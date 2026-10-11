/**
 * Service worker של אפליקציית המתכונים — מה שמאפשר להתקין אותה כאפליקציה
 * בטלפון, ולפתוח את השלד גם בלי רשת.
 *
 * רשת קודם: מה שחוזר מהרשת מעדכן את המטמון, והמטמון משמש רק כשהרשת נכשלה.
 * כך אחרי פריסה מקבלים מיד את הגרסה החדשה (וגם פס "יש גרסה חדשה" ממשיך
 * לעבוד). נתונים לעולם אינם נשמרים כאן: api.php, pantry.php, logs.php,
 * המדיה שבתיקיית data — תמיד מהשרת. מתכון פרטי לא יישאר במטמון של מכשיר.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */

const CACHE_VERSION = 'recipes-v1';

const SHELL = [
  './index.html',
  './manifest.webmanifest',
  './assets/css/app.css',
  './assets/js/app.js',
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
  if (url.origin !== self.location.origin) return;            // תמונות מאתרים אחרים — לא שלנו
  if (/\.php$/.test(url.pathname) || url.pathname.includes('/data/')) return;   // נתונים: תמיד רשת
  event.respondWith(
    fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE_VERSION).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: true }).then((hit) => hit || caches.match('./index.html'))),
  );
});

// ── התראות לטלפון (lib/push.php) ──
// הצלצול ריק: שואלים את השרת מה חדש (עם העוגייה של המשתמש), ומציגים. שמות ומתכונים לא
// עוברים דרך שירות ההתראות. Chrome דורש להציג התראה על כל צלצול — ולכן גם כשלא הגיע
// כלום (אין רשת, החיבור פג) מוצגת התראה כללית.
const ICON = './assets/icons/icon-192.png';
self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    let items = [];
    try {
      const r = await fetch('./api.php?action=push-pending', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      items = (await r.json()).items || [];
    } catch { /* בלי רשת — התראה כללית */ }
    if (items.length === 1) {
      const it = items[0];
      return self.registration.showNotification(it.title, { body: it.body, icon: ICON, badge: ICON, tag: 'recipes', renotify: true, data: { link: it.link }, dir: 'rtl', lang: 'he' });
    }
    return self.registration.showNotification(items.length ? `${items.length} עדכונים חדשים` : 'מתכונים',
      { body: items.length ? items.map((i) => i.title).join('\n') : 'יש עדכון חדש', icon: ICON, badge: ICON, tag: 'recipes', renotify: true, data: { link: '#/activity' }, dir: 'rtl', lang: 'he' });
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const link = event.notification.data?.link || '#/activity';
  const url = new URL('./index.html' + link, self.registration.scope).href;
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if (w.url.startsWith(self.registration.scope)) { await w.focus(); return w.navigate(url); }
    }
    return self.clients.openWindow(url);
  })());
});
