/**
 * פרוסה V10 — החלק הטהור של effects.js / motion.js ו-style.js (מסגרת, פינות):   node kol-maroko/tests/editor/effects.mjs
 * K2 לכל סוג כתיבה חדש: border / border-inline-start-* / border-*-radius (planRule) · css/motion.css (הוספה בסוף) · kol.json
 * ("system") · מפריד צורה (HTML) — diff בשורות שנוגעות בלבד, ביטול זהה בייט בבייט, ניתוח מחדש בלי שבירה, ושקילות המודל ההדרגתי
 * (KolSrc.update) ב-HTML. פירוק וחיבור: צל, מסננים, transform, עקומה. הזרקה: } ; < url(javascript: הערות, ובתוך @keyframes.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/style.js', 'assets/js/editor/background.js', 'assets/js/editor/effects.js', 'assets/js/editor/motion.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;');
const K = globalThis.KolSrc, F = globalThis.KolFx, ST = globalThis.KolStyle, MO = globalThis.KolMotion;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const lines = (a, b) => { const x = a.split('\n'), y = b.split('\n'); let s = 0; while (s < x.length && x[s] === y[s]) s++; let e = 0; while (e < x.length - s && e < y.length - s && x[x.length - 1 - e] === y[y.length - 1 - e]) e++; return { at: s + 1, old: x.length - s - e, neu: y.length - s - e, text: y.slice(s, y.length - e).join('\n') }; };
const throws = fn => { try { fn(); return false; } catch (e) { return e instanceof K.SrcError || e.code === 'invalid' || e.code === 'input'; } };
function k2(name, src, ps, want, html) {
  const parse = html === true ? K.parseHtml : K.parseCss, out = K.applyPatches(src, ps), d = lines(src, out), back = K.applyPatches(out, K.invertPatches(src, ps));
  let e1 = null, e2 = null; if (html === true) { const inc = K.update(parse(src), ps).model; e1 = K.sameModel(inc, parse(out)); e2 = K.sameModel(K.update(inc, K.invertPatches(src, ps)).model, parse(src)); }
  const broken = html === 'json' ? (() => { try { JSON.parse(out); return false; } catch { return true; } })() : html ? false : K.cssAll(parse(out)).some(n => n.broken);
  ok(ps.length && back === src && !broken && e1 == null && e2 == null && want(d, out), name, { d, e1, e2, out: out.slice(-300) });
  return out;
}
const css = '/* עמוד */\n.box {\n  padding: 40px;\n  border: 1px solid #ccc;\n  border-radius: 8px;\n}\n.t { color: red; }\n';
const rule = m => K.cssAll(m).find(n => n.type === 'rule' && n.sel === '.box');

// ── 1. מסגרת ופינות (style.js) ──
ok(ST.covered('border', true)[0] === 'border' && ST.covered('border', true).length === 13 && ST.covered('border-inline-start-width', true)[0] === 'border-right-width' && ST.covered('border-inline-start-width', false)[0] === 'border-left-width', 'covered: border (השם + 12 רכיבים); border-inline-start-width ← right ב-RTL, left ב-LTR');
ok(ST.covered('border-start-start-radius', true)[0] === 'border-top-right-radius' && ST.covered('border-end-start-radius', false)[0] === 'border-bottom-left-radius' && ST.logical('border-top-right-radius', true) === 'border-start-start-radius' && ST.logical('border-top-left-radius', true) === 'border-start-end-radius' && ST.logical('border-left-width', true) === 'border-inline-end-width', 'logical / covered לפינות ולצדדים — הלוך ושוב');
ok(ST.componentOf('border', '2px dashed red', 'border-left-style', true) === 'dashed' && ST.componentOf('border-radius', '4px 8px', 'border-top-right-radius', true) === '8px' && ST.componentOf('border-radius', '40px / 20px', 'border-top-right-radius', true) === null && ST.componentOf('border-top', 'red 3px', 'border-top-width', true) === '3px', 'componentOf: רכיב מתוך border / border-radius / border-top ("מאיפה הערך"); אליפטי — לא מפורק');
{ const m = K.parseCss(css), r = ST.planRule(m, rule(m), 'border', '3px dashed #a3322a', true);
  k2('K2 "כל הצדדים": border: … מחליף את הערך במקום (שורה אחת)', css, r.patches, (d, out) => d.old === 1 && d.neu === 1 && /  border: 3px dashed #a3322a;/.test(out)); }
{ const m = K.parseCss(css), r = ST.planRule(m, rule(m), 'border-right-width', '8px', true);
  k2('K2 צד אחד (RTL ימין): border-inline-start-width אחרי הקיצור — הקיצור לא נוגע', css, r.patches, (d, out) => r.kind === 'after-shorthand' && /border: 1px solid #ccc;\n  border-inline-start-width: 8px;/.test(out)); }
{ const m = K.parseCss(css), r = ST.planRule(m, rule(m), 'border-top-left-radius', '20px', true);
  k2('K2 פינה אחת (RTL שמאל למעלה): border-start-end-radius אחרי border-radius', css, r.patches, (d, out) => /border-radius: 8px;\n  border-start-end-radius: 20px;/.test(out)); }
{ const m = K.parseCss(css), r = ST.planRule(m, rule(m), 'border-radius', '40px / 20px', true);
  k2('K2 רדיוס אליפטי — בערך עצמו', css, r.patches, (d, out) => d.old === 1 && /border-radius: 40px \/ 20px;/.test(out)); }
{ const src = '.a { border-top: 1px solid red; }', m = K.parseCss(src), r = ST.planRule(m, K.cssAll(m)[0], 'border-top-color', 'blue', true);
  k2('K2 רכיב בתוך border-top (קיצור של צד) — נערך בתוכו', src, r.patches, (d, out) => r.kind === 'component' && out === '.a { border-top: 1px solid blue; }'); }

// ── 2. צל, מסננים, transform, עקומה ──
const sh = F.parseShadows('0 8px 24px rgba(0, 0, 0, .18), inset 2px 2px 0 1px #a3322a');
ok(sh && sh.length === 2 && sh[1].inset && sh[1].spread === '1px' && sh[0].color === 'rgba(0, 0, 0, .18)' && F.joinShadows(sh) === '0 8px 24px rgba(0, 0, 0, .18), inset 2px 2px 0 1px #a3322a', 'צל: פירוק לשכבות וחזרה זהה');
ok(F.parseShadows('0 0 var(--b) red') === null && F.parseShadows('none').length === 0 && F.parseShadows('1px 2px 3px 4px red', true) === null && F.joinShadows(F.parseShadows('1px 2px 4px #000', true), true) === '1px 2px 4px #000', 'צל: var() במקום אורך ← לא מפורק ("מאפיינים נוספים"); צל טקסט — בלי פריסה');
const fl = F.parseFilter('blur(4px) grayscale(60%)');
ok(F.joinFilter(F.setFilter(fl, 'blur', null)) === 'grayscale(60%)' && F.joinFilter(F.setFilter(fl, 'sepia', '20%')) === 'blur(4px) grayscale(60%) sepia(20%)' && F.parseFilter('url(#f)') === null, 'מסננים: הסרה / הוספה שומרות סדר; url() — לא מפורק');
const tf = F.parseTransform('translate(10px, 0) rotate(30deg) scale(1.2)');
ok(tf && tf.rot === '30deg' && tf.sx === '1.2' && tf.sy === '1.2' && F.joinTransform(Object.assign({}, tf, { sx: '-1.2' })) === 'translate(10px, 0) rotate(30deg) scale(-1.2, 1.2)' && F.parseTransform('matrix(1,0,0,1,0,0)') === null && F.parseTransform('rotate(1deg) translate(2px)') === null && F.joinTransform(F.parseTransform('none')) === 'none', 'transform: פירוק, היפוך, סדר קבוע; matrix / סדר אחר ← לא מפורק');
ok(F.bezierCss(F.bezierOf('ease')) === 'ease' && F.bezierCss([0.2, 0.8, 0.2, 1]) === 'cubic-bezier(0.2, 0.8, 0.2, 1)' && F.bezierOf('cubic-bezier(2, 0, 1, 1)') === null && F.deg('0.5turn') === 180, 'עקומה: שם ⇄ cubic-bezier; x מחוץ ל-0…1 נדחה; זוויות');

// ── 3. css/motion.css ──
let mc = k2('K2 motion.css חדש (ריק): כותרת, בסיס kol-in (בתוך reduced-motion), וכניסה — בסוף', '', F.planMotion('', ['base', 'in:fade-up']), (d, out) => /^\/\* תנועה/.test(out) && /@media \(prefers-reduced-motion: no-preference\) \{\n  \.kol-in \{/.test(out) && /\.kol-motion-on \.kol-in:not\(\.is-in\) \{ animation-play-state: paused; \}/.test(out) && /\.kol-in-fade-up \{ --kol-in-name: kol-in-fade-up; \}\n@keyframes kol-in-fade-up \{\n  from \{ opacity: 0; transform: translateY\(40px\); \}\n\}/.test(out));
ok(F.planMotion(mc, ['base', 'in:fade-up']).length === 0, 'מה שכבר כתוב — לא נכתב שוב');
mc = k2('K2 כניסה נוספת: רק ה-keyframes שלה, בסוף; מה שקיים לא נוגע', mc, F.planMotion(mc, ['base', 'in:bounce']), (d, out) => d.old === 0 && /@keyframes kol-in-bounce \{\n  from \{[^}]*\}\n  60% \{/.test(out));
mc = k2('K2 גלילה: @supports (animation-timeline: view()) + @keyframes kol-sc עם var()', mc, F.planMotion(mc, ['scroll']), (d, out) => /@supports \(animation-timeline: view\(\)\) \{\n  @media \(prefers-reduced-motion: no-preference\) \{\n    \.kol-sc \{ animation: kol-sc linear both; animation-timeline: view\(\);/.test(out) && /@keyframes kol-sc \{\n  from \{ opacity: var\(--kol-sc-o0, 1\);/.test(out));
mc = k2('K2 מצגת 3 תמונות, דהייה + קן ברנס: keyframes והשהיות מחושבות', mc, F.planMotion(mc, ['slides:3:fade:1']), (d, out) => /@keyframes kol-slides-fade-kb-3 \{\n  0% \{ opacity: 0; transform: scale\(1\); \}\n  6% \{ opacity: 1; \}\n  33\.33% \{ opacity: 1; transform: scale\(1\.15\); \}/.test(out) && /nth-child\(3\) \{ animation-delay: calc\(var\(--kol-slide-d, 5s\) \* 2\); \}/.test(out));
ok(F.ENTRANCES.length >= 12 && F.SHAPES.length >= 10, '12 אנימציות כניסה לפחות; 10 צורות לפחות', [F.ENTRANCES.length, F.SHAPES.length]);
for (const [id] of F.ENTRANCES) { const t = F.entranceCss(id); if (K.cssAll(K.parseCss(t)).some(n => n.broken) || !new RegExp('@keyframes kol-in-' + id + ' ').test(t)) ok(false, 'כניסה ' + id + ' — CSS תקין', t); }
ok(true, 'כל הכניסות — CSS תקין (בלי שבירה)');

// ── 4. kol.json "system" ──
const kj = '{\n    "version": 1,\n    "entry": "index.html"\n}\n';
k2('K2 kol.json בלי system: מפתח חדש בסוף, עם kol-data.js שהיה ברירת המחדל — JSON תקין', kj, MO.planSystem(kj, true), (d, out) => { const j = JSON.parse(out); return j.system['js/kol-data.js'] && j.system['js/kol-motion.js'].version === '1.0.0' && j.entry === 'index.html' && /\n    "system"/.test(out); }, 'json');
const kj2 = '{ "system": { "js/kol-data.js": { "version": "1.1.0", "modified": true } } }';
k2('K2 kol.json עם system: הרשומה נוספת, kol-data.js לא נוגע', kj2, MO.planSystem(kj2, true), (d, out) => { const j = JSON.parse(out); return j.system['js/kol-data.js'].modified === true && j.system['js/kol-motion.js']; }, 'json');

// ── 5. מפריד צורה (HTML) ──
const page = '<!doctype html>\n<html lang="he" dir="rtl">\n<head><title>x</title></head>\n<body>\n<section class="box">\n  <h2>כותרת</h2>\n</section>\n</body>\n</html>\n';
const svg = fs.readFileSync(path.join(root, 'assets/editor-shapes/wave.svg'), 'utf8').trim();
{ const m = K.parseHtml(page), sec = K.q(m, 'section'), ps = K.html.insert(m, sec, sec.kids.length, F.shapeHtml(svg, 'bottom', 'wave')).patches;
  k2('K2 מפריד בתחתית: <div class="shape shape-bottom" aria-hidden> עם SVG גלוי, אחרון ברובריקה; המודל ההדרגתי שקול', page, ps, (d, out) => /<div class="shape shape-bottom" data-shape="wave" aria-hidden="true"><svg [^>]*viewBox="0 0 1000 100"[^>]*><path fill="currentColor"/.test(out) && out.indexOf('shape') > out.indexOf('<h2>'), true); }
for (const f of fs.readdirSync(path.join(root, 'assets/editor-shapes'))) { try { F.checkSvg(fs.readFileSync(path.join(root, 'assets/editor-shapes', f), 'utf8').trim()); } catch { ok(false, 'צורה ' + f + ' עוברת checkSvg'); } }
ok(true, 'כל קובצי הצורות — SVG פשוט (עוברים checkSvg)');

// ── 6. הזרקה ──
const bad = ['red;}body{display:none', 'x</style><script>alert(1)</script>', 'url(javascript:alert(1))', 'blur(1px) /* x */', 'expression(alert(1))', '@import url(x)', "0 0 1px 'a", 'rotate(1deg))', 'a{b', 'x\\3c'];
ok(bad.every(v => throws(() => F.checkFx(v))), 'checkFx: } ; < url(javascript: הערות expression @import מרכאה / סוגר לא מאוזנים \\ — נדחים', bad.filter(v => !throws(() => F.checkFx(v))));
ok(throws(() => F.checkFx("url('javascript:alert(1)')", { url: true })) && throws(() => F.checkFx("url('data:text/html,x')", { url: true })) && F.checkFx("url('../media/a.png')", { url: true }) === "url('../media/a.png')", 'url() — רק כשמותר, ורק כתובת שעוברת checkUrl (לא javascript:, לא data:text)');
ok(throws(() => F.planMotion('', ['in:x;}a{'])) && throws(() => F.planMotion('', ['in:fade} @import'])) && throws(() => F.slidesCss(11, 'fade')) && throws(() => F.slidesCss('2;}', 'fade')), 'בתוך @keyframes: שם כניסה / מספר תמונות מוזרקים — נדחים (רק מרשימה קבועה)');
ok(['<svg onload="x()"></svg>', '<svg><script>x</script></svg>', '<svg><image href="x"/></svg>', '<svg><path style="fill:url(javascript:x)"/></svg>', '<div></div>'].every(v => throws(() => F.checkSvg(v))), 'SVG של צורה: on… / script / image / href / url() — נדחים');
ok(throws(() => MO.planSystem('{"system": 5', true)), 'kol.json שבור — נדחה (לא נכתב)');

console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
process.exit(failed ? 1 : 0);
