// מסגרות טקסט ושטפים — פעולות על המודל, בלי DOM (נבדק ב-tests/frames.test.mjs).
//
// מסגרת = שכבה על עמוד:   { id, type: 'frame', name, box: [x, y, w, h] (pt, מפינת העמוד), flow, columns, gutter }
// שטף   = flows[id]:       { engine: 'prosemirror', doc, frames: [מזהי מסגרות בסדר הזרימה], autogrow, style }
//   doc — JSON של ProseMirror (כמו שעורך התמלילים שומר): פסקאות עם טקסט.
//   autogrow — טקסט שלא נכנס מוסיף עמודים ומסגרות בסוף, עד שנגמר ("זרימה אוטומטית").
// סגנון פסקה = styles[key]: { name, font, size, leading, align, weight, spaceBefore, spaceAfter, indent }
//   body — פסקה רגילה; h1…h3 — כותרות (בצומת heading של ProseMirror, לפי level). מידות ב-pt; weight — ציר wght.
// סגנון תו: סימון bold בטקסט (mark של ProseMirror) — אותו גופן במשקל 700.

import { pagesOf, contentBox, setPageCount } from './pages.js';

export const DEFAULT_STYLE = { name: 'גוף', font: 'Noto Serif Hebrew', size: 12, leading: 18, align: 'justify', weight: 400,
  spaceBefore: 0, spaceAfter: 6, indent: 0 };
export const DEFAULT_STYLES = {
  body: DEFAULT_STYLE,
  h1: { ...DEFAULT_STYLE, name: 'כותרת 1', size: 20, leading: 26, align: 'center', weight: 700, spaceBefore: 12, spaceAfter: 10 },
  h2: { ...DEFAULT_STYLE, name: 'כותרת 2', size: 16, leading: 22, align: 'right', weight: 700, spaceBefore: 10, spaceAfter: 6 },
  h3: { ...DEFAULT_STYLE, name: 'כותרת 3', size: 13, leading: 18, align: 'right', weight: 700, spaceBefore: 8, spaceAfter: 4 },
};
export const STYLE_KEYS = Object.keys(DEFAULT_STYLES);
export const FONTS = { 'Noto Serif Hebrew': 'NotoSerifHebrew.ttf', 'Frank Ruhl Libre': 'FrankRuhlLibre.ttf' };

/** סגנון פסקה עם ברירות המחדל (מסמך ישן בלי חלק מהשדות או בלי הסגנון כולו). */
export function styleOf(model, key) {
  const def = DEFAULT_STYLES[key] || DEFAULT_STYLE;
  return { ...def, ...(model.styles && model.styles[key]) };
}

export const bodyStyle = model => styleOf(model, 'body');

export function setStyle(model, key, patch) {
  return { ...model, styles: { ...(model.styles || {}), [key]: { ...styleOf(model, key), ...patch } } };
}

export const setBodyStyle = (model, patch) => setStyle(model, 'body', patch);

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
  return docBlocks(doc).map(b => b.runs.map(r => r.text).join(''));
}

/**
 * ProseMirror → בלוקים לפריסה: [{ style, runs: [{ text, bold }] }].
 *   paragraph → body; heading (level 1–3) → h1…h3; צומת אחר ברמה העליונה — הטקסט שבו, כגוף.
 *   סימון bold / strong → מודגש. hardBreak → '\n' (שבירת שורה בתוך הפסקה).
 */
export function docBlocks(doc) {
  const out = [];
  for (const n of doc?.content || []) {
    const style = n.type === 'heading' ? 'h' + Math.min(3, Math.max(1, n.attrs?.level || 1)) : 'body';
    const runs = [];
    const walk = (node, bold) => {
      if (node.type === 'text') {
        const b = bold || (node.marks || []).some(m => m.type === 'bold' || m.type === 'strong');
        const last = runs[runs.length - 1];
        if (last && last.bold === b) last.text += node.text || '';
        else runs.push({ text: node.text || '', bold: b });
      } else if (node.type === 'hard_break' || node.type === 'hardBreak') walk({ type: 'text', text: '\n' }, bold);
      else for (const c of node.content || []) walk(c, bold);
    };
    walk(n, false);
    out.push({ style, runs });
  }
  return out;
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

// ===== מסגרות תמונה =====
// מסגרת תמונה = { id, type: 'frame', name, box, src: 'media/<sha256>.<ext>', px: [w, h] (פיקסלים),
//   fit: 'contain' (כל התמונה נראית) | 'cover' (ממלאת, נחתכת), focus: [fx, fy] (0–1: איזה חלק נשאר בחיתוך),
//   zoom: 1–10, wrap: 'none' | 'box' (הטקסט עוקף), wrapGap: pt }

export const isImage = frame => typeof frame.src === 'string';

/**
 * תמונה חדשה בעמוד: באזור התוכן, ביחס של התמונה, צמודה לימין ולמעלה (עברית). מחזיר { model, frameId }.
 */
export function addImageFrame(model, pageIndex, { src, px, newId }) {
  const [cx, cy, cw, ch] = contentBox(model, pageIndex + 1);
  const s = Math.min(cw / px[0], ch / px[1], 1e9);
  const w = px[0] * s, h = px[1] * s;
  const frameId = newId('i');
  const frame = { id: frameId, type: 'frame', name: 'תמונה', box: [cx + cw - w, cy, w, h].map(v => Math.round(v * 100) / 100),
    src, px: [...px], fit: 'contain', focus: [0.5, 0.5], zoom: 1, wrap: 'none', wrapGap: 8 };
  return { model: withPageLayers(model, pageIndex, layers => [...layers, frame]), frameId };
}

/**
 * היכן מצוירת התמונה (קואורדינטות העמוד) — היא נחתכת לגבולות המסגרת: { x, y, w, h }.
 * contain — כולה בפנים; cover — ממלאת; zoom — הגדלה נוספת; focus — איזה חלק נשאר כשהיא גדולה מהמסגרת
 * (0 — הקצה השמאלי / העליון, 1 — הימני / התחתון), או היכן היא יושבת כשהיא קטנה ממנה.
 */
export function imagePlacement(frame) {
  const [bx, by, bw, bh] = frame.box;
  const [iw, ih] = frame.px;
  const base = frame.fit === 'cover' ? Math.max(bw / iw, bh / ih) : Math.min(bw / iw, bh / ih);
  const s = base * (frame.zoom || 1);
  const w = iw * s, h = ih * s;
  const [fx, fy] = frame.focus || [0.5, 0.5];
  return { x: bx + (bw - w) * fx, y: by + (bh - h) * fy, w, h };
}

/** האזורים שהטקסט עוקף בעמוד: [{ x, y, w, h }] — מסגרות עם wrap: 'box', מורחבות במרווח. */
export function wrapRects(model, pageIndex) {
  return framesOnPage(model, pageIndex).filter(f => f.wrap === 'box').map(f => {
    const g = f.wrapGap || 0, [x, y, w, h] = f.box;
    return { id: f.id, x: x - g, y: y - g, w: w + 2 * g, h: h + 2 * g };
  });
}
