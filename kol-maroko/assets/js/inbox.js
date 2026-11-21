// קול מרוקו — 📬 פניות (פרוסה M5, lib/forms.php): התיבה, המונה בלשונית, ושורת 📬 במסך המפתח.
// כלל: ערך של פנייה הוא טקסט של גולש זר — מוצג רק כטקסט (h() / textContent). לעולם לא innerHTML.
'use strict';

const Inbox = (() => {
  const FILTERS = [['', 'הכול'], ['unread', 'לא נקרא'], ['unhandled', 'לא טופל'], ['test', '🧪 בדיקות']];
  const REASONS = { rate: 'קצב', size: 'גודל', invalid: 'לא תקין', closed: 'סגור (תקרה)', spam: 'ספאם', unknown: 'שדה לא מוכר' };
  let F = { filter: '', form: '', q: '' };
  let unread = 0;

  // ── המונה בלשונית ───────────────────────────────────────
  function paint() {
    const a = document.querySelector('#tabs a[href$="#/inbox"]');
    if (!a) return;
    const t = '📬 פניות' + (unread ? ` (${unread})` : '');
    if (a.textContent !== t) a.textContent = t;
  }
  function setUnread(n) { if (typeof n === 'number') { unread = n; paint(); } }
  async function poll() {
    if (!state.user?.role || document.hidden) return;
    try { setUnread((await api('inbox', undefined, '&count=1')).unread); } catch { /* המונה ינסה שוב */ }
  }
  document.addEventListener('DOMContentLoaded', () => {
    const nav = document.getElementById('tabs');
    if (nav) new MutationObserver(paint).observe(nav, { childList: true, subtree: true });
    setTimeout(poll, 1500);
    setInterval(poll, 60000);
  });

  const q = () => `&filter=${encodeURIComponent(F.filter)}&form=${encodeURIComponent(F.form)}&q=${encodeURIComponent(F.q)}`;
  const fieldsText = e => Object.entries(e.fields).map(([k, v]) => `${k}: ${v}`).join(' · ');

  // ── התיבה ───────────────────────────────────────────────
  async function view(host) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let d;
    try { d = await api('inbox', undefined, q()); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    setUnread(d.unread);
    const search = h('input', { type: 'search', placeholder: 'חיפוש בפניות', 'aria-label': 'חיפוש בפניות', value: F.q });
    let t = 0;
    search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { F.q = search.value; draw(); }, 300); });
    const formSel = h('select', { 'aria-label': 'טופס', onchange: e => { F.form = e.target.value; draw(); } },
      h('option', { value: '' }, 'כל הטפסים'), d.forms.map(f => h('option', { value: f.form, selected: f.form === F.form }, `${f.form} (${f.n})`)));
    const tabs = h('div', { class: 'seg-tabs', role: 'tablist' }, FILTERS.map(([k, l]) =>
      h('button', { type: 'button', role: 'tab', 'aria-selected': String(F.filter === k), onclick: () => { F.filter = k; view(host); } },
        l, ' ', h('small', {}, k === 'unread' ? (d.unread || '') : k === 'test' ? (d.tests || '') : ''))));
    const list = h('div', { class: 'ib-list' });
    host.replaceChildren(
      h('p', { class: 'hint' }, 'פניות מהטפסים שבעמוד (data-kol-form). כל פנייה — מגולש, כטקסט כמו שנשלח. 🧪 — מהתצוגה החיה, נמחקות לבד אחרי 7 ימים.'),
      ...d.external.map(x => h('p', { class: 'warnbox ib-ext' }, `הטופס "${x}" שולח לכתובת חיצונית — פניות ממנו אינן מגיעות לכאן.`)),
      tabs,
      h('div', { class: 'toolbar' }, search, formSel),
      h('div', { class: 'row-btns wrap' },
        h('a', { class: 'btn ghost sm', href: 'api.php?action=inbox_csv' + q(), download: '' }, '⬇️ לאקסל (הסינון הנוכחי)'),
        h('button', { type: 'button', class: 'btn ghost sm', onclick: () => settings(d, host) }, '⚙️ הגדרות התיבה')),
      list);
    paintList(list, d, 0, host);
    async function draw() {
      try { const n = await api('inbox', undefined, q()); paintList(list, n, 0, host); setUnread(n.unread); } catch (e) { toast(e.message, true); }
    }
  }

  function paintList(list, d, offset, host, keep = []) {
    const rows = [...keep, ...d.entries];
    list.replaceChildren(...[
      rows.length ? h('ul', { class: 'list ib-entries' }, rows.map(e => entry(e, d, host))) : h('p', { class: 'empty' }, 'אין פניות.'),
      rows.length < d.total ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: async () => {
        try { const n = await api('inbox', undefined, q() + `&offset=${offset + 50}`); paintList(list, n, offset + 50, host, rows); } catch (er) { toast(er.message, true); }
      } }, `עוד (${d.total - rows.length})`) : null,
    ].filter(Boolean));
  }

  function entry(e, d, host) {
    const det = h('details', { class: 'ib-entry' + (e.read_at ? '' : ' unread'), 'data-id': String(e.id) });
    const preview = fieldsText(e);
    det.append(h('summary', {},
      h('span', { class: 'ib-dot', 'aria-label': e.read_at ? 'נקרא' : 'לא נקרא' }, e.read_at ? '' : '●'),
      h('span', { class: 'ib-tx' },
        h('b', { class: 'one' }, (e.word ? `📚 ${e.word.word || '#' + e.word.id} · ` : '') + (preview.length > 120 ? preview.slice(0, 120) + '…' : preview)),
        h('small', {}, `${when(e.at)} · ${e.form}${e.test ? ' · 🧪' : ''}${e.handled_at ? ' · ✅ טופל' : ''}`))));
    const body = h('div', { class: 'ib-body' });
    det.append(body);
    det.addEventListener('toggle', async () => {
      if (!det.open) return;
      fill();
      if (!e.read_at) {
        try { const r = await api('inbox_mark', { id: e.id, read: true }); e.read_at = new Date().toISOString(); e.read_by = state.user.name; det.classList.remove('unread'); det.querySelector('.ib-dot').textContent = ''; setUnread(r.unread); fill(); }
        catch (er) { toast(er.message, true); }
      }
    });
    function fill() {
      body.replaceChildren(
        h('dl', { class: 'ib-fields' }, Object.entries(e.fields).map(([k, v]) => h('div', {}, h('dt', { dir: 'auto' }, k), h('dd', { dir: 'auto' }, v)))),
        h('p', { class: 'hint' }, `עמוד: ${e.page || '—'}`, e.read_at ? ` · נקרא: ${e.read_by || ''} · ${when(e.read_at)}` : '', e.handled_at ? ` · טופל: ${e.handled_by || ''} · ${when(e.handled_at)}` : ''),
        h('div', { class: 'row-btns wrap' },
          e.word ? h('button', { type: 'button', class: 'btn sm', onclick: () => Words.open(e.word.id) }, '✏️ ערוך את המילה') : null,
          h('button', { type: 'button', class: 'btn sm' + (e.handled_at ? ' ghost' : ' primary'), onclick: () => mark({ handled: !e.handled_at }) }, e.handled_at ? '↩️ לא טופל' : '✅ טופל'),
          h('button', { type: 'button', class: 'btn sm ghost', onclick: () => mark({ read: false }) }, 'סמן כלא נקרא'),
          d.can_delete ? h('button', { type: 'button', class: 'btn sm ghost danger', onclick: del }, '🗑️ לסל') : null));
    }
    async function mark(b) {
      try {
        const r = await api('inbox_mark', { id: e.id, ...b });
        setUnread(r.unread);
        if ('handled' in b) { e.handled_at = b.handled ? new Date().toISOString() : null; e.handled_by = b.handled ? state.user.name : null; }
        if ('read' in b) { e.read_at = null; det.classList.add('unread'); det.open = false; det.querySelector('.ib-dot').textContent = '●'; }
        fill();
        toast('נשמר');
      } catch (er) { toast(er.message, true); }
    }
    async function del() {
      if (!confirm('להעביר את הפנייה לסל? אפשר לשחזר מ-↩️ שחזור.')) return;
      try { const r = await api('inbox_delete', { id: e.id }); setUnread(r.unread); det.closest('li').remove(); toast('הועבר לסל'); } catch (er) { toast(er.message, true); }
    }
    return h('li', {}, det);
  }

  // ── הגדרות התיבה: האבחון, המייל על משוב חדש (בעל העמוד ומעלה), "מחק פניות ישנות" ──────
  // הטקסט מהשרת: תאריכים (ISO) ← when(); כתובות מייל — מבודדות (FSI/PDI), כדי שלא יתערבבו בעברית
  const nice = t => String(t).replace(/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/g, m => when(m)).replace(/[^\s()]+@[^\s(),]+/g, m => '\u2068' + m + '\u2069');
  const checkRow = (ok, name, text) => h('li', { class: ok === true ? 'ok' : ok === false ? 'bad' : 'wait' },
    h('span', { class: 'dev-light' }, ok === true ? '✅' : ok === false ? '❌' : '⏳'), h('div', {}, h('b', {}, name), h('p', { dir: 'auto' }, nice(text))));

  function settings(d, host) {
    const can = d.can_delete;
    const S = d.settings;
    const diag = h('ul', { class: 'dev-checks', id: 'ib-diag', 'aria-live': 'polite' });
    const paintDiag = rows => {
      diag.replaceChildren(...rows.map(r => checkRow(r.ok, r.title, r.text)));
      probeRows(S.endpoint).then(p => diag.prepend(...p));
    };
    const date = h('input', { type: 'date', 'aria-label': 'מחק פניות מלפני', value: new Date(Date.now() - 365 * 864e5).toISOString().slice(0, 10) });
    let mail = null;
    if (S.can_mail) {
      const to = h('textarea', { id: 'ib-mail-to', rows: 3, dir: 'ltr', 'aria-label': 'נמענים', placeholder: 'name@example.com' }, S.to.join('\n'));
      const chips = S.candidates.filter(c => !S.to.includes(c.email)).map(c => h('button', { type: 'button', class: 'btn ghost sm', onclick: () => {
        const cur = to.value.split(/[\s,;]+/).filter(Boolean);
        if (!cur.includes(c.email)) to.value = [...cur, c.email].join('\n');
      } }, `＋ ${c.name}`));
      const from = h('input', { id: 'ib-mail-from', type: 'email', dir: 'ltr', value: S.from, placeholder: S.from_default, 'aria-label': 'כתובת השולח' });
      const on = h('input', { id: 'ib-mail-on', type: 'checkbox', checked: S.mail_on });
      const testTo = h('input', { id: 'ib-mail-test-to', type: 'email', dir: 'ltr', value: S.to[0] || state.user.email || '', 'aria-label': 'לאן לשלוח את מייל הבדיקה' });
      const out = h('p', { class: 'hint', id: 'ib-mail-out', 'aria-live': 'polite' });
      const body = () => ({ to: to.value, from: from.value.trim() });
      on.addEventListener('change', async () => {
        try { const r = await api('forms_settings', { ...body(), mail_on: on.checked }); toast(on.checked ? 'המייל הודלק' : 'המייל כובה'); paintDiag(r.settings.diag); }
        catch (e) { on.checked = !on.checked; toast(e.message, true); }
      });
      mail = [
        h('h3', { class: 'sec-title' }, 'מייל על משוב חדש'),
        h('p', { class: 'hint' }, `על כל פנייה נשלח מייל עם התוכן שלה — המילה, השם, המייל להשבה וההערה. "השב" במייל עונה לגולש (אם השאיר מייל). לכל היותר ${S.hour_cap} מיילים בשעה; מעבר לזה הפניות מצטברות למייל אחד.`),
        h('label', { class: 'switch' }, on, h('span', {}, 'שלח לי מייל על משוב חדש')),
        h('label', { class: 'ib-field' }, h('span', {}, `נמענים — כתובת בכל שורה, עד ${S.max_to}`), to),
        chips.length ? h('div', { class: 'row-btns wrap' }, chips) : null,
        h('label', { class: 'ib-field' }, h('span', {}, 'כתובת השולח'), from),
        h('p', { class: 'hint' }, `ריק = ${S.from_default}. כתובת שאינה במתחם ${S.host} (למשל gmail) — שרתי הדואר זורקים בדרך כלל, גם כשהשרת "קיבל" את המייל.`),
        h('div', { class: 'row-btns wrap' },
          h('button', { type: 'button', class: 'btn primary', onclick: async () => {
            try { const r = await api('forms_settings', body()); toast('נשמר'); paintDiag(r.settings.diag); } catch (e) { toast(e.message, true); }
          } }, '💾 שמירה')),
        h('label', { class: 'ib-field' }, h('span', {}, 'מייל בדיקה אל'), testTo),
        h('div', { class: 'row-btns wrap' },
          h('button', { type: 'button', class: 'btn', onclick: async () => {
            out.textContent = 'שולח…';
            try {
              const r = await api('mail_test', { to: testTo.value.trim(), from: from.value.trim() });
              const t = r.test;
              out.textContent = t.ok ? `✅ השרת קיבל את המייל (מ-${t.from} אל ${t.to}). אם לא הגיע תוך כמה דקות — לבדוק בספאם.` : `❌ השליחה נכשלה: ${t.error || ''}`;
              paintDiag(r.settings.diag);
            } catch (e) { out.textContent = ''; toast(e.message, true); }
          } }, '✉️ שלח מייל בדיקה')),
        out];
    }
    sheet('⚙️ הגדרות התיבה',
      h('h3', { class: 'sec-title' }, 'מצב — מהכפתור בעמוד ועד המייל'),
      diag,
      mail || h('p', { class: 'hint' }, `המייל על משוב חדש: ${S.mail_on ? 'דולק · ' + S.to.length + ' נמענים' : 'כבוי'}. שינוי — בעל העמוד ומעלה.`),
      can ? [h('h3', { class: 'sec-title' }, 'מחק פניות ישנות'),
        h('p', { class: 'hint' }, 'כל הפניות מלפני התאריך עוברות לסל כאצווה אחת — ואפשר לשחזר את כולן יחד מ-↩️ שחזור.'),
        h('div', { class: 'toolbar' }, date, h('button', { type: 'button', class: 'btn ghost danger', onclick: async () => {
          try {
            const p = await api('inbox_purge', { before: date.value, preview: true });
            if (!p.count) { toast('אין פניות מלפני התאריך'); return; }
            if (!confirm(`להעביר ${p.count} פניות מלפני ${date.value} לסל?`)) return;
            const r = await api('inbox_purge', { before: date.value, confirm: p.count });
            toast(`${r.deleted} פניות הועברו לסל`); $('#sheet').close(); view(host);
          } catch (e) { toast(e.message, true); }
        } }, '🗑️ מחק ישנות'))] : null);
    paintDiag(S.diag);
  }

  // ── מסך המפתח: 📬 טפסים ─────────────────────────────────
  async function devView(el) {
    el.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let d;
    try { d = await api('dev-forms'); } catch (e) { el.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const m = d.mail;
    const from = h('input', { type: 'email', dir: 'ltr', value: m.from, placeholder: m.from_effective || '', 'aria-label': 'כתובת השולח' });
    const to = h('input', { type: 'email', dir: 'ltr', value: state.user.email || '', placeholder: 'לאן לשלוח את הבדיקה', 'aria-label': 'נמען הבדיקה' });
    const testOut = h('p', { class: 'hint', id: 'ib-mail-test' }, m.last_test ? `בדיקה אחרונה: ${m.last_test.ok ? '✅ true' : '❌ false'} · ${m.last_test.to} · ${when(m.last_test.at)}${m.last_test.error ? ' · ' + m.last_test.error : ''}` : 'עוד לא נשלח מייל בדיקה.');
    const lim = d.limits.map(l => h('label', { class: 'ib-limit' }, h('span', {}, l.label), h('input', { type: 'number', min: l.min, max: l.max, value: l.value, 'data-k': l.id, 'aria-label': l.label }),
      h('small', {}, `ברירת מחדל ${l.default} · ${l.min}–${l.max}`)));
    const health = h('ul', { class: 'dev-checks', id: 'ib-health' });
    el.replaceChildren(...[
      h('h2', { class: 'sec-title' }, '📬 טפסים ופניות'),
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'פניות היום'), h('dd', {}, String(d.today))),
        h('div', {}, h('dt', {}, '7 ימים'), h('dd', {}, String(d.week))),
        h('div', {}, h('dt', {}, 'מייל'), h('dd', {}, m.on ? `דולק · ${m.to} נמענים` : m.auto_off ? `❌ כובה אוטומטית · ${when(m.auto_off)}` : 'כבוי')),
        h('div', {}, h('dt', {}, 'מייל אחרון'), h('dd', {}, m.last_sent ? when(m.last_sent) : 'אף פעם'))),
      m.last_error ? h('p', { class: 'error' }, `שגיאת מייל אחרונה: ${m.last_error.error} · ${when(m.last_error.at)}`) : null,
      h('h3', { class: 'sec-title' }, 'דחיות (היום / 7 ימים)'),
      h('dl', { class: 'cells' }, Object.entries(d.rejects).map(([k, v]) => h('div', {}, h('dt', {}, REASONS[k] || k), h('dd', {}, `${v.today} / ${v.week}`)))),
      h('p', { class: 'hint' }, `הכתובת: `, h('code', { dir: 'ltr' }, d.endpoint), ` · טפסים רשומים באתר: ${d.registered.join(', ') || 'אין (עוד לא נשלחה גרסה עם טופס)'}`),
      h('h3', { class: 'sec-title' }, 'בדיקת תקינות'),
      health,
      h('h3', { class: 'sec-title' }, 'מייל'),
      h('p', { class: 'hint' }, 'המייל על משוב חדש — בעל העמוד מגדיר אותו ב-📬 פניות ← ⚙️ הגדרות התיבה (נמענים, מפסק, מייל בדיקה). כאן — אותו מפסק. 3 כישלונות ברצף — כבוי אוטומטית.'),
      h('div', { class: 'toolbar' }, from), h('div', { class: 'toolbar' }, to),
      h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: 'btn', onclick: async () => {
          try { const r = await api('mail_test', { from: from.value, to: to.value }); const t = r.test; testOut.textContent = `בדיקה: ${t.ok ? '✅ true — עכשיו לבדוק שהמייל הגיע' : '❌ false'}${t.error ? ' · ' + t.error : ''}`; } catch (e) { toast(e.message, true); }
        } }, '✉️ שלח מייל בדיקה'),
        h('button', { type: 'button', class: 'btn ' + (m.on ? 'ghost' : 'primary'), onclick: async () => {
          try { await api('forms_settings', { from: from.value, mail_on: !m.on }); toast(m.on ? 'כובה' : 'הודלק'); devView(el); } catch (e) { toast(e.message, true); }
        } }, m.on ? 'כבה את המייל' : 'הדלק את המייל')),
      testOut,
      h('h3', { class: 'sec-title' }, 'מגבלות'),
      h('p', { class: 'hint' }, 'כל מגבלה נאכפת בשרת (form.php). פנייה שנחסמה — הגולש רואה את אלמנט השגיאה של הטופס, והדחייה נספרת כאן.'),
      h('div', { class: 'ib-limits' }, lim),
      h('button', { type: 'button', class: 'btn primary', onclick: async () => {
        const limits = {};
        for (const l of lim) { const i = l.querySelector('input'); limits[i.dataset.k] = parseInt(i.value, 10); }
        try { await api('forms_settings', { limits }); toast('נשמר'); devView(el); } catch (e) { toast(e.message, true); }
      } }, 'שמירת המגבלות')].filter(x => x != null && x !== false));
    probe(health, d.endpoint);
  }

  /** הבדיקה מהדפדפן, בלי עוגייה — כמו שהעמוד באתר פונה: OPTIONS מחזיר את כותרות ה-CORS; POST בלי טופס רשום ← invalid. */
  async function probeRows(url) {
    const rows = [];
    try {
      const fd = new FormData(); fd.set('_form', '__health__'); fd.set('_t', '9999');
      const p = await fetch(url, { method: 'POST', body: fd, credentials: 'omit' });
      const j = await p.json();
      rows.push(checkRow(j.ok === false && j.reason === 'invalid', 'form.php עונה לעמוד (מהדפדפן, בלי עוגייה)', `${p.status} · ${JSON.stringify(j)} · Access-Control-Allow-Origin: ${p.headers.get('access-control-allow-origin') ?? '—'}`));
    } catch (e) { rows.push(checkRow(false, 'form.php עונה לעמוד (מהדפדפן, בלי עוגייה)', e.message + ' — אם זה דף כניסה: form.php אינו ב-"public" של app.json')); }
    return rows;
  }
  async function probe(ul, url) {
    const rows = [];
    try {
      const o = await fetch(url, { method: 'OPTIONS', credentials: 'omit' });
      const acao = o.headers.get('access-control-allow-origin');
      rows.push(checkRow(o.status === 204 && acao === '*', 'OPTIONS form.php', `${o.status} · Access-Control-Allow-Origin: ${acao ?? '—'}`));
    } catch (e) { rows.push(checkRow(false, 'OPTIONS form.php', e.message + ' — אם זה דף כניסה: form.php אינו ב-"public" של app.json')); }
    rows.push(...await probeRows(url));
    ul.replaceChildren(...rows);
  }

  return { view, devView, poll };
})();
