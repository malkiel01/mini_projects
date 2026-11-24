/* ללימוד סייבר התקפי — מושך מ-api/progress.php (שקורא מהריפו בצד השרת). בלי סודות בדפדפן. */
(function () {
  'use strict';

  const STATUS = { done: 'בוצע', verified: 'אומת' };
  const MESSAGES = {
    no_token: 'עדיין לא הוגדר סוד לקריאה. הגדר אותו במסך ההגדרות.',
    no_file: 'ChatGPT עדיין לא יצר את קובץ ההתקדמות בריפו.',
    unauthorized: 'הסוד לא מתאים לריפו או שאין לו הרשאת קריאה.',
    invalid: 'קובץ ההתקדמות בריפו בפורמט שגוי, ולכן לא מוצג.',
    unavailable: 'לא הצלחתי לטעון כרגע. נסה שוב מאוחר יותר.',
  };

  const $ = (id) => document.getElementById(id);

  function show(id, text) {
    const el = $(id);
    el.textContent = text;
    el.hidden = false;
  }

  function render(items) {
    const list = $('items');
    list.textContent = '';
    if (!items.length) {
      show('empty', 'עדיין אין תרגולים מתועדים.');
      $('summary').textContent = '';
      return;
    }
    const sorted = [...items].sort((a, b) => String(b.date).localeCompare(String(a.date)));
    const verified = items.filter((i) => i.status === 'verified').length;
    $('summary').textContent = `סה"כ ${items.length} תרגולים · מתוכם ${verified} אומתו`;
    for (const it of sorted) {
      const li = document.createElement('li');
      li.className = 'item status-' + (it.status === 'verified' ? 'verified' : 'done');

      const top = document.createElement('div');
      top.className = 'top';
      const topic = document.createElement('strong');
      topic.textContent = String(it.topic || '');
      const badge = document.createElement('span');
      badge.className = 'badge';
      badge.textContent = STATUS[it.status] || STATUS.done;
      top.append(topic, badge);

      const date = document.createElement('div');
      date.className = 'date';
      date.textContent = String(it.date || '');

      li.append(top, date);
      if (it.note) {
        const note = document.createElement('p');
        note.className = 'note';
        note.textContent = String(it.note);
        li.append(note);
      }
      list.append(li);
    }
  }

  fetch('api/progress.php', { cache: 'no-store', credentials: 'same-origin' })
    .then((r) => {
      if (!r.ok) throw new Error('http ' + r.status);
      return r.json();
    })
    .then((data) => {
      if (data.status === 'ok') {
        render(Array.isArray(data.items) ? data.items : []);
        return;
      }
      show('state', MESSAGES[data.status] || MESSAGES.unavailable);
      if (data.status === 'no_token') $('admin-link').hidden = false;
    })
    .catch(() => show('error', 'לא הצלחתי לטעון את הדף.'));
})();
