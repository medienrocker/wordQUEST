/* wordQUEST – Service Worker

   Zwei Speicher mit unterschiedlichem Zweck:

   1. Die **Shell** (index.html, Styles, Symbole) liegt unter einer
      Versionsnummer und wird bei jedem Release ausgetauscht.
   2. Die **Daten** (Wortlisten und deren Bilder) liegen in einem eigenen,
      unversionierten Speicher. Der überlebt ein Update, denn eine neue
      Programmfassung macht die Vokabeln von gestern nicht ungültig.

   Warum überhaupt Wortlisten im Cache: Die App richtet sich an Kinder mit
   knappem Datenvolumen. Ohne Zwischenspeicher startet die App offline zwar,
   findet aber keine einzige Vokabel und zeigt nur eine Fehlermeldung. Sie
   funktionierte damit genau dann nicht, wenn man sie sich gerade nicht leisten
   kann.

   WICHTIG beim Ausliefern: CACHE_VERSION bei jedem Release hochzählen.
   Nur wenn sich diese Datei unterscheidet, bemerkt der Browser überhaupt
   eine neue Fassung und der Update-Hinweis in index.html erscheint.
*/
const CACHE_VERSION = 'v20';
const CACHE_NAME = `wordquest-static-${CACHE_VERSION}`;
const DATEN_CACHE = 'wordquest-daten';
const ASSETS = ['./index.html', './style.css', './wordQUEST_icon.png', './favicon.ico', './manifest.webmanifest'];

/* Obergrenze für den Datenspeicher. Eine Wortliste wiegt wenige Kilobyte, ein
   Bild höchstens 25. Die Grenze verhindert trotzdem, dass der Speicher über
   Monate unbemerkt zuwächst. */
const DATEN_MAX = 300;

/* Lokale Entwicklung: nie aus dem Cache ausliefern. Sonst zeigt der Browser
   nach einer Änderung weiter die alte Datei, und man sucht den Fehler im Code
   statt im Cache. Auf der echten Domain bleibt alles wie gehabt.
   Folge davon: Der Update-Hinweis ist lokal nicht testbar, weil der Worker
   dort sofort übernimmt. */
const LOKAL = ['localhost', '127.0.0.1', '[::1]'].includes(self.location.hostname);

self.addEventListener('install', (event) => {
  if (LOKAL) { self.skipWaiting(); return; }
  // Bewusst KEIN skipWaiting: Der neue Worker wartet, bis das Kind im
  // Update-Hinweis zustimmt. Sonst würden mitten im Spiel alte und neue
  // Dateien gemischt.
  // Jede Datei einzeln cachen, damit ein fehlendes Asset den gesamten
  // Precache nicht abbricht.
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      Promise.all(ASSETS.map((url) => cache.add(url).catch(() => null)))
    )
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      // Lokal alles wegräumen. Sonst nur alte Shell-Fassungen, der
      // Datenspeicher bleibt ausdrücklich stehen.
      Promise.all(
        keys
          .filter((k) => LOKAL || (k !== CACHE_NAME && k !== DATEN_CACHE))
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

/* Der Update-Hinweis in index.html schickt das hier, wenn zugestimmt wurde. */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WARTEN') self.skipWaiting();
});

/* Nur Dateien der Shell dürfen in den Shell-Cache. */
function istShellAsset(path) {
  return ASSETS.some((a) => {
    const rein = a.replace('./', '/');
    return path === rein || path.endsWith(rein);
  });
}

/** Ältestes zuerst wegwerfen, wenn der Datenspeicher zu voll wird. */
async function datenSpeicherKuerzen(cache) {
  const schluessel = await cache.keys();
  if (schluessel.length <= DATEN_MAX) return;
  await Promise.all(
    schluessel.slice(0, schluessel.length - DATEN_MAX).map((k) => cache.delete(k))
  );
}

/**
 * Netz zuerst, Cache als Rückfallebene.
 *
 * Für Wortlisten ist die Reihenfolge wichtig: Eine im Admincenter
 * veröffentlichte oder bearbeitete Liste muss sofort sichtbar sein. Der Cache
 * springt nur ein, wenn das Netz nicht antwortet.
 */
async function netzZuerst(request) {
  const cache = await caches.open(DATEN_CACHE);
  try {
    const antwort = await fetch(request);
    if (antwort && antwort.ok && antwort.type === 'basic') {
      cache.put(request, antwort.clone()).then(() => datenSpeicherKuerzen(cache)).catch(() => {});
    }
    return antwort;
  } catch (fehler) {
    const gespeichert = await cache.match(request);
    if (gespeichert) return gespeichert;
    throw fehler;
  }
}

/**
 * Cache zuerst, Netz nur beim ersten Mal.
 *
 * Für Bilder richtig herum: Der Dateiname eines Bildes ist stabil, ein
 * einmal geladenes Bild ändert sich nicht mehr. Jedes erneute Laden wäre
 * verschenktes Datenvolumen.
 */
async function cacheZuerst(request) {
  const cache = await caches.open(DATEN_CACHE);
  const gespeichert = await cache.match(request);
  if (gespeichert) return gespeichert;
  const antwort = await fetch(request);
  if (antwort && antwort.ok && antwort.type === 'basic') {
    cache.put(request, antwort.clone()).then(() => datenSpeicherKuerzen(cache)).catch(() => {});
  }
  return antwort;
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Fremdursprünge gar nicht erst abfangen: Schriften, CDN und Bild-Hosts
  // sollen direkt vom Browser geladen werden.
  if (url.origin !== self.location.origin) return;
  if (event.request.method !== 'GET') return;
  if (LOKAL) return;

  const path = url.pathname;

  // Admincenter und API gehören NICHT zur Lernapp. Ohne diese Zeile fängt der
  // Worker die Navigation zu /admin/ ab und liefert die zwischengespeicherte
  // index.html der Lernapp aus, also die falsche Seite. Real aufgetreten:
  // Der Aufruf von /admin/ zeigte die Vokabelapp statt der Anmeldung.
  if (path.startsWith('/admin') || path.startsWith('/api/')) return;

  // Wortlisten samt Auto-Discovery: frisch, wenn das Netz da ist, sonst aus
  // dem Zwischenspeicher. Genau das macht die App offline benutzbar.
  if (path.includes('/wordlists/')) {
    event.respondWith(netzZuerst(event.request));
    return;
  }

  // Bilder zu Vokabeln: einmal laden, dann für immer aus dem Speicher.
  if (path.startsWith('/img/')) {
    event.respondWith(cacheZuerst(event.request));
    return;
  }

  // Übrige PHP-Seiten, etwa die Einreichungsseite: immer aus dem Netz, sie
  // ergeben ohne Server keinen Sinn.
  if (path.endsWith('.php')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Shell cache-first. Das ist Absicht: Solange ein Update nur wartet, läuft
  // die App vollständig in der alten Fassung weiter. Erst die Zustimmung im
  // Update-Hinweis schaltet geschlossen auf die neue um.
  const istNavigation = event.request.mode === 'navigate' || path === '/' || path.endsWith('/index.html');

  event.respondWith(
    caches.match(istNavigation ? './index.html' : event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((res) => {
        if (res.ok && res.type === 'basic' && (istNavigation || istShellAsset(path))) {
          const copy = res.clone();
          caches.open(CACHE_NAME)
            .then((cache) => cache.put(istNavigation ? './index.html' : event.request, copy))
            .catch(() => {});
        }
        return res;
      });
    }).catch(() => caches.match('./index.html').then((c) => c || Response.error()))
  );
});
