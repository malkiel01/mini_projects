// מבני הקבצים — חשבון טהור, נבדק ב-node:
// - stack.xml של OpenRaster (‏ORA — שכבות ל-GIMP / Krita)
// - document.json של ‎.mbe (mbe-format.md סעיף 3) — מוכן לליבה; השמירה עצמה רק דרך creative-suite/mbe-core
// - דוחות הפערים (gaps) של כל ייצוג

const COMPOSITE_OP = { normal: 'svg:src-over', multiply: 'svg:multiply', screen: 'svg:screen', overlay: 'svg:overlay' };
const OP_TO_BLEND = Object.fromEntries(Object.entries(COMPOSITE_OP).map(([k, v]) => [v, k]));

const xmlEsc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]));

/**
 * stack.xml: layers — עץ מלמטה למעלה, כמו במסמך; שכבה עם src (נתיב ב-ZIP), קבוצה עם children.
 * ב-ORA הסדר הפוך — העליונה ראשונה; קבוצה = <stack> מקונן (מבודד — כמו אצלנו).
 */
export function oraStack(w, h, layers) {
  const attrs = l => `name="${xmlEsc(l.name)}" opacity="${round(l.opacity)}" visibility="${l.visible ? 'visible' : 'hidden'}"` +
    ` composite-op="${COMPOSITE_OP[l.blend] || 'svg:src-over'}"${l.locked ? ' edit-locked="true"' : ''}`;
  const rows = (list, ind) => [...list].reverse().map(l => l.type === 'group'
    ? `${ind}<stack ${attrs(l)} isolation="isolate">\n${rows(l.children, ind + ' ')}${l.children.length ? '\n' : ''}${ind}</stack>`
    : `${ind}<layer ${attrs(l)} src="${xmlEsc(l.src)}" x="0" y="0"/>`).join('\n');
  return `<?xml version="1.0" encoding="UTF-8"?>\n<image version="0.0.5" w="${w}" h="${h}">\n <stack>\n${rows(layers, '  ')}\n </stack>\n</image>\n`;
}

const round = v => Math.round(v * 1000) / 1000;

/**
 * קריאת stack.xml (לפתיחת ORA) → { w, h, layers } — עץ מלמטה למעלה: שכבה { name, src, x, y, opacity, visible, blend,
 * locked }, קבוצה { type: 'group', name, opacity, visible, blend, locked, children }.
 */
export function parseOraStack(xml) {
  const attr = (tag, name) => {
    const m = new RegExp(`\\s${name}="([^"]*)"`).exec(tag);
    return m ? m[1].replace(/&(amp|lt|gt|quot|apos);/g, (_, e) => ({ amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" }[e])) : null;
  };
  const common = t => ({
    name: attr(t, 'name') ?? 'שכבה',
    opacity: Number(attr(t, 'opacity') ?? 1),
    visible: attr(t, 'visibility') !== 'hidden',
    blend: OP_TO_BLEND[attr(t, 'composite-op')] ?? 'normal',
    locked: attr(t, 'edit-locked') === 'true',
  });
  const img = /<image\b[^>]*>/.exec(xml)?.[0] ?? '';
  // עוברים על התגים לפי הסדר: <stack> פותח, </stack> סוגר, <layer/> — שכבה. הראשון הוא השורש.
  const root = { children: [] };
  const stack = [];
  for (const [t] of xml.matchAll(/<\/?stack\b[^>]*>|<layer\b[^>]*\/?>/g)) {
    if (t.startsWith('</')) { stack.pop(); continue; }
    const parent = stack.at(-1) ?? root;
    if (t.startsWith('<stack')) {
      const g = stack.length ? { type: 'group', ...common(t), children: [] } : root;
      if (stack.length) parent.children.push(g);
      if (!t.endsWith('/>')) stack.push(g);
    } else {
      const src = attr(t, 'src');
      if (src) parent.children.push({ ...common(t), src, x: Number(attr(t, 'x') ?? 0), y: Number(attr(t, 'y') ?? 0) });
    }
  }
  const flip = list => list.reverse().map(l => (l.type === 'group' ? { ...l, children: flip(l.children) } : l));
  return { w: Number(attr(img, 'w')), h: Number(attr(img, 'h')), layers: flip(root.children) };
}

/**
 * document.json של ‎.mbe מסוג raster: משטח canvas אחד, size בפיקסלים (units: pt, ובקנבס רסטר 1pt = 1px).
 * layers — עץ המסמך (מלמטה למעלה); לשכבת רסטר src = "media/<sha256>.png" (בגודל הקנבס) ב-srcOf(layer).
 * base — המסמך שנפתח: { document, surfaceId }. כל מה שלא הבנו נשמר — שדות לא מוכרים במסמך, במשטח ובכל שכבה
 * (layer.mbe — ה-JSON המקורי), משטחים אחרים במקומם, ושכבות של אפליקציות אחרות (layer.foreign) כמו שהן.
 */
export function mbeDocument(doc, layers, base = null, srcOf = l => l.src, recipeOf = () => null) {
  const old = base?.document ?? {};
  const surfaces = Array.isArray(old.surfaces) ? old.surfaces : [];
  const i = surfaces.findIndex(s => s && s.id === base?.surfaceId);
  // עמוד (page) של אפליקציה אחרת נשאר עמוד, ביחידות שלו: scale — פיקסלים ליחידה (קנבס — 1)
  const scale = base?.scale ?? 1;
  const unit = v => (scale === 1 ? v : Math.round(v / scale * 100) / 100);
  const surface = {
    ...(i >= 0 ? surfaces[i] : {}), id: i >= 0 ? surfaces[i].id : (base?.surfaceId ?? 's1'), type: i >= 0 ? surfaces[i].type : 'canvas',
    size: [unit(doc.width), unit(doc.height)], layers: mbeLayers(layers, srcOf, scale === 1 ? null : [doc.width, doc.height], recipeOf),
  };
  const out = { ...old, units: old.units ?? 'pt', color: old.color ?? { space: 'sRGB' } };
  out.surfaces = i >= 0 ? surfaces.map((s, j) => (j === i ? surface : s)) : [surface, ...surfaces];
  return out;
}

/**
 * שכבת התאמה (adjust בפורמט, mbe-format.md סעיף 3): הסוגים שאנחנו מציירים — type בפורמט ↔ סוג התיקון בלוח.
 * הפרמטרים בשמות של המחוונים: brightness / contrast (‎-100..100), hue (מעלות) / saturation / lightness,
 * black / white (0–255) / gamma, levels (2–255), level (0–255).
 */
export const ADJUST_TYPES = {
  'brightness-contrast': 'bc', 'hue-saturation': 'hsl', levels: 'levels', invert: 'invert', posterize: 'posterize', threshold: 'threshold',
};
export const ADJUST_FIELDS = {
  'brightness-contrast': ['brightness', 'contrast'], 'hue-saturation': ['hue', 'saturation', 'lightness'],
  levels: ['black', 'white', 'gamma'], invert: [], posterize: ['levels'], threshold: ['level'],
};
/** התיקון של שכבת התאמה, כמו שהמנוע מקבל אותו ({ kind, …פרמטרים }) */
export const adjustOf = l => ({ ...l.adjust, kind: ADJUST_TYPES[l.adjust.type] });

const ADJUST_DEFAULTS = { brightness: 0, contrast: 0, hue: 0, saturation: 0, lightness: 0, black: 0, white: 255, gamma: 1, levels: 4, level: 128 };
/** adjust מקובץ: סוג מוכר — הפרמטרים שלו כמספרים (חסר / לא מספר → ברירת מחדל), ושאר השדות כמו שהם. לא מוכר — null */
export function cleanAdjust(a) {
  if (!a || typeof a !== 'object' || !ADJUST_FIELDS[a.type]) return null;
  const out = { ...a };
  for (const f of ADJUST_FIELDS[a.type]) out[f] = Number.isFinite(a[f]) ? a[f] : ADJUST_DEFAULTS[f];
  return out;
}

/** שדות המתכון שמסכת השכבה שלנו כותבת (mbe-format.md סעיף 3, "עריכה לא הורסת") */
export const MASK_RECIPE = ['source', 'mask', 'baked'];

const mbeLayers = (list, srcOf, px, recipeOf) => list.map(l => {
  // שכבה של אפליקציה אחרת (vector, frame…) — כמו שהגיעה; רק ההסתרה (מאפיין של המעטפת) אצלנו
  if (l.foreign) return l.visible === (l.foreign.visible ?? true) ? l.foreign : { ...l.foreign, visible: !!l.visible };
  const common = { ...(l.mbe ?? {}), id: l.id, name: l.name, type: l.type === 'group' ? 'group' : 'raster' };
  // ה-PNG שלנו בגודל המשטח כולו: box (מיקום של תמונה קטנה) כבר אפוי לתוכו. בעמוד — px: גודל ה-PNG בפיקסלים
  if (l.type !== 'group') { delete common.box; delete common.px; if (px) common.px = px; }
  // מסכה שלנו (או שנפתחה ונמחקה / הוחלה מאז) — המתכון נכתב מחדש; מתכון של אחרים שלא טענו — נשאר כמו שהוא
  if (l.mask || l.maskLoaded || l.adjust || l.adjustLoaded) for (const k of [...MASK_RECIPE, 'adjust']) delete common[k];
  const body = l.type === 'group' ? { children: mbeLayers(l.children, srcOf, px, recipeOf) } : { src: srcOf(l), ...(recipeOf(l) ?? {}) };
  return { ...common, ...body, opacity: round(l.opacity), blend: l.blend, visible: !!l.visible, locked: !!l.locked };
});

/** כל ההפניות למשאבים (מפתחות src / embedded, בכל עומק) — כמו שהליבה בודקת */
export function mediaRefs(obj, out = new Set()) {
  if (Array.isArray(obj)) for (const v of obj) mediaRefs(v, out);
  else if (obj && typeof obj === 'object') {
    for (const [k, v] of Object.entries(obj)) {
      if ((k === 'src' || k === 'embedded') && typeof v === 'string') out.add(v);
      else mediaRefs(v, out);
    }
  }
  return out;
}

/** המשטח שנפתח בעורך: הקנבס הראשון. בלי קנבס — null (קובץ של אפליקציה אחרת) */
export function canvasSurface(document) {
  const s = (document?.surfaces ?? []).find(x => x && x.type === 'canvas' && Array.isArray(x.size));
  return s ?? null;
}

/** pt לפיקסל בעמוד (הכרעת המתכלל עם סטודיו העיצוב: 1px = 0.75pt, כלומר 96dpi) */
export const PT_PER_PX = 0.75;

/**
 * המשטח שהעורך פותח, וכמה פיקסלים ליחידה שלו: קנבס — 1 (בקנבס רסטר 1pt = 1px). אין קנבס — המשטח הראשון שיש בו
 * שכבת רסטר (למשל עמוד של סטודיו העיצוב, ב-pt), ברזולוציה של שכבת הרסטר הכי חדה בו (px / box), ובלי px — 96dpi.
 * משטח בלי שום שכבת רסטר (מסמך טקסט) — null: אין כאן מה לערוך.
 */
export function editSurface(document) {
  const c = canvasSurface(document);
  if (c) return { surface: c, scale: 1 };
  for (const s of document?.surfaces ?? []) {
    if (!s || !Array.isArray(s.size) || s.size.length !== 2 || !s.size.every(v => v > 0)) continue;
    let scale = 0, rasters = 0;
    const walk = list => {
      for (const l of Array.isArray(list) ? list : []) {
        if (!l || typeof l !== 'object') continue;
        if (Array.isArray(l.children)) walk(l.children);
        if (l.type !== 'raster') continue;
        rasters++;
        if (Array.isArray(l.px) && l.px[0] > 0) {
          const w = Array.isArray(l.box) ? l.box[2] : s.size[0];
          if (w > 0) scale = Math.max(scale, l.px[0] / w);
        }
      }
    };
    walk(s.layers);
    if (!rasters) continue;
    if (!scale) scale = document.units === 'pt' || document.units === undefined ? 1 / PT_PER_PX : 1;
    return { surface: s, scale };
  }
  return null;
}

/** מה לא עובר לכל ייצוג — כנות בייצוא (mbe-format.md עיקרון 3) */
export function gapsFor(rendition, tree) {
  const g = [];
  const all = [], walk = l => l.forEach(x => { all.push(x); if (x.type === 'group') walk(x.children); });
  walk(tree);
  const foreign = all.filter(l => l.foreign);
  const layers = all.filter(l => l.type !== 'group' && !l.foreign);
  const groups = all.length > layers.length + foreign.length;
  // שכבה של אפליקציה אחרת לא מצוירת אצלנו — לא נכנסת לשום ייצוג (ב-‎.mbe עצמו היא נשמרת כמו שהיא)
  for (const t of new Set(foreign.map(l => l.foreign.type))) g.push(`layer:${t} → omitted`);
  if (rendition === 'ora' && layers.some(l => l.mask?.enabled)) g.push('layer-mask → baked');
  if (rendition === 'ora' && layers.some(l => l.adjust)) g.push('adjust → baked');
  if (rendition === 'ora') {
    // ORA (GIMP / Krita) ממזג ב-sRGB; אנחנו — בלינארי. מראה שכבות חצי-שקופות ומצבי מיזוג יכול להשתנות מעט
    if (all.some(l => l.blend !== 'normal' || l.opacity < 1)) g.push('blend-space:linear → srgb');
  }
  if (rendition === 'png' || rendition === 'jpg') {
    if (layers.length > 1 || groups) g.push('layers → flattened');
    if (all.some(l => !l.visible)) g.push('hidden-layers → omitted');
  }
  if (rendition === 'jpg') g.push('alpha → white');
  return g;
}
