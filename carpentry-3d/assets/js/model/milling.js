// חירוץ CNC: לוח אחד שהפאה החיצונית שלו מכורסמת — בלי להדביק חתיכות.
//
// שתי משפחות:
//  • "דמוי סטריפים" (flutes): חריצים ישרים לכל גובה הלוח, צלעות ביניהם,
//    ופס חלק בשוליים — כמו לוח MDF מחורץ.
//  • "מסגרות" (frames): חריץ מלבני (או כפול) במרחק קבוע מהקצה — דלת קלאסית;
//    גם בשני פנלים, עליון ותחתון.
// לעומת "סטריפים" (פסים נפרדים שמודבקים, כל אחד שורה ברשימת החיתוך) כאן
// נשאר חלק אחד, ועליו הוראת כרסום למכונה.
//
// כל דוגמה מתוארת כרשימת מלבני חריץ על הפאה, בקואורדינטות שלה: u לרוחב
// הפאה (מתחילתה), v לגובה (מלמטה). הצופה בונה מזה גוף אמיתי (צלעות/מסגרות
// בולטות), המשקל מחסיר את הנפח, והפלט מפרט את ההוראה.
//
// החלק נושא `mill = { pattern, normal, ...פרמטרי הדוגמה }`.

import { material } from './materials.js';

export const MILL_PATTERNS = {
  'milled-fine': { name: 'חירוץ CNC — דמוי סטריפים דק (6/10)', kind: 'flutes', groove: 6, rib: 10, depth: 5, margin: 20 },
  'milled-wide': { name: 'חירוץ CNC — דמוי סטריפים רחב (10/20)', kind: 'flutes', groove: 10, rib: 20, depth: 6, margin: 30 },
  'milled-frame': { name: 'חירוץ CNC — מסגרת', kind: 'frames', groove: 6, depth: 4, inset: 70, rings: 1, ringGap: 0, panels: 1 },
  'milled-frame-double': { name: 'חירוץ CNC — מסגרת כפולה', kind: 'frames', groove: 6, depth: 4, inset: 60, rings: 2, ringGap: 20, panels: 1 },
  'milled-frame-2': { name: 'חירוץ CNC — שני פנלים, מסגרת כפולה', kind: 'frames', groove: 6, depth: 4, inset: 60, rings: 2, ringGap: 20, panels: 2, split: 0.62 },
};

/** רוחב הפאה (u) וגובהה (v) של חלק מחורץ. */
export function millFace(part) {
  const b = part.box;
  return { U: part.mill.normal === '+z' ? b.w : b.d, V: b.h };
}

/** מיקומי החריצים הישרים לאורך `len`: [[a, b], …], ממורכזים בין השוליים. */
export function fluteGrooves(len, { groove, rib, margin }) {
  const avail = len - 2 * margin;
  const n = Math.max(0, Math.floor((avail - rib) / (groove + rib)));
  if (!n) return [];
  const used = n * groove + (n + 1) * rib;
  const start = margin + (avail - used) / 2 + rib;
  return Array.from({ length: n }, (_, i) => { const a = start + i * (groove + rib); return [a, a + groove]; });
}

/**
 * מלבני החריץ על הפאה: [[u0, u1, v0, v1], …].
 * מסגרת: לכל פנל, טבעות מלבניות — כל טבעת ארבעה מלבנים. המרחק מהקצה קטן
 * בחזיתות קטנות (מגירה) כדי שהמסגרת תיכנס; טבעת שלא נכנסת נשמטת.
 */
export function millRects(U, V, spec) {
  if (spec.kind === 'flutes') return fluteGrooves(U, spec).map(([a, b]) => [a, b, 0, V]);
  const g = spec.groove;
  const inset = Math.min(spec.inset, Math.min(U, V) * 0.14);
  const panels = spec.panels === 2 && V > 900 ? [[0, V * (1 - spec.split)], [V * (1 - spec.split), V]] : [[0, V]];
  const out = [];
  panels.forEach(([pa, pb], pi) => {
    // בין שני פנלים: המרחק מהקו המפריד כמו מהקצה, כך שהפס ביניהם כפול מהשוליים — כמו בתמונה
    for (let r = 0; r < spec.rings; r++) {
      const d = inset + r * (g + spec.ringGap);
      const u0 = d, u1 = U - d, v0 = pa + (pi === 0 ? d : d * 0.5 + inset * 0.5), v1 = pb - (pi === panels.length - 1 ? d : d * 0.5 + inset * 0.5);
      if (u1 - u0 < 4 * g + 40 || v1 - v0 < 4 * g + 40) break;
      out.push([u0, u1, v0, v0 + g], [u0, u1, v1 - g, v1], [u0, u0 + g, v0 + g, v1 - g], [u1 - g, u1, v0 + g, v1 - g]);
    }
  });
  return out;
}

/**
 * חלוקת הפאה לתאים לפי קצוות המלבנים: מה שמחוץ לכל חריץ — חומר (הצלעות /
 * המסגרות הבולטות). מחזיר את התאים המלאים, מאוחדים לאורך כל שורה.
 */
export function millSolids(U, V, rects) {
  const us = [...new Set([0, U, ...rects.flatMap((r) => [r[0], r[1]])])].filter((x) => x >= 0 && x <= U).sort((a, b) => a - b);
  const vs = [...new Set([0, V, ...rects.flatMap((r) => [r[2], r[3]])])].filter((x) => x >= 0 && x <= V).sort((a, b) => a - b);
  const inGroove = (u, v) => rects.some((r) => u > r[0] && u < r[1] && v > r[2] && v < r[3]);
  const out = [];
  for (let j = 0; j < vs.length - 1; j++) {
    const v0 = vs[j], v1 = vs[j + 1], vm = (v0 + v1) / 2;
    let run = null;
    for (let i = 0; i < us.length - 1; i++) {
      const u0 = us[i], u1 = us[i + 1];
      if (!inGroove((u0 + u1) / 2, vm)) { if (run) run[1] = u1; else run = [u0, u1]; }
      else if (run) { out.push([run[0], run[1], v0, v1]); run = null; }
    }
    if (run) out.push([run[0], run[1], v0, v1]);
  }
  return out;
}

/** נפח שהכרסום מסיר (מ"מ³) — למשקל. */
export function millRemoved(part) {
  if (!part.mill) return 0;
  const { U, V } = millFace(part);
  const solid = millSolids(U, V, millRects(U, V, part.mill)).reduce((s, r) => s + (r[1] - r[0]) * (r[3] - r[2]), 0);
  return (U * V - solid) * part.mill.depth;
}

/** הוראת הכרסום כטקסט (להערה ברשימת החיתוך ולפלט). */
export function millText(part) {
  const m = part.mill;
  const { U, V } = millFace(part);
  if (m.kind === 'flutes') {
    const n = fluteGrooves(U, m).length;
    return `חירוץ CNC בפאה החיצונית: ${n} חריצים ${m.groove}×${m.depth} לכל הגובה (${Math.round(V)}), צלע ${m.rib}, שוליים ${m.margin}`;
  }
  const rings = millRects(U, V, m).length / 4;
  const inset = Math.round(Math.min(m.inset, Math.min(U, V) * 0.14));
  return `חירוץ CNC בפאה החיצונית: ${rings} מסגרות${m.panels === 2 && V > 900 ? ' בשני פנלים' : ''}, חריץ ${m.groove}×${m.depth}, ${inset} מהקצה${m.rings > 1 ? `, ${m.ringGap} בין הקווים` : ''}`;
}

/** אזהרות: עובי שלא מחזיק את העומק, וחירוץ בלוח מצופה (חושף את הליבה). */
export function millWarnings(parts) {
  const out = new Set();
  for (const p of parts) {
    if (!p.mill) continue;
    const m = material(p.material);
    const t = Math.min(p.box.w, p.box.h, p.box.d);
    if (t - p.mill.depth < 10) out.add(`${p.name}: עובי ${t} מ"מ דק לחירוץ בעומק ${p.mill.depth} — נשארים פחות מ-10 מ"מ`);
    if (m.kind === 'board' && m.finish !== 'paint') out.add(`${p.name}: חירוץ CNC בלוח ${m.name} חושף את הליבה — לחרץ ב-MDF לצבע`);
  }
  return [...out];
}

/**
 * קווי המתאר של פאה מחורצת, כקטעים [[u, v, l], [u, v, l]] — l=0 על פני
 * הפאה, l=1 בתחתית החריץ. רק במקום שיש חומר: שפת הפאה לא נמתחת מעל פתח של
 * חריץ, וקצה פתוח של חריץ מצויר כ-U (בלי קו שסוגר אותו). במסגרת — הקו
 * החיצוני והפנימי של כל טבעת, בלי תפרים בין ארבעת חלקיה.
 */
export function millEdges(U, V, spec) {
  const segs = [];
  const seg = (u0, v0, l0, u1, v1, l1) => segs.push([[u0, v0, l0], [u1, v1, l1]]);
  const rect = (u0, u1, v0, v1, l) => { seg(u0, v0, l, u1, v0, l); seg(u1, v0, l, u1, v1, l); seg(u1, v1, l, u0, v1, l); seg(u0, v1, l, u0, v0, l); };
  if (spec.kind === 'flutes') {
    const gs = fluteGrooves(U, spec);
    // שפת הפאה: הצדדים שלמים; למעלה ולמטה — רק על הצלעות והשוליים
    seg(0, 0, 0, 0, V, 0); seg(U, 0, 0, U, V, 0);
    const solid = [];
    let at = 0;
    for (const [a, b] of gs) { if (a > at) solid.push([at, a]); at = b; }
    if (at < U) solid.push([at, U]);
    for (const v of [0, V]) for (const [a, b] of solid) seg(a, v, 0, b, v, 0);
    for (const [a, b] of gs) {
      for (const u of [a, b]) { seg(u, 0, 0, u, V, 0); seg(u, 0, 1, u, V, 1); }
      for (const v of [0, V]) { seg(a, v, 0, a, v, 1); seg(a, v, 1, b, v, 1); seg(b, v, 1, b, v, 0); }
    }
    return segs;
  }
  rect(0, U, 0, V, 0);
  const rs = millRects(U, V, spec);
  for (let k = 0; k + 3 < rs.length; k += 4) {
    // ארבעה מלבנים לטבעת: [עליון…] → הקו החיצוני מהמלבן התחתון והעליון, הפנימי פנימה בעובי החריץ
    const [bot, top] = [rs[k], rs[k + 1]];
    const u0 = bot[0], u1 = bot[1], v0 = bot[2], v1 = top[3], g = spec.groove;
    for (const l of [0, 1]) { rect(u0, u1, v0, v1, l); rect(u0 + g, u1 - g, v0 + g, v1 - g, l); }
  }
  return segs;
}
