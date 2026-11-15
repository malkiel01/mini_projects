// בדיקת ממשק S1 (spec-stage-a 18.1) — בחירת זוג בשם, בטלפון: "מה" ו"מול מה", כותרת הגרף, המעקב, הניתוח וההתראות —
// בלי סימול בטקסט הגלוי; זוג בלי ספק — הודעה בלי פנייה לספק; הסימול גלוי רק למנהל.
// node trading-strategies/tests/pairs-ui.mjs   (SHOTS=<dir> — צילומי מסך)
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-pairs-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
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
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  const reqs = [];
  p.on('request', r => { if (r.url().includes('api.php')) reqs.push(new URL(r.url()).searchParams.get('action')); });
  return { p, reqs };
}
// הטקסט הגלוי של המסך — כולל הכותרת ותיבות הבחירה, בלי ה-DOM הנסתר
const visible = p => p.evaluate(() => document.body.innerText + ' ' + [...document.querySelectorAll('select option')].map(o => o.textContent).join(' ') + ' ' + document.title);
const SYMBOL = /BTCUSDT|BTCUSDC|USDILS|ILSUSD|BTC\/USD/;
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });

try {
  const { p: a, reqs } = await open('alice');
  // ── "מה" ו"מול מה" ──
  await a.goto(BASE + '#/market');
  await a.locator('input[name="pair-base"]').first().waitFor();
  eq(await a.locator('input[name="pair-base"]:checked').evaluate(i => i.closest('label').textContent), 'ביטקוין', 'Bitcoin is the default "what" (18.1)');
  eq((await a.locator('main h2.sec-title').nth(1).textContent()).trim(), 'ביטקוין מול מה?', 'the second list: "Bitcoin vs what?"');
  const quotes = await a.locator('main .list').first().locator('.l1 > span:first-child').allTextContents();
  eq(quotes, ['ביטקוין מול דולר', 'ביטקוין מול טתר'], '"vs what" — only pairs with a provider (no key: Binance)');
  eq(SYMBOL.test(await visible(a)), false, 'criterion 1: no symbol in the visible text of the market screen');
  eq(await noHScroll(a), true, 'market screen: no horizontal scroll at 390px');
  await shot(a, 'pairs-market');

  // בחירת "מה" אחר — הרשימה השנייה מתעדכנת
  await a.locator('label', { hasText: 'דולר' }).filter({ has: a.locator('input[value="USD"]') }).click();
  eq((await a.locator('main h2.sec-title').nth(1).textContent()).trim(), 'דולר מול מה?', 'choosing "dollar" updates the second list');
  eq((await a.locator('main .list').first().locator('.l1 > span:first-child').allTextContents()).includes('דולר מול שקל'), true, 'dollar vs shekel is offered (ECB)');

  // ── קריטריון 3: זוג בלי ספק — הודעה, בלי פנייה לספק ──
  reqs.length = 0;
  await a.fill('input[name="q"]', 'ביטקוין מול ין');
  await a.click('form button[type="submit"]');
  await a.locator('main [role="status"]').waitFor();
  eq((await a.locator('main [role="status"]').textContent()).trim(), 'ביטקוין מול ין יפני: אין ספק לזוג הזה', 'criterion 3: a message, not an error');
  eq(reqs.filter(x => ['market_search', 'market_candles'].includes(x)), [], 'criterion 3: no request that reaches a provider');
  eq(await a.locator('main .error').count(), 0, 'criterion 3: not shown as an error');

  // ── חיפוש בעברית — "דולר מול שקל" פותח את הגרף, והכותרת בשם ──
  await a.fill('input[name="q"]', 'דולר מול שקל');
  await a.click('form button[type="submit"]');
  await a.waitForURL(/#\/chart\//);
  await loaded(a);
  eq(await a.locator('#title').textContent(), 'דולר מול שקל', 'chart header: the pair in words');
  eq(SYMBOL.test(await visible(a)), false, 'criterion 1: no symbol on the chart screen');

  // ── גרף ביטקוין מול טתר, ⭐ למעקב, ובחזרה לשוק ──
  await a.goto(BASE + '#/market');
  await a.locator('label').filter({ has: a.locator('input[value="BTC"]') }).click();   // "מה" נשמר — חוזרים לביטקוין
  await a.locator('main .list').first().locator('a.body', { hasText: 'ביטקוין מול טתר' }).click();
  await loaded(a);
  eq(await a.locator('#title').textContent(), 'ביטקוין מול טתר', 'chart header from the catalog');
  await a.getByRole('button', { name: 'הוספה למעקב' }).click();
  await a.goto(BASE + '#/market');
  await a.locator('main .list').last().locator('.card').first().waitFor();
  eq((await a.locator('main .list').last().locator('.l1').first().textContent()).trim(), 'ביטקוין מול טתר', 'the watchlist row: the pair in words');
  eq(SYMBOL.test(await visible(a)), false, 'criterion 1: no symbol in the watchlist');

  // ── ניתוח והתראות: תיבת הנכס בשם ──
  await a.goto(BASE + '#/analysis');
  await a.locator('select[aria-label="נכס"], main .empty').first().waitFor();
  if (await a.locator('select[aria-label="נכס"]').count()) {
    eq((await a.locator('select[aria-label="נכס"] option').first().textContent()).startsWith('ביטקוין מול טתר'), true, 'analysis: the asset picker in words');
  }
  eq(SYMBOL.test(await visible(a)), false, 'criterion 1: no symbol on the analysis screen');

  // ── קריטריון 4: הסימול גלוי למנהל, במסך המנהל ──
  const { p: o } = await open('owner');
  await o.goto(BASE + '#/admin');
  await o.evaluate(() => sessionStorage.setItem('ts-admin-tab', 'cache'));
  await o.goto(BASE + '#/admin');
  await o.reload();
  const cat = o.locator('details', { hasText: 'קטלוג הזוגות' });
  await cat.waitFor();
  await cat.locator('summary').click();
  eq(/binance:BTCUSDT/.test(await cat.textContent()), true, 'criterion 4: the admin sees the symbols in the pairs catalog');
  await shot(o, 'pairs-admin');

  eq(errors, [], 'no page errors');
} catch (e) {
  fail++; console.log('✗ crashed:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
