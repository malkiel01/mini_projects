/* ניווט לפי hash — כל מסך הוא כתובת, והחץ בכותרת חוזר למסך הקודם באפליקציה. האזנה ל-hashchange — ב-app.js. */
'use strict';
window.CA = window.CA || {};
CA.router = {
  /** "#/track/abc" → { name: 'track', arg: 'abc' } */
  parse(hash) {
    const parts = String(hash || '').replace(/^#\/?/, '').split('/').filter(Boolean).map(decodeURIComponent);
    const name = parts[0] || 'home';
    return { name, arg: parts[1] || null };
  },
};
