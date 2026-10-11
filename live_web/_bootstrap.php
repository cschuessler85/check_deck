<?php
/**
 * @doc-file
 * @doc-id live_web.bootstrap
 * @doc-kind bootstrap
 * @doc-purpose Lokalisiert den privaten Anwendungscode und stellt einen sicheren Loader fuer oeffentliche Wrapper-Routen bereit.
 * @doc-entry Wird von oeffentlichen PHP-Entry-Points eingebunden.
 * @doc-input Umgebungsvariable APP_PRIVATE_ROOT und Dateisystem.
 * @doc-output Geladene private PHP-Datei oder HTTP-500 bei Fehlkonfiguration.
 * @doc-side-effects Prueft Verzeichnisse und bindet Zielcode ein.
 */
declare(strict_types=1);

if (!function_exists('app_private_root')) {
    function app_private_root(): string
    {
        $envRoot = getenv('APP_PRIVATE_ROOT');
        if (is_string($envRoot) && $envRoot !== '' && is_dir($envRoot)) {
            return rtrim($envRoot, '/');
        }

        $baseDir = dirname(__DIR__);
        $candidates = [
            $baseDir,
            $baseDir . '/live_app',
            $baseDir . '/testing_app',
            $baseDir . '/app',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/includes/db_connect.php')) {
                return $candidate;
            }
        }

        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Anwendungswurzel konnte nicht gefunden werden.';
        exit;
    }
}

if (!function_exists('app_require_private')) {
    function app_require_private(string $relativePath): void
    {
        $root = app_private_root();
        $file = $root . '/' . ltrim($relativePath, '/');
        if (!is_file($file)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Anwendungsdatei fehlt: ' . $relativePath;
            exit;
        }
        require_once $file;
    }
}