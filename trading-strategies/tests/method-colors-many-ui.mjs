// יותר מ-8 שיטות — צבעי השיטות בדפדפן אמיתי (spec-stage-a 18.5, 2.20).
// node trading-strategies/tests/method-colors-many-ui.mjs   (Playwright; ספקים מדומים, בלי רשת)
//
// עשר שיטות זמינות למשתמש רגיל: אליוט + תשע שיטות בדיקה (tests/fixtures/methods/many.php, "בדיקה 1"…"בדיקה 9").
//  - בלי צבע פנוי: השיטה החדשה מקבלת את הצבע של השיטה הזמינה שלא סומנה הכי הרבה זמן, וזו יורדת מהמפה.
//  - שיטה שכובתה נשארת במפה, אבל הצבע שלה פנוי.
//  - עד 8 שיטות מסומנות יחד; אחרי 8 — שאר התיבות לא פעילות, והודעה "עד 8 שיטות יחד".
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-colors-many-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'many.php') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const NOW = 1794094200;
const E = 'elliott-w3';
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  return p;
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const profile = p => p.evaluate(async () => (await (await fetch('api.php?action=profile')).json()).profile.method_colors);
const colorsOnly = m => Object.fromEntries(Object.entries(m).map(([k, v]) => [k, v.color]).sort());
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const state = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast());
const pop = p => p.locator('.fc-pop');
async function openPop(p) {
  if (await pop(p).isHidden()) await p.getByRole('button', { name: 'תחזית שיטה' }).first().click();
  await pop(p).locator('.fc-opt').first().waitFor();
  await p.waitForFunction(() => document.querySelectorAll('.fc-pop .fc-opt').length >= 9);
}
const box = (p, name) => pop(p).locator('.fc-opt', { hasText: name }).locator('input');
/** מחכה שהפרופיל ישקף את מה שעל הגרף (השמירה אסינכרונית) */
const settle = p => p.waitForFunction(() => {
  const st = document.querySelector('.chart-box:not(.osc)').tsForecast();
  return st.methods.every(m => st.colors[m.id]?.color === m.color);
});
async function goChart(p) { await p.goto(CHART); await p.reload(); await loaded(p); }   // reload — שה-me של הדף יכלול את מה שנשמר

try {
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  const a = await open('alice');
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main [role="combobox"]');
  // שמונה צבעים שמורים, לשמונה שיטות זמינות שאינן על הגרף; "בדיקה 1" — לא סומנה הכי הרבה זמן (at 50)
  const seed = { [E]: { color: '--brand', at: 100 }, t1: { color: '--m2', at: 50 }, t2: { color: '--m3', at: 300 }, t3: { color: '--m4', at: 300 },
    t4: { color: '--m5', at: 300 }, t5: { color: '--m6', at: 300 }, t6: { color: '--m7', at: 300 }, t7: { color: '--m8', at: 300 } };
  eq(await post(a, 'method_colors_save', { colors: seed }), 200, 'seed: 8 saved colors, for 8 available methods');
  await goChart(a);
  await openPop(a);
  eq(await pop(a).locator('.fc-opt input').count(), 10, 'ten methods in the menu (Elliott + 9 test methods)');

  // ── בלי צבע פנוי: הצבע של השיטה שלא סומנה הכי הרבה זמן ──
  await box(a, 'בדיקה 8').check();
  await settle(a);
  eq((await state(a)).methods.map(m => [m.id, m.color]), [['t8', '--m2']], 'no free color → the color of the method not checked the longest ("בדיקה 1", at 50)');
  let pr = await profile(a);
  eq(['t1' in pr, pr.t8?.color, pr.t8?.at === NOW], [false, '--m2', true], '… "בדיקה 1" leaves the map; the new one is saved with the time it was checked');
  eq(Object.keys(pr).length, 8, '… still 8 entries — nothing else was touched');
  await box(a, 'בדיקה 8').uncheck();

  // ── שיטה שכובתה: נשארת במפה, והצבע שלה פנוי ──
  eq(await post(o, 'admin_method_save', { id: 't3', enabled: false, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the admin turns "בדיקה 3" off');
  await a.reload();
  await loaded(a);
  await openPop(a);
  eq(await pop(a).locator('.fc-opt', { hasText: 'בדיקה 3' }).count(), 0, '"בדיקה 3" is no longer in the menu');
  await box(a, 'בדיקה 1').check();
  await settle(a);
  eq((await state(a)).methods.map(m => [m.id, m.color]), [['t1', '--m4']], 'the color of a method that was turned off is free (not the longest-unchecked one)');
  pr = await profile(a);
  eq([pr.t3?.color, pr.t1?.color, pr[E]?.color], ['--m4', '--m4', '--brand'], '"בדיקה 3" stays in the map with its color; Elliott keeps its own');

  // ── עד 8 שיטות יחד ──
  eq(await pop(a).locator('.fc-max').count(), 0, 'under 8 — no limit message');
  for (const name of ['אליוט', 'בדיקה 2', 'בדיקה 4', 'בדיקה 5', 'בדיקה 6', 'בדיקה 7', 'בדיקה 8']) await box(a, name).check();
  await settle(a);
  const st = await state(a);
  eq(st.methods.length, 8, 'eight methods checked');
  eq(new Set(st.methods.map(m => m.color)).size, 8, 'never two checked methods in the same color');
  eq(await box(a, 'בדיקה 9').isDisabled(), true, 'after 8 — the other boxes are disabled');
  eq((await pop(a).locator('.fc-max').textContent()).trim(), 'עד 8 שיטות יחד', '… with the message "עד 8 שיטות יחד"');
  eq(await box(a, 'בדיקה 2').isDisabled(), false, 'a checked method can still be unchecked');
  await box(a, 'בדיקה 2').uncheck();
  eq([await box(a, 'בדיקה 9').isDisabled(), await pop(a).locator('.fc-max').count()], [false, 0], 'back to 7 — the boxes are active again, no message');
  await box(a, 'בדיקה 2').check();
  await settle(a);

  // ── השיטה שכובתה חוזרת: אותו צבע כמו שיטה שעל הגרף → זו שעל הגרף נשארת ──
  eq(await post(o, 'admin_method_save', { id: 't3', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the admin turns "בדיקה 3" back on');
  await a.reload();
  await loaded(a);
  await a.waitForFunction(() => !('t3' in (document.querySelector('.chart-box:not(.osc)').tsForecast().colors)));
  pr = await profile(a);
  eq([pr.t1?.color, 't3' in pr], ['--m4', false], 'two available methods in one color → the one on the chart keeps it; the other gets a color when checked');
  const colors = Object.values(pr).map(v => v.color);
  eq(colors.length, new Set(colors).size, 'no two available methods share a color in the profile');
  eq(errors.filter(e => !/favicon/.test(e)), [], 'no errors in the page');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
