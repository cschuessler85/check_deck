# Audit-Bericht — 24h-Schwimmen Projekt

**Prüfungsumfang:** Gesamtes Projekt (ausgenommen `lane_counter/`)
**Prüfdatum:** 2026-09-23
**Kategorien:** Sicherheit (OWASP Top 10), Fehler/Logik, Kommentierung/Code-Qualität
**Schweregrade:** Kritisch · Hoch · Mittel · Niedrig · Info

---

## 1. Kritische Befunde

### C-01 | Datenbank-Zugangsdaten im Klartext committed
- **Datei:** [live_app/config/.env](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/config/.env)
- **OWASP:** A02 Cryptographic Failures / A05 Security Misconfiguration
- **Befund:** Die Datei enthält echte Produktionszugangsdaten (Host `database-5020550247.webspace-host.com`, DB `dbs15721476`, User `dbu4022132`, Passwort im Klartext). Obwohl `live_app/.gitignore` `/config/.env` ausschließt, ist die Datei dennoch im Repository.
- **Erklärung:** Jeder mit Repo-Zugriff erhält Vollzugriff auf die Produktionsdatenbank. Das Passwort muss sofort geändert werden.
- **Lösung:** (1) DB-Passwort umgehend ändern. (2) `.env` aus dem Git-Verlauf entfernen (`git rm --cached`, History-Rewrite mit `git filter-repo`). (3) `.gitignore` auf Root-Ebene ergänzen (siehe M-04). (4) Für Deployments Umgebungsvariablen oder eine `.env.example` mit Platzhaltern verwenden.
- **Kontext:** Kernkonfiguration — Zugriff auf alle Teilnehmerdaten, Benutzer und Audit-Logs.
- **Schweregrad:** Kritisch

### C-02 | Web-zugänglicher `.old/`-Ordner mit ~500 PDF-Urkunden und altem PHP-Code
- **Datei/Ordner:** [supply_mask/.old/](file:///d:/Coding/DLRG_24/24h-Schwimmen/supply_mask/.old)
- **OWASP:** A01 Broken Access Control / A05 Security Misconfiguration / DSGVO
- **Befund:** In `supply_mask/` existiert **kein** `.htaccess` auf Root-Ebene (nur in `supply_mask/go/`). Damit ist `supply_mask/.old/` direkt per HTTP erreichbar. Darin liegen:
  - `urkunden/` — ca. 500 PDF-Dateien mit echten Teilnehmernamen (z. B. `007_Bucher_Frank.pdf`)
  - `php/urkunde.php` — alte Version des Urkundendownloads, der per Nummer+Name PDFs ausliefert
  - `teilnehmer.csv`, `schwimmer-final.csv` — Rohdaten mit Namen
- **Erklärung:** Unbefugte können alle Urkunden und Teilnehmerdaten abrufen. Die alte `urkunde.php` erlaubt zudem das Durchsuchen von Nummern.
- **Lösung:** (1) `supply_mask/.old/` vollständig löschen oder außerhalb des Webroots verschieben. (2) Falls Aufbewahrung nötig: `.htaccess` mit `Require all denied` in `supply_mask/.old/` und auf `supply_mask/`-Root legen. (3) CSV-Dateien mit personenbezogenen Daten löschen oder schützen.
- **Kontext:** Datenschutzrechtlich höchst relevant — enthält vollständige Namen aller Teilnehmer.
- **Schweregrad:** Kritisch

### C-03 | Live-Session-Dateien und SQL-Backups committed
- **Ordner:** [live_app/storage/sessions/](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/storage/sessions), [live_app/storage/backups/](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/storage/backups)
- **OWASP:** A07 Identification & Authentication Failures / A02 Cryptographic Failures
- **Befund:** Ca. 25 aktive Session-Dateien (`sess_*`) und 3 SQL-Dumps (`dbs15721476_*.sql`, `dbs15778314_*.sql`) sind im Repository. Die SQL-Dumps enthalten die `users`-Tabelle mit Passwort-Hashes und alle Teilnehmerdaten.
- **Erklärung:** Session-Dateien könnten für Session-Hijacking genutzt werden (wenn die Session-ID bekannt und gültig ist). SQL-Dumps erlauben Offline-Brute-Force auf Passwort-Hashes.
- **Lösung:** (1) `storage/sessions/` und `storage/backups/*.sql` aus Git entfernen (`git rm -r --cached`). (2) `.gitignore` ergänzen: `/storage/sessions/`, `/storage/backups/`, `/storage/logs/`. (3) Sessions auf dem Server invalidieren. (4) Passwörter aller Benutzer zurücksetzen.
- **Kontext:** Laufzeitdaten gehören nicht ins Repository.
- **Schweregrad:** Kritisch

### C-04 | Unbefugter Datei-Upload + Remote Code Execution in Beispiel-Datei
- **Datei:** [live_app/php_schwimmer_merge_example.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/php_schwimmer_merge_example.php)
- **OWASP:** A03 Injection (OS Command) / A05 Security Misconfiguration
- **Befund:** Die Datei erlaubt ohne Authentifizierung das Hochladen von CSV- und JSON-Dateien und führt anschließend ein Python-Skript via `shell_exec()` aus. Hochgeladene Dateien landen in `live_app/tmp_uploads/` und die Ergebnisdatei wird zum Download angeboten. Obwohl `escapeshellarg()`/`escapeshellcmd()` verwendet werden (Befehlsinjektion somit erschwert), besteht ein Risiko durch: (a) beliebige Datei-Uploads, (b) potenzielle Python-Seiteneffekte, (c) die Datei hat keine Auth-Prüfung.
- **Erklärung:** Aktuell ist `live_app/` per `.htaccess` (`Require all denied`) geschützt, sodass die Datei nicht direkt erreichbar ist. Falls diese Schutzschicht jedoch jemals versagt oder die Datei in `live_web/` gespiegelt wird, besteht ein RCE-Risiko.
- **Lösung:** (1) Datei aus Produktions-Deployment entfernen oder in einen `examples/`-Ordner außerhalb des Webroots legen. (2) Falls benötigt: Auth-Check (`auth_can`) + CSRF + Dateityp-Validierung + Größe-Limit + sichere `tmp_uploads`-Konfiguration ergänzen.
- **Kontext:** Entwicklungsbeispiel, das nicht in Produktion gehört.
- **Schweregrad:** Kritisch (bei fehlendem Deny-All) / Hoch (aktuell durch `.htaccess` abgesichert)

---

## 2. Hohe Befunde

### H-01 | Kein Rate-Limiting bei Login (Brute-Force-Schwachstelle)
- **Datei:** [live_app/includes/functions.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/includes/functions.php#L397) (`auth_login_with_credentials`)
- **OWASP:** A07 Identification & Authentication Failures
- **Befund:** Die Login-Funktion hat keine Begrenzung der Anmeldeversuche, keinen Timeout und kein CAPTCHA. `session_regenerate_id(true)` wird nur bei erfolgreichem Login ausgeführt.
- **Erklärung:** Angreifer können Passwörter in unbegrenzter Anzahl ausprobieren.
- **Lösung:** (1) Anmeldesperre nach N Fehlversuchen pro Benutzername und/oder IP (z. B. 5 Versuche → 15 min Sperre). (2) Progressives Delay (`sleep()`) bei Fehlversuchen. (3) Optional: CAPTCHA nach mehreren Fehlversuchen. (4) Fehlversuche im Audit-Log protokollieren.
- **Kontext:** `admin.php` Login-Routine.
- **Schweregrad:** Hoch

### H-02 | Keine Passwort-Komplexitätsregeln bei Benutzeranlage und Passwortwechsel
- **Datei:** [live_app/admin.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/admin.php#L679) (User-Management)
- **OWASP:** A07 Identification & Authentication Failures
- **Befund:** Beim Anlegen von Benutzern (`create_user`) und beim Passwortwechsel (`update_password`) wird nur geprüft, dass das Passwort nicht leer ist. Keine Mindestlänge, keine Komplexitätsanforderungen.
- **Erklärung:** Schwache Passwörter wie `123456` oder `a` werden akzeptiert.
- **Lösung:** (1) Mindestlänge von 12 Zeichen (oder 8 + Komplexität). (2) Optional: Prüfung gegen gängige Passwort-Listen. (3) Bei Profil-Passwortwechsel zusätzlich das alte Passwort abfragen.
- **Kontext:** User-Verwaltung im Admin-Bereich.
- **Schweregrad:** Hoch

### H-03 | Dashboard ohne Authentifizierungsprüfung (Informationspreisgabe)
- **Datei:** [live_app/dashboard/dashboard.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/dashboard/dashboard.php)
- **OWASP:** A01 Broken Access Control
- **Befund:** `dashboard.php` prüft nicht, ob ein Benutzer angemeldet ist. Es werden `auth_can()` nur für bedingte Anzeigen verwendet, aber die Hauptabfrage auf `teilnehmer` läuft unbefristet. Unbefugte sehen aggregierte Statistiken: Gesamtanzahl Teilnehmer, Kinder/Erwachsene, bezahlt/offen, Team-Verteilung, letzte Anmeldungen.
- **Erklärung:** Die Seite ist über `live_web/dashboard/dashboard.php` erreichbar. Auch wenn keine Einzeldaten gezeigt werden, sind die aggregierten Zahlen sensibel.
- **Lösung:** Am Anfang nach `require` einfügen: `if (!auth_can($pdo, 'participants.read')) { http_response_code(403); echo 'Zugriff verweigert.'; exit; }`
- **Kontext:** Interne Übersichtsseite.
- **Schweregrad:** Hoch

### H-04 | Debug-Code sendet Daten an lokalen Server (SSRF/Info-Leak)
- **Datei:** [live_app/dashboard/event_live.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/dashboard/event_live.php#L34)
- **OWASP:** A05 Security Misconfiguration / A10 SSRF
- **Befund:** Bei `?format=json` wird ein Debug-POST an `http://127.0.0.1:7777/event` gesendet (URL aus `.dbg/kiosk-no-data.env`). Der Payload enthält Live-Daten (registered, active, laps_total, error). Die Anfrage erfolgt mit `@file_get_contents()` (Fehler werden unterdrückt).
- **Erklärung:** Produktionscode sollte keine Debug-Aufrufe enthalten. Wenn der Debug-Server nicht läuft, entstehen nutzlose Requests; wenn er läuft, werden Daten nach außen (bzw. an einen lokalen Prozess) geleakt.
- **Lösung:** Debug-Code-Block (Zeilen 34–51) vollständig entfernen oder durch ein `if (APP_DEBUG)` guardieren.
- **Kontext:** Live-Event-Anzeige.
- **Schweregrad:** Hoch

### H-05 | `.htpasswd` bcrypt-Kostenfaktor zu niedrig
- **Datei:** [live_app/config/http/.htpasswd](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/config/http/.htpasswd)
- **OWASP:** A02 Cryptographic Failures
- **Befund:** Der Hash für Benutzer `dlrg_admin` verwendet bcrypt mit Kostenfaktor 5 (`$2y$05$...`). Moderne Empfehlung liegt bei 10–12.
- **Erklärung:** Kostenfaktor 5 erlaubt sehr schnelle Offline-Brute-Force-Angriffe.
- **Lösung:** Hash mit `htpasswd -B -C 12` neu erzeugen. Benutzer `dlrg_admin` ist kein DB-Benutzer, sondern für optionales HTTP Basic Auth.
- **Kontext:** Optionale zusätzliche Absicherung von Admin-Bereichen.
- **Schweregrad:** Hoch

### H-06 | Echt-Daten in SQLite, JSON und CSV committed
- **Dateien:** [data.sqlite](file:///d:/Coding/DLRG_24/24h-Schwimmen/data.sqlite), [data_test_sync.sqlite](file:///d:/Coding/DLRG_24/24h-Schwimmen/data_test_sync.sqlite), [24hschwimmen.json](file:///d:/Coding/DLRG_24/24h-Schwimmen/24hschwimmen.json), `supply_mask/schwimmer-final.csv`, `supply_mask/teilnehmer.csv`
- **OWASP:** A01 Broken Access Control / DSGVO
- **Befund:** Diese Dateien enthalten echte Teilnehmerdaten (Namen, Bahnenzahlen, aktive Zustände).
- **Lösung:** Aus Git entfernen und durch anonymisierte Beispieldaten ersetzen. `.gitignore` ergänzen: `*.sqlite`, `*.json` (nur für Daten-JSONs), `*.csv`.
- **Kontext:** Laufzeit-/Beispieldaten.
- **Schweregrad:** Hoch

---

## 3. Mittlere Befunde

### M-01 | Duplikat-Datei `.json_live.php` mit Mojibake-Encoding
- **Datei:** [live_app/dashboard/.json_live.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/dashboard/.json_live.php)
- **OWASP:** A05 Security Misconfiguration
- **Befund:** Eine zweite Version von `json_live.php` mit führendem Punkt im Dateinamen. Die Datei hat kaputte UTF-8-Kodierung (`Ü` → `Ãœ`, `—` → `â€”`, `ß` → `ÃŸ`). Sie nutzt `json_live_load_overview()` statt `live_load_overview()`.
- **Erklärung:** Durch `live_app/.htaccess` (`Require all denied`) geschützt, aber die Datei sollte nicht im Repo sein. Die führende Punkt-Datei ist zudem potenziell web-accessible, falls die Deny-All-Regel nicht greift.
- **Lösung:** Datei löschen. Die produktive `json_live.php` ist die korrekte Version.
- **Kontext:** Alte/Backup-Datei.
- **Schweregrad:** Mittel

### M-02 | Duplikat-Datei `.eventlifkiosk.php` in `live_web/`
- **Datei:** [live_web/.eventlifkiosk.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_web/.eventlifkiosk.php)
- **Befund:** Identisch mit `live_web/eventlifkiosk.php`, aber mit führendem Punkt. Durch `live_web/.htaccess` (dotfile-Block) geschützt.
- **Lösung:** Löschen.
- **Schweregrad:** Mittel

### M-03 | Fehlendes Root-`.gitignore`
- **Befund:** Es gibt nur `live_app/.gitignore`, keines auf Projektebene. Dadurch werden `*.sqlite`, `24hschwimmen.json`, `supply_mask/.old/`, `data.sqlite` usw. nicht ignoriert.
- **Lösung:** `/.gitignore` auf Root-Ebene mit allen relevanten Mustern anlegen: `*.sqlite`, `*.sqlite-journal`, `*.log`, `/storage/`, `*.sql` (Backup-Dumps), `.env`, `tmp_uploads/`, `supply_mask/.old/`, `__pycache__/`, `*.pyc`.
- **Schweregrad:** Mittel

### M-04 | `numbers.php` — gemischtes Escaping im `onclick`-Attribut
- **Datei:** [live_app/numbers.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/numbers.php#L661)
- **OWASP:** A03 Injection (XSS)
- **Befund:** `onclick="openNumModal(..., ' . escape(addslashes($info)) . ')"`. `$info` enthält Benutzernamen oder Teamnamen. Die Kombination `escape(addslashes())` ist funktional sicher, aber unübersichtlich und fehleranfällig.
- **Erklärung:** `addslashes` escapen `'`, `"`, `\`; `escape()` (htmlspecialchars mit ENT_QUOTES) encodiert `<`, `>`, `&`, `"`, `'`. Zusammen verhindern sie das Ausbrechen aus Attribut und JS-String. Es ist jedoch nicht die empfohlene Methode.
- **Lösung:** Für JS-Kontext `json_encode($info, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)` verwenden und in single quotes einbetten. Besser noch: Daten per `data-*`-Attribute übergeben und Event-Listener im JS auswerten.
- **Kontext:** Startnummern-Übersicht.
- **Schweregrad:** Mittel (kein aktives XSS, aber fragile Codierung)

### M-05 | Logout per GET-Request (CSRF auf Logout)
- **Datei:** [live_app/admin.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/admin.php#L18)
- **OWASP:** A01 Broken Access Control
- **Befund:** Logout erfolgt über `?action=logout` (GET). Ein Angreifer kann einen Benutzer per `<img src="admin.php?action=logout">` ausloggen.
- **Lösung:** Logout auf POST umstellen mit CSRF-Token.
- **Kontext:** Admin-Logout.
- **Schweregrad:** Mittel

### M-06 | `merge_schwimmerdaten.py` — Mojibake in Spaltennamen
- **Datei:** [live_web/merge_schwimmerdaten.py](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_web/merge_schwimmerdaten.py#L34)
- **Befund:** Spaltennamen wie `Frühaufsteher` sind als `FrÃ¼haufsteher` gespeichert. Betrifft `EXPECTED_COLUMNS` und `NUMERIC_COLUMNS`.
- **Erklärung:** Wenn die CSV-Datei korrekt UTF-8-kodierte Spaltennamen hat, schlägt die Zuordnung fehl und die Spalte wird ignoriert.
- **Lösung:** Datei als UTF-8 speichern und die Mojibake-Zeichen durch korrekte Umlaute ersetzen.
- **Schweregrad:** Mittel (funktionaler Fehler)

### M-07 | `supply_mask/go/urkunde.php` — kein `@doc-file`-Modulkopf
- **Datei:** [supply_mask/go/urkunde.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/supply_mask/go/urkunde.php)
- **Befund:** Die Datei beginnt direkt mit `<?php declare(strict_types=1);` ohne den vorgeschriebenen `@doc-file`-Kopf gemäß `COMMENTING-STANDARD.md`.
- **Lösung:** `@doc-file`-Block mit `@doc-id`, `@doc-kind`, `@doc-purpose`, `@doc-entry`, `@doc-input`, `@doc-output`, `@doc-side-effects` ergänzen.
- **Schweregrad:** Mittel (Kommentierungsstandard)

### M-08 | `event_live.php` liest Debug-Env-Datei
- **Datei:** [live_app/dashboard/event_live.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/dashboard/event_live.php#L36)
- **Befund:** Liest `.dbg/kiosk-no-data.env` und überschreibt Debug-URL/Session. Diese Datei ist im Repo enthalten.
- **Lösung:** Debug-Code entfernen (siehe H-04), Datei `.dbg/` löschen oder in `.gitignore` aufnehmen.
- **Schweregrad:** Mittel

---

## 4. Niedrige Befunde

### L-01 | Kein `max_input_vars`/Upload-Größenlimit in Beispiel-Datei
- **Datei:** `live_app/php_schwimmer_merge_example.php`
- **Befund:** Uploads ohne Größen- oder Typenlimit.
- **Lösung:** Siehe C-04 (Datei entfernen oder härten).
- **Schweregrad:** Niedrig (wird durch C-04 abgedeckt)

### L-02 | `admin.php` — `display_errors`-Verhalten im Fehlerfall
- **Befund:** Einige `catch`-Blöcke geben generische Fehlermeldungen aus (gut), aber `dashboard.php` nutzt `die()` im Fehlerfall, was Stack-Trace-Ähnliches offenbaren könnte.
- **Lösung:** Konsistent generische Fehlermeldungen verwenden; Details nur im Server-Log.
- **Schweregrad:** Niedrig

### L-03 | `supply_mask/.old/php/urkunde.php` — alte Version ohne Kommentierung
- **Befund:** Alte Datei mit teilweise deutschen Inline-Kommentaren, aber ohne `@doc-file`-Standard.
- **Lösung:** Siehe C-02 (Ordner löschen).
- **Schweregrad:** Niedrig (wird durch C-02 abgedeckt)

### L-04 | Doppelter `escapeHtml` in `app.js`
- **Datei:** [live_app/assets/app.js](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/assets/app.js)
- **Befund:** Die Funktion `escapeHtml` ist zweimal definiert (Zeilen 1078 und 1399) in unterschiedlichen Scopes. Funktional unproblematisch, aber Redundanz.
- **Lösung:** In eine gemeinsame Hilfsfunktion auslagern.
- **Schweregrad:** Niedrig

---

## 5. Positive Feststellungen (gute Praktiken)

- **Prepared Statements durchgängig:** Alle SQL-Abfragen nutzen PDO-Prepared-Statements mit `ATTR_EMULATE_PREPARES => false`. Keine direkte `$_GET/$_POST`-Interpolation gefunden.
- **CSRF-Schutz:** Alle statusändernden POST-Aktionen prüfen `csrf_token_verify()` mit `hash_equals()`.
- **Session-Härtung:** `httponly=true`, `samesite='Strict'`, `secure` (HTTPS-abhängig), `session_regenerate_id(true)` bei Login.
- **Rollenbasierte Zugriffskontrolle:** `auth_can()` / `auth_require_permission_or_403_json()` an allen relevanten Endpunkten.
- **Passwort-Hashing:** `password_hash(PASSWORD_DEFAULT)` / `password_verify()`.
- **Ausgabe-Escaping:** `escape()` (htmlspecialchars) für HTML, `escapeHtml()` in JS.
- **CSV-Injection-Schutz:** `export_csv_safe` präfixt `=+-@` mit `'`.
- **Registrierungs-Codes:** Luhn-mod-N-Prüfziffer, `random_bytes()` für Zufall.
- **CLI-only-Skripte:** `backup.php`, `diagnose.php`, `create_admin.php`, `privacy_cleanup.php` prüfen `PHP_SAPI !== 'cli'`.
- **Export-Whitelist:** Spalten, Sortierfelder und Filter werden gegen definierte Schemata validiert.
- **Audit-Log:** Alle Benutzeraktionen werden protokolliert; nur Admin kann Audit-Log einsehen.
- **Kommentierungsstandard:** Der Großteil der PHP-Dateien hat den `@doc-file`-Modulkopf und `@doc-section`-Marker gemäß `COMMENTING-STANDARD.md`.

---

## 6. OWASP-Top-10-Zuordnung

| OWASP-Kategorie | Befunde |
|---|---|
| A01 Broken Access Control | C-02, C-03, H-03, M-05 |
| A02 Cryptographic Failures | C-01, C-03, H-05 |
| A03 Injection | C-04, M-04 |
| A04 Insecure Design | H-01, H-02 |
| A05 Security Misconfiguration | C-02, C-04, H-04, M-01, M-02, M-08 |
| A06 Vulnerable Components | — (FPDF/Bibliotheken nicht auf Version geprüft) |
| A07 ID & Auth Failures | C-03, H-01, H-02, H-05 |
| A08 Software & Data Integrity | C-03, H-06 |
| A09 Logging & Monitoring | — (Audit-Log vorhanden, aber kein Alerting) |
| A10 SSRF | H-04 |

---

## 7. Priorisierte Handlungsempfehlung

1. **Sofort (Kritisch):** DB-Passwort ändern (C-01), `supply_mask/.old/` schützen/löschen (C-02), Session-Dateien und SQL-Dumps aus Git entfernen (C-03), Beispiel-Datei entfernen (C-04).
2. **Kurzfristig (Hoch):** Rate-Limiting (H-01), Passwortregeln (H-02), Dashboard-Auth (H-03), Debug-Code entfernen (H-04), htpasswd neu hashen (H-05), Echt-Daten aus Git (H-06).
3. **Mittelfristig (Mittel):** Duplikate löschen (M-01, M-02), Root-`.gitignore` (M-03), Escaping-Normalisierung (M-04), Logout-POST (M-05), Python-Encoding (M-06), Kommentierung (M-07, M-08).

---

*Hinweis: Der Ordner `lane_counter/` wurde gemäß Vorgabe nicht geprüft.*
