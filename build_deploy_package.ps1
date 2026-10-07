<#
    build_deploy_package.ps1
    Builds a clean, ready-to-upload copy of the project for free hosting.

    It exists because the single most common cause of "database connection
    failed" after upload is shipping a file that should never leave the
    machine: .env. That file silently overrides config/config.local.php,
    so the site would connect with localhost / root / luqya_ai instead of
    the hosting credentials.

    Usage:
        powershell -ExecutionPolicy Bypass -File .\build_deploy_package.ps1
        powershell ... -NoDiagnostic      # omit setup_check.php
        powershell ... -OutDir "D:\somewhere"
#>
param(
    [string] $OutDir          = "C:\xampp\htdocs",
    [switch] $NoDiagnostic,
    [string] $PackageName     = "luqya-deploy.zip"
)

$ErrorActionPreference = "Stop"
$root    = $PSScriptRoot
$staging = Join-Path $env:TEMP ("luqya-pkg-" + [guid]::NewGuid().ToString("N").Substring(0,8))

# ── Files/folders that must NEVER be uploaded ────────────────────────
#    key -> why
$excludeDirs = [ordered]@{
    '.idea'                       = 'JetBrains IDE settings (local tooling)'
    '.git'                        = 'version-control metadata'
    'assets\images\uploads'       = 'legacy image dir, 10MB, referenced by no DB row'
    'node_modules'                = 'if present, reinstall on the host'
}
$excludeFiles = [ordered]@{
    '.env'                        = 'CRITICAL: overrides config.local.php -> wrong DB'
    '.gitignore'                  = 'local tooling'
    '.dockerignore'               = 'local tooling'
    'Dockerfile'                  = 'local tooling'
    'config\config.local.php.bak' = 'old XAMPP creds (localhost/root)'
    'setup_db.php'                = 'can re-create and re-seed the whole DB'
    'audit_api_surface.php'       = 'dev audit tool'
    'luqya_database_schema.txt'   = 'internal schema dump'
    'database.sql'                = 'contains password hashes + real personal data; import it from your machine via phpMyAdmin instead'
    'srs_reference.txt'           = 'assignment spec, not needed'
    'workflow-and-api-docs.md'    = 'internal docs'
    'build_deploy_package.ps1'    = 'this script'
}
$excludeGlobs = @('*.zip','*.bak','*.old','*.orig','*.tmp','*.log','Thumbs.db','.DS_Store')

Write-Host "Staging -> $staging" -ForegroundColor DarkGray
New-Item -ItemType Directory -Force -Path $staging | Out-Null

# Copy everything, then delete what must not ship.
Copy-Item -Path (Join-Path $root '*') -Destination $staging -Recurse -Force
Get-ChildItem $staging -Force -Directory | Where-Object { $_.Name -in @('.idea','.git','node_modules') } |
    Remove-Item -Recurse -Force
Get-ChildItem $staging -Force -File | Where-Object { $_.Name -in @('.env','.gitignore','.dockerignore','Dockerfile') } |
    Remove-Item -Force

foreach ($rel in $excludeDirs.Keys) {
    $p = Join-Path $staging $rel
    if (Test-Path $p) { Remove-Item $p -Recurse -Force }
}
foreach ($rel in $excludeFiles.Keys) {
    $p = Join-Path $staging $rel
    if (Test-Path $p) { Remove-Item $p -Force }
}
Get-ChildItem $staging -Recurse -Force -File | Where-Object {
    $n = $_.Name
    ($excludeGlobs | Where-Object { $n -like $_ }).Count -gt 0
} | Remove-Item -Force

if ($NoDiagnostic) {
    $p = Join-Path $staging 'setup_check.php'
    if (Test-Path $p) { Remove-Item $p -Force }
}

# ── Pre-flight: refuse to ship a broken package ──────────────────────
$fail = $false
function Check($ok, $msg) {
    if ($ok) { Write-Host "  [ok]   $msg" -ForegroundColor Green }
    else     { Write-Host "  [FAIL] $msg" -ForegroundColor Red; $script:fail = $true }
}

Write-Host "`nPre-flight checks" -ForegroundColor Cyan
Check (-not (Test-Path (Join-Path $staging '.env'))) "'.env' is absent (it would override the hosting config)"
Check (-not (Test-Path (Join-Path $staging 'config\config.local.php.bak'))) "old XAMPP credential backup is absent"
Check (-not (Test-Path (Join-Path $staging 'setup_db.php'))) "setup_db.php is absent (cannot re-seed the DB)"

$cfgPath = Join-Path $staging 'config\config.local.php'
Check (Test-Path $cfgPath) "config/config.local.php is present"
if (Test-Path $cfgPath) {
    $cfg = Get-Content $cfgPath -Raw
    $val  = { param($k) ([regex]::Match($cfg, "'$k'\s*=>\s*'([^']*)'")).Groups[1].Value }
    $dbHost = & $val 'DB_HOST'; $dbUser = & $val 'DB_USER'; $dbName = & $val 'DB_NAME'
    $dbPass = & $val 'DB_PASS'
    Check ($dbHost -and $dbHost -ne 'localhost' -and $dbHost -notmatch '^\d+\.\d+\.\d+\.\d+$') "DB_HOST is a hosting hostname ($dbHost), not localhost"
    Check ([bool]$dbPass)                                                "DB_PASS is populated ($($dbPass.Length) chars)"
    Check ($dbUser -and $dbName -and $dbName.StartsWith($dbUser))        "DB_NAME '$dbName' is prefixed by DB_USER '$dbUser'"
    Check ($cfg -match "'GEMINI_API_KEY'\s*=>\s*''")                "GEMINI_API_KEY is empty (no paid key shipped)"
    Check ($cfg -match "'ALLOW_DB_RESET'\s*=>\s*'0'")              "ALLOW_DB_RESET is 0"
}
Check (Test-Path (Join-Path $staging '.htaccess'))            "root .htaccess present (blocks secrets)"
Check (Test-Path (Join-Path $staging 'config\.htaccess'))     "config/.htaccess present (blocks config dir)"
Check (Test-Path (Join-Path $staging 'uploads\.htaccess'))    "uploads/.htaccess present (blocks script upload)"

if ($fail) {
    Write-Host "`nBuild aborted - fix the problems above.`n" -ForegroundColor Red
    Remove-Item $staging -Recurse -Force
    exit 1
}

# ── Zip it ───────────────────────────────────────────────────────────
$outFile = Join-Path $OutDir $PackageName
if (Test-Path $outFile) { Remove-Item $outFile -Force }
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

$files = Get-ChildItem $staging -Recurse -File

# Entries are written by hand rather than with ZipFile::CreateFromDirectory,
# because that helper stores Windows backslashes ("config\db.php") and several
# Linux unzip tools then create files with the backslash inside the name.
# Forward slashes are the only portable form.
$zip = [IO.Compression.ZipFile]::Open($outFile, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($f in $files) {
        $rel = $f.FullName.Substring($staging.Length + 1).Replace('\', '/')
        $entry = $zip.CreateEntry($rel, [IO.Compression.CompressionLevel]::Optimal)
        $es = $entry.Open()
        try {
            $bytes = [IO.File]::ReadAllBytes($f.FullName)
            $es.Write($bytes, 0, $bytes.Length)
        } finally { $es.Dispose() }
    }
} finally { $zip.Dispose() }

$bytes = ($files | Measure-Object Length -Sum).Sum
Write-Host "`nPackage built" -ForegroundColor Cyan
Write-Host ("  {0}" -f $outFile)
Write-Host ("  {0} files, {1:N1} MB" -f $files.Count, ($bytes/1MB))
Write-Host ("  uploads/ images included: {0}" -f (Get-ChildItem (Join-Path $staging 'uploads') -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -notmatch '^\.' }).Count)
Write-Host "`nNext:" -ForegroundColor Cyan
Write-Host "  1. Upload every entry so it lands directly in htdocs/ (no extra folder)."
Write-Host "  2. Import database.sql via phpMyAdmin - NOT by uploading the file."
Write-Host "  3. Open index.php?dbdebug=<token> and read the result."
Write-Host "  4. Delete setup_check.php and the debug block once green."

Remove-Item $staging -Recurse -Force