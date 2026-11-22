/*
 * ספורנו — ה-service worker (אפיון 06 §2). ה-scope: התיקייה של ספורנו בלבד; לא נוגע בשום כתובת מחוץ לה.
 *
 *   מעטפת — הקבצים של האפליקציה (SHELL) נשמרים במטמון "sforno-shell-<VERSION>-<BUILD>" ומוגשים ממנו (גם בלי רשת).
 *           VERSION = "version" שב-app.json; BUILD = hash של קובצי המעטפת (tests/j-offline בודק ששניהם עדכניים — אחרת
 *           הפריסה נעצרת; עדכון: SF_UPDATE_SW=1 php sforno/tests/run.php). שינוי בקובץ ← sw.js חדש ← הדפדפן מתקין אותו
 *           **וממתין**: הפס "עדכון זמין — לרענן" (assets/js/offline.js). מתחלף רק אחרי SKIP_WAITING מהקורא — לא באמצע תפילה.
 *   הורדות — המטמון "sforno-content" שייך לדף (offline-core.js). כאן רק מגישים ממנו את התמונות (read-file) כשאין רשת.
 *           **activate מוחק רק מטמונים ישנים של המעטפת** — לא את ההורדות, ולא נוגע ב-IndexedDB (המידע האישי).
 *   API — לא נשמר ולא מוגש מכאן (חוץ מ-read-file מתוך הורדה). data/ ושאר התיקיות שאינן של הדפדפן — לא נוגעים.
 *   זמני היום (07) — רשימת הערים (assets/data/cities.json) היא חלק מהמעטפת; הזמנים עצמם מחושבים בדף, וחבילת החודש —
 *           ב-IndexedDB (zdl), לא כאן.
 *   תזכורות (08) — push: התוכן מוצפן מהשרת (aes128gcm) ומפוענח בדפדפן; כאן רק מציגים {t, b, u, tag}. לחיצה ← הכתובת u
 *           (התפילה המקושרת בסידור), **רק בתוך ה-scope** — כתובת אחרת לא נפתחת. אין כאן שום שמירה ושום רשת.
 */
'use strict';

const VERSION = '0.10';
const BUILD = '46d77063c6ea';
const SHELL_CACHE = 'sforno-shell-' + VERSION + '-' + BUILD;
const CONTENT_CACHE = 'sforno-content';

const SHELL = [
    './',
    'index.html',
    'manifest.webmanifest',
    'assets/icon.svg',
    'assets/icon-192.png',
    'assets/icon-512.png',
    'assets/icon-maskable-512.png',
    'assets/apple-touch-icon.png',
    'assets/css/app.css',
    'assets/css/devloop.css',
    'assets/fonts/DavidLibre-Regular.ttf',
    'assets/fonts/DavidLibre-Bold.ttf',
    'assets/js/devloop.js',
    'assets/js/api.js',
    'assets/js/router.js',
    'assets/js/render.js',
    'assets/js/reader-core.js',
    'assets/js/store.js',
    'assets/js/sync-core.js',
    'assets/js/sync.js',
    'assets/js/offline-core.js',
    'assets/js/offline.js',
    'assets/js/calendar-core.js',
    'assets/js/daily-core.js',
    'assets/js/daily.js',
    'assets/js/reminders-core.js',
    'assets/js/reminders.js',
    'assets/js/screens/home.js',
    'assets/js/screens/reader.js',
    'assets/js/screens/siddur.js',
    'assets/js/screens/downloads.js',
    'assets/js/screens/zmanim.js',
    'assets/js/screens/halacha.js',
    'assets/js/screens/reminders.js',
    'assets/js/screens/store.js',
    'assets/js/screens/admin.js',
    'assets/js/screens/content.js',
    'assets/js/screens/import.js',
    'assets/js/screens/reader-admin.js',
    'assets/js/screens/daily-admin.js',
    'assets/js/screens/store-admin.js',
    'assets/js/screens/ops.js',
    'assets/js/screens/dev.js',
    'assets/js/screens/account.js',
    'assets/js/app.js',
    'assets/vendor/pdfjs/pdf.min.js',
    'assets/vendor/pdfjs/pdf.worker.min.js',
    'assets/data/cities.json',
];

const scopePath = () => new URL(self.registration.scope).pathname;

self.addEventListener('install', event => {
    // בלי skipWaiting: גרסה חדשה ממתינה עד שהקורא בוחר "לרענן"
    event.waitUntil(caches.open(SHELL_CACHE).then(c => c.addAll(SHELL.map(u => new Request(u, { cache: 'reload', credentials: 'same-origin' })))));
});

self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys()
        .then(keys => Promise.all(keys.filter(k => k.startsWith('sforno-shell-') && k !== SHELL_CACHE).map(k => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin || !url.pathname.startsWith(scopePath())) return;
    const path = url.pathname.slice(scopePath().length);

    // תמונה / עמוד מתוך הורדה: קודם ההורדה (הקובץ לא משתנה), אחר כך הרשת
    if (path === 'api.php') {
        if (url.searchParams.get('action') !== 'read-file') return;
        event.respondWith(caches.open(CONTENT_CACHE).then(c => c.match(req)).then(hit => hit || fetch(req)));
        return;
    }
    if (/\.php$/.test(path) || /^(data|lib|tests|team|docs|dev)\//.test(path)) return;

    // המעטפת: מהמטמון של הגרסה הזו; דף (ניווט) — תמיד index.html של הגרסה
    const shellKey = req.mode === 'navigate' && (path === '' || path === 'index.html') ? './' : path;
    if (!SHELL.includes(shellKey) && !(req.mode === 'navigate' && shellKey === './')) {
        event.respondWith(fetch(req).catch(() => caches.match(req).then(hit => hit || Response.error())));
        return;
    }
    event.respondWith(caches.open(SHELL_CACHE).then(c => c.match(shellKey)).then(hit => hit || fetch(req)));
});

// ── התראות (08) ─────────────────────────────────────────────
self.addEventListener('push', event => {
    let m = {};
    try { m = event.data ? event.data.json() : {}; } catch (e) { m = {}; }
    const u = typeof m.u === 'string' && /^#\/[A-Za-z0-9/_-]*$/.test(m.u) ? m.u : '#/reminders';
    event.waitUntil(self.registration.showNotification(typeof m.t === 'string' && m.t ? m.t.slice(0, 80) : 'ספורנו', {
        body: typeof m.b === 'string' ? m.b.slice(0, 160) : '', tag: typeof m.tag === 'string' ? m.tag.slice(0, 40) : undefined,
        icon: 'assets/icon-192.png', badge: 'assets/icon-192.png', lang: 'he', dir: 'rtl', data: { u },
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const u = (event.notification.data && event.notification.data.u) || '#/reminders';
    const target = new URL('index.html' + u, self.registration.scope).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
        for (const c of list) {
            if (new URL(c.url).pathname.startsWith(scopePath()) && 'focus' in c) return c.navigate(target).then(w => (w || c).focus()).catch(() => c.focus());
        }
        return self.clients.openWindow(target);
    }));
});
