/*
 * מגדלור · הורים — service worker.
 *
 * תפקידו להפוך את הדף לאפליקציה שאפשר להתקין, ושהמעטפת תיפתח גם ברשת חלשה.
 * תמיד קודם רשת; המטמון רק כשאין. ה-API לא נשמר לעולם — נתוני ילדים לא
 * יושבים במטמון של מכשיר, וגם מצב ישן היה מטעה הורה שמאשר משהו "עכשיו".
 *
 * התראות (שלב 6): השרת שולח "צלצול" ריק; כאן שואלים אותו מה חדש (push_pending, עם
 * העוגייה) ומציגים. כך תוכן הבקשה — שם הילד והאתר — לא עובר דרך שירות ההתראות.
 */
const CACHE = 'migdalor-parent-v3';
const SHELL = ['./', 'index.html', 'app.js', 'manifest.webmanifest',
               '../assets/migdalor.css', '../assets/icon.svg', '../assets/icon-192.png',
               '../assets/vendor/qrcode.js'];

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

self.addEventListener('push', e => {
    e.waitUntil((async () => {
        let items = [];
        try {
            const sub = await self.registration.pushManager.getSubscription();
            const ep = sub ? '&ep=' + encodeURIComponent(sub.endpoint) : '';
            const res = await fetch('api.php?action=push_pending' + ep, { credentials: 'same-origin', cache: 'no-store' });
            items = (await res.json()).items || [];
        } catch { /* בלי רשת — ההודעה הכללית למטה */ }
        // צלצול חייב להסתיים בהתראה גלויה (userVisibleOnly), גם כשלא נמצא פירוט.
        if (!items.length) items = [{ title: 'מגדלור', body: 'יש בקשה חדשה מחכה לכם', link: './', tag: 'req' }];
        await Promise.all(items.map(it => self.registration.showNotification(it.title, {
            body: it.body, tag: it.tag, data: { link: it.link }, dir: 'rtl', lang: 'he',
            icon: '../assets/icon-192.png', badge: '../assets/icon-192.png', requireInteraction: true,
            actions: it.id ? [{ action: 'approve', title: '✓ אישור' }, { action: 'deny', title: '✕ דחייה' }] : [],
        })));
    })());
});

/* לחיצה: פותחים (או מקדימים) את האפליקציה על הבקשה. "דחייה" נעשית שם — עם העוגייה וה-CSRF. */
self.addEventListener('notificationclick', e => {
    e.notification.close();
    let link = (e.notification.data && e.notification.data.link) || './';
    if (e.action === 'approve' || e.action === 'deny') link += '&d=' + e.action;
    const url = new URL(link, self.registration.scope).href;
    e.waitUntil((async () => {
        const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const win = wins.find(w => w.url.startsWith(self.registration.scope));
        if (win) { await win.focus(); win.postMessage({ type: 'open', url }); return; }
        await self.clients.openWindow(url);
    })());
});
