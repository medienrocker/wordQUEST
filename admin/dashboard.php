<?php
/**
 * wordQUEST – Übersicht über die anonyme Nutzungsstatistik.
 *
 * Bewusst der erste Inhalt des Admincenters: Er macht die Daten sichtbar, die
 * seit WQ-7.2 zusammenkommen, und braucht dafür keine schreibende Funktion.
 */
declare(strict_types=1);

define('WQ_ADMIN', true);

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';
require __DIR__ . '/../api/lib/wortlisten.php';
require __DIR__ . '/../api/lib/archiv.php';
require __DIR__ . '/../api/lib/einreichung.php';
require __DIR__ . '/../api/lib/einstellungen.php';
require __DIR__ . '/../api/lib/tafel.php';
require __DIR__ . '/../api/lib/klassen.php';

$admin = wq_verlange_login();
$pdo = wq_db();

$tafelMeldung = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $aktion = (string) ($_POST['aktion'] ?? '');

    if ($aktion === 'tafel-schalten') {
        $an = !empty($_POST['an']);
        wq_einstellung_setzen('tafel_aktiv', $an ? '1' : '0');
        $tafelMeldung = $an
            ? 'Die Ehrentafel ist eingeschaltet und erscheint wieder in der App.'
            : 'Die Ehrentafel ist ausgeschaltet. Die App blendet sie aus, die Einträge bleiben erhalten.';

    } elseif ($aktion === 'klasse-schliessen') {
        wq_klasse_schliessen($pdo, (int) ($_POST['id'] ?? 0));
        $tafelMeldung = 'Die Klasse ist geschlossen. Der Code funktioniert nicht mehr.';

    } elseif ($aktion === 'klasse-loeschen') {
        wq_klasse_loeschen($pdo, (int) ($_POST['id'] ?? 0));
        $tafelMeldung = 'Die Klasse und alle ihre Zahlen sind gelöscht.';

    } elseif ($aktion === 'tafel-loeschen') {
        wq_tafel_loeschen($pdo, (int) ($_POST['id'] ?? 0));
        $tafelMeldung = 'Eintrag entfernt.';
    }
}

$tafelAn = wq_schalter('tafel_aktiv', true);
$tafelEintraege = wq_tafel_eintraege($pdo, 25);
$tafelGeuebt = wq_tafel_gemeinschaft($pdo);
$klassen = wq_klassen_liste($pdo);
$csrf = wq_csrf_token();

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$tage = (int) ($_GET['tage'] ?? 30);
if (!in_array($tage, [7, 30, 90, 3650], true)) {
    $tage = 30;
}
$ab = gmdate('Y-m-d', time() - $tage * 86400);

$stmtBereich = $pdo->prepare('
    SELECT schluessel, SUM(anzahl) AS summe
    FROM stats_taeglich
    WHERE bereich = :b AND tag >= :ab
    GROUP BY schluessel
    ORDER BY summe DESC
');

$stmtBereich->execute([':b' => 'liste', ':ab' => $ab]);
$listen = $stmtBereich->fetchAll();

$stmtBereich->execute([':b' => 'runde', ':ab' => $ab]);
$runden = $stmtBereich->fetchAll();

$stmtBereich->execute([':b' => 'treffer', ':ab' => $ab]);
$treffer = [];
foreach ($stmtBereich->fetchAll() as $z) {
    $treffer[$z['schluessel']] = (int) $z['summe'];
}
$stmtBereich->execute([':b' => 'fragen', ':ab' => $ab]);
$fragen = [];
foreach ($stmtBereich->fetchAll() as $z) {
    $fragen[$z['schluessel']] = (int) $z['summe'];
}

// Die wertvollste Abfrage: Wörter, die durchgängig danebengehen.
$schwer = $pdo->query('
    SELECT liste, wort, richtig, falsch, (richtig + falsch) AS gesamt
    FROM stats_wort
    WHERE (richtig + falsch) >= 5
    ORDER BY (CAST(falsch AS REAL) / (richtig + falsch)) DESC, gesamt DESC
    LIMIT 25
')->fetchAll();

require __DIR__ . '/kopf.php';
?>
<h1>Übersicht</h1>

<nav class="zeitraum" aria-label="Zeitraum wählen">
  <?php foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 3650 => 'Alles'] as $t => $beschriftung): ?>
    <a href="?tage=<?= $t ?>" class="<?= $t === $tage ? 'aktiv' : '' ?>"><?= wq_h($beschriftung) ?></a>
  <?php endforeach; ?>
</nav>

<p class="hinweis">
  Alle Zahlen sind Summen ohne Personenbezug. Gespeichert werden weder Adressen
  noch Kennungen noch Uhrzeiten, nur Tageszähler.
</p>

<section class="karte">
  <h2>Genutzte Wortlisten</h2>
  <?php if (!$listen): ?>
    <p class="leer">Noch keine Daten im gewählten Zeitraum.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Liste</th><th class="zahl">Aufrufe</th></tr></thead>
      <tbody>
      <?php foreach ($listen as $z): ?>
        <tr><td><?= wq_h($z['schluessel']) ?></td><td class="zahl"><?= (int) $z['summe'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="karte">
  <h2>Gespielte Runden und Trefferquote</h2>
  <?php if (!$runden): ?>
    <p class="leer">Noch keine Daten im gewählten Zeitraum.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Modus</th><th class="zahl">Runden</th><th class="zahl">Trefferquote</th></tr></thead>
      <tbody>
      <?php foreach ($runden as $z):
        $modus = (string) $z['schluessel'];
        $g = $fragen[$modus] ?? 0;
        $r = $treffer[$modus] ?? 0;
        $quote = $g > 0 ? round($r / $g * 100) : null;
      ?>
        <tr>
          <td><?= wq_h($modus) ?></td>
          <td class="zahl"><?= (int) $z['summe'] ?></td>
          <td class="zahl"><?= $quote === null ? '-' : $quote . ' %' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="karte">
  <h2>Schwierigste Vokabeln</h2>
  <p class="hinweis">
    Wörter mit mindestens fünf Antworten, sortiert nach Fehlerquote. Ein Wort
    ganz oben hat meist eine mehrdeutige Übersetzung, ein unpassendes Bild oder
    unglückliche Antwortmöglichkeiten. Das ist die Liste, mit der sich die
    Wortlisten verbessern lassen.
  </p>
  <?php if (!$schwer): ?>
    <p class="leer">Noch zu wenig Daten. Es braucht mindestens fünf Antworten je Wort.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Wort</th><th>Liste</th><th class="zahl">Richtig</th><th class="zahl">Falsch</th><th class="zahl">Fehlerquote</th></tr></thead>
      <tbody>
      <?php foreach ($schwer as $z):
        $gesamt = (int) $z['gesamt'];
        $quote = $gesamt > 0 ? round((int) $z['falsch'] / $gesamt * 100) : 0;
      ?>
        <tr>
          <td><strong><?= wq_h($z['wort']) ?></strong></td>
          <td><?= wq_h($z['liste']) ?></td>
          <td class="zahl"><?= (int) $z['richtig'] ?></td>
          <td class="zahl"><?= (int) $z['falsch'] ?></td>
          <td class="zahl <?= $quote >= 50 ? 'warnung' : '' ?>"><?= $quote ?> %</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="karte">
  <h2>Ehrentafel</h2>
  <?php if ($tafelMeldung !== ''): ?>
    <p class="meldung" role="status"><?= wq_h($tafelMeldung) ?></p>
  <?php endif; ?>
  <p class="hinweis">
    Auf die Tafel kommt, wer eine Liste komplett durchgespielt hat. Bewusst
    ohne Rangfolge und ohne Punkte, damit der Eintrag für jedes Kind
    erreichbar bleibt. Gespeichert werden nur der gewürfelte Name, das Tier und
    welche Listen es waren. Diese Woche wurden zusammen
    <strong><?= number_format($tafelGeuebt, 0, ',', '.') ?></strong> Vokabeln geübt.
  </p>

  <form method="post" class="reihe">
    <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
    <input type="hidden" name="aktion" value="tafel-schalten" />
    <?php if ($tafelAn): ?>
      <button type="submit" class="klein">Ehrentafel ausschalten</button>
      <span class="hinweis">Zurzeit eingeschaltet.</span>
    <?php else: ?>
      <input type="hidden" name="an" value="1" />
      <button type="submit" class="klein">Ehrentafel einschalten</button>
      <span class="hinweis">Zurzeit ausgeschaltet, die App blendet sie aus.</span>
    <?php endif; ?>
  </form>

  <?php if (!$tafelEintraege): ?>
    <p class="leer">Noch keine Einträge.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Wer</th><th>Was</th><th>Wann</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($tafelEintraege as $e): ?>
        <tr>
          <td><?= wq_h($e['avatar']) ?> <?= wq_h($e['name']) ?></td>
          <td><?= wq_h($e['titel']) ?></td>
          <td><?= wq_h(gmdate('d.m.Y, H:i', (int) $e['zeitpunkt'])) ?> <small>(UTC)</small></td>
          <td>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="aktion" value="tafel-loeschen" />
              <input type="hidden" name="id" value="<?= (int) $e['id'] ?>" />
              <button type="submit" class="klein">entfernen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<section class="karte">
  <h2>Klassen<?= $klassen ? ' (' . count($klassen) . ')' : '' ?></h2>
  <p class="hinweis">
    Lehrkräfte legen Klassen selbst an, unter <code>/klasse.php</code>, ohne
    Konto. Sichtbar ist hier nur, was zur Aufsicht nötig ist: Name, Code und
    Umfang. Die Übersicht einer Klasse steht ausschliesslich der Lehrkraft
    offen, über ihren geheimen Link. Auch dort gibt es keine einzelnen Kinder,
    sondern nur Summen.
  </p>
  <?php if (!$klassen): ?>
    <p class="leer">Noch keine Klassen.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Code</th><th class="zahl">Beitritte</th><th>Listen</th><th>Angelegt</th><th>Status</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($klassen as $k): ?>
        <?php
        $offen = (int) $k['aktiv'] === 1 && (int) $k['gueltig_bis'] > time();
        $anzahlListen = count(wq_klasse_listen($k));
        ?>
        <tr>
          <td><strong><?= wq_h((string) $k['name']) ?></strong></td>
          <td><code><?= wq_h((string) $k['code']) ?></code></td>
          <td class="zahl"><?= (int) $k['beitritte'] ?></td>
          <td class="zahl"><?= $anzahlListen ?></td>
          <td><?= wq_h(substr((string) $k['erstellt_am'], 0, 10)) ?></td>
          <td><?= $offen ? 'offen' : 'geschlossen' ?></td>
          <td class="aktionen">
            <?php if ($offen): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
                <input type="hidden" name="aktion" value="klasse-schliessen" />
                <input type="hidden" name="id" value="<?= (int) $k['id'] ?>" />
                <button type="submit" class="klein">schließen</button>
              </form>
            <?php endif; ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="aktion" value="klasse-loeschen" />
              <input type="hidden" name="id" value="<?= (int) $k['id'] ?>" />
              <button type="submit" class="klein">löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/fuss.php'; ?>
