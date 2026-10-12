// כניסה, הרשמה, הקמת המפתח ושחזור סיסמה — בחשבונות של האפליקציה עצמה
// (lib/auth.php). אין כאן שום קשר לחשבונות של דף הכלים.
//
// מצבים: login · register · setup (אין עדיין משתמשים) · forgot (קישור לאימייל) ·
//        reset (נפתח מקישור: login.html#reset=…)
'use strict';

// ---- שגיאות בדפדפן נשלחות ליומן (הגדרות ← יומן) ----
(() => {
    let sent = 0;
    const report = (kind, message, extra = {}) => {
        if (++sent > 10) return;
        fetch('api.php?action=client-error', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-PM': '1' },
            body: JSON.stringify({ kind, message: String(message).slice(0, 1000), page: location.pathname, ...extra }),
        }).catch(() => {});
    };
    addEventListener('error', e => report('error', e.message, { source: e.filename, line: e.lineno, col: e.colno, stack: e.error?.stack?.slice(0, 2000) }));
    addEventListener('unhandledrejection', e => report('promise', e.reason?.message || e.reason, { stack: e.reason?.stack?.slice(0, 2000) }));
})();

const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
let csrf = null, mode = 'login', resetToken = null, state = {};

async function call(action, body) {
    const opts = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body) {
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-PM'] = '1'; // בקשות שלפני הכניסה נדחות בלעדיה (הגנת CSRF)
        if (csrf) opts.headers['X-CSRF'] = csrf;
        opts.body = JSON.stringify(body);
    }
    const res = await fetch('api.php?action=' + action, opts);
    let data = {};
    try { data = await res.json(); } catch { /* לא JSON */ }
    return { status: res.status, data };
}

const HINTS = {
    setup: 'הקמת האפליקציה: חשבון המפתח. נדרש קוד ההקמה — הסוד PM_RECOVERY_CODE שהוגדר ב-GitHub.',
    forgot: 'נשלח קישור לקביעת סיסמה חדשה לאימייל הרשום בחשבון.',
    reset: '',
};
const SUBMIT = { login: 'כניסה', register: 'שליחת בקשה', setup: 'הקמת המפתח', forgot: 'שליחת קישור', reset: 'קביעת סיסמה' };

function setMode(m) {
    mode = m;
    $$('[data-modes]').forEach(el => { el.hidden = !el.dataset.modes.split(' ').includes(m); });
    $('.auth-tabs').hidden = !['login', 'register'].includes(m);
    if (['login', 'register'].includes(m)) $(`[name=mode][value=${m}]`).checked = true;
    $('#mode-hint').textContent = HINTS[m] || '';
    $('#mode-hint').hidden = !HINTS[m];
    $('#auth-submit').textContent = SUBMIT[m];
    $('.lbl-user').textContent = m === 'login' || m === 'forgot' ? 'שם משתמש או אימייל' : 'שם משתמש (באנגלית)';
    $('.lbl-email').textContent = m === 'setup' ? 'אימייל (לשחזור סיסמה)' : 'אימייל';
    $('.lbl-pass').textContent = ['register', 'setup', 'reset'].includes(m) ? 'סיסמה חדשה (לפחות 8 תווים)' : 'סיסמה';
    $('[name=password]').autocomplete = m === 'login' ? 'current-password' : 'new-password';
    $('#auth-error').hidden = true;
    $('#auth-ok').hidden = true;
    $('#auth-form').hidden = false;
    $('#waiting').hidden = true;
}

function showError(msg) {
    $('#auth-ok').hidden = true;
    $('#auth-error').textContent = msg;
    $('#auth-error').hidden = false;
}

function showOk(msg) {
    $('#auth-error').hidden = true;
    $('#auth-ok').textContent = msg;
    $('#auth-ok').hidden = false;
}

function showWaiting(title, text, canRetry = true) {
    $('#auth-form').hidden = true;
    $('.auth-tabs').hidden = true;
    $('#mode-hint').hidden = true;
    $('#waiting').hidden = false;
    $('#waiting-title').textContent = title;
    $('#waiting-text').textContent = text;
    $('#recheck').hidden = !canRetry;
    $('#logout').hidden = !csrf;
}

/** לאן ממשיכים: לאפליקציה, למסך "ממתין", להקמה, לאיפוס, או לכניסה. */
async function route() {
    const { data } = await call('auth');
    state = data;
    csrf = data.csrf || null;
    const user = data.user;
    if (resetToken) return startReset();
    if (data.setup_needed) {
        if (!data.recovery_set) {
            showWaiting('האפליקציה עוד לא הוקמה', 'כדי להקים את חשבון המפתח צריך קוד הקמה: להוסיף ב-GitHub (Settings ← Secrets) את הסוד PM_RECOVERY_CODE, ולפרוס.');
            return;
        }
        setMode('setup');
        return;
    }
    if (!user) { if (!['forgot', 'register'].includes(mode)) setMode('login'); return; }
    if (user.status === 'active') { location.replace('./'); return; }
    if (user.status === 'pending') {
        showWaiting('ההרשמה התקבלה', `שלום ${user.display_name}. הבקשה ממתינה לאישור המפתח. אחרי האישור תוכל להיכנס מכאן.`);
        return;
    }
    showWaiting('החשבון חסום', 'פנה למפתח.', false);
}

async function startReset() {
    const r = await call('reset-info', { token: resetToken });
    if (r.status !== 200) {
        resetToken = null;
        history.replaceState(null, '', location.pathname);
        setMode('forgot');
        showError(r.data.error || 'הקישור לא תקף');
        return;
    }
    setMode('reset');
    $('#mode-hint').textContent = `שלום ${r.data.display_name}. בחר סיסמה חדשה לחשבון ${r.data.username}.`;
    $('#mode-hint').hidden = false;
}

async function submit(e) {
    e.preventDefault();
    const f = e.target.elements;
    const v = n => f[n].value.trim();
    let action = mode, body;
    if (mode === 'login') body = { username: v('username'), password: f.password.value };
    if (mode === 'register') body = { display_name: v('display_name'), email: v('email'), phone: v('phone'), username: v('username'), password: f.password.value };
    if (mode === 'setup') body = { code: f.code.value, display_name: v('display_name'), email: v('email'), username: v('username'), password: f.password.value };
    if (mode === 'forgot') body = { login: v('username') };
    if (mode === 'reset') { action = 'reset-password'; body = { token: resetToken, password: f.password.value }; }

    if (mode === 'forgot' && !body.login) return showError('יש למלא שם משתמש או אימייל');
    if (['login', 'register', 'setup'].includes(mode) && (!body.username || !body.password)) return showError('יש למלא שם משתמש וסיסמה');
    if (['register', 'setup'].includes(mode) && !body.display_name) return showError('יש למלא שם מלא');
    if (mode === 'register' && !body.email) return showError('יש למלא אימייל — דרכו משחזרים סיסמה');
    if (mode === 'reset' && f.password.value !== f.password2.value) return showError('הסיסמאות לא זהות');
    if (mode === 'setup' && !body.code) return showError('יש למלא את קוד ההקמה');

    const btn = $('#auth-submit');
    btn.disabled = true;
    try {
        const r = await call(action, body);
        if (r.status !== 200) return showError(r.data.error || 'תקלה, נסה שוב');
        f.password.value = f.password2.value = f.code.value = '';
        if (mode === 'forgot') return showOk('אם יש חשבון עם אימייל רשום — נשלח אליו קישור. אם לא הגיע תוך כמה דקות, בדוק בספאם או פנה למפתח.');
        if (mode === 'reset') {
            resetToken = null;
            history.replaceState(null, '', location.pathname);
            if (r.data.blocked) return showWaiting('הסיסמה עודכנה', 'אבל החשבון חסום — פנה למפתח.', false);
        }
        await route();
    } catch {
        showError('אין חיבור לשרת');
    } finally {
        btn.disabled = false;
    }
}

async function logout() {
    await call('logout', {});
    csrf = null;
    await route();
}

$$('[name=mode]').forEach(r => r.addEventListener('change', () => setMode(r.value)));
$$('[data-go]').forEach(b => b.addEventListener('click', () => setMode(b.dataset.go)));
$('#auth-form').addEventListener('submit', submit);
$('#recheck').addEventListener('click', () => route());
$('#logout').addEventListener('click', logout);

const m = location.hash.match(/reset=([a-f0-9]{48})/);
if (m) resetToken = m[1];
$('#auth-form').hidden = true; // עד שיתברר אם כבר מחובר
route().catch(() => { setMode('login'); showError('אין חיבור לשרת'); });
