/*
 * ספורנו — ההפעלה: מי המנהל (me), הכותרת והתפריט, DevLoop (🐞 למפתח בלבד), והמסך הראשון.
 * הכניסה עצמה — מהכלים הדיגיטליים (השער); אין כאן מסך כניסה. כשלון → הודעה אחידה עם "נסו שוב".
 */
'use strict';

(function (SF) {
    const { h } = SF;
    let me = null;
    SF.isDev = () => !!(me && me.is_developer);

    function menu() {
        const ul = document.getElementById('menu');
        const btn = document.getElementById('menu-btn');
        const close = () => { ul.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
        const item = (icon, label, href) => h('li', null, h('a', { href, onclick: close }, h('span', { 'aria-hidden': 'true' }, icon), label));
        ul.replaceChildren(
            h('li', { class: 'who' }, me.name + (me.email ? ' · ' + me.email : '')),
            item('🏠', 'מסך הבית', '#/'),
            item('🗂️', 'ניהול', '#/admin'),
            item('👥', 'מנהלים', '#/admin/staff'),
            SF.isDev() ? item('🛠️', 'פיתוח', '#/dev/tasks') : null,
            h('li', null, h('button', { type: 'button', onclick: logout }, h('span', { 'aria-hidden': 'true' }, '🚪'), 'יציאה')));
        btn.addEventListener('click', () => {
            const open = ul.hidden;
            ul.hidden = !open;
            btn.setAttribute('aria-expanded', String(open));
            if (open) ul.querySelector('a, button')?.focus();
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !ul.hidden) { close(); btn.focus(); } });
        document.addEventListener('click', e => { if (!ul.hidden && !e.target.closest('.menu-wrap')) close(); });
    }

    async function logout() {
        try { await SF.api('logout', { body: {} }); } catch (e) { /* גם אם נכשל — מראים את מסך היציאה */ }
        document.getElementById('menu').hidden = true;
        document.getElementById('main').replaceChildren(h('div', { class: 'fail' },
            h('div', { class: 'ic', 'aria-hidden': 'true' }, '👋'),
            h('p', null, 'יצאת מספורנו. כדי לצאת מכל המערכות — יוצאים מהכלים הדיגיטליים.'),
            h('button', { class: 'btn primary', type: 'button', onclick: () => location.reload() }, 'כניסה מחדש')));
    }

    async function boot() {
        document.getElementById('back').addEventListener('click', e => SF.go(e.currentTarget.dataset.to || '#/'));
        let data;
        try {
            data = await SF.api('me');
        } catch (e) {
            document.getElementById('menu-btn').hidden = true;
            return SF.fail(document.getElementById('main'), e);
        }
        me = data.staff;
        SF.setCsrf(data.csrf);
        document.getElementById('who').textContent = me.name;
        menu();
        DevLoop.setup({
            api: SF.api, toast: SF.toast, isDev: SF.isDev, appName: 'ספורנו', endpoint: 'api.php',
            tasksHref: '#/dev/tasks', mountBefore: '#menu-btn', headers: SF.headers,
            version: () => data.version, lang: () => 'he', builtinLog: true,
        });
        DevLoop.mount();
        SF.render();
    }

    boot();
})(window.SF);
