// פרוסה 04 — בדיקות ל-assets/js/field-core.js: W6 (הקטנה ל-2,560, ה-GPS נשמר וכל השאר נמחק), W3/W7/W8 בצד התור.
// רץ מ-tests/run.sh: node tests/field.test.js
'use strict';
const assert = require('assert');
const path = require('path');
const W = require(path.join(__dirname, '../assets/js/field-core.js'));
const F = require(path.join(__dirname, '../assets/js/form.js'));

/** JPEG מדומה עם EXIF: IFD0 עם Make ("SecretCam") ו-GPSInfo → GPS IFD (רוחב/אורך כרציונלים). le — סדר הבתים. */
function jpegWithExif(le) {
  const tiff = new Uint8Array(200); const dv = new DataView(tiff.buffer);
  tiff[0] = tiff[1] = le ? 0x49 : 0x4D; dv.setUint16(2, 42, le); dv.setUint32(4, 8, le);
  dv.setUint16(8, 2, le);
  // Make (0x010F), ASCII, 10 → בהיסט 100
  dv.setUint16(10, 0x010F, le); dv.setUint16(12, 2, le); dv.setUint32(14, 10, le); dv.setUint32(18, 100, le);
  tiff.set([...Buffer.from('SecretCam\0')], 100);
  dv.setUint16(22, 0x8825, le); dv.setUint16(24, 4, le); dv.setUint32(26, 1, le); dv.setUint32(30, 40, le);
  dv.setUint32(34, 0, le);
  // GPS IFD ב-40: LatitudeRef 'N', Latitude 3 רציונלים ב-120, LongitudeRef 'E', Longitude ב-150
  dv.setUint16(40, 4, le);
  const ent = (i, tag, type, count, v) => { const a = 42 + i * 12; dv.setUint16(a, tag, le); dv.setUint16(a + 2, type, le); dv.setUint32(a + 4, count, le); v(a + 8); };
  ent(0, 1, 2, 2, a => { tiff[a] = 0x4E; });
  ent(1, 2, 5, 3, a => dv.setUint32(a, 120, le));
  ent(2, 3, 2, 2, a => { tiff[a] = 0x45; });
  ent(3, 4, 5, 3, a => dv.setUint32(a, 150, le));
  dv.setUint32(42 + 48, 0, le);
  [[31, 1], [46, 1], [3012, 100], [35, 1], [12, 1], [4511, 100]].forEach(([n, d], i) => {
    const at = i < 3 ? 120 + i * 8 : 150 + (i - 3) * 8; dv.setUint32(at, n, le); dv.setUint32(at + 4, d, le);
  });
  const len = 2 + 6 + tiff.length;
  return Uint8Array.from([0xFF, 0xD8, 0xFF, 0xE1, len >> 8, len & 0xFF, 0x45, 0x78, 0x69, 0x66, 0, 0, ...tiff, 0xFF, 0xDA, 0, 2, 0xFF, 0xD9]);
}
const has = (u8, s) => Buffer.from(u8).includes(Buffer.from(s));
// מקודד מדומה: JPEG עם JFIF, בלי EXIF — כמו canvas.toBlob
const encoded = [];
const deps = {
  readBytes: async b => new Uint8Array(await b.arrayBuffer()),
  decode: async b => ({width: b.w || 4000, height: b.h || 3000}),
  encode: async (img, w, h, q) => { encoded.push({w, h, q}); return Uint8Array.from([0xFF, 0xD8, 0xFF, 0xE0, 0, 16, 0x4A, 0x46, 0x49, 0x46, 0, 1, 1, 0, 0, 1, 0, 1, 0, 0, 0xFF, 0xD9]); },
  makeBlob: (u8, type) => new Blob([u8], {type}),
};

(async () => {
  // ---- W6: 4,000 פיקסלים → 2,560 לכל היותר, ביחס המקורי
  assert.deepStrictEqual(W.fitSize(4000, 3000), {width: 2560, height: 1920, scaled: true}, 'W6: 4000x3000');
  assert.deepStrictEqual(W.fitSize(3000, 4000), {width: 1920, height: 2560, scaled: true}, 'W6: לאורך');
  assert.deepStrictEqual(W.fitSize(2560, 100), {width: 2560, height: 100, scaled: false}, 'W6: בדיוק 2,560 — לא משתנה');
  for (const le of [true, false]) {
    encoded.length = 0;
    const src = jpegWithExif(le);
    assert.ok(has(src, 'SecretCam'), 'המקור מכיל Make');
    const blob = new Blob([src], {type: 'image/jpeg'}); blob.w = 4000; blob.h = 3000;
    const r = await W.prepareImage(blob, deps);
    assert.ok(r.changed && r.width <= 2560 && r.height <= 2560 && r.width === 2560, 'W6: יוצא ב-2,560 לכל היותר');
    assert.deepStrictEqual(encoded[0], {w: 2560, h: 1920, q: 0.85}, 'W6: JPEG 85%');
    const out = new Uint8Array(await r.blob.arrayBuffer());
    assert.strictEqual(r.blob.type, 'image/jpeg');
    assert.ok(!has(out, 'SecretCam'), 'W6: שאר ה-EXIF נמחק');
    const a = W.readExifGps(src), b = W.readExifGps(out);
    assert.ok(r.gps && b, 'W6: ה-GPS נשמר');
    assert.deepStrictEqual(b.entries.map(e => [e.tag, e.type, e.count, [...e.bytes]]), a.entries.map(e => [e.tag, e.type, e.count, [...e.bytes]]), 'W6: אותו מיקום בדיוק (' + (le ? 'II' : 'MM') + ')');
    assert.ok(out[0] === 0xFF && out[1] === 0xD8 && out[2] === 0xFF && out[3] === 0xE0, 'JFIF נשאר ראשון, EXIF אחריו');
  }
  // תמונה קטנה בלי GPS — מקודדת מחדש (בלי EXIF), בלי הקטנה
  { const b = new Blob([Uint8Array.from([0xFF, 0xD8, 0xFF, 0xD9])], {type: 'image/jpeg'}); b.w = 800; b.h = 600;
    const r = await W.prepareImage(b, deps); assert.ok(r.changed && r.width === 800 && !r.gps, 'JPEG קטן — בלי הקטנה, בלי GPS'); }
  // PDF ו-PNG קטן — כמו שהם; תמונה שלא מתפענחת (HEIC) — כמו שהיא
  { const pdf = new Blob(['%PDF'], {type: 'application/pdf'}); assert.strictEqual((await W.prepareImage(pdf, deps)).blob, pdf, 'PDF לא נוגעים');
    const png = new Blob([Uint8Array.from([0x89, 0x50])], {type: 'image/png'}); png.w = 500; png.h = 500; assert.strictEqual((await W.prepareImage(png, deps)).blob, png, 'PNG קטן לא נוגעים');
    const heic = new Blob(['x'], {type: 'image/heic'}); assert.strictEqual((await W.prepareImage(heic, {...deps, decode: async () => { throw new Error('x'); }})).blob, heic, 'HEIC — כמו שהוא'); }
  assert.strictEqual(W.readExifGps(Uint8Array.from([1, 2, 3])), null, 'לא JPEG → null');
  assert.strictEqual(W.readExifGps(jpegWithExif(true).slice(0, 40)), null, 'EXIF קטוע → null, בלי חריגה');

  // ---- התור
  assert.match(W.uploadId(), /^up_[0-9a-f]{32}$/, 'מזהה חד-פעמי');
  assert.notStrictEqual(W.uploadId(), W.uploadId(), 'מזהים שונים');
  let t = 1000, online = false, dropbox = false;
  const server = new Map(); // upload_id → file (השרת: אותו מזהה → אותו קובץ)
  const sent = [];
  const send = async it => {
    if (!online) throw new TypeError('Failed to fetch');
    sent.push(it.id);
    if (!dropbox) return {status: 409, body: {error: 'Dropbox של הארגון לא מחובר — פנה למנהל', code: 'dropbox_not_connected'}};
    if (!server.has(it.id)) server.set(it.id, {file_id: server.size + 1, name: it.name, link: 'api.php?action=file_view&id=' + (server.size + 1)});
    return {status: 200, body: {ok: true, file: server.get(it.id)}};
  };
  const store = W.memoryStore();
  const changes = [];
  const q = W.createQueue({store, send, now: () => t, onChange: i => changes.push(i.state), owner: 'u1'});

  // W8 (החלק האוטומטי): בלי רשת → "ממתין לשליחה", והקובץ נשאר במכשיר
  const it = await q.add({case_id: 'fd_a', name: 'קבר.jpg', kind: 'grave_photo', blob: new Blob(['x'], {type: 'image/jpeg'})});
  assert.strictEqual((await q.list())[0].state, 'pending');
  await q.flush();
  let cur = (await q.list())[0];
  assert.ok(cur.state === 'pending' && cur.message.startsWith('ממתין לשליחה') && cur.blob, 'W8: בלי רשת — ממתין, הקובץ בתור');
  assert.ok(cur.next > t, 'ניסיון חוזר מתוזמן');
  await q.flush(); assert.strictEqual(sent.length, 0, 'לא שולחים לפני הזמן');

  // W7: רשת חוזרת, Dropbox מנותק → הודעה ברורה, נשאר בתור
  online = true; t += 6e3; await q.flush();
  cur = (await q.list())[0];
  assert.ok(cur.state === 'blocked' && cur.message === 'Dropbox של הארגון לא מחובר — פנה למנהל' && cur.blob, 'W7: הודעה ברורה, בתור');

  // ניסיון חוזר ידני אחרי שהמנהל חיבר → "נשלח ✓" עם קישור, והקובץ יורד מהמכשיר
  dropbox = true; await q.retry(it.id); await q.flush();
  cur = (await q.list())[0];
  assert.ok(cur.state === 'done' && cur.file.link && cur.blob === null && cur.message === 'נשלח ✓', 'W8: נשלח ✓ עם קישור');
  assert.ok(sent.every(id => id === it.id), 'כל הנסיונות — באותו מזהה');

  // W3 בצד הלקוח: נקטע באמצע (sending) → נשלח שוב באותו מזהה, והשרת מחזיר את אותו קובץ
  const it2 = await q.add({case_id: 'fd_a', name: 'b.pdf', kind: 'license', blob: new Blob(['y'])});
  const s2 = (await q.list()).find(i => i.id === it2.id); s2.state = 'sending'; await store.put(s2);
  await q.flush();
  await store.put({...(await q.list()).find(i => i.id === it2.id), state: 'pending', next: 0});   // "שליחה כפולה"
  await q.flush();
  assert.strictEqual(server.size, 2, 'W3: שליחה כפולה עם אותו מזהה — קובץ אחד בשרת');
  assert.strictEqual(sent.filter(id => id === it2.id).length, 2);

  // שגיאה אחרת (415) → נכשל, בלי ניסיון אוטומטי; ביטול מוריד מהתור
  const bad = W.createQueue({store: W.memoryStore(), send: async () => ({status: 415, body: {error: 'סוג קובץ לא נתמך'}}), now: () => t});
  const b1 = await bad.add({case_id: 'fd_a', name: 'x.exe', blob: new Blob(['z'])});
  await bad.flush(); assert.strictEqual((await bad.list())[0].state, 'failed');
  assert.strictEqual((await bad.list())[0].message, 'סוג קובץ לא נתמך');
  assert.ok(await bad.cancel(b1.id)); assert.strictEqual((await bad.list()).length, 0, 'ביטול');
  // 5xx → ממתין עם ניסיון חוזר
  const five = W.createQueue({store: W.memoryStore(), send: async () => ({status: 502, body: {}}), now: () => t});
  await five.add({case_id: 'fd_a', blob: new Blob(['z'])}); await five.flush();
  assert.strictEqual((await five.list())[0].state, 'pending', '5xx → ממתין');
  // פריט של משתמש אחר במכשיר — לא נשלח
  const other = W.memoryStore(); await other.put({id: 'up_x', owner: 'u2', state: 'pending', next: 0, created: 0, tries: 0});
  let n = 0; await W.createQueue({store: other, send: async () => { n++; return {status: 200, body: {ok: true}}; }, owner: 'u1'}).flush();
  assert.strictEqual(n, 0, 'פריט של משתמש אחר לא נשלח');
  // "נשלח ✓" יורד אחרי יום
  t += 864e5 + 1; await q.flush(); assert.strictEqual((await q.list()).length, 0, 'נשלח ✓ יורד אחרי יום');

  // מה חסר מהשטח
  assert.deepStrictEqual(W.needsText({needs: ['grave_photo', 'grave'], checks: {}}, F), ['📷 צילום קבר', '📍 מיקום קבר', F.TOTAL + ' בדיקות פתוחות']);
  console.log('  ✓ פרוסה 04 — W6 (הקטנה ו-GPS), התור (W3, W7, W8 אוטומטי)');
})().catch(e => { console.error('FAIL:', e.message); process.exit(1); });
