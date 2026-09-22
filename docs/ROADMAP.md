# wordQUEST, Roadmap ab Version 2

Fortsetzung von `IMPLEMENTATION_TICKETS.md` (Epics 0 bis 4 sind umgesetzt: PWA, Mehrfachlisten, Vokabelfilter, Diagnose mit Leitner light).

Neue Ticketnummern starten bei Epic 5.

## Stand

**Epic 5 und die Tonstufe aus Epic 6 (WQ-6.1, WQ-6.2) sind umgesetzt.**

Epic 5 vollständig (Runden-Engine, Fortschrittsanzeige, SRS Version 2 mit Leech-Schutz, Distraktoren, Punktesystem, Spielername und Avatar). Dazu wurden die Sofortmaßnahmen aus dem Sicherheitsaudit eingebaut, siehe `SECURITY.md`.

Neu dazu: Aussprache über die Web Speech API an jedem englischen Wort, dazu der Hörmodus
als fünftes Spiel mit den Stufen „Zuordnen" und „Diktat".

Dazu WQ-6.3: Beispielsätze im Schema und der Modus Lückensatz.

Dazu WQ-7.1 und WQ-7.2: Serverfundament mit PHP und SQLite, anonyme Nutzungsstatistik.

Dazu WQ-7.3: Anmeldung mit Rollen, Admincenter mit Statistikübersicht.

Dazu WQ-7.4: Wortlistenverwaltung mit Upload, Prüfung, Vorschau und Freigabe.

Dazu WQ-7.4b: Excel, CSV, eingefügte Tabellen und WQ-9.5: Foto einer Wortschatzseite,
im Browser erkannt, als Einreichung übernommen.

Dazu WQ-7.5: Bilderverwaltung im Admincenter.

Dazu WQ-8.1 und WQ-8.2: öffentliche Seite "Wortliste einreichen" mit
Spamschutz und Warteschlange im Admincenter.

Offen: WQ-6.4, WQ-8.3, Epic 9 ohne WQ-9.5, Epic 10.

### Entscheidungen aus der Wiederverwendungsprüfung

**KI-Anbindung aus blackOUT: nicht übernehmbar.** blackOUT ist bewusst netzwerkfrei gebaut und nutzt ausschließlich lokale ONNX- und WASM-Modelle im Browser. Es gibt dort keinen Anbieter, keinen Endpunkt, kein SDK, keinen Schlüssel und keinen Proxy. Für die Bilderzeugung ist also nichts vorhanden, was sich übertragen ließe. Das bestätigt aber die Architekturentscheidung in Epic 9: Wenn Bilder ohnehin vorab per lokalem Skript entstehen, entfallen Proxy, Schlüsselschutz und Kostendeckel zur Laufzeit komplett.

**Für WQ-9.5 (Foto zu Liste) ist blackOUT dagegen ein Treffer:** `blackOUT/web/src/core/ocr.js` liefert eine fertige, kostenfreie Browser-OCR auf Tesseract-Basis, die Wörter samt Rechtecken zurückgibt. Damit lassen sich zweispaltige Wortschatzseiten anhand der X-Koordinate in Englisch und Deutsch trennen. Zusätzlicher Vorteil: Fotos aus Lehrwerken verlassen das Gerät nicht, was beim Urheberrecht die deutlich ruhigere Lösung ist. Grenze: Handschrift und schlechte Scans liest Tesseract nicht zuverlässig.

**Adminbereich: Website_Maria_2026 ist die Vorlage, taskFLOW nicht.** taskFLOW ist React plus Supabase plus Deno Edge Functions, also ein inkompatibler Stack, von dem sich auf einem Plesk-Server nichts betreiben lässt. Website_Maria_2026 dagegen nutzt exakt den geplanten Stack (PHP 8, SQLite über PDO, kein Framework, kein Build) und ist bereits durch einen Sicherheitsdurchgang gegangen.

Direkt übernehmbar von dort:

| Datei | Wofür |
|-------|-------|
| `includes/auth.php` | Sessions, CSRF-Token, Login-Sperre, Passwort-Reset, Schutz gegen Timing-Angriffe und Benutzer-Enumeration. Der Zwei-Faktor-Teil ist optional abschaltbar |
| `includes/bootstrap.php` | Config-Loader, Fehlerbehandlung, Security-Header aus PHP heraus (nötig, weil `.htaccess`-Header bei IONOS nur für statische Dateien greifen) |
| `includes/helpers.php` | `safeFilename`, `uploadImage`, `processUploadedImage`: MIME-Prüfung per `finfo` aus dem Dateiinhalt, Megapixel-Deckel, Neukodierung über GD |
| `data/.htaccess`, `includes/.htaccess` | Verzeichnisschutz, wörtlich übernehmbar |
| `tools/create-user.php` | CLI-Benutzerverwaltung zum Anlegen des ersten Superadmins |
| `admin/backup.php`, `admin/server-check.php` | Sicherung mit Rotation, Deployment-Prüfung |
| `admin/css/admin.css`, `admin/js/admin.js` | Oberflächengerüst ohne Framework, nur Farbwerte anpassen |

Neu zu bauen bleibt: das Rollensystem (Maria kennt keine Rollen, alle eingeloggten Nutzer dürfen alles), die JSON-Schemavalidierung beim Upload, eine eigenständige Bilderverwaltung, die anonyme Statistik und das Postfach mit Rate Limiting am öffentlichen Endpunkt.

---

## Leitplanken für alle Tickets

Diese Regeln gelten projektweit und werden in jedem Ticket vorausgesetzt.

1. **Die Schüleransicht bleibt ohne Konto und ohne Tracking-Cookies.** Lernstände liegen im `localStorage`, Serverstatistik ist ausschließlich aggregiert und nicht personenbeziehbar.
2. **Kinder tippen nie einen Freitext-Namen ein**, der den Server erreicht. Namen werden generiert. Das ist keine Spielerei, sondern der Schutz davor, dass ein Kind Klarnamen und Klasse in eine öffentliche Ansicht schreibt.
3. **Kein Rangvergleich zwischen Lernenden.** Fortschritt wird gegen das eigene frühere Ich gemessen. Begründung siehe WQ-5.5.
4. **Keine Minuspunkte, kein Zeitdruck im Lernmodus.** Fehler sind Lernanlass, nicht Strafe.
5. **Die App muss ohne Server funktionieren.** Wortlisten bleiben statische JSON-Dateien. Alles, was der Server beisteuert (Statistik, Einreichungen, Ehrentafel), ist additiv und fällt bei Ausfall lautlos weg.
6. **Zielgerät ist ein älteres Android-Smartphone im Mobilfunknetz.** Neue Assets werden lazy geladen, Bilder sind klein, Touchflächen mindestens 48 mal 48 Pixel.

---

## Epic 5, Lernkern

Rein clientseitig, kein Server nötig. Dieses Epic liefert den größten Lernwert und sollte zuerst laufen.

### WQ-5.1, Runden-Engine und vollständige Abdeckung

**Problem:** `startQuiz()` zieht aktuell `shuffle(pool).slice(0, 10)`. Bei 91 Wörtern kann ein Kind zehn Runden spielen und trotzdem Wörter nie gesehen haben. Reiner Zufall garantiert keine Abdeckung.

**Ziel:** Jede Vokabel einer Auswahl kommt garantiert dran, bei großen Listen verteilt über mehrere Runden.

**Konzept:** Pro Kombination aus Listenauswahl und Lernrichtung existiert ein Durchlauf im `localStorage` (`wq.run.<hash>`), der festhält, welche Wörter schon dran waren. Eine Runde wird nach fester Quote befüllt:

| Anteil | Quelle | Zweck |
|--------|--------|-------|
| bis 30 Prozent | fällige Wiederholungen (SRS) | Behalten sichern |
| Rest | noch nie gesehene Wörter | Abdeckung garantieren |
| Auffüllung | schwächste Wörter nach Gewicht | wenn alles gesehen ist |

**Akzeptanz:**

- [ ] Datenmodell `wq.run.<hash>` mit `unseen[]`, `seen[]`, `round`, `startedAt`, dokumentiert im Code.
- [ ] Rundenzusammenstellung nach obiger Quote, gemeinsam genutzt von Quiz, Scramble, Spelling.
- [ ] Wechsel der Listenauswahl oder Lernrichtung erzeugt einen eigenen Durchlauf, alte werden nach 90 Tagen aufgeräumt.
- [ ] Ist jedes Wort einmal dran gewesen, gilt der Durchlauf als abgeschlossen: Abschlussbildschirm mit den Optionen "Neuer Durchlauf" und "Nur die Wackelkandidaten".
- [ ] Memory bleibt ausgenommen, weil es paarweise arbeitet, zieht aber bevorzugt aus `unseen`.

**Abhängigkeiten:** keine. **Blockiert:** WQ-5.2, WQ-5.4.

### WQ-5.2, Fortschrittsanzeige mit zwei Messgrößen

**Ziel:** Das Kind sieht jederzeit, wie weit es ist. Zwei Werte, die bewusst nicht vermischt werden.

- **Abdeckung:** "Runde 3 von 10, 28 von 91 Wörtern waren schon dran."
- **Beherrschung:** "34 von 91 sitzen sicher." (Leitner-Box 4 oder 5)

**Akzeptanz:**

- [ ] Beide Werte im Kopfbereich oder auf dem Startbildschirm, klar unterschiedlich benannt.
- [ ] In der Vokabelansicht pro Karte ein Reifegrad-Indikator (Box 1 bis 5) als Farbe **und** als Text oder Symbol, Farbe nie alleiniger Informationsträger.
- [ ] Übersichtsraster über alle Wörter der Auswahl, das sich mit steigender Beherrschung sichtbar füllt.
- [ ] Werte sind pro Durchlauf zurücksetzbar, ohne den SRS-Lernstand zu löschen.

**Abhängigkeiten:** WQ-5.1.

### WQ-5.3, SRS-Gewichtung Version 2 und Leech-Schutz

**Problem im Ist-Stand:** `recordCorrect` hebt ein Wort nach einer einzigen richtigen Antwort von Box 1 auf Box 2, `recordWrong` wirft es aus jeder Box sofort auf Box 1 zurück. Das schwingt zu stark und bildet echtes Können schlecht ab.

**Ziel:** Häufig falsche Wörter kommen messbar öfter, sicher gekonnte messbar seltener, ohne dass ein Kind an einem einzelnen Wort hängen bleibt.

**Datenmodell,** Erweiterung des bestehenden `wq.srs.leitner.v1` Eintrags (fehlende Felder defaulten auf 0, Migration damit trivial):

```
{ box: 1..5, due: ms, seen: n, correct: n, wrong: n, streak: n, lastSeen: ms, leech: bool }
```

**Auswahlgewicht:**

```
gewicht = boxGewicht[box] * (1 + 0.5 * wrong) * (overdue ? 1.5 : 1.0)
boxGewicht = { 1: 8, 2: 5, 3: 3, 4: 2, 5: 1 }
```

Ziehung innerhalb einer Runde gewichtet und ohne Zurücklegen.

**Akzeptanz:**

- [ ] Aufstieg aus Box 1 erst nach zwei richtigen Antworten in Folge.
- [ ] Bei einem Fehler fällt ein Wort ab Box 4 nur eine Box zurück, nicht auf Box 1.
- [ ] Gewichtete Ziehung umgesetzt und mit einem kurzen Testskript geprüft: über 1000 simulierte Runden kommt ein Wort aus Box 1 nachweislich mehrfach häufiger als eines aus Box 5.
- [ ] **Leech-Schutz:** ab sechs Fehlern wird ein Wort als "Knackpunkt" markiert, seine Häufigkeit gedeckelt und es erscheint zuerst in einer leichteren Form (Bild- oder Emoji-Hilfe sichtbar, Quiz mit zwei statt vier Optionen).
- [ ] Knackpunkte sind in der Vokabelansicht sichtbar und einzeln zurücksetzbar.

**Abhängigkeiten:** keine, sinnvoll gemeinsam mit WQ-5.1.

### WQ-5.4, Bessere Distraktoren im Quiz

**Problem:** Falsche Antwortoptionen werden zufällig aus dem Gesamtbestand gezogen. Bei gemischten Listen steht neben "Erdbeere" dann "Arbeitet mit einem Partner zusammen". Solche Aufgaben prüfen nichts.

**Ziel:** Falschantworten sind plausibel, damit die Aufgabe tatsächlich Wortwissen prüft.

**Akzeptanz:**

- [ ] Distraktoren bevorzugt aus derselben Kategorie (`cat`) des Zielworts.
- [ ] Zweite Priorität: ähnliche Wortlänge oder gleicher Anfangsbuchstabe.
- [ ] Sätze und Einzelwörter werden nicht gemischt, ein Satz bekommt Sätze als Distraktoren.
- [ ] Fallback auf Zufall, wenn zu wenig passende Kandidaten vorhanden sind, ohne Fehler bei sehr kleinen Listen.

**Abhängigkeiten:** keine.

### WQ-5.5, Punktesystem Version 2

**Ist-Stand:** überall pauschal `addScore(10)`, unabhängig von Schwierigkeit und Lernfortschritt.

**Grundpunkte:**

| Aktion | Punkte | Begründung |
|--------|--------|------------|
| Quiz, vier Optionen | 10 | Referenzwert |
| Quiz, zwei Optionen (Hilfsmodus) | 6 | leichtere Aufgabe, weniger Ertrag |
| Memory-Paar | 8 | Wiedererkennen, nicht Abrufen |
| Scramble | 15 | Produktion mit Vorgabe |
| Spelling ohne Hilfe | 20 | freie Produktion, höchste Anforderung |
| Spelling mit Hilfe | 10 | Hilfe genutzt, Ertrag halbiert |

**Zuschläge, alle additiv, nie negativ:**

| Zuschlag | Punkte | Begründung |
|----------|--------|------------|
| Serie ab der dritten richtigen Antwort | plus 2 je Antwort, maximal plus 10 | belohnt Konzentration, gedeckelt gegen Hochschaukeln |
| Erstkontakt, ein Wort zum ersten Mal richtig | plus 5 | belohnt Abdeckung |
| **Comeback, ein vorher falsches Wort sitzt jetzt** | **plus 10** | **wichtigster Bonus des Systems** |
| Runde abgeschlossen | plus 25 | unabhängig von der Trefferquote |
| Durchlauf einer Liste komplett | plus 100 | belohnt Ausdauer |

Der Comeback-Bonus ist der Kern: Er macht den Fehler zur Voraussetzung für die höchste Einzelbelohnung. Ein Kind, das viel falsch macht und dranbleibt, kann mehr Punkte sammeln als eines, das alles sofort kann. Genau diese Umkehrung braucht die Zielgruppe.

Der Rundenabschluss-Bonus ist bewusst leistungsunabhängig, damit auch eine Runde mit drei von zehn Treffern sichtbar etwas einbringt.

**Akzeptanz:**

- [ ] Punktetabelle zentral als Konstante, nicht verstreut im Code.
- [ ] Comeback-Erkennung nutzt `wrong > 0` aus dem SRS-Eintrag.
- [ ] Keine Minuspunkte an irgendeiner Stelle, kein Zeitbonus im Lernmodus.
- [ ] Punktezuwachs wird kurz und sichtbar begründet ("Comeback, plus 10"), damit das System nachvollziehbar ist.
- [ ] Feieranimationen respektieren `prefers-reduced-motion`.

**Abhängigkeiten:** WQ-5.3 für die Comeback-Erkennung.

### WQ-5.6, Spielername und Avatar

**Ziel:** Jede Spielerin und jeder Spieler bekommt eine charmante, generierte Identität. Autonomie und Wiedererkennung ohne jedes personenbezogene Datum.

**Akzeptanz:**

- [ ] Namensgenerator aus kuratierten Listen, Adjektiv plus Tier, etwa "Flinker Fuchs", "Kluge Krähe", "Mutiger Maulwurf". Mindestens 30 mal 30 Kombinationen.
- [ ] Wortlisten werden auf unglückliche Kombinationen geprüft, bevor sie ausgeliefert werden.
- [ ] Avatar ist das passende Tier-Emoji, damit keine zusätzliche Ladezeit entsteht.
- [ ] Name bleibt im `localStorage` erhalten, ist also über Sitzungen hinweg "der eigene Name", mit Knopf "Neuen Namen würfeln".
- [ ] **Kein Freitextfeld für den Namen.** Nur Würfeln.
- [ ] Name und Avatar erscheinen im Kopfbereich und auf dem Rundenabschluss.

**Abhängigkeiten:** keine.

---

## Epic 6, Audio und neue Spielmodi

### WQ-6.1, Aussprache über die Web Speech API

**Begründung:** Die App hat aktuell keinen Ton. Ein Vokabeltrainer für Englisch, der die Aussprache nicht vermittelt, liefert das halbe Produkt. `speechSynthesis` ist in jedem aktuellen Browser eingebaut, kostet nichts, braucht keine Bandbreite und funktioniert offline.

**Akzeptanz:**

- [x] Hilfsfunktion `speak(text, lang)` mit `en-GB` für Englisch und `de-DE` für Deutsch.
- [x] Lautsprecher-Knopf an jedem englischen Wort in der Vokabelansicht, im Quiz-Ergebnis und im Spelling-Feedback.
- [x] Feature-Erkennung: fehlt eine englische Stimme, verschwinden die Knöpfe lautlos, keine Fehlermeldung.
- [x] Globaler Ton-Schalter, Einstellung bleibt gespeichert.
- [x] Knöpfe sind tastaturbedienbar und beschriftet ("Aussprache von apple anhören").

**Abhängigkeiten:** keine. **Blockiert:** WQ-6.2.

### WQ-6.2, Hör-Quiz als fünftes Spiel

**Ziel:** Neuer Spielmodus, der gehörtes Wort und Bedeutung verknüpft.

**Akzeptanz:**

- [x] Modus "Hören": Wort wird vorgelesen, das Kind wählt aus vier Bildern oder Übersetzungen.
- [x] Zweite Stufe "Diktat": Wort wird vorgelesen, das Kind tippt es, Auswertung wie bei Spelling.
- [x] Wiederholen-Knopf, beliebig oft, ohne Punktabzug.
- [x] Modus erscheint nur, wenn eine englische Stimme verfügbar ist.
- [x] Anbindung an Runden-Engine und SRS wie die übrigen Spiele.

**Abhängigkeiten:** WQ-6.1, WQ-5.1.

### WQ-6.3, Beispielsätze im Schema und Lückensatz-Modus

**Ziel:** Die Kategorie "Sätze und Aufgaben" der NHG-Listen wird bisher nur als Übersetzungspaar genutzt. Beispielsätze aus dem Buch ("This is a perfect ___ to live in.") trainieren das Wort im Kontext, eine Stufe über der Einzelvokabel.

**Akzeptanz:**

- [x] Schema-Erweiterung um `example` und `exampleDe`, beide optional, README aktualisiert.
- [x] Neuer Modus "Lückensatz": Satz mit Lücke, Auswahl oder Eingabe des fehlenden Worts.
- [x] Modus erscheint nur, wenn genügend Wörter der Auswahl einen Beispielsatz haben.
- [x] Beispielsätze werden in der Vokabelansicht angezeigt, auch ohne den Spielmodus.

**Abhängigkeiten:** keine, profitiert von WQ-5.4.

### WQ-6.4, Kategorien-Sortieren (optional)

**Ziel:** Schnell gebauter Modus für jüngere Kinder, ohne Tippen. Wörter werden in Körbe einsortiert, die aus dem vorhandenen `cat`-Feld stammen.

**Akzeptanz:**

- [ ] Zwei bis vier Körbe aus den Kategorien der aktuellen Auswahl.
- [ ] Bedienung per Antippen (Wort wählen, Korb wählen), nicht ausschließlich per Drag and Drop, weil Drag auf alten Touchgeräten unzuverlässig ist.
- [ ] Modus erscheint nur bei Listen mit mindestens zwei Kategorien.

**Abhängigkeiten:** keine.

---

## Epic 7, Serverfundament und Admincenter

### WQ-7.1, Technische Basis

**Entscheidung:** PHP 8 plus SQLite. Begründung: Plesk hat PHP bereits, SQLite braucht keinen Datenbankserver, keine Zugangsdaten und keine Plesk-Einrichtung. Die Datenbank ist eine einzelne Datei, die Sicherung ist ein Dateikopie. Der Umfang dieses Projekts erreicht keine Grenze, an der MySQL nötig wäre.

**Akzeptanz:**

- [x] Verzeichnis `api/` mit schlanken Endpunkten, `admin/` für die Oberfläche.
- [x] Datenbankdatei und Einreichungen liegen **außerhalb** von `httpdocs` oder sind per `.htaccess` gesperrt.
- [x] Schema-Migrationen als nummerierte SQL-Dateien, damit ein Deploy reproduzierbar bleibt.
- [x] Alle Endpunkte antworten auch im Fehlerfall mit sauberem JSON, nie mit einem PHP-Stacktrace.
- [x] Der Client behandelt jeden API-Ausfall als "nicht vorhanden" und läuft normal weiter.

**Abhängigkeiten:** keine. **Blockiert:** WQ-7.2 bis WQ-7.5, Epic 8.

### WQ-7.2, Anonyme Nutzungsstatistik

**Ziel:** Du siehst, was genutzt wird, ohne irgendein personenbeziehbares Datum zu speichern.

**Erfasste Ereignisse** (Zähler, nach Tag und Liste aggregiert):

- Liste geladen
- Spielmodus gestartet, Runde abgeschlossen
- Trefferquote je Liste
- **Fehlerhäufigkeit je Vokabel, über alle Spielenden aggregiert**

Der letzte Punkt ist der wertvollste: Er zeigt dir, welche Vokabeln durchgängig schwierig sind. Das sind fast immer Wörter mit mehrdeutiger Übersetzung, schlechtem Bild oder unglücklichen Distraktoren, also konkrete Verbesserungshinweise für die Listen.

**Akzeptanz:**

- [x] Client sendet Ereignisse als "fire and forget", ohne auf die Antwort zu warten, ohne Blockade der Oberfläche.
- [x] Server speichert **keine IP-Adresse, keine Sitzungskennung, keine Uhrzeit feiner als der Tag**. Nur Zähler.
- [x] Damit entsteht kein Personenbezug, es ist keine Einwilligung nötig. Trotzdem: Abschnitt in der Datenschutzerklärung und ein Schalter "Nutzungsstatistik aus" in den Einstellungen.
- [x] Keine Statistik von `localhost` und aus dem Admincenter.

**Abhängigkeiten:** WQ-7.1.

### WQ-7.3, Anmeldung und Rollen

**Akzeptanz:**

- [x] Rollen `superadmin` (verwaltet Admins) und `admin` (nur Inhalte).
- [x] Passwörter ausschließlich als `password_hash()`, Argon2id bevorzugt. Keine Zugangsdaten im Quelltext oder im Repository.
- [x] Sitzung über PHP-Session. Das Sitzungscookie ist technisch notwendig und betrifft nur die Administration, nicht die Schüleransicht.
- [x] CSRF-Token auf jedem Formular, Login mit Rate Limit, Zugriff nur über HTTPS.
- [x] Superadmin kann Admins anlegen, deaktivieren und löschen. Der letzte Superadmin lässt sich nicht löschen.

**Abhängigkeiten:** WQ-7.1.

### WQ-7.4, Wortlisten verwalten

**Ziel:** Listen online anlegen und pflegen, statt per FTP.

**Akzeptanz:**

- [x] Übersicht aller Listen mit Titel, Wortzahl, Status (sichtbar oder versteckt).
- [x] Upload einer JSON-Datei **mit Schemaprüfung**: Pflichtfelder, Typen, Größe, maximale Wortzahl. Ungültige Dateien werden mit verständlicher Meldung abgelehnt, nie ungeprüft gespeichert.
- [ ] Einfacher Tabelleneditor für `en`, `de`, `emoji`, `cat`, damit Tippfehler ohne FTP korrigierbar sind.
- [x] Vorschau vor dem Veröffentlichen.
- [x] Hochgeladene Dateien liegen in einem Verzeichnis ohne PHP-Ausführung, Dateinamen werden servergeneriert.
- [x] Jede Ausgabe von Listeninhalten im Admincenter ist HTML-escaped, wie in der Hauptanwendung bereits umgesetzt.

**Abhängigkeiten:** WQ-7.1, WQ-7.3.

### WQ-7.5, Bilder verwalten

**Umgesetzt.** `admin/bilder.php` mit `api/lib/bilder.php`.

**Akzeptanz:**

- [x] Upload einzelner Bilder, Zuordnung zu einer Vokabel.
- [x] Serverseitige Prüfung des echten Bildtyps, nicht nur der Dateiendung. Umwandlung nach WebP, Begrenzung auf 256 Pixel Kantenlänge.
- [x] Übersicht "Vokabeln ohne Visualisierung" als Arbeitsliste.
- [x] Löschen entfernt Datei und Verweis gemeinsam.

**So arbeitet die Seite:** Oben wird eine Wortliste gewählt. Darunter stehen
alle Wörter ohne Bild und ohne Emoji mit je einem Uploadfeld, das ist die
Arbeitsliste. Es folgen die Wörter mit Bild als Kachelraster, dort lässt sich
eine Zuordnung wieder lösen. Ganz unten liegen alle abgelegten Dateien, dort
wird gelöscht.

**Wichtige Entscheidungen:**

- **Die hochgeladenen Bytes werden nie ausgeliefert.** GD erzeugt aus dem Bild
  ein neues WebP. Damit verschwinden EXIF-Reste, eingebettete Fremddaten und
  Polyglot-Konstruktionen restlos, unabhängig davon, was jemand hochlädt.
- **SVG ist ausgeschlossen.** SVG ist ein Dokumentformat mit Skriptfähigkeit
  und würde beim direkten Aufruf im Ursprung der App laufen.
- **Der Dateiname kommt vom Server**, gebildet aus dem englischen Wort, auf
  Kleinbuchstaben, Ziffern und Bindestriche reduziert und bei Namensgleichheit
  durchnummeriert.
- **Emoji vor Bild.** Die Arbeitsliste zeigt nur Wörter ohne beides. Ein Emoji
  kostet keine Ladezeit und trägt bei den meisten Alltagswörtern genauso weit.
- **Gelöscht wird nur, worauf keine Liste mehr zeigt.** Vor dem Löschen prüft
  der Server alle Wortlisten auf die Adresse der Datei.
- **`img/auto/` liegt nicht im Repository** und gehört deshalb in die Sicherung
  des Servers, siehe `DEPLOY.md`.

**Abhängigkeiten:** WQ-7.4. **Verwandt:** Epic 9.

---

## Epic 8, Einreichungen und Gemeinschaft

### WQ-8.1, Einreichung per E-Mail (Stufe 1)

**Umgesetzt, aber anders als geplant: aufgegangen in WQ-8.2.**

Der E-Mail-Weg war als Überbrückung gedacht, für die Zeit, in der es noch
keine Serverfunktion gab. Die gibt es inzwischen. Ein Formular ist an jeder
Stelle besser: Es prüft sofort, es führt durch die Formate, es erzeugt eine
Warteschlange statt eines Postfachs, und es zwingt uns nicht, eine
Kontaktadresse öffentlich auf eine Seite zu schreiben, die Maschinen absuchen.

Aus dem Ticket übernommen und erledigt:

- [x] Öffentliche Seite "Wortliste einreichen" mit Formatbeschreibung und
      Vorlagen zum Herunterladen (JSON und CSV), siehe `vorlagen/`.
- [x] Hinweis, dass auch ein Foto der Buchseite genügt, samt Erkennung direkt
      auf der Seite.
- [x] Verlinkt aus dem Fussbereich der App.

**Abhängigkeiten:** keine.

### WQ-8.2, Einreichungs-Postfach im Admincenter (Stufe 2)

**Ziel:** Formular auf der Website, Eingang landet in einer Warteschlange, du bekommst Bescheid.

**Umgesetzt.** `einreichen.php` mit `api/lib/einreichung.php`.

**Akzeptanz:**

- [x] Öffentliches Formular ohne Anmeldung: Name (freiwillig), Kontakt (freiwillig), Datei oder Foto, Bemerkung.
- [x] Angenommen werden JSON, CSV, XLSX und Fotos. Prüfung von Typ und Größe serverseitig.
- [x] Keine Datei bleibt liegen: Eingereichtes wird sofort in die Datenbank überführt, Fotos verlassen das Gerät gar nicht erst.
- [x] Spamschutz ohne CAPTCHA: verstecktes Honeypot-Feld, Mindestzeit zwischen Formularaufruf und Absenden, Taktbremse pro Adresse. Die zur Begrenzung genutzte Adresse wird nicht dauerhaft gespeichert.
- [x] Benachrichtigung per E-Mail an den Betreiber über authentifiziertes SMTP, nicht über `mail()`.
- [x] Unabhängig von der E-Mail zeigt das Admincenter einen Zähler offener Einreichungen. Die Benachrichtigung darf ausfallen, ohne dass etwas verloren geht.
- [x] Ablauf im Admincenter: ansehen, bearbeiten, veröffentlichen oder ablehnen.

**Wichtige Entscheidungen:**

- **Kein Ablageverzeichnis für Uploads.** Das ursprüngliche Ticket sah ein
  Verzeichnis ohne PHP-Ausführung vor. Gebraucht wird es nicht: Eine
  hochgeladene Tabelle wird sofort gelesen, geprüft und als Einreichung in die
  Datenbank geschrieben. Die hochgeladene Datei selbst überlebt die Anfrage
  nicht. Damit entfallen Dateirechte, Pfadprüfungen und Aufräumarbeit, und es
  gibt nichts, was jemand später direkt aufrufen könnte.
- **Fotos werden nicht hochgeladen.** Die Texterkennung läuft im Browser, wie
  schon bei WQ-9.5. Abgeschickt wird nur die Tabelle, die der Mensch bestätigt
  hat. `ocr.js` liegt deshalb jetzt im Wurzelverzeichnis und wird von beiden
  Seiten genutzt, der öffentlichen und der im Admincenter.
- **Kein CSRF-Token.** Ein Token bräuchte eine Sitzung und damit ein Cookie für
  jede Besucherin, was der cookiefreien Auslegung der öffentlichen Seiten
  widerspricht. Es gibt hier auch keine Anmeldung und keinen Zustand, den ein
  fremder Absender missbrauchen könnte. Was wirklich droht, ist Spam, und
  dagegen wirken Honigtopf, Mindestzeit und Taktbremse.
- **Die Adresse der Absenderin wird nie gespeichert**, nur ein HMAC, dessen
  Schlüssel den Tag enthält, und auch der fliegt nach 24 Stunden raus.

- **SMTP statt `mail()`.** `mail()` übergibt an ein lokales Sendeprogramm,
  dessen Absenderadresse nicht zur Domain passt. Das landet zuverlässig im
  Spamordner und liefert im Fehlerfall nichts Brauchbares zurück. Der Versand
  über einen angemeldeten Mailserver ist nachvollziehbar und scheitert laut.
- **Die Mail ist absichtlich karg:** Nummer, Titel, Umfang, Anzahl offener
  Einreichungen. Name, Kontakt und Bemerkung bleiben im Admincenter und gehen
  nicht über fremde Server.
- **Ohne Zugangsdaten passiert einfach nichts.** Der Versand ist dann kein
  Fehlerfall, sondern abgeschaltet. Die Einrichtung steht in `DEPLOY.md`.

**Abhängigkeiten:** WQ-7.1, WQ-7.3, WQ-7.4.

### WQ-8.3, Ehrentafel und Gemeinschaftsziel

**Ziel:** Der soziale Reiz der Highscore-Idee, ohne den Rangvergleich. Begründung siehe Abschnitt "Zur Highscore-Frage" unten.

**Akzeptanz:**

- [ ] **Ehrentafel ohne Rang:** Wer einen Listendurchlauf abschließt, erscheint mit Avatar und generiertem Namen auf einer Tafel der letzten Abschlüsse ("Flinker Fuchs hat NHG 1 Welcome komplett geschafft"). Kriterium ist Abschluss, nicht Geschwindigkeit, also für alle erreichbar.
- [ ] **Gemeinschaftszähler:** "Diese Woche wurden hier zusammen 3.200 Vokabeln geübt." Alle zahlen ein, niemand verliert.
- [ ] Übertragen werden ausschließlich generierter Name, Avatar-Emoji, Listenkennung und Zeitstempel. Keine Punktzahl, keine Dauer, keine Kennung des Geräts.
- [ ] Serverseitige Plausibilitätsprüfung gegen offensichtlichen Missbrauch, plus Möglichkeit, einen Eintrag im Admincenter zu entfernen.
- [ ] Die Tafel ist abschaltbar, falls sie sich im Betrieb als störend erweist.

**Abhängigkeiten:** WQ-7.1, WQ-5.6, WQ-5.1.

---

## Epic 9, KI-Visualisierungen

### Grundsatzentscheidungen

**Bilder werden nicht zur Laufzeit im Browser des Kindes erzeugt.** Gründe: Kosten pro Aufruf, Wartezeit, ein Schlüssel im Clientcode wäre öffentlich, und auf einer Mobilfunkverbindung ist jede Erzeugung eine Zumutung. Bilder entstehen einmal vorab, werden als Datei abgelegt und wie bisher über das Feld `img` referenziert.

**Emoji bleibt der Standard.** Ein Emoji kostet null Byte und deckt einen großen Teil des Grundwortschatzes ab. KI-Bilder schließen die Lücke dort, wo kein passendes Emoji existiert, etwa bei "cousin", "target task" oder "wheelchair" in bestimmten Kontexten. Der Preis dafür ist ein leicht uneinheitliches Gesamtbild. Für die Zielgruppe wiegt kurze Ladezeit schwerer als stilistische Geschlossenheit.

**Nicht jedes Wort ist darstellbar.** Funktionswörter wie "so", "their", "really", "not", "away" ergeben als Bild bestenfalls Rätsel. Dafür ist der Beispielsatz aus WQ-6.3 die bessere Antwort, nicht ein erzwungenes Bild.

### WQ-9.1, Wortklassifizierung

**Akzeptanz:**

- [ ] Optionales Feld `vis` je Vokabel mit den Werten `concrete`, `action`, `abstract`.
- [ ] Nur `concrete` und `action` gehen in die Bilderzeugung, `abstract` bekommt den Beispielsatz.
- [ ] Erstbefüllung halbautomatisch, Ergebnis wird von Hand durchgesehen.

**Abhängigkeiten:** keine.

### WQ-9.2, Erzeugungs-Pipeline als lokales Skript

**Akzeptanz:**

- [ ] Skript (Node oder Python) liest eine Wortliste, ermittelt Wörter ohne Bild und mit `vis` ungleich `abstract`, erzeugt fehlende Bilder.
- [ ] **Fester Stil-Prompt als Vorlage**, damit alle Bilder wie ein Satz wirken: flache Vektorillustration, klares Motiv, heller einfarbiger Hintergrund, keine Szene, keine Personen mit erkennbaren Gesichtern.
- [ ] **Explizit kein Text im Bild.** Bildmodelle schreiben gerne Wörter mit, und ein Bild, das "apple" beschriftet, verrät die Lösung. Negative Vorgabe im Prompt plus Kontrolle in der Durchsicht.
- [ ] Ausgabe als WebP, maximal 256 Pixel, Zielgröße unter 25 Kilobyte je Bild.
- [ ] Dateiname aus der stabilen `_wqId`, Ablage in `img/auto/`.
- [ ] Das Skript aktualisiert das `img`-Feld der JSON-Datei und ist mehrfach ausführbar, ohne vorhandene Bilder neu zu erzeugen.
- [ ] API-Schlüssel kommt aus einer Umgebungsvariable, niemals aus dem Repository.
- [ ] Kostenschätzung im Protokoll vor dem Lauf, Abbruchmöglichkeit.

**Abhängigkeiten:** WQ-9.1. Läuft unabhängig von allen Serverarbeiten.

### WQ-9.3, Durchsicht vor Veröffentlichung

**Begründung:** Für "cousin" oder "target task" liefert ein Bildmodell zuverlässig Merkwürdiges. Für Unterrichtsmaterial ist eine menschliche Durchsicht nicht verhandelbar.

**Akzeptanz:**

- [ ] Übersichtsseite mit Wort, Übersetzung und Bild nebeneinander, Aktionen "behalten", "neu erzeugen", "verwerfen".
- [ ] Zuerst als lokale HTML-Seite, später im Admincenter (WQ-7.5).
- [ ] Verworfene Bilder werden gelöscht, das Wort fällt auf Emoji oder Beispielsatz zurück.

**Abhängigkeiten:** WQ-9.2.

### WQ-9.4, Auslieferung an den Client

**Akzeptanz:**

- [ ] Bilder werden lazy geladen (`loading="lazy"`), nie alle auf einmal.
- [ ] In der EN nach DE Richtung bleibt die bestehende Regel erhalten, dass das Bild erst nach der Entscheidung erscheint.
- [ ] Service Worker legt einmal geladene Bilder ab, damit die zweite Runde ohne Netz auskommt.
- [ ] Fällt ein Bild aus, greift der vorhandene Buchstaben-Kreis als Rückfallebene, kein kaputtes Bildsymbol.
- [ ] Hinweis im Impressum oder der Datenschutzerklärung, dass Illustrationen KI-erzeugt sind.

**Abhängigkeiten:** WQ-9.2.

### WQ-9.5, Foto zu Liste (Ausblick)

**Ziel:** Eine Lehrkraft fotografiert die Wortschatzseite ihres Lehrwerks, lädt das Foto hoch, daraus entsteht ein Listenentwurf im Postfach. Genau dieser Weg wurde für die NHG-Listen bereits von Hand gegangen und funktioniert gut.

Das ist der größte Hebel, um den Bestand an Listen wachsen zu lassen, ohne dass du selbst tippst.

**Akzeptanz:**

- [x] Foto-Upload über das Einreichungsformular (WQ-8.2).
- [x] Extraktion in einen Listenentwurf, immer mit Durchsicht, nie automatisch veröffentlicht.
- [x] Unsichere Stellen werden markiert statt geraten.
- [ ] Hinweis auf das Urheberrecht: eingereichte Wortlisten dienen dem eigenen Unterricht, Lehrwerksinhalte werden nicht öffentlich nachgedruckt.

**Abhängigkeiten:** WQ-8.2, WQ-9.3.

---

## Epic 10, Langfristige Features

Ideensammlung, noch keine Tickets. Sortiert nach erwartetem Nutzen.

1. **Ton überall.** Aussprache an jedem Wort, Hörmodi. Größte inhaltliche Lücke im Ist-Stand. Siehe Epic 6.
2. **Foto zu Liste als Selbstbedienung** für Lehrkräfte. Größter Hebel für Inhaltswachstum. Siehe WQ-9.5.
3. **Klassenmodus mit Code.** Eine Lehrkraft erzeugt einen Code, die Klasse übt denselben Satz, die Lehrkraft sieht den Fortschritt der Klasse als Summe, nie einzelne Kinder. Damit ist der Modus von der Bauart her datenschutzkonform. Hier darf auf ausdrücklichen Wunsch der Lehrkraft auch ein Wettbewerb stattfinden, weil er dann pädagogisch begleitet ist.
4. **Arbeitsblätter als PDF** aus jeder Liste erzeugen. Lehrkräfte arbeiten weiterhin viel auf Papier, und der vorhandene `pdf-creator` Skill deckt das ab.
5. **Herkunftssprachen.** Nicht nur Englisch nach Deutsch, sondern auch Deutsch nach Türkisch, Arabisch, Ukrainisch. Für die Zielgruppe ist der Brückenschlag zur Familiensprache ein echter Mehrwert und ein Alleinstellungsmerkmal.
6. **Lernstand mitnehmen ohne Konto.** Export und Import des Fortschritts als Datei oder QR-Code, damit ein Kind zwischen Schultablet und Handy wechseln kann.
7. **Barrierefreiheitspaket.** Legasthenie-Modus mit größerer Laufweite und optionaler Schriftart, vollständige Tastaturbedienung, `prefers-reduced-motion`, Ansagen für Screenreader bei Spielereignissen.
8. **Adaptive Schwierigkeit.** Zwei statt vier Antwortoptionen, wenn die Trefferquote einbricht. Zielkorridor 70 bis 80 Prozent Erfolg.
9. **Vollständiger Offlinebetrieb** einschließlich zwischengespeicherter Listen und Bilder. Wichtig für Kinder mit knappem Datenvolumen.

---

## Zur Highscore-Frage

Die Idee zerfällt in zwei Teile, die getrennt bewertet werden müssen.

**Generierter Name plus Avatar: uneingeschränkt ja.** Das schafft Identität und Wiedererkennung, kostet nichts, und es ist zugleich eine Schutzmassnahme. Solange nur gewürfelt und nie getippt wird, kann kein Kind seinen Klarnamen samt Klasse in eine öffentlich sichtbare Ansicht schreiben. Genau deshalb steht in den Leitplanken, dass es kein Freitextfeld geben darf.

**Globale Rangliste: davon rate ich ab.** Beide einschlägigen Projektskills führen Ranglisten und sozialen Vergleich als harte Anti-Pattern für diese Zielgruppe: Scham, Angst und Rückzug bei genau den Kindern, die die App am nötigsten haben. Wer ohnehin Schwierigkeiten hat, landet zuverlässig unten und lernt daraus vor allem, dass er unten steht. Dazu kommt ein praktisches Problem: Eine rein clientseitige App kann jede Punktzahl frei behaupten. Die Spitze der Liste gehört nach kurzer Zeit dem Kind, das die Entwicklerwerkzeuge gefunden hat, und das entwertet die Tafel für alle anderen.

**Was den Reiz erhält, ohne zu schaden** (umgesetzt in WQ-8.3):

- Die **Ehrentafel** zeigt weiterhin Namen und Avatare öffentlich, aber das Kriterium ist der abgeschlossene Durchlauf, nicht die Punktzahl. Jedes Kind kann dort ankommen, es dauert nur unterschiedlich lange.
- Der **Gemeinschaftszähler** bedient das Bedürfnis, Teil von etwas Größerem zu sein, ohne dass jemand verliert.
- Die **persönliche Bestleistung** bleibt der eigentliche Vergleichsmassstab, gegen das eigene frühere Ich.
- Echten Wettbewerb gibt es später im **Klassenmodus**, auf ausdrücklichen Wunsch einer Lehrkraft und in deren Verantwortung.

---

## Empfohlene Reihenfolge

| Phase | Tickets | Warum hier |
|-------|---------|-----------|
| 1 | WQ-5.1, 5.2, 5.3, 5.4 | Größter Lernwert, rein clientseitig, keine Infrastruktur nötig |
| 2 | WQ-5.5, 5.6, WQ-8.1 | Motivation und Identität, Einreichung per E-Mail nebenbei erledigt |
| 3 | WQ-6.1, 6.2 | Ton schließt die größte inhaltliche Lücke |
| 4 | WQ-9.1, 9.2, 9.3 | Bildpipeline, läuft lokal und unabhängig vom Server |
| 5 | WQ-7.1, 7.2, 7.3 | Serverfundament, Statistik, Anmeldung |
| 6 | WQ-7.4, 7.5, WQ-8.2 | Admincenter mit Inhalt und Postfach |
| 7 | WQ-6.3, 6.4, WQ-8.3, WQ-9.4 | Ausbau, Ehrentafel, Bildauslieferung |
| 8 | Epic 10 | Langfristiges nach Bedarf |

Phase 1 und 2 sind ohne jede Serveränderung machbar und verbessern die App bereits spürbar. Epic 9 kann jederzeit parallel laufen, weil das Erzeugungsskript lokal arbeitet.
