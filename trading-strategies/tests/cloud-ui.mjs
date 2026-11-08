// ענן MBE בדפדפן, ברוחב טלפון (spec-stage-a 3.7, 9; פרק 15: טלפון, RTL, בלי polling). הענן — מדומה (Playwright route), בלי רשת.
// node trading-strategies/tests/cloud-ui.mjs   (Playwright)   SHOTS=<תיקייה> — צילומי מסך
import { createRequire } from 'module';
import { spawn, spawnSync } from 'child_process';
import { tmpdir } from 'os';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const port = 24500 + (process.pid % 400);
const DB = join(tmpdir(), `ts-cloud-ui-${process.pid}.sqlite`);
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
  const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, hasTouch: true, isMobile: true });
  await ctx.addCookies([{ name: 'dev_as', value: as, url: BASE }]);
  await ctx.addInitScript(() => { window.WebSocket = class { constructor() {} close() {} }; });
  // שום דבר לא יוצא לאתר החי. connect.php של הענן — מדומה: נספר, וחוזר לאפליקציה כמו "לא אושר" (spec-6 3.3)
  await ctx.route(u => u.hostname === 'mbe-plus.com' && !/\/mbe-cloud\/(api|picker)\.php/.test(u.pathname), r => {
    const u = new URL(r.request().url());
    if (u.pathname.endsWith('/mbe-cloud/connect.php')) { connects.push(as + ':' + u.searchParams.get('scope')); return r.fulfill({ status: 302, headers: { Location: BASE + '?cloud=denied' } }); }
    errors.push('live site: ' + r.request().url()); return r.abort();
  });
  const p = await ctx.newPage();
  p.on('pageerror', e => errors.push(e.message));
  p.on('request', r => { if (/[?&]action=drives_disconnect\b/.test(r.url())) disconnects.push(as); });
  return p;
}
const connects = [], disconnects = [];
const noHScroll = p => p.evaluate(() => document.scrollingElement.scrollWidth <= window.innerWidth + 1);
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: join(SHOTS, name + '.png'), fullPage: true }); };
const post = (p, action, body) => p.evaluate(async ([a, bd]) => {
  const me = await (await fetch('api.php?action=me')).json();
  return (await fetch('api.php?action=' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': me.csrf }, body: JSON.stringify(bd) })).status;
}, [action, body]);

// ענן MBE מדומה בדפדפן (Playwright route): כונן בזיכרון לכל אסימון — list, mkdir, read, upload_start/chunk/finish.
// אסימון של חיבור files.app (APP) — כמו spec-3-files-scopes 3.3: list() בלי folder = שורשי ההענקות; כתיבה בלי parent נכנסת
// ל"תיקייה של האפליקציה" (אסטרטגיות למסחר), שנוצרת בפעם הראשונה.
const drives = new Map();   // token → { files: Map, app: bool }
let seq = 0;
const node = (o) => ({ id: 'n_' + (++seq), rev: 1, created_at: '2026-11-08 09:00:00', updated_at: '2026-11-08 09:00:00', ...o });
const uploads = new Map();
let uploadsDone = 0;
const APP_TOKENS = new Set();
const driveOf = t => { if (!drives.has(t)) drives.set(t, { files: new Map(), app: APP_TOKENS.has(t) }); return drives.get(t); };
async function mockCloud(route) {
  const req = route.request();
  const cors = { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': '*', 'Access-Control-Allow-Methods': 'GET,POST,PUT,OPTIONS' };
  if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
  const u = new URL(req.url());
  const a = u.searchParams.get('action');
  const json = (o, s = 200) => route.fulfill({ status: s, headers: { ...cors, 'Content-Type': 'application/json' }, body: JSON.stringify(o) });
  const tk = req.headers()['x-cloud-token'] || '';
  if (!/^tok-for-c_\d+$/.test(tk)) return json({ error: 'אסימון לא תקין', code: 'bad_token' }, 401);
  const D = driveOf(tk);
  const files = D.files;
  const appDir = () => {
    let f = [...files.values()].find(n => n.appDir);
    if (!f) { f = node({ kind: 'folder', name: 'אסטרטגיות למסחר', parent: null, appDir: true }); files.set(f.id, f); }
    return f.id;
  };
  const parentOf = p => (p == null && D.app ? appDir() : p ?? null);
  const body = req.method() === 'POST' ? JSON.parse(req.postData() || '{}') : {};
  const pub = ({ content, appDir: _, ...n }) => n;
  if (a === 'list') {
    const folder = u.searchParams.get('folder') || null;
    if (folder && !files.has(folder)) return json({ error: 'לא נמצא', code: 'not_found' }, 404);
    return json({ folder, path: [], items: [...files.values()].filter(n => n.parent === folder).map(pub) });
  }
  if (a === 'mkdir') { const n = node({ kind: 'folder', name: body.name, parent: parentOf(body.parent) }); files.set(n.id, n); return json(pub(n)); }
  if (a === 'stat') { const n = files.get(u.searchParams.get('id')); return n ? json(pub(n)) : json({ error: 'לא נמצא', code: 'not_found' }, 404); }
  if (a === 'read') { const n = files.get(u.searchParams.get('id')); return route.fulfill({ status: 200, headers: cors, body: n.content }); }
  if (a === 'upload_start') { const id = 'u' + (++seq); uploads.set(id, { ...body, parent: parentOf(body.parent), parts: [] }); return json({ upload: id, chunk: 8388608, received: 0 }); }
  if (a === 'upload_chunk') { const up = uploads.get(u.searchParams.get('upload')); up.parts.push(req.postDataBuffer()); return json({ received: up.size }); }
  if (a === 'upload_finish') {
    const up = uploads.get(body.upload);
    const content = Buffer.concat(up.parts).toString('utf8');
    const old = [...files.values()].find(n => n.parent === up.parent && n.name === up.name && n.kind === 'file');
    if (old && up.if_rev !== undefined && up.if_rev !== old.rev) return json({ error: 'השתנה', code: 'conflict', rev: old.rev }, 409);
    if (old && up.on_conflict === 'fail') return json({ error: 'תפוס', code: 'name_taken' }, 409);
    const n = old ? Object.assign(old, { content, size: content.length, rev: old.rev + 1 }) : node({ kind: 'file', name: up.name, parent: up.parent ?? null, content, size: content.length });
    files.set(n.id, n);
    uploadsDone++;
    return json(pub(n));
  }
  return json({ error: 'לא מוכר', code: 'not_found' }, 404);
}
// הבורר של הענן (picker.php) — מדומה: קורא ל-drives_pick_callback שלנו (בשרת המקומי, עם העוגיות של הדפדפן) עם הבחירה
// או ביטול, כמו spec-3 4, ומחזיר את הדפדפן לאן שה-callback הפנה — אבל לשרת המקומי (back ב-api.php הוא האתר החי).
const picker = { pick: null };   // null = ביטול; אחרת id
const pickerRoute = ctx => async route => {
  const u = new URL(route.request().url());
  const q = new URLSearchParams({ action: 'drives_pick_callback', state: u.searchParams.get('state'), ...(picker.pick ? { picked: picker.pick } : { pick: 'cancelled' }) });
  const r = await ctx.request.get(BASE + 'api.php?' + q, { maxRedirects: 0 });
  const back = new URL(r.headers()['location'] || BASE);
  return route.fulfill({ status: 302, headers: { Location: BASE + back.search } });
};
const pathOf = (files, n) => { const p = []; for (let x = n?.parent; x; x = files.get(x).parent) p.unshift(files.get(x).name); return p.join('/'); };
const connect = (sub, scope) => spawnSync('php', ['-r', `$d = new PDO('sqlite:${DB}.drives/drives.sqlite'); $u = (new PDO('sqlite:${DB}'))->query("SELECT id FROM users WHERE platform_sub = '${sub}'")->fetchColumn();
    $d->prepare("INSERT OR REPLACE INTO conns (user, connection, name, cloud_id, scope, created_at, sub) VALUES (?, 'c_${sub}', 'משתמש', 'cloud-1', ?, '2026-11-08', '${sub}')")->execute([(string)$u, '${JSON.stringify(scope)}']);`]);

try {
  // ── לא מחובר (spec-6): פתיחת "עוד" → drives.ensure() → הפניה אחת לענן (המנהל הדליק 📁). חוזרים "לא אושר" — ולא שוב ──
  const a = await open('alice');
  const seenA = [];
  a.on('request', r => { const u = new URL(r.url()); if (u.searchParams.get('action')) seenA.push(u.searchParams.get('action')); });
  await a.goto(BASE + '#/market');
  await a.waitForSelector('main form');
  eq([connects.length, seenA.filter(x => x.startsWith('drives_')).length], [0, 0], 'app load (not the cloud screen) → no ensure, no drives request');
  await a.goto(BASE + '#/more');
  await a.waitForFunction(() => /לא מחובר|מחובר כ-/.test(document.querySelector('main')?.textContent || ''), null, { timeout: 20000 });
  await a.waitForTimeout(500);
  eq(connects, ['alice:files.app'], 'opening "עוד" → ensure() redirects once to the cloud, files.app only');
  eq([(await a.locator('main').textContent()).includes('לא מחובר'), new URL(a.url()).search], [true, ''], 'back with ?cloud=denied → "לא מחובר", the URL is cleaned');
  eq([await a.locator('main .switch').count(), await a.locator('input[aria-label="📁 קבצים בענן MBE"]').count()], [0, 0], 'no switch (the cloud admin decides the layers)');
  eq(await a.locator('[data-cloud-files]').textContent(), '📁 קבצים · לא מחובר🔒 נקבע בידי מנהל הענן', 'a read-only line instead: 📁 · not connected · 🔒 managed');
  eq(await a.getByRole('button', { name: '☁️ חיבור לענן MBE' }).count(), 1, 'not connected → "☁️ חיבור לענן MBE" (drives.connect)');
  await a.reload();
  await a.waitForFunction(() => /לא מחובר/.test(document.querySelector('main')?.textContent || ''), null, { timeout: 20000 });
  await a.waitForTimeout(500);
  eq(connects.length, 1, 'reopening within 2 minutes → no second redirect (no loop)');
  eq(await noHScroll(a), true, '"עוד": no horizontal scroll on a phone');
  await shot(a, 'cloud-more');
  await a.goto(BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT'));
  await a.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 20000 });
  await a.getByRole('button', { name: '💾 שמור לענן שלי' }).click();
  await a.waitForSelector('dialog');
  eq((await a.locator('dialog').textContent()).includes('חברו את הענן MBE'), true, 'not connected → "💾" offers "☁️ חיבור לענן MBE"');
  await a.locator('dialog button[aria-label="סגירה"]').click();
  await a.goto(BASE + '#/method-files');
  await a.waitForSelector('main .error');
  eq((await a.locator('main .error').textContent()).includes('מנהל'), true, 'a regular user on "📚 קובצי שיטות" → the server says admins only');

  // ── מחובר (חיבור בענן המדומה): שמירה לענן — CSV עשרוני, ושמירה חוזרת ממזגת ──
  connect('101', ['files']);
  await a.context().route('https://mbe-plus.com/mini_projects/mbe-cloud/api.php**', mockCloud);
  await a.goto(BASE + '#/more');
  await a.waitForFunction(() => /מחובר כ-/.test(document.querySelector('main')?.textContent || ''));
  eq(await a.locator('[data-cloud-files]').getAttribute('data-cloud-files'), 'on', 'connected → "📁 קבצים · פעיל", "מחובר כ-…"');
  eq((await a.locator('[data-cloud-files]').textContent()).startsWith('📁 קבצים · פעיל'), true, 'the read-only line says active');
  await a.goto(BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT'));
  await a.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 20000 });
  await a.getByRole('button', { name: '💾 שמור לענן שלי' }).click();
  await a.waitForFunction(() => /נשמר לענן/.test(document.querySelector('.toast')?.textContent || ''), null, { timeout: 15000 });
  const af = driveOf('tok-for-c_101').files;
  const saved = [...af.values()].find(n => n.kind === 'file');
  eq([pathOf(af, saved), saved?.name], ['אסטרטגיות למסחר/נתונים/binance/BTCUSDT', 'h1.csv'], 'saved to אסטרטגיות למסחר/נתונים/<ספק>/<סימול>/<מסגרת>.csv');
  const lines = (saved?.content || '').trim().split('\n');
  eq([/^# provider=binance; symbol=BTCUSDT; tf=h1; saved=\d{4}-/.test(lines[0]), lines[1]], [true, 't,o,h,l,c,v'], 'the comment line and the header');
  eq(lines.slice(2).every(l => /^\d+,\d+\.\d{8},\d+\.\d{8},\d+\.\d{8},\d+\.\d{8},\d+(\.\d+)?$/.test(l)), true, 'every row — the provider\'s decimal strings as they are (8 decimals), no float');
  const n1 = lines.length;
  await a.getByRole('button', { name: '💾 שמור לענן שלי' }).click();
  await a.waitForFunction(() => /נרות חדשים/.test(document.querySelector('.toast')?.textContent || ''), null, { timeout: 15000 });
  eq([[...af.values()].filter(n => n.kind === 'file').length, saved.content.trim().split('\n').length, saved.rev], [1, n1, 2], 'saving again — the same file (new rev), no duplicate rows');
  eq(uploadsDone, 2, 'two uploads, each to our own drive');
  await shot(a, 'cloud-chart');

  // ── בלי polling: 20 שניות במסך "עוד" ──
  await a.goto(BASE + '#/more');
  await a.waitForFunction(() => /מחובר כ-/.test(document.querySelector('main')?.textContent || ''));
  await a.waitForTimeout(1000);
  const seen = [];
  const onReq = r => { if (/api\.php/.test(r.url())) seen.push(r.url()); };
  a.on('request', onReq);
  await a.waitForTimeout(20000);
  a.off('request', onReq);
  eq(seen.length, 0, 'no requests (ours or the cloud\'s) in 20 s idle');

  // ── files.app (spec-3-files-scopes): הנתונים בתוך התיקייה של האפליקציה, בלי כפילות של "אסטרטגיות למסחר" ──
  const bb = await open('bob');
  await bb.goto(BASE + '#/market');
  await bb.waitForSelector('main form');
  connect('102', ['files.app']);
  APP_TOKENS.add('tok-for-c_102');
  await bb.context().route('https://mbe-plus.com/mini_projects/mbe-cloud/api.php**', mockCloud);
  await bb.goto(BASE + '#/more');
  await bb.waitForFunction(() => /מחובר כ-/.test(document.querySelector('main')?.textContent || ''));
  eq(await bb.locator('[data-cloud-files]').getAttribute('data-cloud-files'), 'on', 'files.app → "📁 קבצים · פעיל"');
  for (let k = 0; k < 2; k++) {
    await bb.goto(BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT'));
    await bb.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 20000 });
    await bb.evaluate(() => { const t = document.querySelector('.toast'); if (t) t.textContent = ''; });
    await bb.getByRole('button', { name: '💾 שמור לענן שלי' }).click();
    await bb.waitForFunction(() => /נשמר לענן/.test(document.querySelector('.toast')?.textContent || ''), null, { timeout: 15000 });
  }
  const bf = driveOf('tok-for-c_102').files;
  const bfile = [...bf.values()].filter(n => n.kind === 'file');
  eq([bfile.length, pathOf(bf, bfile[0]), bfile[0]?.name], [1, 'אסטרטגיות למסחר/נתונים/binance/BTCUSDT', 'h1.csv'],
    'files.app: saved inside the app folder (אסטרטגיות למסחר/נתונים/…), once, and re-saved to the same file');
  eq([...bf.values()].filter(n => n.name === 'אסטרטגיות למסחר').length, 1, 'no nested duplicate "אסטרטגיות למסחר" folder');
  eq(driveOf('tok-for-c_101').files.has(bfile[0].id), false, 'bob\'s file is in bob\'s drive only');

  // ── מחובר, אבל 📁 לא פעיל באפליקציה הזו (המנהל כיבה): שורה לקריאה בלבד והודעה — בלי כפתור ──
  const cc = await open('carol');
  await cc.goto(BASE + '#/market');
  await cc.waitForSelector('main form');
  connect('103', ['libs']);
  await cc.context().route('https://mbe-plus.com/mini_projects/mbe-cloud/api.php**', mockCloud);
  await cc.goto(BASE + '#/more');
  await cc.waitForFunction(() => /מחובר כ-/.test(document.querySelector('main')?.textContent || ''), null, { timeout: 20000 });
  eq([await cc.locator('[data-cloud-files]').textContent(), await cc.getByRole('button', { name: '☁️ חיבור לענן MBE' }).count()],
    ['📁 קבצים · לא פעיל באפליקציה הזו🔒 נקבע בידי מנהל הענן', 0], 'layer off → "לא פעיל באפליקציה הזו", no button');
  await cc.goto(BASE + '#/chart/' + encodeURIComponent('binance:BTCUSDT'));
  await cc.waitForFunction(() => /נרות/.test(document.querySelector('main p.hint')?.textContent || ''), null, { timeout: 20000 });
  await cc.getByRole('button', { name: '💾 שמור לענן שלי' }).click();
  await cc.waitForFunction(() => /לא פעילים באפליקציה הזו/.test(document.querySelector('.toast')?.textContent || ''), null, { timeout: 15000 });
  eq(await cc.locator('dialog[open]').count(), 0, '"💾" with the layer off → a message, no "connect" sheet');

  // ── 📚 קובצי שיטות (מנהל) ──
  const o = await open('owner');
  await o.goto(BASE + '#/method-files');
  await o.waitForSelector('main .empty, main button');
  eq((await o.locator('main').textContent()).includes('הענן לא מחובר'), true, 'admin, not connected → "☁️ חיבור לענן MBE"');
  eq(await noHScroll(o), true, 'method files: no horizontal scroll');
  connect('100', ['files.app']);
  APP_TOKENS.add('tok-for-c_100');
  await o.context().route('https://mbe-plus.com/mini_projects/mbe-cloud/api.php**', mockCloud);
  await o.context().route('https://mbe-plus.com/mini_projects/mbe-cloud/picker.php**', pickerRoute(o.context()));
  // שום ניווט לא יוצא לאתר החי
  await o.context().route(u => u.hostname === 'mbe-plus.com' && !/\/mbe-cloud\/(api|picker)\.php/.test(u.pathname), r => { errors.push('live site: ' + r.request().url()); return r.abort(); });
  const folderNow = () => o.evaluate(async () => (await (await fetch('api.php?action=admin_method_files')).json()).folder?.id ?? null);
  // ביטול בבורר → בלי שינוי
  await o.reload();
  await o.getByRole('button', { name: '📁 בחירת תיקיית השיטות בענן' }).click();
  await o.waitForFunction(() => /הבחירה בוטלה/.test(document.querySelector('.toast')?.textContent || ''), null, { timeout: 15000 });
  eq([await folderNow(), new URL(o.url()).search, new URL(o.url()).hash], [null, '', '#/method-files'], 'picker cancelled → nothing changes, ?cloud cleaned, back on "📚 קובצי שיטות"');
  await o.waitForSelector('text=עוד לא נבחרה תיקייה');
  // תיקייה שנמחקה / גישה שבוטלה → "בחרו שוב"
  await post(o, 'admin_method_files_folder', { id: 'n_gone', path: 'שיטות' });
  await o.reload();
  await o.waitForSelector('text=בחרו שוב את תיקיית השיטות');
  eq(await o.getByRole('button', { name: '📁 בחירה מחדש' }).count(), 1, 'the folder is gone / access revoked → "בחרו שוב", no raw error');
  eq(await o.locator('main .error').count(), 0, 'no error box');
  await shot(o, 'cloud-method-files');
  // בחירה מחדש בבורר → נשמר רק ה-id, ואז רשימה
  const of = driveOf('tok-for-c_100').files;
  const mdir = node({ kind: 'folder', name: 'שיטות', parent: null }); of.set(mdir.id, mdir);
  const book = node({ kind: 'file', name: 'ספר השיטה.pdf', parent: mdir.id, size: 2400000 }); of.set(book.id, book);
  picker.pick = mdir.id;
  await o.getByRole('button', { name: '📁 בחירה מחדש' }).click();
  await o.waitForSelector('text=ספר השיטה.pdf', { timeout: 15000 });
  eq([await folderNow(), new URL(o.url()).search, new URL(o.url()).hash], [mdir.id, '', '#/method-files'], 'picked in the cloud\'s picker → only its id is saved, ?cloud=picked cleaned');
  eq((await o.locator('main .card').first().textContent()).includes('2.3 MB'), true, 'the list: name and size (and date)');
  eq(await o.getByRole('button', { name: '⬆️ העלאה לענן' }).count(), 1, 'upload straight to the cloud');
  eq(disconnects, [], 'nothing ever calls drives_disconnect (no user disconnect)');
} catch (e) {
  fail++; console.log('✗', e.message);
} finally {
  await b.close();
  srv.kill();
}
eq(errors, [], 'no page errors');
console.log(fail ? `${fail} מתוך ${n} נכשלו` : `כל ${n} הבדיקות עברו`);
process.exit(fail ? 1 : 0);
