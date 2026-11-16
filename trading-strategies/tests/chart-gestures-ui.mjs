// תנועות מגע על הגרף בטלפון (מטלה דחופה מהבעלים, 2026-11-16) — בדפדפן אמיתי, מגע אמיתי (CDP Input.dispatchTouchEvent).
// node trading-strategies/tests/chart-gestures-ui.mjs   (Playwright, טלפון 390×844; ספקים מדומים, בלי רשת)
//   - אצבע אחת במאונך על הגרף → הדף נגלל, טווח הזמן לא זז.
//   - אצבע אחת לאורך הגרף → טווח הזמן זז (כמו קודם).
//   - צביטה אופקית → פחות נרות על המסך (טווח הזמן קטן).
//   - צביטה אנכית → הנרות, השכבות (אליוט) והנפח נמוכים יותר; טווח הזמן לא זז. מתיחה — חזרה לגובה הרגיל, לא מעבר.
//   - (בקרה 9, הבעלים: "2 אצבעות רק לגובה יגללו למעלה ולמטה") שתי אצבעות שזזות יחד לגובה → ציר המחיר זז, בלי לשנות
//     גובה וטווח זמן; "↺" מחזיר. שתי אצבעות יחד לרוחב — לא עושות כלום.
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-gest-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
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
const range = () => p.evaluate(s => { const r = document.querySelector(s).tsRange(); return [Math.round(r.from * 10) / 10, Math.round(r.to * 10) / 10]; }, sel);
const heights = () => p.evaluate(s => document.querySelector(s).tsHeights(), sel);
// כמה הדף נגלל: החלון וכל מי שמעל הגרף
const scrolled = () => p.evaluate(s => { let t = window.scrollY, el = document.querySelector(s).parentElement; while (el) { t += el.scrollTop; el = el.parentElement; } return Math.round(t); }, sel);
const rect = () => p.evaluate(s => { const r = document.querySelector(s).getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; }, sel);
const settle = (ms = 1500) => p.waitForTimeout(ms);   // גם הגלילה הקינטית
/** fingers: [[x0,y0,x1,y1], ...] — כל אצבע מנקודה לנקודה, יחד */
async function gesture(fingers, steps = 14) {
  const at = i => fingers.map(([x0, y0, x1, y1], id) => ({ x: x0 + (x1 - x0) * i / steps, y: y0 + (y1 - y0) * i / steps, id }));
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: at(0) });
  for (let i = 1; i <= steps; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: at(i) }); await p.waitForTimeout(16); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await settle();
}
const span = r => r[1] - r[0];

try {
  await p.goto(CHART);
  await p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
  // אליוט על הגרף — "השכבות" שמתכווצות עם הנרות
  await p.getByRole('button', { name: 'תחזית שיטה' }).first().click();
  await p.locator('.fc-pop .fc-opt', { hasText: 'אליוט' }).locator('input').check();
  await p.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
  await p.keyboard.press('Escape');
  await settle(500);

  // ── אצבע אחת במאונך → הדף נגלל, הגרף לא ──
  let R = await rect();
  const s0 = await scrolled(), r0 = await range();
  const cx = R.x + R.w / 2;
  await gesture([[cx, R.y + R.h * 0.8, cx, R.y + R.h * 0.2]]);
  const s1 = await scrolled(), r1 = await range();
  eq(s1 > s0 + 50, true, `one finger up on the chart scrolls the page (${s0} → ${s1})`);
  eq(r1, r0, 'one finger up on the chart does not move the time axis');
  R = await rect();   // הגרף זז למעלה עם הדף
  const top = Math.max(R.y, 0) + 20, bottom = Math.min(R.y + R.h, 844) - 20;
  await gesture([[cx, top, cx, bottom]]);
  eq((await scrolled()) < s1, true, 'one finger down scrolls the page back');
  await p.evaluate(() => { window.scrollTo(0, 0); for (let el = document.querySelector('.chart-box').parentElement; el; el = el.parentElement) el.scrollTop = 0; });
  await settle(300);

  // ── אצבע אחת לאורך הגרף → טווח הזמן זז ──
  R = await rect();
  const midY = R.y + R.h * 0.5;
  const ra = await range();
  await gesture([[R.x + R.w * 0.3, midY, R.x + R.w * 0.8, midY]]);
  const rb = await range();
  eq(rb[0] < ra[0] - 5, true, `one finger along the chart moves the time axis (${ra} → ${rb})`);
  eq(Math.abs(span(rb) - span(ra)) < 1, true, 'and keeps the number of candles');

  // ── שתי אצבעות יחד לרוחב → כלום (רק לגובה) ──
  const rTwo0 = await range();
  await gesture([[R.x + R.w * 0.65, midY - 40, R.x + R.w * 0.25, midY - 40], [R.x + R.w * 0.65, midY + 40, R.x + R.w * 0.25, midY + 40]]);
  eq([await range(), (await heights()).k, await p.locator('.chart-reset').isHidden()], [rTwo0, 1, true], 'two fingers together sideways do nothing (only up/down)');

  // ── שתי אצבעות יחד לגובה → ציר המחיר זז (הנרות יורדים), הגובה לא משתנה; "↺" מחזיר ──
  const priceAt = () => p.evaluate(s => { const b = document.querySelector(s); return b.tsPriceAt(b.offsetHeight / 2); }, sel);
  const hv0 = await heights(), pv0 = await priceAt(), rv0 = await range();
  eq(await p.locator('.chart-reset').isHidden(), true, 'no "↺" while the price axis is normal');
  await gesture([[cx - 40, midY - 80, cx - 40, midY + 40], [cx + 40, midY - 80, cx + 40, midY + 40]]);
  const hv1 = await heights(), pv1 = await priceAt();
  eq(pv1 > pv0, true, `two fingers down together → the candles move down (the price in the middle rises: ${pv0} → ${pv1})`);
  eq([hv1.k, Math.abs(hv1.price - hv0.price) <= 2, await range()], [1, true, rv0], 'a two-finger vertical drag keeps the height and the time range');
  await p.locator('.chart-reset').click();
  await settle(300);
  eq([Math.abs((await priceAt()) - pv0) / pv0 < 1e-4, await p.locator('.chart-reset').isHidden()], [true, true], '"↺" brings the price axis back and hides');

  // ── צביטה אופקית: מתרחקות → פחות נרות; מתקרבות → יותר ──
  const h0 = await heights();
  await gesture([[cx - 30, midY, cx - 150, midY], [cx + 30, midY, cx + 150, midY]]);
  const rc = await range();
  eq(span(rc) < span(rb) * 0.5, true, `horizontal pinch out → fewer candles (${Math.round(span(rb))} → ${Math.round(span(rc))})`);
  eq((await heights()).k, 1, 'a horizontal pinch does not change the height');
  await gesture([[cx - 150, midY, cx - 30, midY], [cx + 150, midY, cx + 30, midY]]);
  const rd = await range();
  eq(span(rd) > span(rc) * 2, true, `horizontal pinch in → more candles (${Math.round(span(rc))} → ${Math.round(span(rd))})`);

  // ── צביטה אנכית: מתקרבות → נמוך יותר (נרות, אליוט, נפח); טווח הזמן לא זז ──
  const hb = await heights(), re = await range();
  await gesture([[cx, midY - 120, cx, midY - 30], [cx, midY + 120, cx, midY + 30]]);
  const hc = await heights();
  eq(hc.k < 0.6, true, `vertical pinch in → the chart is squeezed (k ${hc.k})`);
  eq(hc.price < hb.price * 0.7, true, `vertical pinch in → the candles (and the Elliott layer on the same price scale) are lower (${hb.price}px → ${hc.price}px)`);
  eq(hc.vol < hb.vol * 0.7, true, `vertical pinch in → the volume bars are lower (${hb.vol}px → ${hc.vol}px)`);
  eq(hc.lines.length > 0 && hc.lines.every(Boolean), true, 'vertical pinch in → the indicator lines are on the same squeezed scale as the candles');
  eq(await range(), re, 'a vertical pinch does not change the time range');
  eq(await p.evaluate(s => document.querySelector(s).tsForecast().methods.map(m => m.id), sel), ['elliott-w3'], 'Elliott still on the chart');
  // מתיחה — חזרה, ולא מעבר לגובה הרגיל
  await gesture([[cx, midY - 30, cx, midY - 160], [cx, midY + 30, cx, midY + 160]]);
  const hd = await heights();
  eq([hd.k, Math.abs(hd.price - h0.price) <= 2, Math.abs(hd.vol - h0.vol) <= 2], [1, true, true], `vertical pinch out → back to the normal height, not beyond (${JSON.stringify(hd)})`);

  // ── אחרי כיווץ: גרירה בזמן (לתוך התחזית / לאחור) לא משנה את טווח המחיר (הבעלים: "ככל שאני נכנס לעומק התחזית הגרף גדל") ──
  const edges = () => p.evaluate(s => { const b = document.querySelector(s); return [b.tsPriceAt(10), b.tsPriceAt(b.offsetHeight - 10)]; }, sel);
  await gesture([[cx, midY - 120, cx, midY - 50], [cx, midY + 120, cx, midY + 50]]);   // מכווצים שוב
  await gesture([[cx - 30, midY, cx - 150, midY], [cx + 30, midY, cx + 150, midY]]);   // מתקרבים בזמן — הגרירה תחליף את הנרות שעל המסך
  const e0 = await edges(), hs0 = await heights(), rz = await range();
  await gesture([[R.x + R.w * 0.8, midY, R.x + R.w * 0.25, midY]]);   // שמאלה — קדימה בזמן, לתוך התחזית
  const e1 = await edges();
  await gesture([[R.x + R.w * 0.2, midY, R.x + R.w * 0.9, midY]]);    // ימינה — אחורה
  await gesture([[R.x + R.w * 0.2, midY, R.x + R.w * 0.9, midY]]);
  const e2 = await edges(), rz2 = await range();
  eq(Math.abs(rz2[0] - rz[0]) > 5, true, `the drags really moved in time (${rz} → ${rz2})`);
  const same = (a, b) => a.every((v, i) => Math.abs(v - b[i]) / Math.abs(b[i]) < 1e-6);
  eq([same(e1, e0), same(e2, e0)], [true, true], `after squeezing, dragging in time keeps the price range (${e0.map(Math.round)} → ${e1.map(Math.round)} → ${e2.map(Math.round)})`);
  eq((await heights()).k, hs0.k, 'and the squeeze');

  // "↺" — חוזרים ל-autoScale: הטווח מתאים את עצמו שוב לנרות שעל המסך
  await p.locator('.chart-reset').click();
  await settle(300);
  const e3 = await edges();
  eq([(await heights()).k, same(e3, e0), await p.locator('.chart-reset').isHidden()], [1, false, true], `"↺" after a squeeze → normal height and automatic price range again (${e3.map(Math.round)})`);

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
