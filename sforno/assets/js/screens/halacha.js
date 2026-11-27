/*
 * הלכה יומית (אפיון 07 §4) — #/halacha (של היום), #/halacha/archive (בחירת תאריך וחיפוש), #/halacha/d/<חודש>-<יום>.
 * SF.homeHalacha(into) — הרכיב במסך הבית.
 *
 *   "היום" מתחלף בצאת הכוכבים במיקום ובשיטה של הקורא (SF.daily.state → SFCal.today). בלי מיקום / שיטה — בחצות, ונאמר.
 *   **אין הלכה לתאריך ← לא מוצג דבר**: לא הודעה ולא הלכה חלופית; במסך הבית הרכיב לא נבנה בכלל (SFDailyCore.homeHalacha).
 *   בלי רשת — מחבילת הזמנים שהורדה (רק היום ומחר שהשרת כבר נתן), עם התאריך, המקום והשיטה.
 */
'use strict';

(function (SF) {
    const { h } = SF;
    const Cal = window.SFCal;
    const D = window.SFDailyCore;
    const show = html => SFRender.render(html, { conds: {}, condDefault: false, condCollapsed: true, filesBase: 'api.php?action=read-file&path=' });

    /** השורה מתחת לכותרת: איך נקבע "היום" */
    function basis(st) {
        const t = st.today;
        if (t.approx === 'no_method') return 'מתחלף בחצות — כדי שיתחלף בצאת הכוכבים, בחרו מיקום ושיטה בזמני היום';
        if (t.approx === 'no_tzeit') return 'היום אי אפשר לחשב צאת הכוכבים ב' + st.place.name + ' — מתחלף בחצות';
        return 'מתחלף בצאת הכוכבים · ' + st.place.name + ' · ' + st.method.name;
    }

    function itemView(res) {
        const it = D.homeHalacha(res);
        if (!it) return null;
        return h('article', { class: 'hl-item' }, show(it.html),
            res.offline ? h('p', { class: 'hint' }, 'נשמר במכשיר · ' + [res.place, res.method].filter(Boolean).join(' · ')) : null);
    }

    /** מסך הבית: הלכה של היום, או כלום (בלי אזור ריק) */
    SF.homeHalacha = async function homeHalacha(into) {
        try {
            const st = await SF.daily.state();
            const res = await SF.daily.halacha(st.today.heb);
            const it = D.homeHalacha(res);
            if (!it || !into.isConnected) return;
            into.replaceChildren(h('section', { class: 'hl-home', 'aria-label': 'הלכה יומית' },
                h('a', { class: 'hl-home-head', href: '#/halacha' }, h('b', null, '🕯️ הלכה יומית'), h('span', { class: 'muted' }, Cal.hebText(st.today.heb)), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')),
                h('div', { class: 'hl-home-body' }, show(it.html))));
        } catch (e) { /* הרכיב פשוט לא מוצג */ }
    };

    SF.route('#/halacha', async main => {
        const st = await SF.daily.state();
        const t = st.today;
        const res = await SF.daily.halacha(t.heb).catch(e => (e.code === 'network' ? { item: null, offline: true } : Promise.reject(e)));
        SF.put(main,
            h('div', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, SF.daily.todayLine(t))),
                h('div', { class: 'line hint' }, basis(st))),
            itemView(res),
            h('a', { class: 'btn ghost', href: '#/halacha/archive' }, '🗂️ ארכיון וחיפוש'));
    }, { title: 'הלכה יומית' });

    SF.route('#/halacha/d/:key', async (main, { key }) => {
        const m = /^(\d{1,2})-(\d{1,2})$/.exec(key);
        if (!m) return SF.go('#/halacha/archive');
        const st = await SF.daily.state();
        const hy = st.today.heb.y;
        const month = +m[1], day = +m[2];
        const f = Cal.fixedFromHeb(hy, month, day);
        const back = document.getElementById('back');
        back.dataset.to = '#/halacha/archive';
        const title = Cal.gem(day) + ' ' + Cal.monthName(month, f === null ? null : Cal.isLeap(hy));
        document.title = title + ' · הלכה יומית · ספורנו';
        let res = { item: null };
        if (f !== null) res = await SF.daily.halacha({ y: hy, m: month, d: day }).catch(() => ({ item: null }));
        SF.put(main, h('h2', { class: 'sec-title' }, 'הלכה יומית · ' + title),
            itemView(res) || h('p', { class: 'empty' }, f === null ? 'התאריך הזה לא חל השנה' : 'אין הלכה שפורסמה לתאריך הזה'));
    }, { title: 'הלכה יומית', parent: '#/halacha/archive' });

    SF.route('#/halacha/archive', async main => {
        const st = await SF.daily.state();
        const leap = Cal.isLeap(st.today.heb.y);
        const months = Cal.ORDER.filter(m => leap || m !== 12);
        const mSel = h('select', { 'aria-label': 'חודש' }, h('option', { value: '' }, 'כל החודשים'), months.map(m => h('option', { value: m }, Cal.monthName(m, leap))));
        const dSel = h('select', { 'aria-label': 'יום' }, Array.from({ length: 30 }, (_, i) => h('option', { value: i + 1 }, Cal.gem(i + 1))));
        const q = h('input', { type: 'search', placeholder: 'חיפוש בהלכות שפורסמו', 'aria-label': 'חיפוש', enterkeyhint: 'search' });
        const out = h('div', { 'aria-live': 'polite' });
        async function search() {
            SF.put(out, h('p', { class: 'loading' }, 'מחפש…'));
            try {
                const r = await SF.api('read-halacha-archive', { params: { q: q.value.trim(), month: mSel.value } });
                SF.put(out, r.items.length ? h('ul', { class: 'list' }, r.items.map(it => h('li', null, h('a', { class: 'card', href: '#/halacha/d/' + it.month + '-' + it.day },
                    h('div', { class: 'body' }, h('div', { class: 'l1' }, h('b', null, Cal.gem(it.day) + ' ' + Cal.monthName(it.month, leap))), h('div', { class: 'l2' }, it.excerpt))))))
                    : h('p', { class: 'empty' }, 'לא נמצאו הלכות'),
                    r.total > r.items.length ? h('p', { class: 'hint' }, 'מוצגות ' + r.items.length + ' מתוך ' + r.total + ' — צמצמו בחיפוש או בחודש') : null);
            } catch (e) {
                SF.put(out, h('p', { class: 'notice' }, e.code === 'network' ? '📡 הארכיון זמין רק עם אינטרנט' : e.message));
            }
        }
        mSel.value = String(st.today.heb.m);
        SF.put(main,
            h('h2', { class: 'sec-title' }, 'ארכיון ההלכה היומית'),
            h('form', { class: 'toolbar', onsubmit: e => { e.preventDefault(); search(); } }, q, h('button', { class: 'btn primary', type: 'submit' }, 'חיפוש')),
            h('div', { class: 'fields two' }, h('label', { class: 'f' }, h('span', null, 'חודש'), mSel), h('label', { class: 'f' }, h('span', null, 'יום'), dSel)),
            h('button', { class: 'btn ghost', type: 'button', onclick: () => { if (mSel.value) SF.go('#/halacha/d/' + mSel.value + '-' + dSel.value); else SF.toast('בחרו חודש', true); } }, 'פתיחת התאריך'),
            out);
        mSel.addEventListener('change', search);
        await search();
    }, { title: 'ארכיון ההלכה', parent: '#/halacha' });
})(window.SF);
