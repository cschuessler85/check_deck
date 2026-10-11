<?php
/**
 * @doc-file
 * @doc-id live_app.impressum
 * @doc-kind page-controller
 * @doc-purpose Rendert die internen rechtlichen und organisatorischen Informationen fuer die Webanwendung.
 * @doc-entry HTTP-Aufruf der Impressumsseite.
 * @doc-input Statische Inhalte und gemeinsam genutztes Layout.
 * @doc-output HTML-Impressum.
 * @doc-side-effects Keine fachlichen Seiteneffekte ausser HTML-Ausgabe.
 */
require_once __DIR__ . '/includes/functions.php';

$assetBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$assetBase = $assetBase === '' ? '' : $assetBase;
?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Impressum – 24-Stunden-Schwimmen (DLRG Sankt Augustin)</title>
    <link rel="stylesheet" href="<?php echo escape($assetBase . '/assets/style.css'); ?>" />
  </head>
  <body>
    <div class="page">
      <div class="container">
        <header class="reg-header">
          <img src="<?php echo escape($assetBase . '/assets/logo.png'); ?>" alt="DLRG Sankt Augustin e.V." class="reg-logo" width="1714" height="332" decoding="async" />
          <h1 class="reg-title">
            <span>Rechtliche Hinweise</span>
            <span>Impressum</span>
          </h1>
        </header>

        <div class="panel">
          <p style="margin-top:0;">
            Deutsche Lebens-Rettungs-Gesellschaft<br />
            Ortsgruppe St. Augustin e.V.
          </p>

          <div class="modal-section-title">Landesverband</div>
          <p style="margin:0;">Landesverband Nordrhein e.V.</p>

          <div class="modal-section-title">Bezirk</div>
          <p style="margin:0;">Bezirk Rhein-Sieg e.V.</p>

          <div class="modal-section-title">Adresse</div>
          <p style="margin:0;">
            DLRG Ortsgruppe St. Augustin e.V.<br />
            Arnold-Janssen-Straße 24<br />
            53757 Sankt Augustin
          </p>

          <div class="modal-section-title">Telefon</div>
          <p style="margin:0;"><a href="tel:+4915788219692">+49 1578 8219692</a></p>

          <div class="modal-section-title">E-Mail</div>
          <p style="margin:0;"><a href="mailto:info@st-augustin.dlrg.de">info@st-augustin.dlrg.de</a></p>

          <div class="modal-section-title">Vertretungsberechtigter Vorstand</div>
          <p style="margin:0;">
            Frank Bucher,<br />
            Britta Bucher,<br />
            Volker C. Schmidt
          </p>

          <div class="modal-section-title">Redaktionell verantwortlich</div>
          <p style="margin:0;">
            Volker C. Schmidt<br />
            Sonnenrain 1A<br />
            53757 Sankt Augustin
          </p>

          <div class="modal-section-title">Vereinsregister</div>
          <p style="margin:0;">
            Gericht: Siegburg<br />
            Registernummer: 1627
          </p>

          <div class="modal-section-title">Kontoverbindung</div>
          <p style="margin:0;">Kontoverbindung für Spenden:</p>
          <p style="margin:10px 0 0 0;">
            Kontoinhaber: DLRG Sankt Augustin e.V.<br />
            Kreissparkasse Köln<br />
            IBAN: DE17370502990074006645<br />
            BIC: COKSDE33
          </p>
        </div>

        <div class="actions" style="margin-top: 14px;">
          <a class="btn btn-secondary btn-wide" href="index.php">Zurück zur Anmeldung</a>
        </div>
      </div>
    </div>
    <script src="<?php echo escape($assetBase . '/assets/app.js'); ?>?v=<?php echo escape(app_asset_version('assets/app.js')); ?>"></script>
  </body>
</html>
