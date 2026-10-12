/* Service worker של ניהול הפרויקטים.
   כרגע רק מאפשר התקנה כאפליקציה עצמאית — בכוונה בלי מטמון: הממשק נטען תמיד
   עדכני מהשרת. בשלב 4 יקלוט כאן קבצים ששותפו לאפליקציה (share_target). */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
