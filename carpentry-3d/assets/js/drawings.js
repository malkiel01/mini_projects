// שרטוטי מבטים: חזית, צד ומבט־על, כ-SVG, מאותה רשימת חלקים שבתלת מימד.
//
// כל חלק הוא תיבה מקבילה לצירים, ולכן כל מבט הוא הטלה של מלבנים: החלקים
// ממוינים לפי העומק במבט (הרחוק קודם, הקרוב מעליו). מידות: הכוללות בכל
// מבט, ובחזית גם רוחבי העמודות הפנויים ומרווחי המדפים בעמודה הראשונה —
// נגזרים מהלוחות עצמם, לא מהפרמטרים, כך שהם נכונים לכל תבנית.
// הפלט הוא מחרוזת SVG; אין כאן DOM ולכן אפשר להשתמש בזה גם להדפסה.

import { material } from './model/materials.js';

const VIEWS = {
  front: { name: 'חזית', u: 'x', v: 'y', depth: 'z', near: +1 },
  side:  { name: 'צד (מימין)', u: 'z', v: 'y', depth: 'x', near: +1 },
  top:   { name: 'מבט על', u: 'x', v: 'z', depth: 'y', near: +1, flipV: true },
};
const SIZE = { x: 'w', y: 'h', z: 'd' };

function hex(c) { return '#' + (c ?? 0xcccccc).toString(16).padStart(6, '0'); }
function lighten(c, k = 0.35) {
  const r = (c >> 16) & 255, g = (c >> 8) & 255, b = c & 255;
  const f = (v) => Math.round(v + (255 - v) * k);
  return `rgb(${f(r)},${f(g)},${f(b)})`;
}

/** מלבן החלק במבט: [u0, v0, uw, vh] במ"מ, ו-depth לסידור. */
function rect(p, view, bounds) {
  const b = p.box;
  const u0 = b[view.u], uw = b[SIZE[view.u]];
  let v0 = b[view.v], vh = b[SIZE[view.v]];
  if (view.flipV) v0 = bounds[SIZE[view.v]] - v0 - vh;   // במבט־על: החזית למטה
  const depth = b[view.depth] + b[SIZE[view.depth]];      // הקצה הקרוב לצופה
  return { u0, v0, uw, vh, depth };
}

/**
 * מצייר מבט אחד. `opts.width` — רוחב ה-SVG בפיקסלים (הגובה נגזר).
 * מחזיר מחרוזת SVG.
 */
export function drawView(model, viewKey, opts = {}) {
  const view = VIEWS[viewKey];
  const bounds = model.bounds;
  const U = bounds[SIZE[view.u]], V = bounds[SIZE[view.v]];
  const M = Math.max(120, Math.round(Math.max(U, V) * 0.12));   // שוליים למידות
  const rects = model.parts.map((p) => ({ p, r: rect(p, view, bounds), m: material(p.material) }))
    .sort((a, b) => a.r.depth - b.r.depth);

  const out = [];
  const Y = (v) => V - v;   // SVG: y למטה; המודל: y למעלה
  out.push(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="${-M} ${-M} ${U + 2 * M} ${V + 2 * M}" class="drawing" ${opts.width ? `width="${opts.width}"` : ''} font-family="Heebo, Arial, sans-serif">`);
  out.push(`<rect x="${-M}" y="${-M}" width="${U + 2 * M}" height="${V + 2 * M}" fill="#fff"/>`);
  for (const { r, m, p } of rects) {
    const fill = m.kind === 'glass' ? 'rgba(191,224,234,0.6)' : lighten(m.color ?? 0xcccccc, 0.45);
    out.push(`<rect x="${r.u0}" y="${Y(r.v0 + r.vh)}" width="${r.uw}" height="${r.vh}" fill="${fill}" stroke="#3b3027" stroke-width="${Math.max(1, Math.max(U, V) / 800)}" data-id="${p.id}"><title>${esc(p.name)}</title></rect>`);
  }

  // מידות
  const big = Math.max(U, V);
  const sw = Math.max(1, big / 900), fs = Math.max(22, big / 42);
  const dim = (u0, u1, y, label, above = true) => {
    const t = above ? -1 : 1;
    const tick = fs * 0.6;
    out.push(`<g stroke="#9a6124" stroke-width="${sw}" fill="none">
      <line x1="${u0}" y1="${y}" x2="${u1}" y2="${y}"/>
      <line x1="${u0}" y1="${y - tick}" x2="${u0}" y2="${y + tick}"/><line x1="${u1}" y1="${y - tick}" x2="${u1}" y2="${y + tick}"/></g>
      <text x="${(u0 + u1) / 2}" y="${y + t * fs * 0.5}" font-size="${fs}" fill="#9a6124" text-anchor="middle" direction="ltr">${label}</text>`);
  };
  const vdim = (v0, v1, x, label, right = true) => {
    const tick = fs * 0.6;
    const y0 = Y(v1), y1 = Y(v0);
    out.push(`<g stroke="#9a6124" stroke-width="${sw}" fill="none">
      <line x1="${x}" y1="${y0}" x2="${x}" y2="${y1}"/>
      <line x1="${x - tick}" y1="${y0}" x2="${x + tick}" y2="${y0}"/><line x1="${x - tick}" y1="${y1}" x2="${x + tick}" y2="${y1}"/></g>
      <text x="${x + (right ? fs * 0.5 : -fs * 0.5)}" y="${(y0 + y1) / 2}" font-size="${fs}" fill="#9a6124" text-anchor="${right ? 'start' : 'end'}" dominant-baseline="middle" direction="ltr">${label}</text>`);
  };
  dim(0, U, -M * 0.45, Math.round(U), true);
  vdim(0, V, U + M * 0.45, Math.round(V), true);

  if (viewKey === 'front') {
    // רוחבי העמודות הפנויים: בין לוחות אנכיים (ציר x) סמוכים, בגובה אמצע הגוף.
    const verticals = model.parts.filter((p) => p.axis === 'x' && p.box.h > V * 0.5).sort((a, b) => a.box.x - b.box.x);
    const gaps = [];
    for (let i = 0; i < verticals.length - 1; i++) {
      const a = verticals[i], b = verticals[i + 1];
      const u0 = a.box.x + a.box.w, u1 = b.box.x;
      if (u1 - u0 > 20) gaps.push([u0, u1]);
    }
    if (gaps.length > 1 || (gaps.length === 1 && Math.round(gaps[0][1] - gaps[0][0]) !== Math.round(U))) {
      for (const [u0, u1] of gaps) dim(u0, u1, V + M * 0.45, Math.round(u1 - u0), false);
    }
    // מרווחי המדפים בעמודה הראשונה: לוחות אופקיים (ציר y) שנמצאים בטווח ה-x שלה.
    if (gaps.length) {
      const [c0, c1] = gaps[0];
      const horiz = model.parts.filter((p) => p.axis === 'y' && p.box.x < c1 && p.box.x + p.box.w > c0 && p.box.w <= (c1 - c0) + 60)
        .sort((a, b) => a.box.y - b.box.y);
      const all = model.parts.filter((p) => p.axis === 'y' && p.box.x <= c0 + 1 && p.box.x + p.box.w >= c1 - 1).sort((a, b) => a.box.y - b.box.y);
      const seq = [...new Set([...all, ...horiz])].sort((a, b) => a.box.y - b.box.y);
      for (let i = 0; i < seq.length - 1; i++) {
        const v0 = seq[i].box.y + seq[i].box.h, v1 = seq[i + 1].box.y;
        if (v1 - v0 > 20) vdim(v0, v1, -M * 0.45, Math.round(v1 - v0), false);
      }
    }
  }
  out.push('</svg>');
  return out.join('\n');
}

/** שלושת המבטים. */
export function drawAll(model, opts) {
  return Object.keys(VIEWS).map((k) => ({ key: k, name: VIEWS[k].name, svg: drawView(model, k, opts) }));
}

/** תוכנית חיתוך של לוח אחד (מתוך nest) כ-SVG. */
export function drawSheet(sheet, opts = {}) {
  const { L, W, places } = sheet;
  const fs = Math.max(24, L / 60);
  const out = [`<svg xmlns="http://www.w3.org/2000/svg" viewBox="-10 -10 ${L + 20} ${W + 20}" class="drawing drawing--sheet" ${opts.width ? `width="${opts.width}"` : ''} font-family="Heebo, Arial, sans-serif">`];
  out.push(`<rect x="0" y="0" width="${L}" height="${W}" fill="#faf7f2" stroke="#3b3027" stroke-width="${L / 500}"/>`);
  for (const pl of places) {
    out.push(`<rect x="${pl.x}" y="${pl.y}" width="${pl.w}" height="${pl.h}" fill="${pl.rotated ? '#f6e8d6' : '#e8d8bf'}" stroke="#9a6124" stroke-width="${L / 700}"><title>${esc(pl.name)} ${pl.w}×${pl.h}</title></rect>`);
    if (pl.w > fs * 4 && pl.h > fs * 1.5) {
      out.push(`<text x="${pl.x + pl.w / 2}" y="${pl.y + pl.h / 2}" font-size="${fs}" fill="#3b3027" text-anchor="middle" dominant-baseline="middle">${esc(pl.name)} <tspan direction="ltr">${Math.round(pl.w)}×${Math.round(pl.h)}</tspan></text>`);
    }
  }
  out.push('</svg>');
  return out.join('\n');
}

function esc(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
