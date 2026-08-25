/**
 * עזרים משותפים.
 *
 * ‏app.js ו-platform.js שניהם צריכים אותם. הם יושבים כאן ולא באחד מהם
 * כדי שלא ייווצר יבוא מעגלי: מודול שמייבא ממודול שממתין לו נכנס
 * לנעילה הדדית, והדף פשוט אינו נטען.
 */

export const $ = (s, r = document) => r.querySelector(s);
export const $$ = (s, r = document) => [...r.querySelectorAll(s)];

export const state = {

  user: null,
  topics: [],
  topicId: null,
  status: 'active',
  search: '',
  tasks: [],
  counts: {},
  openTaskId: null,
  unassigned: 0,
  ai: { has_key: false, model: '', auto_catalog: true, key_from_env: false },
  editTopicId: null,
  connections: null,
  providers: [],
  defaultProvider: '',
  projects: [],
  repos: [],
  openProject: null,
};

export function el(tag, props = {}, children = []) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'text') n.textContent = v;
    else if (k === 'class') n.className = v;
    else if (k === 'dataset') Object.assign(n.dataset, v);
    else if (k.startsWith('on')) n.addEventListener(k.slice(2).toLowerCase(), v);
    else n.setAttribute(k, v === true ? '' : v);
  }
  for (const c of [].concat(children)) if (c) n.append(c);
  return n;
}

export function toast(msg, kind = '') {
  const host = $('#toasts');
  while (host.children.length >= 3) host.firstElementChild.remove();
  const n = el('div', { class: `toast${kind ? ` toast--${kind}` : ''}`, text: msg });
  host.append(n);
  setTimeout(() => n.remove(), kind === 'error' ? 5000 : 2600);
}

/** קריאה ל-API. שגיאה מגיעה כחריגה כדי שהקורא לא ישכח לבדוק. */
export async function api(action, payload = {}) {
  const res = await fetch(`./api.php?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  let data;
  try { data = await res.json(); } catch { throw new Error(`תשובה לא תקינה מהשרת (${res.status})`); }
  if (!data.success) throw new Error(data.error || `שגיאה (${res.status})`);
  return data;
}

export function timeAgo(ts) {
  const mins = Math.floor((Date.now() / 1000 - ts) / 60);
  if (mins < 1) return 'עכשיו';
  if (mins < 60) return `לפני ${mins} דק׳`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `לפני ${hours} שע׳`;
  return `לפני ${Math.floor(hours / 24)} ימים`;
}
