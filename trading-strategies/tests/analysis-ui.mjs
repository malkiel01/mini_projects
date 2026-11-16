// S4 — ממצא · תרחיש · הסתברות בפס שמתחת לגרף, בדפדפן אמיתי (spec-stage-a 18.4; 18.3 — הבדיקה לאחור לא רצה ברענון).
// היה: מסך "🧭 ניתוח" נפרד — הוסר (2.15). כאן גם הפרופיל וניהול השיטות (5, 6.7), שנשארו.
// node trading-strategies/tests/analysis-ui.mjs   (Playwright; ספקים מדומים, בלי רשת)
// SHOTS=<תיקייה> — צילומי מסך (deviceScaleFactor 2) ברוחב 390, בבהיר ובכהה.
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-s4-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
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

const state = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast());
const blocks = p => p.locator('.forecast-panel');
const block = (p, id) => p.locator(`.forecast-panel[data-method="${id}"]`);
const toolsRect = p => p.evaluate(() => { const r = document.querySelector('.chart-tools').getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height)]; });
// הבדיקה לאחור הסתיימה: אין "מריץ…" באף שיטה, ויש הסתברות
const settled = p => p.waitForFunction(() => { const s = [...document.querySelectorAll('.forecast-panel .an-sec.prob')];
  return s.length && s.every(x => !/מריץ בדיקה לאחור/.test(x.textContent) && /ודאות|לא נבדק לאחור/.test(x.textContent)); }, null, { timeout: 90000 });
async function pick(p, name) {
  await openPop(p);
  await pop(p).locator('.fc-opt', { hasText: name }).locator('input').check();
  await p.keyboard.press('Escape');
}

try {
  // ── מנהל: מפעיל את שיטת הדוגמה מהמסך (6.7) ──
  const { p: o, reqs } = await open('owner');
  await o.goto(BASE + '#/admin');
  await o.getByRole('tab', { name: '🧪 שיטות' }).click();
  await o.waitForSelector('text=🧪 שיטת דוגמה — לא מהספר');
  const sampleRow = o.locator('main .list > li', { hasText: '🧪 שיטת דוגמה — לא מהספר' });
  eq(await sampleRow.locator('.switch input').isChecked(), false, 'admin: the sample method is listed OFF');
  eq(await noHScroll(o), true, 'admin methods: no horizontal scroll on a phone');
  await sampleRow.locator('.switch input').check();
  await sampleRow.getByRole('button', { name: 'שמירה' }).click();
  await o.waitForSelector('text=נשמר');

  // ── קריטריון 7: אין 🧭, אין לשונית "ניתוח", ו-#/analysis/ מפנה לגרף ──
  await o.goto(BASE + '#/analysis/' + encodeURIComponent('binance:BTCUSDT'));
  await loaded(o);
  eq(decodeURIComponent(new URL(o.url()).hash), '#/chart/binance:BTCUSDT', 'criterion 7: #/analysis/<asset> opens the chart of that asset');
  eq([await o.locator('a[href^="#/analysis"], a[aria-label="ניתוח לפי שיטות"]').count(), await o.locator('nav.bottom-nav a').count()], [0, 4],
    'criterion 7: no 🧭 button and no "ניתוח" tab in the bottom navigation');
  eq(/🧭/.test(await o.locator('#head-actions').textContent()), false, 'no 🧭 in the chart header');
  await o.goto(BASE + '#/analysis');
  await o.waitForURL(/#\/market$/);
  eq(new URL(o.url()).hash, '#/market', '#/analysis (no asset) → the market screen');

  // ── הגרף בלי שיטה: מודדים את הגרף ואת שורת הכפתורים ──
  await o.goto(CHART);
  await loaded(o);
  await o.evaluate(() => localStorage.setItem('ts-forecast-methods', '[]'));
  await o.reload();
  await loaded(o);
  const rect0 = await boxRect(o), tools0 = await toolsRect(o);
  eq(await blocks(o).count(), 0, 'no method → no panel');
  // ── שיטה אחת: הפס מתחת לגרף ──
  reqs.length = 0;
  await pick(o, 'חציית ממוצעים');
  const s1 = block(o, 'sample-ma-cross');
  await s1.locator('.an-sec.prob').waitFor({ timeout: 60000 });
  await settled(o);
  eq([await boxRect(o), await toolsRect(o)], [rect0, tools0], 'the chart stays put and the same size when the analysis turns on — no line drops on a phone (🟡 F1)');
  eq(await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').getBoundingClientRect().height >= innerHeight / 2), true, 'criterion 8: the chart is at least half the phone screen');
  eq(await o.evaluate(() => { const c = document.querySelector('.chart-box:not(.osc)').getBoundingClientRect(), pn = document.querySelector('.an-panel').getBoundingClientRect(); return pn.top >= c.bottom; }), true, 'the panel is under the chart');
  eq(await s1.locator('.an-sec > h4').allTextContents(), ['ממצא', 'תרחיש', 'הסתברות'], 'criterion 2: three separate headings in the card');
  // קריטריון 2: אף מספר בלי הכותרת שלו — ספרה מחוץ לשלושת החלקים רק בשורת הסיכום (עם המילה שלה) ובשבבים
  eq(await s1.evaluate(e => { const c = e.cloneNode(true); c.querySelectorAll('.an-sec, .an-fold, .chips, .disclaimer, button').forEach(x => x.remove()); return /\d/.test(c.textContent); }), false,
    'criterion 2: no number outside its heading');
  eq(await o.locator('.an-panel .an-bar select[aria-label="קצב הרענון האוטומטי"]').count(), 1, 'the refresh rate sits in the panel header, not in the chart toolbar');
  const cardTxt = await s1.textContent();
  eq(cardTxt.includes('🧪 שיטת דוגמה — לא מהספר'), true, 'the card carries the sample label');
  eq(/ודאות\s*\d+%|לא נבדק לאחור/.test(await s1.locator('.an-sec.prob').textContent()), true, 'probability: certainty with its source, or "not backtested"');
  eq(/אותות/.test(await s1.locator('.an-sec.prob').textContent()) || /לא נבדק לאחור/.test(await s1.locator('.an-sec.prob').textContent()), true, 'N of signals next to the certainty');
  eq(/אופק/.test(await s1.locator('.an-sec.scenario').textContent()), true, 'the horizon is part of the scenario');
  eq(/קנה|מכור|המלצ|כדאי/.test(await o.locator('main').textContent()), false, 'no "buy/sell/recommend" wording (6.6)');
  eq((await s1.locator('.disclaimer').textContent()).includes('אינו ייעוץ השקעות'), true, 'the fixed disclaimer is in the card');
  eq(await o.evaluate(() => [...document.querySelectorAll('.forecast-panel .num')].every(e => getComputedStyle(e).direction === 'ltr')), true, 'numbers are LTR inside the RTL card');
  const bt = reqs.filter(([a]) => a === 'analysis').length;
  eq([refreshes(reqs).length, bt], [1, 1], 'choosing a method: one chart_refresh, and one analysis to run the missing backtest (a user action)');
  eq(await noHScroll(o), true, 'no horizontal scroll on a phone (390px)');
  await shot(o, 's4-one-light');

  // ── 18.3: הרענון האוטומטי לא מריץ בדיקה לאחור ──
  await o.locator('select[aria-label="קצב הרענון האוטומטי"]').selectOption('30');
  const probBefore = await s1.locator('.an-sec.prob').textContent();
  reqs.length = 0;
  await o.waitForFunction(() => true, null, { timeout: 1000 });
  await o.waitForTimeout(32000);
  eq([refreshes(reqs).length >= 1, reqs.filter(([a]) => a === 'analysis').length], [true, 0], 'an automatic refresh → chart_refresh only, no analysis (the backtest does not run on refresh)');
  eq(await s1.locator('.an-sec.prob').textContent(), probBefore, 'the card shows the stored backtest after the refresh');

  // ── שתי שיטות: שקלול, כל אחת בצבע שלה (קריטריון 5: התרחיש לא משנה את הציון) ──
  reqs.length = 0;
  await pick(o, 'אליוט');
  await block(o, 'elliott-w3').locator('.an-sec.prob').waitFor({ timeout: 60000 });
  await settled(o);
  await o.locator('.blend-card').waitFor();
  eq(await o.locator('.blend-card .card-head').textContent(), '⚖️ שקלול השיטות', 'two methods → the blend (6.5) above the cards');
  const cols = await blocks(o).evaluateAll(els => els.map(e => getComputedStyle(e).borderInlineStartColor));
  eq(cols.length === 2 && cols[0] !== cols[1], true, `each method in its own colour (${cols.join(' / ')})`);
  const blend1 = await o.locator('.blend-card').textContent();
  for (const id of ['sample-ma-cross', 'elliott-w3']) {
    const hb = block(o, id).getByRole('button', { name: 'הסתר תרחיש' });
    if (await hb.count()) await hb.click();
  }
  await o.waitForTimeout(300);
  eq(await o.locator('.blend-card').textContent(), blend1, 'criterion 5: hiding the scenarios → the same direction score');
  for (const id of ['sample-ma-cross', 'elliott-w3']) {
    const sb = block(o, id).getByRole('button', { name: 'הצג תרחיש' });
    if (await sb.count()) await sb.click();
  }
  eq(await noHScroll(o), true, 'two methods: no horizontal scroll');
  await shot(o, 's4-two-light');

  {
  // ── קריטריון 6: "הסתר תרחיש" — הקו והאזור נעלמים, הממצא לא משתנה ──
  const s1 = block(o, 'elliott-w3');
  const st1 = (await state(o)).methods.find(m => m.id === 'elliott-w3');
  const find1 = await s1.locator('.an-sec.finding').textContent();
  const print1 = await canvasPrint(o);
  eq(st1.scenario > 0 && st1.finding > 0, true, `the method has a finding and a scenario on the chart (${st1.finding} / ${st1.scenario})`);
  await s1.getByRole('button', { name: 'הסתר תרחיש' }).click();
  await o.waitForTimeout(300);
  const st2 = (await state(o)).methods.find(m => m.id === 'elliott-w3');
  eq([st2.scenario, st2.finding, st2.hide], [0, st1.finding, true], 'criterion 6: hide scenario → no scenario items drawn, the same finding');
  eq(await s1.locator('.an-sec.finding').textContent(), find1, 'criterion 6: the finding text does not change');
  eq(await canvasPrint(o) !== print1, true, 'the scenario line and zone leave the canvas');
  await s1.getByRole('button', { name: 'הצג תרחיש' }).click();
  }

  // ── מתקפל: הסיכום נשאר, הגוף מוסתר; נשמר אחרי טעינה ──
  await block(o, 'elliott-w3').locator('.an-fold').click();
  eq([await block(o, 'elliott-w3').locator('.an-body').isHidden(), await block(o, 'elliott-w3').locator('.an-fold').getAttribute('aria-expanded')], [true, 'false'], 'a method folds: the body is hidden');
  eq(/לונג|שורט|אין כיוון/.test(await block(o, 'elliott-w3').locator('.an-fold').textContent()), true, 'the folded line keeps the direction in words');
  await o.reload();
  await loaded(o);
  await block(o, 'elliott-w3').locator('.an-fold').waitFor({ timeout: 60000 });
  eq(await block(o, 'elliott-w3').locator('.an-body').isHidden(), true, 'the fold survives a reload (localStorage)');
  await block(o, 'elliott-w3').locator('.an-fold').click();

  // ── כהה ──
  const { p: d } = await open('owner', { scheme: 'dark' });
  await d.goto(CHART);
  await loaded(d);
  await d.evaluate(() => localStorage.setItem('ts-forecast-methods', JSON.stringify([{ id: 'elliott-w3', color: '--brand' }, { id: 'sample-ma-cross', color: '--m2' }])));
  await d.reload();
  await loaded(d);
  await block(d, 'sample-ma-cross').locator('.an-sec.prob').waitFor({ timeout: 60000 });
  await settled(d);
  eq(await noHScroll(d), true, 'dark: no horizontal scroll');
  await shot(d, 's4-two-dark');

  // ── פרופיל (5) ──
  const { p: a } = await open('alice');
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
  // הפרופיל קובע את מסגרות הזמן שמוצעות בפס (היה במסך הניתוח): "ארוך" → יום ושבוע (מה שהספק נותן)
  await a.goto(CHART);
  await loaded(a);
  await a.evaluate(() => localStorage.setItem('ts-forecast-methods', JSON.stringify([{ id: 'elliott-w3', color: '--brand' }])));
  await a.reload();
  await loaded(a);
  await a.locator('.an-tfs button').first().waitFor({ timeout: 60000 });
  eq(await a.locator('.an-tfs button').allTextContents(), ['יום', 'שבוע'], 'the timeframes by the profile (long → day, week) in the panel');
  await a.locator('.an-tfs button', { hasText: 'יום' }).click();
  await a.waitForFunction(() => document.querySelector('fieldset[aria-label="מסגרת זמן"] input:checked')?.value === 'd1');
  eq(true, true, 'tapping a profile timeframe switches the chart');
} catch (e) {
  fail++; console.log('✗', e.stack);
} finally {
  await b.close();
  srv.kill();
}
eq(errors, [], 'no page errors');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
