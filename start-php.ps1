# Start Dropilo with PHP built-in server (no Docker)
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

& (Join-Path $PSScriptRoot "start-db.ps1")

$env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Host "PHP is not installed. Run: winget install PHP.PHP.8.3" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
}

$env:PHPRC = Join-Path $PSScriptRoot "php.ini"

Write-Host "Starting PHP dev server..." -ForegroundColor Cyan
Write-Host "Open: http://127.0.0.1:8080"
Write-Host "Login with your Dropilo account from the imported database."
Write-Host "Press Ctrl+C to stop."

php -S 127.0.0.1:8080 -t .
