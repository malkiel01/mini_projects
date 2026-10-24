// כותב ZIP פשוט (שיטת "שמור", בלי דחיסה) — ל-ORA. התוכן שם הוא PNG שכבר דחוס, ו-mimetype חייב להיות ראשון
// ולא דחוס. בלי ZIP64 (מספיק עד 4GB). קובצי ‎.mbe — רק דרך ליבת הפורמט (creative-suite/mbe-core), לא כאן.

import { crc32 } from './png.js';

const enc = new TextEncoder();

/** files — [{ name, data: Uint8Array | string }] בסדר הרצוי. מחזיר Uint8Array. */
export function zipStore(files) {
  const local = [], central = [];
  let offset = 0;
  // תאריך קבוע (1980-01-01) — אותו קלט, אותם בתים
  const DOS_TIME = 0, DOS_DATE = (0 << 9) | (1 << 5) | 1;
  for (const f of files) {
    const name = enc.encode(f.name);
    const data = typeof f.data === 'string' ? enc.encode(f.data) : f.data;
    const crc = crc32(data);
    const h = new Uint8Array(30 + name.length);
    const v = new DataView(h.buffer);
    v.setUint32(0, 0x04034b50, true);
    v.setUint16(4, 20, true);              // גרסה נדרשת
    v.setUint16(6, 0x0800, true);          // UTF-8 בשמות
    v.setUint16(8, 0, true);               // שמור
    v.setUint16(10, DOS_TIME, true); v.setUint16(12, DOS_DATE, true);
    v.setUint32(14, crc, true);
    v.setUint32(18, data.length, true); v.setUint32(22, data.length, true);
    v.setUint16(26, name.length, true);
    h.set(name, 30);
    local.push(h, data);
    const c = new Uint8Array(46 + name.length);
    const w = new DataView(c.buffer);
    w.setUint32(0, 0x02014b50, true);
    w.setUint16(4, 20, true); w.setUint16(6, 20, true);
    w.setUint16(8, 0x0800, true); w.setUint16(10, 0, true);
    w.setUint16(12, DOS_TIME, true); w.setUint16(14, DOS_DATE, true);
    w.setUint32(16, crc, true);
    w.setUint32(20, data.length, true); w.setUint32(24, data.length, true);
    w.setUint16(28, name.length, true);
    w.setUint32(42, offset, true);
    c.set(name, 46);
    central.push(c);
    offset += h.length + data.length;
  }
  const cdSize = central.reduce((n, c) => n + c.length, 0);
  const end = new Uint8Array(22);
  const e = new DataView(end.buffer);
  e.setUint32(0, 0x06054b50, true);
  e.setUint16(8, files.length, true); e.setUint16(10, files.length, true);
  e.setUint32(12, cdSize, true); e.setUint32(16, offset, true);
  const parts = [...local, ...central, end];
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let o = 0;
  for (const p of parts) { out.set(p, o); o += p.length; }
  return out;
}
