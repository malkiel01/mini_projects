/*
 * מגדלור — קונסולת המפתח (SPEC.md, סעיף 11).
 *
 * דף אחד, שתי לשוניות: חשבונות · מערכת. כל הטקסט מוצב כ-textContent ולא כ-HTML —
 * שמות חשבונות והורים מגיעים מבחוץ. כל בקשה משנה נושאת X-Migdalor ו-X-CSRF.
 */
'use strict';

const API = 'api.php';
const LEVELS = {
    browser: ['🌐', 'דפדפן בלבד', 'הדפדפן נעול; שאר המכשיר חופשי'],
    apps:    ['📱', 'דפדפן ואפליקציות', 'גם רשימת אפליקציות מותרות וזמן מסך לכל אחת'],
    device:  ['🔒', 'כל המכשיר', 'גם הגדרות, התקנות ו-VPN שמסנן הכול'],
};
const MODULES = {
    yt_filtered:  ['▶️ יוטיוב מסונן', 'האתר עצמו, בלי הצעות ובלי פרסומות'],
    yt_closed:    ['📺 יוטיוב סגור', 'מסך משלנו — רק הערוצים שאושרו'],
    search_index: ['🔎 חיפוש באתרים המאושרים', 'אינדקס משלנו, חינם'],
    search_web:   ['🌍 חיפוש ברשת', 'ספק חיצוני, כל תוצאה מסוננת'],
    reports_full: ['📊 דוח גלישה מלא', 'ההורה יכול לראות כל כתובת (90 יום)'],
};
const STATUS = { pending: ['ממתין', 'warn'], active: ['פעיל', 'ok'], suspended: ['מושעה', 'bad'] };

const $ = (s, root = document) => root.querySelector(s);
let state = { me: null, csrf: '', accounts: [], filter: 'all', q: '' };

/* ── עזרים ─────────────────────────────────────────────────────── */

function el(tag, props = {}, kids = []) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else if (k === 'style') n.style.cssText = v;
        else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
        else if (k.startsWith('aria') || k.startsWith('data') || k === 'role' || k === 'for') n.setAttribute(k.replace(/[A-Z]/g, m => '-' + m.toLowerCase()), v);
        else n[k] = v;
    }
    for (const c of [].concat(kids)) if (c != null && c !== false) n.append(c);
    return n;
}

async function call(action, body = null) {
    const opts = body === null && action === 'me' ? {} : {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Migdalor': '1', 'X-CSRF': state.csrf },
        body: JSON.stringify(body || {}),
    };
    let res, data;
    try {
        res = await fetch(`${API}?action=${action}`, { credentials: 'same-origin', ...opts });
        data = await res.json();
    } catch (e) {
        throw new Error(res ? `תשובה לא תקינה מהשרת (HTTP ${res.status})` : 'אין חיבור לשרת');
    }
    if (data.code === 'unauthorized') { location.reload(); throw new Error('נדרשת כניסה'); }
    if (!data.ok) throw new Error(data.error || 'הבקשה נכשלה');
    return data;
}

function toast(msg, err = false) {
    const t = $('.toast');
    t.textContent = msg; t.classList.toggle('err', err); t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { t.hidden = true; }, 2800);
}

const hue = s => [...String(s)].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
const initials = s => String(s).trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('') || '?';

function fmtDate(iso) {
    if (!iso) return '';
    const d = new Date(iso.length === 10 ? iso + 'T12:00:00Z' : iso);
    return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: '2-digit' });
}
function ago(iso) {
    if (!iso) return 'אף פעם';
    const min = Math.round((Date.now() - new Date(iso)) / 60000);
    if (min < 2) return 'עכשיו';
    if (min < 60) return `לפני ${min} דק'`;
    if (min < 60 * 24) return `לפני ${Math.round(min / 60)} שע'`;
    return `לפני ${Math.round(min / 1440)} ימים`;
}
const quota = (n, max) => max > 0 ? `${n}/${max}` : `${n}`;

function chipStatus(s) {
    const [t, c] = STATUS[s] || [s, ''];
    return el('span', { class: `chip ${c}`, text: t });
}
function chipExpiry(a) {
    if (a.expiry === 'expired') return el('span', { class: 'chip bad', text: 'פג תוקף' });
    if (a.expiry === 'soon') return el('span', { class: 'chip warn', text: 'תוקף קרוב' });
    return null;
}

// --vvh: הגובה הנראה בלי המקלדת — הגיליון מתקצר והכול נגלל
(() => {
    const vv = window.visualViewport; if (!vv) return;
    const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
    vv.addEventListener('resize', set); set();
})();
document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

/* ── כניסה והתקנה ──────────────────────────────────────────────── */

async function boot() {
    let me;
    try { me = await call('me'); }
    catch (e) { document.body.append(el('p', { class: 'error', text: e.message, style: 'margin:16px' })); return; }
    state.csrf = me.csrf;
    if (!me.me) return showAuth(me.setup);
    state.me = me.me;
    $('#who').textContent = `מנהל מערכת · ${me.me.name || me.me.username}`;
    $('#auth').hidden = true; $('#app').hidden = false;
    route();
}

function showAuth(setup) {
    $('#auth').hidden = false; $('#app').hidden = true;
    $('#auth-sub').textContent = setup ? 'התקנה ראשונה — יצירת מנהל המערכת' : 'כניסת מנהל מערכת';
    $('#f-name').hidden = !setup;
    $('#auth-go').textContent = setup ? 'יצירה וכניסה' : 'כניסה';
    const form = $('#auth-form');
    form.password.autocomplete = setup ? 'new-password' : 'current-password';
    form.onsubmit = async e => {
        e.preventDefault();
        const err = $('.error', form); err.hidden = true;
        const btn = $('#auth-go'); btn.disabled = true;
        try {
            await call(setup ? 'setup' : 'login', {
                username: form.username.value.trim(), password: form.password.value, name: form.name.value,
            });
            location.reload();
        } catch (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; }
    };
    form.username.focus();
}

/* ── ניווט ─────────────────────────────────────────────────────── */

function route() {
    const tab = (location.hash.match(/^#\/(\w+)/) || [])[1] === 'system' ? 'system' : 'accounts';
    document.querySelectorAll('.tabs a').forEach(a => a.dataset.tab === tab
        ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current'));
    $('#v-accounts').hidden = tab !== 'accounts';
    $('#v-system').hidden = tab !== 'system';
    tab === 'system' ? loadSystem() : loadAccounts();
}
window.addEventListener('hashchange', () => { if (state.me) route(); });

/* ── חשבונות ──────────────────────────────────────────────────── */

async function loadAccounts() {
    try { state.accounts = (await call('accounts', {})).accounts; }
    catch (e) { toast(e.message, true); return; }
    renderAccounts();
}

function renderAccounts() {
    const A = state.accounts;
    const count = f => A.filter(f).length;
    $('#acc-sum').replaceChildren(...[
        ['פעילים', count(a => a.status === 'active')],
        ['ממתינים', count(a => a.status === 'pending')],
        ['תוקף קרוב', count(a => a.expiry === 'soon' || a.expiry === 'expired')],
        ['מכשירים', A.reduce((s, a) => s + a.devices, 0)],
    ].map(([t, n]) => el('div', {}, [el('dt', { text: t }), el('dd', { text: String(n) })])));

    const q = state.q.trim().toLowerCase();
    const rows = A.filter(a => {
        if (state.filter === 'soon' && !(a.expiry === 'soon' || a.expiry === 'expired')) return false;
        if (['pending', 'active', 'suspended'].includes(state.filter) && a.status !== state.filter) return false;
        return !q || a.name.toLowerCase().includes(q);
    });

    const list = $('#acc-list');
    if (!rows.length) {
        list.replaceChildren(el('li', { class: 'empty', text: A.length
            ? 'אין חשבונות שמתאימים לסינון.' : 'עוד אין חשבונות. "+ חשבון" כדי לפתוח את הראשון.' }));
        return;
    }
    list.replaceChildren(...rows.map(a => el('li', {
        class: 'card', tabIndex: 0, role: 'button', ariaLabel: a.name,
        onclick: () => openAccount(a.id),
        onkeydown: e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAccount(a.id); } },
    }, [
        el('span', { class: 'avatar', style: `--h:${hue(a.name)}`, ariaHidden: 'true', text: initials(a.name) }),
        el('div', { class: 'body' }, [
            el('div', { class: 'l1' }, [el('span', { text: a.name }),
                el('span', { class: 't', text: a.expires_at ? `עד ${fmtDate(a.expires_at)}` : 'ללא תוקף' })]),
            el('div', { class: 'l2', text: `👤 ${a.parents} · 🧒 ${quota(a.children, a.max_children)} · `
                + `📱 ${quota(a.devices, a.max_devices)} · ${LEVELS[a.level_max]?.[0] || ''}` }),
        ]),
        chipExpiry(a) || chipStatus(a.status),
    ])));
}

$('#acc-q').addEventListener('input', e => { state.q = e.target.value; renderAccounts(); });
$('#acc-filter').addEventListener('change', e => { state.filter = e.target.value; renderAccounts(); });
$('#acc-new').addEventListener('click', () => openNew());

/* ── גיליון חשבון ─────────────────────────────────────────────── */

let current = null;   // החשבון הפתוח
let tab = 'sum';

async function openAccount(id) {
    try { current = (await call('account', { id })).account; }
    catch (e) { toast(e.message, true); return; }
    tab = 'sum';
    renderSheet();
    const dlg = $('#acc-dlg');
    if (!dlg.open) dlg.showModal();
}

function segTabs() {
    const T = [['sum', '📋 סיכום'], ['ent', '🎛️ זכאויות'], ['par', `👤 הורים <${current.parent_list.length}>`],
               ['kid', `🧒 ילדים <${current.child_list.length}>`]];
    return el('div', { class: 'seg-tabs', role: 'tablist' }, T.map(([k, label]) => {
        const m = label.match(/^(.*) <(\d+)>$/);
        return el('button', {
            type: 'button', role: 'tab', ariaSelected: String(tab === k),
            onclick: () => { tab = k; renderSheet(); },
        }, m ? [m[1] + ' ', el('small', { text: m[2] })] : [label]);
    }));
}

function renderSheet() {
    const a = current;
    $('#acc-title').textContent = a.name;
    $('#acc-save').hidden = tab !== 'ent';
    const body = $('#acc-body');
    body.replaceChildren(segTabs(), ...({ sum: sumTab, ent: entTab, par: parTab, kid: kidTab }[tab])(a).filter(Boolean));
}

function sumTab(a) {
    const act = async (action, extra, ok) => {
        try { current = (await call(action, { id: a.id, ...extra })).account; toast(ok); renderSheet(); loadAccounts(); }
        catch (e) { toast(e.message, true); }
    };
    const statusTiles = a.status === 'active'
        ? [el('button', { type: 'button', class: 'tile', onclick: () => {
              if (confirm(`להשעות את "${a.name}"? כל המכשירים של הילדים יתנתקו מיד.`)) act('account_status', { status: 'suspended' }, 'החשבון הושעה');
          } }, [el('span', { class: 'tile-ic', text: '⏸️' }), 'השעיה'])]
        : [el('button', { type: 'button', class: 'tile', onclick: () => act('account_status', { status: 'active' },
              a.status === 'pending' ? 'החשבון אושר' : 'החשבון הופעל') },
              [el('span', { class: 'tile-ic', text: '✅' }), a.status === 'pending' ? 'אישור החשבון' : 'הפעלה מחדש'])];

    const dateIn = el('input', { type: 'date', value: a.expires_at || '', dir: 'ltr', ariaLabel: 'תוקף עד' });
    dateIn.addEventListener('change', () => act('account_update', { account: { expires_at: dateIn.value } }, 'התוקף עודכן'));

    return [
        el('div', { class: 'summary' }, [
            el('div', { class: 'line' }, [chipStatus(a.status), chipExpiry(a),
                el('span', { class: 'muted', text: LEVELS[a.level_max]?.[1] || '' })]),
            el('dl', { class: 'cells' }, [
                el('div', {}, [el('dt', { text: 'תוקף' }), el('dd', { text: a.expires_at ? fmtDate(a.expires_at) : 'ללא' })]),
                el('div', {}, [el('dt', { text: 'ילדים' }), el('dd', {}, [String(a.children),
                    el('small', { text: a.max_children ? ` מתוך ${a.max_children}` : ' · בלי הגבלה' })])]),
                el('div', {}, [el('dt', { text: 'מכשירים' }), el('dd', {}, [String(a.devices),
                    el('small', { text: a.max_devices ? ` מתוך ${a.max_devices}` : ' · בלי הגבלה' })])]),
                el('div', {}, [el('dt', { text: 'נראה לאחרונה' }), el('dd', { text: ago(a.last_seen_at) })]),
            ]),
        ]),
        el('section', { class: 'group' }, [
            el('h3', { text: 'מצב החשבון' }),
            el('div', { class: 'tiles' }, statusTiles),
        ]),
        el('section', { class: 'group' }, [
            el('h3', {}, ['תוקף', el('small', { text: 'הארכה מתחילה מהתוקף הנוכחי אם הוא עוד בעתיד' })]),
            el('div', { class: 'tiles' }, [
                el('button', { type: 'button', class: 'tile', onclick: () => act('account_extend', { months: 1 }, 'הוארך בחודש') },
                    [el('span', { class: 'tile-ic', text: '➕' }), 'חודש']),
                el('button', { type: 'button', class: 'tile', onclick: () => act('account_extend', { months: 12 }, 'הוארך בשנה') },
                    [el('span', { class: 'tile-ic', text: '📅' }), 'שנה']),
            ]),
            el('label', { class: 'f' }, [el('span', { text: 'או תאריך מדויק (ריק = ללא תוקף)' }), dateIn]),
        ]),
        a.note ? el('section', { class: 'group' }, [el('h3', { text: 'הערה' }), el('p', { text: a.note })]) : null,
    ];
}

function entTab(a) {
    const levels = el('fieldset', { class: 'seg grid', style: '--cols:3', ariaLabel: 'רמת אכיפה' },
        Object.entries(LEVELS).map(([k, [ic, t]]) => el('label', {}, [
            el('input', { type: 'radio', name: 'level_max', value: k, checked: a.level_max === k }),
            el('span', { text: `${ic} ${t}` })])));
    const levelHint = el('p', { class: 'hint', text: LEVELS[a.level_max]?.[2] || '' });
    levels.addEventListener('change', e => { levelHint.textContent = LEVELS[e.target.value][2]; });

    return [
        el('label', { class: 'f' }, [el('span', { text: 'שם החשבון' }), el('input', { name: 'name', value: a.name, maxLength: 80 })]),
        el('section', { class: 'group' }, [
            el('h3', {}, ['רמת אכיפה מרבית', el('small', { text: 'ההורה יכול לאכוף עד הרמה הזו' })]),
            levels, levelHint,
            el('p', { class: 'hint', text: '"אפליקציות" ו"כל המכשיר" ייאכפו במכשיר כשייבנו (שלב 11 ואילך).' }),
        ]),
        el('section', { class: 'group' }, [
            el('h3', { text: 'מודולים' }),
            el('div', { class: 'mods' }, Object.entries(MODULES).map(([k, [t, sub]]) => el('label', { class: 'switch' }, [
                el('input', { type: 'checkbox', name: 'modules', value: k, checked: a.modules.includes(k) }),
                el('span', {}, [t, el('small', { text: sub })]),
            ]))),
        ]),
        el('section', { class: 'group' }, [
            el('h3', {}, ['מכסות', el('small', { text: '0 = בלי הגבלה' })]),
            el('div', { class: 'fields two' }, [
                el('label', { class: 'f' }, [el('span', { text: 'ילדים' }),
                    el('input', { name: 'max_children', type: 'number', min: 0, max: 999, inputMode: 'numeric', dir: 'ltr', value: a.max_children })]),
                el('label', { class: 'f' }, [el('span', { text: 'מכשירים' }),
                    el('input', { name: 'max_devices', type: 'number', min: 0, max: 999, inputMode: 'numeric', dir: 'ltr', value: a.max_devices })]),
            ]),
        ]),
        el('label', { class: 'f' }, [el('span', { text: 'הערה (רק אתה רואה)' }),
            el('textarea', { name: 'note', rows: 2, maxLength: 500, value: a.note })]),
    ];
}

$('#acc-form').addEventListener('submit', async e => {
    e.preventDefault();
    if (tab !== 'ent' || !current) return;
    const f = e.target;
    const account = {
        name: f.name.value.trim(),
        level_max: f.querySelector('[name=level_max]:checked')?.value,
        modules: [...f.querySelectorAll('[name=modules]:checked')].map(i => i.value),
        max_children: Number(f.max_children.value || 0),
        max_devices: Number(f.max_devices.value || 0),
        note: f.note.value,
    };
    try {
        current = (await call('account_update', { id: current.id, account })).account;
        toast('נשמר'); renderSheet(); loadAccounts();
    } catch (x) { toast(x.message, true); }
});

function parTab(a) {
    const rows = a.parent_list.map(p => el('li', { class: 'card static' }, [
        el('span', { class: 'avatar', style: `--h:${hue(p.login)}`, ariaHidden: 'true', text: initials(p.name || p.login) }),
        el('div', { class: 'body' }, [
            el('div', { class: 'l1' }, [el('span', { text: p.name || p.login }),
                el('span', { class: 't', text: p.must_change_pw ? '🔑 סיסמה זמנית'
                    : (p.last_login_at ? ago(p.last_login_at) : 'לא נכנס') })]),
            el('div', { class: 'l2', text: p.login, dir: 'ltr', style: 'text-align:end' }),
        ]),
        el('span', { class: 'row-act' }, [
            el('button', { type: 'button', class: 'icon-btn', ariaLabel: `איפוס סיסמה ל-${p.login}`, text: '🔑',
                onclick: async () => {
                    if (!confirm(`לאפס את הסיסמה של ${p.login}? הוא יתנתק מכל המכשירים.`)) return;
                    try { showPw((await call('parent_reset', { id: p.id })).temp_password, p.login); openAccount(a.id); }
                    catch (x) { toast(x.message, true); }
                } }),
            el('button', { type: 'button', class: 'icon-btn', ariaLabel: `הסרת ${p.login}`, text: '🗑️',
                onclick: async () => {
                    if (!confirm(`להסיר את ${p.login} מהחשבון?`)) return;
                    try { current = (await call('parent_remove', { id: p.id })).account; renderSheet(); loadAccounts(); }
                    catch (x) { toast(x.message, true); }
                } }),
        ]),
    ]));

    const login = el('input', { dir: 'ltr', placeholder: 'dana@gmail.com', autocapitalize: 'off', ariaLabel: 'שם כניסה' });
    const name = el('input', { placeholder: 'שם', maxLength: 80, ariaLabel: 'שם' });
    return [
        el('ul', { class: 'list' }, rows),
        el('section', { class: 'group' }, [
            el('h3', {}, ['הורה נוסף', el('small', { text: 'מקבל סיסמה זמנית' })]),
            el('div', { class: 'fields two' }, [
                el('label', { class: 'f' }, [el('span', { text: 'דוא"ל / שם כניסה' }), login]),
                el('label', { class: 'f' }, [el('span', { text: 'שם' }), name]),
            ]),
            el('button', { type: 'button', class: 'btn primary', onclick: async () => {
                try {
                    const r = await call('parent_add', { account_id: a.id, login: login.value, name: name.value });
                    current = r.account; renderSheet(); loadAccounts(); showPw(r.temp_password, login.value.trim().toLowerCase());
                } catch (x) { toast(x.message, true); }
            } }, ['+ הורה']),
        ]),
    ];
}

function kidTab(a) {
    if (!a.child_list.length) return [el('p', { class: 'empty', text: 'עוד אין ילדים בחשבון. ההורה מוסיף אותם מהאפליקציה שלו.' })];
    return [
        el('p', { class: 'hint', text: 'שמות ומכשירים בלבד. ההרשאות וההיסטוריה — אצל ההורה.' }),
        el('ul', { class: 'list' }, a.child_list.map(k => el('li', { class: 'card static' }, [
            el('span', { class: 'avatar', style: `--h:${hue(k.name)}`, ariaHidden: 'true', text: initials(k.name) }),
            el('div', { class: 'body' }, [
                el('div', { class: 'l1' }, [el('span', { text: k.name }), el('span', { class: 't', text: ago(k.last_seen_at) })]),
                el('div', { class: 'l2', text: `${k.devices} מכשירים` }),
            ]),
            k.status !== 'active' ? chipStatus(k.status) : null,
        ]))),
    ];
}

/* ── חשבון חדש ─────────────────────────────────────────────────── */

function openNew() {
    current = null;
    const dlg = $('#acc-dlg');
    $('#acc-title').textContent = 'חשבון חדש';
    $('#acc-save').hidden = true;
    const f = {
        name: el('input', { required: true, maxLength: 80, placeholder: 'משפחת כהן' }),
        login: el('input', { required: true, dir: 'ltr', autocapitalize: 'off', placeholder: 'dana@gmail.com' }),
        pname: el('input', { maxLength: 80, placeholder: 'דנה' }),
        kids: el('input', { type: 'number', min: 0, max: 999, value: 3, inputMode: 'numeric', dir: 'ltr' }),
        devs: el('input', { type: 'number', min: 0, max: 999, value: 3, inputMode: 'numeric', dir: 'ltr' }),
    };
    const months = el('fieldset', { class: 'seg grid', style: '--cols:4', ariaLabel: 'תוקף' },
        [['0', 'ללא'], ['1', 'חודש'], ['3', '3 חודשים'], ['12', 'שנה']].map(([v, t]) => el('label', {}, [
            el('input', { type: 'radio', name: 'new-months', value: v, checked: v === '1' }), el('span', { text: t })])));
    const err = el('p', { class: 'error', role: 'alert', hidden: true });
    const go = el('button', { type: 'button', class: 'btn primary', text: 'פתיחת החשבון' });
    go.addEventListener('click', async () => {
        err.hidden = true; go.disabled = true;
        try {
            const r = await call('account_create', {
                account: { name: f.name.value, max_children: Number(f.kids.value || 0), max_devices: Number(f.devs.value || 0),
                           level_max: 'browser', modules: ['yt_filtered'] },
                parent_login: f.login.value, parent_name: f.pname.value,
            });
            const m = Number(months.querySelector('input:checked').value);
            if (m) await call('account_extend', { id: r.id, months: m });
            dlg.close(); loadAccounts();
            showPw(r.temp_password, f.login.value.trim().toLowerCase());
        } catch (x) { err.textContent = x.message; err.hidden = false; }
        go.disabled = false;
    });
    $('#acc-body').replaceChildren(
        el('p', { class: 'hint', text: 'חשבון שאתה פותח פעיל מיד. ההורה מקבל סיסמה זמנית ומחליף אותה בכניסה הראשונה.' }),
        el('label', { class: 'f' }, [el('span', { text: 'שם החשבון *' }), f.name]),
        el('section', { class: 'group' }, [el('h3', { text: 'ההורה הראשון' }), el('div', { class: 'fields two' }, [
            el('label', { class: 'f' }, [el('span', { text: 'דוא"ל / שם כניסה *' }), f.login]),
            el('label', { class: 'f' }, [el('span', { text: 'שם' }), f.pname]),
        ])]),
        el('section', { class: 'group' }, [el('h3', {}, ['מכסות', el('small', { text: '0 = בלי הגבלה' })]),
            el('div', { class: 'fields two' }, [
                el('label', { class: 'f' }, [el('span', { text: 'ילדים' }), f.kids]),
                el('label', { class: 'f' }, [el('span', { text: 'מכשירים' }), f.devs]),
            ])]),
        el('section', { class: 'group' }, [el('h3', { text: 'תוקף' }), months]),
        err, go,
    );
    if (!dlg.open) dlg.showModal();
    f.name.focus();
}

/* ── סיסמה זמנית ───────────────────────────────────────────────── */

function showPw(pw, who) {
    $('#pw-for').textContent = `הסיסמה הזמנית של ${who}:`;
    $('#pw-code').textContent = pw;
    $('#pw-dlg').showModal();
}
$('#pw-copy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText($('#pw-code').textContent); toast('הועתק'); }
    catch { toast('לא הצלחתי להעתיק — סמנו את הסיסמה והעתיקו ידנית', true); }
});

/* ── מערכת ─────────────────────────────────────────────────────── */

async function loadSystem() {
    let s;
    try { s = (await call('system', {})).system; } catch (e) { toast(e.message, true); return; }
    $('#sys-sum').replaceChildren(...[
        ['חשבונות', s.accounts], ['ילדים', s.children], ['מכשירים', s.devices], ['פעילים היום', s.online],
    ].map(([t, n]) => el('div', {}, [el('dt', { text: t }), el('dd', { text: String(n) })])));
    $('#sys-silent').replaceChildren(...(s.silent.length ? s.silent.map(d => el('li', { class: 'card static' }, [
        el('span', { class: 'avatar', style: `--h:${hue(d.account || '')}`, ariaHidden: 'true', text: '📱' }),
        el('div', { class: 'body' }, [
            el('div', { class: 'l1' }, [el('span', { text: d.display_name || d.username }), el('span', { class: 't', text: ago(d.last_seen_at) })]),
            el('div', { class: 'l2', text: `${d.account || '—'} · ${d.device_name || 'מכשיר'}` }),
        ]),
    ])) : [el('li', { class: 'empty', text: 'כל המכשירים התחברו ביממה האחרונה.' })]));
    renderSearch(s.search);
}

/** חיפוש: מצב האינדקס, סריקה עכשיו, ומפתח Brave (לילדים במצב "הכול פתוח"). */
function renderSearch(x) {
    const key = el('input', { type: 'password', dir: 'ltr', placeholder: x.brave ? '•••• (שמור — הדביקו כדי להחליף)' : 'BSA…', autocomplete: 'off', ariaLabel: 'מפתח Brave Search' });
    $('#sys-search').replaceChildren(
        el('dl', { class: 'cells k4' }, [['מקורות', x.sources], ['דפים', x.pages], ['ממתינים לכותרת', x.pending],
            ['מנוע', x.fts ? 'FTS5' : 'LIKE']].map(([t, n]) => el('div', {}, [el('dt', { text: t }), el('dd', { text: String(n) })]))),
        el('p', { class: 'hint', text: x.last_run ? `סריקה אחרונה: ${ago(x.last_run)}. נסרק לבד אחרי שימוש — כל מקור פעם ביום.` : 'עוד לא נסרק.' }),
        x.errors.length ? el('ul', { class: 'list' }, x.errors.map(e => el('li', { class: 'card static' }, [
            el('div', { class: 'body' }, [el('div', { class: 'l1', text: e.ref }), el('div', { class: 'l2', text: e.error })])]))) : '',
        el('button', { type: 'button', class: 'btn', text: '🔄 סריקה עכשיו', onclick: async ev => {
            ev.target.disabled = true; ev.target.textContent = 'סורק… (עד 20 שניות)';
            try { const r = await call('search_tick', {}); toast(`נסרקו ${r.scanned} מקורות`); renderSearch(r.search); }
            catch (e) { toast(e.message, true); ev.target.disabled = false; }
        } }),
        el('label', { class: 'f', style: 'margin-top:12px' }, [el('span', { text: `מפתח Brave Search ${x.brave ? '✓ מוגדר' : '— לא מוגדר'}` }), key]),
        el('p', { class: 'hint' }, ['לחיפוש ברשת לילדים במצב "הכול פתוח". מפתח חינמי: ',
            el('bdi', { dir: 'ltr', text: 'api-dashboard.search.brave.com' }), '. בלי מפתח — הם מחפשים באתרים שאושרו.']),
        el('button', { type: 'button', class: 'btn primary', text: 'שמירת המפתח', onclick: async () => {
            try { const r = await call('brave_key', { key: key.value }); toast(key.value ? 'נשמר' : 'נמחק'); renderSearch(r.search); }
            catch (e) { toast(e.message, true); }
        } }),
    );
}

/* ── תפריט ─────────────────────────────────────────────────────── */

$('#menu-btn').addEventListener('click', () => { $('#pass-box').hidden = true; $('#menu-dlg').showModal(); });
$('#m-pass').addEventListener('click', () => { $('#pass-box').hidden = false; $('#p-cur').focus(); });
$('#m-admin').addEventListener('click', () => { location.href = '../admin/'; });
$('#m-out').addEventListener('click', async () => { try { await call('logout', {}); } finally { location.reload(); } });
$('#p-go').addEventListener('click', async () => {
    try {
        await call('password', { current: $('#p-cur').value, next: $('#p-new').value });
        $('#p-cur').value = $('#p-new').value = ''; $('#menu-dlg').close(); toast('הסיסמה הוחלפה');
    } catch (x) { toast(x.message, true); }
});

boot();
