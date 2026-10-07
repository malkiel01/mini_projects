// תבנית: מיטה.
//
// מסגרת סביב המזרן (שתי דפנות ארוכות, ראש ורגל), ראש מיטה, קורת תמיכה
// מרכזית, לטות (שלבים), ורגליים. המזרן אינו חלק — הוא נקנה, לא נחתך — ולכן
// אינו ברשימה; מידותיו הן הקלט.

import { part, leg } from '../blocks.js';
import { boardT } from './common.js';

export default {
  key: 'bed',
  name: 'מיטה',
  description: 'מסגרת סביב המזרן, ראש מיטה, לטות וקורת תמיכה.',
  laborHours: 9,

  params: [
    { key: 'mattressW', label: 'רוחב המזרן', type: 'mm', min: 700, max: 2000, default: 1600, group: 'מידות' },
    { key: 'mattressL', label: 'אורך המזרן', type: 'mm', min: 1600, max: 2200, default: 2000, group: 'מידות' },
    { key: 'mattressH', label: 'גובה המזרן', type: 'mm', min: 100, max: 400, default: 250, group: 'מידות', hint: 'לקביעת גובה המסגרת' },
    { key: 'frameTop', label: 'גובה פני הלטות מהרצפה', type: 'mm', min: 150, max: 600, default: 300, group: 'מידות' },
    { key: 'headboardH', label: 'גובה ראש המיטה', type: 'mm', min: 0, max: 1500, default: 1000, group: 'מידות', hint: '0 = ללא' },
    { key: 'gap', label: 'מרווח סביב המזרן', type: 'mm', min: 0, max: 40, default: 10, group: 'מידות' },

    { key: 'frameMaterial', label: 'המסגרת וראש המיטה', type: 'material', kind: 'board', back: false, top: false, default: 'board:veneer-oak-18', group: 'חומרים' },
    { key: 'frameT', label: 'עובי המסגרת', type: 'mm', min: 18, max: 40, default: 25, group: 'חומרים' },
    { key: 'slatMaterial', label: 'הלטות והקורה', type: 'material', kind: 'board', solid: true, default: 'board:solid-beech', group: 'חומרים' },
    { key: 'legMaterial', label: 'הרגליים', type: 'material', kind: 'board', solid: true, default: 'board:solid-oak', group: 'חומרים' },
    { key: 'edgeMaterial', label: 'קנט', type: 'material', kind: 'edge', default: 'edge:veneer-0.5', group: 'חומרים' },

    { key: 'frameH', label: 'גובה דופן המסגרת', type: 'mm', min: 80, max: 400, default: 200, group: 'מבנה', hint: 'מעל הלטות: מכסה חלק מהמזרן' },
    { key: 'slatW', label: 'רוחב לטה', type: 'mm', min: 50, max: 120, default: 80, group: 'מבנה' },
    { key: 'slatGap', label: 'מרווח בין לטות', type: 'mm', min: 20, max: 100, default: 50, group: 'מבנה' },
    { key: 'slatT', label: 'עובי לטה', type: 'mm', min: 12, max: 30, default: 18, group: 'מבנה' },
    { key: 'legSize', label: 'חתך הרגל', type: 'mm', min: 40, max: 120, default: 60, group: 'מבנה' },
    { key: 'legs', label: 'רגליים', type: 'enum', default: '6', group: 'מבנה', options: [{ id: '4', name: '4 (בפינות)' }, { id: '6', name: '6 (+ באמצע)' }] },
  ],
  joinery: [
    { key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים',
      options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] },
  ],

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const t = v.frameT, g = v.gap;
    // X לרוחב המיטה, Z לאורכה (ראש המיטה מאחור, z=0), Y למעלה.
    const innerW = v.mattressW + 2 * g, innerL = v.mattressL + 2 * g;
    const W = innerW + 2 * t, L = innerL + t + (v.headboardH > 0 ? t : t);
    const slatTop = v.frameTop;                       // פני הלטות
    const frameY1 = slatTop + v.frameH;               // קצה המסגרת העליון
    const frameY0 = Math.max(0, slatTop - v.slatT - 40);   // תחתית הדופן: 40 מתחת ללטות (למסילת התמיכה)
    const fh = frameY1 - frameY0;

    // דפנות ארוכות
    parts.push(part('rail-L', 'דופן ארוכה', { x: 0, y: frameY0, z: t, w: t, h: fh, d: innerL }, { axis: 'x', grain: 'z', material: v.frameMaterial, qtyKey: 'rail-long', edges: { top: true, front: true } }));
    parts.push(part('rail-R', 'דופן ארוכה', { x: W - t, y: frameY0, z: t, w: t, h: fh, d: innerL }, { axis: 'x', grain: 'z', material: v.frameMaterial, qtyKey: 'rail-long', edges: { top: true, front: true } }));
    // רגל המיטה (החזית)
    parts.push(part('rail-foot', 'דופן רגל המיטה', { x: 0, y: frameY0, z: t + innerL, w: W, h: fh, d: t }, { axis: 'z', grain: 'x', material: v.frameMaterial, edges: { top: true, left: true, right: true, front: true } }));
    // ראש המיטה: לוח גבוה במקום דופן אחורית, או דופן רגילה
    if (v.headboardH > 0) {
      parts.push(part('headboard', 'ראש מיטה', { x: 0, y: 0, z: 0, w: W, h: v.headboardH, d: t }, { axis: 'z', grain: 'y', material: v.frameMaterial, edges: { top: true, left: true, right: true, front: true } }));
    } else {
      parts.push(part('rail-head', 'דופן ראש', { x: 0, y: frameY0, z: 0, w: W, h: fh, d: t }, { axis: 'z', grain: 'x', material: v.frameMaterial, edges: { top: true, left: true, right: true } }));
    }
    // פסי תמיכה ללטות לאורך הדפנות הפנימיים
    const supportY = slatTop - v.slatT - 30;
    parts.push(part('ledger-L', 'פס תמיכה ללטות', { x: t, y: supportY, z: t, w: 30, h: 30, d: innerL }, { axis: 'x', grain: 'z', material: v.slatMaterial, qtyKey: 'ledger' }));
    parts.push(part('ledger-R', 'פס תמיכה ללטות', { x: W - t - 30, y: supportY, z: t, w: 30, h: 30, d: innerL }, { axis: 'x', grain: 'z', material: v.slatMaterial, qtyKey: 'ledger' }));
    // קורה מרכזית
    const beamW = 60;
    parts.push(part('beam', 'קורת תמיכה מרכזית', { x: W / 2 - beamW / 2, y: supportY - 30, z: t, w: beamW, h: 60, d: innerL }, { axis: 'x', grain: 'z', material: v.slatMaterial }));
    // לטות
    const pitch = v.slatW + v.slatGap;
    const n = Math.floor((innerL - v.slatGap) / pitch);
    const start = t + (innerL - (n * pitch - v.slatGap)) / 2;
    for (let i = 0; i < n; i++) {
      parts.push(part(`slat-${i + 1}`, 'לטה', { x: t, y: slatTop - v.slatT, z: start + i * pitch, w: innerW, h: v.slatT, d: v.slatW },
        { axis: 'y', grain: 'x', material: v.slatMaterial, qtyKey: 'slat' }));
    }
    // רגליים: בפינות, ואופציונלית באמצע (מתחת לקורה)
    const s = v.legSize, legY1 = supportY;
    const corners = [[t, t], [W - t - s, t], [t, t + innerL - s], [W - t - s, t + innerL - s]];
    if (v.legs === '6') corners.push([W / 2 - s / 2, t], [W / 2 - s / 2, t + innerL - s]);
    corners.forEach(([x, z], i) => parts.push(leg({ id: `leg-${i + 1}`, x, z, y0: 0, y1: legY1, size: s, material: v.legMaterial })));
    hardware.push({ id: 'bed-bolts', kind: 'misc', material: 'hw:leg-adjust', qty: corners.length, note: 'חיבורי מיטה / רגליות' });

    if (v.mattressW > 1400 && v.legs === '4') warnings.push('מיטה רחבה על 4 רגליים — הקורה המרכזית תתכופף; מומלץ 6');
    if (v.frameH > v.mattressH) warnings.push(`דופן המסגרת (${v.frameH}) גבוהה מהמזרן (${v.mattressH}) — המזרן ישקע בתוכה`);
    const H = Math.max(frameY1, v.headboardH);
    return { parts, hardware, warnings, bounds: { w: W, h: H, d: L } };
  },
};
