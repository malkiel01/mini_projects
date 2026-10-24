// בדיקות הגאומטריה: node --test layout-studio/tests/pages.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { spreads, pageSide, sideMargins, contentBox, layoutDoc, setPageCount, setPageSize, marginsFit, spreadOfPage, PAD } from '../assets/js/pages.js';

const model = (n, { facing = true, binding = 'right' } = {}) => ({
  units: 'pt',
  layout: { facing, binding, margins: { top: 40, bottom: 50, inside: 60, outside: 30 } },
  surfaces: Array.from({ length: n }, (_, i) => ({ id: 'p' + i, type: 'page', size: [400, 600], master: null, layers: [] })),
  flows: {},
});

test('פריסות בעמודים כפולים: 1 לבד, ואז זוגות', () => {
  assert.deepEqual(spreads(model(1)), [[0]]);
  assert.deepEqual(spreads(model(4)), [[0], [1, 2], [3]]);
  assert.deepEqual(spreads(model(5)), [[0], [1, 2], [3, 4]]);
});

test('בלי עמודים כפולים — כל עמוד לבד', () => {
  assert.deepEqual(spreads(model(3, { facing: false })), [[0], [1], [2]]);
});

test('כריכה מימין: אי-זוגי משמאל, זוגי מימין; משמאל — הפוך', () => {
  const r = model(4), l = model(4, { binding: 'left' });
  assert.equal(pageSide(r, 1), 'left');
  assert.equal(pageSide(r, 2), 'right');
  assert.equal(pageSide(l, 1), 'right');
  assert.equal(pageSide(l, 2), 'left');
});

test('השוליים הפנימיים — תמיד בצד השדרה', () => {
  const r = model(4);
  assert.deepEqual(sideMargins(r, 1), { top: 40, bottom: 50, left: 30, right: 60 });   // משמאל לשדרה → השדרה מימין
  assert.deepEqual(sideMargins(r, 2), { top: 40, bottom: 50, left: 60, right: 30 });
  const l = model(4, { binding: 'left' });
  assert.deepEqual(sideMargins(l, 1), { top: 40, bottom: 50, left: 60, right: 30 });
});

test('בלי עמודים כפולים — הפנימי בצד הכריכה בכל עמוד', () => {
  const r = model(2, { facing: false });
  assert.equal(sideMargins(r, 1).right, 60);
  assert.equal(sideMargins(r, 2).right, 60);
});

test('אזור התוכן', () => {
  assert.deepEqual(contentBox(model(1), 1), [30, 40, 310, 510]);
});

test('מיקום במרחב המסמך — עמוד בודד נשאר בצד שלו', () => {
  const lay = layoutDoc(model(4));
  assert.equal(lay.width, 800 + PAD * 2);
  assert.equal(lay.spreads.length, 3);
  assert.equal(lay.spreads[0].pages[0].x, PAD);          // עמוד 1 משמאל
  const s2 = lay.spreads[1].pages;
  assert.equal(s2.find(p => p.num === 2).x, PAD + 400);   // עמוד 2 מימין
  assert.equal(s2.find(p => p.num === 3).x, PAD);
  assert.equal(lay.spreads[2].pages[0].x, PAD + 400);     // עמוד 4, אחרון, מימין
  assert.ok(lay.spreads[1].y > lay.spreads[0].y + 600);
  assert.equal(spreadOfPage(lay, 2), 1);
});

test('שינוי מספר עמודים — שומר את הקיימים ומוסיף בסוף', () => {
  let i = 0;
  const m = setPageCount(model(2), 4, () => 'n' + i++);
  assert.deepEqual(m.surfaces.map(s => s.id), ['p0', 'p1', 'n0', 'n1']);
  assert.deepEqual(setPageCount(m, 0, () => 'x').surfaces.map(s => s.id), ['p0']);   // לפחות עמוד אחד
});

test('שינוי מספר עמודים — משטח שאינו עמוד נשמר', () => {
  const base = model(2);
  base.surfaces.push({ id: 'm1', type: 'master' });
  const m = setPageCount(base, 1, () => 'x');
  assert.deepEqual(m.surfaces.map(s => s.id), ['p0', 'm1']);
});

test('גודל עמוד לכל העמודים, ובדיקת שוליים', () => {
  const m = setPageSize(model(2), [300, 400]);
  assert.ok(m.surfaces.every(s => s.size[0] === 300));
  assert.ok(marginsFit(m));
  m.layout = { ...m.layout, margins: { top: 10, bottom: 10, inside: 150, outside: 130 } };
  assert.equal(marginsFit(m), false);
});
