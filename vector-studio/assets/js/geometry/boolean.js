// פעולות צורה: איחוד, חיסור, חיתוך והפרדה (xor). העקומות משוטחות לקווים קצרים (סטייה עד ~0.1pt), והחיתוך —
// polygon-clipping (MIT, assets/js/vendor). התוצאה נתיב של קווים ישרים — בלי עקומות בזייה (פער מוכר).

import clip from '../vendor/polygon-clipping.min.mjs';
import { parse, serialize, transform, num } from './path.js';

/** נקודות על עקומה מעוקבת — מספר הנקודות לפי האורך המשוער. */
function flattenCubic(p0, c1, c2, p3, out, tol) {
  const len = Math.hypot(c1[0] - p0[0], c1[1] - p0[1]) + Math.hypot(c2[0] - c1[0], c2[1] - c1[1]) + Math.hypot(p3[0] - c2[0], p3[1] - c2[1]);
  const n = Math.max(2, Math.min(200, Math.ceil(Math.sqrt(len / tol))));
  for (let i = 1; i <= n; i++) {
    const t = i / n, u = 1 - t;
    out.push([
      u * u * u * p0[0] + 3 * u * u * t * c1[0] + 3 * u * t * t * c2[0] + t * t * t * p3[0],
      u * u * u * p0[1] + 3 * u * u * t * c1[1] + 3 * u * t * t * c2[1] + t * t * t * p3[1],
    ]);
  }
}

/** מקטעים → טבעות סגורות של נקודות (כל תת-נתיב — טבעת; פתוח נסגר). */
export function rings(segs, tol = 0.1) {
  const out = [];
  let ring = null, cur = [0, 0];
  const close = () => { if (ring && ring.length >= 3) out.push(ring); ring = null; };
  for (const s of segs) {
    const p = s.p;
    if (s.c === 'M') { close(); ring = [[p[0], p[1]]]; cur = [p[0], p[1]]; }
    else if (s.c === 'L') { ring?.push([p[0], p[1]]); cur = [p[0], p[1]]; }
    else if (s.c === 'C') { if (ring) flattenCubic(cur, [p[0], p[1]], [p[2], p[3]], [p[4], p[5]], ring, tol); cur = [p[4], p[5]]; }
    else if (s.c === 'Q') {
      const c1 = [cur[0] + 2 / 3 * (p[0] - cur[0]), cur[1] + 2 / 3 * (p[1] - cur[1])];
      const c2 = [p[2] + 2 / 3 * (p[0] - p[2]), p[3] + 2 / 3 * (p[1] - p[3])];
      if (ring) flattenCubic(cur, c1, c2, [p[2], p[3]], ring, tol);
      cur = [p[2], p[3]];
    } else if (s.c === 'Z') { if (ring) cur = ring[0]; close(); }
  }
  close();
  return out;
}

/** האזור של נתיב (במרחב שלאחר m): xor של הטבעות — נכון לחורים (אותיות, טבעות) בשני כללי המילוי. */
export function region(d, m) {
  const segs = m ? transform(parse(d), m) : parse(d);
  const rs = rings(segs);
  if (!rs.length) return [];
  return rs.slice(1).reduce((acc, r) => clip.xor(acc, [[r]]), [[rs[0]]]);
}

/** kind: 'union' | 'subtract' (הראשון פחות כל השאר) | 'intersect' | 'xor'. regions — לפי סדר ההצגה (הראשון מאחור). */
export function combine(kind, regions) {
  const [first, ...rest] = regions.filter(r => r.length);
  if (!first) return [];
  if (!rest.length) return first;
  if (kind === 'union') return clip.union(first, ...rest);
  if (kind === 'subtract') return clip.difference(first, ...rest);
  if (kind === 'intersect') return clip.intersection(first, ...rest);
  return clip.xor(first, ...rest);
}

/** MultiPolygon → d (כל טבעת — M … Z). */
export function toD(multi) {
  const parts = [];
  for (const poly of multi) for (const ring of poly) {
    const pts = ring.slice(0, -1); // polygon-clipping סוגר את הטבעת בנקודה הראשונה
    if (pts.length < 3) continue;
    parts.push('M' + pts.map(([x, y]) => num(x) + ' ' + num(y)).join(' L') + ' Z');
  }
  return parts.join(' ');
}

export { serialize };
