/* החיבור: טוען את תמונת המצב פעם אחת, ומציג את המסך לפי ה-hash. */
'use strict';
(function () {
  const $ = (id) => document.getElementById(id);
  const { fmtDate } = CA.ui;
  let state = null; // { status, body } — null = בטעינה

  const SOURCE = { local: 'דוגמה מקומית', github: 'מאגר האקדמיה' };

  /** הפסים והשורה התחתונה. isSample מגיע רק מהשרת; לא ידוע = מתייחסים כדוגמה. */
  function chrome(body) {
    const ok = !!(body && body.ok);
    const sample = !body || body.isSample !== false;
    const real = ok && body.isSample === false && body.source === 'github';
    const realEmpty = real && !!(body.model && body.model.empty);
    document.body.classList.toggle('banner', sample || real);
    $('sample-bar').hidden = !sample;
    $('real-bar').hidden = !real;
    $('real-bar').classList.toggle('empty', realEmpty);
    if (real) {
      const i = body.sourceInfo || {};
      const commit = String(i.commit || '—').slice(0, 7);
      $('real-bar').textContent = realEmpty
        ? (body.filter === 'approved_curriculum_only'
          ? '🗂️ מאגר האקדמיה מחובר · אין עדיין סילבוס מאושר · קומיט ' + commit
          : '🗂️ מאגר האקדמיה מחובר · ה-snapshot ריק — עדיין אין נתוני לימוד · קומיט ' + commit)
        : '✅ נתוני אמת ממאגר האקדמיה · קומיט ' + commit + ' · נאסף ב-' + (i.collectedAt ? fmtDate(i.collectedAt, true) : '—');
    }
    // הפס יכול לרדת לשתי שורות בטלפון — הלשוניות הדביקות יושבות מתחתיו
    const bar = real ? $('real-bar') : $('sample-bar');
    document.documentElement.style.setProperty('--bar-h', (sample || real ? bar.offsetHeight : 0) + 'px');
    const c = body && body.contract;
    $('foot').textContent = body ? [
      'מקור: ' + (SOURCE[body.source] || body.source || '—'),
      c ? 'חוזה v' + c.schemaVersion + (c.commit ? ' (' + c.commit + ')' : '') + (c.proposal ? ' · הצעה' : '') : '',
      body.model && body.model.updatedAt ? 'עודכן ' + fmtDate(body.model.updatedAt, true) : '',
      'קריאה בלבד',
    ].filter(Boolean).join(' · ') : '';
  }

  function show(route) {
    const main = $('main');
    const model = state && state.body && state.body.ok ? state.body.model : null;
    const set = model && model.version === 2 ? CA.screensV2 : CA.screens; // חוזה v2 — מסכים משלו
    const name = set[route.name] && route.name !== 'home' ? route.name : 'home';
    document.querySelectorAll('#tabs a').forEach((a) => {
      const target = CA.router.parse(a.getAttribute('href')).name;
      if (target === (name === 'track' || name === 'module' ? 'syllabus' : name)) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    chrome(state && state.body);
    let view;
    if (!state) view = { title: 'טוען…', html: CA.states.skeleton() };
    else if (state.status !== 200 || !state.body || !state.body.ok || !state.body.model) view = CA.states.errorView(state);
    else if (state.body.model.empty && state.body.isSample === false) view = CA.realEmpty(name, state.body.filter); // אמת, ריק — לא התקדמות
    else view = set[name](state.body.model, route.arg);
    main.setAttribute('aria-busy', state ? 'false' : 'true');
    $('title').textContent = view.title || 'אקדמיית סייבר התקפי';
    $('sub').textContent = name !== 'home' || !state ? 'אקדמיית סייבר התקפי' : 'דשבורד · קריאה בלבד';
    document.title = (view.title ? view.title + ' · ' : '') + 'אקדמיית סייבר התקפי';
    const back = $('back');
    back.hidden = !view.back;
    if (view.back) back.setAttribute('href', view.back);
    main.innerHTML = view.html;
    if (view.after) view.after(main); // מסך שטוען נתונים משלו (ההתקדמות שלי)
    const active = document.querySelector('#tabs a[aria-current="page"]');
    if (active) active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  async function load() {
    state = null;
    show(CA.router.parse(location.hash));
    state = await CA.api.snapshot();
    show(CA.router.parse(location.hash));
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-retry]')) { load(); return; }
    const b = e.target.closest('[data-href]'); // לשוניות בתוך מסך (seg-tabs)
    if (b) location.hash = b.getAttribute('data-href');
  });
  $('reload').addEventListener('click', load);
  window.addEventListener('hashchange', () => { show(CA.router.parse(location.hash)); window.scrollTo(0, 0); });
  load();
})();
