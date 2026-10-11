# Machbarkeitsbericht: PayPal-Integration als zusätzliche Zahlungsmethode

**Projekt:** 24-Stunden-Schwimmen DLRG Sankt Augustin e.V.
**Erstellungsdatum:** 2026-10-11
**Geprüft von:** Software-Audit
**Geltungsbereich:** Ergänzung des bestehenden Zahlungssystems um PayPal als Online-Zahlungsmethode

---

## 1. Ausgangslage

### 1.1 Bestehende Zahlungsarchitektur

Das aktuelle System verfügt über **kein integriertes elektronisches Zahlungsgateway**. Der Zahlungsfluss ist rein manuell und für die Präsenzveranstaltung konzipiert:

| Schritt | Beschreibung |
|---|---|
| 1 | Teilnehmer meldet sich über `live_app/index.php` an (Vorname, Nachname, Team, etc.) |
| 2 | Es wird ein `code` (Registrierungscode mit Luhn-Prüfziffer) generiert |
| 3 | Teilnehmer erhält den Code (Ausdruck / Vorzeigen am Veranstaltungstag) |
| 4 | An der Kasse (`live_app/admin.php`, Rolle `cashier`) wird der Code gescannt/eingegeben |
| 5 | Kassierer:in markiert den Teilnehmer manuell als `bezahlt = 1` |
| 6 | Gleichzeitig wird die nächste freie `startnummer` vergeben |

### 1.2 Datenbankschema (relevant)

Tabelle `teilnehmer` ([live_app/setup.sql](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/setup.sql#L28)) enthält aktuell **keine** Felder für:
- Zahlungsbetrag (`betrag` / `amount`)
- Zahlungsmethode (`zahlungsmethode`)
- Transaktions-ID (`payment_id` / `transaction_id`)
- Zahlungsstatus-Detail (nur `bezahlt` TINYINT und `bezahlt_am`)
- Währung

### 1.3 Technischer Stack

| Komponente | Status |
|---|---|
| PHP | `8.0.0` (Composer-Platform-Pinned) |
| Datenbank | MySQL/MariaDB (`utf8mb4`) |
| Abhängigkeiten | nur `mpdf/mpdf` (PDF-Erzeugung) |
| HTTP-Client | `cURL` nicht in `composer.json`, aber auf Produktivserver üblicherweise vorhanden |
| Session/Auth | Eigenes RBAC-System (`auth_can`, Rollen: admin, cashier, orga, viewer) |
| Webserver | Apache mit `.htaccess` |
| TLS | HTTPS-Erzwingung im Webroot |

### 1.4 Rechtliche Entität

Betreiber ist der **DLRG Ortsgruppe St. Augustin e.V.** (eingetragener Verein, [live_app/impressum.php](file:///d:/Coding/DLRG_24/24h-Schwimmen/live_app/impressum.php)). Ein Verein kann grundsätzlich ein PayPal-Geschäftskonto eröffnen.

---

## 2. Technische Voraussetzungen & Kompatibilität

### 2.1 PayPal-Integrationsoptionen

Für die Integration stehen drei wesentliche Ansätze zur Verfügung:

| Methode | Beschreibung | Eignung |
|---|---|---|
| **PayPal Checkout (Smart Payment Buttons)** | JS-SDK rendert PayPal-Button auf der Seite; Backend erzeugt Order über REST API v2 | ⭐ Empfohlen |
| **PayPal REST API v2 (serverseitig)** | Komplette Steuerung über API-Aufrufe (Create Order, Capture Order) | Flexibel, mehr Aufwand |
| **PayPal Payments Standard (HTML-Form)** | Einfache Formular-Weiterleitung an PayPal | Veraltet, keine SCA-Unterstützung |

**Empfehlung:** Kombination aus **JS-SDK (Frontend)** und **REST API v2 (Backend)** für `createOrder` und `captureOrder`. Dies erfüllt die PSD2/SCA-Anforderungen, da PayPal die Authentifizierung des Käufers (3D-Secure) übernimmt.

### 2.2 Technische Anforderungen an die Infrastruktur

| Anforderung | Status im Projekt | Bewertung |
|---|---|---|
| PHP ≥ 7.4 | ✅ 8.0 | erfüllt |
| cURL-Extension | ⚠️ nicht explizit geprüft | Auf Managed-Hosting (Strato) standardmäßig aktiv; muss verifiziert werden |
| TLS 1.2+ nach `api-m.paypal.com` | ✅ HTTPS aktiv | erfüllt |
| Öffentlich erreichbarer Webhook-Endpoint | ⚠️ benötigt | Server muss von PayPal erreichbar sein (`*.paypal.com` IP-Bereiche) |
| Server-Zeit synchron (NTP) | ⚠️ benötigt | Für Webhook-Signaturprüfung und Token-Ablauf |
| PHP `openssl` | ✅ Standard | erfüllt |

### 2.3 Erforderliche Schema-Erweiterungen

Für die PayPal-Integration müssen mindestens folgende Felder/Tabellen ergänzt werden:

```sql
-- Erweiterung der teilnehmer-Tabelle
ALTER TABLE teilnehmer
  ADD COLUMN betrag DECIMAL(8,2) DEFAULT NULL COMMENT 'Startgebühr in EUR',
  ADD COLUMN zahlungsmethode ENUM('bar','paypal','ueberweisung') DEFAULT NULL,
  ADD COLUMN paypal_order_id VARCHAR(64) DEFAULT NULL,
  ADD COLUMN paypal_capture_id VARCHAR(64) DEFAULT NULL,
  ADD COLUMN paypal_status ENUM('created','approved','captured','failed','refunded') DEFAULT NULL;

-- Optional: eigene Zahlungstransaktionstabelle für Auditierbarkeit
CREATE TABLE zahlungen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teilnehmer_id INT NOT NULL,
  methode ENUM('bar','paypal','ueberweisung') NOT NULL,
  betrag DECIMAL(8,2) NOT NULL,
  waehrung CHAR(3) NOT NULL DEFAULT 'EUR',
  paypal_order_id VARCHAR(64),
  paypal_capture_id VARCHAR(64),
  status ENUM('pending','captured','failed','refunded') NOT NULL DEFAULT 'pending',
  erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  abgeschlossen_am TIMESTAMP NULL,
  FOREIGN KEY (teilnehmer_id) REFERENCES teilnehmer(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_paypal_order (paypal_order_id)
);
```

### 2.4 Sicherheitsanforderungen

- **Client ID + Secret** müssen in `live_app/config/.env` (außerhalb Webroot) gespeichert werden
- **Webhook-Signaturprüfung** zwingend erforderlich (PayPal liefert 5 Signatur-Header + Webhook-ID; Verifikation über `/v1/notifications/verify-webhook-signature`)
- **Idempotenz**: Jede Transaktion darf nur einmal als `bezahlt` markiert werden (`UNIQUE KEY` auf `paypal_order_id`)
- **Keine Client-seitige Vertrauensstellung**: Der `capture`-Call muss serverseitig erfolgen; das Frontend darf nur `approve` auslösen
- **CSRF**: Der Checkout-Button benötigt ggf. einen separaten CSRF-Schutz pro Order

---

## 3. Rechtliche Rahmenbedingungen

### 3.1 Datenschutz (DSGVO)

#### 3.1.1 Datenübermittlung in die USA

PayPal (Europe) S.à r.l. et Cie, S.C.A. ist zwar in Luxemburg ansässig, aber die Muttergesellschaft PayPal Holdings, Inc. unterliegt US-Recht. **Risikopunkte:**

| Aspekt | Bewertung |
|---|---|
| CLOUD Act | ⚠️ US-Behörden können auf Daten zugreifen |
| EU-US Data Privacy Framework (DPF) | ⚠️ Seit 2023 in Kraft, aber vor dem EuGH angefochten; Bestand ungewiss |
| Standard Contractual Clauses (SCCs) | ✅ PayPal bietet AVV + SCCs an |
| Transfer Impact Assessment (TIA) | ⚠️ Muss vom Verein durchgeführt und dokumentiert werden |

**Maßnahmen:**
1. **Auftragsverarbeitungsvertrag (AVV)** mit PayPal abschließen (PayPal stellt diesen als "Data Processing Agreement" bereit)
2. **Standard Contractual Clauses (SCCs)** ergänzen
3. **Transfer Impact Assessment (TIA)** dokumentieren
4. **Datenschutzerklärung** erweitern: Nennung von PayPal als Auftragsverarbeiter, Umfang der übermittelten Daten (Vorname, Nachname, Betrag, Transaktions-ID), Rechtsgrundlage (Art. 6 Abs. 1 lit. b DSGVO – Vertragserfüllung)
5. **Minimierung**: Nur die für die Zahlung notwendigen Daten übermitteln (keine IP-Adressen, keine vollständigen Teamdaten)

#### 3.1.2 Aufbewahrungspflichten

| Rechtsgrundlage | Dauer | Betroffene Daten |
|---|---|---|
| § 257 HGB | 8–10 Jahre | Buchungsbelege, Kontoauszüge |
| § 8 GwG | 5 Jahre | Transaktionsdaten zur Geldwäschebekämpfung |
| § 256 ZAG | 5 Jahre | Zahlungsdienstleistungs-Daten |

**Folge:** PayPal-Transaktionsdaten (`paypal_order_id`, `paypal_capture_id`, Betrag, Zeitpunkt) müssen mindestens **10 Jahre** aufbewahrt werden. Ein Löschrecht der betroffenen Person (Art. 17 DSGVO) ist in diesen Fällen eingeschränkt.

### 3.2 Zahlungsdienstleistungsrecht (PSD2 / PSD3)

| Anforderung | Bewertung |
|---|---|
| **SCA (Strong Customer Authentication)** | ✅ PayPal übernimmt die 3D-Secure-Authentifizierung intern bei Nutzung des JS-SDK + REST API v2 |
| **Zugriff auf Zahlungskonten (XS2A)** | Nicht relevant (PayPal ist Zahlungsdienstleister, nicht die Bank des Nutzers) |
| **Haftung bei unbefugten Zahlungen** | PayPal trägt Haftung im Rahmen seiner AGB |
| **Rückbuchungsrecht** | Käufer haben 180 Tage Käuferschutz → Chargeback-Risiko |

### 3.3 PayPal-Nutzungsbedingungen (AGB)

#### 3.3.1 Aufschlagsverbot

> *"Als Händler dürfen Sie Ihren Kunden für die Nutzung der PayPal-Dienste keine Aufschläge oder 'Servicegebühren', höhere Versandkosten im Vergleich zu anderen Zahlungsmethoden oder sonstige Gebühren berechnen."*

**Folge:** Die PayPal-Gebühr (2,99 % + 0,39 €) **darf nicht** auf den Teilnehmer umgelegt werden. Der Verein muss diese aus dem Startbeitrag oder aus Vereinsmitteln tragen.

Zusätzlich verbietet **§ 270a BGB** Aufschläge für SEPA-Zahlungen und Kartenzahlungen – für PayPal als E-Geld-Institut ist die vertragliche Regelung maßgeblich, die ebenfalls Aufschläge untersagt.

#### 3.3.2 Gleichbehandlung der Zahlungsmethoden

PayPal darf im Checkout nicht schlechter gestellt werden als andere Zahlarten (Position, Logogröße, Konditionen).

#### 3.3.3 Gewerbliche Nutzung

Für den Verein (e.V.) ist ein **Geschäftskonto** erforderlich. Privat-PayPal-Konten ("Geld an Freunde und Familie") sind für gewerbliche Transaktionen **nicht** zulässig.

### 3.4 Vereinsrechtliche Aspekte

- Die Einführung einer neuen Zahlungsmethode und die damit verbundenen Kosten sollten **beschlussfähig** sein (Vorstandsbeschluss, ggf. Mitgliederversammlung bei höheren Kosten)
- Die Gebühren mindern den Vereinshaushalt – muss im Jahresfinanzplan berücksichtigt werden
- Eine **Gewinnerzielungsabsicht** liegt nicht vor (Vereinszweck), was steuerlich relevant ist

---

## 4. Wirtschaftliche Aspekte

### 4.1 Transaktionsgebühren (Stand Juli 2026)

| Transaktionsart | Gebühr | Anwendungsfall |
|---|---|---|
| **PayPal Checkout (Inland/EER)** | **2,99 % + 0,39 €** | Standard-Online-Zahlung |
| Zahlung ohne PayPal-Konto (Gast) | 2,99 % + 0,39 € | Kreditkartenzahlung via PayPal |
| Rechnungskauf (Ratepay) | 2,99 % + 0,39 € | Kauf auf Rechnung |
| Geld für Waren/Dienstleistungen | 2,49 % + 0,35 € | Direktzahlung ohne Checkout |
| QR-Code-Zahlung | 0,90 % | Vor-Ort-Zahlung (Alternative zur Kasse) |
| **Mikrozahlungen (< 15 €)** | 4,99 % + 0,09 € | Muss separat beantragt werden |
| Internationale Zahlung (UK) | +1,29 % Aufschlag | z. B. Teilnehmer aus Großbritannien |
| Internationale Zahlung (Restwelt) | +1,99 % Aufschlag | z. B. USA, Schweiz |
| Währungsumrechnung | 3 % über Mid-Market | Nur bei Nicht-EUR-Zahlungen |

### 4.2 Effektivgebühr bei verschiedenen Startbeiträgen

Annahme: Inlandszahlung, PayPal Checkout, 2,99 % + 0,39 €

| Startbeitrag | PayPal-Gebühr | Effektiv | Netto an Verein |
|---|---|---|---|
| 5,00 € | 0,54 € | 10,8 % | 4,46 € |
| 10,00 € | 0,69 € | 6,9 % | 9,31 € |
| 15,00 € | 0,84 € | 5,6 % | 14,16 € |
| 20,00 € | 0,99 € | 5,0 % | 19,01 € |
| 25,00 € | 1,14 € | 4,6 % | 23,86 € |
| 50,00 € | 1,89 € | 3,8 % | 48,11 € |

**Wirtschaftliche Empfehlung:** Bei einem Startbeitrag unter 10 € ist PayPal aufgrund der Festgebühr von 0,39 € unverhältnismäßig teuer. Ab einem Betrag von ≥ 15 € wird die effektive Gebühr tragbar.

### 4.3 Weitere Kosten

| Kostenart | Betrag | Hinweis |
|---|---|---|
| Monatsgebühr Geschäftskonto | 0,00 € | Standard-Geschäftskonto ist kostenlos |
| Rückerstattung (Refund) | Gebühr der Originalzahlung bleibt einbehalten | Keine zusätzliche Gebühr, aber ursprüngliche Gebühr wird nicht erstattet |
| Rückbuchung (Chargeback) | 16,00 € | Nur bei Streitigkeiten |
| Konfliktgebühr | 14,00 € | Bei Käuferschutz-Anträgen |
| Sofortüberweisung PayPal → Bank | ggf. Gebühr | Standard-Überweisung (3–5 Werktage) ist kostenlos |
| Entwicklungs- / Integrationsaufwand | 1–3 Personentage | Einmalig, je nach Komplexität |

### 4.4 Abwicklungszeiten

| Vorgang | Dauer |
|---|---|
| Zahlungseingang auf PayPal-Konto | sofort (bei erfolgreicher Transaktion) |
| Guthaben verfügbar | sofort auf PayPal-Konto |
| Auszahlung auf Vereinsbankkonto (Standard) | **3–5 Werktage** |
| Auszahlung (Sofort, Debitkarte) | wenige Minuten | mit Gebühr |
| Käuferschutz-Frist für Käufer | **180 Tage** | Rückbuchungsrisiko bis zu 6 Monate |

### 4.5 Wirtschaftliches Fazit

Bei einer angenommenen Teilnehmerzahl von ~500 und einem Startbeitrag von z. B. 15 €:

- Gesamtumsatz: 500 × 15 € = 7.500 €
- PayPal-Gebühren gesamt: 500 × 0,84 € = 420 € (5,6 %)
- Netto: 7.080 €

**Bewertung:** Bei einem Startbeitrag ≥ 15 € ist die Gebühr wirtschaftlich vertretbar. Bei < 10 € sollte entweder die Mikrozahlungsoption beantragt werden oder auf Barzahlung an der Kasse verwiesen werden.

---

## 5. Machbarkeitsbewertung

| Dimension | Bewertung | Begründung |
|---|---|---|
| **Technische Machbarkeit** | ✅ **Gegeben** | REST API v2 + JS-SDK sind ausgereift; PHP 8.0 + cURL ausreichend; Schema-Erweiterung überschaubar |
| **Rechtliche Machbarkeit** | ⚠️ **Bedingt** | DSGVO-konform mit AVV + SCCs + TIA möglich; Aufschlagsverbot beachten; Aufbewahrungspflichten einhalten |
| **Wirtschaftliche Machbarkeit** | ⚠️ **Bedingt** | Ab Startbeitrag ≥ 15 € vertretbar; darunter unverhältnismäßig teuer |
| **Operative Machbarkeit** | ✅ **Gegeben** | Kassierer:innen-Handhabung bleibt; Online-Zahlung entlastet die Kasse am Veranstaltungstag |

### Gesamtfazit

**Die Integration von PayPal ist machbar**, unter folgenden Voraussetzungen:

1. ✅ Der Startbeitrag beträgt mindestens 15 € (oder Mikrozahlungs-Konditionen werden beantragt)
2. ✅ Ein PayPal-Geschäftskonto für den Verein wird eingerichtet
3. ✅ AVV + SCCs mit PayPal werden abgeschlossen und eine TIA durchgeführt
4. ✅ Die Datenschutzerklärung wird erweitert
5. ✅ Das Datenbankschema wird um Zahlungsfelder erweitert
6. ✅ Ein Webhook-Endpoint für die asynchrone Bestätigung wird implementiert

**Empfehlung:** Ja, die Integration ist sinnvoll – sie entlastet die Kasse am Veranstaltungstag erheblich und ermöglicht eine Vorab-Zahlung, die Planungssicherheit schafft.

---

## 6. Implementierungsschritte (falls Machbarkeit bestätigt)

### Phase 1: Vorbereitung (1–2 Tage)

1. PayPal-Geschäftskonto für den Verein eröffnen
2. Developer-Account anlegen, REST-API-App erstellen → **Client ID** + **Secret**
3. Sandbox-Geschäftskonto und Sandbox-Käuferkonto einrichten
4. `PAYPAL_CLIENT_ID` und `PAYPAL_CLIENT_SECRET` in `live_app/config/.env` eintragen
5. AVV + SCCs unterzeichnen, TIA durchführen und dokumentieren
6. Datenschutzerklärung erweitern

### Phase 2: Backend-Implementierung (2–3 Tage)

1. **Schema-Migration**: `zahlungen`-Tabelle anlegen, `teilnehmer`-Felder ergänzen
2. **PayPal-Client-Klasse** (`live_app/includes/paypal.php`):
   - OAuth2-Token abrufen (`/v1/oauth2/token`)
   - `createOrder()` (`/v2/checkout/orders`)
   - `captureOrder()` (`/v2/checkout/orders/{id}/capture`)
   - `verifyWebhookSignature()`
3. **Endpoint `create_paypal_order.php`**:
   - Auth-check (Teilnehmer ist eingeloggt oder hat gültigen Code)
   - Betrag aus Konfiguration/DB laden
   - Order bei PayPal anlegen, `order_id` in DB speichern
   - `order_id` an Frontend zurückgeben
4. **Endpoint `capture_paypal.php`**:
   - Order capturen
   - Bei Erfolg: `teilnehmer.bezahlt = 1`, `zahlungsmethode = 'paypal'`, `startnummer` vergeben
   - Idempotent (bei doppeltem Capture keine doppelte Markierung)
5. **Webhook-Endpoint `paypal_webhook.php`**:
   - Signaturprüfung
   - Verarbeitung von `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.REFUNDED`, `CHECKOUT.ORDER.APPROVED`
   - Fallback, falls Client-seitiger Capture nicht ankam

### Phase 3: Frontend-Implementierung (1–2 Tage)

1. PayPal JS-SDK in `index.php` (Erfolgsseite nach Anmeldung) einbinden
2. Smart Payment Button rendern mit `createOrder` → Backend-Aufruf
3. `onApprove` → `captureOrder` → Backend-Aufruf
4. Erfolgs- / Fehlermeldungen anzeigen
5. Bei Erfolg: Teilnehmerdaten + Zahlungsbestätigung anzeigen (mit Download-Möglichkeit des Belegs)

### Phase 4: Admin-Erweiterungen (1 Tag)

1. In `admin.php` Kassen-Ansicht: Filter nach Zahlungsmethode (bar/paypal/offen)
2. Anzeige von PayPal-Transaktions-ID und Status
3. Manuelle Rückerstattungs-Funktion (nur für `paypal`-Zahlungen, mit Bestätigung)
4. Export (`export.php`): Zahlungsmethode und PayPal-ID ausgeben

### Phase 5: Test & Abnahme (1–2 Tage)

→ siehe Kapitel 7

---

## 7. Testverfahren

### 7.1 PayPal-Sandbox-Tests

| Testfall | Beschreibung | Erwartetes Ergebnis |
|---|---|---|
| **TS-01** | Erfolgreiche Zahlung mit Sandbox-Käuferkonto | Order created → approved → captured; `bezahlt=1`, `startnummer` vergeben |
| **TS-02** | Zahlung mit unzureichendem Guthaben | Capture schlägt fehl; Fehler an Nutzer; kein `bezahlt=1` |
| **TS-03** | Zahlungsabbruch durch Nutzer | Order verbleibt in `created`; kein `bezahlt=1` |
| **TS-04** | Doppeltes Capture (Idempotenz) | Zweiter Capture liefert bereits vorhandenen Status; keine doppelte Vergabe |
| **TS-05** | Webhook `PAYMENT.CAPTURE.COMPLETED` ohne Client-Capture | Status wird trotzdem auf `captured` gesetzt |
| **TS-06** | Webhook-Signaturprüfung (manipuliert) | Manipulierter Webhook wird abgelehnt (401) |
| **TS-07** | Rückerstattung (Refund) über PayPal-Dashboard | Status `refunded`, `bezahlt=0` (falls sinnvoll) |
| **TS-08** | Chargeback-Simulation | Chargeback-Gebühr wird protokolliert |

### 7.2 End-to-End-Tests

| Testfall | Beschreibung |
|---|---|
| **E2E-01** | Anmeldung → PayPal-Zahlung → Startnummer erhalten → Erfolgsseite |
| **E2E-02** | Anmeldung → Zahlungsabbruch → Erneuter Zahlungsversuch |
| **E2E-03** | Anmeldung mit PayPal → Admin sieht Teilnehmer als bezahlt |
| **E2E-04** | Sync (`sync.php`) übernimmt PayPal-bezahlte Teilnehmer korrekt in SQLite |
| **E2E-05** | Barzahlung an Kasse funktioniert weiterhin unverändert |
| **E2E-06** | Teilnehmer mit bereits vergebener Nummer kann nicht erneut PayPal-zahlen |

### 7.3 Sicherheitsprüfungen

| Testfall | Beschreibung |
|---|---|
| **SEC-01** | Manipulation des Betrags im Frontend → Backend ignoriert Client-Betrag, lädt aus DB |
| **SEC-02** | Webhook ohne gültige Signatur → 401 |
| **SEC-03** | Capture-Endpoint ohne vorangegangenen Order-Create → Fehler |
| **SEC-04** | CSRF-Token auf Order-Create vorhanden |
| **SEC-05** | Rate-Limiting auf Order-Create (verhindert Brute-Force / DoS) |
| **SEC-06** | Secrets nicht im Quelltext, nur in `.env` |
| **SEC-07** | Keine Kreditkartendaten werden auf dem Vereinsserver gespeichert (PayPal übernimmt) |
| **SEC-08** | TLS 1.2+ für alle PayPal-API-Aufrufe |
| **SEC-09** | Logging protokolliert keine PAN/PayPal-Anmeldedaten |

### 7.4 Last- & Performance-Tests

- Simultan 50 gleichzeitige Zahlungen → Race-Condition bei Startnummernvergabe prüfen
- Webhook-Verarbeitung bei 100 gleichzeitigen Events → Queue/Retry-Mechanismus

---

## 8. Qualitätsstandards

### 8.1 Code-Qualität

- **Kommentierung**: `@doc-file`-Modulkopf gemäß [COMMENTING-STANDARD.md](file:///d:/Coding/DLRG_24/24h-Schwimmen/COMMENTING-STANDARD.md) auf allen neuen PHP-Dateien
- **Keine Secrets im Code**: Alle Credentials ausschließlich über `.env`
- **Prepared Statements**: Alle DB-Zugriffe mit PDO-Prepared-Statements (konsistent zum bestehenden Code)
- **Fehlerbehandlung**: Try-Catch um alle PayPal-API-Aufrufe; keine `die()`-Aufrufe im Produktivcode

### 8.2 Datenschutz

- **Datenminimierung**: Nur Vorname, Nachname, Betrag, Transaktions-ID werden an PayPal übermittelt
- **Keine IP-Speicherung**: IP-Adressen von Zahlenden werden nicht protokolliert
- **Aufbewahrung**: Transaktionsdaten ≥ 10 Jahre (§ 257 HGB)
- **Löschbarkeit**: Außerhalb gesetzlicher Aufbewahrungspflichten müssen Daten auf Anfrage gelöscht werden können

### 8.3 Zuverlässigkeit

- **Webhook-Fallback**: Wenn der Client-seitige Capture fehlschlägt, muss der Webhook den Status korrigieren
- **Idempotenz**: Mehrfache Aufrufe mit derselben `paypal_order_id` führen zu keinem doppelten Effekt
- **Retry-Logik**: Bei PayPal-API-Fehlern (Timeout, 5xx) wird bis zu 3 Mal wiederholt

### 8.4 Monitoring & Logging

- Alle PayPal-API-Aufrufe werden mit Request-ID, Dauer und Status geloggt
- Webhook-Events werden mit Signatur-Status protokolliert
- Fehler (Capture failed, Webhook invalid) lösen eine Benachrichtigung aus (z. B. E-Mail an Admin)

### 8.5 Dokumentation

- `SERVER-SETUP.md` um PayPal-Installationsschritte erweitern
- `DEPLOY-TEST-PLAN.md` um PayPal-Testfälle erweitern
- Interne Dokumentation für Kassierer:innen: Unterschied Bar-/PayPal-Zahlung in der Admin-Oberfläche

---

## 9. Risiken & Gegenmaßnahmen

| Risiko | Wahrscheinlichkeit | Auswirkung | Gegenmaßnahme |
|---|---|---|---|
| Chargeback-Missbrauch | Mittel | Hoch | Klare Widerrufsbelehrung, Transparenz über Leistungen |
| Schrems-Urteil kippt DPF | Mittel | Mittel | SCCs + TIA als Fallback bereithalten |
| PayPal-Konto-Sperrung | Niedrig | Hoch | Backup-Zahlungsmethode (Bar) bleibt bestehen |
| Ausfall PayPal-API | Niedrig | Mittel | Fallback auf Barzahlung an der Kasse |
| DoS auf Checkout | Mittel | Mittel | Rate-Limiting, Captcha bei Mehrfachversuchen |
| Schema-Migration schlägt fehl | Niedrig | Hoch | Backup vor Migration, Test auf Kopie der DB |

---

## 10. Zusammenfassung & Empfehlung

| Frage | Antwort |
|---|---|
| Ist die Integration technisch machbar? | **Ja** – REST API v2 + JS-SDK sind kompatibel mit PHP 8.0 |
| Ist sie rechtlich vertretbar? | **Ja, mit Maßnahmen** – AVV, SCCs, TIA, erweiterte Datenschutzerklärung erforderlich |
| Ist sie wirtschaftlich sinnvoll? | **Ja, ab ≥ 15 € Startbeitrag** – darunter zu teuer |
| Aufwand | **ca. 5–9 Personentage** (Vorbereitung, Backend, Frontend, Admin, Tests) |
| Empfehlung | **Durchführen** – entlastet Kasse, erhöht Planungssicherheit, barrierearme Zahlung |

**Nächste Schritte bei Freigabe:**
1. Vorstandsbeschluss zur Einführung von PayPal als Zahlungsmethode einholen
2. PayPal-Geschäftskonto eröffnen
3. Schema-Migration und Backend-Implementierung beginnen
4. Sandbox-Tests durchführen
5. Datenschutzerklärung aktualisieren
6. Produktivschaltung mit begleitendem Monitoring

---

*Hinweis: Dieser Bericht ersetzt keine Rechts- oder Steuerberatung. Die rechtliche Prüfung (insbesondere DSGVO, TIA, Vereinsrecht) sollte vor der Implementierung von einer qualifizierten Stelle bestätigt werden. Die genannten PayPal-Gebühren beziehen sich auf den Stand Juli 2026 und können sich ändern – maßgeblich sind die Konditionen im eigenen PayPal-Geschäftskonto.*
