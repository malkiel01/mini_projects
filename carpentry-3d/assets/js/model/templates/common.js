// מה שמשותף לתבניות: רשימות הפרמטרים החוזרות (חומרים, חיבורים, דלתות),
// ספי האזהרות, ועזרים קטנים. תבנית מרכיבה מהן את הרשימה שלה.

import { material } from '../materials.js';
import { wheelHeight } from '../accessories.js';
import { part, back, crown } from '../blocks.js';
import { millSpec, millText, cncPatterns, cncId } from '../milling.js';

export const boardT = (id) => material(id).t || 18;

export const LIMITS = { shelfSpan18: 800, shelfSpan25: 1000, doorWidth: 600, heightUnanchored: 2200, glassMinDepth: 250, drawerMaxWidth: 1000 };

/** חומרי הגוף, המדפים, הגב והקנט. */
export function materialParams({ body = 'board:melamine-oak-18', shelves = true, back = true } = {}) {
  const out = [
    { key: 'bodyMaterial', label: 'חומר הגוף', type: 'material', kind: 'board', back: false, solid: false, top: false, default: body, group: 'חומרים' },
  ];
  if (shelves) out.push({ key: 'shelfMaterial', label: 'חומר המדפים', type: 'material', kind: 'board', back: false, solid: false, top: false, default: body, group: 'חומרים' });
  if (back) out.push({ key: 'backMaterial', label: 'חומר הגב', type: 'material', kind: 'board', back: true, default: 'board:back-hdf-6', group: 'חומרים' });
  out.push({ key: 'edgeMaterial', label: 'קנט', type: 'material', kind: 'edge', default: 'edge:pvc-1', group: 'חומרים' });
  return out;
}

/** דלתות: סוג, גובה, חומר, זכוכית, צירים, ידיות. `sliding` מוסיף הזזה. */
export function doorParams({ sliding = false, glass = true, height = true } = {}) {
  const options = [{ id: 'none', name: 'ללא' }, { id: 'wood', name: 'עץ' }];
  if (glass) options.push({ id: 'glass', name: 'ויטרינה' });
  if (sliding) options.push({ id: 'sliding', name: 'הזזה' });
  const show = { doorType: options.filter((o) => o.id !== 'none').map((o) => o.id) };
  const out = [{ key: 'doorType', label: 'דלתות', type: 'enum', default: 'none', group: 'דלתות', options }];
  if (height) out.push({ key: 'doorHeight', label: 'גובה הדלתות', type: 'mm', min: 0, max: 3000, default: 0, group: 'דלתות', hint: '0 = לכל הגובה', showIf: { doorType: ['wood', 'glass'] } });
  out.push({ key: 'doorMaterial', label: 'חומר הדלתות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'דלתות', showIf: show });
  if (glass) out.push({ key: 'glassType', label: 'זכוכית', type: 'material', kind: 'glass', default: 'glass:clear-4', group: 'דלתות', showIf: { doorType: ['glass'] } });
  out.push(hingeParam({ showIf: { doorType: ['wood', 'glass'] } }));
  if (sliding) {
    out.push({ key: 'slidingLeaves', label: 'כנפי הזזה', type: 'int', min: 2, max: 4, default: 2, group: 'דלתות', showIf: { doorType: ['sliding'] } });
    out.push({ key: 'slidingSystem', label: 'מערכת ההזזה', type: 'material', kind: 'hardware', role: 'sliding', default: 'hw:track-sliding', group: 'דלתות', showIf: { doorType: ['sliding'] }, hint: 'גובה הכנף, הגררות והעומס המותר נגזרים מהמערכת' });
  }
  out.push(handleParam({ showIf: show }));
  return out;
}

/** ידיות וכפתורים — מהאביזרים שבספרייה (מסך "אביזרים"). */
export function handleParam(extra = {}) {
  return { key: 'handle', label: 'ידיות', type: 'material', kind: 'hardware', role: 'handle', allowNone: true, noneLabel: 'ללא (לחיצה)', default: 'hw:handle-bar-128', group: 'דלתות', ...extra };
}
/** צירים — מהאביזרים שבספרייה. */
export function hingeParam(extra = {}) {
  return { key: 'hinge', label: 'צירים', type: 'material', kind: 'hardware', role: 'hinge', default: 'hw:hinge-110', group: 'דלתות', ...extra };
}
/** גלגלים — אופציונלי; המודל מורם בגובה הגלגל. */
export function wheelsParam(extra = {}) {
  return { key: 'wheels', label: 'גלגלים', type: 'material', kind: 'hardware', role: 'wheel', allowNone: true, noneLabel: 'ללא', default: 'none', group: 'סיומות', hint: 'ארבעה גלגלים בפינות התחתית; המודל מורם בגובהם', ...extra };
}

/**
 * גלגלים: ארבעה בפינות התחתית של הגוף (x0..x1 × z0..z1, בגובה y0), וכל
 * המודל מורם בגובה הגלגל. מחזיר את ההרמה (0 בלי גלגלים).
 */
export function addWheels(parts, hardware, v, { x0, x1, y0, z0, z1, inset = 60 }) {
  if (!v.wheels || v.wheels === 'none') return 0;
  const m = material(v.wheels);
  const lift = wheelHeight(m);
  if (!lift) return 0;
  liftAll(parts, hardware, lift);
  const ins = Math.min(inset, (x1 - x0) / 4, (z1 - z0) / 4);
  let k = 1;
  for (const x of [x0 + ins, x1 - ins]) for (const z of [z0 + ins, z1 - ins]) {
    hardware.push({ id: `wheel-${k++}`, kind: 'wheel', material: v.wheels, pos: [x, y0 + lift, z], qty: 1 });
  }
  return lift;
}
/** מרים את כל החלקים, הפרזול וצירי התנועה ב-dy. */
export function liftAll(parts, hardware, dy) {
  if (!dy) return;
  const seen = new Map();
  for (const p of parts) {
    p.box = { ...p.box, y: p.box.y + dy };
    if (p.motion && p.motion.kind === 'hinge') {
      if (!seen.has(p.motion)) seen.set(p.motion, { ...p.motion, pivot: [p.motion.pivot[0], p.motion.pivot[1] + dy, p.motion.pivot[2]] });
      p.motion = seen.get(p.motion);
    }
  }
  for (const h of hardware) if (h.pos) h.pos = [h.pos[0], h.pos[1] + dy, h.pos[2]];
}

/** מגירות: ארגז, תחתית, מסילות. */
export function drawerParams({ slide = 'hw:slide-std' } = {}) {
  return [
    { key: 'drawerBoxMaterial', label: 'ארגז המגירה', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:melamine-white-18', group: 'מגירות' },
    { key: 'drawerBottomMaterial', label: 'תחתית המגירה', type: 'material', kind: 'board', back: true, default: 'board:back-hdf-6', group: 'מגירות' },
    { key: 'slide', label: 'מסילות', type: 'material', kind: 'hardware', role: 'slide', default: slide, group: 'מגירות', hint: 'העומס המותר נבדק מול משקל המגירה + 15 ק"ג תכולה' },
  ];
}

/** החלטות החיבור — נשאלות בכל מופע. */
export function joineryParams({ shelves = true } = {}) {
  const out = [
    { key: 'sideT', label: 'עובי הדפנות', type: 'mm', min: 12, max: 40, default: 18, group: 'חיבורים' },
    { key: 'shelfT', label: 'עובי המדפים', type: 'mm', min: 12, max: 40, default: 18, group: 'חיבורים' },
    { key: 'sidesOverTop', label: 'דפנות ↔ גג', type: 'enum', default: 'sides', group: 'חיבורים',
      options: [{ id: 'sides', name: 'הדפנות עוברות' }, { id: 'top', name: 'הגג עובר' }] },
    { key: 'backMode', label: 'גב', type: 'enum', default: 'groove', group: 'חיבורים',
      options: [{ id: 'groove', name: 'בחריץ' }, { id: 'overlay', name: 'מולבש מאחור' }, { id: 'none', name: 'ללא' }] },
    { key: 'backGrooveDepth', label: 'עומק החריץ', type: 'mm', min: 4, max: 15, default: 8, group: 'חיבורים', showIf: { backMode: ['groove'] } },
    { key: 'backInset', label: 'החריץ מהקצה האחורי', type: 'mm', min: 5, max: 50, default: 10, group: 'חיבורים', showIf: { backMode: ['groove'] } },
  ];
  if (shelves) out.push({ key: 'shelvesMode', label: 'מדפים', type: 'enum', default: 'adjustable', group: 'חיבורים',
    options: [{ id: 'adjustable', name: 'מתכווננים (פינים)' }, { id: 'fixed', name: 'קבועים (בחריץ)' }] });
  out.push({ key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים',
    options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] });
  return out;
}

/** אזהרות כלליות על גוף: גובה, מפתח מדף. */
export function bodyWarnings({ H, colW, shelfT, hasShelves }, L = LIMITS) {
  const w = [];
  const spanLimit = shelfT >= 25 ? L.shelfSpan25 : L.shelfSpan18;
  if (hasShelves && colW > spanLimit) w.push(`מדף ברוחב ${Math.round(colW)} מ"מ בעובי ${shelfT} — מעבר ל-${spanLimit} המומלצים ללא תמיכה`);
  if (H > L.heightUnanchored) w.push(`גובה ${H} מ"מ — מעל ${L.heightUnanchored} מומלץ עיגון לקיר`);
  return w;
}

/** גימורי משטח: לדלתות ולדפנות חיצוניות. */
// רשימות הגימור הן פונקציות: דוגמאות החירוץ באות מהספרייה (מסך "🛠 CNC"),
// כך שדוגמה חדשה מופיעה בטופס מיד. `optionsFor` ו-`clamp` יודעים לקרוא להן.
const BASE_FINISHES = [
  { id: 'flat', name: 'חלק' },
  { id: 'fluted-fine', name: 'סטריפים מודבקים דקים (16/8)' },
  { id: 'fluted-wide', name: 'סטריפים מודבקים רחבים (40/20)' },
  { id: 'grooved', name: 'חריצים (V) אנכיים' },
];
function finishList({ glass = true, fluted = true } = {}) {
  return () => [
    ...BASE_FINISHES.filter((f) => fluted || !f.id.startsWith('fluted')),
    ...cncPatterns().map((m) => ({ id: m.id, name: `חירוץ CNC — ${m.name}` })),
    ...(glass ? [{ id: 'glass', name: 'זכוכית (ויטרינה)' }] : []),
  ];
}
export const FINISHES = finishList();
export const FINISHES_NO_GLASS = finishList({ glass: false });
/** גימורים שלא מוסיפים עובי (חזיתות מטבח ומגירות): חלק, חריצי V וחירוץ CNC. */
export const FINISHES_FLUSH = finishList({ glass: false, fluted: false });

/**
 * מחיל גימור על לוח חזיתי: סטריפים — חלקים אמיתיים על פני הלוח, בכיוון
 * `dir` ('y' אנכי) ועם התנועה של הלוח; חריצים — סימון על החלק (`surface`),
 * והתצוגה מציירת אותם; חלק/זכוכית — כלום. `normal` — הפאה הפונה החוצה:
 * '+z' (חזית), '-x' (דופן שמאל), '+x' (דופן ימין).
 */
export function applyFinish(face, finish, { material, normal = '+z', idPrefix }) {
  const out = [];
  const spec = millSpec(finish);
  if (spec) {
    // חירוץ CNC: הלוח נשאר חלק אחד; הצופה בונה את הצלעות/המסגרות, והפלט מפרט את ההוראה
    face.mill = { ...spec, pattern: cncId(finish), normal };
    face.note = [face.note, millText(face)].filter(Boolean).join(' — ');
    return out;
  }
  if (finish === 'grooved') { face.surface = 'grooved'; face.note = [face.note, 'חריצים V'].filter(Boolean).join(' — '); return out; }
  if (!finish || !finish.startsWith('fluted')) return out;
  const [sw, gap, st] = finish === 'fluted-wide' ? [40, 20, 10] : [16, 8, 8];
  const b = face.box;
  // הסטריפים אנכיים: רצים לאורך הפאה (x לחזית, z לדופן) בגובה הלוח.
  const along = normal === '+z' ? 'x' : 'z';
  const len = along === 'x' ? b.w : b.d;
  const n = Math.max(1, Math.floor((len + gap) / (sw + gap)));
  const start = (len - (n * (sw + gap) - gap)) / 2;
  for (let s = 0; s < n; s++) {
    const off = start + s * (sw + gap);
    let box;
    if (normal === '+z') box = { x: b.x + off, y: b.y, z: b.z + b.d, w: sw, h: b.h, d: st };
    else if (normal === '-x') box = { x: b.x - st, y: b.y, z: b.z + off, w: st, h: b.h, d: sw };
    else box = { x: b.x + b.w, y: b.y, z: b.z + off, w: st, h: b.h, d: sw };
    const strip = part(`${idPrefix || face.id}-strip-${s + 1}`, 'סטריפ', box, { axis: normal === '+z' ? 'z' : 'x', grain: 'y', material: material || face.material, qtyKey: `strip-${sw}x${Math.round(b.h)}`, note: face.note });
    if (face.motion) strip.motion = face.motion;
    out.push(strip);
  }
  return out;
}

/** פרמטרי חומר עם האפשרות "כמו הגוף". */
export function sameOrMaterial(key, label, group, extra = {}) {
  return { key, label, type: 'material', kind: 'board', back: false, solid: false, top: false, allowSame: true, default: 'same', group, ...extra };
}
export const resolveSame = (value, fallback) => (value === 'same' || !value ? fallback : value);

// ---- עמודות בגבהים שונים (ספרייה, ארון בגדים) ----
// ברירת המחדל: כל העמודות בגובה המוצר. עמודה שקיבלה גובה משלה בעורך
// (`columnsLayout…cols[i].height`) מקבלת גג משלה בגובה הזה. עמודות סמוכות
// באותו גובה חולקות גג אחד ("ריצה"). המחיצה בין שתי ריצות עולה עד גג
// הגבוהה מביניהן; דופן חיצונית — עד גג העמודה שלה. הגב והכרכוב — לכל ריצה.

/** גובה כל עמודה (מ"מ, מהרצפה): הנעוץ בעורך, בין `min` לגובה המוצר; אחרת גובה המוצר. */
export function columnHeights(layout, n, H, min) {
  return Array.from({ length: n }, (_, i) => {
    const h = layout?.cols?.[i]?.height;
    return Number.isFinite(h) && h > 0 ? Math.round(Math.max(min, Math.min(H, h))) : H;
  });
}
/** ריצות של עמודות סמוכות עם אותו גג: [{ a, b, top }] (a..b כולל). */
export function topRuns(tops) {
  const out = [];
  tops.forEach((t, i) => { const r = out[out.length - 1]; if (r && r.top === t) r.b = i; else out.push({ a: i, b: i, top: t }); });
  return out;
}
/** הדפנות החיצוניות בגובה העמודה הצמודה להן. לקרוא לפני גימורי הדפנות. */
export function fitSides(parts, tops, { panelT, sidesOverTop }) {
  for (const [id, t] of [['side-L', tops[0]], ['side-R', tops[tops.length - 1]]]) {
    const s = parts.find((p) => p.id === id);
    if (s) s.box = { ...s.box, h: (sidesOverTop ? t : t - panelT) - s.box.y };
  }
}
/**
 * גג לכל ריצה במקום הגג האחד, ומחיצות בגובה המתאים. `cols` — רוחבי העמודות
 * (מ-partitions). כשהגג מונח על הדפנות, גג של ריצה בקצה ממשיך מעל הדופן.
 */
export function stepTops(parts, cols, tops, { W, panelT, sidesOverTop }) {
  const i = parts.findIndex((p) => p.id === 'top');
  if (i < 0) return;
  const top = parts[i];
  const runs = topRuns(tops), last = cols.length - 1;
  const pieces = runs.map((r) => {
    let x0 = cols[r.a].x0, x1 = cols[r.b].x1;
    if (!sidesOverTop && r.a === 0) x0 = 0;
    if (!sidesOverTop && r.b === last) x1 = W;
    const id = runs.length === 1 ? 'top' : `top-${r.a + 1}${r.b > r.a ? `-${r.b + 1}` : ''}`;
    return { ...top, id, name: runs.length === 1 ? top.name : `גג עמודות ${r.a + 1}${r.b > r.a ? `–${r.b + 1}` : ''}`, qtyKey: undefined, box: { ...top.box, x: x0, y: r.top - panelT, w: x1 - x0 } };
  });
  parts.splice(i, 1, ...pieces);
  for (let c = 0; c < last; c++) {
    const p = parts.find((q) => q.id === `partition-${c + 1}`);
    if (!p) continue;
    const boundary = tops[c] !== tops[c + 1];
    const y1 = boundary ? Math.max(tops[c], tops[c + 1]) : tops[c] - panelT;
    p.box = { ...p.box, h: y1 - p.box.y };
    if (boundary) { p.edges = { ...(p.edges || {}), top: true }; p.note = p.note || 'מחיצה בין עמודות בגבהים שונים — עולה עד הגג הגבוה'; }
  }
}
/** קצות ריצה לגב מולבש ולכרכוב: מחצי המחיצה (או מקצה הארון). */
function runSpan(r, cols, sideT, W) {
  return [r.a === 0 ? 0 : cols[r.a].x0 - sideT / 2, r.b === cols.length - 1 ? W : cols[r.b].x1 + sideT / 2];
}
/** גב לכל ריצה: מולבש — עד הגג שלה; בחריץ — בין המחיצות של הריצה, עד מתחת לגג. */
export function steppedBack({ mode, outer, inner, cols, tops, t, grooveDepth, inset, material, sideT, W, panelT }) {
  const runs = topRuns(tops);
  return runs.flatMap((r, k) => {
    const prefix = `r${k + 1}-`;
    if (mode === 'overlay') {
      const [x0, x1] = runSpan(r, cols, sideT, W);
      return back({ mode, outer: { w: x1 - x0, y0: outer.y0, y1: r.top }, inner, t, grooveDepth, inset, material, prefix }).parts.map((p) => ({ ...p, box: { ...p.box, x: p.box.x + x0 } }));
    }
    return back({ mode, outer, inner: { ...inner, x0: cols[r.a].x0, x1: cols[r.b].x1, y1: r.top - panelT }, t, grooveDepth, inset, material, prefix }).parts;
  });
}
/**
 * כרכוב לכל ריצה, בגובה הגג שלה. ליד ריצה גבוהה יותר המחיצה עולה מעל הגג —
 * הכרכוב נעצר בפאה שלה; ליד ריצה נמוכה יותר הוא עובר מעל כל עובי המחיצה.
 */
export function steppedCrown({ cols, tops, h, t, d, material, sideT, W }) {
  const last = cols.length - 1;
  return topRuns(tops).flatMap((r, k) => {
    const x0 = r.a === 0 ? 0 : (tops[r.a - 1] > r.top ? cols[r.a].x0 : cols[r.a].x0 - sideT);
    const x1 = r.b === last ? W : (tops[r.b + 1] > r.top ? cols[r.b].x1 : cols[r.b].x1 + sideT);
    return crown({ w: x1 - x0, y: r.top, h, t, d, material, prefix: `r${k + 1}-` }).parts.map((p) => ({ ...p, box: { ...p.box, x: p.box.x + x0 } }));
  });
}
