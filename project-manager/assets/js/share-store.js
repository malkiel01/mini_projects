// מה ששותף לאפליקציה ("שתף אל…" בטלפון) וממתין למיון — נשמר במכשיר (IndexedDB)
// עד שבוחרים לאן הוא הולך. משותף ל-sw.js (שקולט את השיתוף) ולמסך השיתוף.
// כל שיתוף הוא רשומה אחת: { id, at, title, text, url, files: [File…] }.
'use strict';

const ShareStore = (() => {
    const DB = 'pm-share', STORE = 'items';

    function open() {
        return new Promise((ok, bad) => {
            const req = indexedDB.open(DB, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
            req.onsuccess = () => ok(req.result);
            req.onerror = () => bad(req.error);
        });
    }

    async function run(mode, fn) {
        const db = await open();
        try {
            return await new Promise((ok, bad) => {
                const tx = db.transaction(STORE, mode);
                const req = fn(tx.objectStore(STORE));
                tx.oncomplete = () => ok(req?.result);
                tx.onerror = tx.onabort = () => bad(tx.error);
            });
        } finally { db.close(); }
    }

    return {
        add: item => run('readwrite', s => s.put(item)),
        all: () => run('readonly', s => s.getAll()),
        count: () => run('readonly', s => s.count()),
        remove: id => run('readwrite', s => s.delete(id)),
        put: item => run('readwrite', s => s.put(item)),
        clear: () => run('readwrite', s => s.clear()),
    };
})();
