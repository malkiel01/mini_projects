// בדיקות הציור החופשי: node --test vector-studio/tests/freehand.test.mjs

import test from 'node:test';
import assert from 'node:assert/strict';
import { simplify, smoothD, pencilD, brushD, widths } from '../assets/js/freehand.js';
import { parse, bbox } from '../assets/js/geometry/path.js';

const wave = (n = 100, f = (i) => ({ x: i * 2, y: Math.sin(i / 10) * 20, p: null, t: i * 10 })) => Array.from({ length: n + 1 }, (_, i) => f(i));

test('פישוט: קו ישר — שתי נקודות; פינה נשמרת', () => {
  const line = Array.from({ length: 50 }, (_, i) => ({ x: i, y: 0 }));
  assert.equal(simplify(line, 0.5).length, 2);
  const corner = [...line, ...Array.from({ length: 50 }, (_, i) => ({ x: 49, y: i + 1 }))];
  assert.equal(simplify(corner, 0.5).length, 3);
});

test('קטמול-רום: עובר בכל הנקודות; טבעת נסגרת', () => {
  const pts = [{ x: 0, y: 0 }, { x: 10, y: 10 }, { x: 20, y: 0 }];
  const segs = parse(smoothD(pts));
  assert.deepEqual(segs.map(s => s.c), ['M', 'C', 'C']);
  assert.deepEqual(segs[1].p.slice(4), [10, 10]);
  assert.match(smoothD(pts, true), / Z$/);
});

test('עיפרון: גל מוחלק למעט עקומות, בתוך הסטייה; הקשה — כלום; מעגל שנגמר בהתחלה — נסגר', () => {
  const s = wave();
  const d = pencilD(s, 0.5);
  const segs = parse(d);
  assert.ok(segs.length > 3 && segs.length < 40, 'עקומות: ' + segs.length);
  const b = bbox(segs);
  assert.ok(Math.abs(b[0]) < 0.01 && Math.abs(b[0] + b[2] - 200) < 0.01 && Math.abs(b[3] - 40) < 2, 'תיבה ' + b);
  assert.equal(pencilD([{ x: 5, y: 5, t: 0 }, { x: 5.01, y: 5, t: 5 }], 1), '');
  const circle = Array.from({ length: 41 }, (_, i) => ({ x: Math.cos(i / 40 * 2 * Math.PI) * 30, y: Math.sin(i / 40 * 2 * Math.PI) * 30, t: i }));
  assert.match(pencilD(circle, 0.5, 5), / Z$/);
  assert.doesNotMatch(pencilD(circle.slice(0, 30), 0.5, 5), / Z$/);
});

test('עובי: לחץ עט קובע; בלי לחץ — מהר דק יותר; קצוות מחודדים', () => {
  const s = wave(100, (i) => ({ x: i * 3, y: 0, p: 0.5, t: i < 50 ? i * 20 : 1000 + (i - 50) * 2 }));
  const wp = widths(s, 10, { pressure: true });
  assert.ok(Math.abs(wp[50] - 6) < 0.01, 'לחץ חצי → 0.2+0.8×0.5: ' + wp[50]);
  const ws = widths(s, 10);
  assert.ok(ws[30] > ws[75], `איטי (${ws[30]}) עבה ממהיר (${ws[75]})`);
  assert.ok(ws[0] < 2 && ws[100] < 2, 'חידוד');
});

test('מברשת: צורה סגורה ברוחב העובי; נקודה — עיגול', () => {
  const s = wave(100, (i) => ({ x: i * 2, y: 0, p: null, t: i * 10 }));
  const d = brushD(s, s.map(() => 10), 0.5);
  assert.match(d, /^M.* Z$/);
  const b = bbox(parse(d));
  assert.ok(Math.abs(b[3] - 10) < 1.5, 'גובה ≈ עובי: ' + b[3]);
  assert.ok(b[0] < -3 && b[0] + b[2] > 203, 'קצוות עגולים מעבר לקו: ' + b);
  const dot = bbox(parse(brushD([{ x: 5, y: 5, t: 0 }], [8], 1)));
  dot.forEach((v, i) => assert.ok(Math.abs(v - [1, 1, 8, 8][i]) < 0.01, 'עיגול ' + dot));
});
