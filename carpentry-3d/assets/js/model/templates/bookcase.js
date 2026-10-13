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

import { carcass, back, plinth, crown, part } from '../blocks.js';
import { material } from '../materials.js';
import { sectionLayout, cellsOf } from '../layout.js';
import { unitsInterior, unitsFronts, locateUnitPart, partT } from '../units-engine.js';
import { LED_OPTIONS, isBundle, lightingState, finishLighting } from '../lighting.js';
/** יש תאורה כלשהי — כללית או לקומה. */
const anyLed = (v) => (v.led && v.led !== 'none') || (Array.isArray(v.units) && v.units.some((u) => u.floors?.some((f) => f.led && f.led !== 'same' && f.led !== 'none')));
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

    { key: 'sideLeftFinish', label: 'דופן שמאל — סוג', type: 'enum', default: 'flat', group: 'דפנות', options: FINISHES },
    sameOrMaterial('sideLeftMaterial', 'דופן שמאל — חומר', 'דפנות', { showIf: { sideLeftFinish: { not: ['glass'] } } }),
    { key: 'sideRightFinish', label: 'דופן ימין — סוג', type: 'enum', default: 'flat', group: 'דפנות', options: FINISHES },
    sameOrMaterial('sideRightMaterial', 'דופן ימין — חומר', 'דפנות', { showIf: { sideRightFinish: { not: ['glass'] } } }),
    { key: 'led', label: 'פסי לד — ברירת המחדל לכל קומה', type: 'enum', default: 'none', group: 'תאורה', options: LED_OPTIONS,
      hint: 'בכרטיס של כל קומה אפשר אחרת' },
    { key: 'ledStrip', label: 'פס הלד', type: 'material', kind: 'hardware', role: 'led-strip', default: 'hw:led-12v-3000', group: 'תאורה', showIf: anyLed, showIfText: 'יש תאורה',
      hint: '"כולל פרופיל ושנאי" — המחיר הישן למטר, בלי פירוט' },
    { key: 'ledProfile', label: 'פרופיל', type: 'material', kind: 'hardware', role: 'led-profile', allowNone: true, noneLabel: 'בלי פרופיל (פס חשוף)', default: 'hw:led-profile-surface', group: 'תאורה', showIf: (v) => anyLed(v) && !isBundle(v.ledStrip), showIfText: 'פס לד מפורט' },
    { key: 'ledControl', label: 'שליטה', type: 'enum', default: 'auto', group: 'תאורה', showIf: (v) => anyLed(v) && !isBundle(v.ledStrip), showIfText: 'פס לד מפורט',
      options: [{ id: 'auto', name: 'אוטומטי — חיישן דלת בקומה עם דלת, מתג מגע לשאר' }, { id: 'touch', name: 'מתג מגע / עמעם' }, { id: 'switch', name: 'מתג על הכבל' }, { id: 'door', name: 'חיישן דלת' }, { id: 'motion', name: 'חיישן תנועה' }] },
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
    // פרויקט עם לד מלפני התאורה המפורטת — נשאר על הפס "כולל פרופיל ושנאי" (אותו מחיר)
    if (v.ledStrip === undefined && v.led && v.led !== 'none') v.ledStrip = 'hw:led-strip';
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
  locate(id, units) { return locateUnitPart(id, units); },


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
    // כרכוב לכל עמודה (units[i].crownH; ריק — של המוצר): הגג של העמודה יורד בגובה הכרכוב שלה
    const crownOf = (i) => (saved && Number.isFinite(saved[i]?.crownH) && saved[i].crownH >= 0 ? saved[i].crownH : v.crownH);
    const tops = heights.map((h, i) => h - crownOf(i));
    const stepped = tops.some((t) => t !== topY);
    const sidesOverTop = v.sidesOverTop === 'sides';
    if (stepped) fitSides(parts, tops, { panelT: shelfT, sidesOverTop });
    const colY1 = (i) => (stepped ? tops[i] - shelfT : inner.y1);   // תקרת הפנים של העמודה

    // הדפנות החיצוניות: סוג (חלק / סטריפים / חריצים / זכוכית) וחומר לכל צד בנפרד.
    const glassT = material(v.glassType).t || 4;
    let anyGlassSide = false;
    // הדופן החיצונית — בכרטיס של העמודה הקיצונית (units[0].sideL / units[n-1].sideR), ובלעדיו — של המוצר
    const edge = (side) => (side === 'L' ? saved?.[0]?.sideL : saved?.[v.columns - 1]?.sideR) || {};
    const sideFinish = (side) => sameOr(edge(side).finish, side === 'L' ? v.sideLeftFinish : v.sideRightFinish);
    for (const side of ['L', 'R']) {
      const finish = sideFinish(side);
      const mat = resolveSame(sameOr(edge(side).material, side === 'L' ? v.sideLeftMaterial : v.sideRightMaterial), v.bodyMaterial);
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
    const light = lightingState(v.ledStrip, v.ledProfile);
    const eng = { light, units, columns: v.columns, inner, W, D, sideT, shelfT, bottomY, topY, tops, stepped, colY1, z0, z1, innerMat, shelfMat, sidesOverTop, shelvesMode: v.shelvesMode, led: v.led, plinthH: v.plinthH, doorMaterial: v.doorMaterial, limits: L };
    const ctx = { parts, hardware, warnings };
    const { cols, floorsOf } = unitsInterior(ctx, eng);

    // גב לכל עמודה (units[i].back: שיטה וחומר); כשאין הגדרות לעמודות — הגב של המוצר, כמו קודם
    const backOf = (i) => ({ mode: sameOr(units[i]?.back?.mode, v.backMode), material: sameOr(units[i]?.back?.material, v.backMaterial) });
    const perBack = units.some((u, i) => backOf(i).mode !== v.backMode || backOf(i).material !== v.backMaterial);
    if (perBack) parts.push(...columnBacks({ cols: cols.cols, units, backOf, tops, W, sideT, shelfT, bottomY, inner, grooveDepth: v.backGrooveDepth, inset: v.backInset }));
    else if (stepped) parts.push(...steppedBack({ mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, cols: cols.cols, tops, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial, sideT, W, panelT: shelfT }));
    else parts.push(...back({
      mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, t: backT,
      grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial,
    }).parts);

    parts.push(...plinth({ inner, h: v.plinthH, setback: v.plinthSetback, t: sideT, d: D, material: v.bodyMaterial }).parts);
    if (units.some((u, i) => crownOf(i) !== v.crownH)) parts.push(...columnCrowns({ cols: cols.cols, tops, crownOf, t: sideT, d: D, material: v.bodyMaterial, sideT, W }));
    else if (stepped) parts.push(...steppedCrown({ cols: cols.cols, tops, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial, sideT, W }));
    else parts.push(...crown({ w: W, y: topY, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial }).parts);

    // ---- מגירות ודלתות: לכל קומה בכל עמודה — לפי החזית שלה (units-engine.js) ----
    const { maxFront } = unitsFronts(ctx, eng, cols, floorsOf);
    // תאורה: שנאי לפי ההספק, שליטה, וחור לכבל בגב העמודה (או בגג, כשאין גב)
    finishLighting(ctx, light, { control: v.ledControl, holeBoard: (i) => parts.find((p) => /(^|-)back$/.test(p.id) && p.box.x <= cols.cols[i].x0 + 1 && p.box.x + p.box.w >= cols.cols[i].x1 - 1) || parts.find((p) => (p.id === 'top' || /^top-\d/.test(p.id)) && p.box.x <= cols.cols[i].x0 + 1 && p.box.x + p.box.w >= cols.cols[i].x1 - 1) });
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
    const bounds = { w: W + sideExtra(sideFinish('L')) + sideExtra(sideFinish('R')), h: Math.max(...heights), d: D + extraD };
    // סטריפים על דופן שמאל יוצאים ל-x שלילי — מזיזים הכול ימינה כדי שהגבולות יתחילו ב-0
    const shift = sideExtra(sideFinish('L'));
    if (shift) { for (const p of parts) p.box = { ...p.box, x: p.box.x + shift }; for (const h of hardware) if (h.pos) h.pos = [h.pos[0] + shift, h.pos[1], h.pos[2]]; for (const p of parts) if (p.motion && p.motion.kind === 'hinge' && !p.motion.shifted) { p.motion = { ...p.motion, pivot: [p.motion.pivot[0] + shift, p.motion.pivot[1], p.motion.pivot[2]], shifted: true }; } }
    const lift = addWheels(parts, hardware, v, { x0: shift, x1: shift + W, y0: 0, z0: bodyZ, z1: bodyZ + bodyD });
    bounds.h += lift;
    const unitGeo = cols.cols.map((c, i) => { const { fl, b } = floorsOf(i); const top = (stepped ? tops[i] : topY); return { width: c.x1 - c.x0, height: heights[i], floors: fl.map((_, j) => (j === fl.length - 1 ? top : b[j + 1]) - b[j]) }; });
    return { parts, hardware, warnings, bounds, units, unitGeo, inactive: inactiveOf(units, saved ? null : layout, v, (i) => floorsOf(i)) };
  },
};

function boardThickness(id) { return material(id).t || 18; }
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

const sameOr = (x, dflt) => (!x || x === 'same' ? dflt : x);
/** ריצות של עמודות סמוכות עם אותו מפתח (גג, שיטה, חומר…): [{ a, b }]. */
function runsBy(keys) {
  const out = [];
  keys.forEach((k, i) => { const r = out[out.length - 1]; if (r && r.key === k) r.b = i; else out.push({ a: i, b: i, key: k }); });
  return out;
}
/**
 * גב לכל ריצה של עמודות עם אותו גג, שיטה וחומר: מולבש — מחצי המחיצה עד חצי המחיצה
 * (בקצוות — עד קצה הארון); בחריץ — בין המחיצות של הריצה, עד מתחת לגג; ללא — כלום.
 */
function columnBacks({ cols, units, backOf, tops, W, sideT, shelfT, bottomY, inner, grooveDepth, inset }) {
  const last = cols.length - 1;
  return runsBy(cols.map((c, i) => `${tops[i]}|${backOf(i).mode}|${backOf(i).material}`)).flatMap((r, k) => {
    const { mode, material: mat } = backOf(r.a), top = tops[r.a], t = mode === 'none' ? 0 : material(mat).t || 6;
    if (mode === 'none') return [];
    const prefix = `c${r.a + 1}${r.b > r.a ? `-${r.b + 1}` : ''}-`;
    if (mode === 'overlay') {
      const x0 = r.a === 0 ? 0 : cols[r.a].x0 - partT(units, r.a - 1, sideT) / 2, x1 = r.b === last ? W : cols[r.b].x1 + partT(units, r.b, sideT) / 2;
      return back({ mode, outer: { w: x1 - x0, y0: bottomY - shelfT, y1: top }, inner, t, material: mat, prefix }).parts.map((p) => ({ ...p, box: { ...p.box, x: p.box.x + x0 } }));
    }
    return back({ mode, outer: { w: W, y0: bottomY - shelfT, y1: top }, inner: { ...inner, x0: cols[r.a].x0, x1: cols[r.b].x1, y1: top - shelfT }, t, grooveDepth, inset, material: mat, prefix }).parts;
  });
}
/** כרכוב לכל ריצה של עמודות עם אותו גג ואותו גובה כרכוב (כמו steppedCrown, בגובה של הריצה). */
function columnCrowns({ cols, tops, crownOf, t, d, material: mat, sideT, W }) {
  const last = cols.length - 1;
  return runsBy(cols.map((c, i) => `${tops[i]}|${crownOf(i)}`)).flatMap((r) => {
    const top = tops[r.a], h = crownOf(r.a);
    const x0 = r.a === 0 ? 0 : (tops[r.a - 1] > top ? cols[r.a].x0 : cols[r.a].x0 - sideT);
    const x1 = r.b === last ? W : (tops[r.b + 1] > top ? cols[r.b].x1 : cols[r.b].x1 + sideT);
    return crown({ w: x1 - x0, y: top, h, t, d, material: mat, prefix: `c${r.a + 1}${r.b > r.a ? `-${r.b + 1}` : ''}-` }).parts.map((p) => ({ ...p, box: { ...p.box, x: p.box.x + x0 } }));
  });
}

