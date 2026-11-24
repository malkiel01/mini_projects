/* ללימוד סייבר התקפי — מושך מ-api/progress.php (שקורא מהריפו בצד השרת). בלי סודות בדפדפן. */
(function () {
  'use strict';

  const STATUS_LABEL = { none: 'לא התחלתי', done: 'נלמד', verified: 'אומת' };
  const MESSAGES = {
    no_token: 'עדיין לא הוגדר סוד לקריאה. הגדר אותו במסך ההגדרות.',
    no_file: 'עדיין אין קובץ התקדמות בריפו. אחרי שייווצר, הנתונים יופיעו כאן.',
    unauthorized: 'הסוד לא מתאים לריפו או שאין לו הרשאת קריאה.',
    invalid: 'קובץ ההתקדמות בריפו בפורמט שגוי, ולכן לא מוצג.',
    unavailable: 'לא הצלחתי לטעון כרגע. נסה שוב מאוחר יותר.',
  };
  const REFRESH_MS = 5 * 60 * 1000;
  const $ = (id) => document.getElementById(id);
  let timer = null;

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = String(text);
    return n;
  }

  function ring(percent) {
    const r = 46, c = 2 * Math.PI * r;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 120 120');
    svg.setAttribute('class', 'ring');
    svg.setAttribute('aria-hidden', 'true');
    const bg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    bg.setAttribute('cx', '60'); bg.setAttribute('cy', '60'); bg.setAttribute('r', r); bg.setAttribute('class', 'ring-bg');
    const fg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    fg.setAttribute('cx', '60'); fg.setAttribute('cy', '60'); fg.setAttribute('r', r); fg.setAttribute('class', 'ring-fg');
    fg.setAttribute('stroke-dasharray', String(c));
    fg.setAttribute('stroke-dashoffset', String(c * (1 - Math.min(100, percent) / 100)));
    svg.append(bg, fg);
    return svg;
  }

  function explainer() {
    const box = el('div', 'explain');
    box.append(el('h2', 'sec', 'איך לקרוא את הדף'));
    const list = el('ul', 'legend');
    const items = [
      ['legend-none', 'לא התחלתי', 'עוד לא תרגלת את הנושא.'],
      ['legend-done', 'נלמד', 'תרגלת את הנושא. זה עדיין לא אושר.'],
      ['legend-verified', 'אומת', 'אושרה שליטה בנושא.'],
    ];
    for (const [cls, name, desc] of items) {
      const li = el('li', cls);
      li.append(el('strong', '', name), document.createTextNode(' — ' + desc));
      list.append(li);
    }
    box.append(list);
    return box;
  }

  function lessonsBlock(lessons) {
    const ul = el('ul', 'lessons');
    for (const l of lessons) {
      const li = el('li', 'lesson');
      li.append(el('span', 'kind', l.kind), el('strong', '', l.title));
      if (l.objectives && l.objectives.length) {
        const ob = el('ul', 'objectives');
        for (const o of l.objectives) ob.append(el('li', '', o));
        li.append(ob);
      }
      ul.append(li);
    }
    return ul;
  }

  function renderSummary(sum) {
    const box = $('syllabus');
    box.textContent = '';
    if (!sum) { box.hidden = true; return; }
    box.hidden = false;
    box.append(explainer());

    const o = sum.overall;
    const top = el('div', 'overall');
    const big = el('div', 'overall-ring');
    big.append(ring(o.percent), el('div', 'ring-label', o.percent + '%'));
    const text = el('div', 'overall-text');
    text.append(
      el('p', 'big', `נלמדו ${o.practiced} מתוך ${o.total} מודולים`),
      el('p', 'muted', `אומתו ${o.verified} מודולים`),
    );
    if (sum.pace) text.append(el('p', 'muted', `קצב: ${sum.pace.modulesPerWeek} מודולים בשבוע (מאז ${sum.pace.since})`));
    top.append(big, text);
    box.append(top);

    box.append(el('h2', 'sec', 'מסלולים'));
    for (const t of sum.tracks) {
      const card = el('details', 'track');
      const head = el('summary', 'track-head');
      head.append(el('span', 'track-title', t.title), el('span', 'track-pct', `${t.practiced}/${t.total} · ${t.percent}%`));
      card.append(head);
      const bar = el('div', 'bar');
      const fill = el('div', 'fill');
      fill.style.width = Math.min(100, t.percent) + '%';
      bar.append(fill);
      card.append(bar);

      const mods = el('ul', 'modules');
      for (const m of t.modules) {
        const li = el('li', 'module status-' + m.status);
        const row = el('div', 'module-head');
        row.append(el('span', 'module-title', `${m.id} ${m.title}`), el('span', 'chip', STATUS_LABEL[m.status]));
        li.append(row);
        if (m.lessons.length) li.append(lessonsBlock(m.lessons));
        mods.append(li);
      }
      card.append(mods);
      box.append(card);
    }
    if (sum.unmapped) box.append(el('p', 'muted small', `${sum.unmapped} תרגולים לא מקושרים למודול בתוכנית.`));
  }

  function renderItems(items) {
    const list = $('items');
    list.textContent = '';
    if (!items.length) {
      $('empty').textContent = 'עדיין אין תרגולים מתועדים.';
      $('empty').hidden = false;
      $('summary').textContent = '';
      return;
    }
    $('empty').hidden = true;
    const sorted = [...items].sort((a, b) => String(b.date).localeCompare(String(a.date)));
    const verified = items.filter((i) => i.status === 'verified').length;
    $('summary').textContent = `התרגולים שביצעתי: ${items.length} · אומתו ${verified}`;
    for (const it of sorted) {
      const li = el('li', 'item status-' + (it.status === 'verified' ? 'verified' : 'done'));
      const top = el('div', 'top');
      top.append(el('strong', '', it.topic), el('span', 'chip', it.status === 'verified' ? 'אומת' : 'נלמד'));
      li.append(top, el('div', 'date', it.date));
      if (it.note) li.append(el('p', 'note', it.note));
      list.append(li);
    }
  }

  function load() {
    fetch('api/progress.php', { cache: 'no-store', credentials: 'same-origin' })
      .then((r) => {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then((data) => {
        ['state', 'error'].forEach((id) => { $(id).hidden = true; });
        $('admin-link').hidden = true;
        if (data.status === 'ok') {
          renderItems(Array.isArray(data.items) ? data.items : []);
          renderSummary(data.summary);
          return;
        }
        show('state', MESSAGES[data.status] || MESSAGES.unavailable);
        if (data.status === 'no_token') $('admin-link').hidden = false;
      })
      .catch(() => show('error', 'לא הצלחתי לטעון את הדף.'));
  }

  function show(id, text) {
    const n = $(id);
    n.textContent = text;
    n.hidden = false;
  }

  function start() {
    load();
    clearInterval(timer);
    timer = setInterval(load, REFRESH_MS);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) start(); });
  start();
})();
