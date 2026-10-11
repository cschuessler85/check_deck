<?php
/**
 * @doc-file
 * @doc-id supply_mask.go.urkunde
 * @doc-kind generator
 * @doc-purpose Generiert personalisierte PDF-Urkunden fuer Schwimmer aus einer CSV-Datei mit einer PDF-Vorlage.
 * @doc-entry CLI-Aufruf (`php urkunde.php`) oder Web-Aufruf mit HTTPS-Weiterleitung.
 * @doc-input CSV-Datei (`schwimmer-final.csv`) und PDF-Vorlage (`vorlage.pdf`) im selben Verzeichnis.
 * @doc-output PDF-Urkunden in Unterordnern (z. B. `Tag1/`, `Tag2/`), optional als ZIP-Archiv.
 * @doc-side-effects Erstellt PDF-Dateien und ggf. ein ZIP-Archiv im Dateisystem.
 */
declare(strict_types=1);

use setasign\Fpdi\Fpdi;

const CSV_FILE = __DIR__ . '/schwimmer-final.csv';
const TEMPLATE_FILE = __DIR__ . '/vorlage.pdf';
const AUTOLOAD_FILE = __DIR__ . '/vendor/autoload.php';

if (
    php_sapi_name() !== 'cli'
    && (
        empty($_SERVER['HTTPS'])
        || strtolower((string) $_SERVER['HTTPS']) === 'off'
    )
    && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) !== 'https'
    && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) !== 'on'
) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

    if ($host !== '') {
        header('Location: https://' . $host . $requestUri, true, 301);
        exit;
    }
}

const SCHRIFT = 'Helvetica';
const SCHRIFT_BOLD = 'B';
const GROESSE_NORMAL = 16;
const GROESSE_NAME = 22;
const TEXTFARBE = [0, 105, 180];
const BLANKO_SEITE = [595.0, 842.0];
const POS = [
    'name' => [297.0, 564.0],
    'meter' => [350.0, 435.0],
    'geisterstunde' => [350.0, 394.0],
    'gutenacht' => [350.0, 353.0],
    'fruehaufsteher' => [350.0, 312.0],
];

$setupFehler = '';
if (is_file(AUTOLOAD_FILE)) {
    require_once AUTOLOAD_FILE;
} else {
    $setupFehler = 'Die PDF-Bibliothek fehlt. Bitte den vendor-Ordner mit auf Strato hochladen.';
}

function normalisiereText(?string $wert): string {
    $wert = trim((string) $wert);
    $wert = preg_replace('/\s+/u', ' ', $wert);
    return $wert ?? '';
}

function normalisiereHeader(string $wert): string {
    $wert = preg_replace('/^\xEF\xBB\xBF/u', '', $wert) ?? $wert;
    $wert = mb_strtolower(trim($wert), 'UTF-8');
    $wert = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $wert);
    return preg_replace('/[^a-z0-9]+/', '', $wert) ?? '';
}

function normalisiereName(string $wert): string {
    return mb_strtolower(normalisiereText($wert), 'UTF-8');
}

function normalisiereNummer(string $wert): string {
    $wert = normalisiereText($wert);
    if ($wert === '') {
        return '';
    }
    return ctype_digit($wert) ? (string) ((int) $wert) : $wert;
}

function ermittleCsvTrennzeichen(string $datei): string {
    $probe = (string) file_get_contents($datei, false, null, 0, 2048);
    return substr_count($probe, ';') > substr_count($probe, ',') ? ';' : ',';
}

function feldAusZeile(array $zeile, array $headerMap, array $aliasListe): string {
    foreach ($aliasListe as $alias) {
        if (array_key_exists($alias, $headerMap)) {
            $index = $headerMap[$alias];
            return normalisiereText($zeile[$index] ?? '');
        }
    }
    return '';
}

function csvLesen(): array {
    if (!is_file(CSV_FILE)) {
        return [];
    }

    $trennzeichen = ermittleCsvTrennzeichen(CSV_FILE);
    $handle = fopen(CSV_FILE, 'rb');
    if ($handle === false) {
        return [];
    }

    $kopfzeile = fgetcsv($handle, 0, $trennzeichen);
    if ($kopfzeile === false) {
        fclose($handle);
        return [];
    }

    $headerMap = [];
    foreach ($kopfzeile as $index => $spalte) {
        $headerMap[normalisiereHeader((string) $spalte)] = $index;
    }

    $eintraege = [];
    $rowIndex = 1;
    while (($zeile = fgetcsv($handle, 0, $trennzeichen)) !== false) {
        $eintraege[] = [
            '_row_index' => $rowIndex,
            'nummer' => feldAusZeile($zeile, $headerMap, ['nummer', 'startnummer']),
            'vorname' => feldAusZeile($zeile, $headerMap, ['vorname', 'firstname']),
            'nachname' => feldAusZeile($zeile, $headerMap, ['nachname', 'lastname']),
            'bahnanzahl' => feldAusZeile($zeile, $headerMap, ['bahnanzahl', 'bahnenanzahl', 'gesamtbahnen']),
            'geisterstunde' => feldAusZeile($zeile, $headerMap, ['geisterstunde']),
            'gutenacht' => feldAusZeile($zeile, $headerMap, ['gutenacht']),
            'fruehaufsteher' => feldAusZeile($zeile, $headerMap, ['fruehaufsteher', 'fruhaufsteher']),
        ];
        $rowIndex++;
    }

    fclose($handle);
    return $eintraege;
}

function findeTeilnehmer(array $eintraege, string $nummer, string $vorname, string $nachname): ?array {
    $nummer = normalisiereNummer($nummer);
    $vorname = normalisiereName($vorname);
    $nachname = normalisiereName($nachname);

    foreach ($eintraege as $eintrag) {
        if (normalisiereNummer($eintrag['nummer']) !== $nummer) {
            continue;
        }

        if (normalisiereName($eintrag['vorname']) !== $vorname) {
            continue;
        }

        if (normalisiereName($eintrag['nachname']) !== $nachname) {
            continue;
        }

        return $eintrag;
    }

    return null;
}

function bahnenInMeter(string $wert): string {
    $wert = normalisiereText($wert);
    if ($wert === '') {
        return '';
    }

    $wert = str_replace(',', '.', $wert);
    if (!is_numeric($wert)) {
        return $wert;
    }

    $meter = ((float) $wert) * 100;
    if (fmod($meter, 1.0) === 0.0) {
        return (string) ((int) $meter);
    }

    return rtrim(rtrim(number_format($meter, 2, '.', ''), '0'), '.');
}

function pdfText(string $text): string {
    $konvertiert = iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    return $konvertiert === false ? utf8_decode($text) : $konvertiert;
}

function dateinameTeil(string $wert): string {
    $wert = normalisiereText($wert);
    $wert = str_replace(' ', '_', $wert);
    $wert = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/', '', $wert);
    return $wert === '' ? 'unbekannt' : $wert;
}

function baueDateinamen(array $eintrag): string {
    $nummer = $eintrag['nummer'];
    $nummer = ctype_digit($nummer) ? str_pad($nummer, 3, '0', STR_PAD_LEFT) : dateinameTeil($nummer);
    return $nummer . '_' . dateinameTeil($eintrag['nachname']) . '_' . dateinameTeil($eintrag['vorname']) . '.pdf';
}

function yVonUntenNachOben(float $seitenhoehe, float $yVonUnten): float {
    return $seitenhoehe - $yVonUnten;
}

function zeichneZentriert(Fpdi $pdf, float $seitenhoehe, float $x, float $y, string $text, bool $fett, int $groesse): void {
    $text = normalisiereText($text);
    if ($text === '') {
        return;
    }

    $text = pdfText($text);
    $pdf->SetFont(SCHRIFT, $fett ? SCHRIFT_BOLD : '', $groesse);
    $breite = $pdf->GetStringWidth($text);
    $pdf->Text($x - ($breite / 2), yVonUntenNachOben($seitenhoehe, $y), $text);
}

function zeichneRechts(Fpdi $pdf, float $seitenhoehe, float $x, float $y, string $text, bool $fett, int $groesse): void {
    $text = normalisiereText($text);
    if ($text === '') {
        return;
    }

    $text = pdfText($text);
    $pdf->SetFont(SCHRIFT, $fett ? SCHRIFT_BOLD : '', $groesse);
    $breite = $pdf->GetStringWidth($text);
    $pdf->Text($x - $breite, yVonUntenNachOben($seitenhoehe, $y), $text);
}

function urkundeErzeugen(array $eintrag): array {
    $pdf = new Fpdi('P', 'pt');
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);

    if (is_file(TEMPLATE_FILE)) {
        $seite = $pdf->setSourceFile(TEMPLATE_FILE);
        if ($seite < 1) {
            throw new RuntimeException('Die PDF-Vorlage konnte nicht gelesen werden.');
        }

        $templateId = $pdf->importPage(1);
        $groesse = $pdf->getTemplateSize($templateId);
        $seitenbreite = (float) $groesse['width'];
        $seitenhoehe = (float) $groesse['height'];
        $pdf->AddPage($groesse['orientation'], [$seitenbreite, $seitenhoehe]);
        $pdf->useTemplate($templateId);
    } else {
        $seitenbreite = BLANKO_SEITE[0];
        $seitenhoehe = BLANKO_SEITE[1];
        $pdf->AddPage('P', [$seitenbreite, $seitenhoehe]);
    }

    $pdf->SetTextColor(TEXTFARBE[0], TEXTFARBE[1], TEXTFARBE[2]);

    $vollerName = trim($eintrag['vorname'] . ' ' . $eintrag['nachname']);
    zeichneZentriert($pdf, $seitenhoehe, POS['name'][0], POS['name'][1], $vollerName, true, GROESSE_NAME);

    $meter = bahnenInMeter($eintrag['bahnanzahl']);
    if ($meter !== '') {
        zeichneRechts($pdf, $seitenhoehe, POS['meter'][0], POS['meter'][1], $meter, true, GROESSE_NORMAL);
    }

    $geisterstunde = bahnenInMeter($eintrag['geisterstunde']);
    if ($geisterstunde !== '') {
        zeichneRechts($pdf, $seitenhoehe, POS['geisterstunde'][0], POS['geisterstunde'][1], $geisterstunde, true, GROESSE_NORMAL);
    }

    $gutenacht = bahnenInMeter($eintrag['gutenacht']);
    if ($gutenacht !== '') {
        zeichneRechts($pdf, $seitenhoehe, POS['gutenacht'][0], POS['gutenacht'][1], $gutenacht, true, GROESSE_NORMAL);
    }

    $fruehaufsteher = bahnenInMeter($eintrag['fruehaufsteher']);
    if ($fruehaufsteher !== '') {
        zeichneRechts($pdf, $seitenhoehe, POS['fruehaufsteher'][0], POS['fruehaufsteher'][1], $fruehaufsteher, true, GROESSE_NORMAL);
    }

    return [
        'filename' => baueDateinamen($eintrag),
        'content' => $pdf->Output('S'),
    ];
}

$fehler = $setupFehler;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fehler === '') {
    $nummer = normalisiereText($_POST['nummer'] ?? '');
    $vorname = normalisiereText($_POST['vorname'] ?? '');
    $nachname = normalisiereText($_POST['nachname'] ?? '');

    if ($nummer === '') {
        $fehler = 'Bitte die Startnummer eingeben.';
    } elseif (!ctype_digit($nummer)) {
        $fehler = 'Die Nummer darf nur Ziffern enthalten.';
    } elseif ($vorname === '' || $nachname === '') {
        $fehler = 'Bitte Vor- und Nachname eingeben.';
    } else {
        $eintraege = csvLesen();
        $eintrag = findeTeilnehmer($eintraege, $nummer, $vorname, $nachname);

        if ($eintrag === null) {
            $fehler = 'Keine Übereinstimmung gefunden. Bitte Nummer und Namen prüfen.';
        } else {
            try {
                $urkunde = urkundeErzeugen($eintrag);
                $dateiname = $urkunde['filename'];
                $inhalt = $urkunde['content'];

                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . addslashes($dateiname) . '"');
                header('Content-Length: ' . strlen($inhalt));
                header('Cache-Control: no-store, no-cache, must-revalidate');
                echo $inhalt;
                exit;
            } catch (Throwable $exception) {
                $fehler = 'Die Urkunde konnte nicht erzeugt werden: ' . $exception->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DLRG Urkunden-Download</title>
    <style>
        :root {
            --dlrg-rot: #e30613;
            --dlrg-rot-dunkel: #c40510;
            --dlrg-gelb: #ffed00;
            --dlrg-blau: #0569b4;
            --text: #222222;
            --muted: #5a6570;
            --border: #d7dbe0;
            --panel: #ffffff;
            --focus: rgba(227, 6, 19, 0.25);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(900px 520px at 20% 115%, rgba(5, 105, 180, 0.08), transparent 55%),
                radial-gradient(1100px 620px at 50% -220px, rgba(255, 255, 255, 0.16), transparent 60%),
                linear-gradient(135deg, var(--dlrg-rot) 0%, var(--dlrg-rot-dunkel) 100%);
        }

        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(1rem, 3vw, 1.5rem);
        }

        .card {
            width: 100%;
            max-width: 38rem;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
            padding: clamp(1rem, 3vw, 1.35rem);
        }

        .brand {
            text-align: center;
            border-bottom: 1px solid var(--border);
            padding: 0.35rem 0.5rem 1rem;
            margin-bottom: 1.15rem;
        }

        .brand img {
            display: block;
            width: 100%;
            max-width: 24rem;
            height: auto;
            margin: 0 auto 1rem;
        }

        .brand-title {
            margin: 0;
            line-height: 1.15;
        }

        .brand-title span {
            display: block;
        }

        .brand-title span:first-child {
            font-size: clamp(0.9rem, 3vw, 1rem);
            font-weight: 800;
            color: var(--muted);
        }

        .brand-title span:last-child {
            margin-top: 0.2rem;
            font-size: clamp(1.6rem, 6vw, 2rem);
            font-weight: 900;
            color: var(--dlrg-rot);
            letter-spacing: 0.01em;
        }

        .sub {
            font-size: 1rem;
            line-height: 1.5;
            color: var(--muted);
            text-align: center;
            margin-bottom: 1.2rem;
        }

        form {
            display: grid;
            gap: 0.95rem;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        label {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.35;
        }

        .field:focus-within label {
            color: var(--dlrg-rot);
        }

        input[type="text"] {
            width: 100%;
            min-height: 3.15rem;
            padding: 0.85rem 0.95rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: #ffffff;
            color: var(--text);
            font-size: 1.05rem;
            line-height: 1.2;
            outline: none;
            transition: border-color 140ms ease, box-shadow 140ms ease;
        }

        input[type="text"]::placeholder {
            color: #8b96a3;
        }

        input[type="text"]:focus {
            border-color: var(--dlrg-rot);
            box-shadow: 0 0 0 4px var(--focus);
        }

        .actions {
            margin-top: 0.2rem;
        }

        button[type="submit"] {
            width: 100%;
            min-height: 3.25rem;
            padding: 0.85rem 1rem;
            border: 0;
            border-radius: 0.6rem;
            background: var(--dlrg-rot);
            color: #ffffff;
            font-size: 1.05rem;
            font-weight: 800;
            cursor: pointer;
            transition: background-color 140ms ease, box-shadow 140ms ease, transform 140ms ease;
        }

        button[type="submit"]:hover {
            background: #d10511;
            box-shadow: 0 0 0 3px rgba(255, 237, 0, 0.28), 0 8px 18px rgba(227, 6, 19, 0.22);
            transform: translateY(-1px);
        }

        button[type="submit"]:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px var(--focus);
        }

        .alert {
            border-radius: 0.65rem;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.96rem;
            line-height: 1.45;
        }

        .alert-error {
            background: #fff5f7;
            border: 1px solid rgba(227, 6, 19, 0.22);
            color: #a10913;
        }

        .hinweis {
            margin-top: 1rem;
            font-size: 0.8rem;
            line-height: 1.45;
            color: var(--muted);
            text-align: center;
        }

        @media (max-width: 640px) {
            .card {
                border-radius: 0.55rem;
                padding: 0.95rem;
            }

            .brand {
                padding-bottom: 0.9rem;
                margin-bottom: 1rem;
            }

            .sub {
                font-size: 0.95rem;
            }

            label {
                font-size: 0.95rem;
            }

            input[type="text"],
            button[type="submit"] {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
<main class="page">
    <div class="card">
        <header class="brand">
            <img src="logo.png" alt="DLRG Ortsgruppe Sankt Augustin e.V.">
            <h1 class="brand-title">
                <span>Urkunden-Download zum</span>
                <span>24-Stunden-Schwimmen</span>
            </h1>
        </header>

        <p class="sub">Startnummer, Vorname und Nachname eingeben, um die Urkunde direkt als PDF herunterzuladen.</p>

        <?php if ($fehler !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($fehler) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="field">
                <label for="nummer">Startnummer</label>
                <input
                    type="text"
                    id="nummer"
                    name="nummer"
                    inputmode="numeric"
                    pattern="\d+"
                    placeholder="z. B. 7 oder 007"
                    value="<?= htmlspecialchars($_POST['nummer'] ?? '') ?>"
                    required
                    autocomplete="off"
                >
            </div>

            <div class="field">
                <label for="vorname">Vorname</label>
                <input
                    type="text"
                    id="vorname"
                    name="vorname"
                    placeholder="z. B. Max"
                    value="<?= htmlspecialchars($_POST['vorname'] ?? '') ?>"
                    required
                    autocomplete="off"
                >
            </div>

            <div class="field">
                <label for="nachname">Nachname</label>
                <input
                    type="text"
                    id="nachname"
                    name="nachname"
                    placeholder="z. B. Mueller"
                    value="<?= htmlspecialchars($_POST['nachname'] ?? '') ?>"
                    required
                    autocomplete="off"
                >
            </div>

            <div class="actions">
                <button type="submit">Urkunde herunterladen</button>
            </div>
        </form>

        <p class="hinweis">Die Angaben werden ausschließlich zur Verifikation verwendet und nicht dauerhaft gespeichert.</p>
    </div>
</main>
</body>
</html>
