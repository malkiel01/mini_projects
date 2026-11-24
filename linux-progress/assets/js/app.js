/* ההתקדמות שלי בלינוקס — טוען את data/progress.json ומציג. בלי שרת, בלי זיהוי, בלי אחסון דפדפן. */
(function () {
  'use strict';

  const STATUS = {
    done: 'בוצע',
    verified: 'אומת',
  };

  const $ = (id) => document.getElementById(id);

  function render(items) {
    const list = $('items');
    list.textContent = '';
    if (!items.length) {
      $('empty').hidden = false;
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

  fetch('data/progress.json', { cache: 'no-store' })
    .then((r) => {
      if (!r.ok) throw new Error('http ' + r.status);
      return r.json();
    })
    .then((data) => render(Array.isArray(data.items) ? data.items : []))
    .catch(() => {
      $('error').hidden = false;
    });
})();
