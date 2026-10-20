<?php
/**
 * @doc-file
 * @doc-id live_app.seed_test_data
 * @doc-kind dev-tool
 * @doc-purpose Erzeugt umfangreiche, qualitativ hochwertige Testdaten fuer alle Funktionsbereiche des 24h-Schwimmen-Systems.
 * @doc-entry CLI- oder Browser-Aufruf: php seed_test_data.php
 * @doc-input Bestehende Datenbankverbindung aus config/.env.
 * @doc-output Testdatensaetze in teams, teilnehmer, startnummern, users, lane_timeframes, access_tokens.
 * @doc-side-effects Fuegt Testdaten in die Datenbank ein. Bestehende Daten werden NICHT geloescht (INSERT IGNORE).
 * @doc-note Nur fuer Entwicklungs- und Testumgebungen verwenden. Passwoerter werden per password_hash gehasht.
 * @doc-note Enthaelt eingebaute Qualitaetskontrollen (Dublettenpruefung, Feldvalidierung, Referenzintegritaet).
 */
declare(strict_types=1);

require __DIR__ . '/includes/db_connect.php';

// =====================================================================
// 0b. Fehlende Tabellen anlegen (die Auto-Migration erstellt nicht alle)
// =====================================================================
$pdo->exec("
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
        KEY idx_access_tokens_created_by (created_by)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS webauthn_credentials (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        credential_id VARCHAR(512) NOT NULL,
        public_key TEXT NOT NULL,
        name VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        UNIQUE KEY uniq_credential_id (credential_id),
        KEY idx_webauthn_user_id (user_id)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
");

// =====================================================================
// HILFSFUNKTIONEN
// =====================================================================

function seed_progress(string $msg): void
{
    echo '[seed] ' . $msg . PHP_EOL;
}

function seed_warn(string $msg): void
{
    echo '[WARN] ' . $msg . PHP_EOL;
}

function seed_ok(string $msg): void
{
    echo '[ OK ] ' . $msg . PHP_EOL;
}

// ------------------------------------------------------------------
// Code-Generierung (identisch zur Anwendungslogik: 9-stelliger Body + Pruefziffer)
// ------------------------------------------------------------------
function seed_code_alphabet(): string
{
    return '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
}

function seed_luhn_check(string $body, string $alphabet): string
{
    $n = strlen($alphabet);
    $factor = 2;
    $sum = 0;
    for ($i = strlen($body) - 1; $i >= 0; $i--) {
        $codePoint = strpos($alphabet, $body[$i]);
        if ($codePoint === false) {
            throw new RuntimeException('Ungueltiges Zeichen im Code.');
        }
        $addend = $factor * $codePoint;
        $factor = ($factor === 2) ? 1 : 2;
        $addend = intdiv($addend, $n) + ($addend % $n);
        $sum += $addend;
    }
    $remainder = $sum % $n;
    return $alphabet[($n - $remainder) % $n];
}

function seed_generate_code(int $bodyLen = 9): string
{
    $alphabet = seed_code_alphabet();
    $body = '';
    for ($i = 0; $i < $bodyLen; $i++) {
        $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $body . seed_luhn_check($body, $alphabet);
}

function seed_code_is_valid(string $code): bool
{
    $alphabet = seed_code_alphabet();
    if (strlen($code) < 2) {
        return false;
    }
    $body = substr($code, 0, -1);
    $check = substr($code, -1);
    try {
        return $check === seed_luhn_check($body, $alphabet);
    } catch (Throwable $e) {
        return false;
    }
}

// ------------------------------------------------------------------
// Datums-Hilfe
// ------------------------------------------------------------------
function seed_date_offset(int $daysBack, int $hour = 12, int $minute = 0): string
{
    return date('Y-m-d H:i:s', strtotime('-' . $daysBack . ' days') - (12 - $hour) * 3600 - $minute * 60);
}

// =====================================================================
// 0. VORBEREITUNG: Datenbank leeren (optional ueber Argument --truncate)
// =====================================================================
$truncate = in_array('--truncate', $argv ?? [], true) || in_array('--reset', $argv ?? [], true);
if ($truncate) {
    seed_progress('Leere vorhandene Tabellen (--truncate)...');
    $tables = [
        'access_tokens',
        'webauthn_credentials',
        'audit_log',
        'startnummern',
        'teilnehmer',
        'lane_timeframes',
        'teams',
        'users',
    ];
    // Foreign-Key-Checks temporaer deaktivieren
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) {
        try {
            $pdo->exec("TRUNCATE TABLE `$t`");
        } catch (Throwable $e) {
            seed_warn("Tabelle $t konnte nicht geleert werden: " . $e->getMessage());
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    seed_ok('Tabellen geleert.');
}

// =====================================================================
// 1. TEAMS
// =====================================================================
seed_progress('Erzeuge Teams...');

$teamNames = [
    'Delphin Crew',
    'Haie Sankt Augustin',
    'Wasserratten Siegburg',
    'Flipper Freunde',
    'Kraul-Koenige',
    'Brustschwimmer Sieg',
    'Schmetterlinge Bonn',
    'Rueckenschwimmer Rhein-Sieg',
    'Delfine Troisdorf',
    'Seepferdchen Hennef',
    'Kiemen Koenigswinter',
    'Wellenreiter Bad Honnef',
    'Aal Rauf Beuel',
    'Lachs Lohmar',
    'Barrakuda Niederkassel',
    'Goldfisch Eitorf',
    'Karpfen Wachtberg',
    'Hechte Alfter',
    'Plitsch Platsch Club',
    'Schwimmverein Sieglar',
];

$teamIds = [];
$insertTeam = $pdo->prepare('INSERT IGNORE INTO teams (name) VALUES (:name)');
foreach ($teamNames as $name) {
    $insertTeam->execute([':name' => $name]);
    $id = (int)$pdo->query("SELECT id FROM teams WHERE name = " . $pdo->quote($name))->fetchColumn();
    if ($id > 0) {
        $teamIds[$name] = $id;
    }
}
seed_ok('Teams erstellt: ' . count($teamIds));

// =====================================================================
// 2. STARTNUMMERN (Pool 1-999, Grundbestand)
// =====================================================================
seed_progress('Erzeuge Startnummern-Pool 1-999...');
$numInsert = $pdo->prepare('INSERT IGNORE INTO startnummern (nummer, status) VALUES (:n, :s)');
for ($n = 1; $n <= 999; $n++) {
    $numInsert->execute([':n' => $n, ':s' => 'frei']);
}
seed_ok('Startnummern 1-999 als "frei" angelegt.');

// ------------------------------------------------------------------
// 2a. Team-Reservierungen (Blockreservierungen fuer verschiedene Teams)
// ------------------------------------------------------------------
seed_progress('Erzeuge Team-Reservierungen...');
$reservierungen = [
    ['Haie Sankt Augustin', 100, 119],
    ['Wasserratten Siegburg', 120, 139],
    ['Flipper Freunde', 140, 159],
    ['Kraul-Koenige', 160, 179],
    ['Schmetterlinge Bonn', 180, 199],
    ['Delfine Troisdorf', 200, 219],
    ['Goldfisch Eitorf', 500, 509],
];
$updRes = $pdo->prepare('UPDATE startnummern SET status = :s, reserviert_fuer = :g, team_id = :tid WHERE nummer BETWEEN :start AND :end AND status = :frei');
foreach ($reservierungen as $r) {
    $tid = $teamIds[$r[0]] ?? null;
    if ($tid === null) {
        continue;
    }
    $updRes->execute([
        ':s' => 'reserviert',
        ':g' => $r[0],
        ':tid' => $tid,
        ':start' => $r[1],
        ':end' => $r[2],
        ':frei' => 'frei',
    ]);
}
seed_ok('Team-Reservierungen fuer ' . count($reservierungen) . ' Teams gesetzt.');

// Einzelne reservierte Nummern (nicht in einem Team-Block)
$einzelReserviert = [
    42 => 'Reserve Familie Meier',
    77 => 'Reserve Schule Siegburg',
    300 => 'Reserve VIP-Gast',
    333 => 'Reserve Presse',
];
$updEinzel = $pdo->prepare('UPDATE startnummern SET status = :s, reserviert_fuer = :g WHERE nummer = :n AND status = :frei');
foreach ($einzelReserviert as $nr => $grund) {
    $updEinzel->execute([':s' => 'reserviert', ':g' => $grund, ':n' => $nr, ':frei' => 'frei']);
}
seed_ok('Einzel-Reservierungen gesetzt: ' . count($einzelReserviert));

// =====================================================================
// 3. TEILNEHMER
// =====================================================================
seed_progress('Erzeuge Teilnehmer...');

$vornamen = [
    'Anna', 'Ben', 'Clara', 'David', 'Eva', 'Felix', 'Greta', 'Hannah',
    'Jan', 'Julia', 'Karl', 'Lena', 'Max', 'Nora', 'Ole', 'Pia',
    'Quirin', 'Rosa', 'Sam', 'Tara', 'Uwe', 'Vera', 'Willi', 'Xenia',
    'Yannik', 'Zoe', 'Alexander', 'Bianca', 'Christoph', 'Daniela',
    'Erik', 'Frieda', 'Georg', 'Helena', 'Iris', 'Jonas', 'Katharina',
    'Lukas', 'Marie', 'Niklas', 'Olivia', 'Paul', 'Ramona', 'Stefan',
    'Theresa', 'Ulrich', 'Valentina', 'Wolfgang', 'Annika', 'Bjoern',
    'Cordula', 'Detlef', 'Elena', 'Frank', 'Gisela', 'Heiko', 'Ines',
    'Jens', 'Kerstin', 'Lars', 'Manuela', 'Nico', 'Petra', 'Ralf',
    'Sandra', 'Thomas', 'Ute', 'Vanessa', 'Werner', 'Yvonne',
];

$nachnamen = [
    'Schmidt', 'Mueller', 'Schneider', 'Fischer', 'Weber', 'Meyer',
    'Wagner', 'Becker', 'Schulz', 'Hoffmann', 'Meier', 'Koch',
    'Richter', 'Klein', 'Wolf', 'Schroeder', 'Neumann', 'Schwarz',
    'Zimmermann', 'Braun', 'Krueger', 'Hofmann', 'Hartmann', 'Lange',
    'Schmitt', 'Werner', 'Krause', 'Meier', 'Lehmann', 'Huber',
    'Franks', 'Bergmann', 'Peter', 'Roth', 'Becker', 'Lang', 'Kraus',
    'Vogel', 'Bauer', 'Weiss', 'Jung', 'Hahn', 'Mayer', 'Kaiser',
    'Reichert', 'Fuchs', 'Peters', 'Lang', 'Scholz', 'Moeller',
];

$geschlechter = ['Maennlich', 'Weiblich', 'Divers'];
$teamsList = array_values($teamIds);

$teilnehmerInsert = $pdo->prepare(
    'INSERT INTO teilnehmer
     (code, vorname, nachname, volljaehrig, geschlecht, team_id, teamname,
      teilnahmebedingungen_akzeptiert, dsgvo_akzeptiert, bild_ton_akzeptiert,
      bezahlt, bezahlt_am, registriert_am, startnummer)
     VALUES
     (:code, :vor, :nach, :voll, :gesch, :tid, :tname, 1, 1, 1, :bezahlt, :bezahlt_am, :reg, :snr)'
);

$vergebeneNummern = [];          // bereits vergebene Startnummern
$verwendeteNamen = [];            // bereits verwendete Vorname+Nachname Kombinationen
$count = 0;
$teilnehmerMitNummer = 0;
$vergebeneNummernUpdate = $pdo->prepare(
    'UPDATE startnummern SET status = :s, teilnehmer_id = :tid, team_id = :sntid WHERE nummer = :n'
);

// Gesamtziel: 300 Teilnehmer
$zielAnzahl = 300;
$i = 0;
$versuche = 0;
$maxVersuche = $zielAnzahl * 5;

while ($count < $zielAnzahl && $versuche < $maxVersuche) {
    $versuche++;
    $vor = $vornamen[$i % count($vornamen)];
    $nach = $nachnamen[($i * 7 + 3) % count($nachnamen)];

    // Dublettenpruefung (Name)
    $nameKey = $vor . '|' . $nach;
    if (isset($verwendeteNamen[$nameKey])) {
        $i++;
        continue;
    }
    // Auch in der DB vorhandene Namen vermeiden
    $dupCheck = $pdo->prepare('SELECT 1 FROM teilnehmer WHERE vorname = :v AND nachname = :n LIMIT 1');
    $dupCheck->execute([':v' => $vor, ':n' => $nach]);
    if ((int)$dupCheck->fetchColumn() === 1) {
        $i++;
        continue;
    }
    $verwendeteNamen[$nameKey] = true;

    // Code generieren (eindeutig)
    $code = '';
    for ($t = 0; $t < 10; $t++) {
        $candidate = seed_generate_code();
        $codeCheck = $pdo->prepare('SELECT 1 FROM teilnehmer WHERE code = :c LIMIT 1');
        $codeCheck->execute([':c' => $candidate]);
        if ((int)$codeCheck->fetchColumn() === 0) {
            $code = $candidate;
            break;
        }
    }
    if ($code === '') {
        $i++;
        continue;
    }

    // Geschlecht: ~48% maennlich, ~48% weiblich, ~4% divers
    $r = $i % 25;
    if ($r === 0 || $r === 13) {
        $geschlecht = 'Divers';
    } elseif ($i % 2 === 0) {
        $geschlecht = 'Weiblich';
    } else {
        $geschlecht = 'Maennlich';
    }

    // Volljaehrig: ~65% volljaehrig, ~35% minderjaehrig
    $volljaehrig = ($i % 3 !== 0) ? 1 : 0;

    // Team-Zuordnung: ~70% mit Team
    $tid = null;
    $tname = null;
    if ($i % 10 < 7 && !empty($teamsList)) {
        $tid = $teamsList[$i % count($teamsList)];
        $tname = array_search($tid, $teamIds, true) ?: null;
    }

    // Bezahlstatus: ~80% bezahlt
    $bezahlt = ($i % 5 !== 0) ? 1 : 0;
    $bezahltAm = null;
    if ($bezahlt) {
        // Bezahlt vor 0 bis 45 Tagen
        $bezahltAm = date('Y-m-d H:i:s', strtotime('-' . ($i % 46) . ' days -' . ($i % 24) . ' hours'));
    }

    // Registrierungszeitraum: letzte 60 Tage, mit Schwerpunkt auf letzte 7 Tage
    if ($i % 5 === 0) {
        // sehr aktuelle Registrierung (innerhalb 24h)
        $reg = date('Y-m-d H:i:s', strtotime('-' . ($i % 20) . ' hours -' . ($i % 60) . ' minutes'));
    } elseif ($i % 5 === 1) {
        // letzte 7 Tage
        $reg = date('Y-m-d H:i:s', strtotime('-' . ($i % 7 + 1) . ' days -' . ($i % 24) . ' hours'));
    } else {
        // letzte 60 Tage
        $reg = date('Y-m-d H:i:s', strtotime('-' . ($i % 60 + 1) . ' days -' . ($i % 24) . ' hours'));
    }

    // Startnummer: ~55% der Teilnehmer bekommen eine Nummer
    $snr = null;
    if ($i % 20 < 11) {
        // Versuche eine freie Nummer zu finden
        $candidate = 0;
        for ($k = 0; $k < 200; $k++) {
            $candidate = 1 + (($i * 13 + $k * 7) % 999);
            if (!in_array($candidate, $vergebeneNummern, true) && $candidate >= 1 && $candidate <= 999) {
                // Status in DB pruefen
                $stCheck = $pdo->prepare('SELECT status FROM startnummern WHERE nummer = :n');
                $stCheck->execute([':n' => $candidate]);
                $status = $stCheck->fetchColumn();
                if ($status === 'frei' || $status === 'reserviert') {
                    break;
                }
            }
            $candidate = 0;
        }
        if ($candidate > 0) {
            $snr = $candidate;
            $vergebeneNummern[] = $candidate;
            $teilnehmerMitNummer++;
        }
    }

    try {
        $teilnehmerInsert->execute([
            ':code' => $code,
            ':vor' => $vor,
            ':nach' => $nach,
            ':voll' => $volljaehrig,
            ':gesch' => $geschlecht,
            ':tid' => $tid,
            ':tname' => $tname,
            ':bezahlt' => $bezahlt,
            ':bezahlt_am' => $bezahltAm,
            ':reg' => $reg,
            ':snr' => $snr,
        ]);

        // startnummern.teilnehmer_id nachtraeglich setzen
        if ($snr !== null) {
            $tidDb = (int)$pdo->query("SELECT id FROM teilnehmer WHERE code = " . $pdo->quote($code))->fetchColumn();
            if ($tidDb > 0) {
                $vergebeneNummernUpdate->execute([
                    ':s' => 'vergeben',
                    ':tid' => $tidDb,
                    ':sntid' => $tid,
                    ':n' => $snr,
                ]);
            }
        }
        $count++;
    } catch (PDOException $e) {
        seed_warn("Teilnehmer konnte nicht angelegt werden ({$vor} {$nach}): " . $e->getMessage());
    }
    $i++;
}
seed_ok("Teilnehmer erstellt: {$count} (davon {$teilnehmerMitNummer} mit Startnummer)");

// =====================================================================
// 4. BENUTZER (alle Rollen abgedeckt)
// =====================================================================
seed_progress('Erzeuge Benutzer...');

$users = [
    ['admin_root',  'Admin@2024!Passwort',  'admin'],
    ['kasse_max',   'Kasse@2024!Passwort',  'cashier'],
    ['orga_lisa',   'Orga@2024!Passwort',   'orga'],
    ['orga_ben',    'Orga2@2024!Passwort',  'orga'],
    ['viewer_tim',  'Viewer@2024!Passwort', 'viewer'],
    ['admin_claudia','Admin2@2024!Passwort','admin'],
    ['kasse_pia',   'Kasse2@2024!Passwort', 'cashier'],
    ['viewer_nina', 'Viewer2@2024!Passwort','viewer'],
];
$userStmt = $pdo->prepare('INSERT IGNORE INTO users (username, password_hash, role) VALUES (:u, :h, :r)');
foreach ($users as $u) {
    $hash = password_hash($u[1], PASSWORD_DEFAULT);
    $userStmt->execute([':u' => $u[0], ':h' => $hash, ':r' => $u[2]]);
}
seed_ok('Benutzer erstellt: ' . count($users) . ' (Rollen: admin, cashier, orga, viewer)');

// =====================================================================
// 5. BAHN-ZEITFENSTER (lane_timeframes)
// =====================================================================
seed_progress('Erzeuge Bahn-Zeitfenster...');

$eventDate = date('Y-m-d', strtotime('+7 days'));
$nextDate = date('Y-m-d', strtotime('+8 days'));
$pastDate = date('Y-m-d', strtotime('-7 days'));

$timeframes = [
    // Vergangene Zeitfenster (fuer Historie)
    ['Vergangen: Trainingsschicht', $pastDate . ' 06:00:00', $pastDate . ' 10:00:00', 1, 1],
    ['Vergangen: Abendschicht',    $pastDate . ' 18:00:00', $pastDate . ' 22:00:00', 0, 2],
    // Aktuelle Event-Zeitfenster (in 7 Tagen)
    ['Fruehschicht 06-10 Uhr',     $eventDate . ' 06:00:00', $eventDate . ' 10:00:00', 1, 3],
    ['Vormittag 10-14 Uhr',        $eventDate . ' 10:00:00', $eventDate . ' 14:00:00', 1, 4],
    ['Nachmittag 14-18 Uhr',       $eventDate . ' 14:00:00', $eventDate . ' 18:00:00', 1, 5],
    ['Abend 18-22 Uhr',            $eventDate . ' 18:00:00', $eventDate . ' 22:00:00', 1, 6],
    ['Nacht 22-02 Uhr',            $eventDate . ' 22:00:00', $nextDate  . ' 02:00:00', 1, 7],
    // Inaktives Backup-Zeitfenster
    ['Inaktiv (Backup)',           date('Y-m-d', strtotime('+1 day')) . ' 00:00:00', date('Y-m-d', strtotime('+1 day')) . ' 01:00:00', 0, 8],
];
$tfStmt = $pdo->prepare('INSERT INTO lane_timeframes (name, start_at, end_at, active, sort_order) VALUES (:n, :s, :e, :a, :o)');
foreach ($timeframes as $tf) {
    $tfStmt->execute([':n' => $tf[0], ':s' => $tf[1], ':e' => $tf[2], ':a' => $tf[3], ':o' => $tf[4]]);
}
seed_ok('Zeitfenster erstellt: ' . count($timeframes) . ' (6 aktiv, 2 inaktiv)');

// =====================================================================
// 6. ACCESS TOKENS (verschiedene Zustaende)
// =====================================================================
seed_progress('Erzeuge Access-Tokens...');

$adminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin_root'")->fetchColumn();
$tokenStmt = $pdo->prepare(
    'INSERT IGNORE INTO access_tokens (token_hash, description, created_by, expires_at, revoked)
     VALUES (:h, :d, :cb, :e, :r)'
);

// 1. Gueltiger, nicht abgelaufener Token (nur Hash, Klartext nicht gespeichert)
$tokenStmt->execute([
    ':h' => hash('sha256', 'demo-token-kiosk-valid-' . time()),
    ':d' => 'Kiosk-Lesezugriff (Demo - gueltig)',
    ':cb' => $adminId,
    ':e' => null,
    ':r' => 0,
]);

// 2. Abgelaufener Token
$tokenStmt->execute([
    ':h' => hash('sha256', 'demo-token-expired-' . time()),
    ':d' => 'Abgelaufener Test-Token',
    ':cb' => $adminId,
    ':e' => date('Y-m-d H:i:s', strtotime('-1 day')),
    ':r' => 0,
]);

// 3. Widerrufener Token
$tokenStmt->execute([
    ':h' => hash('sha256', 'demo-token-revoked-' . time()),
    ':d' => 'Widerrufener Test-Token',
    ':cb' => $adminId,
    ':e' => date('Y-m-d H:i:s', strtotime('+30 days')),
    ':r' => 1,
]);

// 4. Zukuenftig ablaufender Token (in 30 Tagen)
$tokenStmt->execute([
    ':h' => hash('sha256', 'demo-token-soon-expiry-' . time()),
    ':d' => 'Token mit Ablauf in 30 Tagen',
    ':cb' => $adminId,
    ':e' => date('Y-m-d H:i:s', strtotime('+30 days')),
    ':r' => 0,
]);

seed_ok('Access-Tokens erstellt: 4 (gueltig, abgelaufen, widerrufen, kurz vor Ablauf)');

// =====================================================================
// 7. KONSISTENZ-REPARATUR (team_id <-> teamname synchron halten)
// =====================================================================
seed_progress('Fuehre Konsistenz-Reparatur durch...');

// teamname aus team_id ableiten
$pdo->exec("
    UPDATE teilnehmer t
    INNER JOIN teams te ON te.id = t.team_id
    SET t.teamname = te.name
    WHERE t.team_id IS NOT NULL AND t.team_id <> 0
      AND (t.teamname IS NULL OR t.teamname = '' OR t.teamname <> te.name)
");

// team_id aus teamname ableiten (falls team_id NULL aber teamname gesetzt)
$pdo->exec("
    UPDATE teilnehmer t
    INNER JOIN teams te ON te.name = TRIM(t.teamname)
    SET t.team_id = te.id
    WHERE (t.team_id IS NULL OR t.team_id = 0)
      AND t.teamname IS NOT NULL AND TRIM(t.teamname) <> ''
");

// startnummern.team_id aus teilnehmer.team_id uebernehmen
$pdo->exec("
    UPDATE startnummern s
    INNER JOIN teilnehmer t ON t.id = s.teilnehmer_id
    SET s.team_id = t.team_id
    WHERE s.teilnehmer_id IS NOT NULL AND t.team_id IS NOT NULL
");

seed_ok('Konsistenz-Reparatur abgeschlossen.');

// =====================================================================
// 8. QUALITAETSKONTROLLE
// =====================================================================
seed_progress('Fuehre Qualitaetskontrolle durch...');

$errors = 0;
$warnings = 0;
$checks = [];

function qc_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// --- 8.1 Teilnehmervollstaendigkeit ---
$checks['teilnehmer.gesamt'] = qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer');
$checks['teilnehmer.ohne_vorname'] = qc_count($pdo, "SELECT COUNT(*) FROM teilnehmer WHERE vorname IS NULL OR TRIM(vorname) = ''");
$checks['teilnehmer.ohne_nachname'] = qc_count($pdo, "SELECT COUNT(*) FROM teilnehmer WHERE nachname IS NULL OR TRIM(nachname) = ''");
$checks['teilnehmer.ohne_code'] = qc_count($pdo, "SELECT COUNT(*) FROM teilnehmer WHERE code IS NULL OR TRIM(code) = ''");
$checks['teilnehmer.ungueltige_agb'] = qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer WHERE teilnahmebedingungen_akzeptiert = 0 OR dsgvo_akzeptiert = 0 OR bild_ton_akzeptiert = 0');

// --- 8.2 Geschlechter-Werte ---
$checks['teilnehmer.geschlecht_maennlich'] = qc_count($pdo, "SELECT COUNT(*) FROM teilnehmer WHERE geschlecht = 'Maennlich'");
$checks['teilnehmer.geschlecht_weiblich'] = qc_count($pdo, "SELECT COUNT(*) FROM teilnehmer WHERE geschlecht = 'Weiblich'");
$checks['teilnehmer.geschlecht_divers'] = qc_count($pdo, "SELECT COUNT(*) FROM teilnehmer WHERE geschlecht = 'Divers'");
$checks['teilnehmer.geschlecht_ungueltig'] = qc_count($pdo, "SELECT COUNT(*) FROM teilnehmer WHERE geschlecht NOT IN ('Maennlich','Weiblich','Divers')");

// --- 8.3 Dublettenpruefung ---
$checks['teilnehmer.dubletten_code'] = qc_count($pdo, 'SELECT COUNT(*) FROM (SELECT code FROM teilnehmer GROUP BY code HAVING COUNT(*) > 1) d');
$checks['teilnehmer.dubletten_name'] = qc_count($pdo, 'SELECT COUNT(*) FROM (SELECT vorname, nachname FROM teilnehmer GROUP BY vorname, nachname HAVING COUNT(*) > 1) d');

// --- 8.4 Code-Validitaet (Luhn-Pruefziffer) ---
$checks['teilnehmer.code_ungueltig'] = 0;
$stmt = $pdo->query('SELECT code FROM teilnehmer');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!seed_code_is_valid((string)$row['code'])) {
        $checks['teilnehmer.code_ungueltig']++;
    }
}

// --- 8.5 Team-Konsistenz ---
$checks['teams.gesamt'] = qc_count($pdo, 'SELECT COUNT(*) FROM teams');
$checks['teilnehmer.mit_team'] = qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer WHERE team_id IS NOT NULL');
$checks['teilnehmer.teamname_mismatch'] = qc_count($pdo,
    "SELECT COUNT(*) FROM teilnehmer t LEFT JOIN teams te ON te.id = t.team_id
     WHERE t.team_id IS NOT NULL AND (t.teamname IS NULL OR t.teamname <> te.name)");
$checks['teilnehmer.teamid_ohne_teamname'] = qc_count($pdo,
    "SELECT COUNT(*) FROM teilnehmer WHERE team_id IS NOT NULL AND (teamname IS NULL OR teamname = '')");

// --- 8.6 Bezahl-Konsistenz ---
$checks['teilnehmer.bezahlt'] = qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer WHERE bezahlt = 1');
$checks['teilnehmer.unbezahlt'] = qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer WHERE bezahlt = 0');
$checks['teilnehmer.bezahlt_ohne_datum'] = qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer WHERE bezahlt = 1 AND bezahlt_am IS NULL');
$checks['teilnehmer.unbezahlt_mit_datum'] = qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer WHERE bezahlt = 0 AND bezahlt_am IS NOT NULL');

// --- 8.7 Startnummern-Konsistenz ---
$checks['startnummern.frei'] = qc_count($pdo, "SELECT COUNT(*) FROM startnummern WHERE status = 'frei'");
$checks['startnummern.reserviert'] = qc_count($pdo, "SELECT COUNT(*) FROM startnummern WHERE status = 'reserviert'");
$checks['startnummern.vergeben'] = qc_count($pdo, "SELECT COUNT(*) FROM startnummern WHERE status = 'vergeben'");
$checks['startnummern.vergeben_ohne_teilnehmer'] = qc_count($pdo,
    "SELECT COUNT(*) FROM startnummern WHERE status = 'vergeben' AND teilnehmer_id IS NULL");
$checks['startnummern.teilnehmer_mismatch'] = qc_count($pdo,
    "SELECT COUNT(*) FROM startnummern s
     INNER JOIN teilnehmer t ON t.id = s.teilnehmer_id
     WHERE s.status = 'vergeben' AND t.startnummer <> s.nummer");
$checks['teilnehmer.mit_nummer_ohne_eintrag'] = qc_count($pdo,
    "SELECT COUNT(*) FROM teilnehmer t
     WHERE t.startnummer IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM startnummern s WHERE s.nummer = t.startnummer AND s.teilnehmer_id = t.id)");

// --- 8.8 Sync-faehige Teilnehmer (bezahlt + startnummer) ---
$checks['teilnehmer.sync_faehig'] = qc_count($pdo,
    'SELECT COUNT(*) FROM teilnehmer WHERE bezahlt = 1 AND startnummer IS NOT NULL AND startnummer > 0');

// --- 8.9 Benutzer & Rollen ---
$checks['users.gesamt'] = qc_count($pdo, 'SELECT COUNT(*) FROM users');
$checks['users.admin'] = qc_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin'");
$checks['users.cashier'] = qc_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'cashier'");
$checks['users.orga'] = qc_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'orga'");
$checks['users.viewer'] = qc_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'viewer'");

// --- 8.10 Zeitfenster ---
$checks['timeframes.gesamt'] = qc_count($pdo, 'SELECT COUNT(*) FROM lane_timeframes');
$checks['timeframes.aktiv'] = qc_count($pdo, 'SELECT COUNT(*) FROM lane_timeframes WHERE active = 1');
$checks['timeframes.inaktiv'] = qc_count($pdo, 'SELECT COUNT(*) FROM lane_timeframes WHERE active = 0');

// --- 8.11 Access Tokens ---
$checks['tokens.gesamt'] = qc_count($pdo, 'SELECT COUNT(*) FROM access_tokens');
$checks['tokens.gueltig'] = qc_count($pdo, 'SELECT COUNT(*) FROM access_tokens WHERE revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())');
$checks['tokens.abgelaufen'] = qc_count($pdo, 'SELECT COUNT(*) FROM access_tokens WHERE revoked = 0 AND expires_at IS NOT NULL AND expires_at <= NOW()');
$checks['tokens.widerrufen'] = qc_count($pdo, 'SELECT COUNT(*) FROM access_tokens WHERE revoked = 1');

// --- Kritische Fehler auswerten ---
$kritisch = [
    'teilnehmer.ohne_vorname',
    'teilnehmer.ohne_nachname',
    'teilnehmer.ohne_code',
    'teilnehmer.geschlecht_ungueltig',
    'teilnehmer.dubletten_code',
    'teilnehmer.dubletten_name',
    'teilnehmer.code_ungueltig',
    'teilnehmer.teamname_mismatch',
    'teilnehmer.bezahlt_ohne_datum',
    'teilnehmer.unbezahlt_mit_datum',
    'startnummern.vergeben_ohne_teilnehmer',
    'startnummern.teilnehmer_mismatch',
    'teilnehmer.mit_nummer_ohne_eintrag',
];
foreach ($kritisch as $k) {
    if ($checks[$k] > 0) {
        $errors++;
        seed_warn("KRITISCH: {$k} = {$checks[$k]}");
    }
}

echo PHP_EOL;
seed_ok('Qualitaetskontrolle abgeschlossen.');
echo PHP_EOL;

// =====================================================================
// 9. AUSGABE: QUALITAETSBERICHT
// =====================================================================
echo str_repeat('=', 70) . PHP_EOL;
echo '           QUALITAETSBERICHT - TESTDATEN' . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
echo PHP_EOL;

echo '--- TEILNEHMER (' . $checks['teilnehmer.gesamt'] . ' gesamt) ---' . PHP_EOL;
echo '  Geschlecht:  Maennlich=' . $checks['teilnehmer.geschlecht_maennlich']
    . '  Weiblich=' . $checks['teilnehmer.geschlecht_weiblich']
    . '  Divers=' . $checks['teilnehmer.geschlecht_divers'] . PHP_EOL;
echo '  Volljaehrig: ~' . round(100 * qc_count($pdo, 'SELECT COUNT(*) FROM teilnehmer WHERE volljaehrig = 1') / max(1, $checks['teilnehmer.gesamt'])) . '%' . PHP_EOL;
echo '  Mit Team:    ' . $checks['teilnehmer.mit_team'] . ' / ' . $checks['teilnehmer.gesamt'] . PHP_EOL;
echo '  Bezahlt:     ' . $checks['teilnehmer.bezahlt'] . ' (Unbezahlt: ' . $checks['teilnehmer.unbezahlt'] . ')' . PHP_EOL;
echo '  Sync-faehig: ' . $checks['teilnehmer.sync_faehig'] . ' (bezahlt + Startnummer)' . PHP_EOL;
echo PHP_EOL;

echo '--- STARTNUMMERN ---' . PHP_EOL;
echo '  Frei:        ' . $checks['startnummern.frei'] . PHP_EOL;
echo '  Reserviert:  ' . $checks['startnummern.reserviert'] . PHP_EOL;
echo '  Vergeben:    ' . $checks['startnummern.vergeben'] . PHP_EOL;
echo PHP_EOL;

echo '--- TEAMS (' . $checks['teams.gesamt'] . ') | BENUTZER (' . $checks['users.gesamt'] . ') | ZEITFENSTER (' . $checks['timeframes.gesamt'] . ') ---' . PHP_EOL;
echo '  Rollen:      admin=' . $checks['users.admin'] . '  cashier=' . $checks['users.cashier']
    . '  orga=' . $checks['users.orga'] . '  viewer=' . $checks['users.viewer'] . PHP_EOL;
echo '  Zeitfenster: aktiv=' . $checks['timeframes.aktiv'] . '  inaktiv=' . $checks['timeframes.inaktiv'] . PHP_EOL;
echo '  Tokens:      gueltig=' . $checks['tokens.gueltig'] . '  abgelaufen=' . $checks['tokens.abgelaufen']
    . '  widerrufen=' . $checks['tokens.widerrufen'] . PHP_EOL;
echo PHP_EOL;

echo '--- PRUEFERGEBNISSE ---' . PHP_EOL;
foreach ($checks as $key => $val) {
    $krit = in_array($key, $kritisch, true);
    $status = ($val > 0 && $krit) ? 'FEHLER' : (($val > 0) ? 'info  ' : 'ok    ');
    if ($krit || $val > 0) {
        echo sprintf("  [%s] %-45s = %d%s", $status, $key, $val, PHP_EOL);
    }
}
echo PHP_EOL;

echo str_repeat('=', 70) . PHP_EOL;
if ($errors === 0) {
    echo '  ERGEBNIS: Alle kritischen Qualitaetschecks erfolgreich.' . PHP_EOL;
} else {
    echo "  ERGEBNIS: {$errors} kritische(r) Fehler gefunden!" . PHP_EOL;
}
echo str_repeat('=', 70) . PHP_EOL;

// Test-Benutzer ausgeben
echo PHP_EOL . 'Test-Benutzer:' . PHP_EOL;
foreach ($users as $u) {
    echo '  ' . $u[0] . ' / ' . $u[1] . ' (Rolle: ' . $u[2] . ')' . PHP_EOL;
}
echo PHP_EOL;
echo 'Hinweis: Die Demo-Access-Tokens sind nicht nutzbar (nur Dummy-Hashes).' . PHP_EOL;
echo 'Echte Tokens muessen ueber die Admin-Oberflaeche erzeugt werden.' . PHP_EOL;
echo PHP_EOL;
echo 'Mit --truncate koennen vorhandene Daten vor dem Seed geleert werden:' . PHP_EOL;
echo '  php seed_test_data.php --truncate' . PHP_EOL;
