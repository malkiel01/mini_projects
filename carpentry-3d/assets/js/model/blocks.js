// אבני הבניין — פונקציות שמקבלות מלבן ועובי ומחזירות חלקים.
//
// מערכת הצירים, בכל הכלי: X רוחב (ימינה חיובי), Y גובה (למעלה חיובי),
// Z עומק (קדימה חיובי). הראשית בפינה התחתונה-השמאלית-האחורית של הגוף.
// הכול במ"מ. אין כאן DOM ואין three.js — הקובץ רץ גם ב-node.
//
// חלק הוא תיבה מקבילה לצירים:
//   box: { x, y, z, w, h, d }   פינת המינימום והמידות בעולם
//   axis: 'x' | 'y' | 'z'       ציר העובי (דופן — x, מדף — y, גב/דלת — z)
//   grain: 'x' | 'y' | 'z'      ציר הסיבים (כיוון האורך בחיתוך)
//   edges: { front, back, top, bottom, left, right }  צדדים שמקבלים קנט
// מידות החיתוך (אורך × רוחב × עובי) נגזרות מהשלושה האלה ב-cutSize — כך
// רשימת החיתוך והתלת מימד קוראים את אותו חלק ולעולם לא יסתרו זה את זה.

/** יוצר חלק. `box` הוא פינת המינימום + מידות. */
export function part(id, name, box, { axis, grain, material, qtyKey, edges = {}, note } = {}) {
  return { id, name, qtyKey: qtyKey || id, box, axis, grain, material, edges, note };
}

/** מידות החיתוך של חלק: אורך בכיוון הסיבים, רוחב בציר השלישי, עובי בציר העובי. */
export function cutSize(p) {
  const dims = { x: p.box.w, y: p.box.h, z: p.box.d };
  const other = ['x', 'y', 'z'].filter((a) => a !== p.axis);
  // ציר הסיבים חייב להיות אחד משני צירי המישור; אם לא צוין כראוי — הארוך מביניהם.
  const grain = other.includes(p.grain) ? p.grain : (dims[other[0]] >= dims[other[1]] ? other[0] : other[1]);
  const across = other.find((a) => a !== grain);
  return { l: round1(dims[grain]), w: round1(dims[across]), t: round1(dims[p.axis]) };
}

function round1(v) { return Math.round(v * 10) / 10; }

/**
 * תיבה: שתי דפנות, גג, רצפה.
 * `sidesOverTop` — הדפנות עוברות (גג ורצפה ביניהן), אחרת הגג והרצפה
 * עוברים והדפנות ביניהם. `bottomY` הוא גובה פני הרצפה מהרצפה האמיתית
 * (מעל הסוקל), ו-`topY` הוא גובה פני הגג העליונים.
 */
export function carcass({ w, d, z = 0, y0 = 0, bottomY, topY, sideT, panelT, sidesOverTop, material, prefix = '' }) {
  const parts = [];
  // `y0` — תחתית הדפנות כשהן עוברות: הרצפה (0) לגוף עומד, או תחתית התיבה לגוף תלוי.
  const sideY0 = sidesOverTop ? y0 : bottomY;
  const sideY1 = sidesOverTop ? topY : topY - panelT;
  const panelX0 = sidesOverTop ? sideT : 0;
  const panelW = sidesOverTop ? w - 2 * sideT : w;
  const edgeSide = { front: true, top: !sidesOverTop ? false : true, bottom: false };

  parts.push(part(`${prefix}side-L`, 'דופן שמאל',
    { x: 0, y: sideY0, z, w: sideT, h: sideY1 - sideY0, d },
    { axis: 'x', grain: 'y', material, qtyKey: `${prefix}side`, edges: edgeSide }));
  parts.push(part(`${prefix}side-R`, 'דופן ימין',
    { x: w - sideT, y: sideY0, z, w: sideT, h: sideY1 - sideY0, d },
    { axis: 'x', grain: 'y', material, qtyKey: `${prefix}side`, edges: edgeSide }));
  parts.push(part(`${prefix}bottom`, 'רצפה',
    { x: panelX0, y: bottomY - panelT, z, w: panelW, h: panelT, d },
    { axis: 'y', grain: 'x', material, edges: { front: true } }));
  parts.push(part(`${prefix}top`, 'גג',
    { x: panelX0, y: topY - panelT, z, w: panelW, h: panelT, d },
    { axis: 'y', grain: 'x', material, edges: { front: true, left: !sidesOverTop, right: !sidesOverTop } }));

  return {
    parts,
    inner: { x0: sideT, x1: w - sideT, y0: bottomY, y1: topY - panelT, z0: z, z1: z + d },
  };
}

/** מחיצות אנכיות שמחלקות את הפנים ל-`columns` עמודות שוות. מחזיר גם את גבולות העמודות. */
export function partitions({ inner, columns, t, material, prefix = '' }) {
  const parts = [];
  const innerW = inner.x1 - inner.x0;
  const colW = (innerW - (columns - 1) * t) / columns;
  const cols = [];
  for (let c = 0; c < columns; c++) {
    const x0 = inner.x0 + c * (colW + t);
    cols.push({ x0, x1: x0 + colW });
    if (c < columns - 1) {
      parts.push(part(`${prefix}partition-${c + 1}`, `מחיצה ${c + 1}`,
        { x: x0 + colW, y: inner.y0, z: inner.z0, w: t, h: inner.y1 - inner.y0, d: inner.z1 - inner.z0 },
        { axis: 'x', grain: 'y', material, qtyKey: `${prefix}partition`, edges: { front: true } }));
    }
  }
  return { parts, cols, colW };
}

/**
 * מדפים בעמודה: `count` מדפים במרווחים שווים בין y0 ל-y1.
 * `setback` — נסיגה מהחזית (מדף מתכוונן נסוג מעט כדי לא לבלוט).
 */
export function shelves({ col, y0, y1, z0, z1, count, t, material, setback = 0, adjustable, prefix = '', colIndex }) {
  const parts = [];
  const gap = (y1 - y0 - count * t) / (count + 1);
  for (let s = 1; s <= count; s++) {
    const y = y0 + s * gap + (s - 1) * t;
    parts.push(part(`${prefix}shelf-${colIndex + 1}-${s}`, `מדף ${colIndex + 1}.${s}`,
      { x: col.x0, y, z: z0, w: col.x1 - col.x0, h: t, d: z1 - z0 - setback },
      { axis: 'y', grain: 'x', material, qtyKey: `${prefix}shelf-w${Math.round(col.x1 - col.x0)}`,
        edges: { front: true }, note: adjustable ? 'מתכוונן' : 'קבוע' }));
  }
  return { parts, gap };
}

/**
 * גב. שלושה מצבים:
 *  groove  — בחריץ: הגב נכנס `grooveDepth` לתוך הדפנות/הגג/הרצפה, במרחק `inset` מהקצה האחורי.
 *  overlay — מולבש מאחור על כל הגוף; הפנלים כבר נבנו קצרים ב-t.
 *  none    — אין גב.
 */
export function back({ mode, outer, inner, t, grooveDepth = 8, inset = 10, material, prefix = '' }) {
  if (mode === 'none') return { parts: [] };
  if (mode === 'overlay') {
    return { parts: [part(`${prefix}back`, 'גב',
      { x: 0, y: outer.y0, z: 0, w: outer.w, h: outer.y1 - outer.y0, d: t },
      { axis: 'z', grain: 'y', material })] };
  }
  const g = grooveDepth;
  return { parts: [part(`${prefix}back`, 'גב (בחריץ)',
    { x: inner.x0 - g, y: inner.y0 - g, z: inset, w: inner.x1 - inner.x0 + 2 * g, h: inner.y1 - inner.y0 + 2 * g, d: t },
    { axis: 'z', grain: 'y', material, note: `חריץ ${g} מ"מ, ${inset} מ"מ מהקצה האחורי` })] };
}

/** סוקל: לוח חזיתי נסוג מתחת לרצפה. */
export function plinth({ inner, h, setback, t, d, material, prefix = '' }) {
  if (h <= 0) return { parts: [] };
  return { parts: [part(`${prefix}plinth`, 'סוקל',
    { x: inner.x0, y: 0, z: d - setback - t, w: inner.x1 - inner.x0, h, d: t },
    { axis: 'z', grain: 'x', material, edges: { front: true } })] };
}

/** כרכוב: לוח חזיתי על הגג, בולט מעלה. */
export function crown({ w, y, h, t, d, material, prefix = '' }) {
  if (h <= 0) return { parts: [] };
  return { parts: [part(`${prefix}crown`, 'כרכוב',
    { x: 0, y, z: d - t, w, h, d: t },
    { axis: 'z', grain: 'x', material, edges: { front: true, top: true, left: true, right: true } })] };
}

/**
 * דלת אחת, מולבשת (overlay) על חזית. `x0..x1` הרוחב שהיא מכסה, `y0..y1` הגובה.
 * עץ — לוח אחד. ויטרינה — מסגרת (שני זקפים ושני קושרות) ושמשה.
 * `hingeSide` — 'left' או 'right'; הידית בצד הנגדי.
 */
export function door({ id, name, x0, x1, y0, y1, zFront, type, t, frameW = 60, material, glass, handle, hinge, hingeSide, gap = 2 }) {
  const parts = [];
  const hardware = [];
  const x = x0 + gap / 2, w = x1 - x0 - gap, y = y0 + gap / 2, h = y1 - y0 - gap;
  const edgesAll = { front: true, top: true, bottom: true, left: true, right: true };

  if (type === 'wood') {
    parts.push(part(id, 'דלת עץ', { x, y, z: zFront, w, h, d: t },
      { axis: 'z', grain: 'y', material, qtyKey: `door-${Math.round(w)}x${Math.round(h)}`, edges: edgesAll, note: name }));
  } else {
    parts.push(part(`${id}-stile-L`, 'זקף דלת', { x, y, z: zFront, w: frameW, h, d: t },
      { axis: 'z', grain: 'y', material, qtyKey: `door-stile-${Math.round(h)}`, edges: edgesAll, note: name }));
    parts.push(part(`${id}-stile-R`, 'זקף דלת', { x: x + w - frameW, y, z: zFront, w: frameW, h, d: t },
      { axis: 'z', grain: 'y', material, qtyKey: `door-stile-${Math.round(h)}`, edges: edgesAll, note: name }));
    const railW = w - 2 * frameW;
    parts.push(part(`${id}-rail-T`, 'קושרת דלת', { x: x + frameW, y: y + h - frameW, z: zFront, w: railW, h: frameW, d: t },
      { axis: 'z', grain: 'x', material, qtyKey: `door-rail-${Math.round(railW)}`, edges: edgesAll, note: name }));
    parts.push(part(`${id}-rail-B`, 'קושרת דלת', { x: x + frameW, y, z: zFront, w: railW, h: frameW, d: t },
      { axis: 'z', grain: 'x', material, qtyKey: `door-rail-${Math.round(railW)}`, edges: edgesAll, note: name }));
    // השמשה נכנסת 10 מ"מ לתוך המסגרת מכל צד (פלץ).
    const rebate = 10;
    parts.push(part(`${id}-glass`, 'זכוכית דלת',
      { x: x + frameW - rebate, y: y + frameW - rebate, z: zFront + t / 2 - 2, w: w - 2 * frameW + 2 * rebate, h: h - 2 * frameW + 2 * rebate, d: 4 },
      { axis: 'z', grain: 'y', material: glass, qtyKey: `glass-${Math.round(w - 2 * frameW + 2 * rebate)}x${Math.round(h - 2 * frameW + 2 * rebate)}`, note: name }));
  }

  // צירים: לפי גובה הדלת, כמקובל אצל יצרני הפרזול.
  const hinges = h <= 900 ? 2 : h <= 1600 ? 3 : h <= 2100 ? 4 : 5;
  const hx = hingeSide === 'left' ? x + 22 : x + w - 22;
  for (let i = 0; i < hinges; i++) {
    const hy = y + 100 + (h - 200) * (hinges === 1 ? 0.5 : i / (hinges - 1));
    hardware.push({ id: `${id}-hinge-${i + 1}`, kind: 'hinge', material: hinge, pos: [hx, hy, zFront], qty: 1, for: id });
  }
  if (handle) {
    const kx = hingeSide === 'left' ? x + w - 40 : x + 40;
    // ידית: בדלת נמוכה — בשני שלישים מגובהה; בדלת גבוהה — בגובה 1000 מהרצפה
    // (גובה יד), ולא יותר מ-100 מתחת לקצה העליון.
    const ky = h > 1200 ? Math.min(y + h - 100, 1000) : y + h * 0.66;
    hardware.push({ id: `${id}-handle`, kind: 'handle', material: handle, pos: [kx, ky, zFront + t], qty: 1, for: id });
  }
  return { parts, hardware };
}

/**
 * מגירה: חזית מולבשת, וארגז (שתי דפנות, גב, תחתית בחריץ). המסילות
 * דורשות מרווח (`slideGap`) בכל צד — זה מה שקובע את רוחב הארגז.
 * `x0..x1` רוחב הפתח שהחזית מכסה, `y0..y1` גובה החזית, `zFront` חזית הגוף.
 */
export function drawer({ id, name, x0, x1, y0, y1, zFront, depth, frontT, boxT = 18, bottomT = 6, slideGap = 13,
  frontMaterial, boxMaterial, bottomMaterial, slide, handle, gap = 2 }) {
  const parts = [];
  const hardware = [];
  const edgesAll = { front: true, top: true, bottom: true, left: true, right: true };
  const fx = x0 + gap / 2, fw = x1 - x0 - gap, fy = y0 + gap / 2, fh = y1 - y0 - gap;
  parts.push(part(id, 'חזית מגירה', { x: fx, y: fy, z: zFront, w: fw, h: fh, d: frontT },
    { axis: 'z', grain: 'x', material: frontMaterial, qtyKey: `drawer-front-${Math.round(fw)}x${Math.round(fh)}`, edges: edgesAll, note: name }));

  // הארגז: בתוך הפתח, פחות מרווח המסילות; נמוך מהחזית ב-30; עמוק פחות מהגוף.
  const bx0 = x0 + slideGap, bx1 = x1 - slideGap;
  const bh = Math.max(60, fh - 30);
  const by = y0 + 15;
  const bd = depth;
  const bz = zFront - bd;
  parts.push(part(`${id}-side-L`, 'דופן מגירה', { x: bx0, y: by, z: bz, w: boxT, h: bh, d: bd },
    { axis: 'x', grain: 'z', material: boxMaterial, qtyKey: `drawer-side-${Math.round(bd)}x${Math.round(bh)}`, edges: { top: true }, note: name }));
  parts.push(part(`${id}-side-R`, 'דופן מגירה', { x: bx1 - boxT, y: by, z: bz, w: boxT, h: bh, d: bd },
    { axis: 'x', grain: 'z', material: boxMaterial, qtyKey: `drawer-side-${Math.round(bd)}x${Math.round(bh)}`, edges: { top: true }, note: name }));
  const innerW = bx1 - bx0 - 2 * boxT;
  parts.push(part(`${id}-back`, 'גב מגירה', { x: bx0 + boxT, y: by, z: bz, w: innerW, h: bh, d: boxT },
    { axis: 'z', grain: 'x', material: boxMaterial, qtyKey: `drawer-back-${Math.round(innerW)}x${Math.round(bh)}`, edges: { top: true }, note: name }));
  parts.push(part(`${id}-front-in`, 'חזית פנימית', { x: bx0 + boxT, y: by, z: zFront - boxT, w: innerW, h: bh, d: boxT },
    { axis: 'z', grain: 'x', material: boxMaterial, qtyKey: `drawer-back-${Math.round(innerW)}x${Math.round(bh)}`, edges: { top: true }, note: name }));
  // תחתית בחריץ 8 מ"מ, 10 מ"מ מהקצה התחתון.
  const g = 8;
  parts.push(part(`${id}-bottom`, 'תחתית מגירה', { x: bx0 + boxT - g, y: by + 10, z: bz + boxT - g, w: innerW + 2 * g, h: bottomT, d: bd - 2 * boxT + 2 * g },
    { axis: 'y', grain: 'x', material: bottomMaterial, qtyKey: `drawer-bottom-${Math.round(innerW + 2 * g)}x${Math.round(bd - 2 * boxT + 2 * g)}`, note: `${name} — חריץ ${g}` }));

  hardware.push({ id: `${id}-slides`, kind: 'slide', material: slide, qty: 1, for: id, note: `זוג, אורך ${Math.round(bd / 50) * 50}` });
  if (handle) hardware.push({ id: `${id}-handle`, kind: 'handle', material: handle, pos: [fx + fw / 2, fy + fh / 2, zFront + frontT], qty: 1, for: id, horizontal: true });
  return { parts, hardware };
}

/** מוט תלייה: פרזול לרוחב העמודה, בגובה נתון. */
export function rod({ id, x0, x1, y, z, material }) {
  return { hardware: [{ id, kind: 'rod', material, qty: 1, pos: [x0, y, z], len: x1 - x0, note: `אורך ${Math.round(x1 - x0)}` }] };
}

/**
 * דלתות הזזה: שתיים או שלוש כנפיים חופפות על שתי מסילות, בחזית הגוף.
 * כל כנף רחבה ב-`overlap` מחלקה; הכנפיים לסירוגין במסילה הקדמית/האחורית.
 */
export function slidingDoors({ id, x0, x1, y0, y1, zFront, leaves, t, overlap = 40, material, track }) {
  const parts = [];
  const hardware = [];
  const W = x1 - x0;
  const lw = (W + (leaves - 1) * overlap) / leaves;
  for (let i = 0; i < leaves; i++) {
    const lx = x0 + i * (lw - overlap);
    const z = zFront + (i % 2 === 0 ? 0 : t + 4);   // המסילה האחורית צמודה לגוף, הקדמית לפניה
    parts.push(part(`${id}-${i + 1}`, 'דלת הזזה', { x: lx, y: y0 + 2, z, w: lw, h: y1 - y0 - 4, d: t },
      { axis: 'z', grain: 'y', material, qtyKey: `slide-door-${Math.round(lw)}x${Math.round(y1 - y0 - 4)}`, edges: { front: true, top: true, bottom: true, left: true, right: true }, note: `כנף ${i + 1}` }));
  }
  hardware.push({ id: `${id}-track`, kind: 'track', material: track, qty: 1, note: `מסילה כפולה ${Math.round(W)}` });
  return { parts, hardware, leafWidth: lw };
}

/** רגל: קורה אנכית מרובעת. */
export function leg({ id, x, z, y0, y1, size, material, note }) {
  return part(id, 'רגל', { x, y: y0, z, w: size, h: y1 - y0, d: size },
    { axis: 'x', grain: 'y', material, qtyKey: `leg-${Math.round(y1 - y0)}`, note });
}
