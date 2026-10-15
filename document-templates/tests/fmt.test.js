// מבנים מותאמים: אותם מקרים כמו tests/fmt.php — הדפדפן והשרת מציגים זהה.
// הרצה: node document-templates/tests/fmt.test.js
const F = require('../assets/js/fmt.js');
const V = require('./fmt-vectors.json');
let fail = 0;
for (const [p, v, want] of V.date) { const got = F.date(p, v); if (got !== want) { fail++; console.log(`✗ date ${p} ${v}: ${got} ≠ ${want}`); } }
for (const [p, v, want] of V.number) { const got = F.number(p, v); if (got !== want) { fail++; console.log(`✗ number ${p} ${v}: ${got} ≠ ${want}`); } }
for (const [p, v, want] of V.text) { const got = F.textRich(p, v).text; if (got !== want) { fail++; console.log(`✗ text ${p} ${v}: ${got} ≠ ${want}`); } }
for (const [k, p, v, want] of V.color) { const got = (k === 'text' ? F.textRich(p, v) : F.numberRich(p, v)).color; if (got !== want) { fail++; console.log(`✗ color ${p} ${v}: ${got} ≠ ${want}`); } }
console.log(fail ? `\n${fail} נכשלו` : 'הכול עבר');
process.exit(fail ? 1 : 0);
