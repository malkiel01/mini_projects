// שלב 1: מציג את בדיקת השרת. נתיבים יחסיים בלבד — האפליקציה תעבור לדומיין משלה.
(function () {
  'use strict';

  const LABELS = {
    sqlite: 'מסד נתונים',
    crypto: 'הצפנת תוכן',
    mail: 'שליחת מייל (mail)',
    zip: 'קובצי ZIP ו-Word'
  };

  const list = document.getElementById('health');

  function row(text, ok) {
    const li = document.createElement('li');
    li.className = ok ? 'ok' : 'bad';
    li.textContent = (ok ? '✓ ' : '✗ ') + text;
    return li;
  }

  fetch('api.php?action=health', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (h) {
      list.replaceChildren();
      if (!h.ok) { list.append(row('השרת החזיר שגיאה', false)); return; }
      Object.keys(LABELS).forEach(function (k) { list.append(row(LABELS[k], !!h[k])); });
      list.append(row('PHP ' + h.php, true));
    })
    .catch(function () {
      list.replaceChildren(row('אין תשובה מהשרת', false));
    });
})();
