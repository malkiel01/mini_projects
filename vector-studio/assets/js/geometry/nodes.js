// נקודות עיגון לעריכה: נתיב (מקטעי M L C Q Z) ⇄ רשימת תת-נתיבים של נקודות עם ידיות.
//
// תת-נתיב = { closed, nodes: [{ p: [x, y], i: [x, y] | null, o: [x, y] | null }] }
//   i — הידית הנכנסת (מהמקטע הקודם), o — היוצאת. null = פינה חדה בצד הזה.
// Q נהפך ל-C שקול (העלאת דרגה מדויקת), כך שעריכה לא משנה את הצורה.

const same = (a, b, eps = 1e-6) => Math.abs(a[0] - b[0]) < eps && Math.abs(a[1] - b[1]) < eps;
const lerp = (a, b, t) => [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];

export function toNodes(segs) {
  const subs = [];
  let cur = null;
  for (const s of segs) {
    const p = s.p;
    if (s.c === 'M') {
      cur = { closed: false, nodes: [{ p: [p[0], p[1]], i: null, o: null }] };
      subs.push(cur);
    } else if (!cur) {
      continue;
    } else if (s.c === 'L') {
      cur.nodes.push({ p: [p[0], p[1]], i: null, o: null });
    } else if (s.c === 'C' || s.c === 'Q') {
      const last = cur.nodes[cur.nodes.length - 1];
      let c1, c2, end;
      if (s.c === 'C') { c1 = [p[0], p[1]]; c2 = [p[2], p[3]]; end = [p[4], p[5]]; }
      else { end = [p[2], p[3]]; c1 = lerp(last.p, [p[0], p[1]], 2 / 3); c2 = lerp(end, [p[0], p[1]], 2 / 3); }
      last.o = same(c1, last.p) ? null : c1;
      cur.nodes.push({ p: end, i: same(c2, end) ? null : c2, o: null });
    } else if (s.c === 'Z') {
      cur.closed = true;
      // מקטע הסגירה כבר צויר כעקומה עד נקודת ההתחלה — מאחדים את הנקודה הכפולה
      const n = cur.nodes;
      if (n.length > 1 && same(n[n.length - 1].p, n[0].p)) {
        const last = n.pop();
        n[0].i = last.i;
      }
      // אחרי Z בלי M — תת-נתיב חדש מאותה נקודה (נדיר; SVG מתיר)
      const start = n[0].p;
      cur = { closed: false, nodes: [{ p: start.slice(), i: null, o: null }], _implicit: true };
      subs.push(cur);
    }
  }
  return subs.filter(s => !(s._implicit && s.nodes.length < 2)).map(s => { delete s._implicit; return s; });
}

function seg(a, b) {
  if (!a.o && !b.i) return { c: 'L', p: [b.p[0], b.p[1]] };
  const c1 = a.o || a.p, c2 = b.i || b.p;
  return { c: 'C', p: [c1[0], c1[1], c2[0], c2[1], b.p[0], b.p[1]] };
}

export function fromNodes(subs) {
  const out = [];
  for (const s of subs) {
    const n = s.nodes;
    if (!n.length) continue;
    out.push({ c: 'M', p: [n[0].p[0], n[0].p[1]] });
    for (let k = 1; k < n.length; k++) out.push(seg(n[k - 1], n[k]));
    if (s.closed && n.length > 1) {
      // מקטע הסגירה: עקומה מפורשת אם יש ידיות; קו ישר — Z עושה אותו לבד
      const last = n[n.length - 1];
      if (last.o || n[0].i) out.push(seg(last, n[0]));
      out.push({ c: 'Z', p: [] });
    }
  }
  return out;
}

/** המקטע ה-k בתת-נתיב כנקודות בזייה [p0 c1 c2 p3] (קו — ידיות על הנקודות). */
export function segment(sub, k) {
  const n = sub.nodes;
  const a = n[k], b = n[(k + 1) % n.length];
  // קו ישר — בקרות בשליש ובשני שליש, כדי ש-t יהיה ליניארי לאורכו
  if (!a.o && !b.i) return [a.p, lerp(a.p, b.p, 1 / 3), lerp(a.p, b.p, 2 / 3), b.p];
  return [a.p, a.o || a.p, b.i || b.p, b.p];
}

export function segmentCount(sub) {
  return sub.closed ? sub.nodes.length : sub.nodes.length - 1;
}

function bez(P, t) {
  const u = 1 - t;
  return [
    u * u * u * P[0][0] + 3 * u * u * t * P[1][0] + 3 * u * t * t * P[2][0] + t * t * t * P[3][0],
    u * u * u * P[0][1] + 3 * u * u * t * P[1][1] + 3 * u * t * t * P[2][1] + t * t * t * P[3][1],
  ];
}

/** הנקודה הקרובה ביותר על הנתיב: { sub, seg, t, point, dist } — בדגימה ושיפור מקומי. */
export function nearest(subs, pt) {
  let best = null;
  subs.forEach((sub, si) => {
    const count = segmentCount(sub);
    for (let k = 0; k < count; k++) {
      const P = segment(sub, k);
      let bt = 0, bd = Infinity;
      for (let j = 0; j <= 32; j++) {
        const t = j / 32, q = bez(P, t), d = Math.hypot(q[0] - pt[0], q[1] - pt[1]);
        if (d < bd) { bd = d; bt = t; }
      }
      // חיפוש בינארי סביב הדגימה הטובה
      let lo = Math.max(0, bt - 1 / 32), hi = Math.min(1, bt + 1 / 32);
      for (let it = 0; it < 20; it++) {
        const t1 = lo + (hi - lo) / 3, t2 = hi - (hi - lo) / 3;
        const d1 = Math.hypot(...bez(P, t1).map((v, i) => v - pt[i])), d2 = Math.hypot(...bez(P, t2).map((v, i) => v - pt[i]));
        if (d1 < d2) hi = t2; else lo = t1;
      }
      const t = (lo + hi) / 2, point = bez(P, t), dist = Math.hypot(point[0] - pt[0], point[1] - pt[1]);
      if (!best || dist < best.dist) best = { sub: si, seg: k, t, point, dist };
    }
  });
  return best;
}

/** הוספת נקודה במקטע בפרמטר t (דה-קסטלז'ו) — הצורה לא משתנה. מחזיר את האינדקס של הנקודה החדשה. */
export function splitAt(sub, k, t) {
  const n = sub.nodes;
  const a = n[k], b = n[(k + 1) % n.length];
  const line = !a.o && !b.i;
  const [p0, c1, c2, p3] = segment(sub, k);
  const p01 = lerp(p0, c1, t), p12 = lerp(c1, c2, t), p23 = lerp(c2, p3, t);
  const p012 = lerp(p01, p12, t), p123 = lerp(p12, p23, t), m = lerp(p012, p123, t);
  const node = line ? { p: m, i: null, o: null } : { p: m, i: p012, o: p123 };
  if (!line) { a.o = p01; b.i = p23; }
  n.splice(k + 1, 0, node);
  return k + 1;
}

/** נקודה חלקה ↔ פינה. פינה → ידיות בכיוון השכנים, שליש מהמרחק אליהם. */
export function toggleSmooth(sub, idx) {
  const n = sub.nodes, node = n[idx];
  if (node.i || node.o) { node.i = null; node.o = null; return; }
  const prev = idx > 0 ? n[idx - 1] : sub.closed ? n[n.length - 1] : null;
  const next = idx < n.length - 1 ? n[idx + 1] : sub.closed ? n[0] : null;
  const ref = prev && next ? [next.p[0] - prev.p[0], next.p[1] - prev.p[1]] : prev ? [node.p[0] - prev.p[0], node.p[1] - prev.p[1]] : next ? [next.p[0] - node.p[0], next.p[1] - node.p[1]] : [1, 0];
  const len = Math.hypot(...ref) || 1;
  const dir = [ref[0] / len, ref[1] / len];
  const dIn = prev ? Math.hypot(node.p[0] - prev.p[0], node.p[1] - prev.p[1]) / 3 : 0;
  const dOut = next ? Math.hypot(next.p[0] - node.p[0], next.p[1] - node.p[1]) / 3 : 0;
  if (prev) node.i = [node.p[0] - dir[0] * dIn, node.p[1] - dir[1] * dIn];
  if (next) node.o = [node.p[0] + dir[0] * dOut, node.p[1] + dir[1] * dOut];
}

/** מחיקת נקודה. תת-נתיב שנשארה בו נקודה אחת — נמחק. מחזיר false אם לא נשאר כלום. */
export function removeNode(subs, si, idx) {
  const sub = subs[si];
  sub.nodes.splice(idx, 1);
  if (sub.nodes.length < 2) subs.splice(si, 1);
  return subs.length > 0;
}

/** הזזת ידית; בנקודה חלקה (הידיות על קו אחד) — הידית השנייה מסתובבת איתה ושומרת על אורכה. */
export function moveHandle(node, which, to, keepSmooth = true) {
  const other = which === 'i' ? 'o' : 'i';
  const was = node[which], opp = node[other];
  node[which] = to;
  if (!keepSmooth || !was || !opp) return;
  const v1 = [was[0] - node.p[0], was[1] - node.p[1]], v2 = [opp[0] - node.p[0], opp[1] - node.p[1]];
  const l1 = Math.hypot(...v1), l2 = Math.hypot(...v2);
  if (!l1 || !l2) return;
  const cross = (v1[0] * v2[1] - v1[1] * v2[0]) / (l1 * l2), dot = (v1[0] * v2[0] + v1[1] * v2[1]) / (l1 * l2);
  if (Math.abs(cross) > 0.02 || dot > -0.98) return; // לא הייתה חלקה — לא נוגעים בשנייה
  const d = [to[0] - node.p[0], to[1] - node.p[1]], ld = Math.hypot(...d) || 1;
  node[other] = [node.p[0] - d[0] / ld * l2, node.p[1] - d[1] / ld * l2];
}

export function moveNode(node, dx, dy) {
  node.p = [node.p[0] + dx, node.p[1] + dy];
  if (node.i) node.i = [node.i[0] + dx, node.i[1] + dy];
  if (node.o) node.o = [node.o[0] + dx, node.o[1] + dy];
}
