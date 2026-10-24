// מסגרות טקסט ושטפים — פעולות על המודל, בלי DOM (נבדק ב-tests/frames.test.mjs).
//
// מסגרת = שכבה על עמוד:   { id, type: 'frame', name, box: [x, y, w, h] (pt, מפינת העמוד), flow, columns, gutter }
// שטף   = flows[id]:       { engine: 'prosemirror', doc, frames: [מזהי מסגרות בסדר הזרימה], autogrow, style }
//   doc — JSON של ProseMirror (כמו שעורך התמלילים שומר): פסקאות עם טקסט.
//   autogrow — טקסט שלא נכנס מוסיף עמודים ומסגרות בסוף, עד שנגמר ("זרימה אוטומטית").
// סגנון  = styles[name]:   { font, size, leading, align, spaceAfter } — בינתיים "body" בלבד; סגנונות מלאים בשלב הבא.

import { pagesOf, contentBox, setPageCount } from './pages.js';

export const DEFAULT_STYLE = { font: 'Noto Serif Hebrew', size: 12, leading: 18, align: 'justify', spaceAfter: 6 };
export const FONTS = { 'Noto Serif Hebrew': 'NotoSerifHebrew.ttf', 'Frank Ruhl Libre': 'FrankRuhlLibre.ttf' };

/** סגנון הגוף — ברירת מחדל למסמך שאין בו. */
export function bodyStyle(model) {
  return { ...DEFAULT_STYLE, ...(model.styles && model.styles.body) };
}

export function setBodyStyle(model, patch) {
  return { ...model, styles: { ...(model.styles || {}), body: { ...bodyStyle(model), ...patch } } };
}

const flowsOf = model => (model.flows && typeof model.flows === 'object' ? model.flows : {});

/** כל המסגרות, עם העמוד שלהן: [{ frame, pageIndex }]. */
export function allFrames(model) {
  const out = [];
  pagesOf(model).forEach((p, pageIndex) => {
    for (const l of p.layers || []) if (l.type === 'frame') out.push({ frame: l, pageIndex });
  });
  return out;
}

export const framesOnPage = (model, pageIndex) => (pagesOf(model)[pageIndex]?.layers || []).filter(l => l.type === 'frame');

/** המסגרות של שטף, בסדר הזרימה: [{ frame, pageIndex }]. */
export function thread(model, flowId) {
  const byId = new Map(allFrames(model).map(x => [x.frame.id, x]));
  return (flowsOf(model)[flowId]?.frames || []).map(id => byId.get(id)).filter(Boolean);
}

// עדכון שכבות של עמוד (לפי אינדקס עמוד) — מחזיר מודל חדש
function withPageLayers(model, pageIndex, fn) {
  let i = -1;
  return { ...model, surfaces: model.surfaces.map(s => s.type !== 'page' ? s : (++i === pageIndex ? { ...s, layers: fn(s.layers || []) } : s)) };
}

function newFlowId(model) {
  const flows = flowsOf(model);
  let n = Object.keys(flows).length + 1;
  while (flows['f' + n]) n++;
  return 'f' + n;
}

const emptyDoc = () => ({ type: 'doc', content: [{ type: 'paragraph' }] });

/**
 * מסגרת חדשה על עמוד. box — ברירת מחדל: אזור התוכן (בתוך השוליים).
 * flowId — להמשיך שטף קיים (המסגרת נכנסת בסוף השרשור); null — שטף חדש וריק.
 * מחזיר { model, frameId, flowId }.
 */
export function addFrame(model, pageIndex, { box, flowId = null, newId }) {
  const frameId = newId('t');
  const frameBox = box || contentBox(model, pageIndex + 1);
  let flows = { ...flowsOf(model) };
  if (!flowId) {
    flowId = newFlowId(model);
    flows[flowId] = { engine: 'prosemirror', doc: emptyDoc(), frames: [], autogrow: false, style: 'body' };
  }
  flows[flowId] = { ...flows[flowId], frames: [...(flows[flowId].frames || []), frameId] };
  const frame = { id: frameId, type: 'frame', name: 'טקסט', box: frameBox.map(v => Math.round(v * 100) / 100), flow: flowId, columns: 1, gutter: 12 };
  model = withPageLayers({ ...model, flows }, pageIndex, layers => [...layers, frame]);
  return { model, frameId, flowId };
}

/** עדכון מסגרת (box, columns, gutter…). */
export function updateFrame(model, frameId, patch) {
  return { ...model, surfaces: model.surfaces.map(s => s.type !== 'page' || !(s.layers || []).some(l => l.id === frameId) ? s
    : { ...s, layers: s.layers.map(l => l.id === frameId ? { ...l, ...patch } : l) }) };
}

/** מחיקת מסגרת. הטקסט נשאר בשטף וזורם למסגרות האחרות; שטף בלי מסגרות — נמחק. */
export function removeFrame(model, frameId) {
  const m = { ...model, surfaces: model.surfaces.map(s => s.type !== 'page' ? s : { ...s, layers: (s.layers || []).filter(l => l.id !== frameId) }) };
  return pruneFlows(m);
}

/** ניקוי: מסגרות שנעלמו (למשל עמודים שהורדו) יוצאות מהשרשור; שטף בלי מסגרות נמחק. */
export function pruneFlows(model) {
  const alive = new Set(allFrames(model).map(x => x.frame.id));
  const flows = {};
  for (const [id, f] of Object.entries(flowsOf(model))) {
    const frames = (f.frames || []).filter(x => alive.has(x));
    if (frames.length || f.engine !== 'prosemirror' || !Array.isArray(f.frames)) flows[id] = { ...f, frames: Array.isArray(f.frames) ? frames : f.frames };
  }
  return { ...model, flows };
}

/**
 * זרימה אוטומטית מעמוד: מסגרת באזור התוכן בכל עמוד מכאן ועד הסוף (עמוד שכבר יש בו מסגרת של השטף — מדלגים),
 * כולן בשרשור אחד, ו-autogrow — טקסט שלא נכנס יוסיף עמודים. flowId — שטף קיים או null (חדש).
 */
export function autoflow(model, fromIndex, { flowId = null, newId }) {
  const n = pagesOf(model).length;
  for (let i = fromIndex; i < n; i++) {
    if (flowId && framesOnPage(model, i).some(f => f.flow === flowId)) continue;
    ({ model, flowId } = addFrame(model, i, { flowId, newId }));
  }
  return { model: setFlow(model, flowId, { autogrow: true }), flowId };
}

export function setFlow(model, flowId, patch) {
  return { ...model, flows: { ...flowsOf(model), [flowId]: { ...flowsOf(model)[flowId], ...patch } } };
}

/**
 * הוספת עמודים בסוף כדי שהשטף ייכנס: count עמודים חדשים, ובכל אחד מסגרת באזור התוכן שממשיכה את השטף.
 */
export function growFlow(model, flowId, count, newId) {
  const start = pagesOf(model).length;
  model = setPageCount(model, start + count, () => newId('p'));
  for (let i = start; i < start + count; i++) ({ model } = addFrame(model, i, { flowId, newId }));
  return model;
}

// ===== טקסט ⇄ ProseMirror =====

/** הטקסט של השטף: פסקה בכל שורה. (סימני עיצוב — בשלב הסגנונות.) */
export function flowText(model, flowId) {
  const doc = flowsOf(model)[flowId]?.doc;
  return docParagraphs(doc).join('\n');
}

/** פסקאות כמחרוזות. צמתים שאינם פסקה (כותרת, רשימה…) — הטקסט שבתוכם, כפסקה. */
export function docParagraphs(doc) {
  const text = n => n.type === 'text' ? n.text || '' : (n.content || []).map(text).join('');
  return (doc?.content || []).map(text);
}

export function textToDoc(text) {
  const paras = String(text).replace(/\r\n?/g, '\n').split('\n');
  return { type: 'doc', content: paras.map(p => p ? { type: 'paragraph', content: [{ type: 'text', text: p }] } : { type: 'paragraph' }) };
}

/** המסגרת שבנקודה בעמוד (קואורדינטות העמוד), העליונה מביניהן. */
export function frameAt(model, pageIndex, x, y) {
  const fr = framesOnPage(model, pageIndex);
  for (let i = fr.length - 1; i >= 0; i--) {
    const [bx, by, bw, bh] = fr[i].box;
    if (x >= bx && x <= bx + bw && y >= by && y <= by + bh) return fr[i];
  }
  return null;
}
