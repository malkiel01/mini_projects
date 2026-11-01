// שינוי צורה — החשבון (מטריצות, ידיות, סיבוב) עבר ל-ui-core (שלב 5; רסטר היה המקור): אותם שמות. dragHandle(…, true)
// עדיין "חופשי"; dragRotate(…, true) — מדרגות של 15°.
// כאן נשאר מה שרק רסטר צריך: העברת צורות בחירה ומסכות פיקסלים דרך מטריצה.
export * from '../ui-core/transform.js';
import { apply, invert } from '../ui-core/transform.js';

/** מעביר צורת בחירה דרך המטריצה (מלבן ואליפסה הופכים למצולע, אלא אם זו רק הזזה) */
export function transformShape(s, m) {
  const pureMove = m[0] === 1 && m[1] === 0 && m[2] === 0 && m[3] === 1;
  if (s.type === 'mask') return pureMove ? { ...s, x: s.x + Math.round(m[4]), y: s.y + Math.round(m[5]) } : transformMask(s, m);
  if (pureMove && s.type !== 'poly') return { ...s, x: s.x + m[4], y: s.y + m[5] };
  let pts;
  if (s.type === 'poly') pts = s.pts;
  else if (s.type === 'rect') pts = [[s.x, s.y], [s.x + s.w, s.y], [s.x + s.w, s.y + s.h], [s.x, s.y + s.h]];
  else {
    const cx = s.x + s.w / 2, cy = s.y + s.h / 2, n = 96;
    pts = Array.from({ length: n }, (_, i) => [cx + s.w / 2 * Math.cos(i / n * Math.PI * 2), cy + s.h / 2 * Math.sin(i / n * Math.PI * 2)]);
  }
  return { op: s.op, type: 'poly', pts: pts.map(([x, y]) => apply(m, x, y)) };
}

/** מסכת פיקסלים דרך מטריצה: דגימה הפוכה (בילינארית) לכל פיקסל במלבן החוסם החדש */
function transformMask(s, m) {
  const c = [[s.x, s.y], [s.x + s.w, s.y], [s.x + s.w, s.y + s.h], [s.x, s.y + s.h]].map(([x, y]) => apply(m, x, y));
  const x0 = Math.floor(Math.min(...c.map(p => p[0]))), y0 = Math.floor(Math.min(...c.map(p => p[1])));
  const w = Math.max(1, Math.ceil(Math.max(...c.map(p => p[0]))) - x0), h = Math.max(1, Math.ceil(Math.max(...c.map(p => p[1]))) - y0);
  const inv = invert(m), src = s.data, out = new Uint8Array(w * h);
  const at = (x, y) => (x < 0 || y < 0 || x >= s.w || y >= s.h ? 0 : src[y * s.w + x]);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      // מרכז הפיקסל במסמך → מקור, יחסית לפינת המסכה, ומרכזי הפיקסלים שלה
      const [u, v] = apply(inv, x0 + x + 0.5, y0 + y + 0.5);
      const fx = u - s.x - 0.5, fy = v - s.y - 0.5;
      const ix = Math.floor(fx), iy = Math.floor(fy), tx = fx - ix, ty = fy - iy;
      const top = at(ix, iy) * (1 - tx) + at(ix + 1, iy) * tx;
      const bot = at(ix, iy + 1) * (1 - tx) + at(ix + 1, iy + 1) * tx;
      out[y * w + x] = Math.round(top * (1 - ty) + bot * ty);
    }
  }
  return { op: s.op, type: 'mask', x: x0, y: y0, w, h, data: out };
}
