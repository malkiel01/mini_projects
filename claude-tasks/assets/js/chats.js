/*
 * הגשר החי — הממשק (docs/spec.md §5.7): רשימת הצ'אטים של הענף, ומסך שיחה
 * עם צ'אט. ‏out — מה שקרה בצ'אט (משוקף בהוקים); ‏in — מה שנכתב כאן בלוח.
 */
import { $, $$, el, api, toast, state, on, timeAgo } from './core.js';
import { approvalCard } from './approvals.js';
import { renderMarkdown } from './markdown.js';
import { choiceCard } from './choices.js';
import { HEALTH, healthDetail, openHandoff } from './newchat.js';
import { labelChip, openLabelsForMessage, openManageLabels, renderPinStrip, openPinList } from './labels.js';

const POLL_MS = 4000;
const conv = { id: null, channel: null, messages: [], timer: null, busy: false, thinking: new Set() };

/* ── רשימת הערוצים ─────────────────────────────────────────── */

/** טוען את הערוצים של הפרויקט הנוכחי. מחזיר את מספר הפעילים (למונה הלשונית). */
export async function loadChannels() {
  if (!state.projectId) { state.channels = []; return 0; }
  const { channels, hidden_count: hidden } = await api('channels', { project_id: state.projectId, include_hidden: !!state.showHidden });
  state.channels = channels;
  state.hiddenChannels = hidden || 0;
  renderChannels();
  return channels.filter((c) => c.status === 'active').length;
}

/** סמל לפי התפקיד — ניחוש ממילים מוכרות, ואחרת 💬. */
function roleIcon(role) {
  const r = role || '';
  if (/קוד|code|dev/i.test(r)) return '💻';
  if (/אפיון|spec/i.test(r)) return '📝';
  if (/בדיק|qa|test/i.test(r)) return '🧪';
  if (/מתכלל|מנהל|lead|manag/i.test(r)) return '🧭';
  if (/עיצוב|design/i.test(r)) return '🎨';
  return '💬';
}

/** 8 הצבעים הקבועים (§5.11) — גוון; '' — לפי התפקיד. */
const COLORS = { blue: 215, teal: 180, green: 140, olive: 85, amber: 45, orange: 22, red: 355, violet: 270 };
const COLOR_NAME = { blue: 'כחול', teal: 'טורקיז', green: 'ירוק', olive: 'זית', amber: 'ענבר', orange: 'כתום', red: 'אדום', violet: 'סגול' };
const channelHue = (c) => COLORS[c.color] ?? roleHue(c.role);
/** הסמל: מה שנבחר לצ'אט (או מהתבנית), ואחרת — ניחוש לפי התפקיד. */
const channelIcon = (c) => c.icon || roleIcon(c.role);
const ICONS = ['🧭', '📝', '🧩', '💻', '🎨', '🖌️', '✒️', '📐', '📄', '🗂️', '🧪', '🛡️', '☁️', '🔤', '📊', '💬'];

/** גוון קבוע לכל תפקיד — הריבוע הצבעוני. */
function roleHue(role) {
  let h = 0;
  for (const ch of role || '') h = (h * 31 + ch.codePointAt(0)) % 360;
  return h;
}

function channelSub(c) {
  const parts = [];
  // צ'אט המשך (§5.9.4): הישן נשאר קריא, מאפיר, עם מי החליף אותו
  if (c.status === 'replaced') parts.push(`הוחלף ← ${c.replaced_by_name || `#${c.replaced_by}`}`);
  else if (c.status !== 'active') parts.push('🔌 מנותק');
  else parts.push(c.listening ? '🟢 מאזין' : '⚪ לא מאזין');
  const last = c.last_message_at || c.last_seen_at;
  if (last) parts.push(timeAgo(last));
  if (c.open_questions) parts.push(`❓ ${c.open_questions}`);
  if (c.open_choices) parts.push(`🔘 ${c.open_choices} ממתינה לך`);
  if (c.muted) parts.push('🔕 מושתק');
  return parts.join(' · ');
}

export function renderChannels() {
  const list = state.channels || [];
  $('#chanRows').replaceChildren(...list.map((c) => el('li', {
    class: `card${c.status !== 'active' ? ' is-off' : ''}${c.hidden ? ' is-hidden' : ''}`, tabindex: '0', role: 'button',
    'aria-label': `${c.name}${c.role ? ' · ' + c.role : ''}`,
    onclick: () => openConversation(c.id),
    onkeydown: (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openConversation(c.id); } },
  }, [
    el('span', { class: 'ic role-ic', 'aria-hidden': 'true', style: `--hue:${channelHue(c)}`, text: channelIcon(c) }),
    el('div', { class: 'body' }, [
      el('div', { class: 'l1' }, [el('span', { text: c.name })]),
      el('div', { class: 'l2', text: channelSub(c) }),
    ]),
    // "הצ'אט מתארך" (§5.9.3) — רק כשהצ'אט דיווח; בלי דיווח — בלי ניחוש
    c.status === 'active' && HEALTH[c.health] ? el('span', { class: `badge health health--${c.health}`, title: healthDetail(c), text: HEALTH[c.health].chip }) : null,
    c.pending_in ? el('span', { class: 'badge', title: 'ממתינות לצ\'אט', text: `✉️ ${c.pending_in}` }) : null,
    el('span', { class: 'go', 'aria-hidden': 'true', text: '‹' }),
  ])));
  // 🗄 N בארכיון — בתחתית; נגיעה מציגה / מסתירה אותם
  if (state.hiddenChannels) {
    $('#chanRows').append(el('li', { class: 'chan-hidden' }, [el('button', {
      type: 'button', class: 'linklike', 'aria-expanded': String(!!state.showHidden),
      text: state.showHidden ? `הסתרת הארכיון (${state.hiddenChannels})` : `🗄 ${state.hiddenChannels} בארכיון`,
      onclick: () => { state.showHidden = !state.showHidden; loadChannels().catch((ex) => toast(ex.message, 'error')); },
    })]));
  }
  $('#chanEmpty').hidden = list.length > 0 || state.hiddenChannels > 0;
}

/* ── מסך השיחה ─────────────────────────────────────────────── */

const isOwner = () => state.user?.role === 'admin';

/** מי כתב: אתה בצ'אט / אתה בלוח / הצ'אט / המערכת. */
function author(m) {
  if (m.author_role === 'platform') return '☁️ Platform';
  if (m.author_role === 'system') return '☁️ מערכת';
  if (m.direction === 'out') {
    if (m.author_role === 'chat') return '💬 צ\'אט';
    return isOwner() ? '👤 אתה בצ\'אט' : '👤 בעל הלוח בצ\'אט';
  }
  if (m.author_user_id && m.author_user_id === state.user?.id) return '👤 אתה בלוח';
  return `👤 ${m.author_name || 'חבר'} בלוח`;
}

function clock(ts) {
  const d = new Date(ts * 1000);
  const t = d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === new Date().toDateString() ? t
    : `${d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric' })} ${t}`;
}

/** טקסט פשוט מתוך Markdown — לתצוגה מקדימה ולכותרת מטלה. */
function plain(body, max = 140) {
  const t = String(body || '').replace(/```[\s\S]*?```/g, ' [קוד] ').replace(/[`*_>#~|]/g, '').replace(/\s+/g, ' ').trim();
  return t.length > max ? `${t.slice(0, max - 1)}…` : t;
}

function bubble(m) {
  const meta = [el('span', { text: author(m) }), el('time', { text: clock(m.created_at) })];
  if (m.platform_pending) {
    meta.push(el('span', { class: 'b-wait', text: conv.thinking.has(m.id) ? '☁️ חושב…' : '⏳ ממתין ל-Platform' }));
  } else if (m.direction === 'in') {
    meta.push(el('span', {
      class: `tick${m.delivered_at ? ' is-done' : ''}`,
      title: m.delivered_at ? 'נמסרה לצ\'אט' : 'נשלחה — הצ\'אט עוד לא קרא',
      'aria-label': m.delivered_at ? 'נמסרה' : 'נשלחה',
      text: m.delivered_at ? '✓✓' : '✓',
    }));
  }
  // 📌 ותוויות (12c) — בשורת המטא, קטנים
  if (m.pinned?.shared || m.pinned?.mine) meta.push(el('span', { class: 'b-pin', title: m.pinned.shared ? 'מוצמדת לכולם' : 'מוצמדת לך', text: m.pinned.shared ? '📌' : '📌🔒' }));
  (m.labels || []).forEach((l) => meta.push(labelChip(l)));
  // ⋯ — תפריט הפעולות (§5.11, 12b). בטלפון — נגיעה ארוכה על הבועה; הכפתור מופיע ב-hover/focus
  meta.push(el('button', { type: 'button', class: 'b-more', 'aria-label': 'פעולות על ההודעה', 'aria-haspopup': 'menu',
    title: 'פעולות', text: '⋯', onclick: (e) => { e.stopPropagation(); openMsgMenu(m); } }));
  // תשובה להודעה — ציטוט קצר של המקור, נגיעה קופצת אליו
  const orig = m.reply_to ? conv.messages.find((x) => x.id === m.reply_to) : null;
  const kind = { question: '❓ שאלה', answer: '💡 תשובה', decision: '🔐 החלטה', choice: '🔘 שאלת בחירה', choice_answer: '🔘 תשובה' }[m.kind];
  return el('li', { class: `bubble bubble--${m.direction}${m.author_role === 'system' ? ' bubble--sys' : ''}${m.author_role === 'platform' ? ' bubble--platform' : ''}`, dataset: { id: String(m.id) } }, [
    el('div', { class: 'b-meta' }, meta),
    orig && m.kind !== 'choice_answer' ? el('button', { type: 'button', class: 'b-reply', dir: 'auto', title: 'מעבר להודעה',
      onclick: () => scrollToMessage(orig.id) }, [`↩ ${plain(orig.body, 90)}`]) : null,
    kind ? el('span', { class: 'badge', text: kind }) : null,
    m.kind === 'approval' && m.approval?.type
      ? approvalCard(m.approval, m.task_id, { canDecide: m.approval.can_decide, pending: m.approval.pending, onDone: reload })
      // שאלת בחירה (§5.10): כפתור לכל אפשרות; אחרי מענה — מקופלת
      : m.kind === 'choice' && m.meta?.questions
        ? choiceCard(m, { canAnswer: conv.channel?.can_send && conv.channel?.status === 'active', onDone: reload })
      // הצ'אט כותב Markdown — מוצג מעוצב כמו בצ'אט (בונה צמתים, אף פעם לא HTML)
        : el('div', { class: 'b-body md', dir: 'auto' }, [renderMarkdown(m.body)]),
    // Platform לא כותב קוד: כשצריך — מציע מטלה, ונגיעה יוצרת אותה ומריצה (§9)
    m.meta?.task ? el('button', {
      type: 'button', class: 'btn ghost sm b-mktask',
      onclick: (e) => makeTask(m.meta.task, e.currentTarget),
    }, [`📋 הפוך למטלה: ${m.meta.task.title}`]) : null,
    m.task_id ? el('button', {
      type: 'button', class: 'b-task',
      onclick: () => document.dispatchEvent(new CustomEvent('ct:open-task', { detail: m.task_id })),
    }, [el('span', { text: `📋 מטלה #${m.task_id}` }), el('span', { 'aria-hidden': 'true', text: '‹' })]) : null,
  ]);
}

function renderHead() {
  const c = conv.channel;
  $('#cvTitle').textContent = c.name;
  $('#cvSub').textContent = [c.role, c.status === 'active' ? (c.listening_until > Date.now() / 1000 ? '🟢 מאזין' : '⚪ לא מאזין')
    : c.status === 'replaced' ? 'הוחלף' : '🔌 מנותק'].filter(Boolean).join(' · ');
  // פס "הצ'אט מתארך" בראש השיחה, עם 🔁 צ'אט המשך בתוכו
  const h = c.status === 'active' ? HEALTH[c.health] : null;
  $('#cvHealth').hidden = !h;
  if (h) {
    $('#cvHealth').className = `cv-health cv-health--${c.health}`;
    $('#cvHealth').replaceChildren(...[
      el('div', { class: 'body' }, [el('b', { text: h.bar }), el('small', { text: `${healthDetail(c)} · ${h.hint}` })]),
      isOwner() ? el('button', { type: 'button', class: 'btn ghost sm', text: '🔁 צ\'אט המשך', onclick: () => openHandoff(conv.channel) }) : null,
    ].filter(Boolean));
  }

  const blocked = c.status === 'replaced' ? 'הצ\'אט הוחלף בצ\'אט המשך — אי אפשר לכתוב אליו. השיחה נשארת לקריאה.'
    : c.status !== 'active' ? 'הצ\'אט מנותק — אי אפשר לכתוב אליו.'
    : !c.can_send ? 'אין לך הרשאה לכתוב לצ\'אט הזה (צפייה בלבד).' : '';
  $('#cvNote').hidden = !blocked;
  $('#cvNote').textContent = blocked;
  $('#chatInput').disabled = !!blocked;
  $('#chatSend').disabled = !!blocked;
  $('#chatInput').placeholder = c.route_effective === 'platform' ? 'הודעה — ☁️ Platform יענה כאן' : 'הודעה לצ\'אט';
}

/* ── ☁️ Platform עונה (§9) ───────────────────────────────────── */

/** עונה על הודעה שממתינה ל-Platform — בתוך הבקשה. בזמן הזה: "☁️ חושב…". */
async function platformAnswer(id) {
  if (conv.thinking.has(id)) return;
  conv.thinking.add(id);
  renderLog(true);
  try {
    const r = await api('platform-reply', { message_id: id });
    if (r.handed_to_chat) toast(`Platform לא ענה — עבר לצ'אט: ${r.reason}`, '');
  } catch (ex) { toast(ex.message, 'error'); }
  conv.thinking.delete(id);
  await poll(true);
}

/** בפתיחה: מה שנשאר "ממתין ל-Platform" (המסך נסגר באמצע) — משלימים, אחד אחד. */
async function completePending() {
  const ids = conv.messages.filter((m) => m.platform_pending && !conv.thinking.has(m.id)).map((m) => m.id);
  for (const id of ids) { if (conv.id) await platformAnswer(id); }
}

async function makeTask(task, btn) {
  if (!confirm(`ליצור מטלה "${task.title}" ולהריץ אותה במנוע?`)) return;
  btn.disabled = true;
  try {
    const { id } = await api('create-task', { title: task.title, body: task.body || '', project_id: conv.channel.project_id });
    try {
      await api('run-task', { id });
      toast(`נוצרה מטלה #${id} ונשלחה להרצה`, 'ok');
    } catch (ex) { toast(`נוצרה מטלה #${id}, אבל ההרצה לא התחילה: ${ex.message}`, 'error'); }
  } catch (ex) { toast(ex.message, 'error'); btn.disabled = false; }
}

function renderLog(stick) {
  const log = $('#chatLog');
  const atEnd = stick || log.scrollHeight - log.scrollTop - log.clientHeight < 80;
  log.replaceChildren(...(conv.messages.length ? conv.messages.map(bubble)
    : [el('li', { class: 'empty', text: 'עוד אין הודעות. מה שתכתוב כאן יגיע לצ\'אט ויעיר אותו.' })]));
  if (atEnd) log.scrollTop = log.scrollHeight;
}

/** ‏after — מהמוקדמת שעוד לא נמסרה, כדי ש-✓ יתעדכן ל-✓✓. */
function afterId() {
  // גם שאלת בחירה פתוחה — כדי שתשובה שהוקלדה בצ'אט תקפל את הבועה
  const pending = conv.messages.find((m) => (m.direction === 'in' && !m.delivered_at) || (m.kind === 'choice' && m.status === 'open'));
  const last = conv.messages.length ? conv.messages[conv.messages.length - 1].id : 0;
  return pending ? Math.min(pending.id - 1, last) : last;
}

async function poll(stick = false) {
  if (!conv.id || conv.busy) return;
  conv.busy = true;
  try {
    const { channel, messages } = await api('channel-messages', { channel_id: conv.id, after: afterId() });
    conv.channel = channel;
    const byId = new Map(conv.messages.map((m) => [m.id, m]));
    // ✓ → ✓✓, ושאלת בחירה שנענתה (גם בצ'אט) — מציירים מחדש
    const fresh = messages.some((m) => !byId.has(m.id) || byId.get(m.id).delivered_at !== m.delivered_at
      || byId.get(m.id).status !== m.status);
    messages.forEach((m) => byId.set(m.id, m));
    conv.messages = [...byId.values()].sort((a, b) => a.id - b.id);
    renderHead();
    if (fresh || stick) renderLog(stick);
  } catch (ex) {
    if (stick) toast(ex.message, 'error');
  } finally { conv.busy = false; }
}

/** אחרי החלטה — טוען הכול מחדש, כדי שהבועה הישנה תראה "הוחלט". */
function reload() {
  conv.messages = [];
  poll(true);
}

function startTimer() {
  stopTimer();
  conv.timer = setInterval(() => { if (!document.hidden) poll(); }, POLL_MS);
}
function stopTimer() { if (conv.timer) clearInterval(conv.timer); conv.timer = null; }

export async function openConversation(id) {
  Object.assign(conv, { id, channel: null, messages: [], pins: [] });
  setReply(null);
  $('#cvPins').hidden = true;
  $('#chatLog').replaceChildren();
  $('#chatInput').value = '';
  const c = (state.channels || []).find((x) => x.id === id);
  if (c) { conv.channel = { ...c, can_send: true }; renderHead(); }
  $('#chatView').showModal();
  await poll(true);
  loadPins();
  startTimer();
  completePending();
}

on('#chatView', 'close', () => {
  stopTimer();
  conv.id = null;
  loadChannels().catch(() => {});
});
document.addEventListener('visibilitychange', () => { if (!document.hidden && conv.id) poll(); });

on('#chatForm', 'submit', async (e) => {
  e.preventDefault();
  const body = $('#chatInput').value.trim();
  if (!body || !conv.id) return;
  $('#chatSend').disabled = true;
  try {
    const { id: sent } = await api('channel-send', { channel_id: conv.id, body, ...(conv.replyTo ? { reply_to: conv.replyTo.id } : {}) });
    setReply(null);
    $('#chatInput').value = '';
    autosize();
    await poll(true);
    // במסלול ☁️ — Platform עונה עכשיו, בתוך הבקשה
    if (conv.messages.find((m) => m.id === sent)?.platform_pending) platformAnswer(sent);
  } catch (ex) { toast(ex.message, 'error'); }
  $('#chatSend').disabled = false;
  $('#chatInput').focus();
});

// Ctrl/⌘+Enter — שליחה. Enter לבד — שורה חדשה (בטלפון אין דרך אחרת לרדת שורה)
on('#chatInput', 'keydown', (e) => {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); $('#chatForm').requestSubmit(); }
});
function autosize() {
  const t = $('#chatInput');
  t.style.height = 'auto';
  t.style.height = `${Math.min(t.scrollHeight, 160)}px`;
}
on('#chatInput', 'input', autosize);


/* ── ⚙ הגדרות הצ'אט (§5.11, שלב 12a) ─────────────────────────────── */

const cs = { info: null };
const ROUTE_LABEL = { chat: '💬 הצ\'אט', platform: '☁️ Platform' };

async function loadInfo() {
  const { channel } = await api('channel-info', { channel_id: conv.id });
  cs.info = channel;
  return channel;
}

function renderSettings() {
  const c = cs.info;
  const status = c.status === 'active' ? (c.listening ? '🟢 מאזין' : '⚪ לא מאזין')
    : c.status === 'replaced' ? `הוחלף ← ${c.replaced_by_name || '#' + c.replaced_by}` : '🔌 מנותק';
  $('#csCard').replaceChildren(
    el('span', { class: 'ic role-ic', 'aria-hidden': 'true', style: `--hue:${channelHue(c)}`, text: channelIcon(c) }),
    el('div', { class: 'body' }, [
      el('b', {}, [c.name, ' ', el('span', { class: 'pid', dir: 'ltr', text: `ערוץ #${c.id}` })]),
      el('span', { class: 'l2', text: `${c.project_path} · #${c.project_id}` }),
      el('div', { class: 'chips' }, [el('span', { class: 'badge', text: status }),
        c.muted ? el('span', { class: 'badge', text: '🔕 מושתק' }) : null].filter(Boolean)),
    ]),
  );
  const active = c.status === 'active';
  const health = HEALTH[c.health];
  const rows = [
    ['ℹ️', 'על הצ\'אט', `${c.role || 'בלי תפקיד'} · נראה ${c.last_seen_at ? timeAgo(c.last_seen_at) : 'אף פעם'}`, () => openAbout()],
    c.can_edit && ['✏️', 'שם, סמל וצבע', `${channelIcon(c)} ${c.name}${c.color ? ' · ' + COLOR_NAME[c.color] : ''}`, () => openEdit()],
    ['📌', 'מוצמדות', conv.pins?.length ? `${conv.pins.length} · ${conv.pins.filter((x) => x.visibility === 'private').length ? 'כולל שלך 🔒' : 'לכולם'}` : 'אין', () => openPinList(conv.pins || [], (id) => { $('#chatSettings').close(); scrollToMessage(id); })],
    ['🏷', 'ניהול תוויות', 'יצירה, עריכה ומחיקה — משותפות ופרטיות', () => openManageLabels(conv.channel, () => reload())],
    ['🔔', 'השתקה', c.muted ? 'מושתק — לא נספר ב"ממתין לך" · נגיעה מבטלת' : 'כבויה — נגיעה משתיקה', () => toggleMute(), { pressed: c.muted }],
    isOwner() && ['🧭', 'מי עונה', `${c.route_own ? ROUTE_LABEL[c.route_own] : 'כמו הפרויקט'} · בפועל: ${ROUTE_LABEL[c.route_effective]}`, () => openRoute()],
    c.can_edit && active && ['🔘', 'שאלות בחירה', c.questions === 'app' ? 'באפליקציה' : 'בלוח', () => openQuestions()],
    active && ['📏', 'אורך הצ\'אט', c.health_at ? `${health ? health.chip + ' · ' : ''}${healthDetail(c)}` : 'עוד לא דיווח',
      isOwner() ? () => { $('#chatSettings').close(); openHandoff(conv.channel); } : null, { sub2: isOwner() ? '🔁 צ\'אט המשך' : '' }],
  ].filter(Boolean);
  $('#csRows').replaceChildren(...rows.map(([ic, title, sub, fn, opt = {}]) => el('li', {
    class: `card${fn ? '' : ' is-static'}`, tabindex: fn ? '0' : null, role: fn ? 'button' : null, 'aria-label': title,
    'aria-pressed': opt.pressed === undefined ? null : String(opt.pressed),
    onclick: fn, onkeydown: fn ? (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); fn(); } } : null,
  }, [
    el('span', { class: 'ic', 'aria-hidden': 'true', text: opt.pressed === undefined ? ic : (opt.pressed ? '🔕' : '🔔') }),
    el('div', { class: 'body' }, [el('div', { class: 'l1' }, [el('span', { text: title })]), el('div', { class: 'l2', text: sub }),
      opt.sub2 ? el('div', { class: 'l2 cs-action', text: opt.sub2 }) : null].filter(Boolean)),
    fn ? el('span', { class: 'go', 'aria-hidden': 'true', text: opt.pressed === undefined ? '‹' : '' }) : null,
  ].filter(Boolean))));
  // אזור אדום — בעל הלוח: ניתוק (רק צ'אט פעיל), ארכיון / החזרה
  $('#csDanger').hidden = !isOwner();
  $('#csDisconnect').hidden = !active;
  $('#csHide').textContent = c.hidden ? '↩ החזרה מהארכיון' : '🗄 ארכיון';
}

async function openSettings() {
  if (!conv.id) return;
  try { await loadInfo(); } catch (ex) { toast(ex.message, 'error'); return; }
  renderSettings();
  $('#chatSettings').showModal();
}
on('#cvSettings', 'click', openSettings);

function openAbout() {
  const c = cs.info;
  const when = (ts) => (ts ? `${new Date(ts * 1000).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' })} (${timeAgo(ts)})` : '—');
  const rows = [
    ['שם', c.name], ['תפקיד', c.role || '—'], ['פרויקט', `${c.project_path} · #${c.project_id}`], ['ערוץ', `#${c.id}`],
    ['סשן', c.session_url ? el('a', { href: c.session_url, target: '_blank', rel: 'noopener', dir: 'ltr', text: c.session_id }) : c.session_id],
    ['נרשם', when(c.created_at)], ['נראה לאחרונה', when(c.last_seen_at)],
    ['מאזין', c.status !== 'active' ? 'לא — הצ\'אט אינו פעיל' : c.listening ? `כן, עד ${new Date(c.listening_until * 1000).toLocaleTimeString('he-IL', { timeStyle: 'short' })}` : 'לא כרגע'],
    ['מי עונה', `${ROUTE_LABEL[c.route_effective]}${c.route_own ? '' : ' (כמו הפרויקט)'}`],
    ['שאלות בחירה', c.questions === 'app' ? 'באפליקציה' : 'בלוח'],
    ['אורך', c.health_at ? healthDetail(c) : 'עוד לא דיווח'],
  ];
  $('#csAboutList').replaceChildren(...rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { dir: 'auto' }, [v])]));
  $('#csAbout').showModal();
}

function renderColors(selected) {
  $('#csColors').replaceChildren(...['', ...Object.keys(COLORS)].map((k) => el('button', {
    type: 'button', role: 'radio', class: 'cs-color', 'aria-checked': String(k === selected), dataset: { color: k },
    'aria-label': k ? COLOR_NAME[k] : 'לפי התפקיד', title: k ? COLOR_NAME[k] : 'לפי התפקיד',
    style: `--hue:${k ? COLORS[k] : roleHue(cs.info.role)}`,
    onclick: () => renderColors(k),
  }, [k ? '' : 'אוטו'])));
}
function renderIcons(selected) {
  $('#csIcon').value = ICONS.includes(selected) ? '' : selected;
  $('#csIcons').replaceChildren(...['', ...ICONS].map((k) => el('button', {
    type: 'button', role: 'radio', class: 'cs-icon', 'aria-checked': String(k === selected), dataset: { icon: k },
    'aria-label': k || 'לפי התפקיד', title: k || 'לפי התפקיד', onclick: () => renderIcons(k),
  }, [k || 'אוטו'])));
}
on('#csIcon', 'input', (e) => {
  const v = e.target.value.trim();
  $$('#csIcons .cs-icon').forEach((b) => b.setAttribute('aria-checked', String(v === '' ? b.dataset.icon === '' : false)));
});
function openEdit() {
  $('#csName').value = cs.info.name;
  $('#csRole').value = cs.info.role || '';
  renderIcons(cs.info.icon || '');
  renderColors(cs.info.color || '');
  $('#csEdit').showModal();
}
on('#csEditForm', 'submit', async (e) => {
  e.preventDefault();
  const color = $('#csColors [aria-checked="true"]')?.dataset.color ?? '';
  const icon = $('#csIcon').value.trim() || ($('#csIcons [aria-checked="true"]')?.dataset.icon ?? '');
  try {
    const { channel } = await api('channel-update', { channel_id: conv.id, name: $('#csName').value, role: $('#csRole').value, color, icon });
    cs.info = channel;
    $('#csEdit').close();
    renderSettings();
    await poll(true);
    toast('נשמר', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

async function toggleMute() {
  try {
    const { muted } = await api('channel-mute', { channel_id: conv.id, muted: !cs.info.muted });
    cs.info.muted = muted;
    renderSettings();
    toast(muted ? 'הצ\'אט הושתק — לא ייספר ב"ממתין לך"' : 'ההשתקה בוטלה', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
}

function renderRouteSeg() {
  const own = cs.info.route_own || '';
  $$('#csRouteSeg button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.route === own)));
  $('#csRouteHint').textContent = `בפועל עכשיו: ${ROUTE_LABEL[cs.info.route_effective]}. "כמו הפרויקט" — לפי ⚙ של הפרויקט. ☁️ Platform עונה רק כשיש מכסה; בלעדיה — הצ'אט.`;
}
function openRoute() { renderRouteSeg(); $('#csRoute').showModal(); }
on('#csRouteSeg', 'click', async (e) => {
  const b = e.target.closest('button[data-route]');
  if (!b) return;
  try {
    const { channel } = await api('channel-update', { channel_id: conv.id, route: b.dataset.route });
    cs.info = channel;
    renderRouteSeg();
    renderSettings();
    await poll(true);
  } catch (ex) { toast(ex.message, 'error'); }
});

function renderQuestionsSeg() {
  const mode = cs.info?.questions || conv.channel?.questions || 'board';
  $$('#cvQuestionsSeg button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.mode === mode)));
}
function openQuestions() { renderQuestionsSeg(); $('#csQuestions').showModal(); }
on('#cvQuestionsSeg', 'click', async (e) => {
  const b = e.target.closest('button[data-mode]');
  if (!b || !conv.id) return;
  try {
    await api('channel-questions', { channel_id: conv.id, mode: b.dataset.mode });
    cs.info.questions = b.dataset.mode;
    conv.channel.questions = b.dataset.mode;
    renderQuestionsSeg();
    renderSettings();
    toast(b.dataset.mode === 'board' ? 'שאלות בחירה יגיעו ללוח' : 'שאלות בחירה ייפתחו בחלון באפליקציה', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
});

on('#csDisconnect', 'click', async () => {
  const c = cs.info;
  if (!c || !confirm(`לנתק את "${c.name}"? האסימון שלו ייפסל, והוא יצטרך להירשם מחדש.`)) return;
  try {
    await api('channel-archive', { channel_id: c.id });
    toast('הצ\'אט נותק', 'ok');
    await loadInfo(); renderSettings(); await poll(true);
  } catch (ex) { toast(ex.message, 'error'); }
});
on('#csHide', 'click', async () => {
  const c = cs.info;
  const hide = !c.hidden;
  if (hide && !confirm(`להעביר את "${c.name}" לארכיון?${c.status === 'active' ? ' הוא מחובר — הוא יתנתק קודם.' : ''} אפשר להחזיר אותו אחר כך.`)) return;
  try {
    await api('channel-hide', { channel_id: c.id, hidden: hide });
    toast(hide ? 'הועבר לארכיון' : 'הוחזר מהארכיון', 'ok');
    $('#chatSettings').close();
    if (hide) $('#chatView').close(); else { await loadInfo(); renderSettings(); }
  } catch (ex) { toast(ex.message, 'error'); }
});

/* ── פעולות על הודעה (§5.11, שלב 12b) ─────────────────────────────── */

function setReply(m) {
  conv.replyTo = m;
  $('#cvReply').hidden = !m;
  $('#cvReplyText').textContent = m ? plain(m.body, 80) : '';
}
on('#cvReplyX', 'click', () => { setReply(null); $('#chatInput').focus(); });

export function scrollToMessage(id) {
  const n = $(`#chatLog [data-id="${id}"]`);
  if (!n) { toast('ההודעה לא נטענה במסך הזה', ''); return false; }
  n.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  n.classList.remove('is-flash'); void n.offsetWidth; n.classList.add('is-flash');
  return true;
}

function messageLink(m) {
  return `${location.origin}${location.pathname}#c${conv.id}-m${m.id}`;
}

async function copy(text, done) {
  try { await navigator.clipboard.writeText(text); toast(done, 'ok'); } catch { toast('ההעתקה נחסמה בדפדפן', 'error'); }
}

async function makeTaskFrom(m) {
  const first = plain(m.body.split('\n').find((l) => l.trim()) || m.body, 120);
  if (!confirm(`ליצור מטלה מההודעה?\n\n"${first}"`)) return;
  try {
    const { id } = await api('create-task', {
      title: first, project_id: conv.channel.project_id,
      body: `${m.body}\n\n— מהצ'אט "${conv.channel.name}", הודעה #${m.id}`,
    });
    toast(`נוצרה מטלה #${id}`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
}

function openMsgMenu(m) {
  const c = conv.channel || {};
  const canSend = c.can_send && c.status === 'active';
  const act = (icon, label, fn, hint = '') => el('button', {
    type: 'button', class: 'mm-act', role: 'menuitem',
    onclick: async () => { $('#msgMenu').close(); await fn(); },
  }, [el('span', { class: 'ic', 'aria-hidden': 'true', text: icon }), el('span', { class: 'body' }, [el('b', { text: label }), hint ? el('small', { text: hint }) : null].filter(Boolean))]);
  $('#mmT').textContent = `${author(m)} · ${clock(m.created_at)}`;
  $('#mmPreview').textContent = plain(m.body, 180);
  $('#mmActions').replaceChildren(...[
    act('📋', 'העתקה', () => copy(m.body, 'הטקסט הועתק'), 'הטקסט המקורי, עם העיצוב (Markdown)'),
    canSend ? act('↩', 'תשובה', () => { setReply(m); $('#chatInput').focus(); }, 'ההודעה הבאה שלך תצוטט כתשובה לזו') : null,
    canSend ? act('📌', m.pinned?.shared ? 'ביטול הצמדה לכולם' : 'הצמד לכולם', () => pin(m, 'shared', !m.pinned?.shared), 'בפס בראש השיחה, אצל כל מי שרואה אותה') : null,
    act('📌', m.pinned?.mine ? 'ביטול ההצמדה שלי' : 'הצמד לי', () => pin(m, 'private', !m.pinned?.mine), '🔒 רק אתה רואה'),
    act('🏷', 'תוויות', () => openLabelsForMessage(conv.channel, m, () => renderLog(false)), (m.labels || []).map((l) => l.name).join(' · ') || 'סימון ההודעה בתווית'),
    act('➕', 'הפיכה למטלה', () => makeTaskFrom(m), 'מטלה בפרויקט של הצ\'אט, עם ההודעה בגוף'),
    act('🔗', 'העתקת קישור', () => copy(messageLink(m), 'הקישור הועתק'), 'פותח את השיחה בהודעה הזו'),
  ].filter(Boolean));
  $('#msgMenu').showModal();
  $('#mmActions .mm-act')?.focus();
}

// נגיעה ארוכה על בועה (טלפון) — חצי שנייה, בלי תזוזה; לא על כפתורים וקישורים בתוכה
(() => {
  let timer = null, sx = 0, sy = 0;
  const cancel = () => { clearTimeout(timer); timer = null; };
  const log = $('#chatLog');
  log.addEventListener('pointerdown', (e) => {
    if (e.pointerType === 'mouse') return;
    const b = e.target.closest('.bubble');
    if (!b || e.target.closest('button, a, input, textarea, summary, pre, .md-table')) return;
    sx = e.clientX; sy = e.clientY;
    cancel();
    timer = setTimeout(() => {
      timer = null;
      const m = conv.messages.find((x) => x.id === Number(b.dataset.id));
      if (m) { navigator.vibrate?.(10); openMsgMenu(m); }
    }, 500);
  });
  log.addEventListener('pointermove', (e) => { if (timer && Math.hypot(e.clientX - sx, e.clientY - sy) > 10) cancel(); });
  ['pointerup', 'pointercancel', 'scroll'].forEach((t) => log.addEventListener(t, cancel, { passive: true }));
  // בחלק מהדפדפנים נגיעה ארוכה = contextmenu; בעכבר — קליק ימני פותח את אותו תפריט
  log.addEventListener('contextmenu', (e) => {
    const b = e.target.closest('.bubble');
    if (!b || e.target.closest('a, pre, .md-table') || getSelection().toString()) return;
    e.preventDefault(); cancel();
    const m = conv.messages.find((x) => x.id === Number(b.dataset.id));
    if (m) openMsgMenu(m);
  });
})();

/** קישור להודעה (#c<ערוץ>-m<הודעה>) — פותח את השיחה וקופץ אליה. */
export async function openFromHash() {
  const h = location.hash.match(/^#c(\d+)-m(\d+)$/);
  if (!h) return;
  history.replaceState(null, '', location.pathname + location.search);
  try {
    await openConversation(Number(h[1]));
    if (!scrollToMessage(Number(h[2]))) {
      // הודעה ישנה מחוץ לטעינה הראשונה — טוענים הכול
      conv.messages = []; await poll(true); scrollToMessage(Number(h[2]));
    }
  } catch (ex) { toast(ex.message, 'error'); }
}
window.addEventListener('hashchange', openFromHash);

/* ── 📌 הצמדה (§5.11, 12c) ──────────────────────────────────────── */

async function loadPins() {
  if (!conv.id) return;
  try {
    const { pins } = await api('channel-pins', { channel_id: conv.id });
    conv.pins = pins;
    renderPinStrip(pins, (id) => scrollToMessage(id));
  } catch { /* הפס הוא תוספת — כשל בו לא עוצר את השיחה */ }
}

async function pin(m, visibility, pinned) {
  try {
    await api('message-pin', { message_id: m.id, visibility, pinned });
    m.pinned = { ...(m.pinned || {}), [visibility === 'shared' ? 'shared' : 'mine']: pinned };
    renderLog(false);
    await loadPins();
    toast(pinned ? (visibility === 'shared' ? 'הוצמדה לכולם' : 'הוצמדה לך') : 'ההצמדה בוטלה', 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
}
