// הרכבה: כמה אלמנטים (פרויקטים) יחד בסצנה אחת — מזווה ומעליו ארונית,
// תחתונים ועליונים של מטבח כשני אלמנטים, ספרייה שחלקה התחתון רחב יותר.
//
// כל פריט הוא מודל שנבנה מהתבנית שלו, ומונח במיקום (x,y,z במ"מ) ובסיבוב
// סביב הציר האנכי (0/90/180/270). הזזה וסיבוב הם על התיבות עצמן — כך
// אותם חלקים משמשים לתלת מימד, לרשימת החיתוך ולמחיר של ההרכבה כולה.
// ה-ids וקבוצות התנועה מקבלים קידומת של הפריט, כדי ששתי ספריות לא יחלקו
// "door-1". הסיבוב הוא סיבוב טהור (לא שיקוף), ולכן כיוון פתיחת הדלתות
// נשמר; רק ציר העובי וכיוון הסיבים מתחלפים ב-90°/270°.
//
// "מאוחד" (joined): ההרכבה נחשבת מוצר אחד — חלקים זהים מכל האלמנטים
// מתקבצים ברשימת החיתוך ושעות העבודה מצטברות. לא מאוחד: כל אלמנט נשאר
// פריט ייצור בפני עצמו, והפלט מפורט לפי אלמנט.

/** סיבוב נקודה (x,z) בתוך תיבת גבולות w×d סביב הציר האנכי, ב-90° נגד כיוון השעון במבט־על. */
function rotPoint(x, z, rot, w, d) {
  switch (rot) {
    case 90: return [z, w - x];
    case 180: return [w - x, d - z];
    case 270: return [d - z, x];
    default: return [x, z];
  }
}
/** תיבה אחרי סיבוב: שני הקצוות המנוגדים מסתובבים, והתיבה החדשה נמתחת ביניהם. */
function rotBox(b, rot, w, d) {
  const [x0, z0] = rotPoint(b.x, b.z, rot, w, d), [x1, z1] = rotPoint(b.x + b.w, b.z + b.d, rot, w, d);
  return { x: Math.min(x0, x1), y: b.y, z: Math.min(z0, z1), w: Math.abs(x1 - x0), h: b.h, d: Math.abs(z1 - z0) };
}
const swapAxis = (a, rot) => (rot === 90 || rot === 270 ? (a === 'x' ? 'z' : a === 'z' ? 'x' : a) : a);
function rotEdges(e, rot) {
  if (!e) return e;
  if (rot === 90) return { front: e.right, back: e.left, left: e.front, right: e.back, top: e.top, bottom: e.bottom };
  if (rot === 180) return { front: e.back, back: e.front, left: e.right, right: e.left, top: e.top, bottom: e.bottom };
  if (rot === 270) return { front: e.left, back: e.right, left: e.back, right: e.front, top: e.top, bottom: e.bottom };
  return e;
}
function rotVec(v, rot) {
  const [x, y, z] = v;
  switch (rot) {
    case 90: return [z, y, -x];
    case 180: return [-x, y, -z];
    case 270: return [-z, y, x];
    default: return v;
  }
}

/**
 * מניח מודל במקום: מחזיר חלקים ופרזול חדשים (המקור לא משתנה).
 * @param model  תוצאת build
 * @param pos    [x, y, z] — פינת הגבולות התחתונה-השמאלית-האחורית אחרי הסיבוב
 * @param rot    0 | 90 | 180 | 270
 * @param prefix קידומת ל-ids ולקבוצות תנועה (למשל "e1:")
 */
export function placeModel(model, { pos = [0, 0, 0], rot = 0, prefix = '' } = {}) {
  const { w, d } = model.bounds;
  const r = [0, 90, 180, 270].includes(rot) ? rot : 0;
  const [px, py, pz] = pos;
  const motions = new Map();   // אותו אובייקט תנועה → אותו אובייקט מסובב (הצופה מזהה קבוצה לפי group)
  const moveMotion = (m) => {
    if (!m) return m;
    if (!motions.has(m)) {
      if (m.kind === 'hinge') {
        const [x, z] = rotPoint(m.pivot[0], m.pivot[2], r, w, d);
        motions.set(m, { ...m, group: prefix + m.group, pivot: [x + px, m.pivot[1] + py, z + pz] });
      } else motions.set(m, { ...m, group: prefix + m.group, vec: rotVec(m.vec, r) });
    }
    return motions.get(m);
  };
  const parts = model.parts.map((p) => {
    const b = rotBox(p.box, r, w, d);
    return { ...p, id: prefix + p.id, box: { ...b, x: b.x + px, y: b.y + py, z: b.z + pz }, axis: swapAxis(p.axis, r), grain: swapAxis(p.grain, r), edges: rotEdges(p.edges, r), motion: moveMotion(p.motion) };
  });
  const hardware = model.hardware.map((h) => {
    const out = { ...h, id: prefix + h.id, for: h.for ? prefix + h.for : h.for };
    if (h.mount) out.mount = prefix + h.mount;
    if (h.drill && prefix) out.drill = h.drill.map((d) => ({ ...d, part: prefix + d.part }));
    if (h.pos) { const [x, z] = rotPoint(h.pos[0], h.pos[2], r, w, d); out.pos = [x + px, h.pos[1] + py, z + pz]; }
    if (h.horizontal !== undefined && (r === 90 || r === 270)) out.horizontal = !h.horizontal;
    return out;
  });
  const bw = r === 90 || r === 270 ? d : w, bd = r === 90 || r === 270 ? w : d;
  return { parts, hardware, bounds: { x: px, y: py, z: pz, w: bw, h: model.bounds.h, d: bd } };
}

/**
 * מאחד פריטים לסצנה אחת. כל פריט: { model, pos, rot, visible, name, key }.
 * מחזיר מודל "מורכב" שאפשר להעביר לצופה, לרשימת החיתוך, למחיר ולשרטוטים:
 * parts/hardware של כל הפריטים הגלויים, גבולות עוטפים (מהראשית, גם אם
 * פריט מונח במינוס — הכול מוזז כך שהמינימום 0), ו-items עם הגבולות של כל פריט.
 */
export function combine(items, { joined = false, name = 'הרכבה' } = {}) {
  const placed = items.map((it, i) => ({ ...it, key: it.key ?? i, placed: placeModel(it.model, { pos: it.pos || [0, 0, 0], rot: it.rot || 0, prefix: `e${it.key ?? i}:` }) }));
  const shown = placed.filter((it) => it.visible !== false);
  let minX = 0, minY = 0, minZ = 0, maxX = 0, maxY = 0, maxZ = 0;
  for (const it of shown) {
    const b = it.placed.bounds;
    minX = Math.min(minX, b.x); minY = Math.min(minY, b.y); minZ = Math.min(minZ, b.z);
    maxX = Math.max(maxX, b.x + b.w); maxY = Math.max(maxY, b.y + b.h); maxZ = Math.max(maxZ, b.z + b.d);
  }
  const shift = [-minX, -minY, -minZ];
  const parts = [], hardware = [], warnings = [];
  for (const it of shown) {
    for (const p of it.placed.parts) parts.push(shift.some(Boolean) ? { ...p, box: { ...p.box, x: p.box.x + shift[0], y: p.box.y + shift[1], z: p.box.z + shift[2] }, motion: shiftMotion(p.motion, shift) } : p);
    for (const h of it.placed.hardware) hardware.push(h.pos && shift.some(Boolean) ? { ...h, pos: [h.pos[0] + shift[0], h.pos[1] + shift[1], h.pos[2] + shift[2]] } : h);
    for (const wtxt of it.model.warnings || []) warnings.push(`${it.name || `אלמנט ${it.key}`}: ${wtxt}`);
  }
  // חפיפה בין אלמנטים — אזהרה, לא חסימה (הנגר אולי מתכוון לחיתוך באתר)
  for (let i = 0; i < shown.length; i++) for (let j = i + 1; j < shown.length; j++) {
    const a = shown[i].placed.bounds, b = shown[j].placed.bounds;
    if (a.x + 1 < b.x + b.w && b.x + 1 < a.x + a.w && a.y + 1 < b.y + b.h && b.y + 1 < a.y + a.h && a.z + 1 < b.z + b.d && b.z + 1 < a.z + a.d) {
      warnings.push(`${shown[i].name || `אלמנט ${shown[i].key}`} ו${shown[j].name || `אלמנט ${shown[j].key}`} חופפים`);
    }
  }
  const first = shown[0]?.model;
  return {
    parts, hardware, warnings,
    bounds: { w: maxX - minX, h: maxY - minY, d: maxZ - minZ },
    values: first ? { edgeMaterial: first.values.edgeMaterial, edgeMode: first.values.edgeMode } : {},
    template: { key: 'assembly', name, laborHours: shown.reduce((s, it) => s + (it.model.template?.laborHours || 0), 0) },
    joined,
    items: placed.map((it) => ({ key: it.key, name: it.name, visible: it.visible !== false, bounds: { ...it.placed.bounds, x: it.placed.bounds.x + shift[0], y: it.placed.bounds.y + shift[1], z: it.placed.bounds.z + shift[2] }, model: it.model, parts: it.placed.parts, hardware: it.placed.hardware })),
  };
}
const shiftCache = new WeakMap();
function shiftMotion(m, s) {
  if (!m) return m;
  if (!shiftCache.has(m)) shiftCache.set(m, m.kind === 'hinge' ? { ...m, pivot: [m.pivot[0] + s[0], m.pivot[1] + s[1], m.pivot[2] + s[2]] } : m);
  return shiftCache.get(m);
}

/**
 * הצמדה: המיקום החדש של פריט `a` ביחס לפריט `b` (שניהם עם bounds אחרי placeModel).
 * where: 'above' (מעל, מיושר לשמאל ולאחור), 'right', 'left', 'front', 'back', 'floor' (y=0).
 * מחזיר [x, y, z] חדשים ל-a (בקואורדינטות של ההרכבה, לפני הנירמול).
 */
export function snapTo(a, b, where) {
  const pos = [a.x, a.y, a.z];
  if (where === 'above') return [b.x, b.y + b.h, b.z];
  if (where === 'below') return [b.x, b.y - a.h, b.z];
  if (where === 'right') return [b.x + b.w, b.y, b.z];
  if (where === 'left') return [b.x - a.w, b.y, b.z];
  if (where === 'front') return [b.x, b.y, b.z + b.d];
  if (where === 'back') return [b.x, b.y, b.z - a.d];
  if (where === 'center') return [b.x + (b.w - a.w) / 2, a.y, b.z + (b.d - a.d) / 2];
  if (where === 'floor') return [a.x, 0, a.z];
  return pos;
}

/**
 * הצמדה בזמן גרירה על הרצפה: התזוזה מתעגלת ל-`grid` מ"מ, וקצה שמתקרב
 * לקצה של אלמנט אחר (עד `magnet` מ"מ) נצמד אליו — צמוד מבחוץ (קצה מול קצה)
 * או מיושר (שמאל לשמאל, ימין לימין). בכל ציר בנפרד: X ו-Z.
 * @param bs  גבולות כל האלמנטים (itemBounds), @param i האלמנט הנגרר
 * @param skip אינדקסים שלא נצמדים אליהם (מוסתרים)
 */
export function dragSnap(bs, i, dx, dz, { grid = 10, magnet = 40, skip = [] } = {}) {
  const b = bs[i];
  const axis = (d, lo, size) => {
    let best = Math.round(d / grid) * grid, dist = Infinity;
    const at = b[lo] + d;
    bs.forEach((o, k) => {
      if (k === i || skip.includes(k) || !o) return;
      for (const target of [o[lo] + o[size], o[lo] - b[size], o[lo], o[lo] + o[size] - b[size]]) {
        const gap = Math.abs(at - target);
        if (gap <= magnet && gap < dist) { dist = gap; best = target - b[lo]; }
      }
    });
    return best;
  };
  return [axis(dx, 'x', 'w'), axis(dz, 'z', 'd')];
}

/**
 * הצמדה בגרירה בגובה: עיגול ל-`grid` מ"מ, והתחתית נצמדת (עד `magnet` מ"מ)
 * לרצפה, לגג של אלמנט אחר (עליו), לתחתית שלו (מיושר), או כך שהגג מתיישר
 * עם הגג שלו / נוגע בתחתיתו (מתחתיו). אלמנט לא יורד מתחת לרצפה.
 */
export function dragSnapY(bs, i, dy, { grid = 10, magnet = 40, skip = [] } = {}) {
  const b = bs[i];
  let best = Math.round(dy / grid) * grid, dist = Infinity;
  const at = b.y + dy;
  const targets = [0];
  bs.forEach((o, k) => { if (k !== i && !skip.includes(k) && o) targets.push(o.y + o.h, o.y, o.y + o.h - b.h, o.y - b.h); });
  for (const t of targets) {
    const gap = Math.abs(at - t);
    if (gap <= magnet && gap < dist) { dist = gap; best = t - b.y; }
  }
  return Math.max(best, -b.y);
}
