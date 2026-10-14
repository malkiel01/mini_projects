/**
 * הצעת תקציב לפי שלבים — "💰 כספים" ← "📐 תקציב" (lib/budget.php, README: "הצעת תקציב").
 * טיוטה: סכום לכל שלב, "בלתי צפוי" באחוזים, שמירה ושליחה לאישור. ממתינה: מה נשלח, רישום אישור ידני, ביטול.
 * נעולה: ראשוני · היום · סוכם · חריגה לכל שלב, "✏️ שינוי תקציב" (שוב לאישור), ויומן כל הגרסאות.
 */
const Budget = (() => {
    let project = null, data = null, editing = false, bound = false;
    const contactOf = id => project.team.find(m => m.id === id);

    async function show(p) {
        project = p;
        bind();
        try { data = await api('budget', { params: { project_id: p.project.id } }); } catch (e) { toast(e.message, true); return; }
        editing = false;
        render();
    }

    function bind() {
        if (bound) return;
        bound = true;
        const root = $('#fin-budget');
        root.addEventListener('click', e => {
            const b = e.target.closest('[data-b]');
            if (!b) return;
            ({ save, send, edit: () => { editing = true; render(); }, stop: () => { editing = false; render(); },
               cancel: cancelPending, manual: manualApprove, share: shareLink })[b.dataset.b]?.();
        });
        root.addEventListener('input', e => { if (e.target.matches('[data-amt], [name=cont]')) totals(); });
    }

    const canEdit = () => data.state === 'draft' || editing;
    const fmt = n => money(Math.round(n * 100) / 100);

    function render() {
        const B = data, editable = canEdit();
        const groups = new Map();
        for (const l of B.lines) {
            const k = l.unit_id ?? 0;
            if (!groups.has(k)) groups.set(k, { name: l.unit_name, lines: [] });
            groups.get(k).lines.push(l);
        }
        const units = B.units || [];
        const sharedName = units.length > 1 ? t('משותף לדירות — {split}', { split: units.map(u => `${u.name} ${u.pct}%`).join(' · ') }) : '';
        const head = { draft: `<span class="chip">✏️ ${esc(t('טיוטה'))}</span>`,
            pending: `<span class="chip fin-warn">⏳ ${esc(t('ממתינה לאישור {who}', { who: B.approver_name }))}</span>`,
            locked: `<span class="chip fin-ok">✅ ${esc(t('אושרה — נעולה'))}</span>` }[B.state];
        const intro = B.state === 'draft'
            ? t('רשום סכום משוער לכל שלב — הסה"כ הוא תקציב הפרויקט. אחרי אישור {who} ההצעה ננעלת, וכל שינוי נשלח שוב לאישור ונרשם ביומן.', { who: B.approver_name })
            : editing ? t('שנה את הסכומים שצריך, כתוב למה — והשינוי יישלח לאישור {who}. עד האישור התקציב לא משתנה.', { who: B.approver_name }) : '';
        const rows = [...groups.entries()].sort((a, b) => (a[0] === 0) - (b[0] === 0)).map(([k, g]) => `
            ${groups.size > 1 || units.length ? `<h4 class="bd-unit">${esc(k ? '🏢 ' + g.name : (sharedName || t('משותף')))}</h4>` : ''}
            <ul class="list bd-rows">${g.lines.map(l => line(l, editable)).join('')}</ul>`).join('');
        $('#fin-budget').innerHTML = `
            <section class="fin-card bd">
                <header class="fin-head"><h2>📐 ${esc(t('הצעת תקציב'))}</h2>${head}</header>
                ${intro ? `<p class="hint">${esc(intro)}</p>` : ''}
                ${B.pending ? pending(B.pending) : ''}
                ${rows || `<p class="empty">${esc(t('עוד אין שלבים בפרויקט.'))}</p>`}
                <dl class="bd-tot" id="bd-tot"></dl>
                ${editable && editing ? `<label class="f wide"><span>${esc(t('למה השינוי'))} *</span><textarea name="note" rows="2" maxlength="1000"></textarea></label>` : ''}
                <p class="error" role="alert" hidden></p>
                <div class="row-btns wrap">${buttons()}</div>
            </section>
            ${B.state === 'locked' && !editing ? `<p class="hint">${esc(t('ראשוני — מה שאושר בהצעה. היום — אחרי השינויים שאושרו. סוכם — הצעות ותוספות שאושרו בפועל. חריגה — סוכם מעל התקציב של השלב.'))}</p>` : ''}
            ${units.length > 1 ? `<section class="fin-card"><h3 class="sec-title">🏢 ${esc(t('לפי דירות'))}</h3><ul class="list bd-units">${units.map(u => `<li><span>${esc(u.name)} <small class="muted">${esc(t('{n}% מהמשותף', { n: u.pct }))}</small></span><b>${esc(money(u.budget))}</b></li>`).join('')}</ul>
                <p class="hint">${esc(t('כל דירה — השלבים שלה, וחלקה בשלבים המשותפים לפי האחוזים ב"⚙️ הגדרות" שבסיכום הכספים.'))}</p></section>` : ''}
            ${log()}`;
        totals();
    }

    function line(l, editable) {
        if (editable) {
            const v = l.today || '';
            return `<li class="bd-row"><span class="one">${esc(t(l.name))}</span>
                <input data-amt="${l.stage_id}" inputmode="decimal" dir="ltr" maxlength="12" value="${esc(String(v))}" placeholder="0" aria-label="${esc(t('סכום ל{stage}', { stage: t(l.name) }))}"></li>`;
        }
        const over = l.over > 0 ? `<span class="chip fin-late">${esc(t('חריגה {amount}', { amount: fmt(l.over) }))}</span>` : '';
        const changed = data.approved_once && Math.abs(l.today - l.initial) >= 0.01;
        return `<li class="bd-row bd-ro"><div class="bd-l1"><span class="one">${esc(t(l.name))}</span><b>${esc(fmt(l.today))}</b></div>
            <div class="bd-l2 muted">${[data.approved_once ? t('ראשוני {amount}', { amount: fmt(l.initial) }) : '',
                changed ? t('שינוי {amount}', { amount: (l.today > l.initial ? '+' : '−') + fmt(Math.abs(l.today - l.initial)) }) : '',
                t('סוכם {amount}', { amount: fmt(l.committed) })].filter(Boolean).map(esc).join('<i class="sep" aria-hidden="true">·</i>')}${over}</div></li>`;
    }

    /** הסכומים למטה — מחושבים מהשדות בזמן ההקלדה. */
    function totals() {
        const B = data, editable = canEdit();
        const vals = editable ? $$('#fin-budget [data-amt]').map(i => Number(i.value.replace(/[^\d.]/g, '')) || 0) : B.lines.map(l => l.today);
        const stages = vals.reduce((a, b) => a + b, 0);
        const contPct = editable ? Number($('#fin-budget [name=cont]')?.value ?? B.contingency_pct) || 0 : B.contingency_pct;
        const fee = B.fee_type === 'percent' ? stages * B.fee_value / 100 : B.fee_type === 'fixed' ? B.fee_value : 0;
        const cont = stages * contPct / 100;
        const total = stages + fee + cont;
        const contCell = editable ? `<input name="cont" inputmode="decimal" dir="ltr" maxlength="5" value="${esc(String(contPct || ''))}" placeholder="0" aria-label="${esc(t('בלתי צפוי (%)'))}"> %`
            : `${contPct}%`;
        const root = $('#bd-tot');
        if (!root) return;
        const keep = document.activeElement?.name === 'cont';
        root.innerHTML = `<div><dt>${esc(t('שלבים'))}</dt><dd>${esc(fmt(stages))}</dd></div>
            ${fee ? `<div><dt>${esc(B.fee_type === 'percent' ? t('שכר טרחה {n}%', { n: B.fee_value }) : t('שכר טרחה'))}</dt><dd>${esc(fmt(fee))}</dd></div>` : ''}
            <div><dt>${esc(t('בלתי צפוי'))} <span class="bd-cont">${contCell}</span></dt><dd>${esc(fmt(cont))}</dd></div>
            <div class="bd-sum"><dt>${esc(t('סה"כ תקציב'))}</dt><dd>${esc(fmt(total))}</dd></div>
            ${B.approved_once && Math.abs(total - B.initial_total) >= 1 ? `<div><dt>${esc(t('ראשוני (אושר)'))}</dt><dd>${esc(money(B.initial_total))}</dd></div>` : ''}
            ${B.state !== 'draft' ? `<div><dt>${esc(t('סוכם בפועל'))}</dt><dd>${esc(fmt(B.committed_total))}${B.over_total ? ` <span class="fin-late">${esc(t('חריגות {amount}', { amount: fmt(B.over_total) }))}</span>` : ''}</dd></div>` : ''}`;
        if (keep) { const i = $('#fin-budget [name=cont]'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }
    }

    function buttons() {
        const B = data, who = B.approver_name;
        if (B.state === 'draft') return `<button type="button" class="btn ghost" data-b="save">💾 ${esc(t('שמירה'))}</button>
            <button type="button" class="btn primary" data-b="send">📤 ${esc(t('שליחה לאישור {who}', { who }))}</button>`;
        if (B.state === 'pending') return '';
        if (editing) return `<button type="button" class="btn ghost" data-b="stop">${esc(t('ביטול'))}</button>
            <button type="button" class="btn primary" data-b="send">📤 ${esc(t('שליחת השינוי לאישור {who}', { who }))}</button>`;
        return `<button type="button" class="btn primary" data-b="edit">✏️ ${esc(t('שינוי תקציב'))}</button>`;
    }

    /** הגרסה שממתינה: מה השתנה, מי צריך לאשר, ופעולות. */
    function pending(v) {
        const need = v.status === 'client_approved' ? t('עו"ד') : data.approver === 'lawyer' ? t('עו"ד') : t('הלקוח');
        const lines = v.lines.map(l => `<li><span class="one">${esc(t(l.name))}</span><b>${v.kind === 'initial' ? esc(fmt(l.to)) : `${esc(fmt(l.from))} ← ${esc(fmt(l.to))}`}</b></li>`).join('');
        return `<div class="bd-pending">
            <p><b>${esc(v.kind === 'initial' ? t('הצעת התקציב נשלחה לאישור') : t('שינוי בתקציב נשלח לאישור'))}</b> · ${esc(t('ממתינה ל{who}', { who: need }))}</p>
            ${v.kind === 'change' ? `<ul class="list bd-chg">${lines}</ul>` : ''}
            <p>${esc(v.kind === 'initial' ? t('סה"כ {amount}', { amount: fmt(v.total_to) }) : t('סה"כ {from} ← {to}', { from: fmt(v.total_from), to: fmt(v.total_to) }))}${v.note ? ' · ' + esc(v.note) : ''}</p>
            <div class="row-btns wrap"><button type="button" class="btn primary sm" data-b="share">📤 ${esc(t('שליחת הקישור'))}</button>
                <button type="button" class="btn ghost sm" data-b="manual">✍️ ${esc(t('רישום אישור'))}</button>
                <button type="button" class="btn ghost sm" data-b="cancel">↩️ ${esc(t('ביטול השליחה'))}</button></div></div>`;
    }

    /** יומן התקציב — כל הגרסאות: מה, מתי, מי החליט ואיך. */
    function log() {
        const V = data.versions.filter(v => v.status !== 'cancelled');
        if (!V.length) return '';
        const how = { manual: t('נרשם על ידי מנהל הפרויקט'), click: t('בקישור'), signature: t('בחתימה בקישור') };
        return `<section class="fin-card"><h3 class="sec-title">📜 ${esc(t('יומן התקציב'))}</h3><ol class="ledger bd-log">${V.map(v => `<li>
            <div class="lg-top"><b class="lg-text">${esc(v.kind === 'initial' ? t('הצעת התקציב') : t('שינוי #{n}', { n: v.seq - 1 }))}</b>
                <span class="chip${v.status === 'approved' ? ' fin-ok' : v.status === 'rejected' ? ' fin-late' : ''}">${esc(v.status_label)}</span></div>
            <div class="lg-meta">${esc(v.kind === 'initial' ? fmt(v.total_to) : `${fmt(v.total_from)} ← ${fmt(v.total_to)}`)}${v.note ? ' · ' + esc(v.note) : ''}</div>
            ${v.kind === 'change' ? `<div class="lg-meta">${v.lines.map(l => esc(`${t(l.name)}: ${fmt(l.from)} ← ${fmt(l.to)}`)).join('<br>')}</div>` : ''}
            ${v.decisions.map(d => `<div class="lg-meta">${d.decision === 'approved' ? '✅' : '✖'} ${esc(d.by_name)} · ${esc(how[d.method] || '')} · ${esc(new Date(d.created_at).toLocaleDateString(I18n.locale()))}${d.note ? ' · ' + esc(d.note) : ''}</div>`).join('')}
        </li>`).join('')}</ol></section>`;
    }

    const lines = () => $$('#fin-budget [data-amt]').map(i => ({ stage_id: Number(i.dataset.amt), amount: i.value.replace(/[^\d.]/g, '') }));
    const err = msg => { const e = $('#fin-budget .error'); e.textContent = msg; e.hidden = !msg; };

    async function save() {
        err('');
        try {
            data = await api('budget-draft', { body: { project_id: project.project.id, lines: lines(), contingency_pct: $('#fin-budget [name=cont]')?.value || 0 } });
            render();
            toast(t('נשמר'));
            Finance.reload(project);
        } catch (e) { err(e.message); }
    }

    async function send() {
        err('');
        const body = { project_id: project.project.id };
        try {
            if (data.state === 'draft') {
                await api('budget-draft', { body: { ...body, lines: lines(), contingency_pct: $('#fin-budget [name=cont]')?.value || 0 } });
            } else {
                Object.assign(body, { lines: lines(), contingency_pct: $('#fin-budget [name=cont]')?.value || 0, note: $('#fin-budget [name=note]')?.value || '' });
            }
            data = await api('budget-send', { body });
            editing = false;
            render();
            Finance.reload(project);
            toast(t('נשלח לאישור — נשלח מייל עם הקישור'));
        } catch (e) { err(e.message); }
    }

    /** "📤 שליחת הקישור" — בוואטסאפ / במייל, כמו דרישת תשלום. */
    async function shareLink() {
        const v = data.pending;
        const lawyerNow = v.status === 'client_approved' || data.approver === 'lawyer';
        const c = contactOf(lawyerNow ? project.project.lawyer_id : project.project.client_id);
        if (!c) { toast(t('אין איש קשר לשליחה'), true); return; }
        const url = await portalUrl(project.project.id, c.id);
        const lang = c.lang || I18n.lang();
        // i18n-none-start — שמות המשתנים בנוסח
        const text = renderMsg('budget_request', { 'שם': c.name, 'פרויקט': project.project.name,
            'פרטים': v.kind === 'initial' ? t('הצעת התקציב לפי שלבים') : t('שינוי בתקציב: {note}', { note: v.note }),
            'סכום': money(v.total_to), 'קישור': url }, lang);
        // i18n-none-end
        Send.open({ title: t('הצעת התקציב — לאישור'), recipients: [{ contact: c, role: lawyerNow ? 'lawyer' : 'client', label: lawyerNow ? t('לעו"ד') : t('ללקוח'), text }],
            subject: t('לאישורך: תקציב הפרויקט — {project}', { project: project.project.name }), key: 'budget_request', projectId: project.project.id, mailed: true });
    }

    /** אישור שהתקבל בטלפון / בפגישה — המנהל רושם. */
    async function manualApprove() {
        const v = data.pending;
        const role = v.status === 'client_approved' || data.approver === 'lawyer' ? 'lawyer' : 'client';
        const note = prompt(t(role === 'lawyer' ? 'לרשום שהעו"ד אישר את התקציב? איך ומתי (לא חובה):' : 'לרשום שהלקוח אישר את התקציב? איך ומתי (לא חובה):'), '');
        if (note === null) return;
        try {
            data = await api('budget-decide', { body: { id: v.id, role, decision: 'approved', note } });
            render();
            Finance.reload(project);
            toast(t('נשמר'));
        } catch (e) { toast(e.message, true); }
    }

    async function cancelPending() {
        if (!confirm(t('לבטל את השליחה לאישור? אפשר יהיה לערוך ולשלוח שוב.'))) return;
        try { data = await api('budget-cancel', { body: { id: data.pending.id } }); render(); } catch (e) { toast(e.message, true); }
    }

    return { show };
})();
