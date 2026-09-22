<?php
/**
 * wordQUEST – öffentliche Einreichungen (WQ-8.1, WQ-8.2).
 *
 * Hier liegt alles, was die Seite `einreichen.php` braucht und was im
 * Admincenter nicht gebraucht wird: Spamschutz, Taktbremse und das
 * Zusammenspiel mit der bestehenden Prüfkette.
 *
 * Grundsatz wie überall in diesem Projekt: **Es gibt keinen zweiten Weg in den
 * Server.** Eine öffentliche Einreichung läuft durch genau dieselbe Import- und
 * Schemaprüfung wie ein Upload im Admincenter und landet als Einreichung mit
 * Status "neu". Veröffentlicht wird sie erst durch einen Menschen.
 *
 * Bewusst **ohne CSRF-Token**. Ein Token bräuchte eine Sitzung und damit ein
 * Cookie für jede Besucherin, und das widerspricht der cookiefreien Auslegung
 * der öffentlichen Seiten. Sinnvoll wäre es auch nicht: Es gibt hier keine
 * Anmeldung und keinen Zustand, den ein fremder Absender missbrauchen könnte.
 * Was wirklich droht, ist Spam, und dagegen wirken Honigtopf, Mindestzeit und
 * Taktbremse. Die signierte Zeitmarke ist zusätzlich ein schwaches
 * Formularmerkmal: Ohne einen Aufruf der Seite lässt sie sich nicht erzeugen.
 */
declare(strict_types=1);

const WQ_EINREICHUNG_MIN_SEKUNDEN = 4;       // schneller füllt kein Mensch aus
const WQ_EINREICHUNG_MAX_SEKUNDEN = 7200;    // nach zwei Stunden neu laden
const WQ_EINREICHUNG_PRO_STUNDE   = 5;
const WQ_EINREICHUNG_PRO_TAG      = 20;
const WQ_EINREICHUNG_MAX_NAME     = 80;
const WQ_EINREICHUNG_MAX_KONTAKT  = 120;
const WQ_EINREICHUNG_MAX_BEMERK   = 500;
const WQ_XLSX_MAX_BYTES           = 2097152; // 2 MB, gezippt reicht das weit

/**
 * Serverschlüssel für Signaturen. Liegt neben der Datenbank, also ausserhalb
 * des Docroots, und wird beim ersten Bedarf erzeugt.
 */
function wq_geheimnis(): string
{
    static $geheim = null;
    if ($geheim !== null) {
        return $geheim;
    }
    $config = wq_config();
    $pfad = rtrim((string) $config['data_dir'], '/') . '/secret.key';

    if (is_file($pfad)) {
        $inhalt = (string) @file_get_contents($pfad);
        if (strlen($inhalt) >= 32) {
            return $geheim = $inhalt;
        }
    }
    $neu = random_bytes(32);
    // Erst in eine Nebendatei, dann umbenennen: Zwei gleichzeitige Anfragen
    // sollen nicht zwei verschiedene Schlüssel sehen.
    $temp = $pfad . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($temp, $neu, LOCK_EX) !== false) {
        @chmod($temp, 0600);
        @rename($temp, $pfad);
    }
    @unlink($temp);
    $inhalt = is_file($pfad) ? (string) @file_get_contents($pfad) : '';
    return $geheim = (strlen($inhalt) >= 32 ? $inhalt : $neu);
}

/* ─── Signierte Zeitmarke ───
   Der Zeitpunkt steht im Formular und darf nicht gefälscht werden, sonst wäre
   die Mindestzeit wirkungslos. */
function wq_formular_marke(): string
{
    $t = (string) time();
    return $t . '.' . hash_hmac('sha256', $t, wq_geheimnis());
}

/** @return array{ok: bool, fehler?: string} */
function wq_formular_marke_pruefen(?string $marke): array
{
    if (!is_string($marke) || !str_contains($marke, '.')) {
        return ['ok' => false, 'fehler' => 'Das Formular ist abgelaufen. Bitte die Seite neu laden.'];
    }
    [$t, $signatur] = explode('.', $marke, 2);
    if (!ctype_digit($t) || !hash_equals(hash_hmac('sha256', $t, wq_geheimnis()), $signatur)) {
        return ['ok' => false, 'fehler' => 'Das Formular ist abgelaufen. Bitte die Seite neu laden.'];
    }
    $alter = time() - (int) $t;
    if ($alter < WQ_EINREICHUNG_MIN_SEKUNDEN) {
        return ['ok' => false, 'fehler' => 'Das ging sehr schnell. Bitte kurz warten und noch einmal absenden.'];
    }
    if ($alter > WQ_EINREICHUNG_MAX_SEKUNDEN) {
        return ['ok' => false, 'fehler' => 'Das Formular ist abgelaufen. Bitte die Seite neu laden.'];
    }
    return ['ok' => true];
}

/**
 * Kennung der Absenderin für die Taktbremse.
 *
 * Die Adresse selbst wird nie gespeichert. Gespeichert wird ein HMAC, dessen
 * Schlüssel den Tag enthält: Damit ist die Kennung am Folgetag nicht mehr
 * derselben Adresse zuzuordnen, auch nicht mit dem Serverschlüssel.
 */
function wq_besucher_kennung(): string
{
    $adresse = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
    return hash_hmac('sha256', $adresse, wq_geheimnis() . gmdate('Y-m-d'));
}

function wq_takt_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS einreichung_takt (
            kennung    TEXT    NOT NULL,
            zeitpunkt  INTEGER NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_takt_zeit ON einreichung_takt (zeitpunkt)');
}

/**
 * Prüft die Taktbremse, ohne sie hochzuzählen.
 *
 * @return array{ok: bool, fehler?: string}
 */
function wq_takt_pruefen(PDO $pdo): array
{
    wq_takt_schema($pdo);
    $jetzt = time();
    // Alles älter als ein Tag fliegt raus. Damit bleibt nichts dauerhaft
    // liegen, auch nicht in gehashter Form.
    $pdo->prepare('DELETE FROM einreichung_takt WHERE zeitpunkt < :alt')
        ->execute([':alt' => $jetzt - 86400]);

    $kennung = wq_besucher_kennung();
    $zaehle = static function (PDO $pdo, string $kennung, int $ab): int {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM einreichung_takt WHERE kennung = :k AND zeitpunkt >= :ab');
        $stmt->execute([':k' => $kennung, ':ab' => $ab]);
        return (int) $stmt->fetchColumn();
    };

    if ($zaehle($pdo, $kennung, $jetzt - 3600) >= WQ_EINREICHUNG_PRO_STUNDE) {
        return ['ok' => false, 'fehler' => 'Von diesem Anschluss kamen gerade mehrere Einreichungen. Bitte in einer Stunde noch einmal versuchen.'];
    }
    if ($zaehle($pdo, $kennung, $jetzt - 86400) >= WQ_EINREICHUNG_PRO_TAG) {
        return ['ok' => false, 'fehler' => 'Von diesem Anschluss kamen heute schon viele Einreichungen. Bitte morgen noch einmal versuchen.'];
    }
    return ['ok' => true];
}

/** Zählt eine angenommene Einreichung auf die Taktbremse. */
function wq_takt_merken(PDO $pdo): void
{
    $pdo->prepare('INSERT INTO einreichung_takt (kennung, zeitpunkt) VALUES (:k, :z)')
        ->execute([':k' => wq_besucher_kennung(), ':z' => time()]);
}

/**
 * Erweitert die Einreichungstabelle um die Felder der öffentlichen Seite.
 * Bestehende Datenbanken werden dabei nachgezogen, ohne Datenverlust.
 */
function wq_einreichung_felder_ergaenzen(PDO $pdo): void
{
    wq_einreichungen_schema($pdo);
    $vorhanden = [];
    foreach ($pdo->query('PRAGMA table_info(einreichungen)') as $spalte) {
        $vorhanden[] = (string) $spalte['name'];
    }
    $neu = [
        'absender'  => 'TEXT',
        'kontakt'   => 'TEXT',
        'bemerkung' => 'TEXT',
        'quelle'    => 'TEXT',
    ];
    foreach ($neu as $name => $typ) {
        if (!in_array($name, $vorhanden, true)) {
            $pdo->exec('ALTER TABLE einreichungen ADD COLUMN ' . $name . ' ' . $typ);
        }
    }
}

/** Schreibt die Begleitangaben zu einer bereits gespeicherten Einreichung. */
function wq_einreichung_begleitung(PDO $pdo, int $id, array $angaben): void
{
    $pdo->prepare('UPDATE einreichungen SET absender = :a, kontakt = :k, bemerkung = :b, quelle = :q WHERE id = :id')
        ->execute([
            ':a'  => $angaben['absender'] ?: null,
            ':k'  => $angaben['kontakt'] ?: null,
            ':b'  => $angaben['bemerkung'] ?: null,
            ':q'  => $angaben['quelle'] ?: null,
            ':id' => $id,
        ]);
}

function wq_offene_einreichungen(PDO $pdo): int
{
    wq_einreichungen_schema($pdo);
    return (int) $pdo->query('SELECT COUNT(*) FROM einreichungen WHERE status = "neu"')->fetchColumn();
}

/** Kürzt und säubert eine Freitextangabe aus dem öffentlichen Formular. */
function wq_freitext(?string $wert, int $max): string
{
    $wert = trim((string) $wert);
    // Steuerzeichen raus, Zeilenumbrüche in der Bemerkung bleiben erlaubt.
    $wert = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $wert);
    return mb_substr($wert, 0, $max);
}
