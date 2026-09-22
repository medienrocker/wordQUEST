<?php
/**
 * wordQUEST – Endpunkt für den Klassenmodus.
 *
 * GET  ?code=ABC-234   prüft einen Code und liefert Name und Wortlisten.
 * POST {code}          zählt einen Beitritt.
 *
 * Es gibt hier bewusst keine Anmeldung und keine Kennung. Ein Code ist ein
 * gemeinsames Geheimnis der Klasse, mehr nicht. Wer ihn hat, übt mit.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/wortlisten.php';
require __DIR__ . '/lib/archiv.php';
require __DIR__ . '/lib/einreichung.php';
require __DIR__ . '/lib/klassen.php';

/* Beitritte je Anschluss und Tag. Grosszügig, weil eine ganze Schulklasse
   hinter einem einzigen Anschluss sitzen kann. Es geht nur darum, das
   Hochzählen aus Langeweile zu bremsen. */
const WQ_KLASSE_BEITRITTE_PRO_TAG = 60;

$pdo = wq_db();
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($methode === 'GET') {
    $code = wq_klasse_code_normal((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        wq_fehler('Ein Klassencode hat sechs Zeichen, zum Beispiel ABC-234.');
    }
    $klasse = wq_klasse_nach_code($pdo, $code);
    if (!wq_klasse_nutzbar($klasse)) {
        // Dieselbe Meldung für "gibt es nicht" und "abgelaufen": Sonst liesse
        // sich durch Ausprobieren herausfinden, welche Codes vergeben sind.
        wq_fehler('Diesen Klassencode gibt es nicht oder er gilt nicht mehr.', 404);
    }

    $listen = [];
    $archiviert = wq_archivierte();
    foreach (wq_klasse_listen($klasse) as $datei) {
        if (wq_wortliste_lesen($datei) !== null && !in_array($datei, $archiviert, true)) {
            $listen[] = $datei;
        }
    }
    if (!$listen) {
        wq_fehler('Zu dieser Klasse gibt es keine Wortlisten mehr.', 410);
    }

    wq_json([
        'ok'     => true,
        'code'   => (string) $klasse['code'],
        'name'   => (string) $klasse['name'],
        'listen' => $listen,
    ]);
}

wq_verlange_methode('POST');

$daten = wq_body_json();
$code = wq_klasse_code_normal((string) ($daten['code'] ?? ''));
if ($code === '') {
    wq_fehler('Kein Klassencode angegeben.');
}
$klasse = wq_klasse_nach_code($pdo, $code);
if (!wq_klasse_nutzbar($klasse)) {
    wq_fehler('Diesen Klassencode gibt es nicht oder er gilt nicht mehr.', 404);
}

wq_takt_schema($pdo);
$kennung = 'klasse-beitritt:' . wq_besucher_kennung();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM einreichung_takt WHERE kennung = :k AND zeitpunkt >= :ab');
$stmt->execute([':k' => $kennung, ':ab' => time() - 86400]);
if ((int) $stmt->fetchColumn() >= WQ_KLASSE_BEITRITTE_PRO_TAG) {
    // Still durchwinken: Für das Kind soll der Beitritt trotzdem klappen,
    // gezählt wird er nur nicht mehr.
    wq_json(['ok' => true, 'gezaehlt' => false]);
}
$pdo->prepare('INSERT INTO einreichung_takt (kennung, zeitpunkt) VALUES (:k, :z)')
    ->execute([':k' => $kennung, ':z' => time()]);

wq_klasse_beitritt($pdo, (int) $klasse['id']);
wq_json(['ok' => true, 'gezaehlt' => true]);
