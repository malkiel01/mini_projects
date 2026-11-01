import { $, $$, el, toast, api, state, timeAgo, on, set } from './core.js';
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

/*
 * "חזרה" בטלפון סוגרת את החלון העליון, ולא יוצאת מהלוח.
 *
 * בלי זה, מטלה שנפתחה ב-showModal לא השאירה רשומה בהיסטוריה, ותנועת
 * "חזרה" עזבה את הדף — ובחזרה אליו הרשימה נראתה ריקה. כל חלון (וגם
 * המגירה) דוחף רשומה; "חזרה" סוגרת אותו; סגירה בכפתור מושכת את הרשומה.
 */
let popsToSkip = 0;
function pushLayer(id) { history.pushState({ layer: id }, ''); }
function dropLayer(id) {
  if (history.state?.layer === id) { popsToSkip++; history.back(); }
}

const showModal = HTMLDialogElement.prototype.showModal;
HTMLDialogElement.prototype.showModal = function () {
  if (this.open) return;
  showModal.call(this);
  pushLayer(this.id);
};
document.addEventListener('close', (e) => {
  if (e.target instanceof HTMLDialogElement) dropLayer(e.target.id);
}, true);

function openDrawer() {
  if (!$('#drawer').hidden) return;
  $('#drawer').hidden = false;
  pushLayer('drawer');
}
function closeDrawer() {
  if ($('#drawer').hidden) return;
  $('#drawer').hidden = true;
  dropLayer('drawer');
}

window.addEventListener('popstate', () => {
  if (popsToSkip > 0) { popsToSkip--; return; }
  const top = $$('dialog[open]').pop();
  if (top) { if (history.state?.layer !== top.id) top.close(); return; }
  if (!$('#drawer').hidden && history.state?.layer !== 'drawer') $('#drawer').hidden = true;
});

// --vvh: הגובה הנראה בפועל (בלי המקלדת) — הגיליון מתקצר והכול נגלל, גם באייפון
(() => {
  const vv = window.visualViewport;
  if (!vv) return;
  const setVvh = () => document.documentElement.style.setProperty('--vvh', `${Math.round(vv.height)}px`);
  vv.addEventListener('resize', setVvh);
  setVvh();
})();





/* ── כניסה ─────────────────────────────────────────────────── */

let gateMode = 'login';

async function boot() {
  const info = await api('bootstrap-status');
  state.platform = !!info.platform;
  if (info.user) { state.user = info.user; return enterApp(); }
  if (info.pending) return showLinkGate(info.pending);

  // מחוברת לפלטפורמה (או שהיא לא עונה): אין כאן כניסה בסיסמה — רק ההסבר.
  if (info.login_blocked) {
    $('#gateSub').textContent = info.login_blocked;
    $$('#gateForm .field, #gSubmit').forEach((n) => { n.hidden = true; });
    $('#gate').hidden = false;
    return;
  }

  gateMode = info.has_users ? 'login' : 'register';
  $('#gateSub').textContent = gateMode === 'login'
    ? 'התחברות'
    : 'אין עדיין משתמשים — החשבון הראשון יהיה המנהל';
  $('#gSubmit').textContent = gateMode === 'login' ? 'כניסה' : 'יצירת חשבון';
  $('#gNameWrap').hidden = gateMode === 'login';
  $('#gate').hidden = false;
}

on('#gateForm', 'submit', async (e) => {
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

/* ── מסך חיבור חד-פעמי (platform/docs/auth.md, סעיף 4) ───────── */

function showLinkGate(identity) {
  $('#linkHello').textContent = identity.name ? `ברוכים הבאים, ${identity.name}` : 'ברוכים הבאים';
  $('#linkGate').hidden = false;
}

function linkError(ex) {
  $('#linkErr').textContent = ex.message;
  $('#linkErr').hidden = false;
}

on('#linkHave', 'click', () => {
  $('#linkChoice').hidden = true;
  $('#linkForm').hidden = false;
  $('#lUser').focus();
});

on('#linkBack', 'click', () => {
  $('#linkForm').hidden = true;
  $('#linkChoice').hidden = false;
  $('#linkErr').hidden = true;
});

on('#linkNew', 'click', async () => {
  try {
    state.user = (await api('platform-new')).user;
    $('#linkGate').hidden = true;
    enterApp();
  } catch (ex) { linkError(ex); }
});

on('#linkForm', 'submit', async (e) => {
  e.preventDefault();
  $('#linkErr').hidden = true;
  try {
    state.user = (await api('platform-link', { username: $('#lUser').value, password: $('#lPass').value })).user;
    $('#linkGate').hidden = true;
    enterApp();
  } catch (ex) { linkError(ex); }
});

// מי שבחר "אין לי חשבון" ונזכר אחר כך — מההגדרות.
on('#oldForm', 'submit', async (e) => {
  e.preventDefault();
  try {
    await api('platform-link', { username: $('#oUser').value, password: $('#oPass').value });
    location.reload();
  } catch (ex) { toast(ex.message, 'error'); }
});

async function enterApp() {
  set('#app', 'hidden', false);
  set('#adminBtn', 'hidden', state.user?.role !== 'admin');
  // דרך הפלטפורמה: היציאה היא מהפלטפורמה, לא מכאן.
  set('#logoutBtn', 'hidden', state.platform);
  set('#oldAccount', 'hidden', !(state.platform && Number(state.user?.link_offer)));

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
  closeDrawer();
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
  const n = {
    active: ['open', 'in_progress', 'blocked', 'answered'].reduce((sum, k) => sum + (Number(c[k]) || 0), 0),
    blocked: Number(c.blocked) || 0,
    in_progress: Number(c.in_progress) || 0,
    open: (Number(c.open) || 0) + (Number(c.answered) || 0),
    done: Number(c.done) || 0,
  };
  $$('[data-count]').forEach((b) => { b.textContent = String(n[b.dataset.count] ?? 0); });
  // מה שממתין דווקא לי — בולט גם כשהלשונית לא נבחרה
  set('#statusChips [data-status="blocked"]', 'className', n.blocked > 0 ? 'alert' : '');
}

const ICON = { blocked: '❓', answered: '↩️', in_progress: '🔧', open: '⏳', done: '✅', cancelled: '✕' };

function taskCard(t) {
  const st = STATUS[t.status] || { label: t.status };
  // שורת משנה אחת, אפורה: מצב · נושא · סוג · שיחה · מי עובד
  const bits = [st.label];
  if (t.topic_name) bits.push(SOURCE[t.topic_source] ? `🪄 ${t.topic_name}` : t.topic_name);
  bits.push(KIND[t.kind] || t.kind);
  if (Number(t.note_count) > 0) bits.push(`💬 ${t.note_count}`);
  if (t.claimed_by) bits.push(`🤖 ${t.claimed_by}`);
  const l2 = [];
  if (t.priority === 'high') l2.push(el('span', { class: 'urgent', text: 'דחוף' }), el('i', { class: 'sep', 'aria-hidden': 'true', text: '·' }));
  bits.forEach((b, i) => {
    if (i) l2.push(el('i', { class: 'sep', 'aria-hidden': 'true', text: '·' }));
    l2.push(document.createTextNode(b));
  });

  const row = el('li', {
    class: 'card', tabindex: '0', role: 'button',
    dataset: { status: t.status },
    'aria-label': `${t.title} — ${st.label}`,
    onclick: () => openTask(t.id),
    onkeydown: (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openTask(t.id); } },
  }, [
    el('span', { class: 'ic', 'aria-hidden': 'true', text: ICON[t.status] || '•' }),
    el('div', { class: 'body' }, [
      el('div', { class: 'l1' }, [el('span', { text: t.title }), el('span', { class: 't', text: timeAgo(Number(t.updated_at)) })]),
      el('div', { class: 'l2' }, l2),
    ]),
  ]);
  // הצעת נושא שממתינה: לחיצה אחת יוצרת אותו ומושכת אליו גם את שאר המטלות עם אותה הצעה
  if (!t.topic_name && t.topic_hint) {
    row.append(el('button', {
      type: 'button', class: 'badge badge--hint', title: 'הקטלוג הציע נושא חדש — לחיצה תיצור אותו',
      text: `💡 ${t.topic_hint}`,
      onclick: (ev) => { ev.stopPropagation(); applyHint(t.id, t.topic_hint); },
    }));
  }
  row.append(el('span', { class: 'go', 'aria-hidden': 'true', text: '‹' }));
  return row;
}

const EMPTY = {
  blocked: 'אין שום דבר שממתין לך. כש-Claude ישאל שאלה — היא תופיע כאן.',
  in_progress: 'אף מטלה לא בעבודה כרגע.',
  open: 'התור ריק.',
  done: 'עוד לא הושלמו מטלות.',
};

function renderList() {
  $('#list').replaceChildren(...state.tasks.map(taskCard));
  const empty = $('#empty');
  empty.hidden = state.tasks.length > 0;
  if (state.tasks.length) return;
  const filtered = state.status !== 'active' || state.search;
  const text = state.search ? 'אין מטלות שמתאימות לחיפוש.'
    : (EMPTY[state.status] || 'אין כאן מטלות. "+ מטלה" — אפשר גם חצי מחשבה, מדייקים אחר כך.');
  // אחרי מענה בסינון "ממתין לך" המטלה יוצאת מהסינון — אומרים את זה ונותנים דרך חזרה, ולא מסך ריק
  empty.replaceChildren(el('p', { text }), ...(filtered ? [el('button', {
    type: 'button', class: 'btn ghost', text: 'לכל המטלות הפעילות', onclick: () => showStatus('active', true),
  })] : []));
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
  set('#replyLabel', 'textContent', task.status === 'blocked' ? 'התשובה שלך ל-Claude' : 'תשובה או הערה');
  set('#dDone', 'hidden', task.status === 'done');
  $('#detail').showModal();
}

on('#replyForm', 'submit', async (e) => {
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

on('#dRun', 'click', async (e) => {
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

on('#dDone', 'click', async () => {
  try {
    await api('update-task', { id: state.openTaskId, status: 'done' });
    $('#detail').close();
    await refresh();
    toast('סומן כהושלם', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── קליטה מהירה ───────────────────────────────────────────── */

on('#captureForm', 'submit', async (e) => {
  e.preventDefault();
  const title = $('#capTitle').value.trim();
  if (!title) return;

  // ‏?. על שדות הבחירה: הוספת מטלה היא הפעולה המרכזית, ואסור שהיא
  // תישבר בגלל שדה משני שחסר מהדף.
  const choice = $('#capTopic')?.value ?? 'auto';
  try {
    const r = await api('create-task', {
      title,
      topic_id: choice === 'auto' || choice === '' ? null : choice,
      auto_topic: choice === 'auto',
      kind: $('#capKind')?.value || 'question',
      priority: $('#capPriority')?.value || 'normal',
      provider: $('#capProvider')?.value || '',
    });
    $('#capTitle').value = '';
    clearGuess();
    $('#newTask').close();
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

function clearGuess() { set('#capHint', 'hidden', true); }

let guessTimer = null;
on('#capTitle', 'input', () => {
  clearTimeout(guessTimer);
  const title = $('#capTitle').value.trim();
  if (($('#capTopic')?.value ?? 'auto') !== 'auto' || title.length < 6) return clearGuess();
  guessTimer = setTimeout(() => guess(title), 400);
});

on('#capTopic', 'change', () => {
  if ($('#capTopic').value !== 'auto') clearGuess();
});

async function guess(title) {
  try {
    const r = await api('classify', { title });
    if (title !== $('#capTitle').value.trim()) return;   // המשתמש המשיך להקליד

    const box = $('#capHint');
    if (!box) return;
    if (r.topic_name) {
      box.textContent = `ישויך ל״${r.topic_name}״`;
      box.className = 'guess guess--hit';
    } else if (state.ai?.has_key && state.ai?.auto_catalog) {
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

on('#catalogBtn', 'click', async (e) => {
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

on('#topicForm', 'submit', async (e) => {
  e.preventDefault();
  try {
    await api('create-topic', { name: $('#topicName').value, repo: $('#topicRepo').value });
    $('#topicName').value = ''; $('#topicRepo').value = '';
    await loadTopics();
    toast('הנושא נוסף', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── סינון וניווט ──────────────────────────────────────────── */

function showStatus(status, clearSearch = false) {
  state.status = status;
  if (clearSearch) { state.search = ''; set('#search', 'value', ''); }
  $$('#statusChips [data-status]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.status === status)));
  refresh();
}

on('#statusChips', 'click', (e) => {
  const tab = e.target.closest('[data-status]');
  if (tab) showStatus(tab.dataset.status);
});

let searchTimer = null;
on('#search', 'input', (e) => {
  state.search = e.target.value;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(refresh, 250);
});

on('#menuBtn', 'click', openDrawer);
on('#drawer', 'click', (e) => { if (e.target === $('#drawer')) closeDrawer(); });
$$('[data-close]').forEach((b) => b.addEventListener('click', () => {
  const t = $(`#${b.dataset.close}`);
  if (t.tagName === 'DIALOG') t.close(); else closeDrawer();
}));

on('#newTaskBtn', 'click', () => {
  $('#newTask').showModal();
  $('#capTitle').focus();
});

on('#logoutBtn', 'click', async () => {
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

on('#topicEditForm', 'submit', async (e) => {
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

on('#teReorder', 'click', () => reorder(false));
on('#teReorderAi', 'click', () => reorder(true));

/* ── הגדרות ────────────────────────────────────────────────── */

on('#settingsBtn', 'click', () => {
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

on('#aiForm', 'submit', async (e) => {
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

on('#aiClear', 'click', async () => {
  try {
    const r = await api('set-ai', { key: '' });
    state.ai = r.ai;
    renderAi();
    toast('המפתח נותק — הקטלוג ממשיך לפי מילות מפתח', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

on('#aiTest', 'click', async () => {
  try {
    const r = await api('test-ai');
    toast(r.message, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

on('#tokenShow', 'click', async () => {
  try {
    const r = await api('worker-token');
    $('#tokenValue').textContent = r.worker_token;
    $('#tokenShow').hidden = true;
  } catch (ex) { toast(ex.message, 'error'); }
});

on('#userForm', 'submit', async (e) => {
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
  if (document.hidden || $$('dialog[open]').length || !$('#drawer').hidden) return;
  if (!state.user) return;
  refresh().catch(() => {});
}, 20000);

boot().catch((ex) => {
  /*
   * מסך הכישלון היחיד שהמשתמש יראה, ולכן הוא צריך לומר מה לעשות ולא רק
   * מה קרה. שתי התקלות השכיחות הן דף ישן במטמון ותקלה בשרת, ולכל אחת
   * יש מסלול משלה.
   */
  document.body.replaceChildren(
    el('div', { class: 'boot-error' }, [
      el('h1', { text: 'הטעינה נכשלה' }),
      el('p', { text: ex.message }),
      el('p', { text: 'נסו רענון מלא: Ctrl+Shift+R (במק: Cmd+Shift+R).' }),
      el('p', {}, [
        el('span', { text: 'אם זה נמשך — ' }),
        el('a', { href: './health.php', text: 'בדיקת הסביבה בשרת' }),
        el('span', { text: ' תראה מה חסר.' }),
      ]),
    ]),
  );
});
