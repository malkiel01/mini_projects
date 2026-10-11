// ממשק המנהל (#admin): משתמשים והרשאות, קטגוריות ושיוך אפליקציות.
// נטען לפני app.js ומשתמש בעזרים שלו דרך window.Platform בזמן ריצה.

window.PlatformAdmin = (() => {
    let root = null;
    let state = null;
    let tab = 'users';
    let editing = null;   // מזהה המשתמש שעורך ההרשאות שלו פתוח
    let flash = '';

    const P = () => window.Platform;
    const el = (...a) => P().el(...a);

    async function show(container) {
        root = container;
        await reload();
    }

    async function reload() {
        state = await P().api('admin_state');
        render();
    }

    // פעולה, ואחריה טעינה מחדש. שגיאה מוצגת בראש המסך ולא מפילה אותו.
    async function act(action, body, message = '') {
        try {
            await P().api(action, body);
            flash = message;
        } catch (err) {
            flash = '⚠️ ' + err.message;
        }
        await reload();
    }

    function button(text, onClick, cls = '') {
        const b = el('button', { type: 'button', className: `btn ${cls}`, textContent: text });
        b.addEventListener('click', onClick);
        return b;
    }

    function render() {
        const tabs = el('div', { className: 'admin-tabs' }, [
            ['users', `👥 משתמשים${pendingCount() ? ` (${pendingCount()} ממתינים)` : ''}`],
            ['catalog', '🗂️ קטגוריות ואפליקציות'],
            ['probe', '🩺 בדיקת שרת'],
        ].map(([id, label]) => button(label, () => { tab = id; flash = ''; render(); }, tab === id ? 'active' : '')));

        const body = tab === 'users' ? renderUsers() : tab === 'catalog' ? renderCatalog() : renderProbe();
        const note = flash ? [el('div', { className: 'admin-flash', textContent: flash })] : [];
        root.replaceChildren(el('div', { className: 'admin' }, [tabs, ...note, body]));
    }

    const pendingCount = () => state.users.filter(u => u.status === 'pending').length;

    // ---------- משתמשים ----------

    const STATUS = { pending: ['ממתין לאישור', 'pending'], active: ['פעיל', 'active'], blocked: ['חסום', 'blocked'] };

    function renderUsers() {
        return el('div', { className: 'admin-list' }, state.users.map(userRow));
    }

    function userRow(u) {
        const [statusText, statusCls] = STATUS[u.status];
        const badges = [el('span', { className: `badge ${statusCls}`, textContent: statusText })];
        if (u.role === 'admin') badges.push(el('span', { className: 'badge admin', textContent: 'מנהל' }));

        const actions = [];
        if (u.self) {
            actions.push(el('span', { className: 'muted', textContent: 'זה אתה' }));
        } else if (u.status === 'pending') {
            actions.push(
                button('✓ אישור', async () => {
                    editing = u.id;
                    await act('admin_user_update', { id: u.id, status: 'active' },
                        `${u.display_name} אושר/ה. בחרו למטה מה יוצג לו/ה.`);
                }, 'primary'),
                button('דחייה', () => confirm(`למחוק את הבקשה של ${u.display_name}?`)
                    && act('admin_user_delete', { id: u.id }, 'הבקשה נמחקה'), 'danger'),
            );
        } else {
            if (u.role !== 'admin') {
                actions.push(button(editing === u.id ? 'סגירת ההרשאות' : '🔑 הרשאות',
                    () => { editing = editing === u.id ? null : u.id; flash = ''; render(); }));
            }
            if (u.status === 'active') {
                actions.push(button('חסימה', () => confirm(`לחסום את ${u.display_name}? הוא/היא ינותק/תנותק מיד.`)
                    && act('admin_user_update', { id: u.id, status: 'blocked' }, 'נחסם/ה'), 'danger'));
                actions.push(u.role === 'admin'
                    ? button('הסרת ניהול', () => act('admin_user_update', { id: u.id, role: 'user' }, 'כבר לא מנהל/ת'))
                    : button('הפיכה למנהל', () => confirm(`מנהל רואה ועורך הכול. להפוך את ${u.display_name} למנהל?`)
                        && act('admin_user_update', { id: u.id, role: 'admin' }, 'הפך/ה למנהל')));
            } else {
                actions.push(button('ביטול חסימה', () => act('admin_user_update', { id: u.id, status: 'active' }, 'החסימה בוטלה')));
                actions.push(button('מחיקה', () => confirm(`למחוק את ${u.display_name} לצמיתות?`)
                    && act('admin_user_delete', { id: u.id }, 'נמחק/ה'), 'danger'));
            }
        }

        const last = u.last_login_at ? `כניסה אחרונה: ${new Date(u.last_login_at).toLocaleDateString('he-IL')}` : 'עוד לא נכנס/ה';
        const parts = [
            el('div', { className: 'row-head' }, [
                el('div', {}, [
                    el('strong', { textContent: u.display_name }),
                    // bdi: בלעדיו כתובת מייל מתהפכת בתוך שורה עברית (gmail.com@052840).
                    el('span', { className: 'muted' }, [' · ', el('bdi', { textContent: u.username })]),
                    el('div', { className: 'muted small', textContent: last }),
                ]),
                el('div', { className: 'badges' }, badges),
            ]),
            el('div', { className: 'row-actions' }, actions),
        ];
        if (editing === u.id && u.role !== 'admin' && u.status === 'active') parts.push(grantsEditor(u));
        return el('div', { className: 'admin-row' }, parts);
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
                    ['', 'לפי הקטגוריה'], ['allow', '✓ מותר'], ['deny', '✕ חסום'],
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
                    const seen = r.app.id in apps ? apps[r.app.id] : cats.has(cat.id);
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
            { id: u.id, categories: [...cats], apps }, `ההרשאות של ${u.display_name} נשמרו`), 'primary');
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
            return el('div', { className: `app-row${app.hidden ? ' is-hidden' : ''}` }, [
                el('span', { textContent: `${app.icon} ${app.name}` }), sel,
                el('label', { className: 'small' }, [hidden, ' מוסתרת']),
            ]);
        });

        return el('div', {}, [
            el('h3', { textContent: 'קטגוריות' }), ...cats, add,
            el('h3', { textContent: 'אפליקציות' }),
            el('p', { className: 'muted small', textContent: 'אפליקציה מוסתרת לא מוצגת לאף אחד, גם לא למי שקיבל הרשאה — רק כאן.' }),
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
