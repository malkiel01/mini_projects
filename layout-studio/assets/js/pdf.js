// פלט PDF להדפסה: העמודים כמו שהעימוד חישב אותם → רשימת ציור (JSON) של pdf-core
// (creative-suite/pdf-core/README.md). בלי DOM — נבדק ב-tests/pdf.test.mjs.
//
// השרת לא מעמד כלום: כל גליף יוצא במקום ש-HarfBuzz קבע על המסך, באותו גופן — ולכן הניקוד יושב בדיוק כמו בתצוגה.
// הגופנים משתנים (ציר wght), ו-pdf-core מטמיע את הקווים של ברירת המחדל (400). למודגש — מופעים סטטיים של אותו
// קובץ (assets/fonts/static/<שם>-700.ttf / -900.ttf; מזהי הגליפים זהים — נוצרו ב-fontTools instancer).

import { pagesOf, pageSize, runningItems, headsByPage } from './pages.js';
import { framesOnPage, isImage, imagePlacement, FONTS } from './frames.js';
import { shapeLine, advance } from './shaper.js';

const STATIC_WEIGHTS = [700, 900];

/** מפתח הגופן ברשימה (מה שהשרת מתרגם לקובץ), לפי השם של הגופן הטעון: "Noto Serif Hebrew" / "…@wght700". */
export function fontKey(f) {
  const [family, axes = ''] = f.name.split('@');
  const file = FONTS[family];
  if (!file) throw new Error('font ' + f.name);
  const w = Number((axes.match(/wght(\d+)/) || [])[1] || 400);
  if (w === 400) return 'fonts/' + file;
  const near = STATIC_WEIGHTS.reduce((a, b) => Math.abs(b - w) < Math.abs(a - w) ? b : a);
  return 'fonts/static/' + file.replace(/\.ttf$/, '') + '-' + near + '.ttf';
}

const r2 = v => Math.round(v * 1000) / 1000;

/**
 * שורה מעוצבת → פעולות glyphs: רצף לכל גופן (גליף מגופן חלופי — g.f — ברצף משלו).
 * glyphs ביחידות הגופן הראשי (shaper.js), dy למעלה — כמו ש-pdf-core מצפה.
 * text — הטקסט הלוגי של השורה (cluster של כל גליף הוא אינדקס בו): כל רצף מקבל את החלק שלו, והרצפים יוצאים בסדר
 * הקריאה — כך שחיפוש והעתקה מה-PDF נותנים את הטקסט כמו שנכתב, גם כשבשורה מודגש או גופן חלופי.
 */
export function glyphOps(f, glyphs, x, y, size, text = '') {
  const s = size / f.upem;
  const runs = [];
  let pen = 0, cur = null;
  for (const g of glyphs) {
    const gf = g.f || f;
    if (!cur || cur.f !== gf) {
      cur = { f: gf, first: g.cluster, last: g.cluster, op: { op: 'glyphs', font: fontKey(gf), size, color: '#000000', x: r2(x + pen * s), y: r2(y), glyphs: [] } };
      runs.push(cur);
    }
    cur.first = Math.min(cur.first, g.cluster);
    cur.last = Math.max(cur.last, g.cluster);
    cur.op.glyphs.push([g.gid, r2(g.dx * s), r2(g.dy * s), r2(g.ax * s)]);
    pen += g.ax;
  }
  if (!text) return runs.map(r => r.op);
  runs.sort((a, b) => a.first - b.first);
  runs.forEach((r, i) => {
    // עד תחילת הרצף הבא בסדר הקריאה (כך גם הרווח שבין הרצפים נכלל), והאחרון — עד סוף השורה
    const end = i + 1 < runs.length ? runs[i + 1].first : text.length;
    const t = text.slice(r.first, Math.max(end, r.last + 1));
    if (t) r.op.text = t;
  });
  return runs.map(r => r.op);
}

/**
 * רשימת הציור של המסמך כולו.
 *   text — layoutFlows(model, fontsFor); running — הגופנים של מספר העמוד והכותרת הרצה (גופן הגוף).
 * מה לא מודפס: קווי עזר, מסגרות, סימן הגלישה — רק מה שעל הנייר.
 */
export function drawList(model, text, running, { title = '' } = {}) {
  const [w, h] = pageSize(model);
  const bleed = model.layout?.bleed || 0;
  const heads = headsByPage(model, text, i => framesOnPage(model, i));
  const pages = pagesOf(model).map((page, index) => {
    const ops = [];
    for (const fr of framesOnPage(model, index)) {
      if (isImage(fr)) {
        const r = imagePlacement(fr);
        ops.push({ op: 'image', src: fr.src, box: [r.x, r.y, r.w, r.h].map(r2), clip: fr.box.map(r2) });
        continue;
      }
      for (const l of text?.frames.get(fr.id)?.lines || []) ops.push(...glyphOps(l.font, l.glyphs, l.x, l.y, l.size, l.text || ''));
    }
    for (const it of runningItems(model, index + 1, heads[index])) {
      const g = shapeLine(running, it.text);
      const width = advance(g) * it.size / running[0].upem;
      const x0 = it.align === 'left' ? it.x : it.align === 'right' ? it.x - width : it.x - width / 2;
      ops.push(...glyphOps(running[0], g, x0, it.y + it.size * 0.3, it.size, it.text));
    }
    return { size: [w, h], ...(bleed ? { bleed } : {}), ops };
  });
  return { title, pages };
}
