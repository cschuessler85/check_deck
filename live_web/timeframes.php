<?php
/**
 * @doc-file
 * @doc-id live_web.timeframes
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Route fuer die Pflege von Zeitfenstern mit Delegation in die private Anwendung.
 * @doc-entry HTTP-Aufruf auf /timeframes.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output HTML oder Aktionsantworten fuer Zeitfenster.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/timeframes.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
app_require_private('timeframes.php');
