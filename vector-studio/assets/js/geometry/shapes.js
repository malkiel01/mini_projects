// צורות חיות: פרמטרים → `d`. כל צורה יושבת בתיבה box = [x, y, w, h] (חוץ מקו — from / to),
// ולכן הזזה ושינוי גודל הם פעולה על התיבה, והצורה נשארת עריכה כפרמטרים.

import { num } from './path.js';
import { apply } from './matrix.js';

const K = 0.5522847498307936; // 4/3·(√2−1): רבע עיגול כבזייה

export const DEFAULTS = {
  rect: { radius: 0 },
  ellipse: {},
  polygon: { sides: 6 },
  star: { points: 5, inner: 0.5 },
  line: {},
};

/** תיבה עם רוחב / גובה חיוביים — גרירה הפוכה נותנת w או h שליליים. */
export function normBox([x, y, w, h]) {
  return [w < 0 ? x + w : x, h < 0 ? y + h : y, Math.abs(w), Math.abs(h)];
}

function poly(points) {
  return 'M' + points.map(([x, y]) => num(x) + ' ' + num(y)).join(' L') + ' Z';
}

/** נקודות על אליפסה החסומה בתיבה, מתחילות למעלה, עם כיוון השעון. */
function ring(box, count, radiusAt) {
  const [x, y, w, h] = box;
  const cx = x + w / 2, cy = y + h / 2;
  const pts = [];
  for (let i = 0; i < count; i++) {
    const t = -Math.PI / 2 + (i * 2 * Math.PI) / count;
    const r = radiusAt(i);
    pts.push([cx + (w / 2) * r * Math.cos(t), cy + (h / 2) * r * Math.sin(t)]);
  }
  return pts;
}

export function shapeToD(shape) {
  switch (shape.type) {
    case 'rect': {
      const [x, y, w, h] = normBox(shape.box);
      const r = Math.max(0, Math.min(Number(shape.radius) || 0, w / 2, h / 2));
      if (!r) return poly([[x, y], [x + w, y], [x + w, y + h], [x, y + h]]);
      const k = r * (1 - K);
      const n = num;
      return `M${n(x + r)} ${n(y)} L${n(x + w - r)} ${n(y)} C${n(x + w - k)} ${n(y)} ${n(x + w)} ${n(y + k)} ${n(x + w)} ${n(y + r)}`
        + ` L${n(x + w)} ${n(y + h - r)} C${n(x + w)} ${n(y + h - k)} ${n(x + w - k)} ${n(y + h)} ${n(x + w - r)} ${n(y + h)}`
        + ` L${n(x + r)} ${n(y + h)} C${n(x + k)} ${n(y + h)} ${n(x)} ${n(y + h - k)} ${n(x)} ${n(y + h - r)}`
        + ` L${n(x)} ${n(y + r)} C${n(x)} ${n(y + k)} ${n(x + k)} ${n(y)} ${n(x + r)} ${n(y)} Z`;
    }
    case 'ellipse': {
      const [x, y, w, h] = normBox(shape.box);
      const rx = w / 2, ry = h / 2, cx = x + rx, cy = y + ry, kx = rx * K, ky = ry * K;
      const n = num;
      return `M${n(cx)} ${n(y)} C${n(cx + kx)} ${n(y)} ${n(x + w)} ${n(cy - ky)} ${n(x + w)} ${n(cy)}`
        + ` C${n(x + w)} ${n(cy + ky)} ${n(cx + kx)} ${n(y + h)} ${n(cx)} ${n(y + h)}`
        + ` C${n(cx - kx)} ${n(y + h)} ${n(x)} ${n(cy + ky)} ${n(x)} ${n(cy)}`
        + ` C${n(x)} ${n(cy - ky)} ${n(cx - kx)} ${n(y)} ${n(cx)} ${n(y)} Z`;
    }
    case 'polygon': {
      const sides = Math.max(3, Math.min(64, Math.round(shape.sides) || 6));
      return poly(ring(normBox(shape.box), sides, () => 1));
    }
    case 'star': {
      const points = Math.max(3, Math.min(64, Math.round(shape.points) || 5));
      const inner = Math.max(0.05, Math.min(1, Number(shape.inner) || 0.5));
      return poly(ring(normBox(shape.box), points * 2, i => (i % 2 ? inner : 1)));
    }
    case 'line': {
      const [x1, y1] = shape.from, [x2, y2] = shape.to;
      return `M${num(x1)} ${num(y1)} L${num(x2)} ${num(y2)}`;
    }
  }
  throw new Error('צורה לא מוכרת: ' + shape.type);
}

/** צורה חדשה שגררו מנקודה לנקודה. shift — ריבוע / עיגול / קו בזוויות של 45°. */
export function shapeFromDrag(type, x0, y0, x1, y1, square = false) {
  if (type === 'line') {
    if (square) {
      const a = Math.round(Math.atan2(y1 - y0, x1 - x0) / (Math.PI / 4)) * (Math.PI / 4);
      const len = Math.hypot(x1 - x0, y1 - y0);
      x1 = x0 + len * Math.cos(a); y1 = y0 + len * Math.sin(a);
    }
    return { type, from: [x0, y0], to: [x1, y1] };
  }
  let w = x1 - x0, h = y1 - y0;
  if (square) { const s = Math.max(Math.abs(w), Math.abs(h)); w = Math.sign(w || 1) * s; h = Math.sign(h || 1) * s; }
  return { type, box: normBox([x0, y0, w, h]), ...DEFAULTS[type] };
}

/**
 * הזזה / שינוי גודל של צורה במטריצה ללא סיבוב (a, d, e, f). מחזיר צורה חדשה; null אם המטריצה מסובבת
 * — אז הצורה הופכת לנתיב רגיל.
 */
export function transformShape(shape, m) {
  if (Math.abs(m[1]) > 1e-9 || Math.abs(m[2]) > 1e-9) return null;
  if (shape.type === 'line') return { ...shape, from: apply(m, ...shape.from), to: apply(m, ...shape.to) };
  const [x, y, w, h] = shape.box;
  const [ax, ay] = apply(m, x, y), [bx, by] = apply(m, x + w, y + h);
  return { ...shape, box: normBox([ax, ay, bx - ax, by - ay]) };
}
