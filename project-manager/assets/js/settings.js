// הגדרות מפתח: תחומים, תבניות שלבים ומנהלים.
'use strict';

const Settings = (() => {
    let bound = false, templateId = null;

    function bind() {
        if (bound) return;
        bound = true;
        $('#trade-add').addEventListener('submit', async e => {
            e.preventDefault();
            const name = $('#trade-name').value.trim();
            if (!name) return;
            await busy(null, null, async () => {
                ({ trades: state.trades } = await api('trade', { body: { name } }));
                $('#trade-name').value = '';
                renderTrades();
            });
        });
        $('#trade-list').addEventListener('click', tradeAction);
        $('#standalone').addEventListener('change', async e => {
            const on = e.target.checked;
            await busy(null, null, async () => {
                ({ standalone: state.standalone } = await api('standalone', { body: { on } }));
                $('#tools-link').hidden = state.standalone;
                toast(on ? 'האפליקציה עצמאית' : 'האפליקציה חלק מדף האפליקציות');
            });
            e.target.checked = !!state.standalone;
        });
        $('#users-list').addEventListener('click', userAction);
        $('#add-template').addEventListener('click', () => openTemplate(null));
        $('#template-list').addEventListener('click', e => {
            const row = e.target.closest('[data-template]');
            if (row) openTemplate(Number(row.dataset.template));
        });
        $('#template-dlg form').addEventListener('submit', e => { e.preventDefault(); saveTemplate(); });
        $('#template-dlg [data-delete]').addEventListener('click', deleteTemplate);
    }

    function show(pane) {
        bind();
        pane = ['general', 'trades', 'templates', 'access'].includes(pane) ? pane : 'general';
        $$('#view-settings [data-set]').forEach(a => a.setAttribute('aria-selected', String(a.dataset.set === pane)));
        $$('#view-settings .pane').forEach(el => { el.hidden = el.id !== 'set-' + pane; });
        if (pane === 'general') $('#standalone').checked = !!state.standalone;
        if (pane === 'trades') renderTrades();
        if (pane === 'templates') renderTemplates();
        if (pane === 'access') loadAccess();
    }

    // ---- תחומים ----

    function renderTrades() {
        Contacts.fillTradeFilter();
        $('#trade-list').innerHTML = state.trades.map(t => `<li data-id="${t.id}">
            <span class="n">${esc(t.name)}</span><small>${t.used ? t.used + ' ספקים' : ''}</small>
            <button class="icon-btn" data-act="rename" aria-label="שינוי שם ${esc(t.name)}">✏️</button>
            <button class="icon-btn" data-act="delete" aria-label="מחיקת ${esc(t.name)}" ${t.used ? 'disabled' : ''}>🗑️</button></li>`).join('');
    }

    async function tradeAction(e) {
        const b = e.target.closest('button[data-act]');
        if (!b) return;
        const id = Number(b.closest('li').dataset.id);
        const t = state.trades.find(x => x.id === id);
        await busy(null, null, async () => {
            if (b.dataset.act === 'rename') {
                const name = prompt('שם התחום', t.name);
                if (!name || name.trim() === t.name) return;
                ({ trades: state.trades } = await api('trade', { body: { id, name: name.trim() } }));
            } else {
                if (!confirm(`למחוק את התחום "${t.name}"?`)) return;
                ({ trades: state.trades } = await api('trade-delete', { body: { id } }));
            }
            renderTrades();
        });
    }

    // ---- תבניות שלבים ----

    function renderTemplates() {
        $('#template-list').innerHTML = state.templates.map(t => `<li class="card" data-template="${t.id}" tabindex="0" role="button">
            <div class="body"><div class="l1">${esc(t.name)}</div>
            <div class="l2">${t.stages.length} שלבים: ${t.stages.map(s => esc(s.name)).join(', ')}</div></div></li>`).join('')
            || '<li><span class="n hint">אין תבניות</span></li>';
    }

    function openTemplate(id) {
        const dlg = $('#template-dlg');
        const form = $('form', dlg);
        formError(form, '');
        templateId = id;
        const t = id ? state.templates.find(x => x.id === id) : { name: '', stages: [] };
        form.elements.name.value = t.name;
        form.elements.stages.value = t.stages.map(s => s.trade ? `${s.name} | ${s.trade}` : s.name).join('\n');
        $('[data-delete]', dlg).hidden = !id;
        dlg.showModal();
    }

    async function saveTemplate() {
        const form = $('#template-dlg form');
        const known = new Set(state.trades.map(t => t.name));
        const stages = form.elements.stages.value.split('\n').map(line => {
            const [name, trade = ''] = line.split('|').map(x => x.trim());
            return { name, trade };
        }).filter(s => s.name);
        const unknown = stages.filter(s => s.trade && !known.has(s.trade)).map(s => s.trade);
        if (unknown.length && !confirm(`התחומים האלה לא קיימים: ${[...new Set(unknown)].join(', ')}. השלבים ייווצרו בלי תחום. להמשיך?`)) return;
        await busy($('button[type=submit]', form), form, async () => {
            ({ templates: state.templates } = await api('template', { body: { id: templateId || undefined, name: form.elements.name.value.trim(), stages } }));
            $('#template-dlg').close();
            renderTemplates();
        });
    }

    async function deleteTemplate() {
        const t = state.templates.find(x => x.id === templateId);
        if (!t || !confirm(`למחוק את התבנית "${t.name}"? פרויקטים קיימים לא ישתנו.`)) return;
        await busy(null, $('#template-dlg form'), async () => {
            ({ templates: state.templates } = await api('template-delete', { body: { id: t.id } }));
            $('#template-dlg').close();
            renderTemplates();
        });
    }

    // ---- מנהלים ----

    const USER_STATUS = { pending: 'ממתין לאישור', active: 'פעיל', blocked: 'חסום' };

    async function loadAccess() {
        await busy(null, null, async () => renderUsers((await api('users')).users));
    }

    function renderUsers(users) {
        const day = iso => iso ? new Date(iso).toLocaleDateString('he-IL') : '';
        $('#users-list').innerHTML = users.map(u => {
            const acts = u.role === 'developer' ? '<span class="chip">מפתח</span>' : {
                pending: `<button class="btn primary" data-do="approve" data-id="${u.id}">אישור</button>
                          <button class="icon-btn" data-do="reject" data-id="${u.id}" aria-label="דחיית ${esc(u.display_name)}">✕</button>`,
                active:  `<button class="btn ghost danger" data-do="block" data-id="${u.id}">חסימה</button>`,
                blocked: `<button class="btn ghost" data-do="unblock" data-id="${u.id}">שחרור</button>`,
            }[u.status];
            const meta = [
                `<span dir="ltr">${esc(u.username)}</span>`,
                u.phone ? `<span dir="ltr">${esc(u.phone)}</span>` : '',
                u.status === 'pending' ? 'נרשם ' + day(u.created_at) : u.last_login_at ? 'נכנס ' + day(u.last_login_at) : 'לא נכנס עדיין',
                u.projects ? u.projects + ' פרויקטים' : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            return `<li class="card user-row st-${u.status}">
                ${avatar(u.display_name)}
                <div class="body"><div class="l1">${esc(u.display_name)}${u.role !== 'developer' ? `<span class="chip">${USER_STATUS[u.status]}</span>` : ''}</div>
                <div class="l2">${meta}</div></div>
                <div class="acts">${acts}</div></li>`;
        }).join('');
    }

    async function userAction(e) {
        const b = e.target.closest('button[data-do]');
        if (!b) return;
        const confirmText = { reject: 'לדחות את הבקשה ולמחוק אותה?', block: 'לחסום? המנהל יתנתק מיד, והפרויקטים שלו יישמרו.' }[b.dataset.do];
        if (confirmText && !confirm(confirmText)) return;
        await busy(b, null, async () => {
            renderUsers((await api('user', { body: { id: Number(b.dataset.id), do: b.dataset.do } })).users);
            toast({ approve: 'אושר — יכול להיכנס', reject: 'הבקשה נדחתה', block: 'נחסם', unblock: 'שוחרר' }[b.dataset.do]);
        });
    }

    return { show };
})();
