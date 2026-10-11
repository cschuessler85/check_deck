<?php
/**
 * @doc-file
 * @doc-id live_app.dashboard.eventlifkiosk
 * @doc-kind page-controller
 * @doc-purpose Rendert die Dashboard-Kioskansicht fuer grossflaechige Live-Anzeige im internen Betrieb.
 * @doc-entry HTTP-Aufruf der Dashboard-Kioskseite.
 * @doc-input Live-Datenquellen und Anzeigeparameter.
 * @doc-output HTML-Kioskansicht.
 * @doc-side-effects Liest Live-Daten und erzeugt eine grossformatige Anzeige.
 */
declare(strict_types=1);

require __DIR__ . '/event_live.php';
