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
        try { await api('logout', {}); } finally { csrf = null; start(); }
    });
    const role = user.role === 'admin' ? ' · מנהל' : '';
    const actions = [logout];
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
    if (!app.links.length) {
        return el('a', { className: 'project-card', href: app.href }, head);
    }
    const links = app.links.map(link => {
        const btn = el('button', { type: 'button', textContent: '📋 העתקת קישור' });
        btn.addEventListener('click', () => copyLink(btn, link.href));
        return el('div', { className: 'entry-link' }, [
            el('a', { href: link.href, textContent: link.label }), btn,
        ]);
    });
    return el('div', { className: 'project-card multi' }, [
        ...head, el('div', { className: 'entry-links' }, links),
    ]);
}

async function showApps() {
    const { categories, apps } = await api('apps');
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
