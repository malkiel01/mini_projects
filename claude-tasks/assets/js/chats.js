/*
 * הגשר החי — הממשק (docs/spec.md §5.7): רשימת הצ'אטים של הענף, ומסך שיחה
 * עם צ'אט. ‏out — מה שקרה בצ'אט (משוקף בהוקים); ‏in — מה שנכתב כאן בלוח.
 */
import { $, el, api, toast, state, on, timeAgo } from './core.js';
import { approvalCard } from './approvals.js';

const POLL_MS = 4000;
const conv = { id: null, channel: null, messages: [], timer: null, busy: false };

/* ── רשימת הערוצים ─────────────────────────────────────────── */

/** טוען את הערוצים של הפרויקט הנוכחי. מחזיר את מספר הפעילים (למונה הלשונית). */
export async function loadChannels() {
  if (!state.projectId) { state.channels = []; return 0; }
  const { channels } = await api('channels', { project_id: state.projectId });
  state.channels = channels;
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

/** גוון קבוע לכל תפקיד — הריבוע הצבעוני. */
function roleHue(role) {
  let h = 0;
  for (const ch of role || '') h = (h * 31 + ch.codePointAt(0)) % 360;
  return h;
}

function channelSub(c) {
  const parts = [];
  if (c.status !== 'active') parts.push('🔌 מנותק');
  else parts.push(c.listening ? '🟢 מאזין' : '⚪ לא מאזין');
  const last = c.last_message_at || c.last_seen_at;
  if (last) parts.push(timeAgo(last));
  if (c.open_questions) parts.push(`❓ ${c.open_questions}`);
  return parts.join(' · ');
}

export function renderChannels() {
  const list = state.channels || [];
  $('#chanRows').replaceChildren(...list.map((c) => el('li', {
    class: `card${c.status !== 'active' ? ' is-off' : ''}`, tabindex: '0', role: 'button',
    'aria-label': `${c.name}${c.role ? ' · ' + c.role : ''}`,
    onclick: () => openConversation(c.id),
    onkeydown: (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openConversation(c.id); } },
  }, [
    el('span', { class: 'ic role-ic', 'aria-hidden': 'true', style: `--hue:${roleHue(c.role)}`, text: roleIcon(c.role) }),
    el('div', { class: 'body' }, [
      el('div', { class: 'l1' }, [el('span', { text: c.name })]),
      el('div', { class: 'l2', text: channelSub(c) }),
    ]),
    c.pending_in ? el('span', { class: 'badge', title: 'ממתינות לצ\'אט', text: `✉️ ${c.pending_in}` }) : null,
    el('span', { class: 'go', 'aria-hidden': 'true', text: '‹' }),
  ])));
  $('#chanEmpty').hidden = list.length > 0;
}

/* ── מסך השיחה ─────────────────────────────────────────────── */

const isOwner = () => state.user?.role === 'admin';

/** מי כתב: אתה בצ'אט / אתה בלוח / הצ'אט / המערכת. */
function author(m) {
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

function bubble(m) {
  const meta = [el('span', { text: author(m) }), el('time', { text: clock(m.created_at) })];
  if (m.direction === 'in') {
    meta.push(el('span', {
      class: `tick${m.delivered_at ? ' is-done' : ''}`,
      title: m.delivered_at ? 'נמסרה לצ\'אט' : 'נשלחה — הצ\'אט עוד לא קרא',
      'aria-label': m.delivered_at ? 'נמסרה' : 'נשלחה',
      text: m.delivered_at ? '✓✓' : '✓',
    }));
  }
  const kind = { question: '❓ שאלה', answer: '💡 תשובה', decision: '🔐 החלטה' }[m.kind];
  return el('li', { class: `bubble bubble--${m.direction}${m.author_role === 'system' ? ' bubble--sys' : ''}`, dataset: { id: String(m.id) } }, [
    el('div', { class: 'b-meta' }, meta),
    kind ? el('span', { class: 'badge', text: kind }) : null,
    m.kind === 'approval' && m.approval?.type
      ? approvalCard(m.approval, m.task_id, { canDecide: m.approval.can_decide, pending: m.approval.pending, onDone: reload })
      : el('div', { class: 'b-body', dir: 'auto', text: m.body }),
    m.task_id ? el('button', {
      type: 'button', class: 'b-task',
      onclick: () => document.dispatchEvent(new CustomEvent('ct:open-task', { detail: m.task_id })),
    }, [el('span', { text: `📋 מטלה #${m.task_id}` }), el('span', { 'aria-hidden': 'true', text: '‹' })]) : null,
  ]);
}

function renderHead() {
  const c = conv.channel;
  $('#cvTitle').textContent = c.name;
  $('#cvSub').textContent = [c.role, c.status === 'active' ? (c.listening_until > Date.now() / 1000 ? '🟢 מאזין' : '⚪ לא מאזין') : '🔌 מנותק']
    .filter(Boolean).join(' · ');
  $('#cvArchive').hidden = !(isOwner() && c.status === 'active');
  const blocked = c.status !== 'active' ? 'הצ\'אט מנותק — אי אפשר לכתוב אליו.'
    : !c.can_send ? 'אין לך הרשאה לכתוב לצ\'אט הזה (צפייה בלבד).' : '';
  $('#cvNote').hidden = !blocked;
  $('#cvNote').textContent = blocked;
  $('#chatInput').disabled = !!blocked;
  $('#chatSend').disabled = !!blocked;
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
  const pending = conv.messages.find((m) => m.direction === 'in' && !m.delivered_at);
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
    const fresh = messages.some((m) => !byId.has(m.id) || byId.get(m.id).delivered_at !== m.delivered_at);
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
  Object.assign(conv, { id, channel: null, messages: [] });
  $('#chatLog').replaceChildren();
  $('#chatInput').value = '';
  const c = (state.channels || []).find((x) => x.id === id);
  if (c) { conv.channel = { ...c, can_send: true }; renderHead(); }
  $('#chatView').showModal();
  await poll(true);
  startTimer();
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
    await api('channel-send', { channel_id: conv.id, body });
    $('#chatInput').value = '';
    autosize();
    await poll(true);
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

on('#cvArchive', 'click', async () => {
  const c = conv.channel;
  if (!c || !confirm(`לנתק את "${c.name}"? האסימון שלו ייפסל, והוא יצטרך להירשם מחדש.`)) return;
  try {
    await api('channel-archive', { channel_id: c.id });
    toast('הצ\'אט נותק', 'ok');
    await poll(true);
  } catch (ex) { toast(ex.message, 'error'); }
});
