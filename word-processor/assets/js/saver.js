/**
 * שמירה אמינה — משותף לאפליקציה (app.js) ולהטמעה (embed.js).
 *
 *   const s = createSaver({ snapshot, send, version, draftKey, onState });
 *   s.changed()            — היה שינוי: טיוטה במכשיר מיד, שמירה לשרת אחרי שנייה וחצי
 *   await s.flush()        — שמירה עכשיו. מחזיר { ok: true } או { ok: false, code, message } — תמיד, בלי לזרוק
 *   s.state                — 'saved' | 'dirty' | 'saving' | 'offline' | 'conflict' | 'locked' | 'error'
 *
 * כללים:
 * - תוכן, הגדרות עמוד וסגנונות נשמרים יחד, בגרסה אחת: snapshot() מחזיר את ה-JSON של המסמך, וההגדרות בתוכו (attrs.page).
 * - כל שינוי נכתב קודם לטיוטה ב-IndexedDB במכשיר. הטיוטה נמחקת רק כשהשרת אישר את אותו תוכן בדיוק.
 *   כך סגירת לשונית, ניתוק או קריסה לא מאבדים כלום — בפתיחה הבאה מוצע שחזור (findDraft).
 * - ניתוק: ניסיון חוזר אוטומטי (2, 5, 10, 30 שניות…), במצב 'offline'.
 * - התנגשות (409) או נעילה (423): השמירה האוטומטית נעצרת — לא דורסים. ההחלטה למשתמש.
 */

const DB_NAME = 'word-processor';
const STORE = 'drafts';

function idb() {
    if (!('indexedDB' in window)) return Promise.resolve(null);
    return new Promise(resolve => {
        try {
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => resolve(null);
            req.onblocked = () => resolve(null);
        } catch { resolve(null); }
    });
}

async function draftOp(mode, fn) {
    const db = await idb();
    if (!db) return null;
    return new Promise(resolve => {
        try {
            const tx = db.transaction(STORE, mode);
            const r = fn(tx.objectStore(STORE));
            tx.oncomplete = () => resolve(r?.result ?? null);
            tx.onerror = () => resolve(null);
        } catch { resolve(null); }
    });
}

export const drafts = {
    put: (key, value) => draftOp('readwrite', s => s.put(value, key)),
    get: key => draftOp('readonly', s => s.get(key)),
    del: key => draftOp('readwrite', s => s.delete(key)),
};

/**
 * טיוטה שלא נשמרה למסמך הזה (או null). מחזיר אותה רק אם היא שונה ממה שבשרת.
 * draft: { json, base_version, at } — at במילישניות.
 */
export async function findDraft(key, serverJsonStr) {
    const d = await drafts.get(key);
    if (!d || !d.json) return null;
    if (JSON.stringify(d.json) === serverJsonStr) { drafts.del(key); return null; }
    return d;
}

const DELAYS = [2000, 5000, 10000, 30000, 60000];

export function createSaver(o) {
    let version = o.version;
    let lastSaved = JSON.stringify(o.snapshot());
    let state = 'saved';
    let timer = 0;
    let draftTimer = 0;
    let inflight = null;
    let retry = 0;
    let stopped = false;

    const set = (s, info = {}) => {
        state = s;
        try { o.onState?.(s, info); } catch (e) { console.error(e); }
    };
    const current = () => {
        const snap = o.snapshot();
        return { snap, str: JSON.stringify(snap) };
    };
    const writeDraft = () => {
        clearTimeout(draftTimer);
        draftTimer = setTimeout(() => {
            const { snap, str } = current();
            if (str === lastSaved) drafts.del(o.draftKey);
            else drafts.put(o.draftKey, { json: snap, base_version: version, at: Date.now() });
        }, 250);
    };

    async function doSave() {
        const { snap, str } = current();
        if (str === lastSaved) { set('saved'); drafts.del(o.draftKey); return { ok: true }; }
        set('saving');
        try {
            const r = await o.send(snap, version);
            version = r.version;
            lastSaved = str;
            retry = 0;
            // אם הוקלד עוד בזמן השמירה — עדיין יש מה לשמור
            if (current().str === lastSaved) { drafts.del(o.draftKey); set('saved', r); }
            else { set('dirty'); schedule(); }
            return { ok: true, version: r.version };
        } catch (e) {
            const code = e.code || 'error';
            if (code === 'conflict') { stopped = true; set('conflict', { message: e.message }); }
            else if (code === 'locked' || code === 'readonly') { stopped = true; set('locked', { message: e.message }); }
            else if (code === 'schema_newer') { stopped = true; set('error', { code, message: e.message }); }
            else if (code === 'offline' || code === 'server' || e.status >= 500) {
                set('offline', { message: e.message });
                clearTimeout(timer);
                timer = setTimeout(() => { flush(); }, DELAYS[Math.min(retry++, DELAYS.length - 1)]);
            } else if (code === 'expired') { stopped = true; set('error', { code, message: e.message }); }
            else set('error', { code, message: e.message });
            return { ok: false, code, message: e.message || 'השמירה נכשלה' };
        }
    }

    function flush() {
        clearTimeout(timer);
        if (stopped) return Promise.resolve({ ok: false, code: state, message: state === 'conflict' ? 'המסמך השתנה במקום אחר' : 'אי אפשר לשמור כרגע' });
        if (inflight) return inflight.then(() => flush());
        inflight = doSave().finally(() => { inflight = null; });
        return inflight;
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(() => { flush(); }, 1500);
    }

    return {
        get state() { return state; },
        get version() { return version; },
        get dirty() { return current().str !== lastSaved; },
        changed() {
            if (stopped) { writeDraft(); return; }
            if (current().str === lastSaved) return;
            if (state !== 'offline' && state !== 'saving') set('dirty');
            writeDraft();
            if (state !== 'offline') schedule();
        },
        flush,
        /** אחרי טעינה מחדש מהשרת (למשל אחרי התנגשות או שחרור נעילה). */
        reset(newVersion) {
            version = newVersion;
            lastSaved = current().str;
            stopped = false;
            retry = 0;
            clearTimeout(timer);
            drafts.del(o.draftKey);
            set('saved');
        },
        /** מניחים שהתוכן הנוכחי לא נשמר (שחזור טיוטה): השמירה הבאה תשלח אותו מול הגרסה שבשרת. */
        markDirty(serverVersion) {
            version = serverVersion;
            lastSaved = '';
            stopped = false;
            set('dirty');
            writeDraft();
            schedule();
        },
        stop() { stopped = true; clearTimeout(timer); },
        destroy() { clearTimeout(timer); clearTimeout(draftTimer); },
    };
}

/** טקסט המצב לכותרת. */
export const STATE_TEXT = {
    saved: '✓ נשמר', dirty: 'לא נשמר', saving: 'שומר…', offline: '⚠ מנותק', conflict: '⚠ התנגשות',
    locked: 'קריאה בלבד', error: '⚠ השמירה נכשלה',
};

/** הורדת עותק מהמכשיר (כשהשרת לא זמין): JSON של המסמך, בצורה של "גיבוי (JSON)". */
export function downloadLocalCopy(json, title) {
    const blob = new Blob([JSON.stringify({ title, json, page: json?.attrs?.page }, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = (title || 'מסמך') + ' (עותק מקומי).json';
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}
