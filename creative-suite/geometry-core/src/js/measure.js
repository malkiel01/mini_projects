// מדידה לאורך נתיב: אורך, ונקודה + משיק במרחק s מההתחלה — לטקסט על נתיב (text-core), חצים, פיזור לאורך קו.
// העקומות נמדדות בגאוס-לז'נדר (מדויק מאוד לבזייה), ו-s → t נפתר בחיפוש בינארי על האורך המצטבר.
// y למטה (כמו במסך וב-SVG): angle ברדיאנים, 0 = ימינה, π/2 = למטה. M חדש פותח קטע חדש; Z — קו חזרה להתחלה.

// צמתים ומשקלים של גאוס-לז'נדר, 8 נקודות על [-1, 1]
const GX = [-0.9602898564975363, -0.7966664774136267, -0.5255324099163290, -0.1834346424956498,
  0.1834346424956498, 0.5255324099163290, 0.7966664774136267, 0.9602898564975363];
const GW = [0.1012285362903763, 0.2223810344533745, 0.3137066458778873, 0.3626837833783620,
  0.3626837833783620, 0.3137066458778873, 0.2223810344533745, 0.1012285362903763];

const cubicAt = (P, t) => {
  const u = 1 - t;
  return [
    u * u * u * P[0] + 3 * u * u * t * P[2] + 3 * u * t * t * P[4] + t * t * t * P[6],
    u * u * u * P[1] + 3 * u * u * t * P[3] + 3 * u * t * t * P[5] + t * t * t * P[7],
  ];
};
const cubicD = (P, t) => {
  const u = 1 - t;
  return [
    3 * u * u * (P[2] - P[0]) + 6 * u * t * (P[4] - P[2]) + 3 * t * t * (P[6] - P[4]),
    3 * u * u * (P[3] - P[1]) + 6 * u * t * (P[5] - P[3]) + 3 * t * t * (P[7] - P[5]),
  ];
};
/** אורך העקומה מ-0 עד t. */
function cubicLen(P, t = 1) {
  let s = 0;
  for (let i = 0; i < 8; i++) {
    const [dx, dy] = cubicD(P, t / 2 * (GX[i] + 1));
    s += GW[i] * Math.hypot(dx, dy);
  }
  return s * t / 2;
}

/** מקטעים → חלקים למדידה: קו { line: [x0 y0 x1 y1] } או עקומה { cubic: [8] }, כל אחד עם start (האורך לפניו) ו-len. */
function pieces(segs) {
  const out = [];
  let x = 0, y = 0, sx = 0, sy = 0, total = 0, sub = -1;
  const push = (p) => { p.start = total; p.sub = sub; total += p.len; out.push(p); };
  for (const s of segs) {
    const p = s.p;
    if (s.c === 'M') { x = sx = p[0]; y = sy = p[1]; sub++; continue; }
    if (s.c === 'L' || s.c === 'Z') {
      const [x1, y1] = s.c === 'L' ? p : [sx, sy];
      const len = Math.hypot(x1 - x, y1 - y);
      if (len > 0) push({ line: [x, y, x1, y1], len });
      x = x1; y = y1;
    } else if (s.c === 'C' || s.c === 'Q') {
      const P = s.c === 'C'
        ? [x, y, p[0], p[1], p[2], p[3], p[4], p[5]]
        : [x, y, x + 2 / 3 * (p[0] - x), y + 2 / 3 * (p[1] - y), p[2] + 2 / 3 * (p[0] - p[2]), p[3] + 2 / 3 * (p[1] - p[3]), p[2], p[3]];
      const len = cubicLen(P);
      if (len > 0) push({ cubic: P, len });
      x = P[6]; y = P[7];
    }
  }
  return { list: out, length: total };
}

function at(pc, local) {
  if (pc.line) {
    const [x0, y0, x1, y1] = pc.line, t = local / pc.len;
    return { x: x0 + (x1 - x0) * t, y: y0 + (y1 - y0) * t, angle: Math.atan2(y1 - y0, x1 - x0) };
  }
  // s → t: חיפוש בינארי על האורך (מונוטוני), ואז נקודה ומשיק
  const P = pc.cubic;
  let lo = 0, hi = 1;
  for (let i = 0; i < 30; i++) { const mid = (lo + hi) / 2; if (cubicLen(P, mid) < local) lo = mid; else hi = mid; }
  const t = (lo + hi) / 2, [x, y] = cubicAt(P, t);
  let [dx, dy] = cubicD(P, t);
  if (Math.hypot(dx, dy) < 1e-12) [dx, dy] = [P[6] - P[0], P[7] - P[1]]; // ידית על הנקודה — כיוון המיתר
  return { x, y, angle: Math.atan2(dy, dx) };
}

/**
 * מדידה של נתיב אחד, לשאלות חוזרות (טקסט על נתיב שואל אות אחרי אות):
 *   const m = measure(segs);  m.length;  m.at(s) → { x, y, angle, sub } | null
 * s מחוץ ל-[0, length] — null (הקורא מחליט אם לחתוך או להמשיך בקו ישר). נתיב בלי אורך — length 0, ו-at → null.
 */
export function measure(segs) {
  const { list, length } = pieces(segs);
  return {
    length,
    at(s) {
      if (!list.length || !(s >= 0) || s > length + 1e-9) return null;
      let lo = 0, hi = list.length - 1;
      while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (list[mid].start <= s) lo = mid; else hi = mid - 1; }
      const pc = list[lo];
      return { ...at(pc, Math.min(pc.len, s - pc.start)), sub: pc.sub };
    },
  };
}

/** אורך הנתיב (כל התת-נתיבים, כולל צלעות הסגירה של Z). */
export const pathLength = (segs) => pieces(segs).length;

/** נקודה ומשיק במרחק s: { x, y, angle, sub } | null. לשאלה אחת; לכמה — measure(). */
export const pointAtLength = (segs, s) => measure(segs).at(s);
