// שעות מסחר (בקשת הבעלים, 2026-11-17: "ברגע שאני נכנס לגרף של אזור מסחר סגור — לסמן שהוא סגור, עם שעון עד מועד הפתיחה").
// מודול טהור, בלי DOM — נבדק ב-tests/session-js.mjs. הזמנים לפי שעון ניו יורק דרך Intl — כולל שעון קיץ, בלי offset קבוע.
//   - מניות ומדדים בארה"ב (NYSE / NASDAQ): ב'–ו' 09:30–16:00 America/New_York.
//   - מט"ח וסחורות: מראשון 17:00 עד שישי 17:00, שעון ניו יורק.
//   - קריפטו, ECB (יומי) ובורסות אחרות — אין תווית (null).
//   - [לא נבנה] חגים וימים מקוצרים — בשוק "פתוח" בחג התווית לא תופיע, והגרף פשוט לא יתעדכן.

const NY = 'America/New_York';
const fmt = new Intl.DateTimeFormat('en-US', { timeZone: NY, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const DOW = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };

/** {dow 0–6, min — דקות מחצות} בשעון ניו יורק */
export function nyClock(ms) {
  const p = Object.fromEntries(fmt.formatToParts(new Date(ms)).map(x => [x.type, x.value]));
  return { dow: DOW[p.weekday], min: (+p.hour % 24) * 60 + +p.minute };
}

/** סוג המושב: 'us' (מניות ארה"ב), 'fx' (מט"ח וסחורות) או null (תמיד פתוח / לא ידוע — בלי תווית) */
export function sessionKind({ provider = '', market = '', exchange = '', tz = '' } = {}) {
  if (provider === 'binance' || provider === 'ecb' || market === 'crypto') return null;
  if (market === 'forex' || market === 'commodities') return 'fx';
  if (market === 'stocks' || market === 'indices') {
    const us = /^(NYSE|NASDAQ|AMEX|ARCA|BATS|NYSE ?ARCA|NYSE ?MKT|US)\b/i.test(exchange) || tz === NY;
    return us ? 'us' : null;
  }
  return null;
}

/** האם פתוח ב-ms */
export function isOpen(kind, ms) {
  if (!kind) return true;
  const { dow, min } = nyClock(ms);
  if (kind === 'us') return dow >= 1 && dow <= 5 && min >= 9 * 60 + 30 && min < 16 * 60;
  // fx: סגור משישי 17:00 עד ראשון 17:00
  return !((dow === 5 && min >= 17 * 60) || dow === 6 || (dow === 0 && min < 17 * 60));
}

const STEP = 30 * 60 * 1000;   // כל הגבולות (09:30, 16:00, 17:00 בניו יורק) על חצאי שעה — וההפרש מ-UTC בשעות שלמות
/** {open, opensAt (ms, או null כשפתוח / בלי מושב)} */
export function sessionState(kind, ms) {
  if (!kind || isOpen(kind, ms)) return { open: true, opensAt: null };
  for (let t = Math.ceil((ms + 1) / STEP) * STEP, end = ms + 9 * 86400000; t <= end; t += STEP) if (isOpen(kind, t)) return { open: false, opensAt: t };
  return { open: false, opensAt: null };
}

/** "7:33:12" / "יום ו-3:05:09" / "2 ימים ו-1:00:00" */
export function countdown(ms) {
  const s = Math.max(0, Math.floor(ms / 1000));
  const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), ss = s % 60;
  const hms = `${h}:${String(m).padStart(2, '0')}:${String(ss).padStart(2, '0')}`;
  return d ? `${d === 1 ? 'יום' : d + ' ימים'} ו-${hms}` : hms;
}

/** "היום 16:30" / "מחר 16:30" / "ביום ב׳ 16:30" — בשעון המשתמש (tz) */
export function whenLabel(at, now, tz) {
  const opt = tz ? { timeZone: tz } : {};
  const day = t => new Intl.DateTimeFormat('en-CA', { ...opt, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(t));
  const hm = new Intl.DateTimeFormat('he-IL', { ...opt, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(at));
  const diff = Math.round((Date.parse(day(at)) - Date.parse(day(now))) / 86400000);
  if (diff === 0) return `היום ${hm}`;
  if (diff === 1) return `מחר ${hm}`;
  const wd = new Intl.DateTimeFormat('he-IL', { ...opt, weekday: 'short' }).format(new Date(at));
  return `ב${wd} ${hm}`;
}
