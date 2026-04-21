# wordQUEST

Kleines Vokabel-Lernspiel (Quiz, Memory, Word Scramble, Spelling Bee) — komplett statisch, läuft in jedem Browser.
Vokabelsätze werden zur Laufzeit aus dem Ordner `wordlists/` geladen; **mehrere Listen** sind per Checkbox kombinierbar.
In der **Vokabelansicht** kannst du einzelne Wörter für die Spiele aktivieren oder deaktivieren. Unter **Üben** werden falsch beantwortete Wörter lokal gespeichert und als **Übungs-Quiz** wiederholt (ohne Login).

---

## Deployment auf Plesk (wordQUEST.bildungssprit.de)

### 1. Subdomain in Plesk anlegen
1. Plesk → **Domains** → **Subdomain hinzufügen**
2. Name: `wordquest`  ·  Parent: `bildungssprit.de`
3. Document Root: `wordquest.bildungssprit.de/httpdocs` (Default)
4. **PHP aktivieren** (Plesk → Hosting-Einstellungen → PHP-Support: an, PHP 8.x empfohlen)
5. TLS: **Let's Encrypt** für die Subdomain aktivieren

### 2. Dateien per FTP hochladen
Lade den gesamten Projektinhalt in `…/wordquest.bildungssprit.de/httpdocs/`:

```
httpdocs/
├── index.html
├── manifest.webmanifest  ← PWA-Manifest
├── sw.js                 ← Service Worker (optional, für Offline-Shell)
├── .htaccess
└── wordlists/
    ├── index.php        ← Auto-Discovery-Endpoint
    ├── index.json       ← Manifest-Fallback (optional)
    ├── food.json
    └── …
```

### 3. Fertig
`https://wordquest.bildungssprit.de/` aufrufen — die Checkboxen listen alle `*.json`-Dateien aus dem `wordlists/`-Ordner (über `index.php` oder `index.json`).

---

## Progressive Web App (PWA)

- **Installation:** In Chromium-basierten Browsern erscheint „App installieren“, sobald `manifest.webmanifest` und `sw.js` mit ausgeliefert werden (HTTPS oder `localhost`).
- **Aktualität der Wortlisten:** Der Service Worker cached die statische Shell (`index.html`, Styles, Icons). Inhalte unter `wordlists/` werden **nicht** dauerhaft gecacht, damit neue JSON-Dateien nach einem Upload sichtbar bleiben.
- **MIME-Typ:** `.htaccess` setzt `application/manifest+json` für `.webmanifest`. Falls nötig, in Plesk unter Apache-Einstellungen prüfen.

---

## Neue Wortliste hinzufügen

1. JSON-Datei nach dem Schema unten erstellen (z. B. `schule.json`)
2. Per FTP in `httpdocs/wordlists/` ablegen
3. Seite neu laden — die neue Liste erscheint automatisch in der Auswahl

**Kein Rebuild, kein Server-Restart nötig.**

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

### Wichtige Regeln

- **`emoji` und `img` sind beide optional.** Fehlt beides, rendert die App automatisch einen farbigen Kreis mit dem ersten Buchstaben als Fallback — die Spiele funktionieren trotzdem.
- **Bilder werden in ein festes Quadrat gezwungen** (via `object-fit: contain`). Egal ob Hoch-, Quer- oder Quadratformat: das Seitenverhältnis bleibt erhalten, es wird nichts abgeschnitten.
- **Bild-URLs sollten HTTPS** sein, sonst blockiert der Browser sie (Mixed Content).
- **Kategorien sind optional.** Ohne Kategorien wird in der Vokabelliste nur der „Alle"-Filter gezeigt.
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

## Spätere Erweiterung: Backend mit Login

Aktuell: Upload per FTP.
Geplant: Web-UI mit Login zum Hochladen/Löschen/Bearbeiten der JSON-Dateien. Die Datenschicht (JSON-Dateien in `wordlists/`) bleibt dabei identisch — das Backend schreibt einfach in denselben Ordner, den das Frontend bereits liest. Keine Migration nötig.
