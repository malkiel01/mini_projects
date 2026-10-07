/* Service worker של ממשק המנהל: מקבל התראות על הזמנות חדשות גם כשהדפדפן
   סגור (Web Push), ופותח את ההזמנה בלחיצה על ההתראה.
   בכוונה בלי מטמון — הממשק תמיד נטען עדכני מהשרת. */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch { /* תוכן לא תקין — מציגים כללי */ }

    const title = data.title || '🔔 הזמנה חדשה — ניחוחות';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || 'יש הזמנה חדשה שממתינה לאישור',
        icon: '../assets/icon-192.png',
        badge: '../assets/badge-96.png',
        tag: data.tag || 'catering',
        renotify: true,                       // גם אם יש כבר התראה — שוב צליל ורטט
        vibrate: [200, 100, 200, 100, 300],
        lang: 'he',
        dir: 'rtl',
        data: { url: data.url || 'admin/' },
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    // הכתובת יחסית לתיקיית הכלי (catering-menu/)
    const target = new URL('../' + (event.notification.data?.url || 'admin/'), self.registration.scope).href;
    event.waitUntil((async () => {
        const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const win of wins) {
            if (win.url.startsWith(self.registration.scope) && 'focus' in win) {
                await win.focus();
                win.postMessage({ type: 'open-order', url: target });
                return;
            }
        }
        await self.clients.openWindow(target);
    })());
});
