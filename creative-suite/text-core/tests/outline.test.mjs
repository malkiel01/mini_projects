// בדיקות text-core — מדידה וקווי מתאר: node --test creative-suite/text-core/tests/*.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadFont, shapeLine, advance, fontMetrics, measure, glyphOutline, glyphsToPath, textToPath } from '../src/js/shaper.js';

const buf = name => { const b = readFileSync(new URL('../../../mbe-cloud/fonts/builtin/' + name, import.meta.url)); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const noto = await loadFont(buf('NotoSerifHebrew.ttf'), 'noto');
const frank = await loadFont(buf('FrankRuhlLibre.ttf'), 'frank');

const coords = d => (d.match(/-?\d+(\.\d+)?/g) || []).map(Number);

test('fontMetrics — עלייה חיובית, ירידה שלילית, יחידות הגופן', () => {
  const m = fontMetrics(noto);
  assert.equal(m.upem, noto.upem);
  assert.ok(m.ascender > 0 && m.descender < 0);
});

test('measure — רוחב ביחידות של size, ומתאים ל-advance', () => {
  const r = measure(noto, 'שָׁלוֹם', 12);
  assert.ok(Math.abs(r.width - advance(r.glyphs) * 12 / noto.upem) < 1e-9);
  assert.ok(r.ascent > 0 && r.descent > 0);
  const r24 = measure(noto, 'שָׁלוֹם', 24);
  assert.ok(Math.abs(r24.width - 2 * r.width) < 1e-9);
});

test('glyphOutline — פקודות M…Z לאות, ריק לרווח, במטמון', () => {
  const [g] = shapeLine(noto, 'א');
  const o = glyphOutline(noto, g.gid);
  assert.equal(o[0].type, 'M');
  assert.equal(o[o.length - 1].type, 'Z');
  assert.equal(glyphOutline(noto, g.gid), o);
  const [sp] = shapeLine(noto, ' ');
  assert.equal(glyphOutline(noto, sp.gid).length, 0);
});

test('textToPath — רק פקודות מוחלטות M L Q C Z, y למטה, בתוך תיבת השורה', () => {
  const size = 20, x = 10, y = 50;
  const { d, width } = textToPath(noto, 'בְּרֵאשִׁית Genesis 1', { size, x, y });
  assert.match(d, /^M/);
  assert.doesNotMatch(d, /[a-df-zA-BD-KN-PR-Y]/);   // אין פקודות יחסיות או אחרות
  const n = coords(d), xs = n.filter((_, i) => i % 2 === 0), ys = n.filter((_, i) => i % 2 === 1);
  const m = measure(noto, 'בְּרֵאשִׁית Genesis 1', size);
  assert.ok(Math.abs(width - m.width) < 1e-9);
  assert.ok(Math.min(...xs) >= x - size && Math.max(...xs) <= x + width + size);
  assert.ok(Math.min(...ys) >= y - m.ascent - 1 && Math.max(...ys) <= y + m.descent + 1);   // y למטה: האותיות מעל הבסיס
  assert.ok(Math.min(...ys) < y - size / 3);
});

test('glyphsToPath — גופן חלופי לטעמים: הסימן מצויר מהגופן שלו, באותו מיקום כמו drawGlyphs', () => {
  const glyphs = shapeLine([frank, noto], 'בָּרָ֣א');
  assert.ok(glyphs.some(g => g.f === noto));
  const d = glyphsToPath(frank, glyphs, 0, 0, 10);
  assert.equal((d.match(/M/g) || []).length > 3, true);
  assert.ok(!d.includes('NaN'));
});

test('אותה שורה — אותו נתיב (דטרמיניסטי), והזזה ב-x מזיזה את כל הנקודות', () => {
  const a = textToPath(noto, 'אבג', { size: 10 }).d;
  assert.equal(textToPath(noto, 'אבג', { size: 10 }).d, a);
  const b = textToPath(noto, 'אבג', { size: 10, x: 5 }).d;
  const na = coords(a), nb = coords(b);
  assert.equal(na.length, nb.length);
  for (let i = 0; i < na.length; i += 2) assert.ok(Math.abs(nb[i] - na[i] - 5) < 0.002);
});
