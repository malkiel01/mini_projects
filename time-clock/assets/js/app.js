'use strict';
/*
 * שעון נוכחות — ממשק הניהול: פתיחת חברה, הגדרות (פרטים, סניפים, צוות), עובדים וכרטיס עובד.
 * מסכים לפי ה-hash: #/ (בית) · #/settings · #/employees · #/employee/<id> · #/approvals · #/month.
 * האפיון: docs/v1-screens-data.md — M1–M7.
 */

const ROLE = { owner: 'בעלים', manager: 'מנהל', branch_manager: 'מנהל סניף', payroll: 'מנהלת שכר', employee: 'עובד' };
const $ = (s, el = document) => el.querySelector(s);
const main = $('#main');
const state = { me: null, companies: [], companyId: null, data: null, backTo: '#/' };
const PAY = { monthly: 'חודשי', hourly: 'שעתי' };
const INVITE = { pending: ['warn', 'ממתין להפעלה'], expired: ['bad', 'הקישור פג'], none: ['', 'אין קישור'] };

// --vvh: הגובה הנראה (בלי המקלדת) — הגיליון מתקצר והכול נגלל
(() => { const vv = window.visualViewport; if (!vv) return;
  const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
  vv.addEventListener('resize', set); set(); })();

function toast(msg, err = false) {
  const el = $('.toast'); el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function api(action, body = {}) {
  const res = await fetch('api.php?action=' + encodeURIComponent(action), {
    method: action === 'me' ? 'GET' : 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: action === 'me' ? undefined : JSON.stringify(body),
    credentials: 'same-origin',
  });
  let data = null;
  try { data = await res.json(); } catch { /* תשובה שאינה JSON — למשל דף שגיאה */ }
  if (!data || !data.success) {
    const e = new Error(data?.error || 'שגיאת תקשורת'); e.status = res.status; throw e;
  }
  return data;
}

/** "8:24" ⇄ 504 דקות */
const hm = m => Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');
function parseHm(s) {
  const m = String(s).trim().match(/^(\d{1,2})(?::(\d{2}))?$/);
  if (!m || +(m[2] || 0) > 59) return NaN;
  return +m[1] * 60 + +(m[2] || 0);
}

function initials(name) {
  const parts = String(name).trim().split(/\s+/);
  return parts.length > 1 ? parts[0][0] + '״' + parts[1][0] : String(name).slice(0, 2);
}
function hue(s) { let h = 0; for (const c of String(s)) h = (h * 31 + c.codePointAt(0)) % 360; return h; }

/* ===== טעינה וניתוב ===== */

async function boot() {
  try {
    const me = await api('me');
    state.me = me.person; state.companies = me.companies;
    const saved = +sessionStorage.getItem('tc-company');
    state.companyId = (state.companies.find(c => c.id === saved) || state.companies[0])?.id ?? null;
    route();
  } catch (e) {
    $('#top').hidden = true;
    main.innerHTML = `<div class="gate"><div class="logo" aria-hidden="true">🕒</div><h2>שעון נוכחות</h2>
      <p class="hint">${e.status === 401 ? 'נכנסים דרך הכלים הדיגיטליים.' : esc(e.message)}</p></div>`;
  }
}

async function route() {
  const path = location.hash.replace(/^#/, '') || '/';
  $('#top').hidden = false;
  if (!state.companyId || path === '/new') return renderOnboarding(path === '/new');
  try {
    if (!state.data || state.data.company.id !== state.companyId) state.data = await api('company_get', { company_id: state.companyId });
  } catch (e) { main.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  const emp = path.match(/^\/employee\/(\d+)$/);
  if (path === '/settings') renderSettings();
  else if (path === '/employees') await renderEmployees();
  else if (emp) await renderEmployee(+emp[1]);
  else if (path === '/approvals') await renderApprovals();
  else if (path === '/month') await renderMonth();
  else renderHome();
  main.focus({ preventScroll: true });
  refreshBadge();
}

/** מספר התיקונים שממתינים — בלשונית "אישורים". */
async function refreshBadge() {
  try { const r = await api('corr_list', { company_id: state.companyId }); $('#appr-n').textContent = r.corrections.length || ''; }
  catch { /* לא קריטי */ }
}
window.addEventListener('hashchange', route);

function setHeader(title, { back = false, settings = false, tab = null } = {}) {
  $('#title').textContent = title;
  $('#back').hidden = !back;
  state.backTo = typeof back === 'string' ? back : '#/';
  $('#tabs').hidden = tab === null;
  for (const a of $('#tabs').children) {
    if (a.dataset.tab === tab) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  }
  $('#settings-btn').hidden = !settings;
  const co = state.companies.find(c => c.id === state.companyId);
  $('#co-name').textContent = co ? co.name + ' · ' + ROLE[co.role] : (state.me?.name ?? '');
  $('#co-caret').hidden = !co;
  $('#co-btn').disabled = !co;
}

$('#back').addEventListener('click', () => { location.hash = state.backTo; });
$('#settings-btn').addEventListener('click', () => { location.hash = '#/settings'; });
$('#co-btn').addEventListener('click', openCompanyPicker);

/* ===== M1 · פתיחת חברה ===== */

function renderOnboarding(another) {
  setHeader(another ? 'חברה חדשה' : 'שעון נוכחות', { back: another });
  main.innerHTML = `
    ${another ? '' : `<div class="welcome"><div class="logo" aria-hidden="true">🕒</div>
      <h2>שלום ${esc(state.me.name)}</h2><p class="hint">פותחים חברה, ואחר כך מוסיפים סניפים, צוות ועובדים.</p></div>`}
    <form class="form" id="new-co" novalidate>
      ${companyFields({ days_per_week: 5, daily_minutes: 504 })}
      <p class="error" role="alert" hidden></p>
      <div class="actions"><button class="btn primary" type="submit">פתיחת החברה</button></div>
    </form>`;
  $('#new-co').addEventListener('submit', async e => {
    e.preventDefault();
    const body = readCompany(e.target); if (!body) return;
    try {
      const d = await api('company_create', body);
      state.companies.push({ id: d.company.id, name: d.company.name, role: 'owner', site_id: null });
      state.companies.sort((a, b) => a.name.localeCompare(b.name, 'he'));
      selectCompany(d.company.id, d);
      toast('החברה נפתחה');
    } catch (err) { showError(e.target, err.message); }
  });
}

function companyFields(c, readonly = false) {
  const ro = readonly ? ' disabled' : '';
  return `<div class="fields">
      <label class="f wide"><span>שם החברה *</span><input name="name" required maxlength="80" value="${esc(c.name ?? '')}" autocomplete="organization"${ro}></label>
      <label class="f"><span>ח.פ. (רשות)</span><input name="tax_id" dir="ltr" inputmode="numeric" maxlength="9" value="${esc(c.tax_id ?? '')}"${ro}></label>
    </div>
    <div class="fields two">
      <div class="f"><span id="dpw">ימי עבודה בשבוע</span>
        <fieldset class="seg" aria-labelledby="dpw"${ro}>
          <label><input type="radio" name="days_per_week" value="5"${c.days_per_week === 5 ? ' checked' : ''}><span>5</span></label>
          <label><input type="radio" name="days_per_week" value="6"${c.days_per_week === 6 ? ' checked' : ''}><span>6</span></label>
        </fieldset></div>
      <label class="f"><span>שעות תקן ליום</span><input name="daily" dir="ltr" inputmode="numeric" placeholder="8:24" value="${hm(c.daily_minutes)}"${ro}></label>
    </div>
    <p class="hint">התקן הוא ברירת המחדל לעובדים חדשים. אפשר לשנות לכל עובד.</p>`;
}

function readCompany(form) {
  const f = new FormData(form);
  const name = String(f.get('name') || '').trim();
  const minutes = parseHm(f.get('daily'));
  if (!name) return showError(form, 'חסר שם לחברה');
  if (!(minutes >= 60 && minutes <= 720)) return showError(form, 'שעות תקן ליום — למשל 8:24 (בין 1 ל-12 שעות)');
  return { name, tax_id: String(f.get('tax_id') || '').trim(), days_per_week: +f.get('days_per_week'), daily_minutes: minutes };
}

function showError(form, msg) {
  const el = form.querySelector('.error'); el.textContent = msg; el.hidden = false; return null;
}

function selectCompany(id, data = null) {
  state.companyId = id; state.data = data;
  try { sessionStorage.setItem('tc-company', String(id)); } catch { /* גלישה פרטית */ }
  if (location.hash === '#/' || location.hash === '') route(); else location.hash = '#/';
}

function openCompanyPicker() {
  openSheet('החברות שלי', `<ul class="list">${state.companies.map(c => `
      <li><button class="use-row" type="button" data-co="${c.id}">
        <span class="use-ic" aria-hidden="true">${c.id === state.companyId ? '✓' : '🏢'}</span>
        <span class="use-tx"><b>${esc(c.name)}</b><small>${ROLE[c.role]}${c.site_name ? ' · ' + esc(c.site_name) : ''}</small></span>
        <span class="use-go" aria-hidden="true">‹</span></button></li>`).join('')}</ul>
    <div class="tiles" style="margin-top:12px"><button class="tile" type="button" data-new><span class="tile-ic" aria-hidden="true">＋</span>חברה חדשה</button></div>`,
    null, { save: false });
  $('#sheet-body').addEventListener('click', e => {
    const b = e.target.closest('[data-co],[data-new]'); if (!b) return;
    $('#sheet').close();
    if (b.dataset.new !== undefined) location.hash = '#/new'; else selectCompany(+b.dataset.co);
  });
}

/* ===== M2 · היום ===== */

const TZ = 'Asia/Jerusalem';
const clock = iso => new Date(iso).toLocaleTimeString('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });

async function renderHome() {
  const d = state.data;
  setHeader('היום', { settings: true, tab: '/' });
  let b;
  try { b = await api('today_board', { company_id: state.companyId }); }
  catch (e) { main.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  const STATE = {
    in: e => [`נכנס ${clock(e.since)} · ${hm(e.minutes)}`, '<span class="chip ok">בעבודה</span>'],
    done: e => [`${clock(e.first)} · סה״כ ${hm(e.minutes)}`, '<span class="chip">סיים</span>'],
    none: e => [`תקן ${hm(e.standard)}`, '<span class="chip bad">לא דיווח</span>'],
    off: () => ['לא יום עבודה', ''],
  };
  const order = { in: 0, none: 1, done: 2, off: 3 };
  const rows = [...b.employees].sort((x, y) => order[x.state] - order[y.state] || x.name.localeCompare(y.name, 'he'));
  const missing = [];
  if (d.my_role === 'owner' && !d.sites.length) missing.push('סניפים');
  main.innerHTML = `
    <dl class="cells" style="grid-template-columns:repeat(3,1fr)">
      <div><dt>בעבודה</dt><dd>${b.count.in}</dd></div>
      <div><dt>לא דיווחו</dt><dd>${b.count.none}</dd></div>
      <div><dt>חסר אתמול</dt><dd>${b.count.missing}</dd></div></dl>
    ${b.site_only ? `<p class="hint">${esc(siteName(b.site_only))} בלבד.</p>` : ''}
    <ul class="list" id="board">${rows.length ? rows.map(e => {
      const [l2, chip] = STATE[e.state](e);
      return `<li class="card" tabindex="0" role="button" data-id="${e.id}">
        <span class="avatar" style="--h:${hue(e.name)}" aria-hidden="true">${esc(initials(e.name))}</span>
        <div class="body"><div class="l1"><span>${esc(e.name)}</span>${e.site_id && !b.site_only ? `<span class="t">${esc(siteName(e.site_id))}</span>` : ''}</div>
          <div class="l2">${l2}${e.missing_yesterday ? ' · <b style="color:var(--danger)">חסר אתמול</b>' : ''}</div></div>
        ${chip}</li>`;
    }).join('') : `<li class="empty">עוד אין עובדים. <a href="#/employees">להוספת עובדים ›</a></li>`}</ul>
    ${missing.length ? `<p class="hint" style="margin-top:8px">חסר בהגדרות: ${missing.join(', ')}.</p>` : ''}`;
  const open = ev => { const li = ev.target.closest('[data-id]'); if (li) { sessionStorage.setItem('tc-emp-tab', 'month'); location.hash = '#/employee/' + li.dataset.id; } };
  $('#board').addEventListener('click', open);
  $('#board').addEventListener('keydown', ev => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); open(ev); } });
}

/* ===== M7 · הגדרות: פרטים · סניפים · צוות ===== */

function renderSettings() {
  const d = state.data;
  setHeader('הגדרות החברה', { back: true });
  const tab = sessionStorage.getItem('tc-tab') || 'details';
  main.innerHTML = `
    <div class="seg-tabs" role="tablist">
      <button role="tab" data-tab="details" aria-selected="${tab === 'details'}">🏢 פרטים</button>
      <button role="tab" data-tab="sites" aria-selected="${tab === 'sites'}">📍 סניפים <small>${d.sites.length || ''}</small></button>
      <button role="tab" data-tab="team" aria-selected="${tab === 'team'}">👥 צוות <small>${d.team.length}</small></button>
      ${canStations() ? `<button role="tab" data-tab="stations" aria-selected="${tab === 'stations'}">📟 עמדות</button>` : ''}
      <button role="tab" data-tab="steps" aria-selected="${tab === 'steps'}">✍️ אישור</button>
      <button role="tab" data-tab="ot" aria-selected="${tab === 'ot'}">⏱️ נוספות</button>
      <button role="tab" data-tab="hol" aria-selected="${tab === 'hol'}">📅 חגים</button>
    </div><div id="pane"></div>`;
  main.querySelector('.seg-tabs').addEventListener('click', e => {
    const b = e.target.closest('[data-tab]'); if (!b) return;
    try { sessionStorage.setItem('tc-tab', b.dataset.tab); } catch { /* */ }
    renderSettings();
  });
  ({ details: paneDetails, sites: paneSites, team: paneTeam, stations: canStations() ? paneStations : paneDetails, steps: paneSteps, ot: paneOt, hol: paneHolidays })[tab]($('#pane'));
}

const isOwner = () => state.data.my_role === 'owner';
const canStations = () => ['owner', 'manager', 'branch_manager'].includes(state.data.my_role);

function paneDetails(pane) {
  const c = state.data.company;
  pane.innerHTML = `<form class="form" id="co-form" novalidate>
      ${companyFields(c, !isOwner())}
      <p class="error" role="alert" hidden></p>
      ${isOwner() ? '<div class="actions"><button class="btn primary" type="submit">שמירה</button></div>' : '<p class="hint">רק הבעלים משנה את פרטי החברה.</p>'}
    </form>`;
  $('#co-form').addEventListener('submit', async e => {
    e.preventDefault();
    const body = readCompany(e.target); if (!body) return;
    try {
      state.data = await api('company_update', { company_id: c.id, ...body });
      const co = state.companies.find(x => x.id === c.id); co.name = state.data.company.name;
      setHeader('הגדרות החברה', { back: true });
      toast('נשמר');
    } catch (err) { showError(e.target, err.message); }
  });
}

function paneSites(pane) {
  const d = state.data, canEdit = ['owner', 'manager'].includes(d.my_role);
  pane.innerHTML = `
    <p class="hint">סניף משייך עמדות ועובדים, ומנהל סניף רואה רק את הסניף שלו.</p>
    ${canEdit ? '<div class="toolbar"><span></span><button class="btn primary" id="add-site" type="button"><span aria-hidden="true">+</span> סניף</button></div>' : ''}
    <ul class="list">${d.sites.length ? d.sites.map(s => `
      <li class="card">
        <span class="avatar" style="--h:${hue(s.name)}" aria-hidden="true">📍</span>
        <div class="body"><div class="l1"><span>${esc(s.name)}</span></div><div class="l2">${s.members} אנשים בסניף</div></div>
        ${canEdit ? `<button class="icon-btn" type="button" data-edit="${s.id}" aria-label="עריכת ${esc(s.name)}">✏️</button>` : ''}
      </li>`).join('') : '<li class="empty">עוד אין סניפים.</li>'}</ul>`;
  $('#add-site')?.addEventListener('click', () => siteSheet());
  pane.querySelector('.list').addEventListener('click', e => {
    const b = e.target.closest('[data-edit]'); if (b) siteSheet(d.sites.find(s => s.id === +b.dataset.edit));
  });
}

function siteSheet(site = null) {
  openSheet(site ? 'עריכת סניף' : 'סניף חדש', `
    <div class="fields"><label class="f wide"><span>שם הסניף *</span><input name="name" required maxlength="60" value="${esc(site?.name ?? '')}"></label></div>
    ${site && isOwner() ? '<div class="row-btns" style="margin-top:16px"><button class="btn danger" type="button" id="del-site">🗑️ מחיקת הסניף</button></div>' : ''}
    <p class="error" role="alert" hidden></p>`,
    async form => {
      state.data = await api('site_save', { company_id: state.companyId, id: site?.id ?? 0, name: form.name.value });
      return 'נשמר';
    });
  $('#del-site')?.addEventListener('click', async () => {
    if (!confirm(`למחוק את "${site.name}"?`)) return;
    try {
      state.data = await api('site_delete', { company_id: state.companyId, id: site.id });
      $('#sheet').close(); toast('הסניף נמחק'); renderSettings();
    } catch (err) { showError($('#sheet form'), err.message); }
  });
}

function paneTeam(pane) {
  const d = state.data;
  const siteName = id => d.sites.find(s => s.id === id)?.name ?? '';
  pane.innerHTML = `
    <p class="hint">צוות הניהול נכנס דרך הכלים הדיגיטליים, עם המייל שרשום כאן. כדי שייכנס, צריך לתת לו גם הרשאה לשעון הנוכחות בכלים הדיגיטליים.</p>
    ${isOwner() ? '<div class="toolbar"><span></span><button class="btn primary" id="add-team" type="button"><span aria-hidden="true">+</span> איש צוות</button></div>' : ''}
    <ul class="list">${d.team.map(t => `
      <li class="card">
        <span class="avatar" style="--h:${hue(t.name)}" aria-hidden="true">${esc(initials(t.name))}</span>
        <div class="body"><div class="l1"><span>${esc(t.name)}${t.me ? ' (אני)' : ''}</span></div>
          <div class="l2">${ROLE[t.role]}${t.site_id ? ' · ' + esc(siteName(t.site_id)) : ''}</div></div>
        ${t.linked ? '' : '<span class="chip warn">טרם נכנס</span>'}
        ${isOwner() && t.role !== 'owner' ? `<button class="icon-btn" type="button" data-edit="${t.id}" aria-label="עריכת ${esc(t.name)}">✏️</button>` : ''}
      </li>`).join('')}</ul>`;
  $('#add-team')?.addEventListener('click', () => teamSheet());
  pane.querySelector('.list').addEventListener('click', e => {
    const b = e.target.closest('[data-edit]'); if (b) teamSheet(d.team.find(t => t.id === +b.dataset.edit));
  });
}

function teamSheet(t = null) {
  const d = state.data;
  const role = t?.role ?? 'manager';
  openSheet(t ? t.name : 'איש צוות חדש', `
    ${t ? `<p class="hint" dir="ltr" style="text-align:end">${esc(t.email ?? '')}</p>` : `<div class="fields">
      <label class="f"><span>שם *</span><input name="name" required maxlength="80"></label>
      <label class="f"><span>מייל *</span><input name="email" type="email" dir="ltr" inputmode="email" autocomplete="off" required></label>
    </div>`}
    <div class="f" style="margin-top:12px"><span id="role-l">תפקיד</span>
      <fieldset class="seg" aria-labelledby="role-l">
        ${['manager', 'branch_manager', 'payroll'].map(r => `<label><input type="radio" name="role" value="${r}"${r === role ? ' checked' : ''}><span>${ROLE[r]}</span></label>`).join('')}
      </fieldset></div>
    <label class="f" id="site-f" style="margin-top:12px"${role === 'branch_manager' ? '' : ' hidden'}><span>סניף</span>
      <select name="site_id">${d.sites.length ? d.sites.map(s => `<option value="${s.id}"${s.id === t?.site_id ? ' selected' : ''}>${esc(s.name)}</option>`).join('') : '<option value="">קודם מוסיפים סניף</option>'}</select></label>
    <p class="hint" style="margin-top:8px">מנהל — כל החברה. מנהל סניף — רק עובדי הסניף שלו. מנהלת שכר — צפייה, אישור חודשי וייצוא.</p>
    ${t ? '<div class="row-btns" style="margin-top:16px"><button class="btn danger" type="button" id="del-team">הסרה מהצוות</button></div>' : ''}
    <p class="error" role="alert" hidden></p>`,
    async form => {
      const body = { company_id: state.companyId, role: form.role.value, site_id: +form.site_id.value || 0 };
      if (t) state.data = await api('team_update', { ...body, id: t.id });
      else state.data = await api('team_add', { ...body, name: form.name.value, email: form.email.value });
      return t ? 'נשמר' : 'נוסף לצוות';
    });
  const form = $('#sheet form');
  form.addEventListener('change', e => { if (e.target.name === 'role') $('#site-f').hidden = form.role.value !== 'branch_manager'; });
  $('#del-team')?.addEventListener('click', async () => {
    if (!confirm(`להסיר את ${t.name} מהצוות?`)) return;
    try {
      state.data = await api('team_remove', { company_id: state.companyId, id: t.id });
      $('#sheet').close(); toast('הוסר מהצוות'); renderSettings();
    } catch (err) { showError(form, err.message); }
  });
}

/* ===== M5 · אישורים ===== */

async function renderApprovals() {
  setHeader('אישורים', { settings: true, tab: '/approvals' });
  const r = await api('corr_list', { company_id: state.companyId });
  main.innerHTML = `
    <p class="hint">בקשות תיקון מהעובדים. הדיווח המקורי נשמר; אישור מחיל את התיקון בחישוב, ודחייה — עם הסבר לעובד.</p>
    <ul class="list" id="appr">${r.corrections.length ? r.corrections.map(c => `
      <li class="card" data-id="${c.id}">
        <span class="avatar" style="--h:${hue(c.name)}" aria-hidden="true">${esc(initials(c.name))}</span>
        <div class="body"><div class="l1"><span>${esc(c.name)}</span><span class="t">${dHe(c.new_at || c.old_at)}</span></div>
          <div class="l2">${corrText(c)} · ${esc(c.reason)}</div></div>
        ${r.can_decide ? `<button class="icon-btn" type="button" data-ok="${c.id}" aria-label="אישור">✅</button>
                          <button class="icon-btn" type="button" data-no="${c.id}" aria-label="דחייה">✕</button>` : ''}
      </li>`).join('') : '<li class="empty">אין בקשות שממתינות. 👍</li>'}</ul>`;
  $('#appr').addEventListener('click', async e => {
    const ok = e.target.closest('[data-ok]'), no = e.target.closest('[data-no]');
    if (ok) {
      try { await api('corr_decide', { company_id: state.companyId, id: +ok.dataset.ok, approve: true }); toast('אושר'); route(); }
      catch (err) { toast(err.message, true); }
    } else if (no) {
      const c = r.corrections.find(x => x.id === +no.dataset.no);
      openSheet('דחיית בקשה', `<p><b>${esc(c.name)}</b> · ${corrText(c)}</p><p class="hint">סיבה: ${esc(c.reason)}</p>
        <label class="f" style="margin-top:12px"><span>למה? (העובד יראה) *</span><input name="note" maxlength="200" required></label>
        <p class="error" role="alert" hidden></p>`,
        async f => { await api('corr_decide', { company_id: state.companyId, id: c.id, approve: false, note: f.note.value }); return 'נדחה'; });
    }
  });
}

/* ===== M7 · כללי שעות נוספות (גרסה 2) ===== */

async function paneOt(pane) {
  let r;
  try { r = await api('ot_rules_get', { company_id: state.companyId }); }
  catch (e) { pane.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  const c = r.current, ro = r.can_edit ? '' : ' disabled';
  const t = (k, label) => `<label class="f"><span>${label}</span><input name="${k}" dir="ltr" inputmode="numeric" value="${hm(c[k])}"${ro}></label>`;
  const p = (k, label) => `<label class="f"><span>${label}</span><input name="${k}" type="number" min="100" max="500" dir="ltr" value="${c[k]}"${ro}></label>`;
  pane.innerHTML = `
    <p class="warn-box">⚠️ ברירות המחדל נגזרות מחוק שעות עבודה ומנוחה ואינן ייעוץ משפטי. לפני שימוש — אישור של רואה חשבון או יועץ שכר. הסכם או חוזה יכולים לקבוע אחרת.</p>
    <form class="form" id="ot-form" novalidate>
      <h3 class="sec-title" style="margin:0">מתי שעה נעשית נוספת</h3>
      <div class="fields two">${t('daily5', 'ביום — שבוע של 5 ימים')}${t('daily6', 'ביום — שבוע של 6 ימים')}</div>
      <div class="fields two">${t('night', 'משמרת לילה')}${t('night_min', 'מינימום לילה (22:00–06:00)')}</div>
      <div class="fields two">${t('ot1_minutes', 'השעות הנוספות הראשונות')}${t('weekly', 'תקן שבועי')}</div>
      <h3 class="sec-title" style="margin:0">שיעורים (%)</h3>
      <div class="fields two">${p('rate_ot1', 'נוספות ראשונות')}${p('rate_ot2', 'נוספות אחריהן')}</div>
      <div class="fields" style="grid-template-columns:repeat(3,minmax(0,1fr))">${p('rate_rest', 'מנוחה / חג')}${p('rate_rest_ot1', 'נוספות במנוחה')}${p('rate_rest_ot2', 'ואחריהן')}</div>
      <label class="switch"><input type="checkbox" name="holiday_rest_cumulative"${c.holiday_rest_cumulative ? ' checked' : ''}${ro}><span>חג שחל ביום המנוחה — מצטבר (סיווג נפרד)</span></label>
      <div class="fields two" id="hr-rate"${c.holiday_rest_cumulative ? '' : ' hidden'}>${p('rate_holiday_rest', 'חג במנוחה')}</div>
      ${r.can_edit ? `<div class="fields two"><label class="f"><span>בתוקף מתאריך</span><input name="effective_from" type="date" value="${new Date().toLocaleDateString('en-CA', { timeZone: TZ })}"></label></div>
        <p class="error" role="alert" hidden></p>
        <div class="actions"><button class="btn" type="button" id="ot-defaults">ברירות המחדל</button><span style="flex:1"></span><button class="btn primary" type="submit">שמירה</button></div>`
        : '<p class="hint">רק הבעלים משנה את הכללים.</p>'}
    </form>
    <p class="hint" style="margin-top:8px">הסיווג נעשה לכל יום (כולל לילה ומנוחה) ואחר כך לכל שבוע (ראשון–שבת). "שעות התקן" של העובד ממשיכות לשמש ל"הפרש".</p>
    ${r.history.length ? `<h3 class="sec-title">גרסאות</h3><ul class="list">${r.history.map(h => `<li class="card"><div class="body"><div class="l1"><span>מ-${dateHe(h.effective_from)}</span></div>
      <div class="l2">${hm(h.rules.daily5)} / ${hm(h.rules.daily6)} ליום · לילה ${hm(h.rules.night)} · שבוע ${hm(h.rules.weekly)} · ${h.rules.rate_ot1}%/${h.rules.rate_ot2}%</div></div></li>`).join('')}</ul>` : ''}`;
  const form = $('#ot-form');
  form.holiday_rest_cumulative.addEventListener('change', e => { $('#hr-rate').hidden = !e.target.checked; });
  $('#ot-defaults')?.addEventListener('click', () => {
    for (const [k, v] of Object.entries(r.defaults)) {
      const el = form.elements[k]; if (!el) continue;
      if (el.type === 'checkbox') el.checked = !!v; else el.value = el.type === 'number' ? v : hm(v);
    }
    $('#hr-rate').hidden = !r.defaults.holiday_rest_cumulative;
  });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const rules = {};
    try {
      for (const k of ['daily5', 'daily6', 'night', 'night_min', 'ot1_minutes', 'weekly']) {
        const m = parseHm(form.elements[k].value); if (Number.isNaN(m)) throw new Error('שעה לא תקינה — למשל 8:36');
        rules[k] = m;
      }
      for (const k of ['rate_ot1', 'rate_ot2', 'rate_rest', 'rate_rest_ot1', 'rate_rest_ot2', 'rate_holiday_rest']) rules[k] = +form.elements[k].value;
      rules.holiday_rest_cumulative = form.holiday_rest_cumulative.checked;
      r = await api('ot_rules_save', { company_id: state.companyId, effective_from: form.effective_from.value, rules });
      toast('נשמר'); paneOt(pane);
    } catch (err) { showError(form, err.message); }
  });
}

/* ===== M7 · חגים (גרסה 2) ===== */

async function paneHolidays(pane, year = null) {
  let r;
  try { r = await api('holidays_get', { company_id: state.companyId, year }); }
  catch (e) { pane.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  const dd = d => new Date(d + 'T12:00:00Z').toLocaleDateString('he-IL', { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC' });
  const relText = h => h.religions === 'all' ? 'כולם' : h.religions.map(x => r.religions[x]).join(', ');
  pane.innerHTML = `<div>
    <div class="toolbar month-nav">
      <button class="icon-btn" id="hy-prev" type="button" aria-label="השנה הקודמת">‹</button>
      <b class="one" style="flex:1;text-align:center">${r.year}</b>
      <button class="icon-btn" id="hy-next" type="button" aria-label="השנה הבאה">›</button>
    </div>
    <p class="hint">יום חג נספר כמו יום מנוחה (⏱️ נוספות). כל עובד מקבל את החגים של הדת שנבחרה בכרטיס שלו.</p>
    <h3 class="sec-title">ימים של החברה</h3>
    ${r.can_edit ? '<div class="toolbar"><span></span><button class="btn primary" id="add-hol" type="button"><span aria-hidden="true">+</span> יום</button></div>' : ''}
    <ul class="list">${r.company.length ? r.company.map(h => `
      <li class="card"><span class="avatar" style="--h:${hue(h.name)}" aria-hidden="true">📅</span>
        <div class="body"><div class="l1"><span>${esc(h.name)}</span><span class="t">${dd(h.date)}</span></div><div class="l2">${relText(h)}</div></div>
        ${r.can_edit ? `<button class="icon-btn" type="button" data-del="${h.id}" aria-label="מחיקת ${esc(h.name)}">🗑️</button>` : ''}</li>`).join('')
      : '<li class="empty">אין ימים של החברה בשנה הזו.</li>'}</ul>
    <p class="hint" style="margin-top:8px">חגי המוסלמים, הנוצרים והדרוזים נקבעים בהחלטת ממשלה, ומוסיפים אותם כאן עם הדתות שהם חלים עליהן (החגים המוסלמיים תלויים גם בראיית הירח).</p>
    <h3 class="sec-title">חגי ישראל — מובנה (לעובדים יהודים)</h3>
    <ul class="list">${r.jewish.length ? r.jewish.map(h => `<li class="card"><div class="body"><div class="l1"><span>${esc(h.name)}</span><span class="t">${dd(h.date)}</span></div></div></li>`).join('')
      : `<li class="empty">הלוח המובנה עד ${r.built_in_until.slice(0, 4)}.</li>`}</ul>
    <p class="hint" style="margin-top:8px">מחושב מהלוח העברי: ימי המנוחה של סעיף 18א לפקודת סדרי השלטון והמשפט, ויום העצמאות (כולל הקדמה ודחייה).</p>
  </div>`;
  const view = pane.firstElementChild;
  view.querySelector('#hy-prev').addEventListener('click', () => paneHolidays(pane, r.year - 1));
  view.querySelector('#hy-next').addEventListener('click', () => paneHolidays(pane, r.year + 1));
  view.addEventListener('click', async e => {
    const del = e.target.closest('[data-del]'); if (!del) return;
    const h = r.company.find(x => x.id === +del.dataset.del);
    if (!confirm(`למחוק את "${h.name}"?`)) return;
    try { await api('holiday_delete', { company_id: state.companyId, id: h.id }); toast('נמחק'); paneHolidays(pane, r.year); }
    catch (err) { toast(err.message, true); }
  });
  view.querySelector('#add-hol')?.addEventListener('click', () => { openSheet('יום חג של החברה', `
      <div class="fields">
        <label class="f"><span>תאריך *</span><input name="date" type="date" value="${r.year}-01-01" required></label>
        <label class="f"><span>שם *</span><input name="name" maxlength="60" required placeholder="למשל: עיד אל-פיטר"></label>
      </div>
      <div class="f" style="margin-top:12px"><span>חל על</span>
        <label class="switch"><input type="checkbox" name="all" checked><span>כל העובדים</span></label>
        <div class="chips" id="rels" hidden>${Object.entries(r.religions).map(([k, v]) => `<label><input type="checkbox" name="rel" value="${k}"><span>${v}</span></label>`).join('')}</div>
      </div>
      <p class="error" role="alert" hidden></p>`,
    async f => {
      const religions = f.all.checked ? 'all' : [...f.querySelectorAll('[name=rel]:checked')].map(x => x.value);
      await api('holiday_add', { company_id: state.companyId, date: f.date.value, name: f.name.value, religions });
      return () => { toast('נוסף'); paneHolidays(pane, +f.date.value.slice(0, 4)); };
    });
    $('#sheet form').elements.all.addEventListener('change', e => { $('#rels').hidden = e.target.checked; });
  });
}

/* ===== M7 · מסלול האישור החודשי ===== */

async function paneSteps(pane) {
  let r;
  try { r = await api('steps_get', { company_id: state.companyId }); }
  catch (e) { pane.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  let steps = [...r.steps];
  const draw = () => {
    const free = Object.keys(r.labels).filter(k => !steps.includes(k));
    pane.innerHTML = `
      <p class="hint">מי מאשר את דוח הנוכחות החודשי, ובאיזה סדר. השינוי חל על חודשים שייפתחו לאישור מעכשיו.</p>
      <ol class="list" id="steps">${steps.map((k, i) => `
        <li class="card"><span class="avatar" aria-hidden="true">${i + 1}</span>
          <div class="body"><div class="l1"><span>${r.labels[k]}</span></div>
            <div class="l2">${k === 'employee' ? 'העובד מהטלפון' : k === 'manager' ? 'בעלים, מנהל, או מנהל הסניף של העובד' : k === 'payroll' ? 'מנהלת השכר (או הבעלים)' : 'הבעלים'}</div></div>
          ${r.can_edit ? `<button class="icon-btn" type="button" data-up="${i}" aria-label="למעלה"${i ? '' : ' disabled'}>↑</button>
            <button class="icon-btn" type="button" data-del="${i}" aria-label="הסרה"${steps.length > 1 ? '' : ' disabled'}>🗑️</button>` : ''}
        </li>`).join('')}</ol>
      ${r.can_edit ? `<div class="toolbar" style="margin-top:12px">
          ${free.length ? `<select id="add-step" aria-label="שלב להוספה">${free.map(k => `<option value="${k}">${r.labels[k]}</option>`).join('')}</select>
          <button class="btn" type="button" id="add-step-btn">+ שלב</button>` : ''}
          <span style="flex:1"></span><button class="btn primary" type="button" id="save-steps">שמירה</button></div>` : '<p class="hint">רק הבעלים משנה את המסלול.</p>'}`;
  };
  draw();
  pane.addEventListener('click', async e => {
    const up = e.target.closest('[data-up]'), del = e.target.closest('[data-del]');
    if (up) { const i = +up.dataset.up; [steps[i - 1], steps[i]] = [steps[i], steps[i - 1]]; draw(); }
    else if (del) { steps.splice(+del.dataset.del, 1); draw(); }
    else if (e.target.id === 'add-step-btn') { steps.push($('#add-step').value); draw(); }
    else if (e.target.id === 'save-steps') {
      try { r = await api('steps_save', { company_id: state.companyId, steps }); steps = [...r.steps]; draw(); toast('נשמר'); }
      catch (err) { toast(err.message, true); }
    }
  });
}

/* ===== M6 · חודש: אישור, נעילה, ייצוא ===== */

let monthShown = null;

async function renderMonth(delta = 0) {
  setHeader('חודש', { settings: true, tab: '/month' });
  if (!monthShown) monthShown = new Date().toLocaleDateString('en-CA', { timeZone: TZ }).slice(0, 7);
  if (delta) { const [y, m] = monthShown.split('-').map(Number); monthShown = new Date(Date.UTC(y, m - 1 + delta, 1)).toISOString().slice(0, 7); }
  let v;
  try { v = await api('period_view', { company_id: state.companyId, month: monthShown }); }
  catch (e) { main.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  const [y, mo] = v.month.split('-').map(Number);
  const title = new Date(Date.UTC(y, mo - 1, 15)).toLocaleDateString('he-IL', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  const hmS = m => (m < 0 ? '−' : '') + hm(Math.abs(m));
  const stepChips = e => v.steps.map((k, i) => {
    const s = e.signoffs.find(x => x.step === i);
    return `<span class="chip ${s ? 'ok' : e.next === i && v.status === 'review' ? 'warn' : ''}" title="${s ? esc(s.on_behalf ? 'במקום העובד: ' + (s.note ?? '') : 'אושר') : ''}">${s ? '✓ ' : ''}${v.labels[k]}${s?.on_behalf ? '*' : ''}</span>`;
  }).join('<span aria-hidden="true">←</span>');
  const status = v.status === 'open' ? 'פתוח — עוד לא נשלח לאישור' : v.status === 'locked' ? '🔒 נעול' : `באישור — ${v.done} מתוך ${v.employees.length} הושלמו`;
  const bulk = v.employees.filter(e => e.can_approve && v.steps[e.next] !== 'employee');
  main.innerHTML = `<div id="mview">
    <div class="toolbar month-nav">
      <button class="icon-btn" id="prev" type="button" aria-label="החודש הקודם">‹</button>
      <b class="one" style="flex:1;text-align:center">${title}</b>
      <button class="icon-btn" id="next" type="button" aria-label="החודש הבא"${v.month >= v.today.slice(0, 7) ? ' disabled' : ''}>›</button>
    </div>
    <div class="summary">
      <div class="line"><b>${status}</b></div>
      <div class="steps">${v.steps.map(k => `<span class="chip">${v.labels[k]}</span>`).join('<span aria-hidden="true">←</span>')}</div>
      ${v.site_only ? `<div class="line muted">${esc(siteName(v.site_only))} בלבד</div>` : ''}
    </div>
    <div class="row-btns" style="flex-wrap:wrap;margin-bottom:12px">
      ${v.can.open ? '<button class="btn primary" type="button" id="p-open">פתיחה לאישור</button>' : ''}
      ${bulk.length ? `<button class="btn primary" type="button" id="p-approve">✓ אישור המסומנים</button>` : ''}
      ${v.can.lock ? '<button class="btn" type="button" id="p-lock">🔒 נעילה</button>' : ''}
      ${v.can.reopen ? '<button class="btn" type="button" id="p-reopen">פתיחה מחדש</button>' : ''}
      <button class="icon-btn" type="button" id="p-csv" aria-label="ייצוא לאקסל">⬇️</button>
      <button class="icon-btn" type="button" id="p-print" aria-label="הדפסה">🖨️</button>
    </div>
    ${bulk.length ? `<label class="hint" style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="all"> סימון כל מי שממתין לאישור שלי (${bulk.length})</label>` : ''}
    <ul class="list">${v.employees.length ? v.employees.map(e => {
      const s = e.summary, mine = e.can_approve && v.steps[e.next] !== 'employee', behalf = e.can_approve && v.steps[e.next] === 'employee';
      return `<li class="card">
        ${mine ? `<input type="checkbox" class="pick" value="${e.id}" aria-label="סימון ${esc(e.name)}">` : ''}
        <div class="body" data-emp="${e.id}" style="cursor:pointer"><div class="l1"><span>${esc(e.name)}</span><span class="t">${hm(s.minutes)} / ${hm(s.standard)}</span></div>
          <div class="l2">הפרש ${hmS(s.diff)}${s.missing ? ` · <b style="color:var(--danger)">${s.missing} חסרים</b>` : ''}${e.pending ? ` · ${e.pending} תיקונים ממתינים` : ''}</div>
          ${s.ot && (s.ot.ot1 + s.ot.ot2 + s.ot.rest + s.ot.rest_ot1 + s.ot.rest_ot2 + s.ot.holiday_rest) ? `<div class="l2" style="font-size:.78rem">נוספות ${hm(s.ot.ot1 + s.ot.ot2)} · מנוחה/חג ${hm(s.ot.rest + s.ot.rest_ot1 + s.ot.rest_ot2 + s.ot.holiday_rest)}</div>` : ''}
          ${v.status !== 'open' ? `<div class="steps" style="margin-top:4px">${stepChips(e)}</div>` : ''}</div>
        ${behalf ? `<button class="btn sm" type="button" data-behalf="${e.id}">במקום העובד</button>` : ''}
      </li>`;
    }).join('') : '<li class="empty">אין עובדים בחודש הזה.</li>'}</ul>
    ${v.status === 'review' ? '<p class="hint" style="margin-top:8px">תיקון שמאושר בחודש הזה מבטל את האישורים של אותו עובד — הם אישרו נתונים אחרים. * — אושר במקום העובד.</p>' : ''}
  </div>`;
  const view = $('#mview'), body = { company_id: state.companyId, month: v.month };
  $('#prev').addEventListener('click', () => renderMonth(-1));
  $('#next').addEventListener('click', () => renderMonth(1));
  const run = async (action, extra = {}, msg = 'נשמר') => {
    try { await api(action, { ...body, ...extra }); toast(msg); renderMonth(); } catch (err) { toast(err.message, true); }
  };
  $('#p-open')?.addEventListener('click', () => { if (confirm(`לפתוח את ${title} לאישור? העובדים יראו בקשה לאשר את החודש.`)) run('period_open', {}, 'נפתח לאישור'); });
  $('#all')?.addEventListener('change', e => { for (const c of view.querySelectorAll('.pick')) c.checked = e.target.checked; });
  $('#p-approve')?.addEventListener('click', () => {
    const ids = [...view.querySelectorAll('.pick:checked')].map(c => +c.value);
    if (!ids.length) return toast('לא סומנו עובדים', true);
    run('period_approve', { member_ids: ids }, `אושרו ${ids.length}`);
  });
  $('#p-lock')?.addEventListener('click', () => {
    const all = v.done === v.employees.length;
    if (all) { if (confirm(`לנעול את ${title}? אחרי הנעילה אין תיקונים.`)) run('period_lock', {}, 'ננעל'); return; }
    openSheet('נעילה לפני שכולם אישרו', `<p>${v.employees.length - v.done} עובדים עוד לא השלימו את כל השלבים. נעילה בכל זאת — רק בעלים, עם סיבה.</p>
      <label class="f" style="margin-top:12px"><span>סיבה *</span><input name="reason" maxlength="200" required></label><p class="error" role="alert" hidden></p>`,
      async f => { await api('period_lock', { ...body, reason: f.reason.value }); return () => { toast('ננעל'); renderMonth(); }; });
  });
  $('#p-reopen')?.addEventListener('click', () => openSheet('פתיחה מחדש', `<p>החודש יחזור לאישור, וכל האישורים יתבטלו (הם נשמרים בתיעוד).</p>
      <label class="f" style="margin-top:12px"><span>למה? *</span><input name="reason" maxlength="200" required></label><p class="error" role="alert" hidden></p>`,
    async f => { await api('period_reopen', { ...body, reason: f.reason.value }); return () => { toast('נפתח מחדש'); renderMonth(); }; }));
  view.addEventListener('click', e => {
    const b = e.target.closest('[data-behalf]'), emp = e.target.closest('[data-emp]');
    if (b) {
      const x = v.employees.find(z => z.id === +b.dataset.behalf);
      openSheet('אישור במקום העובד', `<p>${esc(x.name)} עוד לא אישר את ${title}. האישור יסומן "במקום העובד", עם ההערה.</p>
        <label class="f" style="margin-top:12px"><span>הערה *</span><input name="note" maxlength="200" required placeholder="למשל: אושר בשיחה"></label><p class="error" role="alert" hidden></p>`,
        async f => { await api('period_approve', { ...body, member_ids: [x.id], on_behalf: true, note: f.note.value }); return () => { toast('אושר'); renderMonth(); }; });
    } else if (emp) { sessionStorage.setItem('tc-emp-tab', 'month'); location.hash = '#/employee/' + emp.dataset.emp; }
  });
  $('#p-csv').addEventListener('click', async () => {
    try {
      const res = await fetch('api.php?action=period_csv', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' });
      if (!res.ok) throw new Error((await res.json().catch(() => null))?.error || 'הייצוא נכשל');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(await res.blob());
      a.download = `attendance-${v.month}.csv`;
      document.body.append(a); a.click(); a.remove();
    } catch (err) { toast(err.message, true); }
  });
  $('#p-print').addEventListener('click', () => {
    let box = $('#print-box');
    if (!box) { box = document.createElement('div'); box.id = 'print-box'; box.className = 'print-only'; document.body.append(box); }
    box.innerHTML = `<h1>${esc(state.data.company.name)} — נוכחות ${title} (${status})</h1>
      <table><thead><tr><th>עובד</th><th>מס׳</th><th>ימים</th><th>שעות</th><th>תקן</th><th>הפרש</th><th>חסרים</th><th>רגילות</th><th>נוספות</th><th>מנוחה/חג</th>${v.steps.map(k => `<th>${v.labels[k]}</th>`).join('')}</tr></thead>
      <tbody>${v.employees.map(e => `<tr><td>${esc(e.name)}</td><td>${esc(e.employee_no ?? '')}</td><td>${e.summary.days}</td><td>${hm(e.summary.minutes)}</td>
        <td>${hm(e.summary.standard)}</td><td>${hmS(e.summary.diff)}</td><td>${e.summary.missing || ''}</td>
        <td>${hm(e.summary.ot?.reg ?? 0)}</td><td>${hm((e.summary.ot?.ot1 ?? 0) + (e.summary.ot?.ot2 ?? 0))}</td><td>${hm(['rest', 'rest_ot1', 'rest_ot2', 'holiday_rest'].reduce((a, k) => a + (e.summary.ot?.[k] ?? 0), 0))}</td>
        ${v.steps.map((k, i) => { const s = e.signoffs.find(x => x.step === i); return `<td>${s ? new Date(s.at).toLocaleDateString('he-IL', { timeZone: TZ }) + (s.on_behalf ? '*' : '') : ''}</td>`; }).join('')}</tr>`).join('')}</tbody></table>
      <p style="margin-top:8px;font-size:9pt">"הפרש" — מול שעות התקן של העובד. "נוספות" ו"מנוחה/חג" — לפי כללי החברה (הפירוט לפי שיעור — בייצוא לאקסל). * — אושר במקום העובד.</p>`;
    window.print();
  });
}

/* ===== M7 · עמדות ===== */

const ago = iso => {
  if (!iso) return 'עוד לא';
  const m = Math.round((Date.now() - new Date(iso)) / 60000);
  return m < 2 ? 'עכשיו' : m < 60 ? `לפני ${m} דק׳` : m < 1440 ? `לפני ${Math.round(m / 60)} שע׳` : new Date(iso).toLocaleDateString('he-IL', { timeZone: TZ });
};

async function paneStations(pane) {
  let r;
  try { r = await api('station_list', { company_id: state.companyId }); }
  catch (e) { pane.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  pane.innerHTML = `
    <p class="hint">עמדה היא טאבלט בעסק: העובד בוחר את שמו, מקיש את הקוד האישי ומדווח. בעמדה עם סניף מופיעים רק עובדי הסניף.</p>
    <div class="toolbar"><span></span><button class="btn primary" id="add-station" type="button"><span aria-hidden="true">+</span> עמדה</button></div>
    <ul class="list">${r.stations.length ? r.stations.map(s => `
      <li class="card">
        <span class="avatar" style="--h:${s.paired ? 140 : 40}" aria-hidden="true">📟</span>
        <div class="body"><div class="l1"><span>${esc(s.name)}</span></div>
          <div class="l2">${s.site_id ? esc(siteName(s.site_id)) + ' · ' : ''}${s.paired ? 'פעילה ' + ago(s.last_seen) : 'ממתינה להצמדה'}</div></div>
        <button class="icon-btn" type="button" data-repair="${s.id}" aria-label="קוד הצמדה חדש ל${esc(s.name)}">🔄</button>
        <button class="icon-btn" type="button" data-revoke="${s.id}" aria-label="ביטול ${esc(s.name)}">🗑️</button>
      </li>`).join('') : '<li class="empty">עוד אין עמדות.</li>'}</ul>`;
  $('#add-station').addEventListener('click', () => openSheet('עמדה חדשה', `
      <div class="fields">
        <label class="f"><span>שם העמדה *</span><input name="name" required maxlength="60" placeholder="למשל: כניסה ראשית"></label>
        ${r.site_only ? '' : siteSelect(null)}
      </div>
      <p class="error" role="alert" hidden></p>`,
    async f => {
      const x = await api('station_create', { company_id: state.companyId, name: f.name.value, site_id: +(f.site_id?.value || 0) });
      return () => codeSheet(x, f.name.value);
    }));
  pane.querySelector('.list').addEventListener('click', async e => {
    const rp = e.target.closest('[data-repair]'), rv = e.target.closest('[data-revoke]');
    const s = r.stations.find(x => x.id === +(rp || rv)?.dataset[rp ? 'repair' : 'revoke']);
    if (!s) return;
    try {
      if (rp) {
        if (s.paired && !confirm(`קוד חדש ינתק את הטאבלט הנוכחי של "${s.name}". להמשיך?`)) return;
        codeSheet(await api('station_create', { company_id: state.companyId, id: s.id }), s.name);
      } else {
        if (!confirm(`לבטל את העמדה "${s.name}"? הטאבלט יתנתק מיד.`)) return;
        await api('station_revoke', { company_id: state.companyId, id: s.id }); toast('העמדה בוטלה'); paneStations(pane);
      }
    } catch (err) { toast(err.message, true); }
  });
}

/** קוד ההצמדה — מוצג פעם אחת, 10 דקות. */
function codeSheet(x, name) {
  const url = new URL('s/', location.href).href;
  const until = new Date(x.expires).toLocaleTimeString('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
  openSheet('הצמדת ' + name, `
    <ol style="padding-inline-start:20px;display:grid;gap:8px">
      <li>בטאבלט שבעסק פותחים את הכתובת:<br><b dir="ltr" class="one" style="display:block">${esc(url)}</b></li>
      <li>מקלידים את הקוד:</li>
    </ol>
    <div class="clock" dir="ltr" style="letter-spacing:.15em">${x.code.slice(0, 3)} ${x.code.slice(3)}</div>
    <p class="hint center">בתוקף עד ${until}. אפשר ליצור קוד חדש ב-🔄.</p>`,
    null, { save: false });
  $('#sheet').addEventListener('close', () => { if (location.hash === '#/settings') renderSettings(); }, { once: true });
}

/* ===== M3 · עובדים ===== */

const siteName = id => state.data.sites.find(s => s.id === id)?.name ?? '';
const termsLine = t => t ? `${t.days_per_week} ימים · ${hm(t.daily_minutes)} ליום · ${PAY[t.pay_type]}` : 'בלי תנאים';
const isoToday = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jerusalem' });
const dateHe = d => d ? new Date(d + 'T12:00:00').toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: 'numeric' }) : '';

function inviteChip(e) {
  if (e.status === 'left') return '<span class="chip">סיים</span>';
  if (e.invite === 'active') return '';
  const [cls, label] = INVITE[e.invite];
  return `<span class="chip ${cls}">${label}</span>`;
}

async function renderEmployees() {
  setHeader('עובדים', { settings: true, tab: '/employees' });
  const d = await api('emp_list', { company_id: state.companyId });
  state.emp = d;
  const active = d.employees.filter(e => e.status === 'active').length;
  main.innerHTML = `
    <div class="toolbar">
      <input type="search" id="emp-q" placeholder="חיפוש לפי שם או מספר" aria-label="חיפוש עובד">
      ${d.can_edit ? '<button class="btn primary" id="add-emp" type="button"><span aria-hidden="true">+</span> עובד</button>' : ''}
    </div>
    ${d.site_only ? `<p class="hint">עובדי ${esc(siteName(d.site_only))} בלבד.</p>` : ''}
    <ul class="list" id="emp-list"></ul>
    <p class="hint" style="margin-top:8px">${active} עובדים פעילים.</p>`;
  const list = $('#emp-list');
  const draw = q => {
    const rows = d.employees.filter(e => !q || e.name.includes(q) || (e.employee_no ?? '') === q);
    list.innerHTML = rows.length ? rows.map(e => `
      <li class="card" tabindex="0" role="button" data-id="${e.id}">
        <span class="avatar" style="--h:${hue(e.name)}" aria-hidden="true">${esc(initials(e.name))}</span>
        <div class="body"><div class="l1"><span>${esc(e.name)}</span>${e.employee_no ? `<span class="t">מס׳ ${esc(e.employee_no)}</span>` : ''}</div>
          <div class="l2">${e.site_id ? esc(siteName(e.site_id)) + ' · ' : ''}${termsLine(e.terms)}</div></div>
        ${inviteChip(e)}
      </li>`).join('')
      : `<li class="empty">${q ? 'לא נמצא.' : 'עוד אין עובדים. "+ עובד" כדי להוסיף.'}</li>`;
  };
  draw('');
  $('#emp-q').addEventListener('input', e => draw(e.target.value.trim()));
  const open = e => { const li = e.target.closest('[data-id]'); if (li) location.hash = '#/employee/' + li.dataset.id; };
  list.addEventListener('click', open);
  list.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(e); } });
  $('#add-emp')?.addEventListener('click', addEmployeeSheet);
}

function siteSelect(selected, disabled = false) {
  if (!state.data.sites.length) return '';
  return `<label class="f"><span>סניף</span><select name="site_id"${disabled ? ' disabled' : ''}>
      <option value="">בלי סניף</option>
      ${state.data.sites.map(s => `<option value="${s.id}"${s.id === selected ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}
    </select></label>`;
}

function termsFields(t) {
  return `<div class="fields two">
      <div class="f"><span id="t-days">ימים בשבוע</span>
        <fieldset class="seg" aria-labelledby="t-days">
          ${[5, 6].map(n => `<label><input type="radio" name="days_per_week" value="${n}"${t.days_per_week === n ? ' checked' : ''}><span>${n}</span></label>`).join('')}
        </fieldset></div>
      <label class="f"><span>שעות תקן ליום</span><input name="daily" dir="ltr" inputmode="numeric" value="${hm(t.daily_minutes)}"></label>
    </div>
    <div class="f" style="margin-top:8px"><span id="t-pay">שכר</span>
      <fieldset class="seg" aria-labelledby="t-pay">
        ${Object.entries(PAY).map(([k, v]) => `<label><input type="radio" name="pay_type" value="${k}"${t.pay_type === k ? ' checked' : ''}><span>${v}</span></label>`).join('')}
      </fieldset></div>`;
}

function readTerms(form) {
  const minutes = parseHm(form.daily.value);
  if (!(minutes >= 60 && minutes <= 720)) throw new Error('שעות תקן ליום — למשל 8:24 (בין 1 ל-12 שעות)');
  return { days_per_week: +form.days_per_week.value, daily_minutes: minutes, pay_type: form.pay_type.value };
}

function addEmployeeSheet() {
  const c = state.data.company, siteOnly = state.emp?.site_only ?? null;
  openSheet('עובד חדש', `
    <div class="fields">
      <label class="f wide"><span>שם מלא *</span><input name="name" required maxlength="80" autocomplete="off"></label>
      <label class="f"><span>טלפון (לוואטסאפ)</span><input name="phone" type="tel" dir="ltr" inputmode="tel" autocomplete="off"></label>
      <label class="f"><span>מס׳ עובד</span><input name="employee_no" dir="ltr" inputmode="numeric" maxlength="20"></label>
      ${siteOnly ? '' : siteSelect(null)}
      <label class="f"><span>תאריך התחלה</span><input name="start_date" type="date" value="${isoToday()}"></label>
    </div>
    <h3 class="sec-title">תנאים</h3>
    ${termsFields({ days_per_week: c.days_per_week, daily_minutes: c.daily_minutes, pay_type: 'monthly' })}
    <p class="hint" style="margin-top:8px">אחרי השמירה נפתח קישור הפעלה לשליחה לעובד.</p>
    <p class="error" role="alert" hidden></p>`,
    async form => {
      const r = await api('emp_add', { company_id: state.companyId, name: form.name.value, phone: form.phone.value,
        employee_no: form.employee_no.value, site_id: +(form.site_id?.value || 0), start_date: form.start_date.value, ...readTerms(form) });
      return () => { inviteSheet(r.employee, r.invite_token, () => { location.hash = '#/employee/' + r.employee.id; }); };
    });
}

/** קישור ההפעלה — מוצג פעם אחת: בשרת נשמר רק ה-hash. */
function inviteSheet(e, token, after) {
  const url = new URL('e/#t=' + token, location.href).href;
  const msg = `שלום ${e.name}, זה הקישור שלך לשעון הנוכחות של ${state.data.company.name}:\n${url}\nהקישור בתוקף 7 ימים.`;
  const wa = 'https://wa.me/' + (e.phone ?? '') + '?text=' + encodeURIComponent(msg);
  openSheet('קישור הפעלה', `
    <p>שולחים ל<b>${esc(e.name)}</b> את הקישור. בפתיחה הוא בוחר קוד בן 4 ספרות, והטלפון נשאר מחובר.</p>
    <input class="one" readonly dir="ltr" value="${esc(url)}" aria-label="הקישור" style="margin-block:12px;inline-size:100%">
    <div class="row-btns">
      <a class="btn primary" href="${esc(wa)}" target="_blank" rel="noopener">שליחה בוואטסאפ</a>
      <button class="btn" type="button" id="copy-link">העתקה</button>
    </div>
    <p class="hint" style="margin-top:12px">הקישור חד-פעמי ובתוקף 7 ימים. הוא לא יוצג שוב — אם צריך, יוצרים קישור חדש מכרטיס העובד.</p>`,
    null, { save: false });
  $('#copy-link').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(msg); toast('הועתק'); }
    catch { $('#sheet-body input').select(); toast('סמנו והעתיקו את הקישור', true); }
  });
  $('#sheet').addEventListener('close', () => after?.(), { once: true });
}

/* ===== M4 · כרטיס עובד ===== */

async function renderEmployee(id) {
  setHeader('כרטיס עובד', { back: '#/employees', tab: '/employees' });
  const d = await api('emp_get', { company_id: state.companyId, id });
  const e = d.employee, canEdit = d.can_edit && e.status === 'active';
  const tab = sessionStorage.getItem('tc-emp-tab') || 'month';
  const status = e.status === 'left' ? `סיים ב-${dateHe(e.end_date)}`
    : e.invite === 'active' ? 'מחובר מהטלפון' : INVITE[e.invite][1];
  main.innerHTML = `
    <div class="summary">
      <div class="line"><b>${esc(e.name)}</b>${e.employee_no ? `<span class="muted">· מס׳ ${esc(e.employee_no)}</span>` : ''}</div>
      <div class="line muted">${e.site_id ? esc(siteName(e.site_id)) + ' · ' : ''}${termsLine(e.terms)}</div>
      <div class="chips">${inviteChip(e) || '<span class="chip ok">מחובר</span>'}${e.has_pin ? '<span class="chip">יש קוד</span>' : ''}</div>
    </div>
    ${canEdit ? `<div class="row-btns" style="margin-bottom:12px"><button class="btn" type="button" id="new-link">🔗 ${e.invite === 'none' || e.invite === 'expired' ? 'קישור הפעלה' : 'קישור חדש'}</button></div>` : ''}
    <div class="seg-tabs" role="tablist">
      <button role="tab" data-tab="month" aria-selected="${tab === 'month'}">📅 נוכחות</button>
      <button role="tab" data-tab="details" aria-selected="${tab === 'details'}">👤 פרטים</button>
      <button role="tab" data-tab="terms" aria-selected="${tab === 'terms'}">📄 תנאים <small>${d.terms_history.length}</small></button>
    </div><div id="pane"></div>
    <p class="hint">${status}.</p>`;
  main.querySelector('.seg-tabs').addEventListener('click', ev => {
    const b = ev.target.closest('[data-tab]'); if (!b) return;
    try { sessionStorage.setItem('tc-emp-tab', b.dataset.tab); } catch { /* */ }
    renderEmployee(id);
  });
  $('#new-link')?.addEventListener('click', async () => {
    if (e.invite === 'active' && !confirm(`קישור חדש ינתק את הטלפון הנוכחי של ${e.name}. להמשיך?`)) return;
    try { const r = await api('emp_invite', { company_id: state.companyId, id }); inviteSheet(r.employee, r.invite_token, () => renderEmployee(id)); }
    catch (err) { toast(err.message, true); }
  });
  ({ month: paneMonth, terms: paneTerms, details: paneEmpDetails })[tab]($('#pane'), d, canEdit);
}

async function paneMonth(pane, d, _canEdit, month = null) {
  const id = d.employee.id;
  let r;
  try { r = await api('emp_month_m', { company_id: state.companyId, id, month }); }
  catch (e) { pane.innerHTML = `<p class="error" role="alert">${esc(e.message)}</p>`; return; }
  let corr = [];
  try { corr = (await api('corr_history', { company_id: state.companyId, member_id: id })).corrections; } catch { /* */ }
  pane.innerHTML = '<div>' + monthHtml(r) + '</div>';
  const view = pane.firstElementChild;   // מאזינים על העטיפה — pane נשאר בין חודשים
  const shift = delta => { const [y, m] = r.month.split('-').map(Number); return new Date(Date.UTC(y, m - 1 + delta, 1)).toISOString().slice(0, 7); };
  view.querySelector('#prev').addEventListener('click', () => paneMonth(pane, d, _canEdit, shift(-1)));
  view.querySelector('#next').addEventListener('click', () => paneMonth(pane, d, _canEdit, shift(1)));
  const canFix = d.can_edit && d.employee.status === 'active';
  const open = e => { const li = e.target.closest('[data-date]'); const day = li && r.days.find(x => x.date === li.dataset.date); if (day) mgrDay(d.employee, day, corr, canFix); };
  view.addEventListener('click', open);
  view.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(e); } });
}

/** יום של עובד אצל המנהל: הדיווחים, התיקונים — ותיקון ישיר (מאושר מיד, עם סיבה). */
function mgrDay(emp, day, corr, canFix) {
  openSheet(emp.name + ' · ' + dHe(day.date + 'T12:00:00Z'),
    (canFix ? '<p class="hint">תיקון של מנהל נכנס לתוקף מיד ונרשם ביומן. המקור נשמר.</p>' : '') + dayHtml(day, corr), null, { save: false });
  const body = $('#sheet-body');
  if (!canFix) { for (const b of body.querySelectorAll('[data-move],[data-void],[data-add],[data-cancel]')) b.remove(); return; }
  for (const b of body.querySelectorAll('[data-cancel]')) b.remove();
  body.addEventListener('click', e => {
    const b = e.target.closest('[data-move],[data-void],[data-add]'); if (!b) return;
    const op = b.dataset.add !== undefined ? 'add' : b.dataset.move ? 'move' : 'void';
    const punch = day.punches.find(p => p.id === +(b.dataset.move || b.dataset.void));
    openSheet('תיקון', correctionForm(op, day, punch), async f => {
      await api('corr_direct', { company_id: state.companyId, member_id: emp.id, ...correctionBody(op, f, punch) });
      return 'תוקן';
    });
  });
}

function paneEmpDetails(pane, d, canEdit) {
  const e = d.employee, ro = canEdit ? '' : ' disabled', siteOnly = state.emp?.site_only ?? null;
  pane.innerHTML = `<form class="form" id="emp-form" novalidate>
      <div class="fields">
        <label class="f wide"><span>שם מלא *</span><input name="name" required maxlength="80" value="${esc(e.name)}"${ro}></label>
        <label class="f"><span>טלפון</span><input name="phone" type="tel" dir="ltr" inputmode="tel" value="${esc(e.phone ? '0' + e.phone.slice(3) : '')}"${ro}></label>
        <label class="f"><span>מייל</span><input name="email" type="email" dir="ltr" inputmode="email" value="${esc(e.email ?? '')}"${ro}></label>
        <label class="f"><span>מס׳ עובד</span><input name="employee_no" dir="ltr" inputmode="numeric" value="${esc(e.employee_no ?? '')}"${ro}></label>
        ${siteSelect(e.site_id, !canEdit || siteOnly !== null)}
        <label class="f"><span>תאריך התחלה</span><input name="start_date" type="date" value="${esc(e.start_date ?? '')}"${ro}></label>
        <label class="f"><span>דת (לוח חגים ויום מנוחה)</span><select name="religion"${ro}>
          ${[['', 'לא צוין (יהודי)'], ['jewish', 'יהודי'], ['muslim', 'מוסלמי'], ['christian', 'נוצרי'], ['druze', 'דרוזי'], ['other', 'אחר']].map(([v, l]) => `<option value="${v}"${(e.religion ?? '') === v ? ' selected' : ''}>${l}</option>`).join('')}</select></label>
        <label class="f"><span>יום מנוחה</span><select name="rest_day"${ro}>
          <option value="">לפי הדת</option>${['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'].map((n, i) => `<option value="${i}"${e.rest_day === i ? ' selected' : ''}>${n}</option>`).join('')}</select></label>
      </div>
      <p class="error" role="alert" hidden></p>
      ${canEdit ? `<div class="actions"><button class="btn danger" type="button" id="end-emp">סיום העסקה</button><span style="flex:1"></span><button class="btn primary" type="submit">שמירה</button></div>` : ''}
    </form>`;
  if (!canEdit) return;
  const form = $('#emp-form');
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    try {
      await api('emp_update', { company_id: state.companyId, id: e.id, name: form.name.value, phone: form.phone.value, email: form.email.value,
        employee_no: form.employee_no.value, site_id: +(form.site_id?.value || 0), start_date: form.start_date.value,
        religion: form.religion.value, rest_day: form.rest_day.value });
      toast('נשמר'); renderEmployee(e.id);
    } catch (err) { showError(form, err.message); }
  });
  $('#end-emp').addEventListener('click', () => openSheet('סיום העסקה', `
      <p>${esc(e.name)} לא יוכל לדווח יותר, והטלפון שלו יתנתק. הנתונים נשמרים.</p>
      <div class="fields" style="margin-top:12px"><label class="f"><span>תאריך סיום</span><input name="end_date" type="date" value="${isoToday()}"></label></div>
      <p class="error" role="alert" hidden></p>`,
    async f => { await api('emp_end', { company_id: state.companyId, id: e.id, end_date: f.end_date.value }); return 'ההעסקה הסתיימה'; }));
}

function paneTerms(pane, d, canEdit) {
  const e = d.employee, current = e.terms?.id;
  pane.innerHTML = `
    <p class="hint">תנאים לפי תאריך תחולה. שינוי לא מוחק את הקודם — הוא חל מהתאריך שבוחרים.</p>
    ${canEdit ? '<div class="toolbar"><span></span><button class="btn primary" id="add-terms" type="button"><span aria-hidden="true">+</span> שינוי תנאים</button></div>' : ''}
    <ul class="list">${d.terms_history.map(t => `
      <li class="card">
        <span class="avatar" style="--h:${t.id === current ? 140 : 220}" aria-hidden="true">📄</span>
        <div class="body"><div class="l1"><span>מ-${dateHe(t.effective_from)}</span></div><div class="l2">${termsLine(t)}</div></div>
        ${t.id === current ? '<span class="chip ok">בתוקף</span>' : t.effective_from > isoToday() ? '<span class="chip warn">עתידי</span>' : ''}
      </li>`).join('')}</ul>`;
  $('#add-terms')?.addEventListener('click', () => openSheet('שינוי תנאים', `
      <div class="fields"><label class="f"><span>בתוקף מתאריך</span><input name="effective_from" type="date" value="${isoToday()}"></label></div>
      <div style="margin-top:12px">${termsFields(e.terms ?? { days_per_week: 5, daily_minutes: 504, pay_type: 'monthly' })}</div>
      <p class="hint" style="margin-top:8px">באותו תאריך כבר יש גרסה? היא מתעדכנת, והקודמת נשמרת ביומן.</p>
      <p class="error" role="alert" hidden></p>`,
    async f => { await api('emp_terms_add', { company_id: state.companyId, id: e.id, effective_from: f.effective_from.value, ...readTerms(f) }); return 'נשמר'; }));
}

/* ===== גיליון ===== */

function openSheet(title, html, onSave, { save = true } = {}) {
  const dlg = $('#sheet');
  // כל פתיחה — טופס חדש, כדי שמאזינים מהגיליון הקודם לא יישארו
  const fresh = dlg.querySelector('form').cloneNode(true);
  dlg.querySelector('form').replaceWith(fresh);
  $('#sheet-title').textContent = title;
  $('#sheet-body').innerHTML = html;
  $('#sheet-save').hidden = !save;
  $('#sheet-save').disabled = false;   // השיבוט מעתיק גם "disabled" משמירה שפתחה את הגיליון הזה
  fresh.addEventListener('submit', async e => {
    e.preventDefault();
    if (!onSave) return;
    const btn = $('#sheet-save'); btn.disabled = true;
    try {
      const r = await onSave(fresh);
      dlg.close();
      if (typeof r === 'function') r(); else { toast(r); route(); }
    }
    catch (err) { showError(fresh, err.message); }
    finally { btn.disabled = false; }
  });
  dlg.showModal();
  dlg.querySelector('input:not([type=radio]), select')?.focus();
}

document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

boot();
