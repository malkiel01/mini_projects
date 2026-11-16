// 2.30 — מסך מלא לגובה או לרוחב, בדפדפן אמיתי (בקשת הבעלים 2026-11-16: "גם הגדלה לגובה וגם הרחבה לרוחב").
// node trading-strategies/tests/chart-landscape-ui.mjs   (Playwright, הדמיית טלפון; ספקים מדומים, בלי רשת)
// SHOTS=<תיקייה> — צילומי מסך (deviceScaleFactor 2).
//
// הטלפון מדומה: screen.orientation.lock/unlock מוחלפים בפונקציה שמסובבת את ה-viewport (כמו טלפון שמסתובב) ורושמת
// את הקריאה. כך נבדק הקוד שלנו — מה הוא מבקש ומתי — ושאזור הגרף באמת לרוחב אחרי הסיבוב.
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
async function open({ lock = 'ok', scheme = 'light' } = {}) {
  const ctx = await b.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
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
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  await p.goto(CHART);
  await p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
  return { p, ctx };
}
// צילום דרך CDP: page.screenshot מחיל מחדש את ה-viewport של Playwright ומבטל את הסיבוב המדומה
const shot = async (p, name) => {
  if (!SHOTS) return;
  const c = await p.context().newCDPSession(p);
  const { data } = await c.send('Page.captureScreenshot', { format: 'png' });
  writeFileSync(join(SHOTS, name + '.png'), Buffer.from(data, 'base64'));
  await c.detach();
};
const boxRect = p => p.evaluate(() => { const r = document.querySelector('.chart-box:not(.osc)').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
const ori = p => p.evaluate(() => window.__ori.splice(0));
const fsBtn = p => p.getByRole('button', { name: 'מסך מלא', exact: true });
const menu = p => p.getByRole('menu', { name: 'מסך מלא' });
const inFs = p => p.locator('.chart-wrap.fs').waitFor();
async function fsGone(p) {
  await p.waitForFunction(() => !document.querySelector('.chart-wrap.fs') && !document.fullscreenElement);
  await p.waitForTimeout(500);
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
  const url0 = a.url();
  eq(await a.evaluate(() => [document.fullscreenEnabled, matchMedia('(pointer: coarse)').matches]), [true, true], 'the emulated phone has Fullscreen API and touch');

  // ── ⛶ פותח בחירה: לגובה / לרוחב ──
  await fsBtn(a).click();
  await menu(a).waitFor();
  eq(await menu(a).getByRole('menuitem').allTextContents(), ['⛶לגובה', '⟷לרוחב'], '⛶ opens a choice: portrait / landscape');
  eq(await fsBtn(a).getAttribute('aria-expanded'), 'true', 'the button says the choice is open');
  eq(await a.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1), true, 'the choice does not overflow the screen');
  await shot(a, 'land-menu');
  await a.keyboard.press('Escape');
  eq([await menu(a).isHidden(), await a.locator('.chart-wrap.fs').count()], [true, 0], 'Esc closes the choice without entering full screen');
  await fsBtn(a).click();
  await menu(a).waitFor();
  await a.locator('.disclaimer').click();
  eq(await menu(a).isHidden(), true, 'a tap outside closes the choice');

  // ── "לגובה" — כמו קודם: ממלא את המסך, לאורך, בלי נעילה ──
  await enter(a, 'לגובה');
  const pr = await boxRect(a);
  eq(pr[0] >= W - 1 && pr[1] >= H * 0.8 && pr[1] > pr[0], true, `portrait: fills the screen, taller than wide (${pr})`);
  eq(await ori(a), [], 'portrait: no orientation lock is requested');
  eq(await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לרוחב' }).isVisible(), true, 'the floating bar has a ⟷ switch next to ✕');
  await a.getByRole('button', { name: 'יציאה ממסך מלא' }).click();
  await fsGone(a);
  eq([await boxRect(a), a.url() === url0], [rect0, true], 'portrait: ✕ → back to the normal chart');

  const range = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsRange());
  const range0 = await range(a);
  // ── "לרוחב": הגרף רחב מגובהו; יציאה בשלוש הדרכים ──
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
    eq(lr[0] > lr[1] && lr[0] >= H - 1, true, `landscape (${how} run): the chart area is wider than tall (${lr})`);
    eq(await a.evaluate(() => document.fullscreenElement === document.querySelector('.chart-wrap')), true, `landscape (${how} run): in Fullscreen API`);
    eq(await ori(a), ['lock:landscape'], `landscape (${how} run): locks to landscape after entering full screen`);
    eq(await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לגובה' }).isVisible(), true, `landscape (${how} run): the switch now offers portrait`);
    eq(await range(a), range0, `landscape (${how} run): the same time range is stretched over the width (no empty half)`);
    if (how === '✕') await shot(a, 'land-fullscreen');
    await leave(a);
    await fsGone(a);
    await a.waitForFunction(() => window.innerWidth < window.innerHeight);
    await a.waitForTimeout(300);
    eq(await ori(a), ['unlock'], `landscape: ${how} → unlocks the orientation`);
    eq([await a.locator('.chart-wrap.fs').count(), a.url() === url0, await boxRect(a)], [0, true, rect0], `landscape: ${how} → back to the normal chart`);
  }

  // ── יציאה מה-Fullscreen API מבחוץ (מחווה של המערכת) → fullscreenchange ──
  await enter(a, 'לרוחב');
  await landed(a);
  await ori(a);
  await a.evaluate(() => document.exitFullscreen());
  await fsGone(a);
  eq([await ori(a), await a.locator('.chart-wrap.fs').count()], [['unlock'], 0], 'landscape: leaving the Fullscreen API from outside → unlock and the normal chart');

  // ── ההחלפה בשורה הצפה: לגובה ⇄ לרוחב בלי לצאת ──
  await a.goto(CHART);
  await a.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
  await enter(a, 'לגובה');
  await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לרוחב' }).click();
  await landed(a);
  await a.waitForTimeout(400);
  const sw = await boxRect(a);
  eq([sw[0] > sw[1], await ori(a)], [true, ['lock:landscape']], `switch ⟷: portrait → landscape in place (${sw})`);
  await a.locator('.fs-bar').getByRole('button', { name: 'מסך מלא לגובה' }).click();
  await a.waitForFunction(() => window.innerWidth < window.innerHeight);
  await a.waitForTimeout(400);
  const sp = await boxRect(a);
  eq([sp[1] > sp[0], await ori(a), await a.locator('.chart-wrap.fs').count()], [true, ['lock:portrait'], 1], `switch ⛶: landscape → portrait, still full screen (${sp})`);
  await a.getByRole('button', { name: 'יציאה ממסך מלא' }).click();
  await fsGone(a);
  eq(await ori(a), ['unlock'], 'after a switch, ✕ unlocks');

  // ── הנעילה נדחתה: נשארים לגובה, הודעה, ו"לרוחב" נעלם ──
  const { p: r } = await open({ lock: 'reject' });
  await enter(r, 'לרוחב');
  await r.locator('.toast, [role="status"], [role="alert"]').filter({ hasText: 'לסובב את הטלפון ביד' }).first().waitFor();
  eq([await r.locator('.chart-wrap.fs').count(), await r.locator('.fs-rot').isHidden()], [1, true], 'lock rejected: stays in full screen (portrait), the switch is hidden');
  await r.getByRole('button', { name: 'יציאה ממסך מלא' }).click();
  await fsGone(r);
  await fsBtn(r).click();
  await inFs(r);
  eq(await menu(r).isHidden(), true, 'lock rejected: next time ⛶ goes straight to portrait, no choice');

  // ── אין screen.orientation.lock (Safari, iOS): אין "לרוחב" בכלל ──
  const { p: s } = await open({ lock: 'none' });
  await fsBtn(s).click();
  await inFs(s);
  await s.waitForTimeout(300);
  const nr = await boxRect(s);
  eq([await menu(s).isHidden(), await s.locator('.fs-rot').isHidden(), nr[1] > nr[0]], [true, true, true], 'no lock: ⛶ enters portrait directly, no landscape anywhere');
  await s.keyboard.press('Escape');
  await fsGone(s);

  // ── כהה ──
  if (SHOTS) {
    const { p: k } = await open({ scheme: 'dark' });
    await fsBtn(k).click();
    await menu(k).waitFor();
    await shot(k, 'land-menu-dark');
    await menu(k).getByRole('menuitem', { name: 'לרוחב' }).click();
    await landed(k);
    await k.waitForTimeout(500);
    await shot(k, 'land-fullscreen-dark');
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
