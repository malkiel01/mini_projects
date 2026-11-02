// בדיקות svg.js ו-hit.js: node --test creative-suite/geometry-core/tests/*.test.mjs

import test from 'node:test';
import assert from 'node:assert/strict';
import { parseColor, parseTransform, parseLength, viewBoxMatrix, elementToD, toMbePath } from '../src/js/svg.js';
import { parse, bbox } from '../src/js/path.js';
import { apply } from '../src/js/matrix.js';
import { flatten, pointInPath, distanceToPath, hitPath } from '../src/js/hit.js';

const close = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) < eps, `${a} ≉ ${b}`);
const closeBox = (a, b, eps = 1e-2) => a.forEach((v, i) => close(v, b[i], eps));
const attrs = (o) => (k) => (k in o ? String(o[k]) : null);

test('parseColor: שם, #rgb, rgb() באחוזים, none, לא מובן', () => {
  assert.equal(parseColor('Red'), '#ff0000');
  assert.equal(parseColor('#abc'), '#aabbcc');
  assert.equal(parseColor('rgb(100%, 0, 50%)'), '#ff0080');
  assert.equal(parseColor('none'), null);
  assert.equal(parseColor('hsl(1,2,3)'), undefined);
  assert.equal(parseColor(undefined), undefined);
});

test('parseTransform: translate + rotate סביב נקודה + scale, לפי הסדר של SVG', () => {
  const m = parseTransform('translate(10 20) rotate(90 5 5) scale(2)');
  const [x, y] = apply(m, 1, 0); // scale → (2,0); rotate 90° סביב (5,5) → (10,2); translate → (20,22)
  close(x, 20); close(y, 22);
  assert.deepEqual(parseTransform(''), [1, 0, 0, 1, 0, 0]);
  assert.deepEqual(parseTransform('matrix(1,2,3,4,5,6)'), [1, 2, 3, 4, 5, 6]);
});

test('parseLength ו-viewBoxMatrix: יחידות, גודל חסר, meet במרכז ו-none', () => {
  close(parseLength('1in'), 72);
  close(parseLength('25.4mm'), 72);
  assert.equal(parseLength('50%'), null);
  const a = viewBoxMatrix('0 0 100 50', null, null);
  assert.deepEqual(a.size, [100, 50]);
  const b = viewBoxMatrix('0 0 100 50', '200', '200'); // meet: ×2, ממורכז אנכית
  const [x, y] = apply(b.matrix, 0, 0);
  close(x, 0); close(y, 50);
  const c = viewBoxMatrix('0 0 100 50', '200', '200', 'none');
  close(apply(c.matrix, 100, 50)[1], 200);
  assert.equal(viewBoxMatrix('', '10', null).size, null);
});

test('elementToD: מלבן (גם מעוגל), עיגול, אליפסה, קו, קו שבור ומצולע — התיבה נכונה', () => {
  const box = (tag, o) => bbox(parse(elementToD(tag, attrs(o))));
  closeBox(box('rect', { x: 1, y: 2, width: 30, height: 20 }), [1, 2, 30, 20]);
  closeBox(box('rect', { x: 0, y: 0, width: 30, height: 20, rx: 50 }), [0, 0, 30, 20]); // rx נחתך לחצי הצלע
  closeBox(box('circle', { cx: 10, cy: 10, r: 5 }), [5, 5, 10, 10]);
  closeBox(box('ellipse', { cx: 0, cy: 0, rx: 8, ry: 3 }), [-8, -3, 16, 6]);
  closeBox(box('line', { x1: 0, y1: 0, x2: 4, y2: 3 }), [0, 0, 4, 3]);
  assert.match(elementToD('polygon', attrs({ points: '0,0 10,0 10,10' })), /Z$/);
  assert.doesNotMatch(elementToD('polyline', attrs({ points: '0,0 10,0 10,10' })), /Z$/);
  assert.equal(elementToD('rect', attrs({ width: 0, height: 5 })), '');
  assert.equal(elementToD('text', attrs({})), '');
});

test('toMbePath: רק M L C Q Z אחרי מטריצה — כמו toMbePath של הסטודיו', () => {
  const d = 'm10 10 h20 v10 a5 5 0 0 1 -5 5 s-5 0 -5 -5 t0 -5 z';
  const out = toMbePath(d, [2, 0, 0, 2, 1, 1]);
  assert.match(out, /^[MLCQZ0-9 .\-]+$/);
  // אותה תוצאה כמו toMbePath של הסטודיו (greeting-cards/editor/js/pathnorm.js, נבדק 2026-11-02), עד העיגול:
  // שם 2 ספרות אחרי הנקודה, כאן 3 (num של path.js)
  assert.equal(out, 'M21 21 L61 21 L61 41 C61 46.523 56.523 51 51 51 C51 51 41 51 41 41 Q41 41 41 31 Z');
});

test('hit: בתוך / מחוץ, חור ב-evenodd מול nonzero, מרחק לקו המתאר', () => {
  const sq = parse('M0 0 L100 0 L100 100 L0 100 Z');
  assert.ok(pointInPath(sq, 50, 50));
  assert.ok(!pointInPath(sq, 150, 50));
  // ריבוע בתוך ריבוע באותו כיוון: nonzero — מלא; evenodd — חור
  const ring = parse('M0 0 L100 0 L100 100 L0 100 Z M25 25 L75 25 L75 75 L25 75 Z');
  assert.ok(pointInPath(ring, 50, 50, 'nonzero'));
  assert.ok(!pointInPath(ring, 50, 50, 'evenodd'));
  close(distanceToPath(sq, 50, -3), 3);
  close(distanceToPath(sq, 0, 50), 0);
  const circle = parse(elementToD('circle', attrs({ cx: 0, cy: 0, r: 10 })));
  assert.ok(Math.abs(distanceToPath(circle, 20, 0) - 10) < 0.1);
  assert.ok(pointInPath(circle, 9.5, 0) && !pointInPath(circle, 10.5, 0));
});

test('hit: נתיב פתוח — פגיעה רק לפי סבולת המתאר; flatten ממשיך אחרי Z', () => {
  const line = parse('M0 0 L100 0');
  assert.ok(!hitPath(line, 50, 2, { fill: false }));
  assert.ok(hitPath(line, 50, 2, { fill: false, strokeTol: 3 }));
  const subs = flatten(parse('M0 0 L10 0 L10 10 Z L20 20'));
  assert.equal(subs.length, 2);
  assert.ok(subs[0].closed);
  assert.deepEqual(subs[1].pts[0], [0, 0]);
});
