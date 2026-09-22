<?php
/**
 * wordQUEST – Fassungen, Archiv und Löschen von Wortlisten.
 *
 * Zwei Sicherheitsnetze, die zusammengehören:
 *
 * **Fassungen.** Vor jeder Änderung an einer veröffentlichten Liste wird der
 * bisherige Stand weggeschrieben. Ein versehentlich entferntes Wort oder eine
 * gelöschte Kategorie ist damit kein Unglück mehr, sondern ein Klick. Auch das
 * Zurückholen legt vorher eine Fassung an, ist also selbst wieder umkehrbar.
 *
 * **Archiv.** Eine Liste verschwindet aus der App, ohne dass die Datei
 * angefasst wird. Vermerkt wird nur der Dateiname in einer Datei neben der
 * Datenbank. Das ist mit Absicht so gebaut: Das Auslieferungsverzeichnis ist
 * zugleich ein Git-Arbeitsverzeichnis, und je weniger der Server dort bewegt,
 * desto ruhiger läuft der nächste Deploy.
 *
 * Alles liegt unter `private/`, also ausserhalb des Docroots und in der
 * Sicherung.
 */
declare(strict_types=1);

const WQ_VERSIONEN_MAX = 12;

function wq_privat(string $unterordner): string
{
    $config = wq_config();
    $pfad = rtrim((string) $config['data_dir'], '/') . '/' . $unterordner;
    if (!is_dir($pfad)) {
        @mkdir($pfad, 0770, true);
    }
    return $pfad;
}

/* ─────────────── Frühere Fassungen ─────────────── */

/** Ordnername einer Liste im Fassungsarchiv. */
function wq_versionen_ordner(string $datei): string
{
    $name = preg_replace('/[^a-z0-9_-]/', '', strtolower(pathinfo(basename($datei), PATHINFO_FILENAME))) ?? '';
    return wq_privat('versionen') . '/' . ($name !== '' ? $name : 'liste');
}

/**
 * Schreibt den aktuellen Stand einer Liste weg, bevor er überschrieben wird.
 * Ältere Fassungen über der Obergrenze fallen weg, damit nichts unbegrenzt
 * wächst.
 */
function wq_version_sichern(string $datei): void
{
    $config = wq_config();
    $quelle = rtrim((string) $config['wordlists_dir'], '/') . '/' . basename($datei);
    if (!is_file($quelle)) {
        return;
    }
    $ordner = wq_versionen_ordner($datei);
    if (!is_dir($ordner) && !@mkdir($ordner, 0770, true) && !is_dir($ordner)) {
        return;
    }
    $ziel = $ordner . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
    @copy($quelle, $ziel);
    @chmod($ziel, 0640);

    $vorhanden = glob($ordner . '/*.json') ?: [];
    if (count($vorhanden) > WQ_VERSIONEN_MAX) {
        sort($vorhanden);   // Dateinamen beginnen mit dem Zeitstempel
        foreach (array_slice($vorhanden, 0, count($vorhanden) - WQ_VERSIONEN_MAX) as $alt) {
            @unlink($alt);
        }
    }
}

/** Frühere Fassungen einer Liste, die neueste zuerst. */
function wq_versionen(string $datei): array
{
    $ordner = wq_versionen_ordner($datei);
    $treffer = glob($ordner . '/*.json') ?: [];
    rsort($treffer);
    $liste = [];
    foreach ($treffer as $pfad) {
        $daten = json_decode((string) @file_get_contents($pfad), true);
        $name = basename($pfad);
        $liste[] = [
            'marke'  => $name,
            'zeit'   => wq_version_zeit($name),
            'titel'  => is_array($daten) && isset($daten['title']) ? (string) $daten['title'] : '',
            'anzahl' => is_array($daten) && isset($daten['words']) && is_array($daten['words'])
                        ? count($daten['words']) : 0,
        ];
    }
    return $liste;
}

/** Macht aus "20260922-184501-a1b2c3.json" eine lesbare Zeitangabe. */
function wq_version_zeit(string $name): string
{
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', $name, $t)) {
        return $name;
    }
    return $t[3] . '.' . $t[2] . '.' . $t[1] . ', ' . $t[4] . ':' . $t[5] . ' Uhr';
}

/**
 * Holt eine frühere Fassung zurück.
 *
 * Der aktuelle Stand wird dabei selbst zur Fassung. Ein versehentliches
 * Zurückholen lässt sich also genauso rückgängig machen.
 *
 * @return array{ok: bool, fehler?: string[], anzahl?: int}
 */
function wq_version_zurueckholen(string $datei, string $marke): array
{
    $marke = basename($marke);
    if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}\.json$/', $marke)) {
        return ['ok' => false, 'fehler' => ['Unbekannte Fassung.']];
    }
    $pfad = wq_versionen_ordner($datei) . '/' . $marke;
    if (!is_file($pfad)) {
        return ['ok' => false, 'fehler' => ['Diese Fassung gibt es nicht mehr.']];
    }
    $daten = json_decode((string) @file_get_contents($pfad), true);
    if (!is_array($daten)) {
        return ['ok' => false, 'fehler' => ['Diese Fassung liess sich nicht lesen.']];
    }
    // Läuft durch dieselbe Prüfung und legt vorher eine Fassung des
    // aktuellen Standes an.
    return wq_wortliste_speichern($datei, $daten);
}

/* ─────────────── Archiv ─────────────── */

function wq_archiv_datei(): string
{
    return wq_privat('archiv') . '/archiv.json';
}

/** Dateinamen aller archivierten Listen. */
function wq_archivierte(): array
{
    $pfad = wq_archiv_datei();
    if (!is_file($pfad)) {
        return [];
    }
    $daten = json_decode((string) @file_get_contents($pfad), true);
    if (!is_array($daten)) {
        return [];
    }
    $namen = [];
    foreach ($daten as $name) {
        if (is_string($name) && $name !== '') {
            $namen[] = basename($name);
        }
    }
    return array_values(array_unique($namen));
}

function wq_archiv_schreiben(array $namen): bool
{
    $pfad = wq_archiv_datei();
    $json = json_encode(array_values(array_unique($namen)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return wq_datei_ersetzen($pfad, $json);
}

/** @return array{ok: bool, fehler?: string} */
function wq_liste_archivieren(string $datei): array
{
    $datei = basename($datei);
    $config = wq_config();
    if (!is_file(rtrim((string) $config['wordlists_dir'], '/') . '/' . $datei)) {
        return ['ok' => false, 'fehler' => 'Diese Liste gibt es nicht.'];
    }
    $namen = wq_archivierte();
    if (in_array($datei, $namen, true)) {
        return ['ok' => false, 'fehler' => 'Diese Liste liegt bereits im Archiv.'];
    }
    $namen[] = $datei;
    return wq_archiv_schreiben($namen)
        ? ['ok' => true]
        : ['ok' => false, 'fehler' => 'Das Archiv liess sich nicht schreiben.'];
}

/** @return array{ok: bool, fehler?: string} */
function wq_liste_entarchivieren(string $datei): array
{
    $datei = basename($datei);
    $namen = array_values(array_filter(wq_archivierte(), static fn($n) => $n !== $datei));
    return wq_archiv_schreiben($namen)
        ? ['ok' => true]
        : ['ok' => false, 'fehler' => 'Das Archiv liess sich nicht schreiben.'];
}

/**
 * Entfernt eine archivierte Liste aus dem Auslieferungsverzeichnis.
 *
 * Gelöscht wird nichts: Die Datei wandert nach `private/archiv/dateien/`.
 * Eine Wortliste ist Arbeit von Menschen, die soll ein Fehlklick nicht
 * vernichten können. Aufgeräumt wird dort von Hand, mit Blick auf die Datei.
 *
 * @return array{ok: bool, fehler?: string, abgelegt?: string}
 */
function wq_liste_endgueltig_entfernen(string $datei): array
{
    $datei = basename($datei);
    if (!in_array($datei, wq_archivierte(), true)) {
        return ['ok' => false, 'fehler' => 'Nur archivierte Listen lassen sich entfernen.'];
    }
    $config = wq_config();
    $quelle = rtrim((string) $config['wordlists_dir'], '/') . '/' . $datei;
    if (!is_file($quelle)) {
        // Datei ist schon weg, dann nur noch den Vermerk aufräumen.
        wq_liste_entarchivieren($datei);
        return ['ok' => true, 'abgelegt' => ''];
    }

    $ordner = wq_privat('archiv/dateien');
    $ziel = $ordner . '/' . gmdate('Ymd-His') . '-' . $datei;
    if (!@copy($quelle, $ziel)) {
        return ['ok' => false, 'fehler' => 'Die Sicherung im Archiv ist fehlgeschlagen, es wurde nichts entfernt.'];
    }
    @chmod($ziel, 0640);
    if (!@unlink($quelle)) {
        return ['ok' => false, 'fehler' => 'Die Datei liess sich nicht entfernen.'];
    }
    wq_liste_entarchivieren($datei);
    return ['ok' => true, 'abgelegt' => basename($ziel)];
}

/** Abgelegte Dateien im Archiv, für die Anzeige im Admincenter. */
function wq_archiv_dateien(): array
{
    $ordner = wq_privat('archiv/dateien');
    $treffer = glob($ordner . '/*.json') ?: [];
    rsort($treffer);
    $liste = [];
    foreach ($treffer as $pfad) {
        $daten = json_decode((string) @file_get_contents($pfad), true);
        $liste[] = [
            'datei'  => basename($pfad),
            'titel'  => is_array($daten) && isset($daten['title']) ? (string) $daten['title'] : '',
            'anzahl' => is_array($daten) && isset($daten['words']) && is_array($daten['words'])
                        ? count($daten['words']) : 0,
        ];
    }
    return $liste;
}
