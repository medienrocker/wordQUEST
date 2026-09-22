<?php
/**
 * wordQUEST – Klassenmodus mit Code (Epic 10, Punkt 3).
 *
 * Eine Lehrkraft erzeugt einen Code, gibt ihn der Klasse, und alle üben
 * denselben Satz Vokabeln. Die Lehrkraft sieht den Fortschritt der Klasse als
 * Summe.
 *
 * ─── Was eine Lehrkraft sehen darf, und was nicht ───
 *
 * **Nie einzelne Kinder.** Es gibt keine Namensliste, keine Anwesenheit, keine
 * Zeile je Gerät. Gespeichert werden ausschliesslich Summen je Klasse, in
 * genau derselben Form wie die anonyme Gesamtstatistik: Tageszähler und
 * Trefferquoten je Vokabel. Damit ist der Modus von der Bauart her
 * datenschutzkonform und nicht erst durch eine Zusage.
 *
 * **Keine Gerätekennung.** Gezählt werden Beitritte, also wie oft der Code
 * eingegeben wurde, nicht wie viele verschiedene Geräte dahinterstehen. Der
 * Unterschied ist wichtig: Eine Zahl verschiedener Geräte liesse sich nur mit
 * einer Kennung ermitteln, und die wollen wir nicht. Die Anzeige heisst
 * deshalb ehrlich "Beitritte" und nicht "Kinder".
 *
 * **Mindestzahl vor der ersten Zahl.** Solange weniger als drei Beitritte
 * vorliegen, zeigt die Übersicht keine Werte. Eine Klassensumme aus einem
 * einzigen Gerät ist keine Summe, sondern der Lernstand eines Kindes.
 *
 * **Der wertvollste Wert ist die Fehlerliste.** Welche Wörter der Klasse
 * durchgängig danebengehen, ist die Information, für die eine Lehrkraft
 * wiederkommt, und sie ist von Natur aus aggregiert.
 */
declare(strict_types=1);

const WQ_KLASSE_MINDEST      = 3;        // Beitritte, bevor Zahlen erscheinen
const WQ_KLASSE_GUELTIG_TAGE = 200;      // danach läuft eine Klasse aus
const WQ_KLASSE_MAX_NAME     = 60;
const WQ_KLASSE_MAX_LISTEN   = 8;
const WQ_KLASSE_PRO_TAG      = 10;       // neue Klassen je Anschluss

/* Zeichen ohne Verwechslungsgefahr: kein I und l, kein O und 0.
   Ein Code wird an der Tafel vorgelesen und von Kindern abgetippt. */
const WQ_KLASSE_BUCHSTABEN = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const WQ_KLASSE_ZIFFERN    = '23456789';

function wq_klassen_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS klassen (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            code        TEXT    NOT NULL UNIQUE,
            token       TEXT    NOT NULL UNIQUE,
            name        TEXT    NOT NULL,
            listen      TEXT    NOT NULL,
            beitritte   INTEGER NOT NULL DEFAULT 0,
            erstellt_am TEXT    NOT NULL,
            gueltig_bis INTEGER NOT NULL,
            aktiv       INTEGER NOT NULL DEFAULT 1
        )
    ');
    // Summen je Klasse, genau wie die Gesamtstatistik: nach Tag aggregiert,
    // ohne Uhrzeit, ohne Adresse, ohne Kennung.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS klassen_taeglich (
            klasse     INTEGER NOT NULL,
            tag        TEXT    NOT NULL,
            bereich    TEXT    NOT NULL,
            schluessel TEXT    NOT NULL,
            anzahl     INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (klasse, tag, bereich, schluessel)
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS klassen_wort (
            klasse  INTEGER NOT NULL,
            liste   TEXT    NOT NULL,
            wort    TEXT    NOT NULL,
            richtig INTEGER NOT NULL DEFAULT 0,
            falsch  INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (klasse, liste, wort)
        )
    ');
}

/** Erzeugt einen vorlesbaren Code der Form ABC-234. */
function wq_klasse_code_erzeugen(PDO $pdo): string
{
    for ($versuch = 0; $versuch < 40; $versuch++) {
        $code = '';
        for ($i = 0; $i < 3; $i++) {
            $code .= WQ_KLASSE_BUCHSTABEN[random_int(0, strlen(WQ_KLASSE_BUCHSTABEN) - 1)];
        }
        $code .= '-';
        for ($i = 0; $i < 3; $i++) {
            $code .= WQ_KLASSE_ZIFFERN[random_int(0, strlen(WQ_KLASSE_ZIFFERN) - 1)];
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM klassen WHERE code = :c');
        $stmt->execute([':c' => $code]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
    }
    throw new RuntimeException('Es liess sich kein freier Klassencode finden.');
}

/** Vereinheitlicht eine Eingabe: Grossbuchstaben, Bindestrich ergänzt. */
function wq_klasse_code_normal(string $eingabe): string
{
    $roh = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $eingabe));
    if (strlen($roh) !== 6) {
        return '';
    }
    return substr($roh, 0, 3) . '-' . substr($roh, 3, 3);
}

/**
 * Legt eine Klasse an.
 *
 * @param string[] $listen
 * @return array{ok: bool, fehler?: string, klasse?: array}
 */
function wq_klasse_anlegen(PDO $pdo, string $name, array $listen): array
{
    wq_klassen_schema($pdo);

    $name = wq_freitext($name, WQ_KLASSE_MAX_NAME);
    if ($name === '') {
        return ['ok' => false, 'fehler' => 'Bitte einen Namen für die Klasse angeben.'];
    }

    $listen = array_values(array_unique(array_map('basename', array_filter($listen, 'is_string'))));
    if (!$listen) {
        return ['ok' => false, 'fehler' => 'Bitte mindestens eine Wortliste wählen.'];
    }
    if (count($listen) > WQ_KLASSE_MAX_LISTEN) {
        return ['ok' => false, 'fehler' => 'Höchstens ' . WQ_KLASSE_MAX_LISTEN . ' Listen je Klasse.'];
    }
    $archiviert = wq_archivierte();
    foreach ($listen as $datei) {
        if (wq_wortliste_lesen($datei) === null || in_array($datei, $archiviert, true)) {
            return ['ok' => false, 'fehler' => 'Eine der gewählten Listen gibt es nicht mehr.'];
        }
    }

    $code  = wq_klasse_code_erzeugen($pdo);
    $token = bin2hex(random_bytes(16));
    $bis   = time() + WQ_KLASSE_GUELTIG_TAGE * 86400;

    $pdo->prepare('
        INSERT INTO klassen (code, token, name, listen, erstellt_am, gueltig_bis, aktiv)
        VALUES (:c, :t, :n, :l, :e, :g, 1)
    ')->execute([
        ':c' => $code,
        ':t' => $token,
        ':n' => $name,
        ':l' => implode('|', $listen),
        ':e' => gmdate('Y-m-d H:i:s'),
        ':g' => $bis,
    ]);

    return ['ok' => true, 'klasse' => wq_klasse_nach_code($pdo, $code)];
}

function wq_klasse_nach_code(PDO $pdo, string $code): ?array
{
    wq_klassen_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM klassen WHERE code = :c LIMIT 1');
    $stmt->execute([':c' => $code]);
    $zeile = $stmt->fetch();
    return $zeile ?: null;
}

function wq_klasse_nach_token(PDO $pdo, string $token): ?array
{
    wq_klassen_schema($pdo);
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM klassen WHERE token = :t LIMIT 1');
    $stmt->execute([':t' => $token]);
    $zeile = $stmt->fetch();
    return $zeile ?: null;
}

/** Nutzbar heisst: vorhanden, aktiv und nicht abgelaufen. */
function wq_klasse_nutzbar(?array $klasse): bool
{
    return $klasse !== null
        && (int) $klasse['aktiv'] === 1
        && (int) $klasse['gueltig_bis'] > time();
}

/** Zählt einen Beitritt. Kein Gerät, keine Kennung, nur ein Zähler. */
function wq_klasse_beitritt(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE klassen SET beitritte = beitritte + 1 WHERE id = :id')->execute([':id' => $id]);
}

function wq_klasse_zaehler(PDO $pdo, int $klasse, string $tag, string $bereich, string $schluessel, int $um = 1): void
{
    $pdo->prepare('
        INSERT INTO klassen_taeglich (klasse, tag, bereich, schluessel, anzahl)
        VALUES (:k, :tag, :b, :s, :um)
        ON CONFLICT (klasse, tag, bereich, schluessel)
        DO UPDATE SET anzahl = anzahl + :um
    ')->execute([':k' => $klasse, ':tag' => $tag, ':b' => $bereich, ':s' => $schluessel, ':um' => $um]);
}

function wq_klasse_wort(PDO $pdo, int $klasse, string $liste, string $wort, bool $richtig): void
{
    $spalte = $richtig ? 'richtig' : 'falsch';
    $pdo->prepare("
        INSERT INTO klassen_wort (klasse, liste, wort, richtig, falsch)
        VALUES (:k, :l, :w, :r, :f)
        ON CONFLICT (klasse, liste, wort)
        DO UPDATE SET {$spalte} = {$spalte} + 1
    ")->execute([
        ':k' => $klasse, ':l' => $liste, ':w' => $wort,
        ':r' => $richtig ? 1 : 0, ':f' => $richtig ? 0 : 1,
    ]);
}

/**
 * Übersicht für die Lehrkraft.
 *
 * Gibt `genug` zurück, solange die Mindestzahl an Beitritten nicht erreicht
 * ist. Dann bleiben alle Werte leer, und die Seite erklärt, warum.
 */
function wq_klasse_uebersicht(PDO $pdo, array $klasse): array
{
    wq_klassen_schema($pdo);
    $id = (int) $klasse['id'];
    $beitritte = (int) $klasse['beitritte'];
    $genug = $beitritte >= WQ_KLASSE_MINDEST;

    if (!$genug) {
        return ['genug' => false, 'beitritte' => $beitritte, 'geuebt' => 0,
                'richtig' => 0, 'quote' => null, 'durchlaeufe' => 0, 'schwer' => [], 'tage' => []];
    }

    $summe = static function (PDO $pdo, int $id, string $bereich): int {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(anzahl), 0) FROM klassen_taeglich WHERE klasse = :k AND bereich = :b');
        $stmt->execute([':k' => $id, ':b' => $bereich]);
        return (int) $stmt->fetchColumn();
    };

    $geuebt  = $summe($pdo, $id, 'fragen');
    $richtig = $summe($pdo, $id, 'treffer');
    $durch   = $summe($pdo, $id, 'durchlauf');

    $stmt = $pdo->prepare('
        SELECT liste, wort, richtig, falsch, (richtig + falsch) AS gesamt
        FROM klassen_wort
        WHERE klasse = :k AND (richtig + falsch) >= 3
        ORDER BY (CAST(falsch AS REAL) / (richtig + falsch)) DESC, gesamt DESC
        LIMIT 15
    ');
    $stmt->execute([':k' => $id]);
    $schwer = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT tag, SUM(anzahl) AS anzahl FROM klassen_taeglich
        WHERE klasse = :k AND bereich = "fragen"
        GROUP BY tag ORDER BY tag DESC LIMIT 14
    ');
    $stmt->execute([':k' => $id]);
    $tage = $stmt->fetchAll();

    return [
        'genug'       => true,
        'beitritte'   => $beitritte,
        'geuebt'      => $geuebt,
        'richtig'     => $richtig,
        'quote'       => $geuebt > 0 ? (int) round($richtig / $geuebt * 100) : null,
        'durchlaeufe' => $durch,
        'schwer'      => $schwer,
        'tage'        => array_reverse($tage),
    ];
}

/** Listen einer Klasse als Array. */
function wq_klasse_listen(array $klasse): array
{
    return array_values(array_filter(explode('|', (string) $klasse['listen'])));
}

/** Schliesst eine Klasse. Die Summen bleiben, neue Beitritte gibt es nicht. */
function wq_klasse_schliessen(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE klassen SET aktiv = 0 WHERE id = :id')->execute([':id' => $id]);
}

/** Löscht eine Klasse samt aller Summen. */
function wq_klasse_loeschen(PDO $pdo, int $id): void
{
    wq_klassen_schema($pdo);
    $pdo->prepare('DELETE FROM klassen_taeglich WHERE klasse = :k')->execute([':k' => $id]);
    $pdo->prepare('DELETE FROM klassen_wort WHERE klasse = :k')->execute([':k' => $id]);
    $pdo->prepare('DELETE FROM klassen WHERE id = :id')->execute([':id' => $id]);
}

/** Alle Klassen für das Admincenter. */
function wq_klassen_liste(PDO $pdo): array
{
    wq_klassen_schema($pdo);
    return $pdo->query('SELECT * FROM klassen ORDER BY id DESC LIMIT 200')->fetchAll();
}
