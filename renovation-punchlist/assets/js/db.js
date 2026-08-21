/**
 * שכבת האחסון של רשימת ההשלמות — IndexedDB.
 *
 * הדפדפן הוא מקור האמת: כל רשומה נכתבת כאן קודם, ורק אחר כך (אם הוגדר שרת)
 * מסונכרנת החוצה ע"י sync.js. לכן לכל רשומה יש `updatedAt` ו-`deleted`:
 * שניהם נחוצים כדי שסנכרון דו-כיווני יוכל להכריע מי חדש יותר ולהעביר מחיקות.
 */

const DB_NAME = 'renovation-punchlist';
const DB_VERSION = 1;

export const STORE_TASKS = 'tasks';
export const STORE_MEDIA = 'media';
export const STORE_META = 'meta';

/** מזהה ייחודי קצר. crypto.randomUUID חסר בדפדפנים ישנים ובהקשר לא-מאובטח. */
export function uid(prefix) {
  const rand = crypto.randomUUID
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
  return `${prefix}_${rand.replace(/-/g, '').slice(0, 20)}`;
}

export const now = () => Date.now();

let dbPromise = null;

/** פותח (ובפעם הראשונה גם יוצר) את מסד הנתונים. */
export function open() {
  if (dbPromise) return dbPromise;

  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);

    req.onupgradeneeded = () => {
      const db = req.result;

      if (!db.objectStoreNames.contains(STORE_TASKS)) {
        const tasks = db.createObjectStore(STORE_TASKS, { keyPath: 'id' });
        tasks.createIndex('updatedAt', 'updatedAt');
        tasks.createIndex('status', 'status');
      }

      if (!db.objectStoreNames.contains(STORE_MEDIA)) {
        const media = db.createObjectStore(STORE_MEDIA, { keyPath: 'id' });
        media.createIndex('taskId', 'taskId');
        media.createIndex('updatedAt', 'updatedAt');
      }

      if (!db.objectStoreNames.contains(STORE_META)) {
        db.createObjectStore(STORE_META, { keyPath: 'key' });
      }
    };

    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });

  return dbPromise;
}

/** עוטף IDBRequest ב-Promise. */
function wrap(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

/**
 * מריץ פעולה על מספר stores בטרנזקציה אחת ומחזיר את ערך ה-callback רק
 * אחרי ש-oncomplete נורה — אחרת כתיבה עלולה להיבלע אם הטרנזקציה נכשלת.
 */
async function run(storeNames, mode, fn) {
  const db = await open();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeNames, mode);
    let result;
    let failed = false;

    tx.oncomplete = () => { if (!failed) resolve(result); };
    tx.onerror = () => { failed = true; reject(tx.error); };
    tx.onabort = () => { failed = true; reject(tx.error || new Error('transaction aborted')); };

    const stores = storeNames.map((name) => tx.objectStore(name));
    Promise.resolve(fn(...stores))
      .then((value) => { result = value; })
      .catch((err) => { failed = true; reject(err); try { tx.abort(); } catch { /* כבר נסגרה */ } });
  });
}

/* ── משימות ────────────────────────────────────────────────────────── */

/** שדות ברירת המחדל של השלמה חדשה. */
export function blankTask() {
  const ts = now();
  return {
    id: uid('t'),
    title: '',
    details: '',
    assignee: '',
    location: '',
    status: 'open',      // open | progress | done
    priority: 'normal',  // normal | high
    due: '',
    createdAt: ts,
    updatedAt: ts,
    doneAt: null,
    deleted: false,
  };
}

/** כל ההשלמות החיות (בלי מחוקות), מהחדשה לישנה. */
export async function listTasks() {
  const all = await run([STORE_TASKS], 'readonly', (tasks) => wrap(tasks.getAll()));
  return all.filter((t) => !t.deleted).sort((a, b) => b.createdAt - a.createdAt);
}

export function getTask(id) {
  return run([STORE_TASKS], 'readonly', (tasks) => wrap(tasks.get(id)));
}

/** שומר השלמה ומעדכן חותמת זמן. `touch=false` בשימוש הסנכרון בלבד. */
export function saveTask(task, touch = true) {
  const record = { ...task };
  if (touch) record.updatedAt = now();
  return run([STORE_TASKS], 'readwrite', (tasks) => wrap(tasks.put(record))).then(() => record);
}

/**
 * מחיקה רכה: הרשומה נשארת כ-tombstone כדי שהמחיקה תתפשט בסנכרון.
 * הקבצים עצמם נמחקים מיד — הם תופסים מקום, והמטא-דאטה מספיקה לסנכרון.
 */
export function deleteTask(id) {
  const ts = now();
  return run([STORE_TASKS, STORE_MEDIA], 'readwrite', async (tasks, media) => {
    const task = await wrap(tasks.get(id));
    if (task) await wrap(tasks.put({ ...task, deleted: true, updatedAt: ts }));

    const items = await wrap(media.index('taskId').getAll(id));
    for (const item of items) {
      await wrap(media.put({ ...item, deleted: true, updatedAt: ts, blob: null, thumb: null }));
    }
  });
}

/* ── מדיה ──────────────────────────────────────────────────────────── */

/** מוסיף קובץ מדיה (הקבצים נשמרים כ-Blob, לא כ-base64). */
export function addMedia(record) {
  const ts = now();
  const full = {
    id: uid('m'),
    kind: 'image',
    mime: '',
    name: '',
    size: 0,
    width: 0,
    height: 0,
    blob: null,
    thumb: null,
    createdAt: ts,
    updatedAt: ts,
    deleted: false,
    uploaded: false,
    ...record,
  };
  return run([STORE_MEDIA], 'readwrite', (media) => wrap(media.put(full))).then(() => full);
}

export function putMedia(record) {
  return run([STORE_MEDIA], 'readwrite', (media) => wrap(media.put(record))).then(() => record);
}

export function getMedia(id) {
  return run([STORE_MEDIA], 'readonly', (media) => wrap(media.get(id)));
}

/** כל המדיה החיה של השלמה, לפי סדר ההוספה. */
export async function listMedia(taskId) {
  const items = await run([STORE_MEDIA], 'readonly', (media) =>
    wrap(media.index('taskId').getAll(taskId)));
  return items.filter((m) => !m.deleted).sort((a, b) => a.createdAt - b.createdAt);
}

/** מפה של taskId → פריט מדיה ראשון, לתצוגה המקדימה ברשימה. */
export async function coverMap() {
  const items = await run([STORE_MEDIA], 'readonly', (media) => wrap(media.getAll()));
  const covers = new Map();
  const counts = new Map();

  for (const item of items.filter((m) => !m.deleted).sort((a, b) => a.createdAt - b.createdAt)) {
    counts.set(item.taskId, (counts.get(item.taskId) || 0) + 1);
    if (!covers.has(item.taskId)) covers.set(item.taskId, item);
  }
  return { covers, counts };
}

export function deleteMedia(id) {
  const ts = now();
  return run([STORE_MEDIA], 'readwrite', async (media) => {
    const item = await wrap(media.get(id));
    if (!item) return;
    await wrap(media.put({ ...item, deleted: true, updatedAt: ts, blob: null, thumb: null }));
  });
}

/* ── הגדרות ורשומות עזר ────────────────────────────────────────────── */

export async function getMeta(key, fallback = null) {
  const row = await run([STORE_META], 'readonly', (meta) => wrap(meta.get(key)));
  return row ? row.value : fallback;
}

export function setMeta(key, value) {
  return run([STORE_META], 'readwrite', (meta) => wrap(meta.put({ key, value })));
}

/* ── סנכרון וייצוא ─────────────────────────────────────────────────── */

/** כל מה שהשתנה מאז חותמת זמן — הבסיס ל-push. */
export async function changedSince(ts) {
  const [tasks, media] = await run([STORE_TASKS, STORE_MEDIA], 'readonly', (t, m) =>
    Promise.all([wrap(t.getAll()), wrap(m.getAll())]));

  return {
    tasks: tasks.filter((x) => x.updatedAt > ts),
    media: media.filter((x) => x.updatedAt > ts),
  };
}

/** כל המדיה החיה שטרם הועלתה לשרת. */
export async function pendingUploads() {
  const items = await run([STORE_MEDIA], 'readonly', (media) => wrap(media.getAll()));
  return items.filter((m) => !m.deleted && !m.uploaded && m.blob);
}

export function allTasksRaw() {
  return run([STORE_TASKS], 'readonly', (tasks) => wrap(tasks.getAll()));
}

export function allMediaRaw() {
  return run([STORE_MEDIA], 'readonly', (media) => wrap(media.getAll()));
}

/** מוחק הכול — כולל tombstones. משמש את "איפוס האפליקציה". */
export function wipe() {
  return run([STORE_TASKS, STORE_MEDIA, STORE_META], 'readwrite', async (tasks, media, meta) => {
    await Promise.all([wrap(tasks.clear()), wrap(media.clear()), wrap(meta.clear())]);
  });
}

/** גודל האחסון המשוער בשימוש, אם הדפדפן חושף אותו. */
export async function usage() {
  if (!navigator.storage?.estimate) return null;
  try {
    const { usage: used, quota } = await navigator.storage.estimate();
    return { used, quota };
  } catch {
    return null;
  }
}
