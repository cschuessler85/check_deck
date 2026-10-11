<?php
/**
 * @doc-file
 * @doc-id live_app.diagnose
 * @doc-kind page-controller
 * @doc-purpose Stellt Diagnoseinformationen fuer Betrieb, Konfiguration und Datenquellen bereit.
 * @doc-entry HTTP-Aufruf durch berechtigte Benutzer.
 * @doc-input Session, Datenbank, Umgebung und Laufzeitinformationen.
 * @doc-output HTML oder Textausgabe mit Diagnosewerten.
 * @doc-side-effects Liest Systemzustand und kann Fehlersituationen sichtbar machen.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';

$checks = [];

try {
    $checks[] = ['name' => 'PDO-Verbindung', 'status' => 'ok', 'details' => 'Datenbankverbindung hergestellt'];
    $checks[] = [
        'name' => 'Tabellenanzahl',
        'status' => 'ok',
        'details' => 'Gefundene Tabellen: ' . (string)$pdo->query('SHOW TABLES')->rowCount(),
    ];
    $checks[] = [
        'name' => 'Backup-Ordner',
        'status' => is_dir(APP_ROOT . '/storage/backups') ? 'ok' : 'warn',
        'details' => APP_ROOT . '/storage/backups',
    ];
    $checks[] = [
        'name' => 'Konfiguration',
        'status' => is_readable(APP_ROOT . '/config/.env') ? 'ok' : 'fail',
        'details' => APP_ROOT . '/config/.env',
    ];
} catch (Throwable $e) {
    fwrite(STDERR, "Diagnose fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($checks as $check) {
    fwrite(STDOUT, sprintf("[%s] %s - %s\n", strtoupper((string)$check['status']), (string)$check['name'], (string)$check['details']));
}
