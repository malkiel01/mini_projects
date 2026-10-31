// התמונה הממוזערת של המסמך (renditions/preview.png ב-.mbe): העמוד הראשון, עד 1024 פיקסלים בצלע הארוכה.
// מצויר כמו על הנייר — אותן שורות ואותם גליפים, תמונות חתוכות למסגרת, מספר עמוד; בלי קווי עזר.

import { pageSize, runningItems, headsByPage } from './pages.js';
import { framesOnPage, drawingsOnPage, isImage, imagePlacement } from './frames.js';
import { drawItems, imageRefs } from './vector.js';
import { drawGlyphs, shapeLine, advance } from './shaper.js';

const MAX = 1024;

/** loadImage(src) → Promise<CanvasImageSource | null>. מחזיר Blob של PNG. */
export async function renderPreview(model, text, running, loadImage, index = 0) {
  const [w, h] = pageSize(model);
  const k = MAX / Math.max(w, h);
  const c = document.createElement('canvas');
  c.width = Math.round(w * k); c.height = Math.round(h * k);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
  ctx.scale(k, k);
  // ציורים — מתחת לטקסט; התמונות שבתוכם נטענות קודם
  for (const d of drawingsOnPage(model, index)) {
    const imgs = new Map();
    for (const src of imageRefs(d.paths)) imgs.set(src, await loadImage(src));
    drawItems(ctx, d.paths, { symbols: model.symbols, image: src => imgs.get(src) || null });
  }
  ctx.fillStyle = '#000';
  for (const fr of framesOnPage(model, index)) {
    if (isImage(fr)) {
      const img = await loadImage(fr.src);
      if (!img) continue;
      const r = imagePlacement(fr);
      const [bx, by, bw, bh] = fr.box;
      ctx.save(); ctx.beginPath(); ctx.rect(bx, by, bw, bh); ctx.clip();
      ctx.drawImage(img, r.x, r.y, r.w, r.h);
      ctx.restore();
      continue;
    }
    for (const l of text?.frames.get(fr.id)?.lines || []) drawGlyphs(ctx, l.font, l.glyphs, l.x, l.y, l.size);
  }
  for (const it of runningItems(model, index + 1, headsByPage(model, text, i => framesOnPage(model, i))[index])) {
    const g = shapeLine(running, it.text);
    const width = advance(g) * it.size / running[0].upem;
    const x0 = it.align === 'left' ? it.x : it.align === 'right' ? it.x - width : it.x - width / 2;
    drawGlyphs(ctx, running[0], g, x0, it.y + it.size * 0.3, it.size);
  }
  return new Promise((ok, fail) => c.toBlob(b => (b ? ok(b) : fail(new Error('preview'))), 'image/png'));
}
