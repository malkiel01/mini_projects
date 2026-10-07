// תבנית: שולחן.
//
// פלטה, ארבע רגליים מעץ מלא, ומסגרת (אפרון) מתחת לפלטה שמחברת אותן.
// אבן בניין חדשה: רגל. אין גוף, אין גב, אין מדפים — ולכן גם החיבורים שונים.

import { part, leg } from '../blocks.js';
import { boardT, wheelsParam, addWheels } from './common.js';

export default {
  key: 'table',
  name: 'שולחן',
  description: 'פלטה, ארבע רגליים ומסגרת. אוכל, עבודה או קפה.',
  laborHours: 7,

  params: [
    { key: 'length', label: 'אורך', type: 'mm', min: 500, max: 3600, default: 1600, group: 'מידות' },
    { key: 'width', label: 'רוחב', type: 'mm', min: 400, max: 1500, default: 900, group: 'מידות' },
    { key: 'height', label: 'גובה', type: 'mm', min: 300, max: 1100, default: 750, group: 'מידות' },

    { key: 'topMaterial', label: 'הפלטה', type: 'material', kind: 'board', back: false, default: 'board:veneer-oak-18', group: 'חומרים' },
    { key: 'topT', label: 'עובי הפלטה', type: 'mm', min: 18, max: 60, default: 30, group: 'חומרים', hint: 'כולל עיבוי, אם יש' },
    { key: 'legMaterial', label: 'הרגליים והמסגרת', type: 'material', kind: 'board', solid: true, default: 'board:solid-oak', group: 'חומרים' },
    { key: 'edgeMaterial', label: 'קנט לפלטה', type: 'material', kind: 'edge', default: 'edge:veneer-0.5', group: 'חומרים' },

    { key: 'legSize', label: 'חתך הרגל', type: 'mm', min: 40, max: 120, default: 70, group: 'מבנה' },
    wheelsParam({ group: 'מבנה', hint: 'גלגל מתחת לכל רגל; השולחן מורם בגובהו' }),
    { key: 'legInset', label: 'הרגל מקצה הפלטה', type: 'mm', min: 0, max: 400, default: 60, group: 'מבנה' },
    { key: 'apronH', label: 'גובה המסגרת', type: 'mm', min: 0, max: 200, default: 80, group: 'מבנה', hint: '0 = ללא מסגרת' },
    { key: 'apronT', label: 'עובי המסגרת', type: 'mm', min: 18, max: 50, default: 25, group: 'מבנה' },
    { key: 'apronSetback', label: 'נסיגת המסגרת מפני הרגל', type: 'mm', min: 0, max: 40, default: 10, group: 'מבנה' },
    { key: 'stretcher', label: 'קורת חיזוק תחתונה', type: 'enum', default: 'none', group: 'מבנה', options: [{ id: 'none', name: 'ללא' }, { id: 'long', name: 'לאורך' }, { id: 'h', name: 'צורת H' }] },
  ],
  joinery: [
    { key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים',
      options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] },
  ],

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const L = v.length, W = v.width, H = v.height;
    const topT = v.topT, s = v.legSize, inset = v.legInset;
    // X לאורך השולחן, Z לרוחבו (קדימה = הצד הארוך הקדמי).
    parts.push(part('top', 'פלטה', { x: 0, y: H - topT, z: 0, w: L, h: topT, d: W },
      { axis: 'y', grain: 'x', material: v.topMaterial, edges: { front: true, back: true, left: true, right: true } }));
    const legY1 = H - topT;
    const corners = [[inset, inset, 'שמאל-אחור'], [L - inset - s, inset, 'ימין-אחור'], [inset, W - inset - s, 'שמאל-קדימה'], [L - inset - s, W - inset - s, 'ימין-קדימה']];
    corners.forEach(([x, z, n], i) => parts.push(leg({ id: `leg-${i + 1}`, x, z, y0: 0, y1: legY1, size: s, material: v.legMaterial, note: n })));

    if (v.apronH > 0) {
      const ay = legY1 - v.apronH, t = v.apronT, sb = v.apronSetback;
      const lenX = L - 2 * inset - 2 * s;     // בין הרגליים לאורך
      const lenZ = W - 2 * inset - 2 * s;     // בין הרגליים לרוחב
      // ארוכות: בחזית ובאחור, בין הרגליים, נסוגות מפני הרגל.
      parts.push(part('apron-front', 'מסגרת ארוכה', { x: inset + s, y: ay, z: W - inset - sb - t, w: lenX, h: v.apronH, d: t }, { axis: 'z', grain: 'x', material: v.legMaterial, qtyKey: `apron-${Math.round(lenX)}` }));
      parts.push(part('apron-back', 'מסגרת ארוכה', { x: inset + s, y: ay, z: inset + sb, w: lenX, h: v.apronH, d: t }, { axis: 'z', grain: 'x', material: v.legMaterial, qtyKey: `apron-${Math.round(lenX)}` }));
      parts.push(part('apron-left', 'מסגרת קצרה', { x: inset + sb, y: ay, z: inset + s, w: t, h: v.apronH, d: lenZ }, { axis: 'x', grain: 'z', material: v.legMaterial, qtyKey: `apron-${Math.round(lenZ)}` }));
      parts.push(part('apron-right', 'מסגרת קצרה', { x: L - inset - sb - t, y: ay, z: inset + s, w: t, h: v.apronH, d: lenZ }, { axis: 'x', grain: 'z', material: v.legMaterial, qtyKey: `apron-${Math.round(lenZ)}` }));
      if (v.stretcher !== 'none') {
        const sy = Math.max(100, H * 0.2);
        parts.push(part('stretcher-long', 'קורת חיזוק לאורך', { x: inset + s, y: sy, z: W / 2 - t / 2, w: lenX, h: v.apronH, d: t }, { axis: 'z', grain: 'x', material: v.legMaterial }));
        if (v.stretcher === 'h') {
          parts.push(part('stretcher-left', 'קורת חיזוק לרוחב', { x: inset + sb, y: sy, z: inset + s, w: t, h: v.apronH, d: lenZ }, { axis: 'x', grain: 'z', material: v.legMaterial, qtyKey: `stretcher-${Math.round(lenZ)}` }));
          parts.push(part('stretcher-right', 'קורת חיזוק לרוחב', { x: L - inset - sb - t, y: sy, z: inset + s, w: t, h: v.apronH, d: lenZ }, { axis: 'x', grain: 'z', material: v.legMaterial, qtyKey: `stretcher-${Math.round(lenZ)}` }));
        }
      }
    } else if (L > 1200) {
      warnings.push('שולחן ארוך בלי מסגרת — הפלטה עלולה להתכופף; מומלץ מסגרת או עיבוי');
    }
    hardware.push({ id: 'leg-bolts', kind: 'misc', material: 'hw:leg-adjust', qty: 4, note: 'רגליות / פלטות חיבור' });
    if (L / topT > 60) warnings.push(`פלטה באורך ${L} ובעובי ${topT} — יחס גדול מ-60, מומלץ לעבות`);
    if (H - topT - v.apronH < 600 && H > 650) warnings.push('מתחת למסגרת נשארים פחות מ-600 מ"מ — צפוף לברכיים');
    const lift = addWheels(parts, hardware, v, { x0: 0, x1: L, y0: 0, z0: 0, z1: W, inset: v.legSize / 2 + (v.legInset ?? 0) });
    return { parts, hardware, warnings, bounds: { w: L, h: H + lift, d: W } };
  },
};
