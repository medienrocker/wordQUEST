<?php
/**
 * wordQUEST – Ehrentafel und Gemeinschaftszähler (WQ-8.3).
 *
 * Der soziale Reiz einer Bestenliste, ohne den Rangvergleich. Auf die Tafel
 * kommt, wer eine Liste **komplett durchgespielt** hat. Nicht wer schnell war,
 * nicht wer viele Punkte hat. Damit ist der Eintrag für jedes Kind erreichbar,
 * und niemand steht unten.
 *
 * Übertragen wird ausschliesslich: generierter Name, Tier-Emoji und welche
 * Liste es war. Keine Punktzahl, keine Dauer, keine Gerätekennung, und der
 * Zeitpunkt kommt vom Server. Ein Eintrag lässt sich keinem Kind zuordnen.
 *
 * Der Gemeinschaftszähler kommt ohne neue Daten aus: Er summiert die
 * Tageszähler der Statistik über die letzten sieben Tage.
 */
declare(strict_types=1);

const WQ_TAFEL_EINTRAEGE   = 12;     // sichtbare Zeilen
const WQ_TAFEL_BEHALTEN    = 200;    // ältere werden weggeräumt
const WQ_TAFEL_PRO_TAG     = 20;     // je Anschluss
const WQ_TAFEL_WIEDERHOLUNG = 21600; // 6 Stunden, gegen doppelte Meldungen
const WQ_TAFEL_MAX_LISTEN  = 5;      // ein Durchlauf kann mehrere Listen umfassen

/* ─── Namensbestandteile ───
   Spiegel der Listen aus index.html. Die Doppelung ist der Preis dafür, dass
   der Server einen Namen überhaupt prüfen kann: Nur was die App wirklich
   erzeugen kann, kommt auf die Tafel. Ohne das liesse sich jeder beliebige
   Text eintragen. Wer die Listen in der App erweitert, muss sie hier
   mitziehen, sonst erscheinen neue Namen nicht. */
const WQ_TAFEL_ADJEKTIVE = [
    'flink', 'klug', 'mutig', 'fröhlich', 'neugierig', 'tapfer', 'witzig', 'ruhig', 'schnell', 'clever',
    'freundlich', 'wach', 'pfiffig', 'munter', 'stark', 'sanft', 'listig', 'heiter', 'emsig', 'kühn',
    'flott', 'schlau', 'geduldig', 'eifrig', 'gelassen', 'wendig', 'zäh', 'sportlich', 'frech', 'nett',
];

/** Tier => [Geschlecht, Emoji] */
function wq_tafel_tiere(): array
{
    return [
        'Fuchs' => ['m', '🦊'], 'Igel' => ['m', '🦔'], 'Hase' => ['m', '🐰'],
        'Bär' => ['m', '🐻'], 'Wolf' => ['m', '🐺'], 'Tiger' => ['m', '🐯'],
        'Löwe' => ['m', '🦁'], 'Delfin' => ['m', '🐬'], 'Pinguin' => ['m', '🐧'],
        'Papagei' => ['m', '🦜'], 'Frosch' => ['m', '🐸'], 'Waschbär' => ['m', '🦝'],
        'Hirsch' => ['m', '🦌'], 'Wal' => ['m', '🐳'], 'Adler' => ['m', '🦅'],
        'Hamster' => ['m', '🐹'], 'Affe' => ['m', '🐵'], 'Elefant' => ['m', '🐘'],
        'Hund' => ['m', '🐶'], 'Panda' => ['m', '🐼'],
        'Eule' => ['f', '🦉'], 'Biene' => ['f', '🐝'], 'Maus' => ['f', '🐭'],
        'Katze' => ['f', '🐱'], 'Schildkröte' => ['f', '🐢'], 'Giraffe' => ['f', '🦒'],
        'Robbe' => ['f', '🦭'], 'Ente' => ['f', '🦆'], 'Schlange' => ['f', '🐍'],
        'Fledermaus' => ['f', '🦇'], 'Schnecke' => ['f', '🐌'], 'Krabbe' => ['f', '🦀'],
        'Kuh' => ['f', '🐮'], 'Eidechse' => ['f', '🦎'],
        'Reh' => ['n', '🦌'], 'Faultier' => ['n', '🦥'], 'Eichhörnchen' => ['n', '🐿️'],
        'Nilpferd' => ['n', '🦛'], 'Krokodil' => ['n', '🐊'], 'Zebra' => ['n', '🦓'],
        'Küken' => ['n', '🐥'], 'Lama' => ['n', '🦙'], 'Schaf' => ['n', '🐑'],
        'Pferd' => ['n', '🐴'],
    ];
}

function wq_tafel_endung(string $genus): string
{
    return $genus === 'm' ? 'er' : ($genus === 'f' ? 'e' : 'es');
}

/**
 * Prüft, ob Name und Emoji so von der App erzeugt worden sein können.
 *
 * Das ist die eigentliche Missbrauchsschranke. Freitext käme sonst
 * ungefiltert auf eine Seite, die Kinder lesen.
 */
function wq_tafel_name_gueltig(string $name, string $emoji): bool
{
    $teile = explode(' ', trim($name));
    if (count($teile) !== 2) {
        return false;
    }
    [$wort, $tier] = $teile;
    $tiere = wq_tafel_tiere();
    if (!isset($tiere[$tier])) {
        return false;
    }
    [$genus, $tierEmoji] = $tiere[$tier];
    if ($emoji !== $tierEmoji) {
        return false;   // Emoji muss zum Tier passen
    }
    $endung = wq_tafel_endung($genus);
    foreach (WQ_TAFEL_ADJEKTIVE as $adj) {
        $erwartet = mb_strtoupper(mb_substr($adj, 0, 1)) . mb_substr($adj, 1) . $endung;
        if ($wort === $erwartet) {
            return true;
        }
    }
    return false;
}

function wq_tafel_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS tafel (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            name      TEXT    NOT NULL,
            avatar    TEXT    NOT NULL,
            liste     TEXT    NOT NULL,
            titel     TEXT    NOT NULL,
            zeitpunkt INTEGER NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tafel_zeit ON tafel (zeitpunkt)');
}

/**
 * Trägt einen Listenabschluss ein.
 *
 * Ein Durchlauf kann mehrere Listen umfassen, weil sich in der App mehrere
 * zugleich auswählen lassen. Der Eintrag nennt deshalb alle, sonst stünde auf
 * der Tafel eine Liste, die so gar nicht gespielt wurde.
 *
 * @param string[] $dateien
 * @return array{ok: bool, fehler?: string, doppelt?: bool}
 */
function wq_tafel_eintragen(PDO $pdo, string $name, string $avatar, array $dateien): array
{
    wq_tafel_schema($pdo);

    if (!wq_tafel_name_gueltig($name, $avatar)) {
        return ['ok' => false, 'fehler' => 'Name oder Tier passen nicht.'];
    }

    $dateien = array_values(array_unique(array_map('basename', array_filter($dateien, 'is_string'))));
    if (!$dateien || count($dateien) > WQ_TAFEL_MAX_LISTEN) {
        return ['ok' => false, 'fehler' => 'Ungültige Angabe der Listen.'];
    }
    sort($dateien);

    $archiviert = wq_archivierte();
    $titel = [];
    foreach ($dateien as $datei) {
        $liste = wq_wortliste_lesen($datei);
        if ($liste === null) {
            return ['ok' => false, 'fehler' => 'Diese Liste gibt es nicht.'];
        }
        if (in_array($datei, $archiviert, true)) {
            return ['ok' => false, 'fehler' => 'Diese Liste ist archiviert.'];
        }
        $titel[] = isset($liste['title']) && is_string($liste['title']) && trim($liste['title']) !== ''
            ? trim($liste['title'])
            : pathinfo($datei, PATHINFO_FILENAME);
    }
    $titel = mb_substr(implode(' + ', $titel), 0, WQ_LISTE_MAX_TITEL);
    $datei = implode('|', $dateien);

    $jetzt = time();

    /* Dieselbe Meldung zweimal kurz hintereinander ist kein zweiter Erfolg,
       sondern meist ein neu geladener Browser. Sie füllt die Tafel und
       verdrängt andere Kinder. */
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM tafel
        WHERE name = :n AND liste = :l AND zeitpunkt > :ab
    ');
    $stmt->execute([':n' => $name, ':l' => $datei, ':ab' => $jetzt - WQ_TAFEL_WIEDERHOLUNG]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => true, 'doppelt' => true];
    }

    /* Obergrenze je Anschluss und Tag. Genutzt wird dieselbe Kennung wie bei
       den Einreichungen: ein HMAC mit tageweise wechselndem Schlüssel, aus dem
       sich keine Adresse zurückrechnen lässt. */
    wq_takt_schema($pdo);
    $kennung = 'tafel:' . wq_besucher_kennung();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM einreichung_takt WHERE kennung = :k AND zeitpunkt >= :ab');
    $stmt->execute([':k' => $kennung, ':ab' => $jetzt - 86400]);
    if ((int) $stmt->fetchColumn() >= WQ_TAFEL_PRO_TAG) {
        return ['ok' => false, 'fehler' => 'Für heute sind genug Einträge von hier gekommen.'];
    }
    $pdo->prepare('INSERT INTO einreichung_takt (kennung, zeitpunkt) VALUES (:k, :z)')
        ->execute([':k' => $kennung, ':z' => $jetzt]);

    $pdo->prepare('
        INSERT INTO tafel (name, avatar, liste, titel, zeitpunkt)
        VALUES (:n, :a, :l, :t, :z)
    ')->execute([':n' => $name, ':a' => $avatar, ':l' => $datei, ':t' => $titel, ':z' => $jetzt]);

    // Alte Einträge wegräumen, die Tafel ist kein Archiv.
    $pdo->exec('
        DELETE FROM tafel WHERE id NOT IN (
            SELECT id FROM tafel ORDER BY zeitpunkt DESC LIMIT ' . WQ_TAFEL_BEHALTEN . '
        )
    ');

    return ['ok' => true];
}

/** Die jüngsten Einträge, neueste zuerst. */
function wq_tafel_eintraege(PDO $pdo, int $anzahl = WQ_TAFEL_EINTRAEGE): array
{
    wq_tafel_schema($pdo);
    $stmt = $pdo->prepare('SELECT id, name, avatar, titel, zeitpunkt FROM tafel ORDER BY zeitpunkt DESC, id DESC LIMIT :n');
    $stmt->bindValue(':n', max(1, min(200, $anzahl)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function wq_tafel_loeschen(PDO $pdo, int $id): void
{
    wq_tafel_schema($pdo);
    $pdo->prepare('DELETE FROM tafel WHERE id = :id')->execute([':id' => $id]);
}

/**
 * Gemeinschaftszähler: geübte Vokabeln der letzten sieben Tage.
 *
 * Kommt ohne neue Daten aus. Gezählt wird, was die Statistik ohnehin je Tag
 * und Modus festhält. Alle zahlen ein, niemand verliert etwas.
 */
function wq_tafel_gemeinschaft(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(anzahl), 0) FROM stats_taeglich
            WHERE bereich = "fragen" AND tag >= :ab
        ');
        $stmt->execute([':ab' => gmdate('Y-m-d', time() - 6 * 86400)]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
