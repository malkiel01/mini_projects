// בדיקות מודול הגאומטריה: node --test vector-studio/tests/geometry.test.mjs

import test from 'node:test';
import assert from 'node:assert/strict';
import { parse, serialize, normalize, bbox, transform, unionBox } from '../assets/js/geometry/path.js';
import { multiply, apply, invert, scaleAt, translate } from '../assets/js/geometry/matrix.js';
import { shapeToD, shapeFromDrag, transformShape, normBox } from '../assets/js/geometry/shapes.js';

const close = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) < eps, `${a} ≉ ${b}`);
const closeBox = (a, b, eps = 1e-3) => a.forEach((v, i) => close(v, b[i], eps));

test('נרמול: יחסי → מוחלט, H/V → L, זוגות אחרי M → L', () => {
  assert.equal(normalize('m10 10 20 0 v10 h-20 z'), 'M10 10 L30 10 L30 20 L10 20 Z');
  assert.equal(normalize('M0,0L1e1,5'), 'M0 0 L10 5');
});

test('נרמול: S ו-T משקפים את נקודת הבקרה', () => {
  assert.equal(normalize('M0 0 C0 10 10 10 10 0 S20 -10 20 0'), 'M0 0 C0 10 10 10 10 0 C10 -10 20 -10 20 0');
  assert.equal(normalize('M0 0 Q5 10 10 0 T20 0'), 'M0 0 Q5 10 10 0 Q15 -10 20 0');
});

test('קשת → בזייה, גם עם דגלים צמודים', () => {
  const segs = parse('M0 0 a10 10 0 0120 0');
  assert.equal(segs[0].c, 'M');
  assert.ok(segs.slice(1).every(s => s.c === 'C'));
  const last = segs[segs.length - 1].p;
  close(last[4], 20); close(last[5], 0);
  // חצי עיגול ברדיוס 10 מעל הציר: התיבה מגיעה ל-y=-10 (sweep=1 → עם כיוון השעון, למעלה במסך)
  closeBox(bbox(segs), [0, -10, 20, 10], 0.01);
});

test('קשת עם רדיוס קטן מדי מוגדלת לפי התקן', () => {
  const b = bbox(parse('M0 0 A1 1 0 0 1 10 0'));
  closeBox(b, [0, -5, 10, 5], 0.01);
});

test('נתיב שגוי זורק', () => {
  assert.throws(() => parse('L10 10'));
  assert.throws(() => parse('M10'));
  assert.throws(() => parse('10 10'));
});

test('כתיבה: 3 ספרות, בלי -0', () => {
  assert.equal(serialize([{ c: 'M', p: [1.23456, -0.0001] }, { c: 'Z', p: [] }]), 'M1.235 0 Z');
});

test('תיבה חוסמת כוללת קיצוני עקומה', () => {
  closeBox(bbox(parse('M0 0 C0 -10 10 -10 10 0')), [0, -7.5, 10, 7.5]);
  closeBox(bbox(parse('M0 0 Q5 10 10 0')), [0, 0, 10, 5]);
  assert.equal(bbox([]), null);
});

test('טרנספורמציה ומטריצות', () => {
  const m = multiply(translate(10, 0), scaleAt(2, 2));
  assert.deepEqual(apply(m, 1, 1), [12, 2]);
  const inv = invert(m);
  const [x, y] = apply(inv, 12, 2);
  close(x, 1); close(y, 1);
  const segs = transform(parse('M0 0 L1 1'), m);
  assert.equal(serialize(segs), 'M10 0 L12 2');
  assert.deepEqual(scaleAt(2, 3, 10, 10), [2, 0, 0, 3, -10, -20]);
});

test('איחוד תיבות', () => {
  assert.deepEqual(unionBox([[0, 0, 10, 10], null, [5, -5, 10, 10]]), [0, -5, 15, 15]);
  assert.equal(unionBox([]), null);
});

test('צורות: מלבן, פינות מעוגלות, אליפסה, מצולע, כוכב, קו', () => {
  closeBox(bbox(parse(shapeToD({ type: 'rect', box: [10, 10, 80, 40], radius: 0 }))), [10, 10, 80, 40]);
  closeBox(bbox(parse(shapeToD({ type: 'rect', box: [10, 10, 80, 40], radius: 100 }))), [10, 10, 80, 40]); // רדיוס נחתך לחצי הצלע
  closeBox(bbox(parse(shapeToD({ type: 'ellipse', box: [0, 0, 100, 50] }))), [0, 0, 100, 50]);
  const hex = parse(shapeToD({ type: 'polygon', box: [0, 0, 100, 100], sides: 6 }));
  assert.equal(hex.filter(s => s.c === 'L').length, 5);
  close(hex[0].p[0], 50); close(hex[0].p[1], 0); // מתחיל למעלה
  const star = parse(shapeToD({ type: 'star', box: [0, 0, 100, 100], points: 5, inner: 0.5 }));
  assert.equal(star.length, 11); // M + 9 L + Z
  assert.equal(shapeToD({ type: 'line', from: [0, 0], to: [10, 5] }), 'M0 0 L10 5');
});

test('גרירה: הפוכה, ריבוע עם shift, קו ב-45°', () => {
  assert.deepEqual(shapeFromDrag('rect', 50, 50, 10, 20).box, [10, 20, 40, 30]);
  assert.deepEqual(shapeFromDrag('ellipse', 0, 0, 30, 10, true).box, [0, 0, 30, 30]);
  const l = shapeFromDrag('line', 0, 0, 10, 9, true);
  close(l.to[0], l.to[1]);
  assert.deepEqual(normBox([10, 10, -5, -5]), [5, 5, 5, 5]);
});

test('טרנספורמציה של צורה: תיבה זזה וגדלה; סיבוב → null', () => {
  const r = { type: 'rect', box: [0, 0, 10, 10], radius: 2 };
  assert.deepEqual(transformShape(r, multiply(translate(5, 5), scaleAt(2, 1))).box, [5, 5, 20, 10]);
  assert.deepEqual(transformShape(r, scaleAt(-1, 1, 10, 0)).box, [10, 0, 10, 10]); // היפוך → תיבה חיובית
  assert.equal(transformShape(r, [0, 1, -1, 0, 0, 0]), null);
  assert.deepEqual(transformShape({ type: 'line', from: [0, 0], to: [1, 1] }, translate(1, 2)).to, [2, 3]);
});

// ---- נקודות עיגון ----
import { toNodes, fromNodes, splitAt, toggleSmooth, removeNode, moveHandle, nearest } from '../assets/js/geometry/nodes.js';

const roundTrip = (d) => serialize(fromNodes(toNodes(parse(d))));

test('נקודות: הלוך ושוב שומר על הנתיב', () => {
  assert.equal(roundTrip('M0 0 L10 0 L10 10 Z'), 'M0 0 L10 0 L10 10 Z');
  assert.equal(roundTrip('M0 0 C0 10 10 10 10 0'), 'M0 0 C0 10 10 10 10 0');
  // אליפסה: מקטע הסגירה עקום, והנקודה הכפולה מתאחדת
  const ell = shapeToD({ type: 'ellipse', box: [0, 0, 100, 50] });
  const subs = toNodes(parse(ell));
  assert.equal(subs[0].nodes.length, 4);
  assert.equal(subs[0].closed, true);
  closeBox(bbox(fromNodes(subs)), [0, 0, 100, 50]);
  // Q נהפך ל-C שקול
  closeBox(bbox(fromNodes(toNodes(parse('M0 0 Q5 10 10 0')))), [0, 0, 10, 5]);
  // שני תת-נתיבים
  assert.equal(toNodes(parse('M0 0 L1 1 M5 5 L6 6')).length, 2);
});

test('נקודות: פיצול לא משנה צורה', () => {
  const subs = toNodes(parse('M0 0 C0 -10 10 -10 10 0'));
  splitAt(subs[0], 0, 0.5);
  assert.equal(subs[0].nodes.length, 3);
  closeBox(bbox(fromNodes(subs)), [0, -7.5, 10, 7.5]);
  const line = toNodes(parse('M0 0 L10 0'));
  splitAt(line[0], 0, 0.3);
  assert.equal(serialize(fromNodes(line)), 'M0 0 L3 0 L10 0');
});

test('נקודות: חלקה ↔ פינה, מחיקה, ידית ששומרת על החלקות', () => {
  const subs = toNodes(parse('M0 0 L10 0 L20 0'));
  toggleSmooth(subs[0], 1);
  assert.ok(subs[0].nodes[1].i && subs[0].nodes[1].o);
  moveHandle(subs[0].nodes[1], 'o', [10, 5]);
  const n = subs[0].nodes[1];
  close(n.i[0], 10); assert.ok(n.i[1] < 0); // הידית הנגדית מסתובבת לצד השני
  toggleSmooth(subs[0], 1);
  assert.equal(subs[0].nodes[1].i, null);
  assert.equal(removeNode(subs, 0, 1), true);
  assert.equal(serialize(fromNodes(subs)), 'M0 0 L20 0');
  assert.equal(removeNode(subs, 0, 0), false);
});

test('נקודות: הקרובה ביותר על עקומה', () => {
  const r = nearest(toNodes(parse('M0 0 L10 0 L10 10')), [12, 5]);
  assert.equal(r.seg, 1); close(r.point[0], 10, 1e-3); close(r.point[1], 5, 1e-3); close(r.dist, 2, 1e-3);
});

// ---- פעולות צורה ----
import { region, combine, toD } from '../assets/js/geometry/boolean.js';

test('פעולות צורה: איחוד, חיסור, חיתוך, xor; חור נשמר', () => {
  const sq = (x, y, s) => `M${x} ${y} L${x + s} ${y} L${x + s} ${y + s} L${x} ${y + s} Z`;
  const a = region(sq(0, 0, 10)), b = region(sq(5, 5, 10));
  const area = (multi) => multi.reduce((n, poly) => n + poly.reduce((m, ring, i) => {
    let s = 0; for (let k = 0; k < ring.length - 1; k++) s += ring[k][0] * ring[k + 1][1] - ring[k + 1][0] * ring[k][1];
    return m + (i === 0 ? 1 : -1) * Math.abs(s / 2);
  }, 0), 0);
  close(area(combine('union', [a, b])), 175);
  close(area(combine('subtract', [a, b])), 75);
  close(area(combine('intersect', [a, b])), 25);
  close(area(combine('xor', [a, b])), 150);
  // ריבוע עם חור (שני תת-נתיבים) — השטח בלי החור
  close(area(region(sq(0, 0, 10) + ' ' + sq(3, 3, 4))), 84);
  // עיגול משוטח קרוב לשטח האמיתי
  const circle = region(shapeToD({ type: 'ellipse', box: [0, 0, 20, 20] }));
  assert.ok(Math.abs(area(circle) - Math.PI * 100) < 1.5, 'עיגול: ' + area(circle));
  const d = toD(combine('subtract', [a, b]));
  assert.match(d, /^M[\d.\s L]+ Z$/);
  closeBox(bbox(parse(d)), [0, 0, 10, 10]);
});

test('פעולות צורה: העקומות חוזרות — עיגול פחות ריבוע נשאר עם קשתות', () => {
  const reg = new Map();
  const circle = region(shapeToD({ type: 'ellipse', box: [0, 0, 100, 100] }), null, reg);
  const sqr = region('M50 -10 L120 -10 L120 110 L50 110 Z', null, reg);
  const d = toD(combine('subtract', [circle, sqr]), reg);
  const segs = parse(d);
  const curves = segs.filter(s => s.c === 'C').length, lines = segs.filter(s => s.c === 'L').length;
  assert.ok(curves >= 2 && curves <= 4, `חצי עיגול בכמה עקומות בודדות: ${curves} (${d})`);
  assert.ok(lines <= 1, 'קו החיתוך — קו אחד');
  closeBox(bbox(segs), [0, 0, 50, 100], 0.05);
  // כל נקודה על העקומה באמת על העיגול
  for (const s of segs.filter(x => x.c === 'C')) {
    const [x, y] = [s.p[4], s.p[5]];
    close(Math.hypot(x - 50, y - 50), 50, 0.05);
  }
  // איחוד של שני עיגולים — רק עקומות
  const reg2 = new Map();
  const u = toD(combine('union', [region(shapeToD({ type: 'ellipse', box: [0, 0, 60, 60] }), null, reg2), region(shapeToD({ type: 'ellipse', box: [40, 0, 60, 60] }), null, reg2)]), reg2);
  assert.ok(parse(u).filter(s => s.c === 'L').length === 0 && parse(u).filter(s => s.c === 'C').length <= 10, u);
  // מלבן מעוגל פחות מלבן: קווים ישרים נשארים ישרים
  const reg3 = new Map();
  const rr = toD(combine('intersect', [region(shapeToD({ type: 'rect', box: [0, 0, 100, 50], radius: 10 }), null, reg3), region('M-5 -5 L60 -5 L60 60 L-5 60 Z', null, reg3)]), reg3);
  assert.ok(parse(rr).length < 12, rr);
});
