/*
 * Service worker — כדי שהאפליקציה תהיה ניתנת להתקנה, ותיפתח גם כשהרשת חלשה.
 * קודם רשת; המטמון רק לגיבוי של המעטפת. API לעולם לא נשמר במטמון.
 */
const CACHE = 'ob-shell-v1';
const SHELL = ['./', 'index.html', 'assets/css/app.css', 'assets/js/app.js', 'assets/icon.svg', 'manifest.webmanifest'];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).catch(() => {}));
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))));
    self.clients.claim();
});

self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.endsWith('/api.php')) return;
    e.respondWith(
        fetch(e.request)
            .then(res => {
                if (res.ok && SHELL.some(p => url.pathname.endsWith(p.replace('./', '/')))) {
                    const copy = res.clone();
                    caches.open(CACHE).then(c => c.put(e.request, copy));
                }
                return res;
            })
            .catch(() => caches.match(e.request).then(r => r || caches.match('index.html')))
    );
});
