'use strict';
/*
 * שעון נוכחות — העובד (e/). בלי חשבון בכלים הדיגיטליים: הקישור האישי (#t=…) מפעיל את הטלפון, העובד
 * בוחר קוד בן 4 ספרות, ומכאן העוגייה tc_session מזהה אותו. האפיון: docs/v1-screens-data.md, E1–E4;
 * חופשה ומחלה — docs/v3-absences.md.
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

/* ===== E2 · בית: כניסה / יציאה ===== */

const TZ = 'Asia/Jerusalem';
const time = iso => new Date(iso).toLocaleTimeString('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
const SRC = { phone: 'טלפון', station: 'עמדה', manager: 'מנהל' };
let st = null, tick = null;

function current() {
  const saved = +(localStorage.getItem('tc-member') || 0);
  return st.memberships.find(m => m.id === saved) || st.memberships[0];
}

function home(state) {
  st = state;
  if (location.hash === '#/month') return month();
  if (location.hash === '#/abs') return absences();
  const m = current(), s = m.status, open = !!s.open_since;
  $('#sub').textContent = m.company;
  $('#month-btn').hidden = false; $('#back').hidden = true;
  main.innerHTML = `
    ${st.memberships.length > 1 ? `<fieldset class="seg" aria-label="מקום עבודה" style="margin-bottom:12px">${st.memberships.map(x =>
      `<label><input type="radio" name="mem" value="${x.id}"${x.id === m.id ? ' checked' : ''}><span>${esc(x.company)}</span></label>`).join('')}</fieldset>` : ''}
    ${(m.to_approve || []).map(mo => `<button class="summary approve-card" type="button" data-approve="${mo}">
      <div class="line"><b>📋 ${monthName(mo)} מוכן לאישורך</b></div><div class="line muted">צפייה בשעות ואישור ›</div></button>`).join('')}
    <div class="summary">
      <div class="line"><span class="state">${open ? '🟢 בעבודה מאז ' + time(s.open_since) : '⚪ לא בעבודה'}</span></div>
      <div class="line"><span class="muted">היום</span> <b id="today-min">${hm(s.minutes_today)}</b>${s.standard_today ? ` <span class="muted">מתוך תקן ${hm(s.standard_today)}</span>` : ''}</div>
    </div>
    <button class="big ${open ? 'out' : ''}" id="punch" type="button">${open ? 'יציאה' : 'כניסה'}</button>
    <div class="row-btns" style="margin-top:12px"><button class="btn" type="button" id="abs-btn">🌴 חופשה / 🤒 מחלה</button></div>
    <h2 class="sec-title">היום</h2>
    <ul class="list">${s.punches.length ? s.punches.map(p => `
      <li class="card"><span class="avatar" style="--h:${p.kind === 'in' ? 140 : 10}" aria-hidden="true">${p.kind === 'in' ? '⇠' : '⇢'}</span>
        <div class="body"><div class="l1"><span>${p.kind === 'in' ? 'כניסה' : 'יציאה'}</span><span class="t">${time(p.at)}</span></div>
          <div class="l2">${SRC[p.source] ?? ''}</div></div></li>`).join('') : '<li class="empty">עוד אין דיווחים היום.</li>'}</ul>`;
  main.querySelector('.seg')?.addEventListener('change', e => { try { localStorage.setItem('tc-member', e.target.value); } catch { /* */ } home(st); });
  $('#punch').addEventListener('click', punch);
  $('#abs-btn').addEventListener('click', () => { location.hash = '#/abs'; });
  for (const b of main.querySelectorAll('[data-approve]')) b.addEventListener('click', () => { shown = b.dataset.approve; location.hash = '#/month'; });
  // משמרת פתוחה — הדקות של היום מתעדכנות כל חצי דקה, בלי לפנות לשרת
  clearInterval(tick);
  if (open) {
    const base = s.minutes_today, t0 = Date.now();
    tick = setInterval(() => { const el = $('#today-min'); if (el) el.textContent = hm(base + Math.floor((Date.now() - t0) / 60000)); }, 30000);
  }
}

async function punch() {
  const m = current(), kind = m.status.next, btn = $('#punch');
  btn.disabled = true;
  try {
    const r = await api('emp_punch', { member_id: m.id, kind });
    m.status = r.status;
    toast((kind === 'in' ? 'כניסה נרשמה ' : 'יציאה נרשמה ') + time(r.punch.at));
    home(st);
  } catch (e) {
    toast(e.message, true);
    if (e.status === 401) return boot();
    try { home(await api('emp_state')); } catch { btn.disabled = false; }
  }
}

/* ===== E3 · החודש שלי, E5 · אישור החודש ===== */

const monthName = mo => { const [y, m] = mo.split('-').map(Number); return new Date(Date.UTC(y, m - 1, 15)).toLocaleDateString('he-IL', { month: 'long', year: 'numeric', timeZone: 'UTC' }); };

/** כרטיס מצב האישור בראש החודש: תורי — כפתור אישור; אחרת — השלבים, או "נעול". */
function periodCard(r) {
  const p = r.period;
  if (!p) return '';
  if (p.status === 'locked') return '<div class="summary"><div class="line"><b>🔒 החודש נעול</b></div><div class="line muted">לתיקון — פונים למנהל.</div></div>';
  const chain = p.steps.map((k, i) => `<span class="chip ${p.done.includes(i) ? 'ok' : ''}">${p.done.includes(i) ? '✓ ' : ''}${p.labels[k]}</span>`).join('<span aria-hidden="true">←</span>');
  if (!p.my_turn) return `<div class="summary"><div class="line"><b>החודש באישור</b></div><div class="steps">${chain}</div></div>`;
  return `<div class="summary approve-card"><div class="line"><b>📋 ${monthName(r.month)} מוכן לאישורך</b></div>
    <div class="line muted">בודקים את הימים למטה. משהו לא נכון? לוחצים על היום ומבקשים תיקון — לפני האישור.</div>
    ${r.summary.missing ? `<div class="line" style="color:var(--danger)">⚠️ ${r.summary.missing} דיווחים חסרים</div>` : ''}
    <button class="btn primary" type="button" id="approve-month">✅ אישור החודש</button></div>`;
}

let shown = null;   // "YYYY-MM"

async function month(delta = 0) {
  const m = current();
  clearInterval(tick);
  $('#month-btn').hidden = true; $('#back').hidden = false;
  if (!shown) shown = new Date().toLocaleDateString('en-CA', { timeZone: TZ }).slice(0, 7);
  if (delta) { const [y, mo] = shown.split('-').map(Number); const d = new Date(Date.UTC(y, mo - 1 + delta, 1)); shown = d.toISOString().slice(0, 7); }
  let r;
  try { r = await api('emp_month', { member_id: m.id, month: shown }); }
  catch (e) { toast(e.message, true); return; }
  let corr = [];
  try { corr = (await api('emp_corrections', { member_id: m.id })).corrections; } catch { /* בלי — רק בלי הרשימה */ }
  const recent = corr.filter(c => c.status !== 'cancelled').slice(0, 5);
  main.innerHTML = '<div id="mview">' + periodCard(r) + monthHtml(r) + (recent.length ? `<h2 class="sec-title">הבקשות שלי</h2><ul class="list">${recent.map(c => `
    <li class="card" tabindex="0" role="button" data-date="${corrDay(c)}"><div class="body"><div class="l1"><span>${corrText(c)}</span><span class="t">${dHe(c.new_at || c.old_at)}</span></div>
      <div class="l2">${c.note ? esc(c.note) : esc(c.reason)}</div></div><span class="chip ${CSTATUS[c.status][0]}">${CSTATUS[c.status][1]}</span></li>`).join('')}</ul>` : '') + '</div>';
  $('#prev').addEventListener('click', () => month(-1));
  $('#next').addEventListener('click', () => month(1));
  $('#approve-month')?.addEventListener('click', async () => {
    if (!confirm(`לאשר את ${monthName(r.month)}: ${r.summary.days} ימים, ${hm(r.summary.minutes)} שעות?`)) return;
    try {
      await api('emp_approve', { member_id: m.id, month: r.month });
      toast('החודש אושר. תודה!');
      m.to_approve = (m.to_approve || []).filter(x => x !== r.month);
      month();
    } catch (ex) { toast(ex.message, true); }
  });
  const open = e => {
    const li = e.target.closest('[data-date]'); if (!li) return;
    const d = r.days.find(x => x.date === li.dataset.date);
    if (d) dayView(d, corr); else { shown = li.dataset.date.slice(0, 7); month(); }
  };
  // על העטיפה של התצוגה הזו — main נשאר, ומאזין עליו היה מצטבר בכל החלפת חודש
  $('#mview').addEventListener('click', open);
  $('#mview').addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(e); } });
}

/* ===== E4 · יום ובקשת תיקון ===== */

function openSheet(title, html, onSave) {
  const dlg = $('#sheet');
  const fresh = dlg.querySelector('form').cloneNode(true);
  dlg.querySelector('form').replaceWith(fresh);
  $('#sheet-title').textContent = title;
  $('#sheet-body').innerHTML = html;
  $('#sheet-save').hidden = !onSave;
  $('#sheet-save').disabled = false;
  fresh.addEventListener('submit', async e => {
    e.preventDefault();
    if (!onSave) return;
    const err = fresh.querySelector('.error'), btn = $('#sheet-save');
    btn.disabled = true;
    try { await onSave(fresh); dlg.close(); }
    catch (ex) { if (err) { err.textContent = ex.message; err.hidden = false; } btn.disabled = false; }
  });
  if (!dlg.open) dlg.showModal();
}
document.addEventListener('click', e => { if (e.target.closest('[data-close]')) $('#sheet').close(); });

function dayView(d, corr) {
  const m = current();
  openSheet(dHe(d.date + 'T12:00:00Z'), `<p class="hint">שעה לא נכונה או דיווח חסר? בוחרים, כותבים סיבה, והמנהל מאשר.</p>${dayHtml(d, corr)}`, null);
  $('#sheet-body').addEventListener('click', async e => {
    const b = e.target.closest('[data-move],[data-void],[data-add],[data-cancel]'); if (!b) return;
    if (b.dataset.cancel) {
      try { await api('emp_correct_cancel', { member_id: m.id, id: +b.dataset.cancel }); $('#sheet').close(); toast('הבקשה בוטלה'); month(); }
      catch (ex) { toast(ex.message, true); }
      return;
    }
    const op = b.dataset.add !== undefined ? 'add' : b.dataset.move ? 'move' : 'void';
    const punch = d.punches.find(p => p.id === +(b.dataset.move || b.dataset.void));
    openSheet('בקשת תיקון', correctionForm(op, d, punch), async form => {
      await api('emp_correct', { member_id: m.id, ...correctionBody(op, form, punch) });
      toast('הבקשה נשלחה למנהל');
      month();
    });
  });
}

/* ===== E6 · חופשה ומחלה (גרסה 3) ===== */

async function absences() {
  const m = current();
  clearInterval(tick);
  $('#month-btn').hidden = true; $('#back').hidden = false;
  let r;
  try { r = await api('emp_absences', { member_id: m.id }); }
  catch (e) { toast(e.message, true); if (e.status === 401) boot(); return; }
  main.innerHTML = `
    <div class="toolbar"><h2 class="sec-title" style="margin:0;flex:1">חופשה ומחלה</h2><button class="btn primary" type="button" id="abs-new"><span aria-hidden="true">+</span> בקשה</button></div>
    <h3 class="sec-title" style="margin-top:0">היתרות שלי</h3>
    ${balancesHtml(r.balances)}
    <p class="hint">בוחרים סוג ותאריכים, והמנהל מאשר. יום שאושר נחשב כיום עבודה מלא בשעות התקן.</p>
    <ul class="list" id="abs-list">${r.absences.length ? r.absences.map(a => absItem(a,
      a.status === 'pending' && a.by === 'employee' ? `<button class="icon-btn" type="button" data-cancel="${a.id}" aria-label="ביטול הבקשה">✕</button>` : '')).join('')
      : '<li class="empty">אין בקשות. לחופשה או למחלה — "+ בקשה".</li>'}</ul>`;
  $('#abs-new').addEventListener('click', () => {
    openSheet('בקשת היעדרות', absenceForm(r.types, r.unit, r.today), async form => {
      await api('emp_absence_request', { member_id: m.id, ...absenceBody(form) });
      toast('הבקשה נשלחה למנהל');
      absences();
    });
    absenceWire($('#sheet form'));
  });
  $('#abs-list').addEventListener('click', async e => {
    const b = e.target.closest('[data-cancel]'); if (!b) return;
    if (!confirm('לבטל את הבקשה?')) return;
    try { await api('emp_absence_cancel', { member_id: m.id, id: +b.dataset.cancel }); toast('הבקשה בוטלה'); absences(); }
    catch (ex) { toast(ex.message, true); }
  });
}

$('#month-btn').addEventListener('click', () => { location.hash = '#/month'; });
$('#back').addEventListener('click', () => { location.hash = ''; });
window.addEventListener('hashchange', () => { if (st) home(st); else boot(); });
boot();
