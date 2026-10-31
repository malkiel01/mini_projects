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

// ---- שינוי צורה ----
import * as T from '../assets/js/engine/transform.js';

test('שינוי צורה: זהות — הפינות במקום, והמטריצה הפיכה', () => {
  const t = T.identity(10, 20, 100, 50);
  assert.deepEqual(T.corners(t, 100, 50), [[10, 20], [110, 20], [110, 70], [10, 70]]);
  assert.deepEqual(T.bounds(t, 100, 50), [10, 20, 110, 70]);
  assert.equal(T.isIdentityAt(t, 10, 20, 100, 50), true);
  const m = T.matrix({ ...t, angle: 0.7, sx: 1.3, sy: 0.4 }, 100, 50);
  const [x, y] = T.apply(T.invert(m), ...T.apply(m, 33, 17));
  near(x, 33); near(y, 17);
});

test('שינוי צורה: סיבוב 90° סביב המרכז', () => {
  const t = { ...T.identity(0, 0, 100, 50), angle: Math.PI / 2 };
  const c = T.corners(t, 100, 50);
  near(c[0][0], 75); near(c[0][1], -25);   // הפינה השמאלית-עליונה מסתובבת לימין-למעלה
  assert.deepEqual(T.bounds(t, 100, 50), [25, -25, 75, 75]);
});

test('שינוי צורה: גרירת פינה — יחס נשמר, הפינה שממול במקום', () => {
  const t0 = T.identity(0, 0, 100, 50);
  const t = T.dragHandle(t0, 100, 50, [1, 1], [200, 100]);
  near(t.sx, 2); near(t.sy, 2);
  const c = T.corners(t, 100, 50);
  near(c[0][0], 0); near(c[0][1], 0);
  near(c[2][0], 200); near(c[2][1], 100);
  // גרירה לא על האלכסון — עדיין שומר יחס (הטלה)
  const t2 = T.dragHandle(t0, 100, 50, [1, 1], [200, 50]);
  near(t2.sx / t2.sy, 1);
  // חופשי
  const t3 = T.dragHandle(t0, 100, 50, [1, 1], [200, 50], true);
  near(t3.sx, 2); near(t3.sy, 1);
});

test('שינוי צורה: צלע — ציר אחד; גם בתוכן מסובב; ולא פחות מפיקסל', () => {
  const t0 = T.identity(0, 0, 100, 50);
  const t = T.dragHandle(t0, 100, 50, [-1, 0], [-100, 999]);
  near(t.sx, 2); near(t.sy, 1);
  near(T.corners(t, 100, 50)[1][0], 100, 1e-9);   // הצלע הימנית לא זזה
  const r = { ...t0, angle: Math.PI / 2 };
  // מסובב 90°: הצלע "הימנית" של התוכן פונה למטה; גרירתה ל-y=125 (מרכז 25, עוגן ב-25−50) → רוחב 150
  const tr = T.dragHandle(r, 100, 50, [1, 0], [50, 125]);
  near(tr.sx, 1.5, 1e-9); near(tr.sy, 1);
  const tiny = T.dragHandle(t0, 100, 50, [1, 0], [-500, 0]);
  near(tiny.sx, 1 / 100);
});

test('שינוי צורה: סיבוב בגרירה, ועם Shift — מדרגות 15°', () => {
  const t0 = T.identity(0, 0, 100, 100);   // מרכז (50, 50)
  const t = T.dragRotate(t0, [100, 50], [50, 100]);
  near(t.angle, Math.PI / 2);
  const s = T.dragRotate(t0, [100, 50], [100, 62], true);
  near(s.angle, Math.PI / 12);
});

test('שינוי צורה: פגיעה — ידית, בפנים, סיבוב ליד פינה, כלום רחוק', () => {
  const t = T.identity(0, 0, 100, 100);
  assert.deepEqual(T.hit(t, 100, 100, [101, 99], 4), { kind: 'handle', handle: [1, 1] });
  assert.equal(T.hit(t, 100, 100, [50, 50], 4).kind, 'move');
  assert.equal(T.hit(t, 100, 100, [108, 108], 4).kind, 'rotate');
  assert.equal(T.hit(t, 100, 100, [300, 300], 4), null);
  assert.equal(T.hit(t, 100, 100, [150, 50], 4), null, 'ליד צלע, לא פינה — לא סיבוב');
});

test('שינוי צורה: הבחירה זזה עם התוכן', () => {
  const t = { ...T.identity(10, 10, 20, 20), cx: 120, cy: 20 };   // הזזה ב-100 בציר x
  const m = T.docMatrix(t, 20, 20, 10, 10);
  assert.deepEqual(T.transformShape({ op: 'add', type: 'rect', x: 10, y: 10, w: 20, h: 20 }, m),
    { op: 'add', type: 'rect', x: 110, y: 10, w: 20, h: 20 });
  const rot = T.docMatrix({ ...T.identity(0, 0, 10, 10), angle: Math.PI }, 10, 10, 0, 0);
  const p = T.transformShape({ op: 'add', type: 'rect', x: 0, y: 0, w: 10, h: 10 }, rot);
  assert.equal(p.type, 'poly');
  near(p.pts[0][0], 10); near(p.pts[0][1], 10);
});

// ---- קבצים: PNG, ZIP, ORA, ‎.mbe ----
import { encodePNG, crc32, sha256Hex } from '../assets/js/engine/png.js';
import { zipStore } from '../assets/js/engine/zip.js';
import { oraStack, parseOraStack, mbeDocument, gapsFor, mediaRefs, canvasSurface, editSurface } from '../assets/js/engine/formats.js';
import { inflateSync } from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

test('CRC-32 — וקטור ידוע', () => {
  assert.equal(crc32(new TextEncoder().encode('123456789')), 0xCBF43926);
});

test('PNG: הבתים חוזרים בדיוק, כולל שקיפות חלקית', async () => {
  const w = 37, h = 5;
  const px = new Uint8Array(w * h * 4).map((_, i) => (i * 37 + (i >> 2)) & 0xFF);
  const png = await encodePNG(px, w, h);
  assert.deepEqual([...png.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10]);
  // פענוח ידני: IDAT → inflate → ביטול מסנן Sub
  let o = 8, idat = [];
  while (o < png.length) {
    const len = new DataView(png.buffer, png.byteOffset + o).getUint32(0);
    const type = String.fromCharCode(...png.subarray(o + 4, o + 8));
    const body = png.subarray(o + 8, o + 8 + len);
    assert.equal(new DataView(png.buffer, png.byteOffset + o + 8 + len).getUint32(0), crc32(png.subarray(o + 4, o + 8 + len)), 'CRC של ' + type);
    if (type === 'IDAT') idat.push(body);
    o += 12 + len;
  }
  const raw = inflateSync(Buffer.concat(idat));
  const back = new Uint8Array(w * h * 4);
  for (let y = 0; y < h; y++) {
    assert.equal(raw[y * (w * 4 + 1)], 1);
    for (let i = 0; i < w * 4; i++) back[y * w * 4 + i] = (raw[y * (w * 4 + 1) + 1 + i] + (i >= 4 ? back[y * w * 4 + i - 4] : 0)) & 0xFF;
  }
  assert.deepEqual([...back], [...px]);
});

test('SHA-256 — בהקס', async () => {
  assert.equal(await sha256Hex(new TextEncoder().encode('abc')), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});

test('ZIP: mimetype ראשון ולא דחוס, ו-unzip קורא הכול', () => {
  const zip = zipStore([{ name: 'mimetype', data: 'image/openraster' }, { name: 'data/שכבה.png', data: new Uint8Array([1, 2, 3]) }]);
  assert.equal(new TextDecoder().decode(zip.subarray(30, 38)), 'mimetype');
  assert.equal(new TextDecoder().decode(zip.subarray(38, 54)), 'image/openraster');
  const dir = mkdtempSync(join(tmpdir(), 'rs-zip-'));
  try {
    writeFileSync(join(dir, 'a.ora'), zip);
    const out = execFileSync('unzip', ['-t', join(dir, 'a.ora')]).toString();
    assert.match(out, /No errors detected/);
  } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('ORA: stack.xml — העליונה ראשונה, מצבי מיזוג, הסתרה ונעילה; ובחזרה', () => {
  const layers = [
    { name: 'רקע', src: 'data/0.png', opacity: 1, visible: true, blend: 'normal', locked: true },
    { name: 'צל "כהה" & <עמוק>', src: 'data/1.png', opacity: 0.5, visible: false, blend: 'multiply', locked: false },
  ];
  const xml = oraStack(600, 400, layers);
  assert.ok(xml.indexOf('data/1.png') < xml.indexOf('data/0.png'));
  assert.match(xml, /composite-op="svg:multiply"/);
  assert.match(xml, /visibility="hidden"/);
  assert.match(xml, /edit-locked="true"/);
  assert.match(xml, /&quot;כהה&quot; &amp; &lt;עמוק&gt;/);
  const back = parseOraStack(xml);
  assert.equal(back.w, 600);
  assert.deepEqual(back.layers.map(l => [l.name, l.src, l.opacity, l.visible, l.blend, l.locked]),
    layers.map(l => [l.name, l.src, l.opacity, l.visible, l.blend, l.locked]));
});

test('‎.mbe: document.json — canvas אחד, שכבות raster, ושמירת מה שלא הבנו', () => {
  const doc = { width: 600, height: 400 };
  const layers = [{ id: 'L1', name: 'רקע', src: 'media/ab.png', opacity: 1, blend: 'normal', visible: true, locked: false }];
  const d = mbeDocument(doc, layers);
  assert.equal(d.units, 'pt');
  assert.deepEqual(d.surfaces[0].size, [600, 400]);
  assert.equal(d.surfaces[0].type, 'canvas');
  assert.equal(d.surfaces[0].layers[0].src, 'media/ab.png');
  const base = { surfaceId: 'c9', document: { fonts: [{ family: 'X' }], future: 7, surfaces: [{ id: 'c9' }, { id: 'other', type: 'page' }] } };
  const d2 = mbeDocument(doc, layers, base);
  assert.equal(d2.future, 7, 'שדה לא מוכר נשמר');
  assert.deepEqual(d2.surfaces.map(s => s.id), ['c9', 'other'], 'משטח של אפליקציה אחרת נשמר');
});

test('פערים: ORA — מיזוג; PNG — שכבות ומוסתרות; JPG — שקיפות', () => {
  const L = [{ blend: 'multiply', opacity: 1, visible: true }, { blend: 'normal', opacity: 1, visible: false }];
  assert.deepEqual(gapsFor('ora', L), ['blend-space:linear → srgb']);
  assert.deepEqual(gapsFor('ora', [{ blend: 'normal', opacity: 1, visible: true }]), []);
  assert.deepEqual(gapsFor('png', L), ['layers → flattened', 'hidden-layers → omitted']);
  assert.deepEqual(gapsFor('jpg', [L[0]]), ['alpha → white']);
});

// ---- קריאת ZIP (לפתיחת ORA) ----
import { unzip } from '../assets/js/engine/unzip.js';

test('unzip: קורא את מה ש-zipStore כתב', async () => {
  const z = zipStore([{ name: 'mimetype', data: 'image/openraster' }, { name: 'data/א.png', data: new Uint8Array([9, 8, 7]) }]);
  const m = await unzip(z);
  assert.equal(new TextDecoder().decode(m.get('mimetype')), 'image/openraster');
  assert.deepEqual([...m.get('data/א.png')], [9, 8, 7]);
});

test('unzip: deflate (כמו ש-GIMP ו-zip כותבים)', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'rs-unzip-'));
  try {
    writeFileSync(join(dir, 'stack.xml'), '<image w="5" h="5"><stack></stack></image>'.repeat(50));
    execFileSync('zip', ['-q', '-9', join(dir, 'a.ora'), 'stack.xml'], { cwd: dir });
    const m = await unzip((await import('node:fs')).readFileSync(join(dir, 'a.ora')));
    assert.match(new TextDecoder().decode(m.get('stack.xml')), /^<image w="5"/);
  } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('unzip: נתיב עוין, פצצת דחיסה וקובץ שבור — נדחים', async () => {
  const evil = zipStore([{ name: '../etc/passwd', data: 'x' }]);
  await assert.rejects(unzip(evil), /zip-unsafe/);
  const bomb = zipStore([{ name: 'a', data: new Uint8Array(10) }]);
  // גודל פתוח מוצהר ענק מול דחוס קטן
  const v = new DataView(bomb.buffer);
  const cd = bomb.length - 22 - 47;
  v.setUint32(cd + 24, 500 * 1024 ** 2, true);
  await assert.rejects(unzip(bomb), /zip-too-big/);
  await assert.rejects(unzip(new Uint8Array([1, 2, 3])), /zip/);
});

// ---- קבוצות ----
test('קבוצות: עץ, חיפוש, הוספה ליד הפעילה בתוך הקבוצה', () => {
  const d = new RasterDocument(10, 10);
  const bg = d.addLayer({ name: 'רקע' });
  const g = d.addGroup({ name: 'קבוצה' });
  const a = d.addLayer({ name: 'א', parent: g });
  assert.equal(d.active, a);
  const b = d.addLayer({ name: 'ב', index: d.indexOf(a) + 1 });   // ליד הפעילה — באותה קבוצה
  assert.deepEqual(g.children.map(l => l.name), ['א', 'ב']);
  assert.equal(d.listOf(b), g.children);
  assert.equal(d.parentOf(b), g);
  assert.equal(d.layer(b.id), b);
  assert.deepEqual(d.rasters().map(l => l.name), ['רקע', 'א', 'ב']);
  assert.deepEqual([...d.walk()].map(w => w.layer.name + '/' + w.depth), ['רקע/0', 'קבוצה/0', 'א/1', 'ב/1']);
  const top = d.addLayer({ name: 'עליונה' });   // בלי index — למעלה במסמך
  assert.equal(d.layers.at(-1), top);
  void bg;
});

test('קבוצות: העברה פנימה והחוצה, וקבוצה לא נכנסת לתוך עצמה', () => {
  const d = new RasterDocument(10, 10);
  const x = d.addLayer({ name: 'x' });
  const g = d.addGroup();
  const inner = d.addGroup({ parent: g });
  assert.equal(d.reparent(x, g.children, 0), true);
  assert.deepEqual(g.children.map(l => l.name), ['x', inner.name]);
  assert.equal(d.reparent(g, inner.children, 0), false, 'לא לתוך צאצא');
  assert.equal(d.reparent(g, g.children, 0), false, 'לא לתוך עצמה');
  assert.equal(d.reparent(x, d.layers, d.layers.length), true);
  assert.equal(d.layers.at(-1), x);
  assert.equal(d.contains(g, inner), true);
});

test('קבוצות: מחיקת קבוצה עם הפעילה בתוכה — הפעילה עוברת החוצה', () => {
  const d = new RasterDocument(10, 10);
  const bg = d.addLayer();
  const g = d.addGroup();
  const a = d.addLayer({ parent: g });
  assert.equal(d.active, a);
  assert.equal(d.removeLayer(g), true);
  assert.equal(d.active, bg);
  assert.equal(d.removeLayer(bg), false, 'הפריט האחרון לא נמחק');
});

test('ORA: קבוצות — stack מקונן, וחזרה לעץ', () => {
  const tree = [
    { name: 'רקע', src: 'data/0.png', opacity: 1, visible: true, blend: 'normal', locked: false },
    { type: 'group', name: 'קבוצה', opacity: 0.5, visible: true, blend: 'screen', locked: false, children: [
      { name: 'א', src: 'data/1.png', opacity: 1, visible: true, blend: 'normal', locked: false },
      { type: 'group', name: 'פנימית', opacity: 1, visible: false, blend: 'normal', locked: true, children: [
        { name: 'ב', src: 'data/2.png', opacity: 1, visible: true, blend: 'multiply', locked: false }] },
    ] },
  ];
  const xml = oraStack(10, 10, tree);
  assert.match(xml, /<stack name="קבוצה" opacity="0.5" visibility="visible" composite-op="svg:screen" isolation="isolate">/);
  assert.ok(xml.indexOf('קבוצה') < xml.indexOf('רקע'), 'העליונה ראשונה');
  const back = parseOraStack(xml);
  const show = l => l.map(x => x.type === 'group' ? `${x.name}[${show(x.children)}]${x.opacity}${x.visible ? '' : 'H'}${x.locked ? 'L' : ''}` : `${x.name}:${x.src}:${x.blend}`).join(',');
  assert.equal(show(back.layers), show(tree));
  assert.equal(back.w, 10);
});

test('פערים: קבוצות נספרות כשכבות', () => {
  assert.deepEqual(gapsFor('png', [{ type: 'group', children: [{ blend: 'normal', opacity: 1, visible: true }], visible: true, blend: 'normal', opacity: 1 }]), ['layers → flattened']);
});

test('‎.mbe: קבוצה עם children', () => {
  const d = mbeDocument({ width: 5, height: 5 }, [{ id: 'G1', type: 'group', name: 'ק', opacity: 1, blend: 'normal', visible: true, locked: false,
    children: [{ id: 'L1', name: 'א', src: 'media/x.png', opacity: 1, blend: 'normal', visible: true, locked: false }] }]);
  assert.equal(d.surfaces[0].layers[0].type, 'group');
  assert.equal(d.surfaces[0].layers[0].children[0].src, 'media/x.png');
});

// ---- מדידת ביצועים ----
import { perfFactor, perfLabel, perfCacheValid, PERF_FAST, PERF_SLOW } from '../assets/js/engine/budget.js';

test('ביצועים: מקדם התקציב בין 0.5 ל-1, עולה עם הציון', () => {
  assert.equal(perfFactor(undefined), 1, 'לא נמדד — לא מקטינים');
  assert.equal(perfFactor(PERF_FAST * 3), 1);
  assert.equal(perfFactor(PERF_SLOW / 10), 0.5);
  const mid = perfFactor(Math.sqrt(PERF_FAST * PERF_SLOW));
  assert.ok(mid > 0.7 && mid < 0.8, String(mid));
  assert.ok(perfFactor(1) > perfFactor(0.5));
  assert.equal(perfLabel(5), 'מהיר');
  assert.equal(perfLabel(0.1), 'איטי');
  assert.equal(perfLabel(0), 'לא נמדד');
});

test('ביצועים: התקציב מוקטן במכשיר איטי; המטמון פג לפי מפתח וזמן', () => {
  const fast = deviceBudget({ deviceMemory: 4, perf: 1 }).maxPixels();
  const slow = deviceBudget({ deviceMemory: 4, perf: 0.5 }).maxPixels();
  assert.ok(Math.abs(slow - fast / 2) <= 1);
  const c = { v: 1, key: 'k', score: 3, at: 1000 };
  assert.equal(perfCacheValid(c, 'k', 2000), true);
  assert.equal(perfCacheValid(c, 'אחר', 2000), false);
  assert.equal(perfCacheValid(c, 'k', 1000 + 31 * 864e5), false);
  assert.equal(perfCacheValid(null, 'k'), false);
});

// ---- ‎.mbe: שמירת מה שלא שלנו ----
test('‎.mbe: שכבה זרה כמו שהיא, שדות לא מוכרים בשכבה ובמשטח, והמשטח נשאר במקומו', () => {
  const vec = { id: 'V1', type: 'vector', paths: [], z: 1 };
  const layers = [
    { id: 'R1', name: 'א', type: 'raster', opacity: 1, blend: 'normal', visible: true, locked: false, mbe: { id: 'R1', type: 'raster', src: 'media/old.png', tag: 7 } },
    { id: 'V1', name: 'לוגו (vector)', type: 'raster', visible: true, locked: true, foreign: vec },
    { id: 'V2', name: 'נסתר', type: 'raster', visible: false, locked: true, foreign: { id: 'V2', type: 'frame' } },
  ];
  const base = { surfaceId: 'c1', document: { units: 'pt', surfaces: [{ id: 'p0', type: 'page' }, { id: 'c1', type: 'canvas', size: [1, 1], note: 'x' }] } };
  const d = mbeDocument({ width: 9, height: 8 }, layers, base, () => 'media/new.png');
  assert.deepEqual(d.surfaces.map(s => s.id), ['p0', 'c1']);
  const s = d.surfaces[1];
  assert.equal(s.note, 'x');
  assert.deepEqual(s.size, [9, 8]);
  assert.equal(s.layers[0].tag, 7);
  assert.equal(s.layers[0].src, 'media/new.png');
  assert.equal(s.layers[1], vec, 'שכבה זרה — אותו אובייקט, בלי שינוי');
  assert.equal(s.layers[2].visible, false, 'ההסתרה — שלנו');
});

test('‎.mbe: הפניות למשאבים בכל עומק, והקנבס הראשון', () => {
  const doc = { fonts: [{ embedded: 'media/f.woff2' }], surfaces: [{ id: 'p', type: 'page', layers: [{ src: 'media/a.png', children: [{ src: 'media/b.png' }] }] }, { id: 'c', type: 'canvas', size: [1, 1] }] };
  assert.deepEqual([...mediaRefs(doc)].sort(), ['media/a.png', 'media/b.png', 'media/f.woff2']);
  assert.equal(canvasSurface(doc).id, 'c');
  assert.equal(canvasSurface({ surfaces: [{ id: 'p', type: 'page' }] }), null);
});

test('פערים: שכבה זרה לא נכנסת לייצוגים', () => {
  assert.deepEqual(gapsFor('ora', [{ blend: 'normal', opacity: 1, visible: true, foreign: { type: 'vector' } }]), ['layer:vector → omitted']);
});

test('התאמה למסך: המסמך בשטח הפנוי — לא מתחת לפס הכלים ולוח האפשרויות', () => {
  const v = V.fit(600, 400, 390, 600, 16, { r: 50, t: 100 });
  assert.ok(v.x + 600 * v.zoom <= 390 - 50 - 16 + 1e-9, 'לא נכנס מתחת לפס הכלים מימין');
  assert.ok(v.y >= 100 + 16 - 1e-9, 'מתחת ללוח האפשרויות');
  assert.ok(Math.abs(v.x - 16) < 1e-9 || v.x > 16);
});

test('עמוד של אפליקציה אחרת (pt): נפתח ברזולוציה של הרסטר החד, ונשמר כעמוד ביחידות שלו', () => {
  const doc = { units: 'pt', surfaces: [{ id: 'gc', type: 'page', size: [225, 150], layers: [
    { id: 'R1', type: 'raster', src: 'media/a.png', px: [300, 200] },
    { id: 'R2', type: 'raster', src: 'media/b.png', box: [10, 10, 50, 50], px: [200, 200] },
  ] }] };
  const es = editSurface(doc);
  assert.equal(es.surface.id, 'gc');
  assert.equal(es.scale, 4, 'הכי חד: 200px על 50pt');
  assert.equal(editSurface({ units: 'pt', surfaces: [{ id: 'p', type: 'page', size: [595, 842], layers: [] }] }), null, 'מסמך טקסט — אין מה לערוך');
  assert.ok(Math.abs(editSurface({ units: 'pt', surfaces: [{ id: 'p', type: 'page', size: [75, 75], layers: [{ id: 'x', type: 'raster', src: 'media/c.png' }] }] }).scale - 4 / 3) < 1e-9, 'בלי px — 96dpi');
  const layers = [{ id: 'R2', name: 'ב', type: 'raster', opacity: 1, blend: 'normal', visible: true, locked: false, mbe: doc.surfaces[0].layers[1] }];
  const out = mbeDocument({ width: 900, height: 600 }, layers, { document: doc, surfaceId: 'gc', scale: 4 }, () => 'media/n.png');
  const s = out.surfaces[0];
  assert.equal(s.type, 'page', 'העמוד נשאר עמוד');
  assert.deepEqual(s.size, [225, 150], 'ביחידות שלו');
  assert.equal(s.layers[0].box, undefined, 'המיקום אפוי ל-PNG בגודל העמוד');
  assert.deepEqual(s.layers[0].px, [900, 600]);
});


// ---- מטה קסם: טווח צבעים ומסכת פיקסלים בבחירה ----
import { colorRange } from '../assets/js/engine/fill.js';
import { maskShape, maskOutline, selectionBox as selBox } from '../assets/js/engine/selection.js';
import { transformShape } from '../assets/js/engine/transform.js';

test('טווח צבעים: כל הצבע בתמונה, גם לא רציף — ו-floodFill רק הרציף', () => {
  // שני ריבועים אדומים נפרדים על לבן
  const red = (x, y) => (x <= 1 || x >= 6) && y <= 1;
  const px = img(8, 4, (x, y) => red(x, y) ? [255, 0, 0, 255] : [255, 255, 255, 255]);
  const all = colorRange(px, 8, 4, 0, 0, 10);
  assert.equal(all.count, 8);
  assert.deepEqual(all.box, [0, 0, 7, 1]);
  assert.equal(floodFill(px, 8, 4, 0, 0, 10).count, 4);
  assert.equal(colorRange(px, 8, 4, 9, 0, 10).count, 0, 'מחוץ לתמונה');
});

test('maskShape: חותך למלבן; ריקה — null', () => {
  const m = new Uint8Array(6 * 4);
  m[1 * 6 + 2] = m[2 * 6 + 3] = 255;
  const s = maskShape(m, 6, [2, 1, 3, 2]);
  assert.deepEqual([s.type, s.x, s.y, s.w, s.h], ['mask', 2, 1, 2, 2]);
  assert.deepEqual([...s.data], [255, 0, 0, 255]);
  assert.equal(maskShape(m, 6, null), null);
  assert.deepEqual(selBox({ shapes: [{ ...s, op: 'add' }], inverted: false }, 6, 4), [2, 1, 4, 3]);
});

test('maskOutline: ריבוע 2×2 — ארבעה קטעים מאוחדים; שני פיקסלים באלכסון — שישה (הקווים האמצעיים ממשיכים זה את זה)', () => {
  const sq = maskOutline({ w: 2, h: 2, data: new Uint8Array([255, 255, 255, 255]) });
  assert.deepEqual(sq.map(String).sort(), ['0,0,0,2', '0,0,2,0', '0,2,2,2', '2,0,2,2']);
  assert.equal(maskOutline({ w: 2, h: 2, data: new Uint8Array([255, 0, 0, 255]) }).length, 6);
});

test('transformShape: מסכה — הזזה שלמה; היפוך אופקי — הפיקסלים מתהפכים', () => {
  const s = { op: 'add', type: 'mask', x: 10, y: 5, w: 3, h: 1, data: new Uint8Array([255, 0, 0]) };
  const moved = transformShape(s, [1, 0, 0, 1, 2.4, -1]);
  assert.deepEqual([moved.x, moved.y, moved.data], [12, 4, s.data]);
  // היפוך סביב x = 20: x' = 40 − x → המלבן 27..30, והפיקסל הנבחר עובר לקצה הימני
  const flip = transformShape(s, [-1, 0, 0, 1, 40, 0]);
  assert.deepEqual([flip.type, flip.x, flip.y, flip.w, flip.h, flip.op], ['mask', 27, 5, 3, 1, 'add']);
  assert.deepEqual([...flip.data], [0, 0, 255]);
});

test('mbeDocument: מסכת שכבה — src ולצדו המתכון; מסכה שנמחקה — המתכון שלנו יוצא, של אחרים נשאר', async () => {
  const { mbeDocument } = await import('../assets/js/engine/formats.js');
  const doc = { width: 10, height: 10 };
  const base = { id: 'L1', name: 'א', type: 'raster', opacity: 1, blend: 'normal', visible: true, locked: false };
  const rec = { source: 'media/s.png', mask: { src: 'media/m.png', density: 1, feather: 0, enabled: true }, baked: 'a'.repeat(64) };
  const withMask = mbeDocument(doc, [{ ...base, mask: {} }], null, () => 'media/a.png', () => rec).surfaces[0].layers[0];
  assert.deepEqual([withMask.src, withMask.source, withMask.mask.src, withMask.baked], ['media/a.png', 'media/s.png', 'media/m.png', 'a'.repeat(64)]);
  const old = { ...rec, filters: [{ type: 'blur' }] };
  const removed = mbeDocument(doc, [{ ...base, mbe: old, maskLoaded: true }], null, () => 'media/b.png').surfaces[0].layers[0];
  assert.deepEqual(['source', 'mask', 'baked'].filter(k => k in removed), [], 'המתכון שלנו נמחק');
  assert.deepEqual(removed.filters, [{ type: 'blur' }], 'שדות של אחרים נשארים');
  const foreignRecipe = mbeDocument(doc, [{ ...base, mbe: old }], null, () => 'media/b.png').surfaces[0].layers[0];
  assert.equal(foreignRecipe.baked, 'a'.repeat(64), 'מתכון שלא טענו — נשאר כמו שהוא');
});

test('cleanAdjust: סוג מוכר — פרמטרים כמספרים, וחסר → ברירת מחדל; שדות אחרים נשמרים; לא מוכר — null', async () => {
  const { cleanAdjust, adjustOf } = await import('../assets/js/engine/formats.js');
  assert.deepEqual(cleanAdjust({ type: 'levels', black: 10, white: 'x', future: 1 }), { type: 'levels', black: 10, white: 255, gamma: 1, future: 1 });
  assert.equal(cleanAdjust({ type: 'curves', points: [] }), null);
  assert.equal(cleanAdjust(null), null);
  assert.equal(adjustOf({ adjust: { type: 'hue-saturation', hue: 30 } }).kind, 'hsl');
});

test('מילוי: cleanFill, ramp (sRGB, אלפא ישר) וגאומטריה', async () => {
  const { cleanFill, fillRamp, fillGeometry, scaleFill } = await import('../assets/js/engine/formats.js');
  assert.equal(cleanFill({ color: 'red' }), null);
  const solid = cleanFill({ color: '#ff0000', opacity: 0.5 });
  assert.deepEqual([...fillRamp(solid).slice(0, 4)], [255, 0, 0, 128]);
  const g = cleanFill({ gradient: { type: 'linear', from: [0, 0], to: [0, 10], stops: [{ at: 1, color: '#0000ff' }, { at: 0, color: '#ff0000' }] } });
  const r = fillRamp(g);
  assert.deepEqual([...r.slice(0, 4)], [255, 0, 0, 255], 'העצירות ממוינות');
  assert.deepEqual([...r.slice(255 * 4, 256 * 4)], [0, 0, 255, 255]);
  assert.deepEqual(fillGeometry(g), { mode: 1, geo: [0, 0, 0, 10] });
  assert.deepEqual(scaleFill(g, 2).gradient.to, [0, 20]);
  assert.equal(fillGeometry(cleanFill({ gradient: { type: 'conic', stops: [{ at: 0, color: '#00ff00' }] } })).mode, 0, 'סוג לא מוכר — צבע הראשונה');
});
