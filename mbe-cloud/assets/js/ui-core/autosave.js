/**
 * ui-core/autosave.js — שמירה אוטומטית וטיוטה במכשיר. אחד לכל האפליקציות. המקור: saver.js של עורך התמלילים (ביקורת 03)
 * — טיוטה ב-IndexedDB לפני כל שמירה, ניסיון חוזר בניתוק, עצירה בהתנגשות. ומהרסטר (draft.js): טיוטה מיד כשהדף עובר לרקע,
 * וטיוטה "יקרה" (PNG לכל שכבה) שנבנית אסינכרונית רק כשצריך.
 *
 *   const s = autosave({
 *       snapshot: () => doc,                  // המצב הנוכחי (סינכרוני, זול). JSON.stringify שלו — "האם השתנה"
 *       send: async (snap, version) => ({ version }),   // רשות — שמירה לשרת; זורק { code: 'conflict' | 'locked' | 'offline' | … }
 *       version: doc.version,                 // הגרסה בשרת (נשלחת עם השמירה הבאה)
 *       draftKey: 'doc:' + id,                // מפתח הטיוטה במכשיר
 *       draftData: async () => …,             // רשות — מה נשמר בטיוטה (ברירת מחדל: snapshot()); לטיוטה כבדה (פיקסלים)
 *       store: kv('my-app-drafts'),           // רשות — { get, set, del }; ברירת מחדל kv('uic-drafts')
 *       onState: (state, info) => …,          // 'saved' | 'dirty' | 'saving' | 'offline' | 'conflict' | 'locked' | 'error'
 *   });
 *   s.changed()            — היה שינוי: טיוטה אחרי 250 מ"ש שקט (לכל המאוחר שנייה), שמירה לשרת אחרי 1.5 שנייה
 *   await s.flush()        — שמירה עכשיו → { ok, dirty } | { ok: false, code, message } — לא זורק
 *   await s.flushUntilClean()   — עד שנקי (סגירה, ייצוא)
 *   await s.flushDraft()   — הטיוטה עכשיו → true / false
 *   s.state  s.dirty  s.local ('saved' | 'pending' | 'failed')  s.version
 *   s.reset(version)  s.markDirty(serverVersion)  s.keepConflictCopy()  s.stop()  s.destroy()
 *
 *   const d = await findDraft(store, key, serverStr)   // בפתיחה: טיוטה שונה ממה שבשרת → להציע שחזור (confirm)
 *   saveOnExit(s)                                       // טיוטה מיד כשהדף מוסתר / נסגר (טלפון ברקע)
 *   STATE_TEXT[state]                                   // '✓ נשמר', 'שומר…', '⚠ מנותק'…
 *
 * בלי send — רק טיוטה במכשיר (אפליקציה בלי שרת, או לפני שהמסמך נשמר פעם ראשונה): changed() כותב טיוטה, והמצב 'dirty'
 * עד markSaved(). התנגשות (409 → 'conflict') ונעילה ('locked'): השמירה נעצרת — לא דורסים; ההחלטה למשתמש.
 */
import { kv } from './store.js';

const DELAYS = [2000, 5000, 10000, 30000, 60000];

/** האחסון כברירת מחדל: IndexedDB, עם כישלון שקט (גלישה פרטית / מלא) → false / null */
function safeStore(store) {
    return {
        get: k => Promise.resolve().then(() => store.get(k)).catch(() => null),
        set: (k, v) => Promise.resolve().then(() => store.set(k, v)).then(() => true, () => false),
        del: k => Promise.resolve().then(() => store.del(k)).then(() => true, () => false),
    };
}
let defaultStore = null;
const storeOf = s => safeStore(s || (defaultStore ||= kv('uic-drafts')));

/** טיוטה שלא נשמרה (או null). מחזיר אותה רק כשהיא שונה ממה שבשרת — אחרת מוחק. draft: { data, base_version, at } */
export async function findDraft(store, key, serverStr = null) {
    const st = storeOf(store);
    const d = await st.get(key);
    if (!d || d.data === undefined) return null;
    if (serverStr !== null && JSON.stringify(d.data) === serverStr) { st.del(key); return null; }
    return d;
}

export function autosave(o) {
    const st = storeOf(o.store);
    const serialize = o.serialize || JSON.stringify;
    const DRAFT_WAIT = o.draftWait ?? 250, DRAFT_MAX = o.draftMax ?? 1000, SAVE_DELAY = o.delay ?? 1500;
    let version = o.version ?? 0;
    let lastSaved = serialize(o.snapshot());
    let state = 'saved', timer = 0, draftTimer = 0, inflight = null, retry = 0, stopped = false;
    let draftStr = null, draftFailed = false, draftSince = 0;

    const set = (s, info = {}) => { state = s; try { o.onState?.(s, info); } catch (e) { console.error(e); } };
    const now = () => serialize(o.snapshot());

    /** הטיוטה עכשיו; true כשנכתבה (או שאין מה לכתוב — הכול שמור). */
    async function flushDraft() {
        clearTimeout(draftTimer);
        draftTimer = 0;
        draftSince = 0;
        const str = now();
        if (str === lastSaved) { st.del(o.draftKey); draftStr = str; return true; }
        const data = o.draftData ? await o.draftData() : o.snapshot();
        const ok = await st.set(o.draftKey, { data, base_version: version, at: Date.now() });
        if (ok) { draftStr = str; if (draftFailed) { draftFailed = false; set(state, { localFailed: false }); } }
        else if (!draftFailed) { draftFailed = true; set(state, { localFailed: true }); }
        return ok;
    }
    // שקט של 250 מ"ש — או לכל המאוחר שנייה מהשינוי הראשון שעוד לא נכתב (הקלדה רצופה לא דוחה את הטיוטה לעד)
    function writeDraft() {
        clearTimeout(draftTimer);
        const t = Date.now();
        if (!draftSince) draftSince = t;
        draftTimer = setTimeout(flushDraft, Math.max(0, Math.min(DRAFT_WAIT, draftSince + DRAFT_MAX - t)));
    }

    async function doSave() {
        const snap = o.snapshot(), str = serialize(snap);
        if (str === lastSaved) { set('saved'); st.del(o.draftKey); return { ok: true, dirty: false }; }
        set('saving');
        try {
            const r = (await o.send(snap, version)) || {};
            if (r.version !== undefined) version = r.version;
            lastSaved = str;
            retry = 0;
            const dirty = now() !== lastSaved;      // הוקלד עוד בזמן השמירה — עדיין יש מה לשמור
            if (!dirty) { st.del(o.draftKey); set('saved', r); }
            else { set('dirty'); schedule(); }
            return { ok: true, version, dirty };
        } catch (e) {
            const code = e?.code || 'error', message = e?.message || 'השמירה נכשלה';
            if (code === 'conflict') { stopped = true; set('conflict', { message }); }
            else if (code === 'locked' || code === 'readonly') { stopped = true; set('locked', { message }); }
            else if (code === 'expired' || code === 'schema_newer') { stopped = true; set('error', { code, message }); }
            else if (code === 'offline' || code === 'server' || e?.status >= 500 || e instanceof TypeError) {
                set('offline', { message });
                clearTimeout(timer);
                timer = setTimeout(() => { flush(); }, DELAYS[Math.min(retry++, DELAYS.length - 1)]);
            } else set('error', { code, message });
            return { ok: false, code, message };
        }
    }

    function flush() {
        clearTimeout(timer);
        if (!o.send) return flushDraft().then(ok => ({ ok, dirty: now() !== lastSaved, local: true }));
        if (stopped) return Promise.resolve({ ok: false, code: state, message: state === 'conflict' ? 'המסמך השתנה במקום אחר' : 'אי אפשר לשמור כרגע' });
        if (inflight) return inflight.then(() => flush());
        inflight = doSave().finally(() => { inflight = null; });
        return inflight;
    }

    async function flushUntilClean() {
        let r = { ok: true, dirty: true };
        for (let i = 0; i < 5 && r.ok && now() !== lastSaved; i++) r = await flush();
        return r.ok ? { ...r, dirty: now() !== lastSaved } : r;
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(() => { flush(); }, SAVE_DELAY); }

    return {
        get state() { return state; },
        get version() { return version; },
        get dirty() { return now() !== lastSaved; },
        get draftKey() { return o.draftKey; },
        get local() { const s = now(); return s === lastSaved || s === draftStr ? 'saved' : draftFailed ? 'failed' : 'pending'; },
        changed() {
            if (now() === lastSaved) return;
            writeDraft();
            if (stopped || !o.send) { if (!stopped && state !== 'dirty') set('dirty'); return; }
            if (state !== 'offline' && state !== 'saving') set('dirty');
            if (state !== 'offline') schedule();
        },
        flush, flushUntilClean, flushDraft,
        /** בלי send: המסמך נשמר בדרך אחרת (ייצוא / שמירה ידנית) — הטיוטה נמחקת. */
        markSaved() { lastSaved = now(); st.del(o.draftKey); set('saved'); },
        /** לפני טעינה מחדש אחרי התנגשות: העותק המקומי — בנפרד (draftKey + ':conflict') עד שהמשתמש מחליט. */
        async keepConflictCopy() {
            if (now() === lastSaved) return false;
            return st.set(o.draftKey + ':conflict', { data: o.draftData ? await o.draftData() : o.snapshot(), base_version: version, at: Date.now() });
        },
        /** אחרי טעינה מחדש מהשרת. */
        reset(newVersion = version) { version = newVersion; lastSaved = now(); stopped = false; retry = 0; clearTimeout(timer); st.del(o.draftKey); set('saved'); },
        /** שחזור טיוטה: התוכן הנוכחי לא נשמר — השמירה הבאה שולחת אותו מול הגרסה שבשרת. */
        markDirty(serverVersion = version) { version = serverVersion; lastSaved = ''; stopped = false; set('dirty'); writeDraft(); if (o.send) schedule(); },
        stop() { stopped = true; clearTimeout(timer); },
        /** יציאה: טיוטה שממתינה נכתבת (לא מתבטלת); השמירה לשרת — של מי שקרא. */
        destroy() { clearTimeout(timer); if (draftTimer) flushDraft(); },
    };
}

/** טיוטה מיד כשהדף מוסתר / נסגר (טלפון ברקע — הדפדפן עלול לסגור את הלשונית בלי אזהרה). מחזיר פונקציה לניתוק. */
export function saveOnExit(s, { warn = false } = {}) {
    const hide = () => { if (document.visibilityState === 'hidden' && s.dirty) s.flushDraft(); };
    const leave = e => { if (s.dirty) { s.flushDraft(); if (warn) { e.preventDefault(); e.returnValue = ''; } } };
    document.addEventListener('visibilitychange', hide);
    addEventListener('pagehide', hide);
    addEventListener('beforeunload', leave);
    return () => { document.removeEventListener('visibilitychange', hide); removeEventListener('pagehide', hide); removeEventListener('beforeunload', leave); };
}

/** טקסט המצב לכותרת. */
export const STATE_TEXT = {
    saved: '✓ נשמר', dirty: 'לא נשמר', saving: 'שומר…', offline: '⚠ מנותק', conflict: '⚠ התנגשות',
    locked: 'קריאה בלבד', error: '⚠ השמירה נכשלה',
};
