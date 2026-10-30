// אחסון מקומי ב-IndexedDB: העיצובים, והתמונות כ-Blob (לא base64). כך רענון הדף או ניתוק
// מהרשת לא מוחקים עבודה (spec/03-editor.md, "שמירה אוטומטית").

const DB_NAME = 'gc-editor';
const VERSION = 1;
let dbp = null;

function open() {
    if (dbp) return dbp;
    dbp = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('designs')) db.createObjectStore('designs', { keyPath: 'id' });
            if (!db.objectStoreNames.contains('assets')) db.createObjectStore('assets', { keyPath: 'id' });
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbp;
}

async function tx(store, mode, fn) {
    const db = await open();
    return new Promise((resolve, reject) => {
        const t = db.transaction(store, mode);
        const s = t.objectStore(store);
        let out;
        Promise.resolve(fn(s)).then(v => { out = v; });
        t.oncomplete = () => resolve(out);
        t.onerror = () => reject(t.error);
        t.onabort = () => reject(t.error || new Error('הפעולה בוטלה'));
    });
}

const req2p = r => new Promise((res, rej) => { r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });

export const designs = {
    /**
     * הרשימה בלי המסמך עצמו — רק מה שצריך לשורה. ‏owner — רק של המשתמש הזה (ועיצובים שנוצרו לפני
     * שהיה חשבון, שיעברו אליו בסנכרון). כך שני משתמשים באותו מכשיר לא רואים זה את זה.
     */
    async list(owner) {
        const all = await tx('designs', 'readonly', s => req2p(s.getAll()));
        return all
            .filter(d => d.owner == null || d.owner === owner)
            .map(d => ({ id: d.id, title: d.title, updatedAt: d.updatedAt, thumb: d.thumb, w: d.doc.page.width, h: d.doc.page.height, synced: !!d.synced, serverVersion: d.serverVersion || 0 }))
            .sort((a, b) => b.updatedAt - a.updatedAt);
    },
    async unsynced(owner) {
        const all = await tx('designs', 'readonly', s => req2p(s.getAll()));
        return all.filter(d => !d.synced && (d.owner == null || d.owner === owner));
    },
    /** מחיקה מהמכשיר בלבד (העיצוב נמחק במכשיר אחר). */
    removeLocal(id) { return this.remove(id); },
    get(id) { return tx('designs', 'readonly', s => req2p(s.get(id))); },
    put(rec) { return tx('designs', 'readwrite', s => req2p(s.put(rec))); },
    async remove(id) {
        const rec = await this.get(id);
        await tx('designs', 'readwrite', s => req2p(s.delete(id)));
        // התמונות של העיצוב נמחקות איתו, אלא אם עיצוב אחר משתמש בהן (שכפול).
        if (rec) {
            const others = await tx('designs', 'readonly', s => req2p(s.getAll()));
            // גם הגופנים: של עיצובים אחרים, ושל הרשימה "שלי" (fonts.js, gc-my-fonts)
            let mine = [];
            try { mine = JSON.parse(localStorage.getItem('gc-my-fonts')) || []; } catch { /* אין */ }
            const used = new Set([...others.flatMap(d => [...Object.values(d.doc.assets), ...Object.values(d.doc.fonts || {})].map(a => a.src)), ...mine.map(m => m.src)]);
            for (const a of [...Object.values(rec.doc.assets), ...Object.values(rec.doc.fonts || {})]) {
                if (a.src && !used.has(a.src)) await assets.remove(a.src.slice(4));
            }
        }
    },
};

export const assets = {
    async put(blob) {
        const id = 'a' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
        await tx('assets', 'readwrite', s => req2p(s.put({ id, blob, type: blob.type })));
        return 'idb:' + id;
    },
    async get(src) {
        if (!src?.startsWith('idb:')) return null;
        const rec = await tx('assets', 'readonly', s => req2p(s.get(src.slice(4))));
        return rec?.blob || null;
    },
    remove(id) { return tx('assets', 'readwrite', s => req2p(s.delete(id))); },
};
