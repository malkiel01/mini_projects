// כניסה, הרשמה והקמת המפתח — בחשבונות של האפליקציה עצמה (lib/auth.php).
//
// כשאין אף משתמש: "הקמת המפתח" — רק למי שמחובר לפלטפורמה כמנהל שלה.
// אחר כך: כניסה, או הרשמה כמנהל פרויקט — שממתינה לאישור המפתח.
'use strict';

const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
let csrf = null, setup = false;

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

const mode = () => setup ? 'setup' : document.querySelector('[name=mode]:checked').value;

function setMode() {
    const m = mode();
    const reg = m !== 'login';
    $$('.reg-only').forEach(el => { el.hidden = !reg; });
    $$('.setup-only').forEach(el => { el.hidden = m !== 'setup'; });
    $('.auth-tabs').hidden = m === 'setup';
    $('[name=phone]').closest('label').hidden = m !== 'register';
    $('.reg-only.hint').textContent = m === 'setup' ? 'לפחות 8 תווים.' : 'לפחות 8 תווים. ההרשמה נשלחת למפתח לאישור.';
    $('#auth-submit').textContent = { login: 'כניסה', register: 'שליחת בקשה', setup: 'הקמת המפתח' }[m];
    $('[name=password]').autocomplete = reg ? 'new-password' : 'current-password';
    $('#auth-error').hidden = true;
}

function showError(msg) {
    $('#auth-error').textContent = msg;
    $('#auth-error').hidden = false;
}

function showForm() {
    $('#auth-form').hidden = false;
    $('#waiting').hidden = true;
    setMode();
}

function showWaiting(title, text, canRetry = true) {
    $('#auth-form').hidden = true;
    $('.auth-tabs').hidden = true;
    $$('.setup-only').forEach(el => { el.hidden = true; });
    $('#waiting').hidden = false;
    $('#waiting-title').textContent = title;
    $('#waiting-text').textContent = text;
    $('#recheck').hidden = !canRetry;
    $('#logout').hidden = !csrf;
}

/** לאן ממשיכים: לאפליקציה, למסך "ממתין", להקמה, או לטופס הכניסה. */
async function route() {
    const { data } = await call('auth');
    csrf = data.csrf || null;
    const user = data.user;
    setup = !!data.setup_needed;
    if (setup) {
        if (data.setup_allowed) { showForm(); return; }
        showWaiting('האפליקציה עוד לא הוקמה', 'את חשבון המפתח מקים מנהל הפלטפורמה: להיכנס לדף האפליקציות כמנהל, ואז לחזור לכאן.', true);
        return;
    }
    if (!user) { showForm(); return; }
    if (user.status === 'active') { location.replace('./'); return; }
    if (user.status === 'pending') {
        showWaiting('ההרשמה התקבלה', `שלום ${user.display_name}. הבקשה ממתינה לאישור המפתח. אחרי האישור תוכל להיכנס מכאן.`);
        return;
    }
    showWaiting('החשבון חסום', 'פנה למפתח.', false);
}

async function submit(e) {
    e.preventDefault();
    const f = e.target.elements;
    const m = mode();
    const body = { username: f.username.value.trim(), password: f.password.value };
    if (!body.username || !body.password) { showError('יש למלא שם משתמש וסיסמה'); return; }
    if (m !== 'login') {
        body.display_name = f.display_name.value.trim();
        if (!body.display_name) { showError('יש למלא שם מלא'); return; }
        if (m === 'register') body.phone = f.phone.value.trim();
    }
    const btn = $('#auth-submit');
    btn.disabled = true;
    try {
        const r = await call(m, body);
        if (r.status !== 200) { showError(r.data.error || 'תקלה, נסה שוב'); return; }
        f.password.value = '';
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

$$('[name=mode]').forEach(r => r.addEventListener('change', setMode));
$('#auth-form').addEventListener('submit', submit);
$('#recheck').addEventListener('click', () => route());
$('#logout').addEventListener('click', logout);
$('#auth-form').hidden = true; // עד שיתברר אם כבר מחובר
route().catch(() => { showForm(); showError('אין חיבור לשרת'); });
