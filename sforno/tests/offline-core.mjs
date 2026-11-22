/*
 * בדיקות ההורדות והגרסאות במכשיר (assets/js/offline-core.js) וה-service worker (sw.js) — מורץ מ-tests/j-offline.test.php:
 *   node tests/offline-core.mjs <fixture.json>   → {n, fails: [שם…]}
 * ה-fixture נבנה ב-PHP מהשרת האמיתי: התשובה של read-package, והקבצים עצמם (read-revision, תמונה) — כך שה-hash שהמכשיר
 * מחשב נבדק מול זה של השרת. בלי דפדפן ובלי תלויות.
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const O = require(new URL('../assets/js/offline-core.js', import.meta.url).pathname);
const S = require(new URL('../assets/js/sync-core.js', import.meta.url).pathname);
const fx = JSON.parse(readFileSync(process.argv[2], 'utf8'));

let n = 0;
const fails = [];
const ok = (name, cond) => { n++; if (!cond) fails.push(name); };
const ED = fx.pkg.edition.pid;

// ── עולם מדומה: store (IndexedDB), מטמון, רשת ─────────────────
function world(files) {
    const kv = new Map();
    const cache = new Map();
    let calls = 0;
    const net = { down: false, cutAfter: Infinity, tamper: null };
    const deps = {
        net, kv, mem: cache,
        fetch: async url => {
            if (net.down || calls >= net.cutAfter) throw new TypeError('Failed to fetch');
            calls++;
            if (!(url in files)) return { ok: false, status: 404 };
            let buf = Buffer.from(files[url], 'base64');
            if (net.tamper === url) buf = Buffer.concat([buf, Buffer.from('x')]);
            return { ok: true, status: 200, text: async () => buf.toString('utf8'), arrayBuffer: async () => buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.length) };
        },
        sha256: async x => createHash('sha256').update(typeof x === 'string' ? Buffer.from(x, 'utf8') : Buffer.from(x)).digest('hex'),
        cache: {
            put: async (u, body) => { cache.set(u, typeof body === 'string' ? body : Buffer.from(body)); },
            has: async u => cache.has(u),
            text: async u => (cache.has(u) ? cache.get(u).toString() : null),
            delete: async u => cache.delete(u),
            keys: async () => [...cache.keys()],
        },
        store: {
            get: async k => (kv.has(k) ? JSON.parse(kv.get(k)) : null),
            setRaw: async (k, v) => { kv.set(k, JSON.stringify(v)); },
            delRaw: async k => { kv.delete(k); },
            keys: async () => [...kv.keys()],
        },
        now: () => '2026-11-22T10:00:00Z',
    };
    return deps;
}

// ── K1/K2: הורדה שלמה, ו-hash של המכשיר = של השרת ─────────────
{
    const w = world(fx.files);
    const seen = [];
    const rec = await O.download(Object.assign({}, w, { progress: r => seen.push(r.state + ':' + r.done) }), fx.pkg);
    ok('K1 הורדה שלמה ← ready', rec.state === 'ready' && O.ready(rec));
    ok('K1 ה-hash שהמכשיר חישב = של השרת (טקסט עברי ותמונה)', w.mem.size === fx.pkg.files.length + 1);
    ok('K1 התקדמות מדווחת, מ-0 ועד הגודל', seen[0] === 'partial:0' && seen[seen.length - 1] === 'ready:' + fx.pkg.size);
    const ld = await O.load(w, ED);
    ok('K1 ההורדה נפתחת בלי רשת (הטקסט מהמטמון)', !!ld && ld.data.html === fx.html && ld.data.revision.pid === fx.pkg.revision.pid);
    w.net.down = true;
    ok('K1 בלי רשת — עדיין נפתח', !!(await O.load(w, ED)));
    ok('K1 כותר שלא הורד — "לא זמין בלי אינטרנט"', O.status(null).state === 'none' && O.status(null).text === 'לא זמין בלי אינטרנט' && (await O.load(w, 'zzzzzzzzzzzzzzzz')) === null);
    // הדפדפן פינה קובץ — חוזר ל"לא הושלמה"
    w.mem.delete(fx.pkg.files[0].url);
    ok('K2 קובץ שנמחק מהמטמון ← לא נפתח, ו"לא הושלמה"', (await O.load(w, ED)) === null && O.status(await w.store.get(O.dlKey(ED))).state === 'partial');
}

// ── K2: הורדה שנקטעה באמצע ─────────────────────────────────────
{
    const w = world(fx.files);
    w.net.cutAfter = 1;   // הטקסט עבר, התמונה — הרשת נפלה
    let threw = false;
    try { await O.download(w, fx.pkg); } catch (e) { threw = true; }
    const rec = await w.store.get(O.dlKey(ED));
    ok('K2 הורדה שנקטעה ← חריגה', threw);
    ok('K2 נקטעה ← "לא הושלמה", לא "זמין"', rec.state === 'partial' && !O.ready(rec) && O.status(rec).text.startsWith('לא הושלמה'));
    ok('K2 השגיאה לקורא: "אין חיבור"', rec.error === 'אין חיבור');
    ok('K2 נקטעה ← לא נפתחת כזמינה', (await O.load(w, ED)) === null);
    // סגירת הדף באמצע: הרשומה נכתבה partial לפני הקובץ הראשון
    const w2 = world(fx.files);
    let snap = null;
    await O.download(Object.assign({}, w2, { progress: r => { if (snap === null) snap = w2.kv.get(O.dlKey(ED)); } }), fx.pkg);
    ok('K2 לפני הקובץ הראשון — הרשומה כבר "partial" (סגירת הדף באמצע = לא הושלמה)', JSON.parse(snap).state === 'partial');
    // קובץ פגום
    const w3 = world(fx.files);
    w3.net.tamper = fx.pkg.files[0].url;
    let bad = null;
    try { await O.download(w3, fx.pkg); } catch (e) { bad = e.message; }
    ok('K2 קובץ שה-hash שלו לא תואם ← לא "זמין"', /פגום/.test(bad || '') && !O.ready(await w3.store.get(O.dlKey(ED))));
    // ניסיון שני מצליח, והקבצים של הניסיון הקודם לא נשארים יתומים
    w.net.cutAfter = Infinity;
    const again = await O.download(w, fx.pkg);
    ok('K2 להוריד שוב ← ready', O.ready(again));
}

// ── K3: הגרסה שנבחרה ─────────────────────────────────────────
{
    const A = fx.revs.old, B = fx.revs.new;
    ok('K3 הגרסה שנבחרה גוברת', O.pinned({ rev: A, prev: null }, { rev: B }) === A);
    ok('K3 בלי בחירה — הגרסה שהמקום נשמר עליה (לפני 06)', O.pinned(null, { sid: 'x', rev: A }) === A);
    ok('K3 בלי כלום — null (הנוכחית, ואז נעיצה)', O.pinned(null, null) === null);
    ok('K3 ver:<edition> מסתנכרן כ-pref', S.syncable(O.verKey(ED)) && S.itemsFor(O.verKey(ED), { rev: A })[0].kind === 'pref' && S.keyOf('ver:' + ED) === O.verKey(ED));
    ok('K3 dl:/catalog:last/verseen: — לא מסתנכרנים (של המכשיר)', !S.syncable(O.dlKey(ED)) && !S.syncable('catalog:last') && !S.syncable('verseen:' + ED));
    ok('K3 הורדה של גרסה אחרת מהנבחרת — "stale", לא "זמין"', O.status({ state: 'ready', revision: A, n: 1 }, B).state === 'stale' && !O.ready({ state: 'ready', revision: A }, B));
}

// ── K4: מעבר גרסה לפי sid ─────────────────────────────────────
{
    const A = fx.revs.old, B = fx.revs.new;
    const sidsB = O.sidsOf(fx.revs.newHtml);
    ok('K4 sidsOf קורא את ה-sid-ים מהמקור', sidsB.size === fx.revs.newSids.length && fx.revs.newSids.every(s => sidsB.has(s)));
    const keep = fx.revs.keptSid, gone = fx.revs.goneSid;
    const marks = [
        { id: 'a', edition: ED, sid: keep, type: 'note', note: 'הערה על שורה שנשארה', excerpt: 'x' },
        { id: 'b', edition: ED, rev: A, sid: gone, type: 'note', note: 'הערה על שורה שנמחקה', excerpt: 'הטקסט המקורי' },
        { id: 'c', edition: ED, rev: A, sid: keep, type: 'highlight', color: 'yellow' },
        { id: 'd', edition: 'otheredition0000', sid: gone, type: 'bookmark' },
    ];
    const r = O.migrate(marks, ED, A, B, sidsB);
    const by = Object.fromEntries(r.list.map(x => [x.id, x]));
    ok('K4 הערה על sid קיים — עוברת', by.a.rev === B && by.c.rev === B);
    ok('K4 הערה על sid שנמחק — נשארת צמודה לישנה', by.b.rev === A && by.b.note === 'הערה על שורה שנמחקה');
    ok('K4 שום דבר לא נמחק', r.list.length === marks.length && r.moved === 2 && r.kept === 1);
    ok('K4 מהדורה אחרת — לא נוגעים', by.d === marks[3]);
    ok('K4 "לא הותאמו" — רק מה שלא עבר, עם הטקסט המקורי', O.unmatched(r.list, ED, B).map(x => x.id).join() === 'b' && O.unmatched(r.list, ED, B)[0].excerpt === 'הטקסט המקורי');
    ok('K4 בגרסה החדשה מוצג מה שעבר', O.visible(r.list, ED, B).map(x => x.id).join() === 'a,c');
    const back = O.migrate(r.list, ED, B, A, O.sidsOf(fx.revs.oldHtml));
    ok('K4 חזרה לגרסה הקודמת — הכול שוב מותאם', O.unmatched(back.list, ED, A).length === 0 && back.list.length === marks.length);
    ok('K4 המקום: sid קיים עובר; לא קיים — נשאר', O.movePos({ sid: keep, rev: A }, A, B, sidsB).rev === B && O.movePos({ sid: gone, rev: A }, A, B, sidsB).rev === A);
}

// ── K5: מחיקת הורדה לא מוחקת אף פריט אישי ───────────────────────
{
    const w = world(fx.files);
    const personal = { marks: [{ id: 'a', edition: ED, sid: 'x', type: 'note', note: 'n' }], favorites: [{ id: 'f', edition: ED, sid: 'x' }], settings: { size: 30 },
        ['pos:' + ED]: { sid: 'x', rev: fx.pkg.revision.pid }, [O.verKey(ED)]: { rev: fx.pkg.revision.pid }, tehillim: { done: {}, group: [], names: [{ id: 'p', name: 'שם' }] },
        'sync:outbox': { x: 1 }, 'sync:state': { cursor: 3 } };
    for (const [k, v] of Object.entries(personal)) await w.store.setRaw(k, v);
    await O.download(w, fx.pkg);
    const other = Object.assign({}, fx.pkg, { edition: { pid: 'otheredition0000', label: 'x' } });
    other.files = fx.pkg.files.slice(0, 1);
    // הורדה שנייה שחולקת קובץ (תמונה) עם הראשונה
    const w2 = Object.assign({}, w, { fetch: async u => (u.includes('otheredition0000') ? { ok: true, status: 200, text: async () => JSON.stringify(Object.assign(JSON.parse(Buffer.from(fx.files[O.revUrl(ED, fx.pkg.revision.pid)], 'base64').toString()), { edition: { pid: 'otheredition0000' } })) } : w.fetch(u)) });
    await O.download(w2, other);
    await O.remove(w, ED);
    const left = {};
    for (const k of Object.keys(personal)) left[k] = await w.store.get(k);
    ok('K5 מחיקת הורדה — כל הפריטים האישיים נשארים, בלי שינוי', JSON.stringify(left) === JSON.stringify(personal));
    ok('K5 מחיקת הורדה — הרשומה dl: נמחקה', (await w.store.get(O.dlKey(ED))) === null);
    ok('K5 קובץ משותף להורדה אחרת — נשאר', await w.cache.has(fx.pkg.files[0].url));
    ok('K5 הטקסט של ההורדה שנמחקה — נמחק מהמטמון', !(await w.cache.has(O.revUrl(ED, fx.pkg.revision.pid))));
    await O.remove(w, 'otheredition0000');
    ok('K5 אחרי מחיקת שתיהן — המטמון ריק', w.mem.size === 0);
}

// ── K6: ה-service worker — עדכון תוכנה לא נוגע בהורדות ובמידע האישי ───
{
    const src = readFileSync(new URL('../sw.js', import.meta.url), 'utf8');
    const handlers = {};
    const deleted = [];
    let skipped = 0, claimed = 0;
    const store = ['sforno-shell-0.5-aaaaaaaaaaaa', 'sforno-content', 'family-cache', 'other-app-v1'];
    const self = {
        registration: { scope: 'https://example.test/apps/sforno/' },
        location: { origin: 'https://example.test' },
        addEventListener: (t, fn) => { handlers[t] = fn; },
        skipWaiting: () => { skipped++; },
        clients: { claim: async () => { claimed++; } },
    };
    const caches = {
        keys: async () => store.slice(),
        delete: async k => { deleted.push(k); return true; },
        open: async () => ({ addAll: async () => {}, match: async () => null }),
        match: async () => null,
    };
    const ctx = vm.createContext({ self, caches, URL, Request: class { constructor(u) { this.url = u; } }, Response, fetch: async () => ({ ok: true }), Promise, console });
    vm.runInContext(src, ctx);
    let wait = null;
    handlers.install({ waitUntil: p => { wait = p; } });
    await wait;
    ok('K6 התקנה — בלי skipWaiting (לא מתחלף באמצע תפילה)', skipped === 0);
    handlers.message({ data: { type: 'SKIP_WAITING' } });
    ok('K6 "לרענן" (SKIP_WAITING) — מתחלף', skipped === 1);
    handlers.activate({ waitUntil: p => { wait = p; } });
    await wait;
    ok('K6 activate מוחק רק מעטפת ישנה של ספורנו', deleted.join() === 'sforno-shell-0.5-aaaaaaaaaaaa' && claimed === 1);
    ok('K6 activate לא מוחק את ההורדות ולא מטמון של אפליקציה אחרת', !deleted.includes('sforno-content') && !deleted.includes('family-cache') && !deleted.includes('other-app-v1'));
    ok('K6 ה-service worker לא נוגע ב-IndexedDB', !/indexedDB/.test(src));
    // fetch: מחוץ לתיקייה / POST / API — לא נוגע
    const res = [];
    const ev = (url, extra = {}) => ({ request: Object.assign({ url, method: 'GET', mode: 'cors' }, extra), respondWith: p => res.push(url) });
    handlers.fetch(ev('https://example.test/other-app/index.html'));
    handlers.fetch(ev('https://example.test/apps/sforno/api.php?action=sync-pull'));
    handlers.fetch(ev('https://example.test/apps/sforno/api.php?action=read-revision&edition=x'));
    handlers.fetch(ev('https://example.test/apps/sforno/api.php?action=read-file&path=files/x/1.png', { method: 'POST' }));
    handlers.fetch(ev('https://example.test/apps/sforno/data/sforno.sqlite'));
    handlers.fetch(ev('https://elsewhere.test/apps/sforno/index.html'));
    ok('K6 מחוץ לתיקייה, API, POST, data/ — לא נוגע', res.length === 0);
    handlers.fetch(ev('https://example.test/apps/sforno/api.php?action=read-file&path=files/x/1.png'));
    handlers.fetch(ev('https://example.test/apps/sforno/assets/js/app.js'));
    handlers.fetch(ev('https://example.test/apps/sforno/', { mode: 'navigate' }));
    ok('K6 תמונה מהורדה, קובצי המעטפת והדף — מוגשים (גם בלי רשת)', res.length === 3);
}

process.stdout.write(JSON.stringify({ n, fails }));
