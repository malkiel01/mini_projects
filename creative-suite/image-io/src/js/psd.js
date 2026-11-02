// PSD (Photoshop) — כתיבה וקריאה של קובץ בשכבות. חשבון טהור על מערכים, נבדק ב-node.
// נתמך: RGB של 8 ביט, שכבות רסטר עם שם (גם עברית — luni), מיקום, אטימות, הסתרה, מיזוג (רגיל / הכפלה / מסך /
// שכבת-על) וקבוצות (lsct). כתיבה — בלי דחיסה; קריאה — בלי דחיסה ו-RLE (PackBits). מסכות שכבה, סגנונות, טקסט ושכבות
// התאמה של פוטושופ — לא נקראים (השכבה נקראת כפי שהיא בפיקסלים); 16 / 32 ביט, CMYK, Lab ו-PSB — שגיאה ברורה.
//
// עץ השכבות — מלמטה למעלה (כמו ב-ORA):
//   שכבה: { name, x, y, w, h, rgba (Uint8Array של w×h×4, אלפא ישר), opacity (0..1), visible, blend }
//   קבוצה: { type: 'group', name, opacity, visible, blend, children }

const BLEND_KEY = { normal: 'norm', multiply: 'mul ', screen: 'scrn', overlay: 'over' };
const KEY_BLEND = Object.fromEntries(Object.entries(BLEND_KEY).map(([k, v]) => [v, k]));

export class PSDError extends Error {
  constructor(code) { super(code); this.code = code; }
}

// ---------- כתיבה ----------

class Writer {
  constructor() { this.chunks = []; this.len = 0; }
  bytes(b) { this.chunks.push(b); this.len += b.length; }
  u8(v) { this.bytes(Uint8Array.of(v & 255)); }
  u16(v) { const b = new Uint8Array(2); new DataView(b.buffer).setUint16(0, v); this.bytes(b); }
  i16(v) { const b = new Uint8Array(2); new DataView(b.buffer).setInt16(0, v); this.bytes(b); }
  u32(v) { const b = new Uint8Array(4); new DataView(b.buffer).setUint32(0, v >>> 0); this.bytes(b); }
  i32(v) { const b = new Uint8Array(4); new DataView(b.buffer).setInt32(0, v); this.bytes(b); }
  ascii(s) { this.bytes(Uint8Array.from(s, c => c.charCodeAt(0) & 255)); }
  done() {
    const out = new Uint8Array(this.len);
    let o = 0;
    for (const c of this.chunks) { out.set(c, o); o += c.length; }
    return out;
  }
}

/** בלוק עם אורך מקדים (uint32), מרופד לכפולה של pad */
function block(fill, pad = 1) {
  const w = new Writer();
  fill(w);
  let body = w.done();
  if (body.length % pad) { const p = new Uint8Array(body.length + pad - body.length % pad); p.set(body); body = p; }
  const out = new Writer();
  out.u32(body.length);
  out.bytes(body);
  return out.done();
}

/** שם פסקל (ASCII בלבד — השם המלא ב-luni), מרופד לכפולה של 4 כולל בית האורך */
function pascal(w, name) {
  const s = [...name].map(c => (c.charCodeAt(0) < 128 ? c : '_')).join('').slice(0, 255);
  w.u8(s.length);
  w.ascii(s);
  for (let i = (s.length + 1) % 4; i && i < 4; i++) w.u8(0);
}

function extra(w, key, fill) {
  w.ascii('8BIM'); w.ascii(key);
  w.bytes(block(fill, 2));
}

/** רשומות שטוחות מלמטה למעלה, עם מפרידי קבוצות (lsct): 3 = סוף קבוצה (למטה), 1 = הקבוצה עצמה (למעלה) */
function flatten(list, out = []) {
  for (const l of list) {
    if (l.type === 'group') {
      out.push({ name: '</Layer group>', x: 0, y: 0, w: 0, h: 0, rgba: null, opacity: 1, visible: true, blend: 'normal', section: 3 });
      flatten(l.children, out);
      out.push({ ...l, x: 0, y: 0, w: 0, h: 0, rgba: null, section: 1 });
    } else out.push(l);
  }
  return out;
}

/**
 * { width, height, layers (עץ), composite (RGBA ישר של width×height) } → Uint8Array של PSD.
 */
export function encodePSD({ width, height, layers, composite }) {
  const recs = flatten(layers);
  const w = new Writer();
  // כותרת: חתימה, גרסה 1, 6 שמורים, ערוצים (RGBA), גובה, רוחב, עומק 8, מצב RGB
  w.ascii('8BPS'); w.u16(1); w.bytes(new Uint8Array(6)); w.u16(4); w.u32(height); w.u32(width); w.u16(8); w.u16(3);
  w.u32(0);   // color mode data
  w.u32(0);   // image resources
  const info = block(li => {
    li.i16(-recs.length);   // שלילי — הערוץ הראשון של התמונה המשולבת הוא השקיפות
    const chans = [];
    for (const r of recs) {
      const n = r.w * r.h;
      const planes = [-1, 0, 1, 2].map(id => {
        const p = new Uint8Array(n);
        const c = id < 0 ? 3 : id;
        for (let i = 0; i < n; i++) p[i] = r.rgba[i * 4 + c];
        return { id, p };
      });
      chans.push(planes);
      li.i32(r.y); li.i32(r.x); li.i32(r.y + r.h); li.i32(r.x + r.w);
      li.u16(4);
      for (const { id, p } of planes) { li.i16(id); li.u32(2 + p.length); }
      li.ascii('8BIM'); li.ascii(BLEND_KEY[r.blend] || 'norm');
      li.u8(Math.round(Math.min(1, Math.max(0, r.opacity ?? 1)) * 255));
      li.u8(0);   // clipping
      li.u8(r.visible === false ? 2 : 0);   // flags: ביט 1 — מוסתרת
      li.u8(0);
      li.bytes(block(x => {
        x.u32(0);   // מסכת שכבה — אין
        x.u32(0);   // blending ranges — אין
        pascal(x, r.name || '');
        extra(x, 'luni', e => {
          const s = r.name || '';
          e.u32(s.length);
          for (let i = 0; i < s.length; i++) e.u16(s.charCodeAt(i));
        });
        if (r.section) extra(x, 'lsct', e => e.u32(r.section));
      }));
    }
    for (const planes of chans) for (const { p } of planes) { li.u16(0); li.bytes(p); }
  }, 2);
  w.bytes(block(lm => { lm.bytes(info); lm.u32(0); }));
  // התמונה המשולבת: בלי דחיסה, מישורים R, G, B, A
  w.u16(0);
  const n = width * height;
  for (const c of [0, 1, 2, 3]) {
    const p = new Uint8Array(n);
    for (let i = 0; i < n; i++) p[i] = composite[i * 4 + c];
    w.bytes(p);
  }
  return w.done();
}

// ---------- קריאה ----------

class Reader {
  constructor(buf) { this.b = buf instanceof Uint8Array ? buf : new Uint8Array(buf); this.v = new DataView(this.b.buffer, this.b.byteOffset, this.b.byteLength); this.o = 0; }
  need(n) { if (this.o + n > this.b.length) throw new PSDError('psd-truncated'); }
  u8() { this.need(1); return this.b[this.o++]; }
  u16() { this.need(2); const v = this.v.getUint16(this.o); this.o += 2; return v; }
  i16() { this.need(2); const v = this.v.getInt16(this.o); this.o += 2; return v; }
  u32() { this.need(4); const v = this.v.getUint32(this.o); this.o += 4; return v; }
  i32() { this.need(4); const v = this.v.getInt32(this.o); this.o += 4; return v; }
  ascii(n) { this.need(n); let s = ''; for (let i = 0; i < n; i++) s += String.fromCharCode(this.b[this.o++]); return s; }
  bytes(n) { this.need(n); const s = this.b.subarray(this.o, this.o + n); this.o += n; return s; }
}

/** PackBits של שורה אחת → out (מ-offset) */
function unpackBits(src, out, o, len) {
  let i = 0, end = o + len;
  while (i < src.length && o < end) {
    const n = src[i] > 127 ? src[i] - 256 : src[i];
    i++;
    if (n >= 0) { for (let k = 0; k <= n && o < end; k++) out[o++] = src[i++]; }
    else if (n !== -128) { const v = src[i++]; for (let k = 0; k < 1 - n && o < end; k++) out[o++] = v; }
  }
}

/** נתוני ערוץ (w×h) לפי הדחיסה: 0 — כמו שהוא, 1 — RLE. bytes — כל נתוני הערוץ אחרי מילת הדחיסה */
function channelData(comp, bytes, w, h) {
  const out = new Uint8Array(w * h);
  if (comp === 0) { out.set(bytes.subarray(0, w * h)); return out; }
  if (comp !== 1) throw new PSDError('psd-compression');
  const v = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  let p = h * 2;
  for (let y = 0; y < h; y++) {
    const n = v.getUint16(y * 2);
    unpackBits(bytes.subarray(p, p + n), out, y * w, w);
    p += n;
  }
  return out;
}

/** PSD → { width, height, layers (עץ), composite (RGBA ישר) } */
export function decodePSD(buf) {
  const r = new Reader(buf);
  if (r.ascii(4) !== '8BPS') throw new PSDError('psd-signature');
  const version = r.u16();
  if (version !== 1) throw new PSDError('psd-psb');
  r.bytes(6);
  const channels = r.u16(), height = r.u32(), width = r.u32(), depth = r.u16(), mode = r.u16();
  if (depth !== 8) throw new PSDError('psd-depth');
  if (mode !== 3) throw new PSDError('psd-mode');
  r.bytes(r.u32());   // color mode data
  r.bytes(r.u32());   // image resources
  const lmLen = r.u32(), lmEnd = r.o + lmLen;
  const flat = [];
  if (lmLen) {
    const liLen = r.u32(), liEnd = r.o + liLen;
    if (liLen) {
      const count = Math.abs(r.i16());
      const recs = [];
      for (let i = 0; i < count; i++) {
        const top = r.i32(), left = r.i32(), bottom = r.i32(), right = r.i32();
        const nc = r.u16(), chans = [];
        for (let c = 0; c < nc; c++) chans.push({ id: r.i16(), len: r.u32() });
        if (r.ascii(4) !== '8BIM') throw new PSDError('psd-layer');
        const key = r.ascii(4), opacity = r.u8() / 255;
        r.u8();
        const flags = r.u8();
        r.u8();
        const exLen = r.u32(), exEnd = r.o + exLen;
        r.bytes(r.u32());   // מסכת שכבה
        r.bytes(r.u32());   // blending ranges
        const pl = r.u8();
        let name = r.ascii(pl);
        r.bytes((4 - (pl + 1) % 4) % 4);
        let section = 0;
        while (r.o + 12 <= exEnd) {
          const sig = r.ascii(4);
          if (sig !== '8BIM' && sig !== '8B64') break;
          const k = r.ascii(4), len = r.u32(), end = r.o + len;
          if (k === 'luni') {
            const n = r.u32();
            let s = '';
            for (let j = 0; j < n; j++) s += String.fromCharCode(r.u16());
            name = s.replace(/\0+$/, '');
          } else if (k === 'lsct' || k === 'lsdk') section = r.u32();
          r.o = end + (len % 2);
        }
        r.o = exEnd;
        recs.push({ top, left, w: Math.max(0, right - left), h: Math.max(0, bottom - top), chans, blend: KEY_BLEND[key] ?? 'normal', opacity, visible: !(flags & 2), name, section });
      }
      // נתוני הערוצים, לפי הסדר
      for (const rec of recs) {
        const n = rec.w * rec.h;
        const rgba = n ? new Uint8Array(n * 4) : null;
        let alpha = false;
        for (const ch of rec.chans) {
          const start = r.o;
          const comp = r.u16();
          const data = r.bytes(ch.len - 2);
          r.o = start + ch.len;
          if (!n || ch.id < -1 || ch.id > 2) continue;   // מסכה (-2 / -3) — לא נקראת
          const p = channelData(comp, data, rec.w, rec.h);
          const c = ch.id < 0 ? 3 : ch.id;
          if (ch.id < 0) alpha = true;
          for (let i = 0; i < n; i++) rgba[i * 4 + c] = p[i];
        }
        if (rgba && !alpha) for (let i = 0; i < n; i++) rgba[i * 4 + 3] = 255;
        rec.rgba = rgba;
      }
      // העץ: 3 (סוף קבוצה) פותח הקשר, 1 / 2 (הקבוצה) סוגר אותו
      const stack = [[]];
      for (const rec of recs) {
        const top = stack.at(-1);
        if (rec.section === 3) { stack.push([]); continue; }
        if ((rec.section === 1 || rec.section === 2) && stack.length > 1) {
          const children = stack.pop();
          stack.at(-1).push({ type: 'group', name: rec.name, opacity: rec.opacity, visible: rec.visible, blend: rec.blend, children });
          continue;
        }
        top.push({ name: rec.name, x: rec.left, y: rec.top, w: rec.w, h: rec.h, rgba: rec.rgba, opacity: rec.opacity, visible: rec.visible, blend: rec.blend });
      }
      while (stack.length > 1) { const c = stack.pop(); stack.at(-1).push(...c); }
      flat.push(...stack[0]);
    }
    r.o = Math.max(r.o, liEnd);
  }
  r.o = lmEnd;
  // התמונה המשולבת
  const comp = r.u16(), n = width * height;
  const composite = new Uint8Array(n * 4);
  const planes = [];
  if (comp === 0) for (let c = 0; c < channels; c++) planes.push(r.bytes(n));
  else if (comp === 1) {
    const counts = [];
    for (let i = 0; i < channels * height; i++) counts.push(r.u16());
    for (let c = 0; c < channels; c++) {
      const out = new Uint8Array(n);
      for (let y = 0; y < height; y++) { const len = counts[c * height + y]; unpackBits(r.bytes(len), out, y * width, width); }
      planes.push(out);
    }
  } else throw new PSDError('psd-compression');
  for (let i = 0; i < n; i++) {
    composite[i * 4] = planes[0][i]; composite[i * 4 + 1] = planes[1]?.[i] ?? planes[0][i];
    composite[i * 4 + 2] = planes[2]?.[i] ?? planes[0][i]; composite[i * 4 + 3] = channels > 3 ? planes[3][i] : 255;
  }
  return { width, height, layers: flat, composite };
}

/** החתימה בתחילת הקובץ */
export const isPSD = bytes => bytes.length >= 4 && bytes[0] === 0x38 && bytes[1] === 0x42 && bytes[2] === 0x50 && bytes[3] === 0x53;
