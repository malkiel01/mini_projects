// F-1 — פיבונאצ'י בגרף, בדפדפן אמיתי (spec-stage-a 22.9, קריטריונים 9 ו-10).
// node trading-strategies/tests/fibonacci-ui.mjs   (Playwright; ספקים מדומים, בלי רשת, שעון קבוע)
// SHOTS=<תיקייה מחוץ לריפו> — צילומים (deviceScaleFactor 2) ברוחב 390.
//
// נתוני TS_KLINES_SHAPE=wave, 70 שעות לפני ה-NOW של שאר הבדיקות — שם יש בשעה אזור חפיפה ורמות בודדות (tests/fibonacci-api.php).
// "מה צויר ובאיזה צבע" — מ-box.tsForecast().drawn: faint — רמה בודדת דהויה; extend — נמשך עד קצה הגרף.
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
const RUN = `${process.pid}-${Date.now().toString(36)}`;   // שם קובץ בלבד
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-fib-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 100; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const SHOTS = process.env.SHOTS;
const NOW = 1794094200 - 70 * 3600;
const F = 'fibonacci';
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as, { methods = null, scheme = 'light' } = {}) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(([m]) => {
    window.WebSocket = class { constructor() {} close() {} };
    if (m && !sessionStorage.getItem('fib-seeded')) {
      localStorage.setItem('ts-forecast-methods', JSON.stringify(m));
      sessionStorage.setItem('ts-chart:binance:BTCUSDT', JSON.stringify({ tf: 'h1' }));
      sessionStorage.setItem('fib-seeded', '1');
    }
  }, [methods]);
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  return { p, ctx };
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png') }); };
const state = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast());
const pop = p => p.locator('.fc-pop');
async function openPop(p) {
  if (await pop(p).isHidden()) await p.getByRole('button', { name: 'תחזית שיטה' }).first().click();
  await pop(p).locator('.fc-opt, .fc-none').first().waitFor();
}
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const mine = st => st.drawn.filter(d => d.m === F);

try {
  // ── קריטריון 10: כבויה — לא בתפריט; המנהל מפעיל — בתפריט ──
  const { p: o } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  const { p: a, ctx: actx } = await open('alice');
  await a.goto(CHART);
  await loaded(a);
  await openPop(a);
  eq(await pop(a).locator('.fc-opt', { hasText: "פיבונאצ'י" }).count(), 0, 'criterion 10: off → not in the 🔮 menu');
  eq(await post(o, 'admin_method_save', { id: F, enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4'] }), 200, 'the admin turns Fibonacci on');
  await actx.close();

  // משתמש שסימן את השיטה (המכשיר זוכר) — הגרף נפתח איתה
  const { p: u } = await open('alice', { methods: [{ id: F, color: '--m3' }] });
  await u.goto(CHART);
  await loaded(u);
  await openPop(u);
  eq(await pop(u).locator('.fc-opt', { hasText: "פיבונאצ'י" }).count(), 1, 'criterion 10: on → in the 🔮 menu');
  await u.keyboard.press('Escape');
  const panel = u.locator(`.forecast-panel[data-method="${F}"]`);
  await panel.locator('.an-sec.prob').waitFor({ timeout: 60000 });
  await u.waitForFunction(id => (document.querySelector('.chart-box:not(.osc)').tsForecast().drawn || []).some(d => d.m === id && d.k === 'zone'), F, { timeout: 60000 });
  eq(/לא נבדק לאחור|ודאות מוצגת רק מ-30|מריץ בדיקה לאחור/.test(await panel.locator('.an-sec.prob').textContent()), true, 'criterion 10: the card — "not backtested" (no certainty under N = 30)');

  // ── קריטריון 9 ──
  const purple = await u.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--m3').trim());
  let st = await state(u);
  const zones = mine(st).filter(d => d.k === 'zone');
  const singles = mine(st).filter(d => d.faint);
  eq([zones.length > 0, [...new Set(zones.map(d => d.c))], zones.every(d => d.extend)], [true, [purple], true], 'criterion 9: zones in the method color (S5), drawn to the chart edge (R3)');
  eq([singles.length > 0, [...new Set(singles.map(d => d.c))], singles.every(d => d.k === 'polyline' && d.extend)], [true, [purple], true], 'criterion 9: single levels — faded lines in the method color, to the edge');
  eq(mine(st).filter(d => d.k === 'polyline' && !d.faint).length, 0, 'no other lines: F-1 has no scenario');
  await shot(u, 'fib-chart');

  const solo = panel.locator('.fc-solo');
  eq([await solo.textContent(), await solo.getAttribute('aria-pressed')], ['הסתר רמות בודדות', 'false'], 'the "hide single levels" button');
  await solo.click();
  await u.waitForFunction(id => !(document.querySelector('.chart-box:not(.osc)').tsForecast().drawn || []).some(d => d.m === id && d.faint), F);
  st = await state(u);
  eq([mine(st).filter(d => d.faint).length, mine(st).filter(d => d.k === 'zone').length, st.methods.find(m => m.id === F).solo], [0, zones.length, true],
    'criterion 9: "hide single levels" hides them — the zones stay');
  eq(await panel.locator('.fc-solo').getAttribute('aria-pressed'), 'true', 'the button is pressed');
  await shot(u, 'fib-chart-no-singles');
  await u.reload();
  await u.locator(`.forecast-panel[data-method="${F}"] .an-sec.prob`).waitFor({ timeout: 60000 });
  await u.waitForFunction(id => (document.querySelector('.chart-box:not(.osc)').tsForecast().drawn || []).some(d => d.m === id && d.k === 'zone'), F, { timeout: 60000 });
  st = await state(u);
  eq([mine(st).filter(d => d.faint).length, st.methods.find(m => m.id === F).solo], [0, true], 'the choice survives a reload (localStorage)');
  await u.locator(`.forecast-panel[data-method="${F}"] .fc-solo`).click();
  await u.waitForFunction(id => (document.querySelector('.chart-box:not(.osc)').tsForecast().drawn || []).some(d => d.m === id && d.faint), F);
  eq(mine(await state(u)).filter(d => d.faint).length, singles.length, 'show again → the same single levels');
} catch (e) {
  fail++;
  console.log('✗ exception: ' + (e.stack || e.message));
} finally {
  await b.close();
  srv.kill();
}
const bad = errors.filter(e => !/favicon/.test(e));
eq(bad, [], 'no page errors, no failed requests');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
