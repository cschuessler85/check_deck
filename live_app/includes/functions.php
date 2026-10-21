<?php
/**
 * @doc-file
 * @doc-id live_app.includes.functions
 * @doc-kind shared-library
 * @doc-purpose Bietet zentrale Hilfsfunktionen fuer Sicherheit, Authentifizierung, Registrierung, Teams, Analytics und Live-Auswertungen.
 * @doc-entry Wird von privaten PHP-Seiten und APIs eingebunden.
 * @doc-input PDO, Session, Umgebungswerte und Rohdaten aus JSON oder SQLite.
 * @doc-output Wiederverwendbare Fach- und Infrastruktur-Funktionen.
 * @doc-side-effects Liest Session und Umgebungswerte, erzeugt Tokens, sendet Requests und berechnet Auswertungen.
 */
declare(strict_types=1);

// @doc-section session-bootstrap-delegation
// @doc-purpose Diese Bibliothek startet bewusst keine eigene Session und definiert keine Session-Helfer.
// @doc-note Die Funktionen app_session_configured_path(), app_session_ensure_storage_dir(),
//           app_session_bootstrap_path(), safe_substr() sowie der Session-Start werden ausschliesslich in
//           includes/db_connect.php bereitgestellt (Single Source of Truth).
// @doc-note Reihenfolge: includes/db_connect.php wird vor includes/functions.php eingebunden. Diese Datei
//           bleibt auch isoliert ladbar, da sie beim Laden weder eine Session startet noch eine der
//           delegierten Funktionen aufruft und db_connect.php nur zur Laufzeit benoetigt.
// @doc-note Der multibyte-sichere Kurzhelfer safe_substr() wird von dieser Bibliothek intern genutzt und
//           muss daher zur Laufzeit durch includes/db_connect.php bereitgestellt sein.
// @doc-input Keine.
// @doc-output Keine.

// @doc-section session-and-auth-bootstrap
// @doc-purpose Kapselt Session-Schutz, CSRF-Helfer und die grundlegende Login-Verwaltung.
/**
 * @doc-function live_app.includes.functions.escape
 * @doc-purpose Wandelt einen Wert HTML-sicher fuer die Ausgabe um.
 * @doc-param string|null $value Rohwert.
 * @doc-return string HTML-escaped Wert.
 *
 * @param string|null $value Rohwert.
 * @return string HTML-escaped Wert.
 */
function escape(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// @doc-section asset-helpers
// @doc-purpose Sucht gemeinsame Frontend-Assets robust in privaten und oeffentlichen Projektpfaden und liefert eine stabile Versionskennung.
/**
 * @doc-function live_app.includes.functions.app_asset_candidate_paths
 * @doc-purpose Liefert die moeglichen absoluten Dateipfade fuer ein relatives Frontend-Asset.
 * @doc-param string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.
 * @doc-return array Liste der Kandidatenpfade in Prioritaetsreihenfolge (App-Verzeichnis, danach live_web).
 *
 * @param string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.
 * @return array Liste der Kandidatenpfade in Prioritaetsreihenfolge (App-Verzeichnis, danach live_web).
 */
function app_asset_candidate_paths(string $relativePath): array
{
    $normalized = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
    $appRoot = dirname(__DIR__);
    $projectRoot = dirname($appRoot);

    return [
        $appRoot . DIRECTORY_SEPARATOR . $normalized,
        $projectRoot . DIRECTORY_SEPARATOR . 'live_web' . DIRECTORY_SEPARATOR . $normalized,
    ];
}

/**
 * @doc-function live_app.includes.functions.app_asset_real_path
 * @doc-purpose Ermittelt den ersten tatsaechlich vorhandenen Dateipfad eines Frontend-Assets.
 * @doc-param string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.
 * @doc-return string|null Erster existierender Pfad oder null, wenn kein Kandidat existiert.
 *
 * @param string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.
 * @return string|null Erster existierender Pfad oder null, wenn kein Kandidat existiert.
 */
function app_asset_real_path(string $relativePath): ?string
{
    foreach (app_asset_candidate_paths($relativePath) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @doc-function live_app.includes.functions.app_asset_version
 * @doc-purpose Erzeugt eine cachebare Versionskennung aus dem Aenderungszeitpunkt eines Assets.
 * @doc-param string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.
 * @doc-return string Versionskennung (Zeitstempel, 'present' oder 'missing').
 *
 * @param string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.
 * @return string Versionskennung (Zeitstempel, 'present' oder 'missing').
 */
function app_asset_version(string $relativePath): string
{
    $realPath = app_asset_real_path($relativePath);
    if ($realPath === null) {
        return 'missing';
    }

    $mtime = @filemtime($realPath);
    if ($mtime === false) {
        return 'present';
    }

    return (string)((int)$mtime);
}

/**
 * @doc-function live_app.includes.functions.csrf_token_get
 * @doc-purpose Liefert das CSRF-Token der aktuellen Session und erzeugt es bei Bedarf neu.
 * @doc-return string Aktuelles CSRF-Token der Session.
 * @doc-note Das Token wird bei fehlendem oder ungueltigem Sessionwert neu erzeugt.
 *
 * @return string Aktuelles CSRF-Token der Session.
 */
function csrf_token_get(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * @doc-function live_app.includes.functions.csrf_token_verify
 * @doc-purpose Prueft ein uebermitteltes CSRF-Token zeitkonstant gegen das Session-Token.
 * @doc-param string|null $token Zu pruefendes Token aus der Anfrage.
 * @doc-return bool True, wenn das Token gueltig ist.
 *
 * @param string|null $token Zu pruefendes Token aus der Anfrage.
 * @return bool True, wenn das Token gueltig ist.
 */
function csrf_token_verify(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * @doc-function live_app.includes.functions.admin_is_logged_in
 * @doc-purpose Prueft, ob die Session als Admin angemeldet ist.
 * @doc-return bool True, wenn eine aktive Admin-Anmeldung existiert.
 *
 * @return bool True, wenn eine aktive Admin-Anmeldung existiert.
 */
function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * @doc-function live_app.includes.functions.admin_login
 * @doc-purpose Markiert die aktuelle Session als Admin-Anmeldung und erneuert deren ID.
 * @doc-return void
 * @doc-note Die Session-ID wird bei aktiver Session regeneriert; ein etwaiges HTTP-Logout wird zurueckgesetzt.
 *
 * @return void
 */
function admin_login(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['admin_logged_in'] = true;
    auth_clear_http_logout();
}

/**
 * @doc-function live_app.includes.functions.admin_logout
 * @doc-purpose Beendet die Session vollstaendig und entfernt das Session-Cookie.
 * @doc-return void
 *
 * @return void
 */
function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * @doc-function live_app.includes.functions.admin_password_hash_default
 * @doc-purpose Erzeugt aus dem Umgebungspasswort einen Passwort-Hash fuer den Setup-Admin.
 * @doc-return string Passwort-Hash oder leerer String, wenn kein Passwort konfiguriert ist.
 *
 * @return string Passwort-Hash oder leerer String, wenn kein Passwort konfiguriert ist.
 */
function admin_password_hash_default(): string
{
    $pw = getenv('ADMIN_SETUP_PASSWORD');
    if ($pw === false) {
        $pw = '';
    }
    $pw = trim((string)$pw);
    if ($pw === '') {
        return '';
    }
    return password_hash($pw, PASSWORD_DEFAULT);
}

/**
 * @doc-function live_app.includes.functions.auth_setup_password_enabled
 * @doc-purpose Prueft, ob das Setup-Passwort per Umgebungsflag freigeschaltet ist.
 * @doc-return bool True, wenn ALLOW_SETUP_PASSWORD gesetzt auf '1' ist.
 *
 * @return bool True, wenn ALLOW_SETUP_PASSWORD gesetzt auf '1' ist.
 */
function auth_setup_password_enabled(): bool
{
    $flag = getenv('ALLOW_SETUP_PASSWORD');
    if ($flag === false) {
        $flag = $_ENV['ALLOW_SETUP_PASSWORD'] ?? '';
    }
    return trim((string)$flag) === '1';
}

/**
 * @doc-function live_app.includes.functions.auth_http_user
 * @doc-purpose Liest den per HTTP-Authentifizierung uebermittelten Benutzernamen aus.
 * @doc-return string|null Benutzername oder null, wenn keiner uebermittelt wurde.
 *
 * @return string|null Benutzername oder null, wenn keiner uebermittelt wurde.
 */
function auth_http_user(): ?string
{
    $user = null;
    if (isset($_SERVER['REMOTE_USER']) && is_string($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] !== '') {
        $user = $_SERVER['REMOTE_USER'];
    } elseif (isset($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] !== '') {
        $user = $_SERVER['PHP_AUTH_USER'];
    }
    if ($user === null) {
        return null;
    }
    $user = trim($user);
    return $user !== '' ? $user : null;
}

// @doc-section role-and-permission-model
// @doc-purpose Ordnet Rollen ihren Rechten zu und stellt die Berechtigungspruefung fuer Controller bereit.
/**
 * @doc-function live_app.includes.functions.auth_role_permissions
 * @doc-purpose Definiert die Berechtigungsmatrix je Rolle als zentrale Quelle.
 * @doc-return array Zuordnung von Rolle zu Liste erlaubter Berechtigungen ('*' fuer Vollzugriff).
 *
 * @return array Zuordnung von Rolle zu Liste erlaubter Berechtigungen ('*' fuer Vollzugriff).
 */
function auth_role_permissions(): array
{
    return [
        'admin' => ['*'],
        'cashier' => ['participants.read', 'payments.mark', 'numbers.assign', 'numbers.read'],
        'orga' => ['participants.read', 'participants.create', 'participants.edit', 'exports.participants', 'numbers.read', 'numbers.reserve', 'numbers.assign', 'numbers.unassign', 'teams.manage'],
        'numbers' => ['participants.read', 'participants.create', 'participants.edit', 'exports.participants', 'numbers.read', 'numbers.reserve', 'numbers.assign', 'numbers.unassign'],
        'viewer' => ['participants.read', 'numbers.read'],
    ];
}

/**
 * @doc-function live_app.includes.functions.auth_users_enabled
 * @doc-purpose Prueft, ob die Benutzertabelle in der Datenbank vorhanden ist.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-return bool True, wenn die Tabelle users erreichbar ist.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @return bool True, wenn die Tabelle users erreichbar ist.
 */
function auth_users_enabled(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @doc-function live_app.includes.functions.auth_is_setup_mode
 * @doc-purpose Prueft, ob sich das System im Ersteinrichtungsmodus ohne Benutzer befindet.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-return bool True, wenn die Benutzertabelle existiert und leer ist.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @return bool True, wenn die Benutzertabelle existiert und leer ist.
 */
function auth_is_setup_mode(PDO $pdo): bool
{
    try {
        if (!auth_users_enabled($pdo)) {
            return false;
        }
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        return (int)$stmt->fetchColumn() === 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @doc-function live_app.includes.functions.auth_admin_count
 * @doc-purpose Ermittelt die Anzahl vorhandener Admin-Benutzer.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-return int|null Anzahl der Admins oder null, wenn die Tabelle nicht verfuegbar ist.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @return int|null Anzahl der Admins oder null, wenn die Tabelle nicht verfuegbar ist.
 */
function auth_admin_count(PDO $pdo): ?int
{
    try {
        if (!auth_users_enabled($pdo)) {
            return null;
        }
        return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @doc-function live_app.includes.functions.auth_allow_setup_password
 * @doc-purpose Entscheidet, ob eine Anmeldung mit dem Setup-Passwort erlaubt ist.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-return bool True, wenn das Setup-Passwort genutzt werden darf.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @return bool True, wenn das Setup-Passwort genutzt werden darf.
 */
function auth_allow_setup_password(PDO $pdo): bool
{
    if (!auth_setup_password_enabled()) {
        return false;
    }
    if (!auth_users_enabled($pdo) || auth_is_setup_mode($pdo)) {
        return true;
    }
    $adminCount = auth_admin_count($pdo);
    return $adminCount === 0;
}

/**
 * @doc-function live_app.includes.functions.auth_role_normalize
 * @doc-purpose Vereinheitlicht veraltete Rollennamen auf die aktuelle Rollenbezeichnung.
 * @doc-param string $role Roh-Rollenname.
 * @doc-return string Normalisierter Rollenname ('numbers' wird zu 'orga').
 *
 * @param string $role Roh-Rollenname.
 * @return string Normalisierter Rollenname ('numbers' wird zu 'orga').
 */
function auth_role_normalize(string $role): string
{
    $r = trim($role);
    if ($r === 'numbers') {
        return 'orga';
    }
    return $r;
}

/**
 * @doc-function live_app.includes.functions.auth_set_session
 * @doc-purpose Schreibt die Benutzeridentitaet in die Session und setzt ein HTTP-Logout zurueck.
 * @doc-param int $user_id Benutzer-ID.
 * @doc-param string $username Benutzername.
 * @doc-param string $role Rollenname.
 * @doc-return void
 *
 * @param int $user_id Benutzer-ID.
 * @param string $username Benutzername.
 * @param string $role Rollenname.
 * @return void
 */
function auth_set_session(int $user_id, string $username, string $role): void
{
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = auth_role_normalize($role);
    auth_clear_http_logout();
}

/**
 * @doc-function live_app.includes.functions.auth_clear_session
 * @doc-purpose Loescht die Anmeldesitzung des Benutzers.
 * @doc-return void
 * @doc-note Delegiert an admin_logout() und beendet damit die gesamte Session.
 *
 * @return void
 */
function auth_clear_session(): void
{
    admin_logout();
}

/**
 * @doc-function live_app.includes.functions.auth_current_user
 * @doc-purpose Ermittelt den aktuell angemeldeten Benutzer aus Session, Admin-Flag oder HTTP-Auth.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-return array|null Benutzerdaten (id, username, role) oder null, wenn nicht angemeldet.
 * @doc-note Das Ergebnis wird statisch innerhalb des Requests zwischengespeichert.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @return array|null Benutzerdaten (id, username, role) oder null, wenn nicht angemeldet.
 */
function auth_current_user(PDO $pdo): ?array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $user_id = $_SESSION['user_id'] ?? null;
    if (!is_int($user_id) && !(is_string($user_id) && ctype_digit($user_id))) {
        $user_id = null;
    }

    if ($user_id !== null) {
        try {
            $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['role'] = auth_role_normalize((string)($row['role'] ?? ''));
                $cached = $row;
                $_SESSION['username'] = (string)$row['username'];
                $_SESSION['role'] = (string)$row['role'];
                return $cached;
            }
        } catch (Throwable $e) {
        }
    }

    if (admin_is_logged_in()) {
        $cached = ['id' => -1, 'username' => 'Admin', 'role' => 'admin'];
        return $cached;
    }

    if (auth_http_logout_requested()) {
        return null;
    }

    $httpUser = auth_http_user();
    if ($httpUser !== null) {
        $httpUser = safe_substr($httpUser, 0, 60);
        if (auth_users_enabled($pdo)) {
            try {
                $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$httpUser]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return null;
                }
                $row['role'] = auth_role_normalize((string)($row['role'] ?? ''));
                $cached = $row;
                $_SESSION['username'] = (string)$row['username'];
                $_SESSION['role'] = (string)$row['role'];
                return $cached;
            } catch (Throwable $e) {
                return null;
            }
        }
        return null;
    }

    return null;
}

/**
 * @doc-function live_app.includes.functions.auth_is_logged_in
 * @doc-purpose Prueft, ob aktuell ein Benutzer angemeldet ist.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-return bool True, wenn auth_current_user() einen Benutzer liefert.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @return bool True, wenn ein Benutzer angemeldet ist.
 */
function auth_is_logged_in(PDO $pdo): bool
{
    return auth_current_user($pdo) !== null;
}

// @doc-section login-throttling
// @doc-purpose Schuetzt den Login vor Brute-Force durch Begrenzung von Fehlversuchen pro IP und Benutzername.
/**
 * @doc-function live_app.includes.functions.auth_login_attempts_file
 * @doc-purpose Liefert den Pfad zur Datei mit den Login-Fehlversuchen und legt das Verzeichnis an.
 * @doc-return string Absoluter Pfad zur login_attempts.json.
 *
 * @return string Absoluter Pfad zur login_attempts.json.
 */
function auth_login_attempts_file(): string
{
    $appRoot = dirname(__DIR__);
    $dir = $appRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'login_attempts.json';
}

/**
 * @doc-function live_app.includes.functions.auth_login_client_ip
 * @doc-purpose Ermittelt die Client-IP fuer die Fehlversuchszaehlung.
 * @doc-return string Client-IP oder '0.0.0.0' als Rueckfallwert.
 *
 * @return string Client-IP oder '0.0.0.0' als Rueckfallwert.
 */
function auth_login_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ($ip === '') {
        $ip = '0.0.0.0';
    }
    return $ip;
}

/**
 * @doc-function live_app.includes.functions.auth_login_load_attempts
 * @doc-purpose Laedt die gespeicherten Login-Fehlversuche aus der Datei.
 * @doc-return array Fehlversuchsstruktur oder leeres Array bei fehlender/ungueltiger Datei.
 *
 * @return array Fehlversuchsstruktur oder leeres Array bei fehlender/ungueltiger Datei.
 */
function auth_login_load_attempts(): array
{
    $file = auth_login_attempts_file();
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * @doc-function live_app.includes.functions.auth_login_save_attempts
 * @doc-purpose Persistiert die Login-Fehlversuche unter exklusiver Dateisperre.
 * @doc-param array $data Fehlversuchsstruktur.
 * @doc-return void
 *
 * @param array $data Fehlversuchsstruktur.
 * @return void
 */
function auth_login_save_attempts(array $data): void
{
    $file = auth_login_attempts_file();
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * @doc-function live_app.includes.functions.auth_login_prune_attempts
 * @doc-purpose Entfernt Fehlversuchseintraege ausserhalb des Zeitfensters.
 * @doc-param array $data Fehlversuchsstruktur.
 * @doc-param int $windowSec Gueltiges Zeitfenster in Sekunden.
 * @doc-return array Bereinigte Fehlversuchsstruktur.
 *
 * @param array $data Fehlversuchsstruktur.
 * @param int $windowSec Gueltiges Zeitfenster in Sekunden.
 * @return array Bereinigte Fehlversuchsstruktur.
 */
function auth_login_prune_attempts(array $data, int $windowSec): array
{
    $cutoff = time() - $windowSec;
    foreach ($data as $key => $entries) {
        if (!is_array($entries)) {
            unset($data[$key]);
            continue;
        }
        $filtered = array_values(array_filter($entries, static function ($ts) use ($cutoff): bool {
            return is_int($ts) && $ts >= $cutoff;
        }));
        if ($filtered === []) {
            unset($data[$key]);
        } else {
            $data[$key] = $filtered;
        }
    }
    return $data;
}

/**
 * @doc-function live_app.includes.functions.auth_login_is_locked
 * @doc-purpose Prueft, ob IP oder Benutzername wegen zu vieler Fehlversuche gesperrt ist.
 * @doc-param string $ip Client-IP.
 * @doc-param string $username Benutzername.
 * @doc-param int $maxAttempts Maximale Fehlversuche bis zur Sperre.
 * @doc-param int $lockSec Sperrdauer in Sekunden.
 * @doc-return array Status mit 'locked', 'ip_count', 'user_count', 'max_attempts' und 'lock_seconds'.
 *
 * @param string $ip Client-IP.
 * @param string $username Benutzername.
 * @param int $maxAttempts Maximale Fehlversuche bis zur Sperre.
 * @param int $lockSec Sperrdauer in Sekunden.
 * @return array Status mit Sperrinformationen.
 */
function auth_login_is_locked(string $ip, string $username, int $maxAttempts = 5, int $lockSec = 900): array
{
    $data = auth_login_load_attempts();
    $data = auth_login_prune_attempts($data, $lockSec);
    auth_login_save_attempts($data);

    $ipKey = 'ip:' . $ip;
    $userKey = 'user:' . strtolower($username);
    $ipCount = isset($data[$ipKey]) ? count($data[$ipKey]) : 0;
    $userCount = isset($data[$userKey]) ? count($data[$userKey]) : 0;

    $locked = ($ipCount >= $maxAttempts) || ($userCount >= $maxAttempts);
    return [
        'locked' => $locked,
        'ip_count' => $ipCount,
        'user_count' => $userCount,
        'max_attempts' => $maxAttempts,
        'lock_seconds' => $lockSec,
    ];
}

/**
 * @doc-function live_app.includes.functions.auth_login_record_failure
 * @doc-purpose Erfasst einen Login-Fehlversuch fuer IP und Benutzername.
 * @doc-param string $ip Client-IP.
 * @doc-param string $username Benutzername.
 * @doc-param int $lockSec Sperrfenster in Sekunden, in dem Fehlversuche zaehlen.
 * @doc-return void
 *
 * @param string $ip Client-IP.
 * @param string $username Benutzername.
 * @param int $lockSec Sperrfenster in Sekunden, in dem Fehlversuche zaehlen.
 * @return void
 */
function auth_login_record_failure(string $ip, string $username, int $lockSec = 900): void
{
    $data = auth_login_load_attempts();
    $data = auth_login_prune_attempts($data, $lockSec);

    $now = time();
    $ipKey = 'ip:' . $ip;
    $userKey = 'user:' . strtolower($username);
    $data[$ipKey][] = $now;
    $data[$userKey][] = $now;

    auth_login_save_attempts($data);
}

/**
 * @doc-function live_app.includes.functions.auth_login_clear_attempts
 * @doc-purpose Loescht die Fehlversuche fuer IP und Benutzername nach erfolgreicher Anmeldung.
 * @doc-param string $ip Client-IP.
 * @doc-param string $username Benutzername.
 * @doc-return void
 *
 * @param string $ip Client-IP.
 * @param string $username Benutzername.
 * @return void
 */
function auth_login_clear_attempts(string $ip, string $username): void
{
    $data = auth_login_load_attempts();
    unset($data['ip:' . $ip]);
    unset($data['user:' . strtolower($username)]);
    auth_login_save_attempts($data);
}

/**
 * @doc-function live_app.includes.functions.auth_login_progressive_delay
 * @doc-purpose Verzoegert weitere Login-Versuche progressiv zur Verlangsamung von Brute-Force.
 * @doc-param int $failureCount Bisherige Fehlversuche.
 * @doc-return void
 * @doc-note Die Verzoegerung ist auf maximal 2 Sekunden begrenzt.
 *
 * @param int $failureCount Bisherige Fehlversuche.
 * @return void
 */
function auth_login_progressive_delay(int $failureCount): void
{
    // Progressives Delay: 0s bei 0 Fehlversuchen, bis max. 2s.
    $delay = min(2, (int)floor($failureCount / 2));
    if ($delay > 0) {
        sleep($delay);
    }
}

// @doc-section password-policy
// @doc-purpose Erzwingt eine Mindestpasswortqualitaet bei Anlage und Aenderung von Benutzerpasswoertern.
/**
 * @doc-function live_app.includes.functions.auth_password_min_length
 * @doc-purpose Liefert die zentral definierte Mindestlaenge fuer Benutzerpasswoerter.
 * @doc-return int Mindestlaenge in Zeichen.
 *
 * @return int Mindestlaenge in Zeichen.
 */
function auth_password_min_length(): int
{
    return 12;
}

/**
 * @doc-function live_app.includes.functions.auth_password_validate
 * @doc-purpose Prueft ein Passwort gegen die Passwortrichtlinie und sammelt Fehlermeldungen.
 * @doc-param string $password Zu pruefendes Passwort.
 * @doc-return array Ergebnis mit 'valid' (bool) und 'errors' (Liste der Fehlermeldungen).
 *
 * @param string $password Zu pruefendes Passwort.
 * @return array Ergebnis mit 'valid' (bool) und 'errors' (Liste der Fehlermeldungen).
 */
function auth_password_validate(string $password): array
{
    $errors = [];
    $minLen = auth_password_min_length();

    if (strlen($password) < $minLen) {
        $errors[] = 'Passwort muss mindestens ' . $minLen . ' Zeichen lang sein.';
    }

    $hasLower = (bool)preg_match('/[a-z]/', $password);
    $hasUpper = (bool)preg_match('/[A-Z]/', $password);
    $hasDigit = (bool)preg_match('/[0-9]/', $password);
    $classes = (int)$hasLower + (int)$hasUpper + (int)$hasDigit;
    if ($classes < 3) {
        $errors[] = 'Passwort muss Kleinbuchstaben, Grossbuchstaben und Zahlen enthalten.';
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
    ];
}

/**
 * @doc-function live_app.includes.functions.auth_login_with_credentials
 * @doc-purpose Meldet einen Benutzer mit Benutzername und Passwort unter Beruecksichtigung der Sperrlogik an.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param string $username Benutzername.
 * @doc-param string $password Klartextpasswort.
 * @doc-return bool True bei erfolgreicher Anmeldung.
 * @doc-note Bei Fehlschlag werden Fehlversuche gezaehlt; im Setup-Modus ist die Anmeldung deaktiviert.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param string $username Benutzername.
 * @param string $password Klartextpasswort.
 * @return bool True bei erfolgreicher Anmeldung.
 */
function auth_login_with_credentials(PDO $pdo, string $username, string $password): bool
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }

    $ip = auth_login_client_ip();
    $lockInfo = auth_login_is_locked($ip, $username);
    if ($lockInfo['locked']) {
        // Zu viele Fehlversuche: Account/IP voruebergehend gesperrt.
        return false;
    }

    try {
        if (auth_is_setup_mode($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            auth_login_record_failure($ip, $username);
            return false;
        }
        if (!password_verify($password, (string)$row['password_hash'])) {
            auth_login_record_failure($ip, $username);
            auth_login_progressive_delay($lockInfo['ip_count']);
            return false;
        }
        session_regenerate_id(true);
        auth_set_session((int)$row['id'], (string)$row['username'], auth_role_normalize((string)($row['role'] ?? '')));
        auth_login_clear_attempts($ip, $username);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @doc-function live_app.includes.functions.auth_can
 * @doc-purpose Prueft, ob der aktuelle Benutzer die angeforderte Berechtigung besitzt.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param string $permission Zu pruefende Berechtigung.
 * @doc-return bool True, wenn die Berechtigung erlaubt ist.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param string $permission Zu pruefende Berechtigung.
 * @return bool True, wenn die Berechtigung erlaubt ist.
 */
function auth_can(PDO $pdo, string $permission): bool
{
    $user = auth_current_user($pdo);
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    $map = auth_role_permissions();
    $role = (string)($user['role'] ?? '');
    $perms = $map[$role] ?? [];
    if (in_array('*', $perms, true)) {
        return true;
    }
    return in_array($permission, $perms, true);
}

/**
 * @doc-function live_app.includes.functions.auth_verify_token
 * @doc-purpose Verifiziert ein Access-Token und liefert den zugehoerigen Benutzer.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param string $raw_token Ungehashtes Access-Token.
 * @doc-return array|null Benutzerdaten (user_id, username, role, token_id) oder null, wenn ungueltig/abgelaufen.
 * @doc-note Aktualisiert last_used_at bei gueltigem Token.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param string $raw_token Ungehashtes Access-Token.
 * @return array|null Benutzerdaten oder null, wenn ungueltig/abgelaufen.
 */
function auth_verify_token(PDO $pdo, string $raw_token): ?array
{
    if ($raw_token === '') {
        return null;
    }
    $hash = hash('sha256', $raw_token);
    try {
        $stmt = $pdo->prepare('SELECT t.id, t.created_by, t.revoked, t.expires_at, u.id AS user_id, u.username, u.role FROM access_tokens t JOIN users u ON u.id = t.created_by WHERE t.token_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!empty($row['revoked'])) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
            return null;
        }
        $upd = $pdo->prepare('UPDATE access_tokens SET last_used_at = NOW() WHERE id = ?');
        $upd->execute([(int)$row['id']]);
        return [
            'user_id' => (int)$row['user_id'],
            'username' => (string)$row['username'],
            'role' => (string)$row['role'],
            'token_id' => (int)$row['id'],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @doc-function live_app.includes.functions.auth_require_role
 * @doc-purpose Erzwingt eine Rollenzugehoerigkeit und leitet sonst auf eine Zielseite um.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param array $roles Erlaubte Rollen.
 * @doc-param string $redirect_to Umleitungsziel bei fehlender Berechtigung.
 * @doc-return void
 * @doc-note Beendet das Skript bei fehlender Berechtigung per exit.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param array $roles Erlaubte Rollen.
 * @param string $redirect_to Umleitungsziel bei fehlender Berechtigung.
 * @return void
 */
function auth_require_role(PDO $pdo, array $roles, string $redirect_to = 'admin.php'): void
{
    $user = auth_current_user($pdo);
    $role = $user ? (string)($user['role'] ?? '') : '';
    if (!in_array($role, $roles, true)) {
        header('Location: ' . $redirect_to);
        exit;
    }
}

/**
 * @doc-function live_app.includes.functions.auth_require_permission_or_403_json
 * @doc-purpose Erzwingt eine Berechtigung und antwortet sonst mit HTTP 403 als JSON.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param string $permission Erforderliche Berechtigung.
 * @doc-return void
 * @doc-note Beendet das Skript bei fehlender Berechtigung per exit.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param string $permission Erforderliche Berechtigung.
 * @return void
 */
function auth_require_permission_or_403_json(PDO $pdo, string $permission): void
{
    if (!auth_can($pdo, $permission)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Zugriff verweigert.']);
        exit;
    }
}

// @doc-section notifications
// @doc-purpose Sendet optionale Benachrichtigungen an externe Kanäle, wenn neue Anmeldungen eingehen.
/**
 * @doc-function live_app.includes.functions.push_notify_new_registration
 * @doc-purpose Benachrichtigt einen externen ntfy-Kanal ueber eine neue Anmeldung.
 * @doc-param string $vorname Vorname des Neuzugangs.
 * @doc-param string $nachname Nachname des Neuzugangs.
 * @doc-param string|null $teamname Optionaler Teamname.
 * @doc-return void
 * @doc-note Ohne konfigurierten NTFY_TOPIC passiert nichts; Versandfehler werden bewusst ignoriert.
 *
 * @param string $vorname Vorname des Neuzugangs.
 * @param string $nachname Nachname des Neuzugangs.
 * @param string|null $teamname Optionaler Teamname.
 * @return void
 */
function push_notify_new_registration(string $vorname, string $nachname, ?string $teamname = null): void
{
    $topic = trim((string)getenv('NTFY_TOPIC'));
    if ($topic === '') {
        return;
    }

    $baseUrl = trim((string)getenv('NTFY_URL'));
    if ($baseUrl === '') {
        $baseUrl = 'https://ntfy.sh';
    }

    $title = 'Neue Anmeldung';
    $name = trim($vorname . ' ' . $nachname);
    $message = $name !== '' ? $name : '—';
    if ($teamname !== null && trim($teamname) !== '') {
        $message .= "\nTeam: " . trim($teamname);
    }

    $token = trim((string)getenv('NTFY_TOKEN'));
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($topic);

    $headerTitle = preg_replace("/[\r\n]+/", ' ', $title);
    $headers = [
        'Content-Type: text/plain; charset=utf-8',
        'Title: ' . $headerTitle,
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . preg_replace("/[\r\n]+/", '', $token);
    }

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_exec($ch);
            curl_close($ch);
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $message,
                'timeout' => 3,
            ],
        ]);
        @file_get_contents($url, false, $context);
    } catch (Throwable $e) {
        return;
    }
}

// @doc-section registration-codes
// @doc-purpose Erzeugt, normalisiert und prueft abtippfreundliche Registrierungscodes inklusive Prüfziffer.
/**
 * @doc-function live_app.includes.functions.registration_code_alphabet
 * @doc-purpose Liefert das abtippfreundliche Zeichenalphabet fuer Registrierungscodes.
 * @doc-return string Alphabet ohne verwechselbare Zeichen (kein 0/O/1/I).
 *
 * @return string Alphabet ohne verwechselbare Zeichen (kein 0/O/1/I).
 */
function registration_code_alphabet(): string
{
    return '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
}

/**
 * @doc-function live_app.includes.functions.registration_code_normalize
 * @doc-purpose Vereinheitlicht einen Registrierungscode auf Grossbuchstaben ohne Trennzeichen.
 * @doc-param string $code Roh-Code.
 * @doc-return string Normalisierter Code.
 *
 * @param string $code Roh-Code.
 * @return string Normalisierter Code.
 */
function registration_code_normalize(string $code): string
{
    $c = strtoupper($code);
    $c = preg_replace('/[^A-Z0-9]/', '', $c);
    return is_string($c) ? $c : '';
}

/**
 * @doc-function live_app.includes.functions.registration_name_normalize
 * @doc-purpose Bereinigt einen Namensstring und begrenzt dessen Laenge.
 * @doc-param string $name Roh-Name.
 * @doc-param int $maxLen Maximale Laenge; 0 bedeutet keine Begrenzung.
 * @doc-return string Bereinigter Name.
 *
 * @param string $name Roh-Name.
 * @param int $maxLen Maximale Laenge; 0 bedeutet keine Begrenzung.
 * @return string Bereinigter Name.
 */
function registration_name_normalize(string $name, int $maxLen = 100): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = is_string($name) ? $name : '';
    if ($maxLen > 0) {
        $name = safe_substr($name, 0, $maxLen);
    }
    return $name;
}

/**
 * @doc-function live_app.includes.functions.registration_code_format
 * @doc-purpose Formatiert einen Registrierungscode in Vierergruppen fuer die Anzeige.
 * @doc-param string $code Roh-Code.
 * @doc-return string Formatierter Code mit Bindestrichen oder leerer String.
 *
 * @param string $code Roh-Code.
 * @return string Formatierter Code mit Bindestrichen oder leerer String.
 */
function registration_code_format(string $code): string
{
    $c = registration_code_normalize($code);
    if ($c === '') {
        return '';
    }
    $parts = str_split($c, 4);
    return implode('-', $parts);
}

/**
 * @doc-function live_app.includes.functions.luhn_mod_n_check_char
 * @doc-purpose Berechnet die Luhn-Mod-N-Pruefziffer fuer einen Code-Rumpf.
 * @doc-param string $body Code-Rumpf ohne Pruefziffer.
 * @doc-param string $alphabet Verwendetes Zeichenalphabet.
 * @doc-return string Berechnete Pruefziffer.
 * @doc-throws RuntimeException Wenn das Alphabet zu kurz ist oder ein Zeichen nicht im Alphabet vorkommt.
 *
 * @param string $body Code-Rumpf ohne Pruefziffer.
 * @param string $alphabet Verwendetes Zeichenalphabet.
 * @return string Berechnete Pruefziffer.
 * @throws RuntimeException Wenn das Alphabet zu kurz ist oder ein Zeichen ungueltig ist.
 */
function luhn_mod_n_check_char(string $body, string $alphabet): string
{
    $n = strlen($alphabet);
    if ($n < 2) {
        throw new RuntimeException('Alphabet zu kurz.');
    }

    $factor = 2;
    $sum = 0;

    for ($i = strlen($body) - 1; $i >= 0; $i--) {
        $ch = $body[$i];
        $codePoint = strpos($alphabet, $ch);
        if ($codePoint === false) {
            throw new RuntimeException('Ungültiges Zeichen im Code.');
        }

        $addend = $factor * $codePoint;
        $factor = ($factor === 2) ? 1 : 2;
        $addend = intdiv($addend, $n) + ($addend % $n);
        $sum += $addend;
    }

    $remainder = $sum % $n;
    $checkCodePoint = ($n - $remainder) % $n;
    return $alphabet[$checkCodePoint];
}

/**
 * @doc-function live_app.includes.functions.registration_code_generate
 * @doc-purpose Erzeugt einen neuen Registrierungscode mit angehaengter Pruefziffer.
 * @doc-param int $random_len Anzahl der Zufallszeichen im Rumpf.
 * @doc-return string Vollstaendiger Code inklusive Pruefziffer.
 *
 * @param int $random_len Anzahl der Zufallszeichen im Rumpf.
 * @return string Vollstaendiger Code inklusive Pruefziffer.
 */
function registration_code_generate(int $random_len = 9): string
{
    // @doc-note Der Code bleibt bewusst abtippfreundlich.
    // @doc-note Verwechslungszeichen wie 0/O oder 1/I werden vermieden.
    // @doc-note Eine abschliessende Pruefziffer erkennt typische Tipp- und Uebertragungsfehler.
    $alphabet = registration_code_alphabet();
    $body = '';
    for ($i = 0; $i < $random_len; $i++) {
        $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $check = luhn_mod_n_check_char($body, $alphabet);
    return $body . $check;
}

/**
 * @doc-function live_app.includes.functions.registration_code_is_valid
 * @doc-purpose Prueft die Gueltigkeit eines Registrierungscodes anhand seiner Pruefziffer.
 * @doc-param string $code Zu pruefender Code.
 * @doc-return bool True, wenn der Code formal gueltig ist.
 *
 * @param string $code Zu pruefender Code.
 * @return bool True, wenn der Code formal gueltig ist.
 */
function registration_code_is_valid(string $code): bool
{
    $alphabet = registration_code_alphabet();
    $c = registration_code_normalize($code);
    if (strlen($c) < 2) {
        return false;
    }
    $body = substr($c, 0, -1);
    $check = substr($c, -1);

    try {
        return $check === luhn_mod_n_check_char($body, $alphabet);
    } catch (Throwable $e) {
        return false;
    }
}

// @doc-section team-helpers
// @doc-purpose Vereinheitlicht Teamnamen und kapselt Team-Lookups beziehungsweise automatische Team-Anlage.
/**
 * @doc-function live_app.includes.functions.get_existing_teams
 * @doc-purpose Laedt alle benannten Teams als ID-Name-Paare.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-return array Liste von Teams mit 'id' und 'name'.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @return array Liste von Teams mit 'id' und 'name'.
 */
function get_existing_teams(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, name
        FROM teams
        WHERE name IS NOT NULL AND name <> ''
        ORDER BY name ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $id = isset($r['id']) ? (int)$r['id'] : 0;
        $name = isset($r['name']) ? (string)$r['name'] : '';
        if ($id > 0 && $name !== '') {
            $out[] = ['id' => $id, 'name' => $name];
        }
    }
    return $out;
}

/**
 * @doc-function live_app.includes.functions.team_name_normalize
 * @doc-purpose Bereinigt einen Teamnamen und begrenzt dessen Laenge.
 * @doc-param string|null $name Roh-Teamname.
 * @doc-return string|null Bereinigter Teamname oder null, wenn leer.
 *
 * @param string|null $name Roh-Teamname.
 * @return string|null Bereinigter Teamname oder null, wenn leer.
 */
function team_name_normalize(?string $name): ?string
{
    $in = trim((string)($name ?? ''));
    if ($in === '') {
        return null;
    }
    $n = registration_name_normalize($in, 150);
    return $n !== '' ? $n : null;
}

/**
 * @doc-function live_app.includes.functions.team_get_or_create_id
 * @doc-purpose Liefert die ID eines Teams und legt es bei Bedarf atomar neu an.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param string|null $teamname Teamname.
 * @doc-return int|null Team-ID oder null bei leerem Namen oder Fehler.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param string|null $teamname Teamname.
 * @return int|null Team-ID oder null bei leerem Namen oder Fehler.
 */
function team_get_or_create_id(PDO $pdo, ?string $teamname): ?int
{
    $name = team_name_normalize($teamname);
    if ($name === null) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO teams (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
        $stmt->execute([$name]);
        $id = (int)$pdo->lastInsertId();
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM teams WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            $id = (int)$stmt->fetchColumn();
            return $id > 0 ? $id : null;
        } catch (Throwable $e2) {
            return null;
        }
    }
}

/**
 * @doc-function live_app.includes.functions.team_get_name_by_id
 * @doc-purpose Ermittelt den Namen eines Teams anhand seiner ID.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param int|null $team_id Team-ID.
 * @doc-return string|null Teamname oder null, wenn nicht vorhanden.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param int|null $team_id Team-ID.
 * @return string|null Teamname oder null, wenn nicht vorhanden.
 */
function team_get_name_by_id(PDO $pdo, ?int $team_id): ?string
{
    $id = $team_id ?? null;
    if (!is_int($id) || $id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT name FROM teams WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        $name = is_string($name) ? $name : null;
        return $name !== null && $name !== '' ? $name : null;
    } catch (Throwable $e) {
        return null;
    }
}

// @doc-section startnummern
// @doc-purpose Liefert Vorschlaege und Zuweisungslogik fuer freie oder teamreservierte Startnummern.
/**
 * @doc-function live_app.includes.functions.get_next_startnummer_suggestion
 * @doc-purpose Schlaegt eine naechste Startnummer unter Bevorzugung teamreservierter Nummern vor.
 * @doc-param PDO $pdo MySQL-Verbindung.
 * @doc-param int|null $team_id Optionale Team-ID.
 * @doc-return int|null Vorgeschlagene Startnummer oder null, wenn keine frei ist.
 *
 * @param PDO $pdo MySQL-Verbindung.
 * @param int|null $team_id Optionale Team-ID.
 * @return int|null Vorgeschlagene Startnummer oder null, wenn keine frei ist.
 */
function get_next_startnummer_suggestion(PDO $pdo, ?int $team_id): ?int
{
    $nummer = null;

    // @doc-note Priorisiert zuerst eine bereits fuer das Team reservierte Nummer.
    if (is_int($team_id) && $team_id > 0) {
        $stmt = $pdo->prepare("SELECT nummer FROM startnummern WHERE status = 'reserviert' AND team_id = ? ORDER BY nummer ASC LIMIT 1");
        $stmt->execute([$team_id]);
        $nummer = $stmt->fetchColumn();
    }

    // @doc-note Faellt bei fehlender Teamreservierung auf die naechste freie Nummer zurueck.
    if (!$nummer) {
        $stmt = $pdo->query("SELECT nummer FROM startnummern WHERE status = 'frei' ORDER BY nummer ASC LIMIT 1");
        $nummer = $stmt->fetchColumn();
    }

    return $nummer ? (int)$nummer : null;
}

/**
 * Weist einem Teilnehmer transaktionssicher eine Startnummer zu.
 *
 * @doc-function assign_startnummer
 * @doc-purpose Kapselt Startnummernvergabe, Wunschnummernpruefung und optionale Zahlungsmarkierung in einer
 *             einzigen geschuetzten Transaktion und verhindert so TOCTOU-Race-Conditions.
 * @doc-param PDO $pdo MySQL-Verbindung (Verwaltungs-Datenbank).
 * @doc-param int $teilnehmer_id ID des Ziel-Teilnehmers.
 * @doc-param int|null $team_id Optionale Team-ID zur Bevorzugung reservierter Nummern; ohne Angabe wird die
 *                              Teamzuordnung des Teilnehmers verwendet.
 * @doc-param int $wunschnummer Wunschnummer > 0; 0 bedeutet automatische Vergabe.
 * @doc-param bool $markPaid Markiert den Teilnehmer in derselben Transaktion als bezahlt.
 * @doc-return int|null Vergebene Startnummer oder null, wenn keine Nummer verfuegbar ist.
 * @doc-throws RuntimeException Wenn der Teilnehmer fehlt, die Wunschnummer bereits vergeben ist oder das
 *                             UPDATE durch eine Statusaenderung nicht angewendet werden konnte.
 * @doc-note Eigene Transaktion wird nur gestartet, wenn der Aufrufer noch keine aktiv hat; eine bestehende
 *           Aufrufer-Transaktion wird nahtlos mitgenutzt.
 */
function assign_startnummer(PDO $pdo, int $teilnehmer_id, ?int $team_id, int $wunschnummer = 0, bool $markPaid = false): ?int
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // @doc-note Teilnehmer zuerst sperren, damit gleichzeitige Aufrufe serialisiert werden.
        $lock = $pdo->prepare('SELECT id, team_id, bezahlt, startnummer FROM teilnehmer WHERE id = ? LIMIT 1 FOR UPDATE');
        $lock->execute([$teilnehmer_id]);
        $teilnehmer = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$teilnehmer) {
            throw new RuntimeException('Teilnehmer nicht gefunden.');
        }

        if (!is_int($team_id) || $team_id <= 0) {
            $team_id = ((int)($teilnehmer['team_id'] ?? 0)) > 0 ? (int)$teilnehmer['team_id'] : null;
        }
        $existing = (int)($teilnehmer['startnummer'] ?? 0);

        $nummer = 0;
        $target = null;

        if ($wunschnummer > 0) {
            // @doc-note Wunsch-Startnummer sperren und Konflikt mit einem anderen Teilnehmer sauber abweisen.
            $nLock = $pdo->prepare('SELECT nummer, status, teilnehmer_id FROM startnummern WHERE nummer = ? LIMIT 1 FOR UPDATE');
            $nLock->execute([$wunschnummer]);
            $target = $nLock->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($target)) {
                $status = (string)($target['status'] ?? '');
                $owner = (int)($target['teilnehmer_id'] ?? 0);
                if ($status === 'vergeben' && $owner !== $teilnehmer_id) {
                    throw new RuntimeException('Startnummer ' . $wunschnummer . ' ist bereits vergeben.');
                }
            }
            $nummer = $wunschnummer;
        } elseif ($existing > 0) {
            // @doc-note Eine bereits zugewiesene Nummer bleibt bestehen; Zeile wird zur Konsistenzpruefung gesperrt.
            $nummer = $existing;
            $nLock = $pdo->prepare('SELECT nummer, status, teilnehmer_id FROM startnummern WHERE nummer = ? LIMIT 1 FOR UPDATE');
            $nLock->execute([$existing]);
            $target = $nLock->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            // @doc-note Automatische Vergabe: bevorzugt teamreservierte, sonst die naechste freie Nummer.
            if ($team_id !== null) {
                $r = $pdo->prepare("SELECT nummer FROM startnummern WHERE status = 'reserviert' AND team_id = ? ORDER BY nummer ASC LIMIT 1 FOR UPDATE");
                $r->execute([$team_id]);
                $nummer = (int)($r->fetchColumn() ?: 0);
            }
            if ($nummer <= 0) {
                $r = $pdo->query("SELECT nummer FROM startnummern WHERE status = 'frei' ORDER BY nummer ASC LIMIT 1 FOR UPDATE");
                $nummer = (int)($r->fetchColumn() ?: 0);
            }
        }

        // @doc-note Beim Wechsel wird die alte Nummer des Teilnehmers zuerst freigegeben.
        if ($nummer > 0 && $existing > 0 && $existing !== $nummer) {
            $free = $pdo->prepare("UPDATE startnummern SET status = 'frei', reserviert_fuer = NULL, team_id = NULL, teilnehmer_id = NULL WHERE nummer = ? AND teilnehmer_id = ?");
            $free->execute([$existing, $teilnehmer_id]);
        }

        // @doc-note Zielnummer nur schreiben, wenn sie nicht bereits konsistent diesem Teilnehmer zugeordnet ist.
        $alreadyAssigned = is_array($target)
            && (string)($target['status'] ?? '') === 'vergeben'
            && (int)($target['teilnehmer_id'] ?? 0) === $teilnehmer_id
            && $existing === $nummer;

        if ($nummer > 0 && !$alreadyAssigned) {
            $pdo->prepare("INSERT IGNORE INTO startnummern (nummer, status) VALUES (?, 'frei')")->execute([$nummer]);
            $updS = $pdo->prepare("UPDATE startnummern SET status = 'vergeben', teilnehmer_id = ?, reserviert_fuer = NULL, team_id = NULL WHERE nummer = ? AND (status IN ('frei', 'reserviert') OR teilnehmer_id = ?)");
            $updS->execute([$teilnehmer_id, $nummer, $teilnehmer_id]);
            if ($updS->rowCount() === 0) {
                throw new RuntimeException('Startnummer ' . $nummer . ' ist nicht verfuegbar.');
            }
            $updT = $pdo->prepare("UPDATE teilnehmer SET startnummer = ? WHERE id = ?");
            $updT->execute([$nummer, $teilnehmer_id]);
        }

        if ($markPaid) {
            $updP = $pdo->prepare('UPDATE teilnehmer SET bezahlt = 1, bezahlt_am = COALESCE(bezahlt_am, NOW()) WHERE id = ?');
            $updP->execute([$teilnehmer_id]);
        }

        if ($ownTransaction) {
            $pdo->commit();
        }

        return $nummer > 0 ? $nummer : null;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// @doc-section auth-http-logout
// @doc-purpose Verhindert nach einem expliziten Logout, dass ein vorhandener HTTP-Auth-Header sofort wieder als Anmeldung gewertet wird.
/**
 * @doc-function live_app.includes.functions.auth_http_logout_cookie_name
 * @doc-purpose Liefert den Namen des Cookies, das ein HTTP-Auth-Logout markiert.
 * @doc-return string Cookie-Name.
 *
 * @return string Cookie-Name.
 */
function auth_http_logout_cookie_name(): string
{
    return 'admin_http_logout';
}

/**
 * @doc-function live_app.includes.functions.auth_cookie_is_secure
 * @doc-purpose Erkennt, ob die aktuelle Verbindung ueber HTTPS laeuft.
 * @doc-return bool True, wenn das Cookie als secure gesetzt werden soll.
 *
 * @return bool True, wenn die Verbindung HTTPS nutzt.
 */
function auth_cookie_is_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * @doc-function live_app.includes.functions.auth_mark_http_logout
 * @doc-purpose Setzt ein Cookie, das einen expliziten HTTP-Auth-Logout markiert.
 * @doc-return void
 * @doc-note Das Cookie ist HttpOnly, SameSite=Strict und bei HTTPS secure.
 *
 * @return void
 */
function auth_mark_http_logout(): void
{
    setcookie(auth_http_logout_cookie_name(), '1', [
        'expires' => time() + 3600,
        'path' => '/',
        'secure' => auth_cookie_is_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $_COOKIE[auth_http_logout_cookie_name()] = '1';
}

/**
 * @doc-function live_app.includes.functions.auth_clear_http_logout
 * @doc-purpose Entfernt das HTTP-Auth-Logout-Cookie.
 * @doc-return void
 *
 * @return void
 */
function auth_clear_http_logout(): void
{
    setcookie(auth_http_logout_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => auth_cookie_is_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    unset($_COOKIE[auth_http_logout_cookie_name()]);
}

/**
 * @doc-function live_app.includes.functions.auth_http_logout_requested
 * @doc-purpose Prueft, ob ein HTTP-Auth-Logout angefordert wurde.
 * @doc-return bool True, wenn das Logout-Cookie gesetzt ist.
 *
 * @return bool True, wenn das Logout-Cookie gesetzt ist.
 */
function auth_http_logout_requested(): bool
{
    return isset($_COOKIE[auth_http_logout_cookie_name()]) && $_COOKIE[auth_http_logout_cookie_name()] === '1';
}

// @doc-section json-live-data
// @doc-purpose Liest die externe Schwimm-JSON ein und bereitet daraus Kennzahlen fuer Dashboard- und Kioskansichten auf.
/**
 * @doc-function live_app.includes.functions.json_live_default_file_path
 * @doc-purpose Ermittelt den Pfad zur Schwimm-JSON, bevorzugt aus Umgebung, sonst aus dem Projekt.
 * @doc-return string Aufgeloester JSON-Dateipfad.
 *
 * @return string Aufgeloester JSON-Dateipfad.
 */
function json_live_default_file_path(): string
{
    $candidates = [];

    $envPath = getenv('JSON_LIVE_FILE');
    if (is_string($envPath) && trim($envPath) !== '') {
        $candidates[] = trim($envPath);
    }

    $projectRoot = dirname(__DIR__, 2);
    $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . '24hschwimmen.json';
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . '24hschwimmen.json';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0] ?? ($projectRoot . DIRECTORY_SEPARATOR . '24hschwimmen.json');
}

/**
 * @doc-function live_app.includes.functions.sqlite_live_default_file_path
 * @doc-purpose Liefert den Standardpfad der SQLite-Live-Datenbank.
 * @doc-return string Absoluter Pfad zur data.sqlite.
 *
 * @return string Absoluter Pfad zur data.sqlite.
 */
function sqlite_live_default_file_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data.sqlite';
}

/**
 * Oeffnet die SQLite-Live-Datenbank ueber eine zentrale Factory.
 *
 * @doc-function sqlite_live_open
 * @doc-purpose Stellt die einzige zentrale Verbindungs-Initialisierung fuer die SQLite-Live-Datenbank bereit.
 * @doc-param string|null $sqlitePath Optionaler Pfad; ohne Angabe wird ueber sqlite_live_default_file_path() aufgeloest.
 * @doc-param bool $readOnly Kennzeichnet eine reine Leseverbindung (Datei muss dann bereits existieren).
 * @doc-return PDO Konfigurierte SQLite-Verbindung.
 * @doc-throws RuntimeException Wenn im Read-Only-Modus die Datei fehlt.
 * @doc-note PRAGMA busy_timeout wird immer gesetzt; journal_mode/synchronous werden defensiv abgefangen,
 *           da Read-Only-Dateisysteme oder exotische Mounts sie ablehnen koennen.
 */
function sqlite_live_open(?string $sqlitePath = null, bool $readOnly = false): PDO
{
    $path = ($sqlitePath !== null && trim($sqlitePath) !== '') ? $sqlitePath : sqlite_live_default_file_path();

    if ($readOnly && !is_file($path)) {
        throw new RuntimeException('SQLite-Datei nicht gefunden.');
    }

    $sqlite = new PDO('sqlite:' . $path);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // @doc-note busy_timeout verhindert SQLITE_BUSY bei parallelen Schreibzugriffen und muss immer gesetzt werden.
    $sqlite->exec('PRAGMA busy_timeout = 5000;');
    try {
        $sqlite->exec('PRAGMA journal_mode = WAL;');
        $sqlite->exec('PRAGMA synchronous = NORMAL;');
    } catch (PDOException $e) {
        // @doc-note Read-Only-Dateisysteme oder exotische Mounts lehnen WAL/synchronous ab; unkritisch.
    }

    return $sqlite;
}

/**
 * @doc-function live_app.includes.functions.live_data_source_resolve
 * @doc-purpose Bestimmt die aktive Live-Datenquelle und ihren Pfad.
 * @doc-return array{mode:string,path:string} Aktive Datenquelle mit Modus und Pfad.
 * @doc-note Liefert derzeit immer den SQLite-Modus.
 *
 * @return array{mode:string,path:string} Aktive Datenquelle mit Modus und Pfad.
 */
function live_data_source_resolve(): array
{
    return ['mode' => 'sqlite', 'path' => sqlite_live_default_file_path()];
}

/**
 * @doc-function live_app.includes.functions.json_live_read_payload
 * @doc-purpose Liest und dekodiert die Schwimm-JSON-Datei mit klarer Fehlerbehandlung.
 * @doc-param string $jsonPath Pfad zur JSON-Datei.
 * @doc-return array Dekodierter JSON-Inhalt.
 * @doc-throws RuntimeException Wenn die Datei fehlt, leer oder ungueltig ist.
 *
 * @param string $jsonPath Pfad zur JSON-Datei.
 * @return array Dekodierter JSON-Inhalt.
 * @throws RuntimeException Wenn die Datei fehlt, leer oder ungueltig ist.
 */
function json_live_read_payload(string $jsonPath): array
{
    if (!is_file($jsonPath)) {
        throw new RuntimeException('JSON-Datei nicht gefunden.');
    }

    $raw = file_get_contents($jsonPath);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('JSON-Datei ist leer.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON-Datei ist ungueltig.');
    }

    return $decoded;
}

/**
 * @doc-function live_app.includes.functions.sqlite_live_decode_parameter
 * @doc-purpose Normalisiert den Parameterwert einer Action zu einer Werteliste.
 * @doc-param mixed $rawValue Rohwert (Array, JSON-String oder CSV-String).
 * @doc-return array Liste der dekodierten Parameterwerte.
 *
 * @param mixed $rawValue Rohwert (Array, JSON-String oder CSV-String).
 * @return array Liste der dekodierten Parameterwerte.
 */
function sqlite_live_decode_parameter($rawValue): array
{
    if (is_array($rawValue)) {
        return array_values($rawValue);
    }

    if (!is_string($rawValue) || trim($rawValue) === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (is_array($decoded)) {
        return array_values($decoded);
    }

    $parts = array_map('trim', explode(',', trim($rawValue, "[]() \t\r\n")));
    $values = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $values[] = is_numeric($part) ? (int)$part : $part;
    }

    return $values;
}

/**
 * @doc-function live_app.includes.functions.sqlite_live_read_payload
 * @doc-purpose Liest Schwimmer und Actions aus der SQLite-Live-Datenbank und bereitet sie JSON-aehnlich auf.
 * @doc-param string $sqlitePath Pfad zur SQLite-Datei.
 * @doc-return array Payload mit 'alleSchwimmer', 'actions' und 'statMessages'.
 *
 * @param string $sqlitePath Pfad zur SQLite-Datei.
 * @return array Payload mit 'alleSchwimmer', 'actions' und 'statMessages'.
 */
function sqlite_live_read_payload(string $sqlitePath): array
{
    // @doc-note Zentrale Factory statt eigener PDO-Initialisierung (Single Source of Truth).
    $sqlite = sqlite_live_open($sqlitePath, true);

    $swimmers = $sqlite
        ->query('SELECT nummer, vorname, nachname, istKind, gruppe, bahnanzahl, strecke, auf_bahn, aktiv FROM schwimmer ORDER BY nummer ASC')
        ->fetchAll() ?: [];
    $actionRows = $sqlite
        ->query('SELECT id, benutzer_id, zeitstempel, kommando, parameter FROM actions ORDER BY id DESC')
        ->fetchAll() ?: [];

    $actions = [];
    foreach ($actionRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $parameters = sqlite_live_decode_parameter($row['parameter'] ?? null);
        $action = [
            'id' => (int)($row['id'] ?? 0),
            'user' => (string)($row['benutzer_id'] ?? ''),
            'created_at' => (string)($row['zeitstempel'] ?? ''),
            'timestamp' => (string)($row['zeitstempel'] ?? ''),
            'command' => (string)($row['kommando'] ?? ''),
            'kommando' => (string)($row['kommando'] ?? ''),
            'parameter' => $parameters,
        ];

        if (isset($parameters[0])) {
            $action['nummer'] = (int)$parameters[0];
        }
        if (isset($parameters[1])) {
            $action['delta'] = (int)$parameters[1];
        }
        if (isset($parameters[2])) {
            $action['lane'] = (int)$parameters[2];
        }

        $actions[] = $action;
    }

    return [
        'alleSchwimmer' => array_values(array_filter($swimmers, 'is_array')),
        'actions' => $actions,
        'statMessages' => [],
    ];
}

/**
 * @doc-function live_app.includes.functions.json_live_normalize_name
 * @doc-purpose Bildet einen anzeigbaren Namen aus Vor-, Nachname oder Nummer.
 * @doc-param array $row Rohe Schwimmerdaten.
 * @doc-return string Anzeigename oder Fallback wie 'Schwimmer <Nummer>' bzw. 'Unbekannt'.
 *
 * @param array $row Rohe Schwimmerdaten.
 * @return string Anzeigename oder Fallback wie 'Schwimmer <Nummer>' bzw. 'Unbekannt'.
 */
function json_live_normalize_name(array $row): string
{
    $first = trim((string)($row['vorname'] ?? ''));
    $last = trim((string)($row['nachname'] ?? ''));
    $full = trim($first . ' ' . $last);
    if ($full !== '' && $full !== '-') {
        return $full;
    }

    $nummer = isset($row['nummer']) ? (string)$row['nummer'] : '';
    return $nummer !== '' ? ('Schwimmer ' . $nummer) : 'Unbekannt';
}

/**
 * @doc-function live_app.includes.functions.json_live_normalize_swimmer_map
 * @doc-purpose Vereinheitlicht die unterschiedlichen Schwimmerquellen der JSON zu einer Map nach Nummer.
 * @doc-param array $payload Roh-Payload der Schwimm-JSON.
 * @doc-return array Nach Schwimmernummer sortierte, normalisierte Schwimmerdaten.
 *
 * @param array $payload Roh-Payload der Schwimm-JSON.
 * @return array Nach Schwimmernummer sortierte, normalisierte Schwimmerdaten.
 */
function json_live_normalize_swimmer_map(array $payload): array
{
    $normalized = [];

    if (isset($payload['alleSchwimmer']) && is_array($payload['alleSchwimmer'])) {
        foreach ($payload['alleSchwimmer'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $nummer = (int)($row['nummer'] ?? 0);
            if ($nummer <= 0) {
                continue;
            }

            $normalized[$nummer] = [
                'nummer' => $nummer,
                'name' => json_live_normalize_name($row),
                'vorname' => trim((string)($row['vorname'] ?? '')),
                'nachname' => trim((string)($row['nachname'] ?? '')),
                'gruppe' => trim((string)($row['gruppe'] ?? '')),
                'bahnen' => (int)($row['bahnanzahl'] ?? $row['bahnen'] ?? 0),
                'aktiv' => (int)($row['aktiv'] ?? 0) === 1,
                'auf_bahn' => (int)($row['auf_bahn'] ?? $row['aufBahn'] ?? 0),
                'ist_kind' => (int)($row['istKind'] ?? 0) === 1,
            ];
        }
    }

    if (isset($payload['schwimmer']) && is_array($payload['schwimmer'])) {
        foreach ($payload['schwimmer'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $nummer = (int)($row['nummer'] ?? 0);
            if ($nummer <= 0) {
                continue;
            }

            $base = $normalized[$nummer] ?? [
                'nummer' => $nummer,
                'name' => json_live_normalize_name($row),
                'vorname' => trim((string)($row['vorname'] ?? '')),
                'nachname' => trim((string)($row['nachname'] ?? '')),
                'gruppe' => '',
                'bahnen' => 0,
                'aktiv' => false,
                'auf_bahn' => 0,
                'ist_kind' => false,
            ];

            $base['name'] = json_live_normalize_name($row);
            $base['vorname'] = trim((string)($row['vorname'] ?? $base['vorname']));
            $base['nachname'] = trim((string)($row['nachname'] ?? $base['nachname']));
            $base['bahnen'] = (int)($row['bahnen'] ?? $base['bahnen']);
            $base['aktiv'] = !empty($row['aktiv']) || $base['aktiv'];
            $base['auf_bahn'] = (int)($row['aufBahn'] ?? $row['auf_bahn'] ?? $base['auf_bahn']);
            $normalized[$nummer] = $base;
        }
    }

    ksort($normalized);
    return $normalized;
}

/**
 * @doc-function live_app.includes.functions.json_live_action_timestamp
 * @doc-purpose Ermittelt den Zeitstempel einer Action aus verschiedenen moeglichen Feldern.
 * @doc-param array $action Action-Datensatz.
 * @doc-return int|null Unix-Zeitstempel oder null, wenn keiner ermittelbar ist.
 *
 * @param array $action Action-Datensatz.
 * @return int|null Unix-Zeitstempel oder null, wenn keiner ermittelbar ist.
 */
function json_live_action_timestamp(array $action): ?int
{
    $keys = ['timestamp', 'created_at', 'createdAt', 'time', 'datetime', 'date'];
    foreach ($keys as $key) {
        if (!isset($action[$key])) {
            continue;
        }
        $value = $action[$key];
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $ts = (int)$value;
            if ($ts > 0) {
                return $ts;
            }
        }
        if (is_string($value) && trim($value) !== '') {
            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }
    }

    return null;
}

/**
 * @doc-function live_app.includes.functions.json_live_action_command
 * @doc-purpose Liest das Kommando einer Action in Normalform (Grossbuchstaben).
 * @doc-param array $action Action-Datensatz.
 * @doc-return string Kommando in Grossbuchstaben oder leerer String.
 *
 * @param array $action Action-Datensatz.
 * @return string Kommando in Grossbuchstaben oder leerer String.
 */
function json_live_action_command(array $action): string
{
    $command = $action['command'] ?? $action['kommando'] ?? $action['aktion'] ?? '';
    return strtoupper(trim((string)$command));
}

/**
 * @doc-function live_app.includes.functions.json_live_prepare_actions
 * @doc-purpose Reichert Actions um Kommando und Zeitstempel an und sortiert sie chronologisch.
 * @doc-param array $payload Roh-Payload der Schwimm-JSON.
 * @doc-return array Sortierte Actions mit internen Feldern '_command' und '_timestamp'.
 *
 * @param array $payload Roh-Payload der Schwimm-JSON.
 * @return array Sortierte Actions mit internen Feldern '_command' und '_timestamp'.
 */
function json_live_prepare_actions(array $payload): array
{
    $rawActions = $payload['actions'] ?? [];
    if (!is_array($rawActions)) {
        return [];
    }

    $actions = [];
    foreach ($rawActions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $action['_command'] = json_live_action_command($action);
        $action['_timestamp'] = json_live_action_timestamp($action);
        $actions[] = $action;
    }

    usort($actions, static function (array $a, array $b): int {
        $timeCmp = (int)($a['_timestamp'] ?? 0) <=> (int)($b['_timestamp'] ?? 0);
        if ($timeCmp !== 0) {
            return $timeCmp;
        }
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    return $actions;
}

/**
 * @doc-function live_app.includes.functions.json_live_apply_action_stats
 * @doc-purpose Aggregiert Bahnen und Aktivstatus aus den Actions in die Schwimmerkarte.
 * @doc-param array<int, array<string, mixed>> $swimmerMap Schwimmerdaten nach Nummer.
 * @doc-param list<array<string, mixed>> $actions Vorbereitete Action-Datensaetze.
 * @doc-return array<int, array<string, mixed>> Aktualisierte Schwimmerkarte inkl. Actions ohne Stammdaten.
 *
 * @param array<int, array<string, mixed>> $swimmerMap Schwimmerdaten nach Nummer.
 * @param list<array<string, mixed>> $actions Vorbereitete Action-Datensaetze.
 * @return array<int, array<string, mixed>> Aktualisierte Schwimmerkarte inkl. Actions ohne Stammdaten.
 */
function json_live_apply_action_stats(array $swimmerMap, array $actions): array
{
    $statsByNumber = [];

    foreach ($actions as $action) {
        $nummer = (int)($action['nummer'] ?? $action['startnummer'] ?? 0);
        if ($nummer <= 0) {
            continue;
        }

        if (!isset($statsByNumber[$nummer])) {
            $statsByNumber[$nummer] = [
                'bahnen' => 0,
                'aktiv' => null,
                'action_lane' => 0,
                'last_add_at' => null,
                'last_action_at' => null,
                'add_events' => 0,
            ];
        }

        $command = (string)($action['_command'] ?? '');
        $timestamp = $action['_timestamp'] ?? null;
        if (is_int($timestamp)) {
            $statsByNumber[$nummer]['last_action_at'] = $timestamp;
        }

        if ($command === 'ADD') {
            $delta = (int)($action['delta'] ?? 1);
            $statsByNumber[$nummer]['bahnen'] += $delta;
            $statsByNumber[$nummer]['add_events']++;
            if (is_int($timestamp)) {
                $statsByNumber[$nummer]['last_add_at'] = $timestamp;
            }
            $laneValue = (int)($action['lane'] ?? 0);
            if ($laneValue > 0) {
                $statsByNumber[$nummer]['action_lane'] = $laneValue;
            }
            continue;
        }

        if ($command === 'ACT') {
            $statsByNumber[$nummer]['aktiv'] = ((int)($action['delta'] ?? 0)) === 1;
        }
    }

    foreach ($swimmerMap as $nummer => &$swimmer) {
        $stats = $statsByNumber[$nummer] ?? null;
        if (!is_array($stats)) {
            $swimmer['bahnen'] = 0;
            continue;
        }

        $swimmer['bahnen'] = max(0, (int)($stats['bahnen'] ?? 0));
        if ($stats['aktiv'] !== null) {
            $swimmer['aktiv'] = (bool)$stats['aktiv'];
        }
        $swimmer['action_lane'] = (int)($stats['action_lane'] ?? 0);
        $swimmer['last_add_at'] = $stats['last_add_at'];
        $swimmer['last_action_at'] = $stats['last_action_at'];
        $swimmer['add_events'] = (int)($stats['add_events'] ?? 0);
    }
    unset($swimmer);

    foreach ($statsByNumber as $nummer => $stats) {
        if (isset($swimmerMap[$nummer])) {
            continue;
        }

        $swimmerMap[$nummer] = [
            'nummer' => $nummer,
            'name' => 'Schwimmer ' . $nummer,
            'vorname' => '',
            'nachname' => '',
            'gruppe' => '',
            'bahnen' => max(0, (int)($stats['bahnen'] ?? 0)),
            'aktiv' => (bool)($stats['aktiv'] ?? false),
            'auf_bahn' => 0,
            'action_lane' => (int)($stats['action_lane'] ?? 0),
            'ist_kind' => false,
            'last_add_at' => $stats['last_add_at'],
            'last_action_at' => $stats['last_action_at'],
            'add_events' => (int)($stats['add_events'] ?? 0),
        ];
    }

    ksort($swimmerMap);
    return $swimmerMap;
}

/**
 * @doc-function live_app.includes.functions.json_live_load_lane_timeframes
 * @doc-purpose Wertet aktive Zeitfenster aus und zaehlt die darin enthaltenen ADD-Ereignisse.
 * @doc-param PDO $pdo Verbindung zur Verwaltungsdatenbank.
 * @doc-param array $actions Vorbereitete Action-Datensaetze.
 * @doc-return array Liste der Zeitfenster mit Bahnen und eindeutigen Schwimmern.
 *
 * @param PDO $pdo Verbindung zur Verwaltungsdatenbank.
 * @param array $actions Vorbereitete Action-Datensaetze.
 * @return array Liste der Zeitfenster mit Bahnen und eindeutigen Schwimmern.
 */
function json_live_load_lane_timeframes(PDO $pdo, array $actions): array
{
    $rows = [];
    try {
        $stmt = $pdo->query('SELECT id, name, start_at, end_at, active, sort_order FROM lane_timeframes WHERE active = 1 ORDER BY sort_order ASC, start_at ASC, end_at ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        $start = trim((string)($row['start_at'] ?? ''));
        $end = trim((string)($row['end_at'] ?? ''));
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        $laps = 0;
        $swimmers = [];

        // @doc-note Zeitfenster zaehlen nur ADD-Ereignisse, deren Zeitstempel sauber in den aktiven Bereich fallen.
        foreach ($actions as $action) {
            if ((string)($action['_command'] ?? '') !== 'ADD') {
                continue;
            }
            $ts = $action['_timestamp'] ?? null;
            if (!is_int($ts) || $startTs === false || $endTs === false || $ts < $startTs || $ts > $endTs) {
                continue;
            }
            $laps++;
            $nummer = (int)($action['nummer'] ?? $action['startnummer'] ?? 0);
            if ($nummer > 0) {
                $swimmers[$nummer] = true;
            }
        }

        $items[] = [
            'name' => trim((string)($row['name'] ?? 'Zeitfenster')),
            'start_at' => $start,
            'end_at' => $end,
            'laps' => $laps,
            'unique_swimmers' => count($swimmers),
        ];
    }

    return $items;
}

/**
 * @doc-function live_app.includes.functions.json_live_build_overview
 * @doc-purpose Berechnet aus dem Live-Payload das vollstaendige Uebersichtsmodell fuer Dashboard und Kiosk.
 * @doc-param array $payload Roh-Payload der Live-Daten.
 * @doc-param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @doc-param string $sourceMode Kennzeichnet die Datenquelle ('json' oder 'sqlite').
 * @doc-param string|null $sourcePath Pfad der Datenquelle fuer Metadaten.
 * @doc-return array Vollstaendiges Uebersichtsobjekt inkl. Totals, Toplisten und Highlights.
 *
 * @param array $payload Roh-Payload der Live-Daten.
 * @param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @param string $sourceMode Kennzeichnet die Datenquelle ('json' oder 'sqlite').
 * @param string|null $sourcePath Pfad der Datenquelle fuer Metadaten.
 * @return array Vollstaendiges Uebersichtsobjekt inkl. Totals, Toplisten und Highlights.
 */
function json_live_build_overview(array $payload, ?PDO $pdo = null, string $sourceMode = 'json', ?string $sourcePath = null): array
{
    $poolLengthM = 100;
    $laneCount = 3;
    $swimmerMap = json_live_normalize_swimmer_map($payload);
    $actions = json_live_prepare_actions($payload);
    $swimmerMap = json_live_apply_action_stats($swimmerMap, $actions);
    $swimmers = array_values($swimmerMap);
    $statMessages = $payload['statMessages'] ?? [];
    if (!is_array($statMessages)) {
        $statMessages = [];
    }

    $registered = count($swimmers);
    $active = 0;
    $inWater = 0;
    $kids = 0;
    $adults = 0;
    $grouped = 0;
    $ungrouped = 0;
    $lapsTotal = 0;
    $groups = [];
    $laneStats = [];

    // @doc-note Die JSON-Quellen liefern nicht immer identische Felder, daher laufen alle Berechnungen auf der normalisierten Schwimmerliste.
    foreach ($swimmers as $swimmer) {
        $laps = (int)($swimmer['bahnen'] ?? 0);
        $lane = (int)($swimmer['auf_bahn'] ?? 0);
        $group = trim((string)($swimmer['gruppe'] ?? ''));
        $isActive = !empty($swimmer['aktiv']);
        $isKid = !empty($swimmer['ist_kind']);

        $lapsTotal += $laps;
        if ($isActive) {
            $active++;
        }
        if ($lane > 0) {
            $inWater++;
            if (!isset($laneStats[$lane])) {
                $laneStats[$lane] = [
                    'lane' => $lane,
                    'count' => 0,
                    'laps' => 0,
                    'swimmers' => 0,
                    'active_swimmers' => 0,
                    'avg_laps' => 0.0,
                ];
            }
            $laneStats[$lane]['count']++;
            $laneStats[$lane]['swimmers']++;
            $laneStats[$lane]['laps'] += $laps;
            if ($isActive) {
                $laneStats[$lane]['active_swimmers']++;
            }
        }
        if ($isKid) {
            $kids++;
        } else {
            $adults++;
        }
        if ($group !== '') {
            $grouped++;
            if (!isset($groups[$group])) {
                $groups[$group] = [
                    'name' => $group,
                    'members' => 0,
                    'active_members' => 0,
                    'laps' => 0,
                ];
            }
            $groups[$group]['members']++;
            $groups[$group]['laps'] += $laps;
            if ($isActive) {
                $groups[$group]['active_members']++;
            }
        } else {
            $ungrouped++;
        }
    }

    foreach ($laneStats as &$laneRow) {
        $laneRow['avg_laps'] = $laneRow['swimmers'] > 0 ? round($laneRow['laps'] / $laneRow['swimmers'], 1) : 0.0;
        $laneRow['km'] = round(($laneRow['laps'] * $poolLengthM) / 1000, 1);
    }
    unset($laneRow);

    usort($swimmers, static function (array $a, array $b): int {
        $cmp = (int)($b['bahnen'] ?? 0) <=> (int)($a['bahnen'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        return (int)($a['nummer'] ?? 0) <=> (int)($b['nummer'] ?? 0);
    });

    $groupList = array_values($groups);
    usort($groupList, static function (array $a, array $b): int {
        $cmp = (int)($b['laps'] ?? 0) <=> (int)($a['laps'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $laneList = array_values($laneStats);
    usort($laneList, static function (array $a, array $b): int {
        $cmp = (int)($b['active_swimmers'] ?? 0) <=> (int)($a['active_swimmers'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        return (int)($a['lane'] ?? 0) <=> (int)($b['lane'] ?? 0);
    });

    $configuredLaneList = [];
    for ($laneNumber = 1; $laneNumber <= $laneCount; $laneNumber++) {
        $laneRow = $laneStats[$laneNumber] ?? [
            'lane' => $laneNumber,
            'count' => 0,
            'laps' => 0,
            'swimmers' => 0,
            'active_swimmers' => 0,
            'avg_laps' => 0.0,
            'km' => 0.0,
        ];
        $laneRow['share_pct'] = $lapsTotal > 0 ? round((((int)$laneRow['laps']) / $lapsTotal) * 100, 1) : 0.0;
        $configuredLaneList[] = $laneRow;
    }

    $kidsList = array_values(array_filter($swimmers, static function (array $swimmer): bool {
        return !empty($swimmer['ist_kind']);
    }));
    $singlesList = array_values(array_filter($swimmers, static function (array $swimmer): bool {
        return trim((string)($swimmer['gruppe'] ?? '')) === '';
    }));

    $now = time();
    $recent5m = 0;
    $recent15m = 0;
    $lastAddAt = null;
    foreach ($actions as $action) {
        if ((string)($action['_command'] ?? '') !== 'ADD') {
            continue;
        }
        $ts = $action['_timestamp'] ?? null;
        if (!is_int($ts)) {
            continue;
        }
        if ($lastAddAt === null || $ts > $lastAddAt) {
            $lastAddAt = $ts;
        }
        if ($ts >= ($now - 300)) {
            $recent5m++;
        }
        if ($ts >= ($now - 900)) {
            $recent15m++;
        }
    }

    $topSwimmer = $swimmers[0] ?? null;
    $topGroup = $groupList[0] ?? null;
    $timeframes = $pdo instanceof PDO ? json_live_load_lane_timeframes($pdo, $actions) : [];

    $decorateSwimmer = static function (array $swimmer) use ($poolLengthM): array {
        $laps = (int)($swimmer['bahnen'] ?? 0);
        $swimmer['km'] = round(($laps * $poolLengthM) / 1000, 1);
        return $swimmer;
    };
    $decorateTeam = static function (array $team) use ($poolLengthM): array {
        $laps = (int)($team['laps'] ?? 0);
        $members = (int)($team['members'] ?? 0);
        $team['km'] = round(($laps * $poolLengthM) / 1000, 1);
        $team['avg_laps_per_member'] = $members > 0 ? round($laps / $members, 1) : 0.0;
        return $team;
    };

    $swimmersAll = array_map($decorateSwimmer, $swimmers);
    $singlesAll = array_map($decorateSwimmer, $singlesList);
    $kidsAll = array_map($decorateSwimmer, $kidsList);
    $teamsAll = array_map($decorateTeam, $groupList);
    $swimmersTop10 = array_slice($swimmersAll, 0, 10);
    $swimmersTop25 = array_slice($swimmersAll, 0, 25);
    $singlesTop10 = array_slice($singlesAll, 0, 10);
    $singlesTop25 = array_slice($singlesAll, 0, 25);
    $kidsTop10 = array_slice($kidsAll, 0, 10);
    $kidsTop25 = array_slice($kidsAll, 0, 25);
    $teamsTop10 = array_slice($teamsAll, 0, 10);
    $teamsTop25 = array_slice($teamsAll, 0, 25);

    // @doc-note Funfacts bleiben absichtlich leichtgewichtig, damit sie auch bei duennen Live-Daten sinnvoll lesbar bleiben.
    $funfacts = [];
    if ($topSwimmer) {
        $funfacts[] = $topSwimmer['name'] . ' fuehrt aktuell mit ' . (int)$topSwimmer['bahnen'] . ' Bahnen.';
    }
    if ($topGroup) {
        $funfacts[] = $topGroup['name'] . ' ist gerade das staerkste Team mit ' . (int)$topGroup['members'] . ' Mitgliedern.';
    }
    if ($registered > 0) {
        $funfacts[] = $kids . ' Kinder/Jugendliche und ' . $adults . ' Erwachsene sind aktuell in den Live-Daten sichtbar.';
    }
    $funfacts[] = 'Geschwommen wird auf insgesamt ' . $laneCount . ' Bahnen; jede Bahn zaehlt mit ' . $poolLengthM . ' m.';

    return [
        'ok' => true,
        'source_mode' => $sourceMode,
        'source_path' => $sourcePath,
        'file_path' => $sourcePath,
        'file_updated_at' => is_string($sourcePath) && is_file($sourcePath) ? date('c', (int)filemtime($sourcePath)) : null,
        'loaded_at' => date('c'),
        'pool_length_m' => $poolLengthM,
        'lane_count' => $laneCount,
        'totals' => [
            'registered' => $registered,
            'active' => $active,
            'in_water' => $inWater,
            'laps_total' => $lapsTotal,
            'groups' => count($groupList),
            'kids' => $kids,
            'adults' => $adults,
            'grouped' => $grouped,
            'ungrouped' => $ungrouped,
            'avg_laps' => $registered > 0 ? round($lapsTotal / $registered, 1) : 0.0,
            'actions' => count($actions),
            'stat_messages' => count($statMessages),
            'recent_5m' => $recent5m,
            'recent_15m' => $recent15m,
            'km_total' => round(($lapsTotal * $poolLengthM) / 1000, 1),
        ],
        'leaders' => array_slice(array_map(static function (array $swimmer): array {
            return [
                'nummer' => $swimmer['nummer'],
                'name' => $swimmer['name'],
                'gruppe' => $swimmer['gruppe'],
                'bahnen' => $swimmer['bahnen'],
            ];
        }, $swimmers), 0, 10),
        'active_swimmers' => array_values(array_map(static function (array $swimmer): array {
            return [
                'nummer' => $swimmer['nummer'],
                'name' => $swimmer['name'],
                'gruppe' => $swimmer['gruppe'],
                'bahnen' => $swimmer['bahnen'],
                'auf_bahn' => $swimmer['auf_bahn'],
                'ist_kind' => $swimmer['ist_kind'],
            ];
        }, array_values(array_filter($swimmers, static function (array $swimmer): bool {
            return !empty($swimmer['aktiv']);
        })))),
        'groups' => array_slice($groupList, 0, 10),
        'lane_stats' => [],
        'lane_ranking' => [],
        'lane_configured_stats' => [],
        'timeframes' => $timeframes,
        'toplists' => [
            'all' => $swimmersTop10,
            'all_25' => $swimmersTop25,
            'all_full' => $swimmersAll,
            'singles' => $singlesTop10,
            'singles_25' => $singlesTop25,
            'singles_full' => $singlesAll,
            'kids' => $kidsTop10,
            'kids_25' => $kidsTop25,
            'kids_full' => $kidsAll,
            'teams' => $teamsTop10,
            'teams_25' => $teamsTop25,
            'teams_full' => $teamsAll,
        ],
        'highlights' => [
            'top_swimmer' => $topSwimmer ? [
                'nummer' => $topSwimmer['nummer'],
                'name' => $topSwimmer['name'],
                'bahnen' => $topSwimmer['bahnen'],
            ] : null,
            'top_group' => $topGroup ? [
                'name' => $topGroup['name'],
                'members' => $topGroup['members'],
                'laps' => $topGroup['laps'],
            ] : null,
            'top_team' => $topGroup ? [
                'name' => $topGroup['name'],
                'members' => $topGroup['members'],
                'laps' => $topGroup['laps'],
            ] : null,
            'busiest_lane' => null,
            'last_add_at' => $lastAddAt ? date('c', $lastAddAt) : null,
        ],
        'funfacts' => array_values(array_unique($funfacts)),
    ];
}

/**
 * @doc-function live_app.includes.functions.live_overview_error_payload
 * @doc-purpose Erzeugt ein leeres Uebersichtsmodell mit Fehlermeldung im selben Schema.
 * @doc-param string $sourceMode Kennzeichnet die Datenquelle.
 * @doc-param string $sourcePath Pfad der Datenquelle.
 * @doc-param string $message Fehlermeldung.
 * @doc-return array Leeres Uebersichtsobjekt mit 'ok'=false und 'error'.
 *
 * @param string $sourceMode Kennzeichnet die Datenquelle.
 * @param string $sourcePath Pfad der Datenquelle.
 * @param string $message Fehlermeldung.
 * @return array Leeres Uebersichtsobjekt mit 'ok'=false und 'error'.
 */
function live_overview_error_payload(string $sourceMode, string $sourcePath, string $message): array
{
    return [
        'ok' => false,
        'source_mode' => $sourceMode,
        'source_path' => $sourcePath,
        'file_path' => $sourcePath,
        'file_updated_at' => is_file($sourcePath) ? date('c', (int)filemtime($sourcePath)) : null,
        'loaded_at' => date('c'),
        'pool_length_m' => 100,
        'lane_count' => 3,
        'totals' => [
            'registered' => 0,
            'active' => 0,
            'in_water' => 0,
            'laps_total' => 0,
            'groups' => 0,
            'kids' => 0,
            'adults' => 0,
            'grouped' => 0,
            'ungrouped' => 0,
            'avg_laps' => 0.0,
            'actions' => 0,
            'stat_messages' => 0,
            'recent_5m' => 0,
            'recent_15m' => 0,
            'km_total' => 0.0,
        ],
        'leaders' => [],
        'active_swimmers' => [],
        'groups' => [],
        'lane_stats' => [],
        'lane_ranking' => [],
        'lane_configured_stats' => [],
        'timeframes' => [],
        'toplists' => [
            'all' => [],
            'all_25' => [],
            'all_full' => [],
            'singles' => [],
            'singles_25' => [],
            'singles_full' => [],
            'kids' => [],
            'kids_25' => [],
            'kids_full' => [],
            'teams' => [],
            'teams_25' => [],
            'teams_full' => [],
        ],
        'highlights' => [
            'top_swimmer' => null,
            'top_group' => null,
            'top_team' => null,
            'busiest_lane' => null,
            'last_add_at' => null,
        ],
        'funfacts' => [],
        'error' => $message,
    ];
}

/**
 * @doc-function live_app.includes.functions.json_live_load_overview
 * @doc-purpose Laedt und berechnet die Uebersicht aus einer JSON-Datei mit Fehlerfallback.
 * @doc-param string $jsonPath Pfad zur JSON-Datei.
 * @doc-param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @doc-return array Uebersichtsmodell oder Fehlerpayload.
 *
 * @param string $jsonPath Pfad zur JSON-Datei.
 * @param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @return array Uebersichtsmodell oder Fehlerpayload.
 */
function json_live_load_overview(string $jsonPath, ?PDO $pdo = null): array
{
    try {
        $payload = json_live_read_payload($jsonPath);
        return json_live_build_overview($payload, $pdo, 'json', $jsonPath);
    } catch (Throwable $e) {
        return live_overview_error_payload('json', $jsonPath, $e->getMessage());
    }
}

/**
 * @doc-function live_app.includes.functions.sqlite_live_load_overview
 * @doc-purpose Laedt und berechnet die Uebersicht aus der SQLite-Datenbank mit Fehlerfallback.
 * @doc-param string $sqlitePath Pfad zur SQLite-Datei.
 * @doc-param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @doc-return array Uebersichtsmodell oder Fehlerpayload.
 *
 * @param string $sqlitePath Pfad zur SQLite-Datei.
 * @param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @return array Uebersichtsmodell oder Fehlerpayload.
 */
function sqlite_live_load_overview(string $sqlitePath, ?PDO $pdo = null): array
{
    try {
        $payload = sqlite_live_read_payload($sqlitePath);
        return json_live_build_overview($payload, $pdo, 'sqlite', $sqlitePath);
    } catch (Throwable $e) {
        return live_overview_error_payload('sqlite', $sqlitePath, $e->getMessage());
    }
}

/**
 * @doc-function live_app.includes.functions.live_load_overview
 * @doc-purpose Laedt die Uebersicht anhand der aufgeloesten Live-Datenquelle.
 * @doc-param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @doc-return array Uebersichtsmodell oder Fehlerpayload.
 *
 * @param PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.
 * @return array Uebersichtsmodell oder Fehlerpayload.
 */
function live_load_overview(?PDO $pdo = null): array
{
    $source = live_data_source_resolve();
    if (($source['mode'] ?? 'json') === 'sqlite') {
        return sqlite_live_load_overview((string)($source['path'] ?? ''), $pdo);
    }

    return json_live_load_overview((string)($source['path'] ?? ''), $pdo);
}

/**
 * @doc-function live_app.includes.functions.event_live_load_overview
 * @doc-purpose Laedt die Veranstaltungs-Uebersicht ueber die allgemeine Live-Quelle.
 * @doc-param PDO $pdo Verbindung fuer Zeitfenster-Statistiken.
 * @doc-return array Uebersichtsmodell oder Fehlerpayload.
 *
 * @param PDO $pdo Verbindung fuer Zeitfenster-Statistiken.
 * @return array Uebersichtsmodell oder Fehlerpayload.
 */
function event_live_load_overview(PDO $pdo): array
{
    return live_load_overview($pdo);
}

// @doc-section webauthn
// @doc-purpose Implementiert die biometrische Anmeldung per WebAuthn (Fingerabdruck/Gesicht).
/**
 * @doc-function live_app.includes.functions.webauthn_base64url_encode
 * @doc-purpose Kodiert Binaerdaten Base64-URL-konform ohne Auffuellzeichen.
 * @doc-param string $data Rohdaten.
 * @doc-return string Base64-URL-kodierter String.
 *
 * @param string $data Rohdaten.
 * @return string Base64-URL-kodierter String.
 */
function webauthn_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * @doc-function live_app.includes.functions.webauthn_base64url_decode
 * @doc-purpose Dekodiert einen Base64-URL-String zurueck in Binaerdaten.
 * @doc-param string $data Base64-URL-kodierter String.
 * @doc-return string Dekodierte Rohdaten.
 *
 * @param string $data Base64-URL-kodierter String.
 * @return string Dekodierte Rohdaten.
 */
function webauthn_base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * @doc-function live_app.includes.functions.webauthn_cbor_decode
 * @doc-purpose Dekodiert einen CBOR-Wert ab der aktuellen Offset-Position fuer WebAuthn-Daten.
 * @doc-param string $data CBOR-kodierte Rohdaten.
 * @doc-param int &$offset Leseposition, wird beim Dekodieren fortgeschrieben.
 * @doc-return mixed Dekodierter Wert (int, string, array oder map) oder null bei ungueltigen Daten.
 * @doc-note Der Offset wird als Referenz uebergeben und waehrend der Rekursion aktualisiert.
 *
 * @param string $data CBOR-kodierte Rohdaten.
 * @param int &$offset Leseposition, wird beim Dekodieren fortgeschrieben.
 * @return mixed Dekodierter Wert (int, string, array oder map) oder null bei ungueltigen Daten.
 */
function webauthn_cbor_decode(string $data, int &$offset = 0)
{
    if ($offset >= strlen($data)) {
        return null;
    }
    $initial = $offset;
    $ib = ord($data[$offset++]);
    $major = $ib >> 5;
    $info = $ib & 0x1f;
    if ($info < 24) {
        $val = $info;
    } elseif ($info === 24) {
        $val = ord($data[$offset++]);
    } elseif ($info === 25) {
        $val = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
    } elseif ($info === 26) {
        $val = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
    } else {
        return null;
    }
    switch ($major) {
        case 0:
            return $val;
        case 1:
            return -1 - $val;
        case 2:
        case 3:
            $bytes = substr($data, $offset, $val);
            $offset += $val;
            return $major === 2 ? $bytes : $bytes;
        case 4:
            $arr = [];
            for ($i = 0; $i < $val; $i++) {
                $arr[] = webauthn_cbor_decode($data, $offset);
            }
            return $arr;
        case 5:
            $map = [];
            for ($i = 0; $i < $val; $i++) {
                $k = webauthn_cbor_decode($data, $offset);
                $v = webauthn_cbor_decode($data, $offset);
                $map[$k] = $v;
            }
            return $map;
        default:
            return null;
    }
}

/**
 * @doc-function live_app.includes.functions.webauthn_cose_public_key_to_pem
 * @doc-purpose Wandelt einen COSE-EC2-Schluessel in ein PEM-Format fuer openssl_verify um.
 * @doc-param array $coseKey Dekodierter COSE-Schluessel.
 * @doc-return string|null PEM-Public-Key oder null bei nicht unterstuetztem Schluesseltyp.
 *
 * @param array $coseKey Dekodierter COSE-Schluessel.
 * @return string|null PEM-Public-Key oder null bei nicht unterstuetztem Schluesseltyp.
 */
function webauthn_cose_public_key_to_pem(array $coseKey): ?string
{
    $kty = $coseKey[1] ?? null;
    if ($kty !== 2) {
        return null;
    }
    $x = $coseKey[-2] ?? '';
    $y = $coseKey[-3] ?? '';
    if (strlen($x) !== 32 || strlen($y) !== 32) {
        return null;
    }
    $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00\x04" . $x . $y;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return $pem;
}

/**
 * @doc-function live_app.includes.functions.webauthn_generate_challenge
 * @doc-purpose Erzeugt eine zufaellige WebAuthn-Challenge.
 * @doc-return string Hex-kodierte Challenge.
 *
 * @return string Hex-kodierte Challenge.
 */
function webauthn_generate_challenge(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * @doc-function live_app.includes.functions.webauthn_rp_id
 * @doc-purpose Ermittelt die Relying-Party-ID aus dem aktuellen Host.
 * @doc-return string Hostname ohne Port (Standard 'localhost').
 *
 * @return string Hostname ohne Port (Standard 'localhost').
 */
function webauthn_rp_id(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = explode(':', $host)[0];
    return $host;
}

/**
 * @doc-function live_app.includes.functions.webauthn_verify_registration
 * @doc-purpose Verifiziert die WebAuthn-Registrierung und extrahiert Credential-ID und Public Key.
 * @doc-param string $clientDataJSON ClientData als JSON.
 * @doc-param string $attestationObject CBOR-kodiertes Attestation-Objekt.
 * @doc-param string $expectedChallenge Erwartete Challenge.
 * @doc-return array|null Daten mit 'credential_id' und 'public_key' oder null bei Prueffehler.
 *
 * @param string $clientDataJSON ClientData als JSON.
 * @param string $attestationObject CBOR-kodiertes Attestation-Objekt.
 * @param string $expectedChallenge Erwartete Challenge.
 * @return array|null Daten mit 'credential_id' und 'public_key' oder null bei Prueffehler.
 */
function webauthn_verify_registration(string $clientDataJSON, string $attestationObject, string $expectedChallenge): ?array
{
    $clientData = json_decode($clientDataJSON, true);
    if (!$clientData || ($clientData['type'] ?? '') !== 'webauthn.create') {
        return null;
    }
    if (!hash_equals($expectedChallenge, (string)($clientData['challenge'] ?? ''))) {
        return null;
    }
    $attObj = webauthn_cbor_decode($attestationObject);
    if (!is_array($attObj)) {
        return null;
    }
    $authData = $attObj['authData'] ?? '';
    if (strlen($authData) < 55) {
        return null;
    }
    $rpIdHash = substr($authData, 0, 32);
    $expectedRpIdHash = hash('sha256', webauthn_rp_id());
    if (!hash_equals($expectedRpIdHash, bin2hex($rpIdHash))) {
        return null;
    }
    $flags = ord($authData[32]);
    if (($flags & 0x01) === 0) {
        return null;
    }
    if (($flags & 0x40) === 0) {
        return null;
    }
    $credentialIdLen = unpack('n', substr($authData, 53, 2))[1];
    $credentialId = substr($authData, 55, $credentialIdLen);
    $coseKeyOffset = 55 + $credentialIdLen;
    $coseKey = webauthn_cbor_decode($authData, $coseKeyOffset);
    if (!is_array($coseKey)) {
        return null;
    }
    $pem = webauthn_cose_public_key_to_pem($coseKey);
    if ($pem === null) {
        return null;
    }
    return [
        'credential_id' => webauthn_base64url_encode($credentialId),
        'public_key' => $pem,
    ];
}

/**
 * @doc-function live_app.includes.functions.webauthn_verify_authentication
 * @doc-purpose Verifiziert eine WebAuthn-Anmelde-Assertion per Signaturpruefung.
 * @doc-param string $credentialIdB64 Base64-URL-kodierte Credential-ID.
 * @doc-param string $clientDataJSON ClientData als JSON.
 * @doc-param string $authenticatorData Authenticator-Daten.
 * @doc-param string $signature Signatur ueber die Assertion.
 * @doc-param string $publicKeyPem PEM-Public-Key des Credentials.
 * @doc-param string $expectedChallenge Erwartete Challenge.
 * @doc-return bool True, wenn die Assertion gueltig ist.
 *
 * @param string $credentialIdB64 Base64-URL-kodierte Credential-ID.
 * @param string $clientDataJSON ClientData als JSON.
 * @param string $authenticatorData Authenticator-Daten.
 * @param string $signature Signatur ueber die Assertion.
 * @param string $publicKeyPem PEM-Public-Key des Credentials.
 * @param string $expectedChallenge Erwartete Challenge.
 * @return bool True, wenn die Assertion gueltig ist.
 */
function webauthn_verify_authentication(string $credentialIdB64, string $clientDataJSON, string $authenticatorData, string $signature, string $publicKeyPem, string $expectedChallenge): bool
{
    $clientData = json_decode($clientDataJSON, true);
    if (!$clientData || ($clientData['type'] ?? '') !== 'webauthn.get') {
        return false;
    }
    if (!hash_equals($expectedChallenge, (string)($clientData['challenge'] ?? ''))) {
        return false;
    }
    $rpIdHash = substr($authenticatorData, 0, 32);
    $expectedRpIdHash = hash('sha256', webauthn_rp_id());
    if (!hash_equals($expectedRpIdHash, bin2hex($rpIdHash))) {
        return false;
    }
    $flags = ord($authenticatorData[32]);
    if (($flags & 0x01) === 0) {
        return false;
    }
    $clientDataHash = hash('sha256', $clientDataJSON, true);
    $signedData = $authenticatorData . $clientDataHash;
    $sig = $signature;
    $result = openssl_verify($signedData, $sig, $publicKeyPem, OPENSSL_ALGO_SHA256);
    return $result === 1;
}


