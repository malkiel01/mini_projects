// "התראות" בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 1, 8; פרק 15: טלפון, RTL, בלי polling). ספקים מדומים, בלי רשת.
// node trading-strategies/tests/alerts-ui.mjs   (Playwright)   SHOTS=<תיקייה> — צילומי מסך
import { createRequire } from 'module';
import { spawn, spawnSync } from 'child_process';
import { tmpdir } from 'os';
import { createServer } from 'net';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
// פורט פנוי מהמערכת — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת של ריצה אחרת (#1333)
const port = await new Promise(r => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const RUN = `${process.pid}-${Math.random().toString(16).slice(2, 10)}`;
const DB = join(tmpdir(), `ts-alerts-ui-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'alertable.php') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  return p;
}
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);

try {
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  for (const id of ['test-cross-a', 'test-cross-b']) await post(o, 'admin_method_save', { id, enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4'] });

  const a = await open('alice');
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main [role="combobox"]');
  await post(a, 'watchlist_add', { asset: 'binance:BTCUSDT' });
  await a.goto(BASE + '#/alerts');
  await a.waitForSelector('main .empty');
  eq(await a.locator('nav.bottom-nav a').count(), 4, 'four tabs: שוק · התראות · יומן · עוד — "ניתוח" is under the chart (S4)');
  eq(await a.locator('nav.bottom-nav a[aria-current="page"]').getAttribute('data-tab'), 'alerts', 'the "התראות" tab is current');
  eq((await a.locator('main .disclaimer').textContent()).includes('אינו ייעוץ השקעות'), true, 'disclaimer on the alerts screen');

  // התראת מחיר מהמסך
  await a.getByRole('tab', { name: /ההתראות שלי/ }).click();
  await a.getByRole('button', { name: '+ התראה' }).click();
  await a.locator('dialog input[name="level"]').fill('115');
  await a.locator('dialog fieldset[aria-label="כיוון"] label', { hasText: 'לונג' }).click();
  await a.locator('dialog').getByRole('button', { name: 'שמירה' }).click();
  await a.waitForSelector('main .card');
  const card = a.locator('main .card').first();
  eq([(await card.locator('.pair-main').innerText()).replace(/\s+/g, ' ').trim(), await card.locator('.pair-main svg.vs').count(), /BTCUSDT|מול/.test(await card.innerText())],
    ['BTC USDT', 1, false], 'the new price alert is listed — BTC ⇄ USDT with the icon, not the joined symbol or "מול" (18.1.1)');
  eq((await a.locator('main .card .chip').first().textContent()), 'פעילה', 'active');
  eq(await noHScroll(a), true, 'alerts: no horizontal scroll on a phone');
  eq(await a.evaluate(() => getComputedStyle(document.querySelector('main .card')).direction), 'rtl', 'RTL');
  await shot(a, 'alerts-mine');
  await a.getByRole('button', { name: '⏸ השהיה' }).click();
  await a.waitForSelector('text=מושהית');

  // מכרטיס ניתוח — "התראה מזה"
  // S4 (18.4): הכרטיס בפס שמתחת לגרף — שיטה אחת מסומנת, מסגרת h4
  await a.goto(BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT'));
  await a.waitForSelector('main .chart-box');
  await a.evaluate(([m, t]) => { localStorage.setItem('ts-forecast-methods', JSON.stringify([{ id: m, color: '--brand' }]));
    sessionStorage.setItem('ts-chart:binance:BTCUSDT', JSON.stringify({ tf: t })); }, ['test-cross-b', 'h4']);
  await a.reload();
  // הבדיקה לאחור רצה מעצמה (פעולה של המשתמש — פתיחת הגרף), והכרטיס מתחלף בתוצאה שלה
  await a.waitForFunction(() => { const s = document.querySelector('.analysis-card .an-sec.prob');
    return s && !/מריץ בדיקה לאחור/.test(s.textContent) && /ודאות|לא נבדק לאחור/.test(s.textContent) && document.querySelector('.analysis-card .verdict'); }, null, { timeout: 90000 });
  await a.getByRole('button', { name: '🔔 התראה מזה' }).first().waitFor({ timeout: 30000 });
  eq(await a.getByRole('button', { name: '🔔 התראה מזה' }).count() >= 1, true, '"🔔 התראה מזה" in the panel under the chart, for a method allowed in alerts');
  await a.getByRole('button', { name: '🔔 התראה מזה' }).last().click();
  await a.waitForSelector('dialog select[name="method"]');
  eq(await a.locator('dialog fieldset[aria-label="תנאי"] input:checked').getAttribute('value'), 'method', 'the sheet opens on "method", prefilled');
  await a.locator('dialog').getByRole('button', { name: 'שמירה' }).click();
  await a.waitForURL(/#\/alerts/);
  await a.waitForSelector('main .card');
  eq(await a.locator('main .card').count(), 2, 'the method alert is saved and listed');
  await shot(a, 'alerts-mine-2');

  // בלי polling — 20 שניות בלי פעולה במסך ההתראות
  const seen = [];
  const onReq = r => { if (r.url().includes('/api.php')) seen.push(r.url()); };
  a.on('request', onReq);
  await a.waitForTimeout(20000);
  a.off('request', onReq);
  eq(seen.length, 0, 'alerts: no requests to our server in 20 s idle');

  // התיבה — הודעה שה-cron כתב (כאן ישר למסד), התג בלשונית, וסימון כנקרא
  spawnSync('php', ['-r', `$db = new PDO('sqlite:${DB}'); $u = $db->query("SELECT id FROM users WHERE platform_sub = '101'")->fetchColumn();
    $db->prepare('INSERT INTO alert_events (alert_id, user_id, fired_at, payload, delivered) VALUES (NULL, ?, ?, ?, ?)')->execute([$u, time(),
    json_encode(['title' => 'BTCUSDT חצה את 115', 'body' => 'המחיר חצה מעלה את הרמה 115. ניתוח אוטומטי לפי כללי שיטות — אינו ייעוץ השקעות.', 'kind' => 'price', 'link' => '#/chart/binance%3ABTCUSDT'], JSON_UNESCAPED_UNICODE), '{"inbox":true}']);`]);
  await a.evaluate(() => sessionStorage.setItem('ts-alerts-tab', 'inbox'));
  await a.goto(BASE + '#/market');
  await a.reload();
  await a.waitForSelector('main [role="combobox"]');
  eq(await a.locator('nav.bottom-nav a[data-tab="alerts"] .badge').textContent(), '1', 'unread badge on the tab (from me — no polling)');
  await a.goto(BASE + '#/alerts');
  await a.waitForSelector('main .use-row.unread');
  eq((await a.locator('main .use-row.unread').textContent()).includes('BTCUSDT חצה את 115'), true, 'the message in the inbox, marked unread');
  eq(await noHScroll(a), true, 'inbox: no horizontal scroll');
  await shot(a, 'alerts-inbox');
  await a.getByRole('button', { name: '✓ סימון הכול כנקרא' }).click();
  await a.waitForFunction(() => !document.querySelector('main .use-row.unread'));
  eq(await a.locator('nav.bottom-nav a[data-tab="alerts"] .badge').isHidden(), true, 'after "mark all read" — no badge');
} catch (e) {
  fail++; console.log('✗', e.message);
} finally {
  await b.close();
  srv.kill();
}
eq(errors, [], 'no page errors');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
