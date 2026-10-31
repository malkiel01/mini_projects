// בדיקות ‎.mbe בדפדפן (הורדה ופתיחה דרך הליבה): node --test layout-studio/tests/mbe.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { open, create, save, PREVIEW } from '../assets/js/mbe/mbe.js';
import { buildMbe, mediaRefs, readTextMbe, textReportLines, isMbe } from '../assets/js/mbe-io.js';

// PNG אמיתי של פיקסל אחד (הליבה בודקת preview)
const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), c => c.charCodeAt(0));
const sha = async b => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', b)), x => x.toString(16).padStart(2, '0')).join('');
const imgPath = 'media/' + await sha(png) + '.png';

const model = () => ({
  units: 'pt', color: { space: 'sRGB' },
  layout: { facing: true, binding: 'right', margins: { top: 40, bottom: 40, inside: 50, outside: 30 }, numbering: { start: 1, style: 'arabic' } },
  surfaces: [
    { id: 'p1', type: 'page', size: [400, 600], master: 'mA', layers: [
      { id: 'fr1', type: 'frame', box: [30, 40, 320, 500], flow: 'f1' },
      { id: 'i1', type: 'frame', box: [30, 40, 100, 100], src: imgPath, px: [1, 1], fit: 'contain', focus: [0.5, 0.5], zoom: 1, wrap: 'none', wrapGap: 8 }] },
    { id: 'mA', type: 'master', name: 'A', size: [400, 600], layers: [], running: { folio: { position: 'bottom', align: 'outside', size: 10 }, header: null } }],
  flows: { f1: { engine: 'prosemirror', frames: ['fr1'], doc: { type: 'doc', content: [{ type: 'paragraph', attrs: { id: 'pa1' }, content: [{ type: 'text', text: 'שלום' }] }] } } },
});

test('הפניות למשאבים — בכל עומק, src ו-embedded', () => {
  const m = model();
  m.fonts = [{ family: 'X', embedded: 'media/' + 'b'.repeat(64) + '.ttf' }];
  assert.deepEqual([...mediaRefs(m)].sort(), [imgPath, 'media/' + 'b'.repeat(64) + '.ttf'].sort());
});

test('הורדה בלי מקור: עימוד תקין, המסמך כמו המודל, התמונה מהשרת, preview ו-PDF', async () => {
  const fetched = [];
  const { bytes, file } = await buildMbe({ model: model(), fetchMedia: async p => { fetched.push(p); return png; },
    preview: png, pdf: new TextEncoder().encode('%PDF-1.7 test') });
  assert.ok(isMbe(bytes));
  assert.deepEqual(fetched, [imgPath]);
  const f = await open(bytes);
  assert.ok(f.ok, JSON.stringify(f.issues));
  assert.equal(f.kind, 'layout');
  assert.match(f.id, /^[0-9a-f-]{36}$/);
  assert.deepEqual(f.document, model());
  assert.deepEqual(f.media().map(m => m.path), [imgPath]);
  const r = f.renditions();
  assert.deepEqual(r.map(x => [x.path, x.stale]), [['renditions/document.pdf', false], [PREVIEW, false]]);
  assert.equal(f.manifest.modified.app, 'layout-studio');
  assert.equal(file.id, f.id);
});

test('הורדה מקובץ מקור: המזהה, apps של אחרים וחלקים לא מוכרים נשמרים; משאב שיצא משימוש — נמחק', async () => {
  // קובץ מקור: עם apps/ של אפליקציה אחרת, ומשאב ישן שהמסמך כבר לא מפנה אליו
  const src = create('layout', model());
  await src.addMedia(png, 'image/png');
  const old = await src.addMedia(new TextEncoder().encode('old bytes'), 'image/webp');
  src.document.surfaces[0].layers.push({ id: 'i2', type: 'frame', box: [0, 0, 10, 10], src: old });
  src.setApp('raster-studio', 'view.json', new TextEncoder().encode('{"z":2}'));
  src.setRendition(PREVIEW, png, { type: 'image/png' });
  src.setRendition('renditions/document.pdf', new TextEncoder().encode('%PDF old'), { type: 'application/pdf' });
  const srcBytes = await save(src, { app: 'other-app' });
  const first = await open(srcBytes);

  const m = model();
  m.flows.f1.doc.content[0].content[0].text = 'שלום עולם';
  const { bytes } = await buildMbe({ model: m, source: srcBytes, fetchMedia: async () => { throw new Error('לא אמור לבקש'); }, preview: png });
  const f = await open(bytes);
  assert.ok(f.ok, JSON.stringify(f.issues));
  assert.equal(f.id, first.id);
  assert.deepEqual(f.manifest.created, first.manifest.created);
  assert.equal(new TextDecoder().decode(f.apps('raster-studio').get('view.json')), '{"z":2}');
  assert.deepEqual(f.media().map(x => x.path), [imgPath]);   // old — נמחק
  assert.deepEqual(f.renditions().map(x => x.path), [PREVIEW]);   // PDF ישן בלי חדש — יוצא, לא נשאר מיושן
  assert.equal(f.document.flows.f1.doc.content[0].content[0].text, 'שלום עולם');
});

test('טקסט מ-‎.mbe של עורך התמלילים: השטף, מזהי פסקה, ודוח', async () => {
  const { doc, kind, report } = await readTextMbe(readFileSync(new URL('./fixtures/text.mbe', import.meta.url)));
  assert.equal(kind, 'text');
  assert.equal(doc.content[0].content[0].text, 'שלום עולם — מסמך טקסט בעברית, ו-English באמצע.');
  assert.match(doc.content[0].attrs.id, /^[A-Za-z0-9_-]{1,32}$/);
  assert.equal(report.paragraphs, 1);
  assert.deepEqual(textReportLines(report), []);
});

test('טקסט מ-‎.mbe: רשימה ותמונה בטקסט — בדוח; קובץ שאינו ‎.mbe — not_mbe', async () => {
  const f = create('text', { units: 'pt', surfaces: [], flows: { a: { engine: 'prosemirror', doc: { type: 'doc', content: [
    { type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'פריט' }] }] }] },
    { type: 'paragraph', content: [{ type: 'image', attrs: { src: imgPath } }, { type: 'text', text: 'אחרי' }] }] } } } });
  await f.addMedia(png, 'image/png');
  f.setRendition(PREVIEW, png, { type: 'image/png' });
  const { doc, report } = await readTextMbe(await save(f, { app: 'word-processor' }));
  assert.equal(doc.content.length, 2);
  const lines = textReportLines(report);
  assert.ok(lines.some(l => l.includes('רשימות תבליטים')));
  assert.ok(lines.some(l => l.includes('תמונות בתוך הטקסט')));
  await assert.rejects(readTextMbe(new TextEncoder().encode('not a zip at all')), /not_mbe|mbe_invalid/);
});

import { readHistory, historyVersion } from '../assets/js/mbe-io.js';

test('יומן השלבים: create בהורדה הראשונה (הורדות רצופות שלנו — אותו שלב, כמו בליבה), שחזור נרשם כ-restore', async () => {
  const { bytes: b1 } = await buildMbe({ model: model(), fetchMedia: async () => png, preview: png, now: new Date('2026-10-31T10:00:00Z') });
  const m2 = model(); m2.flows.f1.doc.content[0].content[0].text = 'שני';
  const { bytes: b2 } = await buildMbe({ model: m2, source: b1, fetchMedia: async () => png, preview: png, now: new Date('2026-10-31T11:00:00Z') });
  let h = await readHistory(b2);
  assert.deepEqual(h.map(s => s.kind), ['create']);   // אותה אפליקציה ברצף — השלב מתעדכן, לא נוסף
  assert.equal(h[0].appLabel, 'עימוד');
  assert.ok(h[0].available);
  assert.equal((await historyVersion(b2, h[0].sha)).flows.f1.doc.content[0].content[0].text, 'שני');
  // שחזור: מודל + שלב restore עם הערה — נוסף שלב, והגרסה הקודמת נשארת לשחזור
  const { bytes: b3 } = await buildMbe({ model: model(), source: b2, fetchMedia: async () => png, preview: png,
    stages: [{ kind: 'restore', note: 'שוחזר מ-' + h[0].id }], now: new Date('2026-10-31T12:00:00Z') });
  h = await readHistory(b3);
  assert.deepEqual(h.map(s => s.kind), ['restore', 'create']);
  assert.equal(h[0].kindLabel, 'שוחזר');
  assert.match(h[0].note, /שוחזר מ-s1/);
  assert.ok(h[1].available);
});
