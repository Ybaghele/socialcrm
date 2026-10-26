#!/usr/bin/env bash
#
# Full server-side backup of the SocialDesk production app.
#
# Captures, into ~/backups/<UTC timestamp>/ :
#   1. app.tar.gz          - the Laravel app root (code, .env, storage/app/public
#                            uploads, .git history), excluding vendor/, node_modules/
#                            and disposable caches/logs
#   2. public_html.tar.gz  - the web root (index.php, .htaccess, symlinks)
#   3. database.sql.gz     - compressed MySQL dump (schema + data)
#   4. manifest.txt        - what/when/which commit
#   5. SHA256SUMS          - integrity checksums
#   6. RESTORE.md          - how to restore
#
# Usage:
#   bash deploy/backup-server.sh
#   BACKUP_ROOT=/home/u116324493/backups bash deploy/backup-server.sh
#
# Nothing is deleted by this script. Copy the folder off the server afterwards.

set -euo pipefail

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date -u +%Y-%m-%d_%H%M%S)"
BACKUP_ROOT="${BACKUP_ROOT:-$HOME/backups}"
OUT="$BACKUP_ROOT/$STAMP"

log() {
    printf '[backup] %s\n' "$*"
}

fail() {
    printf '[backup] ERROR: %s\n' "$*" >&2
    exit 1
}

[ -f "$BASE/artisan" ] || fail "No artisan file in $BASE - run this from the app's deploy/ folder."
[ -f "$BASE/.env" ] || fail "No .env in $BASE - refusing to back up without it."

mkdir -p "$OUT"
log "App root : $BASE"
log "Output   : $OUT"

# ---------------------------------------------------------------------------
# 1) Application archive (code + .env + uploads + git history)
# ---------------------------------------------------------------------------
log "Archiving application files (vendor and caches excluded)..."
tar -czf "$OUT/app.tar.gz" -C "$BASE" \
    --exclude='./public_html' \
    --exclude='./vendor' \
    --exclude='./node_modules' \
    --exclude='./storage/framework/cache/*' \
    --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' \
    --exclude='./storage/logs/*' \
    --exclude='./.phpunit.cache' \
    --exclude='./.phpunit.result.cache' \
    .

# ---------------------------------------------------------------------------
# 2) Public web root (entry points and symlinks)
# ---------------------------------------------------------------------------
log "Archiving public_html..."
tar -czf "$OUT/public_html.tar.gz" -C "$BASE" public_html

# ---------------------------------------------------------------------------
# 3) MySQL dump, credentials taken from Laravel's own config (handles quotes
#    and special characters in .env safely).
# ---------------------------------------------------------------------------
log "Reading database credentials from Laravel config..."
eval "$(
    cd "$BASE" && php <<'PHP'
<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = config('database.connections.' . config('database.default'));

$values = [
    'DB_HOST'     => (string) ($connection['host'] ?? ''),
    'DB_PORT'     => (string) ($connection['port'] ?? ''),
    'DB_DATABASE' => (string) ($connection['database'] ?? ''),
    'DB_USERNAME' => (string) ($connection['username'] ?? ''),
    'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
];

$values['DB_HOST'] = $values['DB_HOST'] !== '' ? $values['DB_HOST'] : '127.0.0.1';
$values['DB_PORT'] = $values['DB_PORT'] !== '' ? $values['DB_PORT'] : '3306';

foreach ($values as $key => $value) {
    printf('%s=%s%s', $key, escapeshellarg($value), PHP_EOL);
}
PHP
)"

[ -n "${DB_DATABASE:-}" ] || fail "Could not read DB_DATABASE from Laravel config."

log "Dumping database '$DB_DATABASE'..."
if ! MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
    --single-transaction --quick --routines --no-tablespaces \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" | gzip -9 > "$OUT/database.sql.gz"; then
    log "Routines dump failed (missing privileges?) - retrying without routines..."
    MYSQL_PWD="$DB_PASSWORD" mysqldump \
        --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
        --single-transaction --quick --no-tablespaces \
        --default-character-set=utf8mb4 \
        "$DB_DATABASE" | gzip -9 > "$OUT/database.sql.gz"
fi

# ---------------------------------------------------------------------------
# 4) Verify everything before declaring success
# ---------------------------------------------------------------------------
log "Verifying archives..."
gzip -t "$OUT/database.sql.gz" || fail "database.sql.gz is corrupt."
tar -tzf "$OUT/app.tar.gz" > /dev/null || fail "app.tar.gz is corrupt."
tar -tzf "$OUT/public_html.tar.gz" > /dev/null || fail "public_html.tar.gz is corrupt."

zgrep -q 'CREATE TABLE' "$OUT/database.sql.gz" \
    || fail "database.sql.gz contains no CREATE TABLE statements."

TABLE_COUNT="$(zgrep -c 'CREATE TABLE' "$OUT/database.sql.gz" || true)"
log "Database dump contains $TABLE_COUNT tables."

DUMP_BYTES="$(zcat "$OUT/database.sql.gz" | wc -c | tr -d ' ')"
log "Uncompressed dump size: $DUMP_BYTES bytes."

# ---------------------------------------------------------------------------
# 4b) Exact per-table row counts, so the manifest proves what the dump holds
#     (mysqldump uses extended inserts, so counting INSERT statements lies).
# ---------------------------------------------------------------------------
log "Collecting exact row counts..."
TABLE_COUNTS="$(
    cd "$BASE" && php <<'PHP'
<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$connection = config('database.connections.' . config('database.default'));

$tables = DB::select(
    'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name',
    [$connection['database'], 'BASE TABLE']
);

foreach ($tables as $table) {
    printf("%s\t%d%s", $table->name, DB::table($table->name)->count(), PHP_EOL);
}
PHP
)"

TOTAL_ROWS="$(printf '%s\n' "$TABLE_COUNTS" | awk -F '\t' 'NF == 2 { total += $2 } END { print total + 0 }')"
log "Live database has $TOTAL_ROWS rows across $TABLE_COUNT tables."

# ---------------------------------------------------------------------------
# 5) Manifest + checksums + restore notes
# ---------------------------------------------------------------------------
COMMIT="$(git -C "$BASE" rev-parse HEAD 2>/dev/null || echo 'n/a')"
BRANCH="$(git -C "$BASE" rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'n/a')"

{
    echo "SocialDesk backup"
    echo "Created (UTC): $(date -u '+%Y-%m-%d %H:%M:%S')"
    echo "App root:      $BASE"
    echo "Git commit:    $COMMIT"
    echo "Git branch:    $BRANCH"
    echo "PHP:           $(php -v | head -n 1)"
    echo "Database:      $DB_DATABASE ($TABLE_COUNT tables, $TOTAL_ROWS rows)"
    echo "Dump size:     $DUMP_BYTES bytes uncompressed"
    echo
    echo "Files:"
    ls -lh "$OUT"
    echo
    echo "Exact row counts (from the live database at backup time):"
    printf '%s\n' "$TABLE_COUNTS" | awk -F '\t' 'NF == 2 { printf "  %-32s %d\n", $1, $2 }'
} > "$OUT/manifest.txt"

(
    cd "$OUT"
    sha256sum app.tar.gz public_html.tar.gz database.sql.gz > SHA256SUMS
)

cat > "$OUT/RESTORE.md" <<'EOF'
# Restore notes

These archives were produced by `deploy/backup-server.sh`.

## What is inside

- `app.tar.gz` - Laravel app root (code, `.env`, `storage/app/public`, `.git`).
  `vendor/`, `node_modules/` and disposable caches/logs are excluded.
- `public_html.tar.gz` - web root entry points (`index.php`, `.htaccess`) and
  symlinks into the app's `public/` folder.
- `database.sql.gz` - MySQL dump (schema + data).

## Typical restore (same server layout)

```bash
# 1. Stop the app
php artisan down || true

# 2. Restore code (into a clean app folder)
mkdir -p ~/restore && cd ~/restore
tar -xzf /path/to/app.tar.gz
tar -xzf /path/to/public_html.tar.gz

# 3. Restore dependencies
composer install --no-dev --optimize-autoloader

# 4. Restore the database
gunzip -c /path/to/database.sql.gz | mysql -u USER -p DATABASE

# 5. Rebuild caches and bring the app up
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

`.env` from the archive contains the original `APP_KEY` and Meta credentials -
keep it private.
EOF

chmod 700 "$OUT"
chmod 600 "$OUT"/*.gz "$OUT"/*.txt "$OUT"/SHA256SUMS "$OUT"/RESTORE.md 2>/dev/null || true

TOTAL_SIZE="$(du -sh "$OUT" | cut -f1)"

log "Done."
printf '\nBACKUP COMPLETE\n'
printf '  Location : %s\n' "$OUT"
printf '  Size     : %s\n' "$TOTAL_SIZE"
printf '  Commit   : %s\n' "$COMMIT"
printf '  Contents :\n'
ls -lh "$OUT"
