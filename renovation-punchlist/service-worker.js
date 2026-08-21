/**
 * עבודה ללא אינטרנט — הכלי משמש בסיורים באתר בנייה, שם הקליטה גרועה.
 *
 * חשוב: אחרי כל שינוי בקבצים כאן יש להעלות את CACHE_VERSION,
 * אחרת מבקרים חוזרים ימשיכו לקבל את הגרסה הישנה מהמטמון.
 */

const CACHE_VERSION = 'punchlist-v1';

const SHELL = [
  './',
  './index.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/js/db.js',
  './assets/js/media.js',
  './assets/js/sync.js',
  './assets/icons/icon.svg',
  './assets/icons/icon-maskable.svg',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => cache.addAll(SHELL))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key)),
      ))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  // בקשות סנכרון חייבות להגיע לשרת עצמו — תשובה ממטמון תחזיר נתונים ישנים.
  if (url.pathname.endsWith('/api.php')) return;

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request)
        .then((response) => {
          // רק תשובות תקינות מאותו מקור נשמרות; שגיאה במטמון היא באג דביק.
          if (response.ok && response.type === 'basic') {
            const copy = response.clone();
            caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(() => (request.mode === 'navigate' ? caches.match('./index.html') : Response.error()));
    }),
  );
});
