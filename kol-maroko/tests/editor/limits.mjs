/**
 * המגבלות הידועות של התיקון הנקודתי (docs/spec-v2/03-visual-editor.md §2.3) — בדיקה שמקבעת כל אחת מהן, על המודל לבדו.
 *   node kol-maroko/tests/editor/limits.mjs
 * אם התנהגות כאן משתנה — או שהמגבלה נפתרה (מעדכנים את האפיון ואת הבדיקה), או שנשבר משהו. יוצא ב-1 על כישלון.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;', { filename: f });
const K = globalThis.KolSrc;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got) : '')); } };
const run = (src, fn) => { const m = K.parseHtml(src), r = fn(m), out = K.applyPatches(src, r.patches); return { out, back: K.applyPatches(out, K.invertPatches(src, r.patches)), m }; };
const el = (m, sel) => K.q(m, sel);

// 1. סגנון קוד חדש נלמד מהסביבה; ביטול מחזיר קובץ זהה בייט בבייט — תמיד
{
  const src = '<ul>\n\t<li>א</li>\n    <li class="x">ב</li>\n\t<li>ג</li>\n</ul>\n';      // טאבים ורווחים מעורבים
  const del = run(src, m => K.html.remove(m, el(m, 'li.x')));
  ok(del.back === src, '1. מחיקה ואחריה ביטול — הקובץ זהה בייט בבייט, גם בקובץ שההזחה שלו אינה עקבית');
  const ins = run(del.out, m => K.html.insert(m, el(m, 'ul'), 1, '<li class="x">ב</li>'));
  ok(ins.out !== src && /\n\t<li class="x">ב<\/li>\n/.test(ins.out), '1. אלמנט שנמחק ונוסף מחדש חוזר בהזחה של שכניו (טאב), לא בזו המקורית (4 רווחים)', ins.out);
  ok(ins.back === del.out, '1. וגם ההוספה מתבטלת לקובץ זהה');
}
// 2. ישות שנערכה חוזרת כתו; &nbsp; נשמר
{
  const src = '<p id="c">&copy; 2024&nbsp;קול</p>\n<p id="d">&copy; נשאר</p>\n';
  const r = run(src, m => K.html.setText(m, el(m, '#c'), K.textOf(m, el(m, '#c')).replace('2024', '2025')));
  ok(r.out === '<p id="c">© 2025&nbsp;קול</p>\n<p id="d">&copy; נשאר</p>\n', '2. עריכת טקסט: ‎&copy; חוזרת כתו ©, ‎&nbsp; נשמרת; ישות בטקסט שלא נגעו בו — נשארת', r.out);
  ok(r.back === src, '2. ביטול מחזיר את הישות המקורית');
}
// 3. תכונה בלי מרכאות חוזרת מנורמלת — רק זו שנגעו בה
{
  const src = '<a href=a.html  class=btn   title = "x">קישור</a>\n';
  const r = run(src, m => K.html.setAttr(m, el(m, 'a'), 'href', 'b c.html'));
  ok(r.out === '<a href="b c.html"  class=btn   title = "x">קישור</a>\n', '3. שינוי תכונה בלי מרכאות: היא מקבלת מרכאות; התכונות האחרות והרווחים הכפולים — כמו שהיו', r.out);
  const r2 = run(src, m => K.html.setAttr(m, el(m, 'a'), 'title', 'y'));
  ok(r2.out === '<a href=a.html  class=btn   title = "y">קישור</a>\n' && r2.back === src, '3. שינוי ערך בתכונה עם מרכאות — רק הערך משתנה', r2.out);
}
// 4. תגית באותיות גדולות חוזרת קטנה אחרי שינוי תגית — ורק היא
{
  const src = '<DIV CLASS="a"><SPAN>x</SPAN></DIV>\n<DIV>y</DIV>\n';
  const r = run(src, m => K.html.retag(m, K.all(m).find(n => n.tag === 'div'), 'section'));
  ok(r.out === '<section CLASS="a"><SPAN>x</SPAN></section>\n<DIV>y</DIV>\n' && r.back === src, '4. שינוי תגית של <DIV>: התגית החדשה באותיות קטנות; שאר התגיות הגדולות אינן משתנות', r.out);
}
// 5. עטיפה נכתבת בתוך השורה, בלי להזיח מחדש
{
  const src = '<main>\n  <h2>כותרת</h2>\n  <p>\n    טקסט\n  </p>\n</main>\n';
  const r = run(src, m => K.html.wrap(m, el(m, 'p'), 'div', { class: 'box' }));
  ok(r.out === '<main>\n  <h2>כותרת</h2>\n  <div class="box"><p>\n    טקסט\n  </p></div>\n</main>\n' && r.back === src, '5. "עטוף במכל": התגיות נכתבות צמוד לאלמנט, והתוכן אינו מוזח מחדש', r.out);
}
// 6. סוגר מסולסל חסר ב-CSS בולע את כל מה שאחריו
{
  const css = '.a { color: red; }\n.b { color: blue;\n.c { margin: 0; }\n.d { padding: 0; }\n';
  const m = K.parseCss(css), all = K.cssAll(m), broken = all.filter(n => n.broken);
  ok(broken.length >= 1 && K.lineOf(css, broken[0].from) === 2, '6. CSS עם סוגר חסר: הכלל השבור מסומן, בשורה 2', broken.map(n => [n.sel, K.lineOf(css, n.from)]));
  ok(!K.cssFind(m, '.d', null) && !K.cssFind(m, '.c', null), '6. הכללים שאחרי הסוגר החסר נבלעו — אינם כללים של הקובץ (כך גם הדפדפן קורא)');
  let err = null; try { K.css.setDecl(m, broken[0], 'color', 'green'); } catch (e) { err = e; }
  ok(err instanceof K.SrcError && err.code === 'broken', '6. עריכה חזותית של הכלל השבור נדחית ("מתקנים בעורך הקוד")', err && err.message);
  const good = K.cssFind(m, '.a', null); let e2 = null; try { K.css.setDecl(m, good, 'color', 'green'); } catch (e) { e2 = e; }
  ok(good && !e2, '6. כלל שלפני השגיאה — נערך כרגיל');
}
// 7. שורה ריקה שצמודה לאלמנט נמחקת (ומועברת) איתו
{
  const src = '<body>\n<h1>א</h1>\n\n<p class="x">ב</p>\n\n<p>ג</p>\n</body>\n';
  const r = run(src, m => K.html.remove(m, el(m, 'p.x')));
  ok(r.out === '<body>\n<h1>א</h1>\n\n<p>ג</p>\n</body>\n' && r.back === src, '7. מחיקה: השורה הריקה שצמודה לאלמנט נמחקת איתו — לא נשארת שורה ריקה יתומה', r.out);
}
console.log(`\n${ran - failed}/${ran} עברו` + (failed ? ` — ${failed} נכשלו` : ''));
process.exit(failed ? 1 : 0);
