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
// הקידומת ייחודית לאפליקציה הזו: כמה כלים יושבים על אותו מקור (mbe-plus.com), ולכן מוחקים רק מטמון שלנו.
const PREFIX = 'migdalor-parent-';
const CACHE = PREFIX + 'v4';
// רק המעטפת והמשאבים הסטטיים האלה נשמרים — שום דבר אחר (API, זהות, דפי התחברות, תשובות תלויות משתמש).
const SHELL = ['./', 'index.html', 'app.js', 'manifest.webmanifest',
               '../assets/migdalor.css', '../assets/icon.svg', '../assets/icon-192.png',
               '../assets/vendor/qrcode.js'];
const SHELL_URLS = new Set(SHELL.map(p => new URL(p, self.registration ? self.registration.scope : self.location.href).href));

/** מסך לא מקוון — כשאין רשת ואין מעטפת שמורה. */
const OFFLINE_HTML = '<!doctype html><html lang="he" dir="rtl"><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1"><title>מגדלור · הורים</title>'
    + '<body style="font:16px/1.6 system-ui;margin:0;display:grid;place-items:center;min-height:100vh;padding:16px;text-align:center">'
    + '<div><div style="font-size:48px">📡</div><h1 style="font-size:20px">אין חיבור לאינטרנט</h1>'
    + '<p>מגדלור להורים צריך רשת כדי להציג ולשנות הגדרות. נסו שוב כשיש חיבור.</p>'
    + '<button onclick="location.reload()" style="font:inherit;padding:10px 20px">נסו שוב</button></div>';

/** האם מותר לשמור את התשובה: מהמעטפת, תקינה, לא הפניה (למשל לדף התחברות), ולא no-store. */
function cacheable(req, res) {
    if (!res || !res.ok || res.type !== 'basic' || res.redirected) return false;
    if (/no-store|private/i.test(res.headers.get('Cache-Control') || '')) return false;
    return SHELL_URLS.has(stripSearch(req.url));
}
function stripSearch(u) { const x = new URL(u); x.search = ''; x.hash = ''; return x.href; }

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
    // רק מטמונים ישנים *שלנו* — מטמון של אפליקציה אחרת באותו מקור שורד.
    e.waitUntil(caches.keys()
        .then(keys => Promise.all(keys.filter(k => k.startsWith(PREFIX) && k !== CACHE).map(k => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
    const req = e.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== location.origin) return;
    const shell = SHELL_URLS.has(stripSearch(req.url));
    // מה שאינו מהמעטפת (API, push_pending, כל השאר) — רשת בלבד, בלי מטמון ובלי להתערב.
    if (!shell && req.mode !== 'navigate') return;
    e.respondWith(fetch(req).then(res => {
        if (cacheable(req, res)) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(stripSearch(req.url), copy)); }
        return res;
    }).catch(async () => {
        // בלי רשת: המשאב המדויק מהמעטפת; ניווט — דף המעטפת; אחרת — מסך לא מקוון.
        const c = await caches.open(CACHE);
        const hit = shell ? await c.match(stripSearch(req.url)) : null;
        if (hit) return hit;
        if (req.mode === 'navigate') {
            const page = await c.match(new URL('index.html', self.registration.scope).href);
            if (page) return page;
            return new Response(OFFLINE_HTML, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
        }
        return Response.error();
    }));
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
