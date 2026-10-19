/**
 * Service worker של השרטוט לסוכה — מה שמאפשר להתקין אותו כאפליקציה בטלפון,
 * ולעבוד בו גם בלי רשת.
 *
 * אין לכלי שרת ואין לו נתונים: כל הדפים, הסקריפטים, three.js המקומי וקובצי ה-PDF
 * נשמרים כבר בהתקנה (SHELL), כך שאחרי פתיחה אחת עם רשת הכלי כולו עובד בלי רשת.
 *
 * רשת קודם: מה שחוזר מהרשת מעדכן את המטמון, והמטמון משמש רק כשהרשת נכשלה — כך
 * אחרי פריסה מקבלים מיד את הגרסה החדשה. תשובה שהופנתה (השער של הפלטפורמה שולח
 * לדף הכניסה) לא נשמרת, כדי שדף הכניסה לא יוגש במקום הכלי.
 * הפונט (Google Fonts) נשמר בפעם הראשונה שנטען; בלעדיו הדף נופל לפונט מערכת.
 * three.js מ-CDN אינו נשמר: בלי רשת הטעינה שלו נכשלת מיד, והדף עובר ל-vendor/three.min.js.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'sukkah-sketch-v1';

const SHELL = [
  './index.html',
  './parts.html',
  './angles.html',
  './manifest.webmanifest',
  './assets/js/model.js',
  './assets/js/parts.js',
  './assets/js/angles.js',
  './assets/css/parts.css',
  './assets/css/angles.css',
  './vendor/three.min.js',
  './assets/icon.svg',
  './assets/icons/icon-192.png',
  './parts.pdf',
  './angles.pdf',
];

const FONTS = /^https:\/\/fonts\.(googleapis|gstatic)\.com\//;

self.addEventListener('install', (event) => {
  // לא addAll: תשובה שהופנתה לדף הכניסה היא 200, ו-addAll היה שומר אותה במקום הכלי
  event.waitUntil(caches.open(CACHE_VERSION).then((c) => Promise.all(SHELL.map((u) =>
    fetch(u, { cache: 'reload' }).then((res) => {
      if (!res.ok || res.redirected) throw new Error(`${u}: ${res.status}${res.redirected ? ' (הופנה)' : ''}`);
      return c.put(u, res);
    }),
  ))).then(() => self.skipWaiting()));
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

  // הפונט: מהמטמון אם יש, אחרת מהרשת ונשמר
  if (FONTS.test(req.url)) {
    event.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok || res.type === 'opaque') { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    })));
    return;
  }
  if (url.origin !== self.location.origin) return;            // three.js מ-CDN — יש עותק מקומי

  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok && !res.redirected) { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || caches.match('./index.html'))),
  );
});
