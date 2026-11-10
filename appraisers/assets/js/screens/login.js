/*
 * מסך הכניסה — קיים רק במצב מנותק (אפיון 02 §5.1): "כניסה עם הכלים הדיגיטליים" תמיד; מייל וסיסמה רק כשהמנהל פתח
 * אותם במעטפת. וגם: בחירת סיסמה חדשה מקישור (#/reset/<אסימון>), וקביעת סיסמה מתפריט המשתמש.
 */
'use strict';

/** הטופס שולח, מציג שגיאה בתוכו, ומחזיר את הכפתור למצבו. */
function bindForm(form, send) {
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const err = $('.error', form), btn = $('[type=submit]', form);
        err.hidden = true;
        btn.disabled = true;
        try {
            await send(Object.fromEntries(new FormData(form)));
        } catch (x) {
            err.textContent = x.message + (x.status >= 500 && x.req ? ' · ' + T.reqId(x.req) : '');
            err.hidden = false;
        }
        btn.disabled = false;
    });
}

async function viewLogin(error) {
    setHead(T.loginTitle, '');
    let cfg;
    try { cfg = await api('login-config'); } catch { cfg = { mode: 'unavailable' }; }
    if (cfg.mode === 'connected') {
        App.main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">🔒</div><h2>${T.loginNeeded}</h2><p class="muted">${T.loginViaPlatform}</p></div>`;
        return;
    }
    if (cfg.mode !== 'detached') {
        App.main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">🔒</div><h2>${T.loginUnavailableRetry}</h2>
            <div><button class="btn ghost" data-retry>${T.retry}</button></div></div>`;
        $('[data-retry]').addEventListener('click', () => viewLogin());
        return;
    }
    App.main.innerHTML = `<div class="panel login">
        ${T.loginErrors[error] ? `<p class="banner" role="alert">${T.loginErrors[error]}</p>` : ''}
        <a class="btn primary" href="api.php?action=sso-start">${T.loginPlatform}</a>
        ${cfg.password ? `<p class="sec-title">${T.loginOr}</p>
        <form id="pw" novalidate>
          <label class="f"><span>${T.email}</span><input name="email" type="email" dir="ltr" inputmode="email" autocomplete="username" required maxlength="200"></label>
          <label class="f"><span>${T.password}</span><input name="password" type="password" dir="ltr" autocomplete="current-password" required maxlength="200"></label>
          <p class="error" role="alert" hidden></p>
          <button class="btn ghost" type="submit">${T.loginDo}</button>
          <p class="foot"><button type="button" class="btn sm ghost" id="forgot">${T.forgot}</button></p>
        </form>` : ''}</div>`;
    const form = $('#pw');
    if (!form) return;
    bindForm(form, async f => {
        await api('login', { body: { email: f.email, password: f.password } });
        location.hash = sessionStorage.getItem('apr-after-login') || '#/';
        location.reload();
    });
    $('#forgot').addEventListener('click', () => sheet(T.forgotTitle, `
        <p class="hint">${T.forgotHint}</p>
        <label class="f"><span>${T.email}</span><input name="email" type="email" dir="ltr" inputmode="email" autocomplete="username" required maxlength="200" value="${esc(form.email.value)}"></label>`, async f => {
        await api('forgot', { body: { email: f.email } });
        toast(T.forgotSent);
    }, T.forgotDo));
}

async function viewReset(token) {
    setHead(T.resetTitle, '');
    history.replaceState(null, '', '#/reset');   // האסימון לא נשאר בכתובת
    App.main.innerHTML = `<form class="panel" id="reset" novalidate>
        <label class="f"><span>${T.newPassword}</span><input name="password" type="password" dir="ltr" autocomplete="new-password" required minlength="10" maxlength="200"></label>
        <p class="hint">${T.passwordRule}</p>
        <p class="error" role="alert" hidden></p>
        <button class="btn primary" type="submit">${T.resetDo}</button></form>`;
    bindForm($('#reset'), async f => {
        await api('reset', { body: { token, password: f.password } });
        toast(T.resetDone);
        location.hash = '#/login';
    });
}

/** "סיסמה לכניסה נפרדת" — מתפריט המשתמש, כשמחוברים. */
function openPassword() {
    sheet(T.passwordTitle, `
        <p class="hint">${T.passwordHint}</p>
        <label class="f"><span>${T.newPassword}</span><input name="password" type="password" dir="ltr" autocomplete="new-password" required minlength="10" maxlength="200"></label>
        <p class="hint">${T.passwordRule}</p>`, async f => {
        await api('password-set', { body: { password: f.password } });
        toast(T.passwordSaved);
        await refreshMe();
    });
}

async function logout() {
    try {
        await api('logout', { body: {} });
        location.hash = '#/login';
        location.reload();
    } catch (x) { toastError(x); }
}
