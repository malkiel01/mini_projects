'use strict';
/*
 * שעון נוכחות — ממשק הניהול (שלב 1: פתיחת חברה, פרטים, סניפים וצוות).
 * מסכים לפי ה-hash: #/ (בית) · #/settings. האפיון: docs/v1-screens-data.md, M1 ו-M7.
 */

const ROLE = { owner: 'בעלים', manager: 'מנהל', branch_manager: 'מנהל סניף', payroll: 'מנהלת שכר', employee: 'עובד' };
const $ = (s, el = document) => el.querySelector(s);
const main = $('#main');
const state = { me: null, companies: [], companyId: null, data: null, tab: 'details' };

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
  if (path === '/settings') renderSettings(); else renderHome();
  main.focus({ preventScroll: true });
}
window.addEventListener('hashchange', route);

function setHeader(title, { back = false, settings = false } = {}) {
  $('#title').textContent = title;
  $('#back').hidden = !back;
  $('#settings-btn').hidden = !settings;
  const co = state.companies.find(c => c.id === state.companyId);
  $('#co-name').textContent = co ? co.name + ' · ' + ROLE[co.role] : (state.me?.name ?? '');
  $('#co-caret').hidden = !co;
  $('#co-btn').disabled = !co;
}

$('#back').addEventListener('click', () => { location.hash = '#/'; });
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

/* ===== M2 · בית (בשלב זה — רק הכניסה להגדרות) ===== */

function renderHome() {
  const d = state.data, c = d.company;
  setHeader('היום', { settings: true });
  const missing = [];
  if (!d.sites.length) missing.push('סניפים');
  if (d.team.length < 2) missing.push('צוות');
  main.innerHTML = `
    <p class="empty">עוד אין עובדים. הוספת עובדים ודיווח נוכחות יגיעו בשלב הבא.</p>
    <h2 class="sec-title">החברה</h2>
    <ul class="list"><li><button class="use-row" type="button" id="go-settings">
      <span class="use-ic" aria-hidden="true">⚙️</span>
      <span class="use-tx"><b>${esc(c.name)}</b><small>תקן ${c.days_per_week} ימים · ${hm(c.daily_minutes)} ליום · ${d.sites.length} סניפים · ${d.team.length} בצוות</small></span>
      <span class="use-go" aria-hidden="true">‹</span></button></li></ul>
    ${missing.length && d.my_role === 'owner' ? `<p class="hint" style="margin-top:8px">חסר: ${missing.join(', ')}.</p>` : ''}`;
  $('#go-settings').addEventListener('click', () => { location.hash = '#/settings'; });
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
    </div><div id="pane"></div>`;
  main.querySelector('.seg-tabs').addEventListener('click', e => {
    const b = e.target.closest('[data-tab]'); if (!b) return;
    try { sessionStorage.setItem('tc-tab', b.dataset.tab); } catch { /* */ }
    renderSettings();
  });
  ({ details: paneDetails, sites: paneSites, team: paneTeam })[tab]($('#pane'));
}

const isOwner = () => state.data.my_role === 'owner';

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
        <div class="body"><div class="l1"><span>${esc(s.name)}</span></div><div class="l2">${s.members} בצוות הסניף</div></div>
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

/* ===== גיליון ===== */

function openSheet(title, html, onSave, { save = true } = {}) {
  const dlg = $('#sheet');
  // כל פתיחה — טופס חדש, כדי שמאזינים מהגיליון הקודם לא יישארו
  const fresh = dlg.querySelector('form').cloneNode(true);
  dlg.querySelector('form').replaceWith(fresh);
  $('#sheet-title').textContent = title;
  $('#sheet-body').innerHTML = html;
  $('#sheet-save').hidden = !save;
  fresh.addEventListener('submit', async e => {
    e.preventDefault();
    if (!onSave) return;
    const btn = $('#sheet-save'); btn.disabled = true;
    try { const msg = await onSave(fresh); dlg.close(); toast(msg); renderSettings(); }
    catch (err) { showError(fresh, err.message); }
    finally { btn.disabled = false; }
  });
  dlg.showModal();
  dlg.querySelector('input:not([type=radio]), select')?.focus();
}

document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

boot();
