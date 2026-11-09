// כרטיס הניתוח של elliott-w3 בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 17.1.9 ו, ז, ז0; PR 4א/3): "בדוק בעצמך",
// "שיטה שלנו על בסיס כללי הספר", "הגדרה שלנו" ליד הפרמטרים, ובניהול. node trading-strategies/tests/elliott-card-ui.mjs
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-ew-card-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
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
  const { p: o } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main');
  // ניהול: הפרמטרים מסומנים
  await o.goto(BASE + '#/admin');
  await o.getByRole('tab', { name: '🧪 שיטות' }).click();
  await o.waitForSelector('text=גלי אליוט — כניסה לגל 3');
  const adminText = await o.locator('main').textContent();
  eq([/יעד גל 3 \(× גל 1\) \(1–2\.618\) · לפי הספר/.test(adminText), /עומק גל 2 — מינימום \(0\.236–0\.618\) · הגדרה שלנו/.test(adminText)], [true, true], 'admin: each parameter marked "by the book" / "our definition" (ז0)');
  eq(await post(o, 'admin_method_save', { id: 'elliott-w3', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4'] }), 200, 'the admin turns elliott-w3 on');
  await post(o, 'watchlist_add', { asset: 'binance:BTCUSDT' });

  const { p: a } = await open('alice');
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main');
  await post(a, 'watchlist_add', { asset: 'binance:BTCUSDT' });
  await a.goto(BASE + '#/analysis/' + encodeURIComponent('binance:BTCUSDT'));
  await a.getByRole('button', { name: '🧭 נתח' }).waitFor();
  await a.locator('fieldset[aria-label="מסגרת זמן"] label', { hasText: 'שעה' }).first().click();
  await a.getByRole('button', { name: '🧭 נתח' }).click();
  const card = a.locator('.analysis-card').first();
  await card.waitFor({ timeout: 90000 });
  const text = await card.textContent();
  eq(/שיטה שלנו על בסיס כללי הספר \(Frost & Prechter\)/.test(text), true, 'card: "our method based on the book" (ז0)');
  eq([await card.locator('.manual-conds li').count(), /🔎 בדוק בעצמך/.test(text), /לא נבדק במחשב/.test(text)], [4, true, true], 'card: four "check yourself" items, apart from the computed conditions (ז)');
  eq(/undefined|null/.test(text), false, 'no "undefined"/"null" in the card');
  await card.locator('.param-notes summary').click();
  const notes = await card.locator('.param-notes li').allTextContents();
  eq([notes.length, notes.filter(x => /לפי הספר/.test(x)).length, notes.filter(x => /הגדרה שלנו/.test(x)).length], [8, 1, 7], 'card: 8 parameters — 1 by the book, 7 our definition');
  eq(await card.locator('.hint.warn', { hasText: 'חושב בלי בדיקה לאחור' }).count(), 0, 'with a stored backtest — no "computed without a backtest" note (ו1)');
  eq(await noHScroll(a), true, 'no horizontal scroll on a phone');
  await shot(a, 'elliott-card');
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
