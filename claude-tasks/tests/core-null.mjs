// תקלה 1 (2026-11-03): ‏replaceChildren של הדפדפן הופך null / undefined / false לטקסט — "null" הופיע בפס
// המוצמדות. core.js עוטף את replaceChildren בכל הלוח ומסנן אותם. הבדיקה: DOM מינימלי, ייבוא של core.js,
// ובדיקה שהערכים הריקים לא מגיעים ל-replaceChildren המקורי (ושהעטיפה לא מוכפלת בייבוא שני).
//
//   node claude-tasks/tests/core-null.mjs
let pass = 0, fail = 0;
const check = (name, got, want) => {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : `\n       got:  ${JSON.stringify(got)}\n       want: ${JSON.stringify(want)}`}`);
};

const calls = [];
const original = function (...nodes) { calls.push(nodes); };
for (const name of ['Element', 'DocumentFragment', 'Document']) {
  globalThis[name] = class {};
  globalThis[name].prototype.replaceChildren = original;
}
globalThis.document = new globalThis.Document();
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
globalThis.sessionStorage = globalThis.localStorage;

await import('../assets/js/core.js');

const box = new globalThis.Element();
box.replaceChildren('a', null, 'b', undefined, false, 0, '');
check('null / undefined / false מסוננים; 0 ו-"" נשארים', calls.at(-1), ['a', 'b', 0, '']);
new globalThis.DocumentFragment().replaceChildren(null);
check('גם ב-DocumentFragment', calls.at(-1), []);
globalThis.document.replaceChildren(false, 'x');
check('גם ב-Document', calls.at(-1), ['x']);
check('העטיפה מסומנת — לא נעטפת פעמיים', globalThis.Element.prototype.replaceChildren.ctSafe, true);
await import('../assets/js/core.js?again');
box.replaceChildren(null, 'y');
check('ייבוא שני — עדיין קריאה אחת למקורי, מסוננת', [calls.length, calls.at(-1)], [4, ['y']]);

console.log(`\n════ עברו: ${pass} · נכשלו: ${fail} ════`);
process.exit(fail ? 1 : 0);
