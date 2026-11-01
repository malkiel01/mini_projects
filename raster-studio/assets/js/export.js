// ייצוא: PNG / JPG (משוטחת) ו-ORA (שכבות — GIMP, Krita). ‎.mbe — ב-mbe-io.js, דרך הליבה.

import { encodePNG } from './engine/png.js';
import { zipStore } from './engine/zip.js';
import { oraStack, effectsOf } from './engine/formats.js';
import { fileName as uiFileName } from './ui-core/files.js';

/** התמונה המשוטחת (רק שכבות גלויות): Promise ל-RGBA ישר של 8 ביט בגודל הקנבס */
export const flatRGBA = (renderer, doc) => renderer.readPixels(doc, 0, 0, doc.width, doc.height);

/**
 * שכבה אחת בגודל הקנבס, כ-PNG (התוכן שמחוץ לקנבס לא נכנס). masked — כפול מסכת השכבה (מה שרואים).
 * גם מסכה עצמה (layer.mask) — PNG בגווני אפור.
 */
export const layerPNG = async (renderer, doc, layer, masked = false) => encodePNG(layer.adjust
  // שכבת התאמה: אין לה פיקסלים — מה שנראה במקומה (מה שמתחתיה אחרי ההתאמה, כפול המסכה)
  ? await renderer.readAdjustLayerPixels(doc, layer, 0, 0, doc.width, doc.height)
  // המראה של שכבה עם סגנונות שכבה: כפול המסכה, עם האפקטים
  : masked && effectsOf(layer).length ? await renderer.readEffectsLayerPixels(doc, layer, 0, 0, doc.width, doc.height)
  : await renderer.readLayerPixels(layer, 0, 0, doc.width, doc.height, masked), doc.width, doc.height);

/** תמונה מוקטנת (הצלע הארוכה ≤ max) כ-PNG — לתמונה ממוזערת / preview */
export async function scaledPNG(rgba, w, h, max) {
  const k = Math.min(1, max / Math.max(w, h));
  if (k === 1) return encodePNG(rgba, w, h);
  const tw = Math.max(1, Math.round(w * k)), th = Math.max(1, Math.round(h * k));
  const bmp = await createImageBitmap(new ImageData(new Uint8ClampedArray(rgba.buffer, rgba.byteOffset, rgba.length), w, h),
    { resizeWidth: tw, resizeHeight: th, resizeQuality: 'high', premultiplyAlpha: 'none' });
  const cv = new OffscreenCanvas(tw, th);
  const ctx = cv.getContext('2d');
  ctx.drawImage(bmp, 0, 0);
  bmp.close();
  return encodePNG(ctx.getImageData(0, 0, tw, th).data, tw, th);
}

export async function exportPNG(renderer, doc) {
  return new Blob([await encodePNG(await flatRGBA(renderer, doc), doc.width, doc.height)], { type: 'image/png' });
}

/** JPG: בלי שקיפות — מעל לבן */
export async function exportJPG(renderer, doc, quality = 0.92) {
  const rgba = await flatRGBA(renderer, doc);
  const bmp = await createImageBitmap(new ImageData(rgba, doc.width, doc.height), { premultiplyAlpha: 'none' });
  const cv = new OffscreenCanvas(doc.width, doc.height);
  const ctx = cv.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, doc.width, doc.height);
  ctx.drawImage(bmp, 0, 0);
  bmp.close();
  return cv.convertToBlob({ type: 'image/jpeg', quality });
}

/** ORA — שכבה לכל שכבה, mergedimage.png ותמונה ממוזערת. מחזיר Uint8Array. */
export async function buildORA(renderer, doc) {
  const files = [{ name: 'mimetype', data: 'image/openraster' }];
  let n = 0;
  // עץ: לכל שכבת רסטר — PNG; קבוצה — stack מקונן
  const walk = async list => {
    const out = [];
    for (const l of list) {
      if (l.type === 'group') { out.push({ ...l, children: await walk(l.children) }); continue; }
      if (l.foreign) continue;   // של אפליקציה אחרת — לא מצוירת אצלנו (פער בדוח)
      const src = `data/layer${n++}.png`;
      files.push({ name: src, data: await layerPNG(renderer, doc, l, true) });   // ב-ORA אין מסכות — אפויה
      out.push({ ...l, src });
    }
    return out;
  };
  const layers = await walk(doc.layers);
  const flat = await flatRGBA(renderer, doc);
  files.splice(1, 0, { name: 'stack.xml', data: oraStack(doc.width, doc.height, layers) });
  files.push({ name: 'mergedimage.png', data: await encodePNG(flat, doc.width, doc.height) });
  files.push({ name: 'Thumbnails/thumbnail.png', data: await scaledPNG(flat, doc.width, doc.height, 256) });
  return zipStore(files);
}

export async function exportORA(renderer, doc) {
  return new Blob([await buildORA(renderer, doc)], { type: 'image/openraster' });
}

/** שם קובץ בטוח מהכותרת */
// הורדה ושם קובץ — מ-ui-core; בלי כותרת — "תמונה"
export { download } from './ui-core/files.js';
export const fileName = (title, ext) => uiFileName(String(title ?? '').trim() || 'תמונה', ext);
