// תבנית: שידת מגירות.
//
// גוף, שורות × עמודות של מגירות, גג עם הבלטה קדימה, סוקל. המגירה כאן היא
// אבן הבניין המלאה: חזית, ארגז, תחתית ומסילות.

import { carcass, partitions, shelves, back, plinth, drawer, part } from '../blocks.js';
import { materialParams, drawerParams, joineryParams, boardT, bodyWarnings, LIMITS } from './common.js';

export default {
  key: 'dresser',
  name: 'שידת מגירות',
  description: 'שורות ועמודות של מגירות, גג בולט, סוקל.',
  laborHours: 8,

  params: [
    { key: 'width', label: 'רוחב', type: 'mm', min: 400, max: 2400, default: 1000, group: 'מידות' },
    { key: 'height', label: 'גובה', type: 'mm', min: 400, max: 1400, default: 850, group: 'מידות' },
    { key: 'depth', label: 'עומק', type: 'mm', min: 350, max: 700, default: 480, group: 'מידות' },

    { key: 'drawerRows', label: 'שורות מגירות', type: 'int', min: 1, max: 8, default: 4, group: 'חלוקה' },
    { key: 'drawerColumns', label: 'עמודות מגירות', type: 'int', min: 1, max: 4, default: 1, group: 'חלוקה' },
    { key: 'topRowH', label: 'גובה השורה העליונה', type: 'mm', min: 0, max: 400, default: 0, group: 'חלוקה', hint: '0 = כל השורות שוות' },
    { key: 'topOverhang', label: 'הבלטת הגג', type: 'mm', min: 0, max: 60, default: 20, group: 'חלוקה', hint: 'קדימה ולצדדים' },

    ...materialParams({ shelves: false }),
    { key: 'frontMaterial', label: 'חזיתות המגירות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'חומרים' },
    ...drawerParams(),
    { key: 'handle', label: 'ידיות', type: 'enum', default: 'hw:handle-bar-128', group: 'מגירות',
      options: [{ id: 'none', name: 'ללא (לחיצה)' }, { id: 'hw:handle-bar-128', name: 'מוט 128' }, { id: 'hw:handle-knob', name: 'כפתור' }] },

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 70, group: 'סיומות' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 25, group: 'סיומות' },
  ],
  joinery: joineryParams({ shelves: false }),
  limits: LIMITS,

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const W = v.width, H = v.height, D = v.depth;
    const sideT = v.sideT, shelfT = v.shelfT;
    const backT = v.backMode === 'none' ? 0 : boardT(v.backMaterial);
    const bodyZ = v.backMode === 'overlay' ? backT : 0;
    const frontT = boardT(v.frontMaterial);
    const oh = v.topOverhang;
    // הגג בולט: הגוף צר ב-2×הבלטה, והגג (לוח נפרד) מכסה את כולו.
    const bw = W - 2 * oh, bodyD = D - bodyZ - oh;
    const bottomY = v.plinthH + shelfT;
    const topY = H - shelfT;   // פני הגג העליונים של הגוף; מעליו לוח הגג הבולט

    const body = carcass({ w: bw, d: bodyD, z: bodyZ, bottomY, topY, sideT, panelT: shelfT, sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial });
    parts.push(...body.parts.map((p) => ({ ...p, box: { ...p.box, x: p.box.x + oh } })));
    const inner = { ...body.inner, x0: body.inner.x0 + oh, x1: body.inner.x1 + oh };
    parts.push(part('top-plate', 'גג בולט', { x: 0, y: topY, z: bodyZ, w: W, h: shelfT, d: D - bodyZ },
      { axis: 'y', grain: 'x', material: v.bodyMaterial, edges: { front: true, left: true, right: true } }));

    const cols = partitions({ inner, columns: v.drawerColumns, t: sideT, material: v.bodyMaterial });
    parts.push(...cols.parts);
    const z1 = inner.z1, z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0);

    // חלוקת הגובה לשורות: השורה העליונה בגובה נתון (אם 0 — שוות).
    const innerH = inner.y1 - inner.y0;
    const rows = v.drawerRows;
    const topH = v.topRowH > 0 && rows > 1 ? Math.min(v.topRowH, innerH - (rows - 1) * 100) : innerH / rows;
    const restH = rows > 1 ? (innerH - topH) / (rows - 1) : 0;
    cols.cols.forEach((col, c) => {
      let y = inner.y0;
      for (let r = 0; r < rows; r++) {
        const rowH = r === rows - 1 ? topH : restH;   // העליונה אחרונה מלמטה
        const d = drawer({
          id: `drawer-${c + 1}-${r + 1}`, name: `מגירה ${c + 1}.${r + 1}`, x0: col.x0, x1: col.x1, y0: y, y1: y + rowH,
          zFront: z1, depth: Math.min(z1 - z0 - 20, 550), frontT, boxT: boardT(v.drawerBoxMaterial), bottomT: boardT(v.drawerBottomMaterial),
          frontMaterial: v.frontMaterial, boxMaterial: v.drawerBoxMaterial, bottomMaterial: v.drawerBottomMaterial,
          slide: v.slide, handle: v.handle === 'none' ? null : v.handle,
        });
        parts.push(...d.parts); hardware.push(...d.hardware);
        y += rowH;
      }
      if (col.x1 - col.x0 > LIMITS.drawerMaxWidth) warnings.push(`מגירה ברוחב ${Math.round(col.x1 - col.x0)} מ"מ — מעבר ל-${LIMITS.drawerMaxWidth} המומלצים`);
    });
    if (restH && restH < 120) warnings.push(`שורת מגירה בגובה ${Math.round(restH)} מ"מ — נמוך מ-120`);

    // גב ברוחב הגוף (בלי ההבלטה)
    const b = back({ mode: v.backMode, outer: { w: bw, y0: bottomY - shelfT, y1: topY }, inner: { ...inner, x0: inner.x0, x1: inner.x1 }, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial });
    parts.push(...b.parts.map((p) => (v.backMode === 'overlay' ? { ...p, box: { ...p.box, x: p.box.x + oh } } : p)));
    parts.push(...plinth({ inner, h: v.plinthH, setback: v.plinthSetback, t: sideT, d: D - oh, material: v.bodyMaterial }).parts);

    warnings.push(...bodyWarnings({ H, colW: cols.colW, shelfT, hasShelves: false }));
    return { parts, hardware, warnings, bounds: { w: W, h: H, d: D + frontT } };
  },
};
