// בדיקות הייצוא: node --test vector-studio/tests/export.test.mjs
// ה-PDF נבדק גם ב-pdfinfo (poppler) אם הוא מותקן.

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import * as opentype from '../assets/js/vendor/opentype.min.mjs';
import { visualOrder, fontFile, textToD, loadFont } from '../assets/js/outline.js';
import { prepare, toSVG, toPDF, toPdfList } from '../assets/js/export.js';
import { newDocument, setShape } from '../assets/js/model.js';
import { relayout, setMeasure } from '../assets/js/text.js';
import { parse, bbox } from '../assets/js/geometry/path.js';

const FONT_DIR = new URL('../assets/fonts/', import.meta.url);
const cache = new Map();
async function getFont(file) {
  if (!cache.has(file)) {
    const b = fs.readFileSync(new URL(file, FONT_DIR));
    cache.set(file, loadFont(opentype, b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength)));
  }
  return cache.get(file);
}

test('סדר חזותי: עברית מתהפכת, לטינית וספרות נשארות, סוגריים מתחלפים', () => {
  assert.equal(visualOrder('שלום'), 'םולש');
  assert.equal(visualOrder('abc'), 'abc');
  assert.equal(visualOrder('גרסה 2.5 של PDF'), 'PDF לש 2.5 הסרג');
  assert.equal(visualOrder('(שלום)'), '(םולש)');
  assert.equal(visualOrder(''), '');
});

test('קובץ הגופן לפי עובי', () => {
  assert.deepEqual(fontFile('Heebo', 700), { file: 'Heebo.ttf', variable: true });
  assert.equal(fontFile('David Libre', 700).file, 'DavidLibre-Bold.ttf');
  assert.equal(fontFile('David Libre', 400).file, 'DavidLibre-Regular.ttf');
  assert.equal(fontFile('לא קיים', 400).file, 'Heebo.ttf');
});

function textDoc() {
  const doc = newDocument([400, 300]);
  const L = doc.surfaces[0].layers[0];
  const r = { id: 'p1', d: '', fill: { color: '#2d4a8a', opacity: 0.5 }, stroke: { color: '#000000', width: 2, cap: 'round', join: 'round', dash: [6, 4] } };
  setShape(r, { type: 'rect', box: [20, 20, 100, 60], radius: 8 });
  const t = { id: 't1', type: 'text', text: 'שלום PDF', box: [300, 150, 0, 0], font: { family: 'Heebo', size: 36, weight: 700 }, align: 'right', lineHeight: 1.25, auto: true, fill: { color: '#16192b' } };
  L.paths.push(r, { id: 'g1', type: 'group', children: [t] });
  return doc;
}

test('טקסט לקווי מתאר: הנתיב בתוך התיבה, מיושר לימין', async () => {
  // מדידה לפי הגופן עצמו — כמו שהקנבס מודד אחרי שהגופן נטען
  const heebo = await getFont('Heebo.ttf');
  setMeasure((s, f) => heebo.getAdvanceWidth(visualOrder(s), f.size, { variation: { wght: f.weight } }));
  const t = textDoc().surfaces[0].layers[0].paths[1].children[0];
  relayout(t);
  const d = await textToD(t, getFont);
  const b = bbox(parse(d));
  assert.ok(b, 'יש נתיב');
  assert.ok(Math.abs(b[0] + b[2] - (t.box[0] + t.box[2])) < 6, `הקצה הימני: ${b[0] + b[2]} מול ${t.box[0] + t.box[2]}`);
  assert.ok(b[1] >= t.box[1] - 2 && b[1] + b[3] <= t.box[1] + t.box[3] + 2, 'בתוך גובה השורה');
  assert.ok(!/[ql]/.test(d), 'מנורמל לפקודות מוחלטות');
});

test('SVG: נתיבים, סגנון, קבוצות, טקסט כנתיב עם תווית, ודוח פערים', async () => {
  const prep = await prepare(textDoc(), 's1', getFont);
  assert.deepEqual(prep.gaps, ['text → outlines']);
  const svg = toSVG(prep);
  assert.match(svg, /^<\?xml/);
  assert.match(svg, /width="400pt" height="300pt" viewBox="0 0 400 300"/);
  assert.match(svg, /<path id="p1" d="M28 20 L112 20 C/);
  assert.match(svg, /fill-opacity="0.5"/);
  assert.match(svg, /stroke-dasharray="6 4"/);
  assert.match(svg, /stroke-linecap="round"/);
  assert.match(svg, /<g id="g1">\s*<path id="t1" d="M[^"]+" fill="#16192b" aria-label="שלום PDF"\/>/);
  assert.doesNotMatch(svg, /<text/);
});

test('PDF: מבנה תקין, עמוד לכל לוח, שקיפות', async () => {
  const doc = textDoc();
  doc.surfaces.push({ id: 's2', type: 'artboard', name: 'לוח 2', size: [200, 100], layers: [] });
  const pages = [await prepare(doc, 's1', getFont), await prepare(doc, 's2', getFont)];
  // הרשימה ל-pdf-core: עמוד לכל לוח, בגודל שלו; שקיפות — fillOpacity
  const { list } = toPdfList(pages);
  assert.deepEqual(list.pages.map(p => p.size), [[400, 300], [200, 100]]);
  assert.ok(list.pages[0].ops.some(o => o.op === 'path' && o.fillOpacity === 0.5), 'שקיפות');
  assert.ok(list.pages[0].ops.every(o => /^[MLCQZ0-9 .\-]+$/.test(o.d || 'M')), 'רק פקודות מוחלטות');
  const pdf = await toPDF(pages);
  const s = Buffer.from(pdf).toString('latin1');
  assert.match(s, /^%PDF-1\.\d/);
  assert.match(s, /\/Count 2/);
  await pdfCheck(pdf);
  let info = null;
  try {
    const f = path.join(os.tmpdir(), `vs-${process.pid}.pdf`);
    fs.writeFileSync(f, pdf);
    info = execFileSync('pdfinfo', [f], { encoding: 'utf8' });
    fs.unlinkSync(f);
  } catch (e) { if (e.code !== 'ENOENT') throw e; }
  if (info) assert.match(info, /Pages:\s+2/);
});

import { endpoints, angleOf, makeGradient } from '../assets/js/gradient.js';

test('מעבר צבע: נקודות לפי זווית, ייצוא ל-SVG ול-PDF (Pattern)', async () => {
  assert.deepEqual(endpoints([0, 0, 100, 50], 0), { from: [0, 25], to: [100, 25] });
  assert.deepEqual(endpoints([0, 0, 100, 50], 90), { from: [50, 0], to: [50, 50] });
  assert.equal(angleOf(makeGradient([0, 0, 10, 10], 45, '#000000', '#ffffff')), 45);
  const doc = textDoc();
  const r = doc.surfaces[0].layers[0].paths[0];
  r.fill = { gradient: makeGradient([20, 20, 100, 60], 0, '#ffffff', '#2d4a8a') };
  r.fill.gradient.stops.splice(1, 0, { at: 0.5, color: '#e19a00' });
  const prep = await prepare(doc, 's1', getFont);
  const svg = toSVG(prep);
  assert.match(svg, /<defs>\s*<linearGradient id="grad1" gradientUnits="userSpaceOnUse" x1="20" y1="50" x2="120" y2="50"><stop offset="0" stop-color="#ffffff"\/><stop offset="0.5"/);
  assert.match(svg, /<path id="p1"[^>]*fill="url\(#grad1\)"/);
  const op = toPdfList([prep]).list.pages[0].ops.find(o => o.fill?.gradient);
  assert.deepEqual(op.fill.gradient, { type: 'linear', x1: 20, y1: 50, x2: 120, y2: 50,
    stops: [{ offset: 0, color: '#ffffff' }, { offset: 0.5, color: '#e19a00' }, { offset: 1, color: '#2d4a8a' }] });
  const pdf = await toPDF([prep]);
  assert.match(Buffer.from(pdf).toString('latin1'), /\/PatternType 2/);
  try {
    const f = path.join(os.tmpdir(), `vsg-${process.pid}.pdf`);
    fs.writeFileSync(f, pdf);
    const png = f.replace(/\.pdf$/, '');
    execFileSync('pdftoppm', ['-png', '-r', '72', '-f', '1', '-l', '1', f, png]);
    fs.unlinkSync(f);
    for (const x of fs.readdirSync(os.tmpdir())) if (x.startsWith(path.basename(png))) fs.unlinkSync(path.join(os.tmpdir(), x));
  } catch (e) { if (e.code !== 'ENOENT') throw e; }
});

import zlib from 'node:zlib';

test('מעבר עגול ושקיפות בעצירות: SVG ‏radialGradient, ‏PDF ‏ShadingType 3 עם מסכה רכה', async () => {
  const doc = textDoc();
  const r = doc.surfaces[0].layers[0].paths[0];
  r.fill = { gradient: makeGradient([20, 20, 100, 60], 0, '#ffffff', '#2d4a8a', 'radial') };
  assert.deepEqual(r.fill.gradient.center, [70, 50]);
  r.fill.gradient.stops[1].opacity = 0;
  const prep = await prepare(doc, 's1', getFont);
  const svg = toSVG(prep);
  assert.match(svg, /<radialGradient id="grad1" gradientUnits="userSpaceOnUse" cx="70" cy="50" r="[\d.]+"><stop offset="0" stop-color="#ffffff"\/><stop offset="1" stop-color="#2d4a8a" stop-opacity="0"\/>/);
  const g = toPdfList([prep]).list.pages[0].ops.find(o => o.fill?.gradient).fill.gradient;
  assert.equal(g.type, 'radial');
  assert.deepEqual([g.cx, g.cy], [70, 50]);
  assert.equal(g.stops[1].opacity, 0, 'שקיפות בעצירה עוברת ל-pdf-core');
  const pdf = await toPDF([prep]);
  const s = Buffer.from(pdf).toString('latin1');
  assert.match(s, /\/ShadingType 3/);
  assert.match(s, /\/S \/Luminosity/, 'מסכה רכה');
  await pdfCheck(pdf);
});

test('שכבות של אחרים: תמונה ב-SVG וב-PDF, מסגרת טקסט כקווי מתאר, וחסרה — בדוח', async () => {
  const doc = textDoc();
  doc.flows = { f1: { doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'שלום' }] }] } } };
  doc.surfaces[0].layers.push(
    { id: 'r1', type: 'raster', src: 'media/a.png', box: [10, 10, 40, 20], opacity: 0.5 },
    { id: 'gr', type: 'group', children: [{ id: 'fr', type: 'frame', flow: 'f1', box: [200, 10, 150, 40] }] },
    { id: 'r2', type: 'raster', src: 'media/missing.png' },
  );
  const A = pngUrl(2, 1, [255, 0, 0, 255, 0, 255, 0, 128]);
  const getImage = async (src) => (src === 'media/a.png' ? { href: A, w: 2, h: 1 } : null);
  const prep = await prepare(doc, 's1', getFont, getImage);
  assert.ok(prep.gaps.some(g => g.includes('חסרה')));
  const svg = toSVG(prep);
  assert.match(svg, /xmlns:xlink=/);
  assert.match(svg, /<g id="r1" data-name="" opacity="0.5">\s*<image x="10" y="10" width="40" height="20" preserveAspectRatio="none" href="data:image\/png;base64,/);
  assert.match(svg, /<g id="fr"[^>]*>\s*<path id="fr" d="M/);
  const { list, files } = toPdfList([prep]);
  assert.deepEqual(list.pages[0].ops.find(o => o.op === 'image'), { op: 'image', src: 'img/1.png', box: [10, 10, 40, 20], opacity: 0.5 });
  assert.equal(files.get('img/1.png')[1], 0x50, 'הבתים — PNG');
  const pdf = await toPDF([prep]);
  assert.match(Buffer.from(pdf).toString('latin1'), /\/Subtype \/Image/);
  await pdfCheck(pdf);
});

/** PNG אמיתי (RGBA, 8 סיביות) כ-data URL — pdf-core מפענח את הבתים. */
function pngUrl(w, h, rgba) {
  const crc = (buf) => { let c = ~0; for (const b of buf) { c ^= b; for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xedb88320 & -(c & 1)); } return ~c >>> 0; };
  const chunk = (type, data) => { const t = Buffer.from(type), len = Buffer.alloc(4), c = Buffer.alloc(4);
    len.writeUInt32BE(data.length); c.writeUInt32BE(crc(Buffer.concat([t, data]))); return Buffer.concat([len, t, data, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 6;
  const raw = [];
  for (let y = 0; y < h; y++) { raw.push(0); raw.push(...rgba.slice(y * w * 4, (y + 1) * w * 4)); }
  const png = Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(Buffer.from(raw))), chunk('IEND', Buffer.alloc(0))]);
  return 'data:image/png;base64,' + png.toString('base64');
}

/** PDF תקין: מתחיל ב-%PDF, ו-pdftoppm מצייר אותו בלי הודעות (אם מותקן). */
async function pdfCheck(pdf) {
  pdf = await pdf;
  assert.match(Buffer.from(pdf.slice(0, 8)).toString('latin1'), /^%PDF-/);
  try {
    const f = path.join(os.tmpdir(), `vsc-${process.pid}-${Math.random().toString(36).slice(2)}.pdf`);
    fs.writeFileSync(f, pdf);
    const out = execFileSync('pdftoppm', ['-png', '-r', '36', f, f], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    for (const x of fs.readdirSync(os.tmpdir())) if (x.startsWith(path.basename(f))) fs.unlinkSync(path.join(os.tmpdir(), x));
    assert.equal(out, '');
  } catch (e) { if (e.code !== 'ENOENT') throw e; }
}

import { markOffset } from '../assets/js/marks.js';

test('ניקוד: עוגנים מ-GPOS, והסימן יושב על האות שלו', async () => {
  for (const file of ['Heebo.ttf', 'FrankRuhlLibre.ttf', 'DavidLibre-Regular.ttf']) {
    const ot = await getFont(file);
    assert.ok(ot.marks.length, file + ': יש טבלאות עוגנים');
    const bet = ot.charToGlyph('ב'), dagesh = ot.charToGlyph('\u05BC');
    assert.ok(markOffset(ot.marks, bet.index, dagesh.index), file + ': לדגש יש עוגן בב');
  }
  // "בּ" — הדגש בתוך התיבה של הב (ולא לצידה)
  const item = { id: 't', type: 'text', text: 'בּ', box: [0, 0, 200, 0], font: { family: 'Heebo', size: 100, weight: 400 }, align: 'left', lineHeight: 1.25, auto: true };
  relayout(item);
  const plain = bbox(parse(await textToD({ ...item, text: 'ב' }, getFont)));
  const d = await textToD(item, getFont);
  const subs = d.split(/(?=M)/).map(x => bbox(parse(x)));
  const dot = subs.reduce((a, b) => (b[2] * b[3] < a[2] * a[3] ? b : a));
  const cx = dot[0] + dot[2] / 2, cy = dot[1] + dot[3] / 2;
  assert.ok(cx > plain[0] && cx < plain[0] + plain[2], `הדגש באמצע האות: ${cx} ב-[${plain[0]}, ${plain[0] + plain[2]}]`);
  assert.ok(cy > plain[1] && cy < plain[1] + plain[3], 'ובגובה שלה');
  // קמץ מתחת לאות
  const q = await textToD({ ...item, text: 'בָ' }, getFont);
  const qb = bbox(parse(q));
  assert.ok(qb[1] + qb[3] > plain[1] + plain[3] + 5, 'הקמץ מתחת לשורה של האות');
});

test('מעבר מסוג לא מוכר (מגרסה עתידית): צבע העצירה הראשונה, ובדוח', async () => {
  const doc = textDoc();
  doc.surfaces[0].layers[0].paths[0].fill = { gradient: { type: 'conic', angle: 0, stops: [{ at: 0, color: '#123456' }] } };
  const prep = await prepare(doc, 's1', getFont);
  assert.ok(prep.gaps.includes('gradient:conic → צבע אחיד'));
  assert.match(toSVG(prep), /<path id="p1"[^>]*fill="#123456"/);
  await pdfCheck(toPDF([prep]));
  assert.equal(doc.surfaces[0].layers[0].paths[0].fill.gradient.type, 'conic', 'המסמך עצמו לא משתנה');
});

test('ניקוד: טעם שאין בגופן — מושמט (לא ריבוע ריק); טבלאות סימן-על-סימן נקראות', async () => {
  const ot = await getFont('Heebo.ttf');
  assert.ok(ot.marks.some(t => t.kind === 'mark'), 'יש mark-to-mark');
  assert.equal(ot.charToGlyph('֖').index, 0, 'טרחא — לא בגופן');
  const item = { id: 't', type: 'text', text: 'בָּ', box: [0, 0, 200, 0], font: { family: 'Heebo', size: 100, weight: 400 }, align: 'left', lineHeight: 1.25, auto: true };
  relayout(item);
  const withTa = await textToD({ ...item, text: 'בָּ֖' }, getFont);
  assert.equal(withTa, await textToD(item, getFont));
});

test('תמונה בתוך הציור: SVG ‏<image> עם סיבוב ואטימות, PDF — XObject, וחסרה — בדוח', async () => {
  const doc = textDoc();
  doc.surfaces[0].layers[0].paths.push(
    { id: 'i1', type: 'image', src: 'media/aa.png', box: [10, 20, 40, 30], opacity: 0.5, transform: [0, 1, -1, 0, 100, 0] },
    { id: 'i2', type: 'image', src: 'media/missing.png', box: [0, 0, 10, 10] });
  const B = pngUrl(2, 1, [0, 0, 0, 255, 0, 0, 0, 255]);
  const getImage = async (src) => (src === 'media/aa.png' ? { href: B, w: 2, h: 1 } : null);
  const prep = await prepare(doc, 's1', getFont, getImage);
  assert.ok(prep.gaps.some(g => g.startsWith('image')));
  const svg = toSVG(prep);
  assert.match(svg, /xmlns:xlink=/);
  assert.match(svg, /<image id="i1" x="10" y="20" width="40" height="30" preserveAspectRatio="none" href="data:image\/png;base64,[^"]+" xlink:href="[^"]+" opacity="0.5" transform="matrix\(0 1 -1 0 100 0\)"\/>/);
  assert.doesNotMatch(svg, /id="i2"/);
  assert.deepEqual(toPdfList([prep]).list.pages[0].ops.find(o => o.op === 'image'),
    { op: 'image', src: 'img/1.png', box: [10, 20, 40, 30], transform: [0, 1, -1, 0, 100, 0], opacity: 0.5 }, 'סיבוב — transform של pdf-core');
  await pdfCheck(toPDF([prep]));
});

test('מתאר משוכלל: מעבר צבע במתאר וחץ בסוף — ב-SVG וב-PDF', async () => {
  const doc = newDocument([400, 300]);
  const L = doc.surfaces[0].layers[0];
  L.paths.push({ id: 'ln', d: 'M10 10 L110 10', fill: null,
    stroke: { width: 4, gradient: { type: 'linear', from: [10, 10], to: [110, 10], stops: [{ at: 0, color: '#ff0000' }, { at: 1, color: '#0000ff' }] }, end: 'arrow', start: 'circle' } });
  const prep = await prepare(doc, doc.surfaces[0].id, getFont);
  const svg = toSVG(prep);
  assert.match(svg, /<path id="ln" [^>]*stroke="url\(#grad1\)"/, 'המתאר במעבר צבע');
  assert.match(svg, /<path id="ln-m0" [^>]*fill="url\(#grad\d\)"/, 'העיגול בהתחלה — באותו מעבר');
  assert.match(svg, /<path id="ln-m1" /, 'החץ בסוף');
  const ops = toPdfList([prep]).list.pages[0].ops;
  assert.equal(ops.length, 3, 'הנתיב ושני הקצוות');
  assert.ok(ops[0].stroke.gradient && ops[0].width === 4, 'מתאר במעבר צבע');
  assert.ok(ops[1].fill.gradient && ops[2].fill.gradient && !ops[1].stroke, 'הקצוות — מלאים באותו מעבר');
  await pdfCheck(toPDF([prep]));
});

test('אפקטים: SVG ‏filter (צל, זוהר, טשטוש); PDF — תמונה מתחת, ובלי getEffects — בדוח', async () => {
  const doc = newDocument([400, 300]);
  const L = doc.surfaces[0].layers[0];
  L.paths.push({ id: 'e1', d: 'M10 10 L60 10 L60 60 Z', fill: { color: '#2d4a8a' }, stroke: null,
    effects: [{ type: 'shadow', dx: 4, dy: 4, blur: 6, color: '#000000', opacity: 0.35 }, { type: 'glow', blur: 8, color: '#ffd34d', opacity: 0.8 }] });
  L.paths.push({ id: 'e2', d: 'M100 10 L150 10 L150 60 Z', fill: { color: '#e11d48' }, stroke: null, effects: [{ type: 'blur', radius: 3 }] });
  let prep = await prepare(doc, doc.surfaces[0].id, getFont);
  const svg = toSVG(prep);
  assert.match(svg, /<filter id="fx\d+"[^>]*><feGaussianBlur in="SourceAlpha" stdDeviation="3" result="b0"\/><feOffset in="b0" dx="4" dy="4"/);
  assert.match(svg, /<feMergeNode in="s0"\/><feMergeNode in="s1"\/><feMergeNode in="SourceGraphic"\/>/);
  assert.match(svg, /<feGaussianBlur in="SourceGraphic" stdDeviation="1.5" result="src"\/><feMerge><feMergeNode in="src"\/>/);
  assert.match(svg, /<g id="e1-fx" filter="url\(#fx\d+\)">\s*<path id="e1"/);
  assert.ok(prep.gaps.includes('effects — לא נכללו ב-PDF'));
  // עם getEffects: תמונה מתחת לפריט; blur — במקומו
  const img = { href: pngUrl(1, 1, [0, 0, 0, 128]), w: 1, h: 1 };
  prep = await prepare(doc, doc.surfaces[0].id, getFont, null, null, async (item) => ({ image: img, box: [0, 0, 10, 10], replace: item.id === 'e2' }));
  assert.ok(prep.gaps.includes('effects → תמונה (PDF)'));
  const ops = toPdfList([prep]).list.pages[0].ops;
  assert.deepEqual(ops.map(o => o.op), ['image', 'path', 'image'], 'e1: תמונה ואז הנתיב; e2 (blur) — רק התמונה');
  assert.equal(ops[1].fill, '#2d4a8a');
  await pdfCheck(toPDF([prep]));
});
