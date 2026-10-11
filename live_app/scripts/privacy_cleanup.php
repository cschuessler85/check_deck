<?php
/**
 * @doc-file
 * @doc-id live_app.scripts.privacy_cleanup
 * @doc-kind script
 * @doc-purpose Bereinigt personenbezogene Daten nach definierten Datenschutzregeln oder Fristen.
 * @doc-entry CLI- oder Scheduler-Aufruf.
 * @doc-input Datenbank, Konfiguration und Retentionsregeln.
 * @doc-output Statusausgaben der Bereinigung.
 * @doc-side-effects Aendert oder anonymisiert Datenbankinhalte.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once dirname(__DIR__) . '/includes/db_connect.php';

$mode = isset($argv[1]) ? trim((string)$argv[1]) : 'report';
$allowedModes = ['report', 'cleanup'];

if (!in_array($mode, $allowedModes, true)) {
    fwrite(STDERR, "Verwendung: php scripts/privacy_cleanup.php [report|cleanup]\n");
    exit(1);
}

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() === 1;
};

$columnExists = static function (PDO $pdo, string $table, string $column) use ($tableExists): bool {
    if (!$tableExists($pdo, $table)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() === 1;
};

$countNonEmptyColumn = static function (PDO $pdo, string $table, string $column) use ($columnExists): int {
    if (!$columnExists($pdo, $table, $column)) {
        return 0;
    }
    $sql = sprintf(
        'SELECT COUNT(*) FROM `%s` WHERE `%s` IS NOT NULL AND `%s` <> \'\'',
        str_replace('`', '``', $table),
        str_replace('`', '``', $column),
        str_replace('`', '``', $column)
    );
    return (int)$pdo->query($sql)->fetchColumn();
};

try {
    $stats = [
        'legacy_spalte_teilnehmer_browser_agent' => $columnExists($pdo, 'teilnehmer', 'browser_agent') ? 1 : 0,
        'legacy_spalte_teilnehmer_ip_adresse' => $columnExists($pdo, 'teilnehmer', 'ip_adresse') ? 1 : 0,
        'legacy_spalte_audit_ip_hash' => $columnExists($pdo, 'audit_log', 'ip_hash') ? 1 : 0,
        'legacy_spalte_audit_ua_hash' => $columnExists($pdo, 'audit_log', 'ua_hash') ? 1 : 0,
        'legacy_daten_teilnehmer_browser_agent' => $countNonEmptyColumn($pdo, 'teilnehmer', 'browser_agent'),
        'legacy_daten_teilnehmer_ip_adresse' => $countNonEmptyColumn($pdo, 'teilnehmer', 'ip_adresse'),
        'legacy_daten_audit_ip_hash' => $countNonEmptyColumn($pdo, 'audit_log', 'ip_hash'),
        'legacy_daten_audit_ua_hash' => $countNonEmptyColumn($pdo, 'audit_log', 'ua_hash'),
    ];

    foreach ($stats as $key => $value) {
        fwrite(STDOUT, $key . ': ' . $value . PHP_EOL);
    }

    if ($mode !== 'cleanup') {
        exit(0);
    }

    $pdo->beginTransaction();
    if ($columnExists($pdo, 'teilnehmer', 'browser_agent')) {
        $pdo->exec('UPDATE teilnehmer SET browser_agent = NULL');
        $pdo->exec('ALTER TABLE teilnehmer DROP COLUMN browser_agent');
    }
    if ($columnExists($pdo, 'teilnehmer', 'ip_adresse')) {
        $pdo->exec('UPDATE teilnehmer SET ip_adresse = NULL');
        $pdo->exec('ALTER TABLE teilnehmer DROP COLUMN ip_adresse');
    }
    if ($columnExists($pdo, 'audit_log', 'ip_hash')) {
        $pdo->exec('UPDATE audit_log SET ip_hash = NULL');
        $pdo->exec('ALTER TABLE audit_log DROP COLUMN ip_hash');
    }
    if ($columnExists($pdo, 'audit_log', 'ua_hash')) {
        $pdo->exec('UPDATE audit_log SET ua_hash = NULL');
        $pdo->exec('ALTER TABLE audit_log DROP COLUMN ua_hash');
    }
    $pdo->commit();

    fwrite(STDOUT, "Legacy-Metadaten und Altspalten wurden bereinigt.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Bereinigung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}
