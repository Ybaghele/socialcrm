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
#
# The app path is baked in below rather than derived at runtime: hooks run with
# their working directory set to <app>/.git, and $0 is not guaranteed to be
# absolute, so guessing is fragile. The heredoc is quoted so nothing is expanded
# now; sed fills in this server's values afterwards.
# ---------------------------------------------------------------------------
cat > .git/hooks/post-receive <<'HOOK'
#!/bin/sh
# Installed by deploy/server-bootstrap.sh -- regenerated, not hand-edited.
set -e

APP_DIR="__APP_DIR__"
BRANCH="__BRANCH__"
DEPLOY_SHA=""

# git feeds "<old-sha> <new-sha> <ref>" lines on stdin.
while read -r _old new ref; do
    case "$ref" in
        refs/heads/*) BRANCH="${ref#refs/heads/}"; DEPLOY_SHA="$new" ;;
    esac
done

# Passed on to deploy/post-receive.sh, which cannot run `git rev-parse` itself:
# a hook's GIT_DIR may be relative and it no longer resolves after a cd.
export DEPLOY_SHA

# Sync the working tree to the pushed commit. Untracked files (.env, vendor/,
# storage/ contents) are left alone; local edits to tracked files are discarded,
# which is exactly what a deploy target wants.
git --git-dir="$APP_DIR/.git" --work-tree="$APP_DIR" checkout -q -f "$BRANCH"

# Now that the tree is current, run the versioned deploy script.
exec bash "$APP_DIR/deploy/post-receive.sh"
HOOK

sed -i "s|__APP_DIR__|$APP_DIR|g; s|__BRANCH__|$BRANCH|g" .git/hooks/post-receive
chmod +x .git/hooks/post-receive
echo "==> Installed .git/hooks/post-receive (APP_DIR=$APP_DIR, BRANCH=$BRANCH)"

echo "==> Ready. Push with: git push <remote> HEAD:refs/heads/$BRANCH"
