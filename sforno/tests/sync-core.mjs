/*
 * בדיקות הלוגיקה של הסנכרון במכשיר (assets/js/sync-core.js) — מורץ מ-tests/i-sync.test.php:
 *   node tests/sync-core.mjs                 → {n, fails: [שם…]}
 *   node tests/sync-core.mjs payload <kv>    → הפריטים שמכשיר אורח שולח במיזוג (כדי שה-PHP ישלח אותם לשרת האמיתי)
 * בלי דפדפן ובלי תלויות.
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const C = require(new URL('../assets/js/sync-core.js', import.meta.url).pathname);

if (process.argv[2] === 'payload') {
    const kv = JSON.parse(readFileSync(process.argv[3], 'utf8'));
    process.stdout.write(JSON.stringify(C.batch(C.guestOutbox(kv), 1000).map(s => s.item)));
    process.exit(0);
}

let n = 0;
const fails = [];
const ok = (name, cond) => { n++; if (!cond) fails.push(name); };
const same = (a, b) => C.stable(a) === C.stable(b);
const ED = 'abcdefghijklmnop';

/** שרת מדומה — אותם כללים כמו lib/sync.php (שם הם נבדקים מול המסד), כדי לבדוק את המחזור במכשיר. */
function server() {
    const items = {};
    let rev = 0;
    return {
        items,
        push(list) {
            return { results: list.map(it => {
                const cur = items[it.pid];
                let status;
                if (!cur) status = 'new';
                else if (it.create_only) status = 'kept';
                else if (cur.deleted && !it.deleted && (it.base || 0) < cur.rev) status = 'gone';
                else if (!!it.deleted === cur.deleted && (it.deleted || C.stable(it.data) === C.stable(cur.data))) status = 'same';
                else status = 'saved';
                if (status === 'new' || status === 'saved') items[it.pid] = { pid: it.pid, kind: it.kind, ref: it.ref, data: it.deleted ? null : it.data, deleted: !!it.deleted, rev: ++rev };
                return { pid: it.pid, status, item: items[it.pid] };
            }), cursor: rev };
        },
        pull(since) { const l = Object.values(items).filter(i => i.rev > since).sort((a, b) => a.rev - b.rev); return { items: l, cursor: l.length ? l[l.length - 1].rev : since, more: false }; },
    };
}

/** מכשיר: kv (SF.store), state, outbox; set → local; sync → push + pull + views. online=false → הבקשה נכשלת. */
function device(owner) {
    const d = { kv: {}, state: Object.assign(C.emptyState(), { owner }), outbox: {}, online: true };
    d.set = (k, v) => { d.kv[k] = v; C.local(d.state, d.outbox, k, v); };
    d.sync = srv => {
        if (!d.online) return false;
        const keys = new Set();
        const sent = C.batch(d.outbox);
        if (sent.length) C.applyPush(d.state, d.outbox, sent, srv.push(sent.map(s => s.item))).keys.forEach(k => keys.add(k));
        const p = srv.pull(d.state.cursor);
        C.applyPull(d.state, p.items, p.cursor).forEach(k => keys.add(k));
        for (const k of keys) { const v = C.view(d.state, d.outbox, k); if (v === undefined) delete d.kv[k]; else d.kv[k] = v; }
        return true;
    };
    return d;
}
const mark = (id, extra = {}) => Object.assign({ id, edition: ED, sid: 's' + id, type: 'bookmark', created_at: '2026-11-22T10:00:0' + id.length + 'Z', updated_at: '2026-11-22T10:00:00Z' }, extra);

// ── המיפוי בין הרשומות במכשיר לפריטים ──────────────────────
const kvAll = {
    settings: { font: 1.2, theme: 'sepia' }, customs: { nusach: 'sefarad' }, ['pos:' + ED]: { sid: 's3', off: 0.2, mode: 'flow', rev: 'r1' },
    ['order:' + ED]: { order: ['a'], names: { a: 'שחרית' } }, 'siddur:edition': ED,
    marks: [mark('a1'), mark('b22', { type: 'note', note: 'הערה' }), mark('c333', { type: 'highlight', color: 'yellow' })],
    favorites: [{ id: 'f1', edition: ED, sid: 's1', name: 'עלינו', created_at: '2026-11-22T09:00:00Z' }],
    tehillim: { done: { s1: '2026-11-22T08:00:00Z' }, group: ['s1'], names: [{ id: 'n1', name: 'פלוני בן פלונית', note: 'לרפואת', created_at: '2026-11-22T07:00:00Z' }] },
    'seen:local': true, 'tehillim:tab': 'group',
    // 08: תזכורות (כל אחת פריט) והעדפות התזכורות; push:dev — מכשיר ה-Push של הדפדפן הזה, לא מסתנכרן
    reminders: [{ id: 'r1', name: 'שחרית', type: 'fixed', time: '06:30', days: [0, 1, 2, 3, 4], active: true, created_at: '2026-11-22T06:00:00Z' },
        { id: 'r2', name: '', type: 'relative', base: 'sunset', offset: -20, days: [5], active: true, created_at: '2026-11-22T06:01:00Z' }],
    remprefs: { channels: { push: true, inapp: true, email: false }, extend: { before: 0, after: 30 }, tz: 'Asia/Jerusalem' },
    'push:dev': 'abcdefghijklmnop',
};
for (const [k, v] of Object.entries(kvAll)) {
    const its = C.itemsFor(k, v);
    if (!C.syncable(k)) { ok(`${k} — תלוי מכשיר, לא מסתנכרן`, its.length === 0); continue; }
    ok(`${k} → פריטים → ${k} (הלוך וחזור)`, its.length > 0 && its.every(i => C.keyOf(i.pid) === k) && same(C.build(k, Object.fromEntries(its.map(i => [i.pid, i.data]))), v));
}
const kinds = Object.fromEntries(Object.entries(kvAll).flatMap(([k, v]) => C.itemsFor(k, v)).map(i => [i.pid, i.kind]));
ok('סוגים: הערה = note, הדגשה = highlight, סימניה = bookmark', kinds['m:b22'] === 'note' && kinds['m:c333'] === 'highlight' && kinds['m:a1'] === 'bookmark');
ok('שם לתפילה = prayer_name (מוצפן בשרת)', kinds['pn:n1'] === 'prayer_name');
ok('מיקום קריאה = position, עם ref המהדורה', C.itemsFor('pos:' + ED, kvAll['pos:' + ED])[0].ref === ED && kinds['position:' + ED] === 'position');
ok('pid של פריט שלא מוכר במכשיר — מתעלמים', C.keyOf('reminder:x') === null);
ok('08: תזכורת = פריט reminder, pid rem:<id>', kinds['rem:r1'] === 'reminder' && kinds['rem:r2'] === 'reminder' && kinds['pref:remprefs'] === 'pref');

// ── K6: אותו שינוי פעמיים ← פריט אחד ─────────────────────────
{
    const srv = server();
    const a = device('R');
    a.set('marks', [mark('a1')]);
    ok('K6 סימניה חדשה — שינוי אחד בתור, base 0', Object.keys(a.outbox).length === 1 && a.outbox['m:a1'].base === 0);
    a.set('marks', [mark('a1')]);
    ok('K6 אותו ערך שוב — לא נכנס שוב לתור', Object.keys(a.outbox).length === 1);
    const sent = C.batch(a.outbox);
    srv.push(sent.map(s => s.item));                     // הגיע לשרת, אבל התשובה אבדה (הרשת נפלה)
    ok('K6 בלי תשובה — נשאר בתור', Object.keys(a.outbox).length === 1);
    a.sync(srv);                                          // שליחה חוזרת
    ok('K6 שליחה חוזרת — עדיין פריט אחד בשרת', Object.keys(srv.items).length === 1 && srv.items['m:a1'].rev === 1);
    ok('K6 התור התרוקן', Object.keys(a.outbox).length === 0);
}

// ── K4: סימניה במכשיר א' ← במכשיר ב'; עריכה במכשיר ב' בלי רשת ← נשלחת בחזרת חיבור ──
{
    const srv = server();
    const a = device('R'), b = device('R');
    a.set('marks', [mark('a1')]);
    a.sync(srv);
    b.sync(srv);
    ok('K4 הסימניה של א\' מופיעה ב-ב\'', (b.kv.marks || []).length === 1 && b.kv.marks[0].id === 'a1');
    b.online = false;
    b.set('marks', [Object.assign({}, b.kv.marks[0], { type: 'note', note: 'נכתב בלי רשת' })]);
    ok('K4 בלי רשת — הסנכרון נכשל, והשינוי בתור', b.sync(srv) === false && b.outbox['m:a1'] && b.outbox['m:a1'].data.note === 'נכתב בלי רשת');
    ok('K4 …והמכשיר מציג את השינוי ("שמור במכשיר")', C.view(b.state, b.outbox, 'marks')[0].note === 'נכתב בלי רשת');
    b.online = true;
    b.sync(srv);
    ok('K4 בחזרת חיבור — נשלח', srv.items['m:a1'].data.note === 'נכתב בלי רשת' && !Object.keys(b.outbox).length);
    a.sync(srv);
    ok('K4 …ומגיע למכשיר א\'', a.kv.marks[0].note === 'נכתב בלי רשת');
    // שינוי בזמן שהשליחה בדרך — לא הולך לאיבוד
    const sent = C.batch((b.set('settings', { font: 1 }), b.outbox));
    b.set('settings', { font: 2 });
    C.applyPush(b.state, b.outbox, sent, srv.push(sent.map(s => s.item)));
    ok('K4 שינוי שנעשה בזמן שהשליחה בדרך — נשאר בתור', b.outbox['pref:settings'] && b.outbox['pref:settings'].data.font === 2);
    // סימניות שונות משני המכשירים מתמזגות
    a.set('marks', a.kv.marks.concat([mark('x9')]));
    b.set('marks', b.kv.marks.concat([mark('y8')]));
    a.sync(srv); b.sync(srv); a.sync(srv);
    ok('K4 שתי סימניות שונות משני מכשירים — שתיהן בשניהם', same(a.kv.marks.map(m => m.id).sort(), ['a1', 'x9', 'y8']) && same(b.kv.marks.map(m => m.id).sort(), ['a1', 'x9', 'y8']));
}

// ── K5: מחיקה במכשיר א' ← לא חוזרת ממכשיר ב' שהיה מנותק ─────
{
    const srv = server();
    const a = device('R'), b = device('R');
    a.set('marks', [mark('a1'), mark('b22')]);
    a.sync(srv); b.sync(srv);
    b.online = false;
    a.set('marks', a.kv.marks.filter(m => m.id !== 'a1'));
    ok('K5 מחיקה — נכנסת לתור כמחיקה, עם ה-rev המוכר', a.outbox['m:a1'] && a.outbox['m:a1'].deleted === true && a.outbox['m:a1'].base === 1);
    a.sync(srv);
    ok('K5 בשרת — tombstone, לא נעלם', srv.items['m:a1'].deleted === true);
    b.set('marks', b.kv.marks.map(m => m.id === 'a1' ? Object.assign({}, m, { color: 'נערך בזמן הניתוק' }) : m));   // ב' ערך את מה שנמחק
    b.online = true;
    b.sync(srv);
    ok('K5 העריכה הישנה של ב\' נדחתה — הפריט לא חזר', srv.items['m:a1'].deleted === true);
    ok('K5 …וב\' מוחק אותו אצלו', same(b.kv.marks.map(m => m.id), ['b22']));
    a.sync(srv);
    ok('K5 …וגם א\' לא רואה אותו חוזר', same(a.kv.marks.map(m => m.id), ['b22']));
}

// ── K7: שעון מכשיר מקודם בשנה לא גובר ──────────────────────
{
    const srv = server();
    const fast = device('R'), b = device('R');
    fast.set('settings', { theme: 'dark', updated_at: '2027-11-22T00:00:00Z' });   // השעון של המכשיר בשנה הבאה
    fast.sync(srv);
    b.sync(srv);
    b.set('settings', { theme: 'sepia', updated_at: '2026-11-22T00:00:00Z' });     // שינוי חדש יותר — בשעון אמיתי
    b.sync(srv);
    fast.sync(srv);
    ok('K7 השינוי החדש בשרת קובע, למרות התאריך "מהעתיד"', srv.items['pref:settings'].data.theme === 'sepia' && fast.kv.settings.theme === 'sepia');
    const st = C.emptyState();
    C.applyPull(st, [{ pid: 'pref:settings', kind: 'pref', ref: null, data: { theme: 'new' }, deleted: false, rev: 5 }], 5);
    C.applyPull(st, [{ pid: 'pref:settings', kind: 'pref', ref: null, data: { theme: 'old', updated_at: '2099-01-01T00:00:00Z' }, deleted: false, rev: 4 }], 5);
    ok('K7 משיכה: rev נמוך יותר לא דורס, גם עם תאריך "חדש"', JSON.parse(st.items['pref:settings'].json).theme === 'new');
    ok('K7 הקוד לא קורא את שעון המכשיר להכרעה', !/Date\.now\(\)\s*[<>]|updated_at\s*[<>]|localeCompare\(.*updated_at/.test(readFileSync(new URL('../assets/js/sync-core.js', import.meta.url), 'utf8')));
}

// ── K8: מיזוג אורח ─────────────────────────────────────────
{
    ok('K8 מכשיר ריק / רק ברירות מחדל — אין מה למזג', !C.hasGuestData({}) && !C.hasGuestData({ settings: {}, marks: [], 'seen:local': true, tehillim: { done: {}, group: [], names: [] } }));
    ok('K8 יש סימניה — יש מה למזג', C.hasGuestData({ marks: [mark('a1')] }));
    const out = C.guestOutbox(kvAll);
    const want = Object.entries(kvAll).flatMap(([k, v]) => C.itemsFor(k, v)).length;
    ok('K8 "למזג" — כל הפריטים, כל אחד פעם אחת, כולם create_only', Object.keys(out).length === want && Object.values(out).every(o => o.create_only && o.base === 0));
    ok('K8 אותו מיזוג פעמיים — אותם pid (בלי כפילויות)', same(Object.keys(C.guestOutbox(kvAll)).sort(), Object.keys(out).sort()));
    const srv = server();
    const acc = device('R');
    acc.set('settings', { theme: 'dark' });
    acc.sync(srv);
    const g = device('R');
    g.kv = JSON.parse(JSON.stringify(kvAll));
    g.outbox = C.guestOutbox(g.kv);
    let counts = {};
    const sent = C.batch(g.outbox, 1000);
    const r = C.applyPush(g.state, g.outbox, sent, srv.push(sent.map(s => s.item)));
    counts = r.counts;
    for (const k of r.keys) g.kv[k] = C.view(g.state, g.outbox, k);   // כמו sync.js: writeViews אחרי השליחה
    g.sync(srv);
    ok('K8 הגדרה שקיימת בחשבון — של החשבון קובעת', g.kv.settings.theme === 'dark' && srv.items['pref:settings'].data.theme === 'dark');
    ok('K8 …והסימניות של האורח עברו', (g.kv.marks || []).length === 3);
    ok('K8 הסיכום לקורא', C.mergeSummary(counts) === 'נוספו לחשבון ' + (want - 1) + ' פריטים · 1 הגדרות כבר היו בחשבון — נשארו של החשבון');
    const before = Object.keys(srv.items).length;
    const again = C.guestOutbox(kvAll);
    srv.push(C.batch(again, 1000).map(s => s.item));
    ok('K8 מיזוג חוזר — בלי כפילויות בשרת', Object.keys(srv.items).length === before);
}

// ── "full": נשאר במכשיר, לא נשלח שוב עד שמשתנה ──────────────
{
    const st = C.emptyState(), ob = {};
    st.owner = 'R';
    C.local(st, ob, 'marks', [mark('a1')]);
    const sent = C.batch(ob);
    const r = C.applyPush(st, ob, sent, { results: [{ pid: 'm:a1', status: 'full' }] });
    ok('full — נספר', r.counts.full === 1);
    ok('full — נשאר בתור, מסומן held', ob['m:a1'] && ob['m:a1'].held === true);
    ok('full — מוצג במכשיר', C.view(st, ob, 'marks').length === 1);
    ok('full — לא נשלח שוב', C.batch(ob).length === 0);
    C.local(st, ob, 'marks', [mark('a1', { color: 'x' })]);
    ok('full — אחרי שינוי נשלח שוב', C.batch(ob).length === 1);
}

// ── התור לא נכנס כשאין בעלים (אורח), ואחרי כניסה רק מה שהשתנה ──
{
    const st = C.emptyState(), ob = {};
    C.local(st, ob, 'seen:local', true);
    ok('רשומה תלוית מכשיר — לא נכנסת לתור', Object.keys(ob).length === 0);
    C.applyPull(st, [{ pid: 'm:a1', kind: 'bookmark', ref: ED, data: mark('a1'), deleted: false, rev: 3 }], 3);
    C.local(st, ob, 'marks', [mark('a1')]);
    ok('הערך שכבר בשרת — לא נשלח שוב', Object.keys(ob).length === 0);
    C.local(st, ob, 'marks', [mark('a1', { color: 'x' })]);
    C.local(st, ob, 'marks', [mark('a1')]);
    ok('שינוי שבוטל לפני השליחה — יוצא מהתור', Object.keys(ob).length === 0);
}

process.stdout.write(JSON.stringify({ n, fails }));
