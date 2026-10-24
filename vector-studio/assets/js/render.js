// ציור הקנבס: מרחב העבודה, הלוחות, הנתיבים, ושכבת-על של בחירה וידיות — בפיקסלים של המסך, כדי שיהיו
// באותו עובי בכל זום. הלוח עצמו לבן תמיד (כמו נייר), גם במצב כהה.

import { multiply, apply, invert } from './geometry/matrix.js';
import { objects, layout, pathMatrix, worldBox, findPath, leaves, isText } from './model.js';
import { lines, lineHeight, fontCss } from './text.js';
import { canvasPaint, hasPaint } from './gradient.js';

import { GRID } from './snap.js';

/** רשת על הלוח: כל GRID pt, ובזום רחוק — כל 5 / 25 קווים, כדי שלא תהיה צפופה מדי. */
function drawGrid(ctx, x, y, w, h, z) {
  let step = GRID;
  while (step * z < 8) step *= 5;
  const px = step * z;
  ctx.save();
  ctx.beginPath();
  ctx.rect(x, y, w, h);
  ctx.clip();
  ctx.strokeStyle = 'rgba(45,74,138,.12)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  for (let gx = x + px; gx < x + w; gx += px) { ctx.moveTo(Math.round(gx) + .5, y); ctx.lineTo(Math.round(gx) + .5, y + h); }
  for (let gy = y + px; gy < y + h; gy += px) { ctx.moveTo(x, Math.round(gy) + .5); ctx.lineTo(x + w, Math.round(gy) + .5); }
  ctx.stroke();
  ctx.restore();
}

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
export const ROT_OFFSET = 26; // ידית הסיבוב — מעל האמצע העליון

export function handlePoints(box, view) {
  const [x, y, w, h] = toScreenBox(box, view);
  const mx = x + w / 2, my = y + h / 2;
  return { nw: [x, y], n: [mx, y], ne: [x + w, y], e: [x + w, my], se: [x + w, y + h], s: [mx, y + h], sw: [x, y + h], w: [x, my], rot: [mx, y - ROT_OFFSET] };
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
  colors = { bg: get('--bg'), line: get('--line'), brand: get('--brand'), muted: get('--muted'), surface: get('--surface'), star: get('--star') };
}

export function strokeStyle(ctx, st) {
  ctx.lineWidth = st.width ?? 1;
  ctx.lineCap = st.cap || 'butt';
  ctx.lineJoin = st.join || 'miter';
  ctx.miterLimit = st.miter ?? 4;
  ctx.setLineDash(Array.isArray(st.dash) ? st.dash : []);
}

/** הטקסט של שטף ProseMirror — פסקה בכל שורה. */
export function flowText(flow) {
  const out = [];
  const walk = (n, para) => {
    if (!n || typeof n !== 'object') return para;
    if (n.type === 'text') return para + (n.text || '');
    if (n.type === 'hard_break') return para + '\n';
    let p = para;
    for (const c of n.content || []) p = walk(c, p);
    if (n.type === 'paragraph' || n.type === 'heading') { out.push(p); return ''; }
    return p;
  };
  const rest = walk(flow?.doc, '');
  if (rest) out.push(rest);
  return out.join('\n');
}

/** טקסט: שורה אחר שורה, מימין לשמאל; המיקום האנכי — בערך כמו בדפדפן (גובה השורה סביב הגופן). */
export function drawText(ctx, item) {
  const font = item.font || {};
  const lh = lineHeight(item);
  const [x, y, w] = item.box;
  ctx.font = fontCss(font);
  ctx.direction = 'rtl';
  ctx.textBaseline = 'alphabetic';
  ctx.textAlign = item.align === 'left' ? 'left' : item.align === 'center' ? 'center' : 'right';
  const tx = item.align === 'left' ? x : item.align === 'center' ? x + w / 2 : x + w;
  lines(item).forEach((l, i) => {
    const by = y + i * lh + (lh - font.size) / 2 + font.size * 0.8;
    if (hasPaint(item.fill)) { ctx.globalAlpha = item.fill.opacity ?? 1; ctx.fillStyle = canvasPaint(ctx, item.fill); ctx.fillText(l.text, tx, by); }
    if (item.stroke?.color && (item.stroke.width ?? 1) > 0) {
      ctx.globalAlpha = item.stroke.opacity ?? 1; ctx.strokeStyle = item.stroke.color; strokeStyle(ctx, item.stroke); ctx.strokeText(l.text, tx, by);
    }
  });
  ctx.globalAlpha = 1;
}

function drawPath(ctx, path) {
  if (isText(path)) return drawText(ctx, path);
  const p = path2d(path.d);
  const fill = path.fill, stroke = path.stroke;
  if (hasPaint(fill)) {
    ctx.globalAlpha = fill.opacity ?? 1;
    ctx.fillStyle = canvasPaint(ctx, fill);
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
  if (state.exportMode) ctx.clearRect(0, 0, W, H); // ייצוא PNG: רק הלוח, בלי מרחב העבודה
  else { ctx.fillStyle = colors.bg; ctx.fillRect(0, 0, W, H); }

  const lay = layout(doc);
  const vm = viewMatrix(view);
  const setT = (m) => { const t = multiply(vm, m); ctx.setTransform(dpr * t[0], dpr * t[1], dpr * t[2], dpr * t[3], dpr * t[4], dpr * t[5]); };

  // הלוחות
  for (const s of doc.surfaces) {
    const [ox, oy] = lay.get(s.id);
    const [x, y, w, h] = toScreenBox([ox, oy, s.size[0], s.size[1]], view);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    if (state.exportMode) {
      if (state.bg) { ctx.fillStyle = '#fff'; ctx.fillRect(x, y, w, h); }
      continue;
    }
    ctx.fillStyle = 'rgba(0,0,0,.08)';
    ctx.fillRect(x + 1, y + 2, w, h);
    ctx.fillStyle = '#fff';
    ctx.fillRect(x, y, w, h);
    if (state.grid) drawGrid(ctx, x, y, w, h, view.z);
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

  // השכבות לפי הסדר: וקטוריות — הנתיבים (קבוצה — הילדים לפי הסדר); של אפליקציות אחרות — תצוגה בלבד
  const drawLayers = (layers, off, alpha) => {
    for (const layer of layers || []) {
      if (layer.visible === false) continue;
      const a = alpha * (layer.opacity ?? 1);
      if (layer.type === 'vector') {
        for (const item of layer.paths || []) {
          if (item.hidden) continue;
          for (const path of leaves(item)) {
            if (path.hidden || path.id === state.editingText) continue;
            setT(pathMatrix(path, off));
            ctx.save();
            if (a < 1) ctx.globalAlpha = a;
            try { drawPath(ctx, path); } catch { /* d פגום — לא מציירים, לא קורסים */ }
            ctx.restore();
          }
        }
      } else if (layer.type === 'group') drawLayers(layer.children, off, a);
      else drawForeign(layer, off, a);
    }
  };
  /** רסטר / מסגרת: תמונה מ-media (אם נטענה) בתיבה או על כל המשטח; מסגרת טקסט — הטקסט של השטף, בפשטות. */
  const drawForeign = (layer, off, a) => {
    const surface = state.doc.surfaces.find(s => lay.get(s.id) === off);
    const box = Array.isArray(layer.box) ? layer.box : [0, 0, surface?.size[0] || 0, surface?.size[1] || 0];
    setT([1, 0, 0, 1, off[0], off[1]]);
    ctx.save();
    if (a < 1) ctx.globalAlpha = a;
    const img = layer.src && state.media?.get(layer.src);
    if (img) ctx.drawImage(img, box[0], box[1], box[2], box[3]);
    else if (layer.type === 'frame' && layer.flow && state.doc.flows?.[layer.flow]) {
      drawText(ctx, { text: flowText(state.doc.flows[layer.flow]), box, auto: false, align: 'right', lineHeight: 1.4,
        font: { family: 'David Libre', size: 11, weight: 400 }, fill: { color: '#16192b' } });
    } else if (layer.src) {
      // התמונה עוד לא נטענה (או חסרה) — מסגרת אפורה במקומה
      ctx.fillStyle = 'rgba(93,100,117,.12)'; ctx.fillRect(...box);
    }
    ctx.restore();
  };
  for (const s of doc.surfaces) drawLayers(s.layers, lay.get(s.id), 1);

  // שכבת-על במסך
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.setLineDash([]);
  ctx.lineWidth = 1;
  ctx.strokeStyle = colors.brand;
  const outline = (id) => {
    const o = findPath(doc, id);
    if (!o) return;
    for (const path of leaves(o.path)) {
      if (isText(path)) {
        // מסגרת הטקסט — מסובבת יחד איתו
        setT(pathMatrix(path, lay.get(o.surface.id)));
        const t = ctx.getTransform();
        ctx.lineWidth = 1 / Math.sqrt(Math.abs(t.a * t.d - t.b * t.c) / (dpr * dpr) || 1);
        ctx.strokeRect(...path.box);
        continue;
      }
      setT(pathMatrix(path, lay.get(o.surface.id)));
      const t = ctx.getTransform();
      ctx.lineWidth = 1 / Math.sqrt(Math.abs(t.a * t.d - t.b * t.c) / (dpr * dpr) || 1);
      try { ctx.stroke(path2d(path.d)); } catch { /* d פגום */ }
    }
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.lineWidth = 1;
  };
  if (state.hover && !state.sel.has(state.hover)) outline(state.hover);
  for (const id of state.sel) outline(id);

  if (state.selBox) {
    const [x, y, w, h] = toScreenBox(state.selBox, view);
    ctx.strokeRect(x, y, w, h);
    if (state.handles !== false) {
      const hp = handlePoints(state.selBox, view);
      ctx.beginPath(); ctx.moveTo(hp.n[0], hp.n[1]); ctx.lineTo(hp.rot[0], hp.rot[1] + HANDLE / 2); ctx.stroke();
      ctx.beginPath(); ctx.arc(hp.rot[0], hp.rot[1], HANDLE / 2 + 1, 0, Math.PI * 2);
      ctx.fillStyle = '#fff'; ctx.fill(); ctx.stroke();
      for (const [name, [hx, hy]] of Object.entries(hp)) {
        if (name === 'rot') continue;
        ctx.fillStyle = '#fff';
        ctx.fillRect(hx - HANDLE / 2, hy - HANDLE / 2, HANDLE, HANDLE);
        ctx.strokeRect(hx - HANDLE / 2 + .5, hy - HANDLE / 2 + .5, HANDLE - 1, HANDLE - 1);
      }
    }
  }
  if (state.guides?.length) {
    ctx.strokeStyle = colors.star;
    ctx.lineWidth = 1;
    for (const g of state.guides) {
      ctx.beginPath();
      if (g.axis === 'x') { const x = Math.round(g.v * view.z + view.x) + .5; ctx.moveTo(x, 0); ctx.lineTo(x, H); }
      else { const y = Math.round(g.v * view.z + view.y) + .5; ctx.moveTo(0, y); ctx.lineTo(W, y); }
      ctx.stroke();
    }
    ctx.strokeStyle = colors.brand;
  }
  if (state.marquee) {
    const [x, y, w, h] = state.marquee;
    ctx.fillStyle = 'rgba(45,74,138,.08)';
    ctx.fillRect(x, y, w, h);
    ctx.setLineDash([4, 3]);
    ctx.strokeRect(x + .5, y + .5, w, h);
    ctx.setLineDash([]);
  }
  if (state.overlay) {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    state.overlay(ctx, colors);
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
  for (const { path: item, layer, surface } of all) {
    if (layer.visible === false || layer.locked || item.hidden || item.locked) continue;
    const off = lay.get(surface.id);
    // קבוצה — פוגעים באחד הילדים, ומחזירים את הקבוצה
    for (const path of [...leaves(item)].reverse()) {
      if (!path.hidden && hitPath(path, multiply(vm, pathMatrix(path, off)), sx, sy, tol)) return item.id;
    }
  }
  return null;
}

function hitPath(path, t, sx, sy, tol) {
  if (isText(path)) {
    const [x, y, w, h] = path.box;
    const [px, py] = apply(invert(t), sx, sy);
    const pad = tol / (Math.sqrt(Math.abs(t[0] * t[3] - t[1] * t[2])) || 1);
    return px >= x - pad && px <= x + w + pad && py >= y - pad && py <= y + h + pad;
  }
  probe.setTransform(t[0], t[1], t[2], t[3], t[4], t[5]);
  let p;
  try { p = path2d(path.d); } catch { return false; }
  if ((path.fill?.color || path.fill?.gradient) && probe.isPointInPath(p, sx, sy, path.fillRule === 'evenodd' ? 'evenodd' : 'nonzero')) return true;
  const scale = Math.sqrt(Math.abs(t[0] * t[3] - t[1] * t[2])) || 1;
  probe.lineWidth = Math.max((path.stroke?.color ? path.stroke.width ?? 1 : 0), (tol * 2) / scale);
  probe.lineCap = 'round'; probe.lineJoin = 'round';
  return probe.isPointInStroke(p, sx, sy);
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
