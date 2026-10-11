// המעטפת של הפלטפורמה: כניסה, הרשמה, התקנת המנהל הראשון, והאפליקציות של המשתמש.
// כל הטקסט מוצב כ-textContent — שום ערך מהשרת או מ-app.json אינו מפוענח כ-HTML.

const API = 'platform/api.php';
const view = document.getElementById('view');
const userbar = document.getElementById('userbar');
let csrf = null;

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
    return el('div', { className: 'panel' }, [
        el('h2', { textContent: title }),
        el('p', { className: 'lead', textContent: lead }),
        f,
        ...footer,
    ]);
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
        view.replaceChildren(el('div', { className: 'panel' }, [
            el('h2', { textContent: 'ההתקנה ממתינה לקוד' }),
            el('p', { className: 'lead', textContent:
                'הסוד PLATFORM_SETUP_CODE לא הגיע לשרת. מגדירים אותו ב-GitHub ' +
                '(Settings ← Secrets and variables ← Actions), והוא יגיע בפריסה הבאה.' }),
        ]));
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
        title: 'כניסה',
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

function showMessage(title, text) {
    view.replaceChildren(el('div', { className: 'panel' }, [
        el('h2', { textContent: title }),
        el('p', { className: 'lead', textContent: text }),
    ]));
}

function showUserbar(user) {
    if (!user) { userbar.replaceChildren(); return; }
    const logout = el('button', { type: 'button', textContent: 'יציאה' });
    logout.addEventListener('click', async () => {
        try { await api('logout', {}); } finally {
            csrf = null;
            await clearFamilyCache();
            start();
        }
    });
    const role = user.role === 'admin' ? ' · מנהל' : '';
    const actions = [...familyActions(), logout];
    if (user.role === 'admin') {
        const inAdmin = location.hash === '#admin';
        actions.unshift(el('a', {
            href: inAdmin ? '#' : '#admin',
            textContent: inAdmin ? '← לאפליקציות' : '⚙️ ניהול',
        }));
    }
    userbar.replaceChildren(el('div', { className: 'userbar' }, [
        el('span', { textContent: `שלום, ${user.display_name}${role}` }),
        el('span', { className: 'userbar-actions' }, actions),
    ]));
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
    const label = btn.textContent;
    btn.textContent = '✓ הועתק';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = label; btn.classList.remove('copied'); }, 1800);
}

// כרטיס עם כמה כניסות: הכרטיס עצמו אינו קישור, וליד כל כניסה כפתור העתקה.
function card(app) {
    const head = [
        el('div', { className: 'icon', textContent: app.icon }),
        el('h2', { textContent: app.name }),
        el('p', { textContent: app.description }),
    ];
    const extras = installLinks(app);
    if (!app.links.length && !extras.length) {
        return el('a', { className: 'project-card', href: app.href }, head);
    }
    const entries = app.links.length ? app.links : [{ label: '▶️ פתיחה', href: app.href }];
    const links = entries.map(link => {
        const btn = el('button', { type: 'button', textContent: '📋 העתקת קישור' });
        btn.addEventListener('click', () => copyLink(btn, link.href));
        return el('div', { className: 'entry-link' }, [
            el('a', { href: link.href, textContent: link.label }), btn,
        ]);
    });
    return el('div', { className: 'project-card multi' }, [
        ...head, el('div', { className: 'entry-links' }, links),
        ...(extras.length ? [el('div', { className: 'install-links' }, extras)] : []),
    ]);
}

// ---------- התקנה והורדה (שלב 5) ----------

// מה שהמנהל אישר לאפליקציה: התקנה נפרדת, APK, חנות. השרת לא שולח את מה שלא אושר.
function installLinks(app) {
    const out = [];
    if (app.install.pwa) {
        out.push(el('a', {
            href: app.href, textContent: '📲 התקנה כאפליקציה נפרדת',
            title: 'פתחו את האפליקציה, ובתפריט הדפדפן בחרו "התקנה" או "הוספה למסך הבית"',
        }));
    }
    if (app.install.apk) out.push(el('a', { href: app.install.apk, textContent: '⬇️ הורדת APK', rel: 'noopener' }));
    if (app.install.store) out.push(el('a', { href: app.install.store, textContent: '▶️ Google Play', rel: 'noopener', target: '_blank' }));
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
    start();
});

function familyActions() {
    const out = [];
    if (installPrompt) {
        const b = el('button', { type: 'button', textContent: '📲 התקנת המשפחה' });
        b.addEventListener('click', async () => {
            installPrompt.prompt();
            await installPrompt.userChoice;
            installPrompt = null;
            start();
        });
        out.push(b);
    }
    if ('caches' in window && currentApps.some(a => a.install.offline)) {
        const b = el('button', { type: 'button', textContent: '⬇️ הורדה למכשיר' });
        b.title = 'שומר במכשיר את האפליקציות שעובדות בלי רשת';
        b.addEventListener('click', () => downloadFamily(b));
        out.push(b);
    }
    return out;
}

/**
 * "הורדת המשפחה": שומר במטמון את הדף של כל אפליקציה שמסומנת offline, ואת מה
 * שהוא טוען — סקריפטים (כולל import של מודולים, רמה אחת), עיצוב, תמונות,
 * וספריות מ-CDN. ה-service worker מגיש אותם כשאין רשת.
 */
async function downloadFamily(btn) {
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
        btn.textContent = `⏳ ${app.name}…`;
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
    btn.textContent = failed ? `✓ ${done} אפליקציות במכשיר (${failed} קבצים לא נשמרו)` : `✓ ${done} אפליקציות במכשיר`;
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
        showMessage('עוד אין כאן אפליקציות', 'המנהל עדיין לא הקצה לכם אפליקציות. כשיקצה — הן יופיעו כאן.');
        return;
    }
    const sections = categories
        .map(cat => ({ cat, apps: apps.filter(a => a.category === cat.id) }))
        .filter(s => s.apps.length)
        .map(({ cat, apps }) => el('section', { className: 'category' }, [
            el('h2', { className: 'category-title', textContent: `${cat.icon} ${cat.name}` }),
            el('div', { className: 'projects-grid' }, apps.map(card)),
        ]));
    view.replaceChildren(...sections);
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
    try {
        const me = await api('me');
        csrf = me.csrf;
        showUserbar(me.user);
        if (me.setupNeeded) return showSetup(me);
        if (!me.user) return showLogin();
        if (me.user.status === 'pending') {
            return showMessage('החשבון ממתין לאישור',
                'נרשמתם בהצלחה. המנהל צריך לאשר את החשבון ולבחור אילו אפליקציות יוצגו לכם.');
        }
        if (goNext()) return;
        if (location.hash === '#admin' && me.user.role === 'admin') {
            return await window.PlatformAdmin.show(view);
        }
        await showApps();
        showUserbar(me.user); // כפתור "הורדה למכשיר" תלוי ברשימה שנטענה עכשיו
    } catch (err) {
        view.replaceChildren(el('p', {
            className: 'status error',
            textContent: 'לא ניתן לטעון את הדף. נסו לרענן.',
        }));
    }
}

// עזרים משותפים ל-admin.js.
window.Platform = { el, api, showMessage };

window.addEventListener('hashchange', start);
start();
