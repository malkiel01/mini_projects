'use strict';
/*
 * תצוגת חודש — משותפת לעובד (e/) ולכרטיס העובד אצל המנהל. מקבלת את התשובה של monthReport.
 * ימים בלי כלום ובלי תקן (שבת, יום חופש) לא מוצגים; יום עבודה שעבר בלי דיווח — "לא דווח".
 * החיצים ‹ › נכתבים לפי המשמעות (הקודם / הבא) — בעברית הדפדפן הופך אותם לבד.
 */
function monthHtml(r) {
  const TZ = 'Asia/Jerusalem';
  const hm = m => (m < 0 ? '−' : '') + Math.floor(Math.abs(m) / 60) + ':' + String(Math.abs(m) % 60).padStart(2, '0');
  const t = iso => new Date(iso).toLocaleTimeString('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
  const [y, mo] = r.month.split('-').map(Number);
  const title = new Date(Date.UTC(y, mo - 1, 15)).toLocaleDateString('he-IL', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  const s = r.summary;
  const rows = r.days.filter(d => d.date <= r.today && (d.pairs.length || d.open || d.missing_out || d.missing_in || d.standard)).reverse();
  const dayName = d => new Date(d + 'T12:00:00Z').toLocaleDateString('he-IL', { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC' });
  return `
    <div class="toolbar month-nav">
      <button class="icon-btn" id="prev" type="button" aria-label="החודש הקודם">‹</button>
      <b class="one" style="flex:1;text-align:center">${title}</b>
      <button class="icon-btn" id="next" type="button" aria-label="החודש הבא"${r.month >= r.today.slice(0, 7) ? ' disabled' : ''}>›</button>
    </div>
    <dl class="cells" style="grid-template-columns:repeat(4,1fr)">
      <div><dt>ימים</dt><dd>${s.days}</dd></div>
      <div><dt>שעות</dt><dd>${hm(s.minutes)}</dd></div>
      <div><dt>תקן עד היום</dt><dd>${hm(s.standard)}</dd></div>
      <div><dt>הפרש</dt><dd>${hm(s.diff)}</dd></div>
    </dl>
    ${otCells(r)}
    ${s.absence ? `<p class="hint">🌴 היעדרויות מאושרות: ${hm(s.absence)} — נזקפות כשעות תקן בהפרש.</p>` : ''}
    ${s.missing ? `<p class="hint">⚠️ ${s.missing} דיווחים חסרים. לוחצים על היום כדי לתקן.</p>` : ''}
    <p class="hint">ההפרש הוא מול שעות התקן שלך; השעות הנוספות מסווגות בנפרד.</p>
    <ul class="list">${rows.length ? rows.map(d => {
      const spans = d.pairs.map(p => t(p.in) + '–' + t(p.out));
      if (d.open) spans.push(t(d.open) + '–…');
      const chips = [d.missing_out ? '<span class="chip bad">חסר יציאה</span>' : '', d.missing_in ? '<span class="chip bad">חסר כניסה</span>' : '',
        d.open ? '<span class="chip ok">בעבודה</span>' : '',
        !spans.length && !d.missing_out && !d.missing_in && d.standard && !d.absence?.credit && d.absence?.status !== 'pending' ? '<span class="chip">לא דווח</span>' : '',
        d.absence ? `<span class="chip ${d.absence.status === 'approved' ? 'ok' : 'warn'}">${d.absence.icon} ${escM(d.absence.name)}${ABS_PART[d.absence.part]}${d.absence.status === 'pending' ? ' · ממתין' : ''}</span>` : '',
        d.pending ? '<span class="chip warn">ממתין לאישור</span>' : d.corrected ? '<span class="chip">תוקן</span>' : '',
        d.ot?.holiday ? `<span class="chip ok">${escM(d.ot.holiday)}</span>` : d.ot?.rest && d.pairs.length ? '<span class="chip ok">מנוחה</span>' : ''].join('');
      return `<li class="card" tabindex="0" role="button" data-date="${d.date}"><div class="body"><div class="l1"><span>${dayName(d.date)}</span><span class="t">${d.minutes ? hm(d.minutes) : ''}</span></div>
        <div class="l2" dir="ltr" style="text-align:end">${spans.join(' · ') || '—'}</div>${otLine(d, r)}</div>${chips}</li>`;
    }).join('') : '<li class="empty">אין ימים להצגה בחודש הזה.</li>'}</ul>`;
}

/* ===== יום ותיקונים — משותף לעובד ולמנהל ===== */

const KIND = { in: 'כניסה', out: 'יציאה' };
const escM = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const tHe = iso => new Date(iso).toLocaleTimeString('he-IL', { timeZone: 'Asia/Jerusalem', hour: '2-digit', minute: '2-digit' });
const dHe = iso => new Date(iso).toLocaleDateString('he-IL', { timeZone: 'Asia/Jerusalem', weekday: 'short', day: 'numeric', month: 'numeric' });
const localDay = iso => new Date(iso).toLocaleDateString('en-CA', { timeZone: 'Asia/Jerusalem' });

/** "הוספת יציאה 16:00" · "שינוי כניסה 08:00 ← 07:30" · "ביטול יציאה 12:00" */
function corrText(c) {
  if (c.op === 'add') return `הוספת ${KIND[c.kind]} ${tHe(c.new_at)}`;
  if (c.op === 'move') return `שינוי ${KIND[c.kind]} ${tHe(c.old_at)} ← ${tHe(c.new_at)}`;
  return `ביטול ${KIND[c.kind]} ${tHe(c.old_at)}`;
}
const corrDay = c => localDay(c.new_at || c.old_at);
const CSTATUS = { pending: ['warn', 'ממתין'], approved: ['ok', 'אושר'], rejected: ['bad', 'נדחה'], cancelled: ['', 'בוטל'] };

/** הדיווחים של יום, עם ✏️ / 🗑️ לכל דיווח מקורי (דיווח שנוסף בתיקון — בלי), ו"+ הוספת דיווח". */
function dayHtml(d, corrections = []) {
  const SRC = { phone: 'טלפון', station: 'עמדה', manager: 'מנהל', correction: 'נוסף בתיקון' };
  const mine = corrections.filter(c => corrDay(c) === d.date);
  return `
    <ul class="list">${d.punches.length ? d.punches.map(p => `
      <li class="card">
        <span class="avatar" style="--h:${p.kind === 'in' ? 140 : 10}" aria-hidden="true">${p.kind === 'in' ? '⇠' : '⇢'}</span>
        <div class="body"><div class="l1"><span>${KIND[p.kind]} ${tHe(p.at)}</span></div>
          <div class="l2">${SRC[p.source] ?? ''}${p.corrected && p.source !== 'correction' ? ' · תוקן' : ''}</div></div>
        ${p.id ? `<button class="icon-btn" type="button" data-move="${p.id}" aria-label="שינוי שעה">✏️</button>
                  <button class="icon-btn" type="button" data-void="${p.id}" aria-label="ביטול הדיווח">🗑️</button>` : ''}
      </li>`).join('') : '<li class="empty">אין דיווחים ביום הזה.</li>'}</ul>
    <div class="row-btns" style="margin-top:12px"><button class="btn" type="button" data-add>+ הוספת דיווח</button></div>
    ${mine.length ? `<h3 class="sec-title">תיקונים ביום הזה</h3><ul class="list">${mine.map(c => `
      <li class="card"><div class="body"><div class="l1"><span>${corrText(c)}</span></div>
        <div class="l2">${c.reason ? 'סיבה: ' + escM(c.reason) : ''}${c.note ? ' · ' + escM(c.note) : ''}</div></div>
        <span class="chip ${CSTATUS[c.status][0]}">${CSTATUS[c.status][1]}</span>
        ${c.status === 'pending' && c.by === 'employee' ? `<button class="icon-btn" type="button" data-cancel="${c.id}" aria-label="ביטול הבקשה">✕</button>` : ''}
      </li>`).join('')}</ul>` : ''}`;
}

/** טופס התיקון: op = add | move | void. מחזיר את ה-HTML; הקריאה ל-API — אצל מי שקרא. */
function correctionForm(op, d, punch) {
  const hhmm = punch ? new Date(punch.at).toLocaleTimeString('en-GB', { timeZone: 'Asia/Jerusalem', hour: '2-digit', minute: '2-digit' }) : '';
  const what = op === 'add' ? 'הוספת דיווח' : op === 'move' ? `שינוי השעה של ה${KIND[punch.kind]} (${tHe(punch.at)})` : `ביטול ה${KIND[punch.kind]} (${tHe(punch.at)})`;
  return `
    <p><b>${what}</b> · ${dHe(d.date + 'T12:00:00Z')}</p>
    ${op === 'add' ? `<div class="f" style="margin-top:12px"><span id="ck">מה</span><fieldset class="seg" aria-labelledby="ck">
        <label><input type="radio" name="kind" value="in" checked><span>כניסה</span></label>
        <label><input type="radio" name="kind" value="out"><span>יציאה</span></label></fieldset></div>` : ''}
    ${op !== 'void' ? `<div class="fields two" style="margin-top:12px">
        <label class="f"><span>תאריך</span><input name="date" type="date" value="${d.date}"></label>
        <label class="f"><span>שעה</span><input name="time" type="time" value="${hhmm}" required></label></div>` : ''}
    <label class="f" style="margin-top:12px"><span>סיבה *</span><input name="reason" maxlength="200" required placeholder="למשל: שכחתי לצאת"></label>
    <p class="error" role="alert" hidden></p>`;
}

function correctionBody(op, form, punch) {
  const reason = form.reason.value.trim();
  if (!reason) throw new Error('חסרה סיבה');
  const body = { op, reason };
  if (punch) body.punch_id = punch.id;
  if (op === 'add') body.kind = form.kind.value;
  if (op !== 'void') {
    if (!form.time.value) throw new Error('חסרה שעה');
    body.at = form.date.value + 'T' + form.time.value;
  }
  return body;
}

/* ===== הסיווג (גרסה 2) ===== */

/** סיכום הסלים של החודש: רגילות · נוספות · מנוחה/חג. */
function otCells(r) {
  const o = r.summary.ot, q = r.ot_rules;
  if (!o) return '';
  const hm = m => Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');
  const rest = o.rest + o.rest_ot1 + o.rest_ot2 + o.holiday_rest;
  return `<dl class="cells" style="grid-template-columns:repeat(4,1fr)">
      <div><dt>רגילות</dt><dd>${hm(o.reg)}</dd></div>
      <div><dt>${q.rate_ot1}%</dt><dd>${hm(o.ot1)}</dd></div>
      <div><dt>${q.rate_ot2}%</dt><dd>${hm(o.ot2)}</dd></div>
      <div><dt>מנוחה/חג</dt><dd>${hm(rest)}</dd></div></dl>
    ${rest ? `<p class="hint">במנוחה/חג: ${q.rate_rest}% ${hm(o.rest)}${o.rest_ot1 ? ` · ${q.rate_rest_ot1}% ${hm(o.rest_ot1)}` : ''}${o.rest_ot2 ? ` · ${q.rate_rest_ot2}% ${hm(o.rest_ot2)}` : ''}${o.holiday_rest ? ` · חג במנוחה ${q.rate_holiday_rest}% ${hm(o.holiday_rest)}` : ''}</p>` : ''}
    <p class="hint">הסיווג לפי כללי החברה. סכומים — בשכר.</p>`;
}

/** שורה קטנה ליום שיש בו שעות נוספות או מנוחה: "125% 1:20 · 150% 0:24". */
function otLine(d, r) {
  const b = d.ot?.b, q = r.ot_rules;
  if (!b) return '';
  const hm = m => Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');
  const parts = [['ot1', q.rate_ot1 + '%'], ['ot2', q.rate_ot2 + '%'], ['rest', q.rate_rest + '%'], ['rest_ot1', q.rate_rest_ot1 + '%'],
                 ['rest_ot2', q.rate_rest_ot2 + '%'], ['holiday_rest', 'חג ' + q.rate_holiday_rest + '%']].filter(([k]) => b[k]).map(([k, l]) => `${l} ${hm(b[k])}`);
  if (d.ot.night) parts.unshift('🌙 לילה');
  return parts.length ? `<div class="l2" style="font-size:.78rem">${parts.join(' · ')}</div>` : '';
}

/* ===== היעדרויות (גרסה 3) — משותף לעובד ולמנהל ===== */

const ABS_PART = { full: '', half: ' ½', hours: ' (שעות)' };
const ASTATUS = { pending: ['warn', 'ממתין'], approved: ['ok', 'אושר'], rejected: ['bad', 'נדחה'], cancelled: ['', 'בוטל'] };
const dShort = d => new Date(d + 'T12:00:00Z').toLocaleDateString('he-IL', { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC' });
const hmA = m => Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');

/** "7.2–10.2 · 5 ימי עבודה" · "ב׳ 14.2 · חצי יום" · "ג׳ 15.2 · 2:00 שעות" */
function absText(a) {
  const dm = d => +d.slice(8) + '.' + +d.slice(5, 7);
  const range = a.from === a.to ? dShort(a.from) : `${dm(a.from)}–${dm(a.to)}`;
  const part = a.part === 'half' ? 'חצי יום' : a.part === 'hours' ? `${hmA(a.minutes)} שעות ביום` : '';
  const days = a.from !== a.to || a.part === 'full' ? `${a.workdays} ${a.workdays === 1 ? 'יום עבודה' : 'ימי עבודה'}` : '';
  return [range, part, days].filter(Boolean).join(' · ');
}

const fmtD = n => (Math.round(n * 100) / 100).toLocaleString('he-IL', { maximumFractionDigits: 2 });

/** היתרות: חופשה ומחלה, בימים. מתוכננות — חופשה שאושרה וטרם הגיעה. */
function balancesHtml(bs) {
  return `<dl class="cells" style="grid-template-columns:repeat(2,1fr)">${bs.map(b => `
      <div><dt>${b.kind === 'sick' ? '🤒' : '🌴'} יתרת ${b.name}</dt><dd${b.balance < 0 ? ' style="color:var(--danger)"' : ''}>${fmtD(b.balance)}</dd></div>`).join('')}</dl>
    ${bs.some(b => b.planned) ? `<p class="hint">מתוכננות (אושרו, עוד לא הגיעו): ${bs.filter(b => b.planned).map(b => `${b.name} ${fmtD(b.planned)}`).join(' · ')}</p>` : ''}
    <p class="hint">בימי עבודה. ${bs.some(b => b.as_of) ? 'נצבר בסוף כל חודש עבודה מלא.' : 'עוד לא הוזנו יתרה ותאריך התחלה — אין ממה לחשב.'}</p>`;
}

/** שורה ברשימה. $actions — הכפתורים בסוף (לפי מי שמציג). */
function absItem(a, actions = '', who = false) {
  return `<li class="card" data-abs="${a.id}">
    <span class="avatar" style="--h:${a.type === 'sick' ? 10 : a.type === 'vacation' ? 140 : 220}" aria-hidden="true">${a.icon}</span>
    <div class="body"><div class="l1"><span>${who ? escM(a.name) + ' · ' : ''}${escM(a.type_name)}</span></div>
      <div class="l2">${absText(a)}</div>
      ${a.balance ? `<div class="l2">יתרת ${a.balance.name}: ${fmtD(a.balance.now)} ← <b${a.balance.after < 0 ? ' style="color:var(--danger)"' : ''}>${fmtD(a.balance.after)}</b></div>` : ''}
      ${a.note || a.decision_note ? `<div class="l2">${a.note ? escM(a.note) : ''}${a.note && a.decision_note ? ' · ' : ''}${a.decision_note ? '↩ ' + escM(a.decision_note) : ''}</div>` : ''}</div>
    ${who ? '' : `<span class="chip ${ASTATUS[a.status][0]}">${ASTATUS[a.status][1]}</span>`}${actions}</li>`;
}

/** טופס היעדרות: הסוג, מתאריך עד תאריך, והמשך לפי היחידה של החברה (day | half | hours). */
function absenceForm(types, unit, today) {
  const parts = unit === 'half' ? [['full', 'יום מלא'], ['half', 'חצי יום']] : unit === 'hours' ? [['full', 'יום מלא'], ['hours', 'שעות']] : [];
  return `
    <div class="f"><span id="at">סוג</span><fieldset class="seg grid" style="--cols:${Math.min(types.length, 3)}" aria-labelledby="at">${types.map((t, i) => `
      <label><input type="radio" name="type" value="${t.key}"${i ? '' : ' checked'}><span>${t.icon} ${escM(t.name)}</span></label>`).join('')}</fieldset></div>
    <div class="fields two" style="margin-top:12px">
      <label class="f"><span>מתאריך</span><input name="from" type="date" value="${today}" required></label>
      <label class="f"><span>עד תאריך</span><input name="to" type="date" value="${today}" required></label></div>
    ${parts.length ? `<div class="f" style="margin-top:12px"><span id="ap">משך בכל יום</span><fieldset class="seg" aria-labelledby="ap">${parts.map(([v, l], i) => `
      <label><input type="radio" name="part" value="${v}"${i ? '' : ' checked'}><span>${l}</span></label>`).join('')}</fieldset></div>` : ''}
    ${unit === 'hours' ? `<div class="fields two" id="hours-f" style="margin-top:12px" hidden><label class="f"><span>כמה שעות ביום</span><input name="hours" dir="ltr" inputmode="numeric" placeholder="2:00"></label></div>` : ''}
    <label class="f" style="margin-top:12px"><span>הערה</span><input name="note" maxlength="200" placeholder="לא חובה"></label>
    <p class="hint" id="sick-hint" hidden>🤒 על מחלה מדווחים ביום עצמו או אחריו. את האישור הרפואי מעבירים למנהל.</p>
    <p class="hint">רק ימי עבודה נספרים — לא יום מנוחה ולא חג.</p>
    <p class="error" role="alert" hidden></p>`;
}

/** אחרי שהטופס בדף: "עד" לא לפני "מ", שדה השעות, וההערה למחלה. */
function absenceWire(form) {
  form.from.addEventListener('change', () => { if (form.to.value < form.from.value) form.to.value = form.from.value; });
  const sync = () => {
    if (form.querySelector('#hours-f')) form.querySelector('#hours-f').hidden = form.part?.value !== 'hours';
    form.querySelector('#sick-hint').hidden = form.type.value !== 'sick';
  };
  form.addEventListener('change', sync);
  sync();
}

function absenceBody(form) {
  const body = { type: form.type.value, from: form.from.value, to: form.to.value || form.from.value, part: form.part?.value || 'full', note: form.note.value.trim() };
  if (!body.from) throw new Error('מאיזה תאריך?');
  if (body.part === 'hours') {
    const m = /^(\d{1,2})(?::(\d{2}))?$/.exec(form.hours.value.trim());
    if (!m) throw new Error('כמה שעות? למשל 2:00');
    body.minutes = +m[1] * 60 + +(m[2] || 0);
  }
  return body;
}
