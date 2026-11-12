/**
 * רקע של רובריקה (V5) — החלק הטהור של assets/js/editor/background.js ושל style.js (covered / componentOf ל-background):
 *   node kol-maroko/tests/editor/background.mjs
 * K2 לכל סוג כתיבה חדש: מחיקת הצהרה · החלפת url() בתוך הצהרה · הסרת שכבה מרשימה (עם ההצהרות המקבילות) · הוספת שכבה ·
 * שינוי סדר שכבות · הצהרה מפורשת אחרי קיצור · שינוי הגדרת משתנה. בכל אחת: ביטול מחזיר קובץ זהה, הקובץ נקרא מחדש בלי
 * שבירה (ב-CSS העורך מנתח מחדש; השקילות ההדרגתית — ב-HTML, בדפדפן). ובדיקות הזרקה לכל כתובת.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/background.js', 'assets/js/editor/style.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;');
const K = globalThis.KolSrc, ST = globalThis.KolStyle, B = globalThis.KolBg;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const page = fs.readFileSync(path.join(here, 'fixtures/page.css'), 'utf8'), lex = fs.readFileSync(path.join(here, 'fixtures/lexicon.css'), 'utf8');
const rule = (m, sel) => K.cssAll(m).find(n => n.type === 'rule' && n.sel.replace(/\s+/g, ' ').trim() === sel);
function k2(name, src, ps, want) {
  let out, back, inc = true;
  try { out = K.applyPatches(src, ps); back = K.applyPatches(out, K.invertPatches(src, ps)); } catch (e) { ok(false, name, e.message); return src; }
  const m1 = K.parseCss(src), m2 = K.parseCss(out), broken = K.cssAll(m2).some(n => n.broken) !== K.cssAll(m1).some(n => n.broken);
  // העדכון ההדרגתי (K.update) הוא ל-HTML בלבד; ב-CSS העורך מנתח מחדש — השקילות כאן היא הניתוח המחודש עצמו
  ok(back === src && !broken && inc && want(out, m2), name, { out: out.slice(0, 600) });
  return out;
}

// ── 1. קריאת קיצור ──
const HERO = 'var(--ka-navy) center / cover no-repeat';
ok(B.bgComponent(HERO, 'background-position') === 'center' && B.bgComponent(HERO, 'background-size') === 'cover' && B.bgComponent(HERO, 'background-repeat') === 'no-repeat' && B.bgComponent(HERO, 'background-color') === 'var(--ka-navy)' && B.bgComponent(HERO, 'background-image') === 'none',
  'קיצור של הערכה: מיקום center, גודל cover, חזרה no-repeat, צבע var(--ka-navy), תמונה none');
const ML = "url(a.jpg) left 10% top / 50% auto repeat-x fixed, linear-gradient(red, blue) padding-box content-box #fff";
ok(B.bgComponent(ML, 'background-image') === 'url(a.jpg), linear-gradient(red, blue)' && B.bgComponent(ML, 'background-size') === '50% auto, auto' && B.bgComponent(ML, 'background-position') === 'left 10% top, 0% 0%' && B.bgComponent(ML, 'background-clip') === 'border-box, content-box' && B.bgComponent(ML, 'background-color') === '#fff',
  'כמה שכבות, / בין מיקום לגודל, origin/clip, צבע בשכבה האחרונה');
ok(B.bgComponent('red green', 'background-color') === null && B.bgComponent('url(a) / cover', 'background-size') === null, 'תחביר שגוי — לא מפורק (null), לא מנחשים');
ok(ST.covered('background', true).includes('background-position') && ST.componentOf('background', HERO, 'background-size', true) === 'cover' && ST.setComponent('background', HERO, 'background-size', 'contain', true) === null,
  'style.js: covered(background) כולל את כל הרכיבים; componentOf קורא; setComponent נשאר null (לעולם לא מפרקים קיצור)');
let m = K.parseCss(lex), r = rule(m, '.kadisha-hero'), pr = ST.planRule(m, r, 'background-position', '30% 70%', true);
k2('K2 · רכיב בתוך קיצור background: הצהרה מפורשת מיד אחריו, באותו כלל; הקיצור לא נגעו בו', lex, pr.patches, out => pr.kind === 'after-shorthand' && /background: var\(--ka-navy\) center \/ cover no-repeat;\n\s+background-position: 30% 70%;/.test(out));

// ── 2. url() ──
const us = B.urls(`url( "a b).jpg" ), url(x.png), url('c\\'d.jpg'), linear-gradient(red, blue)`);
ok(us.length === 3 && us[0].url === 'a b).jpg' && us[0].q === '"' && us[1].url === 'x.png' && us[1].q === '' && us[1].layer === 1 && us[2].layer === 2, 'url(): עם / בלי מרכאות, רווחים, ) בתוך השם, שכבה של כל אחד', us);
ok(B.urlCss('../media/a b(1).jpg', "'") === "url('../media/a%20b%281%29.jpg')" && B.urlCss("x').jpg", '') === 'url(x%27%29.jpg)' && B.urlCss('data:image/png;base64,AAAA', '"') === 'url("data:image/png;base64,AAAA")', 'כתיבה: מרכאות / סוגריים / רווחים מקודדים; בסגנון המרכאות של הקובץ; data:image נשמר');
const bad = ["a');} body{color:red", '</style><script>alert(1)</script>', 'javascript:alert(1)', 'vbscript:x', 'data:text/html,<b>', 'data:text/html;base64,AA', 'a\nb', ''];
const thrown = bad.map(u => { try { const t = B.urlCss(u, "'"); return /[;{}]/.test(t.slice(5, -2).replace(/%[0-9A-F]{2}/g, '')) && /['")]/.test(t.slice(5, -2)) ? 'BREAK:' + t : 'ok:' + t; } catch { return 'rejected'; } });
ok(thrown[0].startsWith('ok:') && !/['"()]/.test(thrown[0].slice(8, -2)) && thrown.slice(1).every(x => x === 'rejected'), 'הזרקה: סגירת ) / ; / } — מקודדת ואינה סוגרת; </style>, javascript:, vbscript:, data שאינו תמונה, תו בקרה, ריקה — נדחות', thrown);
ok(B.relFrom('css/page.css', 'media/hero 2.jpg') === '../media/hero%202.jpg' && B.relFrom('index.html', 'media/a.jpg') === 'media/a.jpg' && B.relFrom('a/b/c.css', 'a/m.jpg') === '../m.jpg', 'נתיב יחסי לקובץ ה-CSS (../media/… מתוך css/)');
ok(B.quoteOf("a{background:url('x')} b{background:url('y')}") === "'" && B.quoteOf('a{background:url("x")}') === '"' && B.quoteOf('a{}') === "'", 'סגנון המרכאות של הקובץ');

// ── 3. הסרה ──
m = K.parseCss(page); r = rule(m, '.kadisha-lexicon-page .kadisha-hero.kadisha-hero--has-image');
let rm = B.planRemoveImage(m, r, true);
k2('K2 · מחיקת הצהרה: background-image נמחק מ-page.css; המשתנה --ka-hero-overlay והכלל נשארים', page, rm.patches, (out, m2) => rm.kind === 'decl' && !/background-image/.test(out) && /--ka-hero-overlay: 45;/.test(out) && !!rule(m2, '.kadisha-lexicon-page .kadisha-hero.kadisha-hero--has-image'));
const LAY = `.x {\n  background-image: url(a.jpg), linear-gradient(red, blue), url("b.jpg");\n  background-position: 0 0, center, 10% 20%;\n  background-size: cover;\n  background-repeat: no-repeat, repeat, repeat-x;\n}\n`;
m = K.parseCss(LAY); r = rule(m, '.x'); rm = B.planRemoveImage(m, r, true, [0]);
k2('K2 · הסרת שכבה מרשימה: רק url(a.jpg), עם הרכיב המקביל ב-position / repeat; size (ערך אחד) לא נוגעים', LAY, rm.patches, out => /background-image: linear-gradient\(red, blue\), url\("b.jpg"\);/.test(out) && /background-position: center, 10% 20%;/.test(out) && /background-size: cover;/.test(out) && /background-repeat: repeat, repeat-x;/.test(out));
m = K.parseCss(lex); r = rule(m, '.kadisha-hero');
ok(B.planRemoveImage(m, r, true) === null, 'קיצור בלי תמונה — אין מה להסיר (null), לא נוגעים בקיצור');
const SH = `.y { background: #123 url('h.jpg') center / cover no-repeat; color: red; }\n`;
m = K.parseCss(SH); r = rule(m, '.y'); rm = B.planRemoveImage(m, r, true);
k2('K2 · הסרה מתוך קיצור: הקיצור אינו נערך ואינו מאופס; background-image: none מיד אחריו', SH, rm.patches, out => rm.kind === 'after-shorthand' && out.includes("background: #123 url('h.jpg') center / cover no-repeat; background-image: none; color: red;"));

// ── 4. החלפה, הוספה, סדר, משתנה ──
m = K.parseCss(page); r = rule(m, '.kadisha-lexicon-page .kadisha-hero.kadisha-hero--has-image');
let d = r.decls.find(x => x.prop.trim() === 'background-image'), rp = B.planReplaceUrl(d, B.relFrom('css/page.css', 'media/new.jpg'));
k2("K2 · החלפת url() בתוך הצהרה: רק הכתובת, באותן מרכאות ('…')", page, rp.patches, out => out.includes("background-image: url('../media/new.jpg');") && out.includes('--ka-hero-overlay: 45;'));
m = K.parseCss(SH); r = rule(m, '.y'); d = r.decls[0]; rp = B.planReplaceUrl(d, 'media/x).jpg');
k2('K2 · החלפת url() בתוך קיצור background: המיקום, הגודל והצבע לא נוגעים; ) בשם מקודד', SH, rp.patches, out => out.includes("background: #123 url('media/x%29.jpg') center / cover no-repeat;"));
const VAR = `:root { --hero: url("img/a.jpg"); }\n.z { background-image: var(--hero); }\n`;
m = K.parseCss(VAR); r = rule(m, ':root'); d = r.decls[0]; rp = B.planReplaceUrl(d, 'img/b.png');
k2('K2 · שינוי הגדרת משתנה: url() שמגיע מ-var(--hero) נערך בהגדרה, באותן מרכאות', VAR, rp.patches, out => out.includes(':root { --hero: url("img/b.png"); }') && out.includes('background-image: var(--hero);'));
m = K.parseCss(page); r = rule(m, '.kadisha-lexicon-page .kadisha-hero.kadisha-hero--has-image');
const al = B.planAddLayer(m, r, true, 'linear-gradient(rgba(0, 0, 0, .5), rgba(0, 0, 0, .5))');
k2('K2 · הוספת שכבה (כיסוי חדש): linear-gradient כשכבה עליונה, לפני התמונה', page, al.patches, out => out.includes("background-image: linear-gradient(rgba(0, 0, 0, .5), rgba(0, 0, 0, .5)), url('https://maghreb.co.il/wp-content/uploads/hero.jpg');"));
m = K.parseCss(SH); r = rule(m, '.y');
const al2 = B.planAddLayer(m, r, true, 'linear-gradient(red, red)');
k2('K2 · הוספת שכבה כשהתמונה בקיצור: הצהרה מפורשת אחריו עם כל השכבות', SH, al2.patches, out => out.includes("no-repeat; background-image: linear-gradient(red, red), url('h.jpg'); color"));
m = K.parseCss(LAY); r = rule(m, '.x'); const ro = B.planReorder(m, r, true, [2, 0, 1]);
k2('K2 · שינוי סדר שכבות: נכתב בכל ההצהרות המקבילות יחד', LAY, ro.patches, out => /background-image: url\("b.jpg"\), url\(a.jpg\), linear-gradient\(red, blue\);/.test(out) && /background-position: 10% 20%, 0 0, center;/.test(out) && /background-repeat: repeat-x, no-repeat, repeat;/.test(out) && /background-size: cover;/.test(out));
ok(JSON.stringify(B.varsIn('rgba(10, 16, 32, var(--ka-hero-overlay))')) === '["--ka-hero-overlay"]', 'משתנה בתוך צבע הכיסוי — מזוהה');
let threw = ''; try { B.planReplaceUrl({ value: "url(a)", valFrom: 0 }, 'javascript:alert(1)'); } catch (e) { threw = e.code; }
ok(threw === 'invalid', 'החלפה בכתובת javascript: — נדחית לפני כל תיקון');

console.log('\n' + (failed ? '❌ ' + failed + ' מתוך ' + ran + ' נכשלו' : '✅ כל ' + ran + ' הבדיקות עברו'));
process.exit(failed ? 1 : 0);
