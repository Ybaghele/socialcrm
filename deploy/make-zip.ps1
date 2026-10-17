<#
.SYNOPSIS
  Builds omniflow-deploy.zip for upload to Hostinger.

.DESCRIPTION
  - Runs the Vite production build (Node is NOT available on the server).
  - Copies the project to a clean staging folder, excluding dev/environment files.
  - Zips it with a top-level "omniflow/" folder, ready to extract on the server.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File deploy\make-zip.ps1

.EXAMPLE
  # Smaller zip when you will run "composer install" on the server instead:
  powershell -ExecutionPolicy Bypass -File deploy\make-zip.ps1 -ExcludeVendor
#>
[CmdletBinding()]
param(
    [switch]$ExcludeVendor,
    [string]$OutFile
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = Split-Path -Parent $PSScriptRoot          # deploy\ -> project root
$TopFolder   = 'omniflow'                                # folder name created on the server
if (-not $OutFile) { $OutFile = Join-Path (Split-Path -Parent $ProjectRoot) 'omniflow-deploy.zip' }

Write-Host "Project : $ProjectRoot"
Write-Host "Output  : $OutFile"
Write-Host ""

# ---------------------------------------------------------------------------
# 1) Front-end build
# ---------------------------------------------------------------------------
$npm = Get-Command npm.cmd -ErrorAction SilentlyContinue
if (-not $npm) { $npm = Get-Command npm -ErrorAction SilentlyContinue }

if ($npm) {
    Write-Host '==> npm run build'
    Push-Location $ProjectRoot
    try { & $npm.Source run build } finally { Pop-Location }
} else {
    Write-Host '!! npm not found - reusing the existing public\build folder.'
}

if (-not (Test-Path (Join-Path $ProjectRoot 'public\build'))) {
    throw 'public\build is missing. Run "npm.cmd run build" first and retry.'
}

# ---------------------------------------------------------------------------
# 2) Stage a clean copy
# ---------------------------------------------------------------------------
$Stage    = Join-Path ([System.IO.Path]::GetTempPath()) ("omniflow-stage-" + [guid]::NewGuid().ToString('N'))
$StageTop = Join-Path $Stage $TopFolder
New-Item -ItemType Directory -Path $StageTop -Force | Out-Null

$excludeDirs = @(
    (Join-Path $ProjectRoot 'node_modules'),
    (Join-Path $ProjectRoot '.git'),
    (Join-Path $ProjectRoot '.idea'),
    (Join-Path $ProjectRoot '.vscode'),
    (Join-Path $ProjectRoot '.nova'),
    (Join-Path $ProjectRoot '.zed'),
    (Join-Path $ProjectRoot '.codex'),
    (Join-Path $ProjectRoot 'tests')
)
if ($ExcludeVendor) { $excludeDirs += (Join-Path $ProjectRoot 'vendor') }

$excludeFiles = @(
    (Join-Path $ProjectRoot '.env'),
    (Join-Path $ProjectRoot '.env.backup'),
    (Join-Path $ProjectRoot '.env.production'),
    (Join-Path $ProjectRoot '.phpunit.result.cache'),
    (Join-Path $ProjectRoot '_ide_helper.php'),
    (Join-Path $ProjectRoot 'auth.json'),
    (Join-Path $ProjectRoot 'database\database.sqlite'),
    (Join-Path $ProjectRoot 'mytool'),          # stray local sqlite DB
    (Join-Path $ProjectRoot 'public\hot')       # CRITICAL: breaks assets if shipped
)

Write-Host '==> Copying project files'
$rcArgs = @($ProjectRoot, $StageTop, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
$rcArgs += '/XD'; $rcArgs += $excludeDirs
$rcArgs += '/XF'; $rcArgs += $excludeFiles
& robocopy @rcArgs | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed with code $LASTEXITCODE" }

# ---------------------------------------------------------------------------
# 3) Strip local runtime state (cached config/views/sessions/logs)
# ---------------------------------------------------------------------------
Write-Host '==> Cleaning local caches and logs'
$resetDirs = @(
    'storage\logs',
    'storage\framework\cache\data',
    'storage\framework\sessions',
    'storage\framework\views',
    'bootstrap\cache'
)
foreach ($rel in $resetDirs) {
    $p = Join-Path $StageTop $rel
    New-Item -ItemType Directory -Path $p -Force | Out-Null
    Get-ChildItem -Path $p -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
}

# Belt-and-braces: drop any stray SQLite databases that slipped into the root.
Get-ChildItem -Path $StageTop -Filter '*.sqlite' -File -ErrorAction SilentlyContinue |
    Remove-Item -Force -ErrorAction SilentlyContinue

# ---------------------------------------------------------------------------
# 4) Zip
# ---------------------------------------------------------------------------
if (Test-Path $OutFile) { Remove-Item $OutFile -Force }

Write-Host '==> Compressing'
$tar = Join-Path $env:SystemRoot 'System32\tar.exe'
if (Test-Path $tar) {
    & $tar -a -c -f $OutFile -C $Stage $TopFolder
    if ($LASTEXITCODE -ne 0) { throw "tar failed with code $LASTEXITCODE" }
} else {
    Compress-Archive -Path $StageTop -DestinationPath $OutFile -Force
}

Remove-Item $Stage -Recurse -Force -ErrorAction SilentlyContinue

$sizeMb = [math]::Round((Get-Item $OutFile).Length / 1MB, 1)
Write-Host ""
Write-Host "Done: $OutFile  ($sizeMb MB)" -ForegroundColor Green
Write-Host ""
Write-Host "Next: upload this zip to your domain folder in Hostinger File Manager,"
Write-Host "extract it, then follow DEPLOY_HOSTINGER.md."
