// תנועות מגע על הגרף בטלפון (מטלה דחופה מהבעלים, 2026-11-16) — בדפדפן אמיתי, מגע אמיתי (CDP Input.dispatchTouchEvent).
// node trading-strategies/tests/chart-gestures-ui.mjs   (Playwright, טלפון 390×844; ספקים מדומים, בלי רשת)
//   - אצבע אחת במאונך על הגרף → הדף נגלל, טווח הזמן לא זז.
//   - אצבע אחת לאורך הגרף → טווח הזמן זז (כמו קודם).
//   - צביטה אופקית → פחות נרות על המסך (טווח הזמן קטן).
//   - צביטה אנכית → הנרות, השכבות (אליוט) והנפח נמוכים יותר; טווח הזמן לא זז. מתיחה — חופשית, גם מעבר לגובה הרגיל (עד פי 8).
//   - (בקרה 11, הבעלים: "למה לא נותן להגביה בצורה חופשית") הכיוון לפי התנועה, לא לפי הזווית שבה הונחו האצבעות; מעבר בין
//     גרירה לצביטה בלי להרים אצבעות.
//   - שתי אצבעות שזזות יחד לגובה → ציר המחיר זז, בלי לשנות גובה וטווח זמן; "↺" מחזיר. שתי אצבעות יחד לרוחב → הזמן זז
//     (בקרה 11, 2026-11-17 — מחליף את "2 אצבעות רק לגובה" של 11-16), בלי להקפיא את ציר המחיר.
//   - crosshair דולק (לחיצה ארוכה) → שתי אצבעות עובדות, וה-crosshair נשאר על אותו נר ואותו מחיר בצביטה ובגרירה; הקשה מכבה.
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
// הבדיקה הזו — על התנועות. גרירה עד הנר הראשון טוענת היסטוריה בפעימות (market_history), והמספור הלוגי של הנרות
// זז במספר שנוסף — מה שהבדיקות כאן משוות. ההיסטוריה נבדקת לבד ב-tests/history-ui.mjs, כאן "אין יותר"
await p.route(/api\.php\?action=market_history/, r => r.fulfill({ status: 200, contentType: 'application/json',
  body: JSON.stringify({ ok: true, candles: [], done: true, retry: false, notice: null }) }));
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
/** paths: לכל אצבע — רשימת נקודות [[x,y], …]; כל קטע ב-steps צעדים, כל האצבעות יחד. מגע אחד, בלי להרים */
async function path(paths, steps = 12) {
  const seg = paths[0].length - 1;
  const at = (s, i) => paths.map((pt, id) => ({ x: pt[s][0] + (pt[s + 1][0] - pt[s][0]) * i / steps, y: pt[s][1] + (pt[s + 1][1] - pt[s][1]) * i / steps, id }));
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: paths.map((pt, id) => ({ x: pt[0][0], y: pt[0][1], id })) });
  for (let sg = 0; sg < seg; sg++) for (let i = 1; i <= steps; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: at(sg, i) }); await p.waitForTimeout(16); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await settle();
}
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

  // ── שתי אצבעות יחד לרוחב → הזמן זז (בקרה 11), בלי לשנות גובה ובלי להקפיא את ציר המחיר. לפני: כלום ──
  const rTwo0 = await range();
  await gesture([[R.x + R.w * 0.65, midY - 40, R.x + R.w * 0.25, midY - 40], [R.x + R.w * 0.65, midY + 40, R.x + R.w * 0.25, midY + 40]]);
  const rTwo1 = await range();
  eq([rTwo1[0] > rTwo0[0] + 5, Math.abs(span(rTwo1) - span(rTwo0)) < 1, (await heights()).k, await p.locator('.chart-reset').isHidden()], [true, true, 1, true],
    `two fingers together sideways → the time axis moves with them (${rTwo0} → ${rTwo1}), same number of candles, no height change, price axis not frozen`);
  await gesture([[R.x + R.w * 0.25, midY - 40, R.x + R.w * 0.65, midY - 40], [R.x + R.w * 0.25, midY + 40, R.x + R.w * 0.65, midY + 40]]);
  const rb2 = await range();   // חזרה — כדי שהבדיקות הבאות יתחילו מאותו מקום
  eq(Math.abs(rb2[0] - rTwo0[0]) < 3, true, `… and back (${rb2})`);

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
  // מתיחה — חופשית: חזרה לגובה הרגיל, וגם מעבר לו (הבעלים: "למה לא נותן להגביה בצורה חופשית"). לפני: נעצר ב-1
  await gesture([[cx, midY - 30, cx, midY - 160], [cx, midY + 30, cx, midY + 160]]);
  const hd = await heights();
  eq([hd.k > 1.2, hd.price > h0.price * 1.2, await range()], [true, true, re], `vertical pinch out → taller than the normal height, freely (k ${hd.k}, candles ${h0.price}px → ${hd.price}px); the time range stays`);
  for (let i = 0; i < 4; i++) await gesture([[cx, midY - 20, cx, midY - 200], [cx, midY + 20, cx, midY + 200]]);
  eq((await heights()).k, 8, 'stretching again and again stops at a sane cap (×8)');
  await p.locator('.chart-reset').click();
  await settle(300);

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

  // ── הכיוון לפי התנועה, לא לפי הזווית שבה הונחו האצבעות (בקרה 11) ──
  // אצבעות מונחות באלכסון (~35°, יותר רוחב מגובה) ונפתחות **בגובה** → מתיחה; טווח הזמן לא זז. לפני: "zoom" לפי הזווית
  const rq0 = await range(), hq0 = await heights();
  await path([[[cx - 60, midY - 42], [cx - 60, midY - 130]], [[cx + 60, midY + 42], [cx + 60, midY + 130]]]);
  const hq1 = await heights();
  eq([hq1.k > hq0.k * 1.5, await range()], [true, rq0], `fingers placed at ~35° that open up/down → the height grows (k ${hq0.k} → ${hq1.k}), not the time zoom`);
  // אותן אצבעות באלכסון שנפתחות **ברוחב** → טווח הזמן
  await path([[[cx - 50, midY - 35], [cx - 150, midY - 35]], [[cx + 50, midY + 35], [cx + 150, midY + 35]]]);
  eq([span(await range()) < span(rq0) * 0.6, (await heights()).k], [true, hq1.k], 'fingers at ~35° that open sideways → fewer candles (time zoom), the height stays');
  await p.locator('.chart-reset').click();
  await settle(300);

  // ── מעבר בין גרירה לצביטה בלי להרים אצבעות (בקרה 11). לפני: התנועה ננעלה על הראשונה ──
  const pz0 = await priceAt(), hz0 = await heights(), rz0 = await range();
  await path([
    [[cx, midY - 60], [cx, midY + 20], [cx, midY - 60]],     // יחד למטה (גרירה), ואז נפתחות בגובה (צביטה)
    [[cx, midY + 60], [cx, midY + 140], [cx, midY + 220]],
  ]);
  const pz1 = await priceAt(), hz1 = await heights();
  eq([pz1 > pz0, hz1.k > hz0.k * 1.5, await range()], [true, true, rz0], `one touch: drag down (the price axis moves: ${Math.round(pz0)} → ${Math.round(pz1)}), then open up/down (taller: k ${hz0.k} → ${hz1.k}) — without lifting the fingers`);
  // ולהפך: צביטה ואז גרירה
  const pz2 = await priceAt();
  await path([
    [[cx, midY - 40], [cx, midY - 80], [cx, midY - 160]],
    [[cx, midY + 40], [cx, midY + 80], [cx, midY]],
  ]);
  const hz3 = await heights(), pz3 = await priceAt();
  eq([hz3.k > hz1.k, pz3 !== pz2], [true, true], `and the other way: open (k ${hz1.k} → ${hz3.k}), then drag up together (the price axis moves) — one touch`);
  eq(await p.locator('.chart-reset').isVisible(), true, '"↺" is on screen after any manual change');
  const rb0 = await p.locator('.chart-reset').boundingBox();
  eq(rb0.width >= 36 && rb0.height >= 36, true, `"↺" is big enough for a finger (${Math.round(rb0.width)}×${Math.round(rb0.height)})`);
  await p.locator('.chart-reset').click();
  await settle(300);
  eq([(await heights()).k, await p.locator('.chart-reset').isHidden()], [1, true], '"↺" → back to normal');

  // ── crosshair דולק (בקרה 11, הבעלים: "כשיש לי את הפסים המקווקווים… אני לא יכול לגרור את הגרף") ──
  // שתי אצבעות גוברות; ה-crosshair מעוגן לנר ולמחיר — בצביטה נשאר עליהם, בגרירה זז עם הגרף. לפני: נעלם
  // הבדיקות הקודמות השאירו את התצוגה לפני הנר הראשון — טווח אחר טוען מחדש ומציג את כל הנרות
  await p.locator('select[name="range"]').selectOption('m');
  await p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
  await settle(500);
  const crossAt = () => p.evaluate(s => document.querySelector(s).tsCrossAt(), sel);
  const close = (a, b) => !!a && !!b && a.time === b.time && Math.abs(a.price - b.price) / Math.abs(b.price) < 1e-4;
  async function longPress(x, y) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y, id: 0 }] });
    await p.waitForTimeout(900);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await settle(300);
  }
  // על נר (לא בשטח הריק מימין לנר האחרון, שאין בו זמן)
  const lx = await p.evaluate(s => { const b = document.querySelector(s); const r = b.getBoundingClientRect(); for (let x = 60; x < r.width - 60; x += 10) if (b.tsTimeAt(x) && b.tsTimeAt(x + 40)) return r.left + x + 20; return r.left + 80; }, sel);
  await longPress(lx, midY - 40);
  const x0 = await crossAt();
  eq(!!x0?.time && Number.isFinite(x0?.price), true, `a long press turns the crosshair on (${JSON.stringify(x0)})`);
  // צביטה בגובה
  const kx0 = (await heights()).k;
  await gesture([[cx, midY - 40, cx, midY - 120], [cx, midY + 40, cx, midY + 120]]);
  const x1 = await crossAt();
  eq([(await heights()).k > kx0 * 1.5, close(x1, x0)], [true, true], `crosshair on → a vertical pinch still stretches (k ${kx0} → ${(await heights()).k}), and the crosshair stays on the same candle and price (${JSON.stringify(x1)})`);
  // צביטה בזמן — סביב ה-crosshair (מתקרבים אליו; כך הוא נשאר על המסך)
  const rx0 = await range();
  const sx0 = await p.evaluate(s => { const b = document.querySelector(s); return b.getBoundingClientRect().left + b.tsCross().x; }, sel);
  await gesture([[sx0 - 20, midY, sx0 - 70, midY], [sx0 + 20, midY, sx0 + 70, midY]]);
  const x2 = await crossAt();
  eq([span(await range()) < span(rx0) * 0.8, close(x2, x0)], [true, true], `… a time pinch zooms, and the crosshair stays on the same candle and price (${JSON.stringify(x2)})`);
  // גרירה בשתי אצבעות (באלכסון) — הגרף זז, והנקודה איתו
  const rx1 = await range(), sc0 = await p.evaluate(s => document.querySelector(s).tsCross(), sel);
  await gesture([[cx - 40, midY - 40, cx - 10, midY - 10], [cx + 40, midY + 40, cx + 70, midY + 70]]);
  const x3 = await crossAt(), sc1 = await p.evaluate(s => document.querySelector(s).tsCross(), sel);
  eq([JSON.stringify(await range()) !== JSON.stringify(rx1), close(x3, x0), !!sc1 && Math.abs(sc1.x - sc0.x) > 15 && Math.abs(sc1.y - sc0.y) > 15], [true, true, true],
    `… a two-finger drag moves the chart, and the crosshair moves with it — same candle and price, new place on screen (${JSON.stringify(sc0)} → ${JSON.stringify(sc1)})`);
  // כיבוי — הקשה, כמו היום
  const tb = await rect();
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: tb.x + tb.w * 0.3, y: tb.y + tb.h * 0.7, id: 0 }] });
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await settle(500);
  eq(await crossAt(), null, 'a tap turns the crosshair off, as before');
  await longPress(lx, midY - 40);
  eq(!!(await crossAt())?.time, true, '… and a long press turns it on again');
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: tb.x + tb.w * 0.3, y: tb.y + tb.h * 0.7, id: 0 }] });
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await settle(500);
  await p.locator('.chart-reset').click().catch(() => {});
  await settle(300);

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
