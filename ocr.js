/* wordQUEST – Foto einer Wortschatzseite in eine Wortliste überführen (WQ-9.5).
 *
 * Alles läuft im Browser. Das Foto wird nie hochgeladen: Es geht in ein
 * Canvas, die Texterkennung arbeitet darauf, und abgeschickt wird am Ende nur
 * die korrigierte Tabelle als Text. Für Fotos aus Lehrwerken ist das
 * urheberrechtlich der ruhigere Weg, und der Server bleibt unbelastet.
 *
 * Der Kniff bei Vokabelseiten sind die Koordinaten: Texterkennung liefert
 * jedes Wort mit einem Rechteck. Daraus lässt sich die englische Spalte links
 * von der deutschen rechts trennen. Ein reiner Textauswurf ohne Positionen
 * wäre für zweispaltige Seiten unbrauchbar.
 *
 * Diese Datei wird von zwei Seiten genutzt: `admin/foto.php` und der
 * öffentlichen `einreichen.php`. Beide bringen dieselben Element-Kennungen
 * mit, und das Ergebnis landet in beiden Fällen im Feld `eingefuegt`. Damit
 * läuft die Einreichung durch denselben Weg wie eine von Hand eingefügte
 * Tabelle, es gibt keinen zweiten Pfad in den Server.
 */
(function () {
  'use strict';

  const CDN = 'https://cdn.jsdelivr.net/npm';
  const VERSION = '5.1.1';

  const el = (id) => document.getElementById(id);
  const bildFeld   = el('foto-datei');
  const leinwand   = el('foto-leinwand');
  const startKnopf = el('foto-start');
  const stand      = el('foto-stand');
  const ergebnis   = el('foto-ergebnis');
  const tabelle    = el('foto-tabelle');
  const ausgabe    = el('eingefuegt');
  const zaehler    = el('foto-zaehler');

  // Fehlt ein Baustein, passiert hier gar nichts. So lässt sich die Datei
  // gefahrlos auf Seiten einbinden, die den Fotoweg nicht anbieten.
  if (!bildFeld || !leinwand || !startKnopf || !stand || !ergebnis || !tabelle || !ausgabe || !zaehler) {
    return;
  }

  let worker = null;

  function melde(text, artDerMeldung) {
    stand.textContent = text;
    stand.className = 'meldung ' + (artDerMeldung || 'hinweis-meldung');
    stand.hidden = !text;
  }

  /* ─── Bild laden und für die Erkennung aufbereiten ───

     Tesseract arbeitet am besten bei ungefähr 300 dpi. Eine Buchseite mit
     kleiner Schrift braucht dafür deutlich mehr als die vorher genutzten 1800
     Pixel Breite: Genau daran scheitern die Pünktchen über den Umlauten. Sie
     sind winzig, und wenn sie im Bild verschwimmen, wird aus "ü" ein "u" oder
     ein "ii". Kleine Vorlagen werden deshalb hochgerechnet, grosse nur noch
     mässig verkleinert.

     Dazu Graustufen und eine Kontacktspreizung. Fotos von Buchseiten haben
     durch Raumlicht selten echtes Weiss und echtes Schwarz, und ein flauer
     Kontrast kostet bei kleinen Zeichen mehr Erkennungsleistung als alles
     andere. */
  const ZIEL_BREITE_MAX = 2600;
  const ZIEL_BREITE_MIN = 1600;

  function aufbereiten() {
    const stift = leinwand.getContext('2d', { willReadFrequently: true });
    let bild;
    try {
      bild = stift.getImageData(0, 0, leinwand.width, leinwand.height);
    } catch {
      return;   // Ohne Bilddaten bleibt das Original, das ist kein Beinbruch.
    }
    const px = bild.data;

    // Erst Graustufen, dabei ein Histogramm führen.
    const histogramm = new Uint32Array(256);
    for (let i = 0; i < px.length; i += 4) {
      const grau = (px[i] * 0.299 + px[i + 1] * 0.587 + px[i + 2] * 0.114) | 0;
      px[i] = px[i + 1] = px[i + 2] = grau;
      histogramm[grau]++;
    }

    /* ─── Dunkelmodus erkennen und umkehren ───

       Texterkennung ist auf dunkle Schrift auf hellem Grund ausgelegt. Ein
       Bildschirmfoto im Dunkelmodus ist genau andersherum, und darunter leiden
       zuerst die feinen Teile der Zeichen: die Pünktchen über den Umlauten.
       Aus "ü" wird dann "ii" oder "u", aus "ä" ein "a". Genau dieser Fehler
       ist im Betrieb aufgetreten.

       Entschieden wird über den Anteil dunkler Bildpunkte. Ein Foto einer
       Buchseite ist überwiegend hell, ein Bildschirmfoto im Dunkelmodus
       überwiegend dunkel. */
    let dunkel = 0;
    for (let i = 0; i < 128; i++) {
      dunkel += histogramm[i];
    }
    const umkehren = dunkel > (px.length / 4) * 0.6;
    if (umkehren) {
      histogramm.reverse();
      for (let i = 0; i < px.length; i += 4) {
        const hell = 255 - px[i];
        px[i] = px[i + 1] = px[i + 2] = hell;
      }
    }

    /* Spreizen, aber die äussersten 0,5 Prozent ignorieren. Sonst genügt ein
       einziger schwarzer Fleck oder eine Spiegelung, um die ganze Skala zu
       bestimmen, und der Rest bleibt so flau wie vorher. */
    const gesamt = (px.length / 4) | 0;
    const rand = Math.max(1, Math.round(gesamt * 0.005));
    let unten = 0;
    let oben = 255;
    for (let summe = 0, i = 0; i < 256; i++) {
      summe += histogramm[i];
      if (summe > rand) { unten = i; break; }
    }
    for (let summe = 0, i = 255; i >= 0; i--) {
      summe += histogramm[i];
      if (summe > rand) { oben = i; break; }
    }
    if (oben - unten < 32) return;   // schon kontrastreich oder fast leer

    const spanne = 255 / (oben - unten);
    const tabelle = new Uint8ClampedArray(256);
    for (let i = 0; i < 256; i++) {
      tabelle[i] = Math.min(255, Math.max(0, (i - unten) * spanne));
    }
    for (let i = 0; i < px.length; i += 4) {
      px[i] = px[i + 1] = px[i + 2] = tabelle[px[i]];
    }
    stift.putImageData(bild, 0, 0);
  }

  function bildInLeinwand(datei) {
    return new Promise((fertig, fehlgeschlagen) => {
      const leser = new FileReader();
      leser.onerror = () => fehlgeschlagen(new Error('Die Datei konnte nicht gelesen werden.'));
      leser.onload = () => {
        const bild = new Image();
        bild.onerror = () => fehlgeschlagen(new Error('Das ist kein lesbares Bild.'));
        bild.onload = () => {
          const zielBreite = Math.min(
            ZIEL_BREITE_MAX,
            Math.max(ZIEL_BREITE_MIN, Math.round(bild.naturalWidth * 1.5))
          );
          const faktor = zielBreite / bild.naturalWidth;
          leinwand.width = Math.round(bild.naturalWidth * faktor);
          leinwand.height = Math.round(bild.naturalHeight * faktor);
          const stift = leinwand.getContext('2d', { willReadFrequently: true });
          stift.fillStyle = '#fff';
          stift.fillRect(0, 0, leinwand.width, leinwand.height);
          stift.imageSmoothingQuality = 'high';
          stift.drawImage(bild, 0, 0, leinwand.width, leinwand.height);
          aufbereiten();
          leinwand.hidden = false;
          fertig();
        };
        bild.src = leser.result;
      };
      leser.readAsDataURL(datei);
    });
  }

  async function holeWorker(melder) {
    if (worker) return worker;
    if (typeof Tesseract === 'undefined') {
      throw new Error('Die Texterkennung konnte nicht geladen werden. Besteht eine Internetverbindung?');
    }
    worker = await Tesseract.createWorker(['eng', 'deu'], 1, {
      workerPath: CDN + '/tesseract.js@' + VERSION + '/dist/worker.min.js',
      corePath:   CDN + '/tesseract.js-core@' + VERSION,
      logger: (m) => {
        if (m.status && typeof m.progress === 'number') {
          melder(m.status + ' … ' + Math.round(m.progress * 100) + ' %');
        }
      }
    });
    /* Ohne eine dpi-Angabe schätzt Tesseract sie aus der Bildgrösse und warnt
       bei Abweichungen. Da oben bewusst auf Lesbarkeit hochgerechnet wird, ist
       die Angabe hier ehrlicher als die Schätzung. Die Wortabstände bleiben
       erhalten, weil die Spaltentrennung von ihnen lebt. */
    await worker.setParameters({
      user_defined_dpi: '300',
      preserve_interword_spaces: '1'
    });
    return worker;
  }

  /* Lautschrift und Zeilennummern aus dem Lehrwerk sind für eine Vokabelliste
     unbrauchbar und würden jede Zeile verschmutzen. */
  const LAUTSCHRIFT = /[ːˈˌəæʊɒθðŋʃʒʌɜɪɑ]|^\/|\/$/;

  function istMuell(text) {
    const t = text.trim();
    if (!t) return true;
    if (LAUTSCHRIFT.test(t)) return true;
    if (/^[\d\W_]+$/.test(t)) return true;      // nur Ziffern oder Zeichen
    return false;
  }

  /* Viele Vorlagen bringen eine Kopfzeile mit. Sie ist kein Wortpaar und
     würde sonst als erste Vokabel in der Liste landen. */
  const KOPFZEILE = /^(englisch|english|deutsch|german|wort|word|vokabel|begriff|übersetzung|uebersetzung|bedeutung)$/i;

  function istKopfzeile(paar) {
    return KOPFZEILE.test(paar.en.trim()) && KOPFZEILE.test(paar.de.trim());
  }

  /* ─── Zwei Spalten trennen ───
     Je Zeile die größte waagerechte Lücke suchen. Die Mitte dieser Lücken,
     über alle Zeilen gemittelt, ist die Spaltengrenze. Das ist robuster als
     eine feste Bildmitte, weil Fotos selten gerade sind. */
  function spaltenGrenze(zeilen) {
    const kandidaten = [];
    zeilen.forEach((woerter) => {
      if (woerter.length < 2) return;
      let groesste = 0;
      let mitte = 0;
      for (let i = 1; i < woerter.length; i++) {
        const luecke = woerter[i].x0 - woerter[i - 1].x1;
        if (luecke > groesste) {
          groesste = luecke;
          mitte = (woerter[i].x0 + woerter[i - 1].x1) / 2;
        }
      }
      if (groesste > 25) kandidaten.push(mitte);
    });
    if (!kandidaten.length) return null;
    kandidaten.sort((a, b) => a - b);
    return kandidaten[Math.floor(kandidaten.length / 2)];   // Median
  }

  function zeilenAufbauen(daten) {
    const proZeile = new Map();
    (daten.blocks || []).forEach((block) => {
      (block.paragraphs || []).forEach((absatz) => {
        (absatz.lines || []).forEach((zeile, index) => {
          const schluessel = Math.round(zeile.bbox.y0 / 8) + ':' + index;
          const woerter = (zeile.words || [])
            .filter((w) => w.text && !istMuell(w.text))
            .map((w) => ({ text: w.text.trim(), x0: w.bbox.x0, x1: w.bbox.x1 }))
            .sort((a, b) => a.x0 - b.x0);
          // Die Höhe wird für den zweiten Durchgang gebraucht: Nur über sie
          // lassen sich die Zeilen beider Durchgänge einander zuordnen.
          if (woerter.length) {
            proZeile.set(schluessel, { mitteY: (zeile.bbox.y0 + zeile.bbox.y1) / 2, woerter });
          }
        });
      });
    });
    return [...proZeile.values()];
  }

  function paareBilden(zeilen) {
    const grenze = spaltenGrenze(zeilen.map((z) => z.woerter));
    const paare = [];
    zeilen.forEach(({ mitteY, woerter }) => {
      let links = [];
      let rechts = [];
      if (grenze !== null) {
        woerter.forEach((w) => ((w.x0 + w.x1) / 2 < grenze ? links : rechts).push(w.text));
      } else {
        // Keine erkennbare Spalte: an der größten Lücke teilen.
        const mitte = Math.ceil(woerter.length / 2);
        links = woerter.slice(0, mitte).map((w) => w.text);
        rechts = woerter.slice(mitte).map((w) => w.text);
      }
      const en = links.join(' ').trim();
      const de = rechts.join(' ').trim();
      if (en && de) paare.push({ en, de, mitteY });
    });
    return { paare: paare.filter((p) => !istKopfzeile(p)), grenze };
  }

  /* ─── Zweiter Durchgang nur für die deutsche Spalte ───

     Das ist der eigentliche Hebel bei den Umlauten. Der erste Durchgang läuft
     mit einem gemeinsamen Modell für Englisch und Deutsch. Dabei konkurrieren
     zwei Wörterbücher um dieselbe Buchstabenfolge, und weil Englisch keine
     Umlaute kennt, gewinnt bei "über" leicht das englische "ii" oder ein
     blosses "u". Liest man die deutsche Spalte ein zweites Mal, allein mit dem
     deutschen Modell, steht diese Konkurrenz nicht mehr im Weg.

     Der Zuschnitt auf die Spalte hilft zusätzlich: Ohne die englische Spalte
     im Bild gibt es keine englischen Wortformen mehr, an denen sich das Modell
     festhalten könnte. */
  async function deutscheSpalteNachlesen(w, grenze, melder) {
    if (grenze === null) return null;
    const x0 = Math.max(0, Math.round(grenze) - 6);
    const breite = leinwand.width - x0;
    if (breite < 60) return null;

    const ausschnitt = document.createElement('canvas');
    ausschnitt.width = breite;
    ausschnitt.height = leinwand.height;
    ausschnitt.getContext('2d').drawImage(
      leinwand, x0, 0, breite, leinwand.height, 0, 0, breite, leinwand.height
    );

    melder('Deutsche Spalte wird genauer gelesen …');
    await w.reinitialize('deu');
    try {
      const { data } = await w.recognize(ausschnitt, {}, { blocks: true });
      return zeilenAufbauen(data).map(({ mitteY, woerter }) => ({
        mitteY,
        text: woerter.map((x) => x.text).join(' ').trim()
      })).filter((z) => z.text !== '');
    } finally {
      // Für einen nächsten Lauf wieder beide Sprachen bereitstellen.
      await w.reinitialize(['eng', 'deu']);
    }
  }

  /**
   * Ersetzt die deutsche Seite durch das Ergebnis des zweiten Durchgangs.
   * Zugeordnet wird über die Zeilenhöhe, beide Durchgänge arbeiten auf
   * demselben Bild. Findet sich keine passende Zeile, bleibt der erste
   * Durchgang stehen: schlechter als nichts ist er nie.
   */
  function deutschUebernehmen(paare, deutsch) {
    if (!deutsch || !deutsch.length) return { paare, ersetzt: 0 };
    const toleranz = Math.max(12, leinwand.height * 0.012);
    let ersetzt = 0;

    paare.forEach((paar) => {
      let beste = null;
      let abstand = Infinity;
      deutsch.forEach((z) => {
        const d = Math.abs(z.mitteY - paar.mitteY);
        if (d < abstand) { abstand = d; beste = z; }
      });
      if (beste && abstand <= toleranz && beste.text) {
        if (beste.text !== paar.de) ersetzt++;
        paar.de = beste.text;
      }
    });
    return { paare, ersetzt };
  }

  /* ─── Bearbeitbare Tabelle ─── */
  function tabelleZeigen(paare) {
    tabelle.innerHTML = '';
    paare.forEach((paar, i) => {
      const zeile = document.createElement('tr');
      [['en', paar.en], ['de', paar.de]].forEach(([feld, wert]) => {
        const zelle = document.createElement('td');
        const eingabe = document.createElement('input');
        eingabe.type = 'text';
        eingabe.value = wert;
        eingabe.dataset.feld = feld;
        eingabe.setAttribute('aria-label',
          (feld === 'en' ? 'Englisch' : 'Deutsch') + ', Zeile ' + (i + 1));
        eingabe.addEventListener('input', uebernehmen);
        zelle.appendChild(eingabe);
        zeile.appendChild(zelle);
      });
      const weg = document.createElement('td');
      const knopf = document.createElement('button');
      knopf.type = 'button';
      knopf.className = 'klein';
      knopf.textContent = 'entfernen';
      knopf.addEventListener('click', () => { zeile.remove(); uebernehmen(); });
      weg.appendChild(knopf);
      zeile.appendChild(weg);
      tabelle.appendChild(zeile);
    });
    ergebnis.hidden = paare.length === 0;
    uebernehmen();
  }

  /* Schreibt die Tabelle als Tabulartext in das vorhandene Einfügefeld.
     Damit läuft die Einreichung durch genau denselben Weg wie eine von Hand
     eingefügte Tabelle, es gibt keinen zweiten Pfad in den Server. */
  function uebernehmen() {
    const zeilen = [];
    tabelle.querySelectorAll('tr').forEach((tr) => {
      const felder = tr.querySelectorAll('input');
      const en = (felder[0] && felder[0].value || '').trim();
      const de = (felder[1] && felder[1].value || '').trim();
      if (en && de) zeilen.push(en + '\t' + de);
    });
    ausgabe.value = zeilen.join('\n');
    zaehler.textContent = zeilen.length === 1
      ? '1 Wortpaar wird übernommen.'
      : zeilen.length + ' Wortpaare werden übernommen.';
  }

  /* ─── Ablauf ─── */
  bildFeld.addEventListener('change', async () => {
    const datei = bildFeld.files && bildFeld.files[0];
    if (!datei) return;
    try {
      await bildInLeinwand(datei);
      startKnopf.disabled = false;
      melde('Bild geladen. Jetzt die Texterkennung starten.', 'hinweis-meldung');
    } catch (fehler) {
      startKnopf.disabled = true;
      melde(fehler.message, 'fehler');
    }
  });

  startKnopf.addEventListener('click', async () => {
    startKnopf.disabled = true;
    melde('Texterkennung wird vorbereitet. Beim ersten Mal dauert das etwas länger, weil die Sprachdaten geladen werden.', 'hinweis-meldung');
    try {
      const melder = (text) => melde(text, 'hinweis-meldung');
      const w = await holeWorker(melder);
      const { data } = await w.recognize(leinwand, {}, { blocks: true });
      const { paare, grenze } = paareBilden(zeilenAufbauen(data));

      if (!paare.length) {
        melde('Es liessen sich keine Wortpaare erkennen. Hilfreich sind: gerade von oben fotografieren, gutes Licht, nur die Wortliste im Bild.', 'fehler');
      } else {
        // Zweiter Durchgang für die Umlaute. Scheitert er, bleibt das
        // Ergebnis des ersten stehen.
        let ersetzt = 0;
        let zweiterLief = true;
        try {
          const deutsch = await deutscheSpalteNachlesen(w, grenze, melder);
          ({ ersetzt } = deutschUebernehmen(paare, deutsch));
        } catch {
          zweiterLief = false;
        }

        let nachsatz = '';
        if (!zweiterLief) {
          nachsatz = ' Der zweite Durchgang für die deutsche Spalte ist ausgefallen, bitte Umlaute besonders genau prüfen.';
        } else if (ersetzt > 0) {
          nachsatz = ' Bei ' + ersetzt + (ersetzt === 1 ? ' Zeile' : ' Zeilen')
                   + ' hat der zweite Durchgang die deutsche Seite berichtigt.';
        }
        melde(paare.length + ' Zeilen erkannt.' + nachsatz
            + ' Bitte durchsehen und korrigieren, besonders Umlaute. Die Erkennung macht Fehler.', 'hinweis-meldung');
        tabelleZeigen(paare);
      }
    } catch (fehler) {
      melde('Die Texterkennung ist gescheitert: ' + fehler.message, 'fehler');
    } finally {
      startKnopf.disabled = false;
    }
  });
})();
