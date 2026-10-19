#!/usr/bin/env bash
#
# SocialDesk - post-deploy tasks for Hostinger shared hosting.
#
# Run this ON THE SERVER over SSH, after uploading the app:
#
#   bash ~/domains/yourdomain.com/omniflow/deploy/post-deploy.sh
#
# It is idempotent: safe to run again after every deploy.
#
# If you see errors like  $'\r': command not found  the file has Windows line
# endings. Fix once with:
#   sed -i 's/\r$//' deploy/post-deploy.sh
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Locate the Laravel app root (this script lives in <app>/deploy).
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$APP_DIR"
echo "==> App root: $APP_DIR"

# Pick the PHP binary. Override with:  PHP_BIN=/opt/alt/php83/usr/bin/php bash ...
PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "!! '$PHP_BIN' not found. Re-run with the versioned path, e.g." >&2
    echo "   PHP_BIN=/opt/alt/php83/usr/bin/php bash deploy/post-deploy.sh" >&2
    exit 1
fi
echo "==> PHP: $("$PHP_BIN" -v | head -n1)"

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    if [ -f .env.production.example ]; then
        cp .env.production.example .env
        echo
        echo "!! Created .env from .env.production.example."
        echo "   Edit it now (DB_* and META_*), then run this script again."
        exit 0
    fi
    echo "!! No .env and no template found. Create .env first." >&2
    exit 1
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "==> Generating APP_KEY"
    "$PHP_BIN" artisan key:generate --force
fi

# ---------------------------------------------------------------------------
# Sanity-check APP_PUBLIC_PATH (shared hosting: the fixed public_html web root).
# ---------------------------------------------------------------------------
PUBLIC_PATH_VALUE="$(grep -E '^APP_PUBLIC_PATH=' .env | head -n1 | cut -d= -f2- | tr -d '"' || true)"
if [ -z "$PUBLIC_PATH_VALUE" ]; then
    echo "!! APP_PUBLIC_PATH is not set. On Hostinger (web root = public_html) set it to"
    echo "   the ABSOLUTE public_html path, or the site will fail with a missing Vite manifest."
elif [ ! -d "$PUBLIC_PATH_VALUE" ]; then
    echo "!! APP_PUBLIC_PATH=$PUBLIC_PATH_VALUE does not exist on this server — check it."
fi

# ---------------------------------------------------------------------------
# Writable directories (Hostinger sometimes ships them missing/read-only).
# ---------------------------------------------------------------------------
echo "==> Preparing storage directories"
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
# PHP dependencies.
# ---------------------------------------------------------------------------
if command -v composer >/dev/null 2>&1; then
    echo "==> composer install (production)"
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "==> composer not found — assuming vendor/ was uploaded with the app"
fi

# ---------------------------------------------------------------------------
# Database.
# ---------------------------------------------------------------------------
echo "==> Migrations"
"$PHP_BIN" artisan migrate --force

echo "==> Seeding plans (idempotent)"
"$PHP_BIN" artisan db:seed --class=PlanSeeder --force

# ---------------------------------------------------------------------------
# Upload symlink (uploaded files -> storage/app/public).
# With APP_PUBLIC_PATH set, `artisan storage:link` targets the real web root.
# Otherwise fall back to linking public_html by hand.
# ---------------------------------------------------------------------------
if [ -n "$PUBLIC_PATH_VALUE" ]; then
    echo "==> artisan storage:link (into APP_PUBLIC_PATH)"
    "$PHP_BIN" artisan storage:link || true
else
    PUBLIC_HTML="$(cd "$APP_DIR/.." && pwd)/public_html"
    if [ -d "$PUBLIC_HTML" ] && [ ! -e "$PUBLIC_HTML/storage" ]; then
        echo "==> Linking public_html/storage -> storage/app/public"
        ln -s "$APP_DIR/storage/app/public" "$PUBLIC_HTML/storage" || true
    fi
fi

# ---------------------------------------------------------------------------
# Caches. NOTE: never run route:cache — routes/web.php has closure routes.
# ---------------------------------------------------------------------------
echo "==> Building caches (config + views)"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache

# ---------------------------------------------------------------------------
# Reminder.
# ---------------------------------------------------------------------------
cat <<'EOF'

==> Application tasks done.

Remaining manual steps (cannot be automated on shared hosting):

  1. public_html/index.php must point at this folder — see DEPLOY_HOSTINGER.md §3.
  2. hPanel -> Advanced -> Cron Jobs: add the queue worker + scheduler (see §9).
  3. Set META_APP_SECRET and META_WEBHOOK_VERIFY_TOKEN in .env, then:
         php artisan config:cache
  4. Open https://yourdomain.com and register the first account.

EOF
