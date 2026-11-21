// היסטוריה בפעימות בגרף (בקשת הבעלים: "למה רואים רק חודש אחורה? תוסיף עד 5 שנים, אבל פעימות מידע קטנות, שלא נחסם").
// node trading-strategies/tests/history-ui.mjs   (Playwright, טלפון 390×844; ספקים מדומים, בלי רשת)
//   - פתיחה — כמו קודם, בלי בקשת היסטוריה.
//   - גרירה שמאלה עד הנר הראשון → מנה של נרות ישנים; מה שעל המסך לא זז; גרירה נוספת → עוד מנה.
//   - אף פעם לא שתי בקשות במקביל (גם לא עם chart_refresh), ולפחות ~3 ש' בין מנות.
//   - 429 / תקרה → "ממשיכים בעוד רגע", וניסיון שוב אחרי ~20 ש'.
//   - בחירת שיטה (טעינה מחדש של החלון) — ההיסטוריה שנטענה נשארת.
//   - "5 שנים" ב-4 שעות (10,950 נרות) — לא נחתך ל-5,000: הפעימות ממשיכות לבד עד תחילת הטווח.
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
import { writeFileSync } from 'fs';
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-hist-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const NOW = +(process.env.TS_NOW || 1794094200);
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
p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
const cdp = await ctx.newCDPSession(p);
const sel = '.chart-box:not(.osc)';
const hs = () => p.evaluate(s => document.querySelector(s).tsHistory(), sel);
// בקשות: מתי התחילה ומתי הסתיימה כל אחת — לבדיקת "לא במקביל" ו"הפסקה בין מנות"
const log = [];
p.on('request', r => { const a = new URL(r.url()).searchParams.get('action'); if (a === 'market_history' || a === 'chart_refresh') log.push({ a, r, start: Date.now(), end: null }); });
const done = r => { const x = log.find(e => e.r === r); if (x) x.end = Date.now(); };
p.on('requestfinished', done);
p.on('requestfailed', done);
const hist = () => log.filter(e => e.a === 'market_history');
let fake429 = 0;   // כמה תשובות "retry" לזייף
await p.route(/api\.php\?action=market_history/, async route => {
  if (fake429 > 0) { fake429--; return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, candles: [], done: false, retry: true, notice: 'Binance: הספק הגביל את קצב הבקשות.' }) }); }
  return route.continue();
});
async function swipeRight() {   // הגרף ימינה — אל העבר
  const r = await p.evaluate(s => { const b = document.querySelector(s).getBoundingClientRect(); return [b.x, b.y, b.width, b.height]; }, sel);
  const y = r[1] + r[3] * 0.5, x0 = r[0] + r[2] * 0.15, x1 = r[0] + r[2] * 0.85;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x0, y }] });
  for (let i = 1; i <= 12; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x0 + (x1 - x0) * i / 12, y }] }); await p.waitForTimeout(16); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
}
const loaded = () => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });

try {
  await p.goto(CHART);
  await loaded();
  const h0 = await hs();
  await p.waitForTimeout(2500);
  eq([h0.n, hist().length], [2160, 0], 'opening the chart (hour, 3 months): 2,160 candles as before, no history request');

  // ── גרירה אל הנר הראשון → מנה; מה שעל המסך לא זז ──
  await swipeRight();
  await p.waitForFunction(s => document.querySelector(s).tsHistory().n > 2160, sel, { timeout: 15000 });
  const h1 = await hs();
  eq([h1.n, h1.older, h1.first < h0.first], [2160 + 999, 999, true], 'dragging to the first candle loads one batch of 999 older candles');
  const j = h1.jump;
  eq(!!j && Math.abs(j.after[0] - (j.before[0] + j.added)) < 0.01 && Math.abs(j.after[1] - (j.before[1] + j.added)) < 0.01, true,
    `the candles on screen do not move when the batch comes in — the same bars, shifted by exactly the ${j?.added} added (${JSON.stringify(j)})`);

  // ── עוד גרירות → עוד מנות, אחת בכל פעם, עם הפסקה ──
  for (let i = 0; i < 6 && (await hs()).n < 2160 + 999 * 3; i++) { await swipeRight(); await p.waitForTimeout(1200); }
  await p.waitForFunction(s => document.querySelector(s).tsHistory().n >= 2160 + 999 * 3, sel, { timeout: 30000 });
  const H = hist().filter(e => e.end);
  let overlap = 0;
  for (let i = 0; i < log.length; i++) for (let j = i + 1; j < log.length; j++) {
    const a = log[i], b = log[j];
    if ((a.a === 'market_history' || b.a === 'market_history') && a.start < (b.end ?? Infinity) && b.start < (a.end ?? Infinity)) overlap++;
  }
  eq(overlap, 0, 'never two requests at once (history with history, or with chart_refresh)');
  const gaps = H.slice(1).map((e, i) => e.start - H[i].end);
  eq(H.length >= 3 && gaps.every(g => g >= 2800), true, `at least ~3 s between batches (${gaps.join(', ')} ms)`);
  const all = await p.evaluate(s => { const b = document.querySelector(s); return b.tsHistory(); }, sel);
  eq(all.n, 2160 + 999 * H.length, 'every batch joined the chart, nothing lost or doubled');

  // ── 429 → "ממשיכים בעוד רגע", ושוב אחרי ~20 ש' ──
  fake429 = 1;
  const before429 = hist().length;
  await swipeRight();
  await p.waitForFunction(() => /ממשיכים בעוד רגע/.test(document.querySelector('.hist-info')?.textContent || ''), null, { timeout: 15000 });
  eq(await p.locator('.hist-info').isVisible(), true, 'a 429 / cap → "ממשיכים בעוד רגע" under the chart');
  const t429 = hist()[before429].end;
  await p.waitForFunction(([s, n]) => document.querySelector(s).tsHistory().n > n, [sel, all.n], { timeout: 40000 });
  const retry = hist()[before429 + 1];
  eq(retry.start - t429 >= 19000, true, `retried by itself after ~20 s (${retry.start - t429} ms)`);
  await p.waitForFunction(() => document.querySelector('.hist-info').hidden, null, { timeout: 5000 });
  eq(await p.locator('.hist-info').isHidden(), true, 'and the note goes away');

  // ── בחירת שיטה (החלון של chart_refresh נטען מחדש) — ההיסטוריה נשארת ──
  const hk = await hs();
  await p.getByRole('button', { name: 'תחזית שיטה' }).first().click();
  await p.locator('.fc-pop .fc-opt', { hasText: 'אליוט' }).locator('input').check();
  await p.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
  await p.keyboard.press('Escape');
  await p.waitForTimeout(500);
  const hm = await hs();
  eq([hm.n >= hk.n, hm.first], [true, hk.first], 'picking a method reloads the window but keeps the history already loaded');

  // ── "5 שנים" ב-4 שעות: לא נחתך — ממשיכים לבד עד תחילת הטווח ──
  await p.locator('input[name="tf"][value="h4"]').check({ force: true });
  await loaded();
  await p.selectOption('select[aria-label="טווח"]', 'y5');
  await p.waitForFunction(s => document.querySelector(s).tsHistory().target !== null, sel, { timeout: 15000 });
  const y0 = await hs();
  eq([y0.n, /נטענים בהדרגה/.test(await p.locator('main p.hint[aria-live]').first().textContent())], [5000, true], '5 years at 4 hours: starts with 5,000 and says the older candles are loading');
  await p.waitForFunction(s => { const h = document.querySelector(s).tsHistory(); return h.target === null || h.done; }, sel, { timeout: 90000 });
  const y1 = await hs();
  const span = (NOW - y1.first) / 86400;
  eq([y1.n > 10000, span > 365 * 4.9], [true, true], `without touching the chart, it keeps loading to the start of the range (${y1.n} candles, ${Math.round(span)} days)`);
  const nReq = hist().length;
  await p.waitForTimeout(4000);
  eq(hist().length, nReq, 'and stops there — no more requests');
} catch (e) {
  fail++;
  console.log('✗', e.stack);
} finally {
  await b.close();
  srv.kill();
}
const errs = errors.filter(e => !/favicon|sw\.js/.test(e));
eq(errs, [], 'no page errors');
console.log(fail ? `✗ ${fail}/${n} נכשלו` : `✓ ${n} בדיקות עברו`);
process.exit(fail ? 1 : 0);
