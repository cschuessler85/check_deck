# Deploy- und Testplan

## Ziel

Diese Anwendung soll auf dem Strato-Webspace so synchronisiert werden, dass `/home/www/testing` der öffentliche Webroot bleibt und die private Anwendung separat unter `/home/www/testing_app` liegt.

## Zielstruktur auf dem Server

Konkrete Strato-Zielpfade:

```text
/home/www/
├── testing/
│   ├── .htaccess
│   ├── _bootstrap.php
│   ├── index.php
│   ├── admin.php
│   ├── numbers.php
│   ├── timeframes.php
│   ├── export.php
│   ├── impressum.php
│   ├── assets/
│   ├── static/
│   └── dashboard/
│       ├── .htaccess
│       └── dashboard.php
└── testing_app/
    ├── config/
    │   ├── .env
    │   ├── .env.example
    │   └── http/
    │       └── .htpasswd
    ├── includes/
    ├── scripts/
    ├── storage/
    ├── vendor/
    ├── dashboard/
    ├── index.php
    ├── admin.php
    ├── numbers.php
    ├── timeframes.php
    ├── export.php
    ├── backup.php
    ├── diagnose.php
    ├── impressum.php
    └── setup.sql
```

## Vorbereitung

1. Aktuelle Serverkopie sichern.
2. Bestehende Datenbank exportieren.
3. Bestehende `.env`, `.htpasswd` und Webserver-Konfiguration sichern.
4. Prüfen, ob auf dem Server `php`, Datenbankzugang und Schreibrechte für `storage/` vorhanden sind.

## Sync-Ablauf

1. Inhalt von `public/` nach `/home/www/testing` synchronisieren.
2. Restliches Projekt nach `/home/www/testing_app` synchronisieren.
3. `config/.env.example` in `/home/www/testing_app/config/.env` kopieren und produktive Werte eintragen.
4. Prüfen, dass in `/home/www/testing/.htaccess` und `/home/www/testing/dashboard/.htaccess` der Pfad `/home/www/testing_app/config/http/.htpasswd` stimmt.
5. Rechte prüfen:
   - Webserver braucht Leserechte auf `/home/www/testing` und `/home/www/testing_app`.
   - `/home/www/testing_app/storage/backups` und `/home/www/testing_app/storage/logs` müssen beschreibbar sein.
   - `/home/www/testing_app/config/.env` und `/home/www/testing_app/config/http/.htpasswd` dürfen nicht öffentlich lesbar sein.

## Konfiguration

Empfohlene Werte in `/home/www/testing_app/config/.env`:

```dotenv
APP_ENV=production
APP_DEBUG=0
DB_HOST=127.0.0.1
DB_NAME=dlrg_schwimmen
DB_USER=dlrg_user
DB_PASS=...
ALLOW_SETUP_PASSWORD=0
ADMIN_SETUP_PASSWORD=
AUDIT_INCLUDE_REQUEST_HASHES=0
```

## Datenbank

1. Datenbank und DB-Benutzer bewusst anlegen.
2. Falls nötig `setup.sql` importieren.
3. Anwendung gegen die produktive DB verbinden.
4. Ersten Admin per CLI anlegen:

```bash
cd /home/www/testing_app
php scripts/create_admin.php <benutzername> <passwort> admin
```

## Funktionstests

### Basis

1. `https://deine-domain/` öffnet die Startseite.
2. CSS, JavaScript und Logos laden korrekt.
3. `https://deine-domain/admin.php` fordert Basic-Auth an.

### Registrierung

1. Testanmeldung mit neuer Person durchführen.
2. Erfolgsseite prüfen.
3. QR-Code prüfen.
4. Dublettenprüfung testen.

### Admin

1. Login mit dem per CLI angelegten Admin testen.
2. Teilnehmer per Code suchen.
3. Teilnehmer als bezahlt markieren.
4. Startnummer zuweisen.
5. Teamverwaltung und Benutzerverwaltung öffnen.
6. `timeframes.php` öffnen sowie Anlegen, Bearbeiten und JSON-Ausgabe testen.

### Startnummern

1. Reservierung eines Bereichs testen.
2. Zuweisung an Teilnehmer testen.
3. Freigabe testen.

### Dashboard

1. `https://deine-domain/dashboard/dashboard.php` testen.
2. Zugriffsschutz und Rollen prüfen.

### Export

1. CSV exportieren.
2. Prüfen, dass keine `ip_adresse` und kein `browser_agent` exportiert werden.

### CLI

1. Diagnose:

```bash
cd /home/www/testing_app
php scripts/diagnose.php
```

2. Backup:

```bash
cd /home/www/testing_app
php scripts/backup.php
```

3. Optional Datenschutzbericht:

```bash
cd /home/www/testing_app
php scripts/privacy_cleanup.php report
```

## Sicherheitschecks

Diese URLs dürfen auf `testing.corbinianschuessler.de` nicht erreichbar sein:

- `/config/.env`
- `/.env`
- `/backup.php`
- `/diagnose.php`
- `/scripts/create_admin.php`

## Abnahme

Ein Deployment gilt als sauber, wenn:

1. die Subdomain nur `/home/www/testing` ausliefert,
2. interne Seiten per Basic-Auth und App-Login geschützt sind,
3. Registrierung, Admin, Startnummern, Zeitfenster, Export und Dashboard funktionieren,
4. keine sensiblen Dateien aus `/home/www/testing_app` per HTTP erreichbar sind,
5. CLI-Skripte per SSH in `/home/www/testing_app` sauber laufen.
