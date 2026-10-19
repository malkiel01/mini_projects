/*
 * הצעות תקציב — המעטפת בדפדפן.
 *
 * ניתוב ב-hash:  #/  הבית · #/me  הגדרות משתמש · #/dev  אזור הפיתוח (למפתח)
 * קישורים מהמייל: #verify=<אסימון> · #reset=<אסימון>
 *
 * כל טקסט שמגיע מהשרת מוצב כ-textContent (דרך h()), לעולם לא כ-HTML.
 */
'use strict';

const S = { user: null, csrf: '', devTab: sessionStorage.getItem('ob-dev-tab') || 'users' };
const $ = sel => document.querySelector(sel);
const main = $('#main');

// ── עזרים ────────────────────────────────────────────────────

/** בונה אלמנט: h('div.card', {onclick}, 'טקסט', ילד...) */
function h(tag, attrs, ...kids) {
    const [name, ...classes] = tag.split('.');
    const el = document.createElement(name || 'div');
    if (classes.length) el.className = classes.join(' ');
    // הארגומנט השני הוא תכונות רק כשהוא אובייקט פשוט; כל דבר אחר (גם 0) — תוכן
    if (attrs === null || attrs === undefined || Object.getPrototypeOf(attrs) !== Object.prototype) { kids.unshift(attrs); attrs = null; }
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else if (k === 'style') el.style.cssText = v;
        else if (k === 'dataset') Object.assign(el.dataset, v);
        else if (k in el && k !== 'list' && k !== 'form') el[k] = v;
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) {
        if (kid === null || kid === undefined || kid === false) continue;
        el.append(kid instanceof Node ? kid : String(kid));
    }
    return el;
}

async function api(action, body, query = '') {
    const opt = { method: body ? 'POST' : 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body) {
        opt.headers['Content-Type'] = 'application/json';
        if (S.csrf) opt.headers['X-CSRF'] = S.csrf;
        opt.body = JSON.stringify(body);
    }
    let res, data;
    try {
        res = await fetch(`api.php?action=${encodeURIComponent(action)}${query}`, opt);
        data = await res.json();
    } catch {
        throw new Error(res ? `שגיאת שרת (${res.status})` : 'אין חיבור לשרת');
    }
    if (!data.ok) {
        if (res.status === 401 && S.user) { S.user = null; render(); }
        const err = new Error(data.error || 'שגיאה');
        err.status = res.status;
        throw err;
    }
    return data;
}

function toast(msg, err = false) {
    const el = $('.toast');
    el.textContent = msg;
    el.classList.toggle('err', err);
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

const fmtDate = iso => iso ? new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }) : '—';
const fmtTime = iso => iso ? new Date(iso).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
const initials = name => (name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');
const hue = s => [...(s || '')].reduce((a, c) => (a * 31 + c.codePointAt(0)) % 360, 7);

/** טופס: ערכי השדות לפי name */
const formData = form => Object.fromEntries(new FormData(form).entries());

/** כפתור שמבצע פעולה אסינכרונית: נעול בזמן הריצה, ושגיאה בטוסט או בשדה. */
function busy(btn, fn, errBox) {
    return async (e) => {
        e?.preventDefault?.();
        if (btn.disabled) return;
        btn.disabled = true;
        if (errBox) errBox.hidden = true;
        try { await fn(); } catch (err) {
            if (errBox) { errBox.textContent = err.message; errBox.hidden = false; } else toast(err.message, true);
        } finally { btn.disabled = false; }
    };
}

/** שדה סיסמה עם עינית: 👁️ מציגה את הסיסמה, 🙈 מסתירה שוב. */
function pwField(label, name, autocomplete) {
    const input = h('input', { name, type: 'password', dir: 'ltr', autocomplete, required: true });
    const eye = h('button.icon-btn.eye', { type: 'button', 'aria-label': 'הצגת הסיסמה', 'aria-pressed': 'false' }, '👁️');
    eye.addEventListener('click', e => {
        e.preventDefault();
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        eye.textContent = show ? '🙈' : '👁️';
        eye.setAttribute('aria-label', show ? 'הסתרת הסיסמה' : 'הצגת הסיסמה');
        eye.setAttribute('aria-pressed', String(show));
        input.focus();
    });
    return h('label.f', h('span', label), h('div.pw', input, eye));
}

// גובה נראה בלי המקלדת — הגיליון מתקצר והכול נגלל
(() => {
    const vv = window.visualViewport; if (!vv) return;
    const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
    vv.addEventListener('resize', set); set();
})();

// שגיאת JavaScript — ללוג המעקב (פעם אחת לדף, רק כשמחוברים)
let jsErrSent = false;
window.addEventListener('error', e => {
    if (jsErrSent || !S.user) return;
    jsErrSent = true;
    api('client_error', { message: e.message, where: `${(e.filename || '').split('/').pop()}:${e.lineno}`, page: location.hash }).catch(() => {});
});

// ── גיליון (dialog) ─────────────────────────────────────────

const sheet = $('#sheet');
document.addEventListener('click', e => { if (e.target.closest('[data-close]')) e.target.closest('dialog').close(); });

function openSheet(title, body, action = null) {
    $('#sheet-title').textContent = title;
    $('#sheet-action').replaceChildren(...(action ? [action] : []));
    $('#sheet-body').replaceChildren(...[body].flat().filter(Boolean));
    if (!sheet.open) sheet.showModal();
}

// ── כותרת ותפריט ─────────────────────────────────────────────

function setHeader(title, sub = '', back = false) {
    $('#title').textContent = title;
    $('#subtitle').textContent = sub;
    $('#back').hidden = !back;
    document.title = title === 'הצעות תקציב' ? 'הצעות תקציב לארגונים' : `${title} · הצעות תקציב`;
}

$('#back').addEventListener('click', () => (history.length > 1 ? history.back() : (location.hash = '#/')));

const menu = $('#menu');
$('#me-btn').addEventListener('click', e => {
    e.stopPropagation();
    if (!menu.hidden) { menu.hidden = true; return; }
    const item = (icon, label, fn) => h('button', { role: 'menuitem', type: 'button', onclick: () => { menu.hidden = true; fn(); } },
        h('span', { 'aria-hidden': 'true' }, icon), label);
    menu.replaceChildren(
        h('div.who', h('b', S.user.name), h('small', S.user.email)),
        item('📑', 'הארגונים שלי', () => (location.hash = '#/')),
        item('⚙️', 'הגדרות משתמש', () => (location.hash = '#/me')),
        S.user.is_developer ? item('🛠️', 'אזור הפיתוח', () => (location.hash = '#/dev')) : null,
        item('🚪', 'יציאה', logout),
    );
    menu.hidden = false;
});
document.addEventListener('click', e => { if (!menu.hidden && !menu.contains(e.target)) menu.hidden = true; });

async function logout() {
    try { await api('logout', {}); } catch { /* ממילא יוצאים */ }
    S.user = null; S.csrf = '';
    location.hash = '#/';
    render();
}

// ── ניתוב ───────────────────────────────────────────────────

async function boot() {
    try {
        const r = await api('me');
        S.user = r.user; S.csrf = r.csrf || '';
    } catch (err) {
        main.replaceChildren(h('p.error', { role: 'alert' }, err.message));
        return;
    }
    render();
}

function render() {
    menu.hidden = true;
    const hash = location.hash;
    const meBtn = $('#me-btn');
    meBtn.hidden = !S.user;
    if (S.user) { meBtn.textContent = initials(S.user.name); meBtn.title = S.user.name; }

    // קישורים מהמייל — גם כשמחוברים (איפוס מנתק ממילא)
    let m;
    if ((m = hash.match(/^#verify=([0-9a-f]{48})$/))) return viewVerify(m[1]);
    if ((m = hash.match(/^#reset=([0-9a-f]{48})$/))) return viewReset(m[1]);

    if (!S.user) return viewAuth();
    if (hash === '#/me') return viewMe();
    if (hash === '#/dev' && S.user.is_developer) return viewDev();
    return viewHome();
}
window.addEventListener('hashchange', render);

// ── כניסה והרשמה ────────────────────────────────────────────

function brand(sub) {
    return h('div.brand', h('img', { src: 'assets/icon.svg', alt: '' }),
        h('div', h('h2', 'הצעות תקציב לארגונים'), h('p', sub)));
}

function viewAuth(mode = sessionStorage.getItem('ob-auth') || 'login') {
    setHeader('הצעות תקציב');
    const err = h('p.error', { role: 'alert', hidden: true });
    const done = h('p.ok-msg', { role: 'status', hidden: true });
    const seg = h('fieldset.seg', { 'aria-label': 'כניסה או הרשמה' },
        ...[['login', 'כניסה'], ['register', 'הרשמה']].map(([v, l]) =>
            h('label', h('input', { type: 'radio', name: 'mode', value: v, checked: mode === v,
                onchange: () => { sessionStorage.setItem('ob-auth', v); viewAuth(v); } }), h('span', l))));

    let form;
    if (mode === 'forgot') {
        const btn = h('button.btn.primary', { type: 'submit' }, 'שליחת קישור לאיפוס');
        form = h('form.panel', { novalidate: true },
            h('p.hint', 'נשלח קישור לקביעת סיסמה חדשה לכתובת המייל שלך.'),
            h('label.f', h('span', 'מייל'), h('input', { name: 'email', type: 'email', dir: 'ltr', autocomplete: 'email', required: true })),
            err, done, btn,
            h('button.link', { type: 'button', onclick: () => viewAuth('login') }, 'חזרה לכניסה'));
        form.addEventListener('submit', busy(btn, async () => {
            const r = await api('forgot', formData(form));
            done.textContent = r.message; done.hidden = false;
        }, err));
    } else if (mode === 'register') {
        const btn = h('button.btn.primary', { type: 'submit' }, 'הרשמה');
        form = h('form.panel', { novalidate: true },
            h('label.f', h('span', 'שם מלא'), h('input', { name: 'name', autocomplete: 'name', required: true, maxLength: 60 })),
            h('label.f', h('span', 'מייל'), h('input', { name: 'email', type: 'email', dir: 'ltr', autocomplete: 'email', required: true })),
            pwField('סיסמה (לפחות 8 תווים)', 'password', 'new-password'),
            h('p.hint', 'אחרי ההרשמה יישלח מייל לאימות הכתובת, והחשבון ימתין לאישור.'),
            err, done, btn);
        form.addEventListener('submit', busy(btn, async () => {
            const r = await api('register', formData(form));
            done.textContent = r.message; done.hidden = false; form.reset();
        }, err));
    } else {
        const btn = h('button.btn.primary', { type: 'submit' }, 'כניסה');
        form = h('form.panel', { novalidate: true },
            h('label.f', h('span', 'מייל'), h('input', { name: 'email', type: 'email', dir: 'ltr', autocomplete: 'username', required: true })),
            pwField('סיסמה', 'password', 'current-password'),
            err, btn,
            h('button.link', { type: 'button', onclick: () => viewAuth('forgot') }, 'שכחתי סיסמה'));
        form.addEventListener('submit', busy(btn, async () => {
            await api('login', formData(form));
            await boot();
        }, err));
    }
    main.replaceChildren(h('div.auth', brand('קופות, הקדשות, ריכוז ונספח — במסמך אחד'), mode === 'forgot' ? null : seg, form));
}

async function viewVerify(token) {
    setHeader('אימות מייל');
    main.replaceChildren(h('div.auth', brand('אימות כתובת המייל'), h('p.empty', 'מאמת…')));
    let box;
    try {
        const r = await api('verify', { token });
        box = h('p.ok-msg', r.message);
    } catch (err) {
        box = h('p.error', { role: 'alert' }, err.message);
    }
    history.replaceState(null, '', location.pathname);
    main.replaceChildren(h('div.auth', brand('אימות כתובת המייל'), box,
        h('button.btn.primary', { type: 'button', onclick: () => render() }, S.user ? 'המשך' : 'לכניסה')));
}

function viewReset(token) {
    setHeader('סיסמה חדשה');
    const err = h('p.error', { role: 'alert', hidden: true });
    const btn = h('button.btn.primary', { type: 'submit' }, 'שמירת הסיסמה');
    const form = h('form.panel', { novalidate: true },
        pwField('סיסמה חדשה (לפחות 8 תווים)', 'password', 'new-password'),
        err, btn);
    form.addEventListener('submit', busy(btn, async () => {
        const r = await api('reset', { token, password: formData(form).password });
        history.replaceState(null, '', location.pathname);
        S.user = null; S.csrf = '';
        main.replaceChildren(h('div.auth', brand('סיסמה חדשה'), h('p.ok-msg', r.message),
            h('button.btn.primary', { type: 'button', onclick: () => viewAuth('login') }, 'לכניסה')));
    }, err));
    main.replaceChildren(h('div.auth', brand('קביעת סיסמה חדשה'), form));
}

// ── הבית ────────────────────────────────────────────────────

function viewHome() {
    setHeader('הצעות תקציב', S.user.name);
    const steps = [
        ['חשבונות ואזור פיתוח', true],
        ['ארגונים, תת-ארגון, חברים, קופות, גורמים וסעיפים', false],
        ['הצעת תקציב: שורות, חישוב חודשי, העברות, ריכוז, טיוטות והערות', false],
        ['שכירויות ונספח, שנה קודמת וניצול, קליטת 2025–2026', false],
        ['פלט במבנה המדויק: Word, PDF ואקסל', false],
    ];
    main.replaceChildren(
        h('p.sec-title', 'הארגונים שלי'),
        h('p.empty', 'הארגונים וההצעות ייפתחו כאן בשלב הבא.'),
        h('p.sec-title', 'מה נבנה'),
        h('ol.steps.list', ...steps.map(([t, done]) => h('li', { className: done ? 'done' : '' }, t))),
    );
}

// ── הגדרות משתמש ────────────────────────────────────────────

async function viewMe() {
    setHeader('הגדרות משתמש', '', true);
    main.replaceChildren(h('p.empty', 'טוען…'));
    const info = await api('me_info').catch(err => (toast(err.message, true), null));
    if (!info) return;

    const nameErr = h('p.error', { role: 'alert', hidden: true });
    const nameBtn = h('button.btn.primary', { type: 'submit' }, 'שמירה');
    const nameForm = h('form.panel', { novalidate: true },
        h('div.fields', h('label.f.wide', h('span', 'שם מלא'), h('input', { name: 'name', value: info.name, maxLength: 60, autocomplete: 'name' }))),
        nameErr, nameBtn);
    nameForm.addEventListener('submit', busy(nameBtn, async () => {
        const r = await api('me_update', formData(nameForm));
        S.user = r.user; render(); toast('השם נשמר');
    }, nameErr));

    const pwErr = h('p.error', { role: 'alert', hidden: true });
    const pwBtn = h('button.btn.primary', { type: 'submit' }, 'החלפת סיסמה');
    const pwForm = h('form.panel', { novalidate: true },
        h('div.fields',
            pwField('סיסמה נוכחית', 'current', 'current-password'),
            pwField('סיסמה חדשה', 'new', 'new-password')),
        h('p.hint', 'החלפת הסיסמה מנתקת את שאר המכשירים.'),
        pwErr, pwBtn);
    pwForm.addEventListener('submit', busy(pwBtn, async () => {
        const r = await api('me_password', formData(pwForm));
        pwForm.reset(); toast(r.other_sessions ? `הסיסמה הוחלפה · ${r.other_sessions} מכשירים נותקו` : 'הסיסמה הוחלפה');
    }, pwErr));

    const outBtn = h('button.btn.ghost', { type: 'button' }, 'ניתוק כל המכשירים האחרים');
    outBtn.addEventListener('click', busy(outBtn, async () => {
        const r = await api('me_logout_others', {});
        toast(`${r.count} מכשירים נותקו`); viewMe();
    }));

    main.replaceChildren(
        h('dl.cells',
            h('div', h('dt', 'מייל'), h('dd.one', { dir: 'ltr' }, info.email)),
            h('div', h('dt', 'נרשם'), h('dd', fmtDate(info.created_at))),
            h('div', h('dt', 'מכשירים מחוברים'), h('dd', info.devices)),
            info.is_developer ? h('div', h('dt', 'תפקיד'), h('dd', '🛠️ מפתח')) : null),
        h('p.sec-title', 'שם'), nameForm,
        h('p.sec-title', 'סיסמה'), pwForm,
        h('p.sec-title', 'מכשירים'), h('div.row-btns', outBtn),
    );
}

// ── אזור הפיתוח ─────────────────────────────────────────────

const DEV_TABS = [['users', '👥 משתמשים'], ['log', '🪵 לוג'], ['audit', '🛡️ אכיפה'], ['health', '🩺 מצב']];

async function viewDev() {
    setHeader('אזור הפיתוח', 'למפתח בלבד', true);
    const box = h('div');
    const tabs = h('div.seg-tabs', { role: 'tablist' }, ...DEV_TABS.map(([k, l]) =>
        h('button', { role: 'tab', type: 'button', 'aria-selected': String(S.devTab === k), dataset: { tab: k },
            onclick: () => { S.devTab = k; sessionStorage.setItem('ob-dev-tab', k); viewDev(); } }, l, h('small'))));
    main.replaceChildren(tabs, box);
    box.replaceChildren(h('p.empty', 'טוען…'));
    try {
        await ({ users: devUsers, log: devLog, audit: devAudit, health: devHealth })[S.devTab](box, tabs);
    } catch (err) {
        box.replaceChildren(h('p.error', { role: 'alert' }, err.message));
    }
}

const STATUS = {
    active: ['פעיל', 'ok'], pending: ['ממתין לאישור', 'warn'], unverified: ['לא אימת מייל', ''],
    blocked: ['חסום', 'bad'],
};

async function devUsers(box, tabs) {
    const { users } = await api('dev_users');
    const pending = users.filter(u => u.status === 'pending').length;
    tabs.querySelector('[data-tab="users"] small').textContent = pending || '';
    const list = h('ul.list', ...users.map(u => {
        const [label, cls] = STATUS[u.status] || [u.status, ''];
        return h('li.card', { tabindex: 0, role: 'button', onclick: () => devUserSheet(u), onkeydown: e => e.key === 'Enter' && devUserSheet(u) },
            h('span.avatar', { style: `--h:${hue(u.email)}`, 'aria-hidden': 'true' }, initials(u.display_name)),
            h('div.body',
                h('div.l1', h('span', u.display_name, u.is_developer ? ' · 🛠️' : ''), u.online ? h('span.dot', { title: 'מחובר עכשיו' }) : null),
                h('div.l2', { dir: 'ltr', style: 'text-align:end' }, u.email)),
            h(`span.chip.${cls || 'x'}`, label));
    }));
    box.replaceChildren(
        h('p.hint', 'ממתינים — למעלה. לחיצה על משתמש: פרטים ופעולות. כל פעולה נרשמת ביומן האכיפה.'),
        users.length ? list : h('p.empty', 'אין עדיין משתמשים'));
}

/** פעולות לכל מצב, עם "סיבה" חובה כשהפעולה פוגעת במשתמש (כמו בשרת). */
const USER_OPS = {
    approve: { label: '✓ אישור', states: ['pending'], primary: true },
    reject: { label: '✕ דחייה', states: ['pending', 'unverified'], reason: true, danger: true },
    block: { label: '⛔ חסימה', states: ['active', 'pending'], reason: true, danger: true },
    unblock: { label: 'ביטול חסימה', states: ['blocked'] },
    logout: { label: '🔌 ניתוק מכל המכשירים', states: ['active', 'pending', 'blocked'], reason: true },
    reset: { label: '✉️ שליחת קישור איפוס', states: ['active', 'pending'] },
};

function devUserSheet(u) {
    const self = u.id === S.user.id;
    const [label] = STATUS[u.status] || [u.status];
    const err = h('p.error', { role: 'alert', hidden: true });
    const reason = h('input', { name: 'reason', maxLength: 300, placeholder: 'למשל: לא מוכר לי' });
    const ops = self ? [] : Object.entries(USER_OPS).filter(([, d]) => d.states.includes(u.status));
    const buttons = ops.map(([op, d]) => {
        const b = h(`button.btn.${d.primary ? 'primary' : 'ghost'}${d.danger ? '.danger' : ''}`, { type: 'button' }, d.label);
        b.addEventListener('click', busy(b, async () => {
            if (d.reason && reason.value.trim().length < 3) { reason.focus(); throw new Error('חובה לכתוב סיבה — היא נשמרת ביומן האכיפה'); }
            const r = await api('dev_user', { user_id: u.id, op, reason: reason.value.trim() });
            sheet.close(); toast(r.message); viewDev();
        }, err));
        return b;
    });
    openSheet(u.display_name, [
        h('dl.kv',
            h('dt', 'מייל'), h('dd', { dir: 'ltr', style: 'text-align:end' }, u.email),
            h('dt', 'מצב'), h('dd', label, u.is_developer ? ' · 🛠️ מפתח' : ''),
            h('dt', 'נרשם'), h('dd', fmtDate(u.created_at)),
            h('dt', 'כניסה אחרונה'), h('dd', fmtDate(u.last_login_at)),
            h('dt', 'נראה לאחרונה'), h('dd', fmtDate(u.last_seen_at), u.online ? ' · מחובר עכשיו' : ''),
            h('dt', 'מכשירים'), h('dd', u.devices)),
        self ? h('p.hint', 'זה החשבון שלך — אין עליו פעולות אכיפה.') : null,
        ops.some(([, d]) => d.reason) ? h('label.f', h('span', 'סיבה (חובה בדחייה, חסימה וניתוק)'), reason) : null,
        err,
        buttons.length ? h('div.row-btns.wrap', ...buttons) : null,
    ]);
}

async function devLog(box) {
    const errorsOnly = sessionStorage.getItem('ob-log-err') === '1';
    const r = await api('dev_log_list', null, `&nodev=1${errorsOnly ? '&errors=1' : ''}`);
    const toggle = h('label.switch', h('input', { type: 'checkbox', checked: errorsOnly,
        onchange: e => { sessionStorage.setItem('ob-log-err', e.target.checked ? '1' : '0'); viewDev(); } }), h('span', 'רק שגיאות'));
    const d = r.day;
    const rows = r.rows.map(x => {
        const cls = x.action === 'client_error' || x.status >= 500 ? 'bad' : x.status >= 400 ? 'warn' : 'ok';
        const detail = [x.message, x.trace, x.ref, x.page && `page: ${x.page}`, `req: ${x.req} · ip: ${x.ip}`].filter(Boolean).join('\n');
        const pre = h('pre', { hidden: true }, detail);
        return h('div.log-row', { tabindex: 0, role: 'button', onclick: () => { pre.hidden = !pre.hidden; } },
            h('div.l1', h(`span.chip.${cls}`, x.action === 'client_error' ? 'JS' : x.status), h('b', x.action), h('span.t', fmtTime(x.at))),
            h('div.l2', x.user || 'אורח', h('i.sep', { 'aria-hidden': 'true' }, '·'), `${x.ms}ms`, x.message ? [h('i.sep', '·'), x.message] : null),
            pre);
    });
    box.replaceChildren(
        h('div.stat-line',
            h('span.chip', `יממה: ${d.n} בקשות`), h('span.chip.bad', `${d.e5} שגיאות שרת`),
            h('span.chip.warn', `${d.e4} סירובים`), h('span.chip.bad', `${d.js} JS`), h('span.chip', `ממוצע ${d.avg_ms}ms`)),
        h('div.toolbar', toggle, h('button.icon-btn', { type: 'button', 'aria-label': 'רענון', onclick: viewDev }, '↻')),
        rows.length ? h('div.list', ...rows) : h('p.empty', 'אין רשומות'));
}

async function devAudit(box) {
    const { audit } = await api('dev_audit_list');
    box.replaceChildren(
        h('p.hint', 'כל פעולת מפתח — מי, מתי, על מה ולמה. אי אפשר למחוק מהיומן הזה.'),
        audit.length ? h('ul.list', ...audit.map(a => h('li.card',
            h('div.body',
                h('div.l1', h('span', a.label, a.detail?.email ? ` · ${a.detail.email}` : ''), h('span.t', fmtDate(a.at))),
                h('div.l2', a.by, a.reason ? [h('i.sep', '·'), a.reason] : null))))) : h('p.empty', 'עוד אין פעולות'));
}

async function devHealth(box) {
    const { health: x } = await api('dev_health');
    const yes = v => (v ? '✓' : '✕');
    box.replaceChildren(
        h('dl.cells',
            h('div', h('dt', 'משתמשים פעילים'), h('dd', x.counts.users)),
            h('div', h('dt', 'ממתינים'), h('dd', x.counts.pending)),
            h('div', h('dt', 'בקשות ביממה'), h('dd', x.day.requests)),
            h('div', h('dt', 'שגיאות שרת ביממה'), h('dd', x.day.errors)),
            h('div', h('dt', 'שגיאות JS ביממה'), h('dd', x.day.js_errors)),
            h('div', h('dt', 'מסד'), h('dd', `${x.db_mb}MB`)),
            h('div', h('dt', 'מקום פנוי'), h('dd', x.disk_free_mb === null ? '—' : `${x.disk_free_mb}MB`)),
            h('div', h('dt', 'PHP'), h('dd', { dir: 'ltr', style: 'text-align:end' }, x.php)),
            h('div', h('dt', 'SQLite'), h('dd', { dir: 'ltr', style: 'text-align:end' }, x.sqlite)),
            h('div', h('dt', 'data חסומה לדפדפן'), h('dd', yes(x.data_protected))),
            h('div', h('dt', 'data ניתנת לכתיבה'), h('dd', yes(x.data_writable))),
            h('div', h('dt', 'שליחת מייל'), h('dd', yes(x.mail))),
            h('div', h('dt', 'HTTPS'), h('dd', yes(x.https)))));
}

// ── התחלה ───────────────────────────────────────────────────

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
boot();
