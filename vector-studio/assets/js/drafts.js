// טיוטות במכשיר — עד שהספרייה בשרת תיבנה (שלב השמירה). localStorage יכול להיות חסום (גלישה בסתר,
// אחסון מלא) — אז העבודה נשמרת רק בזיכרון, והעורך אומר את זה.

const INDEX = 'vector-studio:drafts';
const KEY = (id) => 'vector-studio:draft:' + id;

function read(key, fallback) {
  try { const v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch { return fallback; }
}
function write(key, value) {
  try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch { return false; }
}

/** רשימת הטיוטות, החדשה ראשונה: [{ id, name, updated }]. */
export function list() {
  const all = read(INDEX, []);
  return Array.isArray(all) ? all.sort((a, b) => (b.updated || '').localeCompare(a.updated || '')) : [];
}

export function load(id) {
  return read(KEY(id), null);
}

/** שמירה: מחזיר false אם האחסון לא זמין. */
export function save(id, name, doc) {
  const ok = write(KEY(id), doc);
  if (!ok) return false;
  const all = list().filter(d => d.id !== id);
  all.unshift({ id, name, updated: new Date().toISOString() });
  return write(INDEX, all);
}

export function remove(id) {
  try { localStorage.removeItem(KEY(id)); } catch { /* אחסון חסום — אין מה למחוק */ }
  write(INDEX, list().filter(d => d.id !== id));
}

export function newId() {
  return Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
}

// גיבוי של ציור מהספרייה כשהשמירה בשרת נכשלה (בלי חיבור). לא נכנס לרשימת הטיוטות.
const BKEY = (id) => 'vector-studio:backup:' + id;
export const loadBackup = (id) => read(BKEY(id), null);
export const saveBackup = (id, value) => write(BKEY(id), value);
export function removeBackup(id) { try { localStorage.removeItem(BKEY(id)); } catch { /* חסום */ } }
