/* החיבור: טוען את תמונת המצב פעם אחת, ומציג את המסך לפי ה-hash. */
'use strict';
(function () {
  const $ = (id) => document.getElementById(id);
  const { esc, fmtDate } = CA.ui;
  let state = null; // { status, body }

  const SOURCE = { local: 'דוגמה מקומית', github: 'מאגר האקדמיה' };

  function chrome(body) {
    const sample = body && body.isSample !== false; // לא ידוע = מתייחסים כדוגמה
    document.body.classList.toggle('sample', sample);
    $('sample-bar').hidden = !sample;
    const c = body && body.contract;
    $('foot').textContent = body ? [
      'מקור: ' + (SOURCE[body.source] || body.source || '—'),
      c ? 'חוזה v' + c.schemaVersion + ' (' + c.commit + ')' : '',
      body.model && body.model.updatedAt ? 'עודכן ' + fmtDate(body.model.updatedAt, true) : '',
      'קריאה בלבד',
    ].filter(Boolean).join(' · ') : '';
  }

  function errorScreen(body) {
    return `<div class="error" role="alert"><b>${esc(body.error || 'שגיאה')}</b>
      ${body.errors ? `<ul class="errors">${body.errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul>${body.errorCount > body.errors.length ? `<p>ועוד ${body.errorCount - body.errors.length}…</p>` : ''}` : ''}</div>
      <p class="hint" style="margin-top:8px">הדשבורד לא מציג נתונים שאינם תואמים לחוזה — כדי לא להציג מצב שגוי.</p>`;
  }

  function show(route) {
    const main = $('main');
    const name = CA.screens[route.name] && route.name !== 'home' ? route.name : 'home';
    document.querySelectorAll('#tabs a').forEach((a) => {
      const target = CA.router.parse(a.getAttribute('href')).name;
      const cur = name === 'track' ? 'syllabus' : name;
      if (target === cur) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    if (!state) { main.innerHTML = '<p class="empty">טוען…</p>'; return; }
    chrome(state.body);
    let view;
    if (!state.body.ok) view = { title: 'אי אפשר להציג', html: errorScreen(state.body) };
    else view = CA.screens[name](state.body.model, route.arg);
    $('title').textContent = view.title || 'אקדמיית סייבר התקפי';
    $('sub').textContent = view.title && name !== 'home' ? 'אקדמיית סייבר התקפי' : 'דשבורד · קריאה בלבד';
    document.title = (view.title ? view.title + ' · ' : '') + 'אקדמיית סייבר התקפי';
    const back = $('back');
    back.hidden = !view.back;
    if (view.back) back.setAttribute('href', view.back);
    main.innerHTML = view.html;
    const active = document.querySelector('#tabs a[aria-current="page"]');
    if (active) active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  // לשוניות בתוך מסך (seg-tabs) — כפתור עם data-href
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-href]');
    if (b) location.hash = b.getAttribute('data-href');
  });

  async function load() {
    state = null;
    show(CA.router.parse(location.hash));
    state = await CA.api.snapshot();
    show(CA.router.parse(location.hash));
  }
  $('reload').addEventListener('click', load);
  window.addEventListener('hashchange', () => { show(CA.router.parse(location.hash)); window.scrollTo(0, 0); });
  load();
})();
