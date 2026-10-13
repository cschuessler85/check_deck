<?php
/**
 * @doc-file
 * @doc-id live_app.seed_test_data
 * @doc-kind dev-tool
 * @doc-purpose Erzeugt repräsentative Testdaten für alle Funktionsbereiche des 24h-Schwimmen-Systems.
 * @doc-entry CLI- oder Browser-Aufruf: php seed_test_data.php
 * @doc-input Bestehende Datenbankverbindung aus config/.env.
 * @doc-output Testdatensätze in teams, teilnehmer, startnummern, users, lane_timeframes, access_tokens.
 * @doc-side-effects Fügt Testdaten in die Datenbank ein. Bestehende Daten werden NICHT gelöscht.
 * @doc-note Nur für Entwicklungs- und Testumgebungen verwenden. Passwörter werden per password_hash gehasht.
 */
declare(strict_types=1);

require __DIR__ . '/includes/db_connect.php';

function seed_progress(string $msg): void
{
    echo '[seed] ' . $msg . PHP_EOL;
}

$pdo = db_connect();

// ---------------------------------------------------------------------------
// 1. Teams
// ---------------------------------------------------------------------------
seed_progress('Erzeuge Teams...');
$teamNames = [
    'Delphin Crew',
    'Haie Sankt Augustin',
    'Wasserratten',
    'Flipper Freunde',
    'Kraul-Könige',
    'Brustschwimmer Sieg',
    'Schmetterlinge',
    'Rückenschwimmer Rhein-Sieg',
];
$teamIds = [];
foreach ($teamNames as $name) {
    $stmt = $pdo->prepare('INSERT IGNORE INTO teams (name) VALUES (:name)');
    $stmt->execute([':name' => $name]);
    $id = (int)$pdo->query("SELECT id FROM teams WHERE name = " . $pdo->quote($name))->fetchColumn();
    if ($id > 0) {
        $teamIds[$name] = $id;
    }
}
seed_progress('Teams erstellt: ' . count($teamIds));

// ---------------------------------------------------------------------------
// 2. Startnummern (Pool 100–199)
// ---------------------------------------------------------------------------
seed_progress('Erzeuge Startnummern 100–199...');
$numStmt = $pdo->prepare('INSERT IGNORE INTO startnummern (nummer, status) VALUES (:n, :s)');
for ($n = 100; $n <= 199; $n++) {
    $numStmt->execute([':n' => $n, ':s' => 'frei']);
}
// Einige reservieren und vergeben (werden später bei Teilnehmern verknüpft)
$reserviert = [101 => 'Reserve Familie Meier', 102 => 'Reserve Schule Siegburg'];
foreach ($reserviert as $nr => $grund) {
    $pdo->prepare('UPDATE startnummern SET status = :s, reserviert_fuer = :g WHERE nummer = :n')
        ->execute([':s' => 'reserviert', ':g' => $grund, ':n' => $nr]);
}
seed_progress('Startnummern 100–199 angelegt (davon 2 reserviert)');

// ---------------------------------------------------------------------------
// 3. Teilnehmer
// ---------------------------------------------------------------------------
seed_progress('Erzeuge Teilnehmer...');

$vornamen = ['Anna', 'Ben', 'Clara', 'David', 'Eva', 'Felix', 'Greta', 'Hannah', 'Jan', 'Julia', 'Karl', 'Lena', 'Max', 'Nora', 'Ole', 'Pia', 'Quirin', 'Rosa', 'Sam', 'Tara', 'Uwe', 'Vera', 'Willi', 'Xenia', 'Yannik', 'Zoe'];
$nachnamen = ['Schmidt', 'Müller', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker', 'Schulz', 'Hoffmann', 'Meier', 'Koch', 'Richter', 'Klein', 'Wolf', 'Schröder', 'Neumann', 'Schwarz', 'Zimmermann', 'Braun'];
$teamsList = array_values($teamIds);

$teilnehmerInsert = $pdo->prepare('INSERT INTO teilnehmer (code, vorname, nachname, volljaehrig, geschlecht, team_id, teamname, teilnahmebedingungen_akzeptiert, dsgvo_akzeptiert, bild_ton_akzeptiert, bezahlt, bezahlt_am, registriert_am, startnummer) VALUES (:code, :vor, :nach, :voll, :gesch, :tid, :tname, 1, 1, 1, :bezahlt, :bezahlt_am, :reg, :snr)');

$vergebeneNummern = [];
$count = 0;
for ($i = 0; $i < 60; $i++) {
    $vor = $vornamen[$i % count($vornamen)];
    $nach = $nachnamen[($i * 3) % count($nachnamen)];
    $code = strtoupper(substr(md5($vor . $nach . $i), 0, 8));
    $voll = ($i % 5 === 0) ? 0 : 1; // jeder 5. ist minderjährig
    $geschlecht = ($i % 2 === 0) ? 'weiblich' : 'männlich';

    // Team-Zuordnung: ~70% mit Team
    $tid = null;
    $tname = null;
    if ($i % 10 < 7 && !empty($teamsList)) {
        $tid = $teamsList[$i % count($teamsList)];
        $tname = array_search($tid, $teamIds, true);
    }

    // Bezahlstatus: ~75% bezahlt
    $bezahlt = ($i % 4 !== 3) ? 1 : 0;
    $bezahltAm = $bezahlt ? date('Y-m-d H:i:s', strtotime('-' . ($i % 20) . ' days')) : null;

    // Registrierungszeitraum: letzte 30 Tage
    $reg = date('Y-m-d H:i:s', strtotime('-' . ($i % 30) . ' days -' . ($i % 24) . ' hours'));

    // Startnummer: nur für einen Teil der Teilnehmer
    $snr = null;
    if ($i % 3 === 0) {
        $candidate = 100 + $i;
        if (!in_array($candidate, $vergebeneNummern, true) && $candidate <= 199 && !isset($reserviert[$candidate])) {
            $snr = $candidate;
            $vergebeneNummern[] = $candidate;
            $pdo->prepare('UPDATE startnummern SET status = :s, teilnehmer_id = (SELECT id FROM teilnehmer WHERE code = :c), team_id = :tid WHERE nummer = :n')
                ->execute([':s' => 'vergeben', ':c' => $code, ':tid' => $tid, ':n' => $candidate]);
        }
    }

    $teilnehmerInsert->execute([
        ':code' => $code,
        ':vor' => $vor,
        ':nach' => $nach,
        ':voll' => $voll,
        ':gesch' => $geschlecht,
        ':tid' => $tid,
        ':tname' => $tname,
        ':bezahlt' => $bezahlt,
        ':bezahlt_am' => $bezahltAm,
        ':reg' => $reg,
        ':snr' => $snr,
    ]);

    // startnummern.teilnehmer_id nachträglich korrigieren
    if ($snr !== null) {
        $tidDb = (int)$pdo->query("SELECT id FROM teilnehmer WHERE code = " . $pdo->quote($code))->fetchColumn();
        $pdo->prepare('UPDATE startnummern SET teilnehmer_id = :tid WHERE nummer = :n')
            ->execute([':tid' => $tidDb, ':n' => $snr]);
    }
    $count++;
}
seed_progress('Teilnehmer erstellt: ' . $count);

// ---------------------------------------------------------------------------
// 4. Benutzer (verschiedene Rollen)
// ---------------------------------------------------------------------------
seed_progress('Erzeuge Benutzer...');
$users = [
    ['admin_root', 'Admin@2024!Passwort', 'admin'],
    ['kasse_max', 'Kasse@2024!Passwort', 'cashier'],
    ['orga_lisa', 'Orga@2024!Passwort', 'orga'],
    ['viewer_tim', 'Viewer@2024!Passwort', 'viewer'],
    ['orga_ben', 'Orga2@2024!Passwort', 'orga'],
];
$userStmt = $pdo->prepare('INSERT IGNORE INTO users (username, password_hash, role) VALUES (:u, :h, :r)');
foreach ($users as $u) {
    $hash = password_hash($u[1], PASSWORD_DEFAULT);
    $userStmt->execute([':u' => $u[0], ':h' => $hash, ':r' => $u[2]]);
}
seed_progress('Benutzer erstellt: ' . count($users) . ' (Passwörter siehe Kommentare)');

// ---------------------------------------------------------------------------
// 5. Bahn-Zeitfenster (lane_timeframes)
// ---------------------------------------------------------------------------
seed_progress('Erzeuge Bahn-Zeitfenster...');
$eventDate = date('Y-m-d', strtotime('+7 days'));
$timeframes = [
    ['Frühschicht 06–10 Uhr', $eventDate . ' 06:00:00', $eventDate . ' 10:00:00', 1, 1],
    ['Vormittag 10–14 Uhr', $eventDate . ' 10:00:00', $eventDate . ' 14:00:00', 1, 2],
    ['Nachmittag 14–18 Uhr', $eventDate . ' 14:00:00', $eventDate . ' 18:00:00', 1, 3],
    ['Abend 18–22 Uhr', $eventDate . ' 18:00:00', $eventDate . ' 22:00:00', 1, 4],
    ['Nacht 22–02 Uhr', $eventDate . ' 22:00:00', date('Y-m-d', strtotime('+8 days')) . ' 02:00:00', 1, 5],
    ['Inaktiv (Backup)', date('Y-m-d', strtotime('+1 day')) . ' 00:00:00', date('Y-m-d', strtotime('+1 day')) . ' 01:00:00', 0, 6],
];
$tfStmt = $pdo->prepare('INSERT INTO lane_timeframes (name, start_at, end_at, active, sort_order) VALUES (:n, :s, :e, :a, :o)');
foreach ($timeframes as $tf) {
    $tfStmt->execute([':n' => $tf[0], ':s' => $tf[1], ':e' => $tf[2], ':a' => $tf[3], ':o' => $tf[4]]);
}
seed_progress('Zeitfenster erstellt: ' . count($timeframes));

// ---------------------------------------------------------------------------
// 6. Access Tokens (Beispiel-Hashes, nicht nutzbar – nur zur Anzeige)
// ---------------------------------------------------------------------------
seed_progress('Erzeuge Beispiel-Access-Tokens...');
$adminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin_root'")->fetchColumn();
$tokenStmt = $pdo->prepare('INSERT IGNORE INTO access_tokens (token_hash, description, created_by, expires_at, revoked) VALUES (:h, :d, :cb, :e, :r)');
// Dummy-Hashes (SHA-256 von bekannten Strings – nur für die Anzeige)
$tokenStmt->execute([
    ':h' => hash('sha256', 'demo-token-kiosk-1'),
    ':d' => 'Kiosk-Lesezugriff (Demo)',
    ':cb' => $adminId,
    ':e' => null,
    ':r' => 0,
]);
$tokenStmt->execute([
    ':h' => hash('sha256', 'demo-token-expired'),
    ':d' => 'Abgelaufener Test-Token',
    ':cb' => $adminId,
    ':e' => date('Y-m-d H:i:s', strtotime('-1 day')),
    ':r' => 0,
]);
seed_progress('Access Tokens erstellt: 2 (Demo-Hashes)');

// ---------------------------------------------------------------------------
// Zusammenfassung
// ---------------------------------------------------------------------------
$cntTeams = (int)$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
$cntTeil = (int)$pdo->query('SELECT COUNT(*) FROM teilnehmer')->fetchColumn();
$cntNummern = (int)$pdo->query('SELECT COUNT(*) FROM startnummern')->fetchColumn();
$cntUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$cntTf = (int)$pdo->query('SELECT COUNT(*) FROM lane_timeframes')->fetchColumn();
$cntTokens = (int)$pdo->query('SELECT COUNT(*) FROM access_tokens')->fetchColumn();

echo PHP_EOL . '=== Testdaten-Übersicht ===' . PHP_EOL;
echo 'Teams:          ' . $cntTeams . PHP_EOL;
echo 'Teilnehmer:     ' . $cntTeil . PHP_EOL;
echo 'Startnummern:   ' . $cntNummern . PHP_EOL;
echo 'Benutzer:       ' . $cntUsers . PHP_EOL;
echo 'Zeitfenster:    ' . $cntTf . PHP_EOL;
echo 'Access Tokens:  ' . $cntTokens . PHP_EOL;
echo PHP_EOL . 'Test-Benutzer:' . PHP_EOL;
foreach ($users as $u) {
    echo '  ' . $u[0] . ' / ' . $u[1] . ' (Rolle: ' . $u[2] . ')' . PHP_EOL;
}
echo PHP_EOL . 'Hinweis: Die Demo-Access-Tokens sind nicht nutzbar (nur Dummy-Hashes).' . PHP_EOL;
echo 'Echte Tokens müssen über die Admin-Oberfläche erzeugt werden.' . PHP_EOL;
