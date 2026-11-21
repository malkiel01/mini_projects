// בדיקת ממשק S1.2 (spec-stage-a 18.1.3, 18.1.4) — מסך השוק בטלפון: בחירה מרשימה (combobox, בלי כפתור חיפוש ובלי טקסט
// חופשי), ולשוניות: "⭐ מועדפים" ראשונה, המובנות, גרירה, הסרה והחזרה, לשונית משלי, ⭐ בשורה, ובלי מפתח Twelve Data (ש7).
// גם 🟡 מ-F1: הספק בכותרת הגרף לא נחתך. node trading-strategies/tests/market-tabs-ui.mjs   (SHOTS=<dir> — צילומי מסך)
import { createRequire } from 'module';
import { readFileSync } from 'fs';
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
const HTTP_LOG = join(tmpdir(), `ts-tabs-ui-${RUN}.http.log`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-tabs-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'), TS_HTTP_LOG: HTTP_LOG }, stdio: 'ignore' });
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
const provCalls = () => { try { return readFileSync(HTTP_LOG, 'utf8').split('\n').filter(Boolean).length; } catch { return 0; } };
const txt = l => l.innerText().then(s => s.replace(/\s+/g, ' ').trim());
const tabNames = p => p.locator('main [role="tablist"] [role="tab"]').allInnerTexts();
const combo = p => p.locator('main > .combo input[role="combobox"]');
const options = p => p.locator('main > .combo [role="option"]');
const optTexts = async p => (await options(p).allInnerTexts()).map(s => s.replace(/\s+/g, ' ').trim());

try {
  const { p: a, reqs } = await open('alice');
  // ── 18.1.4 קריטריון 1: נפתח על "⭐ מועדפים"; אין פס כפתורי סוגים, אין כפתור חיפוש ──
  await a.goto(BASE + '#/market');
  await combo(a).waitFor();
  eq((await tabNames(a))[0], '⭐ מועדפים', 'criterion 1: the first tab is "⭐ מועדפים"');
  eq(await a.locator('main [role="tab"][aria-selected="true"]').innerText(), '⭐ מועדפים', 'criterion 1: the market screen opens on favorites');
  eq(await tabNames(a), ['⭐ מועדפים', '₿ קריפטו', '💱 מט"ח', 'נאסד"ק 100', 'S&P 500', '📊 מדדים', '🛢 סחורות'], 'the built-in tabs follow, in order');
  eq([await a.locator('main input[name="pair-base"], main [role="radiogroup"]').count(), await a.getByRole('button', { name: /חיפוש/ }).count(), await a.locator('main form').count()],
    [0, 0, 0], 'criterion 1 / 18.1.3 criterion 3: no type-button strip, no "search" button, no form to submit');
  eq(await txt(a.locator('#mtab-panel')), 'סמנו ⭐ ליד נכס כדי להוסיף', 'empty favorites: the hint');
  const tabsBox = await a.locator('main [role="tablist"]').evaluate(e => ({ wrap: getComputedStyle(e).flexWrap, scroll: e.scrollWidth > e.clientWidth }));
  eq(tabsBox, { wrap: 'nowrap', scroll: true }, 'the tab row scrolls sideways and does not wrap (390px)');
  eq(await noHScroll(a), true, 'market screen: no horizontal page scroll at 390px');
  await shot(a, 's12-market-favorites');

  // ── קריטריון 2–3: קריפטו — 20 שורות בתווית של S1.1, מחירים מהמאגר בלבד ──
  let before = provCalls();
  reqs.length = 0;
  await a.getByRole('tab', { name: '₿ קריפטו' }).click();
  await a.locator('#mtab-panel .mrow').first().waitFor();
  eq(await a.locator('#mtab-panel .mrow').count(), 20, 'criterion 2: "₿ קריפטו" has 20 rows');
  const ps = await rules(a, 'crypto tab');
  eq(ps.slice(0, 2).map(x => [x.main, x.sub]), [['BTC USDT', 'ביטקוין טתר'], ['ETH USDT', "את'ריום טתר"]], 'criterion 2: each row is an S1.1 label');
  eq([provCalls() - before, reqs.filter(x => ['market_search', 'market_candles', 'chart_refresh'].includes(x))], [0, []], 'criterion 3: opening a tab sends nothing to a provider');
  eq(await a.locator('#mtab-panel .mrow').first().locator('.mq .p').innerText(), '—', 'criterion 3: no data in the store → "—"');
  await shot(a, 's12-market-crypto');

  // ── קריטריון 6: ⭐ בשורה מוסיף למועדפים מיד ──
  await a.locator('#mtab-panel .mrow').nth(1).getByRole('button', { name: 'הוספה למועדפים' }).click();
  await a.locator('#mtab-panel .mrow').nth(1).getByRole('button', { name: 'הסרה מהמועדפים' }).waitFor();
  await a.getByRole('tab', { name: '⭐ מועדפים' }).click();
  await a.locator('#mtab-panel .mrow').first().waitFor();
  eq((await pairs(a)).map(x => x.main), ['ETH USDT'], 'criterion 6: ⭐ on a row adds it to favorites at once');

  // לחיצה על שורה פותחת את הגרף; בחזרה — הגרף מילא את המאגר, והמחיר מופיע (ש6), עדיין בלי פנייה לספק בפתיחת הלשונית
  await a.getByRole('tab', { name: '₿ קריפטו' }).click();
  await a.locator('#mtab-panel .mrow').first().locator('button.body').click();
  await a.waitForURL(/#\/chart\//);
  await loaded(a);
  eq((await a.locator('#title').innerText()).replace(/\s+/g, ' ').trim(), 'BTC USDT', 'criterion 2: a row opens its chart');
  // 🟡 F1: "Binance" שלם בכותרת המשנית, גם כשהשמות מתקצרים
  const tail = await a.locator('#subtitle .sub-tail').evaluate(e => ({ text: e.textContent, cut: e.scrollWidth > e.clientWidth + 1, inside: e.getBoundingClientRect().left >= e.parentElement.getBoundingClientRect().left - 1 }));
  eq([tail.text.includes('Binance'), tail.cut, tail.inside], [true, false, true], '🟡 F1: the provider name in the chart subtitle is whole (not cut to "…ce")');
  await a.goto(BASE + '#/market');
  await a.locator('#mtab-panel .mrow').first().waitFor();
  eq(await a.locator('main [role="tab"][aria-selected="true"]').innerText(), '₿ קריפטו', 'back from the chart: the same tab is open');
  before = provCalls();
  await a.getByRole('tab', { name: '💱 מט"ח' }).click();
  await a.getByRole('tab', { name: '₿ קריפטו' }).click();
  await a.waitForFunction(() => /\d/.test(document.querySelector('#mtab-panel .mrow .mq .p')?.textContent || ''));
  eq([/^[\d,]+(\.\d+)?$/.test(await a.locator('#mtab-panel .mrow').first().locator('.mq .p').innerText()), provCalls() - before], [true, 0], 'ש6: after the chart filled the store — a price in the row, still no provider request');

  // ── 18.1.3: combobox ──
  const c = combo(a);
  eq([await c.getAttribute('role'), await c.getAttribute('aria-expanded'), await a.locator('main > .combo [role="listbox"]').count()], ['combobox', 'false', 1], 'criterion 6: role=combobox, aria-expanded, and a listbox');
  reqs.length = 0;
  await c.focus();
  await options(a).first().waitFor();
  eq([await c.getAttribute('aria-expanded'), (await optTexts(a))[0]], ['true', 'ETH USDT ETH ⇄ USDT'.split(' ')[0] + ' USDT ' + "את'ריום טתר"], 'empty field on focus: the watchlist first');
  eq((await optTexts(a)).includes('BTC USDT ביטקוין טתר'), true, 'empty field on focus: then the common catalog pairs');
  for (const q of ['ביט', 'bit', 'BTC']) {
    await c.fill(q);
    await a.waitForTimeout(50);
    eq((await optTexts(a)).includes('BTC USDT ביטקוין טתר'), true, `criterion 1: "${q}" finds BTC ⇄ USDT`);
  }
  await a.waitForTimeout(400);
  eq(reqs.filter(x => x === 'market_search').length, 0, 'criterion 4: typing the catalog answers ("ביט") → 0 search requests');
  const roles = await a.evaluate(() => [...document.querySelectorAll('main > .combo [role="listbox"] > *')].every(e => e.getAttribute('role') === 'option'));
  eq(roles, true, 'criterion 6: every list item is role=option');

  // קריטריון 2: Enter בלי פריט מסומן — כלום
  const url0 = a.url();
  reqs.length = 0;
  await c.press('Enter');
  await a.waitForTimeout(300);
  eq([a.url(), reqs], [url0, []], 'criterion 2: Enter with no highlighted item does nothing (no chart, no request)');

  // קריטריון 6: חצים, Esc
  await c.press('ArrowDown');
  const id1 = await c.getAttribute('aria-activedescendant');
  eq([id1 !== null, await a.locator(`[id="${id1}"]`).getAttribute('aria-selected')], [true, 'true'], 'criterion 6: ↓ highlights the first item — aria-activedescendant + aria-selected');
  await c.press('ArrowDown');
  const id2 = await c.getAttribute('aria-activedescendant');
  await c.press('ArrowUp');
  eq([id2 !== id1, await c.getAttribute('aria-activedescendant')], [true, id1], 'criterion 6: ↓ moves on, ↑ back');
  await c.press('Escape');
  eq([await c.getAttribute('aria-expanded'), await c.inputValue()], ['false', ''], 'criterion 6: Esc closes the list and brings back the previous text');

  // קריטריון 4: "apple" מהר — לכל היותר בקשה אחת; תשובה ישנה נזרקת
  reqs.length = 0;
  await c.fill('');
  await c.pressSequentially('apple', { delay: 40 });
  await a.locator('main > .combo .combo-msg:not([hidden]), main > .combo [role="option"]').first().waitFor();
  await a.waitForTimeout(900);
  eq(reqs.filter(x => x === 'market_search').length <= 1, true, `criterion 4: fast "apple" → at most one search request (${reqs.filter(x => x === 'market_search').length})`);
  // קריטריון 5: אין התאמה
  await c.fill('zzzz');
  await a.locator('main > .combo .combo-msg', { hasText: 'לא נמצא' }).waitFor({ timeout: 5000 });
  eq([await options(a).count(), await a.locator('main .error').count()], [0, 0], 'criterion 5: "zzzz" → "לא נמצא", no error');
  await shot(a, 's12-combobox-none');
  // בחירה במקלדת פותחת גרף
  await c.fill('דולר שקל');
  await options(a).first().waitFor();
  eq((await optTexts(a))[0], 'USD ILS דולר שקל', 'two words — both sides ("דולר שקל" → USD ⇄ ILS)');
  await shot(a, 's12-combobox-open');
  await c.press('ArrowDown');
  await c.press('Enter');
  await a.waitForURL(/#\/chart\/ecb/);
  eq(true, true, 'criterion 6: ↓ + Enter picks the item and opens its chart');

  // ── ניווט מהיר: גרף איטי → שוק לפני שהגרף נטען. השוק מופיע מיד (בלי לחכות לגרף), והגרף לא מצויר מעליו כשהוא מסיים ──
  let slowDone = null;
  const slowDelay = new Promise(r => { slowDone = r; });
  await a.route('**/api.php?action=pair_labels*', async r => { await new Promise(x => setTimeout(x, 3000)); await r.continue().catch(() => {}); slowDone(); });
  await a.goto(BASE + '#/chart/' + encodeURIComponent('binance:ETHUSDT'));
  await a.locator('main p.loading').waitFor();
  const tNav = Date.now();
  await a.goto(BASE + '#/market');
  await combo(a).waitFor();
  const navMs = Date.now() - tNav;
  eq(navMs < 2500, true, `fast navigation: the market screen appears without waiting for the slow chart (${navMs} ms, the chart waits 3000)`);
  await slowDelay;
  await a.waitForTimeout(500);
  eq([await a.locator('main .chart-wrap').count(), await combo(a).count(), /#\/market$/.test(a.url())], [0, 1, true],
    'fast navigation: after the slow chart answers — it does not draw over the market screen');
  await a.unroute('**/api.php?action=pair_labels*');

  // ── קריטריון 4: גרירה משנה את הסדר ונשמרת; המועדפים לא נגררים ──
  await a.goto(BASE + '#/market');
  await combo(a).waitFor();
  const drag = async (from, to) => {
    // הלשוניות קיימות לפני הגרירה (אחרי reload — ה-combobox והשורה נבנים יחד, אבל ליתר ביטחון)
    await a.getByRole('tab', { name: from }).waitFor();
    await a.getByRole('tab', { name: to }).waitFor();
    const f = await a.getByRole('tab', { name: from }).boundingBox(), t = await a.getByRole('tab', { name: to }).boundingBox();
    await a.mouse.move(f.x + f.width / 2, f.y + f.height / 2);
    await a.mouse.down();
    for (let i = 1; i <= 8; i++) await a.mouse.move(f.x + f.width / 2 + (t.x + t.width / 2 - f.x - f.width / 2) * i / 8, f.y + f.height / 2);
    await a.mouse.up();
    // השחרור שומר את הסדר (market_tabs_save), ובתשובה כל הכפתורים נבנים מחדש. גרירה שמתחילה לפני כן תופסת כפתור
    // שיוחלף — boundingBox מחזיר null (הרעידה בבדיקה 38). מחכים לסוף השמירה, לא לזמן קבוע
    await a.waitForFunction(() => !document.querySelector('main [role="tablist"]')?.hasAttribute('aria-busy'));
  };
  await drag('💱 מט"ח', '₿ קריפטו');
  eq((await tabNames(a)).slice(0, 3), ['⭐ מועדפים', '💱 מט"ח', '₿ קריפטו'], 'criterion 4: dragging changes the order');
  await drag('₿ קריפטו', '⭐ מועדפים');   // עובר מעל מט"ח — ומחליף איתו, אבל לא את המועדפים
  eq((await tabNames(a)).slice(0, 3), ['⭐ מועדפים', '₿ קריפטו', '💱 מט"ח'], 'criterion 4: no tab goes before favorites');
  await drag('⭐ מועדפים', '🛢 סחורות');
  eq((await tabNames(a))[0], '⭐ מועדפים', 'criterion 4: favorites is not dragged');
  await drag('נאסד"ק 100', '💱 מט"ח');
  const order = await tabNames(a);
  eq(order.indexOf('נאסד"ק 100') < order.indexOf('💱 מט"ח'), true, 'criterion 4: another drag');
  await a.reload();
  await combo(a).waitFor();
  eq(await tabNames(a), order, 'criterion 4: the order survives a reload');

  // ── קריטריון 5: הסרה של S&P 500 ב-⚙️, והחזרה ב-"➕ לשונית" ──
  await a.getByRole('button', { name: 'סידור לשוניות' }).click();
  const dlg = a.locator('dialog[open]');
  eq(await dlg.getByRole('button', { name: /הסרה של ⭐ מועדפים/ }).count(), 0, 'criterion 4: favorites cannot be removed');
  await dlg.getByRole('button', { name: 'הסרה של S&P 500' }).click();
  await a.waitForFunction(() => ![...document.querySelectorAll('main [role="tab"]')].some(t => t.textContent === 'S&P 500'));
  await dlg.getByRole('button', { name: 'סגירה' }).click();
  eq((await tabNames(a)).includes('S&P 500'), false, 'criterion 5: S&P 500 removed');
  await a.getByRole('button', { name: '➕ לשונית' }).click();
  await a.locator('dialog[open]').getByRole('button', { name: 'S&P 500' }).click();
  await a.waitForFunction(() => [...document.querySelectorAll('main [role="tab"]')].some(t => t.textContent === 'S&P 500'));
  eq((await tabNames(a)).at(-1), 'S&P 500', 'criterion 5: it comes back through "➕ לשונית"');

  // ── ש7: בלי מפתח Twelve Data — שורת הסבר, נכסים אפורים, לחיצה → הסבר ולא גרף, בלי פנייה לספק ──
  before = provCalls();
  await a.locator('#mtab-panel .needs').waitFor();
  eq((await txt(a.locator('#mtab-panel .needs'))).includes('Twelve Data'), true, 'ש7 / criterion 7: an explanation row at the top, with the provider');
  eq(await a.locator('#mtab-panel .needs a[href="#/providers"]').count(), 1, 'ש7: with a link to connect a provider');
  eq(await a.locator('#mtab-panel .mrow.locked').count() > 400, true, 'ש7: the assets are shown greyed (locked)');
  await rules(a, 'S&P 500 (locked)');
  await a.locator('#mtab-panel .mrow.locked').first().locator('button.body').click();
  await a.locator('dialog[open]', { hasText: 'צריך לחבר ספק' }).waitFor();
  eq([/#\/market/.test(a.url()), provCalls() - before, await a.locator('main .error').count()], [true, 0, 0], 'criterion 7: tapping a locked asset opens the explanation, not the chart — no error, no provider request');
  await shot(a, 's12-locked-explain');
  await a.locator('dialog[open]').getByRole('button', { name: 'סגירה' }).click();
  await shot(a, 's12-market-sp500-nokey');

  // ── קריטריון 5: לשונית משלי עם 3 נכסים מה-combobox ──
  await a.getByRole('button', { name: '➕ לשונית' }).click();
  const d2 = a.locator('dialog[open]');
  await d2.locator('input[name="tab-name"]').fill('שלי');
  const nc = d2.locator('input[name="new-tab-asset"]');
  for (const q of ['ביט', "את'ר", 'דולר שקל']) {
    await nc.fill(q);
    await d2.locator('[role="option"]').first().waitFor();
    await nc.press('ArrowDown');
    await nc.press('Enter');
  }
  eq(await d2.locator('.list .card').count(), 3, 'criterion 5: three assets chosen in the combobox');
  await shot(a, 's12-new-tab');
  await d2.getByRole('button', { name: 'יצירת הלשונית' }).click();
  await a.locator('main [role="tab"][aria-selected="true"]', { hasText: 'שלי' }).waitFor();
  await a.locator('#mtab-panel .mrow').first().waitFor();
  eq((await pairs(a)).filter(x => x.sub).map(x => x.main).slice(0, 3), ['BTC USD', 'ETH USD', 'USD ILS'], 'criterion 5: the custom tab is shown with its 3 assets');
  await a.reload();
  await a.locator('#mtab-panel .mrow').first().waitFor();
  eq([(await tabNames(a)).at(-1), await a.locator('#mtab-panel .mrow').count()], ['שלי', 3], 'criterion 5: and saved (after a reload)');
  // ✕ בשורה מסיר מהלשונית
  await a.locator('#mtab-panel .mrow').first().getByRole('button', { name: 'הסרה מהלשונית' }).click();
  await a.waitForFunction(() => document.querySelectorAll('#mtab-panel .mrow').length === 2);
  eq(await a.locator('#mtab-panel .mrow').count(), 2, 'a custom tab: ✕ removes an asset');

  // ── כהה ──
  await a.emulateMedia({ colorScheme: 'dark' });
  await a.getByRole('tab', { name: '₿ קריפטו' }).click();
  await a.locator('#mtab-panel .mrow').first().waitFor();
  await shot(a, 's12-market-crypto-dark');
  await a.emulateMedia({ colorScheme: 'light' });

  // ── קריטריון 7 (18.1.3): אותו רכיב בהתראה חדשה וביומן ──
  await a.goto(BASE + '#/alerts');
  await a.evaluate(() => sessionStorage.setItem('ts-alerts-tab', 'mine'));
  await a.reload();
  await a.getByRole('button', { name: '+ התראה' }).click();
  const da = a.locator('dialog[open]');
  await da.locator('input[role="combobox"]').waitFor();
  eq(await txt(da.locator('.asset-chosen')), 'ETH USDT את\'ריום טתר', 'new alert: the first favorite is chosen, as an S1.1 label');
  await da.locator('input[role="combobox"]').fill('ביט');
  await da.locator('[role="option"]').first().waitFor();
  await da.locator('input[role="combobox"]').press('ArrowDown');
  await da.locator('input[role="combobox"]').press('Enter');
  eq([await txt(da.locator('.asset-chosen')), await a.locator('dialog[open]').count()], ['BTC USD ביטקוין דולר', 1], 'criterion 7: the same combobox in a new alert — Enter picks, the dialog stays open');
  await shot(a, 's12-alert-combobox');
  await da.getByRole('button', { name: 'סגירה' }).click();

  await a.goto(BASE + '#/journal');
  await a.locator('main').waitFor();
  await a.getByRole('button', { name: /^\+?\s*עסקה$/ }).first().click();
  const dj = a.locator('dialog[open]');
  const jc = dj.locator('input[name="trade-asset"]');
  await jc.waitFor();
  await jc.fill('ביט');
  await dj.locator('[role="option"]').first().waitFor();
  await jc.press('ArrowDown');
  await jc.press('Enter');
  await a.waitForFunction(() => document.querySelector('dialog[open] input[name="symbol"]')?.value !== '');
  eq([await dj.locator('input[name="symbol"]').inputValue(), await dj.locator('input[name="market"]:checked').getAttribute('value')], ['BTCUSDC', 'crypto'], 'criterion 7: the same combobox in the journal — fills the asset and its market');
  await dj.getByRole('button', { name: 'סגירה' }).click();

  eq(errors.filter(e => !/^4(04|22) /.test(e)), [], 'no page errors');
} catch (e) {
  fail++;
  console.log('✗ exception:', e.message, (e.stack || '').split('\n').find(l => l.includes('market-tabs-ui')) || '');
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
