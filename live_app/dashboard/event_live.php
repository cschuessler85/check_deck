<?php
/**
 * @doc-file
 * @doc-id live_app.dashboard.event_live
 * @doc-kind page-controller
 * @doc-purpose Rendert die interne Live-Eventansicht fuer aktuelle Schwimmdaten und Highlights.
 * @doc-entry HTTP-Aufruf der Live-Dashboard-Seite.
 * @doc-input Live-Datenquellen, Hilfsfunktionen und Anzeigeparameter.
 * @doc-output HTML-Liveansicht fuer Eventbetrieb.
 * @doc-side-effects Liest Live-Daten und rendert fortlaufend aktualisierbare Inhalte.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

if (!auth_can($pdo, 'participants.read')) {
    http_response_code(403);
    echo 'Zugriff verweigert.';
    exit;
}

$scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$dir = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
$rootBase = rtrim(str_replace('\\', '/', dirname($dir)), '/');
if ($rootBase === '/') {
    $rootBase = '';
}
$selfHref = basename($scriptPath) ?: 'event_live.php';
$isStandalone = isset($_GET['standalone']) && (string)$_GET['standalone'] === '1';

$data = event_live_load_overview($pdo);

if (isset($_GET['format']) && (string)$_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!doctype html>
<html lang="de" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Event Live Kiosk</title>
    <script>
        (function () {
            var theme = '';
            try {
                theme = String(localStorage.getItem('event_live_theme') || '');
            } catch (error) {
                theme = '';
            }
            if (theme !== 'light' && theme !== 'dark') {
                theme = 'dark';
            }
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <style>
        :root {
            --red: #e30613;
            --red-dark: #870912;
            --yellow: #ffed00;
            --blue: #0569b4;
            --ink: #f8fafc;
            --muted: rgba(248, 250, 252, 0.72);
            --panel: rgba(15, 23, 42, 0.74);
            --panel-strong: rgba(15, 23, 42, 0.9);
            --panel-soft: rgba(255, 255, 255, 0.08);
            --border: rgba(255, 255, 255, 0.14);
            --success: #22c55e;
            --shadow: 0 22px 60px rgba(2, 6, 23, 0.35);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
            margin: 0;
        }

        body {
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1100px 620px at 12% 8%, rgba(255, 237, 0, 0.20), transparent 58%),
                radial-gradient(1000px 620px at 88% 10%, rgba(5, 105, 180, 0.28), transparent 62%),
                linear-gradient(145deg, #6f0710 0%, #b30711 35%, #e30613 100%);
        }

        html[data-theme="light"] {
            --ink: #1f2937;
            --muted: rgba(31, 41, 55, 0.72);
            --panel: rgba(255, 255, 255, 0.92);
            --panel-strong: rgba(241, 245, 249, 0.98);
            --panel-soft: rgba(15, 23, 42, 0.04);
            --border: rgba(15, 23, 42, 0.12);
            --shadow: 0 18px 44px rgba(15, 23, 42, 0.12);
        }

        html[data-theme="light"] body {
            background:
                radial-gradient(1100px 620px at 12% 8%, rgba(255, 237, 0, 0.24), transparent 58%),
                radial-gradient(1000px 620px at 88% 10%, rgba(5, 105, 180, 0.14), transparent 62%),
                linear-gradient(145deg, #fff6d5 0%, #fef3f2 38%, #eef6ff 100%);
        }

        .page {
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 18px;
            padding: 18px;
        }

        .page.is-standalone {
            grid-template-rows: 1fr;
            gap: 0;
            padding: 0;
        }

        .toolbar,
        .slide {
            border: 1px solid var(--border);
            border-radius: 28px;
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            flex-wrap: wrap;
        }

        .toolbar-title {
            min-width: 300px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .live-dot {
            width: 12px;
            height: 12px;
            border-radius: 999px;
            background: var(--success);
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.45);
            animation: pulse 1.2s ease-out infinite;
        }

        .live-dot.paused {
            animation: none;
            background: rgba(255, 255, 255, 0.35);
            box-shadow: none;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.45); }
            100% { box-shadow: 0 0 0 14px rgba(34, 197, 94, 0); }
        }

        h1 {
            margin: 0;
            font-size: clamp(28px, 3vw, 42px);
            line-height: 1.02;
        }

        .toolbar-subtitle {
            margin: 8px 0 0;
            color: var(--muted);
            max-width: 880px;
            font-size: 15px;
        }

        .toolbar-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px 12px;
            border-radius: 999px;
            background: var(--panel-soft);
            border: 1px solid var(--border);
            font-size: 13px;
        }

        .chip strong {
            font-size: 12px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn,
        .select {
            appearance: none;
            border: 1px solid transparent;
            border-radius: 14px;
            min-height: 48px;
            padding: 10px 16px;
            font-size: 15px;
            font-weight: 800;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            color: #fff;
            background: rgba(255, 255, 255, 0.12);
            text-decoration: none;
            border-color: rgba(255, 255, 255, 0.12);
        }

        .btn-primary {
            background: var(--yellow);
            color: #111827;
            border-color: rgba(0, 0, 0, 0.08);
        }

        .btn-secondary {
            background: var(--panel-strong);
            color: #fff;
            border-color: var(--border);
        }

        .select {
            background: var(--panel-strong);
            color: #fff;
            border-color: var(--border);
        }

        .viewport {
            min-height: 0;
        }

        .slide {
            min-height: calc(100vh - 180px);
            padding: 24px;
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 20px;
        }

        .slide.is-marquee-table {
            min-height: 100vh;
            grid-template-rows: 1fr;
            gap: 0;
            padding: 0;
            border-radius: 0;
            border: 0;
        }

        .page.is-standalone .slide {
            min-height: 100vh;
            border-radius: 0;
            border: 0;
        }

        .slide.is-marquee-table .slide-head {
            display: none;
        }

        .slide.is-marquee-table .content {
            min-height: 100vh;
            height: 100vh;
        }

        .slide-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .slide-title {
            margin: 0;
            font-size: clamp(32px, 3.5vw, 56px);
            line-height: 0.98;
        }

        .slide-subtitle {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 18px;
        }

        .slide-counter {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 48px;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--panel-soft);
            border: 1px solid var(--border);
            font-size: 16px;
            font-weight: 800;
        }

        .content,
        .facts,
        .highlights {
            min-height: 0;
        }

        .ranking {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            align-content: start;
        }

        .ranking.single-column {
            grid-template-columns: 1fr;
        }

        .row {
            display: grid;
            grid-template-columns: 64px minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
            padding: 18px 20px;
            border-radius: 24px;
            background: var(--panel-soft);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .rank {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 22px;
            font-weight: 900;
        }

        .name {
            font-size: clamp(20px, 2vw, 28px);
            font-weight: 900;
            line-height: 1.05;
        }

        .sub {
            margin-top: 6px;
            color: var(--muted);
            font-size: 15px;
        }

        .metric {
            text-align: right;
        }

        .metric strong {
            display: block;
            font-size: clamp(26px, 2.4vw, 38px);
            line-height: 1;
        }

        .metric span {
            display: block;
            margin-top: 6px;
            color: var(--muted);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .facts,
        .highlights {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            align-content: start;
        }

        .fact-card,
        .highlight-card {
            padding: 22px;
            border-radius: 24px;
            background: var(--panel-soft);
            border: 1px solid rgba(255, 255, 255, 0.08);
            min-height: 180px;
        }

        .highlight-label {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .highlight-value {
            margin-top: 10px;
            font-size: clamp(34px, 3vw, 58px);
            font-weight: 900;
            line-height: 0.95;
        }

        .highlight-note,
        .fact-card p {
            margin: 12px 0 0;
            color: var(--muted);
            font-size: 18px;
            line-height: 1.45;
        }

        .error-box {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(239, 68, 68, 0.16);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fff;
        }

        .empty {
            padding: 24px;
            border-radius: 24px;
            background: var(--panel-soft);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: var(--muted);
            font-size: 20px;
        }

        .settings-panel {
            width: 100%;
            margin-top: 8px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            overflow: hidden;
        }

        .settings-panel summary {
            cursor: pointer;
            list-style: none;
            padding: 16px 18px;
            font-size: 15px;
            font-weight: 800;
        }

        .settings-panel summary::-webkit-details-marker {
            display: none;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            padding: 0 18px 18px;
        }

        .settings-group {
            padding: 16px;
            border-radius: 18px;
            background: rgba(15, 23, 42, 0.32);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .settings-group h3 {
            margin: 0 0 12px;
            font-size: 14px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
        }

        .settings-options {
            display: grid;
            gap: 10px;
        }

        .settings-option {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .settings-option input {
            width: 18px;
            height: 18px;
        }

        .preset-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .preset-btn {
            width: 100%;
        }

        .table-shell {
            display: grid;
            gap: 14px;
        }

        .table-note {
            color: var(--muted);
            font-size: 15px;
        }

        .table-wrap-live {
            overflow: auto;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: var(--panel-soft);
            max-height: calc(100vh - 320px);
        }

        .slide.is-marquee-table .table-shell,
        .slide.is-marquee-table .table-wrap-live {
            height: 100%;
            max-height: 100vh;
        }

        .slide.is-marquee-table .table-wrap-live {
            border-radius: 0;
            border: 0;
        }

        .slide.is-marquee-table .score-table {
            min-width: 100%;
        }

        .slide.is-marquee-table .score-table th,
        .slide.is-marquee-table .score-table td {
            padding: 18px 20px;
            font-size: 18px;
        }

        .score-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        .score-table th,
        .score-table td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-align: left;
            vertical-align: middle;
        }

        .score-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: rgba(15, 23, 42, 0.92);
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .score-table tbody tr:last-child td {
            border-bottom: 0;
        }

        html[data-theme="light"] .btn {
            color: #0f172a;
            background: rgba(15, 23, 42, 0.06);
            border-color: rgba(15, 23, 42, 0.08);
        }

        html[data-theme="light"] .btn-secondary,
        html[data-theme="light"] .select {
            background: rgba(255, 255, 255, 0.96);
            color: #0f172a;
            border-color: rgba(15, 23, 42, 0.12);
        }

        html[data-theme="light"] .settings-group,
        html[data-theme="light"] .settings-panel,
        html[data-theme="light"] .table-wrap-live,
        html[data-theme="light"] .fact-card,
        html[data-theme="light"] .highlight-card,
        html[data-theme="light"] .empty {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(15, 23, 42, 0.08);
        }

        html[data-theme="light"] .score-table th {
            background: rgba(248, 250, 252, 0.98);
        }

        html[data-theme="light"] .score-table td {
            color: #0f172a;
        }

        .score-table td.is-number,
        .score-table th.is-number {
            text-align: right;
        }

        .score-table td.is-rank,
        .score-table th.is-rank {
            width: 72px;
            text-align: center;
            font-weight: 900;
        }

        @media (max-width: 1100px) {
            .ranking,
            .facts,
            .highlights {
                grid-template-columns: 1fr;
            }

            .settings-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 780px) {
            .page {
                padding: 12px;
            }

            .toolbar,
            .slide {
                border-radius: 22px;
            }

            .settings-grid {
                grid-template-columns: 1fr;
            }

            .slide {
                min-height: calc(100vh - 150px);
                padding: 18px;
            }

            .row {
                grid-template-columns: 48px minmax(0, 1fr);
            }

            .metric {
                grid-column: 2;
                text-align: left;
            }
        }

        @media (max-width: 560px) {
            .toolbar-title {
                min-width: 0;
            }

            .toolbar-actions,
            .toolbar-meta,
            .settings-panel {
                width: 100%;
            }

            .toolbar-actions > *,
            .toolbar-meta > *,
            .settings-panel > * {
                width: 100%;
            }

            .slide-title {
                font-size: 28px;
            }

            .slide-subtitle {
                font-size: 16px;
            }

            .slide-counter {
                width: 100%;
                justify-content: center;
            }

            .row {
                padding: 16px;
            }

            .fact-card,
            .highlight-card {
                min-height: 0;
                padding: 18px;
            }

            .score-table {
                min-width: 620px;
            }
        }
    </style>
</head>
<body>
    <div class="page<?php echo $isStandalone ? ' is-standalone' : ''; ?>">
        <?php if (!$isStandalone): ?>
        <section class="toolbar">
            <div class="toolbar-title">
                <img src="<?php echo escape($rootBase . '/assets/logo.png'); ?>" alt="DLRG Sankt Augustin e.V." width="1714" height="332" decoding="async" style="max-width:180px;height:auto;margin-bottom:10px;" />
                <div class="eyebrow">
                    <span class="live-dot" id="liveDot"></span>
                    <span>Event Live Kiosk</span>
                </div>
                <h1>Rotierendes Vollbild-Dashboard</h1>
                <p class="toolbar-subtitle">
                    Rankings, Zeitfenster und Funfacts direkt aus der externen Event-SQL.
                </p>
                <div class="toolbar-meta">
                    <div class="chip"><strong>Quelle</strong><span id="metaSourceMode">—</span></div>
                    <div class="chip"><strong>Pfad</strong><span id="metaPath">—</span></div>
                    <div class="chip"><strong>Pool</strong><span id="metaPool">—</span></div>
                    <div class="chip"><strong>Letzter Klick</strong><span id="metaLastAdd">—</span></div>
                    <div class="chip"><strong>Aktualisiert</strong><span id="metaLoadedAt">—</span></div>
                </div>
                <div id="errorBox" class="error-box" hidden></div>
            </div>

            <div class="toolbar-actions">
                <select id="rotationSelect" class="select" aria-label="Rotationsintervall">
                    <option value="5">Rotation: 5s</option>
                    <option value="8">Rotation: 8s</option>
                    <option value="10">Rotation: 10s</option>
                    <option value="15">Rotation: 15s</option>
                </select>
                <button class="btn btn-secondary" type="button" id="toggleRotation">Rotation: an</button>
                <button class="btn btn-secondary" type="button" id="toggleEndless">Endlos: aus</button>
                <button class="btn btn-secondary" type="button" id="toggleRefresh">Auto-Refresh: an</button>
                <button class="btn btn-secondary" type="button" id="themeToggle">Hellmodus</button>
                <button class="btn btn-primary" type="button" id="reloadNow">Jetzt aktualisieren</button>
                <button class="btn btn-secondary" type="button" id="fullscreenBtn">Vollbild</button>
                <a class="btn btn-secondary" href="<?php echo escape($rootBase . '/admin.php'); ?>">Admin</a>
            </div>
            <details class="settings-panel">
                <summary>Tabellen und Spalten konfigurieren</summary>
                <div class="settings-grid">
                    <div class="settings-group">
                        <h3>Presets</h3>
                        <div class="preset-grid" id="presetOptions">
                            <button class="btn btn-secondary preset-btn" type="button" data-preset="publikum">Publikum</button>
                            <button class="btn btn-secondary preset-btn" type="button" data-preset="teams_all">Alle Teams</button>
                            <button class="btn btn-secondary preset-btn" type="button" data-preset="swimmers_all">Alle Schwimmer</button>
                            <button class="btn btn-secondary preset-btn" type="button" data-preset="kids">Kinderwertung</button>
                        </div>
                    </div>
                    <div class="settings-group">
                        <h3>Slides</h3>
                        <div class="settings-options" id="slideOptions"></div>
                    </div>
                    <div class="settings-group">
                        <h3>Schwimmer-Spalten</h3>
                        <div class="settings-options" id="swimmerColumnOptions"></div>
                    </div>
                    <div class="settings-group">
                        <h3>Team-Spalten</h3>
                        <div class="settings-options" id="teamColumnOptions"></div>
                    </div>
                    <div class="settings-group">
                        <h3>Zeitfenster-Spalten</h3>
                        <div class="settings-options" id="timeframeColumnOptions"></div>
                    </div>
                </div>
            </details>
        </section>
        <?php endif; ?>

        <main class="viewport">
            <section class="slide">
                <div class="slide-head">
                    <div>
                        <h2 class="slide-title" id="slideTitle">—</h2>
                        <p class="slide-subtitle" id="slideSubtitle">—</p>
                    </div>
                    <div class="slide-counter" id="slideCounter">1 / 1</div>
                </div>
                <div class="content" id="slideBody"></div>
            </section>
        </main>
    </div>

    <script>
        (function () {
            var endpoint = <?php echo json_encode($selfHref . '?format=json', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var initialData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var params = new URLSearchParams(window.location.search);
            var rotationOptions = ['5', '8', '10', '15'];
            var initialRotation = params.get('rotation') || localStorage.getItem('event_live_rotation') || '8';
            if (rotationOptions.indexOf(initialRotation) === -1) {
                initialRotation = '8';
            }

            var numberFormatter = new Intl.NumberFormat('de-DE');
            var decimalFormatter = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
            var percentFormatter = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
            var liveDot = document.getElementById('liveDot');
            var errorBox = document.getElementById('errorBox');
            var slideSection = document.querySelector('.slide');
            var slideTitle = document.getElementById('slideTitle');
            var slideSubtitle = document.getElementById('slideSubtitle');
            var slideCounter = document.getElementById('slideCounter');
            var slideBody = document.getElementById('slideBody');
            var metaSourceMode = document.getElementById('metaSourceMode');
            var metaPath = document.getElementById('metaPath');
            var metaPool = document.getElementById('metaPool');
            var metaLastAdd = document.getElementById('metaLastAdd');
            var metaLoadedAt = document.getElementById('metaLoadedAt');
            var rotationSelect = document.getElementById('rotationSelect');
            var toggleRotation = document.getElementById('toggleRotation');
            var toggleEndless = document.getElementById('toggleEndless');
            var toggleRefresh = document.getElementById('toggleRefresh');
            var themeToggle = document.getElementById('themeToggle');
            var reloadNow = document.getElementById('reloadNow');
            var fullscreenBtn = document.getElementById('fullscreenBtn');
            var slideOptions = document.getElementById('slideOptions');
            var presetOptions = document.getElementById('presetOptions');
            var swimmerColumnOptions = document.getElementById('swimmerColumnOptions');
            var teamColumnOptions = document.getElementById('teamColumnOptions');
            var timeframeColumnOptions = document.getElementById('timeframeColumnOptions');

            var STORAGE_KEYS = {
                visibleSlides: 'event_live_visible_slides',
                endless: 'event_live_endless_enabled',
                theme: 'event_live_theme',
                columns: {
                    swimmer: 'event_live_columns_swimmer',
                    team: 'event_live_columns_team',
                    lane: 'event_live_columns_lane',
                    timeframe: 'event_live_columns_timeframe'
                }
            };

            var rotationEnabled = localStorage.getItem('event_live_rotation_enabled') !== '0';
            var endlessEnabled = localStorage.getItem(STORAGE_KEYS.endless) === '1';
            var refreshEnabled = localStorage.getItem('event_live_refresh_enabled') !== '0';
            var currentTheme = localStorage.getItem(STORAGE_KEYS.theme) === 'light' ? 'light' : 'dark';
            var rotationSeconds = Number(initialRotation);
            var rotationTimer = null;
            var refreshTimer = null;
            var endlessScrollTimer = null;
            var refreshMs = 15000;
            var currentSlideIndex = 0;
            var currentData = initialData;

            var slideDefinitions = [
                {
                    key: 'all10',
                    label: 'Top 10 Alle',
                    title: 'Top 10 Alle Schwimmer',
                    subtitle: 'Mit und ohne Team nach Gesamtbahnen',
                    type: 'swimmer',
                    emptyMessage: 'Noch keine Schwimmerdaten vorhanden.',
                    items: function (data) { return data.toplists && data.toplists.all; }
                },
                {
                    key: 'all25',
                    label: 'Top 25 Alle',
                    title: 'Top 25 Alle Schwimmer',
                    subtitle: 'Erweiterte Gesamtwertung nach Gesamtbahnen',
                    type: 'swimmer',
                    emptyMessage: 'Noch keine Schwimmerdaten vorhanden.',
                    items: function (data) { return data.toplists && data.toplists.all_25; }
                },
                {
                    key: 'allFull',
                    label: 'Alle Schwimmer',
                    title: 'Alle Schwimmer',
                    subtitle: 'Komplette Liste aller Schwimmer mit Bestenzahlen',
                    type: 'swimmer',
                    emptyMessage: 'Noch keine Schwimmerdaten vorhanden.',
                    items: function (data) { return data.toplists && data.toplists.all_full; }
                },
                {
                    key: 'singles10',
                    label: 'Top 10 Einzel',
                    title: 'Top 10 Einzelkaempfer',
                    subtitle: 'Nur Schwimmer ohne Team',
                    type: 'swimmer',
                    emptyMessage: 'Noch keine Einzelkaempfer vorhanden.',
                    items: function (data) { return data.toplists && data.toplists.singles; }
                },
                {
                    key: 'singles25',
                    label: 'Top 25 Einzel',
                    title: 'Top 25 Einzelkaempfer',
                    subtitle: 'Einzelwertung in der erweiterten Tabelle',
                    type: 'swimmer',
                    emptyMessage: 'Noch keine Einzelkaempfer vorhanden.',
                    items: function (data) { return data.toplists && data.toplists.singles_25; }
                },
                {
                    key: 'kids10',
                    label: 'Top 10 Kinder',
                    title: 'Top 10 Kinder',
                    subtitle: 'Kinderwertung nach Gesamtbahnen',
                    type: 'swimmer',
                    emptyMessage: 'Noch keine Kinderwertung vorhanden.',
                    items: function (data) { return data.toplists && data.toplists.kids; }
                },
                {
                    key: 'kids25',
                    label: 'Top 25 Kinder',
                    title: 'Top 25 Kinder',
                    subtitle: 'Erweiterte Kinderwertung',
                    type: 'swimmer',
                    emptyMessage: 'Noch keine Kinderwertung vorhanden.',
                    items: function (data) { return data.toplists && data.toplists.kids_25; }
                },
                {
                    key: 'teams10',
                    label: 'Top 10 Teams',
                    title: 'Top 10 Teams',
                    subtitle: 'Gruppenwertung nach Gesamtbahnen',
                    type: 'team',
                    emptyMessage: 'Noch keine Teams in der Event-SQL gefunden.',
                    items: function (data) { return data.toplists && data.toplists.teams; }
                },
                {
                    key: 'teams25',
                    label: 'Top 25 Teams',
                    title: 'Top 25 Teams',
                    subtitle: 'Erweiterte Teamwertung nach Gesamtbahnen',
                    type: 'team',
                    emptyMessage: 'Noch keine Teams in der Event-SQL gefunden.',
                    items: function (data) { return data.toplists && data.toplists.teams_25; }
                },
                {
                    key: 'teamsFull',
                    label: 'Alle Teams',
                    title: 'Alle Teams',
                    subtitle: 'Komplette Liste aller Teams mit Kennzahlen',
                    type: 'team',
                    emptyMessage: 'Noch keine Teams in der Event-SQL gefunden.',
                    items: function (data) { return data.toplists && data.toplists.teams_full; }
                },
                {
                    key: 'timeframes',
                    label: 'Zeitfenster',
                    title: 'Zeitfenster-Ranking',
                    subtitle: 'ADD-Events werden per Timestamp den Zeitfenstern zugeordnet',
                    type: 'timeframe',
                    emptyMessage: 'Es sind noch keine aktiven Zeitfenster konfiguriert.',
                    items: function (data) { return data.timeframes; }
                },
                {
                    key: 'highlights',
                    label: 'Highlights',
                    title: 'Live-Highlights',
                    subtitle: 'Was heute im Wasser und am Zaehlpult passiert',
                    render: function (data) { return renderHighlights(data); }
                },
                {
                    key: 'funfacts',
                    label: 'Funfacts',
                    title: 'Funfacts',
                    subtitle: 'Schwimmen, DLRG und ein bisschen Event-Charme',
                    render: function (data) { return renderFunfacts(data.funfacts || []); }
                }
            ];

            var columnDefinitions = {
                swimmer: [
                    { key: 'rank', label: 'Platz', thClass: 'is-rank', tdClass: 'is-rank', render: function (item, index) { return String(index + 1); } },
                    { key: 'name', label: 'Name', render: function (item) { return esc(item.name || '—'); } },
                    { key: 'nummer', label: 'Nummer', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return numberFormatter.format(Number(item.nummer || 0)); } },
                    { key: 'gruppe', label: 'Team', render: function (item) { return esc(item.gruppe || 'Ohne Team'); } },
                    { key: 'kind', label: 'Kategorie', render: function (item) { return item.ist_kind ? 'Kind' : 'Offen'; } },
                    { key: 'bahn', label: 'Bahn', render: function (item) { return item.auf_bahn > 0 ? ('Bahn ' + esc(item.auf_bahn)) : '—'; } },
                    { key: 'bahnen', label: 'Bahnen', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return numberFormatter.format(Number(item.bahnen || 0)); } },
                    { key: 'km', label: 'Kilometer', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return decimalFormatter.format(Number(item.km || 0)); } }
                ],
                team: [
                    { key: 'rank', label: 'Platz', thClass: 'is-rank', tdClass: 'is-rank', render: function (item, index) { return String(index + 1); } },
                    { key: 'name', label: 'Team', render: function (item) { return esc(item.name || '—'); } },
                    { key: 'members', label: 'Mitglieder', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return numberFormatter.format(Number(item.members || 0)); } },
                    { key: 'active_members', label: 'Aktiv', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return numberFormatter.format(Number(item.active_members || 0)); } },
                    { key: 'laps', label: 'Bahnen', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return numberFormatter.format(Number(item.laps || 0)); } },
                    { key: 'km', label: 'Kilometer', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return decimalFormatter.format(Number(item.km || 0)); } },
                    { key: 'avg_laps_per_member', label: 'Ø Bahnen', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return decimalFormatter.format(Number(item.avg_laps_per_member || 0)); } }
                ],
                timeframe: [
                    { key: 'rank', label: 'Platz', thClass: 'is-rank', tdClass: 'is-rank', render: function (item, index) { return String(index + 1); } },
                    { key: 'name', label: 'Zeitfenster', render: function (item) { return esc(item.name || '—'); } },
                    { key: 'period', label: 'Zeitraum', render: function (item) { return esc((item.start_at || '—') + ' bis ' + (item.end_at || '—')); } },
                    { key: 'unique_swimmers', label: 'Schwimmer', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return numberFormatter.format(Number(item.unique_swimmers || 0)); } },
                    { key: 'laps', label: 'ADD-Klicks', thClass: 'is-number', tdClass: 'is-number', render: function (item) { return numberFormatter.format(Number(item.laps || 0)); } }
                ]
            };

            var defaultVisibleSlides = slideDefinitions.map(function (slide) { return slide.key; });
            var defaultColumns = {
                swimmer: ['rank', 'name', 'nummer', 'gruppe', 'bahn', 'bahnen', 'km'],
                team: ['rank', 'name', 'members', 'active_members', 'laps', 'km'],
                timeframe: ['rank', 'name', 'period', 'unique_swimmers', 'laps']
            };

            var presetDefinitions = {
                publikum: {
                    slides: ['highlights', 'all10', 'teams10', 'kids10', 'funfacts'],
                    columns: {
                        swimmer: ['rank', 'name', 'gruppe', 'bahnen', 'km'],
                        team: ['rank', 'name', 'members', 'laps', 'km'],
                        timeframe: defaultColumns.timeframe.slice()
                    },
                    endless: false
                },
                teams_all: {
                    slides: ['teamsFull'],
                    columns: {
                        swimmer: defaultColumns.swimmer.slice(),
                        team: ['rank', 'name', 'members', 'active_members', 'laps', 'km', 'avg_laps_per_member'],
                        timeframe: defaultColumns.timeframe.slice()
                    },
                    endless: true
                },
                swimmers_all: {
                    slides: ['allFull'],
                    columns: {
                        swimmer: ['rank', 'name', 'nummer', 'gruppe', 'bahn', 'bahnen', 'km'],
                        team: defaultColumns.team.slice(),
                        timeframe: defaultColumns.timeframe.slice()
                    },
                    endless: true
                },
                kids: {
                    slides: ['kids10', 'kids25'],
                    columns: {
                        swimmer: ['rank', 'name', 'nummer', 'gruppe', 'bahnen', 'km'],
                        team: defaultColumns.team.slice(),
                        timeframe: defaultColumns.timeframe.slice()
                    },
                    endless: false
                }
            };

            if (rotationSelect) {
                rotationSelect.value = String(rotationSeconds);
            }

            function esc(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatDateTime(value) {
                if (!value) return '—';
                var date = new Date(value);
                if (Number.isNaN(date.getTime())) return String(value);
                return date.toLocaleString('de-DE');
            }

            function showError(message) {
                if (!errorBox) {
                    return;
                }
                errorBox.hidden = false;
                errorBox.textContent = message;
            }

            function clearError() {
                if (!errorBox) {
                    return;
                }
                errorBox.hidden = true;
                errorBox.textContent = '';
            }

            function renderEmpty(message) {
                return '<div class="empty">' + esc(message) + '</div>';
            }

            function storageGetArray(key, defaults, allowedValues, allowEmpty) {
                var raw = localStorage.getItem(key);
                if (!raw) {
                    return defaults.slice();
                }
                try {
                    var parsed = JSON.parse(raw);
                    if (!Array.isArray(parsed)) {
                        return defaults.slice();
                    }
                    var filtered = parsed.filter(function (value, index) {
                        return typeof value === 'string'
                            && allowedValues.indexOf(value) !== -1
                            && parsed.indexOf(value) === index;
                    });
                    if (filtered.length > 0) {
                        return filtered;
                    }
                    return allowEmpty ? [] : defaults.slice();
                } catch (error) {
                    return defaults.slice();
                }
            }

            function storageSetArray(key, values) {
                localStorage.setItem(key, JSON.stringify(values));
            }

            function applyPreset(presetKey) {
                var preset = presetDefinitions[presetKey];
                if (!preset) {
                    return;
                }

                storageSetArray(STORAGE_KEYS.visibleSlides, preset.slides.slice());
                Object.keys(preset.columns).forEach(function (type) {
                    storageSetArray(STORAGE_KEYS.columns[type], preset.columns[type].slice());
                });
                endlessEnabled = preset.endless === true;
                localStorage.setItem(STORAGE_KEYS.endless, endlessEnabled ? '1' : '0');
                currentSlideIndex = 0;
                renderSettings();
                syncButtons();
                renderCurrentSlide();
            }

            function getVisibleSlideKeys() {
                return storageGetArray(
                    STORAGE_KEYS.visibleSlides,
                    defaultVisibleSlides,
                    slideDefinitions.map(function (slide) { return slide.key; }),
                    true
                );
            }

            function getVisibleSlides() {
                var keys = getVisibleSlideKeys();
                return slideDefinitions.filter(function (slide) {
                    return keys.indexOf(slide.key) !== -1;
                });
            }

            function getSelectedColumns(type) {
                var defs = columnDefinitions[type] || [];
                return storageGetArray(
                    STORAGE_KEYS.columns[type],
                    defaultColumns[type] || [],
                    defs.map(function (column) { return column.key; })
                );
            }

            function renderCheckboxGroup(container, items, selectedKeys, settingsKind) {
                if (!container) {
                    return;
                }
                container.innerHTML = items.map(function (item) {
                    var checked = selectedKeys.indexOf(item.key) !== -1 ? ' checked' : '';
                    return '' +
                        '<label class="settings-option">' +
                            '<input type="checkbox" data-settings-kind="' + esc(settingsKind) + '" value="' + esc(item.key) + '"' + checked + '>' +
                            '<span>' + esc(item.label) + '</span>' +
                        '</label>';
                }).join('');
            }

            function renderSettings() {
                renderCheckboxGroup(
                    slideOptions,
                    slideDefinitions.map(function (slide) {
                        return { key: slide.key, label: slide.label };
                    }),
                    getVisibleSlideKeys(),
                    'slide'
                );
                renderCheckboxGroup(swimmerColumnOptions, columnDefinitions.swimmer, getSelectedColumns('swimmer'), 'swimmer');
                renderCheckboxGroup(teamColumnOptions, columnDefinitions.team, getSelectedColumns('team'), 'team');
                renderCheckboxGroup(timeframeColumnOptions, columnDefinitions.timeframe, getSelectedColumns('timeframe'), 'timeframe');
            }

            function resolveColumns(type) {
                var definitions = columnDefinitions[type] || [];
                var selected = getSelectedColumns(type);
                var visible = definitions.filter(function (column) {
                    return selected.indexOf(column.key) !== -1;
                });
                return visible.length > 0 ? visible : definitions.slice(0, 1);
            }

            function renderTable(items, type, emptyMessage) {
                if (!Array.isArray(items) || items.length === 0) {
                    return renderEmpty(emptyMessage);
                }
                var columns = resolveColumns(type);
                var headHtml = columns.map(function (column) {
                    var thClass = column.thClass ? (' class="' + column.thClass + '"') : '';
                    return '<th' + thClass + '>' + esc(column.label) + '</th>';
                }).join('');
                var bodyHtml = items.map(function (item, index) {
                    var cells = columns.map(function (column) {
                        var tdClass = column.tdClass ? (' class="' + column.tdClass + '"') : '';
                        return '<td' + tdClass + '>' + column.render(item, index, currentData || {}) + '</td>';
                    }).join('');
                    return '<tr>' + cells + '</tr>';
                }).join('');
                return '' +
                    '<div class="table-shell">' +
                        '<div class="table-wrap-live" data-table-scroll="1">' +
                            '<table class="score-table">' +
                                '<thead><tr>' + headHtml + '</tr></thead>' +
                                '<tbody>' + bodyHtml + '</tbody>' +
                            '</table>' +
                        '</div>' +
                    '</div>';
            }

            function renderHighlights(data) {
                var totals = data.totals || {};
                var highlights = data.highlights || {};
                var cards = [];
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="highlight-label">Gesamtbahnen</div>' +
                        '<div class="highlight-value">' + numberFormatter.format(Number(totals.laps_total || 0)) + '</div>' +
                        '<div class="highlight-note">' + decimalFormatter.format(Number(totals.km_total || 0)) + ' km bei ' + numberFormatter.format(Number(data.pool_length_m || 100)) + ' m pro Bahn auf insgesamt ' + numberFormatter.format(Number(data.lane_count || 3)) + ' Bahnen</div>' +
                    '</div>'
                );
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="highlight-label">Live-Takt</div>' +
                        '<div class="highlight-value">' + numberFormatter.format(Number(totals.recent_5m || 0)) + '</div>' +
                        '<div class="highlight-note">' + numberFormatter.format(Number(totals.recent_15m || 0)) + ' Klicks in 15 Minuten</div>' +
                    '</div>'
                );
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="highlight-label">Fuehrender Schwimmer</div>' +
                        '<div class="highlight-value">' + esc(highlights.top_swimmer ? highlights.top_swimmer.name : '—') + '</div>' +
                        '<div class="highlight-note">' + (highlights.top_swimmer ? numberFormatter.format(Number(highlights.top_swimmer.bahnen || 0)) + ' Bahnen' : 'Noch keine Daten') + '</div>' +
                    '</div>'
                );
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="highlight-label">Fuehrendes Team</div>' +
                        '<div class="highlight-value">' + esc(highlights.top_team ? highlights.top_team.name : '—') + '</div>' +
                        '<div class="highlight-note">' + (highlights.top_team ? numberFormatter.format(Number(highlights.top_team.laps || 0)) + ' Bahnen | ' + numberFormatter.format(Number(highlights.top_team.members || 0)) + ' Mitglieder' : 'Noch keine Teamdaten') + '</div>' +
                    '</div>'
                );
                return '<div class="highlights">' + cards.join('') + '</div>';
            }

            function renderFunfacts(items) {
                if (!Array.isArray(items) || items.length === 0) {
                    return renderEmpty('Noch keine Funfacts verfuegbar.');
                }
                var visible = items.slice(0, 4);
                return '<div class="facts">' + visible.map(function (item, index) {
                    return '' +
                        '<div class="fact-card">' +
                            '<div class="highlight-label">Funfact ' + (index + 1) + '</div>' +
                            '<p>' + esc(item) + '</p>' +
                        '</div>';
                }).join('') + '</div>';
            }

            function renderMeta(data) {
                if (!metaSourceMode || !metaPath || !metaPool || !metaLastAdd || !metaLoadedAt) {
                    return;
                }
                metaSourceMode.textContent = data && data.source_mode ? String(data.source_mode) : '—';
                metaPath.textContent = data && data.source_path ? String(data.source_path) : '—';
                var poolLength = Number(data && data.pool_length_m ? data.pool_length_m : 100);
                var laneCount = Number(data && data.lane_count ? data.lane_count : 3);
                metaPool.textContent = numberFormatter.format(laneCount) + ' Bahnen gesamt | ' + numberFormatter.format(poolLength) + ' m pro Bahn';
                metaLastAdd.textContent = formatDateTime(data && data.highlights ? data.highlights.last_add_at : null);
                metaLoadedAt.textContent = formatDateTime(data ? data.loaded_at : null);
            }

            function applyTheme(theme) {
                currentTheme = theme === 'light' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', currentTheme);
            }

            function stopEndlessScroll() {
                if (endlessScrollTimer) {
                    window.clearInterval(endlessScrollTimer);
                    endlessScrollTimer = null;
                }
            }

            function isMarqueeSlide(slide) {
                if (!slide || !slide.key) {
                    return false;
                }
                return slide.key === 'allFull' || slide.key === 'teamsFull';
            }

            function applySlideMode(slide) {
                if (!slideSection) {
                    return;
                }
                slideSection.classList.toggle('is-marquee-table', endlessEnabled && isMarqueeSlide(slide));
            }

            function startEndlessScroll(slide) {
                stopEndlessScroll();
                if (!endlessEnabled || !isMarqueeSlide(slide)) {
                    return;
                }
                var scroller = slideBody ? slideBody.querySelector('[data-table-scroll="1"]') : null;
                if (!scroller) {
                    return;
                }
                if (scroller.scrollHeight <= scroller.clientHeight + 8) {
                    return;
                }
                endlessScrollTimer = window.setInterval(function () {
                    var maxScrollTop = scroller.scrollHeight - scroller.clientHeight;
                    if (maxScrollTop <= 0) {
                        return;
                    }
                    var nextScrollTop = scroller.scrollTop + 1;
                    scroller.scrollTop = nextScrollTop >= maxScrollTop ? 0 : nextScrollTop;
                }, 65);
            }

            function renderCurrentSlide() {
                var visibleSlides = getVisibleSlides();
                stopEndlessScroll();
                applySlideMode(null);
                if (visibleSlides.length === 0) {
                    slideTitle.textContent = 'Keine Tabellen ausgewaehlt';
                    slideSubtitle.textContent = 'Im Steuerfenster mindestens einen Slide aktivieren.';
                    slideCounter.textContent = '0 / 0';
                    slideBody.innerHTML = renderEmpty('Aktuell sind keine Tabellen oder Zusatzslides zur Wiedergabe ausgewaehlt.');
                    return;
                }

                if (currentSlideIndex >= visibleSlides.length) {
                    currentSlideIndex = 0;
                }
                if (currentSlideIndex < 0) {
                    currentSlideIndex = visibleSlides.length - 1;
                }

                var slide = visibleSlides[currentSlideIndex];
                applySlideMode(slide);
                slideTitle.textContent = slide.title;
                slideSubtitle.textContent = slide.subtitle;
                slideCounter.textContent = (currentSlideIndex + 1) + ' / ' + visibleSlides.length;

                if (typeof slide.render === 'function') {
                    slideBody.innerHTML = slide.render(currentData || {});
                    return;
                }

                slideBody.innerHTML = renderTable(
                    typeof slide.items === 'function' ? slide.items(currentData || {}) : [],
                    slide.type,
                    slide.emptyMessage || 'Keine Daten verfuegbar.'
                );
                startEndlessScroll(slide);
            }

            function nextSlide() {
                var visibleSlides = getVisibleSlides();
                if (visibleSlides.length === 0) {
                    renderCurrentSlide();
                    return;
                }
                currentSlideIndex = (currentSlideIndex + 1) % visibleSlides.length;
                renderCurrentSlide();
            }

            function previousSlide() {
                var visibleSlides = getVisibleSlides();
                if (visibleSlides.length === 0) {
                    renderCurrentSlide();
                    return;
                }
                currentSlideIndex = (currentSlideIndex - 1 + visibleSlides.length) % visibleSlides.length;
                renderCurrentSlide();
            }

            function scheduleRotation() {
                if (rotationTimer) {
                    window.clearInterval(rotationTimer);
                    rotationTimer = null;
                }
                if (!rotationEnabled) {
                    return;
                }
                rotationTimer = window.setInterval(nextSlide, rotationSeconds * 1000);
            }

            function scheduleRefresh() {
                if (refreshTimer) {
                    window.clearInterval(refreshTimer);
                    refreshTimer = null;
                }
                if (!refreshEnabled) {
                    return;
                }
                refreshTimer = window.setInterval(fetchLatest, refreshMs);
            }

            function syncButtons() {
                if (toggleRotation) {
                    toggleRotation.textContent = rotationEnabled ? 'Rotation: an' : 'Rotation: aus';
                }
                if (toggleEndless) {
                    toggleEndless.textContent = endlessEnabled ? 'Endlos: an' : 'Endlos: aus';
                }
                if (toggleRefresh) {
                    toggleRefresh.textContent = refreshEnabled ? 'Auto-Refresh: an' : 'Auto-Refresh: aus';
                }
                if (themeToggle) {
                    themeToggle.textContent = currentTheme === 'light' ? 'Dunkelmodus' : 'Hellmodus';
                }
                if (rotationSelect) {
                    rotationSelect.value = String(rotationSeconds);
                }
                if (liveDot) {
                    liveDot.classList.toggle('paused', !refreshEnabled && !rotationEnabled);
                }
            }

            function render(data) {
                currentData = data || {};
                applyTheme(currentTheme);
                renderSettings();
                if (!data || data.ok !== true) {
                    showError((data && data.error) ? data.error : 'Die Event-SQL konnte nicht geladen werden.');
                    renderMeta(data || {});
                    slideBody.innerHTML = renderEmpty('Keine Daten verfuegbar.');
                    stopEndlessScroll();
                    return;
                }
                clearError();
                renderMeta(data);
                renderCurrentSlide();
            }

            async function fetchLatest() {
                if (reloadNow) {
                    reloadNow.disabled = true;
                }
                try {
                    var response = await fetch(endpoint + '&_ts=' + Date.now(), {
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });
                    var nextData = await response.json();
                    render(nextData);
                } catch (error) {
                    showError('Aktualisierung fehlgeschlagen. Bitte Event-SQL und Server pruefen.');
                } finally {
                    if (reloadNow) {
                        reloadNow.disabled = false;
                    }
                }
            }

            function updateSlideSelection(slideKey, checked) {
                var keys = getVisibleSlideKeys().slice();
                var index = keys.indexOf(slideKey);
                if (checked && index === -1) {
                    keys.push(slideKey);
                }
                if (!checked && index !== -1) {
                    keys.splice(index, 1);
                }
                storageSetArray(STORAGE_KEYS.visibleSlides, keys);
                renderSettings();
                renderCurrentSlide();
            }

            function updateColumnSelection(type, columnKey, checked, input) {
                var keys = getSelectedColumns(type).slice();
                var index = keys.indexOf(columnKey);
                if (checked && index === -1) {
                    keys.push(columnKey);
                }
                if (!checked && index !== -1) {
                    keys.splice(index, 1);
                }
                if (keys.length === 0) {
                    if (input) {
                        input.checked = true;
                    }
                    return;
                }
                storageSetArray(STORAGE_KEYS.columns[type], keys);
                renderSettings();
                renderCurrentSlide();
            }

            function bindSettingsChange(container) {
                if (!container) {
                    return;
                }
                container.addEventListener('change', function (event) {
                    var input = event.target;
                    if (!(input instanceof HTMLInputElement) || input.type !== 'checkbox') {
                        return;
                    }
                    var kind = String(input.getAttribute('data-settings-kind') || '').trim();
                    var value = String(input.value || '').trim();
                    if (kind === 'slide') {
                        updateSlideSelection(value, input.checked);
                        return;
                    }
                    updateColumnSelection(kind, value, input.checked, input);
                });
            }

            bindSettingsChange(slideOptions);
            bindSettingsChange(swimmerColumnOptions);
            bindSettingsChange(teamColumnOptions);
            bindSettingsChange(timeframeColumnOptions);

            if (presetOptions) {
                presetOptions.addEventListener('click', function (event) {
                    var target = event.target;
                    if (!(target instanceof HTMLElement)) {
                        return;
                    }
                    var button = target.closest('[data-preset]');
                    if (!button) {
                        return;
                    }
                    var presetKey = String(button.getAttribute('data-preset') || '').trim();
                    applyPreset(presetKey);
                });
            }

            if (rotationSelect) {
                rotationSelect.addEventListener('change', function () {
                    rotationSeconds = Number(rotationSelect.value || '8');
                    localStorage.setItem('event_live_rotation', String(rotationSeconds));
                    scheduleRotation();
                });
            }

            if (toggleRotation) {
                toggleRotation.addEventListener('click', function () {
                    rotationEnabled = !rotationEnabled;
                    localStorage.setItem('event_live_rotation_enabled', rotationEnabled ? '1' : '0');
                    syncButtons();
                    scheduleRotation();
                });
            }

            if (toggleEndless) {
                toggleEndless.addEventListener('click', function () {
                    endlessEnabled = !endlessEnabled;
                    localStorage.setItem(STORAGE_KEYS.endless, endlessEnabled ? '1' : '0');
                    syncButtons();
                    startEndlessScroll();
                });
            }

            if (toggleRefresh) {
                toggleRefresh.addEventListener('click', function () {
                    refreshEnabled = !refreshEnabled;
                    localStorage.setItem('event_live_refresh_enabled', refreshEnabled ? '1' : '0');
                    syncButtons();
                    scheduleRefresh();
                });
            }

            if (reloadNow) {
                reloadNow.addEventListener('click', function () {
                    fetchLatest();
                });
            }

            if (themeToggle) {
                themeToggle.addEventListener('click', function () {
                    currentTheme = currentTheme === 'light' ? 'dark' : 'light';
                    localStorage.setItem(STORAGE_KEYS.theme, currentTheme);
                    applyTheme(currentTheme);
                    syncButtons();
                });
            }

            if (fullscreenBtn) {
                fullscreenBtn.addEventListener('click', function () {
                    if (!document.fullscreenElement && document.documentElement.requestFullscreen) {
                        document.documentElement.requestFullscreen().catch(function () {});
                        return;
                    }
                    if (document.fullscreenElement && document.exitFullscreen) {
                        document.exitFullscreen().catch(function () {});
                    }
                });
            }

            window.addEventListener('storage', function (event) {
                var relevantKeys = [
                    STORAGE_KEYS.visibleSlides,
                    STORAGE_KEYS.columns.swimmer,
                    STORAGE_KEYS.columns.team,
                    STORAGE_KEYS.columns.lane,
                    STORAGE_KEYS.columns.timeframe,
                    STORAGE_KEYS.endless,
                    STORAGE_KEYS.theme,
                    'event_live_rotation',
                    'event_live_rotation_enabled',
                    'event_live_refresh_enabled'
                ];
                if (event.key && relevantKeys.indexOf(event.key) === -1) {
                    return;
                }

                var nextRotation = localStorage.getItem('event_live_rotation') || '8';
                rotationSeconds = rotationOptions.indexOf(nextRotation) !== -1 ? Number(nextRotation) : 8;
                rotationEnabled = localStorage.getItem('event_live_rotation_enabled') !== '0';
                endlessEnabled = localStorage.getItem(STORAGE_KEYS.endless) === '1';
                refreshEnabled = localStorage.getItem('event_live_refresh_enabled') !== '0';
                currentTheme = localStorage.getItem(STORAGE_KEYS.theme) === 'light' ? 'light' : 'dark';
                applyTheme(currentTheme);
                renderSettings();
                syncButtons();
                scheduleRotation();
                scheduleRefresh();
                renderCurrentSlide();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowRight' || event.key === 'PageDown') {
                    nextSlide();
                } else if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
                    previousSlide();
                }
            });

            renderSettings();
            syncButtons();
            scheduleRotation();
            scheduleRefresh();
            render(initialData);
        })();
    </script>
</body>
</html>
