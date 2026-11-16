// בדיקת ממשק 21.2 (spec-stage-a, ✅30) — המילוי ברקע בטלפון: בשורה של לשונית, נכס שמולא לפני 4 ימים — המחיר ו"לפני 4
// ימים" בקטן; נכס שלא מולא — "—". בספקי הנתונים, עם מפתח Twelve Data — "מילוי ברקע במפתח שלי — פעיל", ואפשר לכבות.
// node trading-strategies/tests/prefill-ui.mjs   (SHOTS=<dir> — צילומי מסך)
import { createRequire } from 'module';
import { spawnSync, spawn } from 'child_process';
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
const env = { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-prefill-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
  TS_HTTP_LOG: join(tmpdir(), `ts-prefill-ui-${RUN}.http.log`) };
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`], { env, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const SHOTS = process.env.SHOTS;
const NOW = 1794094200;   // 23:30 UTC
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const cron = (now, budget) => spawnSync('php', ['-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`, join(root, 'trading-strategies', 'bin', 'alerts-cron.php'),
  `--now=${now}`, ...Object.entries(budget).map(([k, v]) => `--budget=${k}:${v}`)], { env, encoding: 'utf8' });
const b = await chromium.launch();
async function open(as, dark = false) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: dark ? 'dark' : 'light' });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  await ctx.clock.setFixedTime(NOW * 1000);
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  return p;
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const row = (p, i) => p.locator('#mtab-panel .mrow').nth(i);
const crypto = async p => {
  await p.goto(BASE + '#/market');
  await p.locator('main [role="combobox"]').waitFor();
  await p.locator('main [role="tab"]', { hasText: '₿ קריפטו' }).click();
  await row(p, 0).waitFor();
};

try {
  // המילוי לפני 4 ימים — נכס אחד ב-Binance (חצי מתקציב של 2), הראשון בקטלוג: BTC
  const r = cron(NOW - 4 * 86400, { binance: 2, ecb: 0 });
  eq([r.status, JSON.parse(r.stdout).prefill.done], [0, ['binance:BTCUSDT']], 'the cron 4 days ago filled BTC');

  for (const dark of [false, true]) {
    const a = await open('alice', dark);
    await crypto(a);
    const w = dark ? 'dark' : 'light';
    eq(/^[\d,]+(\.\d+)?$/.test(await row(a, 0).locator('.mq .p').innerText()), true, `${w}: criterion 6 — BTC has a price from the store`);
    eq(await row(a, 0).locator('.mq-age').innerText(), 'לפני 4 ימים', `${w}: criterion 6 — and "לפני 4 ימים" in small`);
    eq([await row(a, 1).locator('.mq .p').innerText(), await row(a, 1).locator('.mq-age').count()], ['—', 0], `${w}: criterion 6 — ETH was not filled → "—", no age`);
    const sz = await row(a, 0).evaluate(el => [parseFloat(getComputedStyle(el.querySelector('.mq-age')).fontSize), parseFloat(getComputedStyle(el.querySelector('.mq .p')).fontSize)]);
    eq(sz[0] < sz[1], true, `${w}: the age is smaller than the price (${sz})`);
    const ratio = await row(a, 0).locator('.mq-age').evaluate(el => {
      const rgb = s => s.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
      const L = c => 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
      let bg = 'rgba(0, 0, 0, 0)', e = el;
      while (e && /rgba\(.*, 0\)|transparent/.test(bg)) { bg = getComputedStyle(e).backgroundColor; e = e.parentElement; }
      const [x, y] = [L(rgb(getComputedStyle(el).color)), L(rgb(bg))].sort((p, q) => q - p);
      return (x + 0.05) / (y + 0.05);
    });
    eq(ratio >= 4.5, true, `${w}: the age text is readable (contrast ${ratio.toFixed(2)} ≥ 4.5)`);
    eq(await noHScroll(a), true, `${w}: no horizontal scroll`);
    await shot(a, `prefill-row-${w}`);
    await a.context().close();
  }

  // ── ההגדרה: "מילוי ברקע במפתח שלי" ──
  const a = await open('alice');
  await a.goto(BASE + '#/providers');
  await a.locator('main .card').first().waitFor();
  eq(await post(a, 'cred_save', { provider: 'twelvedata', secret: 'good-Alice1234567' }), 200, 'alice connects a Twelve Data key');
  await a.reload();
  const sw = a.locator('main input[aria-label="מילוי ברקע במפתח שלי"]');
  await sw.waitFor();
  eq([await sw.count(), await sw.isChecked(), await a.locator('main .prefill-label').innerText()], [1, true, 'מילוי ברקע במפתח שלי — פעיל'],
    'the settings show "מילוי ברקע במפתח שלי — פעיל", only on the provider with a key');
  await shot(a, 'prefill-settings-on');
  await sw.click();
  await a.waitForFunction(() => document.querySelector('main .prefill-label')?.textContent.includes('כבוי'));
  await a.reload();
  await sw.waitFor();
  eq([await sw.isChecked(), await a.locator('main .prefill-label').innerText()], [false, 'מילוי ברקע במפתח שלי — כבוי'], 'criterion 5: turned off — and it stays off after a reload');
  eq(await noHScroll(a), true, 'settings: no horizontal scroll');
  await shot(a, 'prefill-settings-off');
  eq(errors, [], 'no console errors and no failed requests');
} catch (e) {
  fail++;
  console.log('✗ ' + e.message.split('\n')[0]);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
