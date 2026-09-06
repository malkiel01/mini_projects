/**
 * השלמות לשיפוץ — ניהול רשימת הפינישים שנשארו.
 *
 * לכל השלמה: תמונות/סרטונים, תיאור קצר, תיאור מפורט, אחראי ומעקב ביצוע.
 * הכול נשמר בדפדפן (IndexedDB); סנכרון מול שרת הוא תוספת אופציונלית.
 */

import * as db from './db.js';
import * as mediaLib from './media.js';
import * as sync from './sync.js';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const STATUS_LABEL = { open: 'פתוח', progress: 'בתהליך', done: 'בוצע' };

const DEFAULT_SETTINGS = {
  projectName: '',
  compress: true,
  serverUrl: '',
  token: '',
  autoSync: false,
};

const state = {
  settings: { ...DEFAULT_SETTINGS },
  tasks: [],
  covers: new Map(),
  counts: new Map(),
  filters: { status: 'all', assignee: '', location: '', search: '' },
  sort: 'created-desc',
};

/** כתובות blob של הרשימה — משוחררות בכל רינדור כדי שלא ידלוף זיכרון. */
const listUrls = mediaLib.urlPool();
const editorUrls = mediaLib.urlPool();
const viewerUrls = mediaLib.urlPool();

/** מצב העורך הפתוח. המדיה נאספת כאן ונכתבת ל-DB רק בשמירה. */
let editor = null;

/* ── עזרים ─────────────────────────────────────────────────────── */

/** בונה אלמנט. `text` נכתב כ-textContent, ולכן טקסט מהמשתמש בטוח כאן. */
function el(tag, props = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(props)) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'text') node.textContent = value;
    else if (key === 'class') node.className = value;
    else if (key === 'dataset') Object.assign(node.dataset, value);
    else if (key.startsWith('on')) node.addEventListener(key.slice(2).toLowerCase(), value);
    else node.setAttribute(key, value === true ? '' : value);
  }
  for (const child of [].concat(children)) {
    if (child) node.append(child);
  }
  return node;
}

const MAX_TOASTS = 3;

function toast(message, kind = '') {
  const host = $('#toasts');
  // עריכות מהירות ברצף מייצרות הודעות מהר יותר משהן נעלמות; בלי תקרה
  // הן מכסות את הרשימה.
  while (host.children.length >= MAX_TOASTS) host.firstElementChild.remove();

  const node = el('div', { class: `toast${kind ? ` toast--${kind}` : ''}`, text: message });
  host.append(node);
  setTimeout(() => node.remove(), kind === 'error' ? 5200 : 2800);
}

const todayISO = () => new Date().toISOString().slice(0, 10);

function formatDate(iso) {
  if (!iso) return '';
  const date = new Date(`${iso}T00:00:00`);
  if (Number.isNaN(date.getTime())) return iso;
  return date.toLocaleDateString('he-IL', { day: 'numeric', month: 'short' });
}

/** מאשר פעולה הרסנית. confirm חסום בחלק מהדפדפנים בתוך dialog — אבל לא ב־<dialog> פתוח. */
const confirmAction = (message) => window.confirm(message);

/* ── הגדרות ────────────────────────────────────────────────────── */

async function loadSettings() {
  const stored = await db.getMeta('settings', {});
  state.settings = { ...DEFAULT_SETTINGS, ...stored };
  applySettings();
}

async function saveSettings(patch) {
  state.settings = { ...state.settings, ...patch };
  await db.setMeta('settings', state.settings);
  applySettings();
}

function applySettings() {
  const { projectName, serverUrl } = state.settings;
  $('#projectName').textContent = projectName || 'רשימת הפינישים שנשארו';
  document.title = projectName ? `השלמות — ${projectName}` : 'השלמות לשיפוץ';
  $('#syncBtn').hidden = !serverUrl;
}

const syncConfig = () => ({
  url: sync.normalizeUrl(state.settings.serverUrl),
  token: state.settings.token,
});

/* ── טעינה ורינדור ─────────────────────────────────────────────── */

async function refresh() {
  state.tasks = await db.listTasks();
  const { covers, counts } = await db.coverMap();
  state.covers = covers;
  state.counts = counts;
  renderFilters();
  renderList();
  renderProgress();
}

/** ערכים ייחודיים ממוינים לשדה מסוים, לשימוש בסינון וב-datalist. */
function uniqueValues(field) {
  return [...new Set(state.tasks.map((t) => t[field]).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'he'));
}

function renderFilters() {
  for (const [field, select, allLabel] of [
    ['assignee', $('#filterAssignee'), 'כולם'],
    ['location', $('#filterLocation'), 'הכול'],
  ]) {
    const values = uniqueValues(field);
    // שמירת הבחירה גם אם הערך נעלם מהרשימה, כדי שהסינון לא יתאפס לבד.
    const current = state.filters[field];
    select.replaceChildren(
      el('option', { value: '', text: allLabel }),
      ...values.map((value) => el('option', { value, text: value })),
    );
    if (current && !values.includes(current)) {
      select.append(el('option', { value: current, text: current }));
    }
    select.value = current;
  }

  $('#assigneeList').replaceChildren(
    ...uniqueValues('assignee').map((value) => el('option', { value })));
  $('#locationList').replaceChildren(
    ...uniqueValues('location').map((value) => el('option', { value })));
}

function visibleTasks() {
  const { status, assignee, location, search } = state.filters;
  const needle = search.trim().toLowerCase();

  const matches = state.tasks.filter((task) => {
    if (status !== 'all' && task.status !== status) return false;
    if (assignee && task.assignee !== assignee) return false;
    if (location && task.location !== location) return false;
    if (!needle) return true;
    return [task.title, task.details, task.assignee, task.location]
      .some((field) => (field || '').toLowerCase().includes(needle));
  });

  const byPriority = (a, b) => (b.priority === 'high') - (a.priority === 'high');
  const sorters = {
    'created-desc': (a, b) => b.createdAt - a.createdAt,
    'created-asc': (a, b) => a.createdAt - b.createdAt,
    // השלמות בלי תאריך יעד יורדות לסוף במקום להיערם בראש הרשימה.
    due: (a, b) => (a.due || '9999').localeCompare(b.due || '9999') || b.createdAt - a.createdAt,
    priority: (a, b) => byPriority(a, b) || b.createdAt - a.createdAt,
    location: (a, b) => (a.location || 'תתת').localeCompare(b.location || 'תתת', 'he')
      || b.createdAt - a.createdAt,
  };
  return matches.sort(sorters[state.sort] || sorters['created-desc']);
}

function renderProgress() {
  const total = state.tasks.length;
  const done = state.tasks.filter((t) => t.status === 'done').length;
  const percent = total ? Math.round((done / total) * 100) : 0;

  $('#progressFill').style.width = `${percent}%`;
  $('#progressStats').textContent = total
    ? `${done} מתוך ${total} בוצעו · ${percent}%`
    : 'אין עדיין השלמות';
}

function thumbNode(task) {
  const cover = state.covers.get(task.id);
  const extra = (state.counts.get(task.id) || 0) - 1;

  const button = el('button', {
    type: 'button',
    class: 'card__thumb',
    title: cover ? 'הצגת המדיה' : 'הוספת תמונה',
    onclick: () => (cover ? openViewer(task.id) : openEditor(task)),
  });

  if (cover?.thumb) {
    button.append(el('img', { src: listUrls.make(cover.thumb), alt: '', loading: 'lazy' }));
  } else if (cover) {
    // פריט שהמטא-דאטה שלו סונכרנה אך הקובץ עוד לא ירד למכשיר.
    button.append(el('span', { text: cover.kind === 'video' ? '🎬' : '🖼' }));
  } else {
    button.append(el('span', { text: '＋' }));
  }

  if (cover?.kind === 'video') button.append(el('span', { class: 'play', text: '▶' }));
  if (extra > 0) button.append(el('span', { class: 'more', text: `+${extra}` }));
  return button;
}

function badges(task) {
  const nodes = [el('span', {
    class: 'badge badge--status',
    dataset: { status: task.status },
    text: STATUS_LABEL[task.status] || task.status,
  })];

  if (task.priority === 'high') nodes.push(el('span', { class: 'badge badge--high', text: '⚠ דחוף' }));
  if (task.assignee) nodes.push(el('span', { class: 'badge badge--assignee', text: `👤 ${task.assignee}` }));
  if (task.location) nodes.push(el('span', { class: 'badge', text: `📍 ${task.location}` }));

  if (task.due) {
    const overdue = task.status !== 'done' && task.due < todayISO();
    nodes.push(el('span', {
      class: `badge${overdue ? ' badge--overdue' : ''}`,
      text: `${overdue ? '⏰' : '📅'} ${formatDate(task.due)}`,
    }));
  }
  return nodes;
}

function cardNode(task) {
  const details = task.details
    ? el('p', { class: 'card__details', text: task.details })
    : null;

  const title = el('h3', {
    class: 'card__title',
    text: task.title,
    title: 'הצגת התיאור המלא',
    onclick: () => details?.classList.toggle('is-open'),
  });

  return el('article', { class: 'card', dataset: { status: task.status, id: task.id } }, [
    el('button', {
      type: 'button',
      class: 'card__check',
      title: task.status === 'done' ? 'סימון כלא בוצע' : 'סימון כבוצע',
      'aria-label': task.status === 'done' ? 'סימון כלא בוצע' : 'סימון כבוצע',
      text: '✓',
      onclick: () => toggleDone(task),
    }),
    thumbNode(task),
    el('div', { class: 'card__body' }, [
      title,
      details,
      el('div', { class: 'card__meta' }, badges(task)),
    ]),
    el('button', {
      type: 'button',
      class: 'card__edit',
      text: 'עריכה',
      onclick: () => openEditor(task),
    }),
  ]);
}

function renderList() {
  listUrls.release();
  const tasks = visibleTasks();
  $('#list').replaceChildren(...tasks.map(cardNode));

  const empty = $('#empty');
  empty.hidden = tasks.length > 0;
  if (!tasks.length) {
    empty.textContent = state.tasks.length
      ? 'אין השלמות שמתאימות לסינון הנוכחי.'
      : 'עוד אין השלמות ברשימה. הוסיפו את הראשונה — צלמו את הליקוי, כתבו מה חסר וסמנו מי אחראי.';
  }
}

/* ── פעולות על השלמה ───────────────────────────────────────────── */

async function toggleDone(task) {
  const done = task.status === 'done';
  await db.saveTask({
    ...task,
    status: done ? 'open' : 'done',
    doneAt: done ? null : db.now(),
  });
  await refresh();
  maybeAutoSync();
}

/* ── עורך ההשלמה ───────────────────────────────────────────────── */

async function openEditor(task = null) {
  // הקשה כפולה על "השלמה חדשה" בנייד מגיעה כשתי לחיצות; showModal על
  // חלונית פתוחה זורק שגיאה.
  if ($('#editor').open) return;

  const isNew = !task;
  editor = {
    isNew,
    task: task ? { ...task } : db.blankTask(),
    media: task ? (await db.listMedia(task.id)).map((record) => ({ record })) : [],
    removed: new Set(),
  };

  $('#editorTitle').textContent = isNew ? 'השלמה חדשה' : 'עריכת השלמה';
  $('#deleteBtn').hidden = isNew;

  const { task: t } = editor;
  $('#fTitle').value = t.title;
  $('#fDetails').value = t.details;
  $('#fAssignee').value = t.assignee;
  $('#fLocation').value = t.location;
  $('#fStatus').value = t.status;
  $('#fPriority').value = t.priority;
  $('#fDue').value = t.due;

  renderEditorMedia();
  $('#editor').showModal();
  if (isNew) $('#fTitle').focus();
}

function closeEditor() {
  $('#editor').close();
  editorUrls.release();
  editor = null;
}

/** פריטי המדיה שיוצגו כרגע בעורך (בלי אלה שסומנו למחיקה). */
const editorMedia = () => editor.media.filter((item) => !editor.removed.has(item.record?.id ?? item.key));

function renderEditorMedia() {
  editorUrls.release();
  const items = editorMedia();

  $('#mediaGrid').replaceChildren(...items.map((item) => {
    const data = item.pending ? item.pending : item.record;
    const key = item.record?.id ?? item.key;
    const node = el('div', { class: 'media-item' });

    if (data.thumb) {
      node.append(el('button', {
        type: 'button', class: 'open', title: 'הגדלה',
        onclick: () => showMedia(item),
      }, el('img', { src: editorUrls.make(data.thumb), alt: data.name || '' })));
    } else {
      const missing = !data.blob;
      node.classList.toggle('is-missing', missing);
      node.append(el('button', {
        type: 'button', class: 'open',
        title: missing ? 'הקובץ עוד לא ירד למכשיר' : 'הגדלה',
        text: data.kind === 'video' ? '🎬' : '🖼',
        onclick: () => showMedia(item),
      }));
    }

    node.append(el('span', {
      class: 'kind',
      text: data.kind === 'video' ? 'סרטון' : mediaLib.formatBytes(data.size),
    }));
    node.append(el('button', {
      type: 'button', class: 'remove', text: '✕',
      title: 'הסרה', 'aria-label': `הסרת ${data.name || 'הקובץ'}`,
      onclick: () => { editor.removed.add(key); renderEditorMedia(); },
    }));
    return node;
  }));

  const hint = $('#mediaHint');
  const pending = items.filter((i) => i.pending).length;
  hint.hidden = !pending;
  if (pending) hint.textContent = `${pending} קבצים חדשים — יישמרו עם השלמה.`;
}

/** קולט קבצים מהמצלמה או מהגלריה ומכין אותם לשמירה. */
async function addFiles(fileList) {
  const files = [...fileList];
  if (!files.length) return;

  // עיבוד סרטון אורך שניות; אם העורך נסגר בינתיים, התוצאה נזרקת.
  const target = editor;
  toast(files.length > 1 ? `מעבד ${files.length} קבצים…` : 'מעבד קובץ…');
  let failed = 0;

  for (const file of files) {
    try {
      const prepared = await mediaLib.prepare(file, { compress: state.settings.compress });
      target.media.push({ key: db.uid('p'), pending: prepared });
    } catch {
      failed += 1;
    }
  }

  if (editor !== target) return;
  renderEditorMedia();
  if (failed) toast(`${failed} קבצים לא נקלטו`, 'error');
}

async function commitEditor(event) {
  event.preventDefault();
  const title = $('#fTitle').value.trim();
  if (!title) { $('#fTitle').focus(); return; }

  const previous = editor.task.status;
  const status = $('#fStatus').value;
  const task = {
    ...editor.task,
    title,
    details: $('#fDetails').value.trim(),
    assignee: $('#fAssignee').value.trim(),
    location: $('#fLocation').value.trim(),
    status,
    priority: $('#fPriority').value,
    due: $('#fDue').value,
    // doneAt נשמר רק במעבר לבוצע, כדי לא לדרוס את התאריך המקורי בכל עריכה.
    doneAt: status === 'done' ? (editor.task.doneAt || db.now()) : null,
  };

  const { media, removed } = editor;
  const saveBtn = $('#saveBtn');
  saveBtn.disabled = true;

  try {
    await db.saveTask(task);

    for (const item of media) {
      const key = item.record?.id ?? item.key;
      if (removed.has(key)) {
        if (item.record) await db.deleteMedia(item.record.id);
        continue;
      }
      if (item.pending) await db.addMedia({ ...item.pending, taskId: task.id });
    }

    closeEditor();
    await refresh();
    toast(status === 'done' && previous !== 'done' ? 'סומן כבוצע ✓' : 'נשמר', 'ok');
    maybeAutoSync();
  } catch (err) {
    toast(`השמירה נכשלה: ${err.message}`, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function removeTask() {
  if (!confirmAction('למחוק את ההשלמה הזו ואת כל התמונות שלה?')) return;
  const { id } = editor.task;
  closeEditor();
  await db.deleteTask(id);
  await refresh();
  toast('נמחק');
  maybeAutoSync();
}

/* ── מציג המדיה ────────────────────────────────────────────────── */

function showMedia(item) {
  const data = item.pending ? item.pending : item.record;
  if (!data.blob) { toast('הקובץ עוד לא ירד למכשיר — הריצו סנכרון', 'error'); return; }
  renderViewer([data], 0);
}

async function openViewer(taskId) {
  const items = (await db.listMedia(taskId)).filter((m) => m.blob);
  if (!items.length) { toast('אין קבצים זמינים במכשיר', 'error'); return; }
  renderViewer(items, 0);
}

function renderViewer(items, index) {
  viewerUrls.release();
  const item = items[index];
  const url = viewerUrls.make(item.blob);

  const node = item.kind === 'video'
    ? el('video', { src: url, controls: true, playsinline: true, autoplay: true })
    : el('img', { src: url, alt: item.name || '' });

  $('#lightboxStage').replaceChildren(node);
  $('#lightboxCaption').textContent = [
    items.length > 1 ? `${index + 1} מתוך ${items.length}` : '',
    item.name,
    mediaLib.formatBytes(item.size),
  ].filter(Boolean).join(' · ');
  $('#lightbox').hidden = false;

  // ניווט בין פריטים של אותה השלמה, בלי לפתוח את המציג מחדש.
  $('#lightboxStage').onclick = items.length > 1
    ? () => renderViewer(items, (index + 1) % items.length)
    : null;
}

function closeViewer() {
  $('#lightbox').hidden = true;
  $('#lightboxStage').replaceChildren();
  viewerUrls.release();
}

/* ── סנכרון ────────────────────────────────────────────────────── */

let syncing = false;

async function runSync({ silent = false } = {}) {
  const config = syncConfig();
  if (!config.url) { if (!silent) toast('לא הוגדרה כתובת שרת', 'error'); return; }
  if (syncing) return;

  syncing = true;
  $('#syncBtn').classList.add('is-busy');
  const status = $('#syncStatus');

  try {
    const summary = await sync.run(config, (step) => { status.textContent = step; });
    await refresh();

    const parts = [];
    if (summary.pushed) parts.push(`נשלחו ${summary.pushed}`);
    if (summary.pulled) parts.push(`התקבלו ${summary.pulled}`);
    if (summary.uploaded) parts.push(`הועלו ${summary.uploaded} קבצים`);
    if (summary.downloaded) parts.push(`ירדו ${summary.downloaded} קבצים`);
    if (summary.failed) parts.push(`${summary.failed} נכשלו`);

    const message = parts.length ? parts.join(' · ') : 'הכול מעודכן';
    status.textContent = message;
    if (!silent) toast(message, summary.failed ? 'error' : 'ok');
  } catch (err) {
    status.textContent = err.message;
    if (!silent) toast(err.message, 'error');
  } finally {
    syncing = false;
    $('#syncBtn').classList.remove('is-busy');
  }
}

let autoSyncTimer = null;

/** סנכרון אוטומטי מושהה — עריכות רצופות לא פותחות מחזור סנכרון לכל אחת. */
function maybeAutoSync() {
  if (!state.settings.autoSync || !state.settings.serverUrl) return;
  clearTimeout(autoSyncTimer);
  autoSyncTimer = setTimeout(() => runSync({ silent: true }), 2500);
}

/* ── גיבוי מקומי ───────────────────────────────────────────────── */

function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

const dataUrlToBlob = (url) => fetch(url).then((res) => res.blob());

async function exportAll() {
  const button = $('#exportBtn');
  button.disabled = true;
  try {
    const tasks = await db.allTasksRaw();
    const media = [];

    for (const item of await db.allMediaRaw()) {
      const { blob, thumb, ...meta } = item;
      media.push({
        ...meta,
        blobData: blob ? await blobToDataUrl(blob) : null,
        thumbData: thumb ? await blobToDataUrl(thumb) : null,
      });
    }

    const payload = {
      app: 'renovation-punchlist',
      version: 1,
      exportedAt: new Date().toISOString(),
      projectName: state.settings.projectName,
      tasks,
      media,
    };

    const file = new Blob([JSON.stringify(payload)], { type: 'application/json' });
    const url = URL.createObjectURL(file);
    const name = (state.settings.projectName || 'השלמות').replace(/[\\/:*?"<>|]/g, '_');

    const link = el('a', { href: url, download: `${name}-${todayISO()}.json` });
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    toast(`יוצאו ${tasks.filter((t) => !t.deleted).length} השלמות`, 'ok');
  } catch (err) {
    toast(`הייצוא נכשל: ${err.message}`, 'error');
  } finally {
    button.disabled = false;
  }
}

async function importFile(file) {
  try {
    const payload = JSON.parse(await file.text());
    if (payload.app !== 'renovation-punchlist' || !Array.isArray(payload.tasks)) {
      throw new Error('הקובץ אינו גיבוי של האפליקציה');
    }
    if (!confirmAction(`לייבא ${payload.tasks.length} רשומות? רשומות עם אותו מזהה יתמזגו לפי החדשה מביניהן.`)) return;

    let imported = 0;
    for (const task of payload.tasks) {
      const local = await db.getTask(task.id);
      if (local && local.updatedAt >= task.updatedAt) continue;
      await db.saveTask(task, false);
      imported += 1;
    }

    for (const item of payload.media || []) {
      const { blobData, thumbData, ...meta } = item;
      const local = await db.getMedia(meta.id);
      if (local && local.updatedAt >= meta.updatedAt) continue;
      await db.putMedia({
        ...meta,
        blob: blobData ? await dataUrlToBlob(blobData) : null,
        thumb: thumbData ? await dataUrlToBlob(thumbData) : null,
        uploaded: false,
      });
      imported += 1;
    }

    if (payload.projectName && !state.settings.projectName) {
      await saveSettings({ projectName: payload.projectName });
      $('#sProject').value = payload.projectName;
    }

    await refresh();
    toast(`יובאו ${imported} רשומות`, 'ok');
  } catch (err) {
    toast(`הייבוא נכשל: ${err.message}`, 'error');
  }
}

async function showStorage() {
  const info = await db.usage();
  $('#storageInfo').textContent = info
    ? `בשימוש: ${mediaLib.formatBytes(info.used)} מתוך ${mediaLib.formatBytes(info.quota)} שהדפדפן מקצה.`
    : '';
}

/* ── חיבור הממשק ───────────────────────────────────────────────── */

function bindToolbar() {
  $('#search').addEventListener('input', (e) => {
    state.filters.search = e.target.value;
    renderList();
  });

  $('#statusChips').addEventListener('click', (e) => {
    const chip = e.target.closest('.chip');
    if (!chip) return;
    $$('.chip', $('#statusChips')).forEach((c) => c.classList.toggle('is-active', c === chip));
    state.filters.status = chip.dataset.status;
    renderList();
  });

  $('#filterAssignee').addEventListener('change', (e) => {
    state.filters.assignee = e.target.value;
    renderList();
  });
  $('#filterLocation').addEventListener('change', (e) => {
    state.filters.location = e.target.value;
    renderList();
  });
  $('#sortBy').addEventListener('change', (e) => {
    state.sort = e.target.value;
    renderList();
  });

  $('#addBtn').addEventListener('click', () => openEditor());
}

function bindEditor() {
  $('#editorForm').addEventListener('submit', commitEditor);
  $('#deleteBtn').addEventListener('click', removeTask);

  for (const id of ['#fCapture', '#fVideo', '#fPick']) {
    $(id).addEventListener('change', async (e) => {
      await addFiles(e.target.files);
      e.target.value = '';  // בלי איפוס, בחירה חוזרת באותו קובץ לא מפעילה change
    });
  }

  // ESC על <dialog> נסגר לבד; מנקים אחריו את כתובות ה-blob.
  $('#editor').addEventListener('close', () => { editorUrls.release(); editor = null; });
  $$('[data-close]').forEach((btn) => {
    btn.addEventListener('click', () => $(`#${btn.dataset.close}`).close());
  });
}

function bindSettings() {
  const dialog = $('#settings');

  $('#settingsBtn').addEventListener('click', async () => {
    const s = state.settings;
    $('#sProject').value = s.projectName;
    $('#sCompress').checked = s.compress;
    $('#sUrl').value = s.serverUrl;
    $('#sToken').value = s.token;
    $('#sAutoSync').checked = s.autoSync;
    $('#syncStatus').textContent = '';
    await showStorage();
    dialog.showModal();
  });

  $('#sProject').addEventListener('change', (e) => saveSettings({ projectName: e.target.value.trim() }));
  $('#sCompress').addEventListener('change', (e) => saveSettings({ compress: e.target.checked }));
  $('#sUrl').addEventListener('change', (e) => saveSettings({ serverUrl: e.target.value.trim() }));
  $('#sToken').addEventListener('change', (e) => saveSettings({ token: e.target.value }));
  $('#sAutoSync').addEventListener('change', (e) => saveSettings({ autoSync: e.target.checked }));

  $('#testBtn').addEventListener('click', async () => {
    // change לא נורה אם המשתמש לחץ ישר על הכפתור אחרי ההקלדה.
    await saveSettings({ serverUrl: $('#sUrl').value.trim(), token: $('#sToken').value });
    const config = syncConfig();
    if (!config.url) { $('#syncStatus').textContent = 'הזינו כתובת שרת'; return; }

    $('#syncStatus').textContent = 'בודק…';
    try {
      const info = await sync.ping(config);
      $('#syncStatus').textContent =
        `מחובר · ${info.tasks} השלמות ו-${info.media} קבצים בשרת (גרסה ${info.version})`;
    } catch (err) {
      $('#syncStatus').textContent = err.message;
    }
  });

  $('#syncNowBtn').addEventListener('click', async () => {
    await saveSettings({ serverUrl: $('#sUrl').value.trim(), token: $('#sToken').value });
    await runSync();
    await showStorage();
  });

  $('#syncBtn').addEventListener('click', () => runSync());
  $('#exportBtn').addEventListener('click', exportAll);
  $('#importInput').addEventListener('change', async (e) => {
    const [file] = e.target.files;
    e.target.value = '';
    if (file) await importFile(file);
  });

  $('#wipeBtn').addEventListener('click', async () => {
    if (!confirmAction('למחוק את כל ההשלמות, התמונות וההגדרות במכשיר הזה? הפעולה אינה הפיכה.')) return;
    await db.wipe();
    state.settings = { ...DEFAULT_SETTINGS };
    applySettings();
    dialog.close();
    await refresh();
    toast('הנתונים נמחקו');
  });
}

function bindViewer() {
  $('#lightboxClose').addEventListener('click', closeViewer);
  $('#lightbox').addEventListener('click', (e) => {
    if (e.target === $('#lightbox')) closeViewer();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !$('#lightbox').hidden) closeViewer();
  });
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./service-worker.js').catch(() => {
      // עבודה ללא רשת היא בונוס; כישלון רישום לא צריך להפריע לשימוש.
    });
  });
}

async function init() {
  bindToolbar();
  bindEditor();
  bindSettings();
  bindViewer();
  registerServiceWorker();

  try {
    await loadSettings();
    await refresh();
  } catch (err) {
    toast(`טעינת הנתונים נכשלה: ${err.message}`, 'error');
    return;
  }

  if (state.settings.autoSync && state.settings.serverUrl) runSync({ silent: true });
}

init();
