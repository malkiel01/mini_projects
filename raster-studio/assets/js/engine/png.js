// מקודד PNG משלנו: RGBA של 8 ביט, בדיוק הבתים שקיבלנו — בלי המעבר דרך קנבס דו-ממדי (שמכפיל באלפא
// ומאבד דיוק בפיקסלים שקופים-למחצה). הדחיסה — CompressionStream('deflate') של הדפדפן (zlib). עובד גם ב-node 18+.

const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

/** CRC-32 (כמו ב-PNG וב-ZIP). אפשר להמשיך מ-crc קודם. */
export function crc32(bytes, crc = 0) {
  let c = ~crc >>> 0;
  for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
  return ~c >>> 0;
}

async function deflate(bytes) {
  const s = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate'));
  return new Uint8Array(await new Response(s).arrayBuffer());
}

function chunk(type, data) {
  const out = new Uint8Array(12 + data.length);
  const dv = new DataView(out.buffer);
  dv.setUint32(0, data.length);
  for (let i = 0; i < 4; i++) out[4 + i] = type.charCodeAt(i);
  out.set(data, 8);
  dv.setUint32(8 + data.length, crc32(out.subarray(4, 8 + data.length)));
  return out;
}

/** rgba — Uint8Array / Uint8ClampedArray של w×h×4, שורה 0 למעלה. מחזיר Uint8Array של קובץ PNG. */
export async function encodePNG(rgba, w, h) {
  const stride = w * 4;
  const raw = new Uint8Array((stride + 1) * h);
  // מסנן "Sub" לכל שורה — דוחס טוב יותר תמונות אמיתיות, ופשוט
  for (let y = 0; y < h; y++) {
    const o = y * (stride + 1), r = y * stride;
    raw[o] = 1;
    for (let i = 0; i < stride; i++) raw[o + 1 + i] = (rgba[r + i] - (i >= 4 ? rgba[r + i - 4] : 0)) & 0xFF;
  }
  const ihdr = new Uint8Array(13);
  const dv = new DataView(ihdr.buffer);
  dv.setUint32(0, w); dv.setUint32(4, h);
  ihdr[8] = 8; ihdr[9] = 6;   // 8 ביט, RGBA
  const parts = [new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr),
    // sRGB: הצבעים כבר ב-sRGB — מציגים לא ינחשו גמא אחרת
    chunk('sRGB', new Uint8Array([0])), chunk('IDAT', await deflate(raw)), chunk('IEND', new Uint8Array(0))];
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let o = 0;
  for (const p of parts) { out.set(p, o); o += p.length; }
  return out;
}

/** SHA-256 בהקס — לשמות ב-media/ של ‎.mbe */
export async function sha256Hex(bytes) {
  const h = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
  return [...h].map(b => b.toString(16).padStart(2, '0')).join('');
}
