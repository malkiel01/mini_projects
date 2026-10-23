/*
 * מגדלור — אפליקציית ההורה (SPEC.md, סעיף 10).
 *
 * מסך בית עם בקשות הגישה והילדים, וגיליון לכל ילד: סיכום · פעילות · גישה · זמנים · יוטיוב · אתרים · מכשירים.
 * כל הטקסט מוצב כ-textContent — שמות וכתובות מגיעים מבחוץ. כל שינוי נושא X-Migdalor ו-X-CSRF.
 */
'use strict';

const API = 'api.php';
const APK = '../download.php';   // מהשרת שלנו, בלי ההפניה של GitHub
const DAYS = ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש'];
const MODES = { kiosk: ['🧩 אריחים בלבד', 'רק האתרים והערוצים שאישרתם, כאריחים. אין שורת כתובת.'],
                browser: ['🌐 דפדפן', 'יש שורת כתובת. מה שנפתח בה — לפי מה שקבעתם כאן.'] };
const POSTURES = { deny_all: ['🔒 הכול חסום', 'שום דבר לא נפתח עד שאתם מתירים. הבחירה הבטוחה. 🔎 החיפוש — רק באתרים ובערוצים שאישרתם.'],
                   allow_all: ['🔓 הכול פתוח', 'האינטרנט פתוח, חוץ ממה שאתם חוסמים. 🔎 החיפוש — ברשת, וכל תוצאה מסוננת.'] };
const YT = { off: ['⛔ כבוי', 'יוטיוב לא נפתח בכלל.'],
             learn: ['🎓 למידה', 'רק הסרטונים שהוספתם לתור, לפי הסדר — כאריחים במסך הבית. בלי Shorts, חיפוש, ערוצים, המלצות וניגון אוטומטי.'],
             restricted: ['✅ רק מה שאישרתי', 'רק הערוצים והסרטונים שברשימה. בלי דף הבית ובלי המלצות.'],
             full: ['▶️ פתוח', 'כל יוטיוב, חוץ ממה שתחסמו ברשימה.'] };
const SCOPES = { domain: 'כל האתר', exact: 'הדף הזה בלבד', domain_plus: 'האתר + מה שהוא טוען', resource: 'משאב בתוך דף (משימה)' };
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
    // מחוברת לפלטפורמה, והזהות עוד לא מקושרת להורה — המסך החד-פעמי.
    if (!me.me && me.link) return showOnly('#linkacc', () => $('#link-form').login.focus());
    if (!me.me) return showOnly('#auth', () => $('#login-form').login.focus());
    Object.assign(S, { me: me.me, csrf: me.csrf, account: me.account, catalogs: me.catalogs, platform: me.platform });
    // מחוברת: הסיסמה והיציאה שייכות לכלים הדיגיטליים.
    $('#m-pass').hidden = $('#m-out').hidden = !!me.platform;
    $('#m-link').hidden = !me.platform;
    if (me.me.must_change) return showOnly('#newpass', () => $('#newpass-form').current.focus());
    // משוחרר — הפאנל נעול. אין טעם להגדיר דבר כשהאכיפה כבויה.
    if (S.account.expiry === 'released') return renderReleaseLock();
    showOnly('#app');
    $('#family').textContent = `${S.account.name} · ${S.me.name || S.me.login}`;
    renderBanner();
    loadKids();
    await loadRequests();
    fromNotification();
    renderPushNudge();
}

function showOnly(sel, then) {
    for (const s of ['#auth', '#linkacc', '#newpass', '#released', '#app']) $(s).hidden = s !== sel;
    if (then) then();
}

/* ── משוחרר: מסך נעילה (SPEC סעיף 5) ───────────────────────────── */

async function releaseChildCount() {
    try { return (await call('children', {})).children.length; } catch { return null; }
}

function renderReleaseLock() {
    showOnly('#released');
    $('#released-sub').textContent = `${S.account.name} — תוקף המנוי הסתיים.`;
    releaseChildCount().then(n => {
        const btn = $('#released-cleanup');
        if (n === 0) { btn.disabled = true; btn.textContent = 'אין חשבונות ילדים למחוק'; }
        else if (n != null) btn.textContent = `מחיקת ${n} חשבונות הילדים`;
    });
    $('#released-renew').onclick = () => {
        const h = $('#released-renew-help');
        h.textContent = 'הסדרת המנוי נעשית מול מנהל המערכת. אחרי ההסדרה — הפאנל והאכיפה חוזרים לבד בפעימה הבאה של המכשיר.';
        h.hidden = false;
    };
    $('#released-cleanup').onclick = async () => {
        if (!confirm('למחוק את כל חשבונות הילדים? מגדלור יוסר מהמכשירים שלהם, והם יחזרו לטלפון רגיל. אי אפשר לבטל.')) return;
        try {
            const r = await call('release_cleanup', { confirm: true });
            alert(`נמחקו ${r.deleted} חשבונות. המכשירים יסירו את מגדלור בפעימה הבאה.`);
            location.reload();
        } catch (e) { toast(e.message, true); }
    };
    $('#released-logout').hidden = !!S.platform;   // מחוברת — היציאה מהכלים הדיגיטליים
    $('#released-logout').onclick = async () => { try { await call('logout', {}); } catch {} location.reload(); };
}

$('#login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, err = $('.error', f), btn = $('button', f);
    err.hidden = true; btn.disabled = true;
    try { await call('login', { login: f.login.value, password: f.password.value }); location.reload(); }
    catch (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; }
});

$('#link-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, err = $('.error', f), btn = $('button', f);
    err.hidden = true; btn.disabled = true;
    try { await call('link', { login: f.login.value, password: f.password.value }); location.reload(); }
    catch (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; }
});
$('#link-back').addEventListener('click', () => location.reload());

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
    const until = a.expires_at ? new Date(a.expires_at + 'T12:00:00Z').toLocaleDateString('he-IL') : '';
    const msg = a.status === 'suspended' ? ['bad', '⏸️', 'החשבון מושעה', 'המכשירים של הילדים חסומים. פנו למנהל המערכת.']
        : a.status === 'pending' ? ['warn', '⏳', 'החשבון ממתין לאישור', 'אפשר להגדיר כבר עכשיו; המכשירים ייפתחו אחרי האישור.']
        // חסד: התוקף עבר אך השמירה עדיין פעילה לכמה ימים. פס אדום — להסדיר עכשיו.
        : a.expiry === 'grace' ? ['bad', '⚠️', `תוקף החשבון הסתיים (${until})`,
            'השמירה על הילדים עדיין פעילה, אך רק לעוד כמה ימים. הסדירו מול מנהל המערכת כדי שהיא לא תיפסק.']
        // אזהרה: פס צהוב, 7 ימים לפני.
        : a.expiry === 'soon' ? ['warn', '⏰', 'תוקף החשבון מסתיים בקרוב',
            `עד ${until}. פנו למנהל המערכת להארכה.`]
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
                el('div', { class: 'l2', text: (k.requests ? `🙋 ${k.requests} · ` : '') + (k.perms_bad ? '🛡️ · ' : '') + (k.outdated ? '⬆️ · ' : '') + `⏱️ היום ${mins(k.used_sec)}${quota}`
                    + (k.blocked_today ? ` · 🚫 ${k.blocked_today} חסימות` : '') + ` · 📱 ${k.devices}` }),
            ]),
            chip,
        ]);
    }));
}

/* ── גיליון ילד ────────────────────────────────────────────────── */

let K = null, tab = 'sum';
const TABS_BASE = [['sum', '📋 סיכום'], ['act', '📊 פעילות'], ['access', '🔐 גישה'], ['time', '⏰ זמנים'],
                   ['yt', '▶️ יוטיוב'], ['sites', '🌐 אתרים'], ['dev', '📱 מכשירים'], ['hist', '🕓 שינויים']];
// לשונית "אפליקציות" רק לחשבון ברמת אפליקציות/מכשיר (המפתח פותח).
const appsAllowed = () => S.account && (S.account.level_max === 'apps' || S.account.level_max === 'device');
const tabsFor = () => appsAllowed()
    ? [...TABS_BASE.slice(0, 6), ['apps', '📲 אפליקציות'], ...TABS_BASE.slice(6)] : TABS_BASE;

async function openKid(id) {
    try { K = (await call('child', { id })).child; } catch (e) { toast(e.message, true); return; }
    A = null; AP = null;
    tab = sessionStorage.getItem('mg:tab') || 'sum';
    if (!tabsFor().some(([k]) => k === tab)) tab = 'sum';
    renderKid();
    const d = $('#kid-dlg'); if (!d.open) d.showModal();
}

async function act(action, extra = {}, ok = '') {
    try {
        const r = await call(action, { id: K.id, ...extra });
        if (r.child) K = r.child;
        if (r.tasks) { TS = r.tasks; TK = K.id; }
        HK = 0;   // שינוי — ההיסטוריה תיטען מחדש
        if (r.message || ok) toast(r.message || ok);
        renderKid(); loadKids();
        return r;
    } catch (e) { toast(e.message, true); return null; }
}

function renderKid() {
    $('#kid-title').textContent = K.name;
    $('#kid-save').hidden = !['access', 'time'].includes(tab);
    const T = tabsFor();
    const tabs = el('div', { class: 'seg-tabs', role: 'tablist', style: `grid-template-columns:repeat(4,minmax(0,1fr))` },
        T.map(([k, label]) => el('button', { type: 'button', role: 'tab', ariaSelected: String(tab === k),
            text: label, onclick: () => { tab = k; sessionStorage.setItem('mg:tab', k); renderKid(); } })));
    const body = { sum: kidSum, act: kidAct, access: kidAccess, time: kidTime, yt: kidYt, sites: kidSites, dev: kidDev, apps: kidApps,
                   hist: kidHist }[tab]();
    $('#kid-body').replaceChildren(tabs, ...body.filter(Boolean));
}

/*
 * שינויים (שלב 12 א+ז): כל גרסה של המדיניות — מי ומתי ומה השתנה, ולכל מכשיר קבלה: התקבל / הוחל / אומת / נכשל
 * (עם שעה וסיבה) או "הוחלפה" לפני שהגיעה. השוואה לגרסה הנוכחית ושחזור (כגרסה חדשה). lib/history.php.
 */
let H = null, HK = 0;
const RECEIPT = { pending: ['⏳', 'ממתין למכשיר'], received: ['📥', 'התקבל'], applied: ['☑️', 'הוחל'],
                  verified: ['✅', 'אומת'], failed: ['❌', 'נכשל'], superseded: ['⏭️', 'הוחלפה לפני שהגיעה'] };
const whenStr = iso => iso ? new Date(iso).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

function kidHist() {
    if (HK !== K.id) {
        HK = K.id; H = null;
        call('history', { id: K.id }).then(r => { H = r.history; if (tab === 'hist') renderKid(); })
            .catch(e => toast(e.message, true));
    }
    if (!H) return [el('p', { class: 'hint', text: 'טוען…' })];
    if (!H.length) return [el('p', { class: 'empty', text: 'עוד לא נרשמו שינויים. כל שינוי מעכשיו יופיע כאן, עם מה שהמכשיר עשה איתו.' })];
    return [el('ul', { class: 'rows' }, H.map(v => el('li', { style: 'display:block' }, [
        el('div', { class: 'nm' }, [
            el('b', { text: `${whenStr(v.at)} · ${v.by}${v.current ? ' · נוכחית' : ''}` }),
            ...v.changes.slice(0, 8).map(t => el('small', { text: t })),
            v.changes.length > 8 ? el('small', { text: `ועוד ${v.changes.length - 8}…` }) : null,
            ...v.devices.map(d => {
                const [ic, label] = RECEIPT[d.state] || ['', d.state];
                const when = d.applied_at ? ` ${whenStr(d.applied_at)}` : d.received_at ? ` ${whenStr(d.received_at)}` : '';
                return el('small', { text: `${ic} ${d.device || 'מכשיר'}: ${label}${when}${d.detail ? ' — ' + d.detail : ''}`,
                    style: d.state === 'failed' ? 'color:var(--danger)' : 'color:var(--muted)' });
            }),
        ]),
        v.current ? null : el('div', { class: 'row-btns' }, [
            el('button', { type: 'button', class: 'btn ghost sm', text: '🔍 מה ישתנה', onclick: () => compareVersion(v) }),
            el('button', { type: 'button', class: 'btn sm', text: '↩️ שחזור', onclick: () => restoreVersion(v) }),
        ]),
    ])))];
}

async function compareVersion(v) {
    try {
        const r = await call('history_compare', { id: K.id, version: v.id });
        alert(r.changes.length ? `שחזור לגרסה מ-${whenStr(v.at)} ישנה:\n\n${r.changes.join('\n')}` : 'זהה למצב הנוכחי.');
    } catch (e) { toast(e.message, true); }
}

async function restoreVersion(v) {
    let r;
    try { r = await call('history_compare', { id: K.id, version: v.id }); } catch (e) { toast(e.message, true); return; }
    if (!r.changes.length) { toast('זהה למצב הנוכחי'); return; }
    if (!confirm(`לשחזר את המצב מ-${whenStr(v.at)}?\n\n${r.changes.slice(0, 15).join('\n')}\n\nהיתרים זמניים לא ישתנו.`)) return;
    try {
        const res = await call('history_restore', { id: K.id, version: v.id });
        K = res.child; H = res.history; toast(res.message); renderKid(); loadKids();
    } catch (e) { toast(e.message, true); }
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
        enforceCard(K.enforcement),
        el('div', { class: 'tiles' }, tiles.map(([ic, t, fn]) => el('button', { type: 'button', class: 'tile', onclick: fn },
            [el('span', { class: 'tile-ic', text: ic }), t]))),
        simulatorGroup(),
        selftestGroup(),
    ].filter(Boolean);
}

/* ── בדיקת הגנה יזומה (שלב 12 ג) — המכשיר בודק את עצמו; הפסיקה בשרת (lib/selftest.php) ── */

const ST_ICON = { fail: '❌', warn: '🟡', ok: '✅' };
const ST_AREA = { device: '📱', web: '🌐', apps: '🧩', settings: '⚙️' };

function selftestGroup() {
    const kid = K.id;
    const out = el('div', { role: 'status' });
    let timer = 0;
    const show = devs => {
        if (K.id !== kid) return;
        out.replaceChildren(...devs.map(d => {
            const head = d.at
                ? `${d.device} — ${d.fail ? `${d.fail} נכשלו` : 'לא נמצאו כשלים'}${d.warn ? ` · ${d.warn} לתשומת לב` : ''} · ${d.ok} תקינים (${whenStr(d.at)})`
                : `${d.device} — עוד לא נבדק`;
            return el('div', {}, [
                el('div', { class: `banner ${d.fail ? 'warn' : d.at ? 'ok' : ''}` }, [el('span', { text: d.fail ? '❌' : d.at ? '🛡️' : '·' }),
                    el('div', {}, [el('b', { text: head }),
                        d.waiting ? el('div', { class: 'hint', text: '⏳ ממתין למכשיר — הבדיקה רצה בסנכרון הבא (כשמגדלור פתוח או ברקע)' }) : null])]),
                d.checks.length ? el('ul', { class: 'rows' }, d.checks.map(c => el('li', {}, [
                    el('span', { text: `${ST_ICON[c.state]} ${ST_AREA[c.area] || ''}` }),
                    el('div', { class: 'nm' }, [el('b', { text: c.label }), c.detail ? el('small', { text: c.detail, title: c.detail }) : null])]))) : null,
            ]);
        }));
        // ממתין — מתעדכן לבד עוד כמה דקות.
        clearTimeout(timer);
        if (devs.some(d => d.waiting) && out.isConnected) timer = setTimeout(load, 8000);
    };
    const load = async () => { try { show((await call('selftest', { id: kid })).devices); } catch (e) { toast(e.message, true); } };
    const start = async () => {
        try { await call('selftest_request', { id: kid }); toast('נשלח למכשיר'); load(); } catch (e) { toast(e.message, true); }
    };
    load();
    return group('🛡️ בדיקת הגנה', 'המכשיר בודק את עצמו — בלי לגלוש לאתרים ובלי לפתוח אפליקציות', [
        el('div', { class: 'hint', text: 'נבדק: ההכרעה של המכשיר על אתר חסום, אתר מותר ואתר שאינו ברשימה (מול השרת); אפליקציות חסומות — מושעות או נחסמות בפועל; הגדרות המכשיר — נקראות מהמערכת; "בעל מכשיר", נגישות והגנה מהסרה.' }),
        el('button', { type: 'button', class: 'btn primary', text: 'הרצת בדיקה', onclick: start }), out]);
}

/**
 * סימולטור הרשאות (שלב 12 ב): אתר, אפליקציה ושעה ← ההכרעה ואיפה בהגדרות היא נקבעה. מריץ את המנוע האמיתי של
 * השרת על ההגדרות השמורות, בלי לשנות דבר (lib/simulator.php).
 */
function simulatorGroup() {
    const url = el('input', { type: 'text', dir: 'ltr', placeholder: 'אתר — למשל wikipedia.org', ariaLabel: 'אתר' });
    const pkg = el('input', { type: 'text', dir: 'ltr', placeholder: 'אפליקציה — שם חבילה (לא חובה)', ariaLabel: 'אפליקציה' });
    pkg.setAttribute('list', 'sim-apps');   // ‏list הוא מאפיין לקריאה בלבד — רק כ-attribute
    // האפליקציות שהמכשיר דיווח (אם לשונית האפליקציות כבר נטענה).
    const apps = el('datalist', { id: 'sim-apps' }, (AP?.kid === K.id && AP.apps || []).map(a => el('option', { value: a.package, label: a.label })));
    const time = el('input', { type: 'time', ariaLabel: 'שעה' });
    const day = el('select', { ariaLabel: 'יום' }, [['-1', 'היום'], ...['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת']
        .map((d, i) => [String(i), d])].map(([v, t]) => el('option', { value: v, text: t })));
    const used = el('input', { type: 'number', min: '0', max: '1440', placeholder: 'דקות שכבר נוצלו (ריק — בפועל)', ariaLabel: 'דקות שנוצלו' });
    const out = el('div', { role: 'status' });
    const line = (ok, title, sub) => el('div', { class: `banner ${ok ? 'ok' : 'warn'}` }, [el('span', { text: ok ? '✅' : '🚫' }),
        el('div', {}, [el('b', { text: title }), ...sub.filter(Boolean).map(t => el('div', { class: 'hint', text: t }))])]);
    const run = async () => {
        if (!url.value.trim() && !pkg.value.trim()) { toast('אתר או אפליקציה'); return; }
        let r;
        try { r = await call('simulate', { id: K.id, url: url.value.trim(), package: pkg.value.trim(), time: time.value,
                                            day: Number(day.value), used_min: used.value }); }
        catch (e) { toast(e.message, true); return; }
        out.replaceChildren(...[
            r.site && line(r.site.allow, `${r.site.allow ? 'ייפתח' : 'ייחסם'} — ${url.value.trim()}`,
                [r.site.reason, `נקבע ב: ${r.site.where}`, r.site.rule ? `הכלל: ${r.site.rule}` : '',
                 `בדיקה ל-${r.site.at}, אחרי ${r.site.used_min} דקות היום`]),
            r.app && line(r.app.allow, `${r.app.allow ? 'תיפתח' : 'תיחסם'} — ${r.app.label || pkg.value.trim()}`,
                [r.app.reason, r.app.where ? `נקבע ב: ${r.app.where}` : '', r.app.note]),
        ].filter(Boolean));
    };
    return group('🧪 מה יקרה אם…', 'בודק את ההגדרות השמורות, בלי לשנות דבר', [
        url, pkg, apps, el('div', { class: 'row-btns' }, [day, time]), used,
        el('button', { type: 'button', class: 'btn primary', text: 'בדיקה', onclick: run }), out]);
}

/* ── מצב אכיפה: מה באמת נאכף (שלב 11) ───────────────────────────── */

const ENF_ICON = { full: '✅', partial: '🟡', none: '⚠️', na: '·', unknown: '❔' };
const ENF_SUM = {
    full: ['ok', '🛡️ הכול נאכף', 'כל ההגדרות נאכפות בכל המכשירים — לפי מה שהמכשירים דיווחו.'],
    unverified: ['', '❔ עוד לא אומת', 'המכשיר עוד לא דיווח מה הוחל אחרי השינוי האחרון. זה מתעדכן בסנכרון הבא (פתיחת מגדלור).'],
    needs_access: ['warn', '🟡 אכיפה חלקית', 'חלק מההגדרות דורשות את אישור "ניהול אפליקציות" במכשיר.'],
    needs_owner: ['warn', '🛡️ אכיפה לא הרמטית', 'חלק מההגדרות דורשות "בעל מכשיר" — התקנה על מכשיר נקי.'],
    no_device: ['', '📱 אין מכשיר מחובר', 'חברו מכשיר כדי שההגדרות ייאכפו.'],
};

function enforceCard(e) {
    if (!e) return null;
    const [cls, title, sub] = ENF_SUM[e.summary] || ENF_SUM.full;
    const rows = e.rows.filter(r => r.status !== 'na');
    const dlg = el('div', { class: 'enf-detail', hidden: true }, rows.map(r => el('div', { class: 'enf-row' }, [
        // אותם מצבים כמו בהגדרות המכשיר (lib/enforce.php, SETTING_STATES) — "הוחל" רק אחרי דוח מהמכשיר.
        el('span', { class: 'enf-ic', text: SETTING_ICON[r.state] || ENF_ICON[r.status], ariaHidden: 'true' }),
        el('span', { class: 'enf-nm' }, [r.label, r.hint && r.status !== 'full' ? el('small', { text: r.hint }) : null]),
        el('span', { class: `enf-st ${r.status}`, text: r.state_label || { full: 'נאכף', partial: 'חלקי', none: 'לא נאכף', unknown: 'לא דווח' }[r.status] || '' }),
    ])));
    // מתי כל מכשיר דיווח — דוח ישן אינו אימות עדכני.
    const STATE = { fresh: 'עדכני', pending: 'ממתין לסנכרון', stale: 'דוח ישן', none: 'לא דיווח (גרסה ישנה)' };
    (e.devices || []).forEach(d => dlg.append(el('p', { class: 'hint',
        text: `📱 ${d.name || 'מכשיר'}: ${STATE[d.state] || d.state}${d.reported_at ? ' · דווח ' + ago(d.reported_at) : ''}` })));
    const toggle = el('button', { type: 'button', class: 'enf-more', text: 'פירוט ▾',
        onclick: () => { const h = dlg.hidden; dlg.hidden = !h; toggle.textContent = h ? 'פחות ▴' : 'פירוט ▾'; } });
    return el('section', { class: `enf ${cls}` }, [
        el('div', { class: 'enf-head' }, [el('b', { text: title }), toggle]),
        el('p', { class: 'enf-sub', text: sub }),
        dlg,
    ]);
}

/** רובריקות דף הבית של הילד (lib/policy.php, HOME_SECTIONS) — מה שמופיע אצלו, ומה כבוי. */
const HOME_SECTIONS = [
    ['search', '🔎 חיפוש ברשת', 'שורת החיפוש. כבוי — אין חיפוש (במצב "דפדפן" נשארת שורת כתובת).'],
    ['sites', '🌐 אתרים', 'האתרים שאישרתם, כאריחים.'],
    ['youtube', '▶️ יוטיוב', 'כבוי — יוטיוב סגור לגמרי, בלי קשר להגדרות בלשונית יוטיוב.'],
    ['apps', '📲 אפליקציות', 'האפליקציות שמותר לו לפתוח (בקרוב — גם מרקט להתקנה).'],
    ['favorites', '⭐ מועדפים', 'מה שהוא סימן בלחיצה ארוכה.'],
    ['tasks', '📝 משימות', 'דפים שפתחתם למשימה, עם הזמן שנשאר.'],
    ['requests', '🙋 הבקשות שלי', 'מה הוא ביקש, ומה עניתם.'],
];

function kidAccess() {
    const p = K.policy, C = S.catalogs;
    const hint = (map, v) => el('p', { class: 'hint', text: map[v][1] });
    const modeHint = hint(MODES, p.mode), postureHint = hint(POSTURES, p.posture);
    const SEARCH_HINTS = {
        migdalor: 'החיפוש של מגדלור: רק תוצאות שמותרות לילד מוצגות. ב"הכול חסום" — באתרים שאישרתם; ב"הכול פתוח" — בכל הרשת (Brave).',
        google: 'חיפוש בגוגל, בתוך הדפדפן של מגדלור, עם חיפוש בטוח שאי אפשר לכבות. כל תוצאה נבדקת כשלוחצים עליה — חסומה לא נפתחת. ' +
                'מתאים בעיקר ל"הכול פתוח": ב"הכול חסום" רוב התוצאות יובילו לדף חסימה. גוגל מציגה תמונות ותקצירים גם מאתרים שחסומים לילד.',
    };
    const searchHint = el('p', { class: 'hint', text: SEARCH_HINTS[p.search_engine || 'migdalor'] });
    const cats = Object.entries(C.categories).map(([k, c]) => el('li', {}, [
        el('span', { text: c.icon, ariaHidden: 'true' }), el('span', { class: 'nm', text: c.name }),
        seg(`cat_${k}`, [['', '—'], ['allow', 'מותר'], ['deny', 'חסום']], K.categories[k] || '', { cls: 'seg mini' }),
    ]));
    return [
        el('label', { class: 'f' }, [el('span', { text: 'שם' }), el('input', { name: 'display_name', value: K.name, maxLength: 40 })]),
        group('🏠 דף הבית', 'הרובריקות שהוא רואה — כבויה לא מופיעה', [el('ul', { class: 'rows' }, HOME_SECTIONS.map(([k, name, hint]) =>
            el('li', {}, [el('label', { class: 'check', style: 'display:flex;gap:10px;align-items:flex-start;width:100%' }, [
                el('input', { type: 'checkbox', name: 'home_sections', value: k, checked: (p.home_sections || HOME_SECTIONS.map(s => s[0])).includes(k) }),
                el('span', {}, [el('b', { text: name }), el('small', { class: 'muted', style: 'display:block', text: hint })])])])))]),
        group('מה הוא רואה', '', [
            seg('mode', Object.entries(MODES).map(([k, v]) => [k, v[0]]), p.mode, { cols: 2, onchange: v => { modeHint.textContent = MODES[v][1]; } }),
            modeHint]),
        group('כשאין כלל שמתאים', '', [
            seg('posture', Object.entries(POSTURES).map(([k, v]) => [k, v[0]]), p.posture, { cols: 2, onchange: v => { postureHint.textContent = POSTURES[v][1]; } }),
            postureHint]),
        // מנוע החיפוש (lib/policy.php, SEARCH_ENGINES): החיפוש של מגדלור, או דף החיפוש של גוגל עם חיפוש בטוח.
        group('חיפוש', '', [
            seg('search_engine', [['migdalor', '🗼 מגדלור'], ['google', '🔎 גוגל']], p.search_engine || 'migdalor', { cols: 2,
                onchange: v => { searchHint.textContent = SEARCH_HINTS[v]; } }),
            searchHint]),
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
        // מצב לא מקוון מתוכנן (שלב 12 ו) — נאכף במכשיר; הילד רואה במסך הבית מה זמין ועד מתי.
        group('📴 בלי חיבור לאינטרנט', 'כשהמכשיר לא מגיע לשרת — למשל בטיסה או בלי חבילת גלישה', [
            seg('offline_content', [['all', 'מה שמותר'], ['approved', 'רק מה שאישרתי'], ['none', 'כלום']], p.offline_content || 'all', { cols: 3 }),
            el('label', { class: 'f' }, [el('span', { text: 'אחרי כמה שעות בלי חיבור נסגר הכול' }),
                el('select', { name: 'offline_grace_h' }, [1, 2, 4, 8, 12, 24, 48, 72].map(h =>
                    el('option', { value: String(h), text: h === 1 ? 'שעה' : `${h} שעות`, selected: h === (p.offline_grace_h || 24) })))]),
            el('p', { class: 'hint', text: '"רק מה שאישרתי" — אתרים שהוספתם בלשונית אתרים (כולל משימות) ויוטיוב שאישרתם. הזמנים והמכסה ממשיכים לחול גם בלי חיבור. קוד מההורה ("📷 העברה בקוד") מחזיר גלישה גם אחרי שהזמן נגמר.' }),
        ]),
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
            posture: f.querySelector('[name=posture]:checked').value, home_sections: checked('home_sections'), search_engine: f.querySelector('[name=search_engine]:checked').value, categories, types: checked('types'), ads: checked('ads'),
            allow_downloads: f.allow_downloads.checked, allow_pip: f.allow_pip.checked,
            allow_background: f.allow_background.checked, block_screenshots: f.block_screenshots.checked };
    } else if (tab === 'time') {
        policy = { days: checked('days').map(Number), window_start: f.window_start.value, window_end: f.window_end.value,
            daily_quota_min: Number(f.daily_quota_min.value || 0), session_max_min: Number(f.session_max_min.value || 0),
            offline_content: f.querySelector('[name=offline_content]:checked').value, offline_grace_h: Number(f.offline_grace_h.value) };
    } else return;
    await act('child_save', { policy }, 'נשמר — המכשיר יתעדכן תוך דקה');
});

function kidYt() {
    const y = K.youtube, allowed = y.allowed_modes;
    const hint = el('p', { class: 'hint', text: YT[y.mode][1] });
    const mode = seg('yt_mode', Object.entries(YT).map(([k, v]) => [k, v[0]]), y.mode,
        { cols: 2, disabled: Object.keys(YT).filter(k => !allowed.includes(k)), onchange: v => { hint.textContent = YT[v][1]; } });
    const search = el('input', { type: 'checkbox', checked: y.allow_search }), shorts = el('input', { type: 'checkbox', checked: y.allow_shorts });
    const input = el('input', { dir: 'ltr', placeholder: '@ChannelName או קישור', autocapitalize: 'off', ariaLabel: 'ערוץ, סרטון או פלייליסט' });
    const items = y.items.length ? el('ul', { class: 'rows' }, y.items.map(i => el('li', {}, [
        el('span', { text: i.action === 'deny' ? '🚫' : '✅', ariaHidden: 'true' }),
        el('span', { class: 'nm' }, [i.label || (i.kind === 'handle' ? '@' + i.item_id : i.item_id),
            el('small', { text: `${KIND[i.kind] || i.kind} · ${i.action === 'deny' ? 'חסום' : 'מותר'}` })]),
        el('button', { type: 'button', class: 'icon-btn', text: '🗑️', ariaLabel: 'הסרה', onclick: () => act('yt_del', { item_id: i.id }, 'הוסר') }),
    ]))) : el('p', { class: 'empty', text: 'הרשימה ריקה. הוסיפו ערוץ — מספיק @שם-הערוץ.' });
    const action = seg('yt_item_action', [['allow', '✅ להתיר'], ['deny', '🚫 לחסום']], 'allow', { cols: 2 });
    // רובריקת יוטיוב כבויה (🔐 גישה ← 🏠 דף הבית) — יוטיוב סגור, מה שלא יוגדר כאן.
    const ytOff = K.policy.home_sections && !K.policy.home_sections.includes('youtube')
        ? el('div', { class: 'banner warn' }, [el('span', { text: '▶️' }), el('div', {}, [el('b', { text: 'רובריקת יוטיוב כבויה' }),
            'יוטיוב סגור לגמרי, בלי קשר להגדרות כאן. מדליקים בלשונית "🔐 גישה" ← "🏠 דף הבית".'])])
        : null;
    return [
        ytOff,
        allowed.length === 1 ? el('div', { class: 'banner info' }, [el('span', { text: 'ℹ️' }),
            el('div', { text: 'יוטיוב לא פתוח בחשבון שלכם. אפשר לבקש ממנהל המערכת.' })]) : null,
        group('יוטיוב', '', [mode, hint, learnCheck(y),
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

/**
 * מצב למידה: מה אומת בדף במכשיר (lib/ytlearn.php). המלצות, ניגון אוטומטי ועצירה בסוף תלויים בדף של יוטיוב,
 * שמשתנה — לכן מוצג רק מה שהמכשיר בדק בפועל. הגבול עצמו (רק הסרטונים שבתור) נאכף בכל מקרה.
 */
const LEARN_ICON = { verified: '✅', failed: '❌', unknown: '⚪' };
function learnCheck(y) {
    if (y.mode !== 'learn' || !y.learn_check?.length) return null;
    return el('div', { class: 'hint' }, [
        el('b', { text: 'מה אומת במכשיר:' }),
        ...y.learn_check.map(c => el('div', { text: `${LEARN_ICON[c.state]} ${c.label}${c.state === 'unknown' ? ' — עוד לא נבדק (נבדק כשהילד צופה)' : ` · ${c.device} · ${ago(c.at)}`}`,
            style: c.state === 'failed' ? 'color:var(--danger)' : '' })),
        el('div', { text: 'בכל מקרה: סרטון שאינו בתור — לא נפתח, וסרטון שמתחיל לבד מיד אחרי סוף הקודם — נעצר.' }),
    ]);
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
        tasksGroup(),
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

/* ── אישור לפי משימה (שלב 12 ד) — דפים ומשאבים לזמן מוגבל (lib/tasks.php) ── */

let TS = null, TK = 0;

function tasksGroup() {
    if (TK !== K.id) {
        TK = K.id; TS = null;
        call('tasks', { id: K.id }).then(r => { TS = r.tasks; if (tab === 'sites') renderKid(); }).catch(e => toast(e.message, true));
    }
    const list = (TS || []).filter(t => t.active || Date.now() - new Date(t.ends_at) < 86400e3).map(t => el('li', { style: t.active ? '' : 'opacity:.55' }, [
        el('span', { text: t.active ? '📝' : '✔️', ariaHidden: 'true' }),
        el('span', { class: 'nm' }, [t.title,
            el('small', { text: t.active ? `עוד ${t.left_min} דק׳ · עד ${whenStr(t.ends_at)}` : `${t.ended_early ? 'הסתיימה מוקדם' : 'הסתיימה'} ${whenStr(t.ends_at)}` }),
            el('small', { text: [...t.pages, ...t.resources.map(h => `+ ${h}`)].join(' · ') })]),
        t.active ? el('button', { type: 'button', class: 'btn ghost sm', text: 'סיום', onclick: () => {
            if (confirm(`לסיים עכשיו את "${t.title}"? הדפים ייחסמו שוב בסנכרון הבא.`)) act('task_end', { task: t.id }, 'הסתיימה');
        } }) : null,
    ]));

    const title = el('input', { placeholder: 'שיעורי בית בהיסטוריה', maxLength: 60 });
    const url = el('input', { dir: 'ltr', placeholder: 'https://school.example/hw/12', autocapitalize: 'off', inputMode: 'url' });
    const scope = el('select', {}, [['exact', 'הדף הזה בלבד'], ['domain', 'כל האתר']].map(([v, t]) => el('option', { value: v, text: t })));
    const minutes = el('select', {}, [15, 30, 45, 60, 90, 120, 180, 240].map(m => el('option', { value: String(m), text: `${m} דק׳`, selected: m === 45 })));
    const res = el('div', {});
    let found = [];
    const scan = async () => {
        if (!url.value.trim()) { toast('כתובת הדף'); return; }
        res.replaceChildren(el('p', { class: 'hint', text: 'בודק מה הדף טוען…' }));
        try { found = (await call('task_scan', { id: K.id, url: url.value.trim() })).resources; }
        catch (e) { res.replaceChildren(el('p', { class: 'hint', text: `${e.message} — אפשר ליצור בלי משאבים; אם הדף לא נטען כראוי, הוסיפו אותם אחר כך.` })); found = []; return; }
        res.replaceChildren(found.length
            ? el('div', {}, [el('p', { class: 'hint', text: 'הדף טוען גם מהשרתים האלה (תמונות, סקריפטים, סרטונים משובצים). מסומן — ייטען בתוך הדף בלבד, לא כאתר לגלישה:' }),
                ...found.map(h => el('label', { class: 'f' }, [el('input', { type: 'checkbox', checked: true, value: h, name: 'task-res' }), el('span', { text: h, dir: 'ltr' })]))])
            : el('p', { class: 'hint', text: 'הדף לא טוען משאבים משרתים אחרים.' }));
    };
    const add = async () => {
        const chosen = [...document.querySelectorAll('[name=task-res]:checked')].map(i => i.value);
        const r = await act('task_add', { title: title.value, minutes: Number(minutes.value),
            pages: [{ url: url.value.trim(), scope: scope.value }], resources: chosen }, 'נפתח — יופיע לו כאריח בסנכרון הבא');
        if (r) { title.value = ''; url.value = ''; res.replaceChildren(); }
    };
    return group('📝 משימה — פתיחה לזמן מוגבל', 'במקום היתר קבוע: הדף ומה שהוא צריך, ונסגר לבד בסוף הזמן', [
        list.length ? el('ul', { class: 'rows' }, list) : null,
        el('div', { class: 'fields two' }, [el('label', { class: 'f' }, [el('span', { text: 'מה המשימה *' }), title]),
            el('label', { class: 'f' }, [el('span', { text: 'לכמה זמן' }), minutes])]),
        el('label', { class: 'f' }, [el('span', { text: 'הדף *' }), url]),
        el('label', { class: 'f' }, [el('span', { text: 'מה נפתח' }), scope]),
        el('button', { type: 'button', class: 'btn ghost', text: '🔎 מה הדף צריך כדי לעבוד?', onclick: scan }),
        res,
        el('p', { class: 'hint', text: 'חסימות מפורשות וסוגי תוכן חסומים ממשיכים לחול גם בתוך המשימה.' }),
        el('button', { type: 'button', class: 'btn primary', text: 'פתיחה למשימה', onclick: add }),
    ]);
}

/** מצב העדכון כפי שהמכשיר דיווח (שלב 9) — או '' כשאין מה להראות. */
const UPDATE_STATE = {
    downloading: '⏳ מוריד', verifying: '🔎 בודק את הקובץ של', installing: '⏳ מתקין',
    waiting_user: '✋ מחכה שהילד יאשר במסך ההתקנה את', failed: '❌ נכשל עדכון ל-',
};
function updateLine(u) {
    if (!u || !UPDATE_STATE[u.state]) return '';
    const when = u.at ? ` (${ago(u.at)})` : '';
    return u.state === 'failed'
        ? `${UPDATE_STATE.failed}${u.target_name}${u.error ? ': ' + u.error : ''}${when} — ינוסה שוב לבד`
        : `${UPDATE_STATE[u.state]} ${u.target_name}${when}`;
}

/** שורת הגרסה של מכשיר: מה מותקן, ומה המצב מול הגרסה האחרונה. */
function appLine(d) {
    const a = d.app, latest = K.latest_app;
    const u = updateLine(a.update);
    if (u) return `${a.version_name || 'גרסה ישנה'} · ${u}`;
    if (a.status === 'unknown') return a.requested ? '⏳ ישנה — נשלחו לו הוראות מעבר' : '⚠️ גרסה ישנה (לא מדווחת)';
    if (a.status === 'outdated') return a.requested ? `⏳ ${a.version_name} — מחכה שהילד יאשר עדכון` : `⬆️ ${a.version_name} · יש ${latest.name}`;
    return `✓ ${a.version_name} — מעודכנת`;
}

/** שורת ההרשאות של מכשיר (מסלול ההקמה): מה חסר, או ✓. */
function permLine(p) {
    if (!p) return '';
    if (p.complete) return '✓ כל ההרשאות · בעל מכשיר';
    // "בעל מכשיר" חסר — תמיד מסומן: בלעדיו אין שליטה בהגדרות המערכת ועדכון שקט.
    const owner = p.owner ? '' : '⚠️ חסר "בעל מכשיר" — הקמה מלאה ב"חיבור מכשיר": בקוד QR (איפוס) או דרך מחשב (בלי איפוס)';
    const req = p.required_missing.length ? `⚠️ חסרות הרשאות: ${p.required_missing.join('، ')}` : '';
    return [req, owner].filter(Boolean).join(' · ');
}

function updateDevice(d) {
    if (d.app.requested) {
        if (confirm('לבטל את בקשת העדכון?')) act('device_update_cancel', { device_id: d.id }, 'בוטל');
        return;
    }
    const old = d.app.status === 'unknown';
    if (!confirm(old
        ? `ב${d.name || 'מכשיר'} מותקנת גרסה ישנה, שלא יודעת להתעדכן לבד.\n\nהיא תיחסם ותציג לילד הוראות מעבר לגרסה החדשה. כדאי להיות לידו — ראו "איך מעבירים מכשיר ישן" למטה.\n\nלשלוח?`
        : `לבקש עדכון ל-${K.latest_app.name}?\n\nבפתיחה הבאה של האפליקציה הילד יתבקש לאשר הורדה והתקנה. ההגדרות והחיבור נשמרים.`)) return;
    act('device_update', { device_id: d.id }, old ? 'נשלח — יופיע לילד בפעם הבאה שיפתח' : 'נשלח — הילד יתבקש לעדכן');
}

/**
 * הגדרות המכשיר — לכל אחת מותר / חסום (lib/devicesettings.php). נאכף רק ב"בעל מכשיר": בלי הקמה מלאה
 * ההגדרות נשמרות, ומוצג שהן עוד לא נאכפות.
 */
/** מצב הביצוע בפועל של הגדרה (2026-10-22): לא "נשמר" — מה המכשיר עשה, ומתי אומת. */
const SETTING_ICON = { verified: '✅', applied: '☑️', received: '📥', pending: '⏳', saved: '💾',
    unsupported: '➖', needs_device: '📱', needs_action: '👉', failed: '❌' };
function settingStatusLine(st) {
    if (!st) return null;
    const bad = st.state === 'failed' || st.state === 'needs_action' || st.state === 'needs_device';
    const when = st.at && st.state === 'verified' ? ` · ${ago(st.at)}` : '';
    return el('small', { text: `${SETTING_ICON[st.state] || ''} ${st.label}${when}${st.hint ? ' — ' + st.hint : ''}`,
        style: bad ? 'color:var(--danger)' : 'color:var(--muted)' });
}

function deviceSettingsGroup() {
    const S2 = K.device_settings || [];
    if (!S2.length) return null;
    const owners = K.device_list.filter(d => d.perms.owner).length, total = K.device_list.length;
    const warn = total && owners < total
        ? el('div', { class: 'banner warn' }, [el('span', { text: '⚠️' }), el('div', {}, [
            el('b', { text: owners ? 'נאכף רק בחלק מהמכשירים' : 'עוד לא נאכף' }),
            'הגדרות המכשיר דורשות הקמה מלאה ("בעל מכשיר"): איפוס המכשיר, ואז "📷 חיבור מכשיר חדש" ← "🛡️ הקמה מלאה".'])])
        : null;
    const rows = el('ul', { class: 'rows' }, S2.map(s => el('li', {}, [
        el('span', { class: 'nm' }, [s.label, el('small', { text: s.hint }), settingStatusLine(s.status)]),
        seg(`ds_${s.key}`, [['allow', '✅ מותר'], ['block', '🚫 חסום']], s.blocked ? 'block' : 'allow',
            { cls: 'seg mini', onchange: v => act('device_setting', { key: s.key, blocked: v === 'block' }, 'נשמר') }),
    ])));
    const offline = el('button', { type: 'button', class: 'btn', text: '📷 העברה בקוד — גם כשאין למכשיר אינטרנט' });
    offline.addEventListener('click', openOffline);
    return group('⚙️ הגדרות המכשיר', `${S2.filter(s => s.blocked).length} חסומות`, [warn, rows, offline].filter(Boolean));
}

/**
 * העברה בלי אינטרנט: QR חתום עם הגדרות המכשיר והאפליקציות כפי שהן עכשיו. מכשיר הילד סורק אותו
 * ומפעיל גם בלי רשת — כך אפשר לפתוח או לחסום מחדש גם אחרי שהמכשיר התנתק. תקף רבע שעה.
 */
async function openOffline() {
    let r;
    try { r = await call('offline_code', { id: K.id }); } catch (e) { toast(e.message, true); return; }
    const qr = qrcode(0, 'L');
    qr.addData(r.qr);
    qr.make();
    const svg = new DOMParser().parseFromString(qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true }), 'image/svg+xml').documentElement;
    svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'קוד QR להעברת ההגדרות');
    svg.style.cssText = 'inline-size:min(86vw,340px);block-size:auto;background:#fff;border-radius:10px;padding:6px';
    $('#pair-title').textContent = `העברה בקוד — ${K.name}`;
    $('#pair-body').replaceChildren(
        el('ol', { class: 'steps' }, [
            el('li', { text: 'קודם משנים כאן את ההגדרות (מותר / חסום), ורק אז פותחים את הקוד.' }),
            el('li', { text: 'במכשיר של הילד: מגדלור ← ההגדרות שלי ← "📷 קוד מההורה", ומכוונים למסך הזה.' }),
        ]),
        el('div', { style: 'display:grid;justify-items:center;gap:8px' }, [svg]),
        el('p', { class: 'hint', style: 'text-align:center',
            text: `הקוד תקף ${Math.round(r.expires_in / 60)} דקות.` + (r.partial
                ? ' רשימת האפליקציות ארוכה מדי לקוד — עוברות הגדרות המכשיר וברירת המחדל של האפליקציות בלבד.' : '') }),
    );
    const d = $('#pair-dlg'); if (!d.open) d.showModal();
}

function kidDev() {
    const list = K.device_list.length ? el('ul', { class: 'rows' }, K.device_list.map(d => el('li', {}, [
        el('span', { text: '📱', ariaHidden: 'true' }),
        el('span', { class: 'nm' }, [d.name || 'מכשיר',
            el('small', { text: `${appLine(d)} · ${ago(d.last_seen_at)}` }),
            el('small', { text: permLine(d.perms), style: `color:${d.perms.complete ? 'var(--muted)' : 'var(--danger)'}` }),
            // בלי "בעל מכשיר" — קישור למדריך, ישר מהשורה שמתריעה.
            d.perms && !d.perms.owner ? el('button', { type: 'button', class: 'btn ghost sm', text: '📖 איך עושים הקמה מלאה?', onclick: showOwnerGuide }) : null]),
        d.app.status !== 'current' ? el('button', { type: 'button', class: d.app.requested ? 'btn ghost sm' : 'btn primary sm',
            text: d.app.requested ? '⏳ נשלח' : '⬆️ עדכון', onclick: () => updateDevice(d) }) : null,
        el('button', { type: 'button', class: 'icon-btn', text: '✕', ariaLabel: 'ניתוק', onclick: () => {
            if (confirm(`לנתק את ${d.name || 'המכשיר'}?`)) act('device_del', { device_id: d.id }, 'נותק');
        } }),
    ]))) : el('p', { class: 'empty', text: 'אין מכשיר מחובר.' });
    const anyOld = K.device_list.some(d => d.app.status === 'unknown');
    return [
        list,
        deviceSettingsGroup(),
        anyOld ? group('איך מעבירים מכשיר ישן', 'פעם אחת — אחר כך הכול מתעדכן מכאן', [
            el('ol', { class: 'steps' }, [
                el('li', { text: 'במכשיר של הילד: מוחקים את האפליקציה הישנה ("דפדפן מנוהל" או מגדלור ישן). גרסה ישנה מאוד נחתמה אחרת, ולכן אי אפשר להתקין עליה.' }),
                el('li', {}, ['מורידים ומתקינים את מגדלור: ', el('a', { href: APK, text: 'הורדת האפליקציה' }), ' (או בכרטיס "מגדלור" בדף הכלים).']),
                el('li', { text: 'פותחים את מגדלור ← "📷 סריקת קוד", ומכאן: "📷 חיבור מכשיר חדש" למטה.' }),
                el('li', { text: 'מנתקים כאן את המכשיר הישן (✕).' }),
            ]),
            el('p', { class: 'hint', text: 'מגדלור מגרסה 1.37 ומעלה אפשר פשוט להתקין מעל הקיים — החיבור נשמר.' }),
        ]) : null,
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

/* ── פעילות (שלב 7) ────────────────────────────────────────────── */

const LEVELS = { full: ['🔍 מלא', 'כל כתובת שנפתחה, מתי וכמה זמן. נשמר 90 יום.'],
                 summary: ['📊 מסוכם', 'כמה זמן בכל אתר ובכל יום — בלי הכתובות עצמן.'],
                 off: ['🙈 כבוי', 'רק זמן המסך היומי. על אתרים לא נשמר כלום.'] };
let A = null, actDays = 7, actLoading = false;

/** טעינה אחת בכל פעם; שגיאה נשמרת ב-A ומוצגת עם "נסו שוב" — לא נטען שוב לבד. */
async function loadAct() {
    const kid = K.id, days = actDays;
    actLoading = true;
    try { A = { ...(await call('activity', { id: kid, days })).activity, kid }; }
    catch (e) { A = { kid, days, error: e.message }; }
    actLoading = false;
    if (tab === 'act' && K && K.id === kid) renderKid();
}

const hhmm = iso => new Date(iso).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
const dayName = (d, long = false) => new Date(d + 'T12:00:00').toLocaleDateString('he-IL', long
    ? { weekday: 'long', day: 'numeric', month: 'numeric' } : { weekday: 'narrow' });

function kidAct() {
    if (!A || A.kid !== K.id || A.days !== actDays) { if (!actLoading) loadAct(); return [el('p', { class: 'empty', text: 'טוען…' })]; }
    if (A.error) return [el('p', { class: 'error', text: A.error }),
        el('button', { type: 'button', class: 'btn ghost', text: 'נסו שוב', onclick: () => { A = null; renderKid(); } })];
    const hint = el('p', { class: 'hint', text: LEVELS[A.level][1] });
    const level = seg('report_level', Object.entries(LEVELS).map(([k, v]) => [k, v[0]]), A.level, {
        cols: 3, disabled: Object.keys(LEVELS).filter(k => !A.levels.includes(k)),
        onchange: async v => {
            const down = (A.level === 'full' && v !== 'full') || (A.level === 'summary' && v === 'off');
            if (down && !confirm('מה שכבר נשמר מעבר לרמה החדשה יימחק. להמשיך?')) { renderKid(); return; }
            try { A = { ...(await call('report_level', { id: K.id, level: v, days: actDays })).activity, kid: K.id }; toast('נשמר'); }
            catch (e) { toast(e.message, true); }
            renderKid();
        } });
    const days = seg('act_days', [['7', 'שבוע'], ['30', '30 יום']], String(actDays), { cols: 2,
        onchange: v => { actDays = Number(v); A = null; renderKid(); } });
    const avg = Math.round(A.total_sec / A.days);
    const sitesMax = Math.max(1, ...A.sites.map(x => x.seconds));
    return [
        group('כמה לראות', '', [level, hint,
            A.levels.includes('full') ? null : el('p', { class: 'hint', text: 'דיווח מלא — לפי החבילה של החשבון. אפשר לבקש ממנהל המערכת.' })]),
        days,
        el('dl', { class: 'cells k3' }, [
            el('div', {}, [el('dt', { text: 'סה"כ' }), el('dd', { text: mins(A.total_sec) })]),
            el('div', {}, [el('dt', { text: 'ממוצע ליום' }), el('dd', { text: mins(avg) })]),
            el('div', {}, [el('dt', { text: 'חסימות' }), el('dd', { text: String(A.blocked_count) })]),
        ]),
        group('זמן מסך לפי יום', 'בדקות', [dayChart(A.daily, K.quota_min)]),
        A.level === 'off' ? null : group('אתרים', A.sites.length ? 'לפי זמן' : '', [A.sites.length
            ? el('ul', { class: 'rows meter-rows' }, A.sites.map(x => el('li', {}, [
                el('span', { class: 'nm' }, [el('bdi', { dir: 'ltr', text: x.host }),
                    el('small', { text: `${mins(x.seconds)} · ${x.visits} כניסות` })]),
                el('span', { class: 'meter', ariaHidden: 'true' }, [el('span', { style: `inline-size:${Math.max(3, Math.round(x.seconds / sitesMax * 100))}%` })]),
            ])))
            : el('p', { class: 'empty', text: 'עוד אין פעילות באתרים בתקופה הזו.' })]),
        group('ניסיונות שנחסמו', 'תמיד נשמרים', [A.blocks.length
            ? el('ul', { class: 'rows' }, A.blocks.map(x => el('li', {}, [
                el('span', { text: '🚫', ariaHidden: 'true' }),
                el('span', { class: 'nm' }, [el('bdi', { dir: 'ltr', text: x.host }), el('small', { text: x.reason })]),
                el('span', { class: 'muted when', text: whenShort(x.at) }),
            ])))
            : el('p', { class: 'empty', text: 'אין חסימות בתקופה הזו 👍' })]),
        A.level === 'full' ? group('היסטוריה', 'כל כתובת', [historyList(A.history)]) : null,
    ];
}

function whenShort(iso) {
    const d = new Date(iso), today = new Date().toDateString() === d.toDateString();
    return today ? hhmm(iso) : `${d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric' })} ${hhmm(iso)}`;
}

/** בלי https://www., ועם עברית קריאה במקום %D7%90… */
function readableUrl(u) {
    let t = u.replace(/^https?:\/\/(www\.)?/, '');
    try { t = decodeURI(t); } catch { /* כתובת עם קידוד שבור — כמו שהיא */ }
    return t;
}

function historyList(rows) {
    if (!rows.length) return el('p', { class: 'empty', text: 'עוד אין כתובות בתקופה הזו.' });
    const out = [];
    let last = '';
    for (const r of rows) {
        const day = new Date(r.at).toDateString();
        if (day !== last) {
            last = day;
            out.push(el('li', { class: 'day-head', text: new Date(r.at).toLocaleDateString('he-IL', { weekday: 'long', day: 'numeric', month: 'numeric' }) }));
        }
        out.push(el('li', {}, [
            el('span', { class: 'muted when', text: hhmm(r.at) }),
            el('span', { class: 'nm' }, [el('bdi', { dir: 'ltr', class: 'url', text: readableUrl(r.url) }),
                r.seconds ? el('small', { text: mins(r.seconds) }) : null]),
        ]));
    }
    return el('ul', { class: 'rows history' }, out);
}

/**
 * עמודות של דקות ליום — סדרה אחת, בלי מקרא (הכותרת אומרת מה מוצג). 4px עיגול בקצה,
 * ריבוע בבסיס, רווח 2px בין עמודות. מגע/ריחוף — תווית עם היום והדקות; קו מכסה, אם יש.
 * הטבלה מתחת — לקוראי מסך ולמי שרוצה מספרים.
 */
function dayChart(daily, quotaMin) {
    const W = 340, H = 140, padT = 18, padB = 22, padS = 28;
    const minsOf = d => Math.round(d.seconds / 60);
    const top = Math.max(quotaMin || 0, ...daily.map(minsOf), 10);
    const step = top <= 30 ? 10 : top <= 90 ? 30 : top <= 240 ? 60 : 120;
    const yMax = Math.ceil(top / step) * step;
    const n = daily.length, slot = (W - padS) / n, bw = Math.min(24, slot - 2);
    const y = v => padT + (H - padT - padB) * (1 - v / yMax);
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`); svg.setAttribute('class', 'chart');
    svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'זמן מסך לפי יום, בדקות');
    const add = (tag, attrs, text) => { const e = document.createElementNS(NS, tag);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v); if (text != null) e.textContent = text; svg.append(e); return e; };
    // ציר וקווי רשת — עדינים. RTL: הימים מימין לשמאל, כמו בלוח שנה עברי; המספרים בצד ימין.
    for (let v = 0; v <= yMax; v += step) {
        add('line', { x1: 0, x2: W - padS, y1: y(v), y2: y(v), class: v ? 'grid' : 'base' });
        add('text', { x: W - padS + 4, y: y(v) + 4, class: 'tick', 'text-anchor': 'start' }, String(v));
    }
    if (quotaMin) {
        add('line', { x1: 0, x2: W - padS, y1: y(quotaMin), y2: y(quotaMin), class: 'quota' });
        add('text', { x: 2, y: y(quotaMin) - 4, class: 'tick', 'text-anchor': 'start' }, `מכסה ${quotaMin}`);
    }
    const tip = el('div', { class: 'chart-tip', hidden: true, role: 'status' });
    const peak = daily.reduce((m, d, i) => minsOf(d) > minsOf(daily[m]) ? i : m, 0);
    daily.forEach((d, i) => {
        const v = minsOf(d), cx = W - padS - slot * (i + .5), x0 = cx - bw / 2, y0 = y(v), base = y(0);
        if (v > 0) {
            const r = Math.min(4, bw / 2, base - y0);
            add('path', { class: 'bar', d: `M${x0},${base} V${y0 + r} Q${x0},${y0} ${x0 + r},${y0} H${x0 + bw - r} Q${x0 + bw},${y0} ${x0 + bw},${y0 + r} V${base} Z` });
        }
        if (n <= 7 || i % 5 === (n - 1) % 5) {
            add('text', { x: cx, y: H - 6, class: 'tick', 'text-anchor': 'middle' },
                n <= 7 ? dayName(d.day) : new Date(d.day + 'T12:00:00').toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric' }));
        }
        if (i === peak && v > 0) add('text', { x: cx, y: y0 - 5, class: 'val', 'text-anchor': 'middle' }, String(v));
        const hit = add('rect', { x: cx - slot / 2, y: 0, width: slot, height: H, class: 'hit', tabindex: '0' });
        const show = () => {
            tip.textContent = `${dayName(d.day, true)} · ${mins(d.seconds)}`; tip.hidden = false;
            tip.style.insetInlineEnd = `${Math.min(70, Math.max(0, (padS + slot * i) / W * 100 - 10))}%`;
            svg.querySelectorAll('.hit.on').forEach(h => h.classList.remove('on')); hit.classList.add('on');
        };
        hit.addEventListener('pointerenter', show); hit.addEventListener('click', show); hit.addEventListener('focus', show);
    });
    svg.addEventListener('pointerleave', () => { tip.hidden = true; svg.querySelectorAll('.hit.on').forEach(h => h.classList.remove('on')); });
    const table = el('details', { class: 'chart-table' }, [el('summary', { class: 'muted', text: 'כטבלה' }),
        el('table', {}, [el('tbody', {}, [...daily].reverse().map(d => el('tr', {}, [
            el('td', { text: dayName(d.day, true) }), el('td', { text: mins(d.seconds) })])))])]);
    return el('div', { class: 'chart-box' }, [svg, tip, table]);
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
        provisionBox(r.provision),
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

/**
 * הקמה כ"בעל מכשיר" — המסלול המומלץ: שליטה מלאה, גם בהגדרות המערכת, ועדכונים שקטים. אנדרואיד מאפשר
 * אותה רק במכשיר מאופס: במסך הפתיחה מקישים 6 פעמים, וסורקים את ה-QR הזה. אחר כך מחברים כרגיל.
 */
function provisionBox(cfg) {
    if (!cfg) return null;
    const holder = el('div', { style: 'display:grid;justify-items:center;gap:8px' });
    const btn = el('button', { type: 'button', class: 'btn', text: '🛡️ הקמה מלאה — מכשיר חדש או מאופס (מומלץ)' });
    btn.addEventListener('click', () => {
        if (holder.firstChild) { holder.replaceChildren(); return; }
        const qr = qrcode(0, 'M'); qr.addData(JSON.stringify(cfg)); qr.make();
        const svg = new DOMParser().parseFromString(qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true }), 'image/svg+xml').documentElement;
        svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'קוד QR להקמה כבעל מכשיר');
        svg.style.cssText = 'inline-size:min(80vw,320px);block-size:auto;background:#fff;border-radius:10px;padding:6px';
        holder.replaceChildren(
            el('ol', { class: 'steps' }, [
                'מאפסים את המכשיר להגדרות יצרן (כל מה שעליו יימחק).',
                'במסך הפתיחה ("ברוכים הבאים") מקישים 6 פעמים על אותו מקום — נפתח סורק QR.',
                'סורקים את הקוד הזה ומתחברים ל-Wi‑Fi. מגדלור יורד ומוגדר כ"בעל מכשיר" לבד.',
                'כשמגדלור נפתח — סורקים את קוד החיבור שלמעלה.',
            ].map(t => el('li', { text: t }))),
            svg);
    });
    return el('div', { style: 'margin-top:16px;display:grid;gap:8px' }, [
        el('p', { class: 'hint', text: 'בלי הקמה מלאה האכיפה חלקית: אי אפשר לנעול את הגדרות המערכת, והעדכונים דורשים לחיצה של הילד.' }),
        btn, holder,
        // בלי איפוס — דרך מחשב (setup/, WebUSB). בטלפון: מגדלור ← ⚙️ ← "הגנה מלאה" (FullSetupActivity).
        el('a', { class: 'btn', href: '../setup/', target: '_blank', rel: 'noopener', text: '💻 הקמה מלאה בלי איפוס — דרך מחשב' }),
        el('p', { class: 'hint', text: 'בטלפון: מגדלור ← ⚙️ ← "הגנה מלאה"; במחשב בכרום — הדף שנפתח בכפתור. מסירים זמנית את החשבונות בטלפון, וכל השאר אוטומטי.' }),
        el('button', { type: 'button', class: 'btn ghost', text: '📖 המדריך המלא — צעד אחר צעד', onclick: showOwnerGuide })]);
}

/* ── מדריך: הקמה מלאה ("בעל מכשיר") ───────────────────────────────── */

/**
 * המדריך המלא להקמה מלאה — שתי הדרכים (מחשב בלי איפוס, או QR אחרי איפוס), איך יודעים שהצליח, ומה עושים
 * כשנתקעים. נפתח מהתפריט, משורת "חסר בעל מכשיר" של מכשיר, ומחיבור מכשיר. בטלפון — FullSetupActivity.
 */
function showOwnerGuide() {
    const steps = items => el('ol', { class: 'steps' }, items.map(t => el('li', {}, [].concat(t))));
    const tip = t => el('p', { class: 'hint', text: t });
    const route = (title, sub, open, kids) => {
        return el('details', { class: 'group', open }, [el('summary', {}, [el('b', { text: title }), sub ? el('small', { class: 'muted', text: ' — ' + sub }) : null]), ...kids]);
    };
    $('#guide-body').replaceChildren(
        el('p', { text: 'הקמה מלאה הופכת את מגדלור ל"בעל המכשיר" — כמו אפליקציות האכיפה של ארגונים. רק כך אנדרואיד מאפשר:' }),
        el('ul', { class: 'hint' }, [
            'כפתור "הסרה" של מגדלור אפור — אי אפשר למחוק אותו.',
            'הסתרת אפליקציות (👻) — נעלמות מהטלפון.',
            'כרום מנוהל, ונעילת הגדרות הטלפון (Wi‑Fi, חשבונות, התקנות, איפוס).',
            'אפליקציות חסומות מושעות לגמרי, ועדכונים של מגדלור בלי לחיצה של הילד.',
        ].map(t => el('li', { text: t }))),
        tip('אנדרואיד מאפשר זאת רק בטלפון בלי חשבונות. יש שתי דרכים — בוחרים אחת:'),

        route('💻 דרך 1: דרך מחשב — בלי לאפס את הטלפון', 'מומלץ לטלפון שכבר בשימוש', true, [
            el('p', { class: 'muted', text: 'צריך: מחשב עם כרום או אדג׳, וכבל USB שמעביר נתונים. כ-15 דקות.' }),
            steps([
                [el('b', { text: 'מתקינים את מגדלור ומחברים אותו לילד' }), ' — כרגיל, בקוד החיבור.'],
                [el('b', { text: 'בטלפון: מגדלור ← ⚙️ ← "🛡️ הגנה מלאה". ' }), 'שם שלושה צעדים, עם כפתור שפותח כל מסך:'],
                [el('b', { text: 'מסירים את כל החשבונות: ' }), 'הגדרות ← חשבונות (בסמסונג: "חשבונות וגיבוי" ← "ניהול חשבונות") ← כל חשבון ← "הסרת חשבון". גוגל, סמסונג, וואטסאפ — כולם. הם חוזרים אחר כך.'],
                [el('b', { text: 'מוחקים משתמש נוסף, "תיקייה מאובטחת" ו"מרחב שני" ' }), '(בסמסונג גם "Dual Messenger") — אם יש.'],
                [el('b', { text: 'מפעילים "אפשרויות מפתחים": ' }), 'הגדרות ← אודות הטלפון ← (בסמסונג: "מידע תוכנה") ← נוגעים 7 פעמים ב"מספר Build". בשיאומי — 7 פעמים ב"גרסת MIUI / HyperOS".'],
                [el('b', { text: 'מדליקים "ניפוי USB": ' }), 'הגדרות ← אפשרויות מפתחים ← "ניפוי USB". בשיאומי — גם "ניפוי USB (הגדרות אבטחה)".'],
                [el('b', { text: 'במחשב, בכרום — פותחים את דף ההקמה: ' }), el('a', { href: '../setup/', target: '_blank', rel: 'noopener', text: 'דף ההקמה המלאה ↗' })],
                [el('b', { text: 'מחברים כבל ← "חיבור לטלפון" ← בוחרים את הטלפון ← בטלפון: "לאפשר ניפוי באגים". ' }), 'הדף בודק, מגדיר את מגדלור כבעל המכשיר, ומכבה את ניפוי ה-USB לבד.'],
                [el('b', { text: 'מחזירים את החשבונות: ' }), 'הוספת חשבונות חסומה עכשיו. כאן ← "📱 מכשירים" ← "⚙️ הגדרות המכשיר" ← "👤 חשבונות" ← מותר. מוסיפים בטלפון את חשבון גוגל, ואז חוסמים שוב.'],
            ]),
            tip('הדף לא שולח לשרת שום פרט מהטלפון — החשבונות מוצגים רק במסך שלכם, כדי שתדעו מה עוד להסיר.'),
        ]),

        route('📷 דרך 2: קוד QR — אחרי איפוס להגדרות יצרן', 'לטלפון חדש, או כשמוכנים למחוק הכול', false, [
            el('p', { class: 'muted', text: 'כל מה שבטלפון יימחק. אין צורך במחשב.' }),
            steps([
                'כאן ← הילד ← "📱 מכשירים" ← "📷 חיבור מכשיר חדש" ← "🛡️ הקמה מלאה — מכשיר חדש או מאופס". מופיע קוד QR.',
                'מאפסים את הטלפון להגדרות יצרן (הגדרות ← ניהול כללי / מערכת ← איפוס).',
                'במסך הפתיחה ("ברוכים הבאים") מקישים 6 פעמים על אותו מקום — נפתח סורק QR.',
                'סורקים את הקוד ומתחברים ל-Wi‑Fi. מגדלור יורד ומוגדר כבעל המכשיר לבד.',
                'כשמגדלור נפתח — סורקים את קוד החיבור (באותו מסך).',
                'חשבון גוגל: הוספת חשבונות חסומה כברירת מחדל. כאן ← "📱 מכשירים" ← "⚙️ הגדרות המכשיר" ← "👤 חשבונות" ← מותר; מוסיפים בטלפון, וחוסמים שוב.',
            ]),
        ]),

        route('✅ איך יודעים שהצליח', '', false, [
            el('ul', { class: 'hint' }, [
                'כאן, בלשונית "📱 מכשירים": השורה "⚠️ חסר בעל מכשיר" נעלמת, ומופיע "✓ כל ההרשאות · בעל מכשיר".',
                'בטלפון: מגדלור ← ⚙️ — השורה "🛡️ הגנה מלאה" כבר לא מופיעה.',
                'בטלפון: הגדרות ← אפליקציות ← מגדלור — כפתור "הסרה" אפור.',
                'לבדיקה מלאה: לשונית "📋 סיכום" ← "🛡️ בדיקת הגנה" ← "הרצת בדיקה".',
            ].map(t => el('li', { text: t }))),
        ]),

        route('🧰 נתקעתם?', '', false, [
            el('ul', { class: 'hint' }, [
                '"יש עדיין חשבונות" — חשבון אחד נשאר (לפעמים של סמסונג, וואטסאפ או טלגרם). הדף מציג בדיוק אילו.',
                '"יש משתמש נוסף" — "תיקייה מאובטחת", "מרחב שני", "Dual Messenger" או משתמש אורח. מוחקים, ומנסים שוב.',
                'הטלפון לא מופיע ברשימה במחשב — כבל שמעביר רק טעינה, או ניפוי USB כבוי. ב-Windows לפעמים צריך את מנהל ההתקן של היצרן.',
                '"הטלפון חוסם את הפקודה" (שיאומי) — מדליקים גם "ניפוי USB (הגדרות אבטחה)".',
                '"כבר יש בעל מכשיר אחר" — אפליקציית ניהול אחרת כבר שם; צריך להסיר אותה, או לאפס.',
                'שום דבר לא עובד — דרך 2 (QR אחרי איפוס) עובדת תמיד.',
            ].map(t => el('li', { text: t }))),
        ]),
    );
    $('#guide-dlg').showModal();
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

/* ── ניהול אפליקציות (שלב 11) ───────────────────────────────────── */

const APOST = { allow_all: ['🔓 הכול פתוח', 'כל האפליקציות פתוחות, חוץ ממה שתחסמו.'],
                deny_all: ['🔒 רק מה שאישרתי', 'רק האפליקציות שתאשרו ייפתחו. השאר חסומות.'] };
let AP = null;

/* ── 🛒 מרקט: מה משחררים לילד מתוך המלאי (lib/market.php) ── */
let MK = null;

async function loadMarket() {
    const kid = K.id;
    try { MK = { ...(await call('market', { id: kid })).market, kid }; }
    catch (e) { MK = { kid, error: e.message }; }
    if (tab === 'apps' && K && K.id === kid) renderKid();
}

function marketGroup() {
    if (!MK || MK.kid !== K.id) { loadMarket(); return group('🛒 מרקט', '', [el('p', { class: 'muted', text: 'טוען…' })]); }
    if (MK.error) return group('🛒 מרקט', '', [el('p', { class: 'error', text: MK.error })]);
    const save = async (action, data, ok) => {
        try { MK = { ...(await call(action, { id: K.id, ...data })).market, kid: K.id }; if (ok) toast(ok); AP = null; }
        catch (e) { toast(e.message, true); } renderKid();
    };
    const released = MK.apps.filter(a => a.released).length;
    const icon = a => a.icon ? el('img', { src: a.icon, alt: '', width: 32, height: 32, loading: 'lazy', referrerPolicy: 'no-referrer',
        style: 'border-radius:8px;flex:none' }) : el('span', { class: 'app-ic', text: '📱', ariaHidden: 'true' });
    const row = a => el('li', {}, [icon(a),
        el('span', { class: 'nm' }, [a.name, el('small', { text: `${a.min_age ? a.min_age + '+ · ' : ''}${a.installed ? 'מותקנת אצלו' : 'לא מותקנת'}${a.private ? ' · פרטית שלכם' : ''}` }),
            a.note ? el('small', { text: '⚠️ ' + a.note, style: 'color:var(--danger)' }) : null]),
        a.private ? el('button', { type: 'button', class: 'icon-btn', text: '🗑️', ariaLabel: `הסרה מהמלאי — ${a.name}`,
            onclick: () => confirm(`להסיר את ${a.name} מהמלאי שלכם?`) && save('market_remove', { market_id: a.id }, 'הוסר') }) : null,
        // כפתור אחד, קצר — ברוחב טלפון שני מצבים לא נכנסו בשורה.
        el('button', { type: 'button', class: a.released ? 'btn primary sm' : 'btn ghost sm', style: 'flex:none',
            text: a.released ? '✅ משוחררת' : '➕ שחרור', ariaPressed: String(a.released),
            onclick: () => save('market_release', { package: a.package, on: a.released ? 0 : 1 },
                a.released ? 'הוסרה מהמרקט שלו' : 'שוחררה — תופיע אצלו במרקט') }),
    ]);
    const cats = Object.entries(MK.categories).map(([k, [ic, name]]) => {
        const list = MK.apps.filter(a => a.category === k);
        if (!list.length) return null;
        const on = list.filter(a => a.released).length;
        return el('details', { class: 'group', open: on > 0 }, [
            el('summary', {}, [el('b', { text: `${ic} ${name}` }), el('small', { class: 'muted', text: ` — ${list.length} אפליקציות${on ? ` · ${on} משוחררות` : ''}` })]),
            el('ul', { class: 'rows apps' }, list.map(row))]);
    }).filter(Boolean);
    // אפליקציה פרטית מקישור Play — נוספת למלאי שלכם ומשוחררת לילד הזה.
    const link = el('input', { type: 'url', dir: 'ltr', placeholder: 'https://play.google.com/store/apps/details?id=…', style: 'flex:1;min-width:0' });
    const cat = el('select', {}, Object.entries(MK.categories).map(([k, [ic, n]]) => el('option', { value: k, text: `${ic} ${n}` })));
    const add = el('button', { type: 'button', class: 'btn', text: '➕ הוספה', onclick: () => {
        if (!link.value.trim()) return toast('מדביקים קישור של Google Play', true);
        save('market_add', { link: link.value.trim(), category: cat.value, min_age: 0 }, 'נוספה ושוחררה לו');
    } });
    return group('🛒 מרקט', released ? `${released} משוחררות` : 'בוחרים מה הוא יכול להתקין', [
        el('p', { class: 'hint', text: 'מה שמשחררים מופיע אצלו ברובריקת "📲 אפליקציות" עם "התקנה", ומותר לו לפתוח אותו — גם כשאפליקציה חדשה נחסמת.' }),
        ...cats,
        el('details', { class: 'group' }, [el('summary', {}, [el('b', { text: '➕ אפליקציה שאין במלאי' })]),
            el('p', { class: 'hint', text: 'מדביקים קישור מ-Google Play. היא נוספת למלאי שלכם בלבד, ומשוחררת לילד הזה.' }),
            el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, [link, cat, add])]),
    ]);
}

async function loadApps() {
    const kid = K.id;
    try { AP = { ...(await call('apps', { id: kid })).apps, kid }; }
    catch (e) { AP = { kid, error: e.message }; }
    if (tab === 'apps' && K && K.id === kid) renderKid();
}

function kidApps() {
    if (!AP || AP.kid !== K.id) { loadApps(); return [el('p', { class: 'empty', text: 'טוען…' })]; }
    if (AP.error) return [el('p', { class: 'error', text: AP.error })];
    const hint = el('p', { class: 'hint', text: APOST[AP.posture][1] });
    const posture = seg('apps_posture', Object.entries(APOST).map(([k, v]) => [k, v[0]]), AP.posture,
        { cols: 2, onchange: async v => {
            try { AP = { ...(await call('apps_posture', { id: K.id, posture: v })).apps, kid: K.id }; toast('נשמר'); }
            catch (e) { toast(e.message, true); } renderKid();
        } });
    // זמן מסך (lib/apptime.php): כמה שימשה היום (לפי המכשיר), והמגבלה.
    const usedLine = a => {
        const m = Math.round((a.used_sec || 0) / 60);
        if (a.limit_min) return `⏱️ ${m} מתוך ${a.limit_min} דק׳ היום${m >= a.limit_min ? ' — נגמר' : ''}`;
        return m ? `היום: ${m} דק׳` : '';
    };
    const setLimit = async a => {
        const v = prompt(`כמה דקות ביום ל${a.label}? (0 — בלי מגבלה)`, String(a.limit_min || ''));
        if (v === null) return;
        try { AP = { ...(await call('app_limit', { id: K.id, package: a.package, minutes: Number(v) || 0 })).apps, kid: K.id }; toast('נשמר — המכשיר יתעדכן תוך דקה'); }
        catch (e) { toast(e.message, true); } renderKid();
    };
    const row = a => el('li', {}, [
        el('span', { class: 'app-ic', ariaHidden: 'true', text: a.system ? '⚙️' : '📱' }),
        el('span', { class: 'nm' }, [a.label, el('small', { dir: 'ltr', text: a.package }),
            usedLine(a) ? el('small', { text: usedLine(a), style: a.limit_min && a.used_sec >= a.limit_min * 60 ? 'color:var(--danger)' : '' }) : null]),
        a.locked || a.blocked ? null : el('button', { type: 'button', class: 'icon-btn', text: '⏱️',
            ariaLabel: `זמן מסך — ${a.label}`, title: a.limit_min ? `${a.limit_min} דק׳ ביום` : 'בלי מגבלת זמן', onclick: () => setLimit(a) }),
        a.locked ? el('small', { class: 'muted', text: `✅ תמיד — ${a.why}` }) : seg(`ap_${a.package}`, [['allow', '✅'], ['', '—'], ['block', '🚫'], ['hide', '👻']], a.action,
            { cls: 'seg mini', onchange: async v => {
                try { AP = { ...(await call('app_rule', { id: K.id, package: a.package, app_action: v })).apps, kid: K.id }; }
                catch (e) { toast(e.message, true); } renderKid();
            } }),
    ]);
    const list = AP.apps.length
        ? el('ul', { class: 'rows apps' }, AP.apps.map(row))
        : el('div', { class: 'banner info' }, [el('span', { text: '📲' }), el('div', {}, [
            el('b', { text: 'מחכים שהמכשיר ידווח' }),
            'ברגע שהילד יפתח את מגדלור עם ההרשאה, רשימת האפליקציות שלו תופיע כאן.'])]);
    const warn = AP.access_missing > 0 && AP.devices > 0
        ? el('div', { class: 'banner warn' }, [el('span', { text: '⚠️' }), el('div', {}, [
            el('b', { text: 'החסימה עוד לא נאכפת' }),
            `במכשיר של ${K.name} חסר אישור "ניהול אפליקציות". פתחו במכשיר: מגדלור ← הקמה, או הגדרות ← נגישות ← מגדלור.`])])
        : null;
    // הסתרה (👻) דורשת "בעל מכשיר" — בלעדיו המוסתרות רק נחסמות, והאייקון נשאר.
    const hideWarn = AP.hidden_count > 0 && AP.owner_missing > 0
        ? el('div', { class: 'banner warn' }, [el('span', { text: '👻' }), el('div', {}, [
            el('b', { text: 'ההסתרה עוד לא פועלת במכשיר' }),
            'להסתרה צריך "הקמה מלאה" (בעל מכשיר). עד אז האפליקציות שסימנתם 👻 חסומות, אבל האייקון שלהן נשאר. ' +
            'בטלפון: מגדלור ← ⚙️ ← "🛡️ הגנה מלאה".'])])
        : null;
    // אפליקציה חדשה שתותקן (lib/appcontrol.php, new_apps) — בלי קשר לברירת המחדל של מה שכבר מותקן.
    const newApps = seg('apps_new', [['allow', '🔓 פתוחה'], ['block', '🔒 חסומה']], AP.new_apps,
        { cols: 2, onchange: async v => {
            try { AP = { ...(await call('apps_new', { id: K.id, new_apps: v })).apps, kid: K.id }; toast('נשמר — המכשיר יתעדכן תוך דקה'); }
            catch (e) { toast(e.message, true); } renderKid();
        } });
    // כרום מנוהל (lib/chrome.php): הכללים של הילד גם בתוך Google Chrome — רק ב"הקמה מלאה".
    const CH = AP.chrome || { managed: false, devices: [] };
    const CHR = { verified: '✅ נאכף בכרום', fail: '⚠️ ההחלה נכשלה — מגדלור ינסה שוב', no_owner: '⚠️ דורש הקמה מלאה',
                  not_installed: '· כרום לא מותקן', '': '⏳ עוד לא דווח' };
    const chrome = group('🌐 כרום מנוהל', '', [
        seg('chrome_managed', [['0', 'כבוי'], ['1', 'מנוהל']], CH.managed ? '1' : '0', { cols: 2, onchange: async v => {
            try { AP = { ...(await call('chrome_managed', { id: K.id, on: Number(v) })).apps, kid: K.id }; toast('נשמר — המכשיר יתעדכן תוך דקה'); }
            catch (e) { toast(e.message, true); } renderKid();
        } }),
        el('p', { class: 'hint', text: CH.managed
            ? 'בכרום: רק האתרים שמותרים לפי ההגדרות, חיפוש בטוח בגוגל, סינון תוכן למבוגרים, ובלי גלישה בסתר. ' +
              (CH.youtube ? 'יוטיוב פתוח (במצב מוגבל), כי ביוטיוב מוגדר "הכול מותר". ' : 'יוטיוב חסום בכרום — צופים בו דרך מגדלור, עם הערוצים שאישרתם. ') +
              'לא עובר לכרום: זמנים ומכסה (הגבילו את כרום ב-⏱️), סוגי תוכן וחסימת פרסומות. כרום עצמו צריך להיות מותר ברשימה למטה.'
            : 'כבוי: מה שקורה בכרום לא מסונן — לכן כדאי לחסום אותו (🚫 או 👻) ברשימה למטה. "מנוהל" מעביר לכרום את רשימות האתרים, חיפוש בטוח וחסימת גלישה בסתר — רק במכשיר עם הקמה מלאה.' }),
        CH.managed && CH.truncated ? el('p', { class: 'hint', style: 'color:var(--danger)', text: 'יותר מ-1000 כתובות ברשימה — כרום מקבל רק את ה-1000 הראשונות.' }) : null,
        CH.managed && CH.devices.length ? el('ul', { class: 'hint' }, CH.devices.map(d =>
            el('li', { text: `${d.name || 'מכשיר'}: ${d.owner ? (CHR[d.result] ?? d.result) : CHR.no_owner}` }))) : null,
    ].filter(Boolean));
    return [
        warn,
        hideWarn,
        chrome,
        marketGroup(),
        group('ברירת מחדל', '', [posture, hint]),
        group('אפליקציה חדשה שתותקן', '', [newApps, el('p', { class: 'hint', text: AP.new_apps === 'block'
            ? 'כל אפליקציה שתותקן מעכשיו — חסומה מיד, גם בלי אינטרנט. היא תופיע כאן ברשימה עם 🚫, ותוכלו לפתוח אותה. הילד יכול לבקש.'
            : 'כל אפליקציה שתותקן מעכשיו — פתוחה. היא תופיע כאן ברשימה, ותוכלו לחסום אותה.' })]),
        group('האפליקציות במכשיר', AP.apps.length ? `${AP.blocked_count} חסומות · ✅ מותר · — כברירת המחדל · 🚫 חסום · 👻 מוסתר · ⏱️ זמן ביום` : '', [list]),
        el('p', { class: 'hint', text: '👻 מוסתר: האפליקציה נעלמת מהטלפון — בלי אייקון, ולא ברשימת האפליקציות. הנתונים שלה נשמרים, וכשמבטלים את ההסתרה היא חוזרת כמו שהייתה. דורש "הקמה מלאה" (בעל מכשיר).' }),
        el('p', { class: 'hint', text: '⏱️ זמן מסך: מגבלת דקות ביום לאפליקציה. נמדד ונאכף רק כש"ניהול אפליקציות" (נגישות) פעיל במכשיר. נגמר הזמן — הילד יכול לבקש עוד, ואישור זמני גובר על המגבלה. מתחדש בחצות.' }),
    ];
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
            r.kind === 'app'
                ? el('div', { class: 'l2', text: `${r.code === 'market' ? '🛒 מהמרקט: ' : '📱 '}${r.host}${r.device ? ' · ' + r.device : ''}` })
                : el('div', { class: 'l2', dir: 'ltr', style: 'text-align:end', text: r.host }),
            r.note ? el('div', { class: 'l2', text: `"${r.note}"` }) : null,
        ]),
        el('button', { type: 'button', class: 'icon-btn', text: '✕', ariaLabel: `דחייה — ${r.host}`,
            onclick: () => r.kind === 'app' ? decideApp(r, 'deny') : decide(r, 'deny') }),
        el('button', { type: 'button', class: 'btn primary sm', text: '✓ אישור', onclick: () => r.code === 'market' ? decideApp(r, 'permanent')
            : r.kind === 'app' ? openAppDecide(r) : openDecide(r) }),
    ])));
    $('#req-recent').hidden = !done.length;
    $('#req-done').replaceChildren(...done.slice(0, 15).map(r => el('li', { class: 'card', style: 'cursor:default' }, [
        el('span', { text: r.status === 'approved' ? '✅' : '🚫', ariaHidden: 'true' }),
        el('div', { class: 'body' }, [
            el('div', { class: 'l1' }, [el('span', { text: `${r.child} · ${r.host}` })]),
            el('div', { class: 'l2', text: r.kind === 'app' ? appDoneLine(r) : r.status === 'approved'
                ? `אושר — ${((r.scopes.length === 2 ? RSCOPE_YT : RSCOPE)[r.scope] || [r.scope])[0]}` : 'נדחה' }),
        ]),
    ])));
}

/** בקשת אפליקציה שטופלה: ההחלטה, ומה המכשיר באמת עשה (לא רק "אושר"). */
function appDoneLine(r) {
    if (r.status === 'denied') return 'נדחה';
    if (r.code === 'market') return '🛒 שוחררה במרקט — אצל הילד עם "📥 התקנה"';
    const what = r.scope === 'temporary' ? `אושר ל-${minsLabel(r.minutes)}` : 'אושר — קבוע';
    if (r.apply_state === 'pending') return `${what} · ⏳ ממתין שהמכשיר יחיל`;
    if (r.apply_state === 'failed') return `${what} · ❌ לא נפתחה במכשיר: ${r.apply_error || 'סיבה לא ידועה'}`;
    if (r.apply_state === 'revoked') return `${what} · בוטל (שיניתם את הכלל של האפליקציה)`;
    if (r.scope === 'temporary' && r.ends_at) {
        const end = new Date(r.ends_at);
        return end > new Date() ? `${what} · ✅ פתוחה עד ${end.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' })}`
                                : `${what} · הסתיים — חסומה שוב`;
    }
    return `${what} · ✅ נפתחה במכשיר`;
}
const minsLabel = m => m < 60 ? `${m} דקות` : m === 60 ? 'שעה' : m === 120 ? 'שעתיים' : `${m / 60} שעות`;

/** אישור אפליקציה: קבוע (עד שמשנים), זמני (חלון זמן מרגע ההחלה במכשיר — לא מכסת שימוש), או סירוב. */
function openAppDecide(r) {
    const mins = seg('app_mins', [[15, '15 ד׳'], [30, '30 ד׳'], [60, 'שעה'], [120, 'שעתיים'], [240, '4 שעות']], 30, { cols: 5 });
    $('#req-dlg-title').textContent = `${r.child} מבקש/ת לפתוח את ${r.host}`;
    $('#req-dlg-body').replaceChildren(
        el('p', { class: 'hint', text: `${r.device ? 'במכשיר ' + r.device + ' · ' : ''}${ago(r.created_at)}` }),
        r.note ? el('p', { class: 'hint', text: `למה: "${r.note}"` }) : null,
        group('⏱️ זמני', 'חלון זמן שמתחיל כשהמכשיר מקבל את האישור, ואז האפליקציה נחסמת שוב — גם אם היא פתוחה, וגם בלי אינטרנט', [
            mins,
            el('button', { type: 'button', class: 'btn primary', text: '⏱️ פתיחה זמנית', onclick: () => {
                $('#req-dlg').close(); decideApp(r, 'temporary', Number($('[name=app_mins]:checked').value)); } }),
        ]),
        el('div', { class: 'row-btns' }, [
            el('button', { type: 'button', class: 'btn ghost', text: '✕ סירוב', onclick: () => { $('#req-dlg').close(); decideApp(r, 'deny'); } }),
            el('button', { type: 'button', class: 'btn', text: '✅ פתיחה קבועה', onclick: () => {
                $('#req-dlg').close(); decideApp(r, 'permanent'); } }),
        ]));
    if (!$('#req-dlg').open) $('#req-dlg').showModal();
}

async function decideApp(r, decision, minutes = 0) {
    try {
        const res = await call('app_request_decide', { req: r.id, decision, minutes });
        R = res.requests; renderRequests(); loadKids(); toast(res.message);
    } catch (e) { toast(e.message, true); loadRequests(); }
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
$('#m-guide').addEventListener('click', () => { $('#menu-dlg').close(); showOwnerGuide(); });
$('#m-install').addEventListener('click', async () => {
    if (installPrompt) { installPrompt.prompt(); await installPrompt.userChoice; installPrompt = null; $('#menu-dlg').close(); return; }
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    $('#install-steps').replaceChildren(...(ios
        ? ['בספארי: כפתור השיתוף (ריבוע עם חץ למעלה)', 'גללו ובחרו "הוספה למסך הבית"', 'לחצו "הוספה"']
        : ['בכרום: תפריט ⋮ למעלה', 'בחרו "התקנת אפליקציה" (או "הוספה למסך הבית")', 'אשרו']).map(t => el('li', { text: t })));
    $('#install-box').hidden = false;
});
$('#m-pass').addEventListener('click', () => { $('#pass-box').hidden = false; $('#p-cur').focus(); });
$('#m-link').addEventListener('click', () => {
    $('#menu-dlg').close();
    $('#link-sub').textContent = 'חיבור חשבון ישן';
    $('#link-none').hidden = true; $('#link-back').hidden = false;
    showOnly('#linkacc', () => $('#link-form').login.focus());
});
$('#m-out').addEventListener('click', async () => { try { await call('logout', {}); } finally { location.reload(); } });
$('#p-go').addEventListener('click', async () => {
    try {
        await call('password', { current: $('#p-cur').value, next: $('#p-new').value });
        $('#p-cur').value = $('#p-new').value = ''; $('#menu-dlg').close(); toast('הסיסמה הוחלפה');
    } catch (x) { toast(x.message, true); }
});

boot();
