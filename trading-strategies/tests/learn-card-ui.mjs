// L6 (spec-stage-a 23.7) בדפדפן: בגרף, בבלוק "הסתברות" — "ראשונית — מבדיקה לאחור (N = …)"; שורה שנייה "בפועל מאז: X% (N = 30)" רק
// מ-30 שורות live; "החלף לבפועל" במסך השיטות — לעורך ראשי בלבד, ואחריו המספר הראשי מ"בפועל מאז". בטלפון (390, 320 כהה) בלי גלילה לרוחב.
// node trading-strategies/tests/learn-card-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים
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
const DB = join(tmpdir(), `ts-learn-card-ui-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const NOW = 1794094200;
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
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; try { sessionStorage.setItem('ts-admin-tab', 'methods'); } catch { /* about:blank */ } });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  return p;
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const get = (p, action, q) => p.evaluate(async ([a, qs]) => (await fetch('api.php?action=' + a + '&' + new URLSearchParams(qs))).json(), [action, q]);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const part = async (loc, name) => { if (SHOTS) await loc.screenshot({ path: join(SHOTS, name + '.png') }); };
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
/** שורות live ישר למסד (ב-cron הן נכתבות ב-ts_learn_live_cron); ו-$sync — שלב "החלף לבפועל" של ה-cron */
const php = code => execFileSync('php', ['-r', `define('TS_DB_PATH', ${JSON.stringify(DB)}); define('TS_CACHE_PATH', ${JSON.stringify(DB + '.cache')});
  require ${JSON.stringify(join(here, '..', 'lib', 'tune.php'))}; ${code}`], { encoding: 'utf8' });
let T = 1_900_000_000;
const live = count => { const t0 = T; T += count * 3600; return php(`$i = ts_db()->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, cred_scope, t_signal, t_exit, side, result, win, r,
  market, month, quarter, weekday, hour, holidays, created_at) VALUES ('live', 'sample-ma-cross', 1, 'x', 'binance', 'binance:BTCUSDT', 'h1', '', ?, ?, 'long', 'loss', 0, -1, 'crypto', 1, 1, 1, 0, '[]', 0)");
  for ($k = 0; $k < ${count}; $k++) { $t = ${t0} + $k * 3600; $i->execute([$t, $t + 1800]); }`); };
const prob = p => p.locator('.forecast-panel .an-sec.prob');
async function chart(p) {
  await p.goto('about:blank');   // טעינה מלאה — לא רק החלפת hash
  await p.goto(CHART);
  await p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
  const btn = p.getByRole('button', { name: 'תחזית שיטה' });
  if (await btn.getAttribute('aria-pressed') !== 'true') {
    await btn.click();
    await p.locator('.fc-pop .fc-opt', { hasText: /חציית ממוצעים 20\/50/ }).locator('input').check();
    await p.locator('.quote-head').click();
  }
  await prob(p).locator('.summary').waitFor({ timeout: 30000 });
  return prob(p);
}

try {
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  eq(await post(o, 'admin_method_save', { id: 'sample-ma-cross', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the owner turns on the sample method');
  const an = await get(o, 'analysis', { asset: 'binance:BTCUSDT', tf: 'h1', methods: 'sample-ma-cross' });
  const bt = an.cards[0].backtest;
  eq([bt.status, bt.n >= 30], ['ok', true], 'a stored backtest for the chart (the chart itself never runs one, 18.3)');

  // ── קריטריון 1: רק backtest ──
  let pr = await chart(o);
  eq(await pr.locator('.basis-line').textContent(), `ראשונית — מבדיקה לאחור (N = ${bt.n.toLocaleString('he-IL')})`, 'criterion 1: "ראשונית — מבדיקה לאחור (N = …)"');
  eq(await pr.locator('.live-line').count(), 0, '… and no second line');

  // ── קריטריון 2: 29 → אין; 30 → "בפועל מאז: X% (N = 30)" ──
  live(29);
  pr = await chart(o);
  eq(await pr.locator('.live-line').count(), 0, 'criterion 2: 29 live rows → no second line');
  live(1);
  pr = await chart(o);
  eq((await pr.locator('.live-line').innerText()).replace(/\s+/g, ' ').trim(), 'בפועל מאז: 0% (N = 30)', 'criterion 2: 30 live rows → "בפועל מאז: 0% (N = 30)"');
  eq((await pr.locator('.line b.num').first().textContent()).trim(), bt.certainty + '%', 'criterion 3: the main number is still the backtest one');
  eq(await noHScroll(o), true, 'no horizontal scroll at 390');
  await shot(o, 'l6-card-live-390');
  await part(pr, 'l6-prob-live');

  // ── קריטריון 3: הצעה; עורך משני רואה ולא מחליף; העורך הראשי מחליף ──
  eq(JSON.parse(php('echo json_encode(ts_learn_switch_sync(' + NOW + '));')).proposed >= 1, true, 'the cron step proposes "החלף לבפועל" (the intervals do not overlap)');
  const c = await open('carol');
  await c.goto(BASE + '#/market');
  await c.waitForSelector('main [role="combobox"]');
  const carolId = (await get(o, 'admin_state', {})).users.find(u => u.display_name === 'קרול')?.id;
  eq(await post(o, 'admin_role_add', { user_id: carolId }), 200, 'carol — a secondary editor');
  const row = p => p.locator('main .list > li', { hasText: 'חציית ממוצעים' });
  const props = async p => {
    await p.goto(BASE + '#/admin');
    await row(p).waitFor({ timeout: 30000 });
    const t = row(p).locator('details.tune');
    if (!(await t.evaluate(d => d.open))) await t.locator('summary').click();
    return t.locator('.cert-prop', { hasText: 'לפלח:' });   // שוק × מסגרת (יש גם הצעה כללית — כל השווקים)
  };
  let cp = await props(c);
  eq([(await cp.locator('.l1 span').first().textContent()).trim(), await cp.getByRole('button', { name: 'החלף לבפועל' }).isDisabled(), await cp.locator('.tune-only').count()],
    ['ודאות: ראשונית → בפועל מאז', true, 1], 'a secondary editor sees the proposal; "החלף לבפועל" is disabled, "only a chief editor"');
  eq(await cp.locator('thead th').allTextContents(), ['', 'בדיקה לאחור', 'בפועל מאז'], 'the proposal compares the backtest with live');
  eq(await cp.locator('tbody th').allTextContents(), ['עסקאות', 'הצלחה', 'רווח סמך 95%'], '… N, success and the 95% interval');
  eq(await noHScroll(c), true, 'no horizontal scroll (the proposal, 390)');
  await shot(c, 'l6-proposal-carol-390');
  await part(cp, 'l6-proposal-carol');
  cp = await props(o);
  await cp.getByRole('button', { name: 'החלף לבפועל' }).click();
  const dlg = o.locator('dialog[open]');
  await dlg.waitFor();
  eq(/הגרסה לא עולה/.test(await dlg.textContent()), true, 'the confirmation: the version does not go up');
  await dlg.getByRole('button', { name: 'החלף' }).click();
  await o.waitForSelector('text=הוחלף לבפועל');
  eq(await (await props(o)).getAttribute('data-status'), 'applied', 'the proposal is marked applied');
  pr = await chart(o);
  eq((await pr.locator('.line b.num').first().textContent()).trim(), '0%', 'after the switch: the main number is "בפועל מאז"');
  eq([await pr.locator('.live-line .chip.ok').textContent(), await pr.locator('.basis-line').textContent()],
    ['המספר הראשי', `ראשונית — מבדיקה לאחור: ${bt.certainty}% (N = ${bt.n.toLocaleString('he-IL')})`], '… and the backtest number stays, as a line of its own');
  await shot(o, 'l6-card-switched-390');
  await part(pr, 'l6-prob-switched');

  const narrow = await open('alice', { width: 320, scheme: 'dark' });
  await narrow.goto(BASE + '#/market');
  await narrow.waitForSelector('main [role="combobox"]');
  const ac = await get(narrow, 'analysis', { asset: 'binance:BTCUSDT', tf: 'h1', methods: 'elliott-w3' });
  eq(ac.cards[0].certainty_basis, 'backtest', 'another method is untouched (23.1)');
  await narrow.goto(BASE + '#/admin');
  eq(await narrow.locator('.cert-prop').count(), 0, 'a regular user has no proposals screen');
  const nc = await open('carol', { width: 320, scheme: 'dark' });
  await props(nc);
  eq(await noHScroll(nc), true, 'no horizontal scroll at 320 (dark)');
  await shot(nc, 'l6-proposal-carol-320-dark');
  eq(errors.filter(e => !/favicon|status of 4\d\d/.test(e)), [], 'no errors in the page');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
