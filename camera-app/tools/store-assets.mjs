/**
 * מייצר את כל התמונות של ההתקנה: אייקוני ה-PWA, הסמלים ומסך הפתיחה של אפליקציית האנדרואיד,
 * ותיקיית החנות — סמל 512, באנר 1024×500 וצילומי מסך (1080×1920) של האפליקציה עם נתוני הדגמה.
 *
 * הכול נגזר מ-assets/icon.svg. רץ ידנית, בפיתוח — התוצאות בגיט:
 *   node camera-app/tools/store-assets.mjs
 *
 * צילומי המסך: שרת PHP על תיקייה זמנית (כמו ui-check.mjs), ברוחב טלפון — 390px. "צילומי המצלמות"
 * הם איורים שנוצרים כאן (חצר, כניסה, חניה), עם חותמת זמן כמו של מצלמה — לא תמונות אמיתיות.
 */
import { spawn, execSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, mkdirSync, rmSync, utimesSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(join(execSync('npm root -g').toString().trim(), 'playwright'));

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const BG = '#0b1220';

// הסמל בלי הרקע — המצלמה עצמה, במרכז (33,33) של ריבוע 64.
const GLYPH = `
  <rect x="10" y="20" width="36" height="26" rx="5" fill="#f59e0b"/>
  <path d="M46 28 L56 22 V44 L46 38 Z" fill="#f59e0b"/>
  <circle cx="28" cy="33" r="8" fill="${BG}"/>
  <circle cx="28" cy="33" r="4" fill="#38bdf8"/>
  <circle cx="16" cy="25" r="2" fill="#ef4444"/>`;
// scale — החלק מהרוחב שהמצלמה תופסת (רוחבה 46 מתוך 64). shape: square | rounded | circle | none
const icon = (scale, shape) => {
  const s = (scale * 64) / 46;
  const bg = { square: `<rect width="64" height="64" fill="${BG}"/>`, rounded: `<rect width="64" height="64" rx="14" fill="${BG}"/>`,
    circle: `<circle cx="32" cy="32" r="32" fill="${BG}"/>`, none: '' }[shape];
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">${bg}<g transform="translate(32 32) scale(${s}) translate(-33 -33)">${GLYPH}</g></svg>`;
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', headless: true }).catch(() => chromium.launch({ headless: true }));
const page = await browser.newPage();

async function png(svg, size, file) {
  await page.setViewportSize({ width: size, height: size });
  await page.setContent(`<body style="margin:0;background:transparent">${svg.replace('<svg ', `<svg width="${size}" height="${size}" `)}</body>`);
  mkdirSync(dirname(file), { recursive: true });
  await page.screenshot({ path: file, omitBackground: true, clip: { x: 0, y: 0, width: size, height: size } });
}

// PWA. maskable — המצלמה בתוך אזור הבטחון (80% המרכזיים), רקע מלא.
await png(readFileSync(join(root, 'assets/icon.svg'), 'utf8'), 192, join(root, 'assets/icons/icon-192.png'));
await png(readFileSync(join(root, 'assets/icon.svg'), 'utf8'), 512, join(root, 'assets/icons/icon-512.png'));
await png(icon(0.6, 'square'), 512, join(root, 'assets/icons/icon-maskable-512.png'));

// אנדרואיד: סמל רגיל, עגול, וחזית לסמל המותאם (108dp, אזור בטחון 66dp במרכז).
const res = join(root, 'android/app/src/main/res');
for (const [dpi, n] of Object.entries({ mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 })) {
  await png(icon(0.78, 'rounded'), n, join(res, `mipmap-${dpi}/ic_launcher.png`));
  await png(icon(0.7, 'circle'), n, join(res, `mipmap-${dpi}/ic_launcher_round.png`));
  await png(icon(0.5, 'none'), n * 2.25, join(res, `mipmap-${dpi}/ic_launcher_foreground.png`));
}
await png(icon(0.6, 'none'), 512, join(res, 'drawable-nodpi/splash.png'));

// החנות: סמל 512 מלא (גוגל מעגלת בעצמה), ובאנר.
const store = join(root, 'android/store');
await png(icon(0.72, 'square'), 512, join(store, 'icon-512.png'));
await page.setViewportSize({ width: 1024, height: 500 });
await page.setContent(`<body style="margin:0"><div dir="rtl" style="width:1024px;height:500px;box-sizing:border-box;padding:0 80px;
  display:flex;align-items:center;gap:56px;font-family:'DejaVu Sans',sans-serif;color:#e6edf7;
  background:radial-gradient(circle at 20% 50%,#1b2740 0,${BG} 60%)">
  <div><div style="font-size:84px;font-weight:700;line-height:1">מצלמות</div>
    <div style="font-size:30px;color:#8b9bb4;margin-top:22px;line-height:1.45">שידור חי, הקלטות וציר זמן<br>מכל מצלמות ה-IP שלך — בטלפון</div></div>
  <div style="margin-inline-start:auto">${icon(0.78, 'rounded').replace('<svg ', '<svg width="300" height="300" ')}</div></div></body>`);
await page.screenshot({ path: join(store, 'feature-1024x500.png'), clip: { x: 0, y: 0, width: 1024, height: 500 } });
console.log('✓ סמלים ובאנר');

/* ───────── צילומי מסך ───────── */

// "צילום מצלמה" — איור 1280×720 עם חותמת זמן, כמו בפינה של מצלמה אמיתית.
async function scene(kind, when, file, person) {
  const night = when.getHours() < 6 || when.getHours() >= 19;
  const sky = night ? ['#0b1a33', '#1d2f52'] : ['#8ec5ea', '#d7ecf8'];
  const ground = night ? '#1f3324' : '#5e9b4c';
  const p2 = (n) => String(n).padStart(2, '0');
  const stamp = `${when.getFullYear()}-${p2(when.getMonth() + 1)}-${p2(when.getDate())} ${p2(when.getHours())}:${p2(when.getMinutes())}:${p2(when.getSeconds())}`;
  const parts = {
    yard: `<rect x="0" y="380" width="1280" height="340" fill="${ground}"/>
      <rect x="760" y="200" width="420" height="260" fill="${night ? '#3a3f4a' : '#e8dcc6'}"/><path d="M730 210 L970 90 L1210 210 Z" fill="#a0523a"/>
      <rect x="860" y="300" width="80" height="160" fill="#6b4a2f"/><rect x="1000" y="270" width="110" height="80" fill="${night ? '#f6d36b' : '#9cc7e6'}"/>
      <circle cx="220" cy="300" r="110" fill="#2f6b34"/><rect x="205" y="380" width="30" height="110" fill="#5a3e28"/>
      <rect x="0" y="470" width="1280" height="14" fill="#c9b38d"/>`,
    entrance: `<rect x="0" y="0" width="1280" height="720" fill="${night ? '#2a2f3a' : '#d9d4ca'}"/>
      <rect x="480" y="120" width="320" height="520" fill="#5b3b24"/><circle cx="760" cy="400" r="12" fill="#e8c35a"/>
      <rect x="0" y="600" width="1280" height="120" fill="${night ? '#3b3b3b' : '#9a9a9a'}"/>
      <rect x="140" y="220" width="200" height="200" fill="${night ? '#f6d36b' : '#a9cfe8'}"/><rect x="940" y="220" width="200" height="200" fill="${night ? '#f6d36b' : '#a9cfe8'}"/>`,
    parking: `<rect x="0" y="330" width="1280" height="390" fill="${night ? '#2c2c30' : '#7d7f84'}"/>
      ${[160, 480, 800, 1120].map((x) => `<rect x="${x}" y="420" width="12" height="300" fill="#e9e6da"/>`).join('')}
      <rect x="220" y="470" width="220" height="110" rx="24" fill="#c0392b"/><rect x="250" y="430" width="160" height="60" rx="16" fill="#9fc4de"/>
      <circle cx="265" cy="585" r="26" fill="#111"/><circle cx="395" cy="585" r="26" fill="#111"/>
      <rect x="860" y="470" width="220" height="110" rx="24" fill="#e5e7eb"/><rect x="890" y="430" width="160" height="60" rx="16" fill="#9fc4de"/>
      <circle cx="905" cy="585" r="26" fill="#111"/><circle cx="1035" cy="585" r="26" fill="#111"/>`,
  }[kind];
  const man = person ? `<g transform="translate(${person} 330)"><circle cx="0" cy="0" r="32" fill="#e0b48a"/>
      <rect x="-40" y="36" width="80" height="150" rx="20" fill="#2f5d8a"/><rect x="-34" y="186" width="28" height="110" fill="#333"/><rect x="6" y="186" width="28" height="110" fill="#333"/></g>` : '';
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(`<body style="margin:0"><svg width="1280" height="720" xmlns="http://www.w3.org/2000/svg">
    <defs><linearGradient id="s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${sky[0]}"/><stop offset="1" stop-color="${sky[1]}"/></linearGradient></defs>
    <rect width="1280" height="720" fill="url(#s)"/>${parts}${man}
    <rect width="1280" height="720" fill="${night ? 'rgba(20,40,30,.35)' : 'rgba(0,0,0,0)'}"/>
    <text x="24" y="48" font-family="DejaVu Sans Mono,monospace" font-size="30" fill="#fff" stroke="#000" stroke-width="1">${stamp}</text>
    </svg></body>`);
  await page.screenshot({ path: file, type: 'jpeg', quality: 82, clip: { x: 0, y: 0, width: 1280, height: 720 } });
}

const tmp = mkdtempSync(join(tmpdir(), 'cam-store-'));
for (const d of ['media', 'inbox', 'live']) mkdirSync(join(tmp, d));
writeFileSync(join(tmp, 'prepend.php'), `<?php
define('DB_FILE', '${tmp}/db.sqlite'); define('MEDIA_DIR', '${tmp}/media');
define('INBOX_DIR', '${tmp}/inbox'); define('LIVE_DIR', '${tmp}/live'); define('SECRET_FILE', '${tmp}/secret.key');`);
const PORT = 8793;
const srv = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-d', `auto_prepend_file=${tmp}/prepend.php`, '-t', root], { stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 800));
const base = `http://127.0.0.1:${PORT}/`;

// 390px ברוחב, ו-1080×1920 בקובץ — היחס שגוגל מבקשת לצילומי טלפון.
const ctx = await browser.newContext({ viewport: { width: 390, height: 693 }, deviceScaleFactor: 1080 / 390, locale: 'he-IL', timezoneId: 'Asia/Jerusalem' });
const app = await ctx.newPage();
const shots = join(store, 'screenshots');
mkdirSync(shots, { recursive: true });
const shot = (name) => app.screenshot({ path: join(shots, name + '.png') });
const api = (action, body) => app.evaluate(async ([a, b]) => (await (await fetch('api.php?action=' + a, { method: 'POST', body: JSON.stringify(b || {}) })).json()), [action, body]);

try {
  await app.goto(base + 'index.html');
  await app.waitForSelector('#setup-form:not([hidden])');
  await app.fill('#setup-form [name=username]', 'admin');
  await app.fill('#setup-form [name=display_name]', 'מלכיאל');
  await app.fill('#setup-form [name=password]', 'secret123');
  await app.click('#setup-form button[type=submit]');
  await app.waitForSelector('#app:not([hidden])');

  // שלוש מצלמות, דרך הטופס כמו משתמש.
  for (const [name, host] of [['חצר אחורית', '192.168.1.118'], ['כניסה ראשית', '192.168.1.119'], ['חניה', '192.168.1.120']]) {
    await app.click('[data-add]');
    await app.waitForSelector('.modal');
    await app.fill('[data-cam] [name=name]', name);
    await app.click('[data-t="conn"]');
    await app.fill('[data-cam] [name=host]', host);
    await app.fill('[data-cam] [name=password]', 'pw123');
    await app.click('.modal [data-act="1"]');
    await app.waitForSelector('.modal', { state: 'detached' });
  }

  // צילומים "מה-FTP" לאורך היום: שם הקובץ בשעון ישראל, כמו שהמצלמה כותבת.
  const kinds = { 1: 'yard', 2: 'entrance', 3: 'parking' };
  const p2 = (n) => String(n).padStart(2, '0');
  const now = Date.now();
  let k = 0;
  for (const [cam, minsAgo, trig, person] of [
    [1, 20, 'PEOPLE', 520], [1, 95, 'MD', 0], [1, 210, 'PEOPLE', 420], [1, 420, 'MD', 0], [1, 610, 'PEOPLE', 600],
    [2, 35, 'PEOPLE', 640], [2, 160, 'PEOPLE', 560], [2, 380, 'MD', 0],
    [3, 50, 'VEHICLE', 0], [3, 260, 'PEOPLE', 640], [3, 500, 'VEHICLE', 0],
  ]) {
    const t = new Date(now - minsAgo * 60000);
    const il = new Date(t.toLocaleString('en-US', { timeZone: 'Asia/Jerusalem' }));
    const stamp = il.getFullYear() + p2(il.getMonth() + 1) + p2(il.getDate()) + p2(il.getHours()) + p2(il.getMinutes()) + p2(il.getSeconds());
    mkdirSync(join(tmp, 'inbox', 'cam-' + cam), { recursive: true });
    const f = join(tmp, 'inbox', 'cam-' + cam, `Camera${cam}_00_${stamp}_${trig}.jpg`);
    await scene(kinds[cam], il, f, person);
    const old = new Date(now - 120000 - k++ * 1000); utimesSync(f, old, old);
  }
  const m = await api('maintenance');
  console.log('נקלטו', m.result?.inbox?.ingested);

  await app.goto(base + 'index.html#/');
  await app.reload();
  await app.waitForSelector('.cam');
  await app.waitForTimeout(800);
  await shot('1-cameras');

  await app.click('.cam');
  await app.waitForSelector('.tl__seg');
  await app.click('.tl__seg >> nth=0');
  await app.waitForSelector('[data-backlive]:not([hidden])');
  await app.waitForTimeout(800);
  await shot('2-timeline');

  await app.goto(base + 'index.html#/recordings');
  await app.waitForSelector('.rec');
  await app.waitForTimeout(800);
  await shot('3-recordings');

  await app.click('.rec');
  await app.waitForSelector('[data-meta]');
  await app.fill('[data-meta] [name=title]', 'שליח בכניסה');
  await app.fill('[data-meta] [name=tags]', 'משלוח, צהריים');
  await app.click('[data-meta] button[type=submit]');
  await app.waitForSelector('.toast--ok');
  await app.waitForSelector('.toast--ok', { state: 'detached', timeout: 8000 }).catch(() => {});
  await app.evaluate(() => window.scrollTo(0, 0));
  await app.waitForTimeout(500);
  await shot('4-recording');

  await app.goto(base + 'index.html#/topics');
  for (const name of ['שיפוץ המטבח', 'משלוחים', 'ציפורים בחצר']) {
    await app.click('[data-add]');
    await app.fill('.modal [name=name]', name);
    await app.click('.modal [data-act="1"]');
    await app.waitForSelector('.modal', { state: 'detached' });
  }
  await app.goto(base + 'index.html#/events');
  await app.reload();   // שינוי hash בלבד משאיר לרגע את התצוגה הקודמת
  await app.waitForSelector('.ev');
  await app.waitForTimeout(500);
  await shot('5-events');
  console.log('✓ צילומי מסך ב-' + shots);
} finally {
  await browser.close();
  srv.kill();
  rmSync(tmp, { recursive: true, force: true });
}
