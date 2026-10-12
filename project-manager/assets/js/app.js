// ניהול פרויקטים — התחלה וניווט. נטען אחרון.
// הניווט בכתובת (#/…) — כך "חזרה" של הטלפון והדפדפן עובדת, וקישור לפרויקט נשמר.
'use strict';

async function init() {
    try {
        const me = await api('me');
        Object.assign(state, { user: me.user, trades: me.trades, templates: me.templates, labels: me.labels, standalone: me.standalone });
    } catch (e) {
        $('#main').innerHTML = `<p class="empty">${esc(e.message)}</p>`;
        return;
    }
    $('#who').innerHTML = `<span class="name">${esc(state.user.display_name)}</span>`
        + `<span class="badge">${isDev() ? 'מפתח' : 'מנהל פרויקט'}</span>`;
    $$('.dev-only').forEach(el => { el.hidden = !isDev(); });
    $('#logout').addEventListener('click', logout);
    bindPassword();
    addEventListener('hashchange', route);
    route();
}

function route() {
    const [, view = '', a = '', b = ''] = location.hash.split('/');
    const inProject = view === 'p' && Number(a) > 0;
    const section = inProject ? 'project' : view === 'contacts' ? 'contacts' : view === 'settings' && isDev() ? 'settings' : 'projects';

    $$('.view').forEach(v => { v.hidden = v.id !== 'view-' + section; });
    $('#nav').hidden = inProject;
    $('#project-nav').hidden = !inProject;
    $('#back').hidden = !inProject;
    $('#logo').hidden = inProject;
    // בתוך דף האפליקציות — חזרה אליו. במצב עצמאי אין לאן לחזור.
    $('#tools-link').hidden = inProject || state.standalone;
    $('#who').hidden = inProject;
    $('#logout').hidden = inProject;
    $$('#nav [data-nav]').forEach(x => x.setAttribute('aria-current', x.dataset.nav === section ? 'page' : 'false'));
    document.body.dataset.view = section;

    if (!inProject) {
        $('#title').textContent = 'ניהול פרויקטים';
        $('#subtitle').hidden = true;
        document.title = 'ניהול פרויקטים';
    }
    if (section === 'projects') Projects.showList();
    if (section === 'project') Projects.showProject(Number(a), b);
    if (section === 'contacts') Contacts.show(a);
    if (section === 'settings') Settings.show(a);
}

async function logout() {
    await api('logout', { body: {} }).catch(() => {});
    location.href = 'login.html';
}

/** שינוי סיסמה — לחיצה על השם בכותרת. */
function bindPassword() {
    const dlg = $('#password-dlg');
    const form = $('form', dlg);
    $('#who').addEventListener('click', () => { form.reset(); formError(form, ''); dlg.showModal(); });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const el = form.elements;
        if (el.password.value !== el.password2.value) { formError(form, 'הסיסמאות החדשות לא זהות'); return; }
        await busy($('button[type=submit]', form), form, async () => {
            await api('password', { body: { current: el.current.value, password: el.password.value } });
            dlg.close();
            toast('הסיסמה עודכנה');
        });
    });
}

// אפליקציה עצמאית במסך הבית. בלי service worker אנדרואיד לא מציע התקנה.
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

init();
