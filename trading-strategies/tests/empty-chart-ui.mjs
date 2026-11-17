// "זה שוב פעם ריק" (הבעלים, 2026-11-17): גרף בלי נרות — לעולם לא ריק בלי הסבר. בתוך הגרף: הסיבה מהשרת, "↻ נסו שוב" ו"מסגרת יומית".
// node trading-strategies/tests/empty-chart-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-empty-chart-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(scheme = 'light', width = 390, init = null) {
  const ctx = await b.newContext({ viewport: { width, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: 'alice', url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  if (init) await ctx.addInitScript(init);
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  return p;
}
const sel = '.chart-box:not(.osc)';
const why = p => p.evaluate(s => document.querySelector(s).tsEmpty(), sel);
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png') }); };
// מה שהשרת יחזיר ל-chart_refresh: null — אמיתי; {status, body}
let fake = null;
async function route(p) {
  await p.route(/api\.php\?action=chart_refresh/, async r => {
    if (!fake) return r.continue();
    if (fake.hang) return new Promise(() => {});          // השרת לא עונה — הבקשה תלויה לנצח
    if (fake.abort) { fake = null; return r.abort('failed'); }   // הרשת נחסמה (הטלפון ברקע) — פעם אחת
    if (fake.empty) {   // תשובה אמיתית, בלי נרות
      const res = await r.fetch(); const j = await res.json();
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...j, candles: [], notice: fake.notice ?? null }) });
    }
    return r.fulfill({ status: fake.status, contentType: 'application/json', body: JSON.stringify(fake.body) });
  });
}

try {
  const p = await open();
  await route(p);
  await p.goto(BASE + '#/market');
  await p.waitForSelector('main [role="combobox"]');

  // ── 1. שגיאה מהספק (502), ואין שום נר — ההודעה בתוך הגרף ──
  fake = { status: 502, body: { ok: false, error: 'Twelve Data: הסימבול לא זמין בתוכנית של המפתח — /time_series: **symbol** XAG/USD is available exclusively with Grow plans.' } };
  await p.goto(CHART);
  await p.waitForFunction(s => (document.querySelector(s)?.tsEmpty?.() ?? null) !== null, sel, { timeout: 20000 });
  const w1 = await why(p);
  eq(w1.startsWith('Twelve Data: הסימבול לא זמין בתוכנית'), true, `a provider error and no candles → the reason inside the chart ("${w1.slice(0, 50)}…")`);
  const ov = p.locator('.chart-empty');
  eq([await ov.isVisible(), await ov.getAttribute('role'), (await ov.locator('strong').textContent()).trim()], [true, 'status', 'אין נרות להצגה'], '… a visible status box: "אין נרות להצגה"');
  eq([await ov.getByRole('button', { name: '↻ נסו שוב' }).isVisible(), await ov.getByRole('button', { name: 'מסגרת יומית' }).isVisible()], [true, true], '… with "↻ נסו שוב" and "מסגרת יומית"');
  const inChart = await p.evaluate(s => { const a = document.querySelector('.chart-empty').getBoundingClientRect(), c = document.querySelector(s).getBoundingClientRect();
    return a.top >= c.top && a.bottom <= c.bottom && a.left >= c.left - 1 && a.right <= c.right + 1; }, sel);
  eq([inChart, await noHScroll(p)], [true, true], '… inside the chart\'s frame, no horizontal scroll at 390');
  await shot(p, 'empty-error-390');

  // ── 2. ↻ — הספק חזר: הנרות, וההודעה יורדת ──
  fake = null;
  await ov.getByRole('button', { name: '↻ נסו שוב' }).click();
  await p.waitForFunction(s => document.querySelector(s).tsEmpty() === null, sel, { timeout: 20000 });
  eq([await why(p), await ov.isHidden(), (await p.evaluate(s => document.querySelector(s).tsHistory().n, sel)) > 0], [null, true, true], '"↻ נסו שוב" → candles, and the box goes away');

  // ── 3. 200 בלי נרות (שוק סגור / טווח ריק): "אין נתונים בטווח הזה", גם בלי notice ──
  fake = { empty: true };
  await p.locator('select[name="range"]').selectOption('d');
  await p.waitForFunction(s => document.querySelector(s).tsEmpty() !== null, sel, { timeout: 20000 });
  eq((await why(p)).startsWith('אין נתונים בטווח הזה'), true, '200 with no candles → "אין נתונים בטווח הזה — ייתכן שהשוק היה סגור…"');
  fake = { empty: true, notice: 'Twelve Data: הגעתם לתקרת הבקשות של הספק. מוצג מה שבמאגר.' };
  await p.getByRole('button', { name: 'רענן' }).click();
  await p.waitForFunction(s => /תקרת/.test(document.querySelector(s).tsEmpty() || ''), sel, { timeout: 20000 });
  eq((await why(p)).startsWith('Twelve Data: הגעתם לתקרת'), true, '… and with a notice from the server — the notice is the reason');

  // ── 4. "מסגרת יומית" — עוברים ליום; ההודעה נשארת עד שיש נרות ──
  fake = null;
  await ov.getByRole('button', { name: 'מסגרת יומית' }).click();
  await p.waitForFunction(s => document.querySelector(s).tsEmpty() === null, sel, { timeout: 20000 });
  eq([await p.locator('fieldset.seg input[value="d1"]').isChecked(), await why(p)], [true, null], '"מסגרת יומית" → the day timeframe, candles, no box');

  // ── 5. רענון אוטומטי שנכשל כשיש נרות — אין קופסה (הנתונים הקודמים נשארים) ──
  fake = { status: 502, body: { ok: false, error: 'Twelve Data: תקלה בפנייה לספק' } };
  await p.getByRole('button', { name: 'רענן' }).click();
  await p.waitForTimeout(1500);
  eq(await why(p), null, 'a failed refresh while candles are on the chart → no box (the candles stay, the error is in the line under the chart)');

  // ── 7. (בקרה) בקשה שלא חוזרת — לא "טוען…" לנצח: אחרי ה-timeout, הסיבה בתוך הגרף ו-↻ עובד. לפני: נתקע ──
  const tq = await open('light', 390, () => { window.TS_API_TIMEOUT_MS = 1500; });
  await route(tq);
  await tq.goto(BASE + '#/market');
  await tq.waitForSelector('main [role="combobox"]');
  fake = { hang: true };
  await tq.goto(CHART);
  await tq.waitForFunction(s => (document.querySelector(s)?.tsEmpty?.() ?? null) !== null, sel, { timeout: 15000 });
  eq([await why(tq), /טוען/.test(await tq.locator('main p.hint[aria-live]').first().textContent())], ['השרת לא ענה בזמן — נסו שוב (↻)', false],
    'a request that never returns → after the timeout: "השרת לא ענה בזמן — נסו שוב (↻)" inside the chart, not "טוען…" forever');
  fake = null;
  await tq.locator('.chart-empty').getByRole('button', { name: '↻ נסו שוב' }).click();
  await tq.waitForFunction(s => document.querySelector(s).tsEmpty() === null, sel, { timeout: 20000 });
  eq((await tq.evaluate(s => document.querySelector(s).tsHistory().n, sel)) > 0, true, '… and "↻ נסו שוב" loads the candles (the page did not fall asleep)');

  // ── 8. (בקרה) הטעינה הראשונה נכשלה בזמן שהלשונית ברקע ("מושהה"), בלי שיטה פעילה — כשחוזרים, הגרף נטען לבד. לפני: נשאר ריק ──
  const vq = await open('light', 390, () => {
    window.__vis = 'visible';
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => window.__vis });
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => window.__vis !== 'visible' });
  });
  await route(vq);
  await vq.goto(BASE + '#/market');
  await vq.waitForSelector('main [role="combobox"]');
  fake = { abort: true };
  await vq.goto(CHART);
  await vq.evaluate(() => { window.__vis = 'hidden'; document.dispatchEvent(new Event('visibilitychange')); });
  await vq.waitForFunction(s => (document.querySelector(s)?.tsEmpty?.() ?? null) !== null, sel, { timeout: 15000 });
  eq(await why(vq), 'אין חיבור לשרת', 'the first load failed while in the background ("מושהה") → "אין חיבור לשרת" inside the chart');
  await vq.evaluate(() => { window.__vis = 'visible'; document.dispatchEvent(new Event('visibilitychange')); });
  await vq.waitForFunction(s => document.querySelector(s).tsEmpty() === null, sel, { timeout: 20000 });
  eq((await vq.evaluate(s => document.querySelector(s).tsHistory().n, sel)) > 0, true, '… back to the tab → it loads by itself, with no method selected (not left empty until ↻)');

  // ── 6. כהה, 320 ──
  const d = await open('dark', 320);
  await route(d);
  fake = { status: 502, body: { ok: false, error: 'Twelve Data: המפתח נדחה' } };
  await d.goto(BASE + '#/market');
  await d.waitForSelector('main [role="combobox"]');
  await d.goto(CHART);
  await d.waitForFunction(s => (document.querySelector(s)?.tsEmpty?.() ?? null) !== null, sel, { timeout: 20000 });
  const c = await d.evaluate(() => { const e = document.querySelector('.chart-empty'); const s = getComputedStyle(e); return [s.backgroundColor, getComputedStyle(e.querySelector('strong')).color]; });
  eq([c[0] !== c[1], await noHScroll(d)], [true, true], `320, dark: the box is readable (${c.join(' / ')}), no horizontal scroll`);
  await shot(d, 'empty-error-320-dark');
  // ── 9. (בקרה) 🗄️ מאגר ← "יומן בקשות נרות": עמודת "למה" — בקשה שנכשלה, עם הסיבה, בלי המפתח ──
  const o = await open('light', 390);
  await o.context().addCookies([{ name: 'dev_as', value: 'owner', url: BASE }]);
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  eq(await o.evaluate(async () => { const me = await (await fetch('api.php?action=me')).json();
    await fetch('api.php?action=cred_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify({ provider: 'twelvedata', secret: 'good-Owner99887766' }) });
    return (await fetch('api.php?action=market_candles&asset=' + encodeURIComponent('twelvedata:PLANX/USD') + '&tf=h1')).status; }), 502, 'the owner asks for a symbol outside the plan → 502');
  await o.evaluate(() => sessionStorage.setItem('ts-admin-tab', 'cache'));
  await o.goto(BASE + '#/admin');
  const tbl = o.locator('section.block', { hasText: 'יומן בקשות נרות' });
  await tbl.locator('table').waitFor({ timeout: 15000 });
  const row = tbl.locator('tbody tr', { hasText: 'PLANX/USD' }).first();
  const next = row.locator('xpath=following-sibling::tr[1]');
  const reason = (await next.locator('td.fl-reason').textContent()).trim();
  const fits = await next.locator('td.fl-reason > div').evaluate(td => { const r = td.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth + 1; });
  eq([reason.startsWith('למה: שגיאת ספק: Twelve Data: הסימבול לא זמין בתוכנית'), reason.includes('good-Owner'), await row.evaluate(r => r.classList.contains('fl-why')), fits],
    [true, false, true, true], `the failed request is marked, and right under it — "למה: …" across the row, on screen at 390 ("${reason.slice(0, 60)}…")`);
  eq(await tbl.locator('tbody tr', { hasText: 'BTCUSDT' }).first().evaluate(r => r.classList.contains('fl-why')), false, '… a normal request — no reason row');
  await shot(o, 'fetch-log-reason-390');

  eq(errors, [], 'no errors in the page');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
