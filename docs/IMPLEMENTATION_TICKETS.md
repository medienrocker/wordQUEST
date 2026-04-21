# wordQUEST – Umsetzungstickets

Epic: Mehrfach-Wortlisten, Vokabel-Filter, Diagnose-Modus, PWA.  
Branch-Empfehlung: Arbeit auf `feature/wq-learning-stack` (oder Unter-Branches pro Ticket).

---

## Epic 0 – Vorbereitung

### WQ-0.1 – Stabile Wort-IDs (`_wqId`)

**Ziel:** Jeder Vokabeleintrag erhält eine lokale, stabile Kennung (z. B. Hash aus `Quelldatei|en|de`), unabhängig vom Array-Index.

**Akzeptanz:**

- [ ] Beim Laden/Normalisieren wird `_wqId` gesetzt.
- [ ] Gleiche `en`/`de` aus verschiedenen JSON-Dateien erhalten unterschiedliche IDs, wenn die Datei im Hash steckt.
- [ ] Kurz im README oder Code-Kommentar dokumentiert.

**Abhängigkeiten:** keine.  
**Blockiert:** WQ-2.x, WQ-3.x.

---

## Epic 1 – PWA

### WQ-1.1 – Web App Manifest

**Akzeptanz:**

- [ ] `manifest.webmanifest` (oder `.json`) mit `name`, `short_name`, `start_url`, `display: standalone`, `theme_color`, `background_color`.
- [ ] Icons mindestens 192×192 und 512×512 (maskable wo sinnvoll).
- [ ] `index.html`: `<link rel="manifest" href="…">`.

**Abhängigkeiten:** keine.

### WQ-1.2 – Service Worker (v1, konservativ)

**Akzeptanz:**

- [ ] Registrierung nach `load` (kein Blockieren des ersten Paint).
- [ ] Caching-Strategie: statische Assets (`index.html`, `style.css`, Icons); `wordlists/*` **network-first** oder gar nicht cachen, damit neue Listen auf dem Server sichtbar bleiben.
- [ ] `index.php`-Discovery bleibt netzwerkabhängig dokumentiert.

**Abhängigkeiten:** WQ-1.1 sinnvoll zuerst.

### WQ-1.3 – Server / Deploy

**Akzeptanz:**

- [ ] `.htaccess` oder Plesk: korrekter MIME-Typ für `.webmanifest` falls nötig.
- [ ] README: kurzer Abschnitt „Installation als PWA“ (HTTPS, Symbole).

**Abhängigkeiten:** WQ-1.1, WQ-1.2.

---

## Epic 2 – Mehrere Wortlisten

### WQ-2.1 – UI Mehrfachauswahl

**Akzeptanz:**

- [ ] Mehrere Listen gleichzeitig wählbar (Checkbox-Liste, `<select multiple>` mit guter UX, oder Chips – konsistent zum restlichen UI).
- [ ] Barrierefrei: Tastatur, sinnvolle `aria-label`s.
- [ ] Persistenz: z. B. `localStorage` `wq.selectedLists` (Array von Dateinamen); Migration von `wq.lastList` → einelementiges Array.

**Abhängigkeiten:** keine.

### WQ-2.2 – Laden und Zusammenführen

**Akzeptanz:**

- [ ] Paralleles Laden aller gewählten JSONs; Teilfehler pro Datei anzeigen, nicht die ganze App leeren.
- [ ] `VOCAB` = gemergte, normalisierte Einträge mit `_srcFile` (und `_wqId` aus WQ-0.1).
- [ ] Duplikat-Policy festgelegt und umgesetzt (z. B. beide behalten vs. `(en,de)` deduplizieren) + README-Hinweis.
- [ ] Meta-Zeile: z. B. „3 Listen · N Wörter“.

**Abhängigkeiten:** WQ-0.1, WQ-2.1.

### WQ-2.3 – Kategorien über mehrere Listen

**Akzeptanz:**

- [ ] Kategorie-Keys kollisionsfrei (z. B. Präfix `dateiname:cat` oder getrennte Filter-Gruppen).
- [ ] `renderVocabFilters` / Labels zeigen Herkunft verständlich an.

**Abhängigkeiten:** WQ-2.2.

---

## Epic 3 – Vokabel ein-/ausblenden

### WQ-3.1 – Persistenz und Filterfunktion

**Akzeptanz:**

- [ ] `getActiveVocab()` liefert nur nicht deaktivierte Einträge (Default: alle aktiv).
- [ ] Speicher z. B. `wq.disabledIds` (Set von `_wqId`) oder invertiert `activeIds` – beim Wechsel der Listenkonfiguration alte IDs bereinigen.
- [ ] Leere aktive Menge: bestehendes Empty-State-Verhalten / Hinweistext.

**Abhängigkeiten:** WQ-0.1, WQ-2.2 (sinnvoll erst mit Merge).

### WQ-3.2 – UI in „Vokabelliste“

**Akzeptanz:**

- [ ] Pro Karte Toggle (Checkbox oder Button).
- [ ] Globale Aktionen: „Alle an“, „Alle aus“ (optional „Invertieren“).
- [ ] Filter- und Kategorie-UI bleibt konsistent mit `getActiveVocab()`.

**Abhängigkeiten:** WQ-3.1.

### WQ-3.3 – Spiele anbinden

**Akzeptanz:**

- [ ] Quiz, Memory, Scramble, Spelling nutzen durchgängig `getActiveVocab()` statt rohem `VOCAB`.
- [ ] Memory: genug Karten/Paare bei kleiner aktiver Menge (bestehende Logik angepasst).

**Abhängigkeiten:** WQ-3.1.

---

## Epic 4 – Diagnose (ohne Login)

### WQ-4.1 – Ereignisse erfassen

**Akzeptanz:**

- [ ] Bei falscher Antwort (Quiz, Scramble, Spelling): Eintrag für `_wqId` aktualisieren.
- [ ] Memory: definiertes Verhalten bei nicht passendem Paar (z. B. beide betroffenen Wörter oder ein Eintrag pro Zug).
- [ ] Speicher: `localStorage` (Struktur dokumentiert); Schlüsselpräfix z. B. `wq.diag.`.

**Abhängigkeiten:** WQ-0.1, WQ-3.3 empfohlen (nur aktives Set oder alle – Product-Entscheid).

### WQ-4.2 – Diagnose-Algorithmus v1 (Minimal)

**Akzeptanz:**

- [ ] Zähler `wrongCount` (und optional Dekrement bei richtig).
- [ ] Abfrage: „schwache Wörter“ = `wrongCount > 0` innerhalb des aktuellen `getActiveVocab()` bzw. optional global.

**Abhängigkeiten:** WQ-4.1.

### WQ-4.3 – Sechste Kachel + Modus

**Akzeptanz:**

- [ ] Neues Menü-Element / Kachel „Üben“ / „Diagnose“ mit Kurzbeschreibung.
- [ ] Spiel- oder Übungsflow: zieht nur aus schwacher Menge; leerer Zustand mit Hinweis („Noch keine Fehler erfasst“).
- [ ] Optional: gleiche Modi wie Quiz mit anderer Wortquelle (Wiederverwendung).

**Abhängigkeiten:** WQ-4.2.

### WQ-4.4 – Wartung & Datenschutz-UX

**Akzeptanz:**

- [ ] Button oder Einstellung „Diagnose-Daten löschen“.
- [ ] Kurzer Hinweis: Daten nur lokal im Browser.

**Abhängigkeiten:** WQ-4.2.

### WQ-4.5 – (Optional) Erweiterung Leitner / SM-2 light

**Akzeptanz:** Nach v1-Feedback; Boxen oder Intervalle, getrenntes Ticket wenn angegangen.

**Abhängigkeiten:** WQ-4.2.

---

## Reihenfolge (empfohlen)

1. WQ-0.1  
2. WQ-1.1 → WQ-1.2 → WQ-1.3  
3. WQ-2.1 → WQ-2.2 → WQ-2.3  
4. WQ-3.1 → WQ-3.2 → WQ-3.3  
5. WQ-4.1 → WQ-4.2 → WQ-4.3 → WQ-4.4  

---

## Branch-Strategie (Kurz)

- **Ein Feature-Branch** für die gesamte Roadmap (`feature/wq-learning-stack`): wenig Overhead, ein PR am Ende oder wenige große PRs.
- **Alternativ:** `main` nur stabil halten; pro Epic oder Ticket Kurz-Branches (`feature/wq-1-pwa`, `feature/wq-2-lists`) und per Merge in den Epic-Branch oder direkt in `main` – sinnvoll bei paralleler Arbeit oder Review in kleinen Häppchen.

Empfehlung für Solo/klare Abfolge: **ein langlebiger Branch** + sinnvolle Zwischen-Commits pro abgeschlossenem Ticket.
