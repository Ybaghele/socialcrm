<#
.SYNOPSIS
  One-time setup: make the Hostinger app folder accept `git push`.

.DESCRIPTION
  Creates a git repository inside the live app folder, tells git not to refuse
  pushes to its checked-out branch, installs the post-receive hook and registers
  the server as a local remote called "live".

  Run it once. It is safe to run again (nothing is deleted).

  Requires SSH, which needs a Premium plan or higher.

.EXAMPLE
  # Get SshHost from hPanel -> Advanced -> SSH Access (an IP or srvNNNN.hstgr.io).
  powershell -ExecutionPolicy Bypass -File deploy\setup-server.ps1 -SshHost srv1234.hstgr.io

.EXAMPLE
  # Override anything that differs:
  powershell -ExecutionPolicy Bypass -File deploy\setup-server.ps1 `
      -SshHost srv1234.hstgr.io -SshUser u116324493 -SshPort 65002 `
      -AppDir /home/u116324493/domains/socialcrm.vrindaamrit.com/omniflow
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $SshHost,

    [string] $SshUser = 'u116324493',
    [int]    $SshPort = 65002,

    # The Laravel app folder, i.e. the one NEXT TO public_html.
    [string] $AppDir = '/home/u116324493/domains/socialcrm.vrindaamrit.com/omniflow',

    [string] $Branch = 'main',
    [string] $RemoteName = 'live'
)

$ErrorActionPreference = 'Stop'

# These values are interpolated into remote shell commands and into a git URL, so
# refuse anything that could break out of its quoting.
foreach ($item in @(
        @{ Name = 'SshHost'; Value = $SshHost },
        @{ Name = 'SshUser'; Value = $SshUser },
        @{ Name = 'AppDir';  Value = $AppDir },
        @{ Name = 'Branch';  Value = $Branch }
    )) {
    if ($item.Value -notmatch '^[A-Za-z0-9._/@:-]+$') {
        throw "$($item.Name) contains unexpected characters: $($item.Value)"
    }
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$bootstrapPath = Join-Path $PSScriptRoot 'server-bootstrap.sh'

Push-Location $repoRoot
try {
    if (-not (Test-Path '.git')) {
        throw "No git repository in $repoRoot. Run 'git init -b main' and make a first commit, then try again."
    }
    if (-not (Test-Path $bootstrapPath)) {
        throw "Missing $bootstrapPath"
    }

    $target  = "$SshUser@$SshHost"
    $sshArgs = @('-p', "$SshPort", '-o', 'StrictHostKeyChecking=accept-new', $target)

    # Warn early about the thing that makes every push prompt for a password.
    $pubKey = Join-Path $HOME '.ssh\id_ed25519.pub'
    if (-not (Test-Path $pubKey)) {
        Write-Host '!! No ~/.ssh/id_ed25519.pub found. Without an SSH key you will type'
        Write-Host '   your password for every ssh and git push. See DEPLOY_GIT.md step 1.'
        Write-Host ''
    }

    # -----------------------------------------------------------------------
    # 1. Reach the server, and confirm the app folder is where we think it is.
    # -----------------------------------------------------------------------
    Write-Host "==> Checking SSH access to $target"
    $probe = & ssh @sshArgs "test -d '$AppDir' && echo PRESENT || echo MISSING"
    if ($LASTEXITCODE -ne 0) {
        throw "ssh failed (exit $LASTEXITCODE). Check SshHost/SshPort, and that SSH is enabled in hPanel -> Advanced -> SSH Access."
    }
    if ($check = $null) { }
    if (($probe | Out-String).Trim() -ne 'PRESENT') {
        throw "Remote folder not found: $AppDir. Open hPanel -> Files -> File Manager and confirm the path of the Laravel folder next to public_html."
    }
    Write-Host "==> Found $AppDir"

    # -----------------------------------------------------------------------
    # 2. Prepare the repository on the server.
    # -----------------------------------------------------------------------
    Write-Host '==> Creating the repository + post-receive hook'
    # Normalise to LF: a CRLF shebang would make bash fail with '$'\r': command not found.
    $bootstrap = (Get-Content -Raw $bootstrapPath) -replace "`r`n", "`n"
    $bootstrap | & ssh @sshArgs "APP_DIR='$AppDir' BRANCH='$Branch' bash -s"
    if ($LASTEXITCODE -ne 0) {
        throw "Server setup failed (exit $LASTEXITCODE). See the output above."
    }

    # -----------------------------------------------------------------------
    # 2b. One-time public_html wiring: make public_html/build follow the app's
    #     build output so pushes are picked up without copying files by hand.
    # -----------------------------------------------------------------------
    $publicDir  = $AppDir -replace '/[^/]+/?$', '/public_html'
    $wireScript = Join-Path $PSScriptRoot 'wire-public-html.sh'

    Write-Host "==> Wiring $publicDir/build -> app public/build"
    $wire = (Get-Content -Raw $wireScript) -replace "`r`n", "`n"
    $wire | & ssh @sshArgs "APP_DIR='$AppDir' PUBLIC_DIR='$publicDir' bash -s"
    if ($LASTEXITCODE -ne 0) {
        Write-Host '!! public_html wiring did not complete - see DEPLOY_GIT.md step 3.'
    }

    # -----------------------------------------------------------------------
    # 3. Register the remote locally.
    # -----------------------------------------------------------------------
    $liveUrl = "ssh://$SshUser@$SshHost`:$SshPort$AppDir"
    git remote remove $RemoteName 2>&1 | Out-Null
    git remote add $RemoteName $liveUrl
    Write-Host "==> Added remote '$RemoteName' -> $liveUrl"

    # -----------------------------------------------------------------------
    # 4. Prove the remote is reachable (an empty repo lists no refs, exit 0).
    # -----------------------------------------------------------------------
    git ls-remote --heads $RemoteName | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "git could not talk to '$RemoteName'. Check the SSH key/password and the URL above."
    }
    Write-Host '==> Remote is reachable.'
    Write-Host ''
    Write-Host 'Next:' -ForegroundColor Green
    Write-Host '  1. Add your GitHub repo as "origin" (see DEPLOY_GIT.md step 2).'
    Write-Host '  2. Deploy with:  powershell -ExecutionPolicy Bypass -File deploy\push.ps1 "your message"'
}
finally {
    Pop-Location
}
