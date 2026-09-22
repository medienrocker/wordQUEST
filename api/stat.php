<?php
/**
 * wordQUEST – anonyme Nutzungsstatistik (WQ-7.2).
 *
 * Nimmt am Rundenende ein kleines Bündel Ereignisse entgegen und schreibt sie
 * als Tageszähler fort.
 *
 * Was hier ausdrücklich NICHT gespeichert wird:
 *   - keine IP-Adresse, auch nicht gehasht
 *   - keine Sitzungs- oder Gerätekennung
 *   - keine Uhrzeit feiner als der Kalendertag
 *   - kein Spielername, kein Punktestand
 * Damit entsteht kein Personenbezug, eine Einwilligung ist nicht nötig.
 * Trotzdem gibt es in der App einen Schalter zum Abschalten.
 *
 * Schutz gegen Missbrauch ohne Adressspeicherung: Der Schlüsselraum ist
 * begrenzt. Listennamen müssen einer tatsächlich vorhandenen Datei
 * entsprechen, Bereiche und Modi kommen aus festen Listen. Damit kann
 * niemand die Datenbank durch erfundene Schlüssel aufblähen.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/wortlisten.php';
require __DIR__ . '/lib/archiv.php';
require __DIR__ . '/lib/einreichung.php';
require __DIR__ . '/lib/klassen.php';

wq_verlange_methode('POST');

$config = wq_config();
$daten = wq_body_json();

$ereignisse = $daten['events'] ?? null;
if (!is_array($ereignisse) || !$ereignisse) {
    wq_fehler('Keine Ereignisse.');
}
if (count($ereignisse) > (int) $config['max_events']) {
    wq_fehler('Zu viele Ereignisse.', 413);
}

/** Vorhandene Wortlisten einmal einlesen, als Positivliste. */
function wq_bekannte_listen(): array
{
    static $listen = null;
    if ($listen !== null) {
        return $listen;
    }
    $config = wq_config();
    $listen = [];
    $treffer = glob(rtrim((string) $config['wordlists_dir'], '/') . '/*.json') ?: [];
    foreach ($treffer as $pfad) {
        $name = basename($pfad);
        if ($name !== 'index.json') {
            $listen[$name] = true;
        }
    }
    return $listen;
}

const WQ_MODI = ['quiz', 'memory', 'scramble', 'spelling', 'listen', 'dictation', 'cloze', 'practice'];

$tag = gmdate('Y-m-d');
$pdo = wq_db();

/* Klassenmodus: Übt jemand mit einem Klassencode, werden dieselben Zahlen ein
   zweites Mal geschrieben, diesmal der Klasse zugeordnet. Zwei getrennte
   Summenspeicher statt eines gemeinsamen mit Schlüssel: Löscht eine Lehrkraft
   ihre Klasse, verschwinden deren Zahlen restlos, ohne die Gesamtstatistik
   anzurühren. */
$klasseId = 0;
if (isset($daten['klasse']) && is_string($daten['klasse'])) {
    $code = wq_klasse_code_normal($daten['klasse']);
    if ($code !== '') {
        $klasse = wq_klasse_nach_code($pdo, $code);
        if (wq_klasse_nutzbar($klasse)) {
            $klasseId = (int) $klasse['id'];
        }
    }
}
$maxLen = (int) $config['max_key_len'];
$uebernommen = 0;

$pdo->beginTransaction();
try {
    foreach ($ereignisse as $e) {
        if (!is_array($e)) {
            continue;
        }
        $typ = isset($e['typ']) && is_string($e['typ']) ? $e['typ'] : '';

        if ($typ === 'liste') {
            $wert = isset($e['wert']) && is_string($e['wert']) ? basename($e['wert']) : '';
            if ($wert === '' || !isset(wq_bekannte_listen()[$wert])) {
                continue;
            }
            wq_zaehler_erhoehen($pdo, $tag, 'liste', $wert);
            if ($klasseId) { wq_klasse_zaehler($pdo, $klasseId, $tag, 'liste', $wert); }
            $uebernommen++;

        } elseif ($typ === 'modus') {
            $wert = isset($e['wert']) && is_string($e['wert']) ? $e['wert'] : '';
            if (!in_array($wert, WQ_MODI, true)) {
                continue;
            }
            wq_zaehler_erhoehen($pdo, $tag, 'modus', $wert);
            if ($klasseId) { wq_klasse_zaehler($pdo, $klasseId, $tag, 'modus', $wert); }
            $uebernommen++;

        } elseif ($typ === 'runde') {
            $wert = isset($e['wert']) && is_string($e['wert']) ? $e['wert'] : '';
            if (!in_array($wert, WQ_MODI, true)) {
                continue;
            }
            wq_zaehler_erhoehen($pdo, $tag, 'runde', $wert);
            if ($klasseId) { wq_klasse_zaehler($pdo, $klasseId, $tag, 'runde', $wert); }
            $richtig = isset($e['richtig']) ? (int) $e['richtig'] : 0;
            $gesamt  = isset($e['gesamt'])  ? (int) $e['gesamt']  : 0;
            // Trefferquote als zwei Summen, daraus lässt sich der Schnitt
            // bilden, ohne einzelne Runden zu speichern.
            if ($gesamt > 0 && $gesamt <= 100 && $richtig >= 0 && $richtig <= $gesamt) {
                wq_zaehler_erhoehen($pdo, $tag, 'treffer', $wert, $richtig);
                wq_zaehler_erhoehen($pdo, $tag, 'fragen',  $wert, $gesamt);
                if ($klasseId) {
                    wq_klasse_zaehler($pdo, $klasseId, $tag, 'treffer', $wert, $richtig);
                    wq_klasse_zaehler($pdo, $klasseId, $tag, 'fragen',  $wert, $gesamt);
                }
            }
            $uebernommen++;

        } elseif ($typ === 'durchlauf') {
            // Zählt, wie oft in dieser Klasse eine Liste ganz durchgespielt
            // wurde. Für die Gesamtstatistik uninteressant, für eine
            // Lehrkraft die eigentliche Frage.
            if ($klasseId) {
                wq_klasse_zaehler($pdo, $klasseId, $tag, 'durchlauf', 'gesamt');
                $uebernommen++;
            }

        } elseif ($typ === 'wort') {
            $liste = isset($e['liste']) && is_string($e['liste']) ? basename($e['liste']) : '';
            $wort  = isset($e['wort'])  && is_string($e['wort'])  ? trim($e['wort']) : '';
            if ($liste === '' || !isset(wq_bekannte_listen()[$liste])) {
                continue;
            }
            if ($wort === '' || mb_strlen($wort) > $maxLen) {
                continue;
            }
            wq_wort_erhoehen($pdo, $liste, $wort, !empty($e['richtig']));
            if ($klasseId) { wq_klasse_wort($pdo, $klasseId, $liste, $wort, !empty($e['richtig'])); }
            $uebernommen++;
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

wq_json(['ok' => true, 'uebernommen' => $uebernommen]);
