// בדיקות image-io ב-node: node --test creative-suite/image-io/tests/image-io.test.mjs
// הקידוד בדפדפן (canvas.js) נבדק בבדיקות הדפדפן של האפליקציות — אין OffscreenCanvas ב-node.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { inflateSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as IO from '../src/js/index.js';

const here = dirname(fileURLToPath(import.meta.url));
const core = join(here, '../../mbe-core');

// 2×2: אדום, ירוק שקוף-למחצה, כחול, שקוף לגמרי
const RGBA = new Uint8Array([255, 0, 0, 255, 0, 255, 0, 128, 0, 0, 255, 255, 10, 20, 30, 0]);

/** פענוח PNG שלנו (8 ביט RGBA, מסנן Sub) — כדי לוודא שהבתים יוצאים בדיוק כמו שנכנסו */
function decodeOurPng(b) {
  const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
  let o = 8, w, h, idat = [];
  const types = [];
  while (o < b.length) {
    const len = dv.getUint32(o), type = String.fromCharCode(...b.subarray(o + 4, o + 8));
    types.push(type);
    const data = b.subarray(o + 8, o + 8 + len);
    assert.equal(dv.getUint32(o + 8 + len), IO.crc32(b.subarray(o + 4, o + 8 + len)), `CRC של ${type}`);
    if (type === 'IHDR') { w = dv.getUint32(o + 8); h = dv.getUint32(o + 12); }
    if (type === 'IDAT') idat.push(data);
    o += 12 + len;
  }
  const raw = inflateSync(Buffer.concat(idat));
  const out = new Uint8Array(w * h * 4);
  for (let y = 0; y < h; y++) {
    const r = y * (w * 4 + 1);
    assert.equal(raw[r], 1, 'מסנן Sub');
    for (let i = 0; i < w * 4; i++) out[y * w * 4 + i] = (raw[r + 1 + i] + (i >= 4 ? out[y * w * 4 + i - 4] : 0)) & 0xff;
  }
  return { w, h, rgba: out, types };
}

test('PNG — הבתים חוזרים בדיוק, גם בפיקסלים שקופים-למחצה', async () => {
  const png = await IO.encodePNG(RGBA, 2, 2);
  assert.equal(IO.sniff(png), 'png');
  const d = decodeOurPng(png);
  assert.deepEqual([d.w, d.h], [2, 2]);
  assert.deepEqual([...d.rgba], [...RGBA]);
  assert.deepEqual(d.types, ['IHDR', 'sRGB', 'IDAT', 'IEND']);
});

test('PNG — stamp רץ על הקובץ המוכן', async () => {
  const seen = [];
  const out = await IO.encodePNG(RGBA, 2, 2, { stamp: b => { seen.push(b.length); return new Uint8Array([...b, 0]); } });
  assert.equal(seen.length, 1);
  assert.equal(out.length, seen[0] + 1);
});

test('PNG — "פנים" עם stampFace של הליבה: identify מזהה, הפיקסלים לא נגעו', async () => {
  const M = await import(join(core, 'src/js/mbe.js'));
  const { stampFace, identify } = await import(join(core, 'src/js/face.js'));
  const f = await M.open(new Uint8Array(readFileSync(join(core, 'tests/fixtures/valid/raster.mbe'))));
  await M.save(f, { app: 'image-io-test', now: new Date('2026-11-02T10:00:00Z') });
  const png = await IO.encodePNG(RGBA, 2, 2, { stamp: b => stampFace(f, 'png', b) });
  const id = await identify(png);
  assert.deepEqual([id.type, id.format, id.docId, id.base], ['face', 'png', f.id, f.documentSha256]);
  const d = decodeOurPng(png);
  assert.ok(d.types.includes('iTXt'), 'החותמת — iTXt');
  assert.deepEqual([...d.rgba], [...RGBA]);
});

test('sha256Hex — כמו node:crypto', async () => {
  const { createHash } = await import('node:crypto');
  assert.equal(await IO.sha256Hex(RGBA), createHash('sha256').update(RGBA).digest('hex'));
});

test('TIFF — כותרת, תגים ו-RGBA לא דחוס', () => {
  const t = IO.encodeTIFF(RGBA, 2, 2);
  assert.equal(IO.sniff(t), 'tiff');
  assert.deepEqual([...t.subarray(0, 4)], [0x49, 0x49, 0x2a, 0x00]);
  const s = Buffer.from(t);
  assert.ok(s.indexOf(Buffer.from(RGBA)) > 0, 'הפיקסלים כמו שהם, ברצועה אחת');
});

test('PSD — כתיבה וקריאה בשכבות ובקבוצות, שמות בעברית', () => {
  const layer = (name, x) => ({ name, x, y: 0, w: 2, h: 2, rgba: RGBA.slice(), opacity: 0.5, visible: true, blend: 'multiply' });
  const tree = [layer('רקע', 0), { type: 'group', name: 'קבוצה', opacity: 1, visible: false, blend: 'normal', children: [layer('בפנים', 1)] }];
  const psd = IO.encodePSD({ width: 3, height: 2, layers: tree, composite: new Uint8Array(3 * 2 * 4) });
  assert.equal(IO.sniff(psd), 'psd');
  assert.ok(IO.isPSD(psd));
  const back = IO.decodePSD(psd);
  assert.deepEqual([back.width, back.height], [3, 2]);
  assert.equal(back.layers[0].name, 'רקע');
  assert.equal(back.layers[0].blend, 'multiply');
  assert.equal(back.layers[1].type, 'group');
  assert.equal(back.layers[1].visible, false);
  assert.equal(back.layers[1].children[0].name, 'בפנים');
  assert.deepEqual([...back.layers[1].children[0].rgba], [...RGBA]);
  assert.throws(() => IO.decodePSD(new Uint8Array([1, 2, 3, 4])), e => e instanceof IO.PSDError && e.code === 'psd-signature' && e.message.startsWith('image-io: ') && /[א-ת]/.test(e.message));
});

test('ZIP — zipStore ואחריו unzip: אותם קבצים, mimetype ראשון', async () => {
  const z = IO.zipStore([{ name: 'mimetype', data: 'image/openraster' }, { name: 'data/א.png', data: RGBA }]);
  assert.equal(IO.sniff(z), 'zip');
  assert.equal(String.fromCharCode(...z.subarray(30, 38)), 'mimetype');
  const files = await IO.unzip(z);
  const get = n => (files instanceof Map ? files.get(n) : files[n] ?? files.find?.(e => e.name === n)?.data);
  assert.equal(new TextDecoder().decode(get('mimetype')), 'image/openraster');
  assert.deepEqual([...get('data/א.png')], [...RGBA]);
});

test('unzip — נתיב עוין נדחה', async () => {
  const z = IO.zipStore([{ name: '../evil.txt', data: 'x' }]);
  await assert.rejects(IO.unzip(z));
});

test('ORA — stack.xml הלוך וחזור: עץ, קבוצות, מיזוג, הסתרה, נעילה, תווים מיוחדים', () => {
  const tree = [
    { name: 'רקע', src: 'data/0.png', opacity: 1, visible: true, blend: 'normal', locked: true },
    { type: 'group', name: 'A & "B"', opacity: 0.5, visible: false, blend: 'screen', locked: false, children: [
      { name: '<x>', src: 'data/1.png', opacity: 0.25, visible: true, blend: 'multiply', locked: false },
    ] },
  ];
  const back = IO.parseOraStack(IO.oraStack(10, 20, tree));
  assert.deepEqual([back.w, back.h], [10, 20]);
  assert.equal(back.layers[0].name, 'רקע');
  assert.equal(back.layers[0].locked, true);
  assert.equal(back.layers[1].name, 'A & "B"');
  assert.equal(back.layers[1].blend, 'screen');
  assert.equal(back.layers[1].visible, false);
  assert.equal(back.layers[1].children[0].name, '<x>');
  assert.equal(back.layers[1].children[0].opacity, 0.25);
});

test('sniff — כל הסוגים לפי הבתים, ולא מוכר → null', () => {
  const b = (...parts) => new Uint8Array(parts.flatMap(p => (typeof p === 'string' ? [...p].map(c => c.charCodeAt(0)) : p)));
  assert.equal(IO.sniff(b([0xff, 0xd8, 0xff, 0xe0])), 'jpeg');
  assert.equal(IO.sniff(b('GIF89a')), 'gif');
  assert.equal(IO.sniff(b('RIFF', [0, 0, 0, 0], 'WEBPVP8 ')), 'webp');
  assert.equal(IO.sniff(b([0, 0, 0, 0x1c], 'ftypavif')), 'avif');
  assert.equal(IO.sniff(b([0, 0, 0, 0x18], 'ftypheic')), 'heic');
  assert.equal(IO.sniff(b([0x4d, 0x4d, 0x00, 0x2a])), 'tiff');
  assert.equal(IO.sniff(b('BM')), 'bmp');
  assert.equal(IO.sniff(b('hello')), null);
  assert.equal(IO.sniff(new Uint8Array(0)), null);
  for (const k of ['png', 'jpeg', 'webp', 'psd', 'tiff']) assert.ok(IO.MIME[k]);
});
