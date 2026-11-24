// service worker של עובד השטח (פרוסה 04) — רק Background Sync של תור ההעלאות: קובץ שצולם בלי קליטה נשלח כשחוזרת רשת,
// גם אחרי שהמסך נסגר (אנדרואיד/Chrome). בלי fetch handler ובלי מטמון — לא נוגע בשום בקשה אחרת ולא שומר כלום מחוץ לתור.
// אותו תור (IndexedDB fd_field) ואותו upload_id כמו הדף — field-core.js. דפדפן בלי Background Sync (iOS) — הדף שולח בפתיחה.
'use strict';
importScripts('assets/js/field-core.js');
const W = self.FDField;
const TAG = 'fd-field-queue';
// אותה נעילה כמו בדף: לשונית פתוחה וה-service worker לא שולחים במקביל
const lock = fn => (self.navigator && navigator.locks ? navigator.locks.request('fd-field-flush', fn) : fn());

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('sync', e => {
  if (e.tag !== TAG) return;
  e.waitUntil(W.backgroundSync({store: W.idbStore(indexedDB), fetchFn: (u, o) => fetch(u, o), FormDataCtor: FormData, lock})
    .then(left => { if (left > 0) throw new Error('נשאר בתור — הדפדפן ינסה שוב'); }));
});
