// בחירה: רשימת צורות (מלבן / אליפסה / לאסו) עם פעולה — הוספה או חיסור — ואפשרות להיפוך.
// נשמרת כווקטור (קטן, מדויק, בלי תלות בגודל התמונה), והופכת למסכה רק לאריחים שצריך — rasterTile.
//
// sel = { shapes: [{ op: 'add' | 'sub', type: 'rect' | 'ellipse' | 'poly' | 'mask', x, y, w, h, pts, data }], inverted }
// mask — מסכת פיקסלים (מטה הקסם, טווח צבעים): data — Uint8Array של w×h (0–255), שפינתה ב-(x, y).

import { TILE } from './doc.js';

/** מלבן חוסם של צורה: [x0, y0, x1, y1] */
export function shapeBox(s) {
  if (s.type === 'poly') {
    let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    for (const [x, y] of s.pts) { x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y); }
    return [x0, y0, x1, y1];
  }
  return [s.x, s.y, s.x + s.w, s.y + s.h];
}

/** צורה שאין בה שטח (לחיצה בלי גרירה) */
export function isEmptyShape(s) {
  const [x0, y0, x1, y1] = shapeBox(s);
  return x1 - x0 < 1 || y1 - y0 < 1 || (s.type === 'poly' && s.pts.length < 3);
}

/**
 * המלבן שהבחירה יכולה לגעת בו, חתוך לקנבס — [x0, y0, x1, y1] במספרים שלמים, או null (בחירה ריקה).
 * הפוכה — כל הקנבס. חיסור לא מקטין את המלבן (הערכה מלמעלה — מספיקה לבחירת אריחים).
 */
export function selectionBox(sel, W, H) {
  if (!sel) return null;
  if (sel.inverted) return [0, 0, W, H];
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  for (const s of sel.shapes) {
    if (s.op !== 'add') continue;
    const b = shapeBox(s);
    x0 = Math.min(x0, b[0]); y0 = Math.min(y0, b[1]); x1 = Math.max(x1, b[2]); y1 = Math.max(y1, b[3]);
  }
  x0 = Math.max(0, Math.floor(x0)); y0 = Math.max(0, Math.floor(y0));
  x1 = Math.min(W, Math.ceil(x1)); y1 = Math.min(H, Math.ceil(y1));
  return x1 > x0 && y1 > y0 ? [x0, y0, x1, y1] : null;
}

/** בחירה חדשה מצורה, לפי פעולה: 'new' מחליפה, 'add' / 'sub' מוסיפות לקיימת. מחזיר בחירה (או null). */
export function combine(sel, shape, mode) {
  if (mode === 'new' || !sel) {
    return mode === 'sub' ? null : { shapes: [{ ...shape, op: 'add' }], inverted: false };
  }
  // בבחירה הפוכה, "הוספה" מחסירה מהצורות (ולהפך) — כי השטח הנבחר הוא מה שמחוץ להן
  const op = (mode === 'add') !== sel.inverted ? 'add' : 'sub';
  return { shapes: [...sel.shapes, { ...shape, op }], inverted: sel.inverted };
}

/**
 * מסכת פיקסלים מתוך מסכה בגודל התמונה (כמו של floodFill), חתוכה למלבן box = [x0, y0, x1, y1] (כולל).
 * null — אין בה כלום.
 */
export function maskShape(mask, W, box) {
  if (!box) return null;
  const [x0, y0, x1, y1] = box, w = x1 - x0 + 1, h = y1 - y0 + 1;
  const data = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) data.set(mask.subarray((y0 + y) * W + x0, (y0 + y) * W + x0 + w), y * w);
  return { type: 'mask', x: x0, y: y0, w, h, data };
}

const outlines = new WeakMap();   // data → קטעי הגבול (מחושבים פעם אחת)
/**
 * גבול מסכה: קטעים אופקיים ואנכיים בין פיקסל נבחר (≥128) לפיקסל שלא, ביחידות המסכה.
 * כל קטע — [x0, y0, x1, y1]; רצפים באותה שורה / עמודה מאוחדים לקטע אחד.
 */
export function maskOutline(s) {
  let segs = outlines.get(s.data);
  if (segs) return segs;
  segs = [];
  const { w, h, data } = s;
  const on = (x, y) => x >= 0 && y >= 0 && x < w && y < h && data[y * w + x] >= 128;
  for (let y = 0; y <= h; y++) {
    let start = -1;
    for (let x = 0; x <= w; x++) {
      const edge = x < w && on(x, y - 1) !== on(x, y);
      if (edge && start < 0) start = x;
      else if (!edge && start >= 0) { segs.push([start, y, x, y]); start = -1; }
    }
  }
  for (let x = 0; x <= w; x++) {
    let start = -1;
    for (let y = 0; y <= h; y++) {
      const edge = y < h && on(x - 1, y) !== on(x, y);
      if (edge && start < 0) start = y;
      else if (!edge && start >= 0) { segs.push([x, start, x, y]); start = -1; }
    }
  }
  outlines.set(s.data, segs);
  return segs;
}

/** מתווה צורה בהקשר דו-ממדי. map — פונקציה ממסמך למסך (או זהות). מסכה — הגבול שלה, לקו בלבד (לא למילוי). */
export function tracePath(ctx, s, map = (x, y) => [x, y]) {
  if (s.type === 'mask') {
    for (const [x0, y0, x1, y1] of maskOutline(s)) {
      ctx.moveTo(...map(s.x + x0, s.y + y0));
      ctx.lineTo(...map(s.x + x1, s.y + y1));
    }
  } else if (s.type === 'rect') {
    const pts = [[s.x, s.y], [s.x + s.w, s.y], [s.x + s.w, s.y + s.h], [s.x, s.y + s.h]].map(([x, y]) => map(x, y));
    ctx.moveTo(...pts[0]);
    for (const p of pts.slice(1)) ctx.lineTo(...p);
    ctx.closePath();
  } else if (s.type === 'ellipse') {
    // אליפסה כמצולע צפוף — עובד גם כשהמיפוי אינו רק קנה מידה
    const cx = s.x + s.w / 2, cy = s.y + s.h / 2, rx = s.w / 2, ry = s.h / 2;
    const n = Math.max(32, Math.min(720, Math.ceil((rx + ry) * 0.75)));
    for (let i = 0; i <= n; i++) {
      const a = i / n * Math.PI * 2;
      const p = map(cx + rx * Math.cos(a), cy + ry * Math.sin(a));
      if (i) ctx.lineTo(...p); else ctx.moveTo(...p);
    }
    ctx.closePath();
  } else {
    s.pts.forEach(([x, y], i) => { const p = map(x, y); if (i) ctx.lineTo(...p); else ctx.moveTo(...p); });
    ctx.closePath();
  }
}

const maskCanvases = new WeakMap();   // data → OffscreenCanvas: לבן, והאלפא — המסכה
function maskCanvas(s) {
  let c = maskCanvases.get(s.data);
  if (c) return c;
  c = new OffscreenCanvas(s.w, s.h);
  const img = new ImageData(s.w, s.h);
  for (let i = 0; i < s.data.length; i++) {
    img.data[i * 4] = img.data[i * 4 + 1] = img.data[i * 4 + 2] = 255;
    img.data[i * 4 + 3] = s.data[i];
  }
  c.getContext('2d').putImageData(img, 0, 0);
  maskCanvases.set(s.data, c);
  return c;
}

let tileCtx = null;
/**
 * מסכת הבחירה של אריח: Uint8Array של 256×256 (0–255, קצוות רכים בפיקסל אחד), או null אם האריח כולו מחוץ.
 * מצויר ב-OffscreenCanvas — הדפדפן כבר יודע למלא מצולעים עם החלקת קצוות.
 */
export function rasterTile(sel, tx, ty) {
  tileCtx ??= new OffscreenCanvas(TILE, TILE).getContext('2d', { willReadFrequently: true });
  const ctx = tileCtx;
  const ox = tx * TILE, oy = ty * TILE;
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.globalCompositeOperation = 'source-over';
  ctx.clearRect(0, 0, TILE, TILE);
  if (sel.inverted) { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, TILE, TILE); }
  ctx.translate(-ox, -oy);
  for (const s of sel.shapes) {
    const adds = (s.op === 'add') !== sel.inverted;
    ctx.globalCompositeOperation = adds ? 'source-over' : 'destination-out';
    if (s.type === 'mask') {
      // אריח שהמסכה לא נוגעת בו — דילוג (לא מציירים קנבס שלם בשביל כלום)
      if (s.x >= ox + TILE || s.y >= oy + TILE || s.x + s.w <= ox || s.y + s.h <= oy) continue;
      ctx.drawImage(maskCanvas(s), s.x, s.y);
      continue;
    }
    ctx.fillStyle = '#fff';
    ctx.beginPath();
    tracePath(ctx, s);
    ctx.fill();
  }
  const data = ctx.getImageData(0, 0, TILE, TILE).data;
  const out = new Uint8Array(TILE * TILE);
  let any = 0;
  for (let i = 0; i < out.length; i++) { out[i] = data[i * 4 + 3]; any |= out[i]; }
  return any ? out : null;
}

/**
 * הבחירה כמסכה בגודל הקנבס: { mask: Uint8Array של W×H (0–255), box: [x0, y0, x1, y1] כולל } — כמו שהמנוע מקבל
 * (maskFromAlpha). בלי בחירה / בחירה ריקה — null.
 */
export function selectionAlpha(sel, W, H) {
  const b = selectionBox(sel, W, H);
  if (!b) return null;
  const mask = new Uint8Array(W * H);
  let any = false;
  for (let ty = Math.floor(b[1] / TILE); ty * TILE < b[3]; ty++) {
    for (let tx = Math.floor(b[0] / TILE); tx * TILE < b[2]; tx++) {
      const t = rasterTile(sel, tx, ty);
      if (!t) continue;
      any = true;
      for (let y = 0; y < TILE; y++) {
        const dy = ty * TILE + y;
        if (dy >= H) break;
        const x1 = Math.min(TILE, W - tx * TILE);
        mask.set(t.subarray(y * TILE, y * TILE + x1), dy * W + tx * TILE);
      }
    }
  }
  return any ? { mask, box: [b[0], b[1], b[2] - 1, b[3] - 1] } : null;
}

/** טשטוש תיבה מופרד (רדיוס r) על מערך Float32 של w×h, במקום — n מעברים ≈ גאוסי */
function boxBlur(a, w, h, r, n = 3) {
  if (r < 1) return a;
  const tmp = new Float32Array(Math.max(w, h));
  const pass = (len, count, stride, step) => {
    for (let c = 0; c < count; c++) {
      const base = c * stride;
      let s = 0;
      for (let i = -r; i <= r; i++) s += a[base + Math.min(len - 1, Math.max(0, i)) * step];
      for (let i = 0; i < len; i++) {
        tmp[i] = s / (2 * r + 1);
        s += a[base + Math.min(len - 1, i + r + 1) * step] - a[base + Math.max(0, i - r) * step];
      }
      for (let i = 0; i < len; i++) a[base + i * step] = tmp[i];
    }
  };
  for (let k = 0; k < n; k++) { pass(w, h, w, 1); pass(h, w, 1, w); }
  return a;
}

/** הרחבה (s > 0) או כיווץ (s < 0) ב-s פיקסלים — מקסימום / מינימום בריבוע (מופרד), על מסכה 0..1 */
function morph(a, w, h, s) {
  const r = Math.abs(s), grow = s > 0, tmp = new Float32Array(Math.max(w, h));
  const pass = (len, count, stride, step) => {
    for (let c = 0; c < count; c++) {
      const base = c * stride;
      for (let i = 0; i < len; i++) {
        let v = grow ? 0 : 1;
        for (let j = Math.max(0, i - r); j <= Math.min(len - 1, i + r); j++) {
          const x = a[base + j * step];
          v = grow ? Math.max(v, x) : Math.min(v, x);
        }
        tmp[i] = v;
      }
      for (let i = 0; i < len; i++) a[base + i * step] = tmp[i];
    }
  };
  pass(w, h, w, 1); pass(h, w, 1, w);
}

/**
 * שיפור קצוות (Refine Edge) על מסכת בחירה בגודל הקנבס ({ mask, box } של selectionAlpha):
 * smooth — מחליק את המתאר (טשטוש וחזרה לקצה חד), shift — מרחיב / מכווץ בפיקסלים, feather — מרכך,
 * contrast (0–100) — מחדד בחזרה את הריכוך. מחזיר { mask, box } חדש (או null — ריק).
 */
export function refineMask(sel, W, H, { smooth = 0, shift = 0, feather = 0, contrast = 0 } = {}) {
  if (!sel) return null;
  const pad = Math.ceil(smooth + Math.abs(shift) + feather * 2) + 2;
  const x0 = Math.max(0, sel.box[0] - pad), y0 = Math.max(0, sel.box[1] - pad);
  const x1 = Math.min(W - 1, sel.box[2] + pad), y1 = Math.min(H - 1, sel.box[3] + pad);
  const w = x1 - x0 + 1, h = y1 - y0 + 1;
  const a = new Float32Array(w * h);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) a[y * w + x] = sel.mask[(y0 + y) * W + x0 + x] / 255;
  if (smooth > 0) {
    boxBlur(a, w, h, Math.max(1, Math.round(smooth / 1.7)));
    for (let i = 0; i < a.length; i++) a[i] = a[i] >= 0.5 ? 1 : 0;
  }
  if (shift) morph(a, w, h, Math.round(shift));
  if (feather > 0) boxBlur(a, w, h, Math.max(1, Math.round(feather / 1.7)));
  const slope = 1 / (1 - Math.min(100, Math.max(0, contrast)) / 100 * 0.99);
  const mask = new Uint8Array(W * H);
  let bx0 = W, by0 = H, bx1 = -1, by1 = -1;
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const v = Math.round(Math.min(1, Math.max(0, (a[y * w + x] - 0.5) * slope + 0.5)) * 255);
    if (!v) continue;
    const X = x0 + x, Y = y0 + y;
    mask[Y * W + X] = v;
    if (X < bx0) bx0 = X; if (X > bx1) bx1 = X; if (Y < by0) by0 = Y; if (Y > by1) by1 = Y;
  }
  return bx1 < 0 ? null : { mask, box: [bx0, by0, bx1, by1] };
}
