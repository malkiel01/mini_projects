// ציור הקנבס: מרחב העבודה, הלוחות, הנתיבים, ושכבת-על של בחירה וידיות — בפיקסלים של המסך, כדי שיהיו
// באותו עובי בכל זום. הלוח עצמו לבן תמיד (כמו נייר), גם במצב כהה.

import { multiply } from './geometry/matrix.js';
import { objects, layout, pathMatrix, worldBox, findPath } from './model.js';

const pathCache = new Map();
/** Path2D לפי `d` — נבנה פעם אחת לכל צורה. */
export function path2d(d) {
  let p = pathCache.get(d);
  if (!p) {
    if (pathCache.size > 2000) pathCache.clear();
    p = new Path2D(d);
    pathCache.set(d, p);
  }
  return p;
}

export const HANDLE = 9; // גודל ידית בפיקסלים
export const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

/** מיקום הידיות במסך: { name: [x, y] }. */
export function handlePoints(box, view) {
  const [x, y, w, h] = toScreenBox(box, view);
  const mx = x + w / 2, my = y + h / 2;
  return { nw: [x, y], n: [mx, y], ne: [x + w, y], e: [x + w, my], se: [x + w, y + h], s: [mx, y + h], sw: [x, y + h], w: [x, my] };
}

export function toScreenBox([x, y, w, h], v) {
  return [x * v.z + v.x, y * v.z + v.y, w * v.z, h * v.z];
}

/** המטריצה ממרחב העבודה לפיקסלי CSS של הקנבס. */
export const viewMatrix = (v) => [v.z, 0, 0, v.z, v.x, v.y];

let colors = null;
export function readColors() {
  const cs = getComputedStyle(document.documentElement);
  const get = (n) => cs.getPropertyValue(n).trim();
  colors = { bg: get('--bg'), line: get('--line'), brand: get('--brand'), muted: get('--muted'), surface: get('--surface') };
}

export function strokeStyle(ctx, st) {
  ctx.lineWidth = st.width ?? 1;
  ctx.lineCap = st.cap || 'butt';
  ctx.lineJoin = st.join || 'miter';
  ctx.miterLimit = st.miter ?? 4;
  ctx.setLineDash(Array.isArray(st.dash) ? st.dash : []);
}

function drawPath(ctx, path) {
  const p = path2d(path.d);
  const fill = path.fill, stroke = path.stroke;
  if (fill && fill.color) {
    ctx.globalAlpha = fill.opacity ?? 1;
    ctx.fillStyle = fill.color;
    ctx.fill(p, path.fillRule === 'evenodd' ? 'evenodd' : 'nonzero');
  }
  if (stroke && stroke.color && (stroke.width ?? 1) > 0) {
    ctx.globalAlpha = stroke.opacity ?? 1;
    ctx.strokeStyle = stroke.color;
    strokeStyle(ctx, stroke);
    ctx.stroke(p);
  }
  ctx.globalAlpha = 1;
}

/**
 * state: { doc, view: {x, y, z}, sel: Set, hover, marquee: [x,y,w,h] במסך, dpr }.
 */
export function render(canvas, state) {
  if (!colors) readColors();
  const ctx = canvas.getContext('2d');
  const { doc, view, dpr } = state;
  const W = canvas.width / dpr, H = canvas.height / dpr;
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.fillStyle = colors.bg;
  ctx.fillRect(0, 0, W, H);

  const lay = layout(doc);
  const vm = viewMatrix(view);
  const setT = (m) => { const t = multiply(vm, m); ctx.setTransform(dpr * t[0], dpr * t[1], dpr * t[2], dpr * t[3], dpr * t[4], dpr * t[5]); };

  // הלוחות
  for (const s of doc.surfaces) {
    const [ox, oy] = lay.get(s.id);
    const [x, y, w, h] = toScreenBox([ox, oy, s.size[0], s.size[1]], view);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = 'rgba(0,0,0,.08)';
    ctx.fillRect(x + 1, y + 2, w, h);
    ctx.fillStyle = '#fff';
    ctx.fillRect(x, y, w, h);
    ctx.strokeStyle = colors.line;
    ctx.lineWidth = 1;
    ctx.setLineDash([]);
    ctx.strokeRect(Math.round(x) - .5, Math.round(y) - .5, Math.round(w) + 1, Math.round(h) + 1);
    if (state.showNames !== false && h > 30) {
      ctx.fillStyle = colors.muted;
      ctx.font = '12px system-ui, sans-serif';
      ctx.textAlign = 'right';
      ctx.direction = 'rtl';
      ctx.fillText(s.name || '', x + w, y - 6);
    }
  }

  // האובייקטים
  for (const { path, layer, surface } of objects(doc)) {
    if (layer.visible === false || path.hidden) continue;
    setT(pathMatrix(path, lay.get(surface.id)));
    ctx.save();
    if ((layer.opacity ?? 1) < 1) ctx.globalAlpha = layer.opacity;
    try { drawPath(ctx, path); } catch { /* d פגום — לא מציירים, לא קורסים */ }
    ctx.restore();
  }

  // שכבת-על במסך
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.setLineDash([]);
  ctx.lineWidth = 1;
  ctx.strokeStyle = colors.brand;
  const outline = (id) => {
    const o = findPath(doc, id);
    if (!o) return;
    setT(pathMatrix(o.path, lay.get(o.surface.id)));
    const t = ctx.getTransform();
    ctx.lineWidth = 1 / Math.sqrt(Math.abs(t.a * t.d - t.b * t.c) / (dpr * dpr) || 1);
    ctx.stroke(path2d(o.path.d));
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.lineWidth = 1;
  };
  if (state.hover && !state.sel.has(state.hover)) outline(state.hover);
  for (const id of state.sel) outline(id);

  if (state.selBox) {
    const [x, y, w, h] = toScreenBox(state.selBox, view);
    ctx.strokeRect(x, y, w, h);
    if (state.handles !== false) {
      for (const [hx, hy] of Object.values(handlePoints(state.selBox, view))) {
        ctx.fillStyle = '#fff';
        ctx.fillRect(hx - HANDLE / 2, hy - HANDLE / 2, HANDLE, HANDLE);
        ctx.strokeRect(hx - HANDLE / 2 + .5, hy - HANDLE / 2 + .5, HANDLE - 1, HANDLE - 1);
      }
    }
  }
  if (state.marquee) {
    const [x, y, w, h] = state.marquee;
    ctx.fillStyle = 'rgba(45,74,138,.08)';
    ctx.fillRect(x, y, w, h);
    ctx.setLineDash([4, 3]);
    ctx.strokeRect(x + .5, y + .5, w, h);
    ctx.setLineDash([]);
  }
}

const probe = typeof document !== 'undefined' ? document.createElement('canvas').getContext('2d') : null;

/**
 * האובייקט העליון בנקודה (פיקסלי CSS). מילוי — בתוך הצורה; מתאר — בעובי המתאר, ולפחות `tol` פיקסלים,
 * כדי שקו דק עדיין ייתפס באצבע.
 */
export function hitTest(state, sx, sy, tol = 6) {
  const { doc, view } = state;
  const lay = layout(doc);
  const vm = viewMatrix(view);
  const all = [...objects(doc)].reverse();
  for (const { path, layer, surface } of all) {
    if (layer.visible === false || layer.locked || path.hidden || path.locked) continue;
    const t = multiply(vm, pathMatrix(path, lay.get(surface.id)));
    probe.setTransform(t[0], t[1], t[2], t[3], t[4], t[5]);
    let p;
    try { p = path2d(path.d); } catch { continue; }
    if (path.fill && path.fill.color && probe.isPointInPath(p, sx, sy, path.fillRule === 'evenodd' ? 'evenodd' : 'nonzero')) return path.id;
    const scale = Math.sqrt(Math.abs(t[0] * t[3] - t[1] * t[2])) || 1;
    probe.lineWidth = Math.max((path.stroke?.color ? path.stroke.width ?? 1 : 0), (tol * 2) / scale);
    probe.lineCap = 'round'; probe.lineJoin = 'round';
    if (probe.isPointInStroke(p, sx, sy)) return path.id;
  }
  return null;
}

/** האובייקטים שהתיבה שלהם נוגעת במלבן (במרחב העבודה). */
export function inRect(doc, [x, y, w, h]) {
  const lay = layout(doc);
  const ids = [];
  for (const { path, layer, surface } of objects(doc)) {
    if (layer.visible === false || layer.locked || path.hidden || path.locked) continue;
    const b = worldBox(path, lay.get(surface.id));
    if (b && b[0] < x + w && b[0] + b[2] > x && b[1] < y + h && b[1] + b[3] > y) ids.push(path.id);
  }
  return ids;
}
