// פרויקטים — שלב 2. מסך הבית הוא רשימת הפרויקטים כמו רשימת צ'אטים, וכל פרויקט
// נפתח למסך משלו: ציר זמן, שלבים, צוות ופרטים.
'use strict';

const Projects = (() => {
    let list = [], cur = null, pane = 'timeline', bound = false, subFolder = 0;

    function bind() {
        if (bound) return;
        bound = true;
        let timer;
        $('#pq').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadList, 250); });
        $('#pfilter').addEventListener('change', loadList);
        $('#add-project').addEventListener('click', openNew);
        $('#project-list').addEventListener('click', e => {
            const row = e.target.closest('.card');
            if (row) location.hash = `#/p/${row.dataset.id}`;
        });
        $('#project-list').addEventListener('keydown', e => {
            const row = e.target.closest('.card');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); location.hash = `#/p/${row.dataset.id}`; }
        });

        // ציר זמן
        const note = $('#note');
        note.addEventListener('input', () => autoGrow(note));
        note.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && matchMedia('(pointer: fine)').matches) {
                e.preventDefault();
                $('#composer').requestSubmit();
            }
        });
        $('#composer').addEventListener('submit', e => { e.preventDefault(); sendNote(); });

        // שלבים, צוות, פרטים
        $('#add-stage').addEventListener('click', () => openStage(null));
        $('#edit-stages').addEventListener('click', () => { stEdit = !stEdit; renderStages(); });
        $('#unit-bar').addEventListener('click', e => {
            const b = e.target.closest('[data-unit-f]');
            if (b) setUnitFilter(b.dataset.unitF);
            if (e.target.closest('[data-unit-new]')) openUnit(null);
        });
        $('#add-unit').addEventListener('click', () => openUnit(null));
        $('#units-portal-shared').addEventListener('change', async e => {
            await busy(null, null, async () => { update(await api('units-settings', { body: { project_id: cur.project.id, portal_shared: e.target.checked } })); toast(t('נשמר')); });
        });
        $('#units-handover').addEventListener('click', async () => {
            if (!confirm(t('לסמן שכל היחידות שעוד לא נמסרו נמסרו היום? שחרורי העיכבון ותשלומי "במסירה" יגיעו למועד.'))) return;
            await busy($('#units-handover'), null, async () => { update(await api('units-handover', { body: { project_id: cur.project.id } })); toast(t('🔑 כל היחידות נמסרו')); });
        });
        $('#units-list').addEventListener('click', e => { const r = e.target.closest('[data-unit]'); if (r) openUnit(Number(r.dataset.unit)); });
        $('#unit-dlg form').addEventListener('submit', e => { e.preventDefault(); saveUnit(); });
        $('#unit-delete').addEventListener('click', deleteUnit);
        $('#stage-list').addEventListener('click', e => {
            const b = e.target.closest('[data-st-up],[data-st-down],[data-st-del],[data-st-add]');
            if (b) {
                e.stopPropagation();
                if (b.dataset.stAdd !== undefined) openStage(null, Number(b.dataset.stAdd), b.dataset.unit === '' ? null : Number(b.dataset.unit));
                else if (b.dataset.stDel) deleteStage(Number(b.dataset.stDel));
                else moveStage(b.dataset.stUp ? -1 : 1, Number(b.dataset.stUp || b.dataset.stDown));
                return;
            }
            const row = e.target.closest('[data-stage]');
            if (row) openStage(Number(row.dataset.stage));
        });
        $('#stage-list').addEventListener('keydown', e => {
            const row = e.target.closest('[data-stage]');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openStage(Number(row.dataset.stage)); }
        });
        $('#add-member').addEventListener('click', openMember);
        $('#tl-filters').addEventListener('click', e => {
            const b = e.target.closest('[data-tl]');
            if (!b) return;
            tlCat = b.dataset.tl;
            try { sessionStorage.setItem('pm.tl', tlCat); } catch { /* מצב פרטי */ }
            renderTimeline();
        });
        $('#partners').addEventListener('click', e => {
            if (e.target.closest('#add-partner')) { addPartner(); return; }
            const b = e.target.closest('[data-partner-remove]');
            if (b) removePartner(Number(b.dataset.partnerRemove), b.dataset.name);
        });
        $('#team-list').addEventListener('click', e => {
            const b = e.target.closest('[data-remove]');
            if (b) removeMember(Number(b.dataset.remove), b.dataset.name);
            const l = e.target.closest('[data-portal]');
            if (l) openPortal(Number(l.dataset.portal));
        });
        $('#portal-create').addEventListener('click', () => portalDo('get'));
        $('#portal-renew').addEventListener('click', () => portalDo('renew'));
        $('#portal-revoke').addEventListener('click', () => portalDo('revoke'));
        for (const [id, channel] of [['#portal-wa', 'whatsapp'], ['#portal-sms', 'sms'], ['#portal-mail', 'email']]) {
            $(id).addEventListener('click', () => api('message-log', { body: { project_id: cur.project.id, contact_id: portalContact.id, channel, key: 'portal_link', text: portalText } }).catch(() => {}));
        }
                $('#portal-copy').addEventListener('click', async () => {
            try { await navigator.clipboard.writeText($('#portal-url').textContent); toast(t('הועתק')); }
            catch { getSelection().selectAllChildren($('#portal-url')); }
        });
        $('#project-status').addEventListener('change', e => setStatus(e.target.value));
        $('#details-form').addEventListener('submit', e => { e.preventDefault(); saveDetails(); });
        $('#dev-purge-project').addEventListener('click', () => Dev.purge('project', cur.project.id));

        // חלונות
        $('#project-dlg form').addEventListener('submit', e => { e.preventDefault(); createProject(); });
        $('#stage-dlg form').addEventListener('submit', e => { e.preventDefault(); saveStage(); });
        $('#stage-dlg [data-delete]').addEventListener('click', () => deleteStage(stageId));
        $('#member-dlg form').addEventListener('submit', e => { e.preventDefault(); addMember(); });
        document.addEventListener('change', e => {
            if (e.target.matches('[data-project-fields] [name=client_id]') && e.target.value === '__new') newClient(e.target);
        });
    }

    // ================= רשימת הפרויקטים =================

    function showList() {
        bind();
        cur = null;
        loadList();
    }

    async function loadList() {
        try {
            ({ projects: list } = await api('projects', { params: { q: $('#pq').value.trim(), filter: $('#pfilter').value } }));
            renderList();
        } catch (e) { toast(e.message, true); }
    }

    function renderList() {
        if (!list.length) {
            const searching = $('#pq').value.trim() || $('#pfilter').value !== 'open';
            $('#project-list').innerHTML = `<p class="empty">${esc(searching ? t('לא נמצאו פרויקטים') : t('עוד אין פרויקטים. לחץ "+ פרויקט" כדי לפתוח את הראשון.'))}</p>`;
            return;
        }
        $('#project-list').innerHTML = list.map(p => {
            const meta = [
                p.units > 1 ? esc(t('🏢 {n} יחידות', { n: p.units })) : '',
                p.client_name ? esc(p.client_name) : '',
                p.current_stage ? `${esc(t(p.current_stage.name))}: ${esc(state.labels.stage[p.current_stage.status] || '')}` : (p.stages_total ? esc(t('כל השלבים בוצעו')) : ''),
                p.stages_total ? `${p.stages_done}/${p.stages_total}` : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            const status = (p.status !== 'active' ? `<span class="chip">${esc(state.labels.project[p.status])}</span>` : '')
                // שותף מנהל: פרויקט של מנהל אחר — עם השם שלו; פרויקט שלי ששיתפתי — "משותף"
                + (p.owner_id !== state.user.id ? `<span class="chip partner-chip" title="${esc(t('שותף מנהל'))}">🤝 ${esc(p.owner_name)}</span>` : p.shared ? `<span class="chip partner-chip">🤝 ${esc(t('משותף'))}</span>` : '');
            return `<article class="card chat" data-id="${p.id}" tabindex="0" role="link" aria-label="${esc(p.name)}">
                ${avatar(p.name)}
                <div class="body">
                    <div class="l1"><span class="one">${esc(p.name)}</span>${status}<time class="t" datetime="${esc(p.last_activity_at)}">${when(p.last_activity_at)}</time></div>
                    <div class="l2">${esc(p.last_event) || meta}</div>
                    ${p.last_event && meta ? `<div class="l2 l3">${meta}</div>` : ''}
                </div>
            </article>`;
        }).join('');
    }

    // ================= פרויקט חדש =================

    async function openNew() {
        const dlg = $('#project-dlg');
        const form = $('form', dlg);
        formError(form, '');
        await fillProjectFields($('[data-project-fields]', dlg), { kind: 'renovation', owner_id: state.user.id }, false);
        form.elements.template_id.innerHTML = options(state.templates.map(tp => ({ value: tp.id, label: t('{name} ({n} שלבים)', { name: tp.name, n: tp.stages.length }) })), state.templates[0]?.id, t('בלי תבנית'));
        dlg.showModal();
        form.elements.name.focus();
    }

    async function createProject() {
        const form = $('#project-dlg form');
        const body = readProjectFields(form);
        body.template_id = Number(form.elements.template_id.value) || 0;
        if (!body.name) { formError(form, t('שם הפרויקט: שדה חובה')); return; }
        await busy($('button[type=submit]', form), form, async () => {
            const data = await api('project', { body });
            $('#project-dlg').close();
            cur = data;
            location.hash = `#/p/${data.project.id}/timeline`;
        });
    }

    /** שדות הפרויקט — משותפים לחלון "פרויקט חדש" וללשונית "פרטים". */
    async function fillProjectFields(box, p, withNotes) {
        const [clients, lawyers] = await Promise.all([contactsOf('client'), contactsOf('lawyer')]).catch(() => [[], []]);
        const owners = p.id && cur?.project?.id === p.id ? teamOf(cur) : [p.owner_id ?? state.user.id];
        const mine = c => owners.includes(c.owner_id) && (!c.archived || c.id === p.client_id || c.id === p.lawyer_id);
        const kinds = Object.entries(state.labels.kind).map(([value, label]) => ({ value, label }));
        box.innerHTML = `
            <label class="f wide"><span>${esc(t('שם הפרויקט *'))}</span><input name="name" maxlength="100" required value="${esc(p.name || '')}" placeholder="${esc(t('למשל: שיפוץ דירת כהן'))}"></label>
            <label class="f"><span>${esc(t('לקוח'))}</span><select name="client_id">${options(clients.filter(mine).map(c => ({ value: c.id, label: c.name })), p.client_id, t('בחירה'))}<option value="__new">${esc(t('+ לקוח חדש'))}</option></select></label>
            <label class="f"><span>${esc(t('עורך דין'))}</span><select name="lawyer_id">${options(lawyers.filter(mine).map(c => ({ value: c.id, label: c.name })), p.lawyer_id, p.id ? t('ללא') : t('לפי הלקוח'))}</select></label>
            <label class="f"><span>${esc(t('סוג'))}</span><select name="kind">${options(kinds, p.kind)}</select></label>
            <label class="f"><span>${esc(t('תקציב ₪'))} <small>${esc(t('כולל מע"מ'))}</small></span><input name="budget" type="number" inputmode="numeric" min="0" step="1" value="${p.budget || ''}"></label>
            <label class="f wide"><span>${esc(t('כתובת הנכס'))}</span><input name="address" maxlength="200" value="${esc(p.address || '')}"></label>
            <label class="f"><span>${esc(t('התחלה'))}</span><input name="start_date" type="date" value="${esc(p.start_date || '')}"></label>
            <label class="f"><span>${esc(t('יעד מסירה'))}</span><input name="due_date" type="date" value="${esc(p.due_date || '')}"></label>
            ${withNotes ? `<label class="f wide"><span>${esc(t('הערות'))}</span><textarea name="notes" rows="3" maxlength="4000">${esc(p.notes || '')}</textarea></label>` : ''}`;
    }

    function readProjectFields(form) {
        const el = form.elements;
        const v = n => el[n] ? el[n].value.trim() : '';
        return {
            name: v('name'), address: v('address'), kind: v('kind'),
            client_id: Number(v('client_id')) || null, lawyer_id: Number(v('lawyer_id')) || null,
            budget: Number(v('budget')) || 0, start_date: v('start_date'), due_date: v('due_date'),
            notes: el.notes ? el.notes.value : undefined,
        };
    }

    /** "+ לקוח חדש" מתוך בחירת הלקוח: פותח את עורך הכרטיס, ובשמירה בוחר בו. */
    function newClient(select) {
        select.value = '';
        Contacts.open(null, 'client', c => {
            const opt = new Option(c.name, c.id, true, true);
            select.insertBefore(opt, select.querySelector('[value="__new"]'));
        });
    }

    // ================= פרויקט אחד =================

    async function showProject(id, p, sub) {
        bind();
        pane = ['timeline', 'field', 'files', 'stages', 'quotes', 'payments', 'team', 'details'].includes(p) ? p : 'timeline';
        subFolder = sub;
        $$('#project-nav a').forEach(a => {
            a.href = `#/p/${id}/${a.dataset.pane}`;
            a.setAttribute('aria-current', a.dataset.pane === pane ? 'page' : 'false');
        });
        $$('#view-project .pane').forEach(el => { el.hidden = el.id !== 'pane-' + pane; });
        // נטען מחדש בכל מעבר לשונית — קבצים שהועלו, למשל, מוסיפים שורות לציר הזמן.
        // רק ניווט בין תיקיות באותו פרויקט משתמש במה שכבר נטען.
        if (cur?.project.id !== id || pane !== 'files') {
            if (cur?.project.id !== id) $('#title').textContent = '…';
            try { cur = await api('project', { params: { id } }); }
            catch (e) { toast(e.message, true); location.hash = '#/'; return; }
        }
        render();
    }

    function header() {
        const p = cur.project;
        $('#title').textContent = p.name;
        const client = p.client_id ? cur.team.find(c => c.id === p.client_id) : null;
        const sub = [client?.name, state.labels.kind[p.kind], p.status !== 'active' ? state.labels.project[p.status] : ''].filter(Boolean).join(' · ');
        $('#subtitle').textContent = sub;
        $('#subtitle').hidden = !sub;
        document.title = t('{name} — ניהול פרויקטים', { name: p.name });
    }

    function render() {
        header();
        if (pane === 'timeline') renderTimeline();
        if (pane === 'files') Files.show(cur.project, Number(subFolder) || 0);
        if (pane === 'stages') renderStages();
        if (pane === 'quotes') Quotes.show(cur);
        if (pane === 'field') Field.show(cur);
        if (pane === 'payments') Payments.show(cur);
        if (pane === 'team') renderTeam();
        if (pane === 'details') { renderDetails(); renderUnits(); }
    }

    /** תשובת שרת שמחזירה את הפרויקט המעודכן. */
    function update(data) {
        cur = data;
        render();
    }

    // ---- ציר זמן ----

    // ---- ציר הזמן ----
    // כל אירוע — קטגוריה (צבע וסינון) ואייקון. פעולות רצופות של אותו אדם, בהפרש של עד 10 דקות, מתקבצות
    // לכרטיס אחד: מי ומתי — פעם אחת בראש, ומתחת כל פעולה בשורה מלאה (בלי חיתוך), עם הכותרת והסכומים מודגשים.
    const TL_KIND = {
        payment: ['money', '💳'], expense: ['money', '🧾'], change: ['money', '±'], finance: ['money', '⚙️'],
        quote: ['quotes', '📝'], approval: ['quotes', '✅'],
        created: ['stages', '🏁'], details: ['stages', '✏️'], status: ['stages', '🚦'], report: ['stages', '📷'],
        stage_added: ['stages', '➕'], stage_removed: ['stages', '➖'], stage_moved: ['stages', '↕️'], stage_status: ['stages', '🧱'],
        stage_progress: ['stages', '📊'], stage_supplier: ['stages', '👷'],
        member_added: ['team', '👥'], member_removed: ['team', '👥'], partner: ['team', '🤝'], portal: ['team', '🔗'], portal_view: ['team', '👀'],
        message: ['team', '✉️'], appointment: ['team', '📅'],
        file: ['files', '📎'], file_move: ['files', '📁'], file_visible: ['files', '👁'], file_deleted: ['files', '🗑'],
        note: ['notes', '💬'],
    };
    const TL_CATS = [['all', 'הכול'], ['money', '💰 כספים'], ['quotes', '📝 הצעות'], ['stages', '🧱 שלבים ושטח'], ['team', '👥 צוות והודעות'], ['files', '📎 קבצים'], ['notes', '💬 הערות']]; // i18n-ok — מתורגם בתצוגה
    let tlCat = (() => { try { return sessionStorage.getItem('pm.tl') || 'all'; } catch { return 'all'; } })();
    const tlOf = e => TL_KIND[e.kind] || (e.kind.startsWith('file') ? ['files', '📎'] : e.kind.startsWith('stage') ? ['stages', '🧱'] : ['other', '•']);

    /** הטקסט של אירוע: מה שלפני הנקודתיים הראשונות — כותרת מודגשת; סכומים — מודגשים. */
    function tlText(text) {
        text = text.replace(/^\p{Extended_Pictographic}\uFE0F?\s*/u, ''); // האייקון כבר בשורה
        const bold = s => esc(s).replace(/(₪\s?[\d,.]+|[\d,.]+\s?₪)/g, '<b>$1</b>');
        const m = text.match(/^([^:\n]{2,40}):\s([\s\S]+)$/);
        return m ? `<b class="tl-k">${esc(m[1])}</b> ${bold(m[2])}` : bold(text);
    }

    function renderTimeline() {
        const fmtT = iso => new Date(iso).toLocaleTimeString(I18n.locale(), { hour: '2-digit', minute: '2-digit' });
        const counts = {};
        for (const e of cur.events) { const c = tlOf(e)[0]; counts[c] = (counts[c] || 0) + 1; }
        $('#tl-filters').innerHTML = TL_CATS.filter(([k]) => k === 'all' || counts[k]).map(([k, label]) =>
            `<button type="button" class="chip${tlCat === k ? ' on' : ''}" data-tl="${k}" aria-pressed="${tlCat === k}">${esc(t(label))}${k !== 'all' ? ` <small>${counts[k]}</small>` : ''}</button>`).join('');
        if (tlCat !== 'all' && !counts[tlCat]) tlCat = 'all';
        const list = cur.events.filter(e => tlCat === 'all' || tlOf(e)[0] === tlCat);

        // קיבוץ: יום → כרטיסים של אדם אחד ברצף
        const out = [];
        let day = '', g = null;
        const flush = () => { if (g) out.push(group(g)); g = null; };
        for (const e of list) {
            const d = new Date(e.at).toDateString();
            if (d !== day) { flush(); out.push(`<li class="day"><span>${esc(dateLabel(e.at))}</span></li>`); day = d; }
            if (e.kind === 'note') { flush(); out.push(note(e)); continue; }
            const near = g && g.actor === e.actor_name && (new Date(e.at) - new Date(g.items[g.items.length - 1].at)) <= 10 * 60000;
            if (!near) { flush(); g = { actor: e.actor_name, mine: e.actor_id === state.user.id, items: [] }; }
            g.items.push(e);
        }
        flush();

        function note(e) {
            const mine = e.actor_id === state.user.id;
            return `<li class="msg${mine ? ' mine' : ''}">${mine ? '' : `<b>${esc(e.actor_name)}</b>`}<p>${esc(e.text)}</p><time datetime="${esc(e.at)}">${fmtT(e.at)}</time></li>`;
        }
        function group(g) {
            const a = g.items[0].at, z = g.items[g.items.length - 1].at;
            const when = fmtT(a) === fmtT(z) ? fmtT(a) : `${fmtT(a)}–${fmtT(z)}`;
            const row = e => { const [cat, icon] = tlOf(e); return `<li class="tl-item tl-${cat}"><span class="tl-ic" aria-hidden="true">${icon}</span><span class="tl-t">${tlText(e.text)}</span></li>`; };
            const MAX = 6;
            const items = g.items.length > MAX + 1
                ? g.items.slice(0, MAX).map(row).join('') + `<li class="tl-more"><details><summary>${esc(t('עוד {n} פעולות', { n: g.items.length - MAX }))}</summary><ul class="tl-items">${g.items.slice(MAX).map(row).join('')}</ul></details></li>`
                : g.items.map(row).join('');
            return `<li class="tl-group${g.mine ? ' mine' : ''}"><header class="tl-head">${avatar(g.actor)}<b class="one">${esc(g.actor)}</b><time datetime="${esc(a)}">${when}</time></header>
                <ul class="tl-items">${items}</ul></li>`;
        }

        $('#timeline').innerHTML = out.join('') || `<li class="day"><span>${esc(t('עוד אין כלום בציר הזמן'))}</span></li>`;
        requestAnimationFrame(() => {
            const last = $('#timeline > li:last-child');
            if (last) last.scrollIntoView({ block: 'end' });
        });
    }

    function autoGrow(box) {
        box.style.height = 'auto';
        box.style.height = Math.min(box.scrollHeight, 160) + 'px';
    }

    async function sendNote() {
        const box = $('#note');
        const text = box.value.trim();
        if (!text) return;
        await busy($('#composer button'), null, async () => {
            update(await api('note', { body: { project_id: cur.project.id, text } }));
            box.value = '';
            autoGrow(box);
            box.focus();
        });
    }

    // ---- שלבים ----

    function renderStages() {
        const s = cur.stages;
        const done = s.filter(x => x.status === 'done' || x.status === 'paid').length;
        const pct = s.length ? Math.round(done / s.length * 100) : 0;
        $('#progress span').style.width = pct + '%';
        $('#progress').setAttribute('aria-valuenow', String(pct));
        $('#progress').setAttribute('aria-valuemax', '100');
        $('#progress').setAttribute('aria-label', t('{done} מתוך {n} שלבים בוצעו', { done, n: s.length }));
        $('#progress-text').textContent = s.length ? t('{done}/{n} בוצעו', { done, n: s.length }) : '';
        const ed = stEdit && s.length > 0;
        $('#edit-stages').setAttribute('aria-pressed', String(ed));
        $('#edit-stages').classList.toggle('on', ed);
        $('#pane-stages').classList.toggle('st-editing', ed);
        const units = cur.units || [];
        const f = unitFilter();
        $('#unit-bar').hidden = !units.length;
        $('#unit-bar').innerHTML = units.length ? [['all', t('הכול')], ...units.map(u => [String(u.id), u.name]), ['shared', t('משותף')]]
            .map(([k, label]) => `<button type="button" class="chip${f === k ? ' on' : ''}" data-unit-f="${esc(k)}" aria-pressed="${f === k}">${esc(label)}</button>`).join('')
            + `<button type="button" class="chip add" data-unit-new aria-label="${esc(t('+ יחידה'))}">+ 🏢</button>` : '';
        // קבוצות: משותף, ואז כל יחידה (כמו הסדר בשרת). בלי יחידות — קבוצה אחת בלי כותרת.
        const groups = units.length
            ? [{ unit: null, label: t('משותף לכל המתחם'), list: s.filter(x => !x.unit_id) }, ...units.map(u => ({ unit: u.id, label: '🏢 ' + u.name, list: s.filter(x => x.unit_id === u.id) }))]
                .filter(g => f === 'all' || (f === 'shared' ? g.unit === null : g.unit === null || g.unit === Number(f)))
                .filter(g => g.list.length || g.unit !== null)
            : [{ unit: null, label: '', list: s }];
        const addHere = (after, unit) => ed ? `<li class="st-add"><button class="btn ghost small" data-st-add="${after}" data-unit="${unit ?? ''}">${esc(t('+ שלב כאן'))}</button></li>` : '';
        let n = 0;
        $('#stage-list').innerHTML = s.length || units.length ? groups.map(g => {
            const head = g.label ? `<li class="st-group"><h3>${esc(g.label)}</h3>${!g.list.length ? `<small class="muted">${esc(t('אין עדיין שלבים ביחידה הזו'))}</small>` : ''}</li>` : '';
            const prev = s.indexOf(g.list[0]) > 0 ? s[s.indexOf(g.list[0]) - 1].id : (g.list.length ? 0 : (s.filter(x => groupOf(x) <= groupIdx(g.unit)).at(-1)?.id || 0));
            return head + addHere(prev, g.unit) + g.list.map((x, i) => {
            n++;
            const l2 = [
                x.trade_name ? esc(t(x.trade_name)) : '',
                // הספק — שנבחר בשלב, או מי שעובד בו בפועל (הצעה / אבני דרך מהכספים)
                x.supplier_name ? esc(x.supplier_name) : (x.engaged || []).length ? esc(x.engaged.map(e => e.name).join(', ')) : `<span class="muted">${esc(t('אין ספק'))}</span>`,
                x.plan_start || x.plan_end ? `${shortDate(x.plan_start)}–${shortDate(x.plan_end)}` : '',
                x.needs_lawyer ? esc(t('עורך דין')) : '',
                x.status === 'in_progress' && x.progress ? esc(t('{n}% בוצע', { n: x.progress })) : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            const miss = stageMissing(x);
            const name = esc(t(x.name));
            const acts = ed ? `<div class="acts st-acts">
                <button class="icon-btn" data-st-up="${x.id}" ${i ? '' : 'disabled'} aria-label="${esc(t('להזיז למעלה: {name}', { name: t(x.name) }))}">↑</button>
                <button class="icon-btn" data-st-down="${x.id}" ${i < g.list.length - 1 ? '' : 'disabled'} aria-label="${esc(t('להזיז למטה: {name}', { name: t(x.name) }))}">↓</button>
                <button class="icon-btn danger" data-st-del="${x.id}" ${x.used && !Dev.on() ? `title="${esc(t('השלב לא ריק — אי אפשר למחוק'))}" disabled` : ''} aria-label="${esc(t('מחיקת השלב {name}', { name: t(x.name) }))}">🗑</button>
            </div>` : stageChip(x.status);
            return `<li class="card" data-stage="${x.id}" tabindex="0" role="button" aria-label="${esc(stageName(x))}, ${esc(state.labels.stage[x.status])}">
                <span class="num st-${esc(x.status)}" aria-hidden="true">${n}</span>
                <div class="body"><div class="l1">${name}</div><div class="l2">${l2}</div>${engagedNote(x) ? `<div class="l2 st-eng">${engagedNote(x)}</div>` : ''}${miss.length ? `<div class="l2 st-miss">${esc(t('חסר: {list}', { list: miss.join(t(', ')) }))}</div>` : ''}</div>
                ${acts}
            </li>` + addHere(x.id, g.unit);
            }).join('');
        }).join('') : `<p class="empty">${esc(t('אין שלבים. לחץ "+ שלב" כדי להוסיף.'))}</p>`;
    }

    // ---- מתחם: יחידות ----

    /** הסינון לפי יחידה — לכל פרויקט, ללשונית הנוכחית: all · shared · מזהה יחידה. */
    function unitFilter() {
        let f = 'all';
        try { f = sessionStorage.getItem('pm.unit.' + cur.project.id) || 'all'; } catch { /* מצב פרטי */ }
        return f === 'all' || f === 'shared' || (cur.units || []).some(u => String(u.id) === f) ? f : 'all';
    }
    function setUnitFilter(f) {
        try { sessionStorage.setItem('pm.unit.' + cur.project.id, f); } catch { /* מצב פרטי */ }
        renderStages();
    }
    /** מספר הקבוצה של שלב בסדר התצוגה (0 — משותף). */
    const groupIdx = unit => unit === null ? 0 : 1 + (cur.units || []).findIndex(u => u.id === unit);
    const groupOf = x => groupIdx(x.unit_id ?? null);

    let unitId = null;

    async function openUnit(id) {
        const dlg = $('#unit-dlg'), form = $('form', dlg), el = form.elements;
        form.reset(); formError(form, '');
        unitId = id;
        const units = cur.units || [];
        const u = id ? units.find(x => x.id === id) : null;
        $('#unit-dlg-title').textContent = u ? u.name : t('יחידה חדשה במתחם');
        el.name.value = u ? u.name : (units.length ? t('דירה {n}', { n: units.length + 1 }) : t('דירה 1'));
        el.area.value = u?.area ?? '';
        el.budget.value = u?.budget || '';
        el.notes.value = u?.notes || '';
        // לקוח ועו"ד ליחידה — ריק = של הפרויקט
        const [clients, lawyers] = await Promise.all([contactsOf('client'), contactsOf('lawyer')]).catch(() => [[], []]);
        const team = teamOf(cur), mine = c => team.includes(c.owner_id) && !c.archived;
        const projName = kind => (cur.team || []).find(c => c.id === cur.project[kind])?.name;
        el.client_id.innerHTML = options(clients.filter(mine).map(c => ({ value: c.id, label: c.name })), u?.client_id ?? '',
            projName('client_id') ? t('של הפרויקט — {name}', { name: projName('client_id') }) : t('של הפרויקט'));
        el.lawyer_id.innerHTML = options(lawyers.filter(mine).map(c => ({ value: c.id, label: c.name })), u?.lawyer_id ?? '',
            projName('lawyer_id') ? t('של הפרויקט — {name}', { name: projName('lawyer_id') }) : t('של הפרויקט'));
        el.handed_over_at.value = u?.handed_over_at || '';
        $('#unit-handover-box').hidden = $('#unit-handover-hint').hidden = !u;
        // איחוד: יחידה עם נתונים — מעבירים את השלבים ליחידה אחרת או למשותף, ואז מוחקים
        const merge = !!u?.used && !Dev.on();
        $('#unit-merge-box').hidden = !merge;
        el.move_to.innerHTML = options([{ value: 'shared', label: t('משותף לכל המתחם') }, ...units.filter(x => x.id !== id).map(x => ({ value: x.id, label: x.name }))], units.find(x => x.id !== id)?.id || 'shared');
        $('#unit-delete').textContent = merge ? t('🔀 איחוד ומחיקת היחידה') : t('מחיקת היחידה');
        const shared = cur.stages.filter(x => !x.unit_id).length;
        el.copy_from.innerHTML = options([
            { value: '', label: t('בלי שלבים — אוסיף בעצמי') },
            ...(shared ? [{ value: 'shared', label: t('כמו השלבים המשותפים ({n})', { n: shared }) }] : []),
            ...units.filter(x => x.stages).map(x => ({ value: x.id, label: t('כמו {name} ({n} שלבים)', { name: x.name, n: x.stages }) })),
        ], units.find(x => x.stages)?.id || '');
        $('#unit-copy-box').hidden = !!u;
        // היחידה הראשונה בפרויקט שכבר יש בו שלבים: להעביר אותם אליה, או להשאיר משותפים
        $('#unit-move-box').hidden = !!u || units.length > 0 || !shared;
        el.move_shared.checked = false;
        el.move_shared.onchange = () => { el.copy_from.disabled = el.move_shared.checked; };
        el.copy_from.disabled = false;
        $('#unit-delete').hidden = !u;
        dlg.showModal();
        if (!u) el.name.select();
    }

    async function saveUnit() {
        const form = $('#unit-dlg form'), el = form.elements;
        const body = { id: unitId || undefined, project_id: cur.project.id, name: el.name.value.trim(), area: el.area.value.trim(),
            budget: el.budget.value.trim(), notes: el.notes.value.trim(), ...(unitId ? { handed_over_at: el.handed_over_at.value } : {}),
            client_id: Number(el.client_id.value) || null, lawyer_id: Number(el.lawyer_id.value) || null, copy_from: unitId ? undefined : el.copy_from.value, move_shared: !unitId && el.move_shared.checked };
        if (!body.name) { formError(form, t('שם היחידה: שדה חובה')); return; }
        await busy($('button[type=submit]', form), form, async () => {
            const d = await api('unit', { body });
            $('#unit-dlg').close();
            cur = d;
            if (!unitId) try { sessionStorage.setItem('pm.unit.' + cur.project.id, String(d.saved_unit)); } catch { /* מצב פרטי */ }
            render();
            toast(unitId ? t('נשמר') : t('נוספה יחידה: {name}', { name: body.name }));
        });
    }

    async function deleteUnit() {
        const u = (cur.units || []).find(x => x.id === unitId);
        if (!u) return;
        const el = $('#unit-dlg form').elements;
        const merge = !$('#unit-merge-box').hidden;
        const to = merge ? el.move_to.selectedOptions[0].textContent : '';
        if (!confirm(merge ? t('לאחד את "{name}" עם {to}? כל השלבים שלה, עם ההצעות והתשלומים, יעברו לשם.', { name: u.name, to })
            : t('למחוק את "{name}"? השלבים הריקים שלה יימחקו איתה.', { name: u.name }))) return;
        await busy(null, $('#unit-dlg form'), async () => {
            update(await api('unit-delete', { body: { id: u.id, ...(merge ? { move_to: el.move_to.value } : {}) } }));
            $('#unit-dlg').close();
            toast(t('היחידה נמחקה'));
        });
    }

    /** "פרטים": היחידות במתחם — רשימה, עריכה ו"+ יחידה". */
    function renderUnits() {
        const units = cur.units || [];
        $('#units-list').innerHTML = units.map(u => `<li class="card" data-unit="${u.id}" tabindex="0" role="button">
            <span class="num" aria-hidden="true">🏢</span>
            <div class="body"><div class="l1">${esc(u.name)}</div><div class="l2">${[
                u.handed_over_at ? t('🔑 נמסרה {date}', { date: shortDate(u.handed_over_at) }) : '',
                u.client_name ? '👤 ' + u.client_name : '',
                t('{n} שלבים', { n: u.stages }), u.area ? t('{n} מ"ר', { n: u.area }) : '', u.budget ? money(u.budget) : '',
            ].filter(Boolean).map(esc).join('<i class="sep" aria-hidden="true">·</i>')}</div></div></li>`).join('');
        $('#units-hint').hidden = units.length > 0;
        $('#units-shared-box').hidden = $('#units-portal-hint').hidden = !units.length;
        $('#units-portal-shared').checked = cur.project.portal_shared !== false;
        $('#units-handover').hidden = units.length < 2 || units.every(u => u.handed_over_at);
    }

    let stEdit = false;

    /** מה עוד לא מולא בשלב — רק בשלבים שעוד לא בוצעו. */
    /** מה נבנה לשלב בכספים: "💳 3 אבני דרך · ₪162,840" — כך רואים שיש התקשרות גם כשהשלב עוד "מתוכנן". */
    function engagedNote(x) {
        const e = x.engaged || [], ms = e.reduce((a, y) => a + y.milestones, 0), amt = e.reduce((a, y) => a + y.amount, 0);
        return ms ? esc(t('💳 {n} אבני דרך · {amount}', { n: ms, amount: money(amt) })) : '';
    }

    function stageMissing(x) {
        if (['done', 'paid'].includes(x.status)) return [];
        const m = [];
        const e = x.engaged || [];
        if (!x.trade_id) m.push(t('תחום'));
        // יש מי שעובד בשלב, אבל אין הצעה מאושרת — זה מה שחסר (ולא "ספק")
        if (!x.supplier_id && !e.length) m.push(x.quotes ? t('בחירת ספק') : t('ספק או הצעת מחיר'));
        else if (e.length && !e.some(y => y.approved) && ['planned', 'quotes'].includes(x.status)) m.push(t('הצעה מאושרת'));
        if (!x.plan_start && !x.plan_end) m.push(t('תאריכים'));
        if (!x.budget) m.push(t('תקציב'));
        return m;
    }

    let stageId = null, stageAfter = null;

    async function openStage(id, after = null, unit = undefined) {
        stageAfter = id ? null : after;
        const dlg = $('#stage-dlg');
        const form = $('form', dlg);
        formError(form, '');
        stageId = id;
        const s = id ? cur.stages.find(x => x.id === id) : { status: 'planned' };
        $('#stage-dlg-title').textContent = id ? stageName(s) : t('שלב חדש');
        const el = form.elements;
        el.name.value = s.name ? t(s.name) : ''; // שם שנזרע — מוצג מתורגם, ונשלח כמו שהוא אם לא שונה
        el.trade_id.innerHTML = options(state.trades.map(tr => ({ value: tr.id, label: t(tr.name) })), s.trade_id, t('ללא'));
        el.status.innerHTML = options(Object.entries(state.labels.stage).map(([value, label]) => ({ value, label })), s.status);
        // מתחם: לאיזו יחידה השלב שייך (חדש — לפי "+ שלב כאן" או הסינון)
        const units = cur.units || [];
        $('#stage-unit-box').hidden = !units.length;
        const f = units.length ? unitFilter() : 'all';
        const defUnit = id ? s.unit_id : unit !== undefined ? unit : (f !== 'all' && f !== 'shared' ? Number(f) : null);
        el.unit_id.innerHTML = options([{ value: '', label: t('משותף לכל המתחם') }, ...units.map(u => ({ value: u.id, label: u.name }))], defUnit ?? '');
        el.plan_start.value = s.plan_start || '';
        el.plan_end.value = s.plan_end || '';
        el.needs_lawyer.checked = !!s.needs_lawyer;
        // אגף הכספים: התקדמות ("בוצע" = 100, נעול) ותקציב לשלב
        el.progress.value = s.progress || 0;
        el.budget.value = s.budget || '';
        progressShown();
        el.progress.oninput = progressShown;
        el.status.onchange = progressShown;
        $('[data-existing]', dlg).hidden = !id;
        // מי עובד בשלב — ותיק ההתקשרות של כל אחד (חוזה, הצעות, אבני דרך, תיעוד, קבצים)
        const eng = id ? [...(s.engaged || [])] : [];
        if (s.supplier_id && !eng.some(x => x.supplier_id === s.supplier_id)) eng.unshift({ supplier_id: s.supplier_id, name: s.supplier_name || '' });
        const engBox = $('#stage-engaged');
        engBox.hidden = !eng.length;
        engBox.innerHTML = eng.length ? `<h3>${esc(t('מי עובד בשלב'))}</h3>${eng.map(x => `<button type="button" class="use-row" data-engage="${x.supplier_id}">
            <span class="use-ic" aria-hidden="true">🗂️</span><span class="use-tx"><b>${esc(x.name)}</b><small>${esc([x.milestones ? t('{n} אבני דרך', { n: x.milestones }) : '', x.amount ? money(x.amount) : '', t('תיק התקשרות')].filter(Boolean).join(' · '))}</small></span>
            <span class="use-go" aria-hidden="true">‹</span></button>`).join('')}` : '';
        engBox.onclick = e => { const b = e.target.closest('[data-engage]'); if (b) { dlg.close(); Engage.open(cur.project.id, Number(b.dataset.engage), id); } };
        // כל הקבצים של השלב, לפי ספק — עץ "לפי שלבים" בקבצים (lib/links.php)
        $('#stage-files').hidden = !id;
        $('#stage-files').onclick = async () => {
            try {
                const { folder_id } = await api('stage-folder', { params: { project_id: cur.project.id, stage_id: id } });
                dlg.close();
                location.hash = `#/p/${cur.project.id}/files${folder_id ? '/' + folder_id : ''}`;
            } catch (e) { toast(e.message, true); }
        };
        Dev.attach(dlg, 'stage', id);
        $('[data-delete]', dlg).hidden = !!s.used && !Dev.on(); // רק שלב ריק (חוץ מסמכות מפתח)
        await fillSuppliers(el.supplier_id, Number(el.trade_id.value) || 0, s.supplier_id);
        el.trade_id.onchange = () => fillSuppliers(el.supplier_id, Number(el.trade_id.value) || 0, Number(el.supplier_id.value) || null);
        dlg.showModal();
    }

    function progressShown() {
        const el = $('#stage-dlg form').elements;
        const done = ['done', 'paid'].includes(el.status.value);
        if (done) el.progress.value = 100;
        el.progress.disabled = done;
        $('#stage-progress-out').textContent = `${el.progress.value}%`;
    }

    /** ספקים של בעל הפרויקט — קודם מי שבתחום של השלב. */
    async function fillSuppliers(select, tradeId, value, owners = teamOf(cur)) {
        let all = [];
        try { all = await contactsOf('supplier'); } catch { /* ריק */ }
        all = all.filter(c => owners.includes(c.owner_id) && (!c.archived || c.id === value));
        const opt = c => ({ value: c.id, label: c.name + (c.company ? ' · ' + c.company : '') });
        const inTrade = tradeId ? all.filter(c => c.trades.includes(tradeId)) : [];
        const rest = all.filter(c => !inTrade.includes(c));
        select.innerHTML = `<option value="">${esc(t('ללא'))}</option>`
            + (inTrade.length ? `<optgroup label="${esc(t('בתחום'))}">${options(inTrade.map(opt), value)}</optgroup>` : '')
            + (rest.length ? `<optgroup label="${esc(inTrade.length ? t('אחרים') : t('כל הספקים'))}">${options(rest.map(opt), value)}</optgroup>` : '');
    }

    async function saveStage() {
        const form = $('#stage-dlg form');
        const el = form.elements;
        const body = {
            id: stageId || undefined, project_id: cur.project.id, name: el.name.value.trim(),
            after: stageId || stageAfter === null ? undefined : stageAfter,
            trade_id: Number(el.trade_id.value) || null, supplier_id: Number(el.supplier_id.value) || null,
            status: el.status.value, needs_lawyer: el.needs_lawyer.checked,
            plan_start: el.plan_start.value, plan_end: el.plan_end.value,
            progress: Number(el.progress.value) || 0, budget: el.budget.value.trim(),
            ...((cur.units || []).length ? { unit_id: Number(el.unit_id.value) || null } : {}),
        };
        if (!body.name) { formError(form, t('שם השלב: שדה חובה')); return; }
        // "בוצע" — דרך דיווח מהשטח עם תמונה: שומרים את השאר, ועוברים לדיווח
        const old = stageId ? cur.stages.find(x => x.id === stageId) : null;
        if (old && body.name === t(old.name)) body.name = old.name;
        // סמכות מפתח: "בוצע" גם בלי דיווח מהשטח
        const toDone = !Dev.on() && ['done', 'paid'].includes(body.status) && !['done', 'paid'].includes(old?.status || 'planned');
        if (toDone) { body.status = old?.status || 'planned'; body.progress = old?.progress || 0; }
        await busy($('button[type=submit]', form), form, async () => {
            const d = await api('stage', { body });
            if (toDone) {
                $('#stage-dlg').close();
                Field.markDone(cur.project.id, d.stages.find(x => x.name === body.name && (!stageId || x.id === stageId))?.id || stageId);
                return;
            }
            update(d);
            if (d.notice) toast(d.notice);
            $('#stage-dlg').close();
        });
    }

    async function moveStage(dir, id) {
        const ids = cur.stages.map(s => s.id);
        const i = ids.indexOf(id), j = i + dir;
        if (i < 0 || j < 0 || j >= ids.length) return;
        if (groupOf(cur.stages[i]) !== groupOf(cur.stages[j])) return; // רק בתוך אותה יחידה
        [ids[i], ids[j]] = [ids[j], ids[i]];
        const dlg = $('#stage-dlg').open;
        await busy(null, dlg ? $('#stage-dlg form') : null, async () => {
            update(await api('stages-order', { body: { project_id: cur.project.id, ids } }));
            toast(dir < 0 ? t('הוזז למעלה') : t('הוזז למטה'));
            if (!dlg) $(`#stage-list [data-st-${dir < 0 ? 'up' : 'down'}="${id}"]`)?.focus();
        });
    }

    async function deleteStage(id) {
        const s = cur.stages.find(x => x.id === id);
        // סמכות מפתח: שלב עם הצעות — מחיקה גמורה (עם ההצעות, התוספות ואבני הדרך שלו)
        if (s && s.used && Dev.on()) { if (await Dev.purge('stage', id) && $('#stage-dlg').open) $('#stage-dlg').close(); return; }
        if (!s || !confirm(t('למחוק את השלב "{name}"?', { name: t(s.name) }))) return;
        const dlg = $('#stage-dlg').open;
        await busy(null, dlg ? $('#stage-dlg form') : null, async () => {
            update(await api('stage-delete', { body: { id: s.id } }));
            if (dlg) $('#stage-dlg').close();
            toast(t('השלב נמחק'));
        });
    }

    // ---- צוות ----

    let links = {};

    /** שותפים מנהלים: הבעלים, השותפים, ו"+ שותף מנהל" (רק לבעלים). */
    function renderPartners() {
        const P = cur.partners || [], me = state.user.id;
        $('#partners-list').innerHTML = P.map(u => `<li class="card static">
            ${avatar(u.name)}
            <div class="body"><div class="l1">${esc(u.name)}${u.id === me ? `<span class="sub">${esc(t('אני'))}</span>` : ''}</div>
                <div class="l2">${esc(u.owner ? t('👑 בעל הפרויקט') : t('🤝 שותף מנהל — רואה ועורך הכול'))}</div></div>
            <div class="acts">${!u.owner && (cur.is_owner || u.id === me) ? `<button class="icon-btn" data-partner-remove="${u.id}" data-name="${esc(u.name)}"
                aria-label="${esc(u.id === me ? t('עזיבת הפרויקט') : t('הסרת {name} מהשותפים', { name: u.name }))}">✕</button>` : ''}</div></li>`).join('');
        $('#add-partner').hidden = !cur.is_owner;
    }

    async function addPartner() {
        const login = prompt(t('שם המשתמש או האימייל של המנהל (משתמש פעיל באפליקציה):'), '');
        if (!login || !login.trim()) return;
        try {
            cur = await api('project-partner', { body: { project_id: cur.project.id, do: 'add', login: login.trim() } });
            renderTeam();
            toast(t('צורף כשותף מנהל — הפרויקט מופיע אצלו עכשיו'));
        } catch (e) { toast(e.message, true); }
    }

    async function removePartner(id, name) {
        const self = id === state.user.id;
        if (!confirm(self ? t('לעזוב את הפרויקט? הוא ייעלם מהרשימה שלך.') : t('להסיר את {name} מהשותפים? הוא לא יראה יותר את הפרויקט.', { name }))) return;
        try {
            const d = await api('project-partner', { body: { project_id: cur.project.id, do: 'remove', user_id: id } });
            if (d.left) { location.hash = '#/'; toast(t('עזבת את הפרויקט')); return; }
            cur = d;
            renderTeam();
        } catch (e) { toast(e.message, true); }
    }

    async function renderTeam() {
        renderPartners();
        const draw = () => {
            $('#team-list').innerHTML = cur.team.map(c => {
                // ללקוח ולעו"ד — קישור אישי לפורטל. מתי נפתח לאחרונה — בשורה.
                const portal = c.id === cur.project.client_id || c.id === cur.project.lawyer_id || c.kind === 'supplier';
                const l = links[c.id];
                const seen = l ? (l.last_seen_at ? t('פתח {when}', { when: when(l.last_seen_at) }) : t('קישור נשלח, עוד לא נפתח')) : '';
                return `<li class="card">
                ${avatar(c.name)}
                <div class="body"><div class="l1">${esc(c.name)}${c.company ? `<span class="sub">${esc(c.company)}</span>` : ''}</div>
                    <div class="l2">${[...c.roles.map(r => esc(t(r))), seen ? esc(seen) : ''].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div></div>
                <div class="acts">${portal ? `<button class="icon-btn" data-portal="${c.id}" aria-label="${esc(t('הקישור האישי של {name}', { name: c.name }))}" title="${esc(t('קישור אישי'))}">🔗</button>` : ''}${contactActs(c)}${c.removable ? `<button class="icon-btn" data-remove="${c.id}" data-name="${esc(c.name)}" aria-label="${esc(t('הסרת {name} מהצוות', { name: c.name }))}">✕</button>` : ''}</div>
            </li>`;
            }).join('') || `<p class="empty">${esc(t('עוד אין צוות. שייך לקוח בפרטים, או ספק לשלב.'))}</p>`;
        };
        draw();
        try { ({ links } = await api('portal-links', { params: { project_id: cur.project.id } })); draw(); } catch { /* בלי שורת "פתח" */ }
        renderMessages();
    }

    /** ההודעות שיצאו בפרויקט — אוטומטיות (מייל) וידניות (וואטסאפ / SMS שנפתחו). */
    async function renderMessages() {
        let messages = [];
        try { ({ messages } = await api('messages', { params: { project_id: cur.project.id } })); } catch { return; }
        const icon = { email: '✉️', whatsapp: '💬', sms: '📱', copy: '📋' };
        const status = m => m.status === 'sent' ? t('נשלח') : m.status === 'failed' ? t('לא נשלח') : t('נפתח');
        // שורת הפתיחה של ההודעה ("שלום …") לא מוצגת בתקציר — בשפה של הנמען
        const GREETING = /^(שלום|Hello|Hi|Dear|Bonjour|Здравствуйте|مرحبا)/i; // i18n-none
        $('#msg-hist-title').hidden = !messages.length;
        $('#msg-hist').innerHTML = messages.slice(0, 30).map(m => `<li class="card static msg-${m.status}">
            <span class="ficon" aria-hidden="true">${icon[m.channel] || '✉️'}</span>
            <div class="body"><div class="l1"><span class="one">${esc(m.contact_name || '')}${m.subject ? ' · ' + esc(m.subject) : ''}</span><time class="t">${when(m.at)}</time></div>
                <div class="l2 one">${m.auto ? esc(t('אוטומטי')) + ' · ' : ''}${esc(status(m))}${m.body ? ' · ' + esc(m.body.split('\n').find(x => x && !GREETING.test(x)) || '') : ''}</div></div></li>`).join('');
    }

    // ---- קישור אישי לפורטל ----

    let portalContact = null, portalText = '';

    function openPortal(id) {
        portalContact = cur.team.find(c => c.id === id);
        const dlg = $('#portal-dlg');
        formError($('form', dlg), '');
        const role = id === cur.project.client_id ? 'client' : id === cur.project.lawyer_id ? 'lawyer' : 'supplier';
        $('#portal-dlg-title').textContent = t('קישור אישי — {name}', { name: portalContact.name });
        $('#portal-about').textContent = t({ // i18n-ok-start
            client: 'בקישור הלקוח רואה את התקדמות השלבים, את הצעות המחיר שנשלחו אליו (המומלצת בראש) ואת הקבצים שסומנו "גלוי" — ומאשר הצעות בעצמו, בלחיצה או בחתימה לפי הגדרות הלקוח. בלי סיסמה.',
            lawyer: 'בקישור העו"ד רואה את הפרויקט ואת ההצעות, ומאשר הצעות שהלקוח כבר אישר בשלבים שדורשים עו"ד. בלי סיסמה.',
            supplier: 'בקישור הספק רואה את השלבים שלו, מגיש הצעות מחיר, מבקש תשלום, מאשר שהכסף התקבל ומעדכן את פרטי הבנק שלו — בלי לראות הצעות של ספקים אחרים, פרטי לקוח או תשלומים לאחרים. בלי סיסמה.',
        }[role]); // i18n-ok-end
        drawPortal(links[id] || null);
        dlg.showModal();
    }

    function drawPortal(l) {
        links[portalContact.id] = l;
        $('#portal-has').hidden = !l;
        $('#portal-create').hidden = !!l;
        if (!l) return;
        const c = portalContact;
        $('#portal-url').textContent = l.url;
        $('#portal-seen').textContent = l.last_seen_at ? t('נפתח לאחרונה {when} · {n} כניסות', { when: when(l.last_seen_at), n: l.views }) : t('עוד לא נפתח');
        const what = c.kind === 'supplier' ? t('הגשת הצעות מחיר, בקשות תשלום ואישור קבלה') : t('מצב העבודות, הצעות המחיר והמסמכים, ואישור הצעות');
        // בשפה של איש הקשר. שמות המשתנים בעברית — כמו בנוסח (הגדרות ← הודעות)
        const text = renderMsg('portal_link', { 'שם': c.name, 'פרויקט': cur.project.name, 'פרטים': what, 'קישור': l.url }, c.lang || I18n.lang()); // i18n-none
        portalText = text;
        const phone = c.whatsapp || c.phone;
        $('#portal-wa').href = `https://wa.me/${phone ? waNumber(phone) : ''}?text=${encodeURIComponent(text)}`;
        $('#portal-sms').hidden = !c.phone;
        $('#portal-sms').href = `sms:${digits(c.phone || '')}?&body=${encodeURIComponent(text)}`;
        $('#portal-mail').hidden = !c.email;
        $('#portal-mail').href = `mailto:${encodeURIComponent(c.email || '')}?subject=${encodeURIComponent(cur.project.name)}&body=${encodeURIComponent(text)}`;
        $('#portal-open').href = l.url;
    }

    async function portalDo(what) {
        if (what === 'renew' && !confirm(t('ליצור קישור חדש? הקישור הקודם יפסיק לעבוד.'))) return;
        if (what === 'revoke' && !confirm(t('לבטל את הקישור? הוא יפסיק לעבוד מיד.'))) return;
        await busy(null, $('#portal-dlg form'), async () => {
            const { link } = await api('portal-link', { body: { project_id: cur.project.id, contact_id: portalContact.id, do: what } });
            drawPortal(link);
            renderTeam();
            if (what !== 'get') toast(what === 'revoke' ? t('הקישור בוטל') : t('נוצר קישור חדש'));
        });
    }

    async function openMember() {
        const dlg = $('#member-dlg');
        const form = $('form', dlg);
        formError(form, '');
        form.reset();
        const lists = await Promise.all(['supplier', 'lawyer', 'client'].map(k => contactsOf(k).catch(() => [])));
        const inTeam = new Set(cur.team.map(c => c.id));
        const groups = ['ספקים', 'עורכי דין', 'לקוחות'].map((label, i) => { // i18n-ok
            const items = lists[i].filter(c => teamOf(cur).includes(c.owner_id) && !c.archived && !inTeam.has(c.id));
            return items.length ? `<optgroup label="${esc(t(label))}">${options(items.map(c => ({ value: c.id, label: c.name + (c.company ? ' · ' + c.company : '') })))}</optgroup>` : '';
        }).join('');
        form.elements.contact_id.innerHTML = groups || `<option value="">${esc(t('אין אנשי קשר להוספה'))}</option>`;
        dlg.showModal();
    }

    async function addMember() {
        const form = $('#member-dlg form');
        const contact_id = Number(form.elements.contact_id.value);
        if (!contact_id) { formError(form, t('יש לבחור איש קשר')); return; }
        await busy($('button[type=submit]', form), form, async () => {
            update(await api('member', { body: { project_id: cur.project.id, contact_id, add: true, role: form.elements.role.value } }));
            $('#member-dlg').close();
        });
    }

    async function removeMember(id, name) {
        if (!confirm(t('להסיר את {name} מהצוות?', { name }))) return;
        await busy(null, null, async () => update(await api('member', { body: { project_id: cur.project.id, contact_id: id, add: false } })));
    }

    // ---- פרטים ----

    async function renderDetails() {
        const p = cur.project;
        const r = $(`#project-status input[value="${p.status}"]`);
        if (r) r.checked = true;
        const form = $('#details-form');
        formError(form, '');
        await fillProjectFields($('[data-project-fields]', form), p, true);
    }

    async function setStatus(status) {
        await busy(null, null, async () => {
            update(await api('project-status', { body: { id: cur.project.id, status } }));
            toast(t('הסטטוס עודכן'));
        });
    }

    async function saveDetails() {
        const form = $('#details-form');
        const body = { id: cur.project.id, ...readProjectFields(form) };
        if (!body.name) { formError(form, t('שם הפרויקט: שדה חובה')); return; }
        await busy($('button', form), form, async () => {
            update(await api('project', { body }));
            toast(t('הפרטים נשמרו'));
        });
    }

    /** הפרויקט מחדש מהשרת — אחרי פעולה בלשונית אחרת שמזיזה שלבים (הצעות). */
    async function reload() {
        cur = await api('project', { params: { id: cur.project.id } });
        header();
        return cur;
    }

    return { showList, showProject, reload, fillSuppliers };
})();
