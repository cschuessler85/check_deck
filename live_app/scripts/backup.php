<?php
/**
 * @doc-file
 * @doc-id live_app.scripts.backup
 * @doc-kind script
 * @doc-purpose Fuehrt Backup-Aufgaben ausserhalb der Weboberflaeche aus.
 * @doc-entry CLI- oder Scheduler-Aufruf.
 * @doc-input Dateisystem, Datenbank und Konfiguration.
 * @doc-output Statusausgaben und erzeugte Sicherungsdateien.
 * @doc-side-effects Schreibt Backup-Dateien und greift auf Datenquellen zu.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/backup.php';
