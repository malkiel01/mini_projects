// R1 — מסך "עורכים" (spec-stage-a 24.5) בדפדפן: עורך ראשי רואה ומנהל; עורך משני — הרשימה בלי כפתורים; משתמש — בלי המסך.
// ליד העורך מהשער ולידו עצמו — בלי "בטל". בטלפון (390 ו-320): בלי גלילה אופקית, וגם בכהה.
// node trading-strategies/tests/roles-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים
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
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-roles-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=me'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const b = await chromium.launch();
async function open(as, { scheme = 'light', width = 390 } = {}) {
  const ctx = await b.newContext({ viewport: { width, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; sessionStorage.setItem('ts-admin-tab', 'editors'); });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));
  return { p, ctx, errors };
}
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const go = async (p, hash) => { await p.goto(BASE + hash); await p.locator('main .loading').waitFor({ state: 'detached', timeout: 20000 }).catch(() => {}); };
const noScroll = p => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
const row = (p, name) => p.locator('li.editor-row').filter({ has: p.locator('.l1 > span:first-child', { hasText: name }) });

try {
  // כולם נכנסים פעם אחת — כך הם קיימים במסד
  for (const u of ['alice', 'bob', 'carol']) { const x = await open(u); await go(x.p, '#/market'); await x.ctx.close(); }

  // ── עורך ראשי (מהשער) ──
  const o = await open('owner');
  await go(o.p, '#/admin');
  eq(await o.p.locator('.seg-tabs [role="tab"][aria-selected="true"]').textContent(), '👤 עורכים', 'the tab is "👤 עורכים"');
  await row(o.p, 'בעל המערכת').waitFor();
  eq(await row(o.p, 'בעל המערכת').locator('.chip').textContent(), 'עורך ראשי · מהשער', 'the gate chief: "עורך ראשי · מהשער"');
  eq(await row(o.p, 'בעל המערכת').locator('button').count(), 0, 'no buttons next to the gate chief (and next to yourself)');

  // מינוי: בוב — עורך ראשי; קרול — עורך משני (ברירת המחדל)
  const appoint = async (name, chief) => {
    await o.p.locator('main button', { hasText: 'מינוי עורך' }).click();
    const dlg = o.p.locator('dialog[open]');
    await dlg.locator('select[name="user"]').selectOption({ label: name });
    if (chief) await dlg.locator('fieldset.seg label', { hasText: 'עורך ראשי' }).click();
    await shot(o.p, `roles-appoint-${chief ? 'chief' : 'editor'}`);
    await dlg.locator('button.primary').click();
    await row(o.p, name).waitFor();
  };
  await appoint('בוב', true);
  await appoint('קרול', false);
  eq(await row(o.p, 'בוב').locator('.chip').textContent(), 'עורך ראשי', 'bob: "עורך ראשי"');
  eq(await row(o.p, 'קרול').locator('.chip').textContent(), 'עורך משני', 'carol: "עורך משני"');
  eq((await row(o.p, 'קרול').locator('.l2.granted').textContent()).includes('מינה: בעל המערכת'), true, 'who appointed, and when');
  eq([await row(o.p, 'קרול').locator('[data-role-to="chief"]').count(), await row(o.p, 'קרול').locator('[data-revoke]').count()], [1, 1], 'carol: "לעורך ראשי" and "בטל"');
  eq(await row(o.p, 'בוב').locator('[data-role-to="editor"]').count(), 1, 'bob: "לעורך משני"');
  eq(await noScroll(o.p), true, '390: no horizontal scroll');
  await shot(o.p, 'roles-editors-chief');

  // שינוי ותפקיד ובטל
  await row(o.p, 'בוב').locator('[data-role-to="editor"]').click();
  await row(o.p, 'בוב').locator('.chip', { hasText: 'עורך משני' }).waitFor();
  eq(true, true, 'bob → "עורך משני"');
  await row(o.p, 'בוב').locator('[data-revoke]').click();
  await o.p.locator('dialog[open] button.primary').click();
  await row(o.p, 'בוב').waitFor({ state: 'detached' });
  eq(await row(o.p, 'בוב').count(), 0, 'bob revoked — gone from the list');

  // ── בוב עורך ראשי ממונה: אין "בטל" לידו עצמו ──
  await o.p.locator('main button', { hasText: 'מינוי עורך' }).click();
  await o.p.locator('dialog[open] select[name="user"]').selectOption({ label: 'בוב' });
  await o.p.locator('dialog[open] fieldset.seg label', { hasText: 'עורך ראשי' }).click();
  await o.p.locator('dialog[open] button.primary').click();
  await row(o.p, 'בוב').waitFor();
  const bb = await open('bob');
  await go(bb.p, '#/admin');
  await row(bb.p, 'בוב').waitFor();
  eq([await row(bb.p, 'בוב').locator('button').count(), (await row(bb.p, 'בוב').locator('.l2:not(.granted)').textContent()).includes('אתם')], [0, true], 'next to yourself — no buttons, "אתם"');
  eq(await row(bb.p, 'קרול').locator('[data-revoke]').count(), 1, 'an appointed chief can revoke others');
  eq(await bb.p.locator('main button', { hasText: 'מינוי עורך' }).count(), 1, 'an appointed chief can appoint');

  // ── עורך משני: הרשימה, בלי כפתורים ──
  const c = await open('carol');
  await go(c.p, '#/admin');
  await row(c.p, 'בעל המערכת').waitFor();
  eq([await c.p.locator('main li.editor-row button').count(), await c.p.locator('main button', { hasText: 'מינוי עורך' }).count()], [0, 0], 'secondary editor: the list, no buttons');
  eq(await c.p.locator('main', { hasText: 'סיסמאות לאפליקציה' }).count(), 0, 'secondary editor: no password reset');
  await shot(c.p, 'roles-editors-secondary');

  // ── משתמש: לא רואה את המסך ──
  const a = await open('alice');
  await go(a.p, '#/more');
  eq(await a.p.locator('a[href="#/admin"]').count(), 0, 'user: no "ניהול"');

  // ── 320 ובכהה ──
  const s = await open('owner', { width: 320, scheme: 'dark' });
  await go(s.p, '#/admin');
  await row(s.p, 'קרול').waitFor();
  eq(await noScroll(s.p), true, '320 dark: no horizontal scroll');
  const bx = await row(s.p, 'קרול').locator('[data-revoke]').boundingBox();
  eq(bx && bx.x >= 0 && bx.x + bx.width <= 320, true, '320: "בטל" inside the screen');
  await shot(s.p, 'roles-editors-320-dark');

  for (const x of [o, bb, c, a, s]) eq(x.errors, [], 'no page errors');
} catch (e) {
  fail++;
  console.log('✗ ' + (e.stack || e));
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail}/${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
