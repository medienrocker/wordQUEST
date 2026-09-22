<?php
/**
 * wordQUEST – Benachrichtigung über authentifiziertes SMTP (WQ-8.2).
 *
 * Bewusst **nicht** über `mail()`. Die Funktion übergibt an ein lokales
 * Sendeprogramm, dessen Absenderadresse nicht zur Domain passt, landet damit
 * zuverlässig im Spamordner und liefert im Fehlerfall nichts Brauchbares
 * zurück. Ein SMTP-Versand mit Anmeldung ist nachvollziehbar und scheitert
 * laut.
 *
 * Ebenfalls bewusst ohne fremde Bibliothek: Das Projekt hat kein Composer, und
 * eine Nachricht ohne Anhang ist überschaubar.
 *
 * **Der Versand darf nie etwas kaputt machen.** Eine Einreichung ist auch dann
 * gespeichert, wenn keine Mail hinausgeht. Deshalb gibt jede Funktion hier nur
 * ein Ergebnis zurück und wirft nichts nach oben.
 *
 * Zugangsdaten gehören in `config.local.php`, nie ins Repo:
 *
 *     'smtp' => [
 *         'host' => 'smtp.example.org',
 *         'port' => 465,
 *         'sicherheit' => 'tls',      // tls (Standard) oder starttls
 *         'benutzer' => '…',
 *         'passwort' => '…',
 *         'von' => 'wordquest@example.org',
 *         'von_name' => 'wordQUEST',
 *         'an' => 'betreiber@example.org',
 *     ],
 */
declare(strict_types=1);

const WQ_SMTP_TIMEOUT = 15;

/** Einstellungen, ergänzt um die Standardwerte. Null, wenn nicht eingerichtet. */
function wq_smtp_einstellungen(): ?array
{
    $config = wq_config();
    $smtp = $config['smtp'] ?? null;
    if (!is_array($smtp)) {
        return null;
    }
    $smtp += [
        'port'               => 465,
        'sicherheit'         => 'tls',
        'von_name'           => 'wordQUEST',
        'zertifikat_pruefen' => true,
    ];
    foreach (['host', 'benutzer', 'passwort', 'von', 'an'] as $pflicht) {
        if (empty($smtp[$pflicht])) {
            return null;
        }
    }
    return $smtp;
}

/**
 * Entfernt alles, was einen Kopfzeilen-Einschub erlauben würde.
 *
 * Betreff und Adressen landen in Kopfzeilen. Ein Zeilenumbruch darin wäre der
 * klassische Weg, eigene Empfänger anzuhängen.
 */
function wq_kopfzeile_sicher(string $wert): string
{
    return trim((string) preg_replace('/[\r\n\x00]+/', ' ', $wert));
}

/** Betreff nach RFC 2047, damit Umlaute ankommen. */
function wq_betreff_kodieren(string $betreff): string
{
    $betreff = wq_kopfzeile_sicher($betreff);
    if (preg_match('/^[\x20-\x7E]*$/', $betreff)) {
        return $betreff;
    }
    return '=?UTF-8?B?' . base64_encode($betreff) . '?=';
}

/**
 * Führt eine SMTP-Unterhaltung und verschickt eine Nur-Text-Nachricht.
 *
 * @return array{ok: bool, fehler?: string}
 */
function wq_smtp_senden(array $smtp, string $betreff, string $text): array
{
    $host = (string) $smtp['host'];
    $port = (int) $smtp['port'];
    $sicherheit = (string) $smtp['sicherheit'];
    $pruefen = (bool) $smtp['zertifikat_pruefen'];

    $kontext = stream_context_create(['ssl' => [
        'verify_peer'       => $pruefen,
        'verify_peer_name'  => $pruefen,
        'allow_self_signed' => !$pruefen,
        'SNI_enabled'       => true,
    ]]);

    $adresse = ($sicherheit === 'tls' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fehlerNr = 0;
    $fehlerText = '';
    $verbindung = @stream_socket_client($adresse, $fehlerNr, $fehlerText, WQ_SMTP_TIMEOUT, STREAM_CLIENT_CONNECT, $kontext);
    if (!$verbindung) {
        return ['ok' => false, 'fehler' => 'Keine Verbindung zum Mailserver: ' . $fehlerText];
    }
    stream_set_timeout($verbindung, WQ_SMTP_TIMEOUT);

    /* Antwort lesen. SMTP darf mehrzeilig antworten, erkennbar am Bindestrich
       hinter dem Code: "250-STARTTLS" ist Fortsetzung, "250 OK" das Ende. */
    $lesen = static function ($verbindung): array {
        $text = '';
        while (($zeile = fgets($verbindung, 1024)) !== false) {
            $text .= $zeile;
            if (strlen($zeile) < 4 || $zeile[3] !== '-') {
                break;
            }
        }
        return [(int) substr($text, 0, 3), rtrim($text)];
    };
    $sagen = static function ($verbindung, string $befehl) use ($lesen): array {
        fwrite($verbindung, $befehl . "\r\n");
        return $lesen($verbindung);
    };

    try {
        [$code, $antwort] = $lesen($verbindung);
        if ($code !== 220) {
            return ['ok' => false, 'fehler' => 'Unerwarteter Empfang: ' . $antwort];
        }

        $ehlo = 'wordquest.' . (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        [$code, $antwort] = $sagen($verbindung, 'EHLO ' . wq_kopfzeile_sicher($ehlo));
        if ($code !== 250) {
            return ['ok' => false, 'fehler' => 'EHLO abgelehnt: ' . $antwort];
        }

        if ($sicherheit === 'starttls') {
            [$code, $antwort] = $sagen($verbindung, 'STARTTLS');
            if ($code !== 220) {
                return ['ok' => false, 'fehler' => 'STARTTLS abgelehnt: ' . $antwort];
            }
            $art = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!@stream_socket_enable_crypto($verbindung, true, $art)) {
                return ['ok' => false, 'fehler' => 'Die Verschlüsselung kam nicht zustande.'];
            }
            // Nach STARTTLS muss EHLO wiederholt werden, die vorherige
            // Fähigkeitenliste gilt nicht mehr.
            [$code, $antwort] = $sagen($verbindung, 'EHLO ' . wq_kopfzeile_sicher($ehlo));
            if ($code !== 250) {
                return ['ok' => false, 'fehler' => 'EHLO nach STARTTLS abgelehnt: ' . $antwort];
            }
        }

        [$code] = $sagen($verbindung, 'AUTH LOGIN');
        if ($code !== 334) {
            return ['ok' => false, 'fehler' => 'Der Server möchte kein AUTH LOGIN.'];
        }
        [$code] = $sagen($verbindung, base64_encode((string) $smtp['benutzer']));
        if ($code !== 334) {
            return ['ok' => false, 'fehler' => 'Der Benutzername wurde nicht angenommen.'];
        }
        [$code] = $sagen($verbindung, base64_encode((string) $smtp['passwort']));
        if ($code !== 235) {
            // Niemals das Passwort protokollieren, auch nicht gekürzt.
            return ['ok' => false, 'fehler' => 'Die Anmeldung am Mailserver ist gescheitert.'];
        }

        $von = wq_kopfzeile_sicher((string) $smtp['von']);
        $an  = wq_kopfzeile_sicher((string) $smtp['an']);

        [$code, $antwort] = $sagen($verbindung, 'MAIL FROM:<' . $von . '>');
        if ($code !== 250) {
            return ['ok' => false, 'fehler' => 'Absender abgelehnt: ' . $antwort];
        }
        [$code, $antwort] = $sagen($verbindung, 'RCPT TO:<' . $an . '>');
        if ($code !== 250 && $code !== 251) {
            return ['ok' => false, 'fehler' => 'Empfänger abgelehnt: ' . $antwort];
        }
        [$code, $antwort] = $sagen($verbindung, 'DATA');
        if ($code !== 354) {
            return ['ok' => false, 'fehler' => 'DATA abgelehnt: ' . $antwort];
        }

        $kopf = [
            'From: ' . wq_betreff_kodieren((string) $smtp['von_name']) . ' <' . $von . '>',
            'To: <' . $an . '>',
            'Subject: ' . wq_betreff_kodieren($betreff),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        // Base64 umgeht die Zeilenlängengrenze und das Punktproblem zugleich:
        // Eine Zeile aus reinem Base64 kann nie mit einem Punkt allein
        // beginnen, der sonst das Ende der Nachricht bedeuten würde.
        $koerper = chunk_split(base64_encode($text), 76, "\r\n");
        fwrite($verbindung, implode("\r\n", $kopf) . "\r\n\r\n" . $koerper . "\r\n.\r\n");
        [$code, $antwort] = $lesen($verbindung);
        if ($code !== 250) {
            return ['ok' => false, 'fehler' => 'Die Nachricht wurde nicht angenommen: ' . $antwort];
        }

        $sagen($verbindung, 'QUIT');
        return ['ok' => true];
    } finally {
        @fclose($verbindung);
    }
}

/**
 * Meldet eine neue Einreichung.
 *
 * Absichtlich knapp gehalten: Titel, Umfang und die Nummer genügen. Name,
 * Kontakt und Bemerkung stehen im Admincenter und haben in einer Mail nichts
 * zu suchen, die unterwegs über fremde Server läuft.
 *
 * @return array{ok: bool, fehler?: string}
 */
function wq_einreichung_melden(int $id, string $titel, int $anzahl, int $offen): array
{
    $smtp = wq_smtp_einstellungen();
    if ($smtp === null) {
        return ['ok' => false, 'fehler' => 'Kein Mailversand eingerichtet.'];
    }

    $titel = wq_kopfzeile_sicher($titel) ?: 'ohne Titel';
    $text = 'Es ist eine neue Wortliste eingegangen.' . "\n\n"
          . 'Nummer:  ' . $id . "\n"
          . 'Titel:   ' . $titel . "\n"
          . 'Umfang:  ' . $anzahl . ' Wörter' . "\n"
          . 'Offen:   ' . $offen . ($offen === 1 ? ' Einreichung' : ' Einreichungen') . "\n\n"
          . 'Zum Ansehen und Freigeben im Admincenter unter Wortlisten anmelden.' . "\n\n"
          . 'Diese Nachricht wurde automatisch erzeugt. Eine Antwort darauf liest niemand.' . "\n";

    try {
        return wq_smtp_senden($smtp, 'wordQUEST: neue Wortliste eingereicht', $text);
    } catch (Throwable $e) {
        return ['ok' => false, 'fehler' => $e->getMessage()];
    }
}
