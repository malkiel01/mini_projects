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

function setHeader(title, sub = '', back = false, actions = []) {
    $('#head-actions').replaceChildren(...actions.filter(Boolean));
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
    if ((m = hash.match(/^#\/org\/(\d+)$/))) return viewOrg(+m[1]);
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

async function viewHome() {
    setHeader('הצעות תקציב', S.user.name);
    main.replaceChildren(h('p.empty', 'טוען…'));
    const { orgs } = await api('org_list').catch(err => (toast(err.message, true), { orgs: [] }));
    const add = h('button.btn.primary', { type: 'button', onclick: () => orgSheet() }, h('span', { 'aria-hidden': 'true' }, '+'), ' ארגון');
    main.replaceChildren(
        h('div.toolbar', h('p.sec-title', { style: 'flex:1;margin:0' }, 'הארגונים שלי'), add),
        orgs.length
            ? h('ul.list', ...orgs.map(o => h('li', h('button.use-row', { type: 'button', onclick: () => (location.hash = `#/org/${o.id}`) },
                h('span.use-ic', { 'aria-hidden': 'true' }, '🏛️'),
                h('span.use-tx', h('b.one', o.name),
                    h('small', [ROLE_LABEL[o.role], `${o.funds} קופות`, o.subs ? `${o.subs} תתי-ארגונים` : null, `${o.members} חברים`].filter(Boolean).join(' · '))),
                h('span.use-go', { 'aria-hidden': 'true' }, '‹')))))
            : h('p.empty', 'עוד אין ארגונים. "+ ארגון" כדי לפתוח את הראשון — או בקש מבעלים של ארגון להוסיף אותך.'),
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

const DEV_TABS = [['users', '👥 משתמשים'], ['orgs', '🏛️ ארגונים'], ['log', '🪵 לוג'], ['audit', '🛡️ אכיפה'], ['health', '🩺 מצב']];

async function viewDev() {
    setHeader('אזור הפיתוח', 'למפתח בלבד', true);
    const box = h('div');
    const tabs = h('div.seg-tabs', { role: 'tablist' }, ...DEV_TABS.map(([k, l]) =>
        h('button', { role: 'tab', type: 'button', 'aria-selected': String(S.devTab === k), dataset: { tab: k },
            onclick: () => { S.devTab = k; sessionStorage.setItem('ob-dev-tab', k); viewDev(); } }, l, h('small'))));
    main.replaceChildren(tabs, box);
    box.replaceChildren(h('p.empty', 'טוען…'));
    try {
        await ({ users: devUsers, orgs: devOrgs, log: devLog, audit: devAudit, health: devHealth })[S.devTab](box, tabs);
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
                h('div.l1', h('span', a.label, a.detail?.email || a.detail?.name ? ` · ${a.detail.email || a.detail.name}` : ''), h('span.t', fmtDate(a.at))),
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

// ── ארגונים (שלב 2) ─────────────────────────────────────────

const ROLE_LABEL = { owner: 'בעלים', editor: 'עורך', viewer: 'צופה' };
const ROLE_HINT = { owner: 'הכול, כולל חברים ומחיקה', editor: 'בונה הצעות, קופות וגורמים', viewer: 'קריאה, הורדה והערות' };
const FUND_IC = { operating: '🏢', hekdesh: '🕍', secular: '🏠', fund: '💰' };
const CONTACT_IC = { supplier: '🧾', customer: '🤝', both: '🔁' };
const canEdit = role => role === 'owner' || role === 'editor';

/** בורר מקטעים: [[ערך, תווית], …] */
function seg(name, options, value, label) {
    return h('fieldset.seg', { 'aria-label': label }, ...options.map(([v, l]) =>
        h('label', h('input', { type: 'radio', name, value: v, checked: v === value }), h('span', l))));
}

/** טופס בתוך גיליון: כפתור "שמירה" בכותרת, שגיאה בגוף. onSave מקבל את ערכי הטופס. */
function formSheet(title, fields, onSave, extra = []) {
    const err = h('p.error', { role: 'alert', hidden: true });
    const save = h('button.btn.primary', { type: 'button' }, 'שמירה');
    const body = h('div', { style: 'display:grid;gap:12px' }, ...fields, err, ...extra);
    save.addEventListener('click', busy(save, async () => {
        const data = Object.fromEntries(new FormData(sheet.querySelector('form')).entries());
        await onSave(data);
        sheet.close();
    }, err));
    openSheet(title, body, save);
    sheet.querySelector('input:not([type=radio]), textarea')?.focus();
}

const field = (label, name, value = '', opts = {}) =>
    h(`label.f${opts.wide ? '.wide' : ''}`, h('span', label),
        opts.area ? h('textarea', { name, rows: 3, maxLength: opts.max || 2000 }, value || '')
            : h('input', { name, value: value || '', maxLength: opts.max || 160, ...(opts.attrs || {}) }));

/** פתיחת ארגון חדש, תת-ארגון (parent), או עריכת פרטים (org). */
function orgSheet(org = null, parent = null) {
    formSheet(org ? 'פרטי הארגון' : parent ? `תת-ארגון ב${parent.name}` : 'ארגון חדש', [
        h('div.fields',
            field('שם *', 'name', org?.name, { wide: true, max: 120 }),
            field('תת-כותרת (מופיעה בשער)', 'subtitle', org?.subtitle, { wide: true })),
        org ? field('הערות', 'notes', org.notes, { area: true }) : null,
        !org && !parent ? h('p.hint', 'אתה תהיה הבעלים. תיפתח קופה "ארגון שוטף"; הקדשות וקרנות מוסיפים אחר כך.') : null,
    ].filter(Boolean), async d => {
        if (org) { await api('org_update', { org_id: org.id, ...d }); toast('נשמר'); viewOrg(org.id); return; }
        const r = await api('org_create', { ...d, parent_id: parent?.id || null });
        location.hash = `#/org/${r.id}`;
    });
}

async function viewOrg(id) {
    main.replaceChildren(h('p.empty', 'טוען…'));
    let d;
    try { d = await api('org_get', null, `&id=${id}`); } catch (err) {
        setHeader('ארגון', '', true);
        main.replaceChildren(h('p.error', { role: 'alert' }, err.message), h('button.btn.ghost', { type: 'button', onclick: () => (location.hash = '#/') }, 'לארגונים שלי'));
        return;
    }
    const o = d.org, edit = canEdit(o.role), root = !o.parent;
    S.org = d;
    setHeader(o.name, o.parent ? `תת-ארגון · ${o.parent.name}` : `${ROLE_LABEL[o.role]}${o.subtitle ? ' · ' + o.subtitle : ''}`, true, [
        h('button.icon-btn', { type: 'button', 'aria-label': 'היסטוריה', title: 'היסטוריה', onclick: () => orgHistory(o) }, '🕓'),
        edit ? h('button.icon-btn', { type: 'button', 'aria-label': 'פרטי הארגון', title: 'פרטי הארגון', onclick: () => orgSettings(d) }, '✏️') : null,
    ]);

    const tabs = [
        ['funds', '🏦 קופות', d.funds.length],
        root ? ['subs', '🏛️ תתי-ארגונים', d.subs.length] : null,
        ['contacts', '👥 גורמים', d.contacts.length],
        ['cats', '🏷️ סעיפים', d.categories.length],
        root ? ['members', '🔐 חברים', d.members.length] : null,
    ].filter(Boolean);
    const key = `ob-org-tab-${id}`;
    let cur = sessionStorage.getItem(key);
    if (!tabs.some(t => t[0] === cur)) cur = 'funds';
    const box = h('div');
    const bar = h('div.seg-tabs', { role: 'tablist', style: `grid-template-columns:repeat(${tabs.length},minmax(0,1fr))` }, ...tabs.map(([k, l, n]) =>
        h('button', { role: 'tab', type: 'button', 'aria-selected': String(k === cur),
            onclick: () => { sessionStorage.setItem(key, k); viewOrg(id); } }, l, h('small', n || ''))));
    main.replaceChildren(bar, box);
    ({ funds: orgFunds, subs: orgSubs, contacts: orgContacts, cats: orgCats, members: orgMembers })[cur](box, d);
}

function orgSettings(d) {
    const o = d.org;
    const del = h('button.btn.ghost.danger', { type: 'button' }, o.parent ? '🗑️ מחיקת תת-הארגון' : '🗑️ מחיקת הארגון');
    del.addEventListener('click', () => {
        if (!o.parent && o.role !== 'owner') return toast('רק בעלים מוחק ארגון', true);
        formSheet(o.parent ? 'מחיקת תת-ארגון' : 'מחיקת ארגון', [
            h('p.error', o.parent ? 'תת-הארגון וכל הקופות שלו יימחקו.' : 'הארגון, תתי-הארגונים וכל הקופות יימחקו לכל החברים.'),
            field(`לאישור — הקלידו: ${o.name}`, 'confirm', '', { wide: true, max: 120 }),
        ], async x => {
            await api('org_delete', { org_id: o.id, confirm: x.confirm });
            toast('נמחק');
            location.hash = o.parent ? `#/org/${o.parent.id}` : '#/';
        });
    });
    orgSheet(o);
    $('#sheet-body').append(h('section.group', h('h3', 'אזור מסוכן'), del));
}

async function orgHistory(o) {
    openSheet('היסטוריה', h('p.empty', 'טוען…'));
    try {
        const { events } = await api('org_history', null, `&id=${o.id}`);
        const what = e => {
            const x = e.detail || {};
            return [x.name, x.role && ROLE_LABEL[x.role], x.from && `היה ${ROLE_LABEL[x.from]}`, x.org && x.org !== o.name ? x.org : null, x.op, x.reason].filter(Boolean).join(' · ');
        };
        $('#sheet-body').replaceChildren(events.length ? h('ul.list', ...events.map(e => h('li.card',
            h('div.body', h('div.l1', h('span', e.label), h('span.t', fmtDate(e.at))),
                h('div.l2', e.by || 'מערכת', what(e) ? [h('i.sep', '·'), what(e)] : null))))) : h('p.empty', 'אין עדיין שינויים'));
    } catch (err) { $('#sheet-body').replaceChildren(h('p.error', err.message)); }
}

// ── קופות ──

function orgFunds(box, d) {
    const o = d.org, edit = canEdit(o.role);
    const sorting = sessionStorage.getItem('ob-fund-sort') === '1' && edit;
    const move = async (i, dir) => {
        const ids = d.funds.map(f => f.id);
        [ids[i], ids[i + dir]] = [ids[i + dir], ids[i]];
        await api('fund_order', { org_id: o.id, ids }).catch(err => toast(err.message, true));
        viewOrg(o.id);
    };
    box.replaceChildren(
        h('p.hint', 'כל קופה מאוזנת בפני עצמה — הכנסות מול הוצאות. הסדר כאן הוא סדר הדפים במסמך.'),
        edit ? h('div.toolbar', { style: 'justify-content:flex-end' },
            d.funds.length > 1 ? h('button.btn.ghost', { type: 'button', 'aria-pressed': String(sorting),
                onclick: () => { sessionStorage.setItem('ob-fund-sort', sorting ? '0' : '1'); viewOrg(o.id); } }, sorting ? '✓ סיום סידור' : '↕️ סידור') : null,
            h('button.btn.primary', { type: 'button', onclick: () => fundSheet(d) }, h('span', { 'aria-hidden': 'true' }, '+'), ' קופה')) : null,
        d.funds.length ? h('ul.list', ...d.funds.map((f, i) => h('li', { style: 'display:flex;align-items:center' },
            h('button.use-row', { type: 'button', onclick: () => fundSheet(d, f) },
                h('span.use-ic', { 'aria-hidden': 'true' }, FUND_IC[f.kind]),
                h('span.use-tx', h('b.one', f.name), h('small', [d.labels.funds[f.kind], f.address].filter(Boolean).join(' · '))),
                sorting ? null : h('span.use-go', { 'aria-hidden': 'true' }, '‹')),
            sorting ? h('span.row-btns', { style: 'gap:0;padding-inline-end:4px' },
                h('button.icon-btn', { type: 'button', 'aria-label': 'למעלה', disabled: i === 0, onclick: () => move(i, -1) }, '▲'),
                h('button.icon-btn', { type: 'button', 'aria-label': 'למטה', disabled: i === d.funds.length - 1, onclick: () => move(i, 1) }, '▼')) : null)))
            : h('p.empty', 'אין קופות'));
}

function fundSheet(d, f = null) {
    const o = d.org, edit = canEdit(o.role);
    const kinds = Object.entries(d.labels.funds);
    const fields = [
        seg('kind', kinds, f?.kind || (d.funds.length ? 'hekdesh' : 'operating'), 'סוג הקופה'),
        h('div.fields',
            field('שם *', 'name', f?.name, { wide: true, max: 120 }),
            field('כתובת (מופיעה מתחת לשם במסמך)', 'address', f?.address, { wide: true }),
            field('חשבון בנק', 'bank', f?.bank, { wide: true })),
        field('הערות', 'notes', f?.notes, { area: true }),
    ];
    if (!edit) {
        openSheet(f.name, h('dl.kv', h('dt', 'סוג'), h('dd', d.labels.funds[f.kind]), h('dt', 'כתובת'), h('dd', f.address || '—'),
            h('dt', 'חשבון בנק'), h('dd', f.bank || '—'), h('dt', 'הערות'), h('dd', f.notes || '—')));
        return;
    }
    const extra = [];
    if (f) {
        const rm = h('button.btn.ghost.danger', { type: 'button' }, '🗑️ הסרת הקופה');
        rm.addEventListener('click', busy(rm, async () => {
            if (!confirm(`להסיר את "${f.name}"?`)) return;
            await api('fund_archive', { org_id: o.id, id: f.id }); sheet.close(); toast('הקופה הוסרה'); viewOrg(o.id);
        }));
        extra.push(h('div.row-btns', rm));
    }
    formSheet(f ? 'עריכת קופה' : 'קופה חדשה', fields, async x => {
        await api('fund_save', { org_id: o.id, id: f?.id || null, ...x });
        toast('נשמר'); viewOrg(o.id);
    }, extra);
}

// ── תתי-ארגונים ──

function orgSubs(box, d) {
    const o = d.org;
    box.replaceChildren(
        h('p.hint', 'יחידה בתוך הארגון עם קופות ומסמך הצעה משלה (למשל חברה קדישא). החברים, הגורמים והסעיפים — משותפים.'),
        canEdit(o.role) ? h('div.toolbar', { style: 'justify-content:flex-end' },
            h('button.btn.primary', { type: 'button', onclick: () => orgSheet(null, o) }, h('span', { 'aria-hidden': 'true' }, '+'), ' תת-ארגון')) : null,
        d.subs.length ? h('ul.list', ...d.subs.map(x => h('li', h('button.use-row', { type: 'button', onclick: () => (location.hash = `#/org/${x.id}`) },
            h('span.use-ic', { 'aria-hidden': 'true' }, '🏛️'),
            h('span.use-tx', h('b.one', x.name), h('small', [x.subtitle, `${x.funds} קופות`].filter(Boolean).join(' · '))),
            h('span.use-go', { 'aria-hidden': 'true' }, '‹')))))
            : h('p.empty', 'אין תתי-ארגונים'));
}

// ── גורמים ──

function orgContacts(box, d) {
    const o = d.org, edit = canEdit(o.role);
    const filt = sessionStorage.getItem('ob-contact-filter') || 'all';
    const q = h('input', { type: 'search', placeholder: 'חיפוש', 'aria-label': 'חיפוש גורם' });
    const list = h('div');
    const draw = () => {
        const t = q.value.trim();
        const rows = d.contacts.filter(c => (filt === 'all' || c.kind === filt || c.kind === 'both')
            && (!t || [c.name, c.phone, c.email, c.tax_id].some(v => (v || '').includes(t))));
        list.replaceChildren(rows.length ? h('ul.list', ...rows.map(c => h('li', h('button.use-row', { type: 'button', onclick: () => contactSheet(d, c) },
            h('span.use-ic', { 'aria-hidden': 'true' }, CONTACT_IC[c.kind]),
            h('span.use-tx', h('b.one', c.name), h('small', [d.labels.contacts[c.kind], c.phone, c.email].filter(Boolean).join(' · '))),
            h('span.use-go', { 'aria-hidden': 'true' }, '‹')))))
            : h('p.empty', d.contacts.length ? 'לא נמצא' : 'עוד אין גורמים. שוכרים, ספקים ונותני שירות — כאן.'));
    };
    q.addEventListener('input', draw);
    const filter = seg('cf', [['all', 'הכול'], ['customer', 'לקוחות'], ['supplier', 'ספקים']], filt, 'סינון');
    filter.addEventListener('change', e => { sessionStorage.setItem('ob-contact-filter', e.target.value); viewOrg(o.id); });
    box.replaceChildren(
        h('p.hint', o.parent ? 'הגורמים משותפים לכל הארגון.' : 'לקוח — מי שמשלם לארגון (שוכר, תורם). ספק — מי שהארגון משלם לו.'),
        h('div.toolbar', q, edit ? h('button.btn.primary', { type: 'button', onclick: () => contactSheet(d) }, h('span', { 'aria-hidden': 'true' }, '+'), ' גורם') : null),
        h('div', { style: 'margin-bottom:8px' }, filter), list);
    draw();
}

function contactSheet(d, c = null) {
    const o = d.org;
    if (!canEdit(o.role)) {
        openSheet(c.name, h('dl.kv', h('dt', 'סוג'), h('dd', d.labels.contacts[c.kind]), h('dt', 'טלפון'), h('dd', { dir: 'ltr', style: 'text-align:end' }, c.phone || '—'),
            h('dt', 'מייל'), h('dd', { dir: 'ltr', style: 'text-align:end' }, c.email || '—'), h('dt', 'ח"פ / ת"ז'), h('dd', c.tax_id || '—'), h('dt', 'הערות'), h('dd', c.notes || '—')));
        return;
    }
    const extra = [];
    if (c) {
        const rm = h('button.btn.ghost.danger', { type: 'button' }, '🗑️ הסרת הגורם');
        rm.addEventListener('click', busy(rm, async () => {
            if (!confirm(`להסיר את "${c.name}"?`)) return;
            await api('contact_archive', { org_id: o.id, id: c.id }); sheet.close(); toast('הוסר'); viewOrg(o.id);
        }));
        extra.push(h('div.row-btns', rm));
    }
    formSheet(c ? 'עריכת גורם' : 'גורם חדש', [
        seg('kind', Object.entries(d.labels.contacts), c?.kind || 'customer', 'סוג'),
        h('div.fields',
            field('שם *', 'name', c?.name, { wide: true, max: 120 }),
            field('טלפון', 'phone', c?.phone, { max: 40, attrs: { type: 'tel', dir: 'ltr', inputMode: 'tel' } }),
            field('מייל', 'email', c?.email, { max: 190, attrs: { type: 'email', dir: 'ltr' } }),
            field('ח"פ / ת"ז', 'tax_id', c?.tax_id, { max: 20, attrs: { dir: 'ltr', inputMode: 'numeric' } })),
        field('הערות', 'notes', c?.notes, { area: true }),
    ], async x => {
        await api('contact_save', { org_id: o.id, id: c?.id || null, ...x });
        toast('נשמר'); viewOrg(o.id);
    }, extra);
}

// ── סעיפים ──

function orgCats(box, d) {
    const o = d.org, edit = canEdit(o.role);
    const group = (kind, title) => {
        const rows = d.categories.filter(c => c.kind === kind);
        return [h('p.sec-title', title, h('span.muted', ` · ${rows.length}`)),
            rows.length ? h('ul.list', ...rows.map(c => h('li', h('button.use-row', { type: 'button', disabled: !edit, onclick: () => catSheet(d, c) },
                h('span.use-tx', h('b.one', c.name)), edit ? h('span.use-go', { 'aria-hidden': 'true' }, '✏️') : null))))
                : h('p.empty', 'אין עדיין')];
    };
    box.replaceChildren(
        h('p.hint', 'קטלוג שמות לשורות ההצעה (משרדיות, עמלות בנק, דמי שימוש…) — בוחרים מהם בבניית ההצעה. משותף לכל הארגון.'),
        edit ? h('div.toolbar', { style: 'justify-content:flex-end' },
            h('button.btn.primary', { type: 'button', onclick: () => catSheet(d) }, h('span', { 'aria-hidden': 'true' }, '+'), ' סעיף')) : null,
        ...group('expense', 'הוצאות'), ...group('income', 'הכנסות'));
}

function catSheet(d, c = null) {
    const o = d.org;
    const extra = [];
    if (c) {
        const rm = h('button.btn.ghost.danger', { type: 'button' }, '🗑️ הסרת הסעיף');
        rm.addEventListener('click', busy(rm, async () => {
            await api('cat_archive', { org_id: o.id, id: c.id }); sheet.close(); toast('הוסר'); viewOrg(o.id);
        }));
        extra.push(h('div.row-btns', rm));
    }
    formSheet(c ? 'עריכת סעיף' : 'סעיף חדש', [
        seg('kind', Object.entries(d.labels.categories), c?.kind || 'expense', 'סוג'),
        field('שם הסעיף *', 'name', c?.name, { wide: true, max: 120 }),
    ], async x => {
        await api('cat_save', { org_id: o.id, id: c?.id || null, ...x });
        toast('נשמר'); viewOrg(o.id);
    }, extra);
}

// ── חברים ──

function orgMembers(box, d) {
    const o = d.org, owner = o.role === 'owner';
    const me = d.members.find(m => m.me);
    const leave = h('button.btn.ghost.danger', { type: 'button' }, '🚪 עזיבת הארגון');
    leave.addEventListener('click', busy(leave, async () => {
        if (!confirm('לעזוב את הארגון? לא תוכל לראות אותו עד שיוסיפו אותך שוב.')) return;
        await api('member_remove', { org_id: o.id, user_id: me.user_id }); location.hash = '#/';
    }));
    box.replaceChildren(
        h('p.hint', 'בעלים — ' + ROLE_HINT.owner + '. עורך — ' + ROLE_HINT.editor + '. צופה — ' + ROLE_HINT.viewer + '.'),
        owner ? h('div.toolbar', { style: 'justify-content:flex-end' },
            h('button.btn.primary', { type: 'button', onclick: () => memberAdd(d) }, h('span', { 'aria-hidden': 'true' }, '+'), ' חבר')) : null,
        h('ul.list', ...d.members.map(m => h('li.card', { tabindex: owner ? 0 : -1, role: owner ? 'button' : null, onclick: () => owner && memberSheet(d, m) },
            h('span.avatar', { style: `--h:${hue(m.email)}`, 'aria-hidden': 'true' }, initials(m.name)),
            h('div.body', h('div.l1', h('span', m.name, m.me ? ' (אני)' : '')), h('div.l2', { dir: 'ltr', style: 'text-align:end' }, m.email)),
            h(`span.chip.${m.role === 'owner' ? 'ok' : 'x'}`, ROLE_LABEL[m.role])))),
        h('div.row-btns', { style: 'margin-top:12px' }, leave));
}

function memberAdd(d) {
    formSheet('הוספת חבר', [
        h('p.hint', 'לפי המייל שאיתו נרשם לאפליקציה. מי שעוד לא רשום — צריך להירשם ולקבל אישור קודם.'),
        field('מייל *', 'email', '', { wide: true, max: 190, attrs: { type: 'email', dir: 'ltr', autocomplete: 'off' } }),
        seg('role', [['viewer', 'צופה'], ['editor', 'עורך'], ['owner', 'בעלים']], 'editor', 'תפקיד'),
    ], async x => {
        const r = await api('member_add', { org_id: d.org.id, ...x });
        toast(r.message); viewOrg(d.org.id);
    });
}

function memberSheet(d, m) {
    const rm = h('button.btn.ghost.danger', { type: 'button' }, m.me ? '🚪 עזיבה' : '🗑️ הסרה מהארגון');
    rm.addEventListener('click', busy(rm, async () => {
        if (!confirm(`להסיר את ${m.name} מהארגון?`)) return;
        await api('member_remove', { org_id: d.org.id, user_id: m.user_id }); sheet.close();
        if (m.me) location.hash = '#/'; else { toast('הוסר'); viewOrg(d.org.id); }
    }));
    formSheet(m.name, [
        h('dl.kv', h('dt', 'מייל'), h('dd', { dir: 'ltr', style: 'text-align:end' }, m.email), h('dt', 'נוסף'), h('dd', fmtDate(m.added_at))),
        seg('role', [['viewer', 'צופה'], ['editor', 'עורך'], ['owner', 'בעלים']], m.role, 'תפקיד'),
    ], async x => {
        await api('member_set', { org_id: d.org.id, user_id: m.user_id, role: x.role });
        toast('התפקיד עודכן'); viewOrg(d.org.id);
    }, [h('div.row-btns', rm)]);
}

// ── אזור הפיתוח: ארגונים ──

async function devOrgs(box) {
    const { orgs } = await api('dev_orgs');
    box.replaceChildren(
        h('p.hint', 'מטא-דאטה בלבד: שם, יוצר, חברים. מחיקה ושחזור — עם סיבה, ונרשמים ביומן האכיפה ובהיסטוריה של הארגון.'),
        orgs.length ? h('ul.list', ...orgs.map(x => h('li.card', { tabindex: 0, role: 'button', onclick: () => devOrgSheet(x) },
            h('span.use-ic', { 'aria-hidden': 'true' }, '🏛️'),
            h('div.body', h('div.l1', h('span', x.name), h('span.t', `#${x.id}`)),
                h('div.l2', [x.creator, `${x.members} חברים`, x.subs ? `${x.subs} תתי-ארגונים` : null, fmtDate(x.created_at)].filter(Boolean).join(' · '))),
            x.deleted ? h('span.chip.bad', 'מחוק') : null))) : h('p.empty', 'אין ארגונים'));
}

function devOrgSheet(x) {
    const op = x.deleted ? 'restore' : 'delete';
    formSheet(`${x.name} · #${x.id}`, [
        h('dl.kv', h('dt', 'יוצר'), h('dd', x.creator), h('dt', 'נוצר'), h('dd', fmtDate(x.created_at)), h('dt', 'חברים'), h('dd', x.members),
            h('dt', 'מצב'), h('dd', x.deleted ? 'מחוק' : 'פעיל')),
        h('p.hint', x.deleted ? 'שחזור מחזיר את הארגון ואת תתי-הארגונים שנמחקו איתו.' : 'מחיקה רכה — אפשר לשחזר מכאן.'),
        field('סיבה *', 'reason', '', { wide: true, max: 300 }),
        field(`לאישור — הקלידו את המזהה ${x.id}`, 'confirm', '', { wide: true, max: 20, attrs: { dir: 'ltr', inputMode: 'numeric' } }),
    ], async f => {
        const r = await api('dev_org_force', { op, id: x.id, ...f });
        toast(r.message); viewDev();
    });
    $('#sheet-action').firstChild.textContent = x.deleted ? 'שחזור' : 'מחיקה';
}

// ── התחלה ───────────────────────────────────────────────────

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
boot();
