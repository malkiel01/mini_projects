/**
 * אפליקציית מצלמות — הכניסה, הניתוב, ומה שכל המסכים חולקים.
 *
 * SPA על hash. כל מסך הוא מודול שמייצא render(container, params) ומחזיר
 * (אופציונלית) פונקציית ניקוי — כי מסך המצלמה מחזיק אינטרוולים ונגן HLS
 * שחייבים להיסגר כשעוזבים אותו.
 */

import { renderHome, renderCamera } from './views-cameras.js';
import { renderGallery, renderRecording } from './views-recordings.js';
import { renderTopics, renderEvents, renderBridges, renderSettings, renderMe } from './views-admin.js';

/* ───────── API ───────── */

export const state = { user: null, overview: null, assetsVersion: null };

export async function api(action, data = {}) {
  let res;
  try {
    res = await fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
    });
  } catch {
    throw new Error('אין חיבור לשרת');
  }
  const j = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה מהשרת' }));
  if (!j.success) {
    if (res.status === 401 && action !== 'login' && action !== 'me') { state.user = null; showGuest(); }
    throw new Error(j.error || 'שגיאה');
  }
  return j;
}

/** העלאת קובץ (multipart) ל-upload.php. */
export async function upload(kind, fields, file, filename = 'file.jpg') {
  const fd = new FormData();
  fd.append('kind', kind);
  for (const [k, v] of Object.entries(fields)) fd.append(k, v);
  fd.append('file', file, filename);
  const res = await fetch('upload.php', { method: 'POST', body: fd });
  const j = await res.json().catch(() => ({ success: false, error: 'תשובה לא תקינה' }));
  if (!j.success) throw new Error(j.error || 'ההעלאה נכשלה');
  return j;
}

export async function refreshOverview() {
  const j = await api('overview');
  state.overview = j;
  return j;
}

export const isAdmin = () => state.user?.role === 'admin';
export const isOperator = () => state.user && state.user.role !== 'viewer';

/* ───────── עזרי תצוגה ───────── */

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function fmtTime(iso, withDate = true) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const t = d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  if (!withDate) return t;
  return d.toLocaleDateString('he-IL', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' + t;
}
export function fmtDur(s) {
  if (s == null || isNaN(s)) return '';
  s = Math.round(s);
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
  return (h ? h + ':' : '') + String(m).padStart(h ? 2 : 1, '0') + ':' + String(x).padStart(2, '0');
}
export function fmtClock(s) {
  // לנגן: mm:ss.f
  if (s == null || isNaN(s)) return '0:00';
  const m = Math.floor(s / 60), x = s % 60;
  return m + ':' + x.toFixed(1).padStart(4, '0');
}
export function fmtBytes(b) {
  b = Number(b) || 0;
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
  return b.toFixed(i >= 2 ? 1 : 0) + ' ' + u[i];
}
export function timeAgo(iso) {
  if (!iso) return 'אף פעם';
  const s = Math.max(0, (Date.now() - new Date(iso)) / 1000);
  if (s < 60) return 'עכשיו';
  if (s < 3600) return 'לפני ' + Math.round(s / 60) + ' דק׳';
  if (s < 86400) return 'לפני ' + Math.round(s / 3600) + ' שע׳';
  return 'לפני ' + Math.round(s / 86400) + ' ימים';
}
/** YYYY-MM-DD מקומי. */
export function localDay(d = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}
/** גבולות היום המקומי ב-ISO UTC — לסינון בשרת. */
export function dayRange(day) {
  const start = new Date(day + 'T00:00:00');
  const end = new Date(start.getTime() + 86400000);
  return { from: start.toISOString(), to: end.toISOString() };
}

export const TRIGGER_LABEL = { '': '—', motion: 'תנועה', person: 'אדם', pet: 'חיה', vehicle: 'רכב', manual: 'ידני', continuous: 'רציף', schedule: 'לו״ז' };
export const MODE_LABEL = { manual: 'ידני בלבד', continuous: 'רציף 24/7', motion: 'לפי תנועה/אדם', schedule: 'לפי לו״ז' };
export const ROLE_LABEL = { admin: 'מנהל', operator: 'מפעיל', viewer: 'צופה' };

export function cameraName(id) {
  return state.overview?.cameras.find((c) => c.id === id)?.name || ('מצלמה #' + id);
}
export function topicName(id) {
  return state.overview?.topics.find((t) => t.id === id)?.name || '';
}

/* ───────── טוסט ודיאלוג ───────── */

let toastTimer = null;
export function toast(msg, kind = '') {
  const root = document.getElementById('toast-root');
  root.innerHTML = `<div class="toast ${kind ? 'toast--' + kind : ''}">${esc(msg)}</div>`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { root.innerHTML = ''; }, kind === 'err' ? 5000 : 2800);
}

/**
 * דיאלוג. body הוא HTML; onOpen מקבל את הקופסה לחיבור אירועים.
 * מחזיר Promise שנפתר עם הערך שהועבר ל-close(value), או null בביטול.
 */
export function modal({ title, body, onOpen, wide = false, actions = [] }) {
  return new Promise((resolve) => {
    const root = document.getElementById('modal-root');
    const wrap = document.createElement('div');
    wrap.className = 'modal';
    wrap.innerHTML = `<div class="modal__box ${wide ? 'modal__box--wide' : ''}" role="dialog" aria-modal="true">
      <div class="card__title">${esc(title)}<span class="spacer"></span><button class="btn btn--ghost btn--sm" data-x type="button" aria-label="סגור">✕</button></div>
      <div data-body>${body}</div>
      ${actions.length ? `<div class="actions actions--end" style="margin-top:14px">${actions.map((a, i) => `<button class="btn ${a.cls || ''}" data-act="${i}" type="button">${esc(a.label)}</button>`).join('')}</div>` : ''}
    </div>`;
    const close = (v = null) => { wrap.remove(); document.removeEventListener('keydown', onKey); resolve(v); };
    const onKey = (e) => { if (e.key === 'Escape') close(null); };
    wrap.querySelector('[data-x]').onclick = () => close(null);
    wrap.addEventListener('click', (e) => { if (e.target === wrap) close(null); });
    wrap.querySelectorAll('[data-act]').forEach((b) => {
      b.onclick = async () => {
        const a = actions[+b.dataset.act];
        if (!a.onClick) return close(a.value ?? true);
        b.disabled = true;
        try { const r = await a.onClick(wrap.querySelector('[data-body]')); if (r !== false) close(r ?? true); }
        catch (err) { toast(err.message, 'err'); }
        finally { b.disabled = false; }
      };
    });
    document.addEventListener('keydown', onKey);
    root.appendChild(wrap);
    if (onOpen) onOpen(wrap.querySelector('[data-body]'), close);
    const first = wrap.querySelector('input:not([type=hidden]), select, textarea, button[data-act]');
    if (first) setTimeout(() => first.focus(), 30);
  });
}

export function confirmDialog(msg, label = 'מחק') {
  return modal({ title: 'אישור', body: `<p>${esc(msg)}</p>`, actions: [
    { label: 'ביטול', value: null }, { label, cls: 'btn--danger', value: true },
  ] }).then((v) => v === true);
}

export function formData(form) {
  const o = {};
  for (const [k, v] of new FormData(form)) o[k] = v;
  form.querySelectorAll('input[type=checkbox][name]').forEach((c) => { o[c.name] = c.checked ? 1 : 0; });
  return o;
}

/* ───────── ניתוב ───────── */

const routes = [
  [/^\/?$/, renderHome],
  [/^\/camera\/(\d+)$/, (c, m, q) => renderCamera(c, { id: +m[1], ...q })],
  [/^\/recordings$/, (c, m, q) => renderGallery(c, q)],
  [/^\/recording\/(\d+)$/, (c, m, q) => renderRecording(c, { id: +m[1], ...q })],
  [/^\/topics$/, renderTopics],
  [/^\/events$/, (c, m, q) => renderEvents(c, q)],
  [/^\/bridges$/, renderBridges],
  [/^\/settings$/, (c, m, q) => renderSettings(c, q)],
  [/^\/me$/, renderMe],
];

let cleanup = null;

export function navigate(hash) { location.hash = hash; }

async function route() {
  if (!state.user) return;
  const raw = location.hash.replace(/^#/, '') || '/';
  const [path, qs] = raw.split('?');
  const q = Object.fromEntries(new URLSearchParams(qs || ''));
  const view = document.getElementById('view');
  if (typeof cleanup === 'function') { try { cleanup(); } catch {} }
  cleanup = null;
  document.querySelectorAll('#nav a').forEach((a) => {
    const p = a.getAttribute('href').slice(1);
    a.classList.toggle('is-on', p === '/' ? path === '/' : path.startsWith(p));
  });
  for (const [re, fn] of routes) {
    const m = path.match(re);
    if (m) {
      view.innerHTML = '';
      try { cleanup = await fn(view, m, q); }
      catch (err) { view.innerHTML = `<div class="empty"><b>שגיאה</b>${esc(err.message)}</div>`; }
      window.scrollTo(0, 0);
      return;
    }
  }
  view.innerHTML = '<div class="empty"><b>אין דף כזה</b></div>';
}

/* ───────── כניסה ───────── */

function showGuest(needsSetup = false) {
  document.getElementById('guest').hidden = false;
  document.getElementById('app').hidden = true;
  document.getElementById('nav').hidden = true;
  document.getElementById('logout').hidden = true;
  document.getElementById('who').textContent = '';
  document.getElementById('setup-form').hidden = !needsSetup;
  document.getElementById('login-form').hidden = needsSetup;
}

function showApp() {
  document.getElementById('guest').hidden = true;
  document.getElementById('app').hidden = false;
  document.getElementById('nav').hidden = false;
  document.getElementById('logout').hidden = false;
  const who = document.getElementById('who');
  who.innerHTML = `<a href="#/me" style="color:inherit;text-decoration:none">${esc(state.user.display_name)} · ${esc(ROLE_LABEL[state.user.role])}</a>`;
  document.querySelectorAll('#nav [data-admin]').forEach((a) => { a.hidden = !isAdmin(); });
}

async function boot() {
  let me;
  try { me = await api('me'); }
  catch (err) { document.getElementById('view').innerHTML = ''; showGuest(false); toast(err.message, 'err'); return; }
  state.assetsVersion = me.assets_version;
  if (me.user) {
    state.user = me.user;
    await refreshOverview().catch(() => {});
    showApp();
    route();
  } else {
    showGuest(me.needs_setup);
  }
}

document.getElementById('setup-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const j = await api('setup', formData(e.target));
    state.user = j.user;
    await refreshOverview().catch(() => {});
    showApp(); route();
    toast('ברוך הבא. הצעד הבא: להוסיף מצלמה', 'ok');
  } catch (err) { toast(err.message, 'err'); }
});

document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const j = await api('login', formData(e.target));
    state.user = j.user;
    await refreshOverview().catch(() => {});
    showApp(); route();
  } catch (err) { toast(err.message, 'err'); }
});

document.getElementById('logout').addEventListener('click', async () => {
  try { await api('logout'); } catch {}
  state.user = null; state.overview = null;
  if (typeof cleanup === 'function') { try { cleanup(); } catch {} }
  cleanup = null;
  location.hash = '#/';
  showGuest(false);
});

window.addEventListener('hashchange', route);

// גרסה חדשה של הדף בשרת: me מחזיר את ה-?v= של app.js; אם השתנה — באנר.
setInterval(async () => {
  if (!state.user) return;
  try {
    const me = await api('me');
    if (state.assetsVersion && me.assets_version && me.assets_version !== state.assetsVersion) {
      const b = document.getElementById('update-banner');
      b.hidden = false; b.onclick = () => location.reload();
    }
  } catch {}
}, 5 * 60 * 1000);

// שגיאות JS לא נבלעות בשקט.
window.addEventListener('error', (e) => { console.error(e.error || e.message); });
window.addEventListener('unhandledrejection', (e) => { console.error(e.reason); });

boot();
