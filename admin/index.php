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

  <p class="hinweis">
    Konten werden ausschließlich über die Kommandozeile angelegt und geändert.
    Auf diesem Server braucht es dafür den vollen Pfad zur Plesk-PHP, ein
    blankes <code>php</code> ist die System-PHP ohne SQLite-Treiber.
  </p>
  <p class="hinweis">
    Neues Konto:<br />
    <code>sudo -u bs_vps-user -H /opt/plesk/php/8.4/bin/php scripts/admin-anlegen.php anlegen &lt;name&gt; superadmin</code>
  </p>
  <p class="hinweis">
    Passwort ändern oder vergessen:<br />
    <code>sudo -u bs_vps-user -H /opt/plesk/php/8.4/bin/php scripts/admin-anlegen.php passwort &lt;name&gt;</code>
  </p>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
