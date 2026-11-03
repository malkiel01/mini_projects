/*
 * הגשר החי — הממשק (docs/spec.md §5.7): רשימת הצ'אטים של הענף, ומסך שיחה
 * עם צ'אט. ‏out — מה שקרה בצ'אט (משוקף בהוקים); ‏in — מה שנכתב כאן בלוח.
 */
import { $, $$, el, api, toast, state, on, timeAgo } from './core.js';
import { approvalCard } from './approvals.js';
import { renderMarkdown } from './markdown.js';
import { choiceCard } from './choices.js';
import { permCard, permFromMessage, refreshPermStrip } from './permissions.js';
import { HEALTH, healthDetail, openHandoff, mmss } from './newchat.js';
import { labelChip, openLabelsForMessage, openManageLabels, renderPinStrip, openPinList, pinsMode, setPinsMode } from './labels.js';
import { needs as trNeeds, lang as trLang, TRANSLATE_LANGS, engine as trEngine, translate, cached as trCached, setShow as trSetShow } from './translate.js';

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
  // §5.10.1: חלון הרשאה פתוח באפליקציה; גשר ישן שצריך הוראת עדכון
  if (c.status === 'active' && c.waiting_at) parts.push(`⏸ ממתין לאישור באפליקציה${c.waiting_tool ? `: ${c.waiting_tool}` : ''}`);
  if (c.bridge_outdated) parts.push('⚠ גרסה ישנה — צריך עדכון');
  // §5.13.3: Routine — יש לו את השער של הלוח? (מגרסת הגשר 2026-11-03.2, שמדווחת ב-channel-health)
  if (c.role === 'routine' && c.status === 'active') parts.push((c.bridge_version || '') >= '2026-11-03.2' ? '🛡 שער פעיל' : '⚠ בלי שער');
  const last = c.last_message_at || c.last_seen_at;
  if (last) parts.push(timeAgo(last));
  if (c.open_questions) parts.push(`❓ ${c.open_questions}`);
  if (c.open_choices) parts.push(`🔘 ${c.open_choices} ממתינה לך`);
  if (c.open_permissions) parts.push(`🔐 ${c.open_permissions} ממתינה לאישור`);
  if (c.muted) parts.push('🔕 מושתק');
  return parts.join(' · ');
}

let chanHandoffTimer = null;
const laneRunning = new Set();
async function laneRunOnce(id) {
  if (laneRunning.has(id)) return;
  laneRunning.add(id);
  try { const r = await api('lane-run', { channel_id: id }); if (r.ran?.length) loadChannels().catch(() => {}); }
  catch { /* בפעם הבאה שהלוח נפתח */ }
  laneRunning.delete(id);
}
setInterval(() => {
  for (const b of document.querySelectorAll('.ho-live .ho-t')) b.textContent = mmss(Math.floor(Date.now() / 1000) - Number(b.closest('.ho-live').dataset.at));
}, 1000);
document.addEventListener('ct:reload-channels', () => { if (state.projectId) loadChannels().catch(() => {}); });

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
    // §15.12.1: הצ'אט לא עונה / הגיע למגבלה
    c.status === 'active' && c.unresponsive_since > 0 ? el('span', { class: 'badge badge--blocked', title: c.route_effective === 'platform' ? '☁️ Platform עונה במקומו' : 'ההודעות ממתינות',
      text: c.unresponsive_reason === 'limit' ? '🔴 מגבלה' : '🔴 לא עונה' }) : null,
    // §15.13: צ'אט המשך ברקע — טיימר חי; התקציר מוכן — שבב שפותח את הגיליון (לא נפתח מעצמו)
    c.handoff?.state === 'waiting' ? el('span', { class: 'badge ho-live', dataset: { at: String(c.handoff.requested_at) }, title: 'מכין תקציר לצ\'אט המשך' }, [
      '🔁 ', el('span', { class: 'ho-word', text: 'מכין תקציר… ' }),
      el('span', { class: 'ho-t', text: mmss(Math.floor(Date.now() / 1000) - c.handoff.requested_at) })]) : null,
    c.handoff?.state === 'ready' ? el('button', { type: 'button', class: 'badge ho-ready', text: '✅ התקציר מוכן', 'aria-label': 'התקציר מוכן — לפתוח צ\'אט המשך',
      onclick: (e) => { e.stopPropagation(); openHandoff(c); } }) : null,
    el('span', { class: 'go', 'aria-hidden': 'true', text: '‹' }),
  ])));
  // §15.12.1: מה שעבר ל-☁️ כשהצ'אט לא ענה — הלוח מפעיל את Platform (אין cron; מי שפתח את הלוח מפעיל)
  for (const c of list) if (c.platform_queue > 0) laneRunOnce(c.id);
  // יש העברה שממתינה — בודקים שוב בעוד 15 שנ' (כך השבב "מוכן" מופיע גם כשהגיליון סגור)
  clearTimeout(chanHandoffTimer);
  if (list.some((c) => c.handoff?.state === 'waiting')) chanHandoffTimer = setTimeout(() => loadChannels().catch(() => {}), 15000);
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
  // שלב 17: תג מסלול ומצב — מהשרת (handled_by / answered_by), לא מהטקסט
  const lt = laneTag(m);
  if (lt) meta.push(lt);
  // 📌 ותוויות (12c) — בשורת המטא, קטנים
  if (m.pinned?.shared || m.pinned?.mine) meta.push(el('span', { class: 'b-pin', title: m.pinned.shared ? 'מוצמדת לכולם' : 'מוצמדת לך', text: m.pinned.shared ? '📌' : '📌🔒' }));
  (m.labels || []).forEach((l) => meta.push(labelChip(l)));
  // 🌐 תרגום במכשיר (12e) — רק כשההודעה כנראה לא בעברית
  const tr = translatable(m) ? trLive.get(trKey(m)) : null;
  if (translatable(m)) meta.push(el('button', { type: 'button', class: 'b-tr-btn', 'aria-pressed': String(tr?.state === 'on'),
    'aria-label': tr?.state === 'on' ? 'הצגת המקור' : `תרגום ${trTo()}`, title: tr?.state === 'on' ? 'הצג מקור' : `תרגום ${trTo()} (במכשיר)`,
    text: '🌐', onclick: (e) => { e.stopPropagation(); toggleTranslate(m); } }));
  // ⋯ — תפריט הפעולות (§5.11, 12b). בטלפון — נגיעה ארוכה על הבועה; הכפתור מופיע ב-hover/focus
  meta.push(el('button', { type: 'button', class: 'b-more', 'aria-label': 'פעולות על ההודעה', 'aria-haspopup': 'menu',
    title: 'פעולות', text: '⋯', onclick: (e) => { e.stopPropagation(); openMsgMenu(m); } }));
  // תשובה להודעה — ציטוט קצר של המקור, נגיעה קופצת אליו
  const orig = m.reply_to ? conv.messages.find((x) => x.id === m.reply_to) : null;
  const kind = { question: '❓ שאלה', answer: '💡 תשובה', decision: '🔐 החלטה', choice: '🔘 שאלת בחירה', choice_answer: '🔘 תשובה', permission: '🔐 הרשאה' }[m.kind];
  return el('li', { class: `bubble bubble--${m.direction}${m.author_role === 'system' ? ' bubble--sys' : ''}${m.author_role === 'platform' ? ' bubble--platform' : ''}`, dataset: { id: String(m.id) } }, [
    el('div', { class: 'b-meta' }, meta),
    orig && m.kind !== 'choice_answer' && !isPlatformRelay(m) ? el('button', { type: 'button', class: 'b-reply', dir: 'auto', title: 'מעבר להודעה',
      onclick: () => scrollToMessage(orig.id) }, [`↩ ${plain(orig.body, 90)}`]) : null,
    kind ? el('span', { class: 'badge', text: kind }) : null,
    // העתק לצ'אט של תשובת Platform (§9) — התשובה כבר בבועה של Platform; כאן שורה מקופלת בלבד
    isPlatformRelay(m) ? el('details', { class: 'b-relay' }, [
      el('summary', { text: `📨 עודכן לצ'אט: Platform ${/☁️ Platform שאל:/u.test(m.body || '') && !/☁️ Platform ענה:/u.test(m.body || '') ? 'שאל' : 'ענה'} על הודעה #${m.reply_to}` }),
      el('div', { class: 'b-body md', dir: 'auto' }, [renderMarkdown(m.body)]),
    ]) :
    m.kind === 'approval' && m.approval?.type
      ? approvalCard(m.approval, m.task_id, { canDecide: m.approval.can_decide, pending: m.approval.pending, onDone: reload })
      // שאלת בחירה (§5.10): כפתור לכל אפשרות; אחרי מענה — מקופלת
      : m.kind === 'choice' && m.meta?.questions
        ? choiceCard(m, { canAnswer: conv.channel?.can_send && conv.channel?.status === 'active',
            // §9.1: תשובה לשאלה של Platform — Platform ממשיך מיד מהתשובה
            onDone: (r) => { reload(); if (r?.platform) platformAnswer(r.id); } })
      // §15.12.1: הצ'אט לא עונה — כרטיס אחד לאפיזודה, לבעל הלוח
      : m.kind === 'lane_card'
        ? laneCard(m)
      // בקשת הרשאה (§5.12): הפקודה המלאה וכפתורי אישור — בעל הלוח
      : m.kind === 'permission' && m.meta?.tool_name
        ? permCard(permFromMessage(m), { onDone: () => { reload(); refreshPermStrip(); } })
      // הצ'אט כותב Markdown — מוצג מעוצב כמו בצ'אט (בונה צמתים, אף פעם לא HTML)
        : el('div', { class: 'b-body md', dir: 'auto' }, [renderMarkdown(tr && tr.state !== 'off' ? tr.md : m.body)]),
    tr && tr.state !== 'off' ? trLine(m, tr) : null,
    // §9.1: מה שרק הצ'אט יכול — הועבר אליו (ואם הוא לא מאזין, כתוב)
    m.meta?.needs_chat ? el('p', { class: 'b-needs', dir: 'auto', text: `☁️ Platform לא יכול לבצע את זה — הועבר ל${m.meta.needs_chat.to}`
      + (m.meta.needs_chat.listening ? '' : ' · לא מאזין עכשיו, יקבל כשיתעורר') + `: ${m.meta.needs_chat.what}` }) : null,
    m.meta?.claimed_action ? el('p', { class: 'b-warn', text: '⚠ Platform תיאר פעולה שלא בוצעה — אין לו כלים. כשצריך פעולה, כותבים לצ\'אט עצמו.' }) : null,
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

/* ── שלב 17: תגים, "העבר ל…", היומן (§15.6) ───────────────────── */

export const LANE_ICON = { chat: '💬', platform: '☁️', engine: '🤖' };
const LANE_STATE = { owner: 'ממתין לבעל הלוח', chat: 'ממתין לצ\'אט', platform: 'ממתין ל-Platform', done: 'טופל' };

function laneTag(m) {
  if (!m.lane || !m.lane_state) return null;
  return el('span', { class: `b-lane b-lane--${m.lane_state}`, title: `${LANE_ICON[m.lane]} · ${LANE_STATE[m.lane_state]}`,
    text: `${LANE_ICON[m.lane] || ''} ${LANE_STATE[m.lane_state]}` });
}

function openLaneSwitch() {
  const c = conv.channel;
  const toChat = conv.messages.filter((m) => m.platform_pending).length;
  const toPlat = conv.messages.filter((m) => m.lane_state === 'chat').length;
  $('#laneSwitchHint').textContent = `עכשיו: ${toPlat} ממתינות לצ'אט · ${toChat} ממתינות ל-Platform. המסלול של הערוץ: ${LANE_ICON[c.route_effective] || ''} ${c.route_effective === 'platform' ? 'Platform' : 'הצ\'אט'}.`;
  const go = async (to, btn) => {
    btn.disabled = true;
    try {
      const r = await api('lane-switch', { channel_id: conv.id, to });
      toast(`🔀 הועברו ${r.moved}`, 'ok');
      $('#laneSwitch').close();
      reload();
      for (const id of r.run || []) await platformAnswer(id);
    } catch (ex) { toast(ex.message, 'error'); btn.disabled = false; }
  };
  $('#laneSwitchOpts').replaceChildren(
    el('button', { type: 'button', class: 'btn primary', disabled: !toPlat, onclick: (e) => go('platform', e.currentTarget) },
      [`☁️ ל-Platform (${toPlat})`]),
    el('button', { type: 'button', class: 'btn ghost', disabled: !toChat, onclick: (e) => go('chat', e.currentTarget) },
      [`💬 לצ'אט (${toChat})`]),
  );
  $('#laneSwitch').showModal();
}
on('#cvLaneBtn', 'click', () => openLaneSwitch());

async function openLaneLog() {
  $('#laneLogRows').replaceChildren(el('li', { class: 'hint', text: '…' }));
  $('#laneLog').showModal();
  try {
    const { log } = await api('lane-log', { channel_id: conv.id });
    $('#laneLogRows').replaceChildren(...(log.length ? log.map((l) => el('li', {}, [
      el('b', { text: `${LANE_ICON[l.from] || l.from} ← ${LANE_ICON[l.to] || l.to} · ${l.reason_text}` }),
      el('small', { text: [timeAgo(l.at), l.by ? `👤 ${l.by}` : 'אוטומטי', l.setting_text ? `ההגדרה: ${l.setting_text}` : '', l.moved ? `הועברו: ${l.moved}` : '']
        .filter(Boolean).join(' · ') }),
    ])) : [el('li', { class: 'hint', text: 'עוד לא היה מעבר בין מסלולים בערוץ הזה.' })]));
  } catch (ex) { toast(ex.message, 'error'); }
}

/* ── שלב 16: הצ'אט לא עונה (§15.12.1) ─────────────────────────── */

const ago = (t) => { const s = Math.max(0, Math.floor(Date.now() / 1000) - t); return s < 3600 ? `${Math.max(1, Math.round(s / 60))} דק'` : `${Math.round(s / 3600)} שע'`; };
const utcTime = (t) => new Date(t * 1000).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });

/** "💬 הצ'אט לא עונה — <שם>": כמה ממתינות, מתי הישנה, כמה נשאר מהתקרה, ☁️ / ⏳. */
function laneCard(m) {
  const x = m.meta || {};
  const done = m.status !== 'open';
  const facts = [
    x.why,
    `${x.waiting} ${x.waiting === 1 ? 'הודעה ממתינה' : 'הודעות ממתינות'}${x.oldest_at ? ` · הישנה לפני ${ago(x.oldest_at)}` : ''}`,
    x.platform_ok ? `נשאר למעברים אוטומטיים: $${Number(x.ceiling_left || 0).toFixed(2)} (${x.ceiling_by})` : null,
    x.resets_at ? `המגבלה מתאפסת ב-${utcTime(x.resets_at)}` : null,
  ].filter(Boolean);
  const answer = async (choice, btn) => {
    btn.disabled = true;
    try {
      const r = await api('lane-card-answer', { card_id: m.id, choice });
      toast(choice === 'platform' ? `☁️ Platform עונה — הועברו ${r.moved}` : '⏳ מחכים לצ\'אט', 'ok');
      for (const id of r.run || []) await platformAnswer(id);
    } catch (ex) { toast(ex.message, 'error'); btn.disabled = false; }
    reload();
  };
  return el('div', { class: 'lane-card' }, [
    el('b', { text: m.body }),
    el('ul', { class: 'lane-facts' }, facts.map((f) => el('li', { dir: 'auto', text: f }))),
    x.blocked ? el('p', { class: 'b-warn', text: x.blocked }) : null,
    done ? el('p', { class: 'hint', text: m.status === 'resolved' ? '💬 הצ\'אט חזר — הכרטיס נסגר.'
      : x.decision === 'platform' ? '☁️ הוחלט: Platform עונה עד שהצ\'אט יחזור.' : '⏳ הוחלט: מחכים לצ\'אט.' })
      : isOwner() ? el('div', { class: 'row-btns' }, [
        x.platform_ok ? el('button', { type: 'button', class: 'btn primary', text: '☁️ שיענה Platform', onclick: (e) => answer('platform', e.currentTarget) }) : null,
        el('button', { type: 'button', class: 'btn ghost', text: '⏳ נחכה', onclick: (e) => answer('wait', e.currentTarget) }),
      ].filter(Boolean)) : el('p', { class: 'hint', text: 'ממתין להחלטה של בעל הלוח.' }),
  ]);
}

/** הפס בראש השיחה: הצ'אט לא עונה מאז…, ומה קורה עכשיו. */
function renderLaneStrip(c) {
  const on = c.status === 'active' && c.unresponsive_since > 0;
  $('#cvLane').hidden = !on;
  if (!on) return;
  const limit = c.unresponsive_reason === 'limit';
  const what = c.route_effective === 'platform' ? '☁️ Platform עונה במקומו עד שיחזור'
    : c.lane_decision === 'wait' ? '⏳ ההודעות ממתינות לו' : '❓ ממתין להחלטה שלך (בכרטיס למטה)';
  $('#cvLane').replaceChildren(el('div', { class: 'body' }, [
    el('b', { text: `🔴 ${limit ? 'הצ\'אט הגיע למגבלה' : 'הצ\'אט לא עונה'} · מאז ${clock(c.unresponsive_since)}` }),
    el('small', { text: `${what}${c.limit_resets_at ? ` · מתאפס ב-${utcTime(c.limit_resets_at)}` : ''}` }),
  ]));
}

/** ההעתק שהשרת שומר לצ'אט אחרי ש-Platform ענה בשמו — גם בנוסח הישן ("☁️ Platform ענה בלוח."). */
function isPlatformRelay(m) {
  return m.kind === 'status' && m.author_role === 'system' && m.direction === 'in' && m.reply_to
    && /^(📨 לצ'אט: Platform ענה|☁️ Platform ענה בלוח\.)/u.test(m.body || '');
}

function renderHead() {
  const c = conv.channel;
  $('#cvTitle').textContent = c.name;
  $('#cvSub').textContent = [c.role, c.status === 'active' ? (c.listening_until > Date.now() / 1000 ? '🟢 מאזין' : '⚪ לא מאזין')
    : c.status === 'replaced' ? 'הוחלף' : '🔌 מנותק'].filter(Boolean).join(' · ');
  renderLaneStrip(c);
  $('#cvLaneBtn').hidden = !(isOwner() && c.status === 'active');
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
    if (r.handed_to_chat && !r.answered) toast(`Platform לא ענה — עבר לצ'אט: ${r.reason}`, '');
    else if (r.asked) toast('☁️ Platform שואל — בחר תשובה בבועה', '');
    else if (r.waiting) toast(r.reason, '');
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
  // סינון פעיל (12d) — רק ההודעות שמתאימות; אחרת כל השיחה
  const list = conv.view || conv.messages;
  log.replaceChildren(...(list.length ? list.map(bubble)
    : [el('li', { class: 'empty', text: conv.view ? 'אין הודעות שמתאימות לסינון.' : 'עוד אין הודעות. מה שתכתוב כאן יגיע לצ\'אט ויעיר אותו.' })]));
  if (conv.filter?.q) highlight(log, conv.filter.q);
  if (atEnd && !conv.filter) log.scrollTop = log.scrollHeight;
  hydrateTranslations(list);
}

/* ── 🌐 תרגום במכשיר (§5.11, 12e) ─────────────────────────────
 * המצב לפי מזהה הודעה (לא לפי ה-DOM): רענון של השיחה מצייר את הבועות מחדש, והתרגום שבאמצע
 * ממשיך ומעדכן את הבועה שמוצגת עכשיו. state: busy · on · off. */
const trLive = new Map();   // ‎<lang>:<id>‎ → { state, md, done, total }
const trKey = (m) => `${trLang()}:${m.id}`;
const trTo = () => `ל${TRANSLATE_LANGS[trLang()].heName}`;

function translatable(m) {
  return !m.platform_pending && m.kind !== 'approval' && m.kind !== 'choice' && trNeeds(m.body);
}

function trLine(m, tr) {
  if (tr.state === 'busy') {
    return el('p', { class: 'b-trline', role: 'status' }, [tr.total ? `🌐 מתרגם… ${tr.done}/${tr.total}` : '🌐 מתרגם…']);
  }
  return el('p', { class: 'b-trline' }, ['🌐 תורגם מאנגלית · ',
    el('button', { type: 'button', class: 'linklike', text: 'הצג מקור', onclick: (e) => { e.stopPropagation(); toggleTranslate(m); } })]);
}

/** מצייר מחדש רק את הבועה של ההודעה (אם היא על המסך). */
function redrawBubble(m) {
  const li = $(`#chatLog .bubble[data-id="${m.id}"]`);
  if (li) li.replaceWith(bubble(m));
}

async function toggleTranslate(m) {
  const cur = trLive.get(trKey(m));
  if (cur?.state === 'busy') return;
  if (cur?.state === 'on') {
    trLive.set(trKey(m), { ...cur, state: 'off' });
    redrawBubble(m);
    trSetShow(m.id, false);
    return;
  }
  const eng = await trEngine();
  if (!eng.kind) { toast(eng.reason, 'error'); return; }
  trLive.set(trKey(m), { state: 'busy', md: m.body, done: 0, total: 0 });
  redrawBubble(m);
  try {
    const md = await translate(m.id, m.body, (partial, done, total) => {
      trLive.set(trKey(m), { state: 'busy', md: partial, done, total });
      redrawBubble(m);
    });
    trLive.set(trKey(m), { state: 'on', md });
  } catch (ex) {
    trLive.delete(trKey(m));
    toast(`התרגום נכשל: ${ex.message}`, 'error');
  }
  redrawBubble(m);
}

/** תרגום שנשמר במכשיר ונבחר "תרגום" — מוצג שוב בכניסה הבאה לשיחה. ובלי מנוע — 🌐 מסומן כבוי, עם הסיבה. */
async function hydrateTranslations(list) {
  const ids = list.filter(translatable);
  if (!ids.length) return;
  for (const m of ids) {
    if (trLive.has(trKey(m))) continue;
    const rec = await trCached(m.id);
    if (rec?.text && rec.show) { trLive.set(trKey(m), { state: 'on', md: rec.text }); redrawBubble(m); }
  }
  const eng = await trEngine();
  if (!eng.kind) {
    $$('#chatLog .b-tr-btn').forEach((b) => { b.setAttribute('aria-disabled', 'true'); b.title = eng.reason; });
  }
}

/** ‏after — מהמוקדמת שעוד לא נמסרה, כדי ש-✓ יתעדכן ל-✓✓. */
function afterId() {
  // גם שאלת בחירה פתוחה — כדי שתשובה שהוקלדה בצ'אט תקפל את הבועה
  const pending = conv.messages.find((m) => (m.direction === 'in' && !m.delivered_at) || ((m.kind === 'choice' || m.kind === 'permission') && m.status === 'open'));
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
  Object.assign(conv, { id, channel: null, messages: [], pins: [], filter: null, view: null, matches: [], matchAt: -1 });
  $('#cvFilter').hidden = true;
  setReply(null);
  $('#cvPins').hidden = true;
  $('#pinBubble').hidden = true;
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
    // פס המוצמדות הוסתר (בועה / מוסתר) — כאן מחזירים אותו
    pinsMode() !== 'strip' && ['📌', 'פס המוצמדות', pinsMode() === 'bubble' ? 'בועה צפה — נגיעה מחזירה את הפס' : 'מוסתר — נגיעה מחזירה את הפס',
      () => { setPinsMode('strip'); renderSettings(); toast('פס המוצמדות חזר', 'ok'); }, { sub2: '↩ להחזיר את הפס' }],
    ['🔎', 'חיפוש וסינון', conv.filter ? `פעיל · ${filterSummary(conv.filter).join(' · ')}` : 'טקסט, תוויות, מוצמדות, סוג, מי כתב, תאריכים', () => { $('#chatSettings').close(); openSearch(); }],
    ['⬇️', 'ייצוא', conv.view ? 'כל השיחה, או רק המסוננות' : 'כל השיחה כקובץ Markdown', () => exportChat()],
    ['🏷', 'ניהול תוויות', 'יצירה, עריכה ומחיקה — משותפות ופרטיות', () => openManageLabels(conv.channel, () => reload())],
    ['🔔', 'השתקה', c.muted ? 'מושתק — לא נספר ב"ממתין לך" · נגיעה מבטלת' : 'כבויה — נגיעה משתיקה', () => toggleMute(), { pressed: c.muted }],
    isOwner() && ['🧭', 'מי עונה', `${c.route_own ? ROUTE_LABEL[c.route_own] : 'כמו הפרויקט'} · בפועל: ${ROUTE_LABEL[c.route_effective]}`, () => openRoute()],
    c.can_edit && active && ['🔘', 'שאלות בחירה', c.questions === 'app' ? 'באפליקציה' : 'בלוח', () => openQuestions()],
    isOwner() && active && ['🔐', 'אישורי הרשאות', c.permissions === 'app' ? 'באפליקציה בלבד' : 'בלוח — ובמקביל באפליקציה', () => openPerms()],
    // גרסת הגשר (§5.10.1): צ'אט ישן — הוראת עדכון חד-פעמית, שהסקיל הישן כבר יודע לבצע
    active && isOwner() && ['📨', 'גרסת הגשר', c.bridge_outdated
      ? `⚠ ${c.bridge_version ? 'גרסה ' + c.bridge_version : 'מלפני התיקון'} · נגיעה שולחת הוראת עדכון` : `✓ עדכנית (${c.bridge_version})`,
      c.bridge_outdated ? () => sendBridgeUpdate() : null, { sub2: c.bridge_outdated ? '📨 שלח הוראת עדכון' : '' }],
    ['🔀', 'יומן המסלולים', 'כל מעבר בין 💬 / ☁️ — מתי, למה, ומי', () => { $('#chatSettings').close(); openLaneLog(); }],
    isOwner() && ['📝', 'סיכום חכם', 'קריאה אחת למודל, על חשבון המשלם · נשמר מוצמד בשיחה, ומזין את צ\'אט ההמשך', () => smartSummary()],
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
    ['אישורי הרשאות', c.permissions === 'app' ? 'באפליקציה בלבד' : 'בלוח'],
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

async function sendBridgeUpdate() {
  if (!confirm("לשלוח לצ'אט הוראת עדכון חד-פעמית לגשר? הוא יריץ אותה בתור הבא שלו, בלי commit בענף.")) return;
  try {
    await api('bridge-send-update', { channel_id: conv.id });
    toast('ההוראה נשלחה — הצ\'אט יבצע אותה כשיקרא את ההודעה', 'ok');
    $('#chatSettings').close();
    reload();
  } catch (ex) { toast(ex.message, 'error'); }
}

/** 📝 סיכום חכם (§15.5): קריאה אחת, נשמר מוצמד. שוב בלי הודעות חדשות — אותו סיכום, בלי לשלם שוב. */
async function smartSummary() {
  if (!confirm('ליצור סיכום חכם של הערוץ? זו קריאה אחת למודל, על חשבון המשלם של הפרויקט.')) return;
  toast('📝 מסכם…', '');
  try {
    const r = await api('channel-summary', { channel_id: conv.id });
    toast(r.cached ? 'אין הודעות חדשות מאז הסיכום הקודם — מוצג הקיים' : '📝 הסיכום נשמר ומוצמד בשיחה', 'ok');
    $('#chatSettings').close();
    reload(); loadPins();
    setTimeout(() => scrollToMessage(r.message.id), 400);
  } catch (ex) { toast(ex.message, 'error'); }
}

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

function renderPermsSeg() {
  const mode = cs.info?.permissions || 'board';
  $$('#cvPermsSeg button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.mode === mode)));
}
function openPerms() { renderPermsSeg(); $('#csPerms').showModal(); }
on('#cvPermsSeg', 'click', async (e) => {
  const b = e.target.closest('button[data-mode]');
  if (!b || !conv.id) return;
  try {
    await api('channel-permissions', { channel_id: conv.id, mode: b.dataset.mode });
    cs.info.permissions = b.dataset.mode;
    renderPermsSeg();
    renderSettings();
    toast(b.dataset.mode === 'board' ? 'בקשות הרשאה יגיעו ללוח' : 'בקשות הרשאה — באפליקציה בלבד', 'ok');
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
    translatable(m) ? act('🌐', trLive.get(trKey(m))?.state === 'on' ? 'הצג מקור' : `תרגום ${trTo()}`, () => toggleTranslate(m),
      trLive.get(trKey(m))?.state === 'on' ? 'חזרה לטקסט המקורי' : 'במכשיר, בלי שרת — משפט אחר משפט') : null,
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

/* ── 🔎 חיפוש, 🏷 סינון ו-⬇️ ייצוא (§5.11, שלב 12d) ─────────────────── */

const KIND_LABEL = { question: '❓ שאלות', decision: '🔐 החלטות', answer: '💡 תשובות', task: '📋 מטלות', system: '⚙ מערכת' };
const AUTHOR_LABEL = { chat: '💬 הצ\'אט', board: '👤 אנשים בלוח', platform: '☁️ Platform', system: '⚙ מערכת' };
const emptyFilter = () => ({ q: '', labels: [], pinned: false, kinds: [], authors: [], from: '', to: '' });
let labelNames = {};

function filterSummary(f) {
  return [
    f.q && `"${f.q}"`,
    ...f.labels.map((id) => `🏷 ${labelNames[id] || id}`),
    f.pinned && '📌 מוצמדות',
    ...f.kinds.map((k) => KIND_LABEL[k]),
    ...f.authors.map((a) => AUTHOR_LABEL[a]),
    f.from && `מ-${f.from}`, f.to && `עד ${f.to}`,
  ].filter(Boolean);
}
/** רק טקסט? אז מציגים את כל השיחה ומדגישים; כל סינון אחר — מצמצם את הרשימה. */
const narrows = (f) => f.labels.length || f.pinned || f.kinds.length || f.authors.length || f.from || f.to;

/** מסמן את ההתאמות בטקסט שבבועות (צמתי טקסט בלבד — בלי לגעת במבנה). */
function highlight(root, q) {
  const needle = q.toLocaleLowerCase();
  root.querySelectorAll('.b-body, .choice').forEach((body) => {
    const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
    const hits = [];
    for (let n = walker.nextNode(); n; n = walker.nextNode()) if (n.nodeValue.toLocaleLowerCase().includes(needle)) hits.push(n);
    hits.forEach((n) => {
      const frag = document.createDocumentFragment();
      let text = n.nodeValue, low = text.toLocaleLowerCase(), i;
      while ((i = low.indexOf(needle)) !== -1) {
        if (i) frag.append(text.slice(0, i));
        frag.append(el('mark', { class: 'hit', text: text.slice(i, i + q.length) }));
        text = text.slice(i + q.length); low = low.slice(i + q.length);
      }
      if (text) frag.append(text);
      n.replaceWith(frag);
    });
  });
}

function chip(text, onRemove) {
  return el('span', { class: 'fchip' }, [el('span', { dir: 'auto', text }),
    el('button', { type: 'button', class: 'fchip-x', 'aria-label': `הסרת ${text}`, text: '✕', onclick: onRemove })]);
}

function renderFilterBar() {
  const f = conv.filter;
  const bar = $('#cvFilter');
  bar.hidden = !f;
  if (!f) { bar.replaceChildren(); return; }
  const drop = (patch) => () => { applyFilter({ ...conv.filter, ...patch }); };
  const chips = [
    f.q ? chip(`"${f.q}"`, drop({ q: '' })) : null,
    ...f.labels.map((id) => chip(`🏷 ${labelNames[id] || id}`, drop({ labels: f.labels.filter((x) => x !== id) }))),
    f.pinned ? chip('📌 מוצמדות', drop({ pinned: false })) : null,
    ...f.kinds.map((k) => chip(KIND_LABEL[k], drop({ kinds: f.kinds.filter((x) => x !== k) }))),
    ...f.authors.map((a) => chip(AUTHOR_LABEL[a], drop({ authors: f.authors.filter((x) => x !== a) }))),
    f.from ? chip(`מ-${f.from}`, drop({ from: '' })) : null,
    f.to ? chip(`עד ${f.to}`, drop({ to: '' })) : null,
  ].filter(Boolean);
  const n = conv.matches.length;
  bar.replaceChildren(...[
    el('div', { class: 'fchips' }, chips),
    el('div', { class: 'fnav' }, [
      el('span', { class: 'fcount', 'aria-live': 'polite', text: n ? `${conv.matchAt + 1} / ${n}` : 'אין תוצאות' }),
      el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'התוצאה הקודמת', text: '▲', disabled: !n, onclick: () => step(-1) }),
      el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'התוצאה הבאה', text: '▼', disabled: !n, onclick: () => step(1) }),
      el('button', { type: 'button', class: 'linklike', text: 'ניקוי', onclick: () => applyFilter(null) }),
    ]),
  ]);
}

function step(dir) {
  const n = conv.matches.length;
  if (!n) return;
  conv.matchAt = (conv.matchAt + dir + n) % n;
  renderFilterBar();
  const id = conv.matches[conv.matchAt];
  if (!$(`#chatLog [data-id="${id}"]`)) { toast('ההודעה עוד לא נטענה במסך', ''); return; }
  scrollToMessage(id);
}

async function applyFilter(f) {
  const empty = !f || !filterSummary(f).length;
  if (empty) {
    conv.filter = null; conv.view = null; conv.matches = []; conv.matchAt = -1;
    renderFilterBar(); renderLog(true);
    return;
  }
  try {
    const { messages } = await api('channel-search', { channel_id: conv.id, filter: f });
    conv.filter = f;
    conv.view = narrows(f) ? messages : null;
    conv.matches = messages.map((m) => m.id);
    conv.matchAt = conv.matches.length ? conv.matches.length - 1 : -1;   // מהחדשה
    renderFilterBar();
    renderLog(false);
    if (conv.matches.length) setTimeout(() => scrollToMessage(conv.matches[conv.matchAt]), 30);
  } catch (ex) { toast(ex.message, 'error'); }
}

function toggleBtn(text, pressed, onToggle) {
  return el('button', { type: 'button', class: 'ftoggle', 'aria-pressed': String(!!pressed),
    onclick: (e) => { const on = e.currentTarget.getAttribute('aria-pressed') !== 'true'; e.currentTarget.setAttribute('aria-pressed', String(on)); onToggle(on); } }, [text]);
}

async function openSearch() {
  if (!conv.id) return;
  const f = structuredClone(conv.filter || emptyFilter());
  let labels = [];
  try { ({ labels } = await api('labels', { channel_id: conv.id })); } catch { /* בלי תוויות — שאר הסינון עובד */ }
  labelNames = Object.fromEntries(labels.map((l) => [l.id, l.name]));
  const setIn = (arr, v, on) => { const i = arr.indexOf(v); if (on && i < 0) arr.push(v); if (!on && i >= 0) arr.splice(i, 1); };
  const q = el('input', { type: 'search', id: 'ssQ', value: f.q, dir: 'auto', placeholder: 'מה לחפש בהודעות', 'aria-label': 'טקסט לחיפוש', enterkeyhint: 'search' });
  const from = el('input', { type: 'date', value: f.from, 'aria-label': 'מתאריך' });
  const to = el('input', { type: 'date', value: f.to, 'aria-label': 'עד תאריך' });
  $('#ssBody').replaceChildren(...[
    el('label', { class: 'field' }, [el('span', { text: '🔎 טקסט' }), q]),
    el('p', { class: 'hint', text: 'טקסט לבד — מדגיש בתוך השיחה, ו-▲▼ עוברים בין התוצאות. כל סינון אחר — מציג רק את ההודעות שמתאימות.' }),
    labels.length ? el('h3', { text: '🏷 תוויות (כל אחת מהן)' }) : null,
    labels.length ? el('div', { class: 'ftoggles' }, labels.map((l) => toggleBtn(`${l.visibility === 'private' ? '🔒 ' : ''}${l.name}`, f.labels.includes(l.id), (on) => setIn(f.labels, l.id, on)))) : null,
    el('h3', { text: 'עוד' }),
    el('div', { class: 'ftoggles' }, [toggleBtn('📌 מוצמדות', f.pinned, (on) => { f.pinned = on; })]),
    el('h3', { text: 'סוג' }),
    el('div', { class: 'ftoggles' }, Object.entries(KIND_LABEL).map(([k, t]) => toggleBtn(t, f.kinds.includes(k), (on) => setIn(f.kinds, k, on)))),
    el('h3', { text: 'מי כתב' }),
    el('div', { class: 'ftoggles' }, Object.entries(AUTHOR_LABEL).map(([k, t]) => toggleBtn(t, f.authors.includes(k), (on) => setIn(f.authors, k, on)))),
    el('h3', { text: 'תאריכים' }),
    el('div', { class: 'fdates' }, [el('label', { class: 'field' }, [el('span', { text: 'מ-' }), from]), el('label', { class: 'field' }, [el('span', { text: 'עד' }), to])]),
    el('div', { class: 'row-btns' }, [
      el('button', { type: 'button', class: 'btn ghost', text: 'ניקוי', onclick: () => { $('#searchSheet').close(); applyFilter(null); } }),
      el('button', { type: 'button', class: 'btn ghost', text: '⬇️ ייצוא', onclick: () => exportChat() }),
    ]),
  ].filter(Boolean));
  $('#ssForm').onsubmit = (e) => {
    e.preventDefault();
    f.q = q.value.trim(); f.from = from.value; f.to = to.value;
    $('#searchSheet').close();
    applyFilter(f);
  };
  $('#searchSheet').showModal();
  q.focus();
}
on('#cvSearch', 'click', openSearch);

/** ⬇️ קובץ Markdown — כל השיחה, או המסוננות כשיש סינון. */
async function exportChat() {
  if (!conv.id) return;
  const filtered = conv.filter && confirm(`לייצא רק את ההודעות שמתאימות לסינון (${conv.matches.length})?\nביטול — כל השיחה.`);
  try {
    const r = await api('channel-export', { channel_id: conv.id, filter: filtered ? conv.filter : {} });
    const a = el('a', { href: URL.createObjectURL(new Blob([r.markdown], { type: 'text/markdown;charset=utf-8' })), download: r.filename });
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    toast(`יוצאו ${r.count} הודעות`, 'ok');
  } catch (ex) { toast(ex.message, 'error'); }
}
