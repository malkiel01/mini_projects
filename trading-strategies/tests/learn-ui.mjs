// למידה, L3 / L5b, בדפדפן אמיתי (spec-stage-a 23.10): הלשונית "📚 למידה" בכרטיס השיטה — N לפי מקור וגרסה, השכנים וההיסטוגרמות, טבלת פלחים עם
// מינימום N (פלח קטן — "אין מספיק נתונים (N)", אפור, בלי אחוז), מקור אחר — טבלה אחרת, וקישור ל-CSV. 390 ו-320, בלי גלילה לרוחב.
// node trading-strategies/tests/learn-ui.mjs   (Playwright; בלי רשת. השורות נכתבות ישירות למסד — סט ידוע)
// SHOTS=<תיקייה מחוץ לריפו> — צילומים ב-390 וב-320, בבהיר ובכהה.
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
const DB = join(tmpdir(), `ts-learn-ui-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as, { width = 390, scheme = 'light' } = {}) {
  const ctx = await b.newContext({ viewport: { width, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; sessionStorage.setItem('ts-admin-tab', 'methods'); });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  return p;
}
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const noHScroll = p => p.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
const row = p => p.locator('main .list > li', { hasText: 'חציית ממוצעים' });
async function learn(p) {
  await p.goto(BASE + '#/admin');
  await row(p).waitFor({ timeout: 30000 });
  const d = row(p).locator('details.learn');
  await d.locator('summary').click();
  await d.locator('.learn-tbl').waitFor({ timeout: 30000 });
  return d;
}

try {
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  // הסט: 30 עסקאות ביום שישי (15 הצלחות), 29 ביום רביעי; ו-40 live
  execFileSync('php', ['-r', `
    $db = new PDO('sqlite:' . $argv[1]);
    $ins = $db->prepare("INSERT INTO method_outcomes (source, method_id, method_version, params_hash, provider, asset, tf, t_signal, t_exit, side, result, win, r,
      market, month, quarter, weekday, hour, holidays, created_at, entry, stop, atr, mae_r, mfe_r) VALUES (?, 'sample-ma-cross', 1, 'h', 'binance', 'binance:BTCUSDT', 'h1', ?, ?, 'long', ?, ?, ?, 'crypto', 3, 1, ?, 10, '[]', 0, '100', '98', '1', 0.4, 1.2)");
    $k = 0;
    $add = function ($src, $wd, $win) use ($ins, &$k) { $k++; $ins->execute([$src, 1700000000 + $k * 3600, 1700007200 + $k * 3600, $win ? 'win' : 'loss', $win ? 1 : 0, $win ? 2.0 : -1.0, $wd]); };
    for ($i = 0; $i < 30; $i++) $add('backtest', 5, $i < 15);
    for ($i = 0; $i < 29; $i++) $add('backtest', 3, $i < 10);
    for ($i = 0; $i < 40; $i++) $add('live', 2, true);
  `, DB]);

  let d = await learn(o);
  eq(await d.locator('.learn-counts tbody tr').evaluateAll(rs => rs.map(r => [...r.cells].map(c => c.textContent))),
    [['1', 'בדיקה לאחור', '59'], ['1', 'בפועל מאז', '40']], 'N by version and source');
  const dayRow = v => d.locator('tbody[data-dim="weekday"] tr', { hasText: v });
  const fri = await dayRow('שישי').evaluate(r => [r.dataset.enough, [...r.cells].map(c => c.textContent)]);
  eq([fri[0], fri[1].slice(0, 4), /^\d+%$/.test(fri[1][4] || '')], ['true', ['שישי', '30', '50%', '0.5R'], true], 'a segment of 30 — win rate, expectancy and certainty');
  eq(await dayRow('רביעי').evaluate(r => [r.dataset.enough, [...r.cells].map(c => c.textContent)]), ['false', ['רביעי', '29', 'אין מספיק נתונים (29)']],
    'a segment of 29 — "not enough data (29)", no percentage');
  eq(await dayRow('רביעי').locator('td').last().evaluate(e => getComputedStyle(e).color) !== await dayRow('שישי').locator('td').last().evaluate(e => getComputedStyle(e).color),
    true, '… and grey');
  eq([await d.locator('h3.learn-h').first().textContent(), await d.locator('.learn-none').count()], ['שינויי דפוס (p < 0.01)', 1],
    'L4: the pattern-changes section — here, without findings (50% vs 34% in 30/29 — not significant)');
  eq(await d.locator('.learn-values li').evaluateAll(ls => ls.map(l => [l.dataset.param, l.dataset.segment, l.dataset.status])),
    [['stop_atr', '', 'not_enough'], ['target_atr', '', 'not_enough'], ['stop_atr', 'crypto|h1', 'not_enough'], ['target_atr', 'crypto|h1', 'not_enough']],
    'L5b: where to place — each learnable parameter × segment; 59 trades → fewer than 30 out of the sample');
  eq(await d.locator('.learn-values li').first().locator('.hint').textContent(), 'נוכחי 2 · שכנים 1.5 / 2 / 2.5 · טווח 0.5–10 · 41 בתוך הדגימה, 18 מחוצה לה (N ≥ 30 בכל חלק)',
    '… the current, the neighbors (one step each way), and N in each part');
  eq(await d.getByRole('button', { name: /חשב הצעות/ }).count(), 0, 'L5b: no "compute" button — the comparison runs only in the cron');
  eq([await d.locator('.learn-hist').count(), await d.locator('.learn-cut').textContent().then(t => t.startsWith('חתוך בסטופ וביעד הנוכחיים'))], [2, true],
    'criterion 7: MAE / MFE histograms, "cut at the current stop and target" — for discussion only');
  eq(await d.locator('tbody[data-dim]').evaluateAll(bs => bs.map(x => x.dataset.dim)), ['total', 'market', 'tf', 'market_tf', 'month', 'quarter', 'weekday', 'hour', 'holidays'],
    'the total, then every tag, and market × tf');
  const href = await d.getByRole('link', { name: /CSV/ }).getAttribute('href');
  eq(href.includes('action=admin_learn_csv') && href.includes('method=sample-ma-cross') && href.includes('source=backtest'), true, 'the CSV link — of this method and source');
  eq(await d.evaluate(e => e.textContent.includes('null')), false, 'no stray "null" text (an empty part)');
  eq(await noHScroll(o), true, '390: no horizontal scroll');
  await shot(o, 'learn-390-light');

  // מקור אחר — טבלה אחרת (לא מערבבים)
  await d.locator('select[name="learn-source"]').selectOption('live');
  await d.locator('tbody[data-dim="total"] tr', { hasText: '40' }).waitFor();
  eq(await d.locator('tbody[data-dim="total"] tr').evaluate(r => [...r.cells].map(c => c.textContent).slice(0, 3)), ['הכול', '40', '100%'], 'live — its own table');

  // 320, כהה
  const o2 = await open('owner', { width: 320, scheme: 'dark' });
  d = await learn(o2);
  eq(await noHScroll(o2), true, '320 dark: no horizontal scroll');
  await shot(o2, 'learn-320-dark');

  // משתמש — אין לו את המסך (24.2); הבדיקה של השרת — learn-stats-api.php
  eq(errors, [], 'no JavaScript errors');
} catch (e) {
  fail++; console.log('✗ exception: ' + e.message);
} finally {
  await b.close();
  srv.kill();
  for (const f of [DB, DB + '-wal', DB + '-shm', DB + '.cache', DB + '.cache-wal', DB + '.cache-shm', DB + '.key']) { try { (await import('fs')).unlinkSync(f); } catch { /* ריק */ } }
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
