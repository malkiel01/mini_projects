/**
 * עריכה משותפת — הצד של הדפדפן (השרת: lib/collab.php). אותו ממשק כמו createSaver (saver.js), כך שהאפליקציה
 * משתמשת בו במקום השמירה הרגילה:
 *
 *   const s = createCollabSaver({ editor, send: (op, body) => api(...), epoch, clientID, version, draftKey, onState, onPeers, onReload });
 *
 * - כל שינוי מקומי: אחרי 700 מ"ש נשלחים הצעדים שלו (sendableSteps) עם המסמך המלא; השרת מקבל רק כשאנחנו מעודכנים —
 *   אחרת מושכים, ה-collab של ProseMirror מעביר את שלנו מעל מה שהגיע, ושולחים שוב.
 * - כל שנייה (5 כשהלשונית מוסתרת) — משיכה: הצעדים של האחרים, מי במסמך (onPeers) ונעילה ישנה אם יש (onLock).
 * - טיוטה במכשיר כל עוד יש צעדים שלא אושרו — כמו saver.js (draftKey; findDraft מציע שחזור בפתיחה הבאה).
 * - עידן אחר (שחזור גרסה, שמירה מבחוץ) או מאחור מדי — onReload: האפליקציה פותחת את המסמך מחדש.
 * בקשה אחת לשרת בכל רגע (busy) — כך אין משיכה ושליחה שחולפות זו על פני זו.
 */
import { Step, sendableSteps, receiveTransaction, getVersion } from '../../vendor/tiptap.js';
import { drafts } from './saver.js';

const DELAYS = [2000, 5000, 10000, 30000];

export function createCollabSaver(o) {
    const { editor } = o;
    let state = 'saved';
    let version = o.version;          // הגרסה של המסמך בשרת (docs.version) — לטיוטה ולתצוגה
    let busy = null;
    let timer = 0;
    let stopped = false;
    let gone = false;                 // נסגר — לא ממשיכים
    let retry = 0;
    let draftTimer = 0;
    let draftOk = true;
    let lastPull = 0;

    const set = (s, info = {}) => {
        if (state === s && !Object.keys(info).length) return;
        state = s;
        try { o.onState?.(s, info); } catch (e) { console.error(e); }
    };
    const pending = () => !!sendableSteps(editor.state);

    function writeDraft() {
        clearTimeout(draftTimer);
        draftTimer = setTimeout(async () => {
            if (!pending()) { drafts.del(o.draftKey); return; }
            draftOk = await drafts.put(o.draftKey, { json: editor.getJSON(), base_version: version, at: Date.now() });
        }, 500);
    }

    async function push() {
        const s = sendableSteps(editor.state);
        if (!s) return true;
        set('saving');
        const r = await o.send('push', { epoch: o.epoch, v: s.version, client: o.clientID, steps: s.steps.map(x => x.toJSON()), json: editor.getJSON() });
        if (r.reload) { gone = true; o.onReload?.(); return false; }
        if (!r.ok) return false;   // מישהו הקדים — מושכים ושולחים שוב
        version = r.version;
        // השרת קיבל בדיוק את הצעדים האלה, מיד אחרי הגרסה שלנו — מאשרים אותם כאן (בלי לחכות למשיכה)
        if (getVersion(editor.state) === s.version) {
            editor.view.dispatch(receiveTransaction(editor.state, s.steps, s.steps.map(() => o.clientID)));
        }
        return true;
    }

    async function pull() {
        const since = getVersion(editor.state);
        const sel = editor.state.selection;
        const r = await o.send('pull', { epoch: o.epoch, since, client: o.clientID, sel: [sel.from, sel.to] });
        lastPull = Date.now();
        if (r.reload) { gone = true; o.onReload?.(); return; }
        o.onPeers?.(r.others || [], r.lock || null);
        if (!r.steps?.length || getVersion(editor.state) !== since) return;
        const steps = r.steps.map(([j]) => Step.fromJSON(editor.schema, j));
        editor.view.dispatch(receiveTransaction(editor.state, steps, r.steps.map(([, c]) => c), { mapSelectionBackward: true }));
    }

    /** סבב: שולחים (עד 3 ניסיונות, עם משיכה ביניהם) ומושכים. מחזיר { ok, code?, message? }. */
    function tick() {
        if (busy) return busy;
        busy = (async () => {
            try {
                for (let i = 0; i < 3 && pending() && !stopped && !gone; i++) {
                    if (await push()) break;
                    if (!gone) await pull();
                }
                if (!gone) await pull();
                retry = 0;
                if (gone) return { ok: false, code: 'reload', message: 'המסמך נטען מחדש' };
                if (pending()) { if (!stopped) { set('dirty'); schedule(300); } }
                else { set('saved', { version }); clearTimeout(draftTimer); drafts.del(o.draftKey); }
                return { ok: true };
            } catch (e) {
                const code = e.code || 'error';
                if (code === 'offline' || code === 'server' || e.status >= 500) {
                    set('offline', { message: e.message });
                    schedule(DELAYS[Math.min(retry++, DELAYS.length - 1)]);
                } else if (code === 'locked') {
                    set('locked', { message: e.message });
                } else {
                    stopped = true;   // הרשאה, מבנה חדש יותר וכו' — לא מנסים שוב לבד
                    set('error', { code, message: e.message });
                }
                return { ok: false, code, message: e.message || 'השמירה נכשלה' };
            } finally {
                busy = null;
            }
        })();
        return busy;
    }

    function schedule(ms) {
        clearTimeout(timer);
        if (!gone) timer = setTimeout(tick, ms);
    }

    // משיכה קבועה: כל שנייה, וכל 5 שניות כשהלשונית מוסתרת
    const poll = setInterval(() => {
        if (gone || busy || Date.now() - lastPull < (document.hidden ? 5000 : 900)) return;
        tick();
    }, 250);
    tick();

    return {
        get state() { return state; },
        get version() { return version; },
        get dirty() { return pending(); },
        get draftKey() { return o.draftKey; },
        get local() { return !pending() ? 'saved' : draftOk ? 'saved' : 'failed'; },
        changed() {
            if (!pending()) return;
            if (state !== 'offline' && state !== 'saving') set('dirty');
            writeDraft();
            if (state !== 'offline' && !stopped) schedule(700);   // מקבצים הקלדה — כל שליחה כוללת את המסמך המלא
        },
        flush: () => (stopped ? Promise.resolve({ ok: false, code: state, message: 'אי אפשר לשמור כרגע' }) : tick().then(r => ({ ...r, dirty: pending() }))),
        async flushUntilClean() {
            let r = { ok: true };
            for (let i = 0; i < 5 && r.ok && pending(); i++) r = await tick();
            return { ...r, dirty: pending() };
        },
        flushDraft: async () => { if (!pending()) return true; return drafts.put(o.draftKey, { json: editor.getJSON(), base_version: version, at: Date.now() }); },
        async keepConflictCopy() {
            if (!pending()) return false;
            return drafts.put(o.draftKey + ':conflict', { json: editor.getJSON(), base_version: version, at: Date.now() });
        },
        reset() { set('saved'); },
        markDirty() { set('dirty'); writeDraft(); schedule(300); },
        stop() { stopped = true; clearTimeout(timer); },
        destroy() {
            gone = true;
            clearInterval(poll);
            clearTimeout(timer);
            if (draftTimer) { clearTimeout(draftTimer); if (pending()) drafts.put(o.draftKey, { json: editor.getJSON(), base_version: version, at: Date.now() }); }
            o.send('leave', { client: o.clientID }).catch(() => {});
        },
    };
}
