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
        $('#stage-list').addEventListener('click', e => {
            const row = e.target.closest('[data-stage]');
            if (row) openStage(Number(row.dataset.stage));
        });
        $('#stage-list').addEventListener('keydown', e => {
            const row = e.target.closest('[data-stage]');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openStage(Number(row.dataset.stage)); }
        });
        $('#add-member').addEventListener('click', openMember);
        $('#team-list').addEventListener('click', e => {
            const b = e.target.closest('[data-remove]');
            if (b) removeMember(Number(b.dataset.remove), b.dataset.name);
        });
        $('#project-status').addEventListener('change', e => setStatus(e.target.value));
        $('#details-form').addEventListener('submit', e => { e.preventDefault(); saveDetails(); });

        // חלונות
        $('#project-dlg form').addEventListener('submit', e => { e.preventDefault(); createProject(); });
        $('#stage-dlg form').addEventListener('submit', e => { e.preventDefault(); saveStage(); });
        $('#stage-dlg [data-delete]').addEventListener('click', deleteStage);
        $$('#stage-dlg [data-move]').forEach(b => b.addEventListener('click', () => moveStage(Number(b.dataset.move))));
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
            $('#project-list').innerHTML = `<p class="empty">${searching ? 'לא נמצאו פרויקטים' : 'עוד אין פרויקטים. לחץ "+ פרויקט" כדי לפתוח את הראשון.'}</p>`;
            return;
        }
        $('#project-list').innerHTML = list.map(p => {
            const meta = [
                p.client_name ? esc(p.client_name) : '',
                p.current_stage ? `${esc(p.current_stage.name)}: ${esc(state.labels.stage[p.current_stage.status] || '')}` : (p.stages_total ? 'כל השלבים בוצעו' : ''),
                p.stages_total ? `${p.stages_done}/${p.stages_total}` : '',
                isDev() && p.owner_id !== state.user.id && p.owner_name ? 'של ' + esc(p.owner_name) : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            const status = p.status !== 'active' ? `<span class="chip">${esc(state.labels.project[p.status])}</span>` : '';
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
        form.elements.template_id.innerHTML = options(state.templates.map(t => ({ value: t.id, label: `${t.name} (${t.stages.length} שלבים)` })), state.templates[0]?.id, 'בלי תבנית');
        dlg.showModal();
        form.elements.name.focus();
    }

    async function createProject() {
        const form = $('#project-dlg form');
        const body = readProjectFields(form);
        body.template_id = Number(form.elements.template_id.value) || 0;
        if (!body.name) { formError(form, 'שם הפרויקט: שדה חובה'); return; }
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
        const mine = c => c.owner_id === (p.owner_id ?? state.user.id) && (!c.archived || c.id === p.client_id || c.id === p.lawyer_id);
        const kinds = Object.entries(state.labels.kind).map(([value, label]) => ({ value, label }));
        box.innerHTML = `
            <label class="f wide"><span>שם הפרויקט *</span><input name="name" maxlength="100" required value="${esc(p.name || '')}" placeholder="למשל: שיפוץ דירת כהן"></label>
            <label class="f"><span>לקוח</span><select name="client_id">${options(clients.filter(mine).map(c => ({ value: c.id, label: c.name })), p.client_id, 'בחירה')}<option value="__new">+ לקוח חדש</option></select></label>
            <label class="f"><span>עורך דין</span><select name="lawyer_id">${options(lawyers.filter(mine).map(c => ({ value: c.id, label: c.name })), p.lawyer_id, p.id ? 'ללא' : 'לפי הלקוח')}</select></label>
            <label class="f"><span>סוג</span><select name="kind">${options(kinds, p.kind)}</select></label>
            <label class="f"><span>תקציב ₪ <small>כולל מע"מ</small></span><input name="budget" type="number" inputmode="numeric" min="0" step="1" value="${p.budget || ''}"></label>
            <label class="f wide"><span>כתובת הנכס</span><input name="address" maxlength="200" value="${esc(p.address || '')}"></label>
            <label class="f"><span>התחלה</span><input name="start_date" type="date" value="${esc(p.start_date || '')}"></label>
            <label class="f"><span>יעד מסירה</span><input name="due_date" type="date" value="${esc(p.due_date || '')}"></label>
            ${withNotes ? `<label class="f wide"><span>הערות</span><textarea name="notes" rows="3" maxlength="4000">${esc(p.notes || '')}</textarea></label>` : ''}`;
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
        pane = ['timeline', 'files', 'stages', 'quotes', 'team', 'details'].includes(p) ? p : 'timeline';
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
        const client = cur.team.find(t => t.roles.includes('לקוח'));
        const sub = [client?.name, state.labels.kind[p.kind], p.status !== 'active' ? state.labels.project[p.status] : ''].filter(Boolean).join(' · ');
        $('#subtitle').textContent = sub;
        $('#subtitle').hidden = !sub;
        document.title = p.name + ' — ניהול פרויקטים';
    }

    function render() {
        header();
        if (pane === 'timeline') renderTimeline();
        if (pane === 'files') Files.show(cur.project, Number(subFolder) || 0);
        if (pane === 'stages') renderStages();
        if (pane === 'quotes') Quotes.show(cur);
        if (pane === 'team') renderTeam();
        if (pane === 'details') renderDetails();
    }

    /** תשובת שרת שמחזירה את הפרויקט המעודכן. */
    function update(data) {
        cur = data;
        render();
    }

    // ---- ציר זמן ----

    function renderTimeline() {
        let day = '';
        $('#timeline').innerHTML = cur.events.map(e => {
            const d = new Date(e.at).toDateString();
            const sep = d !== day ? `<li class="day"><span>${esc(dateLabel(e.at))}</span></li>` : '';
            day = d;
            const time = `<time datetime="${esc(e.at)}">${new Date(e.at).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' })}</time>`;
            if (e.kind === 'note') {
                const mine = e.actor_id === state.user.id;
                return sep + `<li class="msg${mine ? ' mine' : ''}">
                    ${mine ? '' : `<b>${esc(e.actor_name)}</b>`}
                    <p>${esc(e.text)}</p>${time}</li>`;
            }
            return sep + `<li class="sys"><span>${esc(e.text)} · ${esc(e.actor_name)} · ${time}</span></li>`;
        }).join('') || '<li class="day"><span>עוד אין כלום בציר הזמן</span></li>';
        requestAnimationFrame(() => {
            const last = $('#timeline li:last-child');
            if (last) last.scrollIntoView({ block: 'end' });
        });
    }

    function autoGrow(t) {
        t.style.height = 'auto';
        t.style.height = Math.min(t.scrollHeight, 160) + 'px';
    }

    async function sendNote() {
        const t = $('#note');
        const text = t.value.trim();
        if (!text) return;
        await busy($('#composer button'), null, async () => {
            update(await api('note', { body: { project_id: cur.project.id, text } }));
            t.value = '';
            autoGrow(t);
            t.focus();
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
        $('#progress').setAttribute('aria-label', `${done} מתוך ${s.length} שלבים בוצעו`);
        $('#progress-text').textContent = s.length ? `${done}/${s.length} בוצעו` : '';
        $('#stage-list').innerHTML = s.map((x, i) => {
            const l2 = [
                x.trade_name ? esc(x.trade_name) : '',
                x.supplier_name ? esc(x.supplier_name) : '<span class="muted">אין ספק</span>',
                x.plan_start || x.plan_end ? `${shortDate(x.plan_start)}–${shortDate(x.plan_end)}` : '',
                x.needs_lawyer ? 'עו"ד' : '',
            ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
            return `<li class="card" data-stage="${x.id}" tabindex="0" role="button" aria-label="${esc(x.name)}, ${esc(state.labels.stage[x.status])}">
                <span class="num st-${esc(x.status)}" aria-hidden="true">${i + 1}</span>
                <div class="body"><div class="l1">${esc(x.name)}</div><div class="l2">${l2}</div></div>
                ${stageChip(x.status)}
            </li>`;
        }).join('') || '<p class="empty">אין שלבים. לחץ "+ שלב" כדי להוסיף.</p>';
    }

    let stageId = null;

    async function openStage(id) {
        const dlg = $('#stage-dlg');
        const form = $('form', dlg);
        formError(form, '');
        stageId = id;
        const s = id ? cur.stages.find(x => x.id === id) : { status: 'planned' };
        $('#stage-dlg-title').textContent = id ? s.name : 'שלב חדש';
        const el = form.elements;
        el.name.value = s.name || '';
        el.trade_id.innerHTML = options(state.trades.map(t => ({ value: t.id, label: t.name })), s.trade_id, 'ללא');
        el.status.innerHTML = options(Object.entries(state.labels.stage).map(([value, label]) => ({ value, label })), s.status);
        el.plan_start.value = s.plan_start || '';
        el.plan_end.value = s.plan_end || '';
        el.needs_lawyer.checked = !!s.needs_lawyer;
        $('[data-existing]', dlg).hidden = !id;
        await fillSuppliers(el.supplier_id, Number(el.trade_id.value) || 0, s.supplier_id);
        el.trade_id.onchange = () => fillSuppliers(el.supplier_id, Number(el.trade_id.value) || 0, Number(el.supplier_id.value) || null);
        dlg.showModal();
    }

    /** ספקים של בעל הפרויקט — קודם מי שבתחום של השלב. */
    async function fillSuppliers(select, tradeId, value, ownerId = cur.project.owner_id) {
        let all = [];
        try { all = await contactsOf('supplier'); } catch { /* ריק */ }
        all = all.filter(c => c.owner_id === ownerId && (!c.archived || c.id === value));
        const opt = c => ({ value: c.id, label: c.name + (c.company ? ' · ' + c.company : '') });
        const inTrade = tradeId ? all.filter(c => c.trades.includes(tradeId)) : [];
        const rest = all.filter(c => !inTrade.includes(c));
        select.innerHTML = '<option value="">ללא</option>'
            + (inTrade.length ? `<optgroup label="בתחום">${options(inTrade.map(opt), value)}</optgroup>` : '')
            + (rest.length ? `<optgroup label="${inTrade.length ? 'אחרים' : 'כל הספקים'}">${options(rest.map(opt), value)}</optgroup>` : '');
    }

    async function saveStage() {
        const form = $('#stage-dlg form');
        const el = form.elements;
        const body = {
            id: stageId || undefined, project_id: cur.project.id, name: el.name.value.trim(),
            trade_id: Number(el.trade_id.value) || null, supplier_id: Number(el.supplier_id.value) || null,
            status: el.status.value, needs_lawyer: el.needs_lawyer.checked,
            plan_start: el.plan_start.value, plan_end: el.plan_end.value,
        };
        if (!body.name) { formError(form, 'שם השלב: שדה חובה'); return; }
        await busy($('button[type=submit]', form), form, async () => {
            update(await api('stage', { body }));
            $('#stage-dlg').close();
        });
    }

    async function moveStage(dir) {
        const ids = cur.stages.map(s => s.id);
        const i = ids.indexOf(stageId), j = i + dir;
        if (i < 0 || j < 0 || j >= ids.length) return;
        [ids[i], ids[j]] = [ids[j], ids[i]];
        await busy(null, $('#stage-dlg form'), async () => {
            update(await api('stages-order', { body: { project_id: cur.project.id, ids } }));
            toast(dir < 0 ? 'הוזז למעלה' : 'הוזז למטה');
        });
    }

    async function deleteStage() {
        const s = cur.stages.find(x => x.id === stageId);
        if (!s || !confirm(`למחוק את השלב "${s.name}"?`)) return;
        await busy(null, $('#stage-dlg form'), async () => {
            update(await api('stage-delete', { body: { id: s.id } }));
            $('#stage-dlg').close();
        });
    }

    // ---- צוות ----

    function renderTeam() {
        $('#team-list').innerHTML = cur.team.map(c => `<li class="card">
            ${avatar(c.name)}
            <div class="body"><div class="l1">${esc(c.name)}${c.company ? `<span class="sub">${esc(c.company)}</span>` : ''}</div>
                <div class="l2">${c.roles.map(esc).join('<i class="sep" aria-hidden="true">·</i>')}</div></div>
            <div class="acts">${contactActs(c)}${c.removable ? `<button class="icon-btn" data-remove="${c.id}" data-name="${esc(c.name)}" aria-label="הסרת ${esc(c.name)} מהצוות">✕</button>` : ''}</div>
        </li>`).join('') || '<p class="empty">עוד אין צוות. שייך לקוח בפרטים, או ספק לשלב.</p>';
    }

    async function openMember() {
        const dlg = $('#member-dlg');
        const form = $('form', dlg);
        formError(form, '');
        form.reset();
        const lists = await Promise.all(['supplier', 'lawyer', 'client'].map(k => contactsOf(k).catch(() => [])));
        const inTeam = new Set(cur.team.map(t => t.id));
        const groups = ['ספקים', 'עורכי דין', 'לקוחות'].map((label, i) => {
            const items = lists[i].filter(c => c.owner_id === cur.project.owner_id && !c.archived && !inTeam.has(c.id));
            return items.length ? `<optgroup label="${label}">${options(items.map(c => ({ value: c.id, label: c.name + (c.company ? ' · ' + c.company : '') })))}</optgroup>` : '';
        }).join('');
        form.elements.contact_id.innerHTML = groups || '<option value="">אין אנשי קשר להוספה</option>';
        dlg.showModal();
    }

    async function addMember() {
        const form = $('#member-dlg form');
        const contact_id = Number(form.elements.contact_id.value);
        if (!contact_id) { formError(form, 'יש לבחור איש קשר'); return; }
        await busy($('button[type=submit]', form), form, async () => {
            update(await api('member', { body: { project_id: cur.project.id, contact_id, add: true, role: form.elements.role.value } }));
            $('#member-dlg').close();
        });
    }

    async function removeMember(id, name) {
        if (!confirm(`להסיר את ${name} מהצוות?`)) return;
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
            toast('הסטטוס עודכן');
        });
    }

    async function saveDetails() {
        const form = $('#details-form');
        const body = { id: cur.project.id, ...readProjectFields(form) };
        if (!body.name) { formError(form, 'שם הפרויקט: שדה חובה'); return; }
        await busy($('button', form), form, async () => {
            update(await api('project', { body }));
            toast('הפרטים נשמרו');
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
