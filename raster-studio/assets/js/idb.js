// IndexedDB קטן — מפתח → ערך (גם Blob). משמש להעביר קובץ שנבחר בדף הפתיחה לעורך,
// ובהמשך — לטיוטה האוטומטית.

const DB = 'raster-studio';
const STORE = 'kv';

function open() {
  return new Promise((resolve, reject) => {
    const r = indexedDB.open(DB, 1);
    r.onupgradeneeded = () => r.result.createObjectStore(STORE);
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}

async function run(mode, fn) {
  const db = await open();
  try {
    return await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode);
      const req = fn(tx.objectStore(STORE));
      tx.oncomplete = () => resolve(req.result);
      tx.onerror = () => reject(tx.error);
      tx.onabort = () => reject(tx.error);
    });
  } finally {
    db.close();
  }
}

export const idbGet = k => run('readonly', s => s.get(k));
export const idbSet = (k, v) => run('readwrite', s => s.put(v, k));
export const idbDel = k => run('readwrite', s => s.delete(k));
