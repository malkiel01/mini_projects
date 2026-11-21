// "אין תחזית" במקרא שעל הגרף (בקשת הבעלים, דרך בקרה 9: "בתוך הכפתור שעל המפה תוסיף אייקון שאין תחזית").
// node trading-strategies/tests/legend-none-ui.mjs   (Playwright, טלפון 390×844; ספקים מדומים, בלי רשת)
//   - אליוט עם ספירה ותרחיש → אין אייקון.
//   - אותה שיטה, תשובה בלי ממצא ובלי תרחיש (התשובה של chart_refresh מנותבת ומרוקנת) → "∅" בכפתור, title ו-aria-label.
//     עם overlay.note — ה-title הוא ההערה.
//   - מוסתרת (off) → בלי אייקון. ריענון עם תרחיש → האייקון נעלם.
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
import { writeFileSync } from 'fs';
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-legend-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const NOW = +(process.env.TS_NOW || 1794094200);
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
await ctx.addCookies([{ name: 'dev_as', value: 'alice', url: BASE }]);
await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
await ctx.clock.setFixedTime(NOW * 1000);
await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
const p = await ctx.newPage();
p.on('pageerror', e => errors.push(e.message));
p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
const leg = () => p.locator('.fc-leg[data-method="elliott-w3"]');
const icon = () => leg().locator('.fc-leg-none');
let strip = null;   // null — התשובה כמו שהיא; אחרת — note לשים בתשובה הריקה ('' — בלי note)
await p.route(/api\.php\?action=chart_refresh/, async route => {
  if (strip === null) return route.continue();
  const res = await route.fetch();
  const j = await res.json();
  const m = j.methods?.['elliott-w3'];
  if (m) {
    if (m.overlay) { m.overlay.items = []; if (strip) m.overlay.note = strip; else delete m.overlay.note; }
    m.forecast = m.forecast ? { ...m.forecast, items: [], scenarios: [] } : m.forecast;
  }
  await route.fulfill({ response: res, json: j });
});
const refresh = async () => {
  const before = await p.evaluate(() => performance.now());
  const wait = p.waitForResponse(r => r.url().includes('action=chart_refresh'));
  await p.getByRole('button', { name: 'רענן' }).first().click();
  await wait;
  await p.waitForTimeout(400);
  return before;
};

try {
  await p.goto(CHART);
  await p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
  await p.getByRole('button', { name: 'תחזית שיטה' }).first().click();
  await p.locator('.fc-pop .fc-opt', { hasText: 'אליוט' }).locator('input').check();
  await p.locator('.forecast-panel .chips').waitFor({ timeout: 60000 });
  await p.keyboard.press('Escape');
  await leg().waitFor();

  // ── עם ספירה ותרחיש — אין אייקון ──
  eq([await icon().count(), await leg().getAttribute('title')], [0, 'הסתרה מהגרף'], 'Elliott with a count and a scenario → no "no forecast" icon');

  // ── תשובה בלי שום דבר לצייר → ∅, title ו-aria-label ──
  strip = '';
  await refresh();
  await icon().waitFor({ timeout: 10000 });
  eq([await icon().textContent(), await leg().getAttribute('title'), await leg().getAttribute('aria-label')],
    ['∅', 'אליוט — אין תחזית כרגע', 'אליוט — אין תחזית כרגע'], 'nothing to draw → ∅ inside the button, with title and aria-label');
  eq(await p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast().methods.find(m => m.id === 'elliott-w3')).then(m => [m.items, m.scenario]),
    [0, 0], 'and indeed nothing is drawn for it');
  const grey = await icon().evaluate(el => getComputedStyle(el).color !== getComputedStyle(el.parentElement).color);
  eq(grey, true, 'the icon is muted (grey), not the button colour');
  if (process.env.SHOTS) {
    await leg().screenshot({ path: process.env.SHOTS + '/legend-none-light.png' });
    await p.emulateMedia({ colorScheme: 'dark' });
    await p.waitForTimeout(300);
    await leg().screenshot({ path: process.env.SHOTS + '/legend-none-dark.png' });
    await p.locator('.chart-box:not(.osc)').screenshot({ path: process.env.SHOTS + '/legend-none-chart-dark.png' });
    await p.emulateMedia({ colorScheme: 'light' });
  }

  // ── עם overlay.note — ההערה היא ה-title ──
  strip = 'לא נמצאה ספירה של 5 גלים בטווח';
  await refresh();
  await p.waitForFunction(() => document.querySelector('.fc-leg[data-method="elliott-w3"]')?.title === 'לא נמצאה ספירה של 5 גלים בטווח', null, { timeout: 10000 });
  eq([await icon().count(), await leg().getAttribute('aria-label')], [1, 'אליוט — אין תחזית כרגע'], 'overlay.note → it is the title; the aria-label stays');

  // ── מוסתרת (off) → בלי אייקון, המצב הקיים ──
  await leg().click();
  await p.waitForTimeout(200);
  eq([await icon().count(), await leg().getAttribute('aria-pressed'), await leg().getAttribute('title')], [0, 'false', 'הצגה על הגרף'], 'hidden (off) → no icon, as before');
  await leg().click();
  await icon().waitFor({ timeout: 5000 });
  eq(await icon().count(), 1, 'shown again → the icon is back');

  // ── ריענון עם תרחיש → האייקון נעלם ──
  strip = null;
  await refresh();
  await p.waitForFunction(() => !document.querySelector('.fc-leg[data-method="elliott-w3"] .fc-leg-none'), null, { timeout: 10000 });
  eq([await icon().count(), await leg().getAttribute('title'), await leg().getAttribute('aria-label')], [0, 'הסתרה מהגרף', null], 'a refresh with a scenario → the icon is gone');
} catch (e) {
  fail++;
  console.log('✗', e.stack);
} finally {
  await b.close();
  srv.kill();
}
const errs = errors.filter(e => !/favicon|sw\.js/.test(e));
eq(errs, [], 'no page errors');
console.log(fail ? `✗ ${fail}/${n} נכשלו` : `✓ ${n} בדיקות עברו`);
process.exit(fail ? 1 : 0);
