/*
 * הצעות תקציב — המעטפת בדפדפן.
 *
 * ניתוב ב-hash:  #/  הבית · #/me  הגדרות משתמש · #/dev  אזור הפיתוח (למפתח)
 * קישורים מהמייל: #verify=<אסימון> · #reset=<אסימון>
 *
 * כל טקסט שמגיע מהשרת מוצב כ-textContent (דרך h()), לעולם לא כ-HTML.
 */
'use strict';

const S = { user: null, csrf: '', platform: { state: 'off' }, devTab: sessionStorage.getItem('ob-dev-tab') || 'users' };
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
    for (const kid of kids.flat(Infinity)) {
        if (kid === null || kid === undefined || kid === false) continue;
        el.append(kid instanceof Node ? kid : String(kid));
    }
    return el;
}

/** כמו replaceChildren, אבל משטח מערכים ומדלג על null / false — כדי שתוכן מותנה לא יוצג כ-"null" */
function put(el, ...kids) {
    el.replaceChildren(...kids.flat(Infinity).filter(k => k !== null && k !== undefined && k !== false));
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
        if (res.status === 401 && S.user) { S.user = null; boot(); }   // boot — גם מצב הכניסה דרך הפלטפורמה
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
        S.platform.can_link ? item('🔗', 'חיבור חשבון ישן', () => viewLink(true)) : null,
        // מחוברת לפלטפורמה: היציאה היא מהכלים הדיגיטליים, והיא מוציאה גם מכאן
        connected() ? item('🧰', 'לכלים הדיגיטליים', () => (location.href = new URL('../', location.href).href))
                    : item('🚪', 'יציאה', logout),
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
        S.user = r.user; S.csrf = r.csrf || ''; S.platform = r.platform || { state: 'off' };
    } catch (err) {
        put(main, h('p.error', { role: 'alert' }, err.message));
        return;
    }
    render();
}

function render() {
    // יציאה מהעריכה כטבלה עם שינויים שלא נשמרו — שואלים; "ביטול" משאיר את הטבלה כמו שהיא
    if (window.onbeforeunload && S.gridHash && location.hash !== S.gridHash) {
        if (!confirm('יש שינויים שלא נשמרו בטבלה. לצאת בלי לשמור?')) { history.replaceState(null, '', S.gridHash); return; }
        window.onbeforeunload = null;
    }
    if (S.wpClose) S.wpClose(true);   // ניווט (גם "חזרה" באנדרואיד) כשהעורך פתוח — שומרים וסוגרים
    menu.hidden = true;
    document.body.classList.remove('print-mode', 'grid-mode');
    const hash = location.hash;
    const meBtn = $('#me-btn');
    meBtn.hidden = !S.user;
    if (S.user) { meBtn.textContent = initials(S.user.name); meBtn.title = S.user.name; }

    // קישורים מהמייל — גם כשמחוברים (איפוס מנתק ממילא)
    let m;
    if ((m = hash.match(/^#verify=([0-9a-f]{48})$/))) return viewVerify(m[1]);
    if ((m = hash.match(/^#reset=([0-9a-f]{48})$/))) return viewReset(m[1]);

    if (!S.user) return viewNoUser();
    if (hash === '#/me') return viewMe();
    if (hash === '#/dev' && S.user.is_developer) return viewDev();
    if ((m = hash.match(/^#\/org\/(\d+)$/))) return viewOrg(+m[1]);
    if ((m = hash.match(/^#\/budget\/(\d+)\/print$/))) return viewPrint(+m[1]);
    if ((m = hash.match(/^#\/budget\/(\d+)\/grid$/))) return viewGrid(+m[1]);
    if ((m = hash.match(/^#\/budget\/(\d+)(?:\/fund\/(\d+))?$/))) return viewBudget(+m[1], m[2] ? +m[2] : null);
    return viewHome();
}
window.addEventListener('hashchange', render);

// ── כניסה דרך הפלטפורמה (lib/platform.php) ──────────────────

/** האפליקציה מחוברת לפלטפורמה — הכניסה, היציאה והסיסמה הן שם. */
const connected = () => S.platform.state !== 'off';

function viewNoUser() {
    const st = S.platform.state;
    if (st === 'off') return viewAuth();
    if (st === 'link') return viewLink(false);
    setHeader('הצעות תקציב');
    const box = (...kids) => put(main, h('div.auth', brand('קופות, הקדשות, ריכוז ונספח — במסמך אחד'), h('div.panel', ...kids)));
    if (st === 'blocked') return box(h('p.error', { role: 'alert' }, 'החשבון חסום באפליקציה. פנו למפתח.'));
    if (st === 'unavailable') {
        return box(h('p.error', { role: 'alert' }, 'הכניסה לא זמינה כרגע. נסו שוב בעוד כמה דקות.'),
            h('button.btn.primary', { type: 'button', onclick: boot }, 'לנסות שוב'));
    }
    // login — דף ציבורי בלי זהות: לא נכנסו לפלטפורמה, או שאין הרשאה לאפליקציה
    const next = location.pathname + location.search + location.hash;
    return box(h('p', 'הכניסה היא דרך הכלים הדיגיטליים.'),
        h('a.btn.primary', { href: new URL('../', location.href).pathname + '?next=' + encodeURIComponent(next) }, 'כניסה'),
        h('p.hint', 'כבר נכנסתם ועדיין רואים את המסך הזה? אין לכם הרשאה לאפליקציה — פנו למנהל.'));
}

/**
 * המסך החד-פעמי: מי שנכנס דרך הפלטפורמה ואין לו חשבון באותו מייל — אולי יש לו חשבון ישן במייל אחר.
 * fromMenu — מהתפריט, אחרי שכבר נפתח חשבון חדש: החשבון הישן מחליף אותו.
 */
function viewLink(fromMenu) {
    setHeader('חיבור חשבון', '', fromMenu);
    const err = h('p.error', { role: 'alert', hidden: true });
    const btn = h('button.btn.primary', { type: 'submit' }, 'חיבור החשבון');
    const form = h('form.panel', { novalidate: true },
        h('p.hint', 'נכנסים פעם אחת במייל ובסיסמה של החשבון הישן, ומכאן — דרך הכלים הדיגיטליים.'),
        h('label.f', h('span', 'מייל'), h('input', { name: 'email', type: 'email', dir: 'ltr', autocomplete: 'username', required: true })),
        pwField('סיסמה', 'password', 'current-password'),
        fromMenu ? h('p.hint', 'החשבון שנפתח עכשיו ינותק, והחשבון הישן יבוא במקומו.') : null,
        err, btn);
    form.addEventListener('submit', busy(btn, async () => {
        await api('platform_link', formData(form));
        location.hash = '#/';
        await boot();
        toast('החשבון חובר');
    }, err));

    const intro = h('p', S.platform.name ? `שלום ${S.platform.name}. ` : '',
        'יש לך כבר חשבון באפליקציה הזו — במייל אחר?');
    if (fromMenu) return put(main, h('div.auth', brand('חיבור חשבון ישן'), form));

    const err2 = h('p.error', { role: 'alert', hidden: true });
    const fresh = h('button.btn.ghost', { type: 'button' }, 'אין לי חשבון — להמשיך');
    fresh.addEventListener('click', busy(fresh, async () => {
        await api('platform_new', {});
        await boot();
    }, err2));
    put(main, h('div.auth', brand('פעם אחת, לפני שמתחילים'), h('div.panel', intro,
        h('p.hint', 'אפשר לחבר גם אחר כך, מהתפריט האישי.')), form, h('div.row-btns', fresh), err2));
}

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
    put(main, h('div.auth', brand('קופות, הקדשות, ריכוז ונספח — במסמך אחד'), mode === 'forgot' ? null : seg, form));
}

async function viewVerify(token) {
    setHeader('אימות מייל');
    put(main, h('div.auth', brand('אימות כתובת המייל'), h('p.empty', 'מאמת…')));
    let box;
    try {
        const r = await api('verify', { token });
        box = h('p.ok-msg', r.message);
    } catch (err) {
        box = h('p.error', { role: 'alert' }, err.message);
    }
    history.replaceState(null, '', location.pathname);
    put(main, h('div.auth', brand('אימות כתובת המייל'), box,
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
        put(main, h('div.auth', brand('סיסמה חדשה'), h('p.ok-msg', r.message),
            h('button.btn.primary', { type: 'button', onclick: () => viewAuth('login') }, 'לכניסה')));
    }, err));
    put(main, h('div.auth', brand('קביעת סיסמה חדשה'), form));
}

// ── הבית ────────────────────────────────────────────────────

async function viewHome() {
    setHeader('הצעות תקציב', S.user.name);
    put(main, h('p.empty', 'טוען…'));
    const { orgs } = await api('org_list').catch(err => (toast(err.message, true), { orgs: [] }));
    const add = h('button.btn.primary', { type: 'button', onclick: () => orgSheet() }, h('span', { 'aria-hidden': 'true' }, '+'), ' ארגון');
    const imp = h('button.icon-btn', { type: 'button', 'aria-label': 'ייבוא ארגון מקובץ', title: 'ייבוא מקובץ', onclick: importSheet }, '📥');
    put(main, 
        h('div.toolbar', h('p.sec-title', { style: 'flex:1;margin:0' }, 'הארגונים שלי'), imp, add),
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
    put(main, h('p.empty', 'טוען…'));
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

    put(main, 
        h('dl.cells',
            h('div', h('dt', 'מייל'), h('dd.one', { dir: 'ltr' }, info.email)),
            h('div', h('dt', 'נרשם'), h('dd', fmtDate(info.created_at))),
            h('div', h('dt', 'מכשירים מחוברים'), h('dd', info.devices)),
            info.is_developer ? h('div', h('dt', 'תפקיד'), h('dd', '🛠️ מפתח')) : null),
        h('p.sec-title', 'שם'), nameForm,
        connected() ? null : [h('p.sec-title', 'סיסמה'), pwForm],
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
    put(main, tabs, box);
    put(box, h('p.empty', 'טוען…'));
    try {
        await ({ users: devUsers, orgs: devOrgs, log: devLog, audit: devAudit, health: devHealth })[S.devTab](box, tabs);
    } catch (err) {
        put(box, h('p.error', { role: 'alert' }, err.message));
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
    put(box, 
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
    put(box, 
        h('div.stat-line',
            h('span.chip', `יממה: ${d.n} בקשות`), h('span.chip.bad', `${d.e5} שגיאות שרת`),
            h('span.chip.warn', `${d.e4} סירובים`), h('span.chip.bad', `${d.js} JS`), h('span.chip', `ממוצע ${d.avg_ms}ms`)),
        h('div.toolbar', toggle, h('button.icon-btn', { type: 'button', 'aria-label': 'רענון', onclick: viewDev }, '↻')),
        rows.length ? h('div.list', ...rows) : h('p.empty', 'אין רשומות'));
}

async function devAudit(box) {
    const { audit } = await api('dev_audit_list');
    put(box, 
        h('p.hint', 'כל פעולת מפתח — מי, מתי, על מה ולמה. אי אפשר למחוק מהיומן הזה.'),
        audit.length ? h('ul.list', ...audit.map(a => h('li.card',
            h('div.body',
                h('div.l1', h('span', a.label, a.detail?.email || a.detail?.name ? ` · ${a.detail.email || a.detail.name}` : ''), h('span.t', fmtDate(a.at))),
                h('div.l2', a.by, a.reason ? [h('i.sep', '·'), a.reason] : null))))) : h('p.empty', 'עוד אין פעולות'));
}

async function devHealth(box) {
    const { health: x } = await api('dev_health');
    const yes = v => (v ? '✓' : '✕');
    put(box, 
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
    put(main, h('p.empty', 'טוען…'));
    let d;
    try { d = await api('org_get', null, `&id=${id}`); } catch (err) {
        setHeader('ארגון', '', true);
        put(main, h('p.error', { role: 'alert' }, err.message), h('button.btn.ghost', { type: 'button', onclick: () => (location.hash = '#/') }, 'לארגונים שלי'));
        return;
    }
    const o = d.org, edit = canEdit(o.role), root = !o.parent;
    S.org = d;
    setHeader(o.name, o.parent ? `תת-ארגון · ${o.parent.name}` : `${ROLE_LABEL[o.role]}${o.subtitle ? ' · ' + o.subtitle : ''}`, true, [
        h('button.icon-btn', { type: 'button', 'aria-label': 'היסטוריה', title: 'היסטוריה', onclick: () => orgHistory(o) }, '🕓'),
        edit ? h('button.icon-btn', { type: 'button', 'aria-label': 'פרטי הארגון', title: 'פרטי הארגון', onclick: () => orgSettings(d) }, '✏️') : null,
    ]);

    const tabs = [
        ['budgets', '📑 הצעות', d.budgets],
        ['funds', '🏦 קופות', d.funds.length],
        root ? ['subs', '🏛️ תתי-ארגונים', d.subs.length] : null,
        ['contacts', '👥 גורמים', d.contacts.length],
        ['cats', '🏷️ סעיפים', d.categories.length],
        root ? ['members', '🔐 חברים', d.members.length] : null,
    ].filter(Boolean);
    const key = `ob-org-tab-${id}`;
    let cur = sessionStorage.getItem(key);
    if (!tabs.some(t => t[0] === cur)) cur = 'budgets';
    const box = h('div');
    const bar = h('div.seg-tabs', { role: 'tablist', style: `grid-template-columns:repeat(${Math.min(tabs.length, 3)},minmax(0,1fr))` }, ...tabs.map(([k, l, n]) =>
        h('button', { role: 'tab', type: 'button', 'aria-selected': String(k === cur),
            onclick: () => { sessionStorage.setItem(key, k); viewOrg(id); } }, l, h('small', n || ''))));
    put(main, bar, box);
    ({ budgets: orgBudgets, funds: orgFunds, subs: orgSubs, contacts: orgContacts, cats: orgCats, members: orgMembers })[cur](box, d);
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
    $('#sheet-body').append(logoSection(o), h('section.group', h('h3', 'אזור מסוכן'), del));
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
    put(box, 
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
    put(box, 
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
        put(list, rows.length ? h('ul.list', ...rows.map(c => h('li', h('button.use-row', { type: 'button', onclick: () => contactSheet(d, c) },
            h('span.use-ic', { 'aria-hidden': 'true' }, CONTACT_IC[c.kind]),
            h('span.use-tx', h('b.one', c.name), h('small', [d.labels.contacts[c.kind], c.phone, c.email].filter(Boolean).join(' · '))),
            h('span.use-go', { 'aria-hidden': 'true' }, '‹')))))
            : h('p.empty', d.contacts.length ? 'לא נמצא' : 'עוד אין גורמים. שוכרים, ספקים ונותני שירות — כאן.'));
    };
    q.addEventListener('input', draw);
    const filter = seg('cf', [['all', 'הכול'], ['customer', 'לקוחות'], ['supplier', 'ספקים']], filt, 'סינון');
    filter.addEventListener('change', e => { sessionStorage.setItem('ob-contact-filter', e.target.value); viewOrg(o.id); });
    put(box, 
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
    put(box, 
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
    put(box, 
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
    put(box, 
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

// ── הצעות תקציב (שלב 3) ─────────────────────────────────────

const STATUS_CHIP = { draft: ['טיוטה', 'warn'], submitted: ['הוגשה', 'x'], approved: ['אושרה', 'ok'] };
const DIR_LABEL = { income: 'הכנסות', expense: 'הוצאות' };
const money = n => (n === null || n === undefined || n === '') ? '—' : Number(n).toLocaleString('he-IL');
/** קלט כסף: מציג עם פסיקים, שולח כמו שהוקלד (השרת מנקה פסיקים) */
function moneyField(label, name, value, opts = {}) {
    const input = h('input', { name, value: value === null || value === undefined || value === '' ? '' : Number(value).toLocaleString('en-US'),
        inputMode: 'numeric', dir: 'ltr', maxLength: 16, autocomplete: 'off', ...(opts.attrs || {}) });
    input.addEventListener('blur', () => {
        const v = input.value.replace(/[,\s₪]/g, '');
        if (/^-?\d+(\.\d+)?$/.test(v)) input.value = Math.round(+v).toLocaleString('en-US');
    });
    return h(`label.f${opts.wide ? '.wide' : ''}`, h('span', label), input);
}
const balChip = diff => diff === 0 ? h('span.chip.ok', 'מאוזנת') : diff > 0 ? h('span.chip.warn', `עודף ${money(diff)}`) : h('span.chip.bad', `גירעון ${money(-diff)}`);
const fmtDay = d => d ? d.split('-').reverse().join('.') : '';

// ── רשימת ההצעות בארגון ──

async function orgBudgets(box, d) {
    const o = d.org, edit = canEdit(o.role);
    put(box, h('p.empty', 'טוען…'));
    const { budgets } = await api('budget_list', null, `&org_id=${o.id}`);
    put(box, 
        h('p.hint', 'הצעת תקציב לשנה. הצעה חדשה יכולה להיבנות על השנה הקודמת — הסכומים שלה הופכים לעמודות "שנה קודמת".'),
        edit ? h('div.toolbar', { style: 'justify-content:flex-end' },
            h('button.btn.primary', { type: 'button', onclick: () => budgetNewSheet(o, budgets) }, h('span', { 'aria-hidden': 'true' }, '+'), ' הצעה')) : null,
        budgets.length ? h('ul.list', ...budgets.map(b => {
            const [sl, sc] = STATUS_CHIP[b.status];
            return h('li', h('button.use-row', { type: 'button', onclick: () => (location.hash = `#/budget/${b.id}`) },
                h('span.use-ic', { 'aria-hidden': 'true', style: 'font-weight:700;font-size:.85rem' }, b.year),
                h('span.use-tx', h('b.one', `הצעת תקציב ${b.year}`, b.title ? ` · ${b.title}` : ''),
                    h('small', `הכנסות ${money(b.income)} · הוצאות ${money(b.expense)}`, b.open_comments ? ` · 💬 ${b.open_comments}` : '')),
                h(`span.chip.${sc}`, sl)));
        })) : h('p.empty', 'עוד אין הצעות'));
}

function budgetNewSheet(o, budgets) {
    const next = budgets.length ? Math.max(...budgets.map(b => b.year)) + 1 : new Date().getFullYear() + 1;
    const base = h('select', { name: 'based_on_id' },
        h('option', { value: '' }, 'מאפס — בלי שנה קודמת'),
        ...budgets.map((b, i) => h('option', { value: b.id, selected: i === 0 }, `הצעת ${b.year}${b.title ? ' · ' + b.title : ''} (${STATUS_CHIP[b.status][0]})`)));
    formSheet('הצעה חדשה', [
        h('div.fields',
            field('שנה *', 'year', String(next), { max: 4, attrs: { inputMode: 'numeric', dir: 'ltr' } }),
            field('כותרת (לא חובה)', 'title', '', { max: 80, attrs: { placeholder: 'למשל: גרסה לוועדה' } })),
        budgets.length ? h('label.f', h('span', 'על בסיס'), base) : null,
        h('p.hint', budgets.length ? 'מועתקים: הקופות, השורות, החישובים, ההעברות, הריכוז, תכנית העבודה והחותמים.' : 'כל קופות הארגון ייכנסו להצעה.'),
    ].filter(Boolean), async x => {
        const r = await api('budget_create', { org_id: o.id, ...x });
        location.hash = `#/budget/${r.id}`;
    });
}

// ── מסך ההצעה ──

async function viewBudget(id, fundId = null) {
    put(main, h('p.empty', 'טוען…'));
    let d;
    try { d = await api('budget_get', null, `&id=${id}`); } catch (err) {
        setHeader('הצעת תקציב', '', true);
        put(main, h('p.error', { role: 'alert' }, err.message));
        return;
    }
    S.bud = d;
    d.edit = canEdit(d.org.role) && d.budget.status === 'draft';
    if (fundId) return viewBudgetFund(d, fundId);
    const b = d.budget, [sl, sc] = STATUS_CHIP[b.status];
    const open = d.comments.filter(c => !c.resolved).length;
    setHeader(`הצעת תקציב ${b.year}${b.title ? ' · ' + b.title : ''}`, `${d.org.name} · ${sl}`, true, [
        h('a.icon-btn', { href: `#/budget/${id}/grid`, 'aria-label': 'עריכה כטבלה', title: 'עריכה כטבלה' }, '▦'),
        h('a.icon-btn', { href: `#/budget/${id}/print`, 'aria-label': 'המסמך להדפסה', title: 'המסמך' }, '📄'),
        h('button.icon-btn', { type: 'button', 'aria-label': 'פעולות על ההצעה', title: 'פעולות', onclick: () => budgetActions(d) }, '⋯'),
    ]);
    const sm = d.summary;
    const card = h('div.summary',
        h('div.line', h('span.muted', 'הכנסות'), h('b', money(sm.income)), h('span.muted', '· הוצאות'), h('b', money(sm.expense)),
            h('span.pct', { style: `color:var(--${sm.income === sm.expense ? 'ok' : 'danger'})` }, sm.income === sm.expense ? 'מאוזן' : money(sm.income - sm.expense))),
        h('div.chips', h(`span.chip.${sc}`, sl),
            sm.unbalanced ? h('span.chip.bad', `${sm.unbalanced} קופות לא מאוזנות`) : h('span.chip.ok', 'כל הקופות מאוזנות'),
            open ? h('span.chip.warn', `💬 ${open} הערות פתוחות`) : null),
        !d.edit && canEdit(d.org.role) ? h('p.hint', { style: 'margin:0' }, 'ההצעה נעולה. לעריכה — "⋯" ← החזרה לטיוטה.') : null);
    const leases = d.appendix.reduce((a, g) => a + g.rows.length, 0);
    const tabs = [['funds', '🏦 קופות', d.funds.length], ['summary', '📊 ריכוז', ''], ['appendix', '🏠 נספח', leases || ''], ['plan', '📝 שער ותכנית', ''], ['comments', '💬 הערות', open || '']];
    let cur = sessionStorage.getItem('ob-bud-tab') || 'funds';
    if (!tabs.some(t => t[0] === cur)) cur = 'funds';
    const box = h('div');
    put(main, card,
        h('div.seg-tabs', { role: 'tablist', style: 'grid-template-columns:repeat(5,minmax(0,1fr))' }, ...tabs.map(([k, l, n]) =>
            h('button', { role: 'tab', type: 'button', 'aria-selected': String(k === cur),
                onclick: () => { sessionStorage.setItem('ob-bud-tab', k); viewBudget(id); } }, l, h('small', n || '')))),
        box);
    ({ funds: budFunds, summary: budSummary, appendix: budAppendix, plan: budPlan, comments: budComments })[cur](box, d);
}

function budFunds(box, d) {
    const bal = Object.fromEntries(d.summary.balance.map(x => [x.fund_id, x]));
    const notIn = d.targets.filter(t => t.org_id === d.org.id && !d.funds.some(f => f.id === t.id));
    put(box, 
        h('div.toolbar', { style: 'justify-content:space-between;align-items:center' },
            h('p.hint', { style: 'margin:0' }, 'כל קופה — דף במסמך: הכנסות מול הוצאות. לחיצה פותחת את השורות.'),
            h('a.btn.ghost', { href: `#/budget/${d.budget.id}/grid` }, '▦ ', d.edit ? 'עריכה כטבלה' : 'כטבלה')),
        h('ul.list', ...d.funds.map(f => {
            const x = bal[f.id];
            return h('li', h('button.use-row', { type: 'button', onclick: () => (location.hash = `#/budget/${d.budget.id}/fund/${f.id}`) },
                h('span.use-ic', { 'aria-hidden': 'true' }, FUND_IC[f.kind]),
                h('span.use-tx', h('b.one', f.name), h('small', `הכנסות ${money(x.income)} · הוצאות ${money(x.expense)}`)),
                balChip(x.diff)));
        })),
        d.edit && notIn.length ? h('div.row-btns', { style: 'margin-top:12px' }, h('button.btn.ghost', { type: 'button', onclick: () => {
            const pick = h('div.tiles', ...notIn.map(t => h('button.tile', { type: 'button', onclick: async () => {
                await api('bfund_toggle', { budget_id: d.budget.id, fund_id: t.id, include: 1 }).catch(e => toast(e.message, true));
                sheet.close(); viewBudget(d.budget.id);
            } }, h('span.tile-ic', { 'aria-hidden': 'true' }, FUND_IC[t.kind]), t.name)));
            openSheet('הוספת קופה להצעה', [h('p.hint', 'קופות שנוספו לארגון אחרי שההצעה נפתחה.'), pick]);
        } }, '+ קופה שלא בהצעה')) : null);
}

// ── עריכה כטבלה: כל הקופות, כמו במסמך — ושמירה אחת לכל השינויים ──

/** כניסה לתא מסמנת את כל התוכן — מקלידים ישר ערך חדש. העכבר לא מבטל את הסימון בשחרור. */
function selectOnFocus(inp) {
    let fresh = false;
    inp.addEventListener('focus', () => { fresh = true; requestAnimationFrame(() => inp.select()); });
    inp.addEventListener('mouseup', e => { if (fresh) { e.preventDefault(); fresh = false; } });
    inp.addEventListener('blur', () => { fresh = false; });
    return inp;
}

const toNum = v => { const t = String(v ?? '').replace(/[,\s₪]/g, ''); return t === '' ? null : /^-?\d+(\.\d+)?$/.test(t) ? Math.round(+t) : NaN; };
const fmtNum = n => n === null || n === undefined || n === '' ? '' : Number(n).toLocaleString('en-US');

async function viewGrid(id) {
    put(main, h('p.empty', 'טוען…'));
    let d;
    try { d = await api('budget_get', null, `&id=${id}`); } catch (err) {
        setHeader('עריכה כטבלה', '', true);
        put(main, h('p.error', { role: 'alert' }, err.message));
        return;
    }
    document.body.classList.add('grid-mode');
    const b = d.budget, edit = canEdit(d.org.role) && b.status === 'draft';
    S.gridHash = location.hash;
    setHeader('עריכה כטבלה', `הצעת ${b.year} · ${d.org.name}`, true);
    // העמודות — כמו במסמך: תקציב השנה הקודמת · ניצול · ההצעה; ניצול השנה — עמודה נוספת, לסוף השנה
    const NUMS = [['prev_budget', `הצעת תקציב\n${b.year - 1}`], ['prev_actual', `ניצול תקציב\n${b.prev_actual_as_of ? 'עד ' + fmtDay(b.prev_actual_as_of) : b.year - 1}`],
        ['amount', `הצעת תקציב\n${b.year}`], ['actual', `ניצול\n${b.year}`]];
    const SHORT = { prev_budget: `תקציב ${b.year - 1}`, prev_actual: `ניצול ${b.year - 1}`, amount: `מוצע ${b.year}`, actual: `ניצול ${b.year}` };
    const rows = [];          // { line|null, fund, dir, inputs:{}, tr, msg, table }
    const notes = [];         // { fund, dir, ta, orig }
    const bar = h('div.grid-bar');
    const sumCard = h('div.summary');
    const issuesBox = h('aside.grid-issues', { 'aria-live': 'polite' });
    const fundBal = {};
    const openCmt = {};
    for (const c of d.comments) if (!c.resolved && c.line_id) (openCmt[c.line_id] ??= []).push(c);

    // שורה שאי אפשר לשנות לה סכום כאן: חודשית (מחושבת) או מקבלת מכמה העברות (סכום המעבירות)
    const lockedAmount = l => l && (l.calc ? 'חודשי × חודשים — עריכה בשורה' : l.transfer_role === 'income' && (l.transfer || []).length > 1 ? 'סכום ההעברות — משתנה בקופות המעבירות' : '');
    const value = (r, k) => k === 'title' || k === 'subtitle' ? r.inputs[k].value.trim() : toNum(r.inputs[k].value);
    const changed = r => {
        if (!r.line) return value(r, 'title') !== '' || NUMS.some(([k]) => r.inputs[k].value.trim() !== '');
        return ['title', 'subtitle', ...NUMS.map(n => n[0])].some(k => r.inputs[k] && !r.inputs[k].readOnly && (value(r, k) ?? '') !== (r.line[k] ?? ''));
    };
    const pct = (a, z) => Math.round((a - z) / z * 100);

    /** הערות ואזהרות לשורה — מחושבות בכל הקלדה. err חוסם שמירה; warn — לתשומת לב. */
    const issues = r => {
        const out = [];
        const v = k => value(r, k);
        const bad = NUMS.filter(([k]) => Number.isNaN(v(k)));
        if (bad.length) out.push(['err', `מספר לא תקין: ${bad.map(([k]) => SHORT[k]).join(', ')}`]);
        if (!v('title') && (r.line || NUMS.some(([k]) => r.inputs[k].value.trim() !== ''))) out.push(['err', 'חסר שם לסעיף']);
        if (bad.length) return out;
        const am = v('amount'), pb = v('prev_budget'), pa = v('prev_actual');
        if (am < 0) out.push(['warn', 'סכום שלילי']);
        if (am !== null && pb && Math.abs(am - pb) >= 5000 && Math.abs(pct(am, pb)) >= 50)
            out.push(['warn', `${am > pb ? 'עלייה' : 'ירידה'} של ${Math.abs(pct(am, pb))}% מול ${b.year - 1} (${money(pb)} ← ${money(am)})`]);
        if (am && !pb && pb !== null && r.line) out.push(['info', `סעיף שלא היה ב-${b.year - 1}`]);
        if (pa !== null && pb !== null && pa > pb && pa - pb >= 1000) out.push(['warn', `הניצול ב-${b.year - 1} גבוה מהתקציב ב-${money(pa - pb)}`]);
        const title = v('title');
        if (title && rows.some(o => o !== r && o.table === r.table && value(o, 'title') === title && value(o, 'subtitle') === v('subtitle')))
            out.push(['warn', 'שם כפול בטבלה']);
        if (r.line && openCmt[r.line.id]) out.push(['note', ...openCmt[r.line.id].map(c => `💬 ${c.by}: ${c.body}`)].slice(0, 2));
        return out;
    };
    const ICON = { err: '⛔', warn: '⚠️', info: 'ℹ️', note: '' };

    const recalc = () => {
        for (const t of main.querySelectorAll('table.grid')) {
            const mine = rows.filter(r => r.table === t);
            for (const [k] of NUMS) {
                const tot = mine.reduce((a, r) => a + (r.summaryOnly ? 0 : (value(r, k) || 0)), 0);
                t.querySelector(`tfoot [data-k=${k}]`).textContent = money(tot);
            }
        }
        const all = [];
        let inc = 0, exp = 0;
        for (const f of d.funds) {
            const amt = dir => rows.filter(r => r.fund.id === f.id && r.dir === dir).reduce((a, r) => a + (value(r, 'amount') || 0), 0);
            const diff = amt('income') - amt('expense');
            inc += amt('income'); exp += amt('expense');
            fundBal[f.id].replaceChildren(balChip(diff));
            if (diff) all.push({ level: 'warn', where: f.name, text: diff > 0 ? `עודף ${money(diff)} — הכנסות מול הוצאות` : `גירעון ${money(-diff)} — הכנסות מול הוצאות`, el: fundBal[f.id] });
        }
        for (const r of rows) {
            const list = issues(r);
            r.tr.classList.toggle('dirty', changed(r));
            r.tr.classList.toggle('has-err', list.some(x => x[0] === 'err'));
            r.msg.replaceChildren(...list.map(([lvl, ...txt]) => h(`div.m.${lvl}`, ICON[lvl] ? ICON[lvl] + ' ' : '', txt.join(' · '))));
            for (const [lvl, ...txt] of list) if (lvl !== 'note') all.push({ level: lvl, where: `${r.fund.name} · ${value(r, 'title') || 'שורה חדשה'}`, text: txt.join(' · '), el: r.inputs.title });
        }
        sumCard.replaceChildren(h('div.line', h('span.muted', 'הכנסות'), h('b', money(inc)), h('span.muted', '· הוצאות'), h('b', money(exp)),
            h('span.pct', { style: `color:var(--${inc === exp ? 'ok' : 'danger'})` }, inc === exp ? 'מאוזן' : money(inc - exp))));
        const order = { err: 0, warn: 1, info: 2 };
        all.sort((x, y) => order[x.level] - order[y.level]);
        const errs = all.filter(x => x.level === 'err').length;
        issuesBox.replaceChildren(h('h3', all.length ? `הערות ואזהרות · ${all.length}` : '✓ אין הערות'),
            all.length ? h('ul', ...all.map(x => h('li', h('button', { type: 'button', class: x.level, onclick: () => { x.el.scrollIntoView({ block: 'center' }); x.el.focus?.(); } },
                h('b', ICON[x.level], ' ', x.where), h('span', x.text))))) : h('p.hint', { style: 'margin:0' }, 'כל הקופות מאוזנות, ואין שינויים חריגים.'));
        const n = rows.filter(changed).length + notes.filter(x => x.ta.value !== x.orig).length;
        bar.hidden = !edit || !n;
        save.textContent = errs ? `⛔ ${errs} שגיאות — לתקן לפני שמירה` : `שמירה · ${n} ${n === 1 ? 'שינוי' : 'שינויים'}`;
        save.disabled = !!errs;
        window.onbeforeunload = n ? () => true : null;
    };

    const numCell = (r, k, val, lock, note) => {
        const inp = h('input', { value: fmtNum(val), inputMode: 'numeric', dir: 'ltr', autocomplete: 'off', readOnly: !edit || !!lock,
            'aria-label': `${SHORT[k]} — ${r.line?.title || 'שורה חדשה'}`, title: lock || '' });
        inp.addEventListener('input', recalc);
        inp.addEventListener('blur', () => { const v = toNum(inp.value); if (Number.isFinite(v)) inp.value = fmtNum(v); });
        selectOnFocus(inp);
        r.inputs[k] = inp;
        return h(`td.num.c-${k}`, inp, note ? h('small.cell-note', note) : null);
    };
    const rowEl = (r, i) => {
        const l = r.line;
        const lock = lockedAmount(l);
        const recvLock = l && l.transfer_role === 'income' && (l.transfer || []).length > 1 ? lock : '';
        r.inputs.title = h('input.t', { value: l?.title || '', maxLength: 160, readOnly: !edit, placeholder: l ? '' : 'שורה חדשה…', 'aria-label': 'שם הסעיף' });
        // שורה שנייה ריקה — מוסתרת עד שעומדים על השורה (כדי שהטבלה תישאר צפופה)
        r.inputs.subtitle = h(`input.sub${l?.subtitle ? '' : '.empty'}`, { value: l?.subtitle || '', maxLength: 200, readOnly: !edit, placeholder: edit ? 'שורה שנייה' : '', 'aria-label': 'שורה שנייה' });
        for (const k of ['title', 'subtitle']) { r.inputs[k].addEventListener('input', recalc); selectOnFocus(r.inputs[k]); }
        r.summaryOnly = !!l?.summary_only;
        r.msg = h('div.row-msg');
        const note = l ? [l.calc_note, l.cell_note].filter(Boolean).join(' · ') : '';
        r.tr = h('tr',
            h('td.n', l ? `${i + 1}.` : '+',
                l && (l.transfer || l.lease || l.calc || l.summary_only) ? h('small', l.transfer ? '🔁' : '', l.lease ? '🏠' : '', l.calc ? '🧮' : '', l.summary_only ? '∑' : '') : null),
            h('th.title', { scope: 'row' }, h('div', r.inputs.title, r.inputs.subtitle, r.msg)),
            ...NUMS.map(([k]) => numCell(r, k, l ? l[k] : null, k === 'amount' ? lock : (k !== 'actual' ? recvLock : ''), k === 'amount' ? note : '')));
        return r.tr;
    };
    const table = (f, dir) => {
        const lines = d.lines.filter(l => l.fund_id === f.id && l.direction === dir);
        const tbody = h('tbody');
        const t = h('table.grid',
            h('colgroup', h('col.c-n'), h('col.c-title'), ...NUMS.map(([k]) => h(`col.c-${k}`))),
            h('thead', h('tr', h('th.n', { scope: 'col' }), h('th.title', { scope: 'col' }, DIR_LABEL[dir]),
                ...NUMS.map(([k, label]) => h(`th.c-${k}`, { scope: 'col' }, ...label.split('\n').flatMap((x, i) => i ? [h('br'), x] : [x]))))),
            tbody,
            h('tfoot', h('tr', h('td.n'), h('th.title', { scope: 'row' }, 'סה"כ'), ...NUMS.map(([k]) => h(`td.num.c-${k}`, { 'data-k': k })))));
        lines.forEach((l, i) => { const r = { line: l, fund: f, dir, inputs: {}, table: t }; rows.push(r); tbody.append(rowEl(r, i)); });
        const add = h('button.btn.ghost.sm', { type: 'button' }, '+ שורה');
        add.addEventListener('click', () => {
            const r = { line: null, fund: f, dir, inputs: {}, table: t };
            rows.push(r); tbody.append(rowEl(r)); r.inputs.title.focus(); recalc();
        });
        // הערות אחרי הטבלה — כמו במסמך, ונשמרות יחד עם השורות
        const key = `notes_${dir}`;
        const ta = h('textarea.grid-note', { rows: 1, maxLength: 3000, readOnly: !edit, placeholder: edit ? 'הערה אחרי הטבלה (לא חובה) — למשל: * בשל העברת עובדי חב"ק השתנו הסעיפים' : '', 'aria-label': `הערות אחרי טבלת ה${DIR_LABEL[dir]} — ${f.name}` }, f[key] || '');
        const fit = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 2 + 'px'; };
        ta.addEventListener('input', () => { fit(); recalc(); });
        requestAnimationFrame(fit);
        notes.push({ fund: f, dir, ta, orig: f[key] || '' });
        return [h('h4.grid-cap', `${DIR_LABEL[dir]} – ${f.name}`), h('div.grid-wrap', t), (edit || ta.value) ? ta : null, edit ? h('div.grid-add', add) : null];
    };

    const save = h('button.btn.primary', { type: 'button' });
    const undo = h('button.btn.ghost', { type: 'button', onclick: () => { window.onbeforeunload = null; viewGrid(id); } }, 'ביטול');
    save.addEventListener('click', busy(save, async () => {
        const out = [];
        for (const r of rows.filter(changed)) {
            const x = r.line ? { id: r.line.id } : { fund_id: r.fund.id, direction: r.dir };
            for (const k of ['title', 'subtitle', ...NUMS.map(n => n[0])]) {
                if (r.inputs[k].readOnly) continue;
                const v = value(r, k);
                if (!r.line || (v ?? '') !== (r.line[k] ?? '')) x[k] = v;
            }
            if (!r.line && x.amount === undefined) x.amount = 0;
            if (r.line) x.title ??= r.line.title;
            out.push(x);
        }
        if (out.length) await api('lines_batch', { budget_id: b.id, lines: out });
        const nchg = notes.filter(x => x.ta.value !== x.orig);
        for (const x of nchg) {
            const f = x.fund;
            await api('bfund_set', { budget_id: b.id, fund_id: f.id, row_income: f.row_income, row_expense: f.row_expense,
                notes_income: x.dir === 'income' ? x.ta.value : (notes.find(o => o.fund === f && o.dir === 'income')?.ta.value ?? f.notes_income),
                notes_expense: x.dir === 'expense' ? x.ta.value : (notes.find(o => o.fund === f && o.dir === 'expense')?.ta.value ?? f.notes_expense) });
        }
        window.onbeforeunload = null;
        toast(`נשמר: ${out.length} שורות${nchg.length ? ` ו-${nchg.length} הערות` : ''}`);
        viewGrid(id);
    }));
    bar.append(save, undo);

    // גודל התצוגה: ברירת המחדל — מה שמתאים למסך (כל העמודות נראות, בטלפון לא פחות מ-80%); אפשר להקטין ולהגדיל
    const doc = h('div.grid-doc', ...d.funds.map(f => {
        fundBal[f.id] = h('span.grid-bal');
        const dirs = b.expense_first ? ['expense', 'income'] : ['income', 'expense'];
        return h('section.grid-fund',
            h('div.grid-head', h('h3', FUND_IC[f.kind], ' ', `הכנסות והוצאות – ${f.name}`), fundBal[f.id]),
            f.address ? h('p.grid-addr', f.address) : null,
            ...dirs.flatMap(dir => table(f, dir)));
    }));
    const zLabel = h('output.z-val', { 'aria-live': 'polite' });
    const zoomKey = 'ob-grid-zoom';
    const fitZoom = () => {
        doc.style.zoom = 1;
        const t = doc.querySelector('table.grid');
        const need = t ? t.scrollWidth : 0, have = t ? t.parentElement.clientWidth : 0;
        const min = innerWidth < 900 ? .8 : .6;
        return have && need > have + 1 ? Math.max(min, Math.floor(have / need * 20) / 20) : 1;
    };
    let auto = true;
    const setZoom = z => {
        z = Math.round(Math.min(1.6, Math.max(.5, z)) * 20) / 20;
        doc.style.zoom = z;
        zLabel.textContent = `${Math.round(z * 100)}%${auto ? ' · מותאם' : ''}`;
        try { auto ? localStorage.removeItem(zoomKey) : localStorage.setItem(zoomKey, String(z)); } catch {}
    };
    const cur = () => +doc.style.zoom || 1;
    const zbtn = (label, aria, fn) => h('button.icon-btn', { type: 'button', 'aria-label': aria, title: aria, onclick: fn }, label);
    const zoomBox = h('div.grid-zoom', { role: 'group', 'aria-label': 'גודל התצוגה' },
        h('span.muted', '🔍'),
        zbtn('−', 'הקטנה', () => { auto = false; setZoom(cur() - .1); }),
        zLabel,
        zbtn('+', 'הגדלה', () => { auto = false; setZoom(cur() + .1); }),
        zbtn('↺', 'התאמה למסך', () => { auto = true; setZoom(fitZoom()); }));

    put(main, h('div.grid-top', sumCard,
            h('p.hint', edit
                ? 'כל הקופות כמו במסמך. משנים כמה שרוצים ושומרים פעם אחת; ההערות והאזהרות מתעדכנות תוך כדי הקלדה. 🧮 חודשית ו-🔁 העברה מכמה קופות — הסכום נגזר, ומשנים אותו בעריכת השורה. 🏠 שכירות · ∑ רק בריכוז.'
                : 'ההצעה ' + STATUS_CHIP[b.status][0] + ' — לקריאה בלבד. לעריכה — להחזיר לטיוטה (⋯ במסך ההצעה).')),
        zoomBox,
        h('div.grid-layout', doc, issuesBox),
        bar);
    recalc();
    let saved = null;
    try { saved = +localStorage.getItem(zoomKey) || null; } catch {}
    auto = !saved;
    setZoom(saved || fitZoom());
    // מסך שמשנה גודל (סיבוב טלפון, חלון) — ההתאמה האוטומטית מתעדכנת
    const onResize = () => { if (!doc.isConnected) return removeEventListener('resize', onResize); if (auto) setZoom(fitZoom()); };
    addEventListener('resize', onResize);
}

// ── קופה בתוך ההצעה: טבלת הכנסות / הוצאות ──

function viewBudgetFund(d, fundId) {
    const b = d.budget;
    const f = d.funds.find(x => x.id === fundId);
    if (!f) { location.hash = `#/budget/${b.id}`; return; }
    const dir = sessionStorage.getItem('ob-fund-dir') || (b.expense_first ? 'expense' : 'income');
    setHeader(f.name, `הצעת ${b.year} · ${f.address || d.org.name}`, true);
    const bal = d.summary.balance.find(x => x.fund_id === fundId);
    const lines = d.lines.filter(l => l.fund_id === fundId && l.direction === dir);
    const sorting = d.edit && sessionStorage.getItem('ob-line-sort') === '1';
    const cmt = Object.groupBy ? Object.groupBy(d.comments.filter(c => !c.resolved && c.line_id), c => c.line_id) : {};
    const move = async (i, step) => {
        const ids = lines.map(l => l.id);
        [ids[i], ids[i + step]] = [ids[i + step], ids[i]];
        await api('line_order', { budget_id: b.id, ids }).catch(e => toast(e.message, true));
        viewBudget(b.id, fundId);
    };
    // שורה "רק בריכוז" (למשל הלוואה בחבר"ק) — לא נספרת בסה"כ של הטבלה, כמו במסמך
    const total = k => lines.reduce((a, l) => a + (l.summary_only ? 0 : (l[k] || 0)), 0);
    const dirs = [['income', `הכנסות ${money(bal.income)}`], ['expense', `הוצאות ${money(bal.expense)}`]];
    const dseg = seg('dir', b.expense_first ? dirs.reverse() : dirs, dir, 'הכנסות או הוצאות');
    dseg.addEventListener('change', e => { sessionStorage.setItem('ob-fund-dir', e.target.value); viewBudget(b.id, fundId); });

    const rows = lines.map((l, i) => h('li.card', { tabindex: 0, role: 'button', onclick: () => sorting || lineSheet(d, f, dir, l), onkeydown: e => e.key === 'Enter' && lineSheet(d, f, dir, l) },
        h('span.avatar', { style: '--h:220;width:28px;height:28px;font-size:.75rem', 'aria-hidden': 'true' }, i + 1),
        h('div.body',
            h('div.l1', h('span', l.transfer ? '🔁 ' : '', l.lease ? '🏠 ' : '', l.title, l.summary_only ? h('span.chip', { style: 'margin-inline-start:6px' }, 'רק בריכוז') : null), h('span.t', { style: 'font-weight:700;color:var(--ink);font-size:.95rem' }, money(l.amount))),
            l.subtitle ? h('div.l2', l.subtitle) : null,
            h('div.l2', `קודם ${money(l.prev_budget)} · ניצול ${money(l.prev_actual)}`, l.calc_note || l.cell_note ? ` · ${[l.calc_note, l.cell_note].filter(Boolean).join(' · ')}` : '',
                cmt[l.id] ? ` · 💬 ${cmt[l.id].length}` : '')),
        sorting ? h('span.row-btns', { style: 'gap:0' },
            h('button.icon-btn', { type: 'button', 'aria-label': 'למעלה', disabled: i === 0, onclick: e => { e.stopPropagation(); move(i, -1); } }, '▲'),
            h('button.icon-btn', { type: 'button', 'aria-label': 'למטה', disabled: i === lines.length - 1, onclick: e => { e.stopPropagation(); move(i, 1); } }, '▼')) : null));

    const notesKey = `notes_${dir}`;
    const notes = h('textarea', { rows: 2, maxLength: 3000, disabled: !d.edit, placeholder: d.edit ? 'למשל: * בשל העברת עובדי חב"ק השתנו הסעיפים' : '' }, f[notesKey] || '');
    notes.addEventListener('change', async () => {
        try {
            await api('bfund_set', { budget_id: b.id, fund_id: f.id, notes_income: dir === 'income' ? notes.value : f.notes_income,
                notes_expense: dir === 'expense' ? notes.value : f.notes_expense, row_income: f.row_income, row_expense: f.row_expense });
            f[notesKey] = notes.value; toast('ההערה נשמרה');
        } catch (e) { toast(e.message, true); }
    });

    put(main, 
        h('div.summary', h('div.line', h('span.muted', 'הכנסות'), h('b', money(bal.income)), h('span.muted', '· הוצאות'), h('b', money(bal.expense)),
            h('span.pct', { style: `color:var(--${bal.diff ? 'danger' : 'ok'})` }, bal.diff ? (bal.diff > 0 ? 'עודף ' : 'גירעון ') + money(Math.abs(bal.diff)) : 'מאוזנת'))),
        h('div', { style: 'margin-bottom:8px' }, dseg),
        d.edit ? h('div.toolbar', { style: 'justify-content:flex-end;flex-wrap:wrap' },
            lines.length > 1 ? h('button.btn.ghost', { type: 'button', onclick: () => { sessionStorage.setItem('ob-line-sort', sorting ? '0' : '1'); viewBudget(b.id, fundId); } }, sorting ? '✓ סיום' : '↕️ סידור') : null,
            dir === 'expense' ? h('button.btn.ghost', { type: 'button', onclick: () => transferSheet(d, f) }, '🔁 העברה') : null,
            h('button.btn.primary', { type: 'button', onclick: () => lineSheet(d, f, dir) }, h('span', { 'aria-hidden': 'true' }, '+'), ' שורה')) : null,
        lines.length ? h('ul.list', ...rows,
            h('li.card', { style: 'cursor:default;background:var(--bg)' }, h('div.body',
                h('div.l1', h('span', 'סה"כ'), h('span.t', { style: 'font-weight:700;color:var(--ink);font-size:.95rem' }, money(total('amount')))),
                h('div.l2', `קודם ${money(total('prev_budget'))} · ניצול ${money(total('prev_actual'))}`))))
            : h('p.empty', d.edit ? `אין עדיין ${DIR_LABEL[dir]}. "+ שורה" כדי להוסיף.` : `אין ${DIR_LABEL[dir]}`),
        h('p.sec-title', 'הערות אחרי הטבלה'), notes);
}

function lineSheet(d, f, dir, l = null) {
    const b = d.budget, edit = d.edit;
    const isCalc = !!l?.calc;
    const c = l?.calc || {};
    const list = `cats-${dir}`;
    const preview = h('p.hint', { style: 'margin:0' });
    const modeSeg = seg('mode', [['fixed', 'סכום שנתי'], ['calc', 'חודשי × חודשים']], isCalc ? 'calc' : 'fixed', 'אופן החישוב');
    const fixedBox = h('div.fields', moneyField('סכום מוצע ' + b.year, 'amount', l?.amount ?? ''));
    const months = Array.from({ length: 12 }, (_, i) => i + 1);
    const mSel = (name, val) => h('select', { name }, ...months.map(m => h('option', { value: m, selected: m === val }, m)));
    const calcBox = h('div.fields',
        moneyField('סכום לחודש', 'monthly', c.monthly ?? ''),
        h('label.f', h('span', 'מחודש · עד חודש'), h('div', { style: 'display:flex;gap:6px' }, mSel('from', c.from || 1), mSel('to', c.to || 12))),
        h('label.f', h('span', 'שינוי מחודש (לא חובה)'), h('select', { name: 'change_month' }, h('option', { value: '' }, '—'), ...months.slice(1).map(m => h('option', { value: m, selected: m === c.change_month }, m)))),
        h('label.f', h('span', 'שינוי ב-%'), h('input', { name: 'change_pct', value: c.change_pct ?? '', inputMode: 'decimal', dir: 'ltr', placeholder: '5' })));
    const show = () => {
        const calc = modeSeg.querySelector('input:checked').value === 'calc';
        fixedBox.hidden = calc; calcBox.hidden = !calc;
        if (calc) {
            const v = k => calcBox.querySelector(`[name=${k}]`).value;
            const mon = +String(v('monthly')).replace(/[,\s]/g, '') || 0, from = +v('from'), to = Math.max(from, +v('to'));
            const chm = +v('change_month'), pct = +v('change_pct') || 0;
            const after = Math.round(mon * (1 + pct / 100));
            const tot = chm > from && chm <= to && pct ? mon * (chm - from) + after * (to - chm + 1) : mon * (to - from + 1);
            preview.textContent = `סה"כ לשנה: ${money(tot)} ₪`;
        } else preview.textContent = '';
    };
    modeSeg.addEventListener('change', show);
    calcBox.addEventListener('input', show);
    calcBox.addEventListener('change', show);

    const rowsDir = d.rows.filter(r => r.direction === dir);
    const contact = h('select', { name: 'contact_id' }, h('option', { value: '' }, '— בלי —'),
        ...d.contacts.map(x => h('option', { value: x.id, selected: x.id === l?.contact_id }, x.name)));
    const srow = h('select', { name: 'summary_row_id' }, h('option', { value: '' }, 'לפי הקופה (ברירת מחדל)'),
        ...rowsDir.map(r => h('option', { value: r.id, selected: r.id === l?.summary_row_id }, `${r.section} ← ${r.label}`)));

    const lineComments = l ? d.comments.filter(x => x.line_id === l.id) : [];
    const commentBox = l ? commentsBlock(d, lineComments, l.id, () => lineSheet(d, f, dir, d.lines.find(x => x.id === l.id))) : null;

    if (!edit && l) {
        openSheet(l.title, [
            h('dl.kv',
                l.subtitle ? [h('dt', 'שורה שנייה'), h('dd', l.subtitle)] : null,
                h('dt', `מוצע ${b.year}`), h('dd', h('b', money(l.amount)), l.calc_note ? ` · ${l.calc_note}` : ''),
                h('dt', `תקציב ${b.year - 1}`), h('dd', money(l.prev_budget)),
                h('dt', `ניצול ${b.year - 1}`), h('dd', money(l.prev_actual)),
                h('dt', `ניצול ${b.year}`), h('dd', money(l.actual)),
                l.contact ? [h('dt', 'גורם'), h('dd', l.contact)] : null,
                l.lease ? [h('dt', 'שכירות'), h('dd', [l.lease.property, l.lease.tenant].filter(Boolean).join(' · ') || 'כן — בנספח')] : null,
                l.transfer ? [h('dt', 'העברה'), h('dd', l.transfer.map(p => `${p.fund} · ${p.org}`).join(', ') || 'הצד השני עוד לא בהצעה')] : null),
            commentBox]);
        return;
    }
    const extra = [];
    if (l) {
        const rm = h('button.btn.ghost.danger', { type: 'button' }, l.transfer ? '🗑️ מחיקת ההעברה (שני הצדדים)' : '🗑️ מחיקת השורה');
        rm.addEventListener('click', busy(rm, async () => {
            if (!confirm(l.transfer ? 'למחוק את ההעברה — השורה כאן והשורה בקופה השנייה?' : `למחוק את "${l.title}"?`)) return;
            await api('line_delete', { budget_id: b.id, id: l.id }); sheet.close(); toast('נמחק'); viewBudget(b.id, f.id);
        }));
        extra.push(h('div.row-btns', rm));
        if (commentBox) extra.push(commentBox);
    }
    formSheet(l ? 'עריכת שורה' : `שורת ${dir === 'income' ? 'הכנסה' : 'הוצאה'} — ${f.name}`, [
        l?.transfer ? h('p.hint', '🔁 העברה ' + (dir === 'expense' ? 'אל ' : 'מ') + (l.transfer.map(p => `${p.fund} (${p.org})`).join(', ') || 'קופה שעוד אין לה הצעה בשנה הזו') + '. הסכומים משתנים בשני הצדדים יחד.') : null,
        h('datalist', { id: list }, ...d.categories.filter(x => x.kind === dir).map(x => h('option', { value: x.name }))),
        h('div.fields',
            field('שם הסעיף *', 'title', l?.title, { wide: true, max: 160, attrs: { list } }),
            field('שורה שנייה (פירוט)', 'subtitle', l?.subtitle, { wide: true, max: 200, attrs: { placeholder: 'למשל: מנהלת חשבונות 50%' } })),
        modeSeg, fixedBox, calcBox, preview,
        h('div.fields',
            moneyField(`תקציב ${b.year - 1}`, 'prev_budget', l?.prev_budget),
            moneyField(`ניצול ${b.year - 1}${b.prev_actual_as_of ? ' עד ' + fmtDay(b.prev_actual_as_of) : ''}`, 'prev_actual', l?.prev_actual),
            moneyField(`ניצול ${b.year} (בפועל)`, 'actual', l?.actual)),
        field('הערה בתא (מתחת לסכום)', 'cell_note', l?.cell_note, { wide: true, max: 160 }),
        h('div.fields', h('label.f', h('span', 'גורם (לקוח / ספק)'), contact), h('label.f', h('span', 'שורת ריכוז'), srow)),
        h('label.switch', h('input', { type: 'checkbox', name: 'summary_only', value: '1', checked: !!l?.summary_only }), h('span', 'רק בדף הריכוז (לא בטבלת הקופה)')),
        dir === 'income' ? leaseBox(d, f, l) : null,
    ].filter(Boolean), async x => {
        const body = { budget_id: b.id, id: l?.id || null, fund_id: f.id, direction: dir, title: x.title, subtitle: x.subtitle,
            contact_id: x.contact_id || null, prev_budget: x.prev_budget, prev_actual: x.prev_actual, actual: x.actual,
            cell_note: x.cell_note, summary_row_id: x.summary_row_id || null, summary_only: x.summary_only ? 1 : 0,
            lease: x.lease ? { property: x.lease_property, tenant: x.lease_tenant, monthly: x.lease_monthly, annual: x.lease_annual, period: x.lease_period } : null };
        if (x.mode === 'calc') body.calc = { monthly: x.monthly, from: x.from, to: x.to, change_month: x.change_month || 0, change_pct: x.change_pct || 0 };
        else body.amount = x.amount;
        await api('line_save', body);
        toast('נשמר'); viewBudget(b.id, f.id);
    }, extra);
    show();
}

function transferSheet(d, f) {
    const b = d.budget;
    const to = h('select', { name: 'to_fund_id' }, ...d.targets.filter(t => t.id !== f.id).map(t =>
        h('option', { value: t.id }, t.org_id === d.org.id ? t.name : `${t.name} · ${t.org}`)));
    const tFrom = h('input', { name: 'title_from', maxLength: 160 });
    const tTo = h('input', { name: 'title_to', maxLength: 160 });
    const fill = () => {
        const t = d.targets.find(x => x.id === +to.value);
        if (!tFrom.dataset.touched) tFrom.value = `העברה ל${t.name}`;
        if (!tTo.dataset.touched) tTo.value = `העברה מ${f.name}`;
    };
    [tFrom, tTo].forEach(i => i.addEventListener('input', () => { i.dataset.touched = '1'; }));
    const join = h('select', { name: 'to_line_id' });
    const joinBox = h('label.f', h('span', 'בקופה שם'), join);
    const fillJoin = () => {
        const t = d.targets.find(x => x.id === +to.value);
        const inc = t.org_id === d.org.id ? d.lines.filter(l => l.fund_id === t.id && l.direction === 'income') : [];
        join.replaceChildren(h('option', { value: '' }, 'שורת הכנסה חדשה'),
            ...inc.map(l => h('option', { value: l.id }, `להצטרף ל"${l.title}"${l.transfer ? ' (כבר מקבלת ' + l.transfer.length + ')' : ''}`)));
        joinBox.hidden = !inc.length;
        tTo.closest('label').hidden = !!join.value;
    };
    join.addEventListener('change', fillJoin);
    to.addEventListener('change', () => { fill(); fillJoin(); });
    formSheet(`העברה מ${f.name}`, [
        h('p.hint', 'שתי שורות שתמיד שוות: הוצאה כאן, והכנסה בקופה השנייה (גם בהצעה של תת-הארגון או של הארגון הראשי, באותה שנה).'),
        h('label.f', h('span', 'אל קופה'), to),
        joinBox,
        h('div.fields', moneyField(`סכום ${b.year} *`, 'amount', ''), moneyField(`תקציב ${b.year - 1}`, 'prev_budget', ''), moneyField(`ניצול ${b.year - 1}`, 'prev_actual', '')),
        h('label.f', h('span', 'שם השורה כאן (הוצאה)'), tFrom),
        h('label.f', h('span', 'שם השורה שם (הכנסה)'), tTo),
        h('p.hint', 'כמה קופות שמעבירות לאותה שורה (למשל שלושה הקדשים → "הכנסות מהחזר הלוואה מהקדשות") — בוחרים "להצטרף". הסכום שם = סכום כל המעבירות.'),
    ], async x => {
        if (!x.to_line_id) delete x.to_line_id;
        const r = await api('transfer_create', { budget_id: b.id, from_fund_id: f.id, ...x });
        toast(r.to_budget === b.id ? 'ההעברה נוספה בשתי הקופות' : 'ההעברה נוספה — גם בהצעה של הארגון השני');
        viewBudget(b.id, f.id);
    });
    fill(); fillJoin();
}

/** שכירות: השורה נכנסת לנספח "דוח הכנסות — נכסים והקדשות". שדה ריק = נגזר מהשורה. */
function leaseBox(d, f, l) {
    const on = !!l?.lease, x = l?.lease || {};
    const hek = f.kind === 'hekdesh' || f.kind === 'secular';
    const box = h('div.fields', { hidden: !on },
        field('נכס', 'lease_property', x.property, { max: 160, attrs: { placeholder: hek ? `${f.name}${f.address ? ' · ' + f.address : ''}` : (l?.title || 'שם השורה') } }),
        field('שוכר', 'lease_tenant', x.tenant, { max: 160, attrs: { placeholder: hek ? (l?.title || 'שם השורה') : (l?.subtitle || '') } }),
        field('שכירות לחודש (לא כולל מע"מ)', 'lease_monthly', x.monthly, { max: 60, attrs: { dir: 'ltr', placeholder: 'מהחישוב החודשי' } }),
        moneyField('סה"כ לשנה (לא כולל מע"מ)', 'lease_annual', x.annual, { attrs: { placeholder: 'כמו הסכום' } }),
        field('תקופת השכירות', 'lease_period', x.period, { max: 60, attrs: { dir: 'ltr', placeholder: `01-12/${d.budget.year}` } }));
    const sw = h('input', { type: 'checkbox', name: 'lease', value: '1', checked: on, onchange: e => { box.hidden = !e.target.checked; } });
    return h('section.group', h('label.switch', sw, h('span', '🏠 שכירות — בנספח השכירויות')), box);
}

// ── נספח השכירויות ──

function budAppendix(box, d) {
    const b = d.budget;
    const row = r => h('li.card', { tabindex: 0, role: 'button', onclick: () => {
            const l = d.lines.find(x => x.id === r.line_id);
            lineSheet(d, d.funds.find(x => x.id === r.fund_id), 'income', l);
        } },
        h('div.body',
            h('div.l1', h('span', r.property.split('\n')[0]), h('span.t', { style: 'font-weight:700;color:var(--ink);font-size:.95rem' }, money(r.annual))),
            h('div.l2', [r.tenant, `${r.monthly} לחודש`, r.period].filter(Boolean).join(' · '))));
    put(box,
        h('p.hint', `דוח הכנסות — נכסים והקדשות לשנת ${b.year}. כל שורת הכנסה שמסומנת 🏠 שכירות. הסכומים לא כוללים מע"מ.`),
        d.appendix.length ? d.appendix.map(g => [
            h('p.sec-title', g.title),
            h('ul.list', ...g.rows.map(row),
                h('li.card', { style: 'cursor:default;background:var(--bg)' }, h('div.body',
                    h('div.l1', h('span', `סה"כ ${g.title}`), h('span.t', { style: 'font-weight:700;color:var(--ink);font-size:.95rem' }, money(g.annual))),
                    h('div.l2', `${money(g.monthly)} לחודש`))))]) : h('p.empty', 'אין עדיין שורות שכירות. בעריכת שורת הכנסה — "🏠 שכירות".'));
}

// ── ייבוא ארגון מקובץ ──

function importSheet() {
    const file = h('input', { type: 'file', accept: '.json,application/json' });
    const err = h('p.error', { role: 'alert', hidden: true });
    const go = h('button.btn.primary', { type: 'button' }, 'ייבוא');
    go.addEventListener('click', busy(go, async () => {
        const fl = file.files[0];
        if (!fl) throw new Error('בחר קובץ');
        let data;
        try { data = JSON.parse(await fl.text()); } catch { throw new Error('הקובץ אינו JSON תקין'); }
        const r = await api('org_import', { data });
        sheet.close();
        toast(`יובאו ${r.budgets} הצעות, ${r.lines} שורות`);
        location.hash = `#/org/${r.org_id}`;
    }, err));
    openSheet('ייבוא ארגון מקובץ', [
        h('p.hint', 'קובץ JSON במבנה של הכלי (README, "קובץ ייבוא"): ארגון, תתי-ארגונים, קופות, והצעות של כמה שנים. נוצר ארגון חדש, ואתה הבעלים.'),
        h('label.f', h('span', 'קובץ'), file), err], go);
}

// ── ריכוז ──

function budSummary(box, d) {
    const b = d.budget;
    const head = h('div.l2', { style: 'display:flex;gap:8px;justify-content:flex-end;padding:4px 12px' },
        h('span', `${b.year - 1}`), h('span', `ניצול${b.prev_actual_as_of ? ' עד ' + fmtDay(b.prev_actual_as_of) : ''}`), h('b', `${b.year}`));
    const cells = (r, bold) => h('div.l2', { style: 'display:flex;gap:10px;justify-content:flex-end' },
        h('span', money(r.prev_budget)), h('span', money(r.prev_actual)), h(bold ? 'b' : 'span', { style: 'color:var(--ink)' }, money(r.amount)));
    const sec = s => [h('p.sec-title', s.title),
        h('ul.list', ...s.rows.map(r => {
            const defined = r.key.startsWith('row:');
            return h('li.card', { tabindex: d.edit ? 0 : -1, role: d.edit ? 'button' : null, onclick: () => d.edit && srowSheet(d, r) },
                h('div.body', h('div.l1', h('span', r.label), defined ? null : h('span.t', 'אוטומטית')), cells(r, false)));
        }), h('li.card', { style: 'cursor:default;background:var(--bg)' }, h('div.body', h('div.l1', h('span', 'סה"כ')), cells(s.total, true))))];
    const note = h('textarea', { rows: 2, maxLength: 500, disabled: !d.edit }, b.summary_note || '');
    note.addEventListener('change', () => saveBudgetFields(d, { summary_note: note.value }));
    // סידור: השורות המוגדרות, לפי הסדר במסמך (מקטע הכנסות ומקטע הוצאות יכולים להתחלף, כמו בארגון הקהילות)
    const sorting = d.edit && sessionStorage.getItem('ob-srow-sort') === '1' && d.rows.length > 1;
    const move = async (i, step) => {
        const ids = d.rows.map(x => x.id);
        [ids[i], ids[i + step]] = [ids[i + step], ids[i]];
        await api('srow_order', { budget_id: b.id, ids }).catch(e => toast(e.message, true));
        viewBudget(b.id);
    };
    const sortList = () => [h('p.hint', 'הסדר של השורות שהוגדרו — כך הן יופיעו בדף הריכוז. שורות אוטומטיות באות אחריהן.'),
        h('ul.list', ...d.rows.map((x, i) => h('li.card', { style: 'cursor:default' },
            h('div.body', h('div.l1', h('span', x.label)), h('div.l2', `${DIR_LABEL[x.direction]} · ${x.section}`)),
            h('span.row-btns', { style: 'gap:0' },
                h('button.icon-btn', { type: 'button', 'aria-label': 'למעלה', disabled: i === 0, onclick: () => move(i, -1) }, '▲'),
                h('button.icon-btn', { type: 'button', 'aria-label': 'למטה', disabled: i === d.rows.length - 1, onclick: () => move(i, 1) }, '▼')))))];
    put(box, 
        h('p.hint', 'דף הריכוז: כל שורה כאן = סכום של שורות מהקופות. שורה "אוטומטית" נבנית לפי סוג הקופה; לחיצה עליה הופכת אותה לשורה שאפשר לשנות לה שם ומקטע.'),
        d.edit ? h('div.toolbar', { style: 'justify-content:flex-end' },
            d.rows.length > 1 ? h('button.btn.ghost', { type: 'button', 'aria-pressed': String(sorting),
                onclick: () => { sessionStorage.setItem('ob-srow-sort', sorting ? '0' : '1'); viewBudget(b.id); } }, sorting ? '✓ סיום' : '↕️ סידור') : null,
            sorting ? null : h('button.btn.primary', { type: 'button', onclick: () => srowSheet(d) }, h('span', { 'aria-hidden': 'true' }, '+'), ' שורת ריכוז')) : null,
        sorting ? sortList() : d.summary.sections.length ? [head, ...d.summary.sections.flatMap(sec)] : h('p.empty', 'אין עדיין שורות בהצעה'),
        h('p.sec-title', 'הערה מתחת לריכוז'), note);
}

function srowSheet(d, r = null) {
    const b = d.budget;
    const defined = r && r.key.startsWith('row:');
    const rid = defined ? +r.key.slice(4) : null;
    const dir = r ? r.direction : 'income';
    const sections = [...new Set(d.summary.sections.map(s => s.title).concat(d.rows.map(x => x.section)))];
    // שורה מוגדרת — הקופות שהיא ברירת המחדל שלהן; אוטומטית — הקופות שהשורות שלהן נכנסות אליה עכשיו
    const isDefault = f => defined ? f[`row_${dir}`] === rid : r ? r.funds.includes(f.id) : false;
    const fundsBox = h('div.chips', ...d.funds.map(f => h('label', h('input', { type: 'checkbox', name: 'fund_ids', value: f.id, checked: isDefault(f) }), h('span', f.name))));
    const extra = [];
    if (defined) {
        const rm = h('button.btn.ghost.danger', { type: 'button' }, '🗑️ מחיקת שורת הריכוז');
        rm.addEventListener('click', busy(rm, async () => {
            await api('srow_delete', { budget_id: b.id, id: rid }); sheet.close(); toast('נמחקה — השורות שלה חוזרות לברירת המחדל'); viewBudget(b.id);
        }));
        extra.push(h('div.row-btns', rm));
    }
    formSheet(defined ? 'שורת ריכוז' : r ? 'הפיכה לשורת ריכוז' : 'שורת ריכוז חדשה', [
        r ? null : seg('direction', [['income', 'הכנסות'], ['expense', 'הוצאות']], dir, 'כיוון'),
        h('datalist', { id: 'sections' }, ...sections.map(s => h('option', { value: s }))),
        h('div.fields',
            field('כותרת הטבלה (מקטע) *', 'section', r?.section, { wide: true, max: 120, attrs: { list: 'sections', placeholder: 'למשל: הכנסות מהקדשות' } }),
            field('שם השורה *', 'label', r?.label, { wide: true, max: 160 })),
        h('section.group', h('h3', 'הקופות שנכנסות לשורה הזו', h('small', 'כל השורות שלהן בכיוון הזה')), fundsBox,
            h('p.hint', { style: 'margin:0' }, 'שורה בודדת משייכים לשורת ריכוז אחרת בעריכת השורה ("שורת ריכוז").')),
    ].filter(Boolean), async x => {
        const ids = [...fundsBox.querySelectorAll('input:checked')].map(i => +i.value);
        await api('srow_save', { budget_id: b.id, id: rid, direction: x.direction || dir, section: x.section, label: x.label, fund_ids: ids });
        toast('נשמר'); viewBudget(b.id);
    }, extra);
}

// ── שער ותכנית עבודה ──

async function saveBudgetFields(d, patch) {
    const b = d.budget;
    try {
        await api('budget_update', { budget_id: b.id, title: b.title, submitted_to: b.submitted_to, signers: b.signers, work_plan: b.work_plan,
            summary_note: b.summary_note, prev_actual_as_of: b.prev_actual_as_of, actual_as_of: b.actual_as_of, expense_first: b.expense_first, ...patch });
        Object.assign(b, patch); toast('נשמר');
    } catch (e) { toast(e.message, true); }
}

/** תכנית העבודה מהעורך — העותק השמור (HTML ו-CSS של העורך, ממוקד ב-.wp-doc). */
function planHtml(v) {
    const el = h('div.plan-html');
    el.innerHTML = `<style>${v.css || ''}</style>${v.html || ''}`;
    return el;
}

// ── עורך התמלילים (word-processor/docs/integration.md) ──

const WP_HOST = 'https://mbe-plus.com/mini_projects/word-processor/assets/js/host.js';
let wpLoading = null;

function loadWordProcessor() {
    if (window.WordProcessor) return Promise.resolve(window.WordProcessor);
    return wpLoading ??= new Promise((ok, fail) => {
        const sc = h('script', { src: WP_HOST });
        sc.onload = () => window.WordProcessor ? ok(window.WordProcessor) : fail(new Error('עורך התמלילים לא נטען'));
        sc.onerror = () => { wpLoading = null; sc.remove(); fail(new Error('עורך התמלילים לא זמין כרגע — נסו שוב בעוד רגע')); };
        document.head.append(sc);
    });
}

/**
 * העורך במסך מלא (במחשב — חלון רחב). part: 'cover' (השער) או 'plan' (תכנית העבודה).
 * בסגירה: שמירה, ואז העותק של הפלט נמשך לשרת שלנו (plan_sync).
 */
async function openPartEditor(b, part, after) {
    if (S.wpClose) return;
    let WP;
    try { WP = await loadWordProcessor(); } catch (e) { toast(e.message, true); return; }
    const box = h('div.wp-box');
    const wrap = h('div.wp-overlay', { role: 'dialog', 'aria-label': part === 'cover' ? 'השער' : `תכנית עבודה לשנת ${b.year}` }, box);
    document.body.append(wrap);
    document.body.classList.add('wp-open');
    let timer = null, closing = false;
    const sync = () => api('plan_sync', { budget_id: b.id, part })
        .then(r => { if (r.view) { b[part + '_view'] = r.view; b[part + '_doc'] = true; } })
        .catch(e => toast(e.message, true));
    const ed = WP.mount(box, {
        session: () => api('editor_session', { budget_id: b.id, part }),
        onReady: () => { b[part + '_doc'] = true; },
        onSaved: () => { clearTimeout(timer); timer = setTimeout(sync, 1500); },
        onClose: () => close(false),
        onLock: l => toast(`פתוח לעריכה אצל ${l.by} — נפתח לקריאה בלבד`),
        onError: e => {
            if (e.code === 'session') { toast(e.message, true); close(true); }
            else if (e.code === 'offline') toast('עורך התמלילים לא זמין כרגע — השינויים יישמרו כשיחזור', true);
        },
    });
    async function close(quiet) {
        if (closing) return;
        closing = true;
        if (!(await ed.save()) && !quiet && !confirm('השינויים האחרונים לא נשמרו. לסגור בכל זאת?')) { closing = false; return; }
        S.wpClose = null;
        clearTimeout(timer);
        ed.destroy();
        wrap.remove();
        document.body.classList.remove('wp-open');
        await sync();
        after();
    }
    S.wpClose = close;
}

/** תכנית העבודה כטקסט פשוט: "# כותרת" = פרק (א. ב. …), "## כותרת" = כותרת בלי אות, "- " = תבליט. */
function renderPlan(text) {
    const letters = 'אבגדהוזחטיכלמנסעפצקרשת';
    let n = 0, ul = null;
    const out = [];
    for (const raw of (text || '').split('\n')) {
        const t = raw.trim();
        if (t.startsWith('## ')) { ul = null; out.push(h('p', { style: 'font-weight:700;text-decoration:underline;margin-top:8px' }, t.slice(3))); }
        else if (t.startsWith('# ')) { ul = null; out.push(h('p', { style: 'font-weight:700;text-decoration:underline;margin-top:8px' }, `${letters[n++] || n}. ${t.slice(2)}`)); }
        else if (t.startsWith('- ')) { if (!ul) out.push(ul = h('ul', { style: 'padding-inline-start:20px' })); ul.append(h('li', t.slice(2))); }
        else if (t) { ul = null; out.push(h('p', t)); }
        else ul = null;
    }
    return out;
}

function budPlan(box, d) {
    const b = d.budget, edit = d.edit;
    // השער והתכנית — כל אחד מסמך בעורך התמלילים; כאן תצוגה וכפתור עריכה
    const MIGRATE = ' בפתיחה הראשונה הוא עובר לעורך כמו שהוא — ומשם עורכים הכול.';
    const part = (key, label, fallback, hasOld) => {
        const prev = h('div.part-prev');
        const draw = () => prev.replaceChildren(...(b[key + '_view']   // לתכנית — הכותרת שהמסמך מוסיף לפניה
            ? [key === 'plan' ? h('p', { style: 'font-weight:700;text-align:center' }, `תכנית עבודה לשנת ${b.year}`) : null, planHtml(b[key + '_view'])]
            : fallback()));
        const hint = h('p.hint', (key === 'cover'
            ? 'השער של המסמך — שם הארגון, הלוגו, הכותרת, מוגשת ל… והחותמים, בעיצוב חופשי.'
            : 'כותרות, רשימות, טבלאות, תמונות ועימוד — כמו ב-Word.') + ' נשמר לבד תוך כדי כתיבה.' + (!b[key + '_doc'] && hasOld ? MIGRATE : ''));
        const btn = h('button.btn.primary', { type: 'button' }, edit ? `📝 עריכת ${label}` : '👁️ פתיחה בעורך');
        btn.addEventListener('click', () => openPartEditor(b, key, () => { hint.textContent = hint.textContent.replace(MIGRATE, ''); draw(); }));
        draw();
        return h('section.group', h('h3', key === 'cover' ? '📄 השער' : '📝 תכנית העבודה'), hint,
            h('div', { style: 'display:flex;justify-content:flex-start' }, btn), prev);
    };
    const coverFallback = () => [h('div', { style: 'text-align:center;display:grid;gap:6px' },
        h('p', { style: 'font-weight:700;font-size:1.2rem' }, d.org.name),
        h('p', { style: 'font-weight:700' }, `הצעת תקציב לשנת ${b.year}`),
        b.submitted_to ? h('p', b.submitted_to) : null,
        ...(b.signers || []).map(sg => h('p', h('b', sg.name), ...sg.titles.map(t => [h('br'), t]))))];
    const planFallback = () => [h('p', { style: 'font-weight:700;text-align:center' }, `תכנית עבודה לשנת ${b.year}`),
        ...(b.work_plan ? renderPlan(b.work_plan) : [h('p.hint', 'עוד לא נכתבה תכנית עבודה.')])];

    const save = h('button.btn.primary', { type: 'button' }, 'שמירה');
    save.addEventListener('click', busy(save, async () => {
        const v = k => box.querySelector(`[name=${k}]`).value;
        await saveBudgetFields(d, { title: v('title'), expense_first: box.querySelector('[name=expense_first]').checked,
            prev_actual_as_of: v('prev_actual_as_of'), actual_as_of: v('actual_as_of') });
        viewBudget(b.id);
    }));
    const dis = { disabled: !edit };
    put(box,
        part('cover', 'השער', coverFallback, true),
        part('plan', 'תכנית העבודה', planFallback, !!b.work_plan),
        h('section.group', h('h3', '⚙️ הגדרות ההצעה'),
            h('div.fields',
                field('שם פנימי להצעה (לא חובה)', 'title', b.title, { wide: true, max: 80, attrs: dis }),
                h('label.f', h('span', `ניצול ${b.year - 1} — עד תאריך`), h('input', { type: 'date', name: 'prev_actual_as_of', value: b.prev_actual_as_of || '', dir: 'ltr', ...dis })),
                h('label.f', h('span', `ניצול ${b.year} — עד תאריך`), h('input', { type: 'date', name: 'actual_as_of', value: b.actual_as_of || '', dir: 'ltr', ...dis }))),
            h('label.switch', h('input', { type: 'checkbox', name: 'expense_first', checked: !!b.expense_first, ...dis }), h('span', 'בכל קופה — הוצאות לפני הכנסות (כמו בחבר"ק)')),
            edit ? save : null));
}

// ── הערות ──

function commentsBlock(d, list, lineId, after) {
    const b = d.budget;
    const ta = h('textarea', { rows: 2, maxLength: 2000, placeholder: 'הערה…' });
    const send = h('button.btn.primary', { type: 'button' }, 'שליחה');
    send.addEventListener('click', busy(send, async () => {
        if (!ta.value.trim()) return;
        await api('comment_add', { budget_id: b.id, line_id: lineId, body: ta.value });
        const fresh = await api('budget_get', null, `&id=${b.id}`);
        Object.assign(d, fresh, { edit: d.edit });
        after();
    }));
    const act = (c, op) => async () => {
        await api('comment_set', { budget_id: b.id, id: c.id, op }).catch(e => toast(e.message, true));
        const fresh = await api('budget_get', null, `&id=${b.id}`);
        Object.assign(d, fresh, { edit: d.edit });
        after();
    };
    const lineTitle = id => d.lines.find(l => l.id === id)?.title;
    return h('section.group', h('h3', '💬 הערות', h('small', list.length ? `${list.filter(c => !c.resolved).length} פתוחות` : '')),
        ...list.map(c => h('div', { style: `border:1px solid var(--line);border-radius:var(--r);padding:8px;${c.resolved ? 'opacity:.6' : ''}` },
            h('div.l2', { style: 'color:var(--muted);font-size:.8rem' }, `${c.by} · ${fmtDate(c.at)}`, !lineId && c.line_id ? ` · על "${lineTitle(c.line_id) || 'שורה שנמחקה'}"` : '', c.resolved ? ' · ✓ טופל' : ''),
            h('p', { style: 'white-space:pre-wrap' }, c.body),
            h('div', { style: 'display:flex;gap:4px;justify-content:flex-end' },
                (c.mine || canEdit(d.org.role)) ? h('button.btn.sm.ghost', { type: 'button', onclick: act(c, c.resolved ? 'reopen' : 'resolve') }, c.resolved ? 'פתיחה מחדש' : '✓ טופל') : null,
                c.mine ? h('button.btn.sm.ghost.danger', { type: 'button', onclick: act(c, 'delete') }, 'מחיקה') : null))),
        ta, h('div.row-btns', send));
}

function budComments(box, d) {
    const list = [...d.comments].sort((a, b) => a.resolved - b.resolved || b.id - a.id);
    put(box, h('p.hint', 'הערות על ההצעה — של כל החברים, גם צופים. הערה על שורה מסוימת — מתוך השורה.'),
        commentsBlock(d, list, null, () => viewBudget(d.budget.id)));
}

// ── פעולות על ההצעה ──

function budgetActions(d) {
    const b = d.budget, role = d.org.role;
    const go = (label, fn, cls = 'ghost') => {
        const btn = h(`button.btn.${cls}`, { type: 'button' }, label);
        btn.addEventListener('click', busy(btn, fn));
        return btn;
    };
    const status = to => async () => { await api('budget_status', { budget_id: b.id, status: to }); sheet.close(); toast('המצב עודכן'); viewBudget(b.id); };
    const btns = [];
    if (canEdit(role) && b.status === 'draft') btns.push(go('📨 הגשה (נעילה)', status('submitted'), 'primary'));
    if (canEdit(role) && b.status === 'submitted') btns.push(go('↩ החזרה לטיוטה', status('draft')));
    if (role === 'owner' && b.status === 'submitted') btns.push(go('✓ אישור', status('approved'), 'primary'));
    if (role === 'owner' && b.status === 'approved') btns.push(go('ביטול אישור', status('submitted')));
    if (canEdit(role)) btns.push(go('⧉ עותק (גרסה נוספת)', async () => {
        const r = await api('budget_duplicate', { budget_id: b.id }); sheet.close(); location.hash = `#/budget/${r.id}`;
    }));
    const del = h('button.btn.ghost.danger', { type: 'button' }, '🗑️ מחיקת ההצעה');
    del.addEventListener('click', () => formSheet('מחיקת הצעה', [
        h('p.error', 'ההצעה תימחק. העברות שלה ימשיכו להופיע בצד השני כשורות רגילות.'),
        field(`לאישור — הקלידו את השנה ${b.year}`, 'confirm', '', { wide: true, max: 4, attrs: { inputMode: 'numeric', dir: 'ltr' } }),
    ], async x => {
        await api('budget_delete', { budget_id: b.id, confirm: x.confirm });
        location.hash = `#/org/${d.org.id}`;
    }));
    openSheet(`הצעת ${b.year}`, [
        h('dl.kv', h('dt', 'מצב'), h('dd', STATUS_CHIP[b.status][0], b.status_at ? ` · ${fmtDate(b.status_at)}` : ''),
            h('dt', 'עודכנה'), h('dd', fmtDate(b.updated_at)), h('dt', 'ארגון'), h('dd', d.org.name)),
        h('p.hint', 'טיוטה — עורכים. הוגשה — נעולה (עורך מחזיר לטיוטה). אושרה — רק בעלים. הערות אפשר תמיד.'),
        h('section.group', h('h3', 'המסמך', h('small', 'שער, תכנית עבודה, ריכוז, קופות ונספח')),
            h('div.row-btns.wrap', ...exportButtons(b.id).map(a => (a.addEventListener('click', () => sheet.close()), a)))),
        btns.length ? h('section.group', h('h3', 'מצב'), h('div.row-btns.wrap', ...btns)) : null,
        h('button.btn.ghost', { type: 'button', onclick: () => { sheet.close(); location.hash = `#/org/${d.org.id}`; } }, '🏛️ לארגון'),
        canEdit(role) ? h('section.group', h('h3', 'אזור מסוכן'), del) : null,
    ].filter(Boolean));
}

// ── המסמך: תצוגה להדפסה / PDF, והורדות (שלב 5) ───────────────

const fileUrl = (action, id) => `api.php?action=${action}&id=${id}`;

/** כפתורי הפלט — בתפריט ⋯ של ההצעה ובראש מסך המסמך. */
function exportButtons(id) {
    return [
        h('a.btn.ghost', { href: `#/budget/${id}/print` }, '🖨️ מסמך / PDF'),
        h('a.btn.ghost', { href: fileUrl('budget_docx', id), download: '' }, '⬇️ Word'),
        h('a.btn.ghost', { href: fileUrl('budget_xlsx', id), download: '' }, '⬇️ Excel'),
    ];
}

/**
 * מסך המסמך: כל העמודים כמו בהדפסה (A4), ו"הדפסה" — הדפדפן שומר כ-PDF.
 * מספרי העמודים בדף הריכוז ("עמ' 6 עד 10") נמדדים אחרי הציור: גובה כל חלק ÷ גובה עמוד.
 */
async function viewPrint(id) {
    document.body.classList.add('print-mode');
    setHeader('המסמך', '', true);
    put(main, h('p.empty', 'בונה את המסמך…'));
    let d;
    try { d = (await api('budget_doc', null, `&id=${id}`)).doc; } catch (err) {
        put(main, h('p.error', { role: 'alert' }, err.message));
        return;
    }
    document.title = d.file;
    const c = d.cover, cols = d.cols, st = d.style;
    const br = t => String(t ?? '').split('\n').flatMap((x, i) => i ? [h('br'), x] : [x]);
    // מספר בבידוד משמאל לימין — אחרת בדף עברי מינוס עובר לצד הימני ("1,000-")
    const num = v => (v === null || v === undefined) ? '' : h('bdi', { dir: 'ltr' }, Number(v).toLocaleString('he-IL'));
    const table = (head, rows, widths) => h('table.dt',
        h('colgroup', ...widths.map(w => h('col', { style: `width:${w}%` }))),
        h('thead', h('tr', ...head.map(x => h('th', br(x))))), h('tbody', ...rows));
    const td = (v, cls = '') => h(`td${cls ? '.' + cls : ''}`, v);
    const pg = (...kids) => h('section.pg', ...kids);

    const cover = !st.show_cover ? null : c.view ? pg(planHtml(c.view)) : pg(h('div.cover',
        h('p.c-org', c.org), c.subtitle ? h('p.c-sub', c.subtitle) : null,
        c.has_logo ? h('img.c-logo', { src: fileUrl('org_logo', c.logo_org), alt: '' }) : h('div', { style: 'height:40mm' }),
        h('p.c-title', c.title), c.submitted_to ? h('p.c-to', c.submitted_to) : null,
        h('div.c-sign', ...c.signers.map(s => h('p', h('b', s.name), ...s.titles.map(t => [h('br'), t]))))));

    const plan = st.show_plan && d.plan.blocks.length && d.plan.view ? pg(h('h2.d-title', d.plan.title), planHtml(d.plan.view))
        : st.show_plan && d.plan.blocks.length ? pg(h('h2.d-title', d.plan.title), ...d.plan.blocks.map(b =>
        b.type === 'center' ? h('h3.d-center', b.text) : b.type === 'heading' ? h('h3.d-head', b.text)
            : b.type === 'bullet' ? h('p.d-bullet', '• ', b.text) : h('p.d-para', b.text))) : null;

    const refs = [];
    const summary = !st.show_summary ? null : pg(h('h2.d-title', d.summary.title), ...d.summary.sections.map(sec => [
        h('h3.d-cap', sec.title),
        table(['', cols.prev, cols.actual, cols.cur, 'פירוט'], [
            ...sec.rows.map(r => {
                const ref = h('span');
                if (r.funds.length) refs.push([ref, r.funds]);
                return h('tr', td(h('b', br(r.label))), td(num(r.prev), 'n'), td(num(r.actual), 'n'), td(num(r.cur), 'n'), td(ref, 'n'));
            }),
            h('tr.tot', td('סה"כ'), td(num(sec.total.prev), 'n'), td(num(sec.total.actual), 'n'), td(num(sec.total.cur), 'n'), td('')),
        ], [34, 17, 17, 17, 15])]),
        d.summary.note ? h('p.d-note', d.summary.note) : null);

    // כל קופה בעמוד חדש — או ברצף, בעמוד אחד שנמשך (st.fund_new_page)
    const funds = d.funds.map(f => {
        const s = (st.fund_new_page ? pg : (...k) => h('div.fund-flow', ...k))(h('h2.d-title', f.title), f.address ? h('h3.d-center', f.address) : null,
            ...f.tables.map(t => [h('h3.d-cap', t.caption), table(['', DIR_LABEL[t.dir], cols.prev, cols.actual, cols.cur], [
                ...t.rows.map(r => h('tr', td(r.n + '.', 'n'), td([h('b', r.title), r.subtitle ? [h('br'), h('small', r.subtitle)] : null]),
                    td(num(r.prev), 'n'), td(num(r.actual), 'n'), td([num(r.cur), r.note ? [h('br'), h('small', r.note)] : null], 'n'))),
                h('tr.tot', td(''), td('סה"כ'), td(num(t.total.prev), 'n'), td(num(t.total.actual), 'n'), td(num(t.total.cur), 'n')),
            ], [7, 39, 18, 18, 18]), ...t.notes.map(n => h('p.d-note', n))]),
            ...f.notes.map(n => h('p.d-note', n)));
        s.dataset.fund = f.id;
        return s;
    });

    const ac = d.appendix_cols;
    const appendix = st.show_appendix && d.appendix ? pg(...d.appendix.groups.map(g => {
        let last = null;
        return [h('h2.d-title', d.appendix.title), h('h3.d-cap', g.title), table([ac.property, ac.tenant, ac.monthly, ac.annual, ac.period], [
            ...g.rows.map(r => { const p = r.property === last ? '' : r.property; last = r.property;
                return h('tr', td(h('b', br(p))), td(r.tenant), td(br(r.monthly.replace(' / ', '\n')), 'n'), td(num(r.annual), 'n'), td(br(r.period.replace(', ', '\n')), 'n')); }),
            h('tr.tot', td(`סה"כ ${g.title}`), td('סה"כ'), td(num(g.monthly), 'n'), td(num(g.annual), 'n'), td('')),
        ], [22, 24, 18, 18, 18])];
    })) : null;

    const doc = h('div.doc', cover, plan, summary, ...(st.fund_new_page ? funds : funds.length ? [pg(...funds)] : []), appendix);
    doc.querySelector('.pg')?.classList.add('first');
    applyDocStyle(doc, st);
    const bar = h('div.doc-bar', h('button.btn.primary', { type: 'button', onclick: () => window.print() }, '🖨️ הדפסה / שמירה כ-PDF'),
        ...exportButtons(id).slice(1),
        d.can_edit ? h('button.btn.ghost', { type: 'button', onclick: () => docStyleSheet(d, id) }, '🖋️ עיצוב המסמך') : null,
        h('p.hint', { style: 'margin:0;flex-basis:100%' }, 'ב"הדפסה" בוחרים "שמירה כ-PDF". מספרי העמודים בדף הריכוז מחושבים לפי העמודים כאן.'));
    put(main, bar, doc);

    // מספרי עמודים: כל חלק מתחיל בעמוד חדש; גובה עמוד A4 פחות השוליים (מ"מ → פיקסלים ב-96dpi)
    await (document.fonts?.ready || Promise.resolve());
    const mm = 96 / 25.4;
    const pageH = (297 - st.margin_top - st.margin_bottom) * mm;
    let page = 1;
    const pageOf = {};
    for (const s of doc.querySelectorAll('.pg')) {
        if (s.dataset.fund) pageOf[s.dataset.fund] = page;
        // קופות ברצף: העמוד של כל קופה — לפי המקום שלה בתוך החלק
        for (const f of s.querySelectorAll('.fund-flow')) pageOf[f.dataset.fund] = page + Math.max(0, Math.floor((f.offsetTop - s.offsetTop - 12 * mm - 2) / pageH));
        page += Math.max(1, Math.ceil((s.scrollHeight - 22 * mm - 2) / pageH));   // בלי הריפוד של העמוד על המסך
    }
    for (const [el, ids] of refs) {
        const ps = ids.map(i => pageOf[i]).filter(Boolean);
        if (!ps.length) continue;
        const a = Math.min(...ps), z = Math.max(...ps);
        el.textContent = a === z ? `עמ' ${a}` : `עמ' ${a} עד ${z}`;
    }
    const fit = () => doc.style.setProperty('--fit', Math.min(1, (main.clientWidth - 8) / doc.scrollWidth));
    fit();
    window.addEventListener('resize', fit, { once: true });
}

// ── עיצוב המסמך: גופן, גדלים, כותרות, שוליים, עימוד, ואילו חלקים — לארגון (ותת-ארגון יורש) ──

function applyDocStyle(doc, st) {
    const v = { '--doc-font': `'${st.font}', Arial, 'Noto Sans Hebrew', sans-serif`, '--fs-text': st.size_text, '--fs-table': st.size_table,
        '--fs-title': st.size_title, '--fs-head': st.size_head, '--fs-cover': st.size_cover, '--doc-line': st.line,
        '--doc-u': st.underline ? 'underline' : 'none', '--doc-align': st.title_align === 'right' ? 'start' : 'center',
        '--m-top': `${st.margin_top}mm`, '--m-bottom': `${st.margin_bottom}mm`, '--m-side': `${st.margin_side}mm` };
    for (const [k, x] of Object.entries(v)) doc.style.setProperty(k, x);
    // @page לא מקבל משתנים — כלל משלו, שמוחלף בכל פתיחה של המסמך
    let pageCss = document.getElementById('doc-page');
    if (!pageCss) document.head.append(pageCss = h('style', { id: 'doc-page' }));
    pageCss.textContent = `@page { margin: ${st.margin_top}mm ${st.margin_side}mm ${st.margin_bottom}mm; }`
        + (st.page_numbers ? (st.show_cover ? '' : '@page :first { @bottom-center { content: counter(page); } }') : '@page { @bottom-center { content: none; } }');
}

function docStyleSheet(d, id) {
    const st = d.style, def = d.style_default;
    const num = (label, name, step = .5) => h('label.f', h('span', label),
        h('input', { name, type: 'number', step, value: st[name], inputMode: 'decimal', dir: 'ltr', placeholder: String(def[name]) }));
    const sw = (name, label) => h('label.switch', h('input', { type: 'checkbox', name, value: '1', checked: !!st[name] }), h('span', label));
    const from = { own: `העיצוב של ${d.org_name}.`, parent: 'כרגע — העיצוב של הארגון הראשי. שמירה כאן תיתן לתת-הארגון עיצוב משלו.', default: 'כרגע — ברירת המחדל.' }[d.style_from];
    const reset = h('button.btn.ghost', { type: 'button' }, d.is_sub ? '↺ כמו הארגון הראשי' : '↺ ברירת המחדל');
    reset.addEventListener('click', busy(reset, async () => {
        await api('org_style_set', { org_id: d.org_id, style: null }); sheet.close(); toast('העיצוב חזר לברירת המחדל'); viewPrint(id);
    }));
    formSheet('🖋️ עיצוב המסמך', [
        h('p.hint', `חל על כל ההצעות של ${d.org_name} — במסך, ב-PDF, ב-Word ובאקסל. ${from}`),
        h('label.f', h('span', 'גופן'), h('select', { name: 'font' }, ...d.fonts.map(f => h('option', { value: f, selected: f === st.font, style: `font-family:'${f}'` }, f)))),
        h('section.group', h('h3', 'גודל הכתב', h('small', 'בנקודות')),
            h('div.fields', num('טקסט', 'size_text'), num('טבלאות', 'size_table'), num('כותרת עמוד', 'size_title'), num('כותרת משנה', 'size_head'), num('שם הארגון בשער', 'size_cover'))),
        h('section.group', h('h3', 'כותרות'),
            seg('title_align', [['center', 'במרכז'], ['right', 'לימין']], st.title_align, 'יישור הכותרות'),
            sw('underline', 'קו תחתון בכותרות'),
            h('label.f', h('span', 'ריווח שורות'), h('select', { name: 'line' }, ...[1, 1.1, 1.15, 1.25, 1.5, 2].map(x => h('option', { value: x, selected: x === +st.line }, String(x)))))),
        h('section.group', h('h3', 'עימוד', h('small', 'שוליים במ"מ')),
            h('div.fields', num('עליון', 'margin_top', 1), num('תחתון', 'margin_bottom', 1), num('צדדים', 'margin_side', 1)),
            sw('fund_new_page', 'כל קופה בעמוד חדש'), sw('page_numbers', 'מספרי עמודים')),
        h('section.group', h('h3', 'חלקי המסמך'),
            sw('show_cover', 'שער'), sw('show_plan', 'תכנית עבודה'), sw('show_summary', 'דף ריכוז'), sw('show_appendix', 'נספח שכירויות')),
    ], async x => {
        const style = {};
        for (const k of Object.keys(def)) style[k] = typeof def[k] === 'boolean' ? !!x[k] : typeof def[k] === 'number' ? (x[k] === '' ? def[k] : +x[k]) : x[k];
        await api('org_style_set', { org_id: d.org_id, style });
        toast('העיצוב נשמר'); viewPrint(id);
    }, [h('div.row-btns', reset)]);
}

// ── לוגו לשער ──

function logoSection(o) {
    const img = h('img', { alt: 'הלוגו', style: 'max-height:96px;max-width:160px;display:block' });
    img.src = `${fileUrl('org_logo', o.id)}&t=${Date.now()}`;
    img.onerror = () => { img.hidden = true; none.hidden = false; };
    const none = h('p.hint', { hidden: true, style: 'margin:0' }, 'אין לוגו — בשער יופיע מקום ריק. תת-ארגון בלי לוגו משתמש בלוגו של הארגון הראשי.');
    const file = h('input', { type: 'file', accept: 'image/png,image/jpeg', hidden: true });
    const send = async remove => {
        const fd = new FormData();
        fd.append('org_id', o.id);
        if (remove) fd.append('remove', '1'); else fd.append('logo', file.files[0]);
        const res = await fetch('api.php?action=org_logo_upload', { method: 'POST', body: fd, headers: { 'X-CSRF': S.csrf }, credentials: 'same-origin' });
        const r = await res.json().catch(() => ({ ok: false, error: 'שגיאת שרת' }));
        if (!r.ok) return toast(r.error, true);
        toast(remove ? 'הלוגו הוסר' : 'הלוגו נשמר');
        img.hidden = false; none.hidden = true; img.src = `${fileUrl('org_logo', o.id)}&t=${Date.now()}`;
    };
    file.addEventListener('change', () => file.files[0] && send(false));
    return h('section.group', h('h3', 'לוגו לשער', h('small', 'PNG או JPG, עד 1.5MB')), img, none,
        h('div.row-btns', h('button.btn.ghost', { type: 'button', onclick: () => file.click() }, '🖼️ בחירת לוגו'),
            h('button.btn.ghost.danger', { type: 'button', onclick: () => send(true) }, 'הסרה')), file);
}

// ── התחלה ───────────────────────────────────────────────────

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
boot();
