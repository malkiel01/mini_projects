/*
 * מסך הבית (§4) — הכפתורים המרכזיים: סידור ותהילים (04), הלכה יומית וזמני היום (07), תזכורות (08), חנות (09), והשאר — מסך "בקרוב" עם שם הרכיב.
 * התאריך העברי — לפי צאת הכוכבים במיקום ובשיטה (07). ההלכה של היום — רכיב שנבנה רק אם יש הלכה; אין — אין רכיב ואין
 * אזור ריק (§4). הקיצורים האישיים והמשך הקריאה — בפרוסות שלהם. ההסבר על שמירה מקומית — פעם אחת (04).
 */
'use strict';

(function (SF) {
    const { h } = SF;

    SF.PARTS = [
        { key: 'siddur', icon: '📖', name: 'סידור', slice: '04', href: '#/siddur' },
        { key: 'tehillim', icon: '📜', name: 'תהילים', slice: '04', href: '#/tehillim' },
        { key: 'halacha', icon: '🕯️', name: 'הלכה יומית', slice: '07', href: '#/halacha' },
        { key: 'zmanim', icon: '🕰️', name: 'זמני היום', slice: '07', href: '#/zmanim' },
        { key: 'reminders', icon: '⏰', name: 'תזכורות', slice: '08', href: '#/reminders' },
        { key: 'store', icon: '🛒', name: 'חנות', slice: '09', href: '#/store' },
    ];

    SF.route('#/', main => {
        const date = h('p', { class: 'muted' }, 'סידור, תהילים, הלכה יומית, זמני היום וחנות');
        const halacha = h('div', { class: 'hl-slot' });
        main.replaceChildren(
            h('section', { class: 'home-hero' }, h('h2', null, 'ספורנו'), date),
            halacha,
            h('nav', { class: 'tiles big', 'aria-label': 'הרכיבים' },
                SF.PARTS.map(p => h('a', { class: 'tile', href: p.href || '#/soon/' + p.key },
                    h('span', { class: 'tile-ic', 'aria-hidden': 'true' }, p.icon), p.name))),
            h('a', { class: 'btn ghost home-search', href: '#/search' }, '🔍 חיפוש בספרייה'));
        SF.localNotice(main);
        SF.daily.state().then(st => { if (date.isConnected) date.textContent = SF.daily.todayLine(st.today); }).catch(() => null);
        SF.homeHalacha(halacha);
    }, { parent: null });

    SF.route('#/soon/:key', (main, { key }) => {
        const p = SF.PARTS.find(x => x.key === key);
        if (!p) return SF.go('#/');
        document.title = p.name + ' · ספורנו';
        main.replaceChildren(h('section', { class: 'soon' },
            h('div', { class: 'ic', 'aria-hidden': 'true' }, p.icon),
            h('h2', null, p.name),
            h('p', { class: 'muted' }, 'בקרוב'),
            h('a', { class: 'btn ghost', href: '#/' }, 'למסך הבית')));
    }, { title: 'בקרוב' });
})(window.SF);
