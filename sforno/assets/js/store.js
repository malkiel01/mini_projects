/*
 * ספורנו — המידע האישי של הקורא במכשיר (אפיון 04): IndexedDB, מסד "sforno-reader", טבלה "kv". כל רשומה: {key, value, at}.
 * בלי חשבון — הכול נשאר כאן. עם חשבון (05) — כל שינוי ברשומה שמסתנכרנת עובר ל-SF.sync (assets/js/sync.js), שמכניס אותו
 * לתור ושולח בחזרת חיבור. setRaw / delRaw — כתיבה של הסנכרון עצמו (מה שהגיע מהשרת), שלא חוזרת לתור.
 *
 *   settings          — הגדרות התצוגה
 *   customs           — המנהגים הקבועים {grp: rule}
 *   pos:<edition>     — המקום האחרון {sid, off, mode, rev}
 *   order:<edition>   — הסדר האישי של רשימת התפילות {order, names}
 *   marks             — סימניות, הדגשות והערות [{id, edition, sid, type, color, note, excerpt, created_at, updated_at}]
 *   favorites         — מועדפים [{id, edition, sid, name, created_at}]
 *   tehillim          — {done: {sid: at}, group: [sid…], names: [{id, name, note, created_at, updated_at}]}
 *   seen:local        — ההסבר על שמירה מקומית הוצג
 *   sync:state / sync:outbox / guest:stash — של הסנכרון (05): מה שהשרת אמר, התור, ומידע של אורח ששמרתם "רק במכשיר"
 *   ver:<edition>     — הגרסה שהקורא בחר {rev, prev} (06; מסתנכרן)
 *   dl:<edition>      — ההורדה שבמכשיר הזה (06; setRaw — לא מסתנכרן): גרסה, גודל, מצב, הקבצים. הקבצים עצמם — במטמון "sforno-content"
 *   catalog:last      — הקטלוג האחרון שהתקבל (06; setRaw) — כדי להראות בלי רשת מה זמין ומה לא
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

    const note = (key, value) => { if (SF.sync) SF.sync.note(key, value); };

    SF.store = {
        async get(key, dflt) {
            const r = await tx('readonly', s => s.get(key));
            if (r) return r.value;
            return mem.has(key) ? mem.get(key) : dflt;
        },
        async set(key, value) {
            await SF.store.setRaw(key, value);
            note(key, value);
            return value;
        },
        async del(key) {
            await SF.store.delRaw(key);
            note(key, undefined);
        },
        async setRaw(key, value) {
            mem.set(key, value);
            await tx('readwrite', s => s.put({ key, value, at: new Date().toISOString() }));
            return value;
        },
        async delRaw(key) {
            mem.delete(key);
            await tx('readwrite', s => s.delete(key));
        },
        /** כל המפתחות שבמכשיר */
        async keys() {
            const r = await tx('readonly', s => s.getAllKeys());
            return Array.from(new Set([...(r || []), ...mem.keys()]));
        },
        /** עדכון אטומי של רשומה: fn(ערך נוכחי) → ערך חדש */
        async update(key, dflt, fn) {
            const cur = await SF.store.get(key, dflt);
            return SF.store.set(key, fn(cur));
        },
        id: () => Date.now().toString(36) + Math.random().toString(36).slice(2, 8),
    };
})(window.SF);
