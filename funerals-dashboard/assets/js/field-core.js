/*
 * פרוסה 04 — הליבה של עובד השטח, בלי DOM: הקטנת תמונה, שמירת מיקום ה-GPS מה-EXIF (וכל השאר נמחק), ותור ההעלאות.
 * אותו קוד רץ בדפדפן (window.FDField) ובבדיקות (node, module.exports) — tests/field.test.js.
 */
(function (root) {
  'use strict';
  const MAX_SIDE = 2560;
  const QUALITY = 0.85;

  /** גודל היעד: הצלע הארוכה לכל היותר max, ביחס המקורי. */
  function fitSize(w, h, max = MAX_SIDE) {
    w = Math.max(1, Math.round(w)); h = Math.max(1, Math.round(h));
    const long = Math.max(w, h);
    if (long <= max) return {width: w, height: h, scaled: false};
    const k = max / long;
    return {width: Math.max(1, Math.round(w * k)), height: Math.max(1, Math.round(h * k)), scaled: true};
  }

  const isJpeg = u8 => u8.length > 3 && u8[0] === 0xFF && u8[1] === 0xD8 && u8[2] === 0xFF;
  const TYPE_SIZE = {1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8};

  /**
   * ה-GPS IFD מתוך ה-EXIF של JPEG: {le, entries:[{tag, type, count, bytes}]} — הבתים כמו במקור (אותו סדר בתים),
   * או null כשאין. לא זורק על קובץ פגום — מחזיר null.
   */
  function readExifGps(u8) {
    try {
      if (!isJpeg(u8)) return null;
      let p = 2;
      while (p + 4 <= u8.length && u8[p] === 0xFF) {
        const marker = u8[p + 1];
        if (marker === 0xDA || marker === 0xD9) break;   // תחילת הנתונים — אין EXIF אחרי זה
        const len = (u8[p + 2] << 8) | u8[p + 3];
        if (marker === 0xE1 && len >= 8 && u8[p + 4] === 0x45 && u8[p + 5] === 0x78 && u8[p + 6] === 0x69 && u8[p + 7] === 0x66 && u8[p + 8] === 0 && u8[p + 9] === 0) {
          const t = p + 10, end = p + 2 + len;
          const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
          const le = u8[t] === 0x49;
          const u16 = o => dv.getUint16(t + o, le), u32 = o => dv.getUint32(t + o, le);
          const in_ = (o, n) => t + o + n <= end;
          let ifd = u32(4);
          if (!in_(ifd, 2)) return null;
          let gpsAt = -1;
          const n0 = u16(ifd);
          for (let i = 0; i < n0; i++) {
            const e = ifd + 2 + i * 12;
            if (!in_(e, 12)) return null;
            if (u16(e) === 0x8825) gpsAt = u32(e + 8);
          }
          if (gpsAt < 0 || !in_(gpsAt, 2)) return null;
          const n = u16(gpsAt), entries = [];
          for (let i = 0; i < n && i < 64; i++) {
            const e = gpsAt + 2 + i * 12;
            if (!in_(e, 12)) return null;
            const tag = u16(e), type = u16(e + 2), count = u32(e + 4), size = (TYPE_SIZE[type] || 0) * count;
            if (!size || size > 4096) continue;
            const off = size <= 4 ? e + 8 : u32(e + 8);
            if (!in_(off, size)) continue;
            entries.push({tag, type, count, bytes: u8.slice(t + off, t + off + size)});
          }
          return entries.length ? {le, entries} : null;
        }
        p += 2 + len;
      }
    } catch (e) { /* קובץ פגום — בלי מיקום */ }
    return null;
  }

  /** מקטע APP1 של EXIF עם GPS בלבד: IFD0 עם רשומה אחת (GPSInfo) → GPS IFD. באותו סדר בתים כמו במקור. */
  function buildExifApp1(gps) {
    const n = gps.entries.length;
    const gpsAt = 8 + 2 + 12 + 4;
    let dataAt = gpsAt + 2 + n * 12 + 4;
    const extra = gps.entries.reduce((s, e) => s + (e.bytes.length > 4 ? e.bytes.length + (e.bytes.length & 1) : 0), 0);
    const tiff = new Uint8Array(dataAt + extra);
    const dv = new DataView(tiff.buffer), le = gps.le;
    tiff[0] = tiff[1] = le ? 0x49 : 0x4D;
    dv.setUint16(2, 42, le); dv.setUint32(4, 8, le);
    dv.setUint16(8, 1, le);
    dv.setUint16(10, 0x8825, le); dv.setUint16(12, 4, le); dv.setUint32(14, 1, le); dv.setUint32(18, gpsAt, le);
    dv.setUint32(22, 0, le);
    dv.setUint16(gpsAt, n, le);
    gps.entries.forEach((e, i) => {
      const at = gpsAt + 2 + i * 12;
      dv.setUint16(at, e.tag, le); dv.setUint16(at + 2, e.type, le); dv.setUint32(at + 4, e.count, le);
      if (e.bytes.length <= 4) tiff.set(e.bytes, at + 8);
      else { dv.setUint32(at + 8, dataAt, le); tiff.set(e.bytes, dataAt); dataAt += e.bytes.length + (e.bytes.length & 1); }
    });
    dv.setUint32(gpsAt + 2 + n * 12, 0, le);
    const len = 2 + 6 + tiff.length;
    const app1 = new Uint8Array(2 + len);
    app1.set([0xFF, 0xE1, len >> 8, len & 0xFF, 0x45, 0x78, 0x69, 0x66, 0, 0]);
    app1.set(tiff, 10);
    return app1;
  }

  /** מכניס APP1 אחרי SOI (ואחרי APP0/JFIF אם יש). */
  function insertApp1(jpeg, app1) {
    let at = 2;
    if (jpeg[2] === 0xFF && jpeg[3] === 0xE0) at = 4 + ((jpeg[4] << 8) | jpeg[5]);
    const out = new Uint8Array(jpeg.length + app1.length);
    out.set(jpeg.subarray(0, at)); out.set(app1, at); out.set(jpeg.subarray(at), at + app1.length);
    return out;
  }

  /**
   * הכנת תמונה לשליחה. deps: readBytes(blob) → Uint8Array; decode(blob) → {width, height} (זורק אם הדפדפן לא מפענח);
   * encode(img, w, h, quality) → Uint8Array של JPEG (בלי EXIF). makeBlob(u8, type) → Blob.
   * JPEG — תמיד מקודד מחדש (כך כל ה-EXIF נמחק), ומוקטן מעל 2,560; מיקום ה-GPS מוחזר לקובץ. תמונה אחרת — רק אם גדולה.
   * לא תמונה, או תמונה שהדפדפן לא מפענח (HEIC) — נשלחת כמו שהיא.
   */
  async function prepareImage(blob, deps) {
    const type = (blob && blob.type) || '';
    if (!type.startsWith('image/') || type === 'image/svg+xml') return {blob, changed: false};
    const bytes = await deps.readBytes(blob);
    const jpeg = isJpeg(bytes);
    let img;
    try { img = await deps.decode(blob); } catch (e) { return {blob, changed: false}; }
    const size = fitSize(img.width, img.height);
    if (!jpeg && !size.scaled) return {blob, changed: false};
    let out = await deps.encode(img, size.width, size.height, QUALITY);
    const gps = jpeg ? readExifGps(bytes) : null;
    if (gps) out = insertApp1(out, buildExifApp1(gps));
    return {blob: deps.makeBlob(out, 'image/jpeg'), changed: true, width: size.width, height: size.height, gps: !!gps};
  }

  /* ---------- תור ההעלאות ---------- */

  const STATE_LABELS = {pending: 'ממתין לשליחה', sending: 'שולח…', done: 'נשלח ✓', blocked: 'בתור — לא נשלח', failed: 'נכשל', cancelled: 'בוטל'};
  const BACKOFF = [5e3, 15e3, 60e3, 120e3, 300e3];

  /** מזהה חד-פעמי להעלאה (גם בדפדפן בלי randomUUID). */
  function uploadId(rand) {
    const r = rand || (n => { const a = new Uint8Array(n); (root.crypto || require('crypto').webcrypto).getRandomValues(a); return a; });
    return 'up_' + Array.from(r(16), b => b.toString(16).padStart(2, '0')).join('');
  }

  /**
   * התור. store: {all(), put(item), del(id)} (IndexedDB בדפדפן, זיכרון בבדיקות). send(item) → {status, body}
   * (זורק כשאין רשת). now() → מספר. onChange() — אחרי כל שינוי. owner — מי המשתמש (פריט של משתמש אחר לא נשלח).
   */
  function createQueue({store, send, now = () => Date.now(), onChange = () => {}, owner = ''}) {
    let flushing = null;
    const save = async item => { await store.put(item); onChange(item); return item; };
    async function add(x) {
      const item = {id: x.id || uploadId(), owner, case_id: x.case_id, case_name: x.case_name || '', kind: x.kind || 'other',
        kind_label: x.kind_label || '', name: x.name || 'קובץ', type: x.type || (x.blob && x.blob.type) || '', size: x.size || (x.blob && x.blob.size) || 0,
        blob: x.blob, state: 'pending', tries: 0, next: 0, message: STATE_LABELS.pending, file: null, created: now()};
      return save(item);
    }
    async function cancel(id) {
      const all = await store.all(), it = all.find(i => i.id === id);
      if (!it || it.state === 'sending' || it.state === 'done') return false;
      await store.del(id); onChange({...it, state: 'cancelled'});
      return true;
    }
    async function retry(id) {
      const all = await store.all(), it = all.find(i => i.id === id);
      if (!it || it.state === 'done' || it.state === 'sending') return false;
      Object.assign(it, {state: 'pending', next: 0, message: STATE_LABELS.pending});
      await save(it);
      return true;
    }
    /** תוצאת שליחה → המצב הבא של הפריט. */
    function classify(it, res, err) {
      const t = now();
      const later = msg => Object.assign(it, {state: 'pending', next: t + BACKOFF[Math.min(it.tries - 1, BACKOFF.length - 1)], message: msg});
      if (err) return later('ממתין לשליחה — אין חיבור');
      const s = res.status, b = res.body || {};
      if (s >= 200 && s < 300 && b.ok) return Object.assign(it, {state: 'done', blob: null, file: b.file || null, check: b.check || null, message: STATE_LABELS.done, done_at: t});
      if (s === 409 && b.code === 'dropbox_not_connected') return Object.assign(it, {state: 'blocked', next: t + 300e3, message: b.error || 'Dropbox של הארגון לא מחובר — פנה למנהל'});
      if (s === 409 && b.code === 'in_progress') return later('נשלח כבר — מחכה לאישור');
      if (s === 0 || s === 401 || s === 408 || s === 429 || s >= 500) return later(s === 401 ? 'ממתין — צריך להתחבר מחדש' : 'ממתין לשליחה — השרת לא זמין');
      return Object.assign(it, {state: 'failed', message: b.error || ('השליחה נכשלה (' + s + ')')});
    }
    /** שולח את כל מה שהגיע זמנו. קריאה כפולה בזמן שליחה — מחכה לאותה ריצה. */
    function flush(force = false) {
      if (flushing) return flushing;
      flushing = (async () => {
        const all = (await store.all()).filter(i => (!owner || !i.owner || i.owner === owner)).sort((a, b) => a.created - b.created);
        for (const it of all) {
          if (it.state === 'sending' && !force) it.state = 'pending';   // נקטע באמצע (המסך נסגר) — שולחים שוב, באותו מזהה
          if (!['pending', 'blocked'].includes(it.state) || (!force && it.next > now())) continue;
          it.state = 'sending'; it.tries++; it.message = STATE_LABELS.sending;
          await save(it);
          let res = null, err = null;
          try { res = await send(it); } catch (e) { err = e; }
          classify(it, res, err);
          await save(it);
        }
        // "נשלח ✓" נשאר יום, ואז יורד מהמכשיר
        for (const it of await store.all()) if (it.state === 'done' && now() - (it.done_at || 0) > 864e5) await store.del(it.id);
      })().finally(() => { flushing = null; });
      return flushing;
    }
    return {add, cancel, retry, flush, classify, list: () => store.all()};
  }

  /** מחסן בזיכרון — לבדיקות, ולדפדפן בלי IndexedDB (אז התור לא שורד סגירה). */
  function memoryStore() {
    const m = new Map();
    return {all: async () => [...m.values()].map(x => ({...x})), put: async x => { m.set(x.id, {...x}); }, del: async id => { m.delete(id); }};
  }

  /** "מה חסר מהשטח" לתיק: צילום, מיקום קבר, ומספר הבדיקות שלא נסגרו (form.js). */
  function needsText(card, F) {
    const out = [];
    if ((card.needs || []).includes('grave_photo')) out.push('📷 צילום קבר');
    if ((card.needs || []).includes('grave')) out.push('📍 מיקום קבר');
    if (F) { const st = F.caseState(card); if (st.pending) out.push(st.pending + ' בדיקות פתוחות'); }
    return out;
  }

  const api = {MAX_SIDE, QUALITY, STATE_LABELS, fitSize, readExifGps, buildExifApp1, insertApp1, prepareImage, uploadId, createQueue, memoryStore, needsText};
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.FDField = api;
})(typeof self !== 'undefined' ? self : this);
