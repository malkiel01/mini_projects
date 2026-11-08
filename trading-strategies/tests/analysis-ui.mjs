// "ניתוח", פרופיל וניהול שיטות בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 1, 5, 6; פרק 15: טלפון ו-RTL, בלי polling).
// node trading-strategies/tests/analysis-ui.mjs   (דורש Playwright; ספקים מדומים, בלי רשת)
// SHOTS=<תיקייה> — שומר צילומי מסך (deviceScaleFactor 2) לבקרה.
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const port = 21500 + (process.pid % 400);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-analysis-ui-${process.pid}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  return p;
}
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  const r = await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) });
  return r.status;
}, [action, body]);

try {
  // ── מנהל: מפעיל את שיטת הדוגמה מהמסך ──
  const o = await open('owner');
  await o.goto(BASE + '#/admin');
  await o.getByRole('tab', { name: '🧪 שיטות' }).click();
  await o.waitForSelector('text=🧪 שיטת דוגמה — לא מהספר');
  eq(await o.locator('main .switch input').first().isChecked(), false, 'admin: the code method is listed OFF');
  eq(await noHScroll(o), true, 'admin methods: no horizontal scroll on a phone');
  await shot(o, 'admin-methods');
  await o.locator('main .switch input').first().check();
  await o.getByRole('button', { name: 'שמירה' }).first().click();
  await o.waitForSelector('text=נשמר');
  await post(o, 'watchlist_add', { asset: 'binance:BTCUSDT' });

  // ── ניתוח: בוחרים, לוחצים "נתח", רואים כרטיס ──
  await o.goto(BASE + '#/analysis/' + encodeURIComponent('binance:BTCUSDT'));
  await o.getByRole('button', { name: '🧭 נתח' }).waitFor();
  eq(await o.locator('nav.bottom-nav a[aria-current="page"]').textContent(), '🧭ניתוח', 'the "ניתוח" tab is current');
  await o.getByRole('button', { name: '🧭 נתח' }).click();
  await o.waitForSelector('.analysis-card', { timeout: 60000 });
  const card = await o.locator('.analysis-card').first().textContent();
  eq(card.includes('🧪 שיטת דוגמה — לא מהספר'), true, 'the card carries the sample label');
  eq(/ודאות\d+%|אין מספיק נתונים/.test(card.replace(/\s/g, '')), true, 'certainty with its source, or "not enough data"');
  eq(/אותות/.test(card) && /אופק/.test(card), true, 'N of signals and the horizon are shown next to the certainty');
  eq(/קנה|מכור|המלצ|כדאי/.test(await o.locator('main').textContent()), false, 'no "buy/sell/recommend" wording on the screen (6.6)');
  eq((await o.locator('main .disclaimer').textContent()).includes('אינו ייעוץ השקעות'), true, 'the fixed disclaimer is on the analysis screen');
  eq(await o.evaluate(() => [document.documentElement.dir, getComputedStyle(document.querySelector('.analysis-card')).direction]), ['rtl', 'rtl'], 'RTL');
  eq(await o.evaluate(() => [...document.querySelectorAll('.analysis-card .num')].every(e => getComputedStyle(e).direction === 'ltr')), true, 'numbers are LTR inside the RTL card');
  eq(await noHScroll(o), true, 'analysis: no horizontal scroll on a phone (390px)');
  await shot(o, 'analysis');

  // בלי polling: 20 שניות בלי פעולה — אף בקשה לשרת
  const seen = [];
  const onReq = r => { if (r.url().includes('/api.php')) seen.push(r.url()); };
  o.on('request', onReq);
  await o.waitForTimeout(20000);
  o.off('request', onReq);
  eq(seen.length, 0, 'analysis: no requests to our server in 20 s idle');

  // הגרף מוביל לניתוח
  await o.goto(BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT'));
  await o.locator('a[aria-label="ניתוח לפי שיטות"]').click();
  await o.waitForURL(/#\/analysis\//);
  eq(decodeURIComponent(new URL(o.url()).hash), '#/analysis/binance:BTCUSDT', '🧭 on the chart opens the analysis of that asset');

  // ── משתמש רגיל: שיטת הדוגמה לא קיימת בשבילו ──
  const a = await open('alice');
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main form');
  await post(a, 'watchlist_add', { asset: 'binance:BTCUSDT' });
  await a.goto(BASE + '#/analysis');
  await a.waitForSelector('main .empty');
  eq((await a.locator('main').textContent()).includes('עוד אין שיטות פעילות'), true, 'a regular user does not see the sample method even when it is on');

  // ── פרופיל ──
  await a.goto(BASE + '#/profile');
  await a.waitForSelector('main form');
  eq(await noHScroll(a), true, 'profile: no horizontal scroll on a phone');
  await a.locator('fieldset[aria-label="טווח השקעה"] label', { hasText: 'ארוך' }).click();
  await a.locator('fieldset[aria-label="רמת סיכון"] label', { hasText: 'נמוכה' }).click();
  eq(await a.locator('input[name="risk_pct"]').inputValue(), '0.5', 'risk level fills its default risk (0.5%)');
  await a.getByRole('button', { name: 'שמירה' }).click();
  await a.waitForSelector('text=הפרופיל נשמר');
  const prof = await a.evaluate(async () => (await (await fetch('api.php?action=profile')).json()).profile);
  eq([prof.horizon, prof.risk_level, prof.risk_pct, prof.min_rr], ['long', 'low', '0.5', '3'], 'profile saved from the screen');
  await shot(a, 'profile');
} catch (e) {
  fail++; console.log('✗', e.message);
} finally {
  await b.close();
  srv.kill();
}
eq(errors, [], 'no page errors');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
