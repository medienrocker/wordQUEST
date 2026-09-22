<?php
/**
 * wordQUEST – Endpunkt der Ehrentafel (WQ-8.3).
 *
 * GET  liefert die jüngsten Einträge und den Gemeinschaftszähler.
 * POST meldet, dass jemand eine Liste komplett durchgespielt hat.
 *
 * Ist die Tafel im Admincenter abgeschaltet, antwortet beides höflich mit
 * `aktiv: false`, und die App blendet den Bereich aus.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/wortlisten.php';
require __DIR__ . '/lib/archiv.php';
require __DIR__ . '/lib/einreichung.php';
require __DIR__ . '/lib/einstellungen.php';
require __DIR__ . '/lib/tafel.php';

$pdo = wq_db();

if (!wq_schalter('tafel_aktiv', true)) {
    wq_json(['ok' => true, 'aktiv' => false, 'eintraege' => [], 'geuebt' => 0]);
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($methode === 'GET') {
    $eintraege = [];
    foreach (wq_tafel_eintraege($pdo) as $e) {
        $eintraege[] = [
            'name'   => (string) $e['name'],
            'avatar' => (string) $e['avatar'],
            'titel'  => (string) $e['titel'],
            'vor'    => max(0, time() - (int) $e['zeitpunkt']),   // Sekunden, keine Uhrzeit
        ];
    }
    wq_json([
        'ok'        => true,
        'aktiv'     => true,
        'eintraege' => $eintraege,
        'geuebt'    => wq_tafel_gemeinschaft($pdo),
    ]);
}

wq_verlange_methode('POST');

$daten = wq_body_json();
$name   = isset($daten['name'])   && is_string($daten['name'])   ? trim($daten['name'])   : '';
$avatar = isset($daten['avatar']) && is_string($daten['avatar']) ? trim($daten['avatar']) : '';

// Ein Durchlauf kann mehrere Listen umfassen, deshalb wird beides angenommen.
$roh = $daten['listen'] ?? ($daten['liste'] ?? null);
$listen = [];
foreach (is_array($roh) ? $roh : [$roh] as $eintrag) {
    if (is_string($eintrag) && trim($eintrag) !== '' && mb_strlen($eintrag) <= 120) {
        $listen[] = trim($eintrag);
    }
}

if ($name === '' || $avatar === '' || !$listen) {
    wq_fehler('Unvollständige Meldung.');
}
// Grobe Längengrenze vor jeder weiteren Arbeit.
if (mb_strlen($name) > 60 || mb_strlen($avatar) > 8 || count($listen) > WQ_TAFEL_MAX_LISTEN) {
    wq_fehler('Meldung zu lang.');
}

$ergebnis = wq_tafel_eintragen($pdo, $name, $avatar, $listen);
if (empty($ergebnis['ok'])) {
    wq_fehler((string) $ergebnis['fehler']);
}

wq_json(['ok' => true, 'aktiv' => true, 'doppelt' => !empty($ergebnis['doppelt'])]);
