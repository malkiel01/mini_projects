/* מסך המפתח: מטלות (DevLoop), יומן בקשות ושגיאות, יומן פעולות, בדיקות תקינות, שירותי רקע. */
'use strict';

const DEV_TABS = ['tasks', 'log', 'audit', 'health', 'jobs'];

async function viewDev(tab, arg) {
    if (!DEV_TABS.includes(tab)) tab = sessionStorage.getItem('apr-dev-tab') || 'tasks';
    sessionStorage.setItem('apr-dev-tab', tab);
    setHead(T.devTitle, null);
    App.main.innerHTML = tabsHtml(DEV_TABS.map(k => ['#/dev/' + k, T.devTabs[k]]), '#/dev/' + tab).replace('seg-tabs', 'seg-tabs five') + '<div id="dev-body"></div>';
    const body = $('#dev-body');
    if (tab === 'tasks') return DevLoop.show(body, arg || '');
    if (tab === 'log') return DevLoop.showLog(body, arg || '');
    if (tab === 'audit') return devAudit(body);
    if (tab === 'health') return devHealth(body);
    return devJobs(body);
}

/** יומן הפעולות של כל המערכת — מזהים, קודים ומצבים. לחיצה על מזהה מסננת לפיו; מזהה הבקשה פותח את היומן הטכני. */
async function devAudit(body, f = {}) {
    const d = await api('dev-audit', { params: Object.fromEntries(Object.entries(f).filter(([, v]) => v)) });
    const pidBtn = (kind, pid) => pid ? `<button class="pid" data-f="${kind}" data-v="${esc(pid)}">${esc(pid.slice(0, 6))}…</button>` : '—';
    body.innerHTML = `<p class="hint">${T.auditHint}</p>
        <form class="toolbar wrap" id="af">
          <input name="what" value="${esc(f.what || '')}" placeholder="${T.auditFilter}" aria-label="${T.auditFilter}" dir="ltr">
          <input name="from" type="date" value="${esc(f.from || '')}" aria-label="מתאריך"><input name="to" type="date" value="${esc(f.to || '')}" aria-label="עד תאריך">
        </form>
        ${f.office || f.user ? `<p class="hint">${f.office ? `${T.filterOffice}: <code>${esc(f.office)}</code> ` : ''}${f.user ? `${T.filterUser}: <code>${esc(f.user)}</code> ` : ''}
            <button class="btn sm ghost" id="clear">${T.clear}</button></p>` : ''}
        ${auditHtml(d.rows, r => `<code>${esc(r.action)}</code> <span class="muted">${esc(r.entity_type)}</span> ${pidBtn(r.entity_type === 'user' ? 'user' : r.entity_type === 'office' ? 'office' : '', r.entity_pid)}
            ${Object.keys(r.meta).length ? `<code class="meta">${esc(JSON.stringify(r.meta))}</code>` : ''}`,
            r => `${r.actor ? pidBtn('user', r.actor) : esc(r.actor_kind)}${r.office ? `<i class="sep" aria-hidden="true">·</i>🏛️ ${pidBtn('office', r.office)}` : ''}${
                r.req ? `<i class="sep" aria-hidden="true">·</i><a href="#/dev/log/${esc(r.req)}">#${esc(r.req)}</a>` : ''}`).replaceAll('<li class="ev">', '<li class="ev ltr">')}`;
    const form = $('#af');
    form.addEventListener('change', () => devAudit(body, { ...f, ...Object.fromEntries(new FormData(form)) }));
    form.addEventListener('submit', e => { e.preventDefault(); devAudit(body, { ...f, ...Object.fromEntries(new FormData(form)) }); });
    $('#clear')?.addEventListener('click', () => devAudit(body, { ...f, office: '', user: '' }));
    $$('.pid[data-f]', body).forEach(b => b.dataset.f && b.addEventListener('click', () => devAudit(body, { ...f, [b.dataset.f]: b.dataset.v })));
}

async function devHealth(body) {
    body.innerHTML = `<p class="empty">${T.healthChecking}</p>`;
    const h = await api('dev-health');
    const bad = h.checks.filter(c => c.state !== 'ok').length;
    const icon = { ok: '✅', warn: '⚠️', bad: '❌' };
    body.innerHTML = `<div class="summary"><div class="line"><span aria-hidden="true">${icon[h.state]}</span>
        <b>${bad ? T.healthSome(bad, h.checks.length) : T.healthAll(h.checks.length)}</b>
        <button class="btn sm ghost" id="run" style="margin-inline-start:auto">${T.healthRun}</button></div></div>
        <ul class="list health">${h.checks.map(c => `<li class="card static"><span role="img" aria-label="${T.health[c.state]}">${icon[c.state]}</span>
        <div class="body"><div class="l1"><span>${esc(c.label)}</span></div><div class="l2">${esc(c.detail)}</div></div></li>`).join('')}</ul>`;
    $('#run').addEventListener('click', () => devHealth(body).catch(toastError));
}

async function devJobs(body) {
    const [d, mail] = await Promise.all([api('dev-jobs'), api('dev-mail')]);
    const c = d.cron, cmd = `curl -s "${c.url}"`;
    const chip = { failed: 'bad', needs_action: 'bad', partial: 'warn', pending: 'info', running: 'info' };
    body.innerHTML = `
        <p class="sec-title">${T.cronTitle}</p>
        <div class="summary"><div class="line"><b>${c.last_ok_at ? esc(T.cronLast(fmtDateTime(c.last_ok_at), c.last_ms)) : T.cronNever}</b></div>
          ${c.last_source ? `<div class="line muted">${T.cronSource[c.last_source] || esc(c.last_source)}</div>` : ''}
          <div class="line muted">${T.cronUrlHint}</div><code class="cmd" dir="ltr">${esc(cmd)}</code>
          <div class="row-btns"><button class="btn ghost" id="copy-cron">${T.cronCopy}</button><button class="btn ghost" id="run-cron">${T.cronRun}</button></div></div>
        <p class="sec-title">${T.queueTitle}</p>
        <dl class="cells">${Object.entries(T.jobStatus).filter(([k]) => d.counts[k] || ['pending', 'failed', 'done'].includes(k)).map(([k, label]) =>
            `<div><dt>${label}</dt><dd>${d.counts[k] || 0}</dd></div>`).join('')}</dl>
        <p class="sec-title">${T.kindsTitle}</p>
        <ul class="list">${d.kinds.map(k => `<li class="card static"><span class="use-ic" aria-hidden="true">⚙️</span><div class="body">
            <div class="l1"><span dir="ltr">${esc(k.kind)}</span><span class="t">${T.kindEvery(k.every)}</span></div>
            <div class="l2 wrap">${esc(k.label)}<i class="sep" aria-hidden="true">·</i>${k.last_done_at ? esc(T.kindLast(fmtDateTime(k.last_done_at))) : T.kindNever}</div></div></li>`).join('')}</ul>
        <p class="sec-title">${T.jobsTitle}</p>
        ${d.jobs.length ? `<ul class="list">${d.jobs.map(j => `<li class="card static"><div class="body">
            <div class="l1"><span dir="ltr">${esc(j.kind)}</span><span class="t">${esc(fmtDateTime(j.finished_at || j.run_after))}</span></div>
            <div class="l2 wrap">${esc(T.attempts(j.attempts, j.max_attempts))}${j.last_error ? `<i class="sep" aria-hidden="true">·</i><span dir="ltr">${esc(j.last_error)}</span>` : ''}</div></div>
            <span class="chip ${chip[j.status] || 'ok'}">${T.jobStatus[j.status] || esc(j.status)}</span>
            ${['failed', 'needs_action', 'cancelled'].includes(j.status) ? `<button class="icon-btn" data-retry-job="${j.pid}" aria-label="${T.jobRetry}">🔁</button>` : ''}
            ${['pending', 'failed', 'needs_action'].includes(j.status) ? `<button class="icon-btn" data-cancel-job="${j.pid}" aria-label="${T.jobCancel}">✕</button>` : ''}</li>`).join('')}</ul>`
            : `<p class="empty">${T.jobsEmpty}</p>`}
        <p class="sec-title">✉️ ${T.mailTitle}</p><p class="hint">${T.mailHint}</p>
        ${mail.rows.length ? `<ul class="list">${mail.rows.map(m => `<li class="card static"><div class="body">
            <div class="l1"><span>${esc(T.mailKind[m.kind] || m.kind)}</span><span class="t">${esc(fmtDateTime(m.at))}</span></div>
            <div class="l2 wrap"><span dir="ltr">${esc(m.to)}…</span>${m.error ? `<i class="sep" aria-hidden="true">·</i><span dir="ltr">${esc(m.error)}</span>` : ''}</div></div>
            <span class="chip ${m.status === 'sent' ? 'ok' : 'bad'}">${T.mailChip[m.status] || esc(m.status)}</span></li>`).join('')}</ul>` : `<p class="empty">${T.mailEmpty}</p>`}`;
    const act = (action, job) => api(action, { body: job ? { job } : {} }).then(r => { if (action === 'dev-cron-run') toast(T.cronRan(r)); return devJobs(body); }).catch(toastError);
    $('#copy-cron').addEventListener('click', () => copyText(cmd));
    $('#run-cron').addEventListener('click', () => act('dev-cron-run'));
    $$('[data-retry-job]', body).forEach(b => b.addEventListener('click', () => act('dev-job-retry', b.dataset.retryJob)));
    $$('[data-cancel-job]', body).forEach(b => b.addEventListener('click', () => act('dev-job-cancel', b.dataset.cancelJob)));
}
