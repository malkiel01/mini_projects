// ייצוא: SVG ו-PDF וקטוריים (טקסט — כקווי מתאר), ודוח פערים לכל ייצוג. בלי DOM — רץ גם ב-node.
// PNG — בעורך (צריך קנבס), עם render.js.

import { parse, bbox } from './geometry/path.js';
import { isIdentity } from './geometry/matrix.js';
import { isGroup, isText, isImage } from './model.js';
import { textToD } from './outline.js';
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

function rgb(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
  const n = m ? parseInt(m[1], 16) : 0;
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(v => r(v / 255)).join(' ');
}

function pdfPath(d) {
  const out = [];
  let cx = 0, cy = 0, sx = 0, sy = 0;
  for (const s of parse(d)) {
    const p = s.p;
    if (s.c === 'M') { out.push(`${r(p[0])} ${r(p[1])} m`); cx = sx = p[0]; cy = sy = p[1]; }
    else if (s.c === 'L') { out.push(`${r(p[0])} ${r(p[1])} l`); cx = p[0]; cy = p[1]; }
    else if (s.c === 'C') { out.push(`${p.map(r).join(' ')} c`); cx = p[4]; cy = p[5]; }
    else if (s.c === 'Q') {
      const c1 = [cx + 2 / 3 * (p[0] - cx), cy + 2 / 3 * (p[1] - cy)], c2 = [p[2] + 2 / 3 * (p[0] - p[2]), p[3] + 2 / 3 * (p[1] - p[3])];
      out.push(`${[...c1, ...c2, p[2], p[3]].map(r).join(' ')} c`); cx = p[2]; cy = p[3];
    } else if (s.c === 'Z') { out.push('h'); cx = sx; cy = sy; }
  }
  return out.join('\n');
}

/**
 * PDF וקטורי: עמוד לכל לוח שהועבר. בלי גופנים (הטקסט כבר נתיבים) ובלי דחיסה של התוכן — רק התמונות דחוסות.
 * pages: [prepared]. מחזיר Uint8Array.
 */
export function toPDF(pages) {
  const objs = [];      // תוכן כל אובייקט, לפי מספר (1-based)
  const add = (s) => { objs.push(s); return objs.length; };
  const catalog = add(null), pagesObj = add(null);
  const kids = [];

  for (const prep of pages) {
    const [W, H] = prep.surface.size;
    const gs = new Map(); // "ca/CA" → שם
    const gsName = (ca, CA) => {
      const k = `${r(ca)}/${r(CA)}`;
      if (!gs.has(k)) gs.set(k, { name: 'GS' + (gs.size + 1), ca, CA });
      return gs.get(k).name;
    };
    const ops = [`1 0 0 -1 0 ${r(H)} cm`]; // ראשית למעלה-שמאל, כמו בלוח
    const flip = [1, 0, 0, -1, 0, H];
    const patterns = [], xobjs = [];
    /** הפונקציה של מעבר הצבע: values(stop) → מערך רכיבים (צבע — 3, אטימות — 1). */
    const gradFn = (stops, values) => {
      const c = (st) => `[${values(st)}]`;
      if (stops.length <= 2) return `<< /FunctionType 2 /Domain [0 1] /C0 ${c(stops[0])} /C1 ${c(stops[stops.length - 1])} /N 1 >>`;
      const parts = stops.slice(1).map((st, i) => `<< /FunctionType 2 /Domain [0 1] /C0 ${c(stops[i])} /C1 ${c(st)} /N 1 >>`);
      return `<< /FunctionType 3 /Domain [0 1] /Functions [${parts.join(' ')}] /Bounds [${stops.slice(1, -1).map(st => r(st.at)).join(' ')}] /Encode [${parts.map(() => '0 1').join(' ')}] >>`;
    };
    /** Shading צירי (ישר) או רדיאלי (עגול), בקואורדינטות של הנתיב. */
    const shading = (g, cs, values) => {
      const stops = (g.stops || []).slice().sort((x, y) => x.at - y.at);
      const coords = g.type === 'radial' ? [g.center[0], g.center[1], 0, g.center[0], g.center[1], Math.max(g.radius, 0.001)] : [...g.from, ...g.to];
      return `<< /ShadingType ${g.type === 'radial' ? 3 : 2} /ColorSpace /${cs} /Coords [${coords.map(r).join(' ')}] /Function ${gradFn(stops, values)} /Extend [true true] >>`;
    };
    /** מעבר צבע → Pattern. המטריצה ממפה למרחב ברירת המחדל של העמוד (לא ל-CTM הנוכחי). */
    const patternFor = (g, m) => {
      const name = 'P' + (patterns.length + 1);
      patterns.push(`/${name} ${add(`<< /PatternType 2 /Matrix [${m.map(r).join(' ')}] /Shading ${shading(g, 'DeviceRGB', st => rgb(st.color))} >>`)} 0 R`);
      return name;
    };
    /** שקיפות בתוך המעבר: מסכה רכה (Luminosity) — אותו מעבר באפור, ערך האטימות של כל עצירה. */
    const maskFor = (g, d, ca) => {
      const b = bbox(parse(d)) || [0, 0, 1, 1];
      const sh = shading(g, 'DeviceGray', st => r(st.opacity ?? 1));
      const body = '/Sh1 sh';
      const form = add(`<< /Type /XObject /Subtype /Form /BBox [${[b[0] - 1, b[1] - 1, b[0] + b[2] + 1, b[1] + b[3] + 1].map(r).join(' ')}] /Group << /S /Transparency /CS /DeviceGray >> /Resources << /Shading << /Sh1 ${sh} >> >> /Length ${body.length} >>\nstream\n${body}\nendstream`);
      const name = 'GM' + (masks.length + 1);
      masks.push(`/${name} << /Type /ExtGState /ca ${r(ca)} /SMask << /Type /Mask /S /Luminosity /G ${form} 0 R >> >>`);
      return name;
    };
    const masks = [];
    const emit = (item, alpha) => {
      if (item.hidden) return;
      if (item.fxImage) {   // האפקטים כתמונה, מתחת לפריט
        const fx = item.fxImage;
        image({ image: fx.image, box: fx.box, opacity: alpha });
        if (fx.replace) return;   // blur: הפריט המטושטש כבר בתמונה
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
      const g = fill?.gradient;
      const t = item.transform && !isIdentity(item.transform) ? item.transform : null;
      const path = pdfPath(item.d), eo = item.fillRule === 'evenodd' ? '*' : '';
      const ca = alpha * (fill?.opacity ?? 1), CA = alpha * (st?.opacity ?? 1);
      // מעבר עם שקיפות בעצירות — המילוי לבד, עם המסכה; המתאר אחריו בלי מסכה
      const masked = g && (g.stops || []).some(x => (x.opacity ?? 1) < 1);
      ops.push('q');
      if (t) ops.push(`${t.map(r).join(' ')} cm`);
      if (masked) {
        ops.push('q', `/${maskFor(g, item.d, ca)} gs`, `/Pattern cs /${patternFor(g, mul(flip, t || [1, 0, 0, 1, 0, 0]))} scn`, path, 'f' + eo, 'Q');
        if (!st) { ops.push('Q'); return; }
      }
      const doFill = fill && !masked;
      if ((doFill && ca < 1) || (st && CA < 1)) ops.push(`/${gsName(doFill ? ca : 1, CA)} gs`);
      if (doFill && g) ops.push(`/Pattern cs /${patternFor(g, mul(flip, t || [1, 0, 0, 1, 0, 0]))} scn`);
      else if (doFill) ops.push(`${rgb(fill.color)} rg`);
      if (st) {
        if (st.gradient) ops.push(`/Pattern CS /${patternFor(st.gradient, mul(flip, t || [1, 0, 0, 1, 0, 0]))} SCN`);
        else ops.push(`${rgb(st.color)} RG`);
        ops.push(`${r(st.width ?? 1)} w`);
        ops.push(`${{ butt: 0, round: 1, square: 2 }[st.cap] ?? 0} J`, `${{ miter: 0, round: 1, bevel: 2 }[st.join] ?? 0} j`);
        ops.push(`${r(st.miter ?? 4)} M`);
        if (st.dash?.length) ops.push(`[${st.dash.map(r).join(' ')}] 0 d`);
      }
      ops.push(path);
      ops.push(doFill && st ? 'B' + eo : doFill ? 'f' + eo : 'S');
      ops.push('Q');
    };
    /** תמונה (רסטר של אפליקציה אחרת): XObject עם מסכה רכה לשקיפות. בלוח y למטה — לכן ההיפוך במטריצה. */
    const image = ({ image: im, box, opacity, transform }) => {
      const smask = im.alpha ? add(`<< /Type /XObject /Subtype /Image /Width ${im.w} /Height ${im.h} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ${im.alpha.length} >>\nstream\n${bin(im.alpha)}\nendstream`) : 0;
      const ref = add(`<< /Type /XObject /Subtype /Image /Width ${im.w} /Height ${im.h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode${smask ? ` /SMask ${smask} 0 R` : ''} /Length ${im.rgb.length} >>\nstream\n${bin(im.rgb)}\nendstream`);
      const name = 'Im' + (xobjs.length + 1);
      xobjs.push(`/${name} ${ref} 0 R`);
      ops.push('q');
      if (transform && !isIdentity(transform)) ops.push(`${transform.map(r).join(' ')} cm`);
      if (opacity < 1) ops.push(`/${gsName(opacity, opacity)} gs`);
      ops.push(`${r(box[2])} 0 0 ${r(-box[3])} ${r(box[0])} ${r(box[1] + box[3])} cm`, `/${name} Do`, 'Q');
    };
    for (const entry of prep.layers) {
      if (entry.image) image(entry);
      else for (const it of entry.items) emit(it, entry.opacity ?? entry.layer.opacity ?? 1);
    }

    const stream = ops.join('\n');
    const content = add(`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`);
    const ext = [...[...gs.values()].map(g => `/${g.name} << /Type /ExtGState /ca ${r(g.ca)} /CA ${r(g.CA)} >>`), ...masks].join(' ');
    kids.push(add(`<< /Type /Page /Parent ${pagesObj} 0 R /MediaBox [0 0 ${r(W)} ${r(H)}] /Contents ${content} 0 R /Resources << /ExtGState << ${ext} >> /Pattern << ${patterns.join(' ')} >> /XObject << ${xobjs.join(' ')} >> >> >>`));
  }
  objs[catalog - 1] = `<< /Type /Catalog /Pages ${pagesObj} 0 R >>`;
  objs[pagesObj - 1] = `<< /Type /Pages /Kids [${kids.map(k => k + ' 0 R').join(' ')}] /Count ${kids.length} >>`;

  // תווים עד 0xFF בלבד (זרמי תמונה — בתים כמו שהם) — אורך המחרוזת הוא מספר הבתים
  let out = '%PDF-1.4\n%\xE2\xE3\xCF\xD3\n';
  const offsets = [];
  objs.forEach((o, i) => { offsets.push(byteLen(out)); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const xref = byteLen(out);
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offsets.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root ${catalog} 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return latin1(out);
}

const mul = (a, b) => [a[0] * b[0] + a[2] * b[1], a[1] * b[0] + a[3] * b[1], a[0] * b[2] + a[2] * b[3], a[1] * b[2] + a[3] * b[3], a[0] * b[4] + a[2] * b[5] + a[4], a[1] * b[4] + a[3] * b[5] + a[5]];
/** בתים → מחרוזת latin1 (תו לכל בית), כדי שיישבו בתוך הקובץ כמו שהם. */
function bin(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  return s;
}
const byteLen = (s) => s.length; // רק תווים עד 0xFF — תו אחד = בית אחד
function latin1(s) {
  const b = new Uint8Array(s.length);
  for (let i = 0; i < s.length; i++) b[i] = s.charCodeAt(i) & 0xff;
  return b;
}
