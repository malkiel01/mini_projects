// אבני דרך לתשלום — שלב 7 (lib/payments.php). לשונית "תשלומים" בפרויקט.
//
// האפליקציה רק מתעדת: הלקוח משלם ישירות לספק (בעצמו או דרך העו"ד).
// מתוכנן → "📤 דרישת תשלום" (הודעה עם הקישור האישי) → אישור עו"ד (כשנדרש)
// → הלקוח / העו"ד מעלה אסמכתא בקישור → המנהל: "✅ התקבל" (גם חלקי) — ונסגר.
'use strict';

const Payments = (() => {
    let project = null, data = { payments: [], summary: {} }, bound = false, current = null, mpFile = null;
    const ICON = { planned: '🗓️', requested: '📤', paid: '💳', partial: '◐', received: '✅', cancelled: '🚫' };
    const today = () => new Date().toISOString().slice(0, 10);
    const stageOf = id => project.stages.find(s => s.id === id);
    const fileUrl = id => `api.php?action=file&id=${id}`;
    const hasLawyer = () => !!project.project.lawyer_id;
    const contactOf = id => project.team.find(m => m.id === id);
    // שם שלב שנזרע — בשפה של ההודעה: מתורגם רק כשהיא שפת הממשק (t מתרגם לשפת הממשק בלבד)
    const inLang = (s, lang) => (lang === I18n.lang() ? t(s) : s);

    function bind() {
        if (bound) return;
        bound = true;
        $('#add-payment').addEventListener('click', () => open(null));
        $('#payment-list').addEventListener('click', e => {
            const row = e.target.closest('[data-payment]');
            if (row) open(Number(row.dataset.payment));
        });
        $('#payment-list').addEventListener('keydown', e => {
            const row = e.target.closest('[data-payment]');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); row.click(); }
        });
        const form = $('#payment-dlg form');
        form.addEventListener('submit', e => { e.preventDefault(); save(); });
        form.elements.stage_id.addEventListener('change', () => stageChanged(null));
        $$('#payment-dlg [name=mode]').forEach(r => r.addEventListener('change', modeChanged));
        form.elements.percent.addEventListener('input', quoteHint);
        $('#payment-main-btns').addEventListener('click', e => {
            const b = e.target.closest('[data-do]');
            if (b) ({ request: sendRequest, lawyer: lawyerApprove })[b.dataset.do]();
        });
        $('#proofs').addEventListener('click', e => {
            const b = e.target.closest('[data-review]');
            if (b) review(Number(b.dataset.review), b.dataset.ok === '1');
        });
        $('#mp-file').addEventListener('change', e => { mpFile = e.target.files[0] || null; $('#mp-file-name').textContent = mpFile ? '📎 ' + mpFile.name : ''; });
        $('#mp-save').addEventListener('click', manualProof);
        $('#payment-cancel').addEventListener('click', cancel);
    }

    async function show(p) {
        bind();
        project = p;
        try { data = await api('payments', { params: { project_id: project.project.id } }); }
        catch (e) { toast(e.message, true); return; }
        render();
    }

    async function refresh(d) {
        data = d;
        project = await Projects.reload();
        render();
    }

    function render() {
        const s = data.summary;
        const parts = [s.total ? t('התקבל {amount} מתוך {total}', { amount: money(s.received), total: money(s.total) }) : t('התקבל {amount}', { amount: money(s.received) })];
        if (s.waiting) parts.push(t('{n} לאישור קבלה', { n: s.waiting }));
        if (s.late) parts.push(`<span class="late">${esc(t('{n} באיחור', { n: s.late }))}</span>`);
        $('#pay-sum').innerHTML = parts.map(x => x.startsWith('<') ? x : esc(x)).join('<i class="sep" aria-hidden="true">·</i>');

        const groups = new Map();
        for (const p of data.payments) {
            const k = p.stage_id || 0;
            if (!groups.has(k)) groups.set(k, []);
            groups.get(k).push(p);
        }
        const order = [...project.stages.map(s => s.id).filter(id => groups.has(id)), ...(groups.has(0) ? [0] : [])];
        $('#payment-list').innerHTML = order.map(k => {
            const st = k ? stageOf(k) : null;
            return `<section class="q-stage"><header class="q-head"><h2 class="one">${esc(st ? t(st.name) : t('בלי שלב'))}</h2>${st ? stageChip(st.status) : ''}</header>
                <ul class="list rows-list">${groups.get(k).map(row).join('')}</ul></section>`;
        }).join('') || `<p class="empty">${esc(t('עוד אין אבני דרך לתשלום. "+ אבן דרך" — למשל מקדמה של 30% מההצעה שאושרה.'))}</p>`;
    }

    function row(p) {
        const late = p.due_date && p.due_date < today() && !['received', 'cancelled'].includes(p.status);
        const l2 = [
            esc(p.supplier_name),
            p.supplier_request && p.status === 'planned' ? `📥 ${esc(t('בקשת הספק'))}` : `${ICON[p.status]} ${esc(p.label)}`,
            p.due_date ? `<span class="${late ? 'late' : ''}">${esc(t(late ? 'באיחור · {date}' : 'עד {date}', { date: shortDate(p.due_date) }))}</span>` : '',
            p.status === 'partial' ? esc(t('נשאר {amount}', { amount: money(p.balance) })) : '',
        ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
        return `<li class="card q-row pay-${p.status}" data-payment="${p.id}" tabindex="0" role="button" aria-label="${esc(p.title)}, ${esc(money(p.amount))}, ${esc(p.label)}">
            <div class="body"><div class="l1"><span class="one">${esc(p.title)}</span><span class="amt">${esc(money(p.amount))}</span></div>
                <div class="l2">${l2}</div></div></li>`;
    }

    // ================= חלון =================

    async function open(id) {
        const dlg = $('#payment-dlg'), form = $('form', dlg), el = form.elements;
        formError(form, '');
        current = id ? data.payments.find(p => p.id === id) : null;
        const p = current || { stage_id: guessStage(), payer: defaultPayer(), needs_lawyer: hasLawyer() };
        $('#payment-dlg-title').textContent = current ? `${current.title} — ${money(current.amount)}` : t('אבן דרך לתשלום');
        el.stage_id.innerHTML = options(project.stages.map(s => ({ value: s.id, label: t(s.name) })), p.stage_id, t('בלי שלב'));
        el.title.value = p.title || '';
        el.amount.value = current ? String(current.amount) : '';
        el.percent.value = current?.percent ?? '';
        el.due_date.value = p.due_date || '';
        el.notes.value = p.notes || '';
        $(`#payment-dlg [name=mode][value=${current?.percent ? 'percent' : 'amount'}]`).checked = true;
        $(`#payment-dlg [name=payer][value=${p.payer || 'client'}]`).checked = true;
        $('#payer-box').hidden = !hasLawyer();
        $('#pay-lawyer-box').hidden = !hasLawyer();
        el.needs_lawyer.checked = !!p.needs_lawyer;
        const locked = current && !['planned', 'requested'].includes(current.status);
        [...form.elements].forEach(x => { if (x.name) x.disabled = !!locked; });
        $('button[type=submit]', form).hidden = !!locked;
        $('#payment-state').hidden = !current;
        if (current) $('#payment-state').innerHTML = (current.supplier_request ? `<span class="chip">📥 ${esc(t('הספק ביקש'))}</span>` : '')
            + (current.file_id ? `<a class="chip" href="api.php?action=file&id=${current.file_id}" target="_blank" rel="noopener">📄 ${esc(t('המסמך של הספק'))}</a>` : '')
            + `<span class="chip">${ICON[current.status]} ${esc(current.label)}</span>`
            + (current.received ? `<span class="chip">${esc(t('התקבל {amount}', { amount: money(current.received) }))}</span>` : '')
            + (current.lawyer ? `<span class="chip">⚖️ ${esc(t('{name} אישר', { name: current.lawyer.by }))}</span>` : '');
        await stageChanged(p.supplier_id || null);
        renderActions();
        dlg.showModal();
    }

    /** שלב ברירת מחדל: הראשון שיש בו הצעה מאושרת, ועוד לא נסגר. */
    function guessStage() {
        return project.stages.find(s => ['approved', 'in_progress', 'done'].includes(s.status))?.id || '';
    }

    function defaultPayer() {
        return 'client'; // ברירת המחדל של הלקוח — בכרטיס שלו; כאן אפשר לשנות
    }

    let approvedQuote = null;

    /** שלב נבחר: הספק — מההצעה שאושרה בו (או של השלב); ואחוז — רק כשיש הצעה כזו. */
    async function stageChanged(supplierId) {
        const el = $('#payment-dlg form').elements;
        const st = stageOf(Number(el.stage_id.value));
        approvedQuote = null;
        if (st) {
            try {
                const { quotes } = await api('quotes', { params: { project_id: project.project.id } });
                approvedQuote = quotes.find(q => q.stage_id === st.id && q.status === 'approved') || null;
            } catch { /* בלי אחוז */ }
        }
        const value = supplierId ?? approvedQuote?.supplier_id ?? st?.supplier_id ?? null;
        await Projects.fillSuppliers(el.supplier_id, st?.trade_id || 0, value, project.project.owner_id);
        el.supplier_id.options[0].textContent = t('בחירת ספק');
        $('#pay-mode-box').hidden = !approvedQuote;
        if (!approvedQuote) $('#payment-dlg [name=mode][value=amount]').checked = true;
        modeChanged();
    }

    function modeChanged() {
        const pct = $('#payment-dlg [name=mode]:checked').value === 'percent';
        $('#pay-amount-box').hidden = pct;
        $('#pay-percent-box').hidden = !pct;
        quoteHint();
    }

    function quoteHint() {
        const h = $('#pay-quote-hint');
        h.hidden = !approvedQuote;
        if (!approvedQuote) return;
        const pct = Number($('#payment-dlg form').elements.percent.value) || 0;
        const pctMode = $('#payment-dlg [name=mode]:checked').value === 'percent';
        h.textContent = t('ההצעה שאושרה: {name} — {amount}', { name: approvedQuote.supplier_name, amount: money(approvedQuote.amount) }) + (pctMode && pct ? ` · ${pct}% = ${money(Math.round(approvedQuote.amount * pct) / 100)}` : '');
    }

    function renderActions() {
        const p = current;
        $('#payment-actions').hidden = !p;
        if (!p) return;
        const b = [];
        if (['planned', 'requested'].includes(p.status)) b.push(`<button type="button" class="btn primary" data-do="request">📤 ${esc(t(p.status === 'planned' ? 'שליחת דרישת תשלום' : 'שליחה שוב'))}</button>`);
        const canLawyer = p.needs_lawyer && !p.lawyer && p.status === 'requested';
        if (canLawyer) b.push(`<button type="button" class="btn ghost" data-do="lawyer">⚖️ ${esc(t('העו"ד אישר'))}</button>`);
        $('#payment-main-btns').innerHTML = b.join('');
        $('#payment-main-btns').hidden = !b.length;
        $('#pay-lawyer-row').hidden = !canLawyer;
        $('#pay-lawyer-note').value = '';
        const role = { client: 'הלקוח', lawyer: 'העו"ד', manager: 'נרשם על ידי המנהל' }; // i18n-ok
        $('#proofs').innerHTML = p.proofs.slice().reverse().map(x => `<li class="${x.status === 'received' ? 'approved' : x.status === 'rejected' ? 'rejected' : ''}">
            <span>💳 ${esc(money(x.amount))} · ${esc(data.methods[x.method] || x.method)} · ${esc(shortDate(x.paid_on))}
                ${x.status === 'received' ? ` — ✅ ${esc(t('התקבל {amount}', { amount: money(x.received_amount) }))}` : x.status === 'rejected' ? ` — ✖ ${esc(t('לא התקבל'))}` : ''}</span>
            <small>${esc(x.by_name)} (${esc(role[x.by_role] ? t(role[x.by_role]) : x.by_role)}) · ${esc(new Date(x.created_at).toLocaleString(I18n.locale(), { dateStyle: 'short', timeStyle: 'short' }))}${x.note ? ' · ' + esc(x.note) : ''}${x.review_note ? ' · ' + esc(x.review_note) : ''}</small>
            <div class="row-btns wrap">
                ${x.file ? `<a class="btn ghost sm" href="${fileUrl(x.file.id)}" target="_blank" rel="noopener">📄 ${esc(t('האסמכתא'))}</a>` : ''}
                ${x.status === 'pending' ? `<button type="button" class="btn primary sm" data-review="${x.id}" data-ok="1">✅ ${esc(t('התקבל'))}</button>
                    <button type="button" class="btn ghost sm" data-review="${x.id}" data-ok="0">✖ ${esc(t('לא התקבל'))}</button>` : ''}
            </div></li>`).join('');
        $('#proofs-title').hidden = !p.proofs.length;
        const manual = ['planned', 'requested', 'partial', 'paid'].includes(p.status);
        $('#manual-proof').hidden = !manual;
        $('#manual-proof').open = false;
        $('#mp-amount').value = String(p.balance);
        $('#mp-date').value = today();
        $('#mp-method').innerHTML = options(Object.entries(data.methods).map(([value, label]) => ({ value, label })), 'transfer');
        $('#mp-note').value = '';
        mpFile = null;
        $('#mp-file-name').textContent = '';
        $('#payment-cancel').hidden = p.status === 'cancelled' || p.received > 0;
    }

    async function save() {
        const form = $('#payment-dlg form'), el = form.elements;
        const pct = $('#payment-dlg [name=mode]:checked').value === 'percent';
        const body = {
            id: current?.id, project_id: project.project.id, stage_id: Number(el.stage_id.value) || null,
            supplier_id: Number(el.supplier_id.value) || 0, title: el.title.value.trim(),
            amount: pct ? null : el.amount.value.trim(), percent: pct ? el.percent.value.trim() : '',
            due_date: el.due_date.value, payer: $('#payment-dlg [name=payer]:checked').value,
            needs_lawyer: el.needs_lawyer.checked, notes: el.notes.value,
        };
        if (!body.supplier_id) { formError(form, t('ספק: שדה חובה')); return; }
        if (!body.title) { formError(form, t('שם: שדה חובה (למשל "מקדמה")')); return; }
        if (pct ? !body.percent : !body.amount) { formError(form, t(pct ? 'אחוז: שדה חובה' : 'סכום: שדה חובה')); return; }
        await busy($('button[type=submit]', form), form, async () => {
            await refresh(await api('payment', { body }));
            $('#payment-dlg').close();
            toast(t('נשמר'));
        });
    }

    /** דרישת תשלום: מסמנים, ושולחים הודעה עם הקישור האישי — למשלם, ולעו"ד כשנדרש אישורו. */
    async function sendRequest() {
        const p = current;
        let mailed = [];
        try { const r = await api('payment-request', { body: { id: p.id } }); mailed = (r.notified || []).map(n => n.name); await refresh(r); }
        catch (e) { formError($('#payment-dlg form'), e.message); return; }
        $('#payment-dlg').close();
        const st = p.stage_id ? stageOf(p.stage_id) : null;
        const client = contactOf(project.project.client_id), lawyer = contactOf(project.project.lawyer_id);
        const recipients = [];
        const waitLawyer = p.needs_lawyer && !p.lawyer;
        for (const [c, role] of [[client, 'client'], [lawyer, 'lawyer']]) {
            if (!c) continue;
            const url = await portalUrl(project.project.id, c.id);
            const key = role === 'lawyer' && waitLawyer ? 'payment_lawyer' : 'payment_request';
            const lang = c.lang || I18n.lang();
            const details = `${p.title}${st ? ` (${inLang(st.name, lang)})` : ''} — ${p.supplier_name}`
                + (p.due_date ? ', ' + t('עד {date}', { date: shortDate(p.due_date) }) : '')
                + (role === 'client' && waitLawyer ? ' ' + t('(התשלום — אחרי אישור עורך הדין)') : '');
            recipients.push({ contact: c, role, label: t(role === 'client' ? 'ללקוח' : 'לעו"ד'),
                // i18n-none-start — שמות המשתנים בנוסח
                text: renderMsg(key, { 'שם': c.name, 'פרויקט': project.project.name, 'פרטים': details, 'סכום': money(p.amount), 'קישור': url }, lang) });
                // i18n-none-end
        }
        // מי קודם: מי שצריך לפעול עכשיו
        recipients.sort((a, b) => (waitLawyer ? (a.role === 'lawyer' ? -1 : 1) : (a.role === (p.payer === 'lawyer' ? 'lawyer' : 'client') ? -1 : 1)));
        Send.open({ title: t('דרישת תשלום — {title}', { title: p.title }), recipients, subject: t('דרישת תשלום — {project}', { project: project.project.name }), key: 'payment_request', projectId: project.project.id, mailed,
            hint: t('בקישור האישי: הסכום, פרטי הבנק של הספק, ו"שילמתי" עם העלאת אסמכתא. כשמגיעה אסמכתא — תקבל מייל, ותאשר כאן שהכסף התקבל.') });
    }

    async function lawyerApprove() {
        await busy(null, $('#payment-dlg form'), async () => {
            await refresh(await api('payment-lawyer', { body: { id: current.id, note: $('#pay-lawyer-note').value } }));
            $('#payment-dlg').close();
            toast(t('אישור העו"ד נרשם'));
        });
    }

    async function review(proofId, ok) {
        const x = current.proofs.find(y => y.id === proofId);
        let received = null, note = '';
        if (ok) {
            received = prompt(t('כמה התקבל בפועל? (₪)'), String(x.amount));
            if (received === null) return;
        } else {
            note = prompt(t('למה לא התקבל? (יופיע בציר הזמן)'), '');
            if (note === null) return;
        }
        await busy(null, $('#payment-dlg form'), async () => {
            await refresh(await api('proof-review', { body: { id: proofId, ok, received, note } }));
            $('#payment-dlg').close();
            toast(t(ok ? 'נרשם שהתקבל' : 'נרשם שלא התקבל'));
        });
    }

    /** המנהל רושם תשלום שדווח לו (למשל בטלפון), עם אסמכתא אם יש — לתיקיית הלקוח. */
    async function manualProof() {
        const form = $('#payment-dlg form');
        const body = { id: current.id, amount: $('#mp-amount').value.trim(), paid_on: $('#mp-date').value, method: $('#mp-method').value, note: $('#mp-note').value };
        if (!body.amount) { formError(form, t('סכום: שדה חובה')); return; }
        await busy($('#mp-save'), form, async () => {
            if (mpFile) {
                const { folders } = await api('folders', { params: { project_id: project.project.id } });
                const folder = folders.find(f => f.kind === 'client' && f.parent_id === null) || folders.find(f => f.kind === 'inbox');
                const { file } = await Files.send(mpFile, project.project.id, folder.id);
                body.file_id = file.id;
            }
            await refresh(await api('payment-proof', { body }));
            $('#payment-dlg').close();
            toast(t('התשלום נרשם — אשר כשהכסף התקבל'));
        });
    }

    async function cancel() {
        const note = prompt(t('לבטל את אבן הדרך? היא תישאר בהיסטוריה.\nסיבה (לא חובה):'), '');
        if (note === null) return;
        await busy($('#payment-cancel'), $('#payment-dlg form'), async () => {
            await refresh(await api('payment-cancel', { body: { id: current.id, note } }));
            $('#payment-dlg').close();
            toast(t('בוטל'));
        });
    }

    return { show };
})();
