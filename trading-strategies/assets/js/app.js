// אסטרטגיות למסחר — הממשק. אפיון: docs/spec.md, פרק 2.
// כל טקסט של המשתמש נכנס כ-textContent (לעולם לא innerHTML). אין polling: פנייה לשרת רק בניווט ובשמירה.
import * as D from './decimal.js';
import { mountMarket } from './market.js';
import { mountAnalysis } from './analysis.js';
import { mountAlerts } from './alerts.js';
import { mountCloud } from './cloud.js';

// ── עזרים ──────────────────────────────────────────────
const $ = (s, el = document) => el.querySelector(s);

/** h('div', {class, text, on:{click}, ...attrs}, ...ילדים) */
function h(tag, props = {}, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(props || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'text') el.textContent = v;
    else if (k === 'on') for (const [ev, fn] of Object.entries(v)) el.addEventListener(ev, fn);
    else if (k === 'style') el.setAttribute('style', v);
    else if (k in el && typeof v !== 'string') el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of kids.flat(Infinity)) if (c != null && c !== false) el.append(c instanceof Node ? c : String(c));
  return el;
}
const sep = () => h('i', { class: 'sep', 'aria-hidden': 'true' }, '·');
const num = (v, cls = '') => h('span', { class: 'num ' + cls }, v);

function toast(msg, err = false) {
  const el = $('.toast');
  el.textContent = msg;
  el.classList.toggle('err', err);
  el.hidden = false;
  clearTimeout(toast.t);
  toast.t = setTimeout(() => { el.hidden = true; }, err ? 4500 : 2600);
}

(() => {   // --vvh: הגובה הנראה בלי המקלדת
  const vv = window.visualViewport;
  if (!vv) return;
  const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
  vv.addEventListener('resize', set);
  set();
})();

let ME = null;   // {csrf, user, lists}

async function api(action, body = null, query = {}) {
  const qs = new URLSearchParams({ action, ...query });
  const opt = { credentials: 'same-origin', headers: {} };
  if (body) {
    opt.method = 'POST';
    opt.headers['Content-Type'] = 'application/json';
    opt.headers['X-CSRF'] = ME?.csrf || '';
    opt.body = JSON.stringify(body);
  }
  let res, data;
  try {
    res = await fetch('api.php?' + qs, opt);
    data = await res.json();
  } catch {
    throw new Error(res ? 'תשובה לא צפויה מהשרת' : 'אין חיבור לשרת');
  }
  if (!res.ok || !data.ok) throw new Error(data?.error || 'שגיאה');
  return data;
}

// ── מספרים וכסף ─────────────────────────────────────────
const CUR_SIGN = { USD: '$', ILS: '₪', EUR: '€', GBP: '£', JPY: '¥' };
const money = (v, cur) => (v == null ? '—' : D.fmt(v, 2) + ' ' + (CUR_SIGN[cur] || cur));
const signed = v => (v != null && D.sign(D.parse(v)) > 0 ? '+' : '');
const cls = v => (v == null ? '' : D.sign(D.parse(v)) > 0 ? 'pos' : D.sign(D.parse(v)) < 0 ? 'neg' : '');
const rText = r => (r == null ? 'בלי R' : signed(r) + D.fmt(r, 2) + 'R');
const iso = t => '\u2066' + t + '\u2069';   // בידוד LTR בתוך טקסט עברי (טוסט)
const pct = v => (v == null ? '—' : D.fmt(v, 1) + '%');
const QTY_SCALE = { stocks: 0, crypto: 8, forex: 2, futures: 0, options: 0 };

function nowLocal() {
  const tz = ME?.user?.timezone || 'UTC';
  try { return new Date().toLocaleString('sv-SE', { timeZone: tz }).replace(' ', 'T').slice(0, 16); }
  catch { return new Date().toISOString().slice(0, 16); }
}
const fmtDay = d => {
  try { return new Date(d + 'T12:00:00Z').toLocaleDateString('he-IL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }); }
  catch { return d; }
};
const fmtTime = t => (t ? t.slice(11, 16) : '');
const fmtDate = t => (t ? t.slice(8, 10) + '.' + t.slice(5, 7) + '.' + t.slice(2, 4) : '');
function fmtDuration(sec) {
  if (sec == null) return '—';
  const d = Math.floor(sec / 86400), hr = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
  return d ? `${d} ימים ${hr} ש׳` : hr ? `${hr} ש׳ ${m} ד׳` : `${m} ד׳`;
}
const marketName = m => ME.lists.markets[m]?.name || m;
const MARKET_SHORT = { stocks: 'מניות', crypto: 'קריפטו', forex: 'מט"ח', futures: 'חוזים', options: 'אופציות' };

// ── שלד: ניווט, כותרת ───────────────────────────────────
function setHead(title, sub = '', { back = false, actions = [] } = {}) {
  $('#title').textContent = title;
  $('#subtitle').textContent = sub;
  $('#back').hidden = !back;
  $('#head-actions').replaceChildren(...actions);
  document.title = title === 'אסטרטגיות למסחר' ? title : `${title} · אסטרטגיות למסחר`;
}
$('#back').addEventListener('click', () => (history.length > 1 ? history.back() : (location.hash = '#/market')));

function setTab(tab) {
  document.querySelectorAll('.bottom-nav a').forEach(a => {
    if (a.dataset.tab === tab) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
}

const main = $('#main');
function render(...nodes) { main.replaceChildren(...nodes.flat().filter(n => n != null && n !== false)); }

// לשוניות: שוק · ניתוח · התראות · יומן · עוד (spec-stage-a 1).
// היומן של שלב 1 — שלוש תת-לשוניות בתוך "יומן" (פרק 12, סעיף 7).
const TAB_OF = { market: 'market', chart: 'market', strategies: 'journal', strategy: 'journal', journal: 'journal', stats: 'journal',
  more: 'more', instruments: 'more', providers: 'more', admin: 'more', analysis: 'analysis', profile: 'more', alerts: 'alerts', 'method-files': 'more' };
const JOURNAL_TABS = [['strategies', '🧭 אסטרטגיות'], ['journal', '📒 עסקאות'], ['stats', '📊 סטטיסטיקה']];
function journalTabs(active) {
  return h('nav', { class: 'seg-tabs', 'aria-label': 'יומן' }, JOURNAL_TABS.map(([k, l]) =>
    h('a', { href: '#/' + k, class: 'seg-tab', 'aria-current': k === active ? 'page' : null }, l)));
}

let M = null, A = null, N = null, C = null;
async function route() {
  const [name = 'market', ...rest] = location.hash.replace(/^#\/?/, '').split('/');
  const id = rest.join('/');
  M?.teardown();
  setTab(TAB_OF[name] || 'market');
  render(h('p', { class: 'loading' }, 'טוען…'));
  try {
    if (name === 'strategy' && id) await viewStrategy(+id);
    else if (name === 'strategies') await viewStrategies();
    else if (name === 'journal') await viewJournal();
    else if (name === 'stats') await viewStats();
    else if (name === 'more') await viewMore();
    else if (name === 'instruments') await viewInstruments();
    else if (name === 'providers') await M.viewProviders();
    else if (name === 'admin') await M.viewAdmin();
    else if (name === 'chart' && id) await M.viewChart(decodeURIComponent(id));
    else if (name === 'analysis') await A.viewAnalysis(id ? decodeURIComponent(id) : null);
    else if (name === 'profile') await A.viewProfile();
    else if (name === 'alerts') await N.viewAlerts();
    else if (name === 'method-files') await C.viewMethodFiles();
    else await M.viewMarket();
    if (['strategies', 'journal', 'stats'].includes(name)) main.prepend(journalTabs(name));
  } catch (e) {
    render(h('p', { class: 'error', role: 'alert' }, e.message));
  }
  main.scrollTop = 0;
}
window.addEventListener('hashchange', route);

// ── רכיבי טופס ──────────────────────────────────────────
const field = (label, input, wide = false) => h('label', { class: 'f' + (wide ? ' wide' : '') }, h('span', {}, label), input);
const numInput = (name, value = '', ph = '') => h('input', { name, value: value ?? '', inputmode: 'decimal', dir: 'ltr', autocomplete: 'off', placeholder: ph });

/** בורר מקטעים. options: [[value, label]] */
function seg(name, options, value, { grid = 0, label = '', onChange } = {}) {
  const fs = h('fieldset', { class: 'seg' + (grid ? ' grid' : ''), style: grid ? `--cols:${grid}` : null, 'aria-label': label });
  for (const [v, l] of options) {
    const inp = h('input', { type: 'radio', name, value: v });
    inp.checked = String(v) === String(value ?? '');
    if (onChange) inp.addEventListener('change', () => onChange(v));
    fs.append(h('label', {}, inp, h('span', {}, l)));
  }
  return fs;
}
const segVal = (root, name) => root.querySelector(`input[name="${name}"]:checked`)?.value ?? '';

function select(name, options, value, attrs = {}) {
  const s = h('select', { name, ...attrs });
  for (const [v, l] of options) {
    const o = h('option', { value: v }, l);
    if (String(v) === String(value ?? '')) o.selected = true;
    s.append(o);
  }
  return s;
}

/** גיליון עריכה: dialog עם כותרת דביקה ו"שמירה". מחזיר {dlg, body, close}. */
function sheet(title, { saveLabel = 'שמירה', onSave, footer } = {}) {
  const err = h('p', { class: 'error', role: 'alert', hidden: true });
  const body = h('div', { class: 'sheet-body' });
  const rawAppend = body.append.bind(body);
  body.append = (...n) => rawAppend(...n.flat().filter(x => x != null && x !== false));   // תנאי שלא התקיים — לא "null"
  const save = onSave ? h('button', { type: 'submit', class: 'btn primary' }, saveLabel) : null;
  const form = h('form', { method: 'dialog', novalidate: true },
    h('header', { class: 'sheet-head' },
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', on: { click: () => close() } }, '✕'),
      h('h2', {}, title), save),
    body);
  const dlg = h('dialog', { 'aria-label': title }, form);
  const close = () => { dlg.close(); };
  dlg.addEventListener('close', () => dlg.remove());
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (!onSave) return close();
    err.hidden = true;
    save.disabled = true;
    try {
      if ((await onSave(form)) !== false) close();
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
      err.scrollIntoView({ block: 'nearest' });
    } finally {
      save.disabled = false;
    }
  });
  document.body.append(dlg);
  queueMicrotask(() => { body.append(err); if (footer) body.append(footer); dlg.showModal(); });
  return { dlg, body, form, close };
}

function confirmSheet(title, text, label, fn) {
  const s = sheet(title, { saveLabel: label, onSave: fn });
  s.body.append(h('p', {}, text));
}

/** רשימת שורות עריכה (כללים). withReq — חובה/רצוי. */
function ruleRows(items, withReq, placeholder) {
  const wrap = h('div', { class: 'rule-rows' });
  let k = 0;
  const add = (it = {}) => {
    const row = h('div', { class: 'rule-row' });
    const inp = h('input', { value: it.text ?? (typeof it === 'string' ? it : ''), maxlength: 500, placeholder, 'aria-label': placeholder });
    row.append(inp);
    if (withReq) row.append(seg('req' + ++k, [['1', 'חובה'], ['0', 'רצוי']], it.required === false ? '0' : '1', { label: 'חובה או רצוי' }));
    row.append(h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'מחיקת שורה', on: { click: () => row.remove() } }, '✕'));
    list.append(row);
    return inp;
  };
  const list = h('div', { class: 'rule-rows' });
  (items.length ? items : ['']).forEach(add);
  wrap.append(list, h('button', { type: 'button', class: 'btn ghost sm', on: { click: () => add().focus() } }, '+ שורה'));
  wrap.value = () => [...list.children].map(r => {
    const text = r.querySelector('input:not([type=radio])').value.trim();
    if (!text) return null;
    return withReq ? { text, required: r.querySelector('input[type=radio]:checked')?.value !== '0' } : text;
  }).filter(Boolean);
  return wrap;
}

// ── מחשבון גודל פוזיציה (אפיון 2.3) — בדפדפן, בלי שמירה ─────
function positionCalc(pre = {}, onUse) {
  const u = ME.user;
  const f = {
    account: numInput('account', pre.account ?? u.account_size ?? ''),
    risk: numInput('risk', pre.risk ?? u.default_risk_pct ?? '1'),
    entry: numInput('entry', pre.entry ?? ''),
    stop: numInput('stop', pre.stop ?? ''),
    target: numInput('target', pre.target ?? '', 'רשות'),
    mult: numInput('mult', pre.mult ?? '1'),
  };
  const market = pre.market || 'stocks';
  const out = h('dl', { class: 'calc-out', 'aria-live': 'polite' });
  let units = null;
  const calc = () => {
    const p = Object.fromEntries(Object.entries(f).map(([k, el]) => [k, D.parse(el.value)]));
    out.replaceChildren();
    units = null;
    const cell = (dt, dd) => out.append(h('div', {}, h('dt', {}, dt), h('dd', {}, dd)));
    if (!p.account || !p.risk || !p.entry || !p.stop || !p.mult) { cell('תוצאה', 'ממלאים גודל חשבון, % סיכון, כניסה, סטופ ומכפיל'); return; }
    const dist = D.abs(D.sub(p.entry, p.stop));
    const perUnit = D.mul(dist, p.mult);
    const riskMoney = D.div(D.mul(p.account, p.risk), D.parse('100'), 2);
    if (D.sign(perUnit) === 0) { cell('תוצאה', 'הסטופ שווה למחיר הכניסה'); return; }
    units = D.div(riskMoney, perUnit, QTY_SCALE[market] ?? 0, 'down');   // עיגול כלפי מטה — לא לעבור את הסיכון
    const actual = D.mul(units, perUnit);
    cell('כמות', num(D.fmt(units, 8)));
    cell('סכום בסיכון', num(D.fmt(actual, 2)));
    cell('תקציב סיכון', num(D.fmt(riskMoney, 2)));
    cell('ערך פוזיציה', num(D.fmt(D.mul(D.mul(units, p.entry), p.mult), 2)));
    if (p.target) cell('יחס סיכוי/סיכון', num('1 : ' + D.fmt(D.div(D.abs(D.sub(p.target, p.entry)), dist, 2), 2)));
  };
  const s = sheet('🧮 מחשבון גודל פוזיציה', onUse ? {
    saveLabel: 'לכמות בעסקה',
    onSave: () => { if (!units || D.sign(units) <= 0) throw new Error('אין כמות לשימוש'); onUse(D.str(units)); },
  } : {});
  Object.values(f).forEach(el => el.addEventListener('input', calc));
  s.body.append(
    h('p', { class: 'hint' }, 'כמה יחידות לקנות כדי שהסטופ יעלה בדיוק את הסיכון שבחרתם. החישוב במכשיר, ולא נשמר.'),
    h('div', { class: 'fields two' }, field('גודל חשבון', f.account), field('סיכון לעסקה (%)', f.risk),
      field('מחיר כניסה', f.entry), field('מחיר סטופ', f.stop), field('מכפיל', f.mult), field('יעד', f.target)),
    out);
  calc();
}

// ── אסטרטגיות — רשימה (אפיון 2.1) ───────────────────────
let STRATS = null;
async function loadStrategies() { STRATS = (await api('strategies')).strategies; return STRATS; }

async function viewStrategies() {
  setHead('אסטרטגיות', 'הכלל תחילה, העסקה אחריו');
  const list = await loadStrategies();
  const state = { q: '', status: sessionStorage.getItem('ts-status') || 'live', market: '' };
  const ul = h('ul', { class: 'list' });
  const draw = () => {
    const q = state.q.trim().toLowerCase();
    const rows = list.filter(s =>
      (state.status === 'live' ? s.status !== 'archived' : state.status === 'all' || s.status === state.status) &&
      (!state.market || s.markets.includes(state.market)) &&
      (!q || s.name.toLowerCase().includes(q) || s.tags.some(t => t.toLowerCase().includes(q))));
    ul.replaceChildren(...(rows.length ? rows.map(strategyRow) : [h('li', { class: 'empty' },
      list.length ? 'אין אסטרטגיה שמתאימה לסינון.' : 'עוד אין אסטרטגיות. "+ אסטרטגיה" כדי לכתוב את הראשונה.')]));
  };
  const statusSeg = seg('st', [['live', 'בעבודה'], ['active', 'פעילות'], ['archived', 'ארכיון'], ['all', 'הכול']], state.status,
    { grid: 4, label: 'מצב', onChange: v => { state.status = v; sessionStorage.setItem('ts-status', v); draw(); } });
  const mk = select('market', [['', 'כל השווקים'], ...Object.entries(ME.lists.markets).map(([k, m]) => [k, m.name])], '', { 'aria-label': 'שוק' });
  mk.addEventListener('change', () => { state.market = mk.value; draw(); });
  const search = h('input', { type: 'search', placeholder: 'חיפוש לפי שם או תגית', 'aria-label': 'חיפוש', on: { input: e => { state.q = e.target.value; draw(); } } });
  render(
    h('div', { class: 'toolbar' }, search, h('button', { class: 'btn primary', type: 'button', on: { click: () => editStrategy() } }, h('span', { 'aria-hidden': 'true' }, '+'), ' אסטרטגיה')),
    statusSeg,
    new Set(list.flatMap(s => s.markets)).size > 1 ? h('div', { class: 'toolbar', style: 'margin-top:8px' }, mk) : null,
    h('div', { style: 'height:8px' }),
    ul);
  draw();
}

function strategyRow(s) {
  const st = s.stats;
  const chipCls = { active: 'ok', draft: 'warn', archived: '' }[s.status];
  const l2 = [s.markets.map(marketName).join(', ') || 'בלי שוק', ME.lists.timeframes[s.timeframe], s.trades ? `${s.trades} עסקאות` : 'אין עסקאות']
    .filter(Boolean);
  const open = () => { location.hash = '#/strategy/' + s.id; };
  return h('li', { class: 'card', tabindex: '0', role: 'button', on: { click: open, keydown: e => { if (e.key === 'Enter') open(); } } },
    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🧭'),
    h('div', { class: 'body' },
      h('div', { class: 'l1' }, h('span', {}, s.name), s.version_no ? h('span', { class: 't' }, 'v' + s.version_no) : null),
      h('div', { class: 'l2' }, l2.flatMap((x, i) => (i ? [sep(), x] : [x])))),
    h('div', { class: 'res' },
      st.count ? h('b', { class: cls(st.expectancy_r) }, num(st.expectancy_r != null ? rText(st.expectancy_r) : '—')) : null,
      st.count ? h('small', {}, 'הצלחה ', num(pct(st.win_rate))) : null,
      h('span', { class: 'chip ' + chipCls }, ME.lists.statuses[s.status])));
}

// ── אסטרטגיה — פירוט, גרסאות והשוואה ───────────────────
async function viewStrategy(id) {
  const s = (await api('strategy', null, { id })).strategy;
  const cur = s.versions.find(v => v.id === s.current_version_id) || s.versions.at(-1);
  const anyTrades = s.versions.some(v => v.trades);
  setHead(s.name, `${ME.lists.statuses[s.status]} · גרסה ${cur?.version_no ?? 1}`, {
    back: true,
    actions: [h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'עריכה', on: { click: () => editStrategy(s) } }, '✏️')],
  });
  const total = s.versions.reduce((a, v) => a + v.trades, 0);
  const all = s.stats;
  const summary = h('div', { class: 'summary' },
    h('div', { class: 'line' }, h('span', { class: 'muted' }, 'תוחלת'), h('b', { class: cls(all.expectancy_r) }, num(all.expectancy_r != null ? rText(all.expectancy_r) : '—')),
      h('span', { class: 'muted' }, `· ${total} עסקאות · הצלחה `), num(pct(all.win_rate))),
    h('div', { class: 'chips' }, s.markets.map(m => h('span', { class: 'chip' }, marketName(m))),
      s.timeframe ? h('span', { class: 'chip' }, ME.lists.timeframes[s.timeframe]) : null,
      s.style ? h('span', { class: 'chip' }, ME.lists.styles[s.style]) : null,
      s.tags.map(t => h('span', { class: 'chip' }, '#' + t))));

  const actions = h('div', { class: 'row-btns wrap' },
    h('button', { class: 'btn primary', type: 'button', on: { click: () => editTrade(null, { strategy_version_id: cur.id, strategy_id: s.id }) } }, '+ עסקה'),
    h('button', { class: 'btn ghost', type: 'button', on: { click: () => { sessionStorage.setItem('ts-jf', JSON.stringify({ strategy_id: String(s.id) })); location.hash = '#/journal'; } } }, '📒 ביומן'),
    h('button', { class: 'btn ghost', type: 'button', on: { click: () => { sessionStorage.setItem('ts-sf', JSON.stringify({ strategy_id: String(s.id) })); location.hash = '#/stats'; } } }, '📊 סטטיסטיקה'));
  const more = h('div', { class: 'row-btns wrap' },
    h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => { const r = await api('strategy_duplicate', { id: s.id }); toast('שוכפל — טיוטה חדשה'); location.hash = '#/strategy/' + r.id; } } }, '⧉ שכפול'),
    s.status === 'archived'
      ? h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => { await api('strategy_status', { id: s.id, status: 'active' }); toast('הוחזרה מהארכיון'); route(); } } }, '↩︎ החזרה מהארכיון')
      : h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => { await api('strategy_status', { id: s.id, status: 'archived' }); toast('הועברה לארכיון'); route(); } } }, '🗄️ לארכיון'),
    anyTrades ? null : h('button', { class: 'btn ghost sm danger', type: 'button', on: { click: () => confirmSheet('מחיקת אסטרטגיה', `"${s.name}" תימחק עם כל הגרסאות שלה. אין לה עסקאות.`, 'מחיקה', async () => { await api('strategy_delete', { id: s.id }); toast('נמחקה'); location.hash = '#/strategies'; }) } }, '🗑️ מחיקה'));

  render(h('div', { class: 'detail' }, summary, actions,
    s.summary ? h('p', {}, s.summary) : null,
    cur ? versionDetail(cur, s) : null,
    versionsBlock(s),
    more));
}

const EXIT_LABELS = { stop: 'סטופ', target: 'יעד רווח', partial: 'יציאה חלקית', time: 'יציאה לפי זמן', trailing: 'סטופ נגרר' };
const RISK_LABELS = { risk_pct: ['סיכון לעסקה', '%'], max_daily_loss: ['הפסד יומי מרבי', '%'], max_open: ['פוזיציות פתוחות מרבי', ''], min_rr: ['יחס סיכוי/סיכון מינימלי', ''] };

function versionDetail(v, s) {
  const block = (title, ...c) => h('section', { class: 'block' }, h('h3', {}, title), ...c);
  const exits = Object.entries(EXIT_LABELS).filter(([k]) => v.exit_rules[k]);
  const risks = Object.entries(RISK_LABELS).filter(([k]) => v.risk[k] != null);
  return h('div', { class: 'stack' },
    s.examples ? block('נכסים לדוגמה', h('p', { class: 'num', style: 'direction:ltr;text-align:end' }, s.examples)) : null,
    v.thesis ? block('💡 הרעיון', h('p', {}, v.thesis)) : null,
    v.setup.length ? block('תנאי מקדים', h('ul', {}, v.setup.map(t => h('li', {}, t)))) : null,
    block('כללי כניסה', v.entry_rules.length
      ? h('ul', {}, v.entry_rules.map(r => h('li', {}, r.text, ' ', h('span', { class: 'chip ' + (r.required ? 'warn' : '') }, r.required ? 'חובה' : 'רצוי'))))
      : h('p', { class: 'muted' }, 'עוד לא נכתבו כללי כניסה.')),
    exits.length ? block('כללי יציאה', h('dl', { class: 'kv' }, exits.map(([k, l]) => [h('dt', {}, l), h('dd', {}, v.exit_rules[k])]))) : null,
    risks.length ? block('ניהול סיכון', h('dl', { class: 'kv' }, risks.map(([k, [l, u]]) => [h('dt', {}, l), h('dd', {}, num(D.fmt(v.risk[k], 4) + u))]))) : null,
    v.notes ? block('הערות', h('p', {}, v.notes)) : null);
}

function versionsBlock(s) {
  const rows = [...s.versions].reverse().map(v => h('li', { class: 'card', style: 'cursor:default' },
    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, 'v' + v.version_no),
    h('div', { class: 'body' },
      h('div', { class: 'l1' }, h('span', {}, `גרסה ${v.version_no}` + (v.id === s.current_version_id ? ' · נוכחית' : '')),
        h('span', { class: 't' }, new Date(v.created_at * 1000).toLocaleDateString('he-IL'))),
      h('div', { class: 'l2' }, `${v.trades} עסקאות`, v.stats.count ? [sep(), 'הצלחה ', num(pct(v.stats.win_rate)), sep(), 'תוחלת ', num(v.stats.expectancy_r != null ? rText(v.stats.expectancy_r) : '—')] : null)),
    h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => { sessionStorage.setItem('ts-sf', JSON.stringify({ strategy_id: String(s.id), version_id: String(v.id) })); location.hash = '#/stats'; } } }, '📊')));
  const cmp = s.versions.length > 1
    ? h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => compareVersions(s) } }, '⇄ השוואת גרסאות') : null;
  return h('section', {},
    h('div', { class: 'toolbar', style: 'justify-content:space-between' }, h('h2', { class: 'sec-title' }, 'גרסאות'), cmp),
    h('p', { class: 'hint' }, 'שינוי בכללים כשכבר יש עסקאות על הגרסה פותח גרסה חדשה — העסקאות הקודמות נשארות על הגרסה שלהן.'),
    h('ul', { class: 'list' }, rows));
}

/** השוואה בין שתי גרסאות: מה נוסף, מה ירד, מה השתנה */
function compareVersions(s) {
  const opts = s.versions.map(v => [v.id, 'גרסה ' + v.version_no]);
  const a = select('a', opts, s.versions.at(-2).id, { 'aria-label': 'מגרסה' });
  const b = select('b', opts, s.versions.at(-1).id, { 'aria-label': 'לגרסה' });
  const out = h('div', { class: 'stack' });
  const lines = (title, x, y) => {
    const del = x.filter(t => !y.includes(t)), add = y.filter(t => !x.includes(t));
    if (!del.length && !add.length) return null;
    return h('section', { class: 'block' }, h('h3', {}, title), h('div', { class: 'diff' }, del.map(t => h('div', { class: 'del' }, t)), add.map(t => h('div', { class: 'add' }, t))));
  };
  const draw = () => {
    const va = s.versions.find(v => v.id === +a.value), vb = s.versions.find(v => v.id === +b.value);
    const rule = r => r.text + (r.required ? ' (חובה)' : ' (רצוי)');
    const blocks = [
      lines('הרעיון', va.thesis ? [va.thesis] : [], vb.thesis ? [vb.thesis] : []),
      lines('תנאי מקדים', va.setup, vb.setup),
      lines('כללי כניסה', va.entry_rules.map(rule), vb.entry_rules.map(rule)),
      ...Object.entries(EXIT_LABELS).map(([k, l]) => lines(l, va.exit_rules[k] ? [va.exit_rules[k]] : [], vb.exit_rules[k] ? [vb.exit_rules[k]] : [])),
      ...Object.entries(RISK_LABELS).map(([k, [l, u]]) => lines(l, va.risk[k] != null ? [va.risk[k] + u] : [], vb.risk[k] != null ? [vb.risk[k] + u] : [])),
      lines('הערות', va.notes ? [va.notes] : [], vb.notes ? [vb.notes] : []),
    ].filter(Boolean);
    out.replaceChildren(...(blocks.length ? blocks : [h('p', { class: 'empty' }, 'אין הבדל בין הגרסאות.')]));
  };
  a.addEventListener('change', draw);
  b.addEventListener('change', draw);
  const sh = sheet('השוואת גרסאות');
  sh.body.append(h('div', { class: 'fields two' }, field('מ־', a), field('אל', b)), out);
  draw();
}

// ── אסטרטגיה — עורך (אפיון 2.2) ─────────────────────────
function editStrategy(s = null) {
  const v = s ? (s.versions.find(x => x.id === s.current_version_id) || s.versions.at(-1)) : null;
  const L = ME.lists;
  const name = h('input', { name: 'name', value: s?.name || '', maxlength: 120, required: true });
  const summary = h('input', { name: 'summary', value: s?.summary || '', maxlength: 300 });
  const markets = h('div', { class: 'chips', role: 'group', 'aria-label': 'שווקים' },
    Object.entries(L.markets).map(([k, m]) => {
      const c = h('input', { type: 'checkbox', value: k });
      c.checked = !!s?.markets.includes(k);
      return h('label', {}, c, h('span', {}, m.name));
    }));
  const examples = h('input', { name: 'examples', value: s?.examples || '', maxlength: 300, dir: 'ltr', placeholder: 'AAPL, EUR/USD, BTC' });
  const tf = select('timeframe', [['', '—'], ...Object.entries(L.timeframes)], s?.timeframe);
  const style = select('style', [['', '—'], ...Object.entries(L.styles)], s?.style);
  const tags = h('input', { name: 'tags', value: (s?.tags || []).join(', '), placeholder: 'פריצה, מומנטום' });
  const status = seg('status', Object.entries(L.statuses), s?.status || 'draft', { label: 'מצב' });
  const thesis = h('textarea', { name: 'thesis', rows: 3, maxlength: 4000 }, v?.thesis || '');
  const setup = ruleRows(v?.setup || [], false, 'תנאי מקדים');
  const entry = ruleRows(v?.entry_rules || [], true, 'כלל כניסה');
  const exits = Object.fromEntries(Object.keys(EXIT_LABELS).map(k => [k, h('textarea', { name: 'x_' + k, rows: 2, maxlength: 1000 }, v?.exit_rules?.[k] || '')]));
  const risk = Object.fromEntries(Object.keys(RISK_LABELS).map(k => [k, numInput('r_' + k, v?.risk?.[k] ?? '')]));
  const notes = h('textarea', { name: 'notes', rows: 3, maxlength: 4000 }, v?.notes || '');
  const hasTrades = !!s?.versions.find(x => x.id === s.current_version_id)?.trades;
  const group = (title, sub, ...c) => h('section', { class: 'group' }, h('h3', {}, title, sub ? h('small', {}, sub) : null), ...c);

  const sh = sheet(s ? 'עריכת אסטרטגיה' : 'אסטרטגיה חדשה', {
    onSave: async () => {
      const body = {
        id: s?.id, name: name.value, summary: summary.value, examples: examples.value,
        markets: [...markets.querySelectorAll('input:checked')].map(i => i.value),
        timeframe: tf.value, style: style.value, status: segVal(sh.body, 'status'),
        tags: tags.value.split(/[,،]/).map(t => t.trim()).filter(Boolean),
        thesis: thesis.value, setup: setup.value(), entry_rules: entry.value(),
        exit_rules: Object.fromEntries(Object.entries(exits).map(([k, el]) => [k, el.value])),
        risk: Object.fromEntries(Object.entries(risk).map(([k, el]) => [k, el.value.trim()])),
        notes: notes.value,
      };
      const r = await api('strategy_save', body);
      toast(r.new_version ? `נשמר — נפתחה גרסה ${r.strategy.versions.at(-1).version_no}; העסקאות הקודמות נשארו על הגרסה שלהן` : 'נשמר');
      STRATS = null;
      if (!s) location.hash = '#/strategy/' + r.id; else route();
    },
  });
  sh.body.append(
    hasTrades ? h('p', { class: 'hint' }, `לגרסה ${v.version_no} כבר יש עסקאות — שינוי בכללים ישמר כגרסה חדשה. הערות מתעדכנות בלי גרסה.`) : null,
    h('div', { class: 'fields' }, field('שם *', name, true), field('תיאור קצר', summary, true)),
    h('div', { class: 'f' }, h('span', { class: 'muted', style: 'font-size:.8rem' }, 'שווקים'), markets),
    h('div', { class: 'fields' }, field('מסגרת זמן', tf), field('סגנון', style), field('נכסים לדוגמה', examples), field('תגיות (מופרדות בפסיק)', tags)),
    h('div', { class: 'f' }, h('span', { class: 'muted', style: 'font-size:.8rem' }, 'מצב'), status),
    group('💡 הרעיון', 'למה זה אמור לעבוד', thesis),
    group('תנאי מקדים', 'setup — כל כלל שורה', setup),
    group('כללי כניסה', 'יופיעו כצ\'קליסט בכל עסקה', entry),
    group('כללי יציאה', '', h('div', { class: 'fields' }, Object.entries(EXIT_LABELS).map(([k, l]) => field(l, exits[k])))),
    group('ניהול סיכון', '', h('div', { class: 'fields two' },
      Object.entries(RISK_LABELS).map(([k, [l, u]]) => field(l + (u ? ` (${u})` : ''), risk[k]))),
      h('button', { type: 'button', class: 'btn ghost sm', on: { click: () => positionCalc({ risk: risk.risk_pct.value || undefined, market: [...markets.querySelectorAll('input:checked')][0]?.value }) } }, '🧮 מחשבון גודל פוזיציה')),
    group('הערות', 'לא פותחות גרסה', notes));
}

// ── יומן — רשימה (אפיון 2.4) ────────────────────────────
const RESULT_OPTS = [['', 'כל התוצאות'], ['win', 'רווח'], ['loss', 'הפסד'], ['be', 'איזון'], ['open', 'פתוחות']];

async function strategyOptions(withNone = true) {
  const list = STRATS || await loadStrategies();
  return [['', 'כל האסטרטגיות'], ...(withNone ? [['none', 'בלי אסטרטגיה']] : []), ...list.map(s => [String(s.id), s.name + (s.status === 'archived' ? ' (ארכיון)' : '')])];
}

async function viewJournal() {
  let filt = {};
  try { filt = JSON.parse(sessionStorage.getItem('ts-jf') || '{}'); } catch { /* ריק */ }
  setHead('יומן', 'כל עסקה מול הכללים שלה', {
    actions: [h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'סינון', title: 'סינון', on: { click: () => journalFilters(filt, f => { filt = f; sessionStorage.setItem('ts-jf', JSON.stringify(f)); route(); }) } }, '🔎')],
  });
  const [{ trades }, sOpts] = await Promise.all([api('trades', null, filt), strategyOptions()]);
  const chips = journalChips(filt, sOpts, f => { sessionStorage.setItem('ts-jf', JSON.stringify(f)); route(); });

  // סיכום בראש: פתוחות, ונטו לפי מטבע של הסגורות שבסינון
  const open = trades.filter(t => t.calc.status === 'open').length;
  const byCur = {};
  for (const t of trades) if (t.calc.status === 'closed') byCur[t.currency] = D.add(byCur[t.currency] || D.parse('0'), D.parse(t.calc.net));
  const summary = trades.length ? h('div', { class: 'summary' },
    h('div', { class: 'line' }, h('b', {}, String(trades.length)), h('span', { class: 'muted' }, 'עסקאות'), open ? [sep(), h('span', { class: 'chip warn' }, `${open} פתוחות`)] : null),
    Object.keys(byCur).length ? h('div', { class: 'line' }, h('span', { class: 'muted' }, 'נטו של הסגורות:'),
      Object.entries(byCur).map(([c, v]) => h('b', { class: cls(D.str(v)) }, num(signed(D.str(v)) + money(D.str(v), c))))) : null) : null;

  const nodes = [];
  let day = null, ul = null;
  for (const t of trades) {
    const d = t.opened_at.slice(0, 10);
    if (d !== day) { day = d; nodes.push(h('h2', { class: 'day-sep' }, fmtDay(d))); ul = h('ul', { class: 'list' }); nodes.push(ul); }
    ul.append(tradeRow(t));
  }
  render(
    h('div', { class: 'toolbar' }, h('button', { class: 'btn primary', type: 'button', on: { click: () => editTrade() } }, h('span', { 'aria-hidden': 'true' }, '+'), ' עסקה'),
      h('button', { class: 'btn ghost', type: 'button', on: { click: () => positionCalc() } }, '🧮 מחשבון')),
    chips, summary,
    ...(nodes.length ? nodes : [h('p', { class: 'empty' }, Object.keys(filt).length ? 'אין עסקאות שמתאימות לסינון.' : 'היומן ריק. "+ עסקה" כדי לרשום את הראשונה.')]));
}

function tradeRow(t) {
  const c = t.calc;
  const strat = t.strategy_name ? `${t.strategy_name} · v${t.version_no}` : 'בלי אסטרטגיה';
  const plan = t.followed_plan ? { yes: '✓ שמר על התוכנית', no: '✗ לא שמר', partial: '◐ חלקית' }[t.followed_plan] : null;
  const open = () => editTrade(t.id);
  return h('li', { class: 'card', tabindex: '0', role: 'button', on: { click: open, keydown: e => { if (e.key === 'Enter') open(); } } },
    h('span', { class: 'dir-ic ' + t.direction, 'aria-label': t.direction === 'long' ? 'לונג' : 'שורט' }, t.direction === 'long' ? '▲' : '▼'),
    h('div', { class: 'body' },
      h('div', { class: 'l1' }, h('span', { class: 'num' }, t.symbol), h('span', { class: 't' }, fmtTime(t.opened_at))),
      h('div', { class: 'l2' }, strat, t.method_name ? [sep(), '🧭 ' + t.method_name] : null, plan ? [sep(), plan] : null)),
    h('div', { class: 'res' },
      c.status === 'open' && D.sign(D.parse(c.exited_qty)) === 0
        ? h('span', { class: 'chip warn' }, 'פתוחה')
        : h('b', { class: cls(c.net) }, num(signed(c.net) + money(c.net, t.currency))),
      c.status === 'closed' ? h('small', {}, num(rText(c.r)))
        : D.sign(D.parse(c.exited_qty)) !== 0 ? h('small', {}, 'פתוחה · מומש חלקית') : null));
}

function journalChips(f, sOpts, apply) {
  const names = {
    strategy_id: v => sOpts.find(o => o[0] === v)?.[1] || 'אסטרטגיה',
    market: marketName, symbol: v => v, from: v => 'מ־' + fmtDate(v), to: v => 'עד ' + fmtDate(v),
    result: v => RESULT_OPTS.find(o => o[0] === v)?.[1], plan: v => 'תוכנית: ' + (ME.lists.plan[v] || 'לא סומן'),
  };
  const keys = Object.keys(f).filter(k => f[k] && names[k]);
  if (!keys.length) return null;
  return h('div', { class: 'filter-chips' }, keys.map(k => h('button', { type: 'button', class: 'chip', 'aria-label': 'הסרת סינון',
    on: { click: () => { const n = { ...f }; delete n[k]; apply(n); } } }, names[k](f[k]), ' ✕')));
}

async function journalFilters(f, apply) {
  const sOpts = await strategyOptions();
  const insts = (await api('instruments')).instruments;
  const el = {
    strategy_id: select('strategy_id', sOpts, f.strategy_id),
    market: select('market', [['', 'כל השווקים'], ...Object.entries(ME.lists.markets).map(([k, m]) => [k, m.name])], f.market),
    symbol: select('symbol', [['', 'כל הנכסים'], ...[...new Set(insts.map(i => i.symbol))].map(s => [s, s])], f.symbol),
    result: select('result', RESULT_OPTS, f.result),
    plan: select('plan', [['', 'הכול'], ...Object.entries(ME.lists.plan), ['none', 'לא סומן']], f.plan),
    from: h('input', { type: 'date', name: 'from', value: f.from || '' }),
    to: h('input', { type: 'date', name: 'to', value: f.to || '' }),
  };
  const sh = sheet('סינון היומן', {
    saveLabel: 'הצגה',
    onSave: () => apply(Object.fromEntries(Object.entries(el).map(([k, e]) => [k, e.value]).filter(([, v]) => v))),
  });
  sh.body.append(h('div', { class: 'fields two' },
    field('מתאריך', el.from), field('עד תאריך', el.to), field('אסטרטגיה', el.strategy_id, true),
    field('שוק', el.market), field('נכס', el.symbol), field('תוצאה', el.result), field('שמר על הכללים', el.plan)),
  h('button', { type: 'button', class: 'btn ghost', on: { click: () => { apply({}); sh.close(); } } }, 'ניקוי הסינון'));
}

// ── עסקה — טופס (אפיון 2.5) ─────────────────────────────
async function editTrade(id = null, pre = {}) {
  const L = ME.lists;
  const [t, insts, strategies] = await Promise.all([
    id ? api('trade', null, { id }).then(r => r.trade) : null,
    api('instruments').then(r => r.instruments),
    STRATS || loadStrategies(),
  ]);
  // גרסאות של האסטרטגיה הנבחרת — נטען לפי דרישה
  const versionCache = {};
  const versionsOf = async sid => (versionCache[sid] ??= (await api('strategy', null, { id: sid })).strategy.versions);

  // pre.trade — מילוי מכרטיס ניתוח ("פתח עסקה מזה", spec-stage-a 11): נכס, שוק, מטבע, מכפיל, כיוון ורמות
  const v = t || pre.trade || {};
  const market0 = v.market || 'stocks';
  const fld = {
    symbol: h('input', { name: 'symbol', value: v.symbol || '', maxlength: 40, dir: 'ltr', list: 'ts-insts', autocomplete: 'off', placeholder: 'AAPL' }),
    currency: h('input', { name: 'currency', value: v.currency || ME.user.base_currency, maxlength: 6, dir: 'ltr', autocomplete: 'off' }),
    multiplier: numInput('multiplier', v.multiplier ?? L.markets[market0].multiplier ?? ''),
    opened_at: h('input', { type: 'datetime-local', name: 'opened_at', value: v.opened_at || nowLocal() }),
    entry_price: numInput('entry_price', v.entry_price),
    quantity: numInput('quantity', v.quantity),
    entry_fees: numInput('entry_fees', v.entry_fees ?? '0'),
    planned_stop: numInput('planned_stop', v.planned_stop, 'רשות — בלעדיו אין R'),
    planned_target: numInput('planned_target', v.planned_target, 'רשות'),
    went_well: h('textarea', { name: 'went_well', rows: 2, maxlength: 4000 }, v.went_well || ''),
    to_improve: h('textarea', { name: 'to_improve', rows: 2, maxlength: 4000 }, v.to_improve || ''),
    opt_strike: numInput('opt_strike', v.option?.strike),
    opt_expiry: h('input', { type: 'date', name: 'opt_expiry', value: v.option?.expiry || '' }),
    opt_underlying: h('input', { name: 'opt_underlying', value: v.option?.underlying || '', maxlength: 40, dir: 'ltr' }),
  };
  const datalist = h('datalist', { id: 'ts-insts' }, insts.map(i => h('option', { value: i.symbol }, marketName(i.market))));
  const unitHint = h('small', { class: 'muted' });
  const optBox = h('div', { class: 'fields two' },
    h('div', { class: 'f wide' }, h('span', {}, 'סוג'), seg('opt_type', [['call', 'Call'], ['put', 'Put']], v.option?.type || 'call', { label: 'Call או Put' })),
    field('מחיר מימוש', fld.opt_strike), field('פקיעה', fld.opt_expiry), field('נכס בסיס', fld.opt_underlying, true));

  const onMarket = m => {
    optBox.hidden = m !== 'options';
    const def = L.markets[m].multiplier;
    if (!fld.multiplier.dataset.touched) fld.multiplier.value = def ?? '';
    fld.multiplier.placeholder = def ? '' : 'לפי החוזה, למשל 50';
    unitHint.textContent = 'כמות ב' + L.markets[m].unit + (m === 'forex' ? ' · לוט = 100,000' : '');
    preview();
  };
  fld.multiplier.addEventListener('input', () => { fld.multiplier.dataset.touched = '1'; });
  const marketSeg = seg('market', Object.keys(L.markets).map(k => [k, MARKET_SHORT[k] || L.markets[k].name]), market0, { grid: 5, label: 'שוק', onChange: onMarket });
  const dirSeg = seg('direction', [['long', '▲ לונג'], ['short', '▼ שורט']], v.direction || 'long', { label: 'כיוון', onChange: () => preview() });

  fld.symbol.addEventListener('change', () => {   // נכס מ"נכסים שלי" ממלא שוק, מטבע ומכפיל
    const i = insts.find(x => x.symbol === fld.symbol.value.trim().toUpperCase());
    if (!i) return;
    const r = marketSeg.querySelector(`input[value="${i.market}"]`);
    if (r) r.checked = true;
    fld.currency.value = i.currency;
    fld.multiplier.value = i.multiplier;
    fld.multiplier.dataset.touched = '1';
    if (i.option) {
      optBox.querySelector(`input[value="${i.option.type}"]`).checked = true;
      fld.opt_strike.value = i.option.strike; fld.opt_expiry.value = i.option.expiry; fld.opt_underlying.value = i.option.underlying;
    }
    onMarket(i.market);
  });

  // אסטרטגיה + גרסה → צ'קליסט
  const sid0 = v.strategy_id ?? pre.strategy_id ?? '';
  const stratSel = select('strategy', [['', 'בלי אסטרטגיה'], ...strategies.filter(s => s.status !== 'archived' || s.id === sid0).map(s => [String(s.id), s.name])], sid0, { 'aria-label': 'אסטרטגיה' });
  const verSel = h('select', { name: 'version', 'aria-label': 'גרסה' });
  const checkBox = h('div', { class: 'check-list' });
  const verField = field('גרסה', verSel);
  let versions = [];
  const drawChecklist = () => {
    const ver = versions.find(x => String(x.id) === verSel.value);
    const met = t && String(t.strategy_version_id) === verSel.value ? t.checklist.map(c => c.met) : [];
    checkBox.replaceChildren(...(ver?.entry_rules.length ? ver.entry_rules.map((r, k) => {
      const c = h('input', { type: 'checkbox', 'data-k': k });
      c.checked = !!met[k];
      return h('label', {}, c, h('span', {}, r.text), h('small', {}, r.required ? 'חובה' : 'רצוי'));
    }) : [h('p', { class: 'hint' }, ver ? 'לגרסה הזו אין כללי כניסה.' : 'בוחרים אסטרטגיה — וכללי הכניסה שלה מופיעים כאן לסימון.')]));
  };
  const loadVersions = async () => {
    const sid = stratSel.value;
    versions = sid ? await versionsOf(+sid) : [];
    const cur = strategies.find(s => String(s.id) === sid)?.current_version_id;
    const want = String(t && String(t.strategy_id) === sid ? t.strategy_version_id : pre.strategy_version_id ?? cur ?? '');
    verSel.replaceChildren(...versions.map(x => h('option', { value: x.id, selected: String(x.id) === want }, `גרסה ${x.version_no}` + (x.id === cur ? ' (נוכחית)' : ''))));
    verField.hidden = !sid;
    drawChecklist();
  };
  stratSel.addEventListener('change', loadVersions);
  verSel.addEventListener('change', drawChecklist);

  // יציאות
  const exitsBox = h('div', { class: 'rule-rows' });
  const remain = h('p', { class: 'hint', 'aria-live': 'polite' });
  const addExit = (x = {}) => {
    const row = h('div', { class: 'exit-row' },
      h('label', { class: 'f when' }, h('span', {}, 'זמן'), h('input', { type: 'datetime-local', 'data-k': 'exited_at', value: x.exited_at || nowLocal() })),
      h('label', { class: 'f' }, h('span', {}, 'מחיר'), h('input', { 'data-k': 'price', value: x.price || '', inputmode: 'decimal', dir: 'ltr' })),
      h('label', { class: 'f' }, h('span', {}, 'כמות'), h('input', { 'data-k': 'quantity', value: x.quantity || '', inputmode: 'decimal', dir: 'ltr' })),
      h('label', { class: 'f' }, h('span', {}, 'עמלות'), h('input', { 'data-k': 'fees', value: x.fees ?? '0', inputmode: 'decimal', dir: 'ltr' })),
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'מחיקת יציאה', on: { click: () => { row.remove(); preview(); } } }, '✕'));
    row.addEventListener('input', preview);
    exitsBox.append(row);
    return row;
  };
  (v.exits || []).forEach(addExit);
  const exitBtn = h('button', { type: 'button', class: 'btn ghost sm', on: { click: () => {
    // יציאה חדשה — עם הכמות שנשארה
    const left = remainingQty();
    const row = addExit({ quantity: left && D.sign(left) > 0 ? D.str(left) : '' });
    row.querySelector('[data-k=price]').focus();
    preview();
  } } }, '+ יציאה');
  const exitsVal = () => [...exitsBox.children].map(r => Object.fromEntries([...r.querySelectorAll('[data-k]')].map(i => [i.dataset.k, i.value.trim()])));
  const remainingQty = () => {
    const q = D.parse(fld.quantity.value);
    if (!q) return null;
    return exitsVal().reduce((a, x) => (D.parse(x.quantity) ? D.sub(a, D.parse(x.quantity)) : a), q);
  };

  // תצוגה מקדימה בדפדפן; התוצאה הקובעת — מהשרת אחרי שמירה
  const live = h('dl', { class: 'calc-out', 'aria-live': 'polite' });
  function preview() {
    const p = k => D.parse(fld[k].value);
    const [entry, qty, stop, target, mult] = ['entry_price', 'quantity', 'planned_stop', 'planned_target', 'multiplier'].map(p);
    live.replaceChildren();
    const cell = (dt, dd) => live.append(h('div', {}, h('dt', {}, dt), h('dd', {}, dd)));
    if (entry && qty && mult && stop) {
      const dist = D.abs(D.sub(entry, stop));
      cell('סיכון (1R)', num(money(D.str(D.mul(D.mul(dist, qty), mult)), fld.currency.value.toUpperCase())));
      if (target && D.sign(dist)) cell('יחס סיכוי/סיכון', num('1 : ' + D.fmt(D.div(D.abs(D.sub(target, entry)), dist, 2), 2)));
    } else cell('סיכון (1R)', stop ? '—' : 'בלי סטופ — אין R');
    const left = remainingQty();
    remain.textContent = left == null ? '' : D.sign(left) < 0 ? '⚠️ היציאות גדולות מכמות הכניסה' :
      D.sign(left) === 0 ? 'כל הכמות יצאה — העסקה תיסגר' : `נשארו פתוחות: ${D.fmt(left, 8)}`;
  }
  ['entry_price', 'quantity', 'planned_stop', 'planned_target', 'multiplier', 'currency'].forEach(k => fld[k].addEventListener('input', preview));

  const planSeg = seg('followed_plan', [['yes', 'כן'], ['partial', 'חלקית'], ['no', 'לא']], v.followed_plan, { label: 'שמרתי על התוכנית' });
  const moodSeg = seg('mood', Object.entries(L.moods), v.mood, { grid: 4, label: 'מצב רוח' });
  const ratingSeg = seg('rating', [1, 2, 3, 4, 5].map(n => [n, n + '★']), v.rating, { grid: 5, label: 'דירוג ביצוע' });
  const group = (title, sub, ...c) => h('section', { class: 'group' }, h('h3', {}, title, sub ? h('small', {}, sub) : null), ...c);

  const result = t ? tradeResult(t) : null;
  const del = t ? h('button', { type: 'button', class: 'btn ghost danger', on: { click: () => confirmSheet('מחיקת עסקה', `העסקה ב-${t.symbol} מ-${fmtDate(t.opened_at)} תימחק מהיומן.`, 'מחיקה', async () => { await api('trade_delete', { id: t.id }); sh.close(); toast('נמחקה'); route(); }) } }, '🗑️ מחיקת העסקה') : null;

  const sh = sheet(t ? `${t.symbol} · ${fmtDate(t.opened_at)}` : 'עסקה חדשה', {
    footer: del,
    onSave: async () => {
      const market = segVal(sh.body, 'market');
      const body = {
        id: t?.id, symbol: fld.symbol.value, market, currency: fld.currency.value, multiplier: fld.multiplier.value.trim(),
        direction: segVal(sh.body, 'direction'),
        strategy_version_id: stratSel.value ? +verSel.value || null : null,
        checklist: [...checkBox.querySelectorAll('input[type=checkbox]')].map(c => c.checked),
        opened_at: fld.opened_at.value, entry_price: fld.entry_price.value.trim(), quantity: fld.quantity.value.trim(),
        entry_fees: fld.entry_fees.value.trim() || '0',
        planned_stop: fld.planned_stop.value.trim() || null, planned_target: fld.planned_target.value.trim() || null,
        exits: exitsVal(),
        followed_plan: segVal(sh.body, 'followed_plan') || null, mood: segVal(sh.body, 'mood') || null,
        rating: segVal(sh.body, 'rating') ? +segVal(sh.body, 'rating') : null,
        went_well: fld.went_well.value, to_improve: fld.to_improve.value,
      };
      if (!t && pre.method_id) { body.method_id = pre.method_id; body.analysis_snapshot = pre.snapshot || null; }
      if (market === 'options') body.option = { type: segVal(sh.body, 'opt_type'), strike: fld.opt_strike.value.trim(), expiry: fld.opt_expiry.value, underlying: fld.opt_underlying.value };
      const r = await api('trade_save', body);
      const c = r.trade.calc;
      toast(c.status === 'closed' ? `נשמר · נטו ${iso(signed(c.net) + money(c.net, r.trade.currency))} · ${iso(rText(c.r))}` : 'נשמר · העסקה פתוחה');
      STRATS = null;
      if (location.hash.startsWith('#/journal')) route(); else location.hash = '#/journal';
    },
  });
  const fromAnalysis = !t && pre.method_id ? h('p', { class: 'chip', style: 'white-space:normal' },
    `🧭 מכרטיס ניתוח: ${pre.method_name || pre.method_id} · ${pre.snapshot?.asset || ''}`,
    pre.existing === false ? ' · הנכס חדש ב"נכסים שלי" — בדקו שוק, מטבע ומכפיל לפני שמירה' : '') : null;
  sh.body.append(datalist,
    result, fromAnalysis,
    group('בסיס', '',
      h('div', { class: 'fields two' }, field('נכס', fld.symbol), field('מטבע', fld.currency)),
      h('div', { class: 'f' }, h('span', { class: 'muted', style: 'font-size:.8rem' }, 'שוק'), marketSeg),
      optBox,
      h('div', { class: 'f' }, h('span', { class: 'muted', style: 'font-size:.8rem' }, 'כיוון'), dirSeg),
      h('div', { class: 'fields two' }, field('אסטרטגיה', stratSel), verField)),
    group('כניסה', '',
      h('div', { class: 'fields two' }, field('תאריך ושעה', fld.opened_at, true), field('מחיר', fld.entry_price), field('כמות', fld.quantity),
        field('עמלות', fld.entry_fees), field('מכפיל', fld.multiplier)), unitHint),
    group('תכנון', 'מהם מחושבים R ויחס סיכוי/סיכון',
      h('div', { class: 'fields two' }, field('סטופ מתוכנן', fld.planned_stop), field('יעד מתוכנן', fld.planned_target)), live,
      h('button', { type: 'button', class: 'btn ghost sm', on: { click: () => positionCalc({
        entry: fld.entry_price.value, stop: fld.planned_stop.value, target: fld.planned_target.value, mult: fld.multiplier.value,
        market: segVal(sh.body, 'market'),
      }, q => { fld.quantity.value = q; preview(); }) } }, '🧮 מחשבון גודל פוזיציה')),
    group('יציאה', 'אחת או יותר — יציאה חלקית', exitsBox, exitBtn, remain),
    group('משמעת', '', checkBox, h('div', { class: 'f' }, h('span', { class: 'muted', style: 'font-size:.8rem' }, 'שמרתי על התוכנית'), planSeg)),
    group('רפלקציה', '',
      h('div', { class: 'f' }, h('span', { class: 'muted', style: 'font-size:.8rem' }, 'מצב רוח'), moodSeg),
      field('מה עבד', fld.went_well), field('מה לשפר', fld.to_improve),
      h('div', { class: 'f' }, h('span', { class: 'muted', style: 'font-size:.8rem' }, 'דירוג ביצוע'), ratingSeg)));
  onMarket(market0);
  if (t || pre.trade?.multiplier) fld.multiplier.dataset.touched = '1';
  if (t) fld.multiplier.value = t.multiplier;
  await loadVersions();
}

/** הערכים המחושבים בשרת — בראש הטופס של עסקה קיימת */
function tradeResult(t) {
  const c = t.calc, cur = t.currency;
  const cell = (dt, dd) => h('div', {}, h('dt', {}, dt), h('dd', {}, dd));
  return h('dl', { class: 'calc-out' },
    cell(c.status === 'closed' ? 'נטו' : 'נטו שמומש', h('span', { class: cls(c.net) }, num(signed(c.net) + money(c.net, cur)))),
    cell('R', num(rText(c.r))),
    cell('ברוטו', num(money(c.gross, cur))), cell('עמלות', num(money(c.fees, cur))),
    cell('תשואה', num(c.return_pct != null ? signed(c.return_pct) + D.fmt(c.return_pct, 2) + '%' : '—')),
    c.pips != null ? cell('פיפים', num(signed(c.pips) + D.fmt(c.pips, 1))) : null,
    cell(c.status === 'closed' ? 'משך' : 'מצב', c.status === 'closed' ? fmtDuration(c.duration_sec) : `פתוחה · נשארו ${D.fmt(c.remaining_qty, 8)}`));
}

// ── סטטיסטיקה (אפיון 2.6) ───────────────────────────────
const DISCLAIMER = 'הכלי לתיעוד וניתוח אישי בלבד. ביצועי עבר אינם מבטיחים תוצאות בעתיד. אין כאן ייעוץ השקעות.';
const WEEKDAYS = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];

async function viewStats() {
  let f = {};
  try { f = JSON.parse(sessionStorage.getItem('ts-sf') || '{}'); } catch { /* ריק */ }
  setHead('סטטיסטיקה', 'עסקאות סגורות, לפי תאריך הסגירה');
  const sOpts = await strategyOptions();
  const apply = n => { sessionStorage.setItem('ts-sf', JSON.stringify(n)); route(); };
  const strat = select('strategy_id', sOpts, f.strategy_id, { 'aria-label': 'אסטרטגיה' });
  const ver = h('select', { 'aria-label': 'גרסה', hidden: true });
  if (f.strategy_id && f.strategy_id !== 'none') {
    const vs = (await api('strategy', null, { id: f.strategy_id }).catch(() => ({ strategy: { versions: [] } }))).strategy.versions;
    ver.append(h('option', { value: '' }, 'כל הגרסאות'), ...vs.map(v => h('option', { value: v.id, selected: String(v.id) === f.version_id }, 'גרסה ' + v.version_no)));
    ver.hidden = false;
  }
  const mk = select('market', [['', 'כל השווקים'], ...Object.entries(ME.lists.markets).map(([k, m]) => [k, m.name])], f.market, { 'aria-label': 'שוק' });
  const from = h('input', { type: 'date', value: f.from || '', 'aria-label': 'מתאריך' });
  const to = h('input', { type: 'date', value: f.to || '', 'aria-label': 'עד תאריך' });
  strat.addEventListener('change', () => apply({ ...f, strategy_id: strat.value, version_id: '' }));
  ver.addEventListener('change', () => apply({ ...f, version_id: ver.value }));
  mk.addEventListener('change', () => apply({ ...f, market: mk.value }));
  from.addEventListener('change', () => apply({ ...f, from: from.value }));
  to.addEventListener('change', () => apply({ ...f, to: to.value }));
  const q = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
  const data = await api('stats', null, q);
  const meth = select('method_id', [['', 'כל השיטות'], ['none', 'בלי שיטה'], ...Object.entries(data.method_names || {}).map(([k, n]) => [k, n])], f.method_id, { 'aria-label': 'שיטה' });
  meth.addEventListener('change', () => apply({ ...f, method_id: meth.value }));
  METHOD_NAMES = data.method_names || {};

  const filters = h('div', { class: 'fields two' }, field('אסטרטגיה', strat), ver.hidden ? field('שוק', mk) : field('גרסה', ver),
    ver.hidden ? null : field('שוק', mk), field('שיטה', meth), field('מתאריך', from), field('עד תאריך', to));
  const body = data.by_currency.length ? data.by_currency.map(statsBlock) : [h('p', { class: 'empty' }, 'אין עסקאות סגורות בסינון הזה.')];
  render(h('p', { class: 'hint' }, 'למעלה — הכול במטבע הבסיס, לפי שער ECB של יום הסגירה. מתחת — כל מטבע בנפרד, בלי המרה.'), filters,
    baseBlock(data.in_base, data.by_currency.length),
    data.no_stop ? h('p', { class: 'hint' }, `${data.no_stop} עסקאות בלי סטופ מתוכנן — נספרות ברווח ובאחוז ההצלחה, אבל לא בתוחלת ה-R.`) : null,
    ...body, h('p', { class: 'disclaimer' }, '⚖️ ' + DISCLAIMER));
}

let METHOD_NAMES = {};

/** הסטטיסטיקה במטבע הבסיס (✅12): שער ECB של יום הסגירה; יום בלי שער — האחרון שלפניו, מסומן */
function baseBlock(b, currencies) {
  if (!b) return null;
  const notes = [];
  if (b.approx) notes.push(`${b.approx} עסקאות הומרו בשער של יום עסקים קודם (אין שער ליום הסגירה) — מסומן ≈`);
  if (b.pegged) notes.push(`${b.pegged} עסקאות במטבע יציב (USDT וכד׳) חושבו כשווה-ערך לדולר`);
  const skipped = Object.entries(b.skipped || {});
  if (skipped.length) notes.push('לא הומרו (אין שער ECB): ' + skipped.map(([c, n]) => `${c} (${n})`).join(', '));
  (b.errors || []).forEach(e => notes.push(e));
  if (!b.count) return h('section', { class: 'stack' }, h('p', { class: 'hint' }, 'אין עסקאות שאפשר להמיר למטבע הבסיס. ', notes.join(' · ')));
  if (currencies <= 1 && !b.approx && !b.pegged && !skipped.length && b.currency === b.rates?.[0]?.currency) return null;   // מטבע אחד = הבסיס — אותו בלוק כמו למטה
  const s = { ...b, currency: b.currency };
  const block = statsBlock(s, true);
  block.prepend(h('p', { class: 'chip ok', style: 'white-space:normal' }, `💱 במטבע הבסיס ${b.currency} · ${b.converted} עסקאות · שער ECB של יום הסגירה` + (b.approx ? ' · ≈ חלק ביום קודם' : '')));
  if (notes.length) block.append(h('ul', { class: 'hint' }, notes.map(n => h('li', {}, n))));
  return block;
}

function statsBlock(s, inBase = false) {
  const cur = s.currency;
  const cell = (dt, dd, c = '') => h('div', {}, h('dt', {}, dt), h('dd', { class: c }, dd));
  const R = v => (v == null ? '—' : rText(v));
  return h('section', { class: 'stack' },
    h('h2', { class: 'cur-head' }, num(cur), h('span', { class: 'muted', style: 'font-size:.85rem;font-weight:400' }, `${s.count} עסקאות`)),
    h('div', { class: 'summary' },
      h('div', { class: 'line' }, h('span', { class: 'muted' }, 'נטו'), h('b', { class: cls(s.net) }, num(signed(s.net) + money(s.net, cur))),
        h('span', { class: 'pct' }, num(pct(s.win_rate))), h('span', { class: 'muted' }, 'הצלחה')),
      h('span', { class: 'track', role: 'progressbar', 'aria-label': 'אחוז הצלחה', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': s.win_rate ?? 0 },
        h('span', { style: `inline-size:${s.win_rate ?? 0}%` }))),
    h('dl', { class: 'cells' },
      cell('תוחלת ב-R', num(R(s.expectancy_r)), cls(s.expectancy_r)),
      cell('Profit factor', num(s.profit_factor != null ? D.fmt(s.profit_factor, 2) : '—')),
      cell('רווח ממוצע', num(money(s.avg_win, cur)), 'pos'),
      cell('הפסד ממוצע', num(money(s.avg_loss, cur)), 'neg'),
      cell('ירידה מרבית', num(money(s.max_drawdown, cur))),
      cell('רצף הפסדים מרבי', num(String(s.max_loss_streak))),
      cell('רווח / הפסד / איזון', num(`${s.wins} / ${s.losses} / ${s.breakeven}`)),
      cell('עסקאות עם R', num(String(s.r_count)))),
    equityChart(s.equity, cur),
    monthChart(s.breakdown.month, cur),
    breakdownTable('לפי חודש', s.breakdown.month, k => k.slice(5) + '/' + k.slice(0, 4), cur),
    breakdownTable('לפי יום בשבוע', s.breakdown.weekday, k => WEEKDAYS[+k], cur),
    breakdownTable('שמר על הכללים?', s.breakdown.plan, k => ({ yes: 'שמר', partial: 'חלקית', no: 'לא שמר', none: 'לא סומן' }[k]), cur),
    s.breakdown.method?.some(r => r.key !== 'none') ? breakdownTable('לפי שיטה', s.breakdown.method, k => (k === 'none' ? 'בלי שיטה' : METHOD_NAMES[k] || k), cur) : null);
}

/** עקומת הון: קו אחד, צבע המותג, קו אפס, ועכבר/מגע מראה את הערך בנקודה הקרובה */
function equityChart(points, cur) {
  const W = 600, H = 200, P = { t: 12, r: 8, b: 22, l: 52 };
  const vals = [0, ...points.map(p => Number(p.v))];   // Number — רק לציור; הערכים המדויקים בתוויות
  const min = Math.min(...vals), max = Math.max(...vals), span = max - min || 1;
  const x = i => P.l + (i / Math.max(1, points.length)) * (W - P.l - P.r);
  const y = v => P.t + (1 - (v - min) / span) * (H - P.t - P.b);
  const NS = 'http://www.w3.org/2000/svg';
  const s = (tag, attrs = {}) => { const e = document.createElementNS(NS, tag); for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v); return e; };
  const svg = s('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': `עקומת הון, ${points.length} עסקאות, סוף ${points.at(-1)?.v ?? 0} ${cur}` });
  const grid = s('g', { class: 'grid' }), axis = s('g', { class: 'axis' });
  for (const v of [max, (max + min) / 2, min]) {
    grid.append(s('line', { x1: P.l, x2: W - P.r, y1: y(v), y2: y(v) }));
    const t = s('text', { x: P.l - 6, y: y(v) + 3, 'text-anchor': 'end' });
    t.textContent = D.fmt(String(Math.round(v)), 0);
    axis.append(t);
  }
  const d = vals.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
  svg.append(grid, axis, s('path', { class: 'area', d: `${d} L${x(points.length)},${y(Math.max(min, 0))} L${x(0)},${y(Math.max(min, 0))} Z` }),
    s('line', { class: 'zero', x1: P.l, x2: W - P.r, y1: y(0), y2: y(0) }), s('path', { class: 'line', d }));
  [['#1', 0], [`#${points.length}`, points.length]].forEach(([l, i]) => {
    const t = s('text', { x: x(i), y: H - 6, 'text-anchor': i ? 'end' : 'start', class: 'axis' });
    t.textContent = i ? l : 'התחלה';
    axis.append(t);
  });
  const hair = s('line', { class: 'hair', y1: P.t, y2: H - P.b, visibility: 'hidden' });
  const dot = s('circle', { class: 'dot', r: 5, visibility: 'hidden' });
  svg.append(hair, dot);
  const box = h('div', { class: 'chart' }, h('h3', {}, 'עקומת הון'), svg);
  const tip = h('div', { class: 'tip', hidden: true });
  box.append(tip);
  const move = e => {
    if (!points.length) return;
    const r = svg.getBoundingClientRect();
    const px = ((e.clientX - r.left) / r.width) * W;
    const i = Math.max(1, Math.min(points.length, Math.round(((px - P.l) / (W - P.l - P.r)) * points.length)));
    const p = points[i - 1];
    hair.setAttribute('x1', x(i)); hair.setAttribute('x2', x(i)); hair.setAttribute('visibility', 'visible');
    dot.setAttribute('cx', x(i)); dot.setAttribute('cy', y(Number(p.v))); dot.setAttribute('visibility', 'visible');
    tip.replaceChildren(h('b', { class: 'num' }, money(p.v, cur)), `עסקה ${i} · ${fmtDate(p.t)}`);
    tip.style.insetInlineStart = 'auto';
    tip.style.left = ((x(i) / W) * r.width + 8) + 'px';
    tip.style.top = ((y(Number(p.v)) / H) * r.height + 2) + 'px';
    tip.hidden = false;
  };
  svg.addEventListener('pointermove', move);
  svg.addEventListener('pointerdown', move);
  svg.addEventListener('pointerleave', () => { tip.hidden = true; hair.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); });
  return box;
}

/** נטו לפי חודש: עמודות מהאפס — רווח בצבע המותג, הפסד באדום; הערך המדויק בריחוף ובטבלה */
function monthChart(rows, cur) {
  if (rows.length < 2) return null;
  const W = 600, H = 170, P = { t: 10, r: 8, b: 22, l: 52 };
  const vals = rows.map(r => Number(r.net));
  const max = Math.max(0, ...vals), min = Math.min(0, ...vals), span = max - min || 1;
  const y = v => P.t + (1 - (v - min) / span) * (H - P.t - P.b);
  const slot = (W - P.l - P.r) / rows.length, bw = Math.min(40, slot - 2);
  const NS = 'http://www.w3.org/2000/svg';
  const s = (tag, attrs = {}) => { const e = document.createElementNS(NS, tag); for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v); return e; };
  const svg = s('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': 'נטו לפי חודש' });
  const box = h('div', { class: 'chart' }, h('h3', {}, 'נטו לפי חודש'), svg);
  const tip = h('div', { class: 'tip', hidden: true });
  box.append(tip);
  const axis = s('g', { class: 'axis' });
  svg.append(s('line', { class: 'zero', x1: P.l, x2: W - P.r, y1: y(0), y2: y(0) }), axis);
  rows.forEach((r, i) => {
    const v = vals[i], cx = P.l + slot * i + slot / 2;
    const top = Math.min(y(v), y(0)), hgt = Math.max(1, Math.abs(y(v) - y(0)));
    const bar = s('rect', { class: 'bar ' + (v >= 0 ? 'pos' : 'neg'), x: cx - bw / 2, y: top, width: bw, height: hgt, rx: 3, tabindex: 0,
      'aria-label': `${r.key}: ${r.net} ${cur}` });
    const show = () => {
      const b = svg.getBoundingClientRect();
      tip.replaceChildren(h('b', { class: 'num' }, signed(r.net) + money(r.net, cur)), `${r.key} · ${r.count} עסקאות`);
      tip.style.left = ((cx / W) * b.width) + 'px';
      tip.style.top = ((top / H) * b.height) + 'px';
      tip.hidden = false;
    };
    ['pointerenter', 'focus', 'pointerdown'].forEach(ev => bar.addEventListener(ev, show));
    ['pointerleave', 'blur'].forEach(ev => bar.addEventListener(ev, () => { tip.hidden = true; }));
    svg.append(bar);
    if (rows.length <= 12 || i % Math.ceil(rows.length / 12) === 0) {
      const t = s('text', { x: cx, y: H - 6, 'text-anchor': 'middle' });
      t.textContent = r.key.slice(5) + '/' + r.key.slice(2, 4);
      axis.append(t);
    }
  });
  return box;
}

function breakdownTable(title, rows, label, cur) {
  if (!rows.length) return null;
  return h('section', {}, h('h3', { class: 'sec-title' }, title), h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
    h('thead', {}, h('tr', {}, h('th', {}, ''), h('th', { class: 'n' }, 'עסקאות'), h('th', { class: 'n' }, 'הצלחה'), h('th', { class: 'n' }, 'תוחלת'), h('th', { class: 'n' }, 'נטו'))),
    h('tbody', {}, rows.map(r => h('tr', {},
      h('th', { scope: 'row', style: 'font-weight:500' }, label(r.key)),
      h('td', { class: 'n' }, num(String(r.count))),
      h('td', { class: 'n' }, num(pct(r.win_rate))),
      h('td', { class: 'n ' + cls(r.expectancy_r) }, num(r.expectancy_r != null ? rText(r.expectancy_r) : '—')),
      h('td', { class: 'n ' + cls(r.net) }, num(signed(r.net) + D.fmt(r.net, 2)))))))));
}

// ── עוד: הגדרות, נכסים, ייצוא, אודות (אפיון 2.7) ────────
async function viewMore() {
  setHead('עוד', ME.user.display_name || '');
  const p = ME.profile;
  render(
    h('ul', { class: 'list' },
      h('li', {}, h('a', { class: 'use-row', href: '#/profile' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🎯'),
        h('span', { class: 'use-tx' }, h('b', {}, 'פרופיל והגדרות'),
          h('small', {}, `טווח ${{ short: 'קצר', medium: 'בינוני', long: 'ארוך' }[p.horizon]} · סיכון ${D.fmt(p.risk_pct, 4)}% · ודאות מינימלית ${p.min_certainty}% · ${ME.user.base_currency}`)),
        h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))),
    h('h2', { class: 'sec-title' }, 'ענן'),
    C.section(),
    h('h2', { class: 'sec-title' }, 'נתונים'),
    h('ul', { class: 'list' },
      h('li', {}, h('a', { class: 'use-row', href: '#/providers' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🔌'),
        h('span', { class: 'use-tx' }, h('b', {}, 'ספקי נתונים'), h('small', {}, 'Binance ו-ECB פתוחים; Twelve Data ו-Finnhub — במפתח שלכם')), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))),
      ME.is_admin ? h('li', {}, h('a', { class: 'use-row', href: '#/admin' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '⚙️'),
        h('span', { class: 'use-tx' }, h('b', {}, 'ניהול'), h('small', {}, 'ספקים, שיטות ועמלות, ספקים לחלוקה, מנהלים, מאגר')), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))) : null,
      ME.is_admin ? h('li', {}, h('a', { class: 'use-row', href: '#/method-files' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📚'),
        h('span', { class: 'use-tx' }, h('b', {}, 'קובצי שיטות'), h('small', {}, 'הספרים בענן MBE שלכם — צפייה ברשימה והעלאה')), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))) : null,
      h('li', {}, h('a', { class: 'use-row', href: '#/instruments' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🏷️'),
        h('span', { class: 'use-tx' }, h('b', {}, 'נכסים שלי'), h('small', {}, 'שוק, מטבע ומכפיל לכל נכס — נבנה לבד מהעסקאות')), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))),
      h('li', {}, h('a', { class: 'use-row', href: 'api.php?action=export&format=json', download: '' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '⬇️'),
        h('span', { class: 'use-tx' }, h('b', {}, 'ייצוא מלא (JSON)'), h('small', {}, 'כל האסטרטגיות, הגרסאות, הנכסים והעסקאות')), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))),
      h('li', {}, h('a', { class: 'use-row', href: 'api.php?action=export&format=csv', download: '' }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📄'),
        h('span', { class: 'use-tx' }, h('b', {}, 'היומן כ-CSV'), h('small', {}, 'שורה לכל עסקה, עם הרווח וה-R — נפתח באקסל')), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))),
    h('h2', { class: 'sec-title' }, 'אודות'),
    h('div', { class: 'block stack' },
      h('p', {}, 'מחברת עבודה של סוחר: בונים ומתעדים אסטרטגיות, רושמים כל עסקה ביומן מול הכללים של הגרסה שהייתה בתוקף, ובודקים בסטטיסטיקה אם זה עובד.'),
      h('p', {}, '🔒 אין כאן מסחר ואין כסף אמיתי: האפליקציה לא מתחברת לשום ברוקר, בורסה או ארנק, ולא שולחת פקודות. כל המחירים מוזנים ידנית.'),
      h('p', {}, '👤 הנתונים שלך — רק שלך. אף משתמש אחר לא רואה אותם, ואפשר לייצא הכול בכל רגע.'),
      h('p', { class: 'disclaimer' }, '⚖️ ' + DISCLAIMER)));
}

async function viewInstruments() {
  setHead('נכסים שלי', 'ברירות מחדל לעסקאות הבאות', { back: true });
  const list = (await api('instruments')).instruments;
  const row = i => h('li', {}, h('button', { class: 'use-row', type: 'button', on: { click: () => editInstrument(i) } },
    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, { stocks: '🏢', crypto: '🪙', forex: '💱', futures: '📜', options: '🎯' }[i.market]),
    h('span', { class: 'use-tx' }, h('b', { class: 'num', style: 'text-align:start' }, i.symbol),
      h('small', {}, `${marketName(i.market)} · ${i.currency} · מכפיל ${D.fmt(i.multiplier, 8)} · ${i.trades} עסקאות`)),
    h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
  render(h('p', { class: 'hint' }, 'הרשימה נבנית לבד מהעסקאות. עריכה כאן משנה רק עסקאות חדשות — עסקה שנרשמה שומרת את המכפיל והמטבע שלה.'),
    list.length ? h('ul', { class: 'list' }, list.map(row)) : h('p', { class: 'empty' }, 'עוד אין נכסים — הם נוספים כשרושמים עסקה.'));
}

function editInstrument(i) {
  const cur = h('input', { value: i.currency, maxlength: 6, dir: 'ltr' });
  const mult = numInput('multiplier', i.multiplier);
  const unit = h('input', { value: i.unit_label, maxlength: 20 });
  const o = i.option || {};
  const strike = numInput('strike', o.strike), expiry = h('input', { type: 'date', value: o.expiry || '' }), under = h('input', { value: o.underlying || '', dir: 'ltr', maxlength: 40 });
  const del = i.trades ? null : h('button', { type: 'button', class: 'btn ghost danger', on: { click: async () => { await api('instrument_delete', { id: i.id }); sh.close(); toast('נמחק'); route(); } } }, '🗑️ מחיקה');
  const sh = sheet(i.symbol, {
    footer: del,
    onSave: async () => {
      const body = { id: i.id, currency: cur.value, multiplier: mult.value.trim(), unit_label: unit.value };
      if (i.market === 'options') body.option = { type: segVal(sh.body, 'otype'), strike: strike.value.trim(), expiry: expiry.value, underlying: under.value };
      await api('instrument_save', body);
      toast('נשמר');
      route();
    },
  });
  sh.body.append(h('p', { class: 'hint' }, marketName(i.market)),
    h('div', { class: 'fields two' }, field('מטבע', cur), field('מכפיל', mult), field('יחידת כמות', unit, true)),
    i.market === 'options' ? h('div', { class: 'fields two' }, h('div', { class: 'f wide' }, h('span', {}, 'סוג'), seg('otype', [['call', 'Call'], ['put', 'Put']], o.type || 'call')),
      field('מחיר מימוש', strike), field('פקיעה', expiry), field('נכס בסיס', under, true)) : null);
}

// ── הפעלה ───────────────────────────────────────────────
(async () => {
  try {
    ME = await api('me');
  } catch (e) {
    $('.bottom-nav').hidden = true;
    render(h('p', { class: 'error', role: 'alert' }, e.message));
    return;
  }
  const ctx = { h, api, sheet, confirmSheet, toast, setHead, render, field, numInput, seg, segVal, select, num, sep, me: () => ME,
    setMe: v => { ME = v; }, reload: () => route(),
    openTrade: pre => editTrade(null, pre) };
  C = mountCloud(ctx);
  ctx.cloud = C;
  C.handleReturn();
  N = mountAlerts(ctx);
  ctx.newAlert = N.newAlert;
  A = mountAnalysis(ctx);
  N.setBadge(ME.unread);
  M = mountMarket({ ...ctx, adminMethods: A.adminMethods });
  M.setRoute(route);
  if (!location.hash) history.replaceState(null, '', '#/market');
  route();
})();
