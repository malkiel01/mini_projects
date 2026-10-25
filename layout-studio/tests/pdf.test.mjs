// בדיקות רשימת הציור ל-PDF: node --test layout-studio/tests/pdf.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadFont, fontVariant, shapeLine, advance } from '../assets/js/shaper.js';
import { layoutFlows } from '../assets/js/flow.js';
import { fontKey, glyphOps, drawList } from '../assets/js/pdf.js';
import { addFrame, addImageFrame, textToDoc, setFlow, imagePlacement } from '../assets/js/frames.js';
import { ensureMasters } from '../assets/js/pages.js';

const buf = n => { const b = readFileSync(new URL('../assets/fonts/' + n, import.meta.url)); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const noto = await loadFont(buf('NotoSerifHebrew.ttf'), 'Noto Serif Hebrew');
const frank = await loadFont(buf('FrankRuhlLibre.ttf'), 'Frank Ruhl Libre');
const notoBold = fontVariant(noto, { wght: 700 });
const fontsFor = (family, weight) => {
  const n = weight >= 700 ? notoBold : noto;
  return family === 'Frank Ruhl Libre' ? [weight >= 700 ? fontVariant(frank, { wght: weight }) : frank, n] : [n];
};

let seq = 0;
const newId = (p = 'x') => p + (++seq);
const model = () => withMaster(ensureMasters({
  units: 'pt',
  layout: { facing: true, binding: 'right', margins: { top: 40, bottom: 40, inside: 50, outside: 30 } },
  surfaces: Array.from({ length: 2 }, (_, i) => ({ id: 'p' + i, type: 'page', size: [400, 600], master: null, layers: [] })),
}));
const withMaster = m => {
  const ma = m.surfaces.find(s => s.type === 'master').id;
  return { ...m, surfaces: m.surfaces.map(s => s.type === 'page' ? { ...s, master: ma } : s) };
};

test('מפתח הגופן: רגיל — הקובץ המשתנה; מודגש — המופע הסטטי הקרוב', () => {
  assert.equal(fontKey(noto), 'fonts/NotoSerifHebrew.ttf');
  assert.equal(fontKey(notoBold), 'fonts/static/NotoSerifHebrew-700.ttf');
  assert.equal(fontKey(fontVariant(frank, { wght: 900 })), 'fonts/static/FrankRuhlLibre-900.ttf');
  assert.equal(fontKey(fontVariant(frank, { wght: 800 })), 'fonts/static/FrankRuhlLibre-700.ttf');
});

test('המופעים הסטטיים — אותם מזהי גליפים ואותן התקדמויות כמו הגופן המשתנה ב-wght 700', async () => {
  const st = await loadFont(buf('static/NotoSerifHebrew-700.ttf'), 's');
  const text = 'בְּרֵאשִׁ֖ית בָּרָ֣א אֱלֹהִ֑ים';
  const a = shapeLine([notoBold], text), b = shapeLine([st], text);
  assert.deepEqual(a.map(g => g.gid), b.map(g => g.gid));
  // המיקום מגיע מהרשימה (HarfBuzz על הגופן המשתנה); בין המופע לגופן המשתנה — לכל היותר עיגול של יחידה
  a.forEach((g, i) => ['ax', 'dx', 'dy'].forEach(k => assert.ok(Math.abs(g[k] - b[i][k]) <= 1, k)));
});

test('שורה עם גופן חלופי (טעמים ב-Frank) — רצף נפרד לכל גופן, ההתקדמויות מצטברות', () => {
  const text = 'בְּרֵאשִׁ֖ית בָּרָ֣א';
  const g = shapeLine([frank, noto], text);
  const ops = glyphOps(frank, g, 10, 100, 12, text);
  assert.ok(ops.length > 1);
  assert.ok(ops.some(o => o.font === 'fonts/FrankRuhlLibre.ttf') && ops.some(o => o.font === 'fonts/NotoSerifHebrew.ttf'));
  assert.equal(ops.map(o => o.text).join(''), text);   // כל רצף — החלק שלו, בסדר הקריאה
  const s = 12 / frank.upem;
  const total = ops.reduce((a, o) => a + o.glyphs.reduce((x, q) => x + q[3], 0), 0);
  assert.ok(Math.abs(total - advance(g) * s) < 0.05);
  const right = Math.max(...ops.map(o => o.x + o.glyphs.reduce((x, q) => x + q[3], 0)));
  assert.ok(Math.abs(right - (10 + advance(g) * s)) < 0.05);
  assert.equal(ops.reduce((a, o) => a + o.glyphs.length, 0), g.length);
});

test('רשימת המסמך: טקסט, תמונה עם חיתוך, ומספר עמוד — ובלי קווי עזר', () => {
  let m = model();
  ({ model: m } = addFrame(m, 0, { box: [30, 40, 320, 500], newId }));
  const flowId = Object.keys(m.flows)[0];
  m = setFlow(m, flowId, { doc: textToDoc('שלום עולם\nפסקה שנייה') });
  let img;
  ({ model: m, frameId: img } = addImageFrame(m, 1, { src: 'media/' + 'a'.repeat(64) + '.png', px: [200, 100], newId }));
  const text = layoutFlows(m, fontsFor);
  const list = drawList(m, text, fontsFor('Noto Serif Hebrew', 400), { title: 'ספר' });
  assert.equal(list.title, 'ספר');
  assert.equal(list.pages.length, 2);
  assert.deepEqual(list.pages[0].size, [400, 600]);
  const glyphs = list.pages[0].ops.filter(o => o.op === 'glyphs');
  assert.ok(glyphs.some(o => o.text === 'שלום עולם') && glyphs.some(o => o.text === 'פסקה שנייה'));
  // שורה עם מודגש באמצע: הרצפים בסדר הקריאה, והטקסט שלהם יחד — השורה כולה
  const mixed = shapeLine([noto], 'אחת שתיים שלוש', 'rtl', [{ start: 4, end: 9, fonts: [notoBold] }]);
  const mo = glyphOps(noto, mixed, 0, 0, 12, 'אחת שתיים שלוש');
  assert.deepEqual(mo.map(o => o.text), ['אחת ', 'שתיים', ' שלוש']);
  assert.deepEqual(mo.map(o => o.font), ['fonts/NotoSerifHebrew.ttf', 'fonts/static/NotoSerifHebrew-700.ttf', 'fonts/NotoSerifHebrew.ttf']);
  assert.ok(glyphs.some(o => o.text === '1'));   // מספר העמוד
  const im = list.pages[1].ops.find(o => o.op === 'image');
  const fr = m.surfaces.find(s => s.id === 'p1').layers.find(l => l.id === img);
  const r = imagePlacement(fr);
  assert.deepEqual(im.box, [r.x, r.y, r.w, r.h].map(v => Math.round(v * 1000) / 1000));
  assert.deepEqual(im.clip, fr.box);
  assert.ok(list.pages.every(p => p.ops.every(o => ['glyphs', 'image'].includes(o.op))));
  assert.doesNotThrow(() => JSON.stringify(list));
});
