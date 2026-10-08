// תבנית: שידת מגירות.
//
// גוף, שורות × עמודות של מגירות, גג עם הבלטה קדימה, סוקל. המגירה כאן היא
// אבן הבניין המלאה: חזית, ארגז, תחתית ומסילות.

import { carcass, partitions, shelves, back, plinth, drawer, part } from '../blocks.js';
import { materialParams, drawerParams, joineryParams, boardT, bodyWarnings, LIMITS, handleParam, wheelsParam, addWheels } from './common.js';
import { resolveShares, sectionLayout, effectivePins } from '../layout.js';

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
    { key: 'columnsLayout', label: 'עריכת החלוקה', type: 'json', default: null, group: 'חלוקה', editor: 'columns', hint: 'רוחב לכל עמודה וגובה לכל שורה' },

    ...materialParams({ shelves: false }),
    { key: 'frontMaterial', label: 'חזיתות המגירות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'חומרים' },
    ...drawerParams(),
    handleParam({ group: 'מגירות' }),

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 70, group: 'סיומות' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 25, group: 'סיומות' },
    wheelsParam(),
  ],
  joinery: joineryParams({ shelves: false }),
  limits: LIMITS,

  /** לעורך החלוקה: שתי קבוצות — רוחבי העמודות וגבהי השורות (השורה העליונה לפי "גובה השורה העליונה" כברירת מחדל). */
  columnSpace(v) {
    const sideT = v.sideT ?? 18, shelfT = v.shelfT ?? 18, oh = v.topOverhang ?? 20;
    const cols = v.drawerColumns ?? 1, rows = v.drawerRows ?? 1;
    const innerW = (v.width ?? 1000) - 2 * oh - 2 * sideT - (cols - 1) * sideT;
    const innerH = (v.height ?? 850) - shelfT - ((v.plinthH ?? 70) + shelfT);
    return { sections: [
      { key: 'cols', title: 'עמודות', total: innerW, sizeLabel: 'רוחב', allLabel: 'העמודות', modes: { next: 'מהעמודה שמימין', prev: 'מהעמודה שמשמאל' },
        items: Array.from({ length: cols }, (_, i) => ({ label: `עמודה ${i + 1}`, editable: false, note: '' })) },
      { key: 'rows', title: 'שורות מגירות', total: innerH, sizeLabel: 'גובה', allLabel: 'השורות', reverse: true, modes: { next: 'מהשורה שמעליה', prev: 'מהשורה שמתחתיה' },
        items: Array.from({ length: rows }, (_, i) => ({ label: `שורה ${i + 1}${i === rows - 1 ? ' (עליונה)' : i === 0 ? ' (תחתונה)' : ''}`, editable: false, note: '', defaultPin: i === rows - 1 && rows > 1 && (v.topRowH ?? 0) > 0 ? v.topRowH : null })) },
    ] };
  },

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

    // חלוקה: רוחבי עמודות וגבהי שורות — נעוץ או אוטומטי; השורה העליונה לפי "גובה השורה העליונה" כשלא נעוצה.
    const spec = this.columnSpace(v);
    const colLay = sectionLayout(v.columnsLayout, 'cols', v.drawerColumns);
    const widths = resolveShares(inner.x1 - inner.x0 - (v.drawerColumns - 1) * sideT, colLay.widths);
    const cols = partitions({ inner, columns: v.drawerColumns, t: sideT, material: v.bodyMaterial, widths });
    parts.push(...cols.parts);
    const z1 = inner.z1, z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0);

    const innerH = inner.y1 - inner.y0;
    const rows = v.drawerRows;
    const rowLay = sectionLayout(v.columnsLayout, 'rows', rows);
    const rowHs = resolveShares(innerH, effectivePins(rowLay.widths, spec.sections[1].items), 100);
    const restH = Math.min(...rowHs);
    cols.cols.forEach((col, c) => {
      let y = inner.y0;
      for (let r = 0; r < rows; r++) {
        const rowH = rowHs[r];   // מלמטה למעלה
        const d = drawer({
          id: `drawer-${c + 1}-${r + 1}`, name: `מגירה ${c + 1}.${r + 1}`, x0: col.x0, x1: col.x1, y0: y, y1: y + rowH,
          zFront: z1, depth: Math.min(z1 - z0 - 20, 550), frontT, boxT: boardT(v.drawerBoxMaterial), bottomT: boardT(v.drawerBottomMaterial),
          frontMaterial: v.frontMaterial, boxMaterial: v.drawerBoxMaterial, bottomMaterial: v.drawerBottomMaterial,
          slide: v.slide, handle: v.handle === 'none' ? null : v.handle,
          mountIds: [c === 0 ? 'side-L' : `partition-${c}`, c === cols.cols.length - 1 ? 'side-R' : `partition-${c + 1}`], mountBottom: inner.y0,
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
    const lift = addWheels(parts, hardware, v, { x0: oh, x1: W - oh, y0: 0, z0: bodyZ, z1: bodyZ + bodyD });
    return { parts, hardware, warnings, bounds: { w: W, h: H + lift, d: D + frontT } };
  },
};
