// מנוע העמודות והקומות (docs/elements-spec.md, שלב 7) — משותף לספרייה ולאבן
// "עמודות וקומות" במתכונים. הקוד עבר כמות שהוא מ-templates/bookcase.js, כך
// שהספרייה נבנית בדיוק כמו קודם (בדיקת הזהות ב-model-check).
//
// שני שלבים, כי סדר החלקים חשוב לרשימות: unitsInterior — מחיצות, מדפים קבועים
// בין קומות, מדפים ותאורה; unitsFronts — מגירות ודלתות (אחרי הגב, הסוקל והכרכוב).
// שניהם דוחפים ל-ctx.parts / ctx.hardware / ctx.warnings, כמו הקוד המקורי.

import { partitions, shelves, door, drawer, part } from './blocks.js';
import { material } from './materials.js';
import { resolveShares } from './layout.js';
import { applyFinish, resolveSame, stepTops } from './templates/common.js';
import { isBundle, ledPlaces, lightFloor } from './lighting.js';

function boardThickness(id) { return material(id).t || 18; }
const sameOr = (x, dflt) => (!x || x === 'same' ? dflt : x);
/** עובי המחיצה שמימין לעמודה i (units[i].partitionR.t), או של המוצר. */
export function partT(units, i, dflt) { const t = units?.[i]?.partitionR?.t; return Number.isFinite(t) && t > 0 ? t : dflt; }
/** מחיצות בעובי ובחומר שונים לכל אחת — כמו partitions() של blocks.js. */
function customPartitions({ inner, columns, widths, t, material: mat }) {
  const parts = [], cols = [];
  let x0 = inner.x0;
  for (let c = 0; c < columns; c++) {
    const w = widths[c];
    cols.push({ x0, x1: x0 + w });
    if (c < columns - 1) {
      parts.push(part(`partition-${c + 1}`, `מחיצה ${c + 1}`, { x: x0 + w, y: inner.y0, z: inner.z0, w: t(c), h: inner.y1 - inner.y0, d: inner.z1 - inner.z0 },
        { axis: 'x', grain: 'y', material: mat(c), qtyKey: `partition-${t(c)}-${mat(c)}`, edges: { front: true } }));
      x0 += w + t(c);
    }
  }
  return { parts, cols, colW: Math.max(...widths), free: widths.reduce((a, b) => a + b, 0) };
}
function backThickness(id) { return material(id).t || 6; }
/** עומק שסטריפים מודבקים מוסיפים לחזית. */
function fluteDepth(f) { return typeof f === 'string' && f.startsWith('fluted') ? (f === 'fluted-wide' ? 10 : 8) : 0; }

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

/**
 * מחיצות ותוכן הקומות. `o`: units, columns, inner, W, D, sideT, shelfT, bottomY, topY,
 * tops, stepped, colY1, z0, z1, innerMat, shelfMat, sidesOverTop, shelvesMode, led.
 * מחזיר { cols, floorsOf } לשלב החזיתות ולגאומטריה.
 */
export function unitsInterior(ctx, o) {
  const { parts, hardware } = ctx;
  const { units, inner, W, sideT, shelfT, bottomY, topY, tops, stepped, colY1, z0, z1, innerMat, shelfMat, sidesOverTop } = o;
  const v = { columns: o.columns, shelvesMode: o.shelvesMode, led: o.led };
  // תאורה מפורטת (lighting.js) — לפס מפורט, או למיקום חדש (מלמעלה / גב) גם עם הפס הישן; אחרת — הקוד הישן
  const newPlace = (x) => x && !['none', 'sides', 'shelves', 'same'].includes(x);
  const light = o.light && (!isBundle(o.light.stripId) || newPlace(o.led) || o.units.some((u) => u.floors.some((f) => newPlace(f.led)))) ? o.light : null;
    // מחיצה לכל זוג עמודות: עובי וחומר משלה (units[i].partitionR — המחיצה מימין לעמודה i);
    // בלי הגדרה — של המוצר. כשכולן כברירת המחדל — המחיצות הרגילות, בדיוק כמו קודם.
    const pT = (i) => partT(units, i, sideT), pM = (i) => sameOr(units[i]?.partitionR?.material, innerMat);
    const custom = units.slice(0, -1).some((u, i) => pT(i) !== sideT || pM(i) !== innerMat);
    const widths = resolveShares(inner.x1 - inner.x0 - units.slice(0, -1).reduce((s0, u, i) => s0 + pT(i), 0), units.map((u) => (Number.isFinite(u.width) && u.width > 0 ? u.width : null)));
    const cols = custom ? customPartitions({ inner, columns: v.columns, widths, t: pT, material: pM }) : partitions({ inner, columns: v.columns, t: sideT, material: innerMat, widths });
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
      const ceilings = [], floorShelves = [];
      for (let j = 1; j < fl.length; j++) {
        const bs = shelves({ col, y0: b[j] - shelfT, y1: b[j] + shelfT, z0, z1, count: 1, t: shelfT, material: shelfMat, adjustable: false, colIndex: i, prefix: splitPrefix(fl.length, j) }).parts;
        ceilings[j - 1] = bs[0];
        parts.push(...bs);
      }
      fl.forEach((f, j) => {
        const sh = f.interior?.type === 'shelves' ? f.interior.shelves || {} : null;
        if (!sh) return;
        const adj = (sh.mode || v.shelvesMode) === 'adjustable';
        const a = j === 0 ? inner.y0 : b[j], top = j === fl.length - 1 ? y1c : b[j + 1] - shelfT;
        const n = Math.max(0, Math.round(sh.count ?? 0));
        const glassShelf = sh.material && material(sh.material).kind === 'glass' ? sh.material : null;
        const mat = !sh.material || sh.material === 'same' || glassShelf ? shelfMat : sh.material;
        // עובי המדפים בקומה: שהוקלד, או של החומר שנבחר לקומה, או של המוצר
        const t = Number.isFinite(sh.t) && sh.t > 0 ? sh.t : !sh.material || sh.material === 'same' || glassShelf ? shelfT : boardThickness(mat);
        const gaps = fl.length === 1 && Array.isArray(sh.gaps) && sh.gaps.length === n + 1 && !sh.gaps.every((g) => g === null) ? resolveShares(top - a - n * t, sh.gaps) : null;
        const s = shelves({ col, y0: a, y1: top, z0, z1, count: n, t, material: mat, setback: adj ? 5 : 0, adjustable: adj, colIndex: i, prefix: shelfPrefix(fl.length, j), gaps });
        if (glassShelf) {
          // מדף זכוכית (מאחורי ויטרינה): אותו מיקום, בעובי הזכוכית
          const gt = material(glassShelf).t || 8;
          for (const p of s.parts) { p.material = glassShelf; p.box = { ...p.box, h: gt }; p.edges = {}; p.note = 'מדף זכוכית מחוסם, מלוטש — על תומכי זכוכית'; p.qtyKey = `glass-shelf-${Math.round(p.box.w)}`; }
        }
        parts.push(...s.parts);
        floorShelves[j] = { parts: s.parts, adj: adj && n > 0, glass: !!glassShelf && n > 0 };
        if (adj) s.parts.forEach((p) => hardware.push({ id: `${p.id}-pins`, kind: 'shelf-pin', material: 'hw:shelf-pin', qty: 4, for: p.id }));
      });
      // תאורה: לכל קומה (f.led), ובלי הגדרה — של המוצר
      if (light) {
        const boardAt = (id) => parts.find((p) => p.id === id);
        const roof = parts.find((p) => (p.id === 'top' || /^top-\d/.test(p.id)) && p.box.x <= col.x0 + 1 && p.box.x + p.box.w >= col.x1 - 1);
        fl.forEach((f, j) => {
          const places = ledPlaces(f.led && f.led !== 'same' ? f.led : v.led);
          if (!places.size) return;
          const a = j === 0 ? inner.y0 : b[j], top = j === fl.length - 1 ? y1c : b[j + 1] - shelfT;
          const fs = floorShelves[j] || { parts: [], adj: false, glass: false };
          lightFloor({ hardware, warnings: ctx.warnings || [] }, light, { i, j, nFloors: fl.length, col, a, top, z0, z1, places,
            ceiling: j === fl.length - 1 ? roof : ceilings[j], shelves: fs.parts, adjustable: fs.adj, glassShelves: fs.glass,
            boards: { L: boardAt(i === 0 ? 'side-L' : `partition-${i}`), R: boardAt(i === cols.cols.length - 1 ? 'side-R' : `partition-${i + 1}`) },
            door: ['wood', 'glass'].includes(f.front?.type) });
        });
      } else if (fl.some((f) => f.led && f.led !== 'same')) {
        fl.forEach((f, j) => {
          const led = f.led && f.led !== 'same' ? f.led : v.led;
          const a = j === 0 ? inner.y0 : b[j], top = j === fl.length - 1 ? y1c : b[j + 1] - shelfT;
          if (led === 'sides') {
            for (const [tag, x] of [['L', col.x0], ['R', col.x1 - 6]]) hardware.push({ id: `led-${i + 1}-f${j + 1}-${tag}`, kind: 'led', material: 'hw:led-strip', qty: Math.round((top - a) / 100) / 10, pos: [x, a, z1 - 30], len: top - a, note: `עמודה ${i + 1}, קומה ${j + 1}, ${tag === 'L' ? 'שמאל' : 'ימין'}` });
          } else if (led === 'shelves') {
            for (const p of parts.filter((p) => p.axis === 'y' && p.box.x >= col.x0 - 1 && p.box.x + p.box.w <= col.x1 + 1 && p.id.includes('shelf') && p.box.y > a - 1 && p.box.y < top + 1)) {
              hardware.push({ id: `led-${p.id}`, kind: 'led', material: 'hw:led-strip', qty: Math.round(p.box.w / 100) / 10, pos: [p.box.x, p.box.y - 4, z1 - 30], len: p.box.w, horizontal: true, note: `מתחת ל${p.name}` });
            }
          }
        });
      } else if (v.led === 'sides') {
        for (const [tag, x] of [['L', col.x0], ['R', col.x1 - 6]]) {
          hardware.push({ id: `led-${i + 1}-${tag}`, kind: 'led', material: 'hw:led-strip', qty: Math.round((y1c - inner.y0) / 100) / 10, pos: [x, inner.y0, z1 - 30], len: y1c - inner.y0, note: `עמודה ${i + 1}, ${tag === 'L' ? 'שמאל' : 'ימין'}` });
        }
      } else if (v.led === 'shelves') {
        for (const p of parts.filter((p) => p.axis === 'y' && p.box.x >= col.x0 - 1 && p.box.x + p.box.w <= col.x1 + 1 && p.id.includes('shelf'))) {
          hardware.push({ id: `led-${p.id}`, kind: 'led', material: 'hw:led-strip', qty: Math.round(p.box.w / 100) / 10, pos: [p.box.x, p.box.y - 4, z1 - 30], len: p.box.w, horizontal: true, note: `מתחת ל${p.name}` });
        }
      }
    });

  return { cols, floorsOf };
}

/**
 * מגירות ודלתות לכל קומה. `o` כמו ב-unitsInterior, ובנוסף plinthH (תחתית החזית
 * התחתונה), doorMaterial (ברירת מחדל לחזית מגירות) ו-limits. מחזיר { maxFront }.
 */
export function unitsFronts(ctx, o, cols, floorsOf) {
  const { parts, hardware, warnings } = ctx;
  const { units, W, D, sideT, shelfT, bottomY, topY, tops, stepped, colY1, z0 } = o;
  const L = o.limits;
  const v = { columns: o.columns, plinthH: o.plinthH, doorMaterial: o.doorMaterial };
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
      const x0 = i === 0 ? 0 : col.x0 - partT(units, i - 1, sideT) / 2;
      const x1 = i === cols.cols.length - 1 ? W : col.x1 + partT(units, i, sideT) / 2;
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
  return { maxFront };
}

/** מזהה רכיב → העמודה והקומה שלו (המזהים של המנוע). */
export function locateUnitPart(id, units) {
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
}
