/**
 * Service worker של הדפדפן המשובץ — מה שמאפשר להתקין אותו כאפליקציה בטלפון,
 * ולפתוח את השלד גם בלי רשת.
 *
 * רשת קודם: מה שחוזר מהרשת מעדכן את המטמון, והמטמון משמש רק כשהרשת נכשלה —
 * כך אחרי פריסה מקבלים מיד את הגרסה החדשה. api.php לעולם אינו נשמר כאן: הבדיקה
 * אם אתר מרשה הטמעה חייבת להיות טרייה. האתרים שבמסגרות — ממקור אחר, ולא עוברים כאן.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'frame-browser-v1';

const SHELL = [
  './',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/icon.svg',
  './manifest.webmanifest',
  './assets/icons/icon-192.png',
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
  if (url.origin !== self.location.origin) return;   // האתרים שבמסגרות — לא שלנו
  if (/\.php$/.test(url.pathname)) return;           // הבדיקה: תמיד רשת
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || caches.match('./'))),
  );
});
