// טקסט → קווי מתאר (הכרעת בעל המערכת: בייצוא הטקסט הופך לנתיבים). opentype.js (MIT, assets/js/vendor) קורא את
// הגופן — גם גופנים משתנים (wght) — ונטען רק בייצוא. הוא לא הופך סדר לעברית, אז הסדר החזותי — כאן.

import { lines, lineHeight, FONTS, TEXT_DEFAULT } from './text.js';
import { normalize } from './geometry/path.js';

const RTL = /[֐-׿יִ-ﭏ؀-ۿ]/;
const LTR = /[A-Za-zÀ-ɏͰ-ϿЀ-ӿ0-9]/;
const MIRROR = { '(': ')', ')': '(', '[': ']', ']': '[', '{': '}', '}': '{', '<': '>', '>': '<', '«': '»', '»': '«' };

/**
 * הסדר החזותי של שורה בכיוון בסיס מימין לשמאל (bidi מקוצר): רצפים של עברית מתהפכים (וסוגריים מתחלפים),
 * רצפים של לטינית וספרות נשארים, וסדר הרצפים מתהפך. ניטרלי (רווח, פיסוק) בין שני רצפים משמאל לימין — שייך
 * להם; אחרת — לעברית.
 */
export function visualOrder(str) {
  const chars = [...str];
  const type = chars.map(c => (RTL.test(c) ? 'R' : LTR.test(c) ? 'L' : 'N'));
  for (let i = 0; i < type.length; i++) {
    if (type[i] !== 'N') continue;
    let j = i;
    while (j < type.length && type[j] === 'N') j++;
    const before = i > 0 ? type[i - 1] : 'R', after = j < type.length ? type[j] : 'R';
    const t = before === 'L' && after === 'L' ? 'L' : 'R';
    for (let k = i; k < j; k++) type[k] = t;
    i = j - 1;
  }
  const runs = [];
  chars.forEach((c, i) => {
    const last = runs[runs.length - 1];
    if (last && last.t === type[i]) last.s.push(c); else runs.push({ t: type[i], s: [c] });
  });
  return runs.reverse().map(r => (r.t === 'R' ? r.s.reverse().map(c => MIRROR[c] || c) : r.s).join('')).join('');
}

/** הקובץ שמתאים למשפחה ולעובי: גופן משתנה — הקובץ היחיד; סטטי — הקרוב בעובי. */
export function fontFile(family, weight = 400) {
  const f = FONTS.find(x => x.family === family) || FONTS[0];
  let best = f.files[0], bd = Infinity;
  for (const file of f.files) {
    const [lo, hi = lo] = String(file.weight).split(' ').map(Number);
    const d = weight < lo ? lo - weight : weight > hi ? weight - hi : 0;
    if (d < bd) { bd = d; best = file; }
  }
  return { file: best.file, variable: String(best.weight).includes(' ') };
}

/**
 * הנתיב של פריט טקסט (במרחב הלוח), כמו שהוא מצויר בקנבס: אותן שורות, אותו גובה שורה ואותו יישור.
 * getFont(file) → אובייקט opentype (טעון).
 */
export async function textToD(item, getFont) {
  const font = item.font || TEXT_DEFAULT;
  const { file, variable } = fontFile(font.family, font.weight || 400);
  const ot = await getFont(file);
  const opts = variable ? { variation: { wght: font.weight || 400 } } : {};
  const lh = lineHeight(item);
  const [x, y, w] = item.box;
  const parts = [];
  lines(item).forEach((l, i) => {
    if (!l.text.trim()) return;
    const vis = visualOrder(l.text);
    const by = y + i * lh + (lh - font.size) / 2 + font.size * 0.8;
    const at = (width) => (item.align === 'left' ? x : item.align === 'center' ? x + w / 2 - width / 2 : x + w - width);
    let d;
    try {
      const width = ot.getAdvanceWidth(vis, font.size, opts);
      d = toD(ot.getPath(vis, at(width), by, font.size, opts));
    } catch {
      // יש גופנים עם טבלאות החלפה ש-opentype.js לא מכיר — אז תו אחר תו, בלי ליגטורות
      d = glyphByGlyph(ot, vis, at, by, font.size, opts);
    }
    if (d) parts.push(d);
  });
  return parts.length ? normalize(parts.join(' ')) : '';
}

function glyphByGlyph(ot, str, at, by, size, opts) {
  const scale = size / ot.unitsPerEm;
  const glyphs = [...str].map(c => ot.charToGlyph(c));
  const width = glyphs.reduce((n, g) => n + (g.advanceWidth || 0) * scale, 0);
  let x = at(width);
  const out = [];
  for (const g of glyphs) {
    const d = toD(g.getPath(x, by, size, opts, ot));
    if (d) out.push(d);
    x += (g.advanceWidth || 0) * scale;
  }
  return out.join(' ');
}

/**
 * Path של opentype → d. יש גופנים (Amatic SC Bold) שבהם opentype.js מחזיר נקודת בקרה NaN — אז המקטע
 * נהיה קו ישר לנקודת הסיום, במקום שכל הייצוא ייכשל.
 */
function toD(path) {
  const ok = (...v) => v.every(Number.isFinite);
  const n = (v) => String(Math.round(v * 1000) / 1000);
  const out = [];
  for (const c of path.commands) {
    if (c.type === 'Z') { out.push('Z'); continue; }
    if (!ok(c.x, c.y)) continue;
    if (c.type === 'M') out.push(`M${n(c.x)} ${n(c.y)}`);
    else if (c.type === 'Q' && ok(c.x1, c.y1)) out.push(`Q${n(c.x1)} ${n(c.y1)} ${n(c.x)} ${n(c.y)}`);
    else if (c.type === 'C' && ok(c.x1, c.y1, c.x2, c.y2)) out.push(`C${n(c.x1)} ${n(c.y1)} ${n(c.x2)} ${n(c.y2)} ${n(c.x)} ${n(c.y)}`);
    else out.push(`L${n(c.x)} ${n(c.y)}`);
  }
  return out.join(' ');
}
