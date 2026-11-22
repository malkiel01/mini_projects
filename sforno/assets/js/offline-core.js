/*
 * ספורנו — ההורדות והגרסאות במכשיר (אפיון 06), בלי DOM ובלי רשת אמיתית: כל מה שנוגע בעולם מגיע ב-deps, כך שהקוד נבדק
 * ב-node (tests/offline-core.mjs).
 *
 *   חבילה  = (מהדורה, גרסה): ה-source_html של הגרסה (התשובה של read-revision) + התמונות / העמודים, ולכל קובץ sha256 מהשרת
 *            (read-package). נשמרת במטמון "sforno-content" (Cache API). הרשומה שלה — dl:<edition> ב-SF.store, **בלי סנכרון**
 *            (setRaw): מה שירד הוא של המכשיר הזה.
 *   "זמין" = state "ready" — רק אחרי שכל ה-hash-ים אומתו. לפני הקובץ הראשון נכתב "partial"; הורדה שנקטעה (שגיאה, סגירת
 *            הדף, כיבוי) נשארת "partial" ומוצגת "לא הושלמה" — ולא נפתחת כזמינה.
 *   גרסה נבחרת = ver:<edition> → {rev, prev} (מסתנכרן, pref). פרסום חדש לא מחליף אותה; רק "לעבור" של הקורא.
 *   מעבר   = כל סימניה / הדגשה / הערה / מועדף של המהדורה שצמוד לגרסה הקודמת: sid קיים בחדשה ← עובר; לא קיים ← נשאר צמוד
 *            לקודמת (rev), ומוצג ב"לא הותאמו" עם הטקסט המקורי (excerpt). שום דבר לא נמחק.
 *   מחיקת הורדה — רק המטמון ורשומת dl:<edition>. לא נוגעת באף רשומה אחרת.
 */
'use strict';

(function (root, factory) {
    const C = factory();
    if (typeof module === 'object' && module.exports) module.exports = C;
    else root.SFOfflineCore = C;
})(typeof self !== 'undefined' ? self : this, function () {
    const CACHE = 'sforno-content';
    const PID = /^[a-z0-9]{16}$/;
    const SID = /\ssid="([a-z0-9]{8})"/g;

    const dlKey = ed => 'dl:' + ed;
    const verKey = ed => 'ver:' + ed;
    /** הכתובת של הטקסט במטמון. rev = null — מהדורה של עמודים בלבד. */
    const revUrl = (ed, rev) => 'api.php?action=read-revision&edition=' + ed + (rev ? '&revision=' + rev : '');

    /** ה-sid-ים שבמקור (source_html) — לבדיקת ההתאמה במעבר גרסה. */
    function sidsOf(html) {
        const out = new Set();
        if (typeof html !== 'string') return out;
        for (const m of html.matchAll(SID)) out.add(m[1]);
        return out;
    }

    /** הגרסה שהקורא בחר: ver:<edition>, ואם אין — זו שהמקום נשמר עליה (04/05). null = עוד לא נבחרה (הנוכחית). */
    function pinned(ver, pos) {
        if (ver && typeof ver.rev === 'string' && PID.test(ver.rev)) return ver.rev;
        if (pos && typeof pos.rev === 'string' && PID.test(pos.rev)) return pos.rev;
        return null;
    }

    /** מצב ההורדה לתצוגה: ready / partial / stale (גרסה אחרת מהנבחרת) / none — עם הטקסט לקורא. */
    function status(rec, want) {
        if (!rec || typeof rec !== 'object') return { state: 'none', text: 'לא זמין בלי אינטרנט' };
        if (rec.state !== 'ready') return { state: 'partial', text: rec.error ? 'לא הושלמה — ' + rec.error : 'לא הושלמה' };
        if (want && rec.revision !== want) return { state: 'stale', text: 'הורדה של גרסה ' + rec.n + ' — לא הגרסה שנבחרה' };
        return { state: 'ready', text: 'זמין בלי אינטרנט' };
    }

    const ready = (rec, want) => status(rec, want).state === 'ready';

    function human(bytes) {
        const b = Number(bytes) || 0;
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return Math.round(b / 1024) + ' KB';
        return (b / 1024 / 1024).toFixed(1) + ' MB';
    }

    /**
     * הורדה של חבילה. pkg — התשובה של read-package. deps:
     *   fetch(url) → {ok, status, arrayBuffer(), text()} · sha256(ArrayBuffer | string) → hex
     *   cache: put(url, body, type) / has(url) / text(url) / delete(url) / keys() — הכתובות יחסיות ("api.php?…")
     *   store: get(key) / setRaw / delRaw / keys · now() → ISO · progress(rec) — לא חובה
     * הורדה חדשה של אותה מהדורה מחליפה את הרשומה; הקבצים של הקודמת שאין בחדשה — gc אחרי.
     * מחזיר את הרשומה (ready). שגיאה — הרשומה נשארת partial עם error, והחריגה עולה.
     */
    async function download(deps, pkg) {
        const ed = pkg.edition.pid;
        const rev = pkg.revision ? pkg.revision.pid : null;
        const files = [{ url: revUrl(ed, rev), sha256: pkg.text.sha256, size: pkg.text.size, type: 'application/json', text: true }]
            .concat((pkg.files || []).map(f => Object.assign({ type: f.type || 'application/octet-stream' }, f)));
        const rec = { edition: ed, revision: rev, n: pkg.revision ? pkg.revision.n : 0, published_at: pkg.revision ? pkg.revision.published_at : null, title: pkg.title.name, kind: pkg.title.kind,
            label: pkg.edition.label, size: pkg.size, files: files.map(f => f.url), done: 0, state: 'partial', error: null, at: deps.now(), completed_at: null };
        await deps.store.setRaw(dlKey(ed), rec);
        const tell = () => { if (deps.progress) deps.progress(Object.assign({}, rec)); };
        tell();
        try {
            for (const f of files) {
                const res = await deps.fetch(f.url);
                if (!res || !res.ok) throw new Error('הקובץ לא התקבל' + (res && res.status ? ' (' + res.status + ')' : ''));
                let body, hash;
                if (f.text) {
                    const txt = await res.text();
                    let js = null;
                    try { js = JSON.parse(txt); } catch (e) { /* נבדק מתחת */ }
                    if (!js || !js.edition || js.edition.pid !== ed || ((js.revision && js.revision.pid) || null) !== rev) throw new Error('הטקסט שהתקבל אינו של הגרסה');
                    hash = await deps.sha256(typeof js.html === 'string' ? js.html : '');
                    body = txt;
                } else {
                    body = await res.arrayBuffer();
                    hash = await deps.sha256(body);
                }
                if (hash !== f.sha256) throw new Error('קובץ פגום (בדיקת תקינות נכשלה)');
                await deps.cache.put(f.url, body, f.type);
                rec.done += Number(f.size) || 0;
                tell();
            }
        } catch (e) {
            rec.error = (e && e.code === 'network') || /fetch|network/i.test(String(e && e.message)) ? 'אין חיבור' : String((e && e.message) || e);
            await deps.store.setRaw(dlKey(ed), rec);
            tell();
            throw e;
        }
        rec.state = 'ready';
        rec.done = rec.size;
        rec.completed_at = deps.now();
        await deps.store.setRaw(dlKey(ed), rec);
        tell();
        return rec;
    }

    /**
     * הטקסט של מהדורה מתוך ההורדה — רק אם היא "ready" וכל הקבצים שלה עדיין במטמון (הדפדפן עלול לפנות). חסר קובץ ← הרשומה
     * חוזרת ל-partial ("לא הושלמה") ומחזירים null.
     */
    async function load(deps, edition) {
        const rec = await deps.store.get(dlKey(edition));
        if (!ready(rec)) return null;
        for (const u of rec.files) {
            if (!(await deps.cache.has(u))) {
                await deps.store.setRaw(dlKey(edition), Object.assign({}, rec, { state: 'partial', error: 'חלק מהקבצים נמחקו מהמכשיר' }));
                return null;
            }
        }
        const txt = await deps.cache.text(revUrl(rec.edition, rec.revision));
        if (txt === null) return null;
        const data = JSON.parse(txt);
        return { rec, data };
    }

    /** ניקוי המטמון: כל קובץ שאף הורדה (dl:*) לא מצביעה עליו — נמחק. קובץ משותף לשתי הורדות נשאר כל עוד אחת צריכה אותו. */
    async function gc(deps) {
        const refs = new Set();
        for (const k of await deps.store.keys()) {
            if (!k.startsWith('dl:')) continue;
            const o = await deps.store.get(k);
            for (const u of (o && o.files) || []) refs.add(u);
        }
        let n = 0;
        for (const u of await deps.cache.keys()) if (!refs.has(u)) { await deps.cache.delete(u); n++; }
        return n;
    }

    /**
     * מחיקת הורדה: הרשומה dl:<edition> והקבצים שרק היא צריכה. **רק זה** — סימניות, הערות, הגדרות, מקום וגרסה נבחרת לא
     * נוגעים (§4: "מחיקת הורדה אינה מחיקת ספרייה אישית").
     */
    async function remove(deps, edition) {
        await deps.store.delRaw(dlKey(edition));
        return gc(deps);
    }

    /** הפריט שייך לגרסה הזו? בלי rev (נוצר לפני 06) — שייך לגרסה הנבחרת. */
    const onRev = (x, rev) => !x.rev || x.rev === rev;

    /** מה מוצג בגרסה rev: הפריטים של המהדורה שצמודים אליה. */
    function visible(list, edition, rev) {
        return (Array.isArray(list) ? list : []).filter(x => x && x.edition === edition && onRev(x, rev));
    }

    /** "לא הותאמו": פריטים של המהדורה שצמודים לגרסה אחרת מזו שמוצגת. */
    function unmatched(list, edition, rev) {
        return (Array.isArray(list) ? list : []).filter(x => x && x.edition === edition && x.rev && x.rev !== rev);
    }

    /**
     * מעבר גרסה from → to ברשימה (marks / favorites). sids — ה-sid-ים שבגרסה to. מחזיר {list, moved, kept}.
     * פריט של מהדורה אחרת, או שצמוד לגרסה שלישית — לא משתנה. אורך הרשימה לא משתנה לעולם.
     */
    function migrate(list, edition, from, to, sids) {
        let moved = 0, kept = 0;
        const out = (Array.isArray(list) ? list : []).map(x => {
            if (!x || x.edition !== edition || !onRev(x, from)) return x;
            if (sids.has(x.sid)) { moved++; return Object.assign({}, x, { rev: to }); }
            kept++;
            return Object.assign({}, x, { rev: from });
        });
        return { list: out, moved, kept };
    }

    /** המקום במעבר: sid קיים בחדשה — עובר; לא קיים — נשאר כמו שהוא (הקורא נפתח בהתחלה). */
    function movePos(pos, from, to, sids) {
        if (!pos || (pos.rev && pos.rev !== from) || !sids.has(pos.sid)) return pos;
        return Object.assign({}, pos, { rev: to });
    }

    return { CACHE, dlKey, verKey, revUrl, sidsOf, pinned, status, ready, human, download, load, gc, remove, visible, unmatched, migrate, movePos };
});
