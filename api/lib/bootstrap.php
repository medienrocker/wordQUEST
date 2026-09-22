<?php
/**
 * wordQUEST – gemeinsamer Einstieg für alle API-Endpunkte.
 *
 * Aufgaben: Konfiguration laden, Fehler still protokollieren statt anzeigen,
 * Sicherheits-Header setzen, JSON-Antworten vereinheitlichen.
 */
declare(strict_types=1);

const WQ_MAX_ERROR_LOG_BYTES = 1048576;   // 1 MB, danach wird rotiert

function wq_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = require __DIR__ . '/config.php';
    $lokal = __DIR__ . '/config.local.php';
    if (is_file($lokal)) {
        $ueberlagerung = require $lokal;
        if (is_array($ueberlagerung)) {
            $config = array_merge($config, $ueberlagerung);
        }
    }
    return $config;
}

/**
 * Antwortet als JSON und beendet das Skript.
 * Auch im Fehlerfall immer sauberes JSON, nie ein PHP-Stacktrace.
 */
function wq_json(array $daten, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // Der Client ruft gleichursprünglich auf, deshalb bewusst kein CORS.
    }
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wq_fehler(string $meldung, int $status = 400): void
{
    wq_json(['ok' => false, 'fehler' => $meldung], $status);
}

/**
 * Liest den Anfragekörper mit harter Größengrenze.
 * Ohne Grenze könnte ein einzelner Aufruf den Speicher fluten.
 */
function wq_body_json(): array
{
    $config = wq_config();
    $max = (int) $config['max_body_bytes'];

    $laenge = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($laenge > $max) {
        wq_fehler('Anfrage zu groß.', 413);
    }

    $roh = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($roh === false || $roh === '') {
        return [];
    }
    if (strlen($roh) > $max) {
        wq_fehler('Anfrage zu groß.', 413);
    }

    try {
        $daten = json_decode($roh, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        wq_fehler('Ungültiges JSON.');
    }
    return is_array($daten) ? $daten : [];
}

function wq_verlange_methode(string $methode): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $methode) {
        wq_fehler('Methode nicht erlaubt.', 405);
    }
}

/* ─── Fehlerbehandlung ───
   Anzeigen ist aus, protokolliert wird außerhalb des Docroots. Eine
   unbehandelte Ausnahme darf nie Pfade oder Quelltext preisgeben. */
$wqConfig = wq_config();

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
if (!empty($wqConfig['error_log'])) {
    $logDatei = (string) $wqConfig['error_log'];
    if (is_file($logDatei) && filesize($logDatei) > WQ_MAX_ERROR_LOG_BYTES) {
        @rename($logDatei, $logDatei . '.1');
    }
    @ini_set('error_log', $logDatei);
}

set_exception_handler(static function (Throwable $e): void {
    error_log('wordQUEST: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    wq_json(['ok' => false, 'fehler' => 'Interner Fehler.'], 500);
});

register_shutdown_function(static function (): void {
    $letzter = error_get_last();
    if ($letzter && in_array($letzter['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'fehler' => 'Interner Fehler.']);
        }
    }
});
