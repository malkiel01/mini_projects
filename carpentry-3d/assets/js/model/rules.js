// מעבדת המוצרים: שכבת התאמות של המנהל מעל התבניות שבקוד.
//
// התבנית (bookcase.js וכו') נשארת המקור: היא בונה את החלקים. מה שהמנהל משנה
// במסך "🧪 מעבדת מוצרים" נשמר בשרת (template_rules) כאובייקט אחד:
//   { <מפתח תבנית>: { laborHours, params: { <מפתח>: { label, hint, default, min, max, group, hidden } } },
//     _limits: { shelfSpan18, doorWidth, … } }
// ומופעל כאן על התבניות בזיכרון — בכל טעינה, ומחדש אחרי כל שמירה. applyRules
// מחזיר קודם הכול למקור (תמונת מצב ראשונה), ורק אז מפעיל את ההתאמות, כך
// שמחיקת התאמה = חזרה לקוד. פרויקטים קיימים לא משתנים: הערכים שלהם שמורים;
// ברירת מחדל חדשה חלה על פרויקטים חדשים. פרמטר "מוסתר" יוצא מהטופס ותמיד
// מקבל את ברירת המחדל.

import { TEMPLATES, allParams } from './index.js';
import { LIMITS } from './templates/common.js';

/** השדות של פרמטר שהמנהל יכול לשנות. */
export const EDITABLE = ['label', 'hint', 'default', 'min', 'max', 'group', 'hidden'];

/** הגבולות הכלליים — עם הסבר: מה כל אחד בודק ואיפה הוא משפיע. */
export const LIMIT_INFO = {
  shelfSpan18: { label: 'מוטת מדף מקסימלית — לוח 18', unit: 'מ"מ', hint: 'מעבר לזה — אזהרת שקיעה למדף בעובי 18' },
  shelfSpan25: { label: 'מוטת מדף מקסימלית — לוח 25', unit: 'מ"מ', hint: 'מעבר לזה — אזהרת שקיעה גם למדף עבה' },
  doorWidth: { label: 'רוחב דלת מקסימלי', unit: 'מ"מ', hint: 'עמודה רחבה מזה מקבלת שתי כנפיים (ספרייה, ארון, מטבח)' },
  heightUnanchored: { label: 'גובה שמחייב עיגון לקיר', unit: 'מ"מ', hint: 'מעבר לזה — אזהרה להמליץ על עיגון' },
  glassMinDepth: { label: 'עומק מינימלי לוויטרינה', unit: 'מ"מ', hint: 'פחות מזה — אזהרה בדלתות זכוכית' },
  drawerMaxWidth: { label: 'רוחב מגירה מקסימלי', unit: 'מ"מ', hint: 'מגירה רחבה מזה — אזהרה' },
};

// ---- תמונת המקור ----
let original = null;
function snapshot() {
  if (original) return;
  original = { limits: { ...LIMITS }, templates: {} };
  for (const [key, t] of Object.entries(TEMPLATES)) {
    // עותק לכל פרמטר: תבניות שונות עלולות לחלוק אובייקט פרמטר (ידית, חומר…),
    // והתאמה לתבנית אחת לא אמורה לדלוף לאחרת
    t.params = t.params.map((p) => ({ ...p }));
    if (t.joinery) t.joinery = t.joinery.map((p) => ({ ...p }));
    original.templates[key] = { laborHours: t.laborHours, params: Object.fromEntries(allParams(t).map((p) => [p.key, pick(p)])) };
  }
}
const pick = (p) => Object.fromEntries(EDITABLE.map((k) => [k, p[k]]));

/** הערך המקורי (מהקוד) — למסך המעבדה: מה שונה ואיך לאפס. */
export function originalOf(key) { snapshot(); return original.templates[key]; }
export function originalLimits() { snapshot(); return { ...original.limits }; }

let current = {};
/** ההתאמות הפעילות (כפי שנשמרו בשרת). */
export const activeRules = () => current;

/**
 * מחזיר את כל התבניות למקור, ומפעיל את ההתאמות. ערך לא חוקי — נבלע (הקוד
 * מנצח). מחזיר את ההתאמות שהופעלו בפועל.
 */
export function applyRules(rules) {
  snapshot();
  current = rules && typeof rules === 'object' && !Array.isArray(rules) ? rules : {};
  // גבולות
  for (const k of Object.keys(LIMITS)) LIMITS[k] = original.limits[k];
  const lim = obj(current._limits);
  for (const [k, v] of Object.entries(lim)) if (k in LIMITS && Number.isFinite(Number(v)) && Number(v) > 0) LIMITS[k] = Number(v);
  // תבניות
  for (const [key, t] of Object.entries(TEMPLATES)) {
    const o = original.templates[key];
    t.laborHours = o.laborHours;
    for (const p of allParams(t)) Object.assign(p, o.params[p.key]);
    const r = obj(current[key]);
    if (Number.isFinite(Number(r.laborHours)) && Number(r.laborHours) >= 0 && r.laborHours !== '' && r.laborHours !== null) t.laborHours = Number(r.laborHours);
    const ps = obj(r.params);
    for (const p of allParams(t)) {
      const e = obj(ps[p.key]);
      if (typeof e.label === 'string' && e.label.trim()) p.label = e.label.trim();
      if (typeof e.hint === 'string') p.hint = e.hint.trim() || undefined;
      if (typeof e.group === 'string' && e.group.trim()) p.group = e.group.trim();
      if (typeof e.hidden === 'boolean') p.hidden = e.hidden;
      if (['mm', 'int', 'deg'].includes(p.type)) {
        if (Number.isFinite(Number(e.min)) && e.min !== null && e.min !== '') p.min = Number(e.min);
        if (Number.isFinite(Number(e.max)) && e.max !== null && e.max !== '') p.max = Number(e.max);
        if (p.min > p.max) { p.min = o.params[p.key].min; p.max = o.params[p.key].max; }
        if (Number.isFinite(Number(e.default)) && e.default !== null && e.default !== '') p.default = Math.min(p.max, Math.max(p.min, Number(e.default)));
      } else if (e.default !== undefined && e.default !== null && e.default !== '' && p.type !== 'json') {
        p.default = e.default;
      }
    }
  }
  return current;
}
const obj = (x) => (x && typeof x === 'object' && !Array.isArray(x) ? x : {});

/** תיאור קריא של מתי פרמטר מוצג (showIf) — למסך המעבדה. */
export function describeShowIf(p, t) {
  if (!p.showIf) return 'תמיד';
  const byKey = Object.fromEntries(allParams(t).map((q) => [q.key, q]));
  const name = (k) => byKey[k]?.label || k;
  const opt = (k, v) => {
    const q = byKey[k];
    const list = q && Array.isArray(q.options) ? q.options : [];
    return list.find((o) => String(o.id) === String(v))?.name || String(v);
  };
  return Object.entries(p.showIf).map(([k, allowed]) => {
    if (allowed === 'gt0') return `${name(k)} גדול מ-0`;
    if (allowed && allowed.not) return `${name(k)} אינו ${allowed.not.map((v) => opt(k, v)).join(' / ')}`;
    return `${name(k)} = ${allowed.map((v) => opt(k, v)).join(' / ')}`;
  }).join(' וגם ');
}
