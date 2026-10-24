/**
 * וקטורי נפתח גם בלי רשת: הדפים, הקוד, הגופנים והספריות נשמרים במטמון.
 *
 * רשת קודם — מה שחוזר מעדכן את המטמון, והמטמון רק כשהרשת נכשלת. כך אחרי פריסה מקבלים את הגרסה החדשה מיד.
 * api.php לא נשמר: הציורים תמיד מהשרת (ובלי חיבור — העורך שומר גיבוי במכשיר עד שהחיבור חוזר).
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'vector-studio-v1';

const SHELL = [
  './index.html',
  './editor.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/icon.svg',
  './assets/js/app.js',
  './assets/js/editor.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('vector-studio-') && k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const scope = new URL(self.registration.scope).pathname;
  if (!url.pathname.startsWith(scope) || url.pathname.endsWith('/api.php')) return;
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
      }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: url.pathname.endsWith('.html') }).then((hit) => hit || Response.error())),
  );
});
