/*
 * מסמכים (אפיון 06): לשונית "דרישות וקבצים" בכרטיס התיק, דף דרישה (קבצים, גרסאות, היסטוריה, פעולות), מסך קובץ
 * (המציג, הפרטים והבדיקה — זה לצד זה במחשב, זה מעל זה בטלפון), גיליון העלאה (בחירה, גרירה, צילום), וקבצים שלא שויכו.
 * כל ערך שמגיע ממשתמש — כולל שם קובץ — עובר esc(). קובץ מוצג רק דרך api.php: תמונה ב-<img>, PDF ב-iframe של blob;
 * שום דבר אחר אינו נפתח בדף. ההרשאות נאכפות בשרת; כאן רק לא מציגים כפתור שיידחה.
 */
'use strict';

const D = T.d;
const DF = { pid: null, status: '', party: '', type: '', priority: '', q: '' };   // הסינון של הלשונית, לתיק הנוכחי
let DBLOB = null;                                                                  // כתובת blob של התצוגה המקדימה — משוחררת במעבר

const dChipCls = { required: '', requested: 'info', received: 'warn', returned: 'bad', approved: 'ok', not_relevant: '' };
const dStatusChip = r => r.split ? `<span class="chip">${D.split}</span>` : `<span class="chip ${dChipCls[r.status] || ''}">${esc(D.statuses[r.status] || r.status)}</span>`;
const dReviewChip = l => `<span class="chip ${({ pending: 'warn', approved: 'ok', returned: 'bad', rejected: 'bad' })[l.review_state]}">${esc(D.review[l.review_state])}</span>`;
const dSize = n => `<span dir="ltr">${n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toFixed(1) + ' MB'}</span>`;
const dIcon = f => ({ pdf: '📕', image: '🖼️', office: '📝', package: '🗜️' })[f.kind] || '📄';
const dUrl = (pid, inline) => `api.php?action=file&file=${encodeURIComponent(pid)}${inline ? '&inline=1' : ''}`;
const dThumb = f => f.thumb ? `<img class="thumb" src="api.php?action=file-thumb&file=${encodeURIComponent(f.pid)}" alt="" loading="lazy" data-ic="${dIcon(f)}">` : `<span class="use-ic" aria-hidden="true">${dIcon(f)}</span>`;
const dOpen = r => !r.split && !['approved', 'not_relevant'].includes(r.status);
const dPartyName = (r, d) => { const p = r.party && d.parties.find(x => x.pid === r.party); return p ? p.contact.name : ''; };
/** תמונה ממוזערת שלא נוצרה (אין GD, קובץ פגום) — אייקון הסוג במקומה. */
function dThumbsBind(root = document) {
    $$('img.thumb', root).forEach(i => i.addEventListener('error', () => i.replaceWith(Object.assign(document.createElement('span'), { className: 'use-ic', textContent: i.dataset.ic }))));
}

/** העלאת קובץ אחד: הגוף הגולמי, הפרמטרים בכתובת. מחזיר את תשובת השרת ({file} או {duplicate}). */
async function apiUpload(params, file) {
    const qs = new URLSearchParams({ action: 'file-upload', ...params });
    let res;
    try {
        res = await fetch('api.php?' + qs, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': App.me?.csrf || '', 'Content-Type': 'application/octet-stream' }, body: file });
    } catch {
        DevLoop.noteRequest({ action: 'file-upload', method: 'POST', status: 0, id: '' });
        throw Object.assign(new Error(T.noConnection), { code: 'network', status: 0, req: '' });
    }
    const req = res.headers.get('X-Request-Id') || '';
    DevLoop.noteRequest({ action: 'file-upload', method: 'POST', status: res.status, id: req });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error?.message || T.fault(res.status)), { code: data.error?.code || '', status: res.status, req });
    return data;
}

function fileRow(f, pid, l) {
    const sub = [D.received(fmtDate(f.received_at)), f.uploader ? esc(f.uploader) : '', dSize(f.size)].filter(Boolean).join(' · ');
    return `<li class="card${f.deleted ? ' static' : ''}"${f.deleted ? '' : ` tabindex="0" role="button" data-go="#/cases/${pid}/file/${f.pid}"`}>${f.deleted ? `<span class="use-ic" aria-hidden="true">🗑️</span>` : dThumb(f)}
        <div class="body"><div class="l1"><span dir="auto">${esc(f.name)}</span>${l ? `<span class="t">${esc(l.relation === 'version' ? D.versionN(l.version) : D.attachmentOf(l.version))}</span>` : ''}</div>
        <div class="l2 wrap">${sub}${l && l.review_state === 'returned' && l.return_reason ? ' · ' + esc(l.return_reason) : ''}</div>
        <div class="chips">${l ? dReviewChip(l) : ''}${l && l.relation === 'version' && l.review_state !== 'rejected' ? `<span class="chip${l.current ? ' info' : ''}">${l.current ? D.current : D.replaced}</span>` : ''}${
            f.deleted ? `<span class="chip bad">${D.deleted}</span>` : ''}${f.encrypted ? `<span class="chip warn">${D.encrypted}</span>` : ''}${f.tags.map(t => `<span class="chip">${esc(t)}</span>`).join('')}</div></div></li>`;
}

function docReqRow(r, d, pid, drag) {
    const n = r.files.filter(l => l.review_state !== 'rejected' && !l.file.deleted).length;
    const party = dPartyName(r, d);
    return `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/reqs/${r.pid}" data-rid="${r.pid}" data-group="${esc(r.group || '')}"${drag ? ' draggable="true"' : ''}><span class="use-ic" aria-hidden="true">📄</span>
        <div class="body"><div class="l1"><span>${esc(r.title)}</span>${r.due_at ? `<span class="t">${esc(fmtDate(r.due_at))}</span>` : ''}</div>
        <div class="l2 wrap">${esc(party ? C.assignedTo(party) : r.party_kind === 'office' ? P.partyKinds.office : C.notAssigned)}${r.status_reason && ['returned', 'not_relevant'].includes(r.status) ? ' · ' + esc(r.status_reason) : ''}</div>
        <div class="chips">${dStatusChip(r)}${r.mandatory ? `<span class="chip warn">${C.mandatory}</span>` : ''}${r.priority === 'high' ? `<span class="chip bad">${C.priority}: ${esc(P.priorities.high)}</span>` : ''}${
            n ? `<span class="chip">📎 ${n}</span>` : ''}</div></div></li>`;
}

/** הלשונית "דרישות וקבצים". מחזיר { html, bind } — viewCase מצייר וקורא ל-bind. */
async function docsTab(pid, d, reload) {
    const x = await api('case-docs', { params: { case: pid } });
    if (DF.pid !== pid) Object.assign(DF, { pid, status: '', party: '', type: '', priority: '', q: '' });
    const closed = d.case.status === 'closed', can = x.perms.documents && !closed;
    const live = x.requirements.filter(r => !r.split), split = x.requirements.filter(r => r.split);
    const hasFile = r => r.files.some(l => l.current && !l.file.deleted);
    const byStatus = { missing: r => ['required', 'requested', 'returned'].includes(r.status), uploaded: hasFile, review: r => r.status === 'received', approved: r => r.status === 'approved', not_relevant: r => r.status === 'not_relevant' };
    const q = DF.q.toLowerCase();
    const match = r => (!DF.status || byStatus[DF.status](r)) && (!DF.party || r.party === DF.party) && (!DF.type || r.doc_type === DF.type) && (!DF.priority || r.priority === DF.priority)
        && (!q || r.title.toLowerCase().includes(q) || r.files.some(l => l.file.name.toLowerCase().includes(q) || l.file.tags.some(t => t.toLowerCase().includes(q))));
    const shown = live.filter(match);
    const loose = x.unassigned.filter(f => !q || f.name.toLowerCase().includes(q) || f.tags.some(t => t.toLowerCase().includes(q)));
    const groups = [...x.groups.map(g => [g.key, g.title]), [null, C.other]], known = new Set(x.groups.map(g => g.key));
    const of = k => shown.filter(r => k === null ? !known.has(r.group) : r.group === k);
    const extra = ['party', 'type', 'priority'].filter(k => DF[k]).length;
    const filtered = DF.status || extra || q;
    const html = `<div id="docs-root">
        <form class="toolbar" id="dq"><input type="search" name="q" value="${esc(DF.q)}" placeholder="${D.search}" aria-label="${D.search}" style="flex:1">
          <button type="button" class="btn ghost" id="dfilter">${extra ? esc(C.filtersOn(extra)) : C.filters}</button>
          ${can ? `<button type="button" class="btn primary" id="dup"><span aria-hidden="true">⬆</span> ${D.upload}</button>` : ''}</form>
        <div class="chips" role="group" aria-label="${C.fStatus}">${[['', C.any], ...Object.keys(byStatus).map(k => [k, D.filters[k]])].map(([k, label]) =>
            `<button type="button" class="chip${DF.status === k ? ' info' : ''}" data-fs="${k}" aria-pressed="${DF.status === k}">${label}${k ? ` <small>${live.filter(byStatus[k]).length}</small>` : ''}</button>`).join('')}</div>
        ${shown.length ? groups.filter(([k]) => of(k).length).map(([k, title]) => `${known.size ? `<h2 class="sec-title">${esc(title)} <small>${of(k).length}</small></h2>` : ''}
            <ul class="list" data-list="${esc(k || '')}">${of(k).map(r => docReqRow(r, d, pid, can && !filtered)).join('')}</ul>`).join('') : `<p class="empty">${live.length ? D.noMatch : C.noRequirements}</p>`}
        ${can ? `<div class="row-btns"><button type="button" class="btn ghost" id="radd"><span aria-hidden="true">+</span> ${C.addRequirement}</button></div>
          <p class="hint">${D.dropHint}${filtered ? '' : ' ' + D.dragHint}</p>` : ''}
        ${loose.length || (x.unassigned.length && q) ? `<h2 class="sec-title" id="loose">📎 ${D.unassigned} <small>${loose.length}</small></h2><ul class="list">${loose.map(f => fileRow(f, pid, null)).join('')}</ul>` : ''}
        ${split.length ? `<details><summary class="hint">${esc(D.splitN(split.length))}</summary><ul class="list">${split.map(r => `<li class="card static"><span class="use-ic" aria-hidden="true">🔀</span>
            <div class="body"><div class="l1"><span>${esc(r.title)}</span></div><div class="chips">${dStatusChip(r)}</div></div></li>`).join('')}</ul></details>` : ''}</div>`;
    const bind = () => {
        const root = $('#docs-root'), again = () => viewCase(pid, 'reqs');
        dThumbsBind(root);
        $('#dq').addEventListener('submit', e => { e.preventDefault(); DF.q = e.target.q.value.trim(); again(); });
        $('#dq').q.addEventListener('search', e => { if (!e.target.value && DF.q) { DF.q = ''; again(); } });
        $$('[data-fs]', root).forEach(b => b.addEventListener('click', () => { DF.status = b.dataset.fs; again(); }));
        $('#dfilter').addEventListener('click', () => {
            sheet(C.filters, `<label class="f"><span>${D.byParty}</span><select name="party"><option value="">${C.any}</option>${d.parties.map(p => cOpt(p.pid, p.contact.name, DF.party)).join('')}</select></label>
                <label class="f"><span>${C.docType}</span><select name="type"><option value="">${C.any}</option>${PL.doc_types.map(t => cOpt(t.key, t.name, DF.type)).join('')}</select></label>
                <label class="f"><span>${C.priority}</span><select name="priority"><option value="">${C.any}</option>${PL.priorities.map(v => cOpt(v, P.priorities[v], DF.priority)).join('')}</select></label>`,
                async v => { Object.assign(DF, { party: v.party, type: v.type, priority: v.priority }); again(); }, C.apply);
        });
        $('#dup')?.addEventListener('click', () => uploadSheet(pid, x, null, [], reload));
        if (!can) return;
        // גרירת קבצים מהמחשב אל הלשונית פותחת את גיליון ההעלאה איתם
        root.addEventListener('dragover', e => { if ([...e.dataTransfer.types].includes('Files')) e.preventDefault(); });
        root.addEventListener('drop', e => {
            if (!e.dataTransfer.files.length) return;
            e.preventDefault();
            uploadSheet(pid, x, e.target.closest('[data-rid]')?.dataset.rid || null, [...e.dataTransfer.files], reload);
        });
        // גרירת שורה: סדר בתוך קבוצה ובין קבוצות
        let moving = null;
        $$('[data-rid][draggable]', root).forEach(li => {
            li.addEventListener('dragstart', e => { moving = li.dataset.rid; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', moving); li.classList.add('dragging'); });
            li.addEventListener('dragend', () => { moving = null; li.classList.remove('dragging'); });
            li.addEventListener('dragover', e => { if (moving && moving !== li.dataset.rid) e.preventDefault(); });
            li.addEventListener('drop', async e => {
                if (!moving || moving === li.dataset.rid) return;
                e.preventDefault();
                e.stopPropagation();
                const order = live.map(r => r.pid).filter(p => p !== moving);
                order.splice(order.indexOf(li.dataset.rid), 0, moving);
                try {
                    await api('requirement-order', { body: { case: pid, items: order.map(p => p === moving ? { requirement: p, group: li.dataset.group || null } : { requirement: p }) } });
                    reload();
                } catch (x2) { toastError(x2); }
            });
        });
    };
    return { html, bind };
}

/**
 * גיליון העלאה: כמה קבצים, כל אחד עם שיוך משלו. כפילות — השרת עונה duplicate, והשורה מחכה להחלטה.
 * כל קובץ נושא מזהה פעולה: ניסיון חוזר אחרי נפילת חיבור אינו יוצר עותק.
 */
function uploadSheet(pid, x, presetReq, first, reload) {
    const reqs = x.requirements.filter(r => !r.split && r.status !== 'not_relevant');
    const items = [];
    const hasCur = rp => !!reqs.find(r => r.pid === rp)?.files.some(l => l.current && !l.file.deleted);
    const dlg = sheet(D.upload, `<label class="drop" id="udrop"><input type="file" id="ufiles" multiple hidden><b>${D.pick}</b><span class="muted">${esc(D.limits(Math.floor(x.limits.max_size / 1048576), x.limits.types.join(', ')))}</span></label>
        <div class="row-btns"><label class="btn ghost"><input type="file" id="ucam" accept="image/*" capture="environment" hidden><span aria-hidden="true">📷</span> ${D.camera}</label></div>
        <label class="f"><span>${D.sourceNote}</span><input name="source_note" maxlength="200" placeholder="${D.sourceHint}"></label>
        <ul class="list" id="ulist"></ul>`, async v => {
        let left = 0;
        for (const it of items) {
            if (it.state === 'done' || it.state === 'skip') continue;
            if (it.file.size > x.limits.max_size) { it.state = 'error'; it.msg = D.tooBig(Math.floor(x.limits.max_size / 1048576)); left++; draw(); continue; }
            it.state = 'busy'; it.msg = D.uploading; draw();
            try {
                const r = await apiUpload({ case: pid, name: it.file.name, key: it.key, ...(it.req ? { requirement: it.req, relation: it.relation } : {}), ...(it.dup ? { dup: '1' } : {}), ...(v.source_note ? { source_note: v.source_note } : {}) }, it.file);
                if (r.duplicate) { it.state = 'dup'; it.msg = D.dupMsg(r.duplicate.requirement, fmtDate(r.duplicate.received_at)); left++; }
                else { it.state = 'done'; it.msg = D.uploaded; }
            } catch (e) { it.state = 'error'; it.msg = e.message; left++; }
            draw();
        }
        if (items.some(i => i.state === 'done')) reload();
        if (left || !items.length) return false;
        toast(D.uploadedN(items.filter(i => i.state === 'done').length));
    }, D.uploadDo);
    function draw() {
        $('#ulist', dlg).innerHTML = items.map((it, i) => `<li class="card static up-row"><span class="use-ic" aria-hidden="true">${({ done: '✅', error: '⚠️', dup: '♻️', busy: '⏳', skip: '🚫' })[it.state] || '📄'}</span>
            <div class="body"><div class="l1"><span dir="auto">${esc(it.file.name)}</span><span class="t">${dSize(it.file.size)}</span></div>
            ${it.msg ? `<div class="l2 wrap${it.state === 'error' ? ' error' : ''}">${esc(it.msg)}</div>` : ''}
            ${it.state === 'dup' ? `<div class="row-btns"><button type="button" class="btn ghost sm" data-dup="${i}">${D.dupAnyway}</button><button type="button" class="btn ghost sm" data-skip="${i}">${D.dupCancel}</button></div>` : ''}
            ${['new', 'error'].includes(it.state) ? `<div class="fields two"><select data-req="${i}" aria-label="${D.assignTo}"><option value="">${D.noAssign}</option>${reqs.map(r => cOpt(r.pid, r.title, it.req)).join('')}</select>
              ${it.req && hasCur(it.req) ? `<select data-rel="${i}" aria-label="${D.relation}">${cOpt('version', D.asVersion, it.relation)}${cOpt('attachment', D.asAttachment, it.relation)}</select>` : ''}
              <button type="button" class="btn ghost sm" data-rm="${i}">${D.remove}</button></div>` : ''}</div></li>`).join('');
        $$('[data-req]', dlg).forEach(s => s.addEventListener('change', () => { items[s.dataset.req].req = s.value; draw(); }));
        $$('[data-rel]', dlg).forEach(s => s.addEventListener('change', () => { items[s.dataset.rel].relation = s.value; }));
        $$('[data-rm]', dlg).forEach(b => b.addEventListener('click', () => { items.splice(b.dataset.rm, 1); draw(); }));
        $$('[data-skip]', dlg).forEach(b => b.addEventListener('click', () => { Object.assign(items[b.dataset.skip], { state: 'skip', msg: D.dupSkipped }); draw(); }));
        $$('[data-dup]', dlg).forEach(b => b.addEventListener('click', () => { Object.assign(items[b.dataset.dup], { state: 'new', dup: true, msg: D.dupWill }); draw(); }));
    }
    const add = files => { for (const f of files) items.push({ file: f, key: opId(), req: presetReq || '', relation: 'version', state: 'new', msg: '' }); draw(); };
    for (const id of ['#ufiles', '#ucam']) $(id, dlg).addEventListener('change', e => { add([...e.target.files]); e.target.value = ''; });
    const drop = $('#udrop', dlg);
    drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); add([...e.dataTransfer.files]); });
    add(first);
}

/** נימוק בגיליון, ואז פעולה. */
function reasonSheet(title, hint, run, label) {
    sheet(title, `${hint ? `<p class="hint">${esc(hint)}</p>` : ''}<label class="f"><span>${C.reason} *</span><textarea name="reason" maxlength="500" rows="3" required></textarea></label>`, async v => { await run(v.reason); }, label || T.save);
}

/** דף דרישה: פרטים, קבצים וגרסאות, היסטוריה, פעולות. */
async function viewRequirement(pid, rpid) {
    const [d, x, log] = await Promise.all([api('case', { params: { case: pid } }), api('case-docs', { params: { case: pid } }), api('case-activity', { params: { case: pid } })]);
    const r = x.requirements.find(q => q.pid === rpid);
    if (!r) { location.replace(`#/cases/${pid}/reqs`); return; }
    setHead(d.case.number, null);
    const closed = d.case.status === 'closed', can = x.perms.documents && !closed && !r.split, rev = x.perms.review_docs && !closed && !r.split;
    const reload = () => viewRequirement(pid, rpid);
    const party = dPartyName(r, d);
    const live = x.requirements.filter(q => !q.split).map(q => q.pid), at = live.indexOf(rpid);
    const hist = log.rows.filter(a => a.entity === rpid || a.meta.requirement === rpid);
    const btn = (id, label, cls = 'ghost') => `<button type="button" class="btn ${cls}" id="${id}">${label}</button>`;
    App.main.innerHTML = `<div class="summary"><div class="line"><b>${esc(r.title)}</b>${dStatusChip(r)}</div>
          ${r.status_reason ? `<div class="line muted">${esc(r.status_reason)}</div>` : ''}
          ${r.status_at ? `<div class="line muted">${esc(D.statusBy(r.status_by || C.system, fmtDateTime(r.status_at)))}</div>` : ''}</div>
        <dl class="cells"><div><dt>${C.supplier}</dt><dd>${esc(party || C.notAssigned)}</dd></div><div><dt>${C.priority}</dt><dd>${esc(P.priorities[r.priority] || r.priority)}</dd></div>
          <div><dt>${C.mandatory}</dt><dd>${r.mandatory ? D.yes : D.no}</dd></div><div><dt>${C.taskDue}</dt><dd>${r.due_at ? esc(fmtDate(r.due_at)) : C.unknown}</dd></div>
          <div><dt>${C.docType}</dt><dd>${esc(PL.doc_types.find(t => t.key === r.doc_type)?.name || r.doc_type || '')}</dd></div><div><dt>${D.origin}</dt><dd>${esc(C.reqOrigins[r.origin] || r.origin)}</dd></div></dl>
        ${r.explain_recipient ? `<p class="hint"><b>${C.explainRecipient}:</b> ${esc(r.explain_recipient)}</p>` : ''}${r.explain_internal ? `<p class="hint"><b>${C.explainInternal}:</b> ${esc(r.explain_internal)}</p>` : ''}
        ${r.split ? `<p class="banner" role="note">🔀 ${D.splitNote}</p>` : ''}
        <div class="row-btns wrap">${can && r.status !== 'not_relevant' ? btn('rup', `<span aria-hidden="true">⬆</span> ${D.upload}`, 'primary') : ''}${can ? btn('redit', D.edit) : ''}
          ${rev && ['required', 'requested', 'returned'].includes(r.status) ? btn('rnr', D.markNotRelevant) : ''}${rev && r.status === 'not_relevant' ? btn('rback', D.unmark) : ''}
          ${can && r.status === 'requested' ? btn('rcancel', D.cancelRequest) : ''}${rev && r.status === 'approved' ? btn('rreopen', D.reopen) : ''}
          ${can && r.status === 'required' && !r.files.some(l => l.current) && d.parties.length > 1 ? btn('rsplit', D.splitDo) : ''}
          ${can && at > 0 ? btn('rupw', '▲ ' + D.moveUp) : ''}${can && at > -1 && at < live.length - 1 ? btn('rdown', '▼ ' + D.moveDown) : ''}</div>
        <h2 class="sec-title">📎 ${D.filesTitle} <small>${r.files.length || ''}</small></h2>
        ${r.files.length ? `<ul class="list">${r.files.map(l => fileRow(l.file, pid, l)).join('')}</ul>` : `<p class="empty">${D.noFiles}</p>`}
        <h2 class="sec-title">🕘 ${D.history}</h2>
        ${hist.length ? auditHtml(hist, a => esc((T.audit[a.action] || (() => a.action))(a)), a => esc(a.actor || C.system)) : `<p class="empty">${C.activityEmpty}</p>`}`;
    dThumbsBind();
    const setStatus = (status, title, hint) => reasonSheet(title, hint, async reason => { await api('requirement-status', { body: { requirement: rpid, status, reason } }); reload(); });
    $('#rup')?.addEventListener('click', () => uploadSheet(pid, x, rpid, [], reload));
    $('#rnr')?.addEventListener('click', () => setStatus('not_relevant', D.markNotRelevant, D.notRelevantHint));
    $('#rback')?.addEventListener('click', () => setStatus('required', D.unmark, ''));
    $('#rreopen')?.addEventListener('click', () => setStatus('received', D.reopen, D.reopenHint));
    $('#rcancel')?.addEventListener('click', async () => { try { await api('requirement-status', { body: { requirement: rpid, status: 'required' } }); reload(); } catch (e) { toastError(e); } });
    $('#redit')?.addEventListener('click', () => {
        requirementSheet(r, async v => {
            const body = { requirement: rpid, ...v };
            if (r.mandatory && !v.mandatory) {
                // הורדת "חובה" דורשת נימוק (והרשאת בדיקה) — מבקשים אותו בגיליון נפרד, אחרי שהראשון נסגר
                setTimeout(() => reasonSheet(D.dropMandatory, D.dropMandatoryHint, async reason => { await api('requirement-update', { body: { ...body, reason } }); reload(); }), 0);
                return;
            }
            await api('requirement-update', { body });
            reload();
        }, d);
    });
    const move = async by => {
        const order = [...live];
        order.splice(at, 1);
        order.splice(at + by, 0, rpid);
        try { await api('requirement-order', { body: { case: pid, items: order.map(p => ({ requirement: p })) } }); toast(D.moved); reload(); } catch (e) { toastError(e); }
    };
    $('#rupw')?.addEventListener('click', () => move(-1));
    $('#rdown')?.addEventListener('click', () => move(1));
    $('#rsplit')?.addEventListener('click', () => {
        sheet(D.splitDo, `<p class="hint">${D.splitHint}</p>${d.parties.map(p => `<label class="switch"><input type="checkbox" name="p_${p.pid}"><span>${esc(p.contact.name)} · ${esc(C.partyKinds[p.kind] || p.kind)}</span></label>`).join('')}`, async v => {
            const parts = d.parties.filter(p => v['p_' + p.pid] === 'on').map(p => ({ party: p.pid }));
            await api('requirement-split', { body: { requirement: rpid, parts } });
            toast(D.splitDone);
            location.hash = `#/cases/${pid}/reqs`;
        }, D.splitDo);
    });
}

/** מסך קובץ: המציג, הפרטים, והבדיקה — לכל דרישה שהקובץ משויך אליה בדיקה משלה. */
async function viewFile(pid, fpid) {
    const [d, x] = await Promise.all([api('case', { params: { case: pid } }), api('case-docs', { params: { case: pid } })]);
    const links = x.requirements.flatMap(r => r.files.filter(l => l.file.pid === fpid).map(l => ({ ...l, req: r })));
    const f = links[0]?.file || x.unassigned.find(u => u.pid === fpid);
    if (!f || f.deleted) { App.main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">🗑️</div><h2>${D.fileGone}</h2><div><a class="btn ghost" href="#/cases/${pid}/reqs">${C.tabs.reqs}</a></div></div>`; return; }
    setHead(d.case.number, null);
    App.main.classList.add('wide');
    const closed = d.case.status === 'closed', can = x.perms.documents && !closed, rev = x.perms.review_docs && !closed;
    const reload = () => viewFile(pid, fpid);
    const cell = (k, v) => `<div><dt>${k}</dt><dd dir="auto">${v}</dd></div>`;
    const chk = v => v === true ? '✔' : v === false ? '✘' : '—';
    const view = f.mime.startsWith('image/') && f.preview ? `<img src="${dUrl(f.pid, true)}" alt="${esc(f.name)}">`
        : f.mime === 'application/pdf' ? `<div class="doc-wait" id="pdfbox"><span aria-hidden="true" class="big">📕</span><p class="muted">${D.loadingPreview}</p></div>`
        : `<div class="doc-wait"><span aria-hidden="true" class="big">${dIcon(f)}</span><p class="muted">${f.kind === 'package' ? D.packageNote : D.noPreview}</p></div>`;
    const linkHtml = l => {
        const reviewable = rev && l.current && l.review_state === 'pending' && !l.req.split && l.req.status !== 'not_relevant';
        return `<div class="panel" data-link="${l.link}"><div class="line"><a href="#/cases/${pid}/reqs/${l.req.pid}"><b>${esc(l.req.title)}</b></a></div>
            <div class="chips"><span class="chip">${esc(l.relation === 'version' ? D.versionN(l.version) : D.attachmentOf(l.version))}</span>${dReviewChip(l)}${l.relation === 'version' && l.review_state !== 'rejected' ? `<span class="chip${l.current ? ' info' : ''}">${l.current ? D.current : D.replaced}</span>` : ''}</div>
            ${l.reviewed_at ? `<p class="hint">${esc(D.reviewedBy(l.reviewed_by || C.system, fmtDateTime(l.reviewed_at)))} · ${D.chkReadable} ${chk(l.chk.readable)} · ${D.chkComplete} ${chk(l.chk.complete)} · ${D.chkMatches} ${chk(l.chk.matches)}</p>` : ''}
            ${l.return_reason ? `<p class="hint"><b>${l.review_state === 'rejected' ? D.rejectReason : D.returnReason}:</b> ${l.return_code ? esc(D.returnCodes[l.return_code]) + ' — ' : ''}${esc(l.return_reason)}</p>` : l.return_code ? `<p class="hint"><b>${D.returnReason}:</b> ${esc(D.returnCodes[l.return_code])}</p>` : ''}
            ${l.review_note ? `<p class="hint"><b>${D.note}:</b> ${esc(l.review_note)}</p>` : ''}
            ${reviewable ? `<fieldset class="review"><legend class="sec-title">${D.reviewTitle}</legend>
              ${f.encrypted ? `<p class="banner" role="note">🔒 ${D.encryptedNote}</p>` : ''}
              <label class="switch"><input type="checkbox" data-chk="chk_readable"><span>${D.chkReadable}</span></label>
              <label class="switch"><input type="checkbox" data-chk="chk_complete"><span>${D.chkComplete}</span></label>
              <label class="switch"><input type="checkbox" data-chk="chk_matches"><span>${D.chkMatches}</span></label>
              <label class="f"><span>${D.docDate}</span><input type="date" data-f="doc_date" value="${esc(f.doc_date || '')}"></label>
              <label class="f"><span>${D.note}</span><textarea data-f="note" maxlength="600" rows="2"></textarea></label>
              <div class="row-btns wrap"><button type="button" class="btn primary" data-do="approve" disabled>${D.approve}</button><button type="button" class="btn ghost" data-do="return">${D.returnDo}</button>
                <button type="button" class="btn ghost danger" data-do="reject">${D.reject}</button></div><p class="hint">${D.approveHint}</p></fieldset>` : ''}</div>`;
    };
    App.main.innerHTML = `<div class="doc-grid"><div class="doc-view">${view}</div>
        <div class="doc-side"><div class="summary"><div class="line"><b dir="auto">${esc(f.name)}</b></div>
            <div class="chips">${f.encrypted ? `<span class="chip warn">${D.encrypted}</span>` : ''}<span class="chip${f.scan_state === 'clean' ? ' ok' : ''}">${f.scan_state === 'clean' ? D.scanClean : D.scanNone}</span>${f.tags.map(t => `<span class="chip">${esc(t)}</span>`).join('')}</div></div>
          <div class="row-btns wrap"><a class="btn ghost" href="${dUrl(f.pid)}" download><span aria-hidden="true">⬇</span> ${D.download}</a>${can ? `<button type="button" class="btn ghost" id="fedit">${D.editFile}</button><button type="button" class="btn ghost" id="fassign">${D.assign}</button>` : ''}
            ${rev ? `<button type="button" class="btn ghost danger" id="fdel">${D.del}</button>` : ''}</div>
          <dl class="cells">${cell(D.receivedAt, esc(fmtDateTime(f.received_at)))}${cell(D.docDate, f.doc_date ? esc(fmtDate(f.doc_date)) : C.unknown)}${cell(D.source, esc(D.channels[f.channel] || f.channel) + (f.source_note ? ' · ' + esc(f.source_note) : ''))}
            ${cell(D.uploader, esc(f.uploader || C.system))}${cell(D.type, esc(D.kinds[f.kind] || f.kind) + ' · ' + esc(f.ext.toUpperCase()))}${cell(D.size, dSize(f.size) + (f.pages ? ' · ' + esc(D.pages(f.pages)) : ''))}</dl>
          ${links.length ? links.map(linkHtml).join('') : `<p class="banner" role="note">📎 ${D.unassignedNote}</p>`}</div></div>`;
    if (f.mime === 'application/pdf') {
        // PDF מוצג מתוך blob: התשובה של השרת נושאת sandbox, ודפדפן אינו פותח בה את מציג ה-PDF שלו
        fetch(dUrl(f.pid, true), { credentials: 'same-origin' }).then(r => r.ok ? r.blob() : Promise.reject()).then(b => {
            const box = $('#pdfbox');
            if (!box) return;
            if (DBLOB) URL.revokeObjectURL(DBLOB);
            DBLOB = URL.createObjectURL(new Blob([b], { type: 'application/pdf' }));
            box.outerHTML = `<iframe src="${DBLOB}" title="${esc(f.name)}"></iframe>`;
        }).catch(() => { const box = $('#pdfbox'); if (box) $('p', box).textContent = D.noPreview; });
    }
    const act = async fn => { try { await fn(); } catch (e) { e.code === 'already_reviewed' && e.review ? toast(D.alreadyReviewed(e.review.by || '', D.review[e.review.state] || ''), true) : toastError(e); reload(); } };
    $$('[data-link]').forEach(box => {
        const link = box.dataset.link, checks = $$('[data-chk]', box), val = k => $(`[data-f="${k}"]`, box).value;
        const common = () => ({ link, ...Object.fromEntries(checks.map(c => [c.dataset.chk, c.checked])), note: val('note'), doc_date: val('doc_date') || null });
        checks.forEach(c => c.addEventListener('change', () => { $('[data-do="approve"]', box).disabled = !checks.every(k => k.checked); }));
        $('[data-do="approve"]', box)?.addEventListener('click', () => act(async () => { await api('file-review', { body: { ...common(), do: 'approve' } }); toast(D.approved); reload(); }));
        $('[data-do="return"]', box)?.addEventListener('click', () => {
            const base = common();
            sheet(D.returnDo, `<label class="f"><span>${D.returnCode} *</span><select name="return_code">${x.return_codes.map(k => cOpt(k, D.returnCodes[k], '')).join('')}</select></label>
                <label class="f"><span>${D.returnExplain}</span><textarea name="return_reason" maxlength="600" rows="3"></textarea></label><p class="hint">${D.returnHint}</p>`,
                async v => { await api('file-review', { body: { ...base, do: 'return', return_code: v.return_code, return_reason: v.return_reason } }); toast(D.returned); reload(); }, D.returnDo);
        });
        $('[data-do="reject"]', box)?.addEventListener('click', () => {
            const base = common();
            reasonSheet(D.reject, D.rejectHint, async reason => { await api('file-review', { body: { ...base, do: 'reject', reason } }); toast(D.rejected); reload(); }, D.reject);
        });
    });
    $('#fedit')?.addEventListener('click', () => {
        sheet(D.editFile, `<label class="f"><span>${D.fileName}</span><input name="name" maxlength="150" value="${esc(f.name)}" dir="auto"></label>
            <label class="f"><span>${D.docDate}</span><input type="date" name="doc_date" value="${esc(f.doc_date || '')}"></label>
            <label class="f"><span>${D.sourceNote}</span><input name="source_note" maxlength="200" value="${esc(f.source_note || '')}"></label>
            <label class="f"><span>${D.tags}</span><input name="tags" value="${esc(f.tags.join(', '))}" placeholder="${D.tagsHint}"></label>`, async v => {
            await api('file-update', { body: { file: fpid, name: v.name, doc_date: v.doc_date || null, source_note: v.source_note, tags: v.tags.split(',').map(t => t.trim()).filter(Boolean) } });
            reload();
        });
    });
    $('#fassign')?.addEventListener('click', () => {
        const linked = new Set(links.filter(l => l.review_state !== 'rejected').map(l => l.req.pid));
        const open = x.requirements.filter(r => !r.split && r.status !== 'not_relevant' && !linked.has(r.pid));
        sheet(D.assign, open.length ? `<label class="f"><span>${D.assignTo}</span><select name="requirement">${open.map(r => cOpt(r.pid, r.title, '')).join('')}</select></label>
            <label class="f"><span>${D.relation}</span><select name="relation">${cOpt('version', D.asVersion, 'version')}${cOpt('attachment', D.asAttachment, '')}</select></label><p class="hint">${D.assignHint}</p>` : `<p class="empty">${D.noOpenReqs}</p>`,
            open.length ? async v => { await api('file-assign', { body: { file: fpid, requirement: v.requirement, relation: v.relation } }); toast(D.assigned); reload(); } : null, D.assign);
    });
    $('#fdel')?.addEventListener('click', () => reasonSheet(D.del, D.delHint, async reason => { await api('file-delete', { body: { file: fpid, reason } }); toast(D.deletedDone); location.hash = `#/cases/${pid}/reqs`; }, D.del));
}

/** ללוח הראשי: מסמכים שהתקבלו וממתינים לבדיקה שלי. */
function pendingDocsHtml(rows) {
    if (!rows.length) return '';
    return `<h2 class="sec-title">📥 ${D.pendingTitle} <small>${rows.length}</small></h2><ul class="list">${rows.slice(0, 6).map(p => `<li class="card" tabindex="0" role="button" data-go="#/cases/${p.case.pid}/reqs/${p.requirement}">
        <span class="use-ic" aria-hidden="true">📥</span><div class="body"><div class="l1"><span>${esc(p.title)}</span>${p.since ? `<span class="t">${esc(fmtDate(p.since))}</span>` : ''}</div>
        <div class="l2 wrap"><span dir="ltr">${esc(p.case.number)}</span> ${esc(p.case.title || '')}</div></div></li>`).join('')}</ul>`;
}

/** תוצאות חיפוש מסמכים ודרישות — לצד תוצאות התיקים. */
function docResultsHtml(rows) {
    if (!rows.length) return '';
    return `<h2 class="sec-title">📎 ${D.searchResults} <small>${rows.length}</small></h2><ul class="list">${rows.map(x => `<li class="card" tabindex="0" role="button" data-go="#/cases/${x.case.pid}/${x.type === 'file' ? 'file/' + x.file : 'reqs/' + x.requirement}">
        <span class="use-ic" aria-hidden="true">${x.type === 'file' ? dIcon(x) : '📄'}</span><div class="body"><div class="l1"><span dir="auto">${esc(x.title)}</span></div>
        <div class="l2 wrap"><span dir="ltr">${esc(x.case.number)}</span> ${esc(x.case.title || '')}</div>
        <div class="chips"><span class="chip">${x.type === 'file' ? D.file : D.requirement}</span>${x.type === 'requirement' ? dStatusChip(x) : ''}</div></div></li>`).join('')}</ul>`;
}
