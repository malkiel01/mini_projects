/**
 * זהות ומחלקות (V8) — החלק הטהור של assets/js/editor/identity.js, ומצבים (planState / stateSel / checkState / checkSelector)
 * של style.js:   node kol-maroko/tests/editor/identity.mjs
 * K2 לכל סוג כתיבה: שינוי שם (HTML, CSS, <style>), תכונה חדשה / שינוי / מחיקה, כלל מצב אחרי היעד, כלל מצב בתוך @media,
 * ובדיקות הזרקה שמנסות לשבור: on*, javascript: (גם מוסווה), < ומרכאות בערך, בורר ומצב עם { } ; <.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/style.js', 'assets/js/editor/identity.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;');
const K = globalThis.KolSrc, ST = globalThis.KolStyle, ID = globalThis.KolIdentity;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const throws = (fn, re) => { try { fn(); return false; } catch (e) { return re ? re.test(e.message) : true; } };
const page = fs.readFileSync(path.join(here, 'fixtures/page.css'), 'utf8'), html = fs.readFileSync(path.join(here, 'fixtures/index.html'), 'utf8');
/** K2: הטקסט החדש, ביטול מחזיר בייט בבייט, הניתוח אינו נשבר. */
function k2(src, ps, css) { const out = K.applyPatches(src, ps), back = K.applyPatches(out, K.invertPatches(src, ps)); const br = css ? K.cssAll(K.parseCss(out)).some(n => n.broken) : K.parseHtml(out).errors.some(e => !K.BENIGN.has(e.code)) && !K.parseHtml(src).errors.some(e => !K.BENIGN.has(e.code)); return { out, ok: back === src && !br }; }
const changed = (a, b) => ID.lineDiff(a, b);

// ── 1. בדיקות קלט ──
ok(ID.checkClass('hero-title') === 'hero-title' && ['', 'a b', '.x', 'x"y', 'x<y', '1abc', 'a{b}', 'א'].every(v => throws(() => ID.checkClass(v))), 'שם מחלקה: hero-title עובר; רווח, נקודה, מרכאות, <, ספרה בהתחלה, {} ועברית — נדחים');
ok(ID.checkId('lexicon-hero-title') === 'lexicon-hero-title' && ['a b', '#x', 'x"', '1x', '<x'].every(v => throws(() => ID.checkId(v))), 'id: נבדק');
ok(['onclick', 'ONLOAD', 'onmouseover', 'onerror'].every(n => throws(() => ID.checkAttr(n, 'x'), /JS/)), 'on* — נדחה, עם "קוד נכתב בקובץ JS"');
const bad = ['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'java\tscript:alert(1)', 'java\nscript:x', '&#x6A;avascript:x', 'jav&#x09;ascript:x', 'vbscript:x', 'data:text/html,<script>x</script>', 'DATA:image/svg+xml,<svg onload=x>'];
ok(bad.every(v => throws(() => ID.checkAttr('href', v), /JS/)) && throws(() => ID.checkAttr('src', 'javascript:x')) && throws(() => ID.checkAttr('formaction', 'javascript:x')) && throws(() => ID.checkAttr('xlink:href', 'javascript:x')), 'href / src / formaction / xlink:href עם javascript: (גם מוסווה ברווח, טאב, ישות) ו-data:text/html — נדחים', bad.filter(v => !throws(() => ID.checkAttr('href', v))));
ok(ID.checkAttr('href', '#top') === 'href' && ID.checkAttr('href', 'https://x.test/?a=1&b=2') && ID.checkAttr('data-x', 'javascript:') && ID.checkAttr('xml:lang', 'he') && ID.checkAttr('aria-label', '"<b>"'), 'מותר: href רגיל, data-* עם כל טקסט, שם עם : (xml:lang), aria-label עם מרכאות ו-<');
ok(['a b', 'x=y', 'a"', '<x', '', '1x', 'x:', 'srcdoc', 'style', 'class'].every(n => throws(() => ID.checkAttr(n, 'v'))), 'שם תכונה לא חוקי / srcdoc / style / class — נדחה');

// ── 2. תכונה: חדשה, שינוי, מחיקה, ערך עם " & < ──
let m = K.parseHtml(html), n = K.all(m).find(x => x.tag === 'h1');
let r = k2(html, ID.setAttr(m, n, 'aria-label', 'כותרת "ראשית" & <b>').patches);
ok(r.ok && /aria-label="כותרת &quot;ראשית&quot; &amp; &lt;b&gt;"/.test(r.out) && K.attrOf(K.all(K.parseHtml(r.out)).find(x => x.tag === 'h1'), 'aria-label').value === 'כותרת "ראשית" & <b>' && changed(html, r.out).length === 1, 'תכונה חדשה עם " & < — כישויות; נקראת חזרה זהה; שורה אחת; ביטול זהה', changed(html, r.out));
let m2 = K.parseHtml(r.out), n2 = K.all(m2).find(x => x.tag === 'h1'), r2 = k2(r.out, ID.setAttr(m2, n2, 'aria-label', 'x').patches);
ok(r2.ok && /aria-label="x"/.test(r2.out), 'שינוי ערך — במקום');
let m3 = K.parseHtml(r2.out), r3 = k2(r2.out, K.html.removeAttr(m3, K.all(m3).find(x => x.tag === 'h1'), 'aria-label').patches);
ok(r3.ok && r3.out === html, 'מחיקה — הקובץ חוזר להיות זהה למקור');
let m4 = K.parseHtml(html), r4 = k2(html, ID.setAttr(m4, K.all(m4).find(x => x.tag === 'h1'), 'xml:lang', 'he').patches);
ok(r4.ok && /xml:lang="he"/.test(r4.out), 'שם עם : — נכתב');

// ── 3. שינוי שם ──
const css = '.a .hero { color: red; }\n.hero-x, .hero:hover, [class~="hero"] { x: "a.hero" }\n@scope (.hero) { .t { color: blue; } }\n';
const pc = ID.renameCss(css, 'hero', 'lead'), oc = K.applyPatches(css, pc);
ok(oc === '.a .lead { color: red; }\n.hero-x, .lead:hover, [class~="hero"] { x: "a.hero" }\n@scope (.lead) { .t { color: blue; } }\n', 'CSS: .hero ← .lead בבוררים (גם :hover, גם @scope), לא hero-x, לא בתוך [..] ולא בערכים', oc);
const hh = '<!doctype html><html><head><style>.hero{color:red}</style></head><body><div class="hero  hero-x a">x</div><p class=hero>y</p><span class="xhero">z</span></body></html>';
const ph = ID.renameHtml(hh, 'hero', 'lead'), oh = k2(hh, ph.patches);
ok(oh.ok && oh.out === '<!doctype html><html><head><style>.lead{color:red}</style></head><body><div class="lead  hero-x a">x</div><p class=lead>y</p><span class="xhero">z</span></body></html>', 'HTML: class="" (גם בלי מרכאות) ו-<style>, רווחים נשמרים; xhero / hero-x — לא', oh.out);
const js = "el.classList.add('hero');\nq('.hero-x'); const t = \"the hero of\"; x.hero = 1; `${a} hero`;";
const cj = ID.jsCandidates(js, 'hero');
ok(cj.length === 3 && cj.map(c => c.line).join() === '1,2,2' && js.slice(cj[0].from, cj[0].to) === 'hero', 'JS: מחרוזות שמכילות את המילה — מועמדות (3), לא x.hero ולא hero-x', cj);
const ih = '<label for="a">x</label><input id="a" aria-describedby="b a"><a href="#a">y</a><a href="#ab">z</a><style>#a{color:red} #ab{}</style>';
const pi = ID.renameHtml(ih, 'a', 'name', 'id'), oi = K.applyPatches(ih, pi.patches);
ok(oi === '<label for="name">x</label><input id="name" aria-describedby="b name"><a href="#name">y</a><a href="#ab">z</a><style>#name{color:red} #ab{}</style>', 'id: for, aria-describedby, href="#…", ו-#id ב-<style> — מתעדכנים; #ab — לא', oi);

// ── 4. מחלקה משותפת: הצהרות זהות ──
const cd = ID.commonDecls([[{ prop: 'color', value: 'red' }, { prop: 'font-size', value: '2em' }, { prop: 'margin', value: '0' }], [{ prop: 'font-size', value: '2em' }, { prop: 'color', value: ' red' }, { prop: 'margin', value: '1px' }]]);
ok(cd.length === 2 && cd[0].prop === 'color' && cd[1].prop === 'font-size', 'ההצהרות הזהות בכל הכללים (color, font-size) — לא margin שונה', cd);
ok(ID.commonDecls([[{ prop: 'color', value: 'red' }], []]).length === 0, 'אלמנט בלי כלל משלו — אין מה להעביר');
const pr = { tag: 'section', attrs: [{ name: 'class', value: 'kadisha-hero-wrap' }], parent: null }, mk = (t, c) => ({ tag: t, attrs: c ? [{ name: 'class', value: c }] : [], parent: pr });
pr.kids = []; K.classesOf = K.classesOf || (x => (x.attrs.find(a => a.name === 'class') || { value: '' }).value.split(/\s+/).filter(Boolean));
ok(ID.sharedName([mk('h2', 'a x'), mk('h2', 'x b')]).name === 'x' && ID.sharedName([mk('h2', 'a x'), mk('h2', 'x b')]).existing, 'שם מוצע: המחלקה שכולם כבר חולקים');
ok(ID.sharedName([mk('h2'), mk('h2', 'q')], new Set()).name === 'kadisha-hero-title' && ID.sharedName([mk('h2'), mk('h2')], new Set(['kadisha-hero-title'])).name === 'kadisha-hero-title-2', 'שם מוצע: מההקשר המשותף + התגית (kadisha-hero-title), ייחודי');

// ── 5. מצבים ──
ok(ST.stateSel('.btn', ':hover') === '.btn:hover' && ST.stateSel('.a .b, .c::before', ':focus-visible') === '.a .b:focus-visible, .c:focus-visible::before' && ST.stateSel('.page .card .title', ':hover', '.card') === '.card:hover .title', 'בורר המצב: לכל חלק ברשימה, לפני פסאודו-אלמנט; מצב של הורה — .card:hover .title');
ok([':hover', ':focus-visible', ':nth-child(2n)', ':has(> img)', ':not(.x):hover'].every(v => !throws(() => ST.checkState(v))), 'מצב: :hover, :focus-visible, :nth-child(2n), :has(> img), :not(.x):hover — מתקבלים');
ok(['hover', '::before', ':hover, body', ':hover body', ':hover{x}', ':hover;', ':is(a', ':hover</style>', ':hover .x', ':hover>*', ':hover/**/'].every(v => throws(() => ST.checkState(v))), 'מצב: בלי :, פסאודו-אלמנט, פסיק / רווח / קומבינטור ברמה העליונה, {} ; <, סוגריים לא סגורים, הערה — נדחים');
ok(['.a{}', '.a;b', '</style><x>', '.a /* x */', ''].every(v => throws(() => ST.checkSelector(v))) && ST.checkSelector('.page h2[data-x="a b"]') === '.page h2[data-x="a b"]', 'בורר בהקלדה: {} ; < הערה וריק — נדחים; בורר רגיל עם מרכאות — עובר');
const base = '.a { color: red; }\n.b { color: blue; }\n', mb = K.parseCss(base), rb = K.cssAll(mb).find(x => x.type === 'rule' && x.sel === '.a');
let s1 = ST.planState(base, { sel: '.a:hover', baseFrom: rb.from, rtl: true, sets: [['color', 'green']] });
ok(s1.text === '.a { color: red; }\n.a:hover { color: green; }\n.b { color: blue; }\n' && changed(base, s1.text).length === 1, 'כלל מצב חדש — מיד אחרי כלל היעד, שורה אחת', s1.text);
let s2 = ST.planState(s1.text, { sel: '.a:hover', baseFrom: rb.from, rtl: true, sets: [['background-color', 'pink'], ['color', 'navy']] });
ok(s2.text === '.a { color: red; }\n.a:hover { color: navy; background-color: pink; }\n.b { color: blue; }\n', 'כתיבה נוספת — לאותו כלל מצב', s2.text);
let s3 = ST.planState(s2.text, { sel: '.a:hover', baseFrom: rb.from, rtl: true, sets: [['color', null], ['background-color', null]] });
ok(s3.text === base, 'איפוס במצב — מוחק רק את ההצהרות של המצב; כלל שנשאר ריק נמחק — הקובץ חוזר להיות זהה', s3.text);
const sh = '.btn { color: red; }\n.btn:hover, .btn:focus { color: blue; }\n', ms = K.parseCss(sh), s4 = ST.planState(sh, { sel: '.btn:hover', baseFrom: K.cssAll(ms)[0].from, rtl: true, sets: [['color', 'green']] });
ok(s4.text === '.btn { color: red; }\n.btn:hover, .btn:focus { color: green; }\n' && s4.shared === '.btn:hover, .btn:focus', 'כלל קיים שמכסה את המצב (.btn:hover, .btn:focus) — נערך במקומו, ונאמר שהוא משותף', s4);
const pg = K.parseCss(page), ph1 = K.cssAll(pg).find(x => x.type === 'rule' && /kadisha-hero-title/.test(x.sel) && !x.parent.name);
const s5 = ST.planDevice(page, { sel: ST.stateSel(ph1.sel, ':hover'), max: 767, baseFrom: ph1.from, rtl: true, sets: [['color', 'red']] });
const blk = /@media \(max-width: 767px\) \{[\s\S]*?\n\}/.exec(s5.text);
ok(blk && /kadisha-hero-title:hover,\s*\.kadisha-lexicon-page \.kadisha-hero h1:hover \{ color: red; \}/.test(blk[0]) && s5.text.indexOf(blk[0]) > s5.text.indexOf(ph1.sel) && K.applyPatches(s5.text, K.invertPatches(page, [{ from: 0, to: page.length, text: s5.text }])) === page, 'כלל מצב בתוך @media של מכשיר — מיד אחרי הבסיס', blk && blk[0]);
const s6 = ST.planDevice(s5.text, { sel: ST.stateSel(ph1.sel, ':hover'), max: 767, baseFrom: ph1.from, rtl: true, sets: [['color', null]] });
ok(s6.text === page, 'איפוס מצב × מכשיר — הבלוק נמחק, קובץ זהה');
// כלל ריק למחלקה משותפת + צבע = שורה אחת
const e1 = K.applyPatches(page, K.css.addRule(K.parseCss(page), K.parseCss(page).root, '.hero-title', []).patches), em = K.parseCss(e1), er = K.cssAll(em).find(x => x.type === 'rule' && x.sel === '.hero-title');
const e2 = K.applyPatches(e1, ST.planRule(em, er, 'color', 'red', true).patches);
ok(er && er.decls.length === 0 && !K.cssAll(em).some(x => x.broken) && changed(e1, e2).length === 1 && /\.hero-title \{ color: red; \}/.test(e2), 'כלל ריק (.hero-title) נוצר תקין; צבע אחריו — שורה אחת', changed(e1, e2));

console.log(failed ? '❌ ' + failed + ' מתוך ' + ran + ' נכשלו' : '✅ כל ' + ran + ' הבדיקות עברו');
process.exit(failed ? 1 : 0);
