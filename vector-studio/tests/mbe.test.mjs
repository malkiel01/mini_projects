// ‎.mbe דרך הליבה: node --test vector-studio/tests/mbe.test.mjs
// הקובץ נבדק גם בליבת ה-PHP (Mbe::validate) — אותם כללים, שפה אחרת.

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import * as opentype from '../assets/js/vendor/opentype.min.mjs';
import { buildMbe, readMbe } from '../assets/js/mbefile.js';
import { open, create, save, PREVIEW } from '../assets/js/mbe/mbe.js';
import { newDocument, setShape } from '../assets/js/model.js';
import { relayout } from '../assets/js/text.js';

const FONTS = new URL('../assets/fonts/', import.meta.url);
const fontBytes = async (file) => new Uint8Array(fs.readFileSync(new URL(file, FONTS)));
const getFont = async (file) => { const b = await fontBytes(file); return opentype.parse(b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength)); };
// PNG 1×1 תקין
const PNG = Uint8Array.from(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
const preview = async () => PNG;

function sampleDoc() {
  const doc = newDocument([300, 200]);
  const L = doc.surfaces[0].layers[0];
  const r = { id: 'p1', d: '', fill: { gradient: { type: 'linear', from: [0, 0], to: [100, 0], stops: [{ at: 0, color: '#ffffff' }, { at: 1, color: '#2d4a8a' }] } }, stroke: null };
  setShape(r, { type: 'rect', box: [10, 10, 100, 60], radius: 6 });
  const t = { id: 't1', type: 'text', text: 'שלום', box: [280, 100, 0, 0], font: { family: 'Heebo', size: 30, weight: 700 }, align: 'right', lineHeight: 1.25, auto: true, fill: { color: '#16192b' } };
  relayout(t);
  L.paths.push(r, { id: 'g1', type: 'group', children: [t] });
  return doc;
}

function phpValidate(bytes) {
  const f = path.join(os.tmpdir(), `vs-${process.pid}.mbe`);
  fs.writeFileSync(f, bytes);
  try {
    const lib = new URL('../lib/mbe/mbe.php', import.meta.url).pathname;
    return JSON.parse(execFileSync('php', ['-r', `require '${lib}'; echo json_encode(Mbe::validate(file_get_contents($argv[1])));`, f], { encoding: 'utf8' }));
  } finally { fs.unlinkSync(f); }
}

test('בנייה: kind vector, גופן מוטמע, שלושה ייצוגים עם gaps — ועובר את הליבה בשתי השפות', async () => {
  const { bytes, gaps } = await buildMbe({ doc: sampleDoc(), getFont, fontBytes, preview });
  assert.deepEqual(gaps, ['text → outlines']);
  const f = await open(bytes);
  assert.equal(f.ok, true, JSON.stringify(f.issues));
  assert.equal(f.kind, 'vector');
  assert.equal(f.manifest.created.app, 'vector-studio');
  const fonts = f.document.fonts;
  assert.equal(fonts.length, 1);
  assert.equal(fonts[0].family, 'Heebo');
  assert.match(fonts[0].embedded, /^media\/[0-9a-f]{64}\.ttf$/);
  assert.ok(f.getMedia(fonts[0].embedded).length > 50000);
  const r = Object.fromEntries(f.renditions().map(x => [x.path, x]));
  assert.ok(r[PREVIEW] && r['renditions/drawing.svg'] && r['renditions/document.pdf']);
  assert.ok(Object.values(r).every(x => !x.stale), 'כל הייצוגים עדכניים');
  assert.deepEqual(r['renditions/document.pdf'].gaps, ['text → outlines']);
  assert.match(new TextDecoder().decode(r['renditions/drawing.svg'].data), /<linearGradient/);
  assert.deepEqual(phpValidate(bytes).filter(i => i.level === 'error'), []);
});

test('פתיחה: המסמך חוזר כמו שהוא; שמירה חוזרת שומרת חלקים של אחרים בית-לבית', async () => {
  // קובץ "של אפליקציה אחרת": שכבת רסטר עם תמונה, מידע פרטי של אפליקציה אחרת, ושדה לא מוכר
  const other = create('vector', { units: 'pt', color: { space: 'sRGB' }, custom: { keep: true }, surfaces: [] });
  const img = await other.addMedia(PNG, 'image/png');
  other.document.surfaces.push({ id: 's1', type: 'artboard', size: [300, 200], layers: [
    { id: 'R1', type: 'raster', name: 'רקע', src: img },
    { id: 'L1', type: 'vector', name: 'שכבה', paths: [{ id: 'p1', d: 'M0 0 L10 0 L10 10 Z', fill: { color: '#000000' } }] },
  ] });
  other.setApp('raster-studio', 'state.json', new TextEncoder().encode('{"zoom":2}'));
  other.setRendition(PREVIEW, PNG);
  const base = await save(other, { app: 'raster-studio' });

  const { doc, notes } = await readMbe(base);
  assert.deepEqual(notes, ['שכבות raster — לא מוצגות כאן, ונשמרות כמו שהן']);
  assert.deepEqual(doc.custom, { keep: true });
  doc.surfaces[0].layers[1].paths.push({ id: 'p2', d: 'M20 20 L30 20 L30 30 Z', fill: { color: '#2d4a8a' } });

  const { bytes } = await buildMbe({ doc, base, getFont, fontBytes, preview });
  const f = await open(bytes);
  assert.equal(f.ok, true);
  assert.equal(new TextDecoder().decode(f.apps('raster-studio').get('state.json')), '{"zoom":2}');
  assert.ok(f.getMedia(img), 'התמונה של שכבת הרסטר נשארה');
  assert.equal(f.document.surfaces[0].layers[0].src, img);
  assert.equal(f.document.surfaces[0].layers[1].paths.length, 2);
  assert.equal(f.manifest.created.app, 'raster-studio');
  assert.equal(f.manifest.modified.app, 'vector-studio');
  assert.equal(f.document.fonts, undefined, 'בלי טקסט — בלי גופנים');
  assert.deepEqual(phpValidate(bytes).filter(i => i.level === 'error'), []);
});

test('קובץ פגום — הודעה ברורה, לא קריסה', async () => {
  await assert.rejects(readMbe(new Uint8Array([1, 2, 3])), /./);
});
