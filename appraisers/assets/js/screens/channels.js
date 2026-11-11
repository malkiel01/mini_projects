/*
 * ערוצים, תור השיוך ו"קשר ותזכורות" (אפיון 08 §8): חיבורי המשרד (#/office/channels), חיבורי המערכת והקצאתם (#/system/channels),
 * תור השיוך (#/inbox), "לא מזוהה" למנהל המערכת (#/system/inbox), והלשונית "תהליך ותזכורות" בעורך הפרופיל.
 * סוד של חיבור לעולם אינו מגיע לכאן: השרת מחזיר רק "מוגדר" ו-4 תווים אחרונים. כל תוכן של הודעה נכנסת (שולח, נושא, גוף,
 * שמות קבצים) מוצג דרך esc().
 */
'use strict';

const CH = T.ch;
const chStatus = c => `<span class="chip ${({ active: 'ok', needs_auth: 'warn', disabled: '', failed: 'bad' })[c.status] || ''}">${esc(CH.statuses[c.status] || c.status)}</span>`;
const chHookUrl = c => location.origin + location.pathname.replace(/[^/]*$/, '') + 'api.php?action=inbound-' + c.provider + '&c=' + c.pid;

const chGenBtn = `<div class="row-btns"><button type="button" class="btn ghost sm" data-gen>🎲 ${CH.genSecret}</button></div>`;
/** "ייצור סוד": 48 תווים אקראיים מהדפדפן (crypto.getRandomValues), מוצגים בשדה כדי שאפשר יהיה להעתיק — עד השמירה בלבד. */
function chGenBind(dlg) {
    $('[data-gen]', dlg)?.addEventListener('click', () => {
        const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ' + 'abcdefghijkmnopqrstuvwxyz' + '23456789', buf = crypto.getRandomValues(new Uint8Array(96));
        let out = '';
        for (const b of buf) { if (b < 228 && out.length < 48) out += abc[b % abc.length]; }
        const el = dlg.querySelector('[name=secret]');
        el.type = 'text'; el.dir = 'ltr'; el.value = out; el.select();
        toast(CH.genHint);
    });
}

function chConnCard(c, manage) {
    return `<li class="card${manage ? '' : ' static'}" ${manage ? `tabindex="0" role="button" data-conn="${c.pid}"` : ''}><span class="use-ic" aria-hidden="true">🔌</span>
        <div class="body"><div class="l1"><span>${esc(c.label)}</span></div>
        <div class="l2 wrap">${esc(CH.channels[c.channel] || c.channel)} · ${esc(CH.scope[c.scope])} · ${c.capabilities.inbound ? CH.inboundOnly : CH.sendOnly}</div>
        <div class="l2 wrap">${c.builtin ? '' : esc(c.secret.set ? CH.secretSet(c.secret.tail || '') : CH.secretNone) + ' · '}${c.last_ok_at ? esc(CH.lastOk(fmtDateTime(c.last_ok_at))) : ''}${
            c.last_error ? ` · ${CH.lastError}: <span dir="ltr">${esc(c.last_error)}</span>` : ''}</div></div>${chStatus(c)}</li>`;
}

function chCatalogHtml(cat) {
    return `<h2 class="sec-title">${CH.catalog}</h2><ul class="list">${cat.channels.map(k => `<li class="card static"><div class="body"><div class="l1"><span>${esc(CH.channels[k.channel])}</span></div></div>
        <span class="chip ${k.state === 'available' ? 'ok' : ''}">${esc(CH.states[k.state])}</span></li>`).join('')}</ul>`;
}

/** גיליון של חיבור: בדיקה, הפעלה / השבתה, ועריכה (שם וסוד). $sys — מסלול המערכת. */
function chConnSheet(c, d, sys, reload) {
    const pre = sys ? 'sys-channel-' : 'office-channel-';
    const dlg = sheet(c.label, `<div class="summary"><div class="line"><b class="one">${esc(CH.channels[c.channel])} · ${esc(CH.scope[c.scope])}</b>${chStatus(c)}</div>
          <div class="line muted">${c.builtin ? '' : esc(c.secret.set ? CH.secretSet(c.secret.tail || '') : CH.secretNone)}</div></div>
        ${c.capabilities.inbound ? `<label class="f"><span>${CH.hookUrl}</span><input readonly dir="ltr" value="${esc(chHookUrl(c))}"></label><p class="hint">${CH.hookHint}</p>` : ''}
        ${c.builtin ? '' : `<label class="f"><span>${CH.label}</span><input name="label" required maxlength="80" value="${esc(c.label)}"></label>
          <label class="f"><span>${CH.secret}</span><input name="secret" type="password" autocomplete="off" maxlength="200" placeholder="${CH.secretKeep}"></label>${chGenBtn}<p class="hint">${CH.secretHint}</p>`}
        ${sys ? `<label class="switch"><input type="checkbox" id="chall"${c.all_offices ? ' checked' : ''}><span>${CH.allOffices}</span></label>
          <fieldset class="f"${c.all_offices ? ' hidden' : ''} id="chgrants"><legend>${CH.grants}</legend>${d.offices.map(o => `<label class="switch"><input type="checkbox" data-grant="${o.pid}"${c.offices.includes(o.pid) ? ' checked' : ''}><span>${esc(o.name)}</span></label>`).join('')}</fieldset>` : ''}
        <div class="row-btns wrap"><button type="button" class="btn ghost" id="chtest">${CH.test}</button>
          <button type="button" class="btn ghost${c.status === 'active' ? ' danger' : ''}" id="chtoggle">${c.status === 'active' ? CH.disable : CH.enable}</button></div>`,
        c.builtin ? null : async f => {
            const field = (d.catalog.providers.find(p => p.provider === c.provider) || { fields: [] }).fields[0];
            await api(pre + 'save', { body: { connection: c.pid, label: f.label, config: f.secret && field ? { [field]: f.secret } : {} } });
            toast(CH.saved); reload();
        });
    chGenBind(dlg);
    const act = (sel, fn) => $(sel, dlg)?.addEventListener('click', async () => { try { await fn(); } catch (e) { toastError(e); } });
    act('#chtest', async () => { const r = await api(pre + 'test', { body: { connection: c.pid } }); toast(r.health.text, r.health.state !== 'ok'); });
    act('#chtoggle', async () => { await api(pre + 'status', { body: { connection: c.pid, status: c.status === 'active' ? 'disabled' : 'active' } }); dlg.close(); toast(T.saved); reload(); });
    $('#chall', dlg)?.addEventListener('change', async e => {
        try { await api('sys-channel-grant', { body: { connection: c.pid, all_offices: e.target.checked } }); $('#chgrants', dlg).hidden = e.target.checked; toast(T.saved); } catch (x) { toastError(x); }
    });
    $$('[data-grant]', dlg).forEach(b => b.addEventListener('change', async () => {
        try { await api('sys-channel-grant', { body: { connection: c.pid, office: b.dataset.grant, on: b.checked } }); toast(T.saved); } catch (x) { b.checked = !b.checked; toastError(x); }
    }));
}

function chAddSheet(d, sys, reload) {
    const scope = sys ? 'system' : 'office';
    const list = d.catalog.providers.filter(p => p.scopes.includes(scope));
    sheet(CH.add, `<label class="f"><span>${CH.provider}</span><select name="provider">${list.map(p => `<option value="${esc(p.provider)}">${esc(p.label)}</option>`).join('')}</select></label>
        <label class="f"><span>${CH.label}</span><input name="label" required maxlength="80"></label>
        <label class="f"><span>${CH.secret}</span><input name="secret" type="password" autocomplete="off" maxlength="200" required minlength="32"></label>${chGenBtn}<p class="hint">${CH.secretHint}</p>`, async f => {
        const p = list.find(x => x.provider === f.provider);
        await api(sys ? 'sys-channel-save' : 'office-channel-save', { body: { provider: f.provider, label: f.label, config: { [p.fields[0]]: f.secret } } });
        toast(CH.saved); reload();
    });
    chGenBind($('#sheet'));
}

/** #/office/channels — חיבורי המשרד, החיבורים שהמערכת התירה לו, ומי מטפל בתור. */
async function viewOfficeChannels(tabs) {
    const d = await api('office-channels');
    const reload = () => viewOfficeChannels(tabs);
    App.main.innerHTML = `${tabs}<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${CH.title}</p><button class="btn primary" id="chadd"><span aria-hidden="true">+</span> ${CH.add}</button></div>
        ${d.connections.length ? `<ul class="list">${d.connections.map(c => chConnCard(c, c.scope === 'office')).join('')}</ul>` : `<p class="empty">${CH.none}</p>`}
        ${chCatalogHtml(d.catalog)}
        <h2 class="sec-title">📥 ${CH.handlersTitle}</h2><p class="hint">${CH.handlersHint}</p>
        ${d.members.filter(m => !m.is_admin).map(m => `<label class="switch"><input type="checkbox" data-handler="${m.pid}"${m.inbox_handler ? ' checked' : ''}><span>${esc(m.name)}</span></label>`).join('')}`;
    $('#chadd').addEventListener('click', () => chAddSheet(d, false, reload));
    cOn('[data-conn]', el => chConnSheet(d.connections.find(c => c.pid === el.dataset.conn), d, false, reload));
    $$('[data-handler]').forEach(b => b.addEventListener('change', async () => {
        try { await api('office-inbox-handler', { body: { user: b.dataset.handler, on: b.checked } }); toast(T.saved); } catch (x) { b.checked = !b.checked; toastError(x); }
    }));
}

/** #/system/channels — הקטלוג, חיבורי המערכת וההקצאה למשרדים. */
async function viewSysChannels(tabs) {
    const d = await api('sys-channels');
    const reload = () => viewSysChannels(tabs);
    App.main.innerHTML = `${tabs}<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${CH.sysTitle}</p><button class="btn primary" id="chadd"><span aria-hidden="true">+</span> ${CH.add}</button></div>
        <ul class="list">${d.connections.map(c => chConnCard(c, true)).join('')}</ul>${chCatalogHtml(d.catalog)}`;
    $('#chadd').addEventListener('click', () => chAddSheet(d, true, reload));
    cOn('[data-conn]', el => chConnSheet(d.connections.find(c => c.pid === el.dataset.conn), d, true, reload));
}

/** #/system/inbox — "לא מזוהה": ערוץ, זמן ושולח מקוצר בלבד. */
async function viewSysInbox(tabs) {
    const d = await api('sys-inbox');
    const reload = () => viewSysInbox(tabs);
    App.main.innerHTML = `${tabs}<p class="hint">${CH.sysInboxHint}</p>${d.items.length ? `<ul class="list">${d.items.map(i => `<li class="card static"><span class="use-ic" aria-hidden="true">📥</span>
        <div class="body"><div class="l1"><span dir="ltr">${esc(i.from)}</span><span class="t">${esc(fmtDateTime(i.received_at))}</span></div>
        <div class="l2 wrap">${esc(CH.channels[i.channel] || i.channel)} · ${esc(i.connection)} · ${esc(CH.why[i.why] || '')}</div>
        <div class="row-btns wrap">${i.offices.map(o => `<button type="button" class="btn ghost sm" data-route="${i.pid}" data-office="${o.pid}">${CH.route}${esc(o.name)}</button>`).join('')}
          ${i.can_reply ? `<button type="button" class="btn ghost sm" data-reply="${i.pid}">${CH.reply}</button>` : ''}<button type="button" class="btn ghost sm danger" data-drop="${i.pid}">${CH.dismiss}</button></div></div></li>`).join('')}</ul>`
        : `<p class="empty">${CH.sysInboxEmpty}</p>`}`;
    const act = (sel, fn) => $$(sel).forEach(b => b.addEventListener('click', async () => { try { await fn(b); reload(); } catch (e) { toastError(e); } }));
    act('[data-route]', async b => { await api('sys-inbox-route', { body: { item: b.dataset.route, office: b.dataset.office } }); toast(CH.routed); });
    act('[data-reply]', async b => { await api('sys-inbox-reply', { body: { item: b.dataset.reply } }); toast(CH.replied); });
    act('[data-drop]', async b => { const reason = prompt(CH.dismissReason); if (!reason) return; await api('sys-inbox-dismiss', { body: { item: b.dataset.drop, reason } }); toast(CH.dismissed); });
}

/** #/inbox — תור השיוך של המשרד. שום דבר אינו נכנס לתיק לפני שמשייכים אותו כאן. */
async function viewInbox() {
    setHead(CH.inboxTitle, null);
    const [d, cs] = await Promise.all([api('inbox'), api('cases')]);
    const open = cs.cases.filter(c => c.status !== 'closed');
    App.main.innerHTML = `<p class="hint">${CH.inboxHint}</p>${d.items.length ? `<ul class="list">${d.items.map(i => `<li class="card" tabindex="0" role="button" data-item="${i.pid}"><span class="use-ic" aria-hidden="true">📥</span>
        <div class="body"><div class="l1"><span dir="auto">${esc(i.from)}</span><span class="t">${esc(fmtDateTime(i.received_at))}</span></div>
        ${i.subject ? `<div class="l2 wrap" dir="auto">${esc(i.subject)}</div>` : ''}
        <div class="chips"><span class="chip">${esc(CH.channels[i.channel] || i.channel)}</span><span class="chip warn">${esc(CH.why[i.why] || '')}</span>${i.files.length ? `<span class="chip info">📎 ${i.files.length}</span>` : ''}</div></div></li>`).join('')}</ul>`
        : `<p class="empty">${CH.inboxEmpty}</p>`}`;
    cOn('[data-item]', el => inboxSheet(d.items.find(i => i.pid === el.dataset.item), open));
}

function inboxSheet(i, open) {
    const cand = i.candidates.map(c => c.pid);
    const cases = [...i.candidates.map(c => [c.pid, `${c.title || T.c.noTitle} · ${c.number}`]), ...open.filter(c => !cand.includes(c.pid)).map(c => [c.pid, `${c.title || T.c.noTitle} · ${c.number}`])];
    const dlg = sheet(CH.assign, `<div class="summary"><div class="line"><b class="one" dir="auto">${esc(i.from)}</b></div>
          <div class="line muted">${esc(fmtDateTime(i.received_at))} · ${esc(CH.channels[i.channel] || i.channel)} · ${esc(i.connection)} · ${esc(CH.why[i.why] || '')}</div></div>
        ${i.subject ? `<p class="sec-title" dir="auto">${esc(i.subject)}</p>` : ''}
        ${i.text ? `<div class="panel"><p style="white-space:pre-wrap;margin:0" dir="auto">${esc(i.text)}</p></div>` : ''}
        ${i.files.length ? `<ul class="list">${i.files.map(f => `<li class="card static"><span class="use-ic" aria-hidden="true">${f.rejected ? '🚫' : '📎'}</span><div class="body"><div class="l1"><span dir="auto">${esc(f.name || '')}</span></div>
            <div class="l2 wrap">${f.rejected ? esc(CH.fileRejected + ': ' + (CH.rejects[f.rejected] || CH.rejects.other)) : dSize(f.size)}</div></div></li>`).join('')}</ul>` : ''}
        ${cases.length ? `<label class="f"><span>${CH.toCase}${cand.length ? ` · ${CH.candidates}: ${cand.length}` : ''}</span><select name="case">${cases.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</select></label>
          <label class="f"><span>${CH.toReq}</span><select name="requirement"><option value="">${CH.noReq}</option></select></label>` : `<p class="empty">${CH.noCases}</p>`}
        <div class="row-btns"><button type="button" class="btn ghost danger" id="indrop">${CH.dismiss}</button></div>`, cases.length ? async f => {
        await api('inbox-assign', { body: { item: i.pid, case: f.case, ...(f.requirement ? { requirement: f.requirement } : {}) } });
        toast(CH.assigned); viewInbox();
    } : null, CH.assign);
    const sel = dlg.querySelector('[name=case]'), rq = dlg.querySelector('[name=requirement]');
    const loadReqs = async () => {
        rq.length = 1;
        try {
            const x = await api('case-docs', { params: { case: sel.value } });
            for (const r of x.requirements.filter(r => !r.split && !['approved', 'not_relevant'].includes(r.status))) rq.add(new Option(r.title, r.pid));
        } catch (e) { /* אין הרשאה למסמכי התיק — השיוך עצמו יידחה בשרת */ }
    };
    if (sel) { sel.addEventListener('change', loadReqs); loadReqs(); }
    $('#indrop', dlg).addEventListener('click', async () => {
        const reason = prompt(CH.dismissReason);
        if (!reason) return;
        try { await api('inbox-dismiss', { body: { item: i.pid, reason } }); dlg.close(); toast(CH.dismissed); viewInbox(); } catch (e) { toastError(e); }
    });
}

/** ללוח הראשי: "לא ידוע אם נשלחו" ו"ממתינות לשיוך". */
function contactBoardHtml(rf) {
    const unknown = rf.unknown || [];
    return `${unknown.length ? `<h2 class="sec-title">❔ ${CH.unknownTitle} <small>${unknown.length}</small></h2>
        <ul class="list">${unknown.map(r => `<li class="card" tabindex="0" role="button" data-go="#/cases/${r.case.pid}/parties"><span class="use-ic" aria-hidden="true">❔</span>
            <div class="body"><div class="l1"><span>${esc(r.party_name)}</span><span class="t">${esc(fmtDate(r.failed_at))}</span></div>
            <div class="l2">${esc(r.case.title || T.c.noTitle)} · <span dir="ltr">${esc(r.case.number)}</span></div></div><span class="chip warn">${T.rq.msg.unknown}</span></li>`).join('')}</ul><p class="hint">${CH.unknownHint}</p>` : ''}
        ${rf.inbox ? `<h2 class="sec-title">📥 ${CH.inboxTitle} <small>${rf.inbox}</small></h2><p class="hint"><a href="#/inbox">${CH.inboxTile}</a></p>` : ''}`;
}

/** בגיליון של בקשה: ציר הזמן — נשלחה · תזכורת 1 · דולגה (ולמה) · נמסרה · נענתה — ומצב התזכורות. */
function rqTimelineHtml(q) {
    const what = m => m.kind === 'reminder' ? CH.reminderN(m.reminder_no || '') : (CH.kinds[m.kind] || m.kind);
    return `<ul class="list">${q.messages.map(m => `<li class="card static"><span class="use-ic" aria-hidden="true">${m.direction === 'in' ? '📥' : m.kind === 'reminder' ? '⏰' : '📨'}</span>
        <div class="body"><div class="l1"><span>${esc(what(m))}</span><span class="t">${esc(fmtDateTime(m.sent_at || m.created_at))}</span></div>
        <div class="l2 wrap">${esc(m.by || T.c.system)}${m.status === 'skipped' ? ' · ' + esc(CH.skip[m.skip_reason] || m.skip_reason || '') : ''}${m.status === 'sent' && !m.delivery && m.channel !== 'link_copy' ? ' · ' + CH.noDelivery : ''}</div>
        ${m.direction === 'in' && m.text ? `<div class="l2 wrap" dir="auto">${esc(m.text)}</div>` : ''}
        ${m.status === 'unknown' && m.channel !== 'link_copy' ? `<div class="row-btns"><button type="button" class="btn ghost sm" data-check="${m.pid}">${CH.check}</button></div>` : ''}</div>${rqMsgChip(m)}</li>`).join('')}</ul>
        ${q.reminders ? `<p class="hint">⏰ ${esc(q.reminders.state === 'active' ? CH.planActive(q.reminders.sent, q.reminders.next_at ? fmtDateTime(q.reminders.next_at) : '') : CH.planStopped(CH.skip[q.reminders.stopped_reason] || q.reminders.stopped_reason || ''))}</p>` : ''}`;
}

function rqTimelineBind(q, x, reload, dlg) {
    $$('[data-check]', dlg).forEach(b => b.addEventListener('click', async () => {
        try { const r = await api('message-check', { body: { message: b.dataset.check } }); toast(CH.checked(T.rq.msg[r.status] || r.status)); reload(); } catch (e) { toastError(e); }
    }));
    $('#rqstop', dlg)?.addEventListener('click', async () => {
        if (!confirm(CH.stopAsk)) return;
        try { await api('reminders-stop', { body: { request: q.pid } }); dlg.close(); toast(CH.stopped); reload(); } catch (e) { toastError(e); }
    });
}

// ── עורך הפרופיל: "תהליך ותזכורות" (אפיון 08 §4) ──
function peContact(body) {
    const k = PE.content.contact, r = k.reminders;
    const tpl = (name, title) => `<section class="group"><h3>${title}</h3>
        <label class="f"><span>${CH.subject}</span><input data-tpl="${name}.subject" maxlength="150" value="${esc(k.templates[name].subject)}"></label>
        <label class="f"><span>${CH.body}</span><textarea data-tpl="${name}.body" rows="7" maxlength="4000">${esc(k.templates[name].body)}</textarea></label>
        <p class="sec-title">${CH.preview}</p><div class="panel"><b data-pv="${name}.subject" dir="auto"></b><p style="white-space:pre-wrap;margin:.4em 0 0" dir="auto" data-pv="${name}.body"></p></div></section>`;
    body.innerHTML = `<form class="panel" id="pk" novalidate>
        <label class="f"><span>${CH.sendMode}</span><select name="send_mode">${Object.keys(CH.sendModes).map(m => opt(m, CH.sendModes[m], k.send_mode)).join('')}</select></label><p class="hint">${CH.autoHint}</p>
        <p class="hint">${CH.vars}: <span dir="rtl">${Object.keys(CH.sample).map(v => `<code>{${esc(v)}}</code>`).join(' ')}</span>. ${CH.varsHint}</p>
        ${tpl('request', CH.tplRequest)}${tpl('reminder', CH.tplReminder)}
        <section class="group"><h3>⏰ ${CH.remTitle}</h3>
          <label class="switch"><input type="checkbox" name="enabled"${r.enabled ? ' checked' : ''}><span>${CH.remOn}</span></label>
          <div class="fields two"><label class="f"><span>${CH.afterDays}</span><input name="after_days" inputmode="numeric" value="${esc(r.after_days.join(', '))}"></label>
            <label class="f"><span>${CH.max}</span><input name="max" type="number" inputmode="numeric" min="0" max="10" value="${r.max}"></label></div>
          <div class="f"><span>${CH.days}</span><div class="chips">${Object.keys(CH.weekdays).filter(d => d !== 'sat').map(d => `<label class="chip pick"><input type="checkbox" name="day" value="${d}"${r.days.includes(d) ? ' checked' : ''}> ${CH.weekdays[d]}</label>`).join('')}</div></div>
          <div class="fields two"><label class="f"><span>${CH.from}</span><input name="from" type="time" value="${esc(r.hours.from)}"></label><label class="f"><span>${CH.to}</span><input name="to" type="time" value="${esc(r.hours.to)}"></label></div>
          <label class="switch"><input type="checkbox" name="answered"${r.stop_when.includes('recipient_answered') ? ' checked' : ''}><span>${CH.stopAnswered}</span></label>
          <p class="hint">${CH.remHint}</p></section></form>`;
    const form = $('#pk');
    // תצוגה מקדימה על נתוני דוגמה — הצבה של מחרוזות בלבד, ורק למשתנים שברשימה
    const fill = s => s.replace(/\{([^{}\r\n]*)\}/g, (m, v) => Object.prototype.hasOwnProperty.call(CH.sample, v) ? CH.sample[v] : m);
    const preview = () => $$('[data-pv]', form).forEach(el => { const [t, f] = el.dataset.pv.split('.'); el.textContent = fill(k.templates[t][f]); });
    const read = () => {
        const f = new FormData(form);
        k.send_mode = f.get('send_mode');
        $$('[data-tpl]', form).forEach(el => { const [t, x] = el.dataset.tpl.split('.'); k.templates[t][x] = el.value; });
        r.enabled = f.get('enabled') === 'on';
        r.after_days = [...new Set(String(f.get('after_days')).split(/[,\s]+/).map(n => parseInt(n, 10)).filter(n => n >= 1 && n <= 365))].sort((a, b) => a - b).slice(0, 10);
        r.max = Math.max(0, Math.min(10, parseInt(f.get('max'), 10) || 0));
        r.days = f.getAll('day');
        if (/^\d\d:\d\d$/.test(f.get('from'))) r.hours.from = f.get('from');
        if (/^\d\d:\d\d$/.test(f.get('to'))) r.hours.to = f.get('to');
        r.stop_when = ['requirement_closed', 'request_revoked', ...(f.get('answered') === 'on' ? ['recipient_answered'] : [])];
        preview();
        peChanged();
    };
    form.addEventListener('input', read);
    form.addEventListener('change', read);
    form.addEventListener('submit', e => e.preventDefault());
    preview();
}
