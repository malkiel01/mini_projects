// תבנית: חיפוי קיר מעץ.
//
// קיר אחד או כמה קירות ברצף (עד שלושה), כל פנייה ב-90° שמאלה או ימינה —
// כך החיפוי "מסתובב" סביב פינה. על כל קיר: לטות רוחב (מסד), ועליהן
// סטריפים אנכיים או לוחות שטוחים (עם חריץ ביניהם). לפי בחירה פנל תחתון
// וקרניז עליון, וגובה חלקי (חיפוי עד גובה, לא עד התקרה).
//
// הצירים: הקיר הראשון לאורך X והחזית ל-+z (אל החדר). פנייה שמאלה: הקיר
// הבא ממשיך לאורך -Z מהקצה; ימינה: לאורך +Z. פניות שאינן 90° (זוויות
// אחרות, קירות מעוגלים) אינן נתמכות — החלקים מקבילים לצירים.

import { part } from '../blocks.js';
import { boardT } from './common.js';

export default {
  key: 'cladding',
  name: 'חיפוי קיר',
  description: 'סטריפים או לוחות על לטות, קיר אחד או כמה עם פינות.',
  laborHours: 10,

  params: [
    { key: 'walls', label: 'מספר קירות', type: 'int', min: 1, max: 3, default: 1, group: 'מידות' },
    { key: 'len1', label: 'אורך קיר 1', type: 'mm', min: 300, max: 12000, default: 3000, group: 'מידות' },
    { key: 'turn2', label: 'פנייה לקיר 2', type: 'enum', default: 'left', group: 'מידות', showIf: { walls: [2, 3] }, options: [{ id: 'left', name: 'שמאלה' }, { id: 'right', name: 'ימינה' }] },
    { key: 'len2', label: 'אורך קיר 2', type: 'mm', min: 300, max: 12000, default: 2000, group: 'מידות', showIf: { walls: [2, 3] } },
    { key: 'turn3', label: 'פנייה לקיר 3', type: 'enum', default: 'left', group: 'מידות', showIf: { walls: [3] }, options: [{ id: 'left', name: 'שמאלה' }, { id: 'right', name: 'ימינה' }] },
    { key: 'len3', label: 'אורך קיר 3', type: 'mm', min: 300, max: 12000, default: 2000, group: 'מידות', showIf: { walls: [3] } },
    { key: 'height', label: 'גובה החיפוי', type: 'mm', min: 300, max: 3500, default: 2500, group: 'מידות', hint: 'עד התקרה, או חלקי (למשל 1000)' },
    { key: 'fromFloor', label: 'התחלה מהרצפה', type: 'mm', min: 0, max: 1500, default: 0, group: 'מידות', hint: '0 = מהרצפה' },

    { key: 'style', label: 'סגנון', type: 'enum', default: 'slats', group: 'עיצוב',
      options: [{ id: 'slats', name: 'סטריפים אנכיים' }, { id: 'panels', name: 'לוחות עם חריץ (V) ביניהם' }, { id: 'flat', name: 'לוחות רצופים' }] },
    { key: 'slatW', label: 'רוחב סטריפ / לוח', type: 'mm', min: 20, max: 1200, default: 40, group: 'עיצוב' },
    { key: 'slatGap', label: 'מרווח / חריץ', type: 'mm', min: 0, max: 200, default: 20, group: 'עיצוב' },
    { key: 'slatT', label: 'עובי', type: 'mm', min: 6, max: 60, default: 20, group: 'עיצוב' },
    { key: 'battenRows', label: 'שורות לטות רוחב', type: 'int', min: 2, max: 8, default: 3, group: 'עיצוב' },
    { key: 'battenT', label: 'עובי הלטות', type: 'mm', min: 12, max: 40, default: 18, group: 'עיצוב' },
    { key: 'baseH', label: 'פנל תחתון — גובה', type: 'mm', min: 0, max: 300, default: 0, group: 'סיומות', hint: '0 = ללא' },
    { key: 'crownH', label: 'קרניז עליון — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות', hint: '0 = ללא' },

    { key: 'slatMaterial', label: 'הסטריפים / הלוחות', type: 'material', kind: 'board', back: false, top: false, default: 'board:solid-oak', group: 'חומרים' },
    { key: 'battenMaterial', label: 'הלטות', type: 'material', kind: 'board', back: false, top: false, default: 'board:melamine-white-18', group: 'חומרים' },
    { key: 'trimMaterial', label: 'פנל וקרניז', type: 'material', kind: 'board', back: false, top: false, default: 'board:solid-oak', group: 'חומרים' },
  ],
  joinery: [
    { key: 'edgeMaterial', label: 'קנט ללוחות', type: 'material', kind: 'edge', default: 'edge:veneer-0.5', group: 'חיבורים' },
    { key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים',
      options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] },
  ],

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const H = v.height, y0 = v.fromFloor, y1 = y0 + H;
    const bt = v.battenT, st = v.slatT;
    const totalT = bt + st;
    // מסלול הקירות: כל קיר = { x, z, len, dir } כש-dir הוא כיוון ההתקדמות ('+x','-z','+z','-x')
    // והחזית תמיד ניצבת לקיר, אל החדר. מתחילים מהראשית, הקיר על z=0, החזית ל-+z.
    const walls = [];
    let x = 0, z = 0, dir = '+x';
    const lens = [v.len1, v.len2, v.len3], turns = [null, v.turn2, v.turn3];
    for (let i = 0; i < v.walls; i++) {
      if (i > 0) {
        // פנייה: שמאלה (מהחדר) = סיבוב נגד כיוון השעון במבט־על. +x → -z → -x → +z → +x
        const cw = ['+x', '+z', '-x', '-z'], ccw = ['+x', '-z', '-x', '+z'];
        const seq = turns[i] === 'left' ? ccw : cw;
        dir = seq[(seq.indexOf(dir) + 1) % 4];
      }
      // פינה פנימית (הקיר פונה אל החדר, כמו פינת חדר) או חיצונית (עוטף עמוד/בליטה):
      // פנימית כשהכיוון החדש הוא כיוון החזית של הקיר הקודם.
      const normal = { '+x': '+z', '-z': '+x', '-x': '-z', '+z': '-x' };
      const inside = i > 0 ? dir === normal[walls[i - 1].dir] : null;
      walls.push({ x, z, len: lens[i], dir, i, inside });
      if (dir === '+x') x += lens[i]; else if (dir === '-x') x -= lens[i]; else if (dir === '+z') z += lens[i]; else z -= lens[i];
    }
    // נירמול: מזיזים הכול כך שהמינימום יהיה 0 (הגבולות חייבים להיות חיוביים)
    let minX = 0, minZ = 0, maxX = 0, maxZ = 0;
    const extent = (w) => {
      const along = w.dir[1], sign = w.dir[0] === '+' ? 1 : -1;
      const ex = along === 'x' ? [w.x, w.x + sign * w.len] : [w.x, w.x + (w.dir === '+z' ? -totalT : totalT)];
      const ez = along === 'z' ? [w.z, w.z + sign * w.len] : [w.z, w.z + (w.dir === '+x' ? totalT : -totalT)];
      return { x0: Math.min(...ex), x1: Math.max(...ex), z0: Math.min(...ez), z1: Math.max(...ez) };
    };
    for (const w of walls) { const e = extent(w); minX = Math.min(minX, e.x0); maxX = Math.max(maxX, e.x1); minZ = Math.min(minZ, e.z0); maxZ = Math.max(maxZ, e.z1); }
    for (const w of walls) { w.x -= minX; w.z -= minZ; }

    /** מציב תיבה על קיר: `u` לאורך הקיר (מתחילתו), `depth` מרחק מהקיר (0 = צמוד), `w` אורך לאורך הקיר, `t` עובי. */
    const place = (w, id, name, u, yA, yB, len, depth, t, opts) => {
      const h = yB - yA;
      let box;
      if (w.dir === '+x') box = { x: w.x + u, y: yA, z: w.z + depth, w: len, h, d: t };
      else if (w.dir === '-x') box = { x: w.x - u - len, y: yA, z: w.z - depth - t, w: len, h, d: t };
      else if (w.dir === '+z') box = { x: w.x - depth - t, y: yA, z: w.z + u, w: t, h, d: len };
      else box = { x: w.x + depth, y: yA, z: w.z - u - len, w: t, h, d: len };
      const alongX = w.dir[1] === 'x';
      return part(id, name, box, { axis: alongX ? 'z' : 'x', grain: opts.grainAlong ? (alongX ? 'x' : 'z') : 'y', ...opts });
    };

    for (const w of walls) {
      const k = w.i + 1;
      // בפינה פנימית הקיר הבא מתחיל אחרי עובי החיפוי של הקודם (לא נכנסים זה לזה);
      // בפינה חיצונית הוא מתחיל לפני הקיר — ומכסה את קצה החיפוי של הקודם.
      const startInset = w.i > 0 ? (w.inside ? totalT : -totalT) : 0;
      const len = w.len - startInset;
      // לטות רוחב
      for (let r = 0; r < v.battenRows; r++) {
        const by = y0 + (H - 60) * (v.battenRows === 1 ? 0.5 : r / (v.battenRows - 1));
        parts.push(place(w, `w${k}-batten-${r + 1}`, 'לטת רוחב', startInset, by, by + 60, len, 0, bt, { material: v.battenMaterial, grainAlong: true, qtyKey: `batten-${Math.round(len)}`, note: `קיר ${k}` }));
      }
      // החיפוי
      const yA = y0 + v.baseH, yB = y1 - v.crownH;
      if (v.style === 'flat') {
        const maxPanel = 1200;
        const n = Math.ceil(len / maxPanel), pw = len / n;
        for (let i = 0; i < n; i++) parts.push(place(w, `w${k}-panel-${i + 1}`, 'לוח חיפוי', startInset + i * pw, yA, yB, pw, bt, st, { material: v.slatMaterial, qtyKey: `panel-${Math.round(pw)}x${Math.round(yB - yA)}`, edges: { top: true, bottom: true }, note: `קיר ${k}` }));
      } else {
        const pitch = v.slatW + v.slatGap;
        const n = Math.max(1, Math.floor((len + v.slatGap) / pitch));
        const used = n * pitch - v.slatGap;
        const start = startInset + (len - used) / 2;   // מרכוז: שוליים שווים בשני הקצוות
        for (let i = 0; i < n; i++) parts.push(place(w, `w${k}-slat-${i + 1}`, v.style === 'slats' ? 'סטריפ' : 'לוח חיפוי', start + i * pitch, yA, yB, v.slatW, bt, st, { material: v.slatMaterial, qtyKey: `slat-${Math.round(v.slatW)}x${Math.round(yB - yA)}`, note: `קיר ${k}` }));
        if (v.style === 'panels' && v.slatGap > 12) warnings.push(`קיר ${k}: חריץ ${v.slatGap} מ"מ בין לוחות — רחב ל-V; 6–12 מקובל`);
      }
      if (v.baseH > 0) parts.push(place(w, `w${k}-base`, 'פנל תחתון', startInset, y0, y0 + v.baseH, len, bt, st, { material: v.trimMaterial, grainAlong: true, qtyKey: `base-${Math.round(len)}`, note: `קיר ${k}` }));
      if (v.crownH > 0) parts.push(place(w, `w${k}-crown`, 'קרניז עליון', startInset, y1 - v.crownH, y1, len, bt, st, { material: v.trimMaterial, grainAlong: true, qtyKey: `crown-${Math.round(len)}`, note: `קיר ${k}` }));
    }
    hardware.push({ id: 'screws', kind: 'misc', material: 'hw:leg-adjust', qty: 0, note: `ברגים ודיבלים: ~${Math.round(walls.reduce((s, w) => s + w.len, 0) / 400) * v.battenRows} לקיבוע הלטות` });
    if (v.style === 'slats' && v.slatW / st > 4) warnings.push(`סטריפ ברוחב ${v.slatW} ובעובי ${st} — דק ליחס; נוטה להתעקם`);
    if (H > 2800) warnings.push(`חיפוי בגובה ${H} — מעל 2800 הסטריפים מתחלקים לשניים (חיבור באמצע הלטה)`);

    return { parts, hardware, warnings, bounds: { w: maxX - minX, h: y1, d: maxZ - minZ } };
  },
};
