<?php
/**
 * wordQUEST – Bilder zu Vokabeln verwalten (WQ-7.5).
 *
 * Auch hier gilt der Grundsatz aus der Listenverwaltung: **Die hochgeladenen
 * Bytes werden nie ausgeliefert.** Das Bild wird mit GD neu erzeugt. Damit
 * verschwinden eingebettete Fremddaten, EXIF-Reste und Polyglot-Konstruktionen
 * restlos, unabhängig davon, was jemand hochlädt.
 *
 * SVG ist ausdrücklich nicht erlaubt. SVG ist ein Dokumentformat mit
 * Skriptfähigkeit und würde beim direkten Aufruf im Ursprung der App laufen.
 */
declare(strict_types=1);

const WQ_BILD_MAX_BYTES   = 6291456;   // 6 MB Eingang
const WQ_BILD_MAX_PIXEL   = 40000000;  // 40 Megapixel, gegen Dekompressionsbomben
const WQ_BILD_KANTE       = 256;       // Zielkante, passend zur Darstellung
const WQ_BILD_VERZEICHNIS = 'img/auto';

/** Erlaubte Eingangsformate, erkannt am Inhalt, nicht an der Endung. */
function wq_bild_typen(): array
{
    return [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif'  => 'imagecreatefromgif',
    ];
}

function wq_bild_verzeichnis(): string
{
    $config = wq_config();
    return dirname((string) $config['wordlists_dir']) . '/' . WQ_BILD_VERZEICHNIS;
}

/**
 * Nimmt eine hochgeladene Datei an und legt ein neu erzeugtes WebP ab.
 *
 * @return array{ok: bool, fehler?: string, datei?: string, url?: string}
 */
function wq_bild_aufnehmen(array $datei, string $wunschname): array
{
    if (($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'fehler' => 'Es wurde keine Datei übertragen.'];
    }
    if ((int) $datei['size'] > WQ_BILD_MAX_BYTES) {
        return ['ok' => false, 'fehler' => 'Das Bild ist größer als 6 MB.'];
    }
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
        return ['ok' => false, 'fehler' => 'Diesem PHP fehlt die Bildbibliothek GD mit WebP-Unterstützung.'];
    }

    $pfad = (string) $datei['tmp_name'];

    // Typ aus dem Inhalt lesen. Die Dateiendung sagt nichts darüber aus,
    // was wirklich in der Datei steht.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $typ = @$finfo->file($pfad);
    if ($typ === false) {
        return ['ok' => false, 'fehler' => 'Die hochgeladene Datei liess sich nicht lesen. Bitte noch einmal versuchen.'];
    }
    $erlaubt = wq_bild_typen();
    if (!isset($erlaubt[$typ])) {
        return ['ok' => false, 'fehler' => 'Nicht unterstützt: ' . $typ . '. Erlaubt sind JPEG, PNG, WebP und GIF. SVG ist bewusst ausgeschlossen.'];
    }

    $masse = @getimagesize($pfad);
    if (!$masse || $masse[0] < 1 || $masse[1] < 1) {
        return ['ok' => false, 'fehler' => 'Das ist kein lesbares Bild.'];
    }
    if ($masse[0] * $masse[1] > WQ_BILD_MAX_PIXEL) {
        return ['ok' => false, 'fehler' => 'Das Bild hat zu viele Bildpunkte.'];
    }

    $lader = $erlaubt[$typ];
    $quelle = @$lader($pfad);
    if (!$quelle) {
        return ['ok' => false, 'fehler' => 'Das Bild ließ sich nicht öffnen.'];
    }

    try {
        [$breite, $hoehe] = [imagesx($quelle), imagesy($quelle)];
        $faktor = min(WQ_BILD_KANTE / $breite, WQ_BILD_KANTE / $hoehe, 1.0);
        $neuBreite = max(1, (int) round($breite * $faktor));
        $neuHoehe  = max(1, (int) round($hoehe * $faktor));

        $ziel = imagecreatetruecolor($neuBreite, $neuHoehe);
        // Weißer Grund statt Transparenz: Die App zeigt Bilder auf hellen
        // Karten, und WebP mit Alpha wirkt dort schmutzig.
        $weiss = imagecolorallocate($ziel, 255, 255, 255);
        imagefilledrectangle($ziel, 0, 0, $neuBreite, $neuHoehe, $weiss);
        imagecopyresampled($ziel, $quelle, 0, 0, 0, 0, $neuBreite, $neuHoehe, $breite, $hoehe);

        $verzeichnis = wq_bild_verzeichnis();
        if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0775, true) && !is_dir($verzeichnis)) {
            return ['ok' => false, 'fehler' => 'Das Bildverzeichnis ließ sich nicht anlegen: ' . $verzeichnis];
        }
        if (!is_writable($verzeichnis)) {
            return ['ok' => false, 'fehler' => 'Das Bildverzeichnis ist nicht beschreibbar.'];
        }

        $name = wq_bild_dateiname($wunschname, $verzeichnis);
        $zielPfad = $verzeichnis . '/' . $name;
        $temp = $zielPfad . '.tmp';
        if (!imagewebp($ziel, $temp, 82) || !rename($temp, $zielPfad)) {
            @unlink($temp);
            return ['ok' => false, 'fehler' => 'Das Bild ließ sich nicht speichern.'];
        }
        @chmod($zielPfad, 0644);

        return ['ok' => true, 'datei' => $name, 'url' => WQ_BILD_VERZEICHNIS . '/' . $name];
    } finally {
        if (isset($ziel) && $ziel instanceof GdImage) {
            imagedestroy($ziel);
        }
        imagedestroy($quelle);
    }
}

/** Sicherer, eindeutiger Dateiname aus dem englischen Wort. */
function wq_bild_dateiname(string $wunsch, string $verzeichnis): string
{
    $basis = strtolower(trim($wunsch));
    $basis = strtr($basis, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $basis = trim(preg_replace('/[^a-z0-9]+/', '-', $basis) ?? '', '-');
    if ($basis === '') {
        $basis = 'bild';
    }
    $basis = substr($basis, 0, 40);
    $name = $basis . '.webp';
    $zaehler = 2;
    while (is_file($verzeichnis . '/' . $name)) {
        $name = $basis . '-' . $zaehler . '.webp';
        $zaehler++;
    }
    return $name;
}

/** Trägt eine Bild-Adresse bei einem Wort in einer Wortliste ein. */
function wq_bild_zuordnen(string $datei, string $wortEn, ?string $url): array
{
    $config = wq_config();
    $pfad = rtrim((string) $config['wordlists_dir'], '/') . '/' . basename($datei);
    if (!is_file($pfad)) {
        return ['ok' => false, 'fehler' => 'Wortliste nicht gefunden.'];
    }
    $daten = json_decode((string) file_get_contents($pfad), true);
    if (!is_array($daten) || !isset($daten['words']) || !is_array($daten['words'])) {
        return ['ok' => false, 'fehler' => 'Die Wortliste ließ sich nicht lesen.'];
    }

    $getroffen = false;
    foreach ($daten['words'] as &$w) {
        if (is_array($w) && isset($w['en']) && (string) $w['en'] === $wortEn) {
            if ($url === null) {
                unset($w['img']);
            } else {
                $w['img'] = $url;
            }
            $getroffen = true;
            break;
        }
    }
    unset($w);

    if (!$getroffen) {
        return ['ok' => false, 'fehler' => 'Das Wort steht nicht in dieser Liste.'];
    }

    $json = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return ['ok' => false, 'fehler' => 'Die Liste ließ sich nicht schreiben.'];
    }
    if (!wq_datei_ersetzen($pfad, $json)) {
        return ['ok' => false, 'fehler' => 'Das Schreiben ist fehlgeschlagen.'];
    }
    return ['ok' => true];
}

/** Wörter einer Liste, aufgeteilt nach vorhandener Visualisierung. */
function wq_woerter_mit_status(string $datei): array
{
    $config = wq_config();
    $pfad = rtrim((string) $config['wordlists_dir'], '/') . '/' . basename($datei);
    $daten = json_decode((string) @file_get_contents($pfad), true);
    if (!is_array($daten) || !isset($daten['words']) || !is_array($daten['words'])) {
        return [];
    }
    $out = [];
    foreach ($daten['words'] as $w) {
        if (!is_array($w) || !isset($w['en'], $w['de'])) {
            continue;
        }
        $out[] = [
            'en'    => (string) $w['en'],
            'de'    => (string) $w['de'],
            'emoji' => isset($w['emoji']) ? (string) $w['emoji'] : '',
            'img'   => isset($w['img']) ? (string) $w['img'] : '',
        ];
    }
    return $out;
}

/** Löscht eine hochgeladene Bilddatei, wenn sie nirgends mehr verwendet wird. */
function wq_bild_loeschen(string $dateiname): array
{
    $verzeichnis = wq_bild_verzeichnis();
    $name = basename($dateiname);
    $pfad = $verzeichnis . '/' . $name;
    if (!is_file($pfad)) {
        return ['ok' => false, 'fehler' => 'Diese Datei gibt es nicht.'];
    }

    // Verwendungsnachweis: Erst löschen, wenn keine Liste mehr darauf zeigt.
    $config = wq_config();
    $url = WQ_BILD_VERZEICHNIS . '/' . $name;
    foreach (glob(rtrim((string) $config['wordlists_dir'], '/') . '/*.json') ?: [] as $liste) {
        if (basename($liste) === 'index.json') {
            continue;
        }
        if (str_contains((string) @file_get_contents($liste), $url)) {
            return ['ok' => false, 'fehler' => 'Das Bild wird noch in ' . basename($liste) . ' verwendet.'];
        }
    }

    return @unlink($pfad)
        ? ['ok' => true]
        : ['ok' => false, 'fehler' => 'Die Datei ließ sich nicht löschen.'];
}

/** Alle abgelegten Bilder. */
function wq_bilder_liste(): array
{
    $verzeichnis = wq_bild_verzeichnis();
    $out = [];
    foreach (glob($verzeichnis . '/*.webp') ?: [] as $pfad) {
        $out[] = [
            'datei'   => basename($pfad),
            'url'     => WQ_BILD_VERZEICHNIS . '/' . basename($pfad),
            'groesse' => (int) @filesize($pfad),
        ];
    }
    usort($out, static fn($a, $b) => strcasecmp($a['datei'], $b['datei']));
    return $out;
}
