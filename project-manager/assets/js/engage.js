// תיק ההתקשרות — ספק × מקצוע: כל מה שקשור ל"אינסטלציה עם נתנאל פלאי" במקום אחד.
// למעלה — כמה סוכם, שולם, נשאר ובפיגור; ולשוניות: מסמכים (חוזה, הצעות, תוספות, מסמכים) ·
// תשלומים (אבני דרך עם אסמכתאות וחשבונית) · שטח (תיעוד) · קבצים ("ספק / מקצוע"). כל שורה — לרשומה עצמה.
// נפתח משורת ספק בכספים ומחלון השלב. README: "התקשרות", שלב 3. השרת: pm_engagement (lib/engage.php).
'use strict';

const Engage = (() => {
    let dlg = null, cur = null, tab = 'docs';
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;
    const dateL = s => (s ? new Date(s.length > 10 ? s : s + 'T12:00:00').toLocaleDateString(I18n.locale()) : '');

    function dialog() {
        if (dlg) return dlg;
        dlg = document.createElement('dialog');
        dlg.id = 'eng-dlg';
        dlg.setAttribute('aria-labelledby', 'eng-title');
        dlg.innerHTML = `<form method="dialog" novalidate><header class="sheet-head">
                <button type="button" class="icon-btn" data-close aria-label="${esc(t('סגירה'))}">✕</button>
                <h2 id="eng-title"></h2><a class="icon-btn" id="eng-call" aria-label="${esc(t('חיוג'))}" hidden>📞</a></header>
            <div class="sheet-body" id="eng-body"></div></form>`;
        document.body.appendChild(dlg);
        dlg.querySelector('[data-close]').addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', onClick);
        return dlg;
    }

    /** pid, supplierId, stageId (null — כל המקצועות של הספק). */
    async function open(pid, supplierId, stageId = null) {
        let d;
        try { d = await api('engagement', { params: { project_id: pid, supplier_id: supplierId, stage_id: stageId || undefined } }); } catch (e) { toast(e.message, true); return; }
        cur = { pid, supplierId, stageId, d };
        const box = dialog();
        $('#eng-title', box).textContent = d.stage ? t('{supplier} · {stage}', { supplier: d.supplier.name, stage: t(d.stage.name) }) : d.supplier.name;
        $('#eng-call', box).hidden = !d.supplier.phone;
        if (d.supplier.phone) $('#eng-call', box).href = 'tel:' + d.supplier.phone.replace(/[^\d+]/g, '');
        render();
        if (!box.open) box.showModal();
    }

    const money2 = m => money(m.g);
    function render() {
        const { d } = cur, S = d.summary;
        const cell = (label, m, cls = '') => `<div class="${cls}"><dt>${esc(label)}</dt><dd>${esc(money2(m))}</dd></div>`;
        // מקצועות של הספק — מעבר בין התיקים שלו
        const stages = d.stages.length > 1 ? `<div class="chip-row eng-stages">${[[0, t('כל המקצועות')], ...d.stages.map(s => [s.id, t(s.name)])].map(([id, label]) =>
            `<button type="button" class="chip${(cur.stageId || 0) === id ? ' on' : ''}" data-eng-stage="${id}" aria-pressed="${(cur.stageId || 0) === id}">${esc(label)}</button>`).join('')}</div>` : '';
        const n = { docs: (d.contract ? 1 : 0) + d.quotes.length + d.changes.length + d.docs.length, pay: d.milestones.length, field: d.field.length, files: d.files.length };
        const tabs = [['docs', '📄', t('מסמכים')], ['pay', '💳', t('תשלומים')], ['field', '📷', t('שטח')], ['files', '📁', t('קבצים')]];
        $('#eng-body').innerHTML = `${stages}
            <dl class="fin-cells eng-sum">${cell(t('סוכם'), S.committed)}${cell(t('שולם'), S.paid)}${cell(t('יתרה'), S.balance)}${S.gap.g >= 1 ? cell(t('בפיגור'), S.gap, 'eng-late') : ''}</dl>
            <div class="row-btns wrap eng-acts">
                <button type="button" class="btn primary sm" data-eng-add>🔗 ${esc(t('+ מסמך / קובץ'))}</button>
                <button type="button" class="btn ghost sm" data-eng-ledger>📒 ${esc(t('כרטסת'))}</button>
            </div>
            <div class="fin-tabs eng-tabs" role="tablist">${tabs.map(([k, ic, label]) => `<button type="button" role="tab" data-eng-tab="${k}" class="${tab === k ? 'on' : ''}" aria-selected="${tab === k}">${ic} ${esc(label)} <small>${n[k] || ''}</small></button>`).join('')}</div>
            <div id="eng-pane">${pane()}</div>`;
    }

    const row = (attrs, ic, title, sub, end = '') => `<li><button type="button" class="use-row" ${attrs}><span class="use-ic" aria-hidden="true">${ic}</span>
        <span class="use-tx"><b>${esc(title)}</b>${sub ? `<small>${esc(sub)}</small>` : ''}</span>${end}<span class="use-go" aria-hidden="true">‹</span></button></li>`;
    const empty = txt => `<p class="use-empty">${esc(txt)}</p>`;

    function pane() {
        const { d } = cur;
        if (tab === 'docs') {
            const c = d.contract;
            const items = [
                c ? row(`data-eng-contract="${c.id}"`, '📝', c.title ? t('חוזה — {title}', { title: c.title }) : t('חוזה'),
                    [c.end_date ? t('סיום עד {date}', { date: dateL(c.end_date) }) : '', c.delay_days > 0 ? t('איחור {n} ימים', { n: c.delay_days }) : '', c.file ? '📎 ' + c.file.name : t('בלי מסמך')].filter(Boolean).join(' · '))
                  : row('data-eng-contract="0"', '📝', t('+ חוזה'), t('מועדים, אבני דרך, קנס — ומסמך החוזה')),
                ...d.quotes.map(q => row(`data-eng-quote="${q.id}"`, '🧾', t('הצעת מחיר · {amount}', { amount: money(q.amount) }),
                    [cur.stageId ? '' : t(q.stage), q.label, q.file ? '📎 ' + q.file.name : t('בלי טופס')].filter(Boolean).join(' · '))),
                ...d.changes.map(x => row(`data-eng-change="${x.id}"`, '±', t('{sign}{amount} — {reason}', { sign: x.amount > 0 ? '+' : '−', amount: money(Math.abs(x.amount)), reason: x.reason }),
                    [x.label, x.file ? '📎 ' + x.file.name : ''].filter(Boolean).join(' · '))),
                ...d.docs.map(f => row(`data-eng-file="${f.id}"`, '📄', f.name, [f.note, f.stage_id ? '' : t('כל המקצועות'), dateL(f.at)].filter(Boolean).join(' · '))),
            ];
            return `<ul class="use-list">${items.join('')}</ul>`;
        }
        if (tab === 'pay') {
            if (!d.milestones.length) return empty(t('עוד אין אבני דרך לספק במקצוע הזה.'));
            return `<ul class="use-list">${d.milestones.map(p => {
                const proofs = p.proofs || [];
                const tags = [
                    p.received >= (p.due_amount ?? p.amount) ? '<span class="chip eng-ok">✔ ' + esc(t('שולם')) + '</span>'
                        : proofs.some(x => x.status === 'pending') ? '<span class="chip eng-warn">' + esc(t('אסמכתא ממתינה')) + '</span>'
                        : p.received ? '<span class="chip eng-warn">' + esc(t('שולם חלקית')) + '</span>' : '',
                    p.received > 0 ? (p.invoice ? '<span class="chip eng-ok">🧾 ' + esc(t('חשבונית')) + '</span>' : '<span class="chip eng-bad">' + esc(t('חסרה חשבונית')) + '</span>') : '',
                ].filter(Boolean).join('');
                return row(`data-eng-pay="${p.id}"`, '💳', `${p.title} · ${money(p.due_amount ?? p.amount)}`,
                    [p.stage_id ? '' : t('בלי שלב'), p.label, proofs.length ? t('{n} אסמכתאות', { n: proofs.length }) : ''].filter(Boolean).join(' · '),
                    tags ? `<span class="eng-tags">${tags}</span>` : '');
            }).join('')}</ul>`;
        }
        if (tab === 'field') {
            if (!d.field.length) return empty(t('עוד אין תיעוד מהשטח של הספק — "+ מסמך / קובץ" ← תיעוד מהשטח, או דיווח מהשטח עם הספק.'));
            return `<ul class="eng-field">${d.field.map(r => `<li class="eng-rep">
                <div class="eng-rep-h"><b>${esc(r.kind === 'done' ? t('✅ בוצע') : (r.supplier_id ? t('תיעוד') : t('תיעוד בשלב (בלי ספק)')))}</b><small>${esc(dateL(r.at))}${r.author ? ' · ' + esc(r.author) : ''}</small></div>
                ${r.text ? `<p>${esc(r.text)}</p>` : ''}
                ${r.media.length ? `<div class="eng-media">${r.media.map(m => `<button type="button" class="eng-thumb" data-eng-file="${m.id}" aria-label="${esc(m.name)}">${m.thumb
                    ? `<img src="${fileUrl(m.id, '&thumb=1')}" alt="" loading="lazy">` : `<span aria-hidden="true">${m.video ? '🎬' : '📄'}</span>`}</button>`).join('')}</div>` : ''}
            </li>`).join('')}</ul>`;
        }
        if (!d.files.length) return empty(t('התיקייה של הספק במקצוע ריקה.'));
        return `<ul class="use-list">${d.files.map(f => row(`data-eng-file="${f.id}"`, f.has_thumb ? `<img src="${fileUrl(f.id, '&thumb=1')}" alt="">` : '📄', f.name,
            [t(state.fileTypes[f.type] || ''), dateL(f.created_at)].filter(Boolean).join(' · '))).join('')}</ul>`;
    }

    function onClick(e) {
        const b = e.target.closest('[data-eng-tab],[data-eng-stage],[data-eng-add],[data-eng-ledger],[data-eng-contract],[data-eng-quote],[data-eng-change],[data-eng-pay],[data-eng-file]');
        if (!b) return;
        const { pid, supplierId, stageId } = cur;
        const ds = b.dataset;
        if (ds.engTab) { tab = ds.engTab; $$('#eng-body [data-eng-tab]').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', String(x === b)); }); $('#eng-pane').innerHTML = pane(); return; }
        if (ds.engStage !== undefined) { open(pid, supplierId, Number(ds.engStage) || null); return; }
        if (ds.engFile) { Viewer.open(Number(ds.engFile)); return; }
        if (ds.engAdd !== undefined) {
            // קובץ מהפרויקט (או העלאה בקבצים) — ואז האשף, כשהספק והמקצוע כבר ידועים
            Files.choose(pid, []).then(f => { if (f) Linker.open([f], { projectId: pid, supplier: supplierId, stage: stageId === null ? undefined : stageId, onDone: () => open(pid, supplierId, stageId) }); });
            return;
        }
        dlg.close();
        if (ds.engLedger !== undefined) Finance.openWhenReady({ ledger: supplierId }, pid);
        else if (ds.engContract !== undefined) Finance.openWhenReady({ supplier: supplierId, stage: stageId, contract: Number(ds.engContract) || 0 }, pid);
        else if (ds.engChange) Finance.openWhenReady({ change: Number(ds.engChange) }, pid);
        else if (ds.engPay) { Payments.openWhenReady(Number(ds.engPay)); if (!location.hash.startsWith(`#/p/${pid}/payments`)) location.hash = `#/p/${pid}/payments`; }
        else if (ds.engQuote) { Quotes.openWhenReady(Number(ds.engQuote)); location.hash = `#/p/${pid}/quotes`; }
    }

    return { open };
})();
