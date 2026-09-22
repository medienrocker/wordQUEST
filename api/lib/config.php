<?php
/**
 * wordQUEST – Grundeinstellungen der Serverseite.
 *
 * Für abweichende Server eine `config.local.php` daneben legen, die ein
 * Array mit denselben Schlüsseln zurückgibt. Sie überlagert diese Werte und
 * gehört NICHT ins Repo (siehe .gitignore).
 */
declare(strict_types=1);

// __DIR__ ist …/httpdocs/api/lib, drei Ebenen höher liegt das Vhost-Verzeichnis.
// Die Datenbank liegt damit als Geschwister von httpdocs und ist über HTTP
// nicht erreichbar. Das ist die wichtigste Einzelmaßnahme dieser Schicht.
$vhost = dirname(__DIR__, 3);

return [
    'data_dir'        => $vhost . '/private',
    'db_file'         => $vhost . '/private/wordquest.sqlite',
    'wordlists_dir'   => dirname(__DIR__, 2) . '/wordlists',

    // Obergrenzen für eine einzelne Anfrage an die Statistik.
    'max_body_bytes'  => 8192,
    'max_events'      => 50,
    'max_key_len'     => 120,

    // Fehlerprotokoll landet neben der Datenbank, nie im Docroot.
    'error_log'       => $vhost . '/private/php-error.log',

    /* Benachrichtigung bei neuen Einreichungen. Ohne Eintrag wird nichts
       verschickt, und das ist kein Fehlerfall: Der Zähler im Admincenter zeigt
       offene Einreichungen unabhängig davon. Zugangsdaten gehören in
       `config.local.php`, nie hierher, siehe mail.php. */
    'smtp'            => null,
];
