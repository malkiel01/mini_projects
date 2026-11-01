// מרכז הניהול — קורא וכותב בלוח "מטלות לקלוד" מהדפדפן, עם עוגיית הלוח של בעל המערכת. אין כאן אסימון.
(() => {
  const BOOT = JSON.parse(document.getElementById('boot').textContent);
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MARK = { open: '⏳', in_progress: '🔧', blocked: '❓', answered: '↩️', done: '✅', cancelled: '✕' };
  const ORDER = ['blocked', 'answered', 'in_progress', 'open', 'done', 'cancelled'];

  let toastT;
  const toast = (msg, err = false) => {
    const t = $('toast'); t.textContent = msg; t.className = 'toast' + (err ? ' err' : ''); t.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(() => { t.hidden = true; }, 3200);
  };

  async function api(action, body = {}) {
    const r = await fetch(`${BOOT.api}?action=${encodeURIComponent(action)}`, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    let j = null;
    try { j = await r.json(); } catch { /* לא JSON — למטה */ }
    if (!j) { const e = new Error(`הלוח לא ענה כמו שצריך (HTTP ${r.status})`); e.status = r.status; throw e; }
    if (!j.success) { const e = new Error(j.error || 'שגיאה מהלוח'); e.status = r.status; throw e; }
    return j;
  }

  /** עד n קריאות במקביל — לא להציף את הלוח. */
  async function pool(items, n, fn) {
    const out = new Array(items.length); let i = 0;
    await Promise.all(Array.from({ length: Math.min(n, items.length) }, async () => {
      while (i < items.length) { const k = i++; out[k] = await fn(items[k], k); }
    }));
    return out;
  }

  const ago = ts => {
    const m = Math.max(0, Math.round((Date.now() / 1000 - ts) / 60));
    if (m < 1) return 'עכשיו';
    if (m < 60) return `לפני ${m} דק'`;
    const h = Math.round(m / 60);
    return h < 48 ? `לפני ${h} שע'` : `לפני ${Math.round(h / 24)} ימים`;
  };

  const short = name => name.startsWith(BOOT.prefix) ? name.slice(BOOT.prefix.length) : name;

  /** מצב שאינו התוכן: טעינה, צריך להתחבר, אין עדיין פרויקטים, שגיאה. */
  function state(html) { $('state').innerHTML = html; $('state').hidden = false; $('app').hidden = true; }

  // ── הטעינה ─────────────────────────────────────────────────────
  let data = null;

  async function load() {
    state('<p class="muted">טוען מהלוח…</p>');
    try {
      await api('me');
    } catch (e) {
      if (e.status === 401 || e.status === 403) {
        state(`<p><b>צריך להתחבר ללוח "מטלות לקלוד"</b> באותו דפדפן. המרכז קורא את המטלות משם, על החשבון שלך.</p>
          <div class="row-btns"><a class="btn primary" href="${esc(BOOT.board)}">פתיחת הלוח</a>
          <button type="button" class="btn ghost" data-reload>חזרתי — רענון</button></div>`);
        return;
      }
      return fail(e);
    }
    try {
      const [{ projects }, { topics }, blocked, answered] = await Promise.all([
        api('projects'), api('topics'), api('tasks', { status: 'blocked' }), api('tasks', { status: 'answered' }),
      ]);
      const mine = projects.filter(p => p.name.startsWith(BOOT.prefix));
      if (!mine.length) {
        state(`<p><b>החבילה עוד לא בלוח.</b> המטלות עדיין ברשימות שבמסמכי הפרויקטים.</p>
          <p class="muted">ייבוא חד-פעמי יוצר בלוח פרויקט לכל אפליקציה, נושא לכל שלב, ומטלה לכל שורה.</p>
          <div class="row-btns"><a class="btn primary" href="import.php">ייבוא מהמסמכים</a></div>`);
        return;
      }
      const ids = new Set(mine.map(p => +p.id));
      const ofSuite = list => list.filter(t => ids.has(+t.project_id));
      // נושא שייך לפרויקט לפי השם: "<פרויקט> · <שלב>" — עד שללוח יהיה project_id לנושא (תוספת לבקשה).
      const byProject = new Map(mine.map(p => [+p.id, { p, topics: [] }]));
      for (const t of topics) {
        for (const p of mine) if (t.name.startsWith(short(p.name) + ' · ')) byProject.get(+p.id).topics.push(t);
      }
      data = { mine, byProject, blocked: ofSuite(blocked.tasks), answered: ofSuite(answered.tasks) };
      $('state').hidden = true; $('app').hidden = false;
      $('sub').textContent = `מהלוח "מטלות לקלוד" · ${new Date().toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' })}`;
      renderWait();
      renderPolicy();
      renderRoutes();
      $('n-proj').textContent = mine.length;
      renderProjects();
    } catch (e) { fail(e); }
  }

  function fail(e) {
    state(`<p class="error">${esc(e.message)}</p><div class="row-btns"><button type="button" class="btn ghost" data-reload>ניסיון נוסף</button></div>`);
  }

  // ── ממתין לך ───────────────────────────────────────────────────
  async function renderWait() {
    const list = [...data.blocked].sort((a, b) => a.updated_at - b.updated_at);   // הממתינה הכי הרבה זמן — ראשונה
    $('n-wait').textContent = list.length || '';
    const ul = $('asks');
    if (!list.length) { ul.innerHTML = '<li class="empty">אין כרגע שאלות בשבילך. 🎉</li>'; }
    else {
      ul.innerHTML = list.map(t => `<li class="ask" data-id="${t.id}">${askHead(t)}<p class="q muted">טוען את השאלה…</p></li>`).join('');
      await pool(list, 4, async t => {
        const li = ul.querySelector(`[data-id="${t.id}"]`);
        try { const { notes } = await api('task', { id: t.id }); li.innerHTML = askHead(t) + askBody(t, notes); }
        catch (e) { li.querySelector('.q').textContent = '⚠ ' + e.message; }
      });
    }
    renderAnswered();
  }

  function projectOf(t) { return data.byProject.get(+t.project_id)?.p; }
  function iconOf(p) { return BOOT.icons[p?.name] || '📦'; }

  function askHead(t) {
    const p = projectOf(t);
    const topic = t.topic_name ? short(t.topic_name).replace(short(p?.name || '') + ' · ', '') : '';
    return `<div class="ask-head"><span class="use-ic" aria-hidden="true">${iconOf(p)}</span><span class="use-tx">
      <b class="one">${esc(t.title)}</b><small class="one">${esc(short(p?.name || ''))}${topic ? ' · ' + esc(topic) : ''} · ${ago(t.updated_at)}</small></span></div>`;
  }

  function askBody(t, notes) {
    const qs = notes.filter(n => n.kind === 'question');
    const q = qs[qs.length - 1];
    const rest = notes.filter(n => n !== q);
    const owner = t.kind === 'question' && (t.body || '').includes('"ממתין לבעל המערכת"');
    return `<p class="q" dir="auto">${esc(q ? q.body : t.body || t.title)}</p>
      ${(t.body && q) || rest.length ? `<details class="more"><summary>פרטים${rest.length ? ` · ${rest.length} הערות` : ''}</summary>
        ${t.body && q ? `<p class="note" dir="auto">${esc(t.body)}</p>` : ''}
        ${rest.map(n => `<p class="note" dir="auto"><b>${esc(n.author)}</b> <small class="muted">${ago(n.created_at)}</small><br>${esc(n.body)}</p>`).join('')}
      </details>` : ''}
      <form class="answer" data-id="${t.id}">
        <label class="sr-only" for="a-${t.id}">התשובה שלך ל: ${esc(t.title)}</label>
        <textarea id="a-${t.id}" rows="2" dir="auto" placeholder="${owner ? 'מה עשית / מה מצאת' : 'התשובה שלך'}" required></textarea>
        <div class="row-btns">${owner ? '<button type="button" class="btn ghost" data-done>✓ בוצע</button>' : ''}<button class="btn primary">שליחה</button></div>
      </form>`;
  }

  async function answer(form, text) {
    const id = +form.dataset.id;
    const btns = form.querySelectorAll('button'); btns.forEach(b => { b.disabled = true; });
    try {
      await api('add-note', { id, body: text, kind: 'answer' });
      const t = data.blocked.find(x => +x.id === id);
      data.blocked = data.blocked.filter(x => +x.id !== id);
      if (t) data.answered.unshift({ ...t, status: 'answered', updated_at: Date.now() / 1000 });
      const li = form.closest('.ask');
      li.innerHTML = askHead(t) + '<p class="sent">↩️ נשלח · ⏳ הצ\'אט יקלוט עד ~15 דק\'</p>';
      $('n-wait').textContent = data.blocked.length || '';
      renderAnswered();
      toast('התשובה נרשמה במטלה');
    } catch (e) {
      btns.forEach(b => { b.disabled = false; });
      toast(e.message, true);
    }
  }

  function renderAnswered() {
    const list = data.answered;
    $('answered-wrap').hidden = !list.length;
    $('n-answered').textContent = list.length;
    $('answered').innerHTML = list.map(t => `<li><span class="mk" aria-hidden="true">↩️</span><span><b>${esc(short(projectOf(t)?.name || ''))}:</b> ${esc(t.title)}<br><small class="muted">${ago(t.updated_at)}</small></span></li>`).join('');
  }

  // ── פרויקטים ───────────────────────────────────────────────────
  function renderProjects() {
    const ul = $('projects');
    ul.innerHTML = data.mine.map(p => `<li><details class="proj" data-id="${p.id}">
      <summary class="use-row"><span class="use-ic" aria-hidden="true">${iconOf(p)}</span><span class="use-tx">
        <b class="one">${esc(short(p.name))}</b><span class="counts" data-counts>…</span></span><span class="use-go" aria-hidden="true">‹</span></summary>
      <div class="proj-body" data-body><p class="muted">טוען…</p></div></details></li>`).join('');
    // המונים — מכל הנושאים של הפרויקט; הפירוט נבנה מאותן קריאות.
    pool(data.mine, 2, async p => {
      const entry = data.byProject.get(+p.id);
      const el = ul.querySelector(`[data-id="${p.id}"]`);
      try {
        entry.loaded = await pool(entry.topics, 4, t => api('tasks', { topic_id: t.id }));
        const sum = {};
        entry.loaded.forEach(r => { for (const [k, v] of Object.entries(r.counts || {})) sum[k] = (sum[k] || 0) + +v; });
        el.querySelector('[data-counts]').innerHTML = ['open', 'in_progress', 'blocked', 'answered', 'done']
          .map(k => `<span title="${k}">${MARK[k]} ${sum[k] || 0}</span>`).join('');
        el.querySelector('[data-body]').innerHTML = projectBody(p, entry);
      } catch (e) {
        el.querySelector('[data-counts]').textContent = '⚠ ' + e.message;
        el.querySelector('[data-body]').innerHTML = `<p class="error">${esc(e.message)}</p>`;
      }
    });
  }

  /** קישור החוצה — רק http/https (הערך מהלוח, וייכתב ל-href). */
  const link = (url, label) => /^https?:\/\//i.test(url || '') ? ` <a class="ext" href="${esc(url)}" target="_blank" rel="noopener">${label}</a>` : '';

  function projectBody(p, entry) {
    if (!entry.topics.length) return '<p class="empty">אין לפרויקט נושאים בלוח.</p>';
    const prefix = short(p.name) + ' · ';
    return entry.topics.map((t, i) => {
      const tasks = [...(entry.loaded[i].tasks || [])].sort((a, b) => ORDER.indexOf(a.status) - ORDER.indexOf(b.status) || a.seq - b.seq);
      const openN = tasks.filter(x => !['done', 'cancelled'].includes(x.status)).length;
      return `<details class="group sub"><summary><h3>${esc(t.name.replace(prefix, ''))} <span class="count">${openN}/${tasks.length}</span></h3></summary>
        <ul class="list goals">${tasks.map(x => `<li class="${x.status === 'done' ? 'done' : ''}"><span class="mk" aria-label="${x.status}">${MARK[x.status] || '•'}</span>
          <span>${esc(x.title.replace(/^[א-ת]\d+\s*[,:—]\s*/u, ''))}${x.status === 'in_progress' && x.claimed_by ? `<br><small class="muted">🔧 ${esc(x.claimed_by)}</small>` : ''}${link(x.pr_url, 'PR')}${link(x.session_url, '🔗')}</span></li>`).join('') || '<li class="empty">ריק</li>'}</ul></details>`;
    }).join('');
  }

  // ── מדיניות ומסלול (לתצוגה עד שהלוח יתמוך) ────────────────────
  function renderPolicy() {
    const ic = { yes: '✅', warn: '⚠️', no: '❌' };
    const tip = { yes: 'אפשר "תמיד"', warn: '"תמיד" — רק בהקלדת שם הסוג', no: 'תמיד פעם אחת' };
    $('policy').innerHTML = BOOT.catalog.map(c => `<li><span class="mk" title="${tip[c.always]}" aria-label="${tip[c.always]}">${ic[c.always]}</span>
      <span class="grow"><b>${esc(c.label)}</b><br><small class="muted" title="${esc(c.settings)}"><bdi dir="ltr">${esc(c.type)}</bdi></small></span>
      <span class="chip">פעם אחת</span></li>`).join('');
  }

  function renderRoutes() {
    $('routes').innerHTML = `<li><span class="mk" aria-hidden="true">🌐</span><span class="grow"><b>ברירת מחדל</b></span><span class="chip ok">💻 Code</span></li>`
      + data.mine.map(p => `<li><span class="mk" aria-hidden="true">${iconOf(p)}</span><span class="grow">${esc(short(p.name))}</span><span class="chip">💻 Code</span></li>`).join('');
  }

  // ── אירועים ────────────────────────────────────────────────────
  document.addEventListener('submit', e => {
    const f = e.target.closest('form.answer'); if (!f) return;
    e.preventDefault();
    const text = f.querySelector('textarea').value.trim();
    if (text) answer(f, text);
  });
  document.addEventListener('click', e => {
    if (e.target.closest('[data-reload]') || e.target.closest('#reload')) { load(); return; }
    const done = e.target.closest('[data-done]');
    if (done) { const f = done.closest('form'); const extra = f.querySelector('textarea').value.trim(); answer(f, 'בוצע' + (extra ? ': ' + extra : '')); return; }
    const tab = e.target.closest('[role="tab"]');
    if (tab) selectTab(tab.id);
  });

  const TAB_KEY = 'cs-center-tab';
  function selectTab(id) {
    document.querySelectorAll('[role="tab"]').forEach(b => {
      const on = b.id === id; b.setAttribute('aria-selected', on);
      $(b.getAttribute('aria-controls')).hidden = !on;
    });
    try { localStorage.setItem(TAB_KEY, id); } catch { /* אין אחסון — לא נורא */ }
  }
  try { const t = localStorage.getItem(TAB_KEY); if (t && $(t)) selectTab(t); } catch { /* כנ"ל */ }

  load();
})();
