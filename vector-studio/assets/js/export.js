// ייצוא: SVG ו-PDF וקטוריים (טקסט — כקווי מתאר), ודוח פערים לכל ייצוג. בלי DOM — רץ גם ב-node.
// PNG — בעורך (צריך קנבס), עם render.js.

import { parse } from './geometry/path.js';
import { isIdentity } from './geometry/matrix.js';
import { isGroup, isText } from './model.js';
import { textToD } from './outline.js';

const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const r = (v) => String(Math.round(v * 1000) / 1000);

/**
 * מכין את הלוח לייצוא: טקסטים → נתיבים (עם אותו מילוי ומתאר), ורשימת פערים.
 * מחזיר { surface, items: [{ layer, items }], gaps }.
 */
export async function prepare(doc, surfaceId, getFont) {
  const surface = doc.surfaces.find(s => s.id === surfaceId) || doc.surfaces[0];
  const gaps = new Set();
  const conv = async (item) => {
    if (isGroup(item)) return { ...item, children: await Promise.all((item.children || []).map(conv)) };
    if (isText(item)) {
      gaps.add('text → outlines');
      return { id: item.id, d: await textToD(item, getFont), fill: item.fill, stroke: item.stroke, label: item.text, ...(item.transform ? { transform: item.transform } : {}) };
    }
    return item;
  };
  const layers = [];
  for (const l of surface.layers || []) {
    if (l.type !== 'vector' || l.visible === false) { if (l.type !== 'vector') gaps.add(`layer:${l.type} — לא נכלל`); continue; }
    if (l.blend && l.blend !== 'normal') gaps.add(`blend-mode:${l.blend} → normal`);
    layers.push({ layer: l, items: await Promise.all((l.paths || []).map(conv)) });
  }
  return { surface, layers, gaps: [...gaps] };
}

// ---------- SVG ----------

/** מעבר צבע → <linearGradient> ב-defs (במרחב המשתמש — אותן קואורדינטות של הנתיב). מחזיר את המזהה. */
function svgGradient(g, defs) {
  const id = 'grad' + (defs.length + 1);
  const stops = (g.stops || []).map(st => `<stop offset="${r(st.at)}" stop-color="${esc(st.color)}"${(st.opacity ?? 1) < 1 ? ` stop-opacity="${r(st.opacity)}"` : ''}/>`).join('');
  defs.push(`<linearGradient id="${id}" gradientUnits="userSpaceOnUse" x1="${r(g.from[0])}" y1="${r(g.from[1])}" x2="${r(g.to[0])}" y2="${r(g.to[1])}">${stops}</linearGradient>`);
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
  if (s?.color && (s.width ?? 1) > 0) {
    a.push(`stroke="${esc(s.color)}"`, `stroke-width="${r(s.width ?? 1)}"`);
    if ((s.opacity ?? 1) < 1) a.push(`stroke-opacity="${r(s.opacity)}"`);
    if (s.cap && s.cap !== 'butt') a.push(`stroke-linecap="${esc(s.cap)}"`);
    if (s.join && s.join !== 'miter') a.push(`stroke-linejoin="${esc(s.join)}"`);
    if (s.dash?.length) a.push(`stroke-dasharray="${s.dash.map(r).join(' ')}"`);
  }
  return a.join(' ');
}

function svgItem(item, indent, defs) {
  if (item.hidden) return '';
  if (isGroup(item)) {
    return `${indent}<g id="${esc(item.id)}">\n${(item.children || []).map(c => svgItem(c, indent + '  ', defs)).join('')}${indent}</g>\n`;
  }
  if (!item.d) return '';
  const t = item.transform && !isIdentity(item.transform) ? ` transform="matrix(${item.transform.map(r).join(' ')})"` : '';
  const label = item.label ? ` aria-label="${esc(item.label)}"` : '';
  return `${indent}<path id="${esc(item.id)}" d="${esc(item.d)}" ${svgStyle(item, defs)}${t}${label}/>\n`;
}

/** SVG של לוח אחד, בגודל ב-pt (viewBox ביחידות pt). */
export function toSVG(prepared) {
  const { surface, layers } = prepared;
  const [w, h] = surface.size;
  const defs = [];
  let body = '';
  for (const { layer, items } of layers) {
    const op = (layer.opacity ?? 1) < 1 ? ` opacity="${r(layer.opacity)}"` : '';
    body += `  <g id="${esc(layer.id)}" data-name="${esc(layer.name || '')}"${op}>\n${items.map(i => svgItem(i, '    ', defs)).join('')}  </g>\n`;
  }
  const head = `<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" width="${r(w)}pt" height="${r(h)}pt" viewBox="0 0 ${r(w)} ${r(h)}">\n`;
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
 * PDF וקטורי: עמוד לכל לוח שהועבר. בלי דחיסה ובלי גופנים (הטקסט כבר נתיבים) — קובץ פשוט שכל קורא פותח.
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
    const patterns = [];
    /** מעבר צבע → Pattern עם Shading צירי. המטריצה ממפה למרחב ברירת המחדל של העמוד (לא ל-CTM הנוכחי). */
    const patternFor = (g, m) => {
      const stops = (g.stops || []).slice().sort((a, b) => a.at - b.at);
      const c = (st) => `[${rgb(st.color)}]`;
      let fn;
      if (stops.length <= 2) fn = `<< /FunctionType 2 /Domain [0 1] /C0 ${c(stops[0])} /C1 ${c(stops[stops.length - 1])} /N 1 >>`;
      else {
        const parts = stops.slice(1).map((st, i) => `<< /FunctionType 2 /Domain [0 1] /C0 ${c(stops[i])} /C1 ${c(st)} /N 1 >>`);
        fn = `<< /FunctionType 3 /Domain [0 1] /Functions [${parts.join(' ')}] /Bounds [${stops.slice(1, -1).map(st => r(st.at)).join(' ')}] /Encode [${parts.map(() => '0 1').join(' ')}] >>`;
      }
      const name = 'P' + (patterns.length + 1);
      patterns.push(`/${name} ${add(`<< /PatternType 2 /Matrix [${m.map(r).join(' ')}] /Shading << /ShadingType 2 /ColorSpace /DeviceRGB /Coords [${[...g.from, ...g.to].map(r).join(' ')}] /Function ${fn} /Extend [true true] >> >>`)} 0 R`);
      return name;
    };
    const emit = (item, alpha) => {
      if (item.hidden) return;
      if (isGroup(item)) { for (const c of item.children || []) emit(c, alpha); return; }
      if (!item.d) return;
      const fill = item.fill?.color || item.fill?.gradient ? item.fill : null;
      const st = item.stroke?.color && (item.stroke.width ?? 1) > 0 ? item.stroke : null;
      if (!fill && !st) return;
      ops.push('q');
      if (item.transform && !isIdentity(item.transform)) ops.push(`${item.transform.map(r).join(' ')} cm`);
      const ca = alpha * (fill?.opacity ?? 1), CA = alpha * (st?.opacity ?? 1);
      if (ca < 1 || CA < 1) ops.push(`/${gsName(ca, CA)} gs`);
      if (fill?.gradient) {
        const t = item.transform && !isIdentity(item.transform) ? item.transform : [1, 0, 0, 1, 0, 0];
        ops.push(`/Pattern cs /${patternFor(fill.gradient, mul(flip, t))} scn`);
      } else if (fill) ops.push(`${rgb(fill.color)} rg`);
      if (st) {
        ops.push(`${rgb(st.color)} RG`, `${r(st.width ?? 1)} w`);
        ops.push(`${{ butt: 0, round: 1, square: 2 }[st.cap] ?? 0} J`, `${{ miter: 0, round: 1, bevel: 2 }[st.join] ?? 0} j`);
        ops.push(`${r(st.miter ?? 4)} M`);
        if (st.dash?.length) ops.push(`[${st.dash.map(r).join(' ')}] 0 d`);
      }
      ops.push(pdfPath(item.d));
      const eo = item.fillRule === 'evenodd' ? '*' : '';
      ops.push(fill && st ? 'B' + eo : fill ? 'f' + eo : 'S');
      ops.push('Q');
    };
    for (const { layer, items } of prep.layers) for (const it of items) emit(it, layer.opacity ?? 1);

    const stream = ops.join('\n');
    const content = add(`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`);
    const ext = [...gs.values()].map(g => `/${g.name} << /Type /ExtGState /ca ${r(g.ca)} /CA ${r(g.CA)} >>`).join(' ');
    kids.push(add(`<< /Type /Page /Parent ${pagesObj} 0 R /MediaBox [0 0 ${r(W)} ${r(H)}] /Contents ${content} 0 R /Resources << /ExtGState << ${ext} >> /Pattern << ${patterns.join(' ')} >> >> >>`));
  }
  objs[catalog - 1] = `<< /Type /Catalog /Pages ${pagesObj} 0 R >>`;
  objs[pagesObj - 1] = `<< /Type /Pages /Kids [${kids.map(k => k + ' 0 R').join(' ')}] /Count ${kids.length} >>`;

  // הכול ASCII — אורך המחרוזת הוא מספר הבתים
  let out = '%PDF-1.4\n%\xE2\xE3\xCF\xD3\n';
  const offsets = [];
  objs.forEach((o, i) => { offsets.push(byteLen(out)); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const xref = byteLen(out);
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offsets.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root ${catalog} 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return latin1(out);
}

const mul = (a, b) => [a[0] * b[0] + a[2] * b[1], a[1] * b[0] + a[3] * b[1], a[0] * b[2] + a[2] * b[3], a[1] * b[2] + a[3] * b[3], a[0] * b[4] + a[2] * b[5] + a[4], a[1] * b[4] + a[3] * b[5] + a[5]];
const byteLen = (s) => s.length; // רק תווים עד 0xFF — תו אחד = בית אחד
function latin1(s) {
  const b = new Uint8Array(s.length);
  for (let i = 0; i < s.length; i++) b[i] = s.charCodeAt(i) & 0xff;
  return b;
}
