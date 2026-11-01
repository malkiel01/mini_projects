// בדיקות ציורים מהווקטורי בעימוד: node --test layout-studio/tests/vector.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { mul, transformItems, reId, placeDrawing, moveDrawing, isDrawing, symbolRefs, imageRefs, gapsOf, drawingToDoc, pruneSymbols } from '../assets/js/vector.js';
import { create, save, open, PREVIEW } from '../assets/js/mbe/mbe.js';
import { allItems, frameAt, drawingsOnPage } from '../assets/js/frames.js';
import { drawList } from '../assets/js/pdf.js';

const apply = (m, [x, y]) => [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];
let n = 0;
const fresh = () => 'v' + (++n);

test('מכפלת מטריצות: קודם הפנימית, אחר כך החיצונית (כמו ב-SVG)', () => {
  const T = [2, 0, 0, 2, 10, 20], t = [1, 0, 0, 1, 5, 0];
  assert.deepEqual(apply(mul(T, t), [1, 1]), apply(T, apply(t, [1, 1])));
});

test('הזזה של פריטים: נתיב ומופע — transform מורכב; קבוצה — דרך הילדים; לא מוכר — כמו שהוא', () => {
  const items = [{ id: 'a', d: 'M0 0 L1 1' }, { id: 'g', type: 'group', children: [{ id: 'b', d: 'M0 0', transform: [1, 0, 0, 1, 3, 0] }] },
    { id: 'u', type: 'use', symbol: 's' }, { id: 'z', type: 'future' }];
  const out = transformItems(items, [2, 0, 0, 2, 100, 0]);
  assert.deepEqual(out[0].transform, [2, 0, 0, 2, 100, 0]);
  assert.equal(out[1].transform, undefined);
  assert.deepEqual(out[1].children[0].transform, [2, 0, 0, 2, 106, 0]);
  assert.deepEqual(out[2].transform, [2, 0, 0, 2, 100, 0]);
  assert.deepEqual(out[3], items[3]);
});

const drawing = () => ({
  surfaces: [{ id: 'a1', type: 'artboard', size: [200, 100], layers: [
    { id: 'L1', type: 'vector', paths: [{ id: 'p1', d: 'M0 0 L200 100', fill: { color: '#ff0000' } },
      { id: 'u1', type: 'use', symbol: 'star' }, { id: 't1', type: 'text', text: 'שלום', box: [0, 0, 50, 20] },
      { id: 'i1', type: 'image', src: 'media/' + 'a'.repeat(64) + '.png', box: [0, 0, 10, 10] }] },
    { id: 'L2', type: 'vector', visible: false, paths: [{ id: 'hidden', d: 'M0 0' }] },
    { id: 'R', type: 'raster', src: 'media/x.png' }] }],
  symbols: { star: { name: 'כוכב', paths: [{ id: 's1', d: 'M0 0 L1 0 Z' }] }, unused: { name: 'לא', paths: [] } },
});

test('הצבת ציור: היחס נשמר, צמוד לימין ולמעלה; מזהים וסמלים חדשים; שכבה מוסתרת לא נכנסת; דוח', () => {
  const r = placeDrawing(drawing(), [30, 40, 300, 400], fresh, 'לוגו');
  assert.equal(r.layer.type, 'vector');
  assert.equal(r.layer.name, 'לוגו');
  assert.deepEqual(r.layer.box, [30, 40, 300, 150]);   // 200×100 בתוך 300×400 → פי 1.5
  assert.deepEqual(r.layer.paths[0].transform, [1.5, 0, 0, 1.5, 30, 40]);
  assert.ok(!JSON.stringify(r.layer).includes('hidden'));
  assert.ok(r.layer.paths.every(p => !['p1', 'u1', 't1', 'i1'].includes(p.id)));
  const [sym] = Object.keys(r.symbols);
  assert.equal(Object.keys(r.symbols).length, 1);           // רק הסמל שבשימוש
  assert.equal(r.layer.paths[1].symbol, sym);
  assert.notEqual(r.symbols[sym].paths[0].id, 's1');
  assert.deepEqual([...r.images], ['media/' + 'a'.repeat(64) + '.png']);
  assert.deepEqual(r.gaps, { text: 1, effects: 0, unknown: 0 });
  assert.throws(() => placeDrawing({ surfaces: [{ size: [10, 10], layers: [] }] }, [0, 0, 10, 10], fresh), /no_vector/);
});

test('הזזה ושינוי גודל של ציור מוצב — הנתיבים עוברים יחד עם ה-box', () => {
  const { layer } = placeDrawing(drawing(), [0, 0, 200, 100], fresh);
  const moved = moveDrawing(layer, [50, 60, 100, 50]);
  assert.deepEqual(moved.box, [50, 60, 100, 50]);
  assert.deepEqual(apply(moved.paths[0].transform, [200, 100]), [150, 110]);   // הפינה הרחוקה — בפינה החדשה
  assert.ok(isDrawing(moved) && !isDrawing({ type: 'vector', paths: [] }));
});

test('בחירה ו-PDF: ציור נבחר בהקשה; תמונות "מתחת" נכנסות ראשונות ברשימה', () => {
  const { layer } = placeDrawing(drawing(), [0, 0, 200, 100], fresh);
  const model = { layout: { facing: false, margins: { top: 0, bottom: 0, inside: 0, outside: 0 } },
    surfaces: [{ id: 'p', type: 'page', size: [400, 600], master: null, layers: [layer] }], flows: {} };
  assert.equal(allItems(model).length, 1);
  assert.equal(drawingsOnPage(model, 0).length, 1);
  assert.equal(frameAt(model, 0, 10, 10)?.id, layer.id);
  const list = drawList(model, null, [], { under: new Map([[0, [{ src: 'media/' + 'b'.repeat(64) + '.png', box: [0, 0, 200, 100] }]]]) });
  assert.deepEqual(list.pages[0].ops[0], { op: 'image', src: 'media/' + 'b'.repeat(64) + '.png', box: [0, 0, 200, 100] });
});

test('עזרים: סמלים מקוננים, תמונות בקבוצה, אפקטים', () => {
  const syms = { a: { paths: [{ id: 'x', type: 'use', symbol: 'b' }] }, b: { paths: [] } };
  assert.deepEqual([...symbolRefs([{ id: 'u', type: 'use', symbol: 'a' }], syms)].sort(), ['a', 'b']);
  assert.deepEqual([...imageRefs([{ type: 'group', children: [{ type: 'image', src: 'media/q.png' }] }])], ['media/q.png']);
  assert.equal(gapsOf([{ id: 'e', d: 'M0 0', effects: [{ type: 'shadow' }] }]).effects, 1);
  assert.equal(reId([{ id: 'a', children: [{ id: 'b' }] }], () => 'n').every(x => x.id === 'n' && x.children[0].id === 'n'), true);
});

test('עריכה בווקטורי: ציור מוצב → מסמך vector תקין בליבה → חזרה לאותה מסגרת בלי שינוי', async () => {
  const src = drawing();
  src.surfaces[0].layers[0].paths = src.surfaces[0].layers[0].paths.filter(p => p.type !== 'image');   // תמונה — נבדקת בדפדפן
  const placed = placeDrawing(src, [30, 40, 300, 400], fresh, 'לוגו');
  const doc = drawingToDoc(placed.layer, placed.symbols);
  assert.deepEqual(doc.surfaces[0].size, [300, 150]);
  assert.deepEqual(doc.surfaces[0].layers[0].paths[0].transform, [1.5, 0, 0, 1.5, 0, 0]);   // בקואורדינטות הלוח
  assert.deepEqual(Object.keys(doc.symbols), Object.keys(placed.symbols));
  // הליבה מקבלת אותו כקובץ ציור (הבודק רץ ב-open)
  const f = create('vector', doc);
  f.setRendition(PREVIEW, new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]), { type: 'image/png' });
  const back = await open(await save(f, { app: 'layout-studio', appVersion: '1' }));
  assert.equal(back.kind, 'vector');
  // חזרה: הלוח נכנס בדיוק למסגרת, והנתיבים חוזרים למקום
  const r = placeDrawing(back.document, placed.layer.box, fresh, 'לוגו');
  assert.deepEqual(r.layer.box, placed.layer.box);
  assert.deepEqual(r.layer.paths[0].transform, placed.layer.paths[0].transform);
  assert.equal(r.layer.paths[0].d, placed.layer.paths[0].d);
});

test('ניקוי סמלים: נשארים רק אלה שציור בעמודים מפנה אליהם', () => {
  const placed = placeDrawing(drawing(), [0, 0, 200, 100], fresh);
  const [sym] = Object.keys(placed.symbols);
  const page = { id: 'p1', type: 'page', layers: [placed.layer] };
  const model = { surfaces: [page], symbols: { ...placed.symbols, old: { paths: [] } } };
  assert.deepEqual(Object.keys(pruneSymbols(model).symbols), [sym]);
  const empty = pruneSymbols({ ...model, surfaces: [{ ...page, layers: [] }] });
  assert.equal(empty.symbols, undefined);
  const same = { surfaces: [page], symbols: placed.symbols };
  assert.equal(pruneSymbols(same), same);
});
