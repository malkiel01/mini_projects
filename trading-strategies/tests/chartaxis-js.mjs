// ציר הזמן לפי שעון המשתמש (assets/js/chartaxis.js: tickKey, tzOffset) — node trading-strategies/tests/chartaxis-js.mjs
// התקלה (בקרה 8, 2026-11-16): BTC/USD, שעה, בישראל — הגבולות נחתכו לפי UTC (00:00 / 12:00 UTC) והוצגו כ-02:00 / 14:00 שעון ישראל.
import { tickKey, tzOffset } from '../assets/js/chartaxis.js';

let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const H = 3600, D = 86400;
/** הנרות (כל שעה) שבהם מתחיל סימון — כמו הלולאה ב-timeTicks: מפתח שונה מזה של הנר הקודם */
const marks = (t0, hours, step, kind, off) => {
  const out = [];
  for (let i = 1; i < hours; i++) if (tickKey(t0 + i * H, step, kind, off) !== tickKey(t0 + (i - 1) * H, step, kind, off)) out.push(t0 + i * H);
  return out;
};
const uniq = a => [...new Set(a)].sort();
const hh = (t, tz) => new Date(t * 1000).toLocaleString('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const ymd = (t, tz) => { const p = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: tz, day: 'numeric', month: 'numeric', year: 'numeric' }).formatToParts(new Date(t * 1000)).map(x => [x.type, x.value])); return `${p.day}.${p.month}.${p.year}`; };

// ── ההפרש מ-UTC, כולל שעון קיץ ──
const IL = tzOffset('Asia/Jerusalem');
const winter = Date.UTC(2026, 10, 16, 12) / 1000, summer = Date.UTC(2026, 6, 1, 12) / 1000;
eq([IL(winter), IL(summer)], [2 * H, 3 * H], 'Asia/Jerusalem: +2 in winter, +3 in summer');
eq([tzOffset('UTC')(winter), tzOffset('America/New_York')(winter), tzOffset('Asia/Kolkata')(winter)], [0, -5 * H, 5.5 * H], 'UTC 0, New York −5, Kolkata +5:30');
eq(tzOffset('Not/AZone')(winter), 0, 'an unknown zone → 0 (like UTC)');

// ── התקלה: שעה, צעד 12 שעות, בישראל → 00:00 / 12:00 מקומי (לא 02:00 / 14:00) ──
const t0 = Date.UTC(2026, 10, 10, 0) / 1000;
const il = marks(t0, 96, 12 * H, 'time', IL).map(t => hh(t, 'Asia/Jerusalem'));
eq(uniq(il), ['00:00', '12:00'], 'Israel, 12h step: the marks fall on local 00:00 and 12:00');
eq(marks(t0, 96, 12 * H, 'time').map(t => hh(t, 'Asia/Jerusalem')).includes('14:00'), true, '(before the fix — without an offset — Israel saw 02:00 / 14:00)');
eq(uniq(marks(t0, 96, 12 * H, 'time', tzOffset('UTC')).map(t => hh(t, 'UTC'))), ['00:00', '12:00'], 'UTC — as before');
eq(uniq(marks(t0, 96, 6 * H, 'time', IL).map(t => hh(t, 'Asia/Jerusalem'))), ['00:00', '06:00', '12:00', '18:00'], '6h step — 00 / 06 / 12 / 18 local');
eq(uniq(marks(t0, 24 * 10, D, 'day', IL).map(t => hh(t, 'Asia/Jerusalem'))), ['00:00'], 'a day step — at local midnight');
const ny = tzOffset('America/New_York');
eq(uniq(marks(t0, 24 * 10, D, 'day', ny).map(t => hh(t, 'America/New_York'))), ['00:00'], 'New York (−5) — local midnight too');

// ── מעבר שעון קיץ ──
const dstEnd = Date.UTC(2026, 9, 23, 0) / 1000;    // ישראל: 25.10.2026 02:00 → 01:00
const across = marks(dstEnd, 24 * 5, 12 * H, 'time', IL).map(t => hh(t, 'Asia/Jerusalem'));
eq(uniq(across), ['00:00', '12:00'], 'across the end of daylight saving (Israel, 25.10.2026) — still local 00:00 / 12:00');
eq(across.length, 10, '… one mark every 12 hours, none lost or doubled');
const dstStart = Date.UTC(2026, 2, 24, 0) / 1000;  // ישראל: 27.3.2026 02:00 → 03:00
eq(uniq(marks(dstStart, 24 * 6, D, 'day', IL).map(t => hh(t, 'Asia/Jerusalem'))), ['00:00'], 'across the start of daylight saving — day marks at local midnight');

// ── חודש ושנה — לפי היום המקומי ──
// 1.12 00:00 בישראל = 30.11 22:00 UTC: הסימון של דצמבר על הנר הזה, לא שעתיים אחריו
const m = marks(Date.UTC(2026, 10, 29) / 1000, 24 * 4, 30 * D, 'month', IL);
eq([m.length, hh(m[0], 'Asia/Jerusalem'), ymd(m[0], 'Asia/Jerusalem')], [1, '00:00', '1.12.2026'], 'month — starts at local midnight of the 1st');
const y = marks(Date.UTC(2026, 11, 30) / 1000, 24 * 4, 365 * D, 'year', IL);
eq([y.length, ymd(y[0], 'Asia/Jerusalem'), hh(y[0], 'Asia/Jerusalem')], [1, '1.1.2027', '00:00'], 'year — local 1.1 00:00');

console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
