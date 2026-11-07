/**
 * ui-core/store.js — שמירה מקומית בטוחה. מקור: pref / drafts של הווקטורי, store של עורך התמלילים, idb.js של הרסטר.
 *
 *   const p = pref('vector-studio');      // localStorage עם מרחב שמות — 'vector-studio:snap'
 *   p.get('snap', true);  p.set('snap', false);  p.del('snap');
 *
 *   const db = kv('raster-studio');       // IndexedDB פשוט: מפתח → כל ערך (גם Blob)
 *   await db.set('draft:1', blob);  await db.get('draft:1');  await db.del('draft:1');  await db.keys();
 *
 * localStorage חסום (גלישה פרטית, iframe)? get מחזיר את ברירת המחדל ו-set לא עושה כלום — בלי לזרוק.
 * הערכים ב-pref עוברים JSON.
 */
export function pref(ns) {
    const k = key => `${ns}:${key}`;
    return {
        get(key, def = null) {
            try {
                const v = localStorage.getItem(k(key));
                return v === null ? def : JSON.parse(v);
            } catch { return def; }
        },
        set(key, value) { try { localStorage.setItem(k(key), JSON.stringify(value)); return true; } catch { return false; } },
        del(key) { try { localStorage.removeItem(k(key)); } catch { /* */ } },
    };
}

export function kv(dbName, store = 'kv') {
    let dbp = null;
    const db = () => dbp ||= new Promise((res, rej) => {
        const r = indexedDB.open(dbName, 1);
        r.onupgradeneeded = () => r.result.createObjectStore(store);
        r.onsuccess = () => res(r.result);
        r.onerror = () => { dbp = null; rej(r.error); };
    });
    const run = (mode, fn) => db().then(d => new Promise((res, rej) => {
        const tx = d.transaction(store, mode);
        const req = fn(tx.objectStore(store));
        tx.oncomplete = () => res(req?.result);
        tx.onerror = () => rej(tx.error);
        tx.onabort = () => rej(tx.error);
    }));
    return {
        get: key => run('readonly', s => s.get(key)),
        set: (key, value) => run('readwrite', s => s.put(value, key)).then(() => undefined),
        del: key => run('readwrite', s => s.delete(key)).then(() => undefined),
        keys: () => run('readonly', s => s.getAllKeys()),
    };
}
