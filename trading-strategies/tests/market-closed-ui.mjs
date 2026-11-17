// "השוק סגור" בגרף (בקשת הבעלים, 2026-11-17 — AAPL ב-08:56: "חשבתי שהגרף תקוע על 22:59 של אתמול"): תווית בראש הגרף עם שעון עד
// הפתיחה, שמתעדכן כל שנייה; כשמגיע הזמן — התווית יורדת והגרף נטען מיד. קריפטו — בלי תווית. שעון מדומה.
// node trading-strategies/tests/market-closed-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-market-closed-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const SHOTS = process.env.SHOTS;
const NOW = Date.UTC(2026, 10, 17, 6, 56) / 1000;   // שלישי 08:56 שעון ישראל — כמו אצל הבעלים
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, timezoneId: 'Asia/Jerusalem' });
await ctx.addCookies([{ name: 'dev_as', value: 'alice', url: BASE }]);
await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
await ctx.clock.install({ time: NOW * 1000 });
await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
const p = await ctx.newPage();
p.on('pageerror', e => errors.push(e.message));
p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
const reqs = [];
p.on('request', r => { const a = new URL(r.url()).searchParams.get('action'); if (a === 'chart_refresh') reqs.push(Date.now()); });
const sel = '.chart-box:not(.osc)';
const sess = () => p.evaluate(s => document.querySelector(s).tsSession(), sel);
const loaded = () => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });

try {
  await p.goto(BASE + '#/market');
  await p.waitForSelector('main [role="combobox"]');
  // Twelve Data + חיפוש AAPL (ממלא את הבורסה — NASDAQ)
  eq(await p.evaluate(async () => { const me = await (await fetch('api.php?action=me')).json();
    await fetch('api.php?action=cred_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify({ provider: 'twelvedata', secret: 'good-Alice55667788' }) });
    return (await fetch('api.php?action=market_search&q=AAPL')).status; }), 200, 'alice connects Twelve Data and finds AAPL (NASDAQ)');

  // ── 1. AAPL ב-08:56 שעון ישראל: סגור, נפתח היום 16:30 ──
  await p.goto(BASE + '#/chart/' + encodeURIComponent('twelvedata:AAPL'));
  await loaded();
  // השעון עוצר בדיוק ב-08:57:00 — מכאן מקדמים בצעדים ידועים
  await p.clock.pauseAt(NOW * 1000 + 60000);
  await p.clock.runFor(10);
  let s = await sess();
  eq([s.kind, s.closed, s.text], ['us', true, '🔒 השוק סגור · נפתח בעוד 7:33:00 (היום 16:30)'], 'AAPL at 08:57 Israel time → "🔒 השוק סגור · נפתח בעוד 7:33:00 (היום 16:30)" at the top of the chart');
  const tag = p.locator('.quote-head .closed-tag');
  eq([await tag.isVisible(), await tag.getAttribute('role')], [true, 'status'], '… visible in the price line, as a status');
  await shot();
  // ── 2. כל שנייה ──
  await p.clock.runFor(1000);
  eq((await sess()).text, '🔒 השוק סגור · נפתח בעוד 7:32:59 (היום 16:30)', 'a second later → 7:32:59');
  await p.clock.runFor(60 * 1000);
  eq((await sess()).text, '🔒 השוק סגור · נפתח בעוד 7:31:59 (היום 16:30)', 'a minute later → 7:31:59');
  // ── 3. מגיעה הפתיחה: התווית יורדת והגרף נטען מיד ──
  const open = Date.UTC(2026, 10, 17, 14, 30);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(open / 1000 + 2) });
  const before = reqs.length;
  await p.clock.fastForward(open + 1000 - await p.evaluate(() => Date.now()));
  await p.clock.runFor(1500);
  await p.waitForFunction(s => !document.querySelector(s).tsSession().closed, sel, { timeout: 10000 });
  for (let i = 0; i < 50 && reqs.length === before; i++) await p.clock.runFor(200);
  eq([(await sess()).closed, await tag.isHidden(), reqs.length > before], [false, true, true], 'at 16:30 Israel time (09:30 New York) → the label goes away and the chart loads at once');

  // ── 4. קריפטו — תמיד פתוח, בלי תווית ──
  await p.goto(BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT'));
  await loaded();
  s = await sess();
  eq([s.kind, s.closed, await p.locator('.quote-head .closed-tag').isHidden()], [null, false, true], 'Bitcoin (Binance) → no session, no label');
  eq(errors, [], 'no errors');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
async function shot() { if (SHOTS) await p.screenshot({ path: join(SHOTS, 'market-closed-390.png') }); }
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
