<?php
/**
 * @doc-file
 * @doc-id live_app.dashboard.json_live
 * @doc-kind api-endpoint
 * @doc-purpose Liefert strukturierte Live-Daten fuer Dashboards und andere Frontends.
 * @doc-entry HTTP-Aufruf durch Frontend oder Monitoring.
 * @doc-input Datenbank, JSON- oder SQLite-Livequellen und Hilfsfunktionen.
 * @doc-output JSON-Nutzdaten fuer Live-Anzeigen.
 * @doc-side-effects Liest Live-Datenquellen und sendet JSON mit HTTP-Headern.
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

$data = live_load_overview($pdo);

if (isset($_GET['format']) && (string)$_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_live_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>JSON Live-Übersicht</title>
    <style>
        :root {
            --red: #e30613;
            --red-dark: #9f0810;
            --yellow: #ffed00;
            --blue: #0569b4;
            --ink: #1f2933;
            --muted: #64748b;
            --panel: rgba(255, 255, 255, 0.94);
            --panel-strong: #ffffff;
            --border: rgba(15, 23, 42, 0.08);
            --shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
            --success: #16a34a;
            --warning: #ea580c;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1100px 640px at 15% 0%, rgba(255, 237, 0, 0.22), transparent 58%),
                radial-gradient(900px 520px at 85% 0%, rgba(5, 105, 180, 0.25), transparent 60%),
                linear-gradient(145deg, #7a0810 0%, #c60510 36%, #e30613 100%);
        }

        .page {
            width: min(1320px, calc(100% - 28px));
            margin: 0 auto;
            padding: 22px 0 30px;
        }

        .hero,
        .panel,
        .kpi-card {
            border: 1px solid var(--border);
            border-radius: 24px;
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
        }

        .hero {
            padding: 24px;
            margin-bottom: 18px;
        }

        .hero-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: start;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
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
            box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.45);
            animation: live-pulse 1.3s ease-out infinite;
        }

        .live-dot.paused {
            background: rgba(100, 116, 139, 0.9);
            animation: none;
            box-shadow: none;
        }

        @keyframes live-pulse {
            from {
                box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.45);
            }
            to {
                box-shadow: 0 0 0 14px rgba(22, 163, 74, 0);
            }
        }

        h1 {
            margin: 0;
            font-size: clamp(30px, 4vw, 48px);
            line-height: 0.96;
            letter-spacing: -0.03em;
        }

        .hero p {
            margin: 12px 0 0;
            max-width: 760px;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.5;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn {
            appearance: none;
            border: 0;
            border-radius: 14px;
            padding: 12px 16px;
            min-height: 46px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform 140ms ease, box-shadow 140ms ease, background 140ms ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--blue), #0b85e5);
            color: #fff;
            box-shadow: 0 10px 22px rgba(5, 105, 180, 0.28);
        }

        .btn-secondary {
            background: rgba(15, 23, 42, 0.08);
            color: var(--ink);
        }

        .btn-yellow {
            background: linear-gradient(135deg, #ffe24d, var(--yellow));
            color: #111827;
            box-shadow: 0 10px 22px rgba(255, 237, 0, 0.3);
        }

        .hero-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 36px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(15, 23, 42, 0.06);
            color: var(--ink);
            font-size: 13px;
            font-weight: 700;
        }

        .chip .label {
            color: var(--muted);
        }

        .chip.file {
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .kpi-card {
            padding: 18px;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::after {
            content: "";
            position: absolute;
            inset: auto -30% -60% auto;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(5, 105, 180, 0.12), rgba(227, 6, 19, 0.08));
            pointer-events: none;
        }

        .kpi-label {
            position: relative;
            z-index: 1;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }

        .kpi-value {
            position: relative;
            z-index: 1;
            margin-top: 12px;
            font-size: clamp(28px, 4vw, 40px);
            font-weight: 950;
            line-height: 1;
            letter-spacing: -0.03em;
        }

        .kpi-note {
            position: relative;
            z-index: 1;
            margin-top: 10px;
            font-size: 13px;
            color: var(--muted);
            font-weight: 700;
        }

        .kpi-value.changed {
            animation: value-bump 380ms ease;
        }

        @keyframes value-bump {
            0% { transform: scale(1); color: var(--ink); }
            45% { transform: scale(1.08); color: var(--blue); }
            100% { transform: scale(1); color: var(--ink); }
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(320px, 0.9fr);
            gap: 18px;
        }

        .stack {
            display: grid;
            gap: 18px;
        }

        .panel {
            padding: 20px;
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .panel-head h2 {
            margin: 0;
            font-size: 20px;
            line-height: 1.1;
        }

        .panel-head span {
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .leader-row,
        .active-card,
        .group-row {
            display: grid;
            gap: 10px;
            padding: 14px 16px;
            border: 1px solid rgba(15, 23, 42, 0.07);
            border-radius: 18px;
            background: var(--panel-strong);
        }

        .leader-row,
        .group-row {
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
        }

        .rank {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(5, 105, 180, 0.12), rgba(5, 105, 180, 0.24));
            color: var(--blue);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
        }

        .name {
            min-width: 0;
            font-size: 16px;
            font-weight: 800;
        }

        .sub {
            min-width: 0;
            margin-top: 3px;
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .metric {
            text-align: right;
        }

        .metric strong {
            display: block;
            font-size: 22px;
            line-height: 1;
            letter-spacing: -0.03em;
        }

        .metric span {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .active-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .active-card {
            position: relative;
            overflow: hidden;
        }

        .active-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: linear-gradient(180deg, var(--blue), var(--yellow));
        }

        .active-top {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 12px;
        }

        .lane-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 58px;
            height: 34px;
            padding: 0 10px;
            border-radius: 999px;
            background: rgba(5, 105, 180, 0.12);
            color: var(--blue);
            font-size: 13px;
            font-weight: 900;
        }

        .tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.06);
            color: var(--ink);
            font-size: 12px;
            font-weight: 800;
        }

        .bars {
            display: grid;
            gap: 12px;
        }

        .bar-row {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
        }

        .bar-track {
            height: 14px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--blue), #16a3ff);
            transition: width 400ms ease;
        }

        .bar-value {
            font-size: 14px;
            font-weight: 900;
            white-space: nowrap;
        }

        .highlights {
            display: grid;
            gap: 12px;
        }

        .highlight-card {
            padding: 16px;
            border-radius: 18px;
            background: var(--panel-strong);
            border: 1px solid rgba(15, 23, 42, 0.07);
        }

        .highlight-card .label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .highlight-card strong {
            display: block;
            margin-top: 10px;
            font-size: 22px;
            line-height: 1.08;
        }

        .highlight-card p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }

        .error {
            margin-top: 16px;
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid rgba(220, 38, 38, 0.2);
            background: rgba(255, 255, 255, 0.96);
            color: var(--danger);
            font-weight: 800;
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(15, 23, 42, 0.04);
            color: var(--muted);
            text-align: center;
            font-weight: 700;
        }

        @media (max-width: 1180px) {
            .kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 980px) {
            .hero-top,
            .layout,
            .active-list {
                grid-template-columns: 1fr;
            }

            .actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 720px) {
            .page {
                width: min(100% - 18px, 1320px);
                padding-top: 14px;
            }

            .hero,
            .panel,
            .kpi-card {
                border-radius: 20px;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .leader-row,
            .group-row {
                grid-template-columns: auto minmax(0, 1fr);
            }

            .metric {
                grid-column: 1 / -1;
                text-align: left;
                margin-left: 50px;
            }

            .bar-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .actions,
            .hero-meta {
                width: 100%;
            }

            .actions > *,
            .hero-meta > * {
                width: 100%;
            }

            .chip.file {
                white-space: normal;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 16px;
            }

            .panel-head {
                align-items: flex-start;
            }

            .panel-head span {
                width: 100%;
            }

            .metric {
                margin-left: 0;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .btn,
            .bar-fill,
            .kpi-value,
            .live-dot {
                transition: none;
                animation: none;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <div class="eyebrow">
                        <span class="live-dot" id="liveDot"></span>
                        <span>Live-Modus</span>
                    </div>
                    <h1>Animierte Live-Uebersicht fuer deine Live-Daten</h1>
                    <p>
                        Diese Seite liest die konfigurierte Live-Quelle aus JSON oder SQLite,
                        berechnet automatisch wichtige Kennzahlen und aktualisiert die Anzeige per Auto-Refresh.
                    </p>
                </div>
                <div class="actions">
                    <button class="btn btn-secondary" type="button" id="toggleRefresh">Auto-Refresh: an</button>
                    <button class="btn btn-primary" type="button" id="reloadNow">Jetzt aktualisieren</button>
                    <a class="btn btn-yellow" href="<?php echo json_live_html($rootBase . '/admin.php'); ?>">Admin</a>
                </div>
            </div>
            <div class="hero-meta">
                <div class="chip"><span class="label">Quelle</span><span id="metaSource">—</span></div>
                <div class="chip"><span class="label">Datei aktualisiert</span><span id="metaFileUpdated">—</span></div>
                <div class="chip"><span class="label">Seite aktualisiert</span><span id="metaLoadedAt">—</span></div>
                <div class="chip file"><span class="label">Pfad</span><span id="metaPath">—</span></div>
            </div>
            <div id="errorBox" class="error" hidden></div>
        </section>

        <section class="kpi-grid">
            <article class="kpi-card">
                <div class="kpi-label">Registrierte Schwimmer</div>
                <div class="kpi-value" data-kpi="registered">0</div>
                <div class="kpi-note" id="kpiRegisteredNote">—</div>
            </article>
            <article class="kpi-card">
                <div class="kpi-label">Aktiv gemeldet</div>
                <div class="kpi-value" data-kpi="active">0</div>
                <div class="kpi-note" id="kpiActiveNote">—</div>
            </article>
            <article class="kpi-card">
                <div class="kpi-label">Gerade im Wasser</div>
                <div class="kpi-value" data-kpi="in_water">0</div>
                <div class="kpi-note" id="kpiInWaterNote">—</div>
            </article>
            <article class="kpi-card">
                <div class="kpi-label">Gesamtbahnen</div>
                <div class="kpi-value" data-kpi="laps_total">0</div>
                <div class="kpi-note" id="kpiLapsNote">—</div>
            </article>
            <article class="kpi-card">
                <div class="kpi-label">Gruppen</div>
                <div class="kpi-value" data-kpi="groups">0</div>
                <div class="kpi-note" id="kpiGroupsNote">—</div>
            </article>
            <article class="kpi-card">
                <div class="kpi-label">Kinder/Jugendliche</div>
                <div class="kpi-value" data-kpi="kids">0</div>
                <div class="kpi-note" id="kpiKidsNote">—</div>
            </article>
        </section>

        <section class="layout">
            <div class="stack">
                <article class="panel">
                    <div class="panel-head">
                        <h2>Top Schwimmer</h2>
                        <span>nach Gesamtbahnen</span>
                    </div>
                    <div id="leadersList" class="list"></div>
                </article>

                <article class="panel">
                    <div class="panel-head">
                        <h2>Aktuell aktiv</h2>
                        <span>live aus `schwimmer`</span>
                    </div>
                    <div id="activeList" class="active-list"></div>
                </article>
            </div>

            <div class="stack">
                <article class="panel">
                    <div class="panel-head">
                        <h2>Highlights</h2>
                        <span>wichtige Kennzahlen</span>
                    </div>
                    <div id="highlights" class="highlights"></div>
                </article>

                <article class="panel">
                    <div class="panel-head">
                        <h2>Bahnbelegung</h2>
                        <span>aktuelle Verteilung</span>
                    </div>
                    <div id="laneBars" class="bars"></div>
                </article>

                <article class="panel">
                    <div class="panel-head">
                        <h2>Stärkste Gruppen</h2>
                        <span>Mitglieder und Bahnen</span>
                    </div>
                    <div id="groupList" class="list"></div>
                </article>
            </div>
        </section>
    </div>

    <script>
        (function () {
            var endpoint = 'json_live.php?format=json';
            var initialData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var refreshEnabled = localStorage.getItem('json_live_refresh') !== '0';
            var refreshTimer = null;
            var refreshMs = 15000;
            var liveDot = document.getElementById('liveDot');
            var toggleRefreshBtn = document.getElementById('toggleRefresh');
            var reloadNowBtn = document.getElementById('reloadNow');
            var errorBox = document.getElementById('errorBox');
            var numberFormatter = new Intl.NumberFormat('de-DE');
            var decimalFormatter = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

            var previousValues = Object.create(null);
            var animatedFrames = Object.create(null);

            function formatDateTime(value) {
                if (!value) return '—';
                var date = new Date(value);
                if (Number.isNaN(date.getTime())) return String(value);
                return date.toLocaleString('de-DE');
            }

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function showError(message) {
                errorBox.hidden = false;
                errorBox.textContent = message;
            }

            function clearError() {
                errorBox.hidden = true;
                errorBox.textContent = '';
            }

            function setRefreshUi() {
                toggleRefreshBtn.textContent = refreshEnabled ? 'Auto-Refresh: an' : 'Auto-Refresh: aus';
                liveDot.classList.toggle('paused', !refreshEnabled);
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

            function animateValue(element, nextValue, formatter) {
                if (!element) return;
                var key = element.getAttribute('data-kpi') || element.id || 'value';
                var previousValue = previousValues[key];
                if (typeof previousValue !== 'number') {
                    previousValues[key] = nextValue;
                    element.textContent = formatter(nextValue);
                    return;
                }

                if (previousValue === nextValue) {
                    element.textContent = formatter(nextValue);
                    return;
                }

                if (animatedFrames[key]) {
                    cancelAnimationFrame(animatedFrames[key]);
                }

                var start = performance.now();
                var duration = 450;

                element.classList.remove('changed');
                void element.offsetWidth;
                element.classList.add('changed');

                function step(now) {
                    var progress = Math.min((now - start) / duration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    var currentValue = previousValue + ((nextValue - previousValue) * eased);
                    var rounded = Math.abs(nextValue % 1) > 0 ? Math.round(currentValue * 10) / 10 : Math.round(currentValue);
                    element.textContent = formatter(rounded);

                    if (progress < 1) {
                        animatedFrames[key] = requestAnimationFrame(step);
                    } else {
                        element.textContent = formatter(nextValue);
                        previousValues[key] = nextValue;
                        window.setTimeout(function () {
                            element.classList.remove('changed');
                        }, 420);
                    }
                }

                animatedFrames[key] = requestAnimationFrame(step);
            }

            function setKpiValue(key, value, isDecimal) {
                var element = document.querySelector('[data-kpi="' + key + '"]');
                if (!element) return;
                animateValue(element, Number(value || 0), function (current) {
                    return isDecimal ? decimalFormatter.format(current) : numberFormatter.format(current);
                });
            }

            function renderLeaders(items) {
                var root = document.getElementById('leadersList');
                if (!root) return;
                if (!Array.isArray(items) || items.length === 0) {
                    root.innerHTML = '<div class="empty">Noch keine Schwimmerdaten vorhanden.</div>';
                    return;
                }

                root.innerHTML = items.map(function (item, index) {
                    var groupText = item.gruppe ? ' | ' + escapeHtml(item.gruppe) : ' | Ohne Gruppe';
                    return '' +
                        '<div class="leader-row">' +
                            '<div class="rank">' + (index + 1) + '</div>' +
                            '<div>' +
                                '<div class="name">' + escapeHtml(item.name || '—') + '</div>' +
                                '<div class="sub">Nr. ' + escapeHtml(item.nummer || '—') + groupText + '</div>' +
                            '</div>' +
                            '<div class="metric">' +
                                '<strong>' + numberFormatter.format(Number(item.bahnen || 0)) + '</strong>' +
                                '<span>Bahnen</span>' +
                            '</div>' +
                        '</div>';
                }).join('');
            }

            function renderActive(items) {
                var root = document.getElementById('activeList');
                if (!root) return;
                if (!Array.isArray(items) || items.length === 0) {
                    root.innerHTML = '<div class="empty">Aktuell ist kein Schwimmer auf einer Bahn gemeldet.</div>';
                    return;
                }

                root.innerHTML = items.map(function (item) {
                    var tags = [];
                    tags.push('<span class="tag">' + numberFormatter.format(Number(item.bahnen || 0)) + ' Bahnen</span>');
                    if (item.gruppe) {
                        tags.push('<span class="tag">' + escapeHtml(item.gruppe) + '</span>');
                    }
                    tags.push('<span class="tag">' + ((item.ist_kind === true) ? 'Kind/Jugend' : 'Erwachsen') + '</span>');

                    return '' +
                        '<div class="active-card">' +
                            '<div class="active-top">' +
                                '<div>' +
                                    '<div class="name">' + escapeHtml(item.name || '—') + '</div>' +
                                    '<div class="sub">Startnummer ' + escapeHtml(item.nummer || '—') + '</div>' +
                                '</div>' +
                                '<div class="lane-badge">Bahn ' + numberFormatter.format(Number(item.auf_bahn || 0)) + '</div>' +
                            '</div>' +
                            '<div class="tags">' + tags.join('') + '</div>' +
                        '</div>';
                }).join('');
            }

            function renderHighlights(data) {
                var root = document.getElementById('highlights');
                if (!root) return;

                var topSwimmer = data.highlights && data.highlights.top_swimmer ? data.highlights.top_swimmer : null;
                var topGroup = data.highlights && data.highlights.top_group ? data.highlights.top_group : null;
                var busiestLane = data.highlights && data.highlights.busiest_lane ? data.highlights.busiest_lane : null;
                var totals = data.totals || {};

                var cards = [];
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="label">Spitzenreiter</div>' +
                        '<strong>' + escapeHtml(topSwimmer ? topSwimmer.name : '—') + '</strong>' +
                        '<p>' + (topSwimmer ? (numberFormatter.format(Number(topSwimmer.bahnen || 0)) + ' Bahnen insgesamt') : 'Noch keine Daten verfügbar.') + '</p>' +
                    '</div>'
                );
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="label">Stärkste Gruppe</div>' +
                        '<strong>' + escapeHtml(topGroup ? topGroup.name : '—') + '</strong>' +
                        '<p>' + (topGroup ? (numberFormatter.format(Number(topGroup.members || 0)) + ' Mitglieder | ' + numberFormatter.format(Number(topGroup.laps || 0)) + ' Bahnen') : 'Derzeit keine Gruppen eingetragen.') + '</p>' +
                    '</div>'
                );
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="label">Meistgenutzte Bahn</div>' +
                        '<strong>' + escapeHtml(busiestLane ? ('Bahn ' + busiestLane.lane) : '—') + '</strong>' +
                        '<p>' + (busiestLane ? (numberFormatter.format(Number(busiestLane.count || 0)) + ' Schwimmer gleichzeitig auf dieser Bahn') : 'Aktuell keine belegte Bahn vorhanden.') + '</p>' +
                    '</div>'
                );
                cards.push(
                    '<div class="highlight-card">' +
                        '<div class="label">Weitere Werte</div>' +
                        '<strong>' + decimalFormatter.format(Number(totals.avg_laps || 0)) + ' Ø Bahnen</strong>' +
                        '<p>' + numberFormatter.format(Number(totals.actions || 0)) + ' Actions | ' + numberFormatter.format(Number(totals.stat_messages || 0)) + ' Statusmeldungen</p>' +
                    '</div>'
                );

                root.innerHTML = cards.join('');
            }

            function renderLanes(items) {
                var root = document.getElementById('laneBars');
                if (!root) return;
                if (!Array.isArray(items) || items.length === 0) {
                    root.innerHTML = '<div class="empty">Zurzeit liegen keine aktiven Bahnbelegungen vor.</div>';
                    return;
                }

                var maxCount = items.reduce(function (max, item) {
                    return Math.max(max, Number(item.count || 0));
                }, 1);

                root.innerHTML = items.map(function (item) {
                    var width = Math.max(8, Math.round((Number(item.count || 0) / maxCount) * 100));
                    return '' +
                        '<div class="bar-row">' +
                            '<div class="name">Bahn ' + escapeHtml(item.lane || '—') + '</div>' +
                            '<div class="bar-track"><div class="bar-fill" style="width:' + width + '%"></div></div>' +
                            '<div class="bar-value">' + numberFormatter.format(Number(item.count || 0)) + ' aktiv</div>' +
                        '</div>';
                }).join('');
            }

            function renderGroups(items) {
                var root = document.getElementById('groupList');
                if (!root) return;
                if (!Array.isArray(items) || items.length === 0) {
                    root.innerHTML = '<div class="empty">Es sind noch keine Gruppen in den JSON-Daten enthalten.</div>';
                    return;
                }

                root.innerHTML = items.map(function (item, index) {
                    return '' +
                        '<div class="group-row">' +
                            '<div class="rank">' + (index + 1) + '</div>' +
                            '<div>' +
                                '<div class="name">' + escapeHtml(item.name || '—') + '</div>' +
                                '<div class="sub">' + numberFormatter.format(Number(item.members || 0)) + ' Mitglieder | ' + numberFormatter.format(Number(item.active_members || 0)) + ' aktiv</div>' +
                            '</div>' +
                            '<div class="metric">' +
                                '<strong>' + numberFormatter.format(Number(item.laps || 0)) + '</strong>' +
                                '<span>Bahnen</span>' +
                            '</div>' +
                        '</div>';
                }).join('');
            }

            function render(data) {
                if (!data || data.ok !== true) {
                    showError((data && data.error) ? data.error : 'Die JSON-Daten konnten nicht geladen werden.');
                    return;
                }

                clearError();

                var totals = data.totals || {};
                setKpiValue('registered', totals.registered || 0, false);
                setKpiValue('active', totals.active || 0, false);
                setKpiValue('in_water', totals.in_water || 0, false);
                setKpiValue('laps_total', totals.laps_total || 0, false);
                setKpiValue('groups', totals.groups || 0, false);
                setKpiValue('kids', totals.kids || 0, false);

                document.getElementById('kpiRegisteredNote').textContent =
                    numberFormatter.format(Number(totals.adults || 0)) + ' Erwachsene';
                document.getElementById('kpiActiveNote').textContent =
                    numberFormatter.format(Number(totals.grouped || 0)) + ' mit Gruppe';
                document.getElementById('kpiInWaterNote').textContent =
                    numberFormatter.format(Number((data.lane_stats || []).length)) + ' Bahnen belegt';
                document.getElementById('kpiLapsNote').textContent =
                    decimalFormatter.format(Number(totals.avg_laps || 0)) + ' im Durchschnitt';
                document.getElementById('kpiGroupsNote').textContent =
                    numberFormatter.format(Number(totals.ungrouped || 0)) + ' ohne Gruppe';
                document.getElementById('kpiKidsNote').textContent =
                    numberFormatter.format(Number(totals.actions || 0)) + ' Actions im Feed';

                document.getElementById('metaSource').textContent =
                    numberFormatter.format(Number(totals.stat_messages || 0)) + ' Statusmeldungen';
                document.getElementById('metaFileUpdated').textContent = formatDateTime(data.file_updated_at);
                document.getElementById('metaLoadedAt').textContent = formatDateTime(data.loaded_at);
                document.getElementById('metaPath').textContent = data.file_path || '—';

                renderLeaders(data.leaders || []);
                renderActive(data.active_swimmers || []);
                renderHighlights(data);
                renderLanes(data.lane_stats || []);
                renderGroups(data.groups || []);
            }

            async function fetchLatest() {
                reloadNowBtn.disabled = true;
                try {
                    var response = await fetch(endpoint + '&_ts=' + Date.now(), {
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });
                    var nextData = await response.json();
                    render(nextData);
                } catch (error) {
                    showError('Auto-Refresh fehlgeschlagen. Bitte JSON-Datei oder Server prüfen.');
                } finally {
                    reloadNowBtn.disabled = false;
                }
            }

            toggleRefreshBtn.addEventListener('click', function () {
                refreshEnabled = !refreshEnabled;
                localStorage.setItem('json_live_refresh', refreshEnabled ? '1' : '0');
                setRefreshUi();
                scheduleRefresh();
            });

            reloadNowBtn.addEventListener('click', function () {
                fetchLatest();
            });

            setRefreshUi();
            scheduleRefresh();
            render(initialData);
        })();
    </script>
</body>
</html>
