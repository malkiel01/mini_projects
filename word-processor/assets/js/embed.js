/**
 * העורך בתוך iframe של מארחת — platform/docs/embed.md, פרקים 4 ו-6.
 * הטוקן מהכתובת → embed_open → סשן בזיכרון בלבד (Bearer, לא עוגייה). כל הודעה יוצאת רק ל-return_origin,
 * וכל הודעה נכנסת נבדקת: מ-origin הזה ומהחלון המארח בלבד.
 * עריכה משותפת (collab.js / embed_collab) — כמו באפליקציה: כמה עורכים יחד (גם מהאפליקציה ומקישור לזר), בלי נעילה;
 * רואים מי במסמך ואיפה. צופה — רואה את השינויים חיים. טעינה מחדש (שחזור גרסה, שמירה מבחוץ) — במקום, בלי לרענן
 * את הדף (הטוקן כבר נוצל).
 */
import { createWordEditor, h, toast } from './editor.js';
import { findDraft, drafts, STATE_TEXT } from './saver.js';
import { createCollabSaver, setPeers } from './collab.js';

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
let blocked = false;   // קריאה בלבד בגלל נעילה ישנה של מישהו אחר — עד שתתפנה
let user = null;
let client = '';
const clientID = Array.from(crypto.getRandomValues(new Uint8Array(10)), b => (b % 36).toString(36)).join('');

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
            keepalive: body.op === 'leave',   // יציאה מהנוכחות — גם כשהחלון נסגר
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
    // עד שהמסמך נקי — "נשמר" למארחת רק כשכל מה שהוקלד בשרת (ביקורת 03, F08)
    const r = await saver.flushUntilClean();
    syncDirty();
    return r;
}

let lastState = 'saved';
function onSaveState(s, info) {
    if (ui.state) ui.state.textContent = can === 'edit' || s !== 'saved' ? STATE_TEXT[s] : 'קריאה בלבד';
    ui.state?.classList.toggle('bad', ['offline', 'conflict', 'error'].includes(s));
    // saved למארחת — רק במעבר לשמור (כל משיכה בעריכה המשותפת מדווחת "נשמר" שוב)
    if (s === 'saved' && info.version && lastState !== 'saved') post('saved', { doc: docId, version: info.version, updated_at: info.updated_at });
    lastState = s;
    if (s === 'saved' || s === 'dirty') syncDirty();
    if (s === 'offline') showBanner(saver.local === 'failed' ? 'אין חיבור לשרת, ואי אפשר לשמור במכשיר (האחסון חסום או מלא) — אל תסגרו את החלון.'
        : 'אין חיבור לשרת. השינויים שמורים במכשיר ויישלחו כשהחיבור יחזור.');
    if (info.localFailed && s !== 'saved') showBanner('אי אפשר לשמור טיוטה במכשיר (האחסון חסום או מלא). השינויים נשמרים רק בשרת — כשיש חיבור.');   // error/offline — כבר נשלח מ-api()
    else if (s === 'saved' && ui.banner && !ui.banner.hidden && ui.banner.dataset.kind === 'offline') ui.banner.hidden = true;
    if (s === 'locked') { post('error', { code: 'locked', message: info.message }); readOnlyBecause(info.message || 'המסמך פתוח לעריכה אצל משתמש אחר'); }
    if (s === 'error' && info.code !== 'expired') { post('error', { code: info.code === 'schema_newer' ? 'conflict' : 'save_failed', message: info.message }); toast(info.message || 'השמירה נכשלה', true); }
    if (ui.banner && s === 'offline') ui.banner.dataset.kind = 'offline';
}

/**
 * טעינה מחדש במקום (עידן חדש בעריכה המשותפת: שחזור גרסה, שמירה מבחוץ; או מעבר לעריכה): מה שלא נשלח — נשמר בנפרד
 * במכשיר (ביקורת 03, F09) ומוצע לשחזור; העורך נבנה מחדש מהמסמך שבשרת.
 */
async function reopen(notice = false) {
    if (reopen.busy) return;
    reopen.busy = true;
    try {
        const kept = saver?.dirty && await saver.keepConflictCopy();
        saver?.destroy(!kept);
        if (kept) await drafts.del(saver.draftKey);
        const fresh = await api('embed_load', { collab: 1 });
        ed?.destroy();
        mount(fresh);
        if (kept && can === 'edit') showBanner('הגרסה שלך מלפני הטעינה מחדש שמורה בנפרד במכשיר.', 'שחזור הגרסה שלי', restoreConflictCopy);
        else if (notice) toast('המסמך נטען מחדש — הוא השתנה במקום אחר.');
    } finally {
        reopen.busy = false;
    }
}
async function restoreConflictCopy() {
    const c = await drafts.get(saver.draftKey + ':conflict');
    if (!c?.json) return;
    ed.setContent(c.json);
    saver.markDirty(saver.version);
    drafts.del(saver.draftKey + ':conflict');
    ui.banner.hidden = true;
    syncDirty();
}

function readOnlyBecause(text, lock = null) {
    blocked = !!lock || blocked;
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
            // קריאה בלבד מהמארחת — גם השמירה נעצרת (ביקורת 03, F09); בקשה שכבר יצאה — נגמרת כרגיל
            if (m.value) { can = 'view'; saver?.stop(); ed?.setEditable(false); }
            else if (requested === 'edit') await reopen().catch(() => {});
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
        const r = await fetch('api.php?action=embed_open', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ t: token, collab: 1 }), credentials: 'omit' });
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

    user = d.user;
    client = d.client || '';
    app.textContent = '';
    ui.state = h('span.save-state', { role: 'status' });
    ui.pages = h('span.save-state.pages-count');
    ui.peers = h('span.peers', { 'aria-live': 'polite' });
    ui.title = h('div.titles.one', d.title || (mode === 'section' ? 'עריכת קטע' : 'מסמך'));
    ui.bar = h('div.embed-bar', ui.title, ui.peers, ui.pages, ui.state,
        h('button.btn.sm.ghost', { onclick: async () => { const r = await save(); post('close', { saved: r.ok && !dirty }); } }, 'סגירה'));
    ui.banner = h('div.banner', { hidden: true });
    ui.root = h('div');
    app.append(ui.bar, ui.banner, ui.root);
    mount(d);
    post('ready', { doc: docId, can, version: d.version });

    // יציאה: מה שלא נשלח — לשרת אם אפשר, ובכל מקרה טיוטה במכשיר; ויציאה מהנוכחות
    window.addEventListener('pagehide', () => { if (saver?.dirty) save(); saver?.destroy(); });
})();

/** העורך והעריכה המשותפת על המסמך d (embed_open / embed_load עם collab). */
function mount(d) {
    can = d.can;
    blocked = false;
    ui.root.textContent = '';
    ui.banner.hidden = true;
    ed = createWordEditor(ui.root, {
        json: d.json, page: d.page, editable: can === 'edit', user,
        collab: { version: d.collab.v, clientID },
        upload: file => { const fd = new FormData(); fd.append('file', file); fd.append('collab', '1'); return api('embed_upload', {}, fd); },
        versions: {
            list: async () => (await api('embed_load', { op: 'versions' })).versions,
            get: id => api('embed_load', { op: 'version', id }),
            // שחזור בשרת (ביקורת 04, R07) — קודם כל השינויים נשמרים; אחר כך עידן חדש, וכולם (גם אנחנו) טוענים מחדש
            restore: async id => {
                const s = await save();
                if (!s.ok || saver.dirty) { toast(s.message || 'לא ניתן לשמור לפני השחזור', true); return false; }
                await api('embed_version_restore', { id, collab: 1 });
                await reopen();
                return true;
            },
        },
        onUpdate: changed,
        save: () => save(),
        // הגדרות העמוד בתוך המסמך — נשמרות איתו; למארחת רק מודיעים (page)
        onPage: settings => post('page', { settings }),
    });
    ed.statusTarget(ui.pages);
    // טיוטה במכשיר: לפי לקוח, משתמש ומסמך (session מתחלף בכל פתיחה)
    const draftKey = `embed:${client}:${user?.id || user?.name || ''}:${docId}`;
    saver = createCollabSaver({
        editor: ed.editor, version: d.version, epoch: d.collab.epoch, clientID, draftKey,
        send: (op, body) => api('embed_collab', { op, ...body }),
        onState: onSaveState,
        onPeers: (others, lock) => {
            const names = [...new Set(others.filter(o => !o.me).map(o => o.name))];
            ui.peers.textContent = '';
            ui.peers.title = names.length ? 'גם במסמך עכשיו: ' + names.join(', ') : '';
            for (const n of names) ui.peers.append(h('span.peer', { title: n }, n.trim().slice(0, 1) || '?'));
            setPeers(ed.editor, others.filter(o => !o.me && o.role !== 'view'));   // צופה — בלי סמן
            if (lock && can === 'edit') readOnlyBecause(`פתוח לעריכה אצל ${lock.by} — קריאה בלבד`, lock);
            else if (!lock && blocked && requested === 'edit') showBanner('המסמך התפנה לעריכה.', 'מעבר לעריכה', () => reopen());
        },
        onReload: () => reopen(true),
    });
    ui.state.textContent = can === 'edit' ? STATE_TEXT.saved : 'קריאה בלבד';
    if (d.lock) readOnlyBecause(`פתוח לעריכה אצל ${d.lock.by} — קריאה בלבד`, d.lock);
    if (!mount.observed) { mount.observed = true; new ResizeObserver(reportHeight).observe(ui.root); }
    syncDirty();
    findDraft(draftKey, JSON.stringify(ed.getJSON())).then(dr => {
        if (!dr || can !== 'edit') return;
        showBanner('נמצאו במכשיר שינויים שלא נשמרו' + (dr.base_version !== d.version ? ' (המסמך השתנה מאז — שחזור ידרוס)' : '') + '.', 'שחזור', () => {
            ed.setContent(dr.json);
            saver.markDirty(saver.version);
            ui.banner.hidden = true;
            syncDirty();
        });
    });
}
