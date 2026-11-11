/* ניהול המערכת: משרדים ומשתמשים — נתונים תפעוליים בלבד. */
'use strict';

/** גיליון נימוק לפעולה רגישה (השעיה / ביטול השעיה) — אישור חוזר במסך, ונרשם ביומן. */
function reasonSheet(title, hint, onConfirm) {
    sheet(title, `<p class="hint">${hint}</p>
        <label class="f"><span>${T.reason}</span><textarea name="reason" rows="3" maxlength="500" required placeholder="${T.reasonPh}"></textarea></label>`,
        f => onConfirm(f.reason), T.confirmDo);
}

async function viewSystem(tab) {
    setHead(T.sysTitle, null);
    const users = tab === 'users';
    const tabs = tabsHtml([['#/system', T.tabOffices], ['#/system/users', T.tabUsers], ['#/system/doc-types', T.tabDocTypes], ['#/system/attributes', T.f.catalog], ['#/system/sources', T.s.catalog], ['#/system/channels', T.ch.sysTab], ['#/system/inbox', T.ch.inboxTab]],
        ['doc-types', 'attributes', 'sources', 'channels', 'inbox'].includes(tab) ? '#/system/' + tab : users ? '#/system/users' : '#/system');
    if (tab === 'channels') return viewSysChannels(tabs);
    if (tab === 'inbox') return viewSysInbox(tabs);
    if (tab === 'sources') {
        const v = await viewSysSources();
        App.main.innerHTML = tabs + v.html;
        v.bind();
        return;
    }
    if (tab === 'attributes') {
        const v = await viewAttributes();
        App.main.innerHTML = tabs + v.html;
        v.bind();
        return;
    }
    if (tab === 'doc-types') {
        const v = await viewDocTypes();
        App.main.innerHTML = tabs + v.html;
        v.bind();
        return;
    }
    if (users) {
        const d = await api('sys-users');
        App.main.innerHTML = `${tabs}<ul class="list">${d.users.map(u => `<li class="card" tabindex="0" role="button" data-user="${u.pid}">
            <span class="avatar" style="--h:${hue(u.pid)}" aria-hidden="true">${esc(initials(u.name))}</span>
            <div class="body"><div class="l1"><span>${esc(u.name || u.email || '—')}</span></div>
            <div class="l2"><span dir="ltr">${esc(u.email || '')}</span><i class="sep" aria-hidden="true">·</i>${u.offices ? T.officesCount(u.offices) : T.noOffice}<i class="sep" aria-hidden="true">·</i>${esc(seen(u.last_seen_at))}</div></div>
            ${u.status === 'suspended' ? `<span class="chip bad">${T.statuses.suspended}</span>` : u.is_sysadmin ? `<span class="chip info">${T.tagSys}</span>` : u.is_developer ? `<span class="chip info">${T.tagDev}</span>` : ''}</li>`).join('')}</ul>`;
        $$('[data-user]').forEach(li => onActivate(li, () => {
            const u = d.users.find(x => x.pid === li.dataset.user);
            const dlg = sheet(u.name || u.email || '—', `
                <p class="hint" dir="auto">${esc(u.email || '')}</p>
                <section class="group"><h3>${T.flagsTitle}</h3>
                  <label class="switch"><input type="checkbox" name="is_sysadmin"${u.is_sysadmin ? ' checked' : ''}><span>${T.flagSys}</span></label>
                  <p class="hint">${T.devFlagNote}</p></section>
                <section class="group"><button type="button" class="btn ghost${u.status === 'active' ? ' danger' : ''}" id="status">${u.status === 'active' ? T.suspendUser : T.unsuspendUser}</button></section>`, async f => {
                await api('sys-user-flags', { body: { user: u.pid, is_sysadmin: !!f.is_sysadmin } });
                toast(T.saved);
                await refreshMe();
                route();
            });
            $('#status', dlg).addEventListener('click', () => reasonSheet(u.status === 'active' ? T.suspendUser : T.unsuspendUser, T.suspendUserHint, async reason => {
                await api('sys-user-status', { body: { user: u.pid, status: u.status === 'active' ? 'suspended' : 'active', reason } });
                toast(T.saved);
                viewSystem('users');
            }));
        }));
        return;
    }
    const [d, cfg] = await Promise.all([api('sys-offices'), api('sys-settings')]);
    App.main.innerHTML = `${tabs}<p class="hint">${T.sysHint}</p>
        <div class="toolbar"><label class="switch" style="flex:1"><input type="checkbox" id="self-create"${cfg.settings.office_self_create ? ' checked' : ''}><span>${T.selfCreate}</span></label>
        <button class="btn primary" id="new-office"><span aria-hidden="true">+</span> ${T.sysNewOffice}</button></div>
        ${d.offices.length ? `<ul class="list">${d.offices.map(o => `<li class="card" tabindex="0" role="button" data-sys-office="${o.pid}">
        <span class="use-ic" aria-hidden="true">🏛️</span>
        <div class="body"><div class="l1"><span>${esc(o.name)}</span></div>
        <div class="l2">${T.members(o.members)}<i class="sep" aria-hidden="true">·</i>${o.last_activity ? esc(T.lastActivity(fmtDate(o.last_activity))) : T.noActivity}</div></div>
        ${o.status === 'suspended' ? `<span class="chip bad">${T.statuses.suspended}</span>` : o.no_active_members ? `<span class="chip warn">${T.noActiveMembers}</span>` : ''}</li>`).join('')}</ul>`
        : `<p class="empty">${T.noOffices}</p>`}`;
    $('#self-create').addEventListener('change', async e => {
        try {
            await api('sys-setting-set', { body: { name: 'office_self_create', value: e.target.checked } });
            toast(e.target.checked ? T.saved : T.selfCreateHint);
            await refreshMe();
        } catch (x) {
            e.target.checked = !e.target.checked;
            toastError(x);
        }
    });
    $('#new-office').addEventListener('click', () => sheet(T.sysNewOfficeTitle, `
        <p class="hint">${T.sysNewOfficeHint}</p>
        <label class="f"><span>${T.officeName}</span><input name="name" required maxlength="120" autocomplete="off"></label>
        <label class="f"><span>${T.firstAdminEmail}</span><input name="admin_email" type="email" dir="ltr" inputmode="email" required maxlength="200" autocomplete="off"></label>`, async f => {
        const r = await api('sys-office-create', { body: f });
        viewSystem();
        showInviteLink(r, false);
        return false;   // הגיליון נשאר פתוח — עכשיו עם קישור ההזמנה למנהל הראשון
    }, T.createOfficeDo));
    $$('[data-sys-office]').forEach(li => onActivate(li, async () => {
        try {
            const o = await api('sys-office', { params: { office: li.dataset.sysOffice } });
            const active = o.office.status === 'active';
            const dlg = sheet(o.office.name, `
                <p class="hint">${esc(T.created(fmtDate(o.office.created_at)))}<i class="sep" aria-hidden="true">·</i>${T.members(o.members.length)}</p>
                <ul class="list">${o.members.map(m => `<li class="card static"><div class="body"><div class="l1"><span>${esc(m.name)}</span></div>
                    <div class="l2">${esc(roleLabel(m.role, m.is_admin))}<i class="sep" aria-hidden="true">·</i><span dir="ltr">${esc(m.email || '')}</span></div></div>
                    ${m.status === 'suspended' ? `<span class="chip bad">${T.statuses.suspended}</span>` : ''}</li>`).join('')}</ul>
                <section class="group"><button type="button" class="btn ghost${active ? ' danger' : ''}" id="status">${active ? T.suspendOffice : T.unsuspendOffice}</button></section>`, null);
            $('#status', dlg).addEventListener('click', () => reasonSheet(active ? T.suspendOffice : T.unsuspendOffice, T.suspendOfficeHint, async reason => {
                await api('sys-office-status', { body: { office: o.office.pid, status: active ? 'suspended' : 'active', reason } });
                toast(T.saved);
                viewSystem();
            }));
        } catch (x) { toastError(x); }
    }));
}
