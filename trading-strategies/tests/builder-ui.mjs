// בונה השיטות בדפדפן אמיתי, ברוחב טלפון (spec-stage-a 17.2, ✅19; PR 4ב/3): ➕ שיטה מוגדרת, "✔ בדיקה", "🧪 נסה",
// JSON לא תקין, שמירה (כבויה, "מוגדרת ידנית"), עריכה → גרסה 2, גרסאות. node trading-strategies/tests/builder-ui.mjs
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-builder-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
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
  const { p: o, reqs } = await open('owner');
  await o.goto(BASE + '#/admin');
  await o.getByRole('tab', { name: '🧪 שיטות' }).click();
  await o.getByRole('button', { name: '➕ שיטה מוגדרת' }).click();
  const dlg = o.locator('dialog[open]');
  await dlg.waitFor();
  eq((await dlg.locator('h2').textContent()).trim(), '🧩 שיטה מוגדרת חדשה', 'the builder sheet opens');
  const ta = dlg.locator('textarea.code-edit');
  eq(JSON.parse(await ta.inputValue()).id, 'rsi-levels', 'it starts from a working template');
  eq(await ta.getAttribute('dir'), 'ltr', 'the JSON editor is LTR inside the RTL page');
  // ✔ בדיקה
  await dlg.getByRole('button', { name: '✔ בדיקה' }).click();
  await dlg.locator('.builder-out .ok').waitFor();
  eq(/✔ תקין — RSI עם רמות \(rsi-levels\)/.test(await dlg.locator('.builder-out').textContent()), true, '"✔ בדיקה" → valid, from the server');
  // 🧪 נסה — לא שומר
  reqs.length = 0;
  await dlg.getByRole('button', { name: '🧪 נסה' }).click();
  await dlg.locator('.builder-out', { hasText: '🧪' }).waitFor({ timeout: 60000 });
  const tryText = await dlg.locator('.builder-out').textContent();
  eq([/אותות ב-/.test(tryText), /ניסיון בלבד — לא נשמר/.test(tryText), reqs.filter(a => a === 'admin_method_dry_run').length], [true, true, 1], '"🧪 נסה" → signals and conditions on recent candles, marked "not saved"');
  // JSON לא תקין
  const good = await ta.inputValue();
  await ta.fill(good.replace('"rsi crosses_above $lo"', '"system(1) > 0"'));
  await dlg.getByRole('button', { name: '✔ בדיקה' }).click();
  await dlg.locator('.builder-out .error').waitFor();
  eq(/קריאה לפונקציה/.test(await dlg.locator('.builder-out .error').textContent()), true, 'a function call in a rule → rejected with a Hebrew message');
  await ta.fill('{ not json');
  await dlg.getByRole('button', { name: '✔ בדיקה' }).click();
  await o.waitForFunction(() => /JSON לא תקין/.test(document.querySelector('dialog[open] .builder-out')?.textContent || ''));
  eq(true, true, 'broken JSON → "JSON לא תקין", in the browser');
  await ta.fill(good);
  eq(await dlg.locator('.builder-out').textContent(), '', 'editing the text clears the old result (no stale error next to fixed text)');
  eq(await noHScroll(o), true, 'no horizontal scroll on a phone (sheet open)');
  await shot(o, 'builder-sheet');
  await dlg.getByRole('button', { name: 'שמירה' }).click();
  await o.waitForFunction(() => !document.querySelector('dialog[open]'));
  const card = o.locator('main li.card', { hasText: 'RSI עם רמות' });
  await card.waitFor();
  const ct = await card.textContent();
  eq([/🧩 מוגדרת/.test(ct), /מוגדרת ידנית/.test(ct), /גרסה 1/.test(ct), await card.locator('.switch input').isChecked()], [true, true, true, false], 'saved: listed as 🧩 defined, "מוגדרת ידנית", version 1, OFF');
  // עריכה → גרסה 2
  await card.getByRole('button', { name: '✏️ עריכת ההגדרה' }).click();
  const d2 = o.locator('dialog[open]');
  await d2.waitFor();
  await d2.locator('textarea.code-edit').fill((await d2.locator('textarea.code-edit').inputValue()).replace('"horizon_bars": 30', '"horizon_bars": 40'));
  await d2.getByRole('button', { name: 'שמירה' }).click();
  await o.waitForFunction(() => !document.querySelector('dialog[open]'));
  await o.locator('main li.card', { hasText: 'RSI עם רמות' }).filter({ hasText: 'גרסה 2' }).waitFor();
  await o.locator('main li.card', { hasText: 'RSI עם רמות' }).getByRole('button', { name: '🕘 גרסאות' }).click();
  const d3 = o.locator('dialog[open]');
  await d3.waitFor();
  eq((await d3.locator('li.card .l1 span:first-child').allTextContents()), ['גרסה 2', 'גרסה 1'], 'versions: 2 then 1');
  await shot(o, 'builder-versions');
} catch (e) {
  fail++;
  console.log('✗ ' + e.stack);
} finally {
  eq(errors.filter(x => !/422/.test(x)), [], 'no page errors (except the expected 422 of the rejected definition)');
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
