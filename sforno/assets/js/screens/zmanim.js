/*
 * זמני היום (אפיון 07 §2, §3) — #/zmanim. בראש: המיקום, השיטה, התאריך ואזור הזמן (§11). אחר כך הזמנים של היום — וזמן
 * שלא ניתן לחישוב במקום: "לא ניתן לחישוב במקום הזה", בלי שעה.
 *
 *   אין מיקום  — בחירה: "מיקום המכשיר" (רק בלחיצה; בסירוב — רשימת הערים, בלי הודעת שגיאה) או עיר מהרשימה.
 *   אין שיטה   — (אין ברירת מחדל של המנהל, ולא נבחרה) בחירה מהשיטות המאושרות, **ולא מוצגת שום שעה** (§3).
 *   בלי רשת    — רק מחבילת החודש שהורדה; מיקום אחר מזה של החבילה ← "הזמנים מחושבים ל<עיר> — נדרש עדכון".
 *   SF.daily.locSheet / methodSheet — גם למסכים אחרים (תהילים, הלכה).
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const Cal = window.SFCal;
    const D = window.SFDailyCore;

    // ── בחירת מיקום ────────────────────────────────────────────
    SF.daily.locSheet = async function locSheet(after) {
        const cities = await SF.daily.cities();
        const msg = h('div', { 'aria-live': 'polite' });
        const q = h('input', { type: 'search', placeholder: 'חיפוש עיר — בעברית או באנגלית', 'aria-label': 'חיפוש עיר', enterkeyhint: 'search', autocomplete: 'off' });
        const list = h('ul', { class: 'list loc-list' });
        const pick = async loc => { await SF.daily.setLoc(loc); dlg.close(); SF.toast('המיקום נשמר'); if (after) after(); };
        function draw() {
            const found = D.findCities(cities, q.value).slice(0, 60);
            SF.put(list, found.length ? found.map(c => h('li', { class: 'card', onclick: () => pick({ kind: 'city', id: c.id }) },
                h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', { class: 'one' }, c.name), h('span', { class: 't' }, c.en)),
                    h('div', { class: 'l2' }, c.tz)),
                h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'בחירה: ' + c.name, onclick: e => { e.stopPropagation(); pick({ kind: 'city', id: c.id }); } }, 'בחירה')))
                : h('li', { class: 'empty' }, 'לא נמצאה עיר בשם הזה'));
        }
        q.addEventListener('input', draw);
        const gps = h('button', { class: 'btn primary wide-btn', type: 'button', onclick: async () => {
            gps.disabled = true;
            SF.put(msg, h('p', { class: 'hint' }, '📍 מאתר…'));
            const r = await SF.daily.devicePosition();
            gps.disabled = false;
            if (!r.ok) {
                // סירוב / תקלה — לא שגיאה: עוברים לבחירת עיר (K9)
                SF.put(msg, h('p', { class: 'notice' }, r.why === 'denied' ? 'לא אושרה גישה למיקום. בחרו עיר מהרשימה.' : 'לא הצלחנו לאתר את המיקום. בחרו עיר מהרשימה.'));
                q.focus();
                return;
            }
            pick(D.deviceLoc(Cal, r.coords, cities, SF.daily.deviceTz));
        } }, '📍 מיקום המכשיר');
        draw();
        const dlg = SF.sheet('מיקום', h('div', { class: 'loc-sheet' },
            h('p', { class: 'hint' }, 'הזמנים מחושבים לפי המיקום ואזור הזמן שלו. נשמר רק המיקום הפעיל — לא היסטוריה. מיקום המכשיר נשמר מעוגל (כקילומטר).'),
            gps, msg,
            h('h3', { class: 'sec-title' }, 'או עיר מהרשימה'), q, list,
            h('p', { class: 'hint' }, 'רשימת הערים: GeoNames ‏(CC BY 4.0).')));
    };

    // ── בחירת שיטה ─────────────────────────────────────────────
    SF.daily.methodSheet = async function methodSheet(after) {
        const st = await SF.daily.state();
        const list = st.methods.list;
        const pick = async m => { await SF.daily.setMethod(m.pid); dlg.close(); SF.toast('השיטה נבחרה'); if (after) after(); };
        const dlg = SF.sheet('שיטת חישוב', h('div', null,
            h('p', { class: 'hint' }, 'השיטות שאושרו לספורנו. הבחירה נשמרת אצלך.'),
            list.length ? h('ul', { class: 'list' }, list.map(m => h('li', { class: 'card', onclick: () => pick(m) },
                h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', { class: 'one' }, m.name), st.method && st.method.pid === m.pid ? h('span', { class: 'chip ok' }, 'נבחרה') : null,
                    m.test ? h('span', { class: 'chip warn' }, 'לבדיקה בלבד') : null, m.pid === st.methods.dflt ? h('span', { class: 'chip' }, 'ברירת מחדל') : null),
                    m.note ? h('div', { class: 'l2' }, m.note) : null))))
                : h('p', { class: 'empty' }, st.methods.offline ? '📡 בלי אינטרנט אי אפשר לטעון את השיטות' : 'עוד לא אושרה שיטת חישוב. הזמנים יוצגו כשתאושר.')));
    };

    function rowsList(rows) {
        return h('ul', { class: 'list zm-list' }, rows.map(r => h('li', { class: 'zm-row' + (r.time ? '' : ' none') },
            h('span', { class: 'zm-name' }, r.name),
            r.time ? h('b', { class: 'zm-time', dir: 'ltr' }, r.time) : h('span', { class: 'zm-none' }, 'לא ניתן לחישוב במקום הזה'))));
    }

    // ── המסך ───────────────────────────────────────────────────
    SF.route('#/zmanim', async main => {
        let shift = 0;   // ימים מהיום
        const body = h('div');
        const stop = SF.daily.onChange(() => draw());
        SF.onLeave(stop);
        const net = () => draw();
        window.addEventListener('online', net);
        window.addEventListener('offline', net);
        SF.onLeave(() => { window.removeEventListener('online', net); window.removeEventListener('offline', net); });

        async function draw() {
            if (!main.isConnected) return;
            const st = await SF.daily.state();
            const pkg = await SF.daily.pkg();
            const off = SF.isOffline() || st.methods.offline;
            const where = st.place;
            const head = [];
            const chg = (label, fn) => h('button', { class: 'btn sm ghost', type: 'button', onclick: fn }, label);
            head.push(h('div', { class: 'line' }, h('span', { class: 'muted' }, 'מיקום:'), h('b', { class: 'one' }, where ? where.name : 'לא נבחר'), chg(where ? 'שינוי' : 'בחירה', () => SF.daily.locSheet(draw))));
            head.push(h('div', { class: 'line' }, h('span', { class: 'muted' }, 'שיטה:'), h('b', { class: 'one' }, st.method ? st.method.name : 'לא נבחרה'),
                st.method && st.method.test ? h('span', { class: 'chip warn' }, 'לבדיקה בלבד') : null, chg(st.method ? 'שינוי' : 'בחירה', () => SF.daily.methodSheet(draw))));

            // אין מיקום / אין שיטה — בחירה, בלי שעות
            if (!where || !st.method) {
                SF.put(body, h('div', { class: 'summary' }, head),
                    !where ? h('div', { class: 'zm-need' }, h('p', null, 'לאיזה מקום לחשב?'), h('button', { class: 'btn primary', type: 'button', onclick: () => SF.daily.locSheet(draw) }, '📍 בחירת מיקום')) : null,
                    where && !st.method ? h('div', { class: 'zm-need' }, h('p', null, st.pick.lost ? 'השיטה שבחרתם כבר לא זמינה. בחרו שיטת חישוב כדי לראות את הזמנים.' : 'בחרו שיטת חישוב כדי לראות את הזמנים.'),
                        h('button', { class: 'btn primary', type: 'button', onclick: () => SF.daily.methodSheet(draw) }, 'בחירת שיטה')) : null);
                return;
            }

            const t = st.today;
            const f = t.civil ? Cal.fixedFromGreg(t.civil.y, t.civil.m, t.civil.d) + shift : t.fixed;
            const g = Cal.gregFromFixed(f);
            const dIso = D.iso(g);
            const ps = D.pkgState(pkg, st.loc, st.method.pid, off ? null : st.method.version, D.iso(t.civil));
            let rows = null, shownFor = where, fromPkg = false, notice = null;
            if (off) {
                const day = pkg ? D.pkgDay(Cal, pkg, dIso) : null;
                if (day && ps.state !== 'other_method') {
                    rows = day.rows; fromPkg = true;
                    shownFor = pkg.place;
                    if (ps.state === 'other_place') notice = h('p', { class: 'notice warn' }, '⚠️ ' + ps.text + '. הזמנים כאן — ל' + pkg.place.name + ', לא ל' + where.name + '.');
                    else notice = h('p', { class: 'notice' }, '📡 בלי אינטרנט — מהחבילה שהורדה (' + pkg.method.name + ', גרסה ' + pkg.method.version + ').');
                } else {
                    notice = h('p', { class: 'notice' }, pkg && ps.state === 'other_method' ? '📡 בלי אינטרנט. ' + ps.text + '.' : '📡 בלי אינטרנט — הזמנים זמינים רק אחרי הורדת חודש.');
                }
            } else {
                rows = D.dayRows(Cal, st.method.def, where, g);
            }
            const ms = Cal.zonedToUtc(shownFor.tz, g.y, g.m, g.d, 12, 0);
            head.push(h('div', { class: 'line' }, h('span', { class: 'muted' }, 'תאריך:'), h('b', null, SF.daily.dateLine(g, Cal.hebFromFixed(f), Cal.weekday(f)))));
            head.push(h('div', { class: 'line' }, h('span', { class: 'muted' }, 'אזור זמן:'), h('span', { dir: 'ltr' }, shownFor.tz + ' (' + SF.daily.tzLabel(shownFor.tz, ms) + ')')));

            const nav = h('div', { class: 'zm-nav' },
                h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'היום הקודם', onclick: () => { shift--; draw(); } }, '›'),
                h('button', { class: 'btn sm ghost', type: 'button', disabled: shift === 0, onclick: () => { shift = 0; draw(); } }, 'היום'),
                h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'היום הבא', onclick: () => { shift++; draw(); } }, '‹'));

            const dlBtn = (label, cls) => h('button', { class: 'btn sm ' + cls, type: 'button', disabled: off, onclick: async e => {
                e.currentTarget.disabled = true;
                try { await SF.daily.download(); SF.toast('הזמנים לחודש הקרוב זמינים עכשיו גם בלי אינטרנט'); } catch (x) { SF.toast(x.message, true); }
                draw();
            } }, label);
            const dl = h('div', { class: 'dl-line' },
                h('span', { class: 'dl-st ' + (ps.state === 'ok' ? 'ready' : ps.state === 'none' ? 'none' : 'stale') }, (ps.state === 'ok' ? '✓ ' : ps.state === 'none' ? '📡 ' : '⚠️ ') + (ps.state === 'none' ? 'לא זמין בלי אינטרנט' : ps.text)),
                ps.state === 'none' ? dlBtn('⬇️ הורדת חודש', 'primary') : ps.state !== 'ok' ? dlBtn('עדכון ההורדה', 'primary') : null);

            SF.put(body, h('div', { class: 'summary' }, head), notice, nav,
                rows ? rowsList(rows) : null,
                rows && t.approx === 'no_tzeit' && shift === 0 ? h('p', { class: 'hint' }, 'במקום הזה היום לא ניתן לחשב את צאת הכוכבים — ההלכה והתהילים מתחלפים היום בחצות.') : null,
                h('h3', { class: 'sec-title' }, 'בלי אינטרנט'), dl,
                h('p', { class: 'hint' }, 'הורדת חודש שומרת במכשיר את הזמנים של 30 הימים הקרובים למיקום ולשיטה שבחרתם, עם גרסת השיטה. שום דבר לא יורד מעצמו.'),
                fromPkg ? null : h('p', { class: 'hint' }, 'החישוב נעשה במכשיר (נוסחאות NOAA), לפי אזור הזמן של המקום ולא של המכשיר — כולל שעון קיץ.'));
        }
        SF.put(main, h('h2', { class: 'sec-title' }, 'זמני היום'), body);
        await draw();
    }, { title: 'זמני היום' });
})(window.SF);
