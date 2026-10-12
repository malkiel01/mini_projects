// ניהול פרויקטים — התחלה וניווט. נטען אחרון.
// הניווט בכתובת (#/…) — כך "חזרה" של הטלפון והדפדפן עובדת, וקישור לפרויקט נשמר.
'use strict';

async function init() {
    await I18n.ready; // המילון לפני כל ציור
    try {
        const me = await api('me');
        Object.assign(state, { user: me.user, trades: me.trades, templates: me.templates, labels: me.labels, standalone: me.standalone,
            fileTypes: me.file_types, maxUpload: me.max_upload, msgTemplates: me.msg_templates, msgVars: me.msg_vars || {} });
    } catch (e) {
        $('#main').innerHTML = `<p class="empty">${esc(e.message)}</p>`;
        return;
    }
    // השפה שמורה בחשבון: עוד לא נבחרה — נשמרת זו שבמכשיר; אחרת — של החשבון גוברת
    const lang = state.user.lang || '';
    if (!lang) api('lang', { body: { lang: I18n.lang() } }).catch(() => {});
    else if (lang !== I18n.lang() && I18n.LANGS[lang] && canStore()) { I18n.set(lang); return; }
    $('#logout').before(I18n.picker(l => { api('lang', { body: { lang: l } }).catch(() => {}).finally(() => I18n.set(l)); }));
    $('#who').innerHTML = `<span class="name">${esc(state.user.display_name)}</span>`
        + `<span class="badge">${esc(isDev() ? t('מפתח') : t('מנהל פרויקט'))}</span>`;
    $$('.dev-only').forEach(el => { el.hidden = !isDev(); });
    $('#logout').addEventListener('click', logout);
    bindPassword();
    bindInstall();
    addEventListener('hashchange', route);
    // שיתוף שהגיע לפני הכניסה (או שלא נשמר) — ממשיכים ממנו
    if (!location.hash.startsWith('#/share') && await Share.pending()) location.hash = '#/share';
    else route();
}

/** אפשר לשמור במכשיר? בלי זה החלפת שפה הייתה טוענת את הדף שוב ושוב. */
function canStore() {
    try { localStorage.setItem('pm.t', '1'); localStorage.removeItem('pm.t'); return true; } catch { return false; }
}

function route() {
    const [, view = '', a = '', b = '', c = ''] = location.hash.split('/');
    const inProject = view === 'p' && Number(a) > 0;
    const section = inProject ? 'project' : view === 'contacts' ? (b === 'import' ? 'import' : 'contacts')
        : view === 'settings' ? 'settings' : view === 'share' ? 'share' : view === 'calendar' ? 'calendar' : 'projects';
    const inner = inProject || section === 'share' || section === 'import'; // מסך פנימי: חץ חזרה במקום הניווט הראשי

    $$('.view').forEach(v => { v.hidden = v.id !== 'view-' + section; });
    $('#nav').hidden = inner;
    $('#project-nav').hidden = !inProject;
    $('#back').hidden = !inner;
    $('#back').href = section === 'share' && a ? '#/share' : section === 'import' ? `#/contacts/${a}` : '#/';
    $('#logo').hidden = inner;
    // בתוך דף האפליקציות — חזרה אליו. במצב עצמאי אין לאן לחזור.
    $('#tools-link').hidden = inner || state.standalone;
    $('#who').hidden = inner;
    $('#logout').hidden = inner;
    $$('.top .lang-pick').forEach(el => { el.hidden = inner; });
    $('#install').hidden = inner || !installPrompt;
    $$('#nav [data-nav]').forEach(x => x.setAttribute('aria-current', x.dataset.nav === section ? 'page' : 'false'));
    document.body.dataset.view = section;

    if (!inner) {
        $('#title').textContent = t('ניהול פרויקטים');
        $('#subtitle').hidden = true;
        document.title = t('ניהול פרויקטים');
    }
    if (section === 'projects') { Projects.showList(); Calendar.refreshBadge(); }
    if (section === 'calendar') Calendar.show(a);
    if (section === 'project') Projects.showProject(Number(a), b, c);
    if (section === 'contacts') Contacts.show(a);
    if (section === 'settings') Settings.show(a);
    if (section === 'share') { $('#subtitle').hidden = true; Share.show(a); }
    if (section === 'import') { $('#subtitle').hidden = true; DeviceContacts.show(a); }
}

async function logout() {
    await api('logout', { body: {} }).catch(() => {});
    location.href = 'login.html';
}

/** החשבון שלי — לחיצה על השם בכותרת: שם, אימייל, טלפון, וסיסמה. */
function bindPassword() {
    const dlg = $('#password-dlg');
    const pf = $('#profile-form'), wf = $('#password-form');
    $('#who').addEventListener('click', () => {
        pf.reset(); wf.reset(); formError(pf, ''); formError(wf, '');
        pf.elements.display_name.value = state.user.display_name || '';
        pf.elements.email.value = state.user.email || '';
        pf.elements.phone.value = state.user.phone || '';
        dlg.showModal();
    });
    pf.addEventListener('submit', async e => {
        e.preventDefault();
        const el = pf.elements;
        await busy($('button[type=submit]', pf), pf, async () => {
            const { user } = await api('profile', { body: { display_name: el.display_name.value, email: el.email.value, phone: el.phone.value } });
            Object.assign(state.user, user);
            $('#who .name').textContent = user.display_name;
            dlg.close();
            toast(t('נשמר'));
        });
    });
    wf.addEventListener('submit', async e => {
        e.preventDefault();
        const el = wf.elements;
        if (el.password.value !== el.password2.value) { formError(wf, t('הסיסמאות החדשות לא זהות')); return; }
        await busy($('button[type=submit]', wf), wf, async () => {
            await api('password', { body: { current: el.current.value, password: el.password.value } });
            dlg.close();
            toast(t('הסיסמה עודכנה'));
        });
    });
}

// ---- התקנה במכשיר ----
// אנדרואיד/מחשב: הדפדפן מציע התקנה (beforeinstallprompt) — כפתור 📲 בכותרת ובחשבון.
// אחרי ההתקנה האפליקציה מופיעה ב"שיתוף" של הטלפון (share_target במניפסט).
// אייפון: "הוספה למסך הבית" מספארי; שיתוף לאפליקציה שם — רק באפליקציית האייפון (שלב 14).

let installPrompt = null;
const isInstalled = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
const isIOS = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    installPrompt = e;
    if (state.user) { $('#install').hidden = document.body.dataset.view === 'project' || document.body.dataset.view === 'share'; renderInstall(); }
});
addEventListener('appinstalled', () => {
    installPrompt = null;
    $('#install').hidden = true;
    renderInstall();
    toast(t('האפליקציה הותקנה. מעכשיו אפשר לשתף אליה קבצים מכל אפליקציה.'));
});

function renderInstall() {
    const text = isInstalled()
        ? (isIOS() ? t('האפליקציה במסך הבית. שיתוף קבצים אליה מאפליקציות אחרות — באפליקציית האייפון, בהמשך.')
            : t('האפליקציה מותקנת. לשמירת קבצים מהגלריה, מוואטסאפ או מכל אפליקציה: "שיתוף" ← "פרויקטים".'))
        : installPrompt ? t('התקנה למסך הבית: נפתחת כאפליקציה, ומופיעה ב"שיתוף" של הטלפון — לשמירת קבצים ישר לפרויקט.')
        : isIOS() ? t('בספארי: כפתור השיתוף ← "הוספה למסך הבית".')
        : t('בכרום: תפריט ⋮ ← "התקנת אפליקציה" (או "הוספה למסך הבית").');
    $('#install-text').textContent = text;
    $('#install-btn').hidden = !installPrompt;
}

async function install() {
    if (!installPrompt) return;
    const p = installPrompt;
    installPrompt = null;
    p.prompt();
    const { outcome } = await p.userChoice.catch(() => ({}));
    if (outcome !== 'accepted') installPrompt = p; // אפשר לנסות שוב
    $('#install').hidden = !installPrompt;
    renderInstall();
}

function bindInstall() {
    $('#install').addEventListener('click', install);
    $('#install-btn').addEventListener('click', install);
    $('#who').addEventListener('click', renderInstall);
}

// בלי service worker אין התקנה ואין שיתוף לאפליקציה
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

init();
