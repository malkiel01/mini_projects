// H1 (spec-stage-a 18.1.8) בדפדפן: אליוט על BTC/USDT בשעה, בלי ספירה נוכחית — "∅" במקרא, ולידו "N אותות בעבר בגרף"; אותות העבר
// על הגרף — עמומים (50%), בצבע השיטה, לא קדימה מהנר האחרון; לחיצה על חץ → "אות לונג · היה" עם התוצאה ו"על הנרות שבגרף בלבד".
// המתג "היסטוריה" בתפריט — כבוי: 0 פריטי עבר, ונשמר אחרי טעינה. קריטריונים 1, 7, 9; טלפון 390 ו-320 כהה.
// node trading-strategies/tests/past-signals-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים
import { createRequire } from 'module';
import { spawn, execFileSync } from 'child_process';
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
const DB = join(tmpdir(), `ts-past-signals-ui-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'), TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const NOW = 1794342600;   // אליוט בלי ספירה נוכחית בסט הגלים (כמו ב-past-signals-api.php)
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as, { width = 390, scheme = 'light' } = {}) {
  const ctx = await b.newContext({ viewport: { width, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  return p;
}
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png') }); };
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const st = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast());
const toScreen = (p, q) => p.evaluate(q => { const r = document.querySelector('.chart-box:not(.osc)').getBoundingClientRect(); return [r.left + q.x, r.top + q.y]; }, q);
async function chart(p) {
  await p.goto(CHART);
  await loaded(p);
  const btn = p.getByRole('button', { name: 'תחזית שיטה' });
  if (await btn.getAttribute('aria-pressed') !== 'true') {
    await btn.click();
    await p.locator('.fc-pop .fc-opt', { hasText: /אליוט/ }).locator('input').check();
    await p.locator('.quote-head').click();
  }
  await p.waitForFunction(() => (document.querySelector('.chart-box:not(.osc)').tsForecast().pastN || {})['elliott-w3'] != null, null, { timeout: 30000 });
  await p.waitForTimeout(300);
}

try {
  const a = await open('alice');
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main [role="combobox"]');
  await chart(a);

  // ── קריטריון 1: ∅ בהווה, ואותות עבר בגרף ──
  const leg = a.locator('.fc-legend .fc-leg[data-method="elliott-w3"]');
  let s = await st(a);
  const N = s.pastN['elliott-w3'];
  eq([await leg.locator('.fc-leg-none').count(), N >= 1], [1, true], `criterion 1: Elliott — "∅" in the legend (no forecast now), and ${N} past signals`);
  eq((await leg.locator('.fc-leg-past').textContent()).trim(), `${N.toLocaleString('he-IL')} אותות בעבר בגרף`, '… and next to it: "N אותות בעבר בגרף"');
  const past = s.past.filter(x => x.m === 'elliott-w3');
  eq(past.length >= 1, true, `past signals drawn on the chart (${past.length} in view)`);

  // ── קריטריון 9: עמום (50%), ואף פריט עבר קדימה מהנר האחרון ──
  const lastT = await a.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsHistory().first !== null && document.querySelector('.chart-box:not(.osc)').tsTimes().to);
  eq([...new Set(past.map(x => x.alpha))], [0.5], 'criterion 9: every past item at 50% opacity');
  eq(past.every(x => x.t <= lastT), true, '… none ahead of the last candle (no projection forward)');
  eq(past.filter(x => x.label).every(x => /^(✓ \+|✗ −|⏱ [+−])\d+(\.\d)?R$|^… פתוח$/.test(x.label)), true, `the short result next to each arrow (${past.filter(x => x.label).slice(0, 3).map(x => x.label).join(' | ')})`);
  eq(await noHScroll(a), true, 'no horizontal scroll at 390');
  await shot(a, 'h1a-past-390');

  // ── החלונית: לחיצה על חץ → "אות … · היה" ──
  const W = await a.evaluate(() => document.querySelector('.chart-box:not(.osc)').clientWidth);
  const target = past.filter(x => x.x > 40 && x.x < W - 60).sort((x, y) => y.t - x.t)[0];
  const [sx, sy] = await toScreen(a, target);
  await a.touchscreen.tap(sx, sy);
  await a.locator('.ctip').waitFor({ timeout: 5000 });
  const title = (await a.locator('.ctip-title').textContent()).trim();
  eq(title, `אות ${target.side === 'long' ? 'לונג' : 'שורט'} · היה`, `criterion 9: the tip — "${title}"`);
  eq((await a.locator('.ctip .ctip-row .ctip-tag').textContent()).trim().endsWith('· היה'), true, '… tagged "היה" (not "ממצא")');
  const txt = await a.locator('.ctip').innerText();
  eq([/כניסה .* · סטופ /.test(txt), /על הנרות שבגרף בלבד — הבדיקה לאחור המלאה בכרטיס/.test(txt), /הכלל: /.test(txt)], [true, true, true],
    'the tip: entry and stop, the rule of the method, and "על הנרות שבגרף בלבד"');
  eq(target.result === 'open' ? /פתוח/.test(txt) : /R\u2069? · /.test(txt), true, '… and the result in R with the exit time (or "פתוח")');
  await shot(a, 'h1a-tip-390');
  await a.keyboard.press('Escape');

  // ── קריטריון 7: המתג — כבוי: 0 פריטי עבר, ההווה בלי שינוי; נשמר ──
  const nowBefore = (await st(a)).methods.map(m => [m.id, m.items]);
  await a.getByRole('button', { name: 'תחזית שיטה' }).click();
  const sw = a.locator('.fc-pop input[name="fc-history"]');
  eq(await sw.isChecked(), true, 'the "היסטוריה" switch in the methods menu — on by default');
  await sw.uncheck();
  await a.waitForTimeout(300);
  s = await st(a);
  eq([s.history, s.past.length, await leg.locator('.fc-leg-past').count()], [false, 0, 0], 'criterion 7: off → 0 past items on the chart, and no "אותות בעבר" next to ∅');
  eq(s.methods.map(m => [m.id, m.items]), nowBefore, '… the present (finding, scenario) unchanged');
  await a.locator('.quote-head').click();
  await a.reload();
  await chart(a);
  eq([(await st(a)).history, (await st(a)).past.length], [false, 0], 'criterion 7: still off after leaving and coming back (the profile)');
  await a.getByRole('button', { name: 'תחזית שיטה' }).click();
  await a.locator('.fc-pop input[name="fc-history"]').check();
  await a.waitForTimeout(400);
  eq((await st(a)).past.length >= 1, true, 'on again → the past signals are back');
  await a.locator('.quote-head').click();

  // ── קריטריון 8: תקרה — שיטת הדוגמה על שנה (5,000 נרות בחלון של הגרף): "מוצגים 50 האחרונים מתוך N" ──
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  eq(await o.evaluate(async () => { const me = await (await fetch('api.php?action=me')).json();
    return (await fetch('api.php?action=admin_method_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf },
      body: JSON.stringify({ id: 'sample-ma-cross', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }) })).status; }), 200, 'the owner turns on the sample method');
  await o.goto(CHART);
  await loaded(o);
  await o.locator('select[name="range"]').selectOption('y');
  await loaded(o);
  await o.getByRole('button', { name: 'תחזית שיטה' }).click();
  await o.locator('.fc-pop .fc-opt', { hasText: /חציית ממוצעים/ }).locator('input').check();
  await o.waitForFunction(() => (document.querySelector('.chart-box:not(.osc)').tsForecast().pastN || {})['sample-ma-cross'] != null, null, { timeout: 60000 });
  await o.locator('.quote-head').click();
  await o.getByRole('button', { name: 'תחזית שיטה' }).click();
  const total = (await st(o)).pastN['sample-ma-cross'];
  eq(total > 50, true, `the sample method over a year — ${total} past signals in the chart's window`);
  eq((await o.locator('.fc-pop .fc-hist-cap[data-method="sample-ma-cross"]').textContent()).trim(), `חציית ממוצעים 20/50: מוצגים 50 האחרונים מתוך ${total.toLocaleString('he-IL')}`,
    'criterion 8: "מוצגים 50 האחרונים מתוך N" under the switch');
  eq(await o.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast().past.filter(x => x.m === 'sample-ma-cross').length <= 50), true, '… and no more than 50 drawn');
  eq(/\bnull\b/.test(await o.locator('.fc-pop').innerText()), false, 'the menu has no stray "null" (replaceChildren with a null child)');
  await shot(o, 'h1a-cap-390');
  await o.locator('.quote-head').click();

  const nb = await open('bob', { width: 320, scheme: 'dark' });
  await nb.goto(BASE + '#/market');
  await nb.waitForSelector('main [role="combobox"]');
  await chart(nb);
  eq([(await st(nb)).history, (await st(nb)).past.length >= 1, await noHScroll(nb)], [true, true, true], '320, dark: on by default for another user, past signals, no horizontal scroll');
  await shot(nb, 'h1a-past-320-dark');
  eq(errors, [], 'no errors in the page');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
