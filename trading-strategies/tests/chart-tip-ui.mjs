// T1 — חלונית הסבר על נקודת תחזית (spec-stage-a 18.1.6, 2.33), בטלפון מדומה, לפי קריטריוני הקבלה:
// 1 נקודת אליוט · 2 אזור תרחיש · 3 אזור פיבונאצ'י · 4 הזנב (±4px) · 5 מיקום ובתוך הגרף · 6 סגירה · 7 החלפה · 8 פריט בלי tip (פיבונאצ'י, tip נמחק בתשובה) · 9 מקרא ·
// 10 מסך מלא, לגובה ולרוחב (שכבה מסובבת) · 11 רק תצוגה · 12 ניסוח · 13 קונסול. ובנוסף: גרירה, כלי ציור, "פירוט השיטה ›", כהה.
// node trading-strategies/tests/chart-tip-ui.mjs   (SHOTS=<dir> — צילומי מסך)
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
// נתוני wave — שם אליוט מוצאת ספירה עם תוויות (כמו chart-f1-ui), ופיבונאצ'י — אזור חפיפה (כמו fibonacci-ui, 100 שעות קודם)
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-chart-tip-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_KLINES_SHAPE: 'wave' }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
const SHOTS = process.env.SHOTS;
const NOW = 1794094200;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();

async function open({ scheme = 'light', now = NOW, as = 'owner' } = {}) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.setExtraHTTPHeaders({ 'X-Dev-Now': String(now) });
  await ctx.clock.setFixedTime(now * 1000);
  // מסך מלא לרוחב בלי נעילת כיוון (כמו Safari) — השכבה המסובבת, שבה המגע מומר ב-rotate.js
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; if (window.ScreenOrientation) delete window.ScreenOrientation.prototype.lock; });
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
// השיטה נבחרת מראש (localStorage) — הגרף מבקש אותה כבר בפתיחה
async function chartWith(p, method) {
  await p.goto(BASE + '#/market');
  await p.waitForSelector('main [role="combobox"]');
  await p.evaluate(m => localStorage.setItem('ts-forecast-methods', JSON.stringify([{ id: m, color: '--brand' }])), method);
  await p.goto(CHART);
  await loaded(p);
  await p.locator(`.forecast-panel[data-method="${method}"] .chips`).waitFor({ timeout: 60000 });
}
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png') }); };
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });
const BOX = '.chart-box:not(.osc)';
const tipState = p => p.evaluate(s => document.querySelector(s).tsTip(), BOX);
const tipCount = p => p.locator('.ctip').count();
const tipText = async p => ({ title: (await p.locator('.ctip-title').textContent()).trim(), sub: (await p.locator('.ctip-sub').count()) ? (await p.locator('.ctip-sub').textContent()).trim() : '',
  tag: (await p.locator('.ctip .ctip-row .ctip-tag').textContent()).trim(), explain: (await p.locator('.ctip-text').count()) ? (await p.locator('.ctip-text').textContent()).trim() : null,
  inv: (await p.locator('.ctip-inv').count()) ? (await p.locator('.ctip-inv').textContent()).trim() : null, note: (await p.locator('.ctip-note').textContent()).trim(),
  all: (await p.locator('.ctip').textContent()) });
const seen = [];   // כל הטקסטים של כל החלוניות — לבדיקת הניסוח (12)
const pts = (p, kind) => p.evaluate(([s, k]) => document.querySelector(s).tsPoints(k), [BOX, kind]);

// נקודת אליוט בתוך שטח הגרף, הכי רחוקה מנקודות אחרות
async function point(p) {
  return p.evaluate(s => {
    const box = document.querySelector(s);
    const W = box.clientWidth, H = box.clientHeight, all = box.tsPoints('label');
    const far = q => Math.min(...all.filter(o => o !== q).map(o => Math.hypot(o.x - q.x, o.y - q.y)), 999);
    return all.filter(q => q.x > 40 && q.x < W - 70 && q.y > 70 && q.y < H - 40).sort((a, c) => far(c) - far(a))[0] || null;
  }, BOX);
}
// מנקודה בגרף (x, y) → נקודה על המסך. בשכבה המסובבת: (lx, ly) בשכבה → (R − ly, T + lx) על המסך (rotate.js)
const toScreen = (p, q) => p.evaluate(async ([s, q]) => {
  const { screenRect } = await import('./assets/js/rotate.js');
  const box = document.querySelector(s), wrap = box.closest('.chart-wrap');
  const lx = box.offsetLeft + q.x, ly = box.offsetTop + q.y;
  if (wrap.classList.contains('fs-turn')) { const r = screenRect(wrap); return [r.right - ly, r.top + lx]; }
  const r = screenRect(box);
  return [r.left + q.x, r.top + q.y];
}, [BOX, q]);
// הזנב, המיקום והגבולות — במערכת של wrap (getBoundingClientRect כבר במערכת השכבה כשהיא מסובבת)
const geom = p => p.evaluate(s => {
  const box = document.querySelector(s), wrap = box.closest('.chart-wrap'), tip = wrap.querySelector('.ctip'), t = tip.querySelector('.ctip-tail'), st = box.tsTip();
  const w = wrap.getBoundingClientRect(), r = t.getBoundingClientRect(), k = tip.getBoundingClientRect(), bx = box.getBoundingClientRect();
  const lg = wrap.querySelector('.fc-legend'), l = lg && !lg.hidden ? lg.getBoundingClientRect() : null;
  const tipX = r.left + r.width / 2 - w.left, tipY = (st.side === 'above' ? r.bottom : r.top) - w.top;
  const hit = (a, c) => a.left < c.right && c.left < a.right && a.top < c.bottom && c.top < a.bottom;
  return { gap: [Math.round(Math.abs(tipX - st.x)), Math.round(Math.abs(tipY - st.y))], side: st.side, third: (st.y - (bx.top - w.top)) / bx.height,
    inside: k.left >= bx.left - 0.5 && k.right <= bx.right + 0.5 && k.top >= bx.top - 0.5 && k.bottom <= bx.bottom + 0.5,
    legend: l ? hit(k, l) : false, w: Math.round(k.width), bw: bx.width, flipped: getComputedStyle(tip).transform !== 'none' };
}, BOX);
async function tap(p, q) { const [x, y] = await toScreen(p, q); await p.touchscreen.tap(x, y); await p.waitForTimeout(150); }
// בדיקת המיקום של כל חלונית שנפתחת (4, 5, 9)
async function checkGeom(p, where) {
  const g = await geom(p);
  eq(g.gap[0] <= 4 && g.gap[1] <= 4, true, `${where} — criterion 4: the tail points at the tap (±4px; off by ${g.gap})`);
  eq(g.inside, true, `${where} — criterion 5: the tip is inside the chart`);
  if (g.third < 1 / 3) eq(g.side, 'below', `${where} — criterion 5: a point in the top third → the tip below it`);
  if (g.third > 2 / 3) eq(g.side, 'above', `${where} — criterion 5: a point in the bottom third → the tip above it`);
  eq(g.legend, false, `${where} — criterion 9: the tip does not cover the legend`);
  eq(g.w <= Math.min(280, Math.round(g.bw * 0.85)) + 1, true, `${where} — width ≤ min(280, 85% of the chart) (${g.w})`);
  eq(g.flipped, false, `${where} — criterion 10: the tip itself is not transformed (rotates only with the chart)`);
  seen.push((await tipText(p)).all);
}
// הספירה בנרות האחרונים — בקצה הימני. גוררים את הגרף שמאלה (אל הרווח העתידי) עד שהנקודות בפנים
async function center(p) {
  for (let i = 0; i < 6; i++) {
    const xs = (await pts(p, 'label')).map(q => q.x);
    const W = await p.evaluate(s => document.querySelector(s).clientWidth, BOX);
    if (!xs.length || Math.max(...xs) < W * 0.6) return;
    const [x, y] = await toScreen(p, { x: W * 0.5, y: 150 });
    const [x2] = await toScreen(p, { x: W * 0.5 - 120, y: 150 });
    await p.mouse.move(x, y); await p.mouse.down();
    for (let k = 1; k <= 8; k++) await p.mouse.move(x + (x2 - x) * k / 8, y);
    await p.mouse.up();
    await p.waitForTimeout(200);
  }
}

try {
  const { p: a, reqs } = await open();
  await chartWith(a, 'elliott-w3');
  await a.waitForFunction(s => document.querySelector(s).tsPoints('label').length > 0, BOX, { timeout: 30000 });
  await center(a);
  const q = await point(a);
  eq(q !== null, true, 'there is an Elliott point inside the chart');

  // ── 1: נקודת אליוט → "גל N", מחיר וזמן, "אליוט · ממצא", הסבר, "לא ייעוץ" ──
  await tap(a, q);
  await a.locator('.ctip').waitFor({ timeout: 5000 });
  const st = await tipState(a);
  eq([st?.kind, st?.id], ['label', 'elliott-w3'], 'a tap on an Elliott point opens the tip for that point');
  const t1 = await tipText(a);
  eq([t1.title, /\d/.test(t1.sub) && /:/.test(t1.sub), t1.tag, !!t1.explain && /כלל|גל/.test(t1.explain), /לא ייעוץ/.test(t1.note)],
    [`גל ${q.text}`, true, 'אליוט · ממצא', true, true], `criterion 1: "גל ${q.text}", price and time, "אליוט · ממצא", the rule, "not advice"`);
  eq(/^מתבטל אם מחיר (מתחת|מעל) ל-[\d,.]+ — /.test(t1.inv || ''), true, `the invalidation of the count, with its rule ("${t1.inv}")`);
  eq([await a.locator('.ctip[role="dialog"]').count(), await a.evaluate(() => document.querySelector('.ctip').getAttribute('aria-labelledby') === document.querySelector('.ctip-title').id),
    await a.evaluate(() => document.activeElement?.classList.contains('ctip-x'))], [1, true, true], 'a11y: role=dialog, labelled by its title, focus on ✕');
  await checkGeom(a, 'Elliott point');
  eq(await a.evaluate(() => document.scrollingElement.scrollWidth <= innerWidth + 1), true, 'no horizontal page scroll');
  await shot(a, 'tip-light');

  // ── 2: אזור התרחיש → "אזור תרחיש", טווח, "תרחיש", הכלל, רמת הביטול עם הכלל שלה ──
  await a.keyboard.press('Escape');
  const zone = (await pts(a, 'zone')).find(z => z.scen);
  eq(zone != null, true, 'there is a scenario zone');
  // הקופסה צרה (נר עתידי אחד ב-3 חודשים), וקו התרחיש עובר בה — הוא הקטן, ולכן ראשון. אל הקופסה — דרך "עוד … כאן ›"
  await tap(a, { x: zone.x, y: zone.y });
  let zs = await tipState(a);
  eq(zs !== null && zs.n > 1, true, `several items at the scenario box (${zs?.n}) — the smallest first (${zs?.kind})`);
  for (let i = 0; zs && zs.kind !== 'zone' && i < zs.n; i++) { await a.locator('.ctip-more').click(); zs = await tipState(a); }
  const t2 = await tipText(a);
  eq([zs?.kind, /^אזור תרחיש — /.test(t2.title), /^[\d,.]+–[\d,.]+$/.test(t2.sub), t2.tag, /\d/.test(t2.explain || ''), /^מתבטל אם .+ — .+/.test(t2.inv || '')],
    ['zone', true, true, 'אליוט · תרחיש', true, true], `criterion 2: "${t2.title}", "${t2.sub}", "${t2.tag}", the rule, and the invalidation with its rule`);
  await checkGeom(a, 'scenario zone');
  await shot(a, 'tip-zone');

  // ── 7: לחיצה על פריט אחר כשחלונית פתוחה → אחת, של הפריט החדש ──
  // פריט שהחלונית הפתוחה לא מכסה: נקודה, או קו הביטול בקצה שמחוץ לחלונית
  const other = await a.evaluate(s => {
    const box = document.querySelector(s), k = document.querySelector('.ctip').getBoundingClientRect(), b = box.getBoundingClientRect();
    const free = q => q.x > 8 && q.x < box.clientWidth - 8 && q.y > 8 && q.y < box.clientHeight - 8
      && !(q.x + b.left > k.left - 30 && q.x + b.left < k.right + 30 && q.y + b.top > k.top - 30 && q.y + b.top < k.bottom + 30);
    const cand = [...box.tsPoints('label'), ...box.tsPoints('hline').flatMap(l => [{ ...l, x: 12 }, { ...l, x: box.clientWidth - 40 }])];
    return cand.find(free) || null;
  }, BOX);
  if (other) {
    await tap(a, other);
    eq([await tipCount(a), (await tipState(a))?.kind !== 'zone'], [1, true], 'criterion 7: tapping another item with a tip open → one tip, the new item\'s');
  } else eq(other, 'an item outside the open tip', 'criterion 7');

  // ── רמת הביטול (קו) ──
  await a.keyboard.press('Escape');
  const inv = (await pts(a, 'hline')).find(x => x.role === 'invalidation');
  const H0 = await a.evaluate(s => document.querySelector(s).clientHeight, BOX);
  if (inv && inv.y > 30 && inv.y < H0 - 30) {
    await tap(a, { x: inv.x - 60, y: inv.y + 10 });   // 10px מהקו — עדיין בתוך 24
    const ti = await tipText(a);
    eq([(await tipState(a))?.kind, ti.title, /מבטל את הספירה/.test(ti.explain || ''), ti.inv], ['hline', 'רמת ביטול', true, null],
      'a tap 10px from the invalidation line → "רמת ביטול", its rule, no second invalidation');
    await checkGeom(a, 'invalidation line');
    await a.keyboard.press('Escape');
  }

  // ── כמה פריטים באותה נקודה → "עוד N פריטים כאן ›", ולחיצה עוברת לבא ──
  await tap(a, q);
  const s1 = await tipState(a);
  if (s1.n > 1) {
    await a.locator('.ctip-more').click();
    const s2 = await tipState(a);
    eq([s2.i, s2.n, (await tipCount(a))], [1, s1.n, 1], `several items here (${s1.n}) → "עוד ${s1.n - 1} … כאן ›" goes to the next`);
  } else eq(await a.locator('.ctip-more').count(), 0, 'one item here → no "עוד … כאן"');

  // ── 6: ✕ / Esc / לחיצה מחוץ / גרירה ──
  await a.locator('.ctip .ctip-x').click();
  eq([await tipCount(a), await a.evaluate(s => document.activeElement === document.querySelector(s), BOX)], [0, true], 'criterion 6: ✕ closes, and the focus goes back to the chart');
  await tap(a, q);
  await a.keyboard.press('Escape');
  eq(await tipCount(a), 0, 'criterion 6: Esc closes');
  await tap(a, q);
  await a.locator('main .quote-head').tap();
  eq(await tipCount(a), 0, 'criterion 6: a tap outside the tip closes it');
  await tap(a, q);
  const [px, py] = await toScreen(a, q);
  await a.mouse.move(px, py); await a.mouse.down();
  for (let i = 1; i <= 6; i++) await a.mouse.move(px - i * 10, py);
  await a.mouse.up();
  eq(await tipCount(a), 0, 'criterion 6: dragging the chart closes the tip');
  // גרירה שמתחילה על נקודה — הגרף זז, ולא נפתחת חלונית
  const q2 = await point(a);
  const [sx, sy] = await toScreen(a, q2);
  const r0 = await a.evaluate(s => document.querySelector(s).tsRange().from, BOX);
  await a.mouse.move(sx, sy); await a.mouse.down();
  for (let i = 1; i <= 8; i++) await a.mouse.move(sx + i * 10, sy);
  await a.mouse.up();
  await a.waitForTimeout(200);
  eq([await tipCount(a), (await a.evaluate(s => document.querySelector(s).tsRange().from, BOX)) !== r0], [0, true], 'a drag that starts on a point moves the chart, no tip');
  // כלי ציור פעיל → הלחיצה שלו
  await a.getByRole('button', { name: /ציור/ }).first().click();
  const q3 = await point(a);
  await tap(a, q3);
  eq(await tipCount(a), 0, 'drawing tools on → no tip');
  await a.getByRole('button', { name: /ציור/ }).first().click();
  await tap(a, q3);
  eq(await tipCount(a), 1, 'drawing tools off → the tip is back');
  await a.keyboard.press('Escape');

  // ── 11: רק תצוגה — 10 חלוניות, 0 בקשות, הממצא והתרחיש זהים ──
  const f0 = await a.evaluate(s => JSON.stringify(document.querySelector(s).tsForecast().methods), BOX);
  reqs.length = 0;
  for (let i = 0; i < 10; i++) { await tap(a, q3); await a.keyboard.press('Escape'); }
  eq([reqs.length, await a.evaluate(s => JSON.stringify(document.querySelector(s).tsForecast().methods), BOX) === f0], [0, true],
    'criterion 11: opening and closing 10 tips → 0 requests, the finding and the scenario unchanged');

  // ── רענון: הפריט עדיין שם → החלונית נשארת; השיטה מוסתרת במקרא (הפריט נעלם) → נסגרת ──
  await tap(a, q3);
  await a.locator('.fc-legend .fc-leg').first().click();
  eq(await tipCount(a), 0, 'the method hidden in the legend (its items are gone) → the tip closes');
  await a.locator('.fc-legend .fc-leg').first().click();
  await a.waitForTimeout(200);

  // ── 10: מסך מלא לגובה ──
  await a.getByRole('button', { name: 'מסך מלא', exact: true }).click();
  await a.getByRole('menu', { name: 'מסך מלא' }).getByRole('menuitem', { name: 'לגובה' }).click();
  await a.locator('.chart-wrap.fs').waitFor();
  await a.waitForTimeout(600);
  await center(a);
  await tap(a, await point(a));
  await a.locator('.chart-wrap.fs .ctip').waitFor({ timeout: 5000 });
  await checkGeom(a, 'full screen, portrait');
  await a.keyboard.press('Escape');
  eq([await tipCount(a), await a.locator('.chart-wrap.fs').count()], [0, 1], 'Esc closes the tip first — full screen stays');
  // ── 10: לרוחב — השכבה המסובבת ──
  await a.locator('.fs-rot').click();
  await a.locator('.chart-wrap.fs.fs-turn').waitFor();
  await a.waitForTimeout(600);
  await center(a);
  const qf = await point(a);
  await tap(a, qf);
  await a.locator('.chart-wrap.fs .ctip').waitFor({ timeout: 5000 });
  eq((await tipState(a))?.kind, 'label', 'criterion 10: landscape (rotated layer) — the tip opens on the point');
  await checkGeom(a, 'full screen, landscape (rotated)');
  await shot(a, 'tip-landscape');
  // "פירוט השיטה ›" במסך מלא — יוצא מהמסך המלא, פותח את תפריט השיטות ואת הבלוק של השיטה
  await a.locator('.ctip').getByText('פירוט השיטה ›').click();
  await a.waitForFunction(() => !document.querySelector('.chart-wrap.fs'));
  await a.waitForTimeout(300);
  eq([await tipCount(a), await a.locator('.fc-pop:not([hidden]) input[value="elliott-w3"]:checked').count(),
    await a.locator('.forecast-panel[data-method="elliott-w3"] .an-fold').getAttribute('aria-expanded')], [0, 1, 'true'],
    '"פירוט השיטה ›": leaves full screen, opens the methods menu with Elliott checked, and its block below the chart');

  // ── 3: אזור פיבונאצ'י עם tip (ציון ויחסים); 8: פריט בלי tip — אותו גרף, כשה-tip נמחק מתשובת השרת ──
  // פיבונאצ'י ממלא tip מאז #1701+; כדי שקריטריון 8 עדיין ייבדק באמת, המחיקה נעשית בתשובה (route) — לא בקוד
  // משתמש אחר (alice): ב"עכשיו" של 100 שעות קודם, צבע שהבעלים שמר "עכשיו" נראה לשרת כעתידי (422 — לא קשור לחלונית)
  eq(await post(a, 'admin_method_save', { id: 'fibonacci', enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4'] }), 200, 'the admin turns Fibonacci on');
  const fibZone = async strip => {
    const { p: f } = await open({ now: NOW - 100 * 3600, as: 'alice' });
    if (strip) await f.route(u => u.href.includes('action=chart_refresh'), async route => {
      const r = await route.fetch();
      const j = await r.json();
      for (const m of Object.values(j.methods || {})) for (const part of [m.overlay, m.forecast]) for (const it of part?.items || []) delete it.tip;
      await route.fulfill({ response: r, json: j });
    });
    await chartWith(f, 'fibonacci');
    await f.waitForFunction(s => document.querySelector(s).tsPoints('zone').length > 0, BOX, { timeout: 30000 });
    const FW = await f.evaluate(s => document.querySelector(s).clientWidth, BOX);
    const fz = (await pts(f, 'zone')).find(z => z.y > 60 && z.y < 440);
    if (!fz) return { f, fz: null };
    // באמצע החלק הגלוי של האזור; רמה בודדת שעוברת שם קודמת — אל האזור דרך "עוד … כאן ›"
    await tap(f, { x: (Math.max(Math.min(fz.box[0], fz.box[2]), 10) + Math.min(Math.max(fz.box[0], fz.box[2]), FW - 10)) / 2, y: fz.y });
    await f.locator('.ctip').waitFor({ timeout: 5000 });
    for (let i = 0, s0 = await tipState(f); s0.kind !== 'zone' && i < s0.n; i++) { await f.locator('.ctip-more').click(); s0 = await tipState(f); }
    return { f, fz, tf: await tipText(f), kind: (await tipState(f))?.kind, items: await f.evaluate(s => document.querySelector(s).tsForecast().methods[0].items, BOX) };
  };
  const z3 = await fibZone(false);
  if (z3.fz) {
    eq([z3.kind, /^אזור פיבונאצ'י · ציון \d+$/.test(z3.tf.title), /^\d+ רמות מ-\d+ טווחים/.test(z3.tf.explain || ''), /\d+(\.\d)?% של [\d,.]+→[\d,.]+/.test(z3.tf.explain || ''),
      /יעד|קנה|מכור/.test(z3.tf.all), z3.tf.tag], ['zone', true, true, true, false, 'פיבונאצ\'י · ממצא'],
      `criterion 3: a Fibonacci zone → "${z3.tf.title}", and its ratios in the explanation ("${z3.tf.explain}")`);
    await checkGeom(z3.f, 'Fibonacci zone');
    await shot(z3.f, 'tip-fib-zone');
  } else eq(z3.fz, 'a Fibonacci zone in view', 'criterion 3: a Fibonacci zone');
  const z8 = await fibZone(true);
  if (z8.fz) {
    eq([z8.kind, /^אזור (תמיכה|התנגדות) · \d+ רמות · ציון \d+$/.test(z8.tf.title), z8.tf.explain, z8.tf.tag], ['zone', true, null, 'פיבונאצ\'י · ממצא'],
      `criterion 8: an item without a tip → the title from its text ("${z8.tf.title}"), no explanation, no error (${z8.items} items)`);
    await checkGeom(z8.f, 'Fibonacci zone (no tip)');
    await shot(z8.f, 'tip-fib-notip');
  } else eq(z8.fz, 'a Fibonacci zone in view', 'criterion 8: a Fibonacci zone');

  // ── כהה ──
  const { p: d } = await open({ scheme: 'dark' });
  await chartWith(d, 'elliott-w3');
  await d.waitForFunction(s => document.querySelector(s).tsPoints('label').length > 0, BOX, { timeout: 30000 });
  await center(d);
  await tap(d, await point(d));
  await d.locator('.ctip').waitFor({ timeout: 5000 });
  const bg = await d.evaluate(() => getComputedStyle(document.querySelector('.ctip')).backgroundColor);
  eq(bg !== 'rgb(255, 255, 255)', true, `dark mode: the tip is dark (${bg})`);
  await shot(d, 'tip-dark');

  // ── 12: ניסוח — בלי "יעד", "קנה", "מכור" בשום חלונית ──
  eq(seen.filter(s => /יעד|קנה|מכור/.test(s)), [], `criterion 12: no "יעד", "קנה" or "מכור" in any tip (${seen.length} tips)`);
  eq(errors.filter(e => !/^4(04|22) /.test(e)), [], 'criterion 13: no page errors');
} catch (e) {
  fail++;
  console.log('✗ exception:', e.message, (e.stack || '').split('\n').find(l => l.includes('chart-tip-ui')) || '', errors);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
