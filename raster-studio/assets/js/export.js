// ייצוא: PNG / JPG / WebP / TIFF (משוטחת), ORA (שכבות — GIMP, Krita) ו-PSD (שכבות — פוטושופ). ‎.mbe — ב-mbe-io.js.

import { encodePNG } from './engine/png.js';
import { zipStore } from './engine/zip.js';
import { encodePSD } from './engine/psd.js';
import { encodeTIFF } from './engine/tiff.js';
import { oraStack, effectsOf } from './engine/formats.js';
import { fileName as uiFileName } from './ui-core/files.js';

/** התמונה המשוטחת (רק שכבות גלויות): Promise ל-RGBA ישר של 8 ביט בגודל הקנבס */
export const flatRGBA = (renderer, doc) => renderer.readPixels(doc, 0, 0, doc.width, doc.height);

/**
 * שכבה אחת בגודל הקנבס, כ-PNG (התוכן שמחוץ לקנבס לא נכנס). masked — כפול מסכת השכבה (מה שרואים).
 * גם מסכה עצמה (layer.mask) — PNG בגווני אפור.
 */
export const layerPNG = async (renderer, doc, layer, masked = false) => encodePNG(await layerRGBA(renderer, doc, layer, masked), doc.width, doc.height);

/** שכבה אחת בגודל הקנבס כ-RGBA ישר (כמו layerPNG, בלי הקידוד) */
export const layerRGBA = (renderer, doc, layer, masked = false) => layer.adjust
  // שכבת התאמה: אין לה פיקסלים — מה שנראה במקומה (מה שמתחתיה אחרי ההתאמה, כפול המסכה)
  ? renderer.readAdjustLayerPixels(doc, layer, 0, 0, doc.width, doc.height)
  // המראה של שכבה עם סגנונות שכבה: כפול המסכה, עם האפקטים
  : masked && effectsOf(layer).length ? renderer.readEffectsLayerPixels(doc, layer, 0, 0, doc.width, doc.height)
  : renderer.readLayerPixels(layer, 0, 0, doc.width, doc.height, masked);

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

/** קידוד בדפדפן (WebP / AVIF): עם שקיפות. דפדפן שלא יודע לכתוב את הסוג (מחזיר PNG) — שגיאה <סוג>-unsupported */
async function encodeIn(renderer, doc, type, quality) {
  const rgba = await flatRGBA(renderer, doc);
  const cv = new OffscreenCanvas(doc.width, doc.height);
  cv.getContext('2d').putImageData(new ImageData(rgba, doc.width, doc.height), 0, 0);
  const blob = await cv.convertToBlob({ type, quality });
  if (blob.type !== type) throw new Error(type.split('/')[1] + '-unsupported');
  return blob;
}
export const exportWEBP = (renderer, doc, quality = 0.92) => encodeIn(renderer, doc, 'image/webp', quality);
export const exportAVIF = (renderer, doc, quality = 0.85) => encodeIn(renderer, doc, 'image/avif', quality);

/** הדפדפן יודע לכתוב את הסוג? (בדיקה על קנבס של פיקסל אחד, פעם אחת) */
const canCache = new Map();
export function canEncode(type) {
  if (!canCache.has(type)) {
    canCache.set(type, (async () => {
      try { return (await new OffscreenCanvas(1, 1).convertToBlob({ type })).type === type; } catch { return false; }
    })());
  }
  return canCache.get(type);
}

/** TIFF: משוטחת, RGBA בלי דחיסה (הכי תואם — פוטושופ, GIMP, דפוס) */
export async function exportTIFF(renderer, doc) {
  return new Blob([encodeTIFF(await flatRGBA(renderer, doc), doc.width, doc.height)], { type: 'image/tiff' });
}

/** החיתוך של RGBA בגודל הקנבס למלבן של מה שלא שקוף (שכבה ריקה — 0×0) */
function cropRGBA(rgba, W, H) {
  let x0 = W, y0 = H, x1 = -1, y1 = -1;
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    if (!rgba[(y * W + x) * 4 + 3]) continue;
    if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
  }
  if (x1 < 0) return { x: 0, y: 0, w: 0, h: 0, rgba: new Uint8Array(0) };
  const w = x1 - x0 + 1, h = y1 - y0 + 1, out = new Uint8Array(w * h * 4);
  for (let y = 0; y < h; y++) out.set(rgba.subarray(((y0 + y) * W + x0) * 4, ((y0 + y) * W + x0 + w) * 4), y * w * 4);
  return { x: x0, y: y0, w, h, rgba: out };
}

/**
 * PSD בשכבות: כל שכבה — המראה שלה (כפול המסכה, עם סגנונות השכבה; שכבת התאמה / מילוי — אפויה), חתוכה למה שלא שקוף;
 * קבוצות — קבוצות של פוטושופ. שכבות של אפליקציה אחרת לא נכנסות (כמו ב-ORA).
 */
export async function exportPSD(renderer, doc) {
  const walk = async list => {
    const out = [];
    for (const l of list) {
      const props = { name: l.name, opacity: l.opacity, visible: l.visible !== false, blend: l.blend };
      if (l.type === 'group') { out.push({ ...props, type: 'group', children: await walk(l.children) }); continue; }
      if (l.foreign) continue;
      out.push({ ...props, ...cropRGBA(await layerRGBA(renderer, doc, l, true), doc.width, doc.height) });
    }
    return out;
  };
  const layers = await walk(doc.layers);
  const composite = await flatRGBA(renderer, doc);
  return new Blob([encodePSD({ width: doc.width, height: doc.height, layers, composite })], { type: 'image/vnd.adobe.photoshop' });
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
export const fileName = (title, ext) => uiFileName(title, ext, 'תמונה');
