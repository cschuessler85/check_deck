# Technische Softwaredokumentation

## Modul: `tools.build_docs` (cli-script)
**Datei:** `gem.py`  
**Zweck:** Neuen Teilnehmer ueber die Schnellmaske erfassen.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI-Aufruf ueber Python (python build_docs.py <projekt_pfad>) |
| **Eingaben (`@doc-input`)** | Quellcodedateien im Projektverzeichnis mit @doc-Kommentarbloecken |
| **Ausgaben (`@doc-output`)** | SOFTWARE-DOKU.md und BEDIENUNGSANLEITUNG.md |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Projektdateien rekursiv und schreibt zwei Markdown-Dateien ins Arbeitsverzeichnis |

### Funktionen
#### `tools.build_docs.is_excluded`
Prueft, ob eine Datei gemaess Kommentarstandard ignoriert werden muss.

**Parameter:**
- `Path file_path Zu pruefender Dateipfad.`

**Rückgabe:** `bool True, wenn die Datei ausgeschlossen ist (z.B. vendor oder *.min.js).`

#### `tools.build_docs.extract_doc_blocks`
Liest eine Datei zeilenweise und gruppiert zusammenhaengende @doc-Zeilen zu Dokuobjekten.

**Parameter:**
- `Path file_path Pfad zur Quellcodedatei.`

**Rückgabe:** `list Liste aller extrahierten Doku-Bloecke als Dictionaries.`

#### `tools.build_docs.parse_project`
Durchlaeuft das Projektverzeichnis und ordnet Sektionen, UI-Flows und Funktionen ihren Modulen zu.

**Parameter:**
- `Path root_dir Wurzelverzeichnis des Projekts.`

**Rückgabe:** `list Strukturierte Liste aller Module inklusive Unterabschnitten.`

#### `tools.build_docs.generate_software_docs`
Erzeugt den Markdown-Inhalt fuer die technische Softwaredokumentation.

**Parameter:**
- `list modules Liste der geparsten Modul-Dictionaries.`

**Rückgabe:** `str Fertig formatierter Markdown-String fuer SOFTWARE-DOKU.md.`

#### `tools.build_docs.generate_user_manual`
Erzeugt eine Click-to-Click-Bedienungsanleitung aus allen @doc-ui-flow-Bloecken.

**Parameter:**
- `list modules Liste der geparsten Modul-Dictionaries.`

**Rückgabe:** `str Fertig formatierter Markdown-String fuer BEDIENUNGSANLEITUNG.md.`

---

## Modul: `live_app.admin` (page-controller)
**Datei:** `live_app\admin.php`  
**Zweck:** Steuert die interne Admin-Oberflaeche, Formularaktionen und AJAX-Endpunkte fuer Teilnehmer, Teams und Auditdaten.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch berechtigte Benutzer oder AJAX-Clients. |
| **Eingaben (`@doc-input`)** | Session, Berechtigungen, Formulareingaben, CSRF-Token und Datenbank. |
| **Ausgaben (`@doc-output`)** | HTML der Admin-Seite oder JSON-Antworten fuer Einzelaktionen. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest und schreibt Datenbankeintraege, Sessionstatus und HTTP-Antworten. |

### Logikabschnitte & Abläufe
#### Abschnitt `logout-and-entry`
- **Zweck:** Behandelt fruehe Admin-Einstiege wie Logout, bevor weitere Controller-Logik ausgefuehrt wird.

#### Abschnitt `login-processing`
- **Zweck:** Prueft Login-Versuche fuer reguläre Benutzer und optionale Recovery-Pfade.

#### Abschnitt `webauthn-login`
- **Zweck:** Biometrische Anmeldung per WebAuthn (Fingerabdruck/Gesicht).

#### Abschnitt `ajax-endpoints`
- **Zweck:** Bedient alle JSON-Endpunkte fuer Suche, Teilnehmerpflege, Zahlstatus, Teams und Audit-Log.
- **Auslöser:** GET ajax_action oder POST ajax_action
- **Ausgabe:** JSON-Antworten fuer die Admin-Oberflaeche

#### Abschnitt `webauthn-registration`
- **Zweck:** Registrierung eines biometrischen Schlüssels für den aktuell eingeloggten Benutzer.

#### Abschnitt `user-management-actions`
- **Zweck:** Verarbeitet Benutzerverwaltung wie Anlegen, Rollenwechsel, Passwortwechsel und Loeschen.

#### Abschnitt `token-actions`
- **Zweck:** Generiert Zugriffstokens für autorisierte Benutzer (Admin). Das Klartext-Token wird nur einmal angezeigt.

#### Abschnitt `profile-actions`
- **Zweck:** Erlaubt dem aktuell angemeldeten Benutzer die Pflege des eigenen Passworts.

#### Abschnitt `profile-update`
- **Zweck:** Erlaubt dem Benutzer die Aenderung des eigenen Benutzernamens und (mit Berechtigung) der eigenen Rolle.

#### Abschnitt `team-actions`
- **Zweck:** Verarbeitet klassische Formularaktionen rund um Teamanlage und Teambearbeitung ausserhalb der AJAX-Modale.

#### Abschnitt `page-data-loading`
- **Zweck:** Laedt nach Abschluss aller Schreiboperationen die Datenbasis fuer die HTML-Ansicht.

#### Abschnitt `admin-page-rendering`
- **Zweck:** Rendert die komplette Admin-Oberflaeche inklusive Teilnehmerliste, Teams, Benutzerverwaltung und Audit-Log.

#### Abschnitt `admin-shell`
- **Zweck:** Rendert den oberen Admin-Rahmen mit Session-Info, Schnellzugriffen, Suche und Bereichsnavigation.

#### Abschnitt `participant-list -->`
- **Zweck:** Zeigt alle Teilnehmer mit Such-, Zahlungs- und Bearbeitungsaktionen in einer kompakten Tabelle. -->

#### Abschnitt `cashier-client`
- **Zweck:** Steuert Kasseninteraktion, QR-Scan, Suchtrefferaufbau und Zahlungsaktionen im eingebetteten Admin-Skript.

#### Abschnitt `settings-client`
- **Zweck:** Verwaltet Designeinstellungen, Sprache, Veranstaltungsart und App-Reset.

#### Abschnitt `viewframe-scroll-top`
- **Zweck:** Positioniert den Viewframe (Inhaltsbereich) nach jedem Laden und Navigationsschritt exakt am oberen Viewport-Rand. Sticky-Logo-Höhe wird berücksichtigt.

#### Abschnitt `webauthn-client`
- **Zweck:** Steuert die biometrische Anmeldung und Registrierung per WebAuthn.

---

## Modul: `live_web.assets.app` (frontend-controller)
**Datei:** `live_app\assets\app.js`  
**Zweck:** Initialisiert globale UI-Helfer, Modale, Tabellenwerkzeuge und AJAX-Aktionen der Weboberflaeche.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Browser laedt die zentrale Frontend-Datei. |
| **Eingaben (`@doc-input`)** | DOM, Fetch-Endpunkte, LocalStorage und CSS-Klassen. |
| **Ausgaben (`@doc-output`)** | Interaktive Bedienlogik fuer Admin- und Dashboard-Seiten. |
| **Seiteneffekte (`@doc-side-effects`)** | Veraendert DOM, sendet HTTP-Requests und speichert UI-Zustand im Browser. |

### Logikabschnitte & Abläufe
#### Abschnitt `scroll-to-top`
- **Zweck:** Setzt beim Laden jeder Seite die Scrollposition auf den oberen Viewport-Rand.

#### Abschnitt `alerts`
- **Zweck:** Steuert das automatische Ausblenden neuer und bestehender Statusmeldungen.

#### Abschnitt `display-mode`
- **Zweck:** Erkennt die verfuegbare Anzeigebreite und markiert die Seite explizit als Mobil- oder Desktopmodus.

#### Abschnitt `registration-autoscroll`
- **Zweck:** Scrollt nach dem Laden sanft bis knapp unter das DLRG-Logo, falls der eigentliche Seiteninhalt sonst noch verdeckt waere.

#### Abschnitt `team-selection`
- **Zweck:** Steuert die Umschaltung zwischen bestehender Teamauswahl und Eingabe eines neuen Teams.

#### Abschnitt `modal-base`
- **Zweck:** Kapselt das generische Oeffnen und Schliessen von Modalen inklusive Fokusfuehrung.

#### Abschnitt `participant-edit`
- **Zweck:** Oeffnet die Bearbeitung eines Teilnehmers, befuellt das Formular und uebernimmt Aenderungen per AJAX.

#### Abschnitt `participant-delete`
- **Zweck:** Loescht einen Teilnehmer nach Rueckfrage und aktualisiert die Liste direkt im Browser.

#### Abschnitt `table-state-storage`
- **Zweck:** Verwaltet den gespeicherten Tabellenzustand fuer Sichtbarkeit und stabile Identifikation pro Tabelle.

#### Abschnitt `data-table-enhancement`
- **Zweck:** Erweitert Tabellen um Filter, Sortierung, Spaltenauswahl und Drag-and-drop.
- **Seiteneffekte:** Liest und schreibt Tabellenzustand in LocalStorage und veraendert DOM-Struktur.

#### Abschnitt `admin-navigation`
- **Zweck:** Schaltet zwischen Admin-Bereichen um, spiegelt den aktiven Abschnitt im URL-Hash und fuellt die Sidebar-Sprungmarken.

#### Abschnitt `team-management`
- **Zweck:** Verwaltet Teammitglieder in einem Modal und verbindet Suche, Zuordnung und Entfernen per AJAX.

#### Abschnitt `audit-log`
- **Zweck:** Laedt, filtert, erweitert und loescht Audit-Eintraege in der Admin-Oberflaeche.

---

## Modul: `live_web.assets.style` (style)
**Datei:** `live_app\assets\style.css`  
**Zweck:** Definiert das visuelle Grundlayout der oeffentlichen und internen Weboberflaeche.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Browser laedt das zentrale Stylesheet. |
| **Eingaben (`@doc-input`)** | HTML-Struktur, CSS-Variablen und Komponentenklassen. |
| **Ausgaben (`@doc-output`)** | Gestylte Layouts, Formulare, Karten und Tabellen. |
| **Seiteneffekte (`@doc-side-effects`)** | Beeinflusst Darstellung, Responsivitaet und visuelle Priorisierung. |

### Logikabschnitte & Abläufe
#### Abschnitt `admin-shell-layout */`
- **Zweck:** Strukturiert den oberen Admin-Rahmen in klar getrennte Bereiche fuer Status, Suche und Navigation. */

#### Abschnitt `participant-table-actions */`
- **Zweck:** Gestaltet die Sticky-Aktionsspalte der Teilnehmer-Tabelle passend zum restlichen Karten- und Tabellenstil. */

---

## Modul: `live_app.backup` (page-controller)
**Datei:** `live_app\backup.php`  
**Zweck:** Bietet die Benutzeroberflaeche oder Ausfuehrung fuer Backup-Funktionen der Anwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch berechtigte Benutzer. |
| **Eingaben (`@doc-input`)** | Session, Berechtigungen, Dateisystem und Datenbank. |
| **Ausgaben (`@doc-output`)** | HTML oder Statusantworten fuer Backups. |
| **Seiteneffekte (`@doc-side-effects`)** | Kann Sicherungen erzeugen und Dateien schreiben. |

---

## Modul: `live_app.dashboard.dashboard` (page-controller)
**Datei:** `live_app\dashboard\dashboard.php`  
**Zweck:** Erzeugt die kompakte interne Dashboard-Uebersicht mit Kennzahlen und Schnellstatus.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf der Dashboard-Seite. |
| **Eingaben (`@doc-input`)** | Datenbank, Berechtigungen und Hilfsfunktionen. |
| **Ausgaben (`@doc-output`)** | HTML-Dashboard mit Kennzahlen. |
| **Seiteneffekte (`@doc-side-effects`)** | Fuehrt Statistikabfragen aus und rendert eine Live-Uebersicht. |

### Logikabschnitte & Abläufe
#### Abschnitt `access-control`
- **Zweck:** Das Dashboard zeigt aggregierte Teilnehmerstatistiken und ist nur fuer angemeldete Benutzer sichtbar.

### Funktionen
#### `live_app.dashboard.dashboard.dash_dt`
Formatiert einen Datenbank-Zeitstempel als deutsches Datum mit Uhrzeit fuer die Dashboard-Anzeige.

**Parameter:**
- `?string $value Roher Zeitstempelwert; null oder leer ergibt einen Platzhalter.`

**Rückgabe:** `string Formatiertes Datum "d.m.Y H:i" oder ein Platzhalterzeichen bei ungueltigem Wert.`

---

## Modul: `live_app.dashboard.event_live` (page-controller)
**Datei:** `live_app\dashboard\event_live.php`  
**Zweck:** Rendert die interne Live-Eventansicht fuer aktuelle Schwimmdaten und Highlights.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf der Live-Dashboard-Seite. |
| **Eingaben (`@doc-input`)** | Live-Datenquellen, Hilfsfunktionen und Anzeigeparameter. |
| **Ausgaben (`@doc-output`)** | HTML-Liveansicht fuer Eventbetrieb. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Live-Daten und rendert fortlaufend aktualisierbare Inhalte. |

---

## Modul: `live_app.dashboard.eventlifkiosk` (page-controller)
**Datei:** `live_app\dashboard\eventlifkiosk.php`  
**Zweck:** Rendert die Dashboard-Kioskansicht fuer grossflaechige Live-Anzeige im internen Betrieb.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf der Dashboard-Kioskseite. |
| **Eingaben (`@doc-input`)** | Live-Datenquellen und Anzeigeparameter. |
| **Ausgaben (`@doc-output`)** | HTML-Kioskansicht. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Live-Daten und erzeugt eine grossformatige Anzeige. |

---

## Modul: `live_app.dashboard.json_live` (api-endpoint)
**Datei:** `live_app\dashboard\json_live.php`  
**Zweck:** Liefert strukturierte Live-Daten fuer Dashboards und andere Frontends.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch Frontend oder Monitoring. |
| **Eingaben (`@doc-input`)** | Datenbank, JSON- oder SQLite-Livequellen und Hilfsfunktionen. |
| **Ausgaben (`@doc-output`)** | JSON-Nutzdaten fuer Live-Anzeigen. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Live-Datenquellen und sendet JSON mit HTTP-Headern. |

### Funktionen
#### `live_app.dashboard.json_live.json_live_html`
Wandelt einen Wert HTML-sicher fuer die Live-Ausgabe um.

**Parameter:**
- `string $value Rohwert.`

**Rückgabe:** `string HTML-escaped Wert.`

---

## Modul: `live_app.diagnose` (page-controller)
**Datei:** `live_app\diagnose.php`  
**Zweck:** Stellt Diagnoseinformationen fuer Betrieb, Konfiguration und Datenquellen bereit.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch berechtigte Benutzer. |
| **Eingaben (`@doc-input`)** | Session, Datenbank, Umgebung und Laufzeitinformationen. |
| **Ausgaben (`@doc-output`)** | HTML oder Textausgabe mit Diagnosewerten. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Systemzustand und kann Fehlersituationen sichtbar machen. |

---

## Modul: `live_app.export` (page-controller)
**Datei:** `live_app\export.php`  
**Zweck:** Erzeugt Exportansichten und Dateiausgaben fuer Teilnehmer- und Eventdaten.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch berechtigte Benutzer. |
| **Eingaben (`@doc-input`)** | Session, Berechtigungen, Datenbank und Exportparameter. |
| **Ausgaben (`@doc-output`)** | Dateidownload oder HTML-Vorschau. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Daten und kann Download-Header senden. |

### Funktionen
#### `live_app.export.export_get_team_options`
Laedt alle Teams als Auswahlliste fuer die Exportfilter.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`

**Rückgabe:** `list<array{id:int,name:string}> Liste der Teams mit ID und Name, sortiert nach Name.`

#### `live_app.export.export_build_team_filter_options`
Baut die Auswahloptionen fuer den Teamfilter inklusive "Alle" und optional "Ohne Team".

**Parameter:**
- `list<array{id:int,name:string}> $teamOptions Liste der Teams mit ID und Name.`
- `bool $withNone Fuegt die Option "Ohne Team" hinzu, Standard true.`

**Rückgabe:** `list<array{value:string,label:string}> Liste der Filteroptionen aus Wert und Beschriftung.`

#### `live_app.export.export_get_sources`
Liefert die Konfiguration aller Exportquellen samt Spalten, Filtern und Standardwerten.

**Parameter:**
- `list<array{id:int,name:string}> $teamOptions Liste der Teams fuer die Teamfilteroptionen.`

**Rückgabe:** `array<string, array<string, mixed>> Konfigurationsarray je Datenquelle.`

#### `live_app.export.export_html`
Wandelt einen Rohwert HTML-sicher fuer die Exportansicht um.

**Parameter:**
- `string $value Rohwert.`

**Rückgabe:** `string HTML-escaped Wert.`

#### `live_app.export.export_csv_safe`
Neutralisiert CSV-Formeleinschleusung, indem Werte mit fuehrendem =, +, - oder @ entschaerft werden.

**Parameter:**
- `string $value Rohwert fuer die CSV-Zelle.`

**Rückgabe:** `string Abgesicherter Wert ohne Formelinjektion.`

#### `live_app.export.export_format_value`
Formatiert einen Zellwert anhand der Spaltenkonfiguration und eines optionalen Formatters.

**Parameter:**
- `array<string, mixed> $column Spaltenkonfiguration mit optionalem formatter.`
- `array<string, mixed> $row Datenzeile mit allen Rohwerten.`
- `string $key Schluessel der Spalte in der Datenzeile.`

**Rückgabe:** `string Formatierter Wert als String.`

#### `live_app.export.export_pick_source`
Waehlt die angeforderte Datenquelle oder faellt auf die erste verfuegbare zurueck.

**Parameter:**
- `array<string, array<string, mixed>> $sources Konfiguration aller verfuegbaren Datenquellen.`
- `string|null $requested Angeforderter Quellenschluessel oder null.`

**Rückgabe:** `string Gueltiger Quellenschluessel.`

#### `live_app.export.export_pick_columns`
Ermittelt die gueltigen, deduplizierten und in Wunschreihenfolge sortierten Exportspalten.

**Parameter:**
- `array<string, mixed> $source Konfiguration der gewaehlten Quelle.`
- `list<string> $requestedColumns Vom Benutzer angeforderte Spaltenschluessel.`
- `string|null $columnOrder Kommaseparierte Wunschreihenfolge oder null.`

**Rückgabe:** `list<string> Gueltige Spaltenschluessel in Ausgabereihenfolge.`

#### `live_app.export.export_pick_sort_field`
Waehlt ein gueltiges Sortierfeld der Quelle oder faellt auf den Standard-Schluessel zurueck.

**Parameter:**
- `array<string, mixed> $source Konfiguration der gewaehlten Quelle.`
- `string|null $requested Angefordertes Sortierfeld oder null.`
- `string $fallbackKey Standard-Sortierfeld bei ungueltiger Angabe.`

**Rückgabe:** `string Gueltiger Spaltenschluessel fuer die Sortierung.`

#### `live_app.export.export_pick_sort_direction`
Normalisiert die Sortierrichtung auf ASC oder DESC und nutzt sonst den Standardwert.

**Parameter:**
- `string|null $requested Angeforderte Richtung oder null.`
- `string $fallback Standardrichtung bei ungueltiger Angabe.`

**Rückgabe:** `string 'ASC' oder 'DESC'.`

#### `live_app.export.export_pick_filters`
Bereinigt die angefragten Filterwerte durch Auswahlpruefung bzw. Laengenbegrenzung und liefert die aktiven Filter.

**Parameter:**
- `array<string, mixed> $source Konfiguration der gewaehlten Quelle.`
- `array<string, mixed> $requestFilters Vom Benutzer uebergebene Filterwerte.`

**Rückgabe:** `array<string, string> Bereinigte Filterwerte je Filterschluessel.`

#### `live_app.export.export_build_query`
Baut aus Quelle, Spalten, Filtern und Sortierung das parametrisierte SELECT-Statement.

**Parameter:**
- `array<string, mixed> $source Konfiguration der gewaehlten Quelle.`
- `list<string> $selectedColumns Auszugebende Spaltenschluessel.`
- `array<string, string> $selectedFilters Aktive Filterwerte je Filterschluessel.`
- `string|null $sort1 Primaeres Sortierfeld oder null.`
- `string $dir1 Sortierrichtung des ersten Feldes.`
- `string|null $sort2 Sekundaeres Sortierfeld oder null.`
- `string $dir2 Sortierrichtung des zweiten Feldes.`
- `int|null $limit Optionale Zeilenbegrenzung.`

**Rückgabe:** `array{sql:string,params:array<string,mixed>} SQL-String und zugehoerige Parameter.`

#### `live_app.export.export_execute_query`
Fuehrt die vorbereitete Exportabfrage aus und liefert das Ergebnisstatement.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array<string, mixed> $query Abfrage mit den Schluesseln sql und params.`

**Rückgabe:** `PDOStatement Ausgefuehrtes Statement zum Abrufen der Zeilen.`

---

## Modul: `live_app.impressum` (page-controller)
**Datei:** `live_app\impressum.php`  
**Zweck:** Rendert die internen rechtlichen und organisatorischen Informationen fuer die Webanwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf der Impressumsseite. |
| **Eingaben (`@doc-input`)** | Statische Inhalte und gemeinsam genutztes Layout. |
| **Ausgaben (`@doc-output`)** | HTML-Impressum. |
| **Seiteneffekte (`@doc-side-effects`)** | Keine fachlichen Seiteneffekte ausser HTML-Ausgabe. |

---

## Modul: `live_app.includes.admin_audit_endpoints` (helper-module)
**Datei:** `live_app\includes\admin_audit_endpoints.php`  
**Zweck:** Kapselt die Audit-JSON-Endpunkte der Admin-Oberflaeche ausserhalb des grossen Seitencontrollers.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Aufruf aus `admin.php` nach erfolgreicher Anmeldung. |
| **Eingaben (`@doc-input`)** | PDO-Verbindung, aktueller Benutzer, GET/POST-Parameter und CSRF-Token. |
| **Ausgaben (`@doc-output`)** | JSON-Antworten fuer Audit-Ansicht und Audit-Loeschaktionen. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest und loescht Audit-Eintraege. |

### Funktionen
#### `live_app.includes.admin_audit_endpoints.admin_audit_current_ajax_action`
Bestimmt die Audit-Aktion so, dass bei POST-Requests der Body Vorrang vor der URL hat.


**Rückgabe:** `string Getrimmter Aktionsname oder Leerstring.`

> **Hinweis:** Bei POST wird $_POST['ajax_action'] vorrangig ausgewertet; $_GET dient nur als Fallback bzw. fuer GET-Requests.

#### `live_app.includes.admin_audit_endpoints.admin_audit_json_response`
Kapselt Header, HTTP-Status und JSON-Kodierung fuer alle Audit-Endpunkte.

**Parameter:**
- `array $payload Zu sendende Antwortdaten.`
- `int $statusCode HTTP-Statuscode, Standard 200.`

**Rückgabe:** `void`

#### `live_app.includes.admin_audit_endpoints.admin_handle_audit_ajax`
Behandelt Audit-Anzeige und -Loeschaktionen zentral und prueft dabei Rolle und CSRF-Token.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $currentUser Angemeldeter Benutzer mit Rolle.`

**Rückgabe:** `bool True, wenn die Aktion behandelt wurde (Antwort gesendet), sonst false.`

> **Hinweis:** Unbekannte Aktionen liefern false, damit der Aufrufer normal weiterarbeiten kann.

---

## Modul: `live_app.includes.db_connect` (bootstrap)
**Datei:** `live_app\includes\db_connect.php`  
**Zweck:** Initialisiert Umgebung, Session, Datenbankverbindung und Audit-Kontext und ist die einzige

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Wird von privaten PHP-Seiten und CLI-Skripten frueh eingebunden. |
| **Eingaben (`@doc-input`)** | Umgebungswerte und Session sowie, im Erstfall, den Migrationsaufruf aus scripts/migrate.php. |
| **Ausgaben (`@doc-output`)** | Verfuegbare PDO-Verbindung und initialisierte Laufzeitumgebung. |
| **Seiteneffekte (`@doc-side-effects`)** | Startet die Session, setzt das PHP-Fehlerverhalten, verbindet die Datenbank, setzt die |

### Logikabschnitte & Abläufe
#### Abschnitt `session-bootstrap`
- **Zweck:** Kapselt Ermittlung des Session-Speicherpfads, Verzeichnisanlage und Session-Start.

#### Abschnitt `env-loading`
- **Zweck:** Laedt Konfiguration aus bekannten .env-Dateien und macht sie ueber getenv und $_ENV verfuegbar.

#### Abschnitt `database-bootstrap`
- **Zweck:** Validiert die Datenbankkonfiguration und baut eine robuste PDO-Verbindung auf.

#### Abschnitt `audit-context`
- **Zweck:** Leitet den ausloesenden Benutzer fuer SQL-Trigger her und legt Hashwerte fuer optionale Audit-Metadaten fest.

#### Abschnitt `schema-migration`
- **Zweck:** Prueft per Marker-Datei, ob das Schema bereits initialisiert wurde, und stoesst andernfalls

### Funktionen
#### `live_app.includes.db_connect.app_session_configured_path`
Liest den in der PHP-Konfiguration hinterlegten Session-Speicherpfad aus.


**Rückgabe:** `string|null Nutzbarer Pfad aus der PHP-Konfiguration oder null, wenn keiner gesetzt ist.`

#### `live_app.includes.db_connect.app_session_ensure_storage_dir`
Stellt sicher, dass das angegebene Session-Verzeichnis existiert und beschreibbar ist.

**Parameter:**
- `string $path Gewuenschter Verzeichnispfad.`

**Rückgabe:** `string|null Verzeichnispfad bei Erfolg, sonst null.`

#### `live_app.includes.db_connect.app_session_bootstrap_path`
Ermittelt einen beschreibbaren Session-Speicherpfad mit kontrollierten Ausweichzielen.


**Rückgabe:** `string|null Nutzbarer Session-Pfad oder null im CLI-Betrieb.`

> **Hinweis:** Webzugriffe weichen bei fehlerhaftem PHP-Sessionpfad bewusst auf storage/sessions und

#### `live_app.includes.db_connect.safe_substr`
Kuerzt eine Zeichenkette multibyte-sicher und faellt auf substr zurueck.

**Parameter:**
- `mixed $value Ausgangswert, wird in einen String umgewandelt.`
- `int $start Startposition.`
- `int|null $length Optionale Laenge.`

**Rückgabe:** `string Gekuerzte Zeichenkette.`

---

## Modul: `live_app.includes.functions` (shared-library)
**Datei:** `live_app\includes\functions.php`  
**Zweck:** Bietet zentrale Hilfsfunktionen fuer Sicherheit, Authentifizierung, Registrierung, Teams, Analytics und Live-Auswertungen.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Wird von privaten PHP-Seiten und APIs eingebunden. |
| **Eingaben (`@doc-input`)** | PDO, Session, Umgebungswerte und Rohdaten aus JSON oder SQLite. |
| **Ausgaben (`@doc-output`)** | Wiederverwendbare Fach- und Infrastruktur-Funktionen. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Session und Umgebungswerte, erzeugt Tokens, sendet Requests und berechnet Auswertungen. |

### Logikabschnitte & Abläufe
#### Abschnitt `session-bootstrap-delegation`
- **Zweck:** Diese Bibliothek startet bewusst keine eigene Session und definiert keine Session-Helfer.
- **Eingabe:** Keine.
- **Ausgabe:** Keine.

#### Abschnitt `session-and-auth-bootstrap`
- **Zweck:** Wandelt einen Wert HTML-sicher fuer die Ausgabe um.

#### Abschnitt `asset-helpers`
- **Zweck:** Liefert die moeglichen absoluten Dateipfade fuer ein relatives Frontend-Asset.

#### Abschnitt `role-and-permission-model`
- **Zweck:** Definiert die Berechtigungsmatrix je Rolle als zentrale Quelle.

#### Abschnitt `login-throttling`
- **Zweck:** Liefert den Pfad zur Datei mit den Login-Fehlversuchen und legt das Verzeichnis an.

#### Abschnitt `password-policy`
- **Zweck:** Liefert die zentral definierte Mindestlaenge fuer Benutzerpasswoerter.

#### Abschnitt `notifications`
- **Zweck:** Benachrichtigt einen externen ntfy-Kanal ueber eine neue Anmeldung.

#### Abschnitt `registration-codes`
- **Zweck:** Liefert das abtippfreundliche Zeichenalphabet fuer Registrierungscodes.

#### Abschnitt `team-helpers`
- **Zweck:** Laedt alle benannten Teams als ID-Name-Paare.

#### Abschnitt `startnummern`
- **Zweck:** Schlaegt eine naechste Startnummer unter Bevorzugung teamreservierter Nummern vor.

#### Abschnitt `auth-http-logout`
- **Zweck:** Liefert den Namen des Cookies, das ein HTTP-Auth-Logout markiert.

#### Abschnitt `json-live-data`
- **Zweck:** Ermittelt den Pfad zur Schwimm-JSON, bevorzugt aus Umgebung, sonst aus dem Projekt.

#### Abschnitt `webauthn`
- **Zweck:** Kodiert Binaerdaten Base64-URL-konform ohne Auffuellzeichen.

### Funktionen
#### `live_app.includes.functions.app_asset_real_path`
Ermittelt den ersten tatsaechlich vorhandenen Dateipfad eines Frontend-Assets.

**Parameter:**
- `string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.`

**Rückgabe:** `string|null Erster existierender Pfad oder null, wenn kein Kandidat existiert.`

#### `live_app.includes.functions.app_asset_version`
Erzeugt eine cachebare Versionskennung aus dem Aenderungszeitpunkt eines Assets.

**Parameter:**
- `string $relativePath Relativer Asset-Pfad ab dem App-Verzeichnis.`

**Rückgabe:** `string Versionskennung (Zeitstempel, 'present' oder 'missing').`

#### `live_app.includes.functions.csrf_token_get`
Liefert das CSRF-Token der aktuellen Session und erzeugt es bei Bedarf neu.


**Rückgabe:** `string Aktuelles CSRF-Token der Session.`

> **Hinweis:** Das Token wird bei fehlendem oder ungueltigem Sessionwert neu erzeugt.

#### `live_app.includes.functions.csrf_token_verify`
Prueft ein uebermitteltes CSRF-Token zeitkonstant gegen das Session-Token.

**Parameter:**
- `string|null $token Zu pruefendes Token aus der Anfrage.`

**Rückgabe:** `bool True, wenn das Token gueltig ist.`

#### `live_app.includes.functions.admin_is_logged_in`
Prueft, ob die Session als Admin angemeldet ist.


**Rückgabe:** `bool True, wenn eine aktive Admin-Anmeldung existiert.`

#### `live_app.includes.functions.admin_login`
Markiert die aktuelle Session als Admin-Anmeldung und erneuert deren ID.


**Rückgabe:** `void`

> **Hinweis:** Die Session-ID wird bei aktiver Session regeneriert; ein etwaiges HTTP-Logout wird zurueckgesetzt.

#### `live_app.includes.functions.admin_logout`
Beendet die Session vollstaendig und entfernt das Session-Cookie.


**Rückgabe:** `void`

#### `live_app.includes.functions.admin_password_hash_default`
Erzeugt aus dem Umgebungspasswort einen Passwort-Hash fuer den Setup-Admin.


**Rückgabe:** `string Passwort-Hash oder leerer String, wenn kein Passwort konfiguriert ist.`

#### `live_app.includes.functions.auth_setup_password_enabled`
Prueft, ob das Setup-Passwort per Umgebungsflag freigeschaltet ist.


**Rückgabe:** `bool True, wenn ALLOW_SETUP_PASSWORD gesetzt auf '1' ist.`

#### `live_app.includes.functions.auth_http_user`
Liest den per HTTP-Authentifizierung uebermittelten Benutzernamen aus.


**Rückgabe:** `string|null Benutzername oder null, wenn keiner uebermittelt wurde.`

#### `live_app.includes.functions.auth_users_enabled`
Prueft, ob die Benutzertabelle in der Datenbank vorhanden ist.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`

**Rückgabe:** `bool True, wenn die Tabelle users erreichbar ist.`

#### `live_app.includes.functions.auth_is_setup_mode`
Prueft, ob sich das System im Ersteinrichtungsmodus ohne Benutzer befindet.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`

**Rückgabe:** `bool True, wenn die Benutzertabelle existiert und leer ist.`

#### `live_app.includes.functions.auth_admin_count`
Ermittelt die Anzahl vorhandener Admin-Benutzer.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`

**Rückgabe:** `int|null Anzahl der Admins oder null, wenn die Tabelle nicht verfuegbar ist.`

#### `live_app.includes.functions.auth_allow_setup_password`
Entscheidet, ob eine Anmeldung mit dem Setup-Passwort erlaubt ist.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`

**Rückgabe:** `bool True, wenn das Setup-Passwort genutzt werden darf.`

#### `live_app.includes.functions.auth_role_normalize`
Vereinheitlicht veraltete Rollennamen auf die aktuelle Rollenbezeichnung.

**Parameter:**
- `string $role Roh-Rollenname.`

**Rückgabe:** `string Normalisierter Rollenname ('numbers' wird zu 'orga').`

#### `live_app.includes.functions.auth_set_session`
Schreibt die Benutzeridentitaet in die Session und setzt ein HTTP-Logout zurueck.

**Parameter:**
- `int $user_id Benutzer-ID.`
- `string $username Benutzername.`
- `string $role Rollenname.`

**Rückgabe:** `void`

#### `live_app.includes.functions.auth_clear_session`
Loescht die Anmeldesitzung des Benutzers.


**Rückgabe:** `void`

> **Hinweis:** Delegiert an admin_logout() und beendet damit die gesamte Session.

#### `live_app.includes.functions.auth_current_user`
Ermittelt den aktuell angemeldeten Benutzer aus Session, Admin-Flag oder HTTP-Auth.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`

**Rückgabe:** `array|null Benutzerdaten (id, username, role) oder null, wenn nicht angemeldet.`

> **Hinweis:** Das Ergebnis wird statisch innerhalb des Requests zwischengespeichert.

#### `live_app.includes.functions.auth_is_logged_in`
Prueft, ob aktuell ein Benutzer angemeldet ist.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`

**Rückgabe:** `bool True, wenn auth_current_user() einen Benutzer liefert.`

#### `live_app.includes.functions.auth_login_client_ip`
Ermittelt die Client-IP fuer die Fehlversuchszaehlung.


**Rückgabe:** `string Client-IP oder '0.0.0.0' als Rueckfallwert.`

#### `live_app.includes.functions.auth_login_load_attempts`
Laedt die gespeicherten Login-Fehlversuche aus der Datei.


**Rückgabe:** `array Fehlversuchsstruktur oder leeres Array bei fehlender/ungueltiger Datei.`

#### `live_app.includes.functions.auth_login_save_attempts`
Persistiert die Login-Fehlversuche unter exklusiver Dateisperre.

**Parameter:**
- `array $data Fehlversuchsstruktur.`

**Rückgabe:** `void`

#### `live_app.includes.functions.auth_login_prune_attempts`
Entfernt Fehlversuchseintraege ausserhalb des Zeitfensters.

**Parameter:**
- `array $data Fehlversuchsstruktur.`
- `int $windowSec Gueltiges Zeitfenster in Sekunden.`

**Rückgabe:** `array Bereinigte Fehlversuchsstruktur.`

#### `live_app.includes.functions.auth_login_is_locked`
Prueft, ob IP oder Benutzername wegen zu vieler Fehlversuche gesperrt ist.

**Parameter:**
- `string $ip Client-IP.`
- `string $username Benutzername.`
- `int $maxAttempts Maximale Fehlversuche bis zur Sperre.`
- `int $lockSec Sperrdauer in Sekunden.`

**Rückgabe:** `array Status mit 'locked', 'ip_count', 'user_count', 'max_attempts' und 'lock_seconds'.`

#### `live_app.includes.functions.auth_login_record_failure`
Erfasst einen Login-Fehlversuch fuer IP und Benutzername.

**Parameter:**
- `string $ip Client-IP.`
- `string $username Benutzername.`
- `int $lockSec Sperrfenster in Sekunden, in dem Fehlversuche zaehlen.`

**Rückgabe:** `void`

#### `live_app.includes.functions.auth_login_clear_attempts`
Loescht die Fehlversuche fuer IP und Benutzername nach erfolgreicher Anmeldung.

**Parameter:**
- `string $ip Client-IP.`
- `string $username Benutzername.`

**Rückgabe:** `void`

#### `live_app.includes.functions.auth_login_progressive_delay`
Verzoegert weitere Login-Versuche progressiv zur Verlangsamung von Brute-Force.

**Parameter:**
- `int $failureCount Bisherige Fehlversuche.`

**Rückgabe:** `void`

> **Hinweis:** Die Verzoegerung ist auf maximal 2 Sekunden begrenzt.

#### `live_app.includes.functions.auth_password_validate`
Prueft ein Passwort gegen die Passwortrichtlinie und sammelt Fehlermeldungen.

**Parameter:**
- `string $password Zu pruefendes Passwort.`

**Rückgabe:** `array Ergebnis mit 'valid' (bool) und 'errors' (Liste der Fehlermeldungen).`

#### `live_app.includes.functions.auth_login_with_credentials`
Meldet einen Benutzer mit Benutzername und Passwort unter Beruecksichtigung der Sperrlogik an.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`
- `string $username Benutzername.`
- `string $password Klartextpasswort.`

**Rückgabe:** `bool True bei erfolgreicher Anmeldung.`

> **Hinweis:** Bei Fehlschlag werden Fehlversuche gezaehlt; im Setup-Modus ist die Anmeldung deaktiviert.

#### `live_app.includes.functions.auth_can`
Prueft, ob der aktuelle Benutzer die angeforderte Berechtigung besitzt.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`
- `string $permission Zu pruefende Berechtigung.`

**Rückgabe:** `bool True, wenn die Berechtigung erlaubt ist.`

#### `live_app.includes.functions.auth_verify_token`
Verifiziert ein Access-Token und liefert den zugehoerigen Benutzer.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`
- `string $raw_token Ungehashtes Access-Token.`

**Rückgabe:** `array|null Benutzerdaten (user_id, username, role, token_id) oder null, wenn ungueltig/abgelaufen.`

> **Hinweis:** Aktualisiert last_used_at bei gueltigem Token.

#### `live_app.includes.functions.auth_require_role`
Erzwingt eine Rollenzugehoerigkeit und leitet sonst auf eine Zielseite um.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`
- `array $roles Erlaubte Rollen.`
- `string $redirect_to Umleitungsziel bei fehlender Berechtigung.`

**Rückgabe:** `void`

> **Hinweis:** Beendet das Skript bei fehlender Berechtigung per exit.

#### `live_app.includes.functions.auth_require_permission_or_403_json`
Erzwingt eine Berechtigung und antwortet sonst mit HTTP 403 als JSON.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`
- `string $permission Erforderliche Berechtigung.`

**Rückgabe:** `void`

> **Hinweis:** Beendet das Skript bei fehlender Berechtigung per exit.

#### `live_app.includes.functions.registration_code_normalize`
Vereinheitlicht einen Registrierungscode auf Grossbuchstaben ohne Trennzeichen.

**Parameter:**
- `string $code Roh-Code.`

**Rückgabe:** `string Normalisierter Code.`

#### `live_app.includes.functions.registration_name_normalize`
Bereinigt einen Namensstring und begrenzt dessen Laenge.

**Parameter:**
- `string $name Roh-Name.`
- `int $maxLen Maximale Laenge; 0 bedeutet keine Begrenzung.`

**Rückgabe:** `string Bereinigter Name.`

#### `live_app.includes.functions.registration_code_format`
Formatiert einen Registrierungscode in Vierergruppen fuer die Anzeige.

**Parameter:**
- `string $code Roh-Code.`

**Rückgabe:** `string Formatierter Code mit Bindestrichen oder leerer String.`

#### `live_app.includes.functions.luhn_mod_n_check_char`
Berechnet die Luhn-Mod-N-Pruefziffer fuer einen Code-Rumpf.

**Parameter:**
- `string $body Code-Rumpf ohne Pruefziffer.`
- `string $alphabet Verwendetes Zeichenalphabet.`

**Rückgabe:** `string Berechnete Pruefziffer.`

**Exceptions:**
- `RuntimeException Wenn das Alphabet zu kurz ist oder ein Zeichen nicht im Alphabet vorkommt.`

#### `live_app.includes.functions.registration_code_generate`
Erzeugt einen neuen Registrierungscode mit angehaengter Pruefziffer.

**Parameter:**
- `int $random_len Anzahl der Zufallszeichen im Rumpf.`

**Rückgabe:** `string Vollstaendiger Code inklusive Pruefziffer.`

#### `live_app.includes.functions.registration_code_is_valid`
Prueft die Gueltigkeit eines Registrierungscodes anhand seiner Pruefziffer.

**Parameter:**
- `string $code Zu pruefender Code.`

**Rückgabe:** `bool True, wenn der Code formal gueltig ist.`

#### `live_app.includes.functions.team_name_normalize`
Bereinigt einen Teamnamen und begrenzt dessen Laenge.

**Parameter:**
- `string|null $name Roh-Teamname.`

**Rückgabe:** `string|null Bereinigter Teamname oder null, wenn leer.`

#### `live_app.includes.functions.team_get_or_create_id`
Liefert die ID eines Teams und legt es bei Bedarf atomar neu an.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`
- `string|null $teamname Teamname.`

**Rückgabe:** `int|null Team-ID oder null bei leerem Namen oder Fehler.`

#### `live_app.includes.functions.team_get_name_by_id`
Ermittelt den Namen eines Teams anhand seiner ID.

**Parameter:**
- `PDO $pdo MySQL-Verbindung.`
- `int|null $team_id Team-ID.`

**Rückgabe:** `string|null Teamname oder null, wenn nicht vorhanden.`

#### `assign_startnummer`
Kapselt Startnummernvergabe, Wunschnummernpruefung und optionale Zahlungsmarkierung in einer

**Parameter:**
- `PDO $pdo MySQL-Verbindung (Verwaltungs-Datenbank).`
- `int $teilnehmer_id ID des Ziel-Teilnehmers.`
- `int|null $team_id Optionale Team-ID zur Bevorzugung reservierter Nummern; ohne Angabe wird die`
- `int $wunschnummer Wunschnummer > 0; 0 bedeutet automatische Vergabe.`
- `bool $markPaid Markiert den Teilnehmer in derselben Transaktion als bezahlt.`

**Rückgabe:** `int|null Vergebene Startnummer oder null, wenn keine Nummer verfuegbar ist.`

**Exceptions:**
- `RuntimeException Wenn der Teilnehmer fehlt, die Wunschnummer bereits vergeben ist oder das`

> **Hinweis:** Eigene Transaktion wird nur gestartet, wenn der Aufrufer noch keine aktiv hat; eine bestehende

#### `live_app.includes.functions.auth_cookie_is_secure`
Erkennt, ob die aktuelle Verbindung ueber HTTPS laeuft.


**Rückgabe:** `bool True, wenn das Cookie als secure gesetzt werden soll.`

#### `live_app.includes.functions.auth_mark_http_logout`
Setzt ein Cookie, das einen expliziten HTTP-Auth-Logout markiert.


**Rückgabe:** `void`

> **Hinweis:** Das Cookie ist HttpOnly, SameSite=Strict und bei HTTPS secure.

#### `live_app.includes.functions.auth_clear_http_logout`
Entfernt das HTTP-Auth-Logout-Cookie.


**Rückgabe:** `void`

#### `live_app.includes.functions.auth_http_logout_requested`
Prueft, ob ein HTTP-Auth-Logout angefordert wurde.


**Rückgabe:** `bool True, wenn das Logout-Cookie gesetzt ist.`

#### `live_app.includes.functions.sqlite_live_default_file_path`
Liefert den Standardpfad der SQLite-Live-Datenbank.


**Rückgabe:** `string Absoluter Pfad zur data.sqlite.`

#### `sqlite_live_open`
Stellt die einzige zentrale Verbindungs-Initialisierung fuer die SQLite-Live-Datenbank bereit.

**Parameter:**
- `string|null $sqlitePath Optionaler Pfad; ohne Angabe wird ueber sqlite_live_default_file_path() aufgeloest.`
- `bool $readOnly Kennzeichnet eine reine Leseverbindung (Datei muss dann bereits existieren).`

**Rückgabe:** `PDO Konfigurierte SQLite-Verbindung.`

**Exceptions:**
- `RuntimeException Wenn im Read-Only-Modus die Datei fehlt.`

> **Hinweis:** PRAGMA busy_timeout wird immer gesetzt; journal_mode/synchronous werden defensiv abgefangen,

#### `live_app.includes.functions.live_data_source_resolve`
Bestimmt die aktive Live-Datenquelle und ihren Pfad.


**Rückgabe:** `array{mode:string,path:string} Aktive Datenquelle mit Modus und Pfad.`

> **Hinweis:** Liefert derzeit immer den SQLite-Modus.

#### `live_app.includes.functions.json_live_read_payload`
Liest und dekodiert die Schwimm-JSON-Datei mit klarer Fehlerbehandlung.

**Parameter:**
- `string $jsonPath Pfad zur JSON-Datei.`

**Rückgabe:** `array Dekodierter JSON-Inhalt.`

**Exceptions:**
- `RuntimeException Wenn die Datei fehlt, leer oder ungueltig ist.`

#### `live_app.includes.functions.sqlite_live_decode_parameter`
Normalisiert den Parameterwert einer Action zu einer Werteliste.

**Parameter:**
- `mixed $rawValue Rohwert (Array, JSON-String oder CSV-String).`

**Rückgabe:** `array Liste der dekodierten Parameterwerte.`

#### `live_app.includes.functions.sqlite_live_read_payload`
Liest Schwimmer und Actions aus der SQLite-Live-Datenbank und bereitet sie JSON-aehnlich auf.

**Parameter:**
- `string $sqlitePath Pfad zur SQLite-Datei.`

**Rückgabe:** `array Payload mit 'alleSchwimmer', 'actions' und 'statMessages'.`

#### `live_app.includes.functions.json_live_normalize_name`
Bildet einen anzeigbaren Namen aus Vor-, Nachname oder Nummer.

**Parameter:**
- `array $row Rohe Schwimmerdaten.`

**Rückgabe:** `string Anzeigename oder Fallback wie 'Schwimmer <Nummer>' bzw. 'Unbekannt'.`

#### `live_app.includes.functions.json_live_normalize_swimmer_map`
Vereinheitlicht die unterschiedlichen Schwimmerquellen der JSON zu einer Map nach Nummer.

**Parameter:**
- `array $payload Roh-Payload der Schwimm-JSON.`

**Rückgabe:** `array Nach Schwimmernummer sortierte, normalisierte Schwimmerdaten.`

#### `live_app.includes.functions.json_live_action_timestamp`
Ermittelt den Zeitstempel einer Action aus verschiedenen moeglichen Feldern.

**Parameter:**
- `array $action Action-Datensatz.`

**Rückgabe:** `int|null Unix-Zeitstempel oder null, wenn keiner ermittelbar ist.`

#### `live_app.includes.functions.json_live_action_command`
Liest das Kommando einer Action in Normalform (Grossbuchstaben).

**Parameter:**
- `array $action Action-Datensatz.`

**Rückgabe:** `string Kommando in Grossbuchstaben oder leerer String.`

#### `live_app.includes.functions.json_live_prepare_actions`
Reichert Actions um Kommando und Zeitstempel an und sortiert sie chronologisch.

**Parameter:**
- `array $payload Roh-Payload der Schwimm-JSON.`

**Rückgabe:** `array Sortierte Actions mit internen Feldern '_command' und '_timestamp'.`

#### `live_app.includes.functions.json_live_apply_action_stats`
Aggregiert Bahnen und Aktivstatus aus den Actions in die Schwimmerkarte.

**Parameter:**
- `array<int, array<string, mixed>> $swimmerMap Schwimmerdaten nach Nummer.`
- `list<array<string, mixed>> $actions Vorbereitete Action-Datensaetze.`

**Rückgabe:** `array<int, array<string, mixed>> Aktualisierte Schwimmerkarte inkl. Actions ohne Stammdaten.`

#### `live_app.includes.functions.json_live_load_lane_timeframes`
Wertet aktive Zeitfenster aus und zaehlt die darin enthaltenen ADD-Ereignisse.

**Parameter:**
- `PDO $pdo Verbindung zur Verwaltungsdatenbank.`
- `array $actions Vorbereitete Action-Datensaetze.`

**Rückgabe:** `array Liste der Zeitfenster mit Bahnen und eindeutigen Schwimmern.`

#### `live_app.includes.functions.json_live_build_overview`
Berechnet aus dem Live-Payload das vollstaendige Uebersichtsmodell fuer Dashboard und Kiosk.

**Parameter:**
- `array $payload Roh-Payload der Live-Daten.`
- `PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.`
- `string $sourceMode Kennzeichnet die Datenquelle ('json' oder 'sqlite').`
- `string|null $sourcePath Pfad der Datenquelle fuer Metadaten.`

**Rückgabe:** `array Vollstaendiges Uebersichtsobjekt inkl. Totals, Toplisten und Highlights.`

#### `live_app.includes.functions.live_overview_error_payload`
Erzeugt ein leeres Uebersichtsmodell mit Fehlermeldung im selben Schema.

**Parameter:**
- `string $sourceMode Kennzeichnet die Datenquelle.`
- `string $sourcePath Pfad der Datenquelle.`
- `string $message Fehlermeldung.`

**Rückgabe:** `array Leeres Uebersichtsobjekt mit 'ok'=false und 'error'.`

#### `live_app.includes.functions.json_live_load_overview`
Laedt und berechnet die Uebersicht aus einer JSON-Datei mit Fehlerfallback.

**Parameter:**
- `string $jsonPath Pfad zur JSON-Datei.`
- `PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.`

**Rückgabe:** `array Uebersichtsmodell oder Fehlerpayload.`

#### `live_app.includes.functions.sqlite_live_load_overview`
Laedt und berechnet die Uebersicht aus der SQLite-Datenbank mit Fehlerfallback.

**Parameter:**
- `string $sqlitePath Pfad zur SQLite-Datei.`
- `PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.`

**Rückgabe:** `array Uebersichtsmodell oder Fehlerpayload.`

#### `live_app.includes.functions.live_load_overview`
Laedt die Uebersicht anhand der aufgeloesten Live-Datenquelle.

**Parameter:**
- `PDO|null $pdo Optionale Verbindung fuer Zeitfenster-Statistiken.`

**Rückgabe:** `array Uebersichtsmodell oder Fehlerpayload.`

#### `live_app.includes.functions.event_live_load_overview`
Laedt die Veranstaltungs-Uebersicht ueber die allgemeine Live-Quelle.

**Parameter:**
- `PDO $pdo Verbindung fuer Zeitfenster-Statistiken.`

**Rückgabe:** `array Uebersichtsmodell oder Fehlerpayload.`

#### `live_app.includes.functions.webauthn_base64url_decode`
Dekodiert einen Base64-URL-String zurueck in Binaerdaten.

**Parameter:**
- `string $data Base64-URL-kodierter String.`

**Rückgabe:** `string Dekodierte Rohdaten.`

#### `live_app.includes.functions.webauthn_cbor_decode`
Dekodiert einen CBOR-Wert ab der aktuellen Offset-Position fuer WebAuthn-Daten.

**Parameter:**
- `string $data CBOR-kodierte Rohdaten.`
- `int &$offset Leseposition, wird beim Dekodieren fortgeschrieben.`

**Rückgabe:** `mixed Dekodierter Wert (int, string, array oder map) oder null bei ungueltigen Daten.`

> **Hinweis:** Der Offset wird als Referenz uebergeben und waehrend der Rekursion aktualisiert.

#### `live_app.includes.functions.webauthn_cose_public_key_to_pem`
Wandelt einen COSE-EC2-Schluessel in ein PEM-Format fuer openssl_verify um.

**Parameter:**
- `array $coseKey Dekodierter COSE-Schluessel.`

**Rückgabe:** `string|null PEM-Public-Key oder null bei nicht unterstuetztem Schluesseltyp.`

#### `live_app.includes.functions.webauthn_generate_challenge`
Erzeugt eine zufaellige WebAuthn-Challenge.


**Rückgabe:** `string Hex-kodierte Challenge.`

#### `live_app.includes.functions.webauthn_rp_id`
Ermittelt die Relying-Party-ID aus dem aktuellen Host.


**Rückgabe:** `string Hostname ohne Port (Standard 'localhost').`

#### `live_app.includes.functions.webauthn_verify_registration`
Verifiziert die WebAuthn-Registrierung und extrahiert Credential-ID und Public Key.

**Parameter:**
- `string $clientDataJSON ClientData als JSON.`
- `string $attestationObject CBOR-kodiertes Attestation-Objekt.`
- `string $expectedChallenge Erwartete Challenge.`

**Rückgabe:** `array|null Daten mit 'credential_id' und 'public_key' oder null bei Prueffehler.`

#### `live_app.includes.functions.webauthn_verify_authentication`
Verifiziert eine WebAuthn-Anmelde-Assertion per Signaturpruefung.

**Parameter:**
- `string $credentialIdB64 Base64-URL-kodierte Credential-ID.`
- `string $clientDataJSON ClientData als JSON.`
- `string $authenticatorData Authenticator-Daten.`
- `string $signature Signatur ueber die Assertion.`
- `string $publicKeyPem PEM-Public-Key des Credentials.`
- `string $expectedChallenge Erwartete Challenge.`

**Rückgabe:** `bool True, wenn die Assertion gueltig ist.`

---

## Modul: `live_app.index` (page-controller)
**Datei:** `live_app\index.php`  
**Zweck:** Stellt die Hauptseite fuer Anmeldung und oeffentliche Eventinformationen bereit.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf der Startseite. |
| **Eingaben (`@doc-input`)** | Session, Formularwerte, Datenbank und Konfiguration. |
| **Ausgaben (`@doc-output`)** | HTML der oeffentlichen Hauptseite. |
| **Seiteneffekte (`@doc-side-effects`)** | Kann Formulardaten verarbeiten und Persistenz ausloesen. |

---

## Modul: `live_app.numbers` (page-controller)
**Datei:** `live_app\numbers.php`  
**Zweck:** Verwaltet Startnummern, Reservierungen und Zuweisungen für Teilnehmer und Teams.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch berechtigte Benutzer. |
| **Eingaben (`@doc-input`)** | Session, Berechtigungen, Formularwerte und Datenbank. |
| **Ausgaben (`@doc-output`)** | HTML oder Aktionsantworten für die Startnummernverwaltung. |
| **Seiteneffekte (`@doc-side-effects`)** | Ändert Startnummernstatus und Teilnehmerzuordnungen in der Datenbank. |

### Logikabschnitte & Abläufe
#### Abschnitt `numbers-actions`
- **Zweck:** Verarbeitet Reservieren, Freigeben und direkte Zuweisung von Startnummern.

#### Abschnitt `numbers-data-loading`
- **Zweck:** Lädt Nummernbestand, Teamreservierungen und freie Zuordnungsdaten für die Seite.

---

## Modul: `live_app.scripts.analyze_event_sqlite` (cli-script)
**Datei:** `live_app\scripts\analyze_event_sqlite.py`  
**Zweck:** Analysiert die SQLite-Datenbank eines 24h-Schwimmens und erzeugt umfassende Event-Statistiken.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI-Aufruf durch Entwickler oder Betrieb. |
| **Eingaben (`@doc-input`)** | SQLite-Datei mit Tabellen `schwimmer` und `actions`. |
| **Ausgaben (`@doc-output`)** | Menschlich lesbare Zusammenfassung oder JSON-Report. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest die Datenbank nur lesend. |

---

## Modul: `live_app.scripts.backup` (script)
**Datei:** `live_app\scripts\backup.php`  
**Zweck:** Fuehrt Backup-Aufgaben ausserhalb der Weboberflaeche aus.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI- oder Scheduler-Aufruf. |
| **Eingaben (`@doc-input`)** | Dateisystem, Datenbank und Konfiguration. |
| **Ausgaben (`@doc-output`)** | Statusausgaben und erzeugte Sicherungsdateien. |
| **Seiteneffekte (`@doc-side-effects`)** | Schreibt Backup-Dateien und greift auf Datenquellen zu. |

---

## Modul: `live_app.scripts.create_admin` (script)
**Datei:** `live_app\scripts\create_admin.php`  
**Zweck:** Legt einen Admin-Benutzer fuer den Initialbetrieb oder Notfall an.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI-Aufruf durch Administratoren. |
| **Eingaben (`@doc-input`)** | Zugangsdaten, Datenbank und Konfiguration. |
| **Ausgaben (`@doc-output`)** | Angelegter Admin-Benutzer oder Fehlermeldung. |
| **Seiteneffekte (`@doc-side-effects`)** | Schreibt Benutzerdaten in die Datenbank. |

---

## Modul: `live_app.scripts.diagnose` (script)
**Datei:** `live_app\scripts\diagnose.php`  
**Zweck:** Prueft Konfiguration, Datenbank und Laufzeitbedingungen ausserhalb der Weboberflaeche.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI-Aufruf durch Betrieb oder Entwickler. |
| **Eingaben (`@doc-input`)** | Umgebung, Datenbank und Dateisystem. |
| **Ausgaben (`@doc-output`)** | Statusausgaben fuer Diagnosezwecke. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest System- und Konfigurationszustand. |

---

## Modul: `live_app.scripts.merge_schwimmerdaten` (cli-script)
**Datei:** `live_app\scripts\merge_schwimmerdaten.py`  
**Zweck:** Fuehrt CSV-Stammdaten und JSON-Eventlogs zu einer aktualisierten Ergebnis-CSV zusammen.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI-Aufruf durch Entwickler oder Betrieb. |
| **Eingaben (`@doc-input`)** | CSV-Datei, JSON-Backup und optionale Zeitfensterdatei. |
| **Ausgaben (`@doc-output`)** | Aktualisierte CSV-Datei und Statusausgaben im Terminal. |
| **Seiteneffekte (`@doc-side-effects`)** | Liest Eingabedateien und schreibt eine neue Ausgabedatei. |

---

## Modul: `live_app.scripts.migrate` (migration-module)
**Datei:** `live_app\scripts\migrate.php`  
**Zweck:** Stellt Tabellen, Spalten, Indizes, Fremdschluessel und Audit-Trigger idempotent her und

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Direkter CLI-Aufruf "php live_app/scripts/migrate.php [--force-triggers]" oder einmalige |
| **Eingaben (`@doc-input`)** | PDO-Verbindung zu MySQL/MariaDB sowie optionale Kommandozeilenparameter. |
| **Ausgaben (`@doc-output`)** | Ergebnisarray mit Status, Marker-Kennzeichnung und Protokolleintraegen, im CLI zusaetzlich Textausgabe. |
| **Seiteneffekte (`@doc-side-effects`)** | Legt Datenbankstrukturen und Trigger an, bereinigt Startnummern-Konflikte und schreibt die Marker-Datei. |

### Logikabschnitte & Abläufe
#### Abschnitt `storage-and-marker`
- **Zweck:** Kapselt Pfade, Verzeichnisanlage und die Marker-Datei, die den Migrationszustand dokumentiert.

#### Abschnitt `schema-introspection`
- **Zweck:** Bietet schlanke Metadatenabfragen, die ausschliesslich im Migrationslauf verwendet werden.

#### Abschnitt `table-catalog`
- **Zweck:** Definiert die Mengen der zu erstellenden Basistabellen in abhaengigkeitsfreier Reihenfolge.

#### Abschnitt `column-and-index-migration`
- **Zweck:** Bringt Bestandsinstallationen auf den Spalten- und Indexstand des Referenzschemas.

#### Abschnitt `data-consistency`
- **Zweck:** Stellt Startnummern-Grunddaten, Teamverknuepfungen und die Eindeutigkeit der Startnummern sicher.

#### Abschnitt `audit-triggers`
- **Zweck:** Beschreibt und pflegt die Audit-Trigger, die Datenbankaenderungen in audit_log protokollieren.

#### Abschnitt `migration-runner`
- **Zweck:** Steuert die Reihenfolge aller Migrationsschritte und stellt den oeffentlichen Einstieg bereit.

#### Abschnitt `cli-entry`
- **Zweck:** Startet die Migration ausschliesslich bei direktem CLI-Aufruf dieser Datei.

### Funktionen
#### `live_app.scripts.migrate.app_migration_storage_dir`
Liefert das private Storage-Verzeichnis und legt es bei Bedarf an.


**Rückgabe:** `string|null Absoluter Pfad zum Storage-Verzeichnis oder null, wenn es nicht nutzbar ist.`

#### `live_app.scripts.migrate.app_migration_marker_path`
Ermittelt den Pfad der Schema-Marker-Datei, deren Existenz den Abschluss der Migration signalisiert.


**Rückgabe:** `string Absoluter Pfad der Marker-Datei.`

#### `live_app.scripts.migrate.app_migration_acquire_lock`
Sichert die Erstmigration gegen parallele Aufrufe mittels Advisory File-Lock ab.

**Parameter:**
- `float $timeoutSeconds Maximale Wartezeit in Sekunden, bevor der Aufruf aufgibt.`

**Rückgabe:** `resource|null Offenes Lock-Handle oder null, wenn der Lock nicht innerhalb des Timeouts erworben wurde.`

#### `live_app.scripts.migrate.app_migration_release_lock`
Gibt einen zuvor erworbenen Advisory File-Lock wieder frei.

**Parameter:**
- `resource|null $handle Lock-Handle aus app_migration_acquire_lock().`

**Rückgabe:** `void`

#### `live_app.scripts.migrate.app_migration_write_marker`
Schreibt die Marker-Datei, damit nachfolgende Requests keine DDL-Pruefungen mehr ausfuehren.

**Parameter:**
- `string $source Kennzeichnung des ausloesenden Kontexts, u. a. "web" oder "cli".`

**Rückgabe:** `bool True bei erfolgreichem Schreiben.`

#### `live_app.scripts.migrate.app_migration_table_exists`
Prueft, ob eine Tabelle in der aktuellen Datenbank existiert.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `string $table Tabellenname.`

**Rückgabe:** `bool True, wenn die Tabelle existiert.`

#### `live_app.scripts.migrate.app_migration_column_exists`
Prueft, ob eine Spalte in einer Tabelle existiert.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `string $table Tabellenname.`
- `string $column Spaltenname.`

**Rückgabe:** `bool True, wenn die Spalte existiert.`

#### `live_app.scripts.migrate.app_migration_index_exists`
Prueft, ob ein Index oder Unique-Key in einer Tabelle existiert.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `string $table Tabellenname.`
- `string $index Indexname.`

**Rückgabe:** `bool True, wenn der Index existiert.`

#### `live_app.scripts.migrate.app_migration_trigger_exists`
Prueft, ob ein Trigger in der aktuellen Datenbank existiert.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `string $trigger Triggername.`

**Rückgabe:** `bool True, wenn der Trigger existiert.`

#### `live_app.scripts.migrate.app_migration_exec`
Fuehrt einen DDL-Schritt aus und protokolliert Erfolg oder Ueberspringen.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `string $sql Auszufuehrendes SQL-Statement.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`
- `string $label Sprechende Bezeichnung des Schritts.`
- `bool $optional True, wenn Fehler toleriert und nur protokolliert werden sollen.`

**Rückgabe:** `bool True, wenn das Statement erfolgreich ausgefuehrt wurde.`

**Exceptions:**
- `PDOException Wenn $optional false ist und das Statement fehlschlaegt.`

#### `live_app.scripts.migrate.app_migration_table_statements`
Liefert die CREATE-Statements der Basistabellen in FK-tauglicher Reihenfolge.


**Rückgabe:** `array<string,string> Abbildung Tabellenname auf CREATE TABLE IF NOT EXISTS-Statement.`

#### `live_app.scripts.migrate.app_migration_ensure_tables`
Erstellt alle fehlenden Basistabellen inklusive access_tokens und webauthn_credentials.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

**Exceptions:**
- `PDOException Wenn eine Pflichttabelle nicht angelegt werden kann.`

#### `live_app.scripts.migrate.app_migration_ensure_columns`
Ergaenzt fehlende Spalten und Indizes in teilnehmer, startnummern und lane_timeframes.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

#### `live_app.scripts.migrate.app_migration_drop_legacy_columns`
Entfernt historische Request-Metadaten-Spalten, sofern sie noch vorhanden sind.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

> **Hinweis:** Die fachliche Bereinigung inklusive Berichtsfunktion liegt in scripts/privacy_cleanup.php.

#### `live_app.scripts.migrate.app_migration_seed_startnummern`
Fuellt den Startnummern-Pool einmalig mit den Nummern 1 bis 999.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

#### `live_app.scripts.migrate.app_migration_backfill_teams`
Ueberfuehrt Freitext-Teamnamen in die teams-Tabelle und synchronisiert die Verweise.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

#### `live_app.scripts.migrate.app_migration_ensure_team_foreign_keys`
Legt die Fremdschluessel zwischen Teilnehmern, Startnummern und Teams an, sofern sie fehlen.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

#### `live_app.scripts.migrate.app_migration_enforce_unique_startnummer`
Bereinigt ungueltige Startnummern und erzwingt die Eindeutigkeit von teilnehmer.startnummer.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

> **Hinweis:** Nullwerte bleiben von der Unique-Regel unberuehrt, da MySQL mehrere NULL-Werte in einem

#### `live_app.scripts.migrate.app_migration_repair_startnummer_consistency`
Heilt einmalig verwaiste Zuordnungen zwischen teilnehmer und startnummern.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

> **Hinweis:** Nacharbeiten zu Befund B3: Das frueher bei jedem GET ausgefuehrte Reparatur-UPDATE aus numbers.php

> **Hinweis:** Die Richtung ist bewusst einseitig: teilnehmer.startnummer gilt als fuehrend, die zugehoerige

#### `live_app.scripts.migrate.app_migration_audit_catalog`
Hinterlegt je ueberwachter Tabelle den Primaerschluessel und die zu protokollierenden Spalten.


**Rückgabe:** `array<string,array{pk:string,columns:array<int,string>}> Katalog der Audit-Tabellen.`

#### `live_app.scripts.migrate.app_migration_trigger_definitions`
Erzeugt die Triggerdefinitionen fuer alle vorhandenen Audit-Tabellen.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`

**Rückgabe:** `array<string,string> Abbildung Triggername auf CREATE TRIGGER-Statement.`

> **Hinweis:** Die Trigger lesen den ausloesenden Benutzer aus den Session-Variablen

#### `live_app.scripts.migrate.app_migration_ensure_triggers`
Legt fehlende Audit-Trigger an und erneuert sie nur bei expliziter Anforderung.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `bool $force True, wenn bestehende Trigger verworfen und neu erstellt werden sollen.`
- `array $log Protokollpuffer, wird per Referenz ergaenzt.`

**Rückgabe:** `void`

> **Hinweis:** Das bedingte Vorgehen vermeidet ein DROP/CREATE bei jedem Aufruf und damit ein Zeitfenster,

#### `live_app.scripts.migrate.app_migration_run`
Fuehrt die vollstaendige, idempotente Schema-Migration aus.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $options Optionen: force_triggers (bool) erzwingt das Neuerstellen aller Audit-Trigger.`

**Rückgabe:** `array{status:string,log:array<int,string>} Status und Protokoll des Migrationslaufs.`

**Exceptions:**
- `PDOException Wenn ein Pflichtschritt, etwa das Anlegen der Basistabellen, fehlschlaegt.`

#### `live_app.scripts.migrate.app_migration_maybe_run`
Fuehrt die Migration nur bei fehlender Marker-Datei aus und schuetzt sie per File-Lock.

**Parameter:**
- `PDO $pdo Aktive Datenbankverbindung.`
- `array $options Optionen: force (bool) ignoriert die Marker-Datei, force_triggers (bool)`

**Rückgabe:** `array{status:string,marker_written:bool,log:array<int,string>} Ergebnis des Aufrufs.`

> **Hinweis:** Bei einem Timeout des File-Locks wird die Migration bewusst uebersprungen, da ein anderer

#### `live_app.scripts.migrate.app_migration_cli_main`
Wertet Kommandozeilenparameter aus, fuehrt die Migration aus und gibt das Protokoll aus.

**Parameter:**
- `array<int,string> $arguments Argumentliste ohne Skriptnamen.`

**Rückgabe:** `int Prozess-Rueckgabewert, 0 bei Erfolg.`

#### `live_app.scripts.migrate.app_migration_handle_direct_cli_call`
Erkennt einen direkten CLI-Aufruf dieser Datei und startet dann die Migration.


**Rückgabe:** `void`

> **Hinweis:** Wird diese Datei von db_connect.php eingebunden, unterscheidet sich SCRIPT_FILENAME vom

---

## Modul: `live_app.scripts.privacy_cleanup` (script)
**Datei:** `live_app\scripts\privacy_cleanup.php`  
**Zweck:** Bereinigt personenbezogene Daten nach definierten Datenschutzregeln oder Fristen.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI- oder Scheduler-Aufruf. |
| **Eingaben (`@doc-input`)** | Datenbank, Konfiguration und Retentionsregeln. |
| **Ausgaben (`@doc-output`)** | Statusausgaben der Bereinigung. |
| **Seiteneffekte (`@doc-side-effects`)** | Aendert oder anonymisiert Datenbankinhalte. |

---

## Modul: `live_app.seed_test_data` (dev-tool)
**Datei:** `live_app\seed_test_data.php`  
**Zweck:** Erzeugt umfangreiche, qualitativ hochwertige Testdaten fuer alle Funktionsbereiche des 24h-Schwimmen-Systems.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | CLI- oder Browser-Aufruf: php seed_test_data.php |
| **Eingaben (`@doc-input`)** | Bestehende Datenbankverbindung aus config/.env. |
| **Ausgaben (`@doc-output`)** | Testdatensaetze in teams, teilnehmer, startnummern, users, lane_timeframes, access_tokens. |
| **Seiteneffekte (`@doc-side-effects`)** | Fuegt Testdaten in die Datenbank ein. Bestehende Daten werden NICHT geloescht (INSERT IGNORE). |

---

## Modul: `live_app.sync` (page-controller)
**Datei:** `live_app\sync.php`  
**Zweck:** Synchronisiert bezahlte Teilnehmer mit Startnummer aus der MySQL-Datenbank in die SQLite-Live-Schwimmdatenbank.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch berechtigte Benutzer (orga/numbers/admin). |
| **Eingaben (`@doc-input`)** | MySQL-Tabelle teilnehmer, SQLite-Tabelle schwimmer, Session-Berechtigungen. |
| **Ausgaben (`@doc-output`)** | Vorschau oder Sync-Bericht als HTML. |
| **Seiteneffekte (`@doc-side-effects`)** | Schreibt Stammdaten in die SQLite-Tabelle schwimmer; Live-Betriebsdaten (bahnanzahl, strecke, auf_bahn, aktiv) bleiben unveraendert. |

### Logikabschnitte & Abläufe
#### Abschnitt `access-control`
- **Zweck:** Der Sync veraendert die Live-Stammdaten und ist daher auf Rollen mit Bearbeitungsrecht beschraenkt.

#### Abschnitt `request-handling`

### Funktionen
#### `sync_sqlite_path`
Delegiert die Pfadaufloesung an die zentrale Quelle sqlite_live_default_file_path().


**Rückgabe:** `string Absoluter Pfad zur data.sqlite im Projektstamm.`

#### `live_app.sync.sync_escape`
Escaped einen Wert fuer die sichere Ausgabe im HTML der Sync-Seite.

**Parameter:**
- `?string $value Rohwert, der HTML-sicher ausgegeben werden soll.`

**Rückgabe:** `string HTML-escaped Wert; null wird dabei als leerer String behandelt.`

#### `live_app.sync.sync_load_candidates`
Laedt alle bezahlten Teilnehmer mit vergebener Startnummer als Sync-Kandidaten aus MySQL.

**Parameter:**
- `PDO $pdo MySQL-Verbindung zur Teilnehmerdatenbank.`

**Rückgabe:** `list<array<string, mixed>> Kandidatendatensaetze, sortiert nach Startnummer aufsteigend.`

> **Hinweis:** Nur bezahlte Teilnehmer mit vergebener Startnummer gelangen in die Live-Zeitnahme.

#### `sync_open_sqlite`
Delegiert das Oeffnen an die zentrale Factory sqlite_live_open() (inkl. Concurrency-PRAGMAs).

**Parameter:**
- `string $path Absoluter Pfad zur SQLite-Datei.`

**Rückgabe:** `PDO|null Schreibende Verbindung oder null, wenn die Datei fehlt.`

#### `live_app.sync.sync_existing_numbers`
Laedt die bestehenden schwimmer-Zeilen aus SQLite als Map von Startnummer auf Datensatz.

**Parameter:**
- `PDO $sqlite SQLite-Verbindung zur Live-Datenbank.`

**Rückgabe:** `array<int, array<string, mixed>> Map von Startnummer auf den zugehoerigen schwimmer-Datensatz.`

#### `live_app.sync.sync_run`
Fuehrt den eigentlichen Sync der Kandidaten in die SQLite-Live-Datenbank innerhalb einer Transaktion aus.

**Parameter:**
- `PDO $mysql MySQL-Quellverbindung mit den Teilnehmerdaten.`
- `PDO $sqlite SQLite-Zielverbindung zur Live-Datenbank.`

**Rückgabe:** `array{inserted:int,updated:int,total_candidates:int,details:list<array<string,mixed>>} Bericht mit Anzahl eingefuegter und aktualisierter Zeilen sowie Detailprotokoll.`

**Exceptions:**
- `Throwable Wenn das Schreiben fehlschlaegt; die Transaktion wird dann zurueckgerollt und der Fehler weitergereicht.`

> **Hinweis:** Aktualisierungen fassen nur Stammdaten an; bahnanzahl, strecke, auf_bahn und aktiv bleiben unveraendert.

#### `live_app.sync.sync_preview`
Berechnet die Sync-Vorschau ohne Schreibzugriffe auf die Live-Datenbank.

**Parameter:**
- `PDO $mysql MySQL-Quellverbindung mit den Teilnehmerdaten.`
- `PDO $sqlite SQLite-Zielverbindung zur Live-Datenbank.`

**Rückgabe:** `array{candidates:int,to_insert:list<array<string,mixed>>,to_update:list<array<string,mixed>>,existing_count:int} Vorschau mit Kandidatenzahl, neu anzulegenden und zu aktualisierenden Eintraegen sowie Bestandszahl.`

---

## Modul: `live_app.timeframes` (page-controller)
**Datei:** `live_app\timeframes.php`  
**Zweck:** Pflegt Zeitfenster fuer spaetere Live-Auswertungen nach Abschnitt oder Schicht.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf durch berechtigte Benutzer. |
| **Eingaben (`@doc-input`)** | Session, Berechtigungen, Formularwerte und Datenbank. |
| **Ausgaben (`@doc-output`)** | HTML oder Aktionsantworten fuer Zeitfenster. |
| **Seiteneffekte (`@doc-side-effects`)** | Legt Zeitfenster an, aendert sie oder deaktiviert sie. |

### Funktionen
#### `live_app.timeframes.tf_dt_local_date`
Formatiert einen Datenbank-Zeitstempel als lokales Datum fuer Formularfelder.

**Parameter:**
- `string|null $dbValue Datenbankwert oder null.`

**Rückgabe:** `string Datum im Format Y-m-d oder Leerstring.`

#### `live_app.timeframes.tf_dt_local_time`
Formatiert einen Datenbank-Zeitstempel als lokale Uhrzeit fuer Formularfelder.

**Parameter:**
- `string|null $dbValue Datenbankwert oder null.`

**Rückgabe:** `string Uhrzeit im Format H:i oder Leerstring.`

#### `live_app.timeframes.tf_dt_display`
Formatiert einen Datenbank-Zeitstempel menschenlesbar fuer die Tabellenansicht.

**Parameter:**
- `string|null $dbValue Datenbankwert oder null.`

**Rückgabe:** `string Datum im Format d.m.Y H:i, '-' bei leerem Wert oder Originalwert bei ungueltigem Datum.`

#### `live_app.timeframes.tf_dt_db`
Setzt Datum und Uhrzeit zu einem internen Datenbank-Zeitstempel zusammen.

**Parameter:**
- `string $dateValue Datumswert aus dem Formular.`
- `string $timeValue Uhrzeitwert aus dem Formular.`
- `bool $isEnd Kennzeichnet die Endzeit; dann wird die letzte Minute (:59) gesetzt.`

**Rückgabe:** `string|null Zeitstempel im Format Y-m-d H:i:s oder null bei fehlenden oder ungueltigen Werten.`

#### `live_app.timeframes.tf_escape`
Wandelt einen Rohwert HTML-sicher fuer die Zeitfensteransicht um.

**Parameter:**
- `string $value Rohwert.`

**Rückgabe:** `string HTML-escaped Wert.`

#### `live_app.timeframes.tf_row_overlap`
Ermittelt Paare sich ueberlappender aktiver Zeitfenster zur Konfliktanzeige.

**Parameter:**
- `array $rows Liste der Zeitfenster-Zeilen.`

**Rückgabe:** `array Liste der ueberlappenden Paare aus je zwei Zeitfenster-Bereichen.`

---

## Modul: `live_web.bootstrap` (bootstrap)
**Datei:** `live_web\_bootstrap.php`  
**Zweck:** Lokalisiert den privaten Anwendungscode und stellt einen sicheren Loader fuer oeffentliche Wrapper-Routen bereit.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Wird von oeffentlichen PHP-Entry-Points eingebunden. |
| **Eingaben (`@doc-input`)** | Umgebungsvariable APP_PRIVATE_ROOT und Dateisystem. |
| **Ausgaben (`@doc-output`)** | Geladene private PHP-Datei oder HTTP-500 bei Fehlkonfiguration. |
| **Seiteneffekte (`@doc-side-effects`)** | Prueft Verzeichnisse und bindet Zielcode ein. |

### Funktionen
#### `live_web.bootstrap.app_private_root`
Lokalisiert den privaten Code ueber die Umgebungsvariable APP_PRIVATE_ROOT oder bekannte Kandidatenpfade.


**Rückgabe:** `string Absoluter Pfad zum privaten Wurzelverzeichnis.`

> **Hinweis:** Bricht mit HTTP 500 ab, wenn keine gueltige Wurzel gefunden wird.

#### `live_web.bootstrap.app_require_private`
Kapselt das sichere Einbinden privater Zielrouten fuer oeffentliche Wrapper.

**Parameter:**
- `string $relativePath Pfad relativ zum privaten Wurzelverzeichnis.`

**Rückgabe:** `void`

> **Hinweis:** Bricht mit HTTP 500 ab, wenn die Zieldatei fehlt.

---

## Modul: `live_web.admin` (entrypoint-proxy)
**Datei:** `live_web\admin.php`  
**Zweck:** Oeffentliche Admin-Route, die an die private Admin-Implementierung weiterreicht.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /admin.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | Admin-Oberflaeche oder JSON-Antwort der Adminlogik. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.assets.app` (frontend-controller)
**Datei:** `live_web\assets\app.js`  
**Zweck:** Initialisiert globale UI-Helfer, Modale, Tabellenwerkzeuge und AJAX-Aktionen der Weboberflaeche.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Browser laedt die zentrale Frontend-Datei. |
| **Eingaben (`@doc-input`)** | DOM, Fetch-Endpunkte, LocalStorage und CSS-Klassen. |
| **Ausgaben (`@doc-output`)** | Interaktive Bedienlogik fuer Admin- und Dashboard-Seiten. |
| **Seiteneffekte (`@doc-side-effects`)** | Veraendert DOM, sendet HTTP-Requests und speichert UI-Zustand im Browser. |

### Logikabschnitte & Abläufe
#### Abschnitt `alerts`
- **Zweck:** Steuert das automatische Ausblenden neuer und bestehender Statusmeldungen.

#### Abschnitt `display-mode`
- **Zweck:** Erkennt die verfuegbare Anzeigebreite und markiert die Seite explizit als Mobil- oder Desktopmodus.

#### Abschnitt `registration-autoscroll`
- **Zweck:** Scrollt nach dem Laden sanft bis knapp unter das DLRG-Logo, falls der eigentliche Seiteninhalt sonst noch verdeckt waere.

#### Abschnitt `team-selection`
- **Zweck:** Steuert die Umschaltung zwischen bestehender Teamauswahl und Eingabe eines neuen Teams.

#### Abschnitt `modal-base`
- **Zweck:** Kapselt das generische Oeffnen und Schliessen von Modalen inklusive Fokusfuehrung.

#### Abschnitt `participant-edit`
- **Zweck:** Oeffnet die Bearbeitung eines Teilnehmers, befuellt das Formular und uebernimmt Aenderungen per AJAX.

#### Abschnitt `participant-delete`
- **Zweck:** Loescht einen Teilnehmer nach Rueckfrage und aktualisiert die Liste direkt im Browser.

#### Abschnitt `table-state-storage`
- **Zweck:** Verwaltet den gespeicherten Tabellenzustand fuer Sichtbarkeit und stabile Identifikation pro Tabelle.

#### Abschnitt `data-table-enhancement`
- **Zweck:** Erweitert Tabellen um Filter, Sortierung, Spaltenauswahl und Drag-and-drop.
- **Seiteneffekte:** Liest und schreibt Tabellenzustand in LocalStorage und veraendert DOM-Struktur.

#### Abschnitt `admin-navigation`
- **Zweck:** Schaltet zwischen Admin-Bereichen um, spiegelt den aktiven Abschnitt im URL-Hash und fuellt die Sidebar-Sprungmarken.

#### Abschnitt `team-management`
- **Zweck:** Verwaltet Teammitglieder in einem Modal und verbindet Suche, Zuordnung und Entfernen per AJAX.

#### Abschnitt `audit-log`
- **Zweck:** Laedt, filtert, erweitert und loescht Audit-Eintraege in der Admin-Oberflaeche.

---

## Modul: `live_web.assets.style` (style)
**Datei:** `live_web\assets\style.css`  
**Zweck:** Definiert das visuelle Grundlayout der oeffentlichen und internen Weboberflaeche.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | Browser laedt das zentrale Stylesheet. |
| **Eingaben (`@doc-input`)** | HTML-Struktur, CSS-Variablen und Komponentenklassen. |
| **Ausgaben (`@doc-output`)** | Gestylte Layouts, Formulare, Karten und Tabellen. |
| **Seiteneffekte (`@doc-side-effects`)** | Beeinflusst Darstellung, Responsivitaet und visuelle Priorisierung. |

### Logikabschnitte & Abläufe
#### Abschnitt `admin-shell-layout */`
- **Zweck:** Strukturiert den oberen Admin-Rahmen in klar getrennte Bereiche fuer Status, Suche und Navigation. */

#### Abschnitt `participant-table-actions */`
- **Zweck:** Gestaltet die Sticky-Aktionsspalte der Teilnehmer-Tabelle passend zum restlichen Karten- und Tabellenstil. */

---

## Modul: `live_web.dashboard.dashboard` (entrypoint-proxy)
**Datei:** `live_web\dashboard\dashboard.php`  
**Zweck:** Oeffentliche Dashboard-Route fuer die interne Live-Uebersicht mit Delegation in die private Anwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /dashboard/dashboard.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | HTML des Dashboards. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.dashboard.eventlifkiosk` (entrypoint-proxy)
**Datei:** `live_web\dashboard\eventlifkiosk.php`  
**Zweck:** Oeffentliche Dashboard-Kioskroute mit Delegation in die private Anwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /dashboard/eventlifkiosk.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | HTML der Dashboard-Kioskansicht. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.dashboard.json_live` (entrypoint-proxy)
**Datei:** `live_web\dashboard\json_live.php`  
**Zweck:** Oeffentliche JSON-Live-Route fuer Dashboarddaten mit Delegation in die private Anwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /dashboard/json_live.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | JSON-Live-Daten fuer Frontends. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.eventlifkiosk` (entrypoint-proxy)
**Datei:** `live_web\eventlifkiosk.php`  
**Zweck:** Oeffentliche Route fuer die Event-Live-Kioskansicht mit Delegation in die private Anwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /eventlifkiosk.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | HTML der Kioskansicht. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.export` (entrypoint-proxy)
**Datei:** `live_web\export.php`  
**Zweck:** Oeffentliche Export-Route, die in die private Exportlogik delegiert.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /export.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | Dateidownload oder Exportansicht. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.impressum` (entrypoint-proxy)
**Datei:** `live_web\impressum.php`  
**Zweck:** Oeffentliche Impressumsroute, die die private Seitenimplementierung ausliefert.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /impressum.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | HTML-Seite mit rechtlichen Informationen. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.index` (entrypoint-proxy)
**Datei:** `live_web\index.php`  
**Zweck:** Oeffentliche Startseiten-Route, die in die private Anwendungsimplementierung delegiert.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /index.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | HTML der Startseite. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.numbers` (entrypoint-proxy)
**Datei:** `live_web\numbers.php`  
**Zweck:** Oeffentliche Route fuer die Startnummernverwaltung mit Delegation in die private Anwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /numbers.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | HTML oder Aktionsantworten fuer die Startnummernverwaltung. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.sync` (entrypoint-proxy)
**Datei:** `live_web\sync.php`  
**Zweck:** Oeffentliche Sync-Route, die in die private Sync-Logik delegiert.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /sync.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | Vorschau oder Sync-Bericht. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---

## Modul: `live_web.timeframes` (entrypoint-proxy)
**Datei:** `live_web\timeframes.php`  
**Zweck:** Oeffentliche Route fuer die Pflege von Zeitfenstern mit Delegation in die private Anwendung.

| Eigenschaft | Beschreibung |
| :--- | :--- |
| **Einstieg (`@doc-entry`)** | HTTP-Aufruf auf /timeframes.php. |
| **Eingaben (`@doc-input`)** | Bootstrap-Datei und private Zielroute. |
| **Ausgaben (`@doc-output`)** | HTML oder Aktionsantworten fuer Zeitfenster. |
| **Seiteneffekte (`@doc-side-effects`)** | Laedt Bootstrap und bindet private Zielseite ein. |

---
