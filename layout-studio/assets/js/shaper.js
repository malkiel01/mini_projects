// עיצוב גליפים (shaping) ב-HarfBuzz — אותו מיקום לכל אות וסימן ניקוד בכל דפדפן, ובהמשך גם ב-PDF.
// הדפדפן לא משמש למדידה ולא לציור טקסט: כל גליף הוא נתיב מהגופן עצמו, במקום ש-HarfBuzz קבע.
//
// HarfBuzz (harfbuzzjs, MIT) — assets/vendor/harfbuzzjs; נטען רק כשצריך טקסט.
// bidi (bidi-js, MIT, UAX #9) — assets/vendor/bidi-js: HarfBuzz רק ממקם גליפים; את סדר הרצפים בשורה
// מעורבת ("פרק 12: Genesis") קובע אלגוריתם ה-bidi, לפני העיצוב.

import bidiFactory from '../vendor/bidi-js/bidi.mjs';

let hbPromise = null;
const hb = () => (hbPromise ||= import('../vendor/harfbuzzjs/index.mjs'));
let bidi = null;

/** טעינת גופן (TTF/OTF — לא woff2: HarfBuzz קורא את הטבלאות עצמן). src — כתובת, או ArrayBuffer (בבדיקות). */
export async function loadFont(src) {
  const H = await hb();
  let data = src;
  if (typeof src === 'string') {
    const res = await fetch(src, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('font ' + res.status);
    data = await res.arrayBuffer();
  }
  const face = new H.Face(new H.Blob(data));
  const font = new H.Font(face);
  return { H, face, font, upem: face.upem, paths: new Map() };
}

/**
 * עיצוב רצף טקסט. מחזיר את הגליפים בסדר חזותי (משמאל לימין, גם בעברית) ביחידות הגופן:
 *   [{ gid, cluster, ax, dx, dy }]  — ax: התקדמות, dx/dy: היסט (לסימני ניקוד וטעמים)
 * dir: 'rtl' | 'ltr' | undefined (ניחוש לפי הטקסט).
 */
export function shape(f, text, dir) {
  const { H, font } = f;
  const buf = new H.Buffer();
  buf.addText(text);
  if (dir === 'rtl') {
    buf.setDirection(H.Direction.RTL);
    buf.setScript('Hebr');
    buf.setLanguage('he');
  } else if (dir === 'ltr') {
    buf.setDirection(H.Direction.LTR);
  }
  buf.guessSegmentProperties();
  H.shape(font, buf);
  const inf = buf.getGlyphInfos(), pos = buf.getGlyphPositions();
  const out = new Array(inf.length);
  for (let i = 0; i < inf.length; i++) {
    out[i] = { gid: inf[i].codepoint, cluster: inf[i].cluster, ax: pos[i].xAdvance, dx: pos[i].xOffset, dy: pos[i].yOffset };
  }
  return out;
}

/** רוחב הרצף ביחידות הגופן. */
export const advance = glyphs => glyphs.reduce((a, g) => a + g.ax, 0);

/** נתיב הגליף (Path2D ביחידות הגופן, y למעלה) — במטמון. */
export function glyphPath(f, gid) {
  let p = f.paths.get(gid);
  if (!p) { p = new Path2D(f.font.glyphToPath(gid)); f.paths.set(gid, p); }
  return p;
}

/**
 * ציור רצף מעוצב על קנבס. (x, y) — תחילת קו הבסיס משמאל; size — גודל הגופן ביחידות של ההקשר.
 */
export function drawGlyphs(ctx, f, glyphs, x, y, size) {
  const s = size / f.upem;
  let pen = 0;
  for (const g of glyphs) {
    ctx.save();
    ctx.translate(x + (pen + g.dx) * s, y - g.dy * s);
    ctx.scale(s, -s);
    ctx.fill(glyphPath(f, g.gid));
    ctx.restore();
    pen += g.ax;
  }
}

/**
 * עיצוב שורה שלמה עם bidi: רצפים לפי רמות ההטבעה (UAX #9), כל רצף בכיוון שלו, וסדר חזותי לפי L2.
 * מחזיר גליפים בסדר חזותי (משמאל לימין); cluster — אינדקס בטקסט המקורי.
 * base: 'rtl' (ברירת מחדל — פסקה עברית) או 'ltr'.
 */
export function shapeLine(f, text, base = 'rtl') {
  bidi ||= bidiFactory();
  const { levels } = bidi.getEmbeddingLevels(text, base);
  // רצפים לוגיים של אותה רמה
  const runs = [];
  for (let i = 0; i < text.length; i++) {
    const last = runs[runs.length - 1];
    if (last && last.level === levels[i]) last.end = i + 1;
    else runs.push({ start: i, end: i + 1, level: levels[i] });
  }
  // L2: מהרמה הגבוהה ועד הנמוכה האי-זוגית — הופכים כל רצף רציף של רצפים ברמה הזו ומעלה
  let max = 0, minOdd = Infinity;
  for (const r of runs) { max = Math.max(max, r.level); if (r.level % 2) minOdd = Math.min(minOdd, r.level); }
  const order = runs.slice();
  for (let lv = max; lv >= minOdd && lv > 0; lv--) {
    for (let i = 0; i < order.length; ) {
      if (order[i].level < lv) { i++; continue; }
      let j = i;
      while (j < order.length && order[j].level >= lv) j++;
      order.splice(i, j - i, ...order.slice(i, j).reverse());
      i = j;
    }
  }
  const out = [];
  for (const r of order) {
    const g = shape(f, text.slice(r.start, r.end), r.level % 2 ? 'rtl' : 'ltr');
    for (const x of g) { x.cluster += r.start; out.push(x); }
  }
  return out;
}
