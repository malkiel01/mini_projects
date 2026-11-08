// Service Worker של "אסטרטגיות למסחר" — רק לפוש (spec-stage-a 8.3). אין כאן מטמון ואין fetch handler.
// הפוש מגיע בלי תוכן (VAPID בלבד); כשהוא מגיע — בקשה אחת לתיבה (inbox_peek) והצגת ההודעה האחרונה. אין כאן טיימר.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  const show = (title, body, tag) => self.registration.showNotification(title, {
    body, tag, lang: 'he', dir: 'rtl', icon: 'assets/icon.svg', data: { url: './#/alerts' },
  });
  e.waitUntil(fetch('api.php?action=inbox_peek', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => (d.event ? show(d.event.payload.title, d.event.payload.body, 'ts-' + d.event.id) : show('התראה חדשה', 'פתחו את התיבה באפליקציה.', 'ts')))
    .catch(() => show('התראה חדשה', 'פתחו את התיבה באפליקציה.', 'ts')));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL(e.notification.data?.url || './#/alerts', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    const same = list.find(c => c.url.startsWith(self.registration.scope));
    return same ? same.focus().then(c => c.navigate(url)) : self.clients.openWindow(url);
  }));
});
