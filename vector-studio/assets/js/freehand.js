// ציור חופשי: עיפרון (קו שמוחלק לעקומות בזייה) ומברשת (צורה מלאה בעובי משתנה — לפי לחץ העט, או לפי המהירות
// באצבע ובעכבר). התוצאה — נתיב רגיל (`d` של M/C/Z), אז הכול אחר כך (עט, ייצוא, פעולות צורה) עובד עליו. בלי DOM.
//
// דגימה: { x, y, p, t } — p לחץ 0–1 (רק בעט; null — אין), t זמן (ms). הקואורדינטות — במרחב הלוח.

import { num } from './geometry/path.js';

const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

/** Ramer–Douglas–Peucker: הנקודות שהקו נשען עליהן, בסטייה עד tol. */
export function simplify(pts, tol) {
  if (pts.length <= 2) return pts.slice();
  const keep = new Uint8Array(pts.length);
  keep[0] = keep[pts.length - 1] = 1;
  const stack = [[0, pts.length - 1]];
  while (stack.length) {
    const [a, b] = stack.pop();
    const A = pts[a], B = pts[b];
    const dx = B.x - A.x, dy = B.y - A.y, len = Math.hypot(dx, dy);
    let best = -1, bd = tol;
    for (let i = a + 1; i < b; i++) {
      const P = pts[i];
      const d = len ? Math.abs(dy * P.x - dx * P.y + B.x * A.y - B.y * A.x) / len : dist(P, A);
      if (d > bd) { bd = d; best = i; }
    }
    if (best > 0) { keep[best] = 1; stack.push([a, best], [best, b]); }
  }
  return pts.filter((_, i) => keep[i]);
}

/** קטמול-רום → בזייה: עקומה חלקה שעוברת בכל הנקודות. closed — טבעת. */
export function smoothD(pts, closed = false) {
  const n = pts.length;
  if (!n) return '';
  const P = (i) => (closed ? pts[(i + n) % n] : pts[Math.max(0, Math.min(n - 1, i))]);
  const f = (v) => num(v);
  let d = `M${f(pts[0].x)} ${f(pts[0].y)}`;
  const segs = closed ? n : n - 1;
  for (let i = 0; i < segs; i++) {
    const p0 = P(i - 1), p1 = P(i), p2 = P(i + 1), p3 = P(i + 2);
    const c1 = { x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6 };
    const c2 = { x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6 };
    d += ` C${f(c1.x)} ${f(c1.y)} ${f(c2.x)} ${f(c2.y)} ${f(p2.x)} ${f(p2.y)}`;
  }
  return closed ? d + ' Z' : d;
}

/** בלי נקודות כפולות (אותו מקום — אין מה לצייר ביניהן). */
function dedupe(samples, eps) {
  const out = [];
  for (const s of samples) if (!out.length || dist(s, out[out.length - 1]) > eps) out.push(s);
  return out;
}

/**
 * עיפרון: הקו המוחלק. tol — הסטייה המותרת (במרחב הלוח; בעורך ~2px של מסך). קו שנגמר ליד ההתחלה — נסגר.
 * מחזיר d, או '' אם אין מה לצייר (הקשה בלי תנועה).
 */
export function pencilD(samples, tol, closeDist = 0) {
  const pts = dedupe(samples, tol / 4);
  if (pts.length < 2) return '';
  const simple = simplify(pts, tol);
  const closed = closeDist > 0 && simple.length > 3 && dist(simple[0], simple[simple.length - 1]) <= closeDist;
  if (closed) simple.pop();
  return smoothD(simple, closed);
}

/**
 * העובי בכל דגימה: בעט — לפי הלחץ; אחרת — לפי המהירות (מהר → דק), מוחלק, ומתחדד בהתחלה ובסוף.
 * width — העובי המלא (pt); scale — pt לפיקסל מסך (כדי שהמהירות תימדד במסך, לא בזום).
 */
export function widths(samples, width, { pressure = false, scale = 1 } = {}) {
  const n = samples.length;
  const raw = samples.map((s, i) => {
    if (pressure && s.p != null) return width * (0.2 + 0.8 * Math.max(0, Math.min(1, s.p)));
    if (!i) return width;
    const prev = samples[i - 1];
    const v = dist(s, prev) / scale / Math.max(1, s.t - prev.t); // px/ms
    return width * Math.max(0.3, Math.min(1, 1.2 - v * 0.45));
  });
  // החלקה (ממוצע נע) — בלי קפיצות בעובי
  const out = raw.map((_, i) => {
    let sum = 0, c = 0;
    for (let k = Math.max(0, i - 2); k <= Math.min(n - 1, i + 2); k++) { sum += raw[k]; c++; }
    return sum / c;
  });
  // חידוד בקצוות: לאורך ~שליש מהאורך, עד פי 4 מהעובי
  let total = 0;
  const along = samples.map((s, i) => (total += i ? dist(s, samples[i - 1]) : 0));
  const taper = Math.min(total / 3, width * 4);
  if (taper > 0) {
    for (let i = 0; i < n; i++) {
      const k = Math.min(1, along[i] / taper, (total - along[i]) / taper);
      out[i] *= 0.1 + 0.9 * Math.max(0, k);
    }
  }
  return out;
}

/**
 * מברשת: קו המרכז ברווחים שווים, ומשני צדדיו נקודות במרחק חצי העובי; קצוות עגולים; וטבעת חלקה אחת סביב הכול.
 * w — העובי בכל דגימה (widths). נקודה בודדת — עיגול.
 */
export function brushD(samples, w, tol) {
  const pts = [];
  samples.forEach((s, i) => { if (!pts.length || dist(s, pts[pts.length - 1]) > tol / 4) pts.push({ ...s, w: w[i] }); });
  const maxW = Math.max(...w, 0.1);
  if (pts.length < 2) {
    const c = pts[0] || samples[0];
    if (!c) return '';
    const r = Math.max(0.5, (pts[0]?.w ?? maxW) / 2);
    return circleD(c.x, c.y, r);
  }
  // דגימה מחדש ברווחים שווים לאורך הקו, עם עובי מתאים
  const step = Math.max(tol, maxW / 4);
  const res = [{ ...pts[0] }];
  let carry = 0;
  for (let i = 1; i < pts.length; i++) {
    const a = pts[i - 1], b = pts[i], len = dist(a, b);
    let at = step - carry;
    while (at <= len) {
      const k = at / len;
      res.push({ x: a.x + (b.x - a.x) * k, y: a.y + (b.y - a.y) * k, w: a.w + (b.w - a.w) * k });
      at += step;
    }
    carry = len - (at - step);
  }
  const last = pts[pts.length - 1];
  if (dist(res[res.length - 1], last) > step / 3) res.push({ ...last });
  // החלקה קלה של המרכז (רעד של האצבע)
  const c = res.map((p, i) => {
    if (!i || i === res.length - 1) return p;
    const a = res[i - 1], b = res[i + 1];
    return { x: (a.x + 2 * p.x + b.x) / 4, y: (a.y + 2 * p.y + b.y) / 4, w: p.w };
  });
  const left = [], right = [];
  for (let i = 0; i < c.length; i++) {
    const a = c[Math.max(0, i - 1)], b = c[Math.min(c.length - 1, i + 1)];
    let dx = b.x - a.x, dy = b.y - a.y;
    const l = Math.hypot(dx, dy) || 1;
    dx /= l; dy /= l;
    const h = Math.max(0.25, c[i].w / 2);
    left.push({ x: c[i].x - dy * h, y: c[i].y + dx * h });
    right.push({ x: c[i].x + dy * h, y: c[i].y - dx * h });
  }
  // קצוות עגולים: חצי עיגול מצד ימין לשמאל בסוף, ומשמאל לימין בהתחלה
  const cap = (p, from, sign) => {
    const out = [], h = Math.max(0.25, p.w / 2);
    const a0 = Math.atan2(from.y - p.y, from.x - p.x);
    for (let k = 1; k < 6; k++) { const a = a0 + sign * Math.PI * k / 6; out.push({ x: p.x + Math.cos(a) * h, y: p.y + Math.sin(a) * h }); }
    return out;
  };
  const end = c[c.length - 1], start = c[0];
  const ring = [
    ...left,
    ...cap(end, left[left.length - 1], -1),
    ...right.reverse(),
    ...cap(start, right[right.length - 1], -1),
  ];
  return smoothD(simplify(ring, tol / 2), true);
}

function circleD(x, y, r) {
  const k = 0.5523 * r, f = num;
  return `M${f(x + r)} ${f(y)} C${f(x + r)} ${f(y + k)} ${f(x + k)} ${f(y + r)} ${f(x)} ${f(y + r)} C${f(x - k)} ${f(y + r)} ${f(x - r)} ${f(y + k)} ${f(x - r)} ${f(y)} C${f(x - r)} ${f(y - k)} ${f(x - k)} ${f(y - r)} ${f(x)} ${f(y - r)} C${f(x + k)} ${f(y - r)} ${f(x + r)} ${f(y - k)} ${f(x + r)} ${f(y)} Z`;
}
