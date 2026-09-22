# wordQUEST

Kleines Vokabel-Lernspiel (Quiz, Memory, Word Scramble, Spelling Bee) — komplett statisch, läuft in jedem Browser.
Vokabelsätze werden zur Laufzeit aus dem Ordner `wordlists/` geladen; **mehrere Listen** sind per Checkbox kombinierbar.
In der **Vokabelansicht** kannst du einzelne Wörter für die Spiele aktivieren oder deaktivieren. Unter **Üben** werden falsch beantwortete Wörter lokal gespeichert und als **Übungs-Quiz** wiederholt (ohne Login).

---

## Deployment (wordquest.bildungssprit.de)

Der Deploy läuft über **git push lokal, git pull auf dem Server**. Kein FTP.
Der Docroot ist das Git-Checkout selbst, es gibt keinen Build-Schritt.

```bash
bash scripts/deploy.sh
```

Vollständige Anleitung, Serverfakten und Fallstricke: **[docs/DEPLOY.md](docs/DEPLOY.md)**.

**Vor jedem Push:** `CACHE_VERSION` in [`sw.js`](sw.js) hochzählen, sonst behalten
installierte Geräte ihre alte Fassung.

---

## Progressive Web App (PWA)

- **Installation:** In Chromium-basierten Browsern erscheint „App installieren“, sobald `manifest.webmanifest` und `sw.js` mit ausgeliefert werden (HTTPS oder `localhost`).
- **Aktualität der Wortlisten:** Der Service Worker cached die statische Shell (`index.html`, Styles, Icons). Inhalte unter `wordlists/` werden **nicht** dauerhaft gecacht, damit neue JSON-Dateien nach einem Upload sichtbar bleiben.
- **MIME-Typ:** `.htaccess` setzt `application/manifest+json` für `.webmanifest`. Falls nötig, in Plesk unter Apache-Einstellungen prüfen.

---

## Neue Wortliste hinzufügen

1. JSON-Datei nach dem Schema unten erstellen (z. B. `schule.json`) und in `wordlists/` ablegen
2. Dateinamen in `wordlists/index.json` ergänzen (nur für den lokalen Test nötig, auf dem Server erkennt `index.php` neue Dateien selbst)
3. Committen, pushen, auf dem Server `bash scripts/deploy.sh`

**Kein Rebuild, kein Server-Restart nötig.**

---

## Wortlisten einreichen: welche Formate gehen?

Zwei Wege führen hinein: die öffentliche Seite **[einreichen.php](einreichen.php)**,
die aus dem Fussbereich der App verlinkt ist und keine Anmeldung braucht, und
das Admincenter unter **Wortlisten**. Beide nehmen dieselben Formate an und
laufen durch dieselbe Prüfung:

| Format | Hinweis |
|--------|---------|
| **CSV** | Aus Excel mit "Speichern unter, CSV". Semikolon, Komma und Tabulator werden alle erkannt |
| **Excel (.xlsx)** | Erstes Tabellenblatt, zwei Spalten |
| **Eingefügte Tabelle** | Zeilen aus Excel oder Word direkt ins Textfeld kopieren |
| **Einfache Zeilen** | `apple - Apfel`, eine je Zeile |
| **JSON** | Nach dem Schema unten, bringt den Titel selbst mit |

Erwartet werden zwei Spalten: **englisch, deutsch**. Eine Kopfzeile mit
Bezeichnungen wie `en`, `deutsch`, `emoji`, `kategorie`, `beispiel` wird erkannt
und zugeordnet. Ohne Kopfzeile gilt: erste Spalte englisch, zweite deutsch.

Alles läuft anschließend durch dieselbe Prüfung wie eine JSON-Datei und landet
als Einreichung. Erst die Freigabe macht eine Liste in der App sichtbar.

Vorlagen zum Weiterarbeiten liegen in [`vorlagen/`](vorlagen/), als CSV und als
JSON. Die öffentliche Seite bietet sie zum Herunterladen an.

**Fotos von Buchseiten** gehen im Admincenter unter **Foto**. Die Texterkennung
läuft im Browser, das Foto wird nicht hochgeladen. Nach dem Erkennen steht die
Tabelle zum Korrigieren bereit, erst die bestätigte Fassung wird übernommen.

---

## JSON-Schema

```json
{
  "title": "Schulfächer",
  "description": "Optional — erscheint unter dem Dropdown",
  "categories": {
    "core":    "📘 Hauptfächer",
    "sport":   "⚽ Sport & Kunst"
  },
  "words": [
    { "en": "maths",   "de": "Mathe",    "emoji": "➗", "cat": "core"  },
    { "en": "english", "de": "Englisch", "emoji": "🇬🇧", "cat": "core"  },

    { "en": "sports",  "de": "Sport",
      "img": "https://example.com/sport.jpg",           "cat": "sport" },

    { "en": "art",     "de": "Kunst" }
  ]
}
```

### Feld-Referenz

| Feld | Pflicht | Beschreibung |
|------|--------|--------------|
| `title` | empfohlen | Anzeigename im Dropdown. Default = Dateiname ohne `.json` |
| `description` | optional | Wird unter dem Dropdown eingeblendet |
| `categories` | optional | Map `cat-Key` → Label für Filter-Buttons und Vokabelliste |
| `words[]` | **Pflicht** | Array aller Vokabeln |
| `words[].en` | **Pflicht** | Englisches Wort |
| `words[].de` | **Pflicht** | Deutsche Übersetzung |
| `words[].emoji` | optional | Ein Emoji als Visualisierung |
| `words[].img` | optional | URL zu einem Bild. Hat Vorrang vor `emoji` |
| `words[].cat` | optional | Kategorie-Key (für Filter). Ohne Angabe → `"default"` |
| `words[].example` | optional | Beispielsatz auf Englisch. Speist den Modus **Lückensatz** |
| `words[].exampleDe` | optional | Übersetzung des Beispielsatzes |

### Wichtige Regeln

- **`emoji` und `img` sind beide optional.** Fehlt beides, rendert die App automatisch einen farbigen Kreis mit dem ersten Buchstaben als Fallback — die Spiele funktionieren trotzdem.
- **Bilder werden in ein festes Quadrat gezwungen** (via `object-fit: contain`). Egal ob Hoch-, Quer- oder Quadratformat: das Seitenverhältnis bleibt erhalten, es wird nichts abgeschnitten.
- **Bild-URLs sollten HTTPS** sein, sonst blockiert der Browser sie (Mixed Content).
- **Kategorien sind optional.** Ohne Kategorien wird in der Vokabelliste nur der „Alle"-Filter gezeigt.
- **Beispielsätze lohnen sich.** Kommt das Wort im Satz wörtlich vor, entsteht daraus
  automatisch eine Lückensatz-Aufgabe. Steht im Satz nur eine gebeugte Form (`chase`
  gegen `chasing`, `friend` gegen `friends`), wird der Eintrag für diesen Modus still
  übersprungen. Der Satz erscheint trotzdem in der Vokabelansicht.
- Der Modus **Lückensatz** erscheint erst, wenn die Auswahl mindestens 4 solcher Wörter hat.
- **Mindestens 4 Einträge** empfohlen (für Quiz mit 4 Antwortoptionen). Bei weniger wird die Auswahl automatisch verkleinert.

---

## Wie die Auto-Discovery funktioniert

Der Frontend-Loader versucht in dieser Reihenfolge:

1. **`GET /wordlists/index.php`** — scannt das Verzeichnis per `glob('*.json')`, liefert Titel + Metadaten direkt. → Primärpfad auf Plesk.
2. **`GET /wordlists/index.json`** — reines Manifest (Array von Dateinamen). Fallback für statisches Hosting oder lokalen Dev-Server ohne PHP.

Wenn beides fehlschlägt, zeigt die App einen Fehler-Banner.

---

## Lokales Testen

PHP ist lokal nicht nötig — der Manifest-Fallback greift:

```bash
# im Projekt-Root
python -m http.server 8080
```

Dann `http://localhost:8080/` aufrufen. `wordlists/index.json` wird als Liste verwendet.

Beim Hinzufügen neuer Listen lokal: auch `wordlists/index.json` aktualisieren (auf dem Plesk-Server nicht nötig, dort übernimmt `index.php` die Auto-Erkennung).

---

## Admincenter

Erreichbar unter `/admin/`, Zugänge richtet der Betreiber über die Kommandozeile
ein. Vier Bereiche:

| Bereich | Wofür |
|---------|-------|
| **Übersicht** | Anonyme Nutzungszahlen, dazu die Wörter mit den meisten Fehlern |
| **Wortlisten** | Hochladen, prüfen, Vorschau, freigeben. Abgelehntes bleibt erhalten und lässt sich zurückholen |
| **Bearbeiten** | Veröffentlichte Listen ändern: Wörter, Emoji, Kategorien, Beispielsätze. Jede Änderung ist über „Frühere Fassungen" umkehrbar |
| **Foto** | Foto einer Wortschatzseite im Browser erkennen und als Einreichung übernehmen |
| **Bilder** | Bilder zu Vokabeln hochladen und zuordnen, mit Arbeitsliste "ohne Visualisierung" |

**Wo kommen Bilder und Emoji hin?** Emoji stehen beim Wort und werden unter
**Bearbeiten** eingetragen, entweder von Hand oder mit „Leere Emoji füllen",
das für rund 345 bekannte Wörter einen Vorschlag kennt. Bilder werden unter
**Bilder** hochgeladen und dort einem Wort zugeordnet. Hat ein Wort weder
Emoji noch Bild, zeigt die App einen farbigen Kreis mit dem Anfangsbuchstaben.

Hochgeladene Bilder werden serverseitig nach WebP mit 256 Pixel Kantenlänge neu
erzeugt und liegen in `img/auto/`. Der Ordner ist nicht im Repo und gehört in
die Sicherung, siehe [docs/DEPLOY.md](docs/DEPLOY.md).

Einreichungen von der öffentlichen Seite landen in derselben Warteschlange. Ein
Zähler neben **Wortlisten** zeigt, wie viele offen sind.

**Nichts geht verloren.** Eine abgelehnte Einreichung bleibt erhalten und lässt
sich zurückholen. Eine Liste lässt sich archivieren, womit sie aus der App
verschwindet, ohne dass die Datei angefasst wird. Und jede Änderung im Editor
legt vorher eine Fassung an, die letzten zwölf stehen zum Zurückholen bereit.

**Wichtig dabei:** Eingereichte Dateien dürfen *nicht* direkt in `wordlists/` landen.
Der Ordner liegt im Docroot, wird von `index.php` per `glob()` gelesen und wäre damit
sofort live. Einreichungen gehören in eine Quarantäne außerhalb des Docroots,
Veröffentlichung erst nach Freigabe. Begründung und die weiteren Pflichtpunkte stehen
in [docs/SECURITY.md](docs/SECURITY.md).
