/**
 * V16 — הכללים של §2.1 על כל assets/kit/components/* (החלק שנבדק בלי דפדפן):   node kol-maroko/tests/editor/kit-rules.mjs
 *   · component.json תקין (parseSchema) — אין שדה פגום; כל שדה מוצא את החלק שלו בתבנית (אין "חסר").
 *   · מצב: התבנית מצוירת עם ערכי ברירת המחדל = מופע "מקושר"; לכל שדה — כתיבה, קריאה חזרה, ונשאר "מקושר".
 *   · repeater: 1, 3 ו-12 פריטים — המופע נשאר "מקושר", והמבנה תקין.
 *   · "states" (מצבים בקנבס): כל בורר תקין ומוצא לפחות אלמנט אחד בתבנית; אין יותר מ-8.
 *   · כל אלמנט בתבנית — עם מחלקה (V8: יעד לעיצוב), וכל אלמנט עם טקסט גלוי — הטקסט הוא תוכן (נבחר ונערך במקום).
 *   · ההגדרות כותבות רק למקום גלוי: text / attr / class / var / children / src.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/component-state.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;', { filename: f });
const K = globalThis.KolSrc, C = globalThis.KolComp;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };

const KIT = path.join(root, 'assets/kit/components');
const names = fs.readdirSync(KIT).filter(n => fs.existsSync(path.join(KIT, n, 'kit.json'))).sort();
ok(names.length >= 20, 'לפחות 20 רכיבים מוכנים', names.length);
const SAMPLE = { text: 'טקסט בדיקה', number: '7', size: '14px', color: '#123456', select: null, toggle: null, media: 'media/x.png', link: 'https://example.com/a', rich: 'טקסט', font: 'serif' };

for (const name of names) {
  const dir = path.join(KIT, name), json = fs.readFileSync(path.join(dir, 'component.json'), 'utf8'), tpl = fs.readFileSync(path.join(dir, 'template.html'), 'utf8');
  const sch = C.parseSchema(json, name);
  ok(!sch.error, name + ': component.json תקין', sch.error);
  if (sch.error) continue;
  const bad = sch.fields.filter(f => f.bad);
  ok(!bad.length && sch.fields.length >= 1, name + ': ' + sch.fields.length + ' הגדרות, אין שדה פגום', bad.map(f => f.id + ': ' + f.bad));
  const comp = { schema: sch, template: tpl };
  let html; try { html = C.render(tpl, sch, C.defaults(sch)).html; } catch (e) { ok(false, name + ': הציור', e.message); continue; }
  const page = '<!doctype html><html><body>\n' + html + '\n</body></html>';
  const m = K.parseHtml(page), inst = C.instances(m, name)[0];
  ok(inst && C.state(m, inst.node, comp).state === 'linked', name + ': מופע חדש — "מקושר"', inst && C.state(m, inst.node, comp));
  const { missing } = C.readValues(m, inst.node, sch.fields);
  ok(!missing.length, name + ': כל שדה מוצא את החלק שלו בתבנית', missing);
  // כל שדה: כתיבה ← קריאה חזרה ← עדיין מקושר (ערכי ההגדרות אינם "שינוי במקום")
  for (const f of sch.fields) {
    const k = C.kindOf(f);
    ok(['text', 'attr', 'class', 'var', 'children', 'src'].includes(k), name + '.' + f.id + ': יעד גלוי (' + k + ')');
    if (k === 'children') continue;
    let v = f.control === 'select' ? f.options[f.options.length - 1] : f.control === 'toggle' ? !C.readField(m, inst.node, f) : SAMPLE[f.control] || 'x';
    if (f.target.attr === 'data-kol-until') v = '2030-01-01T10:00:00+02:00';
    let src2; try { src2 = K.applyPatches(page, C.planField(m, inst.node, f, v)); } catch (e) { ok(false, name + '.' + f.id + ': כתיבה', e.message); continue; }
    const m2 = K.parseHtml(src2), i2 = C.instances(m2, name)[0], back = C.readField(m2, i2.node, f);
    ok(String(back) === String(v), name + '.' + f.id + ': כתיבה וקריאה חזרה (' + f.control + ')', [v, back]);
    ok(C.state(m2, i2.node, comp).state === 'linked', name + '.' + f.id + ': אחרי הכתיבה — עדיין "מקושר"');
  }
  // repeater: 1 / 3 / 12 פריטים
  const rep = sch.fields.find(f => C.kindOf(f) === 'children');
  if (rep) {
    for (const want of [1, 3, 12]) {
      let src = page, mm = K.parseHtml(src), r = C.instances(mm, name)[0].node, items = C.itemsOf(r, rep);
      while (items.length > want) { src = K.applyPatches(src, K.html.remove(mm, items[items.length - 1]).patches); mm = K.parseHtml(src); r = C.instances(mm, name)[0].node; items = C.itemsOf(r, rep); }
      while (items.length < want) { src = K.applyPatches(src, K.html.duplicate(mm, items[items.length - 1]).patches); mm = K.parseHtml(src); r = C.instances(mm, name)[0].node; items = C.itemsOf(r, rep); }
      ok(items.length === want && C.state(mm, r, comp).state === 'linked', name + ': repeater ' + want + ' פריטים — מקושר');
    }
  }
  // states
  let raw = {}; try { raw = JSON.parse(json); } catch { /* */ }
  const states = Array.isArray(raw.states) ? raw.states : [];
  ok(states.length <= 8, name + ': עד 8 מצבים');
  for (const s of states) {
    const sels = Array.isArray(s.options) ? s.options.map(o => o.on).filter(x => x != null && x !== '') : [s.each || s.on].filter(x => x != null && x !== '');
    for (const sel of sels) { let hits = []; try { hits = C.findAllIn(inst.node, sel); } catch (e) { hits = null; } ok(hits && hits.length > 0, name + ' · מצב ' + s.id + ': הבורר ' + sel + ' מוצא אלמנט', hits === null ? 'בורר לא תקין' : 0); }
    const touches = Array.isArray(s.options) ? s.options.every(o => o.root || o.attr || o.class) : (s.attr || s.class);
    ok(!!touches && typeof s.label === 'string' && s.label, name + ' · מצב ' + s.id + ': שם, ומה הוא משנה (מחלקה / תכונה)');
  }
  // כל אלמנט — עם מחלקה; טקסט גלוי — בתוך אלמנט (לא בתכונה)
  const noClass = [];
  K.walk(inst.node, n => { if (n.implied || !n.tag) return; if (/^(br|source|track|wbr)$/.test(n.tag)) return; if (!K.classesOf(n).length && !/^(p|b|strong|span|time|cite|summary|li|em|i|small)$/.test(n.tag)) noClass.push(n.tag); });
  ok(!noClass.length, name + ': כל אלמנט מבני — עם מחלקה (יעד עיצוב)', noClass);
  const attrText = [];
  K.walk(inst.node, n => { if (!n.attrs) return; for (const a of n.attrs) if (/^(title|placeholder|aria-label|value)$/i.test(a.name) && /[֐-׿A-Za-z]/.test(a.value) && n.tag !== 'input') attrText.push(n.tag + '[' + a.name + ']'); });
  ok(!attrText.length, name + ': אין טקסט גלוי בתכונה', attrText);
}

console.log(failed ? `\n❌ ${failed} מתוך ${ran} נכשלו` : `\n✅ כל ${ran} הבדיקות עברו`);
process.exit(failed ? 1 : 0);
