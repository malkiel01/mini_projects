/**
 * חריגה מהחוזה בהסכמת כולם — בלשונית "👷 ספקים" שבכספים, כשהמתג דולק בהגדרות (lib/deviations.php, README: "חריגה מהחוזה").
 * רשימת החריגות: מה, על מי, מי כבר הסכים ומי עוד לא; לכל צד — שליחת הקישור או רישום הסכמה. "+ חריגה מהחוזה" — חלון.
 */
const Deviations = (() => {
    let project = null, data = null, bound = false, payments = [];
    const ICON = { approved: '✅', rejected: '✖' };
    const contactOf = id => project.team.find(m => m.id === id);

    async function show(p, on) {
        project = p;
        bind();
        const root = $('#fin-deviations');
        if (!on) { root.innerHTML = ''; return; }
        try { data = await api('deviations', { params: { project_id: p.project.id } }); } catch (e) { toast(e.message, true); return; }
        render();
    }

    function bind() {
        if (bound) return;
        bound = true;
        $('#fin-deviations').addEventListener('click', e => {
            if (e.target.closest('[data-dev-new]')) { openNew(); return; }
            const m = e.target.closest('[data-dev-manual]');
            if (m) { manual(Number(m.dataset.devManual), m.dataset.party, Number(m.dataset.contact)); return; }
            const s = e.target.closest('[data-dev-send]');
            if (s) { sendLink(Number(s.dataset.devSend), Number(s.dataset.contact)); return; }
            const c = e.target.closest('[data-dev-cancel]');
            if (c) cancel(Number(c.dataset.devCancel));
        });
        const f = $('#dev-dlg form');
        f.addEventListener('submit', e => { e.preventDefault(); save(); });
        f.elements.type.addEventListener('change', fields);
        f.elements.scope.addEventListener('change', fields);
        f.elements.supplier_id.addEventListener('change', fields);
    }

    function render() {
        const list = data.deviations.filter(d => d.status !== 'cancelled');
        $('#fin-deviations').innerHTML = `<section class="fin-card dev-box"><header class="fin-head"><h2>⚖️ ${esc(t('חריגות מהחוזה'))}</h2>
                <button type="button" class="btn ghost sm" data-dev-new>+ ${esc(t('חריגה מהחוזה'))}</button></header>
            ${list.length ? `<ul class="list dev-list">${list.map(item).join('')}</ul>` : `<p class="hint">${esc(t('אין חריגות. חריגה חלה רק כשהלקוח, העו"ד וכל ספק שהיא נוגעת לו הסכימו.'))}</p>`}</section>`;
    }

    function item(d) {
        const open = ['pending', 'revoke_pending'].includes(d.status);
        const scope = d.scope === 'project' ? t('כל הפרויקט') : d.scope === 'milestone' ? `${d.supplier} · ${d.milestone}` : d.supplier;
        const what = d.type === 'retention_pct' ? t('{type}: {n}% במקום {c}%', { type: d.type_label, n: d.value, c: d.contract_pct }) : d.type_label;
        const parties = d.parties.map(x => `<li class="dev-party">
            <span>${x.decision ? ICON[x.decision] : '⏳'} ${esc(x.label)} — ${esc(x.name)}${x.decision ? ` <small class="muted">${esc(shortDate(x.at.slice(0, 10)))}${x.note ? ' · ' + esc(x.note) : ''}</small>` : ''}</span>
            ${open && !x.decision ? `<span class="row-btns"><button type="button" class="btn ghost sm" data-dev-send="${d.id}" data-contact="${x.contact_id}">📤</button>
                <button type="button" class="btn ghost sm" data-dev-manual="${d.id}" data-party="${x.party}" data-contact="${x.contact_id}">✍️ ${esc(t('רישום הסכמה'))}</button></span>` : ''}</li>`).join('');
        const cls = d.status === 'approved' ? 'fin-ok' : d.status === 'rejected' || d.status === 'revoked' ? '' : 'fin-warn';
        const cancelLabel = d.status === 'pending' ? t('ביטול הבקשה') : d.status === 'approved' ? t('ביטול החריגה (בהסכמת כולם)') : d.status === 'revoke_pending' ? t('השארת החריגה בתוקף') : '';
        return `<li class="dev-item">
            <div class="dev-top"><b class="one">${esc(what)}</b><span class="chip ${cls}">${esc(d.status_label)}</span></div>
            <div class="muted dev-meta">${esc(scope)}</div>
            <p class="dev-desc">${esc(d.description)}</p>
            ${d.phase === 'revoke' && open ? `<p class="hint">${esc(t('בקשה לבטל את החריגה — חוזרים לחוזה כשכולם מסכימים.'))}</p>` : ''}
            <ul class="dev-parties">${parties}</ul>
            ${cancelLabel ? `<div class="row-btns"><button type="button" class="btn ghost sm" data-dev-cancel="${d.id}">${esc(cancelLabel)}</button></div>` : ''}</li>`;
    }

    async function openNew() {
        const f = $('#dev-dlg form'), el = f.elements;
        f.reset();
        formError(f, '');
        el.type.innerHTML = options(Object.entries(data.types).map(([value, label]) => ({ value, label })), 'retention_waive');
        const sup = project.team.filter(c => c.kind === 'supplier');
        el.supplier_id.innerHTML = options(sup.map(c => ({ value: c.id, label: c.name })), '', t('בחירה'));
        try { payments = (await api('payments', { params: { project_id: project.project.id } })).payments.filter(p => p.kind !== 'retention' && !['cancelled', 'received'].includes(p.status)); }
        catch { payments = []; }
        fields();
        $('#dev-dlg').showModal();
    }

    /** השדות לפי מה ועל מי; ומי יצטרך להסכים. */
    function fields() {
        const el = $('#dev-dlg form').elements, scope = el.scope.value;
        $('#dev-supplier-f').hidden = scope === 'project';
        $('#dev-milestone-f').hidden = scope !== 'milestone';
        $('#dev-value-f').hidden = el.type.value !== 'retention_pct';
        if (scope === 'milestone') {
            const sid = Number(el.supplier_id.value);
            el.payment_id.innerHTML = options(payments.filter(p => !sid || p.supplier_id === sid).map(p => ({ value: p.id, label: `${p.title} — ${money(p.amount)}` })), '', t('בחירה'));
        }
        const who = [project.project.client_id ? t('הלקוח') : '', project.project.lawyer_id ? t('עו"ד') : '',
            scope === 'project' ? t('כל הספקים בפרויקט') : t('הספק')].filter(Boolean);
        $('#dev-parties').textContent = t('יצטרכו להסכים: {who}', { who: who.join(', ') });
    }

    async function save() {
        const f = $('#dev-dlg form'), el = f.elements, scope = el.scope.value;
        const body = { project_id: project.project.id, type: el.type.value, description: el.description.value.trim(),
            supplier_id: scope === 'project' ? '' : el.supplier_id.value, payment_id: scope === 'milestone' ? el.payment_id.value : '', value: el.value.value.trim() };
        if (scope !== 'project' && !body.supplier_id && !body.payment_id) { formError(f, t('בחר ספק')); return; }
        if (scope === 'milestone' && !body.payment_id) { formError(f, t('בחר אבן דרך')); return; }
        await busy($('button[type=submit]', f), f, async () => {
            data = await api('deviation', { body });
            $('#dev-dlg').close();
            render();
            toast(t('נשלח לכל הצדדים — כל אחד מקבל מייל עם הקישור שלו'));
        });
    }

    /** הסכמה שהתקבלה בטלפון / בפגישה — המנהל רושם בשם הצד. */
    async function manual(id, party, contactId) {
        const c = contactOf(contactId);
        const note = prompt(t('לרשום ש{name} הסכים לחריגה? איך ומתי (לא חובה):', { name: c?.name || '' }), '');
        if (note === null) return;
        try { data = await api('deviation-decide', { body: { id, party, contact_id: contactId, decision: 'approved', note } }); render(); Finance.reload(project); }
        catch (e) { toast(e.message, true); }
    }

    async function sendLink(id, contactId) {
        const d = data.deviations.find(x => x.id === id), c = contactOf(contactId);
        if (!c) { toast(t('אין איש קשר לשליחה'), true); return; }
        const url = await portalUrl(project.project.id, c.id);
        // i18n-none-start — שמות המשתנים בנוסח
        const text = renderMsg('deviation_request', { 'שם': c.name, 'פרויקט': project.project.name, 'פרטים': `${d.type_label} — ${d.description}`, 'קישור': url }, c.lang || I18n.lang());
        // i18n-none-end
        Send.open({ title: t('חריגה מהחוזה — להסכמה'), recipients: [{ contact: c, label: c.name, text }],
            subject: t('להסכמתך: חריגה מהחוזה — {project}', { project: project.project.name }), key: 'deviation_request', projectId: project.project.id, mailed: true });
    }

    async function cancel(id) {
        const d = data.deviations.find(x => x.id === id);
        const q = d.status === 'pending' ? t('לבטל את בקשת החריגה?') : d.status === 'approved' ? t('לבקש לבטל את החריגה? היא תתבטל רק כשכל הצדדים יסכימו.') : t('להשאיר את החריגה בתוקף ולבטל את בקשת הביטול?');
        if (!confirm(q)) return;
        try { data = await api('deviation-cancel', { body: { id } }); render(); Finance.reload(project); } catch (e) { toast(e.message, true); }
    }

    return { show };
})();
