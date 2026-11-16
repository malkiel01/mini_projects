// A2 בדפדפן — מחוברת או מנותקת (app-rules 2; platform/docs/sso.md):
// מנותקת: מסך כניסה עם כפתור אחד, "כניסה עם הכלים הדיגיטליים" → המעטפת (מדומה כאן, page.route) → חזרה עם זהות
// חתומה → האפליקציה; "יציאה" ב"עוד"; ביטול במעטפת → הודעה. מחוברת: אין מסך כניסה ואין "יציאה".
// node trading-strategies/tests/login-ui.mjs   (Playwright; בלי רשת)  ·  SHOTS=<תיקייה מחוץ לריפו> — צילומים ב-390
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
import { createServer } from 'net';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { mkdirSync, writeFileSync, rmSync } from 'fs';
import { generateKeyPairSync, sign, randomBytes } from 'crypto';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const port = await new Promise(r => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const RUN = `${process.pid}-${Math.random().toString(16).slice(2, 10)}`;
const tmp = join(tmpdir(), `ts-login-ui-${RUN}`);
mkdirSync(join(tmp, 'fx'), { recursive: true });
const ORIGIN = `http://127.0.0.1:${port}`;
const BASE = `${ORIGIN}/trading-strategies/`;
const RET = BASE + 'api.php?action=sso_callback';
const { publicKey, privateKey } = generateKeyPairSync('ed25519');
writeFileSync(join(tmp, 'fx', 'key.json'), JSON.stringify({ alg: 'Ed25519', key: publicKey.export({ format: 'jwk' }).x, issuer: ORIGIN + '/' }));
writeFileSync(join(tmp, 'fx', 'index.json'), JSON.stringify([{ match: '~/platform/sso\\.php\\?action=key$~', file: 'key.json' }]));
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: join(tmp, 'app.sqlite'), TS_APP_URL: BASE, TS_HTTP_FIXTURES: join(tmp, 'fx') }, stdio: 'ignore' });
for (let i = 0; i < 50; i++) { try { await fetch(BASE + 'api.php?action=login_config'); break; } catch { await new Promise(r => setTimeout(r, 100)); } }
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const b64u = buf => Buffer.from(buf).toString('base64url');

/** זהות חתומה כמו platform/lib/sso.php */
function token(state, o = {}) {
  const now = Math.floor(Date.now() / 1000);
  const p = b64u(JSON.stringify({ iss: ORIGIN + '/', aud: 'trading-strategies', sub: '301', name: 'רות', email: 'ruth@test.local', email_verified: true,
    state, return: RET, iat: now, exp: now + 120, jti: randomBytes(8).toString('hex'), ...o }));
  return p + '.' + b64u(sign(null, Buffer.from(p), privateKey));
}

const b = await chromium.launch();
/** $platform: מה "המעטפת" עונה ב-sso.php — 'ok' (זהות), 'cancel' (ביטול) */
async function open(plat, as = 'none', { platform = 'ok', scheme = 'light' } = {}) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true, colorScheme: scheme });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }, { name: 'dev_platform', value: plat, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const seen = [];
  // Playwright לא מנתב הפניות בתוך שרשרת — לכן sso_start עצמו: התשובה האמיתית של השרת (עם עוגיית ה-state), וההפניה
  // שלה למעטפת נרשמת ומוחלפת במה שהמעטפת הייתה מחזירה
  await ctx.route(u => u.pathname.endsWith('/api.php') && u.searchParams.get('action') === 'sso_start', async route => {
    const res = await route.fetch({ maxRedirects: 0 });
    const loc = new URL(res.headers().location);
    seen.push({ path: loc.origin + loc.pathname, ...Object.fromEntries(loc.searchParams) });
    const st = loc.searchParams.get('state');
    const to = platform === 'cancel' ? `${RET}&error=access_denied&state=${st}` : `${RET}&token=${token(st)}&state=${st}`;
    await route.fulfill({ status: 302, headers: { ...res.headers(), location: to } });
  });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));
  return { p, ctx, errors, seen };
}
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png') }); };
const settle = p => p.locator('main .loading').waitFor({ state: 'detached', timeout: 20000 }).catch(() => {});

try {
  // ── מנותקת: מסך הכניסה ──
  const d = await open('detached');
  await d.p.goto(BASE);
  await settle(d.p);
  const go = d.p.locator('main a.login-go');
  eq(await go.textContent(), 'כניסה עם הכלים הדיגיטליים', 'detached: one login button');
  eq(await d.p.locator('.bottom-nav').isHidden(), true, 'no tabs before login');
  const bx = await go.boundingBox();
  eq(bx && bx.height >= 40 && bx.x >= 0 && bx.x + bx.width <= 390, true, 'button is tappable and inside 390: ' + JSON.stringify(bx));
  eq(await d.p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'no horizontal scroll');
  await shot(d.p, 'login-390');
  // לחיצה → המעטפת → חזרה → האפליקציה
  await go.click();
  await d.p.waitForURL(u => u.pathname.endsWith('/trading-strategies/') && !u.search, { timeout: 20000 });
  await d.p.locator('main [role="combobox"]').first().waitFor({ timeout: 20000 });
  eq([d.seen.length, d.seen[0]?.path, d.seen[0]?.client, d.seen[0]?.return], [1, ORIGIN + '/platform/sso.php', 'trading-strategies', RET], 'went to the platform with client and return');
  eq(await d.p.locator('.bottom-nav').isVisible(), true, 'logged in → app');
  // "עוד" → יציאה
  await d.p.goto(BASE + '#/more');
  await settle(d.p);
  const out = d.p.locator('main button.use-row', { hasText: 'יציאה' });
  eq(await out.count(), 1, 'detached session: logout row in "more"');
  await shot(d.p, 'more-logout-390');
  await out.click();
  await d.p.locator('main a.login-go').waitFor({ timeout: 20000 });
  eq(await d.p.locator('main a.login-go').count(), 1, 'logout → login screen');
  eq(d.errors, [], 'no JS errors (detached)');
  await d.ctx.close();

  // ── ביטול במעטפת ──
  const c = await open('detached', 'none', { platform: 'cancel', scheme: 'dark' });
  await c.p.goto(BASE);
  await settle(c.p);
  await c.p.locator('main a.login-go').click();
  await c.p.locator('main .error').waitFor({ timeout: 20000 });
  eq(await c.p.locator('main .error').textContent(), 'הכניסה בוטלה.', 'cancelled → message');
  eq(new URL(c.p.url()).hash, '', 'the error code is removed from the address');
  await shot(c.p, 'login-cancelled-dark-390');
  await c.ctx.close();

  // ── מחוברת: אין מסך כניסה ──
  const x = await open('connected');
  await x.p.goto(BASE);
  await settle(x.p);
  eq([await x.p.locator('main a.login-go').count(), (await x.p.locator('main .error').textContent()).includes('אין לך הרשאת גישה')], [0, true],
    'connected without identity: no login button, no-access message');
  await x.ctx.close();
  const a = await open('connected', 'alice');
  await a.p.goto(BASE + '#/more');
  await settle(a.p);
  await a.p.locator('main .use-row').first().waitFor({ timeout: 20000 });
  eq(await a.p.locator('main button.use-row', { hasText: 'יציאה' }).count(), 0, 'connected (gate): no logout row');
  eq(a.errors, [], 'no JS errors (connected)');
  await a.ctx.close();
} catch (e) {
  fail++;
  console.log('✗ ' + (e.stack || e));
} finally {
  await b.close();
  srv.kill();
  rmSync(tmp, { recursive: true, force: true });
}
console.log(fail ? `\n${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
