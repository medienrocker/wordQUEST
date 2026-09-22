<?php
/**
 * wordQUEST – Anmeldung zum Admincenter.
 */
declare(strict_types=1);

define('WQ_ADMIN', true);

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';

wq_session_start();

if (($_GET['abmelden'] ?? '') === '1') {
    wq_logout();
    header('Location: index.php');
    exit;
}

if (wq_ist_angemeldet()) {
    header('Location: dashboard.php');
    exit;
}

$fehler = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $ergebnis = wq_login(trim((string) ($_POST['benutzer'] ?? '')), (string) ($_POST['passwort'] ?? ''));
    if (!empty($ergebnis['ok'])) {
        header('Location: dashboard.php');
        exit;
    }
    $fehler = (string) $ergebnis['fehler'];
}

$csrf = wq_csrf_token();
require __DIR__ . '/kopf.php';
?>
<div class="anmeldung">
  <h1>🔒 wordQUEST Admincenter</h1>

  <?php if ($fehler !== ''): ?>
    <p class="meldung fehler" role="alert"><?= htmlspecialchars($fehler, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>" />
    <label for="benutzer">Benutzername</label>
    <input type="text" id="benutzer" name="benutzer" required autofocus autocomplete="username" />

    <label for="passwort">Passwort</label>
    <input type="password" id="passwort" name="passwort" required autocomplete="current-password" />

    <button type="submit" class="btn">Anmelden</button>
  </form>

  <!-- Bewusst ohne technische Angaben: Serverpfade, Systembenutzer und
       PHP-Version gehören nicht auf eine öffentlich erreichbare Seite.
       Die Befehle zur Kontoverwaltung stehen in docs/DEPLOY.md. -->
  <p class="hinweis">Zugänge richtet der Betreiber ein.</p>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
