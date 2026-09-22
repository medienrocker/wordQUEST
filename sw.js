/* wordQUEST – Service Worker (statische Shell, Wortlisten immer frisch)

   Wichtig beim Ausliefern: CACHE_NAME bei jedem Release hochzählen.
   Sonst behalten bereits installierte Geräte die alte index.html dauerhaft,
   und selbst ein behobener Fehler erreicht sie nie.
*/
const CACHE_VERSION = 'v3';
const CACHE_NAME = `wordquest-static-${CACHE_VERSION}`;
const ASSETS = ['./index.html', './style.css', './wordQUEST_icon.png', './favicon.ico', './manifest.webmanifest'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(ASSETS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

/* Nur Dateien der Shell dürfen in den Cache. Alles andere (etwa Bild-URLs
   aus Wortlisten) würde den Cache sonst unbegrenzt volllaufen lassen. */
function isShellAsset(path) {
  return ASSETS.some((a) => path.endsWith(a.replace('./', '/')) || path === a.replace('./', '/'));
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Fremdursprünge gar nicht erst abfangen: Fonts, CDN und Bild-Hosts
  // sollen direkt vom Browser geladen werden.
  if (url.origin !== self.location.origin) return;
  if (event.request.method !== 'GET') return;

  const path = url.pathname;

  // Wortlisten und PHP immer frisch aus dem Netz.
  if (path.includes('/wordlists/') || path.endsWith('.php')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // index.html network-first: So erreichen Korrekturen installierte Geräte
  // sofort, und der Cache ist nur die Rückfallebene ohne Netz.
  if (event.request.mode === 'navigate' || path === '/' || path.endsWith('/index.html')) {
    event.respondWith(
      fetch(event.request)
        .then((res) => {
          if (res.ok && res.type === 'basic') {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put('./index.html', copy)).catch(() => {});
          }
          return res;
        })
        .catch(() => caches.match('./index.html').then((c) => c || Response.error()))
    );
    return;
  }

  // Übrige Shell-Dateien cache-first.
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((res) => {
        if (res.ok && res.type === 'basic' && isShellAsset(path)) {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy)).catch(() => {});
        }
        return res;
      });
    })
  );
});
