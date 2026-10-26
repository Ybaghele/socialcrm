# Staging environment — SocialDesk on Hostinger

This sets up `https://staging.socialcrm.vrindaamrit.com` as an isolated copy of the app
that is safe to break: its own database, its own `APP_KEY`, its own Meta secrets, and
outbound messaging hard-disabled.

> **Status on this account (26 Oct 2026):** staging is installed and working at
> **`https://socialcrm.vrindaamrit.com/staging/`** (the path URL, served by the
> production vhost; note the distinct staging session cookie). The staging database
> `u116324493_socialdesk_stg` exists and is seeded with the three plans.
>
> `staging.socialcrm.vrindaamrit.com` resolves to `145.79.28.83` and the folder
> `/home/u116324493/domains/socialcrm.vrindaamrit.com/public_html/staging` exists, but the
> hostname still serves Hostinger's default 403/404 instead of that folder: the subdomain
> is **not attached to the folder** in hPanel. Fix it in hPanel (Subdomains → `staging` →
> document root `domains/socialcrm.vrindaamrit.com/public_html/staging`), issue SSL, then
> switch `APP_URL` back to the subdomain and re-run `config:cache`. Until then, use the
> path URL.
>
> While the path URL is in use, browse staging in a **separate browser profile or
> incognito**. It shares the `socialcrm.vrindaamrit.com` host with production, so the
> `XSRF-TOKEN` cookie can overwrite production's and cause 419s in a browser that uses
> both. The subdomain gives staging its own cookie host and removes this hazard.

---

## 1. The split-directory layout

Production and staging keep the app **outside** the web root and put only the contents of
Laravel's `public/` in the served folder. Staging mirrors that, but its app folder lives
outside the production tree so a production backup/deploy can never touch it.

```
/home/u116324493/
├── domains/socialcrm.vrindaamrit.com/
│   ├── app/  bootstrap/  config/  ...          <- PRODUCTION app root (git checkout)
│   ├── .env                                    <- production secrets
│   ├── .git/                                   <- receives `git push live`
│   └── public_html/                            <- production web root
│       └── staging/                            <- STAGING web root (subdomain docroot)
│           ├── index.php                       <- loads ~/staging/socialdesk
│           ├── .htaccess
│           ├── build  images  storage          <- symlinks into staging app
│           └── favicon.ico  robots.txt
└── staging/
    └── socialdesk/                             <- STAGING app root (git checkout)
        ├── app/  bootstrap/  config/  ...
        ├── .env                                <- staging secrets (never production's)
        ├── storage/
        └── .git/                               <- receives `git push staging`
```

Why not put the staging app inside the production domain folder? Because that folder is a
git checkout and the backup script archives it. Keeping staging in `~/staging/` means:

- production backups never grow to include staging code/uploads,
- a production deploy cannot overwrite staging, and vice versa,
- `git status` in production stays clean.

---

## 2. Safety model (read this first)

| Risk | How staging is made safe |
|---|---|
| Writing to production data | Separate MySQL database; staging is **never** restored from a production dump |
| Sending a real WhatsApp/IG/Messenger message | `META_OUTBOUND_ENABLED=false` in `.env`; the send action refuses before charging or queueing |
| Receiving production webhooks | Different `META_APP_SECRET`/verify token; production Meta app keeps its own callback URL |
| Charging real cards | `RAZORPAY_*` blank |
| Sending real email | `MAIL_MAILER=log` |
| Background sends/retries | No cron jobs on staging (see §9) |
| Public indexing | Password protection + `X-Robots-Tag: noindex` |
| Shared cache/session bleed | Staging has its own DB; no shared cache |

The one thing staging deliberately shares is the codebase (via git).

---

## 3. Prerequisites

1. **Subdomain + SSL** — already present. In hPanel → Security → SSL, make sure the free
   certificate covers `staging.socialcrm.vrindaamrit.com` and "Force HTTPS" is on.
2. **New MySQL database** — hPanel → Databases → MySQL Databases:
   - Database: `u116324493_socialdesk_stg`
   - User: `u116324493_socialdesk_stg` (or reuse the existing user)
   - Note the generated password. Do **not** reuse the production database.
3. **SSH** — already working (`u116324493@145.79.28.83`, port `65002`).
4. **PHP 8.3** — the CLI already resolves to 8.3.36.

---

## 4. Create the staging app

```bash
# On the server
mkdir -p ~/staging
git clone --branch main https://github.com/Ybaghele/socialcrm.git \
  /home/u116324493/staging/socialdesk
```

The repository is public and the server can reach GitHub (verified 26 Oct 2026), so no
token is needed. The clone must include the staging commit — `.env.staging.example`, this
guide and the `META_OUTBOUND_ENABLED` guard. If the `cp` below says the file is missing,
the commit has not been pushed to `origin/main` yet.

> Alternative (offline): clone the production checkout
> `/home/u116324493/domains/socialcrm.vrindaamrit.com`. It only contains the commit that
> is live now, so the staging template and kill switch arrive with the first
> `git push staging` instead.

### `.env`

```bash
cd ~/staging/socialdesk
cp .env.staging.example .env
# edit DB_DATABASE, DB_USERNAME, DB_PASSWORD and confirm APP_URL / APP_PUBLIC_PATH
php artisan key:generate
```

Generate two staging-only Meta secrets and paste them into `.env`:

```bash
php -r 'echo "META_APP_SECRET=", bin2hex(random_bytes(32)), PHP_EOL;'
php -r 'echo "META_WEBHOOK_VERIFY_TOKEN=", bin2hex(random_bytes(24)), PHP_EOL;'
```

Required values that must match this layout:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.socialcrm.vrindaamrit.com
APP_PUBLIC_PATH=/home/u116324493/domains/socialcrm.vrindaamrit.com/public_html/staging
DB_DATABASE=u116324493_socialdesk_stg
META_OUTBOUND_ENABLED=false
```

**Never copy production's `APP_KEY`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN`, or
database credentials into staging.**

---

## 5. Install and initialise

```bash
cd ~/staging/socialdesk

composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Fresh schema only. Never `mysql < production-dump.sql` without scrubbing it.
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force

# No `storage:link` here: it shells out to exec(), which Hostinger disables.
# The wire step below creates the served storage symlink instead.
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

> Do **not** run `route:cache`: `routes/web.php` contains closure routes and caching them
> aborts. Production's `post-receive.sh` deliberately never runs it either.

Then create your staging login at `https://staging.socialcrm.vrindaamrit.com/register`
(or `https://socialcrm.vrindaamrit.com/staging/register` until the subdomain vhost is
fixed; the first registration creates a workspace + trial subscription). To reach the
platform console, promote that user:

```bash
php artisan tinker --execute 'App\Models\User::where("email", "you@example.com")->update(["is_super_admin" => true]);'
```

---

## 6. Wire the web root

The subdomain's document root already exists but only has `default.php`. Replace it with
Laravel's front controller and symlinks.

### 6a. `index.php`

`/home/u116324493/domains/socialcrm.vrindaamrit.com/public_html/staging/index.php`:

```php
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = '/home/u116324493/staging/socialdesk';

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

(require_once $appPath.'/bootstrap/app.php')
    ->handleRequest(Request::capture());
```

### 6b. `.htaccess`

```bash
cp ~/staging/socialdesk/public/.htaccess \
   ~/domains/socialcrm.vrindaamrit.com/public_html/staging/.htaccess
```

Append the no-index header (LiteSpeed honours `mod_headers`):

```apache
<IfModule mod_headers.c>
    Header always set X-Robots-Tag "noindex, nofollow"
</IfModule>
```

### 6c. Symlinks

`deploy/wire-public-html.sh` supports a `PUBLIC_DIR` override, so the same script used in
production works here:

```bash
cd ~/staging/socialdesk
PUBLIC_DIR=/home/u116324493/domains/socialcrm.vrindaamrit.com/public_html/staging \
  bash deploy/wire-public-html.sh
```

It links `build`, `images`, `favicon.ico`, `robots.txt` and `storage` into the staging web
root. Remove Hostinger's placeholder only after `index.php` is in place:

```bash
rm -f /home/u116324493/domains/socialcrm.vrindaamrit.com/public_html/staging/default.php
```

### 6d. Verify

```bash
curl -I https://staging.socialcrm.vrindaamrit.com/
# expect: HTTP/2 200 and X-Robots-Tag: noindex, nofollow
```

If it 500s, check `APP_PUBLIC_PATH`, then `tail -n 30 ~/staging/socialdesk/storage/logs/laravel.log`.

---

## 7. Protect the door

The staging folder sits inside `public_html`, so it is also reachable at
`https://socialcrm.vrindaamrit.com/staging/`. Protect both at once:

1. **hPanel → Advanced → Password Protect Directories** → protect
   `domains/socialcrm.vrindaamrit.com/public_html/staging`.
2. Keep `APP_DEBUG=false` unless you are actively debugging; never leave it on.
3. Do not add staging to search engines. The `X-Robots-Tag` header from §6b covers this;
   optionally replace the linked `robots.txt` with a file containing `Disallow: /`.

---

## 8. Meta integration on staging

There is one global webhook endpoint (`/webhooks/meta`) and one HTTP client per channel.
Three safe modes, in increasing order of realism:

### Mode A — inert webhooks (default, recommended while building UI)

- Keep the **random** `META_APP_SECRET` and `META_WEBHOOK_VERIFY_TOKEN` from §4.
- No Meta app points at the staging URL.
- Result:
  - `GET /webhooks/meta` only accepts the staging verify token.
  - Any production payload fails the HMAC check and returns **403** — it is never stored
    or processed.
  - `META_OUTBOUND_ENABLED=false` blocks every outbound send.

This is enough for UI, CRM, campaign, billing and automation work that does not need live
Meta traffic.

### Mode B — inbound-only testing with a second Meta app

Never repoint the production app: Meta stores **one** callback URL per app, so pointing it
at staging would silently take production webhooks offline.

1. Create a **new Meta app** ("SocialDesk Staging") in Development mode.
2. Add the WhatsApp product and use Meta's **test business account / test number**.
3. Webhook callback: `https://staging.socialcrm.vrindaamrit.com/webhooks/meta`
4. Verify token: the staging `META_WEBHOOK_VERIFY_TOKEN` value.
5. Subscribe to the `messages` field.
6. Copy the **staging app's** App Secret into staging `META_APP_SECRET`.
7. Keep `META_OUTBOUND_ENABLED=false` — inbound messages are ingested, but replies are
   refused before queueing.

### Mode C — full end-to-end with test recipients

Only with the staging Meta app and Meta's test recipient numbers:

- Temporarily set `META_OUTBOUND_ENABLED=true`.
- Connect only the **test** WABA number (paste its test token on the Channels screen).
- Send only to numbers on Meta's test recipient list.
- When done, set the flag back to `false` and run `php artisan config:cache`.

### What never to do

- ❌ Point the production Meta app's callback at staging.
- ❌ Paste production channel access tokens into staging.
- ❌ Restore a production dump into staging without scrubbing `channel_accounts`:

```sql
UPDATE channel_accounts SET access_token = '', status = 'disconnected';
```

(The outbound kill switch is the second line of defence if this is ever missed.)

---

## 9. Cron policy

Do **not** copy production's cron entries. Staging runs no scheduler and no queue worker
while `QUEUE_CONNECTION=sync` — jobs execute inside the request that triggered them,
which is what makes inbound test webhooks work without a worker.

If you later want to mimic production latency:

1. Set `QUEUE_CONNECTION=database`.
2. Add staging-only cron entries pointing at `~/staging/socialdesk/artisan`.
3. Keep `META_OUTBOUND_ENABLED=false` so the worker cannot send.

Never point a staging cron at `/home/u116324493/domains/socialcrm.vrindaamrit.com/artisan`.

---

## 10. Deploying to staging

Reuse the existing scripts; the app root and remote name are parameters.

### One-time: make the staging folder accept pushes

```powershell
# local, from the project folder
powershell -ExecutionPolicy Bypass -File deploy\setup-server.ps1 `
  -SshHost 145.79.28.83 -SshUser u116324493 -SshPort 65002 `
  -AppDir /home/u116324493/staging/socialdesk `
  -RemoteName staging
```

This installs the same `post-receive` hook as production and registers a `staging` remote.
Because the staging app lives outside the domain folder, tell its deploy which web root to
wire by exporting `PUBLIC_DIR` in the hook:

```bash
# on the server, once
HOOK=~/staging/socialdesk/.git/hooks/post-receive
grep -q '^export PUBLIC_DIR=' "$HOOK" || \
  sed -i '2i export PUBLIC_DIR=/home/u116324493/domains/socialcrm.vrindaamrit.com/public_html/staging' "$HOOK"

# Pin the CLI to the documented PHP 8.3 build. The hook's PHP probe prefers
# /opt/alt/php84 first, which would otherwise make staging deploys differ from
# the version this runbook (and Composer 2.9.8) was set up with.
grep -q '^export PHP_BIN=' "$HOOK" || \
  sed -i '3i export PHP_BIN=/opt/alt/php83/usr/bin/php' "$HOOK"
```

### Every deploy

```powershell
powershell -ExecutionPolicy Bypass -File deploy\push.ps1 -LiveRemote staging "your message"
```

Flow: build assets → push to GitHub → push to staging → composer/migrate/cache on staging.
Production is untouched unless you also run `push.ps1` without `-LiveRemote staging`.

> The first push replaces the cloned checkout. Untracked files (`.env`, `vendor/`,
> `storage/` contents) survive, exactly like production.

---

## 11. Verification checklist

Run after setup and after every staging deploy:

| # | Check | Expected |
|---|---|---|
| 1 | `curl -I https://staging.socialcrm.vrindaamrit.com/` | `200`, `X-Robots-Tag: noindex` |
| 2 | `/login` and `/register` | `200` |
| 3 | `GET /webhooks/meta?hub.mode=subscribe&hub.verify_token=<staging-token>&hub.challenge=123` | body `123` |
| 4 | `POST /webhooks/meta` signed with the **production** secret | `403` |
| 5 | Staging DB row counts | match staging activity, never production's |
| 6 | Reply from the staging inbox | error `outbound_disabled`, no Meta call |
| 7 | `tail -n 30 ~/staging/socialdesk/storage/logs/laravel.log` | no exceptions |
| 8 | `APP_ENV` in the UI/logs | `staging` |
| 9 | Production homepage | still `200` (unchanged) |
| 10 | Production `storage/logs/laravel.log` | no new errors |

> Until the subdomain vhost is fixed (see the status note at the top), use
> `https://socialcrm.vrindaamrit.com/staging` in place of
> `https://staging.socialcrm.vrindaamrit.com` for checks 1–4 and the smoke test below.

Quick smoke test for #3/#4:

```bash
# handshake
curl -s "https://staging.socialcrm.vrindaamrit.com/webhooks/meta?hub.mode=subscribe&hub.verify_token=STAGING_TOKEN&hub.challenge=123"

# forged production-style payload (must be rejected)
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  -H 'Content-Type: application/json' -H 'X-Hub-Signature-256: sha256=deadbeef' \
  -d '{"object":"whatsapp_business_account"}' \
  https://staging.socialcrm.vrindaamrit.com/webhooks/meta
```

---

## 12. Promoting to production

1. Merge the feature branch into `main` locally.
2. Run the full test suite (`php artisan test --compact`).
3. Back up production: `bash deploy/backup-server.sh` (see `~/backups/`).
4. Tag the release: `git tag -a v0.x -m "..." && git push origin v0.x`.
5. Deploy: `deploy\push.ps1 "release: v0.x"` (this one hits `live`).
6. Run §11 checks against production (homepage 200, `tail` logs).
7. Rollback if needed: `git reset --hard <previous-tag>` on production, then
   `composer install --no-dev`, cache rebuild, and restore the DB dump only if a
   migration must be undone.

---

## 13. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| 403 on every staging page | hPanel password protection is working — add credentials. Otherwise check the docroot in hPanel. |
| Subdomain says 403/404 but `/staging/` on the main domain works | The subdomain vhost is not attached to `public_html/staging` (DNS can exist without a vhost). Recreate/fix the subdomain in hPanel with that exact document root, issue SSL, then set `APP_URL` to the subdomain and re-run `config:cache`. |
| `Identifier name ... is too long` during migrate | A composite index name exceeds MySQL/MariaDB's 64-char limit. Give `$table->unique([...])` an explicit short name (see `2026_10_01_000005_create_contacts_tables.php`). SQLite tests do not catch this. |
| 500 after setup | `APP_PUBLIC_PATH` wrong, or `.env` missing `APP_KEY`. Check `~/staging/socialdesk/storage/logs/laravel.log`. |
| CSS/JS 404 | `public_html/staging/build` symlink missing — re-run §6c. |
| "Vite manifest not found" | `APP_PUBLIC_PATH` doesn't point at the staging docroot; re-run `config:cache`. |
| Webhook handshake 403 | Verify token differs from `META_WEBHOOK_VERIFY_TOKEN`; clear config cache. |
| Webhook POST 500 | `META_APP_SECRET` empty — set the staging-only random value. |
| Inbound message stored but never processed | `QUEUE_CONNECTION` is `database` with no worker; set it back to `sync`. |
| Reply shows `outbound_disabled` | Expected while the kill switch is on. |
| Deploy says "no public_html found" | The staging hook lacks `PUBLIC_DIR`; see §10. |
| Staging deploy migrated the wrong DB | `DB_DATABASE` still points at production — stop and fix `.env` immediately. |
