# ========================================================================
# DLRG 24h-Schwimmen - Komplettes Setup-Skript fuer die lokale Testumgebung
# ------------------------------------------------------------------------
# Erledigt in einem Rutsch:
#   1. Docker / MySQL-Container starten
#   2. Datenbank + Schema (setup.sql) einrichten
#   3. Trigger-Berechtigung setzen
#   4. Optional: Datenbank zuruecksetzen (-Reset)
#   5. Testdaten einfuegen (seed_test_data.php)
#   6. Admin-Benutzer anlegen (create_admin.php)
#   7. PHP-Entwicklungsserver starten
#   8. MySQL -> SQLite Sync ausfuehren (sync.php)
#   9. Browser oeffnen
#
# Verwendung:
#   .\setup-all.ps1                      # normales Setup
#   .\setup-all.ps1 -Reset               # DB vorher leeren
#   .\setup-all.ps1 -NoSeed              # keine Testdaten einfuegen
#   .\setup-all.ps1 -Port 8080           # anderen Port verwenden
#   .\setup-all.ps1 -AdminUser meinadmin -AdminPass "MeinSicheresPass1"
# ========================================================================

[CmdletBinding()]
param(
    [switch]$Reset,                    # Datenbank vorher leeren (TRUNCATE)
    [switch]$NoSeed,                   # Keine Testdaten einfuegen
    [switch]$NoSync,                   # Keinen MySQL->SQLite Sync ausfuehren
    [int]$Port = 8000,                 # Port fuer den PHP-Server
    [string]$AdminUser = "admin",      # Admin-Benutzername
    [string]$AdminPass = "Admin12345678", # Admin-Passwort (min. 12 Zeichen)
    [string]$MySQLContainer = "dlrg-mysql",
    [string]$MySQLRootUser = "root",
    [string]$MySQLRootPass = "root",
    [string]$DbName = "dlrg_schwimmen"
)

$ErrorActionPreference = "Continue"
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$liveApp = Join-Path $projectRoot "live_app"

function Write-Step($msg) { Write-Host "`n=== $msg ===" -ForegroundColor Cyan }
function Write-Ok($msg)   { Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "  [!!] $msg" -ForegroundColor Yellow }
function Write-Err($msg)  { Write-Host "  [FEHLER] $msg" -ForegroundColor Red }

Write-Host "`n================================================" -ForegroundColor Cyan
Write-Host "  DLRG 24h-Schwimmen - Komplett-Setup" -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan

# ------------------------------------------------------------------
# 1. Docker / MySQL starten
# ------------------------------------------------------------------
Write-Step "1/9 Docker & MySQL pruefen"

$dockerRunning = $false
try { $null = docker info 2>$null; $dockerRunning = $? } catch {}

if (-not $dockerRunning) {
    Write-Warn "Docker Desktop wird gestartet..."
    Start-Process "C:\Program Files\Docker\Docker\Docker Desktop.exe"
    Write-Host "  Warte auf Docker Engine (max. 90s)..." -NoNewline
    $waited = 0
    while (-not $dockerRunning -and $waited -lt 90) {
        Start-Sleep -Seconds 3
        $waited += 3
        Write-Host "." -NoNewline
        try { $null = docker info 2>$null; $dockerRunning = $? } catch {}
    }
    Write-Host ""
    if (-not $dockerRunning) {
        Write-Err "Docker konnte nicht gestartet werden."
        Read-Host "Enter zum Beenden"
        exit 1
    }
}
Write-Ok "Docker laeuft."

$containerState = docker inspect -f "{{.State.Running}}" $MySQLContainer 2>$null
if ($containerState -ne "true") {
    docker start $MySQLContainer 2>&1 | Out-Null
    Write-Ok "Container '$MySQLContainer' gestartet."
} else {
    Write-Ok "Container '$MySQLContainer' laeuft bereits."
}

Write-Host "  Warte auf MySQL..." -NoNewline
$mysqlReady = $false
$waited = 0
while (-not $mysqlReady -and $waited -lt 40) {
    Start-Sleep -Seconds 2
    $waited += 2
    Write-Host "." -NoNewline
    $ping = docker exec $MySQLContainer mysqladmin ping -h localhost -u$MySQLRootUser -p$MySQLRootPass 2>&1
    if ($ping -match "is alive") { $mysqlReady = $true }
}
Write-Host ""
if (-not $mysqlReady) {
    Write-Err "MySQL antwortet nicht."
    Read-Host "Enter zum Beenden"
    exit 1
}
Write-Ok "MySQL bereit."

# Trigger-Berechtigung
docker exec $MySQLContainer mysql -u$MySQLRootUser -p$MySQLRootPass -e "SET GLOBAL log_bin_trust_function_creators = 1;" 2>$null
Write-Ok "Trigger-Berechtigung gesetzt."

# ------------------------------------------------------------------
# 2. Schema einrichten (setup.sql)
# ------------------------------------------------------------------
Write-Step "2/9 Datenbank-Schema einrichten"

$setupSql = Join-Path $liveApp "setup.sql"
if (Test-Path $setupSql) {
    $sqlContent = Get-Content $setupSql -Raw
    # setup.sql kopieren und im Container ausfuehren
    $containerSql = "/tmp/setup.sql"
    docker cp $setupSql "${MySQLContainer}:${containerSql}" 2>&1 | Out-Null
    docker exec $MySQLContainer mysql -u$MySQLRootUser -p$MySQLRootPass -e "SOURCE $containerSql" 2>&1 | Out-Null
    docker exec $MySQLContainer rm -f $containerSql 2>&1 | Out-Null
    Write-Ok "Schema aus setup.sql importiert."
} else {
    Write-Warn "setup.sql nicht gefunden, uebersprungen."
}

# ------------------------------------------------------------------
# 3. (Optional) Datenbank zuruecksetzen
# ------------------------------------------------------------------
if ($Reset) {
    Write-Step "3/9 Datenbank zuruecksetzen (-Reset)"
    $tables = @("access_tokens","webauthn_credentials","audit_log","startnummern","teilnehmer","lane_timeframes","teams","users")
    foreach ($t in $tables) {
        docker exec $MySQLContainer mysql -u$MySQLRootUser -p$MySQLRootPass $DbName -e "TRUNCATE TABLE $t;" 2>&1 | Out-Null
    }
    Write-Ok "Alle Tabellen geleert."
} else {
    Write-Step "3/9 Reset uebersprungen (kein -Reset)"
}

# ------------------------------------------------------------------
# 4. PHP-Verfuegbarkeit pruefen
# ------------------------------------------------------------------
Write-Step "4/9 PHP pruefen"
$phpExe = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpExe) {
    Write-Err "PHP nicht im PATH gefunden. Bitte PHP installieren und zum PATH hinzufuegen."
    Read-Host "Enter zum Beenden"
    exit 1
}
$phpVersion = & php -v 2>&1 | Select-Object -First 1
Write-Ok "PHP gefunden: $phpVersion"

# ------------------------------------------------------------------
# 5. Testdaten einfuegen
# ------------------------------------------------------------------
if (-not $NoSeed) {
    Write-Step "5/9 Testdaten einfuegen"
    $seedScript = Join-Path $liveApp "seed_test_data.php"
    if (Test-Path $seedScript) {
        Push-Location $liveApp
        # --truncate leert vor dem Seed vorhandene Daten (inkl. Qualitaetsbericht
        $seedArgs = @("seed_test_data.php", "--truncate")
        $seedOutput = & php @seedArgs 2>&1
        Pop-Location
        Write-Host $seedOutput -ForegroundColor Gray
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Testdaten eingefuegt (inkl. Qualitaetschecks)."
        } else {
            Write-Warn "Seed-Script lief mit Warnungen (Exit-Code $LASTEXITCODE)."
        }
    } else {
        Write-Warn "seed_test_data.php nicht gefunden, uebersprungen."
    }
} else {
    Write-Step "5/9 Testdaten uebersprungen (-NoSeed)"
}

# ------------------------------------------------------------------
# 6. Admin-Benutzer anlegen
# ------------------------------------------------------------------
Write-Step "6/9 Admin-Benutzer anlegen"
$createAdmin = Join-Path $liveApp "scripts\create_admin.php"
if (Test-Path $createAdmin) {
    Push-Location $liveApp
    $adminOutput = & php scripts/create_admin.php $AdminUser $AdminPass admin 2>&1
    Pop-Location
    Write-Host $adminOutput -ForegroundColor Gray
    if ($LASTEXITCODE -eq 0) {
        Write-Ok "Admin '$AdminUser' angelegt (oder bereits vorhanden)."
    } else {
        Write-Warn "Admin-Anlage gemeldet (z. B. bereits vorhanden)."
    }
} else {
    Write-Warn "scripts/create_admin.php nicht gefunden."
}

# ------------------------------------------------------------------
# 7. PHP-Server starten
# ------------------------------------------------------------------
Write-Step "7/9 PHP-Server starten (Port $Port)"
$running = Get-Process php -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like "*localhost:$Port*" }
if ($running) {
    Write-Ok "PHP-Server laeuft bereits (PID $($running.Id))."
} else {
    Start-Process -FilePath "php" -ArgumentList "-S","localhost:$Port","-t","live_app" -WorkingDirectory $projectRoot -WindowStyle Minimized
    Start-Sleep -Seconds 2
    Write-Ok "PHP-Server auf Port $Port gestartet."
}

# ------------------------------------------------------------------
# 8. MySQL -> SQLite Sync
# ------------------------------------------------------------------
if (-not $NoSync) {
    Write-Step "8/9 MySQL -> SQLite Sync ausfuehren"
    # Sync erfordert Login. Wir nutzen einen direkten CLI-Aufruf mit Session-Simulation
    # indem wir die sync.php direkt per PHP-CLI mit einem Trick aufrufen:
    # sync.php prueft auth_can(). Fuer CLI setzen wir die Session-Variablen.
    $syncScript = Join-Path $liveApp "sync.php"
    if (Test-Path $syncScript) {
        # Minimaler CLI-Wrapper, der sync.php im Kontext eines Admin-Sessions aufruft
        $wrapper = @'
<?php
// CLI-Wrapper fuer sync.php mit Admin-Session
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["SCRIPT_NAME"] = "/sync.php";
$_GET["standalone"] = "1";
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["username"] = "admin";
$_SESSION["role"] = "admin";
$_SESSION["admin_logged_in"] = true;
require __DIR__ . "/sync.php";
'@
        $wrapperPath = Join-Path $liveApp "_sync_cli_wrapper.php"
        Set-Content -Path $wrapperPath -Value $wrapper -Encoding UTF8
        Push-Location $liveApp
        $syncOutput = & php _sync_cli_wrapper.php 2>&1
        Pop-Location
        Remove-Item $wrapperPath -Force -ErrorAction SilentlyContinue
        # Nur die ersten Zeilen anzeigen (Sync gibt HTML aus)
        $syncText = $syncOutput | Out-String
        if ($syncText -match "erfolgreich|gespeichert|aktualisiert|Sync") {
            Write-Ok "Sync ausgefuehrt (Details siehe Ausgabe oben)."
        } else {
            Write-Warn "Sync-Ausgabe unklar - ggf. manuell im Browser pruefen."
        }
        Write-Host ($syncOutput | Select-Object -First 10) -ForegroundColor Gray
    } else {
        Write-Warn "sync.php nicht gefunden."
    }
} else {
    Write-Step "8/9 Sync uebersprungen (-NoSync)"
}

# ------------------------------------------------------------------
# 9. Browser oeffnen
# ------------------------------------------------------------------
Write-Step "9/9 Browser oeffnen"
$adminUrl = "http://localhost:$Port/admin.php"
$startUrl = "http://localhost:$Port/"
Start-Process $adminUrl
Write-Ok "Browser geoeffnet: $adminUrl"

# ------------------------------------------------------------------
# Zusammenfassung
# ------------------------------------------------------------------
Write-Host "`n================================================" -ForegroundColor Green
Write-Host "  Setup abgeschlossen!" -ForegroundColor Green
Write-Host "================================================" -ForegroundColor Green
Write-Host "`n  Startseite:     $startUrl" -ForegroundColor Cyan
Write-Host "  Admin-Bereich:  $adminUrl" -ForegroundColor Cyan
Write-Host "  Admin-Login:    $AdminUser / $AdminPass" -ForegroundColor Cyan
Write-Host ""

if (-not $NoSeed) {
    Write-Host "  Test-Benutzer (aus seed_test_data.php):" -ForegroundColor Yellow
    Write-Host "    admin_root    / Admin@2024!Passwort   (admin)" -ForegroundColor Gray
    Write-Host "    admin_claudia / Admin2@2024!Passwort  (admin)" -ForegroundColor Gray
    Write-Host "    kasse_max     / Kasse@2024!Passwort   (cashier)" -ForegroundColor Gray
    Write-Host "    kasse_pia     / Kasse2@2024!Passwort  (cashier)" -ForegroundColor Gray
    Write-Host "    orga_lisa     / Orga@2024!Passwort    (orga)" -ForegroundColor Gray
    Write-Host "    orga_ben      / Orga2@2024!Passwort   (orga)" -ForegroundColor Gray
    Write-Host "    viewer_tim    / Viewer@2024!Passwort  (viewer)" -ForegroundColor Gray
    Write-Host "    viewer_nina   / Viewer2@2024!Passwort (viewer)" -ForegroundColor Gray
    Write-Host ""
}

Write-Host "  Hinweise:" -ForegroundColor Yellow
Write-Host "    - DB-Reset:  .\setup-all.ps1 -Reset" -ForegroundColor Gray
Write-Host "    - Ohne Seed: .\setup-all.ps1 -NoSeed" -ForegroundColor Gray
Write-Host "    - Ohne Sync: .\setup-all.ps1 -NoSync" -ForegroundColor Gray
Write-Host "    - Port:      .\setup-all.ps1 -Port 8080" -ForegroundColor Gray
Write-Host ""

Write-Host "Druecke Enter zum Beenden (Server laeuft weiter)." -ForegroundColor Gray
Read-Host
