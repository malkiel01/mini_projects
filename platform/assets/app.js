// המעטפת של הפלטפורמה: כותרת, כניסה, הרשמה, התקנת המנהל הראשון, והאפליקציות של המשתמש.
// כל הטקסט מוצב כ-textContent — שום ערך מהשרת או מ-app.json אינו מפוענח כ-HTML.

const API = 'platform/api.php';
const view = document.getElementById('view');
const userbar = document.getElementById('userbar');
const topbar = document.getElementById('topbar');
let csrf = null;
let me = null;

function el(tag, props = {}, children = []) {
    const node = Object.assign(document.createElement(tag), props);
    node.append(...children);
    return node;
}

// יומן האבחון (assets/diag.js) — אירועים של הפלטפורמה: כניסה, חזרה לאפליקציה, התקנה ופתיחה
const dlog = (event, info) => { try { window.PlatformDiag?.log(event, info); } catch { /* */ } };

async function api(action, body) {
    const opts = { cache: 'no-store', headers: {} };
    if (body !== undefined) {
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-Platform'] = '1';
        if (csrf) opts.headers['X-CSRF'] = csrf;
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(`${API}?action=${action}`, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error || 'שגיאה בשרת'), { status: res.status });
    return data;
}

// גוון קבוע לכל מחרוזת — לאווטאר ולריבוע של האפליקציה.
function hue(text) {
    let h = 0;
    for (const ch of String(text)) h = (h * 31 + ch.codePointAt(0)) % 360;
    return h;
}

function initials(name) {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    return (parts.length > 1 ? parts[0][0] + parts[1][0] : (parts[0] || '?').slice(0, 2)).toUpperCase();
}

function avatar(user, cls = '') {
    const a = el('span', { className: `avatar ${cls}`, textContent: initials(user.display_name), ariaHidden: 'true' });
    a.style.setProperty('--h', hue(user.username));
    return a;
}

// הלוגו מהכותרת — לשימוש חוזר בכרטיסי הכניסה וההודעות.
const logo = () => topbar.querySelector('.logo').cloneNode(true);

let toastTimer = null;
function toast(text) {
    document.querySelector('.toast')?.remove();
    const t = el('div', { className: 'toast', role: 'status', textContent: text });
    document.body.append(t);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.remove(), 3200);
}

// ---------- טופס כללי ----------

function field(label, name, { type = 'text', ltr = false, hint = '', autocomplete = '' } = {}) {
    const input = el('input', { name, type, required: true, autocomplete });
    if (ltr) input.dir = 'ltr';
    return [
        el('label', { textContent: label, htmlFor: `f-${name}` }),
        Object.assign(input, { id: `f-${name}` }),
        ...(hint ? [el('div', { className: 'hint', textContent: hint })] : []),
    ];
}

function form({ title, lead, fields, submit, onSubmit, footer = [] }) {
    const error = el('div', { className: 'form-error', role: 'alert' });
    const button = el('button', { type: 'submit', textContent: submit });
    const f = el('form', {}, [...fields.flat(), button, error]);
    f.addEventListener('submit', async (e) => {
        e.preventDefault();
        error.textContent = '';
        button.disabled = true;
        try {
            await onSubmit(Object.fromEntries(new FormData(f)));
        } catch (err) {
            error.textContent = err.message;
        } finally {
            button.disabled = false;
        }
    });
    return el('div', { className: 'auth' }, [el('div', { className: 'panel' }, [
        logo(),
        el('h2', { textContent: title }),
        el('p', { className: 'lead', textContent: lead }),
        f,
        ...footer,
    ])]);
}

function switchLink(text, linkText, onClick) {
    const btn = el('button', { type: 'button', className: 'link-btn', textContent: linkText });
    btn.addEventListener('click', onClick);
    return el('div', { className: 'switch' }, [text + ' ', btn]);
}

const USERNAME_HINT = 'מומלץ: כתובת המייל שלכם — לפיה תזוהו גם באפליקציות';

// ---------- המסכים ----------

function showSetup(me) {
    if (!me.setupReady) {
        showMessage('ההתקנה ממתינה לקוד',
            'הסוד PLATFORM_SETUP_CODE לא הגיע לשרת. מגדירים אותו ב-GitHub ' +
            '(Settings ← Secrets and variables ← Actions), והוא יגיע בפריסה הבאה.', '🔑');
        return;
    }
    view.replaceChildren(form({
        title: 'התקנה: יצירת המנהל',
        lead: 'המשתמש הראשון הוא המנהל. נדרש קוד ההתקנה — הסוד PLATFORM_SETUP_CODE שהוגדר ב-GitHub. ' +
              'אחרי שהמנהל נוצר, הדף הזה נסגר.',
        fields: [
            field('קוד ההתקנה', 'code', { type: 'password', ltr: true, autocomplete: 'off' }),
            field('השם שלך', 'display_name', { autocomplete: 'name' }),
            field('שם משתמש', 'username', { ltr: true, hint: USERNAME_HINT, autocomplete: 'username' }),
            field('סיסמה', 'password', { type: 'password', ltr: true, hint: 'לפחות 8 תווים', autocomplete: 'new-password' }),
        ],
        submit: 'יצירת המנהל',
        onSubmit: async (data) => { await api('setup', data); justSignedIn = true; start(); },
    }));
}

function showLogin() {
    view.replaceChildren(form({
        title: 'ברוכים הבאים',
        lead: 'נכנסים כדי לראות את האפליקציות שלכם.',
        fields: [
            field('מייל או שם משתמש', 'username', { ltr: true, autocomplete: 'username' }),
            field('סיסמה', 'password', { type: 'password', ltr: true, autocomplete: 'current-password' }),
        ],
        submit: 'כניסה',
        onSubmit: async (data) => { await api('login', data); dlog('login', { next: new URLSearchParams(location.search).get('next') || '' }); justSignedIn = true; start(); },
        footer: [switchLink('אין לכם חשבון?', 'הרשמה', showRegister)],
    }));
}

function showRegister() {
    view.replaceChildren(form({
        title: 'הרשמה',
        lead: 'אחרי ההרשמה יישלח אליכם מייל עם קישור לאימות הכתובת.',
        fields: [
            field('השם שלכם', 'display_name', { autocomplete: 'name' }),
            field('מייל', 'email', { type: 'email', ltr: true, hint: 'בו נכנסים — גם לאפליקציות שמתחברות לכלים הדיגיטליים', autocomplete: 'email' }),
            field('סיסמה', 'password', { type: 'password', ltr: true, hint: 'לפחות 8 תווים', autocomplete: 'new-password' }),
        ],
        submit: 'הרשמה',
        onSubmit: async (data) => { await api('register', data); justSignedIn = true; start(); },
        footer: [switchLink('כבר רשומים?', 'כניסה', showLogin)],
    }));
}

// החלפת סיסמה: מחויבת אחרי סיסמה זמנית מהמנהל, או מרצון מהתפריט.
function showPassword(forced) {
    const footer = forced ? [] : [switchLink('', 'ביטול', () => start())];
    view.replaceChildren(form({
        title: forced ? 'בחירת סיסמה חדשה' : 'החלפת סיסמה',
        lead: forced
            ? 'המנהל קבע לכם סיסמה זמנית. לפני שממשיכים — בחרו סיסמה משלכם.'
            : 'אחרי ההחלפה, שאר המכשירים שלכם יתנתקו.',
        fields: [
            field(forced ? 'הסיסמה הזמנית' : 'הסיסמה הנוכחית', 'current', { type: 'password', ltr: true, autocomplete: 'current-password' }),
            field('סיסמה חדשה', 'password', { type: 'password', ltr: true, hint: 'לפחות 8 תווים', autocomplete: 'new-password' }),
        ],
        submit: 'שמירת הסיסמה',
        onSubmit: async (data) => { await api('password', data); toast('✓ הסיסמה הוחלפה'); justSignedIn = forced; start(); },
        footer,
    }));
}

// אפליקציות שאישרתי להן "כניסה עם חשבון כלים דיגיטליים" (platform/sso.php) — וניתוק.
async function openConnections() {
    const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
    const list = el('div', {}, [el('p', { className: 'muted small', textContent: 'טוען…' })]);
    const dlg = el('dialog', {}, [el('div', { className: 'sheet' }, [
        el('div', { className: 'sheet-head' }, [
            el('span', { className: 'app-ic', textContent: '🔗', ariaHidden: 'true' }),
            el('div', { className: 'tx' }, [el('h2', { textContent: 'חיבורים לאפליקציות' }),
                el('small', { textContent: 'אפליקציות שנכנסים אליהן עם החשבון הזה' })]),
            close,
        ]),
        el('div', { className: 'sheet-body' }, [list,
            el('p', { className: 'muted small', textContent: 'אפליקציה מחוברת מקבלת רק את השם שלך, המייל ומספר מזהה. ניתוק לא מוחק את החשבון שלך בתוכה — רק את הכניסה דרך כאן.' })]),
    ])]);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();

    async function load() {
        try {
            const { consents } = await api('sso_mine');
            list.replaceChildren(consents.length
                ? el('div', { className: 'entries' }, consents.map(c => {
                    const x = el('button', { type: 'button', className: 'btn danger', textContent: 'ניתוק' });
                    x.addEventListener('click', async () => {
                        if (!confirm(`לנתק את ${c.name}? בכניסה הבאה דרכה תתבקשו לאשר שוב.`)) return;
                        try { await api('sso_revoke', { client_id: c.client_id }); toast('נותק'); } catch (err) { toast('⚠️ ' + err.message); }
                        load();
                    });
                    return el('div', { className: 'entry conn' }, [
                        el('span', { className: 'conn-tx' }, [el('b', { textContent: c.name }),
                            el('small', { textContent: `אושר ${new Date(c.granted_at).toLocaleDateString('he-IL')}` })]),
                        x,
                    ]);
                }))
                : el('p', { className: 'muted', textContent: 'עוד לא אישרת לאף אפליקציה להיכנס עם החשבון הזה.' }));
        } catch (err) { list.replaceChildren(el('p', { className: 'status error', textContent: err.message })); }
    }
    load();
}

// נרשם ועוד לא לחץ על הקישור במייל — הכתובת, ו"שליחה שוב".
function showVerifyPending(user) {
    const again = el('button', { type: 'button', className: 'btn sm', textContent: 'לא קיבלתי — שליחה שוב' });
    again.addEventListener('click', async () => {
        again.disabled = true;
        try { await api('resend_verify', {}); toast('✓ נשלח מייל חדש'); } catch (err) { toast('⚠️ ' + err.message); }
        again.disabled = false;
    });
    view.replaceChildren(el('div', { className: 'auth' }, [el('div', { className: 'panel msg' }, [
        el('div', { className: 'big', textContent: '📧', ariaHidden: 'true' }),
        el('h2', { textContent: 'בדקו את המייל' }),
        el('p', { className: 'lead' }, ['שלחנו קישור לאימות אל ', el('bdi', { textContent: user.email }),
            '. לוחצים עליו — ואפשר להמשיך. לא מוצאים? בדקו גם בתיקיית הספאם.']),
        again,
    ])]));
}

// הקישור מהמייל: #verify=<אסימון>. מאמתים, מוחקים את האסימון מהכתובת, וממשיכים כרגיל.
async function consumeVerifyLink() {
    const m = location.hash.match(/^#verify=([a-f0-9]{48})$/);
    if (!m) return;
    history.replaceState(null, '', location.pathname + location.search);
    try {
        const r = await api('verify', { token: m[1] });
        toast(r.status === 'active' ? '✓ המייל אומת — החשבון פעיל' : '✓ המייל אומת — ממתין לאישור המנהל');
    } catch (err) {
        toast('⚠️ ' + err.message);
    }
}

function showMessage(title, text, icon = '') {
    view.replaceChildren(el('div', { className: 'auth' }, [el('div', { className: 'panel msg' }, [
        icon ? el('div', { className: 'big', textContent: icon, ariaHidden: 'true' }) : logo(),
        el('h2', { textContent: title }),
        el('p', { className: 'lead', textContent: text }),
    ])]));
}

// ---------- הכותרת העליונה ----------

const inAdmin = () => location.hash === '#admin';

// מסך משני (ניהול): חץ חזרה לפני הלוגו, ושם המסך מתחת לשם האתר. במסך הראשי — בלי חץ.
function showTopbar() {
    topbar.querySelector('.back-btn')?.remove();
    const tx = topbar.querySelector('.brand-tx');
    tx.querySelector('.brand-sub')?.remove();
    if (inAdmin() && me?.user?.role === 'admin') {
        const back = el('a', { className: 'icon-btn back-btn', href: '#', textContent: '‹', ariaLabel: 'חזרה לאפליקציות' });
        topbar.prepend(back);
        tx.append(el('span', { className: 'brand-sub', textContent: 'ממשק ניהול' }));
    }
}

function closeMenu() {
    const menu = userbar.querySelector('.menu');
    if (!menu) return;
    menu.remove();
    userbar.querySelector('.me-btn')?.setAttribute('aria-expanded', 'false');
}

document.addEventListener('click', (e) => { if (!e.target.closest('.menu-wrap')) closeMenu(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });

function menuItem(icon, text, onClick, { cls = '', href = '', count = 0 } = {}) {
    const item = el(href ? 'a' : 'button', { className: `menu-item ${cls}`, role: 'menuitem' }, [
        el('span', { className: 'ic', textContent: icon, ariaHidden: 'true' }),
        el('span', { className: 'one', textContent: text }),
        ...(count ? [el('span', { className: 'chip bad count', textContent: String(count) })] : []),
    ]);
    if (href) item.href = href; else item.type = 'button';
    item.addEventListener('click', () => { closeMenu(); onClick?.(); });
    return item;
}

function openMenu(btn, user) {
    const role = user.role === 'admin' ? 'מנהל' : 'משתמש';
    const items = [
        el('div', { className: 'menu-head' }, [
            avatar(user, 'lg'),
            el('div', { className: 'tx' }, [
                el('b', { textContent: user.display_name }),
                // bdi: בלעדיו כתובת מייל מתהפכת בתוך שורה עברית.
                el('small', {}, [el('bdi', { textContent: user.username }), ` · ${role}`]),
            ]),
        ]),
    ];
    if (user.role === 'admin') {
        items.push(inAdmin()
            ? menuItem('🏠', 'האפליקציות שלי', null, { href: '#' })
            : menuItem('⚙️', 'ניהול', null, { href: '#admin', count: me.pending || 0 }));
    }
    items.push(menuItem('🔑', 'החלפת סיסמה', () => showPassword(false)));
    items.push(menuItem('🔗', 'חיבורים לאפליקציות', openConnections));
    if (!isStandalone()) {
        items.push(isInstalled()
            ? menuItem('✓', 'מותקן במכשיר', openInstallSheet)
            : menuItem('📲', 'התקנה במכשיר', openInstallSheet));
    }
    if (canDownload()) items.push(menuItem('⬇️', 'הורדה לשימוש בלי רשת', downloadFamily));
    items.push(el('div', { className: 'menu-sep' }), menuItem('↩', 'יציאה', logout, { cls: 'danger' }));

    const menu = el('div', { className: 'menu', role: 'menu' }, items);
    btn.after(menu);
    btn.setAttribute('aria-expanded', 'true');
    menu.querySelector('.menu-item')?.focus();
}

async function logout() {
    try { await api('logout', {}); } finally {
        csrf = null;
        await clearFamilyCache();
        location.hash = '';
        start();
    }
}

function showUserbar(user) {
    if (!user) { userbar.replaceChildren(); return; }
    const actions = [];
    // "התקנה" גלוי עד שהאפליקציה מותקנת במכשיר. אחרי זה — רק בתפריט ("✓ מותקן במכשיר").
    if (!isInstalled()) {
        const b = el('button', { type: 'button', className: 'install-btn' }, [
            el('span', { textContent: '📲', ariaHidden: 'true' }), el('span', { className: 'lbl', textContent: 'התקנה' }),
        ]);
        b.addEventListener('click', openInstallSheet);
        actions.push(b);
    }
    if (user.role === 'admin' && !inAdmin()) {
        const a = el('a', { className: 'icon-btn', href: '#admin', textContent: '⚙️', ariaLabel: 'ניהול', title: 'ניהול' });
        if (me.pending) a.append(el('span', { className: 'dot', textContent: String(me.pending), ariaLabel: `${me.pending} ממתינים לאישור` }));
        actions.push(a);
    }
    const btn = el('button', { type: 'button', className: 'me-btn', ariaLabel: 'תפריט המשתמש', ariaHasPopup: 'menu', ariaExpanded: 'false' }, [
        avatar(user), el('span', { className: 'caret', textContent: '▾', ariaHidden: 'true' }),
    ]);
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (userbar.querySelector('.menu')) closeMenu(); else openMenu(btn, user);
    });
    actions.push(el('div', { className: 'menu-wrap' }, [btn]));
    userbar.replaceChildren(...actions);
}

// ---------- האפליקציות ----------

// העתקת קישור ישיר לכניסה — הכתובת נבנית מהדף הנוכחי, כך שהיא נכונה בכל דומיין.
async function copyLink(btn, href) {
    const url = new URL(href, document.baseURI).href;
    try {
        await navigator.clipboard.writeText(url);
    } catch {
        window.prompt('העתיקו את הקישור:', url);
        return;
    }
    btn.textContent = '✓';
    btn.classList.add('copied');
    toast('הקישור הועתק');
    setTimeout(() => { btn.textContent = '🔗'; btn.classList.remove('copied'); }, 1800);
}

// הפלטפורמה היא חנות/משגר, לא מעטפת: אפליקציה נפתחת בחלון (או לשונית) משלה, והפלטפורמה
// נשארת מאחור. בלי זה, באפליקציה המותקנת הכלי "תפס" את החלון — וכשחוזרים לכלים הדיגיטליים
// אנדרואיד מחזיר את מה שהיה פתוח בו, כלומר את האפליקציה האחרונה.
function openProps(href) {
    return { href, target: '_blank', rel: 'noopener' };
}

function appIcon(app) {
    const ic = el('span', { className: 'app-ic', textContent: app.icon, ariaHidden: 'true' });
    ic.style.setProperty('--h', hue(app.id));
    return ic;
}

// אפליקציה עם כניסה אחת — קישור. עם כמה כניסות, או עם התקנה/הורדה — גיליון בחירה.
function appRow(app) {
    const body = [
        appIcon(app),
        el('span', { className: 'app-tx' }, [
            el('b', {}, [app.name, ...(app.external ? [el('span', { className: 'ext', textContent: '↗', title: 'אתר חיצוני' })] : []),
                el('span', { className: 'chip ok inst-chip', textContent: '✓ מותקנת', hidden: true })]),
            el('small', { textContent: app.description }),
        ]),
        el('span', { className: 'app-go', textContent: '‹', ariaHidden: 'true' }),
    ];
    const extras = installLinks(app);
    let node;
    if (!app.links.length && !extras.length) {
        node = el('a', { className: 'app', ...openProps(app.href) }, body);
    } else {
        node = el('button', { type: 'button', className: 'app', ariaHasPopup: 'dialog' }, body);
        // הכפתורים נבנים מחדש בכל פתיחה — מה שמותקן ובאיזו גרסה יכול להשתנות בינתיים
        node.addEventListener('click', () => openAppSheet(app, installLinks(app)));
    }
    const li = el('li', {}, [node]);
    li.dataset.search = `${app.name} ${app.description}`.toLowerCase();
    li.dataset.app = app.id;
    if (extras.length) li.dataset.can = '1';
    paintInstalled(li, app.id);
    return li;
}

// ---------- מה באמת מותקן במכשיר הזה (lib/installs.php) ----------
// לא "זוכרים שלחצו". הבדיקה נעשית במכשיר: בכל דף של אפליקציה מחוברת רץ pwa-beacon.js (השער
// מוסיף אותו) ומדווח מה הדפדפן אומר — נפתחה מהאייקון / getInstalledRelatedApps / beforeinstallprompt.
// כאן רק מציגים את מה שדווח. APK — הדפדפן לא יכול לבדוק, ולכן לא מוצג עליו "מותקנת".

let installedHere = {};   // {app_id: {state, how, checked_at}}

function deviceId() {
    try {
        let id = localStorage.getItem('platform:device');
        if (!/^[a-f0-9]{32}$/.test(id || '')) {
            id = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
            localStorage.setItem('platform:device', id);
        }
        return id;
    } catch { return null; }   // בלי אחסון (מצב פרטי) — בלי מעקב
}

function deviceLabel() {
    const os = /iPhone/.test(UA) ? 'iPhone' : /iPad/.test(UA) || isIOS() ? 'iPad' : /Android/.test(UA) ? 'Android'
        : /Windows/.test(UA) ? 'Windows' : /Mac OS X/.test(UA) ? 'Mac' : /Linux/.test(UA) ? 'Linux' : 'מכשיר';
    const br = /Edg\//.test(UA) ? 'Edge' : /SamsungBrowser/.test(UA) ? 'Samsung' : /Firefox|FxiOS/.test(UA) ? 'Firefox'
        : /Chrome|CriOS/.test(UA) ? 'Chrome' : /Safari/.test(UA) ? 'Safari' : '';
    return br ? `${os} · ${br}` : os;
}

async function loadInstalls() {
    const id = deviceId();
    if (!id || !me?.user) return;
    try { installedHere = (await api(`installs&device=${id}`)).installed || {}; } catch { installedHere = {}; }
}

// דיווח על הפלטפורמה עצמה — מאותם מקורות (מצב אפליקציה / הדפדפן / beforeinstallprompt).
function reportInstall(appId, state, how) {
    const id = deviceId();
    if (!id) return;
    installedHere[appId] = { state, how, checked_at: new Date().toISOString() };
    fetch(`${API}?action=install_report`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Platform': '1' },
        body: JSON.stringify({ device: id, label: deviceLabel(), app: appId, state, how }),
    }).catch(() => {});
}

const isInstalledHere = (appId) => installedHere[appId]?.state === 'installed';

function paintInstalled(li, appId) {
    li.dataset.inst = isInstalledHere(appId) ? 'yes' : li.dataset.can ? 'can' : 'no';
    li.querySelector('.inst-chip')?.toggleAttribute('hidden', !isInstalledHere(appId));
}

const HOW = { opened: 'נפתחה מהאייקון', detected: 'הדפדפן אישר', installed: 'הותקנה מכאן', prompt: 'הדפדפן אישר' };

// בגיליון האפליקציה: מה ידוע באמת על המכשיר הזה. קריאה בלבד — אין "לסמן ידנית".
function installStatus(app) {
    const info = installedHere[app.id];
    const when = info ? new Date(info.checked_at).toLocaleDateString('he-IL') : '';
    let cls = '', text;
    if (isAndroid() && app.install.apk) {
        // אנדרואיד: האפליקציה היא ה-APK (androidTiles) — המצב לפי מה שמותקן ובאיזו גרסה
        const s = androidState(app);
        const latest = s.latest ? `גרסה ${s.latest}` : '';
        if (!s.installed) text = `לא מותקנת במכשיר הזה${latest ? ` — ${latest}` : ''}. בהתקנה הראשונה אנדרואיד מבקש לאשר התקנה מהמקור הזה`;
        else if (s.update) { cls = 'on'; text = `✓ מותקנת${s.mine ? ` (גרסה ${s.mine})` : ''} — יש ${latest}`; }
        else { cls = 'on'; text = `✓ מותקנת במכשיר הזה${s.mine ? ` — גרסה ${s.mine}, העדכנית` : ''}`; }
    }
    else if (info?.state === 'installed') { cls = 'on'; text = `✓ מותקנת במכשיר הזה — ${HOW[info.how] || 'נבדק'}, ${when}`; }
    else if (info?.state === 'not_installed') { cls = 'off'; text = `לא מותקנת במכשיר הזה (${HOW.prompt}, ${when})`; }
    else if (app.install.pwa) text = 'עוד לא נבדק במכשיר הזה — הבדיקה נעשית לבד בפתיחה הראשונה של האפליקציה';
    else text = 'אפליקציית אנדרואיד (APK) — דפדפן לא יכול לבדוק אם היא מותקנת';
    return el('p', { className: `inst-status ${cls}`, textContent: text });
}

function openAppSheet(app, extras) {
    const entries = app.links.length ? app.links : [{ label: '▶️ פתיחה', href: app.href }];
    const rows = entries.map(link => {
        const copy = el('button', { type: 'button', className: 'icon-btn', textContent: '🔗', ariaLabel: `העתקת קישור: ${link.label}`, title: 'העתקת קישור' });
        copy.addEventListener('click', () => copyLink(copy, link.href));
        return el('div', { className: 'entry' }, [el('a', { ...openProps(link.href), textContent: link.label }), copy]);
    });
    const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
    const dlg = el('dialog', {}, [el('div', { className: 'sheet' }, [
        el('div', { className: 'sheet-head' }, [
            appIcon(app),
            el('div', { className: 'tx' }, [el('h2', { textContent: app.name }), el('small', { textContent: app.description })]),
            close,
        ]),
        el('div', { className: 'sheet-body' }, [
            el('div', { className: 'sheet-sec', textContent: entries.length > 1 ? 'כניסות' : 'כניסה' }),
            el('div', { className: 'entries' }, rows),
            ...(extras.length ? [
                el('div', { className: 'sheet-sec', textContent: 'התקנה והורדה' }),
                installStatus(app),
                el('div', { className: 'tiles' }, extras),
            ] : []),
        ]),
    ])]);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
}

// ---------- התקנה והורדה (שלב 5) ----------

// התקנת אפליקציה מתוך הכלים הדיגיטליים המותקנים. אנדרואיד: intent עם package של כרום פותח את הכתובת
// בכרום עצמו (לא בחלונית), ושם pwa-beacon.js מציג "התקנה" בלחיצה. אם המכשיר לא מצליח — ההסבר
// הידני והעתקת הקישור. אייפון: מתקינים רק מספארי — מעתיקים את הקישור ופותחים שם.
function openInstallElsewhere(app, url) {
    const u = new URL(url);
    const intent = `intent://${u.host}${u.pathname}${u.search}#Intent;scheme=${u.protocol.replace(':', '')};` +
        `package=com.android.chrome;S.browser_fallback_url=${encodeURIComponent(url)};end`;
    const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
    const copy = el('button', { type: 'button', className: 'btn big', textContent: '🔗 העתקת הקישור' });
    copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(url); toast('הקישור הועתק'); } catch { window.prompt('העתיקו את הקישור:', url); }
    });
    const body = isAndroid() ? [
        el('p', { className: 'lead', textContent: 'מתוך האפליקציה המותקנת אנדרואיד פותח אפליקציות בחלונית, ושם אי אפשר להתקין. פותחים בכרום — ושם לוחצים "התקנה".' }),
        el('a', { className: 'btn primary big', href: intent, textContent: '📲 פתיחה בכרום להתקנה' }),
        el('ol', { className: 'steps' }, [
            'נפתחה חלונית עם ✕ למעלה? תפריט <b>⋮</b> ← <b>"פתיחה ב-Chrome"</b>',
            'בכרום, בפס הסגול בתחתית — <b>"התקנה"</b>',
        ].map(h => el('li', {}, [el('span', { className: 'step-tx', innerHTML: h })]))),
        copy,
    ] : [
        el('p', { className: 'lead', textContent: 'באייפון מתקינים רק מספארי. מעתיקים את הקישור, פותחים אותו בספארי, ושם: שיתוף ← "הוספה למסך הבית".' }),
        copy,
    ];
    const dlg = el('dialog', { className: 'install' }, [el('div', { className: 'sheet' }, [
        el('div', { className: 'sheet-head' }, [
            appIcon(app),
            el('div', { className: 'tx' }, [el('h2', { textContent: `התקנת ${app.name}` }), el('small', { textContent: 'אייקון משלה במסך הבית' })]),
            close,
        ]),
        el('div', { className: 'sheet-body' }, body),
    ])]);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
}

// ---------- אפליקציות אנדרואיד (APK) — מותקנת? באיזו גרסה? ----------
// מותקנת: getInstalledRelatedApps — החבילות רשומות ב-related_applications של המניפסט, והאפליקציה
// (TWA) מצהירה על האתר. הגרסה: אנדרואיד לא מגלה לדפדפן, ולכן נרשמת כאן כשמורידים מהכפתור.
// בלי ה-API (דפדפן אחר) — מה שנרשם בהורדה הוא הסימן היחיד.
const APK_KEY = 'platform-apk';
let nativeInstalled = null;   // Set של חבילות מותקנות, או null כשהדפדפן לא יודע לבדוק

function apkRecord() {
    try { return JSON.parse(localStorage.getItem(APK_KEY) || '{}') || {}; } catch { return {}; }
}
function apkRemember(appId, version) {
    const rec = apkRecord();
    rec[appId] = { version: version || null, at: new Date().toISOString() };
    try { localStorage.setItem(APK_KEY, JSON.stringify(rec)); } catch { /* בלי אחסון — רק לא נזכור */ }
}

async function detectNativeApps() {
    if (!isAndroid() || !navigator.getInstalledRelatedApps) return;
    try {
        const list = await navigator.getInstalledRelatedApps();
        nativeInstalled = new Set(list.filter(a => a.platform === 'play').map(a => a.id));
        dlog('installed-apps', { apps: [...nativeInstalled].join(',') || '(אין)' });
    } catch { nativeInstalled = null; }
}

function androidState(app) {
    const pkg = app.install.android?.package;
    const latest = app.install.android?.version || null;
    const mine = apkRecord()[app.id]?.version || null;
    const installed = nativeInstalled && pkg ? nativeInstalled.has(pkg) : Boolean(apkRecord()[app.id]);
    return { pkg, latest, mine, installed, update: installed && latest && (!mine || mine < latest) };
}

// כפתורי אנדרואיד: לא מותקנת — "התקנה"; מותקנת — "פתיחה" (ישר באפליקציה, לא בדפדפן), ו"עדכון"
// כשיש גרסה חדשה ממה שהותקן מכאן. ההורדה מהשרת שלנו (platform/downloads, store-mirror.sh).
function androidTiles(app, tile) {
    const s = androidState(app);
    const download = (icon, text) => {
        const t = tile(icon, text, { href: app.install.apk, download: '' });
        t.addEventListener('click', () => { apkRemember(app.id, s.latest); dlog('apk-download', { app: app.id, version: s.latest, installed: s.installed, mine: s.mine }); });
        return t;
    };
    if (!s.installed) return [download('📲', s.latest ? `התקנה · גרסה ${s.latest}` : 'התקנה')];
    const out = [];
    if (s.pkg) {
        const u = new URL(app.href, document.baseURI);
        const intent = `intent://${u.host}${u.pathname}${u.search}#Intent;scheme=https;package=${s.pkg};` +
            `S.browser_fallback_url=${encodeURIComponent(u.href)};end`;
        const open = tile('▶️', 'פתיחה', { href: intent });
        open.addEventListener('click', () => dlog('open-app', { app: app.id, pkg: s.pkg }));
        out.push(open);
    }
    if (s.update) out.push(download('⬆️', `עדכון לגרסה ${s.latest}`));
    else out.push(download('🔄', 'התקנה מחדש'));
    return out;
}

// מה שהמנהל אישר לאפליקציה: התקנה נפרדת, APK, חנות. השרת לא שולח את מה שלא אושר.
function installLinks(app) {
    const tile = (icon, text, props) => el('a', { className: 'tile', ...props }, [
        el('span', { textContent: icon, ariaHidden: 'true' }), el('span', { className: 'one', textContent: text }),
    ]);
    const out = [];
    // אנדרואיד עם APK: כפתור אחד שמוריד ומתקין. PWA לא — מתוך הכלים הדיגיטליים המותקנים כל קישור נפתח
    // בחלונית של הדפדפן (גם intent לכרום), ושם אי אפשר להתקין. ה-APK (TWA) נפתח במסך מלא עם אייקון משלו.
    const apkFirst = isAndroid() && app.install.apk;
    if (apkFirst) out.push(...androidTiles(app, tile));
    if (app.install.pwa && !apkFirst) {
        // פותח את האפליקציה בחלון משלה עם ?pwa-install=1 — שם pwa-beacon.js מציג "התקנה" (בלחיצה
        // כשהדפדפן מאפשר, אחרת ההסבר המדויק למכשיר). ההתקנה חייבת לקרות מתוך הדף של האפליקציה.
        const href = app.href + (app.href.includes('?') ? '&' : '?') + 'pwa-install=1';
        const t = tile('📲', isInstalledHere(app.id) ? 'מותקנת — התקנה מחדש' : 'התקנה כאפליקציה', openProps(href));
        // מתוך הכלים הדיגיטליים המותקנים בטלפון קישור נפתח בחלונית של הדפדפן (באנדרואיד Custom Tab עם ✕),
        // ושם אי אפשר להתקין. לכן — לא קישור רגיל, אלא פתיחה בדפדפן עצמו.
        if (isStandalone() && (isAndroid() || isIOS())) {
            t.addEventListener('click', (e) => { e.preventDefault(); openInstallElsewhere(app, new URL(href, document.baseURI).href); });
        }
        out.push(t);
    }
    if (app.install.apk && !apkFirst) out.push(tile('⬇️', 'הורדת APK', { href: app.install.apk, rel: 'noopener' }));
    if (app.install.store) out.push(tile('▶️', 'Google Play', { href: app.install.store, rel: 'noopener', target: '_blank' }));
    // תיקיית החנות — רק למנהל (השרת לא שולח את זה לאחרים): הקבצים להעלאה ל-Play Console.
    if (app.install.release) {
        out.push(tile('📦', 'AAB לחנות · מנהל', { href: app.install.release.aab, rel: 'noopener' }));
        out.push(tile('🗂️', 'הגדרות לחנות · מנהל', { href: app.install.release.listing, rel: 'noopener' }));
    }
    return out;
}

const FAMILY_CACHE = 'family-v1';
const CDN_HOSTS = ['cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];
let installPrompt = null;
let currentApps = [];

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('family-sw.js').catch(() => {});
}
// Chrome/Android: הדפדפן מציע להתקין את המשפחה — שומרים את ההצעה לכפתור שלנו.
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    installPrompt = e;
    markInstalled(false); // הדפדפן מציע להתקין — סימן שהאפליקציה לא מותקנת (או שהוסרה)
    reportInstall('_platform', 'not_installed', 'prompt');
    if (me?.user) showUserbar(me.user);
});

window.addEventListener('appinstalled', () => {
    installPrompt = null;
    markInstalled(true);
    reportInstall('_platform', 'installed', 'installed');
    document.querySelector('dialog.install')?.close();
    document.querySelector('.install-banner')?.remove();
    toast('✓ הותקן — האייקון נמצא במסך הבית');
});

async function installFamily() {
    if (!installPrompt) return;
    installPrompt.prompt();
    const { outcome } = await installPrompt.userChoice;
    installPrompt = null;
    if (outcome === 'accepted') document.querySelector('dialog.install')?.close();
    if (me?.user) showUserbar(me.user);
}

// ---------- הסבר התקנה לפי מכשיר ----------
// כרום/אנדרואיד/Edge נותנים beforeinstallprompt — כפתור אחד. אייפון אינו נותן: מסבירים
// שיתוף ← "הוספה למסך הבית". שאר הדפדפנים — דרך התפריט שלהם.

const UA = navigator.userAgent;
const isIOS = () => /iPhone|iPad|iPod/.test(UA) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isAndroid = () => /Android/.test(UA);
// מה מותקן — בטעינה, ושוב כשחוזרים לכאן (למשל אחרי התקנה או הסרה של APK)
detectNativeApps();
document.addEventListener('visibilitychange', () => { if (!document.hidden) detectNativeApps(); });
const isStandalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

// ---------- האם מותקן במכשיר הזה ----------
// דף שנפתח בדפדפן לא יודע לבד שהאפליקציה מותקנת. שלושה סימנים, מהחזק לחלש:
//   1. נפתחנו מתוך האפליקציה המותקנת (standalone) — רושמים במכשיר. האחסון משותף לאפליקציה
//      ולדפדפן (אותו אתר, אותו דפדפן), כך שמעכשיו גם הלשונית בדפדפן יודעת.
//   2. appinstalled — ההתקנה קרתה עכשיו.
//   3. כרום באנדרואיד: getInstalledRelatedApps, לפי related_applications ב-manifest.
// וההפך: beforeinstallprompt אומר שהאפליקציה לא מותקנת (או שהוסרה) — מוחקים את הסימון.
const INSTALLED_KEY = 'platform:installed';
let installedFlag = false;
try { installedFlag = localStorage.getItem(INSTALLED_KEY) === '1'; } catch { /* מצב פרטי */ }

function markInstalled(yes) {
    if (installedFlag === yes) return;
    installedFlag = yes;
    try { yes ? localStorage.setItem(INSTALLED_KEY, '1') : localStorage.removeItem(INSTALLED_KEY); } catch { /* */ }
    if (yes) document.querySelector('.install-banner')?.remove();
    if (me?.user) showUserbar(me.user);
}
const isInstalled = () => isStandalone() || installedFlag;

if (isStandalone()) { markInstalled(true); reportInstall('_platform', 'installed', 'opened'); }
if ('getInstalledRelatedApps' in navigator) {
    navigator.getInstalledRelatedApps()
        .then(apps => { if (apps.some(a => a.platform === 'webapp')) { markInstalled(true); reportInstall('_platform', 'installed', 'detected'); } })
        .catch(() => {});
}

const SHARE_ICON = 'M12 3l-4 4h3v7h2V7h3l-4-4zM6 10v10h12V10h-3v2h1v6H8v-6h1v-2H6z';
function shareIcon() {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'inline-ic');
    svg.setAttribute('aria-label', 'שיתוף');
    const path = document.createElementNS(NS, 'path');
    path.setAttribute('d', SHARE_ICON);
    svg.append(path);
    return svg;
}

function installSteps() {
    const step = (...parts) => el('li', {}, [el('span', { className: 'step-tx' }, parts)]);
    if (isIOS()) {
        const safari = !/CriOS|FxiOS|EdgiOS/.test(UA);
        return [
            ...(safari ? [] : [el('p', { className: 'note', textContent: 'באייפון ההתקנה הכי פשוטה מספארי — פתחו את הכתובת הזו בספארי. אם אתם בכרום: אותם צעדים, דרך כפתור השיתוף שליד שורת הכתובת.' })]),
            el('ol', { className: 'steps' }, [
                step('לוחצים על ', el('b', {}, ['שיתוף ', shareIcon()]), ' בתחתית המסך (באייפד — למעלה)'),
                step('גוללים ובוחרים ', el('b', { textContent: '"הוספה למסך הבית"' })),
                step('לוחצים ', el('b', { textContent: '"הוספה"' }), ' — והאייקון מופיע במסך הבית'),
            ]),
            el('p', { className: 'muted small', textContent: 'מהאייקון האפליקציה נפתחת במסך מלא, ושם אפשר גם להפעיל התראות.' }),
        ];
    }
    if (isAndroid()) {
        // בכרום יש שתי אפשרויות שנראות דומות: "התקנת אפליקציה" מתקינה אפליקציה אמיתית (נפתחת
        // במסך מלא, מופיעה ברשימת האפליקציות); "הוספה למסך הבית" יוצרת רק קיצור דרך לדפדפן.
        return [
            el('ol', { className: 'steps' }, [
                step('פותחים את ', el('b', { textContent: 'תפריט הדפדפן ⋮' }), ' (למעלה בצד)'),
                step('בוחרים ', el('b', { textContent: '"התקנת אפליקציה"' }), ' (בסמסונג: "הוספת דף ל" ← "מסך הבית")'),
                step('לוחצים ', el('b', { textContent: '"התקנה"' }), ' — והאפליקציה מופיעה במסך הבית וברשימת האפליקציות'),
            ]),
            el('p', { className: 'note', textContent:
                'לא "הוספה למסך הבית" — זה רק קיצור דרך שנפתח בתוך הדפדפן. יש לכם כבר אייקון כזה? ' +
                'מוחקים אותו ומתקינים שוב דרך "התקנת אפליקציה".' }),
        ];
    }
    return [el('ol', { className: 'steps' }, [
        step('בכרום או ב-Edge: לוחצים על ', el('b', { textContent: 'סמל ההתקנה ⊕' }), ' בקצה שורת הכתובת'),
        step('או בתפריט ⋮ ← ', el('b', { textContent: '"התקנת כלים דיגיטליים"' })),
        step('האפליקציה תיפתח בחלון משלה, ותופיע בתפריט ההתחלה / ב-Dock'),
    ])];
}

function openInstallSheet() {
    closeMenu();
    const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
    const body = [];
    if (isInstalled() && !installPrompt) {
        body.push(el('p', { className: 'note ok', textContent:
            '✓ האפליקציה כבר מותקנת במכשיר הזה — פותחים אותה מהאייקון במסך הבית. ' +
            'אם הסרתם אותה, כך מתקינים שוב:' }));
    }
    if (installPrompt) {
        const go = el('button', { type: 'button', className: 'btn primary big', textContent: '📲 התקנה עכשיו' });
        go.addEventListener('click', installFamily);
        body.push(go);
    } else {
        body.push(...installSteps());
    }
    body.push(el('ul', { className: 'perks' }, [
        ['⚡', 'נפתח במסך מלא, כמו אפליקציה'],
        ['🔄', 'מתעדכן לבד — בלי חנות ובלי הורדות'],
        ['🗑️', 'אפשר להסיר בכל רגע, כמו כל אפליקציה'],
    ].map(([i, t]) => el('li', {}, [el('span', { textContent: i, ariaHidden: 'true' }), el('span', { textContent: t })]))));

    const dlg = el('dialog', { className: 'install' }, [el('div', { className: 'sheet' }, [
        el('div', { className: 'sheet-head' }, [
            logo(),
            el('div', { className: 'tx' }, [el('h2', { textContent: 'התקנת כלים דיגיטליים' }), el('small', { textContent: 'אייקון אחד במסך הבית — כל האפליקציות שלך בפנים' })]),
            close,
        ]),
        el('div', { className: 'sheet-body' }, body),
    ])]);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
}

// התקנה ישנה: לפני platform/home/ (README, "הפלטפורמה תמיד נפתחת על רשימת האפליקציות") הטווח של
// האפליקציה המותקנת היה כל האתר — כלי שנפתח ממנה נשאר בתוך החלון שלה, וחזרה לאייקון החזירה את הכלי
// האחרון. מנגנון דף האינטרנט לא יכול לתקן את זה בהתקנה הקיימת: רק התקנה מחדש (או עדכון שהדפדפן
// עושה לבד, לפעמים אחרי ימים). מזהים אותה כך: פתוחה כאפליקציה, אבל לא מ-platform/home/.
const oldInstall = () => isStandalone() && !location.pathname.includes('/platform/home/');

function oldInstallBanner() {
    if (!oldInstall()) return null;
    const how = el('button', { type: 'button', className: 'btn primary sm', textContent: 'איך?' });
    how.addEventListener('click', openReinstallSheet);
    return el('div', { className: 'install-banner update', role: 'alert' }, [
        el('span', { className: 'ib-ic', textContent: '⚠️', ariaHidden: 'true' }),
        el('div', { className: 'ib-tx' }, [
            el('b', { textContent: 'צריך להתקין מחדש' }),
            el('small', { textContent: 'בגרסה הזו אפליקציות נפתחות בתוך החלון הזה' }),
        ]),
        how,
    ]);
}

function openReinstallSheet() {
    const url = new URL('./', document.baseURI).href;
    const step = (html) => el('li', {}, [el('span', { className: 'step-tx', innerHTML: html })]);
    const steps = isIOS() ? [
        'לחיצה ארוכה על האייקון <b>כלים דיגיטליים</b> במסך הבית ← <b>"מחיקת אפליקציה"</b> (או "הסרת סימנייה")',
        `פותחים בספארי את <b dir="ltr">${url}</b>`,
        'שיתוף ← <b>"הוספה למסך הבית"</b> ← "הוספה"',
    ] : isAndroid() ? [
        'לחיצה ארוכה על האייקון <b>כלים דיגיטליים</b> במסך הבית ← <b>"הסרת התקנה"</b>',
        `פותחים בכרום את <b dir="ltr">${url}</b>`,
        'לוחצים <b>"התקנה"</b> בראש הדף (או ⋮ ← <b>"התקנת אפליקציה"</b>)',
    ] : [
        'בחלון הזה: תפריט ⋮ למעלה ← <b>"הסרת ההתקנה של כלים דיגיטליים"</b>',
        `פותחים בדפדפן את <b dir="ltr">${url}</b>`,
        'לוחצים על <b>סמל ההתקנה ⊕</b> בקצה שורת הכתובת',
    ];
    const close = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'סגירה' });
    const dlg = el('dialog', { className: 'install' }, [el('div', { className: 'sheet' }, [
        el('div', { className: 'sheet-head' }, [
            logo(),
            el('div', { className: 'tx' }, [el('h2', { textContent: 'התקנה מחדש' }), el('small', { textContent: 'פעם אחת — ומאז הכלים הדיגיטליים תמיד נפתחים על הרשימה' })]),
            close,
        ]),
        el('div', { className: 'sheet-body' }, [
            el('p', { className: 'lead', textContent: 'האפליקציה במכשיר הותקנה בגרסה ישנה, שבה כל אפליקציה נפתחת בתוך החלון של הכלים הדיגיטליים — ולכן חזרה לאייקון מחזירה את האפליקציה האחרונה. בגרסה החדשה כל אפליקציה נפתחת בחלון משלה.' }),
            el('ol', { className: 'steps' }, steps.map(step)),
        ]),
    ])]);
    close.addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
}

// פס הזמנה להתקנה בראש דף האפליקציות — עד שמתקינים או סוגרים אותו.
const BANNER_KEY = 'platform:install-banner';
function installBanner() {
    if (isInstalled()) return null;
    try { if (localStorage.getItem(BANNER_KEY) === 'no') return null; } catch { /* מצב פרטי */ }
    const go = el('button', { type: 'button', className: 'btn primary sm', textContent: 'התקנה' });
    go.addEventListener('click', openInstallSheet);
    const x = el('button', { type: 'button', className: 'icon-btn', textContent: '✕', ariaLabel: 'לא עכשיו' });
    const bar = el('div', { className: 'install-banner' }, [
        el('span', { className: 'ib-ic', textContent: '📲', ariaHidden: 'true' }),
        el('div', { className: 'ib-tx' }, [el('b', { textContent: 'התקינו במכשיר' }), el('small', { textContent: 'אייקון במסך הבית ופתיחה במסך מלא' })]),
        go, x,
    ]);
    x.addEventListener('click', () => { try { localStorage.setItem(BANNER_KEY, 'no'); } catch { /* */ } bar.remove(); });
    return bar;
}

const canDownload = () => 'caches' in window && currentApps.some(a => a.install.offline);

/**
 * "הורדת המשפחה": שומר במטמון את הדף של כל אפליקציה שמסומנת offline, ואת מה
 * שהוא טוען — סקריפטים (כולל import של מודולים, רמה אחת), עיצוב, תמונות,
 * וספריות מ-CDN. ה-service worker מגיש אותם כשאין רשת.
 */
async function downloadFamily() {
    const apps = currentApps.filter(a => a.install.offline);
    const cache = await caches.open(FAMILY_CACHE);
    const base = new URL('./', document.baseURI);
    const allowed = (u) => (u.origin === location.origin && u.pathname.startsWith(base.pathname)) || CDN_HOSTS.includes(u.hostname);
    let done = 0, failed = 0;

    async function save(url) {
        try { await cache.add(url); return true; } catch { failed++; return false; }
    }
    async function assetsOf(pageUrl, html) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const refs = [...doc.querySelectorAll('script[src], link[rel~="stylesheet"][href], link[rel~="icon"][href], link[rel="modulepreload"][href], img[src]')]
            .map(n => n.getAttribute('src') || n.getAttribute('href'));
        // סקריפט inline עם import — גם הוא טוען מודולים
        for (const sc of doc.querySelectorAll('script:not([src])')) refs.push(...importsIn(sc.textContent));
        return refs.map(r => { try { return new URL(r, pageUrl); } catch { return null; } }).filter(u => u && allowed(u));
    }
    function importsIn(code) {
        return [...code.matchAll(/(?:import|from)\s*\(?\s*['"]([^'"]+\.m?js)['"]/g)].map(m => m[1]);
    }

    for (const app of apps) {
        toast(`⏳ מוריד: ${app.name}…`);
        const page = new URL(app.href, document.baseURI);
        try {
            const res = await fetch(page, { credentials: 'same-origin' });
            if (!res.ok || res.redirected) { failed++; continue; }
            await cache.put(page, res.clone());
            const assets = await assetsOf(page, await res.text());
            for (const u of assets) {
                if (!(await save(u.href))) continue;
                if (/\.m?js$/.test(u.pathname) && u.origin === location.origin) {
                    const code = await (await cache.match(u.href)).text();
                    for (const r of importsIn(code)) {
                        const sub = new URL(r, u);
                        if (allowed(sub)) await save(sub.href);
                    }
                }
            }
            done++;
        } catch { failed++; }
    }
    toast(failed ? `✓ ${done} אפליקציות במכשיר (${failed} קבצים לא נשמרו)` : `✓ ${done} אפליקציות זמינות בלי רשת`);
}

// ביציאה: כל מה שהורד ורשימת האפליקציות נמחקים — מי שמקבל את המכשיר אחריך לא
// יראה את המשפחה שלך. המעטפת עצמה (הדף והקוד, בלי מידע אישי) נשמרת מחדש.
async function clearFamilyCache() {
    if (!('caches' in window)) return;
    try {
        await caches.delete(FAMILY_CACHE);
        const c = await caches.open(FAMILY_CACHE);
        await c.addAll(['./', 'index.html', 'platform/home/', 'platform/assets/app.js', 'platform/assets/admin.js',
            'platform/assets/app.css', 'platform/manifest.webmanifest', 'platform/assets/icons/icon-192.png']);
    } catch { /* בלי רשת — יימלא בפעם הבאה */ }
}

async function showApps() {
    const [{ categories, apps }] = await Promise.all([api('apps'), loadInstalls()]);
    currentApps = apps;
    if (!apps.length) {
        showMessage('עוד אין כאן אפליקציות', 'המנהל עדיין לא הקצה לכם אפליקציות. כשיקצה — הן יופיעו כאן.', '📭');
        return;
    }
    const first = me.user.display_name.trim().split(/\s+/)[0];
    const hello = el('div', { className: 'hello' }, [el('div', {}, [
        el('h1', { textContent: `שלום, ${first}` }),
        el('p', { textContent: apps.length === 1 ? 'אפליקציה אחת זמינה לך' : `${apps.length} אפליקציות זמינות לך` }),
    ])]);

    const sections = categories
        .map(cat => ({ cat, apps: apps.filter(a => a.category === cat.id) }))
        .filter(s => s.apps.length)
        .map(({ cat, apps }) => el('section', { className: 'category' }, [
            el('h2', { className: 'category-title' }, [
                el('span', { textContent: cat.icon, ariaHidden: 'true' }),
                el('span', { textContent: cat.name }),
                el('span', { className: 'n', textContent: `· ${apps.length}` }),
            ]),
            el('ul', { className: 'apps' }, apps.map(appRow)),
        ]));

    const empty = el('p', { className: 'no-results', textContent: 'לא נמצאה אפליקציה בשם הזה', hidden: true });
    const parts = [hello];
    const banner = oldInstallBanner() || installBanner();
    if (banner) parts.unshift(banner);
    // חיפוש + סינון לפי המכשיר הזה: הכול / מותקנות / אפשר להתקין.
    let q = '', only = 'all';
    function applyFilter() {
        let any = false;
        for (const sec of sections) {
            let shown = 0;
            for (const li of sec.querySelectorAll('li')) {
                const hit = (!q || li.dataset.search.includes(q))
                    && (only === 'all' || (only === 'yes' ? li.dataset.inst === 'yes' : li.dataset.inst === 'can'));
                li.hidden = !hit;
                if (hit) shown++;
            }
            sec.hidden = !shown;
            any ||= shown > 0;
        }
        empty.textContent = only === 'yes' ? 'עוד לא סימנת אפליקציה כמותקנת במכשיר הזה'
            : only === 'can' ? 'כל מה שאפשר להתקין — כבר מותקן כאן ✓' : 'לא נמצאה אפליקציה בשם הזה';
        empty.hidden = any;
    }
    if (apps.length > 4) {
        const input = el('input', { type: 'search', placeholder: 'חיפוש אפליקציה…', ariaLabel: 'חיפוש אפליקציה', autocomplete: 'off' });
        input.addEventListener('input', () => { q = input.value.trim().toLowerCase(); applyFilter(); });
        parts.push(el('div', { className: 'search' }, [el('span', { className: 'ic', textContent: '🔍', ariaHidden: 'true' }), input]));
    }
    if (deviceId() && apps.some(a => a.install.pwa || a.install.apk || a.install.store)) {
        const seg = el('div', { className: 'seg dev-filter', role: 'radiogroup', ariaLabel: 'במכשיר הזה' },
            [['all', 'הכול'], ['yes', '✓ מותקנות כאן'], ['can', '📲 אפשר להתקין']].map(([v, t]) => {
                const r = el('input', { type: 'radio', name: 'devf', value: v, checked: v === 'all' });
                r.addEventListener('change', () => { only = v; applyFilter(); });
                return el('label', {}, [r, el('span', { textContent: t })]);
            }));
        parts.push(seg);
    }
    view.replaceChildren(...parts, ...sections, empty);
}

// ---------- ניתוב ----------

// השער מפנה לכאן עם ?next=<הכתובת שבוקשה>. אחרי כניסה — חוזרים אליה, רק אם
// היא נתיב באותו אתר (לא //אתר-אחר ולא https://…).
// ?next מכובד רק מיד אחרי כניסה (או החלפת סיסמה זמנית): השער או הכניסה המאוחדת שלחו לכאן
// משתמש לא מחובר, והוא נכנס עכשיו. בכל מצב אחר הפרמטר נמחק מהכתובת — אחרת כתובת עם ?next
// שנשמרה (סימנייה, היסטוריה, האפליקציה המותקנת) הייתה מקפיצה כל פעם לאפליקציה במקום לפלטפורמה.
let justSignedIn = false;

function goNext() {
    const next = new URLSearchParams(location.search).get('next');
    if (next && next.startsWith('/') && !next.startsWith('//')) {
        // מה שאחרי # (#invite=…, #reset=…) לא מגיע לשרת, ולכן אינו ב-?next. הדפדפן משאיר אותו
        // בכתובת אחרי הפניית השער (הפניה בלי # שומרת את ה-# המקורי) — ומכאן מחזירים אותו לכלי.
        dlog('go-next', { next });
        location.replace(next.includes('#') ? next : next + location.hash);
        return true;
    }
    return false;
}

async function start() {
    closeMenu();
    try {
        await consumeVerifyLink();
        me = await api('me');
        csrf = me.csrf;
        showTopbar();
        showUserbar(me.user);
        if (me.setupNeeded) return showSetup(me);
        if (!me.user) { dlog('show-login', { next: new URLSearchParams(location.search).get('next') || '' }); return showLogin(); }
        // ממתין ועוד לא אימת את המייל. מי שהמנהל כבר אישר — נכנס גם בלי (המנהל החליט).
        if (me.user.status === 'pending' && !me.user.email_verified) return showVerifyPending(me.user);
        if (me.user.status === 'pending') {
            return showMessage('החשבון ממתין לאישור',
                'נרשמתם בהצלחה. המנהל צריך לאשר את החשבון ולבחור אילו אפליקציות יוצגו לכם.', '⏳');
        }
        if (me.user.must_change) return showPassword(true);
        if (justSignedIn && goNext()) return;
        justSignedIn = false;
        if (new URLSearchParams(location.search).has('next')) history.replaceState(null, '', location.pathname + location.hash);
        if (inAdmin() && me.user.role === 'admin') {
            return await window.PlatformAdmin.show(view);
        }
        await showApps();
    } catch (err) {
        view.replaceChildren(el('p', {
            className: 'status error',
            textContent: 'לא ניתן לטעון את הדף. נסו לרענן.',
        }));
    }
}

// עזרים משותפים ל-admin.js. setPending — אחרי אישור או דחייה, כדי שהמונה בכותרת יתעדכן.
window.Platform = {
    el, api, showMessage, toast, avatar, hue,
    setPending(n) { if (me) { me.pending = n; showUserbar(me.user); } },
};

window.addEventListener('hashchange', () => { start(); window.scrollTo(0, 0); });
// באפליקציה המותקנת הדף יושב ב-platform/home/ עם <base> לשורש — וקישור "#admin" היה מוביל לשורש
// (מחוץ לאפליקציה). קישורי # מנותבים כאן, בתוך הדף הנוכחי.
document.addEventListener('click', (e) => {
    const a = e.target.closest?.('a[href^="#"]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    const hash = a.getAttribute('href');
    if ((location.hash || '#') === hash) start(); else location.hash = hash;
});
start();
