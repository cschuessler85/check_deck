<?php
/**
 * @doc-file
 * @doc-id live_app.scripts.live_sync
 * @doc-kind script
 * @doc-purpose Fuehrt die MySQL->SQLite-Bruecke einmalig aus und garantiert so per Cron (z. B. jede Minute)
 *             einen maximal 60 Sekunden alten Live-Stand fuer den Lane Counter.
 * @doc-entry CLI-Aufruf durch Betrieb oder Cron.
 * @doc-input Umgebung, bezahlte MySQL-Teilnehmer und die SQLite-Live-Datenbank.
 * @doc-output Statuszeile mit dem Sync-Ergebnis.
 * @doc-side-effects Aktualisiert die schwimmer-Zeilen der SQLite-Live-Datenbank.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once dirname(__DIR__) . '/includes/db_connect.php';
require_once dirname(__DIR__) . '/includes/live_sync.php';

// @doc-note Beabsichtigt ohne Drosselung: der Cron-Aufruf soll immer synchronisieren.
$report = live_sync_run_safe($pdo);

if ($report === null) {
    fwrite(STDERR, "Live-Sync nicht ausgefuehrt (data.sqlite fehlt, Lock aktiv oder Fehler).\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Live-Sync ok: %d neu, %d aktualisiert (%d Kandidaten).\n",
    (int)$report['inserted'],
    (int)$report['updated'],
    (int)$report['total_candidates']
));
exit(0);
