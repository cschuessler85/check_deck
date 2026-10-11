<?php
/**
 * @doc-file
 * @doc-id live_web.eventlifkiosk
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Route fuer die Event-Live-Kioskansicht mit Delegation in die private Anwendung.
 * @doc-entry HTTP-Aufruf auf /eventlifkiosk.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output HTML der Kioskansicht.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/eventlifkiosk.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
app_require_private('eventlifkiosk.php');
