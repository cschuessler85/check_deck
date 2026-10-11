<?php
/**
 * @doc-file
 * @doc-id live_app.scripts.create_admin
 * @doc-kind script
 * @doc-purpose Legt einen Admin-Benutzer fuer den Initialbetrieb oder Notfall an.
 * @doc-entry CLI-Aufruf durch Administratoren.
 * @doc-input Zugangsdaten, Datenbank und Konfiguration.
 * @doc-output Angelegter Admin-Benutzer oder Fehlermeldung.
 * @doc-side-effects Schreibt Benutzerdaten in die Datenbank.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once dirname(__DIR__) . '/includes/db_connect.php';

$username = isset($argv[1]) ? trim((string)$argv[1]) : '';
$password = isset($argv[2]) ? (string)$argv[2] : '';
$role = isset($argv[3]) ? trim((string)$argv[3]) : 'admin';
$allowedRoles = ['admin', 'cashier', 'orga', 'viewer'];

if ($username === '' || $password === '') {
    fwrite(STDERR, "Verwendung: php scripts/create_admin.php <benutzername> <passwort> [rolle]\n");
    exit(1);
}

if (!in_array($role, $allowedRoles, true)) {
    fwrite(STDERR, "Ungültige Rolle. Erlaubt: " . implode(', ', $allowedRoles) . "\n");
    exit(1);
}

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetchColumn()) {
        fwrite(STDERR, "Benutzer existiert bereits: {$username}\n");
        exit(1);
    }

    $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);

    fwrite(STDOUT, "Benutzer angelegt: {$username} ({$role})\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Benutzer konnte nicht angelegt werden: " . $e->getMessage() . "\n");
    exit(1);
}
