// ציר הזמן בגרף לפי שעון המשתמש (בקרה 8, 2026-11-16): BTC, שעה — הבעלים בישראל ראה "02:00" ו-"14:00" וחשב שהגרף נתקע ב-14:00.
// עכשיו: הסימונים בשעות עגולות **מקומיות**, ותווית שעה לנר האחרון בקצה הימני. Asia/Jerusalem מול UTC; טלפון 390, בלי גלילה לרוחב.
// node trading-strategies/tests/chart-axis-tz-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים
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
const DB = join(tmpdir(), `ts-chart-axis-tz-ui-${RUN}.sqlite`);
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
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  return p;
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png') }); };
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const axis = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsAxis());
const local = (t, tz) => new Date(t * 1000).toLocaleString('he-IL', { timeZone: tz, hour: '2-digit', minute: '2-digit' });

async function chartAs(as, tz, range) {
  const p = await open(as);
  await p.goto(BASE + '#/market');
  await p.waitForSelector('main [role="combobox"]');
  eq(await post(p, 'settings', { base_currency: 'USD', timezone: tz, account_size: '', default_risk_pct: '1' }), 200, `${as}: timezone ${tz}`);
  await p.goto(CHART);
  await p.reload();   // me עם אזור הזמן החדש
  await loaded(p);
  eq(await p.evaluate(() => fetch('api.php?action=me').then(r => r.json()).then(j => j.user.timezone)), tz, `${as}: me.user.timezone`);
  await p.locator('select[name="range"]').selectOption(range);
  await loaded(p);
  await p.waitForTimeout(300);
  return p;
}

try {
  // ── ישראל, שעה, יום אחד: סימוני שעה — 00 / 06 / 12 / 18 מקומי (לא 02 / 08 / 14 / 20) ──
  const il = await chartAs('alice', 'Asia/Jerusalem', 'd');
  let ax = await axis(il);
  eq(ax.time.length >= 2 && ax.time.every(s => /^(00|06|12|18):00$/.test(s)), true, `Israel, h1, one day: the marks on local round hours (${ax.time.join(' | ')})`);
  eq(ax.time.some(s => /^(02|08|14|20):00$/.test(s)), false, '… none on the UTC boundaries (02 / 08 / 14 / 20 Israel time)');
  const open = NOW - (NOW % 3600);   // הנר של השעה הנוכחית (פתוח) — האחרון בגרף
  eq(ax.last, local(open, 'Asia/Jerusalem'), `the last candle at the right edge has its own time label (${ax.last})`);
  eq(ax.time.includes(ax.last), false, '… and no other mark repeats or overlaps it');
  eq(await noHScroll(il), true, 'no horizontal scroll at 390');
  await shot(il, 'axis-tz-israel-day');
  // שלושה חודשים (מה שהבעלים ראה): תאריכים / חודשים — ותווית שעה לנר האחרון
  await il.locator('select[name="range"]').selectOption('q');
  await loaded(il);
  await il.waitForTimeout(300);
  ax = await axis(il);
  eq([ax.time.length >= 2, ax.last], [true, local(open, 'Asia/Jerusalem')], `3 months: marks (${ax.time.join(' | ')}), and the last candle's time at the right edge`);
  await shot(il, 'axis-tz-israel-3m');

  // ── UTC — כמו היום ──
  const utc = await chartAs('bob', 'UTC', 'd');
  ax = await axis(utc);
  eq(ax.time.length >= 2 && ax.time.every(s => /^(00|06|12|18):00$/.test(s)), true, `UTC: the same round hours (${ax.time.join(' | ')})`);
  eq(ax.last, local(open, 'UTC'), 'UTC: the last candle label in UTC');
  eq(errors, [], 'no errors in the page');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
