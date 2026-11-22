/*
 * ספורנו — הלוגיקה של הקורא (אפיון 04), בלי DOM ובלי רשת: כאן רק פונקציות טהורות, כדי שהבדיקות ירוצו ב-node
 * (tests/reader-core.mjs). המסכים (screens/reader.js, screens/siddur.js) קוראים לכאן.
 *
 *   mergeOrder   — הסדר האישי מול רשימת הבסיס (§2): לא נדרס; חדש — בסוף; שהוסר — "לא זמין"
 *   conds        — מצב המנהגים: קבוע מול זמני לתפילה (§2)
 *   pickTop      — המקום על המסך: sid + היסט יחסי (§1 — לא פיקסלים)
 *   shareLink    — קישור לקטע: רק מזהי תוכן (§1, §19)
 *   copyText     — העתקה עם מקור
 *   wordHit      — מילה בשאילתה ↔ מילה בטקסט (כמו sf_search_word_hit בשרת)
 *   tehillim     — חלוקה לימי השבוע ולימי החודש, ו"של היום" (⏳ עד 07: לפי חצות מקומית)
 *   wakeLock     — מניעת כיבוי מסך: כשל שקט (§5.3)
 */
'use strict';

(function (root, factory) {
    const m = factory();
    if (typeof module === 'object' && module.exports) module.exports = m;
    else root.SFReaderCore = m;
})(typeof self !== 'undefined' ? self : this, function () {
    const PID = /^[a-z0-9]{16}$/;
    const SID = /^[a-z0-9]{8}$/;

    /**
     * personal: {order: [pid…], names: {pid: שם}} (מה שנשמר במכשיר) — או null למשתמש חדש. base: [{pid, name, sid, kind}].
     * מחזיר {list: [{pid, name, sid, kind, available}], order, names}. הסדר האישי נשמר כמו שהוא; פריט חדש בבסיס — בסוף;
     * פריט שירד מהבסיס — נשאר במקומו ומסומן available: false (השם — מה שנשמר אצל המשתמש).
     */
    function mergeOrder(personal, base) {
        const p = personal && Array.isArray(personal.order) ? personal : { order: [], names: {} };
        const names = Object.assign({}, p.names || {});
        const byPid = {};
        for (const b of base || []) byPid[b.pid] = b;
        const seen = {};
        const list = [];
        for (const pid of p.order) {
            if (seen[pid] || !PID.test(String(pid))) continue;
            seen[pid] = true;
            const b = byPid[pid];
            list.push(b ? Object.assign({}, b, { available: true }) : { pid, name: names[pid] || 'פריט שהוסר', sid: null, kind: 'prayer', available: false });
        }
        for (const b of base || []) if (!seen[b.pid]) { seen[b.pid] = true; list.push(Object.assign({}, b, { available: true })); }
        for (const it of list) if (it.available) names[it.pid] = it.name;
        return { list, order: list.map(x => x.pid), names };
    }

    /** הזזה בסדר האישי (up / down), מחזיר מערך חדש. */
    function move(order, pid, dir) {
        const a = order.slice();
        const i = a.indexOf(pid);
        const j = dir === 'up' ? i - 1 : i + 1;
        if (i < 0 || j < 0 || j >= a.length) return a;
        [a[i], a[j]] = [a[j], a[i]];
        return a;
    }

    // ── מנהגים ───────────────────────────────────────────────

    /** הבחירה הקבועה, משלימה כל קבוצה: מה שנשמר (אם עדיין זמין) ← ברירת המחדל של המנהל ← הראשון. */
    function permanent(groups, saved) {
        const out = {};
        for (const g of groups || []) {
            const rules = g.customs.map(c => c.rule);
            const s = saved && saved[g.grp];
            const def = g.customs.find(c => c.default_on);
            out[g.grp] = rules.indexOf(s) >= 0 ? s : def ? def.rule : rules[0];
        }
        return out;
    }

    /**
     * מצב המנהגים בתוך תפילה: {perm, temp}. temp = null — אין שינוי זמני. כל הפעולות מחזירות מצב חדש.
     *   enter  — כניסה לתפילה: ההגדרה הקבועה
     *   set    — שינוי בתוך התפילה: זמני
     *   save   — "לשמור כקבוע"
     *   leave  — יציאה מהתפילה: השינוי הזמני מתבטל
     */
    const conds = {
        enter: (groups, saved) => ({ perm: permanent(groups, saved), temp: null }),
        set: (st, grp, rule) => ({ perm: st.perm, temp: Object.assign({}, st.temp || st.perm, { [grp]: rule }) }),
        save: st => ({ perm: Object.assign({}, st.temp || st.perm), temp: null }),
        leave: st => ({ perm: st.perm, temp: null }),
        current: st => st.temp || st.perm,
        isTemp: st => !!st.temp && Object.keys(st.temp).some(k => st.temp[k] !== st.perm[k]),
        /** המפה ל-SFRender: {rule: true|false} לכל מנהג בכל קבוצה */
        map(st, groups) {
            const cur = conds.current(st);
            const m = {};
            for (const g of groups || []) for (const c of g.customs) m[c.rule] = cur[g.grp] === c.rule;
            return m;
        },
    };

    /** כמו sf_combo_key בשרת: "grp=rule;grp=rule", ממוין לפי הקבוצה. */
    function comboKey(sel) {
        return Object.keys(sel).sort().map(k => k + '=' + sel[k]).join(';');
    }

    // ── מיקום ────────────────────────────────────────────────

    /**
     * rects: [{sid, top, bottom}] של הרכיבים עם sid, בסדר המסמך (הורה לפני ילדיו). line — קו הקריאה (px מראש החלון).
     * מחזיר {sid, off}: הרכיב העמוק ביותר שהקו עובר בו (האחרון בסדר המסמך), והיסט יחסי 0–1 בתוכו. אין — הראשון שמתחת לקו.
     */
    function pickTop(rects, line) {
        let hit = null;
        for (const r of rects) if (r.bottom > r.top && r.top <= line && line < r.bottom) hit = r;
        if (!hit) for (const r of rects) if (r.bottom > r.top && r.top >= line) { hit = r; break; }
        if (!hit) { const vis = rects.filter(r => r.bottom > r.top); hit = vis[vis.length - 1]; }
        if (!hit) return null;
        const off = Math.max(0, Math.min(1, (line - hit.top) / (hit.bottom - hit.top)));
        return { sid: hit.sid, off: Math.round(off * 1000) / 1000 };
    }

    /** כמה לגלול כדי שהמקום ישוב לקו: (top של הרכיב עכשיו) + off·גובה − line. */
    function scrollDelta(rect, off, line) {
        return rect.top + off * (rect.bottom - rect.top) - line;
    }

    // ── שיתוף והעתקה ─────────────────────────────────────────

    /** הקישור לקטע — רק מזהי תוכן. base: הכתובת בלי ה-# (location.origin + location.pathname). */
    function shareLink(base, edition, sid) {
        if (!PID.test(String(edition))) throw new Error('edition');
        const b = String(base || '').split('#')[0].split('?')[0];
        return b + '#/r/' + edition + (sid && SID.test(String(sid)) ? '/' + sid : '');
    }

    /** ההעתקה: הבלוקים (טקסט המקור, עם ניקוד), ואז שורת מקור — "— סידור עדות המזרח, שחרית". */
    function copyText(parts, source) {
        const body = (parts || []).map(s => String(s).replace(/[ \t]+\n/g, '\n').trim()).filter(Boolean).join('\n');
        return source ? body + '\n— ' + source : body;
    }

    /** "— <כותר> <מהדורה>, <מקטע>" */
    function sourceLine(title, label, section) {
        return [title, label].filter(Boolean).join(' ') + (section ? ', ' + section : '');
    }

    // ── חיפוש ────────────────────────────────────────────────

    const PREFIX = /^[ובכלמשה]{1,3}$/;
    /** מילה בשאילתה ↔ מילה בטקסט (שתיהן מנורמלות): זהה, או עם תחיליות. כמו sf_search_word_hit. */
    function wordHit(w, tok) {
        if (tok === w) return true;
        const n = tok.length - w.length;
        return n >= 1 && n <= 3 && tok.slice(n) === w && PREFIX.test(tok.slice(0, n));
    }

    // ── תהילים (§3) ──────────────────────────────────────────

    /** ימי החודש (30): [מפרק, עד פרק]. יום 25 ו-26 — שני חצאי קיט. */
    const MONTH = [[1, 9], [10, 17], [18, 22], [23, 28], [29, 34], [35, 38], [39, 43], [44, 48], [49, 54], [55, 59], [60, 65], [66, 68], [69, 71],
        [72, 76], [77, 78], [79, 82], [83, 87], [88, 89], [90, 96], [97, 103], [104, 105], [106, 107], [108, 112], [113, 118], [119, 119],
        [119, 119], [120, 134], [135, 139], [140, 144], [145, 150]];
    /** ימי השבוע: ראשון…שבת */
    const WEEK = [[1, 29], [30, 50], [51, 72], [73, 89], [90, 106], [107, 119], [120, 150]];
    const range = ([a, b]) => Array.from({ length: b - a + 1 }, (_, i) => a + i);

    /** הפרקים ליום בחודש העברי (1–30). חודש של 29 יום — ביום כ"ט גם של ל'. */
    function monthChapters(day, monthLen) {
        const d = Math.max(1, Math.min(30, day | 0));
        const out = range(MONTH[d - 1]);
        if (d === 29 && monthLen === 29) for (const c of range(MONTH[29])) out.push(c);
        return out;
    }
    const weekChapters = wd => range(WEEK[Math.max(0, Math.min(6, wd | 0))]);

    /** "של היום" לפי חצות מקומית (⏳ עד 07). hebDay(date) → היום בחודש העברי (מ-Intl בדפדפן). */
    function today(now, hebDay) {
        const d = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12);
        const day = hebDay(d);
        const tomorrow = hebDay(new Date(d.getTime() + 86400000));
        const monthLen = day === 29 && tomorrow === 1 ? 29 : 30;
        return { weekday: d.getDay(), day, monthLen, week: weekChapters(d.getDay()), month: monthChapters(day, monthLen) };
    }

    /** היום בחודש העברי, מ-Intl (לוח עברי). */
    function hebDayIntl(d) {
        const s = new Intl.DateTimeFormat('en-u-ca-hebrew', { day: 'numeric' }).format(d);
        return parseInt(s, 10) || 1;
    }

    /** הפרקים מתוכן העניינים: המקטעים העליונים לפי הסדר = פרק 1, 2, … */
    function chapters(toc) {
        return (toc || []).filter(t => t.kind === 'section' && !t.parent_sid).map((t, i) => ({ n: i + 1, sid: t.sid, title: t.title || 'פרק ' + (i + 1) }));
    }

    // ── מסך דולק (§5.3) ──────────────────────────────────────

    /** מבקש wakeLock. כל כשל (אין תמיכה, סירוב, דף מוסתר) — null, בלי חריגה ובלי הודעה. */
    async function wakeLock(nav) {
        try {
            if (!nav || !nav.wakeLock || typeof nav.wakeLock.request !== 'function') return null;
            return await nav.wakeLock.request('screen');
        } catch (e) {
            return null;
        }
    }

    return { mergeOrder, move, permanent, conds, comboKey, pickTop, scrollDelta, shareLink, copyText, sourceLine, wordHit,
        monthChapters, weekChapters, today, hebDayIntl, chapters, wakeLock, MONTH, WEEK };
});
