<?php
/**
 * @doc-file
 * @doc-id live_app.sync
 * @doc-kind page-controller
 * @doc-purpose Synchronisiert bezahlte Teilnehmer mit Startnummer aus der MySQL-Datenbank in die SQLite-Live-Schwimmdatenbank.
 * @doc-entry HTTP-Aufruf durch berechtigte Benutzer (orga/numbers/admin).
 * @doc-input MySQL-Tabelle teilnehmer, SQLite-Tabelle schwimmer, Session-Berechtigungen.
 * @doc-output Vorschau oder Sync-Bericht als HTML.
 * @doc-side-effects Schreibt Stammdaten in die SQLite-Tabelle schwimmer; Live-Betriebsdaten (bahnanzahl, strecke, auf_bahn, aktiv) bleiben unveraendert.
 * @doc-note Nur Teilnehmer mit bezahlt=1 und einer vergebenen startnummer werden uebernommen.
 * @doc-note Bestehende schwimmer-Zeilen werden per Upsert ueber die Nummer aktualisiert; Spalten der Live-Zeitnahme werden niemals ueberschrieben.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/live_sync.php';

// @doc-section access-control
// @doc-purpose Der Sync veraendert die Live-Stammdaten und ist daher auf Rollen mit Bearbeitungsrecht beschraenkt.
if (!auth_can($pdo, 'participants.edit')) {
    http_response_code(403);
    echo 'Zugriff verweigert.';
    exit;
}

$currentUser = auth_current_user($pdo);
$currentUsername = (string)($currentUser['username'] ?? ($_SESSION['username'] ?? 'Admin'));
$currentRole = (string)($currentUser['role'] ?? ($_SESSION['role'] ?? 'admin'));
$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$assetBase = $assetBase === '' ? '' : $assetBase;
$is_standalone = isset($_GET['standalone']) && (string)$_GET['standalone'] === '1';

// @doc-section sync-functions
// @doc-purpose Die Sync-Funktionen (sync_run, sync_preview, ...) liegen zentral in includes/live_sync.php
//             (Single Source of Truth) und werden oben eingebunden.

// @doc-section request-handling
$sqlitePath = sync_sqlite_path();
$sqlite = sync_open_sqlite($sqlitePath);
$errorMessage = '';
$preview = null;
$result = null;

if ($sqlite === null) {
    $errorMessage = 'SQLite-Datenbank nicht gefunden unter: ' . $sqlitePath;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : (isset($_GET['action']) ? (string)$_GET['action'] : '');

if ($errorMessage === '') {
    if ($action === '' || $action === 'preview') {
        try {
            $preview = sync_preview($pdo, $sqlite);
        } catch (Throwable $e) {
            $errorMessage = 'Vorschau konnte nicht erstellt werden: ' . $e->getMessage();
        }
    } elseif ($action === 'run') {
        if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
            $errorMessage = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
            try {
                $preview = sync_preview($pdo, $sqlite);
            } catch (Throwable $e) {
                // Vorschau-Fehler nach einem CSRF-Fehler nicht ueberdecken
            }
        } else {
            try {
                $result = sync_run($pdo, $sqlite);
                $preview = sync_preview($pdo, $sqlite);
            } catch (Throwable $e) {
                $errorMessage = 'Sync fehlgeschlagen: ' . $e->getMessage();
                try {
                    $preview = sync_preview($pdo, $sqlite);
                } catch (Throwable $e2) {
                    // Vorschau-Fehler nach einem Sync-Fehler nicht ueberdecken
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Teilnehmer synchronisieren</title>
  <link rel="stylesheet" href="<?= sync_escape($assetBase . '/assets/style.css') ?>?v=<?= sync_escape(app_asset_version('assets/style.css')) ?>">
  <script>
    (() => {
      const key = 'admin_theme';
      let theme = '';
      try { theme = String(localStorage.getItem(key) || ''); } catch (e) { theme = ''; }
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
      --dlrg-schwarz: #2f3845;
    }
    html[data-theme="dark"] .panel,
    html[data-theme="dark"] .alert,
    html[data-theme="dark"] fieldset,
    html[data-theme="dark"] .table-wrap,
    html[data-theme="dark"] .stat-card {
      background: #141a24;
      color: var(--text);
    }
    html[data-theme="dark"] tr:nth-child(even) td { background: rgba(255, 255, 255, 0.03); }

    body {
      background:
        radial-gradient(900px 500px at 20% 10%, rgba(255, 237, 0, 0.24), transparent 60%),
        radial-gradient(900px 500px at 80% 10%, rgba(5, 105, 180, 0.14), transparent 62%),
        linear-gradient(180deg, #8c0710 0%, #e30613 48%, #e30613 100%);
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 14px;
      margin-bottom: 18px;
    }

    .stat-card {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 16px 18px;
      background: #fff;
    }

    .stat-card .stat-value {
      font-size: 34px;
      font-weight: 800;
      line-height: 1;
      color: var(--dlrg-rot);
    }

    .stat-card .stat-label {
      margin-top: 6px;
      font-size: 13px;
      color: var(--muted);
      font-weight: 600;
    }

    .sync-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin: 18px 0;
    }

    .table-wrap {
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 12px;
      max-height: 420px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 640px;
    }

    th, td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      text-align: left;
      white-space: nowrap;
      font-size: 14px;
    }

    thead th {
      background: var(--dlrg-rot);
      color: #fff;
      position: sticky;
      top: 0;
    }

    .badge {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
    }

    .badge-insert { background: #16a34a; color: #fff; }
    .badge-update { background: #0569b4; color: #fff; }

    .hint-box {
      background: #fff8e1;
      border: 1px solid #ffe082;
      border-radius: 12px;
      padding: 14px 16px;
      margin-bottom: 18px;
      font-size: 14px;
      line-height: 1.55;
    }

    html[data-theme="dark"] .hint-box {
      background: rgba(255, 237, 0, 0.08);
      border-color: rgba(255, 237, 0, 0.3);
    }

    @media (max-width: 720px) {
      .stat-card .stat-value { font-size: 26px; }
      .sync-actions .btn { width: 100%; }
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="container">
      <div class="app-logo-bar">
        <picture>
          <source type="image/webp" srcset="<?= sync_escape($assetBase . '/assets/check_deck_logo.webp') ?> 1600w" sizes="(max-width: 360px) 120px, 240px">
          <img src="<?= sync_escape($assetBase . '/assets/check_deck_logo.jpg') ?>" alt="Check Deck – Dein Eventmanager" class="reg-logo" width="1600" height="893" decoding="async">
        </picture>
      </div>
      <header class="reg-header">
        <h1 class="reg-title">
          <span>Admin</span>
          <span>Teilnehmer synchronisieren</span>
          <span>MySQL &rarr; SQLite Live-Daten</span>
        </h1>
      </header>

      <div class="page-shell<?php echo $is_standalone ? ' page-shell-standalone' : ''; ?>">
      <?php if (!$is_standalone): ?>
      <aside class="page-sidebar">
        <div class="panel sidebar-panel">
          <div class="sidebar-copy">
            <p class="sidebar-label">Sync</p>
            <h2 class="sidebar-title">Teilnehmer synchronisieren</h2>
            <p class="sidebar-subtitle">Bezahlte Teilnehmer mit Startnummer aus der Anmeldung in die Live-Schwimmdatenbank uebernehmen.</p>
          </div>
          <div class="hint admin-session-info" style="margin:0;">
            <span>Angemeldet als: <strong><?= sync_escape($currentUsername) ?></strong></span>
            <span class="admin-session-badge"><?= sync_escape($currentRole) ?></span>
          </div>
          <div class="sidebar-links">
            <button type="button" class="btn btn-secondary" id="theme_toggle" aria-pressed="false" title="Wechselt zwischen hellem und dunklem Design">Dunkelmodus</button>
            <a class="btn btn-secondary" href="admin.php" title="Wechselt zur Admin-Oberfläche">Admin</a>
            <a class="btn btn-secondary" href="numbers.php" title="Öffnet die Startnummernverwaltung">Startnummern</a>
            <a class="btn btn-secondary" href="export.php" title="Öffnet den Datenexport">Datenexport</a>
            <form method="post" action="admin.php" style="display:inline;"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>"><button type="submit" class="btn btn-secondary" title="Von der Synchronisation abmelden">Abmelden</button></form>
          </div>
        </div>
      </aside>
      <?php endif; ?>

      <div class="page-main">
      <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><strong>Fehler:</strong> <?= sync_escape($errorMessage) ?></div>
      <?php endif; ?>

      <?php if ($result !== null): ?>
        <div class="alert" style="background:#e8f5e9;border-color:#a5d6a7;">
          <strong>Sync abgeschlossen.</strong>
          <?= (int)$result['inserted'] ?> neu angelegt,
          <?= (int)$result['updated'] ?> aktualisiert
          (von <?= (int)$result['total_candidates'] ?> Kandidaten).
        </div>
      <?php endif; ?>

      <?php if ($preview !== null): ?>
        <div class="hint-box">
          <strong>So funktioniert der Sync:</strong><br>
          Es werden nur Teilnehmer &uuml;bernommen, die <strong>bezahlt</strong> sind und eine <strong>Startnummer</strong> haben.
          Bestehende Eintr&auml;ge in der Live-Datenbank werden anhand der Nummer aktualisiert &ndash;
          die geschwommenen Bahnen, Strecke und der aktive Status bleiben dabei <strong>unver&auml;ndert</strong> erhalten.
        </div>

        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-value"><?= (int)$preview['candidates'] ?></div>
            <div class="stat-label">Kandidaten (bezahlt + Startnr.)</div>
          </div>
          <div class="stat-card">
            <div class="stat-value" style="color:#16a34a;"><?= count($preview['to_insert']) ?></div>
            <div class="stat-label">Werden neu angelegt</div>
          </div>
          <div class="stat-card">
            <div class="stat-value" style="color:#0569b4;"><?= count($preview['to_update']) ?></div>
            <div class="stat-label">Werden aktualisiert</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= (int)$preview['existing_count'] ?></div>
            <div class="stat-label">Eintr&auml;ge in Live-DB</div>
          </div>
        </div>

        <div class="sync-actions">
          <form method="post" action="sync.php" style="display:inline;">
            <input type="hidden" name="action" value="run">
            <input type="hidden" name="csrf_token" value="<?= sync_escape(csrf_token_get()) ?>">
            <button class="btn btn-primary" type="submit" onclick="return confirm('Synchronisation jetzt durchfuehren? Die Stammdaten der Live-Datenbank werden aktualisiert.');" title="Führt die Synchronisation der Live-Datenbank mit den Stammdaten durch">
              Sync ausf&uuml;hren (<?= (int)$preview['candidates'] ?> Eintr&auml;ge)
            </button>
          </form>
          <a class="btn btn-secondary" href="sync.php" title="Lädt die Vorschau der zu synchronisierenden Daten neu">Vorschau aktualisieren</a>
        </div>

        <?php if (!empty($preview['to_insert'])): ?>
          <div class="panel" style="margin-bottom:18px;">
            <h2>Neu anzulegende Eintr&auml;ge (<?= count($preview['to_insert']) ?>)</h2>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr><th>Startnr.</th><th>Vorname</th><th>Nachname</th><th>Kind/Jugendlich</th><th>Team/Gruppe</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($preview['to_insert'] as $e): ?>
                    <tr>
                      <td><strong><?= (int)$e['nummer'] ?></strong></td>
                      <td><?= sync_escape($e['vorname']) ?></td>
                      <td><?= sync_escape($e['nachname']) ?></td>
                      <td><?= $e['istKind'] ? 'Ja' : 'Nein' ?></td>
                      <td><?= sync_escape($e['gruppe']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($preview['to_update'])): ?>
          <div class="panel">
            <h2>Zu aktualisierende Eintr&auml;ge (<?= count($preview['to_update']) ?>)</h2>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Startnr.</th>
                    <th>Name (neu)</th>
                    <th>Name (bisher)</th>
                    <th>Kind</th>
                    <th>Gruppe (neu)</th>
                    <th>Bahnen (bleibt)</th>
                    <th>Aktiv</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview['to_update'] as $e): ?>
                    <tr>
                      <td><strong><?= (int)$e['nummer'] ?></strong></td>
                      <td><?= sync_escape($e['vorname'] . ' ' . $e['nachname']) ?></td>
                      <td><?= sync_escape((string)($e['existing']['vorname'] ?? '') . ' ' . (string)($e['existing']['nachname'] ?? '')) ?></td>
                      <td><?= $e['istKind'] ? 'Ja' : 'Nein' ?></td>
                      <td><?= sync_escape($e['gruppe']) ?></td>
                      <td><?= (int)($e['existing']['bahnanzahl'] ?? 0) ?></td>
                      <td><?= !empty($e['existing']['aktiv']) ? 'Ja' : 'Nein' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if (empty($preview['to_insert']) && empty($preview['to_update']) && $preview['candidates'] === 0): ?>
          <div class="panel">
            <p class="helper">Keine Kandidaten gefunden. Es gibt derzeit keine bezahlten Teilnehmer mit zugewiesener Startnummer.</p>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      var root = document.documentElement;
      var themeButton = document.getElementById('theme_toggle');
      function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        if (!themeButton) return;
        themeButton.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        themeButton.textContent = theme === 'dark' ? 'Hellmodus' : 'Dunkelmodus';
      }
      applyTheme(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
      if (themeButton) {
        themeButton.addEventListener('click', function () {
          var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
          applyTheme(next);
          try { localStorage.setItem('admin_theme', next); } catch (e) {}
        });
      }
    })();
  </script>
</body>
</html>
