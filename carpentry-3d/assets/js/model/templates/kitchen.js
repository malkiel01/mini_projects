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

import { carcass, shelves, back, door, drawer, part } from '../blocks.js';
import { materialParams, drawerParams, joineryParams, boardT, LIMITS } from './common.js';

const N = (n) => Array.from({ length: n }, (_, i) => i + 1);

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
    { key: 'drawerCabinets', label: 'מהם ארונות מגירות (קיר אחורי)', type: 'int', min: 0, max: 12, default: 1, group: 'חלוקה', hint: 'הראשונים אחרי הפינה' },
    { key: 'drawerCabinetsB', label: 'ארונות מגירות (קיר שני)', type: 'int', min: 0, max: 12, default: 0, group: 'חלוקה', showIf: { shape: ['L'] } },
    { key: 'drawersPerCabinet', label: 'מגירות בארון מגירות', type: 'int', min: 2, max: 5, default: 3, group: 'חלוקה' },
    { key: 'shelvesPerCabinet', label: 'מדפים בארון דלתות', type: 'int', min: 0, max: 3, default: 1, group: 'חלוקה' },

    { key: 'uppers', label: 'ארונות עליונים', type: 'enum', default: 'yes', group: 'עליונים', options: [{ id: 'yes', name: 'כן' }, { id: 'no', name: 'לא' }] },
    { key: 'upperH', label: 'גובה עליון', type: 'mm', min: 400, max: 1000, default: 720, group: 'עליונים', showIf: { uppers: ['yes'] } },
    { key: 'upperD', label: 'עומק עליון', type: 'mm', min: 250, max: 400, default: 320, group: 'עליונים', showIf: { uppers: ['yes'] } },
    { key: 'upperGap', label: 'מרווח מהמשטח לעליון', type: 'mm', min: 400, max: 800, default: 550, group: 'עליונים', showIf: { uppers: ['yes'] } },

    ...materialParams({ body: 'board:melamine-white-18' }),
    { key: 'frontMaterial', label: 'חזיתות (דלתות ומגירות)', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'חומרים' },
    { key: 'topMaterial', label: 'משטח עבודה', type: 'material', kind: 'board', top: true, default: 'board:countertop-38', group: 'חומרים' },
    { key: 'topOverhang', label: 'הבלטת המשטח קדימה', type: 'mm', min: 0, max: 60, default: 40, group: 'חומרים' },
    ...drawerParams(),
    { key: 'hinge', label: 'צירים', type: 'enum', default: 'hw:hinge-110', group: 'חומרים', options: [{ id: 'hw:hinge-110', name: '110°' }, { id: 'hw:hinge-165', name: '165°' }] },
    { key: 'handle', label: 'ידיות', type: 'enum', default: 'hw:handle-bar-128', group: 'חומרים',
      options: [{ id: 'none', name: 'ללא (לחיצה)' }, { id: 'hw:handle-bar-128', name: 'מוט 128' }, { id: 'hw:handle-knob', name: 'כפתור' }] },
  ],
  joinery: joineryParams(),
  limits: LIMITS,

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const sideT = v.sideT, shelfT = v.shelfT;
    const backT = v.backMode === 'none' ? 0 : boardT(v.backMaterial);
    const bodyZ = v.backMode === 'overlay' ? backT : 0;
    const frontT = boardT(v.frontMaterial);
    const topT = boardT(v.topMaterial);
    const adjustable = v.shelvesMode === 'adjustable';
    const handle = v.handle === 'none' ? null : v.handle;
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
    const box = (prefix, x, w, y, h, d, { drawers, shelvesN, doorsOn, hingeDefault = 'left' }) => {
      const out = { parts: [], hardware: [] };
      const body = carcass({ w, d: d - bodyZ, z: bodyZ, y0: y, bottomY: y + shelfT, topY: y + h, sideT, panelT: shelfT, sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial });
      const shift = (p) => ({ ...p, id: `${prefix}-${p.id}`, box: { ...p.box, x: p.box.x + x }, note: p.note || prefix });
      out.parts.push(...body.parts.map(shift));
      const inner = { ...body.inner, x0: body.inner.x0 + x, x1: body.inner.x1 + x };
      const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;
      out.parts.push(...back({ mode: v.backMode, outer: { w, y0: y, y1: y + h }, inner, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial }).parts
        .map((p) => ({ ...p, id: `${prefix}-${p.id}`, box: { ...p.box, x: v.backMode === 'overlay' ? p.box.x + x : p.box.x } })));
      if (drawers) {
        const rowH = (inner.y1 - inner.y0) / drawers;
        for (let r = 0; r < drawers; r++) {
          const d2 = drawer({ id: `${prefix}-drawer-${r + 1}`, name: `${prefix} מגירה ${r + 1}`, x0: x, x1: x + w,
            y0: inner.y0 + r * rowH - (r === 0 ? shelfT : 0), y1: inner.y0 + (r + 1) * rowH + (r === drawers - 1 ? shelfT : 0),
            boxX0: inner.x0, boxX1: inner.x1, boxY0: inner.y0 + r * rowH, boxY1: inner.y0 + (r + 1) * rowH,
            zFront: z1, depth: Math.min(z1 - z0 - 20, 500), frontT, boxT: boardT(v.drawerBoxMaterial), bottomT: boardT(v.drawerBottomMaterial),
            frontMaterial: v.frontMaterial, boxMaterial: v.drawerBoxMaterial, bottomMaterial: v.drawerBottomMaterial, slide: v.slide, handle });
          out.parts.push(...d2.parts); out.hardware.push(...d2.hardware);
        }
      } else {
        const col = { x0: inner.x0, x1: inner.x1 };
        const s = shelves({ col, y0: inner.y0, y1: inner.y1, z0, z1, count: shelvesN, t: shelfT, material: v.shelfMaterial, setback: adjustable ? 5 : 0, adjustable, colIndex: 0, prefix: `${prefix}-` });
        out.parts.push(...s.parts);
        if (adjustable) s.parts.forEach((p) => out.hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
        if (doorsOn) {
          const leaves = w > LIMITS.doorWidth ? 2 : 1;
          for (let k = 0; k < leaves; k++) {
            const lx0 = x + (w / leaves) * k, lx1 = lx0 + w / leaves;
            const dd = door({ id: `${prefix}-door${leaves === 2 ? 'ab'[k] : ''}`, name: `${prefix} דלת${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
              x0: lx0, x1: lx1, y0: y, y1: y + h, zFront: z1, type: 'wood', t: frontT, material: v.frontMaterial, handle, hinge: v.hinge,
              hingeSide: leaves === 2 ? (k === 0 ? 'left' : 'right') : hingeDefault });
            out.parts.push(...dd.parts); out.hardware.push(...dd.hardware);
          }
        }
      }
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
      out.parts.push(...dA.parts, ...dB.parts); out.hardware.push(...dA.hardware, ...dB.hardware);
      if (bifold) out.hardware.push({ id: `${prefix}-bifold`, kind: 'misc', material: 'hw:hinge-bifold', qty: 3, note: 'צירי קיפול בין שתי הדלתות' });
      if (carousel) out.hardware.push({ id: `${prefix}-carousel`, kind: 'misc', material: 'hw:carousel', qty: 1, note: `קרוסלה לפינה ${Cc}` });
      if (dw < 300) warnings.push(`${prefix}: דלת פינה ברוחב ${dw} מ"מ — צרה מ-300; להגדיל את מידת הפינה`);
      return out;
    };

    /** ארון עיוור: תיבה רגילה ברוחב C לאורך הקיר האחורי; דלת רק על החלק הגלוי (מעבר לעומק הקיר השני + מילוי). */
    const cornerBlind = (prefix, y, h, Cc, Dd, { shelvesN }) => {
      const out = box(prefix, 0, Cc, y, h, Dd, { drawers: 0, shelvesN, doorsOn: false });
      const filler = 50;
      const x0 = Dd + filler;
      mk2(out, `${prefix}-filler`, 'מילוי פינה', { x: Dd, y, z: Dd, w: filler, h, d: frontT }, { axis: 'z', grain: 'y', material: v.frontMaterial, note: `${prefix} — פס חזית קבוע ליד הקיר השני` });
      const dd = door({ id: `${prefix}-door`, name: `${prefix} דלת`, x0, x1: Cc, y0: y, y1: y + h, zFront: Dd, type: 'wood', t: frontT, material: v.frontMaterial, handle, hinge: v.hinge, hingeSide: 'right' });
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
    const runA = Lx - C, wA = runA / v.cabinets;
    for (const i of N(v.cabinets)) {
      const prefix = isL ? `א${i}` : `ת${i}`;
      const drawers = i <= v.drawerCabinets ? v.drawersPerCabinet : 0;
      add(box(prefix, C + (i - 1) * wA, wA, v.plinthH, v.baseH, D, { drawers, shelvesN: v.shelvesPerCabinet, doorsOn: true, hingeDefault: 'left' }));
      if (drawers && wA > LIMITS.drawerMaxWidth) warnings.push(`${prefix}: מגירה ברוחב ${Math.round(wA)} — מעבר ל-${LIMITS.drawerMaxWidth}`);
    }
    let wB = 0;
    if (isL) {
      const runB = Lz - C; wB = runB / v.cabinetsB;
      for (const i of N(v.cabinetsB)) {
        const prefix = `ב${i}`;
        const drawers = i <= v.drawerCabinetsB ? v.drawersPerCabinet : 0;
        add(swapXZ(box(prefix, C + (i - 1) * wB, wB, v.plinthH, v.baseH, D, { drawers, shelvesN: v.shelvesPerCabinet, doorsOn: true, hingeDefault: 'right' })));
        if (drawers && wB > LIMITS.drawerMaxWidth) warnings.push(`${prefix}: מגירה ברוחב ${Math.round(wB)} — מעבר ל-${LIMITS.drawerMaxWidth}`);
      }
    }
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
      const uwA = (Lx - Cu) / v.cabinets;
      for (const i of N(v.cabinets)) add(box(`ע${i}`, Cu + (i - 1) * uwA, uwA, uy, v.upperH, Du, { drawers: 0, shelvesN: Math.max(1, v.shelvesPerCabinet), doorsOn: true, hingeDefault: 'left' }));
      if (isL) {
        const uwB = (Lz - Cu) / v.cabinetsB;
        for (const i of N(v.cabinetsB)) add(swapXZ(box(`עב${i}`, Cu + (i - 1) * uwB, uwB, uy, v.upperH, Du, { drawers: 0, shelvesN: Math.max(1, v.shelvesPerCabinet), doorsOn: true, hingeDefault: 'right' })));
      }
      H = uy + v.upperH;
    }

    if (isL && v.cornerSide === 'right') mirrorX(all, Lx);

    const minW = Math.min(wA, isL ? wB : wA);
    if (minW < 300) warnings.push(`ארון ברוחב ${Math.round(minW)} מ"מ — צר מ-300`);
    if (Math.max(wA, isL ? wB : 0) > 1000) warnings.push('ארונות רחבים מ-1000 — דלתות כפולות ומדפים ארוכים; כדאי יותר ארונות');
    if (isL && C < D + 300) warnings.push(`מידת הפינה ${C} קטנה מעומק הארון + 300 — הדלתות ייצאו צרות`);
    if (v.uppers === 'yes' && H > 2300) warnings.push(`גובה כולל ${Math.round(H)} מ"מ — מעל 2300 קשה להגיע למדף העליון`);
    return { parts, hardware, warnings, bounds: { w: Lx, h: H, d: Math.max(isL ? Lz : 0, topD, D + frontT) } };
  },
};
