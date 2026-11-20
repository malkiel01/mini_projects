// L1 (spec-stage-a 18.1.5, 2.30) — מסך מלא לגובה או לרוחב, בדפדפן אמיתי (בקשת הבעלים: "גם הגדלה לגובה וגם הרחבה לרוחב").
// node trading-strategies/tests/chart-landscape-ui.mjs   (Playwright, הדמיית טלפון; ספקים מדומים, בלי רשת)
// SHOTS=<תיקייה> — צילומי מסך (deviceScaleFactor 2).
//
// שני המסלולים של "לרוחב":
//   - נעילה נתמכת: screen.orientation.lock/unlock מוחלפים בפונקציה שמסובבת את ה-viewport (כמו טלפון שמסתובב) ורושמת את הקריאה.
//   - נעילה נדחית / אין נעילה: השכבה המסובבת ב-CSS, והמגע מומר (rotate.js). שם בודקים גרירה, סמן וציור — לפי מה שעל המסך.
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
import { writeFileSync } from 'fs';
import { createServer } from 'net';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const port = await new Promise(r => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const RUN = `${process.pid}-${Math.random().toString(16).slice(2, 10)}`;
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-land-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const SHOTS = process.env.SHOTS;
const NOW = +(process.env.TS_NOW || 1794094200);
const W = 390, H = 844;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();

/** lock: 'ok' — הטלפון מסתובב · 'reject' — הנעילה נדחית · 'none' — אין screen.orientation.lock (כמו Safari) */
async function open({ lock = 'ok', scheme = 'light', width = W, height = H, mobile = true } = {}) {
  const ctx = await b.newContext({ viewport: { width, height }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: mobile, isMobile: mobile, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: 'alice', url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.clock.setFixedTime(NOW * 1000);
  // הסיבוב — דרך CDP ישירות: setViewportSize נכשל בתוך Fullscreen (Browser.setWindowBounds על חלון במסך מלא)
  const cdp = new Map();
  await ctx.exposeBinding('tsRotate', async ({ page }, o) => {
    const land = o.startsWith('landscape');
    if (!cdp.has(page)) cdp.set(page, await ctx.newCDPSession(page));
    await cdp.get(page).send('Emulation.setDeviceMetricsOverride', { width: land ? H : W, height: land ? W : H, deviceScaleFactor: SHOTS ? 2 : 1, mobile: true,
      screenOrientation: land ? { type: 'landscapePrimary', angle: 90 } : { type: 'portraitPrimary', angle: 0 } });
  });
  await ctx.addInitScript(mode => {
    window.WebSocket = class { constructor() {} close() {} };
    window.__ori = [];
    const P = window.ScreenOrientation?.prototype;
    if (!P) return;
    if (mode === 'none') { delete P.lock; return; }
    P.lock = function (o) {
      window.__ori.push('lock:' + o);
      if (mode === 'reject') return Promise.reject(new DOMException('not supported', 'NotSupportedError'));
      return window.tsRotate(o);
    };
    // unlock: הטלפון חוזר לאורך
    P.unlock = function () {
      window.__ori.push('unlock');
      window.tsRotate('portrait');
    };
  }, lock);
  const p = await ctx.newPage();
  // הבדיקה הזו — על התנועות. גרירה עד הנר הראשון טוענת היסטוריה בפעימות (market_history), והמספור הלוגי של הנרות
  // זז במספר שנוסף — מה שהבדיקות כאן משוות. ההיסטוריה נבדקת לבד ב-tests/history-ui.mjs, כאן "אין יותר"
  await p.route(/api\.php\?action=market_history/, r => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ ok: true, candles: [], done: true, retry: false, notice: null }) }));
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  await p.goto(CHART);
  await loaded(p);
  return { p, ctx };
}
// צילום: אחרי סיבוב מדומה — דרך CDP (page.screenshot מחיל מחדש את ה-viewport של Playwright ומבטל את הסיבוב);
// אחרת page.screenshot (צילום CDP בתוך Fullscreen יוצא בגודל החלון, 800×600)
const shot = async (p, name) => {
  if (!SHOTS) return;
  const path = join(SHOTS, name + '.png');
  if (!(await p.evaluate(() => innerWidth > innerHeight))) { await p.screenshot({ path }); return; }
  const c = await p.context().newCDPSession(p);
  const { data } = await c.send('Page.captureScreenshot', { format: 'png' });
  writeFileSync(path, Buffer.from(data, 'base64'));
  await c.detach();
};
// גודל אזור הגרף בכיוון הקריאה (offsetWidth/Height — לפני הסיבוב), והמלבן האמיתי שלו על המסך
const boxRect = p => p.evaluate(() => { const b = document.querySelector('.chart-box:not(.osc)'); return [b.offsetWidth, b.offsetHeight]; });
const onScreen = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsScreen());
const range = p => p.evaluate(() => { const r = document.querySelector('.chart-box:not(.osc)').tsRange(); return [Math.round(r.from * 100) / 100, Math.round(r.to * 100) / 100]; });
const cross = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsCross());
const turned = p => p.evaluate(() => { const w = document.querySelector('.chart-wrap'); return [w.classList.contains('fs-turn'), getComputedStyle(w).transform]; });
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const ori = p => p.evaluate(() => window.__ori.splice(0));
const fsBtn = p => p.getByRole('button', { name: 'מסך מלא', exact: true });
const menu = p => p.getByRole('menu', { name: 'מסך מלא' });
const inFs = p => p.locator('.chart-wrap.fs').waitFor();
async function fsGone(p) {
  await p.waitForFunction(() => !document.querySelector('.chart-wrap.fs') && !document.fullscreenElement && window.innerWidth < window.innerHeight);
  await p.waitForTimeout(500);
}
// גרירת אצבע במסך (CDP — מגע אמיתי, לא עכבר)
async function swipe(p, [x0, y0], [x1, y1], steps = 12) {
  const c = await p.context().newCDPSession(p);
  const t = (type, x, y) => c.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] });
  await t('touchStart', x0, y0);
  for (let i = 1; i <= steps; i++) { await t('touchMove', x0 + (x1 - x0) * i / steps, y0 + (y1 - y0) * i / steps); await p.waitForTimeout(16); }
  await t('touchEnd');
  await c.detach();
  await p.waitForTimeout(300);
}
async function enter(p, mode) {
  await fsBtn(p).click();
  await menu(p).waitFor();
  await menu(p).getByRole('menuitem', { name: mode }).click();
  await inFs(p);
  await p.waitForTimeout(500);
}
// "לרוחב" הסתיים: הנעילה התבקשה והטלפון (המדומה) הסתובב
const landed = p => p.waitForFunction(() => window.innerWidth > window.innerHeight);

try {
  // ── המצב הרגיל ──
  const { p: a } = await open();
  const rect0 = await boxRect(a);
  const range0 = await range(a);
  const url0 = a.url();
  eq(await a.evaluate(() => document.fullscreenEnabled), true, 'the emulated phone has the Fullscreen API');

  // ── קריטריון 1: ⛶ → תפריט: לגובה / לרוחב ──
  await fsBtn(a).click();
  await menu(a).waitFor();
  eq(await menu(a).getByRole('menuitem').allTextContents(), ['⛶לגובה', '⟷לרוחב'], 'criterion 1: ⛶ opens a menu: portrait / landscape');
  eq(await fsBtn(a).getAttribute('aria-expanded'), 'true', 'the button says the menu is open');
  eq(await a.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1), true, 'the menu does not overflow the screen');
  await shot(a, 'land-menu');
  await a.keyboard.press('Escape');
  eq([await menu(a).isHidden(), await a.locator('.chart-wrap.fs').count()], [true, 0], 'Esc closes the menu without entering full screen');
  await fsBtn(a).click();
  await menu(a).waitFor();
  await a.locator('.disclaimer').click();
  eq(await menu(a).isHidden(), true, 'a tap outside closes the menu');

  // ── קריטריון 2: "לגובה" — כמו היום ──
  await enter(a, 'לגובה');
  const pr = await boxRect(a);
  eq(pr[0] >= W - 1 && pr[1] >= H * 0.8 && pr[1] > pr[0], true, `criterion 2: portrait fills the screen, taller than wide (${pr})`);
  eq([await ori(a), (await turned(a))[0]], [[], false], 'criterion 2: no orientation lock, no rotation');
  eq(await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לרוחב' }).textContent(), '⟳', 'criterion 6: ⟳ in the floating bar, next to ✕');
  await a.getByRole('button', { name: 'יציאה ממסך מלא' }).click();
  await fsGone(a);
  eq([await boxRect(a), a.url() === url0], [rect0, true], 'criterion 2: ✕ → back to the normal chart');

  // ── קריטריונים 3 ו-7: "לרוחב" עם נעילה; יציאה בשלוש הדרכים ──
  const exits = [
    ['✕', p => p.getByRole('button', { name: 'יציאה ממסך מלא' }).click()],
    ['Esc', p => p.keyboard.press('Escape')],
    ['back', p => p.goBack()],
  ];
  for (const [how, leave] of exits) {
    await enter(a, 'לרוחב');
    await landed(a);
    await a.waitForTimeout(400);
    const lr = await boxRect(a);
    eq(lr[0] > lr[1] && lr[0] >= H - 1, true, `criterion 3 (${how} run): the chart area is wider than tall (${lr})`);
    eq([await a.evaluate(() => screen.orientation.type.startsWith('landscape')), await ori(a), (await turned(a))[0]], [true, ['lock:landscape'], false],
      `criterion 3 (${how} run): locked to landscape, no CSS rotation`);
    eq(await range(a), range0, `criterion 3 (${how} run): the same range stretched over the width (no empty half)`);
    eq(await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לגובה' }).isVisible(), true, `criterion 6 (${how} run): ⟳ now offers portrait`);
    if (how === '✕') await shot(a, 'land-lock');
    await leave(a);
    await fsGone(a);
    eq(await ori(a), ['unlock'], `criterion 7: ${how} → unlock`);
    eq([await a.locator('.chart-wrap.fs').count(), a.url() === url0, await boxRect(a), await range(a)], [0, true, rect0, range0], `criterion 7: ${how} → the normal chart, same size and range`);
  }

  // ── יציאה מה-Fullscreen API מבחוץ (מחווה של המערכת) ──
  await enter(a, 'לרוחב');
  await landed(a);
  await ori(a);
  await a.evaluate(() => document.exitFullscreen());
  await fsGone(a);
  eq([await ori(a), await a.locator('.chart-wrap.fs').count()], [['unlock'], 0], 'leaving the Fullscreen API from outside → unlock and the normal chart');

  // ── קריטריון 6: ⟳ מחליף בלי לצאת ──
  await a.goto(CHART);
  await loaded(a);
  await enter(a, 'לגובה');
  await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לרוחב' }).click();
  await landed(a);
  await a.waitForTimeout(400);
  const sw = await boxRect(a);
  eq([sw[0] > sw[1], await ori(a)], [true, ['lock:landscape']], `criterion 6: ⟳ portrait → landscape in place (${sw})`);
  await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לגובה' }).click();
  await a.waitForFunction(() => window.innerWidth < window.innerHeight);
  await a.waitForTimeout(400);
  const sp = await boxRect(a);
  eq([sp[1] > sp[0], await ori(a), await a.locator('.chart-wrap.fs').count()], [true, ['lock:portrait'], 1], `criterion 6: ⟳ landscape → portrait, still full screen (${sp})`);
  await a.getByRole('button', { name: 'יציאה ממסך מלא' }).click();
  await fsGone(a);
  eq(await ori(a), ['unlock'], 'after ⟳, ✕ unlocks');

  // ── קריטריון 1: מסך רחב (מחשב) — אין תפריט ──
  const { p: d } = await open({ width: 1280, height: 800, mobile: false });
  await fsBtn(d).click();
  await inFs(d);
  eq([await menu(d).isHidden(), await d.locator('.fs-rot').isHidden()], [true, true], 'criterion 1: wide screen → straight into full screen, no menu, no ⟳');
  await d.keyboard.press('Escape');
  await d.waitForFunction(() => !document.querySelector('.chart-wrap.fs'));

  // ── קריטריונים 4, 5, 8: הנעילה נדחית → השכבה המסובבת ──
  for (const lock of ['reject', 'none']) {
    const { p: r } = await open({ lock });
    const r0 = await range(r);
    await enter(r, 'לרוחב');
    const [isTurned, tf] = await turned(r);
    const rr = await boxRect(r);
    const [L, T, R, B] = await onScreen(r);
    eq(isTurned && /^matrix\(0, 1, -1, 0,/.test(tf), true, `criterion 4 (${lock}): CSS rotation, 90° clockwise (${tf})`);
    eq(rr[0] > rr[1] && rr[0] >= H * 0.9, true, `criterion 4 (${lock}): wider than tall in reading direction (${rr})`);
    eq(B - T > R - L && B - T >= H * 0.9, true, `criterion 4 (${lock}): the long side of the chart is along the long side of the screen (${[L, T, R, B].map(Math.round)})`);
    eq(await r.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לגובה' }).isVisible(), true, `criterion 6 (${lock}): ⟳ in the rotated layer`);
    if (lock === 'reject') await shot(r, 'land-turned');

    // קריטריון 5 — הסמן מתחת לאצבע (עכבר — אותה המרה), ובכל נקודה
    for (const [fx, fy] of [[0.3, 0.4], [0.7, 0.6]]) {
      const sx = L + (R - L) * fx, sy = T + (B - T) * fy;
      await r.mouse.move(sx, sy);
      await r.waitForTimeout(150);
      const c = await cross(r);
      const want = { x: sy - T, y: R - sx };
      eq(!!c && Math.abs(c.x - want.x) <= 2 && Math.abs(c.y - want.y) <= 2, true, `criterion 5 (${lock}): crosshair under the pointer (${JSON.stringify(c)} vs ${JSON.stringify(want)})`);
    }
    // קריטריון 5 — גרירה לאורך הגרף, כפי שהמשתמש רואה אותו (על המסך: מלמטה למעלה = מימין לשמאל בקריאה)
    const mid = (L + R) / 2;
    await swipe(r, [mid, T + (B - T) * 0.3], [mid, T + (B - T) * 0.7]);
    await r.waitForTimeout(1500);   // גלילה קינטית אחרי שהאצבע עזבה
    const r1 = await range(r);
    eq(r1[0] < r0[0] - 5, true, `criterion 5 (${lock}): a finger drag along the chart moves the time axis (${r0.map(Math.round)} → ${r1.map(Math.round)})`);
    await swipe(r, [L + (R - L) * 0.2, (T + B) / 2], [L + (R - L) * 0.8, (T + B) / 2]);
    await r.waitForTimeout(1500);
    const r2 = await range(r);
    eq(Math.abs(r2[0] - r1[0]) < 3, true, `criterion 5 (${lock}): a drag across the chart (up-down when reading) does not move the time axis (${r1.map(Math.round)} → ${r2.map(Math.round)})`);

    // קריטריון 7 — יציאה: בלי סיבוב, לאורך, והגרף בגודלו
    await r.getByRole('button', { name: 'יציאה ממסך מלא' }).click();
    await fsGone(r);
    eq([(await turned(r))[0], await boxRect(r)], [false, rect0], `criterion 7 (${lock}): ✕ → no rotation, the normal chart`);
    eq(await ori(r), lock === 'reject' ? ['lock:landscape', 'unlock'] : [], `criterion 7 (${lock}): unlock after a lock was tried`);

    // ⟳ בשכבה: לגובה ובחזרה
    await enter(r, 'לגובה');
    await r.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לרוחב' }).click();
    await r.waitForTimeout(400);
    eq((await turned(r))[0], true, `criterion 6 (${lock}): ⟳ portrait → rotated layer`);
    await r.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לגובה' }).click();
    await r.waitForTimeout(400);
    eq([(await turned(r))[0], await r.locator('.chart-wrap.fs').count()], [false, 1], `criterion 6 (${lock}): ⟳ back to portrait, still full screen`);
    await r.keyboard.press('Escape');
    await fsGone(r);
  }

  // ── קריטריון 8: השיטות והשרטוטים בשכבה המסובבת; ציור — ההמרה חלה גם עליו ──
  const { p: w } = await open({ lock: 'reject' });
  await w.getByRole('button', { name: 'תחזית שיטה' }).first().click();
  await w.locator('.fc-pop .fc-opt', { hasText: 'אליוט' }).locator('input').check();
  await w.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
  await w.keyboard.press('Escape');
  await w.click('[aria-label="ציור על הגרף"]');
  await w.click('.draw-btn[aria-label="קו אופקי"]');
  await enter(w, 'לרוחב');
  eq((await turned(w))[0], true, 'criterion 8: in the rotated layer');
  eq(await w.locator('.chart-wrap.fs .fc-legend').isVisible(), true, 'criterion 8: the method legend shows in the rotated layer');
  const [L, T, R, B] = await onScreen(w);
  const sx = L + (R - L) * 0.4, sy = T + (B - T) * 0.5;
  const want = await w.evaluate(y => document.querySelector('.chart-box:not(.osc)').tsPriceAt(y), R - sx);
  await w.mouse.click(sx, sy);
  await w.waitForTimeout(600);
  const dr = await w.evaluate(async () => (await (await fetch('api.php?action=drawings&asset=binance%3ABTCUSDT')).json()).drawings);
  const got = dr.find(x => x.kind === 'hline')?.points[0].p;
  eq(!!got && Math.abs(got - want) / want < 0.001, true, `criterion 8: a horizontal line drawn in the rotated layer lands at the finger's price (${got} vs ${want})`);
  await shot(w, 'land-turned-elliott');
  await w.keyboard.press('Escape');
  await fsGone(w);

  // ── כהה ──
  if (SHOTS) {
    const { p: k } = await open({ scheme: 'dark' });
    await fsBtn(k).click();
    await menu(k).waitFor();
    await shot(k, 'land-menu-dark');
    await menu(k).getByRole('menuitem', { name: 'לרוחב' }).click();
    await landed(k);
    await k.waitForTimeout(500);
    await shot(k, 'land-lock-dark');
  }
} catch (e) {
  fail++;
  console.log('✗', e.stack);
} finally {
  await b.close();
  srv.kill();
}
const errs = errors.filter(e => !/favicon|sw\.js/.test(e));
eq(errs, [], 'no page errors');
console.log(fail ? `✗ ${fail}/${n} נכשלו` : `✓ ${n} בדיקות עברו`);
process.exit(fail ? 1 : 0);
