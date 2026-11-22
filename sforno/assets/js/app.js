/*
 * ספורנו — ההפעלה: מי המנהל (me), הכותרת והתפריט, DevLoop (🐞 למפתח בלבד), והמסך הראשון.
 * כניסת מנהלים — מהכלים הדיגיטליים (השער); אין כאן מסך כניסה למנהלים. כשלון → הודעה אחידה עם "נסו שוב".
 * חשבון הקורא (05) — עולם נפרד: account-me, במקביל, ו"החשבון שלי" בתפריט (screens/account.js).
 */
'use strict';

(function (SF) {
    const { h } = SF;
    let me = null;
    SF.isDev = () => !!(me && me.is_developer);
    SF.isStaff = () => !!me;

    function menu() {
        const ul = document.getElementById('menu');
        const btn = document.getElementById('menu-btn');
        const close = () => { ul.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
        const item = (icon, label, href) => h('li', null, h('a', { href, onclick: close }, h('span', { 'aria-hidden': 'true' }, icon), label));
        ul.replaceChildren(
            me ? h('li', { class: 'who' }, me.name + (me.email ? ' · ' + me.email : '')) : null,
            item('🏠', 'מסך הבית', '#/'),
            item('📖', 'סידור', '#/siddur'),
            item('📜', 'תהילים', '#/tehillim'),
            item('🔍', 'חיפוש', '#/search'),
            item('⬇️', 'הורדות', '#/downloads'),
            item('👤', 'החשבון שלי', '#/account'),
            me ? item('🗂️', 'ניהול', '#/admin') : null,
            me ? item('👥', 'מנהלים', '#/admin/staff') : null,
            SF.isDev() ? item('🛠️', 'פיתוח', '#/dev/tasks') : null,
            me ? h('li', null, h('button', { type: 'button', onclick: logout }, h('span', { 'aria-hidden': 'true' }, '🚪'), 'יציאה')) : null);
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
        const account = SF.accountBoot();
        let data;
        try {
            data = await SF.api('me');
        } catch (e) {
            // לא מנהל (או מחוץ לשער, בעתיד): הקורא עובד בלי זיהוי — הסידור, התהילים והחיפוש (04).
            // בלי רשת (06): ממשיכים כקורא — מה שהורד נפתח מהמכשיר, והשאר מסומן "לא זמין בלי אינטרנט". תקלת שרת — הודעה.
            if (e.code === 'storage' || e.code === 'upgrading') {
                document.getElementById('menu-btn').hidden = true;
                return SF.fail(document.getElementById('main'), e);
            }
            menu();
            await account;
            return SF.render();
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
        await account;
        SF.render();
    }

    boot();
})(window.SF);
