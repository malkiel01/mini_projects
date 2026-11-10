/*
 * שמאות מקרקעין — הניתוב וההפעלה. דף אחד, ניווט ב-#.
 * הזהות מגיעה מהשער של המעטפת. מסך כניסה ויציאה — רק כשהמערכת מנותקת מהמעטפת (#/login, #/reset/<אסימון>).
 *
 *   #/                     בית                              #/welcome            הקמת משרד / יש לי הזמנה
 *   #/invite/<אסימון>      קבלת הזמנה                       #/office · #/office/log   המשרד שלי · יומן הפעולות
 *   #/system · #/system/users   ניהול המערכת                #/dev/<לשונית>[/…]   מסך המפתח
 */
'use strict';

function drawHead() {
    const me = App.me, o = myOffice();
    $('#me-btn').textContent = initials(me.user.name);
    $('#me-btn').hidden = false;
    $('#dev-btn').hidden = !me.user.is_developer;
    // שם המשרד הפעיל מוצג בכותרת, תמיד; יותר ממשרד אחד — הוא גם הבורר
    const sub = $('#sub'), many = me.offices.length > 1;
    const text = App.sub ?? (o ? o.name : '');
    sub.hidden = !text && !o;
    sub.textContent = (text || (o ? o.name : '')) + (many ? ' ▾' : '');
    sub.disabled = !many;
}

async function refreshMe() {
    App.me = await api('me');
    drawHead();
}

async function switchOffice(pid) {
    await api('office-switch', { body: { office: pid } });
    await refreshMe();
    if (location.hash === '#/' || location.hash === '') route(); else location.hash = '#/';
}

function officesListHtml() {
    const me = App.me, many = me.offices.length > 1;
    return `<ul class="list">${me.offices.map(o => `<li><button class="use-row" type="button" data-office="${o.pid}"${many && o.pid !== me.office ? '' : ' disabled'}>
        <span class="use-ic" aria-hidden="true">${me.office === o.pid ? '✔️' : '🏛️'}</span>
        <span class="use-tx"><b>${esc(o.name)}</b><small>${esc(roleLabel(o.role, o.is_admin))}${o.status !== 'active' ? ' · ' + T.statuses[o.status] : ''}</small></span></button></li>`).join('')}</ul>`;
}

function openMe() {
    const me = App.me, u = me.user, o = myOffice();
    const links = [];
    if (o && o.status === 'active') links.push(['#/office', '👥', T.myOffice]);
    links.push(['#/welcome', '➕', T.newOffice]);
    if (u.is_sysadmin) links.push(['#/system', '🏛️', T.systemAdmin]);
    if (u.is_developer) links.push(['#/dev', '🛠️', T.devScreen]);
    const dlg = sheet(T.meTitle, `
        <div class="summary"><div class="line"><b class="one">${esc(u.name)}</b></div>
          <div class="line muted" dir="auto">${esc(u.email || '')}</div>
          <div class="chips">${u.is_developer ? `<span class="chip info">${T.tagDev}</span>` : ''}${u.is_sysadmin ? `<span class="chip info">${T.tagSys}</span>` : ''}${
              u.email && !u.email_verified ? `<span class="chip warn">${T.emailUnverified}</span>` : ''}</div></div>
        ${me.offices.length ? `<p class="sec-title">${T.myOffices}</p>${officesListHtml()}` : ''}
        <ul class="list">${links.map(([h, i, t]) => `<li><a class="use-row" href="${h}" data-close><span class="use-ic" aria-hidden="true">${i}</span>
            <span class="use-tx"><b>${t}</b></span><span class="use-go" aria-hidden="true">‹</span></a></li>`).join('')}</ul>
        <ul class="list"><li><button class="use-row" type="button" id="pw-open"><span class="use-ic" aria-hidden="true">🔑</span>
            <span class="use-tx"><b>${T.passwordMenu}</b><small>${me.has_password ? T.passwordSet : T.passwordNotSet}</small></span></button></li>
          ${me.mode === 'detached' ? `<li><button class="use-row" type="button" id="logout"><span class="use-ic" aria-hidden="true">🚪</span>
            <span class="use-tx"><b>${T.logout}</b></span></button></li>` : ''}</ul>
        <p class="hint">${me.mode === 'detached' ? '' : T.noLogout + ' '}${esc(T.version(me.version))}</p>`, null);
    bindOfficePick(dlg);
    $('#pw-open', dlg).addEventListener('click', openPassword);
    $('#logout', dlg)?.addEventListener('click', logout);
}

function bindOfficePick(dlg) {
    $$('[data-office]', dlg).forEach(b => b.addEventListener('click', async () => {
        dlg.close();
        try { await switchOffice(b.dataset.office); } catch (x) { toastError(x); }
    }));
}

async function route() {
    const [, a = '', b = '', c = ''] = location.hash.split('/').map(decodeURIComponent);
    const me = App.me, o = myOffice(), ok = o && o.status === 'active';
    $('#back').hidden = a === '';
    App.sub = undefined;
    const home = () => { history.replaceState(null, '', '#/'); $('#back').hidden = true; viewHome(); };
    try {
        if (a === '') viewHome();
        else if (a === 'welcome') await viewWelcome();
        else if (a === 'invite' && b) await viewInvite(b);
        else if (a === 'office' && ok) await viewOffice(b);
        else if (a === 'system' && me.user.is_sysadmin) await viewSystem(b);
        else if (a === 'dev' && me.user.is_developer) await viewDev(b, c);
        else home();   // אין הרשאה או אין מסך כזה — לבית, בלי לחשוף מה היה שם
    } catch (x) {
        if (x.code === 'office_changed' || x.code === 'not_found' || x.code === 'no_office') {
            // המשרד הוחלף בלשונית אחרת, או שההרשאה השתנתה — מרעננים וחוזרים לבית
            await refreshMe().catch(() => {});
            home();
        } else {
            App.main.innerHTML = errorHtml(x);
            $('[data-retry]')?.addEventListener('click', route);
        }
    }
    drawHead();
    scrollTo(0, 0);
}

async function boot() {
    App.main = $('#main');
    // --vvh: הגובה הנראה בלי המקלדת — הגיליון מתקצר והכול נגלל
    const vv = window.visualViewport;
    if (vv) {
        const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
        vv.addEventListener('resize', set);
        set();
    }
    // שגיאות דפדפן נרשמות לכל משתמש מחובר (builtinLog); 🐞 — רק למפתח
    DevLoop.setup({
        api, toast, isDev: () => !!App.me?.user.is_developer, appName: T.app, tasksHref: '#/dev/tasks', mountBefore: '#me-btn',
        headers: () => ({ 'X-CSRF': App.me?.csrf || '' }), version: () => App.me?.version || '', builtinLog: true, logHref: r => '#/dev/log/' + r.req,
    });
    // מסכי הכניסה (רק במצב מנותק) — בלי משתמש
    const [, a0 = '', b0 = ''] = location.hash.split('/').map(decodeURIComponent);
    if (a0 === 'reset' && b0) return viewReset(b0);
    try {
        App.me = await api('me');
    } catch (x) {
        if (x.code === 'login_required') {
            // לאן לחזור אחרי הכניסה (למשל קישור הזמנה) — נשמר בלשונית בלבד
            if (a0 && a0 !== 'login' && a0 !== 'reset') sessionStorage.setItem('apr-after-login', location.hash);
            addEventListener('hashchange', () => location.reload());
            return viewLogin(a0 === 'login' ? b0 : '');
        }
        App.main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">🔒</div>
            <h2>${x.status === 401 ? T.loginNeeded : x.status === 503 ? T.loginUnavailable : T.cannotEnter}</h2><p class="muted">${esc(x.message)}</p>
            ${x.req ? `<p class="muted" dir="auto">${esc(T.reqId(x.req))}</p>` : ''}</div>`;
        return;
    }
    const after = sessionStorage.getItem('apr-after-login');
    sessionStorage.removeItem('apr-after-login');
    if (after) history.replaceState(null, '', after);
    else if (a0 === 'login' || a0 === 'reset') history.replaceState(null, '', '#/');
    drawHead();
    if (App.me.user.is_developer) DevLoop.mount();
    addEventListener('hashchange', route);
    // חזרה: למסך הקודם באפליקציה, עד הבית — לא יוצאים מהאפליקציה
    $('#back').addEventListener('click', () => {
        const parts = location.hash.split('/').filter(Boolean);
        location.hash = parts.length > 2 ? '#/' + parts.slice(1, -1).join('/') : '#/';
    });
    $('#me-btn').addEventListener('click', openMe);
    $('#sub').addEventListener('click', () => bindOfficePick(sheet(T.officePick, officesListHtml(), null)));
    document.addEventListener('click', e => {
        const close = e.target.closest('[data-close]');
        if (close) close.closest('dialog')?.close();
        const go = e.target.closest('[data-go]');
        if (go) location.hash = go.dataset.go;
    });
    route();
}

boot();
