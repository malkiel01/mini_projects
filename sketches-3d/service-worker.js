/**
 * Service worker של שרטוטים בתלת מימד — מה שמאפשר להתקין את הכלי כאפליקציה
 * בטלפון, ולעבוד בו גם בלי רשת (app.json: "install": {"offline": true}).
 *
 * כל הכלי הוא קבצים סטטיים, ולכן כולם נשמרים כבר בהתקנה (SHELL) — מהפתיחה
 * הראשונה הכלי נפתח גם בלי רשת. רשת קודם: מה שחוזר מהרשת מעדכן את המטמון,
 * והמטמון משמש רק כשהרשת נכשלה — כך אחרי פריסה מקבלים מיד את הגרסה החדשה.
 *
 * תשובה שהגיעה דרך הפניה (השער שולח לכניסה כשהחיבור פג) — לא נשמרת, אחרת
 * דף הכניסה היה נשמר במקום הכלי.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'sketches-3d-v1';

const SHELL = [
  './index.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/icon.svg',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
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
  if (url.origin !== self.location.origin) return;
  if (!url.pathname.startsWith(new URL(self.registration.scope).pathname)) return;   // רק התיקייה שלנו
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok && !res.redirected) { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || (req.mode === 'navigate' ? caches.match('./index.html') : undefined))
      .then((hit) => hit || Response.error())),
  );
});
