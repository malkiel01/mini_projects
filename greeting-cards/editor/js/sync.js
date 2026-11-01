// סנכרון עם השרת. המכשיר הוא העותק הראשי לעבודה (מהיר, עובד בלי רשת); השרת הוא הגיבוי והגשר בין
// מכשירים. כל שמירה במכשיר מסמנת את העיצוב "לא מסונכרן", והוא נדחף ברקע: קודם התמונות (פעם אחת לכל
// תמונה — השרת מזהה כפילויות), ואז ה-JSON.
//
// התנגשות: עיצוב שהשתנה במכשיר אחר מאז שהתחלנו ממנו (409) — לא דורסים. השינויים שלנו נשמרים כעותק,
// והמקור מתעדכן מהשרת. אף פעם לא הולכת עבודה לאיבוד.

import { designs, assets } from './db.js';
import { uid } from './doc.js';

const API = 'api.php';
let user = null;
const listeners = new Set();

export const currentUser = () => user;
export const onSync = fn => listeners.add(fn);
const emit = (type, data) => listeners.forEach(fn => fn(type, data));

async function call(action, { method = 'GET', json, form, query = '' } = {}) {
    const opt = { method, credentials: 'same-origin', headers: {} };
    if (method !== 'GET') opt.headers['X-Studio'] = '1';
    if (json) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(json); }
    if (form) opt.body = form;
    const res = await fetch(`${API}?action=${action}${query}`, opt);
    let data = {};
    try { data = await res.json(); } catch { /* תשובה שאינה JSON — למשל דף שגיאה של השרת */ }
    if (!res.ok) {
        const e = new Error(data.error || `שגיאת שרת (${res.status})`);
        e.status = res.status;
        e.data = data;
        throw e;
    }
    return data;
}

/** צד השרת של תפריט המשתמש (ui-core account): account_prefs / account_prefs_set / account_export / account_delete. */
export const accountCall = (action, body) => call(action, { method: 'POST', json: body || {} });

/** יומן אבחון לשרת. מחזיר {id} — הקוד שהמשתמש מעביר הלאה. */
export function sendLog(dump) {
    return call('log_put', { method: 'POST', json: dump });
}

/** היומנים שנשלחו — למפתח בלבד. */
export function listLogs() {
    return call('log_list');
}

// ─── משתמש ────────────────────────────────────────────────────────────────

const USER_KEY = 'gc-studio-user';

function remember(u) {
    user = u;
    try { u ? localStorage.setItem(USER_KEY, JSON.stringify(u)) : localStorage.removeItem(USER_KEY); } catch { /* מצב פרטי */ }
}

/**
 * מי מחובר. בלי רשת — המשתמש האחרון שהיה מחובר במכשיר, כדי שאפשר יהיה להמשיך לעבוד.
 * @returns {{user: object|null, offline: boolean}}
 */
export async function whoami() {
    try {
        const d = await call('me');
        remember(d.user);
        return { user: d.user, offline: false };
    } catch (e) {
        if (e.status) throw e;
        let cached = null;
        try { cached = JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch { /* אין */ }
        user = cached;
        return { user: cached, offline: true };
    }
}

export const requestCode = email => call('login_code', { method: 'POST', json: { email } });

export async function verifyCode(email, code) {
    const d = await call('login_verify', { method: 'POST', json: { email, code } });
    remember(d.user);
    return d.user;
}

export async function logout() {
    await call('logout', { method: 'POST' }).catch(() => {});
    remember(null);
}

export const fileUrl = id => `${API}?action=file&id=${encodeURIComponent(id)}`;

// ─── רשימה ────────────────────────────────────────────────────────────────

/**
 * העיצובים: מה שבמכשיר, ועוד מה שיש רק בשרת (ממכשיר אחר). עיצוב שנמחק במכשיר אחר — נמחק גם כאן,
 * אלא אם יש בו שינויים שעוד לא נשלחו.
 * @returns {{list: object[], online: boolean}}
 */
export async function listDesigns() {
    const local = await designs.list(user?.id);
    if (!user) return { list: local, online: false };
    let remote;
    try { remote = (await call('designs')).designs; } catch { return { list: local, online: false }; }

    const byId = new Map(local.map(d => [d.id, d]));
    for (const r of remote) {
        const l = byId.get(r.id);
        if (r.deleted) {
            if (l && l.synced) { await designs.removeLocal(r.id); byId.delete(r.id); }
            continue;
        }
        if (!l) byId.set(r.id, { id: r.id, title: r.title, w: r.w, h: r.h, updatedAt: r.updatedAt, remoteThumb: r.thumb, remoteOnly: true });
        else if (r.version > (l.serverVersion || 0) && l.synced) {
            // השתנה במכשיר אחר: הרשימה מציגה את מה שבשרת, והפתיחה תוריד אותו.
            Object.assign(l, { stale: true, title: r.title, updatedAt: r.updatedAt, w: r.w, h: r.h });
            if (r.thumb) { l.thumb = null; l.remoteThumb = r.thumb; }
        }
    }
    pushAll();
    return { list: [...byId.values()].sort((a, b) => b.updatedAt - a.updatedAt), online: true };
}

// ─── משיכה ────────────────────────────────────────────────────────────────

/** מוריד עיצוב מהשרת ושומר במכשיר, כולל התמונות שעוד אין במכשיר. */
export async function pull(id) {
    const { design: d } = await call('design', { query: '&id=' + encodeURIComponent(id) });
    const prev = await designs.get(id);
    const files = doc => [...Object.values(doc?.assets || {}), ...Object.values(doc?.fonts || {})];   // תמונות וגופנים שהועלו
    const known = new Map(files(prev?.doc).filter(a => a.remote && a.src).map(a => [a.remote, a.src]));
    for (const a of files(d.doc)) {
        if (!a.remote) continue;
        if (known.has(a.remote)) { a.src = known.get(a.remote); continue; }
        const res = await fetch(fileUrl(a.remote), { credentials: 'same-origin' });
        if (res.ok) a.src = await assets.put(await res.blob());
    }
    let thumb = prev?.thumb;
    if (d.thumb) {
        const res = await fetch(fileUrl(d.thumb), { credentials: 'same-origin' }).catch(() => null);
        if (res?.ok) thumb = await res.blob();
    }
    await designs.put({ id, title: d.title, doc: d.doc, updatedAt: d.updatedAt, thumb, owner: user.id, serverVersion: d.version, synced: true });
}

// ─── גרסאות קודמות ────────────────────────────────────────────────────────

/** הגרסאות שבשרת (עד 30, אחת לכל היותר ל-10 דקות): [{id, at, bytes}]. */
export async function listVersions(id) {
    return (await call('design_versions', { query: '&id=' + encodeURIComponent(id) })).versions;
}

/** גרסה קודמת — עם התמונות והגופנים שלה במכשיר (מה שכבר יש — לא יורד שוב). */
export async function fetchVersion(id, vid) {
    const { doc } = await call('design_version', { query: '&id=' + encodeURIComponent(id) + '&v=' + Number(vid) });
    const cur = await designs.get(id);
    const files = d => [...Object.values(d?.assets || {}), ...Object.values(d?.fonts || {})];
    const known = new Map(files(cur?.doc).filter(a => a.remote && a.src).map(a => [a.remote, a.src]));
    for (const a of files(doc)) {
        if (!a.remote) continue;
        if (known.has(a.remote)) { a.src = known.get(a.remote); continue; }
        const res = await fetch(fileUrl(a.remote), { credentials: 'same-origin' });
        if (res.ok) a.src = await assets.put(await res.blob());
    }
    return doc;
}

// ─── דחיפה ────────────────────────────────────────────────────────────────

const inFlight = new Map();

/** דוחף עיצוב אחד. ‏withThumb — גם התמונה הממוזערת (ביציאה מהעורך; לא בכל שמירה). */
export function push(id, { withThumb = false } = {}) {
    if (!user) return Promise.resolve();
    // שתי דחיפות של אותו עיצוב במקביל היו שולחות אותה גרסת בסיס — השנייה מחכה לראשונה.
    const prev = inFlight.get(id) || Promise.resolve();
    const p = prev.catch(() => {}).then(() => pushOnce(id, withThumb));
    inFlight.set(id, p);
    p.finally(() => { if (inFlight.get(id) === p) inFlight.delete(id); });
    return p;
}

async function upload(blob) {
    const fd = new FormData();
    fd.append('file', blob, 'image');
    return (await call('upload', { method: 'POST', form: fd })).file.id;
}

async function pushOnce(id, withThumb) {
    const rec = await designs.get(id);
    if (!rec || (rec.synced && !withThumb)) return;
    if (rec.owner == null) rec.owner = user.id;   // עיצוב שנוצר לפני שהיה שרת — עובר לחשבון
    emit('pushing', id);

    const remotes = {}, fontRemotes = {};
    for (const [key, a] of Object.entries(rec.doc.assets)) {
        if (a.remote || !a.src) continue;
        const blob = await assets.get(a.src);
        if (!blob) continue;
        a.remote = remotes[key] = await upload(blob);
    }
    for (const [key, a] of Object.entries(rec.doc.fonts || {})) {
        if (a.remote || !a.src) continue;
        const blob = await assets.get(a.src);
        if (!blob) continue;
        a.remote = fontRemotes[key] = await upload(blob);
    }
    const thumb = withThumb && rec.thumb ? await upload(rec.thumb).catch(() => null) : null;

    let r;
    try {
        r = await call('design_put', { method: 'POST', json: { id, title: rec.title, doc: rec.doc, baseVersion: rec.serverVersion || 0, updatedAt: rec.updatedAt, thumb } });
    } catch (e) {
        if (e.status !== 409) throw e;
        // התנגשות: השינויים שלנו — עותק חדש; המקור — הגרסה מהשרת.
        const copyId = uid('d');
        await designs.put({ ...rec, id: copyId, title: rec.title + ' (מהמכשיר הזה)', serverVersion: 0, synced: false, updatedAt: Date.now() });
        await pull(id);
        emit('conflict', { id, copyId });
        return push(copyId);
    }

    // בזמן ששלחנו, ייתכן שהמשתמש המשיך לערוך — מעדכנים את הרשומה העדכנית, לא את מה שקראנו.
    const latest = await designs.get(id);
    if (!latest) return;
    for (const [key, remote] of Object.entries(remotes)) if (latest.doc.assets[key]) latest.doc.assets[key].remote = remote;
    for (const [key, remote] of Object.entries(fontRemotes)) if (latest.doc.fonts?.[key]) latest.doc.fonts[key].remote = remote;
    latest.serverVersion = r.version;
    latest.owner = user.id;
    latest.synced = latest.updatedAt === rec.updatedAt;
    await designs.put(latest);
    emit('pushed', { id, remotes, synced: latest.synced });
}

let pushingAll = false;

/** כל מה שלא סונכרן — ברקע, אחד אחרי השני. כישלון (בלי רשת) לא עוצר את העבודה במכשיר. */
export async function pushAll() {
    if (pushingAll || !user) return;
    pushingAll = true;
    try {
        for (const d of await designs.unsynced(user.id)) {
            try { await push(d.id); } catch { break; }
        }
    } finally { pushingAll = false; }
}

// ─── מ-ChatGPT ────────────────────────────────────────────────────────────

/** ייבואים שה-GPT שלח לחשבון (OAuth) ועוד לא נפתחו. */
export async function pendingImports() {
    if (!user) return [];
    try { return (await call('chat_pending')).imports; } catch { return []; }
}

/**
 * מימוש קוד ייבוא: התמונות כבר בחשבון (השרת העביר אותן), וכאן הן יורדות למכשיר.
 * @returns {{design: object|null, files: Object<string,{id:string,width:number,height:number}>, blobs: Map<string,Blob>}}
 */
export async function claimImport(code) {
    const r = await call('chat_claim', { method: 'POST', json: { code } });
    const blobs = new Map();
    for (const [name, f] of Object.entries(r.files || {})) {
        const res = await fetch(fileUrl(f.id), { credentials: 'same-origin' });
        if (res.ok) blobs.set(name, await res.blob());
    }
    return { design: r.design, files: r.files || {}, blobs };
}

export async function gptSetup(regenerate = false) {
    return call('gpt_setup', regenerate ? { method: 'POST', json: {} } : {});
}

export async function removeRemote(id) {
    if (user) await call('design_delete', { method: 'POST', json: { id } });
}

window.addEventListener('online', () => pushAll());
