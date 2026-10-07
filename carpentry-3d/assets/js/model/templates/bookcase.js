// תבנית: ספרייה.
//
// הרכבה קצרה של אבני הבניין, ורשימת פרמטרים כנתונים. הטופס של הנגר נבנה
// מהרשימה הזו אוטומטית — אין כאן טופס כתוב ביד. `build` מקבל את הערכים
// ומחזיר רשימת חלקים, פרזול ואזהרות; הוא לא יודע שקיים מסך.
//
// הדלתות בשתי "קומות": כל עמודה יכולה להתפצל בגובה נתון — חלק תחתון סגור
// (דלת עץ, חלקה או עם סטריפים) וחלק עליון ויטרינה, למשל. עמודות שלמות
// יכולות להיות ויטרינה מלאה, והדפנות החיצוניות — זכוכית. פסי לד לאורך
// הדפנות הפנימיות.

import { carcass, partitions, shelves, back, plinth, crown, door, part } from '../blocks.js';
import { material } from '../materials.js';
import { materialParams, joineryParams } from './common.js';

const DOOR_OPTIONS = [{ id: 'none', name: 'ללא' }, { id: 'wood', name: 'עץ' }, { id: 'glass', name: 'ויטרינה' }];
const WITH_DOORS = ['wood', 'glass'];

export default {
  key: 'bookcase',
  name: 'ספרייה',
  description: 'עמודות ומדפים; כל עמודה סגורה למטה וויטרינה למעלה, או ויטרינה מלאה; דפנות זכוכית; פסי לד.',
  laborHours: 6,

  params: [
    { key: 'width', label: 'רוחב', type: 'mm', min: 300, max: 4000, default: 1200, group: 'מידות' },
    { key: 'height', label: 'גובה', type: 'mm', min: 300, max: 3000, default: 2000, group: 'מידות' },
    { key: 'depth', label: 'עומק', type: 'mm', min: 150, max: 800, default: 350, group: 'מידות' },

    { key: 'columns', label: 'עמודות', type: 'int', min: 1, max: 8, default: 3, group: 'חלוקה' },
    { key: 'shelvesPerColumn', label: 'מדפים בכל עמודה', type: 'int', min: 0, max: 15, default: 4, group: 'חלוקה' },

    ...materialParams(),

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 80, group: 'סיומות', hint: '0 = ללא סוקל' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 30, group: 'סיומות' },
    { key: 'crownH', label: 'כרכוב — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות', hint: '0 = ללא כרכוב' },

    { key: 'lowerH', label: 'פיצול העמודה — גובה החלק התחתון', type: 'mm', min: 0, max: 2500, default: 0, group: 'דלתות', hint: '0 = בלי פיצול. עם פיצול: מדף קבוע בגובה הזה, ודלתות שונות למטה ולמעלה' },
    { key: 'lowerDoors', label: 'דלתות — חלק תחתון', type: 'enum', default: 'wood', group: 'דלתות', options: DOOR_OPTIONS, showIf: { lowerH: 'gt0' } },
    { key: 'doorType', label: 'דלתות — חלק עליון / כל הגובה', type: 'enum', default: 'none', group: 'דלתות', options: DOOR_OPTIONS },
    { key: 'glassColumns', label: 'עמודות ויטרינה מלאה', type: 'enum', default: 'none', group: 'דלתות', hint: 'עמודה שלמה בדלת ויטרינה, בלי פיצול',
      options: [{ id: 'none', name: 'ללא' }, { id: 'first', name: 'הראשונה' }, { id: 'last', name: 'האחרונה' }, { id: 'ends', name: 'שתי הקיצוניות' }, { id: 'all', name: 'כולן' }] },
    { key: 'doorFinish', label: 'גימור דלתות העץ', type: 'enum', default: 'flat', group: 'דלתות',
      options: [{ id: 'flat', name: 'חלק' }, { id: 'fluted', name: 'סטריפים אנכיים' }] },
    { key: 'doorMaterial', label: 'חומר הדלתות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'דלתות' },
    { key: 'glassType', label: 'זכוכית', type: 'material', kind: 'glass', default: 'glass:clear-4', group: 'דלתות' },
    { key: 'hinge', label: 'צירים', type: 'enum', default: 'hw:hinge-110', group: 'דלתות',
      options: [{ id: 'hw:hinge-110', name: '110°' }, { id: 'hw:hinge-165', name: '165°' }, { id: 'hw:hinge-glass', name: 'לוויטרינה' }] },
    { key: 'handle', label: 'ידיות', type: 'enum', default: 'hw:handle-bar-128', group: 'דלתות',
      options: [{ id: 'none', name: 'ללא (לחיצה)' }, { id: 'hw:handle-bar-128', name: 'מוט 128' }, { id: 'hw:handle-knob', name: 'כפתור' }] },

    { key: 'glassSides', label: 'דפנות זכוכית', type: 'enum', default: 'none', group: 'דפנות ותאורה', hint: 'הדופן החיצונית — שמשה במסגרת, במקום לוח',
      options: [{ id: 'none', name: 'ללא' }, { id: 'left', name: 'שמאל' }, { id: 'right', name: 'ימין' }, { id: 'both', name: 'שתיהן' }] },
    { key: 'led', label: 'פסי לד', type: 'enum', default: 'none', group: 'דפנות ותאורה',
      options: [{ id: 'none', name: 'ללא' }, { id: 'sides', name: 'אנכיים, בדפנות של כל עמודה' }, { id: 'shelves', name: 'מתחת לכל מדף' }] },
  ],

  // החלטות החיבור — נשאלות בכל מופע, עם ברירת מחדל מהתבנית.
  joinery: joineryParams(),

  // ספי האזהרות. יושבים בתבנית ואפשר לדרוס אותם בסוג המוצר.
  limits: { shelfSpan18: 800, shelfSpan25: 1000, doorWidth: 600, heightUnanchored: 2200, glassMinDepth: 250 },

  build(v) {
    const parts = [];
    const hardware = [];
    const warnings = [];
    const L = this.limits;

    const W = v.width, H = v.height, D = v.depth;
    const sideT = v.sideT, shelfT = v.shelfT;
    const backT = v.backMode === 'none' ? 0 : backThickness(v.backMaterial);
    const bodyZ = v.backMode === 'overlay' ? backT : 0;
    const bodyD = D - bodyZ;
    const bottomY = Math.max(v.plinthH, 0) + shelfT;      // פני הרצפה העליונים
    const topY = H - v.crownH;                            // פני הגג העליונים

    const body = carcass({
      w: W, d: bodyD, z: bodyZ, bottomY, topY, sideT, panelT: shelfT,
      sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial,
    });
    parts.push(...body.parts);
    const inner = body.inner;

    // דפנות זכוכית: הדופן הופכת לשמשה בין שני זקפים (חזית ואחור) מחומר הגוף.
    const glassT = material(v.glassType).t || 4;
    for (const side of ['L', 'R']) {
      const on = v.glassSides === 'both' || (v.glassSides === 'left' && side === 'L') || (v.glassSides === 'right' && side === 'R');
      if (!on) continue;
      const i = parts.findIndex((p) => p.id === `side-${side}`);
      const s = parts[i];
      const stile = 40;
      const b = s.box;
      parts.splice(i, 1,
        part(`side-${side}-stile-F`, `זקף דופן ${side === 'L' ? 'שמאל' : 'ימין'}`, { x: b.x, y: b.y, z: b.z + b.d - stile, w: b.w, h: b.h, d: stile }, { axis: 'x', grain: 'y', material: v.bodyMaterial, qtyKey: 'side-stile', edges: { front: true } }),
        part(`side-${side}-stile-B`, `זקף דופן ${side === 'L' ? 'שמאל' : 'ימין'}`, { x: b.x, y: b.y, z: b.z, w: b.w, h: b.h, d: stile }, { axis: 'x', grain: 'y', material: v.bodyMaterial, qtyKey: 'side-stile' }),
        part(`side-${side}-glass`, `דופן זכוכית ${side === 'L' ? 'שמאל' : 'ימין'}`, { x: b.x + (b.w - glassT) / 2, y: b.y, z: b.z + stile - 10, w: glassT, h: b.h, d: b.d - 2 * stile + 20 }, { axis: 'x', grain: 'y', material: v.glassType, note: 'בחריץ 10 בזקפים' }),
      );
    }

    const cols = partitions({ inner, columns: v.columns, t: sideT, material: v.bodyMaterial });
    parts.push(...cols.parts);

    const adjustable = v.shelvesMode === 'adjustable';
    const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;
    const isGlassCol = (i) => v.glassColumns === 'all' || (v.glassColumns === 'first' && i === 0) || (v.glassColumns === 'last' && i === cols.cols.length - 1) || (v.glassColumns === 'ends' && (i === 0 || i === cols.cols.length - 1));
    const split = v.lowerH > 0 && v.lowerH < topY - bottomY - 100;
    const splitY = bottomY + v.lowerH;   // פני המדף הקבוע של הפיצול (העליונים)

    cols.cols.forEach((col, i) => {
      const fullGlass = isGlassCol(i);
      if (split && !fullGlass) {
        // מדף קבוע בגובה הפיצול, ומדפים מתכווננים בכל חלק לפי חלקו בגובה
        parts.push(...shelves({ col, y0: splitY - shelfT, y1: splitY + shelfT, z0, z1, count: 1, t: shelfT, material: v.shelfMaterial, adjustable: false, colIndex: i, prefix: 'split-' }).parts);
        const total = v.shelvesPerColumn;
        const lowerN = Math.round(total * (v.lowerH / (inner.y1 - inner.y0)));
        const upperN = total - lowerN;
        for (const [n, a, b, pre] of [[lowerN, inner.y0, splitY - shelfT, 'lo-'], [upperN, splitY, inner.y1, 'up-']]) {
          const s = shelves({ col, y0: a, y1: b, z0, z1, count: n, t: shelfT, material: v.shelfMaterial, setback: adjustable ? 5 : 0, adjustable, colIndex: i, prefix: pre });
          parts.push(...s.parts);
          if (adjustable) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
        }
      } else {
        const s = shelves({ col, y0: inner.y0, y1: inner.y1, z0, z1, count: v.shelvesPerColumn, t: shelfT, material: v.shelfMaterial, setback: adjustable ? 5 : 0, adjustable, colIndex: i });
        parts.push(...s.parts);
        if (adjustable) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
      }
      // תאורה
      if (v.led === 'sides') {
        for (const [tag, x] of [['L', col.x0], ['R', col.x1 - 6]]) {
          hardware.push({ id: `led-${i + 1}-${tag}`, kind: 'led', material: 'hw:led-strip', qty: Math.round((inner.y1 - inner.y0) / 100) / 10, pos: [x, inner.y0, z1 - 30], len: inner.y1 - inner.y0, note: `עמודה ${i + 1}, ${tag === 'L' ? 'שמאל' : 'ימין'}` });
        }
      } else if (v.led === 'shelves') {
        for (const p of parts.filter((p) => p.axis === 'y' && p.box.x >= col.x0 - 1 && p.box.x + p.box.w <= col.x1 + 1 && p.id.includes('shelf'))) {
          hardware.push({ id: `led-${p.id}`, kind: 'led', material: 'hw:led-strip', qty: Math.round(p.box.w / 100) / 10, pos: [p.box.x, p.box.y - 4, z1 - 30], len: p.box.w, horizontal: true, note: `מתחת ל${p.name}` });
        }
      }
    });

    parts.push(...back({
      mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, t: backT,
      grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial,
    }).parts);

    parts.push(...plinth({ inner, h: v.plinthH, setback: v.plinthSetback, t: sideT, d: D, material: v.bodyMaterial }).parts);
    parts.push(...crown({ w: W, y: topY, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial }).parts);

    // ---- דלתות: לכל עמודה רשימת "קומות" — [y0, y1, סוג] ----
    const doorT = boardThickness(v.doorMaterial);
    let anyDoor = false;
    cols.cols.forEach((col, i) => {
      const zones = [];
      if (isGlassCol(i)) zones.push([v.plinthH, topY, 'glass']);
      else if (split) { zones.push([v.plinthH, splitY, v.lowerDoors]); zones.push([splitY, topY, v.doorType]); }
      else zones.push([v.plinthH, topY, v.doorType]);
      // הדלת מכסה את העמודה וחצי מחיצה מכל צד (ובדפנות החיצוניות — את כל הדופן).
      const x0 = i === 0 ? 0 : col.x0 - sideT / 2;
      const x1 = i === cols.cols.length - 1 ? W : col.x1 + sideT / 2;
      const colW = x1 - x0;
      const leaves = colW > L.doorWidth ? 2 : 1;
      zones.forEach(([y0, y1, type], zi) => {
        if (type === 'none') return;
        anyDoor = true;
        const zoneTag = zones.length > 1 ? (zi === 0 ? 'lo' : 'up') : '';
        for (let k = 0; k < leaves; k++) {
          const lx0 = x0 + (colW / leaves) * k, lx1 = lx0 + colW / leaves;
          const hingeSide = leaves === 2 ? (k === 0 ? 'left' : 'right') : (i < cols.cols.length / 2 ? 'left' : 'right');
          const id = `door-${i + 1}${zoneTag ? '-' + zoneTag : ''}${leaves === 2 ? 'ab'[k] : ''}`;
          const d = door({
            id, name: `דלת ${i + 1}${zoneTag ? (zi === 0 ? ' תחתונה' : ' עליונה') : ''}${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
            x0: lx0, x1: lx1, y0, y1, zFront: D, type, t: doorT,
            material: v.doorMaterial, glass: v.glassType, handle: v.handle === 'none' ? null : v.handle,
            hinge: type === 'glass' && v.hinge === 'hw:hinge-110' ? 'hw:hinge-glass' : v.hinge, hingeSide,
          });
          parts.push(...d.parts);
          hardware.push(...d.hardware);
          // סטריפים על דלת עץ: פסים אנכיים דקים, נעים עם הדלת
          if (type === 'wood' && v.doorFinish === 'fluted') {
            const face = d.parts[0];
            const sw = 16, gap = 8, st = 8;
            const n = Math.floor((face.box.w + gap) / (sw + gap));
            const start = face.box.x + (face.box.w - (n * (sw + gap) - gap)) / 2;
            for (let s = 0; s < n; s++) {
              const strip = part(`${id}-flute-${s + 1}`, 'סטריפ דלת', { x: start + s * (sw + gap), y: face.box.y, z: face.box.z + face.box.d, w: sw, h: face.box.h, d: st },
                { axis: 'z', grain: 'y', material: v.doorMaterial, qtyKey: `flute-${Math.round(face.box.h)}`, note: face.note });
              strip.motion = face.motion;
              parts.push(strip);
            }
          }
        }
      });
      if (leaves === 1 && colW > L.doorWidth) warnings.push(`דלת ${i + 1} ברוחב ${Math.round(colW)} מ"מ — מעבר ל-${L.doorWidth} המומלצים לדלת אחת`);
    });
    const anyGlassDoor = anyDoor && (v.doorType === 'glass' || (split && v.lowerDoors === 'glass') || v.glassColumns !== 'none');
    if (anyGlassDoor && D - doorT < L.glassMinDepth) {
      warnings.push(`עומק ${D} מ"מ קטן מדי לוויטרינה — נדרשים לפחות ${L.glassMinDepth + doorT}`);
    }
    if (v.lowerH > 0 && !split) warnings.push(`גובה החלק התחתון ${v.lowerH} גדול מדי לגובה הספרייה — הפיצול בוטל`);
    if (v.glassSides !== 'none' && adjustable) warnings.push('דופן זכוכית: מדפים מתכווננים נשענים על הזקפים בלבד — עדיף מדפים קבועים או מסגרת פנימית');

    // אזהרות על מפתחים וגבהים. אזהרה בלבד — הנגר מחליט.
    const spanLimit = shelfT >= 25 ? L.shelfSpan25 : L.shelfSpan18;
    if (cols.colW > spanLimit && v.shelvesPerColumn > 0) {
      warnings.push(`מדף ברוחב ${Math.round(cols.colW)} מ"מ בעובי ${shelfT} — מעבר ל-${spanLimit} המומלצים ללא תמיכה`);
    }
    if (H > L.heightUnanchored) warnings.push(`גובה ${H} מ"מ — מעל ${L.heightUnanchored} מומלץ עיגון לקיר`);
    if (v.backMode === 'groove' && v.backInset + backT + v.backGrooveDepth > sideT * 3) {
      warnings.push('החריץ לגב רחוק מהקצה יותר מהמקובל — לבדוק מול הנגר');
    }

    const extraD = anyDoor ? doorT + (v.doorFinish === 'fluted' ? 8 : 0) : 0;
    return { parts, hardware, warnings, bounds: { w: W, h: H, d: D + extraD } };
  },
};

function boardThickness(id) { return material(id).t || 18; }
function backThickness(id) { return material(id).t || 6; }
