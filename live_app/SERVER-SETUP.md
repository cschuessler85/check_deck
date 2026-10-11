# Server-Setup

## Zielstruktur

- `public/`: lokaler Quellordner fuer den oeffentlichen Webroot
- `config/`: sensible Konfiguration ausserhalb des Webroots
- `config/http/.htpasswd`: Basic-Auth-Datei fuer interne Bereiche
- `storage/backups/`: SQL-Backups
- `storage/logs/`: Anwendungs-Logs
- Projektwurzel: private PHP-Controller und Includes

## Strato-Zielpfade

- Webspace-Pfad: `/home/www`
- Test-Webroot: `/home/www/testing`
- Private App: `/home/www/testing_app`

### Sync-Regel

- Inhalt von `public/` nach `/home/www/testing`
- Restliches Projekt nach `/home/www/testing_app`
- Die Wrapper in `/home/www/testing` erkennen diese Struktur automatisch

## Webserver

- Bei Strato zeigt die Subdomain `testing.corbinianschuessler.de` auf `/home/www/testing`
- Die private Anwendung liegt in `/home/www/testing_app`
- `AuthUserFile` ist aktuell auf `/home/www/testing_app/config/http/.htpasswd` vorbereitet

## Ersteinrichtung

1. `config/.env.example` nach `config/.env` kopieren und Werte eintragen.
2. Falls noetig Composer-Abhaengigkeiten installieren.
3. Ersten Admin anlegen:

```bash
php scripts/create_admin.php <benutzername> <passwort> admin
```

## Wartung

- Backup erzeugen:

```bash
php scripts/backup.php
```

- Diagnose ausfuehren:

```bash
php scripts/diagnose.php
```

- Datenschutz-Status prüfen:

```bash
php scripts/privacy_cleanup.php report
```

- Historische Request-Metadaten bereinigen:

```bash
php scripts/privacy_cleanup.php cleanup
```

## Sicherheit

- `ALLOW_SETUP_PASSWORD` in Produktion auf `0` lassen.
- `APP_DEBUG` in Produktion auf `0` lassen.
- `AUDIT_INCLUDE_REQUEST_HASHES` in Produktion auf `0` lassen.
- `config/.env` und `config/http/.htpasswd` nie im Webroot ablegen.
