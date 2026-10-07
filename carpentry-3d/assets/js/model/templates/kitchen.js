// תבנית: מטבח — קו ישר.
//
// שרשרת ארונות תחתונים (כל אחד תיבה עם דלתות או מגירות), משטח עבודה, סוקל
// משותף, ואופציונלית ארונות עליונים. מספר הארונות קובע את רוחבם: הקו מתחלק
// שווה. אבני הבניין: תיבה, מדפים, גב, דלת, מגירה.

import { carcass, shelves, back, door, drawer, part } from '../blocks.js';
import { materialParams, drawerParams, joineryParams, boardT, LIMITS } from './common.js';

export default {
  key: 'kitchen',
  name: 'מטבח — קו ישר',
  description: 'ארונות תחתונים עם דלתות ומגירות, משטח עבודה, ארונות עליונים.',
  laborHours: 24,

  params: [
    { key: 'length', label: 'אורך הקו', type: 'mm', min: 600, max: 8000, default: 3000, group: 'מידות' },
    { key: 'baseH', label: 'גובה ארון תחתון (בלי סוקל ומשטח)', type: 'mm', min: 600, max: 900, default: 720, group: 'מידות' },
    { key: 'baseD', label: 'עומק ארון תחתון', type: 'mm', min: 400, max: 650, default: 560, group: 'מידות' },
    { key: 'plinthH', label: 'סוקל', type: 'mm', min: 60, max: 200, default: 100, group: 'מידות' },

    { key: 'cabinets', label: 'ארונות תחתונים', type: 'int', min: 1, max: 12, default: 5, group: 'חלוקה' },
    { key: 'drawerCabinets', label: 'מהם ארונות מגירות', type: 'int', min: 0, max: 12, default: 1, group: 'חלוקה', hint: 'הראשונים משמאל' },
    { key: 'drawersPerCabinet', label: 'מגירות בארון מגירות', type: 'int', min: 2, max: 5, default: 3, group: 'חלוקה', showIf: { drawerCabinets: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] } },
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
    const cabW = v.length / v.cabinets;
    const baseTop = v.plinthH + v.baseH;   // פני הארון התחתון, מתחת למשטח
    let maxD = v.baseD + frontT;

    const buildBox = (prefix, x, y, h, d, { drawers, shelvesN, doorsOn }) => {
      const body = carcass({ w: cabW, d: d - bodyZ, z: bodyZ, y0: y, bottomY: y + shelfT, topY: y + h, sideT, panelT: shelfT, sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial });
      const shift = (p) => ({ ...p, id: `${prefix}-${p.id}`, box: { ...p.box, x: p.box.x + x }, note: p.note || prefix });
      parts.push(...body.parts.map(shift));
      const inner = { ...body.inner, x0: body.inner.x0 + x, x1: body.inner.x1 + x };
      const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;
      parts.push(...back({ mode: v.backMode, outer: { w: cabW, y0: y, y1: y + h }, inner, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial }).parts
        .map((p) => ({ ...p, id: `${prefix}-${p.id}`, box: { ...p.box, x: v.backMode === 'overlay' ? p.box.x + x : p.box.x } })));
      if (drawers) {
        const rowH = (inner.y1 - inner.y0) / drawers;
        for (let r = 0; r < drawers; r++) {
          const d2 = drawer({ id: `${prefix}-drawer-${r + 1}`, name: `${prefix} מגירה ${r + 1}`, x0: x, x1: x + cabW, y0: inner.y0 + r * rowH - (r === 0 ? shelfT : 0), y1: inner.y0 + (r + 1) * rowH + (r === drawers - 1 ? shelfT : 0),
            zFront: z1, depth: Math.min(z1 - z0 - 20, 500), frontT, boxT: boardT(v.drawerBoxMaterial), bottomT: boardT(v.drawerBottomMaterial),
            frontMaterial: v.frontMaterial, boxMaterial: v.drawerBoxMaterial, bottomMaterial: v.drawerBottomMaterial, slide: v.slide, handle });
          parts.push(...d2.parts); hardware.push(...d2.hardware);
        }
      } else {
        const col = { x0: inner.x0, x1: inner.x1 };
        const s = shelves({ col, y0: inner.y0, y1: inner.y1, z0, z1, count: shelvesN, t: shelfT, material: v.shelfMaterial, setback: adjustable ? 5 : 0, adjustable, colIndex: 0, prefix: `${prefix}-` });
        parts.push(...s.parts);
        if (adjustable) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
        if (doorsOn) {
          const leaves = cabW > LIMITS.doorWidth ? 2 : 1;
          for (let k = 0; k < leaves; k++) {
            const lx0 = x + (cabW / leaves) * k, lx1 = lx0 + cabW / leaves;
            const dd = door({ id: `${prefix}-door${leaves === 2 ? 'ab'[k] : ''}`, name: `${prefix} דלת${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
              x0: lx0, x1: lx1, y0: y, y1: y + h, zFront: z1 + (bodyZ ? 0 : 0), type: 'wood', t: frontT, material: v.frontMaterial, handle, hinge: v.hinge,
              hingeSide: leaves === 2 ? (k === 0 ? 'left' : 'right') : 'left' });
            parts.push(...dd.parts); hardware.push(...dd.hardware);
          }
        }
      }
    };

    for (let i = 0; i < v.cabinets; i++) {
      const prefix = `ת${i + 1}`;
      const isDrawers = i < v.drawerCabinets;
      buildBox(prefix, i * cabW, v.plinthH, v.baseH, v.baseD, { drawers: isDrawers ? v.drawersPerCabinet : 0, shelvesN: v.shelvesPerCabinet, doorsOn: true });
      if (isDrawers && cabW > LIMITS.drawerMaxWidth) warnings.push(`${prefix}: מגירה ברוחב ${Math.round(cabW)} — מעבר ל-${LIMITS.drawerMaxWidth}`);
    }
    // סוקל משותף
    parts.push(part('plinth', 'סוקל', { x: 0, y: 0, z: v.baseD - 50 - sideT, w: v.length, h: v.plinthH, d: sideT }, { axis: 'z', grain: 'x', material: v.bodyMaterial, edges: { front: true } }));
    hardware.push({ id: 'legs', kind: 'misc', material: 'hw:leg-adjust', qty: v.cabinets * 4, note: 'רגליות מתכווננות' });
    // משטח
    const topD = v.baseD + v.topOverhang;
    parts.push(part('countertop', 'משטח עבודה', { x: 0, y: baseTop, z: 0, w: v.length, h: topT, d: topD }, { axis: 'y', grain: 'x', material: v.topMaterial, edges: { front: true, left: true, right: true } }));
    maxD = Math.max(maxD, topD);
    let H = baseTop + topT;
    if (v.uppers === 'yes') {
      const uy = baseTop + topT + v.upperGap;
      for (let i = 0; i < v.cabinets; i++) {
        buildBox(`ע${i + 1}`, i * cabW, uy, v.upperH, v.upperD, { drawers: 0, shelvesN: Math.max(1, v.shelvesPerCabinet), doorsOn: true });
      }
      H = uy + v.upperH;
      // העליונים נמצאים מאחור (z קטן) — הם על הקיר, כמו התחתונים
    }
    if (cabW < 300) warnings.push(`ארון ברוחב ${Math.round(cabW)} מ"מ — צר מ-300`);
    if (v.length / v.cabinets > 1000) warnings.push('ארונות רחבים מ-1000 — דלתות כפולות ומדפים ארוכים; כדאי יותר ארונות');
    if (v.uppers === 'yes' && H > 2300) warnings.push(`גובה כולל ${Math.round(H)} מ"מ — מעל 2300 קשה להגיע למדף העליון`);
    return { parts, hardware, warnings, bounds: { w: v.length, h: H, d: maxD } };
  },
};
