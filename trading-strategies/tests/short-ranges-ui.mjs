// טווחים קצרים מיום (בחירת הבעלים, 2026-11-17): "15 דקות", "שעה", "4 שעות" לפני "יום". טווח שנותן פחות מ-10 נרות במסגרת — המסגרת
// יורדת לגדולה ביותר שנותנת לפחות 10, עם הודעה שקטה; אין כזו בספק — האפשרות מושבתת עם הסבר. נשמר ונטען; הפעימות עובדות בגלילה.
// node trading-strategies/tests/short-ranges-ui.mjs   (Playwright; בלי רשת)
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-short-ranges-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'), TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const NOW = 1794094200;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
await ctx.addCookies([{ name: 'dev_as', value: 'alice', url: BASE }]);
await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
await ctx.clock.setFixedTime(NOW * 1000);
await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
const p = await ctx.newPage();
p.on('pageerror', e => errors.push(e.message));
p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
const cdp = await ctx.newCDPSession(p);
const sel = '.chart-box:not(.osc)';
const hs = () => p.evaluate(s => document.querySelector(s).tsHistory(), sel);
const tf = () => p.evaluate(() => document.querySelector('fieldset.seg input[name="tf"]:checked')?.value);
const loaded = () => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const reqs = [];
p.on('request', r => { const u = new URL(r.url()); const a = u.searchParams.get('action'); if (a === 'chart_refresh' || a === 'market_history') reqs.push(Object.fromEntries(u.searchParams)); });
async function pick(v) {
  const before = reqs.length;
  await p.locator('select[name="range"]').selectOption(v);
  for (let i = 0; i < 100 && !reqs.slice(before).some(r => r.action === 'chart_refresh'); i++) await p.waitForTimeout(100);
  await p.waitForFunction(() => !/טוען/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
  await p.waitForTimeout(300);
  return reqs.slice(before).filter(r => r.action === 'chart_refresh').at(-1);
}
async function swipeRight() {
  const r = await p.evaluate(s => { const b = document.querySelector(s).getBoundingClientRect(); return [b.x, b.y, b.width, b.height]; }, sel);
  const y = r[1] + r[3] * 0.5, x0 = r[0] + r[2] * 0.15, x1 = r[0] + r[2] * 0.85;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x0, y }] });
  for (let i = 1; i <= 12; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x0 + (x1 - x0) * i / 12, y }] }); await p.waitForTimeout(16); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}

try {
  await p.goto(BASE + '#/market');
  await p.waitForSelector('main [role="combobox"]');
  await p.goto(CHART);
  await loaded();

  // ── 1. הרשימה: 15 דקות, שעה, 4 שעות — לפני "יום" ──
  const opts = (await p.locator('select[name="range"] option').allTextContents()).map(x => x.trim());
  eq(opts.slice(0, 4), ['15 דקות', 'שעה', '4 שעות', 'יום'], 'the range list starts with "15 דקות", "שעה", "4 שעות", then "יום"');
  eq(await tf(), 'h1', 'the chart opens on the hour');

  // ── 2. "שעה" במסגרת שעה (נר אחד) → 5 ד׳, הודעה שקטה, 10+ נרות ──
  const r1 = await pick('r1h');
  const n1 = (await hs()).n;
  eq([await tf(), r1?.tf, +r1?.to - +r1?.from, n1 >= 10 && n1 <= 14], ['m5', 'm5', 3600, true], `"שעה" on the hour → the timeframe drops to 5 min, the request is one hour of 5-min candles (${n1} candles)`);
  eq((await p.locator('.toast').last().textContent()).trim(), 'המסגרת עברה ל-5 ד׳ — כדי שיהיו לפחות 10 נרות בטווח', '… with a quiet note');
  // ── 3. "15 דקות" → דקה ──
  const r2 = await pick('r15');
  const n2 = (await hs()).n;
  eq([await tf(), r2?.tf, n2 >= 10 && n2 <= 17], ['m1', 'm1', true], `"15 דקות" → 1 min (${n2} candles)`);
  // ── 4. "4 שעות" מדקה — הדקה נותנת מספיק: המסגרת לא משתנה ──
  const r3 = await pick('r4h');
  eq([await tf(), r3?.tf, +r3?.to - +r3?.from], ['m1', 'm1', 4 * 3600], '"4 שעות" while on 1 min — enough candles already, the timeframe stays');
  // ── 5. נשמר ונטען ──
  await pick('r1h');
  await p.reload();
  await loaded();
  eq([await p.locator('select[name="range"]').inputValue(), await tf()], ['r1h', 'm1'], 'reload → the short range and its timeframe are kept (1 min gives 60 candles in an hour — it stays)');
  // ── 6. הפעימות — גלילה אל העבר בטווח קצר מביאה מנה ישנה ──
  const h0 = await hs();
  for (let i = 0; i < 4 && (await hs()).older === 0; i++) { await swipeRight(); await p.waitForTimeout(1500); }
  const h1 = await hs();
  eq([h1.older > 0, h1.n > h0.n, reqs.some(r => r.action === 'market_history' && r.tf === 'm1')], [true, true, true], `the pulses still work on a short range: scrolling left brings an older batch (${h0.n} → ${h1.n})`);
  // ── 7. בחירת מסגרת אחרי טווח קצר — הטווח חוזר לברירת המחדל של המסגרת ──
  await p.locator('fieldset.seg label', { hasText: 'שעה' }).first().click();
  await loaded();
  eq([await tf(), await p.locator('select[name="range"]').inputValue()], ['h1', 'q'], 'picking the hour timeframe → its default range (3 months)');

  // ── 8. ספק יומי בלבד (ECB): הטווחים הקצרים מושבתים, עם הסבר ──
  await p.goto(BASE + '#/chart/' + encodeURIComponent('ecb:EURUSD'));
  await loaded();
  const ecb = await p.locator('select[name="range"] option').evaluateAll(os => os.slice(0, 5).map(o => [o.value, o.disabled, o.textContent.trim()]));
  eq(ecb.map(([v, d]) => [v, d]), [['r15', true], ['r1h', true], ['r4h', true], ['d', true], ['w', true]], 'a daily-only provider (ECB): 15 min / hour / 4 h / day / week give fewer than 10 candles — disabled');
  eq(ecb[0][2], '15 דקות — לא זמין בספק הזה', '… with the reason in the option');
  eq(errors, [], 'no errors');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
