<?php
/**
 * @doc-file
 * @doc-id live_app.timeframes
 * @doc-kind page-controller
 * @doc-purpose Pflegt Zeitfenster fuer spaetere Live-Auswertungen nach Abschnitt oder Schicht.
 * @doc-entry HTTP-Aufruf durch berechtigte Benutzer.
 * @doc-input Session, Berechtigungen, Formularwerte und Datenbank.
 * @doc-output HTML oder Aktionsantworten fuer Zeitfenster.
 * @doc-side-effects Legt Zeitfenster an, aendert sie oder deaktiviert sie.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

$current_user = auth_current_user($pdo);
$is_logged_in = $current_user !== null;
if (!$is_logged_in) {
    header('Location: admin.php');
    exit;
}
if (!auth_can($pdo, 'timeframes.manage')) {
    http_response_code(403);
    echo 'Zugriff verweigert.';
    exit;
}

$success_msg = '';
$error_msg = '';

if (isset($_GET['format']) && (string)$_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $onlyActive = isset($_GET['active']) ? (string)$_GET['active'] !== '0' : true;
    $sql = 'SELECT id, name, start_at, end_at, active, sort_order FROM lane_timeframes';
    $params = [];
    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, start_at ASC, end_at ASC, id ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['success' => true, 'timeframes' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Zeitfenster konnten nicht geladen werden.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function tf_dt_local_date(?string $dbValue): string
{
    if (!is_string($dbValue) || trim($dbValue) === '') {
        return '';
    }
    $ts = strtotime($dbValue);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d', $ts);
}

function tf_dt_local_time(?string $dbValue): string
{
    if (!is_string($dbValue) || trim($dbValue) === '') {
        return '';
    }
    $ts = strtotime($dbValue);
    if ($ts === false) {
        return '';
    }
    return date('H:i', $ts);
}

function tf_dt_display(?string $dbValue): string
{
    if (!is_string($dbValue) || trim($dbValue) === '') {
        return '-';
    }
    $ts = strtotime($dbValue);
    if ($ts === false) {
        return $dbValue;
    }
    return date('d.m.Y H:i', $ts);
}

function tf_dt_db(string $dateValue, string $timeValue, bool $isEnd = false): ?string
{
    $dateValue = trim($dateValue);
    $timeValue = trim($timeValue);
    if ($dateValue === '' || $timeValue === '') {
        return null;
    }
    $ts = strtotime($dateValue . ' ' . $timeValue . ':00');
    if ($ts === false) {
        return null;
    }
    if ($isEnd) {
        $ts += 59;
    }
    return date('Y-m-d H:i:s', $ts);
}

function tf_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tf_row_overlap(array $rows): array
{
    $active = array_values(array_filter($rows, static function ($row): bool {
        return (int)($row['active'] ?? 0) === 1;
    }));
    $ranges = [];
    foreach ($active as $row) {
        $startTs = strtotime((string)($row['start_at'] ?? ''));
        $endTs = strtotime((string)($row['end_at'] ?? ''));
        if ($startTs === false || $endTs === false) {
            continue;
        }
        $ranges[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'start' => $startTs,
            'end' => $endTs,
        ];
    }
    usort($ranges, static function (array $a, array $b): int {
        return ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']);
    });

    $overlaps = [];
    for ($i = 0; $i < count($ranges); $i++) {
        for ($j = $i + 1; $j < count($ranges); $j++) {
            if ($ranges[$j]['start'] >= $ranges[$i]['end']) {
                break;
            }
            $overlaps[] = [$ranges[$i], $ranges[$j]];
        }
    }
    return $overlaps;
}

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_row = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Sicherheitsprüfung fehlgeschlagen. Bitte erneut versuchen.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $start_at = tf_dt_db((string)($_POST['start_date'] ?? ''), (string)($_POST['start_time'] ?? ''));
            $end_at = tf_dt_db((string)($_POST['end_date'] ?? ''), (string)($_POST['end_time'] ?? ''), true);
            $active = isset($_POST['active']) ? 1 : 0;
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if ($name === '' || mb_strlen($name) > 100) {
                $error_msg = 'Bitte einen Namen (max. 100 Zeichen) angeben.';
            } elseif ($start_at === null || $end_at === null) {
                $error_msg = 'Bitte Start und Ende gueltig angeben.';
            } elseif (strtotime($start_at) === false || strtotime($end_at) === false || strtotime($start_at) >= strtotime($end_at)) {
                $error_msg = 'Start muss vor Ende liegen.';
            } else {
                try {
                    if ($id > 0) {
                        $stmt = $pdo->prepare('UPDATE lane_timeframes SET name = ?, start_at = ?, end_at = ?, active = ?, sort_order = ? WHERE id = ?');
                        $stmt->execute([$name, $start_at, $end_at, $active, $sort_order, $id]);
                        $success_msg = 'Zeitfenster gespeichert.';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO lane_timeframes (name, start_at, end_at, active, sort_order) VALUES (?, ?, ?, ?, ?)');
                        $stmt->execute([$name, $start_at, $end_at, $active, $sort_order]);
                        $success_msg = 'Zeitfenster angelegt.';
                    }
                    $edit_id = 0;
                } catch (Throwable $e) {
                    $error_msg = 'Speichern fehlgeschlagen.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error_msg = 'Ungültige ID.';
            } else {
                try {
                    $stmt = $pdo->prepare('DELETE FROM lane_timeframes WHERE id = ? LIMIT 1');
                    $stmt->execute([$id]);
                    $success_msg = 'Zeitfenster gelöscht.';
                    if ($edit_id === $id) {
                        $edit_id = 0;
                    }
                } catch (Throwable $e) {
                    $error_msg = 'Löschen fehlgeschlagen.';
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error_msg = 'Ungültige ID.';
            } else {
                try {
                    $stmt = $pdo->prepare('UPDATE lane_timeframes SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?');
                    $stmt->execute([$id]);
                    $success_msg = 'Status aktualisiert.';
                } catch (Throwable $e) {
                    $error_msg = 'Aktualisieren fehlgeschlagen.';
                }
            }
        }
    }
}

try {
    $stmt = $pdo->query('SELECT id, name, start_at, end_at, active, sort_order, created_at, updated_at FROM lane_timeframes ORDER BY active DESC, sort_order ASC, start_at ASC, end_at ASC, id ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
    if ($error_msg === '') {
        $error_msg = 'Zeitfenster konnten nicht geladen werden.';
    }
}

if ($edit_id > 0) {
    foreach ($rows as $row) {
        if ((int)$row['id'] === $edit_id) {
            $edit_row = $row;
            break;
        }
    }
}

$overlaps = tf_row_overlap($rows);
$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$assetBase = $assetBase === '' ? '' : $assetBase;
$current_username = (string)($current_user['username'] ?? ($_SESSION['username'] ?? 'Admin'));
$current_role = (string)($current_user['role'] ?? ($_SESSION['role'] ?? 'admin'));
$active_count = 0;
foreach ($rows as $row) {
    if ((int)($row['active'] ?? 0) === 1) {
        $active_count++;
    }
}
$can_dashboard_page = auth_can($pdo, 'participants.read');
$can_numbers_page = auth_can($pdo, 'numbers.read');
$can_export_page = auth_can($pdo, 'exports.participants');
$is_standalone = isset($_GET['standalone']) && (string)$_GET['standalone'] === '1';

?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zeitfenster verwalten</title>
    <link rel="stylesheet" href="<?php echo tf_escape($assetBase . '/assets/style.css'); ?>?v=<?php echo tf_escape(app_asset_version('assets/style.css')); ?>">
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
        html[data-theme="dark"] input[type="date"],
        html[data-theme="dark"] input[type="time"],
        html[data-theme="dark"] select {
            background: #0f131a;
            color: var(--text);
            border-color: var(--border);
        }
        html[data-theme="dark"] tr:nth-child(even) td {
            background: rgba(255, 255, 255, 0.03);
        }
        html[data-theme="dark"] .schedule-card,
        html[data-theme="dark"] .admin-session-info,
        html[data-theme="dark"] .table-summary,
        html[data-theme="dark"] .table-column-options {
            background: #141a24;
            color: var(--text);
        }
        html[data-theme="dark"] .table-column-options {
            border-color: var(--border);
        }
        body {
            background:
                radial-gradient(900px 500px at 20% 10%, rgba(255, 237, 0, 0.24), transparent 60%),
                radial-gradient(900px 500px at 80% 10%, rgba(5, 105, 180, 0.14), transparent 62%),
                linear-gradient(180deg, #8c0710 0%, #e30613 48%, #e30613 100%);
        }
        .admin-page .container {
            max-width: none !important;
            width: 100%;
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
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            column-gap: 8px;
            row-gap: 4px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f7f8fb;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        .admin-session-stat {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 9px;
            border-radius: 999px;
            background: rgba(227, 6, 19, 0.06);
            border: 1px solid rgba(227, 6, 19, 0.12);
            color: var(--text);
            font-size: 14px;
            white-space: nowrap;
        }
        .admin-session-actions,
        .admin-links-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .admin-session-actions {
            justify-content: flex-end;
        }
        .admin-link-btn {
            flex: 1 1 180px;
            min-width: 0;
        }
        .content-intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .intro-copy {
            max-width: 860px;
        }
        .page-title {
            margin: 0;
            font-size: 30px;
            line-height: 1.04;
            letter-spacing: -0.02em;
        }
        .page-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.5;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .layout {
            display: grid;
            grid-template-columns: minmax(320px, 400px) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }
        .panel h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
            line-height: 1.15;
        }
        .panel > form {
            display: grid;
            gap: 12px;
        }
        .field {
            display: grid;
            gap: 7px;
        }
        .field label,
        .field legend {
            font-weight: 700;
        }
        .timeframe-form {
            display: grid;
            gap: 14px;
        }
        .timeframe-form .schedule-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }
        .timeframe-form .schedule-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f7f8fb;
            padding: 14px;
        }
        .timeframe-form .schedule-card h3 {
            margin: 0 0 9px 0;
            font-size: 15px;
            line-height: 1.2;
        }
        .timeframe-form .schedule-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 14px;
            align-items: end;
        }
        .timeframe-form .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 14px;
            align-items: end;
        }
        .timeframe-form .field-check {
            align-self: end;
            min-width: 0;
        }
        .field-caption {
            display: block;
            font-weight: 700;
            line-height: 1.35;
        }
        .timeframe-form .field-check .check-row {
            min-height: 46px;
            margin: 0;
            padding: 10px 12px;
            align-items: center;
        }
        .timeframe-form .field-submit {
            display: flex;
            grid-column: 1 / -1;
            align-items: end;
            justify-content: flex-end;
        }
        .timeframe-form .field-submit .btn {
            width: auto;
            min-width: 132px;
        }
        .panel-note {
            margin: 0 0 12px 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
            max-width: 70ch;
        }
        .table-panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .table-summary {
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f7f8fb;
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
        }
        .table-tools {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .table-tool-grow {
            flex: 1 1 280px;
        }
        .table-column-picker {
            position: relative;
        }
        .table-column-picker > summary {
            list-style: none;
            cursor: pointer;
        }
        .table-column-picker > summary::-webkit-details-marker {
            display: none;
        }
        .table-column-options {
            position: absolute;
            top: calc(100% - 1px);
            right: 0;
            z-index: 20;
            display: grid;
            gap: 8px;
            min-width: 220px;
            padding: 12px;
            border: 1px solid rgba(227, 6, 19, 0.18);
            border-radius: 12px 0 12px 12px;
            background: #fff;
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.12);
        }
        .table-column-option {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
        }
        .table-wrap {
            width: 100%;
            overflow: auto;
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 780px;
        }
        th,
        td {
            padding: 11px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
            font-size: 15px;
            line-height: 1.35;
        }
        th {
            background: var(--dlrg-rot);
            color: #fff;
            white-space: nowrap;
            position: relative;
            font-size: 14px;
            letter-spacing: 0.01em;
        }
        .timeframe-table tbody td {
            vertical-align: middle;
        }
        .timeframe-table tbody tr:hover td {
            background: rgba(227, 6, 19, 0.03);
        }
        html[data-theme="dark"] .timeframe-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.05);
        }
        .timeframe-table th[data-col-key] {
            padding-right: 18px;
        }
        .timeframe-table td:last-child {
            min-width: 250px;
        }
        .col-resize-handle {
            position: absolute;
            top: 0;
            right: -4px;
            width: 10px;
            height: 100%;
            cursor: col-resize;
            user-select: none;
            touch-action: none;
            z-index: 2;
        }
        .col-resize-handle::before {
            content: '';
            position: absolute;
            top: 10px;
            bottom: 10px;
            left: 50%;
            width: 2px;
            transform: translateX(-50%);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.55);
            opacity: 0.65;
            transition: opacity 140ms ease, background 140ms ease;
        }
        .col-resize-handle:hover::before,
        .col-resize-handle.is-active::before {
            opacity: 1;
            background: #ffed00;
        }
        .timeframe-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.01em;
            border: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.04);
        }
        .tag-on {
            background: rgba(40, 167, 69, 0.14);
            border-color: rgba(40, 167, 69, 0.25);
            color: #1d6f35;
        }
        .tag-off {
            background: rgba(87, 87, 86, 0.08);
            border-color: rgba(87, 87, 86, 0.18);
            color: var(--muted);
        }
        html[data-theme="dark"] .tag {
            background: rgba(255, 255, 255, 0.05);
        }
        html[data-theme="dark"] .tag-on {
            color: #8ae2a8;
        }
        html[data-theme="dark"] .tag-off {
            color: #c1cada;
        }
        .alert-warn {
            border-color: rgba(255, 237, 0, 0.35);
            background: rgba(255, 237, 0, 0.28);
        }
        html[data-theme="dark"] .alert-warn {
            background: rgba(255, 237, 0, 0.14);
        }
        .nowrap { white-space: nowrap; }
        .empty-state {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }
        @media (max-width: 960px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .timeframe-form .meta-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 720px) {
            .timeframe-form .schedule-fields {
                grid-template-columns: 1fr;
            }
            .page-title {
                font-size: 25px;
            }
            .admin-shell-top,
            .content-intro,
            .admin-session-actions {
                width: 100%;
            }
            .admin-session-actions,
            .admin-links-grid,
            .actions {
                flex-direction: column;
                align-items: stretch;
            }
            .admin-session-actions > .btn,
            .admin-links-grid > .btn,
            .actions > .btn,
            .actions > a,
            .actions > form {
                width: 100%;
            }
            .table-tools,
            .table-tool-grow,
            .table-column-picker {
                width: 100%;
            }
            .table-column-picker > summary,
            .actions > form > .btn {
                width: 100%;
            }
            .table-column-options {
                left: 0;
                right: 0;
                min-width: 0;
            }
        }
    </style>
</head>
<body class="admin-page">
<div class="page">
    <div class="container">
        <header class="reg-header">
            <img src="<?php echo tf_escape($assetBase . '/assets/logo.png'); ?>" alt="DLRG Sankt Augustin e.V." class="reg-logo" width="1714" height="332" decoding="async">
            <h1 class="reg-title">
                <span>Admin</span>
                <span>24-Stunden-Schwimmen</span>
                <span>Zeitfenster und Bahnen-Zuordnung</span>
            </h1>
        </header>

        <div class="page-shell<?php echo $is_standalone ? ' page-shell-standalone' : ''; ?>">
            <?php if (!$is_standalone): ?>
            <aside class="page-sidebar">
                <div class="panel sidebar-panel">
                    <div class="sidebar-copy">
                        <p class="sidebar-label">Zeitfenster</p>
                        <h2 class="sidebar-title">Zeitfenster verwalten</h2>
                        <p class="sidebar-subtitle">Status, Navigation und Kernaktionen bleiben links sichtbar, waehrend Formular und Tabelle rechts bearbeitet werden.</p>
                    </div>
                    <div class="admin-session-info">
                        <span>Angemeldet als: <strong><?php echo tf_escape($current_username); ?></strong></span>
                        <span class="admin-session-badge"><?php echo tf_escape($current_role); ?></span>
                        <span class="admin-session-stat"><?php echo (int)count($rows); ?> Zeitfenster</span>
                        <span class="admin-session-stat"><?php echo (int)$active_count; ?> aktiv</span>
                        <span class="admin-session-stat"><?php echo (int)count($overlaps); ?> Konflikte</span>
                    </div>
                    <div class="sidebar-links">
                        <button type="button" class="btn btn-secondary" id="theme_toggle" aria-pressed="false">Dunkelmodus</button>
                        <a class="btn btn-yellow" href="timeframes.php?format=json">JSON exportieren</a>
                        <a class="btn btn-secondary" href="admin.php">Admin</a>
                        <?php if ($can_dashboard_page): ?>
                            <a class="btn btn-secondary" href="dashboard/dashboard.php">Dashboard</a>
                        <?php endif; ?>
                        <?php if ($can_numbers_page): ?>
                            <a class="btn btn-secondary" href="numbers.php">Startnummern</a>
                        <?php endif; ?>
                        <?php if ($can_export_page): ?>
                            <a class="btn btn-secondary" href="export.php">Datenexport</a>
                        <?php endif; ?>
                        <form method="post" action="admin.php" style="display:inline;"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>"><button type="submit" class="btn btn-secondary">Abmelden</button></form>
                    </div>
                </div>
            </aside>
            <?php endif; ?>

            <div class="page-main">
                <?php if ($success_msg !== ''): ?>
                    <div class="alert alert-success"><strong>Erfolg:</strong> <?php echo tf_escape($success_msg); ?></div>
                <?php endif; ?>
                <?php if ($error_msg !== ''): ?>
                    <div class="alert alert-error"><strong>Fehler:</strong> <?php echo tf_escape($error_msg); ?></div>
                <?php endif; ?>
                <?php if (count($overlaps) > 0): ?>
                    <div class="alert alert-warn">
                        <strong>Hinweis:</strong> Es gibt überlappende aktive Zeitfenster. Bei der Kategorisierung zählt das erste passende Zeitfenster nach Sortierung.
                    </div>
                <?php endif; ?>

        <div class="layout">
            <div class="panel">
                <h2><?php echo $edit_row ? 'Zeitfenster bearbeiten' : 'Neues Zeitfenster'; ?></h2>
                <p class="panel-note">Start und Ende werden minutengenau eingegeben. Endzeiten speichert das System intern immer auf :59, damit die volle letzte Minute in Auswertungen erhalten bleibt. Die Sortierung legt die Priorität fest, falls sich Zeitfenster überschneiden.</p>
                <form class="timeframe-form" method="post" action="timeframes.php<?php echo $edit_row ? ('?edit=' . (int)$edit_row['id']) : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo tf_escape(csrf_token_get()); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo tf_escape((string)($edit_row['id'] ?? '0')); ?>">

                    <div class="field">
                        <label for="tf_name">Name</label>
                        <input id="tf_name" name="name" type="text" maxlength="100" required value="<?php echo tf_escape((string)($edit_row['name'] ?? '')); ?>" placeholder="z. B. Nacht, Morgen, Finale">
                    </div>

                    <div class="schedule-grid">
                        <section class="schedule-card" aria-labelledby="tf_start_heading">
                            <h3 id="tf_start_heading">Start</h3>
                            <div class="schedule-fields">
                                <div class="field">
                                    <label for="tf_start_date">Datum</label>
                                    <input id="tf_start_date" name="start_date" type="date" required value="<?php echo tf_escape(tf_dt_local_date($edit_row['start_at'] ?? null)); ?>">
                                </div>
                                <div class="field">
                                    <label for="tf_start_time">Uhrzeit</label>
                                    <input id="tf_start_time" name="start_time" type="time" step="60" required value="<?php echo tf_escape(tf_dt_local_time($edit_row['start_at'] ?? null)); ?>">
                                </div>
                            </div>
                        </section>

                        <section class="schedule-card" aria-labelledby="tf_end_heading">
                            <h3 id="tf_end_heading">Ende</h3>
                            <div class="schedule-fields">
                                <div class="field">
                                    <label for="tf_end_date">Datum</label>
                                    <input id="tf_end_date" name="end_date" type="date" required value="<?php echo tf_escape(tf_dt_local_date($edit_row['end_at'] ?? null)); ?>">
                                </div>
                                <div class="field">
                                    <label for="tf_end_time">Uhrzeit</label>
                                    <input id="tf_end_time" name="end_time" type="time" step="60" required value="<?php echo tf_escape(tf_dt_local_time($edit_row['end_at'] ?? null)); ?>">
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="meta-grid">
                        <div class="field">
                            <label for="tf_sort">Sortierung</label>
                            <input id="tf_sort" name="sort_order" type="number" step="1" value="<?php echo tf_escape((string)($edit_row['sort_order'] ?? '0')); ?>">
                        </div>
                        <div class="field field-check">
                            <span class="field-caption">Status</span>
                            <label class="check-row">
                                <input type="checkbox" name="active" <?php echo ((int)($edit_row['active'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                <span>Aktiv</span>
                            </label>
                        </div>
                        <div class="field field-submit">
                            <button class="btn btn-primary" type="submit"><?php echo $edit_row ? 'Speichern' : 'Anlegen'; ?></button>
                        </div>
                    </div>

                    <?php if ($edit_row): ?>
                        <div class="actions">
                            <a class="btn btn-secondary" href="timeframes.php">Abbrechen</a>
                            <button class="btn btn-yellow" type="submit" name="action" value="delete" onclick="return confirm('Zeitfenster wirklich löschen?');" formaction="timeframes.php">Löschen</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="panel">
                <div class="table-panel-head">
                    <div>
                        <h2>Vorhandene Zeitfenster</h2>
                        <p class="panel-note">Die Tabelle nutzt dieselben kompakten Steuerelemente wie die übrigen Admin-Seiten.</p>
                    </div>
                    <p class="table-summary" id="timeframes_visible_count"><?php echo (int)count($rows); ?> / <?php echo (int)count($rows); ?> sichtbar</p>
                </div>

                <?php if (count($rows) === 0): ?>
                    <p class="empty-state">Noch keine Zeitfenster angelegt.</p>
                <?php else: ?>
                    <div class="table-tools">
                        <div class="field table-tool-grow" style="margin: 0;">
                            <label for="timeframes_filter">Tabelle filtern</label>
                            <input id="timeframes_filter" type="text" placeholder="Name, Datum, Status oder Sortierung durchsuchen">
                        </div>
                        <details class="table-column-picker">
                            <summary class="btn btn-secondary">Spalten</summary>
                            <div class="table-column-options">
                                <label class="table-column-option">
                                    <input type="checkbox" data-column-toggle="start" checked>
                                    <span>Start</span>
                                </label>
                                <label class="table-column-option">
                                    <input type="checkbox" data-column-toggle="end" checked>
                                    <span>Ende</span>
                                </label>
                                <label class="table-column-option">
                                    <input type="checkbox" data-column-toggle="status" checked>
                                    <span>Status</span>
                                </label>
                                <label class="table-column-option">
                                    <input type="checkbox" data-column-toggle="sort" checked>
                                    <span>Sortierung</span>
                                </label>
                            </div>
                        </details>
                    </div>

                    <div class="table-wrap">
                        <table class="data-table timeframe-table" id="timeframes_table" data-table-managed="external">
                            <colgroup>
                                <col data-col-key="name" style="width: 220px;">
                                <col data-col-key="start" style="width: 170px;">
                                <col data-col-key="end" style="width: 170px;">
                                <col data-col-key="status" style="width: 120px;">
                                <col data-col-key="sort" style="width: 110px;">
                                <col data-col-key="actions" style="width: 280px;">
                            </colgroup>
                            <thead>
                            <tr>
                                <th data-col-key="name">Name<span class="col-resize-handle" data-resize-col="name" aria-hidden="true"></span></th>
                                <th data-col-key="start">Start<span class="col-resize-handle" data-resize-col="start" aria-hidden="true"></span></th>
                                <th data-col-key="end">Ende<span class="col-resize-handle" data-resize-col="end" aria-hidden="true"></span></th>
                                <th data-col-key="status">Status<span class="col-resize-handle" data-resize-col="status" aria-hidden="true"></span></th>
                                <th data-col-key="sort" class="nowrap">Sortierung<span class="col-resize-handle" data-resize-col="sort" aria-hidden="true"></span></th>
                                <th data-col-key="actions" class="nowrap">Aktionen</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td class="nowrap"><strong><?php echo tf_escape((string)$row['name']); ?></strong></td>
                                    <td class="nowrap"><?php echo tf_escape(tf_dt_display((string)$row['start_at'])); ?></td>
                                    <td class="nowrap"><?php echo tf_escape(tf_dt_display((string)$row['end_at'])); ?></td>
                                    <td>
                                        <?php if ((int)$row['active'] === 1): ?>
                                            <span class="tag tag-on">aktiv</span>
                                        <?php else: ?>
                                            <span class="tag tag-off">inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="nowrap"><?php echo tf_escape((string)$row['sort_order']); ?></td>
                                    <td>
                                        <div class="timeframe-actions">
                                            <a class="btn btn-small btn-secondary" href="timeframes.php?edit=<?php echo (int)$row['id']; ?>">Bearbeiten</a>
                                            <form method="post" action="timeframes.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo tf_escape(csrf_token_get()); ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-small btn-primary" type="submit"><?php echo ((int)$row['active'] === 1) ? 'Deaktivieren' : 'Aktivieren'; ?></button>
                                            </form>
                                            <form method="post" action="timeframes.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo tf_escape(csrf_token_get()); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-small btn-yellow" type="submit" onclick="return confirm('Zeitfenster wirklich löschen?');">Löschen</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="empty-state" id="timeframes_table_empty" hidden>Kein Zeitfenster passt zum aktuellen Filter.</p>
                <?php endif; ?>
            </div>
        </div>
            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        var root = document.documentElement;
        var button = document.getElementById('theme_toggle');
        var filterInput = document.getElementById('timeframes_filter');
        var table = document.getElementById('timeframes_table');
        var emptyState = document.getElementById('timeframes_table_empty');
        var visibleCount = document.getElementById('timeframes_visible_count');
        var columnToggles = Array.prototype.slice.call(document.querySelectorAll('[data-column-toggle]'));
        var resizeHandles = Array.prototype.slice.call(document.querySelectorAll('[data-resize-col]'));
        if (!button) {
            return;
        }

        function applyTheme(theme) {
            root.setAttribute('data-theme', theme);
            button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            button.textContent = theme === 'dark' ? 'Hellmodus' : 'Dunkelmodus';
        }

        applyTheme(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

        button.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try {
                localStorage.setItem('admin_theme', next);
            } catch (e) {
            }
        });

        if (!table) {
            return;
        }

        var storageKey = 'timeframes_table_columns';
        var widthStorageKey = 'timeframes_table_column_widths';
        var headerMap = {};
        var colMap = {};
        var minColumnWidths = {
            name: 160,
            start: 140,
            end: 140,
            status: 110,
            sort: 90,
            actions: 220
        };
        var resizeState = null;
        Array.prototype.forEach.call(table.querySelectorAll('thead th[data-col-key]'), function (th, index) {
            headerMap[th.getAttribute('data-col-key')] = index;
        });
        Array.prototype.forEach.call(table.querySelectorAll('col[data-col-key]'), function (col) {
            colMap[col.getAttribute('data-col-key')] = col;
        });

        function getColumnWidth(key) {
            var col = colMap[key];
            if (col) {
                var colWidth = Math.round(col.getBoundingClientRect().width);
                if (colWidth > 0) {
                    return colWidth;
                }
            }
            var th = table.querySelector('thead th[data-col-key="' + key + '"]');
            return th ? Math.round(th.getBoundingClientRect().width) : (minColumnWidths[key] || 120);
        }

        function setColumnWidth(key, width) {
            if (!colMap[key]) {
                return;
            }
            var safeWidth = Math.max(minColumnWidths[key] || 120, Math.round(width));
            colMap[key].style.width = safeWidth + 'px';
        }

        function persistColumnWidths() {
            var state = {};
            Object.keys(colMap).forEach(function (key) {
                state[key] = getColumnWidth(key);
            });
            try {
                localStorage.setItem(widthStorageKey, JSON.stringify(state));
            } catch (e) {
            }
        }

        function updateVisibleCount() {
            if (!visibleCount) {
                return;
            }
            var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
            var visibleRows = rows.filter(function (row) {
                return !row.hidden;
            }).length;
            visibleCount.textContent = visibleRows + ' / ' + rows.length + ' sichtbar';
            if (emptyState) {
                emptyState.hidden = visibleRows !== 0;
            }
        }

        function applyFilter() {
            var query = filterInput ? String(filterInput.value || '').toLowerCase().trim() : '';
            Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
                var haystack = String(row.textContent || '').toLowerCase();
                row.hidden = query !== '' && haystack.indexOf(query) === -1;
            });
            updateVisibleCount();
        }

        function applyColumnVisibility(save) {
            var state = {};
            columnToggles.forEach(function (toggle) {
                var key = toggle.getAttribute('data-column-toggle');
                var index = headerMap[key];
                var isVisible = !!toggle.checked;
                state[key] = isVisible;
                if (typeof index !== 'number') {
                    return;
                }
                if (colMap[key]) {
                    colMap[key].hidden = !isVisible;
                }
                Array.prototype.forEach.call(table.querySelectorAll('tr'), function (row) {
                    if (row.children[index]) {
                        row.children[index].hidden = !isVisible;
                    }
                });
            });
            if (save) {
                try {
                    localStorage.setItem(storageKey, JSON.stringify(state));
                } catch (e) {
                }
            }
        }

        try {
            var savedState = JSON.parse(localStorage.getItem(storageKey) || '{}');
            columnToggles.forEach(function (toggle) {
                var key = toggle.getAttribute('data-column-toggle');
                if (Object.prototype.hasOwnProperty.call(savedState, key)) {
                    toggle.checked = !!savedState[key];
                }
            });
        } catch (e) {
        }

        try {
            var savedWidths = JSON.parse(localStorage.getItem(widthStorageKey) || '{}');
            Object.keys(savedWidths).forEach(function (key) {
                if (Object.prototype.hasOwnProperty.call(colMap, key) && Number(savedWidths[key]) > 0) {
                    setColumnWidth(key, Number(savedWidths[key]));
                }
            });
        } catch (e) {
        }

        if (filterInput) {
            filterInput.addEventListener('input', applyFilter);
        }
        columnToggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                applyColumnVisibility(true);
            });
        });
        resizeHandles.forEach(function (handle) {
            handle.addEventListener('pointerdown', function (event) {
                var key = handle.getAttribute('data-resize-col');
                if (!key || !colMap[key] || colMap[key].hidden) {
                    return;
                }
                resizeState = {
                    key: key,
                    startX: event.clientX,
                    startWidth: getColumnWidth(key),
                    handle: handle
                };
                handle.classList.add('is-active');
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
                event.preventDefault();
            });
        });
        window.addEventListener('pointermove', function (event) {
            if (!resizeState) {
                return;
            }
            setColumnWidth(resizeState.key, resizeState.startWidth + (event.clientX - resizeState.startX));
        });
        window.addEventListener('pointerup', function () {
            if (!resizeState) {
                return;
            }
            resizeState.handle.classList.remove('is-active');
            resizeState = null;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            persistColumnWidths();
        });

        applyColumnVisibility(false);
        applyFilter();
    }());
</script>
<script src="<?php echo tf_escape($assetBase . '/assets/app.js'); ?>?v=<?php echo tf_escape(app_asset_version('assets/app.js')); ?>"></script>
</body>
</html>
