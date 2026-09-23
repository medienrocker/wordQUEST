# wordQUEST, Bildschirmfotos für die Anleitungen

Alle Bilder in `docs/bilder/` entstehen reproduzierbar auf dem eigenen
Rechner. Nichts davon wird von Hand abfotografiert, sonst weicht beim
nächsten Mal Fenstergröße, Spielername und Punktestand ab.

Danach die PDFs neu bauen:

```bash
python scripts/handreichung.py
```

---

## Voraussetzungen

- Chrome (anderer Pfad: `WQ_CHROME` setzen)
- PHP mit GD für die Serverseiten
- Python mit `reportlab` und `Pillow` für die PDFs

---

## 1. Lokalen Server starten

Im Projektverzeichnis:

```bash
php -d extension=gd -S 127.0.0.1:8199 -t .
```

Der Port 8199 ist fest verdrahtet. Ein anderer geht über `WQ_BASIS`.

Der eingebaute PHP-Server liest keine `.htaccess`. Deshalb ist `scripts/`
hier erreichbar, auf dem richtigen Server dagegen nicht.

---

## 2. Bilder der App

`scripts/bildschirmfoto.html` lädt die App in einen Rahmen fester
Telefongröße, bringt sie über `?s=…` in einen bestimmten Zustand und scrollt
den passenden Bereich nach oben.

```bash
bash scripts/bildschirmfoto.sh alle
```

Oder einzeln:

```bash
bash scripts/bildschirmfoto.sh quiz
```

Die Datei landet als `docs/bilder/app-<zustand>.png`.

| Zustand | Was im Bild steht |
|---|---|
| `start` | Startbildschirm mit Kopf, Fortschritt und Reitern |
| `menue` | alle neun Spiele als Kacheln (breiter aufgenommen) |
| `listen` | offene Wortlistenauswahl |
| `quiz` | eine Quizfrage |
| `falsch` | Rückmeldung nach einer falschen Antwort |
| `ueben` | Üben und Diagnose, oberer Teil |
| `adaptiv` | Hilfe bei schweren Runden und Lesehilfe |
| `klasse` | Eingabe des Klassencodes |
| `punktekarte` | die drei Knöpfe zum Zurücksetzen |
| `punkte` | die Rückfrage vor dem Zurücksetzen |
| `lernstand` | Lernstand mitnehmen mit QR-Code |
| `vokabeln` | Vokabelliste mit Bildern |
| `sprache` | Vokabelliste mit türkischer Zusatzsprache |
| `tafel` | Ehrentafel |

### Drei Fallen, die beim Bauen Zeit gekostet haben

- **Ein wiederverwendetes Chrome-Profil blockiert den zweiten Start.** Dann
  entsteht gar kein Bild, der Aufruf läuft nur in den Zeitablauf. Das Skript
  nimmt deshalb je Aufnahme ein frisches Profil und räumt es hinterher weg.
- **Der Rahmen braucht eine feste Pixelbreite.** Mit `100vw` legt der
  kopflose Browser die äußere Seite mit einer anderen Breite aus, als das
  Bild später breit ist, und rechts fehlt ein Stück.
- **Träge geladene Bilder kommen nie an**, solange das Fenster verborgen ist.
  Die Hilfsseite setzt sie vor der Aufnahme auf `eager`.

---

## 3. Bilder der Serverseiten

`klasse.php` und `einreichen.php` brauchen keine Anmeldung und lassen sich
direkt aufnehmen. Sie senden allerdings `X-Frame-Options: DENY`, können also
nicht in den Rahmen der Hilfsseite. Also direkt die Adresse fotografieren,
etwa in Schreibtischbreite 900 Pixel.

Damit die Bilder etwas zeigen, brauchen sie Daten. Für die Klassenübersicht
genügt eine lokal angelegte Klasse mit ein paar Beitritten und Tageszählern,
für die Ehrentafel ein paar Einträge, für die Seite Wortlisten eine offene
Einreichung. Alles über kleine Wegwerfskripte gegen `.localdata/`.

### Admincenter

Die Adminseiten brauchen eine Sitzung, und das Sitzungsplätzchen überlebt den
Start eines kopflosen Browsers nicht. Jede Aufnahme muss sich also selbst
anmelden.

Dafür genügt eine kleine Hilfsseite im Projektverzeichnis, die per `fetch`
das CSRF-Merkmal von `/admin/` holt, die Zugangsdaten als POST hinterherschickt
und dann auf die Zielseite springt. **Diese Datei gehört nicht ins Repo.**
Sie ist nach der Aufnahme zu löschen, und die Zugangsdaten stehen nicht darin,
sondern kommen aus der Adresse.

Für Abschnitte weiter unten auf einer langen Seite: hoch aufnehmen, etwa
1000 mal 2600, und den gewünschten Ausschnitt danach mit GD oder Pillow
herausschneiden.

---

## 4. Was in den Bildern stehen soll

- Immer derselbe Spieler: **Flinker Fuchs**, 340 Punkte, Bestleistung 520.
  Die Hilfsseite leert den Speicher vorher, sonst schleppt ein Bild den Stand
  des vorigen mit.
- Keine echten Namen von Kindern, keine echten Klassencodes vom Server.
- Für die Bilderseite im Admincenter eine Liste mit Bildern aus `img/wq/`
  wählen. Listen mit Adressen auf den Bildhost zeigen beim Aufnehmen ohne
  Netz nur die Ersatztexte.
