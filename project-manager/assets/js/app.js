// ניהול פרויקטים — התחלה וניווט. נטען אחרון.
// הניווט בכתובת (#/…) — כך "חזרה" של הטלפון והדפדפן עובדת, וקישור לפרויקט נשמר.
'use strict';

async function init() {
    await I18n.ready; // המילון לפני כל ציור
    try {
        const me = await api('me');
        Object.assign(state, { user: me.user, trades: me.trades, templates: me.templates, labels: me.labels, standalone: me.standalone,
            fileTypes: me.file_types, maxUpload: me.max_upload, msgTemplates: me.msg_templates, msgVars: me.msg_vars || {}, vatRate: me.vat_rate, finAlerts: me.fin_alerts });
    } catch (e) {
        $('#main').innerHTML = `<p class="empty">${esc(e.message)}</p>`;
        return;
    }
    // השפה שמורה בחשבון: עוד לא נבחרה — נשמרת זו שבמכשיר; אחרת — של החשבון גוברת
    const lang = state.user.lang || '';
    if (!lang) api('lang', { body: { lang: I18n.lang() } }).catch(() => {});
    else if (lang !== I18n.lang() && I18n.LANGS[lang] && canStore()) { I18n.set(lang); return; }
    $$('.dev-only').forEach(el => { el.hidden = !isDev(); });
    // DevLoop (המודול המשותף, devloop.js): 🐞 בכותרת — למפתח: דיווח מכל מסך, ומונה המטלות שממתינות לו
    DevLoop.setup({ api, toast, isDev, appName: 'מנהל הפרויקטים', // i18n-none tasksHref: '#/settings/tasks', mountBefore: '#me-btn',
        headers: () => ({ 'X-CSRF': state.user?.csrf || '', 'X-Lang': I18n.lang() }), lang: () => I18n.lang(),
        version: () => '0.' + ((document.querySelector('script[src*="core.js"]')?.src.match(/v=(\d+)/) || [])[1] || ''), trace: () => PMTrace });
    DevLoop.mount();
    bindMe();
    $('#me-dev').addEventListener('click', () => { Dev.set(!Dev.on()); Me.close(); toast(Dev.on() ? t('סמכות מפתח פעילה — אפשר למחוק הכול ולערוך גם נעול') : t('סמכות מפתח כבויה')); });
    $('#dev-off').addEventListener('click', () => Dev.set(false));
    Dev.show();
    $('#logout').addEventListener('click', logout);
    bindPassword();
    bindInstall();
    addEventListener('hashchange', route);
    // חץ החזרה — למסך הקודם באפליקציה (לא לדף האפליקציות, ולא מחוץ לאפליקציה)
    $('#back').addEventListener('click', e => { e.preventDefault(); goBack(); });
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
        : view === 'settings' ? 'settings' : view === 'share' ? 'share' : view === 'calendar' ? 'calendar' : view === 'finance' ? 'finance' : 'projects';
    const inner = inProject || section === 'share' || section === 'import'; // מסך פנימי: חץ חזרה במקום הניווט הראשי

    $$('.view').forEach(v => { v.hidden = v.id !== 'view-' + section; });
    $('#nav').hidden = inner;
    $('#project-nav').hidden = !inProject;
    track(inner, inProject ? `p/${a}${c ? `/${b}/${c}` : ''}` : `${section}/${a}`, section === 'share' && a ? '#/share' : section === 'import' ? `#/contacts/${a}` : '#/');
    // במסך ראשי (עם הניווט) — אין חץ חזרה. במסך פנימי — חזרה למסך הקודם, עד המסך הראשי.
    $('#back').hidden = !inner;
    $('#logo').hidden = inner;
    $('#me-btn').hidden = inner;
    Me.close();
    $$('#nav [data-nav]').forEach(x => x.setAttribute('aria-current', x.dataset.nav === section ? 'page' : 'false'));
    document.body.dataset.view = section;

    if (!inner) {
        $('#title').textContent = t('ניהול פרויקטים');
        $('#subtitle').hidden = true;
        document.title = t('ניהול פרויקטים');
    }
    if (section === 'projects') { Projects.showList(); Calendar.refreshBadge(); }
    if (section === 'calendar') Calendar.show(a);
    if (section === 'finance') FinanceAll.show();
    if (section === 'project') Projects.showProject(Number(a), b, c);
    if (section === 'contacts') Contacts.show(a);
    if (section === 'settings') Settings.show(a);
    if (section === 'share') { $('#subtitle').hidden = true; Share.show(a); }
    if (section === 'import') { $('#subtitle').hidden = true; DeviceContacts.show(a); }
}

// ---- חזרה למסך הקודם ----
// מחסנית של מסכים באפליקציה (נשמרת ללשונית, גם אחרי רענון). מעבר בין לשוניות של אותו פרויקט — אותו
// מסך (מחליף); תיקייה בתוך הקבצים — מסך חדש. כל מסך ראשי מתחיל את המחסנית מחדש.
const NAV_KEY = 'pm.nav';
let navStack = (() => { try { return JSON.parse(sessionStorage.getItem(NAV_KEY)) || []; } catch { return []; } })();
let navParent = '#/';

function track(inner, key, parent) {
    const hash = location.hash || '#/';
    navParent = parent;
    if (!inner) navStack = [{ key, hash }];
    else {
        const i = navStack.findIndex(x => x.key === key);
        if (i >= 0) { navStack.length = i + 1; navStack[i].hash = hash; } // חזרה למסך שכבר במחסנית / לשונית אחרת באותו מסך
        else navStack.push({ key, hash });
    }
    try { sessionStorage.setItem(NAV_KEY, JSON.stringify(navStack.slice(-30))); } catch { /* מצב פרטי */ }
}

/** המסך הקודם — ואם נכנסו ישר למסך פנימי (קישור, שיתוף), המסך שמעליו. */
function goBack() {
    const prev = navStack.length > 1 ? navStack[navStack.length - 2].hash : navParent;
    location.hash = prev.replace(/^#?/, '#');
}

async function logout() {
    await api('logout', { body: {} }).catch(() => {});
    location.href = 'login.html';
}

/**
 * התפריט שלי — העיגול בכותרת (ראשי התיבות, בצבע הקבוע של השם). נפתח מתחתיו: מי אני,
 * החשבון (פרטים וסיסמה), שפה, התקנה במכשיר, הגדרות, דף האפליקציות ויציאה.
 * נסגר בלחיצה מחוץ לו, ב-Esc ובמעבר מסך.
 */
const Me = (() => {
    const menu = () => $('#me-menu'), btn = () => $('#me-btn');
    function open() {
        menu().hidden = false;
        btn().setAttribute('aria-expanded', 'true');
        $('.me-item:not([hidden])', menu())?.focus();
    }
    function close(focusBack = false) {
        if (!menu() || menu().hidden) return;
        menu().hidden = true;
        btn().setAttribute('aria-expanded', 'false');
        if (focusBack) btn().focus();
    }
    return { open, close, toggle: () => (menu().hidden ? open() : close()) };
})();

function renderMe() {
    const u = state.user;
    $('#me-btn').innerHTML = avatar(u.display_name);
    $('#me-btn').title = u.display_name;
    const id = u.email || u.username || '';
    $('#me-head').innerHTML = `${avatar(u.display_name)}<div class="me-who"><b>${esc(u.display_name)}</b>`
        + `<small>${esc(isDev() ? t('מפתח') : t('מנהל פרויקט'))}${id ? ' · <bdi>' + esc(id) + '</bdi>' : ''}</small></div>`;
}

function bindMe() {
    renderMe();
    $('#me-btn').addEventListener('click', e => { e.stopPropagation(); Me.toggle(); });
    document.addEventListener('click', e => { if (!e.target.closest('#me-menu')) Me.close(); });
    $('#me-menu').addEventListener('keydown', e => {
        if (e.key === 'Escape') { e.preventDefault(); Me.close(true); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        const items = $$('.me-item:not([hidden]), .me-langs button', $('#me-menu'));
        const i = items.indexOf(document.activeElement);
        e.preventDefault();
        items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
    });
    $('#me-menu').addEventListener('click', e => { if (e.target.closest('a.me-item')) Me.close(); });
    $('#me-account').addEventListener('click', () => { Me.close(); openAccount(); });
    // שפה — נשמרת בחשבון ובמכשיר, והדף נטען מחדש בשפה החדשה
    $('#me-langs').innerHTML = Object.entries(I18n.LANGS).map(([k, v]) =>
        `<button type="button" lang="${k}" data-lang="${k}" aria-pressed="${k === I18n.lang()}">${esc(v.name)}</button>`).join('');
    $('#me-langs').addEventListener('click', e => {
        const b = e.target.closest('[data-lang]');
        if (!b || b.dataset.lang === I18n.lang()) return;
        const l = b.dataset.lang;
        api('lang', { body: { lang: l } }).catch(() => {}).finally(() => I18n.set(l));
    });
    $('#me-apps').hidden = !!state.standalone;
}

/** החשבון שלי — מהתפריט: שם, אימייל, טלפון, התקנה וסיסמה. */
function openAccount() {
    const pf = $('#profile-form'), wf = $('#password-form');
    pf.reset(); wf.reset(); formError(pf, ''); formError(wf, '');
    pf.elements.display_name.value = state.user.display_name || '';
    pf.elements.email.value = state.user.email || '';
    pf.elements.phone.value = state.user.phone || '';
    renderInstall();
    $('#password-dlg').showModal();
}

function bindPassword() {
    const dlg = $('#password-dlg');
    const pf = $('#profile-form'), wf = $('#password-form');
    pf.addEventListener('submit', async e => {
        e.preventDefault();
        const el = pf.elements;
        await busy($('button[type=submit]', pf), pf, async () => {
            const { user } = await api('profile', { body: { display_name: el.display_name.value, email: el.email.value, phone: el.phone.value } });
            Object.assign(state.user, user);
            renderMe();
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
    if (state.user) { $('#install').hidden = false; renderInstall(); }
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
    $('#install').addEventListener('click', () => { Me.close(); install(); });
    $('#install-btn').addEventListener('click', install);
}

// בלי service worker אין התקנה ואין שיתוף לאפליקציה
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

init();
