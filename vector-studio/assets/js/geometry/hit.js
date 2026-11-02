// פגיעה בלי קנבס: האם נקודה בתוך נתיב (nonzero / evenodd), ומרחק מנקודה לקו המתאר.
// לאפליקציות שאין להן Path2D (עובד רקע, PHP-JS, בדיקות) או שצריכות מרחק ולא רק כן / לא.
// העקומות משוטחות לקטעים קצרים (flatten) — סטייה של פחות מ-tol מהעקומה.

const lerp = (a, b, t) => a + (b - a) * t;

/** מקטעים → תת-נתיבים כרשימות נקודות: [{ pts: [[x, y]…], closed }]. tol — הסטייה המרבית מהעקומה. */
export function flatten(segs, tol = 0.25) {
  const out = [];
  let cur = null, x = 0, y = 0;
  const steps = (len) => Math.max(2, Math.min(64, Math.ceil(Math.sqrt(len / tol))));
  const cubic = (p0, p1, p2, p3) => {
    const len = Math.hypot(p1[0] - p0[0], p1[1] - p0[1]) + Math.hypot(p2[0] - p1[0], p2[1] - p1[1]) + Math.hypot(p3[0] - p2[0], p3[1] - p2[1]);
    const n = steps(len);
    for (let i = 1; i <= n; i++) {
      const t = i / n, u = 1 - t;
      cur.pts.push([
        u * u * u * p0[0] + 3 * u * u * t * p1[0] + 3 * u * t * t * p2[0] + t * t * t * p3[0],
        u * u * u * p0[1] + 3 * u * u * t * p1[1] + 3 * u * t * t * p2[1] + t * t * t * p3[1],
      ]);
    }
  };
  for (const s of segs) {
    const p = s.p;
    if (s.c === 'M') { cur = { pts: [[p[0], p[1]]], closed: false }; out.push(cur); x = p[0]; y = p[1]; continue; }
    if (!cur) continue;
    if (s.c === 'L') { cur.pts.push([p[0], p[1]]); x = p[0]; y = p[1]; }
    else if (s.c === 'C') { cubic([x, y], [p[0], p[1]], [p[2], p[3]], [p[4], p[5]]); x = p[4]; y = p[5]; }
    else if (s.c === 'Q') {
      cubic([x, y], [lerp(x, p[0], 2 / 3), lerp(y, p[1], 2 / 3)], [lerp(p[2], p[0], 2 / 3), lerp(p[3], p[1], 2 / 3)], [p[2], p[3]]);
      x = p[2]; y = p[3];
    } else if (s.c === 'Z') {
      cur.closed = true;
      [x, y] = cur.pts[0];
      cur = { pts: [[x, y]], closed: false }; out.push(cur); // ציור אחרי Z ממשיך מנקודת ההתחלה
    }
  }
  return out.filter(sp => sp.pts.length > 1);
}

/** האם [x, y] בתוך המילוי. כל תת-נתיב נסגר לצורך המילוי, כמו ב-SVG. rule — 'nonzero' (ברירת מחדל) או 'evenodd'. */
export function pointInPath(segs, x, y, rule = 'nonzero', tol = 0.25) {
  let wind = 0, cross = 0;
  for (const { pts } of flatten(segs, tol)) {
    for (let i = 0; i < pts.length; i++) {
      const [x0, y0] = pts[i], [x1, y1] = pts[(i + 1) % pts.length];
      if ((y0 <= y) !== (y1 <= y)) {
        const xi = x0 + (y - y0) / (y1 - y0) * (x1 - x0);
        if (xi > x) { cross++; wind += y1 > y0 ? 1 : -1; }
      }
    }
  }
  return rule === 'evenodd' ? cross % 2 === 1 : wind !== 0;
}

/** המרחק הקצר מ-[x, y] לקו המתאר (תת-נתיב סגור — כולל צלע הסגירה). נתיב ריק — Infinity. */
export function distanceToPath(segs, x, y, tol = 0.25) {
  let best = Infinity;
  for (const { pts, closed } of flatten(segs, tol)) {
    const n = closed ? pts.length : pts.length - 1;
    for (let i = 0; i < n; i++) {
      const [ax, ay] = pts[i], [bx, by] = pts[(i + 1) % pts.length];
      const dx = bx - ax, dy = by - ay, l2 = dx * dx + dy * dy;
      const t = l2 ? Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / l2)) : 0;
      best = Math.min(best, Math.hypot(x - (ax + t * dx), y - (ay + t * dy)));
    }
  }
  return best;
}

/** פגיעה כמו בעורך: בתוך המילוי (אם יש), או עד tol מקו המתאר (רוחב המתאר / 2 + סבולת המגע). */
export function hitPath(segs, x, y, { fill = true, rule = 'nonzero', strokeTol = 0 } = {}) {
  if (fill && pointInPath(segs, x, y, rule)) return true;
  return strokeTol > 0 && distanceToPath(segs, x, y) <= strokeTol;
}
