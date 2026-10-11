<?php
/**
 * @doc-file
 * @doc-id live_app.includes.db_connect
 * @doc-kind bootstrap
 * @doc-purpose Initialisiert Umgebung, Session, Datenbankverbindung, Audit-Kontext und notwendige Auto-Migrationen.
 * @doc-entry Wird von privaten PHP-Seiten frueh eingebunden.
 * @doc-input Umgebungswerte, Session, PDO und Datenbankschema.
 * @doc-output Verfuegbare PDO-Verbindung und initialisierte Laufzeitumgebung.
 * @doc-side-effects Startet Session, setzt PHP-Fehlerverhalten, verbindet DB und fuehrt DDL aus.
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

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

if (!function_exists('app_session_bootstrap_path')) {
    // @doc-note Webzugriffe weichen bei fehlerhaftem PHP-Sessionpfad kontrolliert auf den lokalen App-Speicher aus.
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

ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (!isset($_ENV) || !is_array($_ENV)) {
    $_ENV = [];
}

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

// @doc-section auto-migrations
// @doc-purpose Stellt benoetigte Tabellen, Spalten, Fremdschluessel und Trigger fuer Laufzeit und Auditierung sicher.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS startnummern (
            nummer INT PRIMARY KEY,
            status ENUM('frei', 'reserviert', 'vergeben') NOT NULL DEFAULT 'frei',
            reserviert_fuer VARCHAR(150) DEFAULT NULL,
            team_id INT DEFAULT NULL,
            teilnehmer_id INT DEFAULT NULL,
            KEY idx_status (status),
            KEY idx_team_id (team_id),
            KEY idx_teilnehmer_id (teilnehmer_id),
            FOREIGN KEY (teilnehmer_id) REFERENCES teilnehmer(id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','cashier','orga','viewer') NOT NULL DEFAULT 'viewer',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_username (username)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lane_timeframes (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_lane_timeframes_active_sort (active, sort_order, start_at, end_at),
            KEY idx_lane_timeframes_start_end (start_at, end_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ");

    $stmt = $pdo->query("SHOW COLUMNS FROM teilnehmer LIKE 'startnummer'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE teilnehmer ADD COLUMN startnummer INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE teilnehmer ADD INDEX idx_teilnehmer_startnummer (startnummer)");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM startnummern");
    if ($stmt->fetchColumn() == 0) {
        $pdo->beginTransaction();
        $insertStmt = $pdo->prepare("INSERT INTO startnummern (nummer, status) VALUES (?, 'frei')");
        for ($i = 1; $i <= 999; $i++) {
            $insertStmt->execute([$i]);
        }
        $pdo->commit();
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            table_name VARCHAR(64) NOT NULL,
            action ENUM('INSERT','UPDATE','DELETE') NOT NULL,
            row_pk VARCHAR(128) NOT NULL,
            user_id INT NULL,
            username VARCHAR(60) NULL,
            role VARCHAR(20) NULL,
            old_data LONGTEXT NULL,
            new_data LONGTEXT NULL,
            KEY idx_audit_table_time (table_name, occurred_at),
            KEY idx_audit_user_time (user_id, occurred_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS teams (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_team_name (name)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    ");

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM teilnehmer LIKE 'team_id'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teilnehmer ADD COLUMN team_id INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE teilnehmer ADD INDEX idx_teilnehmer_team_id (team_id)");
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM startnummern LIKE 'team_id'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE startnummern ADD COLUMN team_id INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE startnummern ADD INDEX idx_team_id (team_id)");
        }
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            INSERT INTO teams (name)
            SELECT DISTINCT TRIM(teamname) AS name
            FROM teilnehmer
            WHERE teamname IS NOT NULL AND TRIM(teamname) <> ''
            ON DUPLICATE KEY UPDATE name = name
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            INSERT INTO teams (name)
            SELECT DISTINCT TRIM(reserviert_fuer) AS name
            FROM startnummern
            WHERE reserviert_fuer IS NOT NULL AND TRIM(reserviert_fuer) <> ''
            ON DUPLICATE KEY UPDATE name = name
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            UPDATE teilnehmer t
            INNER JOIN teams te ON te.name = TRIM(t.teamname)
            SET t.team_id = te.id
            WHERE t.team_id IS NULL AND t.teamname IS NOT NULL AND TRIM(t.teamname) <> ''
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            UPDATE startnummern s
            INNER JOIN teams te ON te.name = TRIM(s.reserviert_fuer)
            SET s.team_id = te.id
            WHERE s.team_id IS NULL AND s.reserviert_fuer IS NOT NULL AND TRIM(s.reserviert_fuer) <> ''
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            UPDATE teilnehmer t
            INNER JOIN teams te ON te.id = t.team_id
            SET t.teamname = te.name
            WHERE t.team_id IS NOT NULL AND t.team_id <> 0 AND (t.teamname IS NULL OR t.teamname = '' OR t.teamname <> te.name)
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            UPDATE startnummern s
            INNER JOIN teams te ON te.id = s.team_id
            SET s.reserviert_fuer = te.name
            WHERE s.team_id IS NOT NULL AND s.team_id <> 0 AND s.status = 'reserviert' AND (s.reserviert_fuer IS NULL OR s.reserviert_fuer = '' OR s.reserviert_fuer <> te.name)
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE teilnehmer ADD CONSTRAINT fk_teilnehmer_team_id FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE startnummern ADD CONSTRAINT fk_startnummern_team_id FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL");
    } catch (Throwable $e) {
    }

    $tableExists = static function (PDO $pdo, string $table): bool {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    };

    $columnExists = static function (PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    };

    $triggerExists = static function (PDO $pdo, string $triggerName): bool {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? LIMIT 1');
            $stmt->execute([$triggerName]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    };

    $replaceTrigger = function (PDO $pdo, string $triggerName, string $sql) use ($triggerExists): void {
        if ($triggerExists($pdo, $triggerName)) {
            $pdo->exec('DROP TRIGGER IF EXISTS `' . str_replace('`', '``', $triggerName) . '`');
        }
        $pdo->exec($sql);
    };

    if ($tableExists($pdo, 'teilnehmer')) {
        try {
            if ($columnExists($pdo, 'teilnehmer', 'browser_agent')) {
                $pdo->exec('ALTER TABLE teilnehmer DROP COLUMN browser_agent');
            }
            if ($columnExists($pdo, 'teilnehmer', 'ip_adresse')) {
                $pdo->exec('ALTER TABLE teilnehmer DROP COLUMN ip_adresse');
            }
        } catch (Throwable $e) {
        }
    }

    if ($tableExists($pdo, 'audit_log')) {
        try {
            if ($columnExists($pdo, 'audit_log', 'ip_hash')) {
                $pdo->exec('ALTER TABLE audit_log DROP COLUMN ip_hash');
            }
            if ($columnExists($pdo, 'audit_log', 'ua_hash')) {
                $pdo->exec('ALTER TABLE audit_log DROP COLUMN ua_hash');
            }
        } catch (Throwable $e) {
        }
    }

    if ($tableExists($pdo, 'lane_timeframes')) {
        try {
            if (!$columnExists($pdo, 'lane_timeframes', 'active')) {
                $pdo->exec('ALTER TABLE lane_timeframes ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
            }
            if (!$columnExists($pdo, 'lane_timeframes', 'sort_order')) {
                $pdo->exec('ALTER TABLE lane_timeframes ADD COLUMN sort_order INT NOT NULL DEFAULT 0');
            }
            if (!$columnExists($pdo, 'lane_timeframes', 'created_at')) {
                $pdo->exec('ALTER TABLE lane_timeframes ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
            }
            if (!$columnExists($pdo, 'lane_timeframes', 'updated_at')) {
                $pdo->exec('ALTER TABLE lane_timeframes ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
            }
            $pdo->exec('CREATE INDEX idx_lane_timeframes_active_sort ON lane_timeframes (active, sort_order, start_at, end_at)');
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec('CREATE INDEX idx_lane_timeframes_start_end ON lane_timeframes (start_at, end_at)');
        } catch (Throwable $e) {
        }
    }

    if ($tableExists($pdo, 'teilnehmer')) {
        try {
            $replaceTrigger(
                $pdo,
                'audit_teilnehmer_ai',
                "CREATE TRIGGER audit_teilnehmer_ai AFTER INSERT ON teilnehmer FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'teilnehmer',
                    'INSERT',
                    CAST(NEW.id AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    NULL,
                    JSON_OBJECT(
                        'id', NEW.id,
                        'code', NEW.code,
                        'vorname', NEW.vorname,
                        'nachname', NEW.nachname,
                        'team_id', NEW.team_id,
                        'teamname', NEW.teamname,
                        'bezahlt', NEW.bezahlt,
                        'bezahlt_am', NEW.bezahlt_am,
                        'startnummer', NEW.startnummer
                    )
                );"
            );

            $replaceTrigger(
                $pdo,
                'audit_teilnehmer_au',
                "CREATE TRIGGER audit_teilnehmer_au AFTER UPDATE ON teilnehmer FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'teilnehmer',
                    'UPDATE',
                    CAST(NEW.id AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    JSON_OBJECT(
                        'id', OLD.id,
                        'code', OLD.code,
                        'vorname', OLD.vorname,
                        'nachname', OLD.nachname,
                        'team_id', OLD.team_id,
                        'teamname', OLD.teamname,
                        'bezahlt', OLD.bezahlt,
                        'bezahlt_am', OLD.bezahlt_am,
                        'startnummer', OLD.startnummer
                    ),
                    JSON_OBJECT(
                        'id', NEW.id,
                        'code', NEW.code,
                        'vorname', NEW.vorname,
                        'nachname', NEW.nachname,
                        'team_id', NEW.team_id,
                        'teamname', NEW.teamname,
                        'bezahlt', NEW.bezahlt,
                        'bezahlt_am', NEW.bezahlt_am,
                        'startnummer', NEW.startnummer
                    )
                );"
            );

            $replaceTrigger(
                $pdo,
                'audit_teilnehmer_ad',
                "CREATE TRIGGER audit_teilnehmer_ad AFTER DELETE ON teilnehmer FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'teilnehmer',
                    'DELETE',
                    CAST(OLD.id AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    JSON_OBJECT(
                        'id', OLD.id,
                        'code', OLD.code,
                        'vorname', OLD.vorname,
                        'nachname', OLD.nachname,
                        'team_id', OLD.team_id,
                        'teamname', OLD.teamname,
                        'bezahlt', OLD.bezahlt,
                        'bezahlt_am', OLD.bezahlt_am,
                        'startnummer', OLD.startnummer
                    ),
                    NULL
                );"
            );
        } catch (Throwable $e) {
            error_log('Audit trigger creation failed (teilnehmer): ' . $e->getMessage());
        }
    }

    if ($tableExists($pdo, 'startnummern')) {
        try {
            $replaceTrigger(
                $pdo,
                'audit_startnummern_ai',
                "CREATE TRIGGER audit_startnummern_ai AFTER INSERT ON startnummern FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'startnummern',
                    'INSERT',
                    CAST(NEW.nummer AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    NULL,
                    JSON_OBJECT(
                        'nummer', NEW.nummer,
                        'status', NEW.status,
                        'reserviert_fuer', NEW.reserviert_fuer,
                        'team_id', NEW.team_id,
                        'teilnehmer_id', NEW.teilnehmer_id
                    )
                );"
            );

            $replaceTrigger(
                $pdo,
                'audit_startnummern_au',
                "CREATE TRIGGER audit_startnummern_au AFTER UPDATE ON startnummern FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'startnummern',
                    'UPDATE',
                    CAST(NEW.nummer AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    JSON_OBJECT(
                        'nummer', OLD.nummer,
                        'status', OLD.status,
                        'reserviert_fuer', OLD.reserviert_fuer,
                        'team_id', OLD.team_id,
                        'teilnehmer_id', OLD.teilnehmer_id
                    ),
                    JSON_OBJECT(
                        'nummer', NEW.nummer,
                        'status', NEW.status,
                        'reserviert_fuer', NEW.reserviert_fuer,
                        'team_id', NEW.team_id,
                        'teilnehmer_id', NEW.teilnehmer_id
                    )
                );"
            );

            $replaceTrigger(
                $pdo,
                'audit_startnummern_ad',
                "CREATE TRIGGER audit_startnummern_ad AFTER DELETE ON startnummern FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'startnummern',
                    'DELETE',
                    CAST(OLD.nummer AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    JSON_OBJECT(
                        'nummer', OLD.nummer,
                        'status', OLD.status,
                        'reserviert_fuer', OLD.reserviert_fuer,
                        'teilnehmer_id', OLD.teilnehmer_id
                    ),
                    NULL
                );"
            );
        } catch (Throwable $e) {
            error_log('Audit trigger creation failed (startnummern): ' . $e->getMessage());
        }
    }

    if ($tableExists($pdo, 'users')) {
        try {
            $replaceTrigger(
                $pdo,
                'audit_users_ai',
                "CREATE TRIGGER audit_users_ai AFTER INSERT ON users FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'users',
                    'INSERT',
                    CAST(NEW.id AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    NULL,
                    JSON_OBJECT(
                        'id', NEW.id,
                        'username', NEW.username,
                        'role', NEW.role,
                        'created_at', NEW.created_at
                    )
                );"
            );

            $replaceTrigger(
                $pdo,
                'audit_users_au',
                "CREATE TRIGGER audit_users_au AFTER UPDATE ON users FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'users',
                    'UPDATE',
                    CAST(NEW.id AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    JSON_OBJECT(
                        'id', OLD.id,
                        'username', OLD.username,
                        'role', OLD.role,
                        'created_at', OLD.created_at
                    ),
                    JSON_OBJECT(
                        'id', NEW.id,
                        'username', NEW.username,
                        'role', NEW.role,
                        'created_at', NEW.created_at
                    )
                );"
            );

            $replaceTrigger(
                $pdo,
                'audit_users_ad',
                "CREATE TRIGGER audit_users_ad AFTER DELETE ON users FOR EACH ROW
                INSERT INTO audit_log (table_name, action, row_pk, user_id, username, role, old_data, new_data)
                VALUES (
                    'users',
                    'DELETE',
                    CAST(OLD.id AS CHAR(128)),
                    @audit_user_id,
                    @audit_username,
                    @audit_role,
                    JSON_OBJECT(
                        'id', OLD.id,
                        'username', OLD.username,
                        'role', OLD.role,
                        'created_at', OLD.created_at
                    ),
                    NULL
                );"
            );
        } catch (Throwable $e) {
            error_log('Audit trigger creation failed (users): ' . $e->getMessage());
        }
    }
} catch (PDOException $e) {
    // Falls keine DDL-Berechtigungen vorliegen, Fehler ignorieren
}
