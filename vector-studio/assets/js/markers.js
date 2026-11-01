// קצוות של מתאר — חץ, עיגול או פס בתחילת הנתיב ובסופו (stroke.start / stroke.end, גרסה 2 של וקטורי, mbe-format סעיף 3).
// הקצה הוא צורה מלאה, בצבע של המתאר, בגודל יחסי לעובי. בלי DOM — אותה גאומטריה לקנבס, ל-SVG ול-PDF.

import { parse } from './geometry/path.js';

export const MARKERS = ['none', 'arrow', 'circle', 'bar'];

const r = (v) => Math.round(v * 1000) / 1000;

/**
 * הנקודות והכיוונים של קצות הנתיב: { start: { p, dir }, end: { p, dir } } — dir יוצא החוצה מהנתיב.
 * נתיב סגור (Z) — אין לו קצוות: null.
 */
export function ends(d) {
  let segs;
  try { segs = parse(d); } catch { return null; }
  if (!segs.length || segs.some(s => s.c === 'Z')) return null;
  const pts = [];      // [נקודה, נקודת בקרה קודמת] לכל מקטע, לפי הסדר
  let cur = null, first = null, firstOut = null;
  for (const s of segs) {
    const p = s.p;
    if (s.c === 'M') { cur = [p[0], p[1]]; if (!first) first = cur; continue; }
    if (!cur) continue;
    let to, c1, c2;
    if (s.c === 'L') { to = [p[0], p[1]]; c1 = to; c2 = cur; }
    else if (s.c === 'C') { c1 = [p[0], p[1]]; c2 = [p[2], p[3]]; to = [p[4], p[5]]; }
    else if (s.c === 'Q') { c1 = c2 = [p[0], p[1]]; to = [p[2], p[3]]; }
    else continue;
    if (!firstOut) firstOut = [cur, c1, to];
    pts.push([cur, c2, to]);
    cur = to;
  }
  if (!firstOut || !pts.length) return null;
  const unit = (from, to) => { const dx = to[0] - from[0], dy = to[1] - from[1], l = Math.hypot(dx, dy); return l > 1e-9 ? [dx / l, dy / l] : null; };
  // כיוון יציאה: הנקודה הקרובה הראשונה שאינה זהה לקצה (בקרה, ואם היא על הקצה — הנקודה הבאה)
  const [s0, s1, s2] = firstOut;
  const startDir = unit(s1, s0) || unit(s2, s0);
  const [e0, e1, e2] = pts[pts.length - 1];
  const endDir = unit(e1, e2) || unit(e0, e2);
  if (!startDir || !endDir) return null;
  return { start: { p: s0, dir: startDir }, end: { p: e2, dir: endDir } };
}

/** צורת קצה אחד: d בקואורדינטות של הנתיב. kind: 'arrow' | 'circle' | 'bar'; w — עובי המתאר. */
export function markerD(kind, { p, dir }, w) {
  const k = Math.max(w, 0.5);
  const [x, y] = p, [ux, uy] = dir, [nx, ny] = [-uy, ux];
  const at = (a, b) => `${r(x + ux * a + nx * b)} ${r(y + uy * a + ny * b)}`;
  if (kind === 'arrow') {
    const len = 4 * k, half = 2.25 * k;
    return `M${at(len * 0.75, 0)} L${at(-len * 0.25, half)} L${at(0, 0)} L${at(-len * 0.25, -half)} Z`;
  }
  if (kind === 'bar') {
    const half = 2.5 * k, t = k / 2;
    return `M${at(-t, -half)} L${at(t, -half)} L${at(t, half)} L${at(-t, half)} Z`;
  }
  if (kind === 'circle') {
    const R = 1.6 * k, c = 0.5523 * R;   // ארבע קשתות בזייה
    const P = (a, b) => `${r(x + a)} ${r(y + b)}`;
    return `M${P(R, 0)} C${P(R, c)} ${P(c, R)} ${P(0, R)} C${P(-c, R)} ${P(-R, c)} ${P(-R, 0)} C${P(-R, -c)} ${P(-c, -R)} ${P(0, -R)} C${P(c, -R)} ${P(R, -c)} ${P(R, 0)} Z`;
  }
  return null;
}

/** כל הקצות של מתאר: [d] (0–2). בלי קצוות, או נתיב סגור — []. */
export function markerPaths(d, stroke) {
  if (!stroke || ((!stroke.start || stroke.start === 'none') && (!stroke.end || stroke.end === 'none'))) return [];
  const e = ends(d);
  if (!e) return [];
  const w = stroke.width ?? 1;
  return [stroke.start && markerD(stroke.start, e.start, w), stroke.end && markerD(stroke.end, e.end, w)].filter(Boolean);
}
