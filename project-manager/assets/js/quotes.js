// הצעות מחיר — שלב 5 (lib/quotes.php). לשונית "הצעות" בפרויקט.
//
// ההצעות מקובצות לפי שלב, מהזולה ליקרה, עם הפרש מהזולה — זו ההשוואה. ⭐ = מומלצת.
// "📤 ללקוח" מסמן את ההצעות של השלב כנשלחו ופותח הודעה מוכנה (וואטסאפ / SMS / מייל).
// בחלון ההצעה המנהל רושם את ההחלטה של הלקוח או העו"ד ("אישר בטלפון") — עם צילום
// מצב של ההצעה. בשלב 6 הלקוח יאשר בעצמו מהקישור האישי, לאותה היסטוריה.
'use strict';

const Quotes = (() => {
    let project = null, data = { quotes: [], summary: {} }, bound = false, current = null, pending = null;
    const LIVE = ['open', 'sent', 'client_approved'];
    const ICON = { open: '📥', sent: '📤', client_approved: '⏳', approved: '✅', declined: '▫️', rejected: '✖️', cancelled: '🚫' };
    const stageOf = id => project.stages.find(s => s.id === id);
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;
    const pct = (a, b) => Math.round((a - b) / b * 100);

    function bind() {
        if (bound) return;
        bound = true;
        $('#add-quote').addEventListener('click', () => open(null));
        $('#invite-quotes').addEventListener('click', () => openInvite());
        $('#invite-dlg form').addEventListener('submit', e => { e.preventDefault(); sendInvite(); });
        $('#invite-dlg [name=stage_id]').addEventListener('change', fillInvitees);
        $('#quote-list').addEventListener('click', e => {
            const send = e.target.closest('[data-send]');
            if (send) { e.stopPropagation(); sendToClient(Number(send.dataset.send)); return; }
            const row = e.target.closest('[data-quote]');
            if (row) open(Number(row.dataset.quote));
        });
        $('#quote-list').addEventListener('keydown', e => {
            const row = e.target.closest('[data-quote]');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); row.click(); }
        });
        const form = $('#quote-dlg form');
        form.addEventListener('submit', e => { e.preventDefault(); save(); });
        form.elements.stage_id.addEventListener('change', () => fillSuppliers());
        form.elements.file_id.addEventListener('change', fileLink);
        $('#quote-pick').addEventListener('change', e => { uploadFile(e.target.files[0]); e.target.value = ''; });
        $('#quote-recommend').addEventListener('click', recommend);
        $('#quote-cancel').addEventListener('click', cancel);
        $('#decision-btns').addEventListener('click', e => {
            const b = e.target.closest('[data-decide]');
            if (b) decide(b.dataset.role, b.dataset.decide);
        });
        $('#decisions').addEventListener('click', async e => {
            const b = e.target.closest('[data-sig]');
            if (!b) return;
            try {
                const { approval } = await api('approval', { params: { id: b.dataset.sig } });
                const img = new Image();
                img.className = 'sig-img';
                img.alt = 'החתימה של ' + approval.by_name;
                img.src = approval.signature;
                b.replaceWith(img);
            } catch (x) { toast(x.message, true); }
        });
    }

    /** project = התשובה של api('project') — הפרויקט, השלבים והצוות. */
    async function show(p) {
        bind();
        project = p;
        await load();
        if (pending) { const x = pending; pending = null; open(null, x); }
    }

    async function load() {
        try { data = await api('quotes', { params: { project_id: project.project.id } }); }
        catch (e) { toast(e.message, true); return; }
        render();
    }

    /** אחרי פעולה: ההצעות מהתשובה, והפרויקט מחדש — כי סטטוס השלבים זז. */
    async function refresh(d) {
        data = d;
        project = await Projects.reload();
        render();
    }

    function render() {
        const s = data.summary;
        const parts = [`אושר ${money(s.approved)}` + (s.budget ? ` מתוך ${money(s.budget)} (${Math.round(s.approved / s.budget * 100)}%)` : '')];
        if (s.open) parts.push(`${s.open} ממתינות`);
        $('#q-sum').innerHTML = parts.map(esc).join('<i class="sep" aria-hidden="true">·</i>');
        $('#q-sum').classList.toggle('over', !!s.budget && s.approved > s.budget);

        const byStage = new Map();
        for (const q of data.quotes) {
            if (!byStage.has(q.stage_id)) byStage.set(q.stage_id, []);
            byStage.get(q.stage_id).push(q);
        }
        const html = project.stages.filter(st => byStage.has(st.id)).map(st => {
            const list = byStage.get(st.id);
            const live = list.filter(q => LIVE.includes(q.status) || q.status === 'approved');
            const low = live.length > 1 ? Math.min(...live.map(q => q.amount)) : null;
            // מומלצת ואושרה למעלה, אחר כך לפי סכום; מבוטלות בסוף
            const rank = q => q.status === 'approved' ? 0 : q.status === 'client_approved' ? 1 : q.recommended ? 2 : LIVE.includes(q.status) ? 3 : q.status === 'cancelled' ? 5 : 4;
            list.sort((a, b) => rank(a) - rank(b) || a.amount - b.amount);
            const canSend = list.some(q => q.status === 'open' || q.status === 'sent');
            return `<section class="q-stage" aria-label="${esc(st.name)}">
                <header class="q-head"><h2 class="one">${esc(st.name)}</h2>${stageChip(st.status)}
                    ${canSend ? `<button class="btn ghost sm" data-send="${st.id}" aria-label="שליחת ההצעות של ${esc(st.name)} ללקוח">📤 ללקוח</button>` : ''}</header>
                <ul class="list rows-list">${list.map(q => row(q, low)).join('')}</ul>
            </section>`;
        }).join('');
        const without = project.stages.filter(st => !byStage.has(st.id) && !['done', 'paid'].includes(st.status));
        $('#quote-list').innerHTML = (html || '<p class="empty">עוד אין הצעות מחיר. "+ הצעה", או מחלון של קובץ: "🧾 הצעת מחיר מהקובץ".</p>')
            + (html && without.length ? `<p class="hint q-without one" title="${esc(without.map(s => s.name).join(', '))}">בלי הצעות (${without.length}): ${without.map(s => esc(s.name)).join(', ')}</p>` : '');
    }

    function row(q, low) {
        const dead = ['declined', 'rejected', 'cancelled'].includes(q.status);
        const diff = low !== null && !dead
            ? (q.amount === low ? '<b class="cheap">הזולה</b>' : `+${pct(q.amount, low)}%`) : '';
        const expired = q.valid_until && LIVE.includes(q.status) && q.valid_until < new Date().toISOString().slice(0, 10);
        const l2 = [
            `${ICON[q.status]} ${esc(state.labels.quote[q.status])}`,
            diff,
            q.valid_until ? `<span class="${expired ? 'late' : ''}">${expired ? 'פג ' : 'עד '}${shortDate(q.valid_until)}</span>` : '',
            q.terms ? esc(q.terms) : '',
        ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
        const pic = q.file_thumb ? `<img class="fthumb" src="${fileUrl(q.file_id, '&thumb=1')}" alt="" loading="lazy" width="40" height="40">`
            : `<span class="ficon" aria-hidden="true">${q.file_id ? '🧾' : '✍️'}</span>`;
        return `<li class="card q-row q-${q.status}${q.recommended ? ' rec' : ''}" data-quote="${q.id}" tabindex="0" role="button"
                aria-label="${esc(q.supplier_name)}, ${esc(money(q.amount))}, ${esc(state.labels.quote[q.status])}${q.recommended ? ', מומלצת' : ''}">
            ${pic}
            <div class="body"><div class="l1"><span class="one">${q.recommended ? '<span aria-hidden="true">⭐ </span>' : ''}${esc(q.supplier_name)}</span>
                <span class="amt">${esc(money(q.amount))}</span></div>
                <div class="l2">${l2}</div></div>
        </li>`;
    }

    // ================= חלון הצעה =================

    /** פתיחה מחלון קובץ: עוברים ללשונית ההצעות, והחלון נפתח עם הקובץ והספק. */
    function fromFile(projectId, preset) {
        pending = preset;
        location.hash = `#/p/${projectId}/quotes`;
    }

    async function open(id, preset = {}) {
        const dlg = $('#quote-dlg'), form = $('form', dlg), el = form.elements;
        formError(form, '');
        current = id ? data.quotes.find(q => q.id === id) : null;
        const q = current || { stage_id: preset.stage_id || stageGuess(), supplier_id: preset.supplier_id || null, file_id: preset.file_id || null };
        $('#quote-dlg-title').textContent = current ? `${current.supplier_name} — ${money(current.amount)}` : 'הצעת מחיר חדשה';
        el.stage_id.innerHTML = options(project.stages.map(s => ({ value: s.id, label: s.name })), q.stage_id, project.stages.length ? undefined : 'אין שלבים');
        el.amount.value = current ? String(current.amount) : '';
        el.valid_until.value = q.valid_until || '';
        el.terms.value = q.terms || '';
        el.notes.value = q.notes || '';
        const locked = current?.status === 'cancelled';
        [...form.elements].forEach(x => { if (x.name) x.disabled = locked; });
        $('button[type=submit]', form).hidden = locked;
        $('.upload-btn', form).hidden = locked;
        $('#quote-version-hint').hidden = !current || !['sent', 'client_approved', 'approved'].includes(current.status);
        $('#quote-state').hidden = !current;
        if (current) {
            $('#quote-state').innerHTML = `<span class="chip q-${current.status}">${ICON[current.status]} ${esc(state.labels.quote[current.status])}</span>`
                + (current.recommended ? '<span class="chip rec">⭐ מומלצת</span>' : '')
                + `<span class="chip">גרסה ${current.version}</span>`;
        }
        await Promise.all([fillSuppliers(q.supplier_id), fillFiles(q.file_id)]);
        renderActions();
        dlg.showModal();
        if (!current) el.amount.focus();
    }

    /** שלב ברירת מחדל: הראשון שעוד אוסף הצעות. */
    function stageGuess() {
        return (project.stages.find(s => ['quotes', 'planned'].includes(s.status)) || project.stages[0])?.id;
    }

    async function fillSuppliers(value) {
        const el = $('#quote-dlg form').elements;
        const st = stageOf(Number(el.stage_id.value));
        await Projects.fillSuppliers(el.supplier_id, st?.trade_id || 0, value ?? (Number(el.supplier_id.value) || null), project.project.owner_id);
        el.supplier_id.options[0].textContent = 'בחירת ספק';
    }

    async function fillFiles(value) {
        const el = $('#quote-dlg form').elements;
        let files = [];
        try { ({ files } = await api('files', { params: { project_id: project.project.id } })); } catch { /* ריק */ }
        const quotes = files.filter(f => f.type === 'quote'), rest = files.filter(f => f.type !== 'quote');
        const opt = f => ({ value: f.id, label: f.name });
        el.file_id.innerHTML = '<option value="">ללא קובץ</option>'
            + (quotes.length ? `<optgroup label="הצעות מחיר">${options(quotes.map(opt), value)}</optgroup>` : '')
            + (rest.length ? `<optgroup label="קבצים אחרים">${options(rest.map(opt), value)}</optgroup>` : '');
        fileLink();
    }

    function fileLink() {
        const id = Number($('#quote-dlg form').elements.file_id.value);
        $('#quote-file-open').hidden = !id;
        if (id) $('#quote-file-open').href = fileUrl(id);
    }

    /** קובץ חדש — עולה ל"ממתין למיון", ובשמירה עובר לתיקיית הספק. */
    async function uploadFile(file) {
        if (!file) return;
        const form = $('#quote-dlg form');
        if (file.size > state.maxUpload) { formError(form, `הקובץ גדול מ-${Math.round(state.maxUpload / 1048576)}MB`); return; }
        const box = $('#quote-upload');
        const bar = p => { box.innerHTML = `<div class="up"><span class="one">${esc(file.name)}</span><span>${p}%</span><i style="width:${p}%"></i></div>`; };
        bar(0);
        try {
            const { folders } = await api('folders', { params: { project_id: project.project.id } });
            const inbox = folders.find(f => f.kind === 'inbox' && f.parent_id === null);
            const { file: saved } = await Files.send(file, project.project.id, inbox.id, bar);
            await fillFiles(saved.id);
        } catch (e) { formError(form, e.message); }
        box.innerHTML = '';
    }

    function renderActions() {
        const q = current;
        $('#quote-actions').hidden = !q;
        if (!q) return;
        const hasLawyer = !!project.project.lawyer_id;
        const st = stageOf(q.stage_id);
        const btn = (role, decide, label, cls = 'ghost') => `<button type="button" class="btn ${cls}" data-role="${role}" data-decide="${decide}">${label}</button>`;
        let b = '';
        if (['open', 'sent', 'declined', 'rejected'].includes(q.status)) b = btn('client', 'approved', '✅ הלקוח אישר', 'primary') + btn('client', 'rejected', '✖ הלקוח דחה');
        if (q.status === 'client_approved' && hasLawyer) b = btn('lawyer', 'approved', '✅ העו"ד אישר', 'primary') + btn('lawyer', 'rejected', '✖ העו"ד דחה');
        if (['in_progress', 'done', 'paid'].includes(st?.status) && q.status !== 'client_approved') b = '';
        $('#decision-btns').innerHTML = b;
        $('#decision-note').closest('.f').hidden = !b;
        $('#decision-note').value = '';
        const roleName = { client: 'הלקוח', lawyer: 'העו"ד' };
        const method = { manual: 'נרשם ידנית', click: 'בקישור האישי', signature: 'בחתימה בקישור האישי' };
        $('#decisions').innerHTML = q.decisions.slice().reverse().map(d => `<li class="${d.decision}">
            <span>${d.decision === 'approved' ? '✅' : '✖'} ${roleName[d.role]} ${d.decision === 'approved' ? 'אישר' : 'דחה'} — ${esc(money(d.amount))}${d.version !== q.version ? ` <small>(גרסה ${d.version}, לא בתוקף)</small>` : ''}</span>
            <small>${esc(d.by_name)} · ${esc(new Date(d.at).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }))} · ${method[d.method] || d.method}${d.note ? ' · ' + esc(d.note) : ''}</small>
            ${d.signed ? `<button type="button" class="btn ghost sm" data-sig="${d.id}">✍️ החתימה</button>` : ''}
        </li>`).join('');
        $('#decisions-title').hidden = !q.decisions.length;
        $('#quote-recommend').hidden = !LIVE.includes(q.status);
        $('#quote-recommend').textContent = q.recommended ? '⭐ ביטול ההמלצה' : '⭐ המלצה ללקוח';
        $('#quote-cancel').hidden = q.status === 'cancelled';
    }

    async function save() {
        const form = $('#quote-dlg form'), el = form.elements;
        const body = {
            id: current?.id, stage_id: Number(el.stage_id.value), supplier_id: Number(el.supplier_id.value) || 0,
            amount: el.amount.value.trim(), valid_until: el.valid_until.value, terms: el.terms.value, notes: el.notes.value,
            file_id: Number(el.file_id.value) || null,
        };
        if (!body.stage_id) { formError(form, 'שלב: שדה חובה. מוסיפים שלבים בלשונית "שלבים"'); return; }
        if (!body.supplier_id) { formError(form, 'ספק: שדה חובה'); return; }
        if (!body.amount) { formError(form, 'סכום: שדה חובה'); return; }
        await busy($('button[type=submit]', form), form, async () => {
            await refresh(await api('quote', { body }));
            $('#quote-dlg').close();
            toast('נשמר');
        });
    }

    async function decide(role, decision) {
        const form = $('#quote-dlg form');
        const note = $('#decision-note').value.trim();
        const who = role === 'client' ? 'הלקוח' : 'העו"ד';
        if (decision === 'approved' && !confirm(`לרשום ש${who} אישר את ההצעה של ${current.supplier_name} על ${money(current.amount)}?`)) return;
        await busy(null, form, async () => {
            await refresh(await api('quote-decide', { body: { id: current.id, role, decision, note } }));
            $('#quote-dlg').close();
            toast(decision === 'approved' ? 'האישור נרשם' : 'הדחייה נרשמה');
        });
    }

    async function recommend() {
        await busy($('#quote-recommend'), $('#quote-dlg form'), async () => {
            await refresh(await api('quote-recommend', { body: { id: current.id, on: !current.recommended } }));
            $('#quote-dlg').close();
        });
    }

    async function cancel() {
        const note = prompt('לבטל את ההצעה? היא תישאר בהיסטוריה.\nסיבה (לא חובה):', '');
        if (note === null) return;
        await busy($('#quote-cancel'), $('#quote-dlg form'), async () => {
            await refresh(await api('quote-cancel', { body: { id: current.id, note } }));
            $('#quote-dlg').close();
            toast('ההצעה בוטלה');
        });
    }

    // ================= שליחה ללקוח =================

    async function sendToClient(stageId) {
        const st = stageOf(stageId);
        let mailed = [];
        try { const r = await api('quotes-send', { body: { stage_id: stageId } }); mailed = (r.notified || []).map(n => n.name); await refresh(r); }
        catch (e) { toast(e.message, true); return; }
        const client = project.team.find(t => t.roles.includes('לקוח'));
        const list = data.quotes.filter(q => q.stage_id === stageId && q.status === 'sent')
            .sort((a, b) => b.recommended - a.recommended || a.amount - b.amount);
        const lines = list.map(q => `${q.recommended ? '⭐' : '•'} ${q.supplier_name}${q.supplier_company ? ` (${q.supplier_company})` : ''} — ${money(q.amount)}`
            + (q.valid_until ? `, בתוקף עד ${shortDate(q.valid_until)}` : '') + (q.recommended ? ' — ההמלצה שלי' : ''));
        // הקישור האישי של הלקוח — שם הוא רואה את ההצעות ומאשר בעצמו
        const url = await portalUrl(project.project.id, client?.id);
        const text = renderMsg('quotes_sent', { 'שם': client?.name || '', 'פרויקט': project.project.name, 'שלב': st.name, 'פרטים': lines.join('\n'), 'קישור': url });
        Send.open({ title: 'שליחת ההצעות ללקוח', recipients: [{ contact: client, label: 'הלקוח', text }], key: 'quotes_sent', projectId: project.project.id, mailed,
            subject: 'הצעות מחיר — ' + project.project.name,
            hint: 'ההצעות סומנו "נשלחה ללקוח", והשלב ממתין לאישורו. בקישור האישי הלקוח רואה את ההצעות ומאשר בעצמו. אם הוא עונה בטלפון — פותחים את ההצעה ורושמים את ההחלטה.' });
    }

    // ================= בקשת הצעות מספקים =================

    function openInvite(stageId) {
        const dlg = $('#invite-dlg'), form = $('form', dlg);
        formError(form, '');
        const open = project.stages.filter(s => ['planned', 'quotes', 'client_approval'].includes(s.status));
        form.elements.stage_id.innerHTML = options((open.length ? open : project.stages).map(s => ({ value: s.id, label: s.name })), stageId || stageGuess());
        fillInvitees();
        dlg.showModal();
    }

    async function fillInvitees() {
        const st = stageOf(Number($('#invite-dlg [name=stage_id]').value));
        let all = [];
        try { all = (await contactsOf('supplier')).filter(c => c.owner_id === project.project.owner_id && !c.archived); } catch { /* ריק */ }
        const asked = new Set(data.quotes.filter(q => q.stage_id === st?.id).map(q => q.supplier_id));
        const inTrade = st?.trade_id ? all.filter(c => c.trades.includes(st.trade_id)) : [];
        const list = [...inTrade, ...all.filter(c => !inTrade.includes(c))];
        $('#invite-suppliers').innerHTML = list.map(c => `<label><input type="checkbox" value="${c.id}"${inTrade.includes(c) && !asked.has(c.id) ? ' checked' : ''}>
            <span>${esc(c.name)}${asked.has(c.id) ? ' ✓' : ''}</span></label>`).join('') || '<p class="hint">אין ספקים. מוסיפים באנשי קשר ← ספקים.</p>';
    }

    async function sendInvite() {
        const form = $('#invite-dlg form');
        const stageId = Number(form.elements.stage_id.value);
        const ids = $$('#invite-suppliers input:checked').map(cb => Number(cb.value));
        if (!ids.length) { formError(form, 'בחרו ספק אחד לפחות'); return; }
        await busy($('button[type=submit]', form), form, async () => {
            const { invites, notified } = await api('quote-invite', { body: { stage_id: stageId, supplier_ids: ids } });
            $('#invite-dlg').close();
            project = await Projects.reload();
            const st = stageOf(stageId), p = project.project;
            Send.open({ title: 'בקשת הצעות — ' + st.name, subject: `בקשת הצעת מחיר — ${st.name}, ${p.name}`,
                key: 'quote_invite', projectId: p.id, mailed: (notified || []).map(n => n.name),
                recipients: invites.map(i => ({ contact: i.contact, label: i.contact.name,
                    text: renderMsg('quote_invite', { 'שם': i.contact.name, 'פרויקט': p.name, 'שלב': st.name, 'כתובת': p.address ? ` (${p.address})` : '', 'קישור': i.url }) })),
                hint: 'כל ספק — ההודעה והקישור שלו (בוחרים למעלה). הצעה שספק מגיש נכנסת לכאן לבד, ותקבל מייל.' });
            render();
        });
    }

    return { show, fromFile };
})();
