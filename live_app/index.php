<?php
/**
 * @doc-file
 * @doc-id live_app.index
 * @doc-kind page-controller
 * @doc-purpose Stellt die Hauptseite fuer Anmeldung und oeffentliche Eventinformationen bereit.
 * @doc-entry HTTP-Aufruf der Startseite.
 * @doc-input Session, Formularwerte, Datenbank und Konfiguration.
 * @doc-output HTML der oeffentlichen Hauptseite.
 * @doc-side-effects Kann Formulardaten verarbeiten und Persistenz ausloesen.
 */
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

$success = false;
$error = '';
$saved = [
    'vorname' => '',
    'nachname' => '',
    'volljaehrig' => null,
    'geschlecht' => '',
    'teamname' => '',
    'code' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
    } else {
        $vorname = registration_name_normalize((string)($_POST['vorname'] ?? ''), 100);
        $nachname = registration_name_normalize((string)($_POST['nachname'] ?? ''), 100);
        $volljaehrig_raw = $_POST['volljaehrig'] ?? null;
        $geschlecht = (string)($_POST['geschlecht'] ?? '');
        $team_select = (string)($_POST['team_select'] ?? '');
        $new_team = trim((string)($_POST['new_team'] ?? ''));
        $teilnahme_ok = isset($_POST['teilnahmebedingungen']) ? 1 : 0;
        $dsgvo_ok = isset($_POST['dsgvo']) ? 1 : 0;
        $bildton_ok = isset($_POST['bildton']) ? 1 : 0;

        $volljaehrig = null;
        if ($volljaehrig_raw === '1' || $volljaehrig_raw === '0') {
            $volljaehrig = (int)$volljaehrig_raw;
        }

        $team_id = null;
        $teamname = null;
        if ($team_select === '__NEW__') {
            $teamname = team_name_normalize($new_team);
            $team_id = $teamname !== null ? team_get_or_create_id($pdo, $teamname) : null;
        } elseif ($team_select !== '' && ctype_digit($team_select)) {
            $team_id = (int)$team_select;
            $teamname = team_get_name_by_id($pdo, $team_id);
            if ($teamname === null) {
                $team_id = null;
            }
        }

        if ($vorname === '' || $nachname === '') {
            $error = 'Bitte Vorname und Nachname ausfüllen.';
        } elseif ($volljaehrig === null) {
            $error = 'Bitte den Altersstatus auswählen.';
        } elseif (!in_array($geschlecht, ['Männlich', 'Weiblich', 'Divers'], true)) {
            $error = 'Bitte ein Geschlecht auswählen.';
        } elseif ($team_select === '__NEW__' && $teamname === null) {
            $error = 'Bitte einen Teamnamen eingeben oder ein bestehendes Team wählen.';
        } elseif ($team_select !== '' && $team_select !== '__NEW__' && !ctype_digit($team_select)) {
            $error = 'Bitte ein gültiges Team wählen.';
        } elseif ($team_select !== '' && $team_select !== '__NEW__' && $team_id === null) {
            $error = 'Bitte ein gültiges Team wählen.';
        } elseif ($teilnahme_ok !== 1 || $dsgvo_ok !== 1 || $bildton_ok !== 1) {
            $error = 'Bitte Teilnahmebedingungen, Datenschutzerklärung und Hinweise zu Bild-/Tonaufnahmen akzeptieren.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM teilnehmer WHERE vorname = :vorname AND nachname = :nachname LIMIT 1');
            $dup->execute([':vorname' => $vorname, ':nachname' => $nachname]);
            if ((int)$dup->fetchColumn() === 1) {
                $error = 'Mit diesem Vor- und Nachnamen existiert bereits eine Anmeldung. Bitte nutze deinen bestehenden Screenshot/Code oder melde dich an der Kasse.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO teilnehmer (code, vorname, nachname, volljaehrig, geschlecht, team_id, teamname, teilnahmebedingungen_akzeptiert, dsgvo_akzeptiert, bild_ton_akzeptiert)
                     VALUES (:code, :vorname, :nachname, :volljaehrig, :geschlecht, :team_id, :teamname, :teilnahme, :dsgvo, :bildton)'
                );

                $code = '';
                $inserted = false;
                for ($tries = 0; $tries < 6; $tries++) {
                    $code = registration_code_generate(9);
                    try {
                        $stmt->execute([
                            ':code' => $code,
                            ':vorname' => $vorname,
                            ':nachname' => $nachname,
                            ':volljaehrig' => $volljaehrig,
                            ':geschlecht' => $geschlecht,
                            ':team_id' => $team_id,
                            ':teamname' => $teamname,
                            ':teilnahme' => 1,
                            ':dsgvo' => 1,
                            ':bildton' => 1,
                        ]);
                        $inserted = true;
                        break;
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            continue;
                        }
                        throw $e;
                    }
                }
                if ($inserted) {
                    $success = true;
                    $saved = [
                        'vorname' => $vorname,
                        'nachname' => $nachname,
                        'volljaehrig' => $volljaehrig,
                        'geschlecht' => $geschlecht,
                        'teamname' => (string)($teamname ?? ''),
                        'code' => $code,
                    ];
                    push_notify_new_registration($vorname, $nachname, $teamname);
                } else {
                    $error = 'Anmeldung konnte nicht abgeschlossen werden. Bitte erneut versuchen.';
                }
            }
        }
    }
}


$teams = get_existing_teams($pdo);
$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$assetBase = $assetBase === '' ? '' : $assetBase;
?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Voranmeldung – 24-Stunden-Schwimmen (DLRG Sankt Augustin)</title>
    <link rel="stylesheet" href="<?php echo escape($assetBase . '/assets/style.css'); ?>" />
  </head>
  <body class="page-reg">
    <div class="page">
      <div class="container">
        <header class="reg-header">
          <!-- @doc-note Das DLRG-Logo verankert die Registrierungsseite visuell im Vereinskontext. -->
          <img src="<?php echo escape($assetBase . '/assets/logo.png'); ?>" alt="DLRG Sankt Augustin e.V." class="reg-logo" decoding="async" />
          <h1 class="reg-title">
            <span>Anmeldung zum</span>
            <span>24‑Stunden‑Schwimmen</span>
          </h1>
        </header>

        <?php if ($success): ?>
          <div class="alert alert-success no-autohide">
            <p class="success-headline"><span class="success-mark" aria-hidden="true"><svg class="success-check" viewBox="0 0 52 52" width="22" height="22" focusable="false"><circle class="success-check-circle" cx="26" cy="26" r="25" fill="none" /><path class="success-check-path" fill="none" d="M14 27.5l7.2 7.2L38 18.9" /></svg></span>Anmeldung erfolgreich!</p>
            <p class="success-warning">Deine (Wunsch-)Startnummer erhältst Du, nachdem Du Dich vor Ort angemeldet hast.</p>
            <p class="success-warning" style="margin-bottom: 2em;">Halte für die Bezahlung den unten angezeigten QR-Code bereit.</p>
            <p class="success-warning">Bitte mache JETZT einen Screenshot dieser Seite oder merke dir deine Daten für die Bezahlung vor Ort!</p>
            <div class="panel" style="margin-top:12px;">
              <div class="grid">
                <div><strong>Vorname:</strong> <?php echo escape($saved['vorname']); ?></div>
                <div><strong>Nachname:</strong> <?php echo escape($saved['nachname']); ?></div>
                <div><strong>Volljährig?</strong> <?php echo $saved['volljaehrig'] === 1 ? 'Ja' : 'Nein'; ?></div>
                <div><strong>Geschlecht:</strong> <?php echo escape($saved['geschlecht']); ?></div>
                <div style="grid-column:1 / -1;"><strong>Team:</strong> <?php echo $saved['teamname'] !== '' ? escape($saved['teamname']) : '—'; ?></div>
                <div style="grid-column:1 / -1;">
                  <strong>Bezahl‑Code:</strong>
                  <span style="font-size:18px;font-weight:900;letter-spacing:0.6px;"><?php echo escape(registration_code_format($saved['code'])); ?></span>
                  <div class="hint">
                    <!-- @doc-note Dieser Hinweistext ist bewusst fachlich formuliert und kann separat gepflegt werden. -->
                    Diesen Code an der Kasse vorzeigen (QR oder Abtippen). Der Code enthält eine Prüfziffer gegen Tippfehler.
                  </div>
                </div>
              </div>
            </div>
            <div class="panel" style="margin-top:12px; display: flex; justify-content: center;">
              <!-- @doc-note Der QR-Code bleibt ueber Flex-Ausrichtung mittig und damit direkt scanbar. -->
              <div id="qr" class="qr-box qr-loading" style="width:180px;height:180px;" aria-busy="true"></div>
            </div>
          </div>
          <div class="actions">
            <a class="btn btn-wide btn-flat" href="index.php">Weitere Person anmelden</a>
          </div>
        <?php else: ?>
          <?php if ($error !== ''): ?>
            <div class="alert alert-error">
              <strong>Fehler:</strong> <?php echo escape($error); ?>
            </div>
          <?php endif; ?>

          <form class="reg-form" method="post" action="index.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token_get()); ?>" />

            <div class="field">
              <label for="vorname">Vorname *</label>
              <input id="vorname" name="vorname" type="text" autocomplete="given-name" required />
            </div>

            <div class="field">
              <label for="nachname">Nachname *</label>
              <input id="nachname" name="nachname" type="text" autocomplete="family-name" required />
            </div>

            <div class="field">
              <label>Ich bin volljährig *</label>
              <div class="radio-row">
                <input id="voll_1" type="radio" name="volljaehrig" value="1" required />
                <label for="voll_1">Ja</label>
              </div>
              <div class="radio-row">
                <input id="voll_0" type="radio" name="volljaehrig" value="0" required />
                <label for="voll_0">Nein</label>
              </div>
            </div>

            <div class="field">
              <label for="geschlecht">Geschlecht *</label>
              <select id="geschlecht" name="geschlecht" required>
                <option value="" selected disabled hidden>Bitte auswählen</option>
                <option value="Männlich">Männlich</option>
                <option value="Weiblich">Weiblich</option>
                <option value="Divers">Divers</option>
              </select>
            </div>

            <div class="field">
              <label for="team_select">Team</label>
              <select id="team_select" name="team_select">
                <option value="" selected>— Kein Team (Einzelkämpfer) —</option>
                <?php foreach ($teams as $t): ?>
                  <option value="<?php echo escape((string)$t['id']); ?>"><?php echo escape((string)$t['name']); ?></option>
                <?php endforeach; ?>
                <option value="__NEW__">[ + Neues Team erstellen ]</option>
              </select>

              <div id="new_team_wrapper" class="field">
                <label for="new_team_input">Neuer Teamname *</label>
                <input id="new_team_input" name="new_team" type="text" />
              </div>
            </div>

            <div class="field consent-field">
             <div class="check-row">
                <input id="teilnahmebedingungen" name="teilnahmebedingungen" type="checkbox" required />
                <div class="check-text">
                  <label for="teilnahmebedingungen">Ich akzeptiere die <button class="link-btn caption-link" type="button" data-modal-open="modal_teilnahme">Teilnahmebedingungen*</button> des 24‑Stunden‑Schwimmens.</label>
                </div>
              </div>
            </div>

            <div class="field consent-field">
              <div class="check-row">
                <input id="dsgvo" name="dsgvo" type="checkbox" required />
                <div class="check-text">
                  <label for="dsgvo">Ich stimme der <button class="link-btn caption-link" type="button" data-modal-open="modal_dsgvo">Datenschutzerklärung*</button> zu.</label>
                </div>
              </div>
            </div>

            <div class="field consent-field">
              <div class="check-row">
                <input id="bildton" name="bildton" type="checkbox" required />
                <div class="check-text">
                  <label for="bildton">Ich bin mit <button class="link-btn caption-link" type="button" data-modal-open="modal_bildton">Foto‑, Video‑ und Tonaufnahmen*</button> sowie deren Veröffentlichung einverstanden.</label>
                </div>
              </div>
            </div>

            <div class="actions">
              <button class="btn btn-wide btn-flat" type="submit">
                Anmelden
              </button>
            </div>
            <p class="muted" style="margin-top:10px;">
              Datensparsamkeit: Es werden keine E‑Mails, Telefonnummern oder Adressen erfasst. Schutz deiner Daten: <button class="link-btn" type="button" data-modal-open="modal_security">Kurzinfo anzeigen</button>
              <br />
            </p>
          </form>

          <div id="modal_teilnahme" class="modal-overlay" aria-hidden="true">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal_teilnahme_title" tabindex="-1">
              <div class="modal-header">
                <h2 class="modal-title" id="modal_teilnahme_title">Teilnahmebedingungen</h2>
                <button class="modal-close" type="button" data-modal-close>Schließen</button>
              </div>

              <div class="modal-section-title">1. Gesundheit &amp; Schwimmfähigkeit</div>
              <p>Nur für Schwimmer: Die Teilnahme ist nur Personen gestattet, die mindestens 100&nbsp;m sicher schwimmen können. Nichtschwimmer sind im Becken nicht erlaubt.</p>
              <p>Eigene Verantwortung: Jeder Teilnehmer startet auf eigene Verantwortung und versichert, körperlich gesund zu sein. Bei Erschöpfung oder Unwohlsein ist das Schwimmen sofort abzubrechen.</p>

              <div class="modal-section-title">2. Verhalten im Freibad &amp; auf den Bahnen</div>
              <p>Anweisungen &amp; Hausordnung: Die Haus- und Badeordnung des Freibads Sankt Augustin sowie alle Anweisungen der DLRG-Helfer und des Badpersonals sind strikt zu befolgen.</p>
              <p>Grillen und Feuer: Grillen und Feuer machen ist im Freibad verboten.</p>
              <p>Fairness im Wasser: Auf den Bahnen gilt das Rechtsverkehr-Prinzip (Kreisverkehr). Schnelleren Schwimmern ist das Überholen fair zu ermöglichen.</p>
              <p>Hilfsmittel: Schnorchel, Paddles oder andere Schwimmhilfen sind für die offizielle Meterwertung nicht zugelassen. Flossen können nach Anmeldung genutzt werden.</p>

              <div class="modal-section-title">3. Jugendschutz &amp; Aufsichtspflicht</div>
              <p>Minderjährige: Kinder und Jugendliche unter 18&nbsp;Jahren benötigen das Einverständnis der Erziehungsberechtigten.</p>
              <p>Keine Aufsicht an Land: Die DLRG übernimmt keine Aufsichtspflicht außerhalb des Wassers. Die Aufsichtspflicht verbleibt stets bei den Eltern oder Begleitpersonen.</p>
              <p>Nachtschwimmen: Für Jugendliche unter 16&nbsp;Jahren gelten nachts die gesetzlichen Bestimmungen des Jugendschutzgesetzes.</p>

              <div class="modal-section-title">4. Haftung &amp; Ausschluss</div>
              <p>Wertgegenstände: Für den Verlust oder die Beschädigung von Kleidung, Geld oder Wertsachen im gesamten Freibadbereich wird keine Haftung übernommen.</p>
              <p>Verstöße: Bei rücksichtslosem Verhalten, Unfairness oder Missachtung der Helfer-Anweisungen kann die Veranstaltungsleitung Teilnehmer direkt vom Event ausschließen.</p>
            </div>
          </div>

          <div id="modal_security" class="modal-overlay" aria-hidden="true">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal_security_title" tabindex="-1">
              <div class="modal-header">
                <h2 class="modal-title" id="modal_security_title">Sicherheitsmaßnahmen (Kurzinfo)</h2>
                <button class="modal-close" type="button" data-modal-close>Schließen</button>
              </div>

              <p>Zum Schutz deiner Daten setzen wir angemessene technische und organisatorische Maßnahmen ein. Aus Sicherheitsgründen veröffentlichen wir keine Detailkonfigurationen.</p>

              <div class="modal-section-title">Übertragung</div>
              <p>Wenn du die Seite über HTTPS aufrufst, werden die eingegebenen Daten verschlüsselt übertragen.</p>

              <div class="modal-section-title">Zugriffsschutz</div>
              <p>Verwaltungs- und Exportfunktionen sind nur nach Anmeldung für berechtigte Personen erreichbar.</p>

              <div class="modal-section-title">Schutz vor Manipulation</div>
              <p>Eingaben werden serverseitig geprüft; es werden Schutzmechanismen gegen missbräuchliche bzw. manipulierte Formularübermittlungen eingesetzt.</p>

              <div class="modal-section-title">Datensparsamkeit &amp; Pseudonymisierung</div>
              <p>Wir erfassen nur die für die Veranstaltung erforderlichen Angaben. Technische Metadaten (z.&nbsp;B. IP‑Adresse, Browserkennung) werden ausschließlich in pseudonymisierter Form gespeichert.</p>

              <div class="modal-section-title">Speicherung &amp; Löschung</div>
              <p>Die Daten werden nur so lange gespeichert, wie es für Durchführung und Abwicklung der Veranstaltung erforderlich ist. Details zur Speicherdauer und zu deinen Rechten findest du in der Datenschutzerklärung.</p>
            </div>
          </div>

          <div id="modal_dsgvo" class="modal-overlay" aria-hidden="true">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal_dsgvo_title" tabindex="-1">
              <div class="modal-header">
                <h2 class="modal-title" id="modal_dsgvo_title">Datenschutzerklärung</h2>
                <button class="modal-close" type="button" data-modal-close>Schließen</button>
              </div>

              <p>Wir freuen uns sehr über Ihr Interesse an unserem Unternehmen. Datenschutz hat einen besonders hohen Stellenwert für die Geschäftsleitung der DLRG Ortsgruppe St. Augustin e.V.. Eine Nutzung der Internetseiten der DLRG Ortsgruppe St. Augustin e.V. ist grundsätzlich ohne jede Angabe personenbezogener Daten möglich. Sofern eine betroffene Person besondere Services unseres Unternehmens über unsere Internetseite in Anspruch nehmen möchte, könnte jedoch eine Verarbeitung personenbezogener Daten erforderlich werden. Ist die Verarbeitung personenbezogener Daten erforderlich und besteht für eine solche Verarbeitung keine gesetzliche Grundlage, holen wir generell eine Einwilligung der betroffenen Person ein.</p>
              <p>Die Verarbeitung personenbezogener Daten, beispielsweise des Namens, der Anschrift, E-Mail-Adresse oder Telefonnummer einer betroffenen Person, erfolgt stets im Einklang mit der Datenschutz-Grundverordnung und in Übereinstimmung mit den für die DLRG Ortsgruppe St. Augustin e.V. geltenden landesspezifischen Datenschutzbestimmungen. Mittels dieser Datenschutzerklärung möchte unser Unternehmen die Öffentlichkeit über Art, Umfang und Zweck der von uns erhobenen, genutzten und verarbeiteten personenbezogenen Daten informieren. Ferner werden betroffene Personen mittels dieser Datenschutzerklärung über die ihnen zustehenden Rechte aufgeklärt.</p>
              <p>Die DLRG Ortsgruppe St. Augustin e.V. hat als für die Verarbeitung Verantwortlicher zahlreiche technische und organisatorische Maßnahmen umgesetzt, um einen möglichst lückenlosen Schutz der über diese Internetseite verarbeiteten personenbezogenen Daten sicherzustellen. Dennoch können internetbasierte Datenübertragungen grundsätzlich Sicherheitslücken aufweisen, sodass ein absoluter Schutz nicht gewährleistet werden kann. Aus diesem Grund steht es jeder betroffenen Person frei, personenbezogene Daten auch auf alternativen Wegen, beispielsweise telefonisch, an uns zu übermitteln.</p>

              <div class="modal-section-title">1. Begriffsbestimmungen</div>
              <p>Die Datenschutzerklärung der DLRG Ortsgruppe St. Augustin e.V. beruht auf den Begrifflichkeiten, die durch den Europäischen Richtlinien- und Verordnungsgeber beim Erlass der Datenschutz-Grundverordnung (DS-GVO) verwendet wurden. Unsere Datenschutzerklärung soll sowohl für die Öffentlichkeit als auch für unsere Kunden und Geschäftspartner einfach lesbar und verständlich sein. Um dies zu gewährleisten, möchten wir vorab die verwendeten Begrifflichkeiten erläutern.</p>
              <p>Wir verwenden in dieser Datenschutzerklärung unter anderem die folgenden Begriffe:</p>

              <p><strong>a) personenbezogene Daten</strong><br />Personenbezogene Daten sind alle Informationen, die sich auf eine identifizierte oder identifizierbare natürliche Person (im Folgenden „betroffene Person“) beziehen. Als identifizierbar wird eine natürliche Person angesehen, die direkt oder indirekt, insbesondere mittels Zuordnung zu einer Kennung wie einem Namen, zu einer Kennnummer, zu Standortdaten, zu einer Online-Kennung oder zu einem oder mehreren besonderen Merkmalen, die Ausdruck der physischen, physiologischen, genetischen, psychischen, wirtschaftlichen, kulturellen oder sozialen Identität dieser natürlichen Person sind, identifiziert werden kann.</p>
              <p><strong>b) betroffene Person</strong><br />Betroffene Person ist jede identifizierte oder identifizierbare natürliche Person, deren personenbezogene Daten von dem für die Verarbeitung Verantwortlichen verarbeitet werden.</p>
              <p><strong>c) Verarbeitung</strong><br />Verarbeitung ist jeder mit oder ohne Hilfe automatisierter Verfahren ausgeführte Vorgang oder jede solche Vorgangsreihe im Zusammenhang mit personenbezogenen Daten wie das Erheben, das Erfassen, die Organisation, das Ordnen, die Speicherung, die Anpassung oder Veränderung, das Auslesen, das Abfragen, die Verwendung, die Offenlegung durch Übermittlung, Verbreitung oder eine andere Form der Bereitstellung, den Abgleich oder die Verknüpfung, die Einschränkung, das Löschen oder die Vernichtung.</p>
              <p><strong>d) Einschränkung der Verarbeitung</strong><br />Einschränkung der Verarbeitung ist die Markierung gespeicherter personenbezogener Daten mit dem Ziel, ihre künftige Verarbeitung einzuschränken.</p>
              <p><strong>e) Profiling</strong><br />Profiling ist jede Art der automatisierten Verarbeitung personenbezogener Daten, die darin besteht, dass diese personenbezogenen Daten verwendet werden, um bestimmte persönliche Aspekte, die sich auf eine natürliche Person beziehen, zu bewerten, insbesondere, um Aspekte bezüglich Arbeitsleistung, wirtschaftlicher Lage, Gesundheit, persönlicher Vorlieben, Interessen, Zuverlässigkeit, Verhalten, Aufenthaltsort oder Ortswechsel dieser natürlichen Person zu analysieren oder vorherzusagen.</p>
              <p><strong>f) Pseudonymisierung</strong><br />Pseudonymisierung ist die Verarbeitung personenbezogener Daten in einer Weise, auf welche die personenbezogenen Daten ohne Hinzuziehung zusätzlicher Informationen nicht mehr einer spezifischen betroffenen Person zugeordnet werden können, sofern diese zusätzlichen Informationen gesondert aufbewahrt werden und technischen und organisatorischen Maßnahmen unterliegen, die gewährleisten, dass die personenbezogenen Daten nicht einer identifizierten oder identifizierbaren natürlichen Person zugewiesen werden.</p>
              <p><strong>g) Verantwortlicher oder für die Verarbeitung Verantwortlicher</strong><br />Verantwortlicher oder für die Verarbeitung Verantwortlicher ist die natürliche oder juristische Person, Behörde, Einrichtung oder andere Stelle, die allein oder gemeinsam mit anderen über die Zwecke und Mittel der Verarbeitung von personenbezogenen Daten entscheidet. Sind die Zwecke und Mittel dieser Verarbeitung durch das Unionsrecht oder das Recht der Mitgliedstaaten vorgegeben, so kann der Verantwortliche beziehungsweise können die bestimmten Kriterien seiner Benennung nach dem Unionsrecht oder dem Recht der Mitgliedstaaten vorgesehen werden.</p>
              <p><strong>h) Auftragsverarbeiter</strong><br />Auftragsverarbeiter ist eine natürliche oder juristische Person, Behörde, Einrichtung oder andere Stelle, die personenbezogene Daten im Auftrag des Verantwortlichen verarbeitet.</p>
              <p><strong>i) Empfänger</strong><br />Empfänger ist eine natürliche oder juristische Person, Behörde, Einrichtung oder andere Stelle, der personenbezogene Daten offengelegt werden, unabhängig davon, ob es sich bei ihr um einen Dritten handelt oder nicht. Behörden, die im Rahmen eines bestimmten Untersuchungsauftrags nach dem Unionsrecht oder dem Recht der Mitgliedstaaten möglicherweise personenbezogene Daten erhalten, gelten jedoch nicht als Empfänger.</p>
              <p><strong>j) Dritter</strong><br />Dritter ist eine natürliche oder juristische Person, Behörde, Einrichtung oder andere Stelle außer der betroffenen Person, dem Verantwortlichen, dem Auftragsverarbeiter und den Personen, die unter der unmittelbaren Verantwortung des Verantwortlichen oder des Auftragsverarbeiters befugt sind, die personenbezogenen Daten zu verarbeiten.</p>
              <p><strong>k) Einwilligung</strong><br />Einwilligung ist jede von der betroffenen Person freiwillig für den bestimmten Fall in informierter Weise und unmissverständlich abgegebene Willensbekundung in Form einer Erklärung oder einer sonstigen eindeutigen bestätigenden Handlung, mit der die betroffene Person zu verstehen gibt, dass sie mit der Verarbeitung der sie betreffenden personenbezogenen Daten einverstanden ist.</p>

              <div class="modal-section-title">2. Name und Anschrift des für die Verarbeitung Verantwortlichen</div>
              <p>Verantwortlicher im Sinne der Datenschutz-Grundverordnung, sonstiger in den Mitgliedstaaten der Europäischen Union geltenden Datenschutzgesetze und anderer Bestimmungen mit datenschutzrechtlichem Charakter ist die:</p>
              <p>
                DLRG Ortsgruppe St. Augustin e.V.<br />
                Arnold-Janssen-Straße 24<br />
                53757 Sankt Augustin<br />
                Deutschland
              </p>
              <p>
                Tel.: 015788219692<br />
                E-Mail: info@st-augustin.dlrg.de<br />
                Website: <a href="https://st-augustin.dlrg.de/" target="_blank" rel="noopener noreferrer">https://st-augustin.dlrg.de/</a>
              </p>

              <div class="modal-section-title">3. Erfassung von allgemeinen Daten und Informationen</div>
              <p>Die Internetseite der DLRG Ortsgruppe St. Augustin e.V. erfasst mit jedem Aufruf der Internetseite durch eine betroffene Person oder ein automatisiertes System eine Reihe von allgemeinen Daten und Informationen. Diese allgemeinen Daten und Informationen werden in den Logfiles des Servers gespeichert. Erfasst werden können die (1) verwendeten Browsertypen und Versionen, (2) das vom zugreifenden System verwendete Betriebssystem, (3) die Internetseite, von welcher ein zugreifendes System auf unsere Internetseite gelangt (sogenannte Referrer), (4) die Unterwebseiten, welche über ein zugreifendes System auf unserer Internetseite angesteuert werden, (5) das Datum und die Uhrzeit eines Zugriffs auf die Internetseite, (6) eine Internet-Protokoll-Adresse (IP-Adresse), (7) der Internet-Service-Provider des zugreifenden Systems und (8) sonstige ähnliche Daten und Informationen, die der Gefahrenabwehr im Falle von Angriffen auf unsere informationstechnologischen Systeme dienen.</p>
              <p>Bei der Nutzung dieser allgemeinen Daten und Informationen zieht die DLRG Ortsgruppe St. Augustin e.V. keine Rückschlüsse auf die betroffene Person. Diese Informationen werden vielmehr benötigt, um (1) die Inhalte unserer Internetseite korrekt auszuliefern, (2) die Inhalte unserer Internetseite sowie die Werbung für diese zu optimieren, (3) die dauerhafte Funktionsfähigkeit unserer informationstechnologischen Systeme und der Technik unserer Internetseite zu gewährleisten sowie (4) um Strafverfolgungsbehörden im Falle eines Cyberangriffes die zur Strafverfolgung notwendigen Informationen bereitzustellen. Diese anonym erhobenen Daten und Informationen werden durch die DLRG Ortsgruppe St. Augustin e.V. daher einerseits statistisch und ferner mit dem Ziel ausgewertet, den Datenschutz und die Datensicherheit in unserem Unternehmen zu erhöhen, um letztlich ein optimales Schutzniveau für die von uns verarbeiteten personenbezogenen Daten sicherzustellen. Die anonymen Daten der Server-Logfiles werden getrennt von allen durch eine betroffene Person angegebenen personenbezogenen Daten gespeichert.</p>

              <div class="modal-section-title">4. Registrierung auf unserer Internetseite</div>
              <p>Die betroffene Person hat die Möglichkeit, sich auf der Internetseite des für die Verarbeitung Verantwortlichen unter Angabe von personenbezogenen Daten zu registrieren. Welche personenbezogenen Daten dabei an den für die Verarbeitung Verantwortlichen übermittelt werden, ergibt sich aus der jeweiligen Eingabemaske, die für die Registrierung verwendet wird. Die von der betroffenen Person eingegebenen personenbezogenen Daten werden ausschließlich für die interne Verwendung bei dem für die Verarbeitung Verantwortlichen und für eigene Zwecke erhoben und gespeichert. Der für die Verarbeitung Verantwortliche kann die Weitergabe an einen oder mehrere Auftragsverarbeiter, beispielsweise einen Paketdienstleister, veranlassen, der die personenbezogenen Daten ebenfalls ausschließlich für eine interne Verwendung, die dem für die Verarbeitung Verantwortlichen zuzurechnen ist, nutzt.</p>
              <p>Durch eine Registrierung auf der Internetseite des für die Verarbeitung Verantwortlichen wird ferner die vom Internet-Service-Provider (ISP) der betroffenen Person vergebene IP-Adresse, das Datum sowie die Uhrzeit der Registrierung gespeichert. Die Speicherung dieser Daten erfolgt vor dem Hintergrund, dass nur so der Missbrauch unserer Dienste verhindert werden kann, und diese Daten im Bedarfsfall ermöglichen, begangene Straftaten aufzuklären. Insofern ist die Speicherung dieser Daten zur Absicherung des für die Verarbeitung Verantwortlichen erforderlich. Eine Weitergabe dieser Daten an Dritte erfolgt grundsätzlich nicht, sofern keine gesetzliche Pflicht zur Weitergabe besteht oder die Weitergabe der Strafverfolgung dient.</p>
              <p>Die Registrierung der betroffenen Person unter freiwilliger Angabe personenbezogener Daten dient dem für die Verarbeitung Verantwortlichen dazu, der betroffenen Person Inhalte oder Leistungen anzubieten, die aufgrund der Natur der Sache nur registrierten Benutzern angeboten werden können. Registrierten Personen steht die Möglichkeit frei, die bei der Registrierung angegebenen personenbezogenen Daten jederzeit abzuändern oder vollständig aus dem Datenbestand des für die Verarbeitung Verantwortlichen löschen zu lassen.</p>
              <p>Der für die Verarbeitung Verantwortliche erteilt jeder betroffenen Person jederzeit auf Anfrage Auskunft darüber, welche personenbezogenen Daten über die betroffene Person gespeichert sind. Ferner berichtigt oder löscht der für die Verarbeitung Verantwortliche personenbezogene Daten auf Wunsch oder Hinweis der betroffenen Person, soweit dem keine gesetzlichen Aufbewahrungspflichten entgegenstehen. Die Gesamtheit der Mitarbeiter des für die Verarbeitung Verantwortlichen stehen der betroffenen Person in diesem Zusammenhang als Ansprechpartner zur Verfügung.</p>

              <div class="modal-section-title">5. Kontaktmöglichkeit über die Internetseite</div>
              <p>Die Internetseite der DLRG Ortsgruppe St. Augustin e.V. enthält aufgrund von gesetzlichen Vorschriften Angaben, die eine schnelle elektronische Kontaktaufnahme zu unserem Unternehmen sowie eine unmittelbare Kommunikation mit uns ermöglichen, was ebenfalls eine allgemeine Adresse der sogenannten elektronischen Post (E-Mail-Adresse) umfasst. Sofern eine betroffene Person per E-Mail oder über ein Kontaktformular den Kontakt mit dem für die Verarbeitung Verantwortlichen aufnimmt, werden die von der betroffenen Person übermittelten personenbezogenen Daten automatisch gespeichert. Solche auf freiwilliger Basis von einer betroffenen Person an den für die Verarbeitung Verantwortlichen übermittelten personenbezogenen Daten werden für Zwecke der Bearbeitung oder der Kontaktaufnahme zur betroffenen Person gespeichert. Es erfolgt keine Weitergabe dieser personenbezogenen Daten an Dritte.</p>

              <div class="modal-section-title">6. Routinemäßige Löschung und Sperrung von personenbezogenen Daten</div>
              <p>Der für die Verarbeitung Verantwortliche verarbeitet und speichert personenbezogene Daten der betroffenen Person nur für den Zeitraum, der zur Erreichung des Speicherungszwecks erforderlich ist oder sofern dies durch den Europäischen Richtlinien- und Verordnungsgeber oder einen anderen Gesetzgeber in Gesetzen oder Vorschriften, welchen der für die Verarbeitung Verantwortliche unterliegt, vorgesehen wurde.</p>
              <p>Entfällt der Speicherungszweck oder läuft eine vom Europäischen Richtlinien- und Verordnungsgeber oder einem anderen zuständigen Gesetzgeber vorgeschriebene Speicherfrist ab, werden die personenbezogenen Daten routinemäßig und entsprechend den gesetzlichen Vorschriften gesperrt oder gelöscht.</p>

              <div class="modal-section-title">7. Rechte der betroffenen Person</div>
              <p><strong>a) Recht auf Bestätigung</strong><br />Jede betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber eingeräumte Recht, von dem für die Verarbeitung Verantwortlichen eine Bestätigung darüber zu verlangen, ob sie betreffende personenbezogene Daten verarbeitet werden. Möchte eine betroffene Person dieses Bestätigungsrecht in Anspruch nehmen, kann sie sich hierzu jederzeit an einen Mitarbeiter des für die Verarbeitung Verantwortlichen wenden.</p>

              <p><strong>b) Recht auf Auskunft</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, jederzeit von dem für die Verarbeitung Verantwortlichen unentgeltliche Auskunft über die zu seiner Person gespeicherten personenbezogenen Daten und eine Kopie dieser Auskunft zu erhalten. Ferner hat der Europäische Richtlinien- und Verordnungsgeber der betroffenen Person Auskunft über folgende Informationen zugestanden:</p>
              <ul>
                <li>die Verarbeitungszwecke</li>
                <li>die Kategorien personenbezogener Daten, die verarbeitet werden</li>
                <li>die Empfänger oder Kategorien von Empfängern, gegenüber denen die personenbezogenen Daten offengelegt worden sind oder noch offengelegt werden, insbesondere bei Empfängern in Drittländern oder bei internationalen Organisationen</li>
                <li>falls möglich die geplante Dauer, für die die personenbezogenen Daten gespeichert werden, oder, falls dies nicht möglich ist, die Kriterien für die Festlegung dieser Dauer</li>
                <li>das Bestehen eines Rechts auf Berichtigung oder Löschung der sie betreffenden personenbezogenen Daten oder auf Einschränkung der Verarbeitung durch den Verantwortlichen oder eines Widerspruchsrechts gegen diese Verarbeitung</li>
                <li>das Bestehen eines Beschwerderechts bei einer Aufsichtsbehörde</li>
                <li>wenn die personenbezogenen Daten nicht bei der betroffenen Person erhoben werden: Alle verfügbaren Informationen über die Herkunft der Daten</li>
                <li>das Bestehen einer automatisierten Entscheidungsfindung einschließlich Profiling gemäß Artikel 22 Abs. 1 und 4 DS-GVO und — zumindest in diesen Fällen — aussagekräftige Informationen über die involvierte Logik sowie die Tragweite und die angestrebten Auswirkungen einer derartigen Verarbeitung für die betroffene Person</li>
              </ul>
              <p>Ferner steht der betroffenen Person ein Auskunftsrecht darüber zu, ob personenbezogene Daten an ein Drittland oder an eine internationale Organisation übermittelt wurden. Sofern dies der Fall ist, so steht der betroffenen Person im Übrigen das Recht zu, Auskunft über die geeigneten Garantien im Zusammenhang mit der Übermittlung zu erhalten.</p>
              <p>Möchte eine betroffene Person dieses Auskunftsrecht in Anspruch nehmen, kann sie sich hierzu jederzeit an einen Mitarbeiter des für die Verarbeitung Verantwortlichen wenden.</p>

              <p><strong>c) Recht auf Berichtigung</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, die unverzügliche Berichtigung sie betreffender unrichtiger personenbezogener Daten zu verlangen. Ferner steht der betroffenen Person das Recht zu, unter Berücksichtigung der Zwecke der Verarbeitung, die Vervollständigung unvollständiger personenbezogener Daten — auch mittels einer ergänzenden Erklärung — zu verlangen.</p>
              <p>Möchte eine betroffene Person dieses Berichtigungsrecht in Anspruch nehmen, kann sie sich hierzu jederzeit an einen Mitarbeiter des für die Verarbeitung Verantwortlichen wenden.</p>

              <p><strong>d) Recht auf Löschung (Recht auf Vergessen werden)</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, von dem Verantwortlichen zu verlangen, dass die sie betreffenden personenbezogenen Daten unverzüglich gelöscht werden, sofern einer der folgenden Gründe zutrifft und soweit die Verarbeitung nicht erforderlich ist:</p>
              <ul>
                <li>Die personenbezogenen Daten wurden für solche Zwecke erhoben oder auf sonstige Weise verarbeitet, für welche sie nicht mehr notwendig sind.</li>
                <li>Die betroffene Person widerruft ihre Einwilligung, auf die sich die Verarbeitung gemäß Art. 6 Abs. 1 Buchstabe a DS-GVO oder Art. 9 Abs. 2 Buchstabe a DS-GVO stützte, und es fehlt an einer anderweitigen Rechtsgrundlage für die Verarbeitung.</li>
                <li>Die betroffene Person legt gemäß Art. 21 Abs. 1 DS-GVO Widerspruch gegen die Verarbeitung ein, und es liegen keine vorrangigen berechtigten Gründe für die Verarbeitung vor, oder die betroffene Person legt gemäß Art. 21 Abs. 2 DS-GVO Widerspruch gegen die Verarbeitung ein.</li>
                <li>Die personenbezogenen Daten wurden unrechtmäßig verarbeitet.</li>
                <li>Die Löschung der personenbezogenen Daten ist zur Erfüllung einer rechtlichen Verpflichtung nach dem Unionsrecht oder dem Recht der Mitgliedstaaten erforderlich, dem der Verantwortliche unterliegt.</li>
                <li>Die personenbezogenen Daten wurden in Bezug auf angebotene Dienste der Informationsgesellschaft gemäß Art. 8 Abs. 1 DS-GVO erhoben.</li>
              </ul>
              <p>Sofern einer der oben genannten Gründe zutrifft und eine betroffene Person die Löschung von personenbezogenen Daten, die bei der DLRG Ortsgruppe St. Augustin e.V. gespeichert sind, veranlassen möchte, kann sie sich hierzu jederzeit an einen Mitarbeiter des für die Verarbeitung Verantwortlichen wenden. Der Mitarbeiter der DLRG Ortsgruppe St. Augustin e.V. wird veranlassen, dass dem Löschverlangen unverzüglich nachgekommen wird.</p>
              <p>Wurden die personenbezogenen Daten von der DLRG Ortsgruppe St. Augustin e.V. öffentlich gemacht und ist unser Unternehmen als Verantwortlicher gemäß Art. 17 Abs. 1 DS-GVO zur Löschung der personenbezogenen Daten verpflichtet, so trifft die DLRG Ortsgruppe St. Augustin e.V. unter Berücksichtigung der verfügbaren Technologie und der Implementierungskosten angemessene Maßnahmen, auch technischer Art, um andere für die Datenverarbeitung Verantwortliche, welche die veröffentlichten personenbezogenen Daten verarbeiten, darüber in Kenntnis zu setzen, dass die betroffene Person von diesen anderen für die Datenverarbeitung Verantwortlichen die Löschung sämtlicher Links zu diesen personenbezogenen Daten oder von Kopien oder Replikationen dieser personenbezogenen Daten verlangt hat, soweit die Verarbeitung nicht erforderlich ist. Der Mitarbeiter der DLRG Ortsgruppe St. Augustin e.V. wird im Einzelfall das Notwendige veranlassen.</p>

              <p><strong>e) Recht auf Einschränkung der Verarbeitung</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, von dem Verantwortlichen die Einschränkung der Verarbeitung zu verlangen, wenn eine der folgenden Voraussetzungen gegeben ist:</p>
              <ul>
                <li>Die Richtigkeit der personenbezogenen Daten wird von der betroffenen Person bestritten, und zwar für eine Dauer, die es dem Verantwortlichen ermöglicht, die Richtigkeit der personenbezogenen Daten zu überprüfen.</li>
                <li>Die Verarbeitung ist unrechtmäßig, die betroffene Person lehnt die Löschung der personenbezogenen Daten ab und verlangt stattdessen die Einschränkung der Nutzung der personenbezogenen Daten.</li>
                <li>Der Verantwortliche benötigt die personenbezogenen Daten für die Zwecke der Verarbeitung nicht länger, die betroffene Person benötigt sie jedoch zur Geltendmachung, Ausübung oder Verteidigung von Rechtsansprüchen.</li>
                <li>Die betroffene Person hat Widerspruch gegen die Verarbeitung gem. Art. 21 Abs. 1 DS-GVO eingelegt und es steht noch nicht fest, ob die berechtigten Gründe des Verantwortlichen gegenüber denen der betroffenen Person überwiegen.</li>
              </ul>
              <p>Sofern eine der oben genannten Voraussetzungen gegeben ist und eine betroffene Person die Einschränkung von personenbezogenen Daten, die bei der DLRG Ortsgruppe St. Augustin e.V. gespeichert sind, verlangen möchte, kann sie sich hierzu jederzeit an einen Mitarbeiter des für die Verarbeitung Verantwortlichen wenden. Der Mitarbeiter der DLRG Ortsgruppe St. Augustin e.V. wird die Einschränkung der Verarbeitung veranlassen.</p>

              <p><strong>f) Recht auf Datenübertragbarkeit</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, die sie betreffenden personenbezogenen Daten, welche durch die betroffene Person einem Verantwortlichen bereitgestellt wurden, in einem strukturierten, gängigen und maschinenlesbaren Format zu erhalten. Sie hat außerdem das Recht, diese Daten einem anderen Verantwortlichen ohne Behinderung durch den Verantwortlichen, dem die personenbezogenen Daten bereitgestellt wurden, zu übermitteln, sofern die Verarbeitung auf der Einwilligung gemäß Art. 6 Abs. 1 Buchstabe a DS-GVO oder Art. 9 Abs. 2 Buchstabe a DS-GVO oder auf einem Vertrag gemäß Art. 6 Abs. 1 Buchstabe b DS-GVO beruht und die Verarbeitung mithilfe automatisierter Verfahren erfolgt, sofern die Verarbeitung nicht für die Wahrnehmung einer Aufgabe erforderlich ist, die im öffentlichen Interesse liegt oder in Ausübung öffentlicher Gewalt erfolgt, welche dem Verantwortlichen übertragen wurde.</p>
              <p>Ferner hat die betroffene Person bei der Ausübung ihres Rechts auf Datenübertragbarkeit gemäß Art. 20 Abs. 1 DS-GVO das Recht, zu erwirken, dass die personenbezogenen Daten direkt von einem Verantwortlichen an einen anderen Verantwortlichen übermittelt werden, soweit dies technisch machbar ist und sofern hiervon nicht die Rechte und Freiheiten anderer Personen beeinträchtigt werden.</p>
              <p>Zur Geltendmachung des Rechts auf Datenübertragbarkeit kann sich die betroffene Person jederzeit an einen Mitarbeiter der DLRG Ortsgruppe St. Augustin e.V. wenden.</p>

              <p><strong>g) Recht auf Widerspruch</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, aus Gründen, die sich aus ihrer besonderen Situation ergeben, jederzeit gegen die Verarbeitung sie betreffender personenbezogener Daten, die aufgrund von Art. 6 Abs. 1 Buchstaben e oder f DS-GVO erfolgt, Widerspruch einzulegen. Dies gilt auch für ein auf diese Bestimmungen gestütztes Profiling.</p>
              <p>Die DLRG Ortsgruppe St. Augustin e.V. verarbeitet die personenbezogenen Daten im Falle des Widerspruchs nicht mehr, es sei denn, wir können zwingende schutzwürdige Gründe für die Verarbeitung nachweisen, die den Interessen, Rechten und Freiheiten der betroffenen Person überwiegen, oder die Verarbeitung dient der Geltendmachung, Ausübung oder Verteidigung von Rechtsansprüchen.</p>
              <p>Verarbeitet die DLRG Ortsgruppe St. Augustin e.V. personenbezogene Daten, um Direktwerbung zu betreiben, so hat die betroffene Person das Recht, jederzeit Widerspruch gegen die Verarbeitung der personenbezogenen Daten zum Zwecke derartiger Werbung einzulegen. Dies gilt auch für das Profiling, soweit es mit solcher Direktwerbung in Verbindung steht. Widerspricht die betroffene Person gegenüber der DLRG Ortsgruppe St. Augustin e.V. der Verarbeitung für Zwecke der Direktwerbung, so wird die DLRG Ortsgruppe St. Augustin e.V. die personenbezogenen Daten nicht mehr für diese Zwecke verarbeiten.</p>
              <p>Zudem hat die betroffene Person das Recht, aus Gründen, die sich aus ihrer besonderen Situation ergeben, gegen die sie betreffende Verarbeitung personenbezogener Daten, die bei der DLRG Ortsgruppe St. Augustin e.V. zu wissenschaftlichen oder historischen Forschungszwecken oder zu statistischen Zwecken gemäß Art. 89 Abs. 1 DS-GVO erfolgen, Widerspruch einzulegen, es sei denn, eine solche Verarbeitung ist zur Erfüllung einer im öffentlichen Interesse liegenden Aufgabe erforderlich.</p>
              <p>Zur Ausübung des Rechts auf Widerspruch kann sich die betroffene Person direkt an jeden Mitarbeiter der DLRG Ortsgruppe St. Augustin e.V. oder einen anderen Mitarbeiter wenden. Der betroffenen Person steht es ferner frei, im Zusammenhang mit der Nutzung von Diensten der Informationsgesellschaft, ungeachtet der Richtlinie 2002/58/EG, ihr Widerspruchsrecht mittels automatisierter Verfahren auszuüben, bei denen technische Spezifikationen verwendet werden.</p>

              <p><strong>h) Automatisierte Entscheidungen im Einzelfall einschließlich Profiling</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, nicht einer ausschließlich auf einer automatisierten Verarbeitung — einschließlich Profiling — beruhenden Entscheidung unterworfen zu werden, die ihr gegenüber rechtliche Wirkung entfaltet oder sie in ähnlicher Weise erheblich beeinträchtigt, sofern die Entscheidung (1) nicht für den Abschluss oder die Erfüllung eines Vertrags zwischen der betroffenen Person und dem Verantwortlichen erforderlich ist, oder (2) aufgrund von Rechtsvorschriften der Union oder der Mitgliedstaaten, denen der Verantwortliche unterliegt, zulässig ist und diese Rechtsvorschriften angemessene Maßnahmen zur Wahrung der Rechte und Freiheiten sowie der berechtigten Interessen der betroffenen Person enthalten oder (3) mit ausdrücklicher Einwilligung der betroffenen Person erfolgt.</p>
              <p>Ist die Entscheidung (1) für den Abschluss oder die Erfüllung eines Vertrags zwischen der betroffenen Person und dem Verantwortlichen erforderlich oder (2) erfolgt sie mit ausdrücklicher Einwilligung der betroffenen Person, trifft die DLRG Ortsgruppe St. Augustin e.V. angemessene Maßnahmen, um die Rechte und Freiheiten sowie die berechtigten Interessen der betroffenen Person zu wahren, wozu mindestens das Recht auf Erwirkung des Eingreifens einer Person seitens des Verantwortlichen, auf Darlegung des eigenen Standpunkts und auf Anfechtung der Entscheidung gehört.</p>
              <p>Möchte die betroffene Person Rechte mit Bezug auf automatisierte Entscheidungen geltend machen, kann sie sich hierzu jederzeit an einen Mitarbeiter des für die Verarbeitung Verantwortlichen wenden.</p>

              <p><strong>i) Recht auf Widerruf einer datenschutzrechtlichen Einwilligung</strong><br />Jede von der Verarbeitung personenbezogener Daten betroffene Person hat das vom Europäischen Richtlinien- und Verordnungsgeber gewährte Recht, eine Einwilligung zur Verarbeitung personenbezogener Daten jederzeit zu widerrufen.</p>
              <p>Möchte die betroffene Person ihr Recht auf Widerruf einer Einwilligung geltend machen, kann sie sich hierzu jederzeit an einen Mitarbeiter des für die Verarbeitung Verantwortlichen wenden.</p>

              <div class="modal-section-title">8. Rechtsgrundlage der Verarbeitung</div>
              <p>Art. 6 I lit. a DS-GVO dient unserem Unternehmen als Rechtsgrundlage für Verarbeitungsvorgänge, bei denen wir eine Einwilligung für einen bestimmten Verarbeitungszweck einholen. Ist die Verarbeitung personenbezogener Daten zur Erfüllung eines Vertrags, dessen Vertragspartei die betroffene Person ist, erforderlich, wie dies beispielsweise bei Verarbeitungsvorgängen der Fall ist, die für eine Lieferung von Waren oder die Erbringung einer sonstigen Leistung oder Gegenleistung notwendig sind, so beruht die Verarbeitung auf Art. 6 I lit. b DS-GVO. Gleiches gilt für solche Verarbeitungsvorgänge die zur Durchführung vorvertraglicher Maßnahmen erforderlich sind, etwa in Fällen von Anfragen zur unseren Produkten oder Leistungen. Unterliegt unser Unternehmen einer rechtlichen Verpflichtung durch welche eine Verarbeitung von personenbezogenen Daten erforderlich wird, wie beispielsweise zur Erfüllung steuerlicher Pflichten, so basiert die Verarbeitung auf Art. 6 I lit. c DS-GVO. In seltenen Fällen könnte die Verarbeitung von personenbezogenen Daten erforderlich werden, um lebenswichtige Interessen der betroffenen Person oder einer anderen natürlichen Person zu schützen. Dies wäre beispielsweise der Fall, wenn ein Besucher in unserem Betrieb verletzt werden würde und daraufhin sein Name, sein Alter, seine Krankenkassendaten oder sonstige lebenswichtige Informationen an einen Arzt, ein Krankenhaus oder sonstige Dritte weitergegeben werden müssten. Dann würde die Verarbeitung auf Art. 6 I lit. d DS-GVO beruhen. Letztlich könnten Verarbeitungsvorgänge auf Art. 6 I lit. f DS-GVO beruhen. Auf dieser Rechtsgrundlage basieren Verarbeitungsvorgänge, die von keiner der vorgenannten Rechtsgrundlagen erfasst werden, wenn die Verarbeitung zur Wahrung eines berechtigten Interesses unseres Unternehmens oder eines Dritten erforderlich ist, sofern die Interessen, Grundrechte und Grundfreiheiten des Betroffenen nicht überwiegen. Solche Verarbeitungsvorgänge sind uns insbesondere deshalb gestattet, weil sie durch den Europäischen Gesetzgeber besonders erwähnt wurden. Er vertrat insoweit die Auffassung, dass ein berechtigtes Interesse anzunehmen sein könnte, wenn die betroffene Person ein Kunde des Verantwortlichen ist (Erwägungsgrund 47 Satz 2 DS-GVO).</p>

              <div class="modal-section-title">9. Berechtigte Interessen an der Verarbeitung, die von dem Verantwortlichen oder einem Dritten verfolgt werden</div>
              <p>Basiert die Verarbeitung personenbezogener Daten auf Artikel 6 I lit. f DS-GVO ist unser berechtigtes Interesse die Durchführung unserer Geschäftstätigkeit zugunsten des Wohlergehens all unserer Mitarbeiter und unserer Anteilseigner.</p>

              <div class="modal-section-title">10. Dauer, für die die personenbezogenen Daten gespeichert werden</div>
              <p>Das Kriterium für die Dauer der Speicherung von personenbezogenen Daten ist die jeweilige gesetzliche Aufbewahrungsfrist. Nach Ablauf der Frist werden die entsprechenden Daten routinemäßig gelöscht, sofern sie nicht mehr zur Vertragserfüllung oder Vertragsanbahnung erforderlich sind.</p>

              <div class="modal-section-title">11. Gesetzliche oder vertragliche Vorschriften zur Bereitstellung der personenbezogenen Daten; Erforderlichkeit für den Vertragsabschluss; Verpflichtung der betroffenen Person, die personenbezogenen Daten bereitzustellen; mögliche Folgen der Nichtbereitstellung</div>
              <p>Wir klären Sie darüber auf, dass die Bereitstellung personenbezogener Daten zum Teil gesetzlich vorgeschrieben ist (z.B. Steuervorschriften) oder sich auch aus vertraglichen Regelungen (z.B. Angaben zum Vertragspartner) ergeben kann. Mitunter kann es zu einem Vertragsschluss erforderlich sein, dass eine betroffene Person uns personenbezogene Daten zur Verfügung stellt, die in der Folge durch uns verarbeitet werden müssen. Die betroffene Person ist beispielsweise verpflichtet uns personenbezogene Daten bereitzustellen, wenn unser Unternehmen mit ihr einen Vertrag abschließt. Eine Nichtbereitstellung der personenbezogenen Daten hätte zur Folge, dass der Vertrag mit dem Betroffenen nicht geschlossen werden könnte. Vor einer Bereitstellung personenbezogener Daten durch den Betroffenen muss sich der Betroffene an einen unserer Mitarbeiter wenden. Unser Mitarbeiter klärt den Betroffenen einzelfallbezogen darüber auf, ob die Bereitstellung der personenbezogenen Daten gesetzlich oder vertraglich vorgeschrieben oder für den Vertragsabschluss erforderlich ist, ob eine Verpflichtung besteht, die personenbezogenen Daten bereitzustellen, und welche Folgen die Nichtbereitstellung der personenbezogenen Daten hätte.</p>

              <div class="modal-section-title">12. Bestehen einer automatisierten Entscheidungsfindung</div>
              <p>Als verantwortungsbewusstes Unternehmen verzichten wir auf eine automatische Entscheidungsfindung oder ein Profiling.</p>
            </div>
          </div>

          <div id="modal_bildton" class="modal-overlay" aria-hidden="true">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal_bildton_title" tabindex="-1">
              <div class="modal-header">
                <h2 class="modal-title" id="modal_bildton_title">Hinweise zu Bild‑/Tonaufnahmen</h2>
                <button class="modal-close" type="button" data-modal-close>Schließen</button>
              </div>

              <p>Liebe Teilnehmerinnen und Teilnehmer, liebe Gäste,</p>
              <p>beim 24-Stunden-Schwimmen der DLRG Ortsgruppe St. Augustin e.V. handelt es sich um eine öffentliche Veranstaltung.</p>
              <p>Bitte beachtet, dass im gesamten Veranstaltungsbereich (Schwimmbecken, Beckenrand und Freibadgelände) Foto-, Video- und Tonaufnahmen durch den Veranstalter angefertigt werden können.</p>
              <p>Diese Aufnahmen dienen unserer Öffentlichkeitsarbeit und werden gegebenenfalls auf unseren offiziellen Webseiten, in den sozialen Medien (z.&nbsp;B. Instagram, Facebook) sowie in der lokalen Presse veröffentlicht.</p>

              <div class="modal-section-title">Wichtiger Teilnahmehinweis</div>
              <p>Da bei einer Veranstaltung dieser Art technisch und organisatorisch nicht gewährleistet werden kann, dass einzelne Personen (z.&nbsp;B. im Hintergrund oder auf Gruppenbildern) nicht erscheinen, ist eine Teilnahme am Schwimmen ohne das Einverständnis zu diesen Aufnahmen leider nicht möglich.</p>
              <p>Mit dem Betreten des Veranstaltungsgeländes bzw. mit der Anmeldung zum Schwimmen erklärt ihr euch mit der Erstellung und Veröffentlichung der Aufnahmen einverstanden.</p>
              <p>Vielen Dank für euer Verständnis und viel Erfolg im Wasser!</p>
              <p>Eure DLRG Ortsgruppe St. Augustin e.V.</p>
            </div>
          </div>
        <?php endif; ?>

        <p class="muted" style="margin-top:1px; display:flex; gap:12px; flex-wrap:wrap; justify-content:center;">
          <a class="link-btn" href="impressum.php">Impressum</a>
        </p>
      </div>
    </div>
    <script src="<?php echo escape($assetBase . '/assets/app.js'); ?>"></script>
    <?php if ($success): ?>
      <script src="<?php echo escape($assetBase . '/static/qrcode.min.js'); ?>"></script>
      <script>
        (function () {
          var code = <?php echo json_encode(registration_code_normalize($saved['code'])); ?>;
          var qrEl = document.getElementById('qr');
          if (!qrEl) return;

          qrEl.classList.add('qr-loading');
          qrEl.setAttribute('aria-busy', 'true');

          var finish = function () {
            qrEl.classList.remove('qr-loading');
            qrEl.setAttribute('aria-busy', 'false');
          };

          if (typeof QRCode === 'undefined') {
            if (!qrEl.textContent || qrEl.textContent.trim() === '') {
              qrEl.textContent = 'QR-Code konnte nicht erzeugt werden. Bitte den Bezahl-Code oben an der Kasse vorzeigen oder abtippen.';
            }
            finish();
            return;
          }

          qrEl.replaceChildren();
          try {
            new QRCode(qrEl, { text: code, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
          } catch (e) {
            qrEl.textContent = 'QR-Code konnte nicht erzeugt werden. Bitte den Bezahl-Code oben an der Kasse vorzeigen oder abtippen.';
          }

          finish();
        })();
      </script>
    <?php endif; ?>
  </body>
</html>
