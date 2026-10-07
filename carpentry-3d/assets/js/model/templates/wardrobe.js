// תבנית: ארון בגדים.
//
// גוף עם עמודות; בכל עמודה: תלייה (מוט + מדף עליון) או מדפים, ומגירות
// בתחתית לפי בחירה. דלתות: צירים (עץ) או הזזה. אותן אבני בניין כמו בספרייה,
// ועוד מגירה, מוט, ודלתות הזזה.

import { carcass, partitions, shelves, back, plinth, crown, door, drawer, rod, slidingDoors } from '../blocks.js';
import { material } from '../materials.js';
import { materialParams, doorParams, drawerParams, joineryParams, boardT, bodyWarnings, LIMITS } from './common.js';

export default {
  key: 'wardrobe',
  name: 'ארון בגדים',
  description: 'עמודות תלייה ומדפים, מגירות בתחתית, דלתות צירים או הזזה.',
  laborHours: 12,

  params: [
    { key: 'width', label: 'רוחב', type: 'mm', min: 500, max: 5000, default: 2400, group: 'מידות' },
    { key: 'height', label: 'גובה', type: 'mm', min: 1200, max: 3000, default: 2400, group: 'מידות' },
    { key: 'depth', label: 'עומק', type: 'mm', min: 400, max: 800, default: 600, group: 'מידות' },

    { key: 'columns', label: 'עמודות', type: 'int', min: 1, max: 8, default: 3, group: 'חלוקה' },
    { key: 'hangingColumns', label: 'עמודות תלייה', type: 'int', min: 0, max: 8, default: 2, group: 'חלוקה', hint: 'הראשונות משמאל; השאר מדפים' },
    { key: 'shelvesPerColumn', label: 'מדפים בעמודת מדפים', type: 'int', min: 0, max: 12, default: 5, group: 'חלוקה' },
    { key: 'topShelf', label: 'מדף עליון בעמודת תלייה', type: 'enum', default: 'yes', group: 'חלוקה', options: [{ id: 'yes', name: 'כן' }, { id: 'no', name: 'לא' }] },
    { key: 'drawersPerColumn', label: 'מגירות בתחתית כל עמודה', type: 'int', min: 0, max: 4, default: 0, group: 'חלוקה' },
    { key: 'drawerH', label: 'גובה מגירה', type: 'mm', min: 120, max: 400, default: 200, group: 'חלוקה', showIf: { drawersPerColumn: [1, 2, 3, 4] } },

    ...materialParams(),
    ...drawerParams(),

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 80, group: 'סיומות' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 30, group: 'סיומות' },
    { key: 'crownH', label: 'כרכוב — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות' },

    ...doorParams({ sliding: true, glass: false, height: false }),
    { key: 'rodMaterial', label: 'מוט תלייה', type: 'enum', default: 'hw:rod-chrome', group: 'מגירות', options: [{ id: 'hw:rod-chrome', name: 'כרום' }] },
  ],
  joinery: joineryParams(),
  limits: LIMITS,

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const W = v.width, H = v.height, D = v.depth;
    const sideT = v.sideT, shelfT = v.shelfT;
    const backT = v.backMode === 'none' ? 0 : boardT(v.backMaterial);
    const bodyZ = v.backMode === 'overlay' ? backT : 0;
    const bodyD = D - bodyZ;
    const bottomY = v.plinthH + shelfT;
    const topY = H - v.crownH;
    const sliding = v.doorType === 'sliding';
    const doorT = v.doorType === 'none' ? 0 : boardT(v.doorMaterial);

    const body = carcass({ w: W, d: bodyD, z: bodyZ, bottomY, topY, sideT, panelT: shelfT, sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial });
    parts.push(...body.parts);
    const inner = body.inner;
    const cols = partitions({ inner, columns: v.columns, t: sideT, material: v.bodyMaterial });
    parts.push(...cols.parts);
    const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;
    const adjustable = v.shelvesMode === 'adjustable';

    cols.cols.forEach((col, i) => {
      // מגירות בתחתית: תופסות גובה, ומעליהן מדף קבוע שסוגר אותן.
      let y0 = inner.y0;
      if (v.drawersPerColumn > 0) {
        for (let k = 0; k < v.drawersPerColumn; k++) {
          const dy0 = y0 + k * v.drawerH, dy1 = dy0 + v.drawerH;
          const d = drawer({
            id: `drawer-${i + 1}-${k + 1}`, name: `מגירה ${i + 1}.${k + 1}`, x0: col.x0, x1: col.x1, y0: dy0, y1: dy1,
            zFront: z1, depth: Math.min(z1 - z0 - 20, 500), frontT: doorT || sideT, boxT: boardT(v.drawerBoxMaterial), bottomT: boardT(v.drawerBottomMaterial),
            frontMaterial: v.doorType === 'none' ? v.bodyMaterial : v.doorMaterial, boxMaterial: v.drawerBoxMaterial, bottomMaterial: v.drawerBottomMaterial,
            slide: v.slide, handle: v.handle === 'none' ? null : v.handle,
          });
          parts.push(...d.parts); hardware.push(...d.hardware);
        }
        y0 += v.drawersPerColumn * v.drawerH;
        parts.push(...shelves({ col, y0: y0 - shelfT, y1: y0 + shelfT, z0, z1, count: 1, t: shelfT, material: v.shelfMaterial, adjustable: false, colIndex: i, prefix: 'over-drawers-' }).parts);
        y0 += shelfT;
        if (col.x1 - col.x0 > LIMITS.drawerMaxWidth) warnings.push(`מגירה ברוחב ${Math.round(col.x1 - col.x0)} מ"מ — מעבר ל-${LIMITS.drawerMaxWidth} המומלצים`);
      }
      if (i < v.hangingColumns) {
        // תלייה: מוט 40 מתחת למדף העליון (או לגג), באמצע העומק.
        let rodY = inner.y1 - 40;
        if (v.topShelf === 'yes' && inner.y1 - y0 > 1800) {
          const s = shelves({ col, y0: inner.y1 - 400 - shelfT, y1: inner.y1 - 400 + shelfT, z0, z1, count: 1, t: shelfT, material: v.shelfMaterial, adjustable, colIndex: i, prefix: 'top-' });
          parts.push(...s.parts);
          rodY = inner.y1 - 400 - 40;
        }
        hardware.push(...rod({ id: `rod-${i + 1}`, x0: col.x0, x1: col.x1, y: rodY, z: (z0 + z1) / 2, material: v.rodMaterial }).hardware);
        if (rodY - y0 < 1000) warnings.push(`עמודה ${i + 1}: גובה תלייה ${Math.round(rodY - y0)} מ"מ — פחות מ-1000, קצר לחולצות`);
      } else {
        const s = shelves({ col, y0, y1: inner.y1, z0, z1, count: v.shelvesPerColumn, t: shelfT, material: v.shelfMaterial, setback: adjustable ? 5 : 0, adjustable, colIndex: i });
        parts.push(...s.parts);
        if (adjustable) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
      }
    });

    parts.push(...back({ mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial }).parts);
    parts.push(...plinth({ inner, h: v.plinthH, setback: v.plinthSetback, t: sideT, d: D, material: v.bodyMaterial }).parts);
    parts.push(...crown({ w: W, y: topY, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial }).parts);

    // דלתות: מעל המגירות (אם יש) או מהסוקל; הזזה מכסה הכול.
    const doorY0 = v.plinthH + (v.drawersPerColumn > 0 && !sliding ? shelfT + v.drawersPerColumn * v.drawerH + shelfT : 0);
    if (sliding) {
      const sd = slidingDoors({ id: 'sliding', x0: 0, x1: W, y0: v.plinthH, y1: topY, zFront: D, leaves: v.slidingLeaves, t: doorT, material: v.doorMaterial, track: 'hw:track-sliding' });
      parts.push(...sd.parts); hardware.push(...sd.hardware);
      if (sd.leafWidth > 1200) warnings.push(`כנף הזזה ברוחב ${Math.round(sd.leafWidth)} מ"מ — כבדה; מומלץ עד 1200`);
    } else if (v.doorType === 'wood') {
      cols.cols.forEach((col, i) => {
        const x0 = i === 0 ? 0 : col.x0 - sideT / 2, x1 = i === cols.cols.length - 1 ? W : col.x1 + sideT / 2;
        const colW = x1 - x0, leaves = colW > LIMITS.doorWidth ? 2 : 1;
        for (let k = 0; k < leaves; k++) {
          const lx0 = x0 + (colW / leaves) * k, lx1 = lx0 + colW / leaves;
          const d = door({ id: `door-${i + 1}${leaves === 2 ? 'ab'[k] : ''}`, name: `דלת ${i + 1}${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
            x0: lx0, x1: lx1, y0: doorY0, y1: topY, zFront: D, type: 'wood', t: doorT, material: v.doorMaterial,
            handle: v.handle === 'none' ? null : v.handle, hinge: v.hinge, hingeSide: leaves === 2 ? (k === 0 ? 'left' : 'right') : (i < cols.cols.length / 2 ? 'left' : 'right') });
          parts.push(...d.parts); hardware.push(...d.hardware);
        }
      });
    }

    warnings.push(...bodyWarnings({ H, colW: cols.colW, shelfT, hasShelves: v.hangingColumns < v.columns && v.shelvesPerColumn > 0 }));
    const extraD = sliding ? 2 * doorT + 4 : doorT;
    return { parts, hardware, warnings, bounds: { w: W, h: H, d: D + extraD } };
  },
};
