/* בית, ברוכים הבאים (הקמת משרד / יש לי הזמנה), וקבלת הזמנה מקישור. */
'use strict';

const myOffice = () => App.me.offices.find(o => o.pid === App.me.office) || null;

function manageTiles() {
    const u = App.me.user, tiles = [];
    const o = myOffice();
    if (o && o.status === 'active') tiles.push(['#/office', '👥', T.tileOffice]);
    if (u.is_sysadmin) tiles.push(['#/system', '🏛️', T.tileSystem]);
    if (u.is_developer) tiles.push(['#/dev', '🛠️', T.tileDev]);
    return tiles.length ? `<div class="tiles">${tiles.map(([h, i, t]) => `<a class="tile" href="${h}"><span class="tile-ic" aria-hidden="true">${i}</span>${t}</a>`).join('')}</div>` : '';
}

function viewHome() {
    const o = myOffice();
    setHead(T.app, o ? o.name : '');
    if (!o) {
        // מנהל המערכת בלי משרד נוחת במסך המערכת; כל השאר — "ברוכים הבאים"
        location.replace(App.me.user.is_sysadmin ? '#/system' : '#/welcome');
        return;
    }
    if (o.status !== 'active') {
        const office = o.status === 'office_suspended';
        App.main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">⏸️</div>
            <h2>${office ? T.officeSuspendedTitle : T.memberSuspendedTitle}</h2><p class="muted">${office ? T.officeSuspendedText : T.memberSuspendedText}</p></div>${manageTiles()}`;
        return;
    }
    App.main.innerHTML = `<p class="banner" role="note">⚠️ ${T.devEnv}</p>
        <div class="summary"><div class="line"><b class="one">${esc(o.name)}</b><span class="chip info">${esc(roleLabel(o.role, o.is_admin))}</span></div></div>
        <p class="empty">${T.homeEmpty}</p>${manageTiles()}`;
}

async function viewWelcome() {
    setHead(T.welcomeTitle, '');
    const inv = await api('my-invites');
    const invites = inv.invites.length
        ? `<ul class="list">${inv.invites.map(i => `<li class="card static"><span class="use-ic" aria-hidden="true">✉️</span>
            <div class="body"><div class="l1"><span>${esc(i.office_name)}</span></div><div class="l2">${esc(T.inviteRow(T.roles[i.role], i.is_admin))}</div></div>
            <button class="btn primary sm" data-join="${i.pid}">${T.join}</button></li>`).join('')}</ul>`
        : `<p class="hint">${inv.verified ? esc(T.noInvites(inv.email)) : T.noInvitesUnverified}</p>`;
    App.main.innerHTML = `<p class="hint">${T.welcomeHint}</p>
        <p class="sec-title">✉️ ${T.haveInvite}</p>${invites}
        <p class="sec-title">🏛️ ${T.createOffice}</p>
        <form class="panel" id="create" novalidate>
          <label class="f"><span>${T.officeName}</span><input name="name" required maxlength="120" autocomplete="organization"></label>
          <div class="f"><span>${T.myRole}</span>${roleField('appraiser', ['appraiser', 'staff'])}</div>
          <p class="hint">${T.createOfficeHint}</p>
          <p class="error" role="alert" hidden></p>
          <button class="btn primary" type="submit">${T.createOfficeDo}</button>
        </form>`;
    const form = $('#create'), op = opId();   // מזהה פעולה אחד לטופס: לחיצה כפולה = משרד אחד
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = $('button[type=submit]', form), err = $('.error', form);
        btn.disabled = true;
        err.hidden = true;
        try {
            await api('office-create', { body: { ...Object.fromEntries(new FormData(form)), op } });
            toast(T.officeCreated);
            await refreshMe();
            location.hash = '#/office';
        } catch (x) {
            err.textContent = x.message;
            err.hidden = false;
            btn.disabled = false;
        }
    });
    $$('[data-join]').forEach(b => b.addEventListener('click', () => acceptInvite({ invite: b.dataset.join }, b)));
}

async function acceptInvite(body, btn) {
    if (btn) btn.disabled = true;
    try {
        const r = await api('invite-accept', { body });
        toast(r.already ? T.alreadyMember : T.joined);
        await refreshMe();
        location.hash = '#/office';
    } catch (x) {
        toastError(x);
        if (btn) btn.disabled = false;
    }
}

/** #/invite/<אסימון> — "הוזמנת למשרד X בתפקיד Y — הצטרפות". */
async function viewInvite(token) {
    setHead(T.inviteScreenTitle, null);
    try {
        const { invite } = await api('invite-info', { body: { token } });
        App.main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">✉️</div>
            <h2>${esc(T.invitedTo(invite.office_name, T.roles[invite.role]))}</h2>${invite.is_admin ? `<p class="muted">${T.invitedAdmin}</p>` : ''}
            <div><button class="btn primary" id="accept">${T.join}</button></div></div>`;
        $('#accept').addEventListener('click', e => acceptInvite({ token }, e.currentTarget));
    } catch (x) {
        // פגה / בוטלה / נוצלה / לא קיימת — אותו נוסח; מייל אחר — בלי לחשוף לאיזו כתובת נשלחה
        App.main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">✉️</div><h2>${esc(x.message)}</h2>
            <div><a class="btn ghost" href="#/">${T.app}</a></div></div>`;
    }
}
