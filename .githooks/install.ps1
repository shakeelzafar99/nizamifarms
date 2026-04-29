# ------------------------------------------------------------------------
# One-time installer for the analytics-sandbox pre-commit hook.
#
# Run this from the repo root after cloning:
#     .\.githooks\install.ps1
#
# It points git at the .githooks folder and normalises line endings on
# the hook script so it runs on macOS / Linux / WSL too.
# ------------------------------------------------------------------------

param()

$ErrorActionPreference = 'Stop'

$repoRoot = (& git rev-parse --show-toplevel) 2>$null
if (-not $repoRoot) {
    Write-Host "Not inside a git repository. Run this from the nizamifarms repo root." -ForegroundColor Red
    exit 1
}

Set-Location $repoRoot

Write-Host "Setting core.hooksPath to .githooks ..." -ForegroundColor Cyan
& git config core.hooksPath .githooks

$hook = Join-Path $repoRoot '.githooks/pre-commit'
if (Test-Path $hook) {
    # Convert CRLF -> LF so the shebang-driven hook runs cleanly on Unix.
    $content = [System.IO.File]::ReadAllText($hook).Replace("`r`n", "`n")
    [System.IO.File]::WriteAllText($hook, $content)
    Write-Host "Pre-commit hook installed at .githooks/pre-commit" -ForegroundColor Green
} else {
    Write-Host "WARNING: .githooks/pre-commit not found - was the repo cloned correctly?" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "Done. Commits on any 'analytics-sandbox*' branch are now restricted to analytics-sandbox/." -ForegroundColor Green
Write-Host "On 'main' or other branches the hook is a no-op." -ForegroundColor Green
