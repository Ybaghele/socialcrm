<#
.SYNOPSIS
  Build, commit and deploy OmniFlow to the live server in one command.

.DESCRIPTION
  1. npm run build   - Node is not on the server, so the compiled assets are
                       committed and travel with the code.
  2. git add -A + commit (skipped when there is nothing staged)
  3. git push origin  - GitHub, for history and backup
  4. git push live    - the server runs deploy/post-receive.sh, which installs
                        Composer deps when composer.lock changed, runs
                        migrations, fixes permissions and rebuilds the caches.
                        You see that output in this terminal.

  One-time setup first: deploy\setup-server.ps1 and DEPLOY_GIT.md.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File deploy\push.ps1 "fix channel token parsing"

.EXAMPLE
  # Assets already built, and skip the GitHub backup push:
  powershell -ExecutionPolicy Bypass -File deploy\push.ps1 -SkipBuild -SkipGitHub
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string] $Message,

    [string] $LiveRemote   = 'live',
    [string] $GitHubRemote = 'origin',
    [string] $Branch       = 'main',

    [switch] $SkipBuild,
    [switch] $SkipGitHub
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
Push-Location $repoRoot
try {
    if (-not (Test-Path '.git')) {
        throw "No git repository in $repoRoot."
    }

    # -----------------------------------------------------------------------
    # 0. Check the remotes we are about to use.
    # -----------------------------------------------------------------------
    $remotes   = @(git remote)
    $hasLive   = $remotes -contains $LiveRemote
    $hasGitHub = $remotes -contains $GitHubRemote

    if (-not $hasLive) {
        throw "Remote '$LiveRemote' is not configured. Run deploy\setup-server.ps1 first."
    }
    if (-not $SkipGitHub -and -not $hasGitHub) {
        Write-Host "!! Remote '$GitHubRemote' is not configured - skipping the GitHub push. See DEPLOY_GIT.md step 2."
    }

    # -----------------------------------------------------------------------
    # 1. Front-end build. Node is not on the server, so public/build is committed
    #    and must be rebuilt before every deploy.
    # -----------------------------------------------------------------------
    if ($SkipBuild) {
        Write-Host '==> Skipping npm run build (-SkipBuild)'
    } else {
        $npm = Get-Command npm.cmd -ErrorAction SilentlyContinue
        if (-not $npm) { $npm = Get-Command npm -ErrorAction SilentlyContinue }
        if (-not $npm) { throw 'npm was not found. Run "npm.cmd run build" manually, then push with -SkipBuild.' }

        $manifestPath = Join-Path $repoRoot 'public\build\manifest.json'
        $before = if (Test-Path $manifestPath) { (Get-Item $manifestPath).LastWriteTimeUtc } else { [datetime]::MinValue }

        Write-Host '==> npm run build'
        & $npm.Source run build

        if (-not (Test-Path $manifestPath)) {
            throw 'public/build/manifest.json is missing - the Vite build did not complete. Nothing was pushed.'
        }

        # Vite writes a [PLUGIN_TIMINGS] notice to stderr and exits 1 on success,
        # so judge the build by the manifest timestamp instead of the exit code.
        $after = (Get-Item $manifestPath).LastWriteTimeUtc
        if ($after -le $before) {
            Write-Host '!! Vite did not rewrite the manifest - double-check the build output above.'
        } else {
            Write-Host '    assets rebuilt'
        }
    }

    # -----------------------------------------------------------------------
    # 2. Commit.
    # -----------------------------------------------------------------------
    if (-not $Message) {
        $Message = 'deploy: ' + (Get-Date -Format 'yyyy-MM-dd HH:mm')
    }

    git add -A
    git diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        Write-Host '==> Nothing to commit'
    } else {
        Write-Host "==> git commit -m `"$Message`""
        git commit -m $Message | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'git commit failed.' }
    }

    # -----------------------------------------------------------------------
    # 3. GitHub first: the code is safely backed up before prod is touched.
    # -----------------------------------------------------------------------
    # Relax ErrorActionPreference from here on: Windows PowerShell 5.1 turns a
    # native command's stderr into a terminating error as soon as it is piped,
    # and git writes the remote hook's whole deploy log to stderr. Failures are
    # detected from $LASTEXITCODE instead.
    $ErrorActionPreference = 'Continue'

    if (-not $SkipGitHub -and $hasGitHub) {
        Write-Host "==> git push $GitHubRemote (backup)"
        git push $GitHubRemote "HEAD:refs/heads/$Branch" 2>&1 | Out-Host
        if ($LASTEXITCODE -ne 0) { throw "Push to '$GitHubRemote' failed. Nothing was deployed." }
    }

    # -----------------------------------------------------------------------
    # 4. Deploy. The server's post-receive hook runs here; its output (composer,
    #    migrations, cache rebuild) is streamed into this terminal.
    # -----------------------------------------------------------------------
    Write-Host "==> git push $LiveRemote (deploying)"
    git push $LiveRemote "HEAD:refs/heads/$Branch" 2>&1 | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "Deploy push to '$LiveRemote' failed." }

    Write-Host ''
    Write-Host 'Deployed.' -ForegroundColor Green
} finally {
    Pop-Location
}
