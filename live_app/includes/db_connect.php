<?php
/**
 * @doc-file
 * @doc-id live_app.includes.db_connect
 * @doc-kind bootstrap
 * @doc-purpose Initialisiert Umgebung, Session, Datenbankverbindung und Audit-Kontext und ist die einzige
 *             Quelle fuer die Session-Bootstrap-Hilfsfunktionen des Projekts.
 * @doc-entry Wird von privaten PHP-Seiten und CLI-Skripten frueh eingebunden.
 * @doc-input Umgebungswerte und Session sowie, im Erstfall, den Migrationsaufruf aus scripts/migrate.php.
 * @doc-output Verfuegbare PDO-Verbindung und initialisierte Laufzeitumgebung.
 * @doc-side-effects Startet die Session, setzt das PHP-Fehlerverhalten, verbindet die Datenbank, setzt die
 *                  Audit-Session-Variablen und stoesst einmalig die Schema-Migration an.
 * @doc-note Seit der Entkopplung der Migrationen fuehrt diese Datei kein DDL mehr im Requestpfad aus.
 *           Solange storage/.schema_v2.migrated fehlt, wird scripts/migrate.php genau einmal eingebunden.
 * @doc-note Mit dem Konstanten-Schalter APP_MIGRATION_AUTORUN_DISABLED kann der automatische
 *           Migrationslauf unterdrueckt werden, etwa beim direkten CLI-Aufruf von migrate.php.
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// @doc-section session-bootstrap
// @doc-purpose Kapselt Ermittlung des Session-Speicherpfads, Verzeichnisanlage und Session-Start.

/**
 * @doc-function live_app.includes.db_connect.app_session_configured_path
 * @doc-purpose Liest den in der PHP-Konfiguration hinterlegten Session-Speicherpfad aus.
 * @doc-return string|null Nutzbarer Pfad aus der PHP-Konfiguration oder null, wenn keiner gesetzt ist.
 */
if (!function_exists('app_session_configured_path')) {
    function app_session_configured_path(): ?string
    {
        $raw = trim((string)session_save_path());
        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/[;,]/', $raw);
        if (!is_array($parts) || $parts === []) {
            return $raw;
        }

        $candidate = trim((string)end($parts));
        return $candidate !== '' ? $candidate : $raw;
    }
}

/**
 * @doc-function live_app.includes.db_connect.app_session_ensure_storage_dir
 * @doc-purpose Stellt sicher, dass das angegebene Session-Verzeichnis existiert und beschreibbar ist.
 * @doc-param string $path Gewuenschter Verzeichnispfad.
 * @doc-return string|null Verzeichnispfad bei Erfolg, sonst null.
 */
if (!function_exists('app_session_ensure_storage_dir')) {
    function app_session_ensure_storage_dir(string $path): ?string
    {
        $target = trim($path);
        if ($target === '') {
            return null;
        }

        if (!is_dir($target)) {
            try {
                mkdir($target, 0775, true);
            } catch (Throwable $e) {
                return null;
            }
        }

        return is_dir($target) && is_writable($target) ? $target : null;
    }
}

/**
 * @doc-function live_app.includes.db_connect.app_session_bootstrap_path
 * @doc-purpose Ermittelt einen beschreibbaren Session-Speicherpfad mit kontrollierten Ausweichzielen.
 * @doc-return string|null Nutzbarer Session-Pfad oder null im CLI-Betrieb.
 * @doc-note Webzugriffe weichen bei fehlerhaftem PHP-Sessionpfad bewusst auf storage/sessions und
 *           anschliessend auf das System-Temp-Verzeichnis aus.
 */
if (!function_exists('app_session_bootstrap_path')) {
    function app_session_bootstrap_path(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $fallbacks = [
            APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions',
            rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dlrg_24h_sessions',
        ];

        foreach ($fallbacks as $fallback) {
            $ready = app_session_ensure_storage_dir($fallback);
            if ($ready !== null) {
                return $ready;
            }
        }

        $configured = app_session_configured_path();
        return $configured;
    }
}

/**
 * @doc-function live_app.includes.db_connect.safe_substr
 * @doc-purpose Kuerzt eine Zeichenkette multibyte-sicher und faellt auf substr zurueck.
 * @doc-param mixed $value Ausgangswert, wird in einen String umgewandelt.
 * @doc-param int $start Startposition.
 * @doc-param int|null $length Optionale Laenge.
 * @doc-return string Gekuerzte Zeichenkette.
 */
if (!function_exists('safe_substr')) {
    function safe_substr($value, $start, $length = null)
    {
        $value = (string)$value;
        $start = (int)$start;
        $length = $length === null ? null : (int)$length;
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
        }
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (!isset($_ENV) || !is_array($_ENV)) {
    $_ENV = [];
}

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $sessionPath = app_session_bootstrap_path();
    if (is_string($sessionPath) && $sessionPath !== '') {
        ini_set('session.save_path', $sessionPath);
        session_save_path($sessionPath);
    }
    $params = session_get_cookie_params();
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => (int)($params['lifetime'] ?? 0),
            'path' => (string)($params['path'] ?? '/'),
            'domain' => (string)($params['domain'] ?? ''),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_start();
}

// @doc-note Im CLI-Betrieb existiert bewusst keine persistente Session; ein leeres Array haelt den Zugriff
//           fuer Skripte dennoch fehlerfrei, die $_SESSION direkt adressieren.
if (PHP_SAPI === 'cli' && (!isset($_SESSION) || !is_array($_SESSION))) {
    $_SESSION = [];
}

// @doc-section env-loading
// @doc-purpose Laedt Konfiguration aus bekannten .env-Dateien und macht sie ueber getenv und $_ENV verfuegbar.
foreach ([APP_ROOT . '/config/.env', APP_ROOT . '/.env'] as $dotenvPath) {
    if (!is_readable($dotenvPath)) {
        continue;
    }
    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        continue;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        if ($key === '') {
            continue;
        }
        $val = trim(substr($line, $pos + 1));
        $len = strlen($val);
        if ($len >= 2) {
            $first = $val[0];
            $last = $val[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
            putenv($key . '=' . $val);
        }
    }
    break;
}

$appEnv = getenv('APP_ENV');
if ($appEnv === false || $appEnv === '') {
    $appEnv = isset($_ENV['APP_ENV']) ? (string)$_ENV['APP_ENV'] : 'production';
}
$appEnv = strtolower(trim((string)$appEnv));
$isDev = in_array($appEnv, ['development', 'dev', 'local'], true);

$appDebug = getenv('APP_DEBUG');
$errorLogPath = APP_ROOT . '/storage/logs/php-error.log';
if (is_dir(dirname($errorLogPath)) && is_writable(dirname($errorLogPath))) {
    ini_set('log_errors', '1');
    ini_set('error_log', $errorLogPath);
}
if ($appDebug === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', $isDev ? '1' : '0');
    ini_set('display_startup_errors', $isDev ? '1' : '0');
    set_exception_handler(static function (Throwable $e) use ($isDev): void {
        error_log('Unbehandelte Exception: ' . get_class($e) . ': ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        if ($isDev) {
            echo 'Unbehandelte Exception: ' . get_class($e) . "\n";
            echo $e->getMessage() . "\n";
            return;
        }
        echo 'Interner Serverfehler.';
    });
    register_shutdown_function(static function () use ($isDev): void {
        $err = error_get_last();
        if ($err && isset($err['type'], $err['message'], $err['file'], $err['line'])) {
            error_log('Fataler Fehler: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            if ($isDev) {
                echo 'Fataler Fehler: ' . $err['message'] . "\n";
                echo $err['file'] . ':' . $err['line'] . "\n";
                return;
            }
            echo 'Interner Serverfehler.';
        }
    });
}

$env = static function (string $key): ?string {
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    return null;
};

$envFlag = static function (string $key, bool $default = false) use ($env): bool {
    $value = $env($key);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
};

// @doc-section database-bootstrap
// @doc-purpose Validiert die Datenbankkonfiguration und baut eine robuste PDO-Verbindung auf.
$dbHost = $env('DB_HOST');
$dbName = $env('DB_NAME');
$dbUser = $env('DB_USER');
$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    $dbPass = isset($_ENV['DB_PASS']) ? (string)$_ENV['DB_PASS'] : null;
}

if ($dbHost === null || $dbName === null || $dbUser === null || $dbPass === null) {
    http_response_code(500);
    echo 'Serverkonfiguration unvollständig (DB_HOST/DB_NAME/DB_USER/DB_PASS).';
    exit;
}

if (!class_exists('PDO')) {
    http_response_code(500);
    echo 'PHP-Erweiterung PDO fehlt.';
    exit;
}
try {
    $drivers = PDO::getAvailableDrivers();
    if (!in_array('mysql', $drivers, true)) {
        http_response_code(500);
        echo 'PDO-MySQL Treiber fehlt (pdo_mysql).';
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'PDO-Treiberprüfung fehlgeschlagen.';
    exit;
}

try {
    // @doc-note Ein kurzer Connect-Timeout verhindert haengende Seitenaufrufe, wenn lokal oder auf Testsystemen kein MySQL erreichbar ist.
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ];
    $pdo = new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => $pdoOptions[PDO::ATTR_ERRMODE],
        PDO::ATTR_DEFAULT_FETCH_MODE => $pdoOptions[PDO::ATTR_DEFAULT_FETCH_MODE],
        PDO::ATTR_EMULATE_PREPARES => $pdoOptions[PDO::ATTR_EMULATE_PREPARES],
        PDO::ATTR_TIMEOUT => $pdoOptions[PDO::ATTR_TIMEOUT],
    ]);
} catch (PDOException $e) {
    if (!$isDev) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Datenbankverbindung fehlgeschlagen.';
        exit;
    }
    $message = $e->getMessage();
    $hostUnavailable = str_contains($message, '[2002]')
        || stripos($message, 'Connection refused') !== false
        || stripos($message, 'No connection could be made') !== false
        || stripos($message, 'A connection attempt failed') !== false;
    if ($hostUnavailable) {
        error_log('DB connection failed: ' . $message);
        http_response_code(500);
        echo 'Datenbankverbindung fehlgeschlagen.';
        exit;
    }
    try {
        $pdoServer = new PDO('mysql:host=' . $dbHost . ';charset=utf8mb4', $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => $pdoOptions[PDO::ATTR_ERRMODE],
            PDO::ATTR_DEFAULT_FETCH_MODE => $pdoOptions[PDO::ATTR_DEFAULT_FETCH_MODE],
            PDO::ATTR_EMULATE_PREPARES => $pdoOptions[PDO::ATTR_EMULATE_PREPARES],
            PDO::ATTR_TIMEOUT => $pdoOptions[PDO::ATTR_TIMEOUT],
        ]);
        try {
            $pdoServer->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e2) {
        }
        $pdo = new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => $pdoOptions[PDO::ATTR_ERRMODE],
            PDO::ATTR_DEFAULT_FETCH_MODE => $pdoOptions[PDO::ATTR_DEFAULT_FETCH_MODE],
            PDO::ATTR_EMULATE_PREPARES => $pdoOptions[PDO::ATTR_EMULATE_PREPARES],
            PDO::ATTR_TIMEOUT => $pdoOptions[PDO::ATTR_TIMEOUT],
        ]);
    } catch (PDOException $e3) {
        error_log('DB connection failed: ' . $e3->getMessage());
        http_response_code(500);
        echo 'Datenbankverbindung fehlgeschlagen.';
        exit;
    }
}

// @doc-section audit-context
// @doc-purpose Leitet den ausloesenden Benutzer fuer SQL-Trigger her und legt Hashwerte fuer optionale Audit-Metadaten fest.
$auditUserId = null;
$auditUsername = null;
$auditRole = null;

$sessionUserId = $_SESSION['user_id'] ?? null;
if (is_int($sessionUserId) || (is_string($sessionUserId) && ctype_digit($sessionUserId))) {
    $auditUserId = (int)$_SESSION['user_id'];
    $auditUsername = isset($_SESSION['username']) && is_string($_SESSION['username']) ? safe_substr($_SESSION['username'], 0, 60) : null;
    $auditRole = isset($_SESSION['role']) && is_string($_SESSION['role']) ? safe_substr($_SESSION['role'], 0, 20) : null;
} elseif (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $auditUserId = -1;
    $auditUsername = 'Admin';
    $auditRole = 'admin';
} else {
    $auditHttpUser = null;
    if (!empty($_SERVER['REMOTE_USER']) && is_string($_SERVER['REMOTE_USER'])) {
        $auditHttpUser = trim((string)$_SERVER['REMOTE_USER']);
    } elseif (!empty($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_USER'])) {
        $auditHttpUser = trim((string)$_SERVER['PHP_AUTH_USER']);
    }
    if ($auditHttpUser !== null && $auditHttpUser !== '') {
        $auditHttpUser = safe_substr($auditHttpUser, 0, 60);
        $auditUserId = -2;
        $auditUsername = $auditHttpUser;
        $auditRole = 'admin';
        try {
            $pdo->query('SELECT 1 FROM users LIMIT 1');
            $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0) {
                $stmt = $pdo->prepare('SELECT id, role FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$auditHttpUser]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $auditUserId = (int)($row['id'] ?? 0);
                    $auditRole = (string)($row['role'] ?? 'viewer');
                    if ($auditRole === 'numbers') {
                        $auditRole = 'orga';
                    }
                } else {
                    $auditUserId = null;
                    $auditUsername = 'public';
                    $auditRole = 'public';
                }
            }
        } catch (Throwable $e) {
        }
    } else {
        $auditUsername = 'public';
        $auditRole = 'public';
    }
}

try {
    $stmt = $pdo->prepare('SET @audit_user_id = ?, @audit_username = ?, @audit_role = ?');
    $stmt->execute([$auditUserId, $auditUsername, $auditRole]);
} catch (Throwable $e) {
}

// @doc-section schema-migration
// @doc-purpose Prueft per Marker-Datei, ob das Schema bereits initialisiert wurde, und stoesst andernfalls
//             genau einmal das entkoppelte Migrationsmodul scripts/migrate.php an.
// @doc-note Der normale Requestpfad fuehrt kein DDL aus; sobald storage/.schema_v2.migrated existiert,
//           entfaellt jede weitere Pruefung und es wird ausschliesslich die Dateiexistenz geprueft.
// @doc-note Die Parallelitaetssicherung (Advisory File-Lock) sowie das Schreiben der Marker-Datei liegen
//           vollstaendig in app_migration_maybe_run() aus scripts/migrate.php.
// @doc-note Ist APP_MIGRATION_AUTORUN_DISABLED definiert, wird der automatische Lauf unterdrueckt, damit
//           der direkte CLI-Aufruf von scripts/migrate.php die Ausfuehrung allein steuert.
$appMigrationMarker = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.schema_v2.migrated';
if (!defined('APP_MIGRATION_AUTORUN_DISABLED') && !is_file($appMigrationMarker)) {
    require_once APP_ROOT . '/scripts/migrate.php';
    if (function_exists('app_migration_maybe_run')) {
        app_migration_maybe_run($pdo, ['source' => 'web']);
    }
}
