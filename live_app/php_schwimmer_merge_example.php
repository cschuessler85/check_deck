<?php
/**
 * @doc-file
 * @doc-id live_app.php_schwimmer_merge_example
 * @doc-kind example
 * @doc-purpose Demonstriert oder testet das Zusammenfuehren von Schwimmerdaten in einer isolierten PHP-Beispieldatei.
 * @doc-entry Manueller Aufruf durch Entwickler.
 * @doc-input Beispieldaten und Merge-Logik.
 * @doc-output Beispielausgabe fuer Entwicklung oder Nachvollzug.
 * @doc-side-effects Kann Testdaten laden und Beispielergebnisse ausgeben.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

// @doc-section access-control
// @doc-purpose Diese Beispiel-Datei erlaubt Dateiuploads und das Ausfuehren eines Python-Skripts.
// Sie ist nur fuer authentifizierte Administratoren mit Recht zur Teilnehmerverwaltung bestimmt.
if (!auth_can($pdo, 'participants.edit')) {
    http_response_code(403);
    echo 'Zugriff verweigert.';
    exit;
}

function merge_example_candidate_script_paths(): array
{
    $candidates = [];

    $envPath = getenv('SCHWIMMER_MERGE_SCRIPT');
    if (is_string($envPath) && trim($envPath) !== '') {
        $candidates[] = trim($envPath);
    }

    $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . 'merge_schwimmerdaten.py';
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'live_web' . DIRECTORY_SEPARATOR . 'merge_schwimmerdaten.py';
    $candidates[] = getcwd() . DIRECTORY_SEPARATOR . 'merge_schwimmerdaten.py';

    return array_values(array_unique($candidates));
}

function merge_example_find_python_script(): ?string
{
    foreach (merge_example_candidate_script_paths() as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function merge_example_safe_stem(string $filename, string $fallback = 'schwimmerdaten'): string
{
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    $stem = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem ?? '');
    $stem = trim((string) $stem, '_');
    return $stem !== '' ? $stem : $fallback;
}

$message = '';
$downloadFile = '';
$maxFileBytes = 5 * 1024 * 1024; // 5 MB Limit pro Datei

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
        $message = 'Sicherheitspruefung fehlgeschlagen. Bitte Seite neu laden.';
    } else {
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp_uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $csvUpload = $_FILES['schwimmer_csv'] ?? null;
    $jsonUpload = $_FILES['backup_json'] ?? null;

    if (!$csvUpload || !$jsonUpload || $csvUpload['error'] !== UPLOAD_ERR_OK || $jsonUpload['error'] !== UPLOAD_ERR_OK) {
        $message = 'Bitte beide Dateien gueltig auswaehlen.';
    } elseif ($csvUpload['size'] > $maxFileBytes || $jsonUpload['size'] > $maxFileBytes) {
        $message = 'Datei ist zu gross. Maximal ' . ($maxFileBytes / 1024 / 1024) . ' MB pro Datei.';
    } elseif (!preg_match('/\.csv$/i', (string)$csvUpload['name'])) {
        $message = 'CSV-Datei muss die Endung .csv haben.';
    } elseif (!preg_match('/\.json$/i', (string)$jsonUpload['name'])) {
        $message = 'JSON-Datei muss die Endung .json haben.';
    } else {
        $jobId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $jobDir = $uploadDir . DIRECTORY_SEPARATOR . $jobId;
        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0775, true);
        }

        $csvBaseName = basename((string) $csvUpload['name']);
        $jsonBaseName = basename((string) $jsonUpload['name']);
        $csvStem = merge_example_safe_stem($csvBaseName);

        $csvPath = $jobDir . DIRECTORY_SEPARATOR . $csvBaseName;
        $jsonPath = $jobDir . DIRECTORY_SEPARATOR . $jsonBaseName;
        $outputFileName = $csvStem . '_aktualisiert.csv';
        $outputPath = $jobDir . DIRECTORY_SEPARATOR . $outputFileName;

        if (!move_uploaded_file($csvUpload['tmp_name'], $csvPath)) {
            $message = 'CSV-Datei konnte nicht gespeichert werden.';
        } elseif (!move_uploaded_file($jsonUpload['tmp_name'], $jsonPath)) {
            $message = 'JSON-Datei konnte nicht gespeichert werden.';
        } else {
            $pythonBinary = trim((string) (getenv('PYTHON_BIN') ?: 'python'));
            $scriptPath = merge_example_find_python_script();

            if ($scriptPath === null) {
                $message = "Python-Skript nicht gefunden.\n\nGepruefte Pfade:\n" . implode("\n", merge_example_candidate_script_paths());
            } else {
                $command = sprintf(
                    '%s %s --csv %s --json %s --output %s 2>&1',
                    escapeshellcmd($pythonBinary),
                    escapeshellarg($scriptPath),
                    escapeshellarg($csvPath),
                    escapeshellarg($jsonPath),
                    escapeshellarg($outputPath)
                );

                $output = shell_exec($command);

                if (is_file($outputPath)) {
                    $message = "Verarbeitung abgeschlossen.\n\n" . trim((string) $output);
                    $downloadFile = 'tmp_uploads/' . rawurlencode($jobId) . '/' . rawurlencode($outputFileName);
                } else {
                    $message = "Verarbeitung fehlgeschlagen.\n\n" . trim((string) $output);
                }
            }
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Schwimmerdaten zusammenfuehren</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 720px; margin: 2rem auto; }
        form { border: 1px solid #ccc; padding: 1rem; border-radius: 8px; }
        .field { margin-top: 1rem; }
        .pick-button, .submit-button { margin-top: 0.4rem; padding: 0.6rem 1rem; }
        .file-name { display: block; margin-top: 0.4rem; color: #444; }
        .hidden-input { display: none; }
        pre { white-space: pre-wrap; background: #f6f6f6; padding: 1rem; border-radius: 8px; }
    </style>
</head>
<body>
    <h1>Schwimmerdaten zusammenfuehren</h1>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>">
        <div class="field">
            <strong>Schwimmer-CSV</strong><br>
            <input class="hidden-input" id="schwimmerCsvInput" type="file" name="schwimmer_csv" accept=".csv,text/csv" required>
            <button class="pick-button" type="button" data-target="schwimmerCsvInput">Datei auswaehlen</button>
            <span class="file-name" id="schwimmerCsvName">Keine Datei ausgewaehlt</span>
        </div>

        <div class="field">
            <strong>JSON-Backup</strong><br>
            <input class="hidden-input" id="backupJsonInput" type="file" name="backup_json" accept=".json,application/json" required>
            <button class="pick-button" type="button" data-target="backupJsonInput">Datei auswaehlen</button>
            <span class="file-name" id="backupJsonName">Keine Datei ausgewaehlt</span>
        </div>

        <button class="submit-button" type="submit">Dateien verarbeiten</button>
    </form>

    <?php if ($message !== ''): ?>
        <h2>Ausgabe</h2>
        <pre><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></pre>
    <?php endif; ?>

    <?php if ($downloadFile !== ''): ?>
        <p><a href="<?php echo htmlspecialchars($downloadFile, ENT_QUOTES, 'UTF-8'); ?>">Ergebnisdatei herunterladen</a></p>
    <?php endif; ?>

    <script>
        document.querySelectorAll('.pick-button').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.target);
                if (input) {
                    input.click();
                }
            });
        });

        const csvInput = document.getElementById('schwimmerCsvInput');
        const jsonInput = document.getElementById('backupJsonInput');
        const csvName = document.getElementById('schwimmerCsvName');
        const jsonName = document.getElementById('backupJsonName');

        csvInput.addEventListener('change', () => {
            csvName.textContent = csvInput.files.length ? csvInput.files[0].name : 'Keine Datei ausgewaehlt';
        });

        jsonInput.addEventListener('change', () => {
            jsonName.textContent = jsonInput.files.length ? jsonInput.files[0].name : 'Keine Datei ausgewaehlt';
        });
    </script>
</body>
</html>
