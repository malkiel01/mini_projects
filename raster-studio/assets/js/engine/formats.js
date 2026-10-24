// מבני הקבצים — חשבון טהור, נבדק ב-node:
// - stack.xml של OpenRaster (‏ORA — שכבות ל-GIMP / Krita)
// - document.json של ‎.mbe (mbe-format.md סעיף 3) — מוכן לליבה; השמירה עצמה רק דרך creative-suite/mbe-core
// - דוחות הפערים (gaps) של כל ייצוג

const COMPOSITE_OP = { normal: 'svg:src-over', multiply: 'svg:multiply', screen: 'svg:screen', overlay: 'svg:overlay' };
const OP_TO_BLEND = Object.fromEntries(Object.entries(COMPOSITE_OP).map(([k, v]) => [v, k]));

const xmlEsc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]));

/**
 * stack.xml: layers — מלמטה למעלה, כמו במסמך; כל אחד עם src (נתיב ב-ZIP). ב-ORA הסדר הפוך — העליונה ראשונה.
 */
export function oraStack(w, h, layers) {
  const rows = [...layers].reverse().map(l =>
    `  <layer name="${xmlEsc(l.name)}" src="${xmlEsc(l.src)}" x="0" y="0" opacity="${round(l.opacity)}"` +
    ` visibility="${l.visible ? 'visible' : 'hidden'}" composite-op="${COMPOSITE_OP[l.blend] || 'svg:src-over'}"` +
    `${l.locked ? ' edit-locked="true"' : ''}/>`);
  return `<?xml version="1.0" encoding="UTF-8"?>\n<image version="0.0.5" w="${w}" h="${h}">\n <stack>\n${rows.join('\n')}\n </stack>\n</image>\n`;
}

const round = v => Math.round(v * 1000) / 1000;

/** קריאת stack.xml (לפתיחת ORA) → { w, h, layers: [{ name, src, x, y, opacity, visible, blend, locked }] } מלמטה למעלה */
export function parseOraStack(xml) {
  const attr = (tag, name) => {
    const m = new RegExp(`\\s${name}="([^"]*)"`).exec(tag);
    return m ? m[1].replace(/&(amp|lt|gt|quot|apos);/g, (_, e) => ({ amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" }[e])) : null;
  };
  const img = /<image\b[^>]*>/.exec(xml)?.[0] ?? '';
  const layers = [...xml.matchAll(/<layer\b[^>]*\/?>/g)].map(([t]) => ({
    name: attr(t, 'name') ?? 'שכבה', src: attr(t, 'src'),
    x: Number(attr(t, 'x') ?? 0), y: Number(attr(t, 'y') ?? 0),
    opacity: Number(attr(t, 'opacity') ?? 1),
    visible: attr(t, 'visibility') !== 'hidden',
    blend: OP_TO_BLEND[attr(t, 'composite-op')] ?? 'normal',
    locked: attr(t, 'edit-locked') === 'true',
  })).filter(l => l.src).reverse();
  return { w: Number(attr(img, 'w')), h: Number(attr(img, 'h')), layers };
}

/**
 * document.json של ‎.mbe מסוג raster: משטח canvas אחד, size בפיקסלים (units: pt, ובקנבס רסטר 1pt = 1px).
 * layers — מלמטה למעלה, עם src = "media/<sha256>.png" (בגודל הקנבס). base — מסמך שנפתח (לשמור את מה שלא הבנו).
 */
export function mbeDocument(doc, layers, base = null) {
  const surface = {
    id: base?.surfaceId ?? 's1', type: 'canvas', size: [doc.width, doc.height],
    layers: layers.map(l => ({
      id: l.id, name: l.name, type: 'raster', src: l.src,
      opacity: round(l.opacity), blend: l.blend, visible: !!l.visible, locked: !!l.locked,
    })),
  };
  const out = { ...(base?.document ?? {}), units: 'pt', color: { space: 'sRGB' } };
  const others = (base?.document?.surfaces ?? []).filter(s => s.id !== surface.id);
  out.surfaces = [surface, ...others];
  return out;
}

/** מה לא עובר לכל ייצוג — כנות בייצוא (mbe-format.md עיקרון 3) */
export function gapsFor(rendition, layers) {
  const g = [];
  if (rendition === 'ora') {
    // ORA (GIMP / Krita) ממזג ב-sRGB; אנחנו — בלינארי. מראה שכבות חצי-שקופות ומצבי מיזוג יכול להשתנות מעט
    if (layers.some(l => l.blend !== 'normal' || l.opacity < 1)) g.push('blend-space:linear → srgb');
  }
  if (rendition === 'png' || rendition === 'jpg') {
    if (layers.length > 1) g.push('layers → flattened');
    if (layers.some(l => !l.visible)) g.push('hidden-layers → omitted');
  }
  if (rendition === 'jpg') g.push('alpha → white');
  return g;
}
