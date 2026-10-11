<?php
/**
 * @doc-file
 * @doc-id live_app.scripts.diagnose
 * @doc-kind script
 * @doc-purpose Prueft Konfiguration, Datenbank und Laufzeitbedingungen ausserhalb der Weboberflaeche.
 * @doc-entry CLI-Aufruf durch Betrieb oder Entwickler.
 * @doc-input Umgebung, Datenbank und Dateisystem.
 * @doc-output Statusausgaben fuer Diagnosezwecke.
 * @doc-side-effects Liest System- und Konfigurationszustand.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/diagnose.php';
