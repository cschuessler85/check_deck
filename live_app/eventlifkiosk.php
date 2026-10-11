<?php
/**
 * @doc-file
 * @doc-id live_app.eventlifkiosk
 * @doc-kind page-controller
 * @doc-purpose Stellt die grosse Event-Kioskansicht fuer Live-Informationen und Publikumsausgabe bereit.
 * @doc-entry HTTP-Aufruf fuer die Kioskansicht.
 * @doc-input Live-Datenquellen, Konfiguration und Anzeigeparameter.
 * @doc-output HTML fuer Vollbild- oder Kioskbetrieb.
 * @doc-side-effects Liest Live-Daten und rendert eine lesbare Grossanzeige.
 */
declare(strict_types=1);

require __DIR__ . '/dashboard/event_live.php';
