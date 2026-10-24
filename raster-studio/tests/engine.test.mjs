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
