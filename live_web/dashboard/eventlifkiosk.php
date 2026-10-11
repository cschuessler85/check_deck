<?php
/**
 * @doc-file
 * @doc-id live_web.dashboard.eventlifkiosk
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Dashboard-Kioskroute mit Delegation in die private Anwendung.
 * @doc-entry HTTP-Aufruf auf /dashboard/eventlifkiosk.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output HTML der Dashboard-Kioskansicht.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/dashboard/eventlifkiosk.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';
app_require_private('dashboard/eventlifkiosk.php');
