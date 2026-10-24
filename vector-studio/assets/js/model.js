// המסמך — בצורה של document.json ב-‎.mbe (creative-suite/docs/mbe-format.md), כדי שהשמירה תהיה העתקה ולא תרגום.
//
// משטח = לוח עבודה (type: "artboard"), בו שכבות `vector`, ובכל שכבה `paths` (ההצעה בבקשה
// creative-suite/docs/requests/vector-studio-paths.md). קואורדינטות של נתיב — ביחידות pt של המשטח שלו.

import { parse, bbox, transform, serialize, unionBox } from './geometry/path.js';
import { multiply, translate, isIdentity, IDENTITY } from './geometry/matrix.js';
import { shapeToD, transformShape } from './geometry/shapes.js';

export const SIZES = [
  { id: 'a4', name: 'A4', size: [595.3, 841.9], icon: '📄' },
  { id: 'square', name: 'ריבוע 1080', size: [1080, 1080], icon: '⬛' },
  { id: 'story', name: 'סטורי 1080×1920', size: [1080, 1920], icon: '📱' },
  { id: 'icon', name: 'אייקון 512', size: [512, 512], icon: '🔷' },
];

export const DEFAULT_STYLE = {
  fill: { color: '#2d4a8a' },
  stroke: { color: '#16192b', width: 1, cap: 'butt', join: 'miter', dash: [] },
};

export function newDocument(size) {
  return {
    units: 'pt',
    color: { space: 'sRGB' },
    surfaces: [{
      id: 's1', type: 'artboard', name: 'לוח 1', size: size.slice(),
      layers: [{ id: 'L1', name: 'שכבה 1', type: 'vector', opacity: 1, blend: 'normal', visible: true, locked: false, paths: [] }],
    }],
  };
}

/** מזהה חדש עם קידומת, שלא קיים בשום מקום במסמך (משטחים, שכבות ונתיבים יחד — כלל של הליבה). */
export function nextId(doc, prefix) {
  const used = new Set();
  for (const s of doc.surfaces) {
    used.add(s.id);
    for (const l of s.layers || []) { used.add(l.id); for (const p of l.paths || []) used.add(p.id); }
  }
  let n = 1;
  while (used.has(prefix + n)) n++;
  return prefix + n;
}

/** מיקום הלוחות במרחב העבודה: זה לצד זה, ברווח של 48pt. */
export function layout(doc) {
  const out = new Map();
  let x = 0;
  for (const s of doc.surfaces) {
    out.set(s.id, [x, 0]);
    x += (s.size?.[0] || 0) + 48;
  }
  return out;
}

/** כל האובייקטים, מלמטה למעלה: { path, layer, surface }. */
export function* objects(doc) {
  for (const surface of doc.surfaces) {
    for (const layer of surface.layers || []) {
      if (layer.type !== 'vector' || !Array.isArray(layer.paths)) continue;
      for (const path of layer.paths) yield { path, layer, surface };
    }
  }
}

export function findPath(doc, id) {
  for (const o of objects(doc)) if (o.path.id === id) return o;
  return null;
}

/** המטריצה ממשטח הנתיב למרחב העבודה. */
export function pathMatrix(path, offset) {
  const m = path.transform && !isIdentity(path.transform) ? path.transform : IDENTITY;
  return multiply(translate(offset[0], offset[1]), m);
}

/** תיבה חוסמת במרחב העבודה. */
export function worldBox(path, offset) {
  return bbox(transform(parse(path.d), pathMatrix(path, offset)));
}

export function selectionBox(doc, ids) {
  const lay = layout(doc);
  const boxes = [];
  for (const id of ids) {
    const o = findPath(doc, id);
    if (o) boxes.push(worldBox(o.path, lay.get(o.surface.id)));
  }
  return unionBox(boxes);
}

/**
 * מחיל מטריצה (במרחב המשטח) על נתיב: הזזה ושינוי גודל נאפים ל-`d` ולפרמטרי הצורה, כך שהמתאר לא
 * נמתח והצורה נשארת עריכה. צורה שלא ניתן לשמר (סיבוב) — הופכת לנתיב.
 */
export function applyMatrix(path, m) {
  if (path.transform && !isIdentity(path.transform)) {
    path.transform = multiply(m, path.transform);
    return;
  }
  if (path.shape) {
    const s = transformShape(path.shape, m);
    if (s) { setShape(path, s); return; }
    delete path.shape;
  }
  path.d = serialize(transform(parse(path.d), m));
}

/** פרמטרים ב-3 ספרות אחרי הנקודה, כמו `d` — בלי שאריות של חישוב (800.0000390…). */
const r3 = (v) => Math.round(v * 1000) / 1000 || 0;
function roundShape(shape) {
  const s = { ...shape };
  for (const k of ['box', 'from', 'to']) if (Array.isArray(s[k])) s[k] = s[k].map(r3);
  if (typeof s.radius === 'number') s.radius = r3(s.radius);
  return s;
}

export function setShape(path, shape) {
  shape = roundShape(shape);
  path.shape = shape;
  path.d = shapeToD(shape);
}

export const clone = (v) => JSON.parse(JSON.stringify(v));
