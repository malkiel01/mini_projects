'use strict';
/*
 * שעון נוכחות — העובד (e/). בלי חשבון בכלים הדיגיטליים: הקישור האישי (#t=…) מפעיל את הטלפון, העובד
 * בוחר קוד בן 4 ספרות, ומכאן העוגייה tc_session מזהה אותו. האפיון: docs/v1-screens-data.md, E1–E2.
 * הקישור ב-# — כדי שלא יגיע ליומני השרת; אחרי ההפעלה הוא נמחק מהכתובת.
 */

const $ = s => document.querySelector(s);
const main = $('#main');

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function toast(msg, err = false) {
  const el = $('.toast'); el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}
const hm = m => Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');

async function api(action, body = {}) {
  const res = await fetch('../api.php?action=' + action, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin',
  });
  let data = null;
  try { data = await res.json(); } catch { /* */ }
  if (!data || !data.success) { const e = new Error(data?.error || 'שגיאת תקשורת'); e.status = res.status; throw e; }
  return data;
}

function message(icon, title, text) {
  main.innerHTML = `<div class="gate"><div class="logo" aria-hidden="true">${icon}</div><h2>${esc(title)}</h2><p class="hint">${esc(text)}</p></div>`;
}

async function boot() {
  const token = new URLSearchParams(location.hash.slice(1)).get('t');
  if (token) return activation(token);
  try { home(await api('emp_state')); }
  catch (e) { message('🔗', 'צריך קישור מהמנהל', e.status === 401 ? 'פתחו את הקישור האישי שהמנהל שלח לכם בוואטסאפ.' : e.message); }
}

/* ===== E1 · הפעלה ===== */

async function activation(token) {
  let info;
  try { info = await api('emp_invite_info', { token }); }
  catch (e) { history.replaceState(null, '', location.pathname); return message('⌛', 'הקישור לא פעיל', e.message); }
  $('#sub').textContent = info.company;
  main.innerHTML = `
    <div class="welcome"><div class="logo" aria-hidden="true">🕒</div>
      <h2>שלום ${esc(info.name)}</h2>
      <p class="hint">זה שעון הנוכחות של ${esc(info.company)}. בוחרים קוד אישי בן 4 ספרות — הוא משמש גם בעמדה בעסק.</p></div>
    <form class="form" id="act" novalidate>
      <div class="fields two">
        <label class="f"><span>קוד אישי</span><input name="pin" type="password" inputmode="numeric" autocomplete="new-password" maxlength="4" pattern="\\d{4}" dir="ltr" required></label>
        <label class="f"><span>שוב, לאימות</span><input name="pin2" type="password" inputmode="numeric" autocomplete="new-password" maxlength="4" dir="ltr" required></label>
      </div>
      <p class="hint">לא 1234 ולא ארבע ספרות זהות.</p>
      <p class="error" role="alert" hidden></p>
      <div class="actions"><button class="btn primary" type="submit">הפעלה</button></div>
    </form>`;
  const form = $('#act'), err = form.querySelector('.error');
  form.pin.focus();
  form.addEventListener('submit', async e => {
    e.preventDefault();
    err.hidden = true;
    const pin = form.pin.value.trim();
    if (!/^\d{4}$/.test(pin)) { err.textContent = 'הקוד — 4 ספרות'; err.hidden = false; return; }
    if (pin !== form.pin2.value.trim()) { err.textContent = 'שני הקודים אינם זהים'; err.hidden = false; return; }
    const btn = form.querySelector('button'); btn.disabled = true;
    try {
      const st = await api('emp_activate', { token, pin });
      history.replaceState(null, '', location.pathname);
      toast('הטלפון מחובר');
      home(st);
    } catch (ex) { err.textContent = ex.message; err.hidden = false; btn.disabled = false; }
  });
}

/* ===== E2 · בית (הדיווח עצמו — בשלב הבא) ===== */

function home(st) {
  const ms = st.memberships;
  $('#sub').textContent = ms.length === 1 ? ms[0].company : ms.length + ' מקומות עבודה';
  main.innerHTML = `
    <div class="summary"><div class="line"><b>✅ הטלפון מחובר</b></div>
      <div class="line muted">כניסה ויציאה מהטלפון יתאפשרו כאן בקרוב.</div></div>
    <h2 class="sec-title">מקומות העבודה שלי</h2>
    <ul class="list">${ms.map(m => `
      <li class="card">
        <span class="avatar" aria-hidden="true">🏢</span>
        <div class="body"><div class="l1"><span>${esc(m.company)}</span>${m.employee_no ? `<span class="t">מס׳ ${esc(m.employee_no)}</span>` : ''}</div>
          <div class="l2">${esc(m.name)}${m.site ? ' · ' + esc(m.site) : ''}${m.terms ? ` · תקן ${hm(m.terms.daily_minutes)} ליום` : ''}</div></div>
      </li>`).join('')}</ul>`;
}

window.addEventListener('hashchange', boot);
boot();
