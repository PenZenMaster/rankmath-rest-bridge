#Requires -Version 5.1
<#
.SYNOPSIS
    Builds the RankRocket SEO Control Layer release zip for the current version.

.DESCRIPTION
    Reads the version from update-manifest.json, assembles the plugin into a
    temporary staging directory, zips it using .NET ZipFile (preserving directory
    hierarchy), verifies critical structural requirements, and writes the artifact
    to releases/vX.Y.Z/rankmath-rest-bridge.zip.

    Run from the repo root:
        .\bin\build-zip.ps1

    Or with an explicit version override (useful for testing):
        .\bin\build-zip.ps1 -Version 2.7.0

.NOTES
    Critical: PUC vendor files must be copied as a DIRECTORY (-Recurse on the
    folder path), not as individual files, to preserve the Puc/v5p5/ sub-tree.
    Copying Get-ChildItem -Recurse output to a flat destination silently drops
    subdirectories — always use the pattern in this script.
#>

param(
    [string]$Version = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ── Paths ─────────────────────────────────────────────────────────────────────
$Root     = Split-Path $PSScriptRoot -Parent
$Slug     = 'rankmath-rest-bridge'
$Manifest = Join-Path $Root 'update-manifest.json'

# ── Resolve version ───────────────────────────────────────────────────────────
if ($Version -eq '') {
    $ManifestData = Get-Content $Manifest -Raw | ConvertFrom-Json
    $Version      = $ManifestData.version
}

$DestDir = Join-Path $Root "releases\v$Version"
$ZipFile = Join-Path $DestDir "$Slug.zip"
$TmpDir  = Join-Path ([System.IO.Path]::GetTempPath()) "rrseo-build-$Version-$(Get-Random)"

Write-Host ""
Write-Host "=== RankRocket SEO — Release Builder ===" -ForegroundColor Cyan
Write-Host "Version  : $Version"
Write-Host "Zip      : $ZipFile"
Write-Host "Staging  : $TmpDir"
Write-Host ""

# ── Staging directory ─────────────────────────────────────────────────────────
if (Test-Path $TmpDir) { Remove-Item $TmpDir -Recurse -Force }
$PluginRoot = Join-Path $TmpDir $Slug
New-Item -ItemType Directory -Path (Join-Path $PluginRoot 'includes') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $PluginRoot 'vendor')   -Force | Out-Null

# ── Copy: plugin file + manifest ──────────────────────────────────────────────
Write-Host "Copying plugin file and manifest..." -ForegroundColor Gray
Copy-Item (Join-Path $Root "$Slug.php")          (Join-Path $PluginRoot "$Slug.php")
Copy-Item (Join-Path $Root 'update-manifest.json') (Join-Path $PluginRoot 'update-manifest.json')

# ── Copy: includes/ ───────────────────────────────────────────────────────────
Write-Host "Copying includes/..." -ForegroundColor Gray
$IncludesSrc = Join-Path $Root 'includes'
$IncludesDst = Join-Path $PluginRoot 'includes'
Get-ChildItem $IncludesSrc -File | ForEach-Object {
    Copy-Item $_.FullName (Join-Path $IncludesDst $_.Name)
}

# ── Copy: vendor/plugin-update-checker ───────────────────────────────────────
# Create the target subdirectory explicitly, then copy contents with a wildcard
# source. Copy-Item $dir -Destination $existing_dir -Recurse is unreliable on
# Windows — it can flatten the source directory into the destination instead of
# creating a subdirectory, which breaks the plugin's loader path check.
Write-Host "Copying vendor/plugin-update-checker/ (recursive)..." -ForegroundColor Gray
$PucSrc = Join-Path $Root 'vendor\plugin-update-checker'
$PucDst = Join-Path $PluginRoot 'vendor\plugin-update-checker'
New-Item -ItemType Directory -Path $PucDst -Force | Out-Null
Copy-Item (Join-Path $PucSrc '*') -Destination $PucDst -Recurse -Force

# ── Build zip ─────────────────────────────────────────────────────────────────
Write-Host "Building zip..." -ForegroundColor Gray
New-Item -ItemType Directory -Path $DestDir -Force | Out-Null
if (Test-Path $ZipFile) { Remove-Item $ZipFile -Force }

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($TmpDir, $ZipFile)

# ── Cleanup staging ───────────────────────────────────────────────────────────
Remove-Item $TmpDir -Recurse -Force

# ── Verify ────────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== Verifying zip ===" -ForegroundColor Cyan

$Zip     = [System.IO.Compression.ZipFile]::OpenRead($ZipFile)
$Entries = $Zip.Entries | Select-Object -ExpandProperty FullName
$Zip.Dispose()

$SizeMB      = [math]::Round((Get-Item $ZipFile).Length / 1KB, 1)
$TotalCount  = $Entries.Count
$PucV5p5     = @($Entries | Where-Object { $_ -like "*plugin-update-checker/Puc/v5p5*" }).Count
$PucLoader   = @($Entries | Where-Object { $_ -like "*plugin-update-checker/plugin-update-checker.php" }).Count
$IncludesAll = @($Entries | Where-Object { $_ -like "$Slug/includes/*" -and $_ -notlike '*/' })
$PluginFile  = @($Entries | Where-Object { $_ -eq "$Slug/$Slug.php" }).Count

$Checks = @(
    @{ Label = 'Plugin file at top level';          Pass = $PluginFile -eq 1     }
    @{ Label = 'PUC loader present';                Pass = $PucLoader -eq 1      }
    @{ Label = 'PUC Puc/v5p5/ files (need >= 30)'; Pass = $PucV5p5 -ge 30       }
    @{ Label = 'includes/ files present';           Pass = $IncludesAll.Count -ge 5 }
)

$AllPassed = $true
foreach ($c in $Checks) {
    $icon = if ($c.Pass) { '[OK]' } else { '[FAIL]' }
    $col  = if ($c.Pass) { 'Green' } else { 'Red' }
    Write-Host "$icon  $($c.Label)" -ForegroundColor $col
    if (-not $c.Pass) { $AllPassed = $false }
}

Write-Host ""
Write-Host "Total entries : $TotalCount"
Write-Host "Puc/v5p5 count: $PucV5p5"
Write-Host "Size          : $SizeMB KB"
Write-Host "Includes      : $($IncludesAll -join ', ')"
Write-Host ""

if ($AllPassed) {
    Write-Host "Build successful: $ZipFile" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "  1. Update update-manifest.json download_url to point at releases/v$Version/"
    Write-Host "  2. git add releases/v$Version/ update-manifest.json"
    Write-Host "  3. git commit -m 'chore: release v$Version zip'"
    Write-Host "  4. git push"
} else {
    Write-Host "Build FAILED — one or more checks did not pass." -ForegroundColor Red
    Write-Host "Inspect: $ZipFile"
    exit 1
}
