/*
 * מסך הבית (§4) — הכפתורים המרכזיים: סידור ותהילים (04), והשאר — מסך "בקרוב" עם שם הרכיב. הקיצורים האישיים, המידע
 * היומי והמשך הקריאה — בפרוסות שלהם. ההסבר על שמירה מקומית — פעם אחת (04).
 */
'use strict';

(function (SF) {
    const { h } = SF;

    SF.PARTS = [
        { key: 'siddur', icon: '📖', name: 'סידור', slice: '04', href: '#/siddur' },
        { key: 'tehillim', icon: '📜', name: 'תהילים', slice: '04', href: '#/tehillim' },
        { key: 'halacha', icon: '🕯️', name: 'הלכה יומית', slice: '07' },
        { key: 'zmanim', icon: '🕰️', name: 'זמני היום', slice: '07' },
        { key: 'store', icon: '🛒', name: 'חנות', slice: '09' },
    ];

    SF.route('#/', main => {
        main.replaceChildren(
            h('section', { class: 'home-hero' }, h('h2', null, 'ספורנו'), h('p', { class: 'muted' }, 'סידור, תהילים, הלכה יומית, זמני היום וחנות')),
            h('nav', { class: 'tiles big', 'aria-label': 'הרכיבים' },
                SF.PARTS.map(p => h('a', { class: 'tile', href: p.href || '#/soon/' + p.key },
                    h('span', { class: 'tile-ic', 'aria-hidden': 'true' }, p.icon), p.name))),
            h('a', { class: 'btn ghost home-search', href: '#/search' }, '🔍 חיפוש בספרייה'));
        SF.localNotice(main);
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
