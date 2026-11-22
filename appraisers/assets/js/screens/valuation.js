/*
 * פרוסה 16 — חלופות שווי וגישות שומה (docs/spec/16-valuation-methods.md §8).
 *   כרטיס תיק ← "עסקאות וחישובים" (wSection, בראש הלשונית): סקירה — מסקנות החלופות זו לצד זו, כל אחת עם שמה ועם המועד הקובע שלה;
 *       הנחות התיק; הגיליונות; רכישה ושיפוץ
 *   #/cases/<תיק>/alt/<חלופה> (viewAlt): מועד קובע, מצב מונח, הנחות, גישות, מסקנה
 *   #/cases/<תיק>/calcsheet/<גיליון> (viewCalcSheet): השורות; לכל שורת חישוב — הנוסחה במילים ובמספרים
 *   #/cases/<תיק>/renovation (viewRenovation): ארבעת הסוגים, כל אחד בנפרד; הצעות מחיר וגרסאותיהן
 * כל מספר מגיע מהשרת כמחרוזת ומוצג כפי שהוא — הדפדפן אינו מחשב דבר, ואינו ממלא מסקנה. "לא ידוע" מוצג במילים, לעולם לא כאפס.
 * כל טקסט שמשתמש הקליד עובר דרך esc(). ההרשאות נאכפות בשרת — כאן רק מסתירים כפתורים.
 */
'use strict';

const W = T.w;
/** אגורות (מחרוזת) ← "1,500,000 ₪"; null ← "לא ידוע". טקסט — מי שמציג עוטף ב-esc(). */
const wMoney = ag => ag === null || ag === undefined ? W.unknown : kAg(ag) + ' ' + W.nis;
/** אגורות ← מחרוזת עשרונית לשדה קלט ("150000050" ← "1500000.5") — פעולת מחרוזות. */
const wPlain = ag => { const d = String(ag).padStart(3, '0'), f = d.slice(-2).replace(/0+$/, ''); return d.slice(0, -2) + (f ? '.' + f : ''); };
const wChip = (text, cls = '') => `<span class="chip ${cls}">${esc(text)}</span>`;
function wToastError(x, reload) {
    toastError(x);
    if (x.code === 'val_changed' || x.code === 'comp_changed') reload();
}
const wAct = async (action, body, reload) => { try { const r = await api(action, { body }); reload(); return r; } catch (e) { wToastError(e, reload); return null; } };
/** קישור לנתון / למסמך שמקושר — HTML שכבר עבר esc. */
function wLinks(o, pid) {
    const out = [];
    if (o.file) out.push(`📎 <a href="#/cases/${pid}/file/${o.file.pid}">${esc(o.file.name + (o.file.deleted ? W.deletedMark : ''))}</a>`);
    if (o.fact) out.push(`🔗 ${esc(W.fact + ': ' + o.fact.label + (o.fact.deleted ? W.deletedMark : ''))}`);
    return out.join(' · ');
}
const wPick = (name, label, items, cur, text) => items.length || cur ? `<label class="f"><span>${label}</span><select name="${name}"><option value="">${W.linkNone}</option>${
    (cur && !items.some(i => i.pid === cur.pid) ? [{ pid: cur.pid, [text]: cur[text] || cur.label || cur.name }, ...items] : items).map(i => `<option value="${i.pid}"${cur && cur.pid === i.pid ? ' selected' : ''}>${esc(i[text])}</option>`).join('')}</select></label>` : '';
/** התוצאה של גישה — טקסט. */
function wResultText(p) {
    const r = p.result;
    if (r.value) return wMoney(r.value.agorot);
    return W.noResult + ': ' + (W.resultNotes[r.note] || r.note || '') + (p.ref_label ? ' (' + p.ref_label + ')' : '');
}
const wAltChips = a => `${a.is_primary ? wChip(W.primary, 'info') : ''}${wChip(W.states[a.state_kind] || a.state_kind)}${a.missing_assumptions ? wChip(W.missingAssumptions, 'bad') : ''}${a.stale ? wChip(W.stale, 'bad') : ''}${a.facts_stale ? wChip(W.factsStale, 'warn') : ''}`;

// ── בראש הלשונית "עסקאות וחישובים" ──
async function wSection(pid, reload) {
    let x;
    try { x = await api('case-valuation', { params: { case: pid } }); } catch (e) { return { html: '', bind: () => {} }; }
    const altCard = a => `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/alt/${a.pid}"><div class="body"><div class="l1"><b dir="auto">${esc(a.name)}</b></div>
        <div class="l2 wrap">${a.key_date ? esc(W.keyDateOf(fmtDate(a.key_date))) : esc(W.keyDateUnknown)}</div>
        <div class="l2 wrap">${a.conclusion ? `<b dir="ltr">${esc(wMoney(a.conclusion.agorot))}</b>` : `<span class="muted">${esc(W.noConclusion)}</span>`}</div>
        ${a.conclusion ? `<div class="l2 wrap"><q dir="auto">${esc(a.conclusion.reason)}</q></div>` : ''}
        <div class="chips">${wAltChips(a)}</div></div></li>`;
    const sheetRow = s => `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/calcsheet/${s.pid}"><span class="use-ic" aria-hidden="true">🧾</span><div class="body"><div class="l1"><span dir="auto">${esc(s.title)}</span><span class="t">${esc(W.rowsN(s.rows))}</span></div>
        <div class="l2 wrap">${s.result.value ? `${esc(W.sheetResult)}: <b dir="ltr">${esc(wMoney(s.result.value.agorot))}</b>` : `<span class="muted">${esc(W.noResult + (s.result.reason ? ': ' + (W.resultNotes[s.result.reason] || s.result.reason) : ''))}</span>`}</div>
        ${s.stale ? `<div class="chips">${wChip(W.sheetStale, 'bad')}</div>` : ''}</div></li>`;
    const ren = x.renovation, renN = ren ? x.lists.renovation_kinds.map(k => ren[k].length) : [];
    const renAny = renN.some(n => n > 0);
    const html = `<h2 class="sec-title">⚖️ ${W.title} <small>${x.alternatives.length || ''}</small></h2>
        ${x.alternatives.length ? `<ul class="list wgrid" aria-label="${W.overview}">${x.alternatives.map(altCard).join('')}</ul>` : `<p class="empty">${W.none}</p>`}
        ${x.perms.judge ? `<div class="row-btns wrap"><button type="button" class="btn ghost" id="w-alt-new"><span aria-hidden="true">+</span> ${W.newAlt}</button><button type="button" class="btn ghost" id="w-as-new"><span aria-hidden="true">+</span> ${W.addAssumption}</button></div>` : ''}
        ${x.case_assumptions.length ? `<h3 class="sec-title">${W.caseAssumptions}</h3><ul class="list">${x.case_assumptions.map(s => wAssumptionRow(s, pid, x.perms.judge)).join('')}</ul>` : ''}
        <h2 class="sec-title">🧾 ${W.sheets} <small>${x.sheets.length || ''}</small></h2>
        ${x.sheets.length ? `<ul class="list">${x.sheets.map(sheetRow).join('')}</ul>` : `<p class="empty">${W.noSheets}</p>`}
        ${x.perms.judge ? `<div class="row-btns wrap"><button type="button" class="btn ghost" id="w-sheet-new"><span aria-hidden="true">+</span> ${W.newSheet}</button><button type="button" class="btn ghost" id="w-sheet-tpl">${W.fromTemplate}</button></div>` : ''}
        ${ren && (x.flags.renovation_planned || renAny) ? `<h2 class="sec-title">🛠️ ${W.renovation}</h2><ul class="list"><li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/renovation"><div class="body">
            <div class="l1"><span>${W.renovation}</span></div><div class="l2 wrap">${x.lists.renovation_kinds.map((k, i) => `${esc(W.renovationKinds[k])}: ${renN[i] || '—'}`).join(' · ')}</div></div></li></ul>` : ''}`;
    return { html, bind: () => {
        $('#w-alt-new')?.addEventListener('click', () => wAltSheet(pid, x, null, reload));
        $('#w-as-new')?.addEventListener('click', () => wAssumptionSheet(pid, x, null, null, reload));
        $$('[data-was-edit]').forEach(b => b.addEventListener('click', () => wAssumptionSheet(pid, x, null, x.case_assumptions.find(s => s.pid === b.dataset.wasEdit), reload)));
        $('#w-sheet-new')?.addEventListener('click', () => sheet(W.newSheet, `<p class="hint">${W.sheetHint}</p><label class="f"><span>${W.sheetTitle}</span><input name="title" maxlength="120" required></label>
            <label class="f"><span>${W.rounding}</span><select name="rounding">${x.lists.roundings.map(o => cOpt(o, K.roundings[o], 'none')).join('')}</select></label>`,
            async f => { const r = await api('sheet-save', { body: { case: pid, title: f.title, rounding: f.rounding } }); location.hash = `#/cases/${pid}/calcsheet/${r.sheet}`; }));
        $('#w-sheet-tpl')?.addEventListener('click', () => wTemplateSheet(pid, reload));
    } };
}

function wAssumptionRow(s, pid, canEdit) {
    const links = wLinks(s, pid);
    return `<li class="card static"><div class="body"><div class="l1 wrap"><span dir="auto">${esc(s.text)}</span></div>
        ${s.basis ? `<div class="l2 wrap" dir="auto">${esc(W.basis + ': ' + s.basis)}</div>` : ''}${links ? `<div class="l2 wrap">${links}</div>` : ''}
        <div class="chips">${wChip(W.kinds[s.kind] || s.kind)}${wChip(W.approvals[s.approval] || s.approval, s.approval === 'unverified' ? 'warn' : '')}</div>
        ${canEdit ? `<div class="row-btns"><button type="button" class="btn ghost sm" data-was-edit="${s.pid}">${W.edit}</button></div>` : ''}</div></li>`;
}

function wAssumptionSheet(pid, x, alt, s, reload) {
    const d = s || { text: '', kind: 'assumption', basis: '', approval: 'unverified', fact: null, file: null };
    const dlg = sheet(s ? W.editAssumption : W.addAssumption, `<label class="f"><span>${W.assumptionText}</span><textarea name="text" maxlength="1000" rows="3" required>${esc(d.text)}</textarea></label>
        <label class="f"><span>${W.kind}</span><select name="kind">${x.lists.assumption_kinds.map(o => cOpt(o, W.kinds[o], d.kind)).join('')}</select></label>
        <label class="f"><span>${W.basis}</span><input name="basis" maxlength="500" value="${esc(d.basis || '')}"></label>
        <label class="f"><span>${W.approval}</span><select name="approval">${x.lists.approvals.map(o => cOpt(o, W.approvals[o], d.approval)).join('')}</select></label>
        ${wPick('file', W.linkFile, x.pick.files, d.file, 'name')}${wPick('fact', W.linkFact, x.pick.facts, d.fact, 'label')}
        ${alt && alt.state_kind === 'after_works' ? `<p class="hint">${W.missingAssumptionsHint}</p>` : ''}
        ${s ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="was-del">${W.deleteAssumption}</button></div>` : ''}`, async f => {
        const body = { text: f.text, kind: f.kind, basis: f.basis, approval: f.approval, file: f.file || null, fact: f.fact || null };
        await api('assumption-save', { body: s ? { assumption: s.pid, rev: s.rev, ...body } : (alt ? { alt: alt.pid, ...body } : { case: pid, ...body }) });
        reload();
    });
    $('#was-del', dlg)?.addEventListener('click', async () => { if (!confirm(W.deleteAssumptionAsk)) return; dlg.close(); wAct('assumption-delete', { assumption: s.pid, rev: s.rev }, reload); });
}

function wAltSheet(pid, x, a, reload) {
    const d = a || { name: '', key_date: x.case.key_date || '', state_kind: 'existing', state_note: '' };
    sheet(a ? W.editAlt : W.newAlt, `<label class="f"><span>${W.name}</span><input name="name" maxlength="80" required value="${esc(d.name)}"></label>
        <label class="f"><span>${W.keyDate}</span><input name="key_date" type="date" value="${esc(d.key_date || '')}"></label>
        <label class="f"><span>${W.state}</span><select name="state_kind">${x.lists.states.map(o => cOpt(o, W.states[o], d.state_kind)).join('')}</select></label>
        <label class="f"><span>${W.stateNote}</span><textarea name="state_note" maxlength="1000" rows="3">${esc(d.state_note || '')}</textarea></label>`, async f => {
        const body = { name: f.name, key_date: f.key_date || null, state_kind: f.state_kind, state_note: f.state_note };
        const made = (await api('alt-save', { body: a ? { alt: a.pid, rev: a.rev, ...body } : { case: pid, ...body } }))['alt'];
        if (a) reload(); else location.hash = `#/cases/${pid}/alt/${made}`;
    });
}

async function wTemplateSheet(pid, reload) {
    let t;
    try { t = (await api('sheet-templates')).templates; } catch (e) { toastError(e); return; }
    const dlg = sheet(W.fromTemplate, t.length ? `<p class="hint">${W.templateNote}</p><label class="f"><span>${W.template}</span><select name="template">${t.map(o => `<option value="${o.pid}">${esc(o.name)} · ${esc(W.scopes[o.scope])} · ${esc(W.rowsN(o.rows))}</option>`).join('')}</select></label>
        <label class="f"><span>${W.sheetTitle}</span><input name="title" maxlength="120"></label>
        <div class="row-btns"><button type="button" class="btn ghost danger" id="wt-del">${W.deleteTemplate}</button></div>` : `<p class="empty">${W.noTemplates}</p>`,
        t.length ? async f => { const r = await api('sheet-template-use', { body: { case: pid, template: f.template, title: f.title } }); location.hash = `#/cases/${pid}/calcsheet/${r.sheet}`; } : null);
    $('#wt-del', dlg)?.addEventListener('click', async () => {
        if (!confirm(W.deleteTemplateAsk)) return;
        try { await api('sheet-template-delete', { body: { template: dlg.querySelector('[name=template]').value } }); dlg.close(); wTemplateSheet(pid, reload); } catch (e) { toastError(e); }
    });
}

// ── חלופה ──
async function viewAlt(pid, altPid) {
    const x = await api('case-valuation', { params: { case: pid } });
    const a = x.alternatives.find(o => o.pid === altPid), reload = () => viewAlt(pid, altPid);
    if (!a) { location.hash = `#/cases/${pid}/comps`; return; }
    setHead(a.name, null);
    const judge = x.perms.judge;
    const approachRow = p => {
        const target = p.set ? `<a href="#/cases/${pid}/compset/${p.set.pid}">🧮 ${esc(p.set.name)}</a>` : p.sheet ? `<a href="#/cases/${pid}/calcsheet/${p.sheet.pid}">🧾 ${esc(p.sheet.title)}</a>` : '';
        return `<li class="card static"><div class="body"><div class="l1"><b dir="auto">${esc(p.title)}</b><span class="t">${esc(W.approachKinds[p.kind] || p.kind)}</span></div>
            <div class="l2 wrap">${esc(W.result)}: <b dir="auto">${esc(wResultText(p))}</b> <small class="muted">(${esc(W.resultSource[p.result.source])})</small></div>
            ${p.per_sqm ? `<div class="l2 wrap muted" dir="auto">${esc(W.perSqmLine(fNum(p.per_sqm), fNum(p.subject_area)))}</div>` : ''}
            ${p.result.source === 'entered' ? `<div class="l2 wrap"><q dir="auto">${esc(p.result.reason)}</q> · <span class="muted">${esc(W.concludedBy(p.result.by, fmtDateTime(p.result.at)))}</span></div>` : ''}
            <div class="l2 wrap"><q dir="auto">${esc(p.reasoning)}</q></div>${target ? `<div class="l2 wrap">${target}</div>` : ''}
            <div class="chips">${p.weight !== null ? wChip(W.weight + ' ' + p.weight + (p.share !== null ? ' (' + W.share(p.share) + ')' : '')) : wChip(W.noWeight)}${p.sheet && p.sheet.stale ? wChip(W.sheetStale, 'bad') : ''}${p.subject_stale ? wChip(K.staleSubject, 'bad') : ''}${
                p.unreasoned ? wChip(W.unreasoned(p.unreasoned), 'warn') : ''}</div>
            ${judge ? `<div class="row-btns wrap"><button type="button" class="btn ghost sm" data-wp-edit="${p.pid}">${W.editApproach}</button>${p.kind !== 'comparison' ? `<button type="button" class="btn ghost sm" data-wp-result="${p.pid}">${W.setResult}</button>` : ''}</div>` : ''}</div></li>`;
    };
    const wt = a.weighted;
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one" dir="auto">${esc(a.name)}</b></div>
          <div class="line"><span>${a.key_date ? esc(W.keyDateOf(fmtDate(a.key_date))) : esc(W.keyDateUnknown)}</span></div>
          ${a.state_note ? `<div class="line wrap" dir="auto">${esc(a.state_note)}</div>` : ''}<div class="chips">${wAltChips(a)}</div>
          ${judge ? `<div class="row-btns wrap"><button type="button" class="btn ghost sm" id="wa-edit">${W.editAlt}</button>${a.is_primary ? '' : `<button type="button" class="btn ghost sm" id="wa-primary">${W.makePrimary}</button>`}</div>` : ''}</div>
        ${a.stale ? `<p class="banner bad" role="alert">⚠️ ${esc(W.staleWhy[a.stale.why] || W.stale)}${judge ? ` <button type="button" class="btn ghost sm" data-wa-recheck>${W.recheck}</button>` : ''}</p>` : ''}
        ${a.facts_stale ? `<p class="banner bad" role="alert">⚠️ ${esc(W.factsStale)}${judge && !a.stale ? ` <button type="button" class="btn ghost sm" data-wa-recheck>${W.recheck}</button>` : ''}</p>` : ''}
        ${a.missing_assumptions ? `<p class="banner bad" role="alert">⚠️ <b>${esc(W.missingAssumptions)}</b> — ${esc(W.missingAssumptionsHint)}</p>` : ''}
        <h2 class="sec-title">📌 ${W.assumptions} <small>${a.assumptions.length || ''}</small></h2>
        ${a.assumptions.length ? `<ul class="list">${a.assumptions.map(s => wAssumptionRow(s, pid, judge)).join('')}</ul>` : `<p class="empty">${W.noAssumptions}</p>`}
        ${judge ? `<div class="row-btns"><button type="button" class="btn ghost" id="was-new"><span aria-hidden="true">+</span> ${W.addAssumption}</button></div>` : ''}
        <h2 class="sec-title">🧭 ${W.approaches} <small>${a.approaches.length || ''}</small></h2>
        ${a.approaches.length ? `<ul class="list">${a.approaches.map(approachRow).join('')}</ul>` : `<p class="empty">${W.noApproaches}</p>`}
        ${wt.value ? `<div class="summary"><div class="line"><span>${esc(W.weighted)}</span><span dir="ltr">${esc(wMoney(wt.value.agorot))}</span></div><div class="line muted">${esc(W.weightedHelper)}</div></div>`
            : wt.block ? `<p class="banner" role="note">${esc(W.weightedBlocks[wt.block](wt.ref || ''))}</p>` : ''}
        ${judge ? `<div class="row-btns"><button type="button" class="btn ghost" id="wp-new"><span aria-hidden="true">+</span> ${W.addApproach}</button></div>` : ''}
        <h2 class="sec-title">⚖️ ${W.conclusion} — <span dir="auto">${esc(a.name)}</span></h2>
        <div class="summary kconclusion">${a.conclusion ? `<div class="line"><span>${esc(a.name)}</span><b dir="ltr" class="big">${esc(wMoney(a.conclusion.agorot))}</b></div>
            <div class="line wrap"><q dir="auto">${esc(a.conclusion.reason)}</q></div><div class="line muted">${esc(W.concludedBy(a.conclusion.by, fmtDateTime(a.conclusion.at)))}</div>` : `<div class="line muted">${esc(W.noConclusion)}</div>`}
          <p class="hint">${esc(W.conclusionHint)}</p>
          ${judge ? `<div class="row-btns"><button type="button" class="btn ${a.conclusion ? 'ghost' : 'primary'}" id="wa-conclude">${a.conclusion ? W.concludeEdit : W.conclude}</button></div>` : ''}</div>
        ${judge ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="wa-delete">${W.deleteAlt}</button></div>` : ''}`;
    $('#wa-edit')?.addEventListener('click', () => wAltSheet(pid, x, a, reload));
    $('#wa-primary')?.addEventListener('click', () => wAct('alt-primary', { alt: a.pid, rev: a.rev }, reload));
    $$('[data-wa-recheck]').forEach(b => b.addEventListener('click', () => wAct('alt-recheck', { alt: a.pid, rev: a.rev }, reload)));
    $('#was-new')?.addEventListener('click', () => wAssumptionSheet(pid, x, a, null, reload));
    $$('[data-was-edit]').forEach(b => b.addEventListener('click', () => wAssumptionSheet(pid, x, a, a.assumptions.find(s => s.pid === b.dataset.wasEdit), reload)));
    $('#wa-delete')?.addEventListener('click', () => sheet(W.deleteAlt, `<p class="hint">${W.deleteNote}</p><label class="f"><span>${W.deleteReason}</span><textarea name="reason" maxlength="500" rows="2" required></textarea></label>`,
        async f => { await api('alt-delete', { body: { alt: a.pid, rev: a.rev, reason: f.reason } }); location.hash = `#/cases/${pid}/comps`; }, W.deleteAlt));
    $('#wa-conclude')?.addEventListener('click', () => {
        const dlg = sheet(W.conclusion + ' — ' + a.name, `<p class="hint">${W.conclusionHint}</p>
            <label class="f"><span>${W.conclusionValue}</span><input name="value" inputmode="decimal" dir="ltr" value="${esc(a.conclusion ? a.conclusion.value : '')}"></label>
            <label class="f"><span>${W.conclusionReason}</span><textarea name="reason" maxlength="500" rows="3" required>${esc(a.conclusion ? a.conclusion.reason : '')}</textarea></label>
            ${a.conclusion ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="wa-clear">${W.clearConclusion}</button></div>` : ''}`,
            async f => { await api('alt-conclude', { body: { alt: a.pid, rev: a.rev, value: f.value.trim() === '' ? null : f.value.trim(), reason: f.reason } }); reload(); });
        $('#wa-clear', dlg)?.addEventListener('click', () => { dlg.close(); wAct('alt-conclude', { alt: a.pid, rev: a.rev, value: null }, reload); });
    });
    const approachSheet = p => {
        const d = p || { kind: 'comparison', title: '', reasoning: '', weight: null, set: null, sheet: null };
        const dlg = sheet(p ? W.editApproach : W.addApproach, `${p ? `<input type="hidden" name="kind" value="${p.kind}">` : `<label class="f"><span>${W.approachKind}</span><select name="kind">${x.lists.approach_kinds.map(o => cOpt(o, W.approachKinds[o], d.kind)).join('')}</select></label>`}
            <label class="f"><span>${W.approachTitle}</span><input name="title" maxlength="80" value="${esc(d.title)}"></label>
            <label class="f" data-for="comparison"><span>${W.compSet}</span><select name="comp_set"><option value="">${W.linkNone}</option>${x.sets.map(g => `<option value="${g.pid}"${d.set && d.set.pid === g.pid ? ' selected' : ''}>${esc(g.name)} · ${esc(K.unitBases[g.unit_basis])}</option>`).join('')}</select></label>
            <label class="f" data-for="sheet"><span>${W.sheet}</span><select name="sheet"><option value="">${W.linkNone}</option>${x.sheets.map(s => `<option value="${s.pid}"${d.sheet && d.sheet.pid === s.pid ? ' selected' : ''}>${esc(s.title)}</option>`).join('')}</select></label>
            <label class="f"><span>${W.reasoning}</span><textarea name="reasoning" maxlength="1000" rows="3" required>${esc(d.reasoning)}</textarea></label>
            <label class="f"><span>${W.weight}</span><input name="weight" inputmode="decimal" dir="ltr" value="${esc(d.weight === null ? '' : d.weight)}"></label><p class="hint">${W.weightHint}</p>
            ${p ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="wp-del">${W.deleteApproach}</button></div>` : ''}`, async f => {
            const body = { kind: f.kind, title: f.title, reasoning: f.reasoning, weight: f.weight.trim() === '' ? null : f.weight.trim(), comp_set: f.kind === 'comparison' ? (f.comp_set || null) : null, sheet: f.kind === 'comparison' ? null : (f.sheet || null) };
            await api('approach-save', { body: p ? { approach: p.pid, rev: p.rev, ...body } : { alt: a.pid, ...body } });
            reload();
        });
        const sync = () => { const cmp = dlg.querySelector('[name=kind]').value === 'comparison'; $('[data-for=comparison]', dlg).hidden = !cmp; $('[data-for=sheet]', dlg).hidden = cmp; };
        dlg.querySelector('select[name=kind]')?.addEventListener('change', sync);
        sync();
        $('#wp-del', dlg)?.addEventListener('click', () => { if (!confirm(W.deleteApproachAsk)) return; dlg.close(); wAct('approach-delete', { approach: p.pid, rev: p.rev }, reload); });
    };
    $('#wp-new')?.addEventListener('click', () => approachSheet(null));
    $$('[data-wp-edit]').forEach(b => b.addEventListener('click', () => approachSheet(a.approaches.find(p => p.pid === b.dataset.wpEdit))));
    $$('[data-wp-result]').forEach(b => b.addEventListener('click', () => {
        const p = a.approaches.find(o => o.pid === b.dataset.wpResult), entered = p.result.source === 'entered';
        const dlg = sheet(W.setResult, `<p class="hint">${W.resultHint}</p>
            <label class="f"><span>${W.resultValue}</span><input name="value" inputmode="decimal" dir="ltr" required value="${esc(entered ? wPlain(p.result.value.agorot) : '')}"></label>
            <label class="f"><span>${W.resultReason}</span><textarea name="reason" maxlength="500" rows="3" required>${esc(entered ? p.result.reason : '')}</textarea></label>
            ${entered ? `<div class="row-btns"><button type="button" class="btn ghost" id="wp-computed">${W.useComputed}</button></div>` : ''}`,
            async f => { await api('approach-result', { body: { approach: p.pid, rev: p.rev, source: 'entered', value: f.value.trim(), reason: f.reason } }); reload(); });
        $('#wp-computed', dlg)?.addEventListener('click', () => { dlg.close(); wAct('approach-result', { approach: p.pid, rev: p.rev, source: 'computed' }, reload); });
    }));
}

// ── גיליון חישוב ──
/** ערך של שורה מהשרת ({text, exact}) — טקסט; "≈" כשהמחרוזת מעוגלת לתצוגה. */
const wVal = v => v ? (v.exact ? '' : W.approx + ' ') + v.text : null;
async function viewCalcSheet(pid, sheetPid) {
    const x = await api('sheet', { params: { sheet: sheetPid } });
    const s = x.sheet, reload = () => viewCalcSheet(pid, sheetPid), judge = x.perms.judge, write = x.perms.write;
    setHead(s.title, null);
    App.main.classList.add('wide');
    const keys = x.rows.map(r => r.key);
    const unitText = r => [r.unit, r.time_unit !== 'none' ? W.timeUnits[r.time_unit] : null].filter(Boolean).join(' ');
    const rowHtml = (r, i) => {
        const canEdit = r.kind === 'input' ? write : judge, links = r.kind === 'input' ? wLinks(r, pid) : '';
        const warn = (r.warnings || []).map(c => wChip('⚠️ ' + (W.warnings[c] || c), 'warn')).join('');
        let body;
        if (r.kind === 'input') {
            body = `<td data-l="${W.value}" dir="ltr">${r.value === null ? `<span class="muted">${esc(W.valueEmpty)}</span>` : `<b>${esc(r.value_text)}</b>`}</td><td data-l="${W.unit}" dir="auto">${esc(unitText(r))}</td>
                <td data-l="${W.origin}">${r.origin ? wChip(W.origins[r.origin], r.origin === 'provided' ? 'info' : 'warn') : `<span class="muted">${esc(W.originNone)}</span>`}${r.source_note ? `<br><small dir="auto">${esc(r.source_note)}</small>` : ''}${links ? `<br><small>${links}</small>` : ''}</td>`;
        } else if (r.kind === 'calc') {
            const res = r.result ? wVal(r.result) : null;
            body = `<td data-l="${W.value}" dir="ltr">${res !== null ? `<b>${esc(res)}</b>` : `<span class="chip bad">${esc(W.cannot(W.reasons[r.reason] || r.reason, r.ref_label))}</span>`}</td><td data-l="${W.unit}" dir="auto">${esc(unitText(r))}</td>
                <td data-l="${W.formula}"><span dir="auto">${esc(r.label + ' = ' + r.text.words + ' = ' + r.text.numbers + (res !== null ? ' = ' + res : ''))}</span></td>`;
        } else {
            body = `<td colspan="3" data-l="${W.rowKinds.note}" class="muted">${esc(W.rowKinds.note)}</td>`;
        }
        return `<tr><td data-l="${W.label}"><small class="muted" dir="ltr">${esc(r.key)}</small> <span dir="auto">${esc(r.label)}</span>${r.reasoning ? `<br><small class="muted" dir="auto">${esc(r.reasoning)}</small>` : ''}${warn ? `<br>${warn}` : ''}</td>${body}
            <td>${canEdit ? `<button type="button" class="btn ghost sm" data-wr-edit="${r.key}">${W.edit}</button>` : ''}${judge ? `<button type="button" class="icon-btn" data-wr-up="${i}" aria-label="${W.moveUp}"${i === 0 ? ' disabled' : ''}>▲</button><button type="button" class="icon-btn" data-wr-down="${i}" aria-label="${W.moveDown}"${i === keys.length - 1 ? ' disabled' : ''}>▼</button>` : ''}</td></tr>`;
    };
    const res = x.result;
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one" dir="auto">${esc(s.title)}</b></div>
          <div class="line muted">${esc(W.rounding)}: ${esc(K.roundings[s.rounding])}${x.used_by.length ? ` · ${esc(W.usedBy)}: ${x.used_by.map(u => esc(W.usedByLine(u.alt, u.approach))).join(' · ')}` : ''}</div>
          ${judge ? `<div class="row-btns wrap"><button type="button" class="btn ghost sm" id="ws-edit">${W.sheetSettings}</button><button type="button" class="btn ghost sm" id="ws-tpl">${W.saveTemplate}</button></div>` : ''}</div>
        ${s.stale ? `<p class="banner bad" role="alert">⚠️ ${esc(W.sheetStale)} — ${esc(W.sheetStaleHint)}${judge ? ` <button type="button" class="btn ghost sm" id="ws-recheck">${W.recheck}</button>` : ''}</p>` : ''}
        <p class="hint">${esc(W.sheetHint)}</p>
        ${x.rows.length ? `<div class="ktable-wrap"><table class="ktable"><thead><tr><th>${W.label}</th><th>${W.value}</th><th>${W.unit}</th><th>${W.origin} / ${W.formula}</th><th></th></tr></thead><tbody>${x.rows.map(rowHtml).join('')}</tbody></table></div>` : `<p class="empty">${W.noRows}</p>`}
        ${write ? `<div class="row-btns wrap"><button type="button" class="btn ghost" data-wr-new="input"><span aria-hidden="true">+</span> ${W.addInput}</button>${judge ? `<button type="button" class="btn ghost" data-wr-new="calc"><span aria-hidden="true">+</span> ${W.addCalc}</button><button type="button" class="btn ghost" data-wr-new="note"><span aria-hidden="true">+</span> ${W.addNote}</button>` : ''}</div>` : ''}
        <h2 class="sec-title">${W.sheetResult}</h2>
        <div class="summary kconclusion">${res.value ? `<div class="line"><span dir="auto">${esc(res.label)}</span><b dir="ltr" class="big">${esc(wMoney(res.value.agorot))}</b></div>
            <div class="line muted">${esc(K.roundings[s.rounding])} · ${esc(W.beforeRounding(wVal(res.exact)))}</div>${res.exact.exact ? '' : `<div class="line muted">${esc(W.exactNote)}</div>`}`
            : `<div class="line muted">${esc(W.noResult + ': ' + (W.resultNotes[res.reason] || res.reason) + (res.ref_label ? ' (' + res.ref_label + ')' : ''))}</div>`}
          <p class="hint">${esc(W.sheetResultHint)}</p></div>
        ${judge ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="ws-del">${W.deleteSheet}</button></div>` : ''}`;
    const rowSheet = (kind, r) => {
        const above = r ? x.rows.slice(0, keys.indexOf(r.key)) : x.rows, refs = above.filter(o => o.kind !== 'note');
        if (kind === 'calc' && !refs.length) { toast(W.needRowsAbove, true); return; }
        const d = r || { label: '', value: null, unit: '', time_unit: 'none', origin: '', source_note: '', reasoning: '', fact: null, file: null };
        // נוסחה קיימת שהבונה יודע להציג: פעולה אחת על שני איברים, כל אחד שורה / מספר, אולי כאחוז. אחרת — מוצגת כטקסט, והבונה מתחיל ריק
        const part = a => typeof a === 'string' ? { v: a, pct: false } : (a && a.pct !== undefined && typeof a.pct === 'string' ? { v: a.pct, pct: true } : null);
        const f0 = r && r.formula && r.formula.op && r.formula.args.length === 2 ? r.formula : null;
        const a0 = f0 ? part(f0.args[0]) : null, b0 = f0 ? part(f0.args[1]) : null, simple = !r || !r.formula || (f0 && a0 && b0);
        const operand = (name, cur) => `<label class="f"><span>${W[name === 'a' ? 'operandA' : 'operandB']}</span><select name="${name}">${refs.map(o => `<option value="${o.key}"${cur && cur.v === o.key ? ' selected' : ''}>${esc(o.key + ' · ' + o.label)}</option>`).join('')}
              <option value=""${cur && !refs.some(o => o.key === cur.v) ? ' selected' : ''}>${W.operandConst}</option></select></label>
            <label class="f" data-const="${name}"><span>${W.constValue}</span><input name="${name}_const" inputmode="decimal" dir="ltr" value="${esc(cur && !refs.some(o => o.key === cur.v) ? cur.v : '')}"></label>
            <label class="check"><input type="checkbox" name="${name}_pct"${cur && cur.pct ? ' checked' : ''}> <span>${W.asPct}</span></label>`;
        const dlg = sheet(r ? W.editRow : W[kind === 'input' ? 'addInput' : kind === 'calc' ? 'addCalc' : 'addNote'], `<label class="f"><span>${W.label}</span><input name="label" maxlength="120" required value="${esc(d.label)}"></label>
            ${kind === 'input' ? `<label class="f"><span>${W.value}</span><input name="value" inputmode="decimal" dir="ltr" value="${esc(d.value === null ? '' : d.value)}" placeholder="${W.valueEmpty}"></label>
              <p class="sec-title">${W.origin}</p><fieldset class="seg" aria-label="${W.origin}">${x.lists.origins.map(o => `<label><input type="radio" name="origin" value="${o}"${o === d.origin ? ' checked' : ''}><span>${W.origins[o]}</span></label>`).join('')}</fieldset>
              <label class="f"><span>${W.sourceNote}</span><input name="source_note" maxlength="300" value="${esc(d.source_note || '')}"></label>
              ${wPick('fact', W.linkFact, x.pick.facts, d.fact, 'label')}${wPick('file', W.linkFile, x.pick.files, d.file, 'name')}` : ''}
            ${kind === 'calc' ? `${simple ? '' : `<p class="banner" role="note">${esc(W.formulaComplex)}<br><span dir="auto">${esc(r.text.words)}</span></p>`}
              <label class="f"><span>${W.op}</span><select name="op">${['mul', 'add', 'sub', 'div', 'sum'].map(o => cOpt(o, W.ops[o], f0 ? f0.op : 'mul')).join('')}</select></label>
              ${operand('a', a0)}${operand('b', b0)}<p class="hint">${W.formulaHint}</p>` : ''}
            ${kind !== 'note' ? `<label class="f"><span>${W.unit}</span><input name="unit" maxlength="20" value="${esc(d.unit || '')}" list="w-units"></label><datalist id="w-units"><option value="₪"><option value="₪ לחודש"><option value="₪ לשנה"><option value="%"><option value="מ&quot;ר"></datalist>
              <label class="f"><span>${W.timeUnit}</span><select name="time_unit">${x.lists.time_units.map(o => cOpt(o, W.timeUnits[o], d.time_unit)).join('')}</select></label>` : ''}
            <label class="f"><span>${W.rowReasoning}</span><textarea name="reasoning" maxlength="1000" rows="2">${esc(d.reasoning || '')}</textarea></label>
            ${r && judge ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="wr-del">${W.deleteRow}</button></div>` : ''}`, async f => {
            const body = { kind, label: f.label, reasoning: f.reasoning };
            if (kind !== 'note') Object.assign(body, { unit: f.unit, time_unit: f.time_unit });
            if (kind === 'input') Object.assign(body, { value: f.value.trim() === '' ? null : f.value.trim(), origin: f.origin || null, source_note: f.source_note, fact: f.fact || null, file: f.file || null });
            if (kind === 'calc') {
                // המסך רק מרכיב את מבנה הנוסחה ממה שנבחר; הבדיקה והחישוב — בשרת
                const arg = n => { const v = f[n] !== '' ? f[n] : f[n + '_const'].trim(); return f[n + '_pct'] ? { pct: v } : v; };
                body.formula = f.op === 'sum' ? { op: 'sum', args: [f.a, f.b] } : { op: f.op, args: [arg('a'), arg('b')] };
            }
            await api('sheet-row-save', { body: { sheet: s.pid, rev: s.rev, ...(r ? { key: r.key } : {}), ...body } });
            reload();
        });
        if (kind === 'calc') {
            const sync = () => {
                const sum = dlg.querySelector('[name=op]').value === 'sum';
                ['a', 'b'].forEach(n => {
                    const sel = dlg.querySelector(`[name=${n}]`), isConst = sel.value === '';
                    sel.querySelector('option[value=""]').hidden = sel.querySelector('option[value=""]').disabled = sum;
                    if (sum && isConst) sel.selectedIndex = 0;
                    $(`[data-const=${n}]`, dlg).hidden = sum || sel.value !== '';
                    dlg.querySelector(`[name=${n}_pct]`).closest('label').hidden = sum;
                });
            };
            $$('[name=op], [name=a], [name=b]', dlg).forEach(el => el.addEventListener('change', sync));
            sync();
        }
        $('#wr-del', dlg)?.addEventListener('click', () => { if (!confirm(W.deleteRowAsk)) return; dlg.close(); wAct('sheet-row-delete', { sheet: s.pid, rev: s.rev, key: r.key }, reload); });
    };
    $$('[data-wr-new]').forEach(b => b.addEventListener('click', () => rowSheet(b.dataset.wrNew, null)));
    $$('[data-wr-edit]').forEach(b => b.addEventListener('click', () => { const r = x.rows.find(o => o.key === b.dataset.wrEdit); rowSheet(r.kind, r); }));
    const move = (i, j) => { const order = keys.slice(); [order[i], order[j]] = [order[j], order[i]]; wAct('sheet-row-order', { sheet: s.pid, rev: s.rev, order }, reload); };
    $$('[data-wr-up]').forEach(b => b.addEventListener('click', () => move(+b.dataset.wrUp, +b.dataset.wrUp - 1)));
    $$('[data-wr-down]').forEach(b => b.addEventListener('click', () => move(+b.dataset.wrDown, +b.dataset.wrDown + 1)));
    $('#ws-recheck')?.addEventListener('click', () => wAct('sheet-recheck', { sheet: s.pid, rev: s.rev }, reload));
    $('#ws-edit')?.addEventListener('click', () => sheet(W.sheetSettings, `<label class="f"><span>${W.sheetTitle}</span><input name="title" maxlength="120" required value="${esc(s.title)}"></label>
        <label class="f"><span>${W.rounding}</span><select name="rounding">${x.lists.roundings.map(o => cOpt(o, K.roundings[o], s.rounding)).join('')}</select></label>`,
        async f => { await api('sheet-save', { body: { sheet: s.pid, rev: s.rev, title: f.title, rounding: f.rounding } }); reload(); }));
    $('#ws-tpl')?.addEventListener('click', () => sheet(W.saveTemplate, `<p class="hint">${W.templateNote}</p><label class="f"><span>${W.templateName}</span><input name="name" maxlength="80" required value="${esc(s.title)}"></label>
        <p class="sec-title">${W.templateScope}</p><fieldset class="seg" aria-label="${W.templateScope}">${['personal', 'office'].map(o => `<label><input type="radio" name="scope" value="${o}"${o === 'personal' ? ' checked' : ''}><span>${W.scopes[o]}</span></label>`).join('')}</fieldset>`,
        async f => { await api('sheet-template-save', { body: { sheet: s.pid, name: f.name, scope: f.scope } }); toast(W.templateSaved); }));
    $('#ws-del')?.addEventListener('click', async () => {
        if (!confirm(W.deleteSheetAsk)) return;
        try { await api('sheet-delete', { body: { sheet: s.pid, rev: s.rev } }); location.hash = `#/cases/${pid}/comps`; } catch (e) { wToastError(e, reload); }
    });
}

// ── רכישה ושיפוץ ──
async function viewRenovation(pid) {
    const x = await api('case-valuation', { params: { case: pid } });
    const reload = () => viewRenovation(pid), ren = x.renovation;
    setHead(W.renovation, null);
    if (!ren) { location.hash = `#/cases/${pid}/comps`; return; }
    const item = (r, kind) => `<li class="card static"><div class="body"><div class="l1"><b dir="ltr">${r.amount ? esc(wMoney(r.amount.agorot)) : esc(W.amountUnknown)}</b><span class="t">${r.item_date ? esc(fmtDate(r.item_date)) : ''}</span></div>
        ${r.provider ? `<div class="l2 wrap" dir="auto">${esc(W.provider + ': ' + r.provider)}</div>` : ''}${r.scope_note ? `<div class="l2 wrap" dir="auto">${esc(r.scope_note)}</div>` : ''}
        ${wLinks(r, pid) ? `<div class="l2 wrap">${wLinks(r, pid)}</div>` : ''}<div class="l2 wrap muted">${esc(W.enteredBy(r.entered_by, fmtDateTime(r.created_at)))}</div>
        ${kind === 'quote' ? `<div class="chips">${wChip(W.version(r.version_no))}${r.superseded_by ? wChip(W.superseded, 'warn') : ''}</div>` : ''}
        ${x.perms.write ? `<div class="row-btns wrap">${kind === 'quote' && !r.superseded_by ? `<button type="button" class="btn ghost sm" data-wn-version="${r.pid}">${W.newVersion}</button>` : ''}${
            !r.superseded_by && (r.mine || x.perms.judge) ? `<button type="button" class="btn ghost sm danger" data-wn-del="${r.pid}">${W.deleteItem}</button>` : ''}</div>` : ''}</div></li>`;
    App.main.innerHTML = `<p class="banner" role="note">${esc(W.renovationHint)}</p>
        ${x.lists.renovation_kinds.map(kind => `<h2 class="sec-title">${esc(W.renovationKinds[kind])} <small>${ren[kind].length || ''}</small></h2>
            ${ren[kind].length ? `<ul class="list">${ren[kind].map(r => item(r, kind)).join('')}</ul>` : `<p class="empty">${W.renovationNone}</p>`}
            ${x.perms.write ? `<div class="row-btns"><button type="button" class="btn ghost" data-wn-new="${kind}"><span aria-hidden="true">+</span> ${W.addItem}: ${esc(W.renovationOne[kind])}</button></div>` : ''}`).join('')}`;
    const itemSheet = (kind, prev) => sheet(prev ? W.newVersion : W.renovationOne[kind], `${prev ? `<p class="hint">${W.newVersionNote}</p>` : ''}
        <label class="f"><span>${W.amount}</span><input name="amount" inputmode="decimal" dir="ltr"></label>
        <label class="f"><span>${W.renovationDate[kind]}</span><input name="item_date" type="date"></label>
        ${kind === 'quote' ? `<label class="f"><span>${W.provider}</span><input name="provider" maxlength="120" value="${esc(prev ? prev.provider || '' : '')}"></label>` : ''}
        <label class="f"><span>${W.scopeNote}</span><textarea name="scope_note" maxlength="1000" rows="2"></textarea></label>
        ${wPick('file', W.linkFile, x.pick.files, null, 'name')}${wPick('fact', W.linkFact, x.pick.facts, null, 'label')}`, async f => {
        const r = await api('renovation-add', { body: { case: pid, kind, amount: f.amount.trim() === '' ? null : f.amount.trim(), item_date: f.item_date || null, provider: f.provider || null, scope_note: f.scope_note, file: f.file || null, fact: f.fact || null,
            ...(prev ? { supersedes: prev.pid } : {}) } });
        if (r.review) toast(W.reviewOpened);
        reload();
    });
    $$('[data-wn-new]').forEach(b => b.addEventListener('click', () => itemSheet(b.dataset.wnNew, null)));
    $$('[data-wn-version]').forEach(b => b.addEventListener('click', () => itemSheet('quote', ren.quote.find(r => r.pid === b.dataset.wnVersion))));
    $$('[data-wn-del]').forEach(b => b.addEventListener('click', () => sheet(W.deleteItem, `<label class="f"><span>${W.deleteItemReason}</span><textarea name="reason" maxlength="500" rows="2" required></textarea></label>`,
        async f => { await api('renovation-delete', { body: { item: b.dataset.wnDel, reason: f.reason } }); reload(); }, W.deleteItem)));
}

// ── שיוך ומשקולות במסך קבוצת ההשוואה (פרוסה 16: לכל קבוצה בנפרד) ──
function wMembership(x, pid) {
    if (!x.perms.judge) return '';
    const row = r => `<li class="card static"><div class="body"><div class="l1"><span dir="auto">${esc(kLabel(r.comp.values))}</span><span class="t">${r.weight === null ? esc(W.noWeight) : esc(W.weight + ' ' + r.weight)}</span></div>
        ${r.member_reason ? `<div class="l2 wrap"><q dir="auto">${esc(r.member_reason)}</q></div>` : ''}${r.comp.values.condition === null ? `<div class="chips">${wChip(W.conditionUnknown, 'warn')}</div>` : ''}
        <div class="row-btns wrap"><button type="button" class="btn ghost sm" data-wm-weight="${r.comp.pid}">${W.memberWeight}</button><button type="button" class="btn ghost sm" data-wm-out="${r.comp.pid}">${W.removeFromSet}</button></div></div></li>`;
    const other = k => `<li class="card static"><div class="body"><div class="l1"><span dir="auto">${esc(kLabel(k.values))}</span><span class="t">${esc(kPrice(k.values))}</span></div>
        ${k.values.condition === null ? `<div class="chips">${wChip(W.conditionUnknown, 'warn')}</div>` : ''}
        <div class="row-btns"><button type="button" class="btn ghost sm" data-wm-in="${k.pid}">${W.addToSet}</button></div></div></li>`;
    return `<h2 class="sec-title">${W.membership}</h2>${x.after_works ? `<p class="banner" role="note">${esc(W.afterWorksSet)}</p>` : ''}
        ${x.rows.length ? `<ul class="list">${x.rows.map(row).join('')}</ul>` : ''}
        ${x.others.length ? `<h3 class="sec-title">${W.notInSet} <small>${x.others.length}</small></h3><ul class="list">${x.others.map(other).join('')}</ul>` : ''}`;
}
function wMembershipBind(x, reload) {
    const g = x.set;
    $$('[data-wm-weight]').forEach(b => b.addEventListener('click', () => {
        const r = x.rows.find(o => o.comp.pid === b.dataset.wmWeight);
        sheet(W.memberWeight, `<p class="hint">${K.weightHint}</p><label class="f"><span>${W.memberWeight}</span><input name="weight" inputmode="decimal" dir="ltr" value="${esc(r.weight === null ? '' : r.weight)}"></label>`,
            async f => { await api('comp-weight', { body: { set: g.pid, comp: r.comp.pid, rev: g.rev, weight: f.weight.trim() === '' ? null : f.weight.trim() } }); reload(); });
    }));
    const member = (comp, on) => sheet(on ? W.addToSet : W.removeFromSet, `${on && x.after_works ? `<p class="hint">${W.afterWorksSet}</p>` : ''}<label class="f"><span>${W.memberReason}</span><textarea name="reason" maxlength="500" rows="2"></textarea></label>`,
        async f => { await api('comp-set-member', { body: { set: g.pid, comp, rev: g.rev, member: on, reason: f.reason } }); reload(); }, on ? W.addToSet : W.removeFromSet);
    $$('[data-wm-out]').forEach(b => b.addEventListener('click', () => member(b.dataset.wmOut, false)));
    $$('[data-wm-in]').forEach(b => b.addEventListener('click', () => member(b.dataset.wmIn, true)));
}
