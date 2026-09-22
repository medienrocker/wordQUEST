<?php
/**
 * Gemeinsamer Seitenkopf des Admincenters.
 * Setzt die Sicherheits-Header zusätzlich aus PHP, weil .htaccess-Header bei
 * manchen Plesk-Konfigurationen nicht für PHP-Antworten greifen.
 */
declare(strict_types=1);

// Teilvorlage: nur zum Einbinden gedacht, nie direkt aufrufbar.
if (!defined('WQ_ADMIN')) { http_response_code(403); exit('Nicht erlaubt.'); }

if (!headers_sent()) {
    /* Seiten dürfen eine eigene Richtlinie mitbringen, indem sie vor dem
       Einbinden $wqCsp setzen. Gebraucht wird das nur von der Fotoseite, die
       als einzige JavaScript und WebAssembly benötigt. Alle anderen Seiten
       bleiben bei script-src 'none'.
       img-src erlaubt den Bildhost, weil die Fußzeile das Logo von dort lädt. */
    $wqStandardCsp = "default-src 'none'; script-src 'none'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' https://img.bildungssprit.de; form-action 'self'; "
        . "base-uri 'none'; frame-ancestors 'none'";
    header('Content-Security-Policy: ' . (isset($wqCsp) ? $wqCsp : $wqStandardCsp));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}
$wqAdmin = function_exists('wq_aktueller_admin') ? wq_aktueller_admin() : null;

/* Zähler offener Einreichungen in der Navigation.
   Bewusst unabhängig von der Benachrichtigung per E-Mail: Fällt die aus,
   sieht man trotzdem sofort, dass etwas wartet. Ein Fehler beim Zählen darf
   das Admincenter nie unbenutzbar machen, deshalb still abgefangen. */
$wqOffen = 0;
if ($wqAdmin && function_exists('wq_db')) {
    try {
        $wqOffen = (int) wq_db()
            ->query('SELECT COUNT(*) FROM einreichungen WHERE status = "neu"')
            ->fetchColumn();
    } catch (Throwable $e) {
        $wqOffen = 0;
    }
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title>wordQUEST Admincenter</title>
<link rel="icon" href="../favicon.ico" />
<link rel="stylesheet" href="admin.css" />
</head>
<body>
<?php if ($wqAdmin): ?>
<header class="kopf">
  <span class="marke">
    <img src="../wordQUEST_icon.png" alt="" width="28" height="28" />
    wordQUEST Admincenter
  </span>
  <nav>
    <a href="dashboard.php">Übersicht</a>
    <a href="listen.php">Wortlisten<?php if ($wqOffen > 0): ?><span class="zaehler"><?= $wqOffen ?><span class="sr-only"> offene Einreichungen</span></span><?php endif; ?></a>
    <a href="foto.php">Foto</a>
    <a href="bilder.php">Bilder</a>
    <?php if ($wqAdmin['rolle'] === 'superadmin'): ?>
      <a href="admins.php">Admins</a>
    <?php endif; ?>
  </nav>
  <span class="wer">
    <?= htmlspecialchars($wqAdmin['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    <small>(<?= htmlspecialchars($wqAdmin['rolle'], ENT_QUOTES, 'UTF-8') ?>)</small>
    <a class="abmelden" href="index.php?abmelden=1">Abmelden</a>
  </span>
</header>
<?php endif; ?>
<main>
