// בדיקות מסגרות הטקסט והשטף: node --test layout-studio/tests/flow.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadFont, fontVariant, advance } from '../assets/js/shaper.js';
import { layoutFlow, layoutFlows, columnsOf, freeSegment } from '../assets/js/flow.js';
import { addFrame, autoflow, growFlow, removeFrame, pruneFlows, thread, flowText, textToDoc, setFlow, frameAt,
  allFrames, bodyStyle, DEFAULT_STYLE, docBlocks, styleOf, setStyle, addImageFrame, imagePlacement, wrapRects, isImage,
  updateFrame } from '../assets/js/frames.js';
import { setPageCount, pagesOf } from '../assets/js/pages.js';

const buf = n => { const b = readFileSync(new URL('../assets/fonts/' + n, import.meta.url)); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const noto = await loadFont(buf('NotoSerifHebrew.ttf'), 'noto');
const notoBold = fontVariant(noto, { wght: 700 });
const FONTS = [noto];
const fontsFor = (family, weight) => weight >= 700 ? [notoBold] : FONTS;
const B = paras => paras.map(t => ({ style: 'body', runs: [{ text: t, bold: false }] }));
const lay = (paras, frames, st) => layoutFlow(B(paras), frames, () => st, fontsFor);

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
  const r = lay([LOREM], [fr], st);
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
  const r = lay(paras, [a, b], st);
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
  const one = lay(['א', 'ב'], [fr], st).frames.get('a').lines;
  const two = lay(['א', '', 'ב'], [fr], st).frames.get('a').lines;
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

// ===== סגנונות =====
const docOf = content => ({ type: 'doc', content });
const para = (...runs) => ({ type: 'paragraph', content: runs });
const txt = (text, bold) => ({ type: 'text', text, ...(bold ? { marks: [{ type: 'bold' }] } : {}) });

test('ProseMirror → בלוקים: כותרות, הדגשה, ושבירת שורה', () => {
  const b = docBlocks(docOf([{ type: 'heading', attrs: { level: 2 }, content: [txt('פרק')] },
    para(txt('אחת '), txt('שתיים', true), { type: 'hard_break' }, txt('שלוש'))]));
  assert.equal(b[0].style, 'h2');
  assert.equal(b[1].style, 'body');
  assert.deepEqual(b[1].runs, [{ text: 'אחת ', bold: false }, { text: 'שתיים', bold: true }, { text: '\nשלוש', bold: false }]);
});

test('סגנון ברירת מחדל, ועדכון שמשאיר את השאר', () => {
  const m = setStyle({ styles: {} }, 'h1', { size: 24 });
  assert.equal(styleOf(m, 'h1').size, 24);
  assert.equal(styleOf(m, 'h1').weight, 700);
  assert.equal(styleOf(m, 'body').size, 12);
});

test('כותרת: גודל ומרווח משלה, ממורכזת; גוף אחריה עם רווח', () => {
  const blocks = [{ style: 'h1', runs: [{ text: 'בְּרֵאשִׁית', bold: false }] }, ...B([LOREM])];
  const r = layoutFlow(blocks, [{ id: 'a', box: [0, 0, 300, 600] }], key => styleOf({}, key), fontsFor);
  const [h, b1] = r.frames.get('a').lines;
  assert.equal(h.size, 20);
  assert.equal(h.font, notoBold);   // כותרת — משקל 700
  const w = advance(h.glyphs) * 20 / noto.upem;
  assert.ok(Math.abs(h.x - (300 - w) / 2) < 0.01);   // במרכז
  assert.equal(b1.size, 12);
  assert.ok(Math.abs(b1.y - h.y - (10 + 18)) < 1e-9);   // רווח אחרי הכותרת + מרווח שורה של הגוף
});

test('מילה מודגשת — הגליפים שלה מהגופן המודגש', () => {
  const blocks = [{ style: 'body', runs: [{ text: 'אחת ', bold: false }, { text: 'שתיים', bold: true }, { text: ' שלוש', bold: false }] }];
  const r = layoutFlow(blocks, [{ id: 'a', box: [0, 0, 300, 100] }], () => ({ ...DEFAULT_STYLE, align: 'right' }), fontsFor);
  const g = r.frames.get('a').lines[0].glyphs;
  const boldCl = g.filter(x => x.f === notoBold).map(x => x.cluster);
  assert.ok(boldCl.length >= 5);
  assert.ok(boldCl.every(c => c >= 4 && c < 9));   // רק "שתיים"
});

test('הזחת שורה ראשונה — מימין, רק בשורה הראשונה', () => {
  const st = { ...DEFAULT_STYLE, align: 'right', indent: 20 };
  const lines = lay([LOREM], [{ id: 'a', box: [0, 0, 200, 400] }], st).frames.get('a').lines;
  const k = 12 / noto.upem;
  assert.ok(Math.abs(lines[0].x + advance(lines[0].glyphs) * k - 180) < 0.01);
  assert.ok(Math.abs(lines[1].x + advance(lines[1].glyphs) * k - 200) < 0.01);
});

// ===== תמונות =====
test('תמונה חדשה: ביחס שלה, באזור התוכן, צמודה לימין', () => {
  const { model: m, frameId } = addImageFrame(model(), 0, { src: 'media/' + 'a'.repeat(64) + '.png', px: [1600, 800], newId });
  const fr = pagesOf(m)[0].layers.find(l => l.id === frameId);
  assert.ok(isImage(fr));
  assert.deepEqual(fr.box, [30, 40, 320, 160]);   // רוחב אזור התוכן 320, יחס 2:1
  assert.equal(fr.fit, 'contain');
});

test('מיקום התמונה: contain, cover, zoom ו-focus', () => {
  const f = { box: [0, 0, 100, 100], px: [200, 100], fit: 'contain', focus: [0.5, 0.5], zoom: 1 };
  assert.deepEqual(imagePlacement(f), { x: 0, y: 25, w: 100, h: 50 });
  assert.deepEqual(imagePlacement({ ...f, fit: 'cover' }), { x: -50, y: 0, w: 200, h: 100 });
  assert.deepEqual(imagePlacement({ ...f, fit: 'cover', focus: [1, 0.5] }), { x: -100, y: 0, w: 200, h: 100 });   // הצד הימני נשאר
  assert.deepEqual(imagePlacement({ ...f, zoom: 2 }), { x: -50, y: 0, w: 200, h: 100 });
});

test('קטע פנוי בשורה: הרחב ביותר, ובשוויון — הימני', () => {
  const s = { x: 0, w: 300, rects: [{ x: 100, y: 0, w: 100, h: 50 }] };
  assert.deepEqual(freeSegment(s, 10, 20, 24), { x: 200, w: 100 });   // שני קטעים של 100 — הימני
  assert.deepEqual(freeSegment(s, 60, 70, 24), { x: 0, w: 300 });     // מתחת לתמונה — כל השורה
  assert.deepEqual(freeSegment({ ...s, rects: [{ x: 50, y: 0, w: 200, h: 50 }] }, 10, 20, 24), { x: 250, w: 50 });   // שווים — הימני
  assert.equal(freeSegment({ ...s, rects: [{ x: 0, y: 0, w: 290, h: 50 }] }, 10, 20, 24), null);   // 10pt — צר מדי
});

test('גלישת טקסט: שורות לצד התמונה קצרות, ומתחתיה — מלאות', () => {
  let { model: m, frameId, flowId } = addFrame(model(1), 0, { box: [0, 0, 300, 400], newId });
  m = setFlow(m, flowId, { doc: textToDoc(Array(8).fill(LOREM).join('\n')) });
  let r0 = layoutFlows(m, fontsFor).frames.get(frameId).lines;
  ({ model: m } = addImageFrame(m, 0, { src: 'media/' + 'b'.repeat(64) + '.png', px: [100, 100], newId }));
  const img = pagesOf(m)[0].layers.find(l => l.src);
  m = updateFrame(m, img.id, { box: [0, 0, 150, 150], wrap: 'box', wrapGap: 10 });   // פינה שמאלית עליונה
  assert.equal(wrapRects(m, 0).length, 1);
  const lines = layoutFlows(m, fontsFor).frames.get(frameId).lines;
  const k = 12 / noto.upem;
  const beside = lines.filter(l => l.y < 160), below = lines.filter(l => l.y - 12 * 0.9 > 160);
  assert.ok(beside.length > 3 && below.length > 3);
  for (const l of beside) assert.ok(l.x >= 160 - 0.01, 'לצד התמונה — מימין לה');
  assert.ok(below.some(l => l.x < 1 && advance(l.glyphs) * k > 290), 'מתחת — שורה מלאה');
  assert.ok(lines.length < r0.length + 20);
});

test('שבירת שורה בתוך פסקה: שורה חדשה, לא מיושרת לשני הצדדים; שתיים — שורה ריקה', () => {
  const st = { ...DEFAULT_STYLE, spaceAfter: 0 };
  const fr = { id: 'a', box: [0, 0, 300, 400] };
  const lines = layoutFlow([{ style: 'body', runs: [{ text: 'שורה ראשונה\nשורה שנייה\n\nרביעית', bold: false }] }], [fr], () => st, fontsFor).frames.get('a').lines;
  assert.equal(lines.length, 3);
  const k = 12 / noto.upem;
  for (const l of lines) assert.ok(Math.abs(l.x + advance(l.glyphs) * k - 300) < 0.01);   // לימין, לא מיושרת
  assert.ok(Math.abs(lines[1].y - lines[0].y - 18) < 1e-9);
  assert.ok(Math.abs(lines[2].y - lines[1].y - 36) < 1e-9);   // שורה ריקה ביניהן
  assert.ok(lines.every(l => !l.glyphs.some(g => g.gid === 0)));   // ה-\n לא מצויר
});

// ---- כללי ספר: יתומה, אלמנה, כותרת עם הפסקה שאחריה ----
// מסגרת A מכילה בדיוק 5 שורות (גודל 12, מרווח 18): 10.8 + 18×4 + 3.6 = 86.4. B — גדולה.
const BOOK = { ...DEFAULT_STYLE, spaceBefore: 0, spaceAfter: 0, indent: 0 };
const two = [{ id: 'A', box: [0, 0, 300, 86.4] }, { id: 'B', box: [0, 200, 300, 400] }];
const P = (n, style = 'body') => ({ style, runs: [{ text: Array.from({ length: n }, (_, i) => 'שורה' + (i + 1)).join('\n'), bold: false }] });
const counts = r => ['A', 'B'].map(id => r.frames.get(id).lines.length);

test('שורה אלמנה: פסקה של 6 שורות במסגרת של 5 — שתיים עוברות (4 + 2), לא אחת', () => {
  assert.deepEqual(counts(layoutFlow([P(6)], two, () => BOOK, fontsFor)), [4, 2]);
});

test('שורה יתומה: פסקה שהשורה הראשונה שלה נשארת לבד בתחתית — כולה עוברת', () => {
  assert.deepEqual(counts(layoutFlow([P(4), P(3)], two, () => BOOK, fontsFor)), [4, 3]);
});

test('כותרת בתחתית טור עוברת עם הפסקה שאחריה', () => {
  const r = layoutFlow([P(4), P(1, 'h1'), P(3)], two, () => BOOK, fontsFor);
  assert.deepEqual(counts(r), [4, 4]);
  assert.equal(r.frames.get('B').lines[0].text, 'שורה1');   // הכותרת — ראשונה ב-B
});

test('כללי ספר לא מזיזים כשאין צורך, ולא נכנסים ללולאה כשהטור קצר מדי', () => {
  assert.deepEqual(counts(layoutFlow([P(5), P(3)], two, () => BOOK, fontsFor)), [5, 3]);
  const tiny = [{ id: 'A', box: [0, 0, 300, 14.4] }, { id: 'B', box: [0, 200, 300, 14.4] }];   // שורה אחת בכל מסגרת
  const r = layoutFlow([P(1, 'h1'), P(3)], tiny, () => BOOK, fontsFor);
  assert.deepEqual(['A', 'B'].map(id => r.frames.get(id).lines.length), [1, 1]);
  assert.equal(r.overset, true);
});

test('כותרות נרשמות בפריסה (heads) — לכותרת הרצה', () => {
  const r = layoutFlow([P(1, 'h1'), P(2), { style: 'h2', runs: [{ text: 'סעיף  ראשון', bold: false }] }, P(1)], two, () => BOOK, fontsFor);
  assert.deepEqual(r.frames.get('A').heads.map(h => [h.level, h.text]), [[1, 'שורה1'], [2, 'סעיף ראשון']]);
});
