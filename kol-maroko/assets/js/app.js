// קול מרוקו — המעטפת: מי אני, לשוניות, חברים ויומן. הסטודיו — studio.js.
'use strict';

const $ = (s, el = document) => el.querySelector(s);
const main = $('#main');
const state = { user: null, csrf: '', roles: {}, version: '' };
// P1: חמשת התפקידים — השמות והדרגות מהשרת (me ← roles, rank)
const ACTIONS = {
  publish: '🚀 פרסם', publish_failed: '⚠️ פרסום נכשל', version_create: '📦 יצר גרסה', import: '📥 ייבא את העמוד מהאתר',
  word_add: '📚 הוסיף מילה', word_edit: '📚 ערך מילה', word_delete: '📚 מחק מילה', site_switch: '🔀 החליף את מה שהאתר מציג',
  audio_import: '🎙️ ייבא הקלטות', code_create: '</> יצר את קבצי הקוד', code_save: '</> שמר קובץ קוד', code_reset: '</> חזר לתבנית המובנית',
  snapshot: '🛟 שמר נקודת שחזור', snapshot_restore: '↩️ שחזר לנקודה', trash_restore: '↩️ שחזר מהסל', trash_purge: '🗑️ מחק סופית',
  backup: '💾 הוריד גיבוי מלא', version_to_tool: '↩️ החזיר את הכלי לגרסה', topic_rename: '🏷️ שינה שם של נושא',
  agent_create: '🤖 יצר כניסה לצ\'אט', agent_revoke: '🤖 ביטל כניסה לצ\'אט', words_csv: '📊 עדכן מילים מאקסל', topic_rename: '🏷️ שינה שם של נושא', topic_delete: '🏷️ הסיר נושא',
  key_rotate: '🔑 יצר מפתח חדש', key_activate: '🔑 הפעיל מפתח חדש', site_set: '🔗 שינה כתובת אתר',
  person_invite: '✉️ הזמין עוזר', person_invite_failed: '⚠️ הזמנה ממתינה', owner_set: '⚙️ שינה הגדרה של בעל האתר', plugin_zip: '⬇️ הוריד את התוסף', pkg_publish_pending: '⏳ ביקש לפרסם',
  pkg_publish_approved: '✅ אישר פרסום', pkg_publish_rejected: '✋ דחה פרסום', login: '🔑 נכנס', member_add: '➕ הוסיף חבר', file_add: '⬆️ העלה קובץ', file_delete: '🗑️ מחק קובץ', disk_cap: '💽 שינה תקרת קבצים', restore: '↺ שחזר טיוטה', member_role: '🔁 שינה תפקיד', member_remove: '➖ הסיר חבר', member_linked: '🔗 חבר נכנס לראשונה',
};

// ── עזרים ────────────────────────────────────────────────
function h(tag, attrs = {}, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v == null || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat()) if (kid != null && kid !== false) el.append(kid);
  return el;
}

async function api(action, body, query = '') {
  const opt = body === undefined ? {} : {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Kol': '1', 'X-CSRF': state.csrf },
    body: JSON.stringify(body),
  };
  let res, data;
  try {
    res = await fetch(`api.php?action=${action}${query}`, opt);
    data = await res.json();
  } catch {
    throw Object.assign(new Error(res ? 'תשובה לא תקינה מהשרת' : 'אין חיבור לשרת'), { status: res?.status || 0 });
  }
  if (!data.ok) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status, data });
  return data;
}

function toast(msg, err = false) {
  const el = $('.toast');
  el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

function hue(s) { let x = 0; for (const c of s) x = (x * 31 + c.codePointAt(0)) % 360; return x; }
function initials(s) { return s.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('') || '?'; }
function when(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Jerusalem' });
}
function day(iso) {
  return new Date(iso).toLocaleDateString('he-IL', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'Asia/Jerusalem' });
}

(() => {
  const vv = window.visualViewport; if (!vv) return;
  const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
  vv.addEventListener('resize', set); set();
})();
document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

function sheet(title, ...body) {
  const dlg = $('#sheet');
  $('#sheet-title').textContent = title;
  $('#sheet-body').replaceChildren(...body.flat().filter(x => x != null && x !== false));   // replaceChildren(null) כותב "null"
  if (!dlg.open) dlg.showModal();
  return dlg;
}

// ── ניתוב ────────────────────────────────────────────────
const TABS = [
  { id: 'studio', label: '🎨 סטודיו', min: 'content_editor' },
  { id: 'words', label: '📚 נתונים', min: 'content_editor' },          // N3: המילים — הרשימה הראשונה
  { id: 'code', label: '</> קוד', min: 'content_editor' },
  { id: 'editor', label: '🎨 עורך', min: 'content_editor' },
  { id: 'project', label: '📁 פרויקט', min: 'content_editor' },
  { id: 'files', label: '🗂️ קבצים', min: 'content_editor' },
  { id: 'restore', label: '↩️ שחזור', min: 'content_editor' },
  { id: 'publish', label: '🚀 פרסום', min: 'content_editor' },
  { id: 'inbox', label: '📬 פניות', min: 'content_editor' },                // M5 (assets/js/inbox.js) — את המונה מצייר Inbox על הקישור
  { id: 'members', label: '👥 אנשים', min: 'page_owner' },          // P1 (assets/js/people.js)
  { id: 'log', label: '📜 יומן', min: 'page_owner' },
  { id: 'settings', label: '⚙️ בעל האתר', min: 'page_owner' },     // P1: לבעל העמוד — לקריאה, במילים
  { id: 'dev', label: '🛠️ מפתח', min: 'content_editor', dev: true },  // מי מפתח — מהשרת (user.developer)
];
const RANK = new Proxy({}, { get: (_, r) => (state.rank || {})[r] || 0 });
// מסכים ישנים (פרסום, שחזור) מכירים editor / publisher / owner — בעל העמוד ובעל האתר הם "owner" שם
const legacyRole = r => (RANK[r] >= RANK.page_owner ? 'owner' : RANK[r] >= RANK.publisher ? 'publisher' : 'editor');
// המסמך המוקשח (editor.php — המדיניות המלאה של C1 שלב ב): בו רצים העורך החזותי ועורך הקוד בלבד. שאר המסכים — ב-index.php (הסטודיו, שלב א).
const HARD = /\/editor\.php$/.test(location.pathname), HARD_TABS = ['editor', 'project'];
// הפניה מוקדמת, לפני כל בקשה: מי שנכנס (או מרענן) ב-index.html#/editor עובר מיד למסמך המוקשח — פעם אחת, בלי טעינה כפולה
if (!HARD && /^#\/editor(\/|$)/.test(location.hash)) location.replace('editor.php' + location.hash);
const tabHref = id => (HARD ? (HARD_TABS.includes(id) ? '#/' + id : 'index.php#/' + id) : HARD_TABS.includes(id) ? 'editor.php#/' + id : '#/' + id);

function route() {
  const tabs = TABS.filter(t => RANK[state.user.role] >= RANK[t.min] && (!t.dev || state.user.developer));
  const [, hashTab, sub, arg] = location.hash.split('/');           // #/dev/log/<מזהה בקשה>
  let cur = tabs.find(t => hashTab === t.id) || (HARD ? tabs.find(t => t.id === 'editor') : null) || tabs[0];
  if (HARD && !HARD_TABS.includes(cur.id)) { location.replace('index.php' + (location.hash || '#/' + cur.id)); return; }   // מסך אחר — במסמך הרגיל
  if (!HARD && cur.id === 'editor') { location.replace('editor.php' + (location.hash || '#/editor')); return; }               // העורך — תמיד תחת המדיניות המלאה
  const nav = $('#tabs');
  nav.hidden = tabs.length < 2;
  nav.replaceChildren(...tabs.map(t => h('a', { href: tabHref(t.id), 'aria-current': t === cur ? 'page' : null }, t.label)));
  People.readOnly(cur.id);                                            // P1: עורך תוכן — "לקריאה בלבד"
  if (cur.id !== 'studio') Studio.unmount();
  if (cur.id !== 'code') Code.unmount();
  if (cur.id === 'inbox') return Inbox.view(main);                    // M5
  ({ studio: viewStudio, words: () => Words.view(main), code: () => Code.view(main), project: () => Project.view(main), editor: () => Editor.view(main, sub && decodeURIComponent(sub)), restore: () => Restore.view(main, legacyRole(state.user.role)), files: () => Library.view(main, state.user.role === 'site_owner', RANK[state.user.role] >= RANK.page_owner),
    publish: () => Publish.view(main, legacyRole(state.user.role)), settings: () => People.owner(main), members: () => People.view(main), log: viewLog, dev: () => Dev.view(main, sub, arg && decodeURIComponent(arg)) })[cur.id]();
}
window.addEventListener('hashchange', () => state.user?.role && route());

// ── מסכים ────────────────────────────────────────────────
function viewGate(icon, title, text) {
  $('#tabs').hidden = true;
  main.replaceChildren(h('section', { class: 'gate-msg' },
    h('span', { class: 'big', 'aria-hidden': 'true' }, icon), h('h2', {}, title), h('p', { class: 'muted' }, text)));
}

function viewStudio() {
  Studio.mount(main);
}

const logFilter = { person: '', source: '' };
async function viewLog() {
  main.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
  try {
    const q = (logFilter.person ? '&person=' + encodeURIComponent(logFilter.person) : '') + (logFilter.source ? '&source=' + logFilter.source : '');
    const { audit, people, all } = await api('audit', undefined, q);
    // P1 (08 §6): סינון לפי אדם ולפי מקור
    const sel = (key, opts, label) => {
      const s = h('select', { 'aria-label': label, onchange: () => { logFilter[key] = s.value; viewLog(); } }, opts.map(([v, t]) => h('option', { value: v, selected: String(v) === logFilter[key] }, t)));
      return s;
    };
    const filters = h('div', { class: 'toolbar log-filters' },
      sel('person', [['', 'כל האנשים'], ...people.map(p => [String(p.id), p.name || p.email || ('#' + p.id)])], 'אדם'),
      sel('source', [['', 'כל המקורות'], ['studio', 'סטודיו'], ['agent', '🤖 צ\'אט'], ['import', 'ייבוא'], ['ai', '✨ בינה'], ['system', 'מערכת']], 'מקור'));
    const hint = h('p', { class: 'hint' }, all ? 'מי עשה מה בכלי — הכול, כולל החיבור והמכסות. 100 האחרונים.' : 'מי עשה מה בעמוד. 100 האחרונים.');
    if (!audit.length) { main.replaceChildren(hint, filters, h('p', { class: 'empty' }, 'אין שורות.')); return; }
    const rows = []; let last = '';
    for (const a of audit) {
      const d = day(a.at);
      if (d !== last) { rows.push(h('li', { class: 'log-day' }, d)); last = d; }
      const [ic, ...txt] = (ACTIONS[a.action] || `• ${a.action}`).split(' ');
      rows.push(h('li', { class: 'card' },
        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
        h('div', { class: 'body' },
          h('div', { class: 'l1' }, h('span', {}, `${a.name} ${txt.join(' ')}`),
            h('span', { class: 't' }, new Date(a.at).toLocaleTimeString('he-IL', { timeStyle: 'short', timeZone: 'Asia/Jerusalem' }))),
          h('div', { class: 'l2', dir: 'auto' }, a.detail))));
    }
    main.replaceChildren(hint, filters, h('ul', { class: 'list' }, rows));
  } catch (e) { main.replaceChildren(h('p', { class: 'error' }, e.message)); }
}

// ── התחלה ────────────────────────────────────────────────
(async () => {
  try {
    const d = await api('me');
    Object.assign(state, { user: d.user, csrf: d.csrf, roles: d.roles, rank: d.rank, version: d.version || '' });
    Dev.start(state);                                                  // 🐞 בכותרת, ושגיאות דפדפן ליומן
    $('#who').textContent = `${d.user.name}${d.user.role ? ' · ' + state.roles[d.user.role] : ''}`;
    if (!d.user.role) {
      if (d.user.removed) viewGate('🚪', 'הוסרת מהכלי', 'בעל העמוד הסיר אותך. לחזרה — פנה אליו.');
      else viewGate('🔒', 'נכנסת. בעל העמוד עוד לא נתן לך תפקיד', 'הוא רואה שביקשת גישה, ויוסיף אותך בתפקיד המתאים.');
      return;
    }
    People.bell();                                                     // P1: 🔔 הודעות
    route();
  } catch (e) {
    if (e.status === 401) viewGate('🔑', 'נדרשת כניסה', 'נכנסים לכלי דרך הכלים הדיגיטליים.');
    else viewGate('⚠️', 'הכלי לא נטען', e.message);
  }
})();
