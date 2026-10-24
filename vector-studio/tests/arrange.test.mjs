// בדיקות הסידור והמודל: node --test vector-studio/tests/arrange.test.mjs

import test from 'node:test';
import assert from 'node:assert/strict';
import { newDocument, setShape, findPath, worldBox, layout, applyMatrix, cloneItem, ids, nextId, leaves } from '../assets/js/model.js';
import { group, ungroup, reorder, align, distribute, addLayer, removeLayer, moveLayer, moveToLayer } from '../assets/js/arrange.js';
import { translate, scaleAt } from '../assets/js/geometry/matrix.js';

function docWith(boxes) {
  const doc = newDocument([500, 500]);
  const l = doc.surfaces[0].layers[0];
  boxes.forEach((b, i) => {
    const p = { id: 'p' + (i + 1), d: '', fill: { color: '#000' } };
    setShape(p, { type: 'rect', box: b, radius: 0 });
    l.paths.push(p);
  });
  return doc;
}
const order = (doc) => doc.surfaces[0].layers[0].paths.map(p => p.id);
const box = (doc, id) => { const o = findPath(doc, id); return worldBox(o.path, layout(doc).get(o.surface.id)); };

test('קבוצה: במקום העליון, פירוק מחזיר לאותו מקום', () => {
  const doc = docWith([[0, 0, 10, 10], [20, 0, 10, 10], [40, 0, 10, 10], [60, 0, 10, 10]]);
  const g = group(doc, new Set(['p1', 'p3']));
  assert.deepEqual(order(doc), ['p2', g, 'p4']);
  assert.deepEqual(box(doc, g), [0, 0, 50, 10]);
  assert.equal(group(doc, new Set(['p2'])), null, 'פריט אחד — אין קבוצה');
  const back = ungroup(doc, new Set([g]));
  assert.deepEqual(back, ['p1', 'p3']);
  assert.deepEqual(order(doc), ['p2', 'p1', 'p3', 'p4']);
});

test('קבוצה זזה ומשנה גודל דרך הילדים; מזהה ייחודי גם לצאצאים', () => {
  const doc = docWith([[0, 0, 10, 10], [20, 0, 10, 10]]);
  const g = group(doc, new Set(['p1', 'p2']));
  applyMatrix(findPath(doc, g).path, translate(5, 5));
  assert.deepEqual(box(doc, g), [5, 5, 30, 10]);
  applyMatrix(findPath(doc, g).path, scaleAt(2, 2, 5, 5));
  assert.deepEqual(box(doc, g), [5, 5, 60, 20]);
  assert.equal(findPath(doc, g).path.children[0].shape.type, 'rect', 'הצורה נשארת חיה');
  assert.equal(nextId(doc, 'p'), 'p3', 'nextId רואה את הילדים');
  const next = ids(doc, 'p');
  const c = cloneItem(findPath(doc, g).path, next);
  const all = [...leaves(c)].map(x => x.id).concat(c.id);
  assert.equal(new Set(all).size, all.length);
  assert.ok(!all.includes('p1') && !all.includes('p2'));
});

test('סדר הצגה: קדימה, אחורה, לקצוות', () => {
  const doc = docWith([[0, 0, 1, 1], [0, 0, 1, 1], [0, 0, 1, 1], [0, 0, 1, 1]]);
  reorder(doc, new Set(['p1']), 'forward');
  assert.deepEqual(order(doc), ['p2', 'p1', 'p3', 'p4']);
  reorder(doc, new Set(['p1']), 'front');
  assert.deepEqual(order(doc), ['p2', 'p3', 'p4', 'p1']);
  reorder(doc, new Set(['p4', 'p1']), 'back');
  assert.deepEqual(order(doc), ['p4', 'p1', 'p2', 'p3']);
  reorder(doc, new Set(['p3']), 'backward');
  assert.deepEqual(order(doc), ['p4', 'p1', 'p3', 'p2']);
});

test('יישור: לתיבה של כולם, ופריט יחיד — ללוח', () => {
  const doc = docWith([[0, 0, 10, 10], [50, 30, 20, 20]]);
  align(doc, new Set(['p1', 'p2']), 'right');
  assert.equal(box(doc, 'p1')[0], 60);
  align(doc, new Set(['p1', 'p2']), 'vcenter');
  assert.equal(box(doc, 'p1')[1], 20 + 5 - 5 + 0); // מרכז התיבה (0..50 → 25) פחות חצי גובה
  align(doc, new Set(['p1']), 'hcenter');
  assert.deepEqual(box(doc, 'p1').slice(0, 1), [245]);
});

test('פיזור: רווחים שווים, הקיצוניים במקום', () => {
  const doc = docWith([[0, 0, 10, 10], [15, 0, 10, 10], [90, 0, 10, 10]]);
  assert.equal(distribute(doc, new Set(['p1', 'p2']), 'h'), false);
  distribute(doc, new Set(['p1', 'p2', 'p3']), 'h');
  assert.equal(box(doc, 'p1')[0], 0);
  assert.equal(box(doc, 'p2')[0], 45);
  assert.equal(box(doc, 'p3')[0], 90);
});

test('שכבות: הוספה, הזזה, העברת פריטים, מחיקה רק כשנשארת שכבה', () => {
  const doc = docWith([[0, 0, 1, 1], [0, 0, 1, 1]]);
  const s = doc.surfaces[0];
  const l2 = addLayer(doc, s);
  assert.equal(l2.id, 'L2');
  moveToLayer(doc, new Set(['p1']), 'L2');
  assert.deepEqual(s.layers[0].paths.map(p => p.id), ['p2']);
  assert.deepEqual(l2.paths.map(p => p.id), ['p1']);
  moveLayer(s, 'L2', -1);
  assert.deepEqual(s.layers.map(l => l.id), ['L2', 'L1']);
  assert.equal(removeLayer(s, 'L1'), true);
  assert.equal(removeLayer(s, 'L2'), false);
});

import { rotateAt } from '../assets/js/geometry/matrix.js';
import { relayout } from '../assets/js/text.js';

test('סיבוב: צורה חיה נשארת, הסיבוב ב-transform; תיבה של מסובב; היפוך כוכב', () => {
  const doc = docWith([[0, 0, 100, 50]]);
  const p = findPath(doc, 'p1').path;
  applyMatrix(p, rotateAt(Math.PI / 2, 50, 25));
  assert.equal(p.shape.type, 'rect');
  assert.deepEqual(p.shape.box, [0, 0, 100, 50], 'הפרמטרים לא השתנו');
  const b = box(doc, 'p1');
  b.forEach((v, i) => assert.ok(Math.abs(v - [25, -25, 50, 100][i]) < 1e-6, 'box ' + b));
  applyMatrix(p, rotateAt(-Math.PI / 2, 50, 25));
  assert.equal(p.transform, undefined, 'סיבוב חזרה — בלי transform');
  // הזזה של מסובב — נשארת ב-transform, הצורה לא נאפית
  applyMatrix(p, rotateAt(Math.PI / 4, 0, 0));
  applyMatrix(p, translate(10, 0));
  assert.deepEqual(p.shape.box, [0, 0, 100, 50]);
  assert.equal(p.transform[4], 10);
  // היפוך כוכב — נשאר כוכב
  const star = { id: 's9', d: '', fill: { color: '#000' } };
  setShape(star, { type: 'star', box: [0, 0, 10, 10], points: 5, inner: 0.5 });
  applyMatrix(star, scaleAt(1, -1, 0, 5));
  assert.equal(star.shape.type, 'star');
  assert.deepEqual(star.transform, [1, 0, 0, -1, 0, 10]);
});

test('סיבוב טקסט: transform, והתיבה בעולם מסתובבת איתו', () => {
  const doc = newDocument([500, 500]);
  const t = { id: 't1', type: 'text', text: 'אבג', box: [100, 100, 0, 0], font: { family: 'Heebo', size: 20, weight: 400 }, align: 'right', lineHeight: 1, auto: true };
  relayout(t);
  doc.surfaces[0].layers[0].paths.push(t);
  const [, , w, h] = t.box;
  applyMatrix(t, rotateAt(Math.PI / 2, t.box[0], t.box[1]));
  const b = worldBox(t, [0, 0]);
  assert.ok(Math.abs(b[2] - h) < 1e-6 && Math.abs(b[3] - w) < 1e-6, 'רוחב וגובה מתחלפים');
  assert.equal(t.font.size, 20, 'הגופן לא משתנה בסיבוב');
});
