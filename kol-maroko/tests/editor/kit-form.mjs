/**
 * V16c — בונה הטופס (assets/js/editor/kit-form.js), החלק הטהור:   node kol-maroko/tests/editor/kit-form.mjs
 * לכל סוג שדה: HTML ← readField ← fieldHtml — הלוך ושוב בלי אובדן; קידוד (תווית עם <script>, מירכאות); שם שדה; pattern; הפניה.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/component-state.js', 'assets/js/editor/kit-form.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;', { filename: f });
const K = globalThis.KolSrc, F = globalThis.KolKitForm;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const throws = (fn, re) => { try { fn(); return false; } catch (e) { return !re || re.test(e.message); } };
const field = html => { const m = K.parseHtml('<!doctype html><body><form data-kol-form="t">' + html + '</form></body>'); let f = null; K.walk(m.root, x => { if (!f && !x.implied && K.classesOf(x).includes('kol-form__field')) f = x; }); return [m, f]; };

const base = { label: 'שם מלא', name: 'full_name', id: 'kf-full_name', required: true, value: '', pattern: '', help: null, msg: null, width: 'half', options: [] };
for (const [type, extra] of [['text', { value: 'ישראל', pattern: '[א-ת ]+', msg: 'רק אותיות בעברית' }], ['email', {}], ['tel', { pattern: '0[0-9]{8,9}', msg: 'מספר טלפון' }], ['number', { value: '3' }], ['date', {}],
  ['textarea', { value: 'שורה' }], ['select', { options: ['א', 'ב', 'ג'], value: 'ב' }], ['radio', { options: ['כן', 'לא'], value: 'לא' }], ['checkbox', { options: ['אוכל', 'חגים', 'שוק'], value: 'אוכל, שוק', required: false }]]) {
  const p = Object.assign({}, base, { type, help: 'עזרה' }, extra), html = F.fieldHtml(p), [m, f] = field(html), back = F.readField(m, f);
  const want = Object.assign({}, p); if (type === 'date' || type === 'number' || type === 'select' || type === 'radio' || type === 'checkbox') want.pattern = ''; if (/radio|checkbox/.test(type)) want.id = p.id;
  const keys = ['type', 'label', 'name', 'required', 'value', 'pattern', 'help', 'msg', 'width', 'options'];
  const diff = keys.filter(k => JSON.stringify(back[k]) !== JSON.stringify(want[k]));
  ok(!diff.length, 'שדה ' + type + ': fieldHtml ← readField — הלוך ושוב', { diff, back, html });
  ok(/data-kol-field/.test(html) && /class="kol-form__field kol-form__field--half"/.test(html), type + ': data-kol-field והרוחב (מחלקה)');
}
{ const html = F.fieldHtml(Object.assign({}, base, { type: 'text', label: '</label><script>alert(1)</script>"', value: '"><img src=x onerror=alert(1)>', help: '<b>x</b>' }));
  ok(!/<script>|<img|<b>/.test(html) && /&lt;\/label&gt;&lt;script&gt;/.test(html) && /value="&quot;&gt;&lt;img/.test(html), 'קידוד: תווית / ערך / עזרה עם HTML — נכתבים כטקסט', html); }
{ const html = F.fieldHtml(Object.assign({}, base, { type: 'radio', options: ['a"b', '<x>'] }));
  ok(/value="a&quot;b"/.test(html) && /&lt;x&gt;/.test(html), 'קידוד באפשרויות'); }
ok(F.checkName('email') === 'email' && throws(() => F.checkName('_form')) && throws(() => F.checkName('a b')) && throws(() => F.checkName('x"y')) && throws(() => F.checkName('email', new Set(['email']))), 'שם שדה: תקין · שמור (_form) · רווח · מירכאה · כפול');
ok(F.checkPattern('[0-9]{3}') === '[0-9]{3}' && throws(() => F.checkPattern('([')), 'pattern: תקין · לא תקין');
ok(F.checkRedirect('thanks.html') === 'thanks.html' && F.checkRedirect('https://example.com/x') === 'https://example.com/x' && throws(() => F.checkRedirect('javascript:alert(1)')) && throws(() => F.checkRedirect('http://x.com')) && throws(() => F.checkRedirect('data:text/html,x')) && throws(() => F.checkRedirect('a"b')), 'הפניה: נתיב / https — כן; javascript: / http / data: / מירכאה — לא');
// התבנית של הרכיב: כל שדה נקרא
const tpl = fs.readFileSync(path.join(root, 'assets/kit/components/form/template.html'), 'utf8'), m = K.parseHtml('<!doctype html><body>' + tpl + '</body>'), fields = [];
K.walk(m.root, x => { if (!x.implied && K.classesOf(x).includes('kol-form__field')) fields.push(F.readField(m, x)); });
ok(fields.length === 3 && fields.map(f => f.type + ':' + f.name).join() === 'text:name,email:email,textarea:message' && fields.every(f => f.required), 'התבנית: 3 שדות נקראים (שם, אימייל, הודעה), כולם חובה', fields);
console.log(failed ? `\n❌ ${failed} מתוך ${ran} נכשלו` : `\n✅ כל ${ran} הבדיקות עברו`);
process.exit(failed ? 1 : 0);
