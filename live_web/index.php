<?php
/**
 * @doc-file
 * @doc-id live_web.index
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Startseiten-Route, die in die private Anwendungsimplementierung delegiert.
 * @doc-entry HTTP-Aufruf auf /index.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output HTML der Startseite.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/index.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
app_require_private('index.php');
