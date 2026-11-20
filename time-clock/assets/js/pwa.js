'use strict';
/* העובד והעמדה: התקנה על המסך הראשי. הטווח צר לתיקייה של הדף — ממשק הניהול לא נכלל (הפלטפורמה מטפלת בו). */
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('../service-worker.js', { scope: './' }).catch(() => { /* בלי שירות — האפליקציה עובדת כרגיל */ });
}
