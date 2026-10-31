// מסכה וקטורית (vectorMask בפורמט, mbe-format.md סעיף 3): { d, feather, enabled } — d בפקודות מוחלטות M L C Q Z,
// ביחידות המשטח (בקנבס — פיקסלים); feather — ריכוך הקצה, באותן יחידות. בתוך הנתיב — נראה, מחוצה לו — מוסתר.
// כאן: נתיב מבחירה, הזזה וקנה מידה של נתיב, ניקוי, ורסטריזציה של אריח (לכיסוי 0–255).

import { TILE } from './doc.js';

export const VMASK_FEATHER_MAX = 64;
const r2 = v => Math.round(v * 100) / 100;
const TOKEN = /([MLCQZ])|(-?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?)/gi;

/** d תקין לנו: רק M L C Q Z מוחלטות ומספרים, ומתחיל ב-M */
export const validPath = d => typeof d === 'string' && /^\s*M/.test(d) && /^[MLCQZ0-9eE.,+\-\s]+$/.test(d);

/** מסכה וקטורית מנוקה — או null (פגומה / ריקה) */
export function cleanVectorMask(v) {
  if (!v || typeof v !== 'object' || !validPath(v.d)) return null;
  const f = Number.isFinite(v.feather) ? Math.max(0, Math.min(VMASK_FEATHER_MAX, v.feather)) : 0;
  return { ...v, feather: f, enabled: v.enabled !== false };
}

/** כל מספר בנתיב, לפי הסדר — x ו-y לסירוגין (בפקודות מוחלטות כל מספר הוא קואורדינטה) */
function mapPath(d, fx, fy) {
  let i = 0;
  return d.replace(TOKEN, (m, cmd) => {
    if (cmd) { i = 0; return cmd.toUpperCase(); }
    const v = Number(m);
    return String(r2(i++ % 2 === 0 ? fx(v) : fy(v)));
  }).replace(/\s+/g, ' ').trim();
}

export const translatePath = (d, dx, dy) => (dx || dy ? mapPath(d, x => x + dx, y => y + dy) : d);
export const scalePath = (d, k) => (k === 1 ? d : mapPath(d, x => x * k, y => y * k));

/**
 * הנתיב של בחירה: מלבן, אליפסה (ארבע עקומות) ולאסו — רק הוספות, לא הפוכה. בחירת מטה / חיסור — null
 * (אין להם נתיב פשוט).
 */
export function pathFromSelection(sel) {
  if (!sel || sel.inverted || !sel.shapes?.length) return null;
  const parts = [];
  for (const s of sel.shapes) {
    if (s.op !== 'add') return null;
    if (s.type === 'rect') {
      parts.push(`M ${r2(s.x)} ${r2(s.y)} L ${r2(s.x + s.w)} ${r2(s.y)} L ${r2(s.x + s.w)} ${r2(s.y + s.h)} L ${r2(s.x)} ${r2(s.y + s.h)} Z`);
    } else if (s.type === 'ellipse') {
      const k = 0.5522847498, cx = s.x + s.w / 2, cy = s.y + s.h / 2, rx = s.w / 2, ry = s.h / 2;
      const p = (x, y) => `${r2(x)} ${r2(y)}`;
      parts.push(`M ${p(cx + rx, cy)} C ${p(cx + rx, cy + ry * k)} ${p(cx + rx * k, cy + ry)} ${p(cx, cy + ry)} `
        + `C ${p(cx - rx * k, cy + ry)} ${p(cx - rx, cy + ry * k)} ${p(cx - rx, cy)} `
        + `C ${p(cx - rx, cy - ry * k)} ${p(cx - rx * k, cy - ry)} ${p(cx, cy - ry)} `
        + `C ${p(cx + rx * k, cy - ry)} ${p(cx + rx, cy - ry * k)} ${p(cx + rx, cy)} Z`);
    } else if (s.type === 'poly' && s.pts?.length > 2) {
      parts.push('M ' + s.pts.map(([x, y]) => `${r2(x)} ${r2(y)}`).join(' L ') + ' Z');
    } else return null;
  }
  return parts.join(' ');
}

const paths = new Map();   // d → Path2D (מטמון קטן)
function path2d(d) {
  let p = paths.get(d);
  if (!p) {
    if (paths.size > 64) paths.clear();
    paths.set(d, p = new Path2D(d));
  }
  return p;
}

let ctx = null;
const PAD_SIZE = TILE + 2 * (Math.ceil(VMASK_FEATHER_MAX * 1.5) + 2);
/**
 * כיסוי המסכה באריח (tx, ty), כשהשכבה מוזזת ב-(dx, dy): Uint8Array של 256×256, או 'empty' / 'full' (בלי מערך —
 * המנוע משתמש במסכות הקבועות). הריכוך — טשטוש גאוסי (σ = feather / 2), על משטח מרופד כדי שגם הקצוות יטושטשו נכון.
 */
export function vmaskTile(vm, tx, ty, dx = 0, dy = 0) {
  const pad = vm.feather > 0 ? Math.ceil(vm.feather * 1.5) + 2 : 0;
  ctx ??= new OffscreenCanvas(PAD_SIZE, PAD_SIZE).getContext('2d', { willReadFrequently: true });
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.filter = 'none';
  ctx.clearRect(0, 0, PAD_SIZE, PAD_SIZE);
  ctx.filter = vm.feather > 0 ? `blur(${vm.feather / 2}px)` : 'none';
  ctx.setTransform(1, 0, 0, 1, pad - tx * TILE + dx, pad - ty * TILE + dy);
  ctx.fillStyle = '#fff';
  ctx.fill(path2d(vm.d));
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.filter = 'none';
  const data = ctx.getImageData(pad, pad, TILE, TILE).data;
  const out = new Uint8Array(TILE * TILE);
  let lo = 255, hi = 0;
  for (let i = 0; i < out.length; i++) {
    const a = data[i * 4 + 3];
    out[i] = a;
    if (a < lo) lo = a;
    if (a > hi) hi = a;
  }
  return hi === 0 ? 'empty' : lo === 255 ? 'full' : out;
}
