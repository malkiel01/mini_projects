// כניסה והרשמה של מנהלים — דרך החשבונות של הפלטפורמה (platform/api.php).
//
// הרשמה יוצרת משתמש "ממתין" בפלטפורמה; המפתח מאשר אותו ומסמן לו "✓ מותר" על
// האפליקציה. עד אז המסך מציג "ממתין לאישור". הדף ציבורי ב-app.json ("public"),
// כי מי שלא נכנס עוד צריך לראות אותו.
'use strict';

const $ = s => document.querySelector(s);
const PLATFORM = '../platform/api.php';
let csrf = null;

async function call(url, body) {
    const opts = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body) {
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-Platform'] = '1'; // הפלטפורמה דוחה בקשה משנה בלעדיה (הגנת CSRF)
        if (csrf) opts.headers['X-CSRF'] = csrf;
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    let data = {};
    try { data = await res.json(); } catch { /* לא JSON */ }
    return { status: res.status, data };
}

function mode() { return document.querySelector('[name=mode]:checked').value; }

function setMode() {
    const reg = mode() === 'register';
    document.querySelectorAll('.reg-only').forEach(el => { el.hidden = !reg; });
    $('#auth-submit').textContent = reg ? 'שליחת בקשה' : 'כניסה';
    $('[name=password]').autocomplete = reg ? 'new-password' : 'current-password';
    $('#auth-error').hidden = true;
}

function showError(msg) {
    const el = $('#auth-error');
    el.textContent = msg;
    el.hidden = false;
}

function showWaiting(title, text) {
    $('#auth-form').hidden = true;
    $('.auth-tabs').hidden = true;
    $('#waiting').hidden = false;
    $('#waiting-title').textContent = title;
    $('#waiting-text').textContent = text;
}

/** לאן ממשיכים אחרי כניסה: לאפליקציה, או למסך "ממתין". */
async function route() {
    const me = await call(PLATFORM + '?action=me');
    const user = me.data.user;
    csrf = me.data.csrf || null;
    if (!user) {
        $('#auth-form').hidden = false;
        $('.auth-tabs').hidden = false;
        $('#waiting').hidden = true;
        return;
    }
    if (user.status === 'pending') {
        showWaiting('ההרשמה התקבלה', `שלום ${user.display_name}. הבקשה ממתינה לאישור המפתח. אחרי האישור תוכל להיכנס מכאן.`);
        return;
    }
    if (user.status === 'blocked') {
        showWaiting('החשבון חסום', 'פנה למפתח.');
        return;
    }
    const app = await call('api.php?action=me');
    if (app.status === 200) { location.replace('./' + location.hash); return; }
    showWaiting('ממתין להרשאה', `שלום ${user.display_name}. החשבון מאושר, אבל עוד לא קיבלת גישה לניהול הפרויקטים. המפתח צריך לאשר אותה.`);
}

async function submit(e) {
    e.preventDefault();
    const f = e.target.elements;
    const body = { username: f.username.value.trim(), password: f.password.value };
    if (!body.username || !body.password) { showError('יש למלא שם משתמש וסיסמה'); return; }
    const reg = mode() === 'register';
    if (reg) {
        body.display_name = f.display_name.value.trim();
        if (!body.display_name) { showError('יש למלא שם מלא'); return; }
    }
    const btn = $('#auth-submit');
    btn.disabled = true;
    try {
        const r = await call(PLATFORM + '?action=' + (reg ? 'register' : 'login'), body);
        if (r.status !== 200) { showError(r.data.error || 'תקלה, נסה שוב'); return; }
        await route();
    } catch {
        showError('אין חיבור לשרת');
    } finally {
        btn.disabled = false;
    }
}

async function logout() {
    await call(PLATFORM + '?action=logout', {});
    csrf = null;
    $('[name=password]').value = '';
    await route();
}

document.querySelectorAll('[name=mode]').forEach(r => r.addEventListener('change', setMode));
$('#auth-form').addEventListener('submit', submit);
$('#recheck').addEventListener('click', route);
$('#logout').addEventListener('click', logout);
$('#auth-form').hidden = true; // עד שיתברר אם כבר מחובר
route().catch(() => { $('#auth-form').hidden = false; showError('אין חיבור לשרת'); });
