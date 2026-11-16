// L2 (spec-stage-a 23.4, ל3 / ✅36) בדפדפן: "העסקאות שלי בלמידה" בפרופיל — דלוק כברירת מחדל, כיבוי נשמר בשרת ונשאר אחרי
// טעינה מחדש, ובטלפון (390 ו-320) בלי גלילה לרוחב.
// node trading-strategies/tests/learn-switch-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-learn-switch-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const b = await chromium.launch();
async function open(as, { width = 390, scheme = 'light' } = {}) {
  const ctx = await b.newContext({ viewport: { width, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));
  return { p, errors };
}
const go = async (p, hash) => { await p.goto(BASE + hash); await p.locator('main .loading').waitFor({ state: 'detached', timeout: 20000 }).catch(() => {}); };
const sw = p => p.locator('.learn-block input[type="checkbox"]');
const noScroll = p => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

try {
  const a = await open('alice');
  await go(a.p, '#/profile');
  await sw(a.p).waitFor();
  eq(await sw(a.p).isChecked(), true, 'on by default');
  eq(await a.p.locator('.learn-block').textContent().then(t => t.includes('בלי שמך')), true, 'the hint says: without your name');
  eq(await noScroll(a.p), true, '390: no horizontal scroll');
  if (SHOTS) await a.p.locator('.learn-block').screenshot({ path: join(SHOTS, 'learn-switch.png') });
  const [resp] = await Promise.all([a.p.waitForResponse(r => r.url().includes('action=learn_off_set')), sw(a.p).click()]);
  eq([resp.status(), (await resp.json()).learn_off], [200, true], 'turning it off → saved on the server');
  await go(a.p, '#/profile');
  await sw(a.p).waitFor();
  eq(await sw(a.p).isChecked(), false, 'after a reload — still off');
  const [r2] = await Promise.all([a.p.waitForResponse(r => r.url().includes('action=learn_off_set')), sw(a.p).click()]);
  eq((await r2.json()).learn_off, false, 'and on again');

  const s = await open('bob', { width: 320, scheme: 'dark' });
  await go(s.p, '#/profile');
  await sw(s.p).waitFor();
  eq([await sw(s.p).isChecked(), await noScroll(s.p)], [true, true], '320 dark: another user\'s switch is his own (on), no horizontal scroll');
  if (SHOTS) await s.p.screenshot({ path: join(SHOTS, 'learn-switch-320-dark.png'), fullPage: true });
  for (const x of [a, s]) eq(x.errors, [], 'no page errors');
} catch (e) {
  fail++;
  console.log('✗ ' + (e.stack || e));
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail}/${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
