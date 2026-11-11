/*
 * פרוסה 10 — נתונים, מקור לכל נתון, וסתירות (docs/spec/10-facts-conflicts.md §8).
 *   כרטיס תיק ← "נתונים" (factsTab) ← מאפיין (viewAttribute) · ← סתירות (viewConflicts / viewConflict)
 *   מסך הקובץ ← "נתונים מהמסמך" (fileFactsPanel) · #/system/attributes — הקטלוג (viewAttributes)
 * "לא ידוע" מוצג במילים ולעולם לא כאפס; ערך מוצג תמיד עם יחידה, ושטח — עם סוגו (fVal). כל ערך שמשתמש הקליד עובר דרך esc().
 * ההרשאות נאכפות בשרת — כאן רק מסתירים כפתורים.
 */
'use strict';

const F = T.f;
const fStateCls = { proposed: 'warn', manual: '', needs_clarification: 'warn', verified: 'ok', rejected: 'bad' };
const fStateChip = f => `<span class="chip ${fStateCls[f.state] || ''}">${esc(F.states[f.state] || f.state)}</span>`;
/** מספר עשרוני כמחרוזת (כך הוא מגיע מהשרת — בלי נקודה צפה) עם מפרידי אלפים. */
const fNum = s => { const [i, d] = String(s).split('.'); return i.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (d ? '.' + d : ''); };
const fChoice = (a, k) => (a.choices || []).find(c => c.key === k)?.label || k;

/** הערך לתצוגה, כטקסט (לא HTML): "78 מ"ר (רשום)" · "1,500,000 ₪" · "לא ידוע". v — נתון או ערך מוסכם; a — המאפיין. */
function fValText(v, a) {
    if (!v) return F.unknown;
    let s;
    if (a.value_type === 'bool') s = v.bool === null ? null : v.bool ? F.yes : F.no;
    else if (a.value_type === 'date') s = v.date ? fmtDate(v.date) : null;
    else if (a.value_type === 'choice') s = v.text === null ? null : fChoice(a, v.text);
    else if (a.value_type === 'text') s = v.text;
    else s = v.num === null ? null : `${fNum(v.num)}${v.unit ? ' ' + (F.units[v.unit] || v.unit) : ''}`;
    if (s === null) s = F.unknown;
    return a.has_area_kind && v.area_kind ? `${s} (${F.areaKinds[v.area_kind] || v.area_kind})` : s;
}
const fVal = (v, a) => !v || (v.has_value === false) ? `<span class="muted">${esc(fValText(v && { ...v, num: null, text: null, date: null, bool: null }, a))}</span>` : `<b dir="auto">${esc(fValText(v, a))}</b>`;

/** המקור לתצוגה. מי שאין לו גישה לקובץ מקבל מהשרת restricted בלבד — ומוצג "מקור: מסמך", בלי שם ובלי קישור. */
function fSource(f, pid) {
    const s = f.source;
    if (s.restricted) return esc(F.srcDocOnly);
    const date = s.date ? ' · ' + esc(fmtDate(s.date)) : '';
    if (s.kind === 'document' || s.file_name) {
        const name = `${esc(s.file_name || '')}${s.page ? ' · ' + esc(F.page(s.page)) : ''}`;
        const doc = s.file ? `<a href="#/cases/${pid}/file/${s.file}">📄 ${name}</a>` : `📄 ${name}`;
        return `${s.kind === 'ai' ? esc(F.sourceKinds.ai) + ' · ' : ''}${doc}${date}${s.locator ? ` · <q dir="auto">${esc(s.locator)}</q>` : ''}${
            s.doc_state === 'deleted' ? ` <span class="chip bad">${esc(F.srcDeleted)}</span>` : s.doc_state === 'returned' ? ` <span class="chip warn">${esc(F.srcReturned)}</span>` : ''}`;
    }
    if (s.check) return `<a href="#/cases/${pid}/check/${s.check}">🔎 ${esc(s.check_source)}</a> · ${s.value_corrected ? `<span class="chip warn">${esc(T.s.valueCorrected)}</span>` : esc(T.s.statuses[s.check_status] || '')}${s.note ? ' · ' + esc(s.note) : ''}${date}`;
    return `${esc(F.sourceKinds[s.kind] || s.kind)}${s.note ? ' · ' + esc(s.note) : ''}${date}`;
}

const fChips = f => `${fStateChip(f)}${f.estimated ? `<span class="chip">${esc(F.estimated)}</span>` : ''}`;

/** שגיאת 409 של נתון: מציגים את הערך שבשרת מול מה שהמשתמש ניסה לשמור — אין דריסה שקטה (§5). */
function fChangedToast(x, a, mine) {
    if (x.code === 'fact_changed' && x.current) toast(F.changed(x.message, fValText(x.current.has_value ? x.current : null, a), mine), true);
    else toastError(x);
}

// ── לשונית "נתונים" בכרטיס התיק ──
async function factsTab(pid) {
    const x = await api('case-facts', { params: { case: pid } });
    const row = a => {
        const active = a.facts.filter(f => f.state !== 'rejected' && f.has_value), open = a.conflicts.filter(c => c.status === 'open').length;
        const val = a.value ? fVal(a.value, a) : !a.known ? `<span class="muted">${esc(F.unknown)}</span>`
            : active.length === 1 ? fVal(active[0], a) : active.slice(0, 3).map(f => fVal(f, a)).join(' · ') + (active.length > 3 ? ' …' : '');
        const one = a.value ? a.facts.find(f => f.pid === a.value.fact) : active.length === 1 ? active[0] : null;
        return `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/facts/${a.key}"><div class="body"><div class="l1"><span>${esc(a.label)}</span><span class="t">${val}</span></div>
            ${one ? `<div class="l2 wrap">${fSource(one, pid)}</div>` : ''}
            <div class="chips">${one ? fChips(one) : active.length > 1 ? `<span class="chip">${esc(F.several(active.length))}</span>` : ''}${open ? `<span class="chip bad">⚠️ ${esc(F.conflictOpen)}</span>` : ''}${
                a.facts.some(f => f.state === 'needs_clarification') && !(one && one.state === 'needs_clarification') ? `<span class="chip warn">${esc(F.states.needs_clarification)}</span>` : ''}${
                a.status !== 'active' ? `<span class="chip">${esc(F.hidden)}</span>` : ''}</div></div></li>`;
    };
    const html = `${x.conflicts_open ? `<p class="banner" role="note">⚠️ ${esc(F.openConflicts(x.conflicts_open))} <a class="btn ghost sm" href="#/cases/${pid}/conflicts">${F.conflicts}</a></p>`
            : `<div class="row-btns"><a class="btn ghost" href="#/cases/${pid}/conflicts">${F.conflicts}</a></div>`}
        ${x.groups.map(g => { const list = x.attributes.filter(a => a.group === g); return list.length ? `<h2 class="sec-title">${esc(F.groups[g])}</h2><ul class="list">${list.map(row).join('')}</ul>` : ''; }).join('')}`;
    return { html, bind: () => {} };
}

/** השדות של ערך, לפי סוג המאפיין. cur — הערך הנוכחי (בתיקון). */
function fValueFields(a, x, cur) {
    const v = cur || {};
    const input = a.value_type === 'date' ? `<input type="date" name="value" value="${esc(v.date || '')}">`
        : a.value_type === 'bool' ? `<select name="value"><option value=""></option>${cOpt('1', F.yes, v.bool === true ? '1' : '')}${cOpt('0', F.no, v.bool === false ? '0' : '')}</select>`
        : a.value_type === 'choice' ? `<select name="value"><option value=""></option>${a.choices.map(c => cOpt(c.key, c.label, v.text)).join('')}</select>`
        : a.value_type === 'text' ? `<textarea name="value" maxlength="500" rows="2">${esc(v.text || '')}</textarea>`
        : `<input name="value" inputmode="decimal" autocomplete="off" dir="ltr" maxlength="16" value="${esc(v.num ?? '')}">`;
    const units = x.units[a.unit_kind] || [];
    return `<label class="f"><span>${esc(a.label)}</span>${input}</label>
        ${units.length ? `<label class="f"><span>${F.unit}</span><select name="unit">${units.map(u => cOpt(u, F.units[u] || u, v.unit || units[0])).join('')}</select></label>` : ''}
        ${a.has_area_kind ? `<label class="f"><span>${F.areaKind} *</span><select name="area_kind" required><option value="">${F.pickAreaKind}</option>${x.area_kinds.map(k => cOpt(k, F.areaKinds[k], v.area_kind || '')).join('')}</select></label>
            <p class="hint">${F.areaKindHint}</p>` : ''}`;
}

/** ערכי הטופס ← גוף הבקשה. ערך ריק נשלח כ-null ("לא ידוע"), לעולם לא כאפס. */
function fValueBody(a, v) {
    const raw = (v.value ?? '').trim();
    const value = raw === '' ? null : a.value_type === 'bool' ? raw === '1' : raw.replace(/,/g, '');
    return { value, ...(v.unit !== undefined ? { unit: v.unit } : {}), ...(a.has_area_kind ? { area_kind: v.area_kind || null } : {}) };
}

/** שולח; "אפס, או לא ידוע?" — שואלים, ורק אישור מפורש שולח את האפס (מקרה קצה 5). */
async function fSend(action, body) {
    try {
        return await api(action, { body });
    } catch (e) {
        if (e.code !== 'fact_zero_confirm' || !confirm(F.zeroAsk)) throw e;
        return api(action, { body: { ...body, zero_ok: true } });
    }
}

/** הזנת נתון. preset: { file, page } כשמוסיפים ממסך הקובץ. */
async function factAddSheet(pid, a, x, preset, reload) {
    const docs = await api('case-docs', { params: { case: pid } });
    const files = [...docs.requirements.flatMap(r => r.files.map(l => l.file)), ...docs.unassigned].filter((f, i, all) => !f.deleted && all.findIndex(o => o.pid === f.pid) === i);
    const kind0 = preset?.file ? 'document' : files.length ? 'document' : 'user';
    // בדיקות מקור שיש בהן ממצא (09) — "לא אותר" ו"מקור לא זמין" אינם מקור לנתון
    const checks = (await api('case-sources', { params: { case: pid } })).sources.flatMap(s => [s.latest, s.verified]).filter(k => k && ['found', 'partial', 'verified', 'needs_clarification'].includes(k.status));
    const dlg = sheet(`${F.addFact}: ${a.label}`, `${fValueFields(a, x, null)}
        <label class="switch"><input type="checkbox" name="estimated"><span>${F.estimatedLabel}</span></label>
        <label class="f"><span>${F.source}</span><select name="kind">${x.sources.map(k => cOpt(k, F.sourceKinds[k], kind0)).join('')}</select></label>
        <div data-src="document"><label class="f"><span>${F.file}</span><select name="file"><option value="">${F.pickFile}</option>${files.map(f => cOpt(f.pid, f.name, preset?.file || '')).join('')}</select></label>
          ${files.length ? '' : `<p class="hint">${F.noFiles}</p>`}
          <label class="f"><span>${F.pageLabel}</span><input name="page" inputmode="numeric" dir="ltr" maxlength="6" value="${esc(preset?.page || '')}"></label>
          <label class="f"><span>${F.locator}</span><input name="locator" maxlength="300" dir="auto"></label></div>
        <div data-src="source_check"><label class="f"><span>${T.s.checkTitle}</span><select name="check"><option value=""></option>${checks.map(k => cOpt(k.pid, `${k.source_name} · ${fmtDate(k.performed_at)} · ${T.s.statuses[k.status]}`, '')).join('')}</select></label></div>
        <div data-src="user"><label class="f"><span>${F.toldBy}</span><input name="src_note" maxlength="200" dir="auto"></label></div>
        <label class="f"><span>${F.sourceDate}</span><input type="date" name="src_date"></label>
        <label class="f"><span>${F.note}</span><textarea name="note" maxlength="1000" rows="2"></textarea></label>
        <label class="switch"><input type="checkbox" name="not_found"><span>${F.notFound}</span></label><p class="hint">${F.notFoundHint}</p>`, async v => {
        const page = (v.page || '').trim();
        const source = { kind: v.kind, date: v.src_date || null, ...(v.kind === 'document' ? { file: v.file || null, page: /^\d+$/.test(page) ? Number(page) : page === '' ? null : page, locator: v.locator } : v.kind === 'source_check' ? { ref: v.check || null } : { note: v.src_note }) };
        await fSend('fact-add', { case: pid, attribute: a.key, ...fValueBody(a, v), estimated: !!v.estimated, source, note: v.note, ...(v.not_found ? { state: 'needs_clarification' } : {}) });
        toast(F.saved);
        reload();
    });
    const sync = () => $$('[data-src]', dlg).forEach(el => { el.hidden = el.dataset.src !== dlg.querySelector('[name=kind]').value; });
    dlg.querySelector('[name=kind]').addEventListener('change', sync);
    sync();
}

function factHistorySheet(f, a) {
    api('fact', { params: { fact: f.pid } }).then(d => sheet(`${F.history}: ${a.label}`,
        `${d.uses.length ? `<p class="banner" role="note">🔗 ${esc(F.uses(d.uses.length, d.uses.filter(u => u.stale).length))}</p>` : ''}
        <ul class="list">${d.history.map(h => `<li class="card static"><div class="body"><div class="l1"><span>${esc((T.audit[h.action] || (() => h.action))(h))}</span><span class="t">${esc(fmtDateTime(h.at))}</span></div>
            <div class="l2 wrap">${esc(h.by || C.system)}${h.meta.from && h.meta.to ? ` · ${esc(fValText(h.meta.from, a))} ← ${esc(fValText(h.meta.to, a))}` : ''}${h.reason ? ` · ${esc(h.reason)}` : ''}</div></div></li>`).join('')}</ul>`, null)).catch(toastError);
}

/** כרטיס של נתון אחד, עם הפעולות שמותרות לי. */
function factCard(f, a, pid, perms) {
    const live = f.state !== 'rejected', mayEdit = perms.write && (f.state !== 'verified' || perms.judge);   // נתון מאומת משנה רק מי שרשאי לאמת
    const btn = (act, label, cls = '') => `<button type="button" class="btn ghost sm${cls}" data-fact="${f.pid}" data-act="${act}">${label}</button>`;
    return `<li class="card static"><div class="body"><div class="l1"><span>${fVal(f, a)}</span></div>
        <div class="l2 wrap">${fSource(f, pid)}</div><div class="chips">${fChips(f)}</div>
        ${f.original ? `<p class="hint">${esc(F.original(fValText(f.original.num === null && f.original.text === null && f.original.date === null && f.original.bool === null ? null : f.original, a), f.corrected_by || C.system, fmtDate(f.corrected_at)))}${
            f.correction_note ? ' · ' + esc(f.correction_note) : ''}</p>` : ''}
        ${f.note ? `<p class="hint"><b>${F.note}:</b> ${esc(f.note)}</p>` : ''}
        ${f.rejected_reason ? `<p class="hint"><b>${F.rejectedWhy}:</b> ${esc(f.rejected_reason)}</p>` : ''}
        <p class="hint">${esc(f.state === 'verified' && f.verified_by ? F.verifiedBy(f.verified_by, fmtDate(f.verified_at)) : F.enteredBy(f.entered_by || C.system, fmtDate(f.entered_at)))}</p>
        <div class="row-btns wrap">${mayEdit && live ? btn('correct', F.correct) + (f.state === 'needs_clarification' ? (f.has_value ? btn('manual', F.backToManual) : '') : btn('clarify', F.clarify)) : ''}${
            perms.judge && live ? (f.state !== 'verified' && f.has_value ? btn('verify', F.verify) : '') + btn('reject', F.reject, ' danger') : ''}${
            perms.write || perms.judge ? btn('delete', F.del, ' danger') : ''}${btn('history', F.history)}</div></div></li>`;
}

/** מחבר את כפתורי הנתונים שבמסך. find(pid) ← [נתון, מאפיין]. */
function factsBind(find, x, reload) {
    cOn('[data-fact]', el => {
        const [f, a] = find(el.dataset.fact), act = el.dataset.act;
        // rev ישן: מציגים את שני הערכים ומרעננים; כל שגיאה אחרת — למי שקרא (הגיליון מציג אותה)
        const run = async (fn, mine = '') => { try { await fn(); reload(); } catch (e) { if (e.code !== 'fact_changed') throw e; fChangedToast(e, a, mine); reload(); } };
        const state = (doWhat, more, msg) => run(async () => { await api('fact-state', { body: { fact: f.pid, rev: f.rev, do: doWhat, ...more } }); toast(msg); });
        if (act === 'history') return factHistorySheet(f, a);
        if (act === 'verify') return state('verify', {}, F.verified).catch(toastError);
        if (act === 'manual') return state('manual', {}, F.saved).catch(toastError);
        if (act === 'reject') return reasonSheet(F.reject, F.rejectHint, reason => state('reject', { reason }, F.rejected), F.reject);
        if (act === 'clarify') return sheet(F.clarify, `<label class="f"><span>${F.clarifyHint}</span><textarea name="note" maxlength="1000" rows="3" required></textarea></label>`, v => state('clarify', { note: v.note }, F.saved), F.clarify);
        if (act === 'delete') return reasonSheet(F.del, F.delHint, reason => run(async () => { await api('fact-delete', { body: { fact: f.pid, rev: f.rev, reason } }); toast(F.deleted); }), F.del);
        if (act === 'correct') sheet(`${F.correct}: ${a.label}`, `${fValueFields(a, x, f.has_value ? f : { unit: f.unit, area_kind: f.area_kind })}
            <label class="switch"><input type="checkbox" name="estimated"${f.estimated ? ' checked' : ''}><span>${F.estimatedLabel}</span></label>
            <label class="f"><span>${F.correctionNote}</span><input name="correction_note" maxlength="500" dir="auto"></label>
            <label class="f"><span>${F.note}</span><textarea name="note" maxlength="1000" rows="2">${esc(f.note || '')}</textarea></label>`, v => {
            const body = fValueBody(a, v);
            return run(async () => { await fSend('fact-correct', { fact: f.pid, rev: f.rev, ...body, estimated: !!v.estimated, note: v.note, correction_note: v.correction_note }); toast(F.corrected); },
                body.value === null ? F.unknown : String(v.value));
        }, F.correct);
    });
}

// ── מאפיין: כל הערכים שנרשמו לו, כולל שנדחו ──
async function viewAttribute(pid, key) {
    const x = await api('case-facts', { params: { case: pid } });
    const a = x.attributes.find(t => t.key === key);
    if (!a) { location.replace(`#/cases/${pid}/facts`); return; }
    const reload = () => viewAttribute(pid, key);
    setHead(x.case.number, null);
    const open = a.conflicts.filter(c => c.status === 'open');
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one">${esc(a.label)}</b><span class="chip">${esc(F.groups[a.group])}</span></div>
          <div class="line">${a.value ? `${esc(F.agreed)}: ${fVal(a.value, a)}` : a.known ? '' : `<span class="muted">${esc(F.unknown)}</span>`}</div>
          <div class="chips">${a.example ? `<span class="chip">${esc(F.example)}</span>` : ''}${a.multi ? `<span class="chip">${esc(F.multi)}</span>` : ''}</div></div>
        ${open.map(c => `<p class="banner" role="note">⚠️ ${esc(F.conflictOpen)}: ${esc(F.conflictKinds[c.kind])} <a class="btn ghost sm" href="#/cases/${pid}/conflicts/${c.pid}">${F.decide}</a></p>`).join('')}
        ${x.perms.write && a.status === 'active' ? `<div class="toolbar"><button type="button" class="btn primary" id="fadd"><span aria-hidden="true">+</span> ${F.add}</button></div>` : ''}
        <h2 class="sec-title">${F.allValues} <small>${a.facts.length || ''}</small></h2>
        ${a.facts.length ? `<ul class="list">${a.facts.map(f => factCard(f, a, pid, x.perms)).join('')}</ul>` : `<p class="empty">${F.noValues}</p>`}`;
    $('#fadd')?.addEventListener('click', () => factAddSheet(pid, a, x, null, reload).catch(toastError));
    factsBind(fp => [a.facts.find(f => f.pid === fp), a], x, reload);
}

// ── סתירות ──
const kStatusChip = k => `<span class="chip ${k.status === 'open' ? 'bad' : k.status === 'resolved' ? 'ok' : ''}">${esc(F.conflictStatuses[k.status])}</span>`;

async function viewConflicts(pid) {
    const x = await api('case-conflicts', { params: { case: pid } });
    setHead(x.case.number, null);
    App.main.innerHTML = `<h2 class="sec-title">⚠️ ${F.conflicts} <small>${x.conflicts.length || ''}</small></h2>${x.conflicts.length ? `<ul class="list">${x.conflicts.map(k => `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/conflicts/${k.pid}">
        <div class="body"><div class="l1"><span>${esc(k.attribute.label)}</span></div>
        <div class="l2 wrap">${k.facts.filter(f => f.active).map(f => fVal(f, k.attribute)).join(' · ')}</div>
        <div class="chips">${kStatusChip(k)}<span class="chip">${esc(F.conflictKinds[k.kind])}</span>${k.needs_human ? `<span class="chip warn">${esc(F.needsHuman)}</span>` : ''}</div></div></li>`).join('')}</ul>` : `<p class="empty">${F.noConflicts}</p>`}`;
}

/** מסך ההכרעה (§6.3, 10-D2): הערכים זה לצד זה — ערך, סוג שטח, מצב ומקור; בחירה למטרה עם נימוק; "אין סתירה"; דחיית נתון. */
async function viewConflict(pid, kpid) {
    const x = await api('conflict', { params: { conflict: kpid } });
    const k = x.conflict, a = k.attribute, judge = x.perms.judge;
    const reload = () => viewConflict(pid, kpid);
    setHead(x.case.number, null);
    App.main.classList.add('wide');
    const col = f => f.deleted ? `<div class="panel off"><div class="line"><span class="big-val muted">${esc(F.deleted)}</span></div></div>`
        : `<div class="panel${f.active ? '' : ' off'}"><div class="line"><span class="big-val">${fVal(f, a)}</span></div>
        <div class="chips">${fChips(f)}${f.active ? '' : `<span class="chip">${esc(F.inactive)}</span>`}</div>
        <p class="hint"><b>${F.source}:</b> ${fSource(f, pid)}</p>
        ${f.note ? `<p class="hint"><b>${F.note}:</b> ${esc(f.note)}</p>` : ''}${f.rejected_reason ? `<p class="hint"><b>${F.rejectedWhy}:</b> ${esc(f.rejected_reason)}</p>` : ''}
        ${judge && f.active ? `<div class="row-btns wrap"><label class="switch"><input type="radio" name="pick" value="${f.pid}"><span>${F.pick}</span></label>
            <button type="button" class="btn ghost sm danger" data-fact="${f.pid}" data-act="reject">${F.reject}</button></div>` : ''}</div>`;
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one">${esc(a.label)}</b>${kStatusChip(k)}</div>
          <div class="chips"><span class="chip">${esc(F.conflictKinds[k.kind])}</span>${k.opened_count > 1 ? `<span class="chip">${esc(F.reopened(k.opened_count))}</span>` : ''}</div></div>
        ${k.kind === 'incomparable' && k.status === 'open' ? `<p class="banner" role="note">ℹ️ ${F.incomparableHint}</p>` : ''}
        ${k.needs_human ? `<p class="banner" role="note">⚠️ ${F.needsHuman}</p>` : ''}
        ${k.close_kind === 'auto' ? `<p class="banner" role="note">✔ ${F.closedAuto}</p>` : ''}
        ${k.status === 'dismissed' ? `<p class="banner" role="note">✔ ${F.dismiss}: ${esc(k.close_reason)} · ${esc(F.resolutionBy(k.closed_by || C.system, fmtDate(k.closed_at)))}</p>` : ''}
        <form id="kform"><div class="side-by-side">${k.facts.map(col).join('')}</div>
        ${judge && k.status !== 'dismissed' ? `<section class="group"><h3>${F.decide}</h3>
            <label class="f"><span>${F.purpose} *</span><input name="purpose" maxlength="120" list="kpurposes" dir="auto" required><datalist id="kpurposes">${F.purposes.map(p => `<option value="${esc(p)}">`).join('')}</datalist></label>
            <p class="hint">${F.purposeHint}</p>
            <label class="f"><span>${F.reasoning} *</span><textarea name="reasoning" maxlength="1000" rows="3" required></textarea></label>
            <p class="error" role="alert" hidden></p>
            <div class="row-btns wrap"><button type="submit" class="btn primary">${F.decide}</button>${k.status === 'open' ? `<button type="button" class="btn ghost" id="kdismiss">${F.dismiss}</button>` : ''}</div></section>` : ''}</form>
        <h2 class="sec-title">${F.resolutions} <small>${k.resolutions.length || ''}</small></h2>
        ${k.resolutions.length ? `<ul class="list">${k.resolutions.map(r => { const f = k.facts.find(t => t.pid === r.fact); return `<li class="card static"><div class="body">
            <div class="l1"><span>${esc(r.purpose)}</span><span class="t">${f && !f.deleted ? fVal(f, a) : ''}</span></div><div class="l2 wrap">${esc(r.reasoning)}</div>
            <div class="chips"><span class="chip">${esc(F.resolutionBy(r.by, fmtDate(r.at)))}</span>${r.valid ? '' : `<span class="chip warn">${esc(F.voided[r.void_reason] || '')}</span>`}</div></div></li>`; }).join('')}</ul>` : `<p class="empty">—</p>`}`;
    $('#kform').addEventListener('submit', async e => {
        e.preventDefault();
        const v = Object.fromEntries(new FormData(e.target)), err = $('.error', e.target);
        try {
            await api('conflict-resolve', { body: { conflict: kpid, rev: k.rev, purpose: v.purpose, fact: v.pick || null, reasoning: v.reasoning } });
            toast(F.decided);
            reload();
        } catch (x2) {
            if (!err) return toastError(x2);
            err.textContent = x2.message;
            err.hidden = false;
            if (x2.code === 'conflict_changed') reload();
        }
    });
    $('#kdismiss')?.addEventListener('click', () => {
        const act = k.facts.filter(f => f.active);
        sheet(F.dismiss, `<p class="hint">${F.dismissHint}</p><label class="f"><span>${F.reasoning} *</span><textarea name="reasoning" maxlength="1000" rows="3" required></textarea></label>
            ${a.has_area_kind ? act.map(f => `<label class="f"><span>${esc(fValText(f, a))} — ${F.areaKind}</span><select name="ak_${f.pid}">${x.area_kinds.map(t => cOpt(t, F.areaKinds[t], f.area_kind)).join('')}</select></label>`).join('') : ''}`, async v => {
            const fixes = a.has_area_kind ? act.filter(f => v['ak_' + f.pid] && v['ak_' + f.pid] !== f.area_kind).map(f => ({ fact: f.pid, rev: f.rev, area_kind: v['ak_' + f.pid] })) : [];
            await api('conflict-dismiss', { body: { conflict: kpid, rev: k.rev, reasoning: v.reasoning, fixes } });
            toast(F.dismissed);
            reload();
        }, F.dismiss);
    });
    factsBind(fp => [k.facts.find(f => f.pid === fp), a], x, reload);
}

// ── מסך הקובץ ← "נתונים מהמסמך" (10-D1): לחיצה על נתון מדפדפת לעמוד שבו הוא מופיע ──
async function fileFactsPanel(pid, fpid, goPage, reload) {
    const box = $('#file-facts');
    if (!box) return;
    let x;
    try { x = await api('file-facts', { params: { file: fpid } }); } catch { return; }
    const cat = await api('case-facts', { params: { case: pid } }), can = x.perms.write;
    box.innerHTML = `<h2 class="sec-title">🔎 ${F.fromFile} <small>${x.facts.length || ''}</small></h2>
        ${x.facts.length ? `<ul class="list">${x.facts.map(f => `<li class="card" tabindex="0" role="button" data-page="${f.source.page || ''}" title="${f.source.page ? esc(F.goPage) : ''}">
            <div class="body"><div class="l1"><span>${esc(f.label)}</span><span class="t">${f.source.page ? esc(F.page(f.source.page)) : ''}</span></div>
            <div class="l2 wrap">${fVal(f, cat.attributes.find(a => a.key === f.attribute))}${
                f.source.locator ? ` · <q dir="auto">${esc(f.source.locator)}</q>` : ''}</div>
            <div class="chips">${fChips(f)}</div></div></li>`).join('')}</ul>` : `<p class="empty">${F.noFileFacts}</p>`}
        ${can ? `<div class="f"><label class="f"><span>${F.addFromPage}</span><select id="ff-attr"><option value="">${F.addFact}…</option>${cat.attributes.filter(a => a.status === 'active').map(a => cOpt(a.key, `${F.groups[a.group]} · ${a.label}`, '')).join('')}</select></label>
            <label class="f"><span>${F.pageLabel}</span><input id="ff-page" inputmode="numeric" dir="ltr" maxlength="6" value="1"></label></div>` : ''}`;
    cOn('[data-page]', el => { if (el.dataset.page) { goPage(Number(el.dataset.page)); const p = $('#ff-page'); if (p) p.value = el.dataset.page; } }, box);
    $('#ff-attr')?.addEventListener('change', e => {
        const a = cat.attributes.find(t => t.key === e.target.value);
        e.target.value = '';
        if (a) factAddSheet(pid, a, cat, { file: fpid, page: $('#ff-page').value.trim() }, reload).catch(toastError);
    });
}

// ── הקטלוג (מנהל המערכת) ──
async function viewAttributes() {
    const d = await api('sys-attributes');
    const html = `<p class="hint">${F.catalogHint}</p><div class="toolbar"><button type="button" class="btn primary" id="attr-new"><span aria-hidden="true">+</span> ${F.newAttr}</button></div>
        ${d.groups.map(g => { const list = d.attributes.filter(a => a.group === g); return list.length ? `<h2 class="sec-title">${esc(F.groups[g])}</h2><ul class="list">${list.map(a => `<li class="card" tabindex="0" role="button" data-attr="${esc(a.key)}">
            <div class="body"><div class="l1"><span>${esc(a.label)}</span><span class="t" dir="ltr">${esc(a.key)}</span></div>
            <div class="chips"><span class="chip">${esc(F.valueTypes[a.value_type])}</span>${a.has_area_kind ? `<span class="chip">${esc(F.areaKind)}</span>` : ''}${a.multi ? `<span class="chip">${esc(F.multi)}</span>` : ''}${
                a.example ? `<span class="chip">${esc(F.example)}</span>` : ''}${a.in_use ? `<span class="chip info">${esc(F.inUse)}</span>` : ''}${a.status !== 'active' ? `<span class="chip warn">${esc(F.hidden)}</span>` : ''}</div></div></li>`).join('')}</ul>` : ''; }).join('')}`;
    const edit = a => {
        const isNew = !a, lock = a?.in_use ? ' disabled' : '';
        a = a || { key: '', label: '', group: 'features', value_type: 'text', unit_kind: 'none', has_area_kind: false, multi: false, decimals: 2, allow_negative: false, status: 'active', example: true, sort: 0, choices: null };
        sheet(isNew ? F.newAttr : a.label, `${isNew ? `<label class="f"><span>${F.key}</span><input name="key" maxlength="40" dir="ltr" autocomplete="off" required></label>` : ''}
            <label class="f"><span>${F.label}</span><input name="label" maxlength="60" value="${esc(a.label)}" required></label>
            <label class="f"><span>${F.group}</span><select name="group">${d.groups.map(g => cOpt(g, F.groups[g], a.group)).join('')}</select></label>
            <label class="f"><span>${F.valueType}</span><select name="value_type"${lock}>${Object.keys(F.valueTypes).map(t => cOpt(t, F.valueTypes[t], a.value_type)).join('')}</select></label>
            <label class="f"><span>${F.unitKind}</span><select name="unit_kind"${lock}>${Object.keys(F.unitKinds).map(t => cOpt(t, F.unitKinds[t], a.unit_kind)).join('')}</select></label>
            <label class="switch"><input type="checkbox" name="has_area_kind"${a.has_area_kind ? ' checked' : ''}${lock}><span>${F.hasAreaKind}</span></label>
            <label class="switch"><input type="checkbox" name="multi"${a.multi ? ' checked' : ''}${lock}><span>${F.multiLabel}</span></label>
            <label class="f"><span>${F.decimals}</span><select name="decimals">${[0, 1, 2].map(n => cOpt(String(n), String(n), String(a.decimals))).join('')}</select></label>
            <label class="switch"><input type="checkbox" name="allow_negative"${a.allow_negative ? ' checked' : ''}><span>${F.negative}</span></label>
            <label class="f"><span>${F.choices}</span><textarea name="choices" rows="4" dir="auto">${esc((a.choices || []).map(c => `${c.key} = ${c.label}`).join('\n'))}</textarea></label>
            <label class="f"><span>${F.sort}</span><input name="sort" inputmode="numeric" dir="ltr" maxlength="6" value="${esc(a.sort)}"></label>
            <label class="switch"><input type="checkbox" name="active"${a.status === 'active' ? ' checked' : ''}><span>${F.active}</span></label>
            <label class="switch"><input type="checkbox" name="example"${a.example ? ' checked' : ''}><span>${F.exampleLabel}</span></label>`, async v => {
            const type = v.value_type ?? a.value_type;
            const choices = type === 'choice' ? v.choices.split('\n').map(l => l.split('=')).filter(p => p[0].trim()).map(p => ({ key: p[0].trim(), label: (p.slice(1).join('=') || p[0]).trim() })) : undefined;
            await api('sys-attribute-save', { body: { key: isNew ? v.key.trim() : a.key, create: isNew, label: v.label, group: v.group, value_type: type, unit_kind: v.unit_kind ?? a.unit_kind,
                has_area_kind: a.in_use ? a.has_area_kind : !!v.has_area_kind, multi: a.in_use ? a.multi : !!v.multi, decimals: Number(v.decimals), allow_negative: !!v.allow_negative,
                status: v.active ? 'active' : 'hidden', example: !!v.example, sort: /^\d+$/.test(v.sort) ? Number(v.sort) : 0, ...(choices ? { choices } : {}) } });
            toast(T.saved);
            route();
        });
    };
    return { html, bind: () => { $('#attr-new').addEventListener('click', () => edit(null)); cOn('[data-attr]', el => edit(d.attributes.find(a => a.key === el.dataset.attr))); } };
}
