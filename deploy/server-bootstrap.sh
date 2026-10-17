#!/usr/bin/env bash
#
# One-time preparation of the Hostinger app folder so it can receive `git push`.
#
# You do not run this by hand: deploy/setup-server.ps1 pipes it over SSH, which
# is why it reads its inputs from the environment instead of arguments.
#
#   APP_DIR  - absolute path of the Laravel app on the server (required)
#   BRANCH   - branch the hook checks out (default: main)
#
# It is idempotent: running it again just re-asserts the settings.
set -euo pipefail

: "${APP_DIR:?APP_DIR must be set}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"
echo "==> App dir: $(pwd)"

command -v git >/dev/null 2>&1 || { echo "!! git is not installed on this server." >&2; exit 1; }
echo "==> git $(git --version | awk '{print $3}')"

# ---------------------------------------------------------------------------
# 1. The repository that will receive pushes.
# ---------------------------------------------------------------------------
if [ ! -d .git ]; then
    git init -q
    # `git init -b` needs git >= 2.28; symbolic-ref works everywhere.
    git symbolic-ref HEAD "refs/heads/$BRANCH"
    echo "==> Created repository (branch: $BRANCH)"
else
    echo "==> Repository already exists - leaving its history alone"
fi

# A deploy target is a non-bare repo with a checked-out branch, so git refuses
# pushes by default. Allow them; the hook below decides what reaches the files.
git config receive.denyCurrentBranch ignore

# Deploy state lives under .git/ so it is never tracked and never web-served.
mkdir -p .git/deploy-state

# ---------------------------------------------------------------------------
# 2. The hook.
#
# Kept deliberately tiny: it only syncs the working tree and then hands off to
# deploy/post-receive.sh, which ships with the code and therefore stays current.
# ---------------------------------------------------------------------------
cat > .git/hooks/post-receive <<'HOOK'
#!/bin/sh
set -e

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
BRANCH="main"

# git feeds "<old-sha> <new-sha> <ref>" lines on stdin.
while read -r _old _new ref; do
    case "$ref" in
        refs/heads/*) BRANCH="${ref#refs/heads/}" ;;
    esac
done

# Sync the working tree to the pushed commit. Untracked files (.env, vendor/,
# storage/ contents) are left alone; local edits to tracked files are discarded,
# which is exactly what a deploy target wants.
git --git-dir="$APP_DIR/.git" --work-tree="$APP_DIR" checkout -f "$BRANCH"

# Now that the tree is current, run the versioned deploy script.
exec bash "$APP_DIR/deploy/post-receive.sh"
HOOK

chmod +x .git/hooks/post-receive
echo "==> Installed .git/hooks/post-receive"

echo "==> Ready. Push with: git push <remote> HEAD:refs/heads/$BRANCH"
