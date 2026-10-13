# Startskript fuer die lokale DLRG 24h-Schwimmen Testumgebung
# Startet Docker, MySQL und den PHP-Server

$ErrorActionPreference = "Continue"
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host "=== DLRG 24h-Schwimmen - Lokale Umgebung starten ===" -ForegroundColor Cyan

# 1. Docker Desktop starten (falls noch nicht laeuft)
Write-Host "`n[1/5] Docker Desktop prüfen..." -ForegroundColor Yellow
$dockerRunning = $false
try {
    $null = docker info 2>$null
    $dockerRunning = $?
} catch {}

if (-not $dockerRunning) {
    Write-Host "  Docker Desktop wird gestartet..."
    Start-Process "C:\Program Files\Docker\Docker\Docker Desktop.exe"
    Write-Host "  Warte auf Docker Engine (max. 60s)..." -NoNewline
    $waited = 0
    while (-not $dockerRunning -and $waited -lt 60) {
        Start-Sleep -Seconds 3
        $waited += 3
        Write-Host "." -NoNewline
        try { $null = docker info 2>$null; $dockerRunning = $? } catch {}
    }
    Write-Host ""
    if (-not $dockerRunning) {
        Write-Host "  FEHLER: Docker konnte nicht gestartet werden." -ForegroundColor Red
        Read-Host "Enter zum Beenden"
        exit 1
    }
}
Write-Host "  Docker laeuft." -ForegroundColor Green

# 2. MySQL-Container starten
Write-Host "`n[2/5] MySQL-Container starten..." -ForegroundColor Yellow
$containerState = docker inspect -f "{{.State.Running}}" dlrg-mysql 2>$null
if ($containerState -ne "true") {
    docker start dlrg-mysql 2>&1 | Out-Null
    Write-Host "  dlrg-mysql gestartet." -ForegroundColor Green
} else {
    Write-Host "  dlrg-mysql laeuft bereits." -ForegroundColor Green
}

# 3. Auf MySQL warten
Write-Host "`n[3/5] Auf MySQL warten..." -NoNewline -ForegroundColor Yellow
$mysqlReady = $false
$waited = 0
while (-not $mysqlReady -and $waited -lt 30) {
    Start-Sleep -Seconds 2
    $waited += 2
    Write-Host "." -NoNewline
    $ping = docker exec dlrg-mysql mysqladmin ping -h localhost -uroot -proot 2>&1
    if ($ping -match "is alive") { $mysqlReady = $true }
}
Write-Host ""
if (-not $mysqlReady) {
    Write-Host "  FEHLER: MySQL antwortet nicht." -ForegroundColor Red
    Read-Host "Enter zum Beenden"
    exit 1
}
Write-Host "  MySQL bereit." -ForegroundColor Green

# 4. Trigger-Berechtigung setzen
Write-Host "`n[4/5] MySQL Trigger-Berechtigung setzen..." -ForegroundColor Yellow
docker exec dlrg-mysql mysql -uroot -proot -e "SET GLOBAL log_bin_trust_function_creators = 1;" 2>$null
Write-Host "  Berechtigung gesetzt." -ForegroundColor Green

# 5. PHP-Server starten
Write-Host "`n[5/5] PHP-Server starten auf http://localhost:8000..." -ForegroundColor Yellow
$phpProc = Get-Process php -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like "*localhost:8000*" }
if ($phpProc) {
    Write-Host "  PHP-Server laeuft bereits (PID $($phpProc.Id))." -ForegroundColor Green
} else {
    Start-Process -FilePath "php" -ArgumentList "-S","localhost:8000","-t","live_app" -WorkingDirectory $projectRoot -WindowStyle Minimized
    Write-Host "  PHP-Server gestartet." -ForegroundColor Green
}

# 6. Browser oeffnen
Write-Host "`n=== Fertig! ===" -ForegroundColor Green
Write-Host "  Startseite:  http://localhost:8000/" -ForegroundColor Cyan
Write-Host "  Admin-Bereich: http://localhost:8000/admin.php" -ForegroundColor Cyan
Write-Host "  Login: admin / Admin12345678`n" -ForegroundColor Cyan

Start-Process "http://localhost:8000/admin.php"

Write-Host "Druecke Enter zum Beenden (der Server laeuft weiter)." -ForegroundColor Gray
Read-Host
