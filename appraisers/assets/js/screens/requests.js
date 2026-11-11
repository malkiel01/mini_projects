/*
 * בקשות לגורם חיצוני (אפיון 07 §4, §6, §7, §10): רשימת הבקשות של תיק, "בקשה חדשה" עם תצוגה מקדימה, שליחה מחדש, ביטול,
 * "הודעות שנכשלו" בלוח הראשי, והבקשות והתשובות של דרישה. אין שליחה בלי התצוגה המקדימה — השרת אוכף; כאן זה פשוט הסדר.
 * כל מה שהנמען כתב (הערה, הסבר) וכל מה שהצוות כתב מוצג דרך esc().
 */
'use strict';

const RQ = T.rq;
const rqChip = q => `<span class="chip ${({ active: 'info', expired: 'warn', revoked: 'bad', completed: 'ok' })[q.status] || ''}">${esc(RQ.statuses[q.status] || q.status)}</span>`;
const rqMsgChip = m => m ? `<span class="chip ${({ sent: 'ok', delivered: 'ok', answered: 'ok', received: 'info', failed: 'bad', unknown: 'warn', skipped: '' })[m.status] || ''}">${esc(RQ.channels[m.channel] || m.channel)} · ${esc(RQ.msg[m.status] || m.status)}</span>` : '';
const rqPermsText = p => Object.keys(RQ.perms).filter(k => p[k]).map(k => RQ.perms[k]).join(' · ') || RQ.noPerms;

function requestRow(q, d) {
    const titles = q.requirements.map(pid => d.requirements.find(r => r.pid === pid)?.title).filter(Boolean);
    return `<li class="card" tabindex="0" role="button" data-rq="${q.pid}"><span class="use-ic" aria-hidden="true">📨</span>
        <div class="body"><div class="l1"><span>${esc(q.party_name)}</span><span class="t">${esc(fmtDate(q.created_at))}</span></div>
        <div class="l2 wrap">${esc(titles.join(' · '))}</div>
        <div class="l2 wrap">${esc(RQ.until(fmtDate(q.expires_at)))} · ${esc(q.last_used_at ? RQ.lastUsed(fmtDateTime(q.last_used_at)) : RQ.neverUsed)}</div>
        <div class="chips">${rqChip(q)}${rqMsgChip(q.messages[0])}${q.needs_resend ? `<span class="chip bad">${RQ.needsResend}</span>` : ''}${
            q.responses.length ? `<span class="chip warn">${esc(RQ.responsesN(q.responses.length))}</span>` : ''}</div></div></li>`;
}

/** הבקשות של התיק — בלשונית "גורמים והרשאות". */
function requestsHtml(x, d) {
    return `<h2 class="sec-title">📨 ${RQ.title} <small>${x.requests.length || ''}</small></h2>
        ${x.requests.length ? `<ul class="list">${x.requests.map(q => requestRow(q, d)).join('')}</ul>` : `<p class="empty">${RQ.none}</p>`}
        ${x.can_send ? `<div class="row-btns"><button type="button" class="btn primary" id="rqnew"><span aria-hidden="true">+</span> ${RQ.newOne}</button></div>` : ''}`;
}

function requestsBind(x, d, reload) {
    $('#rqnew')?.addEventListener('click', () => requestCompose(x, d, reload, {}));
    cOn('[data-rq]', el => requestSheet(x.requests.find(q => q.pid === el.dataset.rq), x, d, reload));
}

/** גיליון של בקשה קיימת: מצב, הודעות, תשובות הנמען, ופעולות. */
function requestSheet(q, x, d, reload) {
    const reqTitle = pid => d.requirements.find(r => r.pid === pid)?.title || '';
    const live = x.can_send && q.status !== 'revoked';
    sheet(RQ.requestTo(q.party_name), `<div class="summary"><div class="line"><b class="one">${esc(q.subject)}</b>${rqChip(q)}</div>
          <div class="line muted">${esc(RQ.sentBy(q.created_by || C.system, fmtDateTime(q.created_at)))}</div>
          <div class="line muted">${esc(RQ.until(fmtDate(q.expires_at)))} · ${esc(q.last_used_at ? RQ.lastUsed(fmtDateTime(q.last_used_at)) : RQ.neverUsed)}</div>
          <div class="line muted">${esc(rqPermsText(q.perms))}</div></div>
        ${q.needs_resend ? `<p class="banner" role="note">✉️ ${RQ.needsResendNote}</p>` : ''}
        <p class="sec-title">${RQ.itemsTitle}</p>
        <ul class="list">${q.requirements.map(pid => `<li class="card static"><div class="body"><div class="l1"><span>${esc(reqTitle(pid))}</span></div></div>${dStatusChip(d.requirements.find(r => r.pid === pid) || { status: '' })}</li>`).join('')}</ul>
        <p class="sec-title">${RQ.messagesTitle}</p>
        ${rqTimelineHtml(q)}
        ${q.messages[0]?.status === 'unknown' ? `<p class="hint">${RQ.unknownNote}</p>` : ''}${q.messages[0]?.status === 'sent' ? `<p class="hint">${RQ.sentNote}</p>` : ''}
        ${q.responses.length ? `<p class="sec-title">${RQ.responsesTitle}</p><ul class="list">${q.responses.map(s => `<li class="card static"><span class="use-ic" aria-hidden="true">${s.kind === 'no_document' ? '🚫' : '💬'}</span>
            <div class="body"><div class="l1"><span>${esc(RQ.kinds[s.kind])} — ${esc(reqTitle(s.requirement))}</span><span class="t">${esc(fmtDate(s.created_at))}</span></div>
            ${s.text ? `<div class="l2 wrap" dir="auto">${esc(s.text)}</div>` : ''}</div></li>`).join('')}</ul>` : ''}
        ${live ? `<div class="row-btns wrap">${q.reminders?.state === 'active' ? `<button type="button" class="btn ghost" id="rqstop">${T.ch.stop}</button>` : ''}<button type="button" class="btn ghost" id="rqresend">${RQ.resend}</button><button type="button" class="btn ghost" id="rqcopy">${RQ.copyLink}</button>
            <button type="button" class="btn ghost danger" id="rqrevoke">${RQ.revoke}</button></div><p class="hint">${RQ.resendHint}</p>` : ''}`);
    const party = d.parties.find(p => p.pid === q.party);
    rqTimelineBind(q, x, reload, $('#sheet'));
    $('#rqresend')?.addEventListener('click', () => requestPreview({ request: q.pid, channel: 'email' }, x, d, reload, party, null));
    $('#rqcopy')?.addEventListener('click', () => requestPreview({ request: q.pid, channel: 'link_copy' }, x, d, reload, party, null));
    $('#rqrevoke')?.addEventListener('click', async () => {
        if (!confirm(RQ.revokeAsk)) return;
        try { await api('request-revoke', { body: { request: q.pid } }); $('#sheet').close(); toast(RQ.revoked); reload(); } catch (e) { toastError(e); }
    });
}

/** שלב א: הרכבה — נמען, דרישות, פעולות, תוקף ונוסח. $st — מה שכבר נבחר (חוזרים לכאן מהתצוגה המקדימה). */
function requestCompose(x, d, reload, st) {
    if (!d.parties.length) { toast(RQ.noParties, true); return; }
    const partyPid = st.party || d.parties.find(p => p.is_orderer)?.pid || d.parties[0].pid;
    const can = d.requirements.filter(r => !r.split && x.item_states.includes(r.status));
    const perms = st.perms || x.perms_default;
    const dlg = sheet(RQ.newOne, `<label class="f"><span>${RQ.recipient}</span><select name="party">${d.parties.map(p => cOpt(p.pid, p.contact.name + ' · ' + (C.partyKinds[p.kind] || p.kind), partyPid)).join('')}</select></label>
        <div id="rqcontact"></div>
        <fieldset class="f"><legend>${RQ.itemsTitle}</legend><div id="rqitems"></div></fieldset>
        <fieldset class="f"><legend>${RQ.allow}</legend>${Object.keys(RQ.perms).map(k => `<label class="switch"><input type="checkbox" name="perm_${k}"${perms[k] ? ' checked' : ''}><span>${RQ.perms[k]}</span></label>`).join('')}
          <p class="hint">${RQ.permsHint}</p></fieldset>
        <label class="f"><span>${RQ.validFor}</span><select name="days">${x.days.map(n => cOpt(String(n), RQ.daysN(n), String(st.days || 30))).join('')}</select></label>
        <label class="f"><span>${RQ.subject}</span><input name="subject" maxlength="150" value="${esc(st.subject || '')}" placeholder="${RQ.fromTemplate}"></label>
        <label class="f"><span>${RQ.body}</span><textarea name="body" maxlength="4000" rows="6" placeholder="${RQ.fromTemplate}">${esc(st.body || '')}</textarea></label>
        <p class="hint">${RQ.bodyHint}</p>`, async f => {
        const body = { case: d.case.pid, party: f.party, requirements: $$('#rqitems input:checked', dlg).map(i => i.value), channel: f.channel || 'link_copy', contact_ok: f.contact_ok === 'on',
            perms: Object.fromEntries(Object.keys(RQ.perms).map(k => [k, f['perm_' + k] === 'on'])), days: Number(f.days) };
        if (f.subject.trim()) body.subject = f.subject.trim();
        if (f.body.trim()) body.body = f.body;
        await requestPreview(body, x, d, reload, d.parties.find(p => p.pid === f.party), body);
        return false;
    }, RQ.toPreview);
    const draw = () => {
        // חיבור מייל מושבת או שנכשל: המייל אינו זמין, והשליחה מציעה "העתקת קישור" (08-E2)
        const p = d.parties.find(q => q.pid === dlg.querySelector('[name=party]').value), canMail = (x.connections || []).some(k => k.channel === 'email' && k.status === 'active');
        const mail = canMail ? p.contact.email : null;
        const ch = st.channel || (mail ? 'email' : 'link_copy');
        $('#rqcontact', dlg).innerHTML = `<div class="summary"><div class="line"><b class="one">${esc(p.contact.name)}</b></div>
              <div class="line muted" dir="auto">${esc([p.contact.org, p.contact.phone, mail].filter(Boolean).join(' · ') || RQ.noContact)}</div></div>
            <fieldset class="seg" aria-label="${RQ.channel}"><label><input type="radio" name="channel" value="email"${ch === 'email' && mail ? ' checked' : ''}${mail ? '' : ' disabled'}><span>${RQ.channels.email}</span></label>
              <label><input type="radio" name="channel" value="link_copy"${ch !== 'email' || !mail ? ' checked' : ''}><span>${RQ.channels.link_copy}</span></label></fieldset>
            ${mail ? `<label class="switch"><input type="checkbox" name="contact_ok"${st.contact_ok ? ' checked' : ''}><span>${esc(RQ.contactOk(mail))}</span></label>` : `<p class="hint">${canMail ? RQ.noEmailHint : T.ch.noEmailChannel}</p>`}
            ${canMail ? '' : (x.connections || []).filter(k => k.channel === 'email').map(k => `<p class="hint">🔌 ${esc(k.label)} — ${esc(T.ch.statuses[k.status] || k.status)}</p>`).join('')}`;
        const chosen = st.requirements;
        $('#rqitems', dlg).innerHTML = can.length ? can.map(r => `<label class="switch"><input type="checkbox" value="${r.pid}"${(chosen ? chosen.includes(r.pid) : r.party === p.pid) ? ' checked' : ''}>
            <span>${esc(r.title)}${r.status !== 'required' ? ` <small class="muted">${esc(D.statuses[r.status])}</small>` : ''}</span></label>`).join('') : `<p class="empty">${RQ.noItems}</p>`;
    };
    dlg.querySelector('[name=party]').addEventListener('change', () => { st = { ...st, requirements: null, channel: null, contact_ok: false }; draw(); });
    draw();
}

/** שלב ב: התצוגה המקדימה — בדיוק מה שיישלח — ומכאן בלבד שולחים. $edit — התוכן לחזרה לעריכה (בקשה חדשה), או null (שליחה מחדש). */
async function requestPreview(body, x, d, reload, party, edit) {
    const mail = party?.contact.email;
    if (!edit && body.channel === 'email') {
        if (!mail) { toast(RQ.noEmailHint, true); return; }
        if (!confirm(RQ.contactOk(mail) + '?')) return;
        body = { ...body, contact_ok: true };
    }
    let pv;
    try { pv = await api('request-preview', { body }); } catch (e) { if (edit) throw e; toastError(e); return; }
    const op = opId();
    const link = pv.channel === 'link_copy';
    const dlg = sheet(RQ.previewTitle, `<p class="banner" role="note">👁️ ${RQ.previewNote}</p>
        <dl class="cells"><div><dt>${RQ.from}</dt><dd>${esc(pv.sender.office)}</dd></div><div><dt>${RQ.replyTo}</dt><dd dir="auto">${esc(pv.sender.name)}${link ? '' : ' · ' + esc(pv.sender.reply_to || '')}</dd></div>
          <div><dt>${RQ.recipient}</dt><dd dir="auto">${esc(pv.recipient.name)}${link ? '' : ' · ' + esc(pv.recipient.email || '')}</dd></div><div><dt>${RQ.channel}</dt><dd>${esc(RQ.channels[pv.channel])}</dd></div>
          <div><dt>${RQ.validUntil}</dt><dd>${esc(fmtDate(pv.expires_at))}</dd></div><div><dt>${RQ.allow}</dt><dd>${esc(rqPermsText(pv.perms))}</dd></div></dl>
        <p class="sec-title">${esc(pv.subject)}</p>
        <div class="panel"><p style="white-space:pre-wrap;margin:0" dir="auto">${esc(pv.body)}</p><p class="muted" style="margin:.6em 0 0">${RQ.linkLine}</p></div>
        <p class="sec-title">${RQ.itemsTitle} <small>${pv.requirements.length}</small></p>
        <ul class="list">${pv.requirements.map(r => `<li class="card static"><div class="body"><div class="l1"><span>${esc(r.title)}</span>${r.due_at ? `<span class="t">${esc(fmtDate(r.due_at))}</span>` : ''}</div>
            ${r.explain ? `<div class="l2 wrap">${esc(r.explain)}</div>` : ''}</div></li>`).join('')}</ul>
        <p class="hint">⚠️ ${RQ.anyoneNote}</p>${link ? `<p class="hint">${RQ.unknownNote}</p>` : `<p class="hint">${RQ.sentNote}</p>`}${pv.otp.always ? `<p class="hint">${RQ.alwaysOtp}</p>` : ''}
        ${edit ? `<div class="row-btns"><button type="button" class="btn ghost" id="rqback">${RQ.backToEdit}</button></div>` : ''}`, async () => {
        const r = await api(body.request ? 'request-resend' : 'request-send', { body: { ...body, preview: pv.preview, op } });
        reload();
        if (r.link) { linkSheet(r.link); return false; }
        if (r.message === 'failed') { toast(RQ.failedToast, true); return; }
        toast(r.repeat ? RQ.alreadySent : RQ.sentToast);
    }, link ? RQ.makeLink : RQ.send);
    $('#rqback', dlg)?.addEventListener('click', () => requestCompose(x, d, reload, { ...edit, subject: pv.subject, body: pv.body }));
}

/** "קישור להעתקה": הקישור מוצג פעם אחת. */
function linkSheet(link) {
    sheet(RQ.linkTitle, `<p class="banner" role="note">🔗 ${RQ.linkOnce}</p>
        <label class="f"><span>${RQ.link}</span><input readonly dir="ltr" value="${esc(link)}" id="rqlink"></label>
        <div class="row-btns"><button type="button" class="btn primary" id="rqlinkcopy">${RQ.copy}</button></div>
        <p class="hint">${RQ.unknownNote}</p><p class="hint">⚠️ ${RQ.anyoneNote}</p>`);
    $('#rqlinkcopy').addEventListener('click', () => copyText(link));
}

/** ללוח הראשי: הודעות שנכשלו. */
function failedRequestsHtml(rows) {
    if (!rows.length) return '';
    return `<h2 class="sec-title">⚠️ ${RQ.failedTitle} <small>${rows.length}</small></h2>
        <ul class="list">${rows.map(r => `<li class="card" tabindex="0" role="button" data-go="#/cases/${r.case.pid}/parties"><span class="use-ic" aria-hidden="true">📭</span>
            <div class="body"><div class="l1"><span>${esc(r.party_name)}</span><span class="t">${esc(fmtDate(r.failed_at))}</span></div>
            <div class="l2">${esc(r.case.title || C.noTitle)} · <span dir="ltr">${esc(r.case.number)}</span></div></div><span class="chip bad">${RQ.msg.failed}</span></li>`).join('')}</ul>
        <p class="hint">${RQ.failedHint}</p>`;
}

/** במסך של דרישה: מתי נשלחה, למי, באיזה ערוץ ומה המצב — ותשובות הנמען. */
function reqRequestsHtml(x, rpid) {
    const mine = x.requests.filter(q => q.requirements.includes(rpid));
    if (!mine.length) return '';
    const resp = mine.flatMap(q => q.responses.filter(s => s.requirement === rpid).map(s => ({ ...s, who: q.party_name })));
    return `<h2 class="sec-title">📨 ${RQ.title} <small>${mine.length}</small></h2>
        <ul class="list">${mine.map(q => `<li class="card static"><span class="use-ic" aria-hidden="true">📨</span><div class="body"><div class="l1"><span>${esc(q.party_name)}</span><span class="t">${esc(fmtDateTime(q.created_at))}</span></div>
            <div class="l2 wrap">${esc(RQ.until(fmtDate(q.expires_at)))} · ${esc(q.last_used_at ? RQ.lastUsed(fmtDateTime(q.last_used_at)) : RQ.neverUsed)}</div>
            <div class="chips">${rqChip(q)}${rqMsgChip(q.messages[0])}</div></div></li>`).join('')}</ul>
        ${resp.length ? `<ul class="list">${resp.map(s => `<li class="card static"><span class="use-ic" aria-hidden="true">${s.kind === 'no_document' ? '🚫' : '💬'}</span>
            <div class="body"><div class="l1"><span>${esc(RQ.kinds[s.kind])} — ${esc(s.who)}</span><span class="t">${esc(fmtDate(s.created_at))}</span></div>${s.text ? `<div class="l2 wrap" dir="auto">${esc(s.text)}</div>` : ''}</div></li>`).join('')}</ul>` : ''}`;
}
