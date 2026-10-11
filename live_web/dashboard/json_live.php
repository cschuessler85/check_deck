<?php
/**
 * @doc-file
 * @doc-id live_web.dashboard.json_live
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche JSON-Live-Route fuer Dashboarddaten mit Delegation in die private Anwendung.
 * @doc-entry HTTP-Aufruf auf /dashboard/json_live.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output JSON-Live-Daten fuer Frontends.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/dashboard/json_live.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';
app_require_private('dashboard/json_live.php');
