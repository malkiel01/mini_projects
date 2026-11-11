// קול מרוקו — תשתית המפתח בדפדפן (פרוסה F1): איסוף שגיאות, חיבור DevLoop (🐞 בכותרת), ומסך המפתח.
// נטען ראשון, כדי לתפוס גם שגיאות של הקבצים שאחריו. devloop.js הוא עותק מסונכרן — לא עורכים אותו; כאן רק מחברים.
'use strict';

const Dev = (() => {
  // ── איסוף: כל בקשה ל-api.php וכל שגיאה ────────────────────
  const trace = { requests: [], errors: [] };
  const push = (list, x) => { list.push(x); if (list.length > 20) list.shift(); };
  const ctx = { csrf: '', version: '', ready: false };
  let lastAction = '';
  const queue = [];                       // שגיאות שקרו לפני שידענו מי המשתמש
  const sent = new Map();                 // חתימה → { at, pending } — אותה שגיאה לא נשלחת יותר מפעם בדקה
  const realFetch = window.fetch.bind(window);

  window.fetch = async function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const m = /(?:^|\/)(api\.php)\?action=([\w-]+)/.exec(url);
    if (!m) return realFetch(input, init);
    const action = m[2], method = (init && init.method) || 'GET', t0 = performance.now();
    if (action !== 'dev-client-error') lastAction = action;
    try {
      const res = await realFetch(input, init);
      const id = res.headers.get('X-Request-Id') || '';
      if (action !== 'dev-client-error') {
        push(trace.requests, { action, method, status: res.status, id, ms: Math.round(performance.now() - t0), at: new Date().toISOString() });
        // 5xx — תקלה. 4xx (אין הרשאה, נעול, התנגשות) הוא מהלך רגיל, והשרת כבר רשם אותו
        if (res.status >= 500) report({ kind: 'api', message: `${method} ${action} → ${res.status}`, action, status: res.status, failed_req: id });
      }
      return res;
    } catch (e) {
      if (action !== 'dev-client-error') {
        push(trace.requests, { action, method, status: 0, id: '', ms: Math.round(performance.now() - t0), at: new Date().toISOString() });
        report({ kind: 'api', message: `${method} ${action} — אין חיבור (${e.message})`, action, status: 0 });
      }
      throw e;
    }
  };

  addEventListener('error', e => {
    if (!e.message) return;               // שגיאת טעינה של תמונה או סקריפט — בלי הודעה
    push(trace.errors, { kind: 'error', message: String(e.message).slice(0, 300), source: e.filename, line: e.lineno, at: new Date().toISOString() });
    report({ kind: 'error', message: e.message, source: e.filename, line: e.lineno, col: e.colno, stack: e.error && e.error.stack });
  });
  addEventListener('unhandledrejection', e => {
    const r = e.reason, msg = String((r && r.message) || r);
    push(trace.errors, { kind: 'promise', message: msg.slice(0, 300), at: new Date().toISOString() });
    report({ kind: 'promise', message: msg, stack: r && r.stack });
  });

  /** שגיאה לשרת, עם ההקשר: המסך, הפעולה האחרונה, גודל החלון, הגרסה (הדפדפן — מכותרת User-Agent). */
  function report(x) {
    const sig = [x.kind, String(x.message).replace(/\d+/g, '#'), x.source || '', x.line || ''].join('|');
    const prev = sent.get(sig), now = Date.now();
    if (prev && now - prev.at < 60000) { prev.pending++; return; }      // נספרת — תישלח עם הבאה
    const times = prev ? prev.pending + 1 : 1;
    sent.set(sig, { at: now, pending: 0 });
    const body = { ...x, message: String(x.message).slice(0, 1000), stack: x.stack ? String(x.stack).slice(0, 3000) : '',
      page: location.hash || '#/', last_action: lastAction, viewport: `${innerWidth}×${innerHeight}`, version: ctx.version, times };
    if (!ctx.ready) { if (queue.length < 10) queue.push(body); return; }
    send(body);
  }
  function send(body) {
    realFetch('api.php?action=dev-client-error', { method: 'POST', keepalive: true, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Kol': '1', 'X-CSRF': ctx.csrf }, body: JSON.stringify(body) }).catch(() => {});
  }

  // הקלטה שלא נטענה בספרייה (library.js קורא ל-window.KolLog) — ליומן המפתח, כאזהרה
  window.KolLog = rec => {
    const { type, at, ...rest } = rec || {};
    report({ kind: 'audio', message: `${type || 'log'}: ${JSON.stringify(rest).slice(0, 600)}`, action: 'library' });
  };

  // ── החיבור ────────────────────────────────────────────────
  const devApi = (action, { body, params } = {}) => api(action, body, params ? '&' + new URLSearchParams(params) : '');

  /** אחרי "me": מי המשתמש. 🐞 לכל חבר עם תפקיד; המטלות ומסך המפתח — למפתח בלבד (השרת אוכף). */
  function start(st) {
    Object.assign(ctx, { csrf: st.csrf, version: st.version || '', ready: true });
    queue.splice(0).forEach(b => send({ ...b, version: ctx.version }));
    if (!st.user.role) return;
    DevLoop.setup({
      api: devApi, toast, appName: 'קול מרוקו', tasksHref: '#/dev/tasks', mountBefore: '#top-end',
      isDev: () => true,                 // "מי רואה 🐞": כל חבר. מי מפתח — מוכרע בשרת (is_developer) ומגיע ב-user.developer
      headers: () => ({ 'X-Kol': '1', 'X-CSRF': ctx.csrf }),
      version: () => ctx.version, trace: () => trace, logHref: r => '#/dev/log/' + r.req,
    });
    DevLoop.mount();
  }

  // ── מסך המפתח ─────────────────────────────────────────────
  const SUBS = [['tasks', '📋 מטלות'], ['log', '📜 יומן'], ['audit', '👣 פעולות'], ['health', '🩺 תקינות'], ['state', '📊 נתונים']];
  const LV = { error: '🔴', warn: '🟠', info: '⚪' };
  const LV_NAME = { error: 'שגיאה', warn: 'אזהרה', info: 'רגיל' };
  const CAT = { api: 'בקשה', agent: 'צ\'אט', php: 'שרת', client: 'דפדפן', auth: 'כניסה', data: 'נתונים', health: 'תקינות' };
  const MORE_ACTIONS = { login: '🔑 נכנס לכלי', draft_save: '💾 שמר טיוטה', dev_token_create: '🔑 יצר אסימון ל-Claude', dev_token_revoke: '🔑 ביטל אסימון ל-Claude',
    secret_set: '🔐 עדכן סוד', secret_remove: '🔐 הסיר סוד', ai: '✨ ביקש מעוזר הבינה', ai_quota: '✨ שינה מכסת בינה' };

  function view(el, sub, arg) {
    if (!SUBS.some(s => s[0] === sub)) sub = 'tasks';
    const body = h('div', { class: 'dev-body' });
    el.replaceChildren(
      h('nav', { class: 'dev-subs', 'aria-label': 'מסך המפתח' }, SUBS.map(([id, label]) => h('a', { href: '#/dev/' + id, 'aria-current': id === sub ? 'page' : null }, label))),
      body);
    try {
      ({ tasks: () => DevLoop.show(body, arg || undefined), log: () => viewLog(body, arg), audit: () => viewAudit(body), health: () => viewHealth(body), state: () => viewState(body) })[sub]();
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  const select = (label, opts, value = '') => h('select', { 'aria-label': label }, h('option', { value: '' }, label), opts.map(([v, t]) => h('option', { value: v, selected: v === value }, t)));
  const isoDay = (v, end) => v ? new Date(v + (end ? 'T23:59:59' : 'T00:00:00')).toISOString().replace(/\.\d+Z$/, 'Z') : '';
  async function copy(text, btn) {
    try { await navigator.clipboard.writeText(text); if (btn) { const t = btn.textContent; btn.textContent = '✓ הועתק'; setTimeout(() => { btn.textContent = t; }, 1500); } else toast('הועתק'); }
    catch { sheet('להעתקה', h('textarea', { rows: 12, readonly: true, dir: 'auto', class: 'dev-copy' }, text)); }
  }

  // יומן הבקשות והשגיאות
  function logText(r) {
    const d = r.data || {};
    return [`קול מרוקו · יומן · שורה ${r.id}${r.count > 1 ? ` · חזרה ${r.count} פעמים (אחרונה ${r.last_at})` : ''}`,
      `${r.at} · ${r.level} · ${r.category}/${r.event} · ${r.method} ${r.action}${r.status != null ? ' → ' + r.status : ''}${r.ms != null ? ' · ' + r.ms + 'ms' : ''}`,
      `משתמש: ${r.username || '—'} · בקשה: ${r.req}`,
      r.message && `הודעה: ${r.message}`,
      (d.file || d.source) && `מקום: ${d.file || d.source}:${d.line ?? ''}`,
      d.page && `מסך: ${d.page} · פעולה אחרונה: ${d.last_action || '—'} · חלון: ${d.viewport || '—'} · גרסה: ${d.version || '—'}`,
      d.trace && 'מחסנית:\n  ' + d.trace.join('\n  '),
      d.stack && 'מחסנית:\n' + d.stack,
      r.ua && `דפדפן: ${r.ua}`].filter(Boolean).join('\n');
  }

  function viewLog(el, req = '') {
    const f = { level: select('רמה', [['warn', 'אזהרות ושגיאות'], ['error', 'שגיאות בלבד']]), category: select('סוג', []), user: select('משתמש', []), act: select('פעולה', []),
      from: h('input', { type: 'date' }), to: h('input', { type: 'date' }),
      q: h('input', { type: 'search', placeholder: 'חיפוש, או מזהה בקשה', 'aria-label': 'חיפוש ביומן', dir: 'auto', value: req || '' }) };
    const out = h('ol', { class: 'dev-log' }), info = h('p', { class: 'hint' });
    let filled = false;
    const load = async () => {
      const p = {};
      for (const k of ['level', 'category', 'user', 'act', 'q']) if (f[k].value.trim()) p[k] = f[k].value.trim();
      if (f.from.value) p.from = isoDay(f.from.value);
      if (f.to.value) p.to = isoDay(f.to.value, true);
      try {
        const r = await devApi('dev-log', { params: p });
        if (!filled) {                       // תפריטי הסינון — ממה שבאמת ביומן
          filled = true;
          r.facets.category.forEach(c => f.category.append(h('option', { value: c }, CAT[c] || c)));
          r.facets.user.forEach(u => f.user.append(h('option', { value: u.sub }, u.name || u.sub)));
          r.facets.action.forEach(a => f.act.append(h('option', { value: a }, a)));
        }
        info.textContent = `${r.rows.length}${r.more ? '+' : ''} שורות מתוך ${r.total} · נשמרות עד ${r.keep.rows.toLocaleString('he-IL')} שורות או ${r.keep.days} יום`;
        out.replaceChildren(...(r.rows.length ? r.rows.map(logRow) : [h('li', { class: 'empty' }, 'אין שורות שמתאימות לסינון.')]));
        DevLoop.decorateLog(out);
      } catch (e) { out.replaceChildren(h('li', { class: 'error' }, e.message)); }
    };
    Object.values(f).forEach(x => x.addEventListener('change', load));
    el.replaceChildren(h('div', { class: 'dev-filters' }, f.q, f.level, f.category, f.user, f.act,
      h('label', { class: 'dev-date' }, h('span', {}, 'מ-'), f.from), h('label', { class: 'dev-date' }, h('span', {}, 'עד'), f.to)), info, out);
    load();
  }

  function logRow(r) {
    const d = r.data || {};
    const where = d.file ? `${d.file}:${d.line ?? ''}` : d.source ? `${String(d.source).split('/').pop()}:${d.line ?? ''}` : '';
    const meta = [r.method && r.action ? `${r.method} ${r.action}` : r.action, r.status != null ? '→ ' + r.status : '', r.ms != null ? r.ms + 'ms' : '', where].filter(Boolean).join(' · ');
    const more = h('div', { class: 'dev-more', hidden: true },
      h('pre', { dir: 'auto' }, logText(r)),
      h('div', { class: 'row-btns' },
        h('button', { type: 'button', class: 'btn ghost sm', onclick: e => { e.stopPropagation(); copy(logText(r), e.currentTarget); } }, '📋 העתקה לצ\'אט'),
        h('a', { class: 'btn ghost sm', href: '#/dev/log/' + r.req, onclick: e => e.stopPropagation() }, 'כל הבקשה')));
    return h('li', { class: 'dev-lr ' + r.level, 'data-log-id': r.id, 'data-log-req': r.req, 'data-log-level': r.level, tabindex: 0,
      onclick: () => { more.hidden = !more.hidden; }, onkeydown: e => { if (e.key === 'Enter' && e.target === e.currentTarget) more.hidden = !more.hidden; } },
      h('div', { class: 'l1' }, h('span', { 'aria-label': LV_NAME[r.level] }, LV[r.level] || '•'), h('b', { dir: 'ltr' }, r.event),
        r.count > 1 && h('span', { class: 'chip warn' }, '×' + r.count), h('span', { class: 'dl-slot' }),
        h('span', { class: 't' }, when(r.at))),
      r.message && h('p', { dir: 'auto' }, r.message),
      h('small', {}, h('span', { dir: 'ltr' }, meta), r.username ? ' · ' + r.username : '', ' · ', h('code', { dir: 'ltr' }, r.req)),
      more);
  }

  // יומן הפעולות
  function viewAudit(el) {
    const f = { user: select('מי', []), act: select('מה', []), q: h('input', { type: 'search', placeholder: 'חיפוש', 'aria-label': 'חיפוש ביומן הפעולות', dir: 'auto' }) };
    const out = h('ul', { class: 'list' });
    const name = a => (ACTIONS[a] || MORE_ACTIONS[a] || '• ' + a);
    let filled = false;
    const load = async () => {
      const p = {};
      for (const k of ['user', 'act', 'q']) if (f[k].value.trim()) p[k] = f[k].value.trim();
      try {
        const r = await devApi('dev-audit', { params: p });
        if (!filled) {
          filled = true;
          r.facets.user.forEach(u => f.user.append(h('option', { value: u.sub }, u.name || u.sub)));
          r.facets.action.forEach(a => f.act.append(h('option', { value: a }, name(a))));
        }
        const rows = []; let last = '';
        for (const a of r.rows) {
          const d = day(a.at);
          if (d !== last) { rows.push(h('li', { class: 'log-day' }, d)); last = d; }
          const [ic, ...txt] = name(a.action).split(' ');
          rows.push(h('li', { class: 'card' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
            h('div', { class: 'body' },
              h('div', { class: 'l1' }, h('span', {}, `${a.name} ${txt.join(' ')}`), h('span', { class: 't' }, new Date(a.at).toLocaleTimeString('he-IL', { timeStyle: 'short', timeZone: 'Asia/Jerusalem' }))),
              h('div', { class: 'l2', dir: 'auto' }, a.detail))));
        }
        out.replaceChildren(...(rows.length ? rows : [h('li', { class: 'empty' }, 'אין פעולות שמתאימות לסינון.')]));
      } catch (e) { out.replaceChildren(h('li', { class: 'error' }, e.message)); }
    };
    Object.values(f).forEach(x => x.addEventListener('change', load));
    el.replaceChildren(h('p', { class: 'hint' }, 'מי עשה מה ומתי: כניסה, שמירה, פרסום, שחזור, מחיקה, חברים והגדרות. 300 האחרונות.'),
      h('div', { class: 'dev-filters' }, f.q, f.user, f.act), out);
    load();
  }

  // בדיקות תקינות
  let lastReport = null;
  function viewHealth(el) {
    const out = h('ul', { class: 'dev-checks' }), info = h('p', { class: 'hint' });
    const runBtn = h('button', { type: 'button', class: 'btn primary', onclick: run }, '▶ הרצת כל הבדיקות');
    const copyBtn = h('button', { type: 'button', class: 'btn ghost', disabled: true, onclick: e => copy(reportText(), e.currentTarget) }, '📋 העתקת הדוח');
    el.replaceChildren(h('div', { class: 'row-btns' }, runBtn, copyBtn), info, out);
    async function run() {
      runBtn.disabled = true;
      info.textContent = 'בודק…';
      out.replaceChildren();
      let r;
      try { r = await devApi('dev-health'); }
      catch (e) {            // גם כשהבקשה כולה נופלת — המסך נשאר, והתקלה מוצגת כבדיקה אדומה
        r = { checks: [{ id: 'request', name: 'הבקשה לשרת', ok: false, text: e.message, fix: 'לחפש את מזהה הבקשה ב-📜 יומן' }], at: new Date().toISOString(), version: ctx.version };
      }
      for (const c of r.checks) {
        if (!c.probe) continue;
        // בקשה אמיתית מהדפדפן אל קובץ בתוך data/ — מצפים ל-403
        try {
          const res = await realFetch(c.probe, { cache: 'no-store', credentials: 'same-origin' });
          if (res.status === 403) { c.text += ' · בקשה מהדפדפן אל ' + c.probe + ' נחסמה (403)'; }
          else { c.ok = false; c.text = `בקשה מהדפדפן אל ${c.probe} החזירה ${res.status} במקום 403`; c.fix = res.status === 200 ? 'התיקייה data/ פתוחה לדפדפן! לבדוק מיד ש-.htaccess פועל בשרת (AllowOverride)' : 'לבדוק את הגדרות השרת — הקובץ אמור להיחסם ב-403'; }
        } catch (e) { c.ok = false; c.text = 'הבקשה מהדפדפן נכשלה: ' + e.message; c.fix = 'לנסות שוב'; }
      }
      let state = null;
      try { state = await devApi('dev-state'); } catch { /* הדוח יוצא בלי מצב הנתונים */ }
      lastReport = { ...r, state };
      const bad = r.checks.filter(c => !c.ok).length;
      info.textContent = `${bad ? `${bad} אדומות מתוך ${r.checks.length}` : `כל ${r.checks.length} הבדיקות ירוקות`} · ${when(r.at)}`;
      out.replaceChildren(...r.checks.map(checkRow));
      runBtn.disabled = false; copyBtn.disabled = false;
    }
    run();
  }
  function checkRow(c) {
    try {
      return h('li', { class: c.ok ? 'ok' : 'bad' },
        h('span', { class: 'dev-light', role: 'img', 'aria-label': c.ok ? 'תקין' : 'תקלה' }, c.ok ? '✅' : '❌'),
        h('div', {}, h('b', {}, c.name), h('p', { dir: 'auto' }, c.text), !c.ok && c.fix && h('p', { class: 'dev-fix', dir: 'auto' }, '🔧 ' + c.fix)));
    } catch (e) { return h('li', { class: 'bad' }, '❌ ', String(c && c.name), ' — ', e.message); }
  }
  function reportText() {
    const r = lastReport;
    if (!r) return '';
    const lines = [`קול מרוקו · דוח תקינות · ${r.at}`, `גרסה ${r.version || '—'}${r.build ? ' · קומיט ' + r.build.commit.slice(0, 7) : ''} · ${location.origin}${location.pathname}`, '',
      ...r.checks.map(c => `${c.ok ? '✅' : '❌'} ${c.name} — ${c.text}${!c.ok && c.fix ? '\n   🔧 ' + c.fix : ''}`)];
    if (r.state) {
      lines.push('', 'שירותי רקע:', ...r.state.services.map(s => `${s.ok === false ? '❌' : s.ok ? '✅' : '•'} ${s.name} — אחרון: ${s.last || 'אף פעם'} · ${s.result} · הבא: ${s.next_text}${s.next ? ' ' + s.next : ''}`));
      lines.push('', 'מצב הנתונים:', ...r.state.stats.map(s => `${s.label}: ${s.value}`));
    }
    lines.push('', `דפדפן: ${navigator.userAgent} · חלון ${innerWidth}×${innerHeight}`);
    return lines.join('\n');
  }

  // שירותי רקע ומצב הנתונים
  async function viewState(el) {
    el.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    try {
      const r = await devApi('dev-state');
      el.replaceChildren(
        h('h2', { class: 'sec-title' }, 'מצב הנתונים'),
        h('dl', { class: 'cells' }, r.stats.map(s => h('div', {}, h('dt', {}, s.label), h('dd', { dir: 'auto' }, typeof s.value === 'number' ? s.value.toLocaleString('he-IL') : (s.id === 'imported' && /^\d{4}-/.test(s.value) ? when(s.value) : s.value))))),
        h('h2', { class: 'sec-title' }, 'שירותי רקע'),
        h('ul', { class: 'dev-checks' }, r.services.map(s => h('li', { class: s.ok === false ? 'bad' : 'ok' },
          h('span', { class: 'dev-light', role: 'img', 'aria-label': s.ok === false ? 'תקלה' : s.ok ? 'תקין' : 'אין ריצה' }, s.ok === false ? '❌' : s.ok ? '✅' : '➖'),
          h('div', {}, h('b', {}, s.name),
            h('p', {}, 'אחרון: ', s.last ? when(s.last) : 'אף פעם', ' · ', h('span', { dir: 'auto' }, s.result)),
            h('p', {}, 'הבא: ', s.next_text, s.next ? ' ' + when(s.next) : ''),
            h('p', { class: 'hint' }, s.how))))));
    } catch (e) { el.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  return { start, view, trace: () => trace };
})();
