# Roadmap Restarbeiten

## Bereits umgesetzt

- Serverstruktur in `public/`, `config/`, `storage/`, `scripts/` getrennt
- sensible Dateien aus dem Webroot verlagert
- Setup-/Recovery-Login deutlich gehärtet
- Backup und Diagnose auf CLI umgestellt
- Export ohne `ip_adresse` und `browser_agent`
- XSS-relevante `innerHTML`-Verwendung im Frontend entfernt
- Audit-Logging standardmäßig ohne Request-Hashes
- Analytics-Fingerprint von IP/User-Agent auf Cookie-basierte Kennung umgestellt
- Setup-Schema, Laufzeit-Inserts und Privacy-Cleanup fuer alte Request-Metadaten bereinigt
- Admin-Sidebar in Mobilansicht bis einschließlich Sitzung in ein kompaktes Klappmenue ueberfuehrt
- Sitzungskarte im Admin-Frame mit gleich breiten Aktionsbuttons vereinheitlicht
- Mobile Admin-Navigation und Sidebar-Flow im Code maschinenlesbar dokumentiert
- Zeitfenster-Schema fuer Neuinstallationen und Laufzeitmigrationen ergänzt
- Audit-Logging fuer Teilnehmerdaten auf fachlich relevante Kernfelder reduziert
- Audit-AJAX-Endpunkte aus `admin.php` in ein separates Include ausgelagert

## Offene Punkte

### 1. SQL-Bereinigung auf Zielumgebung ausfuehren

Ziel:
- vorhandene Legacy-Spalten und Altwerte auf der produktiven Datenbank einmalig entfernen

Werkzeug:

```bash
php scripts/privacy_cleanup.php report
php scripts/privacy_cleanup.php cleanup
```

Hinweis:
- `cleanup` entfernt jetzt auch die alten Spalten aus `teilnehmer` und `audit_log`.
- Die Anwendungslogik erwartet diese Legacy-Spalten nach dem Cleanup nicht mehr.

### 2. Produktions-Webserver härten

Ziel:
- Apache-/Nginx-Konfiguration final ausarbeiten
- `.htaccess`-Regeln nach Möglichkeit in die echte Serverkonfiguration übernehmen

Empfehlung:
- `DocumentRoot` fest auf `public/`
- Zugriff auf Projektwurzel zusätzlich serverseitig blockieren
- TLS/HTTPS-Weiterleitung in der Serverkonfiguration fest verankern

### 3. Berechtigungen und Eigentümer

Ziel:
- saubere Dateirechte im Produktivbetrieb

Empfehlung:
- Projektdateien nur lesbar für den Webserver
- `storage/backups` und `storage/logs` gezielt beschreibbar
- `config/.env` und `.htpasswd` nur für Admin/Deploy-Benutzer lesbar

### 4. Logging und Monitoring

Ziel:
- Fehlerbetrieb besser nachvollziehbar machen, ohne sensible Daten zu leaken

Empfehlung:
- `storage/logs/php-error.log` beobachten
- Logrotation auf Serverebene einrichten
- Diagnose regelmäßig per CLI ausführen

### 5. Strukturarbeit in `admin.php`

Ziel:
- sehr große Datei in kleinere Einheiten zerlegen

Empfehlung:
- AJAX-Endpunkte separieren
- Benutzerverwaltung, Kasse, Audit, Teams und Teilnehmer in eigene Module teilen
- gemeinsame Response-Helfer und Validierung auslagern

### 6. Tests

Ziel:
- wiederholbare Prüfungen für Kernfunktionen

Empfehlung:
- Smoke-Test-Checkliste für Registrierung, Admin, Startnummern, Export und Dashboard
- später einfache automatisierte Tests für kritische Flows

## Empfohlene Reihenfolge

1. `privacy_cleanup.php report` auf der Zielumgebung ausführen
2. `privacy_cleanup.php cleanup` nach Backup einmalig ausführen
3. Deployment mit `DEPLOY-TEST-PLAN.md` durchführen
4. Live-Funktionstest abschließen
5. Webserver-Konfiguration final härten
6. `admin.php` schrittweise modularisieren
