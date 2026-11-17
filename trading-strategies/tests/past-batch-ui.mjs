// H1c (spec-stage-a 18.1.8 סעיף 6, קריטריון 12) בדפדפן: גלילה אל העבר מביאה מנה ישנה (market_history), ואחריה method_history —
// אותות וספירות עבר רק לפני הנר הראשון הקודם. כל מה שהיה על המסך — אותו זמן, אותה תוצאה, אותה תווית; חדשים — רק לפני.
// node trading-strategies/tests/past-batch-ui.mjs   (Playwright; בלי רשת)
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
const DB = join(tmpdir(), `ts-past-batch-ui-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`, '-d', 'memory_limit=512M'],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'), TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const NOW = 1794342600;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  return [p, await ctx.newCDPSession(p)];
}
const sel = '.chart-box:not(.osc)';
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const st = p => p.evaluate(s => document.querySelector(s).tsForecast(), sel);
const hs = p => p.evaluate(s => document.querySelector(s).tsHistory(), sel);
async function swipeRight(p, cdp) {   // הגרף ימינה — אל העבר
  const r = await p.evaluate(s => { const b = document.querySelector(s).getBoundingClientRect(); return [b.x, b.y, b.width, b.height]; }, sel);
  const y = r[1] + r[3] * 0.5, x0 = r[0] + r[2] * 0.15, x1 = r[0] + r[2] * 0.85;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x0, y }] });
  for (let i = 1; i <= 12; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x0 + (x1 - x0) * i / 12, y }] }); await p.waitForTimeout(16); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}
// מה שמזהה פריט עבר על המסך — בלי קואורדינטות (המנה מזיזה את הגרף); הזמן קובע את הנר
// כל פריטי העבר (גם מחוץ לתצוגה) — כל השדות: זמן, צד, כניסה, סטופ, תוצאה, R, יציאה, אזור, נקודות הספירה
const sig = s => s.pastItems.filter(x => x.kind === 'past');
const cnt = s => s.pastItems.filter(x => x.kind === 'pastcount');

try {
  const [o] = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  eq(await o.evaluate(async () => { const me = await (await fetch('api.php?action=me')).json();
    return (await fetch('api.php?action=admin_method_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf },
      body: JSON.stringify({ id: 'fibonacci', enabled: true, markets: ['crypto'], timeframes: ['h1'] }) })).status; }), 200, 'the owner turns on fibonacci');

  const [p, cdp] = await open('alice');
  const mh = [];
  p.on('request', r => { const u = new URL(r.url()); if (u.searchParams.get('action') === 'method_history') mh.push(Object.fromEntries(u.searchParams)); });
  await p.goto(BASE + '#/market');
  await p.waitForSelector('main [role="combobox"]');
  await p.goto(CHART);
  await loaded(p);
  await p.getByRole('button', { name: 'תחזית שיטה' }).click();
  for (const re of [/אליוט/, /פיבונאצ/]) await p.locator('.fc-pop .fc-opt', { hasText: re }).locator('input').check();
  await p.locator('.quote-head').click();
  await p.waitForFunction(s => { const n = document.querySelector(s).tsForecast().pastN || {}; return n['elliott-w3'] != null && n.fibonacci != null; }, sel, { timeout: 60000 });
  await p.waitForTimeout(400);
  const h0 = await hs(p);
  const s0 = await st(p);
  eq([h0.older, mh.length, s0.past.length > 0], [0, 0, true], `before scrolling: no older batch, no method_history, ${s0.past.length} past signals in view`);

  await swipeRight(p, cdp);
  await p.waitForFunction(s => document.querySelector(s).tsHistory().older > 0, sel, { timeout: 20000 });
  await p.waitForFunction(() => true, null);
  for (let i = 0; i < 100 && !mh.length; i++) await p.waitForTimeout(100);
  await p.waitForFunction(s => !document.querySelector(s).tsHistory().busy, sel, { timeout: 30000 });
  await p.waitForTimeout(400);
  const h1 = await hs(p);
  eq([mh.length, +mh[0]?.before, +mh[0]?.from, (mh[0]?.methods || '').split(',').sort()], [1, h0.first, h1.first, ['elliott-w3', 'fibonacci']],
    'one batch → one method_history: from the batch\'s first candle, before = the previous first candle, the methods on the chart');
  const s1 = await st(p);
  // קריטריון 12: כל מה שהיה — באותו זמן, עם אותה תוצאה ותווית
  const key = x => JSON.stringify(x);
  const after = new Set([...sig(s1).map(key), ...cnt(s1).map(key)]);
  const lost = [...sig(s0), ...cnt(s0)].filter(x => !after.has(key(x)));
  eq(lost, [], `criterion 12: every past signal and count that was on screen (${s0.pastItems.length}) — the same time, result and label`);
  const prev = new Set([...sig(s0), ...cnt(s0)].map(key));
  const added = [...sig(s1), ...cnt(s1)].filter(x => !prev.has(key(x)));
  eq(added.every(x => x.t < h0.first), true, `… new items (${added.length}) only before the previous first candle`);
  const N1 = s1.pastN;
  eq(N1['elliott-w3'] >= s0.pastN['elliott-w3'] && N1.fibonacci >= s0.pastN.fibonacci && N1.fibonacci + N1['elliott-w3'] > s0.pastN.fibonacci + s0.pastN['elliott-w3'], true,
    `the counts grow with the batch (${JSON.stringify(s0.pastN)} → ${JSON.stringify(N1)})`);
  // התקרה — על כל הטווח: לא יותר מ-50 לשיטה בסך הכול
  await p.getByRole('button', { name: 'תחזית שיטה' }).click();
  const rows = await p.locator('.fc-pop .fc-hist-n').allTextContents();
  eq(rows.length === 2 && rows.every(t => t.includes(`ב-${(h1.n - 1).toLocaleString('he-IL')} הנרות האחרונים`)), true,
    `the menu counts the batch too — all the closed candles on the chart, ${(h1.n - 1).toLocaleString('he-IL')} (${rows.join(' | ')})`);
  await p.locator('.quote-head').click();
  // החלפת טווח → המנות הישנות יורדות מהגרף, וגם אותות העבר שלהן
  await p.locator('select[name="range"]').selectOption('m');
  await loaded(p);
  await p.waitForTimeout(800);
  const s2 = await st(p);
  const f2 = (await hs(p)).first;
  eq(s2.past.every(x => x.t >= f2), true, 'another range → the older batch\'s past items are gone with it');
  eq(errors, [], 'no errors in the page');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
