// אגף הכספים — שלב כ1 (lib/finance.php). בראש לשונית "כספים" בפרויקט:
// המצב הכספי (תקציב ראשוני מול היום, סוכם, צריך היה עד היום, שולם, פער, יתרה, צפי) עם מע"מ בכל
// סכום; ומתחתיו — לפי שלב וספק: סוכם / עד היום / שולם / יתרה, שולם מול בוצע, וסימונים.
// "⚙️ הגדרות" — תקציב, שכר טרחה, עיכבון והצמדה. "✂️ חלוקה" — אבני דרך מההצעה שאושרה.
'use strict';

const Finance = (() => {
    let project = null, data = null, bound = false, split = null, coFile = null, co = null, exp = null, expFile = null, fee = null, ct = null;
    const pct = n => `${Math.round(n)}%`;

    function bind() {
        if (bound) return;
        bound = true;
        $('#fin-sum').addEventListener('click', e => {
            if (e.target.closest('[data-fin-settings]')) { openSettings(); return; }
            if (e.target.closest('[data-fin-report]')) { openReport(); return; }
            const a = e.target.closest('[data-alert-pay]');
            if (a) Payments.openPayment(Number(a.dataset.alertPay));
        });
        $('#fin-stages').addEventListener('click', e => {
            const b = e.target.closest('[data-split]');
            if (b) { openSplit(Number(b.dataset.split), Number(b.dataset.supplier)); return; }
            const m = e.target.closest('[data-milestone]');
            if (m) { Payments.add(Number(m.dataset.milestone), Number(m.dataset.supplier)); return; }
            const n = e.target.closest('[data-co-new]');
            if (n) { openNewChange(Number(n.dataset.coNew), Number(n.dataset.supplier)); return; }
            const c = e.target.closest('[data-co]');
            if (c) { openChange(Number(c.dataset.co)); return; }
            const k = e.target.closest('[data-contract]');
            if (k) { openContract(Number(k.dataset.contract)); return; }
            const l = e.target.closest('[data-ledger]');
            if (l) { ledger(project.project.id, Number(l.dataset.ledger)); return; }
            if (e.target.closest('[data-exp-new]')) { openExpense(null); return; }
            const x = e.target.closest('[data-exp]');
            if (x) { openExpense(Number(x.dataset.exp)); return; }
            if (e.target.closest('[data-fee-new]')) { openFee(null); return; }
            const fp = e.target.closest('[data-fee]');
            if (fp) openFee(Number(fp.dataset.fee));
        });
        $('#exp-dlg form').addEventListener('submit', e => { e.preventDefault(); saveExpense(); });
        $('#ct-dlg form').addEventListener('submit', e => { e.preventDefault(); saveContract(); });
        $('#ct-ms-add').addEventListener('click', () => { readMs(); ct.ms.push({ title: '', due_date: '', months: null, stage_ids: [], done_on: '' }); renderMs(); });
        $('#ct-ms').addEventListener('input', e => { if (e.target.name === 'months') ctMonths(e.target.closest('.ct-m')); });
        $('#ct-dlg [name=start_date]').addEventListener('change', () => $$('#ct-ms .ct-m').forEach(ctMonths));
        $('#ct-ms').addEventListener('click', e => { const b = e.target.closest('[data-ms-del]'); if (b) { readMs(); ct.ms.splice(Number(b.dataset.msDel), 1); renderMs(); } });
        $('#ct-dlg [name=file_id]').addEventListener('change', ctFileLink);
        $('#ct-pick').addEventListener('change', e => { ctUpload(e.target.files[0]); e.target.value = ''; });
        $('#exp-file').addEventListener('change', e => { expFile = e.target.files[0] || null; $('#exp-file-name').textContent = expFile ? '📎 ' + expFile.name : ''; });
        $('#exp-delete').addEventListener('click', deleteExpense);
        $('#fee-dlg form').addEventListener('submit', e => { e.preventDefault(); saveFee(); });
        $('#fee-recv').addEventListener('click', () => receiveFee(true));
        $('#fee-unrecv').addEventListener('click', () => receiveFee(false));
        $('#fee-delete').addEventListener('click', deleteFee);
        const f = $('#fin-dlg form');
        f.addEventListener('submit', e => { e.preventDefault(); saveSettings(); });
        $$('#fin-dlg [name=fee_type]').forEach(r => r.addEventListener('change', feeChanged));
        f.elements.index_on.addEventListener('change', () => { $('#index-box').hidden = !f.elements.index_on.checked; });
        $('#co-dlg form').addEventListener('submit', e => { e.preventDefault(); sendChange(); });
        $('#co-file').addEventListener('change', e => { coFile = e.target.files[0] || null; $('#co-file-name').textContent = coFile ? '📎 ' + coFile.name : ''; });
        $('#co-actions').addEventListener('click', e => { const b = e.target.closest('[data-co-do]'); if (b) changeAction(b.dataset.coDo); });
        const s = $('#split-dlg form');
        s.addEventListener('submit', e => { e.preventDefault(); saveSplit(); });
        $('#split-add').addEventListener('click', () => { readSplit(); split.rows.push({ title: '', percent: 0, due_kind: 'stage_done', due_pct: 50, due_date: '' }); renderSplit(); });
        $('#split-presets').addEventListener('click', e => {
            const b = e.target.closest('[data-preset]');
            if (b) { split.rows = presets()[Number(b.dataset.preset)].rows.map(r => ({ ...r })); renderSplit(); }
        });
        $('#split-rows').addEventListener('input', () => { readSplit(); renderTotal(); });
        $('#split-rows').addEventListener('change', e => { if (e.target.matches('select')) { readSplit(); renderSplit(); } });
        $('#split-rows').addEventListener('click', e => {
            const b = e.target.closest('[data-del]');
            if (b) { readSplit(); split.rows.splice(Number(b.dataset.del), 1); renderSplit(); }
        });
    }

    async function show(p) {
        bind();
        project = p;
        try { data = await api('finance', { params: { project_id: p.project.id } }); }
        catch (e) { $('#fin-sum').innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }
        render();
    }

    /** אחרי שינוי בתשלומים / בשלבים — מחדש. */
    async function reload(p = project) { if (p) await show(p); }

    // ================= המצב הכספי =================

    function render() {
        const T = data.totals, S = data.settings;
        const gap = Math.round(T.gap.g);
        const status = gap > 0 ? `<b class="fin-late">${esc(t('פיגור בתשלום {amount}', { amount: money(gap) }))}</b>`
            : gap < 0 ? `<b class="fin-ok">${esc(t('שולם מראש {amount}', { amount: money(-gap) }))}</b>`
            : T.committed.g ? `<b class="fin-ok">${esc(t('התשלומים בזמן'))}</b>` : '';
        const sentence = T.committed.g
            ? esc(t('סוכם {committed} · היה צריך להיות משולם עד היום {due} · שולם {paid}', { committed: money(T.committed.g), due: money(T.due.g), paid: money(T.paid.g) }))
            : esc(t('עוד אין הצעות שאושרו — כשתאושר הצעה, היא נכנסת לכאן כהתחייבות.'));
        const growth = Math.round(T.budget_growth.g);
        $('#fin-sum').innerHTML = `
            <header class="fin-head"><h2 id="fin-title">${esc(t('המצב הכספי'))}</h2>
                <span class="row-btns"><button type="button" class="btn ghost sm" data-fin-report>📄 ${esc(t('דוח'))}</button>
                <button type="button" class="btn ghost sm" data-fin-settings>⚙️ ${esc(t('הגדרות'))}</button></span></header>
            <p class="fin-sentence">${sentence}${status ? ` — ${status}` : ''}</p>
            ${alerts()}
            ${bar(T)}
            <dl class="fin-kpis">
                ${kpi(t('תקציב ראשוני'), T.budget_initial)}
                ${kpi(t('תקציב היום'), T.budget_today, growth > 0 ? `<span class="fin-late">${esc(t('גדל ב-{amount}', { amount: money(growth) }))}</span>` : '')}
                ${kpi(t('סוכם (התחייבויות)'), T.committed, T.over_budget ? `<span class="fin-late">${esc(t('מעל התקציב'))}</span>` : '')}
                ${kpi(t('שולם'), T.paid, T.committed.g ? esc(t('{n}% מהסוכם · בוצע {p}%', { n: T.paid_pct, p: T.progress })) : '')}
                ${kpi(t('יתרה לתשלום'), T.balance)}
                ${kpi(t('צפי סופי'), T.forecast, esc(t('סוכם, ותקציב השלבים שעוד אין להם הצעה')))}
            </dl>
            <p class="fin-terms">${terms(S, T)}</p>`;
        renderStages();
    }

    /** ההתראות של הפרויקט (כ2) — מהדחוף; תשלום — פותח את אבן הדרך. */
    function alerts() {
        const list = data.alerts || [];
        if (!list.length) return '';
        const ICON = { late: '⚠️', soon: '⏰', wait: '⏳', info: 'ℹ️' };
        return `<ul class="fin-alerts" aria-label="${esc(t('דורש טיפול'))}">${list.map(a => `<li class="fa-${a.level}">
            <span aria-hidden="true">${ICON[a.level]}</span><span class="fa-text">${esc(a.text)}</span>
            ${a.payment_id ? `<button type="button" class="btn ghost sm" data-alert-pay="${a.payment_id}">${esc(t('לטיפול'))}</button>` : ''}</li>`).join('')}</ul>`;
    }

    /** סרגל אחד: שולם · הגיע מועד ולא שולם · עוד לא הגיע מועד · נותר מהתקציב. */
    function bar(T) {
        const max = Math.max(T.budget_today.g, T.forecast.g, T.committed.g, 1);
        const paid = Math.min(T.paid.g, T.committed.g || T.paid.g);
        const lateAmt = Math.max(0, T.due.g - T.paid.g);
        const later = Math.max(0, T.committed.g - paid - lateAmt);
        const w = v => `${Math.max(0, v / max * 100).toFixed(2)}%`;
        const marker = T.budget_today.g ? `<i class="fin-mark" style="inset-inline-start:${w(T.budget_today.g)}" title="${esc(t('תקציב'))}"></i>` : '';
        return `<div class="fin-bar" role="img" aria-label="${esc(t('שולם {paid}, בפיגור {late}, בהמשך {later}', { paid: money(paid), late: money(lateAmt), later: money(later) }))}">
                <span class="fb-paid" style="inline-size:${w(paid)}"></span><span class="fb-late" style="inline-size:${w(lateAmt)}"></span><span class="fb-later" style="inline-size:${w(later)}"></span>${marker}
            </div>
            <ul class="fin-legend">
                <li><i class="fb-paid"></i>${esc(t('שולם'))}</li><li><i class="fb-late"></i>${esc(t('הגיע מועד ולא שולם'))}</li>
                <li><i class="fb-later"></i>${esc(t('בהמשך'))}</li>${T.budget_today.g ? `<li><i class="fin-mark-key"></i>${esc(t('תקציב'))}</li>` : ''}
            </ul>`;
    }

    function kpi(label, m, extra = '') {
        return `<div><dt>${esc(label)}</dt><dd>${moneyVat(m)}${extra ? `<small class="kpi-x">${extra}</small>` : ''}</dd></div>`;
    }

    function terms(S, T) {
        const parts = [];
        if (data.fee) parts.push(esc(data.fee.type === 'percent'
            ? t('שכר טרחה {n}% — {amount}', { n: data.fee.value, amount: money(data.fee.amount.g) })
            : t('שכר טרחה — {amount}', { amount: money(data.fee.amount.g) })));
        if (S.retention_pct) parts.push(esc(T.retention_held ? t('עיכבון {n}% — נעצרו {amount}', { n: S.retention_pct, amount: money(T.retention.g) }) : t('עיכבון {n}% ({amount})', { n: S.retention_pct, amount: money(T.retention.g) })));
        if (T.index?.g) parts.push(esc(t('הפרשי הצמדה {amount}', { amount: money(T.index.g) })));
        if (S.index_on) parts.push(esc(t('צמוד ל{index} · בסיס {month}: {base}', { index: S.index_name || t('מדד תשומות הבנייה'), month: S.index_base_month.split('-').reverse().join('/'), base: S.index_base })));
        parts.push(esc(t('מע"מ {n}%', { n: S.vat_rate })));
        return parts.join('<i class="sep" aria-hidden="true">·</i>');
    }

    // ================= לפי שלב וספק =================

    const FLAG = {
        behind: r => ({ cls: 'fin-late', text: t('פיגור {amount}', { amount: money(r.gap.g) }) }),
        overpaid: r => ({ cls: 'fin-late', text: t('שולם מעל הביצוע') }),
        uncovered: r => ({ cls: 'fin-warn', text: t('אבני הדרך מכסות {s} מתוך {c}', { s: money(r.scheduled.g), c: money(r.committed.g) }) }),
        overscheduled: r => ({ cls: 'fin-warn', text: t('אבני הדרך עוברות את ההצעה ({s} מול {c})', { s: money(r.scheduled.g), c: money(r.committed.g) }) }),
        no_quote: () => ({ cls: 'fin-warn', text: t('אבני דרך בלי הצעה שאושרה') }),
    };

    function renderStages() {
        const html = data.stages.map(s => {
            const rows = s.rows.map(r => supplierRow(s, r)).join('');
            const head = s.stage_id
                ? `<h3 class="one">${esc(t(s.name))}</h3>${stageChip(s.status)}`
                : `<h3 class="one">${esc(t('בלי שלב'))}</h3>`;
            const budget = s.budget.g ? `<p class="fin-stage-budget">${esc(t('תקציב לשלב {amount}', { amount: money(s.budget.g) }))}${s.over_budget ? ` · <span class="fin-late">${esc(t('ההתחייבות מעל תקציב השלב'))}</span>` : ''}</p>` : '';
            return `<section class="fin-stage"><header class="q-head">${head}</header>
                ${s.stage_id ? meter(s.progress, t('בוצע')) : ''}${budget}
                <ul class="list fin-rows">${rows || `<li class="muted fin-empty">${esc(t('עוד אין הצעה שאושרה'))}</li>`}</ul></section>`;
        }).join('');
        $('#fin-stages').innerHTML = html + expensesSection() + feeSection();
    }

    /** הוצאות ישירות (כ5) — מה שהלקוח קנה בעצמו. */
    function expensesSection() {
        const X = data.expenses || { items: [], total: { g: 0, n: 0 } };
        const items = X.items.map(x => `<li class="fin-row fin-item" data-exp="${x.id}" tabindex="0" role="button">
            <div class="fin-row-head"><b class="one">${esc(x.title)}</b>${x.by_role === 'client' ? `<span class="chip">${esc(t('רשם הלקוח'))}</span>` : ''}${x.file ? '<span aria-hidden="true">📎</span>' : ''}</div>
            <div class="fin-item-l2"><span class="muted">${esc(shortDateL(x.spent_on))}${x.stage ? ' · ' + esc(t(x.stage)) : ''}</span>${moneyVat(x.amount)}</div></li>`).join('');
        return `<section class="fin-stage" id="fin-expenses"><header class="q-head"><h3 class="one">🧾 ${esc(t('הוצאות ישירות'))}</h3>
                ${X.items.length ? `<span class="q-sum">${moneyVat(X.total)}</span>` : ''}</header>
            ${items ? `<ul class="list fin-rows">${items}</ul>` : `<p class="muted fin-empty">${esc(t('מה שהלקוח קנה בעצמו — חומרים, אגרות, היתרים. נכנס לסוכם ולשולם.'))}</p>`}
            <div class="row-btns wrap"><button type="button" class="btn ghost sm" data-exp-new>+ ${esc(t('הוצאה ישירה'))}</button></div></section>`;
    }

    /** שכר הטרחה — סוכם, שולם, יתרה, ואבני דרך משלו (כ5). */
    function feeSection() {
        const F = data.fee;
        if (!F) return '';
        const items = F.payments.map(x => `<li class="fin-row fin-item" data-fee="${x.id}" tabindex="0" role="button">
            <div class="fin-row-head"><b class="one">${esc(x.title)}</b>${x.received ? `<span class="chip fin-ok">✅ ${esc(t('התקבל'))}</span>` : x.late ? `<span class="chip fin-late">${esc(t('באיחור'))}</span>` : ''}</div>
            <div class="fin-item-l2"><span class="muted">${esc(x.received ? t('התקבל ב-{date}', { date: shortDateL(x.received_on) }) : x.due_date ? t('עד {date}', { date: shortDateL(x.due_date) }) : t('בלי תאריך'))}</span>${moneyVat(x.amount)}</div></li>`).join('');
        const gap = Math.round((F.amount.g - F.scheduled.g) * 100) / 100;
        return `<section class="fin-stage" id="fin-fee"><header class="q-head"><h3 class="one">💼 ${esc(t('שכר הטרחה — {name}', { name: F.name }))}</h3></header>
            <ul class="list fin-rows"><li class="fin-row"><dl class="fin-cells">${cell(t('סוכם'), F.amount)}${cell(t('היה צריך עד היום'), F.due)}${cell(t('שולם'), F.paid)}${cell(t('יתרה'), F.balance)}</dl>
                ${F.payments.length && Math.abs(gap) > 1 ? `<p class="fin-stage-budget fin-warn">${esc(t('אבני הדרך מכסות {s} מתוך {c}', { s: money(F.scheduled.g), c: money(F.amount.g) }))}</p>` : ''}</li>
            ${items}</ul>
            <div class="row-btns wrap"><button type="button" class="btn ghost sm" data-fee-new>+ ${esc(t('אבן דרך לשכר הטרחה'))}</button></div></section>`;
    }

    const shortDateL = ymd => (ymd ? new Date(ymd + 'T12:00:00').toLocaleDateString(I18n.locale()) : '');

    function supplierRow(s, r) {
        const flags = r.flags.map(f => FLAG[f](r)).map(x => `<span class="chip ${x.cls}">${esc(x.text)}</span>`).join('') + contractChip(r.contract);
        const act = [];
        if (r.flags.includes('uncovered') && !r.milestones && r.quote_id) act.push(`<button type="button" class="btn ghost sm" data-split="${s.stage_id}" data-supplier="${r.supplier_id}">✂️ ${esc(t('חלוקה לתשלומים'))}</button>`);
        else if (r.flags.includes('uncovered')) act.push(`<button type="button" class="btn ghost sm" data-milestone="${s.stage_id}" data-supplier="${r.supplier_id}">+ ${esc(t('אבן דרך'))}</button>`);
        if (s.stage_id) act.push(`<button type="button" class="btn ghost sm" data-co-new="${s.stage_id}" data-supplier="${r.supplier_id}">± ${esc(t('תוספת / חריגה'))}</button>`);
        act.push(`<button type="button" class="btn ghost sm" data-contract="${r.supplier_id}">📄 ${esc(t(r.contract ? 'חוזה' : '+ חוזה'))}</button>`);
        act.push(`<button type="button" class="btn ghost sm" data-ledger="${r.supplier_id}">📒 ${esc(t('כרטסת'))}</button>`);
        const changes = (r.changes || []).filter(c => c.status !== 'cancelled').map(c => `<button type="button" class="chip co-${c.status}" data-co="${c.id}">${c.amount > 0 ? '+' : '−'}${esc(money(Math.abs(c.amount)))} · ${esc(t(STATUS[c.status]))}</button>`).join('');
        return `<li class="fin-row">
            <div class="fin-row-head"><b class="one">${esc(r.name)}</b>${flags}</div>
            <dl class="fin-cells">
                ${cell(t('סוכם'), r.committed.g ? r.committed : r.scheduled)}${cell(t('היה צריך עד היום'), r.due)}${cell(t('שולם'), r.paid)}${cell(t('יתרה'), r.balance)}
            </dl>
            ${changes ? `<div class="chip-row co-row">${changes}</div>` : ''}
            ${r.index.g ? `<p class="fin-stage-budget">${esc(t('כולל הפרשי הצמדה {amount}', { amount: money(r.index.g) }))}</p>` : ''}
            ${s.stage_id ? `<div class="fin-vs">${meter(r.paid_pct, t('שולם'), 'paid')}${meter(s.progress, t('בוצע'))}</div>` : ''}
            ${act.length ? `<div class="row-btns wrap">${act.join('')}</div>` : ''}
        </li>`;
    }

    /** החוזה בשורת הספק: עד מתי, איחור וקנס, אבני דרך באיחור. */
    function contractChip(c) {
        if (!c) return '';
        if (c.completed_on) return `<span class="chip fin-ok">📄 ${esc(t('הסתיים {date}', { date: dateL(c.completed_on) }))}${c.penalty ? ' · ' + esc(t('קנס {amount}', { amount: money(c.penalty) })) : ''}</span>`;
        if (c.delay_days > 0) return `<span class="chip fin-late">📄 ${esc(c.penalty ? t('איחור {n} ימים · קנס {amount}', { n: c.delay_days, amount: money(c.penalty) }) : t('איחור {n} ימים', { n: c.delay_days }))}</span>`;
        const ms = c.late_milestones ? `<span class="chip fin-late">${esc(t('אבני דרך באיחור: {n}', { n: c.late_milestones }))}</span>` : '';
        return (c.end_date ? `<span class="chip">📄 ${esc(t('סיום עד {date}', { date: dateL(c.end_date) }))}</span>` : '') + ms;
    }
    const dateL = ymd => (ymd ? new Date(ymd + 'T12:00:00').toLocaleDateString(I18n.locale()) : '');

    // i18n-ok-start — מתורגם בתצוגה
    const STATUS = { sent: 'ממתינה ללקוח', client_approved: 'הלקוח אישר — ממתינה לעו"ד', approved: 'אושרה', rejected: 'נדחתה', cancelled: 'בוטלה' };
    // i18n-ok-end

    // ================= תוספות וחריגות (כ4) =================

    function openNewChange(stageId, supplierId) {
        const s = data.stages.find(x => x.stage_id === stageId), r = s.rows.find(x => x.supplier_id === supplierId);
        const f = $('#co-dlg form');
        f.reset(); formError(f, '');
        co = { new: true, stageId, supplierId };
        coFile = null; $('#co-file-name').textContent = '';
        $('#co-dlg-title').textContent = t('תוספת / חריגה');
        $('#co-for').textContent = t('{supplier} · {stage}', { supplier: r.name, stage: t(s.name) });
        $('#co-new').hidden = false; $('#co-view').hidden = true; $('#co-send').hidden = false;
        Dev.attach($('#co-dlg'), 'change', null);
        $('#co-dlg').showModal();
    }

    async function sendChange() {
        const f = $('#co-dlg form'), el = f.elements;
        const v = Number(String(el.amount.value).replace(/[^\d.]/g, ''));
        if (!v) { formError(f, t('סכום: שדה חובה')); return; }
        if (!el.reason.value.trim()) { formError(f, t('מה ולמה: שדה חובה')); return; }
        await busy($('#co-send'), f, async () => {
            let fileId = null;
            if (coFile) {
                const { folders } = await api('folders', { params: { project_id: project.project.id } });
                const folder = folders.find(x => x.contact_id === co.supplierId && x.parent_id === null) || folders.find(x => x.kind === 'inbox');
                fileId = (await Files.send(coFile, project.project.id, folder.id, undefined, { quiet: true })).file.id;
            }
            const d = await api('change', { body: { project_id: project.project.id, stage_id: co.stageId, supplier_id: co.supplierId,
                amount: v * Number($('#co-dlg [name=sign]:checked').value), reason: el.reason.value.trim(), file_id: fileId } });
            $('#co-dlg').close();
            take(d);
            toast(d.notified?.length ? t('נשלחה ללקוח לאישור') : t('נשמרה — הלקוח יראה אותה בקישור האישי'));
        });
    }

    async function openChange(id) {
        const f = $('#co-dlg form');
        formError(f, '');
        let list;
        try { ({ changes: list } = await api('changes', { params: { project_id: project.project.id } })); } catch (e) { toast(e.message, true); return; }
        const c = list.find(x => x.id === id);
        if (!c) return;
        co = { new: false, id, c };
        $('#co-dlg-title').textContent = c.amount > 0 ? t('תוספת') : t('הפחתה');
        $('#co-for').textContent = t('{supplier} · {stage}', { supplier: c.supplier, stage: t(c.stage) });
        $('#co-new').hidden = true; $('#co-view').hidden = false; $('#co-send').hidden = true;
        $('#co-summary').innerHTML = `<dl><dt>${esc(t('סכום'))}</dt><dd>${moneyVat(vatOf(c.amount, c.vat_rate))}</dd>
            <dt>${esc(t('מה ולמה'))}</dt><dd>${esc(c.reason)}</dd>
            <dt>${esc(t('סטטוס'))}</dt><dd>${esc(c.label)}</dd>
            ${c.file ? `<dt>${esc(t('קובץ'))}</dt><dd><a href="api.php?action=file&id=${c.file.id}" target="_blank" rel="noopener">📄 ${esc(c.file.name)}</a></dd>` : ''}</dl>`;
        const who = { client: 'הלקוח', lawyer: 'העו"ד' }; // i18n-ok
        $('#co-decisions').innerHTML = c.decisions.map(d => `<li class="${d.decision}"><span>${d.decision === 'approved' ? '✅' : '✖'} ${esc(t(who[d.role]))} · ${esc(d.by_name)}</span>
            <small>${esc(new Date(d.at).toLocaleString(I18n.locale(), { dateStyle: 'short', timeStyle: 'short' }))}${d.note ? ' · ' + esc(d.note) : ''}</small></li>`).join('');
        const b = [];
        if (c.status === 'sent') b.push(['client-approve', '✅ ' + t('הלקוח אישר (נרשם ידנית)'), 'primary'], ['client-reject', '✖ ' + t('הלקוח דחה'), 'ghost']);
        if (c.status === 'client_approved') b.push(['lawyer-approve', '✅ ' + t('העו"ד אישר (נרשם ידנית)'), 'primary'], ['lawyer-reject', '✖ ' + t('העו"ד דחה'), 'ghost']);
        if (['sent', 'client_approved'].includes(c.status)) b.push(['cancel', t('ביטול התוספת'), 'ghost danger']);
        $('#co-actions').innerHTML = b.map(([k, label, cls]) => `<button type="button" class="btn ${cls}" data-co-do="${k}">${esc(label)}</button>`).join('');
        Dev.attach($('#co-dlg'), 'change', id);
        $('#co-dlg').showModal();
    }

    async function changeAction(k) {
        const f = $('#co-dlg form');
        if (k === 'cancel') {
            if (!confirm(t('לבטל את התוספת? היא תישאר בהיסטוריה.'))) return;
            await busy(null, f, async () => { take(await api('change-cancel', { body: { id: co.id } })); $('#co-dlg').close(); });
            return;
        }
        const [role, act] = k.split('-');
        const note = prompt(t('הערה (לא חובה) — למשל "אישר בטלפון"'), '');
        if (note === null) return;
        await busy(null, f, async () => {
            take(await api('change-decide', { body: { id: co.id, role, decision: act === 'approve' ? 'approved' : 'rejected', note } }));
            $('#co-dlg').close();
            toast(t('נרשם'));
        });
    }

    /** תשובה מהשרת (מצב כספי) — מציירים מחדש. */
    function take(d) {
        data = { settings: d.settings, totals: d.totals, stages: d.stages, fee: d.fee, alerts: d.alerts, expenses: d.expenses };
        render();
    }

    // ================= הוצאות ישירות (כ5) =================

    function openExpense(id) {
        const x = id ? data.expenses.items.find(i => i.id === id) : null;
        const f = $('#exp-dlg form'), el = f.elements;
        f.reset(); formError(f, '');
        exp = { id, x };
        expFile = null;
        $('#exp-file-name').textContent = x?.file ? '📎 ' + x.file.name : '';
        $('#exp-dlg-title').textContent = x ? t('הוצאה ישירה') : t('הוצאה ישירה חדשה');
        el.title.value = x?.title || '';
        el.amount.value = x ? x.amount.g : '';
        el.spent_on.value = x?.spent_on || new Date().toISOString().slice(0, 10);
        el.notes.value = x?.notes || '';
        el.vat.checked = x ? x.vat_rate > 0 : true;
        el.stage_id.innerHTML = options(project.stages.map(s => ({ value: s.id, label: t(s.name) })), x?.stage_id || '', t('בלי שלב'));
        $('#exp-delete').hidden = !x;
        Dev.attach($('#exp-dlg'), 'expense', x?.id);
        $('#exp-dlg').showModal();
        el.title.focus();
    }

    async function saveExpense() {
        const f = $('#exp-dlg form'), el = f.elements;
        if (!el.title.value.trim()) { formError(f, t('מה נקנה: שדה חובה')); return; }
        await busy($('button[type=submit]', f), f, async () => {
            let fileId;
            if (expFile) {
                const { folders } = await api('folders', { params: { project_id: project.project.id } });
                const folder = folders.find(x => x.kind === 'client') || folders.find(x => x.kind === 'inbox');
                fileId = (await Files.send(expFile, project.project.id, folder.id, undefined, { quiet: true })).file.id;
            }
            const body = { id: exp.id || undefined, project_id: project.project.id, title: el.title.value.trim(), amount: el.amount.value.trim(),
                spent_on: el.spent_on.value, stage_id: el.stage_id.value || null, notes: el.notes.value.trim(), vat: el.vat.checked };
            if (fileId) body.file_id = fileId;
            take(await api('expense', { body }));
            $('#exp-dlg').close();
            toast(t('נשמר'));
        });
    }

    async function deleteExpense() {
        if (!confirm(t('למחוק את ההוצאה?'))) return;
        const f = $('#exp-dlg form');
        await busy($('#exp-delete'), f, async () => { take(await api('expense-delete', { body: { id: exp.id } })); $('#exp-dlg').close(); });
    }

    // ================= שכר הטרחה — אבני דרך (כ5) =================

    function openFee(id) {
        const x = id ? data.fee.payments.find(i => i.id === id) : null;
        const f = $('#fee-dlg form'), el = f.elements;
        f.reset(); formError(f, '');
        fee = { id, x };
        $('#fee-dlg-title').textContent = x ? x.title : t('שכר טרחה — אבן דרך');
        el.title.value = x?.title || '';
        el.amount.value = x ? x.amount.g : '';
        el.due_date.value = x?.due_date || '';
        $('#fee-recv-box').hidden = !x;
        if (x) {
            el.received_on.value = x.received_on || new Date().toISOString().slice(0, 10);
            el.received_amount.value = x.received_amount ?? x.amount.g;
            $('#fee-recv').hidden = x.received;
            $('#fee-unrecv').hidden = !x.received;
        }
        Dev.attach($('#fee-dlg'), 'fee_payment', x?.id);
        $('#fee-dlg').showModal();
    }

    async function saveFee() {
        const f = $('#fee-dlg form'), el = f.elements;
        await busy($('button[type=submit]', f), f, async () => {
            take(await api('fee-payment', { body: { id: fee.id || undefined, project_id: project.project.id, title: el.title.value.trim(), amount: el.amount.value.trim(), due_date: el.due_date.value } }));
            $('#fee-dlg').close();
            toast(t('נשמר'));
        });
    }

    async function receiveFee(yes) {
        const f = $('#fee-dlg form'), el = f.elements;
        await busy(null, f, async () => {
            take(await api('fee-payment-receive', { body: { id: fee.id, received: yes, received_on: el.received_on.value, amount: el.received_amount.value.trim() } }));
            $('#fee-dlg').close();
            toast(yes ? t('נרשם שהתקבל') : t('נשמר'));
        });
    }

    async function deleteFee() {
        if (!confirm(t('למחוק את אבן הדרך?'))) return;
        const f = $('#fee-dlg form');
        await busy(null, f, async () => { take(await api('fee-payment-delete', { body: { id: fee.id } })); $('#fee-dlg').close(); });
    }

    // ================= כרטסת ספק (כ5) =================

    // i18n-ok-start — מתורגם בתצוגה
    const LEDGER_ICON = { quote: '📝', change: '±', index: '📈', milestone: '🗓️', paid: '💳', invoice: '🧾' };
    // i18n-ok-end

    /** כרטסת: projectId — בפרויקט אחד; null — בכל הפרויקטים (מכרטיס הספק). */
    async function ledger(projectId, supplierId) {
        let d;
        try { d = await api('ledger', { params: { supplier_id: supplierId, project_id: projectId || undefined } }); } catch (e) { toast(e.message, true); return; }
        $('#ledger-dlg-title').textContent = t('כרטסת — {name}', { name: d.supplier.name });
        const one = L => `<dl class="fin-cells">${cell(t('סוכם'), L.committed)}${cell(t('שולם'), L.paid)}${cell(t('יתרה'), L.balance)}</dl>
            ${L.missing_invoices ? `<p class="fin-stage-budget fin-warn">${esc(t('תשלומים בלי חשבונית: {n}', { n: L.missing_invoices }))}</p>` : ''}
            <ol class="ledger">${L.entries.map(x => `<li class="lg-${x.kind}">
                <span class="lg-at">${esc(shortDateL(x.at))}</span>
                <span class="lg-text"><span aria-hidden="true">${LEDGER_ICON[x.kind]}</span> ${esc(x.text)}${x.kind === 'milestone' ? ` <small class="muted">· ${esc(money(x.amount))} · ${esc(x.label)}</small>` : ''}</span>
                <span class="lg-d">${x.delta.g ? esc((x.delta.g > 0 ? '+' : '−') + money(Math.abs(x.delta.g))) : ''}</span>
                <span class="lg-b">${x.delta.g ? esc((x.balance < 0 ? '−' : '') + money(Math.abs(x.balance))) : ''}</span></li>`).join('') || `<li class="muted">${esc(t('עוד אין תנועות'))}</li>`}</ol>`;
        const total = d.totals ? `<section class="fin-card"><h3 class="sec-title">${esc(t('בכל הפרויקטים'))}</h3><dl class="fin-cells">${cell(t('סוכם'), d.totals.committed)}${cell(t('שולם'), d.totals.paid)}${cell(t('יתרה'), d.totals.balance)}</dl>
            ${d.totals.missing_invoices ? `<p class="fin-stage-budget fin-warn">${esc(t('תשלומים בלי חשבונית: {n}', { n: d.totals.missing_invoices }))}</p>` : ''}</section>` : '';
        $('#ledger-body').innerHTML = total + (d.projects.map(P => `<section class="fin-stage">${d.totals ? `<h3 class="sec-title">${esc(P.name)}</h3>` : ''}${one(P)}</section>`).join('')
            || `<p class="muted">${esc(t('עוד אין לספק התחייבויות או תשלומים בפרויקטים שלך'))}</p>`);
        $('#ledger-dlg').showModal();
    }

    // ================= חוזה עם ספק — מועדים, אבני דרך וקנס =================

    async function openContract(supplierId) {
        let c;
        try { ({ contract: c } = await api('contract', { params: { project_id: project.project.id, supplier_id: supplierId } })); } catch (e) { toast(e.message, true); return; }
        const f = $('#ct-dlg form'), el = f.elements;
        f.reset(); formError(f, '');
        const name = data.stages.flatMap(s => s.rows).find(r => r.supplier_id === supplierId)?.name || '';
        ct = { supplierId, ms: (c?.milestones || []).map(m => ({ title: m.title, due_date: m.due_date, months: m.months, stage_ids: m.stage_ids, done_on: m.done_on,
            status: m.status, late: m.late_days, by_stage: m.by_stage, stages_done: m.stages_done })) };
        $('#ct-dlg-title').textContent = t('חוזה — {name}', { name });
        for (const k of ['signed_on', 'start_date', 'end_date', 'completed_on', 'notes']) el[k].value = c?.[k] || '';
        el.penalty_per_day.value = c?.penalty_per_day || '';
        el.grace_days.value = c?.grace_days ?? '';
        el.max_deviation_days.value = c?.max_deviation_days ?? 30;
        // תקציר: עד מתי, כמה נשאר / איחור, קנס
        const sum = $('#ct-sum');
        sum.hidden = !c || !c.end_date;
        if (c && c.end_date) {
            sum.innerHTML = c.completed_on ? `✅ ${esc(t('הסתיים ב-{date}', { date: dateL(c.completed_on) }))}${c.delay_days ? ' · ' + esc(t('באיחור של {n} ימים', { n: c.delay_days })) : ''}`
                : c.delay_days > 0 ? `<b class="fin-late">${esc(t('איחור של {n} ימים מהמועד בחוזה', { n: c.delay_days }))}</b>`
                : esc(t('נשארו {n} ימים עד מועד הסיום', { n: c.days_left }));
            if (c.penalty) sum.innerHTML += `<br><b class="fin-late">${esc(t('קנס מצטבר: {amount} ({n} ימים × {per})', { amount: money(c.penalty), n: c.penalty_days, per: money(c.penalty_per_day) }))}</b>`;
        }
        renderMs();
        await ctFiles(c?.file?.id || null);
        Dev.attach($('#ct-dlg'), 'contract', c?.id);
        $('#ct-dlg').showModal();
    }

    function renderMs() {
        const ST = { done: '✅', done_late: '✅', late: '⚠️', breach: '⛔', soon: '⏰', ok: '' };
        // אבן דרך = קבוצת שלבים (כמו בנספח "אבני דרך"): "סיום הריסה, קירות צד אחד ותשתיות אינסטלציה — עד 4 חודשים"
        $('#ct-ms').innerHTML = ct.ms.map((m, i) => `<li class="ct-m" data-i="${i}">
            <label class="f wide"><span>${ST[m.status] || ''} ${esc(t('אבן דרך'))}</span><input name="title" value="${esc(m.title)}" maxlength="200" placeholder="${esc(t('למשל: סיום הריסה, פינוי ותשתיות אינסטלציה'))}"></label>
            <label class="f"><span>${esc(t('עד X חודשים מההתחלה'))}</span><input name="months" inputmode="numeric" dir="ltr" maxlength="3" value="${m.months ?? ''}"></label>
            <label class="f"><span>${esc(t('או עד תאריך'))}</span><input name="due_date" type="date" value="${esc(m.due_date)}"></label>
            <button type="button" class="icon-btn" data-ms-del="${i}" aria-label="${esc(t('הסרה'))}">✕</button>
            <fieldset class="f wide ct-stages"><legend>${esc(t('השלבים שבה — הושגה כשכולם בוצעו'))}</legend>
                <div class="chips">${project.stages.map(s => `<label><input type="checkbox" value="${s.id}"${(m.stage_ids || []).includes(s.id) ? ' checked' : ''}><span>${esc(t(s.name))}</span></label>`).join('')}</div></fieldset>
            <label class="f"><span>${esc(t('הושגה ב (ידני)'))}</span><input name="done_on" type="date" value="${esc(m.done_on)}"></label>
            ${m.late && m.status !== 'done' ? `<p class="hint ${m.status === 'done_late' ? '' : 'fin-late'}">${esc(t('{n} ימים אחרי המועד', { n: m.late }))}${m.by_stage ? ' · ' + esc(t('הושגה כשכל השלבים בוצעו')) : ''}</p>` : ''}
            ${!m.done_on && m.stage_ids?.length && m.status && !['done', 'done_late'].includes(m.status) ? `<p class="hint">${esc(t('בוצעו {d} מתוך {n} שלבים', { d: m.stages_done || 0, n: m.stage_ids.length }))}</p>` : ''}
        </li>`).join('') || `<li class="muted">${esc(t('עוד אין אבני דרך — למשל מהנספח "אבני דרך" בחוזה.'))}</li>`;
        $$('#ct-ms .ct-m').forEach(ctMonths);
    }

    /** "עד X חודשים מההתחלה" — מחשב את התאריך (והשדה של התאריך ננעל). */
    function ctMonths(li) {
        const months = $('[name=months]', li).value.trim(), start = $('#ct-dlg [name=start_date]').value, due = $('[name=due_date]', li);
        due.disabled = months !== '';
        if (months !== '' && start) {
            const d = new Date(start + 'T12:00:00');
            d.setMonth(d.getMonth() + Number(months));
            due.value = d.toISOString().slice(0, 10);
        }
    }

    function readMs() {
        $$('#ct-ms .ct-m').forEach(li => {
            const m = ct.ms[Number(li.dataset.i)], q = n => $(`[name=${n}]`, li);
            m.title = q('title').value; m.due_date = q('due_date').value; m.done_on = q('done_on').value;
            m.months = q('months').value.trim() === '' ? null : Number(q('months').value);
            m.stage_ids = $$('.ct-stages input:checked', li).map(x => Number(x.value));
        });
    }

    async function ctFiles(value) {
        const sel = $('#ct-dlg [name=file_id]');
        let files = [];
        try { ({ files } = await api('files', { params: { project_id: project.project.id } })); } catch { /* ריק */ }
        const docs = files.filter(x => x.type === 'contract'), rest = files.filter(x => x.type !== 'contract');
        const opt = x => ({ value: x.id, label: x.name });
        sel.innerHTML = `<option value="">${esc(t('בלי מסמך'))}</option>`
            + (docs.length ? `<optgroup label="${esc(t('חוזים'))}">${options(docs.map(opt), value)}</optgroup>` : '')
            + (rest.length ? `<optgroup label="${esc(t('קבצים אחרים'))}">${options(rest.map(opt), value)}</optgroup>` : '');
        ctFileLink();
    }
    function ctFileLink() {
        const id = Number($('#ct-dlg [name=file_id]').value);
        $('#ct-file-open').hidden = !id;
        if (id) $('#ct-file-open').href = `api.php?action=file&id=${id}`;
    }
    async function ctUpload(file) {
        if (!file) return;
        const f = $('#ct-dlg form');
        await busy(null, f, async () => {
            const { folders } = await api('folders', { params: { project_id: project.project.id } });
            const folder = folders.find(x => x.contact_id === ct.supplierId && x.parent_id === null) || folders.find(x => x.kind === 'inbox');
            const saved = (await Files.send(file, project.project.id, folder.id, undefined, { quiet: true })).file;
            await ctFiles(saved.id);
        });
    }

    async function saveContract() {
        readMs();
        const f = $('#ct-dlg form'), el = f.elements;
        if (ct.ms.some(m => m.title.trim() && !m.due_date && m.months === null)) { formError(f, t('לכל אבן דרך — מועד')); return; }
        if (ct.ms.some(m => m.months !== null) && !el.start_date.value) { formError(f, t('"עד X חודשים" — צריך מועד התחלה')); return; }
        const body = { project_id: project.project.id, supplier_id: ct.supplierId, file_id: Number(el.file_id.value) || null,
            milestones: ct.ms.filter(m => m.title.trim()).map(m => ({ title: m.title.trim(), due_date: m.due_date, months: m.months, stage_ids: m.stage_ids, done_on: m.done_on })) };
        for (const k of ['signed_on', 'start_date', 'end_date', 'completed_on', 'notes', 'penalty_per_day', 'grace_days', 'max_deviation_days']) body[k] = el[k].value.trim();
        await busy($('button[type=submit]', f), f, async () => {
            take(await api('contract', { body }));
            $('#ct-dlg').close();
            toast(t('נשמר'));
        });
    }

    // ================= דוח כספי (כ5) =================

    async function openReport() {
        let payments = [], changes = [];
        try {
            [{ payments }, { changes }] = await Promise.all([api('payments', { params: { project_id: project.project.id } }), api('changes', { params: { project_id: project.project.id } })]);
        } catch (e) { toast(e.message, true); return; }
        const P = project.project;
        FinReport.open({ project: { name: P.name, address: P.address, client: (project.team || []).find(c => c.id === P.client_id)?.name || '' }, manager: state.user.display_name,
            finance: data, payments, changes });
    }

    function cell(label, m) { return `<div><dt>${esc(label)}</dt><dd>${moneyVat(m)}</dd></div>`; }

    function meter(v, label, kind = '') {
        const n = Math.max(0, Math.min(100, v));
        return `<div class="fin-meter ${kind}"><span class="fm-label">${esc(label)} ${pct(v)}</span>
            <span class="fm-track" role="progressbar" aria-label="${esc(label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${n}"><span style="inline-size:${n}%"></span></span></div>`;
    }

    // ================= הגדרות =================

    function openSettings() {
        const f = $('#fin-dlg form'), el = f.elements, S = data.settings;
        formError(f, '');
        el.budget_initial.value = S.budget_initial || '';
        el.budget.value = S.budget || '';
        $(`#fin-dlg [name=fee_type][value="${S.fee_type}"]`).checked = true;
        el.fee_value.value = S.fee_value || '';
        el.retention_pct.value = S.retention_pct;
        el.index_on.checked = S.index_on;
        el.index_name.value = S.index_name ? t(S.index_name) : '';
        el.index_base_month.value = S.index_base_month || '';
        el.index_base.value = S.index_base ?? '';
        $('#index-box').hidden = !S.index_on;
        feeChanged();
        $('#fin-dlg').showModal();
    }

    function feeChanged() {
        const type = $('#fin-dlg [name=fee_type]:checked').value;
        $('#fee-value-box').hidden = !type;
        $('#fee-value-label').textContent = type === 'percent' ? t('אחוז (%)') : t('סכום כולל מע"מ (₪)');
    }

    async function saveSettings() {
        const f = $('#fin-dlg form'), el = f.elements, S = data.settings;
        const indexName = el.index_name.value.trim();
        const body = {
            project_id: project.project.id, budget_initial: el.budget_initial.value.trim(), budget: el.budget.value.trim(),
            fee_type: $('#fin-dlg [name=fee_type]:checked').value, fee_value: el.fee_value.value.trim(),
            retention_pct: el.retention_pct.value.trim(), index_on: el.index_on.checked,
            index_name: S.index_name && indexName === t(S.index_name) ? S.index_name : indexName,
            index_base_month: el.index_base_month.value, index_base: el.index_base.value.trim(),
        };
        await busy($('button[type=submit]', f), f, async () => {
            data = await api('finance-settings', { body });
            $('#fin-dlg').close();
            render();
            toast(t('נשמר'));
        });
    }

    // ================= חלוקה לתשלומים =================

    /** חלוקות מוכנות: מקדמה בתאריך של היום, אמצע בהתקדמות, סוף בגמר השלב. */
    function presets() {
        const today = new Date().toISOString().slice(0, 10);
        const adv = p => ({ title: t('מקדמה'), percent: p, due_kind: 'date', due_pct: 50, due_date: today });
        const mid = (p, at) => ({ title: t('תשלום ביניים'), percent: p, due_kind: 'progress', due_pct: at, due_date: '' });
        const end = p => ({ title: t('תשלום סופי'), percent: p, due_kind: 'stage_done', due_pct: 50, due_date: '' });
        return [
            { label: '30 / 40 / 30', rows: [adv(30), mid(40, 50), end(30)] },
            { label: '50 / 50', rows: [adv(50), end(50)] },
            { label: '20 / 30 / 30 / 20', rows: [adv(20), mid(30, 33), mid(30, 66), end(20)] },
            { label: t('הכול בגמר'), rows: [end(100)] },
        ];
    }

    function openSplit(stageId, supplierId) {
        const s = data.stages.find(x => x.stage_id === stageId);
        const r = s.rows.find(x => x.supplier_id === supplierId);
        split = { stageId, amount: r.committed.g, rate: r.vat_rate, rows: presets()[0].rows.map(x => ({ ...x })) };
        $('#split-dlg-title').textContent = t('חלוקה לתשלומים — {stage}', { stage: t(s.name) });
        $('#split-quote').textContent = t('ההצעה שאושרה: {name} — {amount}', { name: r.name, amount: money(r.committed.g) });
        $('#split-presets').innerHTML = presets().map((p, i) => `<button type="button" class="chip" data-preset="${i}">${esc(p.label)}</button>`).join('');
        formError($('#split-dlg form'), '');
        renderSplit();
        $('#split-dlg').showModal();
    }

    function renderSplit() {
        const kinds = [['date', t('בתאריך')], ['stage_done', t('בגמר השלב')], ['progress', t('בהתקדמות')]];
        $('#split-rows').innerHTML = split.rows.map((r, i) => `<li class="split-row" data-i="${i}">
            <label class="f"><span>${esc(t('שם'))}</span><input name="title" value="${esc(r.title)}" maxlength="100"></label>
            <label class="f sm"><span>%</span><input name="percent" value="${r.percent || ''}" inputmode="decimal" dir="ltr" maxlength="6"></label>
            <label class="f"><span>${esc(t('מתי'))}</span><select name="due_kind">${options(kinds.map(([value, label]) => ({ value, label })), r.due_kind)}</select></label>
            ${r.due_kind === 'date' ? `<label class="f"><span>${esc(t('תאריך'))}</span><input name="due_date" type="date" value="${esc(r.due_date)}"></label>` : ''}
            ${r.due_kind === 'progress' ? `<label class="f sm"><span>${esc(t('ב-%'))}</span><input name="due_pct" type="number" min="1" max="100" value="${r.due_pct || 50}" dir="ltr"></label>` : ''}
            <button type="button" class="icon-btn" data-del="${i}" aria-label="${esc(t('הסרה'))}">✕</button>
        </li>`).join('');
        renderTotal();
    }

    function readSplit() {
        $$('#split-rows .split-row').forEach(li => {
            const r = split.rows[Number(li.dataset.i)], q = n => $(`[name=${n}]`, li);
            r.title = q('title').value;
            r.percent = Number(q('percent').value) || 0;
            r.due_kind = q('due_kind').value;
            if (q('due_date')) r.due_date = q('due_date').value;
            if (q('due_pct')) r.due_pct = Number(q('due_pct').value) || 0;
        });
    }

    function renderTotal() {
        const sum = split.rows.reduce((a, r) => a + (Number(r.percent) || 0), 0);
        const ok = Math.abs(sum - 100) < 0.01;
        $('#split-total').innerHTML = `<span class="${ok ? 'fin-ok' : 'fin-late'}">${esc(t('סה"כ {n}% — {amount}', { n: Math.round(sum * 100) / 100, amount: money(Math.round(split.amount * sum) / 100) }))}</span>`;
    }

    async function saveSplit() {
        readSplit();
        const f = $('#split-dlg form');
        if (split.rows.some(r => !r.title.trim())) { formError(f, t('לכל תשלום — שם')); return; }
        await busy($('button[type=submit]', f), f, async () => {
            const d = await api('payment-split', { body: { stage_id: split.stageId, parts: split.rows } });
            $('#split-dlg').close();
            data = { settings: d.settings, totals: d.totals, stages: d.stages, fee: d.fee, expenses: d.expenses };
            render();
            await Payments.refreshList({ payments: d.payments, summary: d.summary, statuses: d.statuses, methods: d.methods });
            toast(t('נוצרו {n} אבני דרך', { n: split.rows.length }));
        });
    }

    return { show, reload, ledger };
})();
