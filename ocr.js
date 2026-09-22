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

  /* ─── Bild laden und auf eine sinnvolle Größe bringen ───
     Zu kleine Bilder erkennt Tesseract schlecht, zu große kosten nur Zeit.
     Rund 1800 Pixel Breite ist ein guter Kompromiss für Buchseiten. */
  function bildInLeinwand(datei) {
    return new Promise((fertig, fehlgeschlagen) => {
      const leser = new FileReader();
      leser.onerror = () => fehlgeschlagen(new Error('Die Datei konnte nicht gelesen werden.'));
      leser.onload = () => {
        const bild = new Image();
        bild.onerror = () => fehlgeschlagen(new Error('Das ist kein lesbares Bild.'));
        bild.onload = () => {
          const zielBreite = Math.min(1800, Math.max(bild.naturalWidth, 1000));
          const faktor = zielBreite / bild.naturalWidth;
          leinwand.width = Math.round(bild.naturalWidth * faktor);
          leinwand.height = Math.round(bild.naturalHeight * faktor);
          const stift = leinwand.getContext('2d');
          stift.fillStyle = '#fff';
          stift.fillRect(0, 0, leinwand.width, leinwand.height);
          stift.drawImage(bild, 0, 0, leinwand.width, leinwand.height);
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
          if (woerter.length) proZeile.set(schluessel, woerter);
        });
      });
    });
    return [...proZeile.values()];
  }

  function paareBilden(zeilen) {
    const grenze = spaltenGrenze(zeilen);
    const paare = [];
    zeilen.forEach((woerter) => {
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
      if (en && de) paare.push({ en, de });
    });
    return paare;
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
      const w = await holeWorker((text) => melde(text, 'hinweis-meldung'));
      const { data } = await w.recognize(leinwand, {}, { blocks: true });
      const paare = paareBilden(zeilenAufbauen(data));
      if (!paare.length) {
        melde('Es liessen sich keine Wortpaare erkennen. Hilfreich sind: gerade von oben fotografieren, gutes Licht, nur die Wortliste im Bild.', 'fehler');
      } else {
        melde(paare.length + ' Zeilen erkannt. Bitte durchsehen und korrigieren, die Erkennung macht Fehler.', 'hinweis-meldung');
        tabelleZeigen(paare);
      }
    } catch (fehler) {
      melde('Die Texterkennung ist gescheitert: ' + fehler.message, 'fehler');
    } finally {
      startKnopf.disabled = false;
    }
  });
})();
