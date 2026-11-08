// "פתח עסקה מזה" וסטטיסטיקה לפי שיטה, בדפדפן אמיתי ברוחב טלפון (spec-stage-a 11; פרק 15: טלפון, RTL). בלי רשת.
// node trading-strategies/tests/journal-ui.mjs   (Playwright)   SHOTS=<תיקייה> — צילומי מסך
import { createRequire } from 'module';
import { spawn, spawnSync } from 'child_process';
import { tmpdir } from 'os';
import { createServer } from 'net';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
// פורט פנוי מהמערכת — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת של ריצה אחרת (#1333)
const port = await new Promise(r => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const RUN = `${process.pid}-${Math.random().toString(16).slice(2, 10)}`;
const DB = join(tmpdir(), `ts-journal-ui-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'alertable.php') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const NOW = +(process.env.TS_NOW || 1794094200);
const b = await chromium.launch();
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  // "עכשיו" קבוע לשרת (X-Dev-Now, רק ב-TS_DEV): הנרות המדומים תלויים בזמן, ובלי זה "יש רמות / אין רמות" תלוי בשעה
  // שבה הבדיקה רצה. כמו בבדיקות ה-API
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  return p;
}
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);

try {
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main form');
  await post(o, 'admin_method_save', { id: 'test-cross-b', enabled: true, markets: ['crypto'], timeframes: ['h4'] });

  const a = await open('alice');
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main form');
  await post(a, 'watchlist_add', { asset: 'binance:BTCUSDT' });
  await a.goto(BASE + '#/analysis/' + encodeURIComponent('binance:BTCUSDT'));
  await a.locator('fieldset[aria-label="מסגרת זמן"] label', { hasText: '4 ש׳' }).click();
  await a.getByRole('button', { name: '🧭 נתח' }).click();
  await a.waitForSelector('.analysis-card', { timeout: 60000 });
  const btn = a.getByRole('button', { name: '📓 פתח עסקה מזה' });
  eq(await btn.count(), 1, '"📓 פתח עסקה מזה" on a card with levels');
  const entry = (await a.locator('.analysis-card .cells dd').first().textContent()).replace(/[,\s⁦⁩]/g, '');
  await btn.click();
  await a.waitForSelector('dialog input[name="symbol"]');
  eq(await a.locator('dialog input[name="symbol"]').inputValue(), 'BTCUSDT', 'symbol in the journal — without the provider');
  eq(await a.locator('dialog fieldset[aria-label="שוק"] input:checked').getAttribute('value'), 'crypto', 'market from the provider');
  eq(await a.locator('dialog input[name="currency"]').inputValue(), 'USDT', 'currency from the asset');
  eq((await a.locator('dialog input[name="entry_price"]').inputValue()).replace(/,/g, ''), entry, 'entry = the rounded level from the card (a decimal string)');
  eq((await a.locator('dialog .chip').first().textContent()).includes('מכרטיס ניתוח'), true, 'the form says it came from an analysis card');
  eq(await a.evaluate(() => { const d = document.querySelector('dialog'); return d.scrollWidth <= d.clientWidth + 1; }), true, 'trade sheet: no horizontal scroll on a phone');
  await shot(a, 'trade-from-analysis');
  eq(await a.locator('dialog input[name="quantity"]').inputValue(), '', 'no account size in the profile → quantity left for the user');
  await a.locator('dialog input[name="quantity"]').fill('0.1');
  await a.locator('dialog').getByRole('button', { name: 'שמירה' }).click();
  await a.waitForFunction(() => location.hash.startsWith('#/journal') || document.querySelector('dialog .error:not([hidden])'), null, { timeout: 15000 });
  const err = (await a.locator('dialog .error:not([hidden])').count()) ? await a.locator('dialog .error:not([hidden])').textContent() : null;
  eq(err, null, 'saved without an error');
  await a.waitForSelector('main .card');
  eq((await a.locator('main .card').first().textContent()).includes('🧭 בדיקה ב'), true, 'the journal row shows the method');
  const tr = await a.evaluate(async () => (await (await fetch('api.php?action=trades')).json()).trades[0]);
  eq([tr.method_id, tr.analysis_snapshot.asset, tr.analysis_snapshot.tf], ['test-cross-b', 'binance:BTCUSDT', 'h4'], 'saved with the method and the snapshot');
  await a.goto(BASE + '#/stats');
  await a.waitForSelector('main select[name="method_id"]');
  eq(await a.locator('main select[name="method_id"] option').count() >= 3, true, 'stats: filter by method');
  eq(await noHScroll(a), true, 'stats: no horizontal scroll');
} catch (e) {
  fail++; console.log('✗', e.message);
} finally {
  await b.close();
  srv.kill();
}
eq(errors, [], 'no page errors');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
