// תבנית: ארון בגדים.
//
// גוף עם עמודות; כל עמודה — קומות (docs/elements-spec.md, שלב 6): לכל קומה חזית
// (ללא / דלת עץ) ותוכן (תלייה — מוט ומדף עליון / מדפים / מגירות / ריק). מגירות
// בקומה בלי דלת — חזיתות גלויות (בתוך העמודה, כמו תמיד בארון); מאחורי דלת —
// פנימיות, בלי ידית. דלתות הזזה — החלטה של כל הארון: מכסות את כל הרוחב, ואז
// החזיתות של הקומות לא חלות.
//
// הבנייה קוראת רק את `units`. פרויקט שעוד אין לו — מומר מההגדרות הישנות (עמודות
// תלייה, מדפים, מגירות בתחתית, סוג דלתות) בכל בנייה, וזהה לבנייה הישנה
// (legacy/wardrobe-v1.js, נבדק ב-model-check).

import { carcass, partitions, shelves, back, plinth, crown, door, drawer, rod, slidingDoors } from '../blocks.js';
import { resolveShares, sectionLayout } from '../layout.js';
import { wheelsParam, addWheels, materialParams, drawerParams, joineryParams, handleParam, hingeParam, boardT, bodyWarnings, LIMITS, FINISHES_NO_GLASS, applyFinish, sameOrMaterial, resolveSame, columnHeights, fitSides, stepTops, steppedBack, steppedCrown } from './common.js';

const sliding = (v) => v.slidingDoors === 'yes';

export default {
  key: 'wardrobe',
  name: 'ארון בגדים',
  description: 'עמודות תלייה ומדפים, מגירות בתחתית, דלתות צירים או הזזה.',
  laborHours: 12,

  params: [
    { key: 'width', label: 'רוחב', type: 'mm', min: 500, max: 5000, default: 2400, group: 'מידות' },
    { key: 'height', label: 'גובה', type: 'mm', min: 1200, max: 3000, default: 2400, group: 'מידות' },
    { key: 'depth', label: 'עומק', type: 'mm', min: 400, max: 800, default: 600, group: 'מידות' },

    { key: 'columns', label: 'עמודות', type: 'int', min: 1, max: 8, default: 3, group: 'חלוקה' },
    { key: 'newUnit', label: 'עמודה שנוספת', type: 'enum', default: 'last', group: 'חלוקה', hint: 'כשמגדילים את מספר העמודות (או "＋ עמודה" בכרטיסים)',
      showIf: (v) => Array.isArray(v.units) && v.units.length > 0, showIfText: 'יש עמודות שהוגדרו',
      options: [{ id: 'last', name: 'העתק של העמודה האחרונה' }, { id: 'hanging', name: 'תלייה' }, { id: 'shelves', name: 'מדפים' }, { id: 'drawers', name: 'מגירות למטה ומדפים מעל' }] },
    // המבנה לפי עמודה וקומה. ריק — נגזר מההגדרות הישנות בכל בנייה.
    { key: 'units', label: 'עמודות וקומות', type: 'json', default: null, group: 'חלוקה', editor: 'units' },
    // ההגדרות הכלליות הישנות — רק להמרה של פרויקטים שעוד אין להם units
    { legacy: true, key: 'hangingColumns', label: 'עמודות תלייה', type: 'int', min: 0, max: 8, default: 2, group: 'חלוקה', hint: 'הראשונות משמאל; השאר מדפים' },
    { legacy: true, key: 'shelvesPerColumn', label: 'מדפים בעמודת מדפים', type: 'int', min: 0, max: 12, default: 5, group: 'חלוקה' },
    { legacy: true, key: 'topShelf', label: 'מדף עליון בעמודת תלייה', type: 'enum', default: 'yes', group: 'חלוקה', options: [{ id: 'yes', name: 'כן' }, { id: 'no', name: 'לא' }] },
    { legacy: true, key: 'drawersPerColumn', label: 'מגירות בתחתית כל עמודה', type: 'int', min: 0, max: 4, default: 0, group: 'חלוקה' },
    { legacy: true, key: 'drawerH', label: 'גובה מגירה', type: 'mm', min: 120, max: 400, default: 200, group: 'חלוקה' },
    { legacy: true, key: 'columnsLayout', label: 'עריכת עמודות', type: 'json', default: null, group: 'חלוקה', editor: 'columns' },

    ...materialParams(),
    sameOrMaterial('innerMaterial', 'הגוף הפנימי (מחיצות ומדפים)', 'חומרים'),
    { key: 'sideLeftFinish', label: 'דופן שמאל — סוג', type: 'enum', default: 'flat', group: 'חומרים', options: FINISHES_NO_GLASS },
    sameOrMaterial('sideLeftMaterial', 'דופן שמאל — חומר', 'חומרים'),
    { key: 'sideRightFinish', label: 'דופן ימין — סוג', type: 'enum', default: 'flat', group: 'חומרים', options: FINISHES_NO_GLASS },
    sameOrMaterial('sideRightMaterial', 'דופן ימין — חומר', 'חומרים'),
    // מגירות: ברירות המחדל לקומות (הארגז, התחתית והמסילות נבחרים גם לכל קומה)
    ...drawerParams().map((p) => ({ ...p, legacy: true })),

    { key: 'plinthH', label: 'סוקל — גובה', type: 'mm', min: 0, max: 200, default: 80, group: 'סיומות' },
    { key: 'plinthSetback', label: 'סוקל — נסיגה', type: 'mm', min: 0, max: 100, default: 30, group: 'סיומות' },
    { key: 'crownH', label: 'כרכוב — גובה', type: 'mm', min: 0, max: 200, default: 0, group: 'סיומות' },
    wheelsParam(),

    // דלתות: צירים — לכל קומה בכרטיס העמודה; הזזה — החלטה של כל הארון
    { key: 'slidingDoors', label: 'דלתות הזזה', type: 'enum', default: 'no', group: 'דלתות', hint: 'דלתות הזזה מכסות את כל הרוחב; בלעדיהן — דלת לכל קומה בכרטיס העמודה',
      options: [{ id: 'no', name: 'לא — צירים / פתוח, לפי קומה' }, { id: 'yes', name: 'כן — הזזה על כל הארון' }] },
    { key: 'doorMaterial', label: 'חומר הדלתות', type: 'material', kind: 'board', back: false, solid: false, top: false, default: 'board:mdf-paint-18', group: 'דלתות', showIf: { slidingDoors: ['yes'] } },
    { key: 'slidingLeaves', label: 'כנפי הזזה', type: 'int', min: 2, max: 4, default: 2, group: 'דלתות', showIf: { slidingDoors: ['yes'] } },
    { key: 'slidingSystem', label: 'מערכת ההזזה', type: 'material', kind: 'hardware', role: 'sliding', default: 'hw:track-sliding', group: 'דלתות', showIf: { slidingDoors: ['yes'] }, hint: 'גובה הכנף, הגררות והעומס המותר נגזרים מהמערכת' },
    { legacy: true, key: 'doorType', label: 'דלתות', type: 'enum', default: 'none', group: 'דלתות', options: [{ id: 'none', name: 'ללא' }, { id: 'wood', name: 'עץ' }, { id: 'sliding', name: 'הזזה' }] },
    { legacy: true, key: 'doorFinish', label: 'גימור דלתות העץ', type: 'enum', default: 'flat', group: 'דלתות', options: FINISHES_NO_GLASS },
    hingeParam({ legacy: true }), handleParam({ legacy: true }),
    { key: 'rodMaterial', label: 'מוט תלייה', type: 'enum', default: 'hw:rod-chrome', group: 'תלייה', options: [{ id: 'hw:rod-chrome', name: 'כרום' }],
      showIf: (v) => (Array.isArray(v.units) && v.units.length ? v.units.some((u) => u.floors?.some((f) => f.interior?.type === 'hanging')) : (v.hangingColumns ?? 0) > 0), showIfText: 'יש קומת תלייה' },
  ],
  // מדפים קבועים/מתכווננים — לכל קומה; עובי הדפנות והמדפים — מהחומר (שלב 5)
  joinery: joineryParams({ autoT: true }).map((p) => (p.key === 'shelvesMode' ? { ...p, legacy: true } : p)),
  limits: LIMITS,

  /** מה כרטיס העמודה מציע בארון בגדים (units-ui.js). */
  unitsSpec: {
    fronts: { none: 'ללא', wood: 'דלת עץ' },
    interiors: { hanging: 'תלייה', shelves: 'מדפים', drawers: 'מגירות', empty: 'ריק' },
    newUnit: (last, kind, v) => newUnitOf(last, kind, v),
  },

  /** פרויקטים ישנים: סוג הדלתות הכללי → "דלתות הזזה"; עובי ששווה לחומר → נגזר. */
  migrate(v) {
    if (v.slidingDoors === undefined && v.doorType !== undefined) v.slidingDoors = v.doorType === 'sliding' ? 'yes' : 'no';
    for (const [k, m] of [['sideT', v.bodyMaterial], ['shelfT', v.shelfMaterial ?? v.bodyMaterial]]) if (v[k] != null && m && Number(v[k]) === boardT(m)) v[k] = null;
    return v;
  },

  /** מזהה רכיב → העמודה והקומה שלו (לכפתור "⚙️ הגדרות הקומה" בכרטיס הרכיב). */
  locate(id, units) {
    const floorOf = (i, test) => { const fl = units?.[i]?.floors || []; const j = fl.findIndex(test); return j < 0 ? 0 : j; };
    let m = /^drawer-(\d+)-(?:f(\d+)-)?\d+/.exec(id);
    if (m) { const i = +m[1] - 1; return { unit: i, floor: m[2] ? +m[2] - 1 : floorOf(i, (f) => f.interior?.type === 'drawers') }; }
    m = /^door-(\d+)(?:-f(\d+))?[ab]?(?:-|$)/.exec(id);
    if (m) { const i = +m[1] - 1; return { unit: i, floor: m[2] ? +m[2] - 1 : floorOf(i, (f) => f.front?.type === 'wood') }; }
    m = /^rod-(\d+)(?:-f(\d+))?/.exec(id);
    if (m) { const i = +m[1] - 1; return { unit: i, floor: m[2] ? +m[2] - 1 : floorOf(i, (f) => f.interior?.type === 'hanging') }; }
    m = /^(?:f(\d+)-)?(?:top-)?shelf-(\d+)-\d+/.exec(id);
    if (m) { const i = +m[2] - 1; return { unit: i, floor: m[1] ? +m[1] - 1 : floorOf(i, (f) => ['shelves', 'hanging'].includes(f.interior?.type)) }; }
    m = /^(?:over-drawers|split(\d+))-shelf-(\d+)-/.exec(id);
    if (m) return { unit: +m[2] - 1, floor: m[1] ? +m[1] : 1 };
    m = /^partition-(\d+)/.exec(id);
    if (m) return { unit: +m[1] - 1, floor: null };
    return null;
  },

  /** לעורך החלוקה הישן (מוסתר כשיש units): העמודות, לפי ההגדרות הכלליות. */
  columnSpace(v) {
    const sideT = v.sideT ?? 18, shelfT = v.shelfT ?? 18, columns = v.columns ?? 1;
    const bottomY = (v.plinthH ?? 80) + shelfT, topY = (v.height ?? 2400) - (v.crownH ?? 0);
    const drawersH = (v.drawersPerColumn ?? 0) > 0 ? v.drawersPerColumn * (v.drawerH ?? 200) + shelfT : 0;
    const innerH = topY - shelfT - bottomY - drawersH;
    const innerW = (v.width ?? 2400) - 2 * sideT - (columns - 1) * sideT;
    const H = v.height ?? 2400, sl = sliding(v) || v.doorType === 'sliding';
    const heights = sl ? Array.from({ length: columns }, () => H) : columnHeights(sectionLayout(v.columnsLayout, 'main', columns), columns, H, legacyMinH(v));
    const height = sl ? null : { full: H, min: legacyMinH(v) };
    return { sections: [{
      key: 'main', title: '', total: innerW, sizeLabel: 'רוחב', allLabel: 'העמודות', modes: { next: 'מהעמודה שמימין', prev: 'מהעמודה שמשמאל' },
      items: Array.from({ length: columns }, (_, i) => (i < (v.hangingColumns ?? 0)
        ? { label: `עמודה ${i + 1}`, innerH: innerH - (H - heights[i]), shelfT, cellsOf: 'shelves', defaultCount: 0, editable: false, note: 'עמודת תלייה — מוט ומדף עליון; אין תאים לעריכה', height }
        : { label: `עמודה ${i + 1}`, innerH: innerH - (H - heights[i]), shelfT, cellsOf: 'shelves', defaultCount: v.shelvesPerColumn ?? 0, countMax: 12, editable: true, note: '', height })),
    }] };
  },

  build(v) {
    const parts = [], hardware = [], warnings = [];
    const W = v.width, H = v.height, D = v.depth;
    const sideT = v.sideT, shelfT = v.shelfT;
    const backT = v.backMode === 'none' ? 0 : boardT(v.backMaterial);
    const bodyZ = v.backMode === 'overlay' ? backT : 0;
    const bodyD = D - bodyZ;
    const bottomY = v.plinthH + shelfT;
    const topY = H - v.crownH;
    const sl = sliding(v);

    const innerMat = resolveSame(v.innerMaterial, v.bodyMaterial);
    const shelfMat = v.shelfMaterial === v.bodyMaterial ? innerMat : v.shelfMaterial;
    const body = carcass({ w: W, d: bodyD, z: bodyZ, bottomY, topY, sideT, panelT: shelfT, sidesOverTop: v.sidesOverTop === 'sides', material: v.bodyMaterial });
    parts.push(...body.parts);

    // המבנה לפי עמודה וקומה: שמור בפרויקט, או מומר מההגדרות הישנות
    const layout = sectionLayout(v.columnsLayout, 'main', v.columns);
    const saved = validUnits(v.units, v.columns, v);
    const units = saved || migrateUnits(v, layout);
    const minH = Math.max(...units.map((u) => unitMinH(v, u)));
    // גובה לכל עמודה (ברירת מחדל — גובה הארון). דלתות הזזה מכסות את כל הרוחב — שם הגובה אחיד.
    const pinnedHeights = columnHeights({ cols: Object.fromEntries(units.map((u, i) => [i, { height: u.height }])) }, v.columns, H, minH);
    const heights = sl ? pinnedHeights.map(() => H) : pinnedHeights;
    if (sl && pinnedHeights.some((h) => h !== H)) warnings.push('דלתות הזזה דורשות גובה אחיד — גבהי העמודות שנקבעו לא חלים כאן');
    const tops = heights.map((h) => h - v.crownH);
    const stepped = tops.some((t) => t !== topY);
    const sidesOverTop = v.sidesOverTop === 'sides';
    if (stepped) fitSides(parts, tops, { panelT: shelfT, sidesOverTop });
    for (const side of ['L', 'R']) {
      const s = parts.find((p) => p.id === `side-${side}`);
      const finish = side === 'L' ? v.sideLeftFinish : v.sideRightFinish;
      s.material = resolveSame(side === 'L' ? v.sideLeftMaterial : v.sideRightMaterial, v.bodyMaterial);
      parts.push(...applyFinish(s, finish, { material: s.material, normal: side === 'L' ? '-x' : '+x' }));
    }
    const inner = body.inner;
    const widths = resolveShares(inner.x1 - inner.x0 - (v.columns - 1) * sideT, units.map((u) => (Number.isFinite(u.width) && u.width > 0 ? u.width : null)));
    const cols = partitions({ inner, columns: v.columns, t: sideT, material: innerMat, widths });
    parts.push(...cols.parts);
    if (stepped) stepTops(parts, cols.cols, tops, { W, panelT: shelfT, sidesOverTop });
    const z0 = inner.z0 + (v.backMode === 'groove' ? v.backInset + backT : 0), z1 = inner.z1;

    // הקומות של עמודה: [תחתית הפנים, גובה] לכל קומה; בין קומות — מדף קבוע (shelfT)
    const floorsOf = (i) => {
      const fl = units[i].floors;
      const y1c = stepped ? tops[i] - shelfT : inner.y1;   // תקרת הפנים של העמודה
      const free = y1c - inner.y0 - (fl.length - 1) * shelfT;
      const hs = resolveShares(free, fl.map((f) => (Number.isFinite(f.height) && f.height > 0 ? f.height : null)));
      const a = [];
      let y = inner.y0;
      for (const h of hs) { a.push(y); y += h + shelfT; }
      return { fl, a, hs, y1c };
    };

    let anyShelves = false;
    cols.cols.forEach((col, i) => {
      const { fl, a, hs } = floorsOf(i);
      const count = (t) => fl.filter((f) => t.includes(f.interior?.type)).length;
      const nDrawerFloors = count(['drawers']), nHang = count(['hanging']), nShelfFloors = count(['shelves', 'hanging']);
      fl.forEach((f, j) => {
        const it = f.interior || {}, y0 = a[j], y1 = a[j] + hs[j];
        const type = it.type || 'shelves';
        if (type === 'drawers') {
          // מגירות: חזיתות בתוך העמודה. בלי דלת — גלויות, עם ידית; מאחורי דלת — פנימיות, נסוגות, בלי ידית
          const dr = it.drawers || {};
          const innerDr = !sl && f.front?.type === 'wood';
          const n = Math.max(1, Math.round(dr.count ?? 3));
          const fm = dr.frontMaterial || v.bodyMaterial;
          const frontT = Number.isFinite(dr.frontT) ? dr.frontT : boardT(fm);
          const boxM = dr.boxMaterial || v.drawerBoxMaterial, botM = dr.bottomMaterial || v.drawerBottomMaterial;
          const zFront = innerDr ? z1 - 22 : z1;   // פנימית: מרווח לזרועות הצירים של הדלת
          for (let k = 0; k < n; k++) {
            const dy0 = y0 + k * (hs[j] / n), dy1 = dy0 + hs[j] / n;
            const tag = nDrawerFloors > 1 ? `f${j + 1}-` : '';
            const d = drawer({
              id: `drawer-${i + 1}-${tag}${k + 1}`, name: `מגירה ${i + 1}.${k + 1}${nDrawerFloors > 1 ? ` (קומה ${j + 1})` : ''}${innerDr ? ' — פנימית' : ''}`, x0: col.x0, x1: col.x1, y0: dy0, y1: dy1,
              zFront, depth: Math.min(zFront - z0 - 20, 500), frontT, boxT: boardT(boxM), bottomT: boardT(botM),
              frontMaterial: fm, boxMaterial: boxM, bottomMaterial: botM,
              slide: dr.slide || v.slide, handle: innerDr || !dr.handle || dr.handle === 'none' ? null : dr.handle,
              mountIds: [i === 0 ? 'side-L' : `partition-${i}`, i === cols.cols.length - 1 ? 'side-R' : `partition-${i + 1}`], mountBottom: inner.y0,
            });
            if (!innerDr && dr.finish && dr.finish !== 'flat') parts.push(...applyFinish(d.parts[0], dr.finish, { material: fm, normal: '+z' }));
            parts.push(...d.parts); hardware.push(...d.hardware);
          }
        } else if (type === 'hanging') {
          // תלייה: מוט 40 מתחת למדף העליון (או לתקרת הקומה), באמצע העומק
          const hg = it.hanging || {};
          const tag = nHang > 1 ? `-f${j + 1}` : '';
          let rodY = y1 - 40;
          if (hg.topShelf !== false && y1 - y0 > 1800) {
            const s = shelves({ col, y0: y1 - 400 - shelfT, y1: y1 - 400 + shelfT, z0, z1, count: 1, t: shelfT, material: shelfMat, adjustable: (hg.mode || 'adjustable') === 'adjustable', colIndex: i, prefix: `${nShelfFloors > 1 ? `f${j + 1}-` : ''}top-` });
            parts.push(...s.parts);
            rodY = y1 - 400 - 40;
          }
          hardware.push(...rod({ id: `rod-${i + 1}${tag}`, x0: col.x0, x1: col.x1, y: rodY, z: (z0 + z1) / 2, material: v.rodMaterial }).hardware);
          if (rodY - y0 < 1000) warnings.push(`עמודה ${i + 1}: גובה תלייה ${Math.round(rodY - y0)} מ"מ — פחות מ-1000, קצר לחולצות`);
        } else if (type === 'shelves') {
          const sh = it.shelves || {};
          const n = Math.max(0, Math.round(sh.count ?? 0));
          if (n > 0) anyShelves = true;
          const adjustable = (sh.mode || 'adjustable') === 'adjustable';
          const mat = !sh.material || sh.material === 'same' ? shelfMat : sh.material;
          const gaps = Array.isArray(sh.gaps) && sh.gaps.length === n + 1 && !sh.gaps.every((g) => g === null) ? resolveShares(y1 - y0 - n * shelfT, sh.gaps) : null;
          const s = shelves({ col, y0, y1, z0, z1, count: n, t: shelfT, material: mat, setback: adjustable ? 5 : 0, adjustable, colIndex: i, prefix: nShelfFloors > 1 ? `f${j + 1}-` : undefined, gaps });
          parts.push(...s.parts);
          if (adjustable) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
        }
        // מדף קבוע מעל הקומה (בין קומות)
        if (j < fl.length - 1) {
          const prefix = fl.length === 2 && j === 0 && type === 'drawers' ? 'over-drawers-' : `split${j + 1}-`;
          parts.push(...shelves({ col, y0: y1 - shelfT, y1: y1 + shelfT, z0, z1, count: 1, t: shelfT, material: shelfMat, adjustable: false, colIndex: i, prefix }).parts);
        }
        if (type === 'drawers' && col.x1 - col.x0 > LIMITS.drawerMaxWidth) warnings.push(`מגירה ברוחב ${Math.round(col.x1 - col.x0)} מ"מ — מעבר ל-${LIMITS.drawerMaxWidth} המומלצים`);
      });
    });

    if (stepped) parts.push(...steppedBack({ mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, cols: cols.cols, tops, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial, sideT, W, panelT: shelfT }));
    else parts.push(...back({ mode: v.backMode, outer: { w: W, y0: bottomY - shelfT, y1: topY }, inner, t: backT, grooveDepth: v.backGrooveDepth, inset: v.backInset, material: v.backMaterial }).parts);
    parts.push(...plinth({ inner, h: v.plinthH, setback: v.plinthSetback, t: sideT, d: D, material: v.bodyMaterial }).parts);
    if (stepped) parts.push(...steppedCrown({ cols: cols.cols, tops, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial, sideT, W }));
    else parts.push(...crown({ w: W, y: topY, h: v.crownH, t: sideT, d: D, material: v.bodyMaterial }).parts);

    // דלתות: הזזה מכסה הכול; אחרת — דלת לכל קומה עם חזית עץ. הדלת מכסה את הקומה:
    // התחתונה מהסוקל, העליונה עד הגג; בין קומות — עד המדף הקבוע.
    let extraD = 0;
    const mountFor = (i, side) => {
      const id = side === 'left' ? (i === 0 ? 'side-L' : `partition-${i}`) : (i === cols.cols.length - 1 ? 'side-R' : `partition-${i + 1}`);
      const p = parts.find((q) => q.id === id);
      return p ? { id, y: p.box.y } : null;
    };
    if (sl) {
      const doorT = boardT(v.doorMaterial);
      const sd = slidingDoors({ id: 'sliding', x0: 0, x1: W, y0: v.plinthH, y1: topY, zFront: D, leaves: v.slidingLeaves, t: doorT, material: v.doorMaterial, track: v.slidingSystem });
      parts.push(...sd.parts); hardware.push(...sd.hardware);
      extraD = sd.extraD;
      if (sd.leafWidth > 1200) warnings.push(`כנף הזזה ברוחב ${Math.round(sd.leafWidth)} מ"מ — כבדה; מומלץ עד 1200`);
    } else {
      cols.cols.forEach((col, i) => {
        const { fl, a, hs } = floorsOf(i);
        const nDoors = fl.filter((f) => f.front?.type === 'wood').length;
        const x0 = i === 0 ? 0 : col.x0 - sideT / 2, x1 = i === cols.cols.length - 1 ? W : col.x1 + sideT / 2;
        const colW = x1 - x0;
        fl.forEach((f, j) => {
          const fr = f.front || {};
          if (fr.type !== 'wood') return;
          const y0 = j === 0 ? v.plinthH : a[j], y1 = j === fl.length - 1 ? (stepped ? tops[i] : topY) : a[j] + hs[j];
          const leaves = fr.leaves === 1 || fr.leaves === 2 ? fr.leaves : colW > LIMITS.doorWidth ? 2 : 1;
          const doorT = boardT(fr.material);
          extraD = Math.max(extraD, doorT + (String(fr.finish || '').startsWith('fluted') ? 10 : 0));
          const tag = nDoors > 1 ? `-f${j + 1}` : '';
          const zoneName = nDoors > 1 ? ` קומה ${j + 1}` : '';
          for (let k = 0; k < leaves; k++) {
            const lx0 = x0 + (colW / leaves) * k, lx1 = lx0 + colW / leaves;
            const auto = leaves === 2 ? (k === 0 ? 'left' : 'right') : (i < cols.cols.length / 2 ? 'left' : 'right');
            const hingeSide = ['left', 'right', 'top', 'bottom'].includes(fr.hingeSide) ? fr.hingeSide : auto;
            const mount = mountFor(i, hingeSide === 'right' ? 'right' : 'left');
            const d = door({ id: `door-${i + 1}${tag}${leaves === 2 ? 'ab'[k] : ''}`, name: `דלת ${i + 1}${zoneName}${leaves === 2 ? (k === 0 ? ' שמאל' : ' ימין') : ''}`,
              x0: lx0, x1: lx1, y0, y1, zFront: D, type: 'wood', t: doorT, material: fr.material,
              handle: !fr.handle || fr.handle === 'none' ? null : fr.handle, hinge: fr.hinge, hingeSide,
              mountId: (mount || {}).id || null, mountBottom: (mount || {}).y ?? null,
              mounts: leaves === 2 ? (k === 0 ? { left: mountFor(i, 'left') } : { right: mountFor(i, 'right') }) : { left: mountFor(i, 'left'), right: mountFor(i, 'right') } });
            parts.push(...d.parts); hardware.push(...d.hardware);
            parts.push(...applyFinish(d.parts[0], fr.finish || 'flat', { material: fr.material, normal: '+z' }));
          }
        });
      });
    }

    warnings.push(...bodyWarnings({ H, colW: cols.colW, shelfT, hasShelves: anyShelves }));
    const sideExtra = (f) => (f === 'fluted-wide' ? 10 : f.startsWith('fluted') ? 8 : 0);
    const shift = sideExtra(v.sideLeftFinish);
    if (shift) { for (const p of parts) p.box = { ...p.box, x: p.box.x + shift }; for (const h of hardware) if (h.pos) h.pos = [h.pos[0] + shift, h.pos[1], h.pos[2]]; for (const p of parts) if (p.motion && p.motion.kind === 'hinge' && !p.motion.shifted) p.motion = { ...p.motion, pivot: [p.motion.pivot[0] + shift, p.motion.pivot[1], p.motion.pivot[2]], shifted: true }; }
    const lift = addWheels(parts, hardware, v, { x0: shift, x1: shift + W, y0: 0, z0: bodyZ, z1: bodyZ + bodyD });
    const unitGeo = cols.cols.map((c, i) => { const { hs } = floorsOf(i); return { width: c.x1 - c.x0, height: heights[i], floors: hs }; });
    return { parts, hardware, warnings, bounds: { w: W + shift + sideExtra(v.sideRightFinish), h: Math.max(...heights) + lift, d: D + extraD }, units, unitGeo, inactive: inactiveOf(units, v) };
  },
};

/** חזית קומה מההגדרות הישנות — אותו דבר לכל עמודה. */
function legacyFront(v, type) {
  return { type, leaves: 'auto', material: v.doorMaterial, finish: v.doorFinish, hinge: v.hinge, handle: v.handle, hingeSide: 'auto' };
}

/**
 * ההגדרות הישנות → units: מגירות בתחתית (אם יש) כקומה נעוצה בגובה המגירות, ומעליה
 * תלייה (העמודות הראשונות) או מדפים; דלת עץ (אם נבחרה) בקומה העליונה. זהה לבנייה הישנה.
 */
export function migrateUnits(v, layout) {
  const n = v.columns;
  const legacyDoor = v.doorType === 'wood' ? 'wood' : 'none';
  return Array.from({ length: n }, (_, i) => {
    const c = layout.cols[i] || {};
    const unit = { width: layout.widths[i] ?? null, height: Number.isFinite(c.height) && c.height > 0 ? c.height : null, floors: [] };
    if (v.drawersPerColumn > 0) {
      const fm = v.doorType === 'none' ? v.bodyMaterial : v.doorMaterial;
      const legacyT = v.doorType === 'none' ? v.sideT : boardT(v.doorMaterial);
      const drawers = { count: v.drawersPerColumn, frontMaterial: fm, handle: v.handle, slide: v.slide, boxMaterial: v.drawerBoxMaterial, bottomMaterial: v.drawerBottomMaterial };
      if (legacyT !== boardT(fm)) drawers.frontT = legacyT;   // בארון הישן חזית בלי דלתות = בעובי הדפנות
      unit.floors.push({ height: v.drawersPerColumn * v.drawerH, front: legacyFront(v, 'none'), interior: { type: 'drawers', drawers } });
    }
    const interior = i < v.hangingColumns
      ? { type: 'hanging', hanging: { topShelf: v.topShelf === 'yes', mode: v.shelvesMode } }
      : { type: 'shelves', shelves: { count: Number.isInteger(c.shelves) ? c.shelves : v.shelvesPerColumn, gaps: Array.isArray(c.gaps) ? c.gaps : null, material: 'same', mode: v.shelvesMode } };
    unit.floors.push({ height: null, front: legacyFront(v, legacyDoor), interior });
    return unit;
  });
}

function validUnits(u, n, v = {}) {
  if (!Array.isArray(u) || !u.length || !u.every((c) => c && Array.isArray(c.floors) && c.floors.length > 0)) return null;
  if (u.length === n) return u;
  const out = u.slice(0, n);
  while (out.length < n) out.push(newUnitOf(u[u.length - 1], v.newUnit, v));
  return out;
}

/**
 * עמודה חדשה: 'last' — העתק של האחרונה; 'hanging' / 'shelves' — קומה אחת; 'drawers' —
 * 3 מגירות למטה ומדפים מעל. החזית (חומר, צירים, ידית) מהקומה העליונה של האחרונה.
 */
export function newUnitOf(last, kind = 'last', v = {}) {
  const copy = JSON.parse(JSON.stringify(last));
  if (!kind || kind === 'last') return { ...copy, width: null };
  const top = copy.floors[copy.floors.length - 1] || {};
  const front = { ...legacyFront(v, 'none'), ...(top.front || {}) };
  const mode = top.interior?.shelves?.mode || top.interior?.hanging?.mode || v.shelvesMode || 'adjustable';
  const shelvesFloor = { height: null, front, interior: { type: 'shelves', shelves: { count: v.shelvesPerColumn ?? 5, gaps: null, material: 'same', mode } } };
  if (kind === 'hanging') return { width: null, height: null, floors: [{ height: null, front, interior: { type: 'hanging', hanging: { topShelf: true, mode } } }] };
  if (kind === 'drawers') {
    const drawers = { count: 3, frontMaterial: front.type === 'wood' ? front.material : v.bodyMaterial, handle: front.handle || v.handle, slide: v.slide, boxMaterial: v.drawerBoxMaterial, bottomMaterial: v.drawerBottomMaterial };
    return { width: null, height: null, floors: [{ height: 600, front: { ...front, type: 'none' }, interior: { type: 'drawers', drawers } }, shelvesFloor] };
  }
  return { width: null, height: null, floors: [shelvesFloor] };
}

/** הגובה הנמוך ביותר לעמודה: סוקל, רצפה, קומות נעוצות ומדפים ביניהן, גג, כרכוב ותא של 100. */
function unitMinH(v, u) {
  const pinned = u.floors.reduce((s, f) => s + (Number.isFinite(f.height) && f.height > 0 ? f.height : 0), 0);
  return v.plinthH + 2 * v.shelfT + pinned + (u.floors.length - 1) * v.shelfT + v.crownH + 100;
}
function legacyMinH(v) {
  const shelfT = v.shelfT ?? 18;
  const drawersH = (v.drawersPerColumn ?? 0) > 0 ? v.drawersPerColumn * (v.drawerH ?? 200) + shelfT : 0;
  return (v.plinthH ?? 80) + 2 * shelfT + drawersH + (v.crownH ?? 0) + 100;
}

/** "לא בתוקף": מה שנבחר ולא חל — עם הסבר, ליד השדה בכרטיס. */
function inactiveOf(units, v) {
  const out = [];
  units.forEach((u, i) => u.floors.forEach((f, j) => {
    const at = `units.${i}.floors.${j}`, where = `עמודה ${i + 1}${u.floors.length > 1 ? `, קומה ${j + 1}` : ''}`;
    if (sliding(v) && f.front?.type === 'wood') out.push({ path: `${at}.front.type`, column: i, floor: j, reason: `${where}: דלתות ההזזה מכסות את כל הארון — הדלת לא נבנית` });
    if (f.interior?.type === 'drawers' && f.front?.type === 'wood' && !sliding(v) && f.interior.drawers?.handle && f.interior.drawers.handle !== 'none') out.push({ path: `${at}.interior.drawers.handle`, column: i, floor: j, reason: `${where}: מגירות פנימיות (מאחורי דלת) — בלי ידית` });
  }));
  return out;
}
