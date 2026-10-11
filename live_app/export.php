<?php
/**
 * @doc-file
 * @doc-id live_app.export
 * @doc-kind page-controller
 * @doc-purpose Erzeugt Exportansichten und Dateiausgaben fuer Teilnehmer- und Eventdaten.
 * @doc-entry HTTP-Aufruf durch berechtigte Benutzer.
 * @doc-input Session, Berechtigungen, Datenbank und Exportparameter.
 * @doc-output Dateidownload oder HTML-Vorschau.
 * @doc-side-effects Liest Daten und kann Download-Header senden.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

if (!auth_can($pdo, 'exports.participants')) {
    http_response_code(403);
    echo 'Zugriff verweigert.';
    exit;
}

$currentUser = auth_current_user($pdo);
$currentUsername = (string)($currentUser['username'] ?? ($_SESSION['username'] ?? 'Admin'));
$currentRole = (string)($currentUser['role'] ?? ($_SESSION['role'] ?? 'admin'));
$canDashboardPage = auth_can($pdo, 'participants.read');
$canNumbersPage = auth_can($pdo, 'numbers.read');

/**
 * @return list<array{id:int,name:string}>
 */
function export_get_team_options(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC, id ASC');
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $teams = [];

    foreach ($rows as $row) {
        $teams[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
        ];
    }

    return $teams;
}

/**
 * @param list<array{id:int,name:string}> $teamOptions
 * @return list<array{value:string,label:string}>
 */
function export_build_team_filter_options(array $teamOptions, bool $withNone = true): array
{
    $options = [
        ['value' => '', 'label' => 'Alle Teams'],
    ];

    if ($withNone) {
        $options[] = ['value' => '__none__', 'label' => 'Ohne Team'];
    }

    foreach ($teamOptions as $team) {
        $options[] = [
            'value' => (string)$team['id'],
            'label' => $team['name'],
        ];
    }

    return $options;
}

/**
 * @param list<array{id:int,name:string}> $teamOptions
 * @return array<string, array<string, mixed>>
 */
function export_get_sources(array $teamOptions): array
{
    return [
        'participants' => [
            'label' => 'Teilnehmer',
            'filename' => 'teilnehmer',
            'from' => '
                teilnehmer t
                LEFT JOIN teams te ON te.id = t.team_id
            ',
            'default_sort_1' => 'nachname',
            'default_dir_1' => 'ASC',
            'default_sort_2' => 'vorname',
            'default_dir_2' => 'ASC',
            'default_columns' => [
                'id',
                'code',
                'vorname',
                'nachname',
                'geschlecht',
                'teamname',
                'startnummer',
                'bezahlt',
                'bezahlt_am',
                'registriert_am',
            ],
            'columns' => [
                'id' => [
                    'label' => 'ID',
                    'sql' => 't.id',
                ],
                'code' => [
                    'label' => 'Code',
                    'sql' => 't.code',
                    'formatter' => static fn ($value): string => registration_code_format((string)$value),
                ],
                'vorname' => [
                    'label' => 'Vorname',
                    'sql' => 't.vorname',
                ],
                'nachname' => [
                    'label' => 'Nachname',
                    'sql' => 't.nachname',
                ],
                'volljaehrig' => [
                    'label' => 'Volljährig?',
                    'sql' => 't.volljaehrig',
                    'formatter' => static fn ($value): string => ((int)$value === 1) ? 'Ja' : 'Nein',
                ],
                'geschlecht' => [
                    'label' => 'Geschlecht',
                    'sql' => 't.geschlecht',
                ],
                'teamname' => [
                    'label' => 'Team',
                    'sql' => "COALESCE(te.name, t.teamname, '')",
                ],
                'startnummer' => [
                    'label' => 'Startnummer',
                    'sql' => 't.startnummer',
                ],
                'teilnahmebedingungen_akzeptiert' => [
                    'label' => 'Teilnahmebedingungen akzeptiert',
                    'sql' => 't.teilnahmebedingungen_akzeptiert',
                    'formatter' => static fn ($value): string => ((int)$value === 1) ? 'Ja' : 'Nein',
                ],
                'dsgvo_akzeptiert' => [
                    'label' => 'DSGVO akzeptiert',
                    'sql' => 't.dsgvo_akzeptiert',
                    'formatter' => static fn ($value): string => ((int)$value === 1) ? 'Ja' : 'Nein',
                ],
                'bild_ton_akzeptiert' => [
                    'label' => 'Bild/Ton akzeptiert',
                    'sql' => 't.bild_ton_akzeptiert',
                    'formatter' => static fn ($value): string => ((int)$value === 1) ? 'Ja' : 'Nein',
                ],
                'bezahlt' => [
                    'label' => 'Bezahlt?',
                    'sql' => 't.bezahlt',
                    'formatter' => static fn ($value): string => ((int)$value === 1) ? 'Ja' : 'Nein',
                ],
                'bezahlt_am' => [
                    'label' => 'Bezahlt am',
                    'sql' => 't.bezahlt_am',
                ],
                'registriert_am' => [
                    'label' => 'Registriert am',
                    'sql' => 't.registriert_am',
                ],
            ],
            'filters' => [
                'search' => [
                    'label' => 'Suche',
                    'type' => 'text',
                    'placeholder' => 'Name oder Code',
                    'default' => '',
                    'max_length' => 100,
                    'apply' => static function (string $value): ?array {
                        if ($value === '') {
                            return null;
                        }

                        return [
                            'sql' => '(t.vorname LIKE :f_participants_search OR t.nachname LIKE :f_participants_search OR t.code LIKE :f_participants_search)',
                            'params' => [':f_participants_search' => '%' . $value . '%'],
                        ];
                    },
                ],
                'team' => [
                    'label' => 'Team',
                    'type' => 'select',
                    'default' => '',
                    'options' => export_build_team_filter_options($teamOptions),
                    'apply' => static function (string $value): ?array {
                        if ($value === '') {
                            return null;
                        }

                        if ($value === '__none__') {
                            return [
                                'sql' => '(t.team_id IS NULL OR t.team_id = 0)',
                                'params' => [],
                            ];
                        }

                        if (!ctype_digit($value)) {
                            return null;
                        }

                        return [
                            'sql' => 't.team_id = :f_participants_team',
                            'params' => [':f_participants_team' => (int)$value],
                        ];
                    },
                ],
                'bezahlt' => [
                    'label' => 'Bezahlt',
                    'type' => 'select',
                    'default' => '',
                    'options' => [
                        ['value' => '', 'label' => 'Alle'],
                        ['value' => '1', 'label' => 'Ja'],
                        ['value' => '0', 'label' => 'Nein'],
                    ],
                    'apply' => static function (string $value): ?array {
                        if ($value !== '0' && $value !== '1') {
                            return null;
                        }

                        return [
                            'sql' => 't.bezahlt = :f_participants_paid',
                            'params' => [':f_participants_paid' => (int)$value],
                        ];
                    },
                ],
                'volljaehrig' => [
                    'label' => 'Volljährig',
                    'type' => 'select',
                    'default' => '',
                    'options' => [
                        ['value' => '', 'label' => 'Alle'],
                        ['value' => '1', 'label' => 'Ja'],
                        ['value' => '0', 'label' => 'Nein'],
                    ],
                    'apply' => static function (string $value): ?array {
                        if ($value !== '0' && $value !== '1') {
                            return null;
                        }

                        return [
                            'sql' => 't.volljaehrig = :f_participants_adult',
                            'params' => [':f_participants_adult' => (int)$value],
                        ];
                    },
                ],
                'startnummer' => [
                    'label' => 'Startnummer',
                    'type' => 'select',
                    'default' => '',
                    'options' => [
                        ['value' => '', 'label' => 'Alle'],
                        ['value' => '1', 'label' => 'Mit Startnummer'],
                        ['value' => '0', 'label' => 'Ohne Startnummer'],
                    ],
                    'apply' => static function (string $value): ?array {
                        if ($value === '1') {
                            return [
                                'sql' => '(t.startnummer IS NOT NULL AND t.startnummer <> 0)',
                                'params' => [],
                            ];
                        }

                        if ($value === '0') {
                            return [
                                'sql' => '(t.startnummer IS NULL OR t.startnummer = 0)',
                                'params' => [],
                            ];
                        }

                        return null;
                    },
                ],
            ],
        ],
        'teams' => [
            'label' => 'Teams',
            'filename' => 'teams',
            'from' => 'teams te',
            'default_sort_1' => 'name',
            'default_dir_1' => 'ASC',
            'default_sort_2' => 'id',
            'default_dir_2' => 'ASC',
            'default_columns' => [
                'id',
                'name',
                'created_at',
            ],
            'columns' => [
                'id' => [
                    'label' => 'ID',
                    'sql' => 'te.id',
                ],
                'name' => [
                    'label' => 'Name',
                    'sql' => 'te.name',
                ],
                'created_at' => [
                    'label' => 'Angelegt am',
                    'sql' => 'te.created_at',
                ],
            ],
            'filters' => [
                'search' => [
                    'label' => 'Teamsuche',
                    'type' => 'text',
                    'placeholder' => 'Teamname',
                    'default' => '',
                    'max_length' => 100,
                    'apply' => static function (string $value): ?array {
                        if ($value === '') {
                            return null;
                        }

                        return [
                            'sql' => 'te.name LIKE :f_teams_search',
                            'params' => [':f_teams_search' => '%' . $value . '%'],
                        ];
                    },
                ],
            ],
        ],
        'start_numbers' => [
            'label' => 'Startnummern',
            'filename' => 'startnummern',
            'from' => '
                startnummern s
                LEFT JOIN teilnehmer t ON t.id = s.teilnehmer_id
                LEFT JOIN teams ts ON ts.id = s.team_id
                LEFT JOIN teams tp ON tp.id = t.team_id
            ',
            'default_sort_1' => 'nummer',
            'default_dir_1' => 'ASC',
            'default_sort_2' => 'status',
            'default_dir_2' => 'ASC',
            'default_columns' => [
                'nummer',
                'status',
                'reserviert_fuer',
                'teamname',
                'teilnehmer_code',
                'teilnehmer_vorname',
                'teilnehmer_nachname',
            ],
            'columns' => [
                'nummer' => [
                    'label' => 'Nummer',
                    'sql' => 's.nummer',
                ],
                'status' => [
                    'label' => 'Status',
                    'sql' => 's.status',
                ],
                'reserviert_fuer' => [
                    'label' => 'Reserviert für',
                    'sql' => 's.reserviert_fuer',
                ],
                'team_id' => [
                    'label' => 'Team-ID',
                    'sql' => 's.team_id',
                ],
                'teamname' => [
                    'label' => 'Team',
                    'sql' => "COALESCE(ts.name, tp.name, t.teamname, '')",
                ],
                'teilnehmer_id' => [
                    'label' => 'Teilnehmer-ID',
                    'sql' => 's.teilnehmer_id',
                ],
                'teilnehmer_code' => [
                    'label' => 'Teilnehmer-Code',
                    'sql' => 't.code',
                    'formatter' => static fn ($value): string => $value === null || $value === '' ? '' : registration_code_format((string)$value),
                ],
                'teilnehmer_vorname' => [
                    'label' => 'Teilnehmer Vorname',
                    'sql' => 't.vorname',
                ],
                'teilnehmer_nachname' => [
                    'label' => 'Teilnehmer Nachname',
                    'sql' => 't.nachname',
                ],
            ],
            'filters' => [
                'search' => [
                    'label' => 'Suche',
                    'type' => 'text',
                    'placeholder' => 'Nummer, Name, Code oder Reservierung',
                    'default' => '',
                    'max_length' => 100,
                    'apply' => static function (string $value): ?array {
                        if ($value === '') {
                            return null;
                        }

                        return [
                            'sql' => '(CAST(s.nummer AS CHAR) LIKE :f_numbers_search OR s.reserviert_fuer LIKE :f_numbers_search OR t.vorname LIKE :f_numbers_search OR t.nachname LIKE :f_numbers_search OR t.code LIKE :f_numbers_search)',
                            'params' => [':f_numbers_search' => '%' . $value . '%'],
                        ];
                    },
                ],
                'status' => [
                    'label' => 'Status',
                    'type' => 'select',
                    'default' => '',
                    'options' => [
                        ['value' => '', 'label' => 'Alle'],
                        ['value' => 'frei', 'label' => 'Frei'],
                        ['value' => 'reserviert', 'label' => 'Reserviert'],
                        ['value' => 'vergeben', 'label' => 'Vergeben'],
                    ],
                    'apply' => static function (string $value): ?array {
                        if (!in_array($value, ['frei', 'reserviert', 'vergeben'], true)) {
                            return null;
                        }

                        return [
                            'sql' => 's.status = :f_numbers_status',
                            'params' => [':f_numbers_status' => $value],
                        ];
                    },
                ],
                'team' => [
                    'label' => 'Team',
                    'type' => 'select',
                    'default' => '',
                    'options' => export_build_team_filter_options($teamOptions),
                    'apply' => static function (string $value): ?array {
                        if ($value === '') {
                            return null;
                        }

                        if ($value === '__none__') {
                            return [
                                'sql' => '(s.team_id IS NULL OR s.team_id = 0) AND (t.team_id IS NULL OR t.team_id = 0)',
                                'params' => [],
                            ];
                        }

                        if (!ctype_digit($value)) {
                            return null;
                        }

                        return [
                            'sql' => '(s.team_id = :f_numbers_team OR t.team_id = :f_numbers_team)',
                            'params' => [':f_numbers_team' => (int)$value],
                        ];
                    },
                ],
                'zugewiesen' => [
                    'label' => 'Zugewiesen',
                    'type' => 'select',
                    'default' => '',
                    'options' => [
                        ['value' => '', 'label' => 'Alle'],
                        ['value' => '1', 'label' => 'Mit Teilnehmer'],
                        ['value' => '0', 'label' => 'Ohne Teilnehmer'],
                    ],
                    'apply' => static function (string $value): ?array {
                        if ($value === '1') {
                            return [
                                'sql' => 's.teilnehmer_id IS NOT NULL',
                                'params' => [],
                            ];
                        }

                        if ($value === '0') {
                            return [
                                'sql' => 's.teilnehmer_id IS NULL',
                                'params' => [],
                            ];
                        }

                        return null;
                    },
                ],
            ],
        ],
    ];
}

function export_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function export_csv_safe(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }

    return $value;
}

/**
 * @param array<string, mixed> $column
 * @param array<string, mixed> $row
 */
function export_format_value(array $column, array $row, string $key): string
{
    $value = $row[$key] ?? '';

    if (isset($column['formatter']) && is_callable($column['formatter'])) {
        return (string)call_user_func($column['formatter'], $value, $row);
    }

    if ($value === null) {
        return '';
    }

    return (string)$value;
}

/**
 * @param array<string, array<string, mixed>> $sources
 */
function export_pick_source(array $sources, ?string $requested): string
{
    if ($requested !== null && isset($sources[$requested])) {
        return $requested;
    }

    return (string)array_key_first($sources);
}

/**
 * @param array<string, mixed> $source
 * @param list<string> $requestedColumns
 * @return list<string>
 */
function export_pick_columns(array $source, array $requestedColumns, ?string $columnOrder): array
{
    $allowed = array_keys($source['columns']);
    $requested = [];

    foreach ($requestedColumns as $columnKey) {
        if (in_array($columnKey, $allowed, true) && !in_array($columnKey, $requested, true)) {
            $requested[] = $columnKey;
        }
    }

    if ($requested === []) {
        $requested = $source['default_columns'];
    }

    $ordered = [];
    $orderKeys = array_filter(array_map('trim', explode(',', (string)$columnOrder)));
    foreach ($orderKeys as $columnKey) {
        if (in_array($columnKey, $requested, true) && !in_array($columnKey, $ordered, true)) {
            $ordered[] = $columnKey;
        }
    }

    foreach ($requested as $columnKey) {
        if (!in_array($columnKey, $ordered, true)) {
            $ordered[] = $columnKey;
        }
    }

    return $ordered;
}

/**
 * @param array<string, mixed> $source
 */
function export_pick_sort_field(array $source, ?string $requested, string $fallbackKey): string
{
    $columns = $source['columns'];
    if ($requested !== null && $requested !== '' && isset($columns[$requested])) {
        return $requested;
    }

    return $fallbackKey;
}

function export_pick_sort_direction(?string $requested, string $fallback): string
{
    $value = strtoupper((string)$requested);
    if ($value === 'ASC' || $value === 'DESC') {
        return $value;
    }

    return $fallback;
}

/**
 * @param array<string, mixed> $source
 * @param array<string, mixed> $requestFilters
 * @return array<string, string>
 */
function export_pick_filters(array $source, array $requestFilters): array
{
    $picked = [];
    $filters = $source['filters'] ?? [];

    foreach ($filters as $filterKey => $filter) {
        $value = isset($requestFilters[$filterKey]) ? trim((string)$requestFilters[$filterKey]) : (string)($filter['default'] ?? '');

        if (($filter['type'] ?? '') === 'select') {
            $allowedValues = array_map(
                static fn (array $option): string => (string)$option['value'],
                $filter['options'] ?? []
            );
            if (!in_array($value, $allowedValues, true)) {
                $value = (string)($filter['default'] ?? '');
            }
        } else {
            $maxLength = (int)($filter['max_length'] ?? 100);
            if ($maxLength > 0 && strlen($value) > $maxLength) {
                $value = substr($value, 0, $maxLength);
            }
        }

        $picked[$filterKey] = $value;
    }

    return $picked;
}

/**
 * @param array<string, mixed> $source
 * @param list<string> $selectedColumns
 * @param array<string, string> $selectedFilters
 * @return array{sql:string,params:array<string,mixed>}
 */
function export_build_query(array $source, array $selectedColumns, array $selectedFilters, ?string $sort1, string $dir1, ?string $sort2, string $dir2, ?int $limit = null): array
{
    $selectParts = [];
    foreach ($selectedColumns as $columnKey) {
        $column = $source['columns'][$columnKey];
        $selectParts[] = $column['sql'] . ' AS `' . $columnKey . '`';
    }

    $conditions = [];
    $params = [];
    foreach (($source['filters'] ?? []) as $filterKey => $filter) {
        $apply = $filter['apply'] ?? null;
        if (!is_callable($apply)) {
            continue;
        }

        $result = $apply((string)($selectedFilters[$filterKey] ?? ''));
        if (!is_array($result) || ($result['sql'] ?? '') === '') {
            continue;
        }

        $conditions[] = (string)$result['sql'];
        foreach (($result['params'] ?? []) as $paramName => $paramValue) {
            $params[(string)$paramName] = $paramValue;
        }
    }

    $orderParts = [];
    if ($sort1 !== null && isset($source['columns'][$sort1])) {
        $orderParts[] = $source['columns'][$sort1]['sql'] . ' ' . $dir1;
    }
    if ($sort2 !== null && $sort2 !== $sort1 && isset($source['columns'][$sort2])) {
        $orderParts[] = $source['columns'][$sort2]['sql'] . ' ' . $dir2;
    }

    $sql = "SELECT\n    " . implode(",\n    ", $selectParts) . "\nFROM " . trim((string)$source['from']);
    if ($conditions !== []) {
        $sql .= "\nWHERE " . implode("\n  AND ", $conditions);
    }
    if ($orderParts !== []) {
        $sql .= "\nORDER BY " . implode(', ', $orderParts);
    }
    if ($limit !== null) {
        $sql .= "\nLIMIT " . max(1, (int)$limit);
    }

    return [
        'sql' => $sql,
        'params' => $params,
    ];
}

/**
 * @param array<string, mixed> $query
 */
function export_execute_query(PDO $pdo, array $query): PDOStatement
{
    $stmt = $pdo->prepare((string)$query['sql']);
    $stmt->execute($query['params'] ?? []);
    return $stmt;
}

$teamOptions = export_get_team_options($pdo);
$sources = export_get_sources($teamOptions);
$sourceKey = export_pick_source($sources, isset($_GET['source']) ? (string)$_GET['source'] : null);
$source = $sources[$sourceKey];
$selectedColumns = export_pick_columns(
    $source,
    array_values(array_filter((array)($_GET['columns'] ?? []), 'is_string')),
    isset($_GET['column_order']) ? (string)$_GET['column_order'] : null
);
$selectedFilters = export_pick_filters($source, (array)($_GET['filters'] ?? []));

$sort1 = export_pick_sort_field($source, isset($_GET['sort1']) ? (string)$_GET['sort1'] : null, (string)$source['default_sort_1']);
$dir1 = export_pick_sort_direction(isset($_GET['dir1']) ? (string)$_GET['dir1'] : null, (string)$source['default_dir_1']);

$sort2Raw = isset($_GET['sort2']) ? trim((string)$_GET['sort2']) : '';
$sort2 = $sort2Raw === '' ? null : export_pick_sort_field($source, $sort2Raw, (string)$source['default_sort_2']);
$dir2 = export_pick_sort_direction(isset($_GET['dir2']) ? (string)$_GET['dir2'] : null, (string)$source['default_dir_2']);

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$previewRows = [];
$previewLimit = 50;
$errorMessage = '';
$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$assetBase = $assetBase === '' ? '' : $assetBase;
$is_standalone = isset($_GET['standalone']) && (string)$_GET['standalone'] === '1';

if ($selectedColumns === []) {
    $errorMessage = 'Bitte mindestens eine Spalte auswählen.';
}

try {
    if ($errorMessage === '' && $action === 'export') {
        $query = export_build_query($source, $selectedColumns, $selectedFilters, $sort1, $dir1, $sort2, $dir2);
        $stmt = export_execute_query($pdo, $query);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $source['filename'] . '_' . date('Y-m-d_H-i') . '.csv"');

        $out = fopen('php://output', 'wb');
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headerRow = [];
        foreach ($selectedColumns as $columnKey) {
            $headerRow[] = export_csv_safe((string)$source['columns'][$columnKey]['label']);
        }
        fputcsv($out, $headerRow, ';');

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $csvRow = [];
            foreach ($selectedColumns as $columnKey) {
                $csvRow[] = export_csv_safe(export_format_value($source['columns'][$columnKey], $row, $columnKey));
            }
            fputcsv($out, $csvRow, ';');
        }

        fclose($out);
        exit;
    }

    if ($errorMessage === '' && $action === 'preview') {
        $query = export_build_query($source, $selectedColumns, $selectedFilters, $sort1, $dir1, $sort2, $dir2, $previewLimit);
        $stmt = export_execute_query($pdo, $query);
        $previewRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $errorMessage = 'Die Exportdaten konnten nicht geladen werden.';
}
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daten exportieren</title>
  <link rel="stylesheet" href="<?= export_html($assetBase . '/assets/style.css') ?>?v=<?= export_html(app_asset_version('assets/style.css')) ?>">
  <script>
    (() => {
      const key = 'admin_theme';
      let theme = '';
      try {
        theme = String(localStorage.getItem(key) || '');
      } catch (e) {
        theme = '';
      }
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
      --focus: rgba(255, 237, 0, 0.18);
      --dlrg-schwarz: #2f3845;
    }

    html[data-theme="dark"] .panel,
    html[data-theme="dark"] .alert,
    html[data-theme="dark"] .modal-panel,
    html[data-theme="dark"] fieldset,
    html[data-theme="dark"] .table-wrap,
    html[data-theme="dark"] .selected-item {
      background: #141a24;
      color: var(--text);
    }

    html[data-theme="dark"] input[type="text"],
    html[data-theme="dark"] select,
    html[data-theme="dark"] .icon-btn {
      background: #0f131a;
      color: var(--text);
      border-color: var(--border);
    }

    html[data-theme="dark"] tr:nth-child(even) td {
      background: rgba(255, 255, 255, 0.03);
    }

    body {
      background:
        radial-gradient(900px 500px at 20% 10%, rgba(255, 237, 0, 0.24), transparent 60%),
        radial-gradient(900px 500px at 80% 10%, rgba(5, 105, 180, 0.14), transparent 62%),
        linear-gradient(180deg, #8c0710 0%, #e30613 48%, #e30613 100%);
    }

    .page-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
    }

    .page-title {
      margin: 0;
      font-size: 30px;
      line-height: 1.04;
      letter-spacing: -0.02em;
    }

    .page-subtitle {
      margin: 6px 0 0;
      color: var(--muted);
      font-size: 15px;
      line-height: 1.5;
    }

    .intro-copy {
      max-width: 860px;
    }

    .page-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .layout {
      display: grid;
      grid-template-columns: minmax(320px, 420px) minmax(420px, 1fr);
      gap: 18px;
      align-items: start;
    }

    .panel h2 {
      margin: 0 0 10px;
      font-size: 20px;
      line-height: 1.15;
    }

    .field {
      margin-bottom: 14px;
    }

    .field label,
    .field legend {
      display: block;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .field small {
      display: block;
      margin-top: 6px;
      color: var(--muted);
      line-height: 1.45;
    }

    select,
    input[type="text"] {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 10px;
    }

    fieldset {
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 14px;
      margin: 0;
    }

    .filter-grid {
      display: grid;
      gap: 12px;
    }

    .checkbox-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
      max-height: 360px;
      overflow: auto;
      padding-right: 4px;
    }

    .checkbox-grid label {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 0;
      font-weight: 400;
    }

    .inline-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 12px;
    }

    .sort-grid {
      display: grid;
      grid-template-columns: 1fr 120px;
      gap: 10px;
      margin-bottom: 10px;
    }

    .selected-list {
      display: grid;
      gap: 10px;
      margin-top: 12px;
    }

    .selected-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 11px 12px;
      border: 1px solid var(--border);
      border-radius: 12px;
    }

    .selected-item strong {
      font-size: 14px;
    }

    .item-actions {
      display: flex;
      gap: 6px;
      flex-shrink: 0;
    }

    .icon-btn {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      border: 1px solid var(--border);
      font-size: 16px;
      cursor: pointer;
    }

    .icon-btn:disabled {
      opacity: 0.45;
      cursor: not-allowed;
    }

    .empty-state,
    .helper {
      color: var(--muted);
      font-size: 14px;
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 18px;
    }

    .preview-meta {
      color: var(--muted);
      margin-bottom: 12px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 12px;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: #f7f8fb;
      font-size: 14px;
      font-weight: 600;
    }

    .table-wrap {
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 12px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 700px;
    }

    th,
    td {
      padding: 11px 12px;
      border-bottom: 1px solid var(--border);
      text-align: left;
      vertical-align: top;
      white-space: nowrap;
      line-height: 1.35;
    }

    thead th {
      background: var(--dlrg-rot);
      color: #fff;
      font-size: 14px;
      letter-spacing: 0.01em;
    }

    @media (max-width: 960px) {
      .layout {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 720px) {
      .page-title {
        font-size: 24px;
      }

      .page-actions,
      .actions {
        width: 100%;
      }

      .page-actions > .btn,
      .actions > .btn,
      .actions > a,
      .actions > button {
        width: 100%;
      }

      .sort-grid {
        grid-template-columns: 1fr;
      }

      .selected-item {
        flex-direction: column;
        align-items: stretch;
      }

      .item-actions {
        justify-content: flex-end;
      }

      .chip,
      .checkbox-grid label {
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="container">
      <header class="reg-header">
        <img src="<?= export_html($assetBase . '/assets/logo.png') ?>" alt="DLRG Sankt Augustin e.V." class="reg-logo" width="1714" height="332" decoding="async">
        <h1 class="reg-title">
          <span>Admin</span>
          <span>Daten exportieren</span>
          <span>CSV-Vorschau und Export</span>
        </h1>
      </header>

      <div class="page-shell<?php echo $is_standalone ? ' page-shell-standalone' : ''; ?>">
      <?php if (!$is_standalone): ?>
      <aside class="page-sidebar">
        <div class="panel sidebar-panel">
          <div class="sidebar-copy">
            <p class="sidebar-label">Export</p>
            <h2 class="sidebar-title">Daten exportieren</h2>
            <p class="sidebar-subtitle">Quelle waehlen, filtern, Spalten zusammenstellen, Reihenfolge festlegen und die Vorschau direkt rechts pruefen.</p>
          </div>
          <div class="hint admin-session-info" style="margin:0;">
            <span>Angemeldet als: <strong><?= export_html($currentUsername) ?></strong></span>
            <span class="admin-session-badge"><?= export_html($currentRole) ?></span>
          </div>
          <div class="sidebar-links">
            <button type="button" class="btn btn-secondary" id="theme_toggle" aria-pressed="false">Dunkelmodus</button>
            <a class="btn btn-secondary" href="admin.php">Admin</a>
            <?php if ($canDashboardPage): ?>
              <a class="btn btn-secondary" href="dashboard/dashboard.php">Dashboard</a>
            <?php endif; ?>
            <?php if ($canNumbersPage): ?>
              <a class="btn btn-secondary" href="numbers.php">Startnummern</a>
            <?php endif; ?>
            <form method="post" action="admin.php" style="display:inline;"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>"><button type="submit" class="btn btn-secondary">Abmelden</button></form>
          </div>
        </div>
      </aside>
      <?php endif; ?>
      <div class="page-main">
      <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><strong>Fehler:</strong> <?= export_html($errorMessage) ?></div>
      <?php endif; ?>

      <div class="layout">
      <form class="panel" method="get" action="export.php" id="export-form">
        <h2>Export-Einstellungen</h2>

        <div class="field">
          <label for="source">Datenquelle</label>
          <select name="source" id="source" onchange="document.getElementById('export-form').submit()">
            <?php foreach ($sources as $key => $config): ?>
              <option value="<?= export_html($key) ?>" <?= $key === $sourceKey ? 'selected' : '' ?>>
                <?= export_html((string)$config['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small>Aktuell freigegeben: Teilnehmer, Teams und Startnummern.</small>
        </div>

        <?php if (($source['filters'] ?? []) !== []): ?>
          <div class="field">
            <fieldset>
              <legend>Filter</legend>
              <div class="filter-grid">
                <?php foreach ($source['filters'] as $filterKey => $filter): ?>
                  <div>
                    <label for="filter_<?= export_html($filterKey) ?>"><?= export_html((string)$filter['label']) ?></label>
                    <?php if (($filter['type'] ?? '') === 'select'): ?>
                      <select name="filters[<?= export_html($filterKey) ?>]" id="filter_<?= export_html($filterKey) ?>">
                        <?php foreach (($filter['options'] ?? []) as $option): ?>
                          <option value="<?= export_html((string)$option['value']) ?>" <?= ((string)$option['value'] === ($selectedFilters[$filterKey] ?? '')) ? 'selected' : '' ?>>
                            <?= export_html((string)$option['label']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input
                        type="text"
                        name="filters[<?= export_html($filterKey) ?>]"
                        id="filter_<?= export_html($filterKey) ?>"
                        value="<?= export_html($selectedFilters[$filterKey] ?? '') ?>"
                        placeholder="<?= export_html((string)($filter['placeholder'] ?? '')) ?>"
                      >
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </fieldset>
          </div>
        <?php endif; ?>

        <div class="field">
          <fieldset>
            <legend>Spalten auswählen</legend>
            <div class="inline-actions">
              <button class="btn" type="button" id="select-all-columns">Alle auswählen</button>
              <button class="btn" type="button" id="reset-default-columns">Standard</button>
            </div>
            <div class="checkbox-grid">
              <?php foreach ($source['columns'] as $columnKey => $column): ?>
                <label>
                  <input
                    type="checkbox"
                    name="columns[]"
                    value="<?= export_html($columnKey) ?>"
                    <?= in_array($columnKey, $selectedColumns, true) ? 'checked' : '' ?>
                  >
                  <span><?= export_html((string)$column['label']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        </div>

        <div class="field">
          <label>Ausgewählte Spalten-Reihenfolge</label>
          <small class="helper">Mit den Pfeilen legst du fest, in welcher Reihenfolge die Spalten in Vorschau und CSV erscheinen.</small>
          <input type="hidden" name="column_order" id="column_order" value="<?= export_html(implode(',', $selectedColumns)) ?>">
          <div class="selected-list" id="selected-columns"></div>
        </div>

        <div class="field">
          <label>Datensätze sortieren</label>
          <div class="sort-grid">
            <select name="sort1">
              <?php foreach ($source['columns'] as $columnKey => $column): ?>
                <option value="<?= export_html($columnKey) ?>" <?= $columnKey === $sort1 ? 'selected' : '' ?>>
                  <?= export_html((string)$column['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="dir1">
              <option value="ASC" <?= $dir1 === 'ASC' ? 'selected' : '' ?>>Aufsteigend</option>
              <option value="DESC" <?= $dir1 === 'DESC' ? 'selected' : '' ?>>Absteigend</option>
            </select>
          </div>
          <div class="sort-grid">
            <select name="sort2">
              <option value="">Keine zweite Sortierung</option>
              <?php foreach ($source['columns'] as $columnKey => $column): ?>
                <option value="<?= export_html($columnKey) ?>" <?= $columnKey === $sort2 ? 'selected' : '' ?>>
                  <?= export_html((string)$column['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="dir2">
              <option value="ASC" <?= $dir2 === 'ASC' ? 'selected' : '' ?>>Aufsteigend</option>
              <option value="DESC" <?= $dir2 === 'DESC' ? 'selected' : '' ?>>Absteigend</option>
            </select>
          </div>
        </div>

        <div class="actions">
          <a class="btn btn-secondary" href="export.php?source=<?= urlencode($sourceKey) ?>">Zurücksetzen</a>
          <button class="btn btn-yellow" type="submit" name="action" value="preview">Vorschau laden</button>
          <button class="btn btn-primary" type="submit" name="action" value="export">CSV exportieren</button>
        </div>
      </form>

      <div class="panel">
        <h2>Vorschau</h2>
        <?php if ($action === 'preview' && $errorMessage === ''): ?>
          <div class="preview-meta">
            <?= count($previewRows) ?> Zeilen geladen<?php if (count($previewRows) === $previewLimit): ?>, Vorschau begrenzt auf <?= $previewLimit ?> Einträge<?php endif; ?>.
          </div>
          <?php if ($previewRows === []): ?>
            <p class="empty-state">Keine Datensätze für die aktuelle Auswahl und Filterung gefunden.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table id="export_preview_table" data-resize-id="export_preview_table">
                <thead>
                  <tr>
                    <?php foreach ($selectedColumns as $columnKey): ?>
                      <th><?= export_html((string)$source['columns'][$columnKey]['label']) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($previewRows as $row): ?>
                    <tr>
                      <?php foreach ($selectedColumns as $columnKey): ?>
                        <td><?= export_html(export_format_value($source['columns'][$columnKey], $row, $columnKey)) ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="empty-state">Noch keine Vorschau geladen. Wähle Quelle, Filter, Spalten und Sortierung und klicke dann auf <strong>Vorschau laden</strong>.</p>
        <?php endif; ?>
      </div>
      </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      var root = document.documentElement;
      var themeButton = document.getElementById('theme_toggle');
      var form = document.getElementById('export-form');
      var list = document.getElementById('selected-columns');
      var hiddenOrder = document.getElementById('column_order');
      var checkboxSelector = 'input[name="columns[]"]';
      var defaultOrder = <?= json_encode(array_values($source['default_columns']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

      function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        if (!themeButton) {
          return;
        }
        themeButton.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        themeButton.textContent = theme === 'dark' ? 'Hellmodus' : 'Dunkelmodus';
      }

      function setupResizableTable(table, options) {
        if (!table || !table.tHead || !table.tHead.rows.length) {
          return;
        }

        var storageKey = (options && options.storageKey) ? options.storageKey : ('resizable_' + (table.id || 'table'));
        var minWidth = (options && options.minWidth) ? options.minWidth : 110;
        var headers = Array.prototype.slice.call(table.tHead.rows[0].cells);
        var colgroup = table.querySelector('colgroup');
        var resizeState = null;

        if (!colgroup) {
          colgroup = document.createElement('colgroup');
          headers.forEach(function () {
            colgroup.appendChild(document.createElement('col'));
          });
          table.insertBefore(colgroup, table.firstChild);
        }

        var cols = Array.prototype.slice.call(colgroup.children);
        headers.forEach(function (th, index) {
          if (cols[index]) {
            cols[index].style.width = Math.max(minWidth, Math.round(th.getBoundingClientRect().width)) + 'px';
          }
          th.classList.add('is-resizable');
          if (!th.querySelector('.table-resize-handle')) {
            var handle = document.createElement('span');
            handle.className = 'table-resize-handle';
            handle.setAttribute('aria-hidden', 'true');
            handle.addEventListener('pointerdown', function (event) {
              resizeState = {
                index: index,
                startX: event.clientX,
                startWidth: Math.max(minWidth, Math.round(cols[index].getBoundingClientRect().width || th.getBoundingClientRect().width)),
                handle: handle
              };
              handle.classList.add('is-active');
              document.body.style.cursor = 'col-resize';
              document.body.style.userSelect = 'none';
              event.preventDefault();
            });
            th.appendChild(handle);
          }
        });

        try {
          var savedWidths = JSON.parse(localStorage.getItem(storageKey) || '{}');
          Object.keys(savedWidths).forEach(function (key) {
            var index = Number(key);
            if (cols[index] && Number(savedWidths[key]) > 0) {
              cols[index].style.width = Math.max(minWidth, Number(savedWidths[key])) + 'px';
            }
          });
        } catch (e) {
        }

        window.addEventListener('pointermove', function (event) {
          if (!resizeState) {
            return;
          }
          cols[resizeState.index].style.width = Math.max(minWidth, resizeState.startWidth + (event.clientX - resizeState.startX)) + 'px';
        });

        window.addEventListener('pointerup', function () {
          if (!resizeState) {
            return;
          }
          resizeState.handle.classList.remove('is-active');
          document.body.style.cursor = '';
          document.body.style.userSelect = '';
          try {
            var current = JSON.parse(localStorage.getItem(storageKey) || '{}');
            current[resizeState.index] = Math.round(cols[resizeState.index].getBoundingClientRect().width);
            localStorage.setItem(storageKey, JSON.stringify(current));
          } catch (e) {
          }
          resizeState = null;
        });
      }

      applyTheme(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

      if (themeButton) {
        themeButton.addEventListener('click', function () {
          var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
          applyTheme(next);
          try {
            localStorage.setItem('admin_theme', next);
          } catch (e) {
          }
        });
      }

      function getCheckboxes() {
        return Array.prototype.slice.call(document.querySelectorAll(checkboxSelector));
      }

      function getSelectedKeys() {
        return getCheckboxes()
          .filter(function (checkbox) { return checkbox.checked; })
          .map(function (checkbox) { return checkbox.value; });
      }

      function getColumnLabel(key) {
        var checkbox = document.querySelector(checkboxSelector + '[value="' + CSS.escape(key) + '"]');
        if (!checkbox) {
          return key;
        }
        var label = checkbox.closest('label');
        return label ? label.textContent.trim() : key;
      }

      function renderSelectedColumns() {
        var selectedKeys = getSelectedKeys();
        var orderedKeys = hiddenOrder.value
          .split(',')
          .map(function (value) { return value.trim(); })
          .filter(function (value) { return value !== ''; })
          .filter(function (value, index, array) { return array.indexOf(value) === index; })
          .filter(function (value) { return selectedKeys.indexOf(value) !== -1; });

        selectedKeys.forEach(function (key) {
          if (orderedKeys.indexOf(key) === -1) {
            orderedKeys.push(key);
          }
        });

        hiddenOrder.value = orderedKeys.join(',');
        list.innerHTML = '';

        if (orderedKeys.length === 0) {
          list.innerHTML = '<div class="empty-state">Noch keine Spalten ausgewählt.</div>';
          return;
        }

        orderedKeys.forEach(function (key, index) {
          var item = document.createElement('div');
          item.className = 'selected-item';

          var title = document.createElement('strong');
          title.textContent = getColumnLabel(key);

          var actions = document.createElement('div');
          actions.className = 'item-actions';

          var upButton = document.createElement('button');
          upButton.type = 'button';
          upButton.className = 'icon-btn';
          upButton.textContent = '↑';
          upButton.disabled = index === 0;
          upButton.addEventListener('click', function () {
            var swap = orderedKeys[index - 1];
            orderedKeys[index - 1] = orderedKeys[index];
            orderedKeys[index] = swap;
            hiddenOrder.value = orderedKeys.join(',');
            renderSelectedColumns();
          });

          var downButton = document.createElement('button');
          downButton.type = 'button';
          downButton.className = 'icon-btn';
          downButton.textContent = '↓';
          downButton.disabled = index === orderedKeys.length - 1;
          downButton.addEventListener('click', function () {
            var swap = orderedKeys[index + 1];
            orderedKeys[index + 1] = orderedKeys[index];
            orderedKeys[index] = swap;
            hiddenOrder.value = orderedKeys.join(',');
            renderSelectedColumns();
          });

          actions.appendChild(upButton);
          actions.appendChild(downButton);
          item.appendChild(title);
          item.appendChild(actions);
          list.appendChild(item);
        });
      }

      document.getElementById('select-all-columns').addEventListener('click', function () {
        getCheckboxes().forEach(function (checkbox) {
          checkbox.checked = true;
        });
        renderSelectedColumns();
      });

      document.getElementById('reset-default-columns').addEventListener('click', function () {
        getCheckboxes().forEach(function (checkbox) {
          checkbox.checked = defaultOrder.indexOf(checkbox.value) !== -1;
        });
        hiddenOrder.value = defaultOrder.join(',');
        renderSelectedColumns();
      });

      getCheckboxes().forEach(function (checkbox) {
        checkbox.addEventListener('change', renderSelectedColumns);
      });

      form.addEventListener('submit', function () {
        renderSelectedColumns();
      });

      renderSelectedColumns();
      setupResizableTable(document.getElementById('export_preview_table'), {
        storageKey: 'export_preview_table_widths',
        minWidth: 110
      });
    }());
  </script>
  <script src="<?= export_html($assetBase . '/assets/app.js') ?>?v=<?= export_html(app_asset_version('assets/app.js')) ?>"></script>
</body>
</html>
