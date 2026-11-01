/**
 * שמירה אמינה — משותף לאפליקציה (app.js) ולהטמעה (embed.js).
 *
 *   const s = createSaver({ snapshot, send, version, draftKey, onState });
 *   s.changed()            — היה שינוי: טיוטה במכשיר (אחרי 250 מ"ש שקט, ולכל המאוחר אחרי שנייה), שמירה לשרת אחרי שנייה וחצי
 *   await s.flush()        — שמירה עכשיו. מחזיר { ok: true, dirty } או { ok: false, code, message } — תמיד, בלי לזרוק.
 *                            ok + dirty: true — מה שנשלח נשמר, אבל הוקלד עוד בזמן הבקשה (המסמך עוד לא נקי)
 *   await s.flushUntilClean() — שומר עד שהמסמך נקי (או כשל): לסגירה ולייצוא
 *   await s.flushDraft()   — כתיבת הטיוטה למכשיר עכשיו; true כשנכתבה (ביקורת 03, F01)
 *   s.local                — 'saved' (בשרת או בטיוטה שנכתבה) | 'pending' | 'failed' (אין אחסון במכשיר / מלא)
 *   s.keepConflictCopy()   — לפני טעינה מחדש אחרי התנגשות: התוכן המקומי נשמר בנפרד (draftKey + ':conflict') עד החלטה
 *   s.state                — 'saved' | 'dirty' | 'saving' | 'offline' | 'conflict' | 'locked' | 'error'
 *   onState(state, info)   — גם { localFailed: true } כשכתיבת טיוטה נכשלה
 *
 * כללים:
 * - תוכן, הגדרות עמוד וסגנונות נשמרים יחד, בגרסה אחת: snapshot() מחזיר את ה-JSON של המסמך, וההגדרות בתוכו (attrs.page).
 * - כל שינוי נכתב קודם לטיוטה ב-IndexedDB במכשיר. הטיוטה נמחקת רק כשהשרת אישר את אותו תוכן בדיוק.
 *   כך סגירת לשונית, ניתוק או קריסה לא מאבדים כלום — בפתיחה הבאה מוצע שחזור (findDraft).
 * - ניתוק: ניסיון חוזר אוטומטי (2, 5, 10, 30 שניות…), במצב 'offline'.
 * - התנגשות (409) או נעילה (423): השמירה האוטומטית נעצרת — לא דורסים. ההחלטה למשתמש.
 */

import { kv } from './ui-core/store.js';

// הטיוטות — ב-kv של ui-core (store.js, integration.md שורה 16): אותו מסד ואותו מאגר כמו קודם ('word-processor' / 'drafts'),
// כך שטיוטות שכבר במכשיר נמצאות. כאן רק החוזה של השמירה האמינה (F01): כתיבה / מחיקה — true רק כשהעסקה הושלמה,
// אחרת false (אין אחסון, מלא, חסום); קריאה — הערך או null. לא זורק.
const store = 'indexedDB' in globalThis ? kv('word-processor', 'drafts') : null;
const ok = p => p.then(() => true, () => false);

export const drafts = {
    put: (key, value) => store ? ok(store.set(key, value)) : Promise.resolve(false),
    get: key => store ? store.get(key).then(v => v ?? null, () => null) : Promise.resolve(null),
    del: key => store ? ok(store.del(key)) : Promise.resolve(false),
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
    let draftStr = null;     // התוכן שנכתב בהצלחה לטיוטה האחרונה
    let draftFailed = false;
    let draftSince = 0;      // מתי התחילה ההמתנה לכתיבת הטיוטה (maxWait)
    const DRAFT_WAIT = 250, DRAFT_MAX = 1000;

    const set = (s, info = {}) => {
        state = s;
        try { o.onState?.(s, info); } catch (e) { console.error(e); }
    };
    const current = () => {
        const snap = o.snapshot();
        return { snap, str: JSON.stringify(snap) };
    };
    /** כתיבת הטיוטה עכשיו; true כשנכתבה (או שאין מה לכתוב — הכול בשרת). כשל — localFailed למעלה. */
    async function flushDraft() {
        clearTimeout(draftTimer);
        draftTimer = 0;
        draftSince = 0;
        const { snap, str } = current();
        if (str === lastSaved) { drafts.del(o.draftKey); draftStr = str; return true; }
        const ok = await drafts.put(o.draftKey, { json: snap, base_version: version, at: Date.now() });
        if (ok) { draftStr = str; if (draftFailed) { draftFailed = false; set(state, { localFailed: false }); } }
        else if (!draftFailed) { draftFailed = true; set(state, { localFailed: true }); }
        return ok;
    }
    // שקט של 250 מ"ש — או לכל המאוחר שנייה מהשינוי הראשון שעוד לא נכתב (הקלדה רצופה לא דוחה את הטיוטה לעד)
    const writeDraft = () => {
        clearTimeout(draftTimer);
        const now = Date.now();
        if (!draftSince) draftSince = now;
        draftTimer = setTimeout(flushDraft, Math.max(0, Math.min(DRAFT_WAIT, draftSince + DRAFT_MAX - now)));
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
            const dirty = current().str !== lastSaved;
            if (!dirty) { drafts.del(o.draftKey); set('saved', r); }
            else { set('dirty'); schedule(); }
            return { ok: true, version: r.version, dirty };
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

    /** שמירה עד שהמסמך נקי — מה שהוקלד בזמן בקשה נשלח בבקשה הבאה (עד 5 סבבים). */
    async function flushUntilClean() {
        let r = { ok: true, dirty: true };
        for (let i = 0; i < 5 && r.ok && current().str !== lastSaved; i++) r = await flush();
        return r.ok ? { ...r, dirty: current().str !== lastSaved } : r;
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(() => { flush(); }, 1500);
    }

    return {
        get state() { return state; },
        get version() { return version; },
        get dirty() { return current().str !== lastSaved; },
        get draftKey() { return o.draftKey; },
        get local() { const str = current().str; return str === lastSaved || str === draftStr ? 'saved' : draftFailed ? 'failed' : 'pending'; },
        changed() {
            if (stopped) { writeDraft(); return; }
            if (current().str === lastSaved) return;
            if (state !== 'offline' && state !== 'saving') set('dirty');
            writeDraft();
            if (state !== 'offline') schedule();
        },
        flush,
        flushUntilClean,
        flushDraft,
        /** לפני טעינה מחדש אחרי התנגשות: העותק המקומי — בנפרד, עד שהמשתמש מחליט (הורדה / מחיקה). */
        async keepConflictCopy() {
            const { snap, str } = current();
            if (str === lastSaved) return false;
            return drafts.put(o.draftKey + ':conflict', { json: snap, base_version: version, at: Date.now() });
        },
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
        // יציאה: טיוטה שממתינה נכתבת (ולא מתבטלת — ביקורת 03, F01); השמירה לשרת — של מי שקרא (ensureSaved)
        destroy() { clearTimeout(timer); if (draftTimer) flushDraft(); },
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
