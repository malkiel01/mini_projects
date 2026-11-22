/*
 * ספורנו — ניתוב ב-#/… . מסך = פונקציה (main, params) שבונה את התוכן. חץ החזרה חוזר למסך הקודם
 * באפליקציה, עד מסך הבית — ובמסך הבית אין חץ (לעולם לא יוצאים מהאפליקציה).
 */
'use strict';

(function (SF) {
    const routes = [];   // [{re, keys, screen, title, parent}]

    /** SF.route('#/admin/staff', screen, {title, parent: '#/admin'}); ':x' — פרמטר, '*' — השאר */
    SF.route = function route(pattern, screen, opts = {}) {
        const keys = [];
        const re = new RegExp('^' + pattern.replace(/[.?+]/g, '\\$&').replace(/:(\w+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }).replace(/\*$/, '(.*)') + '$');
        if (pattern.endsWith('*')) keys.push('rest');
        routes.push({ re, keys, screen, title: opts.title || '', parent: 'parent' in opts ? opts.parent : '#/', dev: !!opts.dev });
    };

    function match(hash) {
        for (const r of routes) {
            const m = hash.match(r.re);
            if (m) return { r, params: Object.fromEntries(r.keys.map((k, i) => [k, decodeURIComponent(m[i + 1] || '')])) };
        }
        return null;
    }

    SF.go = hash => { if (location.hash === hash) SF.render(); else location.hash = hash; };

    SF.render = async function render() {
        const hash = location.hash && location.hash !== '#' ? location.hash.replace(/\/$/, '') || '#/' : '#/';
        let found = match(hash === '#' ? '#/' : hash);
        if (!found || (found.r.dev && !SF.isDev())) {
            found = match('#/');
            history.replaceState(null, '', '#/');
        }
        const main = document.getElementById('main');
        const back = document.getElementById('back');
        back.hidden = found.r.parent === null;
        back.dataset.to = found.r.parent || '#/';
        document.title = found.r.title ? found.r.title + ' · ספורנו' : 'ספורנו';
        main.replaceChildren(SF.h('p', { class: 'loading' }, 'טוען…'));
        try {
            await found.r.screen(main, found.params);
        } catch (e) {
            SF.fail(main, e);
        }
        main.focus({ preventScroll: true });
    };

    /** הודעה אחידה לשגיאת רשת / 401 / 403 / 503, עם "נסו שוב". */
    SF.fail = function fail(el, e) {
        const icon = { network: '📡', login_required: '🔑', suspended: '⛔', forbidden: '⛔', login_unavailable: '⏳', storage: '⏳', upgrading: '⏳' }[e && e.code] || '⚠️';
        el.replaceChildren(SF.h('div', { class: 'fail', role: 'alert' },
            SF.h('div', { class: 'ic', 'aria-hidden': 'true' }, icon),
            SF.h('p', null, (e && e.message) || 'משהו השתבש'),
            SF.h('button', { class: 'btn primary', type: 'button', onclick: () => location.reload() }, 'נסו שוב')));
    };

    window.addEventListener('hashchange', () => SF.render());
})(window.SF);
