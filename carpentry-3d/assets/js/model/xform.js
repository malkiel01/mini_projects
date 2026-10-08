// טרנספורמציה קשיחה סביב הציר האנכי — לחלקים שאינם מקבילים לצירים.
//
// חלק רגיל הוא תיבה מקבילה לצירים בקואורדינטות העולם. חלק עם `xf` הוא תיבה
// בקואורדינטות מקומיות, והעולם הוא: world = Ry(yaw)·local + (x, 0, z).
// yaw במעלות, באותו כיוון של סיבוב ההרכבה (90 = +x הופך ל--z, נגד כיוון השעון
// במבט־על). כל מה ששייך לחלק — box, axis, grain, edges, face, mill, miter,
// motion — נשאר מקומי, ולכן רשימת החיתוך, החירוץ והגרונג לא משתנים; רק מה
// שמציב את החלק בעולם (הצופה, STL, הגבולות, השרטוטים) מפעיל את xf.
//
// סיבוב ברבעים (0/90/180/270) "נאפה" לתוך התיבה — כמו קודם: תיבה מקבילה
// לצירים, צירים מוחלפים — כך שבזוויות האלה אין xf בכלל.

const RAD = Math.PI / 180;

/** נירמול זווית ל-[0, 360), עם עיגול רעשי נקודה צפה. */
export function normYaw(a) {
  let y = ((a % 360) + 360) % 360;
  if (Math.abs(y - Math.round(y)) < 1e-9) y = Math.round(y);
  return y === 360 ? 0 : y;
}
export const isQuarter = (yaw) => Math.abs(yaw / 90 - Math.round(yaw / 90)) < 1e-9;

/** נקודה [x,y,z] מקומית → עולם. */
export function applyXf(xf, [x, y, z]) {
  if (!xf) return [x, y, z];
  const c = Math.cos(xf.yaw * RAD), s = Math.sin(xf.yaw * RAD);
  return [clean(x * c + z * s + xf.x), y, clean(-x * s + z * c + xf.z)];
}
/** וקטור (כיוון בלבד, בלי הזזה). */
export function rotVecYaw([x, y, z], yaw) {
  const c = Math.cos(yaw * RAD), s = Math.sin(yaw * RAD);
  return [clean(x * c + z * s), y, clean(-x * s + z * c)];
}
const clean = (v) => (Math.abs(v - Math.round(v * 1e6) / 1e6) < 1e-9 ? Math.round(v * 1e6) / 1e6 : v);

/** a אחרי b: world = a(b(p)). */
export function compose(a, b) {
  if (!b) return a ? { ...a } : null;
  if (!a) return { ...b };
  const [x, , z] = applyXf(a, [b.x, 0, b.z]);
  return { yaw: normYaw(a.yaw + b.yaw), x, z };
}
/** סיבוב ב-yaw סביב הנקודה (cx, cz). */
export function aroundXf(yaw, cx, cz) {
  const [rx, , rz] = rotVecYaw([cx, 0, cz], yaw);
  return { yaw: normYaw(yaw), x: cx - rx, z: cz - rz };
}

/** כיוון כמחרוזת ('+x', '-z'…) אחרי סיבוב ברבע. */
export function rotDirQ(dir, yaw) {
  if (!dir || !yaw) return dir;
  const i = 'xyz'.indexOf(dir[1]), v = [0, 0, 0];
  v[i] = dir[0] === '-' ? -1 : 1;
  const r = rotVecYaw(v, yaw).map(Math.round), j = r.findIndex((c) => c !== 0);
  return (r[j] < 0 ? '-' : '+') + 'xyz'[j];
}
function rotEdgesQ(e, yaw) {
  if (!e) return e;
  const r = normYaw(yaw);
  if (r === 90) return { ...e, front: e.right, back: e.left, left: e.front, right: e.back };
  if (r === 180) return { ...e, front: e.back, back: e.front, left: e.right, right: e.left };
  if (r === 270) return { ...e, front: e.left, back: e.right, left: e.back, right: e.front };
  return e;
}
const swapAxisQ = (a, yaw) => (normYaw(yaw) % 180 === 90 ? (a === 'x' ? 'z' : a === 'z' ? 'x' : a) : a);

/** שמונת הקודקודים של חלק בעולם. */
export function partCorners(p) {
  const b = p.box, out = [];
  for (const x of [b.x, b.x + b.w]) for (const y of [b.y, b.y + b.h]) for (const z of [b.z, b.z + b.d]) out.push(applyXf(p.xf, [x, y, z]));
  return out;
}
/** התיבה העוטפת של חלק בעולם (כמו box לחלק בלי xf). */
export function partAabb(p) {
  if (!p.xf) return p.box;
  return aabbOf(partCorners(p));
}
export function aabbOf(pts) {
  let x0 = Infinity, y0 = Infinity, z0 = Infinity, x1 = -Infinity, y1 = -Infinity, z1 = -Infinity;
  for (const [x, y, z] of pts) { x0 = Math.min(x0, x); x1 = Math.max(x1, x); y0 = Math.min(y0, y); y1 = Math.max(y1, y); z0 = Math.min(z0, z); z1 = Math.max(z1, z); }
  return { x: clean(x0), y: clean(y0), z: clean(z0), w: clean(x1 - x0), h: clean(y1 - y0), d: clean(z1 - z0) };
}
/** הגבולות העוטפים של רשימת חלקים בעולם. */
export function partsAabb(parts) {
  const pts = [];
  for (const p of parts) { const b = partAabb(p); pts.push([b.x, b.y, b.z], [b.x + b.w, b.y + b.h, b.z + b.d]); }
  return pts.length ? aabbOf(pts) : { x: 0, y: 0, z: 0, w: 0, h: 0, d: 0 };
}

/** תנועה (ציר/מסילה) אחרי טרנספורמציה — ל-pivot ול-axis/vec. */
export function xfMotion(m, xf) {
  if (!m || !xf) return m;
  if (m.kind === 'hinge') return { ...m, pivot: applyXf(xf, m.pivot), ...(m.axis ? { axis: rotVecYaw(m.axis, xf.yaw) } : {}) };
  return { ...m, vec: rotVecYaw(m.vec, xf.yaw) };
}

/**
 * מפעיל טרנספורמציה על חלק. התוצאה המצטברת ברבע — נאפית (תיבה מקבילה,
 * צירים, קנטים, פאות ותנועה מסתובבים); אחרת החלק מקבל xf ונשאר מקומי.
 * `motionOf(m, total, baked)` — לשיתוף אובייקט התנועה בין חלקי אותה דלת.
 */
export function xfPart(p, xf, motionOf = (m, t, baked) => (baked ? xfMotion(m, t) : m)) {
  if (!xf) return p;
  const total = compose(xf, p.xf);
  if (!isQuarter(total.yaw)) return { ...p, xf: total, motion: motionOf(p.motion, total, false) };
  const { xf: _, ...rest } = p;
  const box = aabbOf(partCorners({ box: p.box, xf: total }));
  const y = total.yaw;
  const out = { ...rest, box: { ...p.box, ...box }, axis: swapAxisQ(p.axis, y), grain: swapAxisQ(p.grain, y), edges: rotEdgesQ(p.edges, y), motion: motionOf(p.motion, total, true) };
  if (p.face) out.face = rotDirQ(p.face, y);
  if (p.mill) out.mill = { ...p.mill, normal: rotDirQ(p.mill.normal, y) };
  if (p.miter) out.miter = p.miter.map((m) => ({ ...m, end: rotDirQ(m.end, y), short: rotDirQ(m.short, y) }));
  return out;
}

/** פרזול: מיקום (ואם צריך — xf לצופה) אחרי טרנספורמציה. */
export function xfHardware(h, xf) {
  if (!xf) return h;
  const total = compose(xf, h.xf);
  const out = { ...h };
  if (isQuarter(total.yaw)) {
    delete out.xf;
    if (h.pos) out.pos = applyXf(total, h.pos);
    if (h.horizontal !== undefined && normYaw(total.yaw) % 180 === 90) out.horizontal = !h.horizontal;
  } else out.xf = total;
  return out;
}

/** הזזה של חלק בעולם (בלי סיבוב): לחלק עם xf — ההזזה נכנסת ל-xf. */
export function shiftPart(p, [sx, sy, sz]) {
  if (p.xf) return { ...p, box: { ...p.box, y: p.box.y + sy }, xf: { ...p.xf, x: p.xf.x + sx, z: p.xf.z + sz } };
  return { ...p, box: { ...p.box, x: p.box.x + sx, y: p.box.y + sy, z: p.box.z + sz } };
}
