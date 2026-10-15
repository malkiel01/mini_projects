// Service worker של משפחת האפליקציות (platform/README.md, "התקנה והורדה").
//
// יושב בשורש כדי שהטווח שלו יכלול את כל הכלים. כלי עם service worker משלו
// (נגרות, איחוד קבצים…) — שלו גובר בתוך התיקייה שלו, וזה לא נוגע בו.
//
// עקרון: לא מפריע. בקשה שאינה ברשימה למטה — לא נוגעים בה בכלל (API, וידאו,
// העלאות). ומה שכן: קודם רשת, ורק בלי רשת — מהמטמון. מטמון מתמלא בשני
// מקומות: המעטפת (בהתקנה), ו"הורדת המשפחה" שבדף (app.js) — ומה שדפים שהורדו
// טוענים בפועל.

const CACHE = 'family-v1';
const SHELL = [
    './', 'index.html',
    'platform/assets/app.js', 'platform/assets/admin.js', 'platform/assets/app.css',
    'platform/manifest.webmanifest', 'platform/assets/icons/icon-192.png',
];
// ספריות חיצוניות שכלים טוענים — נשמרות כשהורדו, כדי שהכלי יעבוד בלי רשת.
const CDN = ['cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];
const STATIC = /\.(?:html?|m?js|css|json|webmanifest|svg|png|jpe?g|gif|webp|ico|woff2?|ttf|otf|wasm)$/i;

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

function handled(req, url) {
    if (req.method !== 'GET') return false;
    if (url.origin !== location.origin) return CDN.includes(url.hostname);
    if (!url.pathname.startsWith(new URL('./', self.registration.scope).pathname)) return false;
    // רשימת האפליקציות וזהות המשתמש — כדי שהמשפחה תוצג גם בלי רשת.
    if (url.pathname.endsWith('/platform/api.php')) return ['me', 'apps'].includes(url.searchParams.get('action'));
    return req.mode === 'navigate' || STATIC.test(url.pathname) || url.pathname.endsWith('/');
}

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (!handled(e.request, url)) return;
    e.respondWith((async () => {
        const cache = await caches.open(CACHE);
        try {
            const res = await fetch(e.request);
            // רק תשובה תקינה, שאינה הפניה (למשל לדף הכניסה של השער), מרעננת את
            // המטמון — ורק עבור מה שכבר נשמר בו: אין צבירה של כל מה שעובר ברשת.
            // רשימת האפליקציות נשמרת תמיד; ביציאה מהחשבון app.js מוחק הכול.
            // וגם: מה שדף שמור במטמון מבקש (ספרייה שנטענת בזמן ריצה, מודול שמיובא
            // ממודול) — כך פתיחה אחת של אפליקציה שהורדה משלימה את מה שחסר בה.
            const isList = url.pathname.endsWith('/platform/api.php');
            const keep = isList || await cache.match(e.request)
                || (e.request.referrer && await cache.match(e.request.referrer, { ignoreSearch: true }));
            if (res.ok && !res.redirected && keep) cache.put(e.request, res.clone());
            return res;
        } catch (err) {
            const hit = await cache.match(e.request, { ignoreVary: true });
            if (hit) return hit;
            if (e.request.mode === 'navigate') {
                const shell = await cache.match('./');
                if (shell && url.pathname === new URL('./', self.registration.scope).pathname) return shell;
            }
            throw err;
        }
    })());
});

// ---------- התראות (Web Push) — למנהל, על כל הרשמה חדשה ----------
// המנוי נרשם מממשק המנהל (platform/assets/admin.js); השרת שולח מ-platform/lib/push.php.

self.addEventListener('push', (e) => {
    let data = {};
    try { data = e.data ? e.data.json() : {}; } catch { /* תוכן לא תקין — הודעה כללית */ }
    e.waitUntil(self.registration.showNotification(data.title || '🔔 כלים דיגיטליים', {
        body: data.body || '',
        icon: 'platform/assets/icons/icon-192.png',
        badge: 'platform/assets/icons/favicon.png',
        tag: data.tag || 'platform',
        renotify: true,
        lang: 'he',
        dir: 'rtl',
        data: { url: data.url || '' },
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    // הכתובת יחסית לשורש האתר — למשל "#admin" פותח את ממשק הניהול.
    const target = new URL('./' + (e.notification.data?.url || ''), self.registration.scope).href;
    e.waitUntil((async () => {
        const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const home = new URL('./', self.registration.scope).href;
        for (const win of wins) {
            // חלון של הדף הראשי (לא של כלי) — מתמקדים בו ומנווטים.
            if (win.url.split('#')[0].split('?')[0] === home && 'focus' in win) {
                await win.focus();
                if ('navigate' in win) await win.navigate(target);
                return;
            }
        }
        await self.clients.openWindow(target);
    })());
});
