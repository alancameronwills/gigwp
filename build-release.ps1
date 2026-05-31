# Build a release zip for the Gigiau Events Posters plugin.
# Reads the version from gigio.php and produces build/gigiau-events-posters-<version>.zip
# with gigiau-events-posters/ as the top-level folder.
#
# Usage:  .\build-release.ps1

$ErrorActionPreference = 'Stop'

$pluginSlug = 'gigiau-events-posters'
$pluginRoot = $PSScriptRoot
$mainFile   = Join-Path $pluginRoot 'gigio.php'

# Pull "Version:" header out of gigio.php (the one WP actually reads).
$versionLine = Select-String -Path $mainFile -Pattern '^\s*\*\s*Version:\s*(.+)$' | Select-Object -First 1
if (-not $versionLine) {
    Write-Error "Could not find 'Version:' header in gigio.php"
    exit 1
}
$version = $versionLine.Matches[0].Groups[1].Value.Trim()

$buildDir = Join-Path $pluginRoot 'build'
$stageDir = Join-Path $buildDir $pluginSlug
$zipPath  = Join-Path $buildDir "$pluginSlug-$version.zip"

# Things that should never go into a release zip.
$excludePatterns = @(
    '.git', '.gitignore', '.gitattributes',
    '.claude', '.vscode', '.idea',
    'build', 'build-release.ps1',
    'CLAUDE.md', 'README.md',
    '*.zip'
)

# Reset build dir.
if (Test-Path $buildDir) { Remove-Item -Recurse -Force $buildDir }
New-Item -ItemType Directory -Path $stageDir | Out-Null

# Stage files.
Get-ChildItem -Path $pluginRoot -Force | Where-Object {
    $name = $_.Name
    -not ($excludePatterns | Where-Object { $name -like $_ })
} | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $stageDir -Recurse -Force
}

# Zip the staged folder so the slug-named directory sits at the top of the
# archive. This is what WP uses as the install directory on manual upload,
# making re-installs overwrite the existing plugin cleanly.
Compress-Archive -Path $stageDir -DestinationPath $zipPath -Force
Remove-Item -Recurse -Force $stageDir

Write-Host ""
Write-Host "Built: $zipPath"
Write-Host "Version: $version"
Write-Host ""
Write-Host "Next:"
Write-Host "  git tag v$version"
Write-Host "  git push --tags"
Write-Host "  Upload the zip as a release asset at:"
Write-Host "    https://github.com/alancameronwills/gigwp/releases/new?tag=v$version"
