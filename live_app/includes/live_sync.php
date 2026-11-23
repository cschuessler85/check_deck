<?php
/**
 * @doc-file
 * @doc-id live_app.includes.live_sync
 * @doc-kind shared-library
 * @doc-purpose Kapselt die Bruecke zwischen den bezahlten MySQL-Teilnehmern und der SQLite-Live-Datenbank
 *             (Tabelle schwimmer) inklusive sofortiger Synchronisation und gedrosseltem 60-Sekunden-Fallback.
 * @doc-entry Wird von Admin-Aktionen, Live-Endpunkten, sync.php und CLI-Skripten eingebunden.
 * @doc-input PDO-Verbindungen zu MySQL und SQLite sowie der Sync-Marker im storage-Verzeichnis.
 * @doc-output Sync-Berichte sowie aktualisierte schwimmer-Zeilen in der Live-Datenbank.
 * @doc-side-effects Schreibt Stammdaten in die SQLite-Tabelle schwimmer und einen Zeitstempelmarker in storage/.
 * @doc-note Nur Teilnehmer mit bezahlt=1 und einer vergebenen startnummer werden uebernommen.
 * @doc-note Live-Betriebsdaten (bahnanzahl, strecke, auf_bahn, aktiv) bleiben beim Update unberuehrt.
 */
declare(strict_types=1);

// @doc-note functions.php liefert sqlite_live_default_file_path() und sqlite_live_open() (zentrale SQLite-Factory).
require_once __DIR__ . '/functions.php';

/**
 * Liefert den Pfad zur SQLite-Live-Datenbank im Projektstamm.
 *
 * @doc-function live_sync.sync_sqlite_path
 * @doc-purpose Delegiert die Pfadaufloesung an die zentrale Quelle sqlite_live_default_file_path().
 * @doc-return string Absoluter Pfad zur data.sqlite im Projektstamm.
 *
 * @return string Absoluter Pfad zur data.sqlite im Projektstamm.
 */
function sync_sqlite_path(): string
{
    return sqlite_live_default_file_path();
}

/**
 * @doc-function live_sync.sync_escape
 * @doc-purpose Escaped einen Wert fuer die sichere Ausgabe im HTML der Sync-Seite.
 * @doc-param ?string $value Rohwert, der HTML-sicher ausgegeben werden soll.
 * @doc-return string HTML-escaped Wert; null wird dabei als leerer String behandelt.
 *
 * @param ?string $value Rohwert, der HTML-sicher ausgegeben werden soll.
 * @return string HTML-escaped Wert; null wird dabei als leerer String behandelt.
 */
function sync_escape(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * @doc-function live_sync.sync_load_candidates
 * @doc-purpose Laedt alle bezahlten Teilnehmer mit vergebener Startnummer als Sync-Kandidaten aus MySQL.
 * @doc-param PDO $pdo MySQL-Verbindung zur Teilnehmerdatenbank.
 * @doc-return list<array<string, mixed>> Kandidatendatensaetze, sortiert nach Startnummer aufsteigend.
 * @doc-note Nur bezahlte Teilnehmer mit vergebener Startnummer gelangen in die Live-Zeitnahme.
 *
 * @param PDO $pdo MySQL-Verbindung zur Teilnehmerdatenbank.
 * @return list<array<string, mixed>> Kandidatendatensaetze, sortiert nach Startnummer aufsteigend.
 */
function sync_load_candidates(PDO $pdo): array
{
    // @doc-note Nur bezahlte Teilnehmer mit vergebener Startnummer gelangen in die Live-Zeitnahme.
    $stmt = $pdo->query("
        SELECT id, code, vorname, nachname, volljaehrig, geschlecht,
               team_id, teamname, bezahlt, bezahlt_am, registriert_am, startnummer
        FROM teilnehmer
        WHERE bezahlt = 1
          AND startnummer IS NOT NULL
          AND startnummer > 0
        ORDER BY startnummer ASC
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * Oeffnet die SQLite-Datenbank schreibend oder liefert null bei fehlender Datei.
 *
 * @doc-function live_sync.sync_open_sqlite
 * @doc-purpose Delegiert das Oeffnen an die zentrale Factory sqlite_live_open() (inkl. Concurrency-PRAGMAs).
 * @doc-param string $path Absoluter Pfad zur SQLite-Datei.
 * @doc-return PDO|null Schreibende Verbindung oder null, wenn die Datei fehlt.
 */
function sync_open_sqlite(string $path): ?PDO
{
    if (!is_file($path)) {
        return null;
    }
    return sqlite_live_open($path, false);
}

/**
 * @doc-function live_sync.sync_existing_numbers
 * @doc-purpose Laedt die bestehenden schwimmer-Zeilen aus SQLite als Map von Startnummer auf Datensatz.
 * @doc-param PDO $sqlite SQLite-Verbindung zur Live-Datenbank.
 * @doc-return array<int, array<string, mixed>> Map von Startnummer auf den zugehoerigen schwimmer-Datensatz.
 *
 * @param PDO $sqlite SQLite-Verbindung zur Live-Datenbank.
 * @return array<int, array<string, mixed>> Map von Startnummer auf den zugehoerigen schwimmer-Datensatz.
 */
function sync_existing_numbers(PDO $sqlite): array
{
    $rows = $sqlite
        ->query('SELECT nummer, vorname, nachname, istKind, gruppe, bahnanzahl, strecke, auf_bahn, aktiv, sync_source, remote_teilnehmer_id FROM schwimmer ORDER BY nummer ASC')
        ->fetchAll() ?: [];
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['nummer']] = $r;
    }
    return $map;
}

/**
 * @doc-function live_sync.sync_run
 * @doc-purpose Fuehrt den eigentlichen Sync der Kandidaten in die SQLite-Live-Datenbank innerhalb einer Transaktion aus.
 * @doc-param PDO $mysql MySQL-Quellverbindung mit den Teilnehmerdaten.
 * @doc-param PDO $sqlite SQLite-Zielverbindung zur Live-Datenbank.
 * @doc-return array{inserted:int,updated:int,total_candidates:int,details:list<array<string,mixed>>} Bericht mit Anzahl eingefuegter und aktualisierter Zeilen sowie Detailprotokoll.
 * @doc-throws Throwable Wenn das Schreiben fehlschlaegt; die Transaktion wird dann zurueckgerollt und der Fehler weitergereicht.
 * @doc-note Aktualisierungen fassen nur Stammdaten an; bahnanzahl, strecke, auf_bahn und aktiv bleiben unveraendert.
 *
 * @param PDO $mysql MySQL-Quellverbindung mit den Teilnehmerdaten.
 * @param PDO $sqlite SQLite-Zielverbindung zur Live-Datenbank.
 * @return array{inserted:int,updated:int,total_candidates:int,details:list<array<string,mixed>>} Bericht mit Anzahl eingefuegter und aktualisierter Zeilen sowie Detailprotokoll.
 * @throws Throwable Wenn das Schreiben fehlschlaegt; die Transaktion wird dann zurueckgerollt und der Fehler weitergereicht.
 */
function sync_run(PDO $mysql, PDO $sqlite): array
{
    $candidates = sync_load_candidates($mysql);
    $existing = sync_existing_numbers($sqlite);

    $inserted = 0;
    $updated = 0;
    $details = [];

    // @doc-note SQLite-Transaktion: entweder werden alle Aenderungen geschrieben oder keine.
    $sqlite->beginTransaction();
    try {
        $insertStmt = $sqlite->prepare("
            INSERT INTO schwimmer
                (nummer, vorname, nachname, istKind, gruppe, bahnanzahl, strecke, auf_bahn, aktiv, sync_source, remote_teilnehmer_id, remote_updated_at)
            VALUES
                (:nummer, :vorname, :nachname, :istKind, :gruppe, 0, 0, 0, 0, :sync_source, :remote_teilnehmer_id, :remote_updated_at)
        ");

        // @doc-note Beim UPDATE werden bewusst nur die Stammdaten aktualisiert.
        // @doc-note bahnanzahl, strecke, auf_bahn, aktiv und erstellt_von_client_id bleiben unangetastet.
        $updateStmt = $sqlite->prepare("
            UPDATE schwimmer
            SET vorname = :vorname,
                nachname = :nachname,
                istKind = :istKind,
                gruppe = :gruppe,
                sync_source = :sync_source,
                remote_teilnehmer_id = :remote_teilnehmer_id,
                remote_updated_at = :remote_updated_at
            WHERE nummer = :nummer
        ");

        foreach ($candidates as $t) {
            $nummer = (int)$t['startnummer'];
            $vorname = (string)$t['vorname'];
            $nachname = (string)$t['nachname'];
            // @doc-note istKind ist das Gegenteil von volljaehrig.
            $istKind = (int)$t['volljaehrig'] === 1 ? 0 : 1;
            $gruppe = trim((string)($t['teamname'] ?? ''));
            $remoteId = (int)$t['id'];
            $updatedAt = (string)($t['registriert_am'] ?? date('c'));
            $syncSource = 'mysql_teilnehmer';

            if (isset($existing[$nummer])) {
                $updateStmt->execute([
                    ':nummer' => $nummer,
                    ':vorname' => $vorname,
                    ':nachname' => $nachname,
                    ':istKind' => $istKind,
                    ':gruppe' => $gruppe,
                    ':sync_source' => $syncSource,
                    ':remote_teilnehmer_id' => $remoteId,
                    ':remote_updated_at' => $updatedAt,
                ]);
                $updated++;
                $details[] = ['nummer' => $nummer, 'action' => 'update', 'name' => $vorname . ' ' . $nachname];
            } else {
                $insertStmt->execute([
                    ':nummer' => $nummer,
                    ':vorname' => $vorname,
                    ':nachname' => $nachname,
                    ':istKind' => $istKind,
                    ':gruppe' => $gruppe,
                    ':sync_source' => $syncSource,
                    ':remote_teilnehmer_id' => $remoteId,
                    ':remote_updated_at' => $updatedAt,
                ]);
                $inserted++;
                $details[] = ['nummer' => $nummer, 'action' => 'insert', 'name' => $vorname . ' ' . $nachname];
            }
        }

        $sqlite->commit();
    } catch (Throwable $e) {
        $sqlite->rollBack();
        throw $e;
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'total_candidates' => count($candidates),
        'details' => $details,
    ];
}

/**
 * @doc-function live_sync.sync_preview
 * @doc-purpose Berechnet die Sync-Vorschau ohne Schreibzugriffe auf die Live-Datenbank.
 * @doc-param PDO $mysql MySQL-Quellverbindung mit den Teilnehmerdaten.
 * @doc-param PDO $sqlite SQLite-Zielverbindung zur Live-Datenbank.
 * @doc-return array{candidates:int,to_insert:list<array<string,mixed>>,to_update:list<array<string,mixed>>,existing_count:int} Vorschau mit Kandidatenzahl, neu anzulegenden und zu aktualisierenden Eintraegen sowie Bestandszahl.
 *
 * @param PDO $mysql MySQL-Quellverbindung mit den Teilnehmerdaten.
 * @param PDO $sqlite SQLite-Zielverbindung zur Live-Datenbank.
 * @return array{candidates:int,to_insert:list<array<string,mixed>>,to_update:list<array<string,mixed>>,existing_count:int} Vorschau mit Kandidatenzahl, neu anzulegenden und zu aktualisierenden Eintraegen sowie Bestandszahl.
 */
function sync_preview(PDO $mysql, PDO $sqlite): array
{
    $candidates = sync_load_candidates($mysql);
    $existing = sync_existing_numbers($sqlite);

    $toInsert = [];
    $toUpdate = [];

    foreach ($candidates as $t) {
        $nummer = (int)$t['startnummer'];
        $entry = [
            'nummer' => $nummer,
            'vorname' => (string)$t['vorname'],
            'nachname' => (string)$t['nachname'],
            'istKind' => (int)$t['volljaehrig'] === 1 ? 0 : 1,
            'gruppe' => trim((string)($t['teamname'] ?? '')),
            'remote_id' => (int)$t['id'],
        ];
        if (isset($existing[$nummer])) {
            $entry['existing'] = $existing[$nummer];
            $toUpdate[] = $entry;
        } else {
            $toInsert[] = $entry;
        }
    }

    return [
        'candidates' => count($candidates),
        'to_insert' => $toInsert,
        'to_update' => $toUpdate,
        'existing_count' => count($existing),
    ];
}

/**
 * @doc-function live_sync.live_sync_storage_dir
 * @doc-purpose Liefert ein garantiert beschreibbares Verzeichnis fuer Lock- und Markerdatei.
 * @doc-return string Absoluter Pfad zum storage-Verzeichnis oder ersatzweise neben der data.sqlite.
 * @doc-note Bevorzugt storage/; ist das nicht beschreibbar, wird das Verzeichnis der data.sqlite genutzt,
 *           das durch den Sync ohnehin beschreibbar sein muss.
 *
 * @return string Absoluter Pfad zum Zustandsverzeichnis.
 */
function live_sync_storage_dir(): string
{
    $appStorage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($appStorage)) {
        @mkdir($appStorage, 0775, true);
    }
    if (is_dir($appStorage) && is_writable($appStorage)) {
        return $appStorage;
    }

    // @doc-note Fallback: neben der data.sqlite ist Schreibzugriff garantiert, weil der Sync sie dort schreibt.
    return dirname(sync_sqlite_path());
}

/**
 * @doc-function live_sync.live_sync_marker_path
 * @doc-purpose Pfad der Markerdatei, die den Zeitpunkt des letzten erfolgreichen Syncs festhaelt.
 * @doc-return string Absoluter Pfad zur Markerdatei.
 *
 * @return string Absoluter Pfad zur Markerdatei.
 */
function live_sync_marker_path(): string
{
    return live_sync_storage_dir() . DIRECTORY_SEPARATOR . 'live_sync.last';
}

/**
 * @doc-function live_sync.live_sync_lock_path
 * @doc-purpose Pfad der Lockdatei, die parallele Syncs gegeneinander serialisiert.
 * @doc-return string Absoluter Pfad zur Lockdatei.
 *
 * @return string Absoluter Pfad zur Lockdatei.
 */
function live_sync_lock_path(): string
{
    return live_sync_storage_dir() . DIRECTORY_SEPARATOR . 'live_sync.lock';
}

/**
 * @doc-function live_sync.live_sync_last_run
 * @doc-purpose Liest den Unix-Zeitstempel des letzten erfolgreichen Syncs.
 * @doc-return int Zeitstempel des letzten Syncs oder 0, wenn noch keiner vermerkt ist.
 *
 * @return int Zeitstempel des letzten Syncs oder 0, wenn noch keiner vermerkt ist.
 */
function live_sync_last_run(): int
{
    $path = live_sync_marker_path();
    if (!is_file($path)) {
        return 0;
    }
    $raw = @file_get_contents($path);
    return $raw === false ? 0 : (int)trim($raw);
}

/**
 * @doc-function live_sync.live_sync_run_safe
 * @doc-purpose Fuehrt die Bruecke sofort und fehlertolerant aus; Fehler duerfen den ausloesenden Vorgang nie stoeren.
 * @doc-param ?PDO $mysql Optionale MySQL-Verbindung; ohne Angabe wird die globale $pdo verwendet.
 * @doc-return array|null Sync-Bericht oder null, wenn kein Sync moeglich war (fehlendes $pdo, fehlende data.sqlite, Lock aktiv oder Fehler).
 * @doc-note Ein non-blockendes Lock verhindert, dass parallel laufende Syncs sich in die Quere kommen.
 *
 * @param ?PDO $mysql Optionale MySQL-Verbindung; ohne Angabe wird die globale $pdo verwendet.
 * @return array|null Sync-Bericht oder null, wenn kein Sync moeglich war.
 */
function live_sync_run_safe(?PDO $mysql = null): ?array
{
    if ($mysql === null) {
        $globalPdo = $GLOBALS['pdo'] ?? null;
        $mysql = $globalPdo instanceof PDO ? $globalPdo : null;
    }
    if (!$mysql instanceof PDO) {
        return null;
    }

    $sqlitePath = sync_sqlite_path();
    if (!is_file($sqlitePath)) {
        return null;
    }

    $lock = @fopen(live_sync_lock_path(), 'c');
    if ($lock === false) {
        return null;
    }

    // @doc-note Non-blockendes Lock: laeuft bereits ein Sync, wird dieser Aufruf uebersprungen.
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return null;
    }

    try {
        $sqlite = sync_open_sqlite($sqlitePath);
        if ($sqlite === null) {
            return null;
        }
        $report = sync_run($mysql, $sqlite);
        @file_put_contents(live_sync_marker_path(), (string)time(), LOCK_EX);
        return $report;
    } catch (Throwable $e) {
        // @doc-note Fehler beim Sync duerfen Bezahlung bzw. Anzeige nicht unterbrechen.
        return null;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * @doc-function live_sync.live_sync_ensure_fresh
 * @doc-purpose Stellt sicher, dass die Live-Datenbank hoechstens $maxAgeSeconds alt ist (gedrosselter Fallback).
 * @doc-param ?PDO $mysql Optionale MySQL-Verbindung; ohne Angabe wird die globale $pdo verwendet.
 * @doc-param int $maxAgeSeconds Maximales Alter des Syncs in Sekunden (Standard 60).
 * @doc-return bool true, wenn ein Sync ausgefuehrt wurde; false, wenn der Bestand noch frisch genug war oder kein Sync moeglich war.
 *
 * @param ?PDO $mysql Optionale MySQL-Verbindung; ohne Angabe wird die globale $pdo verwendet.
 * @param int $maxAgeSeconds Maximales Alter des Syncs in Sekunden (Standard 60).
 * @return bool true, wenn ein Sync ausgefuehrt wurde.
 */
function live_sync_ensure_fresh(?PDO $mysql = null, int $maxAgeSeconds = 60): bool
{
    $maxAge = max(1, $maxAgeSeconds);
    if ((time() - live_sync_last_run()) < $maxAge) {
        return false;
    }
    return live_sync_run_safe($mysql) !== null;
}
