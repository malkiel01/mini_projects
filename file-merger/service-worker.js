/**
 * Service worker לעבודה גם ללא אינטרנט.
 *
 * האפליקציה סטטית לחלוטין, ולכן האסטרטגיה היא cache-first עם רשת כגיבוי.
 * שינוי בקבצים מחייב העלאת CACHE_VERSION — אחרת המשתמש ימשיך לקבל את הגרסה הישנה.
 */
const CACHE_VERSION = 'v1';
const CACHE_NAME = `file-merger-${CACHE_VERSION}`;

const PRECACHE = [
  './',
  './index.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/icons/icon.svg',
  './assets/icons/icon-maskable.svg',
  './vendor/pdf-lib.min.js',
  './vendor/jszip.min.js',
  './vendor/pdf.min.mjs',
  './vendor/pdf.worker.min.mjs',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  event.respondWith(
    caches.match(request).then((hit) => {
      if (hit) return hit;
      return fetch(request)
        .then((response) => {
          // שומרים רק תשובות תקינות מאותו מקור, כדי לא להרעיל את המטמון.
          if (response.ok && response.type === 'basic') {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(() => {
          // ניווט במצב לא-מקוון: מגישים את המעטפת של האפליקציה.
          if (request.mode === 'navigate') return caches.match('./index.html');
          throw new Error('offline');
        });
    }),
  );
});
