// ייצוא: SVG ו-PDF וקטוריים (טקסט — כקווי מתאר), ודוח פערים לכל ייצוג. בלי DOM — רץ גם ב-node.
// PNG — בעורך (צריך קנבס), עם render.js.

import { parse } from './geometry/path.js';
import { isIdentity } from './geometry/matrix.js';
import { isGroup, isText, isImage } from './model.js';
import { textToD } from './outline.js';
import { pdfDraw } from './pdf-core/draw.js';
import { frameItem } from './text.js';
import { drawable, hasStroke } from './gradient.js';
import { markerPaths } from './markers.js';

const fxOf = (item) => (Array.isArray(item?.effects) ? item.effects : []).filter(e => e && ['shadow', 'glow', 'blur'].includes(e.type));
const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const r = (v) => String(Math.round(v * 1000) / 1000);

/**
 * מכין את הלוח לייצוא: טקסטים → נתיבים (עם אותו מילוי ומתאר), ורשימת פערים.
 * שכבות של אפליקציות אחרות: רסטר / מסגרת עם תמונה — getImage(src) → { href, w, h, rgb, alpha } (rgb ו-alpha
 * דחוסים ב-zlib, alpha = null כשהתמונה אטומה), או null; מסגרת טקסט — הטקסט שלה כקווי מתאר.
 * background — עמוד המקור של הלוח (מסמך של אפליקציה אחרת), כתמונה כמו של getImage: נכנס ראשון, ובמקום השכבות של
 * האפליקציה האחרת (הוא כבר מראה אותן כמו שהיא ציירה).
 * מחזיר { surface, layers: [{ layer, opacity, items } | { layer, opacity, image, box }], gaps }.
 */
/*
 * אפקטים (effects): ב-SVG — מסנן (filter) וקטורי; ב-PDF — getEffects(item) מצייר אותם לתמונה ({ image, box, replace }),
 * שנכנסת מתחת לפריט (replace — כשיש blur: התמונה כוללת את הפריט המטושטש, והפריט עצמו לא מצויר). בלי getEffects — בדוח.
 */
export async function prepare(doc, surfaceId, getFont, getImage = null, background = null, getEffects = null) {
  const surface = doc.surfaces.find(s => s.id === surfaceId) || doc.surfaces[0];
  const gaps = new Set();
  const conv = async (item) => {
    const out = await conv1(item);
    if (out && fxOf(item).length) {
      if (getEffects) {
        const fx = await getEffects(item);
        if (fx) { out.fxImage = fx; gaps.add('effects → תמונה (PDF)'); } else gaps.add('effects — לא נכללו ב-PDF');
      } else gaps.add('effects — לא נכללו ב-PDF');
    }
    return out;
  };
  const conv1 = async (item) => {
    if (isGroup(item)) return { ...item, children: await Promise.all((item.children || []).map(conv)) };
    for (const k of ['fill', 'stroke']) {
      const g = item[k]?.gradient;
      if (g && !drawable(g)) {
        gaps.add(`gradient:${g.type} → צבע אחיד`);
        const { gradient, ...rest } = item[k];
        item = { ...item, [k]: { ...rest, color: g.stops?.[0]?.color || '#000000' } };
      }
    }
    // קצוות המתאר (חץ / עיגול / פס) — צורות מלאות בצבע המתאר, בקבוצה עם הנתיב (SVG ו-PDF מציירים אותן כרגיל)
    const marks = item.d && hasStroke(item.stroke) ? markerPaths(item.d, item.stroke) : [];
    if (marks.length) {
      const { width, cap, join, miter, dash, start, end, ...paint } = item.stroke;
      const { effects, ...plain } = item;   // האפקטים — על הקבוצה (הנתיב והקצוות יחד)
      return { type: 'group', id: item.id + '-g', ...(effects ? { effects } : {}), children: [plain, ...marks.map((d, i) => ({ id: `${item.id}-m${i}`, d, fill: paint, ...(item.transform ? { transform: item.transform } : {}) }))] };
    }
    if (isImage(item)) {
      const img = getImage ? await getImage(item.src) : null;
      if (!img) gaps.add('image — התמונה חסרה, לא נכללה');
      return { ...item, img };
    }
    if (isText(item)) {
      gaps.add('text → outlines');
      return { id: item.id, d: await textToD(item, getFont), fill: item.fill, stroke: item.stroke, label: item.text, ...(item.transform ? { transform: item.transform } : {}) };
    }
    return item;
  };
  const layers = [];
  if (background) {
    layers.push({ layer: { id: 'source-page', name: 'עמוד המקור' }, opacity: 1, image: background, box: [0, 0, surface.size[0], surface.size[1]] });
    gaps.add('עמוד המקור → תמונה');
  }
  const walk = async (list, alpha) => {
    for (const l of list || []) {
      if (l.visible === false) continue;
      if (background && l.type !== 'vector' && l.type !== 'group') continue;
      const opacity = alpha * (l.opacity ?? 1);
      if (l.blend && l.blend !== 'normal') gaps.add(`blend-mode:${l.blend} → normal`);
      if (l.type === 'group') { await walk(l.children, opacity); continue; }
      if (l.type === 'vector') { layers.push({ layer: l, opacity, items: await Promise.all((l.paths || []).map(conv)) }); continue; }
      const box = Array.isArray(l.box) ? l.box : [0, 0, surface.size[0], surface.size[1]];
      if (l.src) {
        const image = getImage ? await getImage(l.src) : null;
        if (image) layers.push({ layer: l, opacity, image, box });
        else gaps.add(`layer:${l.type} — התמונה חסרה, לא נכללה`);
      } else if (l.type === 'frame' && l.flow && doc.flows?.[l.flow]) {
        layers.push({ layer: l, opacity, items: [await conv(frameItem(l, doc.flows[l.flow], box))] });
        gaps.add('frame → טקסט פשוט');
      } else gaps.add(`layer:${l.type} — לא נכלל`);
    }
  };
  await walk(surface.layers, 1);
  return { surface, layers, gaps: [...gaps] };
}

/** עמוד עודף של המקור (הטקסט גלש לעמוד נוסף) — עמוד שכולו התמונה, ל-PDF. */
export function pageOnly(image, w, h, n) {
  return {
    surface: { id: 'page' + n, name: 'עמוד ' + n, size: [w, h] },
    layers: [{ layer: { id: 'source-page-' + n, name: 'עמוד המקור' }, opacity: 1, image, box: [0, 0, w, h] }],
    gaps: ['עמוד המקור → תמונה'],
  };
}

// ---------- SVG ----------

/** מעבר צבע → <linearGradient> / <radialGradient> ב-defs (במרחב המשתמש — אותן קואורדינטות של הנתיב). מחזיר את המזהה. */
function svgGradient(g, defs) {
  const id = 'grad' + (defs.length + 1);
  const stops = (g.stops || []).map(st => `<stop offset="${r(st.at)}" stop-color="${esc(st.color)}"${(st.opacity ?? 1) < 1 ? ` stop-opacity="${r(st.opacity)}"` : ''}/>`).join('');
  defs.push(g.type === 'radial'
    ? `<radialGradient id="${id}" gradientUnits="userSpaceOnUse" cx="${r(g.center[0])}" cy="${r(g.center[1])}" r="${r(g.radius)}">${stops}</radialGradient>`
    : `<linearGradient id="${id}" gradientUnits="userSpaceOnUse" x1="${r(g.from[0])}" y1="${r(g.from[1])}" x2="${r(g.to[0])}" y2="${r(g.to[1])}">${stops}</linearGradient>`);
  return id;
}

function svgStyle(p, defs) {
  const a = [];
  if (p.fill?.gradient) {
    a.push(`fill="url(#${svgGradient(p.fill.gradient, defs)})"`);
    if ((p.fill.opacity ?? 1) < 1) a.push(`fill-opacity="${r(p.fill.opacity)}"`);
    if (p.fillRule === 'evenodd') a.push('fill-rule="evenodd"');
  } else if (p.fill?.color) {
    a.push(`fill="${esc(p.fill.color)}"`);
    if ((p.fill.opacity ?? 1) < 1) a.push(`fill-opacity="${r(p.fill.opacity)}"`);
    if (p.fillRule === 'evenodd') a.push('fill-rule="evenodd"');
  } else a.push('fill="none"');
  const s = p.stroke;
  if (hasStroke(s)) {
    a.push(`stroke="${s.gradient ? `url(#${svgGradient(s.gradient, defs)})` : esc(s.color)}"`, `stroke-width="${r(s.width ?? 1)}"`);
    if ((s.opacity ?? 1) < 1) a.push(`stroke-opacity="${r(s.opacity)}"`);
    if (s.cap && s.cap !== 'butt') a.push(`stroke-linecap="${esc(s.cap)}"`);
    if (s.join && s.join !== 'miter') a.push(`stroke-linejoin="${esc(s.join)}"`);
    if (s.dash?.length) a.push(`stroke-dasharray="${s.dash.map(r).join(' ')}"`);
  }
  return a.join(' ');
}

/** effects → <filter>: צללים וזוהר מתחת (מ-SourceAlpha), והפריט מעליהם (מטושטש, אם יש blur). σ = blur / 2. */
function svgFilter(effects, defs) {
  const fx = fxOf({ effects });
  if (!fx.length) return '';
  const id = 'fx' + (defs.length + 1);
  const parts = [], merge = [];
  fx.forEach((e, i) => {
    if (e.type === 'blur') return;
    const shadow = e.type === 'shadow';
    parts.push(`<feGaussianBlur in="SourceAlpha" stdDeviation="${r(Math.max(0, e.blur ?? 0) / 2)}" result="b${i}"/>`,
      `<feOffset in="b${i}" dx="${r(shadow ? e.dx ?? 0 : 0)}" dy="${r(shadow ? e.dy ?? 0 : 0)}" result="o${i}"/>`,
      `<feFlood flood-color="${esc(e.color || (shadow ? '#000000' : '#ffd34d'))}" flood-opacity="${r(e.opacity ?? (shadow ? 0.35 : 0.8))}" result="f${i}"/>`,
      `<feComposite in="f${i}" in2="o${i}" operator="in" result="s${i}"/>`);
    merge.push(`s${i}`);
  });
  const blur = fx.find(e => e.type === 'blur' && (e.radius ?? 0) > 0);
  if (blur) parts.push(`<feGaussianBlur in="SourceGraphic" stdDeviation="${r(blur.radius / 2)}" result="src"/>`);
  merge.push(blur ? 'src' : 'SourceGraphic');
  defs.push(`<filter id="${id}" x="-50%" y="-50%" width="200%" height="200%">${parts.join('')}<feMerge>${merge.map(m => `<feMergeNode in="${m}"/>`).join('')}</feMerge></filter>`);
  return ` filter="url(#${id})"`;
}

function svgItem(item, indent, defs) {
  if (item.hidden) return '';
  const filter = svgFilter(item.effects, defs);
  if (filter && !isGroup(item)) {
    // הטרנספורמציה של הפריט — בתוך הקבוצה, כדי שהצל יזוז במרחב של הלוח (כמו בקנבס) ולא יסתובב איתו
    const { effects, ...plain } = item;
    return `${indent}<g id="${esc(item.id)}-fx"${filter}>\n${svgItem(plain, indent + '  ', defs)}${indent}</g>\n`;
  }
  if (isGroup(item)) {
    return `${indent}<g id="${esc(item.id)}"${filter}>\n${(item.children || []).map(c => svgItem(c, indent + '  ', defs)).join('')}${indent}</g>\n`;
  }
  const t = item.transform && !isIdentity(item.transform) ? ` transform="matrix(${item.transform.map(r).join(' ')})"` : '';
  if (isImage(item)) {
    if (!item.img) return '';
    const [x, y, w, h] = item.box, op = (item.opacity ?? 1) < 1 ? ` opacity="${r(item.opacity)}"` : '';
    return `${indent}<image id="${esc(item.id)}" x="${r(x)}" y="${r(y)}" width="${r(w)}" height="${r(h)}" preserveAspectRatio="none" href="${esc(item.img.href)}" xlink:href="${esc(item.img.href)}"${op}${t}/>\n`;
  }
  if (!item.d) return '';
  const label = item.label ? ` aria-label="${esc(item.label)}"` : '';
  return `${indent}<path id="${esc(item.id)}" d="${esc(item.d)}" ${svgStyle(item, defs)}${t}${label}/>\n`;
}

/** SVG של לוח אחד, בגודל ב-pt (viewBox ביחידות pt). */
export function toSVG(prepared) {
  const { surface, layers } = prepared;
  const [w, h] = surface.size;
  const defs = [];
  let body = '';
  for (const { layer, opacity, items, image, box } of layers) {
    const op = opacity < 1 ? ` opacity="${r(opacity)}"` : '';
    const inner = image
      ? `    <image x="${r(box[0])}" y="${r(box[1])}" width="${r(box[2])}" height="${r(box[3])}" preserveAspectRatio="none" href="${esc(image.href)}" xlink:href="${esc(image.href)}"/>\n`
      : items.map(i => svgItem(i, '    ', defs)).join('');
    body += `  <g id="${esc(layer.id)}" data-name="${esc(layer.name || '')}"${op}>\n${inner}  </g>\n`;
  }
  const hasImg = (it) => isImage(it) || (isGroup(it) && (it.children || []).some(hasImg));
  const xlink = layers.some(l => l.image || l.items?.some(hasImg)) ? ' xmlns:xlink="http://www.w3.org/1999/xlink"' : '';
  const head = `<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg"${xlink} width="${r(w)}pt" height="${r(h)}pt" viewBox="0 0 ${r(w)} ${r(h)}">\n`;
  return head + (defs.length ? `  <defs>\n    ${defs.join('\n    ')}\n  </defs>\n` : '') + body + '</svg>\n';
}

// ---------- PDF ----------

/** d → פקודות מוחלטות בלבד (M L C Q Z), כמו ש-pdf-core מקבל. */
function absD(d) {
  return parse(d).map(sg => sg.c + (sg.p?.length ? sg.p.map(r).join(' ') : '')).join(' ');
}

/** מעבר צבע בפורמט של וקטורי → של pdf-core (x1/y1/x2/y2 או cx/cy/r; offset). */
function pdfGradient(g) {
  const stops = (g.stops || []).slice().sort((a, b) => a.at - b.at).map(st => ({ offset: st.at, color: st.color, ...((st.opacity ?? 1) < 1 ? { opacity: st.opacity } : {}) }));
  return g.type === 'radial'
    ? { type: 'radial', cx: g.center[0], cy: g.center[1], r: Math.max(g.radius, 0.001), stops }
    : { type: 'linear', x1: g.from[0], y1: g.from[1], x2: g.to[0], y2: g.to[1], stops };
}

/**
 * רשימת ציור ל-pdf-core (creative-suite/pdf-core): עמוד לכל לוח. התמונות — מפתח (img/<n>.png) ובתים (PNG מתוך href).
 * מחזיר { list, files: Map(key → Uint8Array) }.
 */
export function toPdfList(pages) {
  const files = new Map(), keys = new Map();
  const keyOf = (im) => {
    if (!keys.has(im)) {
      const key = `img/${keys.size + 1}.png`;
      keys.set(im, key);
      files.set(key, dataBytes(im.href));
    }
    return keys.get(im);
  };
  const out = [];
  for (const prep of pages) {
    const ops = [];
    const image = ({ image: im, box, opacity, transform }) => {
      if (!im?.href) return;
      ops.push({ op: 'image', src: keyOf(im), box, ...(transform && !isIdentity(transform) ? { transform } : {}), ...(opacity < 1 ? { opacity } : {}) });
    };
    const emit = (item, alpha) => {
      if (item.hidden) return;
      if (item.fxImage) {   // האפקטים כתמונה, מתחת לפריט
        image({ image: item.fxImage.image, box: item.fxImage.box, opacity: alpha });
        if (item.fxImage.replace) return;   // blur: הפריט המטושטש כבר בתמונה
      }
      if (isGroup(item)) { for (const c of item.children || []) emit(c, alpha); return; }
      if (isImage(item)) {
        if (item.img) image({ image: item.img, box: item.box, opacity: alpha * (item.opacity ?? 1), transform: item.transform });
        return;
      }
      if (!item.d) return;
      const fill = item.fill?.color || item.fill?.gradient ? item.fill : null;
      const st = hasStroke(item.stroke) ? item.stroke : null;
      if (!fill && !st) return;
      const op = { op: 'path', d: absD(item.d) };
      if (item.transform && !isIdentity(item.transform)) op.transform = item.transform;
      if (fill) {
        op.fill = fill.gradient ? { gradient: pdfGradient(fill.gradient) } : fill.color;
        const fo = alpha * (fill.opacity ?? 1);
        if (fo < 1) op.fillOpacity = fo;
        if (item.fillRule === 'evenodd') op.fillRule = 'evenodd';
      }
      if (st) {
        op.stroke = st.gradient ? { gradient: pdfGradient(st.gradient) } : st.color;
        const so = alpha * (st.opacity ?? 1);
        if (so < 1) op.strokeOpacity = so;
        op.width = st.width ?? 1;
        if (st.cap && st.cap !== 'butt') op.cap = st.cap;
        if (st.join && st.join !== 'miter') op.join = st.join;
        if (st.miter != null && st.miter !== 4) op.miter = st.miter;
        if (st.dash?.length) op.dash = st.dash;
      }
      ops.push(op);
    };
    for (const entry of prep.layers) {
      if (entry.image) image(entry);
      else for (const it of entry.items) emit(it, entry.opacity ?? entry.layer.opacity ?? 1);
    }
    out.push({ size: prep.surface.size, ops });
  }
  return { list: { pages: out }, files };
}

/**
 * PDF וקטורי דרך pdf-core (JS, בלי שרת): עמוד לכל לוח שהועבר. הטקסט כבר נתיבים (prepare), כך שאין גופנים.
 * pages: [prepared]. מחזיר Promise<Uint8Array>.
 */
export async function toPDF(pages) {
  const { list, files } = toPdfList(pages);
  return pdfDraw(list, (key) => files.get(key) || null);
}

/** data:…;base64,… → בתים. */
function dataBytes(href) {
  const m = /^data:[^,]*;base64,(.*)$/.exec(href || '');
  if (!m) return new Uint8Array(0);
  const bin = atob(m[1]);
  const b = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i);
  return b;
}
