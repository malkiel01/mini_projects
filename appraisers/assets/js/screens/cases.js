/*
 * תיקים (אפיון 04): לוח ראשי, רשימה וחיפוש, אשף הפתיחה, כרטיס תיק (סקירה · גורמים והרשאות · דרישות וקבצים — screens/docs.js · פעילות),
 * המשימות שלי, ספר הכתובות, ושורות הניהול של מנהל המשרד.
 * כל ערך שמגיע ממשתמש עובר esc() לפני שהוא נכנס ל-HTML. ההרשאות נאכפות בשרת — המסך רק מסתיר מה שאין טעם להציג.
 */
'use strict';

const C = T.c;
const WZ = { pid: null, skip: {}, set: {}, add: [] };   // התאמות תוכנית העבודה באשף — עד "יצירה"
let SIMILAR = null;                                      // התרעת "מזהה דומה" — מוצגת עד הציור הבא של אותו תיק

const cOpt = (v, label, cur) => `<option value="${esc(v)}"${v === cur ? ' selected' : ''}>${esc(label)}</option>`;
const cStatusChip = s => `<span class="chip${s === 'closed' || s === 'draft' ? '' : ' info'}">${esc(C.statuses[s] || s)}</span>`;
const cUnknown = `<span class="muted">${C.unknown}</span>`;
const cPurpose = c => c.purpose ? esc(P.purposes[c.purpose] + (c.purpose === 'other' && c.purpose_note ? ' — ' + c.purpose_note : '')) : cUnknown;
const cOn = (sel, fn, root = document) => $$(sel, root).forEach(el => onActivate(el, e => fn(el, e)));
const cDateIn = iso => iso ? String(iso).slice(0, 10) : '';

function identText(i) {
    if (i.kind === 'parcel') return `גוש ${i.gush} חלקה ${i.helka}${i.tat_helka ? ' תת-חלקה ' + i.tat_helka : ''}`;
    if (i.kind === 'address' || i.kind === 'apartment') {
        return [[i.street, i.house_no].filter(Boolean).join(' '), i.apartment_no ? 'דירה ' + i.apartment_no : '', i.locality, i.municipality].filter(Boolean).join(', ');
    }
    return i.value_text || '';
}

function caseRow(c) {
    const marks = [c.open_mandatory ? `<span class="chip warn">${esc(C.openMandatory(c.open_mandatory))}</span>` : '', c.overdue_tasks ? `<span class="chip bad">${esc(C.overdue(c.overdue_tasks))}</span>` : ''].join('');
    return `<li class="card" tabindex="0" role="button" data-go="#/cases/${c.pid}${c.status === 'draft' ? '/setup' : ''}"><span class="use-ic" aria-hidden="true">📁</span>
        <div class="body"><div class="l1"><span>${esc(c.title || C.noTitle)}</span><span class="t" dir="ltr">${esc(c.number)}</span></div>
        <div class="l2 wrap">${cPurpose(c)} · ${c.responsible ? esc(c.responsible.name) : C.noResponsible}${c.due_date ? ' · ' + esc(C.due(fmtDate(c.due_date))) : ''}</div>
        <div class="chips">${cStatusChip(c.status)}${marks}</div></div></li>`;
}

function taskRow(t, withCase) {
    const late = t.due_at && ['open', 'in_progress'].includes(t.status) && new Date(t.due_at) < new Date();
    return `<li class="card" tabindex="0" role="button" data-task="${t.pid}"><span class="use-ic" aria-hidden="true">${t.status === 'done' ? '✅' : t.status === 'cancelled' ? '🚫' : '☑️'}</span>
        <div class="body"><div class="l1"><span>${esc(t.title)}</span>${t.due_at ? `<span class="t">${esc(fmtDate(t.due_at))}</span>` : ''}</div>
        <div class="l2 wrap">${withCase ? `<span dir="ltr">${esc(t.case.number)}</span> ${esc(t.case.title || '')}` : esc(t.assignee ? t.assignee.name : C.unassigned)}</div>
        <div class="chips">${t.status !== 'open' ? `<span class="chip">${esc(C.taskStatuses[t.status])}</span>` : ''}${late ? `<span class="chip bad">${esc(C.overdue(1).replace(/^1 /, ''))}</span>` : ''}${
            t.kind !== 'manual' && !withCase ? `<span class="chip">${esc(C.taskKinds[t.kind] || t.kind)}</span>` : ''}</div></div></li>`;
}

// ── לוח ראשי ──────────────────────────────────────────────────

async function viewDashboard(o) {
    const [t, cs, pd, rf] = await Promise.all([api('my-tasks'), api('cases'), api('docs-pending'), api('requests-failed')]);
    const tiles = [['#/cases', '📁', C.cases], ['#/tasks', '☑️', C.myTasks], ['#/contacts', '📇', C.contacts]];
    if (o.is_admin) tiles.push(['#/office/cases', '🗂️', C.officeCases]);
    App.main.innerHTML = `<p class="banner" role="note">⚠️ ${T.devEnv}</p>
        <div class="summary"><div class="line"><b class="one">${esc(o.name)}</b><span class="chip info">${esc(roleLabel(o.role, o.is_admin))}</span></div></div>
        <form class="toolbar" id="dsearch"><input type="search" name="q" placeholder="${C.search}" aria-label="${C.search}" style="flex:1">
          <a class="btn primary" href="#/cases/new"><span aria-hidden="true">+</span> ${C.newCase}</a></form>
        <h2 class="sec-title">☑️ ${C.myTasks} <small>${t.tasks.length || ''}</small></h2>
        ${t.tasks.length ? `<ul class="list">${t.tasks.slice(0, 5).map(x => taskRow(x, true)).join('')}</ul>${t.tasks.length > 5 ? `<p class="hint"><a href="#/tasks">${C.allTasks}</a></p>` : ''}` : `<p class="empty">${C.noMyTasks}</p>`}
        ${failedRequestsHtml(rf.failed)}${pendingDocsHtml(pd.pending)}
        <h2 class="sec-title">📁 ${C.myCases} <small>${cs.cases.length || ''}</small></h2>
        ${cs.cases.length ? `<ul class="list">${cs.cases.slice(0, 8).map(caseRow).join('')}</ul>${cs.cases.length > 8 ? `<p class="hint"><a href="#/cases">${C.allCases}</a></p>` : ''}` : `<p class="empty">${C.noCases}</p>`}
        <div class="tiles">${tiles.map(([h, i, x]) => `<a class="tile" href="${h}"><span class="tile-ic" aria-hidden="true">${i}</span>${x}</a>`).join('')}</div>${manageTiles()}`;
    $('#dsearch').addEventListener('submit', e => {
        e.preventDefault();
        sessionStorage.setItem('apr-cf', JSON.stringify({ q: e.target.q.value.trim() }));
        location.hash = '#/cases';
    });
    cOn('[data-task]', el => myTaskSheet(t.tasks.find(x => x.pid === el.dataset.task), route));
}

// ── רשימה וחיפוש ──────────────────────────────────────────────

async function viewCases(pid, sub, arg) {
    await pLists();
    if (pid === 'new') return viewCaseNew();
    if (pid && sub === 'reqs' && arg) return viewRequirement(pid, arg);
    if (pid && sub === 'file') return arg ? viewFile(pid, arg) : viewCase(pid, 'reqs');
    if (pid) return sub === 'setup' ? viewCaseSetup(pid, arg) : viewCase(pid, sub);
    setHead(C.cases, null);
    let f = {};
    try { f = JSON.parse(sessionStorage.getItem('apr-cf') || '{}') || {}; } catch { f = {}; }
    const [d, ds] = await Promise.all([api('cases', { params: f }), f.q ? api('docs-search', { params: { q: f.q } }) : { results: [] }]);
    const n = Object.keys(f).filter(k => k !== 'q' && f[k]).length;
    App.main.innerHTML = `<form class="toolbar" id="csearch"><input type="search" name="q" value="${esc(f.q || '')}" placeholder="${C.search}" aria-label="${C.search}" style="flex:1">
          <button type="button" class="btn ghost" id="cfilter">${n ? esc(C.filtersOn(n)) : C.filters}</button></form>
        <div class="toolbar"><a class="btn primary" href="#/cases/new"><span aria-hidden="true">+</span> ${C.newCase}</a></div>
        ${d.cases.length ? `<ul class="list">${d.cases.map(caseRow).join('')}</ul>` : `<p class="empty">${f.q || n ? C.noResults : C.noCases}</p>`}${docResultsHtml(ds.results)}`;
    const setF = nf => { sessionStorage.setItem('apr-cf', JSON.stringify(nf)); viewCases(); };
    $('#csearch').addEventListener('submit', e => { e.preventDefault(); setF({ ...f, q: e.target.q.value.trim() }); });
    $('#csearch').q.addEventListener('search', e => { if (!e.target.value && f.q) setF({ ...f, q: '' }); });
    $('#cfilter').addEventListener('click', async () => {
        const [o, ct] = await Promise.all([api('case-options'), api('contacts')]);
        sheet(C.filters, `<label class="f"><span>${C.fStatus}</span><select name="status"><option value="">${C.any}</option>${o.statuses.map(s => cOpt(s, C.statuses[s], f.status)).join('')}</select></label>
            <label class="f"><span>${C.fResponsible}</span><select name="responsible"><option value="">${C.any}</option>${o.members.map(m => cOpt(m.pid, m.name, f.responsible)).join('')}</select></label>
            <label class="f"><span>${C.fType}</span><select name="property_type"><option value="">${C.any}</option>${PL.property_types.map(v => cOpt(v, P.propertyTypes[v], f.property_type)).join('')}</select></label>
            <label class="f"><span>${C.fOrderer}</span><select name="orderer"><option value="">${C.any}</option>${ct.contacts.map(c => cOpt(c.pid, c.name, f.orderer)).join('')}</select></label>
            <label class="f"><span>${C.fDue}</span><input type="date" name="due_before" value="${esc(f.due_before || '')}"></label>
            <label class="switch"><input type="checkbox" name="open_mandatory" value="1"${f.open_mandatory ? ' checked' : ''}><span>${C.fMandatory}</span></label>
            <label class="switch"><input type="checkbox" name="closed" value="1"${f.closed ? ' checked' : ''}><span>${C.fClosed}</span></label>
            <div class="row-btns"><button type="button" class="btn ghost" id="fclear">${C.clear}</button></div>`,
            async v => { setF(Object.fromEntries(Object.entries({ q: f.q || '', ...v }).filter(([, x]) => x))); }, C.apply);
        $('#fclear').addEventListener('click', () => { $('#sheet').close(); setF({ q: f.q || '' }); });
    });
}

// ── אשף הפתיחה ────────────────────────────────────────────────

const wzHead = n => `<p class="hint">${esc(C.stepOf(n, C.steps[n - 1]))}</p><div class="track" aria-hidden="true"><span style="width:${n * 20}%"></span></div>`;

/** שלב 1 של תיק חדש: פרופיל ושמאי אחראי — זה כל מה שטיוטה צריכה. הבחירה יוצרת את הטיוטה, והמספר מוקצה. */
async function viewCaseNew() {
    setHead(C.newCase, null);
    const [pl, o] = await Promise.all([api('profiles', { params: { pick: '1' } }), api('case-options')]);
    let list = [...pl.office, ...pl.mine, ...pl.shared, ...pl.recommended];
    const req = o.required_profile && list.find(p => p.pid === o.required_profile);
    if (req) list = [req];
    const apps = o.members.filter(m => m.role === 'appraiser');
    const me = apps.find(m => m.me);
    App.main.innerHTML = `${wzHead(1)}
        ${!apps.length ? `<p class="banner bad" role="alert">${C.noAppraiser}</p>` : `<label class="f"><span>${C.responsible}</span><select id="wresp">${apps.map(m => cOpt(m.pid, m.name + (m.me ? ` (${C.me})` : ''), me ? me.pid : apps[0].pid)).join('')}</select></label>`}
        ${req ? `<p class="banner" role="note">🔒 ${C.requiredProfile}</p>` : ''}
        <h2 class="sec-title">📑 ${C.pickProfile}</h2>
        ${list.length ? `<ul class="list">${list.map(p => `<li class="card" tabindex="0" role="button" data-pick="${p.pid}"><span class="use-ic" aria-hidden="true">📑</span>
            <div class="body"><div class="l1"><span>${esc(p.name)}</span><span class="t">${esc(P.versionN(p.version.number))}</span></div>
            <div class="l2 wrap">${esc(p.description || '')}</div><div class="chips">${scopeChip(p)}</div></div></li>`).join('')}</ul>`
            : `<p class="empty">${C.noProfiles}</p><div class="row-btns"><a class="btn ghost" href="#/profiles">${C.toProfiles}</a></div>`}`;
    const op = opId();
    let busy = false;
    cOn('[data-pick]', async el => {
        if (busy || !apps.length) return;
        busy = true;
        try {
            const r = await api('case-create', { body: { profile: el.dataset.pick, responsible: $('#wresp').value, op } });
            location.hash = `#/cases/${r.case}/setup/2`;
        } catch (x) { toastError(x); busy = false; }
    });
}

async function viewCaseSetup(pid, stepArg) {
    const d = await api('case', { params: { case: pid } });
    if (d.case.status !== 'draft') { location.replace('#/cases/' + pid); return; }
    if (WZ.pid !== pid) Object.assign(WZ, { pid, skip: {}, set: {}, add: [] });
    const n = Math.min(5, Math.max(1, parseInt(stepArg, 10) || 2));
    const c = d.case, canEdit = !!d.me.perms.edit;
    setHead(`${C.newCase} ${c.number}`, null);
    const go = k => { location.hash = `#/cases/${pid}/setup/${k}`; };
    const redraw = () => viewCaseSetup(pid, n);
    let body = '', save = async () => {};
    if (n === 1) {
        body = `<div class="summary"><div class="line"><b>${C.profileTitle}</b><span>${esc(C.profileLine(d.profile.name, d.profile.version))}</span></div>
            <div class="line"><b>${C.responsible}</b><span>${c.responsible ? esc(c.responsible.name) : C.noResponsible}</span></div></div>
            ${canEdit ? `<div class="row-btns"><button type="button" class="btn danger" id="wdel">${C.deleteDraft}</button></div>` : ''}`;
    } else if (n === 2) {
        body = `<form class="panel" id="wform">${caseDetailFields(d, ['purpose', 'dates', 'flags'])}</form>`;
        save = () => caseDetailSave(pid, $('#wform'), ['purpose', 'dates', 'flags']);
    } else if (n === 3) {
        body = `<form class="panel" id="wform">${caseDetailFields(d, ['title', 'type'])}</form>${identsHtml(d, canEdit)}`;
        save = () => caseDetailSave(pid, $('#wform'), ['title', 'type']);
    } else if (n === 4) {
        body = `<div class="summary"><div class="line"><b>${C.responsible}</b><span>${c.responsible ? esc(c.responsible.name) : C.noResponsible}</span></div></div>${partiesHtml(d, canEdit)}${membersHtml(d)}`;
    } else {
        body = '<div id="wplan"></div>';
    }
    App.main.innerHTML = `${wzHead(n)}${body}<p class="error" role="alert" id="werr" hidden></p>
        <div class="row-btns wrap">${n > 1 ? `<button type="button" class="btn ghost" id="wprev">${C.prev}</button>` : ''}
          <button type="button" class="btn ghost" id="wdraft">${C.saveDraft}</button>
          ${n < 5 ? `<button type="button" class="btn primary" id="wnext">${C.next}</button>` : canEdit ? `<button type="button" class="btn primary" id="wcreate">${C.create}</button>` : ''}</div>`;
    const guard = fn => async () => {
        const err = $('#werr');
        err.hidden = true;
        try { if (canEdit) await save(); await fn(); } catch (x) { err.textContent = x.message; err.hidden = false; }
    };
    $('#wprev')?.addEventListener('click', guard(async () => go(n - 1)));
    $('#wnext')?.addEventListener('click', guard(async () => go(n + 1)));
    $('#wdraft').addEventListener('click', guard(async () => { toast(C.draftSaved); location.hash = '#/cases'; }));
    $('#wdel')?.addEventListener('click', async () => {
        if (!confirm(C.deleteAsk)) return;
        try { await api('case-delete', { body: { case: pid } }); toast(C.deleted); location.hash = '#/cases'; } catch (x) { toastError(x); }
    });
    if (n === 3) identsBind(d, canEdit, async () => { if (canEdit) await save().catch(() => {}); redraw(); });
    if (n === 4) { partiesBind(d, canEdit, redraw); membersBind(d, redraw); }
    if (n === 5) await wzPlan(pid, d, canEdit);
}

/** שלב 5: מה ייווצר — אותו מנוע של תצוגת הניסיון, עם התאמות לפני היצירה. */
async function wzPlan(pid, d, canEdit) {
    const r = await api('case-plan', { params: { case: pid } });
    const box = $('#wplan'), plan = r.plan;
    const stepOf = { purpose: 2, identifier: 3, orderer: 4 };
    const draw = () => {
        box.innerHTML = `${r.missing.length ? `<div class="banner bad" role="alert"><b>${C.missingTitle}</b>${r.missing.map(m => ` <a href="#/cases/${pid}/setup/${stepOf[m]}">${esc(C.missing[m])}</a>`).join(' ·')}</div>` : ''}
            ${plan.alerts.map(a => `<p class="banner" role="note">🔔 ${esc(a.text)} <small>(${esc(C.byRule(a.rule.title || a.rule.key))})</small></p>`).join('')}
            <p class="hint">${C.planHint}</p>
            <h2 class="sec-title">📄 ${C.tabs.reqs} <small>${plan.requirements.length + WZ.add.length}</small></h2>
            ${plan.requirements.length || WZ.add.length ? `<ul class="list">${plan.requirements.map(q => {
                const s = WZ.set[q.key] || {}, off = q.key in WZ.skip;
                return `<li class="card static"><div class="body"><div class="l1"><span${off ? ' class="muted"' : ''}>${esc(q.title)}</span></div>
                    <div class="chips">${q.mandatory ? `<span class="chip warn">${C.mandatory}</span>` : ''}${q.rule ? `<span class="chip">${esc(C.byRule(q.rule.title || q.rule.key))}</span>` : ''}${
                        off ? `<span class="chip bad">${C.skipped}: ${esc(WZ.skip[q.key])}</span>` : ''}</div>
                    ${off || !canEdit ? '' : `<div class="fields two"><label class="f"><span>${C.priority}</span><select data-set="priority" data-key="${esc(q.key)}">${PL.priorities.map(v => cOpt(v, P.priorities[v], s.priority || q.priority)).join('')}</select></label>
                        <label class="f"><span>${C.supplier}</span><select data-set="party_kind" data-key="${esc(q.key)}">${PL.party_kinds.map(v => cOpt(v, P.partyKinds[v], s.party_kind || q.party_kind)).join('')}</select></label></div>`}</div>
                    ${canEdit ? `<button type="button" class="btn ghost sm" data-skip="${esc(q.key)}">${off ? C.unskip : C.skip}</button>` : ''}</li>`;
            }).join('')}${WZ.add.map((a, i) => `<li class="card static"><div class="body"><div class="l1"><span>${esc(a.title)}</span></div>
                <div class="chips"><span class="chip">${C.reqOrigins.manual}</span>${a.mandatory ? `<span class="chip warn">${C.mandatory}</span>` : ''}</div></div>
                <button type="button" class="btn ghost sm" data-unadd="${i}">${C.remove}</button></li>`).join('')}</ul>` : `<p class="empty">${C.planEmpty}</p>`}
            ${canEdit ? `<div class="row-btns"><button type="button" class="btn ghost" id="wadd"><span aria-hidden="true">+</span> ${C.addRequirement}</button></div>` : ''}
            <h2 class="sec-title">☑️ ${C.tasksTitle} <small>${plan.tasks.length}</small></h2>
            ${plan.tasks.length ? `<ul class="list">${plan.tasks.map(t => `<li class="card static"><div class="body"><div class="l1"><span>${esc(t.title)}</span></div>
                <div class="l2">${esc(P.assignees[t.assignee] || '')}${t.rule ? ' · ' + esc(C.byRule(t.rule.title || t.rule.key)) : ''}</div></div></li>`).join('')}</ul>` : `<p class="empty">${C.noTasks}</p>`}`;
        $$('[data-set]', box).forEach(s => s.addEventListener('change', () => { (WZ.set[s.dataset.key] ||= {})[s.dataset.set] = s.value; }));
        $$('[data-skip]', box).forEach(b => b.addEventListener('click', () => {
            const key = b.dataset.skip;
            if (key in WZ.skip) { delete WZ.skip[key]; draw(); return; }
            sheet(C.skip, `<label class="f"><span>${C.skipReason}</span><textarea name="reason" required maxlength="500" rows="3"></textarea></label>`, async f => {
                if (!f.reason.trim()) throw new Error(C.skipReason);
                WZ.skip[key] = f.reason.trim();
                draw();
            });
        }));
        $$('[data-unadd]', box).forEach(b => b.addEventListener('click', () => { WZ.add.splice(+b.dataset.unadd, 1); draw(); }));
        $('#wadd', box)?.addEventListener('click', () => requirementSheet(null, async v => { WZ.add.push(v); draw(); }));
    };
    draw();
    $('#wcreate')?.addEventListener('click', async e => {
        const err = $('#werr'), btn = e.currentTarget;
        err.hidden = true;
        btn.disabled = true;
        try {
            await api('case-start', { body: { case: pid, plan: { skip: WZ.skip, set: WZ.set, add: WZ.add } } });
            Object.assign(WZ, { pid: null, skip: {}, set: {}, add: [] });
            toast(C.created);
            location.hash = '#/cases/' + pid;
        } catch (x) {
            err.textContent = x.code === 'case_incomplete' && x.missing ? `${C.missingTitle} ${x.missing.map(m => C.missing[m]).join(', ')}` : x.message;
            err.hidden = false;
            btn.disabled = false;
        }
    });
}

// ── פרטי התיק (בשימוש האשף וכרטיס התיק) ───────────────────────

function caseDetailFields(d, parts) {
    const c = d.case, has = p => parts.includes(p);
    return `${has('title') ? `<label class="f"><span>${C.title}</span><input name="title" maxlength="200" value="${esc(c.title)}"></label>` : ''}
        ${has('purpose') ? `<label class="f"><span>${C.purpose}</span><select name="purpose"><option value="">${C.unknown}</option>${PL.purposes.map(v => cOpt(v, P.purposes[v], c.purpose)).join('')}</select></label>
          <label class="f"><span>${C.purposeNote}</span><input name="purpose_note" maxlength="300" value="${esc(c.purpose_note || '')}"></label>` : ''}
        ${has('type') ? `<label class="f"><span>${C.propertyType}</span><select name="property_type"><option value="">${C.unknown}</option>${PL.property_types.map(v => cOpt(v, P.propertyTypes[v], c.property_type)).join('')}</select></label>` : ''}
        ${has('dates') ? `<div class="fields two"><label class="f"><span>${C.keyDate}</span><input type="date" name="key_date" value="${esc(c.key_date || '')}"></label>
          <label class="f"><span>${C.dueDate}</span><input type="date" name="due_date" value="${esc(c.due_date || '')}"></label></div><p class="hint">${C.emptyIsUnknown}</p>` : ''}
        ${has('flags') && d.profile.flags.length ? `<div class="f"><span>${C.flags}</span>${d.profile.flags.map(f => `<label class="switch"><input type="checkbox" data-flag="${esc(f.key)}"${c.flags[f.key] ? ' checked' : ''}><span>${esc(f.question || f.title || f.key)}</span></label>`).join('')}</div>` : ''}`;
}

function caseDetailSave(pid, form, parts) {
    const has = p => parts.includes(p), b = { case: pid };
    const el = form.elements;   // לא form.title — זו תכונה של האלמנט עצמו
    if (has('title')) b.title = el.title.value;
    if (has('purpose')) { b.purpose = el.purpose.value || null; b.purpose_note = el.purpose_note.value; }
    if (has('type')) b.property_type = el.property_type.value || null;
    if (has('dates')) { b.key_date = el.key_date.value || null; b.due_date = el.due_date.value || null; }
    if (has('flags') && $('[data-flag]', form)) b.flags = Object.fromEntries($$('[data-flag]', form).map(x => [x.dataset.flag, x.checked]));
    return api('case-update', { body: b });
}

// ── מזהי נכס ──────────────────────────────────────────────────

function identsHtml(d, canEdit) {
    const sim = SIMILAR && SIMILAR.pid === d.case.pid ? SIMILAR.text : '';
    SIMILAR = null;
    return `<h2 class="sec-title">📍 ${C.identifiers} <small>${d.identifiers.length || ''}</small></h2>
        ${sim ? `<p class="banner" role="alert">⚠️ ${esc(sim)}</p>` : ''}
        ${d.identifiers.length ? `<ul class="list">${d.identifiers.map(i => `<li class="card${canEdit ? '' : ' static'}"${canEdit ? ` tabindex="0" role="button" data-ident="${i.pid}"` : ''}><span class="use-ic" aria-hidden="true">📍</span>
            <div class="body"><div class="l1"><span>${esc(identText(i))}</span></div>
            <div class="l2 wrap">${esc(C.identKinds[i.kind])} · ${C.source}: ${esc(C.sources[i.source_kind])}${i.source_note ? ' — ' + esc(i.source_note) : ''}${i.note ? ' · ' + esc(i.note) : ''}</div>
            <div class="chips"><span class="chip ${i.state === 'verified' ? 'ok' : i.state === 'conflicting' ? 'bad' : i.state === 'needs_check' ? 'warn' : ''}">${esc(C.states[i.state])}</span></div></div></li>`).join('')}</ul>`
            : `<p class="empty">${C.noIdentifiers}</p>`}
        ${canEdit ? `<div class="row-btns wrap"><button type="button" class="btn ghost" id="iadd"><span aria-hidden="true">+</span> ${C.addIdentifier}</button>
            ${d.identifiers.length > 1 ? `<button type="button" class="btn ghost" id="iconflict">${C.conflictBtn}</button>` : ''}</div>` : ''}`;
}

function identsBind(d, canEdit, reload) {
    if (!canEdit) return;
    const pid = d.case.pid;
    $('#iadd')?.addEventListener('click', () => {
        const dlg = sheet(C.addIdentifier, `<label class="f"><span>${C.identKind}</span><select name="kind">${['address', 'parcel', 'apartment', 'building_file', 'other'].map(k => cOpt(k, C.identKinds[k], 'address')).join('')}</select></label>
            <div data-for="address apartment"><div class="fields two"><label class="f"><span>${C.locality}</span><input name="locality" maxlength="80"></label><label class="f"><span>${C.street}</span><input name="street" maxlength="80"></label></div>
              <div class="fields two"><label class="f"><span>${C.houseNo}</span><input name="house_no" maxlength="80"></label><label class="f"><span>${C.apartmentNo}</span><input name="apartment_no" maxlength="80"></label></div></div>
            <div data-for="parcel"><div class="fields two"><label class="f"><span>${C.gush}</span><input name="gush" inputmode="numeric" maxlength="8"></label><label class="f"><span>${C.helka}</span><input name="helka" inputmode="numeric" maxlength="8"></label></div>
              <label class="f"><span>${C.tatHelka}</span><input name="tat_helka" inputmode="numeric" maxlength="8"></label><p class="hint">${C.aptNotTat}</p></div>
            <div data-for="building_file other"><label class="f"><span>${C.valueText}</span><input name="value_text" maxlength="200"></label></div>
            <label class="f"><span>${C.sourceNote}</span><input name="source_note" maxlength="120"></label>
            <label class="f"><span>${C.note}</span><input name="note" maxlength="300"></label>`, async f => {
            const r = await api('case-identifier-add', { body: { case: pid, ...f } });
            if (r.similar) SIMILAR = { pid, text: r.similar.cases.length ? C.similarMine(r.similar.cases.map(x => x.number).join(', ')) : C.similarOther };
            reload();
        }, C.add);
        const sync = () => $$('[data-for]', dlg).forEach(g => { g.hidden = !g.dataset.for.split(' ').includes(dlg.querySelector('[name=kind]').value); });
        dlg.querySelector('[name=kind]').addEventListener('change', sync);
        sync();
    });
    $('#iconflict')?.addEventListener('click', async () => {
        if (!confirm(C.conflictAsk)) return;
        try { await api('case-identifiers-conflict', { body: { case: pid } }); toast(C.conflictDone); reload(); } catch (x) { toastError(x); }
    });
    cOn('[data-ident]', async el => {
        const i = d.identifiers.find(x => x.pid === el.dataset.ident);
        // מקור "מסמך" הוא קובץ של התיק (04-C3) — בוחרים אותו מהרשימה
        const x = await api('case-docs', { params: { case: pid } }).catch(() => ({ requirements: [], unassigned: [] }));
        const files = [...new Map([...x.requirements.flatMap(r => r.files.map(l => l.file)), ...x.unassigned].filter(f => !f.deleted).map(f => [f.pid, f])).values()];
        sheet(identText(i), `<label class="f"><span>${C.state}</span><select name="state">${Object.keys(C.states).map(k => cOpt(k, C.states[k], i.state)).join('')}</select></label>
            <label class="f"><span>${C.source}</span><select name="source_kind">${Object.keys(C.sources).map(k => cOpt(k, C.sources[k], i.source_kind)).join('')}</select></label>
            <label class="f" id="isrcfile"><span>${D.sourceFile}</span><select name="source_ref"><option value="">${files.length ? D.pickFile : D.noFilesYet}</option>${files.map(f => cOpt(f.pid, f.name, i.source_ref)).join('')}</select></label>
            <p class="hint">${D.sourceFileHint}</p>
            <label class="f"><span>${C.sourceNote}</span><input name="source_note" maxlength="120" value="${esc(i.source_note || '')}"></label>
            <label class="f"><span>${C.note}</span><input name="note" maxlength="300" value="${esc(i.note || '')}"></label>
            <div class="row-btns"><button type="button" class="btn danger" id="irm">${C.remove}</button></div>`, async f => {
            await api('case-identifier-update', { body: { case: pid, identifier: i.pid, ...f, source_ref: f.source_kind === 'document' ? f.source_ref : null } });
            reload();
        });
        const srcSel = $('#sheet [name=source_kind]'), srcSync = () => { $('#isrcfile').hidden = srcSel.value !== 'document'; };
        srcSel.addEventListener('change', srcSync);
        srcSync();
        $('#irm').addEventListener('click', async () => {
            if (!confirm(C.removeAsk)) return;
            try { await api('case-identifier-remove', { body: { case: pid, identifier: i.pid } }); $('#sheet').close(); reload(); } catch (x) { toastError(x); }
        });
    });
}

// ── גורמים ומשתתפים ───────────────────────────────────────────

function partiesHtml(d, canEdit) {
    return `<h2 class="sec-title">📇 ${C.parties} <small>${d.parties.length || ''}</small></h2>
        ${d.parties.length ? `<ul class="list">${d.parties.map(p => `<li class="card${canEdit ? '' : ' static'}"${canEdit ? ` tabindex="0" role="button" data-party-row="${p.pid}"` : ''}><span class="use-ic" aria-hidden="true">👤</span>
            <div class="body"><div class="l1"><span>${esc(p.contact.name)}</span></div>
            <div class="l2 wrap" dir="auto">${esc([p.contact.org, p.contact.phone, p.contact.email, p.note].filter(Boolean).join(' · '))}</div>
            <div class="chips"><span class="chip">${esc(C.partyKinds[p.kind])}</span>${p.is_orderer ? `<span class="chip info">${C.orderer}</span>` : ''}</div></div></li>`).join('')}</ul>` : `<p class="empty">${C.noParties}</p>`}
        ${canEdit ? `<div class="row-btns"><button type="button" class="btn ghost" id="padd"><span aria-hidden="true">+</span> ${C.addParty}</button></div>` : ''}`;
}

function partiesBind(d, canEdit, reload) {
    if (!canEdit) return;
    const pid = d.case.pid;
    $('#padd')?.addEventListener('click', async () => {
        const ct = await api('contacts');
        const dlg = sheet(C.addParty, `<label class="f"><span>${C.partyKind}</span><select name="kind">${Object.keys(C.partyKinds).map(k => cOpt(k, C.partyKinds[k], 'client')).join('')}</select></label>
            <label class="f"><span>${C.fromContacts}</span><select name="contact"><option value="">${C.newContact}</option>${ct.contacts.map(c => cOpt(c.pid, c.name + (c.org ? ' · ' + c.org : ''))).join('')}</select></label>
            <div id="pnew">${contactFields({})}</div>
            <label class="switch"><input type="checkbox" name="is_orderer"${d.parties.some(p => p.is_orderer) ? '' : ' checked'}><span>${C.isOrderer}</span></label>`, async f => {
            const b = { case: pid, kind: f.kind, is_orderer: f.is_orderer === 'on' };
            await api('case-party-add', { body: f.contact ? { ...b, contact: f.contact } : { ...b, name: f.name, org: f.org, email: f.email, phone: f.phone } });
            reload();
        }, C.add);
        const sel = dlg.querySelector('[name=contact]');
        sel.addEventListener('change', () => { $('#pnew', dlg).hidden = !!sel.value; });
    });
    cOn('[data-party-row]', el => {
        const p = d.parties.find(x => x.pid === el.dataset.partyRow);
        sheet(p.contact.name, `<label class="switch"><input type="checkbox" name="is_orderer"${p.is_orderer ? ' checked' : ''}><span>${C.isOrderer}</span></label>
            <label class="f"><span>${C.note}</span><input name="note" maxlength="300" value="${esc(p.note || '')}"></label>
            <div class="row-btns"><button type="button" class="btn danger" id="prm">${C.remove}</button></div>`, async f => {
            await api('case-party-update', { body: { case: pid, party: p.pid, is_orderer: f.is_orderer === 'on', note: f.note } });
            reload();
        });
        $('#prm').addEventListener('click', async () => {
            if (!confirm(C.removeAsk)) return;
            try { await api('case-party-remove', { body: { case: pid, party: p.pid } }); $('#sheet').close(); reload(); } catch (x) { toastError(x); }
        });
    });
}

function membersHtml(d) {
    const can = !!d.me.perms.members;
    return `<h2 class="sec-title">👥 ${C.members} <small>${d.members.length}</small></h2>
        <ul class="list">${d.members.map(m => `<li class="card${can && m.role !== 'responsible' ? '' : ' static'}"${can && m.role !== 'responsible' ? ` tabindex="0" role="button" data-member="${m.pid}"` : ''}>
            <span class="avatar" aria-hidden="true" style="--h:${hue(m.name)}">${esc(initials(m.name))}</span>
            <div class="body"><div class="l1"><span>${esc(m.name)}${m.me ? ` (${C.me})` : ''}</span></div>
            <div class="l2">${esc(T.roles[m.office_role] || '')}</div>
            <div class="chips"><span class="chip${m.role === 'responsible' ? ' info' : ''}">${esc(C.memberRoles[m.role])}</span></div></div></li>`).join('')}</ul>
        ${can ? `<div class="row-btns"><button type="button" class="btn ghost" id="madd"><span aria-hidden="true">+</span> ${C.addMember}</button></div>` : ''}`;
}

function membersBind(d, reload) {
    if (!d.me.perms.members) return;
    const pid = d.case.pid, roles = ['partner', 'visitor', 'staff'];
    $('#madd')?.addEventListener('click', async () => {
        const o = await api('case-options');
        const free = o.members.filter(m => !d.members.some(x => x.pid === m.pid));
        if (!free.length) { toast(C.noMoreMembers); return; }
        sheet(C.addMember, `<label class="f"><span>${C.addMember}</span><select name="user">${free.map(m => cOpt(m.pid, `${m.name} · ${T.roles[m.role] || ''}`)).join('')}</select></label>
            <label class="f"><span>${C.memberRole}</span><select name="role"><option value="">${C.roleDefault}</option>${roles.map(r => cOpt(r, C.memberRoles[r])).join('')}</select></label>`, async f => {
            await api('case-member-add', { body: { case: pid, user: f.user, ...(f.role ? { role: f.role } : {}) } });
            reload();
        }, C.add);
    });
    cOn('[data-member]', el => {
        const m = d.members.find(x => x.pid === el.dataset.member);
        const boss = d.me.role === 'responsible';   // תפקיד והרשאות — רק השמאי האחראי; הרשאת members מוסיפה ומסירה בלבד
        const fields = boss ? `<label class="f"><span>${C.memberRole}</span><select name="role">${roles.map(r => cOpt(r, C.memberRoles[r], m.role)).join('')}</select></label>
            <div class="f"><span>${C.permsTitle}</span>${Object.keys(C.perms).filter(k => k !== 'view' && k !== 'tasks').map(k =>
                `<label class="switch"><input type="checkbox" data-perm="${k}"${m.perms[k] ? ' checked' : ''}><span>${C.perms[k]}</span></label>`).join('')}
              <label class="f"><span>${C.perms.tasks}</span><select data-perm-tasks>${cOpt('no', T.p.no, m.perms.tasks === true ? 'all' : m.perms.tasks === 'own' ? 'own' : 'no')}${
                  cOpt('own', C.tasksOwn, m.perms.tasks === 'own' ? 'own' : '')}${cOpt('all', T.p.yes, m.perms.tasks === true ? 'all' : '')}</select></label></div>`
            : `<p class="hint">${esc(C.memberRoles[m.role])} · ${C.permsOnlyResponsible}</p>`;
        const dlg = sheet(m.name, `${fields}
            <div class="row-btns wrap">${boss && m.office_role === 'appraiser' ? `<button type="button" class="btn ghost" id="mresp">${C.makeResponsible}</button>` : ''}
              <button type="button" class="btn danger" id="mrm">${C.remove}</button></div>`, boss ? async f => {
            const roleChanged = f.role !== m.role;
            const tv = dlg.querySelector('[data-perm-tasks]').value;
            const perms = { ...Object.fromEntries(Array.from(dlg.querySelectorAll('[data-perm]')).map(x => [x.dataset.perm, x.checked])), tasks: tv === 'all' ? true : tv === 'own' ? 'own' : false };
            // שינוי תפקיד מחזיר את ההרשאות לברירת המחדל שלו; בלי שינוי תפקיד — נשמרות התיבות
            await api('case-member-update', { body: roleChanged ? { case: pid, user: m.pid, role: f.role } : { case: pid, user: m.pid, perms } });
            reload();
        } : null);
        dlg.querySelector('#mresp')?.addEventListener('click', async () => {
            const reason = (prompt(C.makeResponsibleAsk(m.name) + '\n\n' + C.reasonReq) || '').trim();   // העברת אחריות — תמיד עם נימוק
            if (!reason) return;
            try { await api('case-member-update', { body: { case: pid, user: m.pid, role: 'responsible', reason } }); dlg.close(); reload(); } catch (x) { toastError(x); }
        });
        dlg.querySelector('#mrm').addEventListener('click', async () => {
            if (!confirm(C.removeMemberAsk(m.name))) return;
            try { await api('case-member-remove', { body: { case: pid, user: m.pid } }); dlg.close(); reload(); } catch (x) { toastError(x); }
        });
    });
}

// ── דרישות ומשימות ────────────────────────────────────────────

/** גיליון דרישה: חדשה (r ריק — מחזיר את הערכים ל-onSave) או עריכה של קיימת. */
function requirementSheet(r, onSave, d) {
    const edit = !!r;
    sheet(edit ? r.title : C.addRequirement, `<label class="f"><span>${C.reqTitle}</span><input name="title" required maxlength="160" value="${esc(r?.title || '')}"></label>
        ${edit ? '' : `<label class="f"><span>${C.docType}</span><select name="doc_type">${PL.doc_types.map(t => cOpt(t.key, t.name, 'other')).join('')}</select></label>
          <label class="f"><span>${C.supplier}</span><select name="party_kind">${PL.party_kinds.map(v => cOpt(v, P.partyKinds[v], 'client')).join('')}</select></label>`}
        <label class="f"><span>${C.priority}</span><select name="priority">${PL.priorities.map(v => cOpt(v, P.priorities[v], r?.priority || 'normal')).join('')}</select></label>
        <label class="switch"><input type="checkbox" name="mandatory"${r?.mandatory ? ' checked' : ''}><span>${C.mandatory}</span></label>
        ${edit ? `<label class="f"><span>${C.supplier}</span><select name="party"><option value="">${C.notAssigned}</option>${d.parties.map(p => cOpt(p.pid, `${p.contact.name} · ${C.partyKinds[p.kind]}`, r.party)).join('')}</select></label>
          <label class="f"><span>${C.taskDue}</span><input type="date" name="due_at" value="${esc(cDateIn(r.due_at))}"></label>
          <label class="f"><span>${C.explainRecipient}</span><textarea name="explain_recipient" maxlength="600" rows="2">${esc(r.explain_recipient || '')}</textarea></label>
          <label class="f"><span>${C.explainInternal}</span><textarea name="explain_internal" maxlength="600" rows="2">${esc(r.explain_internal || '')}</textarea></label>` : ''}`,
        f => onSave({ ...f, mandatory: f.mandatory === 'on', ...(edit ? { party: f.party || null, due_at: f.due_at || null } : {}) }), edit ? T.save : C.add);
}

function taskSheet(t, d, reload) {
    const full = d.me.perms.tasks === true, edit = !!t;
    sheet(edit ? t.title : C.addTask, `${full ? `<label class="f"><span>${C.taskTitle}</span><input name="title" required maxlength="160" value="${esc(t?.title || '')}"></label>
          <label class="f"><span>${C.taskDetail}</span><textarea name="detail" maxlength="1000" rows="2">${esc(t?.detail || '')}</textarea></label>
          <label class="f"><span>${C.assignee}</span><select name="assignee"><option value="">${C.unassigned}</option>${d.members.map(m => cOpt(m.pid, m.name, t ? t.assignee?.pid : d.members.find(x => x.me)?.pid)).join('')}</select></label>
          <label class="f"><span>${C.taskDue}</span><input type="date" name="due_at" value="${esc(cDateIn(t?.due_at))}"></label>` : `<p class="hint">${esc(t.detail || '')}</p>`}
        ${edit ? taskStatusFields(t) : ''}`, async f => {
        if (!edit) await api('task-create', { body: { case: d.case.pid, title: f.title, detail: f.detail, assignee: f.assignee || null, due_at: f.due_at || null } });
        else await api('task-update', { body: { task: t.pid, ...(full ? { title: f.title, detail: f.detail, assignee: f.assignee || null, due_at: f.due_at || null } : {}), status: f.status, reason: f.reason } });
        reload();
    }, edit ? T.save : C.add);
}

const taskStatusFields = t => `<label class="f"><span>${C.state}</span><select name="status">${Object.keys(C.taskStatuses).map(k => cOpt(k, C.taskStatuses[k], t.status)).join('')}</select></label>
    <label class="f"><span>${C.cancelReason}</span><input name="reason" maxlength="500" value="${esc(t.cancel_reason || '')}"></label>`;

/** משימה מתוך "המשימות שלי": מצב בלבד, וקישור לתיק. */
function myTaskSheet(t, reload) {
    sheet(t.title, `<p class="hint"><a href="#/cases/${t.case.pid}" data-close><span dir="ltr">${esc(t.case.number)}</span> ${esc(t.case.title || '')} — ${C.toCase}</a></p>
        ${t.detail ? `<p>${esc(t.detail)}</p>` : ''}${taskStatusFields(t)}`, async f => {
        await api('task-update', { body: { task: t.pid, status: f.status, reason: f.reason } });
        reload();
    });
}

async function viewMyTasks() {
    setHead(C.myTasks, null);
    const d = await api('my-tasks');
    App.main.innerHTML = d.tasks.length ? `<ul class="list">${d.tasks.map(t => taskRow(t, true)).join('')}</ul>` : `<p class="empty">${C.noMyTasks}</p>`;
    cOn('[data-task]', el => myTaskSheet(d.tasks.find(x => x.pid === el.dataset.task), viewMyTasks));
}

// ── כרטיס תיק ─────────────────────────────────────────────────

async function viewCase(pid, tab) {
    const d = await api('case', { params: { case: pid } });
    const c = d.case, canStatus = !!d.me.perms.edit;
    if (c.status === 'draft') { location.replace(`#/cases/${pid}/setup`); return; }
    // תיק סגור — קריאה בלבד עד פתיחה מחדש (השרת אוכף; כאן רק לא מציגים כפתורים שיידחו)
    if (c.status === 'closed') d.me.perms = { ...d.me.perms, edit: false, tasks: false, documents: false, members: false };
    const perms = d.me.perms;
    const tabs = ['overview', 'parties', 'reqs', 'activity'];   // לשונית של פרוסה שעוד לא נבנתה אינה מוצגת
    const cur = tabs.includes(tab) ? tab : 'overview';
    const href = t => `#/cases/${pid}${t === 'overview' ? '' : '/' + t}`;
    const reload = () => viewCase(pid, cur);
    setHead(c.number, null);
    const head = `<div class="summary"><div class="line"><b class="one">${esc(c.title || C.noTitle)}</b><span class="chip" dir="ltr">${esc(c.number)}</span></div>
        <div class="line muted">${cPurpose(c)} · ${c.responsible ? esc(c.responsible.name) : `<span class="chip bad">${C.noResponsible}</span>`}</div>
        <div class="chips">${canStatus ? `<button type="button" class="chip info" id="cstatus">${esc(C.statuses[c.status])} ▾</button>` : cStatusChip(c.status)}${c.due_date ? `<span class="chip">${esc(C.due(fmtDate(c.due_date)))}</span>` : ''}</div></div>
        ${tabsHtml(tabs.map(t => [href(t), C.tabs[t], t === 'reqs' ? d.requirements.filter(r => !r.split).length : undefined]), href(cur))}`;
    let body = '', docs = null, rqs = null;
    if (cur === 'overview') {
        const openM = d.requirements.filter(r => r.mandatory && dOpen(r));
        const openT = d.tasks.filter(t => ['open', 'in_progress'].includes(t.status)), doneT = d.tasks.filter(t => !['open', 'in_progress'].includes(t.status));
        body = `${c.status === 'closed' ? `<p class="banner" role="note">🔒 ${esc(C.closedBanner(fmtDate(c.closed_at), c.close_reason))}</p>` : ''}
            ${d.profile.newer || d.profile.pending ? `<p class="banner" role="note">🆕 ${esc(d.profile.newer ? C.newer(d.profile.newer) : C.pending)} <button type="button" class="btn ghost sm" id="cdiff">${C.check}</button></p>` : ''}
            ${d.plan.alerts.map(a => `<p class="banner" role="note">🔔 ${esc(a.text)}</p>`).join('')}
            <h2 class="sec-title">✍️ ${C.todoTitle} <small>${d.todo.length || ''}</small></h2>
            ${d.todo.length ? `<ul class="list">${d.todo.map(k => `<li class="card static"><span class="use-ic" aria-hidden="true">▫️</span><div class="body"><div class="l1"><span>${esc(C.todo[k] || k)}</span></div></div></li>`).join('')}</ul>` : `<p class="empty">${C.todoNone}</p>`}
            ${perms.edit ? `<div class="row-btns"><button type="button" class="btn ghost" id="cdetails">${C.details}</button></div>` : ''}
            <h2 class="sec-title">📄 ${C.openMandatoryTitle} <small>${openM.length || ''}</small></h2>
            ${openM.length ? `<ul class="list">${openM.map(r => reqRow(r, d, true)).join('')}</ul>` : `<p class="empty">${d.requirements.length ? C.noOpenMandatory : C.noRequirements}</p>`}
            <h2 class="sec-title">☑️ ${C.tasksTitle} <small>${openT.length || ''}</small></h2>
            ${openT.length ? `<ul class="list">${openT.map(t => taskRow(t, false)).join('')}</ul>` : `<p class="empty">${C.noOpenTasks}</p>`}
            ${perms.tasks === true ? `<div class="row-btns"><button type="button" class="btn ghost" id="tadd"><span aria-hidden="true">+</span> ${C.addTask}</button></div>` : ''}
            ${doneT.length ? `<details><summary class="hint">${esc(C.doneTasks(doneT.length))}</summary><ul class="list">${doneT.map(t => taskRow(t, false)).join('')}</ul></details>` : ''}
            ${identsHtml(d, !!perms.edit)}
            <h2 class="sec-title">📑 ${C.profileTitle}</h2>
            <div class="summary"><div class="line"><span>${d.profile.pid ? `<a href="#/profiles/${d.profile.pid}">${esc(d.profile.name)}</a>` : esc(d.profile.name)}</span><span class="chip">${esc(P.versionN(d.profile.version))}</span></div>
              <div class="line muted">${C.keyDate}: ${c.key_date ? esc(fmtDate(c.key_date)) : C.unknown} · ${C.propertyType}: ${c.property_type ? esc(P.propertyTypes[c.property_type]) : C.unknown}</div></div>
            ${d.plan.skipped.length ? `<h2 class="sec-title">${C.skippedTitle}</h2><ul class="list">${d.plan.skipped.map(s => `<li class="card static"><div class="body"><div class="l1"><span>${esc(s.title)}</span></div><div class="l2 wrap">${esc(s.reason)}</div></div></li>`).join('')}</ul>` : ''}`;
    } else if (cur === 'parties') {
        rqs = await api('case-requests', { params: { case: pid } });
        body = partiesHtml(d, !!perms.edit) + requestsHtml(rqs, d) + membersHtml(d);
    } else if (cur === 'reqs') {
        docs = await docsTab(pid, d, reload);
        body = docs.html;
    } else {
        const log = await api('case-activity', { params: { case: pid } });
        body = log.rows.length ? auditHtml(log.rows, r => esc((T.audit[r.action] || (() => r.action))(r)), r => esc(r.actor || C.system)) : `<p class="empty">${C.activityEmpty}</p>`;
    }
    App.main.innerHTML = head + body;
    docs?.bind();

    $('#cstatus')?.addEventListener('click', () => {
        const o = ['collecting', 'awaiting_reply', 'checking', 'visit', 'analysis', 'review', 'approved', 'delivered', 'closed'];
        sheet(C.changeStatus, `${c.status === 'closed' && d.me.role !== 'responsible' ? `<p class="banner" role="note">${C.reopenOnly}</p>` : ''}
            <label class="f"><span>${C.status}</span><select name="status">${o.map(s => `<option value="${s}"${s === c.status ? ' selected' : ''}${['approved', 'delivered'].includes(s) ? ' disabled' : ''}>${esc(C.statuses[s])}${
                ['approved', 'delivered'].includes(s) ? ' — ' + C.byReport : ''}</option>`).join('')}</select></label>
            <label class="f"><span>${C.reason}</span><textarea name="reason" maxlength="500" rows="2"></textarea></label><p class="hint">${C.reasonWhen}</p>`, async f => {
            await api('case-status', { body: { case: pid, status: f.status, reason: f.reason } });
            reload();
        });
    });
    $('#cdiff')?.addEventListener('click', () => diffSheet(d, reload));
    $('#cdetails')?.addEventListener('click', () => {
        const parts = ['title', 'purpose', 'type', 'dates', 'flags'];
        const dlg = sheet(C.details, caseDetailFields(d, parts), async () => { await caseDetailSave(pid, dlg.querySelector('form'), parts); reload(); });
    });
    $('#tadd')?.addEventListener('click', () => taskSheet(null, d, reload));
    cOn('[data-task]', el => {
        const t = d.tasks.find(x => x.pid === el.dataset.task);
        if (perms.tasks === true || (perms.tasks === 'own' && t.assignee && d.members.find(m => m.me)?.pid === t.assignee.pid)) taskSheet(t, d, reload);
    });
    $('#radd')?.addEventListener('click', () => requirementSheet(null, async v => { await api('requirement-add', { body: { case: pid, ...v } }); reload(); }));
    if (cur === 'overview') identsBind(d, !!perms.edit, reload);
    if (cur === 'parties') { partiesBind(d, !!perms.edit, reload); requestsBind(rqs, d, reload); membersBind(d, reload); }
}

function reqRow(r, d, clickable) {
    const party = r.party && d.parties.find(p => p.pid === r.party);
    return `<li class="card${clickable ? '' : ' static'}"${clickable ? ` tabindex="0" role="button" data-go="#/cases/${d.case.pid}/reqs/${r.pid}"` : ''}><span class="use-ic" aria-hidden="true">📄</span>
        <div class="body"><div class="l1"><span>${esc(r.title)}</span>${r.due_at ? `<span class="t">${esc(fmtDate(r.due_at))}</span>` : ''}</div>
        <div class="l2 wrap">${esc(party ? C.assignedTo(party.contact.name) : r.party_kind === 'office' ? P.partyKinds.office : C.notAssigned)}${r.explain_internal ? ' · ' + esc(r.explain_internal) : ''}</div>
        <div class="chips">${dStatusChip(r)}${r.mandatory ? `<span class="chip warn">${C.mandatory}</span>` : ''}${r.priority === 'high' ? `<span class="chip bad">${C.priority}: ${esc(P.priorities.high)}</span>` : ''}
          <span class="chip">${esc(C.reqOrigins[r.origin] || r.origin)}</span>${r.in_profile ? '' : `<span class="chip">${C.notInVersion}</span>`}</div></div></li>`;
}

/** תצוגת ההבדלים בהחלת גרסה: נוסף / השתנה / הוסר. המשתמש מסמן מה להחיל; שום דבר לא נמחק. */
async function diffSheet(d, reload) {
    let diff;
    try { diff = (await api('case-profile-diff', { params: { case: d.case.pid } })).diff; } catch (x) { toastError(x); return; }
    const canEdit = !!d.me.perms.edit;
    const val = (f, v) => f === 'mandatory' ? (v ? T.p.yes : T.p.no) : f === 'priority' ? (P.priorities[v] || v) : v;
    const sec = (kind, word) => {
        const s = diff[kind];
        return `${s.added.length ? `<p class="sec-title">➕ ${C.diffAdded} · ${word}</p>${s.added.map(i => `<label class="switch"><input type="checkbox" data-pick="${kind}_add" value="${esc(i.key)}" checked${canEdit ? '' : ' disabled'}>
                <span>${esc(i.title)}${i.mandatory ? ` <small class="chip warn">${C.mandatory}</small>` : ''}${i.rule ? ` <small class="muted">${esc(C.byRule(i.rule))}</small>` : ''}</span></label>`).join('')}` : ''}
            ${s.changed.length ? `<p class="sec-title">✏️ ${C.diffChanged} · ${word}</p>${s.changed.map(i => `<label class="switch"><input type="checkbox" data-pick="${kind}_change" value="${esc(i.key)}"${canEdit ? '' : ' disabled'}>
                <span>${esc(i.title)}<br>${Object.entries(i.fields).map(([f, v]) => `<small class="muted">${esc(C.fieldNames[f] || f)} — ${C.inCase}: ${esc(val(f, v.case))} ← ${C.inProfile}: ${esc(val(f, v.profile))}</small>`).join('<br>')}</span></label>`).join('')}` : ''}
            ${s.removed.length ? `<p class="sec-title">➖ ${C.diffRemoved} · ${word}</p><ul class="list">${s.removed.map(i => `<li class="card static"><div class="body"><div class="l1"><span>${esc(i.title)}</span></div></div></li>`).join('')}</ul>` : ''}`;
    };
    const html = sec('requirements', C.reqWord) + sec('tasks', C.taskWord);
    const dlg = sheet(diff.from === diff.to ? C.diffSame : C.diffTitle(diff.from, diff.to), `<p class="hint">${esc(C.profileLine(diff.profile, diff.to))}. ${C.diffHint}</p>${html.trim() ? html : `<p class="empty">${C.diffNone}</p>`}`,
        canEdit ? async () => {
            const pick = k => $$(`[data-pick="${k}"]:checked`, dlg).map(x => x.value);
            await api('case-profile-apply', { body: { case: d.case.pid, requirements_add: pick('requirements_add'), tasks_add: pick('tasks_add'), requirements_change: pick('requirements_change'), tasks_change: pick('tasks_change') } });
            toast(C.diffApplied);
            reload();
        } : null, C.diffApply);
}

// ── ספר הכתובות ───────────────────────────────────────────────

const contactFields = c => `<label class="f"><span>${C.name}</span><input name="name" maxlength="120" value="${esc(c.name || '')}"></label>
    <label class="f"><span>${C.org}</span><input name="org" maxlength="120" value="${esc(c.org || '')}"></label>
    <div class="fields two"><label class="f"><span>${C.phone}</span><input name="phone" type="tel" dir="ltr" maxlength="40" value="${esc(c.phone || '')}"></label>
      <label class="f"><span>${C.email}</span><input name="email" type="email" dir="ltr" maxlength="200" value="${esc(c.email || '')}"></label></div>`;

async function viewContacts() {
    setHead(C.contacts, null);
    const q = sessionStorage.getItem('apr-ctq') || '';
    const d = await api('contacts', { params: q ? { q } : {} });
    App.main.innerHTML = `<form class="toolbar" id="ctsearch"><input type="search" name="q" value="${esc(q)}" placeholder="${C.contactSearch}" aria-label="${C.contactSearch}" style="flex:1">
          <button type="button" class="btn primary" id="ctadd"><span aria-hidden="true">+</span> ${C.addContact}</button></form>
        ${d.contacts.length ? `<ul class="list">${d.contacts.map(c => `<li class="card" tabindex="0" role="button" data-contact="${c.pid}"><span class="avatar" aria-hidden="true" style="--h:${hue(c.name)}">${esc(initials(c.name))}</span>
            <div class="body"><div class="l1"><span>${esc(c.name)}</span></div><div class="l2 wrap" dir="auto">${esc([c.org, c.phone, c.email].filter(Boolean).join(' · '))}</div></div></li>`).join('')}</ul>` : `<p class="empty">${q ? C.noResults : C.noContacts}</p>`}`;
    $('#ctsearch').addEventListener('submit', e => { e.preventDefault(); sessionStorage.setItem('apr-ctq', e.target.q.value.trim()); viewContacts(); });
    const edit = c => {
        const dlg = sheet(c ? c.name : C.addContact, `${contactFields(c || {})}<label class="f"><span>${C.note}</span><input name="note" maxlength="300" value="${esc(c?.note || '')}"></label>
            ${c ? `<p class="hint">${C.contactHint}</p><div class="row-btns"><button type="button" class="btn danger" id="ctrm">${C.remove}</button></div>` : ''}`, async f => {
            await api('contact-save', { body: { ...(c ? { contact: c.pid } : {}), ...f } });
            viewContacts();
        }, c ? T.save : C.add);
        $('#ctrm', dlg)?.addEventListener('click', async () => {
            if (!confirm(C.contactDeleteAsk)) return;
            try { await api('contact-delete', { body: { contact: c.pid } }); dlg.close(); viewContacts(); } catch (x) { toastError(x); }
        });
    };
    $('#ctadd').addEventListener('click', () => edit(null));
    cOn('[data-contact]', el => edit(d.contacts.find(c => c.pid === el.dataset.contact)));
}

// ── מנהל משרד: שורות ניהול ────────────────────────────────────

async function viewOfficeCases() {
    setHead(C.officeCases, null);
    const d = await api('office-cases');
    App.main.innerHTML = `<p class="hint">${C.officeCasesHint}</p>${d.cases.length ? `<ul class="list">${d.cases.map(c => `<li class="card static"><span class="use-ic" aria-hidden="true">🗂️</span>
        <div class="body"><div class="l1"><span dir="ltr">${esc(c.number)}</span><span class="t">${esc(C.updated(fmtDate(c.updated_at)))}</span></div>
        <div class="l2 wrap">${c.responsible ? esc(c.responsible) : `<span class="chip bad">${C.noResponsible}</span>`}${c.due_date ? ' · ' + esc(C.due(fmtDate(c.due_date))) : ''}</div>
        <div class="chips">${cStatusChip(c.status)}</div></div>
        <button type="button" class="btn ghost sm" data-transfer="${c.pid}">${C.transfer}</button></li>`).join('')}</ul>` : `<p class="empty">${C.noCases}</p>`}`;
    $$('[data-transfer]').forEach(b => b.addEventListener('click', () => {
        const c = d.cases.find(x => x.pid === b.dataset.transfer);
        sheet(`${C.transfer} · ${c.number}`, `<label class="f"><span>${C.transferTo}</span><select name="user">${d.appraisers.map(a => cOpt(a.pid, a.name)).join('')}</select></label>
            <label class="f"><span>${C.reasonReq}</span><textarea name="reason" required maxlength="500" rows="2"></textarea></label>`, async f => {
            await api('office-case-transfer', { body: { case: c.pid, user: f.user, reason: f.reason } });
            toast(C.transferred);
            viewOfficeCases();
        }, C.transfer);
    }));
}
