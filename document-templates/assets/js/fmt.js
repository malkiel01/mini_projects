// מבנים מותאמים אישית לשדה — כמו בוורד/אקסל: תאריך (לועזי, עברי, משולב) ומספר.
// אותו מנוע בדיוק ב-lib/fmt.php (Word), עם אותם מקרי בדיקה (tests/fmt-vectors.json).
//
// תאריך: d dd · M MM MMMM (שם החודש) · yy yyyy · dddd (יום בשבוע)
//        עברי: hd (יום באותיות) · hMMMM (חודש עברי) · hyy (תשפ״ז) · hyyyy (ה׳תשפ״ז)
//        טקסט באותיות לועזיות — בגרש: 'at'. כל השאר (עברית, רווחים, סימנים) — כמו שהוא.
// מספר:  #,##0.00 · 0 · 0.0% · ₪ #,##0 · #,##0 ש״ח — פסיק = הפרדת אלפים, אפסים אחרי הנקודה = ספרות.
(function (root) {
  'use strict';

  const MONTHS = ['ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר'];
  const DAYS = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
  // חודשים עבריים (מניין מניסן = 1; תשרי = 7). בשנה מעוברת 12 = אדר א׳, 13 = אדר ב׳
  const HMONTHS = { 1: 'ניסן', 2: 'אייר', 3: 'סיוון', 4: 'תמוז', 5: 'אב', 6: 'אלול', 7: 'תשרי', 8: 'חשוון', 9: 'כסלו', 10: 'טבת', 11: 'שבט', 12: 'אדר', 13: 'אדר ב׳' };

  // ── לוח עברי (Dershowitz & Reingold) ──
  const leap = (y) => (7 * y + 1) % 19 < 7;
  function elapsed(y) {
    const m = 235 * Math.floor((y - 1) / 19) + 12 * ((y - 1) % 19) + Math.floor((7 * ((y - 1) % 19) + 1) / 19);
    const parts = 204 + 793 * (m % 1080);
    const hours = 5 + 12 * m + 793 * Math.floor(m / 1080) + Math.floor(parts / 1080);
    const day = 1 + 29 * m + Math.floor(hours / 24);
    const p = 1080 * (hours % 24) + parts % 1080;
    const alt = (p >= 19440 || (day % 7 === 2 && p >= 9924 && !leap(y)) || (day % 7 === 1 && p >= 16789 && leap(y - 1))) ? day + 1 : day;
    return [0, 3, 5].includes(alt % 7) ? alt + 1 : alt;
  }
  const yearDays = (y) => elapsed(y + 1) - elapsed(y);
  function monthDays(y, m) {
    if ([2, 4, 6, 10, 13].includes(m)) return 29;
    if (m === 12 && !leap(y)) return 29;
    if (m === 8 && yearDays(y) % 10 !== 5) return 29;
    if (m === 9 && yearDays(y) % 10 === 3) return 29;
    return 30;
  }
  // ימים מ-1/1/1970 עד ראש השנה של שנה עברית: לפי ראש השנה תשפ״ז = 12/9/2026
  const EPOCH = Math.round(Date.UTC(2026, 8, 12) / 864e5) - elapsed(5787);
  const newYear = (y) => EPOCH + elapsed(y);

  /** תאריך לועזי (yyyy-mm-dd) → { y, m, d } עברי. */
  function hebrew(iso) {
    const [Y, M, D] = iso.split('-').map(Number);
    const n = Math.round(Date.UTC(Y, M - 1, D) / 864e5);
    let y = Y + 3760;
    while (newYear(y + 1) <= n) y++;
    while (newYear(y) > n) y--;
    let left = n - newYear(y);
    const order = [7, 8, 9, 10, 11, 12, ...(leap(y) ? [13] : []), 1, 2, 3, 4, 5, 6];
    for (const m of order) {
      const len = monthDays(y, m);
      if (left < len) return { y, m, d: left + 1, leap: leap(y) };
      left -= len;
    }
    return null;
  }

  /** מספר → אותיות (גימטריה) עם גרש / גרשיים. 15 → ט״ו, 16 → ט״ז. */
  function gematria(n) {
    const L = [[400, 'ת'], [300, 'ש'], [200, 'ר'], [100, 'ק'], [90, 'צ'], [80, 'פ'], [70, 'ע'], [60, 'ס'], [50, 'נ'],
      [40, 'מ'], [30, 'ל'], [20, 'כ'], [10, 'י'], [9, 'ט'], [8, 'ח'], [7, 'ז'], [6, 'ו'], [5, 'ה'], [4, 'ד'], [3, 'ג'], [2, 'ב'], [1, 'א']];
    let s = '';
    n %= 1000;
    while (n >= 400) { s += 'ת'; n -= 400; }
    if (n === 15 || n === 16) return withMarks(s + (n === 15 ? 'טו' : 'טז'));
    if (n % 100 === 15 || n % 100 === 16) { const h = n - n % 100; s += L.find(([v]) => v === h)?.[1] || ''; return withMarks(s + (n % 100 === 15 ? 'טו' : 'טז')); }
    for (const [v, ch] of L) while (n >= v) { s += ch; n -= v; }
    return withMarks(s);
  }
  const withMarks = (s) => s.length === 1 ? s + '׳' : s.slice(0, -1) + '״' + s.slice(-1);
  const hmonth = (h) => h.m === 12 && h.leap ? 'אדר א׳' : HMONTHS[h.m];

  const TOKENS = ['hMMMM', 'hyyyy', 'hyy', 'hd', 'dddd', 'MMMM', 'yyyy', 'yy', 'dd', 'MM', 'd', 'M'];

  /** תאריך לפי מבנה. iso — yyyy-mm-dd. */
  function date(pattern, iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!m) return String(iso || '');
    const [Y, Mo, D] = [Number(m[1]), Number(m[2]), Number(m[3])];
    let h = null;
    const heb = () => (h ??= hebrew(`${m[1]}-${m[2]}-${m[3]}`));
    const val = {
      d: String(D), dd: m[3], M: String(Mo), MM: m[2], MMMM: MONTHS[Mo - 1], yy: m[1].slice(2), yyyy: m[1],
      dddd: 'יום ' + DAYS[new Date(Date.UTC(Y, Mo - 1, D)).getUTCDay()],
      hd: () => gematria(heb().d), hMMMM: () => hmonth(heb()), hyy: () => gematria(heb().y), hyyyy: () => 'ה׳' + gematria(heb().y),
    };
    let out = '';
    for (let i = 0; i < pattern.length;) {
      if (pattern[i] === "'") { const j = pattern.indexOf("'", i + 1); out += j < 0 ? pattern.slice(i + 1) : pattern.slice(i + 1, j); i = j < 0 ? pattern.length : j + 1; continue; }
      const t = TOKENS.find(t => pattern.startsWith(t, i));
      if (t) { const v = val[t]; out += typeof v === 'function' ? v() : v; i += t.length; continue; }
      out += pattern[i++];
    }
    return out;
  }

  /** מספר לפי מבנה: #,##0.00 · 0% · ₪ #,##0 */
  function number(pattern, raw) {
    const s = String(raw ?? '').trim();
    if (s === '' || !isFinite(Number(s))) return s;
    const core = /[#0,]*[#0](?:\.[#0]+)?/.exec(pattern);
    if (!core) return s;
    let n = Number(s) * (pattern.includes('%') ? 100 : 1);
    const [intPart, frac = ''] = core[0].split('.');
    const minDec = (frac.match(/0/g) || []).length, maxDec = frac.length;
    // ספרות עשרוניות: חובה עד מספר האפסים, ואחריהן — רק מה שאינו אפס בסוף
    let [i, f = ''] = Math.abs(n).toFixed(maxDec).split('.');
    f = f.slice(0, minDec) + f.slice(minDec).replace(/0+$/, '');
    const minInt = (intPart.match(/0/g) || []).length;
    i = i.padStart(minInt, '0');
    if (intPart.includes(',')) i = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (minInt === 0 && i === '0' && f) i = '';
    return (n < 0 ? '-' : '') + pattern.slice(0, core.index) + i + (f ? '.' + f : '') + pattern.slice(core.index + core[0].length);
  }

  /** האם השדה מוצג כתאריך (לבחירת סוג המבנה). */
  const isDate = (f) => f.type === 'date' || (f.type === 'calc' && f.format === 'date') || (f.type === 'system' && ['issued', 'completed'].includes(f.source));
  const isNumber = (f) => f.type === 'number' || (f.type === 'calc' && f.format !== 'date');

  /** הערך להצגה לפי המבנה של השדה, או null — אין מבנה מותאם. */
  function apply(f, raw) {
    if (!f.fmt || raw === '' || raw == null) return null;
    if (isDate(f)) return date(f.fmt, raw);
    if (isNumber(f)) return number(f.fmt, raw);
    return null;
  }

  const PRESETS = {
    date: [
      ['dd/MM/yyyy', 'רגיל'], ['dd.MM.yy', 'מקוצר'], ['d בMMMM yyyy', 'שם החודש'], ['dddd, d בMMMM yyyy', 'עם היום בשבוע'],
      ['hd בhMMMM hyy', 'עברי'], ['dd/MM/yyyy · hd בhMMMM hyy', 'לועזי ועברי'], ['d בMMMM yyyy (hd בhMMMM hyyyy)', 'משולב, מלא'],
    ],
    number: [['#,##0', 'אלפים'], ['#,##0.00', 'שתי ספרות'], ['₪ #,##0.00', 'שקלים'], ['#,##0 ש״ח', 'ש״ח בסוף'], ['0%', 'אחוזים'], ['0.0', 'ספרה אחת']],
  };

  const api = { date, number, hebrew, gematria, apply, isDate, isNumber, PRESETS };
  if (typeof module !== 'undefined') module.exports = api;
  else root.DTFmt = api;
})(typeof window !== 'undefined' ? window : globalThis);
