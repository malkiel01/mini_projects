// תבניות מסמכים — המעטפת: חשבונות ומסך הפיתוח.
// נתיבים יחסיים בלבד — האפליקציה תעבור לדומיין משלה בלי שינוי.
(function () {
  'use strict';

  const app = document.getElementById('app');
  const nav = document.getElementById('nav');
  let me = null;
  // הכניסה דרך הכלים הדיגיטליים (lib/platform.php): mode — connected / detached / unavailable
  let platform = { mode: 'detached' };
  const viaPlatform = () => platform.mode !== 'detached';
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
      if (action.startsWith('platform_')) opts.headers['X-DT-Platform'] = '1';   // מסך החיבור — רק מהאפליקציה עצמה
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
  /** שדה סיסמה עם 👁 — הצגה / הסתרה של מה שהוקלד. */
  function pwField(input) {
    const btn = el('button', { type: 'button', class: 'pw-eye', 'aria-label': 'הצגת הסיסמה', 'aria-pressed': 'false', title: 'הצגת הסיסמה' }, '👁');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? '🙈' : '👁';
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? 'הסתרת הסיסמה' : 'הצגת הסיסמה'); btn.title = btn.getAttribute('aria-label');
      input.focus();
    });
    return el('span', { class: 'pw-wrap' }, input, btn);
  }

  function form(title, fields, submitText, onSubmit, footer) {
    const msg = el('div');
    const btn = el('button', { type: 'submit', class: 'primary' }, submitText);
    const f = el('form', { class: 'card form', novalidate: true },
      el('h1', {}, title),
      fields.map(fd => {
        const inp = el('input', { name: fd.name, type: fd.type || 'text', autocomplete: fd.auto || 'off',
          required: true, minlength: fd.min || null, dir: fd.dir || null });
        return el('label', {}, el('span', {}, fd.label), fd.type === 'password' ? pwField(inp) : inp);
      }),
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
      const r = await api('register', { name: v.name, email: v.email, password: v.password, invite: pendingInvite() || undefined });
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

  // ── כניסה דרך הכלים הדיגיטליים (platform/docs/auth.md) ────────

  /** מסך הכניסה של הפלטפורמה, וחזרה לכאן — כולל ה-# (הזמנה, מסמך). */
  const platformLogin = () => platform.login_url + encodeURIComponent(location.hash);
  const platformHome = () => (platform.login_url || '../').split('?')[0];

  function viewPlatformLogin(note) {
    if (platform.mode === 'unavailable') {
      return show(el('div', { class: 'card invite-card' }, el('h2', {}, 'הכניסה לא זמינה כרגע'),
        el('p', { class: 'hint' }, 'הכניסה היא דרך הכלים הדיגיטליים, והם לא עונים כרגע. נסו שוב בעוד כמה דקות.'),
        el('div', { class: 'invite-btns' }, el('button', { type: 'button', class: 'primary', onclick: () => boot() }, '↻ ניסיון חוזר'))));
    }
    show(el('div', { class: 'card invite-card' },
      platform.blocked ? notice(platform.blocked, 'bad') : null,
      note ? notice(note, 'warn') : null,
      el('h2', {}, 'כניסה'),
      el('p', { class: 'hint' }, 'הכניסה לתבניות המסמכים היא דרך החשבון שלך בכלים הדיגיטליים.'),
      platform.blocked ? null : el('div', { class: 'invite-btns' }, el('a', { class: 'btn primary', href: platformLogin() }, '🔑 כניסה דרך הכלים הדיגיטליים'))));
  }

  /** "יש לי כבר חשבון" — מייל וסיסמה ישנים, פעם אחת. onDone: אחרי שהחשבון חובר. */
  function linkForm(onDone) {
    return form('יש לי כבר חשבון', [
      { name: 'email', label: 'המייל של החשבון הישן', type: 'email', auto: 'username', dir: 'ltr' },
      { name: 'password', label: 'הסיסמה הישנה', type: 'password', auto: 'current-password' },
    ], 'חיבור החשבון', async (v) => {
      const r = await api('platform_link', v);
      toast('✓ החשבון של ' + r.name + ' חובר');
      await onDone();
    });
  }

  /** מסך חיבור חד-פעמי: אין באפליקציה חשבון באותו מייל — אולי יש ישן במייל אחר. */
  function viewPlatformLink() {
    const who = [platform.name, platform.email].filter(Boolean).join(' · ');
    const fresh = el('button', { type: 'button', class: 'primary', onclick: async () => {
      fresh.disabled = true;
      try { await api('platform_new', {}); await boot(); }
      catch (e) { fresh.disabled = false; out.replaceChildren(notice(e.message, 'bad')); }
    } }, '✨ אין לי חשבון — פתיחת חשבון חדש');
    const out = el('div');
    show(el('div', { class: 'card invite-card' },
        el('h2', {}, 'ברוכים הבאים לתבניות מסמכים'),
        el('p', {}, 'נכנסת בתור ', el('strong', { dir: 'auto' }, who), '.'),
        el('p', { class: 'hint' }, 'אין כאן חשבון במייל הזה. אם היה לך חשבון בתבניות המסמכים במייל אחר — חברו אותו, ותחזרו לתבניות ולמסמכים שלכם. אחרת — חשבון חדש נפתח מיד.'),
        el('div', { class: 'invite-btns' }, fresh), out),
      linkForm(boot));
  }

  // ── הזמנה לשיתוף (קישור מוואטסאפ) ─────────────────────────

  // האסימון נשמר עד שהשיתוף נוצר — גם אם בדרך עוברים בהרשמה ובאימות מייל
  const pendingInvite = () => { try { return localStorage.getItem('dt-invite') || ''; } catch (e) { return ''; } };
  const keepInvite = (t) => { try { t ? localStorage.setItem('dt-invite', t) : localStorage.removeItem('dt-invite'); } catch (e) { /* פרטי */ } };

  async function viewInvite(token) {
    keepInvite(token);
    show(el('p', { class: 'muted' }, 'טוען…'));
    let r;
    try { r = await api('invite_info', undefined, { token }); }
    catch (e) { keepInvite(''); return show(notice(e.message, 'bad'), links([me ? 'למסך הראשי' : 'כניסה', me ? '#home' : '#login'])); }
    const kind = r.type === 'template' ? 'התבנית' : 'המסמך';
    const head = el('div', { class: 'invite-head' }, el('span', { class: 'invite-ic', 'aria-hidden': 'true' }, r.type === 'template' ? '📄' : '📝'),
      el('div', {}, el('small', {}, `${r.from} שיתף/ה איתך את ${kind}`), el('strong', {}, r.title),
        el('span', { class: 'badge' }, (LEVEL_IC[r.level] || '') + ' ' + r.level_name)));
    if (!me && viaPlatform()) {
      return show(el('div', { class: 'card invite-card' }, head,
        el('p', { class: 'hint' }, 'כדי לפתוח — נכנסים דרך הכלים הדיגיטליים. אחרי הכניסה השיתוף מחכה לך כאן.'),
        el('div', { class: 'invite-btns' }, el('a', { class: 'btn primary', href: platformLogin() }, '🔑 כניסה דרך הכלים הדיגיטליים'))));
    }
    if (!me) {
      return show(el('div', { class: 'card invite-card' }, head,
        r.used ? notice('הקישור הזה כבר נוצל. אם זה היה אתה — פשוט נכנסים. אחרת, בקשו מהשולח קישור חדש.', 'warn')
          : el('p', { class: 'hint' }, 'כדי לפתוח — נכנסים לחשבון, או נרשמים אם אין. אחרי זה השיתוף מחכה לך.'),
        el('div', { class: 'invite-btns' }, el('a', { class: 'btn primary', href: '#register' }, '✍️ הרשמה'), el('a', { class: 'btn', href: '#login' }, 'כבר רשום? כניסה'))));
    }
    const msg = el('div');
    const go = el('button', { type: 'button', class: 'primary', onclick: async () => {
      go.disabled = true;
      try {
        const a = await api('invite_accept', { token });
        keepInvite('');
        toast('✓ ' + kind + ' נוספ' + (r.type === 'template' ? 'ה' : '') + ' לחשבון שלך');
        location.hash = (a.type === 'template' ? 'tpl=' : 'doc=') + a.id;
      } catch (e) { go.disabled = false; keepInvite(''); msg.replaceChildren(notice(e.message, 'bad')); }
    } }, '✓ קבלת השיתוף');
    show(el('div', { class: 'card invite-card' }, head,
      el('p', { class: 'hint' }, `השיתוף יתווסף לחשבון של ${me.name}.`), go, msg));
    if (r.used) go.click();   // כבר נוצל (בהרשמה) — אם בחשבון הזה, פשוט פותחים
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

  // מה כל הרשאה מתירה — משפט אחד, מתחלף לפי הבחירה
  const LEVEL_IC = { view: '👁', fill: '✍️', edit: '✏️', co_owner: '🔑' };
  const LEVEL_HINT = {
    // 15.4א: בתבנית — מנהל או עורך עם מתגים
    template: { edit: 'רק מה שמסמנים כאן. בלי אף מתג — רואה את התבנית בלבד.', co_owner: 'הכול, כמו הבעלים: עורך, מנפיק, משתף, מאשר, ומסיר מנהלים אחרים. את היוצר — לא.' },
    document: { view: 'רואה את המסמך ואת היומן שלו.', fill: 'מעדכן שדות במסמך, בלי לסיים ובלי לשלוח.',
      edit: 'ממלא, מסיים ושולח למשתתפים.', co_owner: 'כמו עורך, ובנוסף משתף אחרים ומעביר לארכיון.' },
  };

  /**
   * חלון "שיתוף עם צוות" — לבעלי חשבון בלבד (עובדים, שותפים), לתבנית או למסמך. למעלה: מי כבר רואה.
   * למטה: הוספה. לקוח שרק ממלא טופס לא מגיע לכאן — "📨 שליחה למילוי" נפרד (קישור, בלי חשבון).
   */
  async function shareDialog(type, id, title, after, opts = {}) {
    const what = type === 'template' ? 'התבנית' : 'המסמך';
    // "ממלא" שמור ללקוח בקישור; בצוות — מנפיק (תבנית) / מעדכן (מסמך)
    const tplMode = type === 'template';   // 15.4א: מנהל / עורך עם מתגים
    const LV = (k) => tplMode ? (k === 'co_owner' ? 'מנהל' : 'עורך') : k === 'fill' ? 'מעדכן' : LEVEL[k];
    const IC = (k) => tplMode && k === 'co_owner' ? '👑' : LEVEL_IC[k];
    let permNames = {}, myPerms = null;   // myPerms: null — בעלים / מנהל (הכול)
    const permsBox = el('div', { class: 'perm-box', hidden: true });
    /** מתגים לבחירה: רק מה שיש לי. sel — מה מסומן. */
    const permChecks = (sel, onchange) => el('div', { class: 'perm-list' }, Object.entries(permNames)
      .filter(([k]) => myPerms === null || myPerms.includes(k))
      .map(([k, t]) => el('label', { class: 'switch small' }, el('input', { type: 'checkbox', value: k, checked: sel.includes(k), onchange }), el('span', {}, t))));
    const pickedPerms = (box) => [...box.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
    const permsText = (p) => !p ? '' : p.length ? p.map(k => permNames[k] || k).join(' · ') : 'רואה בלבד';
    const list = el('ul', { class: 'share-list' }, el('li', { class: 'empty' }, 'טוען…'));
    const out = el('div');
    const email = el('input', { type: 'email', inputmode: 'email', autocomplete: 'email', placeholder: 'name@example.com', dir: 'ltr', required: true, 'aria-label': 'מייל' });
    // שיתוף בטלפון: הזמנה בקישור חד-פעמי בוואטסאפ — מי שפותח נכנס או נרשם, והשיתוף נוצר לחשבון שלו
    const phone = el('input', { type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: '050-0000000', dir: 'ltr', 'aria-label': 'טלפון' });
    const pname = el('input', { type: 'text', autocomplete: 'name', placeholder: 'שם (לא חובה)', 'aria-label': 'שם' });
    const viaSeg = el('div', { class: 'seg share-via', role: 'radiogroup', 'aria-label': 'איך לשתף' },
      ...[['email', '📧 במייל'], ['phone', '💬 הזמנה בוואטסאפ']].map(([v, t]) => el('label', {},
        el('input', { type: 'radio', name: 'share-via', value: v, checked: v === 'email', onchange: () => setVia(v) }), el('span', {}, t))));
    const byPhone = () => (viaSeg.querySelector('input:checked') || {}).value === 'phone';
    const levelHint = el('p', { class: 'hint share-hint' });
    const seg = el('div', { class: 'seg share-seg', role: 'radiogroup', 'aria-label': 'הרשאה' });
    const avatar = (name) => el('span', { class: 'avatar', style: `--h:${hueOf(name)}`, 'aria-hidden': 'true' }, initialsOf(name));
    const levels = () => tplMode ? (myPerms === null ? ['edit', 'co_owner'] : ['edit']) : Object.keys(LEVEL_IC).filter(k => rank(k) <= myRank);
    const levelSel = (cur, max, name) => {
      return el('select', { 'aria-label': 'הרשאה של ' + name }, (tplMode ? levels() : Object.keys(LEVEL).filter(k => k !== 'owner' && rank(k) <= max))
        .map(k => el('option', { value: k, selected: k === cur }, IC(k) + ' ' + LV(k))));
    };
    const pickLevel = () => (seg.querySelector('input:checked') || {}).value || (tplMode ? 'edit' : 'view');
    const showHint = () => {
      levelHint.textContent = LEVEL_HINT[type][pickLevel()] || '';
      permsBox.hidden = !(tplMode && pickLevel() === 'edit');
    };
    /** עורך קיים: חלון קטן עם המתגים שלו. */
    function permsSheet(sh) {
      const box = permChecks(sh.perms || [], () => {});
      const d = sheet('✏️ ' + (sh.name || sh.email) + ' — מה מותר לו', el('div', { class: 'field-sheet' }, box,
        el('div', { class: 'row-btns' }, el('button', { type: 'button', class: 'primary', onclick: async () => {
          if (await run('share_set', { type, id, user_id: sh.user_id, level: 'edit', perms: pickedPerms(box) }, '✓ ההרשאות עודכנו')) d.close();
        } }, 'שמירה'))));
    }
    let myRank = 4;
    async function load() {
      try {
        const r = await api('share_list', undefined, { type, id });
        const prev = pickLevel();
        myRank = rank(r.my_level);
        if (tplMode) {
          permNames = r.perm_names || {}; myPerms = r.my_perms ?? null;
          const keep = permsBox.firstChild ? pickedPerms(permsBox) : ['issue', 'fill'];
          permsBox.replaceChildren(el('small', { class: 'muted' }, 'מה מותר לעורך:'), permChecks(keep.filter(k => myPerms === null || myPerms.includes(k)), () => {}));
        }
        seg.replaceChildren(...levels().map(k => el('label', {},
          el('input', { type: 'radio', name: 'share-level', value: k, checked: k === prev, onchange: showHint }),
          el('span', {}, IC(k) + ' ' + LV(k)))));
        if (!seg.querySelector('input:checked')) seg.querySelector('input').checked = true;
        showHint();
        const invites = r.invites || [];
        count.textContent = r.shares.length + invites.length ? String(r.shares.length + invites.length + 1) : '';
        // הבעלים ראשון, ואחריו כל מי ששותף: שם, מייל ומאז מתי — ומשמאל ההרשאה וניתוק
        list.replaceChildren(
          el('li', { class: 'share-row owner' }, avatar(r.owner),
            el('div', { class: 'share-who' }, el('strong', {}, r.owner), el('small', {}, 'יצר את ' + what)),
            el('span', { class: 'badge' }, '👑 בעלים')),
          ...r.shares.map(s => {
            const name = s.name || s.email;
            // המאשר של מסמך "רק באישור שלי" — מוגן; מנהל-שותף — רק הבעלים משנה אותו
            // תבנית (15.4א): מנהל משנה גם מנהל; עורך שמשתף — לא נוגע במנהל
            const locked = s.user_id === r.approver ? '✓ המאשר' : s.level === 'co_owner' && (tplMode ? myPerms !== null : myRank < 5) ? IC('co_owner') + ' ' + LV('co_owner') : null;
            if (locked) return el('li', { class: 'share-row' }, avatar(name),
              el('div', { class: 'share-who' }, el('strong', {}, name),
                el('small', {}, el('span', { dir: 'ltr' }, s.email), s.since ? ' · מאז ' + new Date(s.since).toLocaleDateString('he-IL') : '')),
              el('span', { class: 'badge' }, locked));
            const sel = levelSel(s.level, myRank, name);
            sel.addEventListener('change', () => run('share_set', { type, id, user_id: s.user_id, level: sel.value,
              ...(tplMode && sel.value === 'edit' ? { perms: s.perms || ['issue', 'fill'] } : {}) }, `✓ ההרשאה של ${name} עודכנה`));
            return el('li', { class: 'share-row' }, avatar(name),
              el('div', { class: 'share-who' }, el('strong', {}, name),
                el('small', {}, el('span', { dir: 'ltr' }, s.email), s.since ? ' · מאז ' + new Date(s.since).toLocaleDateString('he-IL') : '',
                  s.pending ? ' · ⏳ החשבון ממתין לאישור' : s.blocked ? ' · 🚫 החשבון חסום' : ''),
                tplMode && s.level === 'edit' ? el('small', { class: 'perm-sum' }, permsText(s.perms)) : null),
              el('div', { class: 'share-ctl' }, sel,
                tplMode && s.level === 'edit' ? el('button', { type: 'button', class: 'small', 'aria-label': 'המתגים של ' + name, onclick: () => permsSheet(s) }, '⚙️') : null,
                el('button', { type: 'button', class: 'small share-cut', 'aria-label': 'ניתוק ' + name, onclick: () => {
                  if (confirm(`לנתק את ${name}?\nהגישה שלו ${type === 'template' ? 'לתבנית' : 'למסמך'} תיחסם מיד.`)) run('share_remove', { type, id, user_id: s.user_id }, `✓ ${name} נותק`);
                } }, '🚫 ניתוק')));
          }),
          // הזמנות בוואטסאפ שעוד לא נפתחו
          ...invites.map(i => {
            const name = i.name || i.phone;
            return el('li', { class: 'share-row invite' }, el('span', { class: 'avatar', 'aria-hidden': 'true' }, '💬'),
              el('div', { class: 'share-who' }, el('strong', {}, name),
                el('small', {}, '⏳ ממתין שייכנס · ' + IC(i.level) + ' ' + LV(i.level) + (tplMode && i.perms ? ' (' + permsText(i.perms) + ')' : '') + (i.name ? ' · ' : ''), i.name ? el('span', { dir: 'ltr' }, i.phone) : '')),
              el('div', { class: 'share-ctl' },
                el('button', { type: 'button', class: 'small', 'aria-label': 'שליחה חוזרת ל' + name, onclick: () => invite('share_invite_resend', { type, id, invite_id: i.id }) }, '💬 שוב'),
                el('button', { type: 'button', class: 'small share-cut', 'aria-label': 'ביטול ההזמנה של ' + name, onclick: () => {
                  if (confirm(`לבטל את ההזמנה של ${name}?\nהקישור שנשלח לו יפסיק לעבוד.`)) run('share_invite_cancel', { type, id, invite_id: i.id }, '✓ ההזמנה בוטלה');
                } }, '🚫 ביטול')));
          }),
          ...(r.shares.length || invites.length ? [] : [el('li', { class: 'share-none' }, `עוד לא שיתפת את ${what} עם אף אחד.`)]));
      } catch (e) {
        list.replaceChildren(el('li', {}, notice(e.message, 'bad')));
      }
    }
    async function run(action, body, ok) {
      out.replaceChildren();
      try { await api(action, body); await load(); if (ok) toast(ok); if (after) after(); return true; }
      catch (e) { out.replaceChildren(notice(e.message, 'bad')); return false; }
    }
    /** הזמנה בוואטסאפ: החלון נפתח מיד (אחרת הדפדפן חוסם), והכתובת נקבעת כשהקישור מוכן. */
    async function invite(action, body) {
      out.replaceChildren();
      const wa = window.open('', '_blank');
      try {
        const r = await api(action, body);
        if (wa) wa.location.href = r.whatsapp; else location.href = r.whatsapp;
        const copy = el('button', { type: 'button', class: 'small', onclick: async () => {
          try { await navigator.clipboard.writeText(r.text); toast('✓ ההודעה והקישור הועתקו'); } catch (e) { prompt('העתיקו את הקישור:', r.link); }
        } }, '📋 העתקת ההודעה');
        out.replaceChildren(el('div', { class: 'notice ok share-sent' },
          el('span', {}, '✓ ההזמנה מוכנה בוואטסאפ. הקישור אישי וחד-פעמי, ובתוקף 14 יום.'), copy));
        await load();
        if (after) after();
        return true;
      } catch (e) {
        if (wa) wa.close();
        out.replaceChildren(notice(e.message, 'bad'));
        return false;
      }
    }
    const submit = el('button', { type: 'submit', class: 'primary' }, '➕ שיתוף');
    const viaHint = el('p', { class: 'hint' });
    // מי שרק ממלא טופס לא צריך חשבון: קישור מילוי בוואטסאפ ("📨 שליחה למילוי"), לא הזמנה לחשבון
    const toFill = type === 'template' ? () => window.DTDocs.issue(id, true) : opts.fill;
    const fillBox = toFill ? el('div', { class: 'share-fill' },
      el('div', {}, el('strong', {}, 'לקוח שרק ממלא טופס?'),
        el('small', {}, 'לא כאן — הוא לא צריך חשבון. שולחים לו קישור למילוי, בוואטסאפ או במייל.')),
      el('button', { type: 'button', class: 'small', onclick: () => { dlg.close(); Promise.resolve(toFill()).catch(e => toast(e.message, true)); } }, '📨 שליחה למילוי')) : null;
    const phoneRow = el('div', { class: 'share-phone' }, phone, pname);
    function setVia(v) {
      const p = v === 'phone';
      email.hidden = p; email.required = !p;
      phoneRow.hidden = !p; phone.required = p;
      submit.textContent = p ? '💬 שליחת הזמנה בוואטסאפ' : '➕ שיתוף';
      viaHint.textContent = p
        ? 'לעובד או שותף שעוד אין לו חשבון: מקבל קישור אישי, נרשם דרכו — והשיתוף נוצר לחשבון שלו.'
        : 'לעובד או שותף שכבר רשום במערכת.';
    }
    const add = el('form', { class: 'card share-add' },
      el('h3', {}, 'הוספה לצוות'),
      viaSeg, email, phoneRow, seg, levelHint, permsBox, submit, viaHint);
    setVia('email');
    add.addEventListener('submit', async (e) => {
      e.preventDefault();
      submit.disabled = true;
      const extra = tplMode && pickLevel() === 'edit' ? { perms: pickedPerms(permsBox) } : {};
      if (byPhone()) {
        if (await invite('share_invite', { type, id, phone: phone.value, name: pname.value, level: pickLevel(), ...extra })) { phone.value = ''; pname.value = ''; }
      } else if (await run('share_set', { type, id, email: email.value, level: pickLevel(), ...extra }, '✓ שותף')) email.value = '';
      submit.disabled = false;
    });
    const count = el('span', { class: 'count' });
    const dlg = sheet('👥 שיתוף עם צוות — ' + title, el('div', { class: 'share-sheet' },
      fillBox,
      el('h3', { class: 'share-title' }, `מי בצוות של ${what}`, count),
      list, out, add));
    await load();
  }

  async function leave(type, id, title, after) {
    if (!confirm(`לעזוב את "${title}"? ${type === 'template' ? 'התבנית תיעלם' : 'המסמך ייעלם'} מהרשימה שלך.`)) return;
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
          t.owner_name ? `של ${t.owner_name} · ${t.perms ? 'עורך' : t.access === 'co_owner' ? 'מנהל' : LEVEL[t.access]}` : null].filter(Boolean).join(' · ');
        const can = (k) => !t.perms || t.perms.includes(k);   // 15.4א: עורך — לפי המתגים
        const canIssue = !t.archived && t.fields && rank(t.access) >= 2 && can('issue');
        // 15.3: לחיצה על תבנית = טופס מילוי חדש; אין מה למלא (בלי שדות / ארכיון / צפייה) — העורך
        const open = canIssue
          ? el('button', { type: 'button', class: 'use-row tpl-open', title: 'מילוי מסמך חדש', onclick: () => issue(t, true) }, rowBody(t, sub))
          : el('a', { class: 'use-row tpl-open', href: '#tpl=' + t.id, title: rank(t.access) >= 3 ? 'עריכת התבנית' : 'צפייה בתבנית' }, rowBody(t, sub));
        return el('li', { class: 'tpl-card' + (t.archived ? ' archived' : '') }, open,
          el('button', { class: 'icon-btn', 'aria-label': 'פעולות', title: 'פעולות', onclick: () => actions(t) }, '⋯'));
      })));
    }

    const rowBody = (t, sub) => [
      el('span', { class: 'use-ic', 'aria-hidden': 'true' }, SOURCE_IC[t.source] || '📄'),
      el('span', { class: 'use-tx' }, el('strong', {}, t.name), el('small', {}, sub), window.DTCatalog.chips(t.tags))];

    /** פעולות על תבנית (15.3) — העיקריות, ומתחת "עוד". רק מה שמותר. */
    function actions(t) {
      const r = rank(t.access);
      const can = (k) => !t.perms || t.perms.includes(k);   // 15.4א: עורך — לפי המתגים
      let dlg;
      const go = (fn) => () => { dlg.close(); fn(); };
      const more = [
          t.used ? tile('🗂️', 'מסמכים', go(() => { location.hash = 'docs?template=' + t.id; })) : null,
          // שלב 12: הנפקה מאקסל וייצוא התשובות
          !t.archived && t.fields && r >= 2 && can('issue') ? tile('📥', 'הנפקה מאקסל', go(() => window.DTBulk.open(t)), { sub: 'מסמך לכל שורה' }) : null,
          t.used ? tile('📤', 'ייצוא תשובות', go(async () => {
            try { await window.DTBulk.exportFile({ template_id: t.id }); }
            catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
          }), { sub: 'לאקסל' }) : null,
          r >= 3 ? tile('📦', 'ייצוא תבנית', go(() => window.DTPort.exportDialog(t)), { sub: 'PDF, Word או JSON' }) : null].filter(Boolean);
      dlg = sheet(t.name, [
        el('p', { class: 'hint' }, `${SOURCE[t.source] || t.source} · ${t.pages} דפים · ${t.fields} שדות · גרסה ${t.version}`),
        el('div', { class: 'tiles' },
          tile(r >= 3 ? '✏️' : '👁️', r >= 3 ? 'עריכת התבנית' : 'צפייה בתבנית', go(() => { location.hash = 'tpl=' + t.id; })),
          r >= 4 || (t.perms || []).includes('share') ? tile('👥', 'שיתוף התבנית', go(() => shareDialog('template', t.id, t.name, load)), { sub: 'עם בעלי חשבון' }) : null,
          !t.archived && t.fields && r >= 2 && can('issue') && can('send') ? tile('🔗', 'טופס ללקוח', go(async () => {
            try { await window.DTDocs.issue(t.id, true); } catch (e) { msg.replaceChildren(notice(e.message, 'bad')); }
          }), { sub: 'קישור חד-פעמי' }) : null,
          t.used || t.access !== 'owner' ? null : tile('🗑️', 'מחיקה', go(() => remove(t)), { danger: true }),
          r >= 4 ? tile(t.archived ? '♻️' : '🗄️', t.archived ? 'שחזור' : 'ארכיון', go(() => archive(t))) : null,
          t.access === 'owner' ? null : tile('🚪', 'עזיבה', go(() => leave('template', t.id, t.name, load)), { danger: true })),
        more.length ? el('h3', { class: 'sheet-sub' }, 'עוד') : null,
        more.length ? el('div', { class: 'tiles' }, more) : null,
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

    async function issue(t, fresh) {
      try { await window.DTDocs.issue(t.id, false, fresh); }
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
          if (!p) {
            // הגדרות מוכנות (שדות ורשימות) בתוך ה-PDF — כמו "מקובץ": נוצרת תבנית, והעורך מחיל אותן
            if (await window.DTPort.hasFields(f)) {
              out.replaceChildren(notice('מייבא…'));
              try {
                const fd = new FormData(); fd.append('file', f); fd.append('mode', 'locked');
                fd.append('name', name.value.trim() || f.name.replace(/\.[^.]+$/, ''));
                const r = await api('tpl_create', fd);
                dlg.close(); location.hash = 'tpl=' + r.id;
              } catch (err) { out.replaceChildren(notice(err.message, 'bad')); }
              return;
            }
            out.replaceChildren(notice('ב-PDF הזה אין הגדרות שיוצאו מהמערכת. כדי ליצור ממנו תבנית רגילה — "מקובץ".', 'bad')); return;
          }
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
      el('p', { class: 'hint' }, '🔒 רק אתה ומי ששיתפת רואים את התבניות שלך. הן נשמרות מוצפנות בשרת.')));
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
    if (key === 'invite' && val) return viewInvite(val);
    if (!me && viaPlatform()) {
      if (platform.link) return viewPlatformLink();
      return viewPlatformLogin(['verify', 'reset', 'register', 'forgot'].includes(key) ? 'ההרשמה והסיסמה של האפליקציה הוחלפו בכניסה דרך הכלים הדיגיטליים.' : '');
    }
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
    if (key === 'shares') return window.DTDocs.shares();
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
      platform = r.platform || { mode: 'detached' };
    } catch (e) {
      me = null;
    }
    nav.hidden = !me;
    meBtn.hidden = !me;
    paintMe();
    document.getElementById('nav-dev').hidden = !(me && me.is_developer);
    document.getElementById('who').textContent = me ? me.name : '';
    if (me && /^(login|register|forgot|verify=.*|reset=.*)?$/.test(location.hash.slice(1))) location.hash = pendingInvite() ? 'invite=' + pendingInvite() : 'home';
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
    keepInvite('');   // הזמנה שלא נוצלה לא עוברת למי שייכנס אחריו באותו מכשיר
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
      viaPlatform() && platform.can_relink ? item('🔗', 'חיבור חשבון ישן', relink, 'היה לך חשבון כאן במייל אחר?') : null,
      el('div', { class: 'menu-sep' }),
      // דרך הפלטפורמה — יוצאים מהפלטפורמה (יציאה ממנה = יציאה מכל האפליקציות)
      viaPlatform() ? item(logoutIcon(), 'לכלים הדיגיטליים', () => { location.href = platformHome(); }, 'יציאה — משם')
        : item(logoutIcon(), 'יציאה', logout));
    document.body.append(menu);
    meBtn.setAttribute('aria-expanded', 'true');
    document.addEventListener('pointerdown', outside, true);
    menu.querySelector('.menu-item').focus();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menu) { closeMenu(); meBtn.focus(); } });
  window.addEventListener('hashchange', closeMenu);

  /** חשבון שנפתח דרך הפלטפורמה ועוד ריק — מחליפים אותו בחשבון ישן. */
  function relink() {
    const dlg = sheet('🔗 חיבור חשבון ישן', el('div', { class: 'field-sheet' },
      el('p', { class: 'hint' }, 'החשבון שנפתח עכשיו (ריק) יוחלף בחשבון הישן — עם התבניות והמסמכים שלו.'),
      linkForm(async () => { dlg.close(); await boot(); })));
  }

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
      viaPlatform() ? el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'סיסמה'),
        el('p', { class: 'hint' }, 'הכניסה היא דרך הכלים הדיגיטליים — הסיסמה מתחלפת שם.')) :
      el('section', { class: 'fs-group' }, el('h3', { class: 'sec-title' }, 'סיסמה'),
        el('label', { class: 'f' }, el('span', {}, 'הסיסמה הנוכחית'), pwField(cur)),
        el('div', { class: 'fields two' }, el('label', { class: 'f' }, el('span', {}, 'סיסמה חדשה (8 תווים לפחות)'), pwField(nw)),
          el('label', { class: 'f' }, el('span', {}, 'שוב'), pwField(nw2))),
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
