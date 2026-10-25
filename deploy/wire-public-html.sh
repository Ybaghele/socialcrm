#!/usr/bin/env bash
#
# Points public_html at the app's build output.
#
# Hostinger fixes the web root at public_html and will not let it be changed, so
# the files Laravel would keep in its own public/ directory are served from there
# instead. Almost all of them (index.php, .htaccess) are written once and never
# change again -- but public/build/ is rewritten on every release, so link it at
# the app instead of copying it. After that a git push is all it takes.
#
# Called automatically by deploy/post-receive.sh on every deploy, and safe to run
# by hand. It is idempotent and never removes an existing symlink.
#
# APP_DIR   - the Laravel app. Derived from this script's location when unset.
# PUBLIC_DIR - the web root. Only needed when it cannot be guessed, i.e. when
#              neither $APP_DIR/public_html nor ../public_html exists.
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -z "${APP_DIR:-}" ]; then
    APP_DIR="$(dirname "$SCRIPT_DIR")"
fi

if [ -z "${PUBLIC_DIR:-}" ]; then
    if   [ -d "$APP_DIR/public_html" ];          then PUBLIC_DIR="$APP_DIR/public_html"
    elif [ -d "$(dirname "$APP_DIR")/public_html" ]; then PUBLIC_DIR="$(dirname "$APP_DIR")/public_html"
    else PUBLIC_DIR=""
    fi
fi

echo "==> public_html wiring"

if [ -z "$PUBLIC_DIR" ]; then
    echo "   !! no public_html found next to $APP_DIR (pass PUBLIC_DIR to override)"
    exit 0
fi
if [ ! -d "$PUBLIC_DIR" ]; then
    echo "   !! not a directory: $PUBLIC_DIR"
    exit 0
fi
if [ ! -d "$APP_DIR/public" ]; then
    echo "   !! missing app public dir: $APP_DIR/public"
    exit 0
fi

echo "   app: $APP_DIR"
echo "   web: $PUBLIC_DIR"

# The one directory that is rewritten on every release. The old copy in
# public_html is stale build output, so it is safe to drop.
if [ -L "$PUBLIC_DIR/build" ]; then
    echo "   build is already a symlink -> $(readlink "$PUBLIC_DIR/build")"
else
    rm -rf "$PUBLIC_DIR/build"
    ln -sfn "$APP_DIR/public/build" "$PUBLIC_DIR/build"
    echo "   build -> $APP_DIR/public/build"
fi

# Small static files. Link them so the repo is the source of truth, but never
# discard a file that has been changed by hand: only link when the public_html
# copy is absent, already a symlink, or byte-for-byte identical to the app's.
#
# True when both files exist and have identical contents. Uses cksum rather than
# cmp so it does not depend on diffutils being installed.
same() {
    [ -f "$1" ] && [ -f "$2" ] && [ "$(cksum <"$1")" = "$(cksum <"$2")" ]
}

# Recursive fingerprint of a directory: "path cksum" for every file, sorted.
# Used to compare a web-root copy against the repo copy without diffutils.
tree_hash() {
    ( cd "$1" 2>/dev/null && find . -type f -print | LC_ALL=C sort | while IFS= read -r f; do
        printf '%s %s\n' "$f" "$(cksum <"$f")"
      done )
}

for file in favicon.ico robots.txt; do
    [ -f "$APP_DIR/public/$file" ] || continue
    if [ -L "$PUBLIC_DIR/$file" ]; then
        echo "   $file is already a symlink -> $(readlink "$PUBLIC_DIR/$file")"
    elif [ ! -e "$PUBLIC_DIR/$file" ] || same "$APP_DIR/public/$file" "$PUBLIC_DIR/$file"; then
        rm -f "$PUBLIC_DIR/$file"
        ln -sfn "$APP_DIR/public/$file" "$PUBLIC_DIR/$file"
        echo "   $file -> $APP_DIR/public/$file"
    else
        echo "   $file differs from app/public/$file - left alone"
        echo "        (replace it with the app copy if deploys should manage it)"
    fi
done

# Brand images (logos, app icon). Same rule as build/: the repo is the source of
# truth, so link the directory instead of copying it. A hand-uploaded directory is
# only replaced when it is byte-for-byte identical to the app copy.
if [ -d "$APP_DIR/public/images" ]; then
    if [ -L "$PUBLIC_DIR/images" ]; then
        echo "   images is already a symlink -> $(readlink "$PUBLIC_DIR/images")"
    elif [ ! -e "$PUBLIC_DIR/images" ] || [ "$(tree_hash "$APP_DIR/public/images")" = "$(tree_hash "$PUBLIC_DIR/images")" ]; then
        rm -rf "$PUBLIC_DIR/images"
        ln -sfn "$APP_DIR/public/images" "$PUBLIC_DIR/images"
        echo "   images -> $APP_DIR/public/images"
    else
        echo "   images differs from app/public/images - left alone"
        echo "        (push the new files to the repo if deploys should manage them)"
    fi
fi

if [ -f "$PUBLIC_DIR/build/manifest.json" ]; then
    echo "   manifest resolves"
else
    echo "   !! $PUBLIC_DIR/build/manifest.json does not resolve."
    echo "   !! Is public/build committed? (npm.cmd run build && git add public/build)"
fi

# Uploaded template and campaign images live in storage/app/public and are
# fetched by Meta over HTTPS. `artisan storage:link` only links <app>/public,
# which is not the web root on Hostinger, so link the same target here too.
STORAGE_TARGET="$APP_DIR/storage/app/public"
if [ -d "$STORAGE_TARGET" ]; then
    if [ -L "$PUBLIC_DIR/storage" ]; then
        echo "   storage is already a symlink -> $(readlink "$PUBLIC_DIR/storage")"
    elif [ -d "$PUBLIC_DIR/storage" ] && [ -z "$(find "$PUBLIC_DIR/storage" -mindepth 1 ! -name '.gitignore' -print -quit)" ]; then
        # Older hand uploads left an empty storage/ directory here. It would
        # shadow the symlink, so move it aside rather than deleting it.
        BACKUP="$APP_DIR/storage-public-html.bak.$(date +%s)"
        mv "$PUBLIC_DIR/storage" "$BACKUP"
        ln -sfn "$STORAGE_TARGET" "$PUBLIC_DIR/storage"
        echo "   storage -> $STORAGE_TARGET (old empty directory kept at $BACKUP)"
    elif [ -e "$PUBLIC_DIR/storage" ]; then
        echo "   storage exists and is not empty - left alone"
        echo "        (move it aside if uploads should be served from storage/app/public)"
    else
        ln -sfn "$STORAGE_TARGET" "$PUBLIC_DIR/storage"
        echo "   storage -> $STORAGE_TARGET"
    fi
else
    echo "   !! missing app storage dir: $STORAGE_TARGET"
fi

# Hand-maintained files that predate the git workflow and are not part of a push.
for f in index.php .htaccess; do
    [ -f "$PUBLIC_DIR/$f" ] || echo "   !! $PUBLIC_DIR/$f is missing - see DEPLOY_HOSTINGER.md section 3"
done

exit 0
