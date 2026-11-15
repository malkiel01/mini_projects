// הרענון האוטומטי בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 18.3, S3, ✅23) — מחליפה את no-polling.mjs שנמחקה.
// מוכיחה שיש רענון כשהניתוח פעיל, ושאין כשהלשונית מוסתרת או כשאין שיטה; הקצב (60/30), 429 בשקט, ו"רענן" ידני.
// שעון מזויף (clock.install + pauseAt): הזמן זז רק ב-runFor, כך ש"דקה" נמדדת בלי לחכות דקה.
// node trading-strategies/tests/refresh-ui.mjs (Playwright; ספקים מדומים, בלי רשת). SHOTS=<תיקייה> — צילומי מסך.
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-refresh-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'alertable.php') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const SHOTS = process.env.SHOTS;
const NOW = 1794094200;   // חצי שעה אחרי שעה עגולה — כך דקות של רענון לא חוצות נר של שעה בלי שהבדיקה מבקשת
const MIN = 60_000;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();

async function open(as, { fake = false } = {}) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  if (fake) await ctx.clock.install({ time: NOW * 1000 });
  else await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error' && !/429/.test(m.text())) errors.push(m.text()); });
  const reqs = [];   // [action, methods] לכל בקשה ל-API
  p.on('request', r => { if (r.url().includes('api.php')) { const u = new URL(r.url()).searchParams; reqs.push([u.get('action'), u.get('methods')]); } });
  return { ctx, p, reqs };
}
const refreshes = reqs => reqs.filter(([a]) => a === 'chart_refresh');
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
// הלשונית מוסתרת / גלויה — כמו שהדפדפן מדווח (visibilityState) ועם האירוע
const setVisible = (p, on) => p.evaluate(v => {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (v ? 'visible' : 'hidden') });
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => !v });
  document.dispatchEvent(new Event('visibilitychange'));
}, on);
/**
 * מקדם את השעון, ומחכה שכל בקשה שיצאה בזמן הזה תחזור. הטיימר הבא נקבע רק אחרי שהתשובה חזרה — לכן כמה מחזורים
 * מקדמים צעד אחרי צעד (step), כמו שהזמן זז באמת.
 */
async function advance(p, ms, step = ms) {
  if (step > 0 && step < ms) {
    for (; ms > step; ms -= step) await advance(p, step);
  }
  let inflight = 0;
  const on = r => { if (r.url().includes('chart_refresh')) inflight++; };
  const off = r => { if (r.request().url().includes('chart_refresh')) inflight--; };
  const offFail = r => { if (r.url().includes('chart_refresh')) inflight--; };
  p.on('request', on); p.on('response', off); p.on('requestfailed', offFail);
  await p.clock.runFor(ms);
  for (let i = 0; i < 100 && inflight > 0; i++) await new Promise(r => setTimeout(r, 50));
  await new Promise(r => setTimeout(r, 150));   // התשובה מצוירת (microtasks אחרי ה-fetch)
  p.off('request', on); p.off('response', off); p.off('requestfailed', offFail);
}
const runs = async (o, id) => {
  const s = await o.evaluate(async () => (await fetch('api.php?action=admin_state')).json());
  const r = (s.method_runs || []).find(x => x.method_id === id);
  return r ? [r.runs, r.reused] : [0, 0];
};
const forecastState = p => p.evaluate(() => ({
  asOf: document.querySelector('.forecast-panel')?.dataset.asOf || null,
  pressed: document.querySelector('.forecast-scenarios button[aria-pressed="true"]')?.textContent || null,
}));

try {
  // ── המנהל מפעיל שיטת בדיקה (לא דוגמה — גם משתמש רגיל רואה אותה) ──
  const { p: o } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main');
  eq(await o.evaluate(async () => {
    const me = await (await fetch('api.php?action=me')).json();
    return (await fetch('api.php?action=admin_method_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf },
      body: JSON.stringify({ id: 'test-cross-a', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }) })).status;
  }), 200, 'the admin turns on a test method');

  const { ctx, p: a, reqs } = await open('alice', { fake: true });
  await a.goto(CHART);
  await loaded(a);
  await a.clock.pauseAt((NOW + 1) * 1000);   // מכאן הזמן זז רק כשהבדיקה מקדמת אותו
  await a.evaluate(() => { window.__noReload = 1; });
  eq([refreshes(reqs).length, reqs.some(([x]) => x === 'market_candles' || x === 'method_overlay')], [1, false], 'opening the chart = one chart_refresh (candles), no other data request');
  const every = a.locator('select[aria-label="קצב הרענון האוטומטי"]');
  eq(await every.isHidden(), true, 'no method → no refresh-rate control');

  // ── קריטריון 6 (חלק א): אין שיטה → אין רענון אוטומטי, ו"רענן" ידני עובד ──
  reqs.length = 0;
  await advance(a, 5 * MIN, MIN);
  eq(refreshes(reqs).length, 0, 'criterion 6: no method chosen → 5 minutes, 0 requests (auto refresh off)');
  await a.getByRole('button', { name: 'רענן' }).click();
  await loaded(a);
  await advance(a, 0);
  eq(refreshes(reqs), [['chart_refresh', '']], 'criterion 6: the manual "↻" works without a method — one request');

  // ── בחירת שיטה → בקשה אחת, והרענון מתחיל ──
  reqs.length = 0;
  await a.getByRole('button', { name: 'תחזית שיטה' }).click();
  await a.locator('dialog[open]').getByRole('button', { name: /בדיקה א/ }).click();
  const panel = a.locator('.forecast-panel');
  await panel.locator('.chips').waitFor({ timeout: 30000 });
  await advance(a, 0);
  eq(refreshes(reqs), [['chart_refresh', 'test-cross-a']], 'choosing a method → one request with it (candles + result)');
  eq([await every.isVisible(), await every.inputValue()], [true, '60'], 'the analysis is on → the rate control shows, 60 s by default');
  const st0 = await forecastState(a);

  // ── קריטריון 1: ניתוח פעיל, 60 שניות → בקשה אחת בדקה ──
  reqs.length = 0;
  await advance(a, MIN - 1000);
  eq(refreshes(reqs).length, 0, 'criterion 1: nothing before the minute is up');
  await advance(a, 1000);
  eq(refreshes(reqs), [['chart_refresh', 'test-cross-a']], 'criterion 1: after 60 s — exactly one request, carrying every chosen method');
  await advance(a, 2 * MIN, MIN);
  eq(refreshes(reqs).length, 3, 'criterion 1: 3 minutes → 3 requests (one per minute, not one per method)');
  eq(reqs.filter(([x]) => x !== 'chart_refresh'), [], 'criterion 1: no other request (no per-method call)');
  eq(/עודכן לפני \d+ ש׳/.test(await a.locator('.refresh-age').textContent()), true, '"עודכן לפני X" is shown');

  // ── קריטריון 3 (בדפדפן): אותו נר סגור → אותה תוצאה, והתרחיש שסומן נשאר ──
  eq(await forecastState(a), st0, 'criterion 3: refreshes without a new closed candle → same as_of, the selected scenario stays selected');
  const r0 = await runs(o, 'test-cross-a');
  await advance(a, MIN);
  const r1 = await runs(o, 'test-cross-a');
  eq([r1[0] === r0[0], r1[1] === r0[1] + 1], [true, true], `criterion 3: the run counter in the developer screen does not go up (runs ${r1[0]}, reused ${r0[1]}→${r1[1]})`);

  // ── קריטריון 2: לשונית מוסתרת 5 דקות → 0 בקשות; חזרה → בקשה אחת מיד ──
  reqs.length = 0;
  await setVisible(a, false);
  await advance(a, 5 * MIN, MIN);
  eq(refreshes(reqs).length, 0, 'criterion 2: hidden tab for 5 minutes → 0 requests');
  await setVisible(a, true);
  await advance(a, 0);
  eq(refreshes(reqs).length, 1, 'criterion 2: back to visible → one request right away');
  await advance(a, MIN - 1000);
  eq(refreshes(reqs).length, 1, '… and then the normal rate again (not two at once)');
  await advance(a, 1000);
  eq(refreshes(reqs).length, 2, '… the next one a minute later');

  // ── הקצב: 30 שניות (לא פחות מ-30, לא יותר מ-60) ──
  eq(await every.locator('option').evaluateAll(x => x.map(o => o.value)), ['60', '30'], 'the rate choices: 60 or 30 s only');
  await every.selectOption('30');
  reqs.length = 0;
  await advance(a, MIN, MIN / 2);
  eq(refreshes(reqs).length, 2, 'rate 30 s → 2 requests a minute');
  await every.selectOption('60');
  eq(await a.evaluate(() => localStorage.getItem('ts-refresh-every')), '60', 'the chosen rate is remembered on this device');

  // ── קריטריון 5: 429 → אין ניסיון חוזר לפני הרענון הבא, ואין הודעה קופצת ──
  await advance(a, MIN);   // מתחילים מטיימר נקי בקצב 60
  let hits = 0;
  await a.route(/action=chart_refresh/, route => { hits++; return route.fulfill({ status: 429, contentType: 'application/json',
    headers: { 'Retry-After': '60' }, body: JSON.stringify({ ok: false, error: 'יותר מ-30 בקשות נתונים בדקה. חכו דקה ונסו שוב.' }) }); });
  reqs.length = 0;
  await advance(a, MIN);
  eq(hits, 1, 'criterion 5: the refresh got 429');
  const toast = await a.locator('.toast').evaluate(t => (t.hidden ? '' : t.textContent));
  eq(toast, '', 'criterion 5: no popup message');
  eq(await a.locator('main .error').count(), 0, 'criterion 5: no error on the screen — the previous chart stays');
  eq(/עודכן לפני 1 דק׳/.test(await a.locator('.refresh-age').textContent()), true, 'criterion 5: "עודכן לפני X" shows how old the data is');
  await advance(a, MIN - 1000);
  eq(hits, 1, 'criterion 5: no retry before the next refresh');
  await a.unroute(/action=chart_refresh/);
  await advance(a, 1000);
  eq(refreshes(reqs).length, 2, 'criterion 5: the next refresh at the normal time — and it works');
  eq(/עודכן לפני 0 ש׳/.test(await a.locator('.refresh-age').textContent()), true, 'criterion 5: after a good refresh — "עודכן לפני 0 ש׳"');

  // ── קריטריון 4: נר חדש נסגר → הספירה מתעדכנת בגרף בלי טעינה מחדש ──
  const before = await forecastState(a);
  const NOW2 = NOW + 3600;
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW2) });
  await a.clock.setSystemTime(NOW2 * 1000);
  const r2 = await runs(o, 'test-cross-a');
  reqs.length = 0;
  await advance(a, MIN);
  const after = await forecastState(a);
  eq(refreshes(reqs).length, 1, 'criterion 4: one request');
  eq([after.asOf !== before.asOf, +after.asOf - +before.asOf], [true, 3600], 'criterion 4: the result moved to the new closed candle');
  eq((await runs(o, 'test-cross-a'))[0], r2[0] + 1, 'criterion 4: the method ran once for the new candle');
  eq(await a.evaluate(() => window.__noReload), 1, 'criterion 4: no page reload');

  // ── הסתרת התחזית → אין שיטה → הרענון נעצר ──
  await panel.getByRole('button', { name: 'הסתרה' }).click();
  reqs.length = 0;
  await advance(a, 3 * MIN, MIN);
  eq([refreshes(reqs).length, await every.isHidden()], [0, true], 'method hidden → no auto refresh, no rate control');
  await a.getByRole('button', { name: 'רענן' }).click();
  await loaded(a);
  await advance(a, 0);
  eq(refreshes(reqs), [['chart_refresh', '']], 'criterion 6: "↻" still works');

  // ── עוזבים את הגרף → אין טיימר שנשאר ──
  await a.getByRole('button', { name: 'תחזית שיטה' }).click();
  await a.locator('dialog[open]').getByRole('button', { name: /בדיקה א/ }).click();
  await panel.locator('.chips').waitFor({ timeout: 30000 });
  await shot(a, 'refresh-on');
  eq(await noHScroll(a), true, 'no horizontal scroll on a phone with the rate control and "עודכן לפני"');
  await a.evaluate(() => { location.hash = '#/market'; });
  await a.waitForFunction(() => !document.querySelector('.chart-box'));
  reqs.length = 0;
  await advance(a, 3 * MIN, MIN);
  eq(refreshes(reqs).length, 0, 'leaving the chart stops the refresh');

  // ── מסך המפתח: המונה גלוי ──
  await o.goto(BASE + '#/admin');
  await o.getByRole('tab', { name: /מאגר/ }).click();
  await o.locator('.method-runs').waitFor({ timeout: 15000 });
  eq(await o.locator('.method-runs tr[data-method="test-cross-a"] .runs').count(), 1, 'the developer screen lists the run counter per method');
  await shot(o, 'refresh-admin');
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
