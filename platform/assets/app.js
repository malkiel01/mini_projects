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

const USERNAME_HINT = 'באנגלית, או כתובת המייל שלכם';

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
        onSubmit: async (data) => { await api('setup', data); start(); },
    }));
}

function showLogin() {
    view.replaceChildren(form({
        title: 'ברוכים הבאים',
        lead: 'נכנסים כדי לראות את האפליקציות שלכם.',
        fields: [
            field('שם משתמש', 'username', { ltr: true, autocomplete: 'username' }),
            field('סיסמה', 'password', { type: 'password', ltr: true, autocomplete: 'current-password' }),
        ],
        submit: 'כניסה',
        onSubmit: async (data) => { await api('login', data); start(); },
        footer: [switchLink('אין לכם חשבון?', 'הרשמה', showRegister)],
    }));
}

function showRegister() {
    view.replaceChildren(form({
        title: 'הרשמה',
        lead: 'אחרי ההרשמה המנהל מאשר את החשבון ובוחר אילו אפליקציות יוצגו לכם.',
        fields: [
            field('השם שלכם', 'display_name', { autocomplete: 'name' }),
            field('שם משתמש', 'username', { ltr: true, hint: USERNAME_HINT, autocomplete: 'username' }),
            field('סיסמה', 'password', { type: 'password', ltr: true, hint: 'לפחות 8 תווים', autocomplete: 'new-password' }),
        ],
        submit: 'הרשמה',
        onSubmit: async (data) => { await api('register', data); start(); },
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
        onSubmit: async (data) => { await api('password', data); toast('✓ הסיסמה הוחלפה'); start(); },
        footer,
    }));
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
    const url = new URL(href, location.href).href;
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
            el('b', { textContent: app.name }),
            el('small', { textContent: app.description }),
        ]),
        el('span', { className: 'app-go', textContent: '‹', ariaHidden: 'true' }),
    ];
    const extras = installLinks(app);
    let node;
    if (!app.links.length && !extras.length) {
        node = el('a', { className: 'app', href: app.href }, body);
    } else {
        node = el('button', { type: 'button', className: 'app', ariaHasPopup: 'dialog' }, body);
        node.addEventListener('click', () => openAppSheet(app, extras));
    }
    const li = el('li', {}, [node]);
    li.dataset.search = `${app.name} ${app.description}`.toLowerCase();
    return li;
}

function openAppSheet(app, extras) {
    const entries = app.links.length ? app.links : [{ label: '▶️ פתיחה', href: app.href }];
    const rows = entries.map(link => {
        const copy = el('button', { type: 'button', className: 'icon-btn', textContent: '🔗', ariaLabel: `העתקת קישור: ${link.label}`, title: 'העתקת קישור' });
        copy.addEventListener('click', () => copyLink(copy, link.href));
        return el('div', { className: 'entry' }, [el('a', { href: link.href, textContent: link.label }), copy]);
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

// מה שהמנהל אישר לאפליקציה: התקנה נפרדת, APK, חנות. השרת לא שולח את מה שלא אושר.
function installLinks(app) {
    const tile = (icon, text, props) => el('a', { className: 'tile', ...props }, [
        el('span', { textContent: icon, ariaHidden: 'true' }), el('span', { className: 'one', textContent: text }),
    ]);
    const out = [];
    if (app.install.pwa) {
        out.push(tile('📲', 'התקנה כאפליקציה נפרדת', {
            href: app.href,
            title: 'פתחו את האפליקציה, ובתפריט הדפדפן בחרו "התקנה" או "הוספה למסך הבית"',
        }));
    }
    if (app.install.apk) out.push(tile('⬇️', 'הורדת APK', { href: app.install.apk, rel: 'noopener' }));
    if (app.install.store) out.push(tile('▶️', 'Google Play', { href: app.install.store, rel: 'noopener', target: '_blank' }));
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
    if (me?.user) showUserbar(me.user);
});

window.addEventListener('appinstalled', () => {
    installPrompt = null;
    markInstalled(true);
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

if (isStandalone()) markInstalled(true);
if ('getInstalledRelatedApps' in navigator) {
    navigator.getInstalledRelatedApps()
        .then(apps => { if (apps.some(a => a.platform === 'webapp')) markInstalled(true); })
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
        return [el('ol', { className: 'steps' }, [
            step('פותחים את ', el('b', { textContent: 'תפריט הדפדפן ⋮' }), ' (למעלה בצד)'),
            step('בוחרים ', el('b', { textContent: '"התקנת אפליקציה"' }), ' או ', el('b', { textContent: '"הוספה למסך הבית"' })),
            step('מאשרים — והאייקון מופיע במסך הבית'),
        ])];
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
    const base = new URL('./', location.href);
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
        const page = new URL(app.href, location.href);
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
        await c.addAll(['./', 'index.html', 'platform/assets/app.js', 'platform/assets/admin.js',
            'platform/assets/app.css', 'platform/manifest.webmanifest', 'platform/assets/icons/icon-192.png']);
    } catch { /* בלי רשת — יימלא בפעם הבאה */ }
}

async function showApps() {
    const { categories, apps } = await api('apps');
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
    const banner = installBanner();
    if (banner) parts.unshift(banner);
    if (apps.length > 4) {
        const input = el('input', { type: 'search', placeholder: 'חיפוש אפליקציה…', ariaLabel: 'חיפוש אפליקציה', autocomplete: 'off' });
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            let any = false;
            for (const sec of sections) {
                let shown = 0;
                for (const li of sec.querySelectorAll('li')) {
                    const hit = !q || li.dataset.search.includes(q);
                    li.hidden = !hit;
                    if (hit) shown++;
                }
                sec.hidden = !shown;
                any ||= shown > 0;
            }
            empty.hidden = any;
        });
        parts.push(el('div', { className: 'search' }, [el('span', { className: 'ic', textContent: '🔍', ariaHidden: 'true' }), input]));
    }
    view.replaceChildren(...parts, ...sections, empty);
}

// ---------- ניתוב ----------

// השער מפנה לכאן עם ?next=<הכתובת שבוקשה>. אחרי כניסה — חוזרים אליה, רק אם
// היא נתיב באותו אתר (לא //אתר-אחר ולא https://…).
function goNext() {
    const next = new URLSearchParams(location.search).get('next');
    if (next && next.startsWith('/') && !next.startsWith('//')) {
        location.replace(next);
        return true;
    }
    return false;
}

async function start() {
    closeMenu();
    try {
        me = await api('me');
        csrf = me.csrf;
        showTopbar();
        showUserbar(me.user);
        if (me.setupNeeded) return showSetup(me);
        if (!me.user) return showLogin();
        if (me.user.status === 'pending') {
            return showMessage('החשבון ממתין לאישור',
                'נרשמתם בהצלחה. המנהל צריך לאשר את החשבון ולבחור אילו אפליקציות יוצגו לכם.', '⏳');
        }
        if (me.user.must_change) return showPassword(true);
        if (goNext()) return;
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
start();
