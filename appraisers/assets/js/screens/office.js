/* המשרד שלי: שם, צוות, הזמנות (פעולות — למנהל), ויומן הפעולות של המשרד. */
'use strict';

const inviteUrl = token => location.origin + location.pathname + '#/invite/' + token;

/** שורות יומן לפי ימים: אייקון, מה קרה, מי ומתי. text(r) מחזיר את המשפט. */
function auditHtml(rows, text, who) {
    if (!rows.length) return `<p class="empty">${T.logEmpty}</p>`;
    let day = '', out = '';
    for (const r of rows) {
        const k = fmtDay(r.at);
        if (k !== day) { out += `${day ? '</ul>' : ''}<p class="day">${esc(k)}</p><ul class="list">`; day = k; }
        out += `<li class="ev"><span class="use-ic" aria-hidden="true">${T.auditIcon[r.action.split('.')[0]] || '•'}</span>
            <div class="body"><div class="what">${text(r)}</div>${r.reason ? `<div class="why">${esc(r.reason)}</div>` : ''}
            <div class="who">${who(r)}<i class="sep" aria-hidden="true">·</i>${esc(fmtClock(r.at))}</div></div></li>`;
    }
    return out + '</ul>';
}

/** גיליון עם קישור ההזמנה להעתקה. האסימון מוצג פעם אחת — במסד נשמר רק ה-hash שלו. */
function showInviteLink(r, rotated) {
    sheet(T.inviteLinkTitle, `
        ${rotated ? `<p class="hint">${T.linkRotated}</p>` : ''}<p class="hint">${esc(T.inviteLinkHint(fmtDate(r.expires_at)))}</p>
        <label class="f"><span>${T.inviteLinkTitle}</span><input readonly dir="ltr" id="link" value="${esc(inviteUrl(r.token))}"></label>
        <button type="button" class="btn primary" id="copy">${T.copyLink}</button>`, null).querySelector('#copy')
        .addEventListener('click', () => copyText(inviteUrl(r.token)));
}

async function viewOffice(tab) {
    const o = myOffice();
    setHead(T.officeTitle, o.name);
    const d = await api('office', { params: { office: o.pid } });
    const tabs = d.can_manage ? tabsHtml([['#/office', T.tabTeam, d.members.length], ['#/office/log', T.tabLog]], tab === 'log' ? '#/office/log' : '#/office') : '';
    if (tab === 'log' && d.can_manage) {
        const log = await api('office-audit', { params: { office: o.pid } });
        App.main.innerHTML = tabs + auditHtml(log.rows, r => esc((T.audit[r.action] || (() => r.action))(r)),
            r => esc(r.actor || (r.actor_kind === 'user' ? T.sysadminActor : T.system)));
        return;
    }
    const admin = d.can_manage;
    App.main.innerHTML = `${tabs}
        <div class="summary"><div class="line"><b class="one">${esc(d.office.name)}</b>
          ${admin ? `<button class="icon-btn" id="rename" aria-label="${T.renameOffice}" style="margin-inline-start:auto">✏️</button>` : ''}</div></div>
        <div class="toolbar"><p class="hint one" style="flex:1;margin:0">${admin ? T.teamHintAdmin : T.teamHint}</p>
          ${admin ? `<button class="btn primary" id="invite"><span aria-hidden="true">+</span> ${T.invite}</button>` : ''}</div>
        <ul class="list">${d.members.map(m => `<li class="card${admin ? '' : ' static'}" ${admin ? `tabindex="0" role="button" data-member="${m.pid}"` : ''}>
            <span class="avatar" style="--h:${hue(m.pid)}" aria-hidden="true">${esc(initials(m.name))}</span>
            <div class="body"><div class="l1"><span>${esc(m.name)}${m.me ? ' ' + T.me : ''}</span></div>
            <div class="l2">${esc(roleLabel(m.role, m.is_admin))}${m.title ? `<i class="sep" aria-hidden="true">·</i>${esc(m.title)}` : ''}${
                admin ? `<i class="sep" aria-hidden="true">·</i>${esc(seen(m.last_seen_at))}` : ''}</div></div>
            ${m.account_suspended ? `<span class="chip bad">${T.accountSuspended}</span>` : m.status === 'suspended' ? `<span class="chip bad">${T.statuses.suspended}</span>` : ''}</li>`).join('')}</ul>
        ${admin && d.invites.length ? `<p class="sec-title">${T.pendingInvites}</p><ul class="list">${d.invites.map(i => `<li class="card static">
            <span class="use-ic" aria-hidden="true">✉️</span>
            <div class="body"><div class="l1"><span dir="ltr">${esc(i.email)}</span></div>
            <div class="l2">${esc(roleLabel(i.role, i.is_admin))}<i class="sep" aria-hidden="true">·</i>${esc(T.inviteUntil(fmtDate(i.expires_at)))}</div></div>
            <button class="icon-btn" data-link="${i.pid}" aria-label="${T.copyLink}">📋</button>
            <button class="icon-btn" data-revoke="${i.pid}" data-email="${esc(i.email)}" aria-label="${T.revoke}">🗑️</button></li>`).join('')}</ul>` : ''}
        <p class="foot"><button class="btn sm ghost danger" id="leave">${T.leave}</button></p>`;

    const body = extra => ({ office: o.pid, ...extra });
    const showLink = showInviteLink;

    $('#invite')?.addEventListener('click', () => sheet(T.inviteTitle, `
        <p class="hint">${T.inviteHint}</p>
        <label class="f"><span>${T.email}</span><input name="email" type="email" dir="ltr" inputmode="email" autocomplete="off" required maxlength="200"></label>
        <div class="f"><span>${T.role}</span>${roleField('staff')}</div>
        <label class="switch"><input type="checkbox" name="is_admin"><span>${T.alsoAdmin}</span></label>`, async f => {
        const r = await api('invite-create', { body: body({ email: f.email, role: f.role, is_admin: !!f.is_admin }) });
        viewOffice();
        showLink(r, false);
        return false;   // הגיליון נשאר פתוח — עכשיו עם הקישור
    }, T.inviteCreate));

    $$('[data-link]').forEach(b => b.addEventListener('click', async () => {
        try { showLink(await api('invite-link', { body: body({ invite: b.dataset.link }) }), true); } catch (x) { toastError(x); }
    }));
    $$('[data-revoke]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm(T.revokeAsk(b.dataset.email))) return;
        try { await api('invite-revoke', { body: body({ invite: b.dataset.revoke }) }); toast(T.revoked); viewOffice(); } catch (x) { toastError(x); }
    }));
    $('#rename')?.addEventListener('click', () => sheet(T.renameOffice, `
        <label class="f"><span>${T.officeName}</span><input name="name" required maxlength="120" value="${esc(d.office.name)}"></label>`, async f => {
        await api('office-rename', { body: body({ name: f.name }) });
        toast(T.saved);
        await refreshMe();
        route();
    }));
    $('#leave').addEventListener('click', async () => {
        if (!confirm(T.leaveAsk(d.office.name))) return;
        try { await api('office-leave', { body: body() }); toast(T.left); await refreshMe(); location.hash = '#/'; } catch (x) { toastError(x); }
    });
    $$('[data-member]').forEach(li => onActivate(li, () => {
        const m = d.members.find(x => x.pid === li.dataset.member);
        const dlg = sheet(m.name, `
            <p class="hint" dir="auto">${esc(m.email || '')}</p>
            ${m.account_suspended ? `<p class="banner" role="note">${T.accountSuspendedHint}</p>` : ''}
            <div class="f"><span>${T.role}</span>${roleField(m.role)}</div>
            <div class="fields two">
              <label class="f"><span>${T.memberTitle}</span><input name="title" maxlength="80" value="${esc(m.title || '')}" placeholder="${T.memberTitlePh}"></label>
              <label class="f"><span>${T.license} (${T.licenseNote})</span><input name="license_no" maxlength="30" dir="ltr" inputmode="numeric" value="${esc(m.license_no || '')}"></label>
            </div>
            <label class="switch"><input type="checkbox" name="is_admin"${m.is_admin ? ' checked' : ''}><span>${T.isAdmin}</span></label>
            ${m.me ? '' : `<label class="switch"><input type="checkbox" name="active"${m.status === 'active' ? ' checked' : ''}><span>${T.activeInOffice}</span></label>
            <section class="group"><button type="button" class="btn ghost danger" id="remove">${T.remove}</button></section>`}`, async f => {
            await api('member-update', { body: body({ user: m.pid, role: f.role, is_admin: !!f.is_admin, title: f.title, license_no: f.license_no,
                ...(m.me ? {} : { status: f.active ? 'active' : 'suspended' }) }) });
            toast(T.saved);
            await refreshMe();
            route();
        });
        $('#remove', dlg)?.addEventListener('click', async () => {
            if (!confirm(T.removeAsk(m.name))) return;
            try { await api('member-remove', { body: body({ user: m.pid }) }); dlg.close(); toast(T.removed); viewOffice(); } catch (x) { toastError(x); }
        });
    }));
}
