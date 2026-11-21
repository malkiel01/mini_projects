/**
 * רכיבים (V12) — החלק הטהור של assets/js/editor/component-state.js:   node kol-maroko/tests/editor/component-state.mjs
 * חישוב המצב (מקושר / שונה / מנותק) על 20 מקרים; כל יעד בסכמה (text · attr · class · var · children · src) — כתיבה,
 * קריאה חזרה, וביטול בייט בבייט; הציור מהתבנית; עדכון מופע לתבנית חדשה עם שמירת הערכים; component.json שגוי (שורה);
 * והזרקה: on*, javascript:, משתנה עם ; / }, מחלקה מחוץ לקבוצה, בורר לא נתמך.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/component-state.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;', { filename: f });
const K = globalThis.KolSrc, C = globalThis.KolComp;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const throws = (fn, re) => { try { fn(); return false; } catch (e) { return !re || re.test(e.message); } };

const TPL = `<section class="acc acc--plain">
  <h3 class="acc-title">כותרת</h3>
  <a class="acc-link" href="#">קישור</a>
  <img class="acc-img" src="media/a.png" alt="">
  <ul class="acc-list">
    <li class="acc-item">פריט 1</li>
    <li class="acc-item">פריט 2</li>
  </ul>
  <details class="acc-d"><summary>עוד</summary></details>
</section>
`;
const SCHEMA = C.parseSchema(JSON.stringify({ name: 'accordion', label: 'אקורדיון', icon: '▤', category: 'content', fields: [
  { id: 'title', label: 'כותרת', control: 'text', target: { text: '.acc-title' } },
  { id: 'open', label: 'פתוח', control: 'toggle', target: { attr: 'open', on: '.acc-d' } },
  { id: 'gap', label: 'רווח', control: 'size', target: { var: '--acc-gap' } },
  { id: 'style', label: 'סגנון', control: 'select', options: ['plain', 'boxed'], target: { class: 'acc--{value}' } },
  { id: 'items', label: 'פריטים', control: 'repeater', target: { children: '.acc-list > .acc-item' } },
  { id: 'img', label: 'תמונה', control: 'media', target: { src: '.acc-img' } },
  { id: 'href', label: 'קישור', control: 'link', target: { attr: 'href', on: '.acc-link' } },
] }), 'accordion');
ok(!SCHEMA.error && SCHEMA.fields.length === 7 && SCHEMA.fields.every(f => !f.bad), 'component.json תקין ← 7 שדות, בלי שגיאות', SCHEMA);
const comp = { schema: SCHEMA, template: TPL };
const page = v => `<!doctype html><html lang="he"><head><title>x</title></head><body>\n  <main>\n    ${v}\n  </main>\n</body></html>\n`;
const inst = C.render(TPL, SCHEMA, {}).html;
ok(/^<section data-kol-component="accordion" class="acc acc--plain">|^<section class="acc acc--plain" data-kol-component="accordion">/.test(inst), 'ציור בלי ערכים: התבנית + data-kol-component על השורש', inst.slice(0, 80));

/** המצב של המופע הראשון בעמוד. */
function st(html) { const m = K.parseHtml(page(html)), r = C.instances(m)[0]; return r ? C.state(m, r.node, comp).state : 'detached'; }
const rep = (s, a, b) => { if (!s.includes(a)) throw new Error('אין ' + a); return s.replace(a, b); };
// ── 20 מקרים ──
const cases = [
  ['1 · ציור טרי', inst, 'linked'],
  ['2 · הזחה אחרת לגמרי (רווחים ושורות)', inst.replace(/\n\s*/g, ' '), 'linked'],
  ['3 · סדר תכונות אחר על השורש', inst.replace('data-kol-component="accordion" class="acc acc--plain"', 'class="acc acc--plain" data-kol-component="accordion"').replace('class="acc acc--plain" data-kol-component="accordion"', 'data-kol-component="accordion" class="acc acc--plain"'), 'linked'],
  ['4 · כותרת אחרת (שדה text) — עדיין מקושר', rep(inst, '>כותרת<', '>שלום עולם<'), 'linked'],
  ['5 · open (toggle) דולק', rep(inst, '<details class="acc-d">', '<details class="acc-d" open>'), 'linked'],
  ['6 · --acc-gap ב-style של השורש (var)', rep(inst, '<section ', '<section style="--acc-gap: 12px" '), 'linked'],
  ['7 · סגנון boxed (class מתוך קבוצה)', rep(inst, 'acc--plain', 'acc--boxed'), 'linked'],
  ['8 · שלושה פריטים (repeater)', rep(inst, '<li class="acc-item">פריט 2</li>', '<li class="acc-item">פריט 2</li><li class="acc-item">שלישי</li>'), 'linked'],
  ['9 · פריט אחד בלבד', rep(inst, '<li class="acc-item">פריט 2</li>', ''), 'linked'],
  ['10 · תמונה אחרת (src)', rep(inst, 'media/a.png', 'media/b.jpg'), 'linked'],
  ['11 · קישור אחר (attr href)', rep(inst, 'href="#"', 'href="https://example.org/"'), 'linked'],
  ['12 · סדר מחלקות אחר', rep(inst, 'class="acc acc--plain"', 'class="acc--plain acc"'), 'linked'],
  ['13 · טקסט שאינו הגדרה שונה (summary)', rep(inst, '>עוד<', '>פחות<'), 'modified'],
  ['14 · אלמנט נוסף בתוך המופע', rep(inst, '</section>', '<p>תוספת</p></section>'), 'modified'],
  ['15 · אלמנט נמחק מהמופע (הקישור)', rep(inst, '<a class="acc-link" href="#">קישור</a>', ''), 'modified'],
  ['16 · מחלקה נוספת שאינה בקבוצה', rep(inst, 'class="acc acc--plain"', 'class="acc acc--plain extra"'), 'modified'],
  ['17 · style נוסף שאינו המשתנה', rep(inst, '<section ', '<section style="--acc-gap: 4px; color: red" '), 'modified'],
  ['18 · שתי מחלקות מהקבוצה יחד', rep(inst, 'acc--plain', 'acc--plain acc--boxed'), 'modified'],
  ['19 · תגית אחרת לכותרת', rep(rep(inst, '<h3 class="acc-title">', '<h2 class="acc-title">'), '</h3>', '</h2>'), 'modified'],
  ['20 · בלי data-kol-component — מנותק', inst.replace(/ ?data-kol-component="accordion"/, ''), 'detached'],
];
for (const [name, html, want] of cases) { const got = st(html); ok(got === want, 'מצב ' + name + ' ← ' + want, got); }
{ // רכיב שהתבנית שלו חסרה
  const m = K.parseHtml(page(inst)), r = C.instances(m)[0].node;
  ok(C.state(m, r, { schema: SCHEMA, template: null }).state === 'missing', 'תבנית חסרה ← "missing" (לא "מקושר")');
}

// ── כל יעד: כתיבה דרך planField, קריאה חזרה, וביטול בייט בבייט ──
function write(src, id, value) {
  const m = K.parseHtml(src), r = C.instances(m)[0].node, f = SCHEMA.fields.find(x => x.id === id), ps = C.planField(m, r, f, value);
  const out = K.applyPatches(src, ps), back = K.applyPatches(out, K.invertPatches(src, ps));
  const m2 = K.parseHtml(out), r2 = C.instances(m2)[0].node;
  return { out, back: back === src, read: C.readField(m2, r2, f), state: C.state(m2, r2, comp).state, same: !K.sameModel(K.update(K.parseHtml(src), ps).model, m2) };
}
const P = page(inst);
let w = write(P, 'title', 'שלום <b>&'); ok(w.read === 'שלום <b>&' && w.out.includes('שלום &lt;b&gt;&amp;') && w.back && w.state === 'linked' && w.same, 'text: כותב טקסט מקודד (< & אינם הופכים לתגית), קורא חזרה, מקושר, ביטול זהה', w);
w = write(P, 'open', true); ok(w.read === true && /<details class="acc-d" open>/.test(w.out) && w.back && w.state === 'linked', 'attr (toggle): open נוסף כתכונה בלי ערך', w.out.match(/<details[^>]*>/));
w = write(w.out, 'open', false); ok(w.read === false && !/ open/.test(w.out.match(/<details[^>]*>/)[0]), 'attr (toggle): כיבוי מסיר את התכונה');
w = write(P, 'gap', '12px'); ok(w.read === '12px' && /style="--acc-gap: 12px"/.test(w.out) && w.back && w.state === 'linked', 'var: --acc-gap ב-style של השורש', w.out.match(/<section[^>]*>/));
w = write(w.out, 'gap', ''); ok(w.read === null && !/style=/.test(w.out.match(/<section[^>]*>/)[0]), 'var: ערך ריק מסיר את המשתנה (ואת style כשהוא ריק)');
w = write(P, 'style', 'boxed'); ok(w.read === 'boxed' && /class="acc acc--boxed"/.test(w.out) && w.back && w.state === 'linked', 'class: acc--plain ← acc--boxed (הקודמת מוסרת)', w.out.match(/<section[^>]*>/));
w = write(P, 'img', 'media/x y.png'); ok(w.read === 'media/x y.png' && w.back && w.state === 'linked', 'src: src של התמונה', w.read);
w = write(P, 'href', 'https://a.example/?q=1&b=2'); ok(w.read === 'https://a.example/?q=1&b=2' && w.back && w.state === 'linked', 'attr: href של הקישור');
{ // children: הוספה / מחיקה / סדר — דרך הכתיבה של repeater (ערך גולמי)
  const m = K.parseHtml(P), r = C.instances(m)[0].node, f = SCHEMA.fields.find(x => x.id === 'items'), v = C.readField(m, r, f);
  ok(v.count === 2 && v.items[1].includes('פריט 2'), 'children: קורא 2 פריטים מהמופע', v);
  const raw = v.items.slice().reverse().join('\n      ');
  const ps = C.planField(m, r, f, { raw }), out = K.applyPatches(P, ps), m2 = K.parseHtml(out), r2 = C.instances(m2)[0].node;
  ok(C.readField(m2, r2, f).items[0].includes('פריט 2') && C.state(m2, r2, comp).state === 'linked', 'children: סדר הפוך — עדיין מקושר');
}

// ── עדכון מופע לתבנית חדשה: הערכים נשמרים ──
{
  let src = P; src = write(src, 'title', 'מותאם').out; src = write(src, 'gap', '20px').out; src = write(src, 'style', 'boxed').out;
  const TPL2 = TPL.replace('<h3 class="acc-title">', '<h3 class="acc-title big">').replace('</section>', '  <footer class="acc-f">חדש</footer>\n</section>');
  const m = K.parseHtml(src), r = C.instances(m)[0].node, html = C.updated(m, r, SCHEMA, SCHEMA, TPL2);
  ok(html.includes('class="acc-title big">מותאם<') && html.includes('--acc-gap: 20px') && html.includes('acc--boxed') && html.includes('acc-f'), 'עדכון: המבנה החדש של התבנית, והכותרת / הרווח / הסגנון של המופע נשמרים', html);
  const m3 = K.parseHtml(rep(src, K.endOf ? src.slice(r.from, K.endOf(m, r)) : '', html)), r3 = C.instances(m3)[0].node;
  ok(C.state(m3, r3, { schema: SCHEMA, template: TPL2 }).state === 'linked', 'אחרי העדכון — המופע מקושר לתבנית החדשה');
  ok(C.state(m, r, { schema: SCHEMA, template: TPL2 }).state === 'modified', 'לפני העדכון — מול התבנית החדשה הוא "שונה"');
}
{ // ערכי ברירת מחדל
  const s = C.parseSchema(JSON.stringify({ name: 'x', fields: [{ id: 't', control: 'text', target: { text: 'b' }, default: 'שלום' }] }), 'x');
  ok(C.render('<p><b>a</b></p>', s, C.defaults(s)).html.includes('<b>שלום</b>'), 'הוספה: default של שדה נכתב לציור');
}

// ── חלק חסר ──
{
  const src = page(rep(inst, '<h3 class="acc-title">כותרת</h3>', ''));
  const m = K.parseHtml(src), r = C.instances(m)[0].node, rv = C.readValues(m, r, SCHEMA.fields);
  ok(rv.missing.includes('title') && throws(() => C.planField(m, r, SCHEMA.fields[0], 'x'), /\.acc-title.*חסר/), 'יעד שהבורר שלו אינו במופע ← "החלק .acc-title חסר במופע"', rv);
}

// ── component.json ──
ok(/שורה 3/.test(C.parseSchema('{\n  "name": "a",\n  "fields": [,]\n}', 'a').error || ''), 'JSON לא תקין ← שגיאה עם שורה', C.parseSchema('{\n  "name": "a",\n  "fields": [,]\n}', 'a'));
ok(/שונה משם התיקייה/.test(C.parseSchema('{"name":"b"}', 'a').error || ''), 'שם שונה משם התיקייה ← שגיאה');
ok(/kebab/.test(C.parseSchema('{"name":"Bad Name"}', '').error || ''), 'שם שאינו kebab-case ← שגיאה');
{
  const s = C.parseSchema(JSON.stringify({ name: 'a', fields: [{ id: 'x', control: 'text', target: { attr: 'onclick' } }, { id: 'y', control: 'text', target: { text: 'div:hover' } }, { id: 'z', control: 'text', target: { attr: 'style' } }, { id: 'x', control: 'text', target: { text: 'p' } }] }), 'a');
  ok(s.fields[0].bad && s.fields[1].bad && s.fields[2].bad && s.fields[3].bad, 'שדות פגומים מסומנים (on*, בורר לא נתמך, style, id כפול) — לא נזרקים', s.fields.map(f => f.bad));
}
{
  const t = C.addFieldText(C.schemaText({ name: 'search-bar', label: 'שורת חיפוש' }), { id: 'btn', label: 'כיתוב הכפתור', control: 'text', target: { text: '.go' } });
  const s = C.parseSchema(t, 'search-bar');
  ok(!s.error && s.fields.length === 1 && s.fields[0].target.text === '.go' && s.label === 'שורת חיפוש', '"הוסף הגדרה" ← שורה ב-fields של component.json', t);
  ok(throws(() => C.addFieldText(t, { id: 'btn', label: 'x', control: 'text', target: { text: '.go' } }), /כבר יש/), 'מזהה כפול נדחה');
}
ok(C.slug('Search Bar!') === 'search-bar' && C.slug('שורת חיפוש') === 'component' && C.fieldId('כיתוב', ['field']) === 'field_2', 'שם נגזר: kebab-case; עברית ← component; מזהה פנוי');

// ── הזרקה ──
{
  const m = K.parseHtml(P), r = C.instances(m)[0].node, F = id => SCHEMA.fields.find(x => x.id === id);
  ok(throws(() => C.planField(m, r, F('href'), 'javascript:alert(1)'), /javascript/), 'הזרקה: href="javascript:" נדחה');
  ok(throws(() => C.planField(m, r, F('img'), ' JaVaScRiPt:alert(1)'), /javascript/), 'הזרקה: src="javascript:" (אותיות מעורבות, רווח) נדחה');
  ok(throws(() => C.planField(m, r, F('gap'), '1px; background: url(x)'), /לא תקין/), 'הזרקה: משתנה עם ; נדחה');
  ok(throws(() => C.planField(m, r, F('gap'), '1px"><script>'), /לא תקין/), 'הזרקה: משתנה עם " > < נדחה');
  ok(throws(() => C.planField(m, r, F('gap'), 'calc(1px'), /סוגריים/), 'הזרקה: סוגריים לא מאוזנים נדחים');
  ok(throws(() => C.planField(m, r, F('style'), 'x onload=alert(1)'), /אינה אחת האפשרויות/), 'הזרקה: מחלקה מחוץ לקבוצה נדחית');
  const ps = C.planField(m, r, F('href'), '"><img src=x onerror=alert(1)>'), out = K.applyPatches(P, ps);
  ok(!/<img src=x/.test(out) && out.includes('&quot;&gt;&lt;img') && K.parseHtml(out).errors.length === K.parseHtml(P).errors.length, 'הזרקה: ערך תכונה עם "> נכתב מקודד — לא נפתחת תגית', out.match(/<a [^>]*>/));
  ok(throws(() => C.checkAttrName('onmouseover')) && throws(() => C.checkAttrName('srcdoc')) && throws(() => C.checkAttrName('a b')), 'הזרקה: שם תכונה on* / srcdoc / עם רווח — נדחה');
  ok(throws(() => C.sel('a, b')) && throws(() => C.sel('a:has(b)')) && throws(() => C.sel('')), 'בורר: פסיק / פסאודו / ריק — נדחה');
}
// ── הבורר ──
{
  const m = K.parseHtml('<div class="r"><ul><li class="i">1</li><li class="i"><b class="i">x</b></li></ul><p id="q" data-x="1">t</p></div>'), r = C.rootOf(m);
  ok(C.findAllIn(r, '.i').length === 3 && C.findAllIn(r, 'ul > .i').length === 2 && C.findAllIn(r, '.r li .i').length === 1 && C.findIn(r, '#q[data-x="1"]').tag === 'p' && C.findIn(r, 'p[data-x=2]') === null, 'בורר: מחלקה, >, צאצא, #id, [תכונה=ערך]');
}
// ── diff ──
{
  const d = C.diffLines('a\n  b\nc', 'a\nB\nc');
  ok(d.map(x => x.t).join('') === ' -+ ', 'diff: שורה שהשתנתה — הוסרה ונוספה; הזחה אינה הבדל', d);
}

console.log('\n' + (ran - failed) + '/' + ran + (failed ? ' — ' + failed + ' נכשלו' : ' — הכול עבר'));
process.exit(failed ? 1 : 0);
