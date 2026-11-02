// זיהוי סוג קובץ לפי הבתים הראשונים — לא לפי שם או MIME (שמגיעים מהמשתמש ולפעמים חסרים). חשבון טהור.

const at = (b, o, ...v) => v.every((x, i) => b[o + i] === x);
const ascii = (b, o, s) => [...s].every((c, i) => b[o + i] === c.charCodeAt(0));

/**
 * bytes — Uint8Array, די ב-32 הראשונים. מחזיר אחד מ:
 * 'png' | 'jpeg' | 'gif' | 'webp' | 'avif' | 'heic' | 'tiff' | 'psd' | 'bmp' | 'zip' — או null.
 * 'zip' כולל ORA, ‎.mbe, docx — מה שבפנים מכריע (mimetype הראשון ב-ORA; ‎.mbe — רק דרך הליבה).
 */
export function sniff(bytes) {
  const b = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  if (at(b, 0, 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a)) return 'png';
  if (at(b, 0, 0xff, 0xd8, 0xff)) return 'jpeg';
  if (ascii(b, 0, 'GIF87a') || ascii(b, 0, 'GIF89a')) return 'gif';
  if (ascii(b, 0, 'RIFF') && ascii(b, 8, 'WEBP')) return 'webp';
  if (ascii(b, 4, 'ftyp')) {
    const brand = String.fromCharCode(...b.subarray(8, 12));
    if (brand === 'avif' || brand === 'avis') return 'avif';
    if (['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'].includes(brand)) return 'heic';
  }
  if (at(b, 0, 0x49, 0x49, 0x2a, 0x00) || at(b, 0, 0x4d, 0x4d, 0x00, 0x2a)) return 'tiff';
  if (ascii(b, 0, '8BPS')) return 'psd';
  if (ascii(b, 0, 'BM')) return 'bmp';
  if (at(b, 0, 0x50, 0x4b, 0x03, 0x04)) return 'zip';
  return null;
}

/** ה-MIME של מה ש-sniff החזיר (null — לא מוכר) */
export const MIME = {
  png: 'image/png', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', avif: 'image/avif', heic: 'image/heic',
  tiff: 'image/tiff', psd: 'image/vnd.adobe.photoshop', bmp: 'image/bmp', zip: 'application/zip',
};
