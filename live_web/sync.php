<?php
/**
 * @doc-file
 * @doc-id live_web.sync
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Sync-Route, die in die private Sync-Logik delegiert.
 * @doc-entry HTTP-Aufruf auf /sync.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output Vorschau oder Sync-Bericht.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/sync.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
app_require_private('sync.php');
