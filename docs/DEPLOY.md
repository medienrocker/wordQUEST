# wordQUEST, Deployment

Seit September 2026 läuft der Deploy über `git pull` auf dem VPS. Kein FTP mehr.

wordQUEST ist eine rein statische PWA ohne Build-Schritt. Deshalb gilt hier das
learnWEB-Muster: **der Docroot ist das Git-Checkout selbst**, es gibt kein `rsync`
und kein `dist`.

## Fakten

| Sache | Wert |
|-------|------|
| Server | `nostalgic-vaughan` (Plesk, IONOS), SSH als root |
| Docroot = Checkout | `/var/www/vhosts/bildungssprit.de/wordquest.bildungssprit.de/httpdocs` |
| Remote | `github-wordquest:medienrocker/wordQUEST.git` (SSH-Alias, read-only Deploy-Key) |
| Key | `/root/.ssh/wordquest_deploy` |
| Branch | `main` |
| Besitzer httpdocs | `bs_vps-user:psaserv` |
| Besitzer `.well-known` | `bs_vps-user:psacln` |
| Deploy | `bash scripts/deploy.sh` im Docroot |

## Ablauf bei jedem Update

Lokal committen und pushen, dann in PuTTY:

```bash
cd /var/www/vhosts/bildungssprit.de/wordquest.bildungssprit.de/httpdocs
bash scripts/deploy.sh
```

Mehr nicht. Das Skript prüft auf einen sauberen und vorspulbaren Stand, zieht mit
`--ff-only`, stellt die Plesk-Rechte wieder her und gibt die Service-Worker-Cache-Version aus.

## Pflicht vor jedem Push: Cache-Version hochzählen

In [`sw.js`](../sw.js) steht oben `CACHE_VERSION`. **Diese Zahl bei jeder Änderung an
`index.html`, `style.css` oder `sw.js` erhöhen.** Nur wenn sich die Datei `sw.js`
tatsächlich unterscheidet, bemerkt der Browser eine neue Fassung, und nur dann
erscheint der Update-Hinweis.

## Update-Hinweis in der App

Der neue Service Worker übernimmt **nicht** von selbst. Er wartet, und die App blendet
unten eine Leiste ein: „Es gibt eine neue Version von wordQUEST." Erst ein Klick auf
„Jetzt aktualisieren" schaltet um, danach lädt die Seite über `controllerchange` neu.
So werden nie alte und neue Dateien gemischt.

Gesucht wird aktiv: alle 15 Minuten und immer dann, wenn der Tab wieder in den
Vordergrund kommt. Ohne diesen aktiven Check würde eine installierte PWA, die tagelang
offen bleibt, ein Update nie bemerken.

**Lokal nicht testbar.** Auf `localhost`, `127.0.0.1` und `[::1]` ist der Cache
komplett abgeschaltet und der Worker übernimmt sofort, sonst sucht man beim Entwickeln
Fehler im Cache statt im Code. Die Leiste lässt sich lokal nur mit einem
Platzhalter-Objekt prüfen:

```js
zeigeUpdateHinweis({ postMessage() {} })
```

Der echte Ablauf ist erst auf der Domain zu sehen: eine Fassung deployen, die Seite im
Browser offen lassen, die nächste Fassung deployen, dann erscheint die Leiste.

**Vorsicht bei Deploys, die gleichzeitig Pfade verschieben.** Solange ein Worker wartet,
läuft die alte Fassung aus dem Cache weiter und liefe bei einem Cache-Miss auf 404.
Solche Umzüge besser in zwei Deploys trennen.

## Einmalig: Datenverzeichnis anlegen

Die Serverseite schreibt nach `private/` **neben** `httpdocs`, nie hinein.
Das Verzeichnis gehört nicht ins Repo und muss einmal von Hand angelegt werden:

```bash
D=/var/www/vhosts/bildungssprit.de/wordquest.bildungssprit.de
mkdir -p "$D/private"
chown bs_vps-user:psaserv "$D/private"
chmod 770 "$D/private"
```

PHP läuft als `bs_vps-user` und legt die SQLite-Datei beim ersten Aufruf selbst
an. Prüfen lässt sich das so:

```bash
curl -s -X POST https://wordquest.bildungssprit.de/api/stat.php \
  -H 'Content-Type: application/json' -d '{"events":[{"typ":"modus","wert":"quiz"}]}'
ls -l "$D/private"
```

Erwartet: `{"ok":true,"uebernommen":1}` und danach eine Datei `wordquest.sqlite`.

**Sicherung:** Die Datenbank ist eine einzelne Datei. `cp` genügt, am besten im
selben Lauf wie die übrigen Sicherungen. In `private/` liegt ausserdem
`secret.key`, der Schlüssel für die signierten Formularmarken. Geht er
verloren, ist das kein Drama: Es wird ein neuer erzeugt, und offene Formulare
müssen einmal neu geladen werden.

## Optional: Benachrichtigung bei neuen Einreichungen

Ohne diese Einstellung verschickt der Server nichts, und das ist kein
Fehlerfall. Offene Einreichungen zeigt das Admincenter als Zähler neben
**Wortlisten**.

Die Zugangsdaten gehören in `api/lib/config.local.php`. Diese Datei ist in
`.gitignore` und kommt nie ins Repo:

```php
<?php
return [
    'smtp' => [
        'host'       => 'smtp.example.org',
        'port'       => 465,
        'sicherheit' => 'tls',          // 'tls' für Port 465, 'starttls' für 587
        'benutzer'   => 'wordquest@example.org',
        'passwort'   => 'das-Postfachpasswort',
        'von'        => 'wordquest@example.org',
        'von_name'   => 'wordQUEST',
        'an'         => 'betreiber@example.org',
    ],
];
```

Die Absenderadresse muss zu der Domain gehören, über die verschickt wird, sonst
sortieren die Empfänger die Nachricht aus. In Plesk dafür ein eigenes Postfach
anlegen und dessen Zugangsdaten eintragen, nicht die eines persönlichen
Postfachs.

Prüfen lässt sich das mit einer Testeinreichung über
`https://wordquest.bildungssprit.de/einreichen.php`. Kommt keine Mail an, steht
der Grund in `private/php-error.log`.

## Einmalig: ersten Superadmin anlegen

Das Admincenter liegt unter `/admin/`. Konten entstehen nur über die
Kommandozeile, nie über ein Formular:

```bash
cd /var/www/vhosts/bildungssprit.de/wordquest.bildungssprit.de/httpdocs
sudo -u bs_vps-user -H /opt/plesk/php/8.4/bin/php scripts/admin-anlegen.php anlegen falk superadmin
```

Das Passwort wird abgefragt, mindestens 12 Zeichen.

**Zwei Dinge sind an diesem Befehl wichtig:**

`sudo -u bs_vps-user`, weil PHP-FPM als dieser Benutzer läuft. Als root
angelegte Dateien gehörten danach root, und der Webauftritt käme nicht mehr
an die Datenbank.

**Der volle Pfad zur Plesk-PHP.** Ein blankes `php` ist auf dieser Box
`/usr/bin/php`, die System-PHP von Ubuntu. Ihr fehlt `pdo_sqlite`, und das
Skript scheitert dann mit `could not find driver`. Dieselbe Falle wie bei
`/usr/bin/node`. Verfügbar sind `/opt/plesk/php/8.3/bin/php` und
`/opt/plesk/php/8.4/bin/php`. Prüfen lässt sich das mit:

```bash
/opt/plesk/php/8.4/bin/php -m | grep pdo_sqlite
```

Damit man den Pfad nicht jedes Mal tippt, lohnt sich eine Abkürzung:

```bash
alias wqphp='sudo -u bs_vps-user -H /opt/plesk/php/8.4/bin/php'
wqphp scripts/admin-anlegen.php liste
```

Weitere Befehle:

```bash
wqphp scripts/admin-anlegen.php liste
wqphp scripts/admin-anlegen.php anlegen <name> admin
wqphp scripts/admin-anlegen.php passwort <name>
wqphp scripts/admin-anlegen.php sperren <name>
wqphp scripts/admin-anlegen.php pruefen <name>
```

### Anmeldung klappt nicht?

Der Befehl `pruefen` sagt, woran es liegt, ohne das Passwort auszugeben:

```bash
wqphp scripts/admin-anlegen.php pruefen falk
```

Er meldet Zeichenzahl, Bytezahl, ob das Passwort reines ASCII ist und ob es
zum gespeicherten Hash passt.

**Umlaute und andere Nicht-ASCII-Zeichen sind die häufigste Ursache.** PuTTY
sendet sie je nach Einstellung als ISO-8859-1, der Browser immer als UTF-8.
Dann wird ein anderer Bytestrom gespeichert als später geprüft, und die
Anmeldung scheitert trotz richtiger Eingabe. Erkennbar daran, dass Bytezahl
und Zeichenzahl auseinanderfallen. Abhilfe: ein langes Passwort nur aus
ASCII-Zeichen, oder in PuTTY unter Window, Translation die Zeichenkodierung
auf UTF-8 stellen.

## Was nicht im Repo liegt

`img/`, `index2.html` und `_RAW/` sind in `.gitignore` und landen damit nie auf
dem Server.

**Achtung bei `img/auto/`.** Dort legt die Bilderverwaltung des Admincenters
(WQ-7.5) die hochgeladenen Bilder ab. Der Ordner entsteht auf dem Server und
ist durch `img/` mit ausgeschlossen, ein `git pull` fasst ihn also nie an. Das
ist so gewollt, bedeutet aber: **`img/auto/` gehört in die Sicherung**, genau
wie `private/`. Ohne ihn zeigen die betroffenen Vokabeln nach einer
Wiederherstellung wieder nur den Anfangsbuchstaben im Kreis.

```bash
cp -a "$D/httpdocs/img/auto" /pfad/zur/sicherung/
```

Der Ordner muss `bs_vps-user` gehören, sonst kann PHP nicht hineinschreiben.
`scripts/deploy.sh` setzt das beim nächsten Lauf ohnehin mit.

## Was im Repo liegt, aber nicht öffentlich sein darf

Weil der Docroot das ganze Repo ist, wäre grundsätzlich auch das Entwicklungsmaterial
abrufbar. Die [`.htaccess`](../.htaccess) sperrt deshalb:

- `.git`, `.env`, `.ht*` per `RedirectMatch 404`
- die Ordner `docs/`, `scripts/`, `_RAW/`
- Dateiendungen `.ps1`, `.md`, `.bak`, `.orig`, `.log`, `.sql`, `.db`, `.sqlite`

**Nicht gesperrt und das auch mit Absicht:** `*.json`. Die App lädt die Wortlisten
zur Laufzeit per `fetch()`, ein pauschales JSON-Verbot würde sie zerlegen.

## Fallen

**Ein blankes `php` ist die falsche PHP.** Auf dieser Box ist `php` die
System-PHP von Ubuntu (8.1) ohne `pdo_sqlite`. Jeder Aufruf eines Skripts aus
`scripts/` braucht deshalb den vollen Plesk-Pfad, siehe oben. Symptom sonst:
`could not find driver`.

**CRLF killt das Deploy-Skript.** Von Windows committete `.sh`-Dateien landen ohne
`.gitattributes` mit CRLF im Repo, auf dem Server scheitert dann schon die Shebang
mit `bash\r: not found`. Die [`.gitattributes`](../.gitattributes) erzwingt LF für
`*.sh`, `sw.js`, `*.json` und `.htaccess`.

**Niemals `git clean -fd` im Docroot.** `.well-known` gehört Plesk und Let's Encrypt,
ist untracked und überlebt Pull und `reset --hard`, aber kein `clean`. Ohne diesen
Ordner brechen die Zertifikatserneuerungen.

**`git config --global safe.directory` nur mit `--add`.** Liegen auf der Box bereits
Einträge anderer Projekte, scheitert ein einfaches Setzen mit
`cannot overwrite multiple values with a single value`. Das Deploy-Skript prüft das
selbst und ergänzt nur.

**Kein `git clone` in den vorbelegten Docroot.** Plesk legt dort `.well-known` und je
nach Einrichtung eine Beispielseite ab, `git clone` verweigert deshalb den Dienst.
Bei der Ersteinrichtung stattdessen `git init` plus `git remote add` plus `git fetch`
plus `git checkout -f -B main origin/main`.

**Header prüfen, wenn Plesk statische Dateien direkt über nginx ausliefert.** Ist unter
„Apache & nginx Settings" die Option *Serve static files directly by nginx* aktiv, greift
die `.htaccess` für statische Dateien nicht und die Sicherheits-Header fehlen. Nach dem
Deploy von außen gegenprüfen, siehe [SECURITY.md](SECURITY.md).

## Prüfung nach dem Deploy, von außen

```powershell
# Läuft auch unter Windows PowerShell 5.1. Dort gibt es kein
# -SkipHttpErrorCheck, deshalb der try/catch: 403 und 404 kommen
# als Ausnahme zurück, nicht als Statuscode.
$b = "https://wordquest.bildungssprit.de"
foreach ($p in @("/private/wordquest.sqlite","/api/lib/config.php","/api/lib/db.php","/docs/DEPLOY.md","/.git/HEAD","/sw.js","/")) {
  try   { $c = (Invoke-WebRequest "$b$p" -Method Head -UseBasicParsing -ErrorAction Stop).StatusCode }
  catch { $c = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { "Verbindungsfehler" } }
  "{0,-30} {1}" -f $p, $c
}
$h = (Invoke-WebRequest $b -UseBasicParsing).Headers
"CSP vorhanden: " + [bool]$h["Content-Security-Policy"]
"nosniff:       " + $h["X-Content-Type-Options"]
```

Erwartet: `private/`, `api/lib/`, `docs/` und `.git/HEAD` jeweils **403 oder 404**,
`sw.js` und die Startseite **200**, `CSP vorhanden` **True**.

### Bei jedem Deploy, der ein Formular betrifft

Einmal wirklich absenden und dabei die **Browserkonsole** (F12) offen haben.

Grund: Eine Content-Security-Policy blockt Formulare **still**. Kein
Serverfehler, kein Eintrag im Zugriffsprotokoll, keine Meldung auf der Seite.
Nur die Konsole verrät es. Genau das hat die Anmeldung im Admincenter
stundenlang blockiert, siehe [SECURITY.md](SECURITY.md).

**Merkregel: Funktioniert etwas mit `curl`, aber nicht im Browser, liegt die
Ursache fast immer bei etwas, das nur der Browser durchsetzt.** Also CSP,
CORS, Cookie-Regeln, Mixed Content oder ein Service Worker. Dann zuerst die
Konsole öffnen, nicht serverseitig suchen.

Ist `CSP vorhanden` gleich `False`, liefert Plesk statische Dateien direkt über nginx
aus und die `.htaccess`-Header greifen nicht. Dann entweder *Serve static files directly
by nginx* abschalten oder die Header unter „Additional nginx directives" mit
`add_header … always;` setzen.
