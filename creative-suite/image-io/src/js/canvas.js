// קידוד בדפדפן — JPEG, ‏WebP ו-AVIF, דרך OffscreenCanvas. PNG / TIFF / PSD — בקוד שלנו (png.js, tiff.js, psd.js),
// כי הקנבס מכפיל באלפא ומאבד דיוק. כאן רק מה שאין טעם לכתוב לבד.

const canCache = new Map();

/** הדפדפן יודע לכתוב את הסוג? (בדיקה על קנבס של פיקסל אחד, פעם אחת לכל סוג) → Promise<boolean> */
export function canEncode(type) {
  if (!canCache.has(type)) {
    canCache.set(type, (async () => {
      try { return (await new OffscreenCanvas(1, 1).convertToBlob({ type })).type === type; } catch { return false; }
    })());
  }
  return canCache.get(type);
}

/**
 * rgba (w×h×4, אלפא ישר) → Blob מסוג type ('image/jpeg' | 'image/webp' | 'image/avif').
 * background — צבע CSS מתחת לתמונה; ל-JPEG ברירת המחדל לבן (אין בו שקיפות), לשאר — שקוף.
 * דפדפן שלא יודע לכתוב את הסוג (מחזיר PNG במקום) — Error בעברית, ו-code: '<סוג>-unsupported'.
 */
export async function encodeImage(rgba, w, h, type, { quality, background } = {}) {
  const cv = new OffscreenCanvas(w, h);
  const ctx = cv.getContext('2d');
  const bg = background ?? (type === 'image/jpeg' ? '#fff' : null);
  const img = new ImageData(rgba instanceof Uint8ClampedArray ? rgba : new Uint8ClampedArray(rgba.buffer, rgba.byteOffset, rgba.length), w, h);
  if (bg) {
    // מעל רקע — דרך ImageBitmap, כדי שהשקיפות תתמזג (putImageData מחליף פיקסלים ולא ממזג)
    const bmp = await createImageBitmap(img, { premultiplyAlpha: 'none' });
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(bmp, 0, 0);
    bmp.close();
  } else ctx.putImageData(img, 0, 0);
  const blob = await cv.convertToBlob({ type, quality });
  if (blob.type !== type) {
    const name = type.split('/')[1];
    throw Object.assign(new Error(`image-io: הדפדפן לא יודע לכתוב ${name.toUpperCase()}`), { code: name + '-unsupported' });
  }
  return blob;
}
