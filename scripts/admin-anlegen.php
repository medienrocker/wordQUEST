<?php
/**
 * wordQUEST – Admins auf der Kommandozeile verwalten.
 *
 * Nur über SSH aufrufbar, nie über das Web. So entsteht der erste Superadmin,
 * ohne dass je ein Passwort durch ein Formular oder ins Repo wandert.
 *
 *   php scripts/admin-anlegen.php liste
 *   php scripts/admin-anlegen.php anlegen <name> [superadmin|admin]
 *   php scripts/admin-anlegen.php passwort <name>
 *   php scripts/admin-anlegen.php sperren <name>
 *   php scripts/admin-anlegen.php entsperren <name>
 *
 * Das Passwort wird abgefragt, nicht als Argument übergeben: Argumente landen
 * in der Prozessliste und in der Shell-Historie.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile.');
}

require __DIR__ . '/../api/lib/bootstrap.php';
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/auth.php';

/* Auf Plesk-Servern ist `php` oft die System-PHP des Betriebssystems, nicht
   die des Webauftritts. Ihr fehlt häufig pdo_sqlite, und dann scheitert das
   Skript mit einer wenig sprechenden Meldung. Deshalb hier früh und
   deutlich prüfen. */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Diesem PHP fehlt der SQLite-Treiber (pdo_sqlite).
");
    fwrite(STDERR, 'Verwendet wurde: ' . PHP_BINARY . ' (Version ' . PHP_VERSION . ")

");
    $kandidaten = glob('/opt/plesk/php/*/bin/php') ?: [];
    if ($kandidaten) {
        rsort($kandidaten);
        fwrite(STDERR, "Nimm stattdessen die PHP-Version des Webauftritts, zum Beispiel:
");
        fwrite(STDERR, '  ' . $kandidaten[0] . ' ' . ($argv[0] ?? 'scripts/admin-anlegen.php') . " ...
");
    }
    exit(1);
}

function frage_passwort(string $text): string
{
    echo $text;
    // Eingabe verbergen, wo die Shell das hergibt.
    $versteckt = @shell_exec('stty -echo 2>/dev/null; echo ok') !== null;
    $eingabe = trim((string) fgets(STDIN));
    if ($versteckt) {
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }
    return $eingabe;
}

$befehl = $argv[1] ?? '';
$pdo = wq_db();
wq_auth_schema($pdo);

switch ($befehl) {
    case 'liste':
        $zeilen = $pdo->query('SELECT benutzername, rolle, aktiv, zuletzt_am FROM admins ORDER BY benutzername')->fetchAll();
        if (!$zeilen) {
            echo "Noch keine Admins angelegt.\n";
            break;
        }
        printf("%-24s %-12s %-7s %s\n", 'Benutzer', 'Rolle', 'Aktiv', 'Zuletzt angemeldet');
        foreach ($zeilen as $z) {
            printf("%-24s %-12s %-7s %s\n", $z['benutzername'], $z['rolle'],
                ((int) $z['aktiv'] === 1 ? 'ja' : 'nein'), $z['zuletzt_am'] ?? '-');
        }
        break;

    case 'anlegen':
        $name  = $argv[2] ?? '';
        $rolle = $argv[3] ?? 'admin';
        if ($name === '') {
            exit("Aufruf: php scripts/admin-anlegen.php anlegen <name> [superadmin|admin]\n");
        }
        $p1 = frage_passwort('Passwort (mindestens 12 Zeichen): ');
        $p2 = frage_passwort('Passwort wiederholen: ');
        if ($p1 !== $p2) {
            exit("Die Passwörter stimmen nicht überein.\n");
        }
        $ergebnis = wq_admin_anlegen($pdo, $name, $p1, $rolle);
        echo $ergebnis['ok']
            ? "Angelegt: {$name} ({$rolle})\n"
            : "Fehler: {$ergebnis['fehler']}\n";
        break;

    case 'passwort':
        $name = $argv[2] ?? '';
        $admin = $name !== '' ? wq_admin_nach_name($pdo, $name) : null;
        if (!$admin) {
            exit("Unbekannter Benutzer.\n");
        }
        $p1 = frage_passwort('Neues Passwort (mindestens 12 Zeichen): ');
        $p2 = frage_passwort('Wiederholen: ');
        if ($p1 !== $p2) {
            exit("Die Passwörter stimmen nicht überein.\n");
        }
        if (mb_strlen($p1) < 12) {
            exit("Zu kurz.\n");
        }
        $pdo->prepare('UPDATE admins SET passwort_hash = :h, fehlversuche = 0, gesperrt_bis = 0 WHERE id = :id')
            ->execute([':h' => password_hash($p1, wq_algo()), ':id' => $admin['id']]);
        echo "Passwort geändert.\n";
        break;

    case 'sperren':
    case 'entsperren':
        $name = $argv[2] ?? '';
        $admin = $name !== '' ? wq_admin_nach_name($pdo, $name) : null;
        if (!$admin) {
            exit("Unbekannter Benutzer.\n");
        }
        $aktiv = $befehl === 'entsperren' ? 1 : 0;
        // Der letzte aktive Superadmin darf nicht gesperrt werden, sonst
        // sperrt man sich selbst dauerhaft aus.
        if ($aktiv === 0 && $admin['rolle'] === 'superadmin' && wq_anzahl_superadmins($pdo) <= 1) {
            exit("Das ist der letzte aktive Superadmin. Erst einen zweiten anlegen.\n");
        }
        $pdo->prepare('UPDATE admins SET aktiv = :a, fehlversuche = 0, gesperrt_bis = 0 WHERE id = :id')
            ->execute([':a' => $aktiv, ':id' => $admin['id']]);
        echo ($aktiv === 1 ? "Entsperrt.\n" : "Gesperrt.\n");
        break;

    default:
        echo "wordQUEST Admin-Verwaltung\n\n";
        echo "  php scripts/admin-anlegen.php liste\n";
        echo "  php scripts/admin-anlegen.php anlegen <name> [superadmin|admin]\n";
        echo "  php scripts/admin-anlegen.php passwort <name>\n";
        echo "  php scripts/admin-anlegen.php sperren <name>\n";
        echo "  php scripts/admin-anlegen.php entsperren <name>\n";
}
