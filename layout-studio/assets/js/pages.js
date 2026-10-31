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

/** האם הצד הפנימי (השדרה / הכריכה) של העמוד מימין. */
export function insideOnRight(model, num) {
  if (model.layout.facing) return pageSide(model, num) === 'left';   // עמוד משמאל לשדרה — השדרה מימינו
  return model.layout.binding === 'right';
}

/** שולי העמוד בפועל — שמאל/ימין במקום פנימי/חיצוני. */
export function sideMargins(model, num) {
  const m = model.layout.margins;
  const insideOnRight_ = insideOnRight(model, num);
  return {
    top: m.top, bottom: m.bottom,
    left: insideOnRight_ ? m.outside : m.inside,
    right: insideOnRight_ ? m.inside : m.outside,
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

/** שינוי מספר העמודים — מוסיף בסוף או מוריד מהסוף. עמוד חדש מקבל את עמוד האב של האחרון. מחזיר מודל חדש. */
export function setPageCount(model, count, newId) {
  const pages = pagesOf(model);
  const size = pageSize(model);
  count = Math.max(1, Math.min(2000, Math.round(count)));
  const others = model.surfaces.filter(s => s.type !== 'page');
  const next = pages.slice(0, count);
  const master = pages.length ? pages[pages.length - 1].master ?? null : (mastersOf(model)[0]?.id ?? null);
  while (next.length < count) next.push({ id: newId(), type: 'page', size: [...size], master, layers: [] });
  return { ...model, surfaces: [...next, ...others] };
}

/** גודל עמוד חדש לכל העמודים ולעמודי האב. */
export function setPageSize(model, size) {
  return { ...model, surfaces: model.surfaces.map(s => s.type === 'page' || s.type === 'master' ? { ...s, size: [...size] } : s) };
}

// ===== עמודי אב ומספור =====

export const mastersOf = model => model.surfaces.filter(s => s.type === 'master');

export function masterOf(model, page) {
  return page.master ? model.surfaces.find(s => s.type === 'master' && s.id === page.master) || null : null;
}

/** עמוד אב לכל העמודים שבאינדקסים — או null (בלי עמוד אב). */
export function setMaster(model, indexes, masterId) {
  const set = new Set(indexes);
  let i = -1;
  return { ...model, surfaces: model.surfaces.map(s => s.type !== 'page' ? s : (++i, set.has(i) ? { ...s, master: masterId } : s)) };
}

/** עדכון עמוד אב (לפי id) — מיזוג שדות. */
export function updateMaster(model, id, patch) {
  return { ...model, surfaces: model.surfaces.map(s => s.type === 'master' && s.id === id ? { ...s, ...patch } : s) };
}

/** מסמך מלפני עמודי האב — מקבל עמוד אב A (בלי לשייך אליו עמודים) ומספור. */
export function ensureMasters(model) {
  let m = model;
  if (!m.layout.numbering) m = { ...m, layout: { ...m.layout, numbering: { start: 1, style: 'arabic' } } };
  if (!mastersOf(m).length) {
    const size = pageSize(m);
    m = { ...m, surfaces: [...m.surfaces, { id: 'mA', type: 'master', name: 'A', size: [...size], layers: [],
      running: { folio: { position: 'bottom', align: 'outside', size: 10 }, header: null } }] };
  }
  return m;
}

const HEB_ONES = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];
const HEB_TENS = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
const HEB_HUNDREDS = ['', 'ק', 'ר', 'ש', 'ת', 'תק', 'תר', 'תש', 'תת', 'תתק'];

/** מספר באותיות: 1 → א, 15 → טו, 16 → טז, 115 → קטו, 1005 → א׳ה. בלי גרשיים — כמו במספור עמודים בספר. */
export function hebrewNumeral(n) {
  n = Math.floor(n);
  if (n <= 0) return String(n);
  let out = '';
  if (n >= 1000) { out += hebrewNumeral(Math.floor(n / 1000)) + '׳'; n %= 1000; }
  out += HEB_HUNDREDS[Math.floor(n / 100)];
  const r = n % 100;
  if (r === 15) return out + 'טו';     // לא י+ה
  if (r === 16) return out + 'טז';     // לא י+ו
  return out + HEB_TENS[Math.floor(r / 10)] + HEB_ONES[r % 10];
}

/** מספר העמוד כפי שמודפס (לפי מספור המסמך). num — מספר העמוד בקובץ, 1-based. */
export function folioText(model, num) {
  const nb = model.layout.numbering || { start: 1, style: 'arabic' };
  const n = nb.start + num - 1;
  return nb.style === 'hebrew' ? hebrewNumeral(n) : String(n);
}

/**
 * הכותרות שכל עמוד "נמצא בתוכן" — לכותרת רצה: מערך לפי עמוד (0-based) של { 1: טקסט, 2: …, 3: … }.
 * לכל רמה: הכותרת הראשונה בעמוד; אין בעמוד — האחרונה שלפניו (כמו "running header" ב-InDesign).
 * text — layoutFlows (כל מסגרת עם heads); framesOnPage(i) — מסגרות העמוד.
 */
export function headsByPage(model, text, framesOnPage) {
  const out = [], cur = {};
  pagesOf(model).forEach((_, i) => {
    const here = framesOnPage(i).flatMap(fr => text?.frames.get(fr.id)?.heads || []).sort((a, b) => a.y - b.y);
    const start = { ...cur };
    for (const h of here) {   // לפי הסדר בעמוד: כל כותרת קובעת את הרמה שלה ומאפסת את מה שמתחתיה
      cur[h.level] = h.text;
      for (let d = h.level + 1; d <= 3; d++) delete cur[d];
    }
    const page = {};
    for (const lv of [1, 2, 3]) {
      const first = here.find(h => h.level === lv);
      // בעמוד — הראשונה; אין, אבל יש בעמוד כותרת גבוהה ממנה — מה שנשאר בסוף העמוד; אחרת — מה שהיה בתחילתו
      const v = first ? first.text : here.some(h => h.level < lv) ? cur[lv] : start[lv];
      if (v) page[lv] = v;
    }
    out.push(page);
  });
  return out;
}

/** "{כותרת 1}" / "{כותרת 2}" / "{כותרת 3}" בטקסט הכותרת הרצה → הכותרת של העמוד (אין — ריק). */
export const fillHeads = (str, heads = {}) => str.replace(/\{כותרת ([1-3])\}/g, (_, lv) => heads[lv] || '').replace(/\s+/g, ' ').trim();

/**
 * מה שעמוד האב מצייר על העמוד: [{ kind, text, x, y, align: 'left'|'center'|'right', size }] בקואורדינטות העמוד.
 * y — אמצע השורה, באמצע השוליים (למטה או למעלה). חיצוני/פנימי — לפי צד השדרה של העמוד.
 * heads — הכותרות של העמוד (headsByPage), לכותרת רצה עם "{כותרת 1}".
 */
export function runningItems(model, num, heads = {}) {
  const page = pagesOf(model)[num - 1];
  const master = page && masterOf(model, page);
  if (!master || !master.running) return [];
  const [w, h] = pageSize(model);
  const [cx, , cw] = contentBox(model, num);
  const m = model.layout.margins;
  const inRight = insideOnRight(model, num);
  const place = o => {
    const y = o.position === 'top' ? m.top / 2 : h - m.bottom / 2;
    if (o.align === 'center') return { x: cx + cw / 2, y, align: 'center' };
    const right = (o.align === 'inside') === inRight;   // פנימי והשדרה מימין, או חיצוני והשדרה משמאל — לצד ימין
    return right ? { x: cx + cw, y, align: 'right' } : { x: cx, y, align: 'left' };
  };
  const out = [];
  const { folio, header } = master.running;
  if (folio) out.push({ kind: 'folio', text: folioText(model, num), size: folio.size, ...place(folio) });
  if (header) {
    const even = model.layout.facing && num % 2 === 0;
    const text = fillHeads((even ? header.even : header.odd) || '', heads);
    if (text) out.push({ kind: 'header', text, size: header.size, ...place(header) });
  }
  return out;
}

/** העמוד (אינדקס 0-based) שבנקודה במרחב המסמך, או -1. */
export function pageAt(lay, x, y) {
  for (const s of lay.spreads) {
    if (y < s.y || y > s.y + lay.pageH) continue;
    for (const p of s.pages) if (x >= p.x && x <= p.x + lay.pageW) return p.index;
  }
  return -1;
}

/** בדיקה בצד הדפדפן — אותם כללים כמו lib/model.php, כדי לא לשלוח מודל שהשרת ידחה. */
export function marginsFit(model) {
  const [w, h] = pageSize(model);
  const m = model.layout.margins;
  const min = mmToPt(10);
  return [m.top, m.bottom, m.inside, m.outside].every(v => v >= 0) &&
    m.inside + m.outside <= w - min && m.top + m.bottom <= h - min;
}
