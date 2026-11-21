/**
 * V6b — החלק הטהור של layout.js (מסילות grid: פירוק, חיבור, שינוי גודל בגרירת קו) ושל position.js (מרווח שווה בין שלושה):
 *   node kol-maroko/tests/editor/layout.mjs
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/js/editor/layout.js', 'assets/js/editor/position.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8'));
const L = globalThis.KolLayout, P = globalThis.KolPos;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// ── פירוק מסילות ──
let t = L.tracks('[a] 1fr minmax(100px, 2fr)  repeat(2, [x] 50px) auto');
ok(eq(t.map(x => x.kind), ['line', 'track', 'track', 'repeat', 'track']) && t[2].v === 'minmax(100px, 2fr)' && t[3].v === 'repeat(2, [x] 50px)', 'פירוק ברמה העליונה: שם קו, minmax עם רווח, repeat כקבוצה אחת', t);
ok(L.joinTracks(t) === '[a] 1fr minmax(100px, 2fr) repeat(2, [x] 50px) auto', 'חיבור — אותו ערך (רווח אחד בין מסילות)', L.joinTracks(t));
ok(eq(L.tracks(''), []) && eq(L.tracks(null), []), 'ערך ריק — רשימה ריקה');
ok(L.tracks('fit-content(40%) min-content').length === 2, 'fit-content() ו-min-content — מסילות');

// ── שינוי גודל בגרירת קו ──
ok(eq(L.resize(['1fr', '1fr'], [500, 500], 0, 100, 1000), ['1.2fr', '0.8fr']), 'fr ← fr: חלוקה מחדש של הסכום (600 / 400 ← 1.2fr / 0.8fr)', L.resize(['1fr', '1fr'], [500, 500], 0, 100, 1000));
ok(eq(L.resize(['200px', '1fr'], [200, 800], 0, 50, 1000), ['250px', '1fr']), 'px — נשאר px; השכנה ב-fr נשארת');
ok(eq(L.resize(['25%', '1fr'], [250, 750], 0, 50, 1000), ['30%', '1fr']), '% — נשאר % (מרוחב התוכן)');
ok(eq(L.resize(['auto', '1fr'], [120, 880], 0, 30, 1000), ['150px', '1fr']), 'auto ← px (הגודל הנראה)');
ok(eq(L.resize(['2fr', '100px'], [800, 100], 0, -400, 900), ['1fr', '100px']), 'fr ליד px — יחסית ליחידה שלה');
ok(eq(L.resize(['1fr', '1fr', '1fr'], [300, 300, 300], 1, 60, 900), ['1fr', '1.2fr', '0.8fr']), 'קו אמצעי: רק שתי המסילות שלצידו');
ok(+L.resize(['1fr', '1fr'], [500, 500], 0, -900, 1000)[0].replace('fr', '') > 0, 'גרירה מעבר לקצה — לא שלילי, לא אפס');

// ── מרווח שווה בין שלושה ──
let s = P.eqSnaps([[0, 100], [140, 240]], 50);
ok(s.some(c => c.at === 280 && c.gap === 40), 'אחרי B: באותו מרווח (40) — התחלה ב-280', s);
ok(s.some(c => c.at === -90 && c.gap === 40), 'לפני A: התחלה ב-(-90)', s);
ok(!s.some(c => c.at === 100 + (40 - 50) / 2), 'אין "באמצע" כשהנגרר רחב מהמרווח');
s = P.eqSnaps([[200, 300], [0, 100]], 20);
ok(s.some(c => c.at === 140 && c.gap === 40 && eq(c.segs, [[100, 140], [160, 200]])), 'באמצע: 40 / 40, והקטעים לסימון — גם כשהקופסאות לא ממוינות', s);
ok(eq(P.eqSnaps([[0, 100]], 10), []), 'אח אחד — אין מרווח שווה');
ok(eq(P.eqSnaps([[0, 100], [50, 150]], 10), []), 'אחים חופפים — אין מרווח');

console.log(`\n${ran - failed}/${ran} עברו`);
process.exit(failed ? 1 : 0);
