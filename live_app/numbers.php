<?php
/**
 * @doc-file
 * @doc-id live_app.numbers
 * @doc-kind page-controller
 * @doc-purpose Verwaltet Startnummern, Reservierungen und Zuweisungen für Teilnehmer und Teams.
 * @doc-entry HTTP-Aufruf durch berechtigte Benutzer.
 * @doc-input Session, Berechtigungen, Formularwerte und Datenbank.
 * @doc-output HTML oder Aktionsantworten für die Startnummernverwaltung.
 * @doc-side-effects Ändert Startnummernstatus und Teilnehmerzuordnungen in der Datenbank.
 */
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

$is_logged_in = auth_is_logged_in($pdo);
if (!$is_logged_in) {
    header('Location: admin.php');
    exit;
}
if (!auth_can($pdo, 'numbers.read')) {
    http_response_code(403);
    echo 'Zugriff verweigert.';
    exit;
}

$success_msg = '';
$error_msg = '';

$appEnv = getenv('APP_ENV');
if ($appEnv === false || $appEnv === '') {
    $appEnv = isset($_ENV['APP_ENV']) ? (string)$_ENV['APP_ENV'] : 'production';
}
$appEnv = strtolower(trim((string)$appEnv));
$isDev = in_array($appEnv, ['development', 'dev', 'local'], true);

// @doc-section numbers-actions
// @doc-purpose Verarbeitet Reservieren, Freigeben und direkte Zuweisung von Startnummern.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Sicherheitsprüfung fehlgeschlagen. Bitte erneut versuchen.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'reserve') {
            if (!auth_can($pdo, 'numbers.reserve')) {
                $error_msg = 'Zugriff verweigert.';
            } else {
            $start = (int)($_POST['start'] ?? 0);
            $end = (int)($_POST['end'] ?? 0);
            $name = trim($_POST['reserviert_fuer'] ?? '');
            $teamname = $name !== '' ? team_name_normalize($name) : null;
            $team_id = $teamname !== null ? team_get_or_create_id($pdo, $teamname) : null;

            if ($start < 1 || $end > 999 || $start > $end || $teamname === null || $team_id === null) {
                $error_msg = 'Ungültige Eingabe für Reservierung.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare("INSERT IGNORE INTO startnummern (nummer, status) VALUES (?, 'frei')");
                    for ($i = $start; $i <= $end; $i++) {
                        $ins->execute([$i]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
                // @doc-note Prüft, ob im gewählten Bereich bereits reservierte oder vergebene Nummern liegen.
                $check = $pdo->prepare("SELECT COUNT(*) FROM startnummern WHERE nummer BETWEEN ? AND ? AND status != 'frei'");
                $check->execute([$start, $end]);
                if ($check->fetchColumn() > 0) {
                    $error_msg = 'Einige Nummern in diesem Bereich sind bereits reserviert oder vergeben.';
                } else {
                    $upd = $pdo->prepare("UPDATE startnummern SET status = 'reserviert', reserviert_fuer = ?, team_id = ? WHERE nummer BETWEEN ? AND ?");
                    $upd->execute([$teamname, $team_id, $start, $end]);
                    $success_msg = "Nummern $start bis $end erfolgreich für '$teamname' reserviert.";
                }
            }
            }
        } elseif ($action === 'free') {
            if (!auth_can($pdo, 'numbers.unassign')) {
                $error_msg = 'Zugriff verweigert.';
            } else {
            $num = (int)($_POST['nummer'] ?? 0);
            if ($num >= 1 && $num <= 999) {
                // @doc-note Gibt die Nummer frei und entfernt vorhandene Teilnehmerzuordnungen konsistent mit.
                $pdo->beginTransaction();
                
                // Zuerst Teilnehmer_id finden, um startnummer in teilnehmer zu nullen
                $find = $pdo->prepare("SELECT teilnehmer_id FROM startnummern WHERE nummer = ?");
                $find->execute([$num]);
                $t_id = $find->fetchColumn();

                if (!$t_id) {
                    $find2 = $pdo->prepare("SELECT id FROM teilnehmer WHERE startnummer = ? LIMIT 1");
                    $find2->execute([$num]);
                    $t_id = $find2->fetchColumn();
                }
                
                if ($t_id) {
                    $updT = $pdo->prepare("UPDATE teilnehmer SET startnummer = NULL WHERE id = ?");
                    $updT->execute([$t_id]);
                }
                
                $updS = $pdo->prepare("UPDATE startnummern SET status = 'frei', reserviert_fuer = NULL, team_id = NULL, teilnehmer_id = NULL WHERE nummer = ?");
                $updS->execute([$num]);
                
                $pdo->commit();
                $success_msg = "Startnummer $num wurde freigegeben.";
            }
            }
        } elseif ($action === 'assign') {
            if (!auth_can($pdo, 'numbers.assign')) {
                $error_msg = 'Zugriff verweigert.';
            } else {
            $num = (int)($_POST['nummer'] ?? 0);
            $t_id = (int)($_POST['t_id'] ?? 0);
            if ($num >= 1 && $num <= 999 && $t_id > 0) {
                $pdo->beginTransaction();

                $ins = $pdo->prepare("INSERT IGNORE INTO startnummern (nummer, status) VALUES (?, 'frei')");
                $ins->execute([$num]);

                $find = $pdo->prepare("SELECT startnummer FROM teilnehmer WHERE id = ?");
                $find->execute([$t_id]);
                $old_num = $find->fetchColumn();

                if ($old_num) {
                    $updOld = $pdo->prepare("UPDATE startnummern SET status = 'frei', reserviert_fuer = NULL, team_id = NULL, teilnehmer_id = NULL WHERE nummer = ?");
                    $updOld->execute([$old_num]);
                }

                $updNew = $pdo->prepare("UPDATE startnummern SET status = 'vergeben', teilnehmer_id = ?, reserviert_fuer = NULL, team_id = NULL WHERE nummer = ?");
                $updNew->execute([$t_id, $num]);

                // @doc-note Eine manuelle Nummernzuweisung markiert offene Teilnehmer zugleich als bezahlt.
                $updT = $pdo->prepare("UPDATE teilnehmer SET startnummer = ?, bezahlt = 1, bezahlt_am = COALESCE(bezahlt_am, NOW()) WHERE id = ?");
                $updT->execute([$num, $t_id]);

                $pdo->commit();
                $success_msg = "Startnummer $num wurde erfolgreich zugewiesen.";
            }
            }
        }
    }
}

$initThrottle = $_SESSION['startnummern_init_attempted_at'] ?? null;
$initAllowed = !is_int($initThrottle) || (time() - $initThrottle) > 300;

try {
    $stmtCount = $pdo->query('SELECT COUNT(*) FROM startnummern');
    $count = (int)$stmtCount->fetchColumn();
    if ($count !== 999 && $initAllowed) {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT IGNORE INTO startnummern (nummer, status) VALUES (?, 'frei')");
        for ($i = 1; $i <= 999; $i++) {
            $ins->execute([$i]);
        }
        $pdo->commit();
        $_SESSION['startnummern_init_attempted_at'] = time();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['startnummern_init_attempted_at'] = time();
}

try {
    $pdo->exec("
        UPDATE startnummern s
        INNER JOIN teilnehmer t ON t.startnummer = s.nummer
        SET s.status = 'vergeben', s.teilnehmer_id = t.id, s.reserviert_fuer = NULL, s.team_id = NULL
        WHERE t.startnummer IS NOT NULL AND t.startnummer <> 0
          AND (s.teilnehmer_id IS NULL OR s.teilnehmer_id = 0)
    ");
} catch (Throwable $e) {
}

// @doc-section numbers-data-loading
// @doc-purpose Lädt Nummernbestand, Teamreservierungen und freie Zuordnungsdaten für die Seite.
$numbers = [];
try {
    $stmt = $pdo->query("
        SELECT
            s.nummer,
            CASE
                WHEN s.status = 'vergeben' THEN 'vergeben'
                WHEN s.status = 'reserviert' THEN 'reserviert'
                WHEN t.id IS NOT NULL THEN 'vergeben'
                ELSE 'frei'
            END AS status,
            COALESCE(te.name, s.reserviert_fuer) AS reserviert_fuer,
            COALESCE(s.teilnehmer_id, t.id) AS teilnehmer_id,
            t.vorname,
            t.nachname
        FROM startnummern s
        LEFT JOIN teams te ON te.id = s.team_id
        LEFT JOIN teilnehmer t
          ON t.id = s.teilnehmer_id
          OR (s.teilnehmer_id IS NULL AND t.startnummer = s.nummer)
        ORDER BY s.nummer ASC
    ");
    $numbers = $stmt->fetchAll();
} catch (Throwable $e) {
    $error_msg = $isDev ? ('Startnummern konnten nicht geladen werden: ' . $e->getMessage()) : 'Startnummern konnten nicht geladen werden.';
}

// @doc-note Lädt nur Teilnehmer ohne Startnummer für die direkte Zuweisungsmaske.
$teilnehmer_list = [];
try {
    $stmt_t = $pdo->query("
        SELECT
            t.id,
            t.vorname,
            t.nachname,
            te.name AS teamname,
            t.startnummer,
            t.bezahlt
        FROM teilnehmer t
        LEFT JOIN teams te ON te.id = t.team_id
        WHERE t.startnummer IS NULL OR t.startnummer = 0
        ORDER BY t.nachname, t.vorname
    ");
    $teilnehmer_list = $stmt_t->fetchAll();
} catch (Throwable $e) {
    $error_msg = $error_msg !== '' ? $error_msg : ($isDev ? ('Teilnehmerliste konnte nicht geladen werden: ' . $e->getMessage()) : 'Teilnehmerliste konnte nicht geladen werden.');
}

$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$assetBase = $assetBase === '' ? '' : $assetBase;
$can_reserve = auth_can($pdo, 'numbers.reserve');
$can_assign = auth_can($pdo, 'numbers.assign');
$can_unassign = auth_can($pdo, 'numbers.unassign');
$numbers_interactive = $can_assign || $can_unassign;
$current_user = auth_current_user($pdo);
$current_username = (string)($current_user['username'] ?? ($_SESSION['username'] ?? 'Admin'));
$current_role = (string)($current_user['role'] ?? ($_SESSION['role'] ?? 'admin'));
$can_dashboard_page = auth_can($pdo, 'participants.read');
$can_export_page = auth_can($pdo, 'exports.participants');
$is_standalone = isset($_GET['standalone']) && (string)$_GET['standalone'] === '1';
?>
<!doctype html>
<html lang="de" data-theme="light">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Startnummern – 24‑Stunden‑Schwimmen (DLRG Sankt Augustin)</title>
    <link rel="stylesheet" href="<?php echo escape($assetBase . '/assets/style.css'); ?>?v=<?php echo escape(app_asset_version('assets/style.css')); ?>" />
    <meta name="csrf-token" content="<?php echo escape(csrf_token_get()); ?>" />
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
    <style>
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
        html[data-theme="dark"] input[type="text"],
        html[data-theme="dark"] input[type="number"],
        html[data-theme="dark"] select {
            background: #0f131a;
            color: var(--text);
            border-color: var(--border);
        }
        html[data-theme="dark"] tr:nth-child(even) td {
            background: rgba(255, 255, 255, 0.03);
        }
        html[data-theme="dark"] .admin-session-info,
        html[data-theme="dark"] .filter-bar,
        html[data-theme="dark"] .filter-legend-item {
            background: #141a24;
            color: var(--text);
        }
        html[data-theme="dark"] .admin-session-badge {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.08);
            color: var(--text);
        }
        html[data-theme="dark"] .numbers-page-subtitle,
        html[data-theme="dark"] .assign-help,
        html[data-theme="dark"] .filter-legend,
        html[data-theme="dark"] .num-box .info {
            color: var(--muted);
        }
        html[data-theme="dark"] .num-box {
            background: #10151f;
            border-color: var(--border);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.18);
        }
        html[data-theme="dark"] .num-box.status-frei {
            background: linear-gradient(180deg, rgba(40, 167, 69, 0.20) 0%, rgba(16, 21, 31, 0.96) 100%);
            border-left-color: #4fd17b;
            box-shadow: inset 0 0 0 1px rgba(79, 209, 123, 0.12), 0 1px 2px rgba(0, 0, 0, 0.18);
        }
        html[data-theme="dark"] .num-box.status-reserviert {
            background: linear-gradient(180deg, rgba(255, 237, 0, 0.24) 0%, rgba(16, 21, 31, 0.96) 100%);
            border-left-color: #ffed00;
            box-shadow: inset 0 0 0 1px rgba(255, 237, 0, 0.10), 0 1px 2px rgba(0, 0, 0, 0.18);
        }
        html[data-theme="dark"] .num-box.status-vergeben {
            background: linear-gradient(180deg, rgba(227, 6, 19, 0.28) 0%, rgba(16, 21, 31, 0.96) 100%);
            border-left-color: #ff5c69;
            box-shadow: inset 0 0 0 1px rgba(255, 92, 105, 0.12), 0 1px 2px rgba(0, 0, 0, 0.18);
        }
        html[data-theme="dark"] .num-box .number,
        html[data-theme="dark"] .filter-legend-item,
        html[data-theme="dark"] .modal-close {
            color: var(--text);
        }
        body {
            background:
                radial-gradient(900px 500px at 20% 10%, rgba(255, 237, 0, 0.24), transparent 60%),
                radial-gradient(900px 500px at 80% 10%, rgba(5, 105, 180, 0.14), transparent 62%),
                linear-gradient(180deg, #8c0710 0%, #e30613 48%, #e30613 100%);
        }
        .numbers-topbar {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            justify-content: space-between;
            align-items: flex-start;
        }
        .numbers-topbar-copy {
            flex: 1 1 420px;
            max-width: 820px;
        }
        .numbers-page-title {
            margin: 0;
            font-size: 30px;
            line-height: 1.04;
            letter-spacing: -0.02em;
        }
        .numbers-page-subtitle {
            margin: 6px 0 0;
            color: #5a6570;
            font-size: 15px;
            line-height: 1.5;
        }
        .numbers-topbar-actions,
        .numbers-topbar-left {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .numbers-topbar-actions {
            margin-left: auto;
            justify-content: flex-end;
        }
        .numbers-theme-toggle {
            width: 100%;
        }
        .numbers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
            gap: 10px;
            margin-top: 16px;
        }
        .num-box {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 10px;
            text-align: center;
            background: #fff;
            position: relative;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease;
        }
        .num-box:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(0, 0, 0, 0.08);
        }
        .num-box.readonly {
            cursor: default;
        }
        .num-box.readonly:hover {
            transform: none;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        .num-box.status-frei {
            border-left: 5px solid #28a745; /* Bootstrap Grün */
            background: linear-gradient(180deg, #f2fcf5 0%, #ffffff 100%);
        }
        .num-box.status-reserviert {
            border-left: 5px solid #ffed00; /* DLRG Gelb */
            background: linear-gradient(180deg, #fff9cc 0%, #fffdf0 100%);
        }
        .num-box.status-vergeben {
            border-left: 5px solid #e30613; /* DLRG Rot */
            background: linear-gradient(180deg, #ffd9dd 0%, #fdf0f0 100%);
        }
        .num-box .number {
            font-size: 1.5em;
            font-weight: bold;
            color: #575756; /* DLRG Tiefschwarz */
            line-height: 1.1;
        }
        .num-box .info {
            font-size: 13px;
            margin-top: 7px;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Modal Styles */
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            align-items: center;
            flex-wrap: wrap;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f7f8fb;
        }

        #statusFilter {
            flex: 0 0 auto;
            min-width: 180px;
        }

        #searchFilter {
            flex: 1 1 260px;
            min-width: 220px;
            width: auto;
        }

        .filter-legend {
            margin-left: auto;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
            font-size: 14px;
        }

        .filter-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid var(--border);
        }

        .filter-legend-swatch {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            display: inline-block;
        }

        @media (max-width: 720px) {
            .filter-legend {
                margin-left: 0;
                justify-content: flex-start;
            }
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .tab-btn {
            min-width: 124px;
        }

        .assign-help {
            margin: 0 0 8px 0;
            font-size: 0.92em;
            color: #5a6570;
        }

        #t_search {
            margin-bottom: 8px !important;
        }

        #t_select {
            min-height: 320px;
            height: auto;
            font-size: 15px;
            line-height: 1.35;
            padding-top: 8px;
            padding-bottom: 8px;
        }

        @media (max-width: 720px) {
            #t_select {
                min-height: 260px;
            }

            .numbers-topbar,
            .numbers-topbar-left,
            .numbers-topbar-actions,
            .reserve-form-row {
                flex-direction: column;
                align-items: stretch;
            }

            .numbers-topbar .btn,
            .numbers-topbar-left .btn,
            .numbers-topbar-action,
            .filter-bar > label,
            #statusFilter,
            #searchFilter,
            .tab-btn,
            #m_actions .btn {
                width: 100%;
            }

            .numbers-topbar-copy {
                max-width: none;
            }

            .numbers-topbar-action {
                min-width: 0 !important;
            }

            .filter-bar {
                gap: 10px;
            }

            .filter-legend {
                width: 100%;
            }

            .tabs {
                gap: 8px;
            }
        }
    </style>
  </head>
  <body>
    <div class="page">
      <div class="container">
        <header class="reg-header">
          <!-- @doc-note Das gemeinsame Logo hält die Unterseite visuell im selben Anwendungskontext. -->
          <img src="<?php echo escape($assetBase . '/assets/logo.png'); ?>" alt="DLRG Sankt Augustin e.V." class="reg-logo" width="1714" height="332" decoding="async" />
          <h1 class="reg-title">
            <span>Admin</span>
            <span>Startnummern-Verwaltung</span>
            <span>Reservieren, filtern und vergeben</span>
          </h1>
        </header>

        <div class="page-shell<?php echo $is_standalone ? ' page-shell-standalone' : ''; ?>">
          <?php if (!$is_standalone): ?>
          <aside class="page-sidebar">
            <div class="panel sidebar-panel">
              <div class="sidebar-copy">
                <p class="sidebar-label">Startnummern</p>
                <h2 class="sidebar-title">Startnummern verwalten</h2>
                <p class="sidebar-subtitle">Reservieren, filtern und bestehende Zuweisungen schneller prüfen, ohne wieder an den Seitenanfang zu springen.</p>
              </div>
              <div class="admin-session-info">
                <span>Angemeldet als: <strong><?php echo escape($current_username); ?></strong></span>
                <span class="admin-session-badge"><?php echo escape($current_role); ?></span>
              </div>
              <div class="sidebar-links">
                <?php if ($can_reserve): ?>
                  <button class="btn btn-primary numbers-topbar-action" onclick="document.getElementById('modalReserve').setAttribute('aria-hidden', 'false')" title="Öffnet den Dialog zum Reservieren von Startnummernblöcken">+ Block / Nummer reservieren</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary numbers-theme-toggle" id="theme_toggle" aria-pressed="false" title="Wechselt zwischen hellem und dunklem Design">Dunkelmodus</button>
                <a class="btn btn-secondary" href="<?php echo escape($assetBase . '/admin.php'); ?>" title="Wechselt zur Admin-Oberfläche">Admin</a>
                <?php if ($can_dashboard_page): ?>
                  <a class="btn btn-secondary" href="dashboard/dashboard.php" title="Öffnet das Live-Dashboard">Dashboard</a>
                <?php endif; ?>
                <?php if ($can_export_page): ?>
                  <a class="btn btn-secondary" href="export.php" title="Öffnet den Datenexport">Datenexport</a>
                <?php endif; ?>
                <form method="post" action="admin.php" style="display:inline;"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>"><button type="submit" class="btn btn-secondary" title="Von der Startnummernverwaltung abmelden">Abmelden</button></form>
              </div>
            </div>
          </aside>
          <?php endif; ?>

          <div class="page-main">
        <?php if ($error_msg !== ''): ?>
          <div class="alert alert-error"><strong>Fehler:</strong> <?php echo escape($error_msg); ?></div>
        <?php endif; ?>
        <?php if ($success_msg !== ''): ?>
          <div class="alert alert-success"><strong>Erfolg:</strong> <?php echo escape($success_msg); ?></div>
        <?php endif; ?>

        <div class="panel">
            <div class="tabs">
                <button class="btn btn-primary tab-btn" onclick="switchTab(0)" title="Zeigt die Startnummern 001 bis 200">001 - 200</button>
                <button class="btn btn-secondary tab-btn" onclick="switchTab(1)" title="Zeigt die Startnummern 201 bis 400">201 - 400</button>
                <button class="btn btn-secondary tab-btn" onclick="switchTab(2)" title="Zeigt die Startnummern 401 bis 600">401 - 600</button>
                <button class="btn btn-secondary tab-btn" onclick="switchTab(3)" title="Zeigt die Startnummern 601 bis 800">601 - 800</button>
                <button class="btn btn-secondary tab-btn" onclick="switchTab(4)" title="Zeigt die Startnummern 801 bis 999">801 - 999</button>
            </div>
            
            <div class="filter-bar">
                <label><strong>Filter:</strong></label>
                <select id="statusFilter" onchange="applyFilter()">
                    <option value="all">Alle anzeigen</option>
                    <option value="frei">Nur Freie</option>
                    <option value="reserviert">Nur Reservierte</option>
                    <option value="vergeben">Nur Vergebene</option>
                </select>
                <input type="text" id="searchFilter" onkeyup="applyFilter()" placeholder="Suche (Team, Name, Nr.)" />
                <div class="filter-legend">
                    <span class="filter-legend-item"><span class="filter-legend-swatch" style="background:#28a745;"></span>Frei</span>
                    <span class="filter-legend-item"><span class="filter-legend-swatch" style="background:#ffed00;"></span>Reserviert</span>
                    <span class="filter-legend-item"><span class="filter-legend-swatch" style="background:#e30613;"></span>Vergeben</span>
                </div>
            </div>

            <div class="numbers-grid" id="numbersGrid">
                <?php foreach ($numbers as $n): 
                    $numStr = str_pad((string)$n['nummer'], 3, '0', STR_PAD_LEFT);
                    $info = '';
                    if ($n['status'] === 'reserviert') {
                        $info = $n['reserviert_fuer'];
                    } elseif ($n['status'] === 'vergeben') {
                        $info = $n['vorname'] . ' ' . $n['nachname'];
                    }
                ?>
                <div class="num-box status-<?php echo $n['status']; ?><?php echo $numbers_interactive ? '' : ' readonly'; ?>" 
                     data-status="<?php echo $n['status']; ?>"
                     data-info="<?php echo strtolower(escape($info)); ?>"
                     data-num="<?php echo $n['nummer']; ?>"
                     <?php
                        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;
                        echo $numbers_interactive ? ("onclick='openNumModal(" . (int)$n['nummer'] . ", " . json_encode((string)$n['status'], $jsonFlags) . ", " . json_encode($info, $jsonFlags) . ")'") : '';
                     ?>>
                    <div class="number"><?php echo $numStr; ?></div>
                    <div class="info" title="<?php echo escape($info); ?>"><?php echo $info === '' ? 'Frei' : escape($info); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>
        </div>
      </div>
    </div>

    <!-- Modal für Block-Reservierung -->
    <div id="modalReserve" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
      <div class="modal-panel" style="max-width: 500px;">
        <div class="modal-header">
          <h2 class="modal-title">Nummern reservieren</h2>
          <button type="button" class="modal-close" onclick="document.getElementById('modalReserve').setAttribute('aria-hidden', 'true')" aria-label="Schließen" title="Schließt den Reservierungsdialog">&times;</button>
        </div>
        <div style="margin-top: 16px;">
          <p class="muted">Geben Sie einen Bereich ein. Für eine einzelne Nummer geben Sie bei Start und Ende dieselbe Zahl ein.</p>
          <form method="post" action="numbers.php" class="reg-form" style="margin-top:15px;">
              <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
              <input type="hidden" name="action" value="reserve" />
              <div class="reserve-form-row" style="display:flex; gap:10px;">
                  <div class="field" style="flex:1;">
                      <label>Von Nummer</label>
                      <input type="number" name="start" min="1" max="999" required />
                  </div>
                  <div class="field" style="flex:1;">
                      <label>Bis Nummer</label>
                      <input type="number" name="end" min="1" max="999" required />
                  </div>
              </div>
              <div class="field">
                  <label>Reserviert für (Teamname / Person)</label>
                  <input type="text" name="reserviert_fuer" required />
              </div>
              <div class="actions">
                  <button class="btn btn-primary" type="submit" title="Reserviert den eingegebenen Startnummernbereich">Reservieren</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal für Einzel-Nummer Aktion (Freigeben/Zuweisen) -->
    <div id="modalNum" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
      <div class="modal-panel" style="max-width: 680px;">
        <div class="modal-header">
          <h2 class="modal-title">Startnummer <span id="m_num"></span></h2>
          <button type="button" class="modal-close" onclick="document.getElementById('modalNum').setAttribute('aria-hidden', 'true')" aria-label="Schließen" title="Schließt den Startnummerndialog">&times;</button>
        </div>
        <div style="margin-top: 16px;">
          <div id="m_info_box" style="margin-bottom: 15px; font-size: 1.1em;"></div>
          
          <form method="post" action="numbers.php" class="reg-form" id="freeForm">
              <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />
              <input type="hidden" name="action" id="m_action_input" value="free" />
              <input type="hidden" name="nummer" id="m_num_input" value="" />
              
              <div id="m_assign_box" style="display:none; margin-bottom: 15px;">
                  <label><strong>Teilnehmer zuweisen:</strong></label>
                  <p class="assign-help">Es werden nur Teilnehmer ohne vorhandene Startnummer angezeigt.</p>
                  <input type="text" id="t_search" placeholder="Name suchen..." onkeyup="filterTeilnehmer()" class="field" style="width:100%; padding:5px; margin-bottom:5px; box-sizing:border-box;">
                  <select name="t_id" id="t_select" size="12" class="field" style="width:100%; box-sizing:border-box;">
                      <option value="">-- Bitte wählen --</option>
                      <?php foreach($teilnehmer_list as $t): ?>
                          <option value="<?php echo $t['id']; ?>"><?php echo escape($t['nachname'] . ', ' . $t['vorname'] . ($t['teamname'] ? ' | Team: ' . $t['teamname'] : ' | Einzel') . ' | ' . ((int)($t['bezahlt'] ?? 0) === 1 ? 'bezahlt' : 'offen')); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>

              <div class="actions" id="m_actions">
                  <button class="btn btn-yellow" type="submit" id="btn_free" title="Gibt die aktuelle Startnummer wieder frei (entfernt die Reservierung)">Nummer freigeben</button>
                  <button class="btn btn-primary" type="submit" id="btn_assign" style="display:none;" title="Weist die Startnummer dem ausgewählten Teilnehmer zu">Nummer zuweisen</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <script>
        const themeToggleBtn = document.getElementById('theme_toggle');
        const themeKey = 'admin_theme';
        const CAN_ASSIGN = <?php echo $can_assign ? 'true' : 'false'; ?>;
        const CAN_UNASSIGN = <?php echo $can_unassign ? 'true' : 'false'; ?>;

        let currentTab = 0;

        function applyTheme(theme, persist) {
            const next = theme === 'dark' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            if (persist) {
                try {
                    localStorage.setItem(themeKey, next);
                } catch (e) {}
            }
            if (themeToggleBtn) {
                themeToggleBtn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
                themeToggleBtn.textContent = next === 'dark' ? 'Hellmodus' : 'Dunkelmodus';
            }
        }

        function switchTab(index) {
            currentTab = index;
            sessionStorage.setItem('num_currentTab', currentTab);
            document.querySelectorAll('.tab-btn').forEach((btn, i) => {
                if (i === currentTab) {
                    btn.classList.remove('btn-secondary');
                    btn.classList.add('btn-primary');
                } else {
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-secondary');
                }
            });
            applyFilter();
        }

        function applyFilter() {
            const status = document.getElementById('statusFilter').value;
            const search = document.getElementById('searchFilter').value.toLowerCase();
            
            sessionStorage.setItem('num_statusFilter', status);
            sessionStorage.setItem('num_searchFilter', search);

            const boxes = document.querySelectorAll('.num-box');

            const tabRanges = [
                { min: 1, max: 200 },
                { min: 201, max: 400 },
                { min: 401, max: 600 },
                { min: 601, max: 800 },
                { min: 801, max: 999 }
            ];
            const range = tabRanges[currentTab] || tabRanges[0];

            boxes.forEach(box => {
                const bStatus = box.getAttribute('data-status');
                const bInfo = box.getAttribute('data-info');
                const bNumStr = box.getAttribute('data-num');
                const bNum = parseInt(bNumStr);
                
                let matchStatus = (status === 'all' || status === bStatus);
                let matchSearch = (search === '' || bInfo.includes(search) || bNumStr.includes(search));
                let matchTab = (bNum >= range.min && bNum <= range.max);
                
                // @doc-note Bei aktiver Suche wird der Tab-Filter bewusst aufgehoben, damit Treffer global sichtbar bleiben.
                if (search !== '') {
                    matchTab = true;
                }

                if (matchStatus && matchSearch && matchTab) {
                    box.style.display = 'block';
                } else {
                    box.style.display = 'none';
                }
            });
        }

        function openNumModal(num, status, info) {
            document.getElementById('m_num').innerText = String(num).padStart(3, '0');
            document.getElementById('m_num_input').value = num;
            
            let infoBox = document.getElementById('m_info_box');
            let actionsBox = document.getElementById('m_actions');
            let assignBox = document.getElementById('m_assign_box');
            let btnFree = document.getElementById('btn_free');
            let btnAssign = document.getElementById('btn_assign');
            let actionInput = document.getElementById('m_action_input');
            let tSelect = document.getElementById('t_select');
            
            // Zurücksetzen
            tSelect.value = "";
            tSelect.required = false;
            document.getElementById('t_search').value = "";
            filterTeilnehmer();

            btnFree.onclick = null;
            btnAssign.onclick = null;
            btnFree.style.display = 'none';
            btnAssign.style.display = 'none';
            assignBox.style.display = 'none';
            actionsBox.style.display = 'none';

            function renderInfo(lines) {
                infoBox.replaceChildren();
                lines.forEach(function(line, index) {
                    var strong = document.createElement('strong');
                    strong.textContent = line.label + ':';
                    infoBox.appendChild(strong);
                    infoBox.appendChild(document.createTextNode(' ' + line.value));
                    if (index < lines.length - 1) {
                        infoBox.appendChild(document.createElement('br'));
                    }
                });
            }
            
            if (status === 'frei') {
                renderInfo([{ label: 'Status', value: 'Frei' }]);
                if (CAN_ASSIGN) {
                    actionsBox.style.display = 'block';
                    assignBox.style.display = 'block';
                    btnAssign.style.display = 'block';
                    actionInput.value = 'assign';
                    tSelect.required = true;
                    btnAssign.onclick = function() {
                        actionInput.value = 'assign';
                        tSelect.required = true;
                    };
                }
            } else if (status === 'reserviert') {
                renderInfo([
                    { label: 'Status', value: 'Reserviert' },
                    { label: 'Für', value: String(info || '') }
                ]);
                if (CAN_ASSIGN) {
                    actionsBox.style.display = 'block';
                    assignBox.style.display = 'block';
                    btnAssign.style.display = 'inline-block';
                    actionInput.value = 'assign';
                    tSelect.required = true;
                    btnAssign.onclick = function() {
                        actionInput.value = 'assign';
                        tSelect.required = true;
                    };
                }
                if (CAN_UNASSIGN) {
                    actionsBox.style.display = 'block';
                    btnFree.style.display = 'inline-block';
                    btnFree.onclick = function() {
                        actionInput.value = 'free';
                        tSelect.required = false;
                        return confirm('Wirklich freigeben?');
                    };
                }
            } else if (status === 'vergeben') {
                renderInfo([
                    { label: 'Status', value: 'Vergeben' },
                    { label: 'An', value: String(info || '') }
                ]);
                if (CAN_UNASSIGN) {
                    actionsBox.style.display = 'block';
                    btnFree.style.display = 'block';
                    actionInput.value = 'free';
                    btnFree.onclick = function() {
                        actionInput.value = 'free';
                        return confirm('Wirklich freigeben? Wenn diese Nummer einem Teilnehmer zugeordnet war, verliert er sie.');
                    };
                }
            }
            
            document.getElementById('modalNum').setAttribute('aria-hidden', 'false');
        }

        function filterTeilnehmer() {
            var search = document.getElementById('t_search').value.toLowerCase();
            var options = document.getElementById('t_select').options;
            for (var i = 1; i < options.length; i++) { // Skip placeholder (index 0)
                var text = options[i].text.toLowerCase();
                options[i].style.display = text.includes(search) ? '' : 'none';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedStatus = sessionStorage.getItem('num_statusFilter');
            const savedSearch = sessionStorage.getItem('num_searchFilter');
            const savedTab = sessionStorage.getItem('num_currentTab');
            const initialTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            
            if (savedStatus) document.getElementById('statusFilter').value = savedStatus;
            if (savedSearch) document.getElementById('searchFilter').value = savedSearch;
            
            if (savedTab !== null) {
                switchTab(parseInt(savedTab));
            } else {
                switchTab(0);
            }

            applyTheme(initialTheme, false);

            if (themeToggleBtn) {
                themeToggleBtn.addEventListener('click', () => {
                    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    applyTheme(current === 'dark' ? 'light' : 'dark', true);
                });
            }

            // @doc-note Ein Doppelklick auf den Teilnehmer-Select übernimmt die Auswahl direkt in die Zuweisung.
            document.getElementById('t_select').addEventListener('dblclick', function() {
                if (this.value) {
                    document.getElementById('btn_assign').click();
                }
            });
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.setAttribute('aria-hidden', 'true');
            }
        }
    </script>
    <script src="<?php echo escape($assetBase . '/assets/app.js'); ?>?v=<?php echo escape(app_asset_version('assets/app.js')); ?>"></script>
  </body>
</html>
