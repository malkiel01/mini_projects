// קול מרוקו — המעטפת: מי אני, לשוניות, חברים ויומן. הסטודיו נבנה בשלב 2 (docs/spec.md, פרק 13).
'use strict';

const $ = (s, el = document) => el.querySelector(s);
const main = $('#main');
const state = { user: null, csrf: '', roles: {} };
const ROLE_NAMES = { owner: 'בעלים', publisher: 'מפרסם', editor: 'עורך' };
const ACTIONS = {
  member_add: '➕ הוסיף חבר', member_role: '🔁 שינה תפקיד', member_remove: '➖ הסיר חבר', member_linked: '🔗 חבר נכנס לראשונה',
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
  if (!data.ok) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status });
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
  $('#sheet-body').replaceChildren(...body);
  if (!dlg.open) dlg.showModal();
  return dlg;
}

// ── ניתוב ────────────────────────────────────────────────
const TABS = [
  { id: 'studio', label: '🎨 סטודיו', min: 'editor' },
  { id: 'members', label: '👥 חברים', min: 'owner' },
  { id: 'log', label: '📜 יומן', min: 'owner' },
];
const RANK = { editor: 1, publisher: 2, owner: 3 };

function route() {
  const tabs = TABS.filter(t => RANK[state.user.role] >= RANK[t.min]);
  const cur = tabs.find(t => location.hash === '#/' + t.id) || tabs[0];
  const nav = $('#tabs');
  nav.hidden = tabs.length < 2;
  nav.replaceChildren(...tabs.map(t => h('a', { href: '#/' + t.id, 'aria-current': t === cur ? 'page' : null }, t.label)));
  ({ studio: viewStudio, members: viewMembers, log: viewLog })[cur.id]();
}
window.addEventListener('hashchange', () => state.user?.role && route());

// ── מסכים ────────────────────────────────────────────────
function viewGate(icon, title, text) {
  $('#tabs').hidden = true;
  main.replaceChildren(h('section', { class: 'gate-msg' },
    h('span', { class: 'big', 'aria-hidden': 'true' }, icon), h('h2', {}, title), h('p', { class: 'muted' }, text)));
}

function viewStudio() {
  main.replaceChildren(
    h('div', { class: 'summary' },
      h('div', { class: 'line' }, h('b', {}, 'הסטודיו בבנייה')),
      h('p', { class: 'muted' }, 'עריכת העמוד ', h('span', { dir: 'ltr' }, 'maghreb.co.il/lexicon'), ' תגיע בשלב הבא.')),
    h('p', { class: 'hint' }, `התפקיד שלך: ${ROLE_NAMES[state.user.role]}.`));
}

async function viewMembers() {
  main.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
  try { renderMembers(await api('members')); } catch (e) { main.replaceChildren(h('p', { class: 'error' }, e.message)); }
}

function roleSelect(value, name = 'role') {
  return h('select', { name, 'aria-label': 'תפקיד' },
    Object.entries(state.roles).map(([k, v]) => h('option', { value: k, selected: k === value }, v)));
}

async function act(action, body, okMsg) {
  try {
    const d = await api(action, body);
    renderMembers(d);
    if (okMsg) toast(okMsg);
    return true;
  } catch (e) { toast(e.message, true); return false; }
}

function renderMembers(d) {
  const email = h('input', { type: 'email', name: 'email', dir: 'ltr', inputmode: 'email', placeholder: 'מייל', 'aria-label': 'מייל', required: true });
  const role = roleSelect('editor');
  const form = h('form', {
    class: 'add-form', onsubmit: async e => {
      e.preventDefault();
      if (await act('member_add', { email: email.value, role: role.value }, 'נוסף. יוכל להיכנס אחרי שתאשר לו את הכלי בפלטפורמה')) email.value = '';
    },
  }, email, role, h('button', { class: 'btn primary', type: 'submit' }, h('span', { 'aria-hidden': 'true' }, '+'), ' חבר'));

  const requests = d.requests.length ? [
    h('h2', { class: 'sec-title' }, `ביקשו גישה · ${d.requests.length}`),
    h('ul', { class: 'list' }, d.requests.map(r => h('li', { class: 'card', tabindex: 0, role: 'button', onclick: () => requestSheet(r) },
      h('span', { class: 'avatar', style: `--h:${hue(r.sub)}`, 'aria-hidden': 'true' }, initials(r.name || r.email || '?')),
      h('div', { class: 'body' },
        h('div', { class: 'l1' }, h('span', {}, r.name || 'בלי שם'), h('span', { class: 't' }, when(r.at))),
        h('div', { class: 'l2', dir: 'auto' }, r.email || '')),
      h('span', { class: 'chip warn' }, 'ממתין')))),
  ] : [];

  main.replaceChildren(
    h('p', { class: 'hint' }, 'מי עורך ומי מפרסם. עורך שומר טיוטות; מפרסם גם שולח לאתר. הכלי פתוח רק למי שאישרת לו גם בפלטפורמה.'),
    form, ...requests,
    h('h2', { class: 'sec-title' }, `חברים · ${d.members.length}`),
    d.members.length
      ? h('ul', { class: 'list' }, d.members.map(m => h('li', { class: 'card', tabindex: 0, role: 'button', onclick: () => memberSheet(m) },
        h('span', { class: 'avatar', style: `--h:${hue(m.email || m.name)}`, 'aria-hidden': 'true' }, initials(m.name || m.email || '?')),
        h('div', { class: 'body' },
          h('div', { class: 'l1' }, h('span', {}, m.name || m.email)),
          h('div', { class: 'l2' }, m.joined ? (m.last_seen ? `נכנס לאחרונה ${when(m.last_seen)}` : 'עוד לא נכנס') : 'הוזמן — עוד לא נכנס')),
        h('span', { class: `chip role ${m.role === 'publisher' ? 'ok' : ''}` }, state.roles[m.role]))))
      : h('p', { class: 'empty' }, 'עוד אין חברים. מייל ותפקיד, ואז "+ חבר".'));
  document.querySelectorAll('.card[role=button]').forEach(c => c.addEventListener('keydown', e => { if (e.key === 'Enter') c.click(); }));
}

function requestSheet(r) {
  const role = roleSelect('editor');
  const dlg = sheet(r.name || r.email || 'בקשת גישה',
    h('p', { class: 'muted', dir: 'auto' }, r.email || ''),
    h('label', { class: 'f' }, h('span', {}, 'תפקיד'), role),
    h('div', { class: 'row-btns' },
      h('button', { type: 'button', class: 'btn primary', onclick: async () => { if (await act('member_add', { sub: r.sub, role: role.value }, 'נוסף')) dlg.close(); } }, '✓ הוספה'),
      h('button', { type: 'button', class: 'btn ghost danger', onclick: async () => { if (await act('request_dismiss', { sub: r.sub })) dlg.close(); } }, '✕ התעלמות')));
}

function memberSheet(m) {
  const role = roleSelect(m.role);
  const dlg = sheet(m.name || m.email,
    h('dl', { class: 'cells' },
      h('div', {}, h('dt', {}, 'מייל'), h('dd', { dir: 'ltr', class: 'one' }, m.email || '—')),
      h('div', {}, h('dt', {}, 'נוסף'), h('dd', {}, when(m.added_at))),
      h('div', {}, h('dt', {}, 'כניסה אחרונה'), h('dd', {}, m.last_seen ? when(m.last_seen) : '—'))),
    h('label', { class: 'f' }, h('span', {}, 'תפקיד'), role),
    h('div', { class: 'row-btns' },
      h('button', { type: 'button', class: 'btn primary', onclick: async () => { if (await act('member_set', { id: m.id, role: role.value }, 'נשמר')) dlg.close(); } }, 'שמירה'),
      h('button', {
        type: 'button', class: 'btn ghost danger', onclick: async () => {
          if (confirm(`להסיר את ${m.name || m.email} מהכלי?`) && await act('member_remove', { id: m.id }, 'הוסר')) dlg.close();
        },
      }, '🗑️ הסרה')));
}

async function viewLog() {
  main.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
  try {
    const { audit } = await api('audit');
    if (!audit.length) { main.replaceChildren(h('p', { class: 'empty' }, 'היומן ריק.')); return; }
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
    main.replaceChildren(h('p', { class: 'hint' }, 'מי עשה מה בכלי. 100 האחרונים.'), h('ul', { class: 'list' }, rows));
  } catch (e) { main.replaceChildren(h('p', { class: 'error' }, e.message)); }
}

// ── התחלה ────────────────────────────────────────────────
(async () => {
  try {
    const d = await api('me');
    Object.assign(state, { user: d.user, csrf: d.csrf, roles: d.roles });
    $('#who').textContent = `${d.user.name}${d.user.role ? ' · ' + ROLE_NAMES[d.user.role] : ''}`;
    if (!d.user.role) {
      viewGate('🔒', 'אין לך תפקיד בכלי', 'בעל המערכת יראה שביקשת גישה ויוסיף אותך כעורך או כמפרסם.');
      return;
    }
    route();
  } catch (e) {
    if (e.status === 401) viewGate('🔑', 'נדרשת כניסה', 'נכנסים לכלי דרך הכלים הדיגיטליים.');
    else viewGate('⚠️', 'הכלי לא נטען', e.message);
  }
})();
