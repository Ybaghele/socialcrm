#!/usr/bin/env bash
#
# Runs ON THE SERVER after every `git push live`.
#
# The git hook (<app>/.git/hooks/post-receive, installed once by
# deploy/setup-server.ps1) does nothing but bring the working tree up to date and
# then exec this file. Everything that touches the application lives here so that
# it stays under version control and can be improved with a normal push.
#
# Whatever this prints is streamed straight back to whoever ran `git push`.
#
# Deliberately NOT `set -e`: a failed migration must not stop the cache clear
# below, but it is reported loudly at the end.
set -u

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

# ---------------------------------------------------------------------------
# Locate a usable PHP CLI. hPanel pins the interpreter under /opt/alt, which is
# NOT what a non-interactive SSH shell puts in PATH, so probe the known spots.
# Override by exporting PHP_BIN (e.g. from ~/.bashrc or the cron line).
# ---------------------------------------------------------------------------
find_php() {
    for candidate in "${PHP_BIN:-}" \
        /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php \
        /usr/bin/php /usr/local/bin/php php; do
        [ -n "$candidate" ] || continue
        if command -v "$candidate" >/dev/null 2>&1; then
            printf '%s' "$candidate"
            return 0
        fi
    done
    return 1
}

if ! PHP="$(find_php)"; then
    echo "!! No PHP CLI on PATH. Re-run with PHP_BIN set, e.g.:" >&2
    echo "   ssh ... 'PHP_BIN=/opt/alt/php83/usr/bin/php bash $APP_DIR/deploy/post-receive.sh'" >&2
    exit 1
fi

# The hook exports the pushed commit as DEPLOY_SHA. A bare `git rev-parse` is not
# reliable here: a post-receive hook's GIT_DIR can be relative and stop resolving
# once this script cds.
SHA="${DEPLOY_SHA:-}"
if [ -n "$SHA" ]; then
    SHA="$(printf '%.7s' "$SHA")"
else
    SHA="$(git rev-parse --short HEAD 2>/dev/null || echo '?')"
fi
echo "==> Deployed $SHA to $APP_DIR"
echo "==> PHP $("$PHP" -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------------------
# 1. Dependencies.
#
# vendor/ is NOT in the repo (it is ~19 MB and platform-specific), so Composer
# installs it here. The lockfile checksum is cached under .git/ so this is a
# no-op on ordinary pushes and only runs when dependencies actually change.
# ---------------------------------------------------------------------------
COMPOSER=""
if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
elif [ -f "$HOME/composer.phar" ]; then
    COMPOSER="$PHP $HOME/composer.phar"
fi

if [ -f composer.lock ]; then
    STAMP_DIR="$APP_DIR/.git/deploy-state"
    STAMP="$STAMP_DIR/vendor.lock"
    mkdir -p "$STAMP_DIR"
    LOCK_HASH="$(cksum composer.lock | awk '{print $1"-"$2}')"

    if [ -n "$COMPOSER" ] && { [ ! -f "$STAMP" ] || [ "$(cat "$STAMP" 2>/dev/null)" != "$LOCK_HASH" ]; }; then
        echo "==> composer.lock changed - running composer install"
        if $COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress; then
            printf '%s' "$LOCK_HASH" > "$STAMP"
        else
            echo "" >&2
            echo "!! composer install FAILED. The vendor/ already on disk is still in use," >&2
            echo "!! but it may not match composer.lock. Fix with:" >&2
            echo "!!   cd $APP_DIR && composer install --no-dev --optimize-autoloader" >&2
            echo "" >&2
        fi
    elif [ -z "$COMPOSER" ]; then
        echo "!! composer not found and vendor/ is not in the repo - using the"
        echo "!! vendor/ already on the server. Install Composer to update deps."
    fi
fi

# ---------------------------------------------------------------------------
# 2. Schema.
# ---------------------------------------------------------------------------
echo "==> php artisan migrate --force"
if ! "$PHP" artisan migrate --force --no-interaction; then
    echo "" >&2
    echo "!! MIGRATIONS FAILED - new code is live but the schema is behind." >&2
    echo "!! Re-run once fixed:" >&2
    echo "!!   cd $APP_DIR && $PHP artisan migrate --force" >&2
    echo "" >&2
fi

# ---------------------------------------------------------------------------
# 3. storage/app/public -> public_html/storage, and write permissions.
# ---------------------------------------------------------------------------
"$PHP" artisan storage:link >/dev/null 2>&1 || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# The dev-only Vite marker makes Blade load assets from localhost:5173. It is not
# tracked, but it can survive on the server from an earlier hand upload.
rm -f public/hot

# ---------------------------------------------------------------------------
# 4. Caches. NEVER route:cache - routes/web.php contains closure routes and
#    route caching aborts on them (see DEPLOY_HOSTINGER.md section 11).
# ---------------------------------------------------------------------------
echo "==> Rebuilding caches (config + views)"
"$PHP" artisan optimize:clear
"$PHP" artisan config:cache
"$PHP" artisan view:cache

echo "==> Done. NOTE: the queue worker + scheduler cron jobs still do the runtime work."

# Report success explicitly. Each step above already printed loudly if it failed;
# a non-zero hook status would only add noise to the push output.
exit 0
