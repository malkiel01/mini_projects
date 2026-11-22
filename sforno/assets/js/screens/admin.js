/*
 * הניהול: #/admin — לוח הבקרה (10, screens/ops.js), התוכן (02, screens/content.js), הייבוא (03), הקורא (04), ההלכה היומית ושיטות הזמנים (07,
 * screens/daily-admin.js), החנות (09, screens/store-admin.js); #/admin/staff — המנהלים (§6).
 * כל המנהלים בהרשאות מלאות. השרת מכריע (lib/access.php); כאן רק מסתירים מה שאין טעם להציג.
 */
'use strict';

(function (SF) {
    const { h } = SF;

    SF.route('#/admin', main => {
        main.replaceChildren(
            h('ul', { class: 'list' },
                h('li', null, h('a', { class: 'use-row', href: '#/admin/ops' },
                    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🧭'),
                    h('span', { class: 'use-tx' }, h('b', null, 'לוח הבקרה'), h('small', null, 'תקלות, דיווחים, מדדים, תמיכה בקורא, מה פורסם')),
                    h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))),
                h('li', null, h('a', { class: 'use-row', href: '#/admin/content' },
                    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '📚'),
                    h('span', { class: 'use-tx' }, h('b', null, 'תוכן'), h('small', null, 'כותרים, מהדורות, עורך, פרסום ושחזור')),
                    h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'))),
                [['#/admin/customs', '🔀', 'מנהגים', 'הרשימה שהתוכן מצביע עליה: קבוצות, ברירות מחדל, זמין / לא'],
                    ['#/admin/siddur', '📖', 'רשימת הסידור', 'התפילות והקיצורים שהקורא רואה בפתיחה'],
                    ['#/admin/checks', '✅', 'בדיקת מנהגים', 'כל שילוב מנהגים מול הטקסט — "אושר" עם גרסה ותאריך'],
                    ['#/admin/halacha', '🕯️', 'הלכה יומית', 'לפי תאריך עברי: טיוטה, פרסום, והתאריך הלועזי השנה'],
                    ['#/admin/zmanim', '🕰️', 'שיטות זמני היום', 'הגדרה, אישור וברירת מחדל — הקורא בוחר רק ממאושרות'],
                    ['#/admin/store', '🛒', 'חנות', 'סנכרון מ-WooCommerce, מיפוי שדות, קישור לספר דיגיטלי']].map(([href, ic, b, sm]) =>
                    h('li', null, h('a', { class: 'use-row', href }, h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
                        h('span', { class: 'use-tx' }, h('b', null, b), h('small', null, sm)), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))),
                h('li', null, h('a', { class: 'use-row', href: '#/admin/staff' },
                    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '👥'),
                    h('span', { class: 'use-tx' }, h('b', null, 'מנהלים'), h('small', null, 'מי מנהל את ספורנו, השעיה וביטול השעיה')),
                    h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))));
    }, { title: 'ניהול' });

    const hue = s => [...s].reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 360, 7);
    const initials = n => n.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('') || '?';

    SF.route('#/admin/staff', async main => {
        const { staff } = await SF.api('staff-list');
        const active = staff.filter(s => s.status === 'active').length;
        const row = s => {
            const suspended = s.status === 'suspended';
            const act = s.me ? null : h('button', {
                class: 'btn sm ' + (suspended ? 'ghost' : 'ghost danger'), type: 'button',
                'aria-label': (suspended ? 'ביטול השעיה: ' : 'השעיה: ') + s.name,
                disabled: !suspended && active <= 1,
                onclick: async e => {
                    const verb = suspended ? 'לבטל את ההשעיה של' : 'להשעות את';
                    const btn = e.currentTarget;
                    if (!confirm(`${verb} ${s.name}?`)) return;
                    btn.disabled = true;
                    try {
                        await SF.api(suspended ? 'staff-unsuspend' : 'staff-suspend', { body: { staff: s.pid } });
                        SF.toast(suspended ? 'ההשעיה בוטלה' : 'החשבון הושעה');
                        SF.render();
                    } catch (err) {
                        SF.toast(err.message, true);
                        btn.disabled = false;
                    }
                },
            }, suspended ? 'ביטול השעיה' : 'השעיה');
            return h('li', { class: 'card', style: 'cursor:default' },
                h('span', { class: 'avatar', style: '--h:' + hue(s.name), 'aria-hidden': 'true' }, initials(s.name)),
                h('div', { class: 'body' },
                    h('div', { class: 'l1' }, h('span', null, s.name + (s.me ? ' (את/ה)' : '')), h('span', { class: 't' }, SF.when(s.last_seen_at))),
                    h('div', { class: 'l2' }, s.email || 'בלי מייל', s.is_developer ? h('i', { class: 'sep', 'aria-hidden': 'true' }, '·') : null, s.is_developer ? 'מפתח' : null)),
                h('span', { class: 'chip ' + (suspended ? 'bad' : 'ok') }, suspended ? 'מושעה' : 'פעיל'),
                act);
        };
        main.replaceChildren(
            h('p', { class: 'hint' }, 'מנהל חדש נוסף כשמנהל הכלים הדיגיטליים נותן לו הרשאה לספורנו, והוא נכנס. השעיה חוסמת אותו כאן מהבקשה הבאה; תמיד נשאר מנהל פעיל אחד.'),
            h('ul', { class: 'list' }, staff.length ? staff.map(row) : h('li', { class: 'empty' }, 'אין מנהלים')));
    }, { title: 'מנהלים', parent: '#/admin' });
})(window.SF);
