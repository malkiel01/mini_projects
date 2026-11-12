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

// ── V6: קיצורים של מיקום, flex, יישור, grid, overflow ──
ok(JSON.stringify(ST.covered('inset', true)) === '["top","right","bottom","left"]' && JSON.stringify(ST.covered('inset-inline-start', true)) === '["right"]' && JSON.stringify(ST.covered('inset-inline-start', false)) === '["left"]' && JSON.stringify(ST.covered('inset-block-end', true)) === '["bottom"]' && JSON.stringify(ST.covered('inset-inline', true)) === '["right","left"]',
  'V6 · inset / inset-inline / inset-block-end ← top/right/bottom/left לפי dir');
ok(ST.logical('top', true) === 'inset-block-start' && ST.logical('right', true) === 'inset-inline-start' && ST.logical('left', true) === 'inset-inline-end' && ST.logical('left', false) === 'inset-inline-start' && ST.logical('width', true) === 'width',
  'V6 · הצהרה חדשה — בשם לוגי (top ← inset-block-start; right ב-RTL ← inset-inline-start); width נשאר פיזי');
ok(sc('inset', '0', 'bottom', '10%') === '0 0 10%' && sc('inset-inline', '0 auto', 'left', '5px') === '0 5px' && sc('flex', '1 1 0', 'flex-basis', '30%') === '1 1 30%' && sc('flex', '1', 'flex-grow', '2') === '2 1 0%' && sc('flex', 'none', 'flex-grow', '1') === '1 0 auto' && sc('flex', 'var(--f)', 'flex-grow', '1') === null,
  'V6 · setComponent: inset (ארבעה), inset-inline, flex (1 ← 1 1 0%, none ← 0 0 auto); var() — null');
ok(sc('flex-flow', 'row wrap', 'flex-wrap', 'nowrap') === 'row nowrap' && sc('place-items', 'center', 'justify-items', 'start') === 'center start' && sc('overflow', 'hidden', 'overflow-y', 'auto') === 'hidden auto' && sc('grid-area', '1 / 2', 'grid-row-start', '3') === null && sc('grid-column', '1 / 3', 'grid-column-end', '4') === null && sc('grid-template', 'auto / 1fr', 'grid-template-rows', '1fr') === null,
  'V6 · flex-flow, place-items, overflow — בתוך הקיצור; grid-area / grid-column / grid-template — null (נכתב אחרי הקיצור)');
ok(ST.componentOf('flex', '1 1 0', 'flex-basis', true) === '0' && ST.componentOf('flex', 'auto', 'flex-grow', true) === '1' && ST.componentOf('grid-column', '1 / 3', 'grid-column-end', true) === '3' && ST.componentOf('inset', '0 auto', 'left', true) === 'auto' && ST.componentOf('place-content', 'center end', 'justify-content', true) === 'end',
  'V6 · componentOf: flex, grid-column, inset, place-content');
{ const src = '.a {\n  position: absolute;\n  inset: 0 auto auto 0;\n}\n', m6 = K.parseCss(src), r6 = rule(m6, '.a'), res = ST.planRule(m6, r6, 'bottom', '5%', true);
  k2('V6 · K2 · רכיב בתוך קיצור inset: הערך נערך בתוך הקיצור', src, res.patches, d => d.old === 1 && d.neu === 1 && d.text === '  inset: 0 auto 5% 0;'); }
{ const src = '.a { color: red; }\n', m6 = K.parseCss(src), r6 = rule(m6, '.a'), res = ST.planRule(m6, r6, 'right', '10%', true);
  k2('V6 · K2 · היסט חדש — inset-inline-start (לוגי) בכלל קיים', src, res.patches, (d, out) => out === '.a { color: red; inset-inline-start: 10%; }\n'); }


// ── V7: מכשירים — K2 לכל סוג כתיבה חדש, על page.css של העמוד המוסב ──
{
  /** טקסט ← תיקון אחד (ה-diff), ו-K2 עליו. */
  const asPatch = (a, b) => { let i = 0; while (i < a.length && a[i] === b[i]) i++; let j = 0; while (j < a.length - i && j < b.length - i && a[a.length - 1 - j] === b[b.length - 1 - j]) j++; return [{ from: i, to: a.length - j, text: b.slice(i, b.length - j) }]; };
  const SEL = '.kadisha-lexicon-page .kadisha-hero-subtitle-main', base0 = rule(K.parseCss(page), SEL);
  const dev = (src, sets, sel = SEL, max = 767) => { const b = K.cssAll(K.parseCss(src)).find(r => r.type === 'rule' && r.sel === sel && r.parent.type !== 'at'); return ST.planDevice(src, { sel, max, baseFrom: b ? b.from : -1, rtl: true, sets }).text; };
  ok(ST.mediaMax('(max-width: 767px)') === 767 && ST.mediaMax('(width <= 767px)') === 767 && ST.mediaMax('(  max-width:767px )') === 767 && ST.mediaMax('(767px >= width)') === 767
    && ST.mediaMax('(min-width: 767px)') === null && ST.mediaMax('screen and (max-width: 767px)') === null && ST.mediaMax('print') === null && ST.mediaMax('(prefers-reduced-motion: reduce)') === null && ST.mediaMax('(max-width: 47em)') === null,
    'V7 · נקודת שבירה = התנאי, בדיוק: max-width / width <= (רווחים לא משנים) — מכשיר; min-width, screen and, print, prefers-…, em — אינם מכשיר');
  ok(ST.ruleMax({ media: '@media (max-width: 767px)' }) === 767 && ST.ruleMax({ media: '@supports (display: grid) · @media (max-width: 767px)' }) === null && ST.ruleMax({ media: '@media (max-width: 1024px) · @media (max-width: 767px)' }) === null && ST.ruleMax({ media: '' }) === null,
    'V7 · @media מקונן ו-@supports סביב @media — נקראים כתנאי (אינם מכשיר), ומוצגים ונערכים כמו שהם');
  // 1. בלוק חדש מיד אחרי הכלל הבסיסי
  const t1 = dev(page, [['font-size', '28px']]);
  k2('V7 · K2 · בלוק @media חדש — מיד אחרי הכלל הבסיסי, בסגנון הקובץ', page, asPatch(page, t1), (d, out, m2) => {
    const b = K.cssAll(m2).find(n => n.type === 'at' && ST.mediaMax(n.sel) === 767), base = rule(m2, SEL);
    return b && b.parent === m2.root && b.kids.length === 1 && b.kids[0].sel === SEL && b.kids[0].decls[0].value === '28px' && m2.root.kids.indexOf(b) === m2.root.kids.indexOf(base) + 1 && d.old === 0 && d.neu === 3 && /^@media \(max-width: 767px\) \{\n    \.kadisha-lexicon-page \.kadisha-hero-subtitle-main \{ font-size: 28px; \}\n\}$/.test(d.text);
  });
  // 2. הצהרה בכלל קיים בתוך @media
  const t2 = dev(t1, [['font-size', '26px'], ['margin-bottom', '0']]);
  k2('V7 · K2 · הצהרה בכלל קיים בתוך @media (ערך שמשתנה, והצהרה חדשה בשם לוגי)', t1, asPatch(t1, t2), (d, out) => d.old === 1 && d.neu === 1 && / \{ font-size: 26px; margin-block-end: 0; \}$/.test(d.text) && out.split('@media (max-width: 767px)').length === 2);
  // 3. כלל חדש בתוך בלוק קיים (אלמנט אחר; הבסיס שלו לפני הבלוק)
  const SEL2 = '.kadisha-lexicon-page .kadisha-hero-subtitle', t3 = dev(t2, [['display', 'none']], SEL2);
  k2('V7 · K2 · כלל חדש בתוך בלוק @media קיים (בסופו) — הבסיס שלו לפני הבלוק', t2, asPatch(t2, t3), (d, out, m2) => { const b = K.cssAll(m2).find(n => n.type === 'at' && ST.mediaMax(n.sel) === 767); return b.kids.length === 2 && b.kids[1].sel === SEL2 && d.old === 0 && d.neu === 1 && out.split('@media (max-width: 767px)').length === 2; });
  // בסיס שבא אחרי הבלוק — כלל בבלוק שלפניו היה נדרס: בלוק חדש אחרי הבסיס
  { const src = '@media (max-width: 767px) {\n  .a { color: red; }\n}\n.b { color: blue; }\n', b = rule(K.parseCss(src), '.b'), out = ST.planDevice(src, { sel: '.b', max: 767, baseFrom: b.from, rtl: true, sets: [['color', 'green']] }).text;
    ok(/^@media \(max-width: 767px\) \{\n  \.a \{ color: red; \}\n\}\n\.b \{ color: blue; \}\n@media \(max-width: 767px\) \{\n  \.b \{ color: green; \}\n\}\n$/.test(out), 'V7 · בלוק של המכשיר שלפני הכלל הבסיסי — אינו מתאים (היה נדרס): נוצר בלוק אחרי הבסיס', out); }
  // 4. איפוס: ההצהרה האחרונה ← הכלל נמחק; הכלל האחרון ← הבלוק נמחק — הקובץ זהה למקור
  const t4 = dev(t3, [['display', null]], SEL2), t5 = dev(t4, [['font-size', null], ['margin-bottom', null]]);
  k2('V7 · K2 · איפוס ההצהרה האחרונה בכלל ← הכלל נמחק (הבלוק נשאר — יש בו כלל אחר)', t3, asPatch(t3, t4), (d, out) => out === t2);
  k2('V7 · K2 · איפוס הכלל האחרון בבלוק ← גם הבלוק נמחק — הקובץ זהה בייט בבייט למקור', t4, asPatch(t4, t5), (d, out) => out === page);
  { const src = '.a { color: red; }\n@media (max-width: 767px) {\n  /* טלפון */\n  .a { color: blue; }\n}\n', out = ST.planDevice(src, { sel: '.a', max: 767, baseFrom: 0, rtl: true, sets: [['color', null]] }).text;
    ok(out === '.a { color: red; }\n@media (max-width: 767px) {\n  /* טלפון */\n}\n', 'V7 · בלוק עם הערה — נשאר (רק הכלל שהתרוקן נמחק)', out);
    const back = ST.planDevice(out, { sel: '.a', max: 767, baseFrom: 0, rtl: true, sets: [['color', 'green']] }).text;
    ok(/\/\* טלפון \*\/\n  \.a \{ color: green; \}\n\}/.test(back) && !K.cssAll(K.parseCss(back)).some(n => n.broken), 'V7 · כלל חדש בבלוק שיש בו רק הערה — נכתב בתוכו', back); }
  // תנאי שנכתב ביד: width <= 767px — אותו מכשיר
  { const src = '.a { color: red; }\n@media (width <= 767px) {\n  .a { margin: 0; }\n}\n', out = ST.planDevice(src, { sel: '.a', max: 767, baseFrom: 0, rtl: true, sets: [['color', 'blue']] }).text;
    ok(out === '.a { color: red; }\n@media (width <= 767px) {\n  .a { margin: 0; color: blue; }\n}\n', 'V7 · @media (width <= 767px) שנכתב ביד — מזוהה כמכשיר, והכתיבה נכנסת לכלל שבו', out); }
  // 5. עדכון max בכל הקבצים — לא נוגע ב-@media אחר
  { const css2 = t2 + '@media (max-width: 782px) { .x { color: red; } }\n@media (min-width: 767px) { .y { color: red; } }\n', r = ST.mediaRetarget(css2, 767, 700);
    k2('V7 · K2 · שינוי max: רק התנאי של הבלוק משתנה (782px ו-min-width — לא)', css2, r.patches, (d, out) => r.blocks === 1 && r.rules === 1 && d.old === 1 && d.text === '@media (max-width: 700px) {' && /max-width: 782px/.test(out) && /min-width: 767px/.test(out)); }
  // 6. kol.json: רק הערך של breakpoints
  { const kol = '{\n  "name": "קול מרוקו",\n  "entry": "index.html",\n  "breakpoints": [\n    { "id": "tablet", "label": "טאבלט", "max": 1024, "preview": 820 },\n    { "id": "mobile", "label": "טלפון", "max": 767, "preview": 390 }\n  ],\n  "styles": { "page": "css/page.css" }\n}\n';
    const out = ST.kolBreakpoints(kol, [{ id: 'tablet', label: 'טאבלט', max: 1024, preview: 820 }, { id: 'mobile', label: 'טלפון "קטן"', max: 700, preview: 390 }]), d = lines(kol, out);
    ok(d.old === 1 && d.neu === 1 && /"label": "טלפון \\"קטן\\"", "max": 700/.test(d.text) && JSON.parse(out).styles.page === 'css/page.css' && JSON.parse(out).breakpoints[1].label === 'טלפון "קטן"', 'V7 · K2 · kol.json: שורה אחת — הנקודה שהשתנתה; שאר הקובץ כמו שהוא; שם עם מרכאות — כמחרוזת JSON', d);
    const fresh = ST.kolBreakpoints('{\n  "name": "x"\n}\n', [{ id: 'mobile', label: 'טלפון', max: 767, preview: 390 }]);
    ok(JSON.parse(fresh).breakpoints[0].max === 767 && JSON.parse(fresh).name === 'x', 'V7 · kol.json בלי breakpoints — נוסף (ברירת המחדל נכתבת בשינוי הראשון)', fresh);
    let thrown = ''; try { ST.kolBreakpoints('{ "a": ', []); } catch (e) { thrown = e.code; } ok(thrown === 'broken', 'V7 · kol.json שאינו JSON תקין — לא נכתב (מתקנים בקוד)'); }
  // 7. <style> בעמוד HTML: התיקון מוזז למיקום שב-HTML, והמודל של ה-HTML שקול לניתוח מלא
  { const html = '<!doctype html><html><head><style>\n.a { color: red; }\n</style></head><body><p class="a">x</p></body></html>', m = K.parseHtml(html);
    let st = null; K.walk(m.root, n => { if (n.tag === 'style') st = n; });
    const a = st.st[1], b = st.et[0], text = html.slice(a, b), out = ST.planDevice(text, { sel: '.a', max: 767, baseFrom: K.parseCss(text).root.kids[0].from, rtl: true, sets: [['color', 'blue']] }).text;
    const ps = asPatch(text, out).map(p => ({ from: p.from + a, to: p.to + a, text: p.text })), inc = K.update(K.parseHtml(html), ps).model, full = K.parseHtml(K.applyPatches(html, ps));
    ok(!K.sameModel(inc, full) && /<style>\n\.a \{ color: red; \}\n@media \(max-width: 767px\) \{\n  \.a \{ color: blue; \}\n\}\n<\/style>/.test(full.src), 'V7 · <style> בעמוד: בלוק @media אחרי הכלל, והמודל ההדרגתי של ה-HTML שקול לניתוח מלא', full.src); }
}

console.log('\n' + (failed ? '❌ ' + failed + ' מתוך ' + ran + ' נכשלו' : '✅ כל ' + ran + ' הבדיקות עברו'));
process.exit(failed ? 1 : 0);
