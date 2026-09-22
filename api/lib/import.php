<?php
/**
 * wordQUEST – Eingangsformate für Wortlisten (WQ-7.4b).
 *
 * Lehrkräfte schreiben kein JSON. Sie schicken Tabellen aus Excel, CSV-Dateien
 * oder kopieren zwei Spalten aus einem Dokument. Alles davon wird hier in die
 * interne Struktur überführt.
 *
 * Bewusst ohne fremde Bibliotheken: Das Projekt hat kein Composer, und die
 * Formate sind einfach genug. XLSX ist ein ZIP mit XML, das liest ZipArchive
 * plus SimpleXML ohne Zusatzpaket.
 *
 * Alles, was hier entsteht, läuft anschliessend durch dieselbe Prüfung wie
 * eine hochgeladene JSON-Datei. Dieser Baustein erzeugt also nur eine
 * Vorstufe, er umgeht nichts.
 */
declare(strict_types=1);

const WQ_IMPORT_MAX_ZEILEN = 2000;   // vor der Schemaprüfung, die auf 500 kürzt

/** Spaltenüberschriften, die wir erkennen. */
const WQ_SPALTEN = [
    'en'        => ['en', 'english', 'englisch', 'wort', 'word', 'vokabel', 'begriff'],
    'de'        => ['de', 'deutsch', 'german', 'übersetzung', 'uebersetzung', 'bedeutung'],
    'emoji'     => ['emoji', 'symbol', 'zeichen'],
    'cat'       => ['cat', 'kategorie', 'category', 'gruppe', 'thema'],
    'example'   => ['example', 'beispiel', 'beispielsatz', 'satz'],
    'exampleDe' => ['examplede', 'beispiel de', 'beispiel deutsch', 'satz deutsch'],
];

/** Erkennt das Format an Inhalt und Dateiname. */
function wq_format_erkennen(string $roh, string $dateiname = ''): string
{
    // XLSX ist ein ZIP und beginnt mit PK.
    if (str_starts_with($roh, "PK\x03\x04")) {
        return 'xlsx';
    }
    $anfang = ltrim(substr($roh, 0, 64));
    if ($anfang !== '' && ($anfang[0] === '{' || $anfang[0] === '[')) {
        return 'json';
    }
    $endung = strtolower(pathinfo($dateiname, PATHINFO_EXTENSION));
    if ($endung === 'json') {
        return 'json';
    }
    if (in_array($endung, ['xls'], true)) {
        return 'xls-alt';
    }
    return 'tabelle';   // CSV, TSV oder eingefügter Text
}

/** Ordnet eine Kopfzeile den bekannten Feldern zu. */
function wq_kopfzeile_zuordnen(array $zeile): ?array
{
    $zuordnung = [];
    foreach ($zeile as $i => $wert) {
        $norm = strtolower(trim((string) $wert));
        $norm = str_replace(["\u{00a0}", '_', '-'], ' ', $norm);
        $norm = trim(preg_replace('/\s+/', ' ', $norm) ?? '');
        if ($norm === '') {
            continue;
        }
        foreach (WQ_SPALTEN as $feld => $namen) {
            if (in_array($norm, $namen, true)) {
                $zuordnung[$feld] = $i;
                break;
            }
        }
    }
    // Nur brauchbar, wenn beide Pflichtspalten erkannt wurden.
    return (isset($zuordnung['en']) && isset($zuordnung['de'])) ? $zuordnung : null;
}

/**
 * Wandelt Tabellenzeilen in Wörter um.
 * Ohne erkannte Kopfzeile gilt: erste Spalte Englisch, zweite Deutsch.
 */
function wq_zeilen_zu_woertern(array $zeilen): array
{
    $zeilen = array_values(array_filter($zeilen, static function ($z) {
        foreach ($z as $wert) {
            if (trim((string) $wert) !== '') {
                return true;
            }
        }
        return false;
    }));
    if (!$zeilen) {
        return [];
    }

    $zuordnung = wq_kopfzeile_zuordnen($zeilen[0]);
    if ($zuordnung !== null) {
        array_shift($zeilen);
    } else {
        $zuordnung = ['en' => 0, 'de' => 1];
    }

    $woerter = [];
    foreach (array_slice($zeilen, 0, WQ_IMPORT_MAX_ZEILEN) as $z) {
        $hole = static function (string $feld) use ($z, $zuordnung): ?string {
            if (!isset($zuordnung[$feld])) {
                return null;
            }
            $wert = $z[$zuordnung[$feld]] ?? null;
            $wert = is_string($wert) ? trim($wert) : (is_scalar($wert) ? trim((string) $wert) : '');
            return $wert !== '' ? $wert : null;
        };
        $en = $hole('en');
        $de = $hole('de');
        if ($en === null || $de === null) {
            continue;
        }
        $eintrag = ['en' => $en, 'de' => $de];
        foreach (['emoji', 'cat', 'example', 'exampleDe'] as $feld) {
            $wert = $hole($feld);
            if ($wert !== null) {
                $eintrag[$feld] = $wert;
            }
        }
        $woerter[] = $eintrag;
    }
    return $woerter;
}

/**
 * Liest CSV, TSV oder eingefügten Text.
 * Das Trennzeichen wird an der ersten Zeile erraten, weil Excel im deutschen
 * Sprachraum Semikolon schreibt, englische Werkzeuge aber Komma.
 */
function wq_tabelle_lesen(string $roh): array
{
    // Byte Order Mark entfernen, Excel schreibt ihn gern.
    $roh = preg_replace('/^\x{FEFF}/u', '', $roh) ?? $roh;
    $roh = str_replace(["\r\n", "\r"], "\n", $roh);
    $zeilenRoh = array_values(array_filter(explode("\n", $roh), static fn($z) => trim($z) !== ''));
    if (!$zeilenRoh) {
        return [];
    }

    $erste = $zeilenRoh[0];
    $kandidaten = ["\t" => substr_count($erste, "\t"), ';' => substr_count($erste, ';'), ',' => substr_count($erste, ',')];
    arsort($kandidaten);
    $trenner = (string) array_key_first($kandidaten);
    if ($kandidaten[$trenner] === 0) {
        // Kein Trennzeichen: vielleicht "wort = bedeutung" oder "wort - bedeutung".
        $zeilen = [];
        foreach ($zeilenRoh as $z) {
            $teile = preg_split('/\s+[=–—-]\s+/u', trim($z), 2);
            if ($teile && count($teile) === 2) {
                $zeilen[] = $teile;
            }
        }
        return $zeilen;
    }

    $zeilen = [];
    foreach ($zeilenRoh as $z) {
        $felder = str_getcsv($z, $trenner, '"', '\\');
        $zeilen[] = array_map(static fn($f) => is_string($f) ? trim($f) : $f, $felder);
    }
    return $zeilen;
}

/** Liest die erste Tabelle einer XLSX-Datei. */
function wq_xlsx_lesen(string $pfad): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Diesem PHP fehlt die ZIP-Unterstützung, XLSX lässt sich nicht lesen. Bitte als CSV speichern.');
    }
    $zip = new ZipArchive();
    if ($zip->open($pfad) !== true) {
        throw new RuntimeException('Die XLSX-Datei liess sich nicht öffnen.');
    }
    try {
        // Gemeinsame Zeichenketten: XLSX legt Texte zentral ab.
        $texte = [];
        $sharedRoh = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedRoh !== false) {
            $shared = @simplexml_load_string($sharedRoh, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
            if ($shared) {
                foreach ($shared->si as $si) {
                    // Text kann in mehrere Abschnitte zerfallen.
                    $texte[] = trim(implode('', array_map('strval', $si->xpath('.//text()') ?: [])));
                }
            }
        }

        $blattRoh = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($blattRoh === false) {
            throw new RuntimeException('In der XLSX-Datei wurde kein Tabellenblatt gefunden.');
        }
        $blatt = @simplexml_load_string($blattRoh, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
        if (!$blatt) {
            throw new RuntimeException('Das Tabellenblatt liess sich nicht lesen.');
        }

        $zeilen = [];
        foreach ($blatt->sheetData->row as $row) {
            $zeile = [];
            foreach ($row->c as $c) {
                // Spaltenbuchstabe aus der Zellenkennung, damit leere Zellen
                // die Reihenfolge nicht verschieben.
                $kennung = (string) ($c['r'] ?? '');
                $spalte = preg_replace('/\d+/', '', $kennung) ?? '';
                $index = 0;
                foreach (str_split($spalte) as $buchstabe) {
                    $index = $index * 26 + (ord(strtoupper($buchstabe)) - 64);
                }
                $index = max(0, $index - 1);

                $typ = (string) ($c['t'] ?? '');
                if ($typ === 's') {
                    $wert = $texte[(int) $c->v] ?? '';
                } elseif ($typ === 'inlineStr') {
                    $wert = trim(implode('', array_map('strval', $c->xpath('.//text()') ?: [])));
                } else {
                    $wert = isset($c->v) ? (string) $c->v : '';
                }
                $zeile[$index] = trim($wert);
            }
            if ($zeile) {
                ksort($zeile);
                $zeilen[] = array_values(array_replace(array_fill(0, (int) max(array_keys($zeile)) + 1, ''), $zeile));
            }
            if (count($zeilen) > WQ_IMPORT_MAX_ZEILEN) {
                break;
            }
        }
        return $zeilen;
    } finally {
        $zip->close();
    }
}

/**
 * Führt alles zusammen: aus beliebigem Eingangsformat wird ein
 * Wortlisten-Array, das anschliessend die normale Prüfung durchläuft.
 *
 * @return array{ok: bool, fehler: string, daten: ?array, format: string}
 */
function wq_import(string $roh, string $dateiname, string $titel, ?string $tempPfad = null): array
{
    $format = wq_format_erkennen($roh, $dateiname);

    if ($format === 'json') {
        // JSON bringt seinen Titel selbst mit und geht direkt weiter.
        return ['ok' => true, 'daten' => null, 'fehler' => '', 'format' => 'json'];
    }
    if ($format === 'xls-alt') {
        return ['ok' => false, 'daten' => null, 'format' => 'xls-alt',
                'fehler' => 'Das alte Excel-Format (.xls) wird nicht gelesen. Bitte in Excel unter "Speichern unter" als .xlsx oder CSV sichern.'];
    }

    $titel = trim($titel);
    if ($titel === '') {
        return ['ok' => false, 'daten' => null, 'format' => $format,
                'fehler' => 'Für Tabellen und eingefügten Text wird ein Titel gebraucht, er steht ja nicht in der Datei.'];
    }

    try {
        $zeilen = $format === 'xlsx'
            ? wq_xlsx_lesen((string) $tempPfad)
            : wq_tabelle_lesen($roh);
    } catch (Throwable $e) {
        return ['ok' => false, 'daten' => null, 'format' => $format, 'fehler' => $e->getMessage()];
    }

    $woerter = wq_zeilen_zu_woertern($zeilen);
    if (!$woerter) {
        return ['ok' => false, 'daten' => null, 'format' => $format,
                'fehler' => 'Es liessen sich keine Wortpaare erkennen. Erwartet werden zwei Spalten: englisch und deutsch.'];
    }

    return [
        'ok' => true,
        'format' => $format,
        'fehler' => '',
        'daten' => ['title' => $titel, 'words' => $woerter],
    ];
}

/** Lesbarer Name eines erkannten Formats, für Rückmeldungen. */
function wq_format_name(string $format): string
{
    return [
        'json'    => 'JSON',
        'xlsx'    => 'Excel-Datei',
        'tabelle' => 'Tabelle',
        'xls-alt' => 'altes Excel-Format',
    ][$format] ?? $format;
}
