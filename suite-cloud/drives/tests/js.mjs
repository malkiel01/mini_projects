// בדיקות צד הדפדפן של מודול הכוננים, ב-Node עם fetch מדומה: node suite-cloud/drives/tests/js.mjs
import { createDrives, DriveError } from '../src/js/drives.js';

let fails = 0, n = 0;
const check = (name, ok) => { n++; if (!ok) { fails++; console.log('✗ ' + name); } else console.log('✓ ' + name); };
const json = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } });

const API = 'https://mbe-plus.com/mini_projects/suite-cloud/api.php';
let dbxLinked = true, ptokens = 0, dbxExpire = false;
const dbxCalls = [];
let connected = true, tokens = 0, expireNext = false, chunks = [];
const log = [];
globalThis.fetch = async (url, init = {}) => {
  url = String(url);
  log.push(url);
  const a = new URL(url, 'https://mbe-plus.com/mini_projects/vector-studio/').searchParams.get('action');
  if (!url.startsWith(API)) {
    if (a === 'drives_token') { tokens++; return json(connected ? { connected: true, token: 't' + tokens, expires_in: 900, api: API } : { connected: false }); }
    if (a === 'drives_status') return json({ suite: { connected } });
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

await d.disconnect();
const d2 = createDrives({ endpoint: 'https://mbe-plus.com/mini_projects/vector-studio/api.php' });
let err = null;
try { await d2.suite.list(); } catch (e) { err = e; }
check('בלי חיבור — DriveError not-connected', err instanceof DriveError && err.code === 'not-connected');
const none = await d2.ai.capabilities();
check('בלי חיבור — connected:false, והכול false (המסלול הידני)', none.connected === false && Object.values(none.tasks).every(v => v === false));

console.log(`\n${n - fails} / ${n} עברו`);
process.exit(fails ? 1 : 0);
