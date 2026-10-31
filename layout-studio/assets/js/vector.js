// ציורים מהווקטורי בתוך העימוד: שכבת vector (mbe-format.md סעיף 3, "תוכן שכבה vector") — ציור, הצבה והזזה.
// בלי DOM מלבד הציור עצמו (ctx) — החשבון נבדק ב-tests/vector.test.mjs.
//
//   שכבה מוצבת = { id, type: 'vector', name, box: [x, y, w, h], paths: [...] } — הנתיבים כבר בקואורדינטות העמוד (כמו
//   שכל קורא מצפה); box הוא שלנו (מסגרת הבחירה), ושינוי שלו מזיז ומגדיל את כל הנתיבים.
//   נתמך: נתיב (d, transform, fill / stroke עם color או gradient, fillRule, dash), קבוצה, מסכת חיתוך (clip: true),
//   מופע של סמל (use), תמונה (image). טקסט קצר ואפקטים — עוד לא (מדלגים, ומציינים בדוח ההצבה).

/** [a b c d e f] × [a b c d e f] — קודם m2, אחר כך m1 (כמו ב-SVG: transform="m1 m2"). */
export const mul = (m1, m2) => [
  m1[0] * m2[0] + m1[2] * m2[1], m1[1] * m2[0] + m1[3] * m2[1],
  m1[0] * m2[2] + m1[2] * m2[3], m1[1] * m2[2] + m1[3] * m2[3],
  m1[0] * m2[4] + m1[2] * m2[5] + m1[4], m1[1] * m2[4] + m1[3] * m2[5] + m1[5],
];
const ID = [1, 0, 0, 1, 0, 0];
const r4 = v => Math.round(v * 10000) / 10000;

/**
 * הפעלת מטריצה על פריטים. לקבוצה אין transform משלה (לפי הפורמט) — עוברים לילדים; לכל השאר — transform מורכב.
 * תמונה עם box בלי transform — מקבלת transform. פריט מסוג לא מוכר — נשאר כמו שהוא (וכנראה יזוז לא נכון: נכתב בדוח).
 */
export function transformItems(items, T) {
  return (items || []).map(it => {
    if (!it || typeof it !== 'object') return it;
    if (it.type === 'group') return { ...it, children: transformItems(it.children, T) };
    if (!it.type || it.type === 'path' || it.type === 'use' || it.type === 'image' || it.type === 'text') {
      return { ...it, transform: mul(T, it.transform || ID).map(r4) };
    }
    return it;
  });
}

/** מזהים חדשים לכל הפריטים (ייחודיים במסמך), עם מפה ישן → חדש — כדי להציב אותו ציור פעמיים. */
export function reId(items, fresh) {
  return (items || []).map(it => {
    if (!it || typeof it !== 'object') return it;
    const out = { ...it, id: fresh() };
    if (Array.isArray(it.children)) out.children = reId(it.children, fresh);
    return out;
  });
}

/** כל הסמלים שהפריטים מפנים אליהם (גם בתוך קבוצות וסמלים אחרים). */
export function symbolRefs(items, symbols = {}, out = new Set()) {
  for (const it of items || []) {
    if (it?.type === 'use' && symbols[it.symbol] && !out.has(it.symbol)) { out.add(it.symbol); symbolRefs(symbols[it.symbol].paths, symbols, out); }
    if (Array.isArray(it?.children)) symbolRefs(it.children, symbols, out);
  }
  return out;
}

/** כל ה-src של תמונות בפריטים ובסמלים. */
export function imageRefs(items, out = new Set()) {
  for (const it of items || []) {
    if (it?.type === 'image' && typeof it.src === 'string') out.add(it.src);
    if (Array.isArray(it?.children)) imageRefs(it.children, out);
  }
  return out;
}

/** מה לא יוצג נכון — לדוח ההצבה: { text, effects, unknown } (מספרים). */
export function gapsOf(items, out = { text: 0, effects: 0, unknown: 0 }) {
  for (const it of items || []) {
    if (!it || typeof it !== 'object') continue;
    if (it.type === 'text') out.text++;
    else if (it.type && !['path', 'group', 'use', 'image'].includes(it.type)) out.unknown++;
    if (Array.isArray(it.effects) && it.effects.length) out.effects++;
    if (Array.isArray(it.children)) gapsOf(it.children, out);
  }
  return out;
}

/**
 * ציור מקובץ וקטורי → שכבה מוצבת. doc — document.json של הקובץ; box — היכן בעמוד (התמונה נשמרת ביחס שלה בתוכו);
 * fresh() — מזהה חדש. מחזיר { layer, symbols, images, gaps } — symbols להוספה ל-model.symbols (בשמות חדשים).
 * לוקחים את המשטח הראשון ואת כל שכבות ה-vector הנראות בו, בסדר.
 */
export function placeDrawing(doc, box, fresh, name = 'ציור') {
  const surface = (doc?.surfaces || [])[0];
  if (!surface) throw new Error('no_surface');
  const [sw, sh] = surface.size || [0, 0];
  if (!(sw > 0 && sh > 0)) throw new Error('no_surface');
  const layers = (surface.layers || []).filter(l => l?.type === 'vector' && l.visible !== false);
  let items = layers.flatMap(l => l.paths || []);
  if (!items.length) throw new Error('no_vector');
  // סמלים: מעתיקים את אלה שבשימוש, בשמות חדשים; המופעים מפנים לשמות החדשים
  const all = doc.symbols && typeof doc.symbols === 'object' ? doc.symbols : {};
  const used = symbolRefs(items, all);
  const rename = new Map([...used].map(s => [s, fresh()]));
  const fixUse = list => (list || []).map(it => {
    if (it?.type === 'use' && rename.has(it.symbol)) return { ...it, symbol: rename.get(it.symbol) };
    if (Array.isArray(it?.children)) return { ...it, children: fixUse(it.children) };
    return it;
  });
  const symbols = {};
  for (const [oldId, newId] of rename) symbols[newId] = { ...all[oldId], paths: fixUse(reId(all[oldId].paths, fresh)) };
  // היחס נשמר: מתאימים את המשטח לתוך box, ומצמידים לימין ולמעלה (עברית)
  const [bx, by, bw, bh] = box;
  const s = Math.min(bw / sw, bh / sh);
  const w = sw * s, h = sh * s;
  const x = bx + bw - w, y = by;
  items = transformItems(fixUse(reId(items, fresh)), [s, 0, 0, s, x, y]);
  const images = new Set([...imageRefs(items), ...Object.values(symbols).flatMap(sy => [...imageRefs(sy.paths)])]);
  const gaps = gapsOf([...items, ...Object.values(symbols).flatMap(sy => sy.paths || [])]);
  return { layer: { id: fresh(), type: 'vector', name, box: [x, y, w, h].map(r4), paths: items }, symbols, images, gaps };
}

/** הזזה / שינוי גודל של שכבה מוצבת: כל הנתיבים עוברים מה-box הישן לחדש. */
export function moveDrawing(layer, box) {
  const [ox, oy, ow, oh] = layer.box, [nx, ny, nw, nh] = box;
  const sx = nw / ow, sy = nh / oh;
  return { ...layer, box: box.map(r4), paths: transformItems(layer.paths, [sx, 0, 0, sy, nx - ox * sx, ny - oy * sy]) };
}

export const isDrawing = l => l?.type === 'vector' && Array.isArray(l.box) && l.box.length === 4;

// ===== ציור על קנבס =====

const rgba = (hex, a = 1) => {
  const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
  if (!m) return null;
  const n = parseInt(m[1], 16);
  return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`;
};

function paint(ctx, spec) {
  if (!spec) return null;
  const op = spec.opacity ?? 1;
  const g = spec.gradient;
  if (g && Array.isArray(g.stops) && g.stops.length) {
    let grad = null;
    if (g.type === 'linear' && g.from && g.to) grad = ctx.createLinearGradient(g.from[0], g.from[1], g.to[0], g.to[1]);
    else if (g.type === 'radial' && g.center && g.radius > 0) grad = ctx.createRadialGradient(g.center[0], g.center[1], 0, g.center[0], g.center[1], g.radius);
    if (!grad) return rgba(g.stops[0].color, (g.stops[0].opacity ?? 1) * op);   // סוג לא מוכר — העצירה הראשונה
    for (const st of g.stops) grad.addColorStop(Math.min(1, Math.max(0, st.at ?? 0)), rgba(st.color, (st.opacity ?? 1) * op) || 'transparent');
    return grad;
  }
  return rgba(spec.color, op);
}

/**
 * ציור פריטים על ctx (שכבר במערכת הקואורדינטות של העמוד). env: { symbols, image(src) → תמונה טעונה או null }.
 */
export function drawItems(ctx, items, env, depth = 0) {
  if (depth > 20) return;   // סמל שמפנה לעצמו
  for (const it of items || []) {
    if (!it || typeof it !== 'object') continue;
    if (it.type === 'group') {
      const kids = it.children || [];
      if (it.clip && kids.length) {
        ctx.save();
        const c = kids[0];
        if (typeof c.d === 'string') clipPath(ctx, c, new Path2D(c.d));
        drawItems(ctx, kids.slice(1), env, depth + 1);
        ctx.restore();
      } else drawItems(ctx, kids, env, depth + 1);
      continue;
    }
    if (it.type === 'use') {
      const sy = env.symbols?.[it.symbol];
      if (!sy) continue;
      ctx.save(); ctx.transform(...(it.transform || ID));
      drawItems(ctx, sy.paths, env, depth + 1);
      ctx.restore();
      continue;
    }
    if (it.type === 'image') {
      const img = env.image?.(it.src);
      if (!img || !Array.isArray(it.box)) continue;
      ctx.save(); ctx.transform(...(it.transform || ID));
      ctx.globalAlpha *= it.opacity ?? 1;
      ctx.drawImage(img, ...it.box);
      ctx.restore();
      continue;
    }
    if (it.type && it.type !== 'path') continue;   // טקסט קצר, ואפקטים — עוד לא
    if (typeof it.d !== 'string') continue;
    ctx.save();
    ctx.transform(...(it.transform || ID));
    const path = new Path2D(it.d);
    const fill = paint(ctx, it.fill);
    if (fill) { ctx.fillStyle = fill; ctx.fill(path, it.fillRule === 'evenodd' ? 'evenodd' : 'nonzero'); }
    const st = it.stroke;
    const stroke = st && (st.width ?? 1) > 0 ? paint(ctx, st) : null;
    if (stroke) {
      ctx.strokeStyle = stroke;
      ctx.lineWidth = st.width ?? 1;
      ctx.lineCap = st.cap || 'butt';
      ctx.lineJoin = st.join || 'miter';
      ctx.miterLimit = st.miter ?? 4;
      ctx.setLineDash(Array.isArray(st.dash) ? st.dash : []);
      ctx.stroke(path);
    }
    ctx.restore();
  }
}

// חיתוך בנתיב שה-transform שלו כבר הוחל ב-Path2D (ctx עצמו נשאר במערכת העמוד)
function clipPath(ctx, c, p) {
  const m = new DOMMatrix(c.transform || ID);
  const q = new Path2D();
  q.addPath(p, m);
  ctx.clip(q, c.fillRule === 'evenodd' ? 'evenodd' : 'nonzero');
}
