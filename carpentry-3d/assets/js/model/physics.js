// פיזיקה ופרזול אמיתי: משקלים, צירים לפי גובה ומשקל, מערכות הזזה, קידוחים.
//
// המערכת מתכננת עבודה לנגר — לא תמונה. לכן: לכל חלק משקל (נפח × צפיפות
// החומר), מספר הצירים נקבע לפי גובה הדלת *ומשקלה* (הגבוה מביניהם), דלת
// הזזה יושבת על מסילה אמיתית (גובה הכנף נגזר מפרופיל המסילה, עם גררות
// למעלה או גלגלים למטה), ולכל פרזול יש רשימת קידוחים בקואורדינטות של
// החלק — מה שמכונת CNC צריכה.

import { material } from './materials.js';
import { millRemoved } from './milling.js';

// ---- משקל ----
/** צפיפות בק"ג/מ"ק: מהספרייה אם הוגדרה, אחרת לפי סוג החומר. */
export function densityOf(m) {
  if (m && Number.isFinite(m.density) && m.density > 0) return m.density;
  if (!m) return 650;
  if (m.kind === 'glass') return 2500;
  if (m.kind === 'board') {
    if (m.solid) return 700;            // עץ מלא (אלון ~720, אשור ~700)
    if (m.finish === 'paint') return 740; // MDF
    if (m.finish === 'wood') return 560;  // פורניר על סנדוויץ'
    return 680;                           // סיבית מצופה מלמין
  }
  return 0;
}
/** משקל חלק בק"ג (2 ספרות). */
export function partWeight(p) {
  const m = material(p.material);
  const kg = (p.box.w * p.box.h * p.box.d - millRemoved(p)) / 1e9 * densityOf(m);   // חירוץ CNC מסיר חומר
  return Math.round(kg * 100) / 100;
}
export function totalWeight(parts) {
  return Math.round(parts.reduce((s, p) => s + partWeight(p), 0) * 10) / 10;
}
/** משקל חלק מהמידות בלבד (לפני שהחלק נוצר) — לדלתות. */
export function boardWeight(w, h, t, materialId) {
  return Math.round((w * h * t) / 1e9 * densityOf(material(materialId)) * 100) / 100;
}

// ---- צירים ----
// מספר הצירים: לפי גובה הדלת (כמקובל אצל היצרנים) ולפי משקלה — הגבוה מביניהם.
export const HINGE_BY_HEIGHT = [[900, 2], [1600, 3], [2100, 4], [2400, 5], [Infinity, 6]];
export const HINGE_BY_WEIGHT = [[6, 2], [13, 3], [18, 4], [22, 5], [Infinity, 6]];
const pick = (table, v) => table.find(([lim]) => v <= lim)[1];
export function hingeCount(h, kg) {
  return Math.max(pick(HINGE_BY_HEIGHT, h), pick(HINGE_BY_WEIGHT, kg));
}
/** גבהי הצירים (מרכז הכוס) מתחתית הדלת: הראשון והאחרון 100 מהקצוות, השאר בפיזור שווה. */
export function hingeYs(h, n, edge = 100) {
  if (n <= 1) return [h / 2];
  const e = Math.min(edge, h / 4);
  return Array.from({ length: n }, (_, i) => Math.round(e + (h - 2 * e) * (i / (n - 1))));
}
/**
 * קידוחי ציר נסתר. בדלת (הפאה האחורית): כוס Ø35 עומק 13, מרכזה K+17.5 מקצה
 * הציר (K=5), ושני חורי בורג 45/9.5 (Ø2.5 עומק 10). בדופן (הפאה הפנימית):
 * פלטת ההרכבה 37 מהקצה הקדמי, שני חורים Ø5 עומק 11 במרחק 32 (סיסטם 32).
 * `ys` — גבהי הצירים מתחתית הדלת; `doorBottomOffset` — תחתית הדלת ביחס לתחתית הדופן.
 */
export function hingeDrilling({ doorId, mountId, ys, cup = 35, K = 5, doorBottomOffset = 0 }) {
  const cx = K + cup / 2;
  const out = [];
  for (const y of ys) {
    out.push({ part: doorId, face: 'back', ref: 'מקצה הציר / מהתחתית', x: cx, y, dia: cup, depth: 13, purpose: 'כוס ציר' });
    out.push({ part: doorId, face: 'back', ref: 'מקצה הציר / מהתחתית', x: cx + 9.5, y: y - 22.5, dia: 2.5, depth: 10, purpose: 'בורג ציר (45/9.5)' });
    out.push({ part: doorId, face: 'back', ref: 'מקצה הציר / מהתחתית', x: cx + 9.5, y: y + 22.5, dia: 2.5, depth: 10, purpose: 'בורג ציר (45/9.5)' });
    if (mountId) {
      const my = y + doorBottomOffset;
      out.push({ part: mountId, face: 'inner', ref: 'מהקצה הקדמי / מהתחתית', x: 37, y: my - 16, dia: 5, depth: 11, purpose: 'פלטת ציר (סיסטם 32)' });
      out.push({ part: mountId, face: 'inner', ref: 'מהקצה הקדמי / מהתחתית', x: 37, y: my + 16, dia: 5, depth: 11, purpose: 'פלטת ציר (סיסטם 32)' });
    }
  }
  return out;
}

// ---- מסילות הזזה ----
// הכנף אינה מרחפת: גובהה נגזר מהפרופילים. בשני הסוגים — שני נתיבים, הכנף
// החיצונית לפני הפנימית. מספרים אופייניים ליצרנים (הקל 40 ק"ג, הכבד 60–80).
export const SLIDING_SYSTEMS = {
  'top-hung': {
    name: 'תלויה — מסילה עליונה כפולה',
    trackH: 40, trackD: 68,        // פרופיל כפול מתחת לגג
    topClear: 2,                   // בין הכנף לפרופיל
    guideH: 12, guideD: 24,        // מוליך תחתון (פס ברצפה; הכנף מעליו עם חריץ)
    bottomClear: 6,                // מרווח מעל הרצפה
    laneGap: 6,                    // בין שתי הכנפיים
    carriersPerLeaf: 2, carrierFromEdge: 60, carrier: { w: 60, h: 24, d: 20 },   // גררה: גוף עם שני גלגלים, בקצה העליון
    grooveW: 6, grooveDepth: 12,   // חריץ בתחתית הכנף למוליך
    maxKgPerLeaf: 40,
  },
  'bottom-rolling': {
    name: 'מסילה תחתונה — גלגלים למטה',
    trackH: 14, trackD: 60,        // פס כפול על הרצפה
    rollerLift: 5,                 // הכנף יושבת על הגלגלים, מעל הפס
    guideH: 22, guideD: 60, engage: 10,   // מוליך עליון: הכנף נכנסת 10 לתוכו
    topClear: 2,
    laneGap: 6,
    carriersPerLeaf: 2, carrierFromEdge: 60, carrier: { w: 60, h: 28, d: 20 },   // גלגל תחתון
    maxKgPerLeaf: 60,
  },
};
/**
 * חישוב כנף: גובה הכנף ותחתיתה ביחס לפתח (y0..y1), לפי המערכת.
 * תלויה: תחתית = y0 + מוליך + מרווח; גובה = עד מתחת לפרופיל פחות מרווח.
 * תחתונה: תחתית = y0 + פס + הרמת הגלגל; גובה = עד לתוך המוליך העליון (נכנס engage) פחות מרווח.
 */
export function slidingLeaf(systemKey, y0, y1) {
  const s = SLIDING_SYSTEMS[systemKey] || SLIDING_SYSTEMS['top-hung'];
  const open = y1 - y0;
  if (systemKey === 'bottom-rolling') {
    const bottom = y0 + s.trackH + s.rollerLift;
    const top = y1 - s.guideH + s.engage - s.topClear;
    return { system: s, key: 'bottom-rolling', bottom, h: Math.round(top - bottom), openH: open };
  }
  const bottom = y0 + s.guideH + s.bottomClear;
  const top = y1 - s.trackH - s.topClear;
  return { system: s, key: 'top-hung', bottom, h: Math.round(top - bottom), openH: open };
}

// ---- מסילות מגירה ----
/** עומס מותר למסילה (ק"ג) — מהספרייה (`load`), אחרת לפי השם. */
export function slideLoad(materialId) {
  const m = material(materialId);
  if (Number.isFinite(m.load)) return m.load;
  return /tandem/.test(materialId) ? 40 : 25;
}
export const DRAWER_CONTENT_KG = 15;   // הנחת עומס שימושי למגירה

/** קידוחי מסילות מגירה בדופן הגוף: שורת סיסטם 32 — 37 מהקצה הקדמי ואז כל 224. */
export function slideDrilling({ mountIds, depth, yFromBottom }) {
  const out = [];
  const xs = [37];
  for (let x = 37 + 224; x <= depth - 40; x += 224) xs.push(x);
  for (const id of mountIds) for (const x of xs) out.push({ part: id, face: 'inner', ref: 'מהקצה הקדמי / מהתחתית', x, y: Math.round(yFromBottom), dia: 5, depth: 11, purpose: 'מסילת מגירה' });
  return out;
}

/**
 * אזהרות פיזיקליות מרשומות ה-info שהבלוקים מוסיפים לפרזול:
 * דלת כבדה מ-22 ק"ג (מעבר לטווח צירים רגילים), כנף הזזה מעל עומס המערכת,
 * מגירה שמשקלה + תכולה עובר את עומס המסילה.
 */
export function physicsWarnings(hardware) {
  const out = [];
  for (const h of hardware) {
    if (h.kind !== 'info') continue;
    if (Number.isFinite(h.maxKg)) { if (h.kg > h.maxKg) out.push(`כנף הזזה ${h.door}: ${h.kg} ק"ג — מעל ${h.maxKg} ק"ג המותרים למערכת; לבחור מערכת כבדה או להקטין את הכנף`); }
    else if (Number.isFinite(h.load)) { if (h.kg + DRAWER_CONTENT_KG > h.load) out.push(`מגירה ${h.door}: ${h.kg} ק"ג + ${DRAWER_CONTENT_KG} תכולה — מעל עומס המסילה (${h.load} ק"ג); לבחור מסילה חזקה יותר`); }
    else if (Number.isFinite(h.hinges) && h.kg > 22) out.push(`דלת ${h.door}: ${h.kg} ק"ג — כבדה לצירים רגילים (עד 22 ק"ג); להקטין את הדלת או לפצל לשתי כנפיים`);
  }
  return out;
}

/** רשימת הקידוחים של מודל, מקובצת לפי חלק. */
export function drillingList(model) {
  const byPart = new Map();
  for (const h of model.hardware) for (const d of h.drill || []) {
    if (!byPart.has(d.part)) byPart.set(d.part, []);
    byPart.get(d.part).push(d);
  }
  const names = new Map(model.parts.map((p) => [p.id, p.name]));
  return [...byPart.entries()].map(([id, holes]) => ({ id, name: names.get(id) || id, holes: holes.sort((a, b) => a.y - b.y || a.x - b.x) }));
}
