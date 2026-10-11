<?php
/**
 * @doc-file
 * @doc-id live_app.dashboard.dashboard
 * @doc-kind page-controller
 * @doc-purpose Erzeugt die kompakte interne Dashboard-Uebersicht mit Kennzahlen und Schnellstatus.
 * @doc-entry HTTP-Aufruf der Dashboard-Seite.
 * @doc-input Datenbank, Berechtigungen und Hilfsfunktionen.
 * @doc-output HTML-Dashboard mit Kennzahlen.
 * @doc-side-effects Fuehrt Statistikabfragen aus und rendert eine Live-Uebersicht.
 */
declare(strict_types=1);
 
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

// @doc-section access-control
// @doc-purpose Das Dashboard zeigt aggregierte Teilnehmerstatistiken und ist nur fuer angemeldete Benutzer sichtbar.
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
 
$can_numbers = auth_can($pdo, 'numbers.read');
$can_export = auth_can($pdo, 'exports.participants');
 
function dash_dt(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '—';
    }
    return date('d.m.Y H:i', $ts);
}
 
$overview = [
    'gesamt' => 0,
    'kinder' => 0,
    'erwachsene' => 0,
    'mit_team' => 0,
    'ohne_team' => 0,
    'bezahlt' => 0,
    'offen' => 0,
    'first_reg' => null,
    'last_reg' => null,
];
$windows = [
    'cnt_60m' => 0,
    'cnt_24h' => 0,
    'cnt_today' => 0,
];
$teams_total = 0;
$recent_rows = [];
$startnum_counts = ['frei' => 0, 'reserviert' => 0, 'vergeben' => 0];
 
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS gesamt,
            SUM(CASE WHEN volljaehrig = 0 THEN 1 ELSE 0 END) AS kinder,
            SUM(CASE WHEN volljaehrig = 1 THEN 1 ELSE 0 END) AS erwachsene,
            SUM(CASE WHEN team_id IS NOT NULL THEN 1 ELSE 0 END) AS mit_team,
            SUM(CASE WHEN team_id IS NULL THEN 1 ELSE 0 END) AS ohne_team,
            SUM(CASE WHEN bezahlt = 1 THEN 1 ELSE 0 END) AS bezahlt,
            SUM(CASE WHEN bezahlt = 0 THEN 1 ELSE 0 END) AS offen,
            MIN(registriert_am) AS first_reg,
            MAX(registriert_am) AS last_reg
        FROM teilnehmer
    ");
    $overview = $stmt->fetch(PDO::FETCH_ASSOC) ?: $overview;
 
    $stmt = $pdo->query("
        SELECT
            SUM(CASE WHEN registriert_am >= NOW() - INTERVAL 60 MINUTE THEN 1 ELSE 0 END) AS cnt_60m,
            SUM(CASE WHEN registriert_am >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS cnt_24h,
            SUM(CASE WHEN DATE(registriert_am) = CURDATE() THEN 1 ELSE 0 END) AS cnt_today
        FROM teilnehmer
    ");
    $windows = $stmt->fetch(PDO::FETCH_ASSOC) ?: $windows;
 
    $teams_total = (int)($pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn() ?: 0);
 
    $stmt = $pdo->query("
        SELECT
            CASE WHEN team_id IS NOT NULL THEN 1 ELSE 0 END AS has_team,
            bezahlt,
            registriert_am
        FROM teilnehmer
        ORDER BY registriert_am DESC, id DESC
        LIMIT 8
    ");
    $recent_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
 
    if ($can_numbers) {
        $stmt = $pdo->query("
            SELECT status, COUNT(*) AS cnt
            FROM startnummern
            GROUP BY status
        ");
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $status = (string)($row['status'] ?? '');
            if (array_key_exists($status, $startnum_counts)) {
                $startnum_counts[$status] = (int)($row['cnt'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Dashboard-Statistik konnte nicht geladen werden: ' . $e->getMessage());
    http_response_code(500);
    echo 'Fehler beim Laden der Statistik.';
    exit;
}
 
$gesamt = (int)($overview['gesamt'] ?? 0);
$kinder = (int)($overview['kinder'] ?? 0);
$erwachsene = (int)($overview['erwachsene'] ?? 0);
$mitTeam = (int)($overview['mit_team'] ?? 0);
$ohneTeam = (int)($overview['ohne_team'] ?? 0);
$bezahlt = (int)($overview['bezahlt'] ?? 0);
$offen = (int)($overview['offen'] ?? 0);
$firstReg = is_string($overview['first_reg'] ?? null) ? $overview['first_reg'] : null;
$lastReg = is_string($overview['last_reg'] ?? null) ? $overview['last_reg'] : null;
$cnt60m = (int)($windows['cnt_60m'] ?? 0);
$cnt24h = (int)($windows['cnt_24h'] ?? 0);
$cntToday = (int)($windows['cnt_today'] ?? 0);
$paidPct = $gesamt > 0 ? (int)round(($bezahlt / $gesamt) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard – 24‑Stunden‑Schwimmen</title>
    <link rel="stylesheet" href="<?php echo escape($rootBase . '/assets/style.css'); ?>" />
    <style>
        body {
            background:
                radial-gradient(900px 500px at 20% 10%, rgba(255, 237, 0, 0.24), transparent 60%),
                radial-gradient(900px 500px at 80% 10%, rgba(5, 105, 180, 0.14), transparent 62%),
                linear-gradient(180deg, #8c0710 0%, #e30613 48%, #e30613 100%);
        }
 
        .dash-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: start;
            margin-bottom: 18px;
        }
 
        .dash-title h1 {
            margin: 0 0 6px 0;
            font-size: 30px;
            line-height: 1.05;
        }
 
        .dash-lead {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }
 
        .dash-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 12px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }
 
        .dash-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.18);
        }
 
        .dash-dot[data-live="1"] {
            background: #28a745;
            box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.45);
            animation: dashpulse 1.2s ease-out infinite;
        }
 
        @keyframes dashpulse {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.45); }
            100% { box-shadow: 0 0 0 12px rgba(40, 167, 69, 0); }
        }
 
        .dash-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
 
        .dash-kpis {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
 
        .dash-card {
            border: 1px solid var(--border);
            border-radius: 16px;
            background: #fff;
            padding: 18px;
        }
 
        .dash-card-muted {
            color: var(--muted);
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
 
        .dash-card-value {
            margin-top: 10px;
            font-size: 34px;
            font-weight: 950;
            line-height: 1;
        }
 
        .dash-card-note {
            margin-top: 8px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }
 
        .dash-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(300px, 0.85fr);
            gap: 12px;
        }
 
        .dash-panel-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
 
        .dash-panel-title h2 {
            margin: 0;
            font-size: 18px;
        }
 
        .dash-list {
            display: grid;
            gap: 12px;
        }
 
        .dash-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: center;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.07);
        }
 
        .dash-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
 
        .dash-row strong {
            display: block;
            margin-bottom: 2px;
            font-size: 16px;
        }
 
        .dash-row-value {
            font-size: 16px;
            font-weight: 900;
            white-space: nowrap;
        }
 
        .dash-progress {
            margin-top: 14px;
            height: 12px;
            border-radius: 999px;
            background: #edf0f3;
            overflow: hidden;
        }
 
        .dash-progress > span {
            display: block;
            width: calc(var(--pct, 0) * 1%);
            height: 100%;
            background: linear-gradient(90deg, #28a745 0%, #7bc96f 100%);
        }
 
        .dash-status {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }
 
        .dash-chip {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 7px 10px;
            border-radius: 999px;
            background: #f4f6f8;
            color: #24313d;
            font-size: 13px;
            font-weight: 800;
        }
 
        .dash-chip.ok {
            background: rgba(40, 167, 69, 0.12);
            color: #1f6f35;
        }
 
        .dash-chip.warn {
            background: rgba(227, 6, 19, 0.10);
            color: #9f1220;
        }
 
        .dash-table {
            min-width: 0;
        }
 
        .dash-table table {
            min-width: 100%;
        }
 
        .dash-table th {
            background: #f4f6f8;
            color: var(--muted);
            font-size: 11px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
 
        .dash-table td {
            vertical-align: middle;
        }
 
        .dash-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 800;
        }
 
        .dash-badge-paid {
            background: rgba(40, 167, 69, 0.12);
            color: #1f6f35;
        }
 
        .dash-badge-open {
            background: rgba(227, 6, 19, 0.10);
            color: #9f1220;
        }
 
        @media (max-width: 980px) {
            .dash-head,
            .dash-layout,
            .dash-kpis {
                grid-template-columns: 1fr;
            }
 
            .dash-actions {
                justify-content: flex-start;
            }
        }
 
        @media (max-width: 680px) {
            .dash-title h1 {
                font-size: 26px;
            }

            .dash-actions {
                width: 100%;
            }

            .dash-actions > .btn,
            .dash-actions > a,
            .dash-actions > button {
                width: 100%;
            }

            .dash-card-value {
                font-size: 30px;
            }
 
            .dash-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="container dash-container">
            <div class="dash-head">
                <div class="dash-title">
                    <div class="brand">
                        <h1>Dashboard</h1>
                        <span class="badge">Live</span>
                    </div>
                    <p class="dash-lead">Die wichtigsten Basisinfos auf einen Blick.</p>
                    <div class="dash-meta">
                        <span class="dash-dot" id="liveDot" data-live="1"></span>
                        <span id="clockLabel">—</span>
                        <span>Letztes Update: <span id="lastUpdate">—</span></span>
                        <span>Letzte Anmeldung: <?php echo escape(dash_dt($lastReg)); ?></span>
                    </div>
                </div>
                <div class="dash-actions">
                    <a class="btn btn-secondary" href="<?php echo escape($rootBase . '/admin.php'); ?>">Admin</a>
                    <?php if ($can_numbers): ?>
                        <a class="btn btn-yellow" href="<?php echo escape($rootBase . '/numbers.php'); ?>">Startnummern</a>
                    <?php endif; ?>
                    <?php if ($can_export): ?>
                        <a class="btn" href="<?php echo escape($rootBase . '/export.php'); ?>">Export</a>
                    <?php endif; ?>
                    <button class="btn btn-secondary" type="button" id="toggleAutoRefresh" data-enabled="1">Auto‑Refresh: an</button>
                    <form method="post" action="<?php echo escape($rootBase . '/admin.php'); ?>" style="display:inline;"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>"><button type="submit" class="btn btn-secondary">Abmelden</button></form>
                </div>
            </div>
 
            <div class="dash-kpis">
                <div class="dash-card">
                    <div class="dash-card-muted">Teilnehmer gesamt</div>
                    <div class="dash-card-value"><?php echo escape((string)$gesamt); ?></div>
                    <div class="dash-card-note"><?php echo escape((string)$cntToday); ?> heute</div>
                </div>
                <div class="dash-card">
                    <div class="dash-card-muted">Letzte 60 Minuten</div>
                    <div class="dash-card-value"><?php echo escape((string)$cnt60m); ?></div>
                    <div class="dash-card-note"><?php echo escape((string)$cnt24h); ?> in den letzten 24 Stunden</div>
                </div>
                <div class="dash-card">
                    <div class="dash-card-muted">Bezahlt</div>
                    <div class="dash-card-value"><?php echo escape((string)$bezahlt); ?></div>
                    <div class="dash-card-note"><?php echo escape((string)$offen); ?> offen</div>
                </div>
                <div class="dash-card">
                    <div class="dash-card-muted">Teams</div>
                    <div class="dash-card-value"><?php echo escape((string)$teams_total); ?></div>
                    <div class="dash-card-note"><?php echo escape((string)$mitTeam); ?> Teilnehmer mit Team</div>
                </div>
            </div>
 
            <div class="dash-layout">
                <div class="panel">
                    <div class="dash-panel-title">
                        <h2>Aktueller Stand</h2>
                        <span class="muted">Kompakt</span>
                    </div>
 
                    <div class="dash-list">
                        <div class="dash-row">
                            <div>
                                <strong>Bezahlstatus</strong>
                                <span class="muted"><?php echo escape((string)$bezahlt); ?> bezahlt, <?php echo escape((string)$offen); ?> offen</span>
                            </div>
                            <div class="dash-row-value"><?php echo escape((string)$paidPct); ?>%</div>
                        </div>
 
                        <div class="dash-progress" aria-hidden="true">
                            <span style="--pct: <?php echo escape((string)$paidPct); ?>"></span>
                        </div>
 
                        <div class="dash-row">
                            <div>
                                <strong>Altersstruktur</strong>
                                <span class="muted"><?php echo escape((string)$erwachsene); ?> Erwachsene, <?php echo escape((string)$kinder); ?> Kinder/Jugendliche</span>
                            </div>
                            <div class="dash-row-value"><?php echo escape((string)$gesamt); ?> gesamt</div>
                        </div>
 
                        <div class="dash-row">
                            <div>
                                <strong>Teamstatus</strong>
                                <span class="muted"><?php echo escape((string)$mitTeam); ?> mit Team, <?php echo escape((string)$ohneTeam); ?> ohne Team</span>
                            </div>
                            <div class="dash-row-value"><?php echo escape((string)$teams_total); ?> Teams</div>
                        </div>
 
                        <div class="dash-row">
                            <div>
                                <strong>Anmeldezeitraum</strong>
                                <span class="muted">Erste Anmeldung: <?php echo escape(dash_dt($firstReg)); ?></span>
                            </div>
                            <div class="dash-row-value"><?php echo escape(dash_dt($lastReg)); ?></div>
                        </div>
                    </div>
 
                    <div class="dash-status">
                        <span class="dash-chip ok"><?php echo escape((string)$cntToday); ?> neue Anmeldungen heute</span>
                        <span class="dash-chip"><?php echo escape((string)$cnt24h); ?> Anmeldungen in 24h</span>
                        <?php if ($can_numbers): ?>
                            <span class="dash-chip"><?php echo escape((string)$startnum_counts['vergeben']); ?> Startnummern vergeben</span>
                            <span class="dash-chip"><?php echo escape((string)$startnum_counts['frei']); ?> frei</span>
                        <?php else: ?>
                            <span class="dash-chip warn">Startnummern nur mit Freigabe sichtbar</span>
                        <?php endif; ?>
                    </div>
                </div>
 
                <div class="panel">
                    <div class="dash-panel-title">
                        <h2>Letzte Anmeldungen</h2>
                        <span class="muted">Anonymisiert</span>
                    </div>
 
                    <div class="table-wrap dash-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Eintrag</th>
                                    <th>Typ</th>
                                    <th>Status</th>
                                    <th>Zeit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_rows)): ?>
                                    <?php foreach ($recent_rows as $row): ?>
                                        <?php
                                            $isPaid = (int)($row['bezahlt'] ?? 0) === 1;
                                            $hasTeam = (int)($row['has_team'] ?? 0) === 1;
                                        ?>
                                        <tr>
                                            <td>Neue Anmeldung</td>
                                            <td><?php echo $hasTeam ? 'mit Team' : 'ohne Team'; ?></td>
                                            <td>
                                                <span class="dash-badge <?php echo $isPaid ? 'dash-badge-paid' : 'dash-badge-open'; ?>">
                                                    <?php echo $isPaid ? 'bezahlt' : 'offen'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo escape(dash_dt(is_string($row['registriert_am'] ?? null) ? $row['registriert_am'] : null)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4">Noch keine Anmeldungen vorhanden.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
 
    <script src="<?php echo escape($rootBase . '/assets/app.js'); ?>?v=<?php echo escape(app_asset_version('assets/app.js')); ?>"><\/script>
    <script>
        (function () {
            var clockLabel = document.getElementById('clockLabel');
            var lastUpdate = document.getElementById('lastUpdate');
            var toggle = document.getElementById('toggleAutoRefresh');
            var liveDot = document.getElementById('liveDot');
 
            function fmt(dt) {
                var dd = String(dt.getDate()).padStart(2, '0');
                var mm = String(dt.getMonth() + 1).padStart(2, '0');
                var hh = String(dt.getHours()).padStart(2, '0');
                var mi = String(dt.getMinutes()).padStart(2, '0');
                var ss = String(dt.getSeconds()).padStart(2, '0');
                return dd + '.' + mm + '.' + dt.getFullYear() + ' ' + hh + ':' + mi + ':' + ss;
            }
 
            var auto = localStorage.getItem('dash_auto') !== '0';
 
            function renderToggle() {
                toggle.dataset.enabled = auto ? '1' : '0';
                toggle.textContent = auto ? 'Auto‑Refresh: an' : 'Auto‑Refresh: aus';
                liveDot.dataset.live = auto ? '1' : '0';
            }
 
            toggle.addEventListener('click', function () {
                auto = !auto;
                localStorage.setItem('dash_auto', auto ? '1' : '0');
                renderToggle();
            });
 
            renderToggle();
 
            var now = new Date();
            clockLabel.textContent = 'Uhr: ' + fmt(now);
            lastUpdate.textContent = fmt(now);
 
            setInterval(function () {
                clockLabel.textContent = 'Uhr: ' + fmt(new Date());
            }, 1000);
 
            setInterval(function () {
                if (!auto) {
                    return;
                }
                location.reload();
            }, 30000);
        })();
    <\/script>
</body>
</html>
