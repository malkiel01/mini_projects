/**
 * Service worker של איחוד PDF — מה שמאפשר להתקין את הכלי כאפליקציה בטלפון,
 * ולעבוד בו גם בלי רשת: הכלי כולו רץ בדפדפן, ולכן כל מה שצריך לעבודה נשמר
 * כאן מראש — הדף, העיצוב, הקוד והספריות (pdf-lib, pdf.js והעובד שלו).
 *
 * רשת קודם: מה שחוזר מהרשת מעדכן את המטמון, והמטמון משמש רק כשהרשת נכשלה —
 * כך אחרי פריסה מקבלים מיד את הגרסה החדשה. תשובה שהופנתה (השער של הפלטפורמה
 * שולח לדף הכניסה כשהחיבור פג) אינה נשמרת — אחרת דף הכניסה היה מחליף את הכלי.
 *
 * אחרי שינוי ברשימה — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'pdf-merger-v1';

const OFFLINE = [
  './index.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/vendor/pdf-lib.min.js',
  './assets/vendor/pdf.min.js',
  './assets/vendor/pdf.worker.min.js',
  './assets/icon.svg',
  './assets/icons/icon-192.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_VERSION).then((c) => c.addAll(OFFLINE)).then(() => self.skipWaiting()));
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
  if (url.origin !== self.location.origin) return;
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok && !res.redirected) { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true })
      .then((hit) => hit || (req.mode === 'navigate' ? caches.match('./index.html') : Response.error()))),
  );
});
