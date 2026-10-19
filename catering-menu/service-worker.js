/**
 * Service worker של התפריט הציבורי — מה שמאפשר להתקין אותו כאפליקציה בטלפון
 * (וגם את אפליקציית האנדרואיד, שהיא עטיפה סביב האתר), ולפתוח את השלד גם בלי רשת.
 *
 * רשת קודם: מה שחוזר מהרשת מעדכן את המטמון, והמטמון משמש רק כשהרשת נכשלה —
 * כך אחרי פריסה מקבלים מיד את הגרסה החדשה. התפריט, ההגדרות והלוגו (api.php)
 * ונתוני השרת (data/) — תמיד מהשרת. ממשק המנהל (admin/) — בכלל לא כאן: יש לו
 * service worker משלו (admin/sw.js), בלי מטמון.
 *
 * אחרי שינוי ברשימת השלד — להעלות את CACHE_VERSION.
 */
'use strict';

const CACHE_VERSION = 'catering-v1';

const SHELL = [
    './',
    './manifest.webmanifest',
    './assets/css/app.css',
    './assets/js/app.js',
    './assets/icon.svg',
    './assets/icon-192.png',
];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE_VERSION).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;              // גופנים מגוגל — לא שלנו
    const scope = new URL(self.registration.scope).pathname;
    const path = url.pathname.slice(scope.length);
    if (/\.php$/.test(url.pathname) || path.startsWith('admin/') || path.startsWith('data/')) return;
    event.respondWith(
        fetch(req).then(res => {
            if (res.ok) { const copy = res.clone(); caches.open(CACHE_VERSION).then(c => c.put(req, copy)); }
            return res;
        }).catch(() => caches.match(req, { ignoreSearch: true }).then(hit => hit || caches.match('./'))),
    );
});
