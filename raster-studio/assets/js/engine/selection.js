// בחירה: רשימת צורות (מלבן / אליפסה / לאסו) עם פעולה — הוספה או חיסור — ואפשרות להיפוך.
// נשמרת כווקטור (קטן, מדויק, בלי תלות בגודל התמונה), והופכת למסכה רק לאריחים שצריך — rasterTile.
//
// sel = { shapes: [{ op: 'add' | 'sub', type: 'rect' | 'ellipse' | 'poly', x, y, w, h, pts }], inverted }

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

/** מתווה צורה בהקשר דו-ממדי. map — פונקציה ממסמך למסך (או זהות). */
export function tracePath(ctx, s, map = (x, y) => [x, y]) {
  if (s.type === 'rect') {
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
