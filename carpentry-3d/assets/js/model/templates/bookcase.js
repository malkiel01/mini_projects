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
import { resolveShares, sectionLayout, cellsOf } from '../layout.js';
import { handleParam, hingeParam, wheelsParam, addWheels, materialParams, joineryParams, FINISHES, FINISHES_NO_GLASS, applyFinish, sameOrMaterial, resolveSame, columnHeights, fitSides, stepTops, steppedBack, steppedCrown, LIMITS } from './common.js';

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
    { key: 'shelvesPerColumn', label: 'מדפים בכל עמודה', type: 'int', min: 0, max: 15, default: 4, group: 'חלוקה', hint: 'ברירת המחדל; אפשר לשנות לכל עמודה ולכל תא למטה' },
    { key: 'columnsLayout', label: 'עריכת עמודות', type: 'json', default: null, group: 'חלוקה', editor: 'columns' },

    ...materialParams(),
    sameOrMaterial('innerMaterial', 'הגוף הפנימי (מחיצות ומדפים)', 'חומרים', { hint: '"כמו הגוף" = אותו חומר כמו הדפנות החיצוניות' }),

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 80, group: 'סיומות', hint: '0 = ללא סוקל' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 30, group: 'סיומות' },
    { key: 'crownH', label: 'כרכוב — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות', hint: '0 = ללא כרכוב' },
    wheelsParam(),

    { key: 'lowerH', label: 'פיצול העמודות — גובה החלק התחתון', type: 'mm', min: 0, max: 2500, default: 0, group: 'דלתות', hint: '0 = בלי פיצול. ברירת מחדל לכל העמודות — לכל עמודה אפשר אחרת ב"עריכת עמודות". עם פיצול: מדף קבוע בגובה הזה, ודלתות שונות למטה ולמעלה' },
    { key: 'lowerDoors', label: 'דלתות — חלק תחתון (בעמודה מפוצלת)', type: 'enum', default: 'wood', group: 'דלתות', options: DOOR_OPTIONS },
    { key: 'doorType', label: 'דלתות — חלק עליון / כל הגובה', type: 'enum', default: 'none', group: 'דלתות', options: DOOR_OPTIONS },
    { key: 'glassColumns', label: 'עמודות ויטרינה מלאה', type: 'enum', default: 'none', group: 'דלתות', hint: 'עמודה שלמה בדלת ויטרינה, בלי פיצול',
      options: [{ id: 'none', name: 'ללא' }, { id: 'first', name: 'הראשונה' }, { id: 'last', name: 'האחרונה' }, { id: 'ends', name: 'שתי הקיצוניות' }, { id: 'all', name: 'כולן' }] },
    { key: 'doorFinish', label: 'גימור דלתות העץ', type: 'enum', default: 'flat', group: 'דלתות', options: FINISHES_NO_GLASS },
    { key: 'doorMaterial', label: 'חומר הדלתות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'דלתות' },
    { key: 'glassType', label: 'זכוכית', type: 'material', kind: 'glass', default: 'glass:clear-4', group: 'דלתות' },
    hingeParam(), handleParam(),

    { key: 'sideLeftFinish', label: 'דופן שמאל — סוג', type: 'enum', default: 'flat', group: 'דפנות ותאורה', options: FINISHES },
    sameOrMaterial('sideLeftMaterial', 'דופן שמאל — חומר', 'דפנות ותאורה', { showIf: { sideLeftFinish: { not: ['glass'] } } }),
    { key: 'sideRightFinish', label: 'דופן ימין — סוג', type: 'enum', default: 'flat', group: 'דפנות ותאורה', options: FINISHES },
    sameOrMaterial('sideRightMaterial', 'דופן ימין — חומר', 'דפנות ותאורה', { showIf: { sideRightFinish: { not: ['glass'] } } }),
    { key: 'led', label: 'פסי לד', type: 'enum', default: 'none', group: 'דפנות ותאורה',
      options: [{ id: 'none', name: 'ללא' }, { id: 'sides', name: 'אנכיים, בדפנות של כל עמודה' }, { id: 'shelves', name: 'מתחת לכל מדף' }] },
  ],

  // החלטות החיבור — נשאלות בכל מופע, עם ברירת מחדל מהתבנית.
  joinery: joineryParams(),

  /** פרויקטים שנשמרו לפני הפרדת הדפנות: glassSides → סוג דופן, 'fluted' → סטריפים דקים. */
  migrate(v) {
    if (v.glassSides && v.glassSides !== 'none') {
      if (v.glassSides !== 'right') v.sideLeftFinish ??= 'glass';
      if (v.glassSides !== 'left') v.sideRightFinish ??= 'glass';
    }
    delete v.glassSides;
    if (v.doorFinish === 'fluted') v.doorFinish = 'fluted-fine';
    return v;
  },

  /** לעורך החלוקה: קבוצה אחת — העמודות; לכל עמודה הגובה הפנוי לתאים (עמודה מפוצלת לא נערכת). */
  columnSpace(v) {
    const sideT = v.sideT ?? 18, shelfT = v.shelfT ?? 18, columns = v.columns ?? 1;
    const bottomY = Math.max(v.plinthH ?? 0, 0) + shelfT, topY = (v.height ?? 2000) - (v.crownH ?? 0);
    const innerH = topY - shelfT - bottomY;
    const innerW = (v.width ?? 1200) - 2 * sideT - (columns - 1) * sideT;
    const H = v.height ?? 2000;
    const lay = sectionLayout(v.columnsLayout, 'main', columns);
    const heights = columnHeights(lay, columns, H, minColumnH(v));
    const splitOf = (i) => splitFor(lay, i, v.lowerH ?? 0, heights[i] - (v.crownH ?? 0) - bottomY);
    return { sections: [{
      key: 'main', title: '', total: innerW, sizeLabel: 'רוחב', allLabel: 'העמודות', modes: { next: 'מהעמודה שמימין', prev: 'מהעמודה שמשמאל' },
      items: Array.from({ length: columns }, (_, i) => ({ label: `עמודה ${i + 1}`, innerH: innerH - (H - heights[i]), shelfT, cellsOf: 'shelves', defaultCount: v.shelvesPerColumn ?? 0, countMax: 15, editable: !splitOf(i), note: splitOf(i) ? 'העמודה מפוצלת — המדפים מתחלקים בין החלקים לפי הפיצול' : '',
        height: { full: H, min: minColumnH(v) },
        split: { default: v.lowerH ?? 0, max: Math.max(0, heights[i] - (v.crownH ?? 0) - bottomY - 100) } })),
    }] };
  },

  // ספי האזהרות — המשותפים (common.js), שהמנהל עורך במעבדת המוצרים.
  limits: LIMITS,

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

    const innerMat = resolveSame(v.innerMaterial, v.bodyMaterial);
    const shelfMat = v.shelfMaterial === v.bodyMaterial ? innerMat : v.shelfMaterial;   // "חומר המדפים" שלא שונה — עוקב אחרי הפנימי
    const body = carcass({
      w: W, d: bodyD, z: bodyZ, bottomY, topY, sideT, panelT: shelfT,
      sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial,
    });
    parts.push(...body.parts);
    const inner = body.inner;

    // גובה לכל עמודה (ברירת מחדל — גובה הספרייה). `tops` — פני הגג של כל עמודה.
    const layout = sectionLayout(v.columnsLayout, 'main', v.columns);
    const heights = columnHeights(layout, v.columns, H, minColumnH(v));
    const tops = heights.map((h) => h - v.crownH);
    const stepped = tops.some((t) => t !== topY);
    const sidesOverTop = v.sidesOverTop === 'sides';
    if (stepped) fitSides(parts, tops, { panelT: shelfT, sidesOverTop });
    const colY1 = (i) => (stepped ? tops[i] - shelfT : inner.y1);   // תקרת הפנים של העמודה

    // הדפנות החיצוניות: סוג (חלק / סטריפים / חריצים / זכוכית) וחומר לכל צד בנפרד.
    const glassT = material(v.glassType).t || 4;
    let anyGlassSide = false;
    for (const side of ['L', 'R']) {
      const finish = side === 'L' ? v.sideLeftFinish : v.sideRightFinish;
      const mat = resolveSame(side === 'L' ? v.sideLeftMaterial : v.sideRightMaterial, v.bodyMaterial);
      const i = parts.findIndex((p) => p.id === `side-${side}`);
      const s = parts[i];
      if (finish !== 'glass') {
        s.material = mat;
        parts.push(...applyFinish(s, finish, { material: mat, normal: side === 'L' ? '-x' : '+x' }));
        continue;
      }
      anyGlassSide = true;
      const stile = 40;
      const b = s.box;
      parts.splice(i, 1,
        part(`side-${side}-stile-F`, `זקף דופן ${side === 'L' ? 'שמאל' : 'ימין'}`, { x: b.x, y: b.y, z: b.z + b.d - stile, w: b.w, h: b.h, d: stile }, { axis: 'x', grain: 'y', material: v.bodyMaterial, qtyKey: 'side-stile', edges: { front: true } }),
        part(`side-${side}-stile-B`, `זקף דופן ${side === 'L' ? 'שמאל' : 'ימין'}`, { x: b.x, y: b.y, z: b.z, w: b.w, h: b.h, d: stile }, { axis: 'x', grain: 'y', material: v.bodyMaterial, qtyKey: 'side-stile' }),
        part(`side-${side}-glass`, `דופן זכוכית ${side === 'L' ? 'שמאל' : 'ימין'}`, { x: b.x + (b.w - glassT) / 2, y: b.y, z: b.z + stile - 10, w: glassT, h: b.h, d: b.d - 2 * stile + 20 }, { axis: 'x', grain: 'y', material: v.glassType, note: 'בחריץ 10 בזקפים' }),
      );
    }

    // פריסת עמודות: רוחב לכל עמודה (נעוץ או אוטומטי), מספר מדפים לכל עמודה, וגבהי התאים (מלמטה למעלה).
    const widths = resolveShares(inner.x1 - inner.x0 - (v.columns - 1) * sideT, layout.widths);
    const cols = partitions({ inner, columns: v.columns, t: sideT, material: innerMat, widths });
    parts.push(...cols.parts);
    if (stepped) stepTops(parts, cols.cols, tops, { W, panelT: shelfT, sidesOverTop });
    const colShelves = (i) => { const c = layout.cols[i]; return c && Number.isInteger(c.shelves) ? c.shelves : v.shelvesPerColumn; };
    const colGaps = (i, free) => {
      const c = layout.cols[i], n = colShelves(i);
      if (!c || !Array.isArray(c.gaps) || c.gaps.length !== n + 1 || c.gaps.every((g) => g === null)) return null;
      return resolveShares(free, c.gaps);
    };

    const adjustable = v.shelvesMode === 'adjustable';
    const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;
    const isGlassCol = (i) => v.glassColumns === 'all' || (v.glassColumns === 'first' && i === 0) || (v.glassColumns === 'last' && i === cols.cols.length - 1) || (v.glassColumns === 'ends' && (i === 0 || i === cols.cols.length - 1));
    // פיצול לכל עמודה: גובה החלק התחתון (0 = בלי), ברירת מחדל — הגדרת המוצר
    const lowerOf = (i) => splitFor(layout, i, v.lowerH, (stepped ? tops[i] : topY) - bottomY);
    const splitYOf = (i) => bottomY + lowerOf(i);   // פני המדף הקבוע של הפיצול (העליונים)

    cols.cols.forEach((col, i) => {
      const fullGlass = isGlassCol(i);
      const y1c = colY1(i);
      const splitY = splitYOf(i);
      if (lowerOf(i) && !fullGlass) {
        // מדף קבוע בגובה הפיצול, ומדפים מתכווננים בכל חלק לפי חלקו בגובה
        parts.push(...shelves({ col, y0: splitY - shelfT, y1: splitY + shelfT, z0, z1, count: 1, t: shelfT, material: shelfMat, adjustable: false, colIndex: i, prefix: 'split-' }).parts);
        const total = colShelves(i);
        const lowerN = Math.round(total * (lowerOf(i) / (y1c - inner.y0)));
        const upperN = total - lowerN;
        for (const [n, a, b, pre] of [[lowerN, inner.y0, splitY - shelfT, 'lo-'], [upperN, splitY, y1c, 'up-']]) {
          const s = shelves({ col, y0: a, y1: b, z0, z1, count: n, t: shelfT, material: shelfMat, setback: adjustable ? 5 : 0, adjustable, colIndex: i, prefix: pre });
          parts.push(...s.parts);
          if (adjustable) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
        }
      } else {
        const s = shelves({ col, y0: inner.y0, y1: y1c, z0, z1, count: colShelves(i), t: shelfT, material: shelfMat, setback: adjustable ? 5 : 0, adjustable, colIndex: i, gaps: colGaps(i, y1c - inner.y0 - colShelves(i) * shelfT) });
        parts.push(...s.parts);
        if (adjustable) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
      }
      // תאורה
      if (v.led === 'sides') {
        for (const [tag, x] of [['L', col.x0], ['R', col.x1 - 6]]) {
          hardware.push({ id: `led-${i + 1}-${tag}`, kind: 'led', material: 'hw:led-strip', qty: Math.round((y1c - inner.y0) / 100) / 10, pos: [x, inner.y0, z1 - 30], len: y1c - inner.y0, note: `עמודה ${i + 1}, ${tag === 'L' ? 'שמאל' : 'ימין'}` });
        }
      } else if (v.led === 'shelves') {
        for (const p of parts.filter((p) => p.axis === 'y' && p.box.x >= col.x0 - 1 && p.box.x + p.box.w <= col.x1 + 1 && p.id.includes('shelf'))) {
          hardware.push({ id: `led-${p.id}`, kind: 'led', material: 'hw:led-strip', qty: Math.round(p.box.w / 100) / 10, pos: [p.box.x, p.box.y - 4, z1 - 30], len: p.box.w, horizontal: true, note: `מתחת ל${p.name}` });
        }
      }
    });

    if (stepped) parts.push(...steppedBack({ mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, cols: cols.cols, tops, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial, sideT, W, panelT: shelfT }));
    else parts.push(...back({
      mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, t: backT,
      grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial,
    }).parts);

    parts.push(...plinth({ inner, h: v.plinthH, setback: v.plinthSetback, t: sideT, d: D, material: v.bodyMaterial }).parts);
    if (stepped) parts.push(...steppedCrown({ cols: cols.cols, tops, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial, sideT, W }));
    else parts.push(...crown({ w: W, y: topY, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial }).parts);

    // ---- דלתות: לכל עמודה רשימת "קומות" — [y0, y1, סוג] ----
    // הדופן/המחיצה בכל צד של עמודה — לקידוחי הצירים, גם אם הנגר הופך את כיוון הפתיחה
    const mountOf = (i, side) => {
      const mid = side === 'left' ? (i === 0 ? 'side-L' : `partition-${i}`) : (i === v.columns - 1 ? 'side-R' : `partition-${i + 1}`);
      const mp = parts.find((q) => q.id === mid);
      return mp ? { id: mid, y: mp.box.y } : null;
    };
    const doorT = boardThickness(v.doorMaterial);
    let anyDoor = false;
    cols.cols.forEach((col, i) => {
      const zones = [];
      const colTop = stepped ? tops[i] : topY;
      if (isGlassCol(i)) zones.push([v.plinthH, colTop, 'glass']);
      else if (lowerOf(i)) { zones.push([v.plinthH, splitYOf(i), v.lowerDoors]); zones.push([splitYOf(i), colTop, v.doorType]); }
      else zones.push([v.plinthH, colTop, v.doorType]);
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
          // הדופן שהציר נקדח בה: דופן הגוף בקצוות, מחיצה באמצע
          const mountId = hingeSide === 'left' ? (i === 0 ? 'side-L' : `partition-${i}`) : (i === cols.cols.length - 1 ? 'side-R' : `partition-${i + 1}`);
          const mount = parts.find((p) => p.id === mountId);
          const d = door({
            id, name: `דלת ${i + 1}${zoneTag ? (zi === 0 ? ' תחתונה' : ' עליונה') : ''}${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
            x0: lx0, x1: lx1, y0, y1, zFront: D, type, t: doorT,
            material: v.doorMaterial, glass: v.glassType, handle: v.handle === 'none' ? null : v.handle,
            hinge: type === 'glass' && v.hinge === 'hw:hinge-110' ? 'hw:hinge-glass' : v.hinge, hingeSide,
            mountId: mount ? mountId : null, mountBottom: mount ? mount.box.y : null,
            mounts: leaves === 2 ? (k === 0 ? { left: mountOf(i, 'left') } : { right: mountOf(i, 'right') }) : { left: mountOf(i, 'left'), right: mountOf(i, 'right') },
          });
          parts.push(...d.parts);
          hardware.push(...d.hardware);
          // גימור דלת עץ: סטריפים (חלקים שנעים עם הדלת) או חריצים (סימון לתצוגה)
          if (type === 'wood') parts.push(...applyFinish(d.parts[0], v.doorFinish, { material: v.doorMaterial, normal: '+z' }));
        }
      });
      if (leaves === 1 && colW > L.doorWidth) warnings.push(`דלת ${i + 1} ברוחב ${Math.round(colW)} מ"מ — מעבר ל-${L.doorWidth} המומלצים לדלת אחת`);
    });
    const anySplit = cols.cols.some((_, i) => lowerOf(i) > 0);
    const anyGlassDoor = anyDoor && (v.doorType === 'glass' || (anySplit && v.lowerDoors === 'glass') || v.glassColumns !== 'none');
    if (anyGlassDoor && D - doorT < L.glassMinDepth) {
      warnings.push(`עומק ${D} מ"מ קטן מדי לוויטרינה — נדרשים לפחות ${L.glassMinDepth + doorT}`);
    }
    cols.cols.forEach((_, i) => {
      const want = rawSplit(layout, i, v.lowerH);
      if (want > 0 && !lowerOf(i)) warnings.push(`עמודה ${i + 1}: גובה החלק התחתון ${want} גדול מדי לגובה העמודה — הפיצול בוטל`);
    });
    if (anyGlassSide && adjustable) warnings.push('דופן זכוכית: מדפים מתכווננים נשענים על הזקפים בלבד — עדיף מדפים קבועים או מסגרת פנימית');

    // אזהרות על מפתחים וגבהים. אזהרה בלבד — הנגר מחליט.
    const spanLimit = shelfT >= 25 ? L.shelfSpan25 : L.shelfSpan18;
    if (cols.colW > spanLimit && v.shelvesPerColumn > 0) {
      warnings.push(`מדף ברוחב ${Math.round(cols.colW)} מ"מ בעובי ${shelfT} — מעבר ל-${spanLimit} המומלצים ללא תמיכה`);
    }
    if (H > L.heightUnanchored) warnings.push(`גובה ${H} מ"מ — מעל ${L.heightUnanchored} מומלץ עיגון לקיר`);
    if (v.backMode === 'groove' && v.backInset + backT + v.backGrooveDepth > sideT * 3) {
      warnings.push('החריץ לגב רחוק מהקצה יותר מהמקובל — לבדוק מול הנגר');
    }

    const extraD = anyDoor ? doorT + (v.doorFinish.startsWith('fluted') ? (v.doorFinish === 'fluted-wide' ? 10 : 8) : 0) : 0;
    const sideExtra = (f) => (f === 'fluted-wide' ? 10 : f.startsWith('fluted') ? 8 : 0);
    const bounds = { w: W + sideExtra(v.sideLeftFinish) + sideExtra(v.sideRightFinish), h: Math.max(...heights), d: D + extraD };
    // סטריפים על דופן שמאל יוצאים ל-x שלילי — מזיזים הכול ימינה כדי שהגבולות יתחילו ב-0
    const shift = sideExtra(v.sideLeftFinish);
    if (shift) { for (const p of parts) p.box = { ...p.box, x: p.box.x + shift }; for (const h of hardware) if (h.pos) h.pos = [h.pos[0] + shift, h.pos[1], h.pos[2]]; for (const p of parts) if (p.motion && p.motion.kind === 'hinge' && !p.motion.shifted) { p.motion = { ...p.motion, pivot: [p.motion.pivot[0] + shift, p.motion.pivot[1], p.motion.pivot[2]], shifted: true }; } }
    const lift = addWheels(parts, hardware, v, { x0: shift, x1: shift + W, y0: 0, z0: bodyZ, z1: bodyZ + bodyD });
    bounds.h += lift;
    return { parts, hardware, warnings, bounds };
  },
};

function boardThickness(id) { return material(id).t || 18; }
/** הפיצול שנבחר לעמודה (לפני בדיקת התאמה): של העמודה אם נקבע, אחרת ברירת המחדל. */
function rawSplit(layout, i, dflt) { const s = layout?.cols?.[i]?.split; return Number.isFinite(s) && s >= 0 ? s : (dflt ?? 0); }
/** גובה החלק התחתון בעמודה, או 0 אם אין פיצול או שהוא לא נכנס (`room` — מהרצפה עד הגג). */
function splitFor(layout, i, dflt, room) { const s = rawSplit(layout, i, dflt); return s > 0 && s < room - 100 ? s : 0; }
/** הגובה הנמוך ביותר שעמודה יכולה לקבל: סוקל, רצפה, גג, כרכוב ותא של 100. */
function minColumnH(v) { return (v.plinthH ?? 0) + 2 * (v.shelfT ?? 18) + (v.crownH ?? 0) + 100; }
function backThickness(id) { return material(id).t || 6; }
