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

# Build the zip entry-by-entry with explicit forward-slash entry names.
# Both Compress-Archive and ZipFile.CreateFromDirectory on Windows PowerShell
# 5.1 / .NET Framework write entries with backslash separators, which
# violates the ZIP spec. Linux unzip (and WordPress on Linux hosts) then
# treats the archive as a single oddly-named file, causing "Plugin file
# does not exist" on activation.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::Open(
    $zipPath, [System.IO.Compression.ZipArchiveMode]::Create
)
try {
    $stagePrefix = (Resolve-Path $stageDir).Path
    Get-ChildItem -Path $stageDir -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($stagePrefix.Length).TrimStart('\','/')
        $entryName = "$pluginSlug/" + ($relative -replace '\\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $_.FullName,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
} finally {
    $archive.Dispose()
}
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
