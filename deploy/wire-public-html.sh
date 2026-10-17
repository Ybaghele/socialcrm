#!/usr/bin/env bash
#
# One-time (but safe to re-run): make public_html serve the app's current assets.
#
# Hostinger fixes the web root at public_html, so the files Laravel would keep in
# its own public/ directory live there instead. Most of them (index.php,
# .htaccess) never change, but public/build/ is rewritten on every release -- so
# point public_html/build at the app's copy. After that, a git push is enough.
#
# Inputs come from the environment (deploy/setup-server.ps1 pipes this over SSH):
#   APP_DIR      - absolute path of the Laravel app
#   PUBLIC_DIR   - absolute path of public_html
set -euo pipefail

: "${APP_DIR:?APP_DIR must be set}"
: "${PUBLIC_DIR:?PUBLIC_DIR must be set}"

[ -d "$APP_DIR" ]    || { echo "!! app dir not found: $APP_DIR" >&2; exit 1; }
[ -d "$PUBLIC_DIR" ] || { echo "!! public dir not found: $PUBLIC_DIR" >&2; exit 1; }

APP_NAME="$(basename "$APP_DIR")"
echo "==> Linking $PUBLIC_DIR <- $APP_NAME"

# Directories that change on every release: symlink them.
for sub in build; do
    if [ -L "$PUBLIC_DIR/$sub" ]; then
        echo "    $sub is already a symlink -> $(readlink "$PUBLIC_DIR/$sub")"
        continue
    fi
    # The old copy is stale build output; the live one is in the app.
    rm -rf "$PUBLIC_DIR/$sub"
    ln -s "../$APP_NAME/public/$sub" "$PUBLIC_DIR/$sub"
    echo "    $sub -> ../$APP_NAME/public/$sub"
done

# Small static files: symlink too, so later edits land without a manual copy.
for file in favicon.ico robots.txt; do
    [ -f "$APP_DIR/public/$file" ] || continue
    if [ -L "$PUBLIC_DIR/$file" ]; then
        continue
    fi
    rm -f "$PUBLIC_DIR/$file"
    ln -s "../$APP_NAME/public/$file" "$PUBLIC_DIR/$file"
    echo "    $file -> ../$APP_NAME/public/$file"
done

# Verify the build symlink actually resolves before declaring success.
if [ -f "$PUBLIC_DIR/build/manifest.json" ]; then
    echo "==> public_html/build/manifest.json resolves"
else
    echo "!! public_html/build/manifest.json does NOT resolve through the symlink." >&2
    exit 1
fi

# These two are hand-maintained (index.php points at the app folder) and are NOT
# part of a push; just report whether they are still in place.
for file in index.php .htaccess; do
    if [ -f "$PUBLIC_DIR/$file" ]; then
        echo "    $file present"
    else
        echo "!! $PUBLIC_DIR/$file is missing - see DEPLOY_HOSTINGER.md section 3" >&2
    fi
done

if [ -L "$PUBLIC_DIR/storage" ]; then
    echo "    storage symlink present"
else
    echo "!! $PUBLIC_DIR/storage symlink is missing - run 'php artisan storage:link' in the app" >&2
fi

echo "==> public_html wiring done"
