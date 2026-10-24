// קורא ZIP קטן — לפתיחת ORA (מ-GIMP / Krita: שיטת "שמור" או deflate). הכול בזיכרון, ועם הגנות:
// נתיבים עם ".." או "/" מוביל — נדחים; תקרה לגודל הפתוח, למספר הקבצים וליחס הדחיסה (zip-bomb).
// ‎.mbe לא נקרא כאן — רק דרך ליבת הפורמט.

export const LIMITS = { maxFiles: 10000, maxTotal: 1024 ** 3, maxRatio: 100, ratioFloor: 1024 ** 2 };

async function inflateRaw(bytes) {
  const s = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
  return new Uint8Array(await new Response(s).arrayBuffer());
}

/** מחזיר Map: שם → Uint8Array. זורק Error עם קוד קצר: 'zip' | 'zip-unsafe' | 'zip-too-big' | 'zip-method'. */
export async function unzip(buf, limits = LIMITS) {
  const b = buf instanceof Uint8Array ? buf : new Uint8Array(buf);
  const v = new DataView(b.buffer, b.byteOffset, b.byteLength);
  // סוף הספרייה המרכזית — מחפשים מהסוף (אחריה עד 64KB הערה)
  let eocd = -1;
  for (let i = b.length - 22; i >= Math.max(0, b.length - 22 - 65535); i--) {
    if (v.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
  }
  if (eocd < 0) throw new Error('zip');
  const count = v.getUint16(eocd + 10, true);
  let p = v.getUint32(eocd + 16, true);
  if (count > limits.maxFiles) throw new Error('zip-too-big');
  const out = new Map();
  let total = 0;
  const dec = new TextDecoder();
  for (let n = 0; n < count; n++) {
    if (p + 46 > b.length || v.getUint32(p, true) !== 0x02014b50) throw new Error('zip');
    const method = v.getUint16(p + 10, true);
    const csize = v.getUint32(p + 20, true), usize = v.getUint32(p + 24, true);
    const nlen = v.getUint16(p + 28, true), xlen = v.getUint16(p + 30, true), clen = v.getUint16(p + 32, true);
    const loc = v.getUint32(p + 42, true);
    const name = dec.decode(b.subarray(p + 46, p + 46 + nlen));
    p += 46 + nlen + xlen + clen;
    if (name.endsWith('/')) continue;
    if (name.includes('..') || name.startsWith('/') || name.includes('\\')) throw new Error('zip-unsafe');
    if (usize > limits.ratioFloor && usize > csize * limits.maxRatio) throw new Error('zip-too-big');
    total += usize;
    if (total > limits.maxTotal) throw new Error('zip-too-big');
    if (v.getUint32(loc, true) !== 0x04034b50) throw new Error('zip');
    const start = loc + 30 + v.getUint16(loc + 26, true) + v.getUint16(loc + 28, true);
    const data = b.subarray(start, start + csize);
    let bytes;
    if (method === 0) bytes = data.slice();
    else if (method === 8) bytes = await inflateRaw(data);
    else throw new Error('zip-method');
    if (bytes.length !== usize) throw new Error('zip');
    out.set(name, bytes);
  }
  return out;
}
