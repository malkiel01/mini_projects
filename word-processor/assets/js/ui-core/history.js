/**
 * ui-core/history.js — ביטול / ביצוע. אחד לכל האפליקציות. המקור: History של הרסטר (engine/history.js) — בלי סוגי
 * הרשומות שלו (פיקסלים, שכבות, מסכות): אלה נשארים אצלו, מעל המחלקה הזו.
 *
 * שתי צורות:
 *
 * 1. רשומות (רסטר — פעולות הפוכות, זיכרון גדול):
 *      const h = new History({ maxSteps: 100, limitBytes: 512e6, onChange: h => updateButtons(h) });
 *      h.push({ label: 'מכחול', bytes, undo: () => …, redo: () => …, discard: applied => … });
 *      h.undo()  h.redo()  h.clear()  h.canUndo  h.canRedo  h.undoLabel  h.redoLabel
 *      h.group('יישור', () => { …כמה push… })     // צעד ביטול אחד לכמה פעולות
 *
 * 2. צילומי מצב (וקטורי, עימוד, סטודיו — מסמך JSON קטן):
 *      const u = snapshots({ get: () => doc, set: d => { doc = d; draw(); }, onChange: u => updateButtons(u) });
 *      u.reset()                               // מסמך חדש / נטען — נקודת ההתחלה, בלי צעד
 *      u.commit('הזזה')                         // אחרי שינוי; אותו מצב — לא נרשם (מחזיר false)
 *      u.change(() => { doc.x = 5; }, 'הזזה')   // שינוי במקום + commit
 *      u.commit('צבע', { merge: 'fill' })      // שינויים רצופים באותו מפתח (מחוון, הקלדה) — צעד אחד, עד mergeMs
 *      u.undo()  u.redo()  u.canUndo  u.canRedo  u.undoLabel  u.redoLabel  u.history
 *
 * הכפתורים: undoButtons(h, undoBtn, redoBtn) — disabled ו-title ("ביטול: הזזה"). הקיצורים — keys.js.
 *
 * discard(applied) — כשרשומה נזרקת (תקרה / ענף ביצוע שנמחק / clear): applied=true — הפעולה במסמך (נזרקה מתחתית
 * הביטול), false — לא (נזרקה מהביצוע). כך משחררים זיכרון רק של מה שכבר לא במסמך.
 */

export class History {
    /** { maxSteps = 100, limitBytes = Infinity, mergeMs = 1000, onChange } */
    constructor({ maxSteps = 100, limitBytes = Infinity, mergeMs = 1000, onChange = () => {} } = {}) {
        Object.assign(this, { maxSteps, limitBytes, mergeMs, onChange });
        this.undoStack = [];
        this.redoStack = [];
        this._group = null;
    }

    get canUndo() { return this.undoStack.length > 0; }
    get canRedo() { return this.redoStack.length > 0; }
    get undoLabel() { return this.undoStack.at(-1)?.label || ''; }
    get redoLabel() { return this.redoStack.at(-1)?.label || ''; }
    get bytes() { return [...this.undoStack, ...this.redoStack].reduce((n, e) => n + (e.bytes || 0), 0); }

    /**
     * רשומה חדשה. { merge: key } — אם הרשומה האחרונה עם אותו מפתח ונרשמה לפני פחות מ-mergeMs, ואין ביצוע ממתין:
     * last.merge(entry) — הרשומה הקודמת "בולעת" את החדשה (שומרת את ה-undo שלה ולוקחת את ה-redo החדש).
     */
    push(entry, { merge } = {}) {
        if (this._group) { this._group.push(entry); return; }
        const last = this.undoStack.at(-1), now = Date.now();
        if (merge && last && last.mergeKey === merge && !this.redoStack.length && now - last.at < this.mergeMs) {
            if (last.merge) last.merge(entry);
            else { const redo = entry.redo; last.redo = redo; last.bytes = (last.bytes || 0) + (entry.bytes || 0); }
            last.at = now;
            this.onChange(this);
            return;
        }
        for (const e of this.redoStack) e.discard?.(false);
        this.redoStack = [];
        this.undoStack.push(Object.assign(entry, { mergeKey: merge, at: now }));
        // הישנות ביותר נזרקות — לפי מספר ולפי זיכרון (את האחרונה תמיד שומרים)
        while (this.undoStack.length > 1 && (this.undoStack.length > this.maxSteps || this.bytes > this.limitBytes)) {
            this.undoStack.shift().discard?.(true);
        }
        this.onChange(this);
    }

    undo() {
        const e = this.undoStack.pop();
        if (!e) return null;
        e.undo();
        this.redoStack.push(e);
        this.onChange(this);
        return e;
    }

    redo() {
        const e = this.redoStack.pop();
        if (!e) return null;
        e.redo();
        this.undoStack.push(e);
        this.onChange(this);
        return e;
    }

    /** כמה פעולות — צעד ביטול אחד. fn יכולה להיות async. רשומה ריקה לא נרשמת. */
    group(label, fn) {
        if (this._group) return fn();           // קבוצה בתוך קבוצה — מצטרפת לחיצונית
        const list = this._group = [];
        const done = () => {
            this._group = null;
            if (!list.length) return;
            if (list.length === 1) { list[0].label ??= label; this.push(list[0]); return; }
            this.push({
                label, bytes: list.reduce((n, e) => n + (e.bytes || 0), 0),
                undo: () => { for (const e of [...list].reverse()) e.undo(); },
                redo: () => { for (const e of list) e.redo(); },
                discard: applied => { for (const e of list) e.discard?.(applied); },
            });
        };
        let r;
        try { r = fn(); } catch (err) { done(); throw err; }
        if (r && typeof r.then === 'function') return r.finally(done);
        done();
        return r;
    }

    /** מסמך חדש — ההיסטוריה של הקודם נזרקת. */
    clear() {
        for (const e of this.undoStack) e.discard?.(true);
        for (const e of this.redoStack) e.discard?.(false);
        this.undoStack = [];
        this.redoStack = [];
        this.onChange(this);
    }
}

/**
 * ביטול בצילומי מצב — למסמך JSON קטן. set מקבל עותק חדש (parse), אף פעם לא את האובייקט שבהיסטוריה.
 * { get, set, maxSteps = 200, mergeMs = 1000, serialize = JSON.stringify, parse = JSON.parse, onChange }
 */
export function snapshots({ get, set, maxSteps = 200, mergeMs = 1000, serialize = JSON.stringify, parse = JSON.parse, onChange = () => {} }) {
    let current = null;
    const api = {};
    const h = new History({ maxSteps, mergeMs, onChange: () => onChange(api) });
    const restore = s => { current = s; set(parse(s)); };

    function commit(label = '', { merge } = {}) {
        const snap = serialize(get());
        if (current === null) { current = snap; return false; }
        if (snap === current) return false;
        const before = current;
        current = snap;
        const entry = { label, bytes: snap.length * 2, before, after: snap,
            undo() { restore(this.before); }, redo() { restore(this.after); },
            merge(next) { this.after = next.after; this.bytes = next.bytes; } };
        entry.undo = entry.undo.bind(entry); entry.redo = entry.redo.bind(entry);
        h.push(entry, { merge });
        return true;
    }

    // defineProperties ולא Object.assign — Object.assign מעתיק את ערך ה-getter (קפוא), לא את ה-getter
    return Object.defineProperties(api, Object.getOwnPropertyDescriptors({
        /** נקודת התחלה (מסמך חדש / נטען) — ההיסטוריה מתאפסת. */
        reset() { h.clear(); current = serialize(get()); },
        commit,
        change(fn, label = '', opts) { fn(); return commit(label, opts); },
        undo() { return !!h.undo(); },
        redo() { return !!h.redo(); },
        get canUndo() { return h.canUndo; },
        get canRedo() { return h.canRedo; },
        get undoLabel() { return h.undoLabel; },
        get redoLabel() { return h.redoLabel; },
        history: h,
    }));
}

/** מצב הכפתורים: disabled ו-title לפי הצעד הבא ("ביטול: הזזה"). מחזיר פונקציה לקריאה מ-onChange. */
export function undoButtons(h, undoBtn, redoBtn, { undo = 'ביטול', redo = 'ביצוע מחדש' } = {}) {
    const sync = () => {
        if (undoBtn) { undoBtn.disabled = !h.canUndo; undoBtn.title = h.undoLabel ? `${undo}: ${h.undoLabel}` : undo; }
        if (redoBtn) { redoBtn.disabled = !h.canRedo; redoBtn.title = h.redoLabel ? `${redo}: ${h.redoLabel}` : redo; }
    };
    sync();
    return sync;
}
