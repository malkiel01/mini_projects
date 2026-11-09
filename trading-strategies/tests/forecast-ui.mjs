// "🔮 תחזית שיטה" בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 17.2.2, ✅15, ✅13; PR 4ב/2): הפאנל, הניסוח, הציור על
// הקנבס (לא HTML), "↻" בלבד, ובלי polling. node trading-strategies/tests/forecast-ui.mjs (Playwright; ספקים מדומים, בלי רשת)
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-forecast-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
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
  // ── המנהל מפעיל את שיטת הדוגמה ──
  const { p: o, reqs } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main');
  eq(await post(o, 'admin_method_save', { id: 'sample-ma-cross', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the admin turns on the sample method');
  await o.goto(CHART);
  await loaded(o);
  const before = await canvasPrint(o);
  const btn = o.getByRole('button', { name: 'תחזית שיטה' });
  eq(await btn.getAttribute('aria-pressed'), 'false', 'the 🔮 button in the chart header, off');

  // ── בחירת שיטה ──
  await btn.click();
  const dlg = o.locator('dialog[open]');
  await dlg.waitFor();
  eq((await dlg.locator('h2').textContent()).trim(), '🔮 תחזית שיטה', 'the method picker sheet');
  eq(/לא ייעוץ, בלי אחוז ודאות/.test(await dlg.textContent()), true, 'the sheet says: not advice, no certainty percentage');
  reqs.length = 0;
  await dlg.getByRole('button', { name: /חציית ממוצעים 20\/50/ }).click();
  const panel = o.locator('.forecast-panel');
  await panel.locator('.chips').waitFor({ timeout: 30000 });
  eq(reqs.filter(a => a === 'method_overlay').length, 1, 'one method_overlay request — only after the user chose a method');
  eq((await panel.locator('h3').textContent()).trim(), '🔮 תרחיש לפי שיטת חציית ממוצעים 20/50 — לא ייעוץ', 'the title "תרחיש לפי שיטת X — לא ייעוץ" (✅15)');
  const chips = await panel.locator('.chip').allTextContents();
  eq([chips.includes('לפי כללי השיטה, לא נבדק לאחור'), chips.includes('לא נבדקה לאחור'), chips.includes('🧪 שיטת דוגמה — לא מהספר')], [true, true, true], 'marked "by the method rules, not backtested", "not backtested" and the sample label');
  eq(/אינו ייעוץ השקעות/.test(await panel.locator('.disclaimer').textContent()), true, 'the fixed disclaimer');
  eq(/\d\s*%/.test(await panel.textContent()), false, 'no percentage anywhere in the forecast panel (no certainty)');
  eq(await btn.getAttribute('aria-pressed'), 'true', 'the 🔮 button shows the mode is on');
  const sc = panel.locator('.forecast-scenarios button');
  const nsc = await sc.count();
  eq(nsc >= 1 && await sc.first().getAttribute('aria-pressed') === 'true', true, `scenarios listed (${nsc}), the leading one pressed`);
  await o.waitForTimeout(400);
  const after = await canvasPrint(o);
  eq(after !== before, true, 'the scenario is drawn on the chart canvas');
  const body = await o.evaluate(() => document.body.innerText);
  eq([/סטופ לפי כללי השיטה/.test(body), /רמת היציאה ברווח/.test(body)], [false, false], 'the method texts on the chart are canvas, not HTML');
  eq(await noHScroll(o), true, 'no horizontal scroll on a phone');
  eq(await o.evaluate(() => document.documentElement.dir), 'rtl', 'RTL');
  await shot(o, 'forecast-on');

  // ── בלי polling (✅13): 20 שניות בלי פעולה — אף בקשה ──
  reqs.length = 0;
  await o.waitForTimeout(20000);
  eq(reqs, [], 'no request to the server for 20 s without a user action');

  // ── שינוי טווח: התרחיש מוסתר, ומבקשים "↻" — בלי בקשת תחזית ──
  reqs.length = 0;
  // שבוע (לא יום): השעון של הדפדפן אמיתי, והשרת ב"עכשיו" קבוע — טווח של יום היה מתחיל אחרי ה"עכשיו" של השרת
  await o.locator('select[aria-label="טווח"]').selectOption('w');
  await loaded(o);
  await panel.locator('.hint.warn').waitFor();
  eq(reqs.includes('method_overlay'), false, 'a range change does not re-run the forecast by itself');
  eq(/"↻" מרענן גם את התרחיש/.test(await panel.textContent()), true, 'it asks for "↻"');
  reqs.length = 0;
  await o.getByRole('button', { name: 'רענן' }).click();
  await panel.locator('.chips').waitFor();
  await o.waitForFunction(() => !document.querySelector('.forecast-panel .hint.warn'));
  eq(reqs.filter(a => a === 'method_overlay').length, 1, '"↻" refreshes the candles and the forecast (one request)');

  // ── הסתרה ──
  await panel.getByRole('button', { name: 'הסתרה' }).click();
  eq([await panel.isHidden(), await btn.getAttribute('aria-pressed')], [true, 'false'], 'hide → panel gone, button off');
  await o.waitForTimeout(300);
  eq(await canvasPrint(o) !== after, true, 'hide → the drawing is removed from the canvas');

  // ── משתמש רגיל: שיטת הדוגמה לא מוצעת לו ──
  const { p: a } = await open('alice');
  await a.goto(CHART);
  await loaded(a);
  await a.getByRole('button', { name: 'תחזית שיטה' }).click();
  await a.locator('dialog[open]').waitFor();
  eq(/אין שיטות זמינות/.test(await a.locator('dialog[open]').textContent()), true, 'a regular user is not offered the sample method');
  await shot(a, 'forecast-none');
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
