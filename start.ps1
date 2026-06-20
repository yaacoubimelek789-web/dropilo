# Start Dropilo locally with Docker
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "Docker is not installed. Install Docker Desktop, then run this script again." -ForegroundColor Red
    Write-Host "https://www.docker.com/products/docker-desktop/"
    exit 1
}

if (-not (Test-Path "..\\..\\..\\u631627980_dropilo.20260616183319.sql")) {
    Write-Host "SQL dump not found at dropilo\\u631627980_dropilo.20260616183319.sql" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Host "Created .env from .env.example"
}

Write-Host "Building and starting Dropilo..." -ForegroundColor Cyan
docker compose up --build -d

Write-Host ""
Write-Host "Dropilo is starting." -ForegroundColor Green
Write-Host "Open: http://localhost:8080"
Write-Host "First DB import can take 1-2 minutes."
Write-Host ""
Write-Host "Stop:  docker compose down"
Write-Host "Reset: docker compose down -v"
