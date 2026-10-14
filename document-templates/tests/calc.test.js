// בדיקות שלב 11: מנוע החישוב והתנאים (JS) — אותם ווקטורים כמו tests/calc.php.
// הרצה: node document-templates/tests/calc.test.js
'use strict';
require('../assets/js/calc.js');
const V = require('./calc-vectors.json');
const C = globalThis.DTCalc;
let fail = 0;
const check = (name, ok) => { console.log((ok ? '✓ ' : '✗ ') + name); if (!ok) fail++; };
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const lookup = C.lookup(V.fields, V.values);
for (const [e, want] of V.expr) {
  let got;
  try { got = C.str(C.evaluate(e, lookup)); } catch (x) { got = 'ERR ' + x.message; }
  check(`${e} = ${want}` + (got === want ? '' : ` (קיבלנו ${got})`), got === want);
}
for (const e of V.errors) {
  try { C.evaluate(e, lookup); check('שגיאה: ' + e, false); }
  catch (x) { check(`שגיאה: ${e} (${x.message})`, x instanceof C.CalcError); }
}
const items = V.fields.find(f => f.key === 'items');
const rows = C.tableCompute(items, V.fields, V.values, C.rows(V.values.items));
check('עמודה מחושבת בטבלה', same(rows, V.table));
const vals = { ...V.values, items: JSON.stringify(rows) };
const got = C.all({ fields: V.fields }, vals);
check('כל השדות המחושבים: ' + JSON.stringify(got), same(got, V.all));
check('חישוב חוזר — אין שינוי', same(C.all({ fields: V.fields }, { ...vals, ...got }), {}));
for (const [v, fmt, want] of V.display) check(`תצוגה ${v} (${fmt}) → ${want}`, C.display(v, fmt) === want);
V.visible.cases.forEach(([v, want], i) => {
  const set = C.visibleKeys({ fields: V.visible.fields }, v);
  const keys = V.visible.fields.filter(f => set.has(f.key)).map(f => f.key);
  check(`תנאי הצגה ${i}: ${keys}`, same(keys, want));
});
check('בדיקת ביטוי: תקין', C.check('sum(items.line) * 2', V.fields) === null);
check('בדיקת ביטוי: מזהה לא מוכר', C.check('nope + 1', V.fields) === 'לא מוכר: nope');
check('בדיקת ביטוי בתוך טבלה', C.check('qty * מחיר', V.fields, items) === null);
console.log(fail ? `\n${fail} נכשלו` : '\nהכול עבר');
process.exit(fail ? 1 : 0);
