/*
 * שיתוף (אפיון 05): רשימת השיתופים של תיק והחלון "שיתוף" (נמען ← היקף ← פעולות ← תוקף ← תצוגה מקדימה ← אישור), ביטול,
 * ההזמנות והתיקים "ששותפו איתי", כרטיס התיק כפי ששותף רואה אותו, ומסך התמיכה של מנהל המערכת.
 * המסך רק מציג: מה מותר ומה נראה — מכריע השרת (lib/shares.php). כל ערך שמגיע מהשרת נכנס ל-HTML דרך esc() — שם של אדם ושל
 * משרד כאן מגיעים **ממשרד אחר**.
 */
'use strict';

const SH = T.sh;
const shChip = s => `<span class="chip${s === 'active' ? ' info' : s === 'expired' || s === 'declined' ? ' bad' : ' warn'}">${esc(SH.status[s] || s)}</span>`;
const shUntil = iso => iso ? esc(SH.until(fmtDate(iso))) : esc(SH.forever);
const shItemText = i => i.pid === null ? esc(SH.typeAll[i.type] || i.type) : i.label === null ? esc(SH.deleted) : esc(i.type === 'visit' ? SH.visitN(i.label) : SH.types[i.type] + ': ' + i.label);
const shScopeText = s => s.scope === 'all' ? esc(SH.scopeAll) : s.items.map(shItemText).join(' · ');
const shPermsText = perms => perms.map(k => esc(SH.perms[k] || k)).join(' · ');
const shFileUrl = (fpid, inline) => `api.php?action=file&file=${encodeURIComponent(fpid)}${inline ? '&inline=1' : ''}`;

// ── בתוך התיק: "גורמים והרשאות" ← שיתופים ───────────────────────

function shRowHtml(s, can) {
    const who = s.kind === 'support' ? esc(SH.supportRow) : esc(s.recipient ? s.recipient.name : '');
    const sub = s.kind === 'support' ? esc(s.reason || '') : `<span dir="auto">${esc(s.recipient ? s.recipient.email : '')}</span>${s.office ? ' · ' + esc(s.left_office ? SH.leftOffice(s.office) : s.office) : ''}`;
    return `<li class="card${can ? '' : ' static'}"${can ? ` tabindex="0" role="button" data-share="${esc(s.pid)}"` : ''}><span class="use-ic" aria-hidden="true">${s.kind === 'support' ? '🛟' : '🤝'}</span>
        <div class="body"><div class="l1"><span>${who}</span></div><div class="l2 wrap">${sub}</div>
        <div class="l2 wrap">${shScopeText(s)} · ${shPermsText(s.perms)}</div>
        <div class="chips">${shChip(s.status)}<span class="chip">${shUntil(s.expires_at)}</span><span class="chip">${esc(SH.addedBy(s.added_by))}</span></div></div></li>`;
}

async function sharesSection(pid, reload) {
    const x = await api('case-shares', { params: { case: pid } });
    const support = x.shares.find(s => s.kind === 'support');
    const html = `<h2 class="sec-title">🤝 ${esc(SH.title)} <small>${x.shares.length || ''}</small></h2>
        ${x.shares.length ? `<ul class="list">${x.shares.map(s => shRowHtml(s, x.can_manage)).join('')}</ul>` : `<p class="empty">${esc(SH.none)}</p>`}
        ${x.can_manage ? `<div class="row-btns wrap"><button type="button" class="btn ghost" id="sh-add"><span aria-hidden="true">+</span> ${esc(SH.share)}</button>
            ${x.can_support ? `<button type="button" class="btn ghost" id="sh-support">🛟 ${esc(SH.support)}</button>` : ''}</div>` : ''}
        ${support || x.views.length ? `<h2 class="sec-title">🛟 ${esc(SH.viewsTitle)} <small>${x.views.length || ''}</small></h2>
            ${x.views.length ? `<ul class="list">${x.views.map(v => `<li class="card static"><span class="use-ic" aria-hidden="true">👁️</span><div class="body">
                <div class="l1"><span>${esc(SH.viewed[v.entity_type] || v.entity_type)}${v.label ? ': ' + esc(v.label) : ''}</span><span class="t">${esc(fmtDateTime(v.at))}</span></div>
                <div class="l2">${esc(v.by || '')}</div></div></li>`).join('')}</ul>` : `<p class="empty">${esc(SH.viewsNone)}</p>`}` : ''}`;
    const bind = () => {
        $('#sh-add')?.addEventListener('click', () => shareSheet(pid, x, 'cross_office', null, reload));
        $('#sh-support')?.addEventListener('click', () => shareSheet(pid, x, 'support', support || null, reload));
        cOn('[data-share]', el => {
            const s = x.shares.find(r => r.pid === el.dataset.share);
            const dlg = sheet(s.kind === 'support' ? SH.supportRow : s.recipient.name, `<div class="summary"><div class="line">${shScopeText(s)}</div><div class="line muted">${shPermsText(s.perms)} · ${shUntil(s.expires_at)}</div></div>
                <p class="banner" role="note">⚠️ ${esc(SH.revokeWarn)}</p>
                <div class="row-btns wrap"><button type="button" class="btn ghost" id="sh-edit">${esc(SH.edit)}</button><button type="button" class="btn danger" id="sh-revoke">${esc(SH.revokeDo)}</button></div>`, null);
            $('#sh-edit', dlg).addEventListener('click', () => shareSheet(pid, x, s.kind, s, reload));
            $('#sh-revoke', dlg).addEventListener('click', async () => {
                try { await api('share-revoke', { body: { share: s.pid } }); dlg.close(); reload(); } catch (e) { toastError(e); }
            });
        });
    };
    return { html, bind };
}

/** החלון "שיתוף". שלב א — הטופס; שלב ב — התצוגה המקדימה מהשרת; רק ממנה מאשרים, ורק את מה שהוצג. */
function shareSheet(pid, x, kind, cur, reload, keep) {
    const support = kind === 'support';
    const v = keep || { email: cur?.recipient?.email || '', scope: support ? 'items' : (cur?.scope || 'all'), items: cur ? cur.items.map(i => ({ type: i.type, pid: i.pid })) : [],
        perms: Object.fromEntries((cur?.perms || []).map(k => [k, true])), days: support ? 7 : 0, reason: '' };
    const has = (type, ipid) => v.items.some(i => i.type === type && i.pid === ipid);
    const grant = (support ? ['download'] : ['download', 'see_internal', 'see_draft']).filter(k => x.grantable.includes(k));
    const typeBlock = type => {
        const opts = x.options[type] || [];
        return `<div class="f"><label class="switch"><input type="checkbox" data-all="${type}"${has(type, null) ? ' checked' : ''}><span>${esc(SH.typeAll[type])}</span></label>
            ${opts.length ? `<details${opts.some(o => has(type, o.pid)) ? ' open' : ''}><summary class="hint">${esc(SH.types[type])} (${opts.length})</summary>${opts.map(o =>
                `<label class="switch"><input type="checkbox" data-item="${type}" value="${esc(o.pid)}"${has(type, o.pid) ? ' checked' : ''}><span>${esc(type === 'visit' ? SH.visitN(o.label) : o.label)}</span></label>`).join('')}</details>` : ''}</div>`;
    };
    const dlg = sheet(support ? SH.support : SH.share, `${support ? `<p class="hint">${esc(SH.supportHint)}</p>` : `<label class="f"><span>${esc(SH.email)}</span>
            <input name="email" type="email" dir="ltr" maxlength="200" autocomplete="off" value="${esc(v.email)}"${cur ? ' readonly' : ''} required></label>
          <div class="f"><span>${esc(SH.scope)}</span><fieldset class="seg">${['all', 'items'].map(k => `<label><input type="radio" name="scope" value="${k}"${v.scope === k ? ' checked' : ''}><span>${esc(k === 'all' ? SH.scopeAll : SH.scopeItems)}</span></label>`).join('')}</fieldset></div>`}
        <div id="sh-items"${v.scope === 'items' ? '' : ' hidden'}><p class="hint">${esc(SH.itemsPick)}</p>${['file', 'requirement', 'visit', 'source_check', ...(support ? [] : ['comps', 'report'])].map(typeBlock).join('')}</div>
        <div class="f"><span>${esc(SH.permsTitle)}</span><label class="switch"><input type="checkbox" checked disabled><span>${esc(SH.perms.view)}</span></label>
          ${grant.map(k => `<label class="switch"><input type="checkbox" data-perm="${k}"${v.perms[k] ? ' checked' : ''}><span>${esc(SH.perms[k])}</span></label>`).join('')}</div>
        <p class="hint">${esc(SH.permsHint)}</p>
        <label class="f"><span>${esc(SH.days)}</span><select name="days">${x.days[kind].map(n => `<option value="${n}"${n === v.days ? ' selected' : ''}>${esc(SH.dayOpts[n])}</option>`).join('')}</select></label>
        <label class="f"><span>${esc(support ? SH.reasonSupport : SH.reason)}</span><textarea name="reason" maxlength="500" rows="2"${support ? ' required' : ''}>${esc(v.reason)}</textarea></label>`, async f => {
        const body = { case: pid, kind, scope: support ? 'items' : f.scope, perms: Object.fromEntries($$('[data-perm]', dlg).map(i => [i.dataset.perm, i.checked])), days: Number(f.days), reason: f.reason || '' };
        if (!support) body.email = f.email.trim();
        body.items = body.scope === 'items' ? [...$$('[data-all]:checked', dlg).map(i => ({ type: i.dataset.all, pid: null })), ...$$('[data-item]:checked', dlg).map(i => ({ type: i.dataset.item, pid: i.value }))] : [];
        const pv = await api('share-preview', { body });
        sharePreviewSheet(pid, x, kind, cur, reload, body, pv);
        return false;       // החלון נשאר פתוח — עכשיו עם התצוגה המקדימה
    }, SH.toPreview);
    $$('[name=scope]', dlg).forEach(r => r.addEventListener('change', () => { $('#sh-items', dlg).hidden = r.value !== 'items' || !r.checked; }));
}

const shHasDraft = p => Array.isArray(p) ? p.includes('see_draft') : !!(p && p.see_draft);
function sharePreviewSheet(pid, x, kind, cur, reload, body, pv) {
    const s = pv.summary, r = s.recipient;
    const line = (k, html) => `<div class="line"><span class="muted">${esc(k)}</span><span>${html}</span></div>`;
    const dlg = sheet(SH.previewTitle, `<div class="summary">
          ${line(SH.pvTo, r ? `<b>${esc(r.name)}</b> · <span dir="auto">${esc(r.email)}</span>` : esc(SH.supportRow))}
          ${r ? line(SH.pvOffice, r.office ? esc(r.office) : esc(SH.pvNoOffice)) : ''}
          ${line(SH.pvSees, shScopeText(s))}
          ${line(SH.pvCan, shPermsText(s.perms))}
          ${line(SH.pvUntil, s.expires_at ? esc(fmtDate(s.expires_at)) : esc(SH.pvForever))}
          ${s.reason ? line(SH.reason, esc(s.reason)) : ''}</div>
        <p class="sec-title">🚫 ${esc(SH.pvHidden)}</p>
        <ul class="list">${s.hidden.map(k => `<li class="card static"><span class="use-ic" aria-hidden="true">▫️</span><div class="body"><div class="l1"><span>${esc(SH.hidden[k] || k)}</span></div></div></li>`).join('')}</ul>
        ${shHasDraft(s.perms) ? `<p class="banner" role="note">ℹ️ ${esc(SH.pvDraftNote)}</p>` : ''}${s.future_items ? `<p class="banner" role="note">ℹ️ ${esc(SH.pvFuture)}</p>` : ''}${s.update ? `<p class="banner" role="note">ℹ️ ${esc(SH.pvUpdate)}</p>` : ''}
        <p class="hint">${esc(s.needs_accept ? SH.pvAccept : SH.pvSupport)}</p>
        <div class="row-btns"><button type="button" class="btn ghost" id="sh-back">${esc(SH.back)}</button></div>`, async () => {
        await api('share-create', { body: { ...body, preview: pv.preview } });
        reload();
    }, s.update ? SH.confirmUpdate : SH.confirm);
    $('#sh-back', dlg).addEventListener('click', () => shareSheet(pid, x, kind, cur, reload,
        { email: body.email || '', scope: body.scope, items: body.items, perms: body.perms, days: body.days, reason: body.reason }));
}

// ── הצד של השותף: הזמנות, ו"תיקים ששותפו איתי" ──────────────────

function shSharedRow(s) {
    return `<li class="card" tabindex="0" role="button" data-go="#/cases/${esc(s.case.pid)}"><span class="use-ic" aria-hidden="true">🤝</span>
        <div class="body"><div class="l1"><span>${esc(s.case.title || C.noTitle)}</span><span class="t" dir="ltr">${esc(s.case.number)}</span></div>
        <div class="l2 wrap">${esc(SH.fromOffice(s.office))}</div>
        <div class="chips">${cStatusChip(s.case.status)}<span class="chip">${esc(s.scope === 'all' ? SH.scopeAll : SH.scopeItems)}</span><span class="chip">${shUntil(s.expires_at)}</span></div></div></li>`;
}

/** ההזמנות שממתינות לי והתיקים ששותפו איתי — ללוח הראשי, ל"ברוכים הבאים" (מי שאין לו משרד) ולרשימת התיקים. */
function sharedHomeHtml(ms, withShared) {
    const inv = ms.invites.length ? `<h2 class="sec-title">✉️ ${esc(SH.invitesTitle)} <small>${ms.invites.length}</small></h2><ul class="list">${ms.invites.map(i => `<li class="card static"><span class="use-ic" aria-hidden="true">🤝</span>
        <div class="body"><div class="l1"><span>${esc(SH.inviteLine(i.number, i.office))}</span></div>
        <div class="l2 wrap">${esc(i.scope === 'all' ? SH.scopeAll : SH.scopeItems)} · ${shPermsText(i.perms)} · ${shUntil(i.expires_at)}</div>
        <div class="row-btns"><button type="button" class="btn primary sm" data-share-accept="${esc(i.share)}">${esc(SH.accept)}</button>
          <button type="button" class="btn ghost sm" data-share-decline="${esc(i.share)}">${esc(SH.decline)}</button></div></div></li>`).join('')}</ul>` : '';
    const shared = withShared && ms.shared.length ? `<h2 class="sec-title">🤝 ${esc(SH.sharedTitle)} <small>${ms.shared.length}</small></h2><ul class="list">${ms.shared.map(shSharedRow).join('')}</ul>` : '';
    return inv + shared;
}

function sharedHomeBind(reload) {
    const respond = (el, accept) => el.addEventListener('click', async () => {
        if (!accept && !confirm(SH.declineAsk)) return;
        el.disabled = true;
        try {
            const r = await api('share-respond', { body: { share: accept ? el.dataset.shareAccept : el.dataset.shareDecline, accept } });
            if (accept && r.case) location.hash = '#/cases/' + r.case; else reload();
        } catch (x) { toastError(x); el.disabled = false; }
    });
    $$('[data-share-accept]').forEach(el => respond(el, true));
    $$('[data-share-decline]').forEach(el => respond(el, false));
}

/** תוצאות החיפוש בקבצים ששותפו: כל תוצאה מובילה ללשונית הקבצים של התיק המשותף (מסך הקובץ ומסך הדרישה הם של המשרד הבעלים). */
function shResultsHtml(rows) {
    if (!rows.length) return '';
    return `<h2 class="sec-title">📎 ${esc(D.searchResults)} <small>${rows.length}</small></h2><ul class="list">${rows.map(x => `<li class="card" tabindex="0" role="button" data-go="#/cases/${esc(x.case.pid)}/files">
        <span class="use-ic" aria-hidden="true">${x.type === 'requirement' ? '📄' : '📎'}</span><div class="body"><div class="l1"><span dir="auto">${esc(x.title)}</span></div>
        <div class="l2 wrap"><span dir="ltr">${esc(x.case.number)}</span> ${esc(x.case.title || '')}</div>${x.type === 'text' ? `<div class="l2 wrap" dir="auto">${esc(x.snippet || '')}</div>` : ''}</div></li>`).join('')}</ul>`;
}

/** #/cases/shared — "תיקים ששותפו איתי": לשונית נפרדת, עם חיפוש בקבצים ששותפו. */
async function viewCasesShared() {
    setHead(SH.sharedTitle, null);
    const q = sessionStorage.getItem('apr-shq') || '';
    const [d, ds] = await Promise.all([api('cases', { params: { tab: 'shared' } }), q.length > 1 ? api('docs-search', { params: { q, shared: '1' } }) : { results: [] }]);
    const mine = myOffice()?.status === 'active';
    App.main.innerHTML = `${mine ? tabsHtml([['#/cases', SH.mineTab], ['#/cases/shared', SH.sharedTab, d.shared.length || '']], '#/cases/shared') : ''}
        <form class="toolbar" id="shsearch"><input type="search" name="q" value="${esc(q)}" placeholder="${esc(SH.searchShared)}" aria-label="${esc(SH.searchShared)}" style="flex:1"></form>
        ${d.shared.length ? `<ul class="list">${d.shared.map(shSharedRow).join('')}</ul>` : `<p class="empty">${esc(SH.sharedNone)}</p>`}${shResultsHtml(ds.results)}`;
    $('#shsearch').addEventListener('submit', e => { e.preventDefault(); sessionStorage.setItem('apr-shq', e.target.q.value.trim()); viewCasesShared(); });
}

// ── כרטיס התיק כפי ששותף רואה אותו ──────────────────────────────

function shFileRow(f, canDownload) {
    return `<li class="card static"><span class="use-ic" aria-hidden="true">${f.kind === 'image' ? '🖼️' : '📎'}</span><div class="body"><div class="l1"><span dir="auto">${esc(f.name)}</span></div>
        <div class="l2">${esc(fmtDate(f.received_at))}</div>
        <div class="row-btns">${f.preview ? `<a class="btn ghost sm" target="_blank" rel="noopener noreferrer" href="${shFileUrl(f.pid, true)}">${esc(SH.open)}</a>` : ''}
          ${canDownload ? `<a class="btn ghost sm" href="${shFileUrl(f.pid, false)}">${esc(SH.download)}</a>` : ''}</div></div></li>`;
}

async function shFilesTab(pid, areas) {
    const x = await api('case-docs', { params: { case: pid } });
    const reqs = x.requirements.filter(r => !r.split);
    const html = `${reqs.map(r => `<h2 class="sec-title">📄 ${esc(r.title)} <small>${esc(C.reqStatuses?.[r.status] || '')}</small></h2>
            ${r.explain_internal ? `<p class="hint">${esc(r.explain_internal)}</p>` : ''}
            ${r.files.length ? `<ul class="list">${r.files.filter(l => !l.file.deleted).map(l => shFileRow(l.file, areas.download)).join('')}</ul>` : `<p class="empty">${esc(SH.nothing)}</p>`}`).join('')}
        ${x.unassigned.length ? `<h2 class="sec-title">📎 ${esc(SH.looseFiles)} <small>${x.unassigned.length}</small></h2><ul class="list">${x.unassigned.map(f => shFileRow(f, areas.download)).join('')}</ul>` : ''}
        ${!reqs.length && !x.unassigned.length ? `<p class="empty">${esc(SH.nothing)}</p>` : ''}`;
    return { html, bind() {} };
}

/** בדיקות המקור שבהיקף של השותף — בלי התכנון ובלי פעולות. כל בדיקה נושאת את נוסח ההסתייגות שלה (09-A2). */
async function shSourcesTab(pid) {
    const x = await api('case-sources', { params: { case: pid } });
    const html = x.sources.length ? `<ul class="list">${x.sources.map(s => {
        const k = s.latest;
        return `<li class="card" tabindex="0" role="button" data-go="#/cases/${esc(pid)}/check/${esc(k.pid)}"><span class="use-ic" aria-hidden="true">🔎</span><div class="body">
            <div class="l1"><span>${esc(s.name)}</span><span class="t">${esc(fmtDate(k.performed_at))}</span></div><div class="l2 wrap">${esc(k.result_summary || '')}</div>
            ${k.disclaimer ? `<div class="l2 wrap">⚠️ ${esc(k.disclaimer)}</div>` : ''}<div class="chips"><span class="chip">${esc(T.s.statuses?.[k.status] || k.status)}</span></div></div></li>`;
    }).join('')}</ul>` : `<p class="empty">${esc(SH.nothing)}</p>`;
    return { html, bind() {} };
}

async function viewCaseShared(pid, tab, d) {
    const c = d.case, a = d.shared.areas, support = d.shared.kind === 'support';
    const tabs = ['overview', ...(a.requirement || a.file ? ['files'] : []), ...(a.visit ? ['visits'] : []), ...(a.source_check ? ['sources'] : []), ...(a.comps ? ['comps'] : []), ...(a.facts ? ['facts'] : []), ...(d.report ? ['report'] : [])];
    const cur = tabs.includes(tab) ? tab : tab === 'reqs' && tabs.includes('files') ? 'files' : 'overview';
    const href = t => `#/cases/${pid}${t === 'overview' ? '' : '/' + t}`;
    const reload = () => viewCaseShared(pid, cur, d);
    setHead(c.number, null);
    const head = `<p class="banner" role="note">${support ? '🛟' : '🤝'} ${esc(support ? SH.bannerSupport(d.shared.office) : SH.banner(d.shared.office, d.shared.scope === 'all' ? SH.scopeAll : SH.scopeItems))} · ${shUntil(d.shared.expires_at)}</p>
        <div class="summary"><div class="line"><b class="one">${esc(c.title || C.noTitle)}</b><span class="chip" dir="ltr">${esc(c.number)}</span></div>
        <div class="chips">${cStatusChip(c.status)}</div></div>${tabsHtml(tabs.map(t => [href(t), SH.tabs[t]]), href(cur))}`;
    let part = { html: '', bind() {} };
    if (cur === 'overview') {
        part.html = `${a.details ? `<div class="summary"><div class="line muted">${cPurpose(c)} · ${C.propertyType}: ${c.property_type ? esc(P.propertyTypes[c.property_type]) : C.unknown}</div>
              <div class="line muted">${C.keyDate}: ${c.key_date ? esc(fmtDate(c.key_date)) : C.unknown}${c.due_date ? ' · ' + esc(C.due(fmtDate(c.due_date))) : ''}</div></div>
            <h2 class="sec-title">📍 ${esc(SH.identifiers)}</h2>${d.identifiers.length ? `<ul class="list">${d.identifiers.map(i => `<li class="card static"><div class="body"><div class="l1"><span>${esc(identText(i))}</span></div></div></li>`).join('')}</ul>` : `<p class="empty">${esc(SH.nothing)}</p>`}
            <h2 class="sec-title">👤 ${esc(SH.parties)}</h2>${d.parties.length ? `<ul class="list">${d.parties.map(p => `<li class="card static"><div class="body"><div class="l1"><span>${esc(p.name)}</span></div>
                <div class="l2">${esc(C.partyKinds[p.kind] || p.kind)}${p.org ? ' · ' + esc(p.org) : ''}</div></div></li>`).join('')}</ul>` : `<p class="empty">${esc(SH.nothing)}</p>`}` : ''}
            <ul class="list">${tabs.filter(t => t !== 'overview').map(t => `<li class="card" tabindex="0" role="button" data-go="${href(t)}"><span class="use-ic" aria-hidden="true">📂</span>
                <div class="body"><div class="l1"><span>${esc(SH.tabs[t])}</span></div></div></li>`).join('')}</ul>`;
    } else if (cur === 'files') part = await shFilesTab(pid, a);
    else if (cur === 'visits') part = await visitsTab(pid, reload);
    else if (cur === 'sources') part = await shSourcesTab(pid);
    else if (cur === 'comps') part = await compsTab(pid, reload);
    else if (cur === 'facts') part = await factsTab(pid);
    else if (cur === 'report') part = await reportTab(pid, reload);
    App.main.innerHTML = head + part.html;
    part.bind();
}

// ── מנהל המערכת: #/system/support ───────────────────────────────

async function viewSysSupport(tabs) {
    const d = await api('sys-support');
    App.main.innerHTML = `${tabs}<p class="hint">${esc(SH.sysHint)}</p>${d.shares.length ? `<ul class="list">${d.shares.map(s => `<li class="card" tabindex="0" role="button" data-go="#/cases/${esc(s.case.pid)}">
        <span class="use-ic" aria-hidden="true">🛟</span><div class="body"><div class="l1"><span>${esc(s.case.title || C.noTitle)}</span><span class="t" dir="ltr">${esc(s.case.number)}</span></div>
        <div class="l2 wrap">${esc(SH.fromOffice(s.office))} · ${esc(SH.itemsN(s.items))} · ${shPermsText(s.perms)}</div>
        <div class="l2 wrap">${esc(s.reason || '')}</div><div class="chips"><span class="chip">${shUntil(s.expires_at)}</span></div></div></li>`).join('')}</ul>` : `<p class="empty">${esc(SH.sysNone)}</p>`}`;
}
