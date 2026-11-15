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
  // ── שני הצדדים: הקוד, ומתחתיו השם בעברית ──
  await a.goto(BASE + '#/market');
  await a.locator('input[name="pair-base"]').first().waitFor();
  eq((await a.locator('input[name="pair-base"]:checked').evaluate(i => i.closest('label').innerText)).replace(/\s+/g, ' ').trim(), 'BTC ביטקוין', 'Bitcoin is the default first side: the code, and its Hebrew name below (18.1, 18.1.1)');
  const head2 = a.locator('main h2.sec-title').nth(1);
  eq([(await head2.innerText()).replace(/\s+/g, ' ').trim(), await head2.locator('svg.vs').count()], ['BTC ?', 1], 'the second list: "BTC ⇄ ?" — the icon, not the word');
  // 🟡 מ-S1.1 (F1): על המסך BTC משמאל, "?" מימין — הכותרת כולה LTR, ומיושרת לימין
  const [xb, xq] = await head2.locator('b').evaluateAll(els => els.map(e => e.getBoundingClientRect().left));
  eq([xb < xq, await head2.getAttribute('dir')], [true, 'ltr'], 'the heading reads "BTC ⇄ ?" left to right on screen (not "? ⇄ BTC")');
  const ps = await rules(a, 'market');
  eq(ps.filter(x => !x.main.endsWith('?')).map(x => [x.main, x.sub]), [['BTC USD', 'ביטקוין דולר'], ['BTC USDT', 'ביטקוין טתר']], '"with what" — only pairs with a provider; USDC (Binance) shows as USD');
  eq(await noHScroll(a), true, 'market screen: no horizontal scroll at 390px');
  eq(await iconContrast(a) >= 3, true, 'criterion 5: the icon is visible on the light theme (contrast ≥ 3:1)');
  await shot(a, 'pairs-market');

  // בחירת צד ראשון אחר — הרשימה השנייה מתעדכנת
  await a.locator('label').filter({ has: a.locator('input[value="USD"]') }).click();
  eq((await head2.innerText()).replace(/\s+/g, ' ').trim(), 'USD ?', 'choosing "dollar" updates the second list');
  eq((await pairs(a)).some(x => x.main === 'USD ILS' && x.sub === 'דולר שקל'), true, 'USD ⇄ ILS is offered (ECB)');

  // ── זוג בלי ספק — הודעה, בלי פנייה לספק; הזוג בהודעה — התווית עם האייקון ──
  reqs.length = 0;
  await a.fill('input[name="q"]', 'ביטקוין מול ין');
  await a.click('form button[type="submit"]');
  const status = a.locator('main [role="status"]');
  await status.waitFor();
  eq([(await status.innerText()).replace(/\s+/g, ' ').trim(), await status.locator('svg.vs').count()], ['BTC JPY : אין ספק לזוג הזה', 1], 'no provider: a message with the pair label (icon), not the words');
  eq(reqs.filter(x => ['market_search', 'market_candles', 'chart_refresh'].includes(x)), [], 'S1 criterion 3: no request that reaches a provider');
  eq(await a.locator('main .error').count(), 0, 'S1 criterion 3: not shown as an error');

  // ── חיפוש בעברית — "דולר מול שקל" עדיין מובן, ופותח את הגרף ──
  await a.fill('input[name="q"]', 'דולר מול שקל');
  await a.click('form button[type="submit"]');
  await a.waitForURL(/#\/chart\//);
  await loaded(a);
  const title = await pairs(a);
  eq([title[0]?.main, title[0]?.sub], ['USD ILS', 'דולר שקל'], 'chart header: USD ⇄ ILS, and the Hebrew names below it');
  eq(/מול/.test(await a.locator('header').innerText()), false, 'chart header: no "מול"');
  await rules(a, 'chart');

  // ── גרף BTC ⇄ USDT, ⭐ למעקב, ובחזרה לשוק ──
  await a.goto(BASE + '#/market');
  await a.locator('label').filter({ has: a.locator('input[value="BTC"]') }).click();
  await a.locator('main .list').first().locator('a.body').filter({ has: a.locator('b', { hasText: /^USDT$/ }) }).click();
  await loaded(a);
  eq((await a.locator('#title').innerText()).replace(/\s+/g, ' ').trim(), 'BTC USDT', 'chart header from the catalog');
  await a.getByRole('button', { name: 'הוספה למעקב' }).click();
  await a.goto(BASE + '#/market');
  await a.locator('main .list').last().locator('.card').first().waitFor();
  eq((await a.locator('main .list').last().locator('.pair-main').first().innerText()).replace(/\s+/g, ' ').trim(), 'BTC USDT', 'the watchlist row: BTC ⇄ USDT');
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
  await a.locator('dialog[open] [role="radiogroup"][aria-label="נכס"]').waitFor();
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
