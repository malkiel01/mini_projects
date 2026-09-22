/**
 * בדיקת ממשק בדפדפן אמיתי (Playwright + Chromium שמותקן במכונה).
 *
 * מריץ שרת PHP על תיקייה זמנית, עובר את המסלול הראשי ברוחב טלפון (390px):
 * התקנה → בית ריק → הוספת מצלמה ותיקייה → קליטת צילום "מה-FTP" → דף המצלמה
 * עם ציר הזמן → גלריה → דף ההקלטה → נושאים → גשרים → הגדרות.
 * נכשל על כל שגיאת JS, בקשת API שנכשלה שלא במכוון, או גלישה לרוחב.
 *
 * הרצה: node camera-app/tools/ui-check.mjs [תיקיית-צילומים]
 */
import { spawn, execSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, mkdirSync, rmSync, utimesSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const npmRoot = execSync('npm root -g').toString().trim();
const { chromium } = require(join(npmRoot, 'playwright'));

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const shots = process.argv[2] || join(tmpdir(), 'camera-app-shots');
mkdirSync(shots, { recursive: true });
const tmp = mkdtempSync(join(tmpdir(), 'cam-ui-'));
for (const d of ['media', 'inbox', 'live']) mkdirSync(join(tmp, d));
writeFileSync(join(tmp, 'prepend.php'), `<?php
define('DB_FILE', '${tmp}/db.sqlite'); define('MEDIA_DIR', '${tmp}/media');
define('INBOX_DIR', '${tmp}/inbox'); define('LIVE_DIR', '${tmp}/live'); define('SECRET_FILE', '${tmp}/secret.key');`);

const PORT = 8791;
const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-d', `auto_prepend_file=${tmp}/prepend.php`, '-t', root], { stdio: ['ignore', 'pipe', 'pipe'] });
let srvLog = '';
srv.stdout.on('data', (d) => { srvLog += d; }); srv.stderr.on('data', (d) => { srvLog += d; });
await new Promise((r) => setTimeout(r, 800));

const base = `http://127.0.0.1:${PORT}/`;
const problems = [];
const ok = (m) => console.log('✓ ' + m);
const fail = (m) => { problems.push(m); console.log('✗ ' + m); };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', headless: true }).catch(() => chromium.launch({ headless: true }));
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'he-IL' });
const page = await ctx.newPage();
page.on('pageerror', (e) => fail('שגיאת JS: ' + e.message));
// "Failed to load resource" הוא רעש של הדפדפן על תשובות 4xx שהאפליקציה מטפלת בהן; ה-API עצמו נבדק למטה.
page.on('console', (m) => { if (m.type() === 'error' && !/hls|cdn\.jsdelivr|net::ERR|Failed to load resource/.test(m.text())) fail('console.error: ' + m.text()); });
const expected4xx = new Set(['record']);   // REC בלי גשר מחזיר 409 בכוונה
page.on('response', async (r) => {
  const u = r.url();
  if (!u.includes('api.php')) return;
  const action = new URL(u).searchParams.get('action');
  if (r.status() >= 500) fail(`API ${r.status()} ${action}`);
  else if (r.status() >= 400 && !expected4xx.has(action)) fail(`API ${r.status()} ${action}: ${(await r.text().catch(() => '')).slice(0, 120)}`);
});

const shot = async (name) => { await page.screenshot({ path: join(shots, name + '.png'), fullPage: true }); };
const noOverflow = async (name) => {
  const w = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth));
  if (w > 390) fail(`${name}: גלישה לרוחב (${w}px)`); else ok(`${name}: בלי גלישה לרוחב`);
};

try {
  await page.goto(base + 'index.html');
  await page.waitForSelector('#setup-form:not([hidden])', { timeout: 5000 });
  ok('מסך ההתקנה מוצג כשאין משתמשים');
  await shot('01-setup');
  await page.fill('#setup-form [name=username]', 'admin');
  await page.fill('#setup-form [name=display_name]', 'מלכיאל');
  await page.fill('#setup-form [name=password]', 'secret123');
  await page.click('#setup-form button[type=submit]');
  await page.waitForSelector('#app:not([hidden])', { timeout: 5000 });
  await page.waitForSelector('.empty');
  ok('אחרי ההתקנה: הבית ריק עם הנחיה');
  await noOverflow('בית ריק');
  await shot('02-home-empty');

  // מצלמה חדשה דרך הטופס.
  await page.click('[data-add]');
  await page.waitForSelector('.modal');
  await page.fill('[data-cam] [name=name]', 'חצר אחורית');
  await page.click('[data-t="conn"]');
  await page.fill('[data-cam] [name=host]', '192.168.1.118');
  await page.fill('[data-cam] [name=password]', 'pw123');
  await page.click('[data-t="rec"]');
  await page.click('[data-cam] [name=record_mode][value=schedule]');
  await page.click('[data-addwin]');
  await page.click('[data-t="ftp"]');
  await shot('03-camera-form-ftp');
  await page.click('.modal [data-act="1"]');
  await page.waitForSelector('.cam', { timeout: 5000 });
  ok('המצלמה נוצרה ומופיעה בבית');
  await noOverflow('בית עם מצלמה');
  await shot('04-home-camera');

  // צילום "מה-FTP": קובץ בתיבה, ואז סריקה.
  const jpg = Buffer.from('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==', 'base64');
  mkdirSync(join(tmp, 'inbox', 'cam-1'), { recursive: true });
  const il = new Date(Date.now() - 5 * 60 * 1000 + 180 * 60 * 1000);   // שעון ישראל (קיץ), כמו שהמצלמה כותבת בשם הקובץ
  const p2 = (n) => String(n).padStart(2, '0');
  const stamp = il.getUTCFullYear() + p2(il.getUTCMonth() + 1) + p2(il.getUTCDate()) + p2(il.getUTCHours()) + p2(il.getUTCMinutes()) + '00';
  const f = join(tmp, 'inbox', 'cam-1', `Camera1_00_${stamp}_PEOPLE.jpg`);
  writeFileSync(f, jpg);
  const old = new Date(Date.now() - 120000); utimesSync(f, old, old);
  const m = await page.evaluate(async () => (await (await fetch('api.php?action=maintenance', { method: 'POST', body: '{}' })).json()));
  if (m.result?.inbox?.ingested === 1) ok('הצילום נקלט מהתיבה'); else fail('הקליטה לא עבדה: ' + JSON.stringify(m));

  // דף המצלמה.
  await page.click('.cam');
  await page.waitForSelector('[data-tl]');
  await page.waitForSelector('.tl__seg', { timeout: 5000 });
  ok('דף המצלמה: ציר הזמן עם מקטע');
  const overlay = await page.textContent('.player__overlay');
  if (/אין שידור חי/.test(overlay || '')) ok('בלי גשר: הסבר במקום שידור'); else fail('חסר הסבר על היעדר גשר: ' + overlay);
  await noOverflow('דף מצלמה');
  await shot('05-camera-page');
  await page.click('.tl__seg');
  await page.waitForSelector('[data-backlive]:not([hidden])');
  ok('לחיצה על מקטע מנגנת אותו, עם "חזרה לחי"');
  await shot('06-camera-playing');

  // גלריה ודף הקלטה.
  await page.goto(base + 'index.html#/recordings');
  await page.waitForSelector('.rec', { timeout: 5000 });
  ok('גלריה עם כרטיס');
  await page.click('[data-selmode]');
  await page.waitForSelector('.bulkbar:not([hidden])');
  ok('מצב בחירה מציג סרגל פעולות');
  await noOverflow('גלריה');
  await shot('07-gallery');
  await page.click('[data-b="done"]');
  await page.click('.rec');
  await page.waitForSelector('[data-meta]');
  await page.fill('[data-meta] [name=title]', 'חתול בחצר');
  await page.fill('[data-meta] [name=tags]', 'לילה, חתול');
  await page.click('[data-meta] button[type=submit]');
  await page.waitForSelector('.toast--ok');
  ok('דף ההקלטה: עריכת כותרת ותגיות נשמרה');
  await noOverflow('דף הקלטה');
  await shot('08-recording');

  // נושאים.
  await page.goto(base + 'index.html#/topics');
  await page.click('[data-add]');
  await page.fill('.modal [name=name]', 'שיפוץ');
  await page.click('.modal [data-act="1"]');
  await page.waitForSelector('.tree');
  ok('נושא נוצר');
  await shot('09-topics');

  // גשרים.
  await page.goto(base + 'index.html#/bridges');
  await page.waitForSelector('[data-add]');
  await page.click('[data-add]');
  await page.fill('.modal [name=name]', 'בית');
  await page.click('.modal [data-act="1"]');
  await page.waitForSelector('.paircode', { timeout: 5000 });
  ok('גשר נוצר עם קוד צימוד והוראות התקנה');
  await noOverflow('גשרים');
  await shot('10-bridges');
  await page.keyboard.press('Escape');

  // הגדרות — ארבע לשוניות.
  await page.goto(base + 'index.html#/settings');
  await page.waitForSelector('[data-tab="users"]');
  for (const t of ['users', 'folders', 'storage', 'system']) {
    await page.click(`[data-tab="${t}"]`);
    await page.waitForFunction(() => document.querySelector('[data-pane]').children.length > 0);
    await page.waitForTimeout(150);
    await noOverflow('הגדרות/' + t);
  }
  await shot('11-settings-system');
  await page.click('[data-tab="folders"]');
  await page.click('[data-pane] [data-add]');
  await page.fill('.modal [name=name]', 'בית');
  await page.click('.modal [data-act="1"]');
  await page.waitForSelector('.tree');
  ok('תיקיית מצלמות נוצרה');

  // אירועים.
  await page.goto(base + 'index.html#/events');
  await page.waitForSelector('.ev');
  ok('יומן אירועים מציג שורות');
  await shot('12-events');

  // יציאה וכניסה.
  await page.click('#logout');
  await page.waitForSelector('#login-form:not([hidden])');
  await page.fill('#login-form [name=username]', 'admin');
  await page.fill('#login-form [name=password]', 'secret123');
  await page.click('#login-form button[type=submit]');
  await page.waitForSelector('#app:not([hidden])');
  ok('יציאה וכניסה מחדש');

  // מסך רחב — פריסה דו-טורית של דף המצלמה.
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(base + 'index.html#/camera/1');
  await page.waitForSelector('[data-tl]');
  await page.waitForTimeout(300);
  await page.screenshot({ path: join(shots, '13-camera-desktop.png'), fullPage: true });
  ok('מסך רחב');
} catch (e) {
  fail('חריגה: ' + e.message);
  await shot('99-failure').catch(() => {});
} finally {
  await browser.close();
  srv.kill();
  rmSync(tmp, { recursive: true, force: true });
}
const phpWarn = srvLog.split('\n').filter((l) => /PHP (Warning|Fatal|Notice|Deprecated)/.test(l));
if (phpWarn.length) fail('אזהרות PHP:\n' + phpWarn.join('\n'));
console.log(problems.length ? `\n${problems.length} בעיות` : `\nהכול עבר. צילומים ב-${shots}`);
process.exit(problems.length ? 1 : 0);
