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

import { carcass, partitions, shelves, back, plinth, crown, door, drawer, part } from '../blocks.js';
import { material } from '../materials.js';
import { resolveShares, sectionLayout, cellsOf } from '../layout.js';
import { handleParam, hingeParam, wheelsParam, addWheels, materialParams, joineryParams, FINISHES, FINISHES_NO_GLASS, applyFinish, sameOrMaterial, resolveSame, columnHeights, fitSides, stepTops, steppedBack, steppedCrown, LIMITS } from './common.js';

const DOOR_OPTIONS = [{ id: 'none', name: 'ללא' }, { id: 'wood', name: 'עץ' }, { id: 'glass', name: 'ויטרינה' }];
const WITH_DOORS = ['wood', 'glass'];
/** יש דלת ויטרינה כלשהי — למעלה / כל הגובה, למטה (בפיצול), או עמודת ויטרינה מלאה. */
const anyGlass = (v) => v.doorType === 'glass' || v.lowerDoors === 'glass' || (v.glassColumns && v.glassColumns !== 'none');

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
    { legacy: true, key: 'shelvesPerColumn', label: 'מדפים בכל עמודה', type: 'int', min: 0, max: 15, default: 4, group: 'חלוקה', hint: 'ברירת המחדל; אפשר לשנות לכל עמודה ולכל תא למטה' },
    // המבנה לפי עמודה וקומה (docs/elements-spec.md). ריק — נגזר מההגדרות הישנות בכל בנייה.
    { key: 'newUnit', label: 'עמודה שנוספת', type: 'enum', default: 'last', group: 'חלוקה', hint: 'כשמגדילים את מספר העמודות (או "＋ עמודה" בכרטיסים)',
      showIf: (v) => Array.isArray(v.units) && v.units.length > 0, showIfText: 'יש עמודות שהוגדרו',
      options: [{ id: 'last', name: 'העתק של העמודה האחרונה' }, { id: 'open', name: 'פתוחה — מדפים' }, { id: 'wood', name: 'דלת עץ' }, { id: 'glass', name: 'ויטרינה' }] },
    { key: 'units', label: 'עמודות וקומות', type: 'json', default: null, group: 'חלוקה', editor: 'units' },
    { legacy: true, key: 'columnsLayout', label: 'עריכת עמודות', type: 'json', default: null, group: 'חלוקה', editor: 'columns' },

    ...materialParams(),
    sameOrMaterial('innerMaterial', 'הגוף הפנימי (מחיצות ומדפים)', 'חומרים', { hint: '"כמו הגוף" = אותו חומר כמו הדפנות החיצוניות' }),

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 80, group: 'סיומות', hint: '0 = ללא סוקל' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 30, group: 'סיומות' },
    { key: 'crownH', label: 'כרכוב — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות', hint: '0 = ללא כרכוב' },
    wheelsParam(),

    { legacy: true, key: 'lowerH', label: 'פיצול העמודות — גובה החלק התחתון', type: 'mm', min: 0, max: 2500, default: 0, group: 'דלתות', hint: '0 = בלי פיצול. ברירת מחדל לכל העמודות — לכל עמודה אפשר אחרת ב"עריכת עמודות". עם פיצול: מדף קבוע בגובה הזה, ודלתות שונות למטה ולמעלה' },
    { legacy: true, key: 'lowerDoors', label: 'דלתות — חלק תחתון (בעמודה מפוצלת)', type: 'enum', default: 'wood', group: 'דלתות', options: DOOR_OPTIONS },
    { legacy: true, key: 'doorType', label: 'דלתות — חלק עליון / כל הגובה', type: 'enum', default: 'none', group: 'דלתות', options: DOOR_OPTIONS },
    { legacy: true, key: 'glassColumns', label: 'עמודות ויטרינה מלאה', type: 'enum', default: 'none', group: 'דלתות', hint: 'עמודה שלמה בדלת ויטרינה, בלי פיצול',
      options: [{ id: 'none', name: 'ללא' }, { id: 'first', name: 'הראשונה' }, { id: 'last', name: 'האחרונה' }, { id: 'ends', name: 'שתי הקיצוניות' }, { id: 'all', name: 'כולן' }] },
    { legacy: true, key: 'doorFinish', label: 'גימור דלתות העץ', type: 'enum', default: 'flat', group: 'דלתות', options: FINISHES_NO_GLASS },
    { legacy: true, key: 'doorMaterial', label: 'חומר הדלתות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'דלתות' },
    { legacy: true, key: 'glassType', label: 'זכוכית', type: 'material', kind: 'glass', default: 'glass:clear-4', group: 'דלתות' },
    hingeParam({ legacy: true }), handleParam({ legacy: true }),

    // ויטרינה — מוצג רק כשיש דלת זכוכית כלשהי (למעלה, למטה, או עמודת ויטרינה מלאה)
    { legacy: true, key: 'vitrineFrame', label: 'מסגרת הוויטרינה', type: 'enum', default: 'wood', group: 'ויטרינה', showIf: anyGlass, showIfText: 'יש דלת ויטרינה',
      options: [{ id: 'wood', name: 'מסגרת עץ / MDF' }, { id: 'alu', name: 'פרופיל אלומיניום' }, { id: 'none', name: 'זכוכית בלבד (בלי מסגרת)' }],
      hint: 'בלי מסגרת — זכוכית מחוסמת 6 לפחות, וצירי זכוכית' },
    { legacy: true, key: 'vitrineSides', label: 'כמה צדדים למסגרת', type: 'enum', default: '4', group: 'ויטרינה', showIf: (v) => anyGlass(v) && v.vitrineFrame !== 'none', showIfText: 'יש ויטרינה עם מסגרת',
      options: [{ id: '4', name: '4 צדדים — מסגרת מלאה' }, { id: '2v', name: '2 צדדים — ימין ושמאל (זקפים)' }, { id: '2h', name: '2 צדדים — למעלה ולמטה (קושרות)' }],
      hint: 'בצד בלי מסגרת הזכוכית מגיעה עד הקצה, מלוטשת. בלי זקף בצד הציר — צירי זכוכית' },
    { legacy: true, key: 'vitrineFrameW', label: 'רוחב פס המסגרת', type: 'mm', min: 10, max: 120, default: 60, group: 'ויטרינה', showIf: (v) => anyGlass(v) && v.vitrineFrame !== 'none', showIfText: 'יש ויטרינה עם מסגרת',
      hint: 'הרוחב שנראה מלפנים. אלומיניום דק — 20; עץ קלאסי — 60' },
    { legacy: true, key: 'vitrineProfile', label: 'פרופיל המסגרת', type: 'enum', default: 'flat', group: 'ויטרינה', showIf: (v) => anyGlass(v) && v.vitrineFrame === 'wood', showIfText: 'מסגרת עץ',
      options: [{ id: 'flat', name: 'ישר' }, { id: 'shaker', name: 'שייקר — פס פנימי מדורג' }, { id: 'bevel', name: 'פאזה פנימית 45°' }, { id: 'round', name: 'קנט פנימי מעוגל' }] },
    sameOrMaterial('vitrineFrameMaterial', 'חומר / צבע המסגרת', 'ויטרינה', { legacy: true, showIf: (v) => anyGlass(v) && v.vitrineFrame === 'wood', showIfText: 'מסגרת עץ', hint: '"כמו הגוף" = כמו חומר הדלתות' }),
    { legacy: true, key: 'vitrineAlu', label: 'צבע הפרופיל', type: 'material', kind: 'profile', default: 'profile:alu-black', group: 'ויטרינה', showIf: (v) => anyGlass(v) && v.vitrineFrame === 'alu', showIfText: 'פרופיל אלומיניום' },
    { legacy: true, key: 'vitrineMullions', label: 'חלוקת הזכוכית (שפרוסים)', type: 'enum', default: 'none', group: 'ויטרינה', showIf: (v) => anyGlass(v) && v.vitrineFrame !== 'none', showIfText: 'יש ויטרינה עם מסגרת',
      options: [{ id: 'none', name: 'ללא — שמשה אחת' }, { id: 'v2', name: 'פס אנכי באמצע' }, { id: 'h2', name: 'פס אופקי באמצע' }, { id: 'h3', name: 'שני פסים אופקיים (3 שמשות)' }, { id: 'grid4', name: 'שתי וערב (4 שמשות)' }, { id: 'grid6', name: '6 שמשות' }] },
    { legacy: true, key: 'vitrineShelves', label: 'מדפים בוויטרינה', type: 'enum', default: 'same', group: 'ויטרינה', showIf: anyGlass, showIfText: 'יש דלת ויטרינה',
      options: [{ id: 'same', name: 'כמו שאר המדפים' }, { id: 'glass', name: 'מדפי זכוכית' }] },
    { legacy: true, key: 'vitrineShelfGlass', label: 'זכוכית המדפים', type: 'material', kind: 'glass', default: 'glass:shelf-8', group: 'ויטרינה', showIf: (v) => anyGlass(v) && v.vitrineShelves === 'glass', showIfText: 'מדפי זכוכית' },

    { key: 'sideLeftFinish', label: 'דופן שמאל — סוג', type: 'enum', default: 'flat', group: 'דפנות ותאורה', options: FINISHES },
    sameOrMaterial('sideLeftMaterial', 'דופן שמאל — חומר', 'דפנות ותאורה', { showIf: { sideLeftFinish: { not: ['glass'] } } }),
    { key: 'sideRightFinish', label: 'דופן ימין — סוג', type: 'enum', default: 'flat', group: 'דפנות ותאורה', options: FINISHES },
    sameOrMaterial('sideRightMaterial', 'דופן ימין — חומר', 'דפנות ותאורה', { showIf: { sideRightFinish: { not: ['glass'] } } }),
    { key: 'led', label: 'פסי לד', type: 'enum', default: 'none', group: 'דפנות ותאורה',
      options: [{ id: 'none', name: 'ללא' }, { id: 'sides', name: 'אנכיים, בדפנות של כל עמודה' }, { id: 'shelves', name: 'מתחת לכל מדף' }] },
  ],

  // החלטות החיבור — נשאלות בכל מופע, עם ברירת מחדל מהתבנית.
  // מדפים קבועים/מתכווננים — לכל קומה בכרטיס העמודה (ההגדרה הכללית רק לפרויקטים שעוד לא הומרו)
  joinery: joineryParams({ autoT: true }).map((p) => (p.key === 'shelvesMode' ? { ...p, legacy: true } : p)),

  /** פרויקטים שנשמרו לפני הפרדת הדפנות: glassSides → סוג דופן, 'fluted' → סטריפים דקים. */
  migrate(v) {
    if (v.glassSides && v.glassSides !== 'none') {
      if (v.glassSides !== 'right') v.sideLeftFinish ??= 'glass';
      if (v.glassSides !== 'left') v.sideRightFinish ??= 'glass';
    }
    delete v.glassSides;
    if (v.doorFinish === 'fluted') v.doorFinish = 'fluted-fine';
    // עובי שנשמר כמספר ושווה לעובי החומר → "כעובי החומר" (אותה בנייה; מעכשיו משתנה עם החומר)
    for (const [k, m] of [['sideT', v.bodyMaterial], ['shelfT', v.shelfMaterial ?? v.bodyMaterial]]) if (v[k] != null && m && Number(v[k]) === boardThickness(m)) v[k] = null;
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

  /**
   * לאיזו עמודה וקומה שייך רכיב (לפתיחת כרטיס הקומה מהתלת מימד). null — רכיב של המוצר
   * כולו (דופן, גב, סוקל…). `n` — מספר הקומות בכל עמודה (units).
   */
  locate(id, units) {
    const nOf = (i) => units?.[i]?.floors?.length || 1;
    let m = /^drawer-(\d+)-(\d+)-\d+/.exec(id);
    if (m) return { unit: +m[1] - 1, floor: +m[2] - 1 };
    m = /^door-(\d+)(?:-(lo|up|f\d+))?[ab]?(?:-|$)/.exec(id);
    if (m) { const i = +m[1] - 1, tag = m[2]; return { unit: i, floor: !tag ? 0 : tag === 'lo' ? 0 : tag === 'up' ? nOf(i) - 1 : +tag.slice(1) - 1 }; }
    m = /^(?:(lo|up|f\d+)-)?shelf-(\d+)-\d+/.exec(id);
    if (m) { const i = +m[2] - 1, tag = m[1]; return { unit: i, floor: !tag ? 0 : tag === 'lo' ? 0 : tag === 'up' ? nOf(i) - 1 : +tag.slice(1) - 1 }; }
    m = /^split(\d*)-shelf-(\d+)-/.exec(id);
    if (m) return { unit: +m[2] - 1, floor: m[1] ? +m[1] : 1 };
    m = /^partition-(\d+)/.exec(id);
    if (m) return { unit: +m[1] - 1, floor: null };
    return null;
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
    const saved = validUnits(v.units, v.columns, v);
    const heights = columnHeights(saved ? { cols: Object.fromEntries(saved.map((u, i) => [i, { height: u.height }])) } : layout, v.columns, H, minColumnH(v));
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

    // ---- עמודות: כל עמודה נבנית מ-units[i] בלבד (docs/elements-spec.md) ----
    // פרויקט שאין לו עדיין units — מומר כאן מההגדרות הישנות, כך שנבנה בדיוק כמו קודם.
    const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;
    const geo = { inner, bottomY, topY, tops, stepped, colY1, shelfT };
    const units = saved || migrateUnits(v, layout, geo);
    const widths = resolveShares(inner.x1 - inner.x0 - (v.columns - 1) * sideT, units.map((u) => (Number.isFinite(u.width) && u.width > 0 ? u.width : null)));
    const cols = partitions({ inner, columns: v.columns, t: sideT, material: innerMat, widths });
    parts.push(...cols.parts);
    if (stepped) stepTops(parts, cols.cols, tops, { W, panelT: shelfT, sidesOverTop });

    // גבולות הקומות בעמודה: b[j] = פני המדף הקבוע שמתחת לקומה j (b[0] = רצפת הגוף)
    const floorsOf = (i) => {
      const u = units[i], fl = u.floors;
      const room = (stepped ? tops[i] : topY) - bottomY;
      const hs = fl.length > 1 ? floorHeights(room, fl.map((f) => (Number.isFinite(f.height) && f.height > 0 ? f.height : null))) : [room];
      const b = [bottomY];
      for (let j = 1; j < fl.length; j++) b.push(b[j - 1] + hs[j - 1]);
      return { fl, b };
    };
    const shelfPrefix = (n, j) => (n === 1 ? '' : n === 2 ? ['lo-', 'up-'][j] : `f${j + 1}-`);
    const splitPrefix = (n, j) => (n === 2 ? 'split-' : `split${j}-`);

    cols.cols.forEach((col, i) => {
      const y1c = colY1(i);
      const { fl, b } = floorsOf(i);
      // מדף קבוע בגבול בין קומות
      for (let j = 1; j < fl.length; j++) {
        parts.push(...shelves({ col, y0: b[j] - shelfT, y1: b[j] + shelfT, z0, z1, count: 1, t: shelfT, material: shelfMat, adjustable: false, colIndex: i, prefix: splitPrefix(fl.length, j) }).parts);
      }
      fl.forEach((f, j) => {
        const sh = f.interior?.type === 'shelves' ? f.interior.shelves || {} : null;
        if (!sh) return;
        const adj = (sh.mode || v.shelvesMode) === 'adjustable';
        const a = j === 0 ? inner.y0 : b[j], top = j === fl.length - 1 ? y1c : b[j + 1] - shelfT;
        const n = Math.max(0, Math.round(sh.count ?? 0));
        const gaps = fl.length === 1 && Array.isArray(sh.gaps) && sh.gaps.length === n + 1 && !sh.gaps.every((g) => g === null) ? resolveShares(top - a - n * shelfT, sh.gaps) : null;
        const glassShelf = sh.material && material(sh.material).kind === 'glass' ? sh.material : null;
        const mat = !sh.material || sh.material === 'same' || glassShelf ? shelfMat : sh.material;
        const s = shelves({ col, y0: a, y1: top, z0, z1, count: n, t: shelfT, material: mat, setback: adj ? 5 : 0, adjustable: adj, colIndex: i, prefix: shelfPrefix(fl.length, j), gaps });
        if (glassShelf) {
          // מדף זכוכית (מאחורי ויטרינה): אותו מיקום, בעובי הזכוכית
          const gt = material(glassShelf).t || 8;
          for (const p of s.parts) { p.material = glassShelf; p.box = { ...p.box, h: gt }; p.edges = {}; p.note = 'מדף זכוכית מחוסם, מלוטש — על תומכי זכוכית'; p.qtyKey = `glass-shelf-${Math.round(p.box.w)}`; }
        }
        parts.push(...s.parts);
        if (adj) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
      });
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

    // ---- דלתות: לכל קומה בכל עמודה — לפי החזית שלה ----
    // הדופן/המחיצה בכל צד של עמודה — לקידוחי הצירים, גם אם הנגר הופך את כיוון הפתיחה
    const mountOf = (i, side) => {
      const mid = side === 'left' ? (i === 0 ? 'side-L' : `partition-${i}`) : (i === v.columns - 1 ? 'side-R' : `partition-${i + 1}`);
      const mp = parts.find((q) => q.id === mid);
      return mp ? { id: mid, y: mp.box.y } : null;
    };
    let anyDoor = false, maxFront = 0;
    const glassFronts = [];
    cols.cols.forEach((col, i) => {
      const { fl, b } = floorsOf(i);
      const colTop = stepped ? tops[i] : topY;
      // הדלת מכסה את העמודה וחצי מחיצה מכל צד (ובדפנות החיצוניות — את כל הדופן).
      const x0 = i === 0 ? 0 : col.x0 - sideT / 2;
      const x1 = i === cols.cols.length - 1 ? W : col.x1 + sideT / 2;
      const colW = x1 - x0;
      let narrowWide = false;
      fl.forEach((f, zi) => {
        const fr = f.front || {};
        const type = fr.type || 'none';
        const y0 = zi === 0 ? v.plinthH : b[zi], y1 = zi === fl.length - 1 ? colTop : b[zi + 1];
        // מגירות כתוכן הקומה: בלי חזית — חזיתות גלויות (כמו דלת: עמודה + חצי מחיצה);
        // מאחורי דלת — מגירות פנימיות: החזית בתוך הפתח, נסוגה מאחורי הדלת, בלי ידית
        if (f.interior?.type === 'drawers') {
          const dr = f.interior.drawers || {};
          const inner = type !== 'none';
          const count = Math.max(1, Math.round(dr.count ?? 3));
          const fm = dr.frontMaterial || fr.material || v.doorMaterial;
          const frontT = boardThickness(fm);
          const boxA = zi === 0 ? bottomY : b[zi], boxB = zi === fl.length - 1 ? colY1(i) : b[zi + 1] - shelfT;
          const fa = inner ? boxA : y0, fb = inner ? boxB : y1;
          const hs = resolveShares(fb - fa, Array.from({ length: count }, (_, k) => (Number.isFinite(dr.heights?.[k]) && dr.heights[k] > 0 ? dr.heights[k] : null)), 60);
          const zFront = inner ? D - frontT - 22 : D;   // פנימית: מרווח לזרועות הצירים של הדלת
          const depth = Math.min(zFront - z0 - 20, 550);
          let ry = fa;
          hs.forEach((h, k) => {
            const fy0 = ry, fy1 = ry + h; ry = fy1;
            const d = drawer({
              id: `drawer-${i + 1}-${zi + 1}-${k + 1}`, name: `מגירה ${i + 1}.${k + 1}${fl.length > 1 ? ` (קומה ${zi + 1})` : ''}${inner ? ' — פנימית' : ''}`,
              x0: inner ? col.x0 : x0, x1: inner ? col.x1 : x1, y0: fy0, y1: fy1, boxX0: col.x0, boxX1: col.x1,
              boxY0: Math.max(boxA, fy0), boxY1: Math.min(boxB, fy1),
              zFront, depth, frontT, boxT: boardThickness(dr.boxMaterial || 'board:melamine-white-18'), bottomT: backThickness(dr.bottomMaterial || 'board:back-hdf-6'),
              frontMaterial: fm, boxMaterial: dr.boxMaterial || 'board:melamine-white-18', bottomMaterial: dr.bottomMaterial || 'board:back-hdf-6',
              slide: dr.slide || 'hw:slide-std', handle: inner || !dr.handle || dr.handle === 'none' ? null : dr.handle,
              mountIds: [i === 0 ? 'side-L' : `partition-${i}`, i === cols.cols.length - 1 ? 'side-R' : `partition-${i + 1}`], mountBottom: inner ? boxA : bottomY,
            });
            if (!inner && dr.finish) parts.push(...applyFinish(d.parts[0], dr.finish, { material: fm, normal: '+z' }));
            parts.push(...d.parts); hardware.push(...d.hardware);
            if (!inner) maxFront = Math.max(maxFront, frontT + fluteDepth(dr.finish));
          });
          if (col.x1 - col.x0 > L.drawerMaxWidth) warnings.push(`עמודה ${i + 1}: מגירה ברוחב ${Math.round(col.x1 - col.x0)} מ"מ — מעבר ל-${L.drawerMaxWidth} המומלצים`);
        }
        if (type === 'none') return;
        anyDoor = true;
        const leaves = fr.leaves === 1 || fr.leaves === 2 ? fr.leaves : colW > L.doorWidth ? 2 : 1;
        if (leaves === 1 && colW > L.doorWidth) narrowWide = true;
        const doorT = boardThickness(fr.material);
        maxFront = Math.max(maxFront, doorT + fluteDepth(fr.finish));
        if (type === 'glass') glassFronts.push({ fr, doorT });
        const zoneTag = fl.length === 1 ? '' : fl.length === 2 ? (zi === 0 ? 'lo' : 'up') : `f${zi + 1}`;
        const zoneName = fl.length === 1 ? '' : fl.length === 2 ? (zi === 0 ? ' תחתונה' : ' עליונה') : ` קומה ${zi + 1}`;
        const vt = fr.vitrine || {};
        const vitrine = type === 'glass' ? { ...vt, frameMaterial: vt.frame === 'alu' ? vt.alu : resolveSame(vt.frameMaterial, fr.material) } : null;
        for (let k = 0; k < leaves; k++) {
          const lx0 = x0 + (colW / leaves) * k, lx1 = lx0 + colW / leaves;
          const auto = leaves === 2 ? (k === 0 ? 'left' : 'right') : (i < cols.cols.length / 2 ? 'left' : 'right');
          const hingeSide = ['left', 'right', 'top', 'bottom'].includes(fr.hingeSide) ? fr.hingeSide : auto;
          const id = `door-${i + 1}${zoneTag ? '-' + zoneTag : ''}${leaves === 2 ? 'ab'[k] : ''}`;
          // הדופן שהציר נקדח בה: דופן הגוף בקצוות, מחיצה באמצע
          const mountId = hingeSide === 'left' ? (i === 0 ? 'side-L' : `partition-${i}`) : (i === cols.cols.length - 1 ? 'side-R' : `partition-${i + 1}`);
          const mount = parts.find((p) => p.id === mountId);
          const d = door({
            id, name: `דלת ${i + 1}${zoneName}${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
            x0: lx0, x1: lx1, y0, y1, zFront: D, type, t: doorT,
            material: fr.material, glass: fr.glass, handle: !fr.handle || fr.handle === 'none' ? null : fr.handle,
            vitrine,
            hinge: type === 'glass' && fr.hinge === 'hw:hinge-110' ? 'hw:hinge-glass' : fr.hinge, hingeSide,
            mountId: mount ? mountId : null, mountBottom: mount ? mount.box.y : null,
            mounts: leaves === 2 ? (k === 0 ? { left: mountOf(i, 'left') } : { right: mountOf(i, 'right') }) : { left: mountOf(i, 'left'), right: mountOf(i, 'right') },
          });
          parts.push(...d.parts);
          hardware.push(...d.hardware);
          // גימור דלת עץ: סטריפים (חלקים שנעים עם הדלת) או חריצים (סימון לתצוגה)
          if (type === 'wood') parts.push(...applyFinish(d.parts[0], fr.finish, { material: fr.material, normal: '+z' }));
        }
      });
      if (narrowWide) warnings.push(`דלת ${i + 1} ברוחב ${Math.round(colW)} מ"מ — מעבר ל-${L.doorWidth} המומלצים לדלת אחת`);
    });
    // דלת זכוכית בלי מסגרת: זכוכית מחוסמת 6 לפחות (אזהרה אחת לכל סוג זכוכית)
    const thin = new Set();
    for (const { fr } of glassFronts) if (fr.vitrine?.frame === 'none' && (material(fr.glass).t || 4) < 6) thin.add(fr.glass);
    for (const g of thin) warnings.push(`דלת זכוכית בלי מסגרת צריכה זכוכית מחוסמת 6 מ"מ לפחות — נבחרה ${material(g).name}`);
    const glassT0 = glassFronts.length ? Math.max(...glassFronts.map((g) => g.doorT)) : 0;
    if (glassFronts.length && D - glassT0 < L.glassMinDepth) {
      warnings.push(`עומק ${D} מ"מ קטן מדי לוויטרינה — נדרשים לפחות ${L.glassMinDepth + glassT0}`);
    }
    units.forEach((u, i) => { if (u.splitCancelled > 0) warnings.push(`עמודה ${i + 1}: גובה החלק התחתון ${u.splitCancelled} גדול מדי לגובה העמודה — הפיצול בוטל`); });
    const adjustable = v.shelvesMode === 'adjustable';
    if (anyGlassSide && adjustable) warnings.push('דופן זכוכית: מדפים מתכווננים נשענים על הזקפים בלבד — עדיף מדפים קבועים או מסגרת פנימית');

    // אזהרות על מפתחים וגבהים. אזהרה בלבד — הנגר מחליט.
    const spanLimit = shelfT >= 25 ? L.shelfSpan25 : L.shelfSpan18;
    if (cols.colW > spanLimit && units.some((u) => u.floors.some((f) => f.interior?.type === 'shelves' && (f.interior.shelves?.count ?? 0) > 0))) {
      warnings.push(`מדף ברוחב ${Math.round(cols.colW)} מ"מ בעובי ${shelfT} — מעבר ל-${spanLimit} המומלצים ללא תמיכה`);
    }
    if (H > L.heightUnanchored) warnings.push(`גובה ${H} מ"מ — מעל ${L.heightUnanchored} מומלץ עיגון לקיר`);
    if (v.backMode === 'groove' && v.backInset + backT + v.backGrooveDepth > sideT * 3) {
      warnings.push('החריץ לגב רחוק מהקצה יותר מהמקובל — לבדוק מול הנגר');
    }

    const extraD = maxFront;
    const sideExtra = (f) => (f === 'fluted-wide' ? 10 : f.startsWith('fluted') ? 8 : 0);
    const bounds = { w: W + sideExtra(v.sideLeftFinish) + sideExtra(v.sideRightFinish), h: Math.max(...heights), d: D + extraD };
    // סטריפים על דופן שמאל יוצאים ל-x שלילי — מזיזים הכול ימינה כדי שהגבולות יתחילו ב-0
    const shift = sideExtra(v.sideLeftFinish);
    if (shift) { for (const p of parts) p.box = { ...p.box, x: p.box.x + shift }; for (const h of hardware) if (h.pos) h.pos = [h.pos[0] + shift, h.pos[1], h.pos[2]]; for (const p of parts) if (p.motion && p.motion.kind === 'hinge' && !p.motion.shifted) { p.motion = { ...p.motion, pivot: [p.motion.pivot[0] + shift, p.motion.pivot[1], p.motion.pivot[2]], shifted: true }; } }
    const lift = addWheels(parts, hardware, v, { x0: shift, x1: shift + W, y0: 0, z0: bodyZ, z1: bodyZ + bodyD });
    bounds.h += lift;
    const unitGeo = cols.cols.map((c, i) => { const { fl, b } = floorsOf(i); const top = (stepped ? tops[i] : topY); return { width: c.x1 - c.x0, height: heights[i], floors: fl.map((_, j) => (j === fl.length - 1 ? top : b[j + 1]) - b[j]) }; });
    return { parts, hardware, warnings, bounds, units, unitGeo, inactive: inactiveOf(units, saved ? null : layout, v, (i) => floorsOf(i)) };
  },
};

function boardThickness(id) { return material(id).t || 18; }
/**
 * גבהי הקומות: נעוצה — בדיוק כפי שנקבעה; אוטומטיות — מתחלקות בשווה במה שנשאר.
 * כשהנעוצות לא משאירות לפחות 100 לכל אוטומטית — הן מוקטנות ביחס.
 */
function floorHeights(room, pins) {
  const autos = pins.filter((p) => p === null).length;
  let fixed = pins.reduce((a, p) => a + (p ?? 0), 0);
  const avail = room - autos * 100;
  const k = autos ? (fixed > avail ? Math.max(0, avail) / fixed : 1) : (fixed ? room / fixed : 1);
  const hs = pins.map((p) => (p === null ? null : p * k));
  fixed = hs.reduce((a, p) => a + (p ?? 0), 0);
  const share = autos ? (room - fixed) / autos : 0;
  return hs.map((p) => (p === null ? share : p));
}
/** עומק שסטריפים מודבקים מוסיפים לחזית. */
function fluteDepth(f) { return typeof f === 'string' && f.startsWith('fluted') ? (f === 'fluted-wide' ? 10 : 8) : 0; }

/**
 * הגדרות שנקבעו אבל לא חלות — כדי שהממשק יסמן "לא בתוקף, כי…" במקום להתעלם בשקט.
 * [{ path, reason }]; path לפי המבנה (units.<עמודה>.floors.<קומה>.…), או השדה הישן
 * (columnsLayout / lowerH) כשהעמודות עדיין מומרות ממנו.
 */
function inactiveOf(units, legacyLayout, v, floorsOf) {
  const out = [];
  units.forEach((u, i) => {
    const col = `עמודה ${i + 1}`;
    if (u.splitIgnored > 0) out.push({ path: legacyLayout?.cols?.[i]?.split !== undefined ? `columnsLayout.cols.${i}.split` : 'lowerH', column: i, reason: `${col} היא ויטרינה מלאה (קומה אחת) — הפיצול ${u.splitIgnored} לא חל` });
    if (u.splitCancelled > 0) out.push({ path: legacyLayout?.cols?.[i]?.split !== undefined ? `columnsLayout.cols.${i}.split` : 'lowerH', column: i, reason: `${col}: החלק התחתון ${u.splitCancelled} גבוה מדי לעמודה — הפיצול בוטל` });
    const fl = u.floors;
    if (fl.length === 1 && Number.isFinite(fl[0].height) && fl[0].height > 0) out.push({ path: `units.${i}.floors.0.height`, column: i, reason: `${col}: קומה יחידה — לוקחת את כל הגובה` });
    if (fl.length > 1) {
      const pins = fl.map((f) => (Number.isFinite(f.height) && f.height > 0 ? f.height : null));
      const { b } = floorsOf(i);
      fl.forEach((f, j) => {
        if (pins[j] === null) return;
        const real = (j === fl.length - 1 ? null : b[j + 1] - b[j]);
        if (real !== null && Math.abs(real - pins[j]) > 0.5) out.push({ path: `units.${i}.floors.${j}.height`, column: i, floor: j, reason: `${col}, קומה ${j + 1}: הגובה הוקטן ל-${Math.round(real)} כדי שהקומות ייכנסו` });
      });
    }
    fl.forEach((f, j) => {
      const at = `units.${i}.floors.${j}`, where = `${col}${fl.length > 1 ? `, קומה ${j + 1}` : ''}`;
      const fr = f.front || {}, vt = fr.vitrine || {};
      if (fr.type === 'glass') {
        if (vt.frame === 'none') for (const k of ['sides', 'frameW', 'profile', 'mullions']) if (vt[k] !== undefined && !(k === 'mullions' && vt[k] === 'none') && !(k === 'profile' && vt[k] === 'flat') && !(k === 'sides' && vt[k] === '4')) out.push({ path: `${at}.front.vitrine.${k}`, column: i, floor: j, reason: `${where}: זכוכית בלי מסגרת` });
        if (vt.frame === 'alu' && vt.profile && vt.profile !== 'flat') out.push({ path: `${at}.front.vitrine.profile`, column: i, floor: j, reason: `${where}: פרופיל (שייקר/פאזה/מעוגל) — במסגרת עץ בלבד` });
        if (vt.frame === 'wood' && vt.profile === 'shaker' && vt.sides && vt.sides !== '4') out.push({ path: `${at}.front.vitrine.profile`, column: i, floor: j, reason: `${where}: שייקר — רק במסגרת של 4 צדדים` });
      }
      const it = f.interior || {};
      if (it.type === 'drawers' && fr.type && fr.type !== 'none') {
        if (it.drawers?.handle && it.drawers.handle !== 'none') out.push({ path: `${at}.interior.drawers.handle`, column: i, floor: j, reason: `${where}: מגירות פנימיות (מאחורי דלת) — בלי ידית` });
        if (it.drawers?.finish && it.drawers.finish !== 'flat') out.push({ path: `${at}.interior.drawers.finish`, column: i, floor: j, reason: `${where}: מגירות פנימיות — הגימור לא נראה` });
      }
      if (it.type === 'shelves' && fl.length > 1 && Array.isArray(it.shelves?.gaps) && it.shelves.gaps.some((g) => g !== null)) out.push({ path: `${at}.interior.shelves.gaps`, column: i, floor: j, reason: `${where}: גובה לכל תא — בינתיים רק בעמודה בלי קומות` });
    });
  });
  return out;
}

/** units שנשמרו בפרויקט — רק אם הם במבנה תקין ובמספר העמודות הנוכחי. */
function validUnits(u, n, v = {}) {
  if (!Array.isArray(u) || !u.length || !u.every((c) => c && Array.isArray(c.floors) && c.floors.length > 0)) return null;
  if (u.length === n) return u;
  // מספר העמודות השתנה: עמודות שנוספו — לפי newUnit (ברירת מחדל: העתק של האחרונה, ברוחב אוטומטי); שירדו — נחתכות
  const out = u.slice(0, n);
  while (out.length < n) out.push(newUnitOf(u[u.length - 1], v.newUnit, v));
  return out;
}
/**
 * עמודה חדשה: 'last' — העתק של האחרונה; 'open' / 'wood' / 'glass' — קומה אחת לכל הגובה
 * עם מדפים, והחזית לפי הבחירה. פרטי החזית (חומר, צירים, ויטרינה…) מהקומה העליונה של
 * האחרונה, כדי שהעמודה תתאים לשאר.
 */
export function newUnitOf(last, kind = 'last', v = {}) {
  const copy = JSON.parse(JSON.stringify(last));
  if (!kind || kind === 'last') return { ...copy, width: null };
  const top = copy.floors[copy.floors.length - 1] || {};
  const sh = top.interior?.type === 'shelves' ? top.interior.shelves || {} : {};
  const type = kind === 'open' ? 'none' : kind;
  return {
    width: null, height: null,
    floors: [{ height: null, front: { ...legacyFront(v, type), ...(top.front || {}), type },
      interior: { type: 'shelves', shelves: { count: Number.isInteger(sh.count) ? sh.count : (v.shelvesPerColumn ?? 4), gaps: null, material: type === 'glass' ? (sh.material ?? 'same') : 'same', mode: sh.mode ?? v.shelvesMode ?? 'adjustable' } } }],
  };
}

/** חזית קומה מההגדרות הישנות (כלליות) — אותו דבר לכל עמודה. */
function legacyFront(v, type) {
  return {
    type, leaves: 'auto', material: v.doorMaterial, finish: v.doorFinish, glass: v.glassType, hinge: v.hinge, handle: v.handle, hingeSide: 'auto',
    vitrine: { frame: v.vitrineFrame, sides: v.vitrineSides, frameW: v.vitrineFrameW, profile: v.vitrineProfile, mullions: v.vitrineMullions, frameMaterial: v.vitrineFrameMaterial, alu: v.vitrineAlu },
  };
}

/**
 * המרה: ההגדרות הישנות (doorType, lowerDoors, glassColumns, lowerH, פיצול/מדפים לכל
 * עמודה, vitrineShelves…) → עמודות עם קומות. בדיוק לפי הכללים שהבנייה הישנה הפעילה:
 * עמודת ויטרינה מלאה — קומה אחת (הפיצול לא חל); פיצול שלא נכנס — קומה אחת ואזהרה;
 * בפיצול — המדפים מתחלקים בין הקומות לפי חלקן בגובה.
 */
export function migrateUnits(v, layout, geo) {
  const n = v.columns;
  const isGlassCol = (i) => v.glassColumns === 'all' || (v.glassColumns === 'first' && i === 0) || (v.glassColumns === 'last' && i === n - 1) || (v.glassColumns === 'ends' && (i === 0 || i === n - 1));
  const shelfMatFor = (frontType) => (v.vitrineShelves === 'glass' && frontType === 'glass' ? v.vitrineShelfGlass : 'same');
  return Array.from({ length: n }, (_, i) => {
    const c = layout.cols[i] || {};
    const total = Number.isInteger(c.shelves) ? c.shelves : v.shelvesPerColumn;
    const want = rawSplit(layout, i, v.lowerH);
    const room = (geo.stepped ? geo.tops[i] : geo.topY) - geo.bottomY;
    const lower = splitFor(layout, i, v.lowerH, room);
    const unit = { width: layout.widths[i] ?? null, height: Number.isFinite(c.height) && c.height > 0 ? c.height : null, floors: [] };
    if (want > 0 && !lower) unit.splitCancelled = want;
    const shelvesOf = (count, frontType, gaps) => ({ type: 'shelves', shelves: { count, gaps: gaps || null, material: shelfMatFor(frontType), mode: v.shelvesMode } });
    if (isGlassCol(i)) {
      if (want > 0) unit.splitIgnored = want;   // הפיצול לא חל על עמודת ויטרינה מלאה — מסומן "לא בתוקף"
      unit.floors.push({ height: null, front: legacyFront(v, 'glass'), interior: shelvesOf(total, 'glass', c.gaps) });
    } else if (lower) {
      const y1c = geo.colY1(i);
      const lowerN = Math.round(total * (lower / (y1c - geo.inner.y0)));
      unit.floors.push({ height: lower, front: legacyFront(v, v.lowerDoors), interior: shelvesOf(lowerN, v.lowerDoors) });
      unit.floors.push({ height: null, front: legacyFront(v, v.doorType), interior: shelvesOf(total - lowerN, v.doorType) });
    } else {
      unit.floors.push({ height: null, front: legacyFront(v, v.doorType), interior: shelvesOf(total, v.doorType, c.gaps) });
    }
    return unit;
  });
}
/** הפיצול שנבחר לעמודה (לפני בדיקת התאמה): של העמודה אם נקבע, אחרת ברירת המחדל. */
function rawSplit(layout, i, dflt) { const s = layout?.cols?.[i]?.split; return Number.isFinite(s) && s >= 0 ? s : (dflt ?? 0); }
/** גובה החלק התחתון בעמודה, או 0 אם אין פיצול או שהוא לא נכנס (`room` — מהרצפה עד הגג). */
function splitFor(layout, i, dflt, room) { const s = rawSplit(layout, i, dflt); return s > 0 && s < room - 100 ? s : 0; }
/** הגובה הנמוך ביותר שעמודה יכולה לקבל: סוקל, רצפה, גג, כרכוב ותא של 100. */
function minColumnH(v) { return (v.plinthH ?? 0) + 2 * (v.shelfT ?? 18) + (v.crownH ?? 0) + 100; }
function backThickness(id) { return material(id).t || 6; }
