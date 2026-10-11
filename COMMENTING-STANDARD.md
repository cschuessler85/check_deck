# Kommentarstandard fuer maschinenlesbare Doku

Dieses Projekt nutzt einen einheitlichen Kommentarstandard, damit Menschen und Tools denselben Code schnell verstehen koennen.

## Ziele

- Jede Eigen-Datei beginnt mit einem maschinenlesbaren Modulkopf.
- Wichtige Logikabschnitte sind mit stabilen Markern versehen.
- UI-Aktionen lassen sich als Click-to-Click-Anleitung extrahieren.
- Kommentare beschreiben Zweck, Eingaben, Ausgaben und Seiteneffekte statt offensichtliche Syntax.

## Ausgeschlossen

- Fremdcode in `vendor`
- Minifizierte Dateien wie `*.min.js`
- Binare Dateien, Dumps, Logdateien und automatisch erzeugte Artefakte

## Pflichtmarker

### Modulkopf

Jede bearbeitete Quellcodedatei startet mit einem Block im jeweiligen Sprachformat.

Pflichtfelder:

- `@doc-file`: Kennzeichnet den Beginn eines Dokumentationsblocks.
- `@doc-id`: Stabiler, eindeutiger Bezeichner.
- `@doc-kind`: Typ der Datei, zum Beispiel `entrypoint`, `entrypoint-proxy`, `page`, `shared-library`, `script`, `style`.
- `@doc-purpose`: Fachlicher Zweck der Datei in einem Satz.
- `@doc-entry`: Einstiegsbedingung oder Aufrufkontext.
- `@doc-input`: Wichtigste Eingaben oder Abhaengigkeiten.
- `@doc-output`: Wichtigste Ausgabe oder Wirkung.
- `@doc-side-effects`: Persistenz, Redirects, Session, HTTP oder Dateisystem.

Optionale Felder:

- `@doc-source`
- `@doc-notes`
- `@doc-see`

### Ablaufmarker

Fuer groessere Dateien werden zusaetzlich Abschnittsmarker verwendet:

- `@doc-section <id>`: Grober Bereich innerhalb der Datei.
- `@doc-purpose <text>`: Zweck des Abschnitts.
- `@doc-step <nummer> <text>`: Ablaufreihenfolge fuer Prozess- oder UI-Doku.
- `@doc-trigger <text>`: Ausloeser wie Request, Klick oder Cron.
- `@doc-input <text>`: Abschnittseingaben.
- `@doc-output <text>`: Abschnittsausgaben.
- `@doc-side-effects <text>`: Datenbank, Session, Navigation, DOM, Netzwerk.

### Click-to-Click-Marker

Fuer UI-Flows werden Click-to-Click-Anleitungen ueber folgende Marker beschrieben:

- `@doc-ui-flow <id>`: Stabiler Bezeichner eines Bedienablaufs.
- `@doc-ui-step <nummer> <aktion>`: Einzelner sichtbarer Bedien-Schritt.
- `@doc-ui-target <selector oder element-id>`: Betroffenes Element.
- `@doc-ui-result <text>`: Erwartetes sichtbares Ergebnis.

## Extraktionsregeln

- Parser sollen nur Zeilen mit `@doc-` auswerten.
- Zusammenhaengende Kommentarblöcke bilden jeweils ein Dokuobjekt.
- `@doc-file` startet ein neues Modulobjekt.
- `@doc-section` startet einen Unterabschnitt innerhalb des aktuellen Moduls.
- Wiederholte Felder wie `@doc-step` und `@doc-ui-step` werden als Listen gesammelt.
- Nicht ausgezeichnete Kommentare bleiben menschliche Hilfen und muessen nicht extrahiert werden.

## Beispiele

### PHP

```php
/**
 * @doc-file
 * @doc-id live_web.index
 * @doc-kind entrypoint-proxy
 * @doc-purpose Oeffentliche Route fuer die Startseite und Delegation in den privaten Anwendungscode.
 * @doc-entry HTTP GET auf /index.php
 * @doc-input Bootstrap-Datei und private Zielroute
 * @doc-output HTML der Startseite
 * @doc-side-effects Laedt Bootstrap und bindet private Anwendungsdatei ein
 */
```

### JavaScript

```js
/**
 * @doc-file
 * @doc-id live_web.assets.app
 * @doc-kind frontend-controller
 * @doc-purpose Initialisiert globale UI-Helfer, Tabellenfunktionen, Modale und AJAX-Aktionen.
 * @doc-entry Browser laedt die zentrale Frontend-Datei
 * @doc-input DOM, Fetch-Endpunkte, LocalStorage
 * @doc-output Interaktive Admin-Oberflaeche
 * @doc-side-effects Veraendert DOM, liest und schreibt LocalStorage, sendet HTTP-Requests
 */
```

### Python

```python
"""
@doc-file
@doc-id live_web.merge_schwimmerdaten
@doc-kind cli-script
@doc-purpose Fuehrt CSV-Stammdaten und JSON-Eventlog zu einer aktualisierten Ausgabedatei zusammen.
@doc-entry CLI-Aufruf ueber Python
@doc-input CSV-Datei, JSON-Backup, optionale Zeitfensterdatei
@doc-output Aktualisierte CSV-Datei
@doc-side-effects Liest und schreibt Dateien
"""
```

## Benennungsregeln

- `@doc-id` nutzt Punktnotation nach Ordnerstruktur.
- Begriffe bleiben fachlich stabil und moeglichst sprechend.
- `@doc-purpose` beschreibt warum, nicht nur was.
- `@doc-step` beschreibt Reihenfolge neutral und ohne Implementierungsdetails.

## Pflegehinweise

- Bei jeder neuen Datei zuerst Modulkopf einfuegen.
- Bei neuen groesseren Bloecken mindestens einen `@doc-section` setzen.
- Bei neuen UI-Aktionen immer pruefen, ob ein `@doc-ui-flow` benoetigt wird.
