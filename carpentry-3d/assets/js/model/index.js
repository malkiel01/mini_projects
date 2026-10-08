// רישום התבניות, וכל מה שנגזר מרשימת החלקים.
//
// זו הנקודה היחידה שהמסכים מדברים איתה: נותנים מפתח תבנית וערכים, מקבלים
// חלקים, פרזול, אזהרות ורשימת חיתוך. אין כאן DOM — הקובץ רץ גם ב-node,
// וזה מה שמאפשר ל-tools/model-check.js לבדוק את המודל בלי דפדפן.

import bookcase from './templates/bookcase.js';
import wardrobe from './templates/wardrobe.js';
import dresser from './templates/dresser.js';
import kitchen from './templates/kitchen.js';
import table from './templates/table.js';
import bed from './templates/bed.js';
import cladding from './templates/cladding.js';
import { cutSize } from './blocks.js';
import { material, materialsOfKind, materialsOfRole } from './materials.js';

export const TEMPLATES = { bookcase, wardrobe, dresser, kitchen, table, bed, cladding };

import { physicsWarnings } from './physics.js';

export function template(key) {
  const t = TEMPLATES[key];
  if (!t) throw new Error(`תבנית לא מוכרת: ${key}`);
  return t;
}

/** כל הפרמטרים של תבנית — הרגילים והחיבורים — ברשימה אחת, לטופס ולברירות המחדל. */
export function allParams(t) {
  return [...t.params, ...(t.joinery || [])];
}

/** ערכי ברירת המחדל של תבנית. */
export function defaults(t) {
  const v = {};
  for (const p of allParams(t)) v[p.key] = p.default;
  return v;
}

/** אפשרויות הבחירה של פרמטר: לרשימה קבועה (enum) או מהספרייה החיה (material). */
export function optionsFor(p) {
  if (p.type === 'material') {
    const list = (p.role ? materialsOfRole(p.role) : materialsOfKind(p.kind, { back: p.back, solid: p.solid, top: p.top })).map((m) => ({ id: m.id, name: m.name }));
    if (p.allowNone) list.unshift({ id: 'none', name: p.noneLabel || 'ללא' });
    return p.allowSame ? [{ id: 'same', name: 'כמו הגוף' }, ...list] : list;
  }
  return p.options || [];
}

/** מצמיד ערך לטווח הפרמטר. ערך לא חוקי חוזר לברירת המחדל. */
export function clamp(p, value) {
  if (p.type === 'mm' || p.type === 'int') {
    let n = Number(value);
    if (!Number.isFinite(n)) return p.default;
    if (p.type === 'int') n = Math.round(n);
    return Math.min(p.max, Math.max(p.min, n));
  }
  if (p.type === 'enum') return p.options.some((o) => o.id === value) ? value : p.default;
  if (p.type === 'json') return value && typeof value === 'object' ? value : (p.default ?? null);
  if (p.type === 'material') {
    if (p.allowSame && (value === 'same' || value === undefined)) return 'same';
    if (p.allowNone && (value === 'none' || value === null)) return 'none';
    // חומר שהושבת אחרי שנבחר ממשיך לעבוד — פרויקט ישן לא נשבר. רק מזהה
    // שאינו קיים כלל חוזר לברירת המחדל (או לראשון הפעיל, אם גם היא נמחקה).
    if (material(value).kind !== 'unknown') return value;
    if (material(p.default).kind !== 'unknown') return p.default;
    const first = optionsFor(p)[0];
    return first ? first.id : p.default;
  }
  return value;
}

/** האם פרמטר מוצג לפי הערכים הנוכחיים (showIf). */
export function visible(p, values) {
  if (!p.showIf) return true;
  // 'gt0' — מוצג כשהערך המספרי גדול מאפס (למשל "דלתות החלק התחתון" רק כשיש פיצול)
  return Object.entries(p.showIf).every(([k, allowed]) => allowed === 'gt0' ? Number(values[k]) > 0 : allowed.includes(values[k]));
}

/** בונה מופע: תבנית + ערכים → חלקים, פרזול, אזהרות. הערכים מוצמדים לטווחים קודם. */
export function build(key, values) {
  const t = template(key);
  const v = defaults(t);
  if (t.migrate) values = t.migrate({ ...values });   // ערכים ישנים שנשמרו בפרויקטים → המפתחות הנוכחיים
  for (const p of allParams(t)) if (values[p.key] !== undefined) v[p.key] = clamp(p, values[p.key]);
  const out = t.build(v);
  out.warnings = [...(out.warnings || []), ...physicsWarnings(out.hardware || [])];
  return { ...out, values: v, template: t };
}

/**
 * רשימת חיתוך: חלקים זהים (אותו qtyKey, מידות וחומר) מתקבצים לשורה אחת.
 * מידת הקנט: במצב 'subtract' החלק נחתך קטן בעובי הקנט בכל צד שמקבל קנט,
 * כך שהמידה הסופית היא זו שבמודל. במצב 'add' החלק נחתך במידת המודל והקנט
 * נוסף עליה.
 */
export function cutList({ parts, values }) {
  const edgeT = material(values.edgeMaterial || '').t || 0;
  const subtract = values.edgeMode !== 'add';
  const rows = new Map();
  for (const p of parts) {
    const m = material(p.material);
    if (m.kind === 'glass') continue;           // זכוכית מוזמנת, לא נחתכת — ברשימה נפרדת
    const c = cutSize(p);
    const e = edgeSides(p);
    let l = c.l, w = c.w;
    if (subtract && edgeT) {
      l -= edgeT * e.alongL;
      w -= edgeT * e.alongW;
    }
    const key = [p.qtyKey, l, w, c.t, p.material, e.label].join('|');
    const row = rows.get(key) || { name: p.name.replace(/\s[\d.]+$/, ''), qty: 0, l, w, t: c.t, material: m.name, edges: e.label, grain: p.grain, ids: [], note: p.note };
    row.qty += 1;
    row.ids.push(p.id);
    if (row.note !== p.note) row.note = '';      // הערה שאינה משותפת לכל החלקים בשורה — נשמטת
    rows.set(key, row);
  }
  const glass = [];
  for (const p of parts) {
    const m = material(p.material);
    if (m.kind !== 'glass') continue;
    const c = cutSize(p);
    const key = [c.l, c.w, p.material].join('|');
    const g = glass.find((r) => r.key === key) || (glass.push({ key, name: m.name, qty: 0, l: c.l, w: c.w, t: m.t }), glass[glass.length - 1]);
    g.qty += 1;
  }
  return { boards: [...rows.values()], glass };
}

/**
 * אילו צדדים של החלק מקבלים קנט, מתורגמים לצירי החיתוך: כמה קנטים לאורך
 * (שניים לכל היותר — שני הקצוות הרחוקים זה מזה בכיוון האורך), וכמה לרוחב.
 * `label` הוא הסימון לרשימה: למשל "חזית" או "חזית + עליון".
 */
function edgeSides(p) {
  const names = { front: 'חזית', back: 'אחור', top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' };
  // הצדדים שמקבלים קנט, לפי ציר: צד בציר X מקצר את מידת ה-X, וכן הלאה.
  const axisOf = { front: 'z', back: 'z', top: 'y', bottom: 'y', left: 'x', right: 'x' };
  const on = Object.entries(p.edges || {}).filter(([, yes]) => yes).map(([s]) => s);
  const other = ['x', 'y', 'z'].filter((a) => a !== p.axis);
  const dims = { x: p.box.w, y: p.box.h, z: p.box.d };
  const grain = other.includes(p.grain) ? p.grain : (dims[other[0]] >= dims[other[1]] ? other[0] : other[1]);
  let alongL = 0, alongW = 0;
  for (const s of on) {
    if (axisOf[s] === grain) alongL += 1;
    else if (axisOf[s] !== p.axis) alongW += 1;
  }
  return { alongL, alongW, label: on.map((s) => names[s]).join(' + ') || '—' };
}

/** סיכום הפרזול: לפי סוג ודגם, עם כמויות. */
export function hardwareList({ hardware }) {
  const rows = new Map();
  for (const h of hardware) {
    if (h.kind === 'info' || !h.material) continue;   // רשומות מידע (משקל, עומס) אינן פריטי פרזול
    const m = material(h.material);
    const row = rows.get(h.material) || { name: m.name, kind: h.kind, qty: 0 };
    row.qty += h.qty || 1;
    rows.set(h.material, row);
  }
  return [...rows.values()];
}
