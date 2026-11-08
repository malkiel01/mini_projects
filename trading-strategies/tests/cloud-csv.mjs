// העותק האישי בענן (spec-stage-a 3.7): הפורמט, המחרוזות העשרוניות בלי אובדן ספרות, מיזוג בלי כפילויות ובלי שינוי נר קיים.
// node trading-strategies/tests/cloud-csv.mjs
import { csvFromRows, parseCsv, mergeRows, cloudPath, CSV_HEAD } from '../assets/js/cloud.js';

let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };

const rows = [
  { t: 1772463600, o: '0.00001234', h: '0.00001240', l: '0.00001230', c: '0.00001235', v: '1234567.89' },
  { t: 1772460000, o: '67000.10', h: '67010.25', l: '66990', c: '67001.5', v: '12.5' },
];
const csv = csvFromRows(rows, { provider: 'binance', symbol: 'PEPEUSDT', tf: 'h1', saved: '2026-11-08T09:00:00.000Z' });
const lines = csv.trim().split('\n');
eq(lines[0], '# provider=binance; symbol=PEPEUSDT; tf=h1; saved=2026-11-08T09:00:00.000Z', 'first line — the comment');
eq(lines[1], CSV_HEAD, 'header t,o,h,l,c,v');
eq(lines[2], '1772460000,67000.10,67010.25,66990,67001.5,12.5', 'sorted by t; strings as the provider gave them (trailing zero kept)');
eq(lines[3], '1772463600,0.00001234,0.00001240,0.00001230,0.00001235,1234567.89', 'small prices — no float, no lost digits (0.00001234)');
eq(csv.includes('e-'), false, 'never scientific notation');

const back = parseCsv(csv);
eq([back.meta.provider, back.meta.symbol, back.meta.tf, back.rows.length, back.rows[1].o], ['binance', 'PEPEUSDT', 'h1', 2, '0.00001234'], 'read back: meta and rows, same strings');
eq(parseCsv('# x\nt,o,h,l,c,v\n1,a,1,1,1,1\n2,1,1,1,1,1\n').rows.length, 1, 'invalid lines are skipped, not "fixed"');

// מיזוג: נר קיים לא משתנה; חדש נוסף; הנר הפתוח לא נשמר; בלי כפילויות
const changed = { ...rows[1], c: '99999' };
const now = 1772467200 + 100;   // הנר של 1772467200 עוד פתוח (שעה)
const incoming = [changed, { t: 1772467200 - 3600, o: '1', h: '1', l: '1', c: '1', v: '0' }, { t: 1772467200, o: '2', h: '2', l: '2', c: '2', v: '0' }];
const m = mergeRows(back.rows, incoming, { sec: 3600, now });
eq(m.rows.map(r => r.t), [1772460000, 1772463600], 'the open candle is not saved; existing kept (1772463600 = existing)');
eq([m.added, m.rows.find(r => r.t === 1772460000).c], [0, '67001.5'], 'an existing (closed) candle never changes');
const m2 = mergeRows(m.rows, [{ t: 1772467200, o: '5', h: '5', l: '5', c: '5', v: '1' }], { sec: 3600, now: 1772470800 });
eq([m2.added, m2.rows.map(r => r.t)], [1, [1772460000, 1772463600, 1772467200]], 'save again later — the newly closed candle is added once, sorted, no duplicates');
eq(mergeRows(m2.rows, m2.rows, { sec: 3600, now: 1772470800 }).added, 0, 'saving the same again adds nothing');

eq(cloudPath('finnhub', 'OANDA:EUR_USD', 'd1'), { folders: ['אסטרטגיות למסחר', 'נתונים', 'finnhub', 'OANDA_EUR_USD'], file: 'd1.csv' }, 'path: אסטרטגיות למסחר/נתונים/<ספק>/<סימול>/<מסגרת>.csv, symbol made a safe name');
eq(cloudPath('twelvedata', 'BTC/USD', 'h4').folders[3], 'BTC_USD', '"/" in a symbol does not create a sub-folder');

console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
