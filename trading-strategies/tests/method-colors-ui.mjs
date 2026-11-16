// S5 — כמה שיטות, לכל אחת צבע, בדפדפן אמיתי (spec-stage-a 18.5, כל שבעת הקריטריונים).
// node trading-strategies/tests/method-colors-ui.mjs   (Playwright; ספקים מדומים, בלי רשת)
// SHOTS=<תיקייה מחוץ לריפו> — צילומים (deviceScaleFactor 2) ברוחב 390, בבהיר ובכהה.
//
// אליוט + שיטת הבדיקה הפנימית (sample-ma-cross, קריטריון 5) אצל מנהל, על נתוני TS_KLINES_SHAPE=wave (שם אליוט מוצאת ספירה
// עם חלופות). "מה צויר ובאיזה צבע" — מ-box.tsForecast().drawn: כל קו, אזור, רמה, תווית וסימון שצוירו בפעם האחרונה.
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-s5-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const chartOf = s => BASE + '#/chart/' + encodeURIComponent('binance:' + s);
const CHART = chartOf('BTCUSDT');
const SHOTS = process.env.SHOTS;
// חמישה ימים אחרי ה-NOW של שאר הבדיקות: בנתוני wave יש כאן ספירה לאליוט **וגם** אות פעיל לשיטת הבדיקה
const NOW = +(process.env.TS_NOW || 1794094200 + 5 * 86400);
const MIN = 60_000;
const E = 'elliott-w3', S = 'sample-ma-cross';
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
/** fake — שעון מזויף (clock.install): הזמן זז רק כשהבדיקה מקדמת אותו (קריטריון 6) */
async function open(as, { width = 390, height = 860, scheme = 'light', fake = false, methods = null } = {}) {
  const ctx = await b.newContext({ viewport: { width, height }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(NOW) });
  if (fake) await ctx.clock.install({ time: NOW * 1000 });
  else await ctx.clock.setFixedTime(NOW * 1000);
  await ctx.addInitScript(([m]) => {
    window.WebSocket = class { constructor() {} close() {} };
    // "מכשיר חדש": הבחירה במכשיר נקבעת מראש (פעם אחת), בלי צבעים — הצבע צריך להגיע מהפרופיל
    if (m && !sessionStorage.getItem('s5-seeded')) { localStorage.setItem('ts-forecast-methods', JSON.stringify(m)); sessionStorage.setItem('s5-seeded', '1'); }
  }, [methods]);
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  const reqs = [];
  p.on('request', r => { if (r.url().includes('api.php')) { const q = new URL(r.url()).searchParams; reqs.push([q.get('action'), q.get('methods')]); } });
  return { p, reqs, ctx };
}
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);
const profileColors = p => p.evaluate(async () => (await (await fetch('api.php?action=profile')).json()).profile.method_colors);
const shot = async (p, name, full = false) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: full }); };
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const ready = (p, id) => p.locator(`.forecast-panel[data-method="${id}"] .chips`).waitFor({ timeout: 60000 });
const state = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsForecast());
const labels = p => p.evaluate(() => document.querySelector('.chart-box:not(.osc)').tsLabels());
const boxRect = p => p.evaluate(() => { const r = document.querySelector('.chart-box:not(.osc)').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
/** הצבע של משתנה CSS כמו שהדפדפן מצייר אותו */
const cssColor = (p, v) => p.evaluate(x => getComputedStyle(document.documentElement).getPropertyValue(x).trim(), v);
const refreshes = reqs => reqs.filter(([a]) => a === 'chart_refresh').map(([, m]) => m || '');
const pop = p => p.locator('.fc-pop');
const legendBtn = (p, id) => p.locator(`.fc-legend .fc-leg[data-method="${id}"]`);
async function openPop(p) {
  if (await pop(p).isHidden()) await p.getByRole('button', { name: 'תחזית שיטה' }).first().click();
  await pop(p).locator('.fc-opt, .fc-none').first().waitFor();
}
const check = (p, name) => pop(p).locator('.fc-opt', { hasText: name }).locator('input');
const swatch = (p, id, color) => pop(p).locator(`.fc-colors input[name="fc-color-${id}"][value="${color}"]`);
const overlaps = rs => {
  const out = [];
  for (let i = 0; i < rs.length; i++) for (let j = i + 1; j < rs.length; j++) {
    const a = rs[i], c = rs[j];
    if (a.x < c.x + c.w && c.x < a.x + a.w && a.y < c.y + c.h && c.y < a.y + a.h) out.push([a.text, c.text]);
  }
  return out;
};
/** הצבעים שצוירו לכל שיטה: {id: [צבעים שונים]} */
const colorsBy = st => {
  const out = {};
  for (const d of st.drawn) (out[d.m] ??= new Set()).add(d.c);
  return Object.fromEntries(Object.entries(out).map(([k, v]) => [k, [...v]]));
};
/** מקדם שעון מזויף צעד אחרי צעד, ומחכה שכל chart_refresh שיצא יחזור */
async function advance(p, ms, step = ms) {
  if (step > 0 && step < ms) for (; ms > step; ms -= step) await advance(p, step);
  let inflight = 0;
  const on = r => { if (r.url().includes('chart_refresh')) inflight++; };
  const off = r => { if (r.request().url().includes('chart_refresh')) inflight--; };
  const offFail = r => { if (r.url().includes('chart_refresh')) inflight--; };
  p.on('request', on); p.on('response', off); p.on('requestfailed', offFail);
  await p.clock.runFor(ms);
  for (let i = 0; i < 100 && inflight > 0; i++) await new Promise(r => setTimeout(r, 50));
  await new Promise(r => setTimeout(r, 150));
  p.off('request', on); p.off('response', off); p.off('requestfailed', offFail);
}

try {
  // ── הכנה: המנהל מפעיל את שיטת הבדיקה (קריטריון 5: אליוט + sample-ma-cross) ──
  const { p: o, reqs } = await open('owner');
  await o.goto(BASE + '#/market');
  await o.waitForSelector('main [role="combobox"]');   // המסך נטען — ה-me של הדף חזר (אחרת post() פותח session שני והדף נשאר עם csrf ישן)
  eq(await post(o, 'admin_method_save', { id: S, enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4', 'd1'] }), 200, 'the admin turns on the sample method');
  eq(await profileColors(o), {}, 'no colors in the profile yet');
  await o.goto(CHART);
  await loaded(o);
  const rect0 = await boxRect(o);
  await openPop(o);
  eq(await o.locator('.fc-legend').isHidden(), true, 'no method → no legend');
  await check(o, 'אליוט').check();
  await ready(o, E);
  await check(o, /חציית ממוצעים/).check();
  await ready(o, S);
  eq(refreshes(reqs).at(-1), `${E},${S}`, 'two methods → one chart_refresh with both');

  // ── ברירת מחדל: הצבע הפנוי הבא, ונשמר בפרופיל ──
  let st = await state(o);
  eq(st.methods.map(m => [m.id, m.color]), [[E, '--brand'], [S, '--m2']], 'default: the next free color in the palette');
  await o.waitForFunction(() => Object.keys(document.querySelector('.chart-box:not(.osc)').tsForecast().colors).length === 2);
  eq(await profileColors(o), { [E]: '--brand', [S]: '--m2' }, 'the default colors are saved in the profile (method_colors)');

  // ── קריטריון 2: צבע תפוס — לא ניתן לבחירה ──
  eq([await swatch(o, S, '--brand').isDisabled(), await swatch(o, E, '--m2').isDisabled()], [true, true], 'criterion 2: each method — the color of the other is disabled');
  eq(await pop(o).locator(`.fc-colors input[name="fc-color-${S}"]:not(:disabled)`).count(), 7, 'the other 7 colors are offered');
  eq(/תפוס/.test(await swatch(o, S, '--brand').getAttribute('aria-label')), true, 'a taken color says "תפוס"');
  await swatch(o, S, '--brand').click({ force: true }).catch(() => {});
  await o.waitForTimeout(200);
  eq((await state(o)).methods.find(m => m.id === S).color, '--m2', 'criterion 2: clicking a taken color changes nothing');
  await shot(o, 's5-menu-light');

  // ── בחירת צבע ביד: אליוט → ורוד (--m5) ──
  reqs.length = 0;
  await swatch(o, E, '--m5').check();
  await o.waitForFunction(() => document.querySelector('.chart-box:not(.osc)').tsForecast().colors['elliott-w3'] === '--m5');
  eq(refreshes(reqs), [], 'changing a color does not ask the server for candles again');
  eq(reqs.filter(([a]) => a === 'method_colors_save').length, 1, 'the color is saved — one request');
  eq(await profileColors(o), { [E]: '--m5', [S]: '--m2' }, 'criterion 3: the chosen color is in the profile');
  eq(await swatch(o, S, '--brand').isDisabled(), false, 'the color Elliott left is free again');
  eq(await swatch(o, S, '--m5').isDisabled(), true, 'and the new one is taken');
  await o.keyboard.press('Escape');
  await o.waitForTimeout(400);

  // ── קריטריון 1: כל קו, תווית ואזור בצבע של השיטה שלו ──
  st = await state(o);
  const want = { [E]: await cssColor(o, '--m5'), [S]: await cssColor(o, '--m2') };
  const by = colorsBy(st);
  eq(st.drawn.some(d => d.m === E && d.k === 'polyline'), true, 'Elliott draws lines');
  eq(st.drawn.some(d => d.m === E && d.k === 'text'), true, 'Elliott draws labels');
  eq(st.drawn.some(d => d.m === S), true, `the sample method draws too (${[...new Set(st.drawn.filter(d => d.m === S).map(d => d.k))].join(',')})`);
  eq([by[E], by[S]], [[want[E]], [want[S]]], `criterion 1: each method — one color, its own (${JSON.stringify(by)})`);
  eq(want[E] !== want[S], true, 'the two colors differ');
  const kinds = [...new Set(st.drawn.filter(d => d.m === E).map(d => d.k))].sort();
  console.log(`  · Elliott drew: ${kinds.join(', ')} — ${st.drawn.filter(d => d.m === E).length} items; sample: ${st.drawn.filter(d => d.m === S).length}`);
  // הצבע בפס מתחת לגרף ובמקרא — אותו צבע
  const panelMc = await o.locator('.forecast-panel').evaluateAll(els => els.map(e => [e.dataset.method, getComputedStyle(e.querySelector('.fc-dot')).backgroundColor]));
  const legMc = await o.locator('.fc-legend .fc-leg').evaluateAll(els => els.map(e => [e.dataset.method, getComputedStyle(e.querySelector('.fc-dot')).backgroundColor]));
  eq(legMc, panelMc, 'the legend dots and the panel dots — the same colors');
  eq(new Set(legMc.map(x => x[1])).size, 2, 'two different dots');

  // ── קריטריון 7: המקרא מעל הגרף — כל שיטה שסומנה, עם הנקודה בצבע שלה ──
  eq(await o.locator('.fc-legend .fc-leg').evaluateAll(els => els.map(e => [e.dataset.method, e.textContent.trim(), e.getAttribute('aria-pressed')])),
    [[E, 'אליוט', 'true'], [S, (await o.locator(`.forecast-panel[data-method="${S}"] h3`).textContent()).trim(), 'true']], 'criterion 7: the legend lists every checked method');
  const legPos = await o.evaluate(() => { const l = document.querySelector('.fc-legend').getBoundingClientRect(), c = document.querySelector('.chart-box:not(.osc)').getBoundingClientRect(); return l.top >= c.top && l.bottom < c.top + c.height / 3 && l.left >= c.left && l.right <= c.right; });
  eq(legPos, true, 'the legend sits at the top of the chart');
  eq(await boxRect(o), rect0, 'the legend does not move or resize the chart');
  const ls = await labels(o);
  eq(ls.some(l => l.by === 'legend'), true, 'the legend is on the label board');
  eq(overlaps(ls), [], 'no label is drawn under the legend, and no two labels overlap');
  await shot(o, 's5-two-methods-light');

  // ── קריטריון 4 + 7: לחיצה במקרא מסתירה רק אותה, בלי לבטל את הסימון ──
  reqs.length = 0;
  await legendBtn(o, E).click();
  await o.waitForTimeout(300);
  st = await state(o);
  eq(await legendBtn(o, E).getAttribute('aria-pressed'), 'false', 'Elliott in the legend: pressed off');
  eq([st.drawn.some(d => d.m === E), st.drawn.some(d => d.m === S)], [false, true], 'criterion 4: only Elliott is hidden — the sample method is still drawn');
  eq(st.methods.map(m => [m.id, m.off]), [[E, true], [S, false]], 'criterion 7: Elliott still selected (hidden, not unchecked)');
  await openPop(o);
  eq([await check(o, 'אליוט').isChecked(), await check(o, /חציית ממוצעים/).isChecked()], [true, true], 'criterion 7: both still checked in the 🔮 menu');
  await o.keyboard.press('Escape');
  eq(await o.locator(`.forecast-panel[data-method="${E}"]`).count(), 1, 'Elliott\'s card stays under the chart');
  eq(/מוסתרת בגרף/.test(await o.locator(`.forecast-panel[data-method="${E}"] .an-fold`).textContent()), true, '… marked "מוסתרת בגרף"');
  eq(refreshes(reqs), [], 'hiding in the legend does not ask the server');
  await shot(o, 's5-legend-hidden-light');
  await o.reload();
  await loaded(o);
  await ready(o, S);
  eq(refreshes(reqs).at(-1), `${E},${S}`, 'after reload — a hidden method is still in the request (still selected)');
  eq(await legendBtn(o, E).getAttribute('aria-pressed'), 'false', 'and still hidden');
  await legendBtn(o, E).click();
  await o.waitForTimeout(300);
  eq((await state(o)).drawn.some(d => d.m === E), true, 'a second click shows it again');

  // ── ספירות חלופיות (17.1.5) — אותו צבע, עמום. בנתוני wave לאליוט יש ספירה אחת; החלופה נוספת לתשובה בדפדפן ──
  await o.route(/action=chart_refresh/, async route => {
    const res = await route.fetch();
    const j = await res.json();
    const m = j.methods?.[E];
    if (m?.overlay?.items) {
      const alt = it => ({ ...it, scenario: 'c2', primary: false });
      m.overlay.items.push(...m.overlay.items.filter(it => it.scenario).map(alt));
      m.forecast.items.push(...m.forecast.items.filter(it => it.scenario).map(alt));
      m.forecast.scenarios.push({ ...m.forecast.scenarios[0], id: 'c2', primary: false, label: 'התאמה בינונית · חלופה' });
    }
    await route.fulfill({ response: res, json: j });
  });
  await o.getByRole('button', { name: 'רענן' }).click();
  await o.waitForFunction(() => document.querySelectorAll('.forecast-panel[data-method="elliott-w3"] .forecast-scenarios button').length === 2, null, { timeout: 60000 });
  await o.waitForTimeout(400);
  st = await state(o);
  const alts = st.drawn.filter(d => d.alt);
  eq(alts.length > 0 && alts.every(d => d.m === E && d.c === want[E]), true, `alternative counts — in the method's color (${alts.length})`);
  eq(st.drawn.filter(d => d.m === E && !d.alt).length > 0, true, '… next to the leading count');
  eq(alts.some(d => d.k === 'text'), false, 'alternative counts — no text labels (dimmed lines only)');
  await o.unroute(/action=chart_refresh/);

  // ── קריטריון 3: יציאה וכניסה (מכשיר חדש, בלי הבחירה הקודמת), ובזוג אחר — אותו צבע ──
  const { p: o2, ctx: c2 } = await open('owner', { methods: [{ id: E, color: '--brand' }] });
  await o2.goto(chartOf('ETHUSDT'));
  await loaded(o2);
  await ready(o2, E);
  eq((await state(o2)).methods.map(m => [m.id, m.color]), [[E, '--m5']], 'criterion 3: new device, another pair — Elliott comes back in its color from the profile');
  await openPop(o2);
  await check(o2, /חציית ממוצעים/).check();
  await ready(o2, S);
  eq((await state(o2)).methods.map(m => [m.id, m.color]), [[E, '--m5'], [S, '--m2']], '… and the sample method too, when checked');
  await o2.goto(CHART);
  await loaded(o2);
  await ready(o2, E);
  eq((await state(o2)).methods.map(m => m.color), ['--m5', '--m2'], 'and on the first pair — the same colors');
  await c2.close();
  // משתמש אחר — הצבעים שלו
  const { p: bb } = await open('bob', { methods: [{ id: E, color: '--m3' }] });
  await bb.goto(CHART);
  await loaded(bb);
  await ready(bb, E);
  eq(await profileColors(bb), { [E]: '--m3' }, 'isolation: bob — his own color (taken from his device into his profile)');

  // ── קריטריון 6: שתי שיטות דולקות, רענון כל 60 שניות → בקשה אחת לדקה ──
  const { p: f, reqs: freqs } = await open('owner', { fake: true, methods: [{ id: E, color: '--m5' }, { id: S, color: '--m2' }] });
  await f.goto(CHART);
  await loaded(f);
  await ready(f, E);
  await ready(f, S);
  await f.clock.pauseAt((NOW + 1) * 1000);
  await advance(f, 0);
  freqs.length = 0;
  await advance(f, 5 * MIN, MIN);
  eq(refreshes(freqs), Array(5).fill(`${E},${S}`), 'criterion 6: two methods, every 60 seconds → one request a minute (5 in 5 minutes)');
  eq(freqs.filter(([a]) => a === 'analysis' || a === 'market_candles').length, 0, 'no separate request per method');

  // ── הפלטה נגישה: ניגוד של 3:1 לפחות מול הרקע של הגרף, בבהיר ובכהה ──
  const contrast = p => p.evaluate(() => {
    const rgb = v => { const d = document.createElement('i'); d.style.color = v; document.body.append(d); const m = getComputedStyle(d).color.match(/\d+/g).map(Number); d.remove(); return m; };
    const lum = ([r, g, bl]) => { const f = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(bl); };
    const ratio = (a, c) => { const [x, y] = [lum(a), lum(c)].sort((m, k) => k - m); return (x + 0.05) / (y + 0.05); };
    const bg = rgb('var(--surface)');
    return ['--brand', '--m2', '--m3', '--m4', '--m5', '--m6', '--m7', '--m8'].map(v => [v, Math.round(ratio(rgb(`var(${v})`), bg) * 10) / 10]);
  });
  const low = (await contrast(o)).filter(([, r]) => r < 3);
  eq(low, [], 'the palette — contrast ≥ 3:1 on the light background');
  const { p: k } = await open('owner', { scheme: 'dark', methods: [{ id: E, color: '--m5' }, { id: S, color: '--m2' }] });
  await k.goto(CHART);
  await loaded(k);
  await ready(k, E);
  eq((await contrast(k)).filter(([, r]) => r < 3), [], 'the palette — contrast ≥ 3:1 on the dark background');
  await openPop(k);
  await shot(k, 's5-menu-dark');
  await k.keyboard.press('Escape');
  await k.waitForTimeout(300);
  await shot(k, 's5-two-methods-dark');
  eq(await k.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1), true, 'no horizontal scroll at 390');
} catch (e) {
  fail++;
  console.log('✗ ' + e.stack);
} finally {
  eq(errors.filter(e => !/^404 /.test(e)), [], 'no page errors');
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
