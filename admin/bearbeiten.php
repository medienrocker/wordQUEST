<?php
/**
 * wordQUEST – veröffentlichte Wortliste bearbeiten.
 *
 * Bis hierher konnte man Listen nur ansehen und freigeben. Fehler in einer
 * laufenden Liste liessen sich nur beheben, indem man sie neu einreichte.
 * Diese Seite schliesst die Lücke: Wörter berichtigen, Emoji setzen,
 * Kategorien vergeben, Zeilen entfernen, neue anhängen.
 *
 * Wie das ganze Admincenter kommt die Seite **ohne JavaScript** aus, denn die
 * Richtlinie verbietet Skripte dort vollständig. Deshalb wird in Seiten zu je
 * 40 Wörtern gearbeitet, statt 500 Eingabefelder auf einmal zu schicken: PHP
 * nimmt je Anfrage nur eine begrenzte Zahl Felder entgegen und würde den Rest
 * stillschweigend verwerfen. Genau das wäre der gefährlichste Fehler hier.
 */
declare(strict_types=1);

define('WQ_ADMIN', true);

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';
require __DIR__ . '/../api/lib/wortlisten.php';
require __DIR__ . '/../api/lib/archiv.php';
require __DIR__ . '/../api/lib/emoji.php';

$admin = wq_verlange_login();

const WQ_SEITE_GROESSE = 40;
const WQ_NEUE_ZEILEN   = 5;

/* Sprachbrücke: Die Tabelle hat schon sieben Spalten. Alle Sprachen zugleich
   wären unbedienbar, deshalb wird immer genau eine bearbeitet. */
const WQ_SPRACHNAMEN = [
    'tr' => 'Türkisch', 'ar' => 'Arabisch', 'uk' => 'Ukrainisch', 'ru' => 'Russisch',
    'pl' => 'Polnisch', 'ro' => 'Rumänisch', 'bg' => 'Bulgarisch', 'sq' => 'Albanisch',
    'sr' => 'Serbisch', 'hr' => 'Kroatisch', 'fa' => 'Persisch', 'ku' => 'Kurdisch',
    'ti' => 'Tigrinya', 'so' => 'Somali', 'es' => 'Spanisch', 'it' => 'Italienisch',
    'fr' => 'Französisch',
];
const WQ_RECHTSLAEUFIG = ['ar', 'fa'];

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Liest die Wortfelder einer abgeschickten Seite. */
function wq_zeilen_aus_post(): array
{
    $roh = $_POST['w'] ?? [];
    if (!is_array($roh)) {
        return [];
    }
    $felder = ['en', 'de', 'emoji', 'cat', 'example', 'exampleDe', 'bruecke'];
    $zeilen = [];
    foreach ($roh as $i => $z) {
        if (!is_array($z)) {
            continue;
        }
        $eintrag = ['weg' => !empty($z['weg'])];
        foreach ($felder as $f) {
            $wert = isset($z[$f]) && is_string($z[$f]) ? trim($z[$f]) : '';
            $eintrag[$f] = $wert;
        }
        $zeilen[(int) $i] = $eintrag;
    }
    return $zeilen;
}

/**
 * Baut aus einem Formulareintrag ein Wort für die Liste.
 *
 * `$alt` ist der bisherige Stand. Er wird für die Sprachbrücke gebraucht:
 * Bearbeitet wird immer nur eine Sprache, alle anderen müssen unverändert
 * erhalten bleiben. Ohne das löschte jedes Speichern die übrigen Sprachen.
 */
function wq_wort_aus_zeile(array $z, array $alt = [], string $sprache = ''): ?array
{
    if ($z['en'] === '' || $z['de'] === '') {
        return null;
    }
    $wort = ['en' => $z['en'], 'de' => $z['de']];
    foreach (['emoji', 'cat', 'example', 'exampleDe'] as $f) {
        if ($z[$f] !== '') {
            $wort[$f] = $z[$f];
        }
    }

    $bruecke = isset($alt['trans']) && is_array($alt['trans']) ? $alt['trans'] : [];
    if ($sprache !== '') {
        if (($z['bruecke'] ?? '') !== '') {
            $bruecke[$sprache] = $z['bruecke'];
        } else {
            unset($bruecke[$sprache]);
        }
    }
    if ($bruecke) {
        $wort['trans'] = $bruecke;
    }
    return $wort;
}

$listen = wq_vorhandene_listen();
$bekannt = array_column($listen, 'datei');

$datei = basename((string) ($_POST['liste'] ?? $_GET['liste'] ?? ''));
if ($datei !== '' && !in_array($datei, $bekannt, true)) {
    $datei = '';
}

$meldung = '';
$meldungArt = 'ok';
$hinweise = [];

if ($datei !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $aktion = (string) ($_POST['aktion'] ?? '');
    $liste = wq_wortliste_lesen($datei);

    if ($liste === null) {
        $meldung = 'Diese Wortliste liess sich nicht lesen.';
        $meldungArt = 'fehler';

    } elseif ($aktion === 'kopf') {
        $liste['title'] = trim((string) ($_POST['title'] ?? ''));
        $beschreibung = trim((string) ($_POST['description'] ?? ''));
        if ($beschreibung !== '') {
            $liste['description'] = $beschreibung;
        } else {
            unset($liste['description']);
        }
        $ergebnis = wq_wortliste_speichern($datei, $liste);
        if (!empty($ergebnis['ok'])) {
            $meldung = 'Titel und Beschreibung gespeichert.';
            $hinweise = $ergebnis['hinweise'] ?? [];
        } else {
            $meldung = implode(' ', $ergebnis['fehler'] ?? []);
            $meldungArt = 'fehler';
        }

    } elseif ($aktion === 'kategorien') {
        $kategorien = [];
        foreach ((array) ($_POST['kat'] ?? []) as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }
            $schluessel = trim((string) ($eintrag['key'] ?? ''));
            $beschriftung = trim((string) ($eintrag['label'] ?? ''));
            // Schlüssel landen in den Wörtern und in der Adresse der App,
            // deshalb hier auf Unverfängliches beschränken.
            $schluessel = strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $schluessel));
            if ($schluessel !== '' && $beschriftung !== '' && empty($eintrag['weg'])) {
                $kategorien[$schluessel] = $beschriftung;
            }
        }
        if ($kategorien) {
            $liste['categories'] = $kategorien;
        } else {
            unset($liste['categories']);
        }
        $ergebnis = wq_wortliste_speichern($datei, $liste);
        if (!empty($ergebnis['ok'])) {
            $meldung = count($kategorien) === 1
                ? 'Eine Kategorie gespeichert.'
                : count($kategorien) . ' Kategorien gespeichert.';
            $hinweise = $ergebnis['hinweise'] ?? [];
        } else {
            $meldung = implode(' ', $ergebnis['fehler'] ?? []);
            $meldungArt = 'fehler';
        }

    } elseif ($aktion === 'fassung') {
        $ergebnis = wq_version_zurueckholen($datei, (string) ($_POST['marke'] ?? ''));
        if (!empty($ergebnis['ok'])) {
            $meldung = 'Frühere Fassung zurückgeholt, jetzt ' . (int) $ergebnis['anzahl'] . ' Wörter. '
                     . 'Der vorherige Stand liegt weiterhin als Fassung bereit, das lässt sich also wieder umkehren.';
            $hinweise = $ergebnis['hinweise'] ?? [];
        } else {
            $meldung = implode(' ', $ergebnis['fehler'] ?? []);
            $meldungArt = 'fehler';
        }

    } elseif ($aktion === 'vorlagen') {
        $liste['categories'] = array_merge(WQ_KATEGORIE_VORLAGEN, (array) ($liste['categories'] ?? []));
        $ergebnis = wq_wortliste_speichern($datei, $liste);
        $meldung = !empty($ergebnis['ok'])
            ? 'Die Vorlagen stehen jetzt als Kategorien bereit. Nicht gebrauchte lassen sich hier entfernen.'
            : implode(' ', $ergebnis['fehler'] ?? []);
        $meldungArt = !empty($ergebnis['ok']) ? 'ok' : 'fehler';

    } elseif ($aktion === 'woerter' || $aktion === 'emoji') {
        $sprache = (string) ($_POST['sprache'] ?? '');
        if (!isset(WQ_SPRACHNAMEN[$sprache])) {
            $sprache = '';
        }
        $zeilen = wq_zeilen_aus_post();
        $erwartet = (int) ($_POST['anzahl'] ?? 0);

        /* Schutz gegen abgeschnittene Formulare. PHP verwirft Felder jenseits
           von max_input_vars ohne jede Meldung. Ohne diese Prüfung würde ein
           Speichern dann stillschweigend Wörter löschen. */
        if ($erwartet > 0 && count($zeilen) < $erwartet) {
            $meldung = 'Die Übertragung war unvollständig (' . count($zeilen) . ' von ' . $erwartet
                     . ' Zeilen). Es wurde nichts gespeichert. Bitte den Betreiber informieren.';
            $meldungArt = 'fehler';
        } else {
            $von = max(0, (int) ($_POST['von'] ?? 0));
            $woerter = array_values(array_filter((array) ($liste['words'] ?? []), 'is_array'));
            $vorher = count($woerter);

            $ersetzt = [];
            $angehaengt = [];
            foreach ($zeilen as $i => $z) {
                if ($aktion === 'emoji' && $z['emoji'] === '' && $z['en'] !== '') {
                    $z['emoji'] = (string) (wq_emoji_vorschlag($z['en']) ?? '');
                }
                if ($z['weg']) {
                    continue;
                }
                $bisher = ($i < WQ_SEITE_GROESSE && isset($woerter[$von + $i]) && is_array($woerter[$von + $i]))
                    ? $woerter[$von + $i] : [];
                $wort = wq_wort_aus_zeile($z, $bisher, $sprache);
                if ($wort === null) {
                    continue;
                }
                if ($i < WQ_SEITE_GROESSE) {
                    $ersetzt[$von + $i] = $wort;
                } else {
                    $angehaengt[] = $wort;
                }
            }

            // Nur den bearbeiteten Ausschnitt austauschen, der Rest bleibt.
            $neu = [];
            foreach ($woerter as $i => $wort) {
                if ($i >= $von && $i < $von + WQ_SEITE_GROESSE) {
                    if (isset($ersetzt[$i])) {
                        $neu[] = $ersetzt[$i];
                    }
                    // sonst: gelöscht oder leer, fällt weg
                } else {
                    $neu[] = $wort;
                }
            }
            foreach ($angehaengt as $wort) {
                $neu[] = $wort;
            }
            $liste['words'] = $neu;

            $ergebnis = wq_wortliste_speichern($datei, $liste);
            if (!empty($ergebnis['ok'])) {
                $entfernt = $vorher - count($neu) + count($angehaengt);
                $teile = [(int) $ergebnis['anzahl'] . ' Wörter gespeichert.'];
                if ($angehaengt) {
                    $teile[] = count($angehaengt) . ' neu dazugekommen.';
                }
                if ($entfernt > 0) {
                    $teile[] = $entfernt . ' entfernt.';
                }
                if ($aktion === 'emoji') {
                    $teile[] = 'Leere Emoji-Felder wurden, soweit bekannt, gefüllt.';
                }
                $meldung = implode(' ', $teile);
                $hinweise = $ergebnis['hinweise'] ?? [];
            } else {
                $meldung = implode(' ', $ergebnis['fehler'] ?? []);
                $meldungArt = 'fehler';
                $hinweise = $ergebnis['hinweise'] ?? [];
            }
        }
    }
}

/* ─── Anzeige ─── */
$liste = $datei !== '' ? wq_wortliste_lesen($datei) : null;
$woerter = $liste !== null ? array_values(array_filter((array) ($liste['words'] ?? []), 'is_array')) : [];
$kategorien = (array) ($liste['categories'] ?? []);
$gesamt = count($woerter);
$seiten = max(1, (int) ceil($gesamt / WQ_SEITE_GROESSE));
$seite = min($seiten, max(1, (int) ($_POST['seite'] ?? $_GET['seite'] ?? 1)));
$von = ($seite - 1) * WQ_SEITE_GROESSE;
$ausschnitt = array_slice($woerter, $von, WQ_SEITE_GROESSE);

$ohneVisualisierung = 0;
foreach ($woerter as $w) {
    if (empty($w['emoji']) && empty($w['img'])) {
        $ohneVisualisierung++;
    }
}

$sprache = (string) ($_POST['sprache'] ?? $_GET['sprache'] ?? '');
if (!isset(WQ_SPRACHNAMEN[$sprache])) {
    $sprache = '';
}
$rtl = in_array($sprache, WQ_RECHTSLAEUFIG, true);

$fassungen = $datei !== '' ? wq_versionen($datei) : [];
$istArchiviert = $datei !== '' && in_array($datei, wq_archivierte(), true);

$csrf = wq_csrf_token();
require __DIR__ . '/kopf.php';
?>
<h1>Liste bearbeiten</h1>

<?php if ($meldung !== ''): ?>
  <p class="meldung <?= $meldungArt === 'fehler' ? 'fehler' : '' ?>" role="status"><?= wq_h($meldung) ?></p>
<?php endif; ?>
<?php foreach ($hinweise as $h): ?>
  <p class="meldung hinweis-meldung"><?= wq_h($h) ?></p>
<?php endforeach; ?>

<section class="karte">
  <h2>Wortliste wählen</h2>
  <form method="get">
    <label for="liste">Liste</label>
    <select id="liste" name="liste">
      <option value="">– bitte wählen –</option>
      <?php foreach ($listen as $l): ?>
        <option value="<?= wq_h($l['datei']) ?>"<?= $l['datei'] === $datei ? ' selected' : '' ?>>
          <?= wq_h($l['titel']) ?> (<?= (int) $l['anzahl'] ?> Wörter)
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn schmal">Öffnen</button>
  </form>
  <p class="hinweis">
    Änderungen wirken sofort in der App. Vor jedem Speichern legt der Server
    eine Sicherungskopie der bisherigen Fassung an.
  </p>
</section>

<?php if ($liste === null): ?>
  <?php require __DIR__ . '/fuss.php'; exit; ?>
<?php endif; ?>

<?php if ($istArchiviert): ?>
  <p class="meldung hinweis-meldung">
    Diese Liste liegt im Archiv und erscheint zurzeit nicht in der App.
    Bearbeiten geht trotzdem. Zurückholen lässt sie sich unter
    <a href="listen.php">Wortlisten</a>.
  </p>
<?php endif; ?>

<section class="karte">
  <h2>Titel und Beschreibung</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
    <input type="hidden" name="liste" value="<?= wq_h($datei) ?>" />
    <input type="hidden" name="aktion" value="kopf" />
    <label for="title">Titel</label>
    <input type="text" id="title" name="title" maxlength="120" required
           value="<?= wq_h((string) ($liste['title'] ?? '')) ?>" />
    <label for="description">Beschreibung (freiwillig)</label>
    <input type="text" id="description" name="description" maxlength="300"
           value="<?= wq_h((string) ($liste['description'] ?? '')) ?>" />
    <button type="submit" class="btn schmal">Speichern</button>
  </form>
</section>

<section class="karte">
  <h2>Kategorien</h2>
  <p class="hinweis">
    Kategorien sortieren die Wörter in der App und lassen sich dort als Filter
    anklicken. Die <strong>Kennung</strong> steht in den Wörtern, die
    <strong>Beschriftung</strong> sehen die Kinder. Was hier angelegt ist,
    steht unten bei jedem Wort in der Auswahl.
  </p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
    <input type="hidden" name="liste" value="<?= wq_h($datei) ?>" />
    <input type="hidden" name="aktion" value="kategorien" />
    <table>
      <thead><tr><th>Kennung</th><th>Beschriftung</th><th class="zahl">Wörter</th><th>weg</th></tr></thead>
      <tbody>
      <?php
      $i = 0;
      foreach ($kategorien as $schluessel => $beschriftung):
          $anzahl = 0;
          foreach ($woerter as $w) {
              if ((string) ($w['cat'] ?? '') === (string) $schluessel) { $anzahl++; }
          }
      ?>
        <tr>
          <td><input type="text" name="kat[<?= $i ?>][key]" maxlength="40" value="<?= wq_h((string) $schluessel) ?>"
                     aria-label="Kennung der Kategorie <?= wq_h((string) $beschriftung) ?>" /></td>
          <td><input type="text" name="kat[<?= $i ?>][label]" maxlength="60" value="<?= wq_h((string) $beschriftung) ?>"
                     aria-label="Beschriftung der Kategorie <?= wq_h((string) $schluessel) ?>" /></td>
          <td class="zahl"><?= $anzahl ?></td>
          <td><input type="checkbox" name="kat[<?= $i ?>][weg]" value="1"
                     aria-label="Kategorie <?= wq_h((string) $beschriftung) ?> entfernen" /></td>
        </tr>
      <?php $i++; endforeach; ?>
      <?php for ($n = 0; $n < 3; $n++, $i++): ?>
        <tr>
          <td><input type="text" name="kat[<?= $i ?>][key]" maxlength="40" placeholder="z. B. food"
                     aria-label="Kennung einer neuen Kategorie" /></td>
          <td><input type="text" name="kat[<?= $i ?>][label]" maxlength="60" placeholder="z. B. Essen und Trinken"
                     aria-label="Beschriftung einer neuen Kategorie" /></td>
          <td class="zahl">–</td>
          <td></td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    <button type="submit" class="btn schmal">Kategorien speichern</button>
  </form>
  <?php if (!$kategorien): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
      <input type="hidden" name="liste" value="<?= wq_h($datei) ?>" />
      <input type="hidden" name="aktion" value="vorlagen" />
      <p class="hinweis">Noch keine Kategorien. Ein üblicher Satz lässt sich mit einem Klick anlegen:</p>
      <button type="submit" class="klein">Vorlagen übernehmen</button>
    </form>
  <?php endif; ?>
</section>

<section class="karte">
  <h2>Wörter <?= $gesamt ?><?= $seiten > 1 ? ' · Seite ' . $seite . ' von ' . $seiten : '' ?></h2>
  <p class="hinweis">
    <?= $ohneVisualisierung ?> von <?= $gesamt ?> Wörtern haben weder Emoji noch Bild.
    Ein Emoji reicht meistens und kostet keine Ladezeit.
    Bilder werden unter <a href="bilder.php?liste=<?= urlencode($datei) ?>">Bilder</a> hochgeladen.
  </p>
  <?php if ($seiten > 1): ?>
    <p class="blaettern">
      <?php for ($s = 1; $s <= $seiten; $s++): ?>
        <?php if ($s === $seite): ?>
          <strong><?= $s ?></strong>
        <?php else: ?>
          <a href="?liste=<?= urlencode($datei) ?>&amp;seite=<?= $s ?><?= $sprache !== '' ? '&amp;sprache=' . urlencode($sprache) : '' ?>"><?= $s ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </p>
    <p class="hinweis">
      Jede Seite wird für sich gespeichert. Bitte vor dem Blättern speichern.
    </p>
  <?php endif; ?>

  <form method="get" class="reihe sprachwahl">
    <input type="hidden" name="liste" value="<?= wq_h($datei) ?>" />
    <input type="hidden" name="seite" value="<?= $seite ?>" />
    <label for="sprache">Sprachbrücke bearbeiten</label>
    <select id="sprache" name="sprache">
      <option value="">– keine –</option>
      <?php foreach (WQ_SPRACHNAMEN as $kuerzel => $name): ?>
        <?php
        $wieViele = 0;
        foreach ($woerter as $w) {
            if (!empty($w['trans'][$kuerzel])) { $wieViele++; }
        }
        ?>
        <option value="<?= wq_h($kuerzel) ?>"<?= $sprache === $kuerzel ? ' selected' : '' ?>>
          <?= wq_h($name) ?><?= $wieViele ? ' (' . $wieViele . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="klein">anzeigen</button>
  </form>
  <p class="hinweis">
    Es wird immer nur eine Sprache bearbeitet, sonst hätte die Tabelle zwanzig
    Spalten. Die übrigen Sprachen bleiben beim Speichern unverändert. Die Zahl
    in Klammern sagt, wie viele Wörter diese Sprache schon haben.
  </p>

  <datalist id="emoji-auswahl">
    <?php foreach (wq_emoji_auswahl() as $e): ?>
      <option value="<?= wq_h($e) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
    <input type="hidden" name="liste" value="<?= wq_h($datei) ?>" />
    <input type="hidden" name="seite" value="<?= $seite ?>" />
    <input type="hidden" name="von" value="<?= $von ?>" />
    <input type="hidden" name="anzahl" value="<?= count($ausschnitt) + WQ_NEUE_ZEILEN ?>" />
    <input type="hidden" name="sprache" value="<?= wq_h($sprache) ?>" />

    <div class="tabellen-rahmen rahmen-gross">
    <table class="wortliste">
      <thead>
        <tr>
          <th>Englisch</th><th>Deutsch</th><th>Emoji</th><th>Kategorie</th>
          <?php if ($sprache !== ''): ?><th><?= wq_h(WQ_SPRACHNAMEN[$sprache]) ?></th><?php endif; ?>
          <th>Beispielsatz</th><th>Satz deutsch</th><th>weg</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ausschnitt as $i => $w): $nr = $von + $i + 1; ?>
        <tr>
          <td><input type="text" name="w[<?= $i ?>][en]" maxlength="120" value="<?= wq_h((string) ($w['en'] ?? '')) ?>"
                     aria-label="Englisch, Zeile <?= $nr ?>" /></td>
          <td><input type="text" name="w[<?= $i ?>][de]" maxlength="120" value="<?= wq_h((string) ($w['de'] ?? '')) ?>"
                     aria-label="Deutsch, Zeile <?= $nr ?>" /></td>
          <td class="spalte-emoji">
            <input type="text" name="w[<?= $i ?>][emoji]" maxlength="16" list="emoji-auswahl"
                   value="<?= wq_h((string) ($w['emoji'] ?? '')) ?>" aria-label="Emoji, Zeile <?= $nr ?>" />
            <?php if (!empty($w['img'])): ?><span class="bildmerker" title="Dieses Wort hat ein Bild">🖼️</span><?php endif; ?>
          </td>
          <td>
            <select name="w[<?= $i ?>][cat]" aria-label="Kategorie, Zeile <?= $nr ?>">
              <option value="">– keine –</option>
              <?php
              $eigene = (string) ($w['cat'] ?? '');
              $auswahl = $kategorien;
              if ($eigene !== '' && !isset($auswahl[$eigene])) {
                  $auswahl[$eigene] = $eigene . ' (nicht angelegt)';
              }
              foreach ($auswahl as $schluessel => $beschriftung): ?>
                <option value="<?= wq_h((string) $schluessel) ?>"<?= $eigene === (string) $schluessel ? ' selected' : '' ?>>
                  <?= wq_h((string) $beschriftung) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <?php if ($sprache !== ''): ?>
            <td><input type="text" name="w[<?= $i ?>][bruecke]" maxlength="120"
                       value="<?= wq_h((string) ($w['trans'][$sprache] ?? '')) ?>"
                       <?= $rtl ? 'dir="rtl" ' : '' ?>lang="<?= wq_h($sprache) ?>"
                       aria-label="<?= wq_h(WQ_SPRACHNAMEN[$sprache]) ?>, Zeile <?= $nr ?>" /></td>
          <?php endif; ?>
          <td><input type="text" name="w[<?= $i ?>][example]" maxlength="200" value="<?= wq_h((string) ($w['example'] ?? '')) ?>"
                     aria-label="Beispielsatz englisch, Zeile <?= $nr ?>" /></td>
          <td><input type="text" name="w[<?= $i ?>][exampleDe]" maxlength="200" value="<?= wq_h((string) ($w['exampleDe'] ?? '')) ?>"
                     aria-label="Beispielsatz deutsch, Zeile <?= $nr ?>" /></td>
          <td><input type="checkbox" name="w[<?= $i ?>][weg]" value="1"
                     aria-label="Zeile <?= $nr ?> entfernen" /></td>
        </tr>
      <?php endforeach; ?>

      <?php for ($n = 0; $n < WQ_NEUE_ZEILEN; $n++): $i = WQ_SEITE_GROESSE + $n; ?>
        <tr class="neue-zeile">
          <td><input type="text" name="w[<?= $i ?>][en]" maxlength="120" placeholder="neues Wort"
                     aria-label="Englisch, neue Zeile <?= $n + 1 ?>" /></td>
          <td><input type="text" name="w[<?= $i ?>][de]" maxlength="120" placeholder="Übersetzung"
                     aria-label="Deutsch, neue Zeile <?= $n + 1 ?>" /></td>
          <td class="spalte-emoji">
            <input type="text" name="w[<?= $i ?>][emoji]" maxlength="16" list="emoji-auswahl"
                   aria-label="Emoji, neue Zeile <?= $n + 1 ?>" /></td>
          <td>
            <select name="w[<?= $i ?>][cat]" aria-label="Kategorie, neue Zeile <?= $n + 1 ?>">
              <option value="">– keine –</option>
              <?php foreach ($kategorien as $schluessel => $beschriftung): ?>
                <option value="<?= wq_h((string) $schluessel) ?>"><?= wq_h((string) $beschriftung) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <?php if ($sprache !== ''): ?>
            <td><input type="text" name="w[<?= $i ?>][bruecke]" maxlength="120"
                       <?= $rtl ? 'dir="rtl" ' : '' ?>lang="<?= wq_h($sprache) ?>"
                       aria-label="<?= wq_h(WQ_SPRACHNAMEN[$sprache]) ?>, neue Zeile <?= $n + 1 ?>" /></td>
          <?php endif; ?>
          <td><input type="text" name="w[<?= $i ?>][example]" maxlength="200"
                     aria-label="Beispielsatz englisch, neue Zeile <?= $n + 1 ?>" /></td>
          <td><input type="text" name="w[<?= $i ?>][exampleDe]" maxlength="200"
                     aria-label="Beispielsatz deutsch, neue Zeile <?= $n + 1 ?>" /></td>
          <td></td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    </div>

    <p class="hinweis">
      Eine Zeile verschwindet, wenn das Kästchen in der Spalte „weg“ gesetzt
      ist oder wenn Englisch oder Deutsch leer bleibt.
    </p>
    <p class="aktionsreihe">
      <button type="submit" name="aktion" value="woerter" class="btn schmal">Wörter speichern</button>
      <button type="submit" name="aktion" value="emoji" class="klein">Leere Emoji füllen und speichern</button>
    </p>
    <p class="hinweis">
      Der zweite Knopf schlägt für bekannte Wörter ein Emoji vor, etwa 🍎 für
      „apple“. Unbekannte bleiben leer, denn ein falsches Emoji verwirrt mehr
      als gar keines.
    </p>
  </form>
</section>

<section class="karte">
  <h2>Frühere Fassungen<?= $fassungen ? ' (' . count($fassungen) . ')' : '' ?></h2>
  <p class="hinweis">
    Vor jeder Änderung wird der bisherige Stand hier abgelegt. Ein
    versehentlich entferntes Wort oder eine gelöschte Kategorie ist damit ein
    Klick weit weg. Auch das Zurückholen legt vorher eine Fassung an, es lässt
    sich also seinerseits umkehren. Aufbewahrt werden die letzten
    <?= WQ_VERSIONEN_MAX ?> Fassungen.
  </p>
  <?php if (!$fassungen): ?>
    <p class="leer">Noch keine früheren Fassungen. Die erste entsteht beim nächsten Speichern.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Stand vom</th><th>Titel</th><th class="zahl">Wörter</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($fassungen as $f): ?>
        <tr>
          <td><?= wq_h($f['zeit']) ?> <small>(UTC)</small></td>
          <td><?= wq_h($f['titel']) ?></td>
          <td class="zahl"><?= (int) $f['anzahl'] ?></td>
          <td>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="liste" value="<?= wq_h($datei) ?>" />
              <input type="hidden" name="aktion" value="fassung" />
              <input type="hidden" name="marke" value="<?= wq_h($f['marke']) ?>" />
              <button type="submit" class="klein">diesen Stand zurückholen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/fuss.php'; ?>
