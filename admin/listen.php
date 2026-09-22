<?php
/**
 * wordQUEST – Wortlisten verwalten (WQ-7.4).
 *
 * Hochladen, prüfen, ansehen, freigeben. Der Upload landet zunächst als
 * Einreichung in der Datenbank, nie direkt im ausgelieferten Verzeichnis.
 * Erst die Freigabe erzeugt dort eine Datei, und zwar neu aus den geprüften
 * Werten statt aus den hochgeladenen Bytes.
 */
declare(strict_types=1);

define('WQ_ADMIN', true);

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';
require __DIR__ . '/../api/lib/wortlisten.php';
require __DIR__ . '/../api/lib/import.php';
require __DIR__ . '/../api/lib/einreichung.php';
require __DIR__ . '/../api/lib/archiv.php';

$admin = wq_verlange_login();
$pdo = wq_db();
wq_einreichung_felder_ergaenzen($pdo);

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$meldung = '';
$meldungArt = 'ok';
$pruefung = null;
$zuEntfernen = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $aktion = (string) ($_POST['aktion'] ?? '');

    if ($aktion === 'hochladen') {
        $titel = trim((string) ($_POST['titel'] ?? ''));
        $eingefuegt = trim((string) ($_POST['eingefuegt'] ?? ''));
        $datei = $_FILES['liste'] ?? null;
        $hatDatei = is_array($datei) && ($datei['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        $roh = '';
        $quelle = '';
        $tempPfad = null;

        if ($hatDatei && (int) $datei['size'] > WQ_LISTE_MAX_BYTES) {
            $meldung = 'Die Datei ist größer als 512 KB.';
            $meldungArt = 'fehler';
        } elseif ($hatDatei) {
            // Inhalt prüfen, nicht die Dateiendung. Eine .json-Endung sagt
            // nichts darüber aus, was in der Datei steht.
            $roh = (string) file_get_contents($datei['tmp_name'], false, null, 0, WQ_LISTE_MAX_BYTES + 1);
            $quelle = (string) ($datei['name'] ?? '');
            $tempPfad = (string) $datei['tmp_name'];
        } elseif ($eingefuegt !== '') {
            $roh = mb_substr($eingefuegt, 0, WQ_LISTE_MAX_BYTES);
            $quelle = 'eingefügt';
        } else {
            $meldung = 'Bitte eine Datei wählen oder eine Tabelle einfügen.';
            $meldungArt = 'fehler';
        }

        if ($roh !== '') {
            $import = wq_import($roh, $quelle, $titel, $tempPfad);
            if (!$import['ok']) {
                $meldung = $import['fehler'];
                $meldungArt = 'fehler';
            } else {
                // Aus Tabellen und eingefügtem Text wird erst JSON erzeugt,
                // danach läuft alles durch dieselbe Prüfung wie ein
                // hochgeladenes JSON. Es gibt keinen zweiten Weg hinein.
                if ($import['daten'] !== null) {
                    $roh = (string) json_encode($import['daten'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $pruefung = wq_wortliste_pruefen($roh);
                if ($pruefung['ok']) {
                    $id = wq_einreichung_speichern($pdo, $roh, $quelle, $pruefung);
                    $meldung = 'Erkannt als ' . wq_format_name($import['format']) . '. '
                             . count($pruefung['daten']['words']) . ' Wörter gelesen, '
                             . 'Einreichung Nummer ' . $id . ' liegt bereit.';
                } else {
                    $meldung = 'Die Daten wurden nicht übernommen.';
                    $meldungArt = 'fehler';
                }
            }
        }

    } elseif ($aktion === 'veroeffentlichen') {
        $ergebnis = wq_einreichung_veroeffentlichen($pdo, (int) ($_POST['id'] ?? 0), $admin['name']);
        if (!empty($ergebnis['ok'])) {
            $meldung = 'Veröffentlicht als ' . $ergebnis['datei'] . '. Die Liste erscheint sofort in der App.';
        } else {
            $meldung = (string) $ergebnis['fehler'];
            $meldungArt = 'fehler';
        }

    } elseif ($aktion === 'ablehnen') {
        wq_einreichung_ablehnen($pdo, (int) ($_POST['id'] ?? 0), $admin['name']);
        $meldung = 'Einreichung abgelehnt. Sie bleibt erhalten und lässt sich weiter unten zurückholen.';

    } elseif ($aktion === 'archivieren') {
        $ergebnis = wq_liste_archivieren((string) ($_POST['datei'] ?? ''));
        $meldung = !empty($ergebnis['ok'])
            ? 'Die Liste liegt jetzt im Archiv und erscheint nicht mehr in der App. Die Datei bleibt unangetastet.'
            : (string) $ergebnis['fehler'];
        $meldungArt = !empty($ergebnis['ok']) ? 'ok' : 'fehler';

    } elseif ($aktion === 'entarchivieren') {
        $ergebnis = wq_liste_entarchivieren((string) ($_POST['datei'] ?? ''));
        $meldung = !empty($ergebnis['ok'])
            ? 'Die Liste ist zurück und erscheint wieder in der App.'
            : (string) $ergebnis['fehler'];
        $meldungArt = !empty($ergebnis['ok']) ? 'ok' : 'fehler';

    } elseif ($aktion === 'entfernen') {
        /* Zweistufig mit Absicht. Ohne JavaScript gibt es keinen
           Bestätigungsdialog, also fragt die Seite selbst nach. */
        $zuEntfernen = basename((string) ($_POST['datei'] ?? ''));

    } elseif ($aktion === 'entfernen-bestaetigt') {
        $ergebnis = wq_liste_endgueltig_entfernen((string) ($_POST['datei'] ?? ''));
        if (!empty($ergebnis['ok'])) {
            $meldung = 'Die Liste ist aus dem Auslieferungsverzeichnis entfernt. '
                     . 'Eine Kopie liegt weiterhin unter private/archiv/dateien/, gelöscht wird dort nur von Hand.';
        } else {
            $meldung = (string) $ergebnis['fehler'];
            $meldungArt = 'fehler';
        }

    } elseif ($aktion === 'zurueckholen') {
        $ergebnis = wq_einreichung_zurueckholen($pdo, (int) ($_POST['id'] ?? 0));
        if (!empty($ergebnis['ok'])) {
            $meldung = 'Zurück in der Warteschlange. Die Einreichung steht wieder oben.';
        } else {
            $meldung = (string) $ergebnis['fehler'];
            $meldungArt = 'fehler';
        }
    }
}

/* Vorschau einer Einreichung */
$vorschau = null;
$vorschauPruefung = null;
if (isset($_GET['ansehen'])) {
    $vorschau = wq_einreichung_holen($pdo, (int) $_GET['ansehen']);
    if ($vorschau) {
        $vorschauPruefung = wq_wortliste_pruefen((string) $vorschau['inhalt']);
    }
}

$offen = $pdo->query('SELECT * FROM einreichungen WHERE status = "neu" ORDER BY id DESC')->fetchAll();
/* Abgelehnte vollständig, nicht nur die letzten: Genau sie will man später
   vielleicht zurückholen, und dann dürfen sie nicht hinten abgeschnitten sein. */
$abgelehnt = $pdo->query('SELECT * FROM einreichungen WHERE status = "abgelehnt" ORDER BY id DESC')->fetchAll();
$erledigt = $pdo->query('SELECT * FROM einreichungen WHERE status = "veroeffentlicht" ORDER BY id DESC LIMIT 20')->fetchAll();
$alleListen = wq_vorhandene_listen();
$listen = array_values(array_filter($alleListen, static fn($l) => empty($l['archiviert'])));
$archivListen = array_values(array_filter($alleListen, static fn($l) => !empty($l['archiviert'])));
$archivDateien = wq_archiv_dateien();
$csrf = wq_csrf_token();

require __DIR__ . '/kopf.php';
?>
<h1>Wortlisten</h1>

<?php if ($meldung !== ''): ?>
  <p class="meldung <?= $meldungArt === 'fehler' ? 'fehler' : '' ?>" role="status"><?= wq_h($meldung) ?></p>
<?php endif; ?>

<?php if ($zuEntfernen !== ''): ?>
  <section class="karte warnung">
    <h2>Wirklich aus dem Auslieferungsverzeichnis entfernen?</h2>
    <p>
      Die Datei <code><?= wq_h($zuEntfernen) ?></code> verschwindet aus
      <code>wordlists/</code>. Eine Kopie bleibt unter
      <code>private/archiv/dateien/</code> liegen, dort wird nur von Hand
      aufgeräumt. Auch die früheren Fassungen bleiben erhalten.
    </p>
    <p class="hinweis">
      Falls diese Liste aus dem Repository stammt, fehlt sie danach im
      Arbeitsverzeichnis des Servers. Zurückholen liesse sie sich dann über das
      Repository. Zum reinen Ausblenden genügt das Archiv, dafür muss nichts
      entfernt werden.
    </p>
    <p class="aktionen">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
        <input type="hidden" name="aktion" value="entfernen-bestaetigt" />
        <input type="hidden" name="datei" value="<?= wq_h($zuEntfernen) ?>" />
        <button type="submit" class="klein gefaehrlich">Ja, entfernen</button>
      </form>
      <a class="klein-link" href="listen.php">Abbrechen</a>
    </p>
  </section>
<?php endif; ?>

<?php if ($pruefung && (!$pruefung['ok'] || $pruefung['hinweise'])): ?>
  <section class="karte">
    <h2>Ergebnis der Prüfung</h2>
    <?php foreach ($pruefung['fehler'] as $f): ?>
      <p class="meldung fehler"><?= wq_h($f) ?></p>
    <?php endforeach; ?>
    <?php foreach ($pruefung['hinweise'] as $h): ?>
      <p class="meldung hinweis-meldung"><?= wq_h($h) ?></p>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<section class="karte">
  <h2>Liste hochladen</h2>
  <p class="hinweis">
    Möglich sind <strong>CSV</strong>, <strong>Excel (.xlsx)</strong>,
    <strong>JSON</strong> oder eine einfach <strong>eingefügte Tabelle</strong>.
    Erwartet werden zwei Spalten: englisch und deutsch. Eine Kopfzeile mit
    Bezeichnungen wie <code>en</code>, <code>deutsch</code>, <code>emoji</code>,
    <code>kategorie</code> oder <code>beispiel</code> wird erkannt und
    zugeordnet. Höchstens 512 KB und 500 Wörter.
  </p>
  <p class="hinweis">
    Nichts wird sofort sichtbar: Alles landet zuerst als Einreichung und wird
    erst durch die Freigabe veröffentlicht.
  </p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
    <input type="hidden" name="aktion" value="hochladen" />

    <label for="titel">Titel der Liste</label>
    <input type="text" id="titel" name="titel" maxlength="120"
           placeholder="z. B. NHG 1 · Unit 3" />
    <p class="hinweis">Bei JSON wird der Titel aus der Datei genommen, sonst wird er hier gebraucht.</p>

    <label for="liste">Datei</label>
    <input type="file" id="liste" name="liste" accept=".json,.csv,.tsv,.txt,.xlsx,application/json,text/csv" />

    <label for="eingefuegt">… oder Tabelle einfügen</label>
    <textarea id="eingefuegt" name="eingefuegt" rows="6"
              placeholder="apple&#9;Apfel&#10;banana&#9;Banane"></textarea>
    <p class="hinweis">
      Aus Excel oder Word kopierte Zeilen lassen sich direkt einfügen.
      Erkannt werden Tabulator, Semikolon, Komma und auch „wort - bedeutung“.
    </p>

    <button type="submit" class="btn schmal">Prüfen und ablegen</button>
  </form>
</section>

<?php if ($vorschau && $vorschauPruefung): ?>
<section class="karte">
  <h2>Vorschau: <?= wq_h((string) ($vorschau['titel'] ?? 'ohne Titel')) ?></h2>
  <p class="hinweis">
    Eingereicht am <?= wq_h((string) $vorschau['eingereicht_am']) ?>,
    Datei <?= wq_h((string) ($vorschau['originalname'] ?? 'unbekannt')) ?>,
    <?= (int) $vorschau['anzahl_woerter'] ?> Wörter.
  </p>
  <?php
  /* Begleitangaben aus dem öffentlichen Formular. Alles davon kommt von
     aussen und wird deshalb escaped ausgegeben, nie als Markup. */
  $hatBegleitung = ($vorschau['absender'] ?? '') !== '' || ($vorschau['kontakt'] ?? '') !== ''
                || ($vorschau['bemerkung'] ?? '') !== '';
  ?>
  <?php if ($hatBegleitung): ?>
    <dl class="begleitung">
      <?php if (($vorschau['absender'] ?? '') !== ''): ?>
        <dt>Eingereicht von</dt><dd><?= wq_h((string) $vorschau['absender']) ?></dd>
      <?php endif; ?>
      <?php if (($vorschau['kontakt'] ?? '') !== ''): ?>
        <dt>Kontakt</dt><dd><?= wq_h((string) $vorschau['kontakt']) ?></dd>
      <?php endif; ?>
      <?php if (($vorschau['bemerkung'] ?? '') !== ''): ?>
        <dt>Bemerkung</dt><dd><?= nl2br(wq_h((string) $vorschau['bemerkung'])) ?></dd>
      <?php endif; ?>
    </dl>
  <?php endif; ?>
  <?php foreach ($vorschauPruefung['hinweise'] as $h): ?>
    <p class="meldung hinweis-meldung"><?= wq_h($h) ?></p>
  <?php endforeach; ?>
  <?php if (!empty($vorschauPruefung['daten']['words'])): ?>
    <table>
      <thead><tr><th>Englisch</th><th>Deutsch</th><th>Bild</th><th>Kategorie</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($vorschauPruefung['daten']['words'], 0, 60) as $w): ?>
        <tr>
          <td><?= wq_h($w['en']) ?></td>
          <td><?= wq_h($w['de']) ?></td>
          <td><?= isset($w['emoji']) ? wq_h($w['emoji']) : (isset($w['img']) ? 'Bild' : '–') ?></td>
          <td><?= wq_h($w['cat'] ?? '–') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($vorschauPruefung['daten']['words']) > 60): ?>
      <p class="hinweis">… und <?= count($vorschauPruefung['daten']['words']) - 60 ?> weitere.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="karte">
  <h2>Offene Einreichungen<?= $offen ? ' (' . count($offen) . ')' : '' ?></h2>
  <?php if (!$offen): ?>
    <p class="leer">Nichts offen.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Nr.</th><th>Titel</th><th class="zahl">Wörter</th><th>Von</th><th>Eingereicht</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($offen as $e): ?>
        <tr>
          <td><?= (int) $e['id'] ?></td>
          <td><strong><?= wq_h((string) ($e['titel'] ?? 'ohne Titel')) ?></strong></td>
          <td class="zahl"><?= (int) $e['anzahl_woerter'] ?></td>
          <td><?= wq_h((string) ($e['absender'] ?? '')) ?: '<span class="leer">anonym</span>' ?></td>
          <td><?= wq_h((string) $e['eingereicht_am']) ?></td>
          <td class="aktionen">
            <a class="klein-link" href="?ansehen=<?= (int) $e['id'] ?>">ansehen</a>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="id" value="<?= (int) $e['id'] ?>" />
              <input type="hidden" name="aktion" value="veroeffentlichen" />
              <button type="submit" class="klein">freigeben</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="id" value="<?= (int) $e['id'] ?>" />
              <input type="hidden" name="aktion" value="ablehnen" />
              <button type="submit" class="klein">ablehnen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="karte">
  <h2>Veröffentlichte Listen (<?= count($listen) ?>)</h2>
  <p class="hinweis">
    Diese Dateien liegen im Verzeichnis <code>wordlists/</code> und werden von
    der App geladen. Über die Oberfläche freigegebene Listen liegen nur auf
    dem Server, nicht im Repository. Sie gehören deshalb in die Sicherung.
  </p>
  <table>
    <thead><tr><th>Titel</th><th>Datei</th><th class="zahl">Wörter</th><th>Geändert</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($listen as $l): ?>
      <tr>
        <td><strong><?= wq_h($l['titel']) ?></strong></td>
        <td><?= wq_h($l['datei']) ?></td>
        <td class="zahl"><?= (int) $l['anzahl'] ?></td>
        <td><?= wq_h($l['geaendert']) ?></td>
        <td class="aktionen">
          <a class="klein-link" href="bearbeiten.php?liste=<?= urlencode($l['datei']) ?>">bearbeiten</a>
          <a class="klein-link" href="bilder.php?liste=<?= urlencode($l['datei']) ?>">Bilder</a>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
            <input type="hidden" name="aktion" value="archivieren" />
            <input type="hidden" name="datei" value="<?= wq_h($l['datei']) ?>" />
            <button type="submit" class="klein">archivieren</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="karte">
  <h2>Archiv<?= $archivListen ? ' (' . count($archivListen) . ')' : '' ?></h2>
  <p class="hinweis">
    Archivierte Listen erscheinen nicht mehr in der App. Die Dateien bleiben
    dabei unberührt, vermerkt wird nur der Name. Zurückholen geht jederzeit
    und ohne Datenverlust.
  </p>
  <?php if (!$archivListen): ?>
    <p class="leer">Nichts archiviert.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Titel</th><th>Datei</th><th class="zahl">Wörter</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($archivListen as $l): ?>
        <tr>
          <td><strong><?= wq_h($l['titel']) ?></strong></td>
          <td><?= wq_h($l['datei']) ?></td>
          <td class="zahl"><?= (int) $l['anzahl'] ?></td>
          <td class="aktionen">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="aktion" value="entarchivieren" />
              <input type="hidden" name="datei" value="<?= wq_h($l['datei']) ?>" />
              <button type="submit" class="klein">zurückholen</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="aktion" value="entfernen" />
              <input type="hidden" name="datei" value="<?= wq_h($l['datei']) ?>" />
              <button type="submit" class="klein">entfernen …</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($archivDateien): ?>
    <h3>Abgelegte Dateien (<?= count($archivDateien) ?>)</h3>
    <p class="hinweis">
      Entfernte Listen liegen als Kopie unter <code>private/archiv/dateien/</code>,
      also ausserhalb des Docroots und in der Sicherung. Gelöscht wird dort nur
      von Hand.
    </p>
    <table>
      <thead><tr><th>Datei</th><th>Titel</th><th class="zahl">Wörter</th></tr></thead>
      <tbody>
      <?php foreach ($archivDateien as $a): ?>
        <tr>
          <td><?= wq_h($a['datei']) ?></td>
          <td><?= wq_h($a['titel']) ?></td>
          <td class="zahl"><?= (int) $a['anzahl'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php if ($abgelehnt): ?>
<section class="karte">
  <h2>Abgelehnt (<?= count($abgelehnt) ?>)</h2>
  <p class="hinweis">
    Abgelehnt heißt nicht gelöscht. Der Inhalt bleibt vollständig erhalten und
    lässt sich jederzeit zurück in die Warteschlange stellen.
  </p>
  <table>
    <thead><tr><th>Nr.</th><th>Titel</th><th class="zahl">Wörter</th><th>Abgelehnt von</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($abgelehnt as $e): ?>
      <tr>
        <td><?= (int) $e['id'] ?></td>
        <td><?= wq_h((string) ($e['titel'] ?? 'ohne Titel')) ?></td>
        <td class="zahl"><?= (int) $e['anzahl_woerter'] ?></td>
        <td><?= wq_h((string) ($e['bearbeitet_von'] ?? '')) ?></td>
        <td class="aktionen">
          <a class="klein-link" href="?ansehen=<?= (int) $e['id'] ?>">ansehen</a>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
            <input type="hidden" name="id" value="<?= (int) $e['id'] ?>" />
            <input type="hidden" name="aktion" value="zurueckholen" />
            <button type="submit" class="klein">zurückholen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php if ($erledigt): ?>
<section class="karte">
  <h2>Zuletzt veröffentlicht</h2>
  <table>
    <thead><tr><th>Nr.</th><th>Titel</th><th>Datei</th><th>Von</th><th>Wann</th></tr></thead>
    <tbody>
    <?php foreach ($erledigt as $e): ?>
      <tr>
        <td><?= (int) $e['id'] ?></td>
        <td><?= wq_h((string) ($e['titel'] ?? '')) ?></td>
        <td><?= wq_h((string) ($e['zieldatei'] ?? '–')) ?></td>
        <td><?= wq_h((string) ($e['bearbeitet_von'] ?? '')) ?></td>
        <td><?= wq_h((string) ($e['bearbeitet_am'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php require __DIR__ . '/fuss.php'; ?>
