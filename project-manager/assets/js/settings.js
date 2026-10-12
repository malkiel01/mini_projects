// הגדרות: הודעות (לכל מנהל); כללי, תחומים, תבניות שלבים, מנהלים ויומן — למפתח.
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
        $('#msg-templates').addEventListener('submit', e => { e.preventDefault(); saveMsgTemplate(e.target, false); });
        $('#msg-templates').addEventListener('click', e => { if (e.target.closest('[data-reset]')) saveMsgTemplate(e.target.closest('form'), true); });
        $('#mail-test').addEventListener('click', async () => {
            const out = $('#mail-test-result');
            out.textContent = 'שולח…';
            try {
                const r = await api('mail-test', { body: {} });
                out.textContent = r.sent ? `השרת קיבל את המייל ל-${r.to} (מאת ${r.from}). אם לא הגיע תוך כמה דקות — בדוק בספאם.` : `השרת לא הצליח לשלוח ל-${r.to}. נרשם ביומן.`;
            } catch (e) { out.textContent = e.message; }
        });
        $('#standalone').addEventListener('change', async e => {
            const on = e.target.checked;
            await busy(null, null, async () => {
                ({ standalone: state.standalone } = await api('standalone', { body: { on } }));
                $('#tools-link').hidden = state.standalone;
                toast(on ? 'האפליקציה עצמאית' : 'האפליקציה חלק מדף האפליקציות');
            });
            e.target.checked = !!state.standalone;
        });
        $('#max-upload-form').addEventListener('submit', async e => {
            e.preventDefault();
            await busy($('#max-upload-form .btn'), null, async () => {
                ({ max_upload: state.maxUpload } = await api('max-upload', { body: { mb: Number($('#max-upload').value) } }));
                toast('נשמר: עד ' + Math.round(state.maxUpload / 1048576) + 'MB');
            });
        });
        $('#users-list').addEventListener('click', e => {
            const q = e.target.closest('[data-quick]');
            if (q) { e.stopPropagation(); userAction(q.dataset.quick, Number(q.dataset.id), q); return; }
            const row = e.target.closest('[data-user]');
            if (row) openUser(Number(row.dataset.user));
        });
        $('#users-list').addEventListener('keydown', e => {
            const row = e.target.closest('[data-user]');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openUser(Number(row.dataset.user)); }
        });
        $('#user-dlg form').addEventListener('submit', e => { e.preventDefault(); saveUser(); });
        $('#user-dlg').addEventListener('click', e => {
            const b = e.target.closest('[data-act]');
            if (b) userAction(b.dataset.act, userId, b);
        });
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
        // מנהל רואה רק "הודעות"; המפתח — הכול
        const allowed = isDev() ? ['messages', 'general', 'trades', 'templates', 'access', 'log'] : ['messages'];
        pane = allowed.includes(pane) ? pane : 'messages';
        $$('#view-settings [data-set]').forEach(a => a.setAttribute('aria-selected', String(a.dataset.set === pane)));
        $$('#view-settings .pane').forEach(el => { el.hidden = el.id !== 'set-' + pane; });
        if (pane === 'general') {
            $('#standalone').checked = !!state.standalone;
            $('#max-upload').value = Math.round(state.maxUpload / 1048576);
        }
        if (pane === 'messages') renderMsgTemplates();
        if (pane === 'trades') renderTrades();
        if (pane === 'templates') renderTemplates();
        if (pane === 'access') loadAccess();
        if (pane === 'log') { loadLog(); loadToken(); }
    }

    // ---- נוסחי הודעות (שלב 11) ----

    function renderMsgTemplates() {
        $('#msg-templates').innerHTML = (state.msgTemplates || []).map(t => `<form class="msg-tpl" data-key="${t.key}" novalidate>
            <div class="mt-head"><b class="one">${esc(t.label)}</b><span class="chip">${esc(t.to)}</span>${t.custom ? '<span class="chip">נוסח שלך</span>' : ''}</div>
            <label class="f"><span>נושא (במייל)</span><input name="subject" maxlength="200" value="${esc(t.subject)}"></label>
            <label class="f"><span>הנוסח</span><textarea name="body" rows="6" maxlength="3000">${esc(t.body)}</textarea></label>
            <p class="error" role="alert" hidden></p>
            <div class="row-btns"><button class="btn primary sm">שמירה</button>${t.custom ? '<button type="button" class="btn ghost sm" data-reset>חזרה לנוסח המקורי</button>' : ''}</div>
        </form>`).join('');
    }

    async function saveMsgTemplate(form, reset) {
        const el = form.elements;
        await busy($('.btn.primary', form), form, async () => {
            ({ templates: state.msgTemplates } = await api('msg-template', { body: { key: form.dataset.key, subject: el.subject.value, body: el.body.value, reset } }));
            renderMsgTemplates();
            toast(reset ? 'חזר לנוסח המקורי' : 'הנוסח נשמר');
        });
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

    // ---- הרשומים (מנהלים) — נפרדים לגמרי מהמשתמשים של דף הכלים ----

    const USER_STATUS = { pending: 'ממתין לאישור', active: 'פעיל', blocked: 'חסום' };
    let users = [], userId = null;

    async function loadAccess() {
        await busy(null, null, async () => {
            const r = await api('users');
            renderUsers(r.users);
            renderOrphans(r.orphans);
        });
    }

    const day = iso => iso ? new Date(iso).toLocaleDateString('he-IL') : '';

    function renderUsers(list) {
        users = list;
        $('#users-list').innerHTML = users.map(u => {
            const meta = [
                `<span dir="ltr">${esc(u.username)}</span>`,
                u.email ? `<span dir="ltr">${esc(u.email)}</span>` : '<span class="muted">אין אימייל</span>',
                u.status === 'pending' ? 'נרשם ' + day(u.created_at) : u.last_login_at ? 'נכנס ' + day(u.last_login_at) : 'לא נכנס עדיין',
                u.projects ? u.projects + ' פרויקטים' : '',
                u.reset_pending ? 'נשלח איפוס' : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            const chip = u.role === 'developer' ? '<span class="chip">מפתח</span>' : `<span class="chip us-${u.status}">${USER_STATUS[u.status]}</span>`;
            const quick = u.status === 'pending' ? `<button class="btn primary" data-quick="approve" data-id="${u.id}">אישור</button>` : '';
            return `<li class="card" data-user="${u.id}" tabindex="0" role="button" aria-label="${esc(u.display_name)}">
                ${avatar(u.display_name)}
                <div class="body"><div class="l1"><span class="one">${esc(u.display_name)}</span>${chip}</div><div class="l2">${meta}</div></div>
                <div class="acts">${quick}</div></li>`;
        }).join('');
    }

    function renderOrphans(o) {
        const box = $('#orphans');
        const n = (o?.projects || 0) + (o?.contacts || 0);
        box.hidden = !n;
        if (!n) return;
        box.innerHTML = `<span>${o.projects} פרויקטים ו-${o.contacts} אנשי קשר נוצרו לפני שהיו לאפליקציה משתמשים משלה, ואין להם בעלים.</span>
            <div class="toolbar"><select id="orphans-to" aria-label="למי לשייך">${options(users.filter(u => u.status === 'active').map(u => ({ value: u.id, label: u.display_name })), state.user.id)}</select>
            <button class="btn primary" id="orphans-claim">שיוך</button></div>`;
        $('#orphans-claim').onclick = async () => {
            await busy($('#orphans-claim'), null, async () => {
                const r = await api('claim-orphans', { body: { to: Number($('#orphans-to').value) } });
                renderUsers(r.users); renderOrphans(r.orphans); toast(`שויכו ${r.moved}`);
            });
        };
    }

    function openUser(id) {
        const u = users.find(x => x.id === id);
        if (!u) return;
        userId = id;
        const dlg = $('#user-dlg'), form = $('form', dlg);
        formError(form, '');
        $('#user-dlg-title').textContent = u.display_name;
        $('#user-meta').textContent = [
            u.role === 'developer' ? 'מפתח' : USER_STATUS[u.status],
            'נרשם ' + day(u.created_at), u.approved_at && u.role !== 'developer' ? 'אושר ' + day(u.approved_at) : '',
            u.last_login_at ? 'נכנס לאחרונה ' + day(u.last_login_at) : 'לא נכנס עדיין',
            `${u.sessions} מכשירים מחוברים`, `${u.projects} פרויקטים`, `${u.contacts} אנשי קשר`,
        ].filter(Boolean).join(' · ');
        for (const f of ['display_name', 'username', 'email', 'phone', 'notes']) form.elements[f].value = u[f] || '';
        const dev = u.role === 'developer';
        const acts = dev ? ['reset', 'logout'] : {
            pending: ['approve', 'reject'],
            active: ['reset', 'logout', 'block', 'delete'],
            blocked: ['unblock', 'reset', 'delete'],
        }[u.status];
        const LABEL = { approve: 'אישור', reject: 'דחיית הבקשה', reset: 'שליחת איפוס סיסמה', logout: 'ניתוק מכל המכשירים',
                        block: 'חסימה', unblock: 'שחרור חסימה', delete: 'מחיקה' };
        $('#user-actions').innerHTML = acts.map(a =>
            `<button type="button" class="btn ${a === 'approve' ? 'primary' : 'ghost'}${['block', 'delete', 'reject'].includes(a) ? ' danger' : ''}" data-act="${a}">${LABEL[a]}</button>`).join('');
        const others = users.filter(x => x.id !== id && x.status === 'active');
        $('#user-transfer').hidden = dev || !(u.projects + u.contacts) || !others.length;
        form.elements.transfer_to.innerHTML = options(others.map(x => ({ value: x.id, label: x.display_name })));
        dlg.showModal();
    }

    async function saveUser() {
        const form = $('#user-dlg form');
        const el = form.elements;
        await busy($('button[type=submit]', form), form, async () => {
            const r = await api('user-save', { body: { id: userId, display_name: el.display_name.value, username: el.username.value,
                email: el.email.value, phone: el.phone.value, notes: el.notes.value } });
            renderUsers(r.users);
            $('#user-dlg').close();
            toast('נשמר');
        });
    }

    async function userAction(act, id = userId, btn = null) {
        const u = users.find(x => x.id === id);
        const confirmText = {
            reject: `לדחות את הבקשה של ${u.display_name} ולמחוק אותה?`,
            block: `לחסום את ${u.display_name}? הוא יתנתק מיד, והפרויקטים שלו יישמרו.`,
            delete: `למחוק את ${u.display_name} לצמיתות?`,
            logout: `לנתק את ${u.display_name} מכל המכשירים?`,
            transfer: `להעביר את כל הפרויקטים ואנשי הקשר של ${u.display_name}?`,
        }[act];
        if (confirmText && !confirm(confirmText)) return;
        const body = { id, do: act };
        if (act === 'transfer') body.to = Number($('#user-dlg [name=transfer_to]').value);
        await busy(btn, $('#user-dlg form'), async () => {
            const r = await api('user', { body });
            renderUsers(r.users);
            if ($('#user-dlg').open) $('#user-dlg').close();
            if (act === 'reset') return showReset(r);
            toast({ approve: 'אושר — יכול להיכנס', reject: 'הבקשה נדחתה', block: 'נחסם', unblock: 'שוחרר',
                    logout: 'נותק מכל המכשירים', delete: 'נמחק', transfer: 'הועבר ל' + r.transferred_to }[act]);
        });
    }

    function showReset(r) {
        const text = `שלום ${r.display_name}, זה קישור לקביעת סיסמה חדשה בניהול הפרויקטים (שם המשתמש: ${r.username}):\n${r.reset_link}`;
        $('#reset-status').textContent = r.mailed ? `הקישור נשלח לאימייל ${r.email}. אפשר לשלוח אותו גם בדרך אחרת:`
            : r.email ? `השליחה לאימייל ${r.email} לא הצליחה — שלח את הקישור בדרך אחרת:` : 'אין לו אימייל — שלח את הקישור בדרך אחרת:';
        $('#reset-link').textContent = r.reset_link;
        const wa = $('#reset-wa');
        wa.hidden = !r.phone;
        wa.href = r.phone ? `https://wa.me/${waNumber(r.phone)}?text=${encodeURIComponent(text)}` : '#';
        $('#reset-mail').href = `mailto:${encodeURIComponent(r.email || '')}?subject=${encodeURIComponent('קביעת סיסמה — ניהול פרויקטים')}&body=${encodeURIComponent(text)}`;
        $('#reset-copy').onclick = () => copy(r.reset_link, 'הקישור הועתק');
        $('#reset-dlg').showModal();
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
