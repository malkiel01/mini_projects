// אחסון בדפדפן, עד שיש שרת (שלב 4).
//
// ההגדרות הקטנות (טיוטת הפרויקט, שינויים בספריית החומרים) ב-localStorage.
// תמונות החומרים — גדולות — ב-IndexedDB, מפתח = מזהה החומר. בשלב 4 אותו
// ממשק (load/save) מקבל מימוש מול api.php, והשאר לא משתנה.

const LS_DRAFT = 'carpentry-3d:draft';
const LS_MATERIALS = 'carpentry-3d:materials';
const DB = 'carpentry-3d';
const STORE = 'images';

export function loadDraft() {
  try { return JSON.parse(localStorage.getItem(LS_DRAFT) || 'null'); } catch { return null; }
}
export function saveDraft(draft) {
  try { localStorage.setItem(LS_DRAFT, JSON.stringify(draft)); } catch { /* אחסון חסום — עובדים בלי זיכרון */ }
}

export function loadMaterialsDiff() {
  try { return JSON.parse(localStorage.getItem(LS_MATERIALS) || '[]'); } catch { return []; }
}
export function saveMaterialsDiff(list) {
  try { localStorage.setItem(LS_MATERIALS, JSON.stringify(list)); } catch { /* ראו למעלה */ }
}

function db() {
  return new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) { reject(new Error('אין IndexedDB')); return; }
    const req = indexedDB.open(DB, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
function tx(mode, fn) {
  return db().then((d) => new Promise((resolve, reject) => {
    const t = d.transaction(STORE, mode);
    const req = fn(t.objectStore(STORE));
    t.oncomplete = () => resolve(req && req.result);
    t.onerror = () => reject(t.error);
  })).catch(() => undefined);   // בלי IndexedDB (מצב פרטי וכו') — פשוט בלי תמונות
}

/** כל התמונות: { id → { image, imageMm } } */
export async function loadImages() {
  const out = {};
  await tx('readonly', (s) => {
    const req = s.openCursor();
    req.onsuccess = () => { const c = req.result; if (c) { out[c.key] = c.value; c.continue(); } };
    return null;
  });
  return out;
}
export function saveImage(id, image, imageMm) { return tx('readwrite', (s) => s.put({ image, imageMm }, id)); }
export function deleteImage(id) { return tx('readwrite', (s) => s.delete(id)); }
