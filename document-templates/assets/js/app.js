// תבניות מסמכים — המעטפת: חשבונות ומסך הפיתוח.
// נתיבים יחסיים בלבד — האפליקציה תעבור לדומיין משלה בלי שינוי.
(function () {
  'use strict';

  const app = document.getElementById('app');
  const nav = document.getElementById('nav');
  let me = null;
  let csrf = '';
  let devTimer = null;
  // עורך עם שינויים לא שמורים מחזיר כאן פונקציה ששואלת לפני יציאה.
  let leaveGuard = null;
  let lastHash = location.hash;

  // ── עזרים ──────────────────────────────────────────────────

  // הבקשות והשגיאות האחרונות — ההקשר של 🐞 דיווח (DevLoop): פעולה, תוצאה, זמן ומזהה הבקשה (כמו בלוג). בלי תוכן.
  const trace = { requests: [], errors: [] };
  const keep = (list, x, n) => { list.push(x); if (list.length > n) list.shift(); };

  async function api(action, body, query) {
    const t0 = performance.now();
    const opts = { credentials: 'same-origin', headers: {} };
    if (body !== undefined) {
      opts.method = 'POST';
      if (csrf) opts.headers['X-CSRF'] = csrf;
      if (body instanceof FormData) {
        opts.body = body;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
    }
    let res, data;
    try {
      res = await fetch('api.php?action=' + action + (query ? '&' + new URLSearchParams(query) : ''), opts);
      keep(trace.requests, { action, method: opts.method || 'GET', status: res.status, ms: Math.round(performance.now() - t0),
        id: res.headers.get('X-Request-Id') || '', at: new Date().toISOString() }, 30);
      data = await res.json();
    } catch (e) {
      throw new Error('אין תשובה מהשרת');
    }
    if (!res.ok || data.ok === false) throw new Error(data.error || 'שגיאה');
    return data;
  }

  function el(tag, attrs, ...children) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (k === 'class') n.className = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
      else if (v === true) n.setAttribute(k, '');
      else if (v !== false && v != null) n.setAttribute(k, v);
    }
    for (const c of children.flat(Infinity)) if (c != null) n.append(c);
    return n;
  }

  function show(...nodes) {
    clearInterval(devTimer);
    leaveGuard = null;
    document.body.classList.remove('wide');
    backTo = null;
    backBtn.hidden = true;
    // מעבר מסך (גם "חזרה") סוגר גיליונות וחלונות פתוחים
    for (const d of document.querySelectorAll('dialog[open]')) d.close();
    app.replaceChildren(...nodes.filter(n => n != null));
  }

  // ── חזרה בתוך האפליקציה: למסך הקודם, עד הראשי — ובראשי אין חץ ──
  const backBtn = document.getElementById('back');
  let backTo = null;
  let depth = 0;   // כמה מסכים עברנו בטעינה הזו — "חזרה" לא יוצאת מהאפליקציה
  function setBack(hash) { backTo = hash; backBtn.hidden = false; }
  backBtn.addEventListener('click', () => { if (depth > 1) history.back(); else location.hash = backTo || 'home'; });

  // --vvh: הגובה הנראה בלי המקלדת — הגיליון מתקצר, והגוף נגלל
  (() => {
    const vv = window.visualViewport;
    if (!vv) return;
    const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
    vv.addEventListener('resize', set);
    set();
  })();

  /**
   * גיליון: חלון במחשב, מסך מלא בטלפון. כותרת עם ✕, ופעולה עיקרית בכותרת (לא חובה).
   * מחזיר את ה-dialog; נמחק מהדף כשנסגר.
   */
  function sheet(title, body, opts = {}) {
    const dlg = el('dialog', { class: 'sheet', 'aria-label': title });
    const head = el('header', { class: 'sheet-head' },
      el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', title: 'סגירה', onclick: () => dlg.close() }, '✕'),
      el('h2', {}, title), opts.action || null);
    dlg.append(head, el('div', { class: 'sheet-body' }, body));
    dlg.addEventListener('close', () => { dlg.remove(); if (opts.onClose) opts.onClose(); });
    document.body.append(dlg);
    dlg.showModal();
    return dlg;
  }

  /** אריח: בחירה בין פעולות. */
  const tile = (icon, label, onclick, opts = {}) => el(opts.href ? 'a' : 'button',
    { type: opts.href ? null : 'button', href: opts.href || null, class: 'tile' + (opts.danger ? ' danger' : ''), onclick },
    el('span', { class: 'tile-ic', 'aria-hidden': 'true' }, icon), el('span', {}, label, opts.sub ? el('small', {}, opts.sub) : null));

  function notice(text, kind) {
    return el('p', { class: 'notice ' + (kind || '') }, text);
  }

  function fmtTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    const s = Math.round((Date.now() - d) / 1000);
    if (s < 60) return 'עכשיו';
    if (s < 3600) return 'לפני ' + Math.round(s / 60) + ' דק׳';
    if (s < 86400) return 'לפני ' + Math.round(s / 3600) + ' שע׳';
    return d.toLocaleDateString('he-IL') + ' ' + d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
  }

  /** טופס עם שדות, כפתור ושורת הודעה. onSubmit מקבל אובייקט ערכים. */
  function form(title, fields, submitText, onSubmit, footer) {
    const msg = el('div');
    const btn = el('button', { type: 'submit', class: 'primary' }, submitText);
    const f = el('form', { class: 'card form', novalidate: true },
      el('h1', {}, title),
      fields.map(fd => el('label', {}, el('span', {}, fd.label),
        el('input', { name: fd.name, type: fd.type || 'text', autocomplete: fd.auto || 'off',
          required: true, minlength: fd.min || null, dir: fd.dir || null }))),
      btn, msg, footer || null);
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const values = Object.fromEntries(new FormData(f));
      btn.disabled = true;
      msg.replaceChildren();
      try {
        await onSubmit(values, msg);
      } catch (err) {
        msg.replaceChildren(notice(err.message, 'bad'));
      } finally {
        btn.disabled = false;
      }
    });
    return f;
  }

  const links = (...pairs) => el('p', { class: 'links' },
    pairs.map(([text, href]) => el('a', { href }, text)));

  // ── מסכי חשבון ──────────────────────────────────────────────

  function viewLogin(message) {
    show(
      message ? notice(message.text, message.kind) : null,
      form('כניסה', [
        { name: 'email', label: 'מייל', type: 'email', auto: 'username', dir: 'ltr' },
        { name: 'password', label: 'סיסמה', type: 'password', auto: 'current-password' },
      ], 'כניסה', async (v) => {
        await api('login', v);
        await boot();
      }, links(['הרשמה', '#register'], ['שכחתי סיסמה', '#forgot']))
    );
  }

  function viewRegister() {
    show(form('הרשמה', [
      { name: 'name', label: 'שם מלא' },
      { name: 'email', label: 'מייל', type: 'email', auto: 'email', dir: 'ltr' },
      { name: 'password', label: 'סיסמה (8 תווים לפחות)', type: 'password', auto: 'new-password', min: 8 },
      { name: 'password2', label: 'סיסמה שוב', type: 'password', auto: 'new-password', min: 8 },
    ], 'הרשמה', async (v, msg) => {
      if (v.password !== v.password2) throw new Error('הסיסמאות אינן זהות');
      const r = await api('register', { name: v.name, email: v.email, password: v.password });
      show(notice(r.message, 'ok'),
        el('p', { class: 'muted' }, 'אחרי האימות הבקשה עוברת לאישור. לא הגיע מייל? בדקו בספאם.'),
        links(['חזרה לכניסה', '#login']));
    }, links(['כבר רשום? כניסה', '#login'])));
  }

  function viewForgot() {
    show(form('שכחתי סיסמה', [
      { name: 'email', label: 'מייל', type: 'email', auto: 'email', dir: 'ltr' },
    ], 'שליחת קישור', async (v) => {
      const r = await api('forgot', v);
      show(notice(r.message, 'ok'), links(['חזרה לכניסה', '#login']));
    }, links(['חזרה לכניסה', '#login'])));
  }

  function viewReset(token) {
    show(form('סיסמה חדשה', [
      { name: 'password', label: 'סיסמה חדשה (8 תווים לפחות)', type: 'password', auto: 'new-password', min: 8 },
      { name: 'password2', label: 'סיסמה שוב', type: 'password', auto: 'new-password', min: 8 },
    ], 'שמירה', async (v) => {
      if (v.password !== v.password2) throw new Error('הסיסמאות אינן זהות');
      const r = await api('reset', { token, password: v.password });
      history.replaceState(null, '', location.pathname);
      viewLogin({ text: r.message, kind: 'ok' });
    }));
  }

  async function viewVerify(token) {
    show(el('p', { class: 'muted' }, 'מאמת…'));
    history.replaceState(null, '', location.pathname);
    try {
      const r = await api('verify', { token });
      viewLogin({ text: r.message, kind: 'ok' });
    } catch (e) {
      viewLogin({ text: e.message, kind: 'bad' });
    }
  }

  // ── אחרי כניסה ─────────────────────────────────────────────

  function setTab(tab) {
    for (const a of nav.querySelectorAll('[data-tab]')) {
      a.classList.toggle('active', a.dataset.tab === tab);
      if (a.dataset.tab === tab) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    }
  }

  // רמות שיתוף (שלב 7). rank — להשוואה: מה מותר בכל רמה.
  const LEVEL = { view: 'צופה', fill: 'ממלא', edit: 'עורך', co_owner: 'מנהל-שותף', owner: 'בעלים' };
  const rank = (l) => ({ view: 1, fill: 2, edit: 3, co_owner: 4, owner: 5 }[l] || 0);

  /** חלון שיתוף — לתבנית או למסמך. */
  async function shareDialog(type, id, title, after) {
    const list = el('div', { class: 'share-list' }, el('p', { class: 'muted small' }, 'טוען…'));
    const out = el('div');
    const email = el('input', { type: 'email', placeholder: 'מייל של משתמש רשום', dir: 'ltr', required: true });
    const levelSel = (cur, max) => el('select', {}, Object.entries(LEVEL).filter(([k]) => k !== 'owner' && rank(k) <= max)
      .map(([k, t]) => el('option', { value: k, selected: k === cur }, t)));
    let myRank = 4;
    async function load() {
      try {
        const r = await api('share_list', undefined, { type, id });
        myRank = rank(r.my_level);
        add.replaceChildren(email, levelSel('view', myRank), el('button', { type: 'submit', class: 'primary small' }, 'שיתוף'));
        list.replaceChildren(
          el('div', { class: 'share-row' }, el('strong', {}, r.owner), el('span', { class: 'muted small' }, 'בעלים')),
          ...r.shares.map(s => {
            const sel = levelSel(s.level, myRank);
            sel.addEventListener('change', () => run('share_set', { type, id, user_id: s.user_id, level: sel.value }));
            return el('div', { class: 'share-row' },
              el('span', {}, s.name, el('span', { class: 'muted small', dir: 'ltr' }, ' ' + s.email)), sel,
              el('button', { class: 'link small danger-text', title: 'הסרה', onclick: () => run('share_remove', { type, id, user_id: s.user_id }) }, '✕'));
          }));
      } catch (e) {
        list.replaceChildren(notice(e.message, 'bad'));
      }
    }
    async function run(action, body) {
      out.replaceChildren();
      try { await api(action, body); await load(); if (after) after(); }
      catch (e) { out.replaceChildren(notice(e.message, 'bad')); }
    }
    const add = el('form', { class: 'share-add' });
    add.addEventListener('submit', (e) => {
      e.preventDefault();
      run('share_set', { type, id, email: email.value, level: add.querySelector('select').value }).then(() => { email.value = ''; });
    });
    const dlg = el('dialog', { class: 'sig-dialog' },
      el('h2', {}, 'שיתוף — ' + title),
      el('p', { class: 'muted small' }, type === 'template'
        ? 'צופה — רואה. ממלא — מנפיק ממנה מסמכים משלו. עורך — עורך את התבנית. מנהל-שותף — גם משתף ומעביר לארכיון.'
        : 'צופה — רואה ויומן. ממלא — ממלא שדות. עורך — גם מסיים ושולח למשתתפים. מנהל-שותף — גם משתף ומעביר לארכיון.'),
      list, add, out,
      el('div', { class: 'ops' }, el('button', { onclick: () => dlg.close() }, 'סגירה')));
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
    await load();
  }

  async function leave(type, id, title, after) {
    if (!confirm(`לעזוב את "${title}"? היא תיעלם מהרשימה שלך.`)) return;
    try { await api('share_leave', { type, id }); after ? after() : (location.hash = type === 'template' ? 'home' : 'docs'); }
    catch (e) { alert(e.message); }
  }

  const SOURCE = { pdf_locked: 'PDF נעול', image: 'תמונה', docx: 'Word', pdf_parsed: 'PDF מפורק', authored: 'נכתב כאן' };
  const SOURCE_IC = { pdf_locked: '📄', image: '🖼️', docx: '📝', pdf_parsed: '🔍', authored: '✍️' };

  async function viewHome() {
    setTab('home');
    const list = el('div', {}, el('p', { class: 'empty' }, 'טוען…'));
    const msg = el('div');
    const q = el('input', { type: 'search', placeholder: 'חיפוש תבנית', 'aria-label': 'חיפוש תבנית' });
    const showArchived = el('input', { type: 'checkbox' });
    const tagSel = el('select', { 'aria-label': 'סינון לפי תגית' }, el('option', { value: '' }, 'כל התגיות'));
    const archLabel = el('label', { class: 'switch small' }, showArchived, el('span', {}, 'כולל ארכיון'));
    const filters = el('div', { class: 'toolbar' }, tagSel, archLabel);
    const summary = el('div', { class: 'summary', hidden: true });
    let all = [];

    function render() {
      const term = q.value.trim().toLowerCase();
      const items = all.filter(t => (showArchived.checked || !t.archived) && (!tagSel.value || (t.tags || []).includes(tagSel.value))
        && (!term || (t.name + ' ' + (t.tags || []).join(' ')).toLowerCase().includes(term)));
      if (!items.length) {
        list.replaceChildren(el('p', { class: 'empty' }, all.length
          ? (term || tagSel.value ? 'אין תבניות שמתאימות לחיפוש.' : 'כל התבניות בארכיון.')
          : 'עוד אין תבניות. "+ תבנית חדשה" — מעלים PDF, Word או תמונה, או כותבים כאן.'));
        return;
      }
      list.replaceChildren(el('ul', { class: 'list' }, items.map(t => {
        const sub = [SOURCE[t.source] || t.source, t.fields + ' שדות', 'גרסה ' + t.version,
          t.owner_name ? `של ${t.owner_name} · ${LEVEL[t.access]}` : null].filter(Boolean).join(' · ');
        const canIssue = !t.archived && t.fields && rank(t.access) >= 2;
        return el('li', { class: 'tpl-card' + (t.archived ? ' archived' : '') },
          el('a', { class: 'use-row tpl-open', href: '#tpl=' + t.id, title: rank(t.access) >= 3 ? 'עריכת התבנית' : 'צפייה בתבנית' },
            el('span', { class: 'use-ic', 'aria-hidden': 'true' }, SOURCE_IC[t.source] || '📄'),
            el('span', { class: 'use-tx' }, el('strong', {}, t.name), el('small', {}, sub), window.DTCatalog.chips(t.tags))),
          canIssue ? el('button', { class: 'small primary', 'aria-label': 'הנפקת מסמך', title: 'הנפקת מסמך מהתבנית',
            onclick: () => issue(t) }, '+ מסמך') : null,
          el('button', { class: 'icon-btn', 'aria-label': 'פעולות', title: 'פעולות', onclick: () => actions(t) }, '⋯'));
      })));
    }

    /** פעולות על תבנית — אריחים בגיליון. */
    function actions(t) {
      const r = rank(t.access);
      let dlg;
      const go = (fn) => () => { dlg.close(); fn(); };
      dlg = sheet(t.name, [
        el('p', { class: 'hint' }, `${SOURCE[t.source] || t.source} · ${t.pages} דפים · ${t.fields} שדות · גרסה ${t.version}`),
        el('div', { class: 'tiles' },
          tile(r >= 3 ? '✏️' : '👁️', r >= 3 ? 'עריכה' : 'צפייה', go(() => { location.hash = 'tpl=' + t.id; })),
          t.used ? tile('🗂️', 'מסמכים', go(() => { location.hash = 'docs?template=' + t.id; })) : null,
          // שלב 12: הנפקה מאקסל וייצוא התשובות
          !t.archived && t.fields && r >= 2 ? tile('📥', 'הנפקה מאקסל', go(() => window.DTBulk.open(t)), { sub: 'מסמך לכל שורה' }) : null,
          t.used ? tile('📤', 'ייצוא לאקסל', go(async () => {
            try { await window.DTBulk.exportFile({ template_id: t.id }); }
            catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
          }), { sub: 'כל התשובות' }) : null,
          r >= 3 ? tile('📦', 'ייצוא תבנית', go(() => window.DTPort.exportDialog(t)), { sub: 'עם כל ההגדרות — PDF, Word או JSON' }) : null,
          r >= 4 ? tile('🔗', 'שיתוף', go(() => shareDialog('template', t.id, t.name, load))) : null,
          r >= 4 ? tile(t.archived ? '♻️' : '🗄️', t.archived ? 'שחזור' : 'ארכיון', go(() => archive(t))) : null,
          t.used || t.access !== 'owner' ? null : tile('🗑️', 'מחיקה', go(() => remove(t)), { danger: true }),
          t.access === 'owner' ? null : tile('🚪', 'עזיבה', go(() => leave('template', t.id, t.name, load)), { danger: true })),
      ]);
    }

    async function load() {
      try {
        const r = await api('tpl_list');
        all = r.templates;
        const tags = [...new Set(all.flatMap(t => t.tags || []))].sort();
        const cur = tagSel.value;
        tagSel.replaceChildren(el('option', { value: '' }, 'כל התגיות'), ...tags.map(t => el('option', { value: t, selected: t === cur }, t)));
        tagSel.hidden = !tags.length;
        archLabel.hidden = !all.some(t => t.archived);
        filters.hidden = tagSel.hidden && archLabel.hidden;
        if (r.usage) {
          const u = r.usage;
          const pct = Math.min(100, Math.round(u.used_mb / u.storage_mb * 100));
          const active = all.filter(t => !t.archived).length;
          summary.hidden = false;
          summary.replaceChildren(
            el('div', { class: 'line' }, el('b', {}, String(active)), el('span', { class: 'muted' }, 'תבניות'),
              el('span', { class: 'muted' }, '·'), el('span', { class: 'muted' }, 'נפח'),
              el('b', { class: 'usage' }, (u.used_mb < 0.1 ? '<0.1' : u.used_mb) + 'MB'),
              el('span', { class: 'muted' }, `מתוך ${u.storage_mb}MB`), el('span', { class: 'pct' }, pct + '%')),
            el('span', { class: 'track', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': pct },
              el('span', { style: `width:${Math.max(pct, 1)}%`, class: pct > 90 ? 'full' : '' })));
        }
        render();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    async function issue(t) {
      try { await window.DTDocs.issue(t.id); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    }

    async function archive(t) {
      try { await api('tpl_archive', { id: t.id, restore: t.archived }); await load(); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    }

    async function remove(t) {
      if (!confirm(`למחוק לצמיתות את "${t.name}"? לא ניתן לשחזר.`)) return;
      try { await api('tpl_delete', { id: t.id }); await load(); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    }

    /** תבנית חדשה: שם, ואז מקובץ (PDF / Word / תמונה) — או כותבים כאן. */
    function newTemplate() {
      const out = el('div');
      const name = el('input', { name: 'name', required: true, maxlength: 120, placeholder: 'למשל: הסכם שכירות' });
      const file = el('input', { name: 'file', type: 'file', class: 'visually-hidden',
        accept: 'application/pdf,.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg,image/webp' });
      const chosen = el('p', { class: 'hint', hidden: true });
      const pkg = el('input', { type: 'file', class: 'visually-hidden', accept: '.json,application/json,application/pdf,.pdf,.docx' });
      // ייבוא: JSON → ההגדרות; PDF → ההגדרות שמצורפות בתוכו; Word → השרת מוצא אותן בתוכו
      const runImport = async (f, viaPdf) => {
        out.replaceChildren(notice('מייבא…'));
        try {
          let r;
          if (viaPdf) r = await window.DTPort.importPdf(f, viaPdf, name.value.trim());
          else if (/\.docx$/i.test(f.name)) {
            const fd = new FormData(); fd.append('file', f); fd.append('name', name.value.trim() || f.name.replace(/\.[^.]+$/, ''));
            r = await api('tpl_create', fd);
            if (!r.imported) { dlg.close(); location.hash = 'tpl=' + r.id; window.DT.toast('בקובץ לא היו הגדרות שיוצאו — נוצרה תבנית Word רגילה.'); return; }
          } else {
            const fd = new FormData(); fd.append('file', f);
            if (name.value.trim()) fd.append('name', name.value.trim());
            r = await api('tpl_import', fd);
          }
          dlg.close();
          location.hash = 'tpl=' + r.id;
          const n = (k, one, many) => k === 1 ? one : `${k} ${many}`;
          window.DT.toast('התבנית יובאה' + (r.lists ? ' · ' + n(r.lists, 'רשימה אחת', 'רשימות') : '') + (r.fonts ? ' · ' + n(r.fonts, 'גופן אחד', 'גופנים') : '')
            + ((r.notes || []).length ? '. שימו לב: ' + r.notes.join(' ') : ''));
        } catch (err) { out.replaceChildren(notice(err.message, 'bad')); }
      };
      pkg.addEventListener('change', async () => {
        const f = pkg.files[0];
        pkg.value = '';
        if (!f) return;
        if (/\.pdf$/i.test(f.name) || f.type === 'application/pdf') {
          const p = await window.DTPort.readPdf(f);
          if (!p) { out.replaceChildren(notice('ב-PDF הזה אין הגדרות שיוצאו מהמערכת. כדי ליצור ממנו תבנית רגילה — "מקובץ".', 'bad')); return; }
          return runImport(f, p);
        }
        return runImport(f, null);
      });
      // שלב 10: ב-PDF — לפרק (המערכת קוראת שדות וטקסט) או להשתמש כקובץ נעול.
      const pdfMode = el('div', { class: 'pdf-mode f', hidden: true },
        el('span', {}, 'PDF — מה לעשות איתו?'),
        el('fieldset', { class: 'seg', 'aria-label': 'PDF — לפרק או נעול' },
          el('label', {}, el('input', { type: 'radio', name: 'mode', value: 'parse', checked: true }), el('span', {}, '🔍 לפרק')),
          el('label', {}, el('input', { type: 'radio', name: 'mode', value: 'locked' }), el('span', {}, '🔒 קובץ נעול'))),
        el('small', { class: 'muted' }, 'לפרק — המערכת קוראת את שדות הטופס והטקסט ומציעה מקומות מילוי. נעול — כמו תמונה; את השדות ממקמים לבד.'));
      const submit = el('button', { type: 'submit', class: 'primary', hidden: true }, 'העלאה ופתיחה בעורך');
      const form = el('form', { class: 'upload-form', novalidate: true },
        el('label', { class: 'f' }, el('span', {}, 'שם התבנית'), name),
        el('div', { class: 'tiles' },
          el('label', { class: 'tile' }, file, el('span', { class: 'tile-ic', 'aria-hidden': 'true' }, '📄'),
            el('span', {}, 'מקובץ', el('small', {}, 'PDF, Word (שדות {{כך}}) או תמונה'))),
          el('button', { type: 'button', class: 'tile authored-btn', onclick: async () => {
            if (!name.value.trim()) { name.focus(); out.replaceChildren(notice('קודם שם לתבנית', 'bad')); return; }
            try { const id = (await api('tpl_create_authored', { name: name.value })).id; dlg.close(); location.hash = 'tpl=' + id; }
            catch (err) { out.replaceChildren(notice(err.message, 'bad')); }
          } }, el('span', { class: 'tile-ic', 'aria-hidden': 'true' }, '✍️'), el('span', {}, 'לכתוב כאן, בלי קובץ', el('small', {}, 'כותרות, פסקאות, טבלה ושדות'))),
          // ייבוא תבנית שיוצאה (מהמערכת, ממשתמש אחר): הקובץ המקורי + המבנה + רשימות + גופנים
          el('label', { class: 'tile' }, pkg, el('span', { class: 'tile-ic', 'aria-hidden': 'true' }, '📦'),
            el('span', {}, 'ייבוא תבנית', el('small', {}, 'שיוצאה מהמערכת: PDF, Word או JSON')))),
        chosen, pdfMode, submit, out);
      let packaged = null;   // PDF שיוצא מהמערכת — עם ההגדרות בתוכו
      file.addEventListener('change', async () => {
        const f = file.files[0];
        if (!f) return;
        if (!name.value) name.value = f.name.replace(/\.[^.]+$/, '');
        chosen.hidden = false;
        chosen.textContent = '📎 ' + f.name;
        const isPdf = f.type === 'application/pdf' || /\.pdf$/i.test(f.name);
        packaged = isPdf ? await window.DTPort.readPdf(f) : null;
        if (packaged) chosen.textContent = `📦 ${f.name} — יוצא מהמערכת עם ההגדרות (${packaged.schema.fields.length} שדות). התבנית תיבנה עם כולן.`;
        pdfMode.hidden = !isPdf || !!packaged;
        submit.hidden = false;
      });
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!name.value.trim()) { name.focus(); out.replaceChildren(notice('קודם שם לתבנית', 'bad')); return; }
        submit.disabled = true;
        submit.textContent = 'מעלה…';
        try {
          if (packaged) { await runImport(file.files[0], packaged); return; }
          const r = await api('tpl_create', new FormData(form));
          dlg.close();
          location.hash = 'tpl=' + r.id;
          if (r.imported) window.DT.toast('הקובץ יוצא מהמערכת — התבנית נבנתה עם כל ההגדרות.');
        } catch (err) {
          out.replaceChildren(notice(err.message, 'bad'));
          submit.disabled = false;
          submit.textContent = 'העלאה ופתיחה בעורך';
        }
      });
      const dlg = sheet('תבנית חדשה', [el('p', { class: 'hint' }, 'מעלים קובץ קיים — או כותבים את המסמך כאן. התבניות שלך פרטיות ומוצפנות.'), form]);
      name.focus();
    }

    q.addEventListener('input', render);
    showArchived.addEventListener('change', render);

    show(el('section', { class: 'screen' },
      summary,
      el('div', { class: 'toolbar' }, q,
        el('button', { class: 'primary', onclick: newTemplate }, '+ תבנית חדשה')),
      filters, msg, list,
      el('p', { class: 'hint' }, '🔒 רק אתה רואה את התבניות שלך — גם לא המפתח.')));
    await load();
  }

  const STATUS = {
    unverified: ['לא אומת', 'muted'],
    pending: ['ממתין לאישור', 'warn'],
    active: ['פעיל', 'ok'],
    blocked: ['חסום', 'bad'],
  };

  const OPS = {
    unverified: [['reject', 'מחיקה']],
    pending: [['approve', 'אישור'], ['reject', 'דחייה'], ['block', 'חסימה']],
    active: [['logout', 'ניתוק'], ['reset', 'שליחת איפוס'], ['block', 'חסימה']],
    blocked: [['unblock', 'שחרור'], ['logout', 'ניתוק']],
  };

  const CONFIRM = {
    reject: 'לדחות את הבקשה? החשבון יימחק (מחיקה רכה).',
    block: 'לחסום? המשתמש ינותק מיד מכל המכשירים.',
    logout: 'לנתק את המשתמש מכל המכשירים?',
    reset: 'לשלוח למשתמש קישור לאיפוס סיסמה?',
  };

  async function viewDev(box) {
    const msg = el('div');
    const body = el('ul', { class: 'list' });
    const summary = el('div', { class: 'summary' });

    async function load() {
      try {
        const r = await api('dev_users');
        const online = r.users.filter(u => u.online).length;
        const pending = r.users.filter(u => u.status === 'pending').length;
        summary.replaceChildren(el('div', { class: 'line' },
          el('b', {}, String(r.users.length)), el('span', { class: 'muted' }, 'חשבונות ·'),
          el('b', {}, String(online)), el('span', { class: 'muted' }, 'מחוברים'),
          pending ? el('span', { class: 'badge warn' }, pending + ' ממתינים לאישור') : null));
        body.replaceChildren(...r.users.map(row));
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    // גוון קבוע לכל שם — לאווטאר
    const hue = (s) => [...s].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
    const initials = (s) => s.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');

    function row(u) {
      const [label, kind] = STATUS[u.status] || [u.status, ''];
      const ops = u.id === me.id ? [] : (OPS[u.status] || []);
      const pending = u.status === 'pending';
      return el('li', {},
        el('button', { type: 'button', class: 'use-row', onclick: () => userSheet(u, ops) },
          el('span', { class: 'avatar', style: `--h:${hue(u.display_name)}`, 'aria-hidden': 'true' }, initials(u.display_name)),
          el('span', { class: 'use-tx' },
            el('span', { class: 'l1' }, el('b', {}, u.display_name), el('span', { class: 't' }, (u.online ? '🟢 ' : '') + fmtTime(u.last_seen_at))),
            el('small', { class: 'wrap' }, el('bdi', {}, u.email), u.devices ? ` · ${u.devices} מכשירים` : '',
              u.quota ? [' · ', el('bdi', {}, `${u.quota.used_mb}/${u.quota.storage_mb}MB`)] : '')),
          u.is_developer ? el('span', { class: 'badge' }, 'מפתח') : el('span', { class: 'badge ' + kind }, label)),
        pending && ops.length ? el('button', { class: 'small primary approve', onclick: () => act(u, 'approve') }, 'אישור') : null);
    }

    function userSheet(u, ops) {
      const [label, kind] = STATUS[u.status] || [u.status, ''];
      const ICON = { approve: '✅', reject: '🗑️', block: '⛔', unblock: '🔓', logout: '🔌', reset: '🔑' };
      let dlg;
      const go = (fn) => () => { dlg.close(); fn(); };
      dlg = sheet(u.display_name, [
        el('dl', { class: 'cells' },
          el('div', {}, el('dt', {}, 'מצב'), el('dd', {}, el('span', { class: 'badge ' + kind }, label))),
          el('div', {}, el('dt', {}, 'נראה לאחרונה'), el('dd', {}, fmtTime(u.last_seen_at))),
          el('div', {}, el('dt', {}, 'נרשם'), el('dd', {}, fmtTime(u.created_at))),
          el('div', {}, el('dt', {}, 'מכשירים'), el('dd', {}, String(u.devices || 0)))),
        el('p', { class: 'hint', dir: 'ltr' }, u.email),
        el('div', { class: 'tiles' },
          ...ops.map(([op, text]) => tile(ICON[op] || '•', text, go(() => act(u, op)), { danger: ['reject', 'block'].includes(op) })),
          u.quota ? tile('📦', 'מכסות', go(() => quotaDialog(u)), { sub: `${u.quota.used_mb}/${u.quota.storage_mb}MB` }) : null),
      ]);
    }

    // מכסות לחשבון: נפח, גודל קובץ, ושירותים בתשלום (בינה מלאכותית, SMS) לעתיד.
    function quotaDialog(u) {
      const q = u.quota;
      const num = (name, label, val, min, max) => el('label', {}, el('span', {}, label),
        el('input', { name, type: 'number', min, max, value: val ?? '' }));
      const ai = el('input', { type: 'checkbox', name: 'ai_enabled', checked: q.ai_enabled });
      const out = el('div');
      const form = el('form', { class: 'quota-form' },
        el('h2', {}, 'מכסות — ' + u.display_name),
        el('p', { class: 'muted small' }, `בשימוש: ${q.used_mb}MB`),
        num('storage_mb', 'נפח אחסון (MB)', q.storage_mb, 1, 100000),
        num('max_file_mb', 'גודל קובץ מרבי (MB, עד 50)', q.max_file_mb, 1, 50),
        num('file_retention_days', 'שמירת קבצים (ימים; ריק = ללא הגבלה)', q.file_retention_days, 1, 36500),
        el('label', { class: 'inline' }, ai, ' שירותי בינה מלאכותית'),
        num('ai_credits', 'קרדיטים לבינה מלאכותית', q.ai_credits, 0, 10000000),
        num('sms_credits', 'קרדיטים ל-SMS', q.sms_credits, 0, 10000000),
        out,
        el('div', { class: 'ops' }, el('button', { type: 'submit', class: 'primary' }, 'שמירה'),
          el('button', { type: 'button', onclick: () => dlg.close() }, 'ביטול')));
      const dlg = el('dialog', { class: 'sig-dialog' }, form);
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const b = Object.fromEntries(new FormData(form));
        b.ai_enabled = ai.checked;
        b.user_id = u.id;
        try { await api('dev_quota', b); dlg.close(); await load(); }
        catch (err) { out.replaceChildren(notice(err.message, 'bad')); }
      });
      dlg.addEventListener('close', () => dlg.remove());
      document.body.append(dlg);
      dlg.showModal();
    }

    async function act(u, op) {
      if (CONFIRM[op] && !confirm(u.display_name + ': ' + CONFIRM[op])) return;
      msg.replaceChildren();
      try {
        const r = await api('dev_user', { user_id: u.id, op });
        if (r.message) msg.replaceChildren(notice(r.message, 'ok'));
        await load();
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    box.replaceChildren(
      summary,
      el('p', { class: 'hint' }, '🔒 המפתח רואה חשבונות ומטא-דאטה בלבד — לא כותרות, לא ערכים ולא קבצים של משתמשים.'),
      msg, body);
    await load();
    devTimer = setInterval(load, 30000);
  }

  // ── ניתוב ──────────────────────────────────────────────────

  function route() {
    if (leaveGuard && !leaveGuard()) {
      history.replaceState(null, '', lastHash || location.pathname);
      return;
    }
    lastHash = location.hash;
    depth++;
    const h = location.hash.slice(1);
    const key = h.split(/[=?]/)[0];
    const val = h.includes('=') ? h.slice(h.indexOf('=') + 1) : '';
    if (key === 'verify' && val) return viewVerify(val);
    if (key === 'reset' && val) return viewReset(val);
    if (!me) {
      if (key === 'register') return viewRegister();
      if (key === 'forgot') return viewForgot();
      return viewLogin();
    }
    if (key === 'dev' && me.is_developer) return window.DTDev.view(new URLSearchParams(h.split('?')[1] || '').get('tab'), viewDev);
    if (key === 'tpl' && val) return window.DTEditor.open(Number(val));
    if (key === 'docs') return window.DTDocs.list();
    if (key === 'contacts') return window.DTCatalog.view();
    if (key === 'connections') return window.DTConn.view();
    if (key === 'doc' && val) return window.DTDocs.open(Number(val));
    return viewHome();
  }

  async function boot() {
    try {
      const r = await api('me');
      me = r.user;
      csrf = r.csrf || '';
    } catch (e) {
      me = null;
    }
    nav.hidden = !me;
    meBtn.hidden = !me;
    paintMe();
    document.getElementById('nav-dev').hidden = !(me && me.is_developer);
    document.getElementById('who').textContent = me ? me.name : '';
    if (me && /^(login|register|forgot)?$/.test(location.hash.slice(1))) location.hash = 'home';
    devloop();
    route();
  }

  /** DevLoop (lib/devloop.php, assets/js/devloop.js): 🐞 דיווח מכל מסך, והמונה של מה שממתין למפתח. */
  function devloop() {
    document.getElementById('dev-bug')?.remove();
    if (!me || !me.is_developer || typeof DevLoop === 'undefined') return;
    DevLoop.setup({
      api: (action, o = {}) => api(action, o.body, o.params),
      toast, isDev: () => !!(me && me.is_developer), appName: 'תבניות מסמכים', tasksHref: '#dev?tab=items',
      mountBefore: '#me-btn', headers: () => ({ 'X-CSRF': csrf }), lang: () => 'he', version: () => 'stage 12',
      trace: () => trace,
      logHref: (r) => r.req ? '#dev?tab=log&req=' + encodeURIComponent(r.req) : '#dev?tab=log',   // 📜 יומן במטלה ← הלוג שלנו
    });
    DevLoop.mount();
  }

  /** הודעה קצרה בתחתית המסך. */
  function toast(message, bad) {
    const t = el('div', { class: 'toast' + (bad ? ' bad' : ''), role: 'status' }, String(message));
    document.body.append(t);
    setTimeout(() => t.remove(), 3500);
  }

  async function logout() {
    try { await api('logout', {}); } catch (e) { /* יוצאים בכל מקרה */ }
    me = null;
    csrf = '';
    location.hash = 'login';
    boot();
  }

  // ── תפריט המשתמש: מהאווטאר בפינה — הגדרות, יציאה, ולמפתח: אזור הפיתוח ──
  const meBtn = document.getElementById('me-btn');
  const hueOf = (s) => [...String(s)].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
  /** הכותרת: השם והאווטאר של המשתמש המחובר. */
  function paintMe() {
    document.getElementById('who').textContent = me ? me.name : '';
    if (me) { meBtn.textContent = initialsOf(me.name); meBtn.style.setProperty('--h', hueOf(me.name)); }
  }
  const initialsOf = (s) => String(s).trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');
  let menu = null;
  function closeMenu() {
    if (!menu) return;
    menu.remove(); menu = null;
    meBtn.setAttribute('aria-expanded', 'false');
    document.removeEventListener('pointerdown', outside, true);
  }
  // סמל יציאה מצויר — התו ⏻ חסר בגופנים של אנדרואיד ומוצג כריבוע ריק
  function logoutIcon() {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    for (const d of ['M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4', 'M10 17l-5-5 5-5', 'M5 12h11']) {
      const path = document.createElementNS(ns, 'path');
      path.setAttribute('d', d);
      svg.append(path);
    }
    return svg;
  }
  const outside = (e) => { if (menu && !menu.contains(e.target) && e.target !== meBtn) closeMenu(); };
  meBtn.addEventListener('click', () => {
    if (menu) { closeMenu(); return; }
    const item = (icon, label, on, sub) => el('button', { type: 'button', role: 'menuitem', class: 'menu-item',
      onclick: () => { closeMenu(); on(); } }, el('span', { class: 'mi-ic', 'aria-hidden': 'true' }, icon),
      el('span', { class: 'mi-tx' }, label, sub ? el('small', {}, sub) : null));
    const go = (h) => () => { location.hash = h; };
    menu = el('div', { class: 'user-menu', role: 'menu', 'aria-label': 'תפריט המשתמש' },
      el('div', { class: 'menu-head' }, el('span', { class: 'avatar', style: `--h:${hueOf(me.name)}`, 'aria-hidden': 'true' }, initialsOf(me.name)),
        el('span', { class: 'mi-tx' }, el('b', {}, me.name), el('small', { dir: 'ltr' }, me.email))),
      item('👤', 'הגדרות משתמש', userSettings, 'תצוגה, שם, סיסמה, מכשירים'),
      item('🔌', 'חיבורים ו-API', go('connections')),
      me.is_developer ? el('div', { class: 'menu-sep' }, 'מפתח המערכת') : null,
      me.is_developer ? [
        item('📋', 'מטלות', go('dev?tab=items'), 'קוד, שיח, ושאלות אליך'),
        item('🪵', 'לוג מעקב', go('dev?tab=log'), 'בקשות, שגיאות, דיבוג'),
        item('🛡️', 'אכיפה', go('dev?tab=force'), 'פעולות מעבר לכללים'),
        item('👥', 'משתמשים', go('dev?tab=users')),
        item('🩺', 'מצב המערכת', go('dev?tab=health')),
      ] : null,
      el('div', { class: 'menu-sep' }),
      item(logoutIcon(), 'יציאה', logout));
    document.body.append(menu);
    meBtn.setAttribute('aria-expanded', 'true');
    document.addEventListener('pointerdown', outside, true);
    menu.querySelector('.menu-item').focus();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menu) { closeMenu(); meBtn.focus(); } });
  window.addEventListener('hashchange', closeMenu);

  /** הגדרות המשתמש: שם, החלפת סיסמה, ניתוק מכשירים אחרים. */
  async function userSettings() {
    let info = {};
    try { info = await api('me_info'); } catch (e) { /* מציגים מה שיש */ }
    const msg = el('div');
    const name = el('input', { value: me.name, maxlength: 60, autocomplete: 'name' });
    const saveName = el('button', { type: 'button', class: 'small', onclick: async () => {
      try { const r = await api('me_update', { name: name.value }); me.name = r.user.name; paintMe(); msg.replaceChildren(notice('השם עודכן', 'ok')); }
      catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    } }, 'שמירת השם');
    const cur = el('input', { type: 'password', autocomplete: 'current-password' });
    const nw = el('input', { type: 'password', autocomplete: 'new-password', minlength: 8 });
    const nw2 = el('input', { type: 'password', autocomplete: 'new-password' });
    const savePw = el('button', { type: 'button', class: 'small', onclick: async () => {
      if (nw.value !== nw2.value) { msg.replaceChildren(notice('הסיסמאות החדשות אינן זהות', 'bad')); return; }
      try {
        const r = await api('me_password', { current: cur.value, new: nw.value });
        cur.value = nw.value = nw2.value = '';
        msg.replaceChildren(notice('הסיסמה הוחלפה' + (r.other_sessions ? ` — ${r.other_sessions} מכשירים אחרים נותקו` : ''), 'ok'));
      } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
    } }, 'החלפת סיסמה');
    // ערכת נושא — במכשיר הזה (assets/js/theme.js)
    const theme = el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'ערכת נושא' },
      [['auto', '📱 לפי המכשיר'], ['light', '☀️ בהיר'], ['dark', '🌙 כהה']].map(([v, t]) => el('label', {},
        el('input', { type: 'radio', name: 'dt-theme', value: v, checked: (window.DTTheme ? window.DTTheme.get() : 'auto') === v }), el('span', {}, t))));
    theme.addEventListener('change', (e) => window.DTTheme && window.DTTheme.set(e.target.value));
    sheet('הגדרות משתמש', el('div', { class: 'field-sheet' },
      el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'תצוגה'), theme,
        el('p', { class: 'hint' }, '"לפי המכשיר" — בהיר או כהה לפי הגדרת הטלפון או המחשב. הבחירה נשמרת במכשיר הזה.')),
      el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'החשבון'),
        el('dl', { class: 'cells' },
          el('div', {}, el('dt', {}, 'מייל'), el('dd', { dir: 'ltr' }, me.email)),
          el('div', {}, el('dt', {}, 'נרשם'), el('dd', {}, fmtTime(info.created_at))),
          el('div', {}, el('dt', {}, 'מכשירים מחוברים'), el('dd', {}, String(info.devices ?? '—'))),
          el('div', {}, el('dt', {}, 'סוג'), el('dd', {}, me.is_developer ? 'מפתח המערכת' : 'משתמש'))),
        el('label', { class: 'f' }, el('span', {}, 'השם שמוצג'), name), saveName),
      el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'סיסמה'),
        el('label', { class: 'f' }, el('span', {}, 'הסיסמה הנוכחית'), cur),
        el('div', { class: 'fields two' }, el('label', { class: 'f' }, el('span', {}, 'סיסמה חדשה (8 תווים לפחות)'), nw),
          el('label', { class: 'f' }, el('span', {}, 'שוב'), nw2)),
        savePw,
        el('p', { class: 'hint' }, 'החלפת סיסמה מנתקת את כל המכשירים האחרים.')),
      el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'מכשירים'),
        el('button', { type: 'button', class: 'small', onclick: async () => {
          try { const r = await api('me_logout_others', {}); msg.replaceChildren(notice(`נותקו ${r.count} מכשירים אחרים`, 'ok')); }
          catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
        } }, '🔌 ניתוק כל המכשירים האחרים')),
      msg));
  }

  // שגיאת JavaScript — נשלחת ללוג המעקב (עד 5 לטעינה), כדי שאפשר יהיה לדבג
  let reported = 0;
  const report = (message, where) => {
    if (!message) return;
    const [, src, line] = String(where || '').match(/^(.*?):(\d+)/) || [];
    keep(trace.errors, { message: String(message).slice(0, 300), source: src || String(where || '').slice(0, 80), line: line || '' }, 10);
    if (!me || reported >= 5) return;
    reported++;
    api('client_error', { message: String(message).slice(0, 500), where: String(where || '').slice(0, 300), page: location.hash.slice(0, 120) }).catch(() => {});
  };
  window.addEventListener('error', (e) => report(e.message, `${(e.filename || '').split('/').pop()}:${e.lineno}:${e.colno}`));
  window.addEventListener('unhandledrejection', (e) => report(e.reason?.message || e.reason, 'promise ' + (e.reason?.stack || '').split('\n')[1]?.trim()));

  // לעורך (editor.js): אותם עזרים, אותה מעטפת.
  window.DT = {
    api, el, notice, setTab, shareDialog, leave, rank, LEVEL, sheet, tile, setBack, toast,
    show: (...n) => show(...n),
    setLeaveGuard: (fn) => { leaveGuard = fn; },
    wide: () => document.body.classList.add('wide'),
  };

  window.addEventListener('hashchange', route);
  window.addEventListener('beforeunload', (e) => {
    if (leaveGuard && leaveGuard.dirty && leaveGuard.dirty()) { e.preventDefault(); e.returnValue = ''; }
  });
  boot();
})();
