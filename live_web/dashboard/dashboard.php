<?php
/**
 * @doc-file
 * @doc-id live_web.dashboard.dashboard
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Dashboard-Route fuer die interne Live-Uebersicht mit Delegation in die private Anwendung.
 * @doc-entry HTTP-Aufruf auf /dashboard/dashboard.php.
 * @doc-input Bootstrap-Datei und private Zielroute.
 * @doc-output HTML des Dashboards.
 * @doc-side-effects Laedt Bootstrap und bindet private Zielseite ein.
 * @doc-source live_app/dashboard/dashboard.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';
app_require_private('dashboard/dashboard.php');
