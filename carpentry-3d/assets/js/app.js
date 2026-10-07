// חיבור הכול: כניסה → פרויקטים → טופס → מודל → תלת מימד, רשימת חיתוך,
// אזהרות, ספריית חומרים, שיתוף עם לקוח, ומסכי המנהל (משתמשים, סוגי מוצרים).
//
// שלבים 1–4 מהאפיון. השרת (api.php) מחזיק משתמשים, פרויקטים, סוגי מוצרים,
// ספרייה ותעריפים; הדפדפן מחזיק רק את הפרויקט הפתוח, ושומר אותו אוטומטית
// שנייה אחרי כל שינוי.

import { TEMPLATES, template, defaults, build, cutList, hardwareList, allParams, clamp } from './model/index.js';
import { cutSize } from './model/blocks.js';
import * as M from './model/materials.js';
import { renderForm } from './form.js';
import { createViewer } from './viewer.js';
import { createMaterialsUI } from './materials-ui.js';
import { api, loadLast, saveLast } from './store.js';

const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');

const state = {
  user: null,
  types: [],
  project: null,       // { id, name, client, status, template_key, product_type_id, values, share_token }
  values: {},
  rates: { laborHour: null, markup: null, materials: {} },
  dirty: false,
};
let viewer = null, form = null, model = null, materialsUI = null;
let saveTimer = null;

// ---------- הודעות ----------
function toast(msg, kind = 'info') {
  const el = $('#toast');
  el.textContent = msg;
  el.className = `toast is-on toast--${kind}`;
  clearTimeout(toast.t);
  toast.t = setTimeout(() => el.classList.remove('is-on'), kind === 'error' ? 5000 : 2200);
}
const onError = (err) => { console.error(err); toast(err.message || String(err), 'error'); if (err.status === 401) showAuth(); };

// ---------- כניסה ----------
async function boot() {
  try {
    const r = await api('me');
    state.user = r.user;
    if (!r.user) { showAuth(r.setup); return; }
    await enter();
  } catch (err) { onError(err); showAuth(false); }
}

function showAuth(setup = false) {
  const box = $('#auth');
  box.hidden = false;
  box.dataset.mode = setup ? 'setup' : 'login';
  $('#auth-title').textContent = setup ? 'הקמת המערכת — המנהל הראשון' : 'כניסה';
  $('#auth-name-row').hidden = !setup;
  $('#auth-submit').textContent = setup ? 'יצירת מנהל וכניסה' : 'כניסה';
  $('#auth-hint').textContent = setup
    ? 'המסד ריק. המשתמש שנוצר עכשיו הוא המנהל; הוא יוצר אחר כך את הנגרים.'
    : 'אין הרשמה חופשית — חשבון מקבלים מהמנהל.';
  $('#auth-error').textContent = '';
}
$('#auth-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const setup = $('#auth').dataset.mode === 'setup';
  try {
    const r = await api(setup ? 'setup' : 'login', { email: f.email.value, password: f.password.value, name: f.name.value });
    state.user = r.user;
    $('#auth').hidden = true;
    f.password.value = '';
    await enter();
  } catch (err) { $('#auth-error').textContent = err.message; }
});
$('#btn-logout').addEventListener('click', async () => {
  await flush();
  try { await api('logout'); } catch { /* גם אם נכשל — יוצאים */ }
  location.reload();
});

// ---------- אחרי הכניסה ----------
async function enter() {
  const u = state.user;
  $('#user-name').textContent = u.name;
  document.body.dataset.role = u.role;
  $('#btn-users').hidden = u.role !== 'admin';
  $('#btn-types').hidden = u.role !== 'admin';
  $('#app').hidden = false;

  const [lib, types, rates] = await Promise.all([api('materials-get'), api('types-list'), u.role === 'viewer' ? null : api('rates-get')]);
  M.load(lib.diff);
  for (const [id, v] of Object.entries(lib.images || {})) M.setImage(id, v.url, v.imageMm);
  state.types = types.types;
  if (rates) state.rates = rates.rates;

  if (!viewer) {
    viewer = createViewer($('#stage'), { onPick: showPart });
    materialsUI = createMaterialsUI($('#mlib'), {
      onChange: () => { if (state.project) { form = renderForm($('#form'), currentTemplate(), state.values, onFormChange); rebuild(); } },
      onError, getUser: () => state.user,
      rates: { get: () => state.rates, set: (p) => Object.assign(state.rates, p) },
    });
    wireUi();
  }

  // הפרויקט האחרון שנפתח, אם עדיין קיים; אחרת רשימת הפרויקטים.
  const last = loadLast();
  if (last?.projectId) {
    try { await openProject(last.projectId); return; } catch { /* נמחק — לרשימה */ }
  }
  await showProjects();
}

function currentTemplate() { return template(state.project?.template_key || 'bookcase'); }

function wireUi() {
  document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => viewer.view(b.dataset.view)));
  $('#btn-reset').addEventListener('click', () => {
    if (!state.project) return;
    Object.assign(state.values, typeDefaults(state.project.product_type_id, state.project.template_key));
    form = renderForm($('#form'), currentTemplate(), state.values, onFormChange);
    rebuild(true); markDirty();
  });
  document.querySelectorAll('.tabs button').forEach((b) => b.addEventListener('click', () => {
    if (b.dataset.tab === 'materials') { materialsUI.open(); return; }
    if (b.dataset.tab === 'projects') { showProjects(); return; }
    document.body.dataset.tab = b.dataset.tab;
    document.querySelectorAll('.tabs button').forEach((x) => x.classList.toggle('is-active', x === b));
    viewer.fit();
  }));
  $('#btn-output').addEventListener('click', () => $('#output').classList.toggle('is-open'));
  $('#btn-output-close').addEventListener('click', () => $('#output').classList.remove('is-open'));
  $('#btn-materials').addEventListener('click', () => materialsUI.open());
  $('#btn-projects').addEventListener('click', () => showProjects());
  $('#btn-users').addEventListener('click', () => showUsers());
  $('#btn-types').addEventListener('click', () => showTypes());
  $('#btn-save').addEventListener('click', () => flush(true));
  $('#btn-share').addEventListener('click', shareProject);
  $('#btn-password').addEventListener('click', changePassword);
  document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => $(b.dataset.close).classList.remove('is-open')));

  // שם, לקוח וסטטוס — בכרטיס "פרויקט" שמעל הטופס
  $('#proj-name').addEventListener('input', (e) => { state.project.name = e.target.value; markDirty(); $('#template-name').textContent = state.project.name; });
  $('#proj-client').addEventListener('input', (e) => { state.project.client = e.target.value; markDirty(); });
  $('#proj-status').addEventListener('change', (e) => { state.project.status = e.target.value; markDirty(); });

  window.addEventListener('beforeunload', (e) => { if (state.dirty) { flush(); e.preventDefault(); e.returnValue = ''; } });
}

// ---------- פרויקטים ----------
async function showProjects() {
  const box = $('#projects');
  box.classList.add('is-open');
  const body = $('#projects-body');
  body.innerHTML = '<p class="muted">טוען…</p>';
  try {
    const r = await api('projects-list');
    const canEdit = state.user.role !== 'viewer';
    const typesHtml = state.types.length
      ? state.types.map((t) => `<button type="button" class="btn btn--accent" data-new-type="${t.id}">+ ${esc(t.name)}</button>`).join(' ')
      : '';
    body.innerHTML = `
      ${canEdit ? `<div class="projects__new">
        <span class="muted">פרויקט חדש:</span>
        ${typesHtml}
        ${Object.values(TEMPLATES).map((t) => `<button type="button" class="btn" data-new-tpl="${t.key}">+ ${esc(t.name)} (תבנית בסיסית)</button>`).join(' ')}
      </div>` : ''}
      ${r.projects.length ? `<table class="list"><thead><tr><th>שם</th><th>לקוח</th><th>סטטוס</th>${state.user.role === 'admin' ? '<th>נגר</th>' : ''}<th>עודכן</th><th></th></tr></thead><tbody>
        ${r.projects.map((p) => `<tr data-id="${p.id}">
          <td><a href="#" data-open="${p.id}"><b>${esc(p.name)}</b></a> ${p.shared ? '<span title="משותף עם לקוח">🔗</span>' : ''}</td>
          <td>${esc(p.client)}</td><td>${statusName(p.status)}</td>
          ${state.user.role === 'admin' ? `<td>${esc(p.owner_name)}</td>` : ''}
          <td class="muted">${fmtDate(p.updated_at)}</td>
          <td class="list__actions">${canEdit ? `<button type="button" class="btn btn--small" data-dup="${p.id}">שכפול</button> <button type="button" class="btn btn--small" data-del="${p.id}">מחיקה</button>` : ''}</td>
        </tr>`).join('')}</tbody></table>`
        : '<p class="muted">אין עדיין פרויקטים.</p>'}`;
    body.onclick = async (e) => {
      const t = e.target.closest('[data-open],[data-dup],[data-del],[data-new-type],[data-new-tpl]');
      if (!t) return;
      e.preventDefault();
      try {
        if (t.dataset.open) { await openProject(Number(t.dataset.open)); box.classList.remove('is-open'); }
        if (t.dataset.dup) { await api('project-duplicate', { id: Number(t.dataset.dup) }); showProjects(); }
        if (t.dataset.del && confirm('למחוק את הפרויקט? אין שחזור.')) {
          await api('project-delete', { id: Number(t.dataset.del) });
          if (state.project?.id === Number(t.dataset.del)) { state.project = null; saveLast({}); }
          showProjects();
        }
        if (t.dataset.newType) { await newProject(state.types.find((x) => x.id === Number(t.dataset.newType))); box.classList.remove('is-open'); }
        if (t.dataset.newTpl) { await newProject(null, t.dataset.newTpl); box.classList.remove('is-open'); }
      } catch (err) { onError(err); }
    };
  } catch (err) { onError(err); }
}

function typeDefaults(typeId, templateKey) {
  const tpl = template(templateKey);
  const v = defaults(tpl);
  const t = state.types.find((x) => x.id === typeId);
  if (t) for (const p of allParams(tpl)) if (t.defaults[p.key] !== undefined) v[p.key] = clamp(p, t.defaults[p.key]);
  return v;
}

async function newProject(type, templateKey) {
  await flush();
  const key = type ? type.template_key : templateKey;
  const name = prompt('שם הפרויקט (למשל "ספרייה לסלון — משפחת כהן"):', type ? type.name : template(key).name);
  if (name === null) return;
  const r = await api('project-save', { name, template_key: key, product_type_id: type ? type.id : null, values: typeDefaults(type?.id, key) });
  loadProject(r.project);
  toast('הפרויקט נוצר');
}

async function openProject(id) {
  await flush();
  const r = await api('project-get', { id });
  loadProject(r.project);
}

function loadProject(p) {
  state.project = p;
  if (!TEMPLATES[p.template_key]) { toast(`תבנית לא מוכרת: ${p.template_key}`, 'error'); return; }
  state.values = { ...defaults(currentTemplate()), ...p.values };
  state.dirty = false;
  saveLast({ projectId: p.id });
  $('#template-name').textContent = p.name;
  $('#template-desc').textContent = (p.client ? `${p.client} · ` : '') + currentTemplate().name;
  $('#proj-name').value = p.name;
  $('#proj-client').value = p.client;
  $('#proj-status').value = p.status;
  $('#project-card').hidden = false;
  $('#no-project').hidden = true;
  $('#form').hidden = false;
  const ro = state.user.role === 'viewer';
  $('#project-card').querySelectorAll('input,select').forEach((i) => { i.disabled = ro; });
  form = renderForm($('#form'), currentTemplate(), state.values, onFormChange);
  if (ro) $('#form').querySelectorAll('input,select').forEach((i) => { i.disabled = true; });
  rebuild(true);
  updateShareUi();
}

function onFormChange() { rebuild(); markDirty(); }

function markDirty() {
  if (!state.project || state.user.role === 'viewer') return;
  state.dirty = true;
  $('#save-state').textContent = 'שינויים לא שמורים';
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => flush(), 1200);
}

/** שומר אם יש מה לשמור. `loud` — להודיע גם כשאין שינוי. */
async function flush(loud = false) {
  clearTimeout(saveTimer);
  if (!state.project || !state.dirty) { if (loud) toast('הכול שמור'); return; }
  const p = state.project;
  // מנקים את הדגל לפני הבקשה: שינוי שיגיע בזמן שהיא באוויר ידליק אותו שוב
  // ויזמן שמירה נוספת, במקום להיבלע כשהבקשה הראשונה חוזרת.
  state.dirty = false;
  try {
    const r = await api('project-save', { id: p.id, name: p.name, client: p.client, status: p.status, template_key: p.template_key, product_type_id: p.product_type_id, values: model ? model.values : state.values });
    if (state.project === p) state.project.updated_at = r.project.updated_at;
    $('#save-state').textContent = `נשמר ${fmtTime(r.project.updated_at)}`;
    if (loud) toast('נשמר');
  } catch (err) { state.dirty = true; onError(err); }
}

async function shareProject() {
  if (!state.project) return;
  await flush();
  const p = state.project;
  try {
    if (p.share_token) {
      if (!confirm('לבטל את קישור הצפייה? הלקוח לא יוכל לפתוח אותו יותר.')) { showShareLink(); return; }
      const r = await api('project-share', { id: p.id, on: false });
      state.project.share_token = r.project.share_token;
      toast('הקישור בוטל');
    } else {
      const r = await api('project-share', { id: p.id, on: true });
      state.project.share_token = r.project.share_token;
      showShareLink();
    }
    updateShareUi();
  } catch (err) { onError(err); }
}
function shareUrl() {
  return new URL(`view.html?t=${state.project.share_token}`, location.href).href;
}
function showShareLink() {
  const url = shareUrl();
  $('#share-url').value = url;
  $('#share').classList.add('is-open');
  $('#share-copy').onclick = async () => {
    try { await navigator.clipboard.writeText(url); toast('הקישור הועתק'); } catch { $('#share-url').select(); }
  };
}
function updateShareUi() {
  const on = !!state.project?.share_token;
  $('#btn-share').textContent = on ? '🔗 קישור ללקוח (פעיל)' : '🔗 קישור ללקוח';
  $('#btn-share').classList.toggle('is-on', on);
}

// ---------- בנייה ותצוגות ----------
function rebuild(reframe = false) {
  if (!state.project) return;
  model = build(state.project.template_key, state.values);
  Object.assign(state.values, model.values);
  form.sync(model.values);
  viewer.setModel(model);
  if (reframe) viewer.frame(model.bounds);
  renderWarnings(model.warnings);
  renderSummary(model);
  renderOutput(model);
}

function renderWarnings(list) {
  const box = $('#warnings');
  box.hidden = list.length === 0;
  box.innerHTML = list.map((w) => `<div class="warn">⚠ ${esc(w)}</div>`).join('');
  $('#tab-warn-count').textContent = list.length ? `(${list.length})` : '';
}

function renderSummary(m) {
  const b = m.bounds;
  $('#summary').innerHTML = `
    <span>${b.w} × ${b.h} × ${b.d} מ"מ</span>
    <span>${m.parts.length} חלקים</span>
    <span>${m.hardware.reduce((n, h) => n + (h.qty || 1), 0)} פריטי פרזול</span>`;
}

function showPart(p) {
  const box = $('#part');
  if (!p) { box.innerHTML = '<p class="muted">הקשה על לוח מציגה את מידותיו. גרירה מסובבת; שתי אצבעות או גלגלת מזמנות.</p>'; return; }
  const c = cutSize(p);
  const m = M.material(p.material);
  const edges = Object.entries(p.edges || {}).filter(([, v]) => v).map(([k]) => ({ front: 'חזית', back: 'אחור', top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' }[k])).join(', ') || 'ללא';
  const grain = { x: 'לרוחב', y: 'לגובה', z: 'לעומק' }[p.grain];
  box.innerHTML = `
    <h3>${esc(p.name)}</h3>
    <dl>
      <dt>חיתוך</dt><dd><b>${c.l} × ${c.w}</b> × ${c.t} מ"מ</dd>
      <dt>חומר</dt><dd>${esc(m.name)}</dd>
      <dt>סיבים</dt><dd>${grain}</dd>
      <dt>קנט</dt><dd>${edges}</dd>
      <dt>מיקום</dt><dd>x ${Math.round(p.box.x)} · y ${Math.round(p.box.y)} · z ${Math.round(p.box.z)}</dd>
      ${p.note ? `<dt>הערה</dt><dd>${esc(p.note)}</dd>` : ''}
    </dl>`;
}

function renderOutput(m) {
  const cl = cutList(m);
  const hw = hardwareList(m);
  const rows = cl.boards
    .sort((a, b) => b.l * b.w - a.l * a.w)
    .map((r) => `<tr><td>${esc(r.name)}</td><td>${r.qty}</td><td dir="ltr">${r.l} × ${r.w}</td><td>${r.t}</td><td>${esc(r.material)}</td><td>${esc(r.edges)}</td><td>${esc(r.note || '')}</td></tr>`)
    .join('');
  const glass = cl.glass.map((g) => `<tr><td>${esc(g.name)}</td><td>${g.qty}</td><td dir="ltr">${g.l} × ${g.w}</td><td>${g.t}</td></tr>`).join('');
  const hwRows = hw.map((h) => `<tr><td>${esc(h.name)}</td><td>${h.qty}</td></tr>`).join('');
  const area = cl.boards.reduce((s, r) => s + r.qty * r.l * r.w, 0) / 1e6;

  $('#output-body').innerHTML = `
    <h3>רשימת חיתוך <small>${cl.boards.reduce((s, r) => s + r.qty, 0)} לוחות · ${area.toFixed(2)} מ"ר נטו</small></h3>
    <table><thead><tr><th>חלק</th><th>כמות</th><th>אורך × רוחב</th><th>עובי</th><th>חומר</th><th>קנט</th><th>הערה</th></tr></thead><tbody>${rows}</tbody></table>
    ${glass ? `<h3>זכוכית</h3><table><thead><tr><th>סוג</th><th>כמות</th><th>מידה</th><th>עובי</th></tr></thead><tbody>${glass}</tbody></table>` : ''}
    ${hwRows ? `<h3>פרזול</h3><table><thead><tr><th>פריט</th><th>כמות</th></tr></thead><tbody>${hwRows}</tbody></table>` : ''}
    <p class="muted">האורך הוא בכיוון הסיבים. מידות אחרי הורדת הקנט כשנבחר "יורדת מהמידה".</p>`;
}

// ---------- מנהל: משתמשים ----------
async function showUsers() {
  const box = $('#users');
  box.classList.add('is-open');
  const body = $('#users-body');
  try {
    const r = await api('users-list');
    const roles = { admin: 'מנהל', carpenter: 'נגר', viewer: 'צופה' };
    body.innerHTML = `
      <form class="users__new" id="user-new">
        <input name="name" placeholder="שם" required>
        <input name="email" type="email" placeholder="דוא&quot;ל" required>
        <input name="password" type="text" placeholder="סיסמה (8+ תווים)" minlength="8" required>
        <select name="role"><option value="carpenter">נגר</option><option value="viewer">צופה</option><option value="admin">מנהל</option></select>
        <button type="submit" class="btn btn--accent">+ משתמש</button>
      </form>
      <table class="list"><thead><tr><th>שם</th><th>דוא"ל</th><th>תפקיד</th><th>מצב</th><th></th></tr></thead><tbody>
      ${r.users.map((u) => `<tr data-id="${u.id}">
        <td><input name="name" value="${esc(u.name)}" class="inline"></td>
        <td dir="ltr">${esc(u.email)}</td>
        <td><select name="role" class="inline" ${u.id === state.user.id ? 'disabled' : ''}>${Object.entries(roles).map(([k, v]) => `<option value="${k}" ${u.role === k ? 'selected' : ''}>${v}</option>`).join('')}</select></td>
        <td>${u.blocked ? '<span class="tag tag--off">חסום</span>' : '<span class="tag">פעיל</span>'}</td>
        <td class="list__actions">
          ${u.id !== state.user.id ? `<button type="button" class="btn btn--small" data-block="${u.blocked ? 0 : 1}">${u.blocked ? 'שחרור' : 'חסימה'}</button>` : ''}
          <button type="button" class="btn btn--small" data-pw="1">סיסמה חדשה</button>
        </td></tr>`).join('')}</tbody></table>`;
    $('#user-new').onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      try { await api('user-create', { name: f.name.value, email: f.email.value, password: f.password.value, role: f.role.value }); toast('המשתמש נוצר'); showUsers(); } catch (err) { onError(err); }
    };
    body.onchange = async (e) => {
      const tr = e.target.closest('tr[data-id]'); if (!tr) return;
      const patch = {}; patch[e.target.name] = e.target.value;
      try { await api('user-update', { id: Number(tr.dataset.id), patch }); toast('עודכן'); } catch (err) { onError(err); showUsers(); }
    };
    body.onclick = async (e) => {
      const tr = e.target.closest('tr[data-id]'); if (!tr) return;
      const id = Number(tr.dataset.id);
      try {
        if (e.target.dataset.block !== undefined) { await api('user-update', { id, patch: { blocked: e.target.dataset.block === '1' } }); showUsers(); }
        if (e.target.dataset.pw) {
          const pw = prompt('סיסמה חדשה (8+ תווים):'); if (!pw) return;
          await api('user-update', { id, patch: { password: pw } }); toast('הסיסמה הוחלפה');
        }
      } catch (err) { onError(err); }
    };
  } catch (err) { onError(err); }
}

async function changePassword() {
  const current = prompt('הסיסמה הנוכחית:'); if (!current) return;
  const next = prompt('סיסמה חדשה (8+ תווים):'); if (!next) return;
  try { await api('change-password', { current, new: next }); toast('הסיסמה הוחלפה'); } catch (err) { onError(err); }
}

// ---------- מנהל: סוגי מוצרים ----------
async function showTypes() {
  const box = $('#types');
  box.classList.add('is-open');
  const body = $('#types-body');
  try {
    const r = await api('types-list');
    state.types = r.types.filter((t) => t.active);
    body.innerHTML = `
      <p class="muted">סוג מוצר = תבנית מהקוד + שם + ברירות מחדל משלו. "שמירת הערכים הנוכחיים" לוקחת את מה שבטופס של הפרויקט הפתוח כברירת המחדל של הסוג.</p>
      <form class="users__new" id="type-new">
        <input name="name" placeholder="שם הסוג (למשל: ספריית סלון)" required>
        <select name="template_key">${Object.values(TEMPLATES).map((t) => `<option value="${t.key}">${esc(t.name)}</option>`).join('')}</select>
        <input name="description" placeholder="תיאור קצר">
        <button type="submit" class="btn btn--accent">+ סוג מוצר</button>
      </form>
      <table class="list"><thead><tr><th>שם</th><th>תבנית</th><th>תיאור</th><th>ברירות מחדל</th><th>פעיל</th><th></th></tr></thead><tbody>
      ${r.types.map((t) => `<tr data-id="${t.id}">
        <td><input name="name" value="${esc(t.name)}" class="inline"></td>
        <td>${esc(template(t.template_key)?.name || t.template_key)}</td>
        <td><input name="description" value="${esc(t.description)}" class="inline"></td>
        <td class="muted">${Object.keys(t.defaults).length} ערכים</td>
        <td><input type="checkbox" name="active" ${t.active ? 'checked' : ''}></td>
        <td class="list__actions">
          <button type="button" class="btn btn--small" data-take="1" ${state.project && state.project.template_key === t.template_key ? '' : 'disabled'} title="לוקח את ערכי הפרויקט הפתוח">שמירת הערכים הנוכחיים</button>
          <button type="button" class="btn btn--small" data-del="1">מחיקה</button>
        </td></tr>`).join('')}</tbody></table>`;
    $('#type-new').onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      try { await api('type-save', { name: f.name.value, template_key: f.template_key.value, description: f.description.value, defaults: {} }); toast('סוג המוצר נוצר'); showTypes(); } catch (err) { onError(err); }
    };
    const save = async (tr, extra = {}) => {
      const t = r.types.find((x) => x.id === Number(tr.dataset.id));
      const patch = { id: t.id, template_key: t.template_key, name: tr.querySelector('[name=name]').value, description: tr.querySelector('[name=description]').value, active: tr.querySelector('[name=active]').checked, defaults: t.defaults, ...extra };
      await api('type-save', patch);
    };
    body.onchange = async (e) => { const tr = e.target.closest('tr[data-id]'); if (!tr) return; try { await save(tr); toast('עודכן'); } catch (err) { onError(err); } };
    body.onclick = async (e) => {
      const tr = e.target.closest('tr[data-id]'); if (!tr) return;
      try {
        if (e.target.dataset.take) { await save(tr, { defaults: model ? model.values : state.values }); toast('ברירות המחדל עודכנו מהפרויקט הפתוח'); showTypes(); }
        if (e.target.dataset.del && confirm('למחוק את סוג המוצר? פרויקטים קיימים נשארים.')) { await api('type-delete', { id: Number(tr.dataset.id) }); showTypes(); }
      } catch (err) { onError(err); }
    };
  } catch (err) { onError(err); }
}

// ---------- עזר ----------
function statusName(s) { return { draft: 'טיוטה', quoted: 'הצעת מחיר', approved: 'אושר', done: 'בוצע' }[s] || s; }
function fmtDate(iso) { try { return new Date(iso).toLocaleDateString('he-IL', { day: 'numeric', month: 'short' }); } catch { return iso; } }
function fmtTime(iso) { try { return new Date(iso).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' }); } catch { return ''; } }

// three.js נטען בדף לפני המודול; אם אף מקור לא נענה — הודעה במקום קנבס ריק.
window.__carpentry = { state, get viewer() { return viewer; }, get model() { return model; } };   // לבדיקות מהקונסול
if (window.THREE) boot();
else $('#stage-error').hidden = false;
