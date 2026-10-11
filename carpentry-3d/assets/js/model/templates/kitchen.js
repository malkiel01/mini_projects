// תבנית: מטבח — קו ישר או בצורת ר'.
//
// שרשרת ארונות תחתונים (כל אחד תיבה עם דלתות או מגירות), משטח עבודה, סוקל
// משותף, ואופציונלית ארונות עליונים. בצורת ר' יש קיר שני, ובפינה **ארון
// פינתי**: L (שתי דלתות או דלת מתקפלת, ולפי בחירה קרוסלה), או עיוור (ארון
// רחב שחלקו מאחורי הקיר השני). מספר הארונות בכל קיר קובע את רוחבם.
//
// הצירים: X לאורך הקיר האחורי, Z לאורך הקיר השני (קדימה לחדר). הפינה
// בראשית. הארונות של הקיר השני נבנים כאילו היו על הקיר האחורי ואז
// "מסובבים" בהחלפת X↔Z (swapXZ) — כך אותה פונקציה בונה את שני הקירות.
// "פינה מימין" היא שיקוף של "פינה משמאל" (mirrorX).
//
// כל ארון הוא "עמודה" עם קומות (docs/elements-spec.md, שלב 6): values.units —
// רשימה שטוחה, ולכל ארון `row` (baseA / baseB / upperA / upperB). לכל קומה חזית
// (פתוח / דלת) ותוכן (מדפים / מגירות / ריק) — למשל מגירה למעלה ודלת עם מדף מתחת.
// חומר, גימור, ידית וציר של חזית הם "same" = כמו המטבח (הפרמטרים הכלליים), אלא אם
// נבחר אחרת לקומה. ארון הפינה — הגדרה של המוצר. בלי units — מומר מההגדרות הישנות
// בכל בנייה, זהה לבנייה הישנה (legacy/kitchen-v1.js).

import { carcass, shelves, back, door, drawer, part } from '../blocks.js';
import { materialParams, drawerParams, joineryParams, boardT, LIMITS, hingeParam, handleParam, FINISHES_FLUSH, applyFinish } from './common.js';
import { resolveShares, sectionLayout, cellsOf } from '../layout.js';

const N = (n) => Array.from({ length: n }, (_, i) => i + 1);
const same = (val, dflt) => (!val || val === 'same' ? dflt : val);
/** שורות הארונות הפעילות: מפתח, מספר הארונות, והתחילית של מזהי הרכיבים. */
function rowsOf(v) {
  const isL = v.shape === 'L', up = v.uppers === 'yes';
  const rows = [{ key: 'baseA', n: v.cabinets, prefix: isL ? 'א' : 'ת', title: isL ? 'תחתונים — הקיר האחורי' : 'ארונות תחתונים' }];
  if (isL) rows.push({ key: 'baseB', n: v.cabinetsB, prefix: 'ב', title: 'תחתונים — הקיר השני' });
  if (up) rows.push({ key: 'upperA', n: v.cabinets, prefix: 'ע', title: isL ? 'עליונים — הקיר האחורי' : 'ארונות עליונים' });
  if (up && isL) rows.push({ key: 'upperB', n: v.cabinetsB, prefix: 'עב', title: 'עליונים — הקיר השני' });
  return rows;
}
const PREFIX_ROW = { ת: 'baseA', א: 'baseA', ב: 'baseB', ע: 'upperA', עב: 'upperB' };
const SAME_FRONT = { leaves: 'auto', hingeSide: 'auto', material: 'same', finish: 'same', handle: 'same', hinge: 'same' };

/** X↔Z: תיבה שנבנתה לאורך הקיר האחורי עוברת לקיר השני. */
function swapXZ(items) {
  for (const p of items.parts) {
    const b = p.box;
    p.box = { x: b.z, y: b.y, z: b.x, w: b.d, h: b.h, d: b.w };
    p.axis = p.axis === 'x' ? 'z' : p.axis === 'z' ? 'x' : p.axis;
    p.grain = p.grain === 'x' ? 'z' : p.grain === 'z' ? 'x' : p.grain;
    const e = p.edges || {};
    p.edges = { front: e.right, back: e.left, left: e.back, right: e.front, top: e.top, bottom: e.bottom };
    // תנועה: החלפת X↔Z היא שיקוף, ולכן כיוון הסיבוב מתהפך.
    if (p.motion) p.motion = swapMotion(p.motion);
  }
  for (const h of items.hardware) if (h.pos) h.pos = [h.pos[2], h.pos[1], h.pos[0]];
  return items;
}
const swapCache = new Map();
function swapMotion(m) {
  if (!swapCache.has(m)) swapCache.set(m, m.kind === 'hinge'
    ? { ...m, pivot: [m.pivot[2], m.pivot[1], m.pivot[0]], angle: -m.angle }
    : { ...m, vec: [m.vec[2], m.vec[1], m.vec[0]] });
  return swapCache.get(m);
}

/** שיקוף ב-X סביב Lx: פינה מימין. */
function mirrorX(items, Lx) {
  for (const p of items.parts) {
    p.box = { ...p.box, x: Lx - p.box.x - p.box.w };
    const e = p.edges || {};
    p.edges = { ...e, left: e.right, right: e.left };
    if (p.motion) p.motion = mirrorMotion(p.motion, Lx);
  }
  for (const h of items.hardware) if (h.pos) h.pos = [Lx - h.pos[0], h.pos[1], h.pos[2]];
  return items;
}
const mirrorCache = new Map();
function mirrorMotion(m, Lx) {
  if (!mirrorCache.has(m)) mirrorCache.set(m, m.kind === 'hinge'
    ? { ...m, pivot: [Lx - m.pivot[0], m.pivot[1], m.pivot[2]], angle: -m.angle }
    : { ...m, vec: [-m.vec[0], m.vec[1], m.vec[2]] });
  return mirrorCache.get(m);
}

export default {
  key: 'kitchen',
  name: 'מטבח',
  description: 'קו ישר או בצורת ר׳ עם ארון פינתי; תחתונים עם דלתות ומגירות, משטח, עליונים.',
  laborHours: 24,

  params: [
    { key: 'shape', label: 'צורה', type: 'enum', default: 'line', group: 'מידות', options: [{ id: 'line', name: 'קו ישר' }, { id: 'L', name: "ר' (שני קירות)" }] },
    { key: 'length', label: 'אורך הקיר האחורי', type: 'mm', min: 600, max: 8000, default: 3000, group: 'מידות' },
    { key: 'lengthB', label: 'אורך הקיר השני', type: 'mm', min: 900, max: 8000, default: 2400, group: 'מידות', showIf: { shape: ['L'] } },
    { key: 'cornerSide', label: 'הפינה', type: 'enum', default: 'left', group: 'מידות', showIf: { shape: ['L'] }, options: [{ id: 'left', name: 'משמאל' }, { id: 'right', name: 'מימין' }] },
    { key: 'baseH', label: 'גובה ארון תחתון (בלי סוקל ומשטח)', type: 'mm', min: 600, max: 900, default: 720, group: 'מידות' },
    { key: 'baseD', label: 'עומק ארון תחתון', type: 'mm', min: 400, max: 650, default: 560, group: 'מידות' },
    { key: 'plinthH', label: 'סוקל', type: 'mm', min: 60, max: 200, default: 100, group: 'מידות' },

    { key: 'cornerType', label: 'ארון פינתי', type: 'enum', default: 'l-doors', group: 'פינה', showIf: { shape: ['L'] },
      options: [{ id: 'l-doors', name: 'L — שתי דלתות' }, { id: 'l-bifold', name: 'L — דלת מתקפלת' }, { id: 'l-carousel', name: 'L — דלת מתקפלת + קרוסלה' }, { id: 'blind', name: 'עיוור (ארון רחב, חלקו מוסתר)' }] },
    { key: 'cornerSize', label: 'מידת הפינה (כל צד)', type: 'mm', min: 800, max: 1200, default: 1000, group: 'פינה', showIf: { shape: ['L'] }, hint: 'מהפינה עד הארון הבא, בשני הקירות' },
    { key: 'cornerUpperSize', label: 'מידת הפינה בעליונים', type: 'mm', min: 500, max: 900, default: 650, group: 'פינה', showIf: { shape: ['L'], uppers: ['yes'] } },

    { key: 'cabinets', label: 'ארונות תחתונים (קיר אחורי)', type: 'int', min: 1, max: 12, default: 4, group: 'חלוקה' },
    { key: 'cabinetsB', label: 'ארונות תחתונים (קיר שני)', type: 'int', min: 1, max: 12, default: 2, group: 'חלוקה', showIf: { shape: ['L'] } },
    // כל ארון וכל קומה בו — בכרטיס (units-ui). ריק — נגזר מההגדרות הישנות בכל בנייה.
    { key: 'units', label: 'הארונות', type: 'json', default: null, group: 'חלוקה', editor: 'units' },
    { legacy: true, key: 'drawerCabinets', label: 'מהם ארונות מגירות (קיר אחורי)', type: 'int', min: 0, max: 12, default: 1, group: 'חלוקה', hint: 'הראשונים אחרי הפינה' },
    { legacy: true, key: 'drawerCabinetsB', label: 'ארונות מגירות (קיר שני)', type: 'int', min: 0, max: 12, default: 0, group: 'חלוקה' },
    { legacy: true, key: 'drawersPerCabinet', label: 'מגירות בארון מגירות', type: 'int', min: 2, max: 5, default: 3, group: 'חלוקה' },
    { key: 'shelvesPerCabinet', label: 'מדפים בארון הפינה', type: 'int', min: 0, max: 3, default: 1, group: 'פינה', showIf: { shape: ['L'] }, hint: 'בפרויקט שעוד לא נערך בכרטיסים — גם ברירת המחדל לכל ארון דלתות' },
    { legacy: true, key: 'columnsLayout', label: 'עריכת הארונות', type: 'json', default: null, group: 'חלוקה', editor: 'columns' },

    { key: 'uppers', label: 'ארונות עליונים', type: 'enum', default: 'yes', group: 'עליונים', options: [{ id: 'yes', name: 'כן' }, { id: 'no', name: 'לא' }] },
    { key: 'upperH', label: 'גובה עליון', type: 'mm', min: 400, max: 1000, default: 720, group: 'עליונים', showIf: { uppers: ['yes'] } },
    { key: 'upperD', label: 'עומק עליון', type: 'mm', min: 250, max: 400, default: 320, group: 'עליונים', showIf: { uppers: ['yes'] } },
    { key: 'upperGap', label: 'מרווח מהמשטח לעליון', type: 'mm', min: 400, max: 800, default: 550, group: 'עליונים', showIf: { uppers: ['yes'] } },

    ...materialParams({ body: 'board:melamine-white-18' }),
    { key: 'frontMaterial', label: 'חזיתות (דלתות ומגירות)', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'חומרים' },
    { key: 'frontFinish', label: 'גימור החזיתות', type: 'enum', default: 'flat', group: 'חומרים', options: FINISHES_FLUSH, hint: 'חירוץ CNC — בלוח אחד, בלי להוסיף עובי' },
    { key: 'topMaterial', label: 'משטח עבודה', type: 'material', kind: 'board', top: true, default: 'board:countertop-38', group: 'חומרים' },
    { key: 'topOverhang', label: 'הבלטת המשטח קדימה', type: 'mm', min: 0, max: 60, default: 40, group: 'חומרים' },
    ...drawerParams({ slide: 'hw:slide-tandem' }),   // מגירות מטבח רחבות וכבדות: טנדם 40 ק"ג כברירת מחדל
    hingeParam({ group: 'חומרים' }), handleParam({ group: 'חומרים' }),
  ],
  // מדפים קבועים/מתכווננים — לכל קומה; עובי הדפנות והמדפים — מהחומר (שלב 5)
  joinery: joineryParams({ autoT: true }).map((p) => (p.key === 'shelvesMode' ? { ...p, legacy: true } : p)),
  limits: LIMITS,

  /** מה כרטיס הארון מציע במטבח (units-ui.js). */
  unitsSpec: {
    columnBody: false,
    fronts: { none: 'פתוח', wood: 'דלת' },
    interiors: { shelves: 'מדפים', drawers: 'מגירות', empty: 'ריק' },
    same: 'כמו המטבח',
    finishes: FINISHES_FLUSH,
    unitHeight: false, addColumns: false,
    label: (u, i, units) => { const k = units.slice(0, i + 1).filter((x) => x.row === u.row).length; const isL = units.some((x) => x.row === 'baseB'); return `ארון ${u.row === 'baseA' ? (isL ? 'א' : 'ת') : u.row === 'baseB' ? 'ב' : u.row === 'upperA' ? 'ע' : 'עב'}${k}`; },
    rowTitle: (u, units) => { const isL = units.some((x) => x.row === 'baseB'); return { baseA: isL ? 'תחתונים — הקיר האחורי' : 'ארונות תחתונים', baseB: 'תחתונים — הקיר השני', upperA: isL ? 'עליונים — הקיר האחורי' : 'ארונות עליונים', upperB: 'עליונים — הקיר השני' }[u.row]; },
    group: (u) => u.row,
  },

  /** עובי ששווה לחומר → נגזר (אותה בנייה; מעכשיו משתנה עם החומר). */
  migrate(v) {
    for (const [k, m] of [['sideT', v.bodyMaterial], ['shelfT', v.shelfMaterial ?? v.bodyMaterial]]) if (v[k] != null && m && Number(v[k]) === boardT(m)) v[k] = null;
    return v;
  },

  /** מזהה רכיב → הארון והקומה שלו (לכפתור "⚙️ הגדרות" בכרטיס הרכיב). */
  locate(id, units) {
    const m = /^(עב|ע|ת|א|ב)(\d+)-(?:(?:f|split)(\d+)-)?/.exec(id);
    if (!m || !Array.isArray(units)) return null;
    const row = PREFIX_ROW[m[1]];
    const idx = units.map((u, i) => [u, i]).filter(([u]) => u.row === row)[+m[2] - 1]?.[1];
    if (idx === undefined) return null;
    const j = m[3] ? +m[3] - (/-split/.test(id) ? 0 : 1) : 0;
    return { unit: idx, floor: Math.min(j, units[idx].floors.length - 1) };
  },

  /**
   * לעורך החלוקה: קבוצה לכל שורת ארונות — תחתונים ועליונים בכל קיר. לכל ארון
   * רוחב; בתחתונים גם סוג (דלתות עם מדפים / מגירות) ולפי הסוג מספר מדפים או
   * מגירות וגובה כל תא; בעליונים מדפים.
   */
  columnSpace(v) {
    const shelfT = v.shelfT ?? 18, isL = v.shape === 'L';
    const C = isL ? v.cornerSize ?? 1000 : 0, Cu = isL && v.uppers === 'yes' ? v.cornerUpperSize ?? 650 : 0;
    const baseInner = (v.baseH ?? 720) - 2 * shelfT, upperInner = (v.upperH ?? 720) - 2 * shelfT;
    const kinds = [{ id: 'doors', name: 'דלתות ומדפים', cellsOf: 'shelves', defaultCount: v.shelvesPerCabinet ?? 1 }, { id: 'drawers', name: 'מגירות', cellsOf: 'drawers', defaultCount: v.drawersPerCabinet ?? 3 }];
    const baseRun = (key, title, total, n, drawerN, prefix) => ({
      key, title, total, sizeLabel: 'רוחב', allLabel: 'הארונות', modes: { next: 'מהארון הבא', prev: 'מהארון הקודם' },
      items: Array.from({ length: n }, (_, i) => ({ label: `ארון ${prefix}${i + 1}`, innerH: baseInner, shelfT, kinds, kind: i < drawerN ? 'drawers' : 'doors', countMax: 5, editable: true, note: '' })),
    });
    const upperRun = (key, title, total, n, prefix) => ({
      key, title, total, sizeLabel: 'רוחב', allLabel: 'הארונות', modes: { next: 'מהארון הבא', prev: 'מהארון הקודם' },
      items: Array.from({ length: n }, (_, i) => ({ label: `ארון ${prefix}${i + 1}`, innerH: upperInner, shelfT, cellsOf: 'shelves', defaultCount: Math.max(1, v.shelvesPerCabinet ?? 1), countMax: 4, editable: true, note: '' })),
    });
    const sections = [baseRun('baseA', isL ? 'תחתונים — הקיר האחורי' : 'ארונות תחתונים', (v.length ?? 3000) - C, v.cabinets ?? 1, v.drawerCabinets ?? 0, isL ? 'א' : 'ת')];
    if (isL) sections.push(baseRun('baseB', 'תחתונים — הקיר השני', (v.lengthB ?? 2400) - C, v.cabinetsB ?? 1, v.drawerCabinetsB ?? 0, 'ב'));
    if (v.uppers === 'yes') {
      sections.push(upperRun('upperA', isL ? 'עליונים — הקיר האחורי' : 'ארונות עליונים', (v.length ?? 3000) - Cu, v.cabinets ?? 1, 'ע'));
      if (isL) sections.push(upperRun('upperB', 'עליונים — הקיר השני', (v.lengthB ?? 2400) - Cu, v.cabinetsB ?? 1, 'עב'));
    }
    return { sections };
  },

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const sideT = v.sideT, shelfT = v.shelfT;
    const backT = v.backMode === 'none' ? 0 : boardT(v.backMaterial);
    const bodyZ = v.backMode === 'overlay' ? backT : 0;
    const frontT = boardT(v.frontMaterial);
    const topT = boardT(v.topMaterial);
    const adjustable = v.shelvesMode === 'adjustable';
    const handle = v.handle === 'none' ? null : v.handle;
    const orNone = (hd) => (!hd || hd === 'none' ? null : hd);
    let maxFrontT = frontT;
    const isL = v.shape === 'L';
    const Lx = v.length, Lz = isL ? v.lengthB : v.baseD;
    const C = isL ? v.cornerSize : 0;          // הפינה בתחתונים
    const D = v.baseD;
    const baseTop = v.plinthH + v.baseH;
    const oh = v.topOverhang;
    const add = (items) => { parts.push(...items.parts); hardware.push(...items.hardware); };

    /**
     * תיבה רגילה לאורך X: x מהפינה, רוחב w. מחזירה { parts, hardware } בלי
     * להוסיף — כדי שאפשר יהיה להחליף צירים לפני ההוספה.
     */
    const box = (prefix, x, w, y, h, d, unit, { hingeDefault = 'left' } = {}) => {
      const out = { parts: [], hardware: [], floors: [] };
      const body = carcass({ w, d: d - bodyZ, z: bodyZ, y0: y, bottomY: y + shelfT, topY: y + h, sideT, panelT: shelfT, sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial });
      const shift = (p) => ({ ...p, id: `${prefix}-${p.id}`, box: { ...p.box, x: p.box.x + x }, note: p.note || prefix });
      out.parts.push(...body.parts.map(shift));
      const inner = { ...body.inner, x0: body.inner.x0 + x, x1: body.inner.x1 + x };
      const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;
      const sideY = body.parts.find((p) => p.id === 'side-L').box.y;
      out.parts.push(...back({ mode: v.backMode, outer: { w, y0: y, y1: y + h }, inner, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial }).parts
        .map((p) => ({ ...p, id: `${prefix}-${p.id}`, box: { ...p.box, x: v.backMode === 'overlay' ? p.box.x + x : p.box.x } })));
      // הקומות: מלמטה למעלה, מדף קבוע ביניהן. חזית של קומה — מקצה התיבה (או מאמצע המדף שבין קומות)
      const fl = unit.floors, last = fl.length - 1;
      const hs = resolveShares(inner.y1 - inner.y0 - last * shelfT, fl.map((f) => (Number.isFinite(f.height) && f.height > 0 ? f.height : null)));
      const a = []; { let yy = inner.y0; for (const hh of hs) { a.push(yy); yy += hh + shelfT; } }
      out.floors = hs;
      const tagOf = (j) => (fl.length > 1 ? `${prefix}-f${j + 1}` : prefix);
      const zoneName = (j) => (fl.length > 1 ? ` (קומה ${j + 1})` : '');
      const fy0 = (j) => (j === 0 ? y : a[j] - shelfT / 2), fy1 = (j) => (j === last ? y + h : a[j] + hs[j] + shelfT / 2);
      const col = { x0: inner.x0, x1: inner.x1 };
      fl.forEach((f, j) => {
        const fr = f.front || {}, it = f.interior || {};
        if (it.type === 'drawers') {
          // מגירות: בלי דלת — חזיתות מלאות על התיבה; מאחורי דלת — פנימיות, נסוגות, בלי ידית
          const dr = it.drawers || {};
          const innerDr = fr.type === 'wood';
          const n = Math.max(1, Math.round(dr.count ?? 3));
          const fm = same(dr.frontMaterial, same(fr.material, v.frontMaterial));
          const pins = Array.isArray(dr.heights) && dr.heights.length === n && dr.heights.some((g) => g !== null) ? dr.heights : null;
          const dh = pins ? resolveShares(hs[j], pins) : Array.from({ length: n }, () => hs[j] / n);
          const boxM = same(dr.boxMaterial, v.drawerBoxMaterial), botM = same(dr.bottomMaterial, v.drawerBottomMaterial);
          const zFront = innerDr ? z1 - 22 : z1;
          let ry = a[j];
          for (let r = 0; r < n; r++) {
            const ry0 = ry, ry1 = ry + dh[r]; ry = ry1;
            const d2 = drawer({ id: `${tagOf(j)}-drawer-${r + 1}`, name: `${prefix} מגירה ${r + 1}${zoneName(j)}${innerDr ? ' — פנימית' : ''}`, x0: innerDr ? inner.x0 : x, x1: innerDr ? inner.x1 : x + w,
              y0: innerDr ? ry0 : r === 0 ? fy0(j) : ry0, y1: innerDr ? ry1 : r === n - 1 ? fy1(j) : ry1,
              boxX0: inner.x0, boxX1: inner.x1, boxY0: ry0, boxY1: ry1,
              zFront, depth: Math.min(zFront - z0 - 20, 500), frontT: boardT(fm), boxT: boardT(boxM), bottomT: boardT(botM),
              frontMaterial: fm, boxMaterial: boxM, bottomMaterial: botM, slide: same(dr.slide, v.slide), handle: innerDr ? null : orNone(same(dr.handle, same(fr.handle, v.handle))),
              mountIds: [`${prefix}-side-L`, `${prefix}-side-R`], mountBottom: sideY });
            if (!innerDr) applyFinish(d2.parts[0], same(dr.finish, same(fr.finish, v.frontFinish)), { material: fm, normal: '+z' });
            out.parts.push(...d2.parts); out.hardware.push(...d2.hardware);
            if (!innerDr) maxFrontT = Math.max(maxFrontT, boardT(fm));
          }
          if (w > LIMITS.drawerMaxWidth) out.drawerWide = true;
        } else if (it.type !== 'empty') {
          const sh = it.shelves || {};
          const n = Math.max(0, Math.round(sh.count ?? 0));
          const adj = (sh.mode || 'adjustable') === 'adjustable';
          const gaps = Array.isArray(sh.gaps) && sh.gaps.length === n + 1 && !sh.gaps.every((g) => g === null) ? resolveShares(hs[j] - n * shelfT, sh.gaps) : null;
          const s = shelves({ col, y0: a[j], y1: a[j] + hs[j], z0, z1, count: n, t: shelfT, material: same(sh.material, v.shelfMaterial), setback: adj ? 5 : 0, adjustable: adj, colIndex: 0, prefix: `${tagOf(j)}-`, gaps });
          out.parts.push(...s.parts);
          if (adj) s.parts.forEach((p) => out.hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
        }
        if (j < last) out.parts.push(...shelves({ col, y0: a[j] + hs[j], y1: a[j] + hs[j] + shelfT, z0, z1, count: 1, t: shelfT, material: v.shelfMaterial, adjustable: false, colIndex: 0, prefix: `${prefix}-split${j + 1}-` }).parts);
      });
      // דלתות: לכל קומה עם דלת — על כל רוחב התיבה
      fl.forEach((f, j) => {
        const fr = f.front || {};
        if (fr.type !== 'wood') return;
        const fm = same(fr.material, v.frontMaterial), t = boardT(fm);
        maxFrontT = Math.max(maxFrontT, t);
        const leaves = fr.leaves === 1 || fr.leaves === 2 ? fr.leaves : w > LIMITS.doorWidth ? 2 : 1;
        for (let k = 0; k < leaves; k++) {
          const lx0 = x + (w / leaves) * k, lx1 = lx0 + w / leaves;
          const auto = leaves === 2 ? (k === 0 ? 'left' : 'right') : hingeDefault;
          const hingeSide = ['left', 'right', 'top', 'bottom'].includes(fr.hingeSide) ? fr.hingeSide : auto;
          const L = { id: `${prefix}-side-L`, y: sideY }, R = { id: `${prefix}-side-R`, y: sideY };
          const dd = door({ id: `${tagOf(j)}-door${leaves === 2 ? 'ab'[k] : ''}`, name: `${prefix} דלת${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}${zoneName(j)}`,
            x0: lx0, x1: lx1, y0: fy0(j), y1: fy1(j), zFront: z1, type: 'wood', t, material: fm, handle: orNone(same(fr.handle, v.handle)), hinge: same(fr.hinge, v.hinge),
            hingeSide, mountId: `${prefix}-side-${hingeSide === 'right' ? 'R' : 'L'}`, mountBottom: sideY,
            mounts: leaves === 2 ? (k === 0 ? { left: L } : { right: R }) : { left: L, right: R } });
          applyFinish(dd.parts[0], same(fr.finish, v.frontFinish), { material: fm, normal: '+z' });
          out.parts.push(...dd.parts); out.hardware.push(...dd.hardware);
        }
      });
      return out;
    };

    /**
     * ארון פינתי בצורת L: ריבוע C×C פחות הריבוע הפנוי שבפינה הפנימית
     * [D..C]×[D..C]. רצפה, גג ומדף — שני לוחות כל אחד; גבות מולבשים על שני
     * הקירות; דופן בכל קצה; דלת על כל פאה פנימית (x∈[D,C] בפאה z=D, ו-z∈[D,C]
     * בפאה x=D). מתקפלת: אותן דלתות, מחוברות זו לזו בציר.
     */
    const cornerL = (prefix, y, h, Cc, Dd, { bifold, carousel, shelvesN }) => {
      const out = { parts: [], hardware: [] };
      const bt = backT || 6, t = shelfT;
      const mk = (id, name, b, o) => out.parts.push(part(`${prefix}-${id}`, name, b, { ...o, note: o.note || prefix }));
      // גבות: מולבשים בפנים, לאורך שני הקירות
      mk('back-A', 'גב פינה', { x: 0, y, z: 0, w: Cc, h, d: bt }, { axis: 'z', grain: 'x', material: v.backMaterial });
      mk('back-B', 'גב פינה', { x: 0, y, z: bt, w: bt, h, d: Cc - bt }, { axis: 'x', grain: 'z', material: v.backMaterial });
      // דפנות בקצוות (לכיוון הארון הבא בכל קיר)
      mk('side-A', 'דופן פינה', { x: Cc - sideT, y, z: bt, w: sideT, h, d: Dd - bt }, { axis: 'x', grain: 'y', material: v.bodyMaterial, edges: { front: true } });
      mk('side-B', 'דופן פינה', { x: bt, y, z: Cc - sideT, w: Dd - bt, h, d: sideT }, { axis: 'z', grain: 'y', material: v.bodyMaterial, edges: { right: true } });
      // רצפה, גג, מדפים: לוח A לאורך הקיר האחורי, לוח B ממשיך לאורך הקיר השני
      const panelPair = (id, name, py, edgesA, edgesB) => {
        mk(`${id}-A`, name, { x: bt, y: py, z: bt, w: Cc - bt - sideT, h: t, d: Dd - bt }, { axis: 'y', grain: 'x', material: v.bodyMaterial, edges: edgesA });
        mk(`${id}-B`, name, { x: bt, y: py, z: Dd, w: Dd - bt, h: t, d: Cc - Dd - sideT }, { axis: 'y', grain: 'z', material: v.bodyMaterial, edges: edgesB });
      };
      panelPair('bottom', 'רצפת פינה', y, { front: true }, { right: true });
      panelPair('top', 'גג פינה', y + h - t, { front: true }, { right: true });
      for (let s = 1; s <= shelvesN; s++) panelPair(`shelf-${s}`, 'מדף פינה', y + (h * s) / (shelvesN + 1), { front: true }, { right: true });
      // דלתות: A על הפאה z=Dd, B על הפאה x=Dd (נבנית לאורך X ומוחלפת)
      const dw = Cc - Dd;
      // דלת ב תופסת את הפינה הפנימית בעובייה; דלת א מתחילה אחריה — נפגשות קצה לקצה.
      const dA = door({ id: `${prefix}-door-A`, name: `${prefix} דלת פינה א`, x0: Dd + frontT, x1: Cc, y0: y, y1: y + h, zFront: Dd, type: 'wood', t: frontT, material: v.frontMaterial,
        handle: bifold ? null : handle, hinge: bifold ? 'hw:hinge-165' : v.hinge, hingeSide: 'right' });
      const dB = swapXZ(door({ id: `${prefix}-door-B`, name: `${prefix} דלת פינה ב`, x0: Dd, x1: Cc, y0: y, y1: y + h, zFront: Dd, type: 'wood', t: frontT, material: v.frontMaterial,
        handle, hinge: bifold ? 'hw:hinge-165' : v.hinge, hingeSide: 'right' }));
      applyFinish(dA.parts[0], v.frontFinish, { material: v.frontMaterial, normal: '+z' });
      applyFinish(dB.parts[0], v.frontFinish, { material: v.frontMaterial, normal: '+x' });
      out.parts.push(...dA.parts, ...dB.parts); out.hardware.push(...dA.hardware, ...dB.hardware);
      if (bifold) out.hardware.push({ id: `${prefix}-bifold`, kind: 'misc', material: 'hw:hinge-bifold', qty: 3, note: 'צירי קיפול בין שתי הדלתות' });
      if (carousel) out.hardware.push({ id: `${prefix}-carousel`, kind: 'misc', material: 'hw:carousel', qty: 1, note: `קרוסלה לפינה ${Cc}` });
      if (dw < 300) warnings.push(`${prefix}: דלת פינה ברוחב ${dw} מ"מ — צרה מ-300; להגדיל את מידת הפינה`);
      return out;
    };

    /** ארון עיוור: תיבה רגילה ברוחב C לאורך הקיר האחורי; דלת רק על החלק הגלוי (מעבר לעומק הקיר השני + מילוי). */
    const cornerBlind = (prefix, y, h, Cc, Dd, { shelvesN }) => {
      const out = box(prefix, 0, Cc, y, h, Dd, { floors: [{ height: null, front: { type: 'none' }, interior: { type: 'shelves', shelves: { count: shelvesN, mode: v.shelvesMode } } }] });
      const filler = 50;
      const x0 = Dd + filler;
      mk2(out, `${prefix}-filler`, 'מילוי פינה', { x: Dd, y, z: Dd, w: filler, h, d: frontT }, { axis: 'z', grain: 'y', material: v.frontMaterial, note: `${prefix} — פס חזית קבוע ליד הקיר השני` });
      const dd = door({ id: `${prefix}-door`, name: `${prefix} דלת`, x0, x1: Cc, y0: y, y1: y + h, zFront: Dd, type: 'wood', t: frontT, material: v.frontMaterial, handle, hinge: v.hinge, hingeSide: 'right' });
      applyFinish(dd.parts[0], v.frontFinish, { material: v.frontMaterial, normal: '+z' });
      out.parts.push(...dd.parts); out.hardware.push(...dd.hardware);
      if (Cc - x0 < 300) warnings.push(`${prefix}: הדלת הגלויה ברוחב ${Cc - x0} מ"מ — צרה מ-300`);
      return out;
    };
    const mk2 = (out, id, name, b, o) => out.parts.push(part(id, name, b, o));

    const all = { parts, hardware };
    // ---- תחתונים ----
    if (isL) {
      const kind = v.cornerType;
      add(kind === 'blind'
        ? cornerBlind('פינה', v.plinthH, v.baseH, C, D, { shelvesN: v.shelvesPerCabinet })
        : cornerL('פינה', v.plinthH, v.baseH, C, D, { bifold: kind !== 'l-doors', carousel: kind === 'l-carousel', shelvesN: Math.max(1, v.shelvesPerCabinet) }));
    }
    // הארונות: מה שנשמר (units), ושורה שאין לה — מומרת מההגדרות הישנות
    const units = unitsOf(v, this.columnSpace(v));
    const geo = new Array(units.length);
    const rowUnits = (key) => units.map((u, i) => [u, i]).filter(([u]) => u.row === key);
    const runWidths = (key, total) => { const ru = rowUnits(key); return resolveShares(total, ru.map(([u]) => (Number.isFinite(u.width) && u.width > 0 ? u.width : null))); };
    const wAll = [];
    const baseRow = (key, swap, hingeDefault, prefixOf) => {
      const ws = runWidths(key, (key === 'baseA' ? Lx : v.lengthB) - C);
      let xx = C;
      rowUnits(key).forEach(([u, ui], k) => {
        const prefix = prefixOf(k + 1), w = ws[k];
        const b = box(prefix, xx, w, v.plinthH, v.baseH, D, u, { hingeDefault });
        add(swap ? swapXZ(b) : b);
        geo[ui] = { width: w, height: v.baseH, floors: b.floors };
        if (b.drawerWide) warnings.push(`${prefix}: מגירה ברוחב ${Math.round(w)} — מעבר ל-${LIMITS.drawerMaxWidth}`);
        wAll.push(w); xx += w;
      });
    };
    baseRow('baseA', false, 'left', (i) => (isL ? `א${i}` : `ת${i}`));
    if (isL) baseRow('baseB', true, 'right', (i) => `ב${i}`);
    // סוקל: רצועה לכל קיר
    parts.push(part('plinth-A', 'סוקל', { x: isL ? D - 50 : 0, y: 0, z: D - 50 - sideT, w: Lx - (isL ? D - 50 : 0), h: v.plinthH, d: sideT }, { axis: 'z', grain: 'x', material: v.bodyMaterial, edges: { front: true } }));
    if (isL) parts.push(part('plinth-B', 'סוקל', { x: D - 50 - sideT, y: 0, z: D - 50, w: sideT, h: v.plinthH, d: Lz - (D - 50) }, { axis: 'x', grain: 'z', material: v.bodyMaterial, edges: { right: true } }));
    hardware.push({ id: 'legs', kind: 'misc', material: 'hw:leg-adjust', qty: (v.cabinets + (isL ? v.cabinetsB + 2 : 0)) * 4, note: 'רגליות מתכווננות' });
    // משטח: לוח לכל קיר, נפגשים בפינה
    const topD = D + oh;
    parts.push(part('countertop-A', 'משטח עבודה', { x: 0, y: baseTop, z: 0, w: Lx, h: topT, d: topD }, { axis: 'y', grain: 'x', material: v.topMaterial, edges: { front: true, left: !isL, right: true } }));
    if (isL) parts.push(part('countertop-B', 'משטח עבודה', { x: 0, y: baseTop, z: topD, w: topD, h: topT, d: Lz - topD }, { axis: 'y', grain: 'z', material: v.topMaterial, edges: { right: true, front: true }, note: 'חיבור פינה למשטח א' }));

    // ---- עליונים ----
    let H = baseTop + topT;
    if (v.uppers === 'yes') {
      const uy = baseTop + topT + v.upperGap, Cu = isL ? v.cornerUpperSize : 0, Du = v.upperD;
      if (isL) add(cornerL('פינה-ע', uy, v.upperH, Cu, Du, { bifold: v.cornerType !== 'l-doors' && v.cornerType !== 'blind', carousel: false, shelvesN: 1 }));
      const upperRow = (key, swap, hingeDefault, prefixOf) => {
        const ws = runWidths(key, (key === 'upperA' ? Lx : v.lengthB) - Cu);
        let xx = Cu;
        rowUnits(key).forEach(([u, ui], k) => {
          const b = box(prefixOf(k + 1), xx, ws[k], uy, v.upperH, Du, u, { hingeDefault });
          add(swap ? swapXZ(b) : b);
          geo[ui] = { width: ws[k], height: v.upperH, floors: b.floors };
          xx += ws[k];
        });
      };
      upperRow('upperA', false, 'left', (i) => `ע${i}`);
      if (isL) upperRow('upperB', true, 'right', (i) => `עב${i}`);
      H = uy + v.upperH;
    }

    if (isL && v.cornerSide === 'right') mirrorX(all, Lx);

    const minW = Math.min(...wAll), maxW = Math.max(...wAll);
    if (minW < 300) warnings.push(`ארון ברוחב ${Math.round(minW)} מ"מ — צר מ-300`);
    if (maxW > 1000) warnings.push('ארונות רחבים מ-1000 — דלתות כפולות ומדפים ארוכים; כדאי יותר ארונות');
    if (isL && C < D + 300) warnings.push(`מידת הפינה ${C} קטנה מעומק הארון + 300 — הדלתות ייצאו צרות`);
    if (v.uppers === 'yes' && H > 2300) warnings.push(`גובה כולל ${Math.round(H)} מ"מ — מעל 2300 קשה להגיע למדף העליון`);
    return { parts, hardware, warnings, bounds: { w: Lx, h: H, d: Math.max(isL ? Lz : 0, topD, D + maxFrontT) }, units, unitGeo: geo, inactive: inactiveOf(units) };
  },
};

/**
 * values.units → רשימת הארונות לבנייה: לכל שורה פעילה — הארונות שנשמרו לה (נחתכים
 * או מוארכים בהעתק של האחרון כשמספר הארונות משתנה), ושורה שאין לה — מההגדרות הישנות.
 */
export function unitsOf(v, spec) {
  const saved = Array.isArray(v.units) && v.units.every((u) => u && u.row && Array.isArray(u.floors) && u.floors.length > 0) ? v.units : [];
  const out = [];
  for (const row of rowsOf(v)) {
    const have = saved.filter((u) => u.row === row.key);
    if (!have.length) { out.push(...migrateRow(v, spec, row.key, row.n)); continue; }
    const r = have.slice(0, row.n);
    while (r.length < row.n) r.push({ ...JSON.parse(JSON.stringify(have[have.length - 1])), width: null });
    out.push(...r);
  }
  return out;
}

/** שורת ארונות מההגדרות הישנות (columnsLayout + ברירות המחדל) — זהה לבנייה הישנה. */
function migrateRow(v, spec, key, n) {
  const sec = spec.sections.find((x) => x.key === key);
  const lay = sectionLayout(v.columnsLayout, key, n);
  const upper = key.startsWith('upper');
  return sec.items.map((it, i) => {
    const c = lay.cols[i] || {};
    const kind = it.kinds ? (c.kind || it.kind) : 'doors';
    const kindObj = it.kinds?.find((k) => k.id === kind);
    const count = Number.isInteger(c.shelves) ? c.shelves : (kindObj?.defaultCount ?? it.defaultCount ?? 0);
    const cells = (kindObj?.cellsOf || it.cellsOf) === 'drawers' ? Math.max(0, count) : Math.max(0, count) + 1;
    const pinned = Array.isArray(c.gaps) && c.gaps.length === cells && c.gaps.some((g) => g !== null) ? c.gaps : null;
    const floor = kind === 'drawers'
      ? { height: null, front: { ...SAME_FRONT, type: 'none' }, interior: { type: 'drawers', drawers: { count: Math.max(1, count), heights: pinned, frontMaterial: 'same', finish: 'same', handle: 'same', slide: 'same', boxMaterial: 'same', bottomMaterial: 'same' } } }
      : { height: null, front: { ...SAME_FRONT, type: 'wood' }, interior: { type: 'shelves', shelves: { count: upper ? Math.max(1, count) : count, gaps: upper && count < 1 ? null : pinned, material: 'same', mode: v.shelvesMode } } };
    return { row: key, width: lay.widths[i] ?? null, height: null, floors: [floor] };
  });
}

/** "לא בתוקף": ידית למגירה פנימית. */
function inactiveOf(units) {
  const out = [];
  units.forEach((u, i) => u.floors.forEach((f, j) => {
    const h = f.interior?.drawers?.handle;
    if (f.interior?.type === 'drawers' && f.front?.type === 'wood' && h && h !== 'none' && h !== 'same') out.push({ path: `units.${i}.floors.${j}.interior.drawers.handle`, column: i, floor: j, reason: 'מגירות פנימיות (מאחורי דלת) — בלי ידית' });
  }));
  return out;
}
