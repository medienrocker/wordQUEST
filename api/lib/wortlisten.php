<?php
/**
 * wordQUEST – Wortlisten prüfen, einreichen, freigeben (WQ-7.4).
 *
 * Kernentscheidung für die Sicherheit: **Die hochgeladenen Bytes werden nie
 * veröffentlicht.** Geprüft wird die Struktur, und veröffentlicht wird eine
 * frisch aus den geprüften Werten erzeugte Datei. Damit sind Polyglot-Dateien,
 * eingebetteter Code und unerwartete Felder ausgeschlossen, unabhängig davon,
 * was jemand hochlädt.
 *
 * Einreichungen liegen in der Datenbank, nicht im Dateisystem. Das erspart
 * Dateirechte, Pfadprüfungen und Aufräumarbeit und ist atomar.
 */
declare(strict_types=1);

const WQ_LISTE_MAX_BYTES     = 524288;   // 512 KB
const WQ_LISTE_MAX_WOERTER   = 500;
const WQ_LISTE_MAX_WORTLEN   = 120;
const WQ_LISTE_MAX_BEISPIEL  = 200;
const WQ_LISTE_MAX_TITEL     = 120;
const WQ_LISTE_MAX_BESCHR    = 300;
const WQ_LISTE_MAX_KATEGORIEN = 40;
const WQ_LISTE_MAX_EMOJI     = 16;

/* Dieselbe Positivliste wie im Client. Eine fremde Bild-URL waere ein
   Zaehlpixel auf dem Geraet jedes Kindes. */
const WQ_BILD_HOSTS = ['img.bildungssprit.de'];

function wq_einreichungen_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS einreichungen (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            originalname   TEXT,
            inhalt         TEXT    NOT NULL,
            titel          TEXT,
            anzahl_woerter INTEGER NOT NULL DEFAULT 0,
            status         TEXT    NOT NULL DEFAULT "neu",
            eingereicht_am TEXT    NOT NULL,
            bearbeitet_am  TEXT,
            bearbeitet_von TEXT,
            zieldatei      TEXT
        )
    ');
}

/** Prüft eine Bild-URL wie der Client: nur HTTPS, nur bekannte Hosts. */
function wq_bild_url_ok(string $url): bool
{
    $teile = parse_url(trim($url));
    if (!$teile || ($teile['scheme'] ?? '') !== 'https' || empty($teile['host'])) {
        return false;
    }
    return in_array(strtolower($teile['host']), WQ_BILD_HOSTS, true);
}

function wq_text_ok($wert, int $max): bool
{
    return is_string($wert) && trim($wert) !== '' && mb_strlen($wert) <= $max;
}

/**
 * Prüft rohen JSON-Text gegen das Wortlistenschema.
 *
 * Liefert ['ok' => bool, 'fehler' => string[], 'hinweise' => string[],
 *          'daten' => array|null]
 * `daten` ist die bereinigte Struktur, aus der später veröffentlicht wird.
 */
function wq_wortliste_pruefen(string $roh): array
{
    $fehler = [];
    $hinweise = [];

    if (strlen($roh) > WQ_LISTE_MAX_BYTES) {
        return ['ok' => false, 'fehler' => ['Die Datei ist größer als 512 KB.'], 'hinweise' => [], 'daten' => null];
    }
    if (trim($roh) === '') {
        return ['ok' => false, 'fehler' => ['Die Datei ist leer.'], 'hinweise' => [], 'daten' => null];
    }

    try {
        // Tiefe begrenzen: Eine tief verschachtelte Datei soll den Parser
        // nicht beschäftigen können.
        $eingabe = json_decode($roh, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['ok' => false, 'fehler' => ['Das ist kein gültiges JSON: ' . $e->getMessage()], 'hinweise' => [], 'daten' => null];
    }
    if (!is_array($eingabe)) {
        return ['ok' => false, 'fehler' => ['Die oberste Ebene muss ein Objekt sein.'], 'hinweise' => [], 'daten' => null];
    }

    $sauber = [];

    // Titel und Beschreibung
    if (isset($eingabe['title']) && wq_text_ok($eingabe['title'], WQ_LISTE_MAX_TITEL)) {
        $sauber['title'] = trim((string) $eingabe['title']);
    } else {
        $fehler[] = 'Es fehlt ein Titel (Feld "title", höchstens ' . WQ_LISTE_MAX_TITEL . ' Zeichen).';
    }
    if (isset($eingabe['description'])) {
        if (wq_text_ok($eingabe['description'], WQ_LISTE_MAX_BESCHR)) {
            $sauber['description'] = trim((string) $eingabe['description']);
        } else {
            $hinweise[] = 'Die Beschreibung wurde verworfen, sie ist leer oder zu lang.';
        }
    }

    // Kategorien
    $kategorien = [];
    if (isset($eingabe['categories'])) {
        if (!is_array($eingabe['categories'])) {
            $hinweise[] = 'Das Feld "categories" wurde verworfen, es ist kein Objekt.';
        } else {
            foreach ($eingabe['categories'] as $schluessel => $beschriftung) {
                if (count($kategorien) >= WQ_LISTE_MAX_KATEGORIEN) {
                    $hinweise[] = 'Es werden höchstens ' . WQ_LISTE_MAX_KATEGORIEN . ' Kategorien übernommen.';
                    break;
                }
                if (is_string($schluessel) && wq_text_ok($beschriftung, 60)) {
                    $kategorien[trim($schluessel)] = trim((string) $beschriftung);
                }
            }
        }
    }
    if ($kategorien) {
        $sauber['categories'] = $kategorien;
    }

    // Wörter
    if (!isset($eingabe['words']) || !is_array($eingabe['words']) || !$eingabe['words']) {
        $fehler[] = 'Es fehlt die Liste "words" mit mindestens einem Eintrag.';
        return ['ok' => false, 'fehler' => $fehler, 'hinweise' => $hinweise, 'daten' => null];
    }
    if (count($eingabe['words']) > WQ_LISTE_MAX_WOERTER) {
        $hinweise[] = 'Die Liste wurde auf ' . WQ_LISTE_MAX_WOERTER . ' Wörter gekürzt.';
    }

    $woerter = [];
    $verworfen = 0;
    foreach (array_slice($eingabe['words'], 0, WQ_LISTE_MAX_WOERTER) as $w) {
        if (!is_array($w) || !wq_text_ok($w['en'] ?? null, WQ_LISTE_MAX_WORTLEN) || !wq_text_ok($w['de'] ?? null, WQ_LISTE_MAX_WORTLEN)) {
            $verworfen++;
            continue;
        }
        $eintrag = ['en' => trim((string) $w['en']), 'de' => trim((string) $w['de'])];

        if (isset($w['emoji']) && wq_text_ok($w['emoji'], WQ_LISTE_MAX_EMOJI)) {
            $eintrag['emoji'] = trim((string) $w['emoji']);
        }
        if (isset($w['img']) && is_string($w['img']) && trim($w['img']) !== '') {
            if (wq_bild_url_ok($w['img'])) {
                $eintrag['img'] = trim((string) $w['img']);
            } else {
                $hinweise[] = 'Bild-Adresse bei "' . $eintrag['en'] . '" verworfen: nur HTTPS und ' . implode(', ', WQ_BILD_HOSTS) . ' sind erlaubt.';
            }
        }
        if (isset($w['cat']) && wq_text_ok($w['cat'], 60)) {
            $eintrag['cat'] = trim((string) $w['cat']);
        }
        if (isset($w['example']) && wq_text_ok($w['example'], WQ_LISTE_MAX_BEISPIEL)) {
            $eintrag['example'] = trim((string) $w['example']);
        }
        if (isset($w['exampleDe']) && wq_text_ok($w['exampleDe'], WQ_LISTE_MAX_BEISPIEL)) {
            $eintrag['exampleDe'] = trim((string) $w['exampleDe']);
        }
        $woerter[] = $eintrag;
    }

    if ($verworfen > 0) {
        $hinweise[] = $verworfen . ' Einträge wurden verworfen, weil "en" oder "de" fehlt oder zu lang ist.';
    }
    if (!$woerter) {
        $fehler[] = 'Kein einziger Eintrag war gültig.';
    }
    if (count($woerter) < 4) {
        $hinweise[] = 'Weniger als vier Wörter: Das Quiz zeigt dann weniger Antwortmöglichkeiten.';
    }

    $sauber['words'] = $woerter;

    return [
        'ok'       => !$fehler,
        'fehler'   => $fehler,
        'hinweise' => array_values(array_unique($hinweise)),
        'daten'    => $fehler ? null : $sauber,
    ];
}

/** Erzeugt aus dem Titel einen sicheren Dateinamen. */
function wq_dateiname_aus_titel(string $titel, string $verzeichnis): string
{
    $basis = strtolower(trim($titel));
    $ersetzungen = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
    $basis = strtr($basis, $ersetzungen);
    $basis = preg_replace('/[^a-z0-9]+/', '-', $basis) ?? '';
    $basis = trim($basis, '-');
    if ($basis === '' || strlen($basis) > 40) {
        $basis = substr($basis, 0, 40) ?: 'liste';
        $basis = trim($basis, '-') ?: 'liste';
    }
    $name = $basis . '.json';
    $zaehler = 2;
    while (is_file($verzeichnis . '/' . $name)) {
        $name = $basis . '-' . $zaehler . '.json';
        $zaehler++;
    }
    return $name;
}

/** Einreichung speichern. */
function wq_einreichung_speichern(PDO $pdo, string $roh, ?string $originalname, array $geprueft): int
{
    wq_einreichungen_schema($pdo);
    $stmt = $pdo->prepare('
        INSERT INTO einreichungen (originalname, inhalt, titel, anzahl_woerter, status, eingereicht_am)
        VALUES (:o, :i, :t, :n, "neu", :d)
    ');
    $stmt->execute([
        ':o' => $originalname !== null ? mb_substr($originalname, 0, 120) : null,
        ':i' => $roh,
        ':t' => $geprueft['daten']['title'] ?? null,
        ':n' => count($geprueft['daten']['words'] ?? []),
        ':d' => gmdate('Y-m-d H:i:s'),
    ]);
    return (int) $pdo->lastInsertId();
}

function wq_einreichung_holen(PDO $pdo, int $id): ?array
{
    wq_einreichungen_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM einreichungen WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Gibt eine Einreichung frei.
 *
 * Geschrieben wird NICHT der hochgeladene Text, sondern eine neu erzeugte
 * Datei aus den geprüften Werten. Das ist der Kern der Absicherung.
 */
function wq_einreichung_veroeffentlichen(PDO $pdo, int $id, string $von): array
{
    $eintrag = wq_einreichung_holen($pdo, $id);
    if (!$eintrag) {
        return ['ok' => false, 'fehler' => 'Einreichung nicht gefunden.'];
    }
    if ($eintrag['status'] === 'veroeffentlicht') {
        return ['ok' => false, 'fehler' => 'Diese Einreichung ist bereits veröffentlicht.'];
    }

    $geprueft = wq_wortliste_pruefen((string) $eintrag['inhalt']);
    if (!$geprueft['ok']) {
        return ['ok' => false, 'fehler' => 'Die Datei ist nicht gültig: ' . implode(' ', $geprueft['fehler'])];
    }

    $config = wq_config();
    $verzeichnis = rtrim((string) $config['wordlists_dir'], '/');
    if (!is_dir($verzeichnis) || !is_writable($verzeichnis)) {
        return ['ok' => false, 'fehler' => 'Das Wortlistenverzeichnis ist nicht beschreibbar.'];
    }

    $name = wq_dateiname_aus_titel((string) ($geprueft['daten']['title'] ?? 'liste'), $verzeichnis);
    $json = json_encode($geprueft['daten'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return ['ok' => false, 'fehler' => 'Die Datei ließ sich nicht erzeugen.'];
    }

    // Erst in eine Nebendatei schreiben, dann umbenennen: So sieht die App
    // nie eine halb geschriebene Liste.
    $ziel = $verzeichnis . '/' . $name;
    $temp = $ziel . '.tmp';
    if (file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $ziel)) {
        @unlink($temp);
        return ['ok' => false, 'fehler' => 'Das Schreiben ist fehlgeschlagen.'];
    }
    @chmod($ziel, 0644);

    $pdo->prepare('UPDATE einreichungen SET status = "veroeffentlicht", bearbeitet_am = :d, bearbeitet_von = :v, zieldatei = :z WHERE id = :id')
        ->execute([':d' => gmdate('Y-m-d H:i:s'), ':v' => $von, ':z' => $name, ':id' => $id]);

    return ['ok' => true, 'datei' => $name];
}

function wq_einreichung_ablehnen(PDO $pdo, int $id, string $von): void
{
    $pdo->prepare('UPDATE einreichungen SET status = "abgelehnt", bearbeitet_am = :d, bearbeitet_von = :v WHERE id = :id')
        ->execute([':d' => gmdate('Y-m-d H:i:s'), ':v' => $von, ':id' => $id]);
}

/** Alle veröffentlichten Listen im Wortlistenverzeichnis. */
function wq_vorhandene_listen(): array
{
    $config = wq_config();
    $verzeichnis = rtrim((string) $config['wordlists_dir'], '/');
    $treffer = glob($verzeichnis . '/*.json') ?: [];
    $listen = [];
    foreach ($treffer as $pfad) {
        $name = basename($pfad);
        if ($name === 'index.json') {
            continue;
        }
        $daten = json_decode((string) @file_get_contents($pfad), true);
        $listen[] = [
            'datei'  => $name,
            'titel'  => is_array($daten) && isset($daten['title']) && is_string($daten['title'])
                        ? $daten['title'] : pathinfo($name, PATHINFO_FILENAME),
            'anzahl' => is_array($daten) && isset($daten['words']) && is_array($daten['words'])
                        ? count($daten['words']) : 0,
            'groesse' => (int) @filesize($pfad),
            'geaendert' => @filemtime($pfad) ? gmdate('Y-m-d H:i', (int) filemtime($pfad)) : '',
        ];
    }
    usort($listen, static fn($a, $b) => strcasecmp((string) $a['titel'], (string) $b['titel']));
    return $listen;
}
