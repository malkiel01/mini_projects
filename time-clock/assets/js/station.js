'use strict';
/*
 * עמדת נוכחות (s/) — טאבלט משותף בעסק. האפיון: docs/v1-screens-data.md, S1–S2.
 * הצמדה בקוד מהמנהל ← רשימת עובדי הסניף ← קוד אישי ← כניסה / יציאה ← חזרה לרשימה.
 * בעמדה לא מוצג שום מידע אישי מלבד השם. אחרי חוסר פעילות — חוזרים לרשימה ושוכחים את הקוד.
 */

const $ = s => document.querySelector(s);
const main = $('#main');
const TZ = 'Asia/Jerusalem';
const IDLE_MS = 20000, DONE_MS = 2500;
let data = null, idle = null;

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function toast(msg, err = false) {
  const el = $('.toast'); el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}
const time = (d = new Date()) => d.toLocaleTimeString('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
const dateLong = () => new Date().toLocaleDateString('he-IL', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long' });

async function api(action, body = {}) {
  const res = await fetch('../api.php?action=' + action, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin',
  });
  let d = null;
  try { d = await res.json(); } catch { /* */ }
  if (!d || !d.success) { const e = new Error(d?.error || 'אין חיבור לשרת'); e.status = res.status; throw e; }
  return d;
}

/** חזרה לרשימה אחרי חוסר פעילות — כדי שהקוד והשם לא יישארו על המסך. */
function armIdle(ms = IDLE_MS) { clearTimeout(idle); idle = setTimeout(list, ms); }

async function boot() {
  try { data = await api('station_state'); list(); }
  catch (e) { if (e.status === 401) pairScreen(); else message('⚠️', e.message); }
}

function message(icon, text) {
  main.innerHTML = `<div class="gate"><div class="logo" aria-hidden="true">${icon}</div><p class="hint">${esc(text)}</p>
    <button class="btn" type="button" id="retry">נסו שוב</button></div>`;
  $('#retry').addEventListener('click', boot);
}

/* ===== S1 · הצמדה ===== */

function pairScreen() {
  $('#title').textContent = 'עמדת נוכחות'; $('#sub').textContent = 'המכשיר עוד לא מוצמד';
  main.innerHTML = `
    <div class="welcome"><div class="logo" aria-hidden="true">🕒</div><h2>הצמדת העמדה</h2>
      <p class="hint">המנהל יוצר עמדה ב"הגדרות ← עמדות" ומקבל קוד בן 6 ספרות. מקלידים אותו כאן.</p></div>
    <form class="form" id="pair" novalidate>
      <label class="f"><span>קוד הצמדה</span><input name="code" inputmode="numeric" maxlength="6" dir="ltr" autocomplete="one-time-code" class="code-input" required></label>
      <p class="error" role="alert" hidden></p>
      <div class="actions"><button class="btn primary" type="submit">הצמדה</button></div>
    </form>`;
  const form = $('#pair'), err = form.querySelector('.error');
  form.code.focus();
  form.addEventListener('submit', async e => {
    e.preventDefault(); err.hidden = true;
    try { data = await api('station_pair', { code: form.code.value }); toast('העמדה מוצמדת'); list(); }
    catch (ex) { err.textContent = ex.message; err.hidden = false; }
  });
}

/* ===== S2 · רשימה ← קוד ← כניסה / יציאה ===== */

function header() {
  $('#title').textContent = data.station.company;
  $('#sub').textContent = [data.station.site, data.station.name].filter(Boolean).join(' · ');
}

async function list() {
  clearTimeout(idle);
  try { data = await api('station_state'); }
  catch (e) { if (e.status === 401) return pairScreen(); /* בלי רשת — ממשיכים עם הרשימה הקודמת */ }
  if (!data) return;
  header();
  main.innerHTML = `
    <div class="clock" id="clock">${time()}</div>
    <p class="center muted" style="margin-bottom:16px">${dateLong()}</p>
    <input type="search" id="q" placeholder="חיפוש שם" aria-label="חיפוש שם" style="inline-size:100%;margin-bottom:12px">
    <div class="names" id="names"></div>`;
  const draw = q => {
    const rows = data.employees.filter(e => !q || e.name.includes(q));
    $('#names').innerHTML = rows.length ? rows.map(e =>
      `<button class="tile" type="button" data-id="${e.id}"${e.has_pin ? '' : ' aria-disabled="true"'}>${esc(e.name)}</button>`).join('')
      : `<p class="empty">${q ? 'לא נמצא.' : 'אין עובדים בעמדה הזו.'}</p>`;
  };
  draw('');
  $('#q').addEventListener('input', e => { draw(e.target.value.trim()); armIdle(); });
  $('#names').addEventListener('click', e => {
    const b = e.target.closest('[data-id]'); if (!b) return;
    const emp = data.employees.find(x => x.id === +b.dataset.id);
    if (!emp.has_pin) return toast('עוד אין לך קוד. פתחו קודם את הקישור מהמנהל.', true);
    pinScreen(emp);
  });
}

function pinScreen(emp) {
  let pin = '';
  armIdle();
  main.innerHTML = `
    <div class="center" style="padding-top:8px"><h2>${esc(emp.name)}</h2><p class="hint">הקוד האישי</p></div>
    <div class="dots" dir="ltr" aria-live="polite" id="dots"></div>
    <p class="error center" role="alert" hidden id="perr"></p>
    <div class="pad" dir="ltr">
      ${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(n => `<button type="button" data-k="${n}">${n}</button>`).join('')}
      <button type="button" data-k="back" aria-label="מחיקה">⌫</button><button type="button" data-k="0">0</button>
      <button type="button" data-k="cancel" aria-label="ביטול">✕</button>
    </div>`;
  const dots = () => { $('#dots').innerHTML = [0, 1, 2, 3].map(i => `<i class="${i < pin.length ? 'on' : ''}"></i>`).join(''); };
  dots();
  main.querySelector('.pad').addEventListener('click', async e => {
    const k = e.target.closest('[data-k]')?.dataset.k; if (!k) return;
    armIdle();
    if (k === 'cancel') return list();
    if (k === 'back') { pin = pin.slice(0, -1); return dots(); }
    if (pin.length >= 4) return;
    pin += k; dots();
    if (pin.length < 4) return;
    try { chooseScreen(emp, pin, await api('station_check', { member_id: emp.id, pin })); }
    catch (ex) { pin = ''; dots(); const p = $('#perr'); p.textContent = ex.message; p.hidden = false; }
  });
}

function chooseScreen(emp, pin, r) {
  armIdle(15000);
  const btn = (kind, main_) => `<button class="big ${kind === 'out' ? 'out' : ''}${main_ ? '' : ' ghost-big'}" type="button" data-kind="${kind}">${kind === 'in' ? 'כניסה' : 'יציאה'}</button>`;
  main.innerHTML = `
    <div class="center" style="padding-top:8px"><h2>שלום ${esc(r.name)}</h2>
      <p class="hint">${r.open_since ? 'בעבודה מאז ' + time(new Date(r.open_since)) : 'לא בעבודה כרגע'}</p></div>
    <div id="kinds">${btn(r.next, true)}${btn(r.next === 'in' ? 'out' : 'in', false)}</div>
    <div class="row-btns"><button class="btn" type="button" id="cancel">ביטול</button></div>`;
  $('#cancel').addEventListener('click', list);
  $('#kinds').addEventListener('click', async e => {
    const b = e.target.closest('[data-kind]'); if (!b || b.disabled) return;
    for (const x of main.querySelectorAll('[data-kind]')) x.disabled = true;
    try {
      const p = await api('station_punch', { member_id: emp.id, pin, kind: b.dataset.kind });
      done(p);
    } catch (ex) { toast(ex.message, true); list(); }
  });
}

function done(p) {
  clearTimeout(idle);
  main.innerHTML = `<div class="gate"><div class="logo" aria-hidden="true">✅</div>
    <h2>${p.punch.kind === 'in' ? 'כניסה נרשמה' : 'יציאה נרשמה'} ${time(new Date(p.punch.at))}</h2><p class="hint">${esc(p.name)}</p></div>`;
  setTimeout(list, DONE_MS);
}

setInterval(() => { const c = $('#clock'); if (c) c.textContent = time(); }, 10000);
setInterval(() => { if ($('#clock')) list(); }, 5 * 60000);   // רשימה מעודכנת (עובד חדש, עובד שסיים)
boot();
