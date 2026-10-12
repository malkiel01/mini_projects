/* Service worker של ניהול הפרויקטים.
   - מאפשר התקנה כאפליקציה עצמאית — בכוונה בלי מטמון: הממשק נטען תמיד עדכני מהשרת.
   - קולט שיתוף מאפליקציות אחרות ("שתף אל…", share_target במניפסט): הקבצים נשמרים
     במכשיר (ShareStore), והאפליקציה נפתחת במסך "לאן לשמור?" (#/share).
     השיתוף לא מגיע לשרת בכלל עד שבוחרים פרויקט ותיקייה. */
'use strict';

importScripts('assets/js/share-store.js?v=1');

const SHARE_PATH = new URL('share-target', self.registration.scope).pathname;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (event.request.method === 'POST' && url.pathname === SHARE_PATH) event.respondWith(receive(event.request));
    // כל השאר — ישר לשרת, בלי מטמון
});

async function receive(request) {
    const back = hash => Response.redirect(new URL('./' + hash, self.registration.scope).href, 303);
    try {
        const form = await request.formData();
        const files = form.getAll('files').filter(f => f instanceof File && f.size > 0);
        const item = {
            id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
            at: new Date().toISOString(),
            title: String(form.get('title') || ''),
            text: String(form.get('text') || ''),
            url: String(form.get('url') || ''),
            files,
        };
        if (files.length || item.text || item.url) await ShareStore.add(item);
        return back('#/share');
    } catch {
        return back('#/share/error');
    }
}
