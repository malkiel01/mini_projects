// ענן החבילה — הדף: קבצים, סל וניהול. כל מה שבשרת — דרך api.php (האפיון: docs/spec-1-skeleton.md).

import { $, h as el } from './ui-core/dom.js';
import { toast } from './ui-core/toast.js';
import { initDialogs, confirm, prompt } from './ui-core/dialog.js';
import { dropZone, pickFiles } from './ui-core/files.js';
import { accountMenu, accountServer } from './ui-core/account.js';

initDialogs();   // [data-close], סגירה ברקע, --vvh
const API = 'api.php';
const CHUNK_HASH_MAX = 64 * 1024 * 1024;   // עד כאן — גיבוב בדפדפן (בלי העלאה חוזרת של תוכן קיים); מעל — השרת מחשב

const state = { me: null, quota: null, folder: null, path: [], items: [], current: null, move: null, admin: null };

// ─── עזרים ──────────────────────────────────────────────────────────────

class ApiError extends Error {
  constructor(body, status) { super(body.error || 'תקלה'); this.code = body.code; this.status = status; this.body = body; }
}

async function api(action, body, opts = {}) {
  const init = body === undefined ? { method: 'GET' } : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
  const res = await fetch(`${API}?action=${action}${opts.query || ''}`, { credentials: 'same-origin', ...init, ...opts.init });
  const data = await res.json().catch(() => ({ error: 'תשובה לא תקינה מהשרת' }));
  if (!res.ok) throw new ApiError(data, res.status);
  return data;
}

function bytes(n) {
  if (n == null) return '';
  const u = [['ג"ב', 2 ** 30], ['מ"ב', 2 ** 20], ['ק"ב', 2 ** 10]];
  for (const [name, s] of u) if (n >= s) return `${+(n / s).toFixed(n / s < 10 ? 1 : 0)} ${name}`;
  return `${n} בתים`;
}

function when(iso) {
  const d = new Date(iso), now = new Date();
  const same = d.toDateString() === now.toDateString();
  return same ? d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: d.getFullYear() === now.getFullYear() ? undefined : '2-digit' });
}

function icon(n) {
  if (n.kind === 'folder') return '📁';
  const m = n.mime || '', ext = n.name.split('.').pop().toLowerCase();
  if (ext === 'mbe') return '🧩';
  if (m.startsWith('image/')) return '🖼️';
  if (m === 'application/pdf') return '📕';
  if (m.startsWith('font/')) return '🔤';
  if (m.startsWith('video/')) return '🎬';
  if (m.startsWith('audio/')) return '🎵';
  if (m.startsWith('text/') || ext === 'docx') return '📄';
  return '📦';
}

function fail(e) { console.warn(e); toast(e.message || 'תקלה', true); }

// ─── ניווט ──────────────────────────────────────────────────────────────
// #/files/<folder?> · #/trash · #/admin. חזרה — למסך הקודם באפליקציה; בראשי אין חץ.

function route() {
  const [, tab = 'files', arg = ''] = location.hash.split('/');
  const t = ['files', 'fonts', 'trash', 'links', 'admin'].includes(tab) && (tab !== 'admin' || state.me?.role === 'admin') ? tab : 'files';
  document.querySelectorAll('.tabs a').forEach(a => { if (a.dataset.tab === t) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
  for (const v of ['files', 'fonts', 'trash', 'links', 'admin']) $(`#v-${v}`).hidden = v !== t;
  $('#back').hidden = !(t === 'files' && arg);
  if (t === 'files') loadFolder(arg || null).catch(fail);
  if (t === 'trash') loadTrash().catch(fail);
  if (t === 'fonts') loadFonts().catch(fail);
  if (t === 'links') loadLinks().catch(fail);
  if (t === 'admin') loadAdmin().catch(fail);
}

$('#back').addEventListener('click', () => {
  const up = state.path.length > 1 ? state.path[state.path.length - 2].id : '';
  location.hash = up ? `#/files/${up}` : '#/files';
});

// ─── מכסה ───────────────────────────────────────────────────────────────

function renderQuota() {
  const q = state.quota; if (!q) return;
  const pct = q.quota > 0 ? Math.min(100, Math.round(q.used / q.quota * 100)) : 0;
  $('#q-text').textContent = q.quota > 0 ? `${bytes(q.used)} מתוך ${bytes(q.quota)}` : 'בלי נפח';
  $('#q-pct').textContent = q.quota > 0 ? `${pct}%` : '';
  const tr = $('#q-track'); tr.setAttribute('aria-valuenow', pct); tr.firstElementChild.style.inlineSize = pct + '%';
  tr.classList.toggle('warn', pct >= 80 && pct < 95); tr.classList.toggle('bad', pct >= 95);
  $('#q-none').hidden = q.quota > 0;
  $('#upload-btn').disabled = q.quota <= 0;
  $('#sub').textContent = state.me ? `${state.me.name} · ${q.quota > 0 ? `פנוי ${bytes(Math.max(0, q.quota - q.used))}` : 'בלי נפח'}` : '';
}

async function refreshQuota() { state.quota = await api('quota'); renderQuota(); }

// ─── קבצים ──────────────────────────────────────────────────────────────

async function loadFolder(id) {
  const data = await api('list', undefined, { query: id ? `&folder=${encodeURIComponent(id)}` : '' }).catch(e => {
    if (e.code === 'not_found' && id) { location.hash = '#/files'; return null; } throw e;
  });
  if (!data) return;
  Object.assign(state, { folder: data.folder, path: data.path, items: data.items });
  $('#title').textContent = data.path.length ? data.path[data.path.length - 1].name : 'ענן החבילה';
  renderCrumbs($('#crumbs'), data.path, f => { location.hash = f ? `#/files/${f}` : '#/files'; });
  $('#crumbs').hidden = !data.path.length;   // בשורש — כרטיס "הענן שלי" כבר אומר איפה אנחנו
  renderItems();
}

function renderCrumbs(box, path, go) {
  box.replaceChildren(
    el('button', { type: 'button', 'aria-current': path.length ? false : 'page', onclick: () => go(null) }, '☁️ הענן שלי'),
    ...path.flatMap((p, i) => [el('span', { class: 'gt', 'aria-hidden': 'true' }, '‹'),
      el('button', { type: 'button', 'aria-current': i === path.length - 1 ? 'page' : false, onclick: () => go(p.id) }, p.name)]),
  );
}

function row(n, onclick, extra) {
  const sub = n.kind === 'folder' ? 'תיקייה' : bytes(n.size);
  const by = n.updated_by?.app ? n.updated_by.app : '';
  return el('li', {}, el('button', { class: 'use-row', type: 'button', onclick },
    el('span', { class: 'use-ic' + (n.kind === 'folder' ? ' folder' : ''), 'aria-hidden': 'true' }, icon(n)),
    el('span', { class: 'use-tx' }, el('b', { class: 'one' }, n.name),
      el('small', {}, [sub, when(n.trashed_at || n.updated_at), by].filter(Boolean).join(' · '))),
    extra || el('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
}

function renderItems() {
  const ul = $('#items');
  if (!state.items.length) {
    ul.replaceChildren(el('li', {}, el('p', { class: 'empty' }, state.quota?.quota > 0 ? 'התיקייה ריקה. "⬆️ העלאה" או גרירה לכאן.' : 'התיקייה ריקה.')));
    return;
  }
  ul.replaceChildren(...state.items.map(n => row(n, () => n.kind === 'folder' ? (location.hash = `#/files/${n.id}`) : openItem(n))));
}

// ─── פריט ───────────────────────────────────────────────────────────────

function openItem(n) {
  state.current = n;
  $('#item-title').textContent = n.name;
  const pv = $('#item-preview');
  pv.replaceChildren(/^image\/(png|jpeg|gif|webp)$/.test(n.mime)
    ? el('img', { src: `${API}?action=read&id=${encodeURIComponent(n.id)}`, alt: n.name })
    : el('span', { class: 'big', 'aria-hidden': 'true' }, icon(n)));
  $('#item-cells').replaceChildren(...[
    ['גודל', bytes(n.size)], ['עודכן', `${when(n.updated_at)}${n.updated_by?.name ? ' · ' + n.updated_by.name : ''}`],
    ['מאפליקציה', n.updated_by?.app || 'הענן'], ['גרסה', String(n.rev)],
  ].map(([k, v]) => el('div', {}, el('dt', {}, k), el('dd', { class: 'one' }, v))));
  $('#item-dlg').showModal();
}

$('#item-dlg').addEventListener('click', async e => {
  const b = e.target.closest('[data-act]'); if (!b) return;
  const n = state.current;
  if (b.dataset.act === 'download') {
    const a = el('a', { href: `${API}?action=read&id=${encodeURIComponent(n.id)}`, download: n.name }); a.click(); return;
  }
  $('#item-dlg').close();
  if (b.dataset.act === 'rename') askName('שינוי שם', n.name, name => api('rename', { id: n.id, name }));
  if (b.dataset.act === 'move') openMove([n]);
  if (b.dataset.act === 'trash') {
    try { await api('trash', { id: n.id }); toast('הועבר לסל'); loadFolder(state.folder); } catch (err) { fail(err); }
  }
});

// ─── שם (תיקייה חדשה / שינוי שם) ───────────────────────────────────────

function askName(title, value, save) {
  const dlg = $('#name-dlg'), f = $('form', dlg), err = $('.error', dlg);
  $('#name-title').textContent = title; f.name.value = value; err.hidden = true;
  f.onsubmit = async e => {
    e.preventDefault();
    try { await save(f.name.value.trim()); dlg.close(); loadFolder(state.folder); }
    catch (x) { err.textContent = x.message; err.hidden = false; }
  };
  dlg.showModal();
  const dot = value.lastIndexOf('.');
  f.name.focus(); f.name.setSelectionRange(0, dot > 0 ? dot : value.length);
}

$('#new-folder').addEventListener('click', () => askName('תיקייה חדשה', '', name => api('mkdir', { parent: state.folder, name, on_conflict: 'fail' })));

// ─── העברה ──────────────────────────────────────────────────────────────

async function openMove(nodes) {
  state.move = { nodes, folder: state.folder };
  await loadMoveFolder(state.folder);
  const dlg = $('#move-dlg');
  $('form', dlg).onsubmit = async e => {
    e.preventDefault();
    try {
      for (const n of nodes) await api('move', { id: n.id, parent: state.move.folder });
      dlg.close(); toast('הועבר'); loadFolder(state.folder);
    } catch (x) { fail(x); }
  };
  dlg.showModal();
}

async function loadMoveFolder(id) {
  const data = await api('list', undefined, { query: id ? `&folder=${encodeURIComponent(id)}` : '' });
  state.move.folder = data.folder;
  const moving = new Set(state.move.nodes.map(n => n.id));
  renderCrumbs($('#move-crumbs'), data.path, f => loadMoveFolder(f).catch(fail));
  const folders = data.items.filter(n => n.kind === 'folder' && !moving.has(n.id));
  $('#move-items').replaceChildren(...(folders.length ? folders.map(n => row(n, () => loadMoveFolder(n.id).catch(fail)))
    : [el('li', {}, el('p', { class: 'empty' }, 'אין כאן תיקיות. "לכאן" — להעביר לתיקייה הזו.'))]));
}

// ─── העלאה ──────────────────────────────────────────────────────────────
// בחלקים (8MB): התקדמות אמיתית, ונסיון חוזר לחלק שנכשל. תוכן שכבר בענן — לא עולה שוב.

$('#upload-btn').addEventListener('click', () => $('#file-input').click());
$('#file-input').addEventListener('change', e => { uploadAll([...e.target.files]); e.target.value = ''; });

dropZone($('#v-files'), { onFiles: f => state.quota?.quota > 0 ? uploadAll(f) : toast('עוד לא הוקצה לך מקום בענן', true) });

async function uploadAll(files) {
  const folder = state.folder;
  for (const f of files) {
    try { await uploadOne(f, folder); } catch (e) { fail(e); }
  }
  await refreshQuota().catch(() => {});
  if (state.folder === folder) loadFolder(folder).catch(fail);
}

async function sha256(file) {
  if (file.size > CHUNK_HASH_MAX || !crypto.subtle) return undefined;
  const h = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
  return [...new Uint8Array(h)].map(b => b.toString(16).padStart(2, '0')).join('');
}

async function uploadOne(file, folder) {
  const box = el('div', { class: 'upload' });
  const label = el('span', { class: 'muted' }, 'מתכונן…');
  const bar = el('span', {});
  box.append(el('div', { class: 'line' }, el('b', {}, file.name), label),
    el('span', { class: 'track', role: 'progressbar', 'aria-label': file.name, 'aria-valuemin': 0, 'aria-valuemax': 100 }, bar));
  $('#uploads').append(box);
  const started = Date.now();
  const show = got => {
    const pct = file.size ? Math.round(got / file.size * 100) : 100;
    bar.style.inlineSize = pct + '%'; bar.parentElement.setAttribute('aria-valuenow', pct);
    const rate = got / Math.max(1, (Date.now() - started) / 1000);
    const left = rate > 0 ? Math.round((file.size - got) / rate) : 0;
    label.textContent = `${bytes(got)} מתוך ${bytes(file.size)}${left > 3 ? ` · נשארו ~${left < 90 ? left + ' שנ׳' : Math.round(left / 60) + ' דק׳'}` : ''}`;
  };
  try {
    const start = await api('upload_start', { parent: folder, name: file.name, size: file.size, sha256: await sha256(file), mime: file.type || undefined });
    let got = start.received;
    show(got);
    for (let i = Math.floor(got / start.chunk); got < file.size; i++) {
      const part = file.slice(i * start.chunk, (i + 1) * start.chunk);
      let tries = 0;
      for (;;) {
        try {
          const r = await api('upload_chunk', undefined, { query: `&upload=${start.upload}&index=${i}`,
            init: { method: 'PUT', headers: { 'Content-Type': 'application/octet-stream' }, body: part } });
          got = r.received; break;
        } catch (e) {
          if (e instanceof ApiError && e.status < 500 && e.status !== 0) throw e;
          if (++tries > 4) throw new Error('ההעלאה נקטעה — בדקו את החיבור ונסו שוב');
          label.textContent = 'החיבור נקטע — מנסה שוב…';
          await new Promise(r => setTimeout(r, 1500 * tries));
        }
      }
      show(got);
    }
    const node = await api('upload_finish', { upload: start.upload });
    label.textContent = node.name === file.name ? 'הועלה' : `הועלה בשם "${node.name}"`;
    setTimeout(() => box.remove(), 1500);
  } catch (e) {
    box.remove();
    throw e;
  }
}

// ─── סל ─────────────────────────────────────────────────────────────────

async function loadTrash() {
  $('#title').textContent = 'סל';
  const data = await api('trash_list');
  $('#empty-trash').disabled = !data.items.length;
  $('#trash-items').replaceChildren(...(data.items.length ? data.items.map(n => row(n, () => restore(n),
    el('span', { class: 'chip' }, '↩️ שחזור'))) : [el('li', {}, el('p', { class: 'empty' }, 'הסל ריק.'))]));
}

async function restore(n) {
  try { const r = await api('restore', { id: n.id }); toast(`"${r.name}" שוחזר`); loadTrash(); } catch (e) { fail(e); }
}

$('#empty-trash').addEventListener('click', async () => {
  if (!await confirm('למחוק לצמיתות את כל מה שבסל? אי אפשר לבטל.', { ok: 'מחיקה', danger: true })) return;
  try { const r = await api('trash_empty', {}); toast(`נמחקו ${r.removed}`); loadTrash(); refreshQuota(); } catch (e) { fail(e); }
});

// ─── גופנים ─────────────────────────────────────────────────────────────

const WEIGHT_NAMES = { 100: 'דק מאוד', 200: 'דק', 300: 'קל', 400: 'רגיל', 500: 'בינוני', 600: 'חצי מודגש', 700: 'מודגש', 800: 'מודגש מאוד', 900: 'שחור' };
const SAMPLE = 'אבג דהו זחט · Aa Bb 123';
const loadedFaces = new Set();

async function loadFonts() {
  $('#title').textContent = 'גופנים';
  const data = await api('fonts_list', undefined, { query: '&builtin=1' });
  const byId = Object.fromEntries(data.fonts.map(f => [f.id, f]));
  const mine = data.families.filter(f => f.source !== 'builtin'), builtin = data.families.filter(f => f.source === 'builtin');
  const mineFiles = data.fonts.filter(f => f.source !== 'builtin').length;
  $('#fonts-count').textContent = mine.length ? `${mine.length} משפחות · ${mineFiles} קבצים` : '';
  const famLi = fam => {
    const items = fam.items.map(id => byId[id]);
    const main = items.find(f => f.weight === 400 && f.style === 'normal') || items[0];
    const sample = el('div', { class: 'sample', style: { fontFamily: `"cf-${main.id}", system-ui`, fontWeight: main.weight, fontStyle: main.style } }, SAMPLE);
    face(main);
    return el('li', {}, el('div', { class: 'font-fam' },
      el('div', { class: 'l1 one' }, fam.family), sample,
      ...items.map(f => el('div', { class: 'font-row' },
        el('span', { class: 'l2' }, `${f.axes?.wght ? `משתנה · \u2066${f.axes.wght[0]}–${f.axes.wght[1]}\u2069` : WEIGHT_NAMES[f.weight] || f.weight}${f.style === 'italic' ? ' · נטוי' : ''} · ${f.format} · ${bytes(f.size)}`),
        ...(f.source === 'builtin'
          ? [el('button', { class: 'icon-btn', type: 'button', 'aria-label': `הרישיון של ${f.name}`, onclick: () => showLicense(f) }, '📄')]
          : [el('button', { class: 'icon-btn', type: 'button', 'aria-label': `עריכת ${f.name}`, onclick: () => editFont(f) }, '✏️'),
             el('button', { class: 'icon-btn', type: 'button', 'aria-label': `מחיקת ${f.name}`, onclick: () => deleteFont(f) }, '🗑️')])))));
  };
  $('#fonts').replaceChildren(...(mine.length ? mine.map(famLi) : [el('li', {}, el('p', { class: 'empty' }, 'עוד אין גופנים שלך. "⬆️ גופן" כדי להעלות.'))]));
  $('#fonts-builtin').replaceChildren(...builtin.map(famLi));
}

function showLicense(f) {
  window.open(`${API}?action=font_license&id=${encodeURIComponent(f.id)}`, '_blank', 'noopener');
}

/** טוען את הגופן לדף בשם cf-<id> — לתצוגה המקדימה. */
async function face(f) {
  if (loadedFaces.has(f.id)) return;
  loadedFaces.add(f.id);
  try {
    const buf = await (await fetch(f.url, { credentials: 'same-origin' })).arrayBuffer();
    const w = f.axes?.wght;
    const ff = new FontFace(`cf-${f.id}`, buf, { weight: w ? `${w[0]} ${w[1]}` : String(f.weight), style: f.style });
    document.fonts.add(await ff.load());
  } catch (e) { console.warn('font preview', e); }
}

$('#font-upload').addEventListener('click', async () => {
  const files = await pickFiles({ accept: '.ttf,.otf,.woff,.woff2', multiple: true });
  if (!files?.length) return;
  if (!await confirm('הגופנים ייכנסו לספרייה שלך ויוטמעו בקבצים שתשמור. ודא שהרישיון שלהם מתיר הטמעה — האחריות עליך.',
    { title: 'רישיון הגופנים', ok: 'מאשר, להעלות' })) return;
  let ok = 0;
  for (const file of files) {
    const fd = new FormData();
    fd.append('file', file, file.name);
    fd.append('license_ack', '1');
    try {
      const res = await fetch(`${API}?action=font_add`, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Cloud-Request': '1' } });
      const j = await res.json();
      if (!res.ok) throw new ApiError(j, res.status);
      ok++;
    } catch (e) { toast(`${file.name}: ${e.message}`, true); }
  }
  if (ok) toast(ok === 1 ? 'הגופן נוסף' : `נוספו ${ok} גופנים`);
  refreshQuota().catch(() => {});
  loadFonts().catch(fail);
});

async function editFont(f) {
  const family = await prompt('שם המשפחה', { title: '✏️ ' + f.name, value: f.family, ok: 'שמירה' });
  if (family === null || family === f.family) return;
  try { await api('font_update', { id: f.id, family }); toast('נשמר'); loadFonts(); } catch (e) { fail(e); }
}

async function deleteFont(f) {
  if (!await confirm(`למחוק את ${f.name}? קבצים שכבר הטמיעו אותו לא נפגעים.`, { ok: 'מחיקה', danger: true })) return;
  try { await api('font_delete', { id: f.id }); toast('נמחק'); refreshQuota().catch(() => {}); loadFonts(); } catch (e) { fail(e); }
}

// ─── חיבורים ────────────────────────────────────────────────────────────

const SCOPE_IC = { files: '📁', libs: '🔤', ai: '🤖' };

async function loadLinks() {
  $('#title').textContent = 'חיבורים';
  const data = await api('connections');
  $('#links').replaceChildren(...(data.items.length ? data.items.map(c => el('li', {}, el('div', { class: 'acc-row' },
    el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🧩'),
    el('div', { class: 'body' }, el('div', { class: 'l1' }, c.name),
      el('div', { class: 'l2' }, [c.scope.map(s => SCOPE_IC[s] || s).join(' '), `מאז ${when(c.created_at)}`,
        c.used_at ? `שימוש אחרון ${when(c.used_at)}` : 'עוד לא בשימוש'].join(' · '))),
    el('button', { class: 'btn ghost sm danger', type: 'button', onclick: () => unlink(c) }, 'ניתוק'))))
    : [el('li', {}, el('p', { class: 'empty' }, 'אין אפליקציות מחוברות. באפליקציה של החבילה: "☁️ חיבור לענן החבילה".'))]));
}

async function unlink(c) {
  if (!await confirm(`לנתק את ${c.name}? היא תפסיק לגשת לענן מיד. הקבצים נשארים.`, { ok: 'ניתוק', danger: true })) return;
  try { await api('connection_revoke', { id: c.id }); toast('נותק'); loadLinks(); } catch (e) { fail(e); }
}

// ─── ניהול ──────────────────────────────────────────────────────────────

const MB = 2 ** 20;

async function loadAdmin() {
  $('#title').textContent = 'ניהול הענן';
  renderAdmin(await api('admin'));
}

function renderAdmin(s) {
  state.admin = s;
  const totalQuota = s.accounts.reduce((t, a) => t + a.effective_quota, 0);
  $('#admin-cells').replaceChildren(...[
    ['חשבונות', String(s.accounts.length)], ['בדיסק', bytes(s.disk)], ['הוקצה', bytes(totalQuota)],
  ].map(([k, v]) => el('div', {}, el('dt', {}, k), el('dd', {}, v))));
  const f = $('#settings-form');
  f.default_quota.value = Math.round(s.settings.default_quota / MB);
  f.max_file.value = Math.round(s.settings.max_file / MB);
  $('#accounts').replaceChildren(...s.accounts.map(a => el('li', {}, el('button', { class: 'use-row', type: 'button', onclick: () => openQuota(a) },
    el('span', { class: 'use-ic', 'aria-hidden': 'true' }, a.role === 'admin' ? '🛡️' : '👤'),
    el('span', { class: 'use-tx' }, el('b', { class: 'one' }, a.name),
      el('small', {}, `${bytes(a.used)} מתוך ${a.effective_quota ? bytes(a.effective_quota) : '0'}${a.quota === null ? ' (ברירת מחדל)' : ''} · ${a.email}`)),
    a.blocked ? el('span', { class: 'chip bad' }, 'חסום') : el('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))));
  renderClients(s.clients || []);
  loadAi().catch(fail);
  api('admin_drives', {}).then(renderDrives).catch(fail);
}

// ── כוננים חיצוניים ──

const REDIRECT_DROPBOX = new URL('oauth/dropbox.php', location.href).href;

function renderDrives(cfg) {
  const d = cfg.dropbox;
  $('#ext-drives').replaceChildren(el('li', {}, el('div', { class: 'acc-row' },
    el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📦'),
    el('div', { class: 'body' }, el('div', { class: 'l1' }, 'Dropbox'),
      el('div', { class: 'l2', dir: 'ltr' }, `Redirect URI: ${REDIRECT_DROPBOX}`)),
    d ? el('span', { class: 'chip ok', dir: 'ltr' }, d.app_key) : el('span', { class: 'chip' }, 'לא מוגדר'),
    el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'App key של Dropbox', onclick: () => setDropboxKey(d?.app_key || '') }, '🔑'))));
}

async function setDropboxKey(cur) {
  const key = await prompt('App key של אפליקציית Dropbox (ריק — הסרה). ‏App secret לא צריך.', { title: '📦 Dropbox', required: false, dir: 'ltr', ok: 'שמירה', value: cur });
  if (key === null) return;
  try { renderDrives(await api('admin_drives', { dropbox_app_key: key.trim() })); toast(key.trim() ? 'נשמר — Dropbox יופיע באפליקציות תוך כמה דקות' : 'Dropbox הוסר'); } catch (e) { fail(e); }
}

// ── בינה מלאכותית ──

const TASK_NAMES = { 'remove-background': 'הסרת רקע', segment: 'פירוק לעצמים', 'generate-image': 'יצירת תמונה', inpaint: 'מילוי אזור', complete: 'טקסט / תוכנית' };

async function loadAi() { renderAi(await api('ai_admin')); }

function renderAi(s) {
  state.ai = s;
  const u = s.usage;
  $('#ai-usage').replaceChildren(el('div', { class: 'line' }, el('span', { class: 'muted' }, 'החודש'), el('b', {}, `$${u.cost.toFixed(2)}`),
    el('span', { class: 'muted' }, `· ${u.calls} קריאות`)));
  $('#ai-providers').replaceChildren(...s.providers.map(p => el('li', {}, el('div', { class: 'acc-row' },
    el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🤖'),
    el('div', { class: 'body' }, el('div', { class: 'l1' }, p.name), el('div', { class: 'l2' }, p.tasks.map(t => TASK_NAMES[t]).join(' · '))),
    p.has_key ? el('span', { class: `chip ${p.enabled ? 'ok' : 'bad'}`, dir: p.enabled ? 'ltr' : null }, p.enabled ? `••${p.key_tail}` : 'כבוי') : el('span', { class: 'chip' }, 'אין מפתח'),
    el('button', { class: 'icon-btn', type: 'button', 'aria-label': `מפתח ל-${p.name}`, onclick: () => setKey(p) }, '🔑'),
    p.has_key ? el('button', { class: 'btn ghost sm', type: 'button', onclick: () => testKey(p) }, 'בדיקה') : null,
    p.has_key ? el('button', { class: 'btn ghost sm', type: 'button', onclick: () => toggleProvider(p) }, p.enabled ? 'כיבוי' : 'הדלקה') : null))));
  $('#ai-defaults').replaceChildren(...s.tasks.map(t => {
    const opts = s.providers.filter(p => p.tasks.includes(t));
    const cur = s.defaults[t] || opts[0]?.id;
    return el('label', { class: 'f' }, el('span', {}, TASK_NAMES[t]),
      el('select', { onchange: e => setDefault(t, e.target.value) }, ...opts.map(p => el('option', { value: p.id, selected: p.id === cur }, p.name))));
  }));
}

async function setKey(p) {
  const key = await prompt(`מפתח API של ${p.name} (ריק — מחיקה)`, { title: `🔑 ${p.name}`, required: false, dir: 'ltr', ok: 'שמירה' });
  if (key === null) return;
  try { renderAi(await api('ai_provider', { provider: p.id, key })); toast(key ? 'נשמר — מוצפן בשרת' : 'המפתח נמחק'); } catch (e) { fail(e); }
}

async function testKey(p) {
  try { await api('ai_test', { provider: p.id }); toast(`${p.name}: המפתח תקין ✓`); } catch (e) { fail(e); }
}

async function toggleProvider(p) {
  try { renderAi(await api('ai_provider', { provider: p.id, enabled: !p.enabled })); } catch (e) { fail(e); }
}

async function setDefault(task, provider) {
  try { renderAi(await api('ai_default', { task, provider })); toast('נשמר'); } catch (e) { fail(e); }
}

function renderClients(list) {
  const label = { active: ['פעילה', 'ok'], pending: ['ממתינה', 'warn'], disabled: ['כבויה', 'bad'] };
  $('#clients').replaceChildren(...(list.length ? list.map(c => el('li', {}, el('div', { class: 'acc-row' },
    el('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🧩'),
    el('div', { class: 'body' }, el('div', { class: 'l1' }, c.name),
      el('div', { class: 'l2' }, `${c.origins.join(' ')} · ${c.users} מחוברים`)),
    el('span', { class: `chip ${label[c.status][1]}` }, label[c.status][0]),
    el('button', { class: 'btn ghost sm', type: 'button', onclick: () => setClient(c, c.status === 'active' ? 'disabled' : 'active') },
      c.status === 'active' ? 'כיבוי' : 'אישור'))))
    : [el('li', {}, el('p', { class: 'empty' }, 'עוד אין אפליקציות רשומות.'))]));
}

async function setClient(c, status) {
  if (status === 'disabled' && !await confirm(`לכבות את ${c.name}? כל ${c.users} החיבורים שלה ייעצרו.`, { ok: 'כיבוי', danger: true })) return;
  try { renderClients((await api('admin_client', { client: c.id, status })).clients); toast('נשמר'); } catch (e) { fail(e); }
}

$('#settings-form').addEventListener('submit', async e => {
  e.preventDefault();
  const f = e.target, num = v => Math.round(Number(String(v).trim()) * MB);
  const dq = num(f.default_quota.value), mf = num(f.max_file.value);
  if (!Number.isFinite(dq) || !Number.isFinite(mf) || dq < 0 || mf < MB) { toast('ערכים לא תקינים', true); return; }
  try { renderAdmin(await api('admin_settings', { default_quota: dq, max_file: mf })); toast('נשמר'); refreshQuota(); } catch (x) { fail(x); }
});

function openQuota(a) {
  const dlg = $('#quota-dlg'), f = $('form', dlg), err = $('.error', dlg);
  $('#quota-title').textContent = a.name;
  $('#quota-who').textContent = `${a.email} · בשימוש ${bytes(a.used)}`;
  const presets = { 0: '0', 1024: '1024', 5120: '5120', 20480: '20480' };
  const mb = a.quota === null ? null : Math.round(a.quota / MB);
  const pick = a.quota === null ? 'default' : (presets[mb] ?? 'custom');
  f.preset.value = pick; f.mb.value = mb ?? '';
  $('#quota-custom').hidden = pick !== 'custom';
  f.admin.checked = a.role === 'admin'; f.blocked.checked = a.blocked;
  const self = a.id === state.me.id;
  f.admin.disabled = self; f.blocked.disabled = self;
  const ai = a.ai || { providers: [], monthly: 0, spent: 0 };
  $('#ai-spent').textContent = `החודש $${(ai.spent || 0).toFixed(2)}`;
  $('#ai-allowed').replaceChildren(...(state.ai?.providers || []).map(p => el('label', {},
    el('input', { type: 'checkbox', value: p.id, checked: ai.providers.includes(p.id) }), el('span', {}, p.name))));
  f.ai_monthly.value = ai.monthly || '';
  err.hidden = true;
  f.onchange = () => { $('#quota-custom').hidden = f.preset.value !== 'custom'; };
  f.onsubmit = async e => {
    e.preventDefault();
    const p = f.preset.value;
    const quota = p === 'default' ? null : Math.round(Number(p === 'custom' ? f.mb.value : p) * MB);
    if (quota !== null && (!Number.isFinite(quota) || quota < 0)) { err.textContent = 'נפח לא תקין'; err.hidden = false; return; }
    const body = { account: a.id, quota };
    if (!self) Object.assign(body, { role: f.admin.checked ? 'admin' : 'user', blocked: f.blocked.checked });
    const monthly = Number(String(f.ai_monthly.value || '0').trim());
    if (!Number.isFinite(monthly) || monthly < 0) { err.textContent = 'מכסה לא תקינה'; err.hidden = false; return; }
    const providers = [...$('#ai-allowed').querySelectorAll('input:checked')].map(i => i.value);
    try {
      await api('ai_user', { account: a.id, providers, monthly });
      renderAdmin(await api('admin_account', body)); dlg.close(); toast('נשמר'); if (self) refreshQuota();
    }
    catch (x) { err.textContent = x.message; err.hidden = false; }
  };
  dlg.showModal();
}

// ─── 👤 תפריט המשתמש (ui-core, שורה 19) ─────────────────────────────────

async function initAccount() {
  let server = null;
  try { server = await accountServer((action, body) => api(action, body)); } catch { /* בלי שרת — ההגדרות במכשיר */ }
  const go = tab => () => { location.hash = `#/${tab}`; };
  accountMenu($('header.top'), {
    user: () => state.me && { name: state.me.name, email: state.me.email, admin: state.me.role === 'admin' },
    server,
    docs: go('files'),                     // 📁 "המסמכים שלי" — הכונן עצמו (הענן הוא המקור של suiteSource באפליקציות)
    admin: state.me?.role === 'admin' ? go('admin') : undefined,
    help: () => window.open('https://github.com/malkiel01/mini_projects/tree/main/suite-cloud#readme', '_blank', 'noopener'),
    hide: ['units', 'autosave', 'font', 'cloudSync'],   // אין להן משמעות בדף של הענן
  });
}

// ─── התחלה ──────────────────────────────────────────────────────────────

(async () => {
  try {
    const me = await api('me');
    state.me = me.account; state.quota = me.quota;
    $('.tabs a[data-tab="admin"]').hidden = me.account.role !== 'admin';
    renderQuota();
    initAccount().catch(e => console.warn('account menu', e));
    window.addEventListener('hashchange', route);
    route();
  } catch (e) {
    const box = $('#fatal');
    box.textContent = e.status === 401 ? 'נדרשת כניסה דרך הכלים הדיגיטליים.' : e.message;
    box.hidden = false;
  }
})();
