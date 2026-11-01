// פלט PDF להדפסה: העמודים כמו שהעימוד חישב אותם → רשימת ציור (JSON) של pdf-core
// (creative-suite/pdf-core/README.md). בלי DOM — נבדק ב-tests/pdf.test.mjs.
//
// השרת לא מעמד כלום: כל גליף יוצא במקום ש-HarfBuzz קבע על המסך, באותו גופן — ולכן הניקוד יושב בדיוק כמו בתצוגה.
// הגופנים משתנים (ציר wght), ו-pdf-core מטמיע את הקווים של ברירת המחדל (400). למודגש — מופעים סטטיים של אותו
// קובץ (assets/fonts/static/<שם>-700.ttf / -900.ttf; מזהי הגליפים זהים — נוצרו ב-fontTools instancer).

import { pagesOf, pageSize, runningItems, headsByPage } from './pages.js';
import { framesOnPage, drawingsOnPage, isImage, imagePlacement, FONTS } from './frames.js';
import { mul } from './vector.js';
import { shapeLine, advance } from './shaper.js';

const STATIC_WEIGHTS = [700, 900];

/** מפתח הגופן ברשימה (מה שהשרת מתרגם לקובץ), לפי השם של הגופן הטעון: "Noto Serif Hebrew" / "…@wght700". */
export function fontKey(f) {
  if (f.key) return f.key;   // גופן של המשתמש (model.fonts) — הקובץ עצמו מהאחסון שלו, באותו משקל
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
export function glyphOps(f, glyphs, x, y, size, text = '', color = '#000000') {
  const s = size / f.upem;
  const runs = [];
  let pen = 0, cur = null;
  for (const g of glyphs) {
    const gf = g.f || f;
    if (!cur || cur.f !== gf) {
      cur = { f: gf, first: g.cluster, last: g.cluster, op: { op: 'glyphs', font: fontKey(gf), size, color: /^#[0-9a-f]{6}$/i.test(color) ? color.toLowerCase() : '#000000', x: r2(x + pen * s), y: r2(y), glyphs: [] } };
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

// ===== ציורים (שכבות vector): נתיבים אמיתיים ב-PDF, ומה ש-pdf-core עוד לא יודע — כתמונה =====
// pdf-core: path (d מוחלט M L C Q Z, transform, fill / stroke, שקיפות) — שלב 1; clip … restore ומעבר צבע — שלב 2.

const ID = [1, 0, 0, 1, 0, 0];
const ABS_D = /^[\sMLCQZ0-9eE.,+-]*[MLCQZ][\sMLCQZ0-9eE.,+-]*$/;
const HEX = /^#[0-9a-f]{6}$/i;
const clamp01 = v => Math.min(1, Math.max(0, v));

/**
 * מילוי / קו של הפורמט → { paint, alpha } ל-pdf-core, או null (לא נתמך עדיין). paint — '#rrggbb' או { gradient }.
 * מעבר צבע: from/to → x1..y2, center/radius → cx cy r (המוקד במרכז), at → offset; opacity של עצירה — כמו שהיא
 * (מסכה רכה ב-pdf-core). שקיפות זהה בכל העצירות — עוברת לשקיפות של הנתיב כולו (בלי מסכה).
 */
function paintOf(spec) {
  const g = spec.gradient;
  if (!g) return HEX.test(spec.color || '') ? { paint: spec.color.toLowerCase(), alpha: spec.opacity ?? 1 } : null;
  const stops = Array.isArray(g.stops) ? g.stops : [];
  if (!stops.length || stops.some(st => !HEX.test(st.color || ''))) return null;
  const ops = new Set(stops.map(st => clamp01(st.opacity ?? 1)));
  const same = ops.size === 1;
  const pts = stops.map(st => ({ offset: r2(clamp01(st.at ?? 0)), color: st.color.toLowerCase(),
    ...(!same && (st.opacity ?? 1) !== 1 ? { opacity: r2(clamp01(st.opacity)) } : {}) }));
  let grad;
  if (g.type === 'linear' && Array.isArray(g.from) && Array.isArray(g.to)) {
    grad = { type: 'linear', x1: r2(g.from[0]), y1: r2(g.from[1]), x2: r2(g.to[0]), y2: r2(g.to[1]), stops: pts };
  } else if (g.type === 'radial' && Array.isArray(g.center) && g.radius > 0) {
    grad = { type: 'radial', cx: r2(g.center[0]), cy: r2(g.center[1]), r: r2(g.radius), fx: r2(g.center[0]), fy: r2(g.center[1]), stops: pts };
  } else return null;
  return { paint: { gradient: grad }, alpha: (spec.opacity ?? 1) * (same ? [...ops][0] : 1) };
}

/** פריט → פעולות path (שטוחות, במערכת העמוד), או null — יש בו משהו שעדיין רק כתמונה. */
function itemOps(it, T, symbols, depth = 0) {
  if (!it || typeof it !== 'object' || depth > 20) return [];
  if (Array.isArray(it.effects) && it.effects.length) return null;
  if (it.blend && it.blend !== 'normal') return null;
  if (it.type === 'group') {
    if ((it.opacity ?? 1) !== 1) return null;                        // שקיפות של קבוצה שלמה — לא נתיב-נתיב
    const kids = it.children || [];
    const out = [];
    // מסכת חיתוך: הילד הראשון הוא הצורה, השאר נחתכים בה (clip … restore של pdf-core)
    if (it.clip && kids.length) {
      const c = kids[0];
      if ((c.type && c.type !== 'path') || typeof c.d !== 'string' || !ABS_D.test(c.d)) return null;
      out.push({ op: 'clip', d: c.d.trim(), transform: mul(T, c.transform || ID).map(r2), ...(c.fillRule === 'evenodd' ? { fillRule: 'evenodd' } : {}) });
      for (const k of kids.slice(1)) { const o = itemOps(k, T, symbols, depth + 1); if (!o) return null; out.push(...o); }
      out.push({ op: 'restore' });
      return out;
    }
    for (const c of kids) { const o = itemOps(c, T, symbols, depth + 1); if (!o) return null; out.push(...o); }
    return out;
  }
  if (it.type === 'use') {
    const sy = symbols?.[it.symbol];
    if (!sy) return [];
    if ((it.opacity ?? 1) !== 1) return null;
    const M = mul(T, it.transform || ID), out = [];
    for (const c of sy.paths || []) { const o = itemOps(c, M, symbols, depth + 1); if (!o) return null; out.push(...o); }
    return out;
  }
  if (it.type && it.type !== 'path') return null;                   // תמונה, טקסט, סוג לא מוכר
  if (typeof it.d !== 'string' || !ABS_D.test(it.d)) return null;   // פקודה יחסית או קשת — כתמונה
  const alpha = it.opacity ?? 1;
  const op = { op: 'path', d: it.d.trim(), transform: mul(T, it.transform || ID).map(r2) };
  const f = it.fill;
  if (f) {
    const pf = paintOf(f);
    if (!pf) return null;
    op.fill = pf.paint;
    const a = clamp01(pf.alpha * alpha);
    if (a < 1) op.fillOpacity = r2(a);
    if (it.fillRule === 'evenodd') op.fillRule = 'evenodd';
  }
  const st = it.stroke;
  if (st && (st.width ?? 1) > 0) {
    const ps = paintOf(st);
    if (!ps) return null;
    op.stroke = ps.paint;
    op.width = r2(st.width ?? 1);
    const a = clamp01(ps.alpha * alpha);
    if (a < 1) op.strokeOpacity = r2(a);
    if (st.cap && st.cap !== 'butt') op.cap = st.cap;
    if (st.join && st.join !== 'miter') op.join = st.join;
    if (st.miter != null && st.miter !== 4) op.miter = st.miter;
    if (Array.isArray(st.dash) && st.dash.length) op.dash = st.dash.map(r2);
  }
  if (!op.fill && !op.stroke) return [];                              // לא נראה — לא נכנס
  return [op];
}

/**
 * ציור מוצב → קטעים לפי הסדר: { ops } — נתיבים ל-pdf-core, או { raster: [פריטים] } — מה שיוצא כתמונה (תמונה וטקסט
 * בתוך הציור, אפקטים). פריטים רצופים מאותו סוג — קטע אחד, כך שהסדר (מה מעל מה) נשמר.
 */
export function drawingSegments(drawing, symbols = {}) {
  const segs = [];
  for (const it of drawing.paths || []) {
    const o = itemOps(it, ID, symbols);
    const last = segs[segs.length - 1];
    if (o) { if (!o.length) continue; if (last?.ops) last.ops.push(...o); else segs.push({ ops: o }); }
    else if (last?.raster) last.raster.push(it); else segs.push({ raster: [it] });
  }
  return segs;
}

/** המפתח של קטע-תמונה (rasters ב-drawList). */
export const rasterKey = (drawingId, index) => drawingId + '#' + index;

/**
 * רשימת הציור של המסמך כולו.
 *   text — layoutFlows(model, fontsFor); running — הגופנים של מספר העמוד והכותרת הרצה (גופן הגוף).
 * מה לא מודפס: קווי עזר, מסגרות, סימן הגלישה — רק מה שעל הנייר.
 * rasters — Map(rasterKey → { src, box }): התמונות של קטעי הציורים ש-pdf-core עוד לא מצייר (drawingSegments).
 */
export function drawList(model, text, running, { title = '', rasters = new Map() } = {}) {
  const [w, h] = pageSize(model);
  const bleed = model.layout?.bleed || 0;
  const heads = headsByPage(model, text, i => framesOnPage(model, i));
  const pages = pagesOf(model).map((page, index) => {
    // ציורים — מתחת לטקסט ולתמונות (כמו על המסך), כל אחד בסדר הפריטים שלו
    const ops = [];
    for (const d of drawingsOnPage(model, index)) {
      drawingSegments(d, model.symbols || {}).forEach((sg, k) => {
        if (sg.ops) { ops.push(...sg.ops); return; }
        const r = rasters.get(rasterKey(d.id, k));
        if (r) ops.push({ op: 'image', src: r.src, box: r.box.map(r2) });
      });
    }
    for (const fr of framesOnPage(model, index)) {
      if (isImage(fr)) {
        const r = imagePlacement(fr);
        ops.push({ op: 'image', src: fr.src, box: [r.x, r.y, r.w, r.h].map(r2), clip: fr.box.map(r2) });
        continue;
      }
      for (const l of text?.frames.get(fr.id)?.lines || []) ops.push(...glyphOps(l.font, l.glyphs, l.x, l.y, l.size, l.text || '', l.color));
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
