/**
 * העורך בתוך iframe של מארחת — platform/docs/embed.md, פרקים 4 ו-6.
 * הטוקן מהכתובת → embed_open → סשן בזיכרון בלבד (Bearer, לא עוגייה). כל הודעה יוצאת רק ל-return_origin,
 * וכל הודעה נכנסת נבדקת: מ-origin הזה ומהחלון המארח בלבד.
 */
import { createWordEditor, h, toast } from './editor.js';

const app = document.getElementById('app');
const params = new URLSearchParams(location.search);
const token = params.get('t') || '';
history.replaceState(null, '', location.pathname);   // הטוקן לא נשאר בכתובת ולא בהיסטוריה

let session = '';
let returnOrigin = '';
let docId = '';
let version = 0;
let can = 'view';
let requested = 'view';
let mode = 'document';
let dirty = false;
let saving = null;
let timer = 0;
let ed = null;
let ui = {};
let lastSaved = '';

function post(type, data = {}) {
    if (!returnOrigin || window.parent === window) return;
    window.parent.postMessage({ source: 'word-processor', type, ...data }, returnOrigin);
}

async function api(action, body = {}, form = null) {
    let res;
    try {
        res = await fetch('api.php?action=' + action, {
            method: 'POST',
            headers: form ? { Authorization: 'Bearer ' + session, 'X-Embed-Session': session }
                : { 'Content-Type': 'application/json', Authorization: 'Bearer ' + session, 'X-Embed-Session': session },
            body: form || JSON.stringify(body),
            credentials: 'omit',
            cache: 'no-store',
        });
    } catch {
        post('error', { code: 'offline', message: 'אין חיבור לעורך' });
        throw Object.assign(new Error('אין חיבור לשרת'), { code: 'offline' });
    }
    const data = await res.json().catch(() => ({ error: 'תשובה לא תקינה', code: 'server' }));
    if (!res.ok) {
        if (data.code === 'expired' || data.code === 'pending') expired();
        throw Object.assign(new Error(data.error || 'שגיאה'), { code: data.code, status: res.status });
    }
    return data;
}

function expired() {
    if (expired.done) return;
    expired.done = true;
    session = '';
    ed?.setEditable(false);
    if (returnOrigin) post('expired');
    else window.parent?.postMessage({ source: 'word-processor', type: 'expired' }, '*');   // אין עדיין origin ידוע; אין בהודעה מידע
    showBanner('הסשן של העורך פג — פותחים אותו מחדש…');
}

function showBanner(text, label, onClick) {
    if (!ui.banner) return toast(text);
    ui.banner.textContent = '';
    ui.banner.append(h('span', text), label ? h('button.btn.sm.ghost', { onclick: onClick }, label) : null);
    ui.banner.hidden = false;
}

function setState(text) {
    if (ui.state) ui.state.textContent = text || (dirty ? 'לא נשמר' : 'נשמר');
}

function changed() {
    if (can !== 'edit' || !ed) return;
    if (!dirty && JSON.stringify(ed.getJSON()) === lastSaved) return;   // טרנזקציה שלא שינתה כלום
    if (!dirty) post('change', { dirty: true });
    dirty = true;
    setState();
    clearTimeout(timer);
    timer = setTimeout(save, 1500);
}

async function save() {
    clearTimeout(timer);
    if (!dirty || can !== 'edit' || !session) return { ok: !dirty };
    if (saving) { await saving; return save(); }
    dirty = false;
    const json = ed.getJSON();
    const str = JSON.stringify(json);
    if (str === lastSaved) { setState(); post('change', { dirty: false }); return { ok: true }; }
    setState('שומר…');
    saving = api('embed_save', { json, base_version: version })
        .then(r => {
            version = r.version;
            lastSaved = str;
            setState();
            post('saved', { doc: docId, version: r.version, updated_at: r.updated_at });
            if (!dirty) post('change', { dirty: false });
            return { ok: true };
        })
        .catch(e => {
            dirty = true;
            setState('לא נשמר');
            const code = e.code === 'conflict' ? 'conflict' : e.code === 'offline' ? 'offline' : e.code === 'locked' ? 'locked' : 'save_failed';
            if (e.code !== 'offline') post('error', { code, message: e.message });
            if (e.code === 'locked') readOnlyBecause(e.message);
            else if (e.code === 'offline') timer = setTimeout(save, 5000);
            else toast(e.message, true);
            return { ok: false };
        })
        .finally(() => { saving = null; });
    return saving;
}

function readOnlyBecause(text, lock = null) {
    can = 'view';
    ed?.setEditable(false);
    showBanner(text);
    if (lock) post('lock', { by: lock.by, since: lock.since });
}

// ---------- הודעות מהמארחת ----------
window.addEventListener('message', async e => {
    if (!returnOrigin || e.origin !== returnOrigin || e.source !== window.parent) return;
    const m = e.data;
    if (!m || typeof m !== 'object') return;
    switch (m.type) {
        case 'save':
            await save();
            if (!dirty) post('change', { dirty: false });
            break;
        case 'set_page':
            if (m.settings && typeof m.settings === 'object') {
                ed?.setPage(m.settings);
                if (can === 'edit') api('embed_page', { page: ed.page }).catch(() => {});
            }
            break;
        case 'readonly':
            if (m.value) { can = 'view'; ed?.setEditable(false); }
            else if (requested === 'edit') {
                const l = await api('embed_lock', { op: 'take' }).catch(() => null);
                if (l?.ok) { can = 'edit'; ed?.setEditable(true); }
                else if (l?.lock) readOnlyBecause(`פתוח לעריכה אצל ${l.lock.by}`, l.lock);
            }
            break;
        case 'focus':
            ed?.focus();
            break;
    }
});

// ---------- גובה ----------
let lastH = 0;
function reportHeight() {
    if (!ed) return;
    const stage = app.querySelector('.wp-stage');
    const box = app.querySelector('.wp-zoom-box');
    const px = Math.ceil(app.querySelector('.wp-toolbar').offsetHeight + (ui.bar?.offsetHeight || 0) + (box?.offsetHeight || 0)
        + parseFloat(getComputedStyle(stage).paddingTop) + parseFloat(getComputedStyle(stage).paddingBottom));
    if (Math.abs(px - lastH) > 2) { lastH = px; post('height', { px }); }
}

// ---------- התחלה ----------
(async () => {
    if (app.dataset.valid !== '1' || !token) return expired();
    let d;
    try {
        const r = await fetch('api.php?action=embed_open', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ t: token }), credentials: 'omit' });
        d = await r.json();
        if (!r.ok) {
            if (d.code === 'expired') return expired();
            throw new Error(d.error || 'העורך לא נפתח');
        }
    } catch (e) {
        app.textContent = '';
        app.append(h('p.error', e.message || 'העורך לא נפתח'));
        return;
    }
    session = d.session;
    returnOrigin = d.return_origin;
    docId = d.doc;
    version = d.version;
    can = d.can;
    requested = d.requested || d.can;
    mode = d.mode;

    app.textContent = '';
    ui.state = h('span.save-state', { role: 'status' });
    ui.title = h('div.titles.one', d.title || (mode === 'section' ? 'עריכת קטע' : 'מסמך'));
    ui.bar = h('div.embed-bar', ui.title, ui.state,
        h('button.btn.sm.ghost', { onclick: async () => { const r = await save(); post('close', { saved: r.ok !== false && !dirty }); } }, 'סגירה'));
    ui.banner = h('div.banner', { hidden: true });
    const root = h('div');
    app.append(ui.bar, ui.banner, root);
    ed = createWordEditor(root, {
        json: d.json, page: d.page, editable: can === 'edit',
        upload: file => { const fd = new FormData(); fd.append('file', file); return api('embed_upload', {}, fd); },
        versions: {
            list: async () => (await api('embed_load', { op: 'versions' })).versions,
            get: id => api('embed_load', { op: 'version', id }),
        },
        onUpdate: changed,
        onPage: async settings => {
            try {
                const r = await api('embed_page', { page: settings });
                post('page', { settings: r.page });
            } catch (e) { toast(e.message, true); }
        },
    });
    lastSaved = JSON.stringify(ed.getJSON());
    setState(can === 'edit' ? 'נשמר' : 'קריאה בלבד');
    if (d.lock) readOnlyBecause(`פתוח לעריכה אצל ${d.lock.by} — קריאה בלבד`, d.lock);
    new ResizeObserver(reportHeight).observe(app.querySelector('.wp-zoom-box'));
    post('ready', { doc: docId, can, version });

    // דופק כל 30 שניות: מחזיק את הנעילה; כשמישהו אחר נעל — בודק אם התפנתה
    setInterval(async () => {
        if (!session || requested !== 'edit') return;
        try {
            const l = await api('embed_lock', { op: 'beat' });
            if (l.ok && can !== 'edit') {
                showBanner('המסמך התפנה לעריכה.', 'מעבר לעריכה', async () => {
                    const fresh = await api('embed_load');
                    version = fresh.version;
                    ed.setContent(fresh.json);
                    lastSaved = JSON.stringify(ed.getJSON());
                    ed.setPage(fresh.page);
                    can = fresh.can;
                    ed.setEditable(can === 'edit');
                    ui.banner.hidden = can === 'edit';
                    setState();
                });
            } else if (!l.ok && can === 'edit') {
                readOnlyBecause(`פתוח לעריכה אצל ${l.lock?.by || 'משתמש אחר'} — קריאה בלבד`, l.lock);
            }
        } catch { /* רשת — בדופק הבא */ }
    }, 30000);
    window.addEventListener('pagehide', () => {
        if (dirty) save();
        if (can === 'edit' && session) {
            fetch('api.php?action=embed_lock', { method: 'POST', keepalive: true, credentials: 'omit',
                headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + session, 'X-Embed-Session': session },
                body: JSON.stringify({ op: 'release' }) }).catch(() => {});
        }
    });
})();
