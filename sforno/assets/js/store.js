/*
 * ספורנו — המידע האישי של הקורא, **במכשיר בלבד** (אפיון 04): IndexedDB, מסד "sforno-reader", טבלה "kv".
 * אין חשבון בפרוסה הזו, ושום דבר מכאן לא נשלח לשרת. כל רשומה: {key, value, at} — הסנכרון (05) יוסיף rev ושרת.
 *
 *   settings          — הגדרות התצוגה
 *   customs           — המנהגים הקבועים {grp: rule}
 *   pos:<edition>     — המקום האחרון {sid, off, mode, rev}
 *   order:<edition>   — הסדר האישי של רשימת התפילות {order, names}
 *   marks             — סימניות, הדגשות והערות [{id, edition, sid, type, color, note, excerpt, created_at, updated_at}]
 *   favorites         — מועדפים [{id, edition, sid, name, created_at}]
 *   tehillim          — {done: {sid: at}, group: [sid…], names: [{id, name, note, created_at, updated_at}]}
 *   seen:local        — ההסבר על שמירה מקומית הוצג
 *
 * אין IndexedDB (גלישה פרטית בדפדפנים ישנים) — זיכרון בלבד, והמסך ממשיך לעבוד.
 */
'use strict';

(function (SF) {
    const DB = 'sforno-reader';
    let dbp = null;
    const mem = new Map();

    function open() {
        if (dbp) return dbp;
        dbp = new Promise(resolve => {
            try {
                const req = indexedDB.open(DB, 1);
                req.onupgradeneeded = () => req.result.createObjectStore('kv', { keyPath: 'key' });
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => resolve(null);
                req.onblocked = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
        return dbp;
    }

    async function tx(mode, fn) {
        const db = await open();
        if (!db) return null;
        return new Promise(resolve => {
            try {
                const t = db.transaction('kv', mode);
                const out = fn(t.objectStore('kv'));
                t.oncomplete = () => resolve(out && 'result' in out ? out.result : null);
                t.onerror = t.onabort = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
    }

    SF.store = {
        async get(key, dflt) {
            const r = await tx('readonly', s => s.get(key));
            if (r) return r.value;
            return mem.has(key) ? mem.get(key) : dflt;
        },
        async set(key, value) {
            mem.set(key, value);
            await tx('readwrite', s => s.put({ key, value, at: new Date().toISOString() }));
            return value;
        },
        async del(key) {
            mem.delete(key);
            await tx('readwrite', s => s.delete(key));
        },
        /** עדכון אטומי של רשומה: fn(ערך נוכחי) → ערך חדש */
        async update(key, dflt, fn) {
            const cur = await SF.store.get(key, dflt);
            return SF.store.set(key, fn(cur));
        },
        id: () => Date.now().toString(36) + Math.random().toString(36).slice(2, 8),
    };
})(window.SF);
