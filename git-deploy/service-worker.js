/**
 * Service worker של git-deploy — מה שמאפשר להתקין אותה כאפליקציה בטלפון.
 *
 * נוגע רק בקבצים הסטטיים (assets/, האייקונים, ה-manifest): רשת קודם, והמטמון רק כשהרשת
 * נכשלה. דפי ה-PHP וה-API — לעולם לא: הם מציגים ריפוז, תיקיות בשרת ופלט של git, ופועלים
 * בשרת. אין מה להציג בלי רשת, ושום דבר מהם לא נשאר במכשיר.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'git-deploy-v1';

const SHELL = [
  './manifest.webmanifest',
  './assets/style.css',
  './assets/app.js',
  './assets/icon.svg',
  './assets/icons/icon-192.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_VERSION).then((c) => c.addAll(SHELL)).catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('git-deploy-') && k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const scope = new URL(self.registration.scope).pathname;
  const rel = url.pathname.startsWith(scope) ? url.pathname.slice(scope.length) : null;
  if (rel === null || !(rel.startsWith('assets/') || rel === 'manifest.webmanifest')) return;   // דפים ו-API: תמיד רשת
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || Response.error())),
  );
});
