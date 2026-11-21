// מסך "יומן השיטה" (spec-stage-a 18.6, S6, קריטריונים 2, 4, 5) בדפדפן אמיתי ברוחב טלפון, בהיר וכהה. בלי רשת.
// הרשומות נוצרות מהגרף (chart_refresh), והתוצאה — מה-cron (CLI). השרת עצמו — tests/method-journal-api.php.
// node trading-strategies/tests/method-journal-ui.mjs   (Playwright)   SHOTS=<תיקייה> — צילומי מסך
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
const DB = join(tmpdir(), `ts-method-journal-ui-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'alertable.php'), TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const NOW = +(process.env.TS_NOW || 1794094200);
const b = await chromium.launch();
async function open(as, { width = 390, dark = false } = {}) {
  const ctx = await b.newContext({ viewport: { width, height: 860 }, colorScheme: dark ? 'dark' : 'light', deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  // "עכשיו" קבוע לשרת (X-Dev-Now, רק ב-TS_DEV): הנרות המדומים תלויים בזמן, ובלי זה "יש רמות / אין רמות" תלוי בשעה
  // שבה הבדיקה רצה. כמו בבדיקות ה-API
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
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


const env = { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
  TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'alertable.php'), TS_KLINES_SHAPE: 'wave' };
const cron = now => JSON.parse(spawnSync('php', ['-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`, join(root, 'trading-strategies', 'bin', 'alerts-cron.php'), `--now=${now}`],
  { env, encoding: 'utf8' }).stdout.trim() || '{}');
const get = (p, action, q, now = NOW) => p.evaluate(async ([a, qq, t]) => (await fetch('api.php?' + new URLSearchParams({ action: a, ...qq }), { headers: { 'X-Dev-Now': String(t) } })).json(), [action, q, now]);
const rows = p => p.$$eval('main .mj-row', els => els.map(e => ({ asset: e.dataset.asset, out: e.querySelector('.mj-out')?.dataset.outcome ?? null, text: e.querySelector('.mj-out')?.textContent ?? null, l1: e.querySelector('.l1').textContent })));
const noNull = p => p.evaluate(() => !/\bnull\b|undefined/.test(document.querySelector('main').textContent));
const waitList = p => p.waitForSelector('main .mj-list');

try {
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  for (const id of ['elliott-w3', 'test-cross-a']) await post(o, 'admin_method_save', { id, enabled: true, markets: ['crypto', 'stocks'], timeframes: ['h1'] });
  const q = { asset: 'binance:BTCUSDT', tf: 'h1', from: String(NOW - 30 * 86400), to: String(NOW), methods: 'elliott-w3' };
  const r = await get(o, 'chart_refresh', q);
  eq(r.methods?.['elliott-w3']?.status, 'ok', 'Elliott runs on the chart (a record is written)');
  for (let i = 0; i < 3; i++) await get(o, 'chart_refresh', q);

  // ── קריטריון 5: המסך למנהל — סיכום לפי גרסה + רשימה ──
  await o.goto(BASE + '#/method-journal');
  await waitList(o);
  eq(await o.locator('#title').textContent(), 'יומן השיטה', 'the screen title');
  const sum = await o.$$eval('main .mj-sum tbody tr', els => els.map(e => [e.dataset.method, e.dataset.version, e.querySelector('.total').textContent, e.querySelector('.pending').textContent]));
  eq(sum, [['elliott-w3', '1', '1', '1']], 'criterion 5: a summary line per method and version — 1 record, not known yet (3 more refreshes did not add records)');
  let rs = await rows(o);
  eq([rs.length, rs[0]?.out, rs[0]?.text], [1, 'pending', 'עוד לא ידוע'], 'criterion 2: the record says "not known yet" before its time');
  eq([/BTC/.test(rs[0].l1), /BTCUSDT/.test(rs[0].l1)], [true, false], 'the pair by its label, not the joined symbol (18.1.1)');
  eq(await o.locator('main .mj-row .mj-scen').count(), 1, 'what was said: the zone, the invalidation and until when');
  eq(await noHScroll(o), true, 'no horizontal scroll (390)');
  await shot(o, 'method-journal-pending');

  // ── קריטריון 2: ה-cron ממלא את התוצאה ──
  const rec = (await get(o, 'method_journal', {})).rows[0];
  const c = cron(rec.until_t + 3600 + 60);
  eq(c.journal?.resolved, 1, 'the cron decides the record after its range');
  await o.reload();
  await waitList(o);
  rs = await rows(o);
  eq(['zone', 'invalidated', 'neither'].includes(rs[0]?.out), true, 'criterion 2: after the cron — the outcome (' + rs[0]?.text + ')');
  const s2 = await o.$$eval('main .mj-sum tbody tr', els => els.map(e => ['zone', 'invalidated', 'neither', 'pending'].map(k => e.querySelector('.' + k).firstChild.textContent)));
  eq(s2[0].reduce((a, x) => a + +x, 0) === 1 && s2[0][3] === '0', true, 'criterion 5: the summary counts it under its outcome');
  await shot(o, 'method-journal-decided');

  // סינון לפי תוצאה
  await o.locator('main select.mj-outcome').selectOption('pending');
  await o.waitForSelector('main .mj-list .empty');
  eq(await o.locator('main .mj-row').count(), 0, 'filter "not known yet" → nothing (the only record is decided)');
  await o.locator('main select.mj-outcome').selectOption('');
  await o.waitForSelector('main .mj-row');

  // הלשונית במסך הניהול — אותו יומן
  await o.evaluate(() => sessionStorage.setItem('ts-admin-tab', 'journal'));
  await o.goto(BASE + '#/admin');
  await o.waitForSelector('main .mj-list');
  eq(await o.locator('main .mj-row').count(), 1, 'the admin screen has a "📓 יומן השיטה" tab with the same journal');
  eq(await noHScroll(o), true, 'admin: no horizontal scroll with the extra tab');

  // ── קריטריון 4: רשומה במפתח אישי של אליס — לא אצל בוב (21.3: רק רשומה ישנה, ב-u:N) ──
  const a = await open('alice');
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main [role="combobox"]');
  eq(await post(a, 'cred_save', { provider: 'twelvedata', secret: 'good-Alice1234567' }), 200, 'alice connects her own key');
  const ra = await get(a, 'chart_refresh', { asset: 'twelvedata:AAPL', tf: 'h1', from: String(NOW - 10 * 86400), to: String(NOW), methods: 'test-cross-a' });
  eq(ra.methods?.['test-cross-a']?.status, 'ok', 'alice analyses AAPL with her key');
  await a.goto(BASE + '#/more');
  await a.locator('main a[href="#/method-journal"]').click();
  await waitList(a);
  eq((await rows(a)).some(x => x.asset === 'twelvedata:AAPL'), true, 'criterion 4: alice sees her AAPL record (from "עוד")');
  const bb = await open('bob', { width: 320, dark: true });
  await bb.goto(BASE + '#/market');
  await bb.waitForSelector('main [role="combobox"]');
  // 21.3 (✅31): Twelve Data — מאגר משותף. רשומה חדשה — של כולם
  eq((await get(bb, 'method_journal', {})).rows.some(x => x.asset === 'twelvedata:AAPL'), true, '21.3: a new Twelve Data record is shared — bob sees it');
  // רשומה ישנה ב-u:N (מלפני 21.3) נשארת רק של בעליה: קריטריון 4 של 18.6 נבדק עליה
  const upd = spawnSync('php', ['-r', `$d = new PDO('sqlite:' . $argv[1]); $d->exec("UPDATE method_journal SET cred_scope = 'u:' || (SELECT id FROM users WHERE platform_sub = '101') WHERE asset = 'twelvedata:AAPL'");`, DB], { encoding: 'utf8' });
  eq(upd.status, 0, 'the record is made a legacy personal one (u:<alice>)');
  await bb.goto(BASE + '#/method-journal');
  await waitList(bb);
  const rb = await rows(bb);
  eq([rb.some(x => x.asset === 'twelvedata:AAPL'), rb.some(x => x.asset === 'binance:BTCUSDT')], [false, true], 'criterion 4: bob does not see it — only the shared one');
  eq(await bb.locator('main .mj-sum tbody tr[data-method="test-cross-a"]').count(), 0, 'and it is not in his summary');
  eq(await noHScroll(bb), true, 'no horizontal scroll (320, dark)');
  eq(await bb.evaluate(() => [...document.querySelectorAll('main .tbl-wrap, main .mj-tools')].every(e => e.scrollWidth <= e.clientWidth + 1)), true, '320: the summary table and the filters fit without scrolling');
  eq(await noNull(bb), true, 'no "null" / "undefined" on the screen');
  eq(await bb.evaluate(() => getComputedStyle(document.body).backgroundColor !== 'rgb(255, 255, 255)'), true, 'dark mode follows the system');
  await shot(bb, 'method-journal-bob-320-dark');
  // השורה לא נשברת: הכותרת והמשנה נחתכות ב-…
  eq(await bb.$$eval('main .mj-row .l1', els => els.every(e => e.getBoundingClientRect().height < 30)), true, 'the row title stays on one line at 320');
} catch (e) {
  fail++; console.log('✗', e.message);
} finally {
  await b.close();
  srv.kill();
}
eq(errors, [], 'no page errors');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
