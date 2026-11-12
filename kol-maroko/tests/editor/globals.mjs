/**
 * פרוסה V9 — "סגנון כללי", החלק הטהור של assets/js/editor/globals.js:   node kol-maroko/tests/editor/globals.mjs
 * K2 לכל סוג כתיבה חדש: @font-face ב-tokens.css · <link> ב-<head> · הסרת <link> (שלם / משפחה אחת מתוך href) · משתנה חדש
 * ב-:root · שינוי ערך משתנה · החלפת ערך ב-var() ב-N מקומות · שינוי שם משתנה (CSS ו-HTML) · כלל תגית ב-base.css · החלפת גופן.
 * בכל אחד: ה-diff בשורות שנוגעות בלבד, ביטול מחזיר קובץ זהה בייט בבייט, ניתוח מחדש בלי שבירה, ושקילות המודל ההדרגתי
 * (KolSrc.update) לניתוח מלא — לפני ואחרי הביטול. ובדיקות הזרקה: } ; < url(javascript: ומרכאות.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/style.js', 'assets/js/editor/globals.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;');
const K = globalThis.KolSrc, G = globalThis.KolGlobals;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const lines = (a, b) => { const x = a.split('\n'), y = b.split('\n'); let s = 0; while (s < x.length && x[s] === y[s]) s++; let e = 0; while (e < x.length - s && e < y.length - s && x[x.length - 1 - e] === y[y.length - 1 - e]) e++; return { at: s + 1, old: x.length - s - e, neu: y.length - s - e, text: y.slice(s, y.length - e).join('\n') }; };
const throwsIn = fn => { try { fn(); return false; } catch (e) { return e.code === 'input' || e instanceof K.SrcError; } };
/** K2: diff, ביטול זהה, ניתוח מחדש בלי שבירה, ושקילות המודל (CSS או HTML). */
function k2(name, src, ps, want, html) {
  const parse = html ? K.parseHtml : K.parseCss, out = K.applyPatches(src, ps), d = lines(src, out);
  const back = K.applyPatches(out, K.invertPatches(src, ps));
  // שקילות המודל: ב-HTML — המודל ההדרגתי מול ניתוח מלא (לפני ואחרי הביטול); ב-CSS אין מודל הדרגתי (ניתוח מלא בכל שינוי)
  let e1 = null, e2 = null; if (html) { const inc = K.update(parse(src), ps).model; e1 = K.sameModel(inc, parse(out)); e2 = K.sameModel(K.update(inc, K.invertPatches(src, ps)).model, parse(src)); }
  const broken = html ? false : K.cssAll(parse(out)).some(n => n.broken) !== K.cssAll(parse(src)).some(n => n.broken);
  ok(ps.length && back === src && !broken && e1 == null && e2 == null && want(d, out), name, { d, e1, e2, ps: ps.slice(0, 3) });
  return out;
}
const lex = fs.readFileSync(path.join(here, 'fixtures/lexicon.css'), 'utf8'), page = fs.readFileSync(path.join(here, 'fixtures/page.css'), 'utf8'), idx = fs.readFileSync(path.join(here, 'fixtures/index.html'), 'utf8');
const tokens = "/* משתנים */\n:root {\n  --ka-navy: #14213d;\n  --color-text: #16192b;\n  --font-body: 'Assistant', sans-serif;\n  --gap: 16px;\n}\n@media (max-width: 767px) {\n  :root { --gap: 10px; }\n}\n";
const base = "/* סגנון לפי תגית */\nbody { margin: 0; font-family: var(--font-body); }\nh1, h2, h3 { line-height: 1.25; }\na { color: var(--color-accent); }\n";

// ── 1. איסוף המשתנים ──
const sheets = [{ path: 'css/lexicon.css', m: K.parseCss(lex) }, { path: 'css/tokens.css', m: K.parseCss(tokens) }];
const vars = G.collectVars(sheets);
const navy = vars.get('--ka-navy'), gap = vars.get('--gap');
ok(navy && navy.defs.length === 2 && G.mainDef(navy).path === 'css/tokens.css' && G.mainDef(navy).value === '#14213d', '--ka-navy: שתי הגדרות (lexicon.css, tokens.css); הקובעת — האחרונה ב-:root (tokens.css)', navy && navy.defs.map(d => d.path));
ok(gap && gap.defs.length === 2 && gap.defs[1].media === '@media (max-width: 767px)' && !gap.defs[1].root && G.mainDef(gap).value === '16px', '--gap מוגדר מחדש בתוך @media — מוצג עם כל ההגדרות', gap && gap.defs.map(d => [d.media, d.value]));
ok(vars.has('--ka-hero-overlay') && vars.get('--ka-radius').defs[0].line === 9, 'כל --ka-* של הערכה (לא רק tokens.css), עם שורה');
ok(G.kindOf('#14213d') === 'color' && G.kindOf('rgb(1 2 3 / .5)') === 'color' && G.kindOf("'Assistant', sans-serif") === 'font' && G.kindOf('14px') === 'size' && G.kindOf('clamp(1rem, 2vw, 2rem)') === 'size' && G.kindOf('.4') === 'size' && G.kindOf('url(x.png)') === 'other', 'סוג לפי הערך: צבע · גופן · מידה · אחר');
const uses = G.usages([{ path: 'css/lexicon.css', text: lex }], '--ka-navy');
ok(uses.length === (lex.match(/var\(--ka-navy\)/g) || []).length && uses.length >= 3 && G.usages([{ path: 'x', text: 'a{color:var(--ka-navy-2)}' }], '--ka-navy').length === 0, '"איפה בשימוש": כל var(--ka-navy) — לא --ka-navy-2', uses.length);

// ── 2. שינוי ערך משתנה ──
{ const m = K.parseCss(tokens), d = G.mainDef(G.collectVars([{ path: 't', m }]).get('--ka-navy'));
  k2('K2 שינוי ערך משתנה: שורה אחת', tokens, G.planSetVar(m, d, '#0a3d62'), (x, out) => x.old === 1 && x.neu === 1 && /--ka-navy: #0a3d62;/.test(x.text) && G.collectVars([{ path: 't', m: K.parseCss(out) }]).get('--ka-navy').defs[0].value === '#0a3d62'); }

// ── 3. משתנה חדש ב-:root ──
k2('K2 משתנה חדש: שורה אחת בתוך :root הקיים, באותה הזחה', tokens, G.planNewVar(K.parseCss(tokens), '--color-hero-title', '#a3322a'), (x, out) => x.old === 0 && x.neu === 1 && x.text === '  --color-hero-title: #a3322a;' && /--gap: 16px;\n  --color-hero-title/.test(out));
k2('K2 משתנה חדש בקובץ בלי :root — כלל :root חדש בראש הקובץ (אחרי ההערה)', "/* x */\n.a { color: red; }\n", G.planNewVar(K.parseCss("/* x */\n.a { color: red; }\n"), '--gap', '16px'), (x, out) => /^\/\* x \*\/\n:root \{ --gap: 16px; \}\n\.a/.test(out));
ok(throwsIn(() => G.planNewVar(K.parseCss(tokens), '--ka-navy', 'red')), 'משתנה שכבר קיים בקובץ — נדחה');

// ── 4. החלפת ערך ב-var() ב-N מקומות ──
{ const r = G.planReplaceValue(K.parseCss(page), '#a3322a', '--color-accent');
  const n = (page.match(/:\s*#a3322a\s*[;}]/gi) || []).length;
  if (n) k2('K2 "החלף גם ב-' + r.hits.length + ' מקומות": כל הצהרה עם הערך ← var(--color-accent); שורות אחרות לא נוגעות', page, r.patches, (x, out) => !/#a3322a/i.test(out.split('\n').filter(l => !/--/.test(l)).join('\n')) && r.hits.length === n);
  const src = '.a { color: #A3322A; }\n.b { background: #a3322a; border-color: #a3322a; }\n.c { --x: #a3322a; color: #a3322b; }\n';
  const r2 = G.planReplaceValue(K.parseCss(src), '#a3322a', '--color-accent', null, (rule, d) => rule.sel === '.a');
  k2('K2 החלפה: 2 הצהרות (לא הנוכחית, לא הגדרת משתנה, לא ערך דומה)', src, r2.patches, (x, out) => r2.hits.length === 2 && out === '.a { color: #A3322A; }\n.b { background: var(--color-accent); border-color: var(--color-accent); }\n.c { --x: #a3322a; color: #a3322b; }\n'); }

// ── 5. שינוי שם ──
{ const ps = G.planRename(lex, '--ka-navy', '--brand-navy');
  k2('K2 שינוי שם: ההגדרה וכל var() — ולא --ka-navy-x', lex, ps, (x, out) => !/--ka-navy(?![\w-])/.test(out) && (out.match(/--brand-navy/g) || []).length === (lex.match(/--ka-navy(?![\w-])/g) || []).length);
  const html = '<div style="color: var(--ka-navy)">--ka-navy בטקסט</div><style>.x{background:var(--ka-navy)}</style>';
  k2('K2 שינוי שם ב-HTML: רק בתוך style="" ו-<style>, לא בטקסט', html, G.planRename(html, '--ka-navy', '--brand-navy', true), (x, out) => out === '<div style="color: var(--brand-navy)">--ka-navy בטקסט</div><style>.x{background:var(--brand-navy)}</style>', true);
  ok(throwsIn(() => G.planRename(lex, '--ka-navy', 'brand')) && throwsIn(() => G.planRename(lex, '--ka-navy', '--a;b')) && throwsIn(() => G.planRename(lex, '--ka-navy', '--a}b')), 'שם חדש לא חוקי — נדחה'); }

// ── 6. @font-face ──
{ const f = { family: 'Heebo', weight: '700', style: 'normal', url: '../media/fonts/heebo-700.woff2', format: 'woff2' };
  const out = k2('K2 @font-face ב-tokens.css: לפני :root, עם font-display: swap ו-src יחסי', tokens, G.planFontFace(K.parseCss(tokens), f), (x, o) => x.old === 0 && /@font-face \{\n  font-family: 'Heebo';\n  src: url\('\.\.\/media\/fonts\/heebo-700\.woff2'\) format\('woff2'\);\n  font-weight: 700;\n  font-style: normal;\n  font-display: swap;\n\}/.test(o) && o.indexOf('@font-face') < o.indexOf(':root'));
  const out2 = k2('K2 @font-face שני — אחרי הראשון', out, G.planFontFace(K.parseCss(out), { ...f, weight: '400', url: '../media/fonts/heebo-400.woff2', range: 'U+0590-05FF, U+20AA' }), (x, o) => o.indexOf('heebo-400') > o.indexOf('heebo-700') && o.indexOf('heebo-400') < o.indexOf(':root') && /unicode-range: U\+0590-05FF, U\+20AA;/.test(o));
  const ff = G.fontFaces(K.parseCss(out2));
  ok(ff.length === 2 && ff[0].family === 'Heebo' && ff[1].weight === '400' && ff[1].url === '../media/fonts/heebo-400.woff2', 'fontFaces קורא את מה שנכתב', ff.map(x => [x.family, x.weight, x.url]));
  const inj = [{ family: "Heebo'; } body { color: red" }, { family: 'He<b>' }, { family: 'A;B' }, { family: 'x)' }, { url: "x.woff2') format('woff2'); } body{x:url(javascript:alert(1))" }, { url: 'javascript:alert(1)' }, { url: '/etc/x.woff2' }, { url: 'https://evil.example/x.woff2' }, { url: '../media/../../x.woff2' },
    { weight: '700; color: red' }, { weight: '}' }, { format: "woff2') ; x:(" }, { range: 'U+0590; } a { color: red' }];
  ok(inj.every(o => throwsIn(() => G.fontFaceDecls({ ...f, ...o }))), 'הזרקה ל-@font-face (} ; < מרכאות url(javascript: נתיב מוחלט / חיצוני / .. ) — כולן נדחות'); }

// ── 7. <link> של Google ──
{ const href = 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&display=swap';
  const out = k2('K2 <link> ל-Google ב-<head>: שורה אחת, אחרי ה-<link> הקיים של Google, &amp;', idx, G.planLinkInsert(idx, href), (x, o) => x.old === 0 && x.neu === 1 && x.text === '  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&amp;display=swap">' && x.at === 11, true);
  ok(G.planLinkInsert(out, href).length === 0, 'כבר קיים — אין תיקון');
  const plain = '<!doctype html>\n<html>\n<head>\n  <title>x</title>\n  <link rel="stylesheet" href="css/page.css">\n</head>\n<body></body>\n</html>\n';
  k2('K2 <link> בעמוד בלי Google — לפני ה-stylesheet הראשון', plain, G.planLinkInsert(plain, href), (x, o) => /<title>x<\/title>\n  <link rel="stylesheet" href="https:\/\/fonts\.googleapis\.com[^"]+">\n  <link rel="stylesheet" href="css\/page\.css">/.test(o), true);
  ok(['https://evil.example/css2?family=X', 'https://fonts.googleapis.com/css2?family=X"><script>', "https://fonts.googleapis.com/css2?family=X' onload='x", 'javascript:alert(1)'].every(u => throwsIn(() => G.planLinkInsert(plain, u))), 'href שאינו של Google / עם מרכאות — נדחה');
  const gl = G.googleLinks(idx);
  ok(gl.length === 1 && gl[0].fams.map(f => f.name).join() === 'Frank Ruhl Libre,Assistant' && gl[0].fams[1].weights.join() === '300,400,600,700', 'googleLinks: שתי משפחות עם המשקלים', gl.map(l => l.fams));
  k2('K2 הסרת משפחה אחת מ-href של כמה משפחות — רק family=Assistant…', idx, G.planLinkDrop(idx, 'Assistant'), (x, o) => x.old === 1 && /href="https:\/\/fonts\.googleapis\.com\/css2\?family=Frank\+Ruhl\+Libre:wght@300;400;500;700;900&amp;display=swap"/.test(o), true);
  k2('K2 הסרת <link> של משפחה יחידה — כל השורה', out, G.planLinkDrop(out, 'Heebo'), (x, o) => o === idx, true);
  const vr = G.googleLinks('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Rubik:ital,wght@0,300..900;1,300..900&display=swap">')[0].fams[0];
  ok(vr.italic && vr.range && vr.range.join() === '300,900', 'גופן משתנה (טווח 300..900) ונטוי', vr); }

// ── 8. גופן: איפה בשימוש, והחלפה בכל מקום ──
{ const src = ".a { font-family: 'Assistant', sans-serif; }\n.b { font-family: Arial; }\n.c { font: 700 16px/1.2 'Assistant', serif; }\n:root { --font-body: \"Assistant\", sans-serif; }\n";
  const u = G.familyUses(K.parseCss(src), 'assistant');
  ok(u.length === 3 && u.map(x => x.decl.prop).join() === 'font-family,font,--font-body', 'familyUses: font-family, font, ומשתנה גופן', u.map(x => x.decl.prop));
  k2('K2 "החלף בגופן אחר בכל מקום" — font-family ומשתנה (font המקוצר — מוצג, לא נכתב)', src, G.planReplaceFamily(K.parseCss(src), 'Assistant', "'Heebo'"), (x, o) => o === ".a { font-family: 'Heebo', sans-serif; }\n.b { font-family: Arial; }\n.c { font: 700 16px/1.2 'Assistant', serif; }\n:root { --font-body: 'Heebo', sans-serif; }\n"); }

// ── 9. כלל תגית ב-base.css (Styler — אותו מסלול כמו V8) ──
{ const ST = globalThis.KolStyle, m = K.parseCss(base);
  const r = K.cssAll(m).find(n => n.type === 'rule' && n.sel === 'h1, h2, h3');
  k2('K2 כלל תגית חדש h2 ב-base.css (planNewRule)', base, ST.planNewRule(m, 'h2', [['font-size', '28px']], true).patches, (x, o) => x.old === 0 && x.neu === 1 && /\nh2 \{ font-size: 28px; \}\n$/.test(o));
  ok(!!r && ST.checkValue('font-size', '28px') === undefined, 'checkValue עובר ל-28px'); }

// ── 10. ערכים: הזרקה ──
ok(['red; } body { x: y', 'a{', '<x>', 'url(javascript:alert(1))', 'calc(1px', '"x', 'red /* x */', ''].every(v => throwsIn(() => G.checkRaw(v))) && G.checkRaw(' #fff ') === '#fff' && G.checkRaw("'Heebo', sans-serif") === "'Heebo', sans-serif", 'checkRaw: } ; < url(javascript: סוגריים ומרכאות פתוחים — נדחים');
ok(G.familyValue('Heebo', 'serif') === "'Heebo', serif" && G.familyValue('system-ui') === 'system-ui, sans-serif' && throwsIn(() => G.familyValue("He'bo")), 'familyValue: גופן גיבוי, ובלי מרכאות בשם');
ok(G.suggestName('color', 'kadisha-hero h1', 'color', new Set(['--color-kadisha-hero-h1'])) === '--color-kadisha-hero-h1-2' && G.suggestName('color', 'hero', 'background-color') === '--color-hero-bg', 'שם מוצע מהקשר: --color-hero-title, בלי התנגשות');

console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
process.exit(failed ? 1 : 0);
