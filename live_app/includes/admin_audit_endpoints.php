<?php
/**
 * @doc-file
 * @doc-id live_app.includes.admin_audit_endpoints
 * @doc-kind helper-module
 * @doc-purpose Kapselt die Audit-JSON-Endpunkte der Admin-Oberflaeche ausserhalb des grossen Seitencontrollers.
 * @doc-entry Aufruf aus `admin.php` nach erfolgreicher Anmeldung.
 * @doc-input PDO-Verbindung, aktueller Benutzer, GET/POST-Parameter und CSRF-Token.
 * @doc-output JSON-Antworten fuer Audit-Ansicht und Audit-Loeschaktionen.
 * @doc-side-effects Liest und loescht Audit-Eintraege.
 */
declare(strict_types=1);

function admin_audit_current_ajax_action(): string
{
    if (isset($_GET['ajax_action']) && is_string($_GET['ajax_action'])) {
        return trim($_GET['ajax_action']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ajax_action']) && is_string($_POST['ajax_action'])) {
        return trim($_POST['ajax_action']);
    }
    return '';
}

function admin_audit_json_response(array $payload, int $statusCode = 200): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($payload);
}

function admin_handle_audit_ajax(PDO $pdo, array $currentUser): bool
{
    $ajaxAction = admin_audit_current_ajax_action();
    $knownActions = ['audit_log', 'audit_delete', 'audit_clear_table', 'audit_clear_all'];
    if (!in_array($ajaxAction, $knownActions, true)) {
        return false;
    }

    if (($currentUser['role'] ?? '') !== 'admin') {
        admin_audit_json_response(['success' => false, 'error' => 'Zugriff verweigert.'], 403);
        return true;
    }

    if ($ajaxAction === 'audit_log') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }
        $limitPlus = $limit + 1;

        $beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
        $table = trim((string)($_GET['table'] ?? ''));
        $action = trim((string)($_GET['action'] ?? ''));
        $user = trim((string)($_GET['user'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));

        $where = [];
        $params = [];

        if ($beforeId > 0) {
            $where[] = 'id < :before_id';
            $params[':before_id'] = $beforeId;
        }
        if ($table !== '' && $table !== 'all') {
            $where[] = 'table_name = :table_name';
            $params[':table_name'] = safe_substr($table, 0, 64);
        }
        if ($action !== '' && $action !== 'all') {
            $where[] = 'action = :action';
            $params[':action'] = safe_substr($action, 0, 16);
        }
        if ($user !== '') {
            $where[] = 'username LIKE :user';
            $params[':user'] = '%' . safe_substr($user, 0, 60) . '%';
        }
        if ($q !== '') {
            $where[] = '(row_pk LIKE :q OR username LIKE :q OR old_data LIKE :q OR new_data LIKE :q)';
            $params[':q'] = '%' . safe_substr($q, 0, 200) . '%';
        }
        if ($from !== '') {
            $ts = strtotime($from);
            if ($ts !== false) {
                $where[] = 'occurred_at >= :from';
                $params[':from'] = date('Y-m-d H:i:s', $ts);
            }
        }
        if ($to !== '') {
            $ts = strtotime($to);
            if ($ts !== false) {
                $where[] = 'occurred_at <= :to';
                $params[':to'] = date('Y-m-d H:i:s', $ts);
            }
        }

        $sql = 'SELECT id, occurred_at, table_name, action, row_pk, user_id, username, role, old_data, new_data FROM audit_log';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';

        try {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limitPlus, PDO::PARAM_INT);
            $stmt->execute();
            $rowsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $hasMore = count($rowsData) > $limit;
            if ($hasMore) {
                $rowsData = array_slice($rowsData, 0, $limit);
            }

            admin_audit_json_response([
                'success' => true,
                'has_more' => $hasMore,
                'rows' => $rowsData,
                'server_time' => date('c'),
            ]);
        } catch (Throwable $e) {
            admin_audit_json_response(['success' => false, 'error' => 'Audit Log konnte nicht geladen werden.'], 500);
        }
        return true;
    }

    if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
        admin_audit_json_response(['success' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen.']);
        return true;
    }

    if ($ajaxAction === 'audit_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            admin_audit_json_response(['success' => false, 'error' => 'Ungültige ID.']);
            return true;
        }
        try {
            $stmt = $pdo->prepare('DELETE FROM audit_log WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            admin_audit_json_response(['success' => true, 'deleted' => (int)$stmt->rowCount()]);
        } catch (Throwable $e) {
            admin_audit_json_response(['success' => false, 'error' => 'Eintrag konnte nicht gelöscht werden.'], 500);
        }
        return true;
    }

    if ($ajaxAction === 'audit_clear_table') {
        $table = trim((string)($_POST['table_name'] ?? ''));
        if (!in_array($table, ['teilnehmer', 'startnummern', 'users'], true)) {
            admin_audit_json_response(['success' => false, 'error' => 'Ungültige Tabelle.']);
            return true;
        }
        try {
            $stmt = $pdo->prepare('DELETE FROM audit_log WHERE table_name = ?');
            $stmt->execute([safe_substr($table, 0, 64)]);
            admin_audit_json_response(['success' => true, 'deleted' => (int)$stmt->rowCount()]);
        } catch (Throwable $e) {
            admin_audit_json_response(['success' => false, 'error' => 'Logs konnten nicht gelöscht werden.'], 500);
        }
        return true;
    }

    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $pdo->exec('TRUNCATE TABLE audit_log');
        admin_audit_json_response(['success' => true, 'deleted' => $count]);
    } catch (Throwable $e) {
        try {
            $deleted = $pdo->exec('DELETE FROM audit_log');
            admin_audit_json_response(['success' => true, 'deleted' => (int)($deleted === false ? 0 : $deleted)]);
        } catch (Throwable $e2) {
            admin_audit_json_response(['success' => false, 'error' => 'Logs konnten nicht gelöscht werden.'], 500);
        }
    }

    return true;
}
