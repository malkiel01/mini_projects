// F1 — שיטות על הגרף, בדפדפן אמיתי (spec-stage-a 18.1.2, ושינוי מהבעלים 2026-11-15: 🔮 = תפריט עם תיבות סימון, כמה יחד).
// node trading-strategies/tests/chart-f1-ui.mjs   (Playwright; ספקים מדומים, בלי רשת)
// SHOTS=<תיקייה> — צילומי מסך (deviceScaleFactor 2) ברוחב 390, בבהיר ובכהה.
//
// הנרות — TS_KLINES_SHAPE=wave: גלים סביב 82,000, שבהם אליוט מוצאת ספירה עם תוויות (קריטריון 8), ושבהם רואים את
// פורמט המחירים (קריטריון 7). אין שורה ל-elliott-w3 ב-methods — שיטה מהספר מופעלת בלי שום הגדרה (קריטריון 1).
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-f1-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const SHOTS = process.env.SHOTS;
const NOW = +(process.env.TS_NOW || 1794094200);
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as, { width = 390, height = 860, scheme = 'light', mobile = true } = {}) {
  const ctx = await b.newContext({ viewport: { width, height }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: mobile, isMobile: mobile, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  const reqs = [];
  p.on('request', r => { if (r.url().includes('api.php')) { const q = new URL(r.url()).searchParams; reqs.push([q.get('action'), q.get('methods')]); } });
  return { p, reqs, ctx };
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name, full = true) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: full }); };
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const canvasPrint = p => p.evaluate(() => [...document.querySelectorAll('.chart-box:not(.osc) canvas')].map(c => c.toDataURL().length).join(','));
const boxRect = p => p.evaluate(() => { const r = document.querySelector('.chart-box:not(.osc)').getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; });
const labels = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsLabels());
const axis = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsAxis());
const overlaps = rs => {
  const out = [];
  for (let i = 0; i < rs.length; i++) for (let j = i + 1; j < rs.length; j++) {
    const a = rs[i], c = rs[j];
    if (a.x < c.x + c.w && c.x < a.x + a.w && a.y < c.y + c.h && c.y < a.y + a.h) out.push([a.text, c.text]);
  }
  return out;
};
const refreshes = reqs => reqs.filter(([a]) => a === 'chart_refresh').map(([, m]) => m || '');
const pop = p => p.locator('.fc-pop');
const fcBtn = p => p.getByRole('button', { name: 'תחזית שיטה' }).first();
// יציאה ממסך מלא: ה-class ירד, וגם ה-Fullscreen API סיים (החלון חוזר לגודלו)
async function fsGone(p) {
  await p.waitForFunction(() => !document.querySelector('.chart-wrap.fs') && !document.fullscreenElement);
  await p.waitForTimeout(600);
}
async function openPop(p) {
  // במסך מלא — ה-🔮 שבשורה הצפה (זה שבכותרת מכוסה)
  await (await p.locator('.chart-wrap.fs').count() ? p.locator('.fs-bar').getByRole('button', { name: 'תחזית שיטה' }) : fcBtn(p)).click();
  await pop(p).locator('.fc-opt, .fc-none').first().waitFor();
}

try {
  // ── קריטריון 1: משתמש חדש, אין שורה לאליוט ב-methods → 🔮 → "אליוט" בתפריט מעל הגרף ──
  const { p: a, reqs } = await open('alice');
  await a.goto(CHART);
  await loaded(a);
  const url0 = a.url();
  await a.evaluate(() => { document.querySelector('main').firstElementChild.dataset.f1 = 'same'; });   // סימן: אותו DOM
  const rect0 = await boxRect(a);
  const before = await canvasPrint(a);
  await openPop(a);
  const names = await pop(a).locator('.fc-opt').allTextContents();
  eq(names.some(t => /אליוט/.test(t)), true, 'criterion 1: "אליוט" in the 🔮 menu with no admin setting (book method on by default)');
  eq(names.some(t => /חציית ממוצעים/.test(t)), false, 'criterion 3: the sample method is not offered to a regular user');
  eq(await a.locator('dialog[open]').count(), 0, 'no sheet (dialog) — a popover');
  const pr = await pop(a).evaluate(e => { const r = e.getBoundingClientRect(), c = document.querySelector('.chart-box').getBoundingClientRect(); return r.top >= c.top && r.top < c.bottom && r.left >= c.left && r.right <= c.right; });
  eq(pr, true, 'the menu opens over the chart (inside the chart frame)');
  eq(await pop(a).locator('input[type="checkbox"]').count() >= 1, true, 'the menu items are checkboxes');
  await shot(a, 'f1-menu-light', false);
  reqs.length = 0;
  await pop(a).locator('.fc-opt', { hasText: 'אליוט' }).locator('input').check();
  const panel = a.locator('.forecast-panel[data-method="elliott-w3"]');
  await panel.locator('.chips').waitFor({ timeout: 60000 });
  eq(refreshes(reqs), ['elliott-w3'], 'checking a method → one chart_refresh with it');
  eq(await pop(a).isVisible(), true, 'the menu stays open after checking (more can be checked)');
  await a.waitForTimeout(400);
  eq(await canvasPrint(a) !== before, true, 'criterion 1: the count is drawn on the chart');
  eq([a.url() === url0, await a.evaluate(() => document.querySelector('main').firstElementChild.dataset.f1)], [true, 'same'], 'criterion 1: no screen change (same URL, same DOM)');
  // הגודל — זהה. המקום יכול לרדת בשורה: בורר קצב הרענון ו"עודכן לפני" מופיעים כשיש ניתוח פעיל (S3, 18.3)
  eq((await boxRect(a)).slice(2), rect0.slice(2), 'criterion 4: the chart is visible and the same size before and after (phone)');
  eq((await boxRect(a))[1] < 860 / 2, true, 'and still in the upper half of the screen');
  // סגירה: לחיצה מחוץ לתפריט; Esc
  await a.locator('.quote-head').click();
  eq(await pop(a).isHidden(), true, 'a click outside the menu closes it');
  await openPop(a);
  await a.keyboard.press('Escape');
  eq(await pop(a).isHidden(), true, 'Esc closes the menu');
  eq(await a.locator('.chart-wrap.fs').count(), 0, 'Esc on the menu does not do anything else');

  // ── קריטריון 7: ציר המחירים בתוך הגרף, 82,000 ולא 82000.00 ──
  const ax = await axis(a);
  eq(ax.price.length >= 3 && ax.price.every(s => /^\d{1,3}(,\d{3})+$/.test(s)), true, `criterion 7: price ticks with a thousands separator and no ".00" (${ax.price.join(' | ')})`);
  eq(ax.time.length >= 2, true, `the time axis inside the chart (${ax.time.join(' | ')})`);
  const cw = await a.evaluate(() => { const box = document.querySelector('.chart-box:not(.osc)'); return [box.clientWidth, Math.max(...[...box.querySelectorAll('canvas')].map(c => c.getBoundingClientRect().width))]; });
  eq(cw[1] >= cw[0] - 1, true, `criterion 7: no price-scale column — the pane is the full width (${cw[1]} of ${cw[0]})`);
  eq(/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/.test((await a.locator('.quote-head .price').textContent()).trim()), true, 'the price above the chart — thousands separator');

  // ── קריטריון 8: ברוחב 390, אליוט, ביטקוין, שעה, 3 חודשים — אין שתי תוויות חופפות ──
  // שני שרטוטי פיבונאצ'י על אותן נקודות — אלה היו התוויות הירוקות שנערמו בצד שמאל אצל הבעלים
  const fib = { asset: 'binance:BTCUSDT', tf: null, kind: 'fib', points: [{ t: NOW - 40 * 86400, p: 78000 }, { t: NOW - 10 * 86400, p: 86000 }], style: { color: '#1e7b3a', width: 2 } };
  eq([await post(a, 'drawing_save', fib), await post(a, 'drawing_save', fib)], [200, 200], 'two Fibonacci drawings on the same points');
  await a.reload();
  await loaded(a);
  await a.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
  await a.waitForTimeout(500);
  const ls = await labels(a);
  const W = (await boxRect(a))[2];
  eq(ls.some(l => l.by === 'forecast') && ls.some(l => l.by === 'drawings'), true, `labels from the method and from the drawings (${ls.length})`);
  eq(overlaps(ls), [], 'criterion 8: no two labels overlap (measureText rectangles)');
  eq(ls.some(l => l.by === 'forecast' && /^רמת ביטול/.test(l.text)), true, 'the method label wins over an overlapping drawing label (the leading count first)');
  const fibTexts = ls.filter(l => l.by === 'drawings').map(l => l.text);
  eq(fibTexts.length === new Set(fibTexts).size, true, 'two identical Fibonacci drawings → each level label once (the first one)');
  // השרטוט מתחיל לפני 40 יום ונגמר לפני 10, מתוך 90 בתצוגה — התוויות בקצה הימני שלו, לא בשמאלי
  const fibR = ls.filter(l => l.by === 'drawings').map(l => Math.round(l.x + l.w));
  eq(fibR.length > 0 && Math.max(...fibR) - Math.min(...fibR) <= 1 && fibR[0] > W / 2, true, `Fibonacci labels right-aligned at the right end of the drawing (${fibR.join(',')})`);
  eq(ls.filter(l => l.by === 'forecast' && / · /.test(l.text)).every(l => l.x + l.w >= W - 8), true, 'level labels on the right edge (the present)');
  eq(ls.filter(l => / · \d/.test(l.text)).every(l => !/\.00\b/.test(l.text)), true, 'level prices without ".00"');
  eq(await noHScroll(a), true, 'no horizontal scroll at 390px');
  await shot(a, 'f1-elliott-light', false);

  // ── הבחירה נשמרת ──
  reqs.length = 0;
  await a.reload();
  await loaded(a);
  await a.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
  eq(refreshes(reqs)[0], 'elliott-w3', 'the choice is kept: reopening the chart asks for Elliott in the first request');
  eq(await fcBtn(a).getAttribute('aria-pressed'), 'true', 'and 🔮 shows it is on');

  // ── קריטריון 6: מסך מלא ──
  const vp = a.viewportSize();
  await a.getByRole('button', { name: 'מסך מלא' }).click();
  await a.locator('.chart-wrap.fs').waitFor();
  await a.waitForTimeout(300);
  const fr = await boxRect(a);
  eq(fr[2] >= vp.width - 1 && fr[3] >= vp.height * 0.8, true, `criterion 6: ⛶ → the chart fills the screen (${fr.join(',')})`);
  eq(await a.evaluate(() => { const r = document.querySelector('.chart-wrap').getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, 20); return !!el.closest('.chart-wrap'); }), true, 'nothing but the chart on top (no app header)');
  eq(await a.locator('.fs-bar').getByText('BTC').isVisible(), true, 'the pair label floats over the chart');
  await openPop(a);
  eq(await pop(a).isVisible(), true, 'criterion 6: 🔮 works in full screen');
  await a.locator('.chart-box:not(.osc)').click({ position: { x: 60, y: fr[3] - 60 } });
  eq(await pop(a).isHidden(), true, 'a click on the chart closes the menu');
  await shot(a, 'f1-fullscreen-light', false);
  await a.getByRole('button', { name: 'יציאה ממסך מלא' }).click();
  await fsGone(a);
  eq([await a.locator('.chart-wrap.fs').count(), a.url() === url0], [0, true], 'criterion 6: ✕ → back to the normal chart');
  eq((await boxRect(a)).slice(2), rect0.slice(2), 'the chart is back to its size');
  await a.getByRole('button', { name: 'מסך מלא' }).click();
  await a.locator('.chart-wrap.fs').waitFor();
  await a.keyboard.press('Escape');
  await fsGone(a);
  eq(a.url() === url0, true, 'criterion 6: Esc → back to the normal chart');
  await a.getByRole('button', { name: 'מסך מלא' }).click();
  await a.locator('.chart-wrap.fs').waitFor();
  await a.goBack();
  await fsGone(a);
  eq([a.url() === url0, await a.locator('.chart-box:not(.osc)').count()], [true, 1], 'criterion 6: "back" leaves full screen and stays on the chart');

  // ── כמה שיטות יחד (שינוי מהבעלים): בבקשה אחת, כל אחת בצבע משלה ──
  const { p: o, reqs: oreqs } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main');
  eq(await post(o, 'admin_method_save', { id: 'sample-ma-cross', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the admin turns on the sample method');
  await o.goto(CHART);
  await loaded(o);
  await openPop(o);
  oreqs.length = 0;
  await pop(o).locator('.fc-opt', { hasText: 'אליוט' }).locator('input').check();
  await o.locator('.forecast-panel[data-method="elliott-w3"] .chips').waitFor({ timeout: 60000 });
  await pop(o).locator('.fc-opt', { hasText: /חציית ממוצעים/ }).locator('input').check();
  await o.locator('.forecast-panel[data-method="sample-ma-cross"] .chips').waitFor({ timeout: 60000 });
  eq(refreshes(oreqs).at(-1), 'elliott-w3,sample-ma-cross', 'two methods → one chart_refresh with both');
  const cols = await o.locator('.forecast-panel').evaluateAll(els => els.map(e => getComputedStyle(e).getPropertyValue('--mc').trim()));
  eq([cols.length, new Set(cols).size], [2, 2], `each method its own color from the palette (${cols.join(', ')})`);
  await o.keyboard.press('Escape');
  await o.waitForTimeout(400);
  eq(overlaps(await labels(o)), [], 'two methods together — still no overlapping labels');
  await shot(o, 'f1-two-methods-light', false);
  // מנהל כיבה את אליוט → למשתמש רגיל "אין שיטה פעילה", בלי קישור; למנהל — קישור
  eq(await post(o, 'admin_method_save', { id: 'elliott-w3', enabled: false, markets: ['crypto'], timeframes: ['h1', 'h4'] }), 200, 'the admin turns Elliott off');
  const { p: c } = await open('bob');
  await c.goto(CHART);
  await loaded(c);
  await openPop(c);
  eq([(await pop(c).locator('.fc-none').textContent()).trim(), await pop(c).locator('a').count()], ['אין שיטה פעילה', 0], 'criterion 2: Elliott off → "אין שיטה פעילה" for a regular user, no admin link');
  await shot(c, 'f1-none-light', false);
  await o.evaluate(() => localStorage.clear());
  eq(await post(o, 'admin_method_save', { id: 'sample-ma-cross', enabled: false, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the admin turns the sample off too');
  await o.reload();
  await loaded(o);
  await openPop(o);
  eq(await pop(o).locator('a[href="#/admin"]').count(), 1, 'the admin sees a small link to the methods admin');
  // שיטה שנשמרה בבחירה וכובתה — יוצאת מהבחירה בשקט (alice בחרה אליוט קודם)
  await a.reload();
  await loaded(a);
  await a.waitForTimeout(500);
  eq([await a.locator('.forecast-panel').count(), await a.evaluate(() => localStorage.getItem('ts-forecast-methods'))], [0, '[]'], 'a saved method that was turned off leaves the choice quietly');
  eq(await post(o, 'admin_method_save', { id: 'elliott-w3', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4'] }), 200, 'Elliott back on');

  // ── במחשב: הגרף באותו גודל לפני ואחרי (קריטריון 4) ──
  const { p: d } = await open('carol', { width: 1280, height: 860, mobile: false });
  await d.goto(CHART);
  await loaded(d);
  const dr0 = await boxRect(d);
  await openPop(d);
  await pop(d).locator('.fc-opt', { hasText: 'אליוט' }).locator('input').check();
  await d.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
  eq((await boxRect(d)).slice(2), dr0.slice(2), 'criterion 4: same chart size before and after (desktop)');

  // ── לרוחב (טלפון מסובב): מסך מלא ממלא את המסך ──
  const { p: l } = await open('alice', { width: 844, height: 390 });
  await l.goto(CHART);
  await loaded(l);
  await l.getByRole('button', { name: 'מסך מלא' }).click();
  await l.locator('.chart-wrap.fs').waitFor();
  await l.waitForTimeout(400);
  const lr = await boxRect(l);
  eq(lr[2] >= 843 && lr[3] >= 340, true, `criterion 6: full screen in landscape (${lr.join(',')})`);

  // ── צילומים בכהה ──
  if (SHOTS) {
    const { p: k } = await open('alice', { scheme: 'dark' });
    await k.goto(CHART);
    await loaded(k);
    await openPop(k);
    await pop(k).locator('.fc-opt', { hasText: 'אליוט' }).locator('input').check();
    await k.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
    await shot(k, 'f1-menu-dark', false);
    await k.keyboard.press('Escape');
    await k.waitForTimeout(300);
    await shot(k, 'f1-elliott-dark', false);
    await k.getByRole('button', { name: 'מסך מלא' }).click();
    await k.locator('.chart-wrap.fs').waitFor();
    await k.waitForTimeout(300);
    await shot(k, 'f1-fullscreen-dark', false);
    await l.screenshot({ path: join(SHOTS, 'f1-landscape-light.png') });
  }
} catch (e) {
  fail++;
  console.log('✗ ' + e.stack);
} finally {
  eq(errors.filter(e => !/^404 /.test(e)), [], 'no page errors');
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
