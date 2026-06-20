# Start local MariaDB (portable, no admin rights)
$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..\..\..")
$datadir = Join-Path $root "mysql-data"
$mysqld = "C:\Program Files\MariaDB 12.3\bin\mysqld.exe"
$mysql = "C:\Program Files\MariaDB 12.3\bin\mysql.exe"
$installDb = "C:\Program Files\MariaDB 12.3\bin\mariadb-install-db.exe"

if (-not (Test-Path $mysqld)) {
    Write-Host "MariaDB not found. Install: winget install MariaDB.Server" -ForegroundColor Red
    exit 1
}

$running = & $mysql -h 127.0.0.1 -P 3307 -u root -pdropilo_root_pass -e "SELECT 1" 2>$null
if ($LASTEXITCODE -ne 0) {
    if (-not (Test-Path "$datadir\mysql")) {
        New-Item -ItemType Directory -Force -Path $datadir | Out-Null
        & $installDb --datadir=$datadir --password=dropilo_root_pass | Out-Null
    }
    Start-Process -FilePath $mysqld -ArgumentList "--no-defaults","--datadir=$datadir","--port=3307","--console" -WindowStyle Hidden
    Start-Sleep -Seconds 8
}

Write-Host "MariaDB running on 127.0.0.1:3307" -ForegroundColor Green
