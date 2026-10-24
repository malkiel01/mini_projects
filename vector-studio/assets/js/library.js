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
export const create = (name, doc, thumb) => call('create', { body: { name, doc: JSON.stringify(doc), thumb } });
export const save = (id, rev, fields) => call('save', { id, body: { id, rev, ...fields, ...(fields.doc ? { doc: JSON.stringify(fields.doc) } : {}) } });
export const remove = (id) => call('delete', { id, body: { id } });
export const versions = (id) => call('versions', { id }).then(d => d.versions);
export const restore = (id, version, rev) => call('restore', { id, body: { id, version, rev } }).then(d => d.drawing);
export const thumbUrl = (id, rev) => `api.php?action=thumb&id=${id}&v=${rev}`;
