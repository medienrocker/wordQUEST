<?php
/**
 * wordQUEST – Adminverwaltung, nur für den Superadmin.
 *
 * Bewusste Einschränkung: Konten werden hier NICHT angelegt und Passwörter
 * NICHT gesetzt. Beides läuft über die Kommandozeile. Damit wandert nie ein
 * Passwort durch ein Formular, und ein übernommenes Admin-Konto kann sich
 * keine weiteren Konten verschaffen.
 *
 * Hier möglich: Rolle ändern, Konto sperren und entsperren.
 */
declare(strict_types=1);

define('WQ_ADMIN', true);

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';

// Rollenprüfung serverseitig, nicht nur durch Ausblenden des Menüpunkts.
$admin = wq_verlange_rolle('superadmin');
$pdo = wq_db();
wq_auth_schema($pdo);

function wq_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$meldung = '';
$meldungArt = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wq_verlange_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $aktion = (string) ($_POST['aktion'] ?? '');
    $ziel = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $ziel = $stmt->fetch() ?: null;
    }

    if (!$ziel) {
        $meldung = 'Unbekanntes Konto.';
        $meldungArt = 'fehler';

    } elseif ($aktion === 'rolle') {
        $neu = (string) ($_POST['rolle'] ?? '');
        if (!in_array($neu, WQ_ROLLEN, true)) {
            $meldung = 'Unbekannte Rolle.';
            $meldungArt = 'fehler';
        } elseif ((int) $ziel['id'] === $admin['id']) {
            // Selbstschutz: Wer sich selbst herabstuft, sperrt sich aus.
            $meldung = 'Die eigene Rolle lässt sich nicht ändern.';
            $meldungArt = 'fehler';
        } elseif ($ziel['rolle'] === 'superadmin' && $neu !== 'superadmin' && wq_anzahl_superadmins($pdo) <= 1) {
            $meldung = 'Das ist der letzte aktive Superadmin.';
            $meldungArt = 'fehler';
        } else {
            $pdo->prepare('UPDATE admins SET rolle = :r WHERE id = :id')
                ->execute([':r' => $neu, ':id' => $id]);
            $meldung = 'Rolle geändert.';
        }

    } elseif ($aktion === 'sperren' || $aktion === 'entsperren') {
        $aktiv = $aktion === 'entsperren' ? 1 : 0;
        if ($aktiv === 0 && (int) $ziel['id'] === $admin['id']) {
            $meldung = 'Das eigene Konto lässt sich nicht sperren.';
            $meldungArt = 'fehler';
        } elseif ($aktiv === 0 && $ziel['rolle'] === 'superadmin' && wq_anzahl_superadmins($pdo) <= 1) {
            $meldung = 'Das ist der letzte aktive Superadmin.';
            $meldungArt = 'fehler';
        } else {
            $pdo->prepare('UPDATE admins SET aktiv = :a, fehlversuche = 0, gesperrt_bis = 0 WHERE id = :id')
                ->execute([':a' => $aktiv, ':id' => $id]);
            $meldung = $aktiv === 1 ? 'Konto entsperrt.' : 'Konto gesperrt.';
        }
    }
}

$konten = $pdo->query('SELECT id, benutzername, rolle, aktiv, fehlversuche, gesperrt_bis, zuletzt_am FROM admins ORDER BY benutzername')->fetchAll();
$csrf = wq_csrf_token();
require __DIR__ . '/kopf.php';
?>
<h1>Admins</h1>

<?php if ($meldung !== ''): ?>
  <p class="meldung <?= $meldungArt === 'fehler' ? 'fehler' : '' ?>" role="status"><?= wq_h($meldung) ?></p>
<?php endif; ?>

<section class="karte">
  <table>
    <thead>
      <tr><th>Benutzer</th><th>Rolle</th><th>Status</th><th>Zuletzt</th><th>Aktion</th></tr>
    </thead>
    <tbody>
    <?php foreach ($konten as $k):
      $gesperrt = (int) $k['gesperrt_bis'] > time();
      $selbst = (int) $k['id'] === $admin['id'];
    ?>
      <tr>
        <td><strong><?= wq_h($k['benutzername']) ?></strong><?= $selbst ? ' <small>(du)</small>' : '' ?></td>
        <td><?= wq_h($k['rolle']) ?></td>
        <td>
          <?php if ((int) $k['aktiv'] !== 1): ?>
            <span class="warnung">gesperrt</span>
          <?php elseif ($gesperrt): ?>
            <span class="warnung">vorübergehend gesperrt</span>
          <?php else: ?>
            aktiv
          <?php endif; ?>
        </td>
        <td><?= wq_h($k['zuletzt_am'] ?? '-') ?></td>
        <td class="aktionen">
          <?php if (!$selbst): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="id" value="<?= (int) $k['id'] ?>" />
              <input type="hidden" name="aktion" value="rolle" />
              <input type="hidden" name="rolle" value="<?= $k['rolle'] === 'superadmin' ? 'admin' : 'superadmin' ?>" />
              <button type="submit" class="klein">
                <?= $k['rolle'] === 'superadmin' ? 'zu Admin' : 'zu Superadmin' ?>
              </button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= wq_h($csrf) ?>" />
              <input type="hidden" name="id" value="<?= (int) $k['id'] ?>" />
              <input type="hidden" name="aktion" value="<?= (int) $k['aktiv'] === 1 ? 'sperren' : 'entsperren' ?>" />
              <button type="submit" class="klein"><?= (int) $k['aktiv'] === 1 ? 'sperren' : 'entsperren' ?></button>
            </form>
          <?php else: ?>
            <span class="leer">eigenes Konto</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="karte">
  <h2>Neues Konto anlegen</h2>
  <p class="hinweis">
    Konten entstehen ausschließlich über die Kommandozeile. So wandert nie ein
    Passwort durch ein Formular, und ein übernommenes Admin-Konto kann sich
    keine weiteren Konten verschaffen.
  </p>
  <p class="hinweis"><code>php scripts/admin-anlegen.php anlegen &lt;name&gt; admin</code></p>
  <p class="hinweis"><code>php scripts/admin-anlegen.php passwort &lt;name&gt;</code></p>
</section>
<?php require __DIR__ . '/fuss.php'; ?>
