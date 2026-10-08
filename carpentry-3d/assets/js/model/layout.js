// פריסת עמודות ותאים: מה "נעוץ" (המשתמש קבע מספר) ומה "אוטומטי" (מתחלק בשווה).
//
// הרעיון: ברירת המחדל היא חלוקה שווה — של רוחב הפנים בין העמודות, ושל הגובה
// הפנוי בעמודה בין התאים. כשהמשתמש קובע מספר לעמודה או לתא, המספר נשמר
// ("נעוץ") ושום שינוי אוטומטי לא נוגע בו עד שמאפסים אותו. כל מה שלא נעוץ
// מתחלק בשווה במה שנשאר. כך שינוי רוחב הספרייה, הוספת עמודה או שינוי של תא
// אחר משנים רק את מה שלא נקבע ביד.
//
// הפורמט שנשמר בפרויקט (`columnsLayout`):
//   { widths: [מ"מ | null, …],                      // לכל עמודה: רוחב נעוץ או null = אוטומטי
//     cols: { i: { shelves?: n, gaps?: [מ"מ | null, …], height?: מ"מ } } }   // לכל עמודה: מספר מדפים, גבהי התאים (מלמטה למעלה), וגובה משלה
// `gaps` באורך shelves+1 (או כמספר המגירות בארון מגירות). פורמט ישן
// ({ i: { shelves, gaps:[מספרים] } }) מוסב בטעינה.
//
// מוצר עם כמה קבוצות של חלוקה (מטבח: תחתונים ועליונים בכל קיר; שידה: עמודות
// ושורות) שומר `{ sections: { <key>: { widths, cols } } }`; ספרייה וארון
// שנשמרו בלי `sections` נקראים כקבוצה 'main'. התבנית מתארת את הקבוצות
// לעורך ב-`columnSpace(v).sections`, והעורך כותב באותו מבנה.

export const MIN_SHARE = 50;

/**
 * מחלק `total` בין פריטים: נעוץ מקבל את ערכו, אוטומטי מקבל חלק שווה מהשאר.
 * אם הנעוצים חורגים ממה שנשאר לאוטומטיים (לפחות MIN לכל אחד) — הם מוקטנים
 * ביחס; אם כולם נעוצים וסכומם אינו total — כולם מתוקנים ביחס. מחזיר מספרים חיוביים שסכומם total.
 */
export function resolveShares(total, pins, min = MIN_SHARE) {
  const n = pins.length;
  if (!n) return [];
  if (total <= 0) return pins.map(() => 0);
  const fixed = pins.map((p) => (Number.isFinite(p) && p > 0 ? Math.max(Math.min(min, total / n), p) : null));
  const autoCount = fixed.filter((f) => f === null).length;
  let fixedSum = fixed.reduce((a, f) => a + (f ?? 0), 0);
  const room = total - autoCount * Math.min(min, total / n);
  if (autoCount ? fixedSum > room : Math.abs(fixedSum - total) > 0.001) {
    const k = (autoCount ? Math.max(0, room) : total) / (fixedSum || 1);
    for (let i = 0; i < n; i++) if (fixed[i] !== null) fixed[i] *= k;
    fixedSum = fixed.reduce((a, f) => a + (f ?? 0), 0);
  }
  const share = autoCount ? (total - fixedSum) / autoCount : 0;
  return fixed.map((f) => (f === null ? share : f));
}

/**
 * עריכה של פריט אחד: הפריט `idx` נקבע ל-`want` ונעשה נעוץ. מאיפה נלקח ההפרש —
 * לפי `mode`: 'even' — מכל השאר (הנעוצים מתוקנים בשווה; האוטומטיים ממילא
 * סופגים); 'next' — מהשכן הבא (idx+1) שנעשה נעוץ בערכו החדש; 'prev' — מהשכן
 * הקודם. בלי שכן מתאים (קצה) — נופל ל-'even'. מחזיר מערך נעיצות חדש.
 */
export function editShare(total, pins, idx, want, mode, min = MIN_SHARE) {
  const n = pins.length;
  const cur = resolveShares(total, pins, min);
  const maxWant = total - min * (n - 1);
  const value = Math.max(min, Math.min(maxWant, want));
  const delta = value - cur[idx];
  const next = pins.slice();
  next[idx] = Math.round(value);
  const neighbor = mode === 'next' ? idx + 1 : mode === 'prev' ? idx - 1 : -1;
  if (neighbor >= 0 && neighbor < n) {
    next[neighbor] = Math.round(Math.max(min, cur[neighbor] - delta));
    return next;
  }
  // בשווה: האוטומטיים סופגים לבד; אם אין אוטומטיים — הנעוצים האחרים מתחלקים בהפרש
  const others = pins.map((_, k) => k).filter((k) => k !== idx);
  const autos = others.filter((k) => !(Number.isFinite(pins[k]) && pins[k] > 0));
  if (autos.length === 0) for (const k of others) next[k] = Math.round(Math.max(min, cur[k] - delta / others.length));
  return next;
}

/** מביא כל ערך שנשמר (ישן או חדש, אובייקט או מערך אחרי JSON) לפורמט הנוכחי, באורך `columns`. */
export function normalizeLayout(raw, columns) {
  const out = { widths: Array.from({ length: columns }, () => null), cols: {} };
  if (!raw || typeof raw !== 'object') return out;
  const isNew = !Array.isArray(raw) && ('cols' in raw || 'widths' in raw);
  const cols = isNew ? raw.cols || {} : raw;
  if (isNew && Array.isArray(raw.widths)) for (let i = 0; i < columns; i++) out.widths[i] = pin(raw.widths[i]);
  for (let i = 0; i < columns; i++) {
    const c = cols[i];
    if (!c || typeof c !== 'object') continue;
    const col = {};
    if (Number.isInteger(c.shelves)) col.shelves = Math.max(0, Math.min(15, c.shelves));
    if (Array.isArray(c.gaps)) col.gaps = c.gaps.map(pin);
    if (typeof c.kind === 'string') col.kind = c.kind;
    if (pin(c.height)) col.height = Math.round(c.height);   // גובה עמודה/שדה נעוץ (בלעדיו — גובה המוצר)
    if (Number.isFinite(c.split) && c.split >= 0) col.split = Math.round(c.split);   // פיצול דלת לעמודה (0 = בלי; בלעדיו — ברירת המחדל של המוצר)
    if (col.gaps && col.shelves === undefined) col.shelves = col.gaps.length - 1;
    if (Object.keys(col).length) out.cols[i] = col;
  }
  return out;
}
const pin = (x) => (Number.isFinite(x) && x > 0 ? x : null);

/** true אם יש בפריסה משהו שנקבע ביד (אחרת אפשר לשמור null). */
export function layoutIsEmpty(layout) {
  return !layout || (layout.widths.every((w) => w === null) && Object.keys(layout.cols).length === 0);
}

/** הקבוצה `key` מתוך הערך השמור (פורמט קבוצות, או הפורמט הישן = 'main'). */
export function sectionLayout(raw, key, columns) {
  if (!raw || typeof raw !== 'object') return normalizeLayout(null, columns);
  if (raw.sections && typeof raw.sections === 'object') return normalizeLayout(raw.sections[key], columns);
  return key === 'main' ? normalizeLayout(raw, columns) : normalizeLayout(null, columns);
}

/** מספר הפריטים של עמודה: מדפים → תאים = מדפים+1; מגירות → תאים = מגירות. הגובה הפנוי בהתאם. */
export function cellsOf(item, count) {
  const n = Math.max(0, count);
  return item.cellsOf === 'drawers'
    ? { cells: n, free: item.innerH }
    : { cells: n + 1, free: item.innerH - n * (item.shelfT || 18) };
}

/** הנעיצות בפועל: מה שנשמר, ובלעדיו ברירת המחדל של הפריט (למשל "השורה העליונה בגובה נתון"). */
export const effectivePins = (pins, items) => pins.map((p, i) => (p !== null && p !== undefined ? p : items[i]?.defaultPin ?? null));
