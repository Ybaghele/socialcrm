# Deploying SocialDesk with `git push`

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
                          ssh://u116324493@145.79.28.83:65002/home/u116324493/domains/socialcrm.vrindaamrit.com
                                            │
                                            ├── .git/hooks/post-receive        (installed once)
                                            │      └── git checkout -f main    ← working tree updated
                                            └── deploy/post-receive.sh         (ships with the code)
                                                   ├── deploy/wire-public-html.sh
                                                   │      └── public_html/build -> <app>/public/build
                                                   ├── composer install    (only if composer.lock changed)
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
/domains/socialcrm.vrindaamrit.com/          <- the app root, now a git working copy
├── .git/
├── app/ bootstrap/ config/ database/ routes/ resources/ ...
├── vendor/                                 <- untracked; installed by composer
├── storage/                                <- untracked; logs, sessions, uploads
├── .env                                    <- untracked, never touched
├── public/
│   └── build/                              <- the only part served from here
└── public_html/                            <- fixed web root
    ├── index.php                           <- requires ../vendor/autoload.php
    ├── .htaccess
    ├── build -> /home/.../public/build     <- symlink, rewired on every push
    └── storage -> ../storage/app/public
```

The app root is the domain folder itself and `public_html` sits inside it, which is
why `public_html/index.php` uses `__DIR__.'/../vendor/autoload.php'`.

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
ssh -p 65002 u116324493@145.79.28.83 "whoami"
```

> The host and port are shown in hPanel → Advanced → SSH Access. Both are already the
> default in `deploy\setup-server.ps1`, so you normally do not have to type them.
> `srvNNNN.hstgr.io` is equivalent to the IP if you prefer the hostname.

> ⚠️ While you are in `~/.ssh`, do **not** `chmod` your home directory. The web server
> needs the *execute* bit on `/home/u116324493` to reach anything inside it at all; a
> `drwx------` home directory makes every URL on the account answer with Hostinger's
> "This Page Does Not Exist" page. If that ever happens:
> `ssh -p 65002 u116324493@145.79.28.83 "chmod 711 ~"`.

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

This creates the repository inside the app folder, installs the hook, and registers the
`live` remote locally. `public_html` is wired automatically by the hook on every deploy,
so there is nothing to do by hand.

```powershell
powershell -ExecutionPolicy Bypass -File deploy\setup-server.ps1 -SshHost <145.79.28.83>
```

Defaults are baked in for this project (`u116324493`, port `65002`,
`/home/u116324493/domains/socialcrm.vrindaamrit.com`). Override `-AppDir` if the app
moves, and `-SshUser`/`-SshPort` if the host differs.

The script prints exactly what it does; the important lines are:

```
==> Checking SSH access to u116324493@145.79.28.83
==> Found /home/u116324493/domains/socialcrm.vrindaamrit.com
==> git 2.52.0
==> Created repository (branch: main)
==> Installed .git/hooks/post-receive (APP_DIR=/home/u116324493/domains/socialcrm.vrindaamrit.com, BRANCH=main)
==> Added remote 'live' -> ssh://u116324493@145.79.28.83:65002/home/u116324493/domains/socialcrm.vrindaamrit.com
==> Remote is reachable.
```

> ⚠️ `public_html/build` is replaced with a symlink on the first deploy. Everything else
> in `public_html` is left alone, and `index.php`/`.htaccess` are never touched.

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
ssh -p 65002 u116324493@145.79.28.83 `
  "cd ~/domains/socialcrm.vrindaamrit.com && git reset --hard <good-sha> && bash deploy/post-receive.sh"
```

Note that a code rollback does **not** roll back migrations. `php artisan migrate:rollback`
by hand if the bad release added a migration.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| **Every** URL answers with Hostinger's "This Page Does Not Exist" page, including plain files like `/robots.txt` | The account home directory is not traversable by the web server (it got `chmod 700`'d). On the server: `chmod 711 ~`. This breaks every site on the account, not just this one. |
| Push asks for a password every time | SSH key not installed. See setup step 1. |
| `Permission denied (publickey)` | Key not added in hPanel → Advanced → SSH Access → SSH Keys, or wrong `-SshUser`. |
| `remote: ! No PHP CLI on PATH` | The hook could not find PHP. Re-run with `PHP_BIN=/opt/alt/php83/usr/bin/php`, or export `PHP_BIN` in `~/.bashrc`. |
| `! MIGRATIONS FAILED` | New code is live but the schema is behind. Fix and re-run: `cd <app> && php artisan migrate --force`. |
| `composer install FAILED` | It falls back to the `vendor/` already on disk. Fix on the server with `composer install --no-dev --optimize-autoloader`. |
| CSS/JS 404 or stale after a push | `public_html/build` is not a symlink, or points somewhere wrong. Fix with `bash <app>/deploy/wire-public-html.sh` (no arguments — it self-locates). It also runs on every deploy. |
| `Vite manifest not found at .../public/build/manifest.json` | `APP_PUBLIC_PATH` is unset or wrong in the server `.env`. It must be the absolute `public_html` path, then `php artisan config:cache`. |
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
