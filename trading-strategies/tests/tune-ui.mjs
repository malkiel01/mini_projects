// שכלול שיטה בדפדפן אמיתי (spec-stage-a 18.7, S7): הצעת שינוי פרמטר במסך ניהול השיטות — ביצועים בתוך הדגימה ומחוצה לה,
// "החל" לא פעיל אצל מנהל שאינו הראשי (קריטריון 5) ופעיל אצל הראשי, וההחלה מעלה את הגרסה (קריטריון 6).
// node trading-strategies/tests/tune-ui.mjs   (Playwright; ספקים מדומים, בלי רשת)
// SHOTS=<תיקייה מחוץ לריפו> — צילומים ב-390 וב-320, בבהיר ובכהה.
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-tune-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
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
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; sessionStorage.setItem('ts-admin-tab', 'methods'); });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  return p;
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const noHScroll = p => p.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
const row = p => p.locator('main .list > li', { hasText: 'חציית ממוצעים' });
async function methods(p) {
  await p.goto(BASE + '#/admin');
  await row(p).waitFor({ timeout: 30000 });
  const t = row(p).locator('details.tune');
  if (!(await t.evaluate(d => d.open))) await t.locator('summary').click();
  return t;
}

try {
  const o = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');
  const c = await open('carol');
  await c.goto(BASE + '#/market');
  await c.waitForSelector('main [role="combobox"]');
  const carolId = await o.evaluate(async () => (await (await fetch('api.php?action=admin_state')).json()).users.find(u => u.display_name === 'קרול')?.id);
  eq(await post(o, 'admin_role_add', { user_id: carolId }), 200, 'carol becomes an admin (not the primary one)');

  // ── מנהל שאינו הראשי: מציע ערך מועמד ──
  let t = await methods(c);
  eq(await row(c).locator('input[name="fast"]').isDisabled(), true, '✅24: carol cannot edit a parameter directly');
  eq(await t.locator('select[name="tune-param"] option').count(), 6, 'the proposal lists the method parameters');
  await t.locator('select[name="tune-param"]').selectOption('fast');
  await t.locator('input[name="tune-value"]').fill('10');
  await t.locator('select[name="tune-tf"]').selectOption('h4');
  await t.getByRole('button', { name: 'חשב והצע' }).click();
  await c.waitForSelector('text=ההצעה נשמרה', { timeout: 120000 });
  t = await methods(c);
  const prop = t.locator('.tune-prop').first();
  await prop.waitFor();
  eq((await prop.locator('.l1 span').first().textContent()).trim(), 'ממוצע קצר: 20 → 10', 'the proposal: current → candidate');
  eq(await prop.locator('.tune-tbl tbody').evaluateAll(rs => rs.map(r => r.dataset.part)), ['in', 'out'], 'criterion 3: in the sample and out of it — separate parts');
  eq(await prop.locator('.tune-tbl thead th').evaluateAll(hs => hs.map(x => x.textContent)), ['', 'נוכחי', 'מועמד'], '… each with the current value and the candidate');
  eq(await prop.locator('tbody[data-part="out"] tr').evaluateAll(rs => rs.slice(1).map(r => r.querySelector('th').textContent)), ['עסקאות', 'הצלחה', 'תוחלת', 'ירידה מרבית'], 'the out-of-sample part: trades, success, expectancy, drawdown');
  eq(Number(await prop.locator('tbody[data-part="out"] tr').nth(1).locator('td').first().textContent()) > 0, true, '… with trades out of the sample');
  eq(await t.locator('input[name="tune-value"]').getAttribute('placeholder'), 'עכשיו 20 · 2–200', 'the candidate field shows the current value and the range');
  const apply = prop.getByRole('button', { name: 'החל' });
  eq(await apply.isDisabled(), true, 'criterion 5: carol — "החל" is disabled');
  eq((await prop.locator('.tune-only').textContent()).trim(), 'רק המנהל הראשי מחיל', '… with the reason');
  eq(await noHScroll(c), true, 'no horizontal scroll at 390');
  await shot(c, 's7-carol-390');

  // ── המנהל הראשי: "החל" פעיל; ההחלה מעלה את הגרסה ──
  t = await methods(o);
  eq((await row(o).locator('.l1 .t').first().textContent()).trim(), 'גרסה 1', 'before: version 1');
  const oApply = t.locator('.tune-prop').first().getByRole('button', { name: 'החל' });
  eq(await oApply.isDisabled(), false, 'criterion 5: the primary admin — "החל" is active');
  eq(await t.locator('.tune-only').count(), 0, '… with no "only the primary admin" note');
  await shot(o, 's7-owner-390');
  await oApply.click();
  const dlg = o.locator('dialog[open]');
  await dlg.waitFor();
  eq(/מ-1 ל-2/.test(await dlg.textContent()), true, 'the confirmation says the version goes from 1 to 2');
  await dlg.getByRole('button', { name: 'החל' }).click();
  await o.waitForSelector('text=הוחל — גרסה חדשה');
  await row(o).locator('.l1 .t', { hasText: 'גרסה 2' }).waitFor();
  eq((await row(o).locator('.l1 .t').first().textContent()).trim(), 'גרסה 2', 'criterion 6: after "החל" — version 2');
  eq(await row(o).locator('input[name="fast"]').inputValue(), '10', 'the parameter now holds the applied value');
  t = await methods(o);
  eq(await t.locator('.tune-prop').first().getAttribute('data-status'), 'applied', 'the proposal is marked applied');

  // ── טלפון צר ובכהה ──
  const narrow = await open('carol', { width: 320, scheme: 'dark' });
  await methods(narrow);
  eq(await noHScroll(narrow), true, 'no horizontal scroll at 320 (dark)');
  await shot(narrow, 's7-carol-320-dark');
  eq(errors.filter(e => !/favicon|status of 4\d\d/.test(e)), [], 'no errors in the page');
} catch (e) {
  fail++; console.log('✗ exception:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
