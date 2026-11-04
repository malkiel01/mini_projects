'use strict';
/*
 * עוזרים משותפים לשלושת הממשקים: ניהול (index.html), עובד (e/) ועמדה (s/). נטען לפני שאר הסקריפטים.
 * הכול ב-scope הגלובלי (סקריפטים קלאסיים) — לכן אסור להגדיר שוב את השמות האלה באף קובץ אחר.
 */

const TZ = 'Asia/Jerusalem';
const $ = (s, el = document) => el.querySelector(s);

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function toast(msg, err = false) {
  const el = $('.toast'); el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2600);
}

/** דקות ← "8:24"; שלילי ← "−0:30". */
const hm = m => (m < 0 ? '−' : '') + Math.floor(Math.abs(m) / 60) + ':' + String(Math.abs(m) % 60).padStart(2, '0');

/** קריאה ל-api.php: מחזיר את התשובה, או זורק Error עם status (ועם `fallback` כשאין הודעה מהשרת). */
async function apiCall(url, { method = 'POST', body, fallback = 'שגיאת תקשורת' } = {}) {
  const res = await fetch(url, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
    credentials: 'same-origin',
  });
  let data = null;
  try { data = await res.json(); } catch { /* תשובה שאינה JSON — למשל דף שגיאה */ }
  if (!data || !data.success) {
    const e = new Error(data?.error || fallback); e.status = res.status; throw e;
  }
  return data;
}
