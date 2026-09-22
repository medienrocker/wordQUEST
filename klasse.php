<?php
/**
 * wordQUEST – Klassenmodus für Lehrkräfte (Epic 10, Punkt 3).
 *
 * Ohne Anmeldung und ohne Konto. Eine Lehrkraft legt eine Klasse an, bekommt
 * einen vorlesbaren Code für die Kinder und einen geheimen Link für sich
 * selbst. Der Link ist der Schlüssel: Wer ihn hat, sieht die Übersicht.
 *
 * Die Übersicht zeigt ausschliesslich Summen. Es gibt keine Namensliste, keine
 * Anwesenheit und keine Zeile je Gerät, und unterhalb von drei Beitritten
 * zeigt sie gar keine Zahlen. Die Begründung steht in `api/lib/klassen.php`.
 */
declare(strict_types=1);

require __DIR__ . '/api/lib/bootstrap.php';
require __DIR__ . '/api/lib/db.php';
require __DIR__ . '/api/lib/wortlisten.php';
require __DIR__ . '/api/lib/archiv.php';
require __DIR__ . '/api/lib/einreichung.php';
require __DIR__ . '/api/lib/klassen.php';

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = wq_db();
wq_klassen_schema($pdo);

$meldung = '';
$meldungArt = 'fehler';
$neueKlasse = null;
$loeschenBestaetigen = false;

$token = (string) ($_POST['token'] ?? $_GET['t'] ?? '');
$klasse = $token !== '' ? wq_klasse_nach_token($pdo, $token) : null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $aktion = (string) ($_POST['aktion'] ?? '');
    $honig = trim((string) ($_POST['webseite'] ?? ''));

    /* Die Mindestzeit gilt nur beim Anlegen. Das ist die öffentliche
       Handlung, bei der Massensendungen drohen. Wer den geheimen Link schon
       besitzt, hat sich bereits ausgewiesen, und eine Wartezeit vor dem
       Bestätigen wäre dort nur eine Falle: Die Rückfrage erzeugt eine frische
       Marke, und der nächste Klick käme immer zu früh. Genau das ist im Test
       passiert. */
    $marke = $aktion === 'anlegen'
        ? wq_formular_marke_pruefen($_POST['marke'] ?? null)
        : ['ok' => true];

    if ($honig !== '') {
        $meldung = 'Das hat nicht geklappt.';
    } elseif (!$marke['ok']) {
        $meldung = (string) $marke['fehler'];

    } elseif ($aktion === 'anlegen') {
        $takt = wq_takt_pruefen($pdo);
        if (!$takt['ok']) {
            $meldung = (string) $takt['fehler'];
        } else {
            $listen = array_map('strval', (array) ($_POST['listen'] ?? []));
            $ergebnis = wq_klasse_anlegen($pdo, (string) ($_POST['name'] ?? ''), $listen);
            if (empty($ergebnis['ok'])) {
                $meldung = (string) $ergebnis['fehler'];
            } else {
                wq_takt_merken($pdo);
                $neueKlasse = $ergebnis['klasse'];
                $klasse = $neueKlasse;
                $meldungArt = 'ok';
                $meldung = 'Die Klasse ist angelegt.';
            }
        }

    } elseif ($klasse !== null && $aktion === 'schliessen') {
        wq_klasse_schliessen($pdo, (int) $klasse['id']);
        $klasse = wq_klasse_nach_token($pdo, $token);
        $meldungArt = 'ok';
        $meldung = 'Die Klasse ist geschlossen. Der Code funktioniert nicht mehr, die Zahlen bleiben.';

    } elseif ($klasse !== null && $aktion === 'loeschen') {
        $loeschenBestaetigen = true;

    } elseif ($klasse !== null && $aktion === 'loeschen-bestaetigt') {
        wq_klasse_loeschen($pdo, (int) $klasse['id']);
        $klasse = null;
        $token = '';
        $meldungArt = 'ok';
        $meldung = 'Die Klasse und alle ihre Zahlen sind gelöscht.';
    }
}

$listen = array_values(array_filter(wq_vorhandene_listen(), static fn($l) => empty($l['archiviert'])));
$uebersicht = $klasse !== null ? wq_klasse_uebersicht($pdo, $klasse) : null;
$marke = wq_formular_marke();

$basis = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
       . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'none'; script-src 'none'; style-src 'self'; "
        . "img-src 'self' https://img.bildungssprit.de; form-action 'self'; "
        . "base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    // Der Lehrkraft-Link ist ein Schlüssel. Er hat in keinem Suchindex etwas
    // verloren.
    header('X-Robots-Tag: noindex, nofollow');
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title>Klasse üben lassen – wordQUEST</title>
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
<?php if ($klasse === null): ?>

  <h1>Mit der Klasse üben</h1>
  <p class="vorspann">
    Du bekommst einen Code, den du an die Tafel schreibst. Alle Kinder tippen
    ihn in der App ein und üben denselben Satz Vokabeln. Du siehst, wie weit
    die Klasse als Ganzes ist.
  </p>

  <?php if ($meldung !== ''): ?>
    <p class="meldung <?= $meldungArt === 'ok' ? 'gut' : 'fehler' ?>" role="status"><?= wq_h($meldung) ?></p>
  <?php endif; ?>

  <section class="karte">
    <h2>Was du siehst, und was nicht</h2>
    <ul>
      <li><strong>Nie einzelne Kinder.</strong> Es gibt keine Namensliste, keine
          Anwesenheit und keine Zeile je Gerät. Nur Summen der ganzen Klasse.</li>
      <li>Solange weniger als <?= WQ_KLASSE_MINDEST ?> Kinder beigetreten sind,
          zeigt die Übersicht gar keine Zahlen. Eine Klassensumme aus einem
          Gerät wäre der Lernstand eines Kindes.</li>
      <li>Das Nützlichste ist die Liste der Wörter, die der Klasse durchgängig
          danebengehen. Genau die gehören in die nächste Stunde.</li>
      <li>Die Kinder brauchen weiterhin kein Konto und geben keinen Namen ein.</li>
    </ul>
  </section>

  <form method="post" class="einreichung">
    <input type="hidden" name="marke" value="<?= wq_h($marke) ?>" />
    <input type="hidden" name="aktion" value="anlegen" />
    <div class="honigtopf" aria-hidden="true">
      <label for="webseite">Webseite (bitte frei lassen)</label>
      <input type="text" id="webseite" name="webseite" tabindex="-1" autocomplete="off" />
    </div>

    <section class="karte">
      <h2>1. Wie soll die Klasse heißen?</h2>
      <label for="name">Name</label>
      <input type="text" id="name" name="name" maxlength="60" required
             value="<?= wq_h((string) ($_POST['name'] ?? '')) ?>"
             placeholder="zum Beispiel: Englisch 5b" />
      <p class="hinweis">
        Nur für dich zur Wiedererkennung. Bitte keine Namen von Kindern
        hineinschreiben.
      </p>
    </section>

    <section class="karte">
      <h2>2. Welche Wortlisten?</h2>
      <?php if (!$listen): ?>
        <p class="hinweis">Zurzeit sind keine Wortlisten veröffentlicht.</p>
      <?php else: ?>
        <p class="hinweis">Mehrere sind möglich, höchstens <?= WQ_KLASSE_MAX_LISTEN ?>.</p>
        <?php foreach ($listen as $l): ?>
          <label class="kasten">
            <input type="checkbox" name="listen[]" value="<?= wq_h($l['datei']) ?>" />
            <span><strong><?= wq_h($l['titel']) ?></strong> · <?= (int) $l['anzahl'] ?> Wörter</span>
          </label>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <p class="absenden">
      <button type="submit" class="knopf gross">Klasse anlegen</button>
    </p>
    <p class="hinweis">
      Danach bekommst du zwei Dinge: den Code für die Kinder und einen Link für
      dich. <strong>Den Link bitte aufbewahren</strong>, er ist der einzige Weg
      zurück zu dieser Übersicht.
    </p>
  </form>

<?php else: ?>

  <h1><?= wq_h((string) $klasse['name']) ?></h1>

  <?php if ($meldung !== ''): ?>
    <p class="meldung <?= $meldungArt === 'ok' ? 'gut' : 'fehler' ?>" role="status"><?= wq_h($meldung) ?></p>
  <?php endif; ?>

  <?php if ($neueKlasse !== null): ?>
    <section class="karte gut-karte">
      <h2>Bitte diesen Link aufbewahren</h2>
      <p class="hinweis">
        Er ist der einzige Weg zurück zu dieser Übersicht. Es gibt kein Konto
        und keine Passwortzurücksetzung. Am besten als Lesezeichen speichern
        oder dir selbst per Mail schicken.
      </p>
      <p class="schluessel"><?= wq_h($basis . '/klasse.php?t=' . $klasse['token']) ?></p>
    </section>
  <?php endif; ?>

  <?php if ($loeschenBestaetigen): ?>
    <section class="karte warn-karte">
      <h2>Klasse wirklich löschen?</h2>
      <p>
        Die Klasse und alle ihre Zahlen verschwinden sofort und vollständig.
        Der Code funktioniert nicht mehr, der Link führt ins Leere. Das lässt
        sich nicht rückgängig machen.
      </p>
      <p class="hinweis">
        Zum reinen Beenden genügt "Klasse schließen". Dann bleibt die Übersicht
        erhalten, nur neue Beitritte sind nicht mehr möglich.
      </p>
      <form method="post" class="reihe">
        <input type="hidden" name="marke" value="<?= wq_h($marke) ?>" />
        <input type="hidden" name="token" value="<?= wq_h((string) $klasse['token']) ?>" />
        <input type="hidden" name="aktion" value="loeschen-bestaetigt" />
        <button type="submit" class="knopf schmal gefaehrlich">Ja, endgültig löschen</button>
        <a class="knopf schmal grau" href="klasse.php?t=<?= wq_h((string) $klasse['token']) ?>">Abbrechen</a>
      </form>
    </section>
  <?php endif; ?>

  <section class="karte">
    <h2>Der Code für die Klasse</h2>
    <p class="klassencode"><?= wq_h((string) $klasse['code']) ?></p>
    <?php if ((int) $klasse['aktiv'] !== 1): ?>
      <p class="meldung fehler">Diese Klasse ist geschlossen. Der Code funktioniert nicht mehr.</p>
    <?php elseif ((int) $klasse['gueltig_bis'] <= time()): ?>
      <p class="meldung fehler">Diese Klasse ist abgelaufen.</p>
    <?php else: ?>
      <p class="hinweis">
        So gehen die Kinder vor: App öffnen, unter <strong>Üben</strong> den
        Code eingeben, fertig. Die Wortlisten stellen sich von selbst ein.
        Gültig bis <?= wq_h(gmdate('d.m.Y', (int) $klasse['gueltig_bis'])) ?>.
      </p>
    <?php endif; ?>
    <p class="hinweis">
      Wortlisten dieser Klasse:
      <?php
      $titel = [];
      foreach (wq_klasse_listen($klasse) as $datei) {
          $daten = wq_wortliste_lesen($datei);
          $titel[] = $daten['title'] ?? $datei;
      }
      echo wq_h(implode(' · ', $titel));
      ?>
    </p>
  </section>

  <section class="karte">
    <h2>Wie weit ist die Klasse?</h2>
    <?php if (empty($uebersicht['genug'])): ?>
      <p class="hinweis">
        Bisher <?= (int) $uebersicht['beitritte'] ?>
        <?= (int) $uebersicht['beitritte'] === 1 ? 'Beitritt' : 'Beitritte' ?>.
        Zahlen erscheinen ab <?= WQ_KLASSE_MINDEST ?> Beitritten. Vorher wäre
        die Klassensumme der Lernstand eines einzelnen Kindes, und den zeigt
        diese Seite bewusst nicht.
      </p>
    <?php else: ?>
      <div class="kennzahlen">
        <div class="kennzahl">
          <span class="wert"><?= number_format((int) $uebersicht['geuebt'], 0, ',', '.') ?></span>
          <span class="was">Vokabeln geübt</span>
        </div>
        <div class="kennzahl">
          <span class="wert"><?= $uebersicht['quote'] === null ? '–' : (int) $uebersicht['quote'] . ' %' ?></span>
          <span class="was">richtig beantwortet</span>
        </div>
        <div class="kennzahl">
          <span class="wert"><?= (int) $uebersicht['durchlaeufe'] ?></span>
          <span class="was">Listen komplett geschafft</span>
        </div>
        <div class="kennzahl">
          <span class="wert"><?= (int) $uebersicht['beitritte'] ?></span>
          <span class="was">Beitritte mit dem Code</span>
        </div>
      </div>
      <p class="hinweis">
        „Beitritte" heisst, wie oft der Code eingegeben wurde, nicht wie viele
        Geräte dahinterstehen. Für Letzteres bräuchte es eine Gerätekennung,
        und die speichert wordQUEST nicht.
      </p>
    <?php endif; ?>
  </section>

  <?php if (!empty($uebersicht['schwer'])): ?>
  <section class="karte">
    <h2>Diese Wörter machen Probleme</h2>
    <p class="hinweis">
      Sortiert nach Fehlerquote, erst ab drei Versuchen in der Klasse. Das ist
      die Liste für die nächste Stunde.
    </p>
    <table>
      <thead><tr><th>Wort</th><th class="zahl">richtig</th><th class="zahl">falsch</th><th class="zahl">daneben</th></tr></thead>
      <tbody>
      <?php foreach ($uebersicht['schwer'] as $z): ?>
        <?php $quote = (int) round($z['falsch'] / max(1, (int) $z['gesamt']) * 100); ?>
        <tr>
          <td><strong><?= wq_h((string) $z['wort']) ?></strong></td>
          <td class="zahl"><?= (int) $z['richtig'] ?></td>
          <td class="zahl"><?= (int) $z['falsch'] ?></td>
          <td class="zahl"><?= $quote ?> %</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="karte">
    <h2>Klasse beenden</h2>
    <p class="hinweis">
      <strong>Schließen</strong> macht den Code ungültig, die Übersicht bleibt.
      <strong>Löschen</strong> entfernt auch alle Zahlen, endgültig.
    </p>
    <form method="post" class="reihe">
      <input type="hidden" name="marke" value="<?= wq_h($marke) ?>" />
      <input type="hidden" name="token" value="<?= wq_h((string) $klasse['token']) ?>" />
      <?php if ((int) $klasse['aktiv'] === 1): ?>
        <button type="submit" name="aktion" value="schliessen" class="knopf schmal">Klasse schließen</button>
      <?php endif; ?>
      <button type="submit" name="aktion" value="loeschen" class="knopf schmal gefaehrlich">Klasse löschen …</button>
    </form>
  </section>

<?php endif; ?>
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
</body>
</html>
