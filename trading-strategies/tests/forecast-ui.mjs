// "🔮 תחזית שיטה" בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 17.2.2, ✅15, ✅13; PR 4ב/2): הפאנל, הניסוח, הציור על
// הקנבס (לא HTML), ובקשה אחת לגרף ולשיטה (chart_refresh, 18.3). הרענון האוטומטי עצמו — tests/refresh-ui.mjs. node trading-strategies/tests/forecast-ui.mjs (Playwright; ספקים מדומים, בלי רשת)
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-forecast-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
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
  await ctx.clock.setFixedTime(NOW * 1000);   // ואותו "עכשיו" בדפדפן — אחרת הטווח שהדפדפן מחשב מתחיל אחרי ה"עכשיו" של השרת (422)
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
  // ── המנהל מפעיל את שיטת הדוגמה ──
  const { p: o, reqs } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');   // המסך נטען — ה-me של הדף חזר (אחרת post() פותח session שני והדף נשאר עם csrf ישן)
  eq(await post(o, 'admin_method_save', { id: 'sample-ma-cross', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the admin turns on the sample method');
  await o.goto(CHART);
  await loaded(o);
  const before = await canvasPrint(o);
  const btn = o.getByRole('button', { name: 'תחזית שיטה' });
  eq(await btn.getAttribute('aria-pressed'), 'false', 'the 🔮 button in the chart header, off');

  // ── בחירת שיטה: תפריט נפתח מעל הגרף (F1, 18.1.2) ──
  await btn.click();
  const pop = o.locator('.fc-pop');
  await pop.locator('.fc-opt').first().waitFor();
  eq([await o.locator('dialog[open]').count(), await btn.getAttribute('aria-expanded')], [0, 'true'], 'the 🔮 menu — a popover, not a sheet');
  eq(/לא ייעוץ, בלי אחוז ודאות/.test(await pop.textContent()), true, 'the menu says: not advice, no certainty percentage');
  reqs.length = 0;
  await pop.locator('.fc-opt', { hasText: /חציית ממוצעים 20\/50/ }).locator('input').check();
  const panel = o.locator('.forecast-panel');
  await panel.locator('.chips').waitFor({ timeout: 30000 });
  eq([reqs.filter(a => a === 'chart_refresh').length, reqs.includes('method_overlay')], [1, false], 'one request (chart_refresh: candles + the method) after the user chose a method');
  // S4 (18.4): הכותרת — שם השיטה; שלוש כותרות: ממצא · תרחיש · הסתברות; "תרחיש לפי שיטת X — לא ייעוץ" בתוך התרחיש
  eq((await panel.locator('h3').textContent()).trim(), 'חציית ממוצעים 20/50', 'the block title is the method name (S4)');
  eq(await panel.locator('.an-sec > h4').allTextContents(), ['ממצא', 'תרחיש', 'הסתברות'], 'three separate headings: finding, scenario, probability (18.4 criterion 2)');
  const scen = panel.locator('.an-sec.scenario');
  eq(/תרחיש לפי שיטת חציית ממוצעים 20\/50 — לא ייעוץ/.test(await scen.textContent()), true, 'the scenario says "תרחיש לפי שיטת X — לא ייעוץ" (6.6)');
  const chips = await panel.locator('.chip').allTextContents();
  eq([chips.includes('לפי כללי השיטה, לא נבדק לאחור'), chips.includes('🧪 שיטת דוגמה — לא מהספר')], [true, true], 'the scenario marked "by the method rules, not backtested", and the sample label');
  eq(/אינו ייעוץ השקעות/.test(await panel.locator('.disclaimer').textContent()), true, 'the fixed disclaimer');
  eq(/\d\s*%/.test(await scen.textContent()), false, 'no percentage in the scenario (the scenario has no certainty, ✅26)');
  eq(await btn.getAttribute('aria-pressed'), 'true', 'the 🔮 button shows the mode is on');
  await o.locator('.quote-head').click();   // לחיצה מחוץ לתפריט — נסגר
  eq([await pop.isHidden(), await btn.getAttribute('aria-expanded')], [true, 'false'], 'a click outside the menu closes it');
  const sc = panel.locator('.forecast-scenarios button');
  const nsc = await sc.count();
  eq(nsc >= 1 && await sc.first().getAttribute('aria-pressed') === 'true', true, `scenarios listed (${nsc}), the leading one pressed`);
  await o.waitForTimeout(400);
  const after = await canvasPrint(o);
  eq(after !== before, true, 'the scenario is drawn on the chart canvas');
  const body = await o.evaluate(() => document.body.innerText);
  eq(/סטופ לפי כללי השיטה/.test(body), false, 'the method texts on the chart are canvas, not HTML');
  eq(await noHScroll(o), true, 'no horizontal scroll on a phone');
  eq(await o.evaluate(() => document.documentElement.dir), 'rtl', 'RTL');
  await shot(o, 'forecast-on');

  // ── הדמיית המשך (בקשת הבעלים): נרות-רפאים מהנר האחרון, ארוכים מהתרחיש, בעוצמה לפי הוודאות, עם תווית קבועה ──
  await panel.locator('.an-sec.prob').waitFor();
  await o.waitForFunction(() => !/מריץ/.test(document.querySelector('.an-sec.prob')?.textContent || ''), null, { timeout: 30000 });
  const st0 = await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast());
  const sim = st0.sim.find(x => x.m === 'sample-ma-cross');
  const sec = 3600;
  eq([!!sim, sim?.label, sim?.bars >= 8], [true, true, true], `a continuation is drawn (${sim?.bars} ghost candles), with its label`);
  eq(sim && sim.end >= sim.scenEnd + Math.floor((sim.scenEnd - sim.start + sec) / sec * 0.5) * sec - sec, true,
    'the continuation is longer than the scenario — another 50% of its length after its end');
  const labels = await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsLabels().map(r => r.text));
  eq(labels.includes('הדמיית המשך — לא תחזית'), true, 'the fixed label "הדמיית המשך — לא תחזית" is on the chart');
  const al = await o.evaluate(async () => { const m = await import('./assets/js/forecast.js'); return [m.simAlpha(0), m.simAlpha(50), m.simAlpha(90), m.simAlpha(100), m.simAlpha(null)]; });
  eq(al, [0.15, 0.45, 0.69, 0.75, 0.28], 'strength by certainty: 15% at 0, up to 75% at 100; not backtested — faint (28%)');
  // הוודאות של הכרטיס (בפס, "ודאות N%") — אותה ודאות קובעת את העוצמה
  const pc = /ודאות\s*(\d+)%/.exec(await panel.locator('.an-sec.prob').textContent())?.[1];
  const simNow = (await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast().sim)).find(x => x.m === 'sample-ma-cross');
  eq([simNow?.certainty ?? null, simNow?.alpha], pc ? [+pc, al[0] + 0.6 * +pc / 100 >= 0 ? +(0.15 + 0.6 * +pc / 100).toFixed(3) : null] : [null, 0.28],
    `the strength follows the card's certainty (${pc ?? 'none'}%) → ${simNow?.alpha}`);
  eq(st0.methods[0].items, (await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast().methods[0].items)), 'the continuation does not change the finding or the scenario');
  await shot(o, 'forecast-sim');
  // "הסתר תרחיש" — גם ההמשך נעלם; "הצג תרחיש" — חוזר
  await panel.getByRole('button', { name: 'הסתר תרחיש' }).click();
  await o.waitForTimeout(200);
  eq((await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast().sim)).length, 0, '"הסתר תרחיש" hides the continuation too');
  await panel.getByRole('button', { name: 'הצג תרחיש' }).click();
  await o.waitForTimeout(200);
  eq((await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast().sim)).length, 1, '"הצג תרחיש" brings it back');

  // ── שינוי טווח: הנרות והתרחיש באותה בקשה (18.3) ──
  reqs.length = 0;
  // שבוע: השעון של הדפדפן והשרת קבועים לאותו NOW (open), כך שהטווח לא תלוי בתאריך שבו הבדיקה רצה
  await o.locator('select[aria-label="טווח"]').selectOption('w');
  await loaded(o);
  await panel.locator('.chips').waitFor();
  eq([reqs.filter(a => a === 'chart_refresh').length, reqs.includes('method_overlay')], [1, false], 'a range change → one request for the candles and the scenario together');
  reqs.length = 0;
  await o.getByRole('button', { name: 'רענן' }).click();
  await loaded(o);
  await panel.locator('.chips').waitFor();
  eq(reqs.filter(a => a === 'chart_refresh').length, 1, '"↻" refreshes the candles and the forecast (one request)');

  // ── הסתרה ──
  await panel.getByRole('button', { name: 'הסרה', exact: true }).click();
  eq([await panel.isHidden(), await btn.getAttribute('aria-pressed')], [true, 'false'], 'hide → panel gone, button off');
  await o.waitForTimeout(300);
  eq(await canvasPrint(o) !== after, true, 'hide → the drawing is removed from the canvas');

  // ── משתמש רגיל: שיטת הדוגמה לא מוצעת לו (גם כשהיא מופעלת), שיטה מהספר — כן ──
  const { p: a } = await open('alice');
  await a.goto(CHART);
  await loaded(a);
  await a.getByRole('button', { name: 'תחזית שיטה' }).click();
  await a.locator('.fc-pop .fc-opt').first().waitFor();
  const names = await a.locator('.fc-pop .fc-opt').allTextContents();
  eq([names.some(t => /חציית ממוצעים/.test(t)), names.some(t => /אליוט/.test(t))], [false, true], 'a regular user is not offered the sample method; the book method is (18.1.2)');
  await shot(a, 'forecast-none');
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
