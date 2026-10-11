<?php
/**
 * @doc-file
 * @doc-id live_app.admin
 * @doc-kind page-controller
 * @doc-purpose Steuert die interne Admin-Oberflaeche, Formularaktionen und AJAX-Endpunkte fuer Teilnehmer, Teams und Auditdaten.
 * @doc-entry HTTP-Aufruf durch berechtigte Benutzer oder AJAX-Clients.
 * @doc-input Session, Berechtigungen, Formulareingaben, CSRF-Token und Datenbank.
 * @doc-output HTML der Admin-Seite oder JSON-Antworten fuer Einzelaktionen.
 * @doc-side-effects Liest und schreibt Datenbankeintraege, Sessionstatus und HTTP-Antworten.
 */
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_audit_endpoints.php';

// @doc-section logout-and-entry
// @doc-purpose Behandelt fruehe Admin-Einstiege wie Logout, bevor weitere Controller-Logik ausgefuehrt wird.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
        header('Location: admin.php');
        exit;
    }
    auth_mark_http_logout();
    auth_clear_session();
    header('Location: admin.php?logout=1');
    exit;
}

$users_enabled = auth_users_enabled($pdo);
$login_error = '';
$login_notice = '';

$cash_error = '';
$cash_success = '';
$cash_row = null;
$user_error = '';
$user_success = '';
$profile_error = '';
$profile_success = '';
$participant_error = '';
$participant_success = '';
$team_error = '';
$team_success = '';
$setup_mode = auth_is_setup_mode($pdo);
$allow_setup_password = auth_allow_setup_password($pdo);
$current_user = auth_current_user($pdo);
$is_logged_in = $current_user !== null;
$basic_auth_user = auth_http_user();
$basic_auth_detected = $basic_auth_user !== null;

$login_mode_label = 'Benutzer-Login';
$login_mode_message = 'Login ist nur per Benutzername/Passwort möglich.';
$login_mode_alert_class = 'alert';
if ($users_enabled && !$setup_mode) {
    $login_mode_label = 'DB‑Login';
    $login_mode_message = 'Login ist per Benutzername/Passwort möglich.';
    $login_mode_alert_class = 'alert';
} elseif ($setup_mode) {
    $login_mode_label = 'Setup';
    $login_mode_message = 'Es sind keine Nutzer angelegt. Lege den ersten Admin per CLI an: php scripts/create_admin.php <benutzername> <passwort> admin';
    $login_mode_alert_class = 'alert alert-error no-autohide';
} elseif ($users_enabled && $allow_setup_password) {
    $login_mode_label = 'Recovery';
    $login_mode_message = 'Recovery per Setup‑Passwort ist aktiviert. Nach dem Anlegen eines Admins ALLOW_SETUP_PASSWORD wieder auf 0 setzen.';
    $login_mode_alert_class = 'alert alert-error no-autohide';
} elseif (!$users_enabled && $allow_setup_password) {
    $login_mode_label = 'Setup';
    $login_mode_message = 'Nutzerverwaltung ist nicht verfügbar. Prüfe Datenbank und Migrationen.';
    $login_mode_alert_class = 'alert alert-error no-autohide';
}
if (isset($_GET['logout']) && (string)$_GET['logout'] === '1') {
    $login_notice = 'Du wurdest abgemeldet.';
    if ($basic_auth_detected) {
        $login_mode_message = 'Ein erkannter Basic-Auth-Header wird nach dem Logout fuer diese Browser-Sitzung ignoriert, bis du dich aktiv erneut anmeldest.';
    }
}

// @doc-section login-processing
// @doc-purpose Prueft Login-Versuche fuer reguläre Benutzer und optionale Recovery-Pfade.
if (!$is_logged_in && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $u = (string)($_POST['login_username'] ?? '');
    $p = (string)($_POST['login_password'] ?? '');
    if (auth_login_with_credentials($pdo, $u, $p)) {
        header('Location: admin.php');
        exit;
    }
    if ($setup_mode) {
        $login_error = 'Es existiert noch kein Benutzer. Lege zuerst per CLI einen Admin an.';
    } elseif (!$users_enabled) {
        $login_error = 'Nutzerverwaltung ist aktuell nicht verfügbar.';
    } elseif ($allow_setup_password) {
        $setupPassword = (string)($_POST['password'] ?? '');
        $setupHash = admin_password_hash_default();
        if ($setupPassword !== '' && $setupHash !== '' && password_verify($setupPassword, $setupHash)) {
            admin_login();
            header('Location: admin.php');
            exit;
        }
        if ($setupHash === '') {
            $login_error = 'Recovery ist aktiviert, aber ADMIN_SETUP_PASSWORD fehlt.';
        } else {
            $login_error = 'Benutzername/Passwort oder Recovery-Passwort ist falsch.';
        }
    } else {
        $login_error = 'Benutzername oder Passwort ist falsch.';
    }
}

if (!$is_logged_in && (isset($_GET['ajax_action']) || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ajax_action'])))) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Zugriff verweigert.']);
    exit;
}

// @doc-section ajax-endpoints
// @doc-purpose Bedient alle JSON-Endpunkte fuer Suche, Teilnehmerpflege, Zahlstatus, Teams und Audit-Log.
// @doc-trigger GET ajax_action oder POST ajax_action
// @doc-output JSON-Antworten fuer die Admin-Oberflaeche
$rows = [];
if ($is_logged_in) {
    if (admin_handle_audit_ajax($pdo, $current_user ?? [])) {
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'participants.read');
        $code_in = registration_code_normalize((string)($_GET['code'] ?? ''));
        if ($code_in === '') {
            echo json_encode(['success' => false, 'error' => 'Bitte Code eingeben.']);
            exit;
        }
        
        // @doc-note Live-Suche nutzt LIKE auf dem Code-Praefix ab dem ersten eingegebenen Zeichen.
        // @doc-note Bei unvollstaendigen Codes wird die Pruefziffer ignoriert; die Antwort bleibt auf zehn Treffer begrenzt.
        $find = $pdo->prepare('
            SELECT
                t.id,
                t.code,
                t.nachname,
                t.vorname,
                t.volljaehrig,
                t.geschlecht,
                t.team_id,
                te.name AS teamname,
                t.registriert_am,
                t.bezahlt,
                t.bezahlt_am,
                t.startnummer
            FROM teilnehmer t
            LEFT JOIN teams te ON te.id = t.team_id
            WHERE t.code LIKE :code
            ORDER BY t.registriert_am DESC
            LIMIT 10
        ');
        $find->execute([':code' => $code_in . '%']);
        $rows_data = $find->fetchAll(PDO::FETCH_ASSOC);
        
        // @doc-note Ergaenzt pro Treffer die naechste fachlich passende Startnummer fuer die Kassenoberflaeche.
        foreach ($rows_data as &$row_d) {
            $team_id = isset($row_d['team_id']) ? (int)$row_d['team_id'] : null;
            $row_d['suggested_startnummer'] = get_next_startnummer_suggestion($pdo, $team_id > 0 ? $team_id : null);
        }
        unset($row_d);

        if (count($rows_data) > 0) {
            echo json_encode(['success' => true, 'data' => $rows_data]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Kein passender Code gefunden.']);
        }
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'team_members') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'teams.manage');
        $team = trim((string)($_GET['team'] ?? ''));
        if ($team === '') {
            echo json_encode(['success' => false, 'error' => 'Team fehlt.']);
            exit;
        }
        try {
            $teamId = null;
            if (ctype_digit($team)) {
                $teamId = (int)$team;
            } else {
                $teamName = team_name_normalize($team);
                if ($teamName !== null) {
                    $findTeam = $pdo->prepare('SELECT id FROM teams WHERE name = ? LIMIT 1');
                    $findTeam->execute([$teamName]);
                    $teamId = (int)$findTeam->fetchColumn();
                }
            }
            if (!$teamId) {
                echo json_encode(['success' => false, 'error' => 'Team nicht gefunden.']);
                exit;
            }
            $stmt = $pdo->prepare('
                SELECT t.id, t.code, t.vorname, t.nachname, te.name AS teamname
                FROM teilnehmer t
                LEFT JOIN teams te ON te.id = t.team_id
                WHERE t.team_id = ?
                ORDER BY t.nachname ASC, t.vorname ASC, t.id ASC
            ');
            $stmt->execute([$teamId]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success' => true, 'data' => $members]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Teammitglieder konnten nicht geladen werden.']);
        }
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'unassigned_participants') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'teams.manage');
        try {
            $stmt = $pdo->query("
                SELECT id, code, vorname, nachname
                FROM teilnehmer
                WHERE team_id IS NULL OR team_id = 0
                ORDER BY nachname ASC, vorname ASC, registriert_am DESC, id ASC
                LIMIT 2000
            ");
            $hits = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success' => true, 'data' => $hits]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Teilnehmer ohne Team konnten nicht geladen werden.']);
        }
        exit;
    }

    if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'participant_search') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'teams.manage');
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        $qLike = '%' . safe_substr($q, 0, 80) . '%';
        try {
            $stmt = $pdo->prepare("
                SELECT t.id, t.code, t.vorname, t.nachname, te.name AS teamname
                FROM teilnehmer t
                LEFT JOIN teams te ON te.id = t.team_id
                WHERE t.code LIKE :q OR t.vorname LIKE :q OR t.nachname LIKE :q OR CONCAT(t.vorname, ' ', t.nachname) LIKE :q OR CONCAT(t.nachname, ' ', t.vorname) LIKE :q
                ORDER BY t.nachname ASC, t.vorname ASC, t.registriert_am DESC
                LIMIT 25
            ");
            $stmt->execute([':q' => $qLike]);
            $hits = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success' => true, 'data' => $hits]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Suche fehlgeschlagen.']);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'mark_paid') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'payments.mark');
        auth_require_permission_or_403_json($pdo, 'numbers.assign');
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
            exit;
        }
        $code_in = registration_code_normalize((string)($_POST['code'] ?? ''));
        $wunschnummer = isset($_POST['wunschnummer']) ? (int)$_POST['wunschnummer'] : 0;

        if ($code_in === '' || !registration_code_is_valid($code_in)) {
            echo json_encode(['success' => false, 'error' => 'Ungültiger Code.']);
            exit;
        }

        $find = $pdo->prepare('SELECT id, team_id, bezahlt, startnummer FROM teilnehmer WHERE code = :code LIMIT 1');
        $find->execute([':code' => $code_in]);
        $teilnehmer = $find->fetch(PDO::FETCH_ASSOC);

        if (!$teilnehmer) {
            echo json_encode(['success' => false, 'error' => 'Konnte nicht als bezahlt markieren (Code nicht gefunden).']);
            exit;
        }
        if ((int)$teilnehmer['bezahlt'] === 1) {
            echo json_encode(['success' => false, 'error' => 'Bereits bezahlt.']);
            exit;
        }

        $t_id = (int)$teilnehmer['id'];
        $nummer_to_assign = null;

        // @doc-note Prueft vor der Buchung zuerst Wunschnummer oder alternativ die vorgeschlagene freie Nummer.
        if ($wunschnummer > 0) {
            $check = $pdo->prepare("SELECT status FROM startnummern WHERE nummer = ?");
            $check->execute([$wunschnummer]);
            if ($check->fetchColumn() === 'vergeben') {
                 echo json_encode(['success' => false, 'error' => "Startnummer $wunschnummer ist bereits vergeben."]);
                 exit;
            }
            $nummer_to_assign = $wunschnummer;
        } else {
            $team_id = isset($teilnehmer['team_id']) ? (int)$teilnehmer['team_id'] : null;
            $nummer_to_assign = get_next_startnummer_suggestion($pdo, $team_id && $team_id > 0 ? $team_id : null);
        }

        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE teilnehmer SET bezahlt = 1, bezahlt_am = NOW() WHERE id = ?');
        $upd->execute([$t_id]);

        if ($nummer_to_assign && !$teilnehmer['startnummer']) {
            $updS = $pdo->prepare("UPDATE startnummern SET status = 'vergeben', teilnehmer_id = ?, reserviert_fuer = NULL, team_id = NULL WHERE nummer = ?");
            $updS->execute([$t_id, $nummer_to_assign]);
            $updT = $pdo->prepare("UPDATE teilnehmer SET startnummer = ? WHERE id = ?");
            $updT->execute([$nummer_to_assign, $t_id]);
        }
        $pdo->commit();

        $find = $pdo->prepare('SELECT bezahlt_am FROM teilnehmer WHERE id = ? LIMIT 1');
        $find->execute([$t_id]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        
        $sn_text = $nummer_to_assign ? str_pad((string)$nummer_to_assign, 3, '0', STR_PAD_LEFT) : 'Keine freie Nummer';
        echo json_encode(['success' => true, 'message' => 'Als bezahlt markiert. Startnummer: ' . $sn_text, 'bezahlt_am' => $row['bezahlt_am'], 'startnummer' => $nummer_to_assign]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'change_startnummer') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'numbers.assign');
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
            exit;
        }
        $code_in = registration_code_normalize((string)($_POST['code'] ?? ''));
        $neue_nummer = (int)($_POST['neue_nummer'] ?? 0);

        if ($code_in === '' || !registration_code_is_valid($code_in) || $neue_nummer < 1 || $neue_nummer > 999) {
            echo json_encode(['success' => false, 'error' => 'Ungültiger Code oder Nummer (muss 1-999 sein).']);
            exit;
        }

        $find = $pdo->prepare('SELECT id, startnummer FROM teilnehmer WHERE code = :code LIMIT 1');
        $find->execute([':code' => $code_in]);
        $row = $find->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Teilnehmer nicht gefunden.']);
            exit;
        }

        $t_id = (int)$row['id'];
        $alte_nummer = $row['startnummer'] ? (int)$row['startnummer'] : null;

        if ($alte_nummer === $neue_nummer) {
            echo json_encode(['success' => true, 'message' => 'Nummer ist bereits ' . $neue_nummer, 'startnummer' => $neue_nummer]);
            exit;
        }

        // @doc-note Prueft vor dem Umhaengen, ob die neue Startnummer noch verfuegbar ist.
        $check = $pdo->prepare("SELECT status FROM startnummern WHERE nummer = ?");
        $check->execute([$neue_nummer]);
        $status = $check->fetchColumn();

        if ($status === 'vergeben') {
            echo json_encode(['success' => false, 'error' => 'Nummer ' . $neue_nummer . ' ist bereits vergeben.']);
            exit;
        }

        $pdo->beginTransaction();
        // Alte Nummer freigeben
        if ($alte_nummer) {
            $updAlt = $pdo->prepare("UPDATE startnummern SET status = 'frei', reserviert_fuer = NULL, team_id = NULL, teilnehmer_id = NULL WHERE nummer = ?");
            $updAlt->execute([$alte_nummer]);
        }
        // Neue Nummer vergeben
        $updNeu = $pdo->prepare("UPDATE startnummern SET status = 'vergeben', teilnehmer_id = ?, reserviert_fuer = NULL, team_id = NULL WHERE nummer = ?");
        $updNeu->execute([$t_id, $neue_nummer]);

        $updT = $pdo->prepare("UPDATE teilnehmer SET startnummer = ? WHERE id = ?");
        $updT->execute([$neue_nummer, $t_id]);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Startnummer erfolgreich auf ' . str_pad((string)$neue_nummer, 3, '0', STR_PAD_LEFT) . ' geändert.', 'startnummer' => $neue_nummer]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_participant') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'participants.edit');
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $vorname = registration_name_normalize((string)($_POST['vorname'] ?? ''), 100);
        $nachname = registration_name_normalize((string)($_POST['nachname'] ?? ''), 100);
        $volljaehrig_raw = (string)($_POST['volljaehrig'] ?? '');
        $geschlecht = (string)($_POST['geschlecht'] ?? '');
        $team_select = isset($_POST['team_select']) ? trim((string)$_POST['team_select']) : null;
        $new_team = isset($_POST['new_team']) ? trim((string)$_POST['new_team']) : null;
        $teamname_in = trim((string)($_POST['teamname'] ?? ''));

        $team_id = null;
        $teamname = null;
        if ($team_select !== null) {
            if ($team_select === '' || $team_select === '0') {
                $team_id = null;
                $teamname = null;
            } elseif ($team_select === '__NEW__') {
                $teamname = team_name_normalize($new_team);
                $team_id = $teamname !== null ? team_get_or_create_id($pdo, $teamname) : null;
            } elseif (ctype_digit($team_select)) {
                $team_id = (int)$team_select;
                $teamname = team_get_name_by_id($pdo, $team_id);
                if ($teamname === null) {
                    echo json_encode(['success' => false, 'error' => 'Team nicht gefunden.']);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Ungültige Teamauswahl.']);
                exit;
            }
        } elseif ($teamname_in !== '') {
            $teamname = team_name_normalize($teamname_in);
            $team_id = $teamname !== null ? team_get_or_create_id($pdo, $teamname) : null;
        }

        $volljaehrig = null;
        if ($volljaehrig_raw === '1' || $volljaehrig_raw === '0') {
            $volljaehrig = (int)$volljaehrig_raw;
        }

        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Ungültige ID.']);
            exit;
        }
        if ($vorname === '' || $nachname === '') {
            echo json_encode(['success' => false, 'error' => 'Vorname und Nachname sind Pflicht.']);
            exit;
        }
        if ($volljaehrig === null) {
            echo json_encode(['success' => false, 'error' => 'Bitte den Altersstatus auswählen.']);
            exit;
        }
        if (!in_array($geschlecht, ['Männlich', 'Weiblich', 'Divers'], true)) {
            echo json_encode(['success' => false, 'error' => 'Bitte ein gültiges Geschlecht auswählen.']);
            exit;
        }
        if ($team_select === '__NEW__' && $team_id === null) {
            echo json_encode(['success' => false, 'error' => 'Bitte einen neuen Teamnamen eingeben.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare('UPDATE teilnehmer SET vorname = ?, nachname = ?, volljaehrig = ?, geschlecht = ?, team_id = ?, teamname = ? WHERE id = ?');
            $stmt->execute([$vorname, $nachname, $volljaehrig, $geschlecht, $team_id, $teamname, $id]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Änderung konnte nicht gespeichert werden.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $id,
                'vorname' => $vorname,
                'nachname' => $nachname,
                'volljaehrig' => $volljaehrig,
                'geschlecht' => $geschlecht,
                'team_id' => $team_id,
                'teamname' => $teamname,
            ],
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_participant') {
        header('Content-Type: application/json');
        if (($current_user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Zugriff verweigert.']);
            exit;
        }
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Ungültige ID.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $find = $pdo->prepare('SELECT startnummer FROM teilnehmer WHERE id = ? LIMIT 1');
            $find->execute([$id]);
            $startnummer = $find->fetchColumn();
            if ($startnummer === false) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Teilnehmer nicht gefunden.']);
                exit;
            }

            if ($startnummer) {
                $updS = $pdo->prepare("UPDATE startnummern SET status = 'frei', reserviert_fuer = NULL, team_id = NULL, teilnehmer_id = NULL WHERE nummer = ?");
                $updS->execute([(int)$startnummer]);
            } else {
                $updS = $pdo->prepare("UPDATE startnummern SET status = 'frei', reserviert_fuer = NULL, team_id = NULL, teilnehmer_id = NULL WHERE teilnehmer_id = ?");
                $updS->execute([$id]);
            }

            $del = $pdo->prepare('DELETE FROM teilnehmer WHERE id = ? LIMIT 1');
            $del->execute([$id]);

            if ((int)$del->rowCount() !== 1) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Teilnehmer konnte nicht gelöscht werden.']);
                exit;
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'deleted_id' => $id]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Teilnehmer konnte nicht gelöscht werden.']);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'team_set_member') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'teams.manage');
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
            exit;
        }
        $participantId = (int)($_POST['participant_id'] ?? 0);
        $teamname_in = trim((string)($_POST['teamname'] ?? ''));
        $teamname = $teamname_in !== '' ? team_name_normalize($teamname_in) : null;
        $team_id = $teamname !== null ? team_get_or_create_id($pdo, $teamname) : null;
        if ($participantId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Ungültige Teilnehmer-ID.']);
            exit;
        }
        try {
            $upd = $pdo->prepare('UPDATE teilnehmer SET team_id = ?, teamname = ? WHERE id = ?');
            $upd->execute([$team_id, $teamname, $participantId]);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Änderung konnte nicht gespeichert werden.']);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'team_rename') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'teams.manage');
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
            exit;
        }
        $old_in = trim((string)($_POST['old_teamname'] ?? ''));
        $new_in = trim((string)($_POST['new_teamname'] ?? ''));
        if ($old_in === '' || $new_in === '') {
            echo json_encode(['success' => false, 'error' => 'Teamname fehlt.']);
            exit;
        }
        $old = team_name_normalize($old_in);
        $new = team_name_normalize($new_in);
        if ($old === null || $new === null) {
            echo json_encode(['success' => false, 'error' => 'Teamname ungültig.']);
            exit;
        }
        if ($old === $new) {
            echo json_encode(['success' => true, 'updated_members' => 0]);
            exit;
        }
        try {
            $pdo->beginTransaction();
            $findTeam = $pdo->prepare('SELECT id FROM teams WHERE name = ? LIMIT 1');
            $findTeam->execute([$old]);
            $teamId = (int)$findTeam->fetchColumn();
            if (!$teamId) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Team nicht gefunden.']);
                exit;
            }

            $updTeam = $pdo->prepare('UPDATE teams SET name = ? WHERE id = ?');
            $updTeam->execute([$new, $teamId]);

            $updMembers = $pdo->prepare('UPDATE teilnehmer SET teamname = ? WHERE team_id = ?');
            $updMembers->execute([$new, $teamId]);
            $moved = (int)$updMembers->rowCount();

            $updNums = $pdo->prepare("UPDATE startnummern SET reserviert_fuer = ? WHERE team_id = ?");
            $updNums->execute([$new, $teamId]);
            $pdo->commit();

            echo json_encode(['success' => true, 'updated_members' => $moved]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Team konnte nicht umbenannt werden.']);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'team_delete') {
        header('Content-Type: application/json');
        auth_require_permission_or_403_json($pdo, 'teams.manage');
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
            exit;
        }
        $team_in = trim((string)($_POST['teamname'] ?? ''));
        if ($team_in === '') {
            echo json_encode(['success' => false, 'error' => 'Teamname fehlt.']);
            exit;
        }
        $team = team_name_normalize($team_in);
        if ($team === null) {
            echo json_encode(['success' => false, 'error' => 'Teamname ungültig.']);
            exit;
        }
        try {
            $pdo->beginTransaction();
            $findTeam = $pdo->prepare('SELECT id FROM teams WHERE name = ? LIMIT 1');
            $findTeam->execute([$team]);
            $teamId = (int)$findTeam->fetchColumn();
            if (!$teamId) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Team nicht gefunden.']);
                exit;
            }

            $upd = $pdo->prepare('UPDATE teilnehmer SET team_id = NULL, teamname = NULL WHERE team_id = ?');
            $upd->execute([$teamId]);
            $removedMembers = (int)$upd->rowCount();

            $updS = $pdo->prepare("UPDATE startnummern SET status = 'frei', reserviert_fuer = NULL, team_id = NULL WHERE team_id = ? AND status = 'reserviert'");
            $updS->execute([$teamId]);

            $del = $pdo->prepare('DELETE FROM teams WHERE id = ?');
            $del->execute([$teamId]);
            $pdo->commit();
            echo json_encode(['success' => true, 'removed_members' => $removedMembers]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Team konnte nicht gelöscht werden.']);
        }
        exit;
    }

    // @doc-section user-management-actions
    // @doc-purpose Verarbeitet Benutzerverwaltung wie Anlegen, Rollenwechsel, Passwortwechsel und Loeschen.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_action'])) {
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            $user_error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
        } elseif (!$users_enabled) {
            $user_error = 'Nutzerverwaltung ist nicht verfügbar.';
        } elseif (!$setup_mode && !auth_can($pdo, 'users.manage')) {
            $user_error = 'Zugriff verweigert.';
        } else {
            $action = (string)$_POST['user_action'];
            if ($action === 'create_user') {
                $new_username = trim((string)($_POST['new_username'] ?? ''));
                $new_password = (string)($_POST['new_password'] ?? '');
                $new_role = (string)($_POST['new_role'] ?? 'viewer');
                if ($new_username === '' || $new_password === '') {
                    $user_error = 'Bitte Benutzername und Passwort angeben.';
                } elseif (!in_array($new_role, ['admin', 'cashier', 'orga', 'viewer'], true)) {
                    $user_error = 'Ungültige Rolle.';
                } else {
                    $pwCheck = auth_password_validate($new_password);
                    if (!$pwCheck['valid']) {
                        $user_error = implode(' ', $pwCheck['errors']);
                    } else {
                        if ($new_role === 'admin') {
                    try {
                        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                    } catch (Throwable $e) {
                        $adminCount = 0;
                    }
                    if ($adminCount >= 1) {
                        $user_error = 'Es gibt bereits einen Admin. Es darf global nur einen Admin geben.';
                    } else {
                        try {
                            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
                            $stmt->execute([$new_username, password_hash($new_password, PASSWORD_DEFAULT), $new_role]);
                            $new_id = (int)$pdo->lastInsertId();
                            if ($setup_mode) {
                                auth_set_session($new_id, $new_username, $new_role);
                                header('Location: admin.php');
                                exit;
                            }
                            $user_success = 'Nutzer wurde angelegt.';
                        } catch (Throwable $e) {
                            $user_error = 'Nutzer konnte nicht angelegt werden.';
                        }
                    }
                        } else {
                    try {
                        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
                        $stmt->execute([$new_username, password_hash($new_password, PASSWORD_DEFAULT), $new_role]);
                        $new_id = (int)$pdo->lastInsertId();
                        if ($setup_mode) {
                            auth_set_session($new_id, $new_username, $new_role);
                            header('Location: admin.php');
                            exit;
                        }
                        $user_success = 'Nutzer wurde angelegt.';
                    } catch (Throwable $e) {
                        $user_error = 'Nutzer konnte nicht angelegt werden.';
                    }
                        }
                    }
                }
            } elseif ($action === 'update_role') {
                $uid = (int)($_POST['user_id'] ?? 0);
                $role = (string)($_POST['role'] ?? '');
                if ($uid <= 0 || !in_array($role, ['admin', 'cashier', 'orga', 'viewer'], true)) {
                    $user_error = 'Ungültige Eingabe.';
                } else {
                    try {
                        $currentRoleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
                        $currentRoleStmt->execute([$uid]);
                        $currentRole = (string)($currentRoleStmt->fetchColumn() ?: '');

                        $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

                        if ($role === 'admin' && $currentRole !== 'admin' && $adminCount >= 1) {
                            $user_error = 'Es gibt bereits einen Admin. Es darf global nur einen Admin geben.';
                        } elseif ($currentRole === 'admin' && $role !== 'admin' && $adminCount <= 1) {
                            $user_error = 'Der letzte Admin kann nicht entfernt werden. Es muss global genau ein Admin existieren.';
                        } else {
                        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
                        $stmt->execute([$role, $uid]);
                        $user_success = 'Rolle wurde geändert.';
                        }
                    } catch (Throwable $e) {
                        $user_error = 'Rolle konnte nicht geändert werden.';
                    }
                }
            } elseif ($action === 'update_password') {
                $uid = (int)($_POST['user_id'] ?? 0);
                $pw = (string)($_POST['password'] ?? '');
                if ($uid <= 0 || $pw === '') {
                    $user_error = 'Ungültige Eingabe.';
                } else {
                    $pwCheck = auth_password_validate($pw);
                    if (!$pwCheck['valid']) {
                        $user_error = implode(' ', $pwCheck['errors']);
                    } else {
                    try {
                        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        $stmt->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
                        $user_success = 'Passwort wurde geändert.';
                    } catch (Throwable $e) {
                        $user_error = 'Passwort konnte nicht geändert werden.';
                    }
                    }
                }
            } elseif ($action === 'delete_user') {
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid <= 0) {
                    $user_error = 'Ungültige Eingabe.';
                } else {
                    try {
                        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
                        $roleStmt->execute([$uid]);
                        $roleToDelete = (string)($roleStmt->fetchColumn() ?: '');
                        if ($roleToDelete === 'admin') {
                            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                            if ($adminCount <= 1) {
                                $user_error = 'Der letzte Admin kann nicht gelöscht werden. Es muss global genau ein Admin existieren.';
                                $roleToDelete = '';
                            }
                        }
                        if ($user_error !== '') {
                            // no-op
                        } else {
                        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                        $stmt->execute([$uid]);
                        if ((int)($_SESSION['user_id'] ?? 0) === $uid) {
                            auth_clear_session();
                            header('Location: admin.php');
                            exit;
                        }
                        $user_success = 'Nutzer wurde gelöscht.';
                        }
                    } catch (Throwable $e) {
                        $user_error = 'Nutzer konnte nicht gelöscht werden.';
                    }
                }
            }
        }
    }

    // @doc-section profile-actions
    // @doc-purpose Erlaubt dem aktuell angemeldeten Benutzer die Pflege des eigenen Passworts.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_action']) && $_POST['profile_action'] === 'change_password') {
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            $profile_error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
        } elseif (!$users_enabled) {
            $profile_error = 'Passwortänderung ist nicht verfügbar.';
        } else {
            $uid = (int)($current_user['id'] ?? 0);
            if ($uid <= 0) {
                $profile_error = 'Passwortänderung ist für diesen Login-Typ nicht verfügbar.';
            } else {
                $currentPw = (string)($_POST['current_password'] ?? '');
                $newPw = (string)($_POST['new_password'] ?? '');
                $newPw2 = (string)($_POST['new_password_confirm'] ?? '');

                if ($currentPw === '' || $newPw === '' || $newPw2 === '') {
                    $profile_error = 'Bitte alle Passwort-Felder ausfüllen.';
                } elseif ($newPw !== $newPw2) {
                    $profile_error = 'Neue Passwörter stimmen nicht überein.';
                } else {
                    $pwCheck = auth_password_validate($newPw);
                    if (!$pwCheck['valid']) {
                        $profile_error = implode(' ', $pwCheck['errors']);
                    } else {
                    try {
                        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                        $stmt->execute([$uid]);
                        $hash = (string)($stmt->fetchColumn() ?: '');
                        if ($hash === '' || !password_verify($currentPw, $hash)) {
                            $profile_error = 'Aktuelles Passwort ist falsch.';
                        } else {
                            $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                            $upd->execute([password_hash($newPw, PASSWORD_DEFAULT), $uid]);
                            $profile_success = 'Passwort wurde geändert.';
                        }
                    } catch (Throwable $e) {
                        $profile_error = 'Passwort konnte nicht geändert werden.';
                    }
                    }
                }
            }
        }
    }

    // @doc-section team-actions
    // @doc-purpose Verarbeitet klassische Formularaktionen rund um Teamanlage und Teambearbeitung ausserhalb der AJAX-Modale.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['team_action'])) {
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            $team_error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
        } elseif (!auth_can($pdo, 'teams.manage')) {
            $team_error = 'Zugriff verweigert.';
        } else {
            $action = (string)$_POST['team_action'];
            if ($action === 'create_team') {
                $nameRaw = trim((string)($_POST['team_name'] ?? ''));
                $name = $nameRaw !== '' ? registration_name_normalize($nameRaw, 150) : '';
                if ($name === '') {
                    $team_error = 'Bitte einen Teamnamen eingeben.';
                } else {
                    try {
                        $stmt = $pdo->prepare('INSERT INTO teams (name) VALUES (?)');
                        $stmt->execute([$name]);
                        $team_success = 'Team wurde angelegt.';
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            $team_error = 'Team existiert bereits.';
                        } else {
                            $team_error = 'Team konnte nicht angelegt werden.';
                        }
                    } catch (Throwable $e) {
                        $team_error = 'Team konnte nicht angelegt werden.';
                    }
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['participant_action']) && $_POST['participant_action'] === 'create') {
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            $participant_error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
        } elseif (!auth_can($pdo, 'participants.create')) {
            $participant_error = 'Zugriff verweigert.';
        } else {
            $vorname = registration_name_normalize((string)($_POST['vorname'] ?? ''), 100);
            $nachname = registration_name_normalize((string)($_POST['nachname'] ?? ''), 100);
            $volljaehrig_raw = $_POST['volljaehrig'] ?? null;
            $geschlecht = (string)($_POST['geschlecht'] ?? '');
            $team_select = isset($_POST['team_select']) ? trim((string)$_POST['team_select']) : null;
            $new_team = isset($_POST['new_team']) ? trim((string)$_POST['new_team']) : null;
            $team_id = null;
            $teamname = null;
            if ($team_select === '__NEW__') {
                $teamname = team_name_normalize($new_team);
                $team_id = $teamname !== null ? team_get_or_create_id($pdo, $teamname) : null;
            } elseif ($team_select !== null && $team_select !== '' && ctype_digit($team_select)) {
                $team_id = (int)$team_select;
                $teamname = team_get_name_by_id($pdo, $team_id);
                if ($teamname === null) {
                    $team_id = null;
                }
            } else {
                $fallback = trim((string)($_POST['teamname'] ?? ''));
                $teamname = $fallback !== '' ? team_name_normalize($fallback) : null;
                $team_id = $teamname !== null ? team_get_or_create_id($pdo, $teamname) : null;
            }
            $teilnahme_ok = 1;
            $dsgvo_ok = 1;
            $bildton_ok = 1;

            $volljaehrig = null;
            if ($volljaehrig_raw === '1' || $volljaehrig_raw === '0') {
                $volljaehrig = (int)$volljaehrig_raw;
            }

            if ($vorname === '' || $nachname === '') {
                $participant_error = 'Bitte Vorname und Nachname ausfüllen.';
            } elseif ($volljaehrig === null) {
                $participant_error = 'Bitte den Altersstatus auswählen.';
            } elseif (!in_array($geschlecht, ['Männlich', 'Weiblich', 'Divers'], true)) {
                $participant_error = 'Bitte ein Geschlecht auswählen.';
            } elseif ($team_select === '__NEW__' && $teamname === null) {
                $participant_error = 'Bitte einen Teamnamen eingeben oder ein bestehendes Team wählen.';
            } elseif ($team_select !== null && $team_select !== '' && $team_select !== '__NEW__' && !ctype_digit($team_select)) {
                $participant_error = 'Bitte ein gültiges Team wählen.';
            } elseif ($team_select !== null && $team_select !== '' && $team_select !== '__NEW__' && $team_id === null) {
                $participant_error = 'Bitte ein gültiges Team wählen.';
            } else {
                $dup = $pdo->prepare('SELECT 1 FROM teilnehmer WHERE vorname = :vorname AND nachname = :nachname LIMIT 1');
                $dup->execute([':vorname' => $vorname, ':nachname' => $nachname]);
                if ((int)$dup->fetchColumn() === 1) {
                    $participant_error = 'Mit diesem Vor- und Nachnamen existiert bereits eine Anmeldung.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO teilnehmer (code, vorname, nachname, volljaehrig, geschlecht, team_id, teamname, teilnahmebedingungen_akzeptiert, dsgvo_akzeptiert, bild_ton_akzeptiert)
                         VALUES (:code, :vorname, :nachname, :volljaehrig, :geschlecht, :team_id, :teamname, :teilnahme, :dsgvo, :bildton)'
                    );

                    $code = '';
                    $tries = 0;
                    while (true) {
                        $code = registration_code_generate(9);
                        try {
                            $stmt->execute([
                                ':code' => $code,
                                ':vorname' => $vorname,
                                ':nachname' => $nachname,
                                ':volljaehrig' => $volljaehrig,
                                ':geschlecht' => $geschlecht,
                                ':team_id' => $team_id,
                                ':teamname' => $teamname,
                                ':teilnahme' => 1,
                                ':dsgvo' => 1,
                                ':bildton' => 1,
                            ]);
                            break;
                        } catch (PDOException $e) {
                            $tries++;
                            if ($tries < 6 && $e->getCode() === '23000') {
                                continue;
                            }
                            throw $e;
                        }
                    }

                    $participant_success = 'Teilnehmer angelegt. Code: ' . registration_code_format($code);
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
        if (!auth_can($pdo, 'payments.mark') || !auth_can($pdo, 'numbers.assign')) {
            $cash_error = 'Zugriff verweigert.';
        } elseif (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            $cash_error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
        } else {
            $code_in = registration_code_normalize((string)($_POST['code'] ?? ''));
            if ($code_in === '' || !registration_code_is_valid($code_in)) {
                $cash_error = 'Ungültiger Code (Prüfziffer passt nicht).';
            } else {
                $find = $pdo->prepare('SELECT id, team_id, bezahlt, startnummer FROM teilnehmer WHERE code = :code LIMIT 1');
                $find->execute([':code' => $code_in]);
                $teilnehmer = $find->fetch(PDO::FETCH_ASSOC);

                if (!$teilnehmer) {
                    $cash_error = 'Konnte nicht als bezahlt markieren (Code nicht gefunden).';
                } elseif ((int)$teilnehmer['bezahlt'] === 1) {
                    $cash_error = 'Bereits bezahlt.';
                } else {
                    $t_id = (int)$teilnehmer['id'];
                    $team_id = isset($teilnehmer['team_id']) ? (int)$teilnehmer['team_id'] : null;
                    $nummer_to_assign = get_next_startnummer_suggestion($pdo, $team_id && $team_id > 0 ? $team_id : null);

                    $pdo->beginTransaction();
                    $upd = $pdo->prepare('UPDATE teilnehmer SET bezahlt = 1, bezahlt_am = NOW() WHERE id = ?');
                    $upd->execute([$t_id]);

                    if ($nummer_to_assign && !$teilnehmer['startnummer']) {
                        $updS = $pdo->prepare("UPDATE startnummern SET status = 'vergeben', teilnehmer_id = ?, reserviert_fuer = NULL, team_id = NULL WHERE nummer = ?");
                        $updS->execute([$t_id, $nummer_to_assign]);
                        $updT = $pdo->prepare("UPDATE teilnehmer SET startnummer = ? WHERE id = ?");
                        $updT->execute([$nummer_to_assign, $t_id]);
                    }
                    $pdo->commit();

                    $sn_text = $nummer_to_assign ? str_pad((string)$nummer_to_assign, 3, '0', STR_PAD_LEFT) : 'Keine freie Nummer';
                    $cash_success = 'Als bezahlt markiert. Startnummer: ' . $sn_text;
                }
            }
        }
    }

    $code = isset($_GET['code']) ? registration_code_normalize((string)$_GET['code']) : '';
    if ($code !== '') {
        if (!registration_code_is_valid($code)) {
            $cash_error = 'Ungültiger Code (Prüfziffer passt nicht).';
        } else {
            $find = $pdo->prepare('
                SELECT
                    t.id,
                    t.code,
                    t.nachname,
                    t.vorname,
                    t.volljaehrig,
                    t.geschlecht,
                    te.name AS teamname,
                    t.registriert_am,
                    t.bezahlt,
                    t.bezahlt_am,
                    t.startnummer
                FROM teilnehmer t
                LEFT JOIN teams te ON te.id = t.team_id
                WHERE t.code = :code
                LIMIT 1
            ');
            $find->execute([':code' => $code]);
            $cash_row = $find->fetch() ?: null;
            if ($cash_row === null) {
                $cash_error = 'Code nicht gefunden.';
            }
        }
    }

    $stmt = $pdo->query('
        SELECT
            t.id,
            t.code,
            t.nachname,
            t.vorname,
            t.volljaehrig,
            t.geschlecht,
            t.team_id,
            te.name AS teamname,
            t.registriert_am,
            t.bezahlt,
            t.bezahlt_am,
            t.startnummer
        FROM teilnehmer t
        LEFT JOIN teams te ON te.id = t.team_id
        ORDER BY t.registriert_am DESC
    ');
    $rows = $stmt->fetchAll();
}
?>
<?php
// @doc-section page-data-loading
// @doc-purpose Laedt nach Abschluss aller Schreiboperationen die Datenbasis fuer die HTML-Ansicht.
$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$assetBase = $assetBase === '' ? '' : $assetBase;
$role = $current_user ? (string)($current_user['role'] ?? '') : '';
$can_change_own_password = $users_enabled && $is_logged_in && (int)($current_user['id'] ?? 0) > 0;
$can_pay = $is_logged_in && auth_can($pdo, 'payments.mark') && auth_can($pdo, 'numbers.assign');
$can_change_num = $is_logged_in && auth_can($pdo, 'numbers.assign');
$can_create_participant = $is_logged_in && auth_can($pdo, 'participants.create');
$can_edit_participants = $is_logged_in && auth_can($pdo, 'participants.edit');
$can_delete_participants = $is_logged_in && $role === 'admin';
$can_manage_users = $users_enabled && $is_logged_in && auth_can($pdo, 'users.manage');
$can_numbers_page = $is_logged_in && auth_can($pdo, 'numbers.read');
$can_dashboard_page = $is_logged_in && auth_can($pdo, 'participants.read');
$can_export_participants = $is_logged_in && auth_can($pdo, 'exports.participants');
$can_manage_timeframes = $is_logged_in && auth_can($pdo, 'timeframes.manage');
$can_admin_pages = $is_logged_in && $role === 'admin';
$can_manage_teams = $is_logged_in && auth_can($pdo, 'teams.manage');
$teams = [];
if ($can_create_participant || $can_edit_participants) {
    try {
        $teams = get_existing_teams($pdo);
    } catch (Throwable $e) {
        $teams = [];
    }
}
$teams_overview = [];
if ($can_manage_teams) {
    try {
        $stmt = $pdo->query("
            SELECT te.id, te.name, COUNT(t.id) AS members
            FROM teams te
            LEFT JOIN teilnehmer t ON t.team_id = te.id
            GROUP BY te.id, te.name
            ORDER BY te.name ASC
        ");
        $teams_overview = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $teams_overview = [];
    }
}
$users = [];
$admin_count = 0;
if ($can_manage_users) {
    try {
        $pdo->exec("UPDATE users SET role = 'orga' WHERE role = 'numbers'");
        $users = $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $admin_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    } catch (Throwable $e) {
        $users = [];
        $admin_count = 0;
    }
}
?>
<?php
// @doc-section admin-page-rendering
// @doc-purpose Rendert die komplette Admin-Oberflaeche inklusive Teilnehmerliste, Teams, Benutzerverwaltung und Audit-Log.
?>
<!doctype html>
<html lang="de" data-theme="light">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin – 24‑Stunden‑Schwimmen (DLRG Sankt Augustin)</title>
    <link rel="stylesheet" href="<?php echo escape($assetBase . '/assets/style.css'); ?>?v=<?php echo escape(app_asset_version('assets/style.css')); ?>" />
    <script>
      (() => {
        const key = 'admin_theme';
        let theme = '';
        try {
          theme = String(localStorage.getItem(key) || '');
        } catch (e) {
          theme = '';
        }
        if (theme !== 'dark' && theme !== 'light') {
          const prefersDark = typeof window !== 'undefined'
            && typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-color-scheme: dark)').matches;
          theme = prefersDark ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
      })();
    </script>
    <script charset="utf-8" src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" integrity="sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+" crossorigin="anonymous"></script>
    <meta name="csrf-token" content="<?php echo escape(csrf_token_get()); ?>" />
    <style>
      /* @doc-note Entfernt Browser-Spinner an Number-Inputs, damit das Admin-UI kompakter wirkt. */
      input[type="number"]::-webkit-outer-spin-button,
      input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
      }
      input[type="number"] {
        -moz-appearance: textfield;
      }

      html[data-theme="dark"] {
        --text: #e6e9ef;
        --bg: #0b0d10;
        --panel: #121621;
        --border: #283246;
        --muted: #a9b3c3;
        --focus: rgba(255, 237, 0, 0.18);
        --dlrg-schwarz: #2f3845;
      }

      html[data-theme="dark"] .panel,
      html[data-theme="dark"] .alert,
      html[data-theme="dark"] .modal-panel {
        background: #141a24;
        color: var(--text);
      }

      html[data-theme="dark"] .modal-close {
        background: #141a24;
        color: var(--text);
        border-color: var(--border);
      }

      html[data-theme="dark"] input[type="text"],
      html[data-theme="dark"] input[type="password"],
      html[data-theme="dark"] input[type="number"],
      html[data-theme="dark"] input[type="datetime-local"],
      html[data-theme="dark"] input[type="email"],
      html[data-theme="dark"] select {
        background: #0f131a;
        color: var(--text);
        border-color: var(--border);
      }

      html[data-theme="dark"] tr:nth-child(even) td {
        background: rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] .audit-diff th {
        background: rgba(255, 255, 255, 0.06);
        color: var(--text);
      }

      .admin-page .container {
        width: min(1720px, calc(100vw - 24px)) !important;
        max-width: none !important;
        margin-inline: auto;
        padding-inline: 6px;
        box-sizing: border-box;
      }

      .admin-page {
        background:
          radial-gradient(circle at top center, rgba(227, 6, 19, 0.08), transparent 38%),
          linear-gradient(180deg, #f5f7fb 0%, #eef2f8 100%);
      }

      .admin-page .reg-header {
        margin-bottom: 18px;
      }

      .admin-shell {
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .admin-shell-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
      }

      .admin-session-info {
        margin: 0;
        flex: 1 1 320px;
        width: auto;
        max-width: 100%;
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        column-gap: 8px;
        row-gap: 4px;
        overflow-wrap: anywhere;
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: #f7f8fb;
        line-height: 1.35;
      }

      .admin-session-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 28px;
        padding: 4px 9px;
        border-radius: 999px;
        border: 1px solid rgba(227, 6, 19, 0.12);
        background: rgba(227, 6, 19, 0.06);
        color: var(--text);
        font-size: 14px;
        font-weight: 600;
        white-space: nowrap;
      }

      .admin-session-badge.is-warn {
        border-color: rgba(255, 237, 0, 0.24);
        background: rgba(255, 237, 0, 0.24);
      }

      html[data-theme="dark"] .admin-session-info {
        background: #141a24;
      }

      html[data-theme="dark"] .admin-session-badge {
        background: rgba(255, 255, 255, 0.05);
        border-color: rgba(255, 255, 255, 0.08);
        color: var(--text);
      }

      html[data-theme="dark"] .admin-session-badge.is-warn {
        background: rgba(255, 237, 0, 0.14);
        border-color: rgba(255, 237, 0, 0.22);
      }

      .admin-session-actions {
        margin-left: auto;
        display: flex;
        gap: 10px;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: wrap;
      }

      .admin-page-shell {
        --admin-sidebar-width: 336px;
        --admin-sidebar-min: 280px;
        --admin-sidebar-max: 500px;
        width: 100%;
        align-items: start;
      }

      .admin-page-sidebar {
        min-width: 0;
        align-self: start;
      }

      .admin-mobile-menu-toggle,
      .admin-page-sidebar-mobile-head {
        display: none;
      }

      .admin-mobile-menu-toggle {
        width: 100%;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-inline: 14px;
      }

      .admin-mobile-menu-toggle-copy {
        min-width: 0;
        display: grid;
        gap: 2px;
        text-align: left;
      }

      .admin-mobile-menu-toggle-kicker {
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }

      .admin-mobile-menu-toggle-label {
        font-size: 16px;
        font-weight: 700;
        line-height: 1.25;
      }

      .admin-mobile-menu-toggle-icon {
        flex: 0 0 auto;
        font-size: 22px;
        line-height: 1;
      }

      .admin-page-main {
        min-width: 0;
        display: grid;
        gap: 16px;
        align-content: start;
        align-self: start;
      }

      .admin-content-frame {
        display: flex;
        flex-direction: column;
        gap: 16px;
        min-width: 0;
        min-height: 0;
      }

      .admin-content-frame-head {
        display: grid;
        gap: 6px;
        padding-bottom: 14px;
        border-bottom: 1px solid rgba(40, 50, 70, 0.16);
      }

      .admin-content-kicker {
        margin: 0;
        letter-spacing: 0.04em;
      }

      .admin-content-title {
        margin: 0;
        font-size: clamp(24px, 2vw, 32px);
        line-height: 1.04;
        letter-spacing: -0.03em;
      }

      .admin-content-copy {
        margin: 0;
        color: var(--muted);
        line-height: 1.5;
      }

      .admin-action-ticker {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
        padding: 10px 14px;
        border: 1px solid rgba(40, 50, 70, 0.12);
        border-radius: 14px;
        background: rgba(245, 247, 251, 0.82);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.56);
      }

      .admin-action-ticker-label {
        flex: 0 0 auto;
        margin: 0;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
      }

      .admin-action-ticker-viewport {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
      }

      .admin-action-ticker-text {
        display: block;
        min-width: 0;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: opacity 0.18s ease, transform 0.18s ease;
      }

      .admin-action-ticker-text.is-updating {
        opacity: 0.58;
        transform: translateY(1px);
      }

      .admin-content-frame-body {
        display: flex;
        flex: 1 1 auto;
        min-height: 0;
      }

      .admin-content-scroll,
      .admin-content-iframe {
        width: 100%;
        min-width: 0;
      }

      .admin-content-scroll {
        display: grid;
        gap: 16px;
        align-content: start;
        flex: 1 1 auto;
        min-height: 0;
      }

      .admin-content-iframe {
        display: block;
        flex: 1 1 auto;
        min-height: 720px;
        border: 1px solid rgba(40, 50, 70, 0.14);
        border-radius: 18px;
        background: #ffffff;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.54);
      }

      .admin-content-iframe[hidden] {
        display: none !important;
      }

      .admin-shell-panel {
        display: grid;
        gap: 14px;
        align-content: start;
        padding: 18px;
        border-radius: 20px;
        background:
          linear-gradient(180deg, rgba(227, 6, 19, 0.05) 0%, rgba(227, 6, 19, 0) 180px),
          linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0)),
          var(--panel);
        box-shadow: 0 20px 48px rgba(7, 10, 18, 0.18);
      }

      .admin-shell-panel.admin-content-frame {
        display: flex;
        flex-direction: column;
        gap: 16px;
      }

      .admin-page-sidebar .admin-shell-panel {
        margin: 0 !important;
      }

      .admin-shell-intro {
        display: grid;
        gap: 8px;
        padding-bottom: 14px;
        border-bottom: 1px solid rgba(40, 50, 70, 0.16);
      }

      .admin-sidebar-title {
        margin: 0;
        font-size: clamp(24px, 2vw, 32px);
        line-height: 1.02;
        letter-spacing: -0.03em;
      }

      .admin-sidebar-copy {
        margin: 0;
        color: var(--muted);
        line-height: 1.5;
      }

      .admin-side-list {
        display: grid;
        gap: 12px;
      }

      .admin-side-group {
        display: grid;
        gap: 10px;
        padding: 14px;
        border: 1px solid rgba(40, 50, 70, 0.12);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.42);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.56);
      }

      .admin-side-group-head {
        display: grid;
        gap: 4px;
      }

      .admin-side-title {
        margin: 0;
        font-size: 16px;
        line-height: 1.2;
      }

      .admin-side-copy {
        margin: 0;
        color: var(--muted);
        font-size: 14px;
        line-height: 1.45;
      }

      .admin-shell-panel .sidebar-label {
        margin-bottom: 0;
        letter-spacing: 0.04em;
      }

      .admin-shell-panel .admin-nav,
      .admin-shell-panel .admin-anchor-nav,
      .admin-shell-panel .admin-links-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
      }

      .admin-shell-panel .admin-session-actions {
        width: calc(100% + 6px);
        margin-inline: -3px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
      }

      .admin-shell-panel .admin-controls-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
      }

      .admin-shell-panel .admin-controls-grid .field {
        margin: 0;
      }

      .admin-shell-panel .admin-search-form,
      .admin-shell-panel .admin-search-actions {
        flex-direction: column;
        align-items: stretch;
      }

      .admin-shell-panel .admin-search-form {
        gap: 8px;
      }

      .admin-shell-panel .admin-search-form input,
      .admin-shell-panel .admin-controls-grid input {
        min-height: 48px;
      }

      .admin-shell-panel .admin-link-btn,
      .admin-shell-panel .admin-nav-btn,
      .admin-shell-panel .admin-anchor-btn,
      .admin-shell-panel .admin-session-actions > * {
        width: 100%;
        min-height: 48px;
        justify-content: flex-start;
        border-radius: 14px;
      }

      .admin-shell-panel .admin-session-actions > * {
        min-width: 0;
        width: 100%;
        justify-content: center;
      }

      .admin-shell-panel .admin-nav-btn,
      .admin-shell-panel .admin-anchor-btn {
        position: relative;
        overflow: hidden;
        transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease, background 0.16s ease;
      }

      .admin-shell-panel .admin-nav-btn:hover,
      .admin-shell-panel .admin-anchor-btn:hover {
        transform: translateY(-1px);
      }

      .admin-shell-panel .admin-nav-btn:not(.btn-secondary) {
        color: #ffffff;
        border-color: rgba(227, 6, 19, 0.62);
        background: linear-gradient(135deg, #e30613 0%, #ff3f4f 100%);
        box-shadow: 0 14px 28px rgba(227, 6, 19, 0.24);
      }

      .admin-shell-panel .admin-nav-btn:not(.btn-secondary)::after {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: rgba(255, 255, 255, 0.78);
      }

      .admin-shell-panel .admin-link-btn.is-active-link {
        color: #ffffff;
        border-color: rgba(227, 6, 19, 0.62);
        background: linear-gradient(135deg, #e30613 0%, #ff3f4f 100%);
        box-shadow: 0 14px 28px rgba(227, 6, 19, 0.24);
      }

      .admin-anchor-btn.is-active-anchor {
        border-color: rgba(227, 6, 19, 0.36);
        color: #e30613;
        background: rgba(227, 6, 19, 0.08);
      }

      .admin-anchor-empty[hidden] {
        display: none !important;
      }

      .admin-anchor-target {
        scroll-margin-top: 18px;
      }

      .admin-session-meta {
        display: grid;
        gap: 6px;
      }

      .admin-session-meta strong {
        display: block;
        font-size: 15px;
      }

      .admin-page-shell-resizer {
        display: none;
      }

      .admin-page-main > .panel,
      .admin-page-main > .alert,
      .admin-content-scroll > .panel,
      .admin-content-scroll > .alert {
        position: relative;
        width: 100%;
        margin: 0 !important;
        box-sizing: border-box;
      }

      .admin-page-main > .panel,
      .admin-content-scroll > .panel {
        overflow: hidden;
        border-radius: 20px;
        padding: 20px;
        border-color: rgba(40, 50, 70, 0.16);
        background:
          linear-gradient(180deg, rgba(255, 255, 255, 0.94), rgba(248, 250, 254, 0.96)),
          #ffffff;
        box-shadow:
          0 18px 42px rgba(15, 23, 42, 0.08),
          0 4px 12px rgba(15, 23, 42, 0.04);
      }

      .admin-page-main > .panel::before,
      .admin-content-scroll > .panel::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: linear-gradient(90deg, #e30613 0%, rgba(227, 6, 19, 0.12) 68%, transparent 100%);
      }

      .admin-page-main > .panel > h2:first-child,
      .admin-page-main > .panel .modal-title,
      .admin-content-scroll > .panel > h2:first-child,
      .admin-content-scroll > .panel .modal-title {
        margin-top: 0;
        letter-spacing: -0.02em;
      }

      .admin-page-main > .panel > h2:first-child,
      .admin-content-scroll > .panel > h2:first-child {
        font-size: clamp(24px, 1.8vw, 32px);
        line-height: 1.05;
        margin-bottom: 16px;
      }

      .admin-page-main .hint,
      .admin-page-main .muted {
        line-height: 1.5;
      }

      .admin-page-main .table-wrap {
        margin-top: 14px;
        overflow: auto;
        border: 1px solid rgba(40, 50, 70, 0.12);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.72);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
      }

      .admin-page-main .table-wrap table {
        margin: 0;
      }

      .admin-page-main .table-wrap thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        backdrop-filter: blur(8px);
      }

      .admin-page-main .panel .panel {
        border-radius: 16px;
        box-shadow: none;
        background: rgba(245, 247, 251, 0.74);
      }

      .admin-page-main .alert {
        border-radius: 16px;
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08);
      }

      /* Der rechte Inhalts-Frame behaelt eine ruhigere, eigenstaendige Optik. */
      #admin_content_scroll {
        --text: #222222;
        --panel: #ffffff;
        --border: #d7dbe0;
        --muted: #5a6570;
        --focus: rgba(227, 6, 19, 0.25);
        --dlrg-schwarz: #575756;
        color: var(--text);
      }

      #admin_content_scroll .hint,
      #admin_content_scroll .muted,
      #admin_content_scroll label {
        color: inherit;
      }

      #admin_content_scroll .muted,
      #admin_content_scroll .hint {
        color: var(--muted);
      }

      #admin_internal_frame > .panel,
      #admin_internal_frame > .alert {
        position: relative;
        width: 100%;
        margin: 0 !important;
        box-sizing: border-box;
      }

      #admin_internal_frame > .panel {
        overflow: visible;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 18px;
        background: var(--panel);
        box-shadow: none;
      }

      #admin_internal_frame > .panel::before {
        content: none;
      }

      #admin_internal_frame > .panel > h2:first-child,
      #admin_internal_frame > .panel .modal-title {
        margin-top: 0;
        letter-spacing: normal;
      }

      #admin_internal_frame > .panel > h2:first-child {
        font-size: 1.5rem;
        line-height: 1.2;
        margin-bottom: 10px;
      }

      #admin_content_scroll .table-wrap {
        margin-top: 14px;
        overflow: auto;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--panel);
        box-shadow: none;
      }

      #admin_content_scroll .table-wrap table {
        margin: 0;
      }

      #admin_content_scroll .table-wrap thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        backdrop-filter: none;
      }

      #admin_content_scroll .panel .panel {
        border-radius: 10px;
        box-shadow: none;
        background: #f7f8fb;
      }

      #admin_content_scroll .alert {
        border-radius: 10px;
        box-shadow: none;
      }

      body.admin-shell-resizing {
        cursor: col-resize;
        user-select: none;
      }

      @media (min-width: 1280px) {
        .admin-page .admin-page-shell {
          display: grid;
          grid-template-columns: minmax(var(--admin-sidebar-min), var(--admin-sidebar-width)) 14px minmax(0, 1fr);
          gap: 20px;
          align-items: start;
        }

        .admin-page .admin-page-sidebar {
          position: relative;
          top: auto;
        }

        .admin-page .admin-page-sidebar .admin-shell-panel {
          position: sticky;
          top: 12px;
          max-height: calc(100vh - 24px);
          overflow: auto;
        }

        .admin-page .admin-page-main {
          min-width: 0;
        }

        .admin-page-main {
          gap: 18px;
          min-height: calc(100vh - 24px);
        }

        .admin-page-main > .panel {
          padding: 24px;
        }

        .admin-content-frame {
          min-height: calc(100vh - 24px);
          max-height: calc(100vh - 24px);
        }

        .admin-content-scroll {
          overflow: auto;
          padding-right: 4px;
        }

        .admin-content-iframe {
          min-height: 0;
          height: 100%;
        }

        .admin-page-shell-resizer {
          position: relative;
          display: block;
          min-height: 100%;
          cursor: col-resize;
          border: 0;
          padding: 0;
          background: transparent;
          touch-action: none;
        }

        .admin-page-shell-resizer::before {
          content: '';
          position: absolute;
          left: 50%;
          top: 12px;
          bottom: 12px;
          width: 2px;
          border-radius: 999px;
          transform: translateX(-50%);
          background: linear-gradient(180deg, rgba(227, 6, 19, 0.18), rgba(40, 50, 70, 0.6), rgba(227, 6, 19, 0.18));
          transition: background 0.16s ease, box-shadow 0.16s ease;
        }

        .admin-page-shell-resizer::after {
          content: '||';
          position: sticky;
          top: 72px;
          left: 50%;
          display: grid;
          place-items: center;
          width: 14px;
          height: 54px;
          margin-left: -7px;
          border-radius: 999px;
          border: 1px solid rgba(40, 50, 70, 0.4);
          background: rgba(255, 255, 255, 0.9);
          color: var(--muted);
          font-size: 11px;
          letter-spacing: -1px;
          box-shadow: 0 12px 24px rgba(7, 10, 18, 0.12);
          transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease, background 0.16s ease;
        }

        .admin-page-shell-resizer:hover::before,
        .admin-page-shell-resizer.is-dragging::before {
          background: linear-gradient(180deg, rgba(227, 6, 19, 0.4), rgba(227, 6, 19, 0.82), rgba(227, 6, 19, 0.4));
          box-shadow: 0 0 0 1px rgba(227, 6, 19, 0.08);
        }

        .admin-page-shell-resizer:hover::after,
        .admin-page-shell-resizer.is-dragging::after {
          transform: scale(1.04);
          border-color: rgba(227, 6, 19, 0.44);
          background: #ffffff;
          box-shadow: 0 14px 32px rgba(227, 6, 19, 0.18);
          color: #e30613;
        }
      }

      @media (max-width: 1279px) {
        .admin-page .admin-mobile-menu-toggle {
          display: inline-flex;
          width: auto;
          min-width: 52px;
          min-height: 52px;
          margin-left: auto;
          padding-inline: 14px;
          justify-content: center;
          gap: 0;
          border-radius: 16px;
        }

        .admin-page .admin-mobile-menu-toggle-copy {
          display: none;
        }

        .admin-page .admin-mobile-menu-toggle-icon {
          font-size: 24px;
        }

        .admin-page .admin-page-sidebar {
          display: none;
        }

        .admin-page .admin-page-shell.is-mobile-menu-open .admin-page-sidebar {
          display: block;
        }

        .admin-page .admin-page-shell.is-mobile-menu-open .admin-page-sidebar .admin-shell-panel {
          margin-bottom: 0 !important;
        }

        .admin-page .admin-page-shell.is-mobile-menu-open .admin-mobile-menu-toggle {
          border-color: rgba(227, 6, 19, 0.32);
          box-shadow: 0 0 0 4px rgba(227, 6, 19, 0.08);
        }

        .admin-page .admin-page-sidebar-mobile-head {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 12px;
          padding-bottom: 14px;
          margin-bottom: 14px;
          border-bottom: 1px solid rgba(40, 50, 70, 0.16);
        }

        .admin-page .admin-page-sidebar-mobile-copy {
          min-width: 0;
          display: grid;
          gap: 4px;
        }

        .admin-page .admin-page-sidebar-mobile-title {
          margin: 0;
          font-size: 18px;
          line-height: 1.2;
        }

        .admin-page .admin-page-sidebar-mobile-close {
          flex: 0 0 auto;
        }
      }

      @media (max-width: 1279px) {
        .admin-page .container {
          width: min(100%, calc(100vw - 16px)) !important;
          padding-inline: 0;
        }

        .admin-page-main {
          gap: 14px;
        }

        .admin-shell-panel {
          padding: 18px;
        }

        .admin-page-shell-resizer {
          display: none !important;
        }
      }

      html[data-theme="dark"] .admin-shell-panel {
        background:
          linear-gradient(180deg, rgba(227, 6, 19, 0.08) 0%, rgba(227, 6, 19, 0) 220px),
          linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0)),
          #141a24;
        box-shadow: 0 26px 54px rgba(0, 0, 0, 0.3);
      }

      html[data-theme="dark"] .admin-shell-intro {
        border-bottom-color: rgba(255, 255, 255, 0.08);
      }

      html[data-theme="dark"] .admin-content-frame-head {
        border-bottom-color: rgba(255, 255, 255, 0.08);
      }

      html[data-theme="dark"] .admin-action-ticker {
        border-color: rgba(255, 255, 255, 0.08);
        background: rgba(255, 255, 255, 0.04);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] .admin-action-ticker-label {
        color: #a9b3c3;
      }

      html[data-theme="dark"] .admin-side-group {
        border-color: rgba(255, 255, 255, 0.08);
        background: rgba(11, 13, 16, 0.24);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] .admin-anchor-btn.is-active-anchor {
        border-color: rgba(255, 237, 0, 0.24);
        color: #ffed00;
        background: rgba(255, 237, 0, 0.08);
      }

      html[data-theme="dark"] .admin-page {
        background:
          radial-gradient(circle at top center, rgba(227, 6, 19, 0.12), transparent 42%),
          linear-gradient(180deg, #0b0d10 0%, #0f141d 100%);
      }

      html[data-theme="dark"] .admin-page-main > .panel,
      html[data-theme="dark"] .admin-content-scroll > .panel {
        border-color: rgba(255, 255, 255, 0.06);
        background:
          linear-gradient(180deg, rgba(20, 26, 36, 0.98), rgba(17, 22, 31, 0.98)),
          #141a24;
        box-shadow:
          0 24px 48px rgba(0, 0, 0, 0.28),
          0 4px 16px rgba(0, 0, 0, 0.2);
      }

      html[data-theme="dark"] .admin-page-main .table-wrap {
        border-color: rgba(255, 255, 255, 0.08);
        background: rgba(11, 13, 16, 0.34);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] .admin-page-main .panel .panel {
        background: rgba(11, 13, 16, 0.28);
      }

      html[data-theme="dark"] .admin-page-main .alert {
        box-shadow: 0 16px 32px rgba(0, 0, 0, 0.22);
      }

      html[data-theme="dark"] #admin_content_scroll {
        --text: #e6e9ef;
        --panel: #141a24;
        --border: rgba(255, 255, 255, 0.08);
        --muted: #a9b3c3;
        --focus: rgba(255, 237, 0, 0.18);
        --dlrg-schwarz: #2f3845;
        color: var(--text);
      }

      html[data-theme="dark"] #admin_internal_frame > .panel,
      html[data-theme="dark"] #admin_internal_frame > .alert,
      html[data-theme="dark"] #admin_content_scroll .modal-panel {
        color: var(--text);
        border-color: var(--border);
        background: #141a24;
        box-shadow: none;
      }

      html[data-theme="dark"] #admin_content_scroll input[type="text"],
      html[data-theme="dark"] #admin_content_scroll input[type="password"],
      html[data-theme="dark"] #admin_content_scroll input[type="number"],
      html[data-theme="dark"] #admin_content_scroll input[type="datetime-local"],
      html[data-theme="dark"] #admin_content_scroll input[type="email"],
      html[data-theme="dark"] #admin_content_scroll select {
        background: #0f131a;
        color: var(--text);
        border-color: var(--border);
      }

      html[data-theme="dark"] #admin_content_scroll .table-wrap {
        border-color: var(--border);
        background: rgba(11, 13, 16, 0.34);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] #admin_content_scroll .panel .panel {
        background: rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] #admin_content_scroll tr:nth-child(even) td {
        background: rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] #admin_content_scroll .participant-actions-cell {
        background: linear-gradient(180deg, #141a24 0%, #111720 100%);
        border-left-color: rgba(255, 255, 255, 0.08);
        box-shadow: inset 1px 0 0 rgba(255, 255, 255, 0.04), -8px 0 14px rgba(0, 0, 0, 0.18);
      }

      html[data-theme="dark"] #admin_content_scroll tr:nth-child(even) .participant-actions-cell {
        background: linear-gradient(180deg, #18212d 0%, #121a24 100%);
      }

      html[data-theme="dark"] #admin_content_scroll .participant-actions-wrap .btn.btn-secondary {
        background: #243447;
        border-color: rgba(255, 255, 255, 0.08);
        color: #e6e9ef;
      }

      html[data-theme="dark"] #admin_content_scroll .participant-actions-wrap .btn.btn-secondary:hover {
        background: #2d4157;
      }

      html[data-theme="dark"] #admin_content_scroll .users-action-btn.btn.btn-secondary {
        background: #243447;
        border-color: rgba(255, 255, 255, 0.08);
        color: #e6e9ef;
      }

      html[data-theme="dark"] #admin_content_scroll .users-action-btn.btn.btn-secondary:hover {
        background: #2d4157;
      }

      html[data-theme="dark"] #admin_content_scroll .participant-paid-action.btn.btn-secondary,
      html[data-theme="dark"] #admin_content_scroll .participant-paid-status {
        background: #243447;
        border-color: rgba(255, 255, 255, 0.08);
      }

      html[data-theme="dark"] #admin_content_scroll .participant-paid-action.btn.btn-secondary:hover {
        background: #2d4157;
      }

      html[data-theme="dark"] .admin-content-iframe {
        border-color: rgba(255, 255, 255, 0.08);
        background: #141a24;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      html[data-theme="dark"] .admin-side-block + .admin-side-block {
        border-top-color: rgba(255, 255, 255, 0.08);
      }

      html[data-theme="dark"] .admin-page-shell-resizer::after {
        border-color: rgba(255, 255, 255, 0.1);
        background: #141a24;
        color: var(--text);
      }

      .participant-paid-head,
      .participant-paid-cell {
        width: 108px;
        min-width: 108px;
        text-align: center;
      }

      .participant-paid-cell {
        vertical-align: middle;
      }

      .participant-paid-action {
        display: inline-flex;
        width: 48px;
        min-width: 48px;
        height: 40px;
        padding: 0;
        justify-content: center;
        align-items: center;
        white-space: nowrap;
      }

      .participant-paid-status {
        display: inline-flex;
        width: 48px;
        min-width: 48px;
        height: 40px;
        padding: 0;
        justify-content: center;
        align-items: center;
        border: 1px solid rgba(40, 50, 70, 0.14);
        border-radius: 10px;
        background: #eef2f7;
        box-sizing: border-box;
      }

      .participant-paid-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        line-height: 1;
        font-weight: 700;
      }

      .participant-paid-icon.is-paid {
        color: #1c9b51;
      }

      .participant-paid-icon.is-unpaid {
        color: #d62d45;
      }

      .participant-actions-head,
      .participant-actions-cell {
        width: 132px;
        min-width: 132px;
        text-align: center;
      }

      .participant-actions-head {
        background: #ff1a12;
        color: #ffffff;
        box-shadow: none;
      }

      .participant-actions-cell {
        vertical-align: middle;
      }

      .participant-actions-wrap {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(48px, 1fr);
        justify-content: flex-end;
        align-items: center;
        gap: 8px;
        min-width: 0;
      }

      .participant-actions-wrap .btn {
        min-width: 48px;
        width: 48px;
        height: 40px;
        padding: 0;
        justify-content: center;
        align-items: center;
      }

      .participant-action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        line-height: 1;
      }

      .users-table td {
        vertical-align: middle;
      }

      .users-col-id {
        width: 84px;
      }

      .users-col-name {
        width: 220px;
      }

      .users-col-role,
      .users-col-password {
        width: 260px;
      }

      .users-col-delete {
        width: 160px;
      }

      .users-inline-form,
      .users-delete-form {
        display: grid;
        gap: 8px;
        align-items: center;
      }

      .users-inline-form select,
      .users-inline-form input[type="password"],
      .users-delete-form .btn {
        width: 100%;
      }

      .users-action-btn {
        width: 100%;
        min-height: 40px;
        justify-content: center;
        gap: 8px;
        white-space: nowrap;
      }

      .users-action-btn .participant-action-icon {
        font-size: 16px;
      }

      .users-create-action {
        justify-content: flex-start;
      }

      @media (max-width: 700px) {
        .admin-shell-top {
          flex-direction: column;
          align-items: stretch;
        }

        .admin-session-info,
        .admin-session-actions {
          width: 100%;
        }

        .admin-session-info {
          display: block;
        }

        .admin-session-actions {
          margin-left: 0;
          justify-content: stretch;
        }

        .admin-session-actions > * {
          flex: 1 1 auto;
          width: 100%;
        }
      }
    </style>
  </head>
  <body class="admin-page">
    <div class="page">
      <div class="container" style="width: 100%;">
        <header class="reg-header">
          <!-- @doc-note Das DLRG-Logo sitzt im Kopfbereich oberhalb des Admin-Titels. -->
          <img src="<?php echo escape($assetBase . '/assets/logo.png'); ?>" alt="DLRG Sankt Augustin e.V." class="reg-logo" width="1714" height="332" decoding="async" />
          <h1 class="reg-title">
            <span>Admin</span>
            <span>24‑Stunden‑Schwimmen</span>
            <span>Auswertung &amp; Export</span>
          </h1>
        </header>

        <?php if (!$is_logged_in): ?>
          <div class="panel">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
              <h2 style="margin:0;">Login</h2>
              <button type="button" class="btn btn-secondary" id="theme_toggle" aria-pressed="false">
                <span class="admin-session-action-icon" aria-hidden="true">&#9790;</span>
                <span class="admin-session-action-label">Dunkelmodus</span>
              </button>
            </div>
            <p class="muted" style="margin-top:0;">
              Zugriff nur für berechtigte Personen.
            </p>

            <div class="<?php echo $login_mode_alert_class; ?>">
              <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <div><strong>Modus:</strong> <?php echo escape($login_mode_label); ?></div>
                <div><strong>Basic‑Auth:</strong> <?php echo $basic_auth_detected ? 'erkannt' : 'nicht erkannt'; ?></div>
              </div>
              <?php if ($login_mode_message !== ''): ?>
                <div style="margin-top:6px;"><?php echo escape($login_mode_message); ?></div>
              <?php endif; ?>
            </div>

            <?php if ($login_error !== ''): ?>
              <div class="alert alert-error">
                <strong>Fehler:</strong> <?php echo escape($login_error); ?>
              </div>
            <?php endif; ?>
            <?php if ($login_notice !== ''): ?>
              <div class="alert alert-success">
                <strong>Info:</strong> <?php echo escape($login_notice); ?>
              </div>
            <?php endif; ?>

            <form class="reg-form" method="post" action="admin.php">
              <div class="field">
                <label for="login_username">Benutzername</label>
                <input id="login_username" name="login_username" type="text" <?php echo $allow_setup_password ? '' : 'required'; ?> autofocus />
              </div>
              <div class="field">
                <label for="login_password">Passwort</label>
                <input id="login_password" name="login_password" type="password" <?php echo $allow_setup_password ? '' : 'required'; ?> />
              </div>
              <?php if ($allow_setup_password): ?>
                <div class="field">
                  <label for="password">Recovery‑Passwort (optional)</label>
                  <input id="password" name="password" type="password" />
                </div>
              <?php endif; ?>
              <div class="actions">
                <button class="btn btn-wide" type="submit">Einloggen</button>
              </div>
            </form>
          </div>
        <?php else: ?>
          <?php
          // @doc-section admin-shell
          // @doc-purpose Rendert den oberen Admin-Rahmen mit Session-Info, Schnellzugriffen, Suche und Bereichsnavigation.
          // @doc-ui-flow admin-shell
          // @doc-ui-step 1 Nach dem Login sieht der Nutzer zuerst den kompakten Steuerbereich oberhalb der Admin-Panels.
          // @doc-ui-step 2 Suche, Kasse, externe Schnellzugriffe und interne Bereichswechsel sind in getrennten Zonen gruppiert.
          // @doc-ui-step 3 In Mobilansicht klappt ein kompaktes Menue die komplette linke Sidebar bis einschliesslich Sitzung bei Bedarf ein.
          // @doc-ui-result Die Admin-Oberflaeche bleibt trotz vieler Aktionen visuell konsistent und schneller bedienbar.
          ?>
          <div class="page-shell admin-page-shell" id="admin_page_shell" data-admin-shell-breakpoint="1280">
            <button
              type="button"
              class="btn btn-secondary admin-mobile-menu-toggle"
              id="admin_mobile_menu_toggle"
              aria-controls="admin_page_sidebar"
              aria-expanded="false"
              aria-label="Admin-Men&uuml; &ouml;ffnen"
            >
              <span class="admin-mobile-menu-toggle-copy">
                <span class="admin-mobile-menu-toggle-kicker">Schnellzugriff</span>
                <span class="admin-mobile-menu-toggle-label">Admin-Men&uuml; &ouml;ffnen</span>
              </span>
              <span class="admin-mobile-menu-toggle-icon" aria-hidden="true">&#8942;</span>
            </button>
            <aside class="page-sidebar admin-page-sidebar" id="admin_page_sidebar">
              <div class="panel admin-shell admin-shell-panel" style="margin-bottom: 15px;">
                <div class="admin-page-sidebar-mobile-head">
                  <div class="admin-page-sidebar-mobile-copy">
                    <p class="sidebar-label">Mobil-Menue</p>
                    <h2 class="admin-page-sidebar-mobile-title">Navigation und Sitzung</h2>
                  </div>
                  <button type="button" class="btn btn-secondary admin-page-sidebar-mobile-close" id="admin_mobile_menu_close">Schliessen</button>
                </div>
                <div class="admin-shell-intro">
                  <p class="sidebar-label">Admin-Panel</p>
                </div>

                <div class="admin-side-list">
                  <section class="admin-side-group">
                    <div class="admin-side-group-head">
                      <p class="sidebar-label">Anchor pages</p>
                    </div>
                    <nav class="admin-nav" aria-label="Unterseiten">
                      <button type="button" class="btn btn-secondary admin-nav-btn" data-admin-target="participants_list" aria-controls="participants_list">Teilnehmerliste</button>
                      <button type="button" class="btn btn-secondary admin-nav-btn" data-admin-target="profile" aria-controls="profile">Mein Profil bearbeiten</button>
                      <?php if ($can_create_participant): ?>
                        <button type="button" class="btn btn-secondary admin-nav-btn" data-admin-target="participants_create" aria-controls="participants_create">Teilnehmer anlegen</button>
                      <?php endif; ?>
                      <?php if ($can_manage_users): ?>
                        <button type="button" class="btn btn-secondary admin-nav-btn" data-admin-target="users" aria-controls="users">Nutzerverwaltung</button>
                      <?php endif; ?>
                      <?php if ($can_manage_teams): ?>
                        <button type="button" class="btn btn-secondary admin-nav-btn" data-admin-target="teams_manage" aria-controls="teams_manage">Teams</button>
                      <?php endif; ?>
                      <?php if ($can_admin_pages): ?>
                        <button type="button" class="btn btn-secondary admin-nav-btn" data-admin-target="audit" aria-controls="audit">Ereignisprotokoll</button>
                      <?php endif; ?>
                    </nav>
                  </section>

                  <section class="admin-side-group">
                    <div class="admin-side-group-head">
                      <p class="sidebar-label">In neuem Tab &ouml;ffnen:</p>
                    </div>
                    <div class="admin-links-grid">
                      <?php if ($can_dashboard_page): ?>
                        <a class="btn btn-secondary admin-link-btn" href="dashboard/dashboard.php" target="_blank" rel="noopener noreferrer" data-admin-frame-url="dashboard/dashboard.php" data-admin-frame-title="Dashboard">Dashboard</a>
                        <a class="btn btn-secondary admin-link-btn" href="eventlifkiosk.php?standalone=1" target="_blank" rel="noopener noreferrer" data-admin-frame-url="eventlifkiosk.php" data-admin-frame-title="Event Live Kiosk">Event Kiosk</a>
                      <?php endif; ?>
                      <?php if ($can_numbers_page): ?>
                        <a class="btn btn-secondary admin-link-btn" href="numbers.php?standalone=1" target="_blank" rel="noopener noreferrer" data-admin-frame-url="numbers.php" data-admin-frame-title="Startnummern">Startnummern</a>
                      <?php endif; ?>
                      <?php if ($can_manage_timeframes): ?>
                        <a class="btn btn-secondary admin-link-btn" href="timeframes.php?standalone=1" target="_blank" rel="noopener noreferrer" data-admin-frame-url="timeframes.php" data-admin-frame-title="Zeitfenster">Zeitfenster</a>
                      <?php endif; ?>
                      <?php if ($can_export_participants): ?>
                        <a class="btn btn-secondary admin-link-btn" href="export.php?standalone=1" target="_blank" rel="noopener noreferrer" data-admin-frame-url="export.php" data-admin-frame-title="Datenexport">Datenexport</a>
                      <?php endif; ?>
                      <?php if ($can_edit_participants): ?>
                        <a class="btn btn-secondary admin-link-btn" href="sync.php?standalone=1" target="_blank" rel="noopener noreferrer" data-admin-frame-url="sync.php" data-admin-frame-title="Teilnehmer-Sync">Teilnehmer-Sync</a>
                      <?php endif; ?>
                    </div>
                  </section>

                  <section class="admin-side-group">
                    <div class="admin-side-group-head">
                      <p class="sidebar-label">Tabelle filtern:</p>
                    </div>
                    <div class="admin-controls-grid">
                      <div class="field">
                        <input id="table_search" type="text" placeholder="z.B. Name, Team, ID …" />
                      </div>
                      <div class="field">
                        <p class="sidebar-label">Token eingeben:</p>
                        <form id="cash_form" class="admin-search-form" method="get" action="admin.php">
                          <input id="cash_code" name="code" type="text" placeholder="z.B. ABCD‑EFGH‑JK" autocomplete="off" />
                          <div class="admin-search-actions">
                            <button class="btn btn-secondary" type="submit">Suchen</button>
                            <?php if ($can_pay): ?>
                              <button id="btn_scan_qr" class="btn btn-primary" type="button">QR Scannen</button>
                            <?php endif; ?>
                          </div>
                        </form>
                      </div>
                    </div>
                  </section>

                  <section class="admin-side-group">
                    <div class="admin-side-group-head">
                      <p class="sidebar-label">Sitzung</p>
                      <h3 class="admin-side-title">Konto und Anzeige</h3>
                    </div>
                    <?php // @doc-note Die Sitzungskarte fasst Nutzerstatus und Schnellaktionen im gleichen Raster wie die restliche Sidebar zusammen. ?>
                    <div class="hint admin-session-info">
                      <div class="admin-session-main">
                        <div class="admin-session-meta">
                          <span class="admin-session-kicker">Sitzung aktiv</span>
                          <strong class="admin-session-title">Konto und Anzeige</strong>
                        </div>
                        <span class="admin-session-avatar" aria-hidden="true">&#128100;</span>
                      </div>
                      <div class="admin-session-facts">
                        <div class="admin-session-fact">
                          <span class="admin-session-fact-label">Angemeldet als</span>
                          <span class="admin-session-fact-value"><?php echo escape((string)($_SESSION['username'] ?? ($current_user['username'] ?? ''))); ?></span>
                        </div>
                        <div class="admin-session-fact">
                          <span class="admin-session-fact-label">Rolle</span>
                          <span class="admin-session-badge">
                            <span class="admin-session-badge-icon" aria-hidden="true">&#128274;</span>
                            <span><?php echo escape($role); ?></span>
                          </span>
                        </div>
                      </div>
                      <?php if ($setup_mode): ?>
                        <div class="admin-session-note is-warn">
                          <span class="admin-session-badge-icon" aria-hidden="true">&#9888;</span>
                          <span>Setup-Modus aktiv</span>
                        </div>
                      <?php elseif ($users_enabled && $allow_setup_password): ?>
                        <div class="admin-session-note is-warn">
                          <span class="admin-session-badge-icon" aria-hidden="true">&#9888;</span>
                          <span>Recovery-Modus aktiv</span>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="admin-session-actions">
                      <button type="button" class="btn btn-secondary" id="theme_toggle" aria-pressed="false">
                        <span class="admin-session-action-icon" aria-hidden="true">&#9790;</span>
                        <span class="admin-session-action-label">Dunkelmodus</span>
                      </button>
                      <form method="post" action="admin.php" style="display:inline;">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>">
                        <button type="submit" class="btn btn-secondary">
                          <span class="admin-session-action-icon" aria-hidden="true">&#8618;</span>
                          <span class="admin-session-action-label">Abmelden</span>
                        </button>
                      </form>
                    </div>
                  </section>
                </div>
              </div>
            </aside>

            <button
              type="button"
              class="admin-page-shell-resizer"
              id="admin_page_shell_resizer"
              aria-label="Breite der linken Navigation anpassen"
              title="Breite der linken Navigation anpassen"
            ></button>

            <div class="page-main admin-page-main">
              <section class="panel admin-shell-panel admin-content-frame" id="admin_content_frame">
                <div class="admin-content-frame-head">
                  <div class="admin-action-ticker" aria-live="polite" aria-atomic="true">
                    <p class="admin-action-ticker-label">Aktivitäten</p>
                    <div class="admin-action-ticker-viewport">
                      <span class="admin-action-ticker-text" id="admin_action_ticker_text">Bereit.</span>
                    </div>
                  </div>
                </div>
                <div class="admin-content-frame-body">
                  <div class="admin-content-scroll" id="admin_content_scroll">
                    <div id="admin_internal_frame">
          <?php if ($setup_mode): ?>
            <div class="alert alert-error no-autohide" style="margin-bottom:12px;">
              <strong>Setup-Modus:</strong> Es sind keine Nutzer angelegt. Vollzugriff ist aktiv, bis der erste Nutzer erstellt wurde.
            </div>
          <?php endif; ?>

          <?php if ($users_enabled && !$setup_mode && $allow_setup_password): ?>
            <div class="alert alert-error no-autohide" style="margin-bottom:12px;">
              <strong>Recovery‑Modus:</strong> Es ist kein Admin vorhanden. Bitte einen Admin anlegen, damit der reguläre Login wieder aktiv ist.
            </div>
          <?php endif; ?>

          <div id="profile" class="panel admin-section admin-anchor-target" data-admin-section-title="Profil" style="margin-bottom:12px;" hidden>
            <h2 style="margin:0 0 10px 0;">Profil</h2>

            <?php if ($profile_error !== ''): ?>
              <div class="alert alert-error"><strong>Fehler:</strong> <?php echo escape($profile_error); ?></div>
            <?php endif; ?>
            <?php if ($profile_success !== ''): ?>
              <div class="alert alert-success"><strong>Erfolg:</strong> <?php echo escape($profile_success); ?></div>
            <?php endif; ?>

            <div class="grid" style="align-items:flex-end;">
              <div class="field">
                <label>Benutzer</label>
                <input type="text" value="<?php echo escape((string)($_SESSION['username'] ?? ($current_user['username'] ?? ''))); ?>" disabled />
              </div>
              <div class="field">
                <label>Rolle</label>
                <input type="text" value="<?php echo escape($role); ?>" disabled />
              </div>
            </div>

            <div class="panel admin-anchor-target" id="profile_password" data-admin-anchor-label="Passwort ändern" style="margin-top:12px;">
              <h3 style="margin:0 0 10px 0;">Passwort ändern</h3>
              <?php if (!$can_change_own_password): ?>
                <div class="hint">Passwortänderung ist nur für DB‑Benutzer verfügbar (Login per Benutzername/Passwort).</div>
              <?php else: ?>
                <form class="reg-form" method="post" action="admin.php#profile" style="margin-top:12px;">
                  <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                  <input type="hidden" name="profile_action" value="change_password" />
                  <div class="grid">
                    <div class="field">
                      <label for="profile_current_password">Aktuelles Passwort</label>
                      <input id="profile_current_password" name="current_password" type="password" required />
                    </div>
                    <div class="field">
                      <label for="profile_new_password">Neues Passwort</label>
                      <input id="profile_new_password" name="new_password" type="password" required minlength="8" />
                    </div>
                    <div class="field">
                      <label for="profile_new_password_confirm">Neues Passwort (Wiederholung)</label>
                      <input id="profile_new_password_confirm" name="new_password_confirm" type="password" required minlength="8" />
                    </div>
                  </div>
                  <div class="actions">
                    <button class="btn btn-primary" type="submit">Passwort ändern</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($can_manage_users): ?>
            <div id="users" class="panel admin-section admin-anchor-target" data-admin-section-title="Nutzerverwaltung" style="margin-bottom:12px;" hidden>
              <h2 style="margin:0 0 10px 0;">Nutzerverwaltung</h2>

              <?php if ($user_error !== ''): ?>
                <div class="alert alert-error"><strong>Fehler:</strong> <?php echo escape($user_error); ?></div>
              <?php endif; ?>
              <?php if ($user_success !== ''): ?>
                <div class="alert alert-success"><strong>Erfolg:</strong> <?php echo escape($user_success); ?></div>
              <?php endif; ?>

              <form class="reg-form admin-anchor-target" id="users_create" data-admin-anchor-label="Benutzer anlegen" method="post" action="admin.php#users" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                <input type="hidden" name="user_action" value="create_user" />
                <div class="grid users-grid">
                  <div class="field">
                    <label>Benutzername</label>
                    <input type="text" name="new_username" required />
                  </div>
                  <div class="field">
                    <label>Passwort</label>
                    <input type="password" name="new_password" required />
                  </div>
                  <div class="field">
                    <label>Rolle</label>
                    <select name="new_role">
                      <option value="admin" <?php echo ($setup_mode && $admin_count === 0) ? 'selected' : ''; ?> <?php echo ($admin_count >= 1) ? 'disabled' : ''; ?>>Admin</option>
                      <option value="cashier">Kasse</option>
                      <option value="orga">Orga/Bahn</option>
                      <option value="viewer">Nur Lesen</option>
                    </select>
                  </div>
                </div>
                <div class="actions">
                  <button class="btn btn-primary users-action-btn users-create-action" type="submit">
                    <span class="participant-action-icon" aria-hidden="true">&#10133;</span>
                    <span>Nutzer anlegen</span>
                  </button>
                </div>
              </form>

              <?php if (count($users) > 0): ?>
                <div class="table-wrap admin-anchor-target" id="users_table_anchor" data-admin-anchor-label="Benutzerliste" style="margin-top:12px;">
                  <table class="data-table users-table" id="users_table">
                    <colgroup>
                      <col class="users-col-id">
                      <col class="users-col-name">
                      <col class="users-col-role">
                      <col class="users-col-password">
                      <col class="users-col-delete">
                    </colgroup>
                    <thead>
                      <tr>
                        <th data-col-key="user_id">ID</th>
                        <th data-col-key="user_username">Benutzername</th>
                        <th data-col-key="user_role" data-no-resize="true">Rolle</th>
                        <th data-col-key="user_password_reset" data-no-resize="true">Passwort neu setzen</th>
                        <th data-col-key="user_delete" data-no-resize="true">Löschen</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($users as $u): ?>
                        <tr>
                          <td><?php echo escape((string)$u['id']); ?></td>
                          <td><?php echo escape((string)$u['username']); ?></td>
                          <td>
                            <form method="post" action="admin.php#users" class="users-inline-form">
                              <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                              <input type="hidden" name="user_action" value="update_role" />
                              <input type="hidden" name="user_id" value="<?php echo escape((string)$u['id']); ?>" />
                              <select name="role">
                                <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?> <?php echo ($u['role'] !== 'admin' && $admin_count >= 1) ? 'disabled' : ''; ?>>Admin</option>
                                <option value="cashier" <?php echo $u['role'] === 'cashier' ? 'selected' : ''; ?>>Kasse</option>
                                <option value="orga" <?php echo $u['role'] === 'orga' ? 'selected' : ''; ?>>Orga/Bahn</option>
                                <option value="viewer" <?php echo $u['role'] === 'viewer' ? 'selected' : ''; ?>>Nur Lesen</option>
                              </select>
                              <button class="btn btn-secondary users-action-btn" type="submit">
                                <span class="participant-action-icon" aria-hidden="true">&#128190;</span>
                              </button>
                            </form>
                          </td>
                          <td>
                            <form method="post" action="admin.php#users" class="users-inline-form">
                              <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                              <input type="hidden" name="user_action" value="update_password" />
                              <input type="hidden" name="user_id" value="<?php echo escape((string)$u['id']); ?>" />
                              <input type="password" name="password" placeholder="Neues Passwort" autocomplete="new-password" required />
                              <button class="btn btn-primary users-action-btn" type="submit">
                                <span class="participant-action-icon" aria-hidden="true">&#128273;</span>
                              </button>
                            </form>
                          </td>
                          <td>
                            <form method="post" action="admin.php#users" class="users-delete-form">
                              <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                              <input type="hidden" name="user_action" value="delete_user" />
                              <input type="hidden" name="user_id" value="<?php echo escape((string)$u['id']); ?>" />
                              <button class="btn btn-yellow users-action-btn" type="submit" onclick="return confirm('Nutzer wirklich löschen?')">
                                <span class="participant-action-icon" aria-hidden="true">&#128465;</span>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($can_manage_teams): ?>
            <div id="teams_manage" class="panel admin-section admin-anchor-target" data-admin-section-title="Teams" style="margin-bottom:12px;" hidden>
              <h2 style="margin:0 0 10px 0;">Teams</h2>

              <?php if ($team_error !== ''): ?>
                <div class="alert alert-error"><strong>Fehler:</strong> <?php echo escape($team_error); ?></div>
              <?php endif; ?>
              <?php if ($team_success !== ''): ?>
                <div class="alert alert-success"><strong>Erfolg:</strong> <?php echo escape($team_success); ?></div>
              <?php endif; ?>

              <form class="reg-form admin-anchor-target" id="teams_create" data-admin-anchor-label="Team anlegen" method="post" action="admin.php#teams_manage" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                <input type="hidden" name="team_action" value="create_team" />
                <div class="grid">
                  <div class="field" style="grid-column: 1 / -1;">
                    <label for="team_create_name">Neues Team anlegen</label>
                    <div style="display:flex; gap:10px; align-items:center; width:100%;">
                      <input id="team_create_name" name="team_name" type="text" placeholder="Teamname" style="flex:1;" />
                      <button class="btn btn-primary" type="submit" style="white-space: nowrap;">Team anlegen</button>
                    </div>
                  </div>
                </div>
              </form>

              <?php if (count($teams_overview) > 0): ?>
                <div class="table-wrap admin-anchor-target" id="teams_table_anchor" data-admin-anchor-label="Teamübersicht" style="margin-top:12px;">
                  <table class="data-table" id="teams_table">
                    <thead>
                      <tr>
                        <th data-col-key="team_name">Team</th>
                        <th data-col-key="team_members">Mitglieder</th>
                        <th data-col-key="team_actions" data-no-resize="true">Aktion</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($teams_overview as $t): ?>
                        <tr>
                          <td><?php echo escape((string)$t['name']); ?></td>
                          <td><?php echo escape((string)$t['members']); ?></td>
                          <td>
                            <div class="participant-actions-wrap">
                              <button
                                type="button"
                                class="btn btn-small btn-secondary btn-team-manage"
                                data-team="<?php echo escape((string)$t['name']); ?>"
                                aria-label="Mitglieder verwalten"
                                title="Mitglieder verwalten"
                              ><span class="participant-action-icon" aria-hidden="true">&#128101;</span></button>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="hint" style="margin-top:12px;">Noch keine Teams vorhanden.</div>
              <?php endif; ?>
            </div>

            <div id="modal_team_manage" class="modal-overlay" aria-hidden="true">
              <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="team_manage_title" tabindex="-1" style="max-width: 720px;">
                <div class="modal-header">
                  <h2 class="modal-title" id="team_manage_title">Team: <span id="team_manage_name"></span></h2>
                  <button class="modal-close" type="button" data-modal-close>Schließen</button>
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                  <button id="team_manage_rename" type="button" class="btn btn-secondary">Umbenennen</button>
                  <button id="team_manage_delete" type="button" class="btn btn-secondary">Löschen</button>
                </div>

                <div id="team_manage_msg" style="margin-top:12px;"></div>

                <div class="grid" style="margin-top:12px;">
                  <div class="field" style="grid-column: 1 / -1;">
                    <label for="team_unassigned_select">Teilnehmer ohne Team</label>
                    <div style="display:flex; gap:10px; width:100%;">
                      <select id="team_unassigned_select" style="flex:1;">
                        <option value="" selected>— Laden… —</option>
                      </select>
                      <button id="team_unassigned_add" type="button" class="btn btn-primary" style="white-space:nowrap;">Hinzufügen</button>
                    </div>
                  </div>
                  <div class="field" style="grid-column: 1 / -1;">
                    <label for="team_member_search">Teilnehmer suchen</label>
                    <input id="team_member_search" type="text" placeholder="Name oder Code…" autocomplete="off" />
                  </div>
                </div>

                <div class="grid" style="margin-top:12px;">
                  <div class="field">
                    <label>Mitglieder im Team</label>
                    <div id="team_members_list" class="scrollbox" style="max-height: 240px;"></div>
                  </div>
                  <div class="field">
                    <label>Suchergebnisse</label>
                    <div id="team_search_list" class="scrollbox" style="max-height: 240px;"></div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($can_create_participant): ?>
            <div id="participants_create" class="panel admin-section admin-anchor-target" data-admin-section-title="Teilnehmer anlegen" style="margin-bottom:12px;" hidden>
              <h2 style="margin:0 0 10px 0;">Teilnehmer anlegen</h2>

              <?php if ($participant_error !== ''): ?>
                <div class="alert alert-error"><strong>Fehler:</strong> <?php echo escape($participant_error); ?></div>
              <?php endif; ?>
              <?php if ($participant_success !== ''): ?>
                <div class="alert alert-success"><strong>Erfolg:</strong> <?php echo escape($participant_success); ?></div>
              <?php endif; ?>

              <form class="reg-form" method="post" action="admin.php">
                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                <input type="hidden" name="participant_action" value="create" />
                <div class="grid admin-anchor-target" id="participants_create_masterdata" data-admin-anchor-label="Stammdaten">
                  <div class="field">
                    <label for="p_vorname">Vorname *</label>
                    <input id="p_vorname" name="vorname" type="text" required />
                  </div>
                  <div class="field">
                    <label for="p_nachname">Nachname *</label>
                    <input id="p_nachname" name="nachname" type="text" required />
                  </div>
                  <div class="field">
                    <label>Volljährig *</label>
                    <div class="radio-row">
                      <input id="p_voll_1" type="radio" name="volljaehrig" value="1" required />
                      <label for="p_voll_1">Ja</label>
                    </div>
                    <div class="radio-row">
                      <input id="p_voll_0" type="radio" name="volljaehrig" value="0" required />
                      <label for="p_voll_0">Nein</label>
                    </div>
                  </div>
                  <div class="field">
                    <label for="p_geschlecht">Geschlecht</label>
                    <select id="p_geschlecht" name="geschlecht" required>
                      <option value="" selected disabled hidden>Bitte auswählen</option>
                      <option value="Männlich">Männlich</option>
                      <option value="Weiblich">Weiblich</option>
                      <option value="Divers">Divers</option>
                    </select>
                  </div>
                  <div class="field" style="grid-column:1 / -1;">
                    <label for="team_select">Team</label>
                    <select id="team_select" name="team_select">
                      <option value="" selected>— Kein Team (Einzelkämpfer) —</option>
                      <?php foreach ($teams as $t): ?>
                        <option value="<?php echo escape((string)$t['id']); ?>"><?php echo escape((string)$t['name']); ?></option>
                      <?php endforeach; ?>
                      <option value="__NEW__">[ + Neues Team erstellen ]</option>
                    </select>

                    <div id="new_team_wrapper" class="field">
                      <label for="new_team_input">Neuer Teamname *</label>
                      <input id="new_team_input" name="new_team" type="text" />
                    </div>
                  </div>
                </div>

                <div class="actions">
                  <button class="btn btn-primary" type="submit">Teilnehmer anlegen</button>
                </div>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($can_pay): ?>
            <div id="qr_scanner_container" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="qr_scanner_title">
              <div class="modal-panel" style="max-width: 560px;">
                <div class="modal-header">
                  <h2 id="qr_scanner_title" class="modal-title">QR Code scannen</h2>
                  <button id="btn_close_scan" type="button" class="modal-close" aria-label="Schließen">&times;</button>
                </div>
                <div style="margin-top: 16px;">
                  <p class="hint" style="margin: 0 0 12px 0;">Auf Mobilgeraeten oeffnet der Scanner jetzt direkt als Overlay und nutzt bevorzugt die Rueckkamera.</p>
                  <div id="qr_reader" style="width: 100%; max-width: 500px; min-height: 280px; margin: 0 auto;"></div>
                  <div style="text-align: center; margin-top: 12px;">
                    <button id="btn_stop_scan" type="button" class="btn btn-secondary">Abbrechen</button>
                  </div>
                </div>
              </div>
            </div>

            <div id="modalAssignNum" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
              <div class="modal-panel" style="max-width: 500px;">
                <div class="modal-header">
                  <h2 class="modal-title">Startnummer zuweisen</h2>
                  <button type="button" class="modal-close" onclick="document.getElementById('modalAssignNum').setAttribute('aria-hidden', 'true')" aria-label="Schließen">&times;</button>
                </div>
                <div style="margin-top: 16px;">
                  <p>Teilnehmer: <strong id="assign_name"></strong></p>
                  <div class="field" style="margin-top:15px;">
                    <label for="assign_num_input">Vorgeschlagene Nummer (oder Wunschnummer eingeben):</label>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                      <button type="button" class="btn btn-secondary" style="font-size: 24px; padding: 0; width: 50px; height: 50px; display:flex; justify-content:center; align-items:center;" onclick="document.getElementById('assign_num_input').value = Math.max(1, (parseInt(document.getElementById('assign_num_input').value) || 0) - 1)">-</button>
                      <input type="number" id="assign_num_input" min="1" max="999" style="text-align:center; font-size:24px; font-weight:bold; height:50px; width:120px; margin:0;" />
                      <button type="button" class="btn btn-secondary" style="font-size: 24px; padding: 0; width: 50px; height: 50px; display:flex; justify-content:center; align-items:center;" onclick="document.getElementById('assign_num_input').value = Math.min(999, (parseInt(document.getElementById('assign_num_input').value) || 0) + 1)">+</button>
                    </div>
                  </div>
                  <input type="hidden" id="assign_code_input" />
                  <input type="hidden" id="assign_is_auto_scan" />
                  <div class="actions" style="margin-top:24px;">
                    <button class="btn btn-primary" id="btn_confirm_assign">Bezahlen &amp; Zuweisen</button>
                    <button class="btn btn-secondary" onclick="document.getElementById('modalAssignNum').setAttribute('aria-hidden', 'true')">Abbrechen</button>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Dynamischer AJAX Container für Kasse -->
          <div id="ajax_kasse_result"></div>

          <?php if ($cash_error !== ''): ?>
            <div class="alert alert-error">
              <strong>Kasse:</strong> <?php echo escape($cash_error); ?>
            </div>
          <?php endif; ?>

          <?php if ($cash_success !== ''): ?>
            <div class="alert alert-success">
              <strong>Kasse:</strong> <?php echo escape($cash_success); ?>
            </div>
          <?php endif; ?>

          <?php if ($cash_row !== null): ?>
            <div class="panel" style="margin-bottom:12px;">
              <h2 style="margin:0 0 10px 0;">Gefunden</h2>
              <div class="grid">
                <div><strong>Code:</strong> <?php echo escape(registration_code_format((string)$cash_row['code'])); ?></div>
                <div><strong>Status:</strong> <?php echo ((int)$cash_row['bezahlt'] === 1) ? 'Bezahlt' : 'Offen'; ?></div>
                <div><strong>Vorname:</strong> <?php echo escape((string)$cash_row['vorname']); ?></div>
                <div><strong>Nachname:</strong> <?php echo escape((string)$cash_row['nachname']); ?></div>
                <div><strong>Team:</strong> <?php echo ((string)($cash_row['teamname'] ?? '') !== '') ? escape((string)$cash_row['teamname']) : '—'; ?></div>
                <div><strong>Startnummer:</strong> <?php echo $cash_row['startnummer'] ? escape(str_pad((string)$cash_row['startnummer'], 3, '0', STR_PAD_LEFT)) : 'Keine'; ?></div>
                <div><strong>Anmeldung:</strong> <?php echo escape(date('d.m.Y H:i', strtotime((string)$cash_row['registriert_am']))); ?></div>
                <div style="grid-column:1 / -1;" class="hint">
                  <!-- @doc-note Hier kann spaeter nicht personenbezogene Zusatzinfo fuer die Kasse erscheinen. -->
                  Tipp: Wenn jemand zweimal kassiert werden könnte, verhindert die "Bezahlt"-Markierung die zweite Buchung.
                </div>
              </div>

              <?php if ((int)$cash_row['bezahlt'] !== 1): ?>
                <?php if ($can_pay): ?>
                  <form method="post" action="admin.php?cash=1&amp;code=<?php echo urlencode((string)$cash_row['code']); ?>" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                    <input type="hidden" name="code" value="<?php echo escape((string)$cash_row['code']); ?>" />
                    <button class="btn btn-wide" type="submit" name="mark_paid" value="1">Als bezahlt markieren</button>
                  </form>
                <?php else: ?>
                  <div class="hint" style="margin-top:12px;">Keine Berechtigung zum Kassieren.</div>
                <?php endif; ?>
              <?php else: ?>
                <div class="hint" style="margin-top:12px;">
                  Bezahlt am: <?php echo $cash_row['bezahlt_am'] ? escape(date('d.m.Y H:i', strtotime((string)$cash_row['bezahlt_am']))) : '—'; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- @doc-section participant-list -->
          <!-- @doc-purpose Zeigt alle Teilnehmer mit Such-, Zahlungs- und Bearbeitungsaktionen in einer kompakten Tabelle. -->
          <!-- @doc-ui-flow participant-list-actions -->
          <!-- @doc-ui-step 1 Nutzer oeffnet den Bereich Teilnehmerliste. -->
          <!-- @doc-ui-step 2 In der letzten Spalte stehen kontextsensitive Aktionen wie Bearbeiten oder Loeschen bereit. -->
          <!-- @doc-ui-result Alle Kernaktionen fuer Teilnehmer bleiben direkt an der Tabellenzeile verfuegbar. -->
          <div id="participants_list" class="panel admin-section admin-anchor-target" data-admin-section-title="Teilnehmerliste" style="margin-top:12px;">
            <h2 style="margin:0 0 10px 0;">Teilnehmerliste</h2>
            <div class="table-wrap admin-anchor-target" id="participants_list_table" data-admin-anchor-label="Tabelle">
              <table class="data-table" id="participants_table" data-table-version="participants-v2">
                <thead>
                  <tr>
                    <th data-col-key="participant_id">#</th>
                    <th data-col-key="participant_code">Token</th>
                    <th data-col-key="participant_lastname">Nachname</th>
                    <th data-col-key="participant_firstname">Vorname</th>
                    <th data-col-key="participant_adult">Volljährig?</th>
                    <th data-col-key="participant_gender">Geschlecht</th>
                    <th data-col-key="participant_team">Team</th>
                    <th data-col-key="participant_number">Nummer</th>
                    <th data-col-key="participant_registered_at">Anmeldung</th>
                    <th class="participant-paid-head" data-col-key="participant_paid" data-no-resize="true" data-default-width="108">Bezahlt?</th>
                    <?php if ($can_edit_participants || $can_delete_participants): ?>
                      <th class="participant-actions-head" data-col-key="participant_actions" data-no-resize="true" data-default-width="132">Aktion</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr data-participant-id="<?php echo escape((string)$r['id']); ?>">
                      <td><?php echo escape((string)$r['id']); ?></td>
                      <td><?php echo escape(registration_code_format((string)$r['code'])); ?></td>
                      <td><?php echo escape((string)$r['nachname']); ?></td>
                      <td><?php echo escape((string)$r['vorname']); ?></td>
                      <td><?php echo ((int)$r['volljaehrig'] === 1) ? 'Ja' : 'Nein'; ?></td>
                      <td><?php echo escape((string)$r['geschlecht']); ?></td>
                      <td><?php echo ($r['teamname'] ?? '') !== '' ? escape((string)$r['teamname']) : ''; ?></td>
                      <td><?php echo $r['startnummer'] ? escape(str_pad((string)$r['startnummer'], 3, '0', STR_PAD_LEFT)) : '—'; ?></td>
                      <td><?php echo escape(date('d.m.Y H:i', strtotime((string)$r['registriert_am']))); ?></td>
                      <td class="participant-paid-cell">
                        <?php if ((int)$r['bezahlt'] === 1): ?>
                          <span class="participant-paid-status is-paid" aria-label="Bezahlt" title="Bezahlt">
                            <span class="participant-paid-icon is-paid" aria-hidden="true">&#10003;</span>
                          </span>
                        <?php else: ?>
                          <?php if ($can_pay): ?>
                            <button class="btn btn-small btn-secondary participant-paid-action btn-ajax-mark-paid" data-code="<?php echo escape($r['code']); ?>" data-name="<?php echo escape($r['vorname'] . ' ' . $r['nachname']); ?>" aria-label="Offen, als bezahlt markieren" title="Offen, als bezahlt markieren">
                              <span class="participant-paid-icon is-unpaid" aria-hidden="true">&#10005;</span>
                            </button>
                          <?php else: ?>
                            <span class="participant-paid-status is-unpaid" aria-label="Offen" title="Offen">
                              <span class="participant-paid-icon is-unpaid" aria-hidden="true">&#10005;</span>
                            </span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                      <?php if ($can_edit_participants || $can_delete_participants): ?>
                        <td class="participant-actions-cell">
                          <div class="participant-actions-wrap">
                            <?php if ($can_edit_participants): ?>
                              <button
                                type="button"
                                class="btn btn-small btn-secondary btn-participant-edit"
                                data-id="<?php echo escape((string)$r['id']); ?>"
                                data-vorname="<?php echo escape((string)$r['vorname']); ?>"
                                data-nachname="<?php echo escape((string)$r['nachname']); ?>"
                                data-volljaehrig="<?php echo escape((string)((int)$r['volljaehrig'])); ?>"
                                data-geschlecht="<?php echo escape((string)$r['geschlecht']); ?>"
                                data-team-id="<?php echo escape((string)((int)($r['team_id'] ?? 0))); ?>"
                                data-teamname="<?php echo escape((string)($r['teamname'] ?? '')); ?>"
                                aria-label="Teilnehmer bearbeiten"
                                title="Bearbeiten"
                              ><span class="participant-action-icon" aria-hidden="true">&#9998;</span></button>
                            <?php endif; ?>
                            <?php if ($can_delete_participants): ?>
                              <button
                                type="button"
                                class="btn btn-small btn-yellow btn-participant-delete"
                                data-id="<?php echo escape((string)$r['id']); ?>"
                                data-name="<?php echo escape(trim((string)$r['vorname'] . ' ' . (string)$r['nachname'])); ?>"
                                aria-label="Teilnehmer löschen"
                                title="Löschen"
                              ><span class="participant-action-icon" aria-hidden="true">&#128465;</span></button>
                            <?php endif; ?>
                          </div>
                        </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php if ($can_edit_participants): ?>
            <div class="modal-overlay" id="modal_participant_edit" aria-hidden="true">
              <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="participant_edit_title" tabindex="-1" style="max-width: 560px;">
                <div class="modal-header">
                  <h2 class="modal-title" id="participant_edit_title">Teilnehmer bearbeiten</h2>
                  <button class="modal-close" type="button" data-modal-close>Schließen</button>
                </div>

                <div id="participant_edit_msg" style="margin-top:12px;"></div>

                <form id="participant_edit_form" class="reg-form" style="margin-top:12px;">
                  <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
                  <input type="hidden" name="ajax_action" value="update_participant" />
                  <input type="hidden" name="id" id="participant_edit_id" value="" />

                  <div class="grid" style="align-items: end;">
                    <div class="field">
                      <label for="participant_edit_vorname">Vorname</label>
                      <input id="participant_edit_vorname" name="vorname" type="text" required />
                    </div>
                    <div class="field">
                      <label for="participant_edit_nachname">Nachname</label>
                      <input id="participant_edit_nachname" name="nachname" type="text" required />
                    </div>
                    <div class="field">
                      <label for="participant_edit_volljaehrig">Volljährig?</label>
                      <select id="participant_edit_volljaehrig" name="volljaehrig" required>
                        <option value="">Bitte wählen</option>
                        <option value="1">Ja</option>
                        <option value="0">Nein</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="participant_edit_geschlecht">Geschlecht</label>
                      <select id="participant_edit_geschlecht" name="geschlecht" required>
                        <option value="">Bitte wählen</option>
                        <option value="Männlich">Männlich</option>
                        <option value="Weiblich">Weiblich</option>
                        <option value="Divers">Divers</option>
                      </select>
                    </div>
                    <div class="field" style="grid-column: 1 / -1;">
                      <label for="participant_edit_team_select">Team</label>
                      <select id="participant_edit_team_select" name="team_select">
                        <option value="" selected>— Kein Team (Einzelkämpfer) —</option>
                        <?php foreach ($teams as $t): ?>
                          <option value="<?php echo escape((string)$t['id']); ?>"><?php echo escape((string)$t['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>

                  <div class="actions">
                    <button class="btn btn-primary" type="submit">Speichern</button>
                    <button class="btn btn-secondary" type="button" data-modal-close>Abbrechen</button>
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($can_admin_pages): ?>
            <div id="audit" class="panel admin-section admin-anchor-target" data-admin-section-title="Audit Log" style="margin-top:12px;" hidden>
              <h2 style="margin:0 0 10px 0;">Audit Log</h2>
              <p class="muted" style="margin:0 0 12px 0;">
                Letzte Änderungen (INSERT/UPDATE/DELETE) an Teilnehmern, Startnummern und Nutzern.
              </p>

              <div class="grid grid-4 admin-anchor-target" id="audit_filters" data-admin-anchor-label="Filter">
                <div class="field">
                  <label for="audit_table">Tabelle</label>
                  <select id="audit_table">
                    <option value="all">Alle</option>
                    <option value="teilnehmer">teilnehmer</option>
                    <option value="startnummern">startnummern</option>
                    <option value="users">users</option>
                  </select>
                </div>
                <div class="field">
                  <label for="audit_action">Aktion</label>
                  <select id="audit_action">
                    <option value="all">Alle</option>
                    <option value="INSERT">INSERT</option>
                    <option value="UPDATE">UPDATE</option>
                    <option value="DELETE">DELETE</option>
                  </select>
                </div>
                <div class="field">
                  <label for="audit_user">User</label>
                  <input id="audit_user" type="text" placeholder="z.B. Admin, cashier …" />
                </div>
                <div class="field">
                  <label for="audit_q">Suchtext</label>
                  <input id="audit_q" type="text" placeholder="ID, Name, JSON …" />
                </div>
                <div class="field">
                  <label for="audit_from">Von</label>
                  <input id="audit_from" type="datetime-local" />
                </div>
                <div class="field">
                  <label for="audit_to">Bis</label>
                  <input id="audit_to" type="datetime-local" />
                </div>
              </div>

              <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top: 12px;">
                <button class="btn btn-primary" type="button" id="audit_reload">Neu laden</button>
                <button class="btn btn-secondary" type="button" id="audit_more">Mehr laden</button>
                <button class="btn btn-secondary" type="button" id="audit_clear_table">Tabelle leeren</button>
                <button class="btn btn-secondary" type="button" id="audit_clear_all">Alle Logs leeren</button>
                <label style="display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--border); border-radius:10px;">
                  <input type="checkbox" id="audit_auto" />
                  Auto‑Refresh
                </label>
                <div id="audit_status" class="muted" style="margin-left:auto;"></div>
              </div>

              <div class="table-wrap admin-anchor-target" id="audit_entries" data-admin-anchor-label="Einträge" style="margin-top: 12px;">
                <table class="data-table audit-table" id="audit_table_view">
                  <thead>
                    <tr>
                      <th data-col-key="audit_time">Zeit</th>
                      <th data-col-key="audit_table">Tabelle</th>
                      <th data-col-key="audit_action">Aktion</th>
                      <th data-col-key="audit_pk">PK</th>
                      <th data-col-key="audit_user">User</th>
                      <th data-col-key="audit_changes">Änderungen</th>
                      <th data-col-key="audit_details" data-no-resize="true">Details</th>
                      <th data-col-key="audit_delete" data-no-resize="true">Löschen</th>
                    </tr>
                  </thead>
                  <tbody id="audit_tbody">
                    <tr>
                      <td colspan="8" class="muted">Lade …</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <button id="audit_modal_open" type="button" data-modal-open="modal_audit_detail" style="display:none;"></button>
            </div>

            <div class="modal-overlay" id="modal_audit_detail" aria-hidden="true">
              <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal_audit_title" tabindex="-1">
                <div class="modal-header">
                  <h2 class="modal-title" id="modal_audit_title">Audit‑Eintrag</h2>
                  <button class="modal-close" type="button" data-modal-close>Schließen</button>
                </div>

                <div id="audit_meta" class="grid" style="margin-top: 12px; grid-template-columns: 1fr 1fr;"></div>

                <div class="modal-section-title">Änderungen</div>
                <div id="audit_changed" class="check-text"></div>

                <div class="modal-section-title">Alt</div>
                <pre id="audit_old" class="audit-json">—</pre>

                <div class="modal-section-title">Neu</div>
                <pre id="audit_new" class="audit-json">—</pre>
              </div>
            </div>
        <?php endif; ?>
                    </div>
                    <iframe id="admin_content_iframe" class="admin-content-iframe" title="Admin-Unterseite" loading="lazy" hidden></iframe>
                  </div>
                </div>
              </section>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <script charset="utf-8" src="<?php echo escape($assetBase . '/assets/app.js'); ?>?v=<?php echo escape(app_asset_version('assets/app.js')); ?>"></script>
    <script>
      // @doc-section cashier-client
      // @doc-purpose Steuert Kasseninteraktion, QR-Scan, Suchtrefferaufbau und Zahlungsaktionen im eingebetteten Admin-Skript.
      document.addEventListener('DOMContentLoaded', () => {
        const themeToggleBtn = document.getElementById('theme_toggle');
        const themeToggleLabel = themeToggleBtn ? themeToggleBtn.querySelector('.admin-session-action-label') : null;
        const themeToggleIcon = themeToggleBtn ? themeToggleBtn.querySelector('.admin-session-action-icon') : null;
        const themeKey = 'admin_theme';
        const adminShell = document.getElementById('admin_page_shell');
        const adminSidebar = document.getElementById('admin_page_sidebar');
        const adminMobileMenuToggle = document.getElementById('admin_mobile_menu_toggle');
        const adminMobileMenuToggleLabel = adminMobileMenuToggle ? adminMobileMenuToggle.querySelector('.admin-mobile-menu-toggle-label') : null;
        const adminMobileMenuClose = document.getElementById('admin_mobile_menu_close');
        const setTheme = (theme, persist) => {
          const next = theme === 'dark' ? 'dark' : 'light';
          document.documentElement.setAttribute('data-theme', next);
          if (persist) {
            try {
              localStorage.setItem(themeKey, next);
            } catch (e) {}
          }
          if (themeToggleBtn) {
            themeToggleBtn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
            // @doc-note Das Button-Label zeigt bewusst den jeweils verfuegbaren Wechsel an, nicht das aktuelle Theme.
            if (themeToggleLabel) {
              themeToggleLabel.textContent = next === 'dark' ? 'Hellmodus' : 'Dunkelmodus';
            } else {
              themeToggleBtn.textContent = next === 'dark' ? 'Hellmodus' : 'Dunkelmodus';
            }
            if (themeToggleIcon) {
              themeToggleIcon.innerHTML = next === 'dark' ? '&#9728;' : '&#9790;';
            }
          }
          window.dispatchEvent(new CustomEvent('admin-theme-changed', { detail: { theme: next } }));
        };
        if (themeToggleBtn) {
          themeToggleBtn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            setTheme(current === 'dark' ? 'light' : 'dark', true);
          });
          const initial = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
          setTheme(initial, false);
        }

        const adminShellResizer = document.getElementById('admin_page_shell_resizer');
        // @doc-note Die Top-Level-Adminseite scrollt beim Laden bis zur Shell-Oberkante, damit Branding erhalten bleibt und die Navigation sofort sichtbar startet.
        const scrollAdminShellIntoView = () => {
          if (!adminShell || document.documentElement.hasAttribute('data-admin-embedded-frame')) {
            return;
          }
          const shellTop = Math.max(0, Math.round(adminShell.getBoundingClientRect().top + window.scrollY));
          window.requestAnimationFrame(() => {
            window.scrollTo({ top: shellTop, behavior: 'auto' });
          });
        };
        if (adminShell && adminShellResizer && typeof window.matchMedia === 'function') {
          const shellBreakpoint = parseInt(adminShell.getAttribute('data-admin-shell-breakpoint') || '1280', 10) || 1280;
          const desktopQuery = window.matchMedia(`(min-width: ${shellBreakpoint}px)`);
          const mobileQuery = window.matchMedia(`(max-width: ${Math.max(0, shellBreakpoint - 1)}px)`);
          const sidebarStorageKey = 'admin_sidebar_ratio_v1';
          const defaultSidebarWidth = 320;
          const minSidebarWidth = 260;
          const maxSidebarWidth = 520;
          const minMainWidth = 720;
          let resizeState = null;

          const setAdminMobileMenuOpen = (open) => {
            if (!adminShell || !adminSidebar || !adminMobileMenuToggle) {
              return;
            }
            const nextOpen = mobileQuery.matches && open;
            adminShell.classList.toggle('is-mobile-menu-open', nextOpen);
            adminMobileMenuToggle.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
            if (adminMobileMenuToggleLabel) {
              adminMobileMenuToggleLabel.textContent = nextOpen ? 'Admin-Menue schliessen' : 'Admin-Menue oeffnen';
            }
            adminMobileMenuToggle.setAttribute('aria-label', nextOpen ? 'Admin-Menue schliessen' : 'Admin-Menue oeffnen');
            adminSidebar.hidden = mobileQuery.matches ? !nextOpen : false;
          };

          const getStoredSidebarRatio = () => {
            try {
              const value = parseFloat(localStorage.getItem(sidebarStorageKey) || '');
              return Number.isFinite(value) && value > 0 ? value : null;
            } catch (e) {
              return null;
            }
          };

          const getShellGapWidth = () => {
            const styles = window.getComputedStyle(adminShell);
            return parseFloat(styles.columnGap || styles.gap || '0') || 0;
          };

          const getAvailableShellWidth = () => {
            const shellWidth = Math.round(adminShell.getBoundingClientRect().width || 0);
            const handleWidth = Math.round(adminShellResizer.getBoundingClientRect().width || 14);
            return Math.max(0, shellWidth - handleWidth - (getShellGapWidth() * 2));
          };

          const clampSidebarWidth = (rawWidth) => {
            const availableShellWidth = getAvailableShellWidth();
            const maxByShell = availableShellWidth > 0
              ? availableShellWidth - minMainWidth
              : maxSidebarWidth;
            const dynamicMax = Math.min(maxSidebarWidth, Math.max(minSidebarWidth, Math.round(maxByShell)));
            const numericWidth = Number(rawWidth);
            const safeWidth = Number.isFinite(numericWidth) ? numericWidth : defaultSidebarWidth;
            return Math.max(minSidebarWidth, Math.min(dynamicMax, Math.round(safeWidth)));
          };

          const getDefaultSidebarRatio = () => {
            const availableShellWidth = getAvailableShellWidth();
            if (availableShellWidth <= 0) {
              return 0.28;
            }
            return clampSidebarWidth(defaultSidebarWidth) / availableShellWidth;
          };

          const getCurrentSidebarRatio = () => {
            const inlineValue = parseFloat(adminShell.style.getPropertyValue('--admin-shell-ratio') || '');
            if (Number.isFinite(inlineValue) && inlineValue > 0) {
              return inlineValue;
            }
            return getStoredSidebarRatio() || getDefaultSidebarRatio();
          };

          const applySidebarWidth = (rawWidth, persist) => {
            const nextWidth = clampSidebarWidth(rawWidth);
            const availableShellWidth = getAvailableShellWidth();
            const nextRatio = availableShellWidth > 0 ? nextWidth / availableShellWidth : getCurrentSidebarRatio();
            adminShell.style.setProperty('--admin-sidebar-width', `${nextWidth}px`);
            adminShell.style.setProperty('--admin-shell-ratio', String(nextRatio));
            if (persist) {
              try {
                localStorage.setItem(sidebarStorageKey, String(nextRatio));
              } catch (e) {}
            }
          };

          const getCurrentSidebarWidth = () => {
            const inlineValue = parseInt(adminShell.style.getPropertyValue('--admin-sidebar-width') || '', 10);
            if (Number.isFinite(inlineValue)) {
              return inlineValue;
            }
            return clampSidebarWidth(getCurrentSidebarRatio() * getAvailableShellWidth());
          };

          const stopShellResize = () => {
            if (!resizeState) {
              return;
            }
            resizeState = null;
            adminShellResizer.classList.remove('is-dragging');
            document.body.classList.remove('admin-shell-resizing');
            applySidebarWidth(getCurrentSidebarWidth(), true);
          };

          const syncShellLayout = () => {
            const isDesktop = desktopQuery.matches;
            adminShell.classList.toggle('is-desktop-shell', isDesktop);
            adminShell.classList.toggle('is-mobile-shell', !isDesktop);
            if (!isDesktop) {
              stopShellResize();
              adminShell.style.removeProperty('--admin-sidebar-width');
              adminShell.style.removeProperty('--admin-shell-ratio');
              setAdminMobileMenuOpen(false);
              return;
            }
            setAdminMobileMenuOpen(false);
            applySidebarWidth((getStoredSidebarRatio() || getDefaultSidebarRatio()) * getAvailableShellWidth(), false);
          };

          if (adminMobileMenuToggle) {
            adminMobileMenuToggle.addEventListener('click', () => {
              const isOpen = adminShell.classList.contains('is-mobile-menu-open');
              setAdminMobileMenuOpen(!isOpen);
            });
          }

          if (adminMobileMenuClose) {
            adminMobileMenuClose.addEventListener('click', () => {
              setAdminMobileMenuOpen(false);
            });
          }

          document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
              setAdminMobileMenuOpen(false);
            }
          });

          adminShellResizer.addEventListener('pointerdown', (event) => {
            if (!desktopQuery.matches) {
              return;
            }
            resizeState = {
              startX: event.clientX,
              startWidth: getCurrentSidebarWidth(),
            };
            adminShellResizer.classList.add('is-dragging');
            document.body.classList.add('admin-shell-resizing');
            if (typeof adminShellResizer.setPointerCapture === 'function') {
              adminShellResizer.setPointerCapture(event.pointerId);
            }
            event.preventDefault();
          });

          adminShellResizer.addEventListener('dblclick', () => {
            if (!desktopQuery.matches) {
              return;
            }
            applySidebarWidth(defaultSidebarWidth, true);
          });

          window.addEventListener('pointermove', (event) => {
            if (!resizeState) {
              return;
            }
            applySidebarWidth(resizeState.startWidth + (event.clientX - resizeState.startX), false);
          });

          window.addEventListener('pointerup', () => {
            stopShellResize();
          });

          window.addEventListener('resize', () => {
            if (!desktopQuery.matches) {
              return;
            }
            applySidebarWidth(getCurrentSidebarWidth(), false);
          });

          if (typeof desktopQuery.addEventListener === 'function') {
            desktopQuery.addEventListener('change', syncShellLayout);
          } else if (typeof desktopQuery.addListener === 'function') {
            desktopQuery.addListener(syncShellLayout);
          }

          syncShellLayout();
        }

        const adminSections = Array.from(document.querySelectorAll('.admin-section'));
        const adminNavButtons = Array.from(document.querySelectorAll('.admin-nav-btn[data-admin-target]'));
        const adminFrameLinks = Array.from(document.querySelectorAll('.admin-link-btn[data-admin-frame-url]'));
        const adminAnchorNav = document.getElementById('admin_anchor_nav');
        const adminAnchorEmpty = document.getElementById('admin_anchor_empty');
        const adminAnchorCopy = document.getElementById('admin_anchor_copy');
        const adminActionTickerText = document.getElementById('admin_action_ticker_text');
        const adminContentScroll = document.getElementById('admin_content_scroll');
        const adminInternalFrame = document.getElementById('admin_internal_frame');
        const adminContentIframe = document.getElementById('admin_content_iframe');

        if (adminSections.length > 0 && adminNavButtons.length > 0 && adminAnchorNav && adminAnchorEmpty && adminAnchorCopy && adminActionTickerText && adminContentScroll && adminInternalFrame && adminContentIframe) {
          const defaultAdminSectionId = adminNavButtons[0].getAttribute('data-admin-target') || adminSections[0].id;
          let activeAdminSectionId = '';
          const adminTickerEntries = [];
          let adminTickerIndex = 0;
          let adminTickerTimer = null;

          const getAdminSectionById = (sectionId) => adminSections.find((section) => section.id === sectionId) || null;

          const renderAdminTicker = () => {
            const nextMessage = adminTickerEntries.length > 0 ? adminTickerEntries[adminTickerIndex] : 'Bereit.';
            adminActionTickerText.classList.add('is-updating');
            window.setTimeout(() => {
              adminActionTickerText.textContent = nextMessage;
              window.requestAnimationFrame(() => {
                adminActionTickerText.classList.remove('is-updating');
              });
            }, 90);
          };

          const ensureAdminTickerTimer = () => {
            if (adminTickerTimer !== null) {
              return;
            }
            adminTickerTimer = window.setInterval(() => {
              if (adminTickerEntries.length <= 1) {
                return;
              }
              adminTickerIndex = (adminTickerIndex + 1) % adminTickerEntries.length;
              renderAdminTicker();
            }, 4200);
          };

          const pushAdminTicker = (message) => {
            const text = String(message || '').trim();
            if (text === '') {
              return;
            }
            const timestamp = new Intl.DateTimeFormat('de-DE', {
              hour: '2-digit',
              minute: '2-digit',
            }).format(new Date());
            adminTickerEntries.unshift(`${timestamp} · ${text}`);
            if (adminTickerEntries.length > 8) {
              adminTickerEntries.length = 8;
            }
            adminTickerIndex = 0;
            renderAdminTicker();
            ensureAdminTickerTimer();
          };

          window.addEventListener('admin-action-ticker', (event) => {
            const nextMessage = event && event.detail ? event.detail.message : '';
            pushAdminTicker(nextMessage);
          });

          const getSectionAnchors = (section) => {
            if (!section) {
              return [];
            }
            return Array.from(section.querySelectorAll('[data-admin-anchor-label][id]'));
          };

          const setActiveNavigationState = (sectionId) => {
            adminNavButtons.forEach((button) => {
              const isActive = button.getAttribute('data-admin-target') === sectionId;
              button.classList.toggle('btn-secondary', !isActive);
              button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
          };

          const setActiveFrameLinkState = (activeUrl) => {
            adminFrameLinks.forEach((link) => {
              const isActive = activeUrl !== '' && link.getAttribute('data-admin-frame-url') === activeUrl;
              link.classList.toggle('is-active-link', isActive);
              link.setAttribute('aria-current', isActive ? 'page' : 'false');
            });
          };

          const setContentFrameHeader = (title) => {
            pushAdminTicker(`Bereich geoeffnet: ${title}`);
          };

          const buildAdminFrameUrl = (url) => {
            if (!url) {
              return '';
            }
            try {
              const nextUrl = new URL(url, window.location.href);
              nextUrl.searchParams.set('embedded_frame', 'admin');
              nextUrl.searchParams.set('embedded_theme', 'legacy');
              return nextUrl.toString();
            } catch (error) {
              return url;
            }
          };

          const syncEmbeddedAdminFrame = () => {
            try {
              const frameDoc = adminContentIframe.contentDocument;
              const frameRoot = frameDoc && frameDoc.documentElement ? frameDoc.documentElement : null;
              const frameBody = frameDoc && frameDoc.body ? frameDoc.body : null;
              const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
              if (!frameDoc || !frameRoot || !frameBody) {
                return;
              }

              frameRoot.setAttribute('data-admin-embedded-frame', 'true');
              frameRoot.setAttribute('data-theme', currentTheme);
              frameRoot.style.colorScheme = currentTheme;
              frameBody.setAttribute('data-admin-embedded-frame', 'true');
              if (adminShell) {
                const parentShellStyles = window.getComputedStyle(adminShell);
                const parentSidebarWidth = parentShellStyles.getPropertyValue('--admin-sidebar-width').trim();
                const parentShellRatio = parentShellStyles.getPropertyValue('--admin-shell-ratio').trim();
                if (parentSidebarWidth !== '') {
                  frameRoot.style.setProperty('--admin-sidebar-width', parentSidebarWidth);
                }
                if (parentShellRatio !== '') {
                  frameRoot.style.setProperty('--admin-shell-ratio', parentShellRatio);
                }
              }

              const themeToggle = frameDoc.getElementById('theme_toggle');
              if (themeToggle) {
                themeToggle.hidden = true;
              }

              if (!frameDoc.getElementById('admin_embedded_frame_style')) {
                const frameStyle = frameDoc.createElement('style');
                frameStyle.id = 'admin_embedded_frame_style';
                frameStyle.textContent = `
html[data-admin-embedded-frame="true"] {
  --text: #222222;
  --bg: #ffffff;
  --panel: #ffffff;
  --border: #d7dbe0;
  --muted: #5a6570;
  --focus: rgba(227, 6, 19, 0.25);
  --dlrg-schwarz: #575756;
}
html[data-admin-embedded-frame="true"],
html[data-admin-embedded-frame="true"] body {
  background: #ffffff !important;
  color: #222222 !important;
}
html[data-admin-embedded-frame="true"] .page {
  padding: 0 !important;
}
html[data-admin-embedded-frame="true"] .admin-page-shell {
  width: 100% !important;
}
html[data-admin-embedded-frame="true"] .container {
  max-width: none !important;
  width: 100% !important;
  margin: 0 !important;
  padding: 0 !important;
  border: 0 !important;
  border-radius: 0 !important;
  background: transparent !important;
  box-shadow: none !important;
}
html[data-admin-embedded-frame="true"] .panel,
html[data-admin-embedded-frame="true"] .alert,
html[data-admin-embedded-frame="true"] .modal-panel,
html[data-admin-embedded-frame="true"] .table-wrap,
html[data-admin-embedded-frame="true"] fieldset,
html[data-admin-embedded-frame="true"] .selected-item,
html[data-admin-embedded-frame="true"] .schedule-card,
html[data-admin-embedded-frame="true"] .admin-session-info,
html[data-admin-embedded-frame="true"] .table-summary,
html[data-admin-embedded-frame="true"] .table-column-options {
  background: #ffffff !important;
  color: #222222 !important;
  border-color: #d7dbe0 !important;
  box-shadow: none !important;
}
html[data-admin-embedded-frame="true"] input[type="text"],
html[data-admin-embedded-frame="true"] input[type="password"],
html[data-admin-embedded-frame="true"] input[type="number"],
html[data-admin-embedded-frame="true"] input[type="datetime-local"],
html[data-admin-embedded-frame="true"] input[type="email"],
html[data-admin-embedded-frame="true"] input[type="date"],
html[data-admin-embedded-frame="true"] input[type="time"],
html[data-admin-embedded-frame="true"] select,
html[data-admin-embedded-frame="true"] .icon-btn {
  background: #ffffff !important;
  color: #222222 !important;
  border-color: #d7dbe0 !important;
}
html[data-admin-embedded-frame="true"] .muted,
html[data-admin-embedded-frame="true"] .hint {
  color: #5a6570 !important;
}
html[data-admin-embedded-frame="true"] tr:nth-child(even) td {
  background: #fafbfc !important;
}
html[data-admin-embedded-frame="true"] .participant-actions-cell {
  background: linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%) !important;
}
html[data-admin-embedded-frame="true"] tr:nth-child(even) .participant-actions-cell {
  background: linear-gradient(180deg, #fafcff 0%, #f3f7fb 100%) !important;
}
@media (min-width: 1280px) {
  html[data-admin-embedded-frame="true"] .admin-page-shell.is-desktop-shell {
    grid-template-columns: minmax(var(--admin-sidebar-min, 280px), var(--admin-sidebar-width, 336px)) 14px minmax(0, 1fr) !important;
    gap: 20px !important;
  }
}
html[data-admin-embedded-frame="true"][data-theme="dark"] {
  color-scheme: dark;
  --text: #e6e9ef;
  --bg: #141a24;
  --panel: #141a24;
  --border: rgba(255, 255, 255, 0.08);
  --muted: #a9b3c3;
  --focus: rgba(255, 237, 0, 0.18);
  --dlrg-schwarz: #2f3845;
}
html[data-admin-embedded-frame="true"][data-theme="dark"],
html[data-admin-embedded-frame="true"][data-theme="dark"] body {
  background: #141a24 !important;
  color: #e6e9ef !important;
}
html[data-admin-embedded-frame="true"][data-theme="dark"] .panel,
html[data-admin-embedded-frame="true"][data-theme="dark"] .alert,
html[data-admin-embedded-frame="true"][data-theme="dark"] .modal-panel,
html[data-admin-embedded-frame="true"][data-theme="dark"] .table-wrap,
html[data-admin-embedded-frame="true"][data-theme="dark"] fieldset,
html[data-admin-embedded-frame="true"][data-theme="dark"] .selected-item,
html[data-admin-embedded-frame="true"][data-theme="dark"] .schedule-card,
html[data-admin-embedded-frame="true"][data-theme="dark"] .admin-session-info,
html[data-admin-embedded-frame="true"][data-theme="dark"] .table-summary,
html[data-admin-embedded-frame="true"][data-theme="dark"] .table-column-options {
  background: #141a24 !important;
  color: #e6e9ef !important;
  border-color: rgba(255, 255, 255, 0.08) !important;
}
html[data-admin-embedded-frame="true"][data-theme="dark"] input[type="text"],
html[data-admin-embedded-frame="true"][data-theme="dark"] input[type="password"],
html[data-admin-embedded-frame="true"][data-theme="dark"] input[type="number"],
html[data-admin-embedded-frame="true"][data-theme="dark"] input[type="datetime-local"],
html[data-admin-embedded-frame="true"][data-theme="dark"] input[type="email"],
html[data-admin-embedded-frame="true"][data-theme="dark"] input[type="date"],
html[data-admin-embedded-frame="true"][data-theme="dark"] input[type="time"],
html[data-admin-embedded-frame="true"][data-theme="dark"] select,
html[data-admin-embedded-frame="true"][data-theme="dark"] .icon-btn {
  background: #0f131a !important;
  color: #e6e9ef !important;
  border-color: rgba(255, 255, 255, 0.08) !important;
}
html[data-admin-embedded-frame="true"][data-theme="dark"] .muted,
html[data-admin-embedded-frame="true"][data-theme="dark"] .hint {
  color: #a9b3c3 !important;
}
html[data-admin-embedded-frame="true"][data-theme="dark"] tr:nth-child(even) td {
  background: rgba(255, 255, 255, 0.03) !important;
}
html[data-admin-embedded-frame="true"][data-theme="dark"] .participant-actions-cell {
  background: linear-gradient(180deg, #141a24 0%, #111720 100%) !important;
}
html[data-admin-embedded-frame="true"][data-theme="dark"] tr:nth-child(even) .participant-actions-cell {
  background: linear-gradient(180deg, #18212d 0%, #121a24 100%) !important;
}
html[data-admin-embedded-frame="true"][data-theme="dark"] .participant-actions-wrap .btn.btn-secondary {
  background: #243447 !important;
  border-color: rgba(255, 255, 255, 0.08) !important;
  color: #e6e9ef !important;
}
                `;
                if (frameDoc.head) {
                  frameDoc.head.appendChild(frameStyle);
                }
              }
            } catch (error) {
              // Same-origin Unterseiten werden gestylt; bei Ausnahmen bleibt die Seite funktionsfaehig.
            }
          };

          adminContentIframe.addEventListener('load', syncEmbeddedAdminFrame);
          window.addEventListener('admin-theme-changed', syncEmbeddedAdminFrame);

          const scrollAdminTargetIntoView = (targetId, smooth) => {
            if (!targetId) {
              return;
            }
            const target = document.getElementById(targetId);
            if (!target) {
              return;
            }
            window.requestAnimationFrame(() => {
              target.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start', inline: 'nearest' });
            });
          };

          const setActiveAnchorState = (targetId) => {
            Array.from(adminAnchorNav.querySelectorAll('.admin-anchor-btn')).forEach((button) => {
              const isActive = button.getAttribute('data-admin-anchor-id') === targetId;
              button.classList.toggle('is-active-anchor', isActive);
              button.setAttribute('aria-current', isActive ? 'location' : 'false');
            });
          };

          const renderAdminAnchors = (section, activeTargetId) => {
            adminAnchorNav.replaceChildren();
            const anchors = getSectionAnchors(section);

            if (anchors.length === 0) {
              adminAnchorCopy.textContent = 'Fuer diesen Bereich gibt es aktuell keine weiteren Sprungmarken. Die Ansicht bleibt direkt im rechten Frame geoeffnet.';
              adminAnchorEmpty.hidden = false;
              return;
            }

            adminAnchorCopy.textContent = 'Spruenge innerhalb des aktuell geoeffneten Bereichs landen direkt im rechten Inhalts-Frame.';
            adminAnchorEmpty.hidden = true;

            const topButton = document.createElement('button');
            topButton.type = 'button';
            topButton.className = 'btn btn-secondary admin-anchor-btn';
            topButton.textContent = 'Anfang';
            topButton.setAttribute('data-admin-anchor-id', section.id);
            adminAnchorNav.appendChild(topButton);

            anchors.forEach((anchor) => {
              const button = document.createElement('button');
              button.type = 'button';
              button.className = 'btn btn-secondary admin-anchor-btn';
              button.textContent = String(anchor.getAttribute('data-admin-anchor-label') || anchor.id);
              button.setAttribute('data-admin-anchor-id', anchor.id);
              adminAnchorNav.appendChild(button);
            });

            setActiveAnchorState(activeTargetId || section.id);
          };

          const showInternalAdminFrame = () => {
            adminInternalFrame.hidden = false;
            adminContentScroll.hidden = false;
            adminContentIframe.hidden = true;
            setActiveFrameLinkState('');
          };

          const activateAdminSection = (sectionId, options = {}) => {
            const nextSection = getAdminSectionById(sectionId) || getAdminSectionById(defaultAdminSectionId);
            if (!nextSection) {
              return;
            }

            const anchorId = options.anchorId && document.getElementById(options.anchorId) ? options.anchorId : '';
            const activeTargetId = anchorId || nextSection.id;
            activeAdminSectionId = nextSection.id;

            showInternalAdminFrame();
            adminSections.forEach((section) => {
              section.hidden = section !== nextSection;
            });

            setActiveNavigationState(nextSection.id);
            setContentFrameHeader(String(nextSection.getAttribute('data-admin-section-title') || nextSection.id));
            renderAdminAnchors(nextSection, activeTargetId);

            if (options.updateHash !== false) {
              history.replaceState(null, '', `#${activeTargetId}`);
            }

            if (options.scroll !== false) {
              scrollAdminTargetIntoView(activeTargetId, options.smooth === true);
            } else {
              setActiveAnchorState(activeTargetId);
            }
          };

          const loadAdminSubpageInFrame = (url, title) => {
            if (!url) {
              return;
            }
            const frameUrl = buildAdminFrameUrl(url);
            activeAdminSectionId = '';
            adminSections.forEach((section) => {
              section.hidden = true;
            });
            setActiveNavigationState('');
            setActiveFrameLinkState(url);
            adminAnchorNav.replaceChildren();
            adminAnchorEmpty.hidden = false;
            adminAnchorCopy.textContent = 'Diese Unterseite wird direkt im rechten Frame geladen. Interne Sprungmarken stehen hier nicht zentral zur Verfuegung.';
            pushAdminTicker(`Unterseite geladen: ${title || 'Unterseite'}`);
            adminInternalFrame.hidden = true;
            adminContentScroll.hidden = true;
            adminContentIframe.hidden = false;
            if (adminContentIframe.getAttribute('src') !== frameUrl) {
              adminContentIframe.setAttribute('src', frameUrl);
            } else {
              syncEmbeddedAdminFrame();
            }
          };

          const resolveInitialAdminView = () => {
            const rawHash = String(window.location.hash || '').replace(/^#/, '').trim();
            if (rawHash !== '') {
              const section = getAdminSectionById(rawHash);
              if (section) {
                return { sectionId: section.id, anchorId: '' };
              }
              const anchor = document.getElementById(rawHash);
              const sectionFromAnchor = anchor ? anchor.closest('.admin-section') : null;
              if (sectionFromAnchor && sectionFromAnchor.id) {
                return { sectionId: sectionFromAnchor.id, anchorId: rawHash };
              }
            }
            return { sectionId: defaultAdminSectionId, anchorId: '' };
          };

          adminNavButtons.forEach((button) => {
            button.addEventListener('click', () => {
              const targetId = button.getAttribute('data-admin-target') || defaultAdminSectionId;
              activateAdminSection(targetId, { updateHash: true, scroll: true });
              setAdminMobileMenuOpen(false);
            });
          });

          adminAnchorNav.addEventListener('click', (event) => {
            const button = event.target.closest('.admin-anchor-btn[data-admin-anchor-id]');
            if (!button || !activeAdminSectionId) {
              return;
            }
            const anchorId = button.getAttribute('data-admin-anchor-id') || activeAdminSectionId;
            activateAdminSection(activeAdminSectionId, { anchorId, updateHash: true, scroll: true, smooth: true });
          });

          adminFrameLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
              if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') {
                return;
              }
              event.preventDefault();
              loadAdminSubpageInFrame(
                String(link.getAttribute('data-admin-frame-url') || link.getAttribute('href') || ''),
                String(link.getAttribute('data-admin-frame-title') || link.textContent || 'Unterseite').trim()
              );
              setAdminMobileMenuOpen(false);
            });
          });

          window.addEventListener('hashchange', () => {
            const nextView = resolveInitialAdminView();
            activateAdminSection(nextView.sectionId, { anchorId: nextView.anchorId, updateHash: false, scroll: true });
          });

          const initialAdminView = resolveInitialAdminView();
          activateAdminSection(initialAdminView.sectionId, { anchorId: initialAdminView.anchorId, updateHash: false, scroll: false });
          scrollAdminShellIntoView();
        }

        const setupResizableTable = (table, options = {}) => {
          if (!table || !table.tHead || !table.tHead.rows.length) {
            return;
          }

          const storageKey = options.storageKey || `resizable_${table.id || 'table'}`;
          const minWidth = options.minWidth || 96;
          const headers = Array.from(table.tHead.rows[0].cells);
          let colgroup = table.querySelector('colgroup');
          let resizeState = null;

          if (!colgroup) {
            colgroup = document.createElement('colgroup');
            headers.forEach(() => {
              colgroup.appendChild(document.createElement('col'));
            });
            table.insertBefore(colgroup, table.firstChild);
          }

          const cols = Array.from(colgroup.children);
          headers.forEach((th, index) => {
            if (!cols[index]) {
              return;
            }
            const defaultWidth = Number(th.dataset.defaultWidth || 0);
            const measuredWidth = Math.round(th.getBoundingClientRect().width);
            cols[index].style.width = `${Math.max(minWidth, defaultWidth || measuredWidth)}px`;
            if (th.dataset.noResize === 'true') {
              return;
            }
            th.classList.add('is-resizable');
            if (!th.querySelector('.table-resize-handle')) {
              const handle = document.createElement('span');
              handle.className = 'table-resize-handle';
              handle.setAttribute('aria-hidden', 'true');
              handle.addEventListener('pointerdown', (event) => {
                resizeState = {
                  index,
                  startX: event.clientX,
                  startWidth: Math.max(minWidth, Math.round(cols[index].getBoundingClientRect().width || th.getBoundingClientRect().width)),
                  handle,
                };
                handle.classList.add('is-active');
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
                event.preventDefault();
              });
              th.appendChild(handle);
            }
          });

          try {
            const savedWidths = JSON.parse(localStorage.getItem(storageKey) || '{}');
            Object.keys(savedWidths).forEach((key) => {
              const index = Number(key);
              if (!cols[index] || !headers[index] || headers[index].dataset.noResize === 'true') {
                return;
              }
              if (Number(savedWidths[key]) > 0) {
                const defaultWidth = Number(headers[index].dataset.defaultWidth || 0);
                cols[index].style.width = `${Math.max(minWidth, defaultWidth || 0, Number(savedWidths[key]))}px`;
              }
            });
          } catch (e) {}

          window.addEventListener('pointermove', (event) => {
            if (!resizeState) {
              return;
            }
            cols[resizeState.index].style.width = `${Math.max(minWidth, resizeState.startWidth + (event.clientX - resizeState.startX))}px`;
          });

          window.addEventListener('pointerup', () => {
            if (!resizeState) {
              return;
            }
            resizeState.handle.classList.remove('is-active');
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            try {
              const current = JSON.parse(localStorage.getItem(storageKey) || '{}');
              current[resizeState.index] = Math.round(cols[resizeState.index].getBoundingClientRect().width);
              localStorage.setItem(storageKey, JSON.stringify(current));
            } catch (e) {}
            resizeState = null;
          });
        };

        setupResizableTable(document.getElementById('participants_table'), { storageKey: 'participants_table_widths_v2', minWidth: 90 });
        setupResizableTable(document.getElementById('audit_table_view'), { storageKey: 'audit_table_widths', minWidth: 90 });
        setupResizableTable(document.getElementById('users_table'), { storageKey: 'users_table_widths', minWidth: 90 });
        setupResizableTable(document.getElementById('teams_table'), { storageKey: 'teams_table_widths', minWidth: 100 });

        const cashCodeInput = document.getElementById('cash_code');
        const ajaxResult = document.getElementById('ajax_kasse_result');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const canPay = <?php echo $can_pay ? 'true' : 'false'; ?>;
        const canChangeNum = <?php echo $can_change_num ? 'true' : 'false'; ?>;
        
        // @doc-note Reagiert zentral auf Buttons, die Teilnehmer als bezahlt markieren sollen.
        document.addEventListener('click', (e) => {
          const btn = e.target.closest('.btn-ajax-mark-paid');
          if (btn) {
            if (!canPay) {
              return;
            }
            const code = btn.getAttribute('data-code');
            const name = btn.getAttribute('data-name');
            let suggested = btn.getAttribute('data-suggested');
            
            if (!suggested && btn.closest('.data-table')) {
              // Wenn aus der Tabelle, holen wir die vorgeschlagene Nummer per AJAX
              fetch(`admin.php?ajax_action=search&code=${encodeURIComponent(code)}`)
                .then(res => res.json())
                .then(res => {
                  if (res.success && res.data.length > 0) {
                    openAssignModal(code, name, res.data[0].suggested_startnummer, false);
                  } else {
                    openAssignModal(code, name, '', false);
                  }
                });
            } else {
              openAssignModal(code, name, suggested || '', false);
            }
          }
        });
        
        let html5QrcodeScanner = null;
        let qrScannerActive = false;
        const btnScanQr = document.getElementById('btn_scan_qr');
        const btnStopScan = document.getElementById('btn_stop_scan');
        const btnCloseScan = document.getElementById('btn_close_scan');
        const qrContainer = document.getElementById('qr_scanner_container');
        
        // @doc-note Normalisiert Codes fuer Eingabe, Suche und QR-Scan auf einen robusten Vergleichswert.
        function normalizeCode(code) {
          return code.toUpperCase().replace(/[^A-Z0-9]/g, '');
        }

        function setAjaxAlert(type, title, message, extraLines = []) {
          const wrap = document.createElement('div');
          wrap.className = type === 'success' ? 'alert alert-success' : 'alert alert-error';
          const strong = document.createElement('strong');
          strong.textContent = `${title}:`;
          wrap.appendChild(strong);
          wrap.appendChild(document.createTextNode(` ${message}`));
          extraLines.forEach((line) => {
            wrap.appendChild(document.createElement('br'));
            wrap.appendChild(document.createTextNode(String(line || '')));
          });
          ajaxResult.replaceChildren(wrap);
          const tickerMessage = `${title}: ${message}`;
          const tickerEvent = new CustomEvent('admin-action-ticker', { detail: { message: tickerMessage } });
          window.dispatchEvent(tickerEvent);
        }

        function appendCashField(grid, label, value) {
          const item = document.createElement('div');
          const strong = document.createElement('strong');
          strong.textContent = `${label}:`;
          item.appendChild(strong);
          item.appendChild(document.createTextNode(` ${value}`));
          grid.appendChild(item);
        }

        // @doc-note Baut Suchtreffer bewusst per DOM-API auf, damit keine HTML-Fragmente aus Nutzdaten entstehen.
        function renderCashResult(dataInput) {
          const dataList = Array.isArray(dataInput) ? dataInput : [dataInput];
          const fragment = document.createDocumentFragment();

          dataList.forEach(data => {
            const panel = document.createElement('div');
            panel.className = 'panel';
            panel.style.marginBottom = '12px';

            const heading = document.createElement('h2');
            heading.style.margin = '0 0 10px 0';
            heading.textContent = `Gefunden: ${(data.vorname || '').trim()} ${(data.nachname || '').trim()}`.trim();
            panel.appendChild(heading);

            const grid = document.createElement('div');
            grid.className = 'grid';
            appendCashField(grid, 'Code', String(data.code || ''));
            appendCashField(grid, 'Status', parseInt(data.bezahlt, 10) === 1 ? 'Bezahlt' : 'Offen');
            appendCashField(grid, 'Vorname', String(data.vorname || ''));
            appendCashField(grid, 'Nachname', String(data.nachname || ''));
            appendCashField(grid, 'Team', data.teamname ? String(data.teamname) : '—');
            appendCashField(grid, 'Startnummer', data.startnummer ? String(data.startnummer).padStart(3, '0') : 'Keine');
            appendCashField(grid, 'Anmeldung', String(data.registriert_am || ''));
            panel.appendChild(grid);

            if (parseInt(data.bezahlt) !== 1) {
              if (canPay) {
                const actionWrap = document.createElement('div');
                actionWrap.style.marginTop = '12px';
                const button = document.createElement('button');
                button.className = 'btn btn-wide btn-ajax-mark-paid';
                button.type = 'button';
                button.setAttribute('data-code', String(data.code || ''));
                button.setAttribute('data-name', `${String(data.vorname || '').trim()} ${String(data.nachname || '').trim()}`.trim());
                button.setAttribute('data-suggested', data.suggested_startnummer ? String(data.suggested_startnummer) : '');
                button.textContent = 'Als bezahlt markieren & Nr. zuweisen';
                actionWrap.appendChild(button);
                panel.appendChild(actionWrap);
              } else {
                const hint = document.createElement('div');
                hint.className = 'hint';
                hint.style.marginTop = '12px';
                hint.textContent = 'Keine Berechtigung zum Kassieren.';
                panel.appendChild(hint);
              }
            } else {
              const hint = document.createElement('div');
              hint.className = 'hint';
              hint.style.marginTop = '12px';
              hint.textContent = `Bezahlt am: ${data.bezahlt_am || '—'}`;
              panel.appendChild(hint);
              if (canChangeNum) {
                const actionWrap = document.createElement('div');
                actionWrap.style.marginTop = '12px';
                actionWrap.style.display = 'flex';
                actionWrap.style.gap = '10px';

                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'field';
                input.setAttribute('data-code-input', String(data.code || ''));
                input.min = '1';
                input.max = '999';
                input.placeholder = 'Neue Nr.';
                input.style.width = '100px';

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-secondary btn-ajax-change-num';
                button.setAttribute('data-code', String(data.code || ''));
                button.textContent = 'Nummer ändern';

                actionWrap.appendChild(input);
                actionWrap.appendChild(button);
                panel.appendChild(actionWrap);
              }
            }
            fragment.appendChild(panel);
          });

          ajaxResult.replaceChildren(fragment);

          if (canChangeNum) {
            const btnsChangeNum = ajaxResult.querySelectorAll('.btn-ajax-change-num');
            btnsChangeNum.forEach(btn => {
              btn.addEventListener('click', () => {
                const code = btn.getAttribute('data-code');
                const numInput = ajaxResult.querySelector(`[data-code-input="${CSS.escape(code || '')}"]`);
                if (numInput && numInput.value) {
                  changeStartnummer(code, numInput.value);
                }
              });
            });
          }
        }

        // @doc-note Sendet die gewuenschte Startnummeraenderung an den Server und aktualisiert danach die Ansicht.
        function changeStartnummer(code, neueNummer) {
          fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              ajax_action: 'change_startnummer',
              code: code,
              neue_nummer: neueNummer,
              csrf_token: csrfToken
            })
          })
          .then(res => res.json())
          .then(res => {
            if (res.success) {
              setAjaxAlert('success', 'Kasse', String(res.message || 'Startnummer geändert.'));
              setTimeout(() => window.location.reload(), 1500);
            } else {
              setAjaxAlert('error', 'Kasse Fehler', String(res.error || 'Unbekannter Fehler.'));
            }
          })
          .catch(err => {
            setAjaxAlert('error', 'Kasse Fehler', 'Netzwerkfehler.');
          });
        }

        // @doc-note Oeffnet das Bestaetigungsmodal vor dem Bezahlen und uebergibt Vorschlags- oder Wunschdaten.
        function openAssignModal(code, name, suggested, isAutoScan) {
          document.getElementById('assign_name').innerText = name;
          document.getElementById('assign_num_input').value = suggested || '';
          document.getElementById('assign_code_input').value = code;
          document.getElementById('assign_is_auto_scan').value = isAutoScan ? '1' : '0';
          document.getElementById('modalAssignNum').setAttribute('aria-hidden', 'false');
        }

        document.getElementById('btn_confirm_assign')?.addEventListener('click', () => {
          const code = document.getElementById('assign_code_input').value;
          const num = document.getElementById('assign_num_input').value;
          const isAutoScan = document.getElementById('assign_is_auto_scan').value === '1';
          document.getElementById('modalAssignNum').setAttribute('aria-hidden', 'true');
          executeMarkAsPaid(code, num, isAutoScan);
        });

        // @doc-note Fuehrt die Zahlungsbuchung aus und gibt Zeitstempel sowie Nummernzuweisung an das UI zurueck.
        function executeMarkAsPaid(code, wunschnummer, isAutoScan) {
          fetch('admin.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
              ajax_action: 'mark_paid',
              code: code,
              wunschnummer: wunschnummer,
              csrf_token: csrfToken
            })
          })
          .then(res => res.json())
          .then(res => {
            if (res.success) {
              setAjaxAlert('success', 'Kasse', String(res.message || 'Als bezahlt markiert.'), [
                `Zeitstempel: ${String(res.bezahlt_am || '—')}`
              ]);
              if (isAutoScan) {
                cashCodeInput.value = '';
              }
              setTimeout(() => window.location.reload(), 1500);
            } else {
              setAjaxAlert('error', 'Kasse Fehler', String(res.error || 'Unbekannter Fehler.'));
            }
          })
          .catch(err => {
            setAjaxAlert('error', 'Kasse Fehler', 'Netzwerkfehler.');
          });
        }

        // @doc-note Startet die Suche bereits waehrend der Eingabe, sobald mindestens ein Zeichen vorliegt.
        if (cashCodeInput) {
          cashCodeInput.addEventListener('input', (e) => {
            const val = e.target.value;
            const norm = normalizeCode(val);
            if (norm.length >= 1) { 
              fetch(`admin.php?ajax_action=search&code=${encodeURIComponent(val)}`)
                .then(res => res.json())
                .then(res => {
                  if (res.success) {
                    renderCashResult(res.data);
                  } else {
                    setAjaxAlert('error', 'Kasse', String(res.error || 'Kein Ergebnis.'));
                  }
                })
                .catch(err => {
                  setAjaxAlert('error', 'Kasse', 'Netzwerkfehler.');
                });
            } else {
              ajaxResult.replaceChildren();
            }
          });
          
          // @doc-note Ein eigener Enter-Handler kann hier spaeter ergaenzt werden; derzeit bleibt der Fallback bewusst unveraendert.
        }

        // @doc-ui-flow cashier-qr-scan
        // @doc-ui-step 1 Kassenpersonal startet den QR-Scanner.
        // @doc-ui-step 2 Ein erkannter Code wird normalisiert, gesucht und bei gueltigem Treffer in den Zahlungsdialog uebernommen.
        // @doc-ui-result Der Bezahlvorgang kann direkt aus einem Scan heraus fortgesetzt werden.
        if (btnScanQr && qrContainer && btnStopScan) {
          const setQrContainerVisible = (visible) => {
            qrContainer.setAttribute('aria-hidden', visible ? 'false' : 'true');
          };

          const ensureQrScanner = () => {
            if (!html5QrcodeScanner) {
              html5QrcodeScanner = new Html5Qrcode('qr_reader');
            }
            return html5QrcodeScanner;
          };

          const getQrBoxSize = () => {
            const maxWidth = Math.min(window.innerWidth || 360, 360);
            const size = Math.max(180, Math.min(280, maxWidth - 80));
            return { width: size, height: size };
          };

          const findBackCameraId = async () => {
            if (typeof Html5Qrcode.getCameras !== 'function') {
              return null;
            }
            try {
              const cameras = await Html5Qrcode.getCameras();
              if (!Array.isArray(cameras) || cameras.length === 0) {
                return null;
              }
              const preferredCamera = cameras.find((camera) => /back|rear|environment/i.test(String(camera.label || '')));
              return String((preferredCamera || cameras[0]).id || '');
            } catch {
              return null;
            }
          };

          const startQrScanner = async () => {
            if (qrScannerActive) {
              return;
            }
            if (typeof Html5Qrcode !== 'function') {
              setQrContainerVisible(false);
              alert('QR-Scanner konnte nicht geladen werden.');
              return;
            }

            const scanner = ensureQrScanner();
            const config = { fps: 10, qrbox: getQrBoxSize() };
            const cameraCandidates = [
              { facingMode: { exact: 'environment' } },
              { facingMode: 'environment' },
            ];
            const backCameraId = await findBackCameraId();
            if (backCameraId) {
              cameraCandidates.unshift(backCameraId);
            }

            let lastError = null;
            for (const cameraConfig of cameraCandidates) {
              try {
                await scanner.start(
                  cameraConfig,
                  config,
                  (decodedText) => {
                    // @doc-note Nach erfolgreichem Scan wird die Kamera sofort gestoppt, um Doppeltrigger zu vermeiden.
                    stopQrScanner({ hide: true }).then(() => {
                      const norm = normalizeCode(decodedText);
                      if (norm.length < 10) {
                        setAjaxAlert('error', 'Kasse', 'Gescannter Code ungueltig.');
                        return;
                      }
                      cashCodeInput.value = decodedText;

                      // @doc-note Bei gueltigem Code wird der Teilnehmer geladen und direkt fuer den Zahlungsdialog vorbereitet.
                      fetch(`admin.php?ajax_action=search&code=${encodeURIComponent(decodedText)}`)
                        .then(res => res.json())
                        .then(res => {
                          if (res.success) {
                            const match = res.data[0];
                            if (parseInt(match.bezahlt) === 1) {
                              setAjaxAlert('error', 'Kasse', `Teilnehmer bereits bezahlt am ${String(match.bezahlt_am || '—')}`);
                            } else {
                              openAssignModal(match.code, `${match.vorname} ${match.nachname}`, match.suggested_startnummer, true);
                            }
                          } else {
                            setAjaxAlert('error', 'Kasse', 'Code nicht in Teilnehmerliste gefunden.');
                          }
                        })
                        .catch(() => {
                          setAjaxAlert('error', 'Kasse', 'Netzwerkfehler beim Scannen.');
                        });
                    }).catch(err => {
                      console.error('Failed to stop scanning', err);
                    });
                  },
                  () => {
                    // @doc-note Laufende Parse-Fehler werden waehrend des Scanvorgangs bewusst ignoriert.
                  }
                );
                qrScannerActive = true;
                return;
              } catch (err) {
                lastError = err;
              }
            }

            setQrContainerVisible(false);
            console.error('QR scanner start failed', lastError);
            alert('Kamera konnte nicht gestartet werden. Bitte Berechtigungen pruefen.');
          };

          const stopQrScanner = async ({ hide = true } = {}) => {
            if (!html5QrcodeScanner || !qrScannerActive) {
              if (hide) {
                setQrContainerVisible(false);
              }
              return;
            }
            try {
              await html5QrcodeScanner.stop();
            } catch (err) {
              console.error('Failed to stop scanning', err);
            } finally {
              qrScannerActive = false;
              try {
                await html5QrcodeScanner.clear();
              } catch {
              }
              html5QrcodeScanner = null;
              if (hide) {
                setQrContainerVisible(false);
              }
            }
          };

          btnScanQr.addEventListener('click', async () => {
            ajaxResult.replaceChildren();
            setQrContainerVisible(true);
            try {
              await startQrScanner();
            } catch (err) {
              console.error('QR scanner setup failed', err);
              await stopQrScanner({ hide: true });
            }
          });

          btnStopScan.addEventListener('click', async () => {
            await stopQrScanner({ hide: true });
          });

          if (btnCloseScan) {
            btnCloseScan.addEventListener('click', async () => {
              await stopQrScanner({ hide: true });
            });
          }

          qrContainer.addEventListener('click', async (e) => {
            if (e.target === qrContainer) {
              await stopQrScanner({ hide: true });
            }
          });

          document.addEventListener('keydown', async (e) => {
            if (e.key === 'Escape' && qrContainer.getAttribute('aria-hidden') === 'false') {
              await stopQrScanner({ hide: true });
            }
          });
        }

      });
    </script>
  </body>
</html>
