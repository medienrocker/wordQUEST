<?php
/**
 * wordQUEST – Anmeldung und Rollen (WQ-7.3).
 *
 * Zwei Rollen:
 *   superadmin  verwaltet Admins, darf alles
 *   admin       nur Inhalte
 *
 * Grundsätze, die hier bewusst umgesetzt sind:
 *  - Passwörter nur als Hash, nie im Klartext, nie im Repo.
 *  - Gegen Benutzer-Enumeration und Timing-Angriffe wird auch bei unbekanntem
 *    Benutzernamen gegen einen Dummy-Hash geprüft, damit die Antwortzeit
 *    gleich bleibt und die Fehlermeldung immer dieselbe ist.
 *  - Nach zu vielen Fehlversuchen wird das Konto zeitlich gesperrt.
 *  - Rollenprüfung läuft serverseitig bei jeder Aktion, nie nur im
 *    Ausblenden von Knöpfen.
 */
declare(strict_types=1);

const WQ_MAX_FEHLVERSUCHE   = 10;
const WQ_SPERRE_SEKUNDEN    = 900;    // 15 Minuten
const WQ_SITZUNG_LEERLAUF   = 3600;   // 60 Minuten ohne Aktivität
const WQ_ROLLEN             = ['superadmin', 'admin'];

/* Ein fester, gültiger Hash für den Vergleich bei unbekanntem Benutzer.
   Der Klartext dazu ist bedeutungslos, er wird nie geprüft. */
const WQ_DUMMY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7Q5U5rqz3GJ9mZsVtF8Qz9cJ5aXm9Bu';

function wq_algo(): string
{
    // Argon2id, wenn die PHP-Installation ihn mitbringt, sonst bcrypt.
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function wq_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $sicher = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $sicher,
    ]);
    session_name('wqadmin');
    session_start();

    // Leerlauf beendet die Sitzung.
    $jetzt = time();
    if (isset($_SESSION['zuletzt']) && ($jetzt - (int) $_SESSION['zuletzt']) > WQ_SITZUNG_LEERLAUF) {
        wq_logout();
        wq_session_start();
        return;
    }
    $_SESSION['zuletzt'] = $jetzt;
}

/* ─── Schema für Admins ─── */
function wq_auth_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS admins (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            benutzername  TEXT    NOT NULL UNIQUE,
            passwort_hash TEXT    NOT NULL,
            rolle         TEXT    NOT NULL DEFAULT "admin",
            aktiv         INTEGER NOT NULL DEFAULT 1,
            fehlversuche  INTEGER NOT NULL DEFAULT 0,
            gesperrt_bis  INTEGER NOT NULL DEFAULT 0,
            erstellt_am   TEXT    NOT NULL,
            zuletzt_am    TEXT
        )
    ');
}

function wq_admin_nach_name(PDO $pdo, string $name): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE benutzername = :n LIMIT 1');
    $stmt->execute([':n' => $name]);
    $zeile = $stmt->fetch();
    return $zeile ?: null;
}

/**
 * Prüft Zugangsdaten und meldet an.
 * Gibt immer dieselbe Fehlermeldung zurück, egal woran es lag.
 */
function wq_login(string $name, string $passwort): array
{
    $pdo = wq_db();
    wq_auth_schema($pdo);
    $admin = wq_admin_nach_name($pdo, $name);
    $jetzt = time();

    // Gesperrt? Auch hier nicht verraten, ob es den Benutzer gibt.
    if ($admin && (int) $admin['gesperrt_bis'] > $jetzt) {
        $rest = (int) ceil(((int) $admin['gesperrt_bis'] - $jetzt) / 60);
        return ['ok' => false, 'fehler' => 'Zu viele Fehlversuche. Bitte in ' . $rest . ' Minuten erneut versuchen.'];
    }

    // Gegen Dummy prüfen, wenn es den Benutzer nicht gibt: gleiche Laufzeit.
    $hash = ($admin && !empty($admin['passwort_hash'])) ? (string) $admin['passwort_hash'] : WQ_DUMMY_HASH;
    $passt = password_verify($passwort, $hash);

    if (!$admin || !$passt || (int) $admin['aktiv'] !== 1) {
        if ($admin) {
            $versuche = (int) $admin['fehlversuche'] + 1;
            $sperre = $versuche >= WQ_MAX_FEHLVERSUCHE ? $jetzt + WQ_SPERRE_SEKUNDEN : 0;
            $stmt = $pdo->prepare('UPDATE admins SET fehlversuche = :v, gesperrt_bis = :s WHERE id = :id');
            $stmt->execute([':v' => $versuche, ':s' => $sperre, ':id' => $admin['id']]);
        }
        // Kleine zufällige Verzögerung erschwert das Ausmessen zusätzlich.
        usleep(random_int(150000, 400000));
        return ['ok' => false, 'fehler' => 'Benutzername oder Passwort falsch.'];
    }

    // Erfolg: Zähler zurücksetzen, Hash bei Bedarf erneuern.
    if (password_needs_rehash($hash, wq_algo())) {
        $neu = password_hash($passwort, wq_algo());
        $pdo->prepare('UPDATE admins SET passwort_hash = :h WHERE id = :id')
            ->execute([':h' => $neu, ':id' => $admin['id']]);
    }
    $pdo->prepare('UPDATE admins SET fehlversuche = 0, gesperrt_bis = 0, zuletzt_am = :t WHERE id = :id')
        ->execute([':t' => gmdate('Y-m-d H:i:s'), ':id' => $admin['id']]);

    wq_session_start();
    // Gegen Session-Fixation: neue Kennung nach der Anmeldung.
    session_regenerate_id(true);
    $_SESSION['admin_id']    = (int) $admin['id'];
    $_SESSION['admin_name']  = (string) $admin['benutzername'];
    $_SESSION['admin_rolle'] = (string) $admin['rolle'];
    $_SESSION['zuletzt']     = $jetzt;

    return ['ok' => true];
}

function wq_logout(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }
}

function wq_aktueller_admin(): ?array
{
    wq_session_start();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return [
        'id'    => (int) $_SESSION['admin_id'],
        'name'  => (string) ($_SESSION['admin_name'] ?? ''),
        'rolle' => (string) ($_SESSION['admin_rolle'] ?? 'admin'),
    ];
}

function wq_ist_angemeldet(): bool
{
    return wq_aktueller_admin() !== null;
}

/** Bricht ab, wenn niemand angemeldet ist. */
function wq_verlange_login(): array
{
    $admin = wq_aktueller_admin();
    if (!$admin) {
        header('Location: index.php');
        exit;
    }
    return $admin;
}

/**
 * Bricht ab, wenn die Rolle nicht reicht.
 * Muss bei JEDER schreibenden Aktion serverseitig aufgerufen werden,
 * das Ausblenden von Knöpfen ist keine Absicherung.
 */
function wq_verlange_rolle(string $rolle): array
{
    $admin = wq_verlange_login();
    if ($rolle === 'superadmin' && $admin['rolle'] !== 'superadmin') {
        http_response_code(403);
        exit('Nicht erlaubt.');
    }
    return $admin;
}

/* ─── CSRF ─── */
function wq_csrf_token(): string
{
    wq_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function wq_csrf_gueltig(?string $token): bool
{
    wq_session_start();
    if (empty($_SESSION['csrf']) || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals((string) $_SESSION['csrf'], $token);
}

function wq_verlange_csrf(): void
{
    if (!wq_csrf_gueltig($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Sitzung abgelaufen. Bitte die Seite neu laden.');
    }
}

/* ─── Admin-Verwaltung (nur Superadmin) ─── */
function wq_admin_anlegen(PDO $pdo, string $name, string $passwort, string $rolle = 'admin'): array
{
    wq_auth_schema($pdo);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 60) {
        return ['ok' => false, 'fehler' => 'Benutzername fehlt oder ist zu lang.'];
    }
    if (mb_strlen($passwort) < 12) {
        return ['ok' => false, 'fehler' => 'Das Passwort braucht mindestens 12 Zeichen.'];
    }
    if (!in_array($rolle, WQ_ROLLEN, true)) {
        return ['ok' => false, 'fehler' => 'Unbekannte Rolle.'];
    }
    if (wq_admin_nach_name($pdo, $name)) {
        return ['ok' => false, 'fehler' => 'Diesen Benutzernamen gibt es schon.'];
    }
    $stmt = $pdo->prepare('
        INSERT INTO admins (benutzername, passwort_hash, rolle, aktiv, erstellt_am)
        VALUES (:n, :h, :r, 1, :t)
    ');
    $stmt->execute([
        ':n' => $name,
        ':h' => password_hash($passwort, wq_algo()),
        ':r' => $rolle,
        ':t' => gmdate('Y-m-d H:i:s'),
    ]);
    return ['ok' => true];
}

function wq_anzahl_superadmins(PDO $pdo): int
{
    wq_auth_schema($pdo);
    return (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE rolle = 'superadmin' AND aktiv = 1")->fetchColumn();
}
