/**
 * העורך בתוך iframe של מארחת — platform/docs/embed.md, פרקים 4 ו-6.
 * הטוקן מהכתובת → embed_open → סשן בזיכרון בלבד (Bearer, לא עוגייה). כל הודעה יוצאת רק ל-return_origin,
 * וכל הודעה נכנסת נבדקת: מ-origin הזה ומהחלון המארח בלבד.
 */
import { createWordEditor, h, toast } from './editor.js';
import { createSaver, findDraft, drafts, STATE_TEXT } from './saver.js';

const app = document.getElementById('app');
const params = new URLSearchParams(location.search);
const token = params.get('t') || '';
history.replaceState(null, '', location.pathname);   // הטוקן לא נשאר בכתובת ולא בהיסטוריה

let session = '';
let returnOrigin = '';
let docId = '';
let can = 'view';
let requested = 'view';
let mode = 'document';
let dirty = false;   // מה שהמארחת יודעת (ההודעה change)
let ed = null;
let saver = null;
let ui = {};

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
    ui.banner.dataset.kind = '';
    ui.banner.append(h('span', text), label ? h('button.btn.sm.ghost', { onclick: onClick }, label) : null);
    ui.banner.hidden = false;
}

/** שינוי במסמך → טיוטה במכשיר ושמירה (saver.js). המארחת מקבלת change רק כשהמצב מתהפך. */
function changed() {
    if (can !== 'edit' || !saver) return;
    saver.changed();
    syncDirty();
}
function syncDirty() {
    const d = !!saver?.dirty;
    if (d !== dirty) { dirty = d; post('change', { dirty }); }
}

/** שמירה עכשיו. מחזיר { ok, code?, message? } — הודעות saved / error למארחת נשלחות מ-onState. */
async function save() {
    if (!saver || can !== 'edit' || !session) { syncDirty(); return { ok: !saver?.dirty }; }
    const r = await saver.flush();
    syncDirty();
    return r;
}

function onSaveState(s, info) {
    if (ui.state) ui.state.textContent = can === 'edit' || s !== 'saved' ? STATE_TEXT[s] : 'קריאה בלבד';
    ui.state?.classList.toggle('bad', ['offline', 'conflict', 'error'].includes(s));
    if (s === 'saved' && info.version) post('saved', { doc: docId, version: info.version, updated_at: info.updated_at });
    if (s === 'saved' || s === 'dirty') syncDirty();
    if (s === 'offline') showBanner('אין חיבור לשרת. השינויים שמורים במכשיר ויישלחו כשהחיבור יחזור.');   // error/offline — כבר נשלח מ-api()
    else if (s === 'saved' && ui.banner && !ui.banner.hidden && ui.banner.dataset.kind === 'offline') ui.banner.hidden = true;
    if (s === 'conflict') {
        post('error', { code: 'conflict', message: info.message || 'המסמך השתנה במקום אחר' });
        showBanner('המסמך השתנה במקום אחר. השינויים שלך שמורים במכשיר.', 'טעינה מחדש', reloadFromServer);
    }
    if (s === 'locked') { post('error', { code: 'locked', message: info.message }); readOnlyBecause(info.message || 'המסמך פתוח לעריכה אצל משתמש אחר'); }
    if (s === 'error' && info.code !== 'expired') { post('error', { code: info.code === 'schema_newer' ? 'conflict' : 'save_failed', message: info.message }); toast(info.message || 'השמירה נכשלה', true); }
    if (ui.banner && s === 'offline') ui.banner.dataset.kind = 'offline';
}

async function reloadFromServer() {
    const fresh = await api('embed_load');
    ed.setContent(fresh.json);
    can = fresh.can;
    ed.setEditable(can === 'edit');
    saver.reset(fresh.version);
    ui.banner.hidden = can === 'edit';
    syncDirty();
}

function readOnlyBecause(text, lock = null) {
    can = 'view';
    saver?.stop();
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
            if (!dirty) post('change', { dirty: false });   // host.js מחכה ל-saved או ל-change:false
            break;
        case 'set_page':
            // ההגדרות נכנסות למסמך עצמו ונשמרות איתו (באותה גרסה), כמו שינוי מהתפריט
            if (m.settings && typeof m.settings === 'object') ed?.setPage(m.settings);
            break;
        case 'readonly':
            if (m.value) { can = 'view'; ed?.setEditable(false); }
            else if (requested === 'edit') {
                const l = await api('embed_lock', { op: 'take' }).catch(() => null);
                if (l?.ok) await reloadFromServer();
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
    can = d.can;
    requested = d.requested || d.can;
    mode = d.mode;

    app.textContent = '';
    ui.state = h('span.save-state', { role: 'status' });
    ui.pages = h('span.save-state.pages-count');
    ui.title = h('div.titles.one', d.title || (mode === 'section' ? 'עריכת קטע' : 'מסמך'));
    ui.bar = h('div.embed-bar', ui.title, ui.pages, ui.state,
        h('button.btn.sm.ghost', { onclick: async () => { const r = await save(); post('close', { saved: r.ok && !dirty }); } }, 'סגירה'));
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
        // הגדרות העמוד בתוך המסמך — נשמרות איתו; למארחת רק מודיעים (page)
        onPage: settings => post('page', { settings }),
    });
    ed.statusTarget(ui.pages);
    // טיוטה במכשיר: לפי לקוח, משתמש ומסמך (session מתחלף בכל פתיחה)
    const draftKey = `embed:${d.client || ''}:${d.user?.id || d.user?.name || ''}:${docId}`;
    saver = createSaver({
        version: d.version,
        draftKey,
        snapshot: () => ed.getJSON(),
        send: (json, base) => api('embed_save', { json, base_version: base }),
        onState: onSaveState,
    });
    ui.state.textContent = can === 'edit' ? STATE_TEXT.saved : 'קריאה בלבד';
    if (d.lock) readOnlyBecause(`פתוח לעריכה אצל ${d.lock.by} — קריאה בלבד`, d.lock);
    new ResizeObserver(reportHeight).observe(app.querySelector('.wp-zoom-box'));
    post('ready', { doc: docId, can, version: d.version });
    findDraft(draftKey, JSON.stringify(ed.getJSON())).then(dr => {
        if (!dr || can !== 'edit') return;
        showBanner('נמצאו במכשיר שינויים שלא נשמרו' + (dr.base_version !== d.version ? ' (המסמך השתנה מאז — שחזור ידרוס)' : '') + '.', 'שחזור', () => {
            ed.setContent(dr.json);
            saver.markDirty(saver.version);
            ui.banner.hidden = true;
            syncDirty();
        });
    });

    // דופק כל 30 שניות: מחזיק את הנעילה; כשמישהו אחר נעל — בודק אם התפנתה
    setInterval(async () => {
        if (!session || requested !== 'edit') return;
        try {
            const l = await api('embed_lock', { op: 'beat' });
            if (l.ok && can !== 'edit') {
                showBanner('המסמך התפנה לעריכה.', 'מעבר לעריכה', reloadFromServer);
            } else if (!l.ok && can === 'edit') {
                readOnlyBecause(`פתוח לעריכה אצל ${l.lock?.by || 'משתמש אחר'} — קריאה בלבד`, l.lock);
            }
        } catch { /* רשת — בדופק הבא */ }
    }, 30000);
    window.addEventListener('pagehide', () => {
        if (saver?.dirty) save();   // ואם לא יספיק — הטיוטה במכשיר
        if (can === 'edit' && session) {
            fetch('api.php?action=embed_lock', { method: 'POST', keepalive: true, credentials: 'omit',
                headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + session, 'X-Embed-Session': session },
                body: JSON.stringify({ op: 'release' }) }).catch(() => {});
        }
    });
})();
