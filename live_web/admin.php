<?php
/**
 * @doc-file
 * @doc-id live_web.admin
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Admin-Route, die an die private Admin-Implementierung weiterreicht.
 * @doc-entry HTTP-Aufruf auf /admin.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output Admin-Oberflaeche oder JSON-Antwort der Adminlogik.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/admin.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
app_require_private('admin.php');
