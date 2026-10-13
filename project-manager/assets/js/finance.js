// אגף הכספים — שלב כ1 (lib/finance.php). בראש לשונית "כספים" בפרויקט:
// המצב הכספי (תקציב ראשוני מול היום, סוכם, צריך היה עד היום, שולם, פער, יתרה, צפי) עם מע"מ בכל
// סכום; ומתחתיו — לפי שלב וספק: סוכם / עד היום / שולם / יתרה, שולם מול בוצע, וסימונים.
// "⚙️ הגדרות" — תקציב, שכר טרחה, עיכבון והצמדה. "✂️ חלוקה" — אבני דרך מההצעה שאושרה.
'use strict';

const Finance = (() => {
    let project = null, data = null, bound = false, split = null;
    const pct = n => `${Math.round(n)}%`;

    function bind() {
        if (bound) return;
        bound = true;
        $('#fin-sum').addEventListener('click', e => { if (e.target.closest('[data-fin-settings]')) openSettings(); });
        $('#fin-stages').addEventListener('click', e => {
            const b = e.target.closest('[data-split]');
            if (b) { openSplit(Number(b.dataset.split), Number(b.dataset.supplier)); return; }
            const m = e.target.closest('[data-milestone]');
            if (m) Payments.add(Number(m.dataset.milestone), Number(m.dataset.supplier));
        });
        const f = $('#fin-dlg form');
        f.addEventListener('submit', e => { e.preventDefault(); saveSettings(); });
        $$('#fin-dlg [name=fee_type]').forEach(r => r.addEventListener('change', feeChanged));
        f.elements.index_on.addEventListener('change', () => { $('#index-box').hidden = !f.elements.index_on.checked; });
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
                <button type="button" class="btn ghost sm" data-fin-settings>⚙️ ${esc(t('הגדרות'))}</button></header>
            <p class="fin-sentence">${sentence}${status ? ` — ${status}` : ''}</p>
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
        if (S.retention_pct) parts.push(esc(t('עיכבון {n}% ({amount})', { n: S.retention_pct, amount: money(T.retention.g) })));
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
        const fee = data.fee ? `<section class="fin-stage"><header class="q-head"><h3 class="one">💼 ${esc(t('שכר הטרחה — {name}', { name: data.fee.name }))}</h3></header>
            <ul class="list fin-rows"><li class="fin-row"><dl class="fin-cells">${cell(t('סוכם'), data.fee.amount)}</dl></li></ul></section>` : '';
        $('#fin-stages').innerHTML = html + fee;
    }

    function supplierRow(s, r) {
        const flags = r.flags.map(f => FLAG[f](r)).map(x => `<span class="chip ${x.cls}">${esc(x.text)}</span>`).join('');
        const act = [];
        if (r.flags.includes('uncovered') && !r.milestones && r.quote_id) act.push(`<button type="button" class="btn ghost sm" data-split="${s.stage_id}" data-supplier="${r.supplier_id}">✂️ ${esc(t('חלוקה לתשלומים'))}</button>`);
        else if (r.flags.includes('uncovered')) act.push(`<button type="button" class="btn ghost sm" data-milestone="${s.stage_id}" data-supplier="${r.supplier_id}">+ ${esc(t('אבן דרך'))}</button>`);
        return `<li class="fin-row">
            <div class="fin-row-head"><b class="one">${esc(r.name)}</b>${flags}</div>
            <dl class="fin-cells">
                ${cell(t('סוכם'), r.committed.g ? r.committed : r.scheduled)}${cell(t('היה צריך עד היום'), r.due)}${cell(t('שולם'), r.paid)}${cell(t('יתרה'), r.balance)}
            </dl>
            ${s.stage_id ? `<div class="fin-vs">${meter(r.paid_pct, t('שולם'), 'paid')}${meter(s.progress, t('בוצע'))}</div>` : ''}
            ${act.length ? `<div class="row-btns wrap">${act.join('')}</div>` : ''}
        </li>`;
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
            data = { settings: d.settings, totals: d.totals, stages: d.stages, fee: d.fee };
            render();
            await Payments.refreshList({ payments: d.payments, summary: d.summary, statuses: d.statuses, methods: d.methods });
            toast(t('נוצרו {n} אבני דרך', { n: split.rows.length }));
        });
    }

    return { show, reload };
})();
