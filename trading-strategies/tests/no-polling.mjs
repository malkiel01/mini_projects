// "בלי polling" (spec-stage-a 15, ✅13): בדפדפן, בלי פעולה של המשתמש, אין פנייה חוזרת לשרת שלנו.
// מרים php -S עם ספקים מדומים, פותח את השוק ואת הגרף, ממתין 20 שניות בלי לגעת — ובודק שאין אף בקשה ל-api.php.
// node trading-strategies/tests/no-polling.mjs   (דורש Playwright; WebSocket ל-Binance אינו פנייה לשרת שלנו)
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
// פורט פנוי מהמערכת — לא לפי ה-pid: ריצות במקביל לא נופלות על שרת של ריצה אחרת (#1333)
const port = await new Promise(r => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const RUN = `${process.pid}-${Math.random().toString(16).slice(2, 10)}`;
const db = join(tmpdir(), `ts-nopoll-${RUN}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: db, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
let fail = 0;
try {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 } });
  await ctx.addCookies([{ name: 'dev_as', value: 'alice', url: BASE }]);
  // "עוד" קורא ל-drives.ensure() פעם אחת (spec-6): לא מחובר → הפניה ל-connect.php של הענן — מדומה, חוזר "לא אושר".
  // שום דבר אחר לא יוצא לאתר החי
  const connects = [];
  await ctx.route(u => u.hostname === 'mbe-plus.com', r => {
    if (new URL(r.request().url()).pathname.endsWith('/mbe-cloud/connect.php')) { connects.push(r.request().url()); return r.fulfill({ status: 302, headers: { Location: BASE + '?cloud=denied' } }); }
    fail++; console.log('✗ live site:', r.request().url()); return r.abort();
  });
  const p = await ctx.newPage();
  for (const [name, hash, ready] of [['market', '#/market', 'main form'], ['chart', '#/chart/' + encodeURIComponent('binance:BTCUSDT'), '.chart-box canvas'], ['analysis', '#/analysis', 'main .disclaimer'], ['profile', '#/profile', 'main form'], ['alerts', '#/alerts', 'main .disclaimer'], ['more', '#/more', '[data-cloud-files]']]) {
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
  if (connects.length !== 1) { fail++; console.log(`✗ ensure(): ${connects.length} redirects to the cloud — expected exactly one (opening "עוד")`); }
  else console.log('✓ ensure(): one redirect, only when "עוד" opened');
  await b.close();
} finally {
  srv.kill();
}
process.exit(fail ? 1 : 0);
