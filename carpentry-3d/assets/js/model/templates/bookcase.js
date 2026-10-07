// תבנית: ספרייה.
//
// הרכבה קצרה של אבני הבניין, ורשימת פרמטרים כנתונים. הטופס של הנגר נבנה
// מהרשימה הזו אוטומטית — אין כאן טופס כתוב ביד. `build` מקבל את הערכים
// ומחזיר רשימת חלקים, פרזול ואזהרות; הוא לא יודע שקיים מסך.

import { carcass, partitions, shelves, back, plinth, crown, door } from '../blocks.js';
import { material } from '../materials.js';

// פרמטר מסוג 'material' לא מחזיק רשימה: הוא מצביע על סוג (וסינון), והרשימה
// נפתרת ברגע בניית הטופס מהספרייה החיה — כך חומר שנוסף במסך מופיע מיד.

export default {
  key: 'bookcase',
  name: 'ספרייה',
  description: 'גוף עם עמודות ומדפים, סוקל וכרכוב, ודלתות עץ או ויטרינה.',
  // שעות עבודה משוערות לסוג המוצר — בסיס להמלצת המחיר בשלב 5.
  laborHours: 6,

  params: [
    { key: 'width', label: 'רוחב', type: 'mm', min: 300, max: 4000, default: 1200, group: 'מידות' },
    { key: 'height', label: 'גובה', type: 'mm', min: 300, max: 3000, default: 2000, group: 'מידות' },
    { key: 'depth', label: 'עומק', type: 'mm', min: 150, max: 800, default: 350, group: 'מידות' },

    { key: 'columns', label: 'עמודות', type: 'int', min: 1, max: 8, default: 3, group: 'חלוקה' },
    { key: 'shelvesPerColumn', label: 'מדפים בכל עמודה', type: 'int', min: 0, max: 15, default: 4, group: 'חלוקה' },

    { key: 'bodyMaterial', label: 'חומר הגוף', type: 'material', kind: 'board', back: false, default: 'board:melamine-oak-18', group: 'חומרים' },
    { key: 'shelfMaterial', label: 'חומר המדפים', type: 'material', kind: 'board', back: false, default: 'board:melamine-oak-18', group: 'חומרים' },
    { key: 'backMaterial', label: 'חומר הגב', type: 'material', kind: 'board', back: true, default: 'board:back-hdf-6', group: 'חומרים' },
    { key: 'edgeMaterial', label: 'קנט', type: 'material', kind: 'edge', default: 'edge:pvc-1', group: 'חומרים' },

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 80, group: 'סיומות', hint: '0 = ללא סוקל' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 30, group: 'סיומות' },
    { key: 'crownH', label: 'כרכוב — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות', hint: '0 = ללא כרכוב' },

    { key: 'doorType', label: 'דלתות', type: 'enum', default: 'none', group: 'דלתות',
      options: [{ id: 'none', name: 'ללא' }, { id: 'wood', name: 'עץ' }, { id: 'glass', name: 'ויטרינה' }] },
    { key: 'doorHeight', label: 'גובה הדלתות', type: 'mm', min: 0, max: 3000, default: 0, group: 'דלתות', hint: '0 = לכל הגובה', showIf: { doorType: ['wood', 'glass'] } },
    { key: 'doorMaterial', label: 'חומר הדלתות', type: 'material', kind: 'board', back: false, default: 'board:mdf-paint-18', group: 'דלתות', showIf: { doorType: ['wood', 'glass'] } },
    { key: 'glassType', label: 'זכוכית', type: 'material', kind: 'glass', default: 'glass:clear-4', group: 'דלתות', showIf: { doorType: ['glass'] } },
    { key: 'hinge', label: 'צירים', type: 'enum', default: 'hw:hinge-110', group: 'דלתות', showIf: { doorType: ['wood', 'glass'] },
      options: [{ id: 'hw:hinge-110', name: '110°' }, { id: 'hw:hinge-165', name: '165°' }, { id: 'hw:hinge-glass', name: 'לוויטרינה' }] },
    { key: 'handle', label: 'ידיות', type: 'enum', default: 'hw:handle-bar-128', group: 'דלתות', showIf: { doorType: ['wood', 'glass'] },
      options: [{ id: 'none', name: 'ללא (לחיצה)' }, { id: 'hw:handle-bar-128', name: 'מוט 128' }, { id: 'hw:handle-knob', name: 'כפתור' }] },
  ],

  // החלטות החיבור — נשאלות בכל מופע, עם ברירת מחדל מהתבנית.
  joinery: [
    { key: 'sideT', label: 'עובי הדפנות', type: 'mm', min: 12, max: 40, default: 18, group: 'חיבורים' },
    { key: 'shelfT', label: 'עובי המדפים', type: 'mm', min: 12, max: 40, default: 18, group: 'חיבורים' },
    { key: 'sidesOverTop', label: 'דפנות ↔ גג', type: 'enum', default: 'sides', group: 'חיבורים',
      options: [{ id: 'sides', name: 'הדפנות עוברות' }, { id: 'top', name: 'הגג עובר' }] },
    { key: 'backMode', label: 'גב', type: 'enum', default: 'groove', group: 'חיבורים',
      options: [{ id: 'groove', name: 'בחריץ' }, { id: 'overlay', name: 'מולבש מאחור' }, { id: 'none', name: 'ללא' }] },
    { key: 'backGrooveDepth', label: 'עומק החריץ', type: 'mm', min: 4, max: 15, default: 8, group: 'חיבורים', showIf: { backMode: ['groove'] } },
    { key: 'backInset', label: 'החריץ מהקצה האחורי', type: 'mm', min: 5, max: 50, default: 10, group: 'חיבורים', showIf: { backMode: ['groove'] } },
    { key: 'shelvesMode', label: 'מדפים', type: 'enum', default: 'adjustable', group: 'חיבורים',
      options: [{ id: 'adjustable', name: 'מתכווננים (פינים)' }, { id: 'fixed', name: 'קבועים (בחריץ)' }] },
    { key: 'edgeMode', label: 'מידת הקנט', type: 'enum', default: 'subtract', group: 'חיבורים',
      options: [{ id: 'subtract', name: 'יורדת מהמידה' }, { id: 'add', name: 'נוספת למידה' }] },
  ],

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
    // גב מולבש יושב מאחורי הגוף, בתוך העומק הכולל; גב בחריץ יושב בתוך הפנלים.
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

    const cols = partitions({ inner, columns: v.columns, t: sideT, material: v.bodyMaterial });
    parts.push(...cols.parts);

    const adjustable = v.shelvesMode === 'adjustable';
    cols.cols.forEach((col, i) => {
      const s = shelves({
        col, y0: inner.y0, y1: inner.y1, z0: inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1: inner.z1,
        count: v.shelvesPerColumn, t: shelfT, material: v.shelfMaterial, setback: adjustable ? 5 : 0, adjustable, colIndex: i,
      });
      parts.push(...s.parts);
      if (adjustable) {
        s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
      }
    });

    parts.push(...back({
      mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, t: backT,
      grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial,
    }).parts);

    parts.push(...plinth({ inner, h: v.plinthH, setback: v.plinthSetback, t: sideT, d: D, material: v.bodyMaterial }).parts);
    parts.push(...crown({ w: W, y: topY, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial }).parts);

    if (v.doorType !== 'none') {
      const doorT = boardThickness(v.doorMaterial);
      const y0 = v.plinthH, y1 = v.doorHeight > 0 ? Math.min(v.plinthH + v.doorHeight, topY) : topY;
      cols.cols.forEach((col, i) => {
        // הדלת מכסה את העמודה וחצי מחיצה מכל צד (ובדפנות החיצוניות — את כל הדופן).
        const x0 = i === 0 ? 0 : col.x0 - sideT / 2;
        const x1 = i === cols.cols.length - 1 ? W : col.x1 + sideT / 2;
        const colW = x1 - x0;
        const leaves = colW > L.doorWidth ? 2 : 1;
        for (let k = 0; k < leaves; k++) {
          const lx0 = x0 + (colW / leaves) * k, lx1 = lx0 + colW / leaves;
          const hingeSide = leaves === 2 ? (k === 0 ? 'left' : 'right') : (i < cols.cols.length / 2 ? 'left' : 'right');
          const d = door({
            id: `door-${i + 1}${leaves === 2 ? (k === 0 ? 'a' : 'b') : ''}`,
            name: `דלת ${i + 1}${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
            x0: lx0, x1: lx1, y0, y1, zFront: D, type: v.doorType, t: doorT,
            material: v.doorMaterial, glass: v.glassType, handle: v.handle === 'none' ? null : v.handle,
            hinge: v.hinge, hingeSide,
          });
          parts.push(...d.parts);
          hardware.push(...d.hardware);
          if (leaves === 1 && colW > L.doorWidth) warnings.push(`דלת ${i + 1} ברוחב ${Math.round(colW)} מ"מ — מעבר ל-${L.doorWidth} המומלצים לדלת אחת`);
        }
      });
      if (v.doorType === 'glass' && D - doorT < L.glassMinDepth) {
        warnings.push(`עומק ${D} מ"מ קטן מדי לוויטרינה — נדרשים לפחות ${L.glassMinDepth + doorT}`);
      }
    }

    // אזהרות על מפתחים וגבהים. אזהרה בלבד — הנגר מחליט.
    const spanLimit = shelfT >= 25 ? L.shelfSpan25 : L.shelfSpan18;
    if (cols.colW > spanLimit && v.shelvesPerColumn > 0) {
      warnings.push(`מדף ברוחב ${Math.round(cols.colW)} מ"מ בעובי ${shelfT} — מעבר ל-${spanLimit} המומלצים ללא תמיכה`);
    }
    if (H > L.heightUnanchored) warnings.push(`גובה ${H} מ"מ — מעל ${L.heightUnanchored} מומלץ עיגון לקיר`);
    if (v.backMode === 'groove' && v.backInset + backT + v.backGrooveDepth > sideT * 3) {
      warnings.push('החריץ לגב רחוק מהקצה יותר מהמקובל — לבדוק מול הנגר');
    }

    return { parts, hardware, warnings, bounds: { w: W, h: H, d: D + (v.doorType !== 'none' ? boardThickness(v.doorMaterial) : 0) } };
  },
};

function boardThickness(id) { return material(id).t || 18; }
function backThickness(id) { return material(id).t || 6; }
