// הגדרות: הודעות (לכל מנהל); כללי, תחומים, תבניות שלבים, מנהלים ויומן — למפתח.
'use strict';

const Settings = (() => {
    let bound = false, templateId = null;
    // השפה של הנוסחים שבעריכה (הגדרות ← הודעות) — ברירת המחדל: שפת הממשק
    let msgLang = I18n.lang();

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
            out.textContent = t('שולח…');
            try {
                const r = await api('mail-test', { body: {} });
                out.textContent = r.sent ? t('השרת קיבל את המייל ל-{to} (מאת {from}). אם לא הגיע תוך כמה דקות — בדוק בספאם.', { to: r.to, from: r.from })
                    : t('השרת לא הצליח לשלוח ל-{to}. נרשם ביומן.', { to: r.to });
            } catch (e) { out.textContent = e.message; }
        });
        $('#standalone').addEventListener('change', async e => {
            const on = e.target.checked;
            await busy(null, null, async () => {
                ({ standalone: state.standalone } = await api('standalone', { body: { on } }));
                toast(t(on ? 'האפליקציה עצמאית' : 'האפליקציה חלק מדף האפליקציות'));
            });
            e.target.checked = !!state.standalone;
        });
        // הספים של ההתראות הכספיות (כ2)
        $('#alerts-form').addEventListener('submit', async e => {
            e.preventDefault();
            const f = $('#alerts-form'), el = f.elements;
            const body = Object.fromEntries(['days_before', 'proof_days', 'overpay', 'budget_warn', 'invoice_days'].map(k => [k, el[k].value]));
            body.notify_contacts = el.notify_contacts.checked;
            await busy($('button', f), f, async () => {
                ({ fin_alerts: state.finAlerts } = await api('fin-alerts', { body }));
                formError(f, '');
                toast(t('נשמר'));
            });
        });
        // שיעור המע"מ — לסכומים חדשים (אגף הכספים)
        $('#vat-form').addEventListener('submit', async e => {
            e.preventDefault();
            await busy($('#vat-form .btn'), null, async () => {
                ({ vat_rate: state.vatRate } = await api('vat-rate', { body: { rate: Number($('#vat-rate').value) } }));
                toast(t('נשמר: מע"מ {n}%', { n: state.vatRate }));
            });
        });
        $('#max-upload-form').addEventListener('submit', async e => {
            e.preventDefault();
            await busy($('#max-upload-form .btn'), null, async () => {
                ({ max_upload: state.maxUpload } = await api('max-upload', { body: { mb: Number($('#max-upload').value) } }));
                toast(t('נשמר: עד {n}MB', { n: Math.round(state.maxUpload / 1048576) }));
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
        let timer;
        $('#log-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadLog(), 300); });
        $('#log-level').addEventListener('change', () => loadLog());
        $('#log-cat').addEventListener('change', () => loadLog());
        $('#log-more').addEventListener('click', () => loadLog(true));
        $('#token-copy').addEventListener('click', () => copy($('#token-url').textContent, t('הקישור הועתק')));
        $('#log-copy').addEventListener('click', () => copy(logText(), t('היומן הועתק')));
        $('#token-rotate').addEventListener('click', async () => {
            if (!confirm(t('להחליף את האסימון? הקישור הנוכחי יפסיק לעבוד מיד.'))) return;
            await busy(null, null, async () => { showToken(await api('log-token', { body: {} })); toast(t('הוחלף')); });
        });
        $('#add-template').addEventListener('click', () => openTemplate(null));
        $('#template-list').addEventListener('click', e => {
            const row = e.target.closest('[data-template]');
            if (row) openTemplate(Number(row.dataset.template));
        });
        $('#template-dlg form').addEventListener('submit', e => { e.preventDefault(); saveTemplate(); });
        $('#template-dlg [data-delete]').addEventListener('click', deleteTemplate);
    }

    function fillAlerts() {
        const el = $('#alerts-form').elements, c = state.finAlerts || {};
        for (const k of ['days_before', 'proof_days', 'overpay', 'budget_warn', 'invoice_days']) el[k].value = c[k] ?? '';
        el.notify_contacts.checked = c.notify_contacts !== false;
    }

    function show(pane) {
        bind();
        // מנהל רואה רק "הודעות"; המפתח — הכול
        const allowed = isDev() ? ['messages', 'alerts', 'general', 'trades', 'templates', 'access', 'log', 'tasks'] : ['messages', 'alerts'];
        pane = allowed.includes(pane) ? pane : 'messages';
        $$('#view-settings [data-set]').forEach(a => a.setAttribute('aria-selected', String(a.dataset.set === pane)));
        $$('#view-settings .pane').forEach(el => { el.hidden = el.id !== 'set-' + pane; });
        if (pane === 'general') {
            $('#standalone').checked = !!state.standalone;
            $('#max-upload').value = Math.round(state.maxUpload / 1048576);
            $('#vat-rate').value = state.vatRate ?? 18;
        }
        if (pane === 'messages') renderMsgTemplates();
        if (pane === 'alerts') fillAlerts();
        if (pane === 'trades') renderTrades();
        if (pane === 'templates') renderTemplates();
        if (pane === 'access') loadAccess();
        if (pane === 'log') { loadLog(); loadToken(); }
        if (pane === 'tasks') DevLoop.show($('#set-tasks')); // מטלות ושיח עם Claude (DevLoop — devloop.js)
    }

    // ---- נוסחי הודעות (שלב 11) ----

    // המשתנים בנוסח — בשמם העברי; בשפה אחרת מוצג הכינוי שלהם (state.msgVars[שפה]), ושניהם עובדים
    const MSG_VARS = ['שם', 'פרויקט', 'שלב', 'סכום', 'פרטים', 'קישור', 'מנהל']; // i18n-none

    /** בורר השפה והמשתנים — מעל הנוסחים; נוצר פעם אחת. */
    function msgLangBar() {
        let bar = $('#msg-lang-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'msg-lang-bar';
            bar.className = 'toolbar';
            bar.innerHTML = `<label class="f"><span>${esc(t('שפת הנוסח'))}</span>
                <select id="msg-lang" data-no-i18n>${Object.entries(I18n.LANGS).map(([k, v]) => `<option value="${k}">${esc(v.name)}</option>`).join('')}</select></label>`;
            $('#msg-templates').before(bar);
            // המשתנים — בשורת ההסבר שמעל (#msg-vars-hint); אם אין — מתחת לבורר
            if (!$('#msg-vars-hint')) bar.insertAdjacentHTML('beforeend', '<p class="hint" id="msg-vars-hint"></p>');
            $('#msg-lang').addEventListener('change', e => { msgLang = e.target.value; renderMsgTemplates(); });
        }
        $('#msg-lang').value = msgLang;
        const alias = state.msgVars?.[msgLang] || {};
        $('#msg-vars-hint').innerHTML = `${esc(t('משתנים:'))} ` + MSG_VARS.map(v => `<code dir="auto">{${esc(alias[v] || v)}}</code>`).join(' ') + '.';
    }

    function renderMsgTemplates() {
        msgLangBar();
        const dir = I18n.LANGS[msgLang]?.dir || 'rtl';
        const list = (state.msgTemplates || []).filter(m => (m.lang || 'he') === msgLang);
        $('#msg-templates').innerHTML = list.map(m => `<form class="msg-tpl" data-key="${esc(m.key)}" data-lang="${esc(msgLang)}" novalidate>
            <div class="mt-head"><b class="one">${esc(m.label)}</b><span class="chip">${esc(m.to)}</span>${m.custom ? `<span class="chip">${esc(t('נוסח שלך'))}</span>` : ''}</div>
            <label class="f"><span>${esc(t('נושא (במייל)'))}</span><input name="subject" maxlength="200" dir="${dir}" lang="${esc(msgLang)}" value="${esc(m.subject)}"></label>
            <label class="f"><span>${esc(t('הנוסח'))}</span><textarea name="body" rows="6" maxlength="3000" dir="${dir}" lang="${esc(msgLang)}">${esc(m.body)}</textarea></label>
            <p class="error" role="alert" hidden></p>
            <div class="row-btns"><button class="btn primary sm">${esc(t('שמירה'))}</button>${m.custom ? `<button type="button" class="btn ghost sm" data-reset>${esc(t('חזרה לנוסח המקורי'))}</button>` : ''}</div>
        </form>`).join('');
    }

    async function saveMsgTemplate(form, reset) {
        const el = form.elements;
        await busy($('.btn.primary', form), form, async () => {
            // התשובה — כל הנוסחים, בכל השפות
            ({ templates: state.msgTemplates } = await api('msg-template', { body: { key: form.dataset.key, lang: form.dataset.lang, subject: el.subject.value, body: el.body.value, reset } }));
            renderMsgTemplates();
            toast(t(reset ? 'חזר לנוסח המקורי' : 'הנוסח נשמר'));
        });
    }

    // ---- תחומים ----

    function renderTrades() {
        Contacts.fillTradeFilter();
        $('#trade-list').innerHTML = state.trades.map(tr => `<li data-id="${tr.id}">
            <span class="n">${esc(t(tr.name))}</span><small>${tr.used ? esc(t('{n} ספקים', { n: tr.used })) : ''}</small>
            <button class="icon-btn" data-act="rename" aria-label="${esc(t('שינוי שם {name}', { name: tr.name }))}">✏️</button>
            <button class="icon-btn" data-act="delete" aria-label="${esc(t('מחיקת {name}', { name: tr.name }))}" ${tr.used ? 'disabled' : ''}>🗑️</button></li>`).join('');
    }

    async function tradeAction(e) {
        const b = e.target.closest('button[data-act]');
        if (!b) return;
        const id = Number(b.closest('li').dataset.id);
        const tr = state.trades.find(x => x.id === id);
        await busy(null, null, async () => {
            if (b.dataset.act === 'rename') {
                // שם שנזרע מוצג מתורגם; לא שונה — לא נוגעים (שלא יוחלף בתרגום)
                const name = prompt(t('שם התחום'), t(tr.name));
                if (!name || name.trim() === tr.name || name.trim() === t(tr.name)) return;
                ({ trades: state.trades } = await api('trade', { body: { id, name: name.trim() } }));
            } else {
                if (!confirm(t('למחוק את התחום "{name}"?', { name: tr.name }))) return;
                ({ trades: state.trades } = await api('trade-delete', { body: { id } }));
            }
            renderTrades();
        });
    }

    // ---- תבניות שלבים ----

    function renderTemplates() {
        $('#template-list').innerHTML = state.templates.map(tp => `<li class="card" data-template="${tp.id}" tabindex="0" role="button">
            <div class="body"><div class="l1">${esc(t(tp.name))}</div>
            <div class="l2">${esc(t('{n} שלבים: {names}', { n: tp.stages.length, names: tp.stages.map(s => t(s.name)).join(', ') }))}</div></div></li>`).join('')
            || `<li><span class="n hint">${esc(t('אין תבניות'))}</span></li>`;
    }

    function openTemplate(id) {
        const dlg = $('#template-dlg');
        const form = $('form', dlg);
        formError(form, '');
        templateId = id;
        const tp = id ? state.templates.find(x => x.id === id) : { name: '', stages: [] };
        // שמות שנזרעו — מוצגים מתורגמים; בשמירה חוזרים למקור אם לא שונו (backName)
        form.elements.name.value = t(tp.name);
        form.elements.stages.value = tp.stages.map(s => s.trade ? `${t(s.name)} | ${t(s.trade)}` : t(s.name)).join('\n');
        $('[data-delete]', dlg).hidden = !id;
        dlg.showModal();
    }

    async function saveTemplate() {
        const form = $('#template-dlg form');
        const known = new Set(state.trades.map(tr => tr.name));
        const orig = templateId ? state.templates.find(x => x.id === templateId) : null;
        // התרגום ← השם המקורי: שלבי התבנית והתחומים
        const back = new Map([...(orig?.stages || []).flatMap(s => [[t(s.name), s.name], [t(s.trade || ''), s.trade || '']]), ...state.trades.map(tr => [t(tr.name), tr.name])]);
        const backName = s => (back.has(s) ? back.get(s) : s);
        const stages = form.elements.stages.value.split('\n').map(line => {
            const [name, trade = ''] = line.split('|').map(x => x.trim());
            return { name: backName(name), trade: backName(trade) };
        }).filter(s => s.name);
        const name = form.elements.name.value.trim();
        const unknown = stages.filter(s => s.trade && !known.has(s.trade)).map(s => s.trade);
        if (unknown.length && !confirm(t('התחומים האלה לא קיימים: {names}. השלבים ייווצרו בלי תחום. להמשיך?', { names: [...new Set(unknown)].join(', ') }))) return;
        await busy($('button[type=submit]', form), form, async () => {
            ({ templates: state.templates } = await api('template', { body: { id: templateId || undefined, name: orig && name === t(orig.name) ? orig.name : name, stages } }));
            $('#template-dlg').close();
            renderTemplates();
        });
    }

    async function deleteTemplate() {
        const tp = state.templates.find(x => x.id === templateId);
        if (!tp || !confirm(t('למחוק את התבנית "{name}"? פרויקטים קיימים לא ישתנו.', { name: tp.name }))) return;
        await busy(null, $('#template-dlg form'), async () => {
            ({ templates: state.templates } = await api('template-delete', { body: { id: tp.id } }));
            $('#template-dlg').close();
            renderTemplates();
        });
    }

    // ---- הרשומים (מנהלים) — נפרדים לגמרי מהמשתמשים של דף הכלים ----

    const USER_STATUS = { pending: 'ממתין לאישור', active: 'פעיל', blocked: 'חסום' }; // i18n-ok
    let users = [], userId = null;

    async function loadAccess() {
        await busy(null, null, async () => {
            const r = await api('users');
            renderUsers(r.users);
            renderOrphans(r.orphans);
        });
    }

    const day = iso => iso ? new Date(iso).toLocaleDateString(I18n.locale()) : '';

    function renderUsers(list) {
        users = list;
        $('#users-list').innerHTML = users.map(u => {
            const meta = [
                `<span dir="ltr">${esc(u.username)}</span>`,
                u.email ? `<span dir="ltr">${esc(u.email)}</span>` : `<span class="muted">${esc(t('אין אימייל'))}</span>`,
                esc(u.status === 'pending' ? t('נרשם {date}', { date: day(u.created_at) }) : u.last_login_at ? t('נכנס {date}', { date: day(u.last_login_at) }) : t('לא נכנס עדיין')),
                u.projects ? esc(t('{n} פרויקטים', { n: u.projects })) : '',
                u.reset_pending ? esc(t('נשלח איפוס')) : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            const chip = u.role === 'developer' ? `<span class="chip">${esc(t('מפתח'))}</span>` : `<span class="chip us-${u.status}">${esc(t(USER_STATUS[u.status]))}</span>`;
            const quick = u.status === 'pending' ? `<button class="btn primary" data-quick="approve" data-id="${u.id}">${esc(t('אישור'))}</button>` : '';
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
        box.innerHTML = `<span>${esc(t('{projects} פרויקטים ו-{contacts} אנשי קשר נוצרו לפני שהיו לאפליקציה משתמשים משלה, ואין להם בעלים.', { projects: o.projects, contacts: o.contacts }))}</span>
            <div class="toolbar"><select id="orphans-to" aria-label="${esc(t('למי לשייך'))}">${options(users.filter(u => u.status === 'active').map(u => ({ value: u.id, label: u.display_name })), state.user.id)}</select>
            <button class="btn primary" id="orphans-claim">${esc(t('שיוך'))}</button></div>`;
        $('#orphans-claim').onclick = async () => {
            await busy($('#orphans-claim'), null, async () => {
                const r = await api('claim-orphans', { body: { to: Number($('#orphans-to').value) } });
                renderUsers(r.users); renderOrphans(r.orphans); toast(t('שויכו {n}', { n: r.moved }));
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
            u.role === 'developer' ? t('מפתח') : t(USER_STATUS[u.status]),
            t('נרשם {date}', { date: day(u.created_at) }), u.approved_at && u.role !== 'developer' ? t('אושר {date}', { date: day(u.approved_at) }) : '',
            u.last_login_at ? t('נכנס לאחרונה {date}', { date: day(u.last_login_at) }) : t('לא נכנס עדיין'),
            t('{n} מכשירים מחוברים', { n: u.sessions }), t('{n} פרויקטים', { n: u.projects }), t('{n} אנשי קשר', { n: u.contacts }),
        ].filter(Boolean).join(' · ');
        for (const f of ['display_name', 'username', 'email', 'phone', 'notes']) form.elements[f].value = u[f] || '';
        const dev = u.role === 'developer';
        const acts = dev ? ['reset', 'logout'] : {
            pending: ['approve', 'reject'],
            active: ['reset', 'logout', 'block', 'delete'],
            blocked: ['unblock', 'reset', 'delete'],
        }[u.status];
        // i18n-ok-start
        const LABEL = { approve: 'אישור', reject: 'דחיית הבקשה', reset: 'שליחת איפוס סיסמה', logout: 'ניתוק מכל המכשירים',
                        block: 'חסימה', unblock: 'שחרור חסימה', delete: 'מחיקה' };
        // i18n-ok-end
        $('#user-actions').innerHTML = acts.map(a =>
            `<button type="button" class="btn ${a === 'approve' ? 'primary' : 'ghost'}${['block', 'delete', 'reject'].includes(a) ? ' danger' : ''}" data-act="${a}">${esc(t(LABEL[a]))}</button>`).join('');
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
            toast(t('נשמר'));
        });
    }

    async function userAction(act, id = userId, btn = null) {
        const u = users.find(x => x.id === id);
        // i18n-ok-start
        const ask = {
            reject: 'לדחות את הבקשה של {name} ולמחוק אותה?',
            block: 'לחסום את {name}? הוא יתנתק מיד, והפרויקטים שלו יישמרו.',
            delete: 'למחוק את {name} לצמיתות?',
            logout: 'לנתק את {name} מכל המכשירים?',
            transfer: 'להעביר את כל הפרויקטים ואנשי הקשר של {name}?',
        }[act];
        // i18n-ok-end
        const confirmText = ask && t(ask, { name: u.display_name });
        if (confirmText && !confirm(confirmText)) return;
        const body = { id, do: act };
        if (act === 'transfer') body.to = Number($('#user-dlg [name=transfer_to]').value);
        await busy(btn, $('#user-dlg form'), async () => {
            const r = await api('user', { body });
            renderUsers(r.users);
            if ($('#user-dlg').open) $('#user-dlg').close();
            if (act === 'reset') return showReset(r);
            if (act === 'transfer') { toast(t('הועבר ל{name}', { name: r.transferred_to })); return; }
            toast(t({ approve: 'אושר — יכול להיכנס', reject: 'הבקשה נדחתה', block: 'נחסם', unblock: 'שוחרר', // i18n-ok
                    logout: 'נותק מכל המכשירים', delete: 'נמחק' }[act])); // i18n-ok
        });
    }

    function showReset(r) {
        const text = t('שלום {name}, זה קישור לקביעת סיסמה חדשה בניהול הפרויקטים (שם המשתמש: {username}):\n{link}', { name: r.display_name, username: r.username, link: r.reset_link });
        $('#reset-status').textContent = r.mailed ? t('הקישור נשלח לאימייל {email}. אפשר לשלוח אותו גם בדרך אחרת:', { email: r.email })
            : r.email ? t('השליחה לאימייל {email} לא הצליחה — שלח את הקישור בדרך אחרת:', { email: r.email }) : t('אין לו אימייל — שלח את הקישור בדרך אחרת:');
        $('#reset-link').textContent = r.reset_link;
        const wa = $('#reset-wa');
        wa.hidden = !r.phone;
        wa.href = r.phone ? `https://wa.me/${waNumber(r.phone)}?text=${encodeURIComponent(text)}` : '#';
        $('#reset-mail').href = `mailto:${encodeURIComponent(r.email || '')}?subject=${encodeURIComponent(t('קביעת סיסמה — ניהול פרויקטים'))}&body=${encodeURIComponent(text)}`;
        $('#reset-copy').onclick = () => copy(r.reset_link, t('הקישור הועתק'));
        $('#reset-dlg').showModal();
    }

    // ---- יומן ----

    const LOG_CAT = { auth: 'כניסה', api: 'בקשה', data: 'נתונים', admin: 'מפתח', client: 'דפדפן', php: 'שרת' }; // i18n-ok
    /** "12 שגיאות" — המספר מודגש, בכל סדר מילים. */
    const bold = (key, n) => esc(t(key, { n })).replace(String(n), `<b>${n}</b>`);
    let logRows = [];

    async function loadLog(more = false) {
        const params = { level: $('#log-level').value, category: $('#log-cat').value, q: $('#log-q').value.trim(), limit: 200 };
        if (more && logRows.length) params.before_id = logRows[logRows.length - 1].id;
        await busy(null, null, async () => {
            const { entries, stats } = await api('log', { params });
            logRows = more ? logRows.concat(entries) : entries;
            $('#log-more').hidden = entries.length < 200;
            // i18n-ok-start — bold מתרגם
            $('#log-stats').innerHTML = `<span>${bold('{n} רשומות', Number(stats.total ?? 0))}</span>
                <span>${esc(t('24 שעות:'))} <span class="e">${bold('{n} שגיאות', Number(stats.last24h?.error ?? 0))}</span> · ${bold('{n} אזהרות', Number(stats.last24h?.warn ?? 0))} · ${bold('{n} מידע', Number(stats.last24h?.info ?? 0))}</span>`;
            // i18n-ok-end
            $('#log-list').innerHTML = logRows.map(r => {
                const at = new Date(r.at);
                const who = r.username || (r.user_id ? '#' + r.user_id : '');
                return `<li><details><summary>
                    <span class="lv ${esc(r.level)}" aria-label="${esc(r.level)}"></span>
                    <span class="ev">${esc(r.event)}</span>
                    <span class="msg">${esc([LOG_CAT[r.category] ? t(LOG_CAT[r.category]) : r.category, who, r.message, r.status ? '→' + r.status : ''].filter(Boolean).join(' · '))}</span>
                    <time datetime="${esc(r.at)}">${at.toLocaleDateString(I18n.locale(), { day: 'numeric', month: 'numeric' })} ${at.toLocaleTimeString(I18n.locale(), { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</time>
                </summary><pre>${esc(JSON.stringify({ ...r, data: r.data }, null, 1))}</pre></details></li>`;
            }).join('') || `<li><p class="empty">${esc(t('אין רשומות'))}</p></li>`;
        });
    }

    /** מה שמוצג, כטקסט — להדבקה בצ'אט. */
    function logText() {
        return logRows.map(r => [r.at, r.level.toUpperCase(), r.category, r.event, r.username || (r.user_id ? '#' + r.user_id : '-'), r.ip,
            r.method + (r.action ? ' ' + r.action : '') + (r.status ? ' →' + r.status : '') + (r.ms != null ? ' ' + r.ms + 'ms' : ''),
            r.message ? '| ' + r.message : '', JSON.stringify(r.data), 'req=' + r.req].join(' ')).join('\n');
    }

    function showToken(tok) {
        const url = new URL('log.php?token=' + tok.token, location.href).href;
        $('#token-url').textContent = url;
        $('#token-exp').textContent = t('עד {date}', { date: new Date(tok.expires_at).toLocaleDateString(I18n.locale(), { weekday: 'long', day: 'numeric', month: 'numeric' }) });
    }

    async function loadToken() {
        await busy(null, null, async () => showToken(await api('log-token')));
    }

    async function copy(text, done) {
        try { await navigator.clipboard.writeText(text); toast(done); }
        catch { prompt(t('להעתקה:'), text); }
    }

    return { show };
})();
