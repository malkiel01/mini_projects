// mbe-handoff (src/js/handoff.js) — שני הצדדים מול חלונות מדומים: הלוך-חזור, ומה שנדחה (מקור זר, חלון זר, id זר).
//   node --test creative-suite/mbe-core/tests/

import { test } from 'node:test';
import assert from 'node:assert/strict';

// חלון מדומה אחד לכל הקובץ: המודול רושם מאזין פעם אחת (צד המקור)
const listeners = new Set();
globalThis.window = {
    addEventListener: (t, fn) => t === 'message' && listeners.add(fn),
    removeEventListener: (t, fn) => listeners.delete(fn),
    opener: null,
    open: null,
};
globalThis.location = { origin: 'https://mbe-plus.com', href: 'https://mbe-plus.com/mini_projects/greeting-cards/editor/', search: '' };
const store = new Map();
globalThis.sessionStorage = { getItem: k => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: k => store.delete(k) };
const deliver = (data, { origin = 'https://mbe-plus.com', source }) => { for (const fn of [...listeners]) fn({ data, origin, source }); };
const fakeWin = () => { const sent = []; return { sent, closed: false, postMessage: (data, target, transfer) => sent.push({ data, target, transfer }) }; };
const H = await import('../src/js/handoff.js');
const tick = () => new Promise(r => setTimeout(r, 0));

test('היעד: ready, open רק מה-opener ומהמקור הנכון, returnHandoff ו-endHandoff', async () => {
    const opener = fakeWin();
    window.opener = opener;
    location.search = '?mbe-handoff=abc';
    const got = [];
    assert.equal(H.acceptHandoff(x => got.push(x), { defaultName: 'ציור.mbe' }), true);
    assert.deepEqual(opener.sent[0], { data: { proto: 'mbe-handoff', v: 1, type: 'ready', id: 'abc' }, target: 'https://mbe-plus.com', transfer: undefined });

    const file = new Uint8Array([1, 2, 3]).buffer;
    const msg = { proto: 'mbe-handoff', v: 1, type: 'open', id: 'abc', file, from: { app: 'greeting-cards', name: 'סטודיו העיצוב' } };
    deliver(msg, { origin: 'https://evil.example', source: opener });          // מקור זר
    deliver(msg, { source: fakeWin() });                                        // חלון אחר
    deliver({ ...msg, id: 'zzz' }, { source: opener });                         // id אחר
    deliver({ ...msg, v: 2 }, { source: opener });                              // גרסה אחרת
    assert.equal(got.length, 0);
    deliver(msg, { source: opener });
    assert.equal(got.length, 1);
    assert.equal(got[0].file.name, 'ציור.mbe');
    assert.deepEqual(got[0].from, { app: 'greeting-cards', name: 'סטודיו העיצוב' });
    assert.deepEqual([...new Uint8Array(await got[0].file.arrayBuffer())], [1, 2, 3]);
    deliver(msg, { source: opener });
    assert.equal(got.length, 1, 'open שני — כבר לא מאזינים');

    const h = H.activeHandoff();
    assert.equal(h.id, 'abc');
    const bytes = new Uint8Array([9, 8]);
    const back = H.returnHandoff(h, bytes);
    const ret = opener.sent.at(-1);
    assert.equal(ret.data.type, 'return');
    assert.equal(ret.target, 'https://mbe-plus.com');
    assert.deepEqual([...new Uint8Array(ret.data.file)], [9, 8]);
    assert.deepEqual([...bytes], [9, 8], 'נשלח עותק — המקור נשאר');
    deliver({ proto: 'mbe-handoff', v: 1, type: 'returned', id: 'abc' }, { origin: 'https://evil.example', source: opener });
    deliver({ proto: 'mbe-handoff', v: 1, type: 'returned', id: 'abc' }, { source: opener });
    await back;
    H.endHandoff();
    assert.equal(H.activeHandoff(), null);

    location.search = '';
    assert.equal(H.acceptHandoff(() => {}), false, 'בלי ?mbe-handoff — לא העברה');
    window.opener = null;
    location.search = '?mbe-handoff=abc';
    assert.equal(H.acceptHandoff(() => {}), false, 'בלי opener — לא העברה');
    await assert.rejects(H.returnHandoff({ id: 'abc' }, bytes), /closed/);
    location.search = '';
});

test('המקור: openIn — שולח רק אחרי ready מהחלון שפתח, ומקבל את הקובץ בחזרה', async () => {
    const win = fakeWin();
    let opened;
    window.open = (url, target) => { opened = { url, target }; return win; };
    const returned = [], events = [], fallbacks = [];
    const id = H.openIn('../../vector-studio/', Promise.resolve({ bytes: new Uint8Array([5, 6, 7]), name: 'כרטיס.mbe' }), {
        from: { app: 'greeting-cards', name: 'סטודיו העיצוב' },
        onReturn: b => returned.push([...b]), onFallback: w => fallbacks.push(w), onEvent: t => events.push(t),
    });
    assert.equal(opened.url, `https://mbe-plus.com/mini_projects/vector-studio/?mbe-handoff=${id}`);
    assert.equal(opened.target, '_blank');

    deliver({ proto: 'mbe-handoff', v: 1, type: 'ready', id }, { source: fakeWin() });              // חלון אחר
    deliver({ proto: 'mbe-handoff', v: 1, type: 'ready', id }, { origin: 'https://evil.example', source: win });
    deliver({ proto: 'mbe-handoff', v: 1, type: 'ready', id: 'other' }, { source: win });
    await tick();
    assert.equal(win.sent.length, 0);
    deliver({ proto: 'mbe-handoff', v: 1, type: 'ready', id }, { source: win });
    await tick();
    const open = win.sent[0];
    assert.equal(open.target, 'https://mbe-plus.com');
    assert.deepEqual({ ...open.data, file: [...new Uint8Array(open.data.file)] },
        { proto: 'mbe-handoff', v: 1, type: 'open', id, name: 'כרטיס.mbe', file: [5, 6, 7], from: { app: 'greeting-cards', name: 'סטודיו העיצוב' } });
    assert.equal(open.transfer[0], open.data.file);

    deliver({ proto: 'mbe-handoff', v: 1, type: 'return', id, file: new Uint8Array([1]).buffer }, { origin: 'https://evil.example', source: win });
    deliver({ proto: 'mbe-handoff', v: 1, type: 'return', id, file: new Uint8Array([4, 4]).buffer }, { source: win });
    assert.deepEqual(returned, [[4, 4]]);
    assert.deepEqual(win.sent[1], { data: { proto: 'mbe-handoff', v: 1, type: 'returned', id }, target: 'https://mbe-plus.com', transfer: undefined });
    assert.deepEqual(events, ['open', 'sent', 'return']);
    assert.deepEqual(fallbacks, []);

    window.open = () => null;
    assert.equal(H.openIn('../../raster-studio/', Promise.resolve({ bytes: new Uint8Array(), name: 'x' }), { onReturn() {}, onFallback: w => fallbacks.push(w) }), null);
    assert.deepEqual(fallbacks, ['blocked']);
});
