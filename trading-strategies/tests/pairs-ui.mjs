// בדיקת ממשק S1 + S1.1 (spec-stage-a 18.1, 18.1.1) — בחירת זוג, בטלפון: שני הצדדים, כותרת הגרף, המעקב, הניתוח וההתראות.
// כל תווית: BTC ⇄ USDT (אייקון SVG אחד, משמאל לימין, הבסיס ראשון), ומתחת השמות בעברית — בלי המילה "מול" ובלי סימול
// מחובר; האייקון נראה בבהיר ובכהה; זוג בלי ספק — הודעה בלי פנייה לספק; הסימול גלוי רק למנהל.
// node trading-strategies/tests/pairs-ui.mjs   (SHOTS=<dir> — צילומי מסך)
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-pairs-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
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
  p.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  p.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(/^.*api.php/, '')); });
  const reqs = [];
  p.on('request', r => { if (r.url().includes('api.php')) reqs.push(new URL(r.url()).searchParams.get('action')); });
  return { p, reqs };
}
// הטקסט הגלוי של המסך — כולל הכותרת ותיבות הבחירה, בלי ה-DOM הנסתר
const visible = p => p.evaluate(() => document.body.innerText + ' ' + [...document.querySelectorAll('select option')].map(o => o.textContent).join(' ') + ' ' + document.title);
const SYMBOL = /BTCUSDT|BTCUSDC|USDILS|ILSUSD|BTC\/USD/;
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const loaded = p => p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint[aria-live]')?.textContent || ''), null, { timeout: 30000 });

// 18.1.1: כל תווית זוג גלויה במסך — [טקסט הראשית, טקסט המשנית, כמה SVG בין הקודים, כיוון, הבסיס ראשון]
// (גלויה: בלי השורה של המסך המלא, שמוסתרת עד ⛶ — F1)
const pairs = p => p.evaluate(() => [...document.querySelectorAll('.pair-main')].filter(m => m.querySelectorAll('b').length === 2 && m.getClientRects().length).map(m => {
  const bs = [...m.querySelectorAll('b')];
  const between = [...m.childNodes].slice([...m.childNodes].indexOf(bs[0]) + 1, [...m.childNodes].indexOf(bs[1]));
  const sub = m.parentElement?.querySelector(':scope > .pair-sub') || document.querySelector('#subtitle .pair-sub');
  return { main: m.innerText.replace(/\s+/g, ' ').trim(), sub: sub ? sub.innerText.replace(/\s+/g, ' ').trim() : null,
    svg: between.filter(n => n.nodeName.toLowerCase() === 'svg').length, text: between.map(n => (n.nodeType === 3 ? n.textContent : '')).join('').trim(),
    dir: getComputedStyle(m).direction, baseLeft: bs[0].getBoundingClientRect().left < bs[1].getBoundingClientRect().left,
    aria: m.querySelector('svg')?.getAttribute('aria-label') };
}));
// כל הכללים של 18.1.1 על מסך אחד
async function rules(p, where) {
  const ps = await pairs(p);
  eq(ps.length > 0, true, `${where}: there are pair labels (${ps.length})`);
  eq(ps.filter(x => /מול/.test(x.main + ' ' + (x.sub || ''))).map(x => x.main), [], `${where}: criterion 2 — no "מול" in any pair label`);
  eq(ps.filter(x => x.svg !== 1 || x.text !== '').map(x => x.main), [], `${where}: criterion 3 — exactly one SVG between the two codes, no text`);
  eq(ps.filter(x => x.dir !== 'ltr' || !x.baseLeft).map(x => x.main), [], `${where}: criterion 4 — the main line is left-to-right, base first`);
  eq([...new Set(ps.map(x => x.aria))], ['מול'], `${where}: the icon says "מול" to a screen reader only`);
  eq(SYMBOL.test(await visible(p)), false, `${where}: criterion 1 — no joined symbol in the visible text`);
  return ps;
}
// ניגוד האייקון מול הרקע שמאחוריו (WCAG, רכיב גרפי ≥ 3:1)
const iconContrast = p => p.evaluate(() => {
  const rgb = c => (c.match(/[\d.]+/g) || []).slice(0, 4).map(Number);
  const lum = ([r, g, b]) => [r, g, b].map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((s, v, i) => s + v * [0.2126, 0.7152, 0.0722][i], 0);
  const bg = el => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c.length && (c[3] ?? 1) > 0.5) return c; } return [255, 255, 255]; };
  return Math.min(...[...document.querySelectorAll('.pair-main svg.vs')].map(s => {
    const a = lum(rgb(getComputedStyle(s).color)), b2 = lum(bg(s));
    return (Math.max(a, b2) + 0.05) / (Math.min(a, b2) + 0.05);
  }));
});

try {
  const { p: a, reqs } = await open('alice');
  // ── מסך השוק (S1.2 מחליף את שתי הרשימות): לשונית קריפטו — כל שורה בתווית של S1.1 ──
  await a.goto(BASE + '#/market');
  await a.locator('main [role="combobox"]').waitFor();
  await a.getByRole('tab', { name: '₿ קריפטו' }).click();
  await a.locator('#mtab-panel .mrow').first().waitFor();
  const ps = await rules(a, 'market');
  eq(ps.slice(0, 2).map(x => [x.main, x.sub]), [['BTC USDT', 'ביטקוין טתר'], ['ETH USDT', "את'ריום טתר"]], 'market rows: the code, and the Hebrew names below (18.1.1)');
  eq(await noHScroll(a), true, 'market screen: no horizontal scroll at 390px');
  eq(await iconContrast(a) >= 3, true, 'criterion 5: the icon is visible on the light theme (contrast ≥ 3:1)');
  await shot(a, 'pairs-market');
  // הרשימה של ה-combobox — אותה תווית
  const cb = a.locator('main [role="combobox"]');
  await cb.fill('דולר');
  await a.locator('main [role="option"]').first().waitFor();
  await rules(a, 'combobox');
  eq((await pairs(a)).some(x => x.main === 'USD ILS' && x.sub === 'דולר שקל'), true, 'USD ⇄ ILS is offered (ECB)');

  // ── זוג בלי ספק — "ביטקוין ין": אין פריט, אין פנייה לספק (✅28 — לא בונים זוג מחושב) ──
  reqs.length = 0;
  await cb.fill('ביטקוין ין');
  await a.locator('main .combo-msg', { hasText: 'לא נמצא' }).waitFor({ timeout: 5000 });
  eq(reqs.filter(x => ['market_candles', 'chart_refresh'].includes(x)), [], 'S1 criterion 3: no request for candles');
  eq(await a.locator('main .error').count(), 0, 'S1 criterion 3: not shown as an error');

  // ── "דולר שקל" מהרשימה פותח את הגרף ──
  await cb.fill('דולר שקל');
  await a.locator('main [role="option"]').first().waitFor();
  await cb.press('ArrowDown');
  await cb.press('Enter');
  await a.waitForURL(/#\/chart\//);
  await loaded(a);
  const title = await pairs(a);
  eq([title[0]?.main, title[0]?.sub], ['USD ILS', 'דולר שקל'], 'chart header: USD ⇄ ILS, and the Hebrew names below it');
  eq(/מול/.test(await a.locator('header').innerText()), false, 'chart header: no "מול"');
  await rules(a, 'chart');

  // ── גרף BTC ⇄ USDT, ⭐ למעקב, ובחזרה לשוק ──
  await a.goto(BASE + '#/market');
  await a.getByRole('tab', { name: '₿ קריפטו' }).click();
  await a.locator('#mtab-panel .mrow').first().locator('button.body').click();
  await loaded(a);
  eq((await a.locator('#title').innerText()).replace(/\s+/g, ' ').trim(), 'BTC USDT', 'chart header from the catalog');
  await a.getByRole('button', { name: 'הוספה למעקב' }).click();
  await a.goto(BASE + '#/market');
  await a.getByRole('tab', { name: '⭐ מועדפים' }).click();
  await a.locator('#mtab-panel .mrow').first().waitFor();
  eq((await a.locator('#mtab-panel .pair-main').first().innerText()).replace(/\s+/g, ' ').trim(), 'BTC USDT', 'the favorites row: BTC ⇄ USDT');
  await rules(a, 'market + watchlist');

  // ── ניתוח: בחירת הנכס — כפתורים עם התווית (לא <select>, שאין בו אייקון) ──
  await a.goto(BASE + '#/analysis');
  await a.locator('[role="radiogroup"][aria-label="נכס"], main .empty').first().waitFor();
  if (await a.locator('[role="radiogroup"][aria-label="נכס"]').count()) {
    eq(await a.locator('select[aria-label="נכס"]').count(), 0, 'analysis: the asset picker is not a <select>');
    await rules(a, 'analysis');
  }
  eq(SYMBOL.test(await visible(a)), false, 'S1 criterion 1: no symbol on the analysis screen');

  // ── התראה חדשה: אותה בחירה ──
  await a.goto(BASE + '#/alerts');
  await a.evaluate(() => sessionStorage.setItem('ts-alerts-tab', 'mine'));
  await a.reload();
  await a.getByRole('button', { name: '+ התראה' }).click();
  await a.locator('dialog[open] input[role="combobox"]').waitFor();
  await rules(a, 'new alert');
  await a.locator('dialog[open]').getByRole('button', { name: 'סגירה' }).click();

  // ── כהה (criterion 5) ──
  await a.emulateMedia({ colorScheme: 'dark' });
  await a.goto(BASE + '#/market');
  await a.locator('.pair-main svg.vs').first().waitFor();
  eq(await iconContrast(a) >= 3, true, 'criterion 5: the icon is visible on the dark theme (contrast ≥ 3:1)');
  await shot(a, 'pairs-market-dark');
  await a.emulateMedia({ colorScheme: 'light' });

  // ── קריטריון 4: הסימול גלוי למנהל, במסך המנהל ──
  const { p: o } = await open('owner');
  await o.goto(BASE + '#/admin');
  await o.evaluate(() => sessionStorage.setItem('ts-admin-tab', 'cache'));
  await o.goto(BASE + '#/admin');
  await o.reload();
  const cat = o.locator('details', { hasText: 'קטלוג הזוגות' });
  await cat.waitFor();
  await cat.locator('summary').click();
  eq(/binance:BTCUSDT/.test(await cat.textContent()), true, 'criterion 4: the admin sees the symbols in the pairs catalog');
  await shot(o, 'pairs-admin');

  eq(errors, [], 'no page errors');
} catch (e) {
  fail++; console.log('✗ crashed:', e.message);
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
