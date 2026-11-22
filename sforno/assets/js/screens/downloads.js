/*
 * ההורדות (אפיון 06 §1, §4) — #/downloads, ו-SF.dlLine(edition) — שורת "בלי אינטרנט" בסידור, בתהילים ובקורא.
 *
 *   הורדה = רק בלחיצה (§15). לכל הורדה: הכותר והמהדורה, הגרסה ותאריך הפרסום, הגודל, מתי ירדה, התקדמות, שגיאה.
 *   "לא הושלמה" — לא "זמין" (§21.6). "מחיקת ההורדה" מוחקת רק את הקבצים מהמכשיר; הסימניות, ההערות וההגדרות נשארות (§4) —
 *   ולכן היא פעולה נפרדת, בשם אחר, מ"מחיקת החשבון" / "מחיקת המידע".
 *   התקנה ≠ הורדה: התקנת האפליקציה שומרת את האפליקציה בלבד.
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const O = window.SFOfflineCore;
    const pct = r => (r && r.size ? Math.min(100, Math.round((r.done / r.size) * 100)) : 0);

    let installEvt = null;
    window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); installEvt = e; });

    async function startDownload(ed, rev) {
        try {
            const rec = await SF.offline.download(ed, rev);
            SF.toast('"' + rec.title + '" זמין עכשיו גם בלי אינטרנט');
        } catch (e) {
            SF.toast(e.code === 'network' ? 'אין חיבור — ההורדה לא הושלמה' : 'ההורדה לא הושלמה: ' + e.message, true);
        }
    }

    function removeSheet(rec, after) {
        const dlg = SF.sheet('מחיקת ההורדה', h('div', null,
            h('p', null, 'למחוק מהמכשיר את ההורדה של "' + rec.title + (rec.label ? ' · ' + rec.label : '') + '"?'),
            h('p', { class: 'hint' }, 'נמחקים רק הקבצים שבמכשיר (' + O.human(rec.size) + '). הסימניות, ההדגשות, ההערות, המקום וההגדרות נשארים. בלי אינטרנט הכותר לא ייפתח עד שיורד שוב.'),
            h('div', { class: 'row-btns' },
                h('button', { class: 'btn danger', type: 'button', onclick: async () => { await SF.offline.remove(rec.edition); dlg.close(); SF.toast('ההורדה נמחקה מהמכשיר'); if (after) after(); } }, 'מחיקת ההורדה'),
                h('button', { class: 'btn ghost', type: 'button', onclick: () => dlg.close() }, 'ביטול'))));
    }

    /**
     * שורת "בלי אינטרנט" למהדורה: המצב, ההתקדמות וכפתור אחד. opts.rev — הגרסה שנבחרה (ברירת מחדל — מ-SF.version);
     * opts.full — גם "מחיקת ההורדה".
     */
    SF.dlLine = function dlLine(ed, opts = {}) {
        const box = h('div', { class: 'dl-line', 'aria-live': 'polite' });
        let stop = null;
        async function draw() {
            if (!SF.offline.supported) { SF.put(box, h('span', { class: 'hint' }, 'הדפדפן הזה לא תומך בקריאה בלי אינטרנט')); return; }
            const want = opts.rev || await SF.version.get(ed);
            const live = SF.offline.running(ed);
            const rec = live || await SF.offline.record(ed);
            if (live && live.state === 'partial' && !live.error) {
                SF.put(box, h('span', null, '⬇️ מוריד… ' + pct(live) + '%'), h('progress', { max: 100, value: pct(live), 'aria-label': 'התקדמות ההורדה' }));
                return;
            }
            const st = O.status(rec, want);
            const off = SF.isOffline();
            const btn = (label, fn, cls) => h('button', { class: 'btn sm ' + (cls || 'ghost'), type: 'button', disabled: off && fn !== 'rm', onclick: fn === 'rm' ? () => removeSheet(rec, draw) : fn }, label);
            const get = () => startDownload(ed, want);
            const icon = { ready: '✓', partial: '⚠️', stale: '⚠️', none: '📡' }[st.state];
            SF.put(box,
                h('span', { class: 'dl-st ' + st.state }, icon + ' ' + st.text + (st.state === 'ready' ? ' · ' + O.human(rec.size) : '')),
                st.state === 'none' ? btn('⬇️ הורדה לקריאה בלי אינטרנט', get, 'primary') : null,
                st.state === 'partial' ? btn('להוריד שוב', get, 'primary') : null,
                st.state === 'stale' ? btn('להוריד את הגרסה שלי', get, 'primary') : null,
                opts.full && rec ? btn('מחיקת ההורדה', 'rm') : null);
        }
        stop = SF.offline.watch(r => { if (!box.isConnected) { stop(); return; } if (r.edition === ed) draw(); });
        SF.onLeave(() => stop && stop());
        draw();
        return box;
    };

    // ── המסך ─────────────────────────────────────────────────
    SF.route('#/downloads', async main => {
        const listBox = h('ul', { class: 'list' });
        const moreBox = h('div');
        const infoBox = h('div', { class: 'dl-info' });
        let stop = null;

        async function info() {
            const [persist, est] = await Promise.all([SF.offline.persisted(), SF.offline.usage()]);
            SF.put(infoBox,
                SF.isOffline() ? h('p', { class: 'notice' }, '📡 אין חיבור לאינטרנט. מה שמסומן "זמין" נפתח מהמכשיר; השאר — כשתחזור הרשת.') : null,
                persist === false ? h('p', { class: 'hint' }, 'הדפדפן לא אישר שמירה קבועה — כשהמכשיר מתמלא הוא עלול למחוק הורדות. כותר שנמחק יסומן "לא הושלמה".') : null,
                est && est.usage ? h('p', { class: 'hint' }, 'בשימוש במכשיר: ' + O.human(est.usage) + (est.quota ? ' מתוך ' + O.human(est.quota) + ' שהדפדפן מאפשר' : '')) : null);
        }

        function card(rec, cat) {
            const live = SF.offline.running(rec.edition);
            const r = live || rec;
            const cur = SF.currentRev(cat, rec.edition);
            const st = O.status(r, null);
            const busy = live && live.state === 'partial' && !live.error;
            return h('li', { class: 'card dl-card' },
                h('div', { class: 'body' },
                    h('div', { class: 'l1' }, h('a', { href: '#/r/' + rec.edition, class: 'one' }, h('b', null, rec.title)), rec.label ? h('span', { class: 'muted one' }, ' · ' + rec.label) : null),
                    h('div', { class: 'l2' }, [rec.n ? 'גרסה ' + rec.n : 'עמודים', rec.published_at ? 'פורסמה ' + SF.when(rec.published_at).split(',')[0] : null,
                        O.human(rec.size), rec.completed_at ? 'ירדה ' + SF.when(rec.completed_at) : null].filter(Boolean).join(' · ')),
                    busy ? h('progress', { max: 100, value: pct(live), 'aria-label': 'התקדמות ההורדה של ' + rec.title }) : null,
                    h('div', { class: 'l2' }, busy ? h('span', { class: 'dl-st' }, '⬇️ מוריד… ' + pct(live) + '%') : h('span', { class: 'dl-st ' + st.state }, (st.state === 'ready' ? '✓ ' : '⚠️ ') + st.text),
                        cur && rec.revision && cur.pid !== rec.revision ? h('span', { class: 'chip' }, 'יש גרסה ' + cur.n) : null)),
                busy ? null : h('div', { class: 'dl-acts' },
                    st.state !== 'ready' ? h('button', { class: 'btn sm primary', type: 'button', disabled: SF.isOffline(), onclick: () => startDownload(rec.edition, rec.revision) }, 'להוריד שוב') : null,
                    h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'מחיקת ההורדה: ' + rec.title, onclick: () => removeSheet(rec, draw) }, '🗑️')));
        }

        async function draw() {
            if (!main.isConnected) return;
            const cat = await SF.catalog(true).catch(() => null);
            const recs = await SF.offline.records();
            const have = new Set(recs.map(r => r.edition));
            SF.put(listBox, recs.length ? recs.map(r => card(r, cat)) : h('li', { class: 'empty' }, 'עוד לא הורדתם כלום. בלי אינטרנט נפתח רק מה שהורד.'));
            const rest = [];
            for (const t of (cat && cat.titles) || []) for (const e of t.editions) if (!have.has(e.pid)) rest.push({ t, e });
            SF.put(moreBox, rest.length ? [h('h3', { class: 'sec-title' }, 'עוד לא הורדו'),
                h('ul', { class: 'list' }, rest.map(({ t, e }) => h('li', { class: 'card' },
                    h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', { class: 'one' }, t.name), h('span', { class: 'muted one' }, ' · ' + e.label)),
                        h('div', { class: 'l2' }, h('span', { class: 'dl-st none' }, '📡 לא זמין בלי אינטרנט'))),
                    h('button', { class: 'btn sm primary', type: 'button', disabled: SF.isOffline() || !SF.offline.supported, onclick: async () => {
                        startDownload(e.pid, await SF.version.get(e.pid));
                    } }, '⬇️ הורדה'))))] : null);
            info();
        }

        SF.put(main,
            h('h2', { class: 'sec-title' }, 'הורדות'),
            h('p', { class: 'hint' }, 'הורדה = קריאה בלי אינטרנט. שום ספר לא יורד מעצמו — רק מה שבחרתם כאן או בקורא. מחיקת הורדה לא מוחקת סימניות, הערות והגדרות.'),
            infoBox,
            h('h3', { class: 'sec-title' }, 'במכשיר'),
            listBox,
            moreBox,
            h('h3', { class: 'sec-title' }, 'התקנה'),
            h('p', { class: 'hint' }, 'התקנה שומרת את האפליקציה במכשיר ופותחת אותה בלי שורת כתובת — אבל לא מורידה ספרים. באייפון: שיתוף ← "הוספה למסך הבית".'),
            installEvt ? h('button', { class: 'btn ghost', type: 'button', onclick: async () => { const e = installEvt; installEvt = null; e.prompt(); await e.userChoice.catch(() => null); SF.render(); } }, '📲 התקנת האפליקציה') : null);
        let t = 0;
        stop = SF.offline.watch(() => { clearTimeout(t); t = setTimeout(draw, 150); });
        const net = () => draw();
        window.addEventListener('online', net);
        window.addEventListener('offline', net);
        SF.onLeave(() => { stop(); window.removeEventListener('online', net); window.removeEventListener('offline', net); });
        await draw();
    }, { title: 'הורדות' });
})(window.SF);
