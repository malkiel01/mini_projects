/*
 * מגדלור — אפליקציית ההורה (SPEC.md, סעיף 10).
 *
 * מסך בית עם בקשות הגישה והילדים, וגיליון לכל ילד: סיכום · גישה · זמנים · יוטיוב · אתרים · מכשירים.
 * כל הטקסט מוצב כ-textContent — שמות וכתובות מגיעים מבחוץ. כל שינוי נושא X-Migdalor ו-X-CSRF.
 */
'use strict';

const API = 'api.php';
const DAYS = ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש'];
const MODES = { kiosk: ['🧩 אריחים בלבד', 'רק האתרים והערוצים שאישרתם, כאריחים. אין שורת כתובת.'],
                browser: ['🌐 דפדפן', 'יש שורת כתובת. מה שנפתח בה — לפי מה שקבעתם כאן.'] };
const POSTURES = { deny_all: ['🔒 הכול חסום', 'שום דבר לא נפתח עד שאתם מתירים. הבחירה הבטוחה.'],
                   allow_all: ['🔓 הכול פתוח', 'האינטרנט פתוח, חוץ ממה שאתם חוסמים.'] };
const YT = { off: ['⛔ כבוי', 'יוטיוב לא נפתח בכלל.'],
             restricted: ['✅ רק מה שאישרתי', 'רק הערוצים והסרטונים שברשימה. בלי דף הבית ובלי המלצות.'],
             full: ['▶️ פתוח', 'כל יוטיוב, חוץ ממה שתחסמו ברשימה.'] };
const SCOPES = { domain: 'כל האתר', exact: 'הדף הזה בלבד', domain_plus: 'האתר + מה שהוא טוען' };
const KIND = { channel: 'ערוץ', handle: 'ערוץ', video: 'סרטון', playlist: 'פלייליסט' };

const $ = (s, root = document) => root.querySelector(s);
let S = { me: null, csrf: '', account: null, catalogs: null, kids: [] };

/* ── עזרים ─────────────────────────────────────────────────────── */

function el(tag, props = {}, kids = []) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
        if (v === undefined || v === null) continue;
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else if (k === 'style') n.style.cssText = v;
        else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
        else if (k.startsWith('aria') || k.startsWith('data') || k === 'role') n.setAttribute(k.replace(/[A-Z]/g, m => '-' + m.toLowerCase()), v);
        else n[k] = v;
    }
    for (const c of [].concat(kids)) if (c != null && c !== false) n.append(c);
    return n;
}

async function call(action, body = null) {
    const get = body === null;
    let res, data;
    try {
        res = await fetch(`${API}?action=${action}`, get ? { credentials: 'same-origin' } : {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Migdalor': '1', 'X-CSRF': S.csrf },
            body: JSON.stringify(body),
        });
        data = await res.json();
    } catch (e) {
        throw new Error(res ? `תשובה לא תקינה מהשרת (HTTP ${res.status})` : 'אין חיבור לשרת');
    }
    if (data.code === 'unauthorized' || data.code === 'must_change') { location.reload(); throw new Error(data.error); }
    if (!data.ok) throw new Error(data.error || 'הבקשה נכשלה');
    return data;
}

function toast(msg, err = false) {
    const t = $('.toast');
    t.textContent = msg; t.classList.toggle('err', err); t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { t.hidden = true; }, 3200);
}

const hue = s => [...String(s)].reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
const initials = s => String(s).trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('') || '?';
function ago(iso) {
    if (!iso) return 'עוד לא התחבר';
    const min = Math.round((Date.now() - new Date(iso)) / 60000);
    if (min < 3) return 'מחובר עכשיו';
    if (min < 60) return `לפני ${min} דק'`;
    if (min < 1440) return `לפני ${Math.round(min / 60)} שע'`;
    return `לפני ${Math.round(min / 1440)} ימים`;
}
const mins = sec => sec < 60 ? (sec ? 'פחות מדקה' : '0 דק\'') : `${Math.round(sec / 60)} דק'`;

function seg(name, options, value, { cls = 'seg grid', cols, onchange, disabled = [] } = {}) {
    const f = el('fieldset', { class: cls, style: cols ? `--cols:${cols}` : null, ariaLabel: name });
    for (const [v, label] of options) {
        f.append(el('label', {}, [
            el('input', { type: 'radio', name, value: v, checked: value === v, disabled: disabled.includes(v) }),
            el('span', { text: label })]));
    }
    if (onchange) f.addEventListener('change', e => onchange(e.target.value));
    return f;
}
const sw = (name, label, checked, sub) => el('label', { class: 'switch' }, [
    el('input', { type: 'checkbox', name, checked: !!checked }),
    el('span', {}, [label, sub ? el('small', { text: sub, style: 'display:block;color:var(--muted);font-size:.78rem' }) : null])]);
const group = (title, sub, kids) => el('section', { class: 'group' }, [el('h3', {}, [title, sub ? el('small', { text: sub }) : null]), ...kids]);

(() => {
    const vv = window.visualViewport; if (!vv) return;
    const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
    vv.addEventListener('resize', set); set();
})();
document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

/* ── כניסה ─────────────────────────────────────────────────────── */

async function boot() {
    let me;
    try { me = await call('me'); }
    catch (e) { document.body.append(el('p', { class: 'error', text: e.message, style: 'margin:16px' })); return; }
    if (!me.me) return showOnly('#auth', () => $('#login-form').login.focus());
    Object.assign(S, { me: me.me, csrf: me.csrf, account: me.account, catalogs: me.catalogs });
    if (me.me.must_change) return showOnly('#newpass', () => $('#newpass-form').current.focus());
    showOnly('#app');
    $('#family').textContent = `${S.account.name} · ${S.me.name || S.me.login}`;
    renderBanner();
    loadKids();
    await loadRequests();
    fromNotification();
    renderPushNudge();
}

function showOnly(sel, then) {
    for (const s of ['#auth', '#newpass', '#app']) $(s).hidden = s !== sel;
    if (then) then();
}

$('#login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, err = $('.error', f), btn = $('button', f);
    err.hidden = true; btn.disabled = true;
    try { await call('login', { login: f.login.value, password: f.password.value }); location.reload(); }
    catch (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; }
});

$('#newpass-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, err = $('.error', f);
    err.hidden = true;
    if (f.next.value !== f.again.value) { err.textContent = 'שתי הסיסמאות החדשות אינן זהות'; err.hidden = false; return; }
    try { await call('password', { current: f.current.value, next: f.next.value }); location.reload(); }
    catch (x) { err.textContent = x.message; err.hidden = false; }
});

function renderBanner() {
    const a = S.account, box = $('#banner');
    const msg = a.status === 'suspended' ? ['bad', '⏸️', 'החשבון מושעה', 'המכשירים של הילדים חסומים. פנו למנהל המערכת.']
        : a.status === 'pending' ? ['warn', '⏳', 'החשבון ממתין לאישור', 'אפשר להגדיר כבר עכשיו; המכשירים ייפתחו אחרי האישור.']
        : a.expiry === 'expired' ? ['bad', '⚠️', 'תוקף החשבון הסתיים', 'כדי שהשמירה על הילדים תימשך — פנו למנהל המערכת.']
        : a.expiry === 'soon' ? ['warn', '⏰', 'תוקף החשבון מסתיים בקרוב',
            `עד ${new Date(a.expires_at + 'T12:00:00Z').toLocaleDateString('he-IL')}. פנו למנהל המערכת להארכה.`]
        : null;
    box.replaceChildren(msg ? el('div', { class: `banner ${msg[0]}`, role: 'status' }, [
        el('span', { text: msg[1], ariaHidden: 'true' }), el('div', {}, [el('b', { text: msg[2] }), msg[3]])]) : '');
}

/* ── הבית: הילדים ──────────────────────────────────────────────── */

async function loadKids() {
    try { S.kids = (await call('children', {})).children; } catch (e) { toast(e.message, true); return; }
    const list = $('#kids');
    if (!S.kids.length) {
        list.replaceChildren(el('li', { class: 'empty', text: 'עוד אין ילדים. "+ ילד" — ותקבלו פרטי כניסה למכשיר שלו.' }));
        return;
    }
    list.replaceChildren(...S.kids.map(k => {
        const quota = k.quota_min ? ` מתוך ${k.quota_min} דק'` : '';
        const chip = k.status === 'pending' ? el('span', { class: 'chip warn', text: 'ממתין לאישור' })
            : k.status !== 'active' ? el('span', { class: 'chip', text: 'מושהה' })
            : k.allowed_now ? el('span', { class: 'chip ok', text: 'פתוח' })
            : el('span', { class: 'chip bad', text: 'חסום עכשיו', title: k.reason_now });
        return el('li', { class: 'card', tabIndex: 0, role: 'button', ariaLabel: k.name,
            onclick: () => openKid(k.id),
            onkeydown: e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openKid(k.id); } } }, [
            el('span', { class: 'avatar', style: `--h:${hue(k.name)}`, ariaHidden: 'true', text: initials(k.name) }),
            el('div', { class: 'body' }, [
                el('div', { class: 'l1' }, [el('span', { text: k.name }), el('span', { class: 't', text: ago(k.last_seen_at) })]),
                el('div', { class: 'l2', text: (k.requests ? `🙋 ${k.requests} · ` : '') + `⏱️ היום ${mins(k.used_sec)}${quota}`
                    + (k.blocked_today ? ` · 🚫 ${k.blocked_today} חסימות` : '') + ` · 📱 ${k.devices}` }),
            ]),
            chip,
        ]);
    }));
}

/* ── גיליון ילד ────────────────────────────────────────────────── */

let K = null, tab = 'sum';
const TABS = [['sum', '📋 סיכום'], ['access', '🔐 גישה'], ['time', '⏰ זמנים'], ['yt', '▶️ יוטיוב'],
              ['sites', '🌐 אתרים'], ['dev', '📱 מכשירים']];

async function openKid(id) {
    try { K = (await call('child', { id })).child; } catch (e) { toast(e.message, true); return; }
    tab = sessionStorage.getItem('mg:tab') || 'sum';
    renderKid();
    const d = $('#kid-dlg'); if (!d.open) d.showModal();
}

async function act(action, extra = {}, ok = '') {
    try {
        const r = await call(action, { id: K.id, ...extra });
        if (r.child) K = r.child;
        if (r.message || ok) toast(r.message || ok);
        renderKid(); loadKids();
        return r;
    } catch (e) { toast(e.message, true); return null; }
}

function renderKid() {
    $('#kid-title').textContent = K.name;
    $('#kid-save').hidden = !['access', 'time'].includes(tab);
    const tabs = el('div', { class: 'seg-tabs', role: 'tablist', style: 'grid-template-columns:repeat(3,minmax(0,1fr))' },
        TABS.map(([k, label]) => el('button', { type: 'button', role: 'tab', ariaSelected: String(tab === k),
            text: label, onclick: () => { tab = k; sessionStorage.setItem('mg:tab', k); renderKid(); } })));
    const body = { sum: kidSum, access: kidAccess, time: kidTime, yt: kidYt, sites: kidSites, dev: kidDev }[tab]();
    $('#kid-body').replaceChildren(tabs, ...body.filter(Boolean));
}

function kidSum() {
    const q = K.quota_min * 60, pct = q ? Math.min(100, Math.round(K.used_sec / q * 100)) : 0;
    const tiles = [];
    if (K.status === 'pending') tiles.push(['✅', 'אישור הילד', () => act('child_status', { status: 'active' }, 'אושר')]);
    else if (K.status === 'active') tiles.push(['⏸️', 'השהיה', () => {
        if (confirm(`להשהות את ${K.name}? המכשירים שלו יתנתקו מיד.`)) act('child_status', { status: 'suspended' }, 'הושהה');
    }]);
    else tiles.push(['▶️', 'החזרה', () => act('child_status', { status: 'active' }, 'הוחזר')]);
    tiles.push(['🔄', 'איפוס הזמן של היום', () => act('usage_reset', {}, 'הזמן של היום אופס')]);
    tiles.push(['🔑', 'פרטי כניסה חדשים', async () => {
        if (!confirm('ליצור סיסמה חדשה למכשירים? המכשירים שמחוברים עכשיו יתנתקו.')) return;
        const r = await act('child_password'); if (r) showCreds(K.name, r.username, r.password);
    }]);
    tiles.push(['🗑️', 'מחיקת הילד', async () => {
        if (!confirm(`למחוק את ${K.name} לגמרי? ההגדרות וההיסטוריה שלו יימחקו, והמכשירים יתנתקו.`)) return;
        try { await call('child_delete', { id: K.id }); $('#kid-dlg').close(); loadKids(); toast('נמחק'); }
        catch (e) { toast(e.message, true); }
    }]);
    return [
        el('div', { class: 'summary' }, [
            el('div', { class: 'line' }, [
                K.allowed_now ? el('span', { class: 'chip ok', text: 'פתוח עכשיו' }) : el('span', { class: 'chip bad', text: 'חסום עכשיו' }),
                el('span', { class: 'muted', text: K.status === 'pending' ? 'ממתין לאישור שלכם — "אישור הילד" למטה'
                    : (K.allowed_now ? '' : K.reason_now) }),
            ]),
            el('div', { class: 'line' }, [el('span', { class: 'muted', text: 'היום' }), el('b', { text: mins(K.used_sec) }),
                q ? el('span', { class: 'muted', text: `מתוך ${K.quota_min} דק'` }) : el('span', { class: 'muted', text: 'בלי מכסה' }),
                q ? el('span', { class: 'pct', text: `${pct}%` }) : null]),
            q ? el('span', { class: 'track', role: 'progressbar', ariaValuemin: '0', ariaValuemax: '100', ariaValuenow: String(pct) },
                [el('span', { style: `inline-size:${pct}%` })]) : null,
            el('dl', { class: 'cells' }, [
                el('div', {}, [el('dt', { text: 'חסימות היום' }), el('dd', { text: String(K.blocked_today) })]),
                el('div', {}, [el('dt', { text: 'מכשירים' }), el('dd', { text: String(K.devices) })]),
                el('div', {}, [el('dt', { text: 'נראה' }), el('dd', { text: ago(K.last_seen_at) })]),
            ]),
        ]),
        el('div', { class: 'tiles' }, tiles.map(([ic, t, fn]) => el('button', { type: 'button', class: 'tile', onclick: fn },
            [el('span', { class: 'tile-ic', text: ic }), t]))),
    ];
}

function kidAccess() {
    const p = K.policy, C = S.catalogs;
    const hint = (map, v) => el('p', { class: 'hint', text: map[v][1] });
    const modeHint = hint(MODES, p.mode), postureHint = hint(POSTURES, p.posture);
    const cats = Object.entries(C.categories).map(([k, c]) => el('li', {}, [
        el('span', { text: c.icon, ariaHidden: 'true' }), el('span', { class: 'nm', text: c.name }),
        seg(`cat_${k}`, [['', '—'], ['allow', 'מותר'], ['deny', 'חסום']], K.categories[k] || '', { cls: 'seg mini' }),
    ]));
    return [
        el('label', { class: 'f' }, [el('span', { text: 'שם' }), el('input', { name: 'display_name', value: K.name, maxLength: 40 })]),
        group('מה הוא רואה', '', [
            seg('mode', Object.entries(MODES).map(([k, v]) => [k, v[0]]), p.mode, { cols: 2, onchange: v => { modeHint.textContent = MODES[v][1]; } }),
            modeHint]),
        group('כשאין כלל שמתאים', '', [
            seg('posture', Object.entries(POSTURES).map(([k, v]) => [k, v[0]]), p.posture, { cols: 2, onchange: v => { postureHint.textContent = POSTURES[v][1]; } }),
            postureHint]),
        group('סוגי אתרים', 'מותר / חסום לפי סוג, לא רק לפי כתובת', [el('ul', { class: 'rows' }, cats)]),
        group('לחסום תמיד', 'גם באתר שמותר', [el('div', { class: 'chips' }, Object.entries(C.types).map(([k, t]) =>
            el('label', {}, [el('input', { type: 'checkbox', name: 'types', value: k, checked: p.types.includes(k) }),
                el('span', { text: `${t.icon} ${t.name}` })])))]),
        group('פרסומות', '', [el('div', { class: 'mods' }, Object.entries(C.ads).map(([k, a]) =>
            sw('ads', `${a.icon} ${a.name}`, p.ads.includes(k), a.hint)).map((n, i) => {
                n.querySelector('input').value = Object.keys(C.ads)[i]; return n; }))]),
        group('עוד', '', [el('div', { class: 'mods' }, [
            sw('allow_downloads', '⬇️ הורדת קבצים', p.allow_downloads),
            sw('allow_pip', '🖼️ חלון צף לסרטונים', p.allow_pip),
            sw('allow_background', '🎧 המשך ניגון ברקע', p.allow_background),
            sw('block_screenshots', '📵 חסימת צילום מסך', p.block_screenshots),
        ])]),
    ];
}

function kidTime() {
    const p = K.policy;
    return [
        el('p', { class: 'hint', text: 'מתי ובכמה זמן. ריק = בלי הגבלה.' }),
        group('ימים', '', [el('div', { class: 'days' }, DAYS.map((d, i) => el('label', {}, [
            el('input', { type: 'checkbox', name: 'days', value: String(i), checked: p.days.includes(i), ariaLabel: `יום ${d}` }),
            el('span', { text: d })])))]),
        group('שעות', 'חלון שחוצה חצות — גם עובד', [el('div', { class: 'fields two' }, [
            el('label', { class: 'f' }, [el('span', { text: 'מ-' }), el('input', { type: 'time', name: 'window_start', value: p.window_start, dir: 'ltr' })]),
            el('label', { class: 'f' }, [el('span', { text: 'עד' }), el('input', { type: 'time', name: 'window_end', value: p.window_end, dir: 'ltr' })]),
        ])]),
        group('מכסה', 'בדקות', [el('div', { class: 'fields two' }, [
            el('label', { class: 'f' }, [el('span', { text: 'ליום' }), el('input', { type: 'number', name: 'daily_quota_min', min: 0, max: 1440,
                value: p.daily_quota_min || '', inputMode: 'numeric', dir: 'ltr', placeholder: 'בלי' })]),
            el('label', { class: 'f' }, [el('span', { text: 'לישיבה אחת' }), el('input', { type: 'number', name: 'session_max_min', min: 0, max: 1440,
                value: p.session_max_min || '', inputMode: 'numeric', dir: 'ltr', placeholder: 'בלי' })]),
        ])]),
    ];
}

$('#kid-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, checked = n => [...f.querySelectorAll(`[name=${n}]:checked`)].map(i => i.value);
    let policy;
    if (tab === 'access') {
        const categories = {};
        for (const k of Object.keys(S.catalogs.categories)) {
            const v = f.querySelector(`[name=cat_${k}]:checked`)?.value; if (v) categories[k] = v;
        }
        policy = { display_name: f.display_name.value, mode: f.querySelector('[name=mode]:checked').value,
            posture: f.querySelector('[name=posture]:checked').value, categories, types: checked('types'), ads: checked('ads'),
            allow_downloads: f.allow_downloads.checked, allow_pip: f.allow_pip.checked,
            allow_background: f.allow_background.checked, block_screenshots: f.block_screenshots.checked };
    } else if (tab === 'time') {
        policy = { days: checked('days').map(Number), window_start: f.window_start.value, window_end: f.window_end.value,
            daily_quota_min: Number(f.daily_quota_min.value || 0), session_max_min: Number(f.session_max_min.value || 0) };
    } else return;
    await act('child_save', { policy }, 'נשמר — המכשיר יתעדכן תוך דקה');
});

function kidYt() {
    const y = K.youtube, allowed = y.allowed_modes;
    const hint = el('p', { class: 'hint', text: YT[y.mode][1] });
    const mode = seg('yt_mode', Object.entries(YT).map(([k, v]) => [k, v[0]]), y.mode,
        { cols: 3, disabled: Object.keys(YT).filter(k => !allowed.includes(k)), onchange: v => { hint.textContent = YT[v][1]; } });
    const search = el('input', { type: 'checkbox', checked: y.allow_search }), shorts = el('input', { type: 'checkbox', checked: y.allow_shorts });
    const input = el('input', { dir: 'ltr', placeholder: '@ChannelName או קישור', autocapitalize: 'off', ariaLabel: 'ערוץ, סרטון או פלייליסט' });
    const items = y.items.length ? el('ul', { class: 'rows' }, y.items.map(i => el('li', {}, [
        el('span', { text: i.action === 'deny' ? '🚫' : '✅', ariaHidden: 'true' }),
        el('span', { class: 'nm' }, [i.label || (i.kind === 'handle' ? '@' + i.item_id : i.item_id),
            el('small', { text: `${KIND[i.kind] || i.kind} · ${i.action === 'deny' ? 'חסום' : 'מותר'}` })]),
        el('button', { type: 'button', class: 'icon-btn', text: '🗑️', ariaLabel: 'הסרה', onclick: () => act('yt_del', { item_id: i.id }, 'הוסר') }),
    ]))) : el('p', { class: 'empty', text: 'הרשימה ריקה. הוסיפו ערוץ — מספיק @שם-הערוץ.' });
    const action = seg('yt_item_action', [['allow', '✅ להתיר'], ['deny', '🚫 לחסום']], 'allow', { cols: 2 });
    return [
        allowed.length === 1 ? el('div', { class: 'banner info' }, [el('span', { text: 'ℹ️' }),
            el('div', { text: 'יוטיוב לא פתוח בחשבון שלכם. אפשר לבקש ממנהל המערכת.' })]) : null,
        group('יוטיוב', '', [mode, hint,
            el('div', { class: 'mods' }, [
                el('label', { class: 'switch' }, [search, el('span', { text: '🔎 חיפוש ביוטיוב' })]),
                el('label', { class: 'switch' }, [shorts, el('span', {}, ['📱 פיד Shorts',
                    el('small', { text: 'במצב "פתוח". בערוצים שאישרתם ה-Shorts שלהם תמיד מוצגים',
                        style: 'display:block;color:var(--muted);font-size:.78rem' })])]),
            ]),
            el('button', { type: 'button', class: 'btn primary', text: 'שמירת יוטיוב', onclick: () => act('youtube', {
                mode: $('[name=yt_mode]:checked').value, allow_search: search.checked, allow_shorts: shorts.checked }, 'נשמר') }),
        ]),
        group('ערוצים וסרטונים', 'מה שאישרתם — מופיע לו כאריח', [items,
            el('label', { class: 'f' }, [el('span', { text: 'הוספה' }), input]), action,
            el('button', { type: 'button', class: 'btn ghost', text: '+ הוספה לרשימה', onclick: async () => {
                if (await act('yt_add', { input: input.value, item_action: $('[name=yt_item_action]:checked').value })) input.value = '';
            } }),
        ]),
    ];
}

function kidSites() {
    const rows = K.rules.length ? el('ul', { class: 'rows' }, K.rules.map(r => el('li', { style: r.enabled ? '' : 'opacity:.55' }, [
        el('span', { text: r.action === 'deny' ? '🚫' : '✅', ariaHidden: 'true' }),
        el('span', { class: 'nm' }, [r.label || r.pattern,
            el('small', { text: `${r.label ? r.pattern + ' · ' : ''}${SCOPES[r.scope] || r.scope}${r.enabled ? '' : ' · מושבת'}`
                + (r.expires_at ? ` · עד ${new Date(r.expires_at).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' })}` : '') })]),
        el('button', { type: 'button', class: 'icon-btn', text: r.enabled ? '⏸️' : '▶️', ariaLabel: r.enabled ? 'השבתה' : 'הפעלה',
            onclick: () => act('rule_toggle', { rule_id: r.id }) }),
        el('button', { type: 'button', class: 'icon-btn', text: '🗑️', ariaLabel: 'מחיקה', onclick: () => {
            if (confirm(`למחוק את ${r.label || r.pattern}?`)) act('rule_del', { rule_id: r.id }, 'נמחק');
        } }),
    ]))) : el('p', { class: 'empty', text: 'עוד אין אתרים. הוסיפו כתובת — היא תופיע לו כאריח.' });

    const pattern = el('input', { dir: 'ltr', placeholder: 'wikipedia.org', autocapitalize: 'off', inputMode: 'url' });
    const label = el('input', { placeholder: 'ויקיפדיה', maxLength: 80 });
    const scope = el('select', {}, Object.entries(SCOPES).map(([k, v]) => el('option', { value: k, text: v, selected: k === 'domain' })));
    const action = seg('rule_action', [['allow', '✅ להתיר'], ['deny', '🚫 לחסום']], 'allow', { cols: 2 });
    return [
        el('p', { class: 'hint', text: 'כתובת שאתם מתירים — מופיעה כאריח במסך הבית שלו. כלל שאתם כותבים גובר על "סוגי אתרים".' }),
        rows,
        group('אתר חדש', '', [
            el('div', { class: 'fields two' }, [el('label', { class: 'f' }, [el('span', { text: 'כתובת *' }), pattern]),
                el('label', { class: 'f' }, [el('span', { text: 'שם' }), label])]),
            el('label', { class: 'f' }, [el('span', { text: 'מה נפתח' }), scope]),
            action,
            el('button', { type: 'button', class: 'btn primary', text: '+ הוספה', onclick: async () => {
                const r = await act('rule_add', { pattern: pattern.value, label: label.value, scope: scope.value,
                    rule_action: $('[name=rule_action]:checked').value, show_tile: true });
                if (r) { pattern.value = ''; label.value = ''; }
            } }),
        ]),
    ];
}

function kidDev() {
    const list = K.device_list.length ? el('ul', { class: 'rows' }, K.device_list.map(d => el('li', {}, [
        el('span', { text: '📱', ariaHidden: 'true' }),
        el('span', { class: 'nm' }, [d.name || 'מכשיר', el('small', { text: ago(d.last_seen_at) })]),
        el('button', { type: 'button', class: 'icon-btn', text: '✕', ariaLabel: 'ניתוק', onclick: () => {
            if (confirm(`לנתק את ${d.name || 'המכשיר'}?`)) act('device_del', { device_id: d.id }, 'נותק');
        } }),
    ]))) : el('p', { class: 'empty', text: 'אין מכשיר מחובר.' });
    return [
        list,
        group('חיבור מכשיר', '', [
            el('button', { type: 'button', class: 'btn primary', text: '📷 חיבור מכשיר חדש', onclick: () => openPair() }),
            el('p', { class: 'hint', text: 'מציגים קוד, והמכשיר של הילד סורק אותו — בלי סיסמה.' }),
            el('details', {}, [el('summary', { class: 'muted', text: 'או בשם משתמש וסיסמה (מכשיר ישן)' }),
                el('ol', { class: 'steps' }, [
                    el('li', {}, ['באפליקציה: "כניסה עם שם משתמש", ושם המשתמש ', el('code', { text: K.username, dir: 'ltr' }), '.']),
                    el('li', { text: 'הסיסמה — זו שקיבלתם, או "פרטי כניסה חדשים" בלשונית סיכום.' }),
                ])]),
        ]),
    ];
}

/* ── חיבור מכשיר (QR) ──────────────────────────────────────────── */

let pairTimer = null;

async function openPair() {
    // לפי מזהים ולא לפי מספר: מכשיר חדש יכול להחליף ישן (מכסת מכשירים לילד), והמספר לא ישתנה.
    const kid = K, before = new Set(K.device_list.map(d => d.id));
    let r;
    try { r = await call('pair_create', { id: kid.id }); } catch (e) { toast(e.message, true); return; }

    const qr = qrcode(0, 'M');
    qr.addData(r.qr);
    qr.make();
    const svg = new DOMParser().parseFromString(qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true }), 'image/svg+xml').documentElement;
    svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'קוד QR לחיבור המכשיר');
    svg.style.cssText = 'inline-size:min(72vw,280px);block-size:auto;background:#fff;border-radius:10px';

    const left = el('p', { class: 'hint', style: 'text-align:center' });
    const pretty = r.code.slice(0, 4) + '-' + r.code.slice(4);
    $('#pair-title').textContent = `חיבור מכשיר — ${kid.name}`;
    $('#pair-body').replaceChildren(
        el('ol', { class: 'steps' }, [
            el('li', { text: 'במכשיר של הילד: פותחים את מגדלור.' }),
            el('li', { text: 'לוחצים "📷 סריקת קוד" ומכוונים למסך הזה.' }),
        ]),
        el('div', { style: 'display:grid;justify-items:center;gap:8px' }, [svg,
            el('p', { class: 'muted', text: 'או מקלידים את הקוד:' }),
            el('div', { class: 'pw' }, [el('code', { text: pretty })])]),
        left,
    );
    const d = $('#pair-dlg'); if (!d.open) d.showModal();

    clearInterval(pairTimer);
    const until = new Date(r.expires_at).getTime();
    let ticks = 0;
    pairTimer = setInterval(async () => {
        if (!d.open) { clearInterval(pairTimer); return; }
        const sec = Math.max(0, Math.round((until - Date.now()) / 1000));
        left.textContent = sec ? `הקוד תקף עוד ${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}` : 'פג תוקף הקוד — סגרו ופתחו שוב.';
        if (!sec) { clearInterval(pairTimer); return; }
        if (++ticks % 3) return;   // בדיקה כל 3 שניות: האם המכשיר כבר הצטרף
        try {
            const c = (await call('child', { id: kid.id })).child;
            const fresh = c.device_list.find(x => !before.has(x.id));
            if (fresh) {
                clearInterval(pairTimer); d.close();
                if (K && K.id === c.id) { K = c; renderKid(); }
                loadKids(); toast(`המכשיר חובר ✓ ${fresh.name || ''}`);
            }
        } catch { /* ננסה שוב בעוד רגע */ }
    }, 1000);
}

/* ── ילד חדש ───────────────────────────────────────────────────── */

$('#kid-new').addEventListener('click', () => {
    const name = el('input', { required: true, maxLength: 40, placeholder: 'נועה' });
    const err = el('p', { class: 'error', role: 'alert', hidden: true });
    const go = el('button', { type: 'button', class: 'btn primary', text: 'הוספה' });
    go.addEventListener('click', async () => {
        err.hidden = true; go.disabled = true;
        try {
            const r = await call('child_add', { name: name.value });
            loadKids(); showCreds(name.value.trim(), r.username, r.password, r.id);
        } catch (x) { err.textContent = x.message; err.hidden = false; }
        go.disabled = false;
    });
    $('#add-title').textContent = 'ילד חדש';
    $('#add-body').replaceChildren(
        el('p', { class: 'hint', text: 'מתחילים סגור: הכול חסום עד שתתירו. אחרי ההוספה תקבלו פרטי כניסה למכשיר שלו.' }),
        el('label', { class: 'f' }, [el('span', { text: 'שם *' }), name]), err, go);
    $('#add-dlg').showModal(); name.focus();
});

function showCreds(name, username, password, newId = 0) {
    const copy = text => async () => {
        try { await navigator.clipboard.writeText(text); toast('הועתק'); } catch { toast('סמנו והעתיקו ידנית', true); }
    };
    $('#add-title').textContent = `פרטי כניסה — ${name}`;
    $('#add-body').replaceChildren(
        newId ? el('button', { type: 'button', class: 'btn primary', text: '📷 חיבור המכשיר שלו עכשיו', onclick: async () => {
            $('#add-dlg').close(); await openKid(newId); if (K) openPair();
        } }) : null,
        el('p', { class: 'hint', text: newId ? 'או, במכשיר ישן — כניסה עם שם משתמש. הפרטים מוצגים פעם אחת:'
            : 'מוצגים פעם אחת. במכשיר של הילד: "כניסה עם שם משתמש", ואז:' }),
        el('div', { class: 'cred' }, [
            el('div', {}, [el('span', { class: 'muted', text: 'שם משתמש' }), el('code', { text: username }),
                el('button', { type: 'button', class: 'icon-btn', text: '📋', ariaLabel: 'העתקת שם המשתמש', onclick: copy(username) })]),
            el('div', {}, [el('span', { class: 'muted', text: 'סיסמה' }), el('code', { text: password }),
                el('button', { type: 'button', class: 'icon-btn', text: '📋', ariaLabel: 'העתקת הסיסמה', onclick: copy(password) })]),
        ]),
        el('p', { class: 'hint', text: 'הילד מתחיל עם הכול חסום. פתחו לו אתרים וערוצים בגיליון שלו.' }),
        el('button', { type: 'button', class: 'btn ghost', text: 'סגירה', onclick: () => $('#add-dlg').close() }));
    if (!$('#add-dlg').open) $('#add-dlg').showModal();
}

/* ── בקשות גישה (שלב 6) ─────────────────────────────────────────── */

const RSCOPE = { page: ['📄 הדף הזה', 'רק הכתובת שהוא ביקש.'], site: ['🌐 כל האתר', 'כל הדפים באתר, ויופיע לו כאריח.'],
                 hour: ['⏳ לשעה', 'כל האתר, לשעה אחת. אחר כך נסגר לבד.'] };
const RSCOPE_YT = { page: ['🎬 הסרטון הזה', 'רק הסרטון שביקש.'], site: ['📺 כל הערוץ', 'כל הסרטונים של הערוץ — נוסף לרשימת יוטיוב.'] };
let R = [];

async function loadRequests() {
    try { R = (await call('requests', {})).requests; } catch (e) { return; }
    renderRequests();
}

function renderRequests() {
    const open = R.filter(r => r.status === 'pending'), done = R.filter(r => r.status !== 'pending');
    $('#requests').hidden = !open.length;
    $('#req-count').textContent = String(open.length);
    $('#req-list').replaceChildren(...open.map(r => el('li', { class: 'card req' }, [
        el('span', { class: 'avatar', style: `--h:${hue(r.child)}`, ariaHidden: 'true', text: initials(r.child) }),
        el('div', { class: 'body' }, [
            el('div', { class: 'l1' }, [el('span', { text: r.child }), el('span', { class: 't', text: ago(r.created_at).replace('מחובר עכשיו', 'עכשיו') })]),
            el('div', { class: 'l2', dir: 'ltr', style: 'text-align:end', text: r.host }),
            r.note ? el('div', { class: 'l2', text: `"${r.note}"` }) : null,
        ]),
        el('button', { type: 'button', class: 'icon-btn', text: '✕', ariaLabel: `דחייה — ${r.host}`, onclick: () => decide(r, 'deny') }),
        el('button', { type: 'button', class: 'btn primary sm', text: '✓ אישור', onclick: () => openDecide(r) }),
    ])));
    $('#req-recent').hidden = !done.length;
    $('#req-done').replaceChildren(...done.slice(0, 15).map(r => el('li', { class: 'card', style: 'cursor:default' }, [
        el('span', { text: r.status === 'approved' ? '✅' : '🚫', ariaHidden: 'true' }),
        el('div', { class: 'body' }, [
            el('div', { class: 'l1' }, [el('span', { text: `${r.child} · ${r.host}` })]),
            el('div', { class: 'l2', text: r.status === 'approved'
                ? `אושר — ${((r.scopes.length === 2 ? RSCOPE_YT : RSCOPE)[r.scope] || [r.scope])[0]}` : 'נדחה' }),
        ]),
    ])));
}

function openDecide(r) {
    const yt = r.scopes.length === 2, map = yt ? RSCOPE_YT : RSCOPE;
    const hint = el('p', { class: 'hint', text: map[r.scopes[0]][1] });
    const pick = seg('req_scope', r.scopes.map(k => [k, map[k][0]]), r.scopes[0],
        { cols: r.scopes.length, onchange: v => { hint.textContent = map[v][1]; } });
    $('#req-dlg-title').textContent = `${r.child} מבקש/ת`;
    $('#req-dlg-body').replaceChildren(
        el('p', { class: 'req-url', dir: 'ltr', text: r.url }),
        r.note ? el('p', { class: 'hint', text: `למה: "${r.note}"` }) : null,
        group('מה לפתוח', '', [pick, hint]),
        el('div', { class: 'row-btns' }, [
            el('button', { type: 'button', class: 'btn ghost', text: '✕ דחייה', onclick: () => { $('#req-dlg').close(); decide(r, 'deny'); } }),
            el('button', { type: 'button', class: 'btn primary', text: '✓ אישור', onclick: () => {
                $('#req-dlg').close(); decide(r, 'approve', $('[name=req_scope]:checked').value); } }),
        ]));
    if (!$('#req-dlg').open) $('#req-dlg').showModal();
}

async function decide(r, decision, scope = 'page') {
    try {
        const res = await call('request_decide', { req: r.id, decision, scope });
        R = res.requests; renderRequests(); loadKids(); toast(res.message);
    } catch (e) { toast(e.message, true); loadRequests(); }
}

/** נפתח מהתראה: ‏?req=ID (ו-&d=approve|deny מהכפתורים שבהתראה). */
function fromNotification() {
    const q = new URLSearchParams(location.search), id = Number(q.get('req'));
    if (!id) return;
    history.replaceState(null, '', location.pathname);
    const r = R.find(x => x.id === id);
    if (!r) return;
    if (r.status !== 'pending') { toast(r.status === 'approved' ? 'כבר אושר' : 'כבר נדחה'); return; }
    if (q.get('d') === 'deny') decide(r, 'deny'); else openDecide(r);
}

// כשהאפליקציה פתוחה — רענון כל חצי דקה, ומיד כשחוזרים אליה.
setInterval(() => { if (S.me && !document.hidden) loadRequests(); }, 30000);
document.addEventListener('visibilitychange', () => { if (S.me && !document.hidden) { loadRequests(); loadKids(); } });
navigator.serviceWorker?.addEventListener('message', e => {
    if (e.data?.type === 'open' && e.data.url) { history.replaceState(null, '', e.data.url); loadRequests().then(fromNotification); }
});

/* ── התראות לטלפון ─────────────────────────────────────────────── */

const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const b64 = s => Uint8Array.from(atob((s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));

async function pushSub() {
    if (!pushSupported()) return null;
    const reg = await navigator.serviceWorker.ready;
    return reg.pushManager.getSubscription();
}

async function pushOn() {
    if (!pushSupported()) {
        const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
        toast(ios ? 'באייפון: קודם "הוספה למסך הבית", ואז מפעילים מהאפליקציה' : 'הדפדפן הזה לא תומך בהתראות', true);
        return false;
    }
    if (await Notification.requestPermission() !== 'granted') { toast('ההתראות חסומות בהגדרות הדפדפן', true); return false; }
    try {
        const { key } = await call('push_key', {});
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription()
            || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(key) });
        await call('push_sub', { endpoint: sub.endpoint });
        toast('🔔 ההתראות פועלות — תשמעו כשילד מבקש גישה');
        return true;
    } catch (e) { toast(e.message || 'ההפעלה נכשלה', true); return false; }
}

async function pushOff() {
    const sub = await pushSub();
    if (sub) { try { await call('push_unsub', { endpoint: sub.endpoint }); } catch { } await sub.unsubscribe(); }
    toast('ההתראות כובו');
}

async function renderPushNudge() {
    const box = $('#push-nudge');
    if (!pushSupported() || Notification.permission === 'denied' || await pushSub() || localStorage.getItem('mg:nudge') === 'no') {
        box.replaceChildren(); return;
    }
    box.replaceChildren(el('div', { class: 'banner info' }, [el('span', { text: '🔔', ariaHidden: 'true' }), el('div', {}, [
        el('b', { text: 'התראה כשילד מבקש גישה' }), 'בלי זה תראו בקשות רק כשתפתחו את האפליקציה.',
        el('div', { class: 'row-btns', style: 'margin-top:8px' }, [
            el('button', { type: 'button', class: 'btn ghost sm', text: 'לא עכשיו', onclick: () => {
                try { localStorage.setItem('mg:nudge', 'no'); } catch { } box.replaceChildren(); } }),
            el('button', { type: 'button', class: 'btn primary sm', text: 'הפעלה', onclick: async () => { if (await pushOn()) box.replaceChildren(); } }),
        ])])]));
}

$('#m-push').addEventListener('click', async () => {
    $('#menu-dlg').close();
    if (await pushSub()) { if (confirm('לכבות את ההתראות בטלפון הזה?')) await pushOff(); }
    else await pushOn();
    renderPushNudge();
});

/* ── תפריט והתקנה ─────────────────────────────────────────────── */

let installPrompt = null;
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); installPrompt = e; });
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

$('#menu-btn').addEventListener('click', () => {
    $('#pass-box').hidden = true; $('#install-box').hidden = true;
    $('#m-install').hidden = matchMedia('(display-mode: standalone)').matches;
    pushSub().then(s => { $('#m-push').textContent = s ? '🔕 כיבוי ההתראות לטלפון' : '🔔 התראות לטלפון'; }).catch(() => {});
    $('#menu-dlg').showModal();
});
$('#m-install').addEventListener('click', async () => {
    if (installPrompt) { installPrompt.prompt(); await installPrompt.userChoice; installPrompt = null; $('#menu-dlg').close(); return; }
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    $('#install-steps').replaceChildren(...(ios
        ? ['בספארי: כפתור השיתוף (ריבוע עם חץ למעלה)', 'גללו ובחרו "הוספה למסך הבית"', 'לחצו "הוספה"']
        : ['בכרום: תפריט ⋮ למעלה', 'בחרו "התקנת אפליקציה" (או "הוספה למסך הבית")', 'אשרו']).map(t => el('li', { text: t })));
    $('#install-box').hidden = false;
});
$('#m-pass').addEventListener('click', () => { $('#pass-box').hidden = false; $('#p-cur').focus(); });
$('#m-out').addEventListener('click', async () => { try { await call('logout', {}); } finally { location.reload(); } });
$('#p-go').addEventListener('click', async () => {
    try {
        await call('password', { current: $('#p-cur').value, next: $('#p-new').value });
        $('#p-cur').value = $('#p-new').value = ''; $('#menu-dlg').close(); toast('הסיסמה הוחלפה');
    } catch (x) { toast(x.message, true); }
});

boot();
