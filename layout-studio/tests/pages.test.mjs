// בדיקות הגאומטריה: node --test layout-studio/tests/pages.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { spreads, pageSide, sideMargins, contentBox, layoutDoc, setPageCount, setPageSize, marginsFit, spreadOfPage, PAD,
  hebrewNumeral, folioText, runningItems, setMaster, ensureMasters, pageAt, mastersOf } from '../assets/js/pages.js';

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

// ===== עמודי אב ומספור =====
const withMaster = (n, opts, running = { folio: { position: 'bottom', align: 'outside', size: 10 }, header: null }) => {
  const m = model(n, opts);
  m.surfaces.forEach(p => { p.master = 'mA'; });
  m.surfaces.push({ id: 'mA', type: 'master', name: 'A', size: [400, 600], layers: [], running });
  m.layout.numbering = { start: 1, style: 'arabic' };
  return m;
};

test('מספרים עבריים', () => {
  const t = [[1, 'א'], [10, 'י'], [15, 'טו'], [16, 'טז'], [21, 'כא'], [115, 'קטו'], [400, 'ת'], [500, 'תק'], [1000, 'א׳'], [1016, 'א׳טז']];
  for (const [n, h] of t) assert.equal(hebrewNumeral(n), h, String(n));
});

test('מספר העמוד לפי המספור', () => {
  const m = withMaster(4);
  assert.equal(folioText(m, 3), '3');
  m.layout.numbering = { start: 14, style: 'hebrew' };
  assert.equal(folioText(m, 2), 'טו');
});

test('מספר עמוד בצד החיצוני — מתחלף בין זוגי לאי-זוגי (כריכה מימין)', () => {
  const m = withMaster(4);
  const [f1] = runningItems(m, 1);   // עמוד 1 משמאל לשדרה → החוץ משמאל
  assert.equal(f1.align, 'left');
  assert.equal(f1.x, 30);
  assert.equal(f1.y, 600 - 25);
  const [f2] = runningItems(m, 2);   // עמוד 2 מימין לשדרה → החוץ מימין
  assert.equal(f2.align, 'right');
  assert.equal(f2.x, 400 - 30);
});

test('כריכה משמאל — הפוך', () => {
  const [f1] = runningItems(withMaster(4, { binding: 'left' }), 1);
  assert.equal(f1.align, 'right');
});

test('כותרת רצה: אי-זוגי וזוגי, למעלה במרכז', () => {
  const m = withMaster(4, {}, { folio: null, header: { odd: 'פרק', even: 'הספר', position: 'top', align: 'center', size: 9 } });
  const [h1] = runningItems(m, 1), [h2] = runningItems(m, 2);
  assert.equal(h1.text, 'פרק');
  assert.equal(h2.text, 'הספר');
  assert.equal(h1.align, 'center');
  assert.equal(h1.y, 20);
});

test('עמוד בלי עמוד אב — בלי מספר וכותרת', () => {
  const m = setMaster(withMaster(4), [0], null);
  assert.deepEqual(runningItems(m, 1), []);
  assert.equal(runningItems(m, 2).length, 1);
});

test('עמוד חדש מקבל את עמוד האב של האחרון; גודל חל גם על עמוד האב', () => {
  const m = setPageCount(withMaster(2), 4, (() => { let i = 0; return () => 'n' + i++; })());
  assert.ok(m.surfaces.filter(s => s.type === 'page').every(p => p.master === 'mA'));
  assert.deepEqual(mastersOf(setPageSize(m, [300, 500]))[0].size, [300, 500]);
});

test('מסמך ישן מקבל עמוד אב ומספור, בלי לשייך עמודים', () => {
  const m = ensureMasters(model(2));
  assert.equal(mastersOf(m).length, 1);
  assert.deepEqual(m.layout.numbering, { start: 1, style: 'arabic' });
  assert.ok(m.surfaces.filter(s => s.type === 'page').every(p => p.master === null));
  assert.equal(ensureMasters(m), m);   // כבר יש — בלי שינוי
});

test('איזה עמוד בנקודה', () => {
  const lay = layoutDoc(model(4));
  const s2 = lay.spreads[1];
  assert.equal(pageAt(lay, PAD + 10, s2.y + 10), 2);         // שמאל → עמוד 3
  assert.equal(pageAt(lay, PAD + 410, s2.y + 10), 1);        // ימין → עמוד 2
  assert.equal(pageAt(lay, PAD + 410, lay.spreads[0].y + 10), -1);   // ליד עמוד 1 אין עמוד
});

// ---- כותרת רצה עם שם הפרק ----
import { headsByPage, fillHeads } from '../assets/js/pages.js';

test('כותרות לפי עמוד: הראשונה בעמוד, ואם אין — האחרונה שלפניו; פרק חדש מאפס את הסעיף', () => {
  const m = { layout: { facing: true }, surfaces: [0, 1, 2, 3].map(i => ({ id: 'p' + i, type: 'page', size: [400, 600], layers: [{ id: 'f' + i, type: 'frame' }] })) };
  const H = { f0: [{ level: 1, y: 50, text: 'פרק א' }, { level: 2, y: 200, text: 'סעיף 1' }], f1: [],
    f2: [{ level: 2, y: 300, text: 'סעיף 2' }, { level: 1, y: 100, text: 'פרק ב' }, { level: 1, y: 400, text: 'פרק ג' }], f3: [] };
  const text = { frames: new Map(Object.entries(H).map(([id, heads]) => [id, { lines: [], heads }])) };
  const r = headsByPage(m, text, i => m.surfaces[i].layers);
  assert.deepEqual(r[0], { 1: 'פרק א', 2: 'סעיף 1' });
  assert.deepEqual(r[1], { 1: 'פרק א', 2: 'סעיף 1' });
  assert.deepEqual(r[2], { 1: 'פרק ב', 2: 'סעיף 2' });   // הראשונה בעמוד — לפי y, לא לפי הסדר ברשימה
  assert.deepEqual(r[3], { 1: 'פרק ג' });                 // פרק ג בלי סעיף — הסעיף התאפס
});

test('מילוי הכותרת הרצה', () => {
  assert.equal(fillHeads('{כותרת 1}', { 1: 'בראשית' }), 'בראשית');
  assert.equal(fillHeads('ספר · {כותרת 1} · {כותרת 2}', { 1: 'פרק א' }), 'ספר · פרק א ·');
  assert.equal(fillHeads('שם קבוע', {}), 'שם קבוע');
});
