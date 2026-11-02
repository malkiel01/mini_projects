// בדיקות text-core — טקסט על נתיב: node --test creative-suite/text-core/tests/*.test.mjs
// המדידה מ-geometry-core (המקור, לא עותק) — כך שהבדיקה מוכיחה שהשניים מדברים באותה שפה.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadFont, shapeLine, measure as measureLine, glyphsToPath, textOnPath, glyphsOnPath } from '../src/js/shaper.js';
import { parse } from '../../geometry-core/src/js/path.js';
import { measure } from '../../geometry-core/src/js/measure.js';

const buf = name => { const b = readFileSync(new URL('../../../suite-cloud/fonts/builtin/' + name, import.meta.url)); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const noto = await loadFont(buf('NotoSerifHebrew.ttf'), 'noto');
const frank = await loadFont(buf('FrankRuhlLibre.ttf'), 'frank');
const coords = d => (d.match(/-?\d+(\.\d+)?/g) || []).map(Number);
const TEXT = 'בְּרֵאשִׁ֖ית Genesis 1';

test('קו ישר — זהה לשורה רגילה (glyphsToPath) עד אלפית', () => {
  const glyphs = shapeLine([frank, noto], TEXT);
  const flat = coords(glyphsToPath(frank, glyphs, 10, 50, 20));
  const on = glyphsOnPath(frank, glyphs, measure(parse('M10 50 L2000 50')), { size: 20 });
  assert.equal(on.overflow, 0);
  const c = coords(on.d);
  assert.equal(c.length, flat.length);
  for (let i = 0; i < c.length; i++) assert.ok(Math.abs(c[i] - flat[i]) < 0.003, `${i}: ${c[i]} ≠ ${flat[i]}`);
});

test('קו אנכי כלפי מטה — סיבוב ב-90°: x ו-y מתחלפים', () => {
  const glyphs = shapeLine(noto, 'אבג');
  const flat = coords(glyphsToPath(noto, glyphs, 0, 0, 10));
  const on = coords(glyphsOnPath(noto, glyphs, measure(parse('M0 0 L0 500')), { size: 10 }).d);
  for (let i = 0; i < on.length; i += 2) {
    assert.ok(Math.abs(on[i] - -flat[i + 1]) < 0.003);   // x' = -y
    assert.ok(Math.abs(on[i + 1] - flat[i]) < 0.003);    // y' = x
  }
});

test('מעגל עם כיוון השעון (y למטה) — האותיות מבחוץ, בטבעת ברוחב גובה האותיות', () => {
  const r = 100, size = 12;
  const circle = `M${r} 0 C${r} 55.228 55.228 ${r} 0 ${r} C-55.228 ${r} -${r} 55.228 -${r} 0 C-${r} -55.228 -55.228 -${r} 0 -${r} C55.228 -${r} ${r} -55.228 ${r} 0Z`;
  const { d, overflow } = textOnPath(noto, 'שָׁלוֹם עֲלֵיכֶם', measure(parse(circle)), { size });
  assert.equal(overflow, 0);
  const c = coords(d), m = measureLine(noto, 'x', size);
  let outside = 0;
  for (let i = 0; i < c.length; i += 2) {
    const dist = Math.hypot(c[i], c[i + 1]);
    assert.ok(dist > r - m.descent - 1 && dist < r + m.ascent + 1, `${dist}`);
    if (dist > r + size / 3) outside++;
  }
  assert.ok(outside > 0);   // ראשי האותיות כלפי חוץ
});

test('align ו-overflow: נתיב קצר — חלק מהגליפים לא נכנסים ונספרים', () => {
  const glyphs = shapeLine(noto, 'אבגדהוזחט');
  const w = measureLine(noto, 'אבגדהוזחט', 20).width;
  const short = measure(parse(`M0 0 L${w / 2} 0`));
  const r = glyphsOnPath(noto, glyphs, short, { size: 20 });
  assert.ok(r.overflow > 0 && r.overflow < glyphs.length);
  const long = measure(parse(`M0 0 L${w * 3} 0`));
  const end = glyphsOnPath(noto, glyphs, long, { size: 20, align: 'end' });
  const last = end.placed[end.placed.length - 1];
  assert.ok(Math.abs(last.x + glyphs[glyphs.length - 1].ax * 20 / noto.upem - w * 3) < 0.01);
  const mid = glyphsOnPath(noto, glyphs, long, { size: 20, align: 'center' });
  assert.ok(Math.abs(mid.placed[0].x - w) < 0.01);
});

test('shift — קו הבסיס זז למעלה מהנתיב (y קטן יותר)', () => {
  const p = measure(parse('M0 0 L1000 0'));
  const a = glyphsOnPath(noto, shapeLine(noto, 'א'), p, { size: 10 }).placed[0];
  const b = glyphsOnPath(noto, shapeLine(noto, 'א'), p, { size: 10, shift: 3 }).placed[0];
  assert.ok(Math.abs(a.y - b.y - 3) < 1e-9);
});
