/*
 * ספורנו — הסנכרון במכשיר (אפיון 05 §4–§6). הלוגיקה עצמה — sync-core.js; כאן: שמירה ב-SF.store, רשת, תזמון וחיווי.
 *
 *   כל שינוי ברשומה שמסתנכרנת (SF.store.set) → התור (sync:outbox, ב-IndexedDB) → נשלח אחרי שנייה וחצי, בחזרת חיבור
 *   (online), בחזרה ללשונית, ופעם בדקה. כשל רשת — ננסה שוב (5 ש', 10, 20… עד 2 דקות). קודם שולחים, אחר כך מושכים.
 *   החיווי (§14): local — "שמור במכשיר" · synced — "מסונכרן" · failed — "כשל — ננסה שוב" · busy · off (אין חשבון).
 *
 *   כניסה ראשונה במכשיר עם מידע של אורח → "למזג לחשבון" / "להשאיר רק במכשיר" (§5).
 *   יציאה → "למחוק את המידע האישי מהמכשיר הזה?" (§6; ברירת המחדל: כן).
 */
'use strict';

(function (SF) {
    const C = window.SFSyncCore;
    let state = null, outbox = null, reader = null;
    let status = 'off', lastOk = null, fails = 0, timer = 0, running = null, again = false, wired = false;
    const listeners = new Set();

    async function load() {
        if (state) return;
        state = (await SF.store.get('sync:state', null)) || C.emptyState();
        outbox = (await SF.store.get('sync:outbox', null)) || {};
    }
    async function save() {
        await SF.store.setRaw('sync:state', state);
        await SF.store.setRaw('sync:outbox', outbox);
    }
    const pending = () => Object.values(outbox || {}).filter(o => !o.held).length;
    function setStatus(s) {
        status = s;
        for (const fn of listeners) { try { fn(SF.sync.info()); } catch (e) { /* מאזין של מסך שנסגר */ } }
    }

    /** כל הרשומות שמסתנכרנות במכשיר — {key: value} */
    async function snapshot() {
        const out = {};
        for (const k of await SF.store.keys()) if (C.syncable(k)) out[k] = await SF.store.get(k, undefined);
        return out;
    }
    async function wipeLocal() {
        for (const k of await SF.store.keys()) if (C.syncable(k)) await SF.store.delRaw(k);
    }
    async function writeViews(keys) {
        for (const k of keys) {
            const v = C.view(state, outbox, k);
            if (v === undefined) await SF.store.delRaw(k); else await SF.store.setRaw(k, v);
        }
    }

    function schedule(ms) {
        clearTimeout(timer);
        timer = setTimeout(() => cycle(), ms);
    }

    /** מחזור אחד: שליחת התור, ואז משיכה מה-cursor. מחזיר את הספירות של השליחה (לסיכום המיזוג). */
    async function cycle() {
        if (running) { again = true; return running; }
        running = (async () => {
            await load();
            const counts = {};
            if (!reader || !state.owner) return counts;
            if (navigator.onLine === false) { setStatus(pending() ? 'local' : 'synced'); return counts; }
            setStatus('busy');
            const keys = new Set();
            try {
                for (let i = 0; i < 100; i++) {
                    const sent = C.batch(outbox, 200);
                    if (!sent.length) break;
                    const res = await SF.api('sync-push', { body: { items: sent.map(s => s.item) } });
                    const r = C.applyPush(state, outbox, sent, res);
                    r.keys.forEach(k => keys.add(k));
                    for (const [k, n] of Object.entries(r.counts)) counts[k] = (counts[k] || 0) + n;
                    await save();
                }
                if (counts.full) SF.toast('אין עוד מקום בחשבון — חלק מהשינויים נשמרו רק במכשיר הזה', true);
                for (let i = 0; i < 100; i++) {
                    const res = await SF.api('sync-pull', { params: { since: String(state.cursor) } });
                    C.applyPull(state, res.items, res.cursor).forEach(k => keys.add(k));
                    await save();
                    if (!res.more) break;
                }
                await writeViews(keys);
                fails = 0;
                lastOk = new Date().toISOString();
                setStatus(pending() ? 'local' : 'synced');
                if (keys.size) changed();
            } catch (e) {
                await writeViews(keys);
                if (e.code === 'reader_login') {   // המכשיר בוטל / החשבון נמחק / יצאו במכשיר אחר עם איפוס
                    reader = null;
                    SF.setReaderCsrf('');
                    setStatus('off');
                    if (SF.account) SF.account.lost();
                    return counts;
                }
                fails++;
                setStatus('failed');
                schedule(Math.min(120000, 5000 * 2 ** Math.min(fails - 1, 5)));
            }
            return counts;
        })();
        try {
            return await running;
        } finally {
            running = null;
            if (again) { again = false; schedule(300); }
        }
    }

    /** הגיע מידע מהשרת — מסכי הרשימות מתרעננים (הקורא עצמו לא: הוא שומר את המקום ולא נקפוץ באמצע קריאה). */
    function changed() {
        if (document.querySelector('dialog[open]')) return;
        if (/^#\/(siddur|tehillim|account)?\/?$/.test(location.hash || '#/')) SF.render();
    }

    function wire() {
        if (wired) return;
        wired = true;
        window.addEventListener('online', () => cycle());
        window.addEventListener('offline', () => setStatus(pending() ? 'local' : status));
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') cycle(); });
        setInterval(() => { if (document.visibilityState === 'visible') cycle(); }, 60000);
    }

    /** שאלת המיזוג (§5). מחזירה 'merge' / 'keep'. */
    function askMerge() {
        return new Promise(resolve => {
            let answer = 'keep';
            const choose = a => { answer = a; dlg.close(); };
            const dlg = SF.sheet('מידע מלפני ההתחברות', SF.h('div', { class: 'account-q' },
                SF.h('p', null, 'יש במכשיר הזה סימניות והגדרות מלפני ההתחברות.'),
                SF.h('div', { class: 'row-btns stack' },
                    SF.h('button', { class: 'btn primary', type: 'button', onclick: () => choose('merge') }, 'למזג לחשבון'),
                    SF.h('button', { class: 'btn ghost', type: 'button', onclick: () => choose('keep') }, 'להשאיר רק במכשיר')),
                SF.h('p', { class: 'hint' }, 'במיזוג — הגדרה שכבר קיימת בחשבון נשארת של החשבון. "להשאיר" — שום דבר לא נשלח, והמידע חוזר למכשיר כשיוצאים מהחשבון.')),
                { onClose: () => resolve(answer) });
        });
    }

    SF.sync = {
        info: () => ({ status, pending: pending(), lastOk, active: !!reader }),
        on(fn) { listeners.add(fn); return () => listeners.delete(fn); },
        /** מחובר לחשבון (המידע האישי מסתנכרן) */
        active: () => !!reader,

        /** שינוי מקומי (מ-SF.store) → התור. כל עוד המכשיר שייך לחשבון — גם אחרי יציאה בלי מחיקה (יישלח בכניסה הבאה). */
        async note(key, value) {
            if (!C.syncable(key)) return;
            await load();
            if (!state.owner) return;
            if (C.local(state, outbox, key, value)) {
                await save();
                if (reader) { setStatus(navigator.onLine === false ? 'local' : status === 'synced' ? 'local' : status); schedule(1500); }
            }
        },

        /** אחרי כניסה (או בהפעלה, כשיש session). */
        async start(r, opts = {}) {
            await load();
            reader = r;
            wire();
            if (state.owner === r.pid) { cycle(); return; }
            if (state.owner) {   // המכשיר היה של חשבון אחר — המידע שלו בשרת; לא מערבבים
                await wipeLocal();
                await SF.store.delRaw('guest:stash');
                state = C.emptyState();
                outbox = {};
            }
            const kv = await snapshot();
            const choice = C.hasGuestData(kv) ? (opts.merge || await askMerge()) : 'none';
            state = Object.assign(C.emptyState(), { owner: r.pid });
            outbox = {};
            if (choice === 'merge') outbox = C.guestOutbox(kv);
            else if (choice === 'keep') {
                await SF.store.setRaw('guest:stash', kv);
                await wipeLocal();
            }
            await save();
            const counts = await cycle();
            if (choice === 'merge' && status !== 'failed') SF.toast(C.mergeSummary(counts));
            if (choice === 'merge' && status === 'failed') SF.toast('המידע ממתין במכשיר — יישלח לחשבון כשיהיה חיבור');
        },

        now: () => cycle(),

        /** יציאה. wipe — מחיקת המידע האישי מהמכשיר (ומידע של אורח ששמרתם "רק במכשיר" — חוזר). */
        async stop(wipe) {
            await load();
            reader = null;
            clearTimeout(timer);
            if (wipe) {
                await wipeLocal();
                const stash = await SF.store.get('guest:stash', null);
                if (stash) for (const [k, v] of Object.entries(stash)) if (C.syncable(k) && v !== undefined) await SF.store.setRaw(k, v);
                await SF.store.delRaw('guest:stash');
                state = C.emptyState();
                outbox = {};
                await save();
            }
            setStatus('off');
        },
    };
})(window.SF);
