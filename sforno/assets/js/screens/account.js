/*
 * האזור האישי של הקורא (אפיון 05 §1, §6) — עולם נפרד מהמנהלים: account-* ו-sync-* בלבד, עוגיית sf_reader.
 *
 *   #/account                — בלי חשבון: כניסה / הרשמה / Google / "שכחתי סיסמה". עם חשבון: החשבון, הסנכרון,
 *                              המכשירים, פרטיות (ייצוא, בקשת מחיקה) ויציאה
 *   #/account/verify/<token> — אימות מייל (הקישור מהמייל)
 *   #/account/reset/<token>  — סיסמה חדשה (הקישור מהמייל)
 */
'use strict';

(function (SF) {
    const { h } = SF;
    let me = { reader: null, google_client_id: null };

    const STATUS = {
        off: ['💾', 'שמור במכשיר', ''],
        local: ['💾', 'שמור במכשיר', 'warn'],
        busy: ['🔄', 'מסנכרן…', ''],
        synced: ['☁️', 'מסונכרן', 'ok'],
        failed: ['⚠️', 'כשל — ננסה שוב', 'bad'],
    };

    SF.account = {
        reader: () => me.reader,
        /** ה-session נעלם (מכשיר בוטל, איפוס, מחיקה) — חוזרים למצב אורח */
        lost() {
            me = Object.assign({}, me, { reader: null });
            chip();
            SF.toast('החיבור לחשבון במכשיר הזה הסתיים. אפשר להיכנס מחדש', true);
        },
    };

    /** הפעלה (app.js): מי מחובר, ואם יש — הסנכרון. תקלה כאן לא עוצרת את האפליקציה. */
    SF.accountBoot = async function accountBoot() {
        try {
            me = await SF.api('account-me');
        } catch (e) {
            return;
        }
        SF.setReaderCsrf(me.csrf);
        if (me.reader) SF.sync.start(me.reader);
        chip();
    };

    /** החיווי בכותרת: מצב הסנכרון (מוצג רק כשמחוברים). */
    function chip() {
        const el = document.getElementById('sync-chip');
        if (!el) return;
        const info = SF.sync.info();
        el.hidden = !me.reader;
        const [ic, label] = STATUS[info.status] || STATUS.off;
        el.textContent = ic;
        el.setAttribute('aria-label', 'הסנכרון: ' + label + (info.pending ? ' · ' + info.pending + ' ממתינים' : ''));
        el.title = label;
    }
    SF.sync.on(chip);

    async function afterLogin(data, merge) {
        me = data;
        SF.setReaderCsrf(data.csrf);
        chip();
        await SF.sync.start(data.reader, merge ? { merge } : {});
        SF.go('#/account');
    }

    // ── בלי חשבון ────────────────────────────────────────────

    function field(label, attrs) {
        return h('label', { class: 'f wide' }, h('span', null, label), h('input', attrs));
    }

    function loginView(main, tab) {
        const err = h('p', { class: 'error', role: 'alert', hidden: true });
        const showErr = e => { err.textContent = e.message; err.hidden = false; };
        const reg = tab === 'register';
        const form = h('form', { class: 'fields account-form', novalidate: true, onsubmit: async e => {
            e.preventDefault();
            err.hidden = true;
            const f = Object.fromEntries(new FormData(form));
            btn.disabled = true;
            try {
                await afterLogin(await SF.api(reg ? 'account-register' : 'account-login', { body: reg ? { email: f.email, password: f.password, name: f.name } : { email: f.email, password: f.password } }));
                if (reg) SF.toast('נרשמת. שלחנו מייל לאימות הכתובת');
            } catch (x) { showErr(x); } finally { btn.disabled = false; }
        } },
            reg ? field('שם (לא חובה)', { name: 'name', autocomplete: 'name', maxlength: 80 }) : null,
            field('מייל', { name: 'email', type: 'email', autocomplete: 'email', inputmode: 'email', required: true, dir: 'ltr' }),
            field('סיסמה', { name: 'password', type: 'password', autocomplete: reg ? 'new-password' : 'current-password', required: true, minlength: reg ? 8 : null, dir: 'ltr' }),
            reg ? h('p', { class: 'hint wide' }, 'לפחות 8 תווים.') : null,
            err);
        const btn = h('button', { class: 'btn primary wide', type: 'submit' }, reg ? 'הרשמה' : 'כניסה');
        form.append(btn);
        const google = h('div', { class: 'google-box' });
        SF.put(main,
            h('section', { class: 'account-intro' },
                h('h2', null, 'חשבון ספורנו'),
                h('p', { class: 'muted' }, 'עם חשבון — הסימניות, ההערות, ההגדרות והשמות לתפילה נשמרים ומסתנכרנים בין המכשירים שלך. בלי חשבון — הכול נשאר רק במכשיר הזה.')),
            h('div', { class: 'seg-tabs', role: 'tablist', 'aria-label': 'כניסה או הרשמה' },
                [['login', 'כניסה'], ['register', 'הרשמה']].map(([k, l]) => h('button', { type: 'button', role: 'tab', 'aria-selected': String(k === (tab || 'login')),
                    onclick: () => loginView(main, k) }, l))),
            form,
            reg ? null : h('button', { class: 'btn ghost sm', type: 'button', onclick: forgot }, 'שכחתי סיסמה'),
            me.google_client_id ? [h('div', { class: 'or', 'aria-hidden': 'true' }, 'או'), google] : null);
        if (me.google_client_id) googleButton(google, showErr);
        form.querySelector('input').focus();
    }

    /** Google Identity Services — נטען רק כשמזהה הלקוח הוגדר, ורק במסך הזה. ה-ID token נבדק בשרת (account-google). */
    let gis = null;
    function googleButton(el, showErr) {
        gis = gis || new Promise((resolve, reject) => {
            const s = h('script', { src: 'https://accounts.google.com/gsi/client', async: true });
            s.onload = () => resolve(window.google);
            s.onerror = () => { gis = null; reject(new Error('לא ניתן לטעון את הכניסה עם Google')); };
            document.head.append(s);
        });
        gis.then(g => {
            g.accounts.id.initialize({ client_id: me.google_client_id, ux_mode: 'popup', callback: async r => {
                try { await afterLogin(await SF.api('account-google', { body: { credential: r.credential } })); } catch (x) { showErr(x); }
            } });
            g.accounts.id.renderButton(el, { theme: 'outline', size: 'large', text: 'continue_with', locale: 'he', width: Math.min(320, el.clientWidth || 300) });
        }).catch(showErr);
    }

    function forgot() {
        const input = h('input', { type: 'email', autocomplete: 'email', inputmode: 'email', dir: 'ltr', 'aria-label': 'מייל' });
        const msg = h('p', { class: 'hint wide' }, 'נשלח קישור לאיפוס, בתוקף שעה.');
        const send = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
            try {
                await SF.api('account-reset-request', { body: { email: input.value } });
                msg.textContent = 'אם הכתובת רשומה — נשלח אליה קישור לאיפוס. כדאי לבדוק גם בתיבת הספאם.';
                send.disabled = true;
            } catch (e) { msg.textContent = e.message; }
        } }, 'שליחה');
        SF.sheet('איפוס סיסמה', h('div', { class: 'fields' }, h('label', { class: 'f wide' }, h('span', null, 'המייל של החשבון'), input), msg), { action: send });
        input.focus();
    }

    // ── עם חשבון ─────────────────────────────────────────────

    async function accountView(main) {
        const r = me.reader;
        const info = SF.sync.info();
        const [ic, label, cls] = STATUS[info.status] || STATUS.off;
        const devices = h('ul', { class: 'list' }, h('li', { class: 'loading' }, 'טוען…'));
        SF.put(main,
            h('section', { class: 'summary' },
                h('div', { class: 'line' }, h('b', null, r.name)),
                h('div', { class: 'line muted', dir: 'ltr' }, r.email),
                h('div', { class: 'line' }, r.verified ? h('span', { class: 'chip ok' }, '✓ המייל אומת')
                    : [h('span', { class: 'chip warn' }, 'המייל עוד לא אומת'), ' ', h('button', { class: 'btn sm ghost', type: 'button', onclick: async e => {
                        try { await SF.api('account-verify-resend', { body: {} }); SF.toast('נשלח מייל אימות'); e.target.disabled = true; } catch (x) { SF.toast(x.message, true); }
                    } }, 'שליחה חוזרת')]),
                r.verified ? null : h('p', { class: 'hint' }, 'אפשר להשתמש גם בלי אימות; תזכורות במייל — רק אחרי אימות.')),

            h('h3', { class: 'sec-title' }, 'סנכרון'),
            h('div', { class: 'summary' },
                h('div', { class: 'line' }, h('span', { class: 'chip ' + cls }, ic + ' ' + label),
                    info.pending ? h('span', { class: 'muted' }, ' · ' + info.pending + ' שינויים ממתינים') : null),
                h('div', { class: 'line muted' }, 'סנכרון אחרון: ' + SF.when(info.lastOk)),
                h('div', { class: 'row-btns' }, h('button', { class: 'btn sm ghost', type: 'button', onclick: async () => { await SF.sync.now(); accountView(main); } }, '🔄 לסנכרן עכשיו'))),

            h('h3', { class: 'sec-title' }, 'מכשירים מחוברים'),
            devices,

            h('h3', { class: 'sec-title' }, 'פרטיות'),
            h('div', { class: 'list' },
                h('button', { class: 'use-row', type: 'button', onclick: exportData },
                    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '⬇️'), h('span', { class: 'use-tx' }, h('b', null, 'ייצוא המידע שלי'), h('small', null, 'קובץ JSON — כולל הערות ושמות לתפילה'))),
                h('button', { class: 'use-row', type: 'button', onclick: deleteAccount },
                    h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🗑️'), h('span', { class: 'use-tx' }, h('b', null, 'בקשת מחיקה של החשבון'), h('small', null, 'החשבון נסגר מיד; המחיקה המלאה — אחרי 30 יום')))),
            h('p', { class: 'hint' }, 'ההערות והשמות לתפילה נשמרים בשרת מוצפנים, ואינם מוצגים לאף מנהל.'),

            h('button', { class: 'btn ghost wide-btn', type: 'button', onclick: logout }, '🚪 יציאה מהחשבון'));
        const off = SF.sync.on(() => { if (location.hash.startsWith('#/account')) chip(); });
        SF.onLeave(off);
        try {
            const d = await SF.api('account-devices');
            SF.put(devices, d.devices.map(x => h('li', { class: 'card', style: 'cursor:default' },
                h('div', { class: 'body' },
                    h('div', { class: 'l1' }, h('span', null, x.label), x.current ? h('span', { class: 'chip ok' }, 'המכשיר הזה') : null),
                    h('div', { class: 'l2' }, 'נכנס ' + SF.when(x.created_at) + ' · פעיל ' + SF.when(x.last_seen_at))),
                x.current ? null : h('button', { class: 'btn sm ghost', type: 'button', onclick: async () => {
                    if (!confirm('לנתק את "' + x.label + '"? הסנכרון אליו ייעצר, וצריך יהיה להיכנס בו מחדש.')) return;
                    try { await SF.api('account-device-revoke', { body: { device: x.pid } }); SF.toast('המכשיר נותק'); accountView(main); } catch (e) { SF.toast(e.message, true); }
                } }, 'ניתוק'))));
        } catch (e) {
            if (e.code === 'reader_login') return;
            SF.put(devices, h('li', { class: 'empty' }, e.message));
        }
    }

    async function exportData() {
        try {
            const d = await SF.api('account-export');
            const blob = new Blob([JSON.stringify(d.export, null, 2)], { type: 'application/json' });
            const a = h('a', { href: URL.createObjectURL(blob), download: 'sforno-' + new Date().toISOString().slice(0, 10) + '.json' });
            document.body.append(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 5000);
        } catch (e) { SF.toast(e.message, true); }
    }

    function deleteAccount() {
        const ok = h('input', { type: 'checkbox' });
        const go = h('button', { class: 'btn danger', type: 'button', disabled: true, onclick: async () => {
            try {
                await SF.api('account-delete', { body: { confirm: true } });
                dlg.close();
                await SF.sync.stop(true);
                me = Object.assign({}, me, { reader: null });
                SF.setReaderCsrf('');
                chip();
                SF.toast('החשבון נסגר');
                SF.go('#/account');
            } catch (e) { SF.toast(e.message, true); }
        } }, 'מחיקה');
        ok.addEventListener('change', () => { go.disabled = !ok.checked; });
        const dlg = SF.sheet('בקשת מחיקה', h('div', { class: 'account-q' },
            h('p', null, 'החשבון ייסגר מיד וכל המכשירים ינותקו. אחרי 30 יום — כל המידע האישי נמחק לצמיתות.'),
            h('p', { class: 'hint' }, 'הזמנות וחשבון באתר החנות אינם בשליטת ספורנו, ואינם נמחקים מכאן.'),
            h('label', { class: 'switch' }, ok, h('span', null, 'הבנתי — למחוק את החשבון'))), { action: go });
    }

    function logout() {
        const wipe = h('input', { type: 'checkbox', checked: true });
        const pend = SF.sync.info().pending;
        const go = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
            const w = wipe.checked;
            dlg.close();
            try { await SF.api('account-logout', { body: {} }); } catch (e) { /* גם בלי רשת — יוצאים במכשיר */ }
            await SF.sync.stop(w);
            me = Object.assign({}, me, { reader: null });
            SF.setReaderCsrf('');
            chip();
            SF.toast(w ? 'יצאת, והמידע האישי נמחק מהמכשיר' : 'יצאת מהחשבון');
            SF.go('#/account');
        } }, 'יציאה');
        const dlg = SF.sheet('יציאה מהחשבון', h('div', { class: 'account-q' },
            h('label', { class: 'switch' }, wipe, h('span', null, 'למחוק את המידע האישי מהמכשיר הזה')),
            h('p', { class: 'hint' }, 'מומלץ במכשיר משותף. המידע נשאר בחשבון, ויחזור בכניסה הבאה.'),
            pend ? h('p', { class: 'error' }, pend + ' שינויים עוד לא נשלחו לחשבון — במחיקה הם יאבדו.') : null), { action: go });
    }

    // ── המסכים ───────────────────────────────────────────────

    SF.route('#/account', async main => {
        if (me.reader) return accountView(main);
        loginView(main, 'login');
    }, { title: 'החשבון שלי' });

    SF.route('#/account/verify/:token', async (main, { token }) => {
        history.replaceState(null, '', '#/account');   // האסימון לא נשאר בהיסטוריה
        try {
            await SF.api('account-verify', { body: { token } });
            if (me.reader) me = await SF.api('account-me');
            SF.put(main, h('div', { class: 'fail' }, h('div', { class: 'ic', 'aria-hidden': 'true' }, '✅'), h('p', null, 'כתובת המייל אומתה.'),
                h('a', { class: 'btn primary', href: '#/account' }, 'לחשבון')));
        } catch (e) {
            SF.put(main, h('div', { class: 'fail', role: 'alert' }, h('div', { class: 'ic', 'aria-hidden': 'true' }, '⚠️'), h('p', null, e.message),
                h('a', { class: 'btn primary', href: '#/account' }, 'לחשבון')));
        }
    }, { title: 'אימות מייל', parent: '#/account' });

    SF.route('#/account/reset/:token', async (main, { token }) => {
        history.replaceState(null, '', '#/account');
        const err = h('p', { class: 'error', role: 'alert', hidden: true });
        const pass = h('input', { type: 'password', autocomplete: 'new-password', minlength: 8, dir: 'ltr', required: true });
        const form = h('form', { class: 'fields account-form', onsubmit: async e => {
            e.preventDefault();
            try {
                await afterLogin(await SF.api('account-reset', { body: { token, password: pass.value } }));
                SF.toast('הסיסמה הוחלפה. כל המכשירים האחרים נותקו');
            } catch (x) { err.textContent = x.message; err.hidden = false; }
        } },
            h('label', { class: 'f wide' }, h('span', null, 'סיסמה חדשה'), pass),
            h('p', { class: 'hint wide' }, 'לפחות 8 תווים. אחרי ההחלפה — כל המכשירים האחרים ינותקו.'),
            err, h('button', { class: 'btn primary wide', type: 'submit' }, 'החלפה'));
        SF.put(main, h('h2', { class: 'sec-title' }, 'איפוס סיסמה'), form);
        pass.focus();
    }, { title: 'איפוס סיסמה', parent: '#/account' });
})(window.SF);
