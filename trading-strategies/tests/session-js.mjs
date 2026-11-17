// שעות מסחר (assets/js/session.js): סגור / פתוח, שעון קיץ (ארה"ב וישראל לא עוברים באותו יום), סוף שבוע, מט"ח, וקריפטו בלי תווית.
// node trading-strategies/tests/session-js.mjs
import { sessionKind, isOpen, sessionState, countdown, whenLabel, nyClock } from '../assets/js/session.js';
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const U = (y, mo, d, h, mi = 0, s = 0) => Date.UTC(y, mo - 1, d, h, mi, s);
const IL = 'Asia/Jerusalem';

// ── סוג המושב ──
eq([sessionKind({ provider: 'twelvedata', market: 'stocks', exchange: 'NASDAQ' }), sessionKind({ provider: 'twelvedata', market: 'stocks', exchange: 'NYSE' }),
  sessionKind({ provider: 'finnhub', market: 'stocks', tz: 'America/New_York' })], ['us', 'us', 'us'], 'US stocks (NASDAQ / NYSE / New York time zone) → us');
eq([sessionKind({ provider: 'twelvedata', market: 'forex' }), sessionKind({ provider: 'twelvedata', market: 'commodities' })], ['fx', 'fx'], 'forex and commodities → fx');
eq([sessionKind({ provider: 'binance', market: 'crypto' }), sessionKind({ provider: 'twelvedata', market: 'crypto' }), sessionKind({ provider: 'ecb', market: 'forex' }),
  sessionKind({ provider: 'twelvedata', market: 'stocks', exchange: 'TASE' })], [null, null, null, null], 'crypto, ECB (daily) and other exchanges → no label');

// ── הבעלים: AAPL ב-08:56 שעון ישראל, שלישי 17.11.2026 → סגור, נפתח היום 16:30 (09:30 בניו יורק), בעוד 7:34:00 ──
const t0 = U(2026, 11, 17, 6, 56);
const s0 = sessionState('us', t0);
eq([s0.open, s0.opensAt, countdown(s0.opensAt - t0), whenLabel(s0.opensAt, t0, IL)], [false, U(2026, 11, 17, 14, 30), '7:34:00', 'היום 16:30'],
  "the owner's case: AAPL at 08:56 Israel time → closed, opens today 16:30 (in 7:34:00)");
eq(countdown(s0.opensAt - (t0 + 1000)), '7:33:59', 'a second later → 7:33:59');
eq([isOpen('us', U(2026, 11, 17, 14, 30)), isOpen('us', U(2026, 11, 17, 14, 29, 59)), isOpen('us', U(2026, 11, 17, 20, 59, 59)), isOpen('us', U(2026, 11, 17, 21, 0))],
  [true, false, true, false], 'opens at 09:30 and closes at 16:00 New York (14:30 / 21:00 UTC in winter)');
// ── שעון קיץ: ארה"ב עוברת ב-8.3.2026, ישראל ב-27.3 — באמצע הפתיחה ב-15:30 שעון ישראל ──
const s1 = sessionState('us', U(2026, 3, 10, 7, 0));
eq([s1.opensAt, whenLabel(s1.opensAt, U(2026, 3, 10, 7, 0), IL)], [U(2026, 3, 10, 13, 30), 'היום 15:30'], 'US daylight saving (10.3.2026): 09:30 New York = 13:30 UTC = 15:30 in Israel');
eq(sessionState('us', U(2026, 3, 6, 7, 0)).opensAt, U(2026, 3, 6, 14, 30), '… and the Friday before the switch: 14:30 UTC');
eq(sessionState('us', U(2026, 4, 1, 7, 0)).opensAt, U(2026, 4, 1, 13, 30), '… in April (both on daylight time): 13:30 UTC = 16:30 in Israel');
// ── סוף שבוע ──
const sat = U(2026, 11, 14, 12, 0);
const s2 = sessionState('us', sat);
eq([s2.opensAt, countdown(s2.opensAt - sat), whenLabel(s2.opensAt, sat, IL)], [U(2026, 11, 16, 14, 30), '2 ימים ו-2:30:00', 'ביום ב׳ 16:30'], 'Saturday → Monday 09:30 New York (in 2 days and 2:30:00, "ביום ב׳ 16:30")');
eq(sessionState('us', U(2026, 11, 13, 21, 0)).opensAt, U(2026, 11, 16, 14, 30), 'Friday after the close → Monday');
eq(countdown(U(2026, 11, 15, 14, 30) - U(2026, 11, 14, 12, 0)), 'יום ו-2:30:00', 'one day and some → "יום ו-2:30:00"');
// ── מט"ח: שישי 17:00 עד ראשון 17:00 בניו יורק ──
eq([isOpen('fx', U(2026, 11, 13, 21, 59)), isOpen('fx', U(2026, 11, 13, 22, 0)), isOpen('fx', U(2026, 11, 15, 21, 59)), isOpen('fx', U(2026, 11, 15, 22, 0)), isOpen('fx', U(2026, 11, 17, 3, 0))],
  [true, false, false, true, true], 'forex: closes Friday 17:00 New York (22:00 UTC in winter), opens Sunday 17:00; open overnight on weekdays');
const s3 = sessionState('fx', sat);
eq([s3.opensAt, whenLabel(s3.opensAt, sat, IL)], [U(2026, 11, 15, 22, 0), 'ביום ב׳ 00:00'], 'forex on Saturday → Sunday 17:00 New York = Monday 00:00 in Israel');
eq(sessionState('fx', U(2026, 7, 11, 12, 0)).opensAt, U(2026, 7, 12, 21, 0), '… in summer: 21:00 UTC (17:00 EDT)');
// ── קריפטו — תמיד פתוח, בלי תווית ──
eq([sessionState(null, sat), sessionState(null, t0)], [{ open: true, opensAt: null }, { open: true, opensAt: null }], 'crypto (no session) → always open, no label');
eq(nyClock(U(2026, 11, 17, 14, 30)), { dow: 2, min: 570 }, 'nyClock: Tuesday 09:30 in New York');
eq(whenLabel(U(2026, 11, 18, 14, 30), U(2026, 11, 17, 20, 0), IL), 'מחר 16:30', '"מחר" — the next day in the user\'s clock');

console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
