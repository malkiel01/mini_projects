/**
 * 📐 צפי — הצעת תקציב לפי שלבים, וצפי מול סוכם — "💰 כספים" ← "📐 צפי". לא נכנס לתזרים (PM-040) (lib/budget.php, README: "הצעת תקציב").
 * טיוטה: סכום לכל שלב, "בלתי צפוי" באחוזים, שמירה ושליחה לאישור. ממתינה: מה נשלח, רישום אישור ידני, ביטול.
 * נעולה: ראשוני · היום · סוכם · חריגה לכל שלב, "✏️ שינוי תקציב" (שוב לאישור), ויומן כל הגרסאות.
 * מע"מ: בורר "כולל מע"מ / לפני מע"מ" — איך רואים ועורכים. בשרת הכול נשמר כולל מע"מ (כמו שאר הכספים), וההמרה כאן.
 * שיוך: לכל שורה — בעל המקצוע והספק של השלב ("🏷️ שיוך" עורך אותם במקום). מזה — סינון (fin-filter.js) וחלוקה:
 * כמה עולה כל בעל מקצוע / ספק, וכמה מזה על כל דירה (שלב משותף — לפי אחוזי החלוקה).
 */
const Budget = (() => {
    let project = null, data = null, editing = false, bound = false, assigning = false, supplierContacts = [], ff = null, splitT = 0;
    const contactOf = id => project.team.find(m => m.id === id);

    async function show(p) {
        project = p;
        ff = null;
        bind();
        try { [data, supplierContacts] = await Promise.all([api('budget', { params: { project_id: p.project.id } }), contactsOf('supplier').catch(() => [])]); }
        catch (e) { toast(e.message, true); return; }
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
               assign: () => rerender(() => { assigning = !assigning; }),
               cancel: cancelPending, manual: manualApprove, share: shareLink })[b.dataset.b]?.();
        });
        // הסה"כ — מיד; טבלת החלוקה — אחרי הפסקה קצרה בהקלדה, לא בכל הקשה
        root.addEventListener('input', e => { if (e.target.matches('[data-amt], [name=cont]')) { totals(); if (e.target.matches('[data-amt]')) { clearTimeout(splitT); splitT = setTimeout(breakdown, 200); } } });
        root.addEventListener('change', e => {
            if (e.target.name === 'bd-vat') setVat(e.target.value);
            if (e.target.matches('[data-as]')) assign(e.target);
        });
        FinFilter.bind(root, 'bd', () => project.project.id, f => { ff = f; filterRows(); breakdown(); totals(); });
    }

    const canEdit = () => data.state === 'draft' || editing;
    const fmt = n => money(Math.round(n * 100) / 100);
    // כולל מע"מ ← מה שמוצג: לפני מע"מ — מחלקים ב-1.18
    const k = () => (data.vat_mode === 'net' ? 1 + data.vat_rate / 100 : 1);
    const fv = g => fmt(g / k());
    const r2 = n => Math.round(n * 100) / 100;
    /** הסכום שבשדה — רק ספרות ונקודה. */
    const digits = i => i.value.replace(/[^\d.]/g, '');
    const num = i => Number(digits(i)) || 0;

    /** בנייה מחדש בלי לאבד מה שהוקלד ועוד לא נשמר: סכומים (נשמרים כולל מע"מ ומומרים), בלתי צפוי והערה. */
    function rerender(before) {
        const typed = $$('#fin-budget [data-amt]').map(i => [i.dataset.amt, i.value.trim() === '' ? null : num(i) * k()]);
        const cont = $('#fin-budget [name=cont]')?.value, note = $('#fin-budget [name=note]')?.value;
        before?.();
        render();
        for (const [id, g] of typed) { const i = $(`#fin-budget [data-amt="${id}"]`); if (i) i.value = g === null ? '' : String(r2(g / k())); }
        if (cont !== undefined && $('#fin-budget [name=cont]')) $('#fin-budget [name=cont]').value = cont;
        if (note && $('#fin-budget [name=note]')) $('#fin-budget [name=note]').value = note;
        if (typed.length || cont !== undefined) { breakdown(); totals(); }
    }

    /** החלפת התצוגה. בעריכה — מה שכבר הוקלד נשאר באותו ערך (מומר), לא נמחק. */
    async function setVat(mode) {
        rerender(() => { data.vat_mode = mode; });
        api('budget-vat', { body: { project_id: project.project.id, mode } }).catch(e => toast(e.message, true));
    }

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
                <div class="seg bd-vat" role="radiogroup" aria-label="${esc(t('הסכומים'))}">
                    <label><input type="radio" name="bd-vat" value="net"${B.vat_mode === 'net' ? ' checked' : ''}><span>${esc(t('לפני מע"מ'))}</span></label>
                    <label><input type="radio" name="bd-vat" value="gross"${B.vat_mode !== 'net' ? ' checked' : ''}><span>${esc(t('כולל מע"מ'))}</span></label>
                </div>
                ${intro ? `<p class="hint">${esc(intro)}</p>` : ''}
                ${B.pending ? pending(B.pending) : ''}
                ${B.lines.length ? `<div id="bd-ff"></div><div class="row-btns wrap"><button type="button" class="btn ghost sm${assigning ? ' on' : ''}" data-b="assign" aria-pressed="${assigning}">🏷️ ${esc(assigning ? t('סיום השיוך') : t('שיוך לבעלי מקצוע וספקים'))}</button></div>` : ''}
                ${rows || `<p class="empty">${esc(t('עוד אין שלבים בפרויקט.'))}</p>`}
                <dl class="bd-tot" id="bd-tot"></dl>
                ${editable && editing ? `<label class="f wide"><span>${esc(t('למה השינוי'))} *</span><textarea name="note" rows="2" maxlength="1000"></textarea></label>` : ''}
                <p class="error" role="alert" hidden></p>
                <div class="row-btns wrap">${buttons()}</div>
            </section>
            ${B.state === 'locked' && !editing ? `<p class="hint">${esc(t('ראשוני — מה שאושר בהצעה. היום — אחרי השינויים שאושרו. סוכם — הצעות ותוספות שאושרו בפועל. חריגה — סוכם מעל התקציב של השלב.'))}</p>` : ''}
            <section class="fin-card" id="bd-vs"></section>
            <section class="fin-card" id="bd-split"></section>
            ${units.length > 1 ? `<section class="fin-card"><h3 class="sec-title">🏢 ${esc(t('לפי דירות'))}</h3><ul class="list bd-units">${units.map(u => `<li><span>${esc(u.name)} <small class="muted">${esc(t('{n}% מהמשותף', { n: u.pct }))}</small></span><b>${esc(fv(u.budget))}</b></li>`).join('')}</ul>
                <p class="hint">${esc(t('כל דירה — השלבים שלה, וחלקה בשלבים המשותפים לפי האחוזים ב"⚙️ הגדרות" שבסיכום הכספים.'))}</p></section>` : ''}
            ${log()}`;
        filterRows();
        breakdown();
        totals();
    }

    // ---- שיוך, סינון וחלוקה ----

    const stageOf = l => project.stages.find(s => s.id === l.stage_id);
    const tradesOf = l => FinFilter.tradesOf(stageOf(l), stageOf(l)?.supplier_id, supplierContacts);
    const supplierOf = l => stageOf(l)?.supplier_id || 0;
    const supplierName = id => (id ? project.stages.find(s => s.supplier_id === id)?.supplier_name || supplierContacts.find(c => c.id === id)?.name || '' : t('בלי ספק'));
    /** הבחירה נקראת מ-sessionStorage פעם אחת, ומתעדכנת בכל שינוי בבורר. */
    const filter = () => (ff ||= FinFilter.get('bd', project.project.id));
    const shownLines = (f = filter()) => data.lines.filter(l => FinFilter.match(f, tradesOf(l), supplierOf(l)));

    /** מתחת לשם השלב: בעל המקצוע והספק — או, במצב "🏷️ שיוך", שני שדות בחירה ששומרים מיד. */
    function tag(l) {
        const s = stageOf(l);
        if (!s) return '';
        if (assigning) {
            // ספקי הפרויקט קודם, ואחריהם — ספקים מהתחום של השלב, ואז כל השאר
            const own = new Set(project.team.filter(m => m.kind === 'supplier').map(m => m.id));
            const live = supplierContacts.filter(c => !c.archived || c.id === s.supplier_id);
            const grp = (label, list) => list.length ? `<optgroup label="${esc(label)}">${options(list.map(c => ({ value: c.id, label: c.name })), s.supplier_id)}</optgroup>` : '';
            const inProject = live.filter(c => own.has(c.id)), inTrade = live.filter(c => !own.has(c.id) && s.trade_id && c.trades.includes(s.trade_id));
            const rest = live.filter(c => !inProject.includes(c) && !inTrade.includes(c));
            return `<div class="bd-as"><select data-as="trade" data-stage="${s.id}" aria-label="${esc(t('בעל מקצוע'))}">${options(state.trades.map(x => ({ value: x.id, label: t(x.name) })), s.trade_id, t('בלי בעל מקצוע'))}</select>
                <select data-as="supplier" data-stage="${s.id}" aria-label="${esc(t('ספק'))}"><option value="">${esc(t('בלי ספק'))}</option>${grp(t('בפרויקט'), inProject)}${grp(t('בתחום'), inTrade)}${grp(t('כל הספקים'), rest)}</select></div>`;
        }
        const tr = tradesOf(l);
        const parts = [tr.length ? tr.map(FinFilter.tradeName).join(', ') : '', s.supplier_name || ''].filter(Boolean);
        return parts.length ? `<div class="bd-tag muted">🏷️ ${parts.map(esc).join('<i class="sep" aria-hidden="true">·</i>')}</div>` : '';
    }

    /** שיוך מהתקציב — אותו שמירה של חלון השלב (api stage), עם כל שאר השדות כמו שהם. */
    async function assign(sel) {
        const s = project.stages.find(x => x.id === Number(sel.dataset.stage));
        // השרת מחליף את השלב כולו — שולחים כל שדה שלו כמו שהוא (בלי budget: הסכום לא משתנה)
        const body = { id: s.id, name: s.name, trade_id: s.trade_id || '', supplier_id: s.supplier_id || '', status: s.status,
            needs_lawyer: s.needs_lawyer ? 1 : '', plan_start: s.plan_start, plan_end: s.plan_end, progress: s.progress || 0,
            ...('unit_id' in s ? { unit_id: s.unit_id || '' } : {}) };
        body[sel.dataset.as === 'trade' ? 'trade_id' : 'supplier_id'] = sel.value;
        sel.disabled = true;
        try {
            const d = await api('stage', { body });
            if (d?.stages) project.stages = d.stages; // התשובה של השרת — כולל סדר שלבים שהשתנה
            else if (sel.dataset.as === 'trade') s.trade_id = Number(sel.value) || null;
            else { s.supplier_id = Number(sel.value) || null; s.supplier_name = supplierContacts.find(c => c.id === s.supplier_id)?.name || null; }
            rerender();
            toast(d?.notice || t('נשמר'));
        } catch (e) {
            toast(e.message, true);
            sel.value = String((sel.dataset.as === 'trade' ? s.trade_id : s.supplier_id) || ''); // חוזר למה שבאמת שמור
            sel.disabled = false;
        }
    }

    /** הבורר מעל השורות, והסתרת השורות שלא עוברות (בעריכה — רק מוסתרות, הסכומים שלהן נשמרים). */
    function filterRows() {
        const box = $('#bd-ff');
        if (!box) return;
        const opts = {
            trades: FinFilter.uniq(data.lines.flatMap(l => { const tr = tradesOf(l); return tr.length ? tr : [0]; }), FinFilter.tradeName),
            suppliers: FinFilter.uniq(data.lines.map(supplierOf), supplierName),
        };
        const f = FinFilter.prune('bd', project.project.id, filter(), opts);
        box.innerHTML = FinFilter.html('bd', f, opts);
        const ok = new Set(shownLines(f).map(l => l.stage_id));
        $$('#fin-budget .bd-row').forEach(li => { li.hidden = !ok.has(Number(li.dataset.stage)); });
        $$('#fin-budget .bd-unit').forEach(h => { const ul = h.nextElementSibling; h.hidden = ul && !$$('.bd-row', ul).some(li => !li.hidden); });
    }

    /** הסכום של שורה כמו שמוצג עכשיו — בעריכה מהשדה, אחרת "היום". */
    const amountOf = l => {
        const i = $(`#fin-budget [data-amt="${l.stage_id}"]`);
        return i ? num(i) : l.today / k();
    };

    /** כמה עולה כל בעל מקצוע / ספק — וכמה מזה על כל דירה (שלב של דירה — שלה; משותף — לפי האחוזים). */
    function breakdown() {
        const root = $('#bd-split');
        if (!root) return;
        const f = filter(), units = data.units || [];
        vsCommitted(f);
        if (!data.lines.length) { root.hidden = true; return; }
        root.hidden = false;
        const list = FinFilter.group(shownLines(f),
            l => (f.by === 'supplier' ? supplierOf(l) : FinFilter.tradeKey(f, tradesOf(l))),
            (l, id) => ({ name: f.by === 'supplier' ? supplierName(id) : FinFilter.tradeName(id), total: 0, u: {} }),
            (g, l) => {
                const a = amountOf(l);
                g.total += a;
                if (units.length > 1) for (const u of units) g.u[u.id] = (g.u[u.id] || 0) + (l.unit_id ? (l.unit_id === u.id ? a : 0) : a * u.pct / 100);
            },
            (a, b) => b.total - a.total);
        const multi = units.length > 1;
        const td = v => `<td class="n">${v ? esc(fmt(v)) : '—'}</td>`;
        const sum = fn => list.reduce((a, g) => a + fn(g), 0);
        root.innerHTML = `<h3 class="sec-title">📊 ${esc(t('כמה עולה כל אחד'))}</h3>
            ${FinFilter.byHtml('bd', f)}
            <div class="fin-units"><div class="tbl-wrap"><table>
                <thead><tr><th>${esc(f.by === 'supplier' ? t('ספק') : t('בעל מקצוע'))}</th><th class="n">${esc(t('סה"כ'))}</th>${multi ? units.map(u => `<th class="n">${esc(u.name)}</th>`).join('') : ''}</tr></thead>
                <tbody>${list.map(g => `<tr><th>${esc(g.name)}</th>${td(g.total)}${multi ? units.map(u => td(g.u[u.id])).join('') : ''}</tr>`).join('')}</tbody>
                <tfoot><tr><th>${esc(FinFilter.active(f) ? t('סה"כ מסונן') : t('סה"כ שלבים'))}</th>${td(sum(g => g.total))}${multi ? units.map(u => td(sum(g => g.u[u.id] || 0))).join('') : ''}</tr></tfoot></table></div>
            <p class="hint">${esc([data.vat_mode === 'net' ? t('לפני מע"מ') : t('כולל מע"מ'), t('בלי שכר טרחה ו"בלתי צפוי"'),
                multi ? t('שלב משותף — לפי אחוזי החלוקה בין הדירות') : ''].filter(Boolean).join(' · '))}</p></div>`;
    }

    /**
     * ⚖️ צפי מול סוכם — לכל שלב: הצפי (התקציב), מה שסוכם בפועל, והפער. בלי תשלומים (אלה בתזרים).
     * הערכת סוף: כל מה שסוכם (גם הוצאות ושכר טרחה), ולשלבים שעוד לא סוכמו — הצפי שלהם.
     */
    function vsCommitted(f) {
        const root = $('#bd-vs');
        if (!root) return;
        if (!data.lines.length) { root.hidden = true; return; }
        root.hidden = false;
        const amt = new Map(data.lines.map(l => [l.stage_id, amountOf(l)])); // פעם אחת לכל שלב
        const rows = shownLines(f).map(l => ({ l, plan: amt.get(l.stage_id), done: l.committed / k() }));
        const notYet = data.lines.filter(l => !l.committed).reduce((a, l) => a + amt.get(l.stage_id), 0);
        // הסוכם — מהשרת, לפי התצוגה: "לפני מע״מ" לוקח את הנטו של כל פריט (הוצאה בלי מע״מ לא מחולקת ב-1.18)
        const estimate = (data.vat_mode === 'net' ? data.committed_total_net : data.committed_total) + notYet;
        const sum = key => rows.reduce((a, r) => a + r[key], 0);
        const gapCell = (plan, done) => {
            if (!done) return `<td class="n muted">${esc(t('עוד לא סוכם'))}</td>`;
            const g = plan - done;
            return `<td class="n${g < -0.5 ? ' fin-late' : ''}">${Math.abs(g) < 0.5 ? '0' : `<bdi dir="ltr">${esc((g < 0 ? '−' : '+') + fmt(Math.abs(g)))}</bdi>`}</td>`;
        };
        // הפער בסה"כ — רק על שלבים שכבר סוכמו (שלב שלא סוכם אינו "חיסכון")
        const agreed = rows.filter(r => r.done);
        const td = v => `<td class="n">${v ? esc(fmt(v)) : '—'}</td>`;
        root.innerHTML = `<h3 class="sec-title">⚖️ ${esc(t('צפי מול סוכם'))}</h3>
            <dl class="fin-kpis bd-vs-kpis">
                <div><dt>${esc(t('צפי לשלבים'))}</dt><dd>${esc(fmt(sum('plan')))}</dd></div>
                <div><dt>${esc(t('סוכם בשלבים'))}</dt><dd>${esc(fmt(sum('done')))}</dd></div>
                ${FinFilter.active(f) ? '' : `<div class="bd-est"><dt>${esc(t('הערכת סוף'))}</dt><dd>${esc(fmt(estimate))}<small class="kpi-x">${esc(t('סוכם + צפי לשלבים שעוד לא סוכמו'))}</small></dd></div>`}
            </dl>
            <div class="fin-units"><div class="tbl-wrap"><table>
                <thead><tr><th>${esc(t('שלב'))}</th><th class="n">${esc(t('צפי'))}</th><th class="n">${esc(t('סוכם'))}</th><th class="n">${esc(t('פער'))}</th></tr></thead>
                <tbody>${rows.map(r => `<tr><th>${esc(t(r.l.name))}${r.l.unit_name ? `<small>${esc(r.l.unit_name)}</small>` : ''}</th>${td(r.plan)}${td(r.done)}${gapCell(r.plan, r.done)}</tr>`).join('')}</tbody>
                <tfoot><tr><th>${esc(FinFilter.active(f) ? t('סה"כ מסונן') : t('סה"כ שלבים'))}</th>${td(sum('plan'))}${td(sum('done'))}${gapCell(agreed.reduce((a, r) => a + r.plan, 0), sum('done'))}</tr></tfoot></table></div>
            <p class="hint">${esc([data.vat_mode === 'net' ? t('לפני מע"מ') : t('כולל מע"מ'), t('פער — הצפי פחות מה שסוכם, רק בשלבים שסוכמו; במינוס — סוכם יותר מהצפי'),
                t('הצפי לא נכנס לתזרים: שם רק חוזים, תוספות, אבני דרך ותשלומים')].join(' · '))}</p></div>`;
    }

    function line(l, editable) {
        if (editable) {
            const v = l.today ? r2(l.today / k()) : '';
            return `<li class="bd-row bd-ro" data-stage="${l.stage_id}"><div class="bd-l1"><span class="one">${esc(t(l.name))}</span>
                <input data-amt="${l.stage_id}" inputmode="decimal" dir="ltr" maxlength="12" value="${esc(String(v))}" placeholder="0" aria-label="${esc(t('סכום ל{stage}', { stage: t(l.name) }))}"></div>${tag(l)}</li>`;
        }
        const over = l.over > 0 ? `<span class="chip fin-late">${esc(t('חריגה {amount}', { amount: fv(l.over) }))}</span>` : '';
        const changed = data.approved_once && Math.abs(l.today - l.initial) >= 0.01;
        return `<li class="bd-row bd-ro" data-stage="${l.stage_id}"><div class="bd-l1"><span class="one">${esc(t(l.name))}</span><b>${esc(fv(l.today))}</b></div>${tag(l)}
            <div class="bd-l2 muted">${[data.approved_once ? t('ראשוני {amount}', { amount: fv(l.initial) }) : '',
                changed ? t('שינוי {amount}', { amount: (l.today > l.initial ? '+' : '−') + fv(Math.abs(l.today - l.initial)) }) : '',
                t('סוכם {amount}', { amount: fv(l.committed) })].filter(Boolean).map(esc).join('<i class="sep" aria-hidden="true">·</i>')}${over}</div></li>`;
    }

    /** הסכומים למטה — מחושבים מהשדות בזמן ההקלדה. */
    function totals() {
        const B = data, editable = canEdit();
        // הכול כאן — בתצוגה שנבחרה (כולל / לפני מע"מ)
        const vals = editable ? $$('#fin-budget [data-amt]').map(num) : B.lines.map(l => l.today / k());
        const stages = vals.reduce((a, b) => a + b, 0);
        const contPct = editable ? Number($('#fin-budget [name=cont]')?.value ?? B.contingency_pct) || 0 : B.contingency_pct;
        const fee = B.fee_type === 'percent' ? stages * B.fee_value / 100 : B.fee_type === 'fixed' ? B.fee_value / k() : 0;
        const cont = stages * contPct / 100;
        const total = stages + fee + cont;
        const contCell = editable ? `<input name="cont" inputmode="decimal" dir="ltr" maxlength="5" value="${esc(String(contPct || ''))}" placeholder="0" aria-label="${esc(t('בלתי צפוי (%)'))}"> %`
            : `${contPct}%`;
        const root = $('#bd-tot');
        if (!root) return;
        const keep = document.activeElement?.name === 'cont';
        const f = filter();
        const part = FinFilter.active(f) ? shownLines(f).reduce((a, l) => a + amountOf(l), 0) : null;
        root.innerHTML = `<div><dt>${esc(t('שלבים'))}</dt><dd>${esc(fmt(stages))}</dd></div>
            ${part !== null ? `<div class="bd-part"><dt>${esc(t('מזה — מה שבסינון'))}</dt><dd>${esc(fmt(part))}</dd></div>` : ''}
            ${fee ? `<div><dt>${esc(B.fee_type === 'percent' ? t('שכר טרחה {n}%', { n: B.fee_value }) : t('שכר טרחה'))}</dt><dd>${esc(fmt(fee))}</dd></div>` : ''}
            <div><dt>${esc(t('בלתי צפוי'))} <span class="bd-cont">${contCell}</span></dt><dd>${esc(fmt(cont))}</dd></div>
            <div class="bd-sum"><dt>${esc(t('סה"כ תקציב'))} <small class="muted">${esc(B.vat_mode === 'net' ? t('לפני מע"מ') : t('כולל מע"מ'))}</small></dt><dd>${esc(fmt(total))}</dd></div>
            <div class="bd-other"><dt>${esc(B.vat_mode === 'net' ? t('כולל מע"מ {n}%', { n: B.vat_rate }) : t('לפני מע"מ'))}</dt><dd>${esc(fmt(B.vat_mode === 'net' ? total * (1 + B.vat_rate / 100) : total / (1 + B.vat_rate / 100)))}</dd></div>
            ${B.approved_once && Math.abs(total - B.initial_total / k()) >= 1 ? `<div><dt>${esc(t('ראשוני (אושר)'))}</dt><dd>${esc(fv(B.initial_total))}</dd></div>` : ''}
            ${B.state !== 'draft' ? `<div><dt>${esc(t('סוכם בפועל'))}</dt><dd>${esc(fv(B.committed_total))}${B.over_total ? ` <span class="fin-late">${esc(t('חריגות {amount}', { amount: fv(B.over_total) }))}</span>` : ''}</dd></div>` : ''}`;
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
        const lines = v.lines.map(l => `<li><span class="one">${esc(t(l.name))}</span><b>${v.kind === 'initial' ? esc(fv(l.to)) : `${esc(fv(l.from))} ← ${esc(fv(l.to))}`}</b></li>`).join('');
        return `<div class="bd-pending">
            <p><b>${esc(v.kind === 'initial' ? t('הצעת התקציב נשלחה לאישור') : t('שינוי בתקציב נשלח לאישור'))}</b> · ${esc(t('ממתינה ל{who}', { who: need }))}</p>
            ${v.kind === 'change' ? `<ul class="list bd-chg">${lines}</ul>` : ''}
            <p>${esc(v.kind === 'initial' ? t('סה"כ {amount}', { amount: fv(v.total_to) }) : t('סה"כ {from} ← {to}', { from: fv(v.total_from), to: fv(v.total_to) }))} · ${esc(data.vat_mode === 'net' ? t('לפני מע"מ') : t('כולל מע"מ'))}${v.note ? ' · ' + esc(v.note) : ''}</p>
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
            <div class="lg-meta">${esc(v.kind === 'initial' ? fv(v.total_to) : `${fv(v.total_from)} ← ${fv(v.total_to)}`)}${v.note ? ' · ' + esc(v.note) : ''}</div>
            ${v.kind === 'change' ? `<div class="lg-meta">${v.lines.map(l => esc(`${t(l.name)}: ${fv(l.from)} ← ${fv(l.to)}`)).join('<br>')}</div>` : ''}
            ${v.decisions.map(d => `<div class="lg-meta">${d.decision === 'approved' ? '✅' : '✖'} ${esc(d.by_name)} · ${esc(how[d.method] || '')} · ${esc(new Date(d.created_at).toLocaleDateString(I18n.locale()))}${d.note ? ' · ' + esc(d.note) : ''}</div>`).join('')}
        </li>`).join('')}</ol></section>`;
    }

    // לשרת — תמיד כולל מע"מ: מה שהוקלד לפני מע"מ מוכפל בשיעור
    const lines = () => $$('#fin-budget [data-amt]').map(i => {
        const v = digits(i);
        return { stage_id: Number(i.dataset.amt), amount: v === '' ? '' : String(r2(Number(v) * k())) };
    });
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
