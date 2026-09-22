<?php
/**
 * wordQUEST – Schalter, die der Betrieb umlegen können muss.
 *
 * Bewusst in der Datenbank und nicht in `config.php`: Wer das Admincenter
 * bedient, soll die Ehrentafel abschalten können, ohne eine Datei auf dem
 * Server zu bearbeiten.
 */
declare(strict_types=1);

function wq_einstellungen_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS einstellungen (
            schluessel TEXT PRIMARY KEY,
            wert       TEXT NOT NULL
        )
    ');
}

function wq_einstellung(string $schluessel, string $standard = ''): string
{
    static $zwischenspeicher = [];
    if (array_key_exists($schluessel, $zwischenspeicher)) {
        return $zwischenspeicher[$schluessel];
    }
    try {
        $pdo = wq_db();
        wq_einstellungen_schema($pdo);
        $stmt = $pdo->prepare('SELECT wert FROM einstellungen WHERE schluessel = :s');
        $stmt->execute([':s' => $schluessel]);
        $wert = $stmt->fetchColumn();
    } catch (Throwable $e) {
        // Ein Schalter darf nie die Seite mitreissen.
        return $standard;
    }
    return $zwischenspeicher[$schluessel] = ($wert === false ? $standard : (string) $wert);
}

function wq_einstellung_setzen(string $schluessel, string $wert): void
{
    $pdo = wq_db();
    wq_einstellungen_schema($pdo);
    $pdo->prepare('
        INSERT INTO einstellungen (schluessel, wert) VALUES (:s, :w)
        ON CONFLICT (schluessel) DO UPDATE SET wert = :w
    ')->execute([':s' => $schluessel, ':w' => $wert]);
}

function wq_schalter(string $schluessel, bool $standard = true): bool
{
    return wq_einstellung($schluessel, $standard ? '1' : '0') === '1';
}
