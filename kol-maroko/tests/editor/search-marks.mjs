/**
 * S1 — חיפוש בלי ניקוד:   node kol-maroko/tests/editor/search-marks.mjs   (רץ גם מ-tests/cases/99-search-marks.php)
 *
 * התקלה: ב-/lexicon/ החי "חפש מילה…" לא מצא מילים מנוקדות, כי plain() של kol-data.js לא הסיר ניקוד.
 * כאן kol-data.js (מה שרץ אצל הגולש) ו-bind.js (הקנבס בעורך) רצים על אותו DOM מינימלי ואותם נתונים, ושניהם צריכים
 * למצוא: שאילתה בלי ניקוד ← מילה מנוקדת, שאילתה מנוקדת ← מילה בלי ניקוד, תנועות ערביות, ותו כיוון/רוחב-אפס בשאילתה.
 * והתצוגה נשארת מנוקדת — רק ההשוואה משתנה.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
import { docFrom, serialize, decode } from './mini-dom.mjs';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
const KD = fs.readFileSync(path.join(root, 'assets/project-seed/kol-data.js'), 'utf8');
vm.runInThisContext(fs.readFileSync(path.join(root, 'assets/js/editor/bind.js'), 'utf8'), { filename: 'bind.js' });
const B = globalThis.KolBind;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 800) : '')); } };

const DATA = { items: [
  { id: 1, word: 'שָׁלוֹם', meaning: '<p>בְּרָכָה &amp; פְּרִידָה</p>' },
  { id: 2, word: 'לחם', meaning: 'אוכל' },
  { id: 3, word: 'مَاءٌ', meaning: 'מים' },
  { id: 4, word: 'Bonjour', meaning: 'שלום בצרפתית' },
  { id: 5, word: 'בַּיִת', meaning: '' },
] };

async function runKd(body) {
  const doc = docFrom(body), win = { document: doc, setTimeout, console, Intl, Date, URL, Audio: class {}, location: { href: 'http://t.test/index.html', pathname: '/index.html' }, fetch: async () => ({ ok: true, json: async () => JSON.parse(JSON.stringify(DATA)) }) };
  win.window = win; win.parent = win;
  vm.runInNewContext(KD, Object.assign(Object.create(null), win, { window: win }), { filename: 'kol-data.js' });
  for (let i = 0; i < 20; i++) await new Promise(r => setTimeout(r, 0));
  return { doc, KD: win.KolData };
}

ok(/^1\.5\.1$/.test((await runKd('<div></div>')).KD.version), 'הגרסה עלתה ל-1.5.1 (כך מופיע "🔄 עדכן את kol-data.js" אצל הבעלים)');

const body = '<div data-kol-list="words"><template><h3 data-kol-text="word"></h3></template></div><input data-kol-search="words" data-kol-search-fields="word meaning">';
const a = await runKd(body), st = a.KD.lists.words;
const cases = [
  ['שלום', [1, 4], 'בלי ניקוד ← מילה מנוקדת (וגם בפירוש)'],
  ['שָׁלוֹם', [1, 4], 'מנוקד ← גם הפירוש שאינו מנוקד'],
  ['לֶחֶם', [2], 'מנוקד ← מילה בלי ניקוד'],
  ['בית', [5], 'בלי ניקוד ← בַּיִת'],
  ['‏שלום‎', [1, 4], 'תווי RLM/LRM בשאילתה לא מפריעים'],
  ['‫ל​חם‬﻿', [2], 'RLE/PDF, רוחב-אפס ו-BOM בשאילתה לא מפריעים'],
  ['ماء', [3], 'ערבית בלי תנועות ← مَاءٌ'],
  ['ברכה', [1], 'ניקוד בתוך HTML של הפירוש'],
  ['bonjour', [4], 'אותיות גדולות/קטנות — כמו קודם'],
  ['', [1, 2, 3, 4, 5], 'שאילתה ריקה — הכול'],
  ['ְ', [1, 2, 3, 4, 5], 'שאילתה של ניקוד בלבד — כמו ריקה'],
  ['לא קיים', [], 'אין התאמה — אין תוצאות'],
];
for (const [q, want, what] of cases) {
  st.query = q; a.KD.refresh('words');
  const got = st.matched.map(x => x.id);
  const bind = B.matched({ items: B.itemsOf(DATA), labels: B.labelsOf(DATA) }, { query: q, fields: 'word meaning' }, decode).map(x => x.id);
  ok(JSON.stringify(got) === JSON.stringify(want) && JSON.stringify(bind) === JSON.stringify(want), what + ' — "' + q + '" → [' + got + ']', { kolData: got, bind, want });
}

st.query = 'שלום'; a.KD.refresh('words');
const shown = a.doc.querySelector('[data-kol-list]').childNodes.filter(n => n.nodeType === 1 && n.localName !== 'template').map(serialize).join('');
ok(shown.includes('שָׁלוֹם'), 'התצוגה נשארת מנוקדת — רק ההשוואה בלי ניקוד', shown);
ok(B.plain('שָׁלוֹם') === 'שלום' && B.plain('  A‏  b ') === 'a b', 'bind.js plain(): בלי ניקוד, בלי תווי כיוון, רווח אחד');

console.log('\n' + (failed ? '❌ ' + failed + ' נכשלו' : '✅ הכול עבר') + ' (' + ran + ' בדיקות)');
process.exit(failed ? 1 : 0);
