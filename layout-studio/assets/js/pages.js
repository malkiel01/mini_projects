// גאומטריית העמודים — בלי DOM, כדי שאפשר לבדוק ב-node (tests/pages.test.mjs).
// הכול ב-pt. "מרחב המסמך": הפריסות (spreads) זו מתחת לזו, כמו בלוח העמודים של InDesign.

export const PT_PER_MM = 72 / 25.4;
export const mmToPt = mm => Math.round(mm * PT_PER_MM * 100) / 100;
export const ptToMm = pt => Math.round(pt / PT_PER_MM * 10) / 10;

export const SPREAD_GAP = 36;   // רווח בין פריסות
export const PAD = 36;          // שוליים סביב המסמך כולו

export function pageSize(model) {
  const p = model.surfaces.find(s => s.type === 'page');
  return p.size;
}

/** רק העמודים (משטחים מסוגים אחרים — למשל עמודי אב בהמשך — לא נספרים). */
export const pagesOf = model => model.surfaces.filter(s => s.type === 'page');

/**
 * הפריסות: מערך של מערכי אינדקסים (0-based) לעמודים.
 * עמודים כפולים: עמוד 1 לבד, ואחריו זוגות [2,3] [4,5]… (כמו ספר: עמוד 1 הוא דף ימני/שמאלי בודד).
 */
export function spreads(model) {
  const n = pagesOf(model).length;
  const out = [];
  if (!model.layout.facing) {
    for (let i = 0; i < n; i++) out.push([i]);
    return out;
  }
  if (n) out.push([0]);
  for (let i = 1; i < n; i += 2) out.push(i + 1 < n ? [i, i + 1] : [i]);
  return out;
}

/**
 * באיזה צד של השדרה העמוד: 'left' או 'right'. מספר עמוד 1-based.
 * כריכה מימין (ספר עברי): אי-זוגי משמאל לשדרה, זוגי מימין. כריכה משמאל — הפוך.
 * בלי עמודים כפולים — אין שדרה; הצד "הפנימי" הוא צד הכריכה.
 */
export function pageSide(model, num) {
  const odd = num % 2 === 1;
  if (model.layout.binding === 'right') return odd ? 'left' : 'right';
  return odd ? 'right' : 'left';
}

/** שולי העמוד בפועל — שמאל/ימין במקום פנימי/חיצוני. */
export function sideMargins(model, num) {
  const m = model.layout.margins;
  let insideOnRight;
  if (model.layout.facing) insideOnRight = pageSide(model, num) === 'left';   // עמוד משמאל לשדרה — השדרה מימינו
  else insideOnRight = model.layout.binding === 'right';
  return {
    top: m.top, bottom: m.bottom,
    left: insideOnRight ? m.outside : m.inside,
    right: insideOnRight ? m.inside : m.outside,
  };
}

/** מלבן אזור התוכן בתוך העמוד: [x, y, w, h]. */
export function contentBox(model, num) {
  const [w, h] = pageSize(model);
  const s = sideMargins(model, num);
  return [s.left, s.top, w - s.left - s.right, h - s.top - s.bottom];
}

/**
 * הפריסה המלאה במרחב המסמך:
 *   width, height — גודל המסמך כולו
 *   spreads: [{ y, pages: [{ index, num, x, y }] }]
 * בעמודים כפולים כל פריסה ברוחב שני עמודים, כך שעמוד בודד (1, או האחרון) נשאר בצד הנכון שלו.
 */
export function layoutDoc(model) {
  const [w, h] = pageSize(model);
  const facing = model.layout.facing;
  const spreadW = facing ? w * 2 : w;
  const list = spreads(model);
  let y = PAD;
  const out = [];
  for (const idxs of list) {
    const pages = idxs.map(index => {
      const num = index + 1;
      const x = PAD + (facing && pageSide(model, num) === 'right' ? w : 0);
      return { index, num, x, y };
    });
    out.push({ y, pages });
    y += h + SPREAD_GAP;
  }
  return { width: spreadW + PAD * 2, height: y - SPREAD_GAP + PAD, pageW: w, pageH: h, spreads: out };
}

/** הפריסה שמכילה את העמוד (0-based), או -1. */
export function spreadOfPage(lay, index) {
  return lay.spreads.findIndex(s => s.pages.some(p => p.index === index));
}

/** שינוי מספר העמודים — מוסיף בסוף או מוריד מהסוף. מחזיר מודל חדש. */
export function setPageCount(model, count, newId) {
  const pages = pagesOf(model);
  const size = pageSize(model);
  count = Math.max(1, Math.min(2000, Math.round(count)));
  let others = model.surfaces.filter(s => s.type !== 'page');
  let next = pages.slice(0, count);
  while (next.length < count) next.push({ id: newId(), type: 'page', size: [...size], master: null, layers: [] });
  return { ...model, surfaces: [...next, ...others] };
}

/** גודל עמוד חדש לכל העמודים. */
export function setPageSize(model, size) {
  return { ...model, surfaces: model.surfaces.map(s => s.type === 'page' ? { ...s, size: [...size] } : s) };
}

/** בדיקה בצד הדפדפן — אותם כללים כמו lib/model.php, כדי לא לשלוח מודל שהשרת ידחה. */
export function marginsFit(model) {
  const [w, h] = pageSize(model);
  const m = model.layout.margins;
  const min = mmToPt(10);
  return [m.top, m.bottom, m.inside, m.outside].every(v => v >= 0) &&
    m.inside + m.outside <= w - min && m.top + m.bottom <= h - min;
}
