// בדיקות עיצוב הגליפים: node --test layout-studio/tests/shaper.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadFont, shape, shapeLine, advance } from '../assets/js/shaper.js';

const buf = name => { const b = readFileSync(new URL('../assets/fonts/' + name, import.meta.url)); return b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength); };
const noto = await loadFont(buf('NotoSerifHebrew.ttf'));
const frank = await loadFont(buf('FrankRuhlLibre.ttf'));

const TAAMIM = 'בְּרֵאשִׁ֖ית בָּרָ֣א אֱלֹהִ֑ים אֵ֥ת הַשָּׁמַ֖יִם וְאֵ֥ת הָאָֽרֶץ׃';
const NIQQUD = 'בָּרוּךְ אַתָּה ה׳ אֱלֹהֵינוּ מֶלֶךְ הָעוֹלָם';

test('Noto Serif Hebrew — כל סימני הניקוד והטעמים קיימים', () => {
  assert.equal(shapeLine(noto, TAAMIM).filter(g => g.gid === 0).length, 0);
  assert.equal(shapeLine(noto, NIQQUD).filter(g => g.gid === 0).length, 0);
});

test('Frank Ruhl Libre — ניקוד כן, טעמים חסרים (לכן ברירת המחדל לטעמים: Noto)', () => {
  assert.equal(shapeLine(frank, NIQQUD).filter(g => g.gid === 0).length, 0);
  assert.ok(shapeLine(frank, TAAMIM).filter(g => g.gid === 0).length > 0);
});

test('סימני ניקוד ממוקמים בהיסט ובלי התקדמות', () => {
  const g = shapeLine(noto, 'בָּ');
  assert.equal(g.length, 3);
  assert.equal(g.filter(x => x.ax > 0).length, 1);   // רק הבי"ת מתקדמת
});

test('עברית — סדר חזותי הפוך מהלוגי', () => {
  const g = shapeLine(noto, 'אבג');
  assert.deepEqual(g.map(x => x.cluster), [2, 1, 0]);
});

test('bidi: מילה באנגלית ומספר בתוך משפט עברי נשארים משמאל לימין', () => {
  const text = 'ספר Genesis פרק 12';
  const g = shapeLine(noto, text);
  const vis = g.map(x => x.cluster);
  const G = text.indexOf('G');
  const iG = vis.indexOf(G), iS = vis.indexOf(text.indexOf('s', text.indexOf('Genesi')));
  assert.ok(iG < iS, 'G משמאל ל-s');
  const i1 = vis.indexOf(text.indexOf('1')), i2 = vis.indexOf(text.indexOf('2'));
  assert.ok(i1 < i2, '1 משמאל ל-2');
  // והמשפט עצמו מימין לשמאל: "ספר" בקצה הימני, "12" בקצה השמאלי
  assert.equal(vis[vis.length - 1], 0);
  assert.ok(vis.slice(0, 2).every(c => c >= text.indexOf('1')));
});

test('רוחב — סכום ההתקדמויות, זהה בין shape ו-shapeLine לטקסט עברי בלבד', () => {
  assert.equal(advance(shapeLine(noto, NIQQUD)), advance(shape(noto, NIQQUD, 'rtl')));
});
