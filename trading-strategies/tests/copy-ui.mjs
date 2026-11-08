// העותק האישי בענן בדפדפן (spec-stage-a 3.7, ✅14): "🧪 הרץ על העותק שלי" בניתוח, התוצאה לצד הרגילה, בחירת השקלול,
// ותצוגה מהעותק בגרף (טווח ישן מ-60 יום — מהעותק, לפני פנייה לספק). טלפון 390px, RTL. הענן — מדומה, בלי רשת.
// node trading-strategies/tests/copy-ui.mjs   (Playwright)   SHOTS=<תיקייה> — צילומי מסך
import { createRequire } from 'module';
import { spawn, spawnSync } from 'child_process';
import { tmpdir } from 'os';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const port = 24900 + (process.pid % 90);
const DB = join(tmpdir(), `ts-copy-ui-${process.pid}.sqlite`);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', root, '-d', `auto_prepend_file=${join(here, 'dev-prepend.php')}`],
  { env: { ...process.env, TS_DB_PATH: DB, TS_HTTP_FIXTURES: join(here, 'fixtures', 'market'),
    TS_TEST_METHODS: join(here, 'fixtures', 'methods', 'alertable.php') }, stdio: 'ignore' });
await new Promise(r => setTimeout(r, 1000));
const BASE = `http://127.0.0.1:${port}/trading-strategies/`;
const SHOTS = process.env.SHOTS;
let fail = 0, n = 0;
const eq = (got, want, what) => { n++; if (JSON.stringify(got) !== JSON.stringify(want)) { fail++; console.log(`✗ ${what}: got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`); } };
const errors = [];
const b = await chromium.launch();
async function open(as) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, hasTouch: true, isMobile: true, deviceScaleFactor: SHOTS ? 2 : 1 });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  // שום ניווט או בקשה לא יוצאים לאתר החי (חוץ מה-API המדומה של הענן)
  await ctx.route(u => u.hostname === 'mbe-plus.com' && !/\/mbe-cloud\/api\.php/.test(u.pathname), r => { errors.push('live site: ' + r.request().url()); return r.abort(); });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  return p;
}
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);

// ── הכונן המדומה של אליס: כבר יש בו עותק של BTCUSDT h1, מלפני 400 יום עד לפני 100 יום ──
const H = 3600;
const NOW = Math.floor(Date.now() / 1000);
const FROM = Math.floor((NOW - 400 * 86400) / H) * H;
const TO = Math.floor((NOW - 100 * 86400) / H) * H;
let csv = '# provider=binance; symbol=BTCUSDT; tf=h1; saved=2026-11-08T10:00:00Z\nt,o,h,l,c,v\n';
for (let t = FROM; t <= TO; t += H) { const p = 100 + Math.floor(t / H) % 50; csv += `${t},${p}.10000000,${p + 1}.00000000,${p - 1}.00000000,${p}.50000000,12.5\n`; }
const COPY_N = (TO - FROM) / H + 1;
const files = new Map();
let seq = 0;
const node = o => { const x = { id: 'n_' + (++seq), rev: 1, created_at: '2026-11-08 09:00:00', updated_at: '2026-11-08 09:00:00', ...o }; files.set(x.id, x); return x; };
let parent = null;
for (const name of ['אסטרטגיות למסחר', 'נתונים', 'binance', 'BTCUSDT']) parent = node({ kind: 'folder', name, parent }).id;
node({ kind: 'file', name: 'h1.csv', parent, size: csv.length, content: csv });
const reads = [];
async function mockCloud(route) {
  const req = route.request();
  const cors = { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': '*', 'Access-Control-Allow-Methods': 'GET,POST,PUT,OPTIONS' };
  if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
  const u = new URL(req.url());
  const a = u.searchParams.get('action');
  const json = (o, s = 200) => route.fulfill({ status: s, headers: { ...cors, 'Content-Type': 'application/json' }, body: JSON.stringify(o) });
  if (req.headers()['x-cloud-token'] !== 'tok-for-c_101') return json({ error: 'אסימון לא תקין', code: 'bad_token' }, 401);
  const pub = ({ content, ...x }) => x;
  if (a === 'list') {
    const folder = u.searchParams.get('folder') || null;
    return json({ folder, path: [], items: [...files.values()].filter(x => x.parent === folder).map(pub) });
  }
  if (a === 'read') { reads.push(u.searchParams.get('id')); return route.fulfill({ status: 200, headers: cors, body: files.get(u.searchParams.get('id')).content }); }
  return json({ error: 'לא מוכר', code: 'not_found' }, 404);
}
const connect = sub => spawnSync('php', ['-r', `$d = new PDO('sqlite:${DB}.drives/drives.sqlite'); $u = (new PDO('sqlite:${DB}'))->query("SELECT id FROM users WHERE platform_sub = '${sub}'")->fetchColumn();
    $d->prepare("INSERT OR REPLACE INTO conns (user, connection, name, cloud_id, scope, created_at, sub) VALUES (?, 'c_${sub}', 'אליס', 'cloud-1', '[\\"files\\"]', '2026-11-08', '${sub}')")->execute([(string)$u]);`]);
const ASSET = 'binance:BTCUSDT';

try {
  const o = await open('owner');
  await o.goto(BASE + '#/more');
  await o.waitForSelector('main');
  for (const id of ['test-cross-a', 'test-cross-b']) await post(o, 'admin_method_save', { id, enabled: true, markets: ['crypto'], timeframes: ['h1', 'h4'] });

  // ── בלי חיבור לענן: אין כפתור, והבדיקה הרגילה עובדת ──
  const a = await open('alice');
  await a.goto(BASE + '#/analysis/' + encodeURIComponent(ASSET));
  await a.getByRole('button', { name: '🧭 נתח' }).click();
  await a.waitForSelector('.analysis-card', { timeout: 30000 });
  await a.waitForTimeout(500);
  eq([await a.getByRole('button', { name: '🧪 הרץ על העותק שלי' }).count(), await a.locator('.analysis-card .summary').count() >= 2],
    [0, true], 'not connected → no "🧪" button, and the regular analysis works');

  // ── מחובר, ויש עותק: "🧪 הרץ על העותק שלי" ──
  connect('101');
  await a.context().route('https://mbe-plus.com/mini_projects/mbe-cloud/api.php**', mockCloud);
  await a.reload();
  await a.getByRole('button', { name: '🧭 נתח' }).click();
  const run = a.getByRole('button', { name: '🧪 הרץ על העותק שלי' });
  await run.waitFor({ timeout: 30000 });
  eq(await a.locator('.summary.copy').count(), 0, 'before the run: only the regular result');
  const posted = [];
  a.on('request', r => { if (/action=analysis_copy\b/.test(r.url()) && r.method() === 'POST') posted.push(r.postData()); });
  await run.click();
  await a.waitForSelector('.summary.copy', { timeout: 60000 });
  const copyLine = await a.locator('.summary.copy .line').first().textContent();
  const m = copyLine.match(/מהעותק שלך · ([\d,]+) נרות מהעותק \+ ([\d,]+) מהספק/);
  eq([!!m, m && +m[1].replace(/,/g, '')], [true, COPY_N], 'the copy result beside the regular one: "🧪 מהעותק שלך · N נרות מהעותק + M מהספק"');
  eq(await a.locator('.analysis-card').first().locator('.summary').count(), 2, 'both results in the card — regular and copy');
  const body = JSON.parse(posted[0] || '{}');
  eq([body.asset, body.tf, typeof body.csv_gz, (body.csv_gz || '').length < csv.length / 2, 'csv' in body], [ASSET, 'h1', 'string', true, false],
    'one request: the copy as gzip+base64 (much smaller than the text)');
  eq(await a.locator('.card-head .chip', { hasText: 'שקלול לפי העותק שלך' }).count(), 0, 'default: the regular result is in the blend');
  eq(await noHScroll(a), true, 'analysis with the copy: no horizontal scroll on a phone');
  eq(/null|undefined|NaN/.test(await a.locator('main').textContent()), false, 'no "null" / "undefined" / "NaN" text on the screen');
  await shot(a, 'copy-analysis');

  // ── בחירת השקלול ──
  const sw = a.locator('.analysis-card').first().getByLabel('לשקלול — התוצאה מהעותק');
  if (await sw.isDisabled()) { fail++; n++; console.log('✗ the copy result has no certainty — cannot choose it'); }
  else {
    await sw.check();
    await a.waitForSelector('.card-head .chip:has-text("🧪 שקלול לפי העותק שלך")', { timeout: 30000 });
    eq(await a.locator('.card-head .chip', { hasText: 'שקלול לפי העותק שלך' }).count(), 1, 'chose the copy → the card says "🧪 שקלול לפי העותק שלך" (only that method)');
    await a.locator('.analysis-card').first().getByLabel('לשקלול — התוצאה מהעותק').uncheck();
    await a.waitForFunction(() => !document.querySelector('.card-head .chip.warn')?.textContent?.includes('שקלול לפי העותק'), null, { timeout: 30000 });
    eq(await a.locator('.card-head .chip', { hasText: 'שקלול לפי העותק שלך' }).count(), 0, 'and back to the regular result');
  }

  // ── הגרף: טווח ישן מ-60 יום — מהעותק בדפדפן, לפני פנייה לספק ──
  const asked = [];
  a.on('request', r => { const u = new URL(r.url()); if (u.searchParams.get('action') === 'market_candles') asked.push(+u.searchParams.get('from')); });
  await a.evaluate(asset => sessionStorage.setItem('ts-chart:' + asset, JSON.stringify({ tf: 'h1', range: 'y' })), ASSET);
  await a.goto(BASE + '#/chart/' + encodeURIComponent(ASSET));
  await a.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 30000 });
  const info = await a.locator('main p.hint').first().textContent();
  eq(/🧪 [\d,]+ מהעותק שלך בענן \(תצוגה בלבד\)/.test(info), true, 'chart, 1 year: the old part comes from the copy (display only)');
  eq(asked.length === 1 && asked[0] > TO, true, 'the server is asked only from the copy\'s end — the provider is not asked for what the copy covers');
  await shot(a, 'copy-chart');
  eq(await noHScroll(a), true, 'chart: no horizontal scroll');
  // טעינה מחדש של הדף: המטמון של העותק חי רק בזמן שהדף פתוח — הוא נקרא שוב, פעם אחת
  const r0 = reads.length;
  await a.evaluate(asset => sessionStorage.setItem('ts-chart:' + asset, JSON.stringify({ tf: 'h1', range: 'y' })), ASSET);
  await a.reload();
  await a.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 30000 });
  eq(reads.length, r0 + 1, 'after a reload the copy is read again once (cache lives only while the page is open)');
} catch (e) {
  fail++; console.log('✗', e.message);
} finally {
  await b.close();
  srv.kill();
}
eq(errors, [], 'no page errors, nothing reached the live site');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
