/**
 * מטלות לקלוד — ממשק.
 *
 * שתי פעולות נושאות את המסך: לזרוק מטלה חדשה בלי חיכוך, ולראות מיד מה
 * ממתין דווקא לי. השאר משני.
 */

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

const STATUS = {
  open:        { label: 'בתור',      cls: '' },
  in_progress: { label: 'בעבודה',    cls: 'badge--progress' },
  blocked:     { label: 'ממתין לך',  cls: 'badge--blocked' },
  answered:    { label: 'נענתה',     cls: 'badge--answered' },
  done:        { label: 'הושלמה',    cls: 'badge--done' },
  cancelled:   { label: 'בוטלה',     cls: '' },
};
const KIND = { code: 'קוד', question: 'שאלה', research: 'מחקר' };

const state = {
  user: null,
  topics: [],
  topicId: null,
  status: 'active',
  search: '',
  tasks: [],
  counts: {},
  openTaskId: null,
};

/* ── עזרים ─────────────────────────────────────────────────── */

function el(tag, props = {}, children = []) {
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

function toast(msg, kind = '') {
  const host = $('#toasts');
  while (host.children.length >= 3) host.firstElementChild.remove();
  const n = el('div', { class: `toast${kind ? ` toast--${kind}` : ''}`, text: msg });
  host.append(n);
  setTimeout(() => n.remove(), kind === 'error' ? 5000 : 2600);
}

/** קריאה ל-API. שגיאה מגיעה כחריגה כדי שהקורא לא ישכח לבדוק. */
async function api(action, payload = {}) {
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

function timeAgo(ts) {
  const mins = Math.floor((Date.now() / 1000 - ts) / 60);
  if (mins < 1) return 'עכשיו';
  if (mins < 60) return `לפני ${mins} דק׳`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `לפני ${hours} שע׳`;
  return `לפני ${Math.floor(hours / 24)} ימים`;
}

/* ── כניסה ─────────────────────────────────────────────────── */

let gateMode = 'login';

async function boot() {
  const info = await api('bootstrap-status');
  if (info.user) { state.user = info.user; return enterApp(); }

  gateMode = info.has_users ? 'login' : 'register';
  $('#gateSub').textContent = gateMode === 'login'
    ? 'התחברות'
    : 'אין עדיין משתמשים — החשבון הראשון יהיה המנהל';
  $('#gSubmit').textContent = gateMode === 'login' ? 'כניסה' : 'יצירת חשבון';
  $('#gNameWrap').hidden = gateMode === 'login';
  $('#gate').hidden = false;
}

$('#gateForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = $('#gateErr');
  err.hidden = true;
  try {
    const payload = { username: $('#gUser').value, password: $('#gPass').value };
    if (gateMode === 'register') payload.display_name = $('#gName').value;
    const r = await api(gateMode === 'register' ? 'register-first' : 'login', payload);
    state.user = r.user;
    $('#gate').hidden = true;
    enterApp();
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
  }
});

async function enterApp() {
  $('#app').hidden = false;
  await loadTopics();
  await refresh();
}

/* ── נושאים ────────────────────────────────────────────────── */

async function loadTopics() {
  const { topics } = await api('topics');
  state.topics = topics;

  $('#topics').replaceChildren(
    el('button', {
      type: 'button',
      class: `topic${state.topicId === null ? ' is-active' : ''}`,
      onclick: () => selectTopic(null),
    }, [el('span', { text: 'כל הנושאים' })]),
    ...topics.map((t) => el('button', {
      type: 'button',
      class: `topic${state.topicId === t.id ? ' is-active' : ''}`,
      onclick: () => selectTopic(t.id),
    }, [
      el('span', { text: t.name }),
      el('span', { class: 'count', text: String(t.open_count) }),
    ])),
  );

  $('#capTopic').replaceChildren(
    el('option', { value: '', text: 'ללא נושא' }),
    ...topics.map((t) => el('option', { value: String(t.id), text: t.name })),
  );
  if (state.topicId) $('#capTopic').value = String(state.topicId);
}

function selectTopic(id) {
  state.topicId = id;
  $('#drawer').hidden = true;
  $('#scopeLabel').textContent = id
    ? (state.topics.find((t) => t.id === id)?.name ?? 'נושא')
    : 'כל הנושאים';
  if (id) $('#capTopic').value = String(id);
  loadTopics();
  refresh();
}

/* ── רשימה ─────────────────────────────────────────────────── */

async function refresh() {
  const { tasks, counts } = await api('tasks', {
    topic_id: state.topicId,
    status: state.status,
    search: state.search,
  });
  state.tasks = tasks;
  state.counts = counts;
  renderPulse();
  renderList();
}

function renderPulse() {
  const c = state.counts;
  const stats = [
    { n: c.blocked || 0, label: 'ממתינות לך', alert: true },
    { n: c.in_progress || 0, label: 'בעבודה' },
    { n: (c.open || 0) + (c.answered || 0), label: 'בתור' },
    { n: c.done || 0, label: 'הושלמו' },
  ];
  $('#pulse').replaceChildren(...stats.map((s) => el('div', {
    class: `stat${s.alert && s.n > 0 ? ' stat--alert' : ''}`,
  }, [el('b', { text: String(s.n) }), el('span', { text: s.label })])));
}

function taskCard(t) {
  const st = STATUS[t.status] || { label: t.status, cls: '' };
  const badges = [el('span', { class: `badge ${st.cls}`, text: st.label })];

  if (t.priority === 'high') badges.push(el('span', { class: 'badge badge--high', text: '⚠ דחוף' }));
  if (t.topic_name) badges.push(el('span', { class: 'badge badge--topic', text: t.topic_name }));
  badges.push(el('span', { class: 'badge', text: KIND[t.kind] || t.kind }));
  if (Number(t.note_count) > 0) badges.push(el('span', { class: 'badge', text: `💬 ${t.note_count}` }));
  if (t.claimed_by) badges.push(el('span', { class: 'badge', text: `🤖 ${t.claimed_by}` }));
  badges.push(el('span', { class: 'badge', text: timeAgo(Number(t.updated_at)) }));

  return el('article', {
    class: 'card',
    dataset: { status: t.status },
    onclick: () => openTask(t.id),
  }, [
    el('h3', { class: 'card__title', text: t.title }),
    el('div', { class: 'card__meta' }, badges),
  ]);
}

function renderList() {
  $('#list').replaceChildren(...state.tasks.map(taskCard));
  const empty = $('#empty');
  empty.hidden = state.tasks.length > 0;
  if (!state.tasks.length) {
    empty.textContent = state.search
      ? 'אין מטלות שמתאימות לחיפוש.'
      : 'אין כאן מטלות. כתבו בשורה למעלה מה צריך לעשות — אפשר גם חצי מחשבה, אפשר לדייק אחר כך.';
  }
}

/* ── פרטי מטלה ─────────────────────────────────────────────── */

async function openTask(id) {
  const { task, notes } = await api('task', { id });
  state.openTaskId = id;

  $('#dTitle').textContent = task.title;

  const st = STATUS[task.status] || { label: task.status, cls: '' };
  const meta = [el('span', { class: `badge ${st.cls}`, text: st.label }),
                el('span', { class: 'badge', text: KIND[task.kind] || task.kind })];
  if (task.repo) meta.push(el('span', { class: 'badge', text: `📦 ${task.repo}` }));
  if (task.branch) meta.push(el('span', { class: 'badge', text: `🌿 ${task.branch}` }));
  $('#dMeta').replaceChildren(...meta);

  $('#dBody').textContent = task.body || '(אין תיאור מפורט)';

  $('#dThread').replaceChildren(...notes.map((n) => el('div', {
    class: `note note--${n.kind}`,
  }, [
    el('div', { class: 'note__head' }, [
      el('b', { text: n.author }),
      el('span', { text: { question: 'שאלה', answer: 'תשובה', result: 'תוצאה' }[n.kind] || 'הערה' }),
      el('span', { text: timeAgo(Number(n.created_at)) }),
    ]),
    el('p', { text: n.body }),
  ])));
  if (!notes.length) $('#dThread').replaceChildren(el('p', { class: 'hint', text: 'עוד אין שיחה על המטלה.' }));

  $('#replyBody').value = '';
  $('#detail').showModal();
}

$('#replyForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const body = $('#replyBody').value.trim();
  if (!body) return;
  try {
    const r = await api('add-note', { id: state.openTaskId, body });
    $('#detail').close();
    await refresh();
    toast(r.reopened ? 'נשלח — המטלה חזרה לתור של Claude' : 'ההערה נשמרה', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

$('#dDone').addEventListener('click', async () => {
  try {
    await api('update-task', { id: state.openTaskId, status: 'done' });
    $('#detail').close();
    await refresh();
    toast('סומן כהושלם', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── קליטה מהירה ───────────────────────────────────────────── */

$('#captureForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const title = $('#capTitle').value.trim();
  if (!title) return;
  try {
    await api('create-task', {
      title,
      topic_id: $('#capTopic').value || null,
      kind: $('#capKind').value,
      priority: $('#capPriority').value,
    });
    $('#capTitle').value = '';
    await loadTopics();
    await refresh();
    toast('נוסף לתור', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

$('#topicForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await api('create-topic', { name: $('#topicName').value, repo: $('#topicRepo').value });
    $('#topicName').value = ''; $('#topicRepo').value = '';
    await loadTopics();
    toast('הנושא נוסף', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── סינון וניווט ──────────────────────────────────────────── */

$('#statusChips').addEventListener('click', (e) => {
  const chip = e.target.closest('.chip');
  if (!chip) return;
  $$('.chip', $('#statusChips')).forEach((c) => c.classList.toggle('is-active', c === chip));
  state.status = chip.dataset.status;
  refresh();
});

let searchTimer = null;
$('#search').addEventListener('input', (e) => {
  state.search = e.target.value;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(refresh, 250);
});

$('#menuBtn').addEventListener('click', () => { $('#drawer').hidden = false; });
$('#drawer').addEventListener('click', (e) => { if (e.target === $('#drawer')) $('#drawer').hidden = true; });
$$('[data-close]').forEach((b) => b.addEventListener('click', () => {
  const t = $(`#${b.dataset.close}`);
  if (t.tagName === 'DIALOG') t.close(); else t.hidden = true;
}));

$('#logoutBtn').addEventListener('click', async () => {
  await api('logout').catch(() => {});
  location.reload();
});

/* ── הגדרות ────────────────────────────────────────────────── */

$('#settingsBtn').addEventListener('click', () => {
  $('#tokenValue').textContent = '••••••••';
  $('#tokenShow').hidden = false;
  $('#adminOnly').hidden = state.user?.role === 'admin';
  $('#settings').showModal();
});

$('#tokenShow').addEventListener('click', async () => {
  try {
    const r = await api('worker-token');
    $('#tokenValue').textContent = r.worker_token;
    $('#tokenShow').hidden = true;
  } catch (ex) { toast(ex.message, 'error'); }
});

$('#userForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await api('create-user', {
      username: $('#nUser').value,
      password: $('#nPass').value,
      display_name: $('#nName').value,
    });
    $('#nUser').value = ''; $('#nPass').value = ''; $('#nName').value = '';
    toast('המשתמש נוצר', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── רענון תקופתי ──────────────────────────────────────────── */

// Claude עובד ברקע, ולכן המסך צריך להתעדכן מעצמו. מרענן רק כשהלשונית
// גלויה ואין חלונית פתוחה, כדי לא לדרוס עריכה באמצע.
setInterval(() => {
  if (document.hidden || $('#detail').open || $('#settings').open) return;
  if (!state.user) return;
  refresh().catch(() => {});
}, 20000);

boot().catch((ex) => {
  document.body.textContent = `טעינה נכשלה: ${ex.message}`;
});
