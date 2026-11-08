// ציור על הגרף בדפדפן אמיתי (spec-stage-a פרק 7 + פרק 1: לחיצה ארוכה במגע). ספקים מדומים, בלי רשת.
// node trading-strategies/tests/drawings-ui.mjs   (דורש Playwright)
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
import { createServer } from 'net';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium, devices } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
// פורט פנוי מהמערכת — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת של ריצה אחרת (#1333)
const port = await new Promise(r => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const RUN = `${process.pid}-${Math.random().toString(16).slice(2, 10)}`;
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: join(tmpdir(), `ts-draw-ui-${RUN}.sqlite`), TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const CHART = BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT');
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];

const b = await chromium.launch();
async function open(as, opts = { viewport: { width: 1100, height: 900 } }) {
  const ctx = await b.newContext(opts);
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  // הנרות מספק מדומה — ה-WebSocket החי ל-Binance האמיתי היה מערבב מחיר אמיתי ומשנה את סקאלת המחיר באמצע הבדיקה
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  await p.goto(CHART);
  await p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 20000 });
  return { p, ctx };
}
const list = p => p.evaluate(async () => (await (await fetch('api.php?action=drawings&asset=binance%3ABTCUSDT')).json()).drawings);
const plot = async p => { const r = await p.locator('.chart-box').first().boundingBox(); return { x: r.x, y: r.y, w: r.width - 70, h: r.height - 30 }; };
const settle = p => p.waitForTimeout(500);

try {
  const { p } = await open('alice');
  const R = await plot(p);
  await p.click('[aria-label="ציור על הגרף"]');
  eq(await p.isVisible('.draw-bar'), true, 'the floating toolbar opens');

  // קו מגמה — שתי לחיצות
  await p.click('.draw-btn[aria-label="קו מגמה"]');
  await p.mouse.click(R.x + R.w * 0.3, R.y + R.h * 0.3);
  await p.mouse.click(R.x + R.w * 0.6, R.y + R.h * 0.6);
  await settle(p);
  let d = await list(p);
  eq(d.map(x => [x.kind, x.points.length, x.tf]), [['trend', 2, null]], 'trend line saved after two clicks');

  // קו אופקי — לחיצה אחת
  await p.click('.draw-btn[aria-label="קו אופקי"]');
  await p.mouse.click(R.x + R.w * 0.5, R.y + R.h * 0.8);
  await settle(p);
  d = await list(p);
  eq(d.map(x => x.kind), ['trend', 'hline'], 'horizontal line saved after one click');
  const hline = d[1];

  // בחירה וגרירה של הקו האופקי — נשמר בסוף, עם מחיר חדש
  await p.click('.draw-btn[aria-label="בחירה והזזה"]');
  const yLine = await p.evaluate(() => 0) || R.y + R.h * 0.8;
  await p.mouse.move(R.x + R.w * 0.5, yLine);
  await p.mouse.down();
  await p.mouse.move(R.x + R.w * 0.5, yLine - 60, { steps: 6 });
  await p.mouse.up();
  await settle(p);
  d = await list(p);
  eq(d[1].points[0].p > hline.points[0].p, true, 'dragging the horizontal line up saves a higher price');

  // מחיקה, ביטול ושחזור
  const before = await list(p);
  await p.click('.draw-btn[aria-label="מחיקת השרטוט"]');
  await settle(p);
  eq((await list(p)).length, 1, 'delete the selected drawing');
  await p.click('.draw-btn[aria-label="ביטול"]');
  await settle(p);
  d = await list(p);
  eq([d.length, d[1].kind, d[1].points[0].p], [2, 'hline', before[1].points[0].p], 'undo brings it back (same price)');
  await p.click('.draw-btn[aria-label="שחזור"]');
  await settle(p);
  eq((await list(p)).length, 1, 'redo deletes again');
  await p.click('.draw-btn[aria-label="ביטול"]');
  await settle(p);
  eq((await list(p)).length, 2, 'undo once more');

  // "רק במסגרת הזו" על השרטוט הנבחר
  await p.click('.draw-btn[aria-label="בחירה והזזה"]');
  await p.mouse.click(R.x + R.w * 0.45, R.y + R.h * 0.45);                // באמצע קו המגמה
  await settle(p);
  await p.click('.draw-only input');
  await settle(p);
  d = await list(p);
  eq(d.find(x => x.kind === 'trend').tf, 'h1', '"only this timeframe" saved on the selected drawing');

  // אחרי רענון — השרטוטים שם
  await p.reload();
  await p.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 20000 });
  await p.click('[aria-label="ציור על הגרף"]');
  eq(await p.textContent('.draw-hint').then(t => /2 שרטוטים/.test(t)), true, 'after reload — both drawings loaded');

  // במצב ציור, בלי פעולה — אין פנייה לשרת
  const seen = [];
  p.on('request', r => { if (r.url().includes('/api.php')) seen.push(r.url()); });
  await p.waitForTimeout(8000);
  eq(seen.length, 0, 'no requests while idle in drawing mode');

  // משתמש אחר — לא רואה
  const { p: pb } = await open('bob');
  eq((await list(pb)).length, 0, 'bob sees none of alice\'s drawings');

  // טלפון: לחיצה ארוכה מניחה נקודה (גרירה רגילה — מזיזה את הגרף)
  const { p: pm, ctx: cm } = await open('carol', { ...devices['Pixel 7'] });
  const M = await plot(pm);
  await pm.click('[aria-label="ציור על הגרף"]');
  await pm.click('.draw-btn[aria-label="קו אופקי"]');
  const cdp = await cm.newCDPSession(pm);
  const touch = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] });
  const tx = M.x + M.w * 0.5, ty = M.y + M.h * 0.5;
  await touch('touchStart', tx, ty); await pm.waitForTimeout(150); await touch('touchEnd', tx, ty);
  await settle(pm);
  eq((await list(pm)).length, 0, 'a short tap does not place a point');
  await touch('touchStart', tx, ty); await pm.waitForTimeout(700); await touch('touchEnd', tx, ty);
  await settle(pm);
  eq((await list(pm)).map(x => x.kind), ['hline'], 'a long press places the horizontal line');
  // קו מגמה במגע: לחיצה ארוכה, גרירה, שחרור
  await pm.click('.draw-btn[aria-label="קו מגמה"]');
  await touch('touchStart', tx - 60, ty - 40); await pm.waitForTimeout(700);
  for (let i = 1; i <= 5; i++) { await touch('touchMove', tx - 60 + i * 20, ty - 40 + i * 15); await pm.waitForTimeout(30); }
  await touch('touchEnd', tx + 40, ty + 35);
  await settle(pm);
  eq((await list(pm)).map(x => x.kind), ['hline', 'trend'], 'long press + drag + release draws a trend line');
  await pm.screenshot({ path: join(tmpdir(), 'ts-drawings-phone.png') });

  await p.screenshot({ path: join(tmpdir(), 'ts-drawings-desktop.png') });
  eq(errors, [], 'no page errors');
} finally {
  await b.close();
  srv.kill();
}
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
