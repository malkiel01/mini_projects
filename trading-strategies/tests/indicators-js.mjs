// אינדיקטורים לתצוגה בגרף (assets/js/market.js) מול ערכים ידועים, בטולרנס יחסי 1e-9 (spec-stage-a 15, עיקרון 6).
// node trading-strategies/tests/indicators-js.mjs — הערכים חושבו בנפרד (Python), לפי Wilder; הסדרה — הדוגמה של Wilder ל-RSI.
import { sma, ema, rsi, atr } from '../assets/js/market.js';

let fail = 0, n = 0;
const near = (got, want, what) => {
  n++;
  const ok = got === want || (got !== null && want !== null && Math.abs(got - want) <= 1e-9 * Math.max(1, Math.abs(want)));
  if (!ok) { fail++; console.log(`✗ ${what}: got ${got}, want ${want}`); }
};
const c = [44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42, 45.84, 46.08, 45.89, 46.03, 45.61, 46.28, 46.28, 46.00, 46.03, 46.41, 46.22, 45.64];
const s = sma([1, 2, 3, 4, 5], 3);
[null, null, 2, 3, 4].forEach((w, i) => near(s[i], w, `sma[${i}]`));
const e = ema(c, 5);
[null, null, null, null, 44.104, 44.346000000000004, 44.59733333333334, 44.87155555555556].forEach((w, i) => near(e[i], w, `ema5[${i}]`));
const r = rsi(c, 14);
near(r[13], null, 'rsi before period');
near(r[14], 70.46413502109705, 'rsi[14] (Wilder)');
near(r[19], 57.91502067008556, 'rsi[19] (Wilder smoothing)');
const a = atr(c.map(x => x + 0.5), c.map(x => x - 0.4), c, 5);
[null, null, null, null, 0.9719999999999984, 0.9775999999999987, 0.9620799999999987, 0.9496639999999987].forEach((w, i) => near(a[i], w, `atr5[${i}]`));
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
