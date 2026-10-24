// פעולות צורה: איחוד, חיסור, חיתוך והפרדה (xor). העקומות משוטחות לקווים קצרים (סטייה עד ~0.1pt), והחיתוך —
// polygon-clipping (MIT, assets/js/vendor). כל נקודה משוטחת נרשמת עם העקומה שממנה באה (reg), ו-toD בונה
// מהתוצאה חזרה את קטעי העקומות המקוריות — כך עיגול נשאר עיגול, ולא מצולע.

import clip from '../vendor/polygon-clipping.min.mjs';
import { parse, serialize, transform, num } from './path.js';

/** נקודות על עקומה מעוקבת — מספר הנקודות לפי האורך המשוער. */
function flattenCubic(p0, c1, c2, p3, out, tol, reg) {
  const curve = reg ? { c: [p0, c1, c2, p3] } : null;
  if (reg) note(reg, p0, curve, 0);
  const len = Math.hypot(c1[0] - p0[0], c1[1] - p0[1]) + Math.hypot(c2[0] - c1[0], c2[1] - c1[1]) + Math.hypot(p3[0] - c2[0], p3[1] - c2[1]);
  const n = Math.max(2, Math.min(200, Math.ceil(Math.sqrt(len / tol))));
  for (let i = 1; i <= n; i++) {
    const t = i / n, u = 1 - t;
    const pt = i === n ? [p3[0], p3[1]] : [
      u * u * u * p0[0] + 3 * u * u * t * c1[0] + 3 * u * t * t * c2[0] + t * t * t * p3[0],
      u * u * u * p0[1] + 3 * u * u * t * c1[1] + 3 * u * t * t * c2[1] + t * t * t * p3[1],
    ];
    out.push(pt);
    if (reg) note(reg, pt, curve, t);
  }
  if (curve) curve.n = n;
}

/** reg: "x,y" → [{ curve, t }] — מאיזו עקומה (ובאיזה t) באה כל נקודה. polygon-clipping מזיז נקודות בשבר
 * זעיר, אז המפתח מעוגל ל-1e-5, והחיפוש בודק גם את השכנים. */
const key = (x, y) => Math.round(x * 1e5) + ',' + Math.round(y * 1e5);
function lookup(reg, [x, y]) {
  const qx = Math.round(x * 1e5), qy = Math.round(y * 1e5);
  for (const dx of [0, -1, 1]) for (const dy of [0, -1, 1]) {
    const e = reg.get((qx + dx) + ',' + (qy + dy));
    if (e) return e;
  }
  return [];
}
function note(reg, p, curve, t) {
  const k = key(p[0], p[1]);
  const list = reg.get(k);
  if (list) { if (!list.some(e => e.curve === curve)) list.push({ curve, t }); } else reg.set(k, [{ curve, t }]);
}

/** מקטעים → טבעות סגורות של נקודות (כל תת-נתיב — טבעת; פתוח נסגר). */
export function rings(segs, tol = 0.1, reg = null) {
  const out = [];
  let ring = null, cur = [0, 0];
  const close = () => { if (ring && ring.length >= 3) out.push(ring); ring = null; };
  for (const s of segs) {
    const p = s.p;
    if (s.c === 'M') { close(); ring = [[p[0], p[1]]]; cur = [p[0], p[1]]; }
    else if (s.c === 'L') { ring?.push([p[0], p[1]]); cur = [p[0], p[1]]; }
    else if (s.c === 'C') { if (ring) flattenCubic(cur, [p[0], p[1]], [p[2], p[3]], [p[4], p[5]], ring, tol, reg); cur = [p[4], p[5]]; }
    else if (s.c === 'Q') {
      const c1 = [cur[0] + 2 / 3 * (p[0] - cur[0]), cur[1] + 2 / 3 * (p[1] - cur[1])];
      const c2 = [p[2] + 2 / 3 * (p[0] - p[2]), p[3] + 2 / 3 * (p[1] - p[3])];
      if (ring) flattenCubic(cur, c1, c2, [p[2], p[3]], ring, tol, reg);
      cur = [p[2], p[3]];
    } else if (s.c === 'Z') { if (ring) cur = ring[0]; close(); }
  }
  close();
  return out;
}

/** האזור של נתיב (במרחב שלאחר m): xor של הטבעות — נכון לחורים (אותיות, טבעות) בשני כללי המילוי. */
export function region(d, m, reg = null) {
  const segs = m ? transform(parse(d), m) : parse(d);
  const rs = rings(segs, 0.1, reg);
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

const lerp = (a, b, t) => [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
function at([p0, c1, c2, p3], t) {
  const u = 1 - t;
  return [0, 1].map(k => u * u * u * p0[k] + 3 * u * u * t * c1[k] + 3 * u * t * t * c2[k] + t * t * t * p3[k]);
}
/** חלוקה של בזייה ב-t (דה-קסטלז'ו) → [שמאל, ימין]. */
function split([p0, c1, c2, p3], t) {
  const a = lerp(p0, c1, t), b = lerp(c1, c2, t), c = lerp(c2, p3, t);
  const d = lerp(a, b, t), e = lerp(b, c, t), f = lerp(d, e, t);
  return [[p0, a, d, f], [f, e, c, p3]];
}
/** הקטע של העקומה בין t0 ל-t1 (גם הפוך — אז הקטע הפוך). */
function sub(c, t0, t1) {
  if (t0 > t1) { const [a, b, cc, d] = sub(c, t1, t0); return [d, cc, b, a]; }
  const left = t1 >= 1 ? c : split(c, t1)[0];
  return t0 <= 0 ? left : split(left, t0 / t1)[1];
}
/** ה-t של הנקודה על העקומה (הקרובה ביותר), והמרחק. */
function project(c, p) {
  let best = 0, bd = Infinity;
  for (let i = 0; i <= 64; i++) { const q = at(c, i / 64), dd = Math.hypot(q[0] - p[0], q[1] - p[1]); if (dd < bd) { bd = dd; best = i / 64; } }
  let lo = Math.max(0, best - 1 / 64), hi = Math.min(1, best + 1 / 64);
  for (let k = 0; k < 30; k++) {
    const m1 = lo + (hi - lo) / 3, m2 = hi - (hi - lo) / 3;
    const d1 = Math.hypot(...at(c, m1).map((v, i) => v - p[i])), d2 = Math.hypot(...at(c, m2).map((v, i) => v - p[i]));
    if (d1 < d2) hi = m2; else lo = m1;
  }
  const t = (lo + hi) / 2;
  return { t, dist: Math.hypot(...at(c, t).map((v, i) => v - p[i])) };
}

/** הצלע a→b: על איזו עקומה מקורית היא, ומאיזה t לאיזה — או null (קו ישר). */
function edgeCurve(reg, a, b) {
  const ea = lookup(reg, a), eb = lookup(reg, b);
  for (const x of ea) {
    const y = eb.find(e => e.curve === x.curve);
    let t1 = y?.t;
    if (y == null) {
      if (eb.length) continue;
      const pr = project(x.curve.c, b); // b — נקודת חיתוך חדשה
      if (pr.dist > 0.3) continue;
      t1 = pr.t;
    }
    if (ok(x.curve, x.t, t1, a, b)) return { curve: x.curve, t0: x.t, t1 };
  }
  if (!ea.length) {
    for (const y of eb) {
      const pr = project(y.curve.c, a);
      if (pr.dist <= 0.3 && ok(y.curve, pr.t, y.t, a, b)) return { curve: y.curve, t0: pr.t, t1: y.t };
    }
  }
  return null;
}
/** הגנה: צלע בין שתי נקודות של אותה עקומה שרחוקות בה — רק אם העקומה שם באמת צמודה לצלע. */
function ok(curve, t0, t1, a, b) {
  if (t0 === t1) return false;
  if (Math.abs(t1 - t0) <= 1.5 / (curve.n || 1)) return true;
  const m = at(curve.c, (t0 + t1) / 2), mid = lerp(a, b, 0.5);
  return Math.hypot(m[0] - mid[0], m[1] - mid[1]) < 0.5;
}

/** MultiPolygon → d (כל טבעת — M … Z). עם reg — הצלעות שעל עקומה מקורית חוזרות להיות עקומה. */
export function toD(multi, reg = null) {
  const parts = [];
  for (const poly of multi) for (const ring of poly) {
    const pts = ring.slice(0, -1); // polygon-clipping סוגר את הטבעת בנקודה הראשונה
    if (pts.length < 3) continue;
    if (!reg) { parts.push('M' + pts.map(([x, y]) => num(x) + ' ' + num(y)).join(' L') + ' Z'); continue; }
    const out = [`M${num(pts[0][0])} ${num(pts[0][1])}`];
    let run = null; // { curve, t0, t1 } — קטע עקומה שמתארך
    const flush = () => {
      if (!run) return;
      const [, c1, c2, p3] = sub(run.curve.c, run.t0, run.t1);
      out.push(`C${[...c1, ...c2, ...p3].map(num).join(' ')}`);
      run = null;
    };
    for (let i = 0; i < pts.length; i++) {
      const a = pts[i], b = pts[(i + 1) % pts.length];
      const e = edgeCurve(reg, a, b);
      if (e && run && run.curve === e.curve && run.t1 === e.t0 && (run.t1 - run.t0) * (e.t1 - e.t0) > 0) { run.t1 = e.t1; continue; }
      flush();
      if (e) run = e;
      else if (i < pts.length - 1) out.push(`L${num(b[0])} ${num(b[1])}`);
    }
    flush();
    parts.push(out.join(' ') + ' Z');
  }
  return parts.join(' ');
}

export { serialize };
