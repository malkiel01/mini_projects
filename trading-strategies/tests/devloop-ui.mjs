// DevLoop בדפדפן (A1, PROTOCOL 7; ר2): 🐞 בכותרת של כל מסך — לכולם. עורך ראשי — המפתח; עורך משני — רואה ומגיב, בלי "✅ אומת";
// משתמש — 🐞 בלבד, בלי מסך המטלות (R1 קריטריון 8).
// 🐞 פותח דיווח, והדיווח נפתח כמטלה TS-; מסך המטלות והיומן ב-#/dev; שורה "פיתוח" ב"עוד"; שגיאת JavaScript מגיעה ליומן.
// node trading-strategies/tests/devloop-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים ב-390
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-devloop-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const b = await chromium.launch();
async function open(as, scheme = 'light') {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));
  return { p, ctx, errors };
}
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png') }); };
const go = async (p, hash) => { await p.goto(BASE + hash); await p.locator('main .loading').waitFor({ state: 'detached', timeout: 20000 }).catch(() => {}); };
/** במסך המטלות — המסנן "הכול" (ברירת המחדל: מה שממתין לך) */
const all = p => p.locator('main button', { hasText: /^הכול/ }).first().click();
const SCREENS = ['#/market', '#/alerts', '#/journal', '#/stats', '#/more', '#/profile', '#/method-journal', '#/admin'];

try {
  // ── המפתח: 🐞 בכל מסך ──
  const o = await open('owner');
  for (const s of SCREENS) {
    await go(o.p, s);
    eq(await o.p.locator('header.top #dev-bug').count(), 1, `owner: 🐞 in ${s}`);
  }
  // הכותרת לא נשברת בטלפון: 🐞 בתוך הכותרת, ובלי גלילה אופקית
  await go(o.p, '#/market');
  const box = await o.p.locator('#dev-bug').boundingBox();
  eq(box && box.x >= 0 && box.x + box.width <= 390 && box.height >= 40, true, '🐞 inside the header at 390, tap ≥ 40px');
  eq(await o.p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'no horizontal scroll');
  await shot(o.p, 'devloop-header');

  // ── 🐞 → דיווח → מטלה TS- ──
  await o.p.locator('#dev-bug').click();
  const dlg = o.p.locator('dialog[open]');
  await dlg.locator('input[name="title"]').fill('הגרף לא נטען');
  await shot(o.p, 'devloop-report');
  const [resp] = await Promise.all([o.p.waitForResponse(r => r.url().includes('action=dev-report')), dlg.locator('button.primary').click()]);
  const rep = await resp.json();
  eq([resp.status(), /^TS-\d{3}$/.test(rep.code || '')], [200, true], 'report → TS- code');

  // ── מסך המטלות: המטלה שנפתחה, והמטלות מהקוד ──
  await go(o.p, '#/dev/tasks');
  await all(o.p);
  await o.p.getByText(rep.code).first().waitFor({ timeout: 10000 });
  eq(await o.p.getByText('TS-001').count() > 0, true, 'tasks from dev/open-items.json');
  eq(await o.p.locator('.seg-tabs a[aria-current="page"]').textContent(), '📋 מטלות', 'tasks tab current');
  eq(await o.p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'tasks: no horizontal scroll');
  await shot(o.p, 'devloop-tasks');

  // ── "עוד" ← פיתוח ──
  await go(o.p, '#/more');
  eq(await o.p.locator('a[href="#/dev/tasks"]').count(), 1, 'owner: "פיתוח" in more');

  // ── שגיאת JavaScript של משתמש → היומן ──
  const a = await open('alice');
  await go(a.p, '#/more');
  eq(await a.p.locator('header.top #dev-bug').count(), 1, 'ר2: alice (user) has 🐞');
  eq(await a.p.locator('a[href="#/dev/tasks"]').count(), 0, 'alice: no "פיתוח"');
  await a.p.locator('#dev-bug').click();
  await a.p.locator('dialog[open] input[name="title"]').fill('לא מצליח לשמור');
  const [ar] = await Promise.all([a.p.waitForResponse(r => r.url().includes('action=dev-report')), a.p.locator('dialog[open] button.primary').click()]);
  eq([ar.status(), /^TS-\d{3}$/.test((await ar.json()).code || '')], [200, true], 'alice: 🐞 → report → TS- code');
  await a.p.evaluate(() => setTimeout(() => { throw new Error('ts-devloop-ui-boom'); }));
  await a.p.waitForTimeout(800);
  await go(a.p, '#/dev/tasks');
  eq(await a.p.evaluate(() => location.hash), '#/more', 'alice: #/dev → back to more');
  await go(o.p, '#/dev/log');
  await o.p.locator('[data-lq]').fill('ts-devloop-ui-boom');
  await o.p.locator('[data-lq]').dispatchEvent('change');
  await o.p.locator('.dt-lr', { hasText: 'ts-devloop-ui-boom' }).first().waitFor({ timeout: 10000 });
  eq(true, true, 'JS error in the log');
  await shot(o.p, 'devloop-log');

  // ── עורך משני — רואה ומגיב; "✅ אומת" לא אצלו. עורך ראשי ממונה — כן (R1 קריטריון 8) ──
  const csrf = await o.p.evaluate(async () => (await (await fetch('api.php?action=me')).json()).csrf);
  const post = (action, body) => o.p.evaluate(async ([a, x, t]) => (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': t }, body: JSON.stringify(x) })).status, [action, body, csrf]);
  const idOf = email => o.p.evaluate(async e => (await (await fetch('api.php?action=admin_state')).json()).users.find(u => u.email === e).id, email);
  const c = await open('carol');
  const dv = await open('dave');
  await go(c.p, '#/market'); await go(dv.p, '#/market');
  eq(await post('admin_role_add', { user_id: await idOf('carol@test.local') }), 200, 'carol → secondary editor');
  eq(await post('admin_role_add', { user_id: await idOf('dave@test.local'), role: 'chief' }), 200, 'dave → chief editor');
  eq(await post('dev-task-set', { code: rep.code, status: 'verify' }), 200, 'the report waits for verification');
  await c.p.reload();
  await go(c.p, '#/more');
  eq(await c.p.locator('a[href="#/admin"]').count(), 1, 'carol sees "ניהול"');
  eq([await c.p.locator('header.top #dev-bug').count(), await c.p.locator('a[href="#/dev/tasks"]').count()], [1, 1], 'carol: 🐞 and "פיתוח"');
  await go(c.p, '#/dev/tasks/' + rep.code);
  await c.p.getByText(rep.code).first().waitFor({ timeout: 10000 });
  eq(await c.p.locator('.dt-verify').count(), 0, 'criterion 8: carol (secondary) — no "✅ אומת"');
  eq(await c.p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'carol tasks: no horizontal scroll');
  await shot(c.p, 'devloop-tasks-editor');
  await dv.p.reload();
  await go(dv.p, '#/dev/tasks/' + rep.code);
  await dv.p.getByText(rep.code).first().waitFor({ timeout: 10000 });
  eq(await dv.p.locator('.dt-verify').count(), 1, 'criterion 8: dave (appointed chief) — "✅ אומת" is there');

  // ── כהה ──
  const d = await open('owner', 'dark');
  await go(d.p, '#/dev/tasks');
  await all(d.p);
  await d.p.getByText(rep.code).first().waitFor({ timeout: 10000 });
  await shot(d.p, 'devloop-tasks-dark');

  for (const x of [o, a, c, dv, d]) eq(x.errors.filter(e => !e.includes('ts-devloop-ui-boom')), [], 'no page errors');
} catch (e) {
  fail++;
  console.log('✗ ' + (e.stack || e));
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `\n${fail}/${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
