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

## Was nicht im Repo liegt

`img/` (lokales Bildmaterial, die ausgelieferten Bilder liegen auf `img.bildungssprit.de`),
`index2.html` und `_RAW/` sind in `.gitignore` und landen damit nie auf dem Server.

## Was im Repo liegt, aber nicht öffentlich sein darf

Weil der Docroot das ganze Repo ist, wäre grundsätzlich auch das Entwicklungsmaterial
abrufbar. Die [`.htaccess`](../.htaccess) sperrt deshalb:

- `.git`, `.env`, `.ht*` per `RedirectMatch 404`
- die Ordner `docs/`, `scripts/`, `_RAW/`
- Dateiendungen `.ps1`, `.md`, `.bak`, `.orig`, `.log`, `.sql`, `.db`, `.sqlite`

**Nicht gesperrt und das auch mit Absicht:** `*.json`. Die App lädt die Wortlisten
zur Laufzeit per `fetch()`, ein pauschales JSON-Verbot würde sie zerlegen.

## Fallen

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
Invoke-WebRequest https://wordquest.bildungssprit.de/ -SkipHttpErrorCheck | % Headers
Invoke-WebRequest https://wordquest.bildungssprit.de/.git/HEAD -Method Head -SkipHttpErrorCheck
Invoke-WebRequest https://wordquest.bildungssprit.de/docs/DEPLOY.md -Method Head -SkipHttpErrorCheck
Invoke-WebRequest https://wordquest.bildungssprit.de/sw.js -Method Head -SkipHttpErrorCheck
```

Erwartet: Startseite 200 mit CSP und `nosniff`, `.git/HEAD` und `docs/` jeweils 403 oder 404,
`sw.js` 200.
