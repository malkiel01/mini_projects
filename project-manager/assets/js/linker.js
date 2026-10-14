// אשף שיוך קבצים להתקשרות (ספק × מקצוע) — "של מי ← באיזה מקצוע ← מה זה ← לאן בדיוק".
// נפתח מחלון קובץ, מבחירה של כמה קבצים ברשימה, ומתיק ההתקשרות. כל צעד — אריחים; מה שכבר ידוע
// (מהתיקייה של הקובץ, או מהתיק) — מדולג. README: "התקשרות", שלב 2.
'use strict';

const Linker = (() => {
    // i18n-ok-start — מתורגם בתצוגה
    const KINDS = [
        ['quote', '🧾', 'הצעת מחיר', 'רישום ההצעה, עם הקובץ כטופס'],
        ['contract', '📝', 'חוזה', 'החוזה של הספק במקצוע — קיים או חדש'],
        ['change', '±', 'תוספת / חריגה', 'פירוט התוספת — נשלח ללקוח לאישור'],
        ['proof', '💳', 'אסמכתת תשלום', 'לאבן דרך של הספק — עם סכום ותאריך'],
        ['invoice', '🧾', 'חשבונית', 'לאבן דרך שהתקבל בה תשלום'],
        ['field', '📷', 'תיעוד מהשטח', 'דיווח מהשטח על העבודה של הספק'],
        ['doc', '📄', 'מסמך אחר', 'ביטוח, רישיון, אחריות, פרוטוקול…'],
    ];
    const MULTI = ['field', 'doc']; // לכמה קבצים יחד
    // i18n-ok-end

    let dlg = null, st = null;

    function dialog() {
        if (dlg) return dlg;
        dlg = document.createElement('dialog');
        dlg.id = 'link-dlg';
        dlg.setAttribute('aria-labelledby', 'link-title');
        dlg.innerHTML = `<form method="dialog" novalidate><header class="sheet-head">
                <button type="button" class="icon-btn" data-close aria-label="${esc(t('סגירה'))}">✕</button>
                <h2 id="link-title"></h2><span></span></header>
            <div class="sheet-body">
                <div class="link-path" id="link-path"></div>
                <h3 class="sec-title" id="link-q"></h3>
                <div id="link-step"></div>
                <p class="error" role="alert" hidden></p>
            </div></form>`;
        document.body.appendChild(dlg);
        dlg.querySelector('[data-close]').addEventListener('click', () => dlg.close());
        dlg.querySelector('#link-path').addEventListener('click', e => {
            const b = e.target.closest('[data-back]');
            if (!b) return;
            const k = b.dataset.back;
            if (k === 'supplier') { st.supplier = null; st.stage = undefined; st.kind = null; }
            if (k === 'stage') { st.stage = undefined; st.kind = null; }
            if (k === 'kind') st.kind = null;
            step();
        });
        return dlg;
    }

    /**
     * files: [{id, name}], ctx: {projectId, supplier?, stage?, onDone?}. stage: undefined — לשאול; null — כללי (כל המקצועות).
     */
    async function open(files, ctx) {
        let project, folders, payments, methods;
        try {
            [project, { folders }, { payments, methods }] = await Promise.all([api('project', { params: { id: ctx.projectId } }),
                api('folders', { params: { project_id: ctx.projectId } }), api('payments', { params: { project_id: ctx.projectId } })]);
        } catch (e) { toast(e.message, true); return; }
        // הספקים: מתיקיות הספקים בפרויקט, ומי שעובד בשלבים
        const sup = new Map();
        for (const f of folders) if (f.kind === 'contact' && f.parent_id === null && f.contact_id) sup.set(f.contact_id, f.name);
        for (const s of project.stages) { if (s.supplier_id) sup.set(s.supplier_id, s.supplier_name); for (const e of s.engaged || []) sup.set(e.supplier_id, e.name); }
        st = { files, ctx, project, payments, payMethods: methods, sup, supplier: ctx.supplier || null, stage: ctx.stage, kind: null };
        const d = dialog();
        $('#link-title', d).textContent = files.length === 1 ? t('שיוך — {name}', { name: files[0].name }) : t('שיוך {n} קבצים', { n: files.length });
        if (!d.open) d.showModal();
        step();
    }

    /** השלבים שהספק עובד בהם — קודם; ואז השאר. */
    function stagesOf(supplierId) {
        const mine = st.project.stages.filter(s => s.supplier_id === supplierId || (s.engaged || []).some(e => e.supplier_id === supplierId)
            || st.payments.some(p => p.supplier_id === supplierId && p.stage_id === s.id));
        return { mine, rest: st.project.stages.filter(s => !mine.includes(s)) };
    }
    const stageLabel = s => t(s.name) + (s.unit_name ? ' · ' + s.unit_name : '');
    const stageById = id => st.project.stages.find(s => s.id === id);
    const tile = (attrs, ic, label, sub = '') => `<button type="button" class="tile" ${attrs}><span class="tile-ic" aria-hidden="true">${ic}</span>
        <span class="tile-tx"><span>${esc(label)}</span>${sub ? `<small>${esc(sub)}</small>` : ''}</span></button>`;

    function path() {
        const bits = [];
        if (st.supplier) bits.push(`<button type="button" class="chip" data-back="supplier">🧰 ${esc(st.sup.get(st.supplier) || '')}</button>`);
        if (st.stage !== undefined) bits.push(`<button type="button" class="chip" data-back="stage">🧱 ${esc(st.stage ? stageLabel(stageById(st.stage)) : t('כל המקצועות'))}</button>`);
        if (st.kind) { const k = KINDS.find(x => x[0] === st.kind); bits.push(`<button type="button" class="chip" data-back="kind">${k[1]} ${esc(t(k[2]))}</button>`); }
        $('#link-path').innerHTML = bits.join('<span class="link-sep" aria-hidden="true">‹</span>');
        $('#link-path').hidden = !bits.length;
    }

    function step() {
        formError($('#link-dlg form'), '');
        const box = $('#link-step'), q = $('#link-q');
        // 1. של מי?
        if (!st.supplier) {
            path();
            q.textContent = t('של איזה ספק?');
            box.innerHTML = st.sup.size ? `<div class="attach-grid">${[...st.sup].map(([id, name]) => tile(`data-sup="${id}"`, '🧰', name)).join('')}</div>`
                : `<p class="empty">${esc(t('אין עדיין ספקים בפרויקט — מוסיפים ספק לשלב או הצעת מחיר, ואז משייכים.'))}</p>`;
            box.onclick = e => { const b = e.target.closest('[data-sup]'); if (b) { st.supplier = Number(b.dataset.sup); step(); } };
            return;
        }
        // 2. באיזה מקצוע? (מקצוע יחיד — נבחר לבד)
        if (st.stage === undefined) {
            const { mine, rest } = stagesOf(st.supplier);
            if (mine.length === 1 && !st.ctx.askStage) { st.stage = mine[0].id; return step(); }
            path();
            q.textContent = t('באיזה מקצוע / שלב?');
            box.innerHTML = `<div class="attach-grid">${mine.map(s => tile(`data-stage="${s.id}"`, '🧱', stageLabel(s))).join('')}
                ${tile('data-stage="0"', '🗂️', t('כל המקצועות'), t('כללי לספק'))}</div>
                ${rest.length ? `<details class="link-more"><summary>${esc(t('שלב אחר בפרויקט'))}</summary><div class="attach-grid">${rest.map(s => tile(`data-stage="${s.id}"`, '▫️', stageLabel(s))).join('')}</div></details>` : ''}`;
            box.onclick = e => { const b = e.target.closest('[data-stage]'); if (b) { st.stage = Number(b.dataset.stage) || null; step(); } };
            return;
        }
        // 3. מה זה?
        if (!st.kind) {
            path();
            q.textContent = st.files.length === 1 ? t('מה הקובץ?') : t('מה הקבצים?');
            const ok = k => (st.files.length === 1 || MULTI.includes(k)) && !(k === 'change' && !st.stage);
            box.innerHTML = `<div class="attach-grid">${KINDS.filter(([k]) => ok(k)).map(([k, ic, label, sub]) => tile(`data-kind="${k}"`, ic, t(label), t(sub))).join('')}</div>
                ${st.files.length > 1 ? `<p class="hint">${esc(t('לכמה קבצים יחד — תיעוד מהשטח או מסמך אחר. הצעה, חוזה ותשלום — קובץ אחד בכל פעם.'))}</p>` : ''}`;
            box.onclick = e => { const b = e.target.closest('[data-kind]'); if (b) { st.kind = b.dataset.kind; step(); } };
            return;
        }
        path();
        detail();
    }

    // ---- 4. לאן בדיוק, ושמירה ----

    function detail() {
        const box = $('#link-step'), q = $('#link-q'), f = st.files[0], pid = st.ctx.projectId;
        const k = st.kind;
        if (k === 'quote') { done(); Quotes.fromFile(pid, { file_id: f.id, supplier_id: st.supplier, stage_id: st.stage || undefined }); return; }
        if (k === 'contract') { done(); Finance.openWhenReady({ supplier: st.supplier, stage: st.stage || null, file: f.id }, pid); return; }
        if (k === 'change') { done(); Finance.openWhenReady({ changeNew: { stage: st.stage, supplier: st.supplier, file: f } }, pid); return; }
        if (k === 'proof' || k === 'invoice') {
            const mine = st.payments.filter(p => p.supplier_id === st.supplier && p.status !== 'cancelled' && (!st.stage || p.stage_id === st.stage || !p.stage_id));
            const list = k === 'proof' ? mine.filter(p => ['planned', 'requested', 'partial', 'paid'].includes(p.status)) : mine.filter(p => p.received > 0 && !p.invoice);
            q.textContent = k === 'proof' ? t('לאיזו אבן דרך התשלום?') : t('לאיזו אבן דרך החשבונית?');
            box.innerHTML = list.length ? `<div class="link-list">${list.map(p => `<button type="button" class="use-row" data-pay="${p.id}">
                    <span class="use-ic" aria-hidden="true">${k === 'proof' ? '💳' : '🧾'}</span>
                    <span class="use-tx"><b>${esc(p.title)} · ${esc(money(p.due_amount ?? p.amount))}</b><small>${esc([p.stage_id ? stageLabel(stageById(p.stage_id) || { name: '' }) : t('בלי שלב'), p.label, p.received ? t('התקבל {amount}', { amount: money(p.received) }) : ''].filter(Boolean).join(' · '))}</small></span>
                    <span class="use-go" aria-hidden="true">‹</span></button>`).join('')}</div><div id="link-form"></div>`
                : `<p class="empty">${esc(k === 'proof' ? t('אין לספק במקצוע הזה אבן דרך פתוחה לתשלום.') : t('אין לספק במקצוע הזה אבן דרך שהתקבל בה תשלום ועוד אין לה חשבונית.'))}</p>`;
            box.onclick = e => { const b = e.target.closest('[data-pay]'); if (b) payForm(Number(b.dataset.pay)); };
            return;
        }
        if (k === 'field') {
            q.textContent = t('מה רואים בתיעוד?');
            box.innerHTML = `<label class="f wide"><span>${esc(t('תיאור (לא חובה)'))}</span><textarea id="link-text" rows="2" maxlength="4000" placeholder="${esc(t('למשל: הונחו צנרות במטבח'))}"></textarea></label>
                <label class="switch"><input type="checkbox" id="link-visible" checked><span>${esc(t('גלוי ללקוח בקישור האישי'))}</span></label>
                <div class="row-btns"><button type="button" class="btn primary" id="link-go">📷 ${esc(t('הוספה לתיעוד מהשטח'))}</button></div>`;
            $('#link-go').onclick = () => save('report', { project_id: pid, stage_id: st.stage, supplier_id: st.supplier, kind: 'update',
                text: $('#link-text').value.trim(), visible: $('#link-visible').checked, file_ids: st.files.map(x => x.id) }, t('נוסף לתיעוד מהשטח'));
            box.onclick = null;
            return;
        }
        if (k === 'doc') {
            q.textContent = t('מה המסמך?');
            box.innerHTML = `<label class="f wide"><span>${esc(t('הערה (לא חובה)'))}</span><input id="link-note" maxlength="500" placeholder="${esc(t('למשל: ביטוח צד ג׳ עד 12/2026'))}"></label>
                <div class="row-btns"><button type="button" class="btn primary" id="link-go">📄 ${esc(t('שיוך'))}</button></div>`;
            $('#link-go').onclick = () => save('file-ref', { project_id: pid, supplier_id: st.supplier, stage_id: st.stage, note: $('#link-note').value.trim(), file_ids: st.files.map(x => x.id) }, t('המסמך שויך'));
            box.onclick = null;
        }
    }

    /** אסמכתא: סכום (ברירת מחדל — היתרה), תאריך, אמצעי, מספר אסמכתא. חשבונית: מספר חשבונית. */
    function payForm(paymentId) {
        const p = st.payments.find(x => x.id === paymentId), box = $('#link-form');
        $$('#link-step [data-pay]').forEach(b => b.classList.toggle('on', Number(b.dataset.pay) === paymentId));
        if (st.kind === 'invoice') {
            box.innerHTML = `<div class="fields"><label class="f"><span>${esc(t('מספר חשבונית'))}</span><input id="link-inv" maxlength="40" dir="ltr"></label></div>
                <div class="row-btns"><button type="button" class="btn primary" id="link-go">🧾 ${esc(t('שמירת החשבונית'))}</button></div>`;
            $('#link-go').onclick = () => save('payment-invoice', { id: p.id, invoice_no: $('#link-inv').value.trim(), file_id: st.files[0].id }, t('החשבונית נשמרה'));
        } else {
            box.innerHTML = `<div class="fields">
                    <label class="f"><span>${esc(t('סכום (₪)'))}</span><input id="link-amount" inputmode="decimal" dir="ltr" value="${esc(String(p.balance ?? p.amount))}"></label>
                    <label class="f"><span>${esc(t('תאריך'))}</span><input id="link-date" type="date" value="${new Date().toISOString().slice(0, 10)}"></label>
                    <label class="f"><span>${esc(t('אמצעי'))}</span><select id="link-method">${options(Object.entries(st.payMethods || {}).map(([value, label]) => ({ value, label: t(label) })), 'transfer')}</select></label>
                    <label class="f"><span>${esc(t("מספר אסמכתא / צ'ק"))}</span><input id="link-ref" maxlength="40" dir="ltr" inputmode="numeric"></label>
                </div>
                <div class="row-btns"><button type="button" class="btn primary" id="link-go">💳 ${esc(t('רישום התשלום'))}</button></div>`;
            $('#link-go').onclick = () => save('payment-proof', { id: p.id, amount: $('#link-amount').value.trim(), paid_on: $('#link-date').value, method: $('#link-method').value,
                ref_no: $('#link-ref').value.trim(), note: '', file_id: st.files[0].id }, t('התשלום נרשם, עם האסמכתא'));
        }
        box.scrollIntoView({ block: 'nearest' });
    }

    async function save(action, body, msg) {
        const form = $('#link-dlg form');
        await busy($('#link-go'), form, async () => {
            await api(action, { body });
            done();
            toast(msg);
            st.ctx.onDone?.();
        });
    }

    function done() { dlg.close(); }

    return { open };
})();
