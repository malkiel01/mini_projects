/**
 * שקילות המודל ההדרגתי לניתוח מלא — על המודל לבדו, בלי דפדפן. 200 פעולות אקראיות בזרע קבוע על כל קובץ בדיקה.
 *   node kol-maroko/tests/editor/equiv.mjs [seed] [n]
 * יוצא ב-1 על אי-שקילות אחת. (אותה בדיקה רצה גם דרך הקנבס, ברתמה: node kol-maroko/dev/spike/run.mjs.)
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
for (const f of ['assets/vendor/spike-parsers.js', 'assets/js/editor/model.js', 'assets/js/editor/equiv.js']) vm.runInThisContext(fs.readFileSync(path.join(root, f), 'utf8') + '\n;globalThis.KolParsers = typeof KolParsers !== "undefined" ? KolParsers : globalThis.KolParsers;', { filename: f });
const K = globalThis.KolSrc, E = globalThis.KolEquiv, seed = +(process.argv[2] || 20261111), n = +(process.argv[3] || 200);
let fail = 0;
for (const f of ['handwritten.html', 'lexicon.html']) {           // broken.html שבור מראש: שם הכול ניתוח מלא (K4)
  let m = K.parseHtml(fs.readFileSync(path.join(root, 'dev/spike/fixtures', f), 'utf8'));
  const times = []; let inv = null;
  const res = E.randomRun({ seed, n, model: () => m, maxElements: 60000, apply(run) {
    const p = run(m).patches; inv = K.invertPatches(m.src, p); const t0 = performance.now(), u = K.update(m, p); times.push(performance.now() - t0);
    m = u.model;
    return { mode: u.mode, why: u.why, diff: K.sameModel(m, K.parseHtml(m.src)) };
  }, undo() { const u = K.update(m, inv); m = u.model; return { diff: K.sameModel(m, K.parseHtml(m.src)) }; } });
  times.sort((a, b) => a - b);
  console.log(f.padEnd(18), `פעולות ${res.applied} · הדרגתי ${res.inc} · מלא ${res.full} · שברו ובוטלו ${res.broke} · אי-שקילות ${res.inequivalent} · חציון ${times[times.length >> 1].toFixed(1)}ms · מקס' ${times[times.length - 1].toFixed(1)}ms`);
  if (res.full) console.log('   נפילות לניתוח מלא:', JSON.stringify(res.fullWhy));
  if (res.firstDiff) console.log('   ההבדל הראשון:', JSON.stringify(res.firstDiff));
  if (res.inequivalent || res.applied < n) fail = 1;
}
process.exit(fail);
