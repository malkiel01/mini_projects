// הספרייה בשרת (api.php): רשימה, טעינה, יצירה ושמירה עם rev, גרסאות. המסמך עובר כמחרוזת JSON — השרת שומר אותה
// כמו שהיא.

async function call(action, { id, body, method } = {}) {
  const url = 'api.php?action=' + action + (id ? '&id=' + encodeURIComponent(id) : '');
  const opt = { credentials: 'same-origin', method: method || (body ? 'POST' : 'GET') };
  if (body) { opt.headers = { 'Content-Type': 'application/json' }; opt.body = JSON.stringify(body); }
  let res;
  try { res = await fetch(url, opt); } catch { throw Object.assign(new Error('אין חיבור לשרת'), { offline: true }); }
  const data = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה מהשרת' }));
  if (!data.success) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status });
  return data;
}

export const list = () => call('list').then(d => d.drawings);
export const get = (id) => call('get', { id }).then(d => d.drawing);
export const create = (name, doc, thumb, container = null) =>
  call('create', { body: { name, doc: JSON.stringify(doc), thumb, ...(container ? { container: toBase64(container) } : {}) } });
export const save = (id, rev, fields) => call('save', { id, body: { id, rev, ...fields, ...(fields.doc ? { doc: JSON.stringify(fields.doc) } : {}) } });
export const remove = (id) => call('delete', { id, body: { id } });
export const versions = (id) => call('versions', { id }).then(d => d.versions);
export const restore = (id, version, rev) => call('restore', { id, body: { id, version, rev } }).then(d => d.drawing);
export const thumbUrl = (id, rev) => `api.php?action=thumb&id=${id}&v=${rev}`;

/** ה-‎.mbe המקורי של ציור שנפתח מקובץ, או null. */
export async function container(id) {
  const res = await fetch(`api.php?action=container&id=${id}`, { credentials: 'same-origin' });
  if (res.status === 404) return null;
  if (!res.ok) throw new Error('הקובץ המקורי לא נטען');
  return new Uint8Array(await res.arrayBuffer());
}

function toBase64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(s);
}

/** פתיחת ‎.mbe מהמכשיר: נבדק בליבה, ונכנס לספרייה עם הקובץ המקורי (כדי לשמור את מה שהעורך לא מבין). */
export async function importMbe(file) {
  if (file.size > 25 * 1024 * 1024) throw new Error('הקובץ גדול מ-25MB');
  const bytes = new Uint8Array(await file.arrayBuffer());
  const { readMbe } = await import('./mbefile.js');
  const { doc, notes } = await readMbe(bytes);
  const name = file.name.replace(/\.mbe$/i, '').trim() || 'ציור';
  const r = await create(name, doc, null, bytes);
  return { id: r.id, notes };
}
