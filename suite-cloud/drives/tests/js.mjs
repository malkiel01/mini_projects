// בדיקות צד הדפדפן של מודול הכוננים, ב-Node עם fetch מדומה: node suite-cloud/drives/tests/js.mjs
import { createDrives, DriveError } from '../src/js/drives.js';

let fails = 0, n = 0;
const check = (name, ok) => { n++; if (!ok) { fails++; console.log('✗ ' + name); } else console.log('✓ ' + name); };
const json = (body, status = 200, headers = {}) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } });

const API = 'https://mbe-plus.com/mini_projects/suite-cloud/api.php';
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
const r = await d.ai.run('generate-image', { prompt: 'לוגו', size: '1080x1350', transparent: true });
check('ai.run — PNG, הגודל שיצא, שקיפות וספק', r.png instanceof Blob && r.size.join() === '1024,1536' && r.transparent && r.provider === 'openai');
const sg = await d.ai.run('segment', { image: new Blob(['x']) });
check('ai.run segment — מסכות כ-Blob עם תיבה', sg.masks[0].png instanceof Blob && sg.masks[0].box.join() === '1,2,3,4');
check('ai.capabilities — מחובר', (await d.ai.capabilities()).tasks.inpaint === true);

await d.disconnect();
const d2 = createDrives({ endpoint: 'https://mbe-plus.com/mini_projects/vector-studio/api.php' });
let err = null;
try { await d2.suite.list(); } catch (e) { err = e; }
check('בלי חיבור — DriveError not-connected', err instanceof DriveError && err.code === 'not-connected');
const none = await d2.ai.capabilities();
check('בלי חיבור — capabilities הכול false (המסלול הידני)', Object.values(none.tasks).every(v => v === false));

console.log(`\n${n - fails} / ${n} עברו`);
process.exit(fails ? 1 : 0);
