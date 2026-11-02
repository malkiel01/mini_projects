// בדיקות measure.js: node --test creative-suite/geometry-core/tests/*.test.mjs

import test from 'node:test';
import assert from 'node:assert/strict';
import { measure, pathLength, pointAtLength } from '../src/js/measure.js';
import { parse } from '../src/js/path.js';
import { shapeToD } from '../src/js/shapes.js';

const close = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) < eps, `${a} ≉ ${b}`);

test('קווים: אורך, נקודה ומשיק, ו-Z סוגר את הריבוע', () => {
  const sq = parse('M0 0 L100 0 L100 100 L0 100 Z');
  close(pathLength(sq), 400);
  const p = pointAtLength(sq, 150); // באמצע הצלע הימנית, יורדים
  close(p.x, 100); close(p.y, 50); close(p.angle, Math.PI / 2);
  const back = pointAtLength(sq, 350); // צלע הסגירה: למעלה
  close(back.x, 0); close(back.y, 50); close(back.angle, -Math.PI / 2);
  assert.equal(pointAtLength(sq, -1), null);
  assert.equal(pointAtLength(sq, 401), null);
});

test('עיגול מבזייה: היקף 2πr, ובכל נקודה המשיק ניצב לרדיוס', () => {
  const r = 50;
  const m = measure(parse(shapeToD({ type: 'ellipse', box: [-r, -r, 2 * r, 2 * r] })));
  assert.ok(Math.abs(m.length - 2 * Math.PI * r) < 0.05, `${m.length}`); // הקירוב של 4 בזייה: ~0.03%
  for (let s = 0; s < m.length; s += m.length / 13) {
    const p = m.at(s);
    close(Math.hypot(p.x, p.y), r, 0.02);
    const dot = p.x * Math.cos(p.angle) + p.y * Math.sin(p.angle);
    close(dot / r, 0, 0.002); // זווית: עד ~0.1° — עיגול מבזייה אינו עיגול מדויק
  }
});

test('עקומה: האורך ב-s חוזר — הנקודות שוות מרחק לאורך הנתיב', () => {
  const segs = parse('M0 0 C0 100 100 100 100 0 Q150 -80 200 0');
  const m = measure(segs);
  // מרחק בין נקודות סמוכות ≈ הצעד (קשת קצרה ≈ מיתר)
  const step = m.length / 400;
  let prev = m.at(0);
  for (let i = 1; i <= 400; i++) {
    const p = m.at(Math.min(m.length, i * step));
    close(Math.hypot(p.x - prev.x, p.y - prev.y), step, step * 0.01);
    prev = p;
  }
  const end = m.at(m.length);
  close(end.x, 200, 1e-4); close(end.y, 0, 1e-4);
});

test('כמה תת-נתיבים: sub, וקפיצה בין התת-נתיבים לא נספרת באורך', () => {
  const m = measure(parse('M0 0 L10 0 M100 100 L100 120'));
  close(m.length, 30);
  assert.equal(m.at(5).sub, 0);
  const q = m.at(15);
  assert.equal(q.sub, 1); close(q.x, 100); close(q.y, 105);
  assert.equal(measure(parse('M5 5')).length, 0);
  assert.equal(measure([]).at(0), null);
});
