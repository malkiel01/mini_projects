// "בלי polling" (spec-stage-a 15, ✅13): בדפדפן, בלי פעולה של המשתמש, אין פנייה חוזרת לשרת שלנו.
// מרים php -S עם ספקים מדומים, פותח את השוק ואת הגרף, ממתין 20 שניות בלי לגעת — ובודק שאין אף בקשה ל-api.php.
// node trading-strategies/tests/no-polling.mjs   (דורש Playwright; WebSocket ל-Binance אינו פנייה לשרת שלנו)
import { createRequire } from 'module';
import { spawn } from 'child_process';
import { tmpdir } from 'os';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const port = 19500 + (process.pid % 400);
const db = join(tmpdir(), `ts-nopoll-${process.pid}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: db, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
let fail = 0;
try {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 } });
  await ctx.addCookies([{ name: 'dev_as', value: 'alice', url: BASE }]);
  const p = await ctx.newPage();
  for (const [name, hash, ready] of [['market', '#/market', 'main form'], ['chart', '#/chart/' + encodeURIComponent('binance:BTCUSDT'), '.chart-box canvas']]) {
    await p.goto(BASE + hash);
    await p.waitForSelector(ready);
    await p.waitForTimeout(1500);                       // הטעינה עצמה נגמרת
    const seen = [];
    const onReq = r => { if (r.url().includes('/api.php')) seen.push(r.url()); };
    p.on('request', onReq);
    await p.waitForTimeout(20000);                      // 20 שניות בלי פעולה
    p.off('request', onReq);
    if (seen.length) { fail++; console.log(`✗ ${name}: ${seen.length} requests to our server without user action`, seen.slice(0, 3)); }
    else console.log(`✓ ${name}: no requests in 20s idle`);
  }
  await b.close();
} finally {
  srv.kill();
}
process.exit(fail ? 1 : 0);
