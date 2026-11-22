/*
 * ספורנו — הלוגיקה של הסנכרון (אפיון 05 §4–§5), בלי DOM ובלי רשת — כך שהיא נבדקת ב-node (tests/sync-core.mjs).
 *
 * המכשיר שומר את המידע האישי ב-SF.store כמו ב-04 (marks, favorites, tehillim, settings, pos:<ed>…). כאן — ההמרה בין
 * הרשומות האלה לבין "פריטים" של השרת (pid, kind, ref, data), ושני מבנים:
 *
 *   state  = {owner, cursor, items: {pid: {kind, ref, rev, json, del}}} — מה שהשרת אמר בפעם האחרונה
 *   outbox = {pid: {pid, kind, ref, data | deleted, base, create_only, seq}} — שינויים שעוד לא התקבלו (התור ללא רשת)
 *
 * התצוגה במכשיר = state + outbox. **שעון המכשיר לא משמש להכרעה** — רק rev של השרת.
 */
'use strict';

(function (root, factory) {
    const C = factory();
    if (typeof module === 'object' && module.exports) module.exports = C;
    else root.SFSyncCore = C;
})(typeof self !== 'undefined' ? self : this, function () {
    const MARK_KIND = { note: 'note', highlight: 'highlight', bookmark: 'bookmark' };

    /** JSON יציב (סדר מפתחות קבוע) — להשוואה. */
    function stable(v) {
        if (Array.isArray(v)) return '[' + v.map(stable).join(',') + ']';
        if (v && typeof v === 'object') return '{' + Object.keys(v).sort().filter(k => v[k] !== undefined).map(k => JSON.stringify(k) + ':' + stable(v[k])).join(',') + '}';
        return JSON.stringify(v === undefined ? null : v);
    }

    /** האם הרשומה במכשיר מסתנכרנת. seen:local, tehillim:tab וכו' — תלויי מכשיר (§14: גם ערוצי התראות והרשאות). */
    function syncable(key) {
        return key === 'settings' || key === 'customs' || key === 'marks' || key === 'favorites' || key === 'tehillim'
            || /^pos:[a-z0-9]{16}$/.test(key) || /^order:[a-z0-9]{16}$/.test(key) || /^ver:[a-z0-9]{16}$/.test(key)
            || key === 'siddur:edition' || key === 'tehillim:edition' || key === 'loc' || key === 'zmethod';
    }

    const okId = id => typeof id === 'string' && /^[A-Za-z0-9._-]{1,100}$/.test(id);

    /** רשומה במכשיר → פריטים. */
    function itemsFor(key, value) {
        if (value === undefined || value === null) return [];
        const one = (pid, kind, ref, data) => [{ pid, kind, ref: ref || null, data }];
        if (key === 'settings') return one('pref:settings', 'pref', null, value);
        if (key === 'customs') return one('custom:perm', 'custom', null, value);
        if (key === 'siddur:edition' || key === 'tehillim:edition') return one('pref:' + key, 'pref', null, value);
        // 07: המיקום הפעיל והשיטה — פריט אחד כל אחד, שמתעדכן במקום (§19: רק המיקום הפעיל, לא היסטוריה)
        if (key === 'loc' || key === 'zmethod') return one('pref:' + key, 'pref', null, value);
        let m = /^pos:([a-z0-9]{16})$/.exec(key);
        if (m) return one('position:' + m[1], 'position', m[1], value);
        m = /^order:([a-z0-9]{16})$/.exec(key);
        if (m) return one('order:' + m[1], 'pref', m[1], value);
        m = /^ver:([a-z0-9]{16})$/.exec(key);   // הגרסה שהקורא בחר למהדורה (06)
        if (m) return one('ver:' + m[1], 'pref', m[1], value);
        if (key === 'marks') return (Array.isArray(value) ? value : []).filter(x => x && okId(x.id))
            .map(x => ({ pid: 'm:' + x.id, kind: MARK_KIND[x.type] || 'bookmark', ref: typeof x.edition === 'string' ? x.edition : null, data: x }));
        if (key === 'favorites') return (Array.isArray(value) ? value : []).filter(x => x && okId(x.id))
            .map(x => ({ pid: 'f:' + x.id, kind: 'favorite', ref: typeof x.edition === 'string' ? x.edition : null, data: x }));
        if (key === 'tehillim') {
            const out = [{ pid: 'tehillim:list', kind: 'tehillim_list', ref: null, data: { done: value.done || {}, group: value.group || [] } }];
            for (const n of Array.isArray(value.names) ? value.names : []) if (n && okId(n.id)) out.push({ pid: 'pn:' + n.id, kind: 'prayer_name', ref: null, data: n });
            return out;
        }
        return [];
    }

    /** פריט → הרשומה במכשיר שהוא שייך לה (null — סוג שהמכשיר הזה עוד לא מכיר, למשל תזכורות של 08). */
    function keyOf(pid) {
        if (pid === 'pref:settings') return 'settings';
        if (pid === 'custom:perm') return 'customs';
        if (pid === 'pref:siddur:edition' || pid === 'pref:tehillim:edition') return pid.slice(5);
        if (pid === 'pref:loc' || pid === 'pref:zmethod') return pid.slice(5);
        let m = /^position:([a-z0-9]{16})$/.exec(pid);
        if (m) return 'pos:' + m[1];
        m = /^order:([a-z0-9]{16})$/.exec(pid);
        if (m) return 'order:' + m[1];
        m = /^ver:([a-z0-9]{16})$/.exec(pid);
        if (m) return 'ver:' + m[1];
        if (pid.startsWith('m:')) return 'marks';
        if (pid.startsWith('f:')) return 'favorites';
        if (pid === 'tehillim:list' || pid.startsWith('pn:')) return 'tehillim';
        return null;
    }

    const byCreated = (a, b) => String(a.created_at || '').localeCompare(String(b.created_at || '')) || String(a.id).localeCompare(String(b.id));

    /** פריטים (pid → data) → הערך של הרשומה במכשיר. undefined = אין (הרשומה נמחקת). */
    function build(key, datas) {
        const pids = Object.keys(datas);
        if (key === 'marks' || key === 'favorites') return pids.map(p => datas[p]).sort(byCreated);
        if (key === 'tehillim') {
            const list = datas['tehillim:list'] || {};
            const names = pids.filter(p => p.startsWith('pn:')).map(p => datas[p]).sort(byCreated);
            if (!pids.length) return undefined;
            return { done: list.done || {}, group: list.group || [], names };
        }
        return pids.length ? datas[pids[0]] : undefined;
    }

    function emptyState() { return { owner: null, cursor: 0, items: {} }; }

    /** התצוגה (state + outbox) של כל הפריטים ששייכים לרשומה: pid → {kind, ref, json}. */
    function effective(state, outbox, key) {
        const out = {};
        for (const [pid, it] of Object.entries(state.items)) if (!it.del && keyOf(pid) === key) out[pid] = { kind: it.kind, ref: it.ref, json: it.json };
        for (const [pid, o] of Object.entries(outbox)) {
            if (keyOf(pid) !== key) continue;
            if (o.deleted) delete out[pid];
            else out[pid] = { kind: o.kind, ref: o.ref, json: stable(o.data) };
        }
        return out;
    }

    /** הערך שהמכשיר מציג לרשומה — אחרי מה שהשרת החזיר ומה שעוד בתור. */
    function view(state, outbox, key) {
        const eff = effective(state, outbox, key);
        const datas = {};
        for (const [pid, e] of Object.entries(eff)) datas[pid] = JSON.parse(e.json);
        return build(key, datas);
    }

    let seq = 0;
    const nextSeq = () => ++seq + Date.now() * 1000;

    /**
     * שינוי מקומי של רשומה → התור. חדש / שונה — נכנס; נעלם — מחיקה; חזר למה שבשרת — יוצא מהתור. base = ה-rev שהמכשיר מכיר.
     * מחזיר את מספר השינויים שנכנסו.
     */
    function local(state, outbox, key, value) {
        if (!syncable(key)) return 0;
        const now = {};
        for (const it of itemsFor(key, value)) now[it.pid] = it;
        const eff = effective(state, outbox, key);
        let n = 0;
        const put = (pid, entry) => {
            const srv = state.items[pid];
            const same = srv && (entry.deleted ? srv.del : !srv.del && srv.json === stable(entry.data) && (srv.ref || null) === (entry.ref || null));
            if (same) { if (outbox[pid] && !outbox[pid].create_only) delete outbox[pid]; return; }
            outbox[pid] = Object.assign({ pid, base: srv ? srv.rev : 0, seq: nextSeq() }, entry);
            n++;
        };
        for (const [pid, it] of Object.entries(now)) {
            const e = eff[pid];
            if (e && e.json === stable(it.data) && (e.ref || null) === (it.ref || null)) continue;
            put(pid, { kind: it.kind, ref: it.ref, data: it.data });
        }
        for (const [pid, e] of Object.entries(eff)) if (!now[pid]) put(pid, { kind: e.kind, ref: e.ref, deleted: true });
        return n;
    }

    /** מה שנשלח לשרת מהתור (עד max). */
    function batch(outbox, max = 200) {
        return Object.values(outbox).filter(o => !o.held).sort((a, b) => a.seq - b.seq).slice(0, max).map(o => {
            const it = { pid: o.pid, kind: o.kind, ref: o.ref || null, base: o.base || 0 };
            if (o.deleted) it.deleted = true; else it.data = o.data;
            if (o.create_only) it.create_only = true;
            return { item: it, seq: o.seq };
        });
    }

    function remember(state, item) {
        state.items[item.pid] = { kind: item.kind, ref: item.ref || null, rev: item.rev, json: item.deleted ? null : stable(item.data), del: !!item.deleted };
    }

    /**
     * תשובת sync-push → state; מה שנשלח יוצא מהתור — אלא אם השתנה שוב בינתיים (seq אחר). "bad" — יוצא (לא חוזרים עליו לנצח).
     * "full" — נשאר בתור מסומן held: מוצג במכשיר, לא נשלח שוב עד שהקורא ישנה אותו.
     * מחזיר {keys: רשומות שהשתנו, counts: {new, saved, same, kept, gone, bad}}.
     */
    function applyPush(state, outbox, sent, res) {
        const keys = new Set();
        const counts = { new: 0, saved: 0, same: 0, kept: 0, gone: 0, bad: 0, full: 0 };
        const seqOf = Object.fromEntries(sent.map(s => [s.item.pid, s.seq]));
        for (const r of (res && res.results) || []) {
            if (!r || typeof r.pid !== 'string') continue;
            counts[r.status] = (counts[r.status] || 0) + 1;
            if (r.item) remember(state, r.item);
            // "full" — החשבון מלא: נשאר במכשיר (מוצג), ולא נשלח שוב עד שישתנה
            if (r.status === 'full' && outbox[r.pid] && outbox[r.pid].seq === seqOf[r.pid]) outbox[r.pid].held = true;
            else if (outbox[r.pid] && outbox[r.pid].seq === seqOf[r.pid]) delete outbox[r.pid];
            const k = keyOf(r.pid);
            if (k) keys.add(k);
        }
        return { keys: [...keys], counts };
    }

    /** תשובת sync-pull → state. מה שעוד בתור נשאר (יישלח ויקבע — "האחרון בשרת"). */
    function applyPull(state, items, cursor) {
        const keys = new Set();
        for (const it of items || []) {
            if (!it || typeof it.pid !== 'string') continue;
            const before = state.items[it.pid];
            if (before && before.rev >= it.rev) continue;
            remember(state, it);
            const k = keyOf(it.pid);
            if (k) keys.add(k);
        }
        if (typeof cursor === 'number' && cursor > state.cursor) state.cursor = cursor;
        return [...keys];
    }

    /** מיזוג אורח (§5): כל הרשומות במכשיר → פריטים חדשים לתור, עם create_only (מה שכבר בחשבון — של החשבון קובע). */
    function guestOutbox(kv) {
        const outbox = {};
        for (const [key, value] of Object.entries(kv)) {
            if (!syncable(key)) continue;
            for (const it of itemsFor(key, value)) outbox[it.pid] = { pid: it.pid, kind: it.kind, ref: it.ref, data: it.data, base: 0, create_only: true, seq: nextSeq() };
        }
        return outbox;
    }

    /** יש במכשיר מידע אישי של אורח? (הסבר על שמירה מקומית והגדרות ריקות — לא נחשבים) */
    function hasGuestData(kv) {
        return Object.entries(kv).some(([k, v]) => syncable(k) && itemsFor(k, v).some(it => stable(it.data) !== '{}' && stable(it.data) !== '[]'
            && !(it.kind === 'tehillim_list' && !Object.keys(it.data.done || {}).length && !(it.data.group || []).length)));
    }

    /** סיכום המיזוג לקורא. */
    function mergeSummary(counts) {
        const added = (counts.new || 0) + (counts.same || 0);
        const kept = counts.kept || 0;
        return 'נוספו לחשבון ' + added + ' פריטים' + (kept ? ' · ' + kept + ' הגדרות כבר היו בחשבון — נשארו של החשבון' : '');
    }

    return { stable, syncable, itemsFor, keyOf, build, emptyState, effective, view, local, batch, applyPush, applyPull, guestOutbox, hasGuestData, mergeSummary };
});
