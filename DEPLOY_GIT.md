# Deploying OmniFlow with `git push`

This replaces the build-a-zip-and-upload loop. Once set up, getting changes live is:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\push.ps1 "what changed"
```

That builds the front end, commits, backs the commit up to GitHub, and pushes to the
server — where a git hook runs Composer (only when dependencies changed), migrations,
`storage:link`, permissions and the config/view caches, and streams the output back to
your terminal.

---

## How it works

```
your PC                                    Hostinger
────────                                   ─────────
deploy/push.ps1
   ├── npm run build ──► public/build/ ─┐
   ├── git commit                       │
   ├── git push origin   ──► GitHub ────┤ backup + history
   └── git push live     ───────────────┘
                          ssh://u116324493@<host>:65002/home/u116324493/.../omniflow
                                            │
                                            ├── .git/hooks/post-receive     (installed once)
                                            │      └── git checkout -f main   ← working tree updated
                                            └── deploy/post-receive.sh      (ships with the code)
                                                   ├── composer install   (only if composer.lock changed)
                                                   ├── php artisan migrate --force
                                                   ├── php artisan storage:link
                                                   ├── chmod storage bootstrap/cache
                                                   └── optimize:clear + config:cache + view:cache
```

Two properties worth knowing:

- **`.env` is never tracked**, so it is never overwritten by a deploy. Untracked files
  (`vendor/`, `storage/`, `.env`) survive every push. Tracked files the server had
  edited locally *are* discarded — that is what a deploy target should do.
- **`public/build` is tracked** because there is no Node on the server. If you forget to
  build, the old assets stay live. `push.ps1` always builds first.

### Server layout (unchanged from the zip deploys)

```
/domains/socialcrm.vrindaamrit.com/
├── public_html/                 <- web root
│   ├── index.php                <- hand-maintained, points at ../omniflow
│   ├── .htaccess                <- hand-maintained, Laravel's front controller
│   ├── build -> ../omniflow/public/build      <- symlink (wired once)
│   ├── favicon.ico, robots.txt  <- symlinks (wired once)
│   └── storage -> ../omniflow/storage/app/public
└── omniflow/                    <- the app, now a git working copy
    ├── .git/
    ├── app/ config/ routes/ resources/ ...
    ├── public/build/            <- served through the symlink above
    └── .env                     <- untracked, never touched
```

---

## One-time setup

### 1. SSH key (so you are not typing a password for every push)

```powershell
# Create a key if you do not have one (press Enter for defaults, empty passphrase is fine)
ssh-keygen -t ed25519 -C "omniflow deploy"

# Print the public half
Get-Content $HOME\.ssh\id_ed25519.pub
```

hPanel → **Advanced → SSH Access → SSH Keys** → paste the line → save.
Confirm it works (this must not ask for a password):

```powershell
ssh -p 65002 u116324493@<your-ssh-host> "whoami"
```

> `<your-ssh-host>` is shown in hPanel → Advanced → SSH Access. It looks like an IP
> address or `srvNNNN.hstgr.io`. Substitute it everywhere below.

### 2. GitHub repository

Create an **empty private** repository (no README, no .gitignore), then:

```powershell
cd c:\wamp64\www\my-tool
git remote add origin git@github.com:ybaghele2023/omniflow.git   # or the HTTPS URL
git push -u origin main
```

If you use the HTTPS URL, GitHub will ask for a **personal access token** rather than a
password, or you can add your SSH key there too.

### 3. Prepare the server

This creates the repository inside the app folder, installs the hook, points
`public_html/build` at the app, and registers the `live` remote locally.

```powershell
powershell -ExecutionPolicy Bypass -File deploy\setup-server.ps1 -SshHost <your-ssh-host>
```

Defaults are baked in for this project (`u116324493`, port `65002`,
`/home/u116324493/domains/socialcrm.vrindaamrit.com/omniflow`). Override any of them,
and check the app path first if you are unsure:

```powershell
ssh -p 65002 u116324493@<your-ssh-host> "ls -d ~/domains/*/*/omniflow ~/domains/*/omniflow 2>/dev/null"
```

If it reports the path as `MISSING`, pass the real one with `-AppDir`.

The script prints exactly what it does; the important lines are:

```
==> App dir: /home/u116324493/domains/socialcrm.vrindaamrit.com/omniflow
==> Installed .git/hooks/post-receive
==> build -> ../omniflow/public/build
==> public_html/build/manifest.json resolves
==> Added remote 'live' -> ssh://u116324493@<host>:65002/home/u116324493/domains/socialcrm.vrindaamrit.com/omniflow
```

> ⚠️ **Back up `public_html/index.php` before the first run if you have customised it.**
> The wiring step only replaces `build/`, `favicon.ico` and `robots.txt`; `index.php`
> and `.htaccess` are never touched.

### 4. First deploy

```powershell
powershell -ExecutionPolicy Bypass -File deploy\push.ps1 "initial deploy via git"
```

The first push checks out every tracked file over the zip-deployed copies. Then open
<https://socialcrm.vrindaamrit.com> and confirm the CSS/JS load and the login page
renders.

---

## Everyday use

```powershell
# build + commit + push to GitHub and live
powershell -ExecutionPolicy Bypass -File deploy\push.ps1 "fix channel token parsing"

# the two remotes also work directly
git push origin main
git push live main
```

`git push live main` on its own is fine too — it just skips the build and the GitHub
backup, so only use it when you have already run `npm.cmd run build` and committed.

---

## What is tracked

| Path | Tracked? | Why |
|---|---|---|
| `public/build/` | **yes** | No Node on the server, so compiled assets ship with the code |
| `vendor/` | no | ~19 MB and platform-specific — the hook runs `composer install` instead |
| `.env` | no | Secrets; also means a deploy can never clobber it |
| `storage/`, `bootstrap/cache/` | no (only `.gitignore` placeholders) | Runtime state |
| `storage/app/public/` | no | User uploads, not code |
| `node_modules/`, `public/hot` | no | Dev only |

If you ever *do* change dependencies (`composer require ...`), commit the updated
`composer.json` + `composer.lock`. The hook notices the new lockfile checksum and runs
`composer install` on the server for you.

---

## Rollback

Preferred: revert and push again (keeps the history honest).

```powershell
git revert <bad-sha>
powershell -ExecutionPolicy Bypass -File deploy\push.ps1 "revert <bad-sha>"
```

Hard reset on the server, when you need it *now*:

```powershell
ssh -p 65002 u116324493@<your-ssh-host> `
  "cd ~/domains/socialcrm.vrindaamrit.com/omniflow && git reset --hard <good-sha> && bash deploy/post-receive.sh"
```

Note that a code rollback does **not** roll back migrations. `php artisan migrate:rollback`
by hand if the bad release added a migration.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Push asks for a password every time | SSH key not installed. See setup step 1. |
| `Permission denied (publickey)` | Key not added in hPanel → Advanced → SSH Access → SSH Keys, or wrong `-SshUser`. |
| `remote: ! No PHP CLI on PATH` | The hook could not find PHP. Re-run with `PHP_BIN=/opt/alt/php83/usr/bin/php`, or export `PHP_BIN` in `~/.bashrc`. |
| `! MIGRATIONS FAILED` | New code is live but the schema is behind. Fix and re-run: `cd <app> && php artisan migrate --force`. |
| `composer install FAILED` | It falls back to the `vendor/` already on disk. Fix on the server with `composer install --no-dev --optimize-autoloader`. |
| CSS/JS 404 or stale after a push | `public_html/build` is not a symlink any more. Re-run `deploy\setup-server.ps1`, or on the server: `bash deploy/wire-public-html.sh` with `APP_DIR`/`PUBLIC_DIR` set. |
| `Vite manifest not found at .../omniflow/public/build/manifest.json` | `APP_PUBLIC_PATH` is unset or wrong in the server `.env`. It must be the absolute `public_html` path, then `php artisan config:cache`. |
| `refusing to update checked out branch` | `receive.denyCurrentBranch` was not set. Re-run `deploy\setup-server.ps1`. |
| `$'\r': command not found` | A `.sh` file has Windows line endings. The PowerShell scripts strip them automatically; if you copied a file by hand, run `sed -i 's/\r$//' deploy/*.sh` on the server. |
| Config changes in `.env` have no effect | The hook caches config. After editing `.env` manually run `php artisan config:clear && php artisan config:cache`. |
| Assets still load from `localhost:5173` | A stale `public/hot` on the server. The hook deletes it; otherwise `rm <app>/public/hot`. |
| Site 500s right after a push | `storage/logs/laravel.log` on the server. Almost always a failed migration or a missing `vendor/`. |

---

## When to still use the zip

`DEPLOY_HOSTINGER.md` + `deploy\make-zip.ps1` remain the way to **provision a brand new
server** (first `.env`, database, cron jobs, Meta webhook). Once a server has been
prepared with `deploy\setup-server.ps1`, use `git push` for everything else.
