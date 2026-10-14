// אבני דרך לתשלום — שלב 7 (lib/payments.php). לשונית "תשלומים" בפרויקט.
//
// האפליקציה רק מתעדת: הלקוח משלם ישירות לספק (בעצמו או דרך העו"ד).
// מתוכנן → "📤 דרישת תשלום" (הודעה עם הקישור האישי) → אישור עו"ד (כשנדרש)
// → הלקוח / העו"ד מעלה אסמכתא בקישור → המנהל: "✅ התקבל" (גם חלקי) — ונסגר.
'use strict';

const Payments = (() => {
    let project = null, data = { payments: [], summary: {} }, bound = false, current = null, mpFile = null, invFile = null;
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
        form.elements.stage_id.addEventListener('change', () => {
            // "במסירה של" — עוקב אחרי היחידה של השלב (מתחם)
            // מקדמה — שם ברירת מחדל
            if (form.elements.stage_id.value === 'advance' && !form.elements.title.value.trim()) form.elements.title.value = t('מקדמה');
            const u = project.stages.find(s => s.id === Number(form.elements.stage_id.value))?.unit_id;
            if (u !== undefined) form.elements.unit_id.value = u ?? '';
            stageChanged(null); dueKindChanged(); calcHint();
        });
        $$('#payment-dlg [name=mode]').forEach(r => r.addEventListener('change', modeChanged));
        $$('#payment-dlg [name=due_kind]').forEach(r => r.addEventListener('change', dueKindChanged));
        $('#due-need-stage').addEventListener('click', () => $('#payment-dlg [name=stage_id]').focus());
        form.elements.percent.addEventListener('input', quoteHint);
        $('#pm-tabs').addEventListener('click', e => { const b = e.target.closest('[data-pm-tab]'); if (b) pmTab(b.dataset.pmTab); });
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
        $('#inv-file').addEventListener('change', e => { invFile = e.target.files[0] || null; $('#inv-file-name').textContent = invFile ? '📎 ' + invFile.name : ''; });
        // קובץ שכבר בפרויקט — במקום להעלות שוב ({id, name}; קובץ חדש — File)
        $('#mp-existing').addEventListener('click', async () => { const f = await Files.choose(project.project.id, ['proof', 'photo', 'other']); if (f) { mpFile = f; $('#mp-file-name').textContent = '📂 ' + f.name; } });
        $('#inv-existing').addEventListener('click', async () => { const f = await Files.choose(project.project.id, ['invoice', 'photo', 'other']); if (f) { invFile = f; $('#inv-file-name').textContent = '📂 ' + f.name; } });
        $('#inv-save').addEventListener('click', saveInvoice);
        form.elements.index_value.addEventListener('input', calcHint);
        form.elements.amount.addEventListener('input', calcHint);
        $('#payment-cancel').addEventListener('click', cancel);
    }

    async function show(p) {
        bind();
        project = p;
        Finance.show(p);
        try { data = await api('payments', { params: { project_id: project.project.id } }); }
        catch (e) { toast(e.message, true); return; }
        render();
        // מ"כספים" (כ3): אבן דרך שביקשו לפתוח
        if (pendingOpen && data.payments.some(p => p.id === pendingOpen)) {
            Finance.tab('milestones');
            await open(pendingOpen);
            // מחלון קובץ: "אסמכתא לאבן דרך" — הקובץ כבר בטופס הרישום, רק סכום ותאריך
            if (pendingExtra?.proof && !$('#manual-proof').hidden) {
                mpFile = pendingExtra.proof;
                $('#mp-file-name').textContent = '📂 ' + mpFile.name;
                $('#manual-proof').open = true;
            }
        }
        pendingOpen = null; pendingExtra = null;
    }

    let pendingOpen = null, pendingExtra = null;
    /** פתיחת אבן דרך אחרי שהלשונית תיטען. extra.proof — {id, name}: קובץ מהפרויקט כאסמכתא. */
    function openWhenReady(id, extra = null) {
        // כבר בכספים של הפרויקט — פותחים עכשיו
        if (project && data.payments.some(p => p.id === id) && location.hash.startsWith(`#/p/${project.project.id}/payments`)) { Finance.tab('milestones'); open(id); return; }
        pendingOpen = id; pendingExtra = extra;
    }

    /** אחרי פעולה: הרשימה, הפרויקט, והמצב הכספי (אגף הכספים). */
    async function refresh(d) {
        data = d;
        project = await Projects.reload();
        render();
        Finance.reload(project);
    }

    /** מחלוקה לתשלומים (finance.js) — רק הרשימה. */
    async function refreshList(d) {
        data = d;
        render();
    }

    /** "+ אבן דרך" מהמצב הכספי — עם השלב והספק. */
    function add(stageId, supplierId) {
        open(null, { stage_id: stageId, supplier_id: supplierId });
    }

    /** מתי משלמים — לשורה ולחלון. */
    function dueText(p, late) {
        if (p.due_kind === 'advance') return t('מקדמה — בתחילת העבודה');
        if (p.due_kind === 'handover') return p.unit_name ? t('במסירת {unit}', { unit: p.unit_name }) : t('במסירת הפרויקט');
        if (p.due_kind === 'stage_done') return t('בגמר השלב');
        if (p.due_kind === 'progress') return t('בהתקדמות {n}%', { n: p.due_pct });
        return p.due_date ? t(late ? 'באיחור · {date}' : 'עד {date}', { date: shortDate(p.due_date) }) : '';
    }

    function render() {
        const s = data.summary;
        // תקציר: כמה התקבל מתוך כמה, עם פס התקדמות; ממתינים לאישור ובאיחור — תגיות
        const pct = s.total ? Math.min(100, Math.round(s.received * 100 / s.total)) : 0;
        const tags = [s.waiting ? `<span class="chip">${esc(t('{n} לאישור קבלה', { n: s.waiting }))}</span>` : '',
            s.late ? `<span class="chip fin-late">${esc(t('{n} באיחור', { n: s.late }))}</span>` : ''].join('');
        $('#pay-sum').hidden = !data.payments.length;
        $('#pay-sum').innerHTML = `<div class="ps-line"><span class="muted">${esc(t('התקבל'))}</span> <b>${esc(money(s.received))}</b>${s.total ? ` <span class="muted">${esc(t('מתוך {total}', { total: money(s.total) }))}</span><span class="ps-pct">${pct}%</span>` : ''}</div>
            ${s.total ? `<span class="fm-track" role="progressbar" aria-label="${esc(t('התקבל'))}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><span style="inline-size:${pct}%"></span></span>` : ''}
            ${tags ? `<div class="chip-row">${tags}</div>` : ''}`;

        $('#fin-n-milestones').textContent = data.payments.length || '';
        const groups = new Map();
        for (const p of data.payments) {
            const k = p.stage_id || 0;
            if (!groups.has(k)) groups.set(k, []);
            groups.get(k).push(p);
        }
        const order = [...project.stages.map(s => s.id).filter(id => groups.has(id)), ...(groups.has(0) ? [0] : [])];
        $('#payment-list').innerHTML = order.map(k => {
            const st = k ? stageOf(k) : null;
            // בלי שלב — כשכולן מקדמות: "💰 מקדמות"
            const noStage = groups.get(k).every(x => x.due_kind === 'advance') ? t('💰 מקדמות') : t('בלי שלב');
            return `<section class="q-stage"><header class="q-head"><h2 class="one">${esc(st ? stageName(st) : noStage)}</h2>${st ? stageChip(st.status) : ''}</header>
                <ul class="list rows-list">${groups.get(k).map(row).join('')}</ul></section>`;
        }).join('') || `<p class="empty">${esc(t('עוד אין אבני דרך לתשלום. "+ אבן דרך" — למשל מקדמה של 30% מההצעה שאושרה.'))}</p>`;
    }

    function row(p) {
        const late = p.due_kind === 'date' && p.due_date && p.due_date < today() && !['received', 'cancelled'].includes(p.status);
        const l2 = [
            esc(p.supplier_name),
            p.supplier_request && p.status === 'planned' ? `📥 ${esc(t('בקשת הספק'))}` : `${ICON[p.status]} ${esc(p.label)}`,
            dueText(p, late) ? `<span class="${late ? 'late' : ''}">${esc(dueText(p, late))}</span>` : '',
            p.status === 'partial' ? esc(t('נשאר {amount}', { amount: money(p.balance) })) : '',
            p.kind === 'retention' ? '' : p.retention ? esc(t('לתשלום {amount} (עיכבון {r})', { amount: money(p.due_amount), r: money(p.retention) })) : '',
            p.received > 0 && !p.invoice ? `<span class="late">🧾 ${esc(t('חסרה חשבונית'))}</span>` : '',
        ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
        return `<li class="card q-row pay-${p.status}" data-payment="${p.id}" tabindex="0" role="button" aria-label="${esc(p.title)}, ${esc(money(p.amount))}, ${esc(p.label)}">
            <div class="body"><div class="l1"><span class="one">${esc(p.title)}</span><span class="amt">${moneyVat(vatOf(p.amount, p.vat_rate))}</span></div>
                <div class="l2">${l2}</div></div></li>`;
    }

    // ================= חלון =================

    async function open(id, preset = {}) {
        const dlg = $('#payment-dlg'), form = $('form', dlg), el = form.elements;
        formError(form, '');
        current = id ? data.payments.find(p => p.id === id) : null;
        const p = current || { stage_id: guessStage(), payer: defaultPayer(), needs_lawyer: hasLawyer(), due_kind: 'date', ...preset };
        $('#payment-dlg-title').textContent = current ? `${current.title} — ${money(current.amount)}` : t('אבן דרך לתשלום');
        // "💰 מקדמה" — בראש הרשימה, אחרי "בלי שלב": תשלום בתחילת העבודה, בלי שלב ובלי תאריך
        el.stage_id.innerHTML = options([{ value: 'advance', label: t('💰 מקדמה — בתחילת העבודה') }, ...project.stages.map(s => ({ value: s.id, label: stageName(s) }))],
            p.due_kind === 'advance' ? 'advance' : p.stage_id, t('בלי שלב'));
        el.title.value = p.title || '';
        el.amount.value = current ? String(current.amount) : '';
        el.percent.value = current?.percent ?? '';
        el.due_date.value = p.due_date || '';
        // מקדמה — אין לה כפתור ב"מתי משלמים" (היא נבחרת ברשימת השלבים); כל מועד לא מוכר — "בתאריך"
        ($(`#payment-dlg [name=due_kind][value=${p.due_kind || 'date'}]`) || $('#payment-dlg [name=due_kind][value=date]')).checked = true;
        // מתחם: "במסירה" — של איזו יחידה (ברירת מחדל: היחידה של השלב)
        const units = project.units || [];
        const stUnit = project.stages.find(s => s.id === Number(p.stage_id))?.unit_id ?? '';
        el.unit_id.innerHTML = options([{ value: '', label: units.length ? t('כל המתחם') : t('הפרויקט') }, ...units.map(u => ({ value: u.id, label: u.name }))], current ? (current.unit_id ?? '') : stUnit);
        el.due_pct.value = p.due_pct || 50;
        // הצמדה (כ4): המדד הקובע — כשהפרויקט צמוד
        const fs = project.finance || {};
        $('#pay-index-box').hidden = !fs.index_on;
        el.index_value.value = current?.index_value ?? '';
        el.notes.value = p.notes || '';
        $(`#payment-dlg [name=mode][value=${current?.percent ? 'percent' : 'amount'}]`).checked = true;
        $(`#payment-dlg [name=payer][value=${p.payer || 'client'}]`).checked = true;
        $('#payer-box').hidden = $('#payer-title').hidden = !hasLawyer();
        $('#pay-lawyer-box').hidden = !hasLawyer();
        el.needs_lawyer.checked = !!p.needs_lawyer;
        const locked = current && !['planned', 'requested'].includes(current.status) && !Dev.on();
        Dev.attach(dlg, 'payment', current?.id);
        [...form.elements].forEach(x => { if (x.name) x.disabled = !!locked; });
        $('button[type=submit]', form).hidden = !!locked;
        drawHead();
        await stageChanged(p.supplier_id || null);
        dueKindChanged();
        calcHint();
        renderActions();
        // אבן דרך קיימת: לשוניות — "תשלומים" (מה קרה ומה עכשיו) ו"פרטים" (הטופס). מתוכננת — קודם הפרטים
        $('#pm-tabs').hidden = !current;
        pmTab(current && current.status !== 'planned' ? 'money' : 'details');
        dlg.showModal();
    }

    /** כרטיס הסיכום בראש החלון: הסכום, המצב, למי · על מה · מתי, וכמה התקבל מתוך כמה. */
    function drawHead() {
        const p = current;
        $('#pm-head').hidden = !p;
        if (!p) return;
        const stage = p.stage_id ? project.stages.find(s => s.id === p.stage_id) : null;
        $('#pm-amt').textContent = money(p.amount); // הסכום של אבן הדרך — כמו בכותרת וברשימה
        $('#pm-status').textContent = `${ICON[p.status] || ''} ${p.label}`;
        // למי · על איזה שלב · מתי (מקדמה — "בתחילת העבודה" אומר הכול)
        $('#pm-sub').textContent = [p.supplier_name, p.due_kind === 'advance' ? '💰 ' + t('מקדמה — בתחילת העבודה') : stage ? '🧱 ' + stageName(stage) : t('בלי שלב'),
            p.due_kind === 'advance' ? '' : dueText(p)].filter(Boolean).join(' · ');
        const total = p.due_amount ?? p.amount, pct = total ? Math.min(100, Math.round(p.received * 100 / total)) : 0;
        $('#pm-progress').innerHTML = p.received ? `<div class="ps-line"><span class="muted">${esc(t('התקבל'))}</span> <b>${esc(money(p.received))}</b> <span class="muted">${esc(t('מתוך {total}', { total: money(total) }))}</span><span class="ps-pct">${pct}%</span></div>
            <span class="fm-track" role="progressbar" aria-label="${esc(t('התקבל'))}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><span style="inline-size:${pct}%"></span></span>` : '';
        $('#payment-state').innerHTML = (p.supplier_request ? `<span class="chip">📥 ${esc(t('הספק ביקש'))}</span>` : '')
            + (p.file_id ? `<a class="chip" href="api.php?action=file&id=${p.file_id}" target="_blank" rel="noopener">📄 ${esc(t('המסמך של הספק'))}</a>` : '')
            + (p.retention ? `<span class="chip">${esc(t('עיכבון {amount}', { amount: money(p.retention) }))}</span><span class="chip">${esc(t('לתשלום עכשיו {amount}', { amount: money(p.due_amount) }))}</span>` : '')
            + (p.lawyer ? `<span class="chip">⚖️ ${esc(t('{name} אישר', { name: p.lawyer.by }))}</span>` : '');
        $('#payment-state').hidden = !$('#payment-state').innerHTML;
        $('#pm-tab-n').textContent = p.proofs.length || '';
        $('#pm-no-proofs').hidden = !!p.proofs.length;
    }

    /** לשונית בחלון אבן הדרך: money — אסמכתאות, דיווח ידני, חשבונית; details — הטופס וביטול. */
    let pmTabNow = 'details';
    function pmTab(k) {
        pmTabNow = k;
        const existing = !!current;
        $('#payment-actions').hidden = !existing || k !== 'money';
        $('#pm-details').hidden = existing && k !== 'details';
        $$('#pm-tabs [data-pm-tab]').forEach(b => { b.classList.toggle('on', b.dataset.pmTab === k); b.setAttribute('aria-selected', String(b.dataset.pmTab === k)); });
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
        // מי שמקבל כסף בתיק — מוגדר בתיק: רק ספקי הפרויקט, ו"+ ספק שלא בפרויקט…" מגדיר אותו בצוות וחוזר
        await Projects.fillSuppliers(el.supplier_id, st?.trade_id || 0, value, teamOf(project), { projectOnly: true });
        el.supplier_id.onchange = () => {
            if (el.supplier_id.value !== '__add') return;
            el.supplier_id.value = value ? String(value) : '';
            Projects.openMember(0, { after: async id => { await Projects.fillSuppliers(el.supplier_id, st?.trade_id || 0, id, teamOf(project), { projectOnly: true }); el.supplier_id.value = String(id); } });
        };
        el.supplier_id.options[0].textContent = t('בחירת ספק');
        $('#pay-mode-box').hidden = !approvedQuote;
        if (!approvedQuote) $('#payment-dlg [name=mode][value=amount]').checked = true;
        modeChanged();
    }

    /** כמה משלמים בפועל: בלי העיכבון (הוא בשחרור), ועם הפרשי ההצמדה. */
    function calcHint() {
        const h = $('#pay-calc');
        const p = current;
        if (p?.kind === 'retention') { h.hidden = false; h.textContent = t('שחרור העיכבון — במסירת הפרויקט.'); return; }
        // מחושב ממה שבטופס עכשיו — כמו בשרת (lib/payments.php)
        const F = project.finance || {}, el = $('#payment-dlg form').elements;
        const amount = Number(String(el.amount.value).replace(/[,\s₪]/g, '')) || 0;
        const iv = Number(el.index_value.value) || 0;
        const ret = el.stage_id.value === 'advance' ? 0 : Math.round(amount * (F.retention_pct || 0)) / 100; // באגורות, כמו בשרת; מקדמה — בלי עיכבון
        const diff = F.index_on && F.index_base && iv ? Math.round((amount - ret) * 100 * (iv / F.index_base - 1)) / 100 : 0;
        const parts = [];
        if (ret) parts.push(t('עיכבון {amount} — נשאר עד המסירה', { amount: money(ret) }));
        if (diff) parts.push(t('הפרשי הצמדה {amount}', { amount: money(diff) }));
        if (amount && (ret || diff)) parts.push(t('לתשלום עכשיו: {amount}', { amount: money(amount - ret + diff) }));
        else if (!amount && (F.retention_pct || 0) > 0 && el.stage_id.value !== 'advance') parts.push(t('{n}% מהסכום יישאר כעיכבון עד המסירה', { n: F.retention_pct }));
        h.hidden = !parts.length;
        h.textContent = parts.join(' · ');
    }

    /** חשבונית מהספק — על תשלום שהתקבל. */
    async function saveInvoice() {
        const form = $('#payment-dlg form');
        const no = $('#inv-no').value.trim();
        if (!no && !invFile) { formError(form, t('חשבונית: מספר או קובץ')); return; }
        await busy($('#inv-save'), form, async () => {
            let fileId = null;
            if (invFile?.id) fileId = invFile.id;
            else if (invFile) {
                const { folders } = await api('folders', { params: { project_id: project.project.id } });
                const folder = folders.find(f => f.contact_id === current.supplier_id && f.parent_id === null) || folders.find(f => f.kind === 'inbox');
                fileId = (await Files.send(invFile, project.project.id, folder.id, undefined, { quiet: true, type: 'invoice' })).file.id;
            }
            await refresh(await api('payment-invoice', { body: { id: current.id, invoice_no: no, file_id: fileId } }));
            $('#payment-dlg').close();
            toast(t('החשבונית נשמרה'));
        });
    }

    /**
     * "בגמר השלב" / "בהתקדמות" — תלויים בשלב. בלי שלב הם לא נעולים (זה בלבל): בוחרים, ומופיעה הערה
     * "👆 בוחרים שלב", השדה מסומן והסמן עובר אליו.
     */
    function dueKindChanged(e) {
        const el = $('#payment-dlg form').elements;
        const hasStage = !!Number(el.stage_id.value);
        $$('#payment-dlg [name=due_kind]').forEach(r => { if (r.value !== 'date') r.disabled = el.title.disabled; });
        const kind = $('#payment-dlg [name=due_kind]:checked').value;
        // מקדמה: בלי "מתי משלמים" — היא בתחילת העבודה
        const adv = el.stage_id.value === 'advance';
        $('#due-kind-box').hidden = adv;
        $('#due-advance-hint').hidden = !adv;
        if (adv) { $('#due-date-box').hidden = $('#due-pct-box').hidden = $('#due-kind-hint').hidden = $('#due-unit-box').hidden = $('#due-need-stage').hidden = true; el.stage_id.classList.remove('need'); return; }
        const need = !['date', 'handover'].includes(kind) && !hasStage;
        $('#due-unit-box').hidden = kind !== 'handover' || !(project.units || []).length;
        $('#due-need-stage').hidden = !need;
        el.stage_id.classList.toggle('need', need);
        if (need && e?.target?.name === 'due_kind') { el.stage_id.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.stage_id.focus(); }
        $('#due-date-box').hidden = kind !== 'date';
        $('#due-pct-box').hidden = kind !== 'progress';
        $('#due-kind-hint').hidden = kind !== 'stage_done';
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
        h.textContent = t('ההצעה שאושרה: {name} — {amount}', { name: approvedQuote.supplier_name, amount: money(approvedQuote.amount) }) + ` (${vatLine(vatOf(approvedQuote.amount, approvedQuote.vat_rate))})` + (pctMode && pct ? ` · ${pct}% = ${money(Math.round(approvedQuote.amount * pct) / 100)}` : '');
    }

    function renderActions() {
        const p = current;
        $('#payment-actions').hidden = !p || pmTabNow !== 'money';
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
            <small>${esc(x.by_name)} (${esc(role[x.by_role] ? t(role[x.by_role]) : x.by_role)}) · ${esc(new Date(x.created_at).toLocaleString(I18n.locale(), { dateStyle: 'short', timeStyle: 'short' }))}${x.ref_no ? ' · #' + esc(x.ref_no) : ''}${x.note ? ' · ' + esc(x.note) : ''}${x.review_note ? ' · ' + esc(x.review_note) : ''}</small>
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
        $('#mp-ref').value = '';
        mpFile = null;
        $('#mp-file-name').textContent = '';
        $('#payment-cancel').hidden = p.status === 'cancelled' || p.received > 0 || p.kind === 'retention';
        // חשבונית (כ4) — אחרי שהתקבל כסף
        $('#invoice-box').hidden = !(p.received > 0);
        invFile = null; $('#inv-file-name').textContent = ''; $('#inv-no').value = p.invoice?.no || '';
        $('#invoice-state').innerHTML = p.invoice
            ? `🧾 ${esc(p.invoice.no ? t('התקבלה חשבונית {no}', { no: p.invoice.no }) : t('התקבלה חשבונית'))}${p.invoice.file_id ? ` · <a href="${fileUrl(p.invoice.file_id)}" target="_blank" rel="noopener">${esc(t('הקובץ'))}</a>` : ''}`
            : esc(t('עוד אין חשבונית — אפשר לרשום כאן, או שהספק יעלה בקישור שלו.'));
    }

    async function save() {
        const form = $('#payment-dlg form'), el = form.elements;
        const pct = $('#payment-dlg [name=mode]:checked').value === 'percent';
        const body = {
            id: current?.id, project_id: project.project.id, stage_id: Number(el.stage_id.value) || null,
            supplier_id: Number(el.supplier_id.value) || 0, title: el.title.value.trim(),
            amount: pct ? null : el.amount.value.trim(), percent: pct ? el.percent.value.trim() : '',
            due_kind: $('#payment-dlg [name=due_kind]:checked').value, due_pct: Number(el.due_pct.value) || null,
            due_date: $('#payment-dlg [name=due_kind]:checked').value === 'date' ? el.due_date.value : '', payer: $('#payment-dlg [name=payer]:checked').value,
            needs_lawyer: el.needs_lawyer.checked, notes: el.notes.value,
        };
        if (project.finance?.index_on) body.index_value = el.index_value.value.trim();
        if (!body.supplier_id) { formError(form, t('ספק: שדה חובה')); return; }
        if (!body.title) { formError(form, t('שם: שדה חובה (למשל "מקדמה")')); return; }
        if (el.stage_id.value === 'advance') Object.assign(body, { stage_id: null, due_kind: 'advance', due_date: '' });
        if (body.due_kind === 'handover' && (project.units || []).length) body.unit_id = Number(el.unit_id.value) || null;
        if (!['date', 'handover', 'advance'].includes(body.due_kind) && !body.stage_id) { formError(form, t('"בגמר השלב" או "בהתקדמות" — קודם בוחרים שלב (למעלה)')); el.stage_id.focus(); return; }
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
                + (dueText(p, false) ? ', ' + dueText(p, false) : '')
                + (role === 'client' && waitLawyer ? ' ' + t('(התשלום — אחרי אישור עורך הדין)') : '');
            recipients.push({ contact: c, role, label: t(role === 'client' ? 'ללקוח' : 'לעו"ד'),
                // i18n-none-start — שמות המשתנים בנוסח
                text: renderMsg(key, { 'שם': c.name, 'פרויקט': project.project.name, 'פרטים': details, 'סכום': money(p.due_amount), 'קישור': url }, lang) });
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
        const body = { id: current.id, amount: $('#mp-amount').value.trim(), paid_on: $('#mp-date').value, method: $('#mp-method').value, note: $('#mp-note').value, ref_no: $('#mp-ref').value.trim() };
        if (!body.amount) { formError(form, t('סכום: שדה חובה')); return; }
        await busy($('#mp-save'), form, async () => {
            if (mpFile?.id) body.file_id = mpFile.id;
            else if (mpFile) {
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

    /** מהתראה (finance.js) — פותח את אבן הדרך. */
    function openPayment(id) { if (data.payments.some(p => p.id === id)) open(id); }

    return { show, add, refreshList, openPayment, openWhenReady };
})();
