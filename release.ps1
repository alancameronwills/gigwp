# Cut a GitHub release for the currently committed version.
# Run this AFTER you've bumped the Version/@version headers in gigio.php,
# committed, and pushed to origin. It builds the zip, tags v<version>,
# pushes the tag, and creates the GitHub release with the zip as an asset.
#
# Usage:  .\release.ps1

$ErrorActionPreference = 'Stop'

$pluginRoot = $PSScriptRoot
Set-Location $pluginRoot

# Working tree must be clean - the release should match exactly what's committed.
$status = git status --porcelain
if ($status) {
    Write-Error "Working tree is not clean. Commit or stash changes first."
    exit 1
}

# HEAD must match the upstream branch - the tag should point at something on origin.
git fetch origin --quiet
$local = git rev-parse HEAD
$remote = git rev-parse '@{u}' 2>$null
if (-not $remote -or $local -ne $remote) {
    Write-Error "Local HEAD is not in sync with the upstream branch. Push your commit first."
    exit 1
}

# Read the version from gigio.php, same pattern build-release.ps1 uses.
$mainFile = Join-Path $pluginRoot 'gigio.php'
$versionLine = Select-String -Path $mainFile -Pattern '^\s*\*\s*Version:\s*(.+)$' | Select-Object -First 1
if (-not $versionLine) {
    Write-Error "Could not find 'Version:' header in gigio.php"
    exit 1
}
$version = $versionLine.Matches[0].Groups[1].Value.Trim()
$tag = "v$version"

if (git tag -l $tag) {
    Write-Error "Tag $tag already exists. Bump the version first."
    exit 1
}

# Build the zip (build-release.ps1 re-reads the version itself).
& (Join-Path $pluginRoot 'build-release.ps1')

$zipPath = Join-Path $pluginRoot "build\gigiau-events-posters-$version.zip"
if (-not (Test-Path $zipPath)) {
    Write-Error "Expected zip not found at $zipPath"
    exit 1
}

# Release notes: commit subjects since the previous tag, skipping bump/release noise.
$prevTag = git describe --tags --abbrev=0 HEAD~1 2>$null
if ($prevTag) {
    $notesLines = git log "$prevTag..HEAD" --pretty=format:'- %s' |
        Where-Object { $_ -notmatch '^- bump version$' -and $_ -notmatch '^- Release ' }
} else {
    $notesLines = @()
}
$notesText = ($notesLines -join "`n")
if (-not $notesText) { $notesText = "Release $version" }

git tag $tag
git push origin $tag

gh release create $tag $zipPath --title $version --notes $notesText

Write-Host "Released $tag"
