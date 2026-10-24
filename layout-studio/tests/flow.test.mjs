// בדיקות מסגרות הטקסט והשטף: node --test layout-studio/tests/flow.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadFont, advance } from '../assets/js/shaper.js';
import { layoutFlow, layoutFlows, columnsOf } from '../assets/js/flow.js';
import { addFrame, autoflow, growFlow, removeFrame, pruneFlows, thread, flowText, textToDoc, setFlow, frameAt,
  allFrames, bodyStyle, DEFAULT_STYLE } from '../assets/js/frames.js';
import { setPageCount, pagesOf } from '../assets/js/pages.js';

const buf = n => { const b = readFileSync(new URL('../assets/fonts/' + n, import.meta.url)); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const noto = await loadFont(buf('NotoSerifHebrew.ttf'), 'noto');
const FONTS = [noto];
const fontsFor = () => FONTS;

let seq = 0;
const newId = (p = 'x') => p + (++seq);
const model = (n = 4) => ({
  units: 'pt',
  layout: { facing: true, binding: 'right', margins: { top: 40, bottom: 40, inside: 50, outside: 30 } },
  surfaces: Array.from({ length: n }, (_, i) => ({ id: 'p' + i, type: 'page', size: [400, 600], master: null, layers: [] })),
  flows: {},
});
const LOREM = 'בְּרֵאשִׁית בָּרָא אֱלֹהִים אֵת הַשָּׁמַיִם וְאֵת הָאָרֶץ׃ וְהָאָרֶץ הָיְתָה תֹהוּ וָבֹהוּ וְחֹשֶׁךְ עַל־פְּנֵי תְהוֹם';

test('מסגרת חדשה — באזור התוכן, עם שטף חדש וריק', () => {
  const { model: m, frameId, flowId } = addFrame(model(), 0, { newId });
  const [fr] = pagesOf(m)[0].layers;
  assert.equal(fr.id, frameId);
  assert.deepEqual(fr.box, [30, 40, 320, 520]);   // עמוד 1 משמאל לשדרה: חוץ משמאל (30), פנים מימין (50)
  assert.deepEqual(m.flows[flowId].frames, [frameId]);
  assert.equal(flowText(m, flowId), '');
});

test('טורים מימין לשמאל', () => {
  const cols = columnsOf({ box: [0, 0, 310, 100], columns: 2, gutter: 10 });
  assert.equal(cols[0].x, 160);   // הטור הראשון — הימני
  assert.equal(cols[1].x, 0);
  assert.equal(cols[0].w, 150);
});

test('שורות: מיושרות לשני הצדדים, האחרונה לימין', () => {
  const st = { ...DEFAULT_STYLE, size: 12, leading: 18 };
  const fr = { id: 'a', box: [0, 0, 200, 400], columns: 1 };
  const r = layoutFlow([LOREM], [fr], st, FONTS);
  const lines = r.frames.get('a').lines;
  assert.ok(lines.length >= 3);
  assert.equal(r.overset, false);
  const k = st.size / noto.upem;
  for (const l of lines.slice(0, -1)) {   // מלאות — מתחילות ב-0 ורוחבן 200
    assert.equal(l.x, 0);
    assert.ok(Math.abs(advance(l.glyphs) * k - 200) < 0.01);
  }
  const last = lines[lines.length - 1];
  assert.ok(Math.abs(last.x + advance(last.glyphs) * k - 200) < 0.01);   // צמודה לימין
  assert.ok(lines[1].y - lines[0].y === 18);
});

test('גלישה בין מסגרות, ו-overset כשנגמר המקום', () => {
  const st = { ...DEFAULT_STYLE };
  const a = { id: 'a', box: [0, 0, 150, 60] }, b = { id: 'b', box: [0, 0, 150, 60] };
  const paras = Array(6).fill(LOREM);
  const r = layoutFlow(paras, [a, b], st, FONTS);
  assert.ok(r.frames.get('a').lines.length > 0);
  assert.ok(r.frames.get('b').lines.length > 0);
  assert.equal(r.overset, true);
  assert.equal(r.frames.get('b').overset, true);
  assert.equal(r.frames.get('a').overset, false);
  // כל שורה בתוך המסגרת שלה
  for (const l of [...r.frames.get('a').lines, ...r.frames.get('b').lines]) assert.ok(l.y <= 60);
});

test('פסקה ריקה תופסת שורה', () => {
  const st = { ...DEFAULT_STYLE, spaceAfter: 0 };
  const fr = { id: 'a', box: [0, 0, 300, 300] };
  const one = layoutFlow(['א', 'ב'], [fr], st, FONTS).frames.get('a').lines;
  const two = layoutFlow(['א', '', 'ב'], [fr], st, FONTS).frames.get('a').lines;
  assert.ok(Math.abs(two[two.length - 1].y - one[one.length - 1].y - 18) < 1e-9);
});

test('זרימה אוטומטית: מסגרת בכל עמוד מכאן, בשרשור אחד; גדילה מוסיפה עמודים', () => {
  let { model: m, flowId } = autoflow(model(4), 1, { newId });
  assert.equal(thread(m, flowId).length, 3);
  assert.deepEqual(thread(m, flowId).map(t => t.pageIndex), [1, 2, 3]);
  assert.equal(m.flows[flowId].autogrow, true);
  m = growFlow(m, flowId, 2, newId);
  assert.equal(pagesOf(m).length, 6);
  assert.deepEqual(thread(m, flowId).map(t => t.pageIndex), [1, 2, 3, 4, 5]);
  // זרימה חוזרת על אותו שטף — לא מכפילה מסגרות
  ({ model: m } = autoflow(m, 1, { flowId, newId }));
  assert.equal(thread(m, flowId).length, 5);
});

test('מחיקת מסגרת ועמודים — השרשור מתנקה, שטף בלי מסגרות נמחק', () => {
  let { model: m, flowId } = autoflow(model(4), 0, { newId });
  m = setFlow(m, flowId, { doc: textToDoc('שלום\n\nעולם') });
  assert.equal(flowText(m, flowId), 'שלום\n\nעולם');
  m = pruneFlows(setPageCount(m, 2, () => newId('p')));
  assert.equal(thread(m, flowId).length, 2);
  m = removeFrame(m, thread(m, flowId)[0].frame.id);
  assert.equal(thread(m, flowId).length, 1);
  m = removeFrame(m, thread(m, flowId)[0].frame.id);
  assert.equal(m.flows[flowId], undefined);
});

test('layoutFlows על מודל: טקסט ארוך במסגרות של הספר', () => {
  let { model: m, flowId } = autoflow(model(2), 0, { newId });
  m = setFlow(m, flowId, { doc: textToDoc(Array(40).fill(LOREM).join('\n')) });
  const r = layoutFlows(m, fontsFor);
  assert.equal(r.flows.get(flowId).overset, true);
  const ids = allFrames(m).map(x => x.frame.id);
  assert.ok(ids.every(id => r.frames.get(id).lines.length > 10));
  assert.equal(bodyStyle(m).font, 'Noto Serif Hebrew');
});

test('איזו מסגרת בנקודה', () => {
  const { model: m, frameId } = addFrame(model(), 0, { newId });
  assert.equal(frameAt(m, 0, 100, 100).id, frameId);
  assert.equal(frameAt(m, 0, 5, 5), null);
});
