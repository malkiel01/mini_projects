// בדיקות הייצוא: node --test vector-studio/tests/export.test.mjs
// ה-PDF נבדק גם ב-pdfinfo (poppler) אם הוא מותקן.

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import * as opentype from '../assets/js/vendor/opentype.min.mjs';
import { visualOrder, fontFile, textToD } from '../assets/js/outline.js';
import { prepare, toSVG, toPDF } from '../assets/js/export.js';
import { newDocument, setShape } from '../assets/js/model.js';
import { relayout, setMeasure } from '../assets/js/text.js';
import { parse, bbox } from '../assets/js/geometry/path.js';

const FONT_DIR = new URL('../assets/fonts/', import.meta.url);
const cache = new Map();
async function getFont(file) {
  if (!cache.has(file)) {
    const b = fs.readFileSync(new URL(file, FONT_DIR));
    cache.set(file, opentype.parse(b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength)));
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
  const pdf = toPDF([await prepare(doc, 's1', getFont), await prepare(doc, 's2', getFont)]);
  const s = Buffer.from(pdf).toString('latin1');
  assert.match(s, /^%PDF-1\.4/);
  assert.match(s, /\/Count 2/);
  assert.match(s, /\/MediaBox \[0 0 400 300\]/);
  assert.match(s, /\/ca 0\.5/);
  // כל היסט ב-xref מצביע על תחילת אובייקט
  const xref = Number(/startxref\n(\d+)/.exec(s)[1]);
  const entries = s.slice(xref).split('\n').slice(3).filter(l => /^\d{10} 00000 n/.test(l));
  entries.forEach((e, i) => assert.ok(s.startsWith(`${i + 1} 0 obj`, Number(e.slice(0, 10))), 'אובייקט ' + (i + 1)));
  let info = null;
  try {
    const f = path.join(os.tmpdir(), `vs-${process.pid}.pdf`);
    fs.writeFileSync(f, pdf);
    info = execFileSync('pdfinfo', [f], { encoding: 'utf8' });
    fs.unlinkSync(f);
  } catch (e) { if (e.code !== 'ENOENT') throw e; }
  if (info) assert.match(info, /Pages:\s+2/);
});

