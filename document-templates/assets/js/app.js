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

  async function api(action, body, query) {
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
    for (const c of children.flat()) if (c != null) n.append(c);
    return n;
  }

  function show(...nodes) {
    clearInterval(devTimer);
    leaveGuard = null;
    document.body.classList.remove('wide');
    app.replaceChildren(...nodes.filter(n => n != null));
  }

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
    for (const a of nav.querySelectorAll('[data-tab]')) a.classList.toggle('active', a.dataset.tab === tab);
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

  const SOURCE = { pdf_locked: '📄 PDF', image: '🖼️ תמונה', docx: '📝 Word', pdf_parsed: '📄 PDF', authored: '✍️ נכתב כאן' };

  async function viewHome() {
    setTab('home');
    const list = el('div', { class: 'tpl-grid' }, el('p', { class: 'muted' }, 'טוען…'));
    const msg = el('div');
    const showArchived = el('input', { type: 'checkbox' });
    const tagSel = el('select', { 'aria-label': 'סינון לפי תגית' }, el('option', { value: '' }, 'כל התגיות'));
    tagSel.addEventListener('change', () => render());
    let all = [];

    function render() {
      const items = all.filter(t => (showArchived.checked || !t.archived) && (!tagSel.value || (t.tags || []).includes(tagSel.value)));
      if (!items.length) {
        list.replaceChildren(el('p', { class: 'muted' }, all.length
          ? 'כל התבניות בארכיון.'
          : 'עוד אין תבניות. התחילו ב"תבנית חדשה" — מעלים PDF או תמונה, וממקמים עליהם שדות.'));
        return;
      }
      list.replaceChildren(...items.map(t => el('article', { class: 'tpl-card' + (t.archived ? ' archived' : '') },
        el('a', { class: 'tpl-open', href: '#tpl=' + t.id },
          el('strong', {}, t.name),
          t.owner_name ? el('span', { class: 'shared-tag small' }, `👥 של ${t.owner_name} · ${LEVEL[t.access]}`) : null,
          window.DTCatalog.chips(t.tags),
          el('span', { class: 'muted small' }, `${SOURCE[t.source] || t.source} · ${t.pages} דפים · ${t.fields} שדות · גרסה ${t.version}`)),
        el('div', { class: 'ops' },
          t.archived || !t.fields || rank(t.access) < 2 ? null : el('button', { class: 'small primary', onclick: () => issue(t) }, 'הנפקת מסמך'),
          el('a', { class: 'btn small', href: '#tpl=' + t.id }, rank(t.access) >= 3 ? 'עריכה' : 'צפייה'),
          t.used ? el('a', { class: 'btn small', href: '#docs?template=' + t.id }, 'מסמכים') : null,
          rank(t.access) >= 4 ? el('button', { class: 'small', onclick: () => shareDialog('template', t.id, t.name, load) }, '🔗 שיתוף') : null,
          rank(t.access) >= 4 ? el('button', { class: 'small', onclick: () => archive(t) }, t.archived ? 'שחזור' : 'ארכיון') : null,
          t.used || t.access !== 'owner' ? null : el('button', { class: 'small danger', onclick: () => remove(t) }, 'מחיקה'),
          t.access === 'owner' ? null : el('button', { class: 'small', onclick: () => leave('template', t.id, t.name, load) }, 'עזיבה')))));
    }

    const usage = el('p', { class: 'muted small usage' });
    async function load() {
      try {
        const r = await api('tpl_list');
        all = r.templates;
        const tags = [...new Set(all.flatMap(t => t.tags || []))].sort();
        const cur = tagSel.value;
        tagSel.replaceChildren(el('option', { value: '' }, 'כל התגיות'), ...tags.map(t => el('option', { value: t, selected: t === cur }, t)));
        tagSel.hidden = !tags.length;
        if (r.usage) {
          const pct = Math.min(100, Math.round(r.usage.used_mb / r.usage.storage_mb * 100));
          usage.replaceChildren(`נפח: ${r.usage.used_mb < 0.1 ? 'פחות מ-0.1' : r.usage.used_mb}MB מתוך ${r.usage.storage_mb}MB · קובץ עד ${r.usage.max_file_mb}MB `,
            el('span', { class: 'meter' }, el('span', { style: `width:${pct}%`, class: pct > 90 ? 'full' : '' })));
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

    const upload = el('form', { class: 'upload', hidden: true },
      el('label', {}, el('span', {}, 'שם התבנית'), el('input', { name: 'name', required: true, maxlength: 120 })),
      el('label', {}, el('span', {}, 'קובץ: PDF או תמונה (PNG, JPG, WEBP)'),
        el('input', { name: 'file', type: 'file', required: true, accept: 'application/pdf,image/png,image/jpeg,image/webp' })),
      el('div', { class: 'ops' },
        el('button', { type: 'submit', class: 'primary' }, 'העלאה ופתיחה בעורך'),
        el('button', { type: 'button', onclick: () => { upload.hidden = true; } }, 'ביטול')));
    upload.querySelector('[name=file]').addEventListener('change', (e) => {
      const name = upload.querySelector('[name=name]');
      const f = e.target.files[0];
      if (f && !name.value) name.value = f.name.replace(/\.[^.]+$/, '');
    });
    upload.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = upload.querySelector('[type=submit]');
      btn.disabled = true;
      btn.textContent = 'מעלה…';
      try {
        const r = await api('tpl_create', new FormData(upload));
        location.hash = 'tpl=' + r.id;
      } catch (err) {
        msg.replaceChildren(notice(err.message, 'bad'));
        btn.disabled = false;
        btn.textContent = 'העלאה ופתיחה בעורך';
      }
    });
    showArchived.addEventListener('change', render);

    show(el('section', { class: 'card' },
      el('div', { class: 'row' },
        el('h1', {}, 'התבניות שלי'),
        el('button', { class: 'primary', onclick: () => { upload.hidden = false; upload.querySelector('[name=name]').focus(); } },
          '+ תבנית חדשה')),
      el('p', { class: 'muted small' }, 'רק אתה רואה את התבניות שלך — גם לא המפתח. הקבצים נשמרים מוצפנים.'),
      upload, msg, list,
      el('div', { class: 'filters' }, tagSel, el('label', { class: 'inline small muted' }, showArchived, ' להציג גם תבניות בארכיון')), usage));
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

  async function viewDev() {
    setTab('dev');
    const msg = el('div');
    const body = el('tbody');
    const summary = el('p', { class: 'muted' });

    async function load() {
      try {
        const r = await api('dev_users');
        const online = r.users.filter(u => u.online).length;
        const pending = r.users.filter(u => u.status === 'pending').length;
        summary.textContent = `${r.users.length} חשבונות · ${online} מחוברים עכשיו · ${pending} ממתינים לאישור`;
        body.replaceChildren(...r.users.map(row));
      } catch (e) {
        msg.replaceChildren(notice(e.message, 'bad'));
      }
    }

    function row(u) {
      const [label, kind] = STATUS[u.status] || [u.status, ''];
      const ops = u.id === me.id ? [] : (OPS[u.status] || []);
      return el('tr', {},
        el('td', {}, el('span', { class: 'dot ' + (u.online ? 'on' : ''), title: u.online ? 'מחובר' : 'לא מחובר' }),
          el('strong', {}, u.display_name), u.is_developer ? el('span', { class: 'tag' }, 'מפתח') : null,
          el('div', { class: 'muted small', dir: 'ltr' }, u.email)),
        el('td', { 'data-label': 'מצב' }, el('span', { class: 'badge ' + kind }, label)),
        el('td', { class: 'small', 'data-label': 'נראה לאחרונה' }, fmtTime(u.last_seen_at),
          el('div', { class: 'muted' }, u.devices ? u.devices + ' מכשירים' : 'אין חיבור פעיל')),
        el('td', { class: 'small muted', 'data-label': 'נרשם' }, fmtTime(u.created_at)),
        el('td', { class: 'small', 'data-label': 'נפח' }, u.quota
          ? el('button', { class: 'link small', title: 'עריכת מכסות', onclick: () => quotaDialog(u) },
              `${u.quota.used_mb}/${u.quota.storage_mb}MB`) : '—'),
        el('td', { class: 'ops' }, ops.map(([op, text]) =>
          el('button', { class: op === 'approve' ? 'primary small' : 'small', onclick: () => act(u, op) }, text))));
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

    show(el('section', { class: 'card' },
      el('h1', {}, 'פיתוח — חשבונות'),
      el('p', { class: 'muted small' }, 'כאן רואים חשבונות בלבד. תבניות ומסמכים של משתמשים אינם נגישים מכאן — לעולם.'),
      summary, msg,
      el('div', { class: 'table-wrap' }, el('table', {},
        el('thead', {}, el('tr', {}, ['משתמש', 'מצב', 'נראה לאחרונה', 'נרשם', 'נפח', ''].map(h => el('th', {}, h)))),
        body))));
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
    if (key === 'dev' && me.is_developer) return viewDev();
    if (key === 'tpl' && val) return window.DTEditor.open(Number(val));
    if (key === 'docs') return window.DTDocs.list();
    if (key === 'contacts') return window.DTCatalog.view();
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
    document.getElementById('nav-dev').hidden = !(me && me.is_developer);
    document.getElementById('who').textContent = me ? me.name : '';
    if (me && /^(login|register|forgot)?$/.test(location.hash.slice(1))) location.hash = 'home';
    route();
  }

  document.getElementById('logout').addEventListener('click', async () => {
    try { await api('logout', {}); } catch (e) { /* יוצאים בכל מקרה */ }
    me = null;
    csrf = '';
    location.hash = 'login';
    boot();
  });

  // לעורך (editor.js): אותם עזרים, אותה מעטפת.
  window.DT = {
    api, el, notice, setTab, shareDialog, leave, rank, LEVEL,
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
