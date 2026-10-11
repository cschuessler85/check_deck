<?php
/**
 * @doc-file
 * @doc-id live_web.export
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Export-Route, die in die private Exportlogik delegiert.
 * @doc-entry HTTP-Aufruf auf /export.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output Dateidownload oder Exportansicht.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/export.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
app_require_private('export.php');
