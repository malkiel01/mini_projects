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
 * layers — מלמטה למעלה, עם src = "media/<sha256>.png" (בגודל הקנבס). base — מסמך שנפתח (לשמור את מה שלא הבנו).
 */
export function mbeDocument(doc, layers, base = null) {
  const surface = {
    id: base?.surfaceId ?? 's1', type: 'canvas', size: [doc.width, doc.height],
    layers: mbeLayers(layers),
  };
  const out = { ...(base?.document ?? {}), units: 'pt', color: { space: 'sRGB' } };
  const others = (base?.document?.surfaces ?? []).filter(s => s.id !== surface.id);
  out.surfaces = [surface, ...others];
  return out;
}

const mbeLayers = list => list.map(l => ({
  id: l.id, name: l.name, type: l.type === 'group' ? 'group' : 'raster',
  ...(l.type === 'group' ? { children: mbeLayers(l.children) } : { src: l.src }),
  opacity: round(l.opacity), blend: l.blend, visible: !!l.visible, locked: !!l.locked,
}));

/** מה לא עובר לכל ייצוג — כנות בייצוא (mbe-format.md עיקרון 3) */
export function gapsFor(rendition, tree) {
  const g = [];
  const all = [], walk = l => l.forEach(x => { all.push(x); if (x.type === 'group') walk(x.children); });
  walk(tree);
  const layers = all.filter(l => l.type !== 'group');
  const groups = all.length > layers.length;
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
