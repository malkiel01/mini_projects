// בדיקות render-core ב-node: node --test creative-suite/render-core/tests/*.test.mjs
// ההשוואה מול מנועי ה-GPU (WebGL2 / WebGPU) — בבדיקות הדפדפן של הרסטר ("render-core — המרכיב במעבד כמו המנוע").

import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as R from '../src/js/index.js';

const px = (r, g, b, a = 255) => new Uint8Array([r, g, b, a]);
const layer = (rgba, more = {}) => ({ rgba, w: 1, h: 1, ...more });
const one = layers => [...R.composite({ width: 1, height: 1, layers })];
const near = (a, b, tol = 1) => a.length === b.length && a.every((v, i) => Math.abs(v - b[i]) <= tol);

test('sRGB ↔ לינארי: הלוך וחזור מדויק בכל 256 הערכים', () => {
  for (let v = 0; v < 256; v++) assert.equal(R.linearToSrgb(R.srgbToLinear(v)), v);
  assert.equal(R.SRGB_TO_LINEAR[255], 1);
  assert.equal(R.linearToSrgb(-1), 0);
  assert.equal(R.linearToSrgb(2), 255);
});

test('מצבי מיזוג — הסדר קבוע, ומצב לא מוכר הוא רגיל', () => {
  assert.deepEqual(R.BLEND_MODES, ['normal', 'multiply', 'screen', 'overlay']);
  assert.equal(R.blendIndex('screen'), 2);
  assert.equal(R.blendIndex('hue'), 0);
  assert.equal(R.blendChannel(1, 0.5, 0.5), 0.25);
  assert.equal(R.blendChannel(2, 0.5, 0.5), 0.75);
  // overlay: הרקע כהה (≤ 0.5) → multiply(2b); בהיר → screen
  assert.equal(R.blendChannel(3, 0.25, 0.5), 0.25);
  assert.equal(R.blendChannel(3, 0.75, 0.5), 0.75);
});

test('blendOver — source-over רגיל: אטום מכסה, שקוף משאיר', () => {
  assert.deepEqual(R.blendOver([0, 0, 0, 0], [0, 0, 0.2, 1], [0.1, 0.2, 0.3, 1], 0), [0.1, 0.2, 0.3, 1]);
  const out = R.blendOver([0, 0, 0, 0], [0.5, 0.5, 0.5, 1], [0, 0, 0, 0], 0);
  assert.deepEqual(out, [0.5, 0.5, 0.5, 1]);
});

test('composite — שכבה אחת חוזרת כמו שהיא (גם חצי שקופה)', () => {
  assert.deepEqual(one([layer(px(200, 100, 50))]), [200, 100, 50, 255]);
  assert.ok(near(one([layer(px(200, 100, 50, 128))]), [200, 100, 50, 128]));
  assert.deepEqual(one([]), [0, 0, 0, 0]);
});

test('composite — מיזוג בלינארי: 50% לבן על שחור ≈ 188, לא 128', () => {
  const r = one([layer(px(0, 0, 0)), layer(px(255, 255, 255), { opacity: 0.5 })]);
  assert.ok(near(r, [188, 188, 188, 255]), r.join());
});

test('composite — multiply / screen / overlay', () => {
  const base = layer(px(128, 128, 128));
  const lin = v => R.srgbToLinear(v);
  const want = f => R.linearToSrgb(f(lin(128), lin(200)));
  const top = blend => layer(px(200, 200, 200), { blend });
  assert.equal(one([base, top('multiply')])[0], want((b, s) => b * s));
  assert.equal(one([base, top('screen')])[0], want((b, s) => b + s - b * s));
  assert.equal(one([base, top('overlay')])[0], want((b, s) => R.blendChannel(3, b, s)));
});

test('composite — מוסתרת ואטימות 0 לא נספרות; מיקום x / y', () => {
  assert.deepEqual(one([layer(px(1, 2, 3), { visible: false })]), [0, 0, 0, 0]);
  assert.deepEqual(one([layer(px(1, 2, 3), { opacity: 0 })]), [0, 0, 0, 0]);
  const out = R.composite({ width: 3, height: 1, layers: [{ rgba: px(255, 0, 0), w: 1, h: 1, x: 2, y: 0 }] });
  assert.deepEqual([...out], [0, 0, 0, 0, 0, 0, 0, 0, 255, 0, 0, 255]);
  // שכבה שחורגת מהמסמך — נחתכת, בלי שגיאה
  const big = R.composite({ width: 1, height: 1, layers: [{ rgba: new Uint8Array(16).fill(255), w: 2, h: 2, x: -1, y: -1 }] });
  assert.deepEqual([...big], [255, 255, 255, 255]);
});

test('composite — מסכה: 0 מסתירה, 255 מראה, ומחוץ למסכה — outside', () => {
  const m = (v, outside) => ({ data: new Uint8Array([v]), w: 1, h: 1, outside });
  assert.deepEqual(one([layer(px(9, 9, 9), { mask: m(0) })]), [0, 0, 0, 0]);
  assert.deepEqual(one([layer(px(9, 9, 9), { mask: m(255) })]), [9, 9, 9, 255]);
  assert.ok(near(one([layer(px(9, 9, 9), { mask: m(128) })]), [9, 9, 9, 128]));
  assert.deepEqual(one([layer(px(9, 9, 9), { mask: { ...m(0), x: 5, outside: 255 } })]), [9, 9, 9, 255]);
  assert.deepEqual(one([layer(px(9, 9, 9), { mask: { ...m(0), enabled: false } })]), [9, 9, 9, 255]);
});

test('composite — קבוצה מבודדת: מיזוג בפנים, ואז אטימות של הקבוצה על הכול', () => {
  const black = layer(px(0, 0, 0));
  const white = layer(px(255, 255, 255));
  // בתוך קבוצה: לבן מוכפל בשחור = שחור; הקבוצה ב-50% על לבן — חצי שחור בלינארי
  const r = one([white, { type: 'group', opacity: 0.5, children: [black, { ...white, blend: 'multiply' }] }]);
  assert.ok(near(r, [188, 188, 188, 255]), r.join());
  // קבוצה מוסתרת / ריקה — לא נספרת
  assert.deepEqual(one([{ type: 'group', visible: false, children: [white] }]), [0, 0, 0, 0]);
  assert.deepEqual(one([{ type: 'group', children: [] }]), [0, 0, 0, 0]);
});

test('composite — linear: true מחזיר Float32Array מוכפל; גודל לא תקין — bad-size', () => {
  const lin = R.composite({ width: 1, height: 1, layers: [layer(px(255, 255, 255, 51))] }, { linear: true });
  assert.ok(lin instanceof Float32Array);
  assert.ok(Math.abs(lin[3] - 0.2) < 1e-6 && Math.abs(lin[0] - 0.2) < 1e-6);
  assert.throws(() => R.composite({ width: 0, height: 1, layers: [] }), e => e.code === 'bad-size' && e.message.startsWith('render-core: '));
});

test('השיידרים — אותה נוסחה: blendOver מוגדר ב-GLSL וב-WGSL, ו-enc / dec בשניהם', () => {
  for (const src of [R.GLSL_BLEND, R.WGSL_BLEND]) for (const f of ['screenB', 'hardLight', 'blendOver']) assert.ok(src.includes(f), f);
  assert.ok(R.GLSL_COLOR.includes('vec3 dec(') && R.GLSL_COLOR.includes('vec3 enc('));
  assert.ok(R.WGSL_COLOR.includes('fn dec(') && R.WGSL_COLOR.includes('fn enc('));
});

test('budget — תקציב לפי המכשיר, והצעת גודל באותו יחס', () => {
  const b = R.deviceBudget({ deviceMemory: 8 });
  assert.equal(b.guessed, false);
  assert.equal(b.bytes, Math.floor(8 * 1024 ** 3 * 0.25));
  assert.equal(R.deviceBudget({ mobile: true }).memGB, 3);
  assert.equal(R.suggestSize(10, 10, 1000), null);
  const [w, h] = R.suggestSize(4000, 2000, 2e6);
  assert.ok(w * h <= 2e6 && Math.abs(w / h - 2) < 0.01);
  assert.equal(R.perfFactor(R.PERF_FAST), 1);
  assert.equal(R.perfFactor(R.PERF_SLOW), 0.5);
});

test('pickBackend — בלי דפדפן: canvas', async () => {
  assert.equal(R.webgl2Supported(), false);
  assert.equal(await R.pickBackend(), 'canvas');
});
