/*
 * מגדלור · הורים — service worker.
 *
 * תפקידו להפוך את הדף לאפליקציה שאפשר להתקין, ושהמעטפת תיפתח גם ברשת חלשה.
 * תמיד קודם רשת; המטמון רק כשאין. ה-API לא נשמר לעולם — נתוני ילדים לא
 * יושבים במטמון של מכשיר, וגם מצב ישן היה מטעה הורה שמאשר משהו "עכשיו".
 */
const CACHE = 'migdalor-parent-v1';
const SHELL = ['./', 'index.html', 'app.js', 'manifest.webmanifest',
               '../assets/migdalor.css', '../assets/icon.svg', '../assets/icon-192.png'];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
    e.waitUntil(caches.keys()
        .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.endsWith('/api.php')) return;
    e.respondWith(fetch(e.request).then(res => {
        if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)); }
        return res;
    }).catch(() => caches.match(e.request, { ignoreSearch: true })));
});
