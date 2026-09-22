/* wordQUEST – Service Worker (statische Shell, Wortlisten immer frisch)

   WICHTIG beim Ausliefern: CACHE_VERSION bei jedem Release hochzählen.
   Nur wenn sich diese Datei unterscheidet, bemerkt der Browser überhaupt
   eine neue Fassung und der Update-Hinweis in index.html erscheint.
*/
const CACHE_VERSION = 'v9';
const CACHE_NAME = `wordquest-static-${CACHE_VERSION}`;
const ASSETS = ['./index.html', './style.css', './wordQUEST_icon.png', './favicon.ico', './manifest.webmanifest'];

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
      // Lokal alles wegräumen, sonst nur die alten Versionen.
      Promise.all(keys.filter((k) => LOKAL || k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

/* Der Update-Hinweis in index.html schickt das hier, wenn zugestimmt wurde. */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WARTEN') self.skipWaiting();
});

/* Nur Dateien der Shell dürfen in den Cache. Alles andere, etwa Bild-URLs aus
   Wortlisten, würde den Cache sonst unbegrenzt volllaufen lassen. */
function istShellAsset(path) {
  return ASSETS.some((a) => {
    const rein = a.replace('./', '/');
    return path === rein || path.endsWith(rein);
  });
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Fremdursprünge gar nicht erst abfangen: Schriften, CDN und Bild-Hosts
  // sollen direkt vom Browser geladen werden.
  if (url.origin !== self.location.origin) return;
  if (event.request.method !== 'GET') return;
  if (LOKAL) return;

  const path = url.pathname;

  // Wortlisten und PHP immer frisch aus dem Netz, damit neue Listen sofort
  // sichtbar sind.
  if (path.includes('/wordlists/') || path.endsWith('.php')) {
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
