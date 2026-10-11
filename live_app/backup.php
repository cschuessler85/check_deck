<?php
/**
 * @doc-file
 * @doc-id live_app.backup
 * @doc-kind page-controller
 * @doc-purpose Bietet die Benutzeroberflaeche oder Ausfuehrung fuer Backup-Funktionen der Anwendung.
 * @doc-entry HTTP-Aufruf durch berechtigte Benutzer.
 * @doc-input Session, Berechtigungen, Dateisystem und Datenbank.
 * @doc-output HTML oder Statusantworten fuer Backups.
 * @doc-side-effects Kann Sicherungen erzeugen und Dateien schreiben.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';

$dbName = getenv('DB_NAME');
if ($dbName === false || $dbName === '') {
    $dbName = isset($_ENV['DB_NAME']) ? (string)$_ENV['DB_NAME'] : 'db';
}

$keepDays = 7;
$dir = APP_ROOT . '/storage/backups';
$date = date('Y-m-d_His');
$file = sprintf('%s/%s_%s.sql', $dir, preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$dbName), $date);

if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Backup-Ordner konnte nicht angelegt werden.\n");
    exit(1);
}

try {
    $tables = [];
    $stmt = $pdo->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $fh = fopen($file, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Backup-Datei konnte nicht angelegt werden.');
    }

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $tableStructure = $stmt->fetch(PDO::FETCH_ASSOC);
        fwrite($fh, "\n\n" . (string)($tableStructure['Create Table'] ?? '') . ";\n\n");

        $stmt = $pdo->query("SELECT * FROM `$table`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $keys = array_map(static function ($key): string {
                return '`' . str_replace('`', '``', (string)$key) . '`';
            }, array_keys($row));
            $values = array_map(static function ($value) use ($pdo): string {
                return $value === null ? 'NULL' : $pdo->quote((string)$value);
            }, array_values($row));
            fwrite($fh, "INSERT INTO `$table` (" . implode(', ', $keys) . ') VALUES (' . implode(', ', $values) . ");\n");
        }
    }
    fclose($fh);
    chmod($file, 0640);

    $files = glob($dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$dbName) . '_*.sql') ?: [];
    $now = time();
    foreach ($files as $existingFile) {
        if (is_file($existingFile) && ($now - filemtime($existingFile) > $keepDays * 86400)) {
            unlink($existingFile);
        }
    }

    fwrite(STDOUT, "Backup-Datei geschrieben: {$file}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Erstellen des Backups: " . $e->getMessage() . "\n");
    exit(1);
}
