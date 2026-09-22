<?php
/**
 * wordQUEST – Datenbankzugriff (SQLite).
 *
 * Bewusst SQLite statt MySQL: kein Datenbankserver, keine Zugangsdaten,
 * keine Einrichtung in Plesk. Die Sicherung ist eine Dateikopie.
 *
 * Das Schema legt sich beim ersten Aufruf selbst an und wird über eine
 * Versionsnummer fortgeschrieben, damit ein Deploy reproduzierbar bleibt.
 */
declare(strict_types=1);

const WQ_SCHEMA_VERSION = 1;

function wq_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = wq_config();
    $verzeichnis = (string) $config['data_dir'];
    $datei = (string) $config['db_file'];

    if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0770, true) && !is_dir($verzeichnis)) {
        throw new RuntimeException('Datenverzeichnis nicht anlegbar: ' . $verzeichnis);
    }

    $pdo = new PDO('sqlite:' . $datei, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // WAL: erlaubt gleichzeitiges Lesen während geschrieben wird. Bei einer
    // Klasse, die zeitgleich spielt, ist das der Unterschied zwischen
    // "läuft" und "database is locked".
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 3000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    wq_db_migrieren($pdo);
    return $pdo;
}

function wq_db_migrieren(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= WQ_SCHEMA_VERSION) {
        return;
    }

    if ($version < 1) {
        // Tageszähler. Bewusst aggregiert: keine Einzelereignisse, keine
        // Uhrzeit feiner als der Tag, keine Adresse, keine Sitzungskennung.
        // Damit entsteht kein Personenbezug.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS stats_taeglich (
                tag        TEXT    NOT NULL,
                bereich    TEXT    NOT NULL,
                schluessel TEXT    NOT NULL,
                anzahl     INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (tag, bereich, schluessel)
            )
        ');

        // Trefferquote je Vokabel über alle Spielenden hinweg. Das ist der
        // wertvollste Wert: Er zeigt, welche Wörter durchgängig schwierig
        // sind, also meist mehrdeutige Übersetzungen oder schlechte Bilder.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS stats_wort (
                liste   TEXT    NOT NULL,
                wort    TEXT    NOT NULL,
                richtig INTEGER NOT NULL DEFAULT 0,
                falsch  INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (liste, wort)
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stats_tag ON stats_taeglich (tag)');
    }

    $pdo->exec('PRAGMA user_version = ' . WQ_SCHEMA_VERSION);
}

/** Zähler für einen Tag erhöhen. */
function wq_zaehler_erhoehen(PDO $pdo, string $tag, string $bereich, string $schluessel, int $um = 1): void
{
    $stmt = $pdo->prepare('
        INSERT INTO stats_taeglich (tag, bereich, schluessel, anzahl)
        VALUES (:tag, :bereich, :schluessel, :um)
        ON CONFLICT (tag, bereich, schluessel)
        DO UPDATE SET anzahl = anzahl + :um
    ');
    $stmt->execute([':tag' => $tag, ':bereich' => $bereich, ':schluessel' => $schluessel, ':um' => $um]);
}

/** Trefferquote einer Vokabel fortschreiben. */
function wq_wort_erhoehen(PDO $pdo, string $liste, string $wort, bool $richtig): void
{
    $spalte = $richtig ? 'richtig' : 'falsch';
    $stmt = $pdo->prepare("
        INSERT INTO stats_wort (liste, wort, richtig, falsch)
        VALUES (:liste, :wort, :r, :f)
        ON CONFLICT (liste, wort)
        DO UPDATE SET {$spalte} = {$spalte} + 1
    ");
    $stmt->execute([
        ':liste' => $liste,
        ':wort'  => $wort,
        ':r'     => $richtig ? 1 : 0,
        ':f'     => $richtig ? 0 : 1,
    ]);
}
