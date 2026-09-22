# wordQUEST, Sicherheit

Ergebnis des Audits vom September 2026 und Stand der Umsetzung.

Der zentrale Blickwinkel: Heute erstellt nur der Betreiber die Wortlisten, sie sind also vertrauenswürdig. **Sobald Lehrkräfte eigene Listen einreichen können, werden die JSON-Inhalte zu fremden Eingaben.** Die meisten Punkte hier betreffen genau diesen Übergang.

---

## Erledigt

### Bild-URLs aus Wortlisten gefiltert

Das Feld `img` nimmt eine beliebige URL. Das war kein XSS-Risiko, weil das Attribut doppelt gequotet und der Wert escaped ist, aber es war ein Zählpixel: Wer eine Liste einreicht, bekäme IP-Adresse, Gerät und Zeitpunkt jedes Kindes, das sie öffnet. Bei Minderjährigen ist das ein ernstes Datenschutzproblem.

Umgesetzt ist `safeImgUrl()` in `index.html`: nur HTTPS, nur der eigene Host und `img.bildungssprit.de`. Alles andere fällt auf Emoji oder den Buchstabenkreis zurück. Zusätzlich `referrerpolicy="no-referrer"`. Zweite Ebene ist die `img-src`-Direktive der CSP.

Geprüft: `data:`-URLs, `javascript:`-URLs, fremde Hosts und unverschlüsselte Verbindungen werden abgewiesen, ein Ausbruchsversuch aus dem Attribut wird zusätzlich URL-kodiert.

### Security-Header und CSP

`.htaccess` setzt jetzt Content-Security-Policy, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` und `Permissions-Policy`. `img-src` steht ohne Wildcard und ohne `data:`, `connect-src` auf `'self'`.

**Wichtig für Plesk:** Ist unter „Apache & nginx Settings" die Option *Serve static files directly by nginx* aktiv, liefert nginx `index.html` und die JSON-Dateien aus, ohne dass die `.htaccess` greift. Dann erscheinen die Header nur bei PHP-Antworten. Entweder die Option deaktivieren oder die Header zusätzlich unter „Additional nginx directives" mit `add_header … always;` setzen. Nach dem Deployment gegenprüfen:

```bash
curl -I https://wordquest.bildungssprit.de/
```

Unter Windows PowerShell 5.1 gibt es `-SkipHttpErrorCheck` nicht, dort:

```powershell
(Invoke-WebRequest https://wordquest.bildungssprit.de/ -UseBasicParsing).Headers
```

### Subresource Integrity auf den CDN-Ressourcen

canvas-confetti und animate.css werden von fremden CDNs geladen. Ohne Integritätsprüfung führt ein kompromittiertes CDN beliebigen Code in der App-Origin aus. Beide Einbindungen haben jetzt `integrity`, `crossorigin` und `referrerpolicy`. `celebrate()` prüft vor dem Aufruf, ob die Bibliothek überhaupt geladen wurde, damit ein fehlgeschlagener Integritätscheck das Spiel nicht bricht.

### Service Worker und Update-Hinweis

Vorher cache-first ohne Revalidierung und mit unverändertem Cache-Namen. Wer `index.html` änderte, ohne `sw.js` anzufassen, lieferte an alle installierten Geräte dauerhaft die alte Datei aus. Ein behobener Fehler hätte die Schulgeräte nie erreicht.

Der erste Ansatz war, `index.html` auf network-first zu stellen. Das war falsch und ist zurückgenommen: Es hätte neues HTML mit altem, noch gecachtem CSS gemischt.

Gelöst ist es jetzt über den **Update-Hinweis**. Der neue Service Worker übernimmt nicht von selbst, sondern wartet. Die App prüft alle 15 Minuten und bei jedem Tab-Fokus aktiv auf eine neue Fassung und blendet dann unten eine Leiste ein. Erst die Zustimmung schaltet geschlossen um, alte und neue Dateien werden nie gemischt. Die Shell bleibt deshalb bewusst cache-first.

Damit erreichen Korrekturen installierte Geräte verlässlich, ohne dass jemand von Hand neu laden muss. Fremdursprünge fängt der Worker gar nicht mehr ab, und in den Cache kommen nur die Dateien der Shell.

**Regel für jedes Release: `CACHE_VERSION` in `sw.js` hochzählen.** Ohne diese Änderung bemerkt der Browser keine neue Fassung, und der Hinweis erscheint nie.

**Der Worker darf das Admincenter nicht anfassen.** Im Betrieb aufgetreten:
Ein Aufruf von `/admin/` zeigte die Lernapp statt der Anmeldung, weil der
Worker jede Navigation abfängt und die zwischengespeicherte `index.html`
ausliefert. Pfade unter `/admin` und `/api/` sind deshalb ausdrücklich
ausgenommen und gehen immer ans Netz.

Eine Einschränkung bleibt: Wer die Leiste mit „Später" wegklickt, bleibt vorerst auf der alten Fassung. Die Leiste erscheint beim nächsten Seitenaufruf erneut, es gibt also kein dauerhaftes Wegdrücken.

### Obergrenzen beim Laden von Wortlisten

Eine Liste mit 200.000 Einträgen legt ein altes Schul-Tablet lahm. Der Client begrenzt jetzt auf 500 Wörter je Liste und 120 Zeichen je Eintrag und meldet eine Kürzung sichtbar. `wordlists/index.php` überspringt Dateien über 512 KB.

Diese Grenzen sind Geräteschutz. **Beim späteren Upload müssen sie serverseitig nochmals erzwungen werden**, der Client ist dafür keine Instanz.

### Kleinere Härtungen

Der Wildcard `Access-Control-Allow-Origin: *` in `wordlists/index.php` ist entfernt, der Endpunkt wird nur gleichursprünglich genutzt. Kategorieschlüssel werden mit `Object.hasOwn` geprüft, damit `"cat": "constructor"` keine geerbten Eigenschaften nach oben holt. Der Fallback-Ladepfad kodiert Dateinamen jetzt wie der Hauptpfad. Punktestände aus dem Speicher werden gegen `NaN` abgesichert, und die Bestleistung kann nicht mehr unter den aktuellen Punktestand fallen.

---

## Ohne Befund geprüft

Damit klar ist, wo nicht nachgebessert werden muss:

- **Escaping.** `escapeHtml()` maskiert `&`, `<`, `>`, `"` und `'`. Jede erzeugte Attributstelle ist doppelt gequotet, ein zusätzliches `escapeAttr` ist nicht nötig. Alle `innerHTML`-Senken mit Wortlistendaten wurden einzeln durchgegangen und sind escaped. Antwortbuttons, Feedback und Fehlermeldungen laufen über `textContent`.
- **Keine dynamische Codeausführung.** Kein `eval`, kein `new Function`, kein `document.write`, keine dynamische Skripterzeugung.
- **Keine Verarbeitung von URL-Parametern.** Kein `location.search`, kein `location.hash`, kein `document.referrer`. Diese Angriffsklasse existiert in der App nicht.
- **localStorage.** Alle `JSON.parse`-Aufrufe auf Speicherwerte stehen in `try/catch`. Kein Speicherinhalt gelangt über `innerHTML` ins DOM.
- **`wordlists/index.php`.** Keine Auswertung von `$_GET`, `$_POST` oder `$_SERVER`, also kein Path Traversal. `glob()` ist auf das eigene Verzeichnis beschränkt, `basename()` verhindert Pfadanteile in der Ausgabe.
- **Kein Cache Poisoning über Fremdursprünge im Service Worker**, da nur Antworten vom Typ `basic` gespeichert werden.

---

## Serverseite, Stand nach WQ-7.1 und WQ-7.2

Die erste Serverschicht steht: PHP 8 mit SQLite, ein einziger Endpunkt
`api/stat.php` für die anonyme Statistik. Bewusst gewählt als Einstieg, weil
er ohne Login, ohne Upload und ohne ein einziges personenbeziehbares Datum
auskommt und trotzdem den gesamten Serverweg beweist.

**Wo die Daten liegen.** Die SQLite-Datei liegt unter `private/` als
Geschwister von `httpdocs` und ist über HTTP nicht erreichbar. Das ist die
wichtigste Einzelmaßnahme dieser Schicht. Der Pfad wird aus `__DIR__`
berechnet, nicht aus `DOCUMENT_ROOT`, und lässt sich über eine
`api/lib/config.local.php` überschreiben, die nie ins Repo kommt.

**Was gespeichert wird.** Ausschließlich Zähler: Tag, Bereich, Schlüssel,
Anzahl. Dazu je Vokabel die Summe richtiger und falscher Antworten über alle
Spielenden. Keine IP-Adresse, auch nicht gehasht. Keine Sitzungs- oder
Gerätekennung. Keine Uhrzeit feiner als der Kalendertag. Kein Spielername,
kein Punktestand. Damit entsteht kein Personenbezug.

**Warum es ohne Rate Limiting auskommt.** Ein offener Schreibendpunkt wäre
sonst ein Weg, die Platte vollzuschreiben. Hier ist stattdessen der
Schlüsselraum begrenzt: Listennamen müssen einer tatsächlich vorhandenen
Datei entsprechen, Modi und Bereiche kommen aus festen Positivlisten,
Wortlängen sind gedeckelt. Es lassen sich also keine erfundenen Zeilen
anlegen, nur vorhandene Zähler erhöhen. Die Zahlen sind damit als Hinweis zu
lesen, nicht als Beweis. Für den Zweck, schwierige Vokabeln zu finden,
genügt das.

Geprüft wurde gegen: erfundene Listennamen, Path Traversal im Listennamen,
falsche HTTP-Methode, kaputtes JSON, überlange Anfragen und zu viele
Ereignisse. Alle wurden abgewiesen oder still verworfen, ohne PHP-Fehler
nach außen.

**Weiterhin offen:** Sobald Uploads oder ein Login dazukommen, gelten die
Punkte im nächsten Abschnitt unverändert.

## Anmeldung und Rollen, Stand nach WQ-7.3

Zwei Rollen: `superadmin` verwaltet Konten, `admin` nur Inhalte.

**Passwörter** liegen ausschließlich als Argon2id-Hash in der Datenbank,
bcrypt als Rückfall, wenn die PHP-Installation Argon2id nicht mitbringt. Ein
veralteter Hash wird bei der nächsten Anmeldung still erneuert. Nie steht ein
Passwort im Repo oder in einer Konfigurationsdatei.

**Konten entstehen nur über die Kommandozeile** (`scripts/admin-anlegen.php`,
mit `PHP_SAPI`-Sperre gegen Webaufrufe). Das Passwort wird abgefragt statt als
Argument übergeben, sonst stünde es in der Prozessliste und in der
Shell-Historie. Folge dieser Entscheidung: Ein übernommenes Admin-Konto kann
sich keine weiteren Konten verschaffen.

**Gegen Benutzer-Enumeration und Timing-Angriffe** wird auch bei unbekanntem
Benutzernamen gegen einen festen Dummy-Hash geprüft, die Fehlermeldung ist
immer dieselbe, und eine zufällige Verzögerung von 150 bis 400 Millisekunden
überdeckt den Rest. Gemessener Unterschied zwischen unbekanntem Benutzer und
falschem Passwort: 59 Millisekunden, also deutlich innerhalb des Rauschens.

**Kontosperre** nach 10 Fehlversuchen für 15 Minuten. Läuft die Sperre ab, wird
der Fehlerzähler vollständig zurückgesetzt. Ohne das bliebe er auf 10 stehen,
und der nächste Fehlversuch löste sofort eine neue Sperre aus, das Konto wäre
praktisch dauerhaft zu. Genau das ist bei der Ersteinrichtung passiert und hat
eine Weile wie ein falsches Passwort ausgesehen. Der Zustand ist jetzt in
`admin-anlegen.php liste` sichtbar.

Während der Sperre wird
auch das richtige Passwort abgewiesen.

**Sitzung:** eigener Name `wqadmin`, Cookie mit `httponly`, `samesite=Strict`
und `secure` unter HTTPS, `use_strict_mode` und `use_only_cookies` gesetzt,
nach der Anmeldung `session_regenerate_id(true)` gegen Session-Fixation,
Leerlauf beendet die Sitzung nach 60 Minuten.

**CSRF:** 32 Byte aus `random_bytes`, Vergleich mit `hash_equals`, verlangt bei
jedem POST. Geprüft: Anmeldung ohne Token endet mit 403.

**Rollen werden serverseitig durchgesetzt.** `wq_verlange_rolle('superadmin')`
steht am Anfang jeder betroffenen Seite, das Ausblenden des Menüpunkts ist nur
Kosmetik. Geprüft: Ein normaler Admin bekommt auf `admins.php` 403, der
Menüpunkt fehlt ihm zusätzlich.

**Selbstaussperrung verhindert:** Die eigene Rolle lässt sich nicht ändern, das
eigene Konto nicht sperren, und der letzte aktive Superadmin kann weder
herabgestuft noch gesperrt werden.

**Das Admincenter kommt ohne JavaScript aus.** Seine Content-Security-Policy
setzt deshalb `script-src 'none'`, gesendet aus PHP heraus, weil
`.htaccess`-Header bei manchen Plesk-Konfigurationen nicht für PHP-Antworten
greifen. Teilvorlagen (`kopf.php`, `fuss.php`) antworten bei direktem Aufruf
mit 403.

### Zwei CSP-Header werden als Schnittmenge ausgewertet

Real passiert und hat lange gekostet: Die Anmeldung im Admincenter tat
nichts. Kein Fehler, keine Meldung, kein Protokolleintrag, in zwei Browsern
und auch im privaten Fenster. Mit `curl` funktionierte dieselbe Anmeldung
einwandfrei.

Ursache: Die `.htaccess` im Wurzelverzeichnis setzte `form-action 'none'`.
Das `kopf.php` des Admincenters setzte zwar `form-action 'self'`, aber **bei
mehreren CSP-Headern gilt immer die Schnittmenge**, und die strengere Angabe
gewinnt. Der Browser hat das Formular deshalb gar nicht erst abgeschickt.

Zwei Lehren daraus:

1. **`form-action 'none'` blockt still.** Es gibt keinen Serverfehler, keinen
   Eintrag im Zugriffsprotokoll und keine sichtbare Meldung. Nur die
   Browserkonsole verrät es. Wer serverseitig sucht, findet nichts.
2. **Eine Richtlinie kann eine andere nicht lockern, nur verschärfen.** Wer
   sie pro Verzeichnis überschreiben will, muss die Wurzelfassung passend
   halten. Hier steht deshalb auch dort `form-action 'self'`.

Beim nächsten unerklärlichen Verhalten im Browser zuerst die Konsole
öffnen, bevor serverseitig gesucht wird.

### Keine Serverinterna auf ausgelieferten Seiten

Real passiert: Die Anmeldeseite des Admincenters trug als Hilfestellung die
vollständigen Befehle zur Kontoverwaltung, inklusive Serverpfad
`/opt/plesk/php/8.4/bin/php`, Systembenutzer `bs_vps-user` und
Projektstruktur. Das ist eine Landkarte für jeden, der die Seite findet,
und es stand auf der einen Seite, die ohne Anmeldung erreichbar ist.

Entfernt. **Regel: Betriebswissen gehört in `docs/`, nie in eine Datei, die
der Server ausliefert.** Das gilt besonders für Anmeldeseiten und
Fehlermeldungen. Gegenprüfen lässt sich das mit:

```bash
grep -rn "opt/plesk\|bs_vps-user" admin/ api/ index.html
```

## Wortlisten-Upload, Stand nach WQ-7.4

**Die hochgeladenen Bytes werden nie veröffentlicht.** Das ist die zentrale
Entscheidung dieser Schicht. Geprüft wird die Struktur, geschrieben wird eine
frisch aus den geprüften Werten erzeugte Datei. Damit sind Polyglot-Dateien,
eingebetteter Code und unbekannte Felder ausgeschlossen, unabhängig davon, was
jemand hochlädt. Im Test verschwanden erfundene Felder wie `boeses_feld` und
`schadcode` restlos aus der veröffentlichten Datei.

**Einreichungen liegen in der Datenbank, nicht im Dateisystem.** Das erspart
Dateirechte, Pfadprüfungen und Aufräumarbeit, und der Upload berührt das
ausgelieferte Verzeichnis zu keinem Zeitpunkt.

**Geprüft wird der Inhalt, nicht die Dateiendung.** Eine `.json`-Endung sagt
nichts darüber aus, was in der Datei steht.

Weitere Grenzen: 512 KB je Datei, 500 Wörter, 120 Zeichen je Wort, 200 je
Beispielsatz, 40 Kategorien, JSON-Tiefe 8. Bild-Adressen müssen HTTPS sein und
von einem bekannten Host kommen, sonst werden sie mit Hinweis verworfen.
Dateinamen erzeugt der Server aus dem Titel; ein Titel wie `../../etc/passwd`
wird dabei zu `etc-passwd.json`.

Geprüft wurde gegen: PHP-Code im JSON-Mantel, HTML mit Skript, kaputtes JSON,
fehlende Pflichtfelder, fremde Bildhosts, unverschlüsselte Bild-Adressen,
Path Traversal im Titel, unbekannte Zusatzfelder, Upload ohne CSRF-Token und
Zugriff ohne Anmeldung. Alle wurden abgewiesen oder still bereinigt.

**Offener Punkt:** Über die Oberfläche freigegebene Listen liegen nur auf dem
Server, nicht im Repository. Sie gehören deshalb in die Sicherung, sonst gehen
sie bei einer Neueinrichtung verloren.

## Foto zu Liste, Stand nach WQ-9.5

**Die Texterkennung läuft im Browser, das Foto wird nie hochgeladen.** Das ist
hier nicht nur die sparsamere, sondern auch die richtige Lösung: Fotos aus
Lehrwerken sind urheberrechtlich heikel, und was das Geraet nie verlässt, kann
auch nicht auf dem Server liegen bleiben. Abgeschickt wird ausschließlich die
vom Menschen bestätigte Tabelle als Text.

**Es gibt keinen zweiten Weg in den Server.** Der bestätigte Text läuft durch
genau dieselbe Import- und Prüfkette wie eine von Hand eingefügte Tabelle und
landet als Einreichung, nicht als veröffentlichte Liste.

**Die Fotoseite ist die einzige Seite des Admincenters mit JavaScript.** Sie
bringt deshalb eine eigene, enger begründete Richtlinie mit: Skript nur von
sich selbst und dem CDN, WebAssembly erlaubt, Worker nur aus Blob. Alle anderen
Seiten bleiben bei `script-src 'none'`. Damit die beiden sich nicht
gegenseitig aufheben, entfernt `admin/.htaccess` für diese eine Datei den
geerbten Header, denn mehrere CSP-Header gelten immer als Schnittmenge.

**Keine KI auf dem Server.** Ein Vision-Modell, das Fotos zuverlässig liest,
braucht mehrere Gigabyte und eine Grafikkarte. Auf einer geteilten Plesk-Box
mit weiteren Auftritten ist das keine Option. Gebraucht wird ohnehin nur OCR,
und die ist klein genug für den Browser.

## Vor dem Postfach zwingend zu erledigen

Diese Punkte sind noch offen und dürfen nicht übersprungen werden, sobald Lehrkräfte hochladen können.

1. **Uploads außerhalb der Document Root.** Das Verzeichnis `httpdocs/wordlists/` ist per HTTP erreichbar und PHP ist aktiv. Eine hochgeladene `.php` wäre Codeausführung, eine `.html` oder `.svg` wäre gespeichertes XSS in der App-Origin, eine `.htaccess` würde jede Schutzmaßnahme aufheben. Einreichungen gehören nach `…/private/`, ausgeliefert wird über einen PHP-Reader mit festem `Content-Type`.
2. **Dateinamen serverseitig erzeugen**, etwa `bin2hex(random_bytes(8)) . '.json'`. Der Originalname nur als Metadatum.
3. **Freigabe-Workflow vom Auslieferungsverzeichnis trennen.** `index.php` listet mit `glob('*.json')` alles, was im Ordner liegt. Schreibt das Postfach dorthin, ist jede Einreichung sofort live. Einreichungen gehören in eine Quarantäne, die `index.php` nicht scannt.
4. **Schema- und Größenprüfung serverseitig**, mit `json_decode` und `JSON_THROW_ON_ERROR`, danach Strukturprüfung gegen das Wortlistenschema.
5. **Rate Limiting am öffentlichen Endpunkt.** Honeypot-Feld, Mindestzeit zwischen Formularaufruf und Absenden, Zähler pro Adresse. Die zur Begrenzung genutzte Adresse nicht dauerhaft speichern.
6. **SVG gehört nicht auf die Positivliste erlaubter Bildformate**, auch nicht nach Typprüfung. SVG ist skriptfähig und wird bei direktem Aufruf in der App-Origin ausgeführt. Die geplante Umwandlung nach WebP löst das, solange sie verpflichtend ist.
7. **`'unsafe-inline'` aus der CSP entfernen.** Dafür muss das Anwendungsskript aus `index.html` in eine eigene Datei wandern und die `onclick`-Attribute müssen durch `addEventListener` ersetzt werden. Das Muster dafür ist im Code bereits vorhanden.

---

## Beim Deployment prüfen

Diese Dateien gehören **nicht** auf den Server: `_RAW/`, `index2.html`, `docs/`, `scripts/`, `.git/`. Die `.htaccess` sperrt sie zusätzlich, aber der sicherste Weg ist, sie gar nicht erst hochzuladen. Beim Umstieg auf Deployment per `git pull` entfällt das Problem weitgehend, weil `.gitignore` bereits greift.
