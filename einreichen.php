<?php
/**
 * wordQUEST – öffentliche Seite "Wortliste einreichen" (WQ-8.1, WQ-8.2).
 *
 * Ohne Anmeldung erreichbar. Drei Wege führen zum selben Ziel: eine Datei
 * hochladen, eine Tabelle einfügen oder ein Foto der Buchseite im Browser
 * erkennen lassen.
 *
 * Nichts davon wird sofort sichtbar. Jede Einreichung landet mit Status "neu"
 * in der Warteschlange und durchläuft genau dieselbe Prüfkette wie ein Upload
 * im Admincenter. Veröffentlicht wird erst durch einen Menschen.
 */
declare(strict_types=1);

require __DIR__ . '/api/lib/bootstrap.php';
require __DIR__ . '/api/lib/db.php';
require __DIR__ . '/api/lib/wortlisten.php';
require __DIR__ . '/api/lib/import.php';
require __DIR__ . '/api/lib/einreichung.php';
require __DIR__ . '/api/lib/mail.php';

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = wq_db();
wq_einreichung_felder_ergaenzen($pdo);

$meldung = '';
$meldungArt = 'fehler';
$hinweise = [];
$geschafft = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $absender  = wq_freitext($_POST['absender']  ?? '', WQ_EINREICHUNG_MAX_NAME);
    $kontakt   = wq_freitext($_POST['kontakt']   ?? '', WQ_EINREICHUNG_MAX_KONTAKT);
    $bemerkung = wq_freitext($_POST['bemerkung'] ?? '', WQ_EINREICHUNG_MAX_BEMERK);
    $titel     = wq_freitext($_POST['titel']     ?? '', WQ_LISTE_MAX_TITEL);

    /* Reihenfolge mit Absicht: Erst das Billige, dann das Teure. Der Honigtopf
       kostet nichts, die Datenbank schon, und das Einlesen einer Tabelle am
       meisten. */
    $honig = trim((string) ($_POST['webseite'] ?? ''));
    $marke = wq_formular_marke_pruefen($_POST['marke'] ?? null);

    if ($honig !== '') {
        // Kein Hinweis, was aufgefallen ist. Ein Bot soll nicht lernen.
        $meldung = 'Die Einreichung konnte nicht angenommen werden.';
    } elseif (!$marke['ok']) {
        $meldung = (string) $marke['fehler'];
    } else {
        $takt = wq_takt_pruefen($pdo);
        if (!$takt['ok']) {
            $meldung = (string) $takt['fehler'];
        } else {
            $datei = $_FILES['liste'] ?? null;
            $hatDatei = is_array($datei) && ($datei['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            $eingefuegt = trim((string) ($_POST['eingefuegt'] ?? ''));

            $roh = '';
            $quelle = '';
            $tempPfad = null;

            if ($hatDatei && (int) $datei['size'] > WQ_XLSX_MAX_BYTES) {
                $meldung = 'Die Datei ist zu groß. Höchstens 2 MB, bei Text höchstens 512 KB.';
            } elseif ($hatDatei) {
                // Gelesen wird der Inhalt, nicht die Endung. Eine .json-Endung
                // sagt nichts darüber aus, was in der Datei steht.
                $roh = (string) file_get_contents($datei['tmp_name'], false, null, 0, WQ_XLSX_MAX_BYTES + 1);
                $quelle = wq_freitext((string) ($datei['name'] ?? ''), 120);
                $tempPfad = (string) $datei['tmp_name'];
                if (!str_starts_with($roh, "PK\x03\x04") && strlen($roh) > WQ_LISTE_MAX_BYTES) {
                    $meldung = 'Die Datei ist größer als 512 KB.';
                    $roh = '';
                }
            } elseif ($eingefuegt !== '') {
                $roh = mb_substr($eingefuegt, 0, WQ_LISTE_MAX_BYTES);
                $quelle = 'eingefügt';
            } else {
                $meldung = 'Bitte eine Datei wählen, eine Tabelle einfügen oder ein Foto erkennen lassen.';
            }

            if ($roh !== '') {
                $import = wq_import($roh, $quelle, $titel, $tempPfad);
                if (!$import['ok']) {
                    $meldung = (string) $import['fehler'];
                } else {
                    if ($import['daten'] !== null) {
                        $roh = (string) json_encode($import['daten'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $pruefung = wq_wortliste_pruefen($roh);
                    if (!$pruefung['ok']) {
                        $meldung = 'Die Liste wurde nicht angenommen: ' . implode(' ', $pruefung['fehler']);
                    } else {
                        $id = wq_einreichung_speichern($pdo, $roh, $quelle, $pruefung);
                        wq_einreichung_begleitung($pdo, $id, [
                            'absender'  => $absender,
                            'kontakt'   => $kontakt,
                            'bemerkung' => $bemerkung,
                            'quelle'    => wq_format_name($import['format']),
                        ]);
                        wq_takt_merken($pdo);

                        /* Benachrichtigung ist Beiwerk. Die Einreichung ist
                           bereits gespeichert, und ein stummer Mailserver darf
                           weder die Rückmeldung verfälschen noch die Seite
                           aufhalten. Scheitert der Versand, steht es nur im
                           Protokoll, und der Zähler im Admincenter zeigt die
                           offene Einreichung ohnehin. */
                        $versand = wq_einreichung_melden(
                            $id,
                            (string) ($pruefung['daten']['title'] ?? ''),
                            count($pruefung['daten']['words']),
                            wq_offene_einreichungen($pdo)
                        );
                        if (empty($versand['ok'])) {
                            error_log('wordQUEST: Benachrichtigung nicht verschickt: ' . ($versand['fehler'] ?? '?'));
                        }

                        $geschafft = true;
                        $meldungArt = 'ok';
                        $meldung = 'Vielen Dank. ' . count($pruefung['daten']['words'])
                                 . ' Wörter sind angekommen und liegen zur Durchsicht bereit.';
                        $hinweise = $pruefung['hinweise'];
                    }
                }
            }
        }
    }
}

$marke = wq_formular_marke();

/* Eigene Richtlinie, weil die Texterkennung Skript, WebAssembly, einen Worker
   aus einem Blob und die Sprachdaten braucht. Die Richtlinie aus der
   .htaccess wird für genau diese Datei entfernt, denn mehrere
   CSP-Header gelten immer als Schnittmenge und könnten sich sonst nur
   gegenseitig verschärfen. */
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'none'; "
        . "script-src 'self' 'wasm-unsafe-eval' https://cdn.jsdelivr.net; "
        . "style-src 'self'; "
        . "img-src 'self' blob: data: https://img.bildungssprit.de; "
        . "connect-src 'self' blob: data: https://cdn.jsdelivr.net https://tessdata.projectnaptha.com; "
        . "worker-src blob:; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Wortliste einreichen – wordQUEST</title>
<meta name="description" content="Eigene Vokabelliste für wordQUEST einreichen: als Excel, CSV, JSON, eingefügte Tabelle oder Foto einer Buchseite." />
<link rel="icon" href="favicon.ico" />
<link rel="stylesheet" href="einreichen.css" />
</head>
<body>

<header class="kopf">
  <a class="marke" href="index.html">
    <img src="wordQUEST_icon.png" alt="" width="34" height="34" />
    <span>wordQUEST</span>
  </a>
  <a class="zurueck" href="index.html">Zur App</a>
</header>

<main>
  <h1>Eigene Wortliste einreichen</h1>
  <p class="vorspann">
    Du unterrichtest und hast eine Vokabelliste, die hier hineingehört? Sehr
    gerne. Du brauchst dafür nichts Technisches zu können: Excel, CSV, eine
    kopierte Tabelle oder ein Foto der Buchseite genügen.
  </p>

  <?php if ($meldung !== ''): ?>
    <p class="meldung <?= $meldungArt === 'ok' ? 'gut' : 'fehler' ?>" role="status"><?= wq_h($meldung) ?></p>
    <?php foreach ($hinweise as $h): ?>
      <p class="meldung hinweis-meldung"><?= wq_h($h) ?></p>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($geschafft): ?>
    <section class="karte gut-karte">
      <h2>Angekommen</h2>
      <p>
        Die Liste liegt jetzt in der Warteschlange und wird von Hand durchgesehen,
        bevor sie in der App erscheint. Das dauert in der Regel ein paar Tage.
        Wenn du eine Kontaktmöglichkeit angegeben hast, gibt es danach Bescheid.
      </p>
      <p><a class="knopf" href="index.html">Zurück zur App</a></p>
    </section>
  <?php endif; ?>

  <section class="karte">
    <h2>Was ankommen kann</h2>
    <table class="formate">
      <thead><tr><th>Format</th><th>Hinweis</th></tr></thead>
      <tbody>
        <tr><td><strong>Excel (.xlsx)</strong></td><td>Erstes Tabellenblatt, zwei Spalten</td></tr>
        <tr><td><strong>CSV</strong></td><td>Semikolon, Komma und Tabulator werden alle erkannt</td></tr>
        <tr><td><strong>Eingefügte Tabelle</strong></td><td>Zeilen aus Excel oder Word direkt ins Textfeld kopieren</td></tr>
        <tr><td><strong>Einfache Zeilen</strong></td><td><code>apple - Apfel</code>, eine je Zeile</td></tr>
        <tr><td><strong>Foto</strong></td><td>Bild einer Wortschatzseite, wird im Browser gelesen</td></tr>
        <tr><td><strong>JSON</strong></td><td>Bringt den Titel selbst mit</td></tr>
      </tbody>
    </table>
    <p class="hinweis">
      Erwartet werden zwei Spalten: <strong>englisch</strong> und
      <strong>deutsch</strong>. Eine Kopfzeile mit Bezeichnungen wie
      <code>en</code>, <code>deutsch</code>, <code>emoji</code>,
      <code>kategorie</code> oder <code>beispiel</code> wird erkannt und
      zugeordnet. Ohne Kopfzeile gilt: erste Spalte englisch, zweite deutsch.
      Höchstens 500 Wörter je Liste.
    </p>
    <p class="hinweis">
      Zum Anschauen und Weiterarbeiten:
      <a href="vorlagen/wortliste-vorlage.csv" download>Vorlage als CSV</a> ·
      <a href="vorlagen/wortliste-vorlage.json" download>Vorlage als JSON</a>
    </p>
  </section>

  <form method="post" enctype="multipart/form-data" class="einreichung">
    <input type="hidden" name="marke" value="<?= wq_h($marke) ?>" />

    <!-- Honigtopf gegen automatisches Ausfüllen. Für Menschen unsichtbar und
         nicht erreichbar, auch nicht mit der Tastatur oder dem Screenreader. -->
    <div class="honigtopf" aria-hidden="true">
      <label for="webseite">Webseite (bitte frei lassen)</label>
      <input type="text" id="webseite" name="webseite" tabindex="-1" autocomplete="off" />
    </div>

    <section class="karte">
      <h2>1. Wie heißt die Liste?</h2>
      <label for="titel">Titel</label>
      <input type="text" id="titel" name="titel" maxlength="120"
             value="<?= wq_h((string) ($_POST['titel'] ?? '')) ?>"
             placeholder="zum Beispiel: Englisch Klasse 5, Unit 3" />
      <p class="hinweis">
        Bei einer JSON-Datei steht der Titel schon darin, sonst wird er hier
        gebraucht. Am hilfreichsten ist etwas, das Schulbuch und Kapitel nennt.
      </p>
    </section>

    <section class="karte">
      <h2>2. Die Wörter</h2>
      <p class="hinweis">Einer der drei Wege genügt.</p>

      <h3>Datei hochladen</h3>
      <label for="liste">Excel, CSV oder JSON</label>
      <input type="file" id="liste" name="liste"
             accept=".json,.csv,.tsv,.txt,.xlsx,application/json,text/csv" />

      <h3>… oder Tabelle einfügen</h3>
      <label for="eingefuegt">Zwei Spalten, eine Zeile je Wort</label>
      <textarea id="eingefuegt" name="eingefuegt" rows="7"
                placeholder="apple&#9;Apfel&#10;banana&#9;Banane"><?= wq_h((string) ($_POST['eingefuegt'] ?? '')) ?></textarea>
      <p class="hinweis">
        Aus Excel oder Word kopierte Zeilen lassen sich direkt einfügen.
        Erkannt werden Tabulator, Semikolon, Komma und auch „wort - bedeutung“.
      </p>

      <h3>… oder ein Foto der Buchseite</h3>
      <p class="hinweis">
        <strong>Das Foto bleibt auf deinem Gerät.</strong> Es wird nicht
        hochgeladen, die Texterkennung läuft im Browser. Abgeschickt wird nur
        die Tabelle, die du vorher durchsiehst. Gute Ergebnisse gibt es bei
        Fotos, die gerade von oben aufgenommen sind, bei gutem Licht und
        möglichst nur mit der Wortliste im Bild.
      </p>
      <label for="foto-datei">Bild oder Bildschirmfoto</label>
      <input type="file" id="foto-datei" accept="image/*" />
      <canvas id="foto-leinwand" class="foto-leinwand" hidden></canvas>
      <p>
        <button type="button" class="knopf schmal" id="foto-start" disabled>Text im Foto erkennen</button>
      </p>
      <p class="meldung hinweis-meldung" id="foto-stand" role="status" hidden></p>

      <div id="foto-ergebnis" hidden>
        <h3>Erkannte Zeilen durchsehen</h3>
        <p class="hinweis">
          Die Erkennung macht Fehler, das ist normal. Bitte jede Zeile prüfen,
          falsche entfernen und den Rest berichtigen. Was hier steht, landet im
          Textfeld oben und wird abgeschickt.
        </p>
        <div class="tabellen-rahmen">
          <table class="foto-tabelle">
            <thead><tr><th>Englisch</th><th>Deutsch</th><th><span class="sr-only">Zeile entfernen</span></th></tr></thead>
            <tbody id="foto-tabelle"></tbody>
          </table>
        </div>
        <p class="hinweis" id="foto-zaehler"></p>
      </div>
    </section>

    <section class="karte">
      <h2>3. Wer schickt das? (freiwillig)</h2>
      <p class="hinweis">
        Beides kannst du weglassen. Ohne Kontakt gibt es allerdings keine
        Rückmeldung, falls etwas unklar ist.
      </p>
      <label for="absender">Name</label>
      <input type="text" id="absender" name="absender" maxlength="80"
             value="<?= wq_h((string) ($_POST['absender'] ?? '')) ?>" autocomplete="name" />

      <label for="kontakt">E-Mail oder anderer Kontakt</label>
      <input type="text" id="kontakt" name="kontakt" maxlength="120"
             value="<?= wq_h((string) ($_POST['kontakt'] ?? '')) ?>" autocomplete="email" />

      <label for="bemerkung">Bemerkung</label>
      <textarea id="bemerkung" name="bemerkung" rows="3" maxlength="500"
                placeholder="Schulbuch, Klassenstufe, Besonderheiten"><?= wq_h((string) ($_POST['bemerkung'] ?? '')) ?></textarea>
    </section>

    <p class="absenden">
      <button type="submit" class="knopf gross">Liste einreichen</button>
    </p>
    <p class="hinweis">
      Nichts wird sofort sichtbar. Jede Einreichung wird von Hand durchgesehen,
      bevor sie in der App erscheint.
    </p>
  </form>

  <section class="karte">
    <h2>Was mit den Angaben passiert</h2>
    <ul>
      <li>Gespeichert werden die Wörter, der Titel und, falls angegeben, Name,
          Kontakt und Bemerkung.</li>
      <li>Fotos werden nie hochgeladen. Sie bleiben auf dem Gerät.</li>
      <li>Die App selbst setzt keine Cookies und legt keine Benutzerkonten an.</li>
      <li>Zum Schutz vor Massensendungen merkt sich der Server für höchstens
          einen Tag eine verschlüsselte Kennung des Anschlusses, aus der sich
          die Adresse nicht zurückrechnen lässt.</li>
    </ul>
  </section>
</main>

<footer class="fuss">
  <a class="fuss-logo" href="https://bildungssprit.de" target="_blank" rel="noopener noreferrer" aria-label="bildungssprit.de">
    <img src="https://img.bildungssprit.de/bildungssprit_logo.png" alt="bildungssprit Logo" loading="lazy" />
  </a>
  <span>
    &copy; 2026 Created by
    <a href="https://bildungssprit.de" target="_blank" rel="noopener noreferrer"><strong>bildungssprit</strong></a>
    | Falk Szyba @medienrocker
  </span>
</footer>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="ocr.js"></script>
</body>
</html>
