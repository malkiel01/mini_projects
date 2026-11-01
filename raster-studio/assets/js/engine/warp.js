// עיוות של תוכן צף: רשת (mesh) של (N+1)×(N+1) נקודות במסמך מעל המרקם הצף — כל תא מצויר כמרובע מארבע הפינות שלו.
// מעל הרשת: עיוות פינות (פרספקטיבה — הומוגרפיה מארבע פינות חופשיות) ו-Warp (משטח בזייה בי-קובי, 4×4 נקודות שליטה).
// חשבון טהור — בלי מנוע ובלי DOM. נקודה: [x, y] במסמך. סדר הפינות: שמאל-עליון, ימין-עליון, ימין-תחתון, שמאל-תחתון.

export const MESH_N = 16;

/** הרשת של מיפוי כללי f(u, v) → [x, y] (u, v ב-0..1) */
export function meshOf(f, n = MESH_N) {
  const pts = [];
  for (let j = 0; j <= n; j++) for (let i = 0; i <= n; i++) pts.push(f(i / n, j / n));
  return { n, pts };
}

/**
 * הומוגרפיה מהריבוע היחידה אל המרובע q (ארבע פינות בסדר הנ"ל) — (Heckbert, "Fundamentals of Texture Mapping").
 * מחזיר f(u, v) → [x, y]. מרובע מנוון (שלוש נקודות על קו) — נופל לאינטרפולציה בילינארית.
 */
export function homography(q) {
  const [[x0, y0], [x1, y1], [x2, y2], [x3, y3]] = q;
  const sx = x0 - x1 + x2 - x3, sy = y0 - y1 + y2 - y3;
  let a, b, c, d, e, f, g, h;
  if (Math.abs(sx) < 1e-9 && Math.abs(sy) < 1e-9) {
    a = x1 - x0; b = x2 - x1; c = x0; d = y1 - y0; e = y2 - y1; f = y0; g = 0; h = 0;
  } else {
    const dx1 = x1 - x2, dx2 = x3 - x2, dy1 = y1 - y2, dy2 = y3 - y2;
    const den = dx1 * dy2 - dx2 * dy1;
    if (Math.abs(den) < 1e-9) return bilinear(q);
    g = (sx * dy2 - dx2 * sy) / den;
    h = (dx1 * sy - sx * dy1) / den;
    a = x1 - x0 + g * x1; b = x3 - x0 + h * x3; c = x0;
    d = y1 - y0 + g * y1; e = y3 - y0 + h * y3; f = y0;
  }
  return (u, v) => {
    const w = g * u + h * v + 1;
    return [(a * u + b * v + c) / w, (d * u + e * v + f) / w];
  };
}

/** אינטרפולציה בילינארית בין ארבע הפינות */
export const bilinear = ([p0, p1, p2, p3]) => (u, v) => [
  (1 - v) * ((1 - u) * p0[0] + u * p1[0]) + v * ((1 - u) * p3[0] + u * p2[0]),
  (1 - v) * ((1 - u) * p0[1] + u * p1[1]) + v * ((1 - u) * p3[1] + u * p2[1]),
];

const B = t => [(1 - t) ** 3, 3 * t * (1 - t) ** 2, 3 * t * t * (1 - t), t ** 3];

/** משטח בזייה בי-קובי: ctrl — 16 נקודות שליטה, שורה אחר שורה (4×4) */
export function bezierPatch(ctrl) {
  return (u, v) => {
    const bu = B(u), bv = B(v);
    let x = 0, y = 0;
    for (let j = 0; j < 4; j++) for (let i = 0; i < 4; i++) {
      const w = bu[i] * bv[j], p = ctrl[j * 4 + i];
      x += w * p[0]; y += w * p[1];
    }
    return [x, y];
  };
}

/** נקודות שליטה של Warp שמתחילות מהמצב הנוכחי (מיפוי f) — משטח זהה למיפוי בפינות ובשליש / שני שליש */
export function warpFrom(f) {
  const ctrl = [];
  for (let j = 0; j < 4; j++) for (let i = 0; i < 4; i++) ctrl.push(f(i / 3, j / 3));
  return ctrl;
}

/** המלבן החוסם של הרשת (במספרים שלמים) — [x0, y0, x1, y1] */
export function meshBounds(mesh) {
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  for (const [x, y] of mesh.pts) {
    if (x < x0) x0 = x; if (x > x1) x1 = x;
    if (y < y0) y0 = y; if (y > y1) y1 = y;
  }
  return [Math.floor(x0), Math.floor(y0), Math.ceil(x1), Math.ceil(y1)];
}

/** תאי הרשת: לכל תא — הפינות [p00, p10, p01, p11] והמלבן שלו במרקם (uv) */
export function* meshCells(mesh) {
  const { n, pts } = mesh, at = (i, j) => pts[j * (n + 1) + i];
  for (let j = 0; j < n; j++) for (let i = 0; i < n; i++) {
    yield { p: [at(i, j), at(i + 1, j), at(i, j + 1), at(i + 1, j + 1)], uv: [i / n, j / n, (i + 1) / n, (j + 1) / n] };
  }
}

// ---- Liquify: מכחול שדוחף את קודקודי רשת צפופה ----

export const LIQUIFY_N = 48;

/** עותק של רשת (הנקודות — מערכים חדשים) */
export const cloneMesh = ({ n, pts }) => ({ n, pts: pts.map(p => [p[0], p[1]]) });

/** הרשת כמיפוי (u, v) → [x, y] — אינטרפולציה בילינארית בתוך התא */
export function sampleMesh({ n, pts }) {
  const at = (i, j) => pts[j * (n + 1) + i];
  return (u, v) => {
    const fx = Math.min(Math.max(u, 0), 1) * n, fy = Math.min(Math.max(v, 0), 1) * n;
    const i = Math.min(n - 1, Math.floor(fx)), j = Math.min(n - 1, Math.floor(fy));
    return bilinear([at(i, j), at(i + 1, j), at(i + 1, j + 1), at(i, j + 1)])(fx - i, fy - j);
  };
}

/** משקל המכחול: 1 במרכז, 0 בקצה — רך (smooth falloff) */
const falloff = (d2, r2) => (d2 >= r2 ? 0 : (1 - d2 / r2) ** 2);

/**
 * דחיפה: כל קודקוד בתוך המכחול (מרכז c, רדיוס r) זז ב-delta כפול המשקל והעוצמה k (0..1). משנה את הרשת במקום.
 * גרירה ארוכה — בצעדים של עד רבע רדיוס, כדי שהרשת לא "תקפוץ" מעל עצמה. מחזיר אם משהו זז.
 */
export function liquifyPush(mesh, c, delta, r, k = 1) {
  const len = Math.hypot(delta[0], delta[1]);
  if (!len || r <= 0) return false;
  const steps = Math.max(1, Math.ceil(len / (r / 4)));
  const sx = delta[0] / steps, sy = delta[1] / steps, r2 = r * r;
  let moved = false;
  for (let s = 0; s < steps; s++) {
    const cx = c[0] + sx * s, cy = c[1] + sy * s;
    for (const p of mesh.pts) {
      const w = falloff((p[0] - cx) ** 2 + (p[1] - cy) ** 2, r2) * k;
      if (w > 0) { p[0] += sx * w; p[1] += sy * w; moved = true; }
    }
  }
  return moved;
}

/** שחזור: הקודקודים בתוך המכחול חוזרים אל המקור (orig — אותה רשת לפני הדחיפות) לפי המשקל והעוצמה */
export function liquifyRestore(mesh, orig, c, r, k = 1) {
  const r2 = r * r;
  let moved = false;
  mesh.pts.forEach((p, i) => {
    const w = falloff((p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2, r2) * k;
    if (w <= 0) return;
    const o = orig.pts[i];
    if (p[0] === o[0] && p[1] === o[1]) return;
    p[0] += (o[0] - p[0]) * w; p[1] += (o[1] - p[1]) * w;
    moved = true;
  });
  return moved;
}

/** הנקודה הקרובה ביותר ל-p (במרחק עד tol) מתוך רשימה — האינדקס, או -1 */
export function nearest(list, p, tol) {
  let best = -1, bd = tol;
  list.forEach((q, i) => { const d = Math.hypot(q[0] - p[0], q[1] - p[1]); if (d <= bd) { bd = d; best = i; } });
  return best;
}
