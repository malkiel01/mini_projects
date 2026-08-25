import { $, $$, el, toast, api, state, timeAgo } from './core.js';
import { loadConnections, loadProviders, runTask } from './platform.js';

/**
 * מטלות לקלוד — ממשק.
 *
 * שתי פעולות נושאות את המסך: לזרוק מטלה חדשה בלי חיכוך, ולראות מיד מה
 * ממתין דווקא לי. השאר משני.
 */


const STATUS = {
  open:        { label: 'בתור',      cls: '' },
  in_progress: { label: 'בעבודה',    cls: 'badge--progress' },
  blocked:     { label: 'ממתין לך',  cls: 'badge--blocked' },
  answered:    { label: 'נענתה',     cls: 'badge--answered' },
  done:        { label: 'הושלמה',    cls: 'badge--done' },
  cancelled:   { label: 'בוטלה',     cls: '' },
};
const KIND = { code: 'קוד', question: 'שאלה', research: 'מחקר' };


/** איך נקבע הנושא — מוצג על הכרטיס, כדי שיהיה ברור מה המערכת החליטה לבד. */
const SOURCE = {
  keyword: { icon: '🪄', title: 'שויך אוטומטית לפי מילות מפתח' },
  llm:     { icon: '🪄', title: 'שויך אוטומטית על ידי המודל' },
};

/* ── עזרים ─────────────────────────────────────────────────── */





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
  $('#adminBtn').hidden = state.user?.role !== 'admin';

  await loadTopics();
  await refresh();

  // כישלון בטעינת ההגדרות לא אמור למנוע עבודה על מטלות.
  loadConnections().catch(() => {});
  loadProviders().catch(() => {});
}

/* ── נושאים ────────────────────────────────────────────────── */

async function loadTopics() {
  const { topics, unassigned, ai } = await api('topics');
  state.topics = topics;
  state.unassigned = unassigned || 0;
  if (ai) state.ai = ai;

  const row = (label, id, count, topic) => {
    const btn = el('button', {
      type: 'button',
      class: `topic${state.topicId === id ? ' is-active' : ''}`,
      onclick: () => selectTopic(id),
    }, [
      el('span', { text: label }),
      el('span', { class: 'count', text: String(count) }),
    ]);
    if (!topic) return btn;
    return el('div', { class: 'topic-row' }, [
      btn,
      el('button', {
        type: 'button', class: 'icon-btn', title: 'עריכה ומילות מפתח',
        onclick: () => openTopicEdit(topic),
      }, ['✎']),
    ]);
  };

  $('#topics').replaceChildren(
    row('כל הנושאים', null, ''),
    ...topics.map((t) => row(t.name, t.id, t.open_count, t)),
    ...(state.unassigned ? [row('ללא נושא', 'none', state.unassigned)] : []),
  );

  // הכפתור מופיע רק כשיש מה לקטלג — אחרת הוא רעש.
  const cat = $('#catalogBtn');
  cat.hidden = state.unassigned === 0;
  cat.textContent = `קטלוג ${state.unassigned} מטלות ללא נושא`;

  $('#capTopic').replaceChildren(
    el('option', { value: 'auto', text: '✨ נושא אוטומטי' }),
    el('option', { value: '', text: 'בלי נושא' }),
    ...topics.map((t) => el('option', { value: String(t.id), text: t.name })),
  );
  $('#capTopic').value = typeof state.topicId === 'number' ? String(state.topicId) : 'auto';
}

function selectTopic(id) {
  state.topicId = id;
  $('#drawer').hidden = true;
  $('#scopeLabel').textContent = id === 'none'
    ? 'ללא נושא'
    : (id ? (state.topics.find((t) => t.id === id)?.name ?? 'נושא') : 'כל הנושאים');
  if (typeof id === 'number') $('#capTopic').value = String(id);
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

  if (t.topic_name) {
    const auto = SOURCE[t.topic_source];
    badges.push(el('span', {
      class: 'badge badge--topic',
      title: auto ? `${auto.title} (${Math.round((t.topic_confidence || 0) * 100)}%)` : 'נושא שנבחר ידנית',
      text: auto ? `${auto.icon} ${t.topic_name}` : t.topic_name,
    }));
  } else if (t.topic_hint) {
    // הצעה שממתינה לאישור: קליק אחד הופך אותה לנושא ומושך אליו גם את
    // שאר המטלות שקיבלו את אותה הצעה.
    badges.push(el('button', {
      type: 'button',
      class: 'badge badge--hint',
      title: 'הקטלוג הציע נושא חדש — לחיצה תיצור אותו',
      text: `💡 ${t.topic_hint}`,
      onclick: (ev) => { ev.stopPropagation(); applyHint(t.id, t.topic_hint); },
    }));
  }
  badges.push(el('span', { class: 'badge', text: KIND[t.kind] || t.kind }));
  if (Number(t.note_count) > 0) badges.push(el('span', { class: 'badge', text: `💬 ${t.note_count}` }));
  if (t.claimed_by) badges.push(el('span', { class: 'badge', text: `🤖 ${t.claimed_by}` }));

  // השביל חזרה לסשן שעבד על המטלה. נפתח בלשונית נפרדת, ולחיצה עליו
  // לא אמורה לפתוח גם את חלונית המטלה.
  if (t.pr_url) badges.push(el('a', {
    class: 'badge badge--link', href: t.pr_url, target: '_blank', rel: 'noopener noreferrer',
    title: 'ה-PR שנפתח למטלה', text: '🔀 PR',
    onclick: (ev) => ev.stopPropagation(),
  }));

  if (t.session_url) badges.push(el('a', {
    class: 'badge badge--link', href: t.session_url, target: '_blank', rel: 'noopener noreferrer',
    title: 'הסשן שעבד על המטלה', text: '🔗 סשן',
    onclick: (ev) => ev.stopPropagation(),
  }));
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
  if (task.session_url) meta.push(el('a', {
    class: 'badge badge--link', href: task.session_url, target: '_blank', rel: 'noopener noreferrer',
    text: '🔗 פתיחת הסשן',
  }));
  if (task.pr_url) meta.push(el('a', {
    class: 'badge badge--link', href: task.pr_url, target: '_blank', rel: 'noopener noreferrer',
    text: '🔀 ה-PR',
  }));

  // הרצה אפשרית רק כשיש ריפו מאחורי המטלה. ההרשאה עצמה נבדקת בשרת.
  $('#dRun').hidden = !task.project_id;
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

$('#dRun').addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  btn.disabled = true;
  try {
    const r = await runTask(state.openTaskId);
    $('#detail').close();
    await refresh();
    toast(`נשלחה להרצה ב-${r.provider} (${r.model})`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
  btn.disabled = false;
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

  const choice = $('#capTopic').value;
  try {
    const r = await api('create-task', {
      title,
      topic_id: choice === 'auto' || choice === '' ? null : choice,
      auto_topic: choice === 'auto',
      kind: $('#capKind').value,
      priority: $('#capPriority').value,
      provider: $('#capProvider').value || '',
    });
    $('#capTitle').value = '';
    clearGuess();
    await loadTopics();
    await refresh();

    if (r.topic_name)   toast(`נוסף ל״${r.topic_name}״`, 'ok');
    else if (r.hint)    toast(`נוסף · הקטלוג מציע נושא חדש: ${r.hint}`, 'ok');
    else                toast('נוסף לתור', 'ok');
    // המודל נכשל אך המטלה נשמרה — שקיפות במקום שקט.
    if (r.fallback) toast(`הקטלוג עבד לפי מילות מפתח: ${r.fallback}`, 'error');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── ניחוש חי ───────────────────────────────────────────────────
 *
 * מראה לאן המטלה הולכת עוד לפני ההוספה, כדי שהשיוך לא יהיה הפתעה.
 * מילות מפתח בלבד — זה רץ תוך כדי הקלדה ואסור שיעלה כסף.
 */

function clearGuess() { $('#capHint').hidden = true; }

let guessTimer = null;
$('#capTitle').addEventListener('input', () => {
  clearTimeout(guessTimer);
  const title = $('#capTitle').value.trim();
  if ($('#capTopic').value !== 'auto' || title.length < 6) return clearGuess();
  guessTimer = setTimeout(() => guess(title), 400);
});

$('#capTopic').addEventListener('change', () => {
  if ($('#capTopic').value !== 'auto') clearGuess();
});

async function guess(title) {
  try {
    const r = await api('classify', { title });
    if (title !== $('#capTitle').value.trim()) return;   // המשתמש המשיך להקליד

    const box = $('#capHint');
    if (r.topic_name) {
      box.textContent = `ישויך ל״${r.topic_name}״`;
      box.className = 'guess guess--hit';
    } else if (state.ai.has_key && state.ai.auto_catalog) {
      box.textContent = 'אין התאמה למילות מפתח — המודל יחליט בהוספה';
      box.className = 'guess';
    } else {
      box.textContent = 'לא זוהה נושא — ייכנס לרשימת "ללא נושא"';
      box.className = 'guess';
    }
    box.hidden = false;
  } catch { clearGuess(); }
}

/* ── קטלוג של מה שנשאר ─────────────────────────────────────────── */

async function applyHint(id, name) {
  try {
    const r = await api('apply-hint', { id, name });
    await loadTopics();
    await refresh();
    toast(r.also_moved
      ? `הנושא ״${r.name}״ נוצר · הועברו אליו עוד ${r.also_moved} מטלות`
      : `הנושא ״${r.name}״ נוצר`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
}

$('#catalogBtn').addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  btn.disabled = true;
  const original = btn.textContent;
  btn.textContent = 'מקטלג…';
  try {
    const r = await api('catalog-backlog', { limit: 25 });
    await loadTopics();
    await refresh();
    const parts = [`נבדקו ${r.checked}`];
    if (r.assigned) parts.push(`שויכו ${r.assigned}`);
    if (r.hints)    parts.push(`${r.hints} עם הצעת נושא`);
    toast(parts.join(' · '), 'ok');
  } catch (ex) {
    toast(ex.message, 'error');
    btn.textContent = original;
  } finally { btn.disabled = false; }
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

/* ── עריכת נושא וסדר העבודה ────────────────────────────────── */

function openTopicEdit(topic) {
  state.editTopicId = topic.id;
  $('#teName').value     = topic.name;
  $('#teKeywords').value = topic.keywords || '';
  $('#teDesc').value     = topic.description || '';
  $('#teRepo').value     = topic.repo || '';
  $('#teOrderResult').hidden = true;
  $('#teReorderAi').hidden = !state.ai.has_key;
  $('#topicEdit').showModal();
}

$('#topicEditForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await api('update-topic', {
      id: state.editTopicId,
      name: $('#teName').value,
      keywords: $('#teKeywords').value,
      description: $('#teDesc').value,
      repo: $('#teRepo').value,
    });
    $('#topicEdit').close();
    await loadTopics();
    await refresh();
    toast('הנושא עודכן', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

async function reorder(useModel) {
  const box = $('#teOrderResult');
  box.hidden = false;
  box.textContent = 'מסדר…';
  try {
    const r = await api('reorder-topic', { topic_id: state.editTopicId, use_model: useModel });
    box.textContent = r.ordered < 2
      ? 'אין מספיק מטלות פתוחות בנושא כדי לסדר'
      : `סודרו ${r.ordered} מטלות — ${r.reason}`;
    await refresh();
  } catch (ex) {
    box.textContent = ex.message;
  }
}

$('#teReorder').addEventListener('click', () => reorder(false));
$('#teReorderAi').addEventListener('click', () => reorder(true));

/* ── הגדרות ────────────────────────────────────────────────── */

$('#settingsBtn').addEventListener('click', () => {
  $('#tokenValue').textContent = '••••••••';
  $('#tokenShow').hidden = false;
  $('#adminOnly').hidden = state.user?.role === 'admin';
  renderAi();
  $('#settings').showModal();
});

function renderAi() {
  const ai = state.ai;
  const sel = $('#aiProvider');
  if (!sel.options.length && state.providers.length) {
    sel.replaceChildren(...state.providers.map((p) => el('option', { value: p.provider, text: p.label })));
  }
  sel.value = ai.provider || 'anthropic';
  $('#aiModel').value = ai.model || '';
  $('#aiAuto').checked = ai.auto_catalog !== false;
  $('#aiKey').value = '';
  $('#aiKey').placeholder = ai.has_key ? `מוגדר · …${ai.key_tail} (ריק = ללא שינוי)` : 'sk-ant-…';

  $('#aiState').textContent = ai.has_key
    ? (ai.key_from_env
        ? `${ai.label}: המפתח מגיע ממשתנה סביבה של השרת.`
        : `${ai.label} (…${ai.key_tail}) · מודל ${ai.model}`)
    : 'עובד לפי מילות מפתח בלבד. אין מפתח כללי מוגדר, ולכן אין חיוב.';

  // רק מנהל משנה את ההגדרה — לשאר אין טעם להציג טופס שיידחה.
  const admin = state.user?.role === 'admin';
  $$('#aiForm input, #aiForm button').forEach((n) => { n.disabled = !admin; });
  $('#aiClear').hidden = !ai.has_key || ai.key_from_env || !admin;
}

$('#aiForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    provider: $('#aiProvider').value,
    ai_model: $('#aiModel').value.trim(),
    auto_catalog: $('#aiAuto').checked,
  };
  // שדה ריק פירושו "אל תיגע במפתח". ניתוק נעשה במחיקה מפורשת.
  const key = $('#aiKey').value.trim();
  if (key !== '') payload.key = key;

  try {
    const r = await api('set-ai', payload);
    state.ai = r.ai;
    renderAi();
    toast('ההגדרות נשמרו', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

$('#aiClear').addEventListener('click', async () => {
  try {
    const r = await api('set-ai', { key: '' });
    state.ai = r.ai;
    renderAi();
    toast('המפתח נותק — הקטלוג ממשיך לפי מילות מפתח', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

$('#aiTest').addEventListener('click', async () => {
  try {
    const r = await api('test-ai');
    toast(r.message, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
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
