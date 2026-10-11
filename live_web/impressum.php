<?php
/**
 * @doc-file
 * @doc-id live_web.impressum
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Impressumsroute, die die private Seitenimplementierung ausliefert.
 * @doc-entry HTTP-Aufruf auf /impressum.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output HTML-Seite mit rechtlichen Informationen.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/impressum.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
app_require_private('impressum.php');
