// בדיקות ל-service worker של אפליקציית ההורים (parent/sw.js) — מריצים את הקובץ האמיתי בסביבה מדומה.
//   node guarded-browser/tests/sw.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

let pass = 0, fail = 0;
const check = (name, got, want) => {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    ok ? pass++ : fail++;
    console.log(ok ? `  ok   ${name}` : `  FAIL ${name}\n       got:  ${JSON.stringify(got)}\n       want: ${JSON.stringify(want)}`);
};

const SCOPE = 'https://mbe-plus.com/mini_projects/guarded-browser/parent/';
const ORIGIN = 'https://mbe-plus.com';

/** סביבה חדשה: מטמונים, רשת מדומה, והאירועים שה-SW רושם. */
function env({ network }) {
    const stores = new Map();
    const caches = {
        async open(n) {
            if (!stores.has(n)) stores.set(n, new Map());
            const m = stores.get(n);
            return {
                async put(k, r) { m.set(typeof k === 'string' ? k : k.url, r); },
                async match(k) { const r = m.get(typeof k === 'string' ? k : k.url); return r ? r.clone() : undefined; },
                async addAll(list) { for (const p of list) { const u = new URL(p, SCOPE).href; m.set(u, await network(new Request(u))); } },
            };
        },
        async keys() { return [...stores.keys()]; },
        async delete(n) { return stores.delete(n); },
    };
    const handlers = {};
    const self = {
        registration: { scope: SCOPE }, location: new URL(SCOPE + 'sw.js'), clients: { claim: async () => {} },
        skipWaiting: async () => {}, addEventListener: (t, f) => { handlers[t] = f; },
    };
    const ctx = vm.createContext({ self, caches, location: self.location, fetch: (r) => network(r), URL, Response, Request,
                                   Headers, Promise, Set, console });
    vm.runInContext(readFileSync(new URL('../parent/sw.js', import.meta.url), 'utf8'), ctx);
    const run = async (type, extra) => {
        let waited = null, responded;
        const e = { waitUntil: p => { waited = p; }, respondWith: p => { responded = p; }, ...extra };
        handlers[type](e);
        if (waited) await waited;
        return responded === undefined ? 'passthrough' : await responded;
    };
    return { stores, run };
}
const req = (path, opts = {}) => new Request(new URL(path, SCOPE).href, opts);
const navReq = (path) => Object.defineProperty(req(path), 'mode', { value: 'navigate' });
// בדפדפן תשובה מאותו מקור היא type 'basic'; ב-Node היא 'default' — מדמים כמו בדפדפן.
const ok = (body, headers = {}) => Object.defineProperty(new Response(body, { status: 200, headers }), 'type', { value: 'basic' });

console.log('\n— שדרוג: מחיקת מטמונים —');
{
    const e = env({ network: async () => ok('x') });
    for (const n of ['migdalor-parent-v3', 'other-app-v1', 'project-manager-shell']) await caches_open(e, n);
    await e.run('install');
    await e.run('activate');
    const keys = [...e.stores.keys()].sort();
    check('המטמון הישן שלנו נמחק', keys.includes('migdalor-parent-v3'), false);
    check('מטמונים של אפליקציות אחרות באותו מקור — שורדים', ['other-app-v1', 'project-manager-shell'].every(k => keys.includes(k)), true);
    check('המטמון החדש קיים', keys.includes('migdalor-parent-v4'), true);
}
async function caches_open(e, n) { e.stores.set(n, new Map()); }

console.log('\n— מה נשמר —');
{
    let next = () => ok('{"child":"נועה"}', { 'Content-Type': 'application/json' });
    const e = env({ network: async () => next() });
    const r = await e.run('fetch', { request: req('api.php?action=child&id=3') });
    check('API — לא מיורט ולא נשמר', r, 'passthrough');
    next = () => ok('// js', { 'Cache-Control': 'no-store' });
    await e.run('fetch', { request: req('app.js') });
    check('תשובה עם no-store — לא נשמרת', (e.stores.get('migdalor-parent-v4') || new Map()).has(new URL('app.js', SCOPE).href), false);
    next = () => Object.defineProperty(ok('<form>login</form>'), 'redirected', { value: true });
    await e.run('fetch', { request: navReq('./') });
    check('הפניה (לדף התחברות) — לא נשמרת', (e.stores.get('migdalor-parent-v4') || new Map()).has(SCOPE), false);
    next = () => ok('/* css */');
    const other = await e.run('fetch', { request: req('../../other-tool/data.json') });
    check('קובץ שאינו מהמעטפת — לא מיורט', other, 'passthrough');
    next = () => ok('// app v2');
    await e.run('fetch', { request: req('app.js?v=2') });
    check('משאב מהמעטפת — נשמר (בלי השאילתה)', (e.stores.get('migdalor-parent-v4') || new Map()).has(new URL('app.js', SCOPE).href), true);
}

console.log('\n— בלי רשת —');
{
    let online = true;
    const e = env({ network: async (r) => { if (!online) throw new TypeError('offline'); return ok(r.url.endsWith('index.html') || r.url.endsWith('/') ? '<html>shell</html>' : 'asset'); } });
    await e.run('install');
    online = false;
    const page = await e.run('fetch', { request: navReq('./?child=3') });
    check('ניווט בלי רשת — המעטפת נפתחת', await page.text(), '<html>shell</html>');
    const js = await e.run('fetch', { request: req('app.js') });
    check('משאב מהמעטפת — מהמטמון', await js.text(), 'asset');
    const e2 = env({ network: async () => { throw new TypeError('offline'); } });
    const off = await e2.run('fetch', { request: navReq('./') });
    check('בלי רשת ובלי מעטפת שמורה — מסך לא מקוון ברור', [off.status, (await off.text()).includes('אין חיבור לאינטרנט')], [503, true]);
}

console.log(`\n${pass} עברו, ${fail} נכשלו`);
process.exit(fail ? 1 : 0);
