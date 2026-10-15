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
            ['users', '👥 משתמשים', n],
            ['catalog', '🗂️ אפליקציות', 0],
            ['probe', '🩺 שרת', 0],
        ].map(([id, label, count]) => {
            const b = button(label, () => { tab = id; render(); }, tab === id ? 'active' : '');
            b.role = 'tab';
            b.ariaSelected = String(tab === id);
            if (count) b.append(el('span', { className: 'chip bad', textContent: String(count) }));
            return b;
        }));
        const body = tab === 'users' ? renderUsers() : tab === 'catalog' ? renderCatalog() : renderProbe();
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
            if (u.status === 'active' && u.role !== 'admin') {
                body.push(el('div', { className: 'sheet-sec', textContent: 'מה הוא/היא רואה' }), grantsEditor(u));
            } else if (u.role === 'admin') {
                body.push(el('p', { className: 'muted small', textContent: 'מנהל רואה את כל האפליקציות — אין צורך בהרשאות.' }));
            }
        }

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
            // התקנה נפרדת / APK / חנות — מוצגים למשתמשים רק אחרי אישור כאן.
            if (app.can_install) {
                const inst = el('input', { type: 'checkbox', checked: app.install_ok });
                inst.addEventListener('change', () => act('admin_app_update', { id: app.id, install_ok: inst.checked },
                    inst.checked ? `${app.name}: התקנה והורדה מוצגות` : `${app.name}: התקנה והורדה הוסתרו`));
                row.push(el('label', { className: 'small' }, [inst, ' 📲 התקנה והורדה']));
            }
            return el('div', { className: `app-row${app.hidden ? ' is-hidden' : ''}` }, row);
        });

        return el('div', {}, [
            el('h3', { textContent: 'קטגוריות' }), ...cats, add,
            el('h3', { textContent: 'אפליקציות' }),
            el('p', { className: 'muted small', textContent: 'אפליקציה מוסתרת לא מוצגת לאף אחד, גם לא למי שקיבל הרשאה — רק כאן. ' +
                '"📲 התקנה והורדה" מציג למשתמשים את ההתקנה כאפליקציה נפרדת, את ה-APK או את החנות — רק לאפליקציות שיש להן אחד מאלה.' }),
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
