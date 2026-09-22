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

### Subresource Integrity auf den CDN-Ressourcen

canvas-confetti und animate.css werden von fremden CDNs geladen. Ohne Integritätsprüfung führt ein kompromittiertes CDN beliebigen Code in der App-Origin aus. Beide Einbindungen haben jetzt `integrity`, `crossorigin` und `referrerpolicy`. `celebrate()` prüft vor dem Aufruf, ob die Bibliothek überhaupt geladen wurde, damit ein fehlgeschlagener Integritätscheck das Spiel nicht bricht.

### Service Worker und Update-Hinweis

Vorher cache-first ohne Revalidierung und mit unverändertem Cache-Namen. Wer `index.html` änderte, ohne `sw.js` anzufassen, lieferte an alle installierten Geräte dauerhaft die alte Datei aus. Ein behobener Fehler hätte die Schulgeräte nie erreicht.

Der erste Ansatz war, `index.html` auf network-first zu stellen. Das war falsch und ist zurückgenommen: Es hätte neues HTML mit altem, noch gecachtem CSS gemischt.

Gelöst ist es jetzt über den **Update-Hinweis**. Der neue Service Worker übernimmt nicht von selbst, sondern wartet. Die App prüft alle 15 Minuten und bei jedem Tab-Fokus aktiv auf eine neue Fassung und blendet dann unten eine Leiste ein. Erst die Zustimmung schaltet geschlossen um, alte und neue Dateien werden nie gemischt. Die Shell bleibt deshalb bewusst cache-first.

Damit erreichen Korrekturen installierte Geräte verlässlich, ohne dass jemand von Hand neu laden muss. Fremdursprünge fängt der Worker gar nicht mehr ab, und in den Cache kommen nur die Dateien der Shell.

**Regel für jedes Release: `CACHE_VERSION` in `sw.js` hochzählen.** Ohne diese Änderung bemerkt der Browser keine neue Fassung, und der Hinweis erscheint nie.

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
