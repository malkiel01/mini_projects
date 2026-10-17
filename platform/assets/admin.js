// ממשק המנהל (#admin): משתמשים והרשאות, קטגוריות ושיוך אפליקציות.
// נטען לפני app.js ומשתמש בעזרים שלו דרך window.Platform בזמן ריצה.

window.PlatformAdmin = (() => {
    let root = null;
    let state = null;
    let tab = 'users';
    let filter = 'all';     // סינון לפי סטטוס
    let query = '';         // חיפוש בשם / שם משתמש
    let sort = 'recent';    // recent | login | name
    let openUser = null;    // מזהה המשתמש שהגיליון שלו פתוח
    let sheet = null;

    const P = () => window.Platform;
    const el = (...a) => P().el(...a);

    async function show(container) {
        root = container;
        await reload();
    }

    async function reload() {
        state = await P().api('admin_state');
        P().setPending(pendingCount());
        render();
        if (openUser !== null) {
            const u = state.users.find(x => x.id === openUser);
            u ? fillSheet(u) : closeSheet();
        }
    }

    // פעולה, ואחריה טעינה מחדש. התוצאה — בהודעה קופצת; שגיאה לא מפילה את המסך.
    async function act(action, body, message = '') {
        try {
            await P().api(action, body);
            if (message) P().toast(message);
        } catch (err) {
            P().toast('⚠️ ' + err.message);
        }
        await reload();
    }

    function button(text, onClick, cls = '') {
        const b = el('button', { type: 'button', className: `btn ${cls}`, textContent: text });
        b.addEventListener('click', onClick);
        return b;
    }

    function render() {
        const n = pendingCount();
        const tabs = el('div', { className: 'admin-tabs', role: 'tablist' }, [
            ['users', '👥', 'משתמשים', n],
            ['usage', '📊', 'שימוש', 0],
            ['catalog', '🗂️', 'אפליקציות', 0],
            ['sso', '🔗', 'חיבורים', 0],
            ['probe', '🩺', 'שרת', 0],
        ].map(([id, icon, label, count]) => {
            const b = button('', () => { tab = id; render(); }, tab === id ? 'active' : '');
            // בטלפון האייקון מוסתר — ארבע לשוניות בשורה אחת.
            b.append(el('span', { className: 'tab-ic', textContent: icon, ariaHidden: 'true' }), el('span', { className: 'one', textContent: label }));
            b.role = 'tab';
            b.ariaSelected = String(tab === id);
            if (count) b.append(el('span', { className: 'chip bad', textContent: String(count) }));
            return b;
        }));
        const body = tab === 'users' ? renderUsers() : tab === 'usage' ? renderUsage()
            : tab === 'catalog' ? renderCatalog() : tab === 'sso' ? renderSso() : renderProbe();
        root.replaceChildren(el('div', { className: 'admin' }, [tabs, body]));
    }

    const pendingCount = () => state.users.filter(u => u.status === 'pending').length;

    // ---------- זמנים ----------

    function relTime(iso) {
        if (!iso) return '';
        const sec = (Date.now() - new Date(iso).getTime()) / 1000;
        if (sec < 60) return 'עכשיו';
        if (sec < 3600) return `לפני ${Math.round(sec / 60)} דק׳`;
        if (sec < 86400) return `לפני ${Math.round(sec / 3600)} שע׳`;
        const days = Math.floor(sec / 86400);
        if (days === 1) return 'אתמול';
        if (days < 30) return `לפני ${days} ימים`;
        return fullDate(iso);
    }
    const fullDate = (iso) => iso ? new Date(iso).toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: 'numeric' }) : '—';
    const fullTime = (iso) => iso ? new Date(iso).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

    // ---------- משתמשים ----------

    const STATUS = { pending: ['ממתין לאישור', 'warn'], active: ['פעיל', 'ok'], blocked: ['חסום', 'bad'] };
    const FILTERS = [['all', 'הכול'], ['pending', 'ממתינים'], ['active', 'פעילים'], ['blocked', 'חסומים']];
    const SORTS = [['recent', 'נרשמו לאחרונה'], ['login', 'כניסה אחרונה'], ['name', 'לפי שם']];

    function visibleUsers() {
        const q = query.trim().toLowerCase();
        const list = state.users.filter(u =>
            (filter === 'all' || u.status === filter)
            && (!q || u.display_name.toLowerCase().includes(q) || u.username.toLowerCase().includes(q)));
        const by = {
            recent: (a, b) => (b.created_at || '').localeCompare(a.created_at || ''),
            login: (a, b) => (b.last_login_at || '').localeCompare(a.last_login_at || ''),
            name: (a, b) => a.display_name.localeCompare(b.display_name, 'he'),
        }[sort];
        // ממתינים תמיד למעלה — הם מה שדורש פעולה.
        return list.sort((a, b) => ((b.status === 'pending') - (a.status === 'pending')) || by(a, b));
    }

    function renderUsers() {
        const count = (f) => f === 'all' ? state.users.length : state.users.filter(u => u.status === f).length;
        const seg = el('div', { className: 'seg', role: 'radiogroup', ariaLabel: 'סינון לפי מצב' }, FILTERS.map(([id, label]) => {
            const input = el('input', { type: 'radio', name: 'ufilter', value: id, checked: filter === id });
            input.addEventListener('change', () => { filter = id; drawList(); });
            const n = count(id);
            return el('label', {}, [input, el('span', {}, [label, ...(n ? [el('small', { textContent: String(n) })] : [])])]);
        }));

        const search = el('input', { type: 'search', value: query, placeholder: 'חיפוש לפי שם או משתמש…', ariaLabel: 'חיפוש משתמש' });
        search.addEventListener('input', () => { query = search.value; drawList(); });
        const sortSel = el('select', { ariaLabel: 'מיון' }, SORTS.map(([v, t]) => el('option', { value: v, textContent: t })));
        sortSel.value = sort;
        sortSel.addEventListener('change', () => { sort = sortSel.value; drawList(); });

        const list = el('ul', { className: 'list' });
        function drawList() {
            const users = visibleUsers();
            list.replaceChildren(...(users.length ? users.map(userRow)
                : [el('li', { className: 'empty', textContent: query ? 'אין משתמש שמתאים לחיפוש' : 'אין משתמשים במצב הזה' })]));
        }
        drawList();

        return el('div', {}, [notifyCard(), seg, el('div', { className: 'toolbar' }, [search, sortSel]), list]);
    }

    function statusChips(u) {
        const [text, cls] = STATUS[u.status];
        const out = [el('span', { className: `chip ${cls}`, textContent: text })];
        if (u.role === 'admin') out.push(el('span', { className: 'chip brand', textContent: 'מנהל' }));
        if (u.must_change) out.push(el('span', { className: 'chip', textContent: '🔑 סיסמה זמנית' }));
        return out;
    }

    function userRow(u) {
        const sub = [el('bdi', { textContent: u.username }), ` · נרשם ${relTime(u.created_at)}`];
        if (u.status !== 'pending') sub.push(` · ${u.last_login_at ? 'נכנס ' + relTime(u.last_login_at) : 'עוד לא נכנס'}`);

        const main = el('button', { type: 'button', className: 'u-main', ariaHasPopup: 'dialog' }, [
            P().avatar(u),
            el('span', { className: 'u-tx' }, [
                el('span', { className: 'u-l1' }, [el('b', { textContent: u.display_name + (u.self ? ' (את/ה)' : '') }), ...statusChips(u)]),
                el('small', {}, sub),
            ]),
        ]);
        main.addEventListener('click', () => openSheet(u));

        const tail = [];
        if (u.status === 'pending') {
            const ok = el('button', { type: 'button', className: 'icon-btn ok-btn', textContent: '✓', ariaLabel: `אישור ${u.display_name}`, title: 'אישור' });
            ok.addEventListener('click', () => approve(u));
            const no = el('button', { type: 'button', className: 'icon-btn no-btn', textContent: '✕', ariaLabel: `דחיית ${u.display_name}`, title: 'דחייה' });
            no.addEventListener('click', () => reject(u));
            tail.push(ok, no);
        } else {
            tail.push(el('span', { className: 'app-go', textContent: '‹', ariaHidden: 'true' }));
        }
        return el('li', { className: 'urow' }, [main, ...tail]);
    }

    // אישור פותח מיד את הגיליון — הצעד הבא הוא לבחור מה המשתמש יראה.
    async function approve(u) {
        openUser = u.id;
        await act('admin_user_update', { id: u.id, status: 'active' }, `✓ ${u.display_name} אושר/ה — בחרו מה יוצג לו/ה`);
        const fresh = state.users.find(x => x.id === u.id);
        if (fresh && !sheet) openSheet(fresh);
    }
    function reject(u) {
        if (confirm(`לדחות ולמחוק את הבקשה של ${u.display_name}?`)) act('admin_user_delete', { id: u.id }, 'הבקשה נדחתה');
    }

    // ---------- התראה על הרשמה חדשה (Web Push) ----------
    // המנוי שייך ל-service worker של המשפחה (family-sw.js, בשורש). באייפון Push עובד רק
    // מאפליקציה שהותקנה למסך הבית (iOS 16.4 ומעלה) — אז קודם מסבירים איך להתקין.

    const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const isIOS = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

    function deviceName() {
        const ua = navigator.userAgent;
        const os = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) || isIOS() ? 'iPad' : /Android/.test(ua) ? 'Android'
            : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : 'מכשיר';
        const br = /Edg\//.test(ua) ? 'Edge' : /SamsungBrowser/.test(ua) ? 'Samsung' : /Firefox|FxiOS/.test(ua) ? 'Firefox'
            : /Chrome|CriOS/.test(ua) ? 'Chrome' : /Safari/.test(ua) ? 'Safari' : '';
        return br ? `${os} · ${br}` : os;
    }

    function b64ToBytes(s) {
        const raw = atob((s + '='.repeat((4 - s.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, c => c.charCodeAt(0));
    }

    async function currentSub() {
        const reg = await navigator.serviceWorker.ready;
        return { reg, sub: await reg.pushManager.getSubscription() };
    }

    function notifyCard() {
        const tx = el('small', { textContent: 'בודק…' });
        const btns = el('div', { className: 'notify-btns' });
        const card = el('div', { className: 'notify' }, [
            el('span', { className: 'notify-ic', textContent: '🔔', ariaHidden: 'true' }),
            el('div', { className: 'notify-tx' }, [el('b', { textContent: 'התראה על כל הרשמה חדשה' }), tx]),
            btns,
        ]);
        const b = (text, onClick, cls = '') => { const x = button(text, onClick, `sm ${cls}`); return x; };

        async function refresh() {
            btns.replaceChildren();
            if (!pushSupported()) {
                tx.textContent = isIOS() && !standalone()
                    ? 'באייפון: התקינו קודם למסך הבית (שיתוף ← "הוספה למסך הבית") ופתחו משם'
                    : 'הדפדפן הזה לא תומך בהתראות';
                return;
            }
            if (Notification.permission === 'denied') {
                tx.textContent = 'ההתראות חסומות בדפדפן — מאפשרים אותן בהגדרות האתר';
                return;
            }
            let state;
            try {
                const [{ sub }, server] = await Promise.all([currentSub(), P().api('admin_push')]);
                state = { sub, server };
            } catch { tx.textContent = 'לא ניתן לבדוק כרגע'; return; }
            const mine = state.sub && state.server.devices.some(d => d.endpoint === state.sub.endpoint);
            const others = state.server.devices.length - (mine ? 1 : 0);
            const more = others > 0 ? ` · ועוד ${others} ${others === 1 ? 'מכשיר' : 'מכשירים'}` : '';
            if (mine && Notification.permission === 'granted') {
                card.classList.add('on');
                tx.textContent = `פעיל במכשיר הזה${more}`;
                btns.append(b('בדיקה', test), b('כיבוי', disable));
            } else {
                card.classList.remove('on');
                tx.textContent = `כבוי במכשיר הזה${more}`;
                btns.append(b('הפעלה', enable, 'primary'));
            }
            card._key = state.server.key;
        }

        async function enable() {
            try {
                if (await Notification.requestPermission() !== 'granted') { P().toast('לא התקבל אישור להתראות'); return refresh(); }
                const { reg, sub: old } = await currentSub();
                const key = card._key || (await P().api('admin_push')).key;
                const sub = old || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
                await P().api('admin_push_subscribe', { subscription: sub.toJSON(), device: deviceName() });
                P().toast('✓ ההתראות הופעלו במכשיר הזה');
            } catch (err) {
                P().toast('⚠️ ' + (err.message || 'ההפעלה נכשלה'));
            }
            refresh();
        }

        async function disable() {
            try {
                const { sub } = await currentSub();
                if (sub) {
                    await P().api('admin_push_unsubscribe', { endpoint: sub.endpoint });
                    await sub.unsubscribe();
                }
                P().toast('ההתראות כובו במכשיר הזה');
            } catch (err) { P().toast('⚠️ ' + err.message); }
            refresh();
        }

        async function test() {
            try {
                const r = await P().api('admin_push_test', {});
                P().toast(r.sent ? `✓ נשלחה בדיקה ל-${r.sent} ${r.sent === 1 ? 'מכשיר' : 'מכשירים'}` : '⚠️ השליחה נכשלה — נסו לכבות ולהפעיל שוב');
            } catch (err) { P().toast('⚠️ ' + err.message); }
            refresh();
        }

        refresh();
        return card;
    }

    // ---------- גיליון משתמש ----------

    function openSheet(u) {
        closeSheet();
        openUser = u.id;
        sheet = el('dialog', { className: 'user-sheet' });
        sheet.addEventListener('click', (e) => { if (e.target === sheet) closeSheet(); });
        sheet.addEventListener('close', () => { sheet?.remove(); sheet = null; openUser = null; });
        document.body.append(sheet);
        fillSheet(u);
        sheet.showModal();
    }
    function closeSheet() { if (sheet?.open) sheet.close(); }

    function fillSheet(u) {
        if (!sheet) return;
        const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
        close.addEventListener('click', closeSheet);
        const [statusText] = STATUS[u.status];

        const cells = el('dl', { className: 'cells' }, [
            ['מצב', statusText], ['תפקיד', u.role === 'admin' ? 'מנהל' : 'משתמש'],
            ['נרשם', fullDate(u.created_at)], ['כניסה אחרונה', u.last_login_at ? fullTime(u.last_login_at) : 'עוד לא נכנס'],
        ].map(([k, v]) => el('div', {}, [el('dt', { textContent: k }), el('dd', { textContent: v })])));

        const body = [cells];
        if (u.must_change) body.push(el('p', { className: 'note', textContent: '🔑 קיבל/ה סיסמה זמנית — יתבקש/תתבקש לבחור סיסמה חדשה בכניסה הבאה.' }));

        if (u.self) {
            body.push(el('p', { className: 'muted small', textContent: 'זה החשבון שלך. את הסיסמה מחליפים מהתפריט האישי (האווטאר למעלה).' }));
        } else {
            body.push(el('div', { className: 'sheet-sec', textContent: 'פעולות' }), el('div', { className: 'tiles' }, actionTiles(u)));
            if (u.status !== 'pending') body.push(passwordBox(u));
            if (u.status !== 'pending') body.push(el('div', { className: 'sheet-sec', textContent: 'שימוש באפליקציות · 30 יום' }), userUsage(u));
            if (u.sso?.length) body.push(el('div', { className: 'sheet-sec', textContent: 'כניסה מאוחדת — אישר/ה ל' }), userSso(u));
            if (u.status === 'active' && u.role !== 'admin') {
                body.push(el('div', { className: 'sheet-sec', textContent: 'מה הוא/היא רואה' }), grantsEditor(u));
            } else if (u.role === 'admin') {
                body.push(el('p', { className: 'muted small', textContent: 'מנהל רואה את כל האפליקציות — אין צורך בהרשאות.' }));
            }
        }

        if (u.devices?.length) body.push(el('div', { className: 'sheet-sec', textContent: 'מכשירים — מה מותקן' }), userDevices(u));

        sheet.replaceChildren(el('div', { className: 'sheet' }, [
            el('div', { className: 'sheet-head' }, [
                P().avatar(u, 'lg'),
                el('div', { className: 'tx' }, [
                    el('h2', { textContent: u.display_name }),
                    el('small', {}, [el('bdi', { textContent: u.username })]),
                ]),
                close,
            ]),
            el('div', { className: 'sheet-body' }, body),
        ]));
    }

    function tile(icon, text, onClick, cls = '') {
        const t = el('button', { type: 'button', className: `tile ${cls}` }, [
            el('span', { textContent: icon, ariaHidden: 'true' }), el('span', { className: 'one', textContent: text }),
        ]);
        t.addEventListener('click', onClick);
        return t;
    }

    function actionTiles(u) {
        const name = u.display_name;
        if (u.status === 'pending') {
            return [tile('✓', 'אישור', () => approve(u), 'primary'), tile('✕', 'דחייה', () => reject(u), 'danger')];
        }
        if (u.status === 'blocked') {
            return [
                tile('↺', 'ביטול חסימה', () => act('admin_user_update', { id: u.id, status: 'active' }, 'החסימה בוטלה')),
                tile('🗑️', 'מחיקה לצמיתות', () => confirm(`למחוק את ${name} לצמיתות?`)
                    && act('admin_user_delete', { id: u.id }, 'נמחק/ה'), 'danger'),
            ];
        }
        return [
            u.role === 'admin'
                ? tile('⬇', 'הסרת ניהול', () => act('admin_user_update', { id: u.id, role: 'user' }, 'כבר לא מנהל/ת'))
                : tile('⭐', 'הפיכה למנהל', () => confirm(`מנהל רואה ועורך הכול. להפוך את ${name} למנהל?`)
                    && act('admin_user_update', { id: u.id, role: 'admin' }, 'הפך/ה למנהל')),
            tile('⛔', 'חסימה', () => confirm(`לחסום את ${name}? הוא/היא ינותק/תנותק מיד מכל המכשירים.`)
                && act('admin_user_update', { id: u.id, status: 'blocked' }, 'נחסם/ה'), 'danger'),
        ];
    }

    // סיסמה זמנית: מוצעת אחת אקראית, גלויה כדי שאפשר יהיה להעתיק ולמסור.
    function tempPassword() {
        const abc = 'abcdefghjkmnpqrstuvwxyz23456789';
        const bytes = crypto.getRandomValues(new Uint8Array(10));
        return [...bytes].map(b => abc[b % abc.length]).join('');
    }

    function passwordBox(u) {
        const input = el('input', { type: 'text', value: tempPassword(), dir: 'ltr', ariaLabel: 'סיסמה זמנית', autocomplete: 'off', spellcheck: false });
        const copy = el('button', { type: 'button', className: 'icon-btn', textContent: '📋', ariaLabel: 'העתקת הסיסמה', title: 'העתקה' });
        copy.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(input.value); P().toast('הסיסמה הועתקה'); }
            catch { window.prompt('העתיקו:', input.value); }
        });
        const set = button('קביעה', () => {
            if (!confirm(`לקבוע ל-${u.display_name} את הסיסמה הזמנית ${input.value}?\nהוא/היא ינותק/תנותק מכל המכשירים.`)) return;
            act('admin_user_password', { id: u.id, password: input.value }, '✓ הסיסמה הזמנית נקבעה — מסרו אותה למשתמש');
        }, 'primary');
        return el('div', { className: 'pw-box' }, [
            el('div', { className: 'sheet-sec', textContent: 'איפוס סיסמה' }),
            el('p', { className: 'muted small', textContent: 'קובעים סיסמה זמנית ומוסרים אותה. בכניסה הבאה המשתמש יחויב לבחור סיסמה משלו.' }),
            el('div', { className: 'toolbar' }, [input, copy, set]),
        ]);
    }

    // עורך ההרשאות: לכל קטגוריה "הכול", ולכל אפליקציה — לפי הקטגוריה / מותר / חסום.
    function grantsEditor(u) {
        const cats = new Set(Object.keys(u.grants.categories).filter(c => u.grants.categories[c]));
        const apps = { ...u.grants.apps };

        const sections = state.categories.map(cat => {
            const catApps = state.apps.filter(a => a.category === cat.id && !a.hidden);
            const box = el('input', { type: 'checkbox', checked: cats.has(cat.id) });
            box.addEventListener('change', () => { box.checked ? cats.add(cat.id) : cats.delete(cat.id); refresh(); });

            const rows = catApps.map(app => {
                const sel = el('select', {}, [
                    ['', app.restricted ? '🔒 רק באישור ישיר' : 'לפי הקטגוריה'], ['allow', '✓ מותר'], ['deny', '✕ חסום'],
                ].map(([v, t]) => el('option', { value: v, textContent: t })));
                sel.value = app.id in apps ? (apps[app.id] ? 'allow' : 'deny') : '';
                const result = el('span', { className: 'small' });
                sel.addEventListener('change', () => {
                    if (sel.value === '') delete apps[app.id]; else apps[app.id] = sel.value === 'allow';
                    refresh();
                });
                return { app, sel, result, node: el('div', { className: 'grant-app' }, [
                    el('span', { textContent: `${app.icon} ${app.name}` }), sel, result,
                ]) };
            });

            function refresh() {
                for (const r of rows) {
                    const seen = r.app.id in apps ? apps[r.app.id] : (!r.app.restricted && cats.has(cat.id));
                    r.result.textContent = seen ? '👁️ רואה' : '— לא רואה';
                    r.result.className = `small ${seen ? 'yes' : 'no'}`;
                }
            }
            refresh();

            return el('fieldset', { className: 'grant-cat' }, [
                el('legend', {}, [el('label', {}, [box, ` ${cat.icon} ${cat.name} — כל הקטגוריה`])]),
                ...(rows.length ? rows.map(r => r.node) : [el('div', { className: 'muted small', textContent: 'אין בה אפליקציות' })]),
            ]);
        });

        const save = button('💾 שמירת ההרשאות', () => act('admin_grants_set',
            { id: u.id, categories: [...cats], apps }, `✓ ההרשאות של ${u.display_name} נשמרו`), 'primary save-grants');
        return el('div', { className: 'grants' }, [
            el('p', { className: 'muted small', textContent:
                'קטגוריה מסומנת כוללת גם אפליקציות שיתווספו אליה בעתיד. הרשאה לאפליקציה גוברת על הקטגוריה.' }),
            ...sections, save,
        ]);
    }

    // ---------- שימוש: כניסות לאפליקציות ----------
    // הנתונים נרשמים בשער (platform/lib/gate.php) — רק לאפליקציות עם "gate": true.

    let usageDays = 30;
    const appInfo = (id) => state.apps.find(a => a.id === id) || { id, name: id, icon: '🧩' };
    const plural = (n, one, many) => n === 1 ? one : `${n.toLocaleString('he-IL')} ${many}`;
    const usageApi = (extra = '') => P().api(`admin_usage&days=${usageDays}${extra}`);

    function appIc(id) {
        const a = appInfo(id);
        const ic = el('span', { className: 'app-ic sm', textContent: a.icon, ariaHidden: 'true' });
        ic.style.setProperty('--h', P().hue(a.id));
        return ic;
    }

    function cellsOf(pairs) {
        return el('dl', { className: 'cells' }, pairs.map(([k, v]) =>
            el('div', {}, [el('dt', { textContent: k }), el('dd', { textContent: typeof v === 'number' ? v.toLocaleString('he-IL') : v })])));
    }

    // גרף עמודות יומי: סדרה אחת בצבע המותג, ציר זמן משמאל לימין (כמקובל בגרפים),
    // עמודות דקות עם קצה מעוגל, רשת חלשה, ותווית ריחוף לכל עמודה.
    function barChart(daily) {
        const NS = 'http://www.w3.org/2000/svg';
        const svgEl = (tag, attrs) => { const n = document.createElementNS(NS, tag); for (const k in attrs) n.setAttribute(k, attrs[k]); return n; };
        // בטלפון — רוחב קטן יותר, כדי שהתוויות לא יתכווצו עם ה-SVG.
        const W = matchMedia('(max-width: 600px)').matches ? 360 : 640, H = 170, padT = 10, padB = 22, padL = 30, padR = 6;
        const max = Math.max(1, ...daily.map(d => d.n));
        const step = Math.max(1, Math.ceil(max / 3));
        const top = step * Math.ceil(max / step);
        const plotW = W - padL - padR, plotH = H - padT - padB;
        const slot = plotW / daily.length, gap = Math.min(2, slot * 0.25), bw = Math.max(1, slot - gap);
        const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, class: 'chart-svg', role: 'img',
            'aria-label': `כניסות ליום, ${daily.length} ימים, שיא ${max}` });

        for (let v = 0; v <= top; v += step) {
            const y = padT + plotH - (v / top) * plotH;
            svg.append(svgEl('line', { x1: padL, x2: W - padR, y1: y, y2: y, class: v ? 'grid' : 'base' }));
            const t = svgEl('text', { x: padL - 6, y: y + 3.5, class: 'tick', 'text-anchor': 'end' });
            t.textContent = v;
            svg.append(t);
        }
        const fmt = (d) => { const [, m, day] = d.split('-'); return `${+day}.${+m}`; };
        const labelAt = new Set([0, Math.floor((daily.length - 1) / 2), daily.length - 1]);
        const tip = el('div', { className: 'chart-tip', hidden: true });

        daily.forEach((d, i) => {
            const x = padL + i * slot + gap / 2;
            const h = (d.n / top) * plotH;
            const y = padT + plotH - h;
            if (d.n > 0) {
                const r = Math.min(4, bw / 2, h);
                svg.append(svgEl('path', { class: 'bar', d:
                    `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + bw - r} Q${x + bw},${y} ${x + bw},${y + r} V${y + h} Z` }));
            }
            if (labelAt.has(i)) {
                const t = svgEl('text', { x: x + bw / 2, y: H - 6, class: 'tick', 'text-anchor': i === 0 ? 'start' : i === daily.length - 1 ? 'end' : 'middle' });
                t.textContent = fmt(d.d);
                svg.append(t);
            }
            // אזור ריחוף בגובה כל הגרף — גדול מהעמודה, קל לפגוע בו גם באצבע.
            const hit = svgEl('rect', { x: padL + i * slot, y: padT, width: slot, height: plotH, class: 'hit' });
            const show = () => {
                tip.textContent = `${fmt(d.d)} · ${plural(d.n, 'כניסה אחת', 'כניסות')}`;
                tip.hidden = false;
                tip.style.left = `${((padL + (i + 0.5) * slot) / W) * 100}%`;
            };
            hit.addEventListener('mouseenter', show);
            hit.addEventListener('click', show);
            svg.append(hit);
        });
        svg.addEventListener('mouseleave', () => { tip.hidden = true; });
        return el('div', { className: 'chart', dir: 'ltr' }, [svg, tip]);
    }

    function daysSeg(onChange) {
        return el('div', { className: 'seg days', role: 'radiogroup', ariaLabel: 'טווח' }, [[7, '7 ימים'], [30, '30 יום'], [90, '90 יום']].map(([d, label]) => {
            const input = el('input', { type: 'radio', name: 'udays', value: d, checked: usageDays === d });
            input.addEventListener('change', () => { usageDays = d; onChange(); });
            return el('label', {}, [input, el('span', { textContent: label })]);
        }));
    }

    function renderUsage() {
        const box = el('div', {}, [el('p', { className: 'status', textContent: 'טוען…' })]);
        const load = async () => {
            let data;
            try { data = await usageApi(); } catch (err) { box.replaceChildren(el('p', { className: 'status error', textContent: err.message })); return; }
            const appRows = data.apps.map(a => {
                const info = appInfo(a.app);
                const sub = [plural(a.n, 'כניסה אחת', 'כניסות'), plural(a.users, 'משתמש אחד', 'משתמשים')];
                if (a.guests) sub.push(`${a.guests} מאורחים`);
                sub.push(`אחרונה ${relTime(a.last)}`);
                const row = el('button', { type: 'button', className: 'u-main' }, [
                    appIc(a.app),
                    el('span', { className: 'u-tx' }, [
                        el('span', { className: 'u-l1' }, [el('b', { textContent: info.name })]),
                        el('small', { textContent: sub.join(' · ') }),
                        el('span', { className: 'track' }, [Object.assign(el('span'), { style: `width:${(a.n / data.apps[0].n) * 100}%` })]),
                    ]),
                ]);
                row.addEventListener('click', () => openAppUsage(a.app));
                return el('li', { className: 'urow' }, [row, el('span', { className: 'app-go', textContent: '‹', ariaHidden: 'true' })]);
            });
            const userRows = data.users.slice(0, 10).map(u => el('li', { className: 'urow' }, [el('div', { className: 'u-main static' }, [
                P().avatar({ display_name: u.name, username: u.username }),
                el('span', { className: 'u-tx' }, [el('b', { textContent: u.name }), el('small', { textContent: `${plural(u.n, 'כניסה אחת', 'כניסות')} · אחרונה ${relTime(u.last)}` })]),
            ])]));
            const cells = [['כניסות', data.total], ['משתמשים ייחודיים', data.unique]];
            if (data.guests) cells.push(['כניסות של אורחים', data.guests]);
            box.replaceChildren(
                daysSeg(load),
                el('p', { className: 'muted small hint-line', textContent: 'כל פתיחה של אפליקציה נספרת פעם אחת לכל 30 דקות. אורח = דף ציבורי בלי כניסה.' }),
                cellsOf(cells),
                el('h3', { textContent: 'כניסות ליום' }),
                barChart(data.daily),
                el('h3', { textContent: 'לפי אפליקציה' }),
                el('ul', { className: 'list' }, appRows.length ? appRows : [el('li', { className: 'empty', textContent: 'עוד אין כניסות בטווח הזה' })]),
                ...(userRows.length ? [el('h3', { textContent: 'המשתמשים הפעילים' }), el('ul', { className: 'list' }, userRows)] : []),
            );
        };
        load();
        return box;
    }

    async function openAppUsage(appId) {
        const info = appInfo(appId);
        const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
        const body = el('div', { className: 'sheet-body' }, [el('p', { className: 'status', textContent: 'טוען…' })]);
        const dlg = el('dialog', { className: 'user-sheet' }, [el('div', { className: 'sheet' }, [
            el('div', { className: 'sheet-head' }, [appIc(appId), el('div', { className: 'tx' }, [
                el('h2', { textContent: info.name }), el('small', { textContent: `שימוש · ${usageDays} ימים אחרונים` }),
            ]), close]),
            body,
        ])]);
        close.addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
        dlg.addEventListener('close', () => dlg.remove());
        document.body.append(dlg);
        dlg.showModal();

        let data;
        try { data = await usageApi(`&app=${encodeURIComponent(appId)}`); } catch (err) { body.replaceChildren(el('p', { className: 'status error', textContent: err.message })); return; }
        const cells = [['כניסות', data.total], ['משתמשים', data.unique]];
        if (data.guests) cells.push(['אורחים', data.guests]);
        const who = data.users.map(u => el('li', { className: 'urow' }, [el('div', { className: 'u-main static' }, [
            P().avatar({ display_name: u.name, username: u.username }),
            el('span', { className: 'u-tx' }, [el('b', { textContent: u.name }), el('small', { textContent: `${plural(u.n, 'כניסה אחת', 'כניסות')} · אחרונה ${relTime(u.last)}` })]),
        ])]));
        const recent = data.recent.map(r => el('li', { className: 'vrow' }, [
            el('span', { className: 'one', textContent: r.name ?? 'אורח' }),
            el('span', { className: 'muted one dev', dir: 'ltr', textContent: r.device }),
            el('time', { className: 'muted', dateTime: r.at, textContent: fullTime(r.at), title: relTime(r.at) }),
        ]));
        body.replaceChildren(
            cellsOf(cells),
            el('div', { className: 'sheet-sec', textContent: 'כניסות ליום' }), barChart(data.daily),
            ...(who.length ? [el('div', { className: 'sheet-sec', textContent: 'מי נכנס' }), el('ul', { className: 'list' }, who)] : []),
            el('div', { className: 'sheet-sec', textContent: 'כניסות אחרונות' }),
            el('ul', { className: 'list' }, recent.length ? recent : [el('li', { className: 'empty', textContent: 'אין כניסות בטווח הזה' })]),
        );
    }

    // בגיליון המשתמש: לאילו אפליקציות נכנס ב-30 הימים האחרונים.
    function userUsage(u) {
        const box = el('div', {}, [el('p', { className: 'muted small', textContent: 'טוען…' })]);
        P().api(`admin_usage&days=30&user=${u.id}`).then(data => {
            if (!data.apps.length) { box.replaceChildren(el('p', { className: 'muted small', textContent: 'לא נכנס/ה לאף אפליקציה בתקופה הזו.' })); return; }
            box.replaceChildren(el('ul', { className: 'list' }, data.apps.map(a => el('li', { className: 'urow' }, [el('div', { className: 'u-main static' }, [
                appIc(a.app),
                el('span', { className: 'u-tx' }, [el('b', { textContent: appInfo(a.app).name }),
                    el('small', { textContent: `${plural(a.n, 'כניסה אחת', 'כניסות')} · אחרונה ${relTime(a.last)}` })]),
            ])]))));
        }).catch(() => box.replaceChildren(el('p', { className: 'muted small', textContent: 'לא ניתן לטעון את השימוש.' })));
        return box;
    }

    // ---------- כניסה מאוחדת: האפליקציות שמזהות משתמשים דרך הפלטפורמה ----------
    // הצד של הספק — platform/sso.php. המדריך לאפליקציות — platform/docs/sso.md.

    // מה מותקן בכל מכשיר של המשתמש (lib/installs.php). "_platform" — הכלים הדיגיטליים עצמם.
    const METHOD = { pwa: 'אפליקציה', apk: 'APK', store: 'Google Play', manual: 'סימון ידני', auto: 'זוהה לבד' };
    function userDevices(u) {
        return el('ul', { className: 'list' }, u.devices.map(d => el('li', { className: 'dev-row' }, [
            el('div', { className: 'dev-head' }, [el('b', { textContent: `📱 ${d.device}` }),
                el('small', { className: 'muted', textContent: `עודכן ${relTime(d.updated_at)}` })]),
            el('div', { className: 'chips-row' }, d.apps.map(a => {
                const info = a.app_id === '_platform' ? { icon: '🧩', name: 'כלים דיגיטליים' } : appInfo(a.app_id);
                return el('span', { className: 'chip ok', title: METHOD[a.method] || a.method, textContent: `${info.icon} ${info.name}` });
            })),
        ])));
    }

    function userSso(u) {
        return el('ul', { className: 'list' }, u.sso.map(c => {
            const x = el('button', { type: 'button', className: 'btn sm danger', textContent: 'ניתוק' });
            x.addEventListener('click', () => confirm(`לנתק את ${u.display_name} מ-${c.name}? בכניסה הבאה יתבקש/תתבקש לאשר שוב.`)
                && act('admin_sso_revoke', { user_id: u.id, client_id: c.client_id }, 'נותק/ה'));
            return el('li', { className: 'urow' }, [el('div', { className: 'u-main static' }, [
                el('span', { className: 'app-ic sm', textContent: '🔗', ariaHidden: 'true' }),
                el('span', { className: 'u-tx' }, [el('b', { textContent: c.name }),
                    el('small', { textContent: `אושר ${relTime(c.granted_at)}${c.last_used_at ? ' · נכנס ' + relTime(c.last_used_at) : ''}` })]),
            ]), x]);
        }));
    }

    // אפליקציה נכנסת לכאן לבד, כשה-app.json שלה מצהיר "sso" (הצ'אט שלה מוסיף שורה אחת).
    // אין קודים ואין סודות להעתיק — המנהל רק מדליק או מכבה, בלחיצה.
    function renderSso() {
        const rows = state.sso.map(c => {
            const sub = [c.users === 1 ? 'משתמש אחד' : `${c.users} משתמשים`];
            if (c.last_used) sub.push(`בשימוש ${relTime(c.last_used)}`);
            const sw = el('input', { type: 'checkbox', checked: !c.disabled, ariaLabel: `כניסה מאוחדת ל${c.name}` });
            sw.addEventListener('change', () => {
                if (!sw.checked && !confirm(`לכבות את הכניסה המאוחדת ל${c.name}? מי שנכנס דרכה יצטרך להיכנס בסיסמה של האפליקציה עד שתדליק שוב.`)) {
                    sw.checked = true; return;
                }
                act('admin_sso_toggle', { id: c.id, disabled: !sw.checked }, sw.checked ? `${c.name}: הכניסה המאוחדת פעילה` : `${c.name}: הכניסה המאוחדת כבויה`);
            });
            const main = el('button', { type: 'button', className: 'u-main' }, [
                el('span', { className: 'app-ic sm', textContent: c.icon, ariaHidden: 'true' }),
                el('span', { className: 'u-tx' }, [
                    el('span', { className: 'u-l1' }, [el('b', { textContent: c.name })]),
                    el('small', { textContent: sub.join(' · ') }),
                ]),
            ]);
            main.addEventListener('click', () => openSsoClient(c));
            return el('li', { className: 'urow' }, [main, el('label', { className: 'toggle' }, [sw, el('span', { ariaHidden: 'true' })])]);
        });
        return el('div', {}, [
            el('p', { className: 'muted small hint-line', textContent:
                '"כניסה עם חשבון כלים דיגיטליים" — האפליקציות כאן מזהות משתמש דרך הפלטפורמה. המתג מדליק או מכבה. ' +
                'אפליקציה חדשה מופיעה כאן לבד, ברגע שהצ\'אט שלה מחבר אותה — בלי קודים ובלי סודות.' }),
            el('ul', { className: 'list' }, rows.length ? rows
                : [el('li', { className: 'empty', textContent: 'עוד אף אפליקציה לא התחברה. כשאפליקציה תתחבר — היא תופיע כאן.' })]),
        ]);
    }

    // מי אישר את האפליקציה — וניתוק.
    function openSsoClient(c) {
        const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
        const users = state.users.filter(u => u.sso?.some(s => s.client_id === c.id));
        const list = users.length
            ? el('ul', { className: 'list' }, users.map(u => {
                const s = u.sso.find(x => x.client_id === c.id);
                const x = el('button', { type: 'button', className: 'btn sm danger', textContent: 'ניתוק' });
                x.addEventListener('click', async () => {
                    if (!confirm(`לנתק את ${u.display_name}? בכניסה הבאה יתבקש/תתבקש לאשר שוב.`)) return;
                    dlg.close();
                    await act('admin_sso_revoke', { user_id: u.id, client_id: c.id }, 'נותק/ה');
                });
                return el('li', { className: 'urow' }, [el('div', { className: 'u-main static' }, [P().avatar(u),
                    el('span', { className: 'u-tx' }, [el('b', { textContent: u.display_name }),
                        el('small', { textContent: `אושר ${relTime(s.granted_at)}${s.last_used_at ? ' · נכנס ' + relTime(s.last_used_at) : ''}` })]),
                ]), x]);
            }))
            : el('p', { className: 'muted small', textContent: 'עוד אף אחד לא נכנס דרכה.' });
        const dlg = el('dialog', { className: 'user-sheet' }, [el('div', { className: 'sheet' }, [
            el('div', { className: 'sheet-head' }, [
                el('span', { className: 'app-ic sm', textContent: c.icon, ariaHidden: 'true' }),
                el('div', { className: 'tx' }, [el('h2', { textContent: c.name }),
                    el('small', { textContent: c.disabled ? 'הכניסה המאוחדת כבויה' : 'הכניסה המאוחדת פעילה' })]),
                close,
            ]),
            el('div', { className: 'sheet-body' }, [el('div', { className: 'sheet-sec', textContent: `משתמשים שאישרו (${users.length})` }), list]),
        ])]);
        close.addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
        dlg.addEventListener('close', () => dlg.remove());
        document.body.append(dlg);
        dlg.showModal();
    }

    function tileBtn(icon, text, onClick, cls = '') {
        const t = el('button', { type: 'button', className: `tile ${cls}` }, [
            el('span', { textContent: icon, ariaHidden: 'true' }), el('span', { className: 'one', textContent: text }),
        ]);
        t.addEventListener('click', onClick);
        return t;
    }

    // ---------- חיבור לפלטפורמה ----------
    // מחוברת: נכנסים רק עם חשבון בפלטפורמה והרשאה לאפליקציה (השער). מנותקת: עומדת לבד —
    // כל מי שיש לו את הכתובת נכנס, והכניסה (אם יש) היא של האפליקציה עצמה. כמו קדישא.
    function connectionControl(app) {
        if (!app.gate) {
            return el('span', { className: 'chip', title: app.external ? 'אפליקציה בדומיין אחר' : 'אין לה שער ב-app.json',
                textContent: app.external ? '↗ חיצונית — עומדת לבד' : '🔓 עומדת לבד' });
        }
        const box = el('input', { type: 'checkbox', checked: app.connected });
        box.addEventListener('change', () => {
            if (!box.checked && !confirm(`לנתק את "${app.name}" מהפלטפורמה?\n\n` +
                'מעכשיו כל מי שיש לו את הכתובת ייכנס בלי חשבון בפלטפורמה ובלי הרשאה. ' +
                'אם יש לאפליקציה כניסה משלה — היא שתשמור עליה. כניסות לא יירשמו בלשונית "שימוש".\n\n' +
                'אפשר לחבר מחדש בכל רגע.')) { box.checked = true; return; }
            act('admin_app_update', { id: app.id, detached: !box.checked },
                box.checked ? `${app.name}: מחוברת — רק עם חשבון והרשאה` : `${app.name}: נותקה — עומדת לבד`);
        });
        return el('label', { className: `small conn-toggle${app.connected ? '' : ' off'}` }, [box, app.connected ? ' 🔌 מחוברת' : ' 🔌 מנותקת']);
    }

    // ---------- קטגוריות ואפליקציות ----------

    function renderCatalog() {
        const cats = state.categories.map((cat, i) => {
            const icon = el('input', { value: cat.icon, className: 'icon-input', maxLength: 8, ariaLabel: 'אייקון' });
            const name = el('input', { value: cat.name, maxLength: 40, ariaLabel: 'שם' });
            const count = state.apps.filter(a => a.category === cat.id).length;
            return el('div', { className: 'cat-row' }, [
                icon, name,
                el('span', { className: 'muted small', textContent: `${count} אפליקציות` }),
                button('שמירה', () => act('admin_category_save', { id: cat.id, name: name.value, icon: icon.value }, 'נשמר')),
                button('▲', () => act('admin_category_move', { id: cat.id, dir: -1 }), i === 0 ? 'hidden' : ''),
                button('▼', () => act('admin_category_move', { id: cat.id, dir: 1 }), i === state.categories.length - 1 ? 'hidden' : ''),
                button('מחיקה', () => confirm(`למחוק את הקטגוריה "${cat.name}"?`)
                    && act('admin_category_delete', { id: cat.id }, 'נמחקה'), 'danger'),
            ]);
        });

        const newIcon = el('input', { placeholder: '📁', className: 'icon-input', maxLength: 8, ariaLabel: 'אייקון' });
        const newName = el('input', { placeholder: 'קטגוריה חדשה', maxLength: 40, ariaLabel: 'שם' });
        const add = el('div', { className: 'cat-row' }, [newIcon, newName,
            button('➕ הוספה', () => act('admin_category_save', { name: newName.value, icon: newIcon.value }, 'נוספה'), 'primary')]);

        const apps = state.apps.map(app => {
            const sel = el('select', {}, state.categories.map(c =>
                el('option', { value: c.id, textContent: `${c.icon} ${c.name}` })));
            sel.value = app.category;
            sel.addEventListener('change', () => act('admin_app_update', { id: app.id, category: sel.value }, `${app.name} הועברה`));
            const hidden = el('input', { type: 'checkbox', checked: app.hidden });
            hidden.addEventListener('change', () => act('admin_app_update', { id: app.id, hidden: hidden.checked },
                hidden.checked ? `${app.name} מוסתרת מכולם` : `${app.name} גלויה שוב`));
            const row = [
                el('span', { textContent: `${app.icon} ${app.name}` }), sel,
                el('label', { className: 'small' }, [hidden, ' מוסתרת']),
            ];
            // התקנה נפרדת / APK / חנות — מוצגים לכולם (כמו בחנות); כאן אפשר להסתיר.
            if (app.can_install) {
                const inst = el('input', { type: 'checkbox', checked: app.install_ok });
                inst.addEventListener('change', () => act('admin_app_update', { id: app.id, install_ok: inst.checked },
                    inst.checked ? `${app.name}: התקנה והורדה מוצגות` : `${app.name}: התקנה והורדה הוסתרו`));
                row.push(el('label', { className: 'small' }, [inst, ' 📲 התקנה והורדה']));
            }
            row.push(connectionControl(app));
            return el('div', { className: `app-row${app.hidden ? ' is-hidden' : ''}` }, row);
        });

        return el('div', {}, [
            el('h3', { textContent: 'קטגוריות' }), ...cats, add,
            el('h3', { textContent: 'אפליקציות' }),
            el('p', { className: 'muted small', textContent: 'אפליקציה מוסתרת לא מוצגת לאף אחד, גם לא למי שקיבל הרשאה — רק כאן. ' +
                '"📲 התקנה והורדה" — מסומן כברירת מחדל: מי שרואה את האפליקציה יכול גם להתקין אותה (כאפליקציה נפרדת, APK או חנות). מורידים את הסימון כדי להסתיר. ' +
                '"🔌 מחוברת" — נכנסים לאפליקציה רק עם חשבון בפלטפורמה והרשאה אליה. מורידים את הסימון כדי לנתק אותה: היא תעמוד לבד, כמו קדישא.' }),
            ...apps,
        ]);
    }

    // ---------- בדיקת שרת (לקראת השער, שלב 4) ----------
    // כל בדיקה היא בקשה אמיתית לשרת — ראו platform/probe/README.md.

    async function probe(path) {
        try {
            const res = await fetch(`platform/probe/${path}`, { cache: 'no-store' });
            const text = await res.text();
            try { return { status: res.status, json: JSON.parse(text) }; }
            catch { return { status: res.status, text: text.slice(0, 80) }; }
        } catch (err) {
            return { status: 0, text: err.message };
        }
    }

    function renderProbe() {
        const out = el('pre', { className: 'probe-out', textContent: 'בודק…' });
        const copy = button('📋 העתקת התוצאה', async () => {
            try { await navigator.clipboard.writeText(out.textContent); copy.textContent = '✓ הועתק'; }
            catch { window.prompt('העתיקו:', out.textContent); }
        });
        (async () => {
            const [info, rewriteFile, rewriteVirtual, userIni, htaccess] = await Promise.all([
                probe('info.php'), probe('r/page.html'), probe('r/virtual'), probe('u/check.php'), probe('h/check.php'),
            ]);
            const yes = (ok) => ok ? '✅ כן' : '❌ לא';
            const lines = [
                `PHP: ${info.json?.php} · SAPI: ${info.json?.sapi} · ${info.json?.server}`,
                `rewrite על קובץ קיים: ${yes(rewriteFile.json?.rewritten)} (${rewriteFile.status})`,
                `rewrite על כתובת וירטואלית: ${yes(rewriteVirtual.json?.rewritten)} (${rewriteVirtual.status})`,
                `.user.ini auto_prepend: ${yes(userIni.json?.prepended)} (${userIni.status})`,
                `.htaccess php_value: ${yes(htaccess.json?.prepended)} (${htaccess.status})`,
                `user_ini: ${info.json?.user_ini} · ttl: ${info.json?.user_ini_ttl}`,
                `platform: ${info.json?.platform_dir}`,
                `document_root: ${info.json?.document_root}`,
                `script: ${info.json?.script_name} · cwd: ${info.json?.cwd}`,
                `include_path: ${info.json?.include_path} · pdo_sqlite: ${info.json?.pdo_sqlite}`,
            ];
            out.textContent = lines.join('\n');
        })();
        return el('div', {}, [
            el('p', { className: 'muted small', textContent:
                'הבדיקה קובעת איך ייבנה השער שחוסם כניסה ישירה לכלים. העתיקו את התוצאה ושלחו אותה בצ׳אט. ' +
                '.user.ini נקרא מחדש רק כל כמה דקות — אם הוא "לא" מיד אחרי פריסה, נסו שוב בעוד 5 דקות.' }),
            out, copy,
        ]);
    }

    return { show };
})();
