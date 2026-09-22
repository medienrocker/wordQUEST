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

$admin = wq_verlange_login();
$pdo = wq_db();
wq_einreichungen_schema($pdo);

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$meldung = '';
$meldungArt = 'ok';
$pruefung = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $aktion = (string) ($_POST['aktion'] ?? '');

    if ($aktion === 'hochladen') {
        $datei = $_FILES['liste'] ?? null;
        if (!is_array($datei) || ($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $meldung = 'Es wurde keine Datei übertragen.';
            $meldungArt = 'fehler';
        } elseif ((int) $datei['size'] > WQ_LISTE_MAX_BYTES) {
            $meldung = 'Die Datei ist größer als 512 KB.';
            $meldungArt = 'fehler';
        } else {
            // Inhalt prüfen, nicht die Dateiendung. Eine .json-Endung sagt
            // nichts darüber aus, was in der Datei steht.
            $roh = (string) file_get_contents($datei['tmp_name'], false, null, 0, WQ_LISTE_MAX_BYTES + 1);
            $pruefung = wq_wortliste_pruefen($roh);
            if ($pruefung['ok']) {
                $id = wq_einreichung_speichern($pdo, $roh, (string) ($datei['name'] ?? ''), $pruefung);
                $meldung = 'Die Liste ist geprüft und liegt als Einreichung Nummer ' . $id . ' bereit.';
            } else {
                $meldung = 'Die Datei wurde nicht übernommen.';
                $meldungArt = 'fehler';
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
        $meldung = 'Einreichung abgelehnt.';
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
$erledigt = $pdo->query('SELECT * FROM einreichungen WHERE status != "neu" ORDER BY id DESC LIMIT 20')->fetchAll();
$listen = wq_vorhandene_listen();
$csrf = wq_csrf_token();

require __DIR__ . '/kopf.php';
?>
<h1>Wortlisten</h1>

<?php if ($meldung !== ''): ?>
  <p class="meldung <?= $meldungArt === 'fehler' ? 'fehler' : '' ?>" role="status"><?= wq_h($meldung) ?></p>
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
    JSON nach dem Schema aus der Projektdokumentation, höchstens 512 KB und
    500 Wörter. Die Datei wird geprüft und als Einreichung abgelegt. Erst die
    Freigabe macht sie in der App sichtbar.
  </p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
    <input type="hidden" name="aktion" value="hochladen" />
    <input type="file" name="liste" accept=".json,application/json" required />
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
      <thead><tr><th>Nr.</th><th>Titel</th><th class="zahl">Wörter</th><th>Eingereicht</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($offen as $e): ?>
        <tr>
          <td><?= (int) $e['id'] ?></td>
          <td><strong><?= wq_h((string) ($e['titel'] ?? 'ohne Titel')) ?></strong></td>
          <td class="zahl"><?= (int) $e['anzahl_woerter'] ?></td>
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
    <thead><tr><th>Titel</th><th>Datei</th><th class="zahl">Wörter</th><th>Geändert</th></tr></thead>
    <tbody>
    <?php foreach ($listen as $l): ?>
      <tr>
        <td><strong><?= wq_h($l['titel']) ?></strong></td>
        <td><?= wq_h($l['datei']) ?></td>
        <td class="zahl"><?= (int) $l['anzahl'] ?></td>
        <td><?= wq_h($l['geaendert']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($erledigt): ?>
<section class="karte">
  <h2>Zuletzt bearbeitet</h2>
  <table>
    <thead><tr><th>Nr.</th><th>Titel</th><th>Status</th><th>Datei</th><th>Von</th></tr></thead>
    <tbody>
    <?php foreach ($erledigt as $e): ?>
      <tr>
        <td><?= (int) $e['id'] ?></td>
        <td><?= wq_h((string) ($e['titel'] ?? '')) ?></td>
        <td><?= $e['status'] === 'veroeffentlicht' ? 'veröffentlicht' : 'abgelehnt' ?></td>
        <td><?= wq_h((string) ($e['zieldatei'] ?? '–')) ?></td>
        <td><?= wq_h((string) ($e['bearbeitet_von'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php require __DIR__ . '/fuss.php'; ?>
