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
        let t;
        $('#log-q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => loadLog(), 300); });
        $('#log-level').addEventListener('change', () => loadLog());
        $('#log-cat').addEventListener('change', () => loadLog());
        $('#log-more').addEventListener('click', () => loadLog(true));
        $('#token-copy').addEventListener('click', () => copy($('#token-url').textContent, 'הקישור הועתק'));
        $('#log-copy').addEventListener('click', () => copy(logText(), 'היומן הועתק'));
        $('#token-rotate').addEventListener('click', async () => {
            if (!confirm('להחליף את האסימון? הקישור הנוכחי יפסיק לעבוד מיד.')) return;
            await busy(null, null, async () => { showToken(await api('log-token', { body: {} })); toast('הוחלף'); });
        });
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
        pane = ['general', 'trades', 'templates', 'access', 'log'].includes(pane) ? pane : 'general';
        $$('#view-settings [data-set]').forEach(a => a.setAttribute('aria-selected', String(a.dataset.set === pane)));
        $$('#view-settings .pane').forEach(el => { el.hidden = el.id !== 'set-' + pane; });
        if (pane === 'general') $('#standalone').checked = !!state.standalone;
        if (pane === 'trades') renderTrades();
        if (pane === 'templates') renderTemplates();
        if (pane === 'access') loadAccess();
        if (pane === 'log') { loadLog(); loadToken(); }
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
                active:  `<button class="icon-btn" data-do="reset" data-id="${u.id}" aria-label="איפוס הסיסמה של ${esc(u.display_name)}" title="איפוס סיסמה">🔑</button>
                          <button class="btn ghost danger" data-do="block" data-id="${u.id}">חסימה</button>`,
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
        const confirmText = {
            reject: 'לדחות את הבקשה ולמחוק אותה?',
            block: 'לחסום? המנהל יתנתק מיד, והפרויקטים שלו יישמרו.',
            reset: 'לקבוע סיסמה זמנית חדשה? המנהל יתנתק, והסיסמה תוצג לך פעם אחת כדי שתעביר לו.',
        }[b.dataset.do];
        if (confirmText && !confirm(confirmText)) return;
        await busy(b, null, async () => {
            const r = await api('user', { body: { id: Number(b.dataset.id), do: b.dataset.do } });
            renderUsers(r.users);
            if (r.temp_password) prompt(`הסיסמה הזמנית של ${r.username} (להעתיק ולהעביר לו; היא לא תוצג שוב):`, r.temp_password);
            else toast({ approve: 'אושר — יכול להיכנס', reject: 'הבקשה נדחתה', block: 'נחסם', unblock: 'שוחרר' }[b.dataset.do]);
        });
    }

    // ---- יומן ----

    const LOG_CAT = { auth: 'כניסה', api: 'בקשה', data: 'נתונים', admin: 'מפתח', client: 'דפדפן', php: 'שרת' };
    let logRows = [];

    async function loadLog(more = false) {
        const params = { level: $('#log-level').value, category: $('#log-cat').value, q: $('#log-q').value.trim(), limit: 200 };
        if (more && logRows.length) params.before_id = logRows[logRows.length - 1].id;
        await busy(null, null, async () => {
            const { entries, stats } = await api('log', { params });
            logRows = more ? logRows.concat(entries) : entries;
            $('#log-more').hidden = entries.length < 200;
            $('#log-stats').innerHTML = `<span><b>${stats.total ?? 0}</b> רשומות</span>
                <span>24 שעות: <span class="e"><b>${stats.last24h?.error ?? 0}</b> שגיאות</span> · <b>${stats.last24h?.warn ?? 0}</b> אזהרות · <b>${stats.last24h?.info ?? 0}</b> מידע</span>`;
            $('#log-list').innerHTML = logRows.map(r => {
                const t = new Date(r.at);
                const who = r.username || (r.user_id ? '#' + r.user_id : '');
                return `<li><details><summary>
                    <span class="lv ${esc(r.level)}" aria-label="${esc(r.level)}"></span>
                    <span class="ev">${esc(r.event)}</span>
                    <span class="msg">${esc([LOG_CAT[r.category] || r.category, who, r.message, r.status ? '→' + r.status : ''].filter(Boolean).join(' · '))}</span>
                    <time datetime="${esc(r.at)}">${t.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric' })} ${t.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</time>
                </summary><pre>${esc(JSON.stringify({ ...r, data: r.data }, null, 1))}</pre></details></li>`;
            }).join('') || '<li><p class="empty">אין רשומות</p></li>';
        });
    }

    /** מה שמוצג, כטקסט — להדבקה בצ'אט. */
    function logText() {
        return logRows.map(r => [r.at, r.level.toUpperCase(), r.category, r.event, r.username || (r.user_id ? '#' + r.user_id : '-'), r.ip,
            r.method + (r.action ? ' ' + r.action : '') + (r.status ? ' →' + r.status : '') + (r.ms != null ? ' ' + r.ms + 'ms' : ''),
            r.message ? '| ' + r.message : '', JSON.stringify(r.data), 'req=' + r.req].join(' ')).join('\n');
    }

    function showToken(t) {
        const url = new URL('log.php?token=' + t.token, location.href).href;
        $('#token-url').textContent = url;
        $('#token-exp').textContent = 'עד ' + new Date(t.expires_at).toLocaleDateString('he-IL', { weekday: 'long', day: 'numeric', month: 'numeric' });
    }

    async function loadToken() {
        await busy(null, null, async () => showToken(await api('log-token')));
    }

    async function copy(text, done) {
        try { await navigator.clipboard.writeText(text); toast(done); }
        catch { prompt('להעתקה:', text); }
    }

    return { show };
})();
