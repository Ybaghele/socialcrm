# Deploying SocialDesk to Hostinger (shared / cloud hosting, hPanel)

This guide gets your app to a **public HTTPS URL** so Meta can verify the webhook,
which is required before you can generate/validate the Meta app secret & token flow.

> **Plan assumption:** Hostinger **shared / cloud hosting** (hPanel), **without**
> real-time WebSockets for now (`BROADCAST_CONNECTION=log`; the inbox still works,
> it just refreshes on action instead of pushing live).

---

## Quick start — what *you* do

**On your PC (Windows):**

| # | Do this | Where |
|---|---|---|
| 1 | `powershell -ExecutionPolicy Bypass -File deploy\make-zip.ps1` | project folder `C:\wamp64\www\my-tool` |
| 2 | Note the zip path it prints, e.g. `C:\wamp64\www\omniflow-deploy.zip` | — |

**In Hostinger hPanel:**

| # | Do this | Where |
|---|---|---|
| 3 | Set PHP to **8.3+**, enable **SSL (Force HTTPS)** | hPanel → Advanced → PHP Configuration; Security → SSL |
| 4 | Create a **MySQL** database + user; note name/user/password | hPanel → Databases → MySQL Databases |
| 5 | Open **File Manager**, go to the **domain folder** (parent of `public_html`) | hPanel → Files → File Manager |
| 6 | **Upload** `omniflow-deploy.zip`, then right-click → **Extract** → gives `omniflow/` | same folder |
| 7 | Move everything from `omniflow/public/` into `public_html/` | File Manager |
| 8 | Edit `public_html/index.php` to point at `../omniflow/`, and set `APP_PUBLIC_PATH` in `.env` | see §3 |
| 9 | Create the `public_html/storage` symlink | see §3 |
| 10 | Create `.env` from `.env.production.example`, fill DB + Meta values | File Manager |
| 11 | Run `bash deploy/post-deploy.sh` (SSH) — or add a one-off cron | see §5–§8b |
| 12 | Add the cron job (scheduler + queue worker via `deploy/queue-tick.sh`) | hPanel → Advanced → Cron Jobs |
| 13 | Paste the URL + verify token into Meta's Webhook config | Meta App Dashboard |

Details for each step are below. If you get stuck on step 8, §13 lists the usual causes.

---

## 0. What works (and what doesn't) on shared hosting

| Feature | Shared hosting | Why |
|---|---|---|
| Inbound Meta webhooks | ✅ | Plain PHP endpoint + cron queue worker |
| Outbound WhatsApp/IG/Messenger | ✅ | Server-to-server HTTPS calls |
| CRM, campaigns, billing, auth | ✅ | Standard PHP + MySQL |
| Scheduled tasks | ✅ | hPanel cron |
| **Live inbox WebSockets** | ❌ for now | Needs a long-running process (Reverb) — VPS or Pusher cloud only |

The app is already configured for this: it uses **database** session/cache/queue
(no Redis needed) and only needs a cron-driven queue worker.

---

## 1. hPanel prerequisites

1. **PHP 8.3+**
   hPanel → **Advanced → PHP Configuration** → select **PHP 8.3** (or 8.4).
   - Set `memory_limit` = **256M** or higher, `max_execution_time` = **120**.
   - Required extensions are on by default: `ctype, curl, dom, fileinfo, mbstring,
     openssl, pcre, pdo, pdo_mysql, tokenizer, xml`. Enable `intl`, `zip`, `gd` if listed.
2. **SSL (https)**
   hPanel → **Security → SSL** → install the free **Let's Encrypt** certificate for your
   domain, and turn on "Force HTTPS".
3. **MySQL database**
   hPanel → **Databases → MySQL Databases** → create a database + user.
   Hostinger prefixes names, e.g. `u123456789_omniflow`. Note **DB name, user, password**.
4. **SSH access** (recommended — makes everything easier)
   hPanel → **Advanced → SSH Access**. Note the host, port, username, password.
   - If SSH isn't available on your plan, you can still deploy via **File Manager**,
     upload a pre-built `vendor/` folder, and run artisan via cron (see §9 fallback).
5. **Cron jobs** — available on all plans (hPanel → **Advanced → Cron Jobs**).

---

## 2. Build the front-end assets locally (Node is NOT on the server)

The packaging script in §4 already runs this for you, but for reference:

```powershell
npm.cmd run build
```

This writes `public/build/`. `public/hot` (a dev-only marker that makes the site load
assets from `localhost:5173`) must never reach the server — the §4 script excludes it
automatically. To also clean it up locally:

```powershell
Remove-Item c:\wamp64\www\my-tool\public\hot -ErrorAction SilentlyContinue
```

---

## 3. Server folder layout (shared hosting)

> **Hostinger shared hosting cannot change the document root.** The web root is always
> `public_html`, and Hostinger's own docs tell you to use a VPS if you need a custom root.
> So there is exactly **one** supported layout: keep the app in a folder next to
> `public_html`, and put the *contents of Laravel's `public/`* inside `public_html`.

Target layout:

```
/home/u123456789/domains/yourdomain.com/
├── public_html/              <- web root (Apache serves from here)
│   ├── index.php             <- edited to load the app from ../omniflow
│   ├── .htaccess             <- the one shipped in Laravel's public/
│   ├── build/                <- compiled JS/CSS
│   ├── favicon.ico  robots.txt
│   └── storage               <- symlink -> ../omniflow/storage/app/public
└── omniflow/                 <- the rest of Laravel
    ├── app/ bootstrap/ config/ database/ resources/ routes/ storage/ vendor/
    ├── public/               <- now empty (its contents were moved)
    └── .env
```

Find your exact paths first: hPanel → **Files → FTP Accounts**, and read the **root folder
path** (it is either `/home/u123456789/domains/yourdomain.com/public_html` or
`/home/u123456789/public_html`).

Then:

1. **Move** everything inside `omniflow/public/` into `public_html/`
   (File Manager: open `omniflow/public`, **Select All** → **Move** → `/public_html`).
   You may then delete the now-empty `omniflow/public/` — but keeping an empty `public/`
   folder is harmless.
2. **Create the upload symlink** (uploaded files → served). With `APP_PUBLIC_PATH` set
   (step 4) you can simply run `php artisan storage:link`; it will create the link in
   `public_html`. Or do it by hand:
   ```bash
   ln -s /home/u123456789/domains/yourdomain.com/omniflow/storage/app/public \
         /home/u123456789/domains/yourdomain.com/public_html/storage
   ```
3. **Rewrite `public_html/index.php`** so it points one level up into `omniflow/`:
   ```php
   <?php
   use Illuminate\Foundation\Application;
   use Illuminate\Http\Request;

   define('LARAVEL_START', microtime(true));

   if (file_exists($maintenance = __DIR__.'/../omniflow/storage/framework/maintenance.php')) {
       require $maintenance;
   }

   require __DIR__.'/../omniflow/vendor/autoload.php';

   (require_once __DIR__.'/../omniflow/bootstrap/app.php')
       ->handleRequest(Request::capture());
   ```
   (Only the three `omniflow/` path segments differ from the shipping `index.php`.)
4. **Tell Laravel where the web root is** — add this to `.env` (absolute path):
   ```dotenv
   APP_PUBLIC_PATH=/home/u123456789/domains/yourdomain.com/public_html
   ```
   Why: Laravel resolves `asset()`, the **Vite manifest** and `storage:link` through
   `public_path()`, which still defaults to `omniflow/public` — now empty after the move.
   That would cause `Vite manifest not found at: .../omniflow/public/build/manifest.json`.
   This setting (wired in `config/app.php` + `AppServiceProvider`) points `public_path()`
   at the real web root. It is ignored when unset, so local dev is unaffected.

> The `.htaccess` that came with `public/` already rewrites everything to `index.php`, so
> no extra Apache config is needed. If your domain's `public_html` already contains a
> Hostinger placeholder `index.php`/`default.php`, delete it.

> Run artisan from the app folder:
> ```bash
> cd ~/domains/yourdomain.com/omniflow
> php artisan <command>
> ```

---

## 4. Upload the files

Do **not** hand-pick files — this repo ships a script that builds a correct zip:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\make-zip.ps1
```

It runs `npm run build`, then writes **`omniflow-deploy.zip`** (with a top-level
`omniflow/` folder) to the parent of the project directory, e.g.
`C:\wamp64\www\omniflow-deploy.zip`. It automatically excludes:

```
node_modules/  .git/  tests/  .env  public/hot
*.sqlite  storage/logs/*  storage/framework/{cache,sessions,views}/*
bootstrap/cache/*  .env.backup  .env.production
```

Use `-ExcludeVendor` to leave out `vendor/` (smaller zip, only if you will run
`composer install` on the server in §5).

**Upload (File Manager — no SSH needed):**
1. hPanel → **Files → File Manager**.
2. Go to the **domain folder** (`domains/yourdomain.com/`) — i.e. the parent of `public_html`,
   **not** inside `public_html`.
3. **Upload** `omniflow-deploy.zip` there.
4. Right-click the zip → **Extract**. You should now have `domains/yourdomain.com/omniflow/`.
5. Move `omniflow/public/*` into `public_html/` and edit `index.php` per §3.
6. Delete the zip when done (keeps your account tidy and under quota).

Alternatives: **SFTP** (`scp`/`rsync`) or hPanel → **Advanced → Git** (push to GitHub,
deploy a branch). Git does *not* run `npm build` or `composer install`, so you'd still
upload `public/build/` and `vendor/`, or run §5 over SSH.

---

## 5. Install PHP dependencies (over SSH)

```bash
cd ~/domains/yourdomain.com/omniflow
composer install --no-dev --optimize-autoloader
```

If Composer isn't available on the server, upload your local `vendor/` folder instead
(built for the same PHP 8.3+).

---

## 6. Configure `.env`

Copy the template this repo ships and fill it in:

```bash
cp .env.production.example .env
php artisan key:generate
```

Minimum values to set in `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_PUBLIC_PATH=/home/u123456789/domains/yourdomain.com/public_html   # §3 step 4

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=u123456789_omniflow
DB_USERNAME=u123456789_omniflow
DB_PASSWORD=your-db-password

BROADCAST_CONNECTION=log      # no WebSockets on shared hosting
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

META_APP_SECRET=...           # see §10
META_WEBHOOK_VERIFY_TOKEN=... # see §10
```

---

## 7. Migrate + seed

```bash
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
```

Then register the first account at `https://yourdomain.com/register` — that creates your
workspace (plan/subscription/pipeline stages are provisioned automatically).

---

## 8. Storage link + permissions

`public_html/index.php` and `APP_PUBLIC_PATH` are already in place from §3. Now:

```bash
cd ~/domains/yourdomain.com/omniflow
php artisan storage:link          # creates public_html/storage (thanks to APP_PUBLIC_PATH)
chmod -R 775 storage bootstrap/cache
```

> If `storage:link` complains the link already exists, that's fine — skip it. If you did
> **not** set `APP_PUBLIC_PATH`, create the symlink by hand (§3 step 2) instead, because
> `storage:link` would otherwise create it inside `omniflow/public/`, which is not served.

---

## 8b. Or just run the bundled script

Everything from §5 to §9 (composer, migrations, seeding, symlink, caches) is wrapped up:

```bash
cd ~/domains/yourdomain.com/omniflow
bash deploy/post-deploy.sh
```

If it reports Windows line endings (`$'\r': command not found`), fix once:

```bash
sed -i 's/\r$//' deploy/post-deploy.sh
```

Override the PHP binary if needed:
```bash
PHP_BIN=/opt/alt/php83/usr/bin/php bash deploy/post-deploy.sh
```


---

## 9. Queue worker via cron (REQUIRED for inbound Meta messages)

The webhook saves each event and **queues** a job. Without a worker, the URL verifies but
messages never appear. On shared hosting, keep a worker alive via cron.

Add **one** cron job (every minute) that runs the bundled heartbeat script:

```
* * * * * /bin/bash /home/u123456789/domains/yourdomain.com/omniflow/deploy/queue-tick.sh >> /dev/null 2>&1
```

`deploy/queue-tick.sh` runs `schedule:run` and then `queue:work --max-time=55 --sleep=1`, so a
single entry covers both the scheduler and the worker.

> **Do not use `--stop-when-empty`.** It makes the worker exit the instant the queue is
> empty — typically within a second — so any job queued mid-minute (an agent reply, a
> broadcast send) waits for the next cron tick, i.e. up to ~60 seconds. `--max-time=55`
> keeps the worker polling for the rest of the minute and exits before the next tick, so
> workers never pile up. `--sleep=1` (default 3) cuts the worst-case pickup delay.

**Why a script and not a one-liner:** the hPanel cron field mangles shell quoting and `&&`
chains easily, and a broken chain silently stops *both* the scheduler and the worker. A single
short command removes that failure mode.

**Is it actually running?** The script stamps `storage/framework/queue-tick.stamp` each tick
and appends to `storage/logs/queue-tick.log`. A stale stamp means the cron is not firing:

```bash
cat storage/framework/queue-tick.stamp          # should be within the last minute or two
tail -n 40 storage/logs/queue-tick.log
```

**PHP binary path:** the script pins `/opt/alt/php84/usr/bin/php`. To change it, edit `PHP_BIN`
at the top of `deploy/queue-tick.sh`. The path comes from hPanel → PHP Configuration; confirm
over SSH with `php -v`.

> Fallback if you have no SSH/composer: the cron job itself can run artisan, so once files
> are uploaded and `.env` exists this still works. `key:generate` / `migrate` can also be
> triggered by a one-off cron line you delete afterwards.

---

## 10. Wire up the Meta webhook (your original blocker)

In **Meta App Dashboard → WhatsApp / Instagram / Messenger → Configuration → Webhooks**:

| Field | Value |
|---|---|
| **Callback URL** | `https://yourdomain.com/webhooks/meta` |
| **Verify token** | the exact value of `META_WEBHOOK_VERIFY_TOKEN` in `.env` |
| **Subscribe to fields** | `messages` (per product: `whatsapp_business_account`, `instagram`, `page`) |

How the app validates it (`app/Http/Controllers/Webhooks/MetaWebhookController.php`):
- **GET** (handshake): compares `hub_verify_token` to `META_WEBHOOK_VERIFY_TOKEN`, echoes
  `hub_challenge`. Wrong token → 403.
- **POST** (events): verifies the `X-Hub-Signature-256` HMAC against `META_APP_SECRET`.
  Wrong signature → 403.

Where the two secrets come from:
- **`META_APP_SECRET`** → Meta App Dashboard → **App Settings → Basic → App Secret** (click *Show*).
- **`META_WEBHOOK_VERIFY_TOKEN`** → **you invent it** (any long random string). It isn't issued
  by Meta; you just paste the same string in both `.env` and the dashboard.

> Sending requires per-tenant credentials (WhatsApp phone number ID + a permanent System User
> access token). Add them in the app under **Settings → Channels** (`/w/{workspace}/channels`),
> which registers the asset ids the shared webhook routes inbound traffic to. That page also
> shows the **callback URL** and **verify token** to paste into the dashboard above, exposes a
> **Test** button that reads the asset back from the Graph API, and supports disconnect/delete.
> **Verification itself only needs the public URL, the app secret, and the verify token.**

Until the Meta app is published (Business Verification + App Review), real traffic won't be
delivered — only dashboard-triggered test payloads. Note that those test payloads carry a fake
`phone_number_id`, so they are logged and dropped (no matching channel account).

Test it end-to-end without Meta:
```bash
php artisan omniflow:simulate-inbound acme --sync
```

---

## 11. Optimize after every deploy

```bash
cd ~/domains/yourdomain.com/omniflow
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

⚠️ **Do NOT run `php artisan route:cache`.** `routes/web.php` contains closure routes
(`/` and `/dashboard`), and route caching aborts on closures. (`config:cache` + `view:cache`
are safe and give most of the speedup.)

After changing `.env` on the server, always re-run `php artisan config:clear` (or the
`optimize:clear` + `config:cache` pair), otherwise the old config is cached.

---

## 12. Deploy checklist (repeat for each update)

```bash
# local
npm.cmd run build
# upload changed files (or git pull on server), then:
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan view:cache
```

---

## 13. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Assets load from `localhost:5173` | `public/hot` still on the server — delete it and re-run `npm.cmd run build`. |
| `Vite manifest not found at .../omniflow/public/build/manifest.json` | `APP_PUBLIC_PATH` isn't set (or is wrong). Set it to the absolute `public_html` path, then `php artisan config:cache`. |
| CSS/JS 404 in the browser | The `public/` contents weren't moved into `public_html` (so `/build/...` isn't served). |
| 500 on every page | Check `storage/logs/laravel.log`. Usually missing `APP_KEY` or file permissions. |
| "Please provide a valid cache path" | `storage/framework/{cache,sessions,views}` missing → recreate and `chmod 775`. |
| Every route 404s | `public_html/index.php` not edited, or `public/.htaccess` not moved into `public_html` (§3). |
| Webhook verification fails (403) | `META_WEBHOOK_VERIFY_TOKEN` mismatch, or the GET request can't reach `https://` (SSL/module). |
| Webhook POST 403 | `META_APP_SECRET` wrong, or a proxy altered the body before the HMAC check. |
| Webhook returns 200 but no message appears | Cron queue worker ( §9 ) isn't running — check the Cron Jobs log. |
| Config `.env` changes have no effect | Run `php artisan config:clear` / `config:cache`. |
| Mixed-content / redirect loop | Ensure "Force HTTPS" is on and `APP_URL=https://...`. |

---

## 14. Want a public URL *today*, before deploying?

If you just need Meta to verify the webhook right now, expose your local dev server over a
tunnel instead of deploying:

```powershell
# example with Cloudflare Tunnel (no account needed)
cloudflared tunnel --url http://localhost:8000
```
Use the generated `https://<random>.trycloudflare.com/webhooks/meta` as the callback URL.
This is temporary; the Hostinger deploy above is the durable setup.


## 15. Post-Deployment Verification (Mandatory Agent Step)
Every time you finish running the deployment scripts on the Hostinger server, you MUST execute these verification steps before marking the task as complete:
1. Run `php artisan config:cache` and `php artisan route:cache` on the live server.
2. Use your local curl/HTTP tool to ping the live homepage URL to ensure it returns a `200 OK` status code (not a 500 error).
3. Run `tail -n 20 storage/logs/laravel.log` on the live server to verify that no new critical exceptions or connection errors occurred during the code update.
