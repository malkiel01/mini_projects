// בדיקות יחידה למנוע (חשבון טהור): node --test raster-studio/tests/engine.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as V from '../assets/js/engine/view.js';
import { deviceBudget, suggestSize, megapixels, BYTES_PER_PIXEL } from '../assets/js/engine/budget.js';
import { RasterDocument, tilesInRect, TILE } from '../assets/js/engine/doc.js';

const near = (a, b, eps = 1e-9) => assert.ok(Math.abs(a - b) < eps, `${a} ≉ ${b}`);

test('fit: מסמך גדול — מוקטן ובאמצע', () => {
  const v = V.fit(2000, 1000, 400, 800, 0);
  near(v.zoom, 0.2);
  near(v.x, 0);
  near(v.y, (800 - 200) / 2);
});

test('fit: מסמך קטן — לא מוגדל מעבר ל-100%', () => {
  const v = V.fit(100, 50, 400, 800);
  assert.equal(v.zoom, 1);
  near(v.x, 150);
});

test('zoomTo: הנקודה שמתחת לסמן נשארת במקום', () => {
  const v0 = { zoom: 0.5, x: 10, y: 20 };
  const [dx, dy] = V.screenToDoc(v0, 123, 77);
  const v1 = V.zoomTo(v0, 3, 123, 77);
  const [sx, sy] = V.docToScreen(v1, dx, dy);
  near(sx, 123); near(sy, 77);
});

test('zoomTo: גבולות 1% עד 6400%', () => {
  assert.equal(V.zoomTo({ zoom: 1, x: 0, y: 0 }, 1000, 0, 0).zoom, V.ZOOM_MAX);
  assert.equal(V.zoomTo({ zoom: 1, x: 0, y: 0 }, 0, 0, 0).zoom, V.ZOOM_MIN);
});

test('nextStep: מדרגות, גם מנקודה שבין מדרגות', () => {
  assert.equal(V.nextStep(1, 1), 1.5);
  assert.equal(V.nextStep(1, -1), 0.67);
  assert.equal(V.nextStep(0.4, 1), 0.5);
  assert.equal(V.nextStep(0.4, -1), 0.33);
  assert.equal(V.nextStep(64, 1), 64);
  assert.equal(V.nextStep(0.01, -1), 0.01);
});

test('pinch: האצבעות מתרחקות פי 2 — זום פי 2, והאמצע נשאר תחת האצבעות', () => {
  const v0 = { zoom: 1, x: 0, y: 0 };
  const v1 = V.pinch(v0, [100, 100], [200, 100], [50, 100], [250, 100]);
  near(v1.zoom, 2);
  const [dx] = V.screenToDoc(v0, 150, 100);
  near(V.docToScreen(v1, dx, 0)[0], 150);
});

test('pinch: שתי אצבעות זזות יחד — הזזה בלי זום', () => {
  const v1 = V.pinch({ zoom: 2, x: 5, y: 5 }, [0, 0], [100, 0], [30, 40], [130, 40]);
  near(v1.zoom, 2); near(v1.x, 35); near(v1.y, 45);
});

test('zoomLabel', () => {
  assert.equal(V.zoomLabel(1), '100%');
  assert.equal(V.zoomLabel(0.333), '33%');
  assert.equal(V.zoomLabel(0.015), '1.5%');
  assert.equal(V.zoomLabel(12), '1,200%');
});

test('תקציב: מחשב 8GB — רבע מהזיכרון', () => {
  const b = deviceBudget({ deviceMemory: 8 });
  assert.equal(b.bytes, 2 * 1024 ** 3);
  assert.equal(b.guessed, false);
  // 4 שכבות + עותק מורכב: מעל 40 מגה-פיקסל
  assert.ok(b.maxPixels() > 40e6 && b.maxPixels() < 60e6, String(b.maxPixels()));
});

test('תקציב: טלפון בלי מידע (Safari) — הערכה זהירה', () => {
  const b = deviceBudget({ mobile: true });
  assert.equal(b.guessed, true);
  assert.equal(b.memGB, 3);
  assert.ok(b.maxPixels() < deviceBudget({ deviceMemory: 8 }).maxPixels());
  // תמונת מצלמה של 12MP נכנסת; של 48MP — לא
  assert.ok(b.maxPixels() > 12e6);
  assert.ok(b.maxPixels() < 48e6);
});

test('תקציב: יותר שכבות — פחות פיקסלים', () => {
  const b = deviceBudget({ deviceMemory: 4 });
  assert.ok(b.maxPixels(10) < b.maxPixels(2));
  assert.ok(b.maxPixels(1) * BYTES_PER_PIXEL * 2 <= b.bytes + BYTES_PER_PIXEL * 2);
});

test('suggestSize: בתוך התקציב — null; מעל — אותו יחס ומתחת לתקרה', () => {
  assert.equal(suggestSize(1000, 1000, 2e6), null);
  const [w, h] = suggestSize(8000, 6000, 12e6);
  assert.ok(w * h <= 12e6);
  near(w / h, 8000 / 6000, 0.01);
  assert.equal(megapixels(8000, 6000), 48);
});

test('מסמך: גודל לא תקין נדחה; שכבות מלמטה למעלה', () => {
  assert.throws(() => new RasterDocument(0, 10), RangeError);
  assert.throws(() => new RasterDocument(10.5, 10), RangeError);
  const d = new RasterDocument(600, 300);
  const bg = d.addLayer({ name: 'רקע' });
  const top = d.addLayer();
  assert.deepEqual(d.layers, [bg, top]);
  assert.equal(top.blend, 'normal');
  assert.equal(d.tilesX, 3);
  assert.equal(d.tilesY, 2);
  assert.notEqual(bg.id, top.id);
});

test('tilesInRect: גבולות אריחים, וגם מחוץ לקנבס', () => {
  assert.deepEqual(tilesInRect(0, 0, TILE, TILE), [[0, 0]]);
  assert.deepEqual(tilesInRect(0, 0, TILE + 1, 1), [[0, 0], [1, 0]]);
  assert.deepEqual(tilesInRect(-1, -1, 2, 2), [[-1, -1], [0, -1], [-1, 0], [0, 0]]);
  assert.deepEqual(tilesInRect(5, 5, 0, 10), []);
});

test('מסמך: שכבה פעילה, הזזה ומחיקה', () => {
  const d = new RasterDocument(10, 10);
  const a = d.addLayer({ name: 'א' }), b = d.addLayer({ name: 'ב' }), c = d.addLayer({ name: 'ג' });
  assert.equal(d.active, c, 'שכבה חדשה — פעילה');
  const mid = d.addLayer({ name: 'אמצע', index: 1 });
  assert.deepEqual(d.layers.map(l => l.name), ['א', 'אמצע', 'ב', 'ג']);
  assert.equal(d.moveLayer(mid, 99), true);
  assert.deepEqual(d.layers.map(l => l.name), ['א', 'ב', 'ג', 'אמצע']);
  assert.equal(d.moveLayer(mid, 3), false, 'אותו מקום — לא זז');
  d.moveLayer(mid, -5);
  assert.equal(d.indexOf(mid), 0);
  d.activeId = b.id;
  assert.equal(d.removeLayer(b), true);
  assert.equal(d.active, c, 'אחרי מחיקת הפעילה — השכנה שמעל');
  d.activeId = c.id;
  d.removeLayer(c);
  assert.equal(d.active, a, 'מחיקת העליונה — זו שמתחת');
  d.removeLayer(mid);
  assert.equal(d.removeLayer(a), false, 'השכבה האחרונה לא נמחקת');
  assert.equal(d.layer(a.id), a);
  assert.equal(d.layer('אין'), null);
});

// ---- ביטול / ביצוע, מול רנדרר מדומה (בלי GPU): "מרקם" = אובייקט עם ערך ----
import { History, TILE_BYTES } from '../assets/js/engine/history.js';

function fakeRenderer() {
  const live = new Set();
  const tex = v => { const t = { v }; live.add(t); return t; };
  return {
    live, tex,
    snapshot: (layer, keys) => new Map(keys.map(k => [k, layer.tiles.has(k) ? tex(layer.tiles.get(k).v) : null])),
    freeTextures: ts => { for (const t of ts) if (t) live.delete(t); },
    freeLayer: l => { for (const t of l.tiles.values()) live.delete(t); l.tiles.clear(); },
    invalidate() {}, invalidateTiles() {},
  };
}
const paint = (r, layer, k, v) => { const old = layer.tiles.get(k); if (old) old.v = v; else layer.tiles.set(k, r.tex(v)); };
const val = (layer, k) => layer.tiles.get(k)?.v ?? null;

test('היסטוריה: פיקסלים — ביטול וביצוע בהחלפת מרקמים', () => {
  const r = fakeRenderer();
  const h = new History({ renderer: r, limitBytes: 1e9 });
  const d = new RasterDocument(600, 300);
  const l = d.addLayer();
  paint(r, l, '0,0', 'לבן');
  const before = r.snapshot(l, ['0,0', '1,0']);
  paint(r, l, '0,0', 'אדום'); paint(r, l, '1,0', 'אדום');
  h.pixels(d, l, before, 'מכחול');
  h.undo();
  assert.equal(val(l, '0,0'), 'לבן');
  assert.equal(val(l, '1,0'), null, 'אריח שלא היה — נעלם');
  h.redo();
  assert.equal(val(l, '0,0'), 'אדום');
  assert.equal(val(l, '1,0'), 'אדום');
  assert.equal(h.canRedo, false);
});

test('היסטוריה: פעולה חדשה אחרי ביטול — הביצוע נזרק והמרקמים משתחררים', () => {
  const r = fakeRenderer();
  const h = new History({ renderer: r, limitBytes: 1e9 });
  const d = new RasterDocument(300, 300);
  const l = d.addLayer();
  paint(r, l, '0,0', 'א');
  const b1 = r.snapshot(l, ['0,0']); paint(r, l, '0,0', 'ב'); h.pixels(d, l, b1, '1');
  h.undo();
  const liveBefore = r.live.size;
  const b2 = r.snapshot(l, ['0,0']); paint(r, l, '0,0', 'ג'); h.pixels(d, l, b2, '2');
  assert.equal(h.canRedo, false);
  assert.equal(r.live.size, liveBefore, 'העותק של "ב" שוחרר, ונוסף העותק של "א"');
  h.undo();
  assert.equal(val(l, '0,0'), 'א');
});

test('היסטוריה: תקרת זיכרון — הישנות נזרקות, האחרונה נשמרת', () => {
  const r = fakeRenderer();
  const h = new History({ renderer: r, limitBytes: TILE_BYTES * 3 });
  const d = new RasterDocument(300, 300);
  const l = d.addLayer();
  for (let i = 0; i < 6; i++) {
    paint(r, l, '0,0', i);
    const b = r.snapshot(l, ['0,0']); paint(r, l, '0,0', i + 0.5); h.pixels(d, l, b, String(i));
  }
  assert.equal(h.undoStack.length, 3);
  assert.ok(h.bytes <= TILE_BYTES * 3);
  assert.equal(r.live.size, 1 + 3, 'אריח השכבה + 3 עותקים');
  const big = new History({ renderer: r, limitBytes: 1 });
  const b = r.snapshot(l, ['0,0']); big.pixels(d, l, b, 'גדולה');
  assert.equal(big.undoStack.length, 1, 'רשומה גדולה מהתקרה — עדיין אפשר לבטל אותה');
});

test('היסטוריה: מספר צעדים מוגבל', () => {
  const r = fakeRenderer();
  const h = new History({ renderer: r, limitBytes: 1e12, maxSteps: 5 });
  const d = new RasterDocument(10, 10);
  const l = d.addLayer();
  for (let i = 0; i < 9; i++) h.props(d, l, { opacity: i / 10 }, { opacity: (i + 1) / 10 }, 'אטימות');
  assert.equal(h.undoStack.length, 5);
});

test('היסטוריה: שכבות — הוספה, מחיקה, הזזה ומאפיינים', () => {
  const r = fakeRenderer();
  const h = new History({ renderer: r, limitBytes: 1e9 });
  const d = new RasterDocument(10, 10);
  const a = d.addLayer({ name: 'א' });
  const b = d.addLayer({ name: 'ב' });
  h.addLayer(d, b, 'הוספה');
  h.undo();
  assert.deepEqual(d.layers.map(l => l.name), ['א']);
  assert.equal(d.active, a);
  h.redo();
  assert.deepEqual(d.layers.map(l => l.name), ['א', 'ב']);
  assert.equal(d.active, b);

  paint(r, b, '0,0', 'תוכן');
  const i = d.indexOf(b);
  d.removeLayer(b);
  h.removeLayer(d, b, i, 'מחיקה');
  assert.deepEqual(d.layers.map(l => l.name), ['א']);
  h.undo();
  assert.deepEqual(d.layers.map(l => l.name), ['א', 'ב']);
  assert.equal(val(b, '0,0'), 'תוכן', 'האריחים שרדו את המחיקה');
  h.redo();
  assert.deepEqual(d.layers.map(l => l.name), ['א']);

  h.undo();
  d.moveLayer(b, 0);
  h.moveLayer(d, b, 1, 0, 'סדר');
  h.undo();
  assert.deepEqual(d.layers.map(l => l.name), ['א', 'ב']);
  h.redo();
  assert.deepEqual(d.layers.map(l => l.name), ['ב', 'א']);

  b.opacity = 0.3;
  h.props(d, b, { opacity: 1 }, { opacity: 0.3 }, 'אטימות');
  h.undo();
  assert.equal(b.opacity, 1);
  h.redo();
  assert.equal(b.opacity, 0.3);
});

test('היסטוריה: מחיקה שנזרקת מהעבר — האריחים משתחררים; הוספה שבוטלה ונזרקה — גם', () => {
  const r = fakeRenderer();
  const h = new History({ renderer: r, limitBytes: 1e9, maxSteps: 1 });
  const d = new RasterDocument(10, 10);
  d.addLayer();
  const b = d.addLayer();
  paint(r, b, '0,0', 'x');
  d.removeLayer(b);
  h.removeLayer(d, b, 1, 'מחיקה');
  h.props(d, d.layers[0], { name: 'a' }, { name: 'b' }, 'שם');   // דוחק את המחיקה החוצה
  assert.equal(b.tiles.size, 0, 'השכבה שנמחקה שוחררה');

  const c = d.addLayer();
  paint(r, c, '0,0', 'y');
  h.addLayer(d, c, 'הוספה');
  h.undo();
  h.props(d, d.layers[0], { name: 'b' }, { name: 'c' }, 'שם');   // הביצוע של ההוספה נזרק
  assert.equal(c.tiles.size, 0, 'שכבה שבוטלה ולא תחזור — שוחררה');
});

test('היסטוריה: clear', () => {
  const r = fakeRenderer();
  const h = new History({ renderer: r, limitBytes: 1e9 });
  const d = new RasterDocument(10, 10);
  const l = d.addLayer();
  const b = r.snapshot(l, ['0,0']);
  h.pixels(d, l, b, 'x');
  h.clear();
  assert.equal(h.canUndo || h.canRedo, false);
});

// ---- מילוי ומשיכות ----
import { floodFill, hexToRgb, rgbToHex, dabsAlong } from '../assets/js/engine/fill.js';

function img(w, h, f) {
  const px = new Uint8ClampedArray(w * h * 4);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) px.set(f(x, y), (y * w + x) * 4);
  return px;
}

test('דלי: ממלא אזור סגור ולא חוצה קו', () => {
  // מסגרת שחורה בעובי 1 סביב ריבוע 6×6 בתוך 10×10 לבן
  const px = img(10, 10, (x, y) => (x === 2 || x === 7 || y === 2 || y === 7) && x >= 2 && x <= 7 && y >= 2 && y <= 7 ? [0, 0, 0, 255] : [255, 255, 255, 255]);
  const inside = floodFill(px, 10, 10, 4, 4, 32);
  assert.equal(inside.count, 16);
  assert.deepEqual(inside.box, [3, 3, 6, 6]);
  const outside = floodFill(px, 10, 10, 0, 0, 32);
  assert.equal(outside.count, 100 - 36);
  assert.equal(outside.mask[4 * 10 + 4], 0, 'לא נכנס פנימה');
});

test('דלי: סבולת — צבע קרוב נכלל, רחוק לא', () => {
  const px = img(4, 1, x => [[100, 100, 100, 255], [120, 100, 100, 255], [140, 100, 100, 255], [100, 100, 100, 255]][x]);
  assert.equal(floodFill(px, 4, 1, 0, 0, 20).count, 2);
  assert.equal(floodFill(px, 4, 1, 0, 0, 40).count, 4);
  assert.equal(floodFill(px, 4, 1, 0, 0, 0).count, 1);
});

test('דלי: שקוף — כל השקופים שווים, בלי קשר לצבע', () => {
  const px = img(3, 1, x => [[0, 0, 0, 0], [255, 255, 255, 0], [9, 9, 9, 255]][x]);
  assert.equal(floodFill(px, 3, 1, 0, 0, 0).count, 2);
});

test('דלי: מחוץ לתמונה — כלום; ספירלה גדולה בלי רקורסיה', () => {
  const px = img(5, 5, () => [0, 0, 0, 255]);
  assert.equal(floodFill(px, 5, 5, -1, 0).count, 0);
  const big = img(800, 800, (x, y) => (x % 4 === 2 && y !== (x % 8 === 2 ? 0 : 799)) ? [0, 0, 0, 255] : [255, 255, 255, 255]);
  const r = floodFill(big, 800, 800, 0, 0, 10);
  assert.ok(r.count > 400 * 800, String(r.count));
});

test('צבע: hex ↔ rgb', () => {
  assert.deepEqual(hexToRgb('#ff8000'), [255, 128, 0]);
  assert.equal(rgbToHex([1, 2, 255]), '#0102ff');
});

test('משיכה: נקודות במרחק קבוע, גם בין קטעים', () => {
  const a = { x: 0, y: 0, p: 0 }, b = { x: 10, y: 0, p: 1 };
  const { dabs, carry } = dabsAlong(a, b, 4);
  assert.deepEqual(dabs.map(d => d.x), [4, 8]);
  near(dabs[0].p, 0.4);
  near(carry, 2);
  const next = dabsAlong(b, { x: 13, y: 0, p: 1 }, 4, carry);
  assert.deepEqual(next.dabs.map(d => d.x), [12]);
  near(next.carry, 1);
  const tiny = dabsAlong(b, { x: 11, y: 0, p: 1 }, 4, 1);
  assert.equal(tiny.dabs.length, 0);
  near(tiny.carry, 2);
});

// ---- בחירה ----
import { shapeBox, selectionBox, combine, isEmptyShape } from '../assets/js/engine/selection.js';

test('בחירה: מלבן חוסם, חתוך לקנבס ובמספרים שלמים', () => {
  const sel = { shapes: [{ op: 'add', type: 'rect', x: -10, y: 5.5, w: 50, h: 20 }], inverted: false };
  assert.deepEqual(selectionBox(sel, 100, 100), [0, 5, 40, 26]);
  assert.deepEqual(selectionBox({ ...sel, inverted: true }, 100, 100), [0, 0, 100, 100]);
  assert.equal(selectionBox({ shapes: [{ op: 'add', type: 'rect', x: 200, y: 0, w: 10, h: 10 }] }, 100, 100), null, 'מחוץ לקנבס');
  assert.equal(selectionBox(null, 10, 10), null);
  assert.deepEqual(shapeBox({ type: 'poly', pts: [[5, 1], [2, 9], [8, 4]] }), [2, 1, 8, 9]);
});

test('בחירה: חדשה / הוספה / חיסור, וגם בבחירה הפוכה', () => {
  const a = { type: 'rect', x: 0, y: 0, w: 10, h: 10 }, b = { type: 'ellipse', x: 5, y: 5, w: 10, h: 10 };
  const s1 = combine(null, a, 'new');
  assert.deepEqual(s1.shapes.map(s => s.op), ['add']);
  assert.equal(combine(null, a, 'sub'), null, 'חיסור מכלום — כלום');
  assert.deepEqual(combine(null, a, 'add').shapes.length, 1);
  const s2 = combine(s1, b, 'sub');
  assert.deepEqual(s2.shapes.map(s => s.op), ['add', 'sub']);
  assert.equal(combine(s2, b, 'new').shapes.length, 1);
  const inv = { ...s1, inverted: true };
  assert.equal(combine(inv, b, 'add').shapes.at(-1).op, 'sub', 'בהפוכה, הוספה = חיסור מהצורות');
  assert.equal(combine(inv, b, 'sub').shapes.at(-1).op, 'add');
  assert.equal(isEmptyShape({ type: 'rect', x: 3, y: 3, w: 0, h: 9 }), true);
  assert.equal(isEmptyShape({ type: 'poly', pts: [[0, 0], [9, 9]] }), true);
});
