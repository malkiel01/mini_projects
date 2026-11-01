// קצוות המתאר (חץ / עיגול / פס) וגאומטריית הקצוות של נתיב
import test from 'node:test';
import assert from 'node:assert/strict';
import { ends, markerPaths, markerD } from '../assets/js/markers.js';
import { parse, bbox } from '../assets/js/geometry/path.js';

test('קצוות של קו ישר — נקודות וכיוון החוצה', () => {
  const e = ends('M0 0 L100 0');
  assert.deepEqual(e.start.p, [0, 0]);
  assert.deepEqual(e.start.dir, [-1, 0]);
  assert.deepEqual(e.end.p, [100, 0]);
  assert.deepEqual(e.end.dir, [1, 0]);
});

test('עקומה — הכיוון לפי נקודת הבקרה; בקרה על הקצה — לפי הנקודה הבאה', () => {
  const e = ends('M0 0 C0 50 100 50 100 0');
  assert.deepEqual(e.start.dir, [0, -1]);
  assert.deepEqual(e.end.dir, [0, -1]);
  const f = ends('M0 0 C0 0 100 0 100 0');
  assert.deepEqual(f.start.dir, [-1, 0]);
});

test('נתיב סגור — אין קצוות', () => {
  assert.equal(ends('M0 0 L10 0 L10 10 Z'), null);
  assert.deepEqual(markerPaths('M0 0 L10 0 L10 10 Z', { width: 2, end: 'arrow' }), []);
});

test('חץ בסוף — מחודו מעבר לקצה, ברוחב יחסי לעובי', () => {
  const [d] = markerPaths('M0 0 L100 0', { width: 2, end: 'arrow' });
  const b = bbox(parse(d));
  assert.ok(Math.abs(b[0] + b[2] - 106) < 0.01, 'החוד ב-100 + 0.75·4·2');
  assert.ok(Math.abs(b[3] - 9) < 0.01, 'רוחב 2·2.25·2');
});

test('עיגול ופס — סביב הקצה; בלי קצוות — רשימה ריקה', () => {
  const c = bbox(parse(markerD('circle', { p: [10, 10], dir: [1, 0] }, 5)));
  assert.ok(Math.abs(c[2] - 16) < 0.01 && Math.abs(c[0] + c[2] / 2 - 10) < 0.01);
  const b = bbox(parse(markerD('bar', { p: [0, 0], dir: [1, 0] }, 2)));
  assert.ok(Math.abs(b[2] - 2) < 0.01 && Math.abs(b[3] - 10) < 0.01);
  assert.deepEqual(markerPaths('M0 0 L1 0', { width: 1 }), []);
  assert.equal(markerPaths('M0 0 L1 0', { width: 1, start: 'bar', end: 'none' }).length, 1);
});
