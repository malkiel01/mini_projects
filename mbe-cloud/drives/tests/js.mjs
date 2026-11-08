// בדיקות צד הדפדפן של מודול הכוננים, ב-Node עם fetch מדומה: node mbe-cloud/drives/tests/js.mjs
import { createDrives, DriveError } from '../src/js/drives.js';

let fails = 0, n = 0;
const check = (name, ok) => { n++; if (!ok) { fails++; console.log('✗ ' + name); } else console.log('✓ ' + name); };
const json = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } });

const API = 'https://mbe-plus.com/mini_projects/mbe-cloud/api.php';
let dbxLinked = true, ptokens = 0, dbxExpire = false;
const dbxCalls = [];
let statusExtra = {};
let connected = true, tokens = 0, expireNext = false, chunks = [], layerOff = false, readOnly = false;
const appBodies = {};
const adCalls = [];
const log = [];
globalThis.fetch = async (url, init = {}) => {
  url = String(url);
  log.push(url);
  const a = new URL(url, 'https://mbe-plus.com/mini_projects/vector-studio/').searchParams.get('action');
  if (!url.startsWith(API)) {
    if (init.body && typeof init.body === 'string') appBodies[a] = JSON.parse(init.body);
    if (a === 'drives_connect') return json({ url: 'https://mbe-plus.com/mini_projects/mbe-cloud/connect.php?x=1' });
    if (a === 'drives_pick') return json({ url: 'https://mbe-plus.com/mini_projects/mbe-cloud/picker.php?x=1' });
    if (a === 'drives_picked') return json({ ids: ['n_ok', 'n_gone'] });
    if (a === 'drives_disconnect' && appBodies[a]?.layers) return json({ ok: true, suite: { connected: true, layers: { files: true, libs: false } } });
    if (a === 'drives_token') { tokens++; return json(connected ? { connected: true, token: 't' + tokens, expires_in: 900, api: API } : { connected: false }); }
    if (a === 'drives_status') return json({ suite: { connected, ...statusExtra } });
    if (a === 'drives_disconnect') { connected = false; return json({ ok: true }); }
    if (a === 'drives_ptoken') { ptokens++; return json(dbxLinked ? { connected: true, token: 'd' + ptokens, expires_in: 14000 } : { connected: false }); }
    if (a === 'drives_unlink') { dbxLinked = false; return json({ ok: true }); }
  }
  if (url.startsWith('https://api.dropboxapi.com/2/') || url.startsWith('https://content.dropboxapi.com/2/')) {
    const route = url.replace(/^https:\/\/(api|content)\.dropboxapi\.com\/2\//, '');
    const h = init.headers || {};
    dbxCalls.push({ route, auth: h.Authorization, arg: h['Dropbox-API-Arg'], body: init.body });
    if (dbxExpire) { dbxExpire = false; return json({ error_summary: 'expired_access_token/' }, 401); }
    const arg = h['Dropbox-API-Arg'] ? JSON.parse(h['Dropbox-API-Arg']) : JSON.parse(init.body);
    if (route === 'files/list_folder') return json({ entries: [
      { '.tag': 'file', id: 'id:f1', name: 'ב.mbe', path_lower: '/ב.mbe', path_display: '/ב.mbe', rev: 'r1', size: 5, server_modified: '2026-11-01T00:00:00Z' },
      { '.tag': 'folder', id: 'id:d1', name: 'א', path_lower: '/א', path_display: '/א' }], has_more: true, cursor: 'c1' });
    if (route === 'files/list_folder/continue') return json({ entries: [{ '.tag': 'file', id: 'id:f2', name: 'א.png', path_lower: '/א.png', path_display: '/א.png', rev: 'r2', size: 1 }], has_more: false });
    if (route === 'files/upload') return arg.mode['.tag'] === 'update' && arg.mode.update === 'old'
      ? json({ error_summary: 'path/conflict/file/..' }, 409)
      : json({ id: 'id:n', name: arg.path.split('/').pop(), path_lower: arg.path, path_display: arg.path, rev: 'r9', size: 3 });
    if (route === 'files/upload_session/start') return json({ session_id: 's1' });
    if (route === 'files/upload_session/append_v2') return json(null);
    if (route === 'files/upload_session/finish') return json({ id: 'id:big', name: 'big.bin', path_display: arg.commit.path, path_lower: arg.commit.path, rev: 'r10', size: 1 });
    if (route === 'files/download') return new Response(new Blob(['dbx']), { status: 200 });
    if (route === 'users/get_space_usage') return json({ used: 10, allocation: { '.tag': 'individual', allocated: 2000 } });
    return json({ error_summary: 'path/not_found/' }, 409);
  }
  const tok = init.headers?.['X-Cloud-Token'];
  if (!tok) return json({ error: 'no token', code: 'unauthorized' }, 401);
  if (expireNext) { expireNext = false; return json({ error: 'expired', code: 'expired' }, 401); }
  if (a?.startsWith('appdata_')) {
    const b = init.body ? JSON.parse(init.body) : {};
    adCalls.push({ a, b, method: init.method });
    if (a === 'appdata_get') return b.key === 'none' ? json({ error: 'לא נמצא', code: 'not_found' }, 404) : json({ value: { zoom: 2 }, rev: 1, updated_at: 'x' });
    if (a === 'appdata_put' && b.if_rev === 9) return json({ error: 'המסמך השתנה', code: 'conflict', rev: 2, value: { zoom: 3 } }, 409);
    if (a === 'appdata_put' && b.space === 'app') return json({ error: 'במרחב app כותב רק השרת', code: 'forbidden' }, 403);
    if (a === 'appdata_changes' && b.since === 999) return json({ error: 'gone', code: 'gone' }, 410);
    return json({ ok: true, rev: 2, seq: 7, items: [], changes: [], cursor: 7, more: false, used: 10, quota: 20 });
  }
  if (readOnly && a === 'mkdir') return json({ error: 'קריאה בלבד', code: 'read_only' }, 403);
  if (a === 'stat' && url.includes('id=n_gone')) return json({ error: 'לא נמצא', code: 'not_found' }, 404);
  if (a === 'stat' && url.includes('id=n_ok')) return json({ id: 'n_ok', kind: 'file', name: 'שיטה.txt', parent: null });
  if (layerOff && a === 'list') return json({ error: 'אין לאפליקציה הרשאה לזה', code: 'forbidden', scope: 'files', layer: 'files' }, 403);
  if (a === 'list') return json({ folder: null, path: [], items: [{ id: 'n_1', name: 'ספר.mbe', tok }] });
  if (a === 'upload_start') return json({ upload: 'u_1', chunk: 4, received: 0 });
  if (a === 'upload_chunk') { chunks.push(new URL(url).searchParams.get('index')); return json({ received: Math.min(10, (chunks.length) * 4) }); }
  if (a === 'upload_finish') return json({ id: 'n_2', name: 'x.bin', size: 10 });
  if (a === 'write') return json({ id: 'n_3', body: JSON.parse(init.body) });
  if (a === 'ai_capabilities') return json({ tasks: { inpaint: true } });
  if (a === 'ai_run') {
    const fd = init.body;
    if (fd.get('task') === 'segment') return json({ masks: [{ png: Buffer.from('PNG').toString('base64'), label: 'עצם 1', box: [1, 2, 3, 4] }] });
    return new Response(new Blob([new Uint8Array([137, 80, 78, 71])]), { status: 200, headers: { 'content-type': 'image/png', 'x-ai-size': '1024x1536', 'x-ai-transparent': '1', 'x-ai-provider': 'openai' } });
  }
  if (a === 'read') return new Response(new Blob(['abc']), { status: 200 });
  if (a === 'fonts_list') return json({ fonts: [{ id: 'fnt_1', family: 'Alef' }], families: [{ family: 'Alef', items: ['fnt_1'] }] });
  if (a === 'font_add') { const fd = init.body; return json({ id: 'fnt_2', family: fd.get('family'), ack: fd.get('license_ack') }); }
  return json({ error: 'not found', code: 'not_found' }, 404);
};

const d = createDrives({ endpoint: 'https://mbe-plus.com/mini_projects/vector-studio/api.php' });
const l1 = await d.suite.list();
const l2 = await d.suite.list('n_9');
check('list — עם האסימון, ובקשה אחת לאסימון לשתי קריאות', l1.items[0].tok === 't1' && l2.items[0].tok === 't1' && tokens === 1);
check('folder בכתובת', log.some(u => u.includes('action=list&folder=n_9')));
expireNext = true;
const l3 = await d.suite.list();
check('אסימון שפג — חדש ונסיון נוסף, בשקט', l3.items[0].tok === 't2' && tokens === 2);
const node = await d.suite.upload(new Blob([new Uint8Array(10)]), { name: 'x.bin' });
check('upload — בחלקים לפי chunk של השרת', node.id === 'n_2' && chunks.join() === '0,1,2');
const w = await d.suite.write('a.txt', new Blob(['שלום']), { ifRev: 3 });
check('write — base64 ו-if_rev', w.body.if_rev === 3 && Buffer.from(w.body.content_b64, 'base64').toString() === 'שלום');
check('read — Blob', (await (await d.suite.read('n_1')).text()) === 'abc');
check('fonts.list', (await d.fonts.list()).families[0].family === 'Alef');
await d.fonts.list({ builtin: true });
check('fonts.list({ builtin }) — ‎&builtin=1 בכתובת', log.at(-1).includes('action=fonts_list&builtin=1'));
const fa = await d.fonts.add(new Blob(['x']), { family: 'Rubik' });
check('fonts.add — multipart עם אישור רישיון', fa.family === 'Rubik' && fa.ack === '1');
const r = await d.ai.run('generate-image', { prompt: 'לוגו', size: '1080x1350', transparent: true });
check('ai.run — PNG, הגודל שיצא, שקיפות וספק', r.png instanceof Blob && r.size.join() === '1024,1536' && r.transparent && r.provider === 'openai');
const sg = await d.ai.run('segment', { image: new Blob(['x']) });
check('ai.run segment — מסכות כ-Blob עם תיבה', sg.masks[0].png instanceof Blob && sg.masks[0].box.join() === '1,2,3,4');
const capOn = await d.ai.capabilities();
check('ai.capabilities — מחובר, עם connected:true', capOn.tasks.inpaint === true && capOn.connected === true);

// ─── Dropbox ───
const dl = await d.dropbox.list();
check('dropbox.list — שתי הדפים, תיקיות קודם, צורת node של suite', dl.items.map(i => i.id).join() === 'id:d1,id:f2,id:f1'
  && dl.items[0].kind === 'folder' && dl.items[2].rev === 'r1' && dl.folder === null && dbxCalls[0].auth === 'Bearer d1');
check('dropbox — שורש הוא path ריק', JSON.parse(dbxCalls[0].body).path === '');
dbxExpire = true;
await d.dropbox.list();
check('dropbox — 401 → אסימון חדש מהאפליקציה ונסיון נוסף', ptokens === 2 && dbxCalls.at(-1).auth === 'Bearer d2');
const up = await d.dropbox.write('שלום.txt', new Blob(['abc']), { parent: 'id:d1' });
const upArg = dbxCalls.at(-1).arg;
check('dropbox.write — Dropbox-API-Arg ב-ASCII בלבד, ונתיב יחסי לתיקייה', /^[\x00-\x7f]*$/.test(upArg) && JSON.parse(upArg).path === 'id:d1/שלום.txt' && up.parent === 'id:d1');
check('dropbox — שינוי שם כברירת מחדל (autorename)', JSON.parse(upArg).autorename === true && JSON.parse(upArg).mode === 'add');
let dErr = null;
try { await d.dropbox.write('a.txt', new Blob(['x']), { ifRev: 'old' }); } catch (e) { dErr = e; }
check('dropbox — ifRev שלא תואם → conflict', dErr?.code === 'conflict');
const parts = [];
const big = await d.dropbox.upload(new Blob([new Uint8Array(8 * 1024 * 1024 * 2 + 5)]), { name: 'big.bin', onProgress: (g) => parts.push(g) });
check('dropbox.upload — קובץ גדול בסשן: start, append, finish', big.id === 'id:big'
  && dbxCalls.slice(-4).map(c => c.route).join() === 'files/upload_session/start,files/upload_session/append_v2,files/upload_session/append_v2,files/upload_session/finish'
  && JSON.parse(dbxCalls.at(-1).arg).cursor.offset === 16 * 1024 * 1024);
check('dropbox.read — Blob', (await (await d.dropbox.read('id:f1')).text()) === 'dbx');
check('dropbox.quota', (await d.dropbox.quota()).quota === 2000);
let nf = null;
try { await d.dropbox.stat('id:none'); } catch (e) { nf = e; }
check('dropbox — לא נמצא → not_found', nf?.code === 'not_found');
await d.unlink('dropbox');
let nc = null;
try { await d.dropbox.list(); } catch (e) { nc = e; }
check('dropbox אחרי unlink — not-connected', nc?.code === 'not-connected');

// ── נתוני משתמש — 🗂️ (spec-4-appdata.md) ──
const ad = d.appdata;
check('appdata.get — ערך ו-rev, ב-POST עם JSON', (await ad.get('settings', 'chart')).value.zoom === 2
  && adCalls.at(-1).method === 'POST' && JSON.stringify(adCalls.at(-1).b) === '{"collection":"settings","key":"chart"}');
check('appdata.get של מפתח שלא קיים — null', (await ad.get('settings', 'none')) === null);
await ad.put('settings', 'chart', { zoom: 4 }, { ifRev: 1 });
check('appdata.put — if_rev בגוף, בלי space במרחב user', JSON.stringify(adCalls.at(-1).b) === '{"collection":"settings","key":"chart","value":{"zoom":4},"if_rev":1}');
let cf = null;
try { await ad.put('settings', 'chart', {}, { ifRev: 9 }); } catch (e) { cf = e; }
check('appdata.put עם if_rev ישן — DriveError conflict עם rev ו-value', cf?.code === 'conflict' && cf.rev === 2 && cf.value.zoom === 3);
await ad.put('settings', 'new', 1, { ifRev: 0 });
check('appdata.put עם ifRev 0 (יצירה בלבד) — נשלח', adCalls.at(-1).b.if_rev === 0);
const app = ad.space('app');
await app.get('catalog', 'v1');
check('appdata.space(\'app\') — space app בגוף', adCalls.at(-1).b.space === 'app');
let fb = null;
try { await app.put('catalog', 'v1', 1); } catch (e) { fb = e; }
check('מרחב app מהדפדפן — כתיבה נדחית (forbidden, לא layer-off)', fb?.code === 'forbidden');
let gone = null;
try { await ad.changes(999); } catch (e) { gone = e; }
check('appdata.changes ישן — gone (טוענים מחדש ב-list)', gone?.code === 'gone');
await ad.clear();
check('appdata.clear — confirm: clear', JSON.stringify(adCalls.at(-1).b) === '{"confirm":"clear"}' && adCalls.at(-1).a === 'appdata_clear');
await ad.getMany('watch', ['a', 'b']); await ad.list({ collection: 'watch', prefix: '2026/' }); await ad.history('h', 'doc'); await ad.restore('h', 'doc', 3);
check('getMany, list, history, restore — הפעולות והגופים', adCalls.slice(-4).map(c => c.a).join() === 'appdata_get_many,appdata_list,appdata_history,appdata_restore'
  && adCalls.at(-1).b.rev === 3 && adCalls.at(-3).b.prefix === '2026/');

// ── שלב 3: קריאה בלבד והבורר ──
readOnly = true;
let ro = null;
try { await d.suite.mkdir('x'); } catch (e) { ro = e; }
check('files.readonly — כתיבה: DriveError read-only (לא layer-off)', ro?.code === 'read-only');
readOnly = false;
{
  let assigned2 = null;
  globalThis.location = { href: 'https://mbe-plus.com/mini_projects/vector-studio/?cloud=picked&x=1', assign: u => { assigned2 = u; } };
  let replaced = null;
  globalThis.history = { state: null, replaceState: (s, t, u) => { replaced = u; } };
  await d.pick({ kind: 'folder', multiple: true, mime: ['application/pdf'] });
  check('pick — שולח kind, multiple, mime, ועובר לבורר', JSON.stringify(appBodies.drives_pick) === '{"kind":"folder","multiple":true,"mime":["application/pdf"]}'
    && assigned2.includes('picker.php'));
  const got = await d.picked();
  check('picked — רק מה שבאמת נגיש (n_gone נשמט), והכתובת מתנקה מ-cloud=picked', got.length === 1 && got[0].id === 'n_ok'
    && replaced === '/mini_projects/vector-studio/?x=1');
  delete globalThis.location; delete globalThis.history;
}
{
  // בקשת "אסטרטגיות": layers.files דלוק גם ב-files.app / files.readonly — status מעביר כמו שהשרת מחזיר
  statusExtra = { layers: { files: true }, files_scope: 'files.app' };
  const st = await d.status();
  check('status — layers.files דלוק עם files_scope files.app', st.suite.layers.files === true && st.suite.files_scope === 'files.app');
  statusExtra = {};
  // החזרה מהבורר בלי ?cloud=picked בכתובת (למשל מ-APK, אחרי "נשמרה") — picked עדיין מחזיר מהשרת
  globalThis.location = { href: 'https://mbe-plus.com/mini_projects/vector-studio/', assign() {} };
  globalThis.history = { state: null, replaceState() { throw new Error('לא אמור לנקות'); } };
  const got2 = await d.picked();
  check('picked — גם בלי cloud=picked בכתובת (APK): מה שנבחר, והכתובת לא נוגעים בה', got2.length === 1 && got2[0].id === 'n_ok');
  delete globalThis.location; delete globalThis.history;
}

// ── שכבות (spec-0-vision.md 7.3) ──
layerOff = true;
let lo = null;
try { await d.suite.list(); } catch (e) { lo = e; }
check('שכבה כבויה — DriveError layer-off עם layer', lo instanceof DriveError && lo.code === 'layer-off' && lo.layer === 'files');
layerOff = false;
let assigned = null;
globalThis.location = { assign: u => { assigned = u; } };
await d.connect({ layers: ['libs'] });
check('connect({layers}) — שולח layers ועובר למסך האישור', JSON.stringify(appBodies.drives_connect) === '{"layers":["libs"]}' && assigned.includes('connect.php'));
await d.connect();
check('connect() — בלי layers (כל השכבות שהוצהרו)', JSON.stringify(appBodies.drives_connect) === '{}');
await d.connect('files,ai');
check('connect(\'files,ai\') — התאמה לאחור (scope)', JSON.stringify(appBodies.drives_connect) === '{"scope":"files,ai"}');
const off = await d.disconnect({ layers: ['libs'] });
check('disconnect({layers}) — כיבוי שכבה, ומחזיר status', JSON.stringify(appBodies.drives_disconnect) === '{"layers":["libs"]}' && off.suite.layers.libs === false && off.suite.connected);
delete globalThis.location;

await d.disconnect();
check('disconnect() — ניתוק מלא, בלי layers', JSON.stringify(appBodies.drives_disconnect) === '{}');
const d2 = createDrives({ endpoint: 'https://mbe-plus.com/mini_projects/vector-studio/api.php' });
let err = null;
try { await d2.suite.list(); } catch (e) { err = e; }
check('בלי חיבור — DriveError not-connected', err instanceof DriveError && err.code === 'not-connected');
const none = await d2.ai.capabilities();
check('בלי חיבור — connected:false, והכול false (המסלול הידני)', none.connected === false && Object.values(none.tasks).every(v => v === false));

console.log(`\n${n - fails} / ${n} עברו`);
process.exit(fails ? 1 : 0);
