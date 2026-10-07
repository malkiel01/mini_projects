/**
 * האפליקציה נפתחת גם בלי רשת: השלד (הדף, הקוד, three.js, הגופן) נשמר במטמון.
 *
 * האסטרטגיה: רשת קודם, ומה שחוזר מהרשת מעדכן את המטמון; המטמון משמש רק
 * כשהרשת נכשלת. כך אחרי פריסה המשתמש מקבל את הגרסה החדשה מיד (בניגוד
 * ל-cache-first), ובלי רשת עדיין יש לו משהו לפתוח. ה-API (api.php) ותמונות
 * החומרים אינם נשמרים — נתונים תמיד מהשרת.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'carpentry-v1';

const SHELL = [
  './index.html',
  './view.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/icon.svg',
  './vendor/three.min.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // API ונתונים: תמיד רשת, בלי מטמון.
  if (url.pathname.endsWith('/api.php') || url.pathname.includes('/data/')) return;
  // רק מה ששייך לאפליקציה (אותו מקור) או three.js מה-CDN.
  const ours = url.origin === self.location.origin || /three(\.min)?\.js$/.test(url.pathname);
  if (!ours) return;
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok) caches.open(CACHE_VERSION).then((c) => c.put(req, res.clone()));
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true })),
  );
});
