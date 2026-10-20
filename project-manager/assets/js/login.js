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
            headers: { 'Content-Type': 'application/json', 'X-PM': '1', 'X-Lang': I18n.lang() },
            body: JSON.stringify({ kind, message: String(message).slice(0, 1000), page: location.pathname, ...extra }),
        }).catch(() => {});
    };
    addEventListener('error', e => report('error', e.message, { source: e.filename, line: e.lineno, col: e.colno, stack: e.error?.stack?.slice(0, 2000) }));
    addEventListener('unhandledrejection', e => report('promise', e.reason?.message || e.reason, { stack: e.reason?.stack?.slice(0, 2000) }));
})();

// השדה שבפוקוס נגלל למרכז, כדי שלא יישאר מתחת למקלדת בטלפון
document.addEventListener('focusin', e => {
    if (e.target.matches('input, textarea, select')) setTimeout(() => e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }), 300);
});

const $ = (s, root = document) => root.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
let csrf = null, mode = 'login', resetToken = null, state = {};

async function call(action, body) {
    const opts = { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Lang': I18n.lang() } };
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

// מוצגים דרך t()
// i18n-ok-start
const HINTS = {
    setup: 'הקמת האפליקציה: חשבון המפתח. נדרש קוד ההקמה — הסוד PM_RECOVERY_CODE שהוגדר ב-GitHub.',
    forgot: 'נשלח קישור לקביעת סיסמה חדשה לאימייל הרשום בחשבון.',
    reset: '',
};
const SUBMIT = { login: 'כניסה', register: 'שליחת בקשה', setup: 'הקמת המפתח', forgot: 'שליחת קישור', reset: 'קביעת סיסמה' };
// i18n-ok-end

function setMode(m) {
    mode = m;
    $$('[data-modes]').forEach(el => { el.hidden = !el.dataset.modes.split(' ').includes(m); });
    $('.auth-tabs').hidden = !['login', 'register'].includes(m);
    if (['login', 'register'].includes(m)) $(`[name=mode][value=${m}]`).checked = true;
    $('#mode-hint').textContent = HINTS[m] ? t(HINTS[m]) : '';
    $('#mode-hint').hidden = !HINTS[m];
    $('#auth-submit').textContent = t(SUBMIT[m]);
    $('.lbl-user').textContent = m === 'login' || m === 'forgot' ? t('שם משתמש או אימייל') : t('שם משתמש (באנגלית)');
    $('.lbl-email').textContent = m === 'setup' ? t('אימייל (לשחזור סיסמה)') : t('אימייל');
    $('.lbl-pass').textContent = ['register', 'setup', 'reset'].includes(m) ? t('סיסמה חדשה (לפחות 8 תווים)') : t('סיסמה');
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
    $('#logout').hidden = !csrf || !!state.platform; // מחוברת — יוצאים מהכלים הדיגיטליים, לא מכאן
}

/** לאן ממשיכים: לאפליקציה, למסך "ממתין", להקמה, לאיפוס, או לכניסה. */
async function route() {
    const { data } = await call('auth');
    state = data;
    csrf = data.csrf || null;
    const user = data.user;
    // מחוברת לפלטפורמה: אין כאן כניסה בסיסמה. מחובר לכלים הדיגיטליים עם הרשאה — נכנס לבד (lib/platform.php);
    // לא — הכניסה דרך השער (platform/handoff.php); אין מכאן קישור לכלים הדיגיטליים
    if (data.platform && !user) {
        showWaiting(t('הכניסה היא דרך הכלים הדיגיטליים'), t('נכנסים פעם אחת לכלים הדיגיטליים — ומשם האפליקציה נפתחת לבד, בלי סיסמה נוספת.'));
        $('#recheck').hidden = true;
        $('.big', $('#waiting')).textContent = '🔐';
        return;
    }
    if (resetToken) return startReset();
    if (data.setup_needed) {
        if (!data.recovery_set) {
            showWaiting(t('האפליקציה עוד לא הוקמה'), t('כדי להקים את חשבון המפתח צריך קוד הקמה: להוסיף ב-GitHub (Settings ← Secrets) את הסוד PM_RECOVERY_CODE, ולפרוס.'));
            return;
        }
        setMode('setup');
        return;
    }
    if (!user) { if (!['forgot', 'register'].includes(mode)) setMode('login'); return; }
    if (user.status === 'active') { location.replace('./'); return; }
    if (user.status === 'pending') {
        showWaiting(t('ההרשמה התקבלה'), t('שלום {name}. הבקשה ממתינה לאישור המפתח. אחרי האישור תוכל להיכנס מכאן.', { name: user.display_name }));
        return;
    }
    showWaiting(t('החשבון חסום'), t('פנה למפתח.'), false);
}

async function startReset() {
    const r = await call('reset-info', { token: resetToken });
    if (r.status !== 200) {
        resetToken = null;
        history.replaceState(null, '', location.pathname);
        // קישור שכבר שומש, ומי שפתח אותו כבר מחובר — פשוט נכנסים
        if (state.user?.status === 'active') { location.replace('./'); return; }
        setMode('login');
        showError(t('הקישור כבר שומש או שפג. אם כבר קבעת סיסמה חדשה — היכנס איתה כאן. אם לא — "שכחתי סיסמה" או בקש מהמפתח קישור חדש.'));
        return;
    }
    setMode('reset');
    $('#mode-hint').textContent = t('שלום {name}. בחר סיסמה חדשה לחשבון {username}.', { name: r.data.display_name, username: r.data.username });
    $('#mode-hint').hidden = false;
}

async function submit(e) {
    e.preventDefault();
    const f = e.target.elements;
    const v = n => f[n].value.trim();
    let action = mode, body;
    // השפה שנבחרה במסך הכניסה נשמרת בחשבון ('' — לא נבחרה)
    const lang = I18n.chosen() ? I18n.lang() : '';
    if (mode === 'login') body = { username: v('username'), password: f.password.value, lang };
    if (mode === 'register') body = { display_name: v('display_name'), email: v('email'), phone: v('phone'), username: v('username'), password: f.password.value, lang };
    if (mode === 'setup') body = { code: f.code.value, display_name: v('display_name'), email: v('email'), username: v('username'), password: f.password.value };
    if (mode === 'forgot') body = { login: v('username') };
    if (mode === 'reset') { action = 'reset-password'; body = { token: resetToken, password: f.password.value }; }

    if (mode === 'forgot' && !body.login) return showError(t('יש למלא שם משתמש או אימייל'));
    if (['login', 'register', 'setup'].includes(mode) && (!body.username || !body.password)) return showError(t('יש למלא שם משתמש וסיסמה'));
    if (['register', 'setup'].includes(mode) && !body.display_name) return showError(t('יש למלא שם מלא'));
    if (mode === 'register' && !body.email) return showError(t('יש למלא אימייל — דרכו משחזרים סיסמה'));
    if (mode === 'reset' && f.password.value !== f.password2.value) return showError(t('הסיסמאות לא זהות'));
    if (mode === 'setup' && !body.code) return showError(t('יש למלא את קוד ההקמה'));

    const btn = $('#auth-submit');
    btn.disabled = true;
    try {
        const r = await call(action, body);
        if (r.status !== 200) return showError(r.data.error || t('תקלה, נסה שוב'));
        f.password.value = f.password2.value = f.code.value = '';
        if (mode === 'forgot') return showOk(t('אם יש חשבון עם אימייל רשום — נשלח אליו קישור. אם לא הגיע תוך כמה דקות, בדוק בספאם או פנה למפתח.'));
        if (mode === 'reset') {
            resetToken = null;
            history.replaceState(null, '', location.pathname);
            if (r.data.blocked) return showWaiting(t('הסיסמה עודכנה'), t('אבל החשבון חסום — פנה למפתח.'), false);
        }
        await route();
    } catch {
        showError(t('אין חיבור לשרת'));
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
// בחירת שפה — בראש המסך, בצד
const langPick = I18n.picker();
langPick.style.justifySelf = 'end';
$('#main').prepend(langPick);
I18n.ready.then(() => route()).catch(() => { setMode('login'); showError(t('אין חיבור לשרת')); });
