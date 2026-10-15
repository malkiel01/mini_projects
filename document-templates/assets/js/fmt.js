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

  // ── מספר (כמו "עיצוב תאים" באקסל) ──
  // חלקים מופרדים ב-; — חיובי;שלילי;אפס. צבע בסוגריים בתחילת חלק: [אדום] [כחול] [ירוק] [שחור] (או באנגלית).
  // טקסט קבוע: במירכאות "..." או אחרי \. מדעי: 0.00E+00. שבר: # ?/? · # ??/?? · # ?/4. מסכה: 000-0000000.
  const COLORS = { 'אדום': '#c00000', red: '#c00000', 'כחול': '#0000ff', blue: '#0000ff', 'ירוק': '#008000', green: '#008000',
    'שחור': '#000000', black: '#000000', 'צהוב': '#b8860b', yellow: '#b8860b', 'סגול': '#800080', magenta: '#800080' };

  /** מפצל ל-; מחוץ למירכאות ולסוגריים. */
  function sections(p) {
    const out = [''];
    let q = false, b = false;
    for (let i = 0; i < p.length; i++) {
      const c = p[i];
      if (c === '\\' && !q) { out[out.length - 1] += c + (p[i + 1] ?? ''); i++; continue; }
      if (c === '"') q = !q;
      else if (!q && c === '[') b = true;
      else if (!q && c === ']') b = false;
      if (c === ';' && !q && !b) { out.push(''); continue; }
      out[out.length - 1] += c;
    }
    return out;
  }
  /** [צבע] בתחילת החלק → { sec בלי התגיות, color }. תגית לא מוכרת — נזרקת. */
  function colorTag(sec) {
    let color = null;
    sec = sec.replace(/\[([^\]]*)\]/g, (_, t) => { const c = COLORS[t.trim().toLowerCase()]; if (c) color = c; return ''; });
    return { sec, color };
  }
  /** "מסכה": תווי תבנית במקומם, וטקסט קבוע (מירכאות, \x) מוחלף ב-\u0001 — כדי שלא ייחשב כספרה. */
  function masked(sec) {
    let m = '', lit = [];
    for (let i = 0; i < sec.length; i++) {
      const c = sec[i];
      if (c === '"') { const j = sec.indexOf('"', i + 1); const t = j < 0 ? sec.slice(i + 1) : sec.slice(i + 1, j);
        for (const ch of t) { m += '\u0001'; lit.push(ch); } i = j < 0 ? sec.length : j; continue; }
      if (c === '\\') { m += '\u0001'; lit.push(sec[i + 1] ?? ''); i++; continue; }
      m += c; lit.push(c);
    }
    return { m, lit };
  }
  const litText = (mk, from, to) => mk.lit.slice(from, to).join('');

  /** מספר רגיל לפי ליבה (#,##0.00): בלי סימן. */
  function plain(core, n) {
    const [intPart, frac = ''] = core.split('.');
    const minDec = (frac.match(/0/g) || []).length, maxDec = frac.length;
    let [i, f = ''] = Math.abs(n).toFixed(maxDec).split('.');
    f = f.slice(0, minDec) + f.slice(minDec).replace(/0+$/, '');
    const minInt = (intPart.match(/0/g) || []).length;
    i = i.padStart(minInt, '0');
    if (intPart.includes(',')) i = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (minInt === 0 && i === '0' && f) i = '';
    return i + (f ? '.' + f : '');
  }
  /** שבר קרוב ביותר עם מכנה עד max (או מכנה קבוע). */
  function fraction(x, maxDen, fixed) {
    if (fixed) return [Math.round(x * fixed), fixed];
    let best = [0, 1], err = Infinity;
    for (let d = 1; d <= maxDen; d++) { const nn = Math.round(x * d), e = Math.abs(x - nn / d); if (e < err - 1e-12) { err = e; best = [nn, d]; } }
    return best;
  }
  /** מסכה של ספרות: 000-0000000 — הספרות של הערך ממלאות מימין; עודף — בתחילת המסכה. */
  function digitMask(mk, raw) {
    const ph = [...mk.m].filter(c => c === '0').length;
    let dg = String(raw).replace(/\D/g, '');
    if (dg.length < ph) dg = dg.padStart(ph, '0');
    let out = '', k = dg.length;
    for (let i = mk.m.length - 1; i >= 0; i--) out = (mk.m[i] === '0' ? dg[--k] : mk.lit[i]) + out;
    return dg.slice(0, k) + out;
  }
  const isMask = (m) => /^[0\-\s()\/\u0001]+$/.test(m) && /0[^0]+0/.test(m);

  /** חלק אחד של מבנה מספר. sign — להוסיף מינוס (כשאין חלק נפרד לשלילי). */
  function numSection(sec, n, sign) {
    const mk = masked(sec);
    const m = mk.m;
    const minus = sign && n < 0 ? '-' : '';
    if (isMask(m)) return minus + digitMask(mk, Math.abs(n));
    let r = /([#0]+)(?:\.([#0]+))?E([+-])(0+)/.exec(m);
    if (r) { // מדעי
      const a = Math.abs(n), dec = (r[2] || '').length;
      let e = a === 0 ? 0 : Math.floor(Math.log10(a));
      let mant = a === 0 ? 0 : a / Math.pow(10, e);
      if (Number(mant.toFixed(dec)) >= 10) { e += 1; mant /= 10; }   // 9.999 → 1.00E+01
      const body = mant.toFixed(dec) + 'E' + (e < 0 ? '-' : r[3] === '+' ? '+' : '') + String(Math.abs(e)).padStart(r[4].length, '0');
      return minus + litText(mk, 0, r.index) + body + litText(mk, r.index + r[0].length);
    }
    r = /(?:([#0]+)\s+)?(\?+)\/(\?+|\d+)/.exec(m);
    if (r) { // שבר
      const a = Math.abs(n);
      const fixed = /^\d+$/.test(r[3]) ? Number(r[3]) : 0;
      const whole = r[1] ? Math.floor(a) : 0;
      let [nu, de] = fraction(a - whole, Math.pow(10, r[3].length) - 1, fixed);
      let w = whole;
      if (nu === de) { w += 1; nu = 0; }
      const fr = nu ? `${nu}/${de}` : '';
      const body = r[1] ? (w || !fr ? String(w) : '') + (w && fr ? ' ' : '') + fr : (fr || '0');
      return minus + litText(mk, 0, r.index) + body + litText(mk, r.index + r[0].length);
    }
    r = /[#0,]*[#0](?:\.[#0]+)?/.exec(m);
    if (!r) return litText(mk, 0); // רק טקסט קבוע (למשל אפס כ"-")
    const pct = m.includes('%') ? 100 : 1;
    return minus + litText(mk, 0, r.index) + plain(r[0], n * pct) + litText(mk, r.index + r[0].length);
  }

  /** מספר לפי מבנה → { text, color }. */
  function numberRich(pattern, raw) {
    const s = String(raw ?? '').trim();
    if (s === '' || !isFinite(Number(s))) return { text: s, color: null };
    const n = Number(s);
    const secs = sections(pattern);
    let sec = secs[0], sign = true;
    if (n < 0 && secs.length >= 2) { sec = secs[1]; sign = false; }
    else if (n === 0 && secs.length >= 3) { sec = secs[2]; }
    const { sec: body, color } = colorTag(sec);
    return { text: numSection(body, sec === secs[1] ? Math.abs(n) : n, sign), color };
  }
  const number = (pattern, raw) => numberRich(pattern, raw).text;

  /** טקסט לפי מבנה: @ — הערך עצמו, עם טקסט קבוע לפניו/אחריו; בלי @ ועם 0 — מסכה של ספרות (טלפון, ת"ז). */
  function textRich(pattern, raw) {
    const v = String(raw ?? '');
    const { sec, color } = colorTag(sections(pattern)[sections(pattern).length >= 4 ? 3 : 0]);
    const mk = masked(sec);
    if (mk.m.includes('@')) return { text: [...mk.m].map((c, i) => c === '@' ? v : mk.lit[i]).join(''), color };
    if (mk.m.includes('0') && /\d/.test(v)) return { text: digitMask(mk, v), color };
    return { text: v, color };
  }

  /** האם השדה מוצג כתאריך (לבחירת סוג המבנה). */
  const isDate = (f) => f.type === 'date' || (f.type === 'calc' && f.format === 'date') || (f.type === 'system' && ['issued', 'completed'].includes(f.source));
  const isNumber = (f) => f.type === 'number' || (f.type === 'calc' && f.format !== 'date');

  const isText = (f) => f.type === 'text';

  /** הערך להצגה לפי המבנה של השדה → { text, color }, או null — אין מבנה. */
  function rich(f, raw) {
    if (!f.fmt || raw === '' || raw == null) return null;
    if (isDate(f)) return { text: date(colorTag(f.fmt).sec, raw), color: colorTag(f.fmt).color };
    if (isNumber(f)) return numberRich(f.fmt, raw);
    if (isText(f)) return textRich(f.fmt, raw);
    return null;
  }
  /** רק הטקסט (מסך, PDF, Word). */
  const apply = (f, raw) => rich(f, raw)?.text ?? null;

  const PRESETS = {
    date: [
      ['dd/MM/yyyy', 'רגיל'], ['dd.MM.yy', 'מקוצר'], ['d בMMMM yyyy', 'שם החודש'], ['dddd, d בMMMM yyyy', 'עם היום בשבוע'],
      ['hd בhMMMM hyy', 'עברי'], ['dd/MM/yyyy · hd בhMMMM hyy', 'לועזי ועברי'], ['d בMMMM yyyy (hd בhMMMM hyyyy)', 'משולב, מלא'],
    ],
    number: [['#,##0', 'אלפים'], ['#,##0.00', 'שתי ספרות'], ['₪ #,##0.00', 'שקלים'], ['#,##0 ש״ח', 'ש״ח בסוף'], ['0%', 'אחוזים'], ['0.0', 'ספרה אחת']],
  };

  const api = { date, number, numberRich, textRich, hebrew, gematria, apply, rich, isDate, isNumber, isText, PRESETS };
  if (typeof module !== 'undefined') module.exports = api;
  else root.DTFmt = api;
})(typeof window !== 'undefined' ? window : globalThis);
