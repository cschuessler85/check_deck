# Debug Session: kiosk-no-data

Status: OPEN

## Symptom
- Der Eventkiosk laedt, zeigt aber weiterhin keine nutzbaren Daten an.

## Ziel
- Mit Laufzeitevidenz feststellen, warum der Kiosk trotz vorhandener SQLite-Daten leer bleibt.

## Hypothesen
- H1: Der Server nutzt zur Laufzeit nicht die aktuelle `functions.php`, sondern noch eine alte Deploy-Version.
- H2: Die Kiosk-Seite erhaelt von `eventlifkiosk.php?format=json` ein Fehler- oder Leerpayload, obwohl die lokale Probedatei Daten liefert.
- H3: Die Datenquelle wird zur Laufzeit zwar gefunden, aber `auth`/Wrapper/Bootstrap blockiert oder veraendert die Antwort vor dem Rendern.
- H4: Das Frontend bekommt Daten, verwirft sie aber wegen lokaler Slide-/Spalten-Konfiguration oder leerer `visibleSlides`.
- H5: Der Serverprozess kann den fest eingetragenen SQLite-Pfad auf dem Zielsystem nicht lesen, obwohl der Pfad lokal auf dem Entwicklungsrechner existiert.

## Evidence Log
- `eventlifkiosk.php?format=json` liefert:
  - `ok=false`
  - `source_mode=sqlite`
  - `source_path=D:\Coding\peter\24h_python\data.sqlite`
  - `error=SQLite-Datei nicht gefunden.`

## Hypothesis Status
- H1: Offen. Noch nicht bewiesen, aber die neue SQLite-Logik laeuft offenbar, weil `source_mode=sqlite` im Payload erscheint.
- H2: Bestaetigt. Der Endpoint liefert ein Fehlerpayload statt Nutzdaten.
- H3: Widerlegt. Wrapper/Auth sind nicht die primaere Ursache, da ein strukturiertes JSON mit konkretem Dateifehler zurueckkommt.
- H4: Widerlegt. Das Problem liegt vor dem Frontend-Rendering, schon im Payload.
- H5: Bestaetigt. Der zur Laufzeit verwendete SQLite-Pfad ist auf dem Zielsystem nicht vorhanden oder nicht lesbar.

## Next Step
- Den echten absoluten SQLite-Pfad auf dem Zielsystem ermitteln und die Quellenkonfiguration darauf umstellen.
