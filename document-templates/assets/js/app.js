// תבניות מסמכים — המעטפת: חשבונות ומסך הפיתוח.
// נתיבים יחסיים בלבד — האפליקציה תעבור לדומיין משלה בלי שינוי.
(function () {
  'use strict';

  const app = document.getElementById('app');
  const nav = document.getElementById('nav');
  let me = null;
  let csrf = '';
  let devTimer = null;

  // ── עזרים ──────────────────────────────────────────────────

  async function api(action, body) {
    const opts = { credentials: 'same-origin', headers: {} };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      if (csrf) opts.headers['X-CSRF'] = csrf;
      opts.body = JSON.stringify(body);
    }
    let res, data;
    try {
      res = await fetch('api.php?action=' + action, opts);
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
    app.replaceChildren(...nodes);
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

  function viewHome() {
    setTab('home');
    show(el('section', { class: 'card' },
      el('h1', {}, 'התבניות שלי'),
      el('p', {}, 'כאן יופיעו התבניות והמסמכים שלך. אף אחד אחר לא רואה אותם — גם לא המפתח — אלא אם תשתף.'),
      el('p', { class: 'muted' }, 'בבנייה: השלב הבא הוא יצירת תבנית מ-PDF או מתמונה, ומיקום שדות עליה.')));
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
        el('td', { class: 'ops' }, ops.map(([op, text]) =>
          el('button', { class: op === 'approve' ? 'primary small' : 'small', onclick: () => act(u, op) }, text))));
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
        el('thead', {}, el('tr', {}, ['משתמש', 'מצב', 'נראה לאחרונה', 'נרשם', ''].map(h => el('th', {}, h)))),
        body))));
    await load();
    devTimer = setInterval(load, 30000);
  }

  // ── ניתוב ──────────────────────────────────────────────────

  function route() {
    const h = location.hash.slice(1);
    const [key, val] = h.split('=');
    if (key === 'verify' && val) return viewVerify(val);
    if (key === 'reset' && val) return viewReset(val);
    if (!me) {
      if (key === 'register') return viewRegister();
      if (key === 'forgot') return viewForgot();
      return viewLogin();
    }
    if (key === 'dev' && me.is_developer) return viewDev();
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

  window.addEventListener('hashchange', route);
  boot();
})();
