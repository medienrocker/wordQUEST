<?php
/**
 * wordQUEST – Foto einer Wortschatzseite in eine Wortliste überführen (WQ-9.5).
 *
 * Die Texterkennung läuft vollständig im Browser. Das Foto wird nie
 * hochgeladen, abgeschickt wird nur die korrigierte Tabelle als Text. Diese
 * geht dann durch genau denselben Einreichungsweg wie eine von Hand
 * eingefügte Tabelle.
 *
 * Diese Seite ist die einzige im Admincenter, die JavaScript braucht, und
 * bekommt deshalb eine eigene Richtlinie. Alle anderen bleiben bei
 * script-src 'none'.
 */
declare(strict_types=1);

define('WQ_ADMIN', true);

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';
require __DIR__ . '/../api/lib/wortlisten.php';
require __DIR__ . '/../api/lib/import.php';

$admin = wq_verlange_login();
$pdo = wq_db();
wq_einreichungen_schema($pdo);

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$meldung = '';
$meldungArt = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $titel = trim((string) ($_POST['titel'] ?? ''));
    $text = trim((string) ($_POST['eingefuegt'] ?? ''));

    if ($text === '') {
        $meldung = 'Es wurden keine Wortpaare übernommen.';
        $meldungArt = 'fehler';
    } else {
        $import = wq_import(mb_substr($text, 0, WQ_LISTE_MAX_BYTES), 'foto', $titel);
        if (!$import['ok']) {
            $meldung = $import['fehler'];
            $meldungArt = 'fehler';
        } else {
            $roh = (string) json_encode($import['daten'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pruefung = wq_wortliste_pruefen($roh);
            if ($pruefung['ok']) {
                $id = wq_einreichung_speichern($pdo, $roh, 'Foto', $pruefung);
                $meldung = count($pruefung['daten']['words']) . ' Wörter übernommen. '
                         . 'Einreichung Nummer ' . $id . ' liegt unter Wortlisten zur Freigabe bereit.';
            } else {
                $meldung = 'Die Daten wurden nicht übernommen: ' . implode(' ', $pruefung['fehler']);
                $meldungArt = 'fehler';
            }
        }
    }
}

/* Eigene Richtlinie: Texterkennung braucht Skript, WebAssembly, einen Worker
   aus einem Blob und Zugriff auf die Sprachdaten. Bewusst eng gehalten und
   nur auf dieser einen Seite. */
$wqCsp = "default-src 'none'; "
    . "script-src 'self' 'wasm-unsafe-eval' https://cdn.jsdelivr.net; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' blob: data: https://img.bildungssprit.de; "
    . "connect-src 'self' blob: data: https://cdn.jsdelivr.net https://tessdata.projectnaptha.com; "
    . "worker-src blob:; "
    . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

$csrf = wq_csrf_token();
require __DIR__ . '/kopf.php';
?>
<h1>Foto in eine Wortliste verwandeln</h1>

<?php if ($meldung !== ''): ?>
  <p class="meldung <?= $meldungArt === 'fehler' ? 'fehler' : '' ?>" role="status"><?= wq_h($meldung) ?></p>
<?php endif; ?>

<section class="karte">
  <h2>1. Foto auswählen</h2>
  <p class="hinweis">
    <strong>Das Foto bleibt auf diesem Gerät.</strong> Es wird nicht hochgeladen,
    die Texterkennung läuft im Browser. Abgeschickt wird nur die Tabelle, die du
    in Schritt 3 bestätigst.
  </p>
  <p class="hinweis">
    Gute Ergebnisse gibt es bei Fotos, die gerade von oben aufgenommen sind, bei
    gutem Licht, möglichst nur die Wortliste im Bild. Handschrift wird nicht
    erkannt, Lautschrift wird automatisch aussortiert.
  </p>
  <label for="foto-datei">Bild oder Bildschirmfoto</label>
  <input type="file" id="foto-datei" accept="image/*" />
  <canvas id="foto-leinwand" class="foto-leinwand" hidden></canvas>
</section>

<section class="karte">
  <h2>2. Text erkennen</h2>
  <button type="button" class="btn schmal" id="foto-start" disabled>Texterkennung starten</button>
  <p class="meldung hinweis-meldung" id="foto-stand" role="status" hidden></p>
  <p class="hinweis">
    Beim ersten Mal lädt der Browser die Sprachdaten für Englisch und Deutsch,
    das dauert einen Moment. Danach liegen sie im Browserspeicher.
  </p>
</section>

<section class="karte" id="foto-ergebnis" hidden>
  <h2>3. Durchsehen und übernehmen</h2>
  <p class="hinweis">
    Die Erkennung macht Fehler, das ist normal. Bitte jede Zeile prüfen,
    falsche entfernen und den Rest korrigieren. Erst dann übernehmen.
  </p>
  <div class="tabellen-rahmen">
    <table class="foto-tabelle">
      <thead><tr><th>Englisch</th><th>Deutsch</th><th></th></tr></thead>
      <tbody id="foto-tabelle"></tbody>
    </table>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
    <label for="titel">Titel der Liste</label>
    <input type="text" id="titel" name="titel" maxlength="120" required
           placeholder="z. B. NHG 1 · Unit 3" />
    <textarea id="eingefuegt" name="eingefuegt" hidden></textarea>
    <p class="hinweis" id="foto-zaehler"></p>
    <button type="submit" class="btn schmal">Als Einreichung übernehmen</button>
  </form>
</section>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="../ocr.js"></script>
<?php require __DIR__ . '/fuss.php'; ?>
