/**
 * שירות לעובד (e/) ולעמדה (s/) — מותקנים על המסך הראשי, והדף נפתח גם כשהקליטה חלשה.
 *
 * ⚠️ זה לא "דיווח בלי רשת": כל דיווח, בקשה ואישור עוברים ל-api.php, ואלה לעולם לא נשמרים כאן. בלי רשת
 * רואים את הדף, והפעולות נכשלות עם הודעה. דיווח בלי רשת (תור במכשיר) הוא פיתוח נפרד, שטרם הוחלט.
 *
 * רשת קודם, מטמון רק כגיבוי: כך אחרי פריסה מקבלים את הגרסה החדשה בלי להעלות מספר גרסה ידנית.
 * רק תשובות תקינות מאותו מקור נשמרות. ממשק הניהול לא רשום כאן (הפלטפורמה מטפלת בהתקנה שלו).
 */

const CACHE = 'time-clock-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', event => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  // נתונים (כולל אישורי מחלה — מידע רפואי) תמיד מהשרת, ולעולם לא מהמטמון
  if (url.pathname.endsWith('/api.php')) return;

  event.respondWith(
    fetch(request)
      .then(response => {
        if (response.ok && response.type === 'basic') {
          const copy = response.clone();
          caches.open(CACHE).then(c => c.put(request, copy));
        }
        return response;
      })
      .catch(() => caches.match(request).then(hit => hit || Response.error())),
  );
});
