// ביטול וחזרה: צילומי מצב של המסמך (JSON). המסמך קטן — התמונות עצמן ב-IndexedDB ולא בתוכו —
// ולכן צילום מלא פשוט ובטוח יותר מרשימת פקודות הפוכות.

const LIMIT = 200;

export class History {
    constructor(onChange) {
        this.past = [];
        this.future = [];
        this.current = null;
        this.onChange = onChange;
    }

    reset(doc) {
        this.past = [];
        this.future = [];
        this.current = JSON.stringify(doc);
        this.onChange?.();
    }

    /** רושם מצב חדש. אותו מצב פעמיים (למשל לחיצה בלי תזוזה) — לא נרשם. */
    commit(doc) {
        const snap = JSON.stringify(doc);
        if (snap === this.current) return false;
        if (this.current !== null) this.past.push(this.current);
        if (this.past.length > LIMIT) this.past.shift();
        this.current = snap;
        this.future = [];
        this.onChange?.();
        return true;
    }

    get canUndo() { return this.past.length > 0; }
    get canRedo() { return this.future.length > 0; }

    undo() {
        if (!this.canUndo) return null;
        this.future.push(this.current);
        this.current = this.past.pop();
        this.onChange?.();
        return JSON.parse(this.current);
    }

    redo() {
        if (!this.canRedo) return null;
        this.past.push(this.current);
        this.current = this.future.pop();
        this.onChange?.();
        return JSON.parse(this.current);
    }
}
