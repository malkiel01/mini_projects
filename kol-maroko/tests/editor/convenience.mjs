/**
 * פרוסה V13 — החלקים הטהורים של history.js, stylecopy.js, templates.js ו-palette.js:   node kol-maroko/tests/editor/convenience.mjs
 * שמות בהיסטוריה (איחוד "48 ← 52", שורות קוד) · איסוף ההצהרות של בורר (בסיס, מכשיר, מצב) ואיפוס · שינוי שם מחלקה מתנגשת,
 * כללים זהים לא כפולים · ZIP: כתיבה ← קריאה, CRC, בדיקת נתיבים (רק templates/<שם>/ ו-media/) · ציון החיפוש.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/style.js', 'assets/js/editor/history.js', 'assets/js/editor/stylecopy.js', 'assets/js/editor/templates.js', 'assets/js/editor/palette.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;');
const H = globalThis.KolHistory, SC = globalThis.KolStyleCopy, T = globalThis.KolTemplates, P = globalThis.KolPalette, K = globalThis.KolSrc;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };

// ── היסטוריה ──
ok(H.mergeKey('גודל · כותרת ראשית · 48 ← 56') === 'גודל · כותרת ראשית' && H.mergeKey('מחיקת h1') === null && H.mergeKey('קוד · a.css · 3 שורות') === null, 'מפתח האיחוד: רק "ערך ← ערך" בסוף');
ok(H.mergeLabel('גודל · כותרת · 48 ← 50', 'גודל · כותרת · 50 ← 52') === 'גודל · כותרת · 48 ← 52', 'שני צעדים רצופים ← "48 ← 52"');
ok(H.linesChanged('a\nb\nc', 'a\nB\nc') === 1 && H.linesChanged('a\nb\nc', 'a\nX\nY\nZ\nc') === 3 && H.linesChanged('x', 'x') === 0, 'שורות שהשתנו');
ok(H.codeLabel('css/page.css', 3) === 'קוד · css/page.css · 3 שורות' && H.codeLabel('a.css', 1) === 'קוד · a.css · שורה אחת', 'שם של עריכת קוד');
ok(H.MAX === 500, 'עד 500 צעדים');

// ── העתק סגנון ──
const css = '.t { color: red; margin-inline-start: 4px }\n.t:hover { color: blue }\n.other { color: green }\n@media (max-width: 767px) {\n  .t { font-size: 20px; }\n  .x { top: 0 }\n}\n@media (min-width: 900px) { .t { color: pink } }\n@media (max-width: 500px) { .t { color: gray } }\n';
const got = SC.collectSel(css, '.t', mx => mx === 767);
ok(got.items.length === 3 && got.items[0].decls.length === 2 && got.items[1].state === 'hover' && got.items[2].dev === 767 && got.items[2].decls[0].prop === 'font-size', 'collectSel: בסיס, מצב hover, מכשיר 767 — ולא @media שאינו מכשיר', got.items.map(x => [x.dev, x.state, x.decls.length]));
ok(SC.physOf('margin-inline-start', true) === 'margin-right' && SC.physOf('margin', true) === 'margin' && SC.physOf('color', true) === 'color', 'physOf: לוגי ← פיזי, קיצור כמו שהוא');
const gs = SC.groups([...got.items, { dev: null, state: null, decls: [{ prop: 'color', value: 'black' }] }], true);
ok(gs.length === 3 && gs[0].dev === null && !gs[0].state && gs[0].sets.find(x => x[0] === 'color')[1] === 'black' && gs[0].sets.length === 2, 'groups: אותו (מכשיר, מצב) מתאחד, האחרונה גוברת', gs);
const reset = SC.resetText(css, got.items.map(i => i.node));
ok(!/\.t\s*\{/.test(reset.replace(/@media \(min-width: 900px\) \{ \.t \{ color: pink \} \}/, '').replace(/@media \(max-width: 500px\) \{ \.t \{ color: gray \} \}/, '')) && /\.other/.test(reset) && /\.x \{ top: 0 \}/.test(reset) && /min-width: 900px/.test(reset), 'resetText: הכללים של .t נמחקו (בסיס, מצב, מכשיר) — .other ו-.x נשארו', reset);
const reset2 = SC.resetText('.a{color:red}\n@media (max-width: 767px) {\n  .a { color: blue }\n}\n.b{color:x}', SC.collectSel('.a{color:red}\n@media (max-width: 767px) {\n  .a { color: blue }\n}\n.b{color:x}', '.a', () => true).items.map(i => i.node));
ok(!/@media/.test(reset2) && /\.b\{color:x\}/.test(reset2), '@media שהתרוקן — נמחק כולו', reset2);
ok(SC.inlineDecls('color: red; font-size: 2px').length === 2 && SC.inlineDecls('').length === 0, 'inlineDecls');

// ── תבניות ──
const tplCss = '.hero { color: red }\n.hero-t { font-size: 3em }\n.same { padding: 1px }\n', tplHtml = '<div class="hero same"><h2 class="hero-t">x</h2></div>';
const pageCss = ['.hero { color: blue }\n.same { padding: 1px }\n'];
const pl = T.plan(tplCss, tplHtml, pageCss, new Set(['hero', 'same', 'hero-2']));
ok(pl.rename.get('hero') === 'hero-3' && pl.same.has('same') && !pl.rename.has('hero-t') && !pl.rename.has('same'), 'מחלקה בשימוש עם תוכן אחר ← שם חדש (hero-3, כי hero-2 תפוס); זהה — נשארת', { r: [...pl.rename], s: [...pl.same] });
ok(T.renameHtml(tplHtml, pl.rename) === '<div class="hero-3 same"><h2 class="hero-t">x</h2></div>', 'renameHtml: רק המחלקה עצמה (לא hero-t)');
const rc = T.renameCss(tplCss + '.hero:hover .hero-t, .hero > b { x: url(.hero) }', pl.rename);
ok(/\.hero-3 \{ color: red \}/.test(rc) && /\.hero-t \{/.test(rc) && /\.hero-3:hover \.hero-t, \.hero-3 > b \{ x: url\(\.hero\) \}/.test(rc), 'renameCss: בבוררים בלבד, לא בערכים', rc);
const ds = T.dropSame(T.renameCss(tplCss, pl.rename), pageCss);
ok(!/\.same/.test(ds) && /\.hero-3/.test(ds) && /\.hero-t/.test(ds), 'dropSame: כלל זהה שכבר בעמוד — לא נוסף שוב', ds);
ok(T.commentSafe('a */ b\nc') === 'a * / b c', 'commentSafe: אין סגירת הערה בשם');
ok(T.slug('Hero Strip!') === 'hero-strip' && T.slug('פס') === 'template', 'slug');
let thrown = 0; for (const bad of ['', '../x', 'A', 'a/b', 'x'.repeat(60), 'a b']) { try { T.checkName(bad); } catch { thrown++; } }
ok(thrown === 6 && T.checkName('hero-strip') === 'hero-strip', 'checkName: רק kebab-case קטן');
ok(JSON.stringify(T.refs('<img src="media/a.jpg"><a href="https://x.y/z">x</a><img srcset="media/b.png 1x, media/c.png 2x"><div style="background:url(media/d.webp)">', '.x{background:url("media/e.svg")} .y{background:url(../../etc/passwd)}').sort()) === JSON.stringify(['media/a.jpg', 'media/b.png', 'media/c.png', 'media/d.webp', 'media/e.svg']), 'refs: src / srcset / url() — בלי כתובות חיצוניות ובלי ..');

// ── ZIP ──
const enc = new TextEncoder(), files = [['templates/hero/template.html', enc.encode('<div>שלום</div>')], ['templates/hero/template.json', enc.encode('{"name":"hero"}')], ['media/a.bin', Uint8Array.from([0, 1, 2, 255])]];
const zip = T.zipBuild(files), list = T.zipList(zip);
ok(list.length === 3 && list.every((x, i) => x.name === files[i][0] && x.method === 0 && x.size === files[i][1].length && Buffer.from(x.raw).equals(Buffer.from(files[i][1]))), 'ZIP: כתיבה ← קריאה, שמות UTF-8 ובייטים זהים');
ok(T.crc32(enc.encode('123456789')) === 0xCBF43926, 'CRC32 (וקטור הבדיקה)');
{ // ה-ZIP נקרא גם ב-PHP (ZipArchive) — אותו פורמט שהשרת מקבל ב-pf_zip_import
  const tmp = path.join(fs.mkdtempSync(path.join((await import('node:os')).tmpdir(), 'kol-v13-')), 't.zip'); fs.writeFileSync(tmp, zip);
  let out = ''; try { out = (await import('node:child_process')).execFileSync('php', ['-r', '$z=new ZipArchive(); if ($z->open($argv[1])!==true) exit(1); $o=[]; for($i=0;$i<$z->numFiles;$i++){ $s=$z->statIndex($i); $o[]=$s["name"].":".strlen($z->getFromIndex($i)); } echo implode(",", $o);', tmp]).toString(); } catch (e) { out = 'ERR ' + e.message; }
  ok(out === 'templates/hero/template.html:19,templates/hero/template.json:15,media/a.bin:4', 'ZIP נפתח ב-PHP ZipArchive', out);
}
ok(T.checkZip(list).name === 'hero', 'checkZip: תבנית אחת + media/');
const bad = (names, what) => { let msg = ''; try { T.checkZip(names.map(name => ({ name }))); } catch (e) { msg = e.message; } ok(!!msg, 'checkZip דוחה: ' + what, msg); };
bad(['templates/a/template.html', 'templates/a/template.json', 'index.html'], 'קובץ מחוץ ל-templates/ ו-media/');
bad(['templates/a/template.html', 'templates/a/template.json', 'templates/b/template.json'], 'שתי תבניות');
bad(['templates/a/../../x', 'templates/a/template.json', 'templates/a/template.html'], '..');
bad(['/etc/passwd'], 'נתיב מוחלט');
bad(['templates/a/template.html'], 'חסר template.json');
bad(['templates/A B/template.html', 'templates/A B/template.json'], 'שם תבנית לא חוקי');

// ── חיפוש ──
ok(P.score('צל', 'צל וקו מתאר') >= 80 && P.score('letter', 'letter-spacing') >= 60 && P.score('xyz', 'צבע') === 0 && P.score('הוסף כותרת', 'הוסף כותרת') === 100, 'ציון החיפוש');
ok(P.CONTROLS.some(c => c[3] === 'letter-spacing' && c[2] === 'ריווח אותיות') && P.CONTROLS.some(c => c[1] === 'shadow'), 'השליטות: ריווח אותיות, צל');

console.log((failed ? '❌ ' : '✅ ') + (ran - failed) + '/' + ran);
process.exit(failed ? 1 : 0);
