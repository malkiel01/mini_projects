/*
 * פרוסה 09 — מקורות מידע, בדיקות, תכנון והיתרים: המסלול הידני (docs/spec/09-sources.md §9).
 *   כרטיס תיק ← "מקורות ותכנון" (sourcesTab) ← מקור (viewCaseSource) · ← בדיקה (viewCheck) · ← פריט תכנון (viewPlanningItem)
 *   עורך פרופיל ← "מקורות" (peSources) · #/sources — הקטלוג לקריאה · #/system/sources — ניהול הקטלוג
 * המסך אינו פונה לשום אתר חיצוני: "פתיחת המקור" הוא קישור רגיל שנפתח בלשונית חדשה (rel="noopener noreferrer"), ואין כאן
 * שום שדה לסיסמה, לקוד או לפרטי תשלום. "לא אותר" ו"מקור לא זמין" מוצגים תמיד עם נוסח ההסתייגות שהשרת שולח (sCheckNote).
 * כל ערך של משתמש עובר דרך esc(); כתובת מוצגת כקישור רק אם היא http / https (sLink). ההרשאות נאכפות בשרת.
 */
'use strict';

const S = T.s;
/** קישור חיצוני בטוח: רק http(s), תמיד בלשונית חדשה ובלי opener / referrer. כל דבר אחר — טקסט בלבד. */
const sLink = (url, label, cls = '') => /^https?:\/\//i.test(String(url || ''))
    ? `<a${cls ? ` class="${cls}"` : ''} href="${esc(url)}" target="_blank" rel="noopener noreferrer">${label ?? esc(url)}</a>` : esc(url || '');
const sStatus = st => `<span class="chip ${S.statusCls[st] || ''}">${esc(S.statuses[st] || st)}</span>`;
/** נוסח ההסתייגות של בדיקה — מוצג תמיד כשהשרת שלח אותו, בלי אפשרות הסתרה. */
const sCheckNote = k => k && k.disclaimer ? `<p class="banner" role="note">⚠️ ${esc(k.disclaimer)}</p>` : '';
const sMoney = a => (a / 100).toLocaleString('he-IL', { minimumFractionDigits: a % 100 ? 2 : 0 });
const sFile = (f, pid) => !f ? '' : f.restricted ? `📄 ${esc(S.fileRestricted)}` : f.deleted ? `📄 ${esc(f.name)} <span class="chip bad">${esc(S.fileGone)}</span>` : `<a href="#/cases/${pid}/file/${f.pid}">📄 ${esc(f.name)}</a>`;
const sKindChip = k => `<span class="chip ${S.kindCls[k] || ''}">${esc(S.kinds[k] || k)}</span>`;

function sSourceChips(s) {
    return `${s.connected ? `<span class="chip ok">${esc(S.connected)}</span>` : `<span class="chip">${esc(S.access[s.access_mode] || s.access_mode)}</span>`}${
        s.paid ? `<span class="chip warn">${esc(S.auth.payment)}</span>` : s.auth_required !== 'none' ? `<span class="chip">${esc(S.auth[s.auth_required])}</span>` : ''}${
        s.card_stale ? `<span class="chip warn">🟡 ${esc(S.cardStale)}</span>` : ''}${s.status !== 'active' ? `<span class="chip warn">${esc(S.hidden)}</span>` : ''}`;
}

/** בדיקה אחת, כבלוק: מצב, מי ומתי, מה נבדק, התוצאה, אסמכתא, הוצאה — ונוסח ההסתייגות כשיש. */
function sCheckHtml(k, pid, link = true) {
    const q = [...k.query.identifiers.map(i => i.label), ...(k.query.text ? [k.query.text] : [])];
    return `<li class="card${link ? '' : ' static'}"${link ? ` tabindex="0" role="button" data-go="#/cases/${pid}/check/${k.pid}"` : ''}><div class="body">
        <div class="l1"><span>${sStatus(k.status)}</span><span class="t">${esc(S.by(k.performed_by, fmtDateTime(k.performed_at)))}</span></div>
        ${k.disclaimer ? `<div class="l2 wrap"><b>⚠️ ${esc(k.disclaimer)}</b></div>` : ''}
        ${q.length ? `<div class="l2 wrap">${esc(S.queried)}: ${q.map(esc).join(' · ')}</div>` : ''}
        ${k.coverage_note ? `<div class="l2 wrap">${esc(S.coverage)}: ${esc(k.coverage_note)}</div>` : ''}
        ${k.result_summary ? `<div class="l2 wrap" dir="auto">${esc(k.result_summary)}</div>` : ''}
        ${k.limitation ? `<div class="l2 wrap">${esc(S.limitations)}: ${esc(k.limitation)}</div>` : ''}
        ${k.link_url || k.file ? `<div class="l2 wrap">${k.link_url ? '🔗 ' + sLink(k.link_url) : ''} ${sFile(k.file, pid)}</div>` : ''}
        <div class="chips">${k.no_evidence ? `<span class="chip warn">${esc(S.noEvidence)}</span>` : ''}${k.ids_changed ? `<span class="chip warn">${esc(S.idsChanged)}</span>` : ''}${
            k.cost_amount === null ? '' : `<span class="chip">${esc(k.cost_amount === 0 ? S.costNone : S.costOf(sMoney(k.cost_amount)))}${k.cost_note ? ' — ' + esc(k.cost_note) : ''}</span>`}${
            k.approved_at ? `<span class="chip info">${esc(S.approved(k.approved_by, fmtDate(k.approved_at)))}</span>` : ''}${
            k.verified_at ? `<span class="chip ok">${esc(S.verified(k.verified_by, fmtDate(k.verified_at)))}</span>` : ''}</div></div></li>`;
}

function sSummaryHtml(sum) {
    return `<ul class="list">${sum.kinds.map(k => `<li class="card static"><div class="body"><div class="l1"><span>${esc(k.text)}</span></div>${k.caveat ? `<div class="l2 wrap">${esc(k.caveat)}</div>` : ''}</div></li>`).join('')}</ul>`;
}

// ── לשונית "מקורות ותכנון" בכרטיס התיק ──
async function sourcesTab(pid, reload) {
    const [x, pl] = await Promise.all([api('case-sources', { params: { case: pid } }), api('case-planning', { params: { case: pid } })]);
    const srcRow = s => `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/sources/${esc(s.key)}"><div class="body">
        <div class="l1"><span>${esc(s.name)}</span><span class="t">${sStatus(s.check_status)}</span></div>
        ${s.latest && s.latest.disclaimer ? `<div class="l2 wrap"><b>⚠️ ${esc(s.latest.disclaimer)}</b></div>` : ''}
        ${s.latest ? `<div class="l2 wrap">${esc(S.by(s.latest.performed_by, fmtDateTime(s.latest.performed_at)))}${s.latest.result_summary ? ' · ' + esc(s.latest.result_summary) : ''}</div>` : ''}
        ${s.verified ? `<div class="l2 wrap">✅ ${esc(S.lastVerified)}: ${esc(S.verified(s.verified.verified_by, fmtDate(s.verified.verified_at)))}</div>` : ''}
        ${s.coverage_note ? `<div class="l2 wrap">${esc(S.coverage)}: ${esc(s.coverage_note)}</div>` : ''}
        <div class="chips">${sSourceChips(s)}${s.mode !== 'manual' ? `<span class="chip">${esc(S.modeShort[s.mode])}</span>` : ''}${s.checks > 1 ? `<span class="chip">${esc(S.checksN(s.checks))}</span>` : ''}</div></div></li>`;
    const groups = S.kinds ? pl.kinds.map(k => { const list = pl.items.filter(i => i.kind === k); return list.length ? `<h3 class="sec-title">${sKindChip(k)} <small>${list.length}</small></h3><ul class="list">${list.map(i => sItemRow(i, pid)).join('')}</ul>` : ''; }).join('') : '';
    const html = `<h2 class="sec-title">🔎 ${S.sourcesTitle}</h2>
        ${x.locality_hint ? `<p class="hint">${esc(x.locality_hint)}</p>` : ''}${x.identified ? '' : `<p class="banner" role="note">${esc(S.noIdent)}</p>`}
        <ul class="list">${x.sources.map(srcRow).join('')}</ul>
        <p class="hint"><a href="#/sources">${S.catalog}</a></p>
        <h2 class="sec-title" id="planning">🏗️ ${S.planningTitle} <small>${pl.summary.total || ''}</small></h2>
        ${pl.summary.disclaimer ? `<p class="banner" role="note">${esc(pl.summary.disclaimer)}</p>` : groups}
        ${pl.perms.write ? `<div class="row-btns"><button type="button" class="btn ghost" id="pl-add"><span aria-hidden="true">+</span> ${S.addItem}</button></div>` : ''}
        <details${pl.summary.total ? '' : ' open'}><summary class="hint">${S.planningTitle}: ${esc(pl.summary.kinds.map(k => k.text).join(' · '))}</summary>${sSummaryHtml(pl.summary)}</details>`;
    return { html, bind: () => { cOn('[data-go]', el => { location.hash = el.dataset.go; }); $('#pl-add')?.addEventListener('click', () => planningSheet(pid, null, pl, x.sources, reload)); } };
}

function sItemRow(i, pid) {
    return `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/planning/${i.pid}"><div class="body">
        <div class="l1"><span dir="auto">${esc(i.title)}</span><span class="t" dir="ltr">${esc(i.ref_number || '')}</span></div>
        ${i.status_reported ? `<div class="l2 wrap">${esc(S.statusReported)}: <q dir="auto">${esc(i.status_reported)}</q></div>` : ''}
        <div class="chips">${sKindChip(i.kind)}${i.item_date ? `<span class="chip">${esc(fmtDate(i.item_date))}</span>` : ''}${i.source_name ? `<span class="chip">${esc(i.source_name)}</span>` : ''}
            <span class="chip ${i.applicability === 'unknown' ? '' : 'info'}">${esc(S.applicability)}: ${esc(S.applicabilities[i.applicability])}</span></div></div></li>`;
}

// ── מקור אחד בתיק: הכרטיס המלא, המזהים להעתקה, וכל הבדיקות ──
function sCardHtml(s) {
    const row = (label, val) => val ? `<div class="line"><span class="muted">${esc(label)}</span><span dir="auto">${val}</span></div>` : '';
    return `<div class="summary"><div class="line"><b>${esc(s.name)}</b></div>
        ${row(S.owner, esc(s.owner))}${row(S.infoLabel, s.info_kinds.map(k => esc(S.infoKinds[k] || k)).join(' · '))}${row(S.accessMode, esc(S.access[s.access_mode]) + ' · ' + esc(S.feasibility[s.feasibility]))}
        ${row(S.authLabel, esc(S.auth[s.auth_required]))}${row(S.cost, esc(s.cost_note || ''))}${row(S.terms, s.terms_url ? sLink(s.terms_url) : '')}${row(S.attribution, esc(s.attribution || ''))}
        ${row(S.incident, esc(s.last_incident || ''))}${row(S.research, esc(s.research_ref || ''))}
        <div class="chips">${sSourceChips(s)}${s.last_verified_at ? `<span class="chip">${esc(S.cardChecked(fmtDate(s.last_verified_at)))}</span>` : ''}${s.connected ? '' : `<span class="chip">${esc(S.notConnected)}</span>`}</div></div>
        ${s.coverage_note ? `<p class="banner" role="note">📌 ${esc(S.coverage)}: ${esc(s.coverage_note)}</p>` : ''}
        ${s.limitations ? `<p class="hint">${esc(S.limitations)}: ${esc(s.limitations)}</p>` : ''}
        ${s.how_to ? `<h2 class="sec-title">${S.howTo}</h2><p class="panel" dir="auto">${esc(s.how_to)}</p>` : ''}
        <details><summary class="hint">${S.licenseTitle}</summary><div class="chips">${Object.keys(S.license).map(k => `<span class="chip ${s.license[k] === 'no' ? 'bad' : s.license[k] === 'yes' ? 'ok' : ''}">${esc(S.license[k])}: ${esc(S.licenseValues[s.license[k]])}</span>`).join('')}</div></details>`;
}

async function viewCaseSource(pid, key) {
    const x = await api('case-source', { params: { case: pid, source: key } });
    const s = x.source, can = x.perms.write && s.status === 'active';
    setHead(s.name, null);
    App.main.innerHTML = `<p class="hint"><a href="#/cases/${pid}/sources">‹ ${S.tab}</a> · ${esc(x.case.number)}</p>${sCardHtml(s)}
        <h2 class="sec-title">${S.idsTitle}</h2>
        ${x.identifiers.length ? `<ul class="list">${x.identifiers.map(i => `<li class="card static"><div class="body"><div class="l1"><span dir="auto">${esc(i.label)}</span></div></div>
            <button type="button" class="btn ghost sm" data-copy="${esc(i.label)}">${S.copy}</button></li>`).join('')}</ul>` : ''}
        ${x.identified ? '' : `<p class="banner" role="note">${esc(S.noIdent)}</p>`}
        <div class="row-btns">${s.url ? sLink(s.url, '🌐 ' + esc(S.open), 'btn primary') : ''}${can ? `<button type="button" class="btn ghost" id="s-start">${S.start}</button><button type="button" class="btn ghost" id="s-record">✍️ ${S.record}</button>` : ''}</div>
        <p class="hint">${s.url ? S.openHint : S.noUrl}${s.store_note ? ' ' + esc(s.store_note) : ''}${x.spend_needs_approval && s.paid ? ' ' + S.spendApproval : ''}</p>
        <h2 class="sec-title">${S.history} <small>${x.checks.length || ''}</small></h2>
        ${x.checks.length ? `<ul class="list">${x.checks.map(k => sCheckHtml(k, pid)).join('')}</ul>` : `<p class="empty">${S.noChecks}</p>`}`;
    const reload = () => viewCaseSource(pid, key);
    cOn('[data-go]', el => { location.hash = el.dataset.go; });
    $$('[data-copy]').forEach(b => b.addEventListener('click', async () => { await copyText(b.dataset.copy); toast(S.copied); }));
    $('#s-start')?.addEventListener('click', async () => {
        try { const r = await api('source-check-plan', { body: { case: pid, source: key } }); toast(S.statuses[r.check.status]); reload(); } catch (e) { toastError(e); }
    });
    $('#s-record')?.addEventListener('click', () => checkSheet(pid, x, reload));
}

/** "רישום התוצאה". מזהה פעולה (op) נוצר פעם אחת לגיליון — שליחה כפולה היא בדיקה אחת. */
async function checkSheet(pid, x, reload) {
    const s = x.source, op = 'chk-' + Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
    let files = [], reqs = [];
    if (s.license.store !== 'no') {
        const docs = await api('case-docs', { params: { case: pid } }).catch(() => ({ requirements: [], unassigned: [] }));
        reqs = docs.requirements.filter(r => !r.split);
        files = [...docs.requirements.flatMap(r => r.files.map(l => l.file)), ...docs.unassigned].filter((f, i, all) => !f.deleted && all.findIndex(o => o.pid === f.pid) === i);
    }
    sheet(`${S.record}: ${s.name}`, `<label class="f"><span>${S.result} *</span><select name="status" required><option value="">${S.pickResult}</option>${x.results.map(r => cOpt(r, S.statuses[r], '')).join('')}</select></label>
        <div class="f"><span>${S.idsChecked}</span><div class="chips">${x.identifiers.map(i => `<label class="chip pick"><input type="checkbox" name="ident" value="${esc(i.pid)}" checked> ${esc(i.label)}</label>`).join('')}</div></div>
        <label class="f"><span>${S.queryText}</span><input name="query_text" maxlength="300" autocomplete="off"></label>
        <label class="f"><span>${S.coverageNote}</span><textarea name="coverage_note" maxlength="1000" rows="2"></textarea></label><p class="hint">${S.coverageHint}</p>
        <label class="f"><span>${S.summary}</span><textarea name="result_summary" maxlength="2000" rows="3"></textarea></label>
        <label class="f"><span>${S.link}</span><input name="link_url" type="url" inputmode="url" dir="ltr" maxlength="500" autocomplete="off"></label><p class="hint">${S.linkHint}</p>
        ${s.license.store === 'no' ? `<p class="banner" role="note">${esc(s.store_note)}</p>` : `<label class="f"><span>${S.file}</span><select name="file"><option value="">${S.noFile}</option>${files.map(f => cOpt(f.pid, f.name, '')).join('')}</select></label>
            <label class="f"><span>${S.upload}</span><input type="file" name="upload"></label>
            <label class="f"><span>${S.toRequirement}</span><select name="requirement"><option value="">${S.noRequirement}</option>${reqs.map(r => cOpt(r.pid, r.title, '')).join('')}</select></label>
            <p class="hint">${s.store_note ? esc(s.store_note) : ''}</p>`}
        <label class="f"><span>${S.limitation}</span><input name="limitation" maxlength="1000" autocomplete="off"></label>
        <div class="fields two"><label class="f"><span>${S.costAmount}</span><input name="cost" inputmode="decimal" dir="ltr" maxlength="10" autocomplete="off"></label>
            <label class="f"><span>${S.costNote}</span><input name="cost_note" maxlength="300" autocomplete="off"></label></div><p class="hint">${S.costHint}</p>`, async (v, dlg) => {
        const cost = sCostIn(v.cost);
        // מסמך חדש עובר קודם את צינור הקליטה של התיק (06) — ורק אז מצורף לבדיקה. אותו מזהה פעולה: ניסיון חוזר אינו מעלה פעמיים
        const up = $('[name="upload"]', dlg)?.files?.[0];
        if (up) { const u = await apiUpload({ action: 'source-file-upload', case: pid, source: s.key, name: up.name, key: op, ...(v.requirement ? { requirement: v.requirement } : {}) }, up); v.file = u.file || u.duplicate?.file; }   // אותו קובץ כבר בתיק — מצרפים את הקיים
        const r = await api('source-check-record', { body: { case: pid, source: s.key, status: v.status, identifiers: $$('[name="ident"]:checked', dlg).map(i => i.value), query_text: v.query_text,
            coverage_note: v.coverage_note, result_summary: v.result_summary, link_url: v.link_url, file: v.file || null, limitation: v.limitation, cost_amount: cost, cost_note: v.cost_note, op } });
        toast(r.check.disclaimer || T.saved);
        reload();
    });
}

/** ₪ מהטופס ← אגורות (שלם), בלי נקודה צפה. ריק ← null ("לא נרשם"). ערך לא תקין נשלח כמחרוזת — והשרת דוחה. */
function sCostIn(raw) {
    const t = String(raw ?? '').trim().replace(/,/g, '');
    if (t === '') return null;
    const m = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(t);
    return m ? Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0')) : t;
}

// ── בדיקה אחת ──
async function viewCheck(pid, checkPid) {
    const x = await api('source-check', { params: { check: checkPid } });
    const k = x.check, reload = () => viewCheck(pid, checkPid);
    setHead(S.checkTitle, null);
    const result = ['found', 'partial', 'not_found', 'unavailable', 'needs_login', 'needs_clarification', 'failed', 'verified'].includes(k.status);
    App.main.innerHTML = `<p class="hint"><a href="#/cases/${pid}/sources/${esc(k.source)}">‹ ${esc(k.source_name)}</a> · ${esc(x.case.number)}</p>
        ${sCheckNote(k)}<ul class="list">${sCheckHtml(k, pid, false)}</ul>
        ${x.source.coverage_note ? `<p class="hint">📌 ${esc(S.coverage)}: ${esc(x.source.coverage_note)}</p>` : ''}
        <div class="row-btns">${x.perms.judge && k.status === 'needs_approval' ? `<button type="button" class="btn primary" id="k-approve">${S.approve}</button>` : ''}
            ${x.perms.judge && k.status === 'found' ? `<button type="button" class="btn primary" id="k-verify">✅ ${S.verify}</button>` : ''}
            ${x.perms.write && result && k.cost_amount === null ? `<button type="button" class="btn ghost" id="k-cost">${S.addCost}</button>` : ''}
            ${x.perms.write && ['found', 'partial', 'verified', 'needs_clarification'].includes(k.status) ? `<a class="btn ghost" href="#/cases/${pid}/facts">+ ${S.addFact}</a>` : ''}</div>
        ${x.perms.write && ['found', 'partial', 'verified', 'needs_clarification'].includes(k.status) ? `<p class="hint">${S.addFactHint}</p>` : ''}
        ${x.tasks.length ? `<h2 class="sec-title">☑️ ${S.tasks}</h2><ul class="list">${x.tasks.map(t => `<li class="card static"><div class="body"><div class="l1"><span>${esc(t.title)}</span></div></div></li>`).join('')}</ul>` : ''}
        ${x.facts.length || x.items.length ? `<h2 class="sec-title">${S.derived}</h2><ul class="list">${x.facts.map(f => `<li class="card static"><div class="body"><div class="l1"><span>📊 ${esc(f.label)}</span></div></div></li>`).join('')}${
            x.items.map(i => `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/planning/${i.pid}"><div class="body"><div class="l1"><span dir="auto">${esc(i.title)}</span><span class="t">${sKindChip(i.kind)}</span></div></div></li>`).join('')}</ul>` : ''}`;
    cOn('[data-go]', el => { location.hash = el.dataset.go; });
    $('#k-approve')?.addEventListener('click', async () => { try { await api('source-check-approve', { body: { check: checkPid } }); toast(T.saved); location.hash = `#/cases/${pid}/sources/${k.source}`; } catch (e) { toastError(e); } });
    $('#k-verify')?.addEventListener('click', async () => { if (!confirm(S.verifyAsk)) return; try { await api('source-check-verify', { body: { check: checkPid } }); toast(T.saved); reload(); } catch (e) { toastError(e); } });
    $('#k-cost')?.addEventListener('click', () => sheet(S.addCost, `<label class="f"><span>${S.costAmount}</span><input name="cost" inputmode="decimal" dir="ltr" maxlength="10" autocomplete="off" required></label>
        <label class="f"><span>${S.costNote}</span><input name="cost_note" maxlength="300" autocomplete="off"></label><p class="hint">${S.costHint}</p>`, async v => {
        await api('source-check-cost', { body: { check: checkPid, cost_amount: sCostIn(v.cost), cost_note: v.cost_note } });
        reload();
    }));
}

// ── פריט תכנון ──
/** הוספה (item = null) או עריכה. בעריכה אין שדה סוג: הסוג נקבע ביצירה, ובקשה אינה הופכת להיתר. */
function planningSheet(pid, item, pl, sources, reload) {
    const i = item || { kind: '', title: '', ref_number: '', status_reported: '', item_date: '', source: '', url: '', note: '', related: null };
    const others = pl.items.filter(o => !item || o.pid !== item.pid);
    sheet(item ? `${S.edit}: ${S.kinds[item.kind]}` : `${S.addItem} — ${S.planningTitle}`, `${item ? `<p class="hint">${sKindChip(item.kind)} ${S.kindFixed}</p>`
            : `<label class="f"><span>${S.kind} *</span><select name="kind" required><option value=""></option>${pl.kinds.map(k => cOpt(k, S.kinds[k], '')).join('')}</select></label><p class="hint">${S.kindFixed}</p>`}
        <label class="f"><span>${S.title} *</span><input name="title" maxlength="200" value="${esc(i.title)}" required></label>
        <div class="fields two"><label class="f"><span>${S.refNumber}</span><input name="ref_number" maxlength="80" dir="auto" autocomplete="off" value="${esc(i.ref_number || '')}"></label>
            <label class="f"><span>${S.itemDate}</span><input name="item_date" type="date" value="${esc(i.item_date || '')}"></label></div>
        <label class="f"><span>${S.statusReported}</span><input name="status_reported" maxlength="200" autocomplete="off" value="${esc(i.status_reported || '')}"></label><p class="hint">${S.statusReportedHint}</p>
        <label class="f"><span>${S.source}</span><select name="source"><option value="">${S.noSource}</option>${sources.map(s => cOpt(s.key, s.name, i.source || '')).join('')}</select></label>
        <label class="f"><span>${S.url}</span><input name="url" type="url" inputmode="url" dir="ltr" maxlength="500" autocomplete="off" value="${esc(i.url || '')}"></label>
        <label class="f"><span>${S.related}</span><select name="related"><option value="">${S.noRelated}</option>${others.map(o => cOpt(o.pid, `${S.kinds[o.kind]}: ${o.title}`, i.related?.pid || '')).join('')}</select></label>
        <label class="f"><span>${S.note}</span><textarea name="note" maxlength="1000" rows="2">${esc(i.note || '')}</textarea></label>`, async v => {
        const body = { title: v.title, ref_number: v.ref_number, status_reported: v.status_reported, item_date: v.item_date || null, source: v.source || null, url: v.url, related: v.related || null, note: v.note };
        const r = item ? await api('planning-update', { body: { item: item.pid, ...body } }) : await api('planning-add', { body: { case: pid, kind: v.kind, ...body } });
        toast(r.duplicate ? S.duplicate : T.saved, !!r.duplicate);
        reload();
    });
}

async function viewPlanningItem(pid, itemPid) {
    const [x, pl, cs] = await Promise.all([api('planning-item', { params: { item: itemPid } }), api('case-planning', { params: { case: pid } }), api('case-sources', { params: { case: pid } })]);
    const i = x.item, reload = () => viewPlanningItem(pid, itemPid);
    setHead(S.kinds[i.kind], null);
    const row = (label, val) => val ? `<div class="line"><span class="muted">${esc(label)}</span><span dir="auto">${val}</span></div>` : '';
    App.main.innerHTML = `<p class="hint"><a href="#/cases/${pid}/sources">‹ ${S.tab}</a> · ${esc(x.case.number)}</p>
        <div class="summary"><div class="line"><b dir="auto">${esc(i.title)}</b>${sKindChip(i.kind)}</div>
            ${row(S.refNumber, esc(i.ref_number || ''))}${row(S.itemDate, i.item_date ? esc(fmtDate(i.item_date)) : '')}${row(S.statusReported, i.status_reported ? `<q>${esc(i.status_reported)}</q>` : '')}
            ${row(S.source, i.source ? `<a href="#/cases/${pid}/sources/${esc(i.source)}">${esc(i.source_name)}</a>${i.check ? ` · <a href="#/cases/${pid}/check/${i.check}">${S.checkTitle}</a>` : ''}` : '')}
            ${row(S.url, i.url ? sLink(i.url) : '')}${row(S.file, sFile(i.file, pid))}
            ${row(S.related, i.related ? `<a href="#/cases/${pid}/planning/${i.related.pid}">${esc(S.kinds[i.related.kind])}: ${esc(i.related.title)}</a>` : '')}${row(S.note, esc(i.note || ''))}</div>
        <h2 class="sec-title">${S.applicability}</h2>
        <div class="summary"><div class="line"><span class="chip ${i.applicability === 'unknown' ? '' : 'info'}">${esc(S.applicabilities[i.applicability])}</span></div>
            ${i.applicability_at ? `<div class="line muted">${esc(S.applicabilityBy(i.applicability_by, fmtDate(i.applicability_at)))}</div><div class="line" dir="auto">${esc(i.applicability_note)}</div>` : ''}</div>
        <p class="hint">${x.perms.judge ? '' : S.applicabilityOnly}${x.perms.write && !x.perms.edit_item ? ' ' + S.lockedByJudge : ''}</p>
        <div class="row-btns">${x.perms.judge ? `<button type="button" class="btn primary" id="p-apply">${S.setApplicability}</button>` : ''}
            ${x.perms.edit_item ? `<button type="button" class="btn ghost" id="p-edit">${S.edit}</button>` : ''}
            ${x.perms.write ? `<button type="button" class="btn ghost" id="p-task">${S.task}</button>` : ''}
            ${x.perms.edit_item ? `<button type="button" class="btn ghost danger" id="p-del">${S.del}</button>` : ''}</div>
        ${x.tasks.length ? `<h2 class="sec-title">☑️ ${S.tasks}</h2><ul class="list">${x.tasks.map(t => `<li class="card static"><div class="body"><div class="l1"><span>${esc(t.title)}</span><span class="t">${esc(T.c.taskStatuses?.[t.status] || '')}</span></div>${
            t.detail ? `<div class="l2 wrap">${esc(t.detail)}</div>` : ''}</div></li>`).join('')}</ul>` : ''}
        <h2 class="sec-title">${S.itemHistory}</h2>${auditHtml(x.history, r => esc((T.audit[r.action] || (() => r.action))({ ...r, meta: {} })) + (r.reason ? ' — ' + esc(r.reason) : ''), r => esc(r.by || T.c.system))}`;
    $('#p-edit')?.addEventListener('click', () => planningSheet(pid, i, pl, cs.sources, reload));
    $('#p-apply')?.addEventListener('click', () => sheet(S.setApplicability, `<label class="f"><span>${S.applicability}</span><select name="applicability">${x.applicability.map(a => cOpt(a, S.applicabilities[a], i.applicability)).join('')}</select></label>
        <label class="f"><span>${S.applicabilityNote} *</span><textarea name="note" maxlength="500" rows="3" required></textarea></label>`, async v => {
        await api('planning-applicability', { body: { item: itemPid, applicability: v.applicability, note: v.note } });
        reload();
    }));
    $('#p-task')?.addEventListener('click', () => sheet(S.task, `<p class="hint">${S.taskHint}</p><label class="f"><span>${S.taskDetail}</span><textarea name="detail" maxlength="1000" rows="3"></textarea></label>`, async v => {
        const r = await api('planning-task', { body: { item: itemPid, detail: v.detail } });
        toast(r.existing ? S.taskExists : S.taskOpened);
        reload();
    }));
    $('#p-del')?.addEventListener('click', () => sheet(S.del, `<label class="f"><span>${S.delReason} *</span><textarea name="reason" maxlength="500" rows="2" required></textarea></label>`, async v => {
        await api('planning-delete', { body: { item: itemPid, reason: v.reason } });
        location.hash = `#/cases/${pid}/sources`;
    }, S.del));
}

// ── הקטלוג לקריאה (#/sources) ──
async function viewSourcesCatalog(key) {
    const d = await api('sources');
    setHead(S.catalog, null);
    const one = key && d.sources.find(s => s.key === key);
    if (one) {
        App.main.innerHTML = `<p class="hint"><a href="#/sources">‹ ${S.catalog}</a></p>${sCardHtml(one)}<div class="row-btns">${one.url ? sLink(one.url, '🌐 ' + esc(S.open), 'btn ghost') : ''}</div><p class="hint">${one.url ? S.openHint : S.noUrl}</p>`;
        return;
    }
    App.main.innerHTML = `<p class="hint">${S.catalogHint}</p><ul class="list">${d.sources.map(s => `<li class="card" tabindex="0" role="button" data-go="#/sources/${esc(s.key)}"><div class="body">
        <div class="l1"><span>${esc(s.name)}</span><span class="t">${s.info_kinds.map(k => esc(S.infoKinds[k] || k)).join(' · ')}</span></div>
        ${s.coverage_note ? `<div class="l2 wrap">${esc(S.coverage)}: ${esc(s.coverage_note)}</div>` : ''}<div class="chips">${sSourceChips(s)}</div></div></li>`).join('')}</ul>`;
    cOn('[data-go]', el => { location.hash = el.dataset.go; });
}

// ── ניהול הקטלוג (מנהל המערכת) ──
async function viewSysSources() {
    const d = await api('sys-sources');
    const html = `<p class="hint">${S.sysHint}</p><div class="toolbar"><button type="button" class="btn primary" id="src-new"><span aria-hidden="true">+</span> ${S.newSource}</button></div>
        <ul class="list">${d.sources.map(s => `<li class="card" tabindex="0" role="button" data-src="${esc(s.key)}"><div class="body"><div class="l1"><span>${esc(s.name)}</span><span class="t" dir="ltr">${esc(s.key)}</span></div>
            ${s.coverage_note ? `<div class="l2 wrap">${esc(s.coverage_note)}</div>` : ''}<div class="chips">${sSourceChips(s)}${s.in_use ? `<span class="chip info">${esc(S.inUse)}</span>` : ''}</div></div></li>`).join('')}</ul>`;
    const edit = s => {
        const isNew = !s;
        s = s || { key: '', name: '', owner: '', url: '', info_kinds: [], region: 'national', municipality: '', access_mode: 'manual', feasibility: 'manual_only', auth_required: 'none', cost_note: '', terms_url: '',
            license: {}, attribution: '', coverage_note: '', limitations: '', how_to: '', last_incident: '', status: 'active', sort: 0 };
        const ta = (name, label, val, max) => `<label class="f"><span>${label}</span><textarea name="${name}" maxlength="${max}" rows="2">${esc(val || '')}</textarea></label>`;
        sheet(isNew ? S.newSource : s.name, `${isNew ? `<label class="f"><span>${S.key}</span><input name="key" maxlength="40" dir="ltr" autocomplete="off" required></label>` : ''}
            <label class="f"><span>${S.name}</span><input name="name" maxlength="120" value="${esc(s.name)}" required></label>
            <label class="f"><span>${S.owner}</span><input name="owner" maxlength="120" value="${esc(s.owner)}" required></label>
            <label class="f"><span>${S.siteUrl}</span><input name="url" type="url" dir="ltr" maxlength="500" autocomplete="off" value="${esc(s.url || '')}"></label>
            <div class="f"><span>${S.infoLabel}</span><div class="chips">${d.info_kinds.map(k => `<label class="chip pick"><input type="checkbox" name="info" value="${k}"${s.info_kinds.includes(k) ? ' checked' : ''}> ${esc(S.infoKinds[k])}</label>`).join('')}</div></div>
            <div class="fields two"><label class="f"><span>${S.region}</span><select name="region">${Object.keys(S.regions).map(r => cOpt(r, S.regions[r], s.region)).join('')}</select></label>
                <label class="f"><span>${S.municipality}</span><input name="municipality" maxlength="80" value="${esc(s.municipality || '')}"></label></div>
            <div class="fields two"><label class="f"><span>${S.accessMode}</span><select name="access_mode">${d.access_modes.map(a => cOpt(a, S.access[a], s.access_mode)).join('')}</select></label>
                <label class="f"><span>${S.feasibilityLabel}</span><select name="feasibility">${d.feasibility.map(a => cOpt(a, S.feasibility[a], s.feasibility)).join('')}</select></label></div>
            <label class="f"><span>${S.authLabel}</span><select name="auth_required">${d.auth.map(a => cOpt(a, S.auth[a], s.auth_required)).join('')}</select></label>
            ${ta('cost_note', S.cost, s.cost_note, 300)}<label class="f"><span>${S.termsUrl}</span><input name="terms_url" type="url" dir="ltr" maxlength="500" autocomplete="off" value="${esc(s.terms_url || '')}"></label>
            <section class="group"><h3>${S.licenseTitle}</h3>${d.license_keys.map(k => `<label class="f"><span>${esc(S.license[k])}</span><select name="lic_${k}">${Object.keys(S.licenseValues).map(v => cOpt(v, S.licenseValues[v], s.license[k] || 'unknown')).join('')}</select></label>`).join('')}</section>
            ${ta('attribution', S.attribution, s.attribution, 300)}${ta('coverage_note', S.coverage, s.coverage_note, 1000)}${ta('limitations', S.limitations, s.limitations, 1000)}${ta('how_to', S.howTo, s.how_to, 2000)}${ta('last_incident', S.incident, s.last_incident, 500)}
            <label class="f"><span>${S.sort}</span><input name="sort" inputmode="numeric" dir="ltr" maxlength="6" value="${esc(s.sort)}"></label>
            <label class="switch"><input type="checkbox" name="active"${s.status === 'active' ? ' checked' : ''}><span>${S.active}</span></label>
            <label class="switch"><input type="checkbox" name="mark_verified"><span>${S.markVerified}</span></label>`, async (v, dlg) => {
            await api('sys-source-save', { body: { key: isNew ? v.key.trim() : s.key, create: isNew, name: v.name, owner: v.owner, url: v.url, info_kinds: $$('[name="info"]:checked', dlg).map(i => i.value), region: v.region,
                municipality: v.municipality, access_mode: v.access_mode, feasibility: v.feasibility, auth_required: v.auth_required, cost_note: v.cost_note, terms_url: v.terms_url,
                license: Object.fromEntries(d.license_keys.map(k => [k, v['lic_' + k]])), attribution: v.attribution, coverage_note: v.coverage_note, limitations: v.limitations, how_to: v.how_to,
                last_incident: v.last_incident, status: v.active ? 'active' : 'hidden', sort: /^\d+$/.test(v.sort) ? Number(v.sort) : 0, mark_verified: !!v.mark_verified } });
            toast(T.saved);
            route();
        });
    };
    return { html, bind: () => { $('#src-new').addEventListener('click', () => edit(null)); cOn('[data-src]', el => edit(d.sources.find(s => s.key === el.dataset.src))); } };
}

// ── עורך הפרופיל ← "מקורות" (§8) ──
async function peSources(body) {
    const cat = (await api('sources')).sources;
    const k = PE.content.sources = PE.content.sources || { items: [], spend_needs_approval: true };
    const modeOf = key => k.items.find(i => i.source === key)?.mode || 'manual';
    const gone = k.items.filter(i => !cat.some(s => s.key === i.source));
    body.innerHTML = `<form class="panel" id="psrc" novalidate><p class="hint">${S.profileHint}</p>
        ${cat.map(s => `<label class="f"><span>${esc(s.name)} <small class="muted">${esc(S.access[s.access_mode])}</small></span><select data-src-mode="${esc(s.key)}">${Object.keys(S.modes).map(m => cOpt(m, S.modes[m], modeOf(s.key))).join('')}</select></label>`).join('')}
        ${gone.map(i => `<p class="banner" role="note">⚠️ <span dir="ltr">${esc(i.source)}</span> — ${esc(S.unknownSource)} <button type="button" class="btn ghost sm" data-src-drop="${esc(i.source)}">${S.remove}</button></p>`).join('')}
        <label class="switch"><input type="checkbox" name="spend"${k.spend_needs_approval ? ' checked' : ''}><span>${S.spendSwitch}</span></label></form>`;
    const read = () => {
        const keep = k.items.filter(i => !cat.some(s => s.key === i.source));
        // "ביוזמת השמאי" היא ברירת המחדל — לא נשמרת כשורה, אלא אם כבר הייתה (כדי לשמור תנאי שהוגדר לה)
        const mine = $$('[data-src-mode]', body).map(el => ({ source: el.dataset.srcMode, mode: el.value, when: k.items.find(i => i.source === el.dataset.srcMode)?.when ?? null }))
            .filter(i => i.mode !== 'manual' || k.items.some(o => o.source === i.source));
        k.items = [...mine, ...keep];
        k.spend_needs_approval = $('[name="spend"]', body).checked;
        peChanged();
    };
    $('#psrc').addEventListener('change', read);
    $$('[data-src-drop]', body).forEach(b => b.addEventListener('click', () => { k.items = k.items.filter(i => i.source !== b.dataset.srcDrop); peChanged(); peSources(body); }));
}
