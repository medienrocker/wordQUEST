<?php
/**
 * wordQUEST – Bilderverwaltung (WQ-7.5).
 *
 * Zeigt je Wortliste, welche Wörter noch keine Visualisierung haben, nimmt
 * Bilder entgegen und trägt sie ein. Hochgeladene Bytes werden nie
 * ausgeliefert, das Bild wird mit GD neu erzeugt.
 */
declare(strict_types=1);

define('WQ_ADMIN', true);

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';
require __DIR__ . '/../api/lib/wortlisten.php';
require __DIR__ . '/../api/lib/bilder.php';

$admin = wq_verlange_login();

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$meldung = '';
$meldungArt = 'ok';
$listen = wq_vorhandene_listen();
$gewaehlt = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $aktion = (string) ($_POST['aktion'] ?? '');
    $gewaehlt = basename((string) ($_POST['liste'] ?? ''));

    if ($aktion === 'zuordnen') {
        $wort = (string) ($_POST['wort'] ?? '');
        $ergebnis = wq_bild_aufnehmen($_FILES['bild'] ?? [], $wort);
        if (empty($ergebnis['ok'])) {
            $meldung = (string) $ergebnis['fehler'];
            $meldungArt = 'fehler';
        } else {
            $zuordnung = wq_bild_zuordnen($gewaehlt, $wort, (string) $ergebnis['url']);
            if (empty($zuordnung['ok'])) {
                $meldung = (string) $zuordnung['fehler'];
                $meldungArt = 'fehler';
            } else {
                $meldung = 'Bild für "' . $wort . '" gespeichert als ' . $ergebnis['datei'] . '.';
            }
        }

    } elseif ($aktion === 'entfernen') {
        $wort = (string) ($_POST['wort'] ?? '');
        $zuordnung = wq_bild_zuordnen($gewaehlt, $wort, null);
        $meldung = !empty($zuordnung['ok'])
            ? 'Zuordnung bei "' . $wort . '" entfernt. Die Bilddatei bleibt erhalten.'
            : (string) $zuordnung['fehler'];
        $meldungArt = !empty($zuordnung['ok']) ? 'ok' : 'fehler';

    } elseif ($aktion === 'datei-loeschen') {
        $ergebnis = wq_bild_loeschen((string) ($_POST['datei'] ?? ''));
        $meldung = !empty($ergebnis['ok']) ? 'Bilddatei gelöscht.' : (string) $ergebnis['fehler'];
        $meldungArt = !empty($ergebnis['ok']) ? 'ok' : 'fehler';
    }
}

if ($gewaehlt === '' && isset($_GET['liste'])) {
    $gewaehlt = basename((string) $_GET['liste']);
}
// Nur Dateien, die es wirklich gibt.
$bekannt = array_column($listen, 'datei');
if ($gewaehlt !== '' && !in_array($gewaehlt, $bekannt, true)) {
    $gewaehlt = '';
}

$woerter = $gewaehlt !== '' ? wq_woerter_mit_status($gewaehlt) : [];
$ohneBild = array_values(array_filter($woerter, static fn($w) => $w['img'] === '' && $w['emoji'] === ''));
$mitBild  = array_values(array_filter($woerter, static fn($w) => $w['img'] !== ''));
$bilder = wq_bilder_liste();
$gdDa = function_exists('imagewebp');
$csrf = wq_csrf_token();

require __DIR__ . '/kopf.php';
?>
<h1>Bilder</h1>

<?php if ($meldung !== ''): ?>
  <p class="meldung <?= $meldungArt === 'fehler' ? 'fehler' : '' ?>" role="status"><?= wq_h($meldung) ?></p>
<?php endif; ?>

<?php if (!$gdDa): ?>
  <p class="meldung fehler">
    Diesem PHP fehlt die Bildbibliothek GD mit WebP-Unterstützung. Das Hochladen
    von Bildern funktioniert deshalb nicht.
  </p>
<?php endif; ?>

<section class="karte">
  <h2>Wortliste wählen</h2>
  <form method="get">
    <label for="liste">Liste</label>
    <select id="liste" name="liste">
      <option value="">– bitte wählen –</option>
      <?php foreach ($listen as $l): ?>
        <option value="<?= wq_h($l['datei']) ?>"<?= $l['datei'] === $gewaehlt ? ' selected' : '' ?>>
          <?= wq_h($l['titel']) ?> (<?= (int) $l['anzahl'] ?> Wörter)
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn schmal">Anzeigen</button>
  </form>
</section>

<?php if ($gewaehlt !== ''): ?>
<section class="karte">
  <h2>Ohne Visualisierung (<?= count($ohneBild) ?>)</h2>
  <p class="hinweis">
    Diese Wörter haben weder Bild noch Emoji. Die App zeigt dort einen farbigen
    Kreis mit dem Anfangsbuchstaben. Ein Emoji reicht oft völlig und kostet
    keine Ladezeit, ein Bild lohnt sich vor allem dort, wo kein passendes
    Emoji existiert.
  </p>
  <?php if (!$ohneBild): ?>
    <p class="leer">Alle Wörter dieser Liste haben ein Bild oder ein Emoji.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Englisch</th><th>Deutsch</th><th>Bild hochladen</th></tr></thead>
      <tbody>
      <?php foreach ($ohneBild as $w): ?>
        <tr>
          <td><strong><?= wq_h($w['en']) ?></strong></td>
          <td><?= wq_h($w['de']) ?></td>
          <td>
            <form method="post" enctype="multipart/form-data" class="reihe">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="aktion" value="zuordnen" />
              <input type="hidden" name="liste" value="<?= wq_h($gewaehlt) ?>" />
              <input type="hidden" name="wort" value="<?= wq_h($w['en']) ?>" />
              <input type="file" name="bild" accept="image/jpeg,image/png,image/webp,image/gif" required<?= $gdDa ? '' : ' disabled' ?> />
              <button type="submit" class="klein"<?= $gdDa ? '' : ' disabled' ?>>hochladen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="karte">
  <h2>Mit Bild (<?= count($mitBild) ?>)</h2>
  <?php if (!$mitBild): ?>
    <p class="leer">Noch keine Bilder zugeordnet.</p>
  <?php else: ?>
    <div class="bildraster">
      <?php foreach ($mitBild as $w): ?>
        <figure class="bildkachel">
          <img src="../<?= wq_h($w['img']) ?>" alt="Bild zu <?= wq_h($w['en']) ?>" loading="lazy" width="120" height="120" />
          <figcaption><?= wq_h($w['en']) ?><br /><small><?= wq_h($w['de']) ?></small></figcaption>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
            <input type="hidden" name="aktion" value="entfernen" />
            <input type="hidden" name="liste" value="<?= wq_h($gewaehlt) ?>" />
            <input type="hidden" name="wort" value="<?= wq_h($w['en']) ?>" />
            <button type="submit" class="klein">Zuordnung lösen</button>
          </form>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="karte">
  <h2>Abgelegte Bilddateien (<?= count($bilder) ?>)</h2>
  <p class="hinweis">
    Alle hochgeladenen Bilder liegen als WebP mit höchstens 256 Pixel Kantenlänge
    in <code>img/auto/</code>. Sie sind nicht im Repository und gehören deshalb
    in die Sicherung. Gelöscht werden kann nur, worauf keine Liste mehr zeigt.
  </p>
  <?php if (!$bilder): ?>
    <p class="leer">Noch keine Bilder hochgeladen.</p>
  <?php else: ?>
    <div class="bildraster">
      <?php foreach ($bilder as $b): ?>
        <figure class="bildkachel">
          <img src="../<?= wq_h($b['url']) ?>" alt="" loading="lazy" width="120" height="120" />
          <figcaption><small><?= wq_h($b['datei']) ?><br /><?= (int) round($b['groesse'] / 1024) ?> KB</small></figcaption>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
            <input type="hidden" name="aktion" value="datei-loeschen" />
            <input type="hidden" name="datei" value="<?= wq_h($b['datei']) ?>" />
            <button type="submit" class="klein">löschen</button>
          </form>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/fuss.php'; ?>
