// אליוט בגרף בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 17.1.7, 17.2.2, ✅15, ✅16; PR 4א/4): 🔮 ← גלי אליוט, ספירות
// במילים, החלפה בין ספירות, "בדוק בעצמך", ציור על הקנבס. node trading-strategies/tests/elliott-chart-ui.mjs
// SHOTS=<תיקייה> — שומר צילומי מסך (deviceScaleFactor 2) לבקרה.
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-ew-chart-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'alertable.php') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const SHOTS = process.env.SHOTS;
const NOW = +(process.env.TS_NOW || 1794094200);
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });   // "עכשיו" קבוע לשרת — אותם נרות בכל ריצה
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  const reqs = [];
  p.on('request', r => { if (r.url().includes('api.php')) reqs.push(new URL(r.url()).searchParams.get('action')); });
  return { p, reqs };
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
// "טביעת אצבע" של מה שמצויר בקנבסים של הגרף הראשי
const canvasPrint = p => p.evaluate(() => [...document.querySelectorAll('.chart-box:not(.osc) canvas')].map(c => c.toDataURL().length).join(','));

try {
  const { p: o, reqs } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main');
  eq(await post(o, 'admin_method_save', { id: 'elliott-w3', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4'], params: { pivot_atr_mult: 2 } }), 200, 'the admin turns elliott-w3 on (sensitivity 2)');
  await o.goto(CHART);
  await loaded(o);
  const before = await canvasPrint(o);
  await o.getByRole('button', { name: 'תחזית שיטה' }).click();
  const dlg = o.locator('dialog[open]');
  await dlg.waitFor();
  reqs.length = 0;
  await dlg.getByRole('button', { name: /גלי אליוט — כניסה לגל 3/ }).click();
  const panel = o.locator('.forecast-panel');
  await panel.locator('.chips').waitFor({ timeout: 60000 });
  eq((await panel.locator('h3').textContent()).trim(), '🔮 תרחיש לפי שיטת גלי אליוט — כניסה לגל 3 — לא ייעוץ', 'the Elliott forecast title (✅15)');
  const chips = await panel.locator('.chip').allTextContents();
  eq([chips.includes('לפי כללי השיטה, לא נבדק לאחור'), chips.includes('שיטה שלנו על בסיס כללי הספר (Frost & Prechter)')], [true, true], 'marked "not backtested", with "our method based on the book"');
  await panel.locator('details summary', { hasText: '🔎 בדוק בעצמך' }).click();
  eq(await panel.locator('details li').count() >= 4, true, '"check yourself": the guidelines the computer does not measure');
  const sc = panel.locator('.forecast-scenarios button');
  const nsc = await sc.count();
  const labels = await sc.allTextContents();
  eq(labels.every(t => /^התאמה (גבוהה|בינונית) · דחף/.test(t)), true, `counts in words only (✅16): ${labels.join(' | ') || 'none'}`);
  eq(/\d\s*%/.test(await panel.textContent()), false, 'no percentage in the panel');
  if (nsc) {
    await o.waitForTimeout(400);
    const drawn = await canvasPrint(o);
    eq(drawn !== before, true, 'the counts, zone, invalidation and projection are drawn on the canvas');
    eq(/רמת ביטול|אזור לגל/.test(await o.evaluate(() => document.body.innerText)), false, 'the Elliott texts are canvas, not HTML');
    if (nsc > 1) {
      await sc.nth(1).click();
      eq([await sc.nth(1).getAttribute('aria-pressed'), await sc.nth(0).getAttribute('aria-pressed')], ['true', 'false'], 'tapping an alternate count makes it the highlighted one');
      await o.waitForTimeout(300);
      eq(await canvasPrint(o) !== drawn, true, '… and the drawing changes');
    }
  } else {
    eq((await panel.textContent()).includes('אין כרגע ספירה'), true, 'no count → the panel says why');
  }
  eq(reqs.filter(a => a === 'method_overlay').length, 1, 'one request — only after the user chose');
  eq(await noHScroll(o), true, 'no horizontal scroll on a phone');
  await shot(o, 'elliott-chart');
} catch (e) {
  fail++;
  console.log('✗ ' + e.stack);
} finally {
  eq(errors, [], 'no page errors');
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
