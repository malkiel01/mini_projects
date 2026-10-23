/**
 * עריכה בקישור לזר (שלב 5 — lib/links.php): guest.html#k=<טוקן>. בלי חשבון ובלי כניסה — מסמך אחד בלבד.
 * הזר מקליד שם (נשמר במכשיר), ומקבל מזהה אקראי במכשיר (gid) — הנעילה, ההערות ומעקב השינויים לפיהם.
 * הטוקן אחרי # — לא נשלח לשרת בטעינת הדף ולא ב-Referer; רק בגוף הבקשות ל-API. כל בקשה נבדקת מחדש בשרת:
 * קישור שבוטל או פג מפסיק לעבוד מיד.
 */
import { createWordEditor, h, toast } from './editor.js';
import { createSaver, findDraft, STATE_TEXT } from './saver.js';

const app = document.getElementById('app');
const k = new URLSearchParams(location.hash.slice(1)).get('k') || '';
const store = {
    get: key => { try { return localStorage.getItem('wp-guest-' + key) || ''; } catch { return ''; } },
    set: (key, v) => { try { localStorage.setItem('wp-guest-' + key, v); } catch { /* בלי אחסון — רק לפעם הזו */ } },
};
let gid = store.get('id');
if (!/^[a-z0-9]{8,40}$/.test(gid)) { gid = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => (b % 36).toString(36)).join(''); store.set('id', gid); }
let name = store.get('name');
let can = 'view';
let ed = null;
let saver = null;
const ui = {};

async function api(action, body = {}, form = null) {
    let res;
    try {
        if (form) { form.append('k', k); form.append('gid', gid); form.append('name', name); }
        res = await fetch('api.php?action=' + action, {
            method: 'POST',
            headers: form ? { 'X-WP': '1' } : { 'Content-Type': 'application/json', 'X-WP': '1' },
            body: form || JSON.stringify({ ...body, k, gid, name }),
            credentials: 'omit',
            cache: 'no-store',
        });
    } catch {
        throw Object.assign(new Error('אין חיבור לשרת'), { code: 'offline' });
    }
    const data = await res.json().catch(() => ({ error: 'תשובה לא תקינה מהשרת', code: 'server' }));
    if (!res.ok) {
        if (data.code === 'link_invalid' && ed) readOnly(data.error);
        throw Object.assign(new Error(data.error || 'שגיאה'), { code: data.code, status: res.status });
    }
    return data;
}

function showBanner(text, label, onClick) {
    ui.banner.textContent = '';
    ui.banner.append(h('span', text), label ? h('button.btn.sm.ghost', { onclick: onClick }, label) : null);
    ui.banner.hidden = false;
}

function readOnly(text) {
    can = 'view';
    saver?.stop();
    ed?.setEditable(false);
    if (ui.state) ui.state.textContent = 'קריאה בלבד';
    showBanner(text);
}

function message(text, extra = null) {
    app.textContent = '';
    app.append(h('div.login-box', h('h2', 'עורך התמלילים'), h('p', text), extra));
}

/** השם שיופיע בהערות ובמעקב השינויים ("(אורח)" נוסף בשרת). */
function askName(error = '') {
    return new Promise(resolve => {
        const input = h('input', { name: 'name', value: name, maxlength: 60, required: true, autocomplete: 'name', 'aria-label': 'השם שלך' });
        const form = h('form.login-box', { onsubmit: e => { e.preventDefault(); name = input.value.trim(); if (!name) return; store.set('name', name); resolve(); } },
            h('h2', 'עריכה בקישור'),
            h('p.muted', 'קיבלתם קישור לעריכת מסמך. השם שלכם יופיע ליד ההערות והשינויים שתעשו.'),
            error ? h('p.error', error) : null,
            input,
            h('button.btn.primary', { type: 'submit' }, 'פתיחת המסמך'));
        app.textContent = '';
        app.append(form);
        input.focus();
    });
}

(async () => {
    if (!/^[a-z0-9]{32}$/.test(k)) return message('הקישור לא שלם. העתיקו אותו שוב, במלואו.');
    if (!name) await askName();
    let d;
    for (;;) {
        try {
            d = await api('guest_open');
            break;
        } catch (e) {
            if (e.code === 'bad_name') { await askName(e.message); continue; }
            return message(e.message);
        }
    }
    can = d.can;
    app.textContent = '';
    ui.state = h('span.save-state', { role: 'status' });
    ui.pages = h('span.save-state.pages-count');
    ui.bar = h('div.embed-bar', h('div.titles.one', d.title || 'מסמך'), ui.pages, ui.state,
        h('span.chip', { title: 'עריכה בקישור, בלי חשבון' }, d.user.name));
    ui.banner = h('div.banner', { hidden: true });
    const root = h('div');
    app.append(ui.bar, ui.banner, root);
    ed = createWordEditor(root, {
        json: d.json, page: d.page, editable: can === 'edit', user: d.user,
        upload: file => { const fd = new FormData(); fd.append('file', file); return api('guest_upload', {}, fd); },
        onUpdate: () => { if (can === 'edit') saver?.changed(); },
        save: async () => { const r = await saver?.flush(); if (r?.ok) toast('נשמר'); },
    });
    ed.statusTarget(ui.pages);
    const draftKey = `guest:${gid}:${d.doc}`;
    saver = createSaver({
        version: d.version,
        draftKey,
        snapshot: () => ed.getJSON(),
        send: (json, base) => api('guest_save', { json, base_version: base }),
        onState: (s, info) => {
            ui.state.textContent = can === 'edit' || s !== 'saved' ? STATE_TEXT[s] : 'קריאה בלבד';
            ui.state.classList.toggle('bad', ['offline', 'conflict', 'error'].includes(s));
            if (s === 'conflict') showBanner('המסמך השתנה במקום אחר. השינויים שלך שמורים במכשיר.', 'טעינה מחדש', () => location.reload());
            else if (s === 'locked') readOnly((info.message || 'המסמך פתוח לעריכה אצל מישהו אחר') + ' — קריאה בלבד.');
            else if (s === 'offline') showBanner('אין חיבור לשרת. ממשיכים לעבוד — הכול נשמר במכשיר ויישלח כשהחיבור יחזור.');
            else if (s === 'error' && info.code !== 'link_invalid') toast(info.message || 'השמירה נכשלה', true);
            else if (s === 'saved' && !ui.banner.hidden && can === 'edit') ui.banner.hidden = true;
        },
    });
    ui.state.textContent = can === 'edit' ? STATE_TEXT.saved : 'קריאה בלבד';
    if (d.lock) readOnly(`המסמך פתוח לעריכה אצל ${d.lock.by} — קריאה בלבד. נסו שוב כשיתפנה.`);
    findDraft(draftKey, JSON.stringify(ed.getJSON())).then(dr => {
        if (!dr || can !== 'edit') return;
        showBanner('נמצאו במכשיר שינויים שלא נשמרו' + (dr.base_version !== d.version ? ' (המסמך השתנה מאז — שחזור ידרוס)' : '') + '.', 'שחזור', () => {
            ed.setContent(dr.json);
            saver.markDirty(saver.version);
            ui.banner.hidden = true;
        });
    });

    // דופק כל 30 שניות: מחזיק את הנעילה, ובודק שהקישור עדיין בתוקף
    setInterval(async () => {
        try {
            const l = await api('guest_lock', { op: 'beat' });
            if (l.ok && can !== 'edit' && !ui.banner.hidden) showBanner('המסמך התפנה לעריכה.', 'טעינה מחדש', () => location.reload());
            else if (!l.ok && can === 'edit') readOnly(`המסמך פתוח לעריכה אצל ${l.lock?.by || 'מישהו אחר'} — קריאה בלבד.`);
        } catch { /* רשת — בדופק הבא; קישור שבוטל — api() כבר עבר לקריאה בלבד */ }
    }, 30000);
    window.addEventListener('pagehide', () => {
        if (saver?.dirty) saver.flush();   // ואם לא יספיק — הטיוטה במכשיר
        if (can === 'edit') {
            fetch('api.php?action=guest_lock', { method: 'POST', keepalive: true, credentials: 'omit',
                headers: { 'Content-Type': 'application/json', 'X-WP': '1' }, body: JSON.stringify({ op: 'release', k, gid, name }) }).catch(() => {});
        }
    });
})();
