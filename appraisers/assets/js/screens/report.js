/*
 * פרוסה 17 — הדוח: פרקים, טיוטה, בקרה ואישור (docs/spec/17-report.md §10).
 *   כרטיס תיק ← "דוח וגרסאות" (reportTab): רשימת הגרסאות; "יצירת טיוטה" / "גרסה חדשה"
 *   #/cases/<תיק>/report/<גרסה> (viewReport): פרקים (העורך) · בקרה · תצוגה מקדימה · השוואה · הערות
 *   סקירת התיק (rCaseCheck): שורת הבקרה של הגרסה הפתוחה
 *   עורך פרופיל ← "דוח" (peReport): תבנית הפרקים, הטריגר, ומה חוסם אישור
 * המסך אינו מחשב דבר: כל ערך (מספר, יחידה, טבלה) מגיע מהשרת כמחרוזת ומוצג כפי שהוא; "לא ידוע" מוצג במילים. כל טקסט מהשרת עובר דרך esc().
 * טקסט שנוסח בבינה מסומן עד ששמאי סקר אותו. גרסה שאושרה מוצגת "מאושרת" — לעולם לא "חתומה". ההרשאות נאכפות בשרת; כאן רק מסתירים כפתורים.
 */
'use strict';

const RT = T.r;
const R = { v: null, mode: 'edit', sec: null, editing: false, merge: null, draft: null, state: 'saved', timer: null, saving: false, acks: {}, cmp: null, want: null, x: null, pid: null };

const rChip = (text, cls = '') => `<span class="chip ${cls}">${esc(text)}</span>`;
const rStateChip = st => rChip(RT.states[st] || st, st === 'approved' || st === 'delivered' ? 'ok' : st === 'in_review' ? 'info' : st === 'superseded' ? '' : 'warn');
/** ערך של נתון — טקסט; מי שמציג עוטף ב-esc(). היחידה וסוג השטח מתורגמים לתווית, הערך עצמו כפי שהגיע. */
function rVal(v) {
    if (!v) return RT.unknown;
    const main = v.date && v.text === v.date ? fmtDate(v.date) : String(v.text ?? '');
    return [main, v.unit ? (T.f.units[v.unit] || v.unit) : '', v.area_kind ? '(' + kAreaKind(v.area_kind) + ')' : ''].filter(Boolean).join(' ');
}
/** לאן מוביל מקור: [כתובת, עמוד | null]. */
function rSrcHref(s, pid) {
    const base = `#/cases/${encodeURIComponent(pid)}`;
    if (s.type === 'file') return [`${base}/file/${encodeURIComponent(s.pid)}`, s.page || null, s.pid];
    if (s.type === 'fact') return s.file ? [`${base}/file/${encodeURIComponent(s.file)}`, s.page || null, s.file] : [s.attr ? `${base}/facts/${encodeURIComponent(s.attr)}` : `${base}/facts`, null, null];
    if (s.type === 'visit') return [`${base}/visits/${encodeURIComponent(s.pid)}`, null, null];
    if (s.type === 'source_check') return [`${base}/check/${encodeURIComponent(s.pid)}`, null, null];
    if (s.type === 'comp') return [`${base}/comps/${encodeURIComponent(s.pid)}`, null, null];
    return [base, null, null];
}
/** קישור למקור — HTML שכבר עבר esc. לחיצה על מסמך פותחת אותו בעמוד (17-E1): העמוד נמסר למסך הקובץ. */
function rSrcLink(s, pid, label) {
    const [href, page, file] = rSrcHref(s, pid);
    return `<a class="rsrc" href="${esc(href)}"${file && page ? ` data-rfile="${esc(file)}" data-rpage="${esc(page)}"` : ''}>🔗 ${esc(label || RT.srcTypes[s.type] || RT.src)}${page ? ' · ' + esc(RT.page(page)) : ''}</a>`;
}
function rBindSrc(root = document) {
    $$('[data-rfile]', root).forEach(a => a.addEventListener('click', () => { try { sessionStorage.setItem('apr-file-page', a.dataset.rfile + ':' + a.dataset.rpage); } catch { /* בלי אחסון — המסמך ייפתח בעמוד הראשון */ } }));
}
const rTable = (cols, rows, refs, pid, plain) => `<div class="ktable-wrap"><table class="ktable${cols && cols.length ? '' : ' plain'}">${cols && cols.length ? `<thead><tr>${cols.map(c => `<th>${esc(c)}</th>`).join('')}${!plain && refs ? '<th></th>' : ''}</tr></thead>` : ''}
    <tbody>${rows.map((r, i) => `<tr>${r.map((c, j) => `<td dir="auto"${cols && cols[j] ? ` data-l="${esc(cols[j])}"` : ''}>${esc(c)}</td>`).join('')}${!plain && refs ? `<td>${refs[i] ? rSrcLink(refs[i], pid) : ''}</td>` : ''}</tr>`).join('')}</tbody></table></div>`;

/** בלוק אחד להצגה. o.plain — תצוגה מקדימה (בלי קישורי מקור); o.aiOpen — הפרק נכתב בבינה וטרם נסקר: כל פסקת בינה מסומנת. */
function rBlockHtml(b, pid, o) {
    const src = !o.plain && b.src ? ' ' + rSrcLink(b.src, pid) : '';
    if (b.type === 'p') {
        const ai = b.ai && o.aiOpen;
        return `<p class="rp${ai ? ' rai' : ''}" dir="auto">${ai ? `<span class="chip bad">🤖 ${esc(RT.aiMark)}</span> ` : ''}${esc(b.text)}${src}${b.num_unknown && !o.frozen ? `<br><span class="chip bad">⚠️ ${esc(RT.numUnknown(b.num_unknown.join(', ')))}</span>` : ''}</p>`;
    }
    if (b.type === 'h') return `<h4 class="rh" dir="auto">${esc(b.text)}</h4>`;
    if (b.type === 'note_missing') return `<p class="rmiss" dir="auto">⚠️ ${esc(RT.unknown + ': ' + b.what)}</p>`;
    if (b.type === 'list') return `<ul class="rlist">${b.items.map(i => `<li dir="auto">${esc(i)}</li>`).join('')}</ul>`;
    if (b.type === 'table') return rTable(null, b.rows, null, pid, true);
    if (b.type === 'data_table') return (b.rows || []).length ? rTable(b.cols, b.rows, b.refs, pid, o.plain) : `<p class="rmiss" dir="auto">⚠️ ${esc(RT.unknown + ': ' + (b.label || ''))}</p>`;
    if (b.type === 'fact') {
        return `<p class="rfact"><span dir="auto">${esc(b.label || b.attr)}:</span> ${b.value ? `<b dir="auto">${esc(rVal(b.value))}</b>` : `<span class="muted">${esc(RT.unknown)}</span>`}
            ${b.value && b.verified === false ? rChip(RT.notVerified, 'warn') : ''}${b.changed && !o.plain ? rChip(RT.valueChanged(b.was ?? RT.unknown), 'bad') : ''}${!o.plain && b.fact ? ' ' + rSrcLink({ type: 'fact', pid: b.fact, attr: b.attr }, pid) : ''}</p>`;
    }
    if (b.type === 'image') {
        if (b.state && b.state !== 'ok') return `<p class="rmiss">🖼️ ${esc(b.state === 'removed' ? RT.imageRemoved : RT.imageNotReport)}${b.caption ? ' · ' + esc(b.caption) : ''}</p>`;
        return `<figure class="rimg"><img src="api.php?action=visit-photo&amp;photo=${encodeURIComponent(b.photo)}&amp;thumb=1" alt="${esc(b.caption || RT.image)}" loading="lazy">${b.caption ? `<figcaption dir="auto">${esc(b.caption)}</figcaption>` : ''}</figure>`;
    }
    return '';
}
const rBlocksHtml = (blocks, pid, o) => blocks.length ? blocks.map(b => rBlockHtml(b, pid, o)).join('') : `<p class="empty">${esc(RT.secStates.empty)}</p>`;
const rAiOpen = s => s.origin === 'ai' && !s.ai_reviewed;

// ── כרטיס התיק ← "דוח וגרסאות" ─────────────────────────────────

function rVersionCard(v, pid) {
    return `<li class="card" tabindex="0" role="button" data-go="#/cases/${esc(pid)}/report/${esc(v.pid)}"><span class="use-ic" aria-hidden="true">📘</span><div class="body">
        <div class="l1"><span>${esc(RT.versionN(v.number))}</span><span class="t">${esc(RT.origins[v.origin] || v.origin)}</span></div>
        <div class="l2 wrap">${esc(RT.createdBy(v.created_by, fmtDateTime(v.created_at)))}${v.submitted_at ? '<br>' + esc(RT.submittedBy(v.submitted_by, fmtDateTime(v.submitted_at))) : ''}${v.approved_at ? '<br>' + esc(RT.approvedBy(v.approved_by, fmtDateTime(v.approved_at))) : ''}</div>
        <div class="chips">${rStateChip(v.state)}${v.stale_after_approval ? rChip(RT.stale, 'bad') : ''}</div></div></li>`;
}

async function reportTab(pid, reload) {
    let x;
    try { x = await api('case-report', { params: { case: pid } }); } catch (e) { return { html: errorHtml(e), bind: () => {} }; }
    const k = x.check;
    const html = `<h2 class="sec-title">📘 ${esc(RT.title)} <small>${x.versions.length || ''}</small></h2>
        ${x.draft_job ? `<p class="banner bad" role="alert">⏳ ${esc(RT.draftDelayed(x.draft_job.error || RT.jobStates[x.draft_job.status] || x.draft_job.status))}</p>` : ''}
        ${k ? `<p class="banner${k.block ? ' bad' : ''}" role="note">🧪 ${esc(k.block || k.warn ? RT.checkSummary(k.block, k.warn) : RT.checkClean)} <a class="btn ghost sm" href="#/cases/${esc(pid)}/report/${esc(k.version)}" data-rwant="check" data-rv="${esc(k.version)}">${esc(RT.modes.check)}</a></p>` : ''}
        ${x.versions.length ? `<ul class="list">${x.versions.map(v => rVersionCard(v, pid)).join('')}</ul>` : `<p class="empty">${esc(RT.none)}</p>`}
        ${x.trigger && !x.versions.length ? `<p class="hint">${esc(RT.triggers[x.trigger] || '')}</p>` : ''}
        <div class="row-btns wrap">${x.can_create === 'draft' && x.perms.edit ? `<button type="button" class="btn primary" id="r-create">${esc(RT.createDraft)}</button>` : ''}
          ${x.can_create === 'new_version' && x.perms.approve ? `<button type="button" class="btn ghost" id="r-newver"><span aria-hidden="true">+</span> ${esc(RT.newVersion)}</button>` : ''}</div>`;
    return { html, bind: () => {
        rBindWant();
        $('#r-create')?.addEventListener('click', async e => {
            e.target.disabled = true;
            toast(RT.creating);
            try { const r = await api('report-draft-create', { body: { case: pid } }); location.hash = `#/cases/${pid}/report/${r.version}`; } catch (err) { toastError(err); reload(); }
        });
        $('#r-newver')?.addEventListener('click', () => sheet(RT.newVersion, `<p class="hint">${esc(RT.newVersionHint)}</p><label class="f"><span>${esc(RT.reason)}</span><textarea name="reason" maxlength="500" rows="3" required></textarea></label>`,
            async f => { const r = await api('report-version-new', { body: { case: pid, reason: f.reason } }); location.hash = `#/cases/${pid}/report/${r.version}`; }, RT.newVersion));
    } };
}
/** קישור שנפתח במצב מסוים של מסך הגרסה (בקרה, למשל). */
function rBindWant(root = document) {
    $$('[data-rwant]', root).forEach(a => a.addEventListener('click', () => { R.want = { v: a.dataset.rv, mode: a.dataset.rwant }; }));
}
/** סקירת התיק: שורת הבקרה של הגרסה הפתוחה (אפיון §9 — "מוצגת גם בסקירת התיק"). נטענת בנפרד, ואינה מעכבת את המסך. */
async function rCaseCheck(pid) {
    const box = $('#case-report');
    if (!box) return;
    let x;
    try { x = await api('case-report', { params: { case: pid } }); } catch { return; }
    const k = x.check;
    if (!k || !$('#case-report')) return;
    box.innerHTML = `<p class="banner${k.block ? ' bad' : ''}" role="note">📘 ${esc(RT.versionN(k.number))} · ${esc(RT.states[k.state] || k.state)} — ${esc(k.block || k.warn ? RT.checkSummary(k.block, k.warn) : RT.checkClean)}
        <a class="btn ghost sm" href="#/cases/${esc(pid)}/report/${esc(k.version)}" data-rwant="check" data-rv="${esc(k.version)}">${esc(RT.modes.check)}</a></p>`;
    rBindWant(box);
}

// ── מסך הגרסה ─────────────────────────────────────────────────

async function viewReport(pid, vpid) {
    if (R.v !== vpid) Object.assign(R, { v: vpid, mode: 'edit', sec: null, editing: false, merge: null, draft: null, acks: {}, cmp: null, versions: null });
    if (R.want && R.want.v === vpid) { R.mode = R.want.mode; if (R.want.sec) R.sec = R.want.sec; R.editing = false; R.merge = null; }
    R.want = null;
    clearTimeout(R.timer);
    R.pid = pid;
    R.x = await api('report-version', { params: { version: vpid } });
    if (location.hash !== `#/cases/${pid}/report/${vpid}`) return;   // עברו למסך אחר בזמן הטעינה
    rDraw();
}
const rReload = () => viewReport(R.pid, R.v);
const rCanEdit = () => R.x.internal && !R.x.frozen && R.x.version.state === 'draft' && R.x.perms.edit;
/** פעולה ורענון; שגיאת 409 ("עודכן בינתיים") — מרעננים כדי להציג את המצב העדכני. */
async function rAct(action, body, okMsg) {
    try { const r = await api(action, { body }); if (okMsg) toast(okMsg); await rReload(); return r; } catch (e) {
        if (e.code === 'confirm_cancelled') return null;
        toastError(e);
        if (e.status === 409) await rReload();
        return null;
    }
}

function rDraw() {
    const x = R.x, v = x.version, pid = R.pid;
    setHead(x.case.number, null);
    const modes = x.internal ? ['edit', 'check', 'preview', 'compare', 'comments'] : ['edit', 'preview'];
    if (!modes.includes(R.mode)) R.mode = 'edit';
    const blockN = x.findings.filter(f => f.level === 'block').length, openC = x.comments.filter(c => !c.resolved_at).length;
    const count = m => m === 'check' && !x.frozen ? (x.findings.length ? (blockN ? '⛔' + blockN : String(x.findings.length)) : '') : m === 'comments' && openC ? String(openC) : '';
    App.main.classList.add('wide');
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one" dir="auto">${esc(x.case.title || C.noTitle)}</b>${rStateChip(v.state)}</div>
          <div class="line muted"><a href="#/cases/${esc(pid)}/report">${esc(RT.back)}</a> · ${esc(RT.versionN(v.number))} · ${esc(RT.origins[v.origin] || v.origin)}</div>
          <div class="line muted">${esc(RT.createdBy(v.created_by, fmtDateTime(v.created_at)))}${v.submitted_at ? ' · ' + esc(RT.submittedBy(v.submitted_by, fmtDateTime(v.submitted_at))) : ''}</div>
          ${v.approved_at ? `<div class="line"><span>✅ ${esc(RT.approvedBy(v.approved_by, fmtDateTime(v.approved_at)))}</span></div>` : ''}
          ${v.reopen_reason ? `<div class="line muted" dir="auto">${esc(RT.reopenReason + ': ' + v.reopen_reason)}</div>` : ''}</div>
        ${x.frozen ? `<p class="banner" role="note">🔒 ${esc(RT.frozen)}</p>` : ''}
        ${v.stale_after_approval ? `<p class="banner bad" role="alert">⚠️ ${esc(RT.stale)} — ${esc(RT.staleHint)}</p>` : ''}
        ${!x.frozen && v.state === 'in_review' ? `<p class="banner" role="note">🔎 ${esc(RT.inReview)}</p>` : ''}
        ${!x.internal && !x.frozen ? `<p class="banner" role="note">🤝 ${esc(RT.sharedDraft)}</p>` : ''}
        <div class="seg-tabs${modes.length > 4 ? ' five' : ''}" role="tablist">${modes.map(m => `<button type="button" role="tab" data-rmode="${m}" aria-selected="${m === R.mode}">${esc(RT.modes[m])} <small>${esc(count(m))}</small></button>`).join('')}</div>
        <div id="r-body"></div>`;
    $$('[data-rmode]').forEach(b => b.addEventListener('click', async () => { if (!(await rLeaveEdit())) return; R.mode = b.dataset.rmode; rDraw(); }));
    ({ edit: rEditMode, check: rCheckMode, preview: rPreviewMode, compare: rCompareMode, comments: rCommentsMode })[R.mode]($('#r-body'));
}

// ── פרקים: קריאה ועריכה ───────────────────────────────────────

function rSecMark(s) {
    return (rAiOpen(s) ? '🤖' : '') + (s.proposal ? '🔔' : '') + (s.state === 'empty' ? '▫️' : '');
}

function rEditMode(body) {
    const x = R.x, pid = R.pid;
    if (!x.sections.length) { body.innerHTML = `<p class="empty">${esc(RT.noSections)}</p>${rVersionActions()}`; rBindVersionActions(); return; }
    const s = x.sections.find(q => q.pid === R.sec) || x.sections[0];
    R.sec = s.pid;
    const can = rCanEdit(), judge = can && x.perms.judge, aiOpen = rAiOpen(s);
    const nav = `<nav class="rnav" aria-label="${esc(RT.sections)}">${x.sections.map(q => `<button type="button" data-rsec="${esc(q.pid)}"${q.pid === s.pid ? ' aria-current="true"' : ''}><span dir="auto">${esc(q.title)}</span>${q.mandatory ? '<i aria-hidden="true">*</i>' : ''} <small>${rSecMark(q)}</small></button>`).join('')}</nav>`;
    const chips = `${s.mandatory ? rChip(RT.mandatory, 'warn') : ''}${s.state ? rChip(RT.secStates[s.state] || s.state) : ''}${s.origin === 'ai' ? rChip(s.ai_reviewed ? RT.aiReviewed(s.ai_reviewed_by) : RT.aiUnreviewed, s.ai_reviewed ? 'ok' : 'bad') : ''}${s.user_edited ? rChip(RT.userEdited, 'info') : ''}`;
    const p = s.proposal;
    const mine = s.lines || [], theirs = p ? p.lines : [];
    const side = (title, lines, other, cls) => `<div class="panel"><b>${esc(title)}</b><ul class="diff">${lines.length ? lines.map(l => `<li dir="auto"${other.includes(l) ? '' : ` class="${cls}"`}>${esc(l)}</li>`).join('') : `<li class="muted">${esc(RT.secStates.empty)}</li>`}</ul></div>`;
    const proposal = p ? `<div class="rprop"><p class="banner" role="note">🔔 ${esc(RT.proposal(p.reason))}</p>
        ${mine.join('\n') === theirs.join('\n') ? `<p class="hint">${esc(RT.same)}</p>` : `<div class="side-by-side">${side(RT.proposalMine, mine, theirs, 'del')}${side(RT.proposalNew, theirs, mine, 'add')}</div>`}
        ${can ? `<div class="row-btns wrap"><button type="button" class="btn primary" data-rprop="accept">${esc(RT.accept)}</button><button type="button" class="btn ghost" data-rprop="merge">${esc(RT.merge)}</button><button type="button" class="btn ghost" data-rprop="reject">${esc(RT.reject)}</button></div>` : ''}</div>` : '';
    const waits = s.ai_wait ? `<p class="banner" role="note">🤖 ${esc(RT.aiWait(RT.aiWaits[s.ai_wait] || s.ai_wait))}${can && s.ai_allowed ? ` <button type="button" class="btn ghost sm" data-rai>${esc(RT.aiNow)}</button>` : ''}</p>` : '';
    const acts = can ? `<div class="row-btns wrap"><button type="button" class="btn primary" id="r-edit">✏️ ${esc(RT.edit)}</button>
        ${s.ai_allowed && !s.ai_wait ? `<button type="button" class="btn ghost" data-rai>🤖 ${esc(RT.aiWrite)}</button>` : ''}
        ${judge && aiOpen ? `<button type="button" class="btn ghost" id="r-reviewed">✅ ${esc(RT.markReviewed)}</button>` : ''}
        ${s.build === 'data' ? `<button type="button" class="btn ghost" id="r-rebuild1">${esc(RT.rebuildSection)}</button>` : ''}
        ${judge ? `<button type="button" class="btn ghost danger" id="r-secdel">${esc(RT.removeSection)}</button>` : ''}</div>` : '';
    const secComments = x.comments.filter(c => c.section === s.pid && !c.resolved_at).length;
    const view = R.editing ? '<div id="r-ed"></div>' : `<div class="summary"><div class="line"><b dir="auto">${esc(s.title)}</b></div><div class="chips">${chips}</div></div>
        ${aiOpen ? `<p class="banner bad" role="note">🤖 ${esc(RT.aiBanner)}</p>` : ''}${waits}${s.changed_note ? `<p class="banner" role="note">🆕 ${esc(RT.changedNote(s.changed_note))}</p>` : ''}${proposal}
        <article class="rdoc">${rBlocksHtml(s.blocks, pid, { aiOpen, frozen: x.frozen })}</article>${acts}
        ${secComments ? `<p class="hint">💬 ${esc(RT.commentsOpen(secComments))}</p>` : ''}`;
    const sources = x.internal ? `<aside class="rside"><h3 class="sec-title">🔗 ${esc(RT.sources)}</h3>${(s.sources || []).length ? `<ul class="list">${s.sources.map(q => `<li class="card static"><div class="body"><div class="l1 wrap"><span dir="auto">${esc(q.label || RT.srcTypes[q.type] || '')}</span></div>
        <div class="l2 wrap">${rSrcLink(q, pid, q.file ? RT.openDoc : RT.srcTypes[q.type])}</div></div></li>`).join('')}</ul>` : `<p class="hint">${esc(RT.noSources)}</p>`}</aside>` : '';
    body.innerHTML = `<div class="rgrid">${nav}<section class="rmain">${view}</section>${sources}</div>${R.editing ? '' : rVersionActions()}`;
    $$('[data-rsec]').forEach(b => b.addEventListener('click', async () => { if (!(await rLeaveEdit())) return; R.sec = b.dataset.rsec; rDraw(); }));
    rBindSrc(body);
    if (R.editing) { rEditorDraw(s); return; }
    rBindVersionActions();
    $('#r-edit')?.addEventListener('click', () => rEditorOpen(s, null));
    $$('[data-rai]').forEach(b => b.addEventListener('click', async () => {
        if (s.user_edited && !confirm(RT.aiReplaceAsk)) return;
        b.disabled = true;
        toast(RT.aiWorking);
        try { const r = await api('report-section-ai', { body: { section: s.pid, rev: s.rev, replace: !!s.user_edited } }); toast(r.state === 'done' ? (r.as_proposal ? RT.aiAsProposal : RT.aiDone) : r.state === 'discarded' ? RT.aiDiscarded : RT.aiNotDone(RT.aiWaits[r.reason] || r.reason || ''), r.state !== 'done'); } catch (e) { toastError(e); }
        rReload();
    }));
    // "סקרתי" על טקסט שהנתונים שלו השתנו מאז שנכתב: רק באישור מפורש, ורק אחרי שהממצא (וההצעה) מוצגים במסך. מסך ישן — השרת עונה 409, והמסך נטען מחדש
    $('#r-reviewed')?.addEventListener('click', () => {
        const stale = (R.x.findings || []).some(f => f.kind === 'ai_stale' && f.section === s.pid);
        if (confirm(stale ? RT.markReviewedChangedAsk : RT.markReviewedAsk)) rAct('report-section-reviewed', { section: s.pid, rev: s.rev, ...(stale ? { confirm_changed: true } : {}) });
    });
    $('#r-rebuild1')?.addEventListener('click', () => { if (confirm(s.user_edited ? RT.rebuildForceAsk : RT.rebuildAsk)) rAct('report-rebuild', { version: R.v, sections: [s.pid], include_edited: !!s.user_edited }); });
    $('#r-secdel')?.addEventListener('click', () => { if (confirm((s.mandatory ? RT.removeMandatoryAsk : RT.removeSectionAsk)(s.title))) { R.sec = null; rAct('report-section-delete', { section: s.pid, rev: s.rev }); } });
    $$('[data-rprop]').forEach(b => b.addEventListener('click', () => {
        const d = b.dataset.rprop;
        if (d === 'merge') return rEditorOpen(s, p);
        if (d === 'accept' && !confirm(RT.acceptAsk)) return;
        rAct('report-proposal-decide', { proposal: p.pid, decision: d });
    }));
}

function rVersionActions() {
    const x = R.x, st = x.version.state, p = x.perms;
    if (!x.internal || x.frozen) return '';
    const b = [];
    if (st === 'draft' && p.judge) b.push(`<button type="button" class="btn primary" id="r-submit">${esc(RT.submit)}</button>`);
    if (st === 'draft' && p.edit) b.push(`<button type="button" class="btn ghost" id="r-rebuild">${esc(RT.rebuild)}</button>`, `<button type="button" class="btn ghost" id="r-secadd"><span aria-hidden="true">+</span> ${esc(RT.addSection)}</button>`);
    if (st === 'in_review' && p.approve) b.push(`<button type="button" class="btn primary" id="r-toapprove">${esc(RT.toApprove)}</button>`);
    if (st === 'in_review' && p.judge) b.push(`<button type="button" class="btn ghost" id="r-return">${esc(RT.returnToEdit)}</button>`);
    if (st === 'draft' && p.approve) b.push(`<button type="button" class="btn ghost danger" id="r-verdel">${esc(RT.deleteDraft)}</button>`);
    return b.length ? `<h3 class="sec-title">${esc(RT.versionN(x.version.number))}</h3><div class="row-btns wrap">${b.join('')}</div>` : '';
}
function rBindVersionActions() {
    const x = R.x, v = x.version;
    $('#r-submit')?.addEventListener('click', () => { if (confirm(RT.submitAsk)) rAct('report-submit', { version: v.pid, rev: v.rev }); });
    $('#r-return')?.addEventListener('click', () => sheet(RT.returnToEdit, `<label class="f"><span>${esc(RT.reason)}</span><textarea name="reason" maxlength="500" rows="2"></textarea></label>`,
        async f => { await api('report-return', { body: { version: v.pid, rev: v.rev, reason: f.reason } }); rReload(); }, RT.returnToEdit));
    $('#r-toapprove')?.addEventListener('click', () => { R.mode = 'check'; rDraw(); });
    $('#r-secadd')?.addEventListener('click', () => sheet(RT.addSection, `<label class="f"><span>${esc(RT.sectionTitle)}</span><input name="title" maxlength="120" required></label>`,
        async f => { const r = await api('report-section-add', { body: { version: v.pid, title: f.title } }); R.sec = r.section; rReload(); }));
    $('#r-rebuild')?.addEventListener('click', async () => {
        if (!confirm(RT.rebuildAsk)) return;
        const r = await rAct('report-rebuild', { version: v.pid });
        if (!r) return;
        toast(RT.rebuildDone(r.rebuilt.length));
        if (r.skipped.length) sheet(RT.rebuild, `<p class="banner" role="note">${esc(RT.rebuildSkipped)}</p><ul class="rlist">${r.skipped.map(q => `<li dir="auto">${esc(q.title)}</li>`).join('')}</ul>
            <label class="switch"><input type="checkbox" name="force"><span>${esc(RT.rebuildForce)}</span></label>`, async f => { if (f.force) { await api('report-rebuild', { body: { version: v.pid, sections: r.skipped.map(q => q.pid), include_edited: true } }); rReload(); } }, RT.rebuild);
    });
    $('#r-verdel')?.addEventListener('click', () => sheet(RT.deleteDraft, `<p class="hint">${esc(RT.deleteDraftHint)}</p><label class="f"><span>${esc(RT.reason)}</span><textarea name="reason" maxlength="500" rows="2" required></textarea></label>`,
        async f => { await api('report-version-delete', { body: { version: v.pid, rev: v.rev, reason: f.reason } }); location.hash = `#/cases/${R.pid}/report`; }, RT.deleteDraft));
}

// ── העורך: בלוקים, שמירה אוטומטית עם rev, התנגשות, מיזוג הצעה ──

/** בלוק מוצג ← בלוק לעריכה: רק השדות שהשרת שומר (בלי הערכים הפתורים). */
function rBlockIn(b) {
    const o = { type: b.type };
    if (b.type === 'p' || b.type === 'h') o.text = b.text;
    else if (b.type === 'note_missing') o.what = b.what;
    else if (b.type === 'list') o.items = [...b.items];
    else if (b.type === 'table') o.rows = b.rows.map(r => [...r]);
    else if (b.type === 'data_table') o.table = b.table;
    else if (b.type === 'fact') o.attr = b.attr;
    else if (b.type === 'image') { o.photo = b.photo; if (b.caption) o.caption = b.caption; }
    if (b.src) o.src = b.src;
    if (b.ai) o.ai = true;
    return o;
}
function rEditorOpen(s, proposal) {
    R.editing = true;
    R.merge = proposal ? proposal.pid : null;
    R.draft = { pid: s.pid, rev: s.rev, title: s.title, blocks: s.blocks.map(rBlockIn), dirty: false, theirs: null };
    R.state = 'saved';
    rDraw();
}
function rEdStatus(state) {
    R.state = state;
    const el = $('#r-status');
    if (el) { el.textContent = RT.saveStates[state]; el.className = 'chip ' + ({ saved: 'ok', saving: 'info', failed: 'bad', dirty: 'info', conflict: 'bad' })[state]; }
}
function rEditorDraw(s) {
    const d = R.draft, L = R.x.lists || { tables: {}, attributes: [], photos: [] }, box = $('#r-ed');
    const p = R.merge ? s.proposal : null;
    const field = (b, i) => {
        if (b.type === 'p') return `<textarea data-f="text" rows="4" maxlength="5000" dir="auto" aria-label="${esc(RT.blockTypes.p)}">${esc(b.text || '')}</textarea>`;
        if (b.type === 'h') return `<input data-f="text" maxlength="200" dir="auto" aria-label="${esc(RT.blockTypes.h)}" value="${esc(b.text || '')}">`;
        if (b.type === 'note_missing') return `<input data-f="what" maxlength="200" dir="auto" aria-label="${esc(RT.blockTypes.note_missing)}" value="${esc(b.what || '')}">`;
        if (b.type === 'list') return `<textarea data-f="items" rows="4" dir="auto" aria-label="${esc(RT.blockTypes.list)}">${esc((b.items || []).join('\n'))}</textarea><small class="hint">${esc(RT.listHint)}</small>`;
        if (b.type === 'table') return `<textarea data-f="rows" rows="4" dir="auto" aria-label="${esc(RT.blockTypes.table)}">${esc((b.rows || []).map(r => r.join(' | ')).join('\n'))}</textarea><small class="hint">${esc(RT.tableHint)}</small>`;
        if (b.type === 'data_table') return `<select data-f="table" aria-label="${esc(RT.blockTypes.data_table)}">${Object.keys(L.tables).map(k => cOpt(k, L.tables[k], b.table)).join('')}</select><small class="hint">${esc(RT.dataTableHint)}</small>`;
        if (b.type === 'fact') return `<select data-f="attr" aria-label="${esc(RT.blockTypes.fact)}">${L.attributes.map(a => cOpt(a.key, a.label, b.attr)).join('')}</select><small class="hint">${esc(RT.factHint)}</small>`;
        if (b.type === 'image') return `<select data-f="photo" aria-label="${esc(RT.blockTypes.image)}">${[...(L.photos.some(q => q.pid === b.photo) || !b.photo ? [] : [{ pid: b.photo, caption: b.caption }]), ...L.photos].map((q, n) => cOpt(q.pid, q.caption || RT.image + ' ' + (n + 1), b.photo)).join('')}</select>
            <input data-f="caption" maxlength="300" dir="auto" placeholder="${esc(RT.caption)}" aria-label="${esc(RT.caption)}" value="${esc(b.caption || '')}">`;
        return '';
    };
    box.innerHTML = `<div class="toolbar"><span class="chip ok" id="r-status" role="status">${esc(RT.saveStates[R.state])}</span><span style="flex:1"></span>
          ${R.merge ? `<button type="button" class="btn ghost sm" id="r-cancel">${esc(RT.cancel)}</button><button type="button" class="btn primary sm" id="r-merge">${esc(RT.mergeSave)}</button>` : `<button type="button" class="btn primary sm" id="r-done">${esc(RT.done)}</button>`}</div>
        <div id="r-conflict"></div>
        ${p ? `<p class="hint">${esc(RT.mergeHint)}</p><details open><summary class="hint">${esc(RT.proposalNew)}</summary><article class="rdoc">${rBlocksHtml(p.blocks, R.pid, { plain: true })}</article></details>` : ''}
        <div class="panel"><label class="f"><span>${esc(RT.sectionTitle)}</span><input id="r-title" maxlength="120" dir="auto" value="${esc(d.title)}"${R.merge ? ' disabled' : ''}></label>
        ${d.blocks.map((b, i) => `<div class="rblk${b.ai ? ' rai' : ''}" data-i="${i}"><div class="rblk-h"><span>${esc(RT.blockTypes[b.type] || b.type)}${b.ai ? ' · 🤖 ' + esc(RT.aiMark) : ''}</span>
            <button type="button" class="icon-btn" data-rup="${i}" aria-label="${esc(RT.moveUp)}"${i === 0 ? ' disabled' : ''}>▲</button><button type="button" class="icon-btn" data-rdown="${i}" aria-label="${esc(RT.moveDown)}"${i === d.blocks.length - 1 ? ' disabled' : ''}>▼</button>
            <button type="button" class="icon-btn" data-rdel="${i}" aria-label="${esc(RT.removeBlock)}">✕</button></div>${field(b, i)}</div>`).join('')}
        <div class="toolbar"><select id="r-newtype" aria-label="${esc(RT.addBlock)}">${Object.keys(RT.blockTypes).filter(t => t !== 'image' || L.photos.length).map(t => cOpt(t, RT.blockTypes[t], 'p')).join('')}</select>
          <button type="button" class="btn ghost" id="r-addblk"><span aria-hidden="true">+</span> ${esc(RT.addBlock)}</button></div></div>`;
    if (d.theirs) rConflictDraw();
    const changed = () => { rCollect(); d.dirty = true; if (R.merge) return rEdStatus('dirty'); if (R.state !== 'conflict') { rEdStatus('dirty'); clearTimeout(R.timer); R.timer = setTimeout(rSave, 1500); } };
    box.addEventListener('input', changed);
    box.addEventListener('change', changed);
    const structural = fn => { rCollect(); fn(); d.dirty = true; rEditorDraw(s); if (!R.merge && R.state !== 'conflict') { rEdStatus('dirty'); clearTimeout(R.timer); R.timer = setTimeout(rSave, 1500); } else if (R.merge) rEdStatus('dirty'); };
    const swap = (i, j) => structural(() => { [d.blocks[i], d.blocks[j]] = [d.blocks[j], d.blocks[i]]; });
    $$('[data-rup]', box).forEach(b => b.addEventListener('click', () => swap(+b.dataset.rup, +b.dataset.rup - 1)));
    $$('[data-rdown]', box).forEach(b => b.addEventListener('click', () => swap(+b.dataset.rdown, +b.dataset.rdown + 1)));
    $$('[data-rdel]', box).forEach(b => b.addEventListener('click', () => structural(() => d.blocks.splice(+b.dataset.rdel, 1))));
    $('#r-addblk', box).addEventListener('click', () => structural(() => {
        const t = $('#r-newtype').value;
        d.blocks.push(t === 'data_table' ? { type: t, table: Object.keys(L.tables)[0] } : t === 'fact' ? { type: t, attr: (L.attributes[0] || {}).key } : t === 'image' ? { type: t, photo: (L.photos[0] || {}).pid } : t === 'list' ? { type: t, items: [] } : t === 'table' ? { type: t, rows: [] } : t === 'note_missing' ? { type: t, what: '' } : { type: t, text: '' });
    }));
    $('#r-done', box)?.addEventListener('click', async () => { if (await rLeaveEdit()) rReload(); });
    $('#r-cancel', box)?.addEventListener('click', () => { R.editing = false; R.merge = null; R.draft = null; rDraw(); });
    $('#r-merge', box)?.addEventListener('click', async e => {
        rCollect();
        e.target.disabled = true;
        try {
            await api('report-proposal-decide', { body: { proposal: R.merge, decision: 'merge', content: rSendable(d.blocks) } });
            R.editing = false; R.merge = null; R.draft = null;
            await rReload();
        } catch (err) { toastError(err); e.target.disabled = false; if (err.status === 409) { R.editing = false; R.merge = null; rReload(); } }
    });
}
/** מה שבשדות ← R.draft (הטקסט כפי שהוקלד). מקור וסימון הבינה של כל בלוק נשמרים. */
function rCollect() {
    const d = R.draft;
    if (!d || !$('#r-ed')) return;
    const t = $('#r-title');
    if (t && !t.disabled) d.title = t.value;
    $$('#r-ed .rblk').forEach(el => {
        const b = d.blocks[+el.dataset.i];
        if (!b) return;
        $$('[data-f]', el).forEach(f => {
            const k = f.dataset.f, val = f.value;
            if (k === 'items') b.items = val.split('\n').map(q => q.trim()).filter(Boolean);
            else if (k === 'rows') b.rows = val.split('\n').map(q => q.trim()).filter(Boolean).map(q => q.split('|').map(c => c.trim()));
            else if (k === 'caption') { if (val.trim()) b.caption = val.trim(); else delete b.caption; }
            else b[k] = val;
        });
    });
}
/** הבלוקים כפי שנשלחים: בלוק שעוד ריק נשאר בעורך ואינו נשלח; שורות טבלה מושלמות לאותו מספר תאים. */
function rSendable(blocks) {
    return blocks.map(b => {
        if (b.type === 'table') { const w = Math.min(12, Math.max(0, ...b.rows.map(r => r.length))); return { ...b, rows: b.rows.map(r => [...r, ...Array(w).fill('')].slice(0, w)) }; }
        return b;
    }).filter(b => b.type === 'p' || b.type === 'h' ? (b.text || '').trim() : b.type === 'note_missing' ? (b.what || '').trim() : b.type === 'list' ? b.items.length : b.type === 'table' ? b.rows.length : b.type === 'data_table' ? b.table : b.type === 'fact' ? b.attr : b.type === 'image' ? b.photo : false);
}
async function rSave() {
    clearTimeout(R.timer);
    const d = R.draft;
    if (!d || !d.dirty || R.saving || R.merge || R.state === 'conflict') return true;
    if (!d.title.trim()) { rEdStatus('dirty'); return false; }
    R.saving = true;
    d.dirty = false;
    rEdStatus('saving');
    try {
        const r = await api('report-section-save', { body: { section: d.pid, rev: d.rev, content: rSendable(d.blocks), title: d.title.trim() } });
        d.rev = r.rev;
    } catch (e) {
        R.saving = false;
        if (e.code === 'nothing_to_change') { rEdStatus(d.dirty ? 'dirty' : 'saved'); return true; }
        d.dirty = true;
        if (e.code === 'report_changed') { await rConflict(e.by); return false; }
        rEdStatus('failed');
        toastError(e);
        return false;
    }
    R.saving = false;
    if (d.dirty) return rSave();
    rEdStatus('saved');
    return true;
}
/** מישהו אחר שמר את הפרק בינתיים: לא דורסים. מוצגות שתי הגרסאות; בוחרים — הגרסה שנשמרה, או שלי במקומה. */
async function rConflict(by) {
    rEdStatus('conflict');
    const d = R.draft;
    try {
        const x = await api('report-version', { params: { version: R.v } });
        const s = x.sections.find(q => q.pid === d.pid);
        d.theirs = s ? { rev: s.rev, lines: s.lines || [], by: by || s.updated_by } : { rev: null, lines: [], by };
        if (x.version.state !== 'draft' || !s) { toast(RT.inReview, true); R.editing = false; R.draft = null; return rReload(); }
    } catch (e) { d.theirs = { rev: null, lines: [], by }; }
    rConflictDraw();
}
function rConflictDraw() {
    const d = R.draft, box = $('#r-conflict');
    if (!box || !d.theirs) return;
    box.innerHTML = `<p class="banner bad" role="alert">⚠️ ${esc(RT.conflict(d.theirs.by))}</p>
        <details open><summary class="hint">${esc(RT.conflictTheirs)}</summary><ul class="diff">${d.theirs.lines.map(l => `<li dir="auto">${esc(l)}</li>`).join('') || `<li class="muted">${esc(RT.secStates.empty)}</li>`}</ul></details>
        <p class="hint">${esc(RT.conflictMine)} ↓</p>
        <div class="row-btns wrap"><button type="button" class="btn ghost" id="r-theirs">${esc(RT.takeTheirs)}</button>${d.theirs.rev ? `<button type="button" class="btn ghost danger" id="r-mine">${esc(RT.keepMine)}</button>` : ''}</div>`;
    $('#r-theirs').addEventListener('click', () => { R.editing = false; R.draft = null; rReload(); });
    $('#r-mine')?.addEventListener('click', () => { d.rev = d.theirs.rev; d.theirs = null; box.innerHTML = ''; R.state = 'dirty'; d.dirty = true; rSave(); });
}
/** יציאה מעריכה (מעבר פרק / לשונית / סיום): שומרים מה שממתין. false — נשארים (השמירה נכשלה או שיש התנגשות). */
async function rRouteLeave() {
    if (R.editing && !R.merge && R.draft && $('#r-ed')) { rCollect(); await rSave(); }
    clearTimeout(R.timer);
    R.editing = false;
    R.merge = null;
    R.draft = null;
}
async function rLeaveEdit() {
    if (!R.editing) return true;
    if (R.merge) { R.editing = false; R.merge = null; R.draft = null; return true; }
    rCollect();
    if (R.state === 'conflict') return false;
    if (R.draft && R.draft.dirty && !(await rSave())) return false;
    R.editing = false;
    R.draft = null;
    return true;
}

// ── בקרה ואישור ───────────────────────────────────────────────

function rFindingGo(f, pid) {
    const base = `#/cases/${encodeURIComponent(pid)}`, l = f.link, id = l ? encodeURIComponent(l.pid) : '';
    const href = !l ? (f.kind === 'no_approver' ? `${base}/parties` : null) : l.type === 'fact' ? (l.attr ? `${base}/facts/${encodeURIComponent(l.attr)}` : `${base}/facts`) : l.type === 'requirement' ? `${base}/reqs/${id}` : l.type === 'conflict' ? `${base}/conflicts/${id}`
        : l.type === 'source_check' ? `${base}/check/${id}` : l.type === 'task' ? base : l.type === 'comp_set' ? `${base}/compset/${id}` : l.type === 'comp' ? `${base}/comps/${id}` : l.type === 'alternative' ? `${base}/alt/${id}` : null;
    return `${f.section && !R.x.frozen ? `<button type="button" class="btn ghost sm" data-rgo-sec="${esc(f.section)}">${esc(RT.goSection)}</button>` : ''}${href ? `<a class="btn ghost sm" href="${esc(href)}">${esc(RT.goFix)}</a>` : ''}`;
}

function rCheckMode(body) {
    const x = R.x, v = x.version, pid = R.pid;
    const blocks = x.findings.filter(f => f.level === 'block'), warns = x.findings.filter(f => f.level !== 'block');
    const canApprove = !x.frozen && v.state === 'in_review' && x.perms.approve;
    const row = (f, ack) => `<li class="card static"><span class="use-ic" aria-hidden="true">${f.level === 'block' ? '⛔' : '⚠️'}</span><div class="body"><div class="l1 wrap"><span dir="auto">${esc(f.text)}</span></div>
        <div class="l2 wrap">${esc(RT.b.kinds[f.kind] || '')}</div>${x.frozen ? '' : `<div class="row-btns wrap">${rFindingGo(f, pid)}</div>`}
        ${ack && canApprove ? `<label class="f"><span>${esc(RT.ackReason)}</span><textarea data-rack="${esc(f.key)}" rows="2" maxlength="500" dir="auto">${esc(R.acks[f.key] ?? f.ack ?? '')}</textarea></label>` : f.ack ? `<div class="l2 wrap" dir="auto">📝 ${esc(RT.ackSaved + ': ' + f.ack)}</div>` : ''}</div></li>`;
    if (x.frozen) {
        body.innerHTML = `<h2 class="sec-title">🧪 ${esc(RT.approvalNotes)} <small>${x.findings.length || ''}</small></h2>${x.findings.length ? `<ul class="list">${x.findings.map(f => row(f, false)).join('')}</ul>` : `<p class="empty">${esc(RT.checkNone)}</p>`}
            ${v.content_hash ? `<p class="hint" dir="auto">${esc(RT.hash)}: <span dir="ltr">${esc(v.content_hash.slice(0, 16))}…</span></p>` : ''}`;
        return;
    }
    body.innerHTML = `<h2 class="sec-title">⛔ ${esc(RT.blocking)} <small>${blocks.length || ''}</small></h2><p class="hint">${esc(RT.blockHint)}</p>
        ${blocks.length ? `<ul class="list">${blocks.map(f => row(f, false)).join('')}</ul>` : `<p class="empty">${esc(RT.checkNone)}</p>`}
        <h2 class="sec-title">⚠️ ${esc(RT.warnings)} <small>${warns.length || ''}</small></h2><p class="hint">${esc(RT.warnHint)}</p>
        ${warns.length ? `<ul class="list">${warns.map(f => row(f, true)).join('')}</ul>` : `<p class="empty">${esc(RT.checkNone)}</p>`}
        ${x.perms.approve ? `<div class="panel"><p class="hint">${esc(v.state !== 'in_review' ? RT.approveNotReview : blocks.length ? RT.approveBlocked(blocks.length) : RT.approveNote)}</p>
            <div class="row-btns"><button type="button" class="btn primary" id="r-approve"${canApprove && !blocks.length ? '' : ' disabled'}>✅ ${esc(RT.approve)}</button></div></div>` : ''}${rVersionActions()}`;
    rBindVersionActions();
    $$('[data-rgo-sec]', body).forEach(b => b.addEventListener('click', () => { R.sec = b.dataset.rgoSec; R.mode = 'edit'; rDraw(); }));
    $$('[data-rack]', body).forEach(t => t.addEventListener('input', () => { R.acks[t.dataset.rack] = t.value; }));
    $('#r-approve')?.addEventListener('click', async e => {
        const acks = {};
        $$('[data-rack]', body).forEach(t => { if (t.value.trim()) acks[t.dataset.rack] = t.value.trim(); });
        e.target.disabled = true;
        try {
            await api('report-approve', { body: { version: v.pid, rev: v.rev, acks } });   // פעולה רגישה: api() מבקש אישור חוזר
            toast(RT.approved);
            R.acks = {};
            R.mode = 'edit';
        } catch (err) { if (err.code !== 'confirm_cancelled') toastError(err); }
        rReload();
    });
}

// ── תצוגה מקדימה ──────────────────────────────────────────────

function rPreviewMode(body) {
    const x = R.x, v = x.version;
    const strip = x.frozen ? RT.previewApproved(v.number, v.approved_by, fmtDate(v.approved_at)) : v.state === 'in_review' ? RT.previewReview : RT.previewDraft;
    body.innerHTML = `<div class="rpreview"><p class="rstrip${x.frozen ? ' ok' : ''}" role="note">${esc(strip)}</p><h2 class="rtitle" dir="auto">${esc(x.case.title || C.noTitle)}</h2>
        ${x.sections.map(s => `<section><h3 dir="auto">${esc(s.title)}</h3>${rAiOpen(s) && !x.frozen ? `<p class="banner bad" role="note">🤖 ${esc(RT.aiUnreviewed)}</p>` : ''}
            ${rBlocksHtml(s.blocks, R.pid, { plain: true, aiOpen: rAiOpen(s) && !x.frozen, frozen: x.frozen })}</section>`).join('') || `<p class="empty">${esc(RT.noSections)}</p>`}
        <p class="rstrip${x.frozen ? ' ok' : ''}" role="note">${esc(strip)}</p></div>`;
}

// ── השוואת גרסאות ─────────────────────────────────────────────

async function rCompareMode(body) {
    const pid = R.pid, cur = R.x.version;
    body.innerHTML = `<p class="empty">…</p>`;
    try {
        R.versions = R.versions || (await api('case-report', { params: { case: pid } })).versions;
        const others = R.versions.filter(q => q.pid !== cur.pid);
        if (!others.length) { body.innerHTML = `<p class="empty">${esc(RT.compareNone)}</p>`; return; }
        if (!others.some(q => q.pid === R.cmp)) R.cmp = (others.find(q => q.number < cur.number) || others[0]).pid;
        const d = await api('report-compare', { params: { version: cur.pid, with: R.cmp } });
        if (R.mode !== 'compare') return;
        const changed = d.sections.filter(s => s.status !== 'same');
        body.innerHTML = `<label class="f"><span>${esc(RT.compareWith)}</span><select id="r-cmp">${others.map(q => cOpt(q.pid, RT.versionN(q.number) + ' · ' + (RT.states[q.state] || q.state), R.cmp)).join('')}</select></label>
            <p class="hint">${esc(RT.versionN(d.from.number))} ← ${esc(RT.versionN(d.to.number))}</p>
            ${changed.length ? changed.map(s => `<div class="panel rcmp"><div class="line"><b dir="auto">${esc(s.title)}</b>${rChip(RT.cmp[s.status] || s.status, s.status === 'added' ? 'ok' : s.status === 'removed' ? 'bad' : 'info')}</div>
                <ul class="diff">${s.removed.map(l => `<li class="del" dir="auto">− ${esc(l)}</li>`).join('')}${s.added.map(l => `<li class="add" dir="auto">+ ${esc(l)}</li>`).join('')}</ul></div>`).join('') : `<p class="empty">${esc(RT.cmpSameAll)}</p>`}`;
        $('#r-cmp').addEventListener('change', e => { R.cmp = e.target.value; rCompareMode(body); });
    } catch (e) { body.innerHTML = errorHtml(e); $('[data-retry]', body)?.addEventListener('click', () => rCompareMode(body)); }
}

// ── הערות לבודק ───────────────────────────────────────────────

function rCommentsMode(body) {
    const x = R.x, titles = Object.fromEntries(x.sections.map(s => [s.pid, s.title]));
    const can = !x.frozen && x.perms.edit;
    body.innerHTML = `${x.comments.length ? `<ul class="list">${x.comments.map(c => `<li class="card static"><span class="use-ic" aria-hidden="true">💬</span><div class="body"><div class="l1 wrap"><span dir="auto">${esc(c.text)}</span></div>
        <div class="l2 wrap">${esc(c.section ? (titles[c.section] || RT.commentSection) : RT.commentAll)} · ${esc(c.by || C.system)} · ${esc(fmtDateTime(c.at))}</div>
        ${c.resolved_at ? `<div class="chips">${rChip(RT.resolved(c.resolved_by, fmtDate(c.resolved_at)), 'ok')}</div>` : can ? `<div class="row-btns"><button type="button" class="btn ghost sm" data-rresolve="${esc(c.pid)}">${esc(RT.resolve)}</button></div>` : ''}</div></li>`).join('')}</ul>` : `<p class="empty">${esc(RT.commentsNone)}</p>`}
        ${can ? `<div class="row-btns"><button type="button" class="btn ghost" id="r-cadd"><span aria-hidden="true">+</span> ${esc(RT.commentAdd)}</button></div>` : ''}`;
    $$('[data-rresolve]', body).forEach(b => b.addEventListener('click', () => rAct('report-comment-resolve', { comment: b.dataset.rresolve })));
    $('#r-cadd')?.addEventListener('click', () => sheet(RT.commentAdd, `<label class="f"><span>${esc(RT.commentSection)}</span><select name="section"><option value="">${esc(RT.commentAll)}</option>${x.sections.map(s => cOpt(s.pid, s.title, R.sec)).join('')}</select></label>
        <label class="f"><span>${esc(RT.commentText)}</span><textarea name="text" maxlength="2000" rows="4" required dir="auto"></textarea></label>`,
        async f => { await api('report-comment-add', { body: { version: R.v, section: f.section || null, text: f.text } }); rReload(); }));
}

// ── עורך הפרופיל ← "דוח" (אפיון §4) ────────────────────────────

const R_AI_KEYS = ['property', 'area_desc', 'visit', 'planning'];    // הרשימה הסגורה — השרת אוכף (APR_REPORT_AI_KEYS)
const R_LOCKED = ['no_approver', 'mandatory_empty', 'ai_unreviewed', 'image_not_report', 'ai_stale', 'data_new'];

function peReport(body) {
    const B = RT.b, r = PE.content.report;
    if (!r) { body.innerHTML = `<p class="empty">${esc(RT.unknown)}</p>`; return; }
    const aiOk = s => s.build === 'data' && R_AI_KEYS.includes(s.key);
    body.innerHTML = `<form class="panel" id="prep" novalidate><p class="hint">${esc(B.hint)}</p>
        <label class="f"><span>${esc(B.trigger)}</span><select name="trigger">${Object.keys(B.triggers).map(o => cOpt(o, B.triggers[o], r.trigger)).join('')}</select></label>
        <h3 class="sec-title">${esc(B.sections)} <small>${r.sections.length}</small></h3>
        ${r.sections.map((s, i) => `<div class="rblk" data-i="${i}"><div class="rblk-h"><span dir="ltr" class="muted">${esc(s.key)}</span>
            <button type="button" class="icon-btn" data-pr-up="${i}" aria-label="${esc(RT.moveUp)}"${i === 0 ? ' disabled' : ''}>▲</button><button type="button" class="icon-btn" data-pr-down="${i}" aria-label="${esc(RT.moveDown)}"${i === r.sections.length - 1 ? ' disabled' : ''}>▼</button>
            <button type="button" class="icon-btn" data-pr-del="${i}" aria-label="${esc(B.remove)}">✕</button></div>
            <label class="f"><span>${esc(B.title)}</span><input data-pr="title" maxlength="120" dir="auto" value="${esc(s.title)}"></label>
            <label class="f"><span>${esc(B.build)}</span><select data-pr="build">${Object.keys(B.builds).map(o => cOpt(o, B.builds[o], s.build)).join('')}</select></label>
            <label class="switch"><input type="checkbox" data-pr="mandatory"${s.mandatory ? ' checked' : ''}><span>${esc(B.mandatory)}</span></label>
            <label class="switch"><input type="checkbox" data-pr="ai"${s.ai && aiOk(s) ? ' checked' : ''}${aiOk(s) ? '' : ' disabled'}><span>${esc(aiOk(s) ? B.ai : B.aiNever)}</span></label></div>`).join('')}
        <div class="row-btns"><button type="button" class="btn ghost" id="pr-add"><span aria-hidden="true">+</span> ${esc(B.add)}</button></div>
        <label class="f"><span>${esc(B.unverified)}</span><select name="include_unverified">${Object.keys(B.unverifiedModes).map(o => cOpt(o, B.unverifiedModes[o], r.include_unverified)).join('')}</select></label>
        <label class="switch"><input type="checkbox" name="include_excluded_comps"${r.include_excluded_comps ? ' checked' : ''}><span>${esc(B.excluded)}</span></label>
        <h3 class="sec-title">${esc(B.findings)}</h3><p class="hint">${esc(B.findingsHint)}</p>
        ${Object.keys(B.kinds).map(k => `<label class="f"><span>${esc(B.kinds[k])}</span><select data-pr-finding="${k}"${R_LOCKED.includes(k) ? ' disabled' : ''}>${Object.keys(B.levels).map(o => cOpt(o, R_LOCKED.includes(k) && o === 'block' ? B.locked : B.levels[o], R_LOCKED.includes(k) ? 'block' : (r.findings || {})[k] || 'warn')).join('')}</select></label>`).join('')}</form>`;
    const form = $('#prep');
    const collect = () => {
        r.trigger = form.trigger.value;
        r.include_unverified = form.include_unverified.value;
        r.include_excluded_comps = form.include_excluded_comps.checked;
        $$('.rblk', form).forEach(el => {
            const s = r.sections[+el.dataset.i];
            s.title = $('[data-pr="title"]', el).value;
            s.build = $('[data-pr="build"]', el).value;
            s.mandatory = $('[data-pr="mandatory"]', el).checked;
            s.ai = aiOk(s) && $('[data-pr="ai"]', el).checked;
        });
        r.findings = r.findings || {};
        $$('[data-pr-finding]', form).forEach(el => { r.findings[el.dataset.prFinding] = R_LOCKED.includes(el.dataset.prFinding) ? 'block' : el.value; });
    };
    form.addEventListener('change', e => { collect(); peChanged(); if (e.target.dataset.pr === 'build') peReport(body); });
    const structural = fn => { collect(); fn(); peChanged(); peReport(body); };
    $$('[data-pr-up]', form).forEach(b => b.addEventListener('click', () => structural(() => { const i = +b.dataset.prUp; [r.sections[i - 1], r.sections[i]] = [r.sections[i], r.sections[i - 1]]; })));
    $$('[data-pr-down]', form).forEach(b => b.addEventListener('click', () => structural(() => { const i = +b.dataset.prDown; [r.sections[i + 1], r.sections[i]] = [r.sections[i], r.sections[i + 1]]; })));
    $$('[data-pr-del]', form).forEach(b => b.addEventListener('click', () => structural(() => r.sections.splice(+b.dataset.prDel, 1))));
    $('#pr-add').addEventListener('click', () => structural(() => {
        let n = r.sections.length + 1;
        while (r.sections.some(s => s.key === 'section_' + n)) n++;
        r.sections.push({ key: 'section_' + n, title: B.add, mandatory: false, build: 'manual', ai: false });
    }));
}
