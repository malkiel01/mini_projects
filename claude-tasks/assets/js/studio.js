/**
 * סטודיו — צ'אט פיתוח מול Claude Platform (docs/studio.md).
 *
 * הדף מציג את היומן שבשרת שלנו. הוא פונה לשרת רק כשיש סיבה:
 *   - בכניסה, ובחזרה ללשונית;
 *   - אחרי פעולה של המשתמש;
 *   - ובזמן שריצה פעילה בשיחה הפתוחה — במרווח שמתארך, ונעצר כשהריצה נגמרת.
 * שיחה שקטה = אפס פניות. אין כאן טיימר שרץ "ליתר ביטחון".
 */

import { $, el, toast, timeAgo } from './core.js';
import { renderMarkdown } from './markdown.js';

const S = { state: null, conv: null, lastSeq: 0, timer: null, ticks: 0, busy: false, pending: [] };

const STATUS = {
  new: 'חדשה', running: 'עובד', idle: 'ממתין לך', needs_action: 'מחכה לאישור',
  budget: 'תקרת תקציב', terminated: 'הסתיים',
};
const IDLE_REASON = {
  end_turn: 'הסוכן סיים', requires_action: 'מחכה לאישור פעולה', budget_reached: 'נעצר בתקרת התקציב',
  retries_exhausted: 'נעצר אחרי שגיאות חוזרות', refusal: 'המודל סירב להמשיך',
};

async function call(action, payload = {}) {
  let res;
  try {
    res = await fetch(`./studio.php?action=${encodeURIComponent(action)}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
  } catch { throw new Error('אין חיבור לשרת'); }
  let data;
  try { data = await res.json(); } catch { throw new Error(`תשובה לא תקינה מהשרת (${res.status})`); }
  if (!data.success) {
    const err = new Error(data.error || `שגיאה (${res.status})`);
    err.status = res.status;
    throw err;
  }
  return data;
}

const money = (cents) => `$${(cents / 100).toFixed(2)}`;
const badge = (status) => el('span', { class: `s-badge s-badge--${status}`, text: STATUS[status] || status });

/* ── רשימת השיחות ──────────────────────────────────────────────── */

async function loadState() {
  try {
    S.state = await call('state');
  } catch (e) {
    showBlocked(e);
    return false;
  }
  return true;
}

function showBlocked(e) {
  const msg = e.status === 401 ? 'צריך להתחבר ללוח קודם.' : e.status === 403 ? 'הסטודיו פתוח רק לבעל הלוח.' : e.message;
  const box = $('#sBlocked');
  box.replaceChildren(document.createTextNode(msg + ' '), el('a', { href: './index.html', text: 'חזרה ללוח' }));
  box.hidden = false;
  $('#sNew').hidden = true;
  $('#sSettings').hidden = true;
}

function renderSetup() {
  const c = S.state.connections;
  const box = $('#sSetup');
  const ready = c.anthropic && c.github;
  box.hidden = ready;
  $('#sNew').disabled = !ready;
  if (ready) return;
  const row = (ok, text) => el('div', { class: 'row' }, [el('span', { class: ok ? 'ok' : 'miss', text: ok ? '✓' : '✗' }), el('span', { text })]);
  box.replaceChildren(
    el('b', { text: 'לפני השיחה הראשונה צריך שני חיבורים' }),
    row(c.anthropic, 'מפתח API של Anthropic (Console) — משלם על הריצות'),
    row(c.github, 'אסימון GitHub עם הרשאת repo — כדי שהסוכן ידחוף ויפתח PR'),
    el('span', { class: 'hint' }, ['מוסיפים אותם בלוח: ', el('a', { href: './index.html', text: 'הגדרות ← חיבורים' }), '.']),
  );
}

function renderList() {
  renderSetup();
  const convs = S.state.convs;
  $('#sEmpty').hidden = convs.length > 0;
  $('#sConvs').replaceChildren(...convs.map((c) => el('button', { type: 'button', class: 's-conv', onclick: () => openConv(c.id) }, [
    el('span', { class: 't', text: c.title }),
    el('span', { class: 'st' }, badge(c.status)),
    el('span', { class: 'm', text: [c.repo, c.cost_cents ? money(c.cost_cents) : '', c.last_event_at ? timeAgo(c.last_event_at) : ''].filter(Boolean).join(' · ') }),
  ])));
}

async function showList(reload = true) {
  stopPolling();
  S.conv = null;
  $('#sChat').hidden = true;
  $('#sList').hidden = false;
  $('#sRefresh').hidden = true;
  $('#sTitle').textContent = 'סטודיו';
  $('#sSub').textContent = "צ'אט פיתוח מול Claude Platform";
  $('#sBack').onclick = null;
  if (!reload || await loadState()) renderList();
}

/* ── שיחה ──────────────────────────────────────────────────────── */

async function openConv(id) {
  S.conv = S.state.convs.find((c) => c.id === id) || { id };
  S.lastSeq = 0;
  S.pending = [];
  $('#sLog').replaceChildren();
  $('#sList').hidden = true;
  $('#sChat').hidden = false;
  $('#sRefresh').hidden = false;
  $('#sBack').onclick = (e) => { e.preventDefault(); history.back(); };
  if (location.hash !== `#c${id}`) history.pushState(null, '', `#c${id}`);
  renderConv();
  await refresh(true);
  $('#sText').focus();
}

function renderConv() {
  const c = S.conv;
  $('#sTitle').textContent = c.title || 'שיחה';
  $('#sSub').textContent = STATUS[c.status] || '';
  $('#sMeta').replaceChildren(
    badge(c.status),
    el('span', {}, ['Platform · ', el('code', { text: c.model || '' })]),
    el('code', { text: c.repo || '' }),
    el('span', { text: c.budget_cents ? `${money(c.cost_cents || 0)} מתוך ${money(c.budget_cents)}` : (c.cost_cents ? money(c.cost_cents) : 'בלי תקרת תקציב') }),
    el('span', { text: c.allow_merge ? 'מיזוג: מותר' : 'מיזוג: רק ידני' }),
  );
  const running = c.status === 'running';
  $('#sStop').hidden = !running;
  const blocked = ['needs_action', 'budget', 'terminated'].includes(c.status);
  $('#sText').disabled = blocked;
  $('#sSend').disabled = blocked || S.busy;
  renderStrip();
}

function renderStrip() {
  const c = S.conv;
  const strip = $('#sStrip');
  const rows = [];
  if (c.status === 'needs_action') {
    rows.push(el('div', { text: `הסוכן מחכה לאישור של ${c.pending} פעולות. הפעולה האחרונה ביומן מסומנת.` }));
    rows.push(el('div', { class: 'row' }, [
      el('button', { type: 'button', class: 'btn sm primary', text: 'אשר', onclick: () => act('conv-confirm', { result: 'allow' }) }),
      el('button', { type: 'button', class: 'btn sm ghost', text: 'דחה', onclick: () => act('conv-confirm', { result: 'deny' }) }),
    ]));
  } else if (c.status === 'budget') {
    const input = el('input', { type: 'number', min: '1', max: '1000', step: '1', dir: 'ltr', value: String(Math.ceil((c.budget_cents || 0) / 100) * 2 || 20), 'aria-label': 'תקרה חדשה בדולרים' });
    rows.push(el('div', { text: `השיחה הגיעה לתקרת התקציב (${money(c.budget_cents)}). כדי להמשיך — העלו את התקרה.` }));
    rows.push(el('div', { class: 'row' }, [
      el('span', { text: 'תקרה חדשה ($):' }), input,
      el('button', { type: 'button', class: 'btn sm primary', text: 'העלה והמשך', onclick: () => act('conv-budget', { budget_cents: Math.round(Number(input.value) * 100) }) }),
    ]));
  } else if (c.status === 'terminated') {
    rows.push(el('div', { text: 'הסשן הסתיים ואי אפשר להמשיך בו. ההיסטוריה נשמרת כאן; להמשך — פתחו שיחה חדשה.' }));
  } else if (c.status === 'running') {
    rows.push(el('div', { text: c.status_detail === 'retrying' ? 'תקלה זמנית בפלטפורמה — מנסה שוב לבד…' : 'הסוכן עובד…' }));
  }
  if (c.last_error && c.status !== 'running') rows.push(el('div', { class: 'hint', text: `שגיאה אחרונה: ${c.last_error}` }));
  strip.replaceChildren(...rows);
  strip.hidden = rows.length === 0;
}

function eventNode(e) {
  switch (e.kind) {
    case 'user': return el('div', { class: 's-msg s-msg--user', dir: 'auto', text: e.text });
    case 'agent': { const n = el('div', { class: 's-msg s-msg--agent md', dir: 'auto' }); n.append(renderMarkdown(e.text)); return n; }
    case 'tool': return el('details', { class: 's-tool', dataset: { tool: e.id } }, [
      el('summary', {}, [el('span', { class: 'n', text: `${e.ask ? '🔐' : '🔧'} ${e.name}` }), el('span', { class: 'c', text: e.summary })]),
      e.summary ? el('pre', { text: e.summary }) : null,
    ]);
    case 'result': return { result: e };
    case 'error': return el('div', { class: 's-err', text: `⚠ ${e.text}${e.retry === 'retrying' ? ' (מנסה שוב)' : ''}` });
    case 'idle': return el('div', { class: 's-div', text: `— ${IDLE_REASON[e.reason] || e.reason} —` });
    case 'end': return el('div', { class: 's-div', text: '— הסשן הסתיים —' });
    case 'note': return el('div', { class: 's-div', text: e.text });
    default: return null;
  }
}

function appendEvents(events) {
  const log = $('#sLog');
  const stick = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
  for (const e of events) {
    if (e.kind === 'user' && S.pending.length) S.pending.shift()?.remove();   // ההודעה האמיתית הגיעה — מסירים את הזמנית
    const n = eventNode(e);
    if (!n) continue;
    if (n.result) {
      // תוצאת כלי נכנסת לתוך שורת הכלי שלה (או לאחרונה, אם אין התאמה)
      const host = (n.result.for && log.querySelector(`.s-tool[data-tool="${CSS.escape(n.result.for)}"]`)) || [...log.querySelectorAll('.s-tool')].pop();
      if (host) {
        if (n.result.error) host.classList.add('s-tool--err');
        if (n.result.text) host.append(el('pre', { text: n.result.text }));
      }
      continue;
    }
    log.append(n);
  }
  if (stick) log.scrollTop = log.scrollHeight;
}

/** משיכת אירועים חדשים מהשרת שלנו. force = גם לבקש מהשרת לסנכרן עכשיו מול הפלטפורמה. */
async function refresh(force = false) {
  if (!S.conv) return;
  const id = S.conv.id;
  try {
    const r = await call('conv-events', { id, since: S.lastSeq, force });
    if (!S.conv || S.conv.id !== id) return;
    const wasRunning = S.conv.status === 'running';
    S.conv = r.conv;
    S.lastSeq = r.last_seq;
    appendEvents(r.events);
    renderConv();
    if (r.sync_error) toast(r.sync_error, 'error');
    // ריצה שנגמרה עכשיו: בדיקה אחת נוספת מול הפלטפורמה. הודעה שחיכתה בתור מתחילה תור חדש מיד אחרי
    // הסיום, ובלי הבדיקה הזו הדף היה נעצר על "ממתין לך" בזמן שהסוכן עובד.
    if (wasRunning && r.conv.status === 'idle' && !force) { S.timer = setTimeout(() => refresh(true), 4000); return; }
  } catch (e) {
    if (e.status === 401 || e.status === 403) { stopPolling(); showBlocked(e); return; }
    toast(e.message, 'error');
  }
  schedule();
}

/** מתזמן בדיקה הבאה רק כשיש ריצה פעילה והלשונית גלויה. המרווח מתארך: 3 ← 6 ← 10 שניות. */
function schedule() {
  stopPolling(false);
  const c = S.conv;
  if (!c || document.hidden || !(c.status === 'running' || c.more)) { S.ticks = 0; return; }
  const delay = S.ticks < 20 ? 3000 : S.ticks < 60 ? 6000 : 10000;
  S.ticks++;
  S.timer = setTimeout(() => refresh(false), delay);
}
function stopPolling(reset = true) {
  if (S.timer) clearTimeout(S.timer);
  S.timer = null;
  if (reset) S.ticks = 0;
}

async function act(action, payload = {}) {
  if (S.busy || !S.conv) return;
  S.busy = true;
  renderConv();
  try {
    S.conv = (await call(action, { id: S.conv.id, ...payload })).conv;
    S.ticks = 0;
  } catch (e) {
    toast(e.message, 'error');
  }
  S.busy = false;
  renderConv();
  await refresh(false);
}

async function send() {
  const box = $('#sText');
  const text = box.value.trim();
  if (!text || S.busy || !S.conv) return;
  S.busy = true;
  renderConv();
  const ghost = el('div', { class: 's-msg s-msg--user s-msg--pending', dir: 'auto', text });
  $('#sLog').append(ghost);
  $('#sLog').scrollTop = $('#sLog').scrollHeight;
  S.pending.push(ghost);
  box.value = '';
  autosize();
  try {
    S.conv = (await call('conv-send', { id: S.conv.id, text })).conv;
    S.ticks = 0;
  } catch (e) {
    ghost.remove();
    S.pending = S.pending.filter((n) => n !== ghost);
    box.value = text;
    autosize();
    toast(e.message, 'error');
  }
  S.busy = false;
  renderConv();
  await refresh(false);
}

function autosize() {
  const t = $('#sText');
  t.style.height = 'auto';
  t.style.height = `${Math.min(t.scrollHeight, window.innerHeight * 0.4)}px`;
}

/* ── שיחה חדשה והגדרות ─────────────────────────────────────────── */

function openNew() {
  const s = S.state.settings;
  $('#nTitle').value = '';
  $('#nRepo').value = s.repo || '';
  $('#nBranch').value = 'main';
  $('#nModel').value = s.model || '';
  $('#nBudget').value = String(Math.round((s.budget_cents ?? 2000) / 100));
  $('#nMerge').checked = !!s.allow_merge;
  $('#nEngines').replaceChildren(...S.state.engines.map((g) => el('div', {
    class: `s-engine${g.enabled ? ' is-on' : ''}`, 'aria-disabled': g.enabled ? null : 'true',
  }, [el('b', { text: `${g.enabled ? '✓' : '🔒'} ${g.label}` }), el('small', { text: g.enabled ? 'קרדיט API' : (g.note || 'לא זמין') })])));
  $('#sNewDlg').showModal();
}

async function createConv(ev) {
  ev.preventDefault();
  try {
    const r = await call('conv-create', {
      engine: 'platform', title: $('#nTitle').value, repo: $('#nRepo').value.trim(), base_branch: $('#nBranch').value.trim() || 'main',
      model: $('#nModel').value.trim(), budget_cents: Math.round(Number($('#nBudget').value || 0) * 100), allow_merge: $('#nMerge').checked,
    });
    $('#sNewDlg').close();
    S.state.convs.unshift(r.conv);
    await openConv(r.conv.id);
  } catch (e) { toast(e.message, 'error'); }
}

function openSettings() {
  const s = S.state.settings;
  const c = S.state.connections;
  $('#setConn').replaceChildren(
    el('div', { text: `${c.anthropic ? '✓' : '✗'} מפתח Anthropic ${c.anthropic ? 'מחובר' : 'חסר'}` }),
    el('div', { text: `${c.github ? '✓' : '✗'} GitHub ${c.github ? `מחובר (${c.github_login || 'אסימון'})` : 'חסר'}` }),
    el('div', { class: 'hint' }, ['החיבורים מנוהלים בלוח: ', el('a', { href: './index.html', text: 'הגדרות ← חיבורים' })]),
  );
  $('#setModel').value = s.model || '';
  $('#setRepo').value = s.repo || '';
  $('#setBudget').value = String(Math.round((s.budget_cents ?? 2000) / 100));
  $('#setMerge').checked = !!s.allow_merge;
  $('#setHookUrl').value = s.webhook_url || '';
  $('#setHookSecret').value = '';
  $('#setHookState').textContent = s.webhook_secret_set
    ? (s.webhook_last_at ? `סוד שמור. ה-webhook האחרון התקבל ${timeAgo(s.webhook_last_at)}.` : 'סוד שמור. עוד לא התקבל אף webhook.')
    : 'לא הוגדר סוד — העדכון ברקע נשען על הקורן (פעם בדקה).';
  $('#sSetDlg').showModal();
}

async function saveSettings(ev) {
  ev.preventDefault();
  const patch = {
    model: $('#setModel').value.trim(), repo: $('#setRepo').value.trim(),
    budget_cents: Math.round(Number($('#setBudget').value || 0) * 100), allow_merge: $('#setMerge').checked,
  };
  if ($('#setHookSecret').value.trim()) patch.webhook_secret = $('#setHookSecret').value.trim();
  try {
    await call('settings-save', patch);
    $('#sSetDlg').close();
    toast('נשמר', 'ok');
    if (await loadState() && !S.conv) renderList();
  } catch (e) { toast(e.message, 'error'); }
}

/* ── חיבור האירועים ────────────────────────────────────────────── */

$('#sNew').addEventListener('click', openNew);
$('#sNewForm').addEventListener('submit', createConv);
$('#sSettings').addEventListener('click', openSettings);
$('#sSetForm').addEventListener('submit', saveSettings);
$('#sRefresh').addEventListener('click', () => refresh(true));
$('#sStop').addEventListener('click', () => act('conv-interrupt'));
$('#sCompose').addEventListener('submit', (e) => { e.preventDefault(); send(); });
$('#sText').addEventListener('input', autosize);
$('#sText').addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
});
for (const b of document.querySelectorAll('dialog [data-close]')) b.addEventListener('click', () => b.closest('dialog').close());

// חזרה ללשונית = בדיקה אחת; לשונית מוסתרת = בלי פניות בכלל (הקורן וה-webhook ממשיכים בשרת).
document.addEventListener('visibilitychange', () => {
  if (document.hidden) stopPolling(false);
  else if (S.conv) refresh(false);
});
window.addEventListener('popstate', route);

async function route() {
  const m = location.hash.match(/^#c(\d+)$/);
  const first = !S.state;
  if (first && !(await loadState())) return;
  if (m && S.state.convs.some((c) => c.id === Number(m[1]))) {
    if (!S.conv || S.conv.id !== Number(m[1])) await openConv(Number(m[1]));
  } else {
    await showList(!first);
  }
}

route();
