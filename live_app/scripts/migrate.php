<?php
/**
 * @doc-file
 * @doc-id live_app.scripts.migrate
 * @doc-kind migration-module
 * @doc-purpose Stellt Tabellen, Spalten, Indizes, Fremdschluessel und Audit-Trigger idempotent her und
 *             entkoppelt diese DDL-Arbeit vollstaendig aus dem HTTP-Request-Lifecycle.
 * @doc-entry Direkter CLI-Aufruf "php live_app/scripts/migrate.php [--force-triggers]" oder einmalige
 *            Einbindung durch live_app/includes/db_connect.php, solange die Marker-Datei fehlt.
 * @doc-input PDO-Verbindung zu MySQL/MariaDB sowie optionale Kommandozeilenparameter.
 * @doc-output Ergebnisarray mit Status, Marker-Kennzeichnung und Protokolleintraegen, im CLI zusaetzlich Textausgabe.
 * @doc-side-effects Legt Datenbankstrukturen und Trigger an, bereinigt Startnummern-Konflikte und schreibt die Marker-Datei.
 * @doc-source live_app/setup.sql
 * @doc-note live_app/setup.sql bleibt die fachliche Referenz des Schemas. Diese Datei haelt Bestands-
 *           installationen idempotent auf demselben Stand und muss inhaltlich mit setup.sql synchron bleiben.
 * @doc-note Diese Datei laeuft nur, wenn die Marker-Datei storage/.schema_v2.migrated fehlt oder der
 *           CLI-Aufruf dies explizit erzwingt. Damit entfallen Metadata-Locks im normalen Requestpfad.
 * @doc-note Audit-Trigger werden nur erzeugt, wenn sie fehlen. Eine Erneuerung bestehender Trigger
 *           erfordert den expliziten CLI-Parameter --force-triggers.
 * @doc-note Alle Migrationsschritte sind wiederholbar; ein fehlgeschlagener Pflichtschritt verhindert
 *           bewusst das Schreiben der Marker-Datei, damit der naechste Aufruf erneut migriert.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// @doc-section storage-and-marker
// @doc-purpose Kapselt Pfade, Verzeichnisanlage und die Marker-Datei, die den Migrationszustand dokumentiert.

/**
 * @doc-function live_app.scripts.migrate.app_migration_storage_dir
 * @doc-purpose Liefert das private Storage-Verzeichnis und legt es bei Bedarf an.
 * @doc-return string|null Absoluter Pfad zum Storage-Verzeichnis oder null, wenn es nicht nutzbar ist.
 */
function app_migration_storage_dir(): ?string
{
    $dir = APP_ROOT . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($dir)) {
        try {
            @mkdir($dir, 0775, true);
        } catch (Throwable $e) {
            return null;
        }
    }
    return is_dir($dir) && is_writable($dir) ? $dir : null;
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_marker_path
 * @doc-purpose Ermittelt den Pfad der Schema-Marker-Datei, deren Existenz den Abschluss der Migration signalisiert.
 * @doc-return string Absoluter Pfad der Marker-Datei.
 */
function app_migration_marker_path(): string
{
    return APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.schema_v2.migrated';
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_acquire_lock
 * @doc-purpose Sichert die Erstmigration gegen parallele Aufrufe mittels Advisory File-Lock ab.
 * @doc-param float $timeoutSeconds Maximale Wartezeit in Sekunden, bevor der Aufruf aufgibt.
 * @doc-return resource|null Offenes Lock-Handle oder null, wenn der Lock nicht innerhalb des Timeouts erworben wurde.
 */
function app_migration_acquire_lock(float $timeoutSeconds = 5.0)
{
    $dir = app_migration_storage_dir();
    if ($dir === null) {
        return null;
    }

    $handle = @fopen($dir . DIRECTORY_SEPARATOR . '.schema_v2.lock', 'c');
    if (!is_resource($handle)) {
        return null;
    }

    $deadline = microtime(true) + $timeoutSeconds;
    while (true) {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }
        if (microtime(true) >= $deadline) {
            @fclose($handle);
            return null;
        }
        usleep(150000);
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_release_lock
 * @doc-purpose Gibt einen zuvor erworbenen Advisory File-Lock wieder frei.
 * @doc-param resource|null $handle Lock-Handle aus app_migration_acquire_lock().
 * @doc-return void
 */
function app_migration_release_lock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_write_marker
 * @doc-purpose Schreibt die Marker-Datei, damit nachfolgende Requests keine DDL-Pruefungen mehr ausfuehren.
 * @doc-param string $source Kennzeichnung des ausloesenden Kontexts, u. a. "web" oder "cli".
 * @doc-return bool True bei erfolgreichem Schreiben.
 */
function app_migration_write_marker(string $source = 'runtime'): bool
{
    $dir = app_migration_storage_dir();
    if ($dir === null) {
        return false;
    }

    $payload = "schema=v2\n"
        . 'migrated_at=' . date('c') . "\n"
        . 'source=' . $source . "\n";

    return @file_put_contents($dir . DIRECTORY_SEPARATOR . '.schema_v2.migrated', $payload) !== false;
}

// @doc-section schema-introspection
// @doc-purpose Bietet schlanke Metadatenabfragen, die ausschliesslich im Migrationslauf verwendet werden.

/**
 * @doc-function live_app.scripts.migrate.app_migration_table_exists
 * @doc-purpose Prueft, ob eine Tabelle in der aktuellen Datenbank existiert.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param string $table Tabellenname.
 * @doc-return bool True, wenn die Tabelle existiert.
 */
function app_migration_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_column_exists
 * @doc-purpose Prueft, ob eine Spalte in einer Tabelle existiert.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param string $table Tabellenname.
 * @doc-param string $column Spaltenname.
 * @doc-return bool True, wenn die Spalte existiert.
 */
function app_migration_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_index_exists
 * @doc-purpose Prueft, ob ein Index oder Unique-Key in einer Tabelle existiert.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param string $table Tabellenname.
 * @doc-param string $index Indexname.
 * @doc-return bool True, wenn der Index existiert.
 */
function app_migration_index_exists(PDO $pdo, string $table, string $index): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_trigger_exists
 * @doc-purpose Prueft, ob ein Trigger in der aktuellen Datenbank existiert.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param string $trigger Triggername.
 * @doc-return bool True, wenn der Trigger existiert.
 */
function app_migration_trigger_exists(PDO $pdo, string $trigger): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? LIMIT 1');
        $stmt->execute([$trigger]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_exec
 * @doc-purpose Fuehrt einen DDL-Schritt aus und protokolliert Erfolg oder Ueberspringen.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param string $sql Auszufuehrendes SQL-Statement.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-param string $label Sprechende Bezeichnung des Schritts.
 * @doc-param bool $optional True, wenn Fehler toleriert und nur protokolliert werden sollen.
 * @doc-return bool True, wenn das Statement erfolgreich ausgefuehrt wurde.
 * @doc-throws PDOException Wenn $optional false ist und das Statement fehlschlaegt.
 */
function app_migration_exec(PDO $pdo, string $sql, array &$log, string $label, bool $optional = true): bool
{
    try {
        $pdo->exec($sql);
        $log[] = '[ok] ' . $label;
        return true;
    } catch (Throwable $e) {
        if (!$optional) {
            throw $e;
        }
        $log[] = '[skip] ' . $label . ' (' . $e->getMessage() . ')';
        return false;
    }
}

// @doc-section table-catalog
// @doc-purpose Definiert die Mengen der zu erstellenden Basistabellen in abhaengigkeitsfreier Reihenfolge.

/**
 * @doc-function live_app.scripts.migrate.app_migration_table_statements
 * @doc-purpose Liefert die CREATE-Statements der Basistabellen in FK-tauglicher Reihenfolge.
 * @doc-return array<string,string> Abbildung Tabellenname auf CREATE TABLE IF NOT EXISTS-Statement.
 */
function app_migration_table_statements(): array
{
    return [
        'teams' => "
            CREATE TABLE IF NOT EXISTS teams (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_team_name (name)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ",
        'startnummern' => "
            CREATE TABLE IF NOT EXISTS startnummern (
                nummer INT PRIMARY KEY,
                status ENUM('frei', 'reserviert', 'vergeben') NOT NULL DEFAULT 'frei',
                reserviert_fuer VARCHAR(150) DEFAULT NULL,
                team_id INT DEFAULT NULL,
                teilnehmer_id INT DEFAULT NULL,
                KEY idx_status (status),
                KEY idx_team_id (team_id),
                KEY idx_teilnehmer_id (teilnehmer_id),
                FOREIGN KEY (teilnehmer_id) REFERENCES teilnehmer(id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ",
        'users' => "
            CREATE TABLE IF NOT EXISTS users (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','cashier','orga','viewer') NOT NULL DEFAULT 'viewer',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_username (username)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ",
        'lane_timeframes' => "
            CREATE TABLE IF NOT EXISTS lane_timeframes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_lane_timeframes_active_sort (active, sort_order, start_at, end_at),
                KEY idx_lane_timeframes_start_end (start_at, end_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ",
        'audit_log' => "
            CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                table_name VARCHAR(64) NOT NULL,
                action ENUM('INSERT','UPDATE','DELETE') NOT NULL,
                row_pk VARCHAR(128) NOT NULL,
                user_id INT NULL,
                username VARCHAR(60) NULL,
                role VARCHAR(20) NULL,
                old_data LONGTEXT NULL,
                new_data LONGTEXT NULL,
                KEY idx_audit_table_time (table_name, occurred_at),
                KEY idx_audit_user_time (user_id, occurred_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ",
        'access_tokens' => "
            CREATE TABLE IF NOT EXISTS access_tokens (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                description VARCHAR(200) NOT NULL,
                created_by INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                last_used_at DATETIME NULL,
                revoked TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_token_hash (token_hash),
                KEY idx_access_tokens_created_by (created_by),
                CONSTRAINT fk_access_tokens_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ",
        'webauthn_credentials' => "
            CREATE TABLE IF NOT EXISTS webauthn_credentials (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                credential_id VARCHAR(512) NOT NULL,
                public_key TEXT NOT NULL,
                name VARCHAR(100) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                UNIQUE KEY uniq_credential_id (credential_id),
                KEY idx_webauthn_user_id (user_id),
                CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ",
    ];
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_ensure_tables
 * @doc-purpose Erstellt alle fehlenden Basistabellen inklusive access_tokens und webauthn_credentials.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 * @doc-throws PDOException Wenn eine Pflichttabelle nicht angelegt werden kann.
 */
function app_migration_ensure_tables(PDO $pdo, array &$log): void
{
    foreach (app_migration_table_statements() as $table => $sql) {
        app_migration_exec($pdo, $sql, $log, 'Tabelle ' . $table, false);
    }
}

// @doc-section column-and-index-migration
// @doc-purpose Bringt Bestandsinstallationen auf den Spalten- und Indexstand des Referenzschemas.

/**
 * @doc-function live_app.scripts.migrate.app_migration_ensure_columns
 * @doc-purpose Ergaenzt fehlende Spalten und Indizes in teilnehmer, startnummern und lane_timeframes.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 */
function app_migration_ensure_columns(PDO $pdo, array &$log): void
{
    if (app_migration_table_exists($pdo, 'teilnehmer')
        && !app_migration_column_exists($pdo, 'teilnehmer', 'startnummer')) {
        app_migration_exec($pdo, 'ALTER TABLE teilnehmer ADD COLUMN startnummer INT DEFAULT NULL', $log, 'Spalte teilnehmer.startnummer');
    }

    if (app_migration_table_exists($pdo, 'teilnehmer')
        && !app_migration_column_exists($pdo, 'teilnehmer', 'team_id')) {
        app_migration_exec($pdo, 'ALTER TABLE teilnehmer ADD COLUMN team_id INT DEFAULT NULL', $log, 'Spalte teilnehmer.team_id');
    }

    if (app_migration_table_exists($pdo, 'teilnehmer')
        && !app_migration_index_exists($pdo, 'teilnehmer', 'idx_teilnehmer_team_id')) {
        app_migration_exec($pdo, 'ALTER TABLE teilnehmer ADD INDEX idx_teilnehmer_team_id (team_id)', $log, 'Index idx_teilnehmer_team_id');
    }

    if (app_migration_table_exists($pdo, 'startnummern')
        && !app_migration_column_exists($pdo, 'startnummern', 'team_id')) {
        app_migration_exec($pdo, 'ALTER TABLE startnummern ADD COLUMN team_id INT DEFAULT NULL', $log, 'Spalte startnummern.team_id');
    }

    if (app_migration_table_exists($pdo, 'startnummern')
        && !app_migration_index_exists($pdo, 'startnummern', 'idx_team_id')) {
        app_migration_exec($pdo, 'ALTER TABLE startnummern ADD INDEX idx_team_id (team_id)', $log, 'Index idx_team_id');
    }

    if (app_migration_table_exists($pdo, 'lane_timeframes')) {
        if (!app_migration_column_exists($pdo, 'lane_timeframes', 'active')) {
            app_migration_exec($pdo, 'ALTER TABLE lane_timeframes ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1', $log, 'Spalte lane_timeframes.active');
        }
        if (!app_migration_column_exists($pdo, 'lane_timeframes', 'sort_order')) {
            app_migration_exec($pdo, 'ALTER TABLE lane_timeframes ADD COLUMN sort_order INT NOT NULL DEFAULT 0', $log, 'Spalte lane_timeframes.sort_order');
        }
        if (!app_migration_column_exists($pdo, 'lane_timeframes', 'created_at')) {
            app_migration_exec($pdo, 'ALTER TABLE lane_timeframes ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $log, 'Spalte lane_timeframes.created_at');
        }
        if (!app_migration_column_exists($pdo, 'lane_timeframes', 'updated_at')) {
            app_migration_exec($pdo, 'ALTER TABLE lane_timeframes ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $log, 'Spalte lane_timeframes.updated_at');
        }
        if (!app_migration_index_exists($pdo, 'lane_timeframes', 'idx_lane_timeframes_active_sort')) {
            app_migration_exec($pdo, 'ALTER TABLE lane_timeframes ADD INDEX idx_lane_timeframes_active_sort (active, sort_order, start_at, end_at)', $log, 'Index idx_lane_timeframes_active_sort');
        }
        if (!app_migration_index_exists($pdo, 'lane_timeframes', 'idx_lane_timeframes_start_end')) {
            app_migration_exec($pdo, 'ALTER TABLE lane_timeframes ADD INDEX idx_lane_timeframes_start_end (start_at, end_at)', $log, 'Index idx_lane_timeframes_start_end');
        }
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_drop_legacy_columns
 * @doc-purpose Entfernt historische Request-Metadaten-Spalten, sofern sie noch vorhanden sind.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 * @doc-note Die fachliche Bereinigung inklusive Berichtsfunktion liegt in scripts/privacy_cleanup.php.
 */
function app_migration_drop_legacy_columns(PDO $pdo, array &$log): void
{
    $legacy = [
        'teilnehmer' => ['browser_agent', 'ip_adresse'],
        'audit_log' => ['ip_hash', 'ua_hash'],
    ];

    foreach ($legacy as $table => $columns) {
        if (!app_migration_table_exists($pdo, $table)) {
            continue;
        }
        foreach ($columns as $column) {
            if (app_migration_column_exists($pdo, $table, $column)) {
                app_migration_exec($pdo, 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $column, $log, 'Altspalte ' . $table . '.' . $column);
            }
        }
    }
}

// @doc-section data-consistency
// @doc-purpose Stellt Startnummern-Grunddaten, Teamverknuepfungen und die Eindeutigkeit der Startnummern sicher.

/**
 * @doc-function live_app.scripts.migrate.app_migration_seed_startnummern
 * @doc-purpose Fuellt den Startnummern-Pool einmalig mit den Nummern 1 bis 999.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 */
function app_migration_seed_startnummern(PDO $pdo, array &$log): void
{
    if (!app_migration_table_exists($pdo, 'startnummern')) {
        return;
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM startnummern')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO startnummern (nummer, status) VALUES (?, 'frei')");
        for ($i = 1; $i <= 999; $i++) {
            $insert->execute([$i]);
        }
        $pdo->commit();
        $log[] = '[ok] Startnummern 1-999 initialisiert';
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_backfill_teams
 * @doc-purpose Ueberfuehrt Freitext-Teamnamen in die teams-Tabelle und synchronisiert die Verweise.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 */
function app_migration_backfill_teams(PDO $pdo, array &$log): void
{
    $steps = [
        'Teamnamen aus teilnehmer uebernommen' => "
            INSERT INTO teams (name)
            SELECT DISTINCT TRIM(teamname) AS name
            FROM teilnehmer
            WHERE teamname IS NOT NULL AND TRIM(teamname) <> ''
            ON DUPLICATE KEY UPDATE name = name
        ",
        'Teamnamen aus startnummern uebernommen' => "
            INSERT INTO teams (name)
            SELECT DISTINCT TRIM(reserviert_fuer) AS name
            FROM startnummern
            WHERE reserviert_fuer IS NOT NULL AND TRIM(reserviert_fuer) <> ''
            ON DUPLICATE KEY UPDATE name = name
        ",
        'teilnehmer.team_id gesetzt' => "
            UPDATE teilnehmer t
            INNER JOIN teams te ON te.name = TRIM(t.teamname)
            SET t.team_id = te.id
            WHERE t.team_id IS NULL AND t.teamname IS NOT NULL AND TRIM(t.teamname) <> ''
        ",
        'startnummern.team_id gesetzt' => "
            UPDATE startnummern s
            INNER JOIN teams te ON te.name = TRIM(s.reserviert_fuer)
            SET s.team_id = te.id
            WHERE s.team_id IS NULL AND s.reserviert_fuer IS NOT NULL AND TRIM(s.reserviert_fuer) <> ''
        ",
        'teilnehmer.teamname normalisiert' => "
            UPDATE teilnehmer t
            INNER JOIN teams te ON te.id = t.team_id
            SET t.teamname = te.name
            WHERE t.team_id IS NOT NULL AND t.team_id <> 0 AND (t.teamname IS NULL OR t.teamname = '' OR t.teamname <> te.name)
        ",
        'startnummern.reserviert_fuer normalisiert' => "
            UPDATE startnummern s
            INNER JOIN teams te ON te.id = s.team_id
            SET s.reserviert_fuer = te.name
            WHERE s.team_id IS NOT NULL AND s.team_id <> 0 AND s.status = 'reserviert' AND (s.reserviert_fuer IS NULL OR s.reserviert_fuer = '' OR s.reserviert_fuer <> te.name)
        ",
    ];

    foreach ($steps as $label => $sql) {
        try {
            $affected = $pdo->exec($sql);
            $log[] = '[ok] ' . $label . ' (betroffen: ' . var_export($affected, true) . ')';
        } catch (Throwable $e) {
            $log[] = '[skip] ' . $label . ' (' . $e->getMessage() . ')';
        }
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_ensure_team_foreign_keys
 * @doc-purpose Legt die Fremdschluessel zwischen Teilnehmern, Startnummern und Teams an, sofern sie fehlen.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 */
function app_migration_ensure_team_foreign_keys(PDO $pdo, array &$log): void
{
    app_migration_exec(
        $pdo,
        'ALTER TABLE teilnehmer ADD CONSTRAINT fk_teilnehmer_team_id FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL',
        $log,
        'Fremdschluessel fk_teilnehmer_team_id'
    );
    app_migration_exec(
        $pdo,
        'ALTER TABLE startnummern ADD CONSTRAINT fk_startnummern_team_id FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL',
        $log,
        'Fremdschluessel fk_startnummern_team_id'
    );
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_enforce_unique_startnummer
 * @doc-purpose Bereinigt ungueltige Startnummern und erzwingt die Eindeutigkeit von teilnehmer.startnummer.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 * @doc-note Nullwerte bleiben von der Unique-Regel unberuehrt, da MySQL mehrere NULL-Werte in einem
 *           Unique-Index zulaesst. Unzugewiesene Teilnehmer sind daher weiterhin moeglich.
 */
function app_migration_enforce_unique_startnummer(PDO $pdo, array &$log): void
{
    if (!app_migration_table_exists($pdo, 'teilnehmer')
        || !app_migration_column_exists($pdo, 'teilnehmer', 'startnummer')) {
        return;
    }

    $hasUnique = false;
    try {
        $stmt = $pdo->prepare("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teilnehmer' AND INDEX_NAME = 'uniq_teilnehmer_startnummer' LIMIT 1");
        $stmt->execute();
        $nonUnique = $stmt->fetchColumn();
        $hasUnique = ($nonUnique !== false && (int)$nonUnique === 0);
    } catch (Throwable $e) {
        $hasUnique = false;
    }

    if ($hasUnique) {
        return;
    }

    try {
        $pdo->exec('UPDATE teilnehmer SET startnummer = NULL WHERE startnummer = 0');
        $pdo->exec(
            'UPDATE teilnehmer t
             INNER JOIN (
                 SELECT startnummer, MIN(id) AS keep_id
                 FROM teilnehmer
                 WHERE startnummer IS NOT NULL AND startnummer > 0
                 GROUP BY startnummer
                 HAVING COUNT(*) > 1
             ) dup ON dup.startnummer = t.startnummer AND t.id <> dup.keep_id
             SET t.startnummer = NULL'
        );
        $log[] = '[ok] Startnummern bereinigt (0 und Dubletten auf NULL gesetzt)';
    } catch (Throwable $e) {
        $log[] = '[skip] Startnummern-Bereinigung (' . $e->getMessage() . ')';
    }

    if (app_migration_index_exists($pdo, 'teilnehmer', 'idx_teilnehmer_startnummer')) {
        app_migration_exec($pdo, 'ALTER TABLE teilnehmer DROP INDEX idx_teilnehmer_startnummer', $log, 'Alter Index idx_teilnehmer_startnummer entfernt');
    }

    try {
        $pdo->exec('ALTER TABLE teilnehmer ADD UNIQUE KEY uniq_teilnehmer_startnummer (startnummer)');
        $log[] = '[ok] Unique-Key uniq_teilnehmer_startnummer angelegt';
    } catch (Throwable $e) {
        $log[] = '[FEHLER] Unique-Key uniq_teilnehmer_startnummer konnte nicht angelegt werden (' . $e->getMessage() . ')';
        error_log('Schema-Migration: Unique-Key uniq_teilnehmer_startnummer fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_repair_startnummer_consistency
 * @doc-purpose Heilt einmalig verwaiste Zuordnungen zwischen teilnehmer und startnummern.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 * @doc-note Nacharbeiten zu Befund B3: Das frueher bei jedem GET ausgefuehrte Reparatur-UPDATE aus numbers.php
 *           wird hier idempotent und ausserhalb des Request-Pfads aufgehoben. Der WHERE-Filter sorgt dafuer,
 *           dass ein zweiter Lauf keine Zeilen mehr aendert.
 * @doc-note Die Richtung ist bewusst einseitig: teilnehmer.startnummer gilt als fuehrend, die zugehoerige
 *           Zeile in startnummern wird entsprechend nachgezogen.
 */
function app_migration_repair_startnummer_consistency(PDO $pdo, array &$log): void
{
    if (!app_migration_table_exists($pdo, 'startnummern')
        || !app_migration_table_exists($pdo, 'teilnehmer')) {
        return;
    }

    try {
        $affected = $pdo->exec("
            UPDATE startnummern s
            INNER JOIN teilnehmer t ON t.startnummer = s.nummer
            SET s.status = 'vergeben', s.teilnehmer_id = t.id, s.reserviert_fuer = NULL, s.team_id = NULL
            WHERE s.status <> 'vergeben' OR s.teilnehmer_id IS NULL OR s.teilnehmer_id <> t.id
        ");
        $log[] = '[ok] Startnummern-Konsistenz repariert (betroffen: ' . var_export($affected, true) . ')';
    } catch (Throwable $e) {
        $log[] = '[skip] Startnummern-Konsistenz-Reparatur (' . $e->getMessage() . ')';
        error_log('Schema-Migration: Startnummern-Konsistenz-Reparatur fehlgeschlagen: ' . $e->getMessage());
    }
}

// @doc-section audit-triggers
// @doc-purpose Beschreibt und pflegt die Audit-Trigger, die Datenbankaenderungen in audit_log protokollieren.

/**
 * @doc-function live_app.scripts.migrate.app_migration_audit_catalog
 * @doc-purpose Hinterlegt je ueberwachter Tabelle den Primaerschluessel und die zu protokollierenden Spalten.
 * @doc-return array<string,array{pk:string,columns:array<int,string>}> Katalog der Audit-Tabellen.
 */
function app_migration_audit_catalog(): array
{
    return [
        'teilnehmer' => [
            'pk' => 'id',
            'columns' => ['id', 'code', 'vorname', 'nachname', 'team_id', 'teamname', 'bezahlt', 'bezahlt_am', 'startnummer'],
        ],
        'startnummern' => [
            'pk' => 'nummer',
            'columns' => ['nummer', 'status', 'reserviert_fuer', 'team_id', 'teilnehmer_id'],
        ],
        'users' => [
            'pk' => 'id',
            'columns' => ['id', 'username', 'role', 'created_at'],
        ],
    ];
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_trigger_definitions
 * @doc-purpose Erzeugt die Triggerdefinitionen fuer alle vorhandenen Audit-Tabellen.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-return array<string,string> Abbildung Triggername auf CREATE TRIGGER-Statement.
 * @doc-note Die Trigger lesen den ausloesenden Benutzer aus den Session-Variablen
 *           @audit_user_id, @audit_username und @audit_role.
 */
function app_migration_trigger_definitions(PDO $pdo): array
{
    $events = ['INSERT' => 'ai', 'UPDATE' => 'au', 'DELETE' => 'ad'];
    $definitions = [];

    foreach (app_migration_audit_catalog() as $table => $definition) {
        if (!app_migration_table_exists($pdo, $table)) {
            continue;
        }

        $pk = (string)$definition['pk'];
        $columns = $definition['columns'];

        $jsonObject = static function (string $alias) use ($columns): string {
            $pairs = [];
            foreach ($columns as $column) {
                $pairs[] = "'" . $column . "'";
                $pairs[] = $alias . '.' . $column;
            }
            return 'JSON_OBJECT(' . implode(', ', $pairs) . ')';
        };

        foreach ($events as $event => $suffix) {
            $triggerName = 'audit_' . $table . '_' . $suffix;
            $pkExpression = ($event === 'DELETE') ? 'OLD.' . $pk : 'NEW.' . $pk;
            $oldData = ($event === 'INSERT') ? 'NULL' : $jsonObject('OLD');
            $newData = ($event === 'DELETE') ? 'NULL' : $jsonObject('NEW');

            $definitions[$triggerName] = 'CREATE TRIGGER `' . $triggerName . '` AFTER ' . $event . ' ON `' . $table . '` FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    ' . $pdo->quote($table) . ',
                    ' . $pdo->quote($event) . ',
                    CAST(' . $pkExpression . ' AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    ' . $oldData . ',
                    ' . $newData . '
                )';
        }
    }

    return $definitions;
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_ensure_triggers
 * @doc-purpose Legt fehlende Audit-Trigger an und erneuert sie nur bei expliziter Anforderung.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param bool $force True, wenn bestehende Trigger verworfen und neu erstellt werden sollen.
 * @doc-param array $log Protokollpuffer, wird per Referenz ergaenzt.
 * @doc-return void
 * @doc-note Das bedingte Vorgehen vermeidet ein DROP/CREATE bei jedem Aufruf und damit ein Zeitfenster,
 *           in dem Audit-Ereignisse nicht protokolliert werden koennen.
 */
function app_migration_ensure_triggers(PDO $pdo, bool $force, array &$log): void
{
    foreach (app_migration_trigger_definitions($pdo) as $triggerName => $sql) {
        $exists = app_migration_trigger_exists($pdo, $triggerName);
        if (!$force && $exists) {
            continue;
        }

        try {
            if ($exists) {
                $pdo->exec('DROP TRIGGER IF EXISTS `' . str_replace('`', '``', $triggerName) . '`');
            }
            $pdo->exec($sql);
            $log[] = '[ok] Trigger ' . $triggerName . ($exists ? ' erneuert' : ' angelegt');
        } catch (Throwable $e) {
            $log[] = '[skip] Trigger ' . $triggerName . ' (' . $e->getMessage() . ')';
            error_log('Schema-Migration: Trigger ' . $triggerName . ' fehlgeschlagen: ' . $e->getMessage());
        }
    }
}

// @doc-section migration-runner
// @doc-purpose Steuert die Reihenfolge aller Migrationsschritte und stellt den oeffentlichen Einstieg bereit.

/**
 * @doc-function live_app.scripts.migrate.app_migration_run
 * @doc-purpose Fuehrt die vollstaendige, idempotente Schema-Migration aus.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $options Optionen: force_triggers (bool) erzwingt das Neuerstellen aller Audit-Trigger.
 * @doc-return array{status:string,log:array<int,string>} Status und Protokoll des Migrationslaufs.
 * @doc-throws PDOException Wenn ein Pflichtschritt, etwa das Anlegen der Basistabellen, fehlschlaegt.
 */
function app_migration_run(PDO $pdo, array $options = []): array
{
    $forceTriggers = !empty($options['force_triggers']);
    $log = [];

    app_migration_ensure_tables($pdo, $log);
    app_migration_ensure_columns($pdo, $log);
    app_migration_seed_startnummern($pdo, $log);
    app_migration_backfill_teams($pdo, $log);
    app_migration_ensure_team_foreign_keys($pdo, $log);
    app_migration_drop_legacy_columns($pdo, $log);
    app_migration_enforce_unique_startnummer($pdo, $log);
    app_migration_repair_startnummer_consistency($pdo, $log);
    app_migration_ensure_triggers($pdo, $forceTriggers, $log);

    return ['status' => 'migrated', 'log' => $log];
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_maybe_run
 * @doc-purpose Fuehrt die Migration nur bei fehlender Marker-Datei aus und schuetzt sie per File-Lock.
 * @doc-param PDO $pdo Aktive Datenbankverbindung.
 * @doc-param array $options Optionen: force (bool) ignoriert die Marker-Datei, force_triggers (bool)
 *                     erneuert alle Audit-Trigger, source (string) kennzeichnet den Aufrufkontext.
 * @doc-return array{status:string,marker_written:bool,log:array<int,string>} Ergebnis des Aufrufs.
 * @doc-note Bei einem Timeout des File-Locks wird die Migration bewusst uebersprungen, da ein anderer
 *           Prozess sie bereits ausfuehrt und die Marker-Datei schreiben wird.
 */
function app_migration_maybe_run(PDO $pdo, array $options = []): array
{
    $force = !empty($options['force']);
    $forceTriggers = !empty($options['force_triggers']);
    $source = (string)($options['source'] ?? 'runtime');
    $marker = app_migration_marker_path();

    if (!$force && !$forceTriggers && is_file($marker)) {
        return ['status' => 'skipped', 'marker_written' => false, 'log' => ['Marker vorhanden, keine Migration notwendig.']];
    }

    $lock = app_migration_acquire_lock();
    if ($lock === null) {
        return ['status' => 'locked', 'marker_written' => false, 'log' => ['Migrations-Lock nicht erhalten; Schritt wird uebersprungen.']];
    }

    try {
        if (!$force && !$forceTriggers && is_file($marker)) {
            return ['status' => 'skipped', 'marker_written' => false, 'log' => ['Marker nach Lock-Pruefung vorhanden.']];
        }

        try {
            $result = app_migration_run($pdo, ['force_triggers' => $forceTriggers]);
        } catch (Throwable $e) {
            error_log('Schema-Migration fehlgeschlagen: ' . $e->getMessage());
            return ['status' => 'failed', 'marker_written' => false, 'log' => ['[FEHLER] ' . $e->getMessage()]];
        }

        $result['marker_written'] = app_migration_write_marker($source);
        $result['log'][] = $result['marker_written']
            ? '[ok] Marker-Datei geschrieben'
            : '[FEHLER] Marker-Datei konnte nicht geschrieben werden';
        return $result;
    } finally {
        app_migration_release_lock($lock);
    }
}

/**
 * @doc-function live_app.scripts.migrate.app_migration_cli_main
 * @doc-purpose Wertet Kommandozeilenparameter aus, fuehrt die Migration aus und gibt das Protokoll aus.
 * @doc-param array<int,string> $arguments Argumentliste ohne Skriptnamen.
 * @doc-return int Prozess-Rueckgabewert, 0 bei Erfolg.
 */
function app_migration_cli_main(array $arguments): int
{
    if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
        fwrite(STDOUT, "Verwendung: php live_app/scripts/migrate.php [--force-triggers] [--force]\n"
            . "  --force-triggers  Bestehende Audit-Trigger verwerfen und neu anlegen\n"
            . "  --force           Migration auch bei vorhandener Marker-Datei ausfuehren\n");
        return 0;
    }

    foreach ($arguments as $argument) {
        if ($argument !== '' && $argument[0] === '-' && !in_array($argument, ['--force-triggers', '--force'], true)) {
            fwrite(STDERR, 'Unbekannter Parameter: ' . $argument . "\n");
            return 2;
        }
    }

    define('APP_MIGRATION_AUTORUN_DISABLED', true);
    require_once dirname(__DIR__) . '/includes/db_connect.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        fwrite(STDERR, "Keine Datenbankverbindung verfuegbar.\n");
        return 1;
    }

    $result = app_migration_maybe_run($pdo, [
        'force' => in_array('--force', $arguments, true),
        'force_triggers' => in_array('--force-triggers', $arguments, true),
        'source' => 'cli',
    ]);

    fwrite(STDOUT, 'Schema-Migration: ' . $result['status'] . "\n");
    foreach ($result['log'] as $line) {
        fwrite(STDOUT, '  ' . $line . "\n");
    }

    return ($result['status'] === 'failed') ? 1 : 0;
}

// @doc-section cli-entry
// @doc-purpose Startet die Migration ausschliesslich bei direktem CLI-Aufruf dieser Datei.

/**
 * @doc-function live_app.scripts.migrate.app_migration_handle_direct_cli_call
 * @doc-purpose Erkennt einen direkten CLI-Aufruf dieser Datei und startet dann die Migration.
 * @doc-return void
 * @doc-note Wird diese Datei von db_connect.php eingebunden, unterscheidet sich SCRIPT_FILENAME vom
 *           Dateipfad und der Einstieg wird bewusst nicht ausgefuehrt.
 */
function app_migration_handle_direct_cli_call(): void
{
    if (PHP_SAPI !== 'cli') {
        return;
    }

    $argv = $GLOBALS['argv'] ?? [];
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === '' && is_array($argv) && isset($argv[0])) {
        $script = (string)$argv[0];
    }
    if ($script === '' || realpath($script) !== realpath(__FILE__)) {
        return;
    }

    exit(app_migration_cli_main(array_slice(is_array($argv) ? $argv : [], 1)));
}

app_migration_handle_direct_cli_call();
