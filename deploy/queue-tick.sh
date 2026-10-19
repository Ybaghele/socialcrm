#!/usr/bin/env bash
#
# Queue heartbeat — run once per minute from the Hostinger cron.
#
# Why a script instead of a long one-liner: the cron field in hPanel mangles
# shell quoting and `&&` chains easily, and a broken chain silently kills both
# the scheduler and the worker. Keeping the cron entry to a single short command
# removes that whole class of failure.
#
#   Cron entry (every minute):
#     /bin/bash /home/u116324493/domains/socialcrm.vrindaamrit.com/deploy/queue-tick.sh
#
# Design notes:
#   * schedule:run first, so the worker sees the jobs it just queued.
#   * NO --stop-when-empty. That flag makes the worker exit the instant the queue
#     is empty, so it dies within a second and mid-minute sends wait for the next
#     cron tick (~60s). --max-time keeps it polling instead.
#   * --sleep=1 (default is 3) so a reply queued mid-minute goes out right away.
#   * --max-time=55 so the worker always exits before the next cron fires,
#     which prevents workers piling up on top of each other.
#
# A heartbeat stamp is written every tick, so "is the cron actually running?"
# is answerable from outside without guessing.

set -u

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="/opt/alt/php84/usr/bin/php"
LOG="$APP_DIR/storage/logs/queue-tick.log"
STAMP="$APP_DIR/storage/framework/queue-tick.stamp"

cd "$APP_DIR" || exit 1

# Heartbeat. If this file is not fresh, the cron is not firing.
date -u '+%Y-%m-%dT%H:%M:%SZ' > "$STAMP" 2>/dev/null

"$PHP_BIN" artisan schedule:run >> "$LOG" 2>&1

"$PHP_BIN" artisan queue:work --max-time=55 --sleep=1 >> "$LOG" 2>&1

# Keep the log bounded so it can never fill the disk.
if [ -f "$LOG" ] && [ "$(wc -c < "$LOG" | tr -d ' ')" -gt 1048576 ]; then
    tail -n 200 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG"
fi
