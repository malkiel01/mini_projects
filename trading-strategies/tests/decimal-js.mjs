// בדיקות ל-assets/js/decimal.js — node trading-strategies/tests/decimal-js.mjs
// כולל ממצא הבקרה ב-#1259: ערכים מחושבים מהשרת ארוכים ממגבלת הקלט (16 ספרות) — parse החזירה null והיומן נשבר.
import * as D from '../assets/js/decimal.js';

let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (got !== want) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };

// הערך מתרחיש הבקרה: BTC 0.00012345 × 1000.12345678 → נטו בן 16 ספרות אחרי הנקודה
const net = '0.0040554906172429';
eq(D.parse(net) !== null, true, 'server value with 16 decimals parses');
eq(D.sign(D.parse(net)), 1, 'sign of 16-decimal value');
eq(D.fmt(net, 2), '0', 'fmt rounds to 2');
eq(D.fmt(net, 8), '0.00405549', 'fmt to 8');
eq(D.str(D.add(D.parse('1.5'), D.parse(net))), '1.5040554906172429', 'add keeps every digit');
eq(D.fmt('-1234567.0000000000000001', 2), '-1,234,567', 'long negative');
eq(D.parse('123456789012345678901234567890') !== null, true, 'long integer part (sums)');

// השאר
eq(D.fmt('1234567.555', 2), '1,234,567.56', 'thousands + half-up');
eq(D.str(D.div(D.parse('100'), D.parse('3'), 0, 'down')), '33', 'div down');
eq(D.str(D.div(D.parse('-2.075'), D.parse('1'), 2)), '-2.08', 'div half away from zero');
eq(D.str(D.mul(D.parse('0.00012345'), D.parse('1000.12345678'))), '0.123465240739491', 'mul exact (Python Decimal agrees)');
eq(D.parse('1e5'), null, 'exponent rejected');
eq(D.parse(1.5), null, 'number (float) rejected');
eq(D.parse('1,25') && D.str(D.parse('1,25')), '1.25', 'comma decimal');

console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
