/*
 * ספורנו — PWA, הורדות וגרסאות בדפדפן (אפיון 06). הלוגיקה — assets/js/offline-core.js; כאן רק הדפדפן:
 *
 *   - service worker (sw.js, בתיקייה של ספורנו בלבד) שומר את מעטפת האפליקציה לפי הגרסה. גרסה חדשה ← הפס "עדכון זמין —
 *     לרענן". **לא מתחלפת לבד** (לא באמצע תפילה): הקורא לוחץ, ורק אז SKIP_WAITING ורענון. עדכון תוכנה לא נוגע ב-IndexedDB
 *     ולא במטמון ההורדות.
 *   - SF.offline: הורדה מפורשת בלבד (read-package → כל קובץ → sha256 → Cache API "sforno-content"), מחיקה, טעינה מההורדה.
 *   - navigator.storage.persist() בהורדה הראשונה; נדחה — אומרים שהדפדפן עלול למחוק.
 *   - בלי רשת: ‹body class="offline"› והשבב "בלי אינטרנט" בכותרת.
 */
'use strict';

(function (SF) {
    const C = window.SFOfflineCore;
    const { h } = SF;
    const supported = typeof caches !== 'undefined' && !!(window.crypto && crypto.subtle) && window.isSecureContext !== false;
    const base = () => new URL('./', location.href).href;
    const abs = u => new URL(u, base()).href;

    const hex = buf => Array.from(new Uint8Array(buf), b => b.toString(16).padStart(2, '0')).join('');
    const open = () => caches.open(C.CACHE);
    const deps = {
        fetch: url => fetch(url, { credentials: 'same-origin', cache: 'no-store' }),
        sha256: async x => hex(await crypto.subtle.digest('SHA-256', typeof x === 'string' ? new TextEncoder().encode(x) : x)),
        cache: {
            put: async (url, body, type) => (await open()).put(abs(url), new Response(body, { headers: { 'Content-Type': type } })),
            has: async url => !!(await (await open()).match(abs(url))),
            text: async url => { const r = await (await open()).match(abs(url)); return r ? r.text() : null; },
            delete: async url => (await open()).delete(abs(url)),
            keys: async () => (await (await open()).keys()).map(r => r.url.startsWith(base()) ? r.url.slice(base().length) : r.url),
        },
        store: { get: k => SF.store.get(k, null), setRaw: SF.store.setRaw, delRaw: SF.store.delRaw, keys: SF.store.keys },
        now: () => new Date().toISOString(),
    };

    // ── מאזינים להתקדמות (מסך ההורדות והקורא) ───────────────
    const watchers = new Set();
    const running = new Map();   // edition → הרשומה החיה
    const emit = rec => { running.set(rec.edition, rec); for (const fn of watchers) { try { fn(rec); } catch (e) { /* מסך שנסגר */ } } };

    async function askPersist() {
        if (!navigator.storage || !navigator.storage.persist) return null;
        try {
            if (await navigator.storage.persisted()) return true;
            const ok = await navigator.storage.persist();
            if (!ok) SF.toast('הדפדפן לא אישר שמירה קבועה — הוא עלול למחוק את ההורדות כשהמכשיר מתמלא', true);
            return ok;
        } catch (e) {
            return null;
        }
    }

    SF.offline = {
        supported,
        core: C,
        watch(fn) { watchers.add(fn); return () => watchers.delete(fn); },
        running: ed => running.get(ed) || null,
        async record(ed) { return SF.store.get(C.dlKey(ed), null); },
        async records() {
            const out = [];
            for (const k of await SF.store.keys()) if (k.startsWith('dl:')) { const r = await SF.store.get(k, null); if (r) out.push(r); }
            return out.sort((a, b) => String(a.title).localeCompare(String(b.title), 'he'));
        },
        /** הורדה מפורשת של מהדורה (בגרסה rev, או זו שנבחרה). */
        async download(ed, rev) {
            if (!supported) throw new Error('הדפדפן הזה לא תומך בהורדה לקריאה בלי אינטרנט');
            if (running.has(ed) && running.get(ed).state === 'partial' && !running.get(ed).error) throw new Error('ההורדה כבר רצה');
            await askPersist();
            const pkg = await SF.api('read-package', { params: rev ? { edition: ed, revision: rev } : { edition: ed } });
            try {
                return await C.download(Object.assign({}, deps, { progress: emit }), pkg);
            } finally {
                running.delete(ed);
                C.gc(deps).catch(() => {});
            }
        },
        /** מחיקת ההורדה מהמכשיר בלבד — המידע האישי נשאר. */
        async remove(ed) { const n = await C.remove(deps, ed); running.delete(ed); return n; },
        /** הטקסט מההורדה, אם היא שלמה ({rec, data}) — אחרת null. */
        async load(ed) { return supported ? C.load(deps, ed) : null; },
        /** קובץ מההורדה (העמודים) — ArrayBuffer או null. */
        async buffer(url) {
            if (!supported) return null;
            const r = await (await open()).match(abs(url));
            return r ? r.arrayBuffer() : null;
        },
        async usage() {
            try { return navigator.storage && navigator.storage.estimate ? await navigator.storage.estimate() : null; } catch (e) { return null; }
        },
        async persisted() {
            try { return navigator.storage && navigator.storage.persisted ? await navigator.storage.persisted() : null; } catch (e) { return null; }
        },
    };

    // ── הגרסה שנבחרה (ver:<edition>) ────────────────────────
    SF.version = {
        async get(ed) { return C.pinned(await SF.store.get(C.verKey(ed), null), await SF.store.get('pos:' + ed, null)); },
        async raw(ed) { return SF.store.get(C.verKey(ed), null); },
        /** נעיצה ראשונה: מי שקורא — נשאר בגרסה הזו עד שיבחר לעבור (§21.7). */
        async pin(ed, rev) { const cur = await SF.store.get(C.verKey(ed), null); if (!cur && rev) await SF.store.set(C.verKey(ed), { rev, prev: null }); },
        /**
         * מעבר from → to: כל הסימניות / ההדגשות / ההערות / המועדפים / המקום — לפי sid (offline-core migrate). sids — של to.
         * מחזיר {moved, kept}.
         */
        async move(ed, from, to, sids) {
            const m = C.migrate(await SF.store.get('marks', []), ed, from, to, sids);
            const f = C.migrate(await SF.store.get('favorites', []), ed, from, to, sids);
            if (m.moved + m.kept) await SF.store.set('marks', m.list);
            if (f.moved + f.kept) await SF.store.set('favorites', f.list);
            const pos = await SF.store.get('pos:' + ed, null);
            const np = C.movePos(pos, from, to, sids);
            if (np !== pos) await SF.store.set('pos:' + ed, np);
            await SF.store.set(C.verKey(ed), { rev: to, prev: from });
            return { moved: m.moved + f.moved, kept: m.kept + f.kept };
        },
    };

    // ── בלי רשת ───────────────────────────────────────────
    function net() {
        const off = navigator.onLine === false;
        document.body.classList.toggle('offline', off);
        const chip = document.getElementById('net-chip');
        if (chip) chip.hidden = !off;
    }
    window.addEventListener('online', net);
    window.addEventListener('offline', net);
    SF.isOffline = () => navigator.onLine === false;

    // ── service worker ופס "עדכון זמין" ──────────────────────
    function updateBar(reg) {
        if (document.getElementById('sw-update')) return;
        const bar = h('div', { class: 'sw-update', id: 'sw-update', role: 'status' },
            h('span', null, 'עדכון זמין לאפליקציה'),
            h('button', { class: 'btn sm primary', type: 'button', onclick: () => {
                bar.querySelector('button').disabled = true;
                if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                else location.reload();
            } }, 'לרענן'),
            h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'אחר כך', onclick: () => bar.remove() }, '✕'));
        document.body.append(bar);
    }

    function registerSW() {
        if (!('serviceWorker' in navigator) || window.isSecureContext === false) return;
        let reloading = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            // רק אחרי "לרענן" (SKIP_WAITING) — הרישום הראשון לא מחליף מעטפת קיימת
            if (reloading || !SF.swAsked) return;
            reloading = true;
            location.reload();
        });
        navigator.serviceWorker.register('sw.js', { scope: './' }).then(reg => {
            const watch = w => w && w.addEventListener('statechange', () => { if (w.state === 'installed' && navigator.serviceWorker.controller) updateBar(reg); });
            if (reg.waiting && navigator.serviceWorker.controller) updateBar(reg);
            reg.addEventListener('updatefound', () => watch(reg.installing));
            // בדיקה לעדכון כשחוזרים לאפליקציה (לא באמצע — רק פס)
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') reg.update().catch(() => {}); });
        }).catch(() => { /* בלי service worker — האפליקציה עובדת ברשת */ });
        document.addEventListener('click', e => { if (e.target.closest('#sw-update .primary')) SF.swAsked = true; }, true);
    }

    net();
    registerSW();
})(window.SF);
