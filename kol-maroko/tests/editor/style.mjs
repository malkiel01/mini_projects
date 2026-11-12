/**
 * כתיבת CSS (V3) — החלק הטהור של assets/js/editor/style.js:   node kol-maroko/tests/editor/style.mjs
 * K2 לכל סוג כתיבה חדש: הצהרה בכלל קיים, הצהרה חדשה בכלל, רכיב בתוך קיצור (margin, padding, font, gap), כלל חדש,
 * תכונת style, ועסקה בשני קבצים (מחלקה ב-HTML + כלל ב-CSS). בכל אחת: ה-diff בשורות של ההצהרה בלבד, ביטול מחזיר
 * קובץ זהה בייט בבייט, והקובץ החדש נקרא (ניתוח מלא) עם הערך החדש במקום הנכון. הקבצים — של העמוד המוסב (F3).
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/style.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;');
const K = globalThis.KolSrc, ST = globalThis.KolStyle;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const lines = (a, b) => { const x = a.split('\n'), y = b.split('\n'); let s = 0; while (s < x.length && x[s] === y[s]) s++; let e = 0; while (e < x.length - s && e < y.length - s && x[x.length - 1 - e] === y[y.length - 1 - e]) e++; return { at: s + 1, old: x.length - s - e, neu: y.length - s - e, text: y.slice(s, y.length - e).join('\n') }; };
const page = fs.readFileSync(path.join(here, 'fixtures/page.css'), 'utf8'), lex = fs.readFileSync(path.join(here, 'fixtures/lexicon.css'), 'utf8');
const rule = (m, sel) => K.cssAll(m).find(n => n.type === 'rule' && n.sel.replace(/\s+/g, ' ').trim() === sel);
/** כתיבה אחת: מחזירה את הטקסט החדש, ובודקת K2 (שורות, ביטול, ניתוח מחדש). */
function k2(name, src, ps, want) {
  const out = K.applyPatches(src, ps), back = K.applyPatches(out, K.invertPatches(src, ps)), d = lines(src, out), m2 = K.parseCss(out);
  const broken = K.cssAll(m2).some(n => n.broken) !== K.cssAll(K.parseCss(src)).some(n => n.broken);
  ok(back === src && !broken && want(d, out, m2), name, { d, ps });
  return out;
}

// ── 1. מיפוי לוגי ↔ פיזי ──
ok(JSON.stringify(ST.covered('margin-inline-start', true)) === '["margin-right"]' && JSON.stringify(ST.covered('margin-inline-start', false)) === '["margin-left"]' && ST.covered('margin', true).length === 4 && JSON.stringify(ST.covered('padding-block', true)) === '["padding-top","padding-bottom"]',
  'מיפוי: margin-inline-start ← ימין ב-RTL / שמאל ב-LTR; margin ← ארבעה צדדים; padding-block ← עליון ותחתון');
ok(ST.logical('margin-top', true) === 'margin-block-start' && ST.logical('margin-right', true) === 'margin-inline-start' && ST.logical('margin-right', false) === 'margin-inline-end' && ST.logical('font-size', true) === 'font-size', 'הצהרה חדשה — בשם לוגי (margin-block-start, margin-inline-start)');

// ── 2. רכיב בתוך קיצור ──
const sc = (p, v, phys, nv) => ST.setComponent(p, v, phys, nv, true);
ok(sc('margin', '0 0 .5em', 'margin-bottom', '0') === '0 0 0' && sc('margin', '0', 'margin-top', '5px') === '5px 0 0' && sc('margin', '1px 2px', 'margin-right', '3px') === '1px 3px 1px 2px' && sc('margin', '-.1em 0 1.1rem', 'margin-top', '0') === '0 0 1.1rem',
  'margin: הרכיב משתנה בתוך הקיצור, באותו מספר ערכים או במינימום הנדרש (0 0 .5em ← 0 0 0; 0 ← 5px 0 0)');
ok(sc('padding', '.15rem 1.5rem', 'padding-left', '2rem') === '.15rem 1.5rem .15rem 2rem' && sc('padding-inline', '1rem', 'padding-right', '0') === '0 1rem', 'padding / padding-inline: הרכיב הנכון (ב-RTL ההתחלה היא ימין)');
ok(sc('font', "italic 700 16px/1.2 'Frank Ruhl Libre', serif", 'font-size', '20px') === "italic 700 20px/1.2 'Frank Ruhl Libre', serif" && sc('font', '16px Arial', 'line-height', '1.5') === '16px/1.5 Arial' && sc('font', 'bold 16px Arial', 'font-weight', '400') === '400 16px Arial' && sc('font', '16px Arial', 'font-style', 'italic') === 'italic 16px Arial',
  'font: גודל, גובה שורה, משקל וסגנון — בתוך הקיצור');
ok(sc('gap', '.75rem', 'column-gap', '0') === '.75rem 0' && sc('gap', '1rem 2rem', 'row-gap', '0') === '0 2rem' && sc('margin', 'var(--x)', 'margin-top', '0') === null, 'gap: שורות ועמודות; var() שעשוי להיות כמה ערכים — לא נוגעים בו (נכתב מאפיין מפורט אחריו)');
ok(ST.componentOf('margin', '-.1em 0 1.1rem', 'margin-bottom', true) === '1.1rem' && ST.componentOf('font', "700 36px/1.1 x", 'line-height', true) === '1.1', 'קריאה: הרכיב של צד בתוך הקיצור');

// ── 3. K2 על page.css של העמוד המוסב ──
let m = K.parseCss(page), r = rule(m, '.kadisha-lexicon-page .kadisha-hero-subtitle-main');
let res = ST.planRule(m, r, 'margin-top', '0', true);
k2('page.css: רכיב בתוך קיצור קיים (margin: -.1em 0 1.1rem ← 0 0 1.1rem) — שורה אחת, "margin-bottom" לא נוסף לצידו', page, res.patches, d => d.old === 1 && d.neu === 1 && d.text.includes('margin: 0 0 1.1rem;') && !d.text.includes('margin-top') && res.kind === 'component');
r = rule(m, '.kadisha-lexicon-page .kadisha-hero-subtitle-main'); res = ST.planRule(m, r, 'padding-right', '4px', true);
k2('page.css: padding: 0 ← הרכיב בקיצור (padding: 0 4px 0 0)', page, res.patches, d => d.text.includes('padding: 0 4px 0 0;'));
r = rule(m, '.kadisha-lexicon-page .kadisha-hero-subtitle-main'); res = ST.planRule(m, r, 'font-size', '40px', true);
k2('page.css: הצהרה קיימת (font-size: 36px ← 40px) — רק הערך', page, res.patches, d => d.old === 1 && d.neu === 1 && d.text.includes('font-size: 40px;') && res.kind === 'value');
r = rule(m, '.kadisha-lexicon-page .kadisha-hero-credit'); res = ST.planRule(m, r, 'color', '#fff', true);
k2('page.css: הצהרה חדשה בכלל של שורה אחת — בסגנון הכלל (באותה שורה, "; ")', page, res.patches, d => d.old === 1 && d.neu === 1 && /opacity: \.92; color: #fff; \}$/.test(d.text) && res.kind === 'add');
r = rule(m, '.kadisha-lexicon-page .kadisha-lexicon-intro'); res = ST.planRule(m, r, 'margin-bottom', '0', true);
k2('page.css: הצהרה חדשה בכלל רב-שורות — שורה חדשה, באותה הזחה, בשם לוגי', page, res.patches, d => d.old === 0 && d.neu === 1 && d.text === '    margin-block-end: 0;');
r = rule(m, '.kadisha-lexicon-page .kadisha-hero-title, .kadisha-lexicon-page .kadisha-hero h1'); res = ST.planReset(m, r, 'margin-bottom', true);
k2('page.css: איפוס מוחק את ההצהרה (margin-bottom: 0), לא כותב ברירת מחדל', page, res.patches, d => d.old === 1 && d.neu === 1 && !d.text.includes('margin-bottom') && /line-height: 1\.15; \}$/.test(d.text));
res = ST.planNewRule(m, '.hero-title-2', [['margin-top', '0']], true);
k2('page.css: כלל חדש בסוף הקובץ, בסגנון הקובץ, במאפיין לוגי', page, res.patches, (d, out) => out.endsWith('.hero-title-2 {\n    margin-block-start: 0;\n}\n') || /\.hero-title-2 \{[^}]*margin-block-start: 0;[^}]*\}\s*$/.test(out));
const one = 'a { color: red }\n.b { margin: 0 0 .5em; }\n';
let m1 = K.parseCss(one); res = ST.planReset(m1, rule(m1, 'a'), 'color', true);
k2('איפוס של ההצהרה האחרונה בכלל — הכלל נשאר ריק, ואינו נמחק', one, res.patches, (d, out) => /^a \{\s*\}/.test(out));
m1 = K.parseCss(one); res = ST.planRule(m1, rule(m1, '.b'), 'margin-bottom', '0', true);
k2('.b { margin: 0 0 .5em } — ה-margin-bottom נקבע בתוך הקיצור (0 0 0)', one, res.patches, (d, out) => out.includes('.b { margin: 0 0 0; }'));
const imp = '.t { margin-bottom: 12px !important; }\n';
let mi = K.parseCss(imp); res = ST.planRule(mi, rule(mi, '.t'), 'margin-bottom', '0', true);
k2('!important קיים נשמר בעריכה (העורך אינו מוסיף ואינו מוחק אותו מעצמו)', imp, res.patches, (d, out) => out.includes('margin-bottom: 0 !important;'));
mi = K.parseCss(imp); res = ST.planUnimportant(mi, rule(mi, '.t'), 'margin-bottom', true);
k2('"הסר !important" — כפתור, ורק הוא מוחק', imp, res.patches, (d, out) => out.includes('margin-bottom: 12px;'));
// lexicon.css: קיצור margin בכלל של המחלקה
let ml = K.parseCss(lex), rl = rule(ml, '.kadisha-hero-title'); res = ST.planRule(ml, rl, 'margin-bottom', '0', true);
k2('lexicon.css: .kadisha-hero-title { margin: 0 0 .4rem } ← 0 0 0', lex, res.patches, d => d.old === 1 && d.text.includes('margin: 0 0 0;'));

// ── 4. תכונת style ──
ok(ST.planInline('', 'margin-top', '0', true) === 'margin-block-start: 0' && ST.planInline('color: red', 'margin-top', '0', true) === 'color: red; margin-block-start: 0' && ST.planInline('margin: 4px', 'margin-left', '0', true) === 'margin: 4px 4px 4px 0' && ST.planInline('color: red; margin-top: 3px', 'margin-top', '0', true, true) === 'color: red',
  'style="": הוספה, רכיב בקיצור, ואיפוס — באותו מנוע');

// ── 5. עסקה בשני קבצים: מחלקה חדשה ב-HTML + כלל ב-CSS ──
const html = '<!doctype html><html><head><link rel="stylesheet" href="css/page.css"></head><body>\n<div class="hero">\n  <h2>כותרת</h2>\n</div>\n</body></html>\n';
const mh = K.parseHtml(html), h2 = K.q(mh, 'h2'), hp = K.html.addClass(mh, h2, 'hero-title').patches, hOut = K.applyPatches(html, hp);
const cssOut = K.applyPatches(page, ST.planNewRule(K.parseCss(page), '.hero-title', [['margin-bottom', '0']], true).patches);
ok(lines(html, hOut).text === '  <h2 class="hero-title">כותרת</h2>' && K.applyPatches(hOut, K.invertPatches(html, hp)) === html && /\.hero-title \{[^}]*margin-block-end: 0;/.test(cssOut) && K.q(K.parseHtml(hOut), '.hero-title') !== null,
  'שני קבצים: ב-HTML נוספה מחלקה (שורה אחת), ב-CSS נוסף כלל — ושניהם חוזרים בביטול');

// ── 6. קלט שמנסה לצאת מההצהרה / מהתכונה / מתגית <style> ──
const bad = ['"</style><img src=x onerror=alert(1)>"', "a, '</STYLE >'", 'var(--a, "</style><script>x</script>")', '<b>'];
ok(bad.every(v => { try { ST.checkValue('font-family', v); return false; } catch (e) { return e instanceof K.SrcError && /</.test(e.message); } }), 'checkValue: כל ערך עם < נדחה (SrcError בעברית) — גם כשהוא מחרוזת CSS חוקית');
ok((() => { try { ST.checkValue('margin-top', '0'); ST.checkValue('color', 'var(--ka-cream)'); ST.checkValue('x', null); return true; } catch { return false; } })(), 'checkValue: ערכים רגילים ואיפוס (null) עוברים');
const qh = '<!doctype html><html><body>\n<p id="q">x</p>\n</body></html>\n', qm = K.parseHtml(qh), qn = K.q(qm, '#q');
const qs = ST.planInline('', 'font-family', '"a" onmouseover="alert(1)', true), qo = K.applyPatches(qh, K.html.setAttr(qm, qn, 'style', qs).patches), qn2 = K.q(K.parseHtml(qo), '#q');
ok(qn2 && qn2.attrs.length === 2 && !qn2.attrs.some(a => a.name === 'onmouseover') && qo.includes('&quot;'), 'style="": מרכאות בערך נשארות ישות (&quot;) — אין יציאה מהתכונה ואין onmouseover', qo.split('\n')[1]);

console.log('\n' + (failed ? '❌ ' + failed + ' מתוך ' + ran + ' נכשלו' : '✅ כל ' + ran + ' הבדיקות עברו'));
process.exit(failed ? 1 : 0);
