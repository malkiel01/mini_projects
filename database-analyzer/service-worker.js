/**
 * Service worker של אנליסט מסד הנתונים — מה שמאפשר להתקין אותו כאפליקציה בטלפון,
 * ולפתוח את המסך גם בלי רשת (בלי רשת אין גם חיבור למסד, אבל לא מסך לבן).
 *
 * רשת קודם: מה שחוזר מהרשת מעדכן את המטמון, והמטמון משמש רק כשהרשת נכשלה —
 * כך אחרי פריסה מקבלים מיד את הגרסה החדשה. api.php לעולם אינו נשמר: פרטי
 * החיבור והנתונים של המסד עוברים רק בין הדף לשרת.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'database-analyzer-v1';

const SHELL = [
  './index.html',
  './manifest.webmanifest',
  './assets/icon.svg',
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
  if (url.origin !== self.location.origin) return;
  if (/\.php$/.test(url.pathname)) return;   // api.php — תמיד רשת
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok && !res.redirected) { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || caches.match('./index.html'))),
  );
});
