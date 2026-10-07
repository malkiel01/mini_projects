// מה שמשותף לתבניות: רשימות הפרמטרים החוזרות (חומרים, חיבורים, דלתות),
// ספי האזהרות, ועזרים קטנים. תבנית מרכיבה מהן את הרשימה שלה.

import { material } from '../materials.js';
import { wheelHeight } from '../accessories.js';
import { part } from '../blocks.js';

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
  if (sliding) out.push({ key: 'slidingLeaves', label: 'כנפי הזזה', type: 'int', min: 2, max: 4, default: 2, group: 'דלתות', showIf: { doorType: ['sliding'] } });
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
export function drawerParams() {
  return [
    { key: 'drawerBoxMaterial', label: 'ארגז המגירה', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:melamine-white-18', group: 'מגירות' },
    { key: 'drawerBottomMaterial', label: 'תחתית המגירה', type: 'material', kind: 'board', back: true, default: 'board:back-hdf-6', group: 'מגירות' },
    { key: 'slide', label: 'מסילות', type: 'enum', default: 'hw:slide-std', group: 'מגירות',
      options: [{ id: 'hw:slide-std', name: 'רגילות' }, { id: 'hw:slide-tandem', name: 'טנדם, טריקה שקטה' }] },
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
export const FINISHES = [
  { id: 'flat', name: 'חלק' },
  { id: 'fluted-fine', name: 'סטריפים דקים (16/8)' },
  { id: 'fluted-wide', name: 'סטריפים רחבים (40/20)' },
  { id: 'grooved', name: 'חריצים (V) אנכיים' },
  { id: 'glass', name: 'זכוכית (ויטרינה)' },
];
export const FINISHES_NO_GLASS = FINISHES.filter((f) => f.id !== 'glass');

/**
 * מחיל גימור על לוח חזיתי: סטריפים — חלקים אמיתיים על פני הלוח, בכיוון
 * `dir` ('y' אנכי) ועם התנועה של הלוח; חריצים — סימון על החלק (`surface`),
 * והתצוגה מציירת אותם; חלק/זכוכית — כלום. `normal` — הפאה הפונה החוצה:
 * '+z' (חזית), '-x' (דופן שמאל), '+x' (דופן ימין).
 */
export function applyFinish(face, finish, { material, normal = '+z', idPrefix }) {
  const out = [];
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
