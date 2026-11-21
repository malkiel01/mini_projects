/*
 * בינה (אפיון 11 §10): #/system/ai — ספקים, מחירון, חיבורי המערכת, הקצאות, מכסות ושימוש מצרפי (מנהל המערכת);
 * #/office/ai — מדיניות והדלקה, החיבור האישי, מכסות ושימוש (מנהל משרד); #/my/ai — "הבינה שלי"; והמתג "בלי בינה" בכרטיס התיק.
 *
 * **מפתח אינו מגיע לכאן לעולם:** השרת מחזיר רק את 4 התווים האחרונים. שדה המפתח הוא password, נשלח פעם אחת, ואינו נשמר בדפדפן.
 * המסך אינו מחשב כסף ואינו מכריע דבר — הוא מציג מה שהשרת שלח. כל סכום מסומן "משוער" או "לפי דיווח הספק"; לעולם לא "חיוב".
 * כל טקסט שמשתמש או מנהל הזין (שם חיבור, תיאור הספק, שם משרד) מוצג דרך esc().
 */
'use strict';

const AI = T.ai;
const aiMoney = a => '₪' + (Number(a || 0) / 100).toLocaleString('he-IL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const aiConnChip = c => `<span class="chip ${({ active: 'ok', invalid: 'bad', revoked: '' })[c.status] || ''}">${esc(AI.connStatus[c.status] || c.status)}</span>`;
const aiOpts = (rows, val, label, cur) => rows.map(r => `<option value="${esc(val(r))}"${val(r) === cur ? ' selected' : ''}>${esc(label(r))}</option>`).join('');
const aiAct = (sel, root, fn) => $$(sel, root).forEach(b => b.addEventListener('click', async () => { try { await fn(b); } catch (x) { if (x.code !== 'confirm_cancelled') toastError(x); } }));

/** שורת שימוש: בקשות, טוקנים, ושני הסכומים בנפרד — כל אחד עם התווית שלו. */
function aiUsageText(u) {
    const parts = [AI.runs(u.runs)];
    if (u.blocked) parts.push(AI.blockedN(u.blocked));
    if (u.tokens) parts.push(AI.tokens(u.tokens));
    if (u.cost_reported) parts.push(`${aiMoney(u.cost_reported)} (${AI.reported})`);
    if (u.cost_estimated) parts.push(`${aiMoney(u.cost_estimated)} (${AI.estimated})`);
    return parts.join(' · ');
}

/** מד של צד אחד במכסה (כסף או טוקנים). פס צהוב מ-80%, אדום בחריגה. */
function aiMeter(side, fmt, note = '') {
    if (!side) return '';
    const taken = side.used + side.reserved, pct = side.limit > 0 ? Math.min(100, Math.round(taken * 100 / side.limit)) : 100;
    return `<div class="ai-meter${side.over ? ' over' : side.warn ? ' warn' : ''}" role="img" aria-label="${pct}%"><i style="inline-size:${pct}%"></i></div>
        <div class="l2 wrap">${esc(AI.ofLimit(fmt(taken), fmt(side.limit)))} · ${esc(AI.left(fmt(side.left)))}${note ? ` (${esc(note)})` : ''}</div>`;
}

function aiQuotaCard(q, title, clickable, note = '') {
    return `<li class="card${clickable ? '' : ' static'}"${clickable ? ` tabindex="0" role="button" data-quota="${esc(q.pid)}"` : ''}><span class="use-ic" aria-hidden="true">📏</span>
        <div class="body"><div class="l1"><span>${esc(title)}</span></div>
        <div class="l2 wrap">${esc(AI.periods[q.period])} · ${esc(AI.trackShort[q.track])}${q.resets_at ? ' · ' + esc(AI.resets(fmtDate(q.resets_at))) : ' · ' + AI.noReset}${note ? ' · ' + esc(note) : ''}</div>
        ${aiMeter(q.money, aiMoney, AI.estimated)}${aiMeter(q.tokens, v => AI.tokens(v))}</div>
        <span class="chip ${q.hard ? '' : 'warn'}">${q.hard ? '🔒' : esc(AI.soft)}</span></li>`;
}
const aiQuotaTitle = q => `${AI.scopes[q.scope]}: ${q.target_name || ''}${q.scope === 'user' && q.office_name ? ` (${q.office_name})` : ''}`;

/** גיליון של מכסה קיימת: עדכון התקרות, או מחיקה. pre — 'sys-ai-' או 'office-ai-'. */
function aiQuotaSheet(q, pre, reload) {
    const dlg = sheet(aiQuotaTitle(q), `<p class="hint">${esc(AI.periods[q.period])} · ${esc(AI.tracks[q.track])}</p>
        <label class="f"><span>${AI.limitMoney}</span><input name="limit_money" inputmode="decimal" dir="ltr" value="${q.money ? (q.money.limit / 100).toFixed(2) : ''}"></label>
        <label class="f"><span>${AI.limitTokens}</span><input name="limit_tokens" inputmode="numeric" dir="ltr" value="${q.tokens ? q.tokens.limit : ''}"></label>
        <label class="switch"><input type="checkbox" name="hard"${q.hard ? ' checked' : ''}><span>${AI.hard}</span></label>
        <p class="hint">${AI.estNote}</p>
        <div class="row-btns"><button type="button" class="btn ghost danger" id="aiqdel">${AI.deleteQuota}</button></div>`, async f => {
        await api(pre + 'quota-save', { body: { quota: q.pid, limit_money: f.limit_money.trim() || null, limit_tokens: f.limit_tokens.trim() ? Number(f.limit_tokens) : null, hard: !!f.hard } });
        toast(T.saved); reload();
    });
    aiAct('#aiqdel', dlg, async () => { if (!confirm(AI.deleteQuotaAsk)) return; await api(pre + 'quota-delete', { body: { quota: q.pid } }); dlg.close(); toast(T.saved); reload(); });
}

/** גיליון של חיבור קיים: שם, מודל ברירת מחדל (באישי), החלפת מפתח, בדיקה וביטול. */
function aiConnSheet(c, pre, models, reload) {
    const personal = c.track === 'personal', revoked = c.status === 'revoked';
    const dlg = sheet(c.label, `<div class="summary"><div class="line"><b class="one">${esc(c.provider)}</b>${aiConnChip(c)}</div>
          <div class="line muted">${c.key_hint ? esc(AI.keyHint(c.key_hint)) : ''}${c.last_ok_at ? ' · ' + esc(AI.lastOk(fmtDateTime(c.last_ok_at))) : ''}${c.last_error ? ` · ${AI.lastError}: <span dir="ltr">${esc(c.last_error)}</span>` : ''}</div></div>
        ${revoked ? '' : `<label class="f"><span>${AI.connLabel}</span><input name="label" required maxlength="80" value="${esc(c.label)}"></label>
        ${personal ? `<label class="f"><span>${AI.defaultModel}</span><select name="default_model"><option value="">${AI.firstAllowed}</option>${aiOpts(models.filter(m => m.provider === c.provider), m => m.model_id, m => m.label, c.default_model)}</select></label>` : ''}
        <label class="f"><span>${AI.keyReplace}</span><input name="key" type="password" autocomplete="off" dir="ltr" maxlength="300"></label><p class="hint">${AI.keyOnce}</p>
        <div class="row-btns wrap"><button type="button" class="btn ghost" id="aictest">${AI.test}</button><button type="button" class="btn ghost danger" id="aicrev">${AI.revoke}</button></div>`}`,
        revoked ? null : async f => {
            await api(pre + 'connection-save', { body: { connection: c.pid, label: f.label, key: f.key || null, default_model: personal ? f.default_model || null : c.default_model } });
            toast(T.saved); reload();
        });
    aiAct('#aictest', dlg, async () => { const r = await api(pre + 'connection-test', { body: { connection: c.pid } }); toast(AI.testResult[r.result] || r.result, r.result !== 'ok'); reload(); });
    aiAct('#aicrev', dlg, async () => { if (!confirm(AI.revokeAsk)) return; await api(pre + 'connection-revoke', { body: { connection: c.pid } }); dlg.close(); toast(T.saved); reload(); });
}

function aiConnAddSheet(pre, providers, reload) {
    sheet(AI.addConn, `<label class="f"><span>${AI.provider}</span><select name="provider">${aiOpts(providers, p => p.key, p => p.name)}</select></label>
        <label class="f"><span>${AI.connLabel}</span><input name="label" required maxlength="80"></label>
        <label class="f"><span>${AI.key}</span><input name="key" type="password" autocomplete="off" dir="ltr" required minlength="20" maxlength="300"></label><p class="hint">${AI.keyOnce}</p>`, async f => {
        await api(pre + 'connection-save', { body: { provider: f.provider, label: f.label, key: f.key } });
        toast(T.saved); reload();
    });
}

const aiConnCard = c => `<li class="card" tabindex="0" role="button" data-aiconn="${esc(c.pid)}"><span class="use-ic" aria-hidden="true">🔑</span>
    <div class="body"><div class="l1"><span>${esc(c.label)}</span></div>
    <div class="l2 wrap">${esc(c.provider)}${c.key_hint ? ' · ' + esc(AI.keyHint(c.key_hint)) : ''}${c.last_ok_at ? ' · ' + esc(AI.lastOk(fmtDateTime(c.last_ok_at))) : ''}</div></div>${aiConnChip(c)}</li>`;

const aiBlocksHtml = rows => rows.length ? `<ul class="list">${rows.map(b => `<li class="card static"><span class="use-ic" aria-hidden="true">⛔</span><div class="body">
    <div class="l1"><span>${esc(AI.reasons[b.reason] || b.reason)}</span><span class="t">${esc(fmtDateTime(b.at || b.last_at))}</span></div>
    <div class="l2 wrap">${esc([b.office_name, b.user_name, b.model, b.count ? AI.runs(b.count) : ''].filter(Boolean).join(' · '))}</div></div></li>`).join('')}</ul>` : `<p class="empty">${AI.noBlocks}</p>`;

// ── #/system/ai ──────────────────────────────────────────────

async function viewSysAi(tabs) {
    const [d, offices, users] = await Promise.all([api('sys-ai'), api('sys-offices'), api('sys-users')]);
    const reload = () => viewSysAi(tabs);
    const al = d.alerts, alerts = [al.invalid_connections ? AI.alertInvalid(al.invalid_connections) : '', al.over.length ? AI.alertOver(al.over.length) : ''].filter(Boolean);
    const add = (id, label) => `<button class="btn ghost sm" type="button" id="${id}"><span aria-hidden="true">+</span> ${label}</button>`;
    App.main.innerHTML = `${tabs}<p class="hint">${AI.sysHint}</p>
        ${alerts.map(a => `<p class="banner" role="note">⚠️ ${esc(a)}</p>`).join('')}
        ${al.requests.length ? `<h2 class="sec-title">🙋 ${AI.alertRequests}</h2><ul class="list">${al.requests.map(r => `<li class="card static"><div class="body"><div class="l1"><span>${esc(r.user_name || '')}</span><span class="t">${esc(fmtDateTime(r.at))}</span></div>
            <div class="l2 wrap">${esc(r.office_name || '')}</div></div></li>`).join('')}</ul>` : ''}
        <h2 class="sec-title">🏢 ${AI.providers}</h2><ul class="list">${d.providers.map(p => `<li class="card" tabindex="0" role="button" data-provider="${esc(p.key)}"><div class="body"><div class="l1"><span>${esc(p.name)}</span></div>
            <div class="l2 wrap">${p.privacy_note ? esc(p.privacy_note) : esc(AI.noPrivacyNote)}${p.privacy_checked_at ? ' · ' + esc(AI.checked(fmtDate(p.privacy_checked_at))) : ''}</div></div>
            <span class="chip ${p.status === 'active' ? 'ok' : ''}">${p.status === 'active' ? AI.statusActive : AI.statusHidden}</span></li>`).join('')}</ul>
        <h2 class="sec-title">🧠 ${AI.models} ${add('aimadd', AI.addModel)}</h2><ul class="list">${d.models.map((m, i) => `<li class="card" tabindex="0" role="button" data-model="${i}"><div class="body"><div class="l1"><span>${esc(m.label)}</span></div>
            <div class="l2 wrap"><span dir="ltr">${esc(m.model_id)}</span> · ${m.priced ? esc(AI.priceLine(m.price_in, m.price_out, fmtDate(m.price_checked_at))) : `<span class="chip warn">${AI.noPrice}</span>`}</div></div>
            <span class="chip ${m.status === 'active' ? 'ok' : ''}">${m.status === 'active' ? AI.statusActive : AI.statusHidden}</span></li>`).join('')}</ul>
        <h2 class="sec-title">💱 ${AI.fx}</h2><ul class="list"><li class="card" tabindex="0" role="button" id="aifx"><div class="body"><div class="l1"><span>${d.fx ? esc(AI.fxLine(d.fx.rate, fmtDate(d.fx.set_at))) : esc(AI.fxNone)}</span></div></div></li></ul>
        <h2 class="sec-title">🔑 ${AI.connections} ${add('aicadd', AI.addConn)}</h2>${d.connections.length ? `<ul class="list">${d.connections.map(aiConnCard).join('')}</ul>` : `<p class="empty">${AI.noConns}</p>`}
        <h2 class="sec-title">🎫 ${AI.assignments} ${add('aiaadd', AI.addAssign)}</h2>${d.assignments.length ? `<ul class="list">${d.assignments.map((a, i) => `<li class="card" tabindex="0" role="button" data-assign="${i}"><div class="body">
            <div class="l1"><span>${esc(AI.scopes[a.scope])}: ${esc(a.target_name)}</span></div>
            <div class="l2 wrap">${esc((d.connections.find(c => c.pid === a.connection) || {}).label || '')} · ${a.models.length ? esc(a.models.join(', ')) : AI.allModels}</div></div></li>`).join('')}</ul>` : `<p class="empty">${AI.noAssign}</p>`}
        <h2 class="sec-title">📏 ${AI.quotas} ${add('aiqadd', AI.addQuota)}</h2><p class="hint">${AI.managedNeedsQuota} ${AI.estNote}</p>
        ${d.quotas.length ? `<ul class="list">${d.quotas.map(q => aiQuotaCard(q, aiQuotaTitle(q), true)).join('')}</ul>` : `<p class="empty">${AI.noQuotas}</p>`}
        <h2 class="sec-title">📊 ${AI.usage}</h2><p class="hint">${AI.usageSysHint}</p>
        ${d.usage.length ? `<ul class="list">${d.usage.map(u => `<li class="card static"><div class="body"><div class="l1"><span>${esc(u.office_name)} · ${esc(u.user_name || '—')}</span><span class="t" dir="ltr">${esc(u.day)}</span></div>
            <div class="l2 wrap">${esc(u.model || '—')}${u.track ? ' · ' + esc(AI.trackShort[u.track]) : ''} · ${esc(aiUsageText(u))}</div></div></li>`).join('')}</ul>` : `<p class="empty">${AI.noUsage}</p>`}
        <h2 class="sec-title">⛔ ${AI.blocks}</h2>${aiBlocksHtml(d.blocks)}`;

    cOn('[data-provider]', el => {
        const p = d.providers.find(x => x.key === el.dataset.provider);
        sheet(p.name, `<label class="f"><span>${AI.privacyNote}</span><textarea name="privacy_note" rows="4" maxlength="2000">${esc(p.privacy_note || '')}</textarea></label>
            <label class="f"><span>${AI.privacyUrl}</span><input name="privacy_url" dir="ltr" inputmode="url" maxlength="500" value="${esc(p.privacy_url || '')}"></label>
            <label class="f"><span>${AI.checkedAt}</span><input name="privacy_checked_at" type="date" value="${esc(p.privacy_checked_at || '')}"></label>
            <label class="switch"><input type="checkbox" name="material"><span>${AI.material}</span></label>
            <label class="switch"><input type="checkbox" name="active"${p.status === 'active' ? ' checked' : ''}><span>${AI.statusActive}</span></label>`, async f => {
            await api('sys-ai-provider-save', { body: { provider: p.key, privacy_note: f.privacy_note, privacy_url: f.privacy_url, privacy_checked_at: f.privacy_checked_at || null, material: !!f.material, status: f.active ? 'active' : 'hidden' } });
            toast(T.saved); reload();
        });
    });
    const modelSheet = m => sheet(m ? m.label : AI.addModel, `
        ${m ? '' : `<label class="f"><span>${AI.provider}</span><select name="provider">${aiOpts(d.providers, p => p.key, p => p.name)}</select></label>
          <label class="f"><span>${AI.modelId}</span><input name="model_id" dir="ltr" required maxlength="100" autocomplete="off"></label>`}
        <label class="f"><span>${AI.modelLabel}</span><input name="label" required maxlength="80" value="${esc(m ? m.label : '')}"></label>
        <label class="f"><span>${AI.priceIn}</span><input name="price_in" inputmode="decimal" dir="ltr" value="${esc(m ? m.price_in ?? '' : '')}"></label>
        <label class="f"><span>${AI.priceOut}</span><input name="price_out" inputmode="decimal" dir="ltr" value="${esc(m ? m.price_out ?? '' : '')}"></label>
        <label class="f"><span>${AI.checkedAt}</span><input name="price_checked_at" type="date" value="${esc(m ? m.price_checked_at || '' : '')}"></label>
        <label class="f"><span>${AI.ctxTokens}</span><input name="context_tokens" inputmode="numeric" dir="ltr" required value="${m ? m.context_tokens : ''}"></label>
        <label class="f"><span>${AI.maxOut}</span><input name="max_output_tokens" inputmode="numeric" dir="ltr" required value="${m ? m.max_output_tokens : ''}"></label>
        <label class="switch"><input type="checkbox" name="vision"${!m || m.capabilities.vision ? ' checked' : ''}><span>${AI.capVision}</span></label>
        <label class="switch"><input type="checkbox" name="pdf"${!m || m.capabilities.pdf ? ' checked' : ''}><span>${AI.capPdf}</span></label>
        <label class="switch"><input type="checkbox" name="json_output"${!m || m.capabilities.json_output ? ' checked' : ''}><span>${AI.capJson}</span></label>
        <label class="switch"><input type="checkbox" name="active"${!m || m.status === 'active' ? ' checked' : ''}><span>${AI.statusActive}</span></label>`, async f => {
        await api('sys-ai-model-save', { body: { provider: m ? m.provider : f.provider, model_id: m ? m.model_id : f.model_id.trim(), label: f.label, price_in: f.price_in.trim() || null, price_out: f.price_out.trim() || null,
            price_checked_at: f.price_checked_at || null, context_tokens: Number(f.context_tokens), max_output_tokens: Number(f.max_output_tokens),
            capabilities: { vision: !!f.vision, pdf: !!f.pdf, json_output: !!f.json_output }, status: f.active ? 'active' : 'hidden' } });
        toast(T.saved); reload();
    });
    cOn('[data-model]', el => modelSheet(d.models[Number(el.dataset.model)]));
    $('#aimadd').addEventListener('click', () => modelSheet(null));
    onActivate($('#aifx'), () => sheet(AI.fxSet, `<label class="f"><span>${AI.fxRate}</span><input name="rate" inputmode="decimal" dir="ltr" required value="${esc(d.fx ? d.fx.rate : '')}"></label>`, async f => {
        await api('sys-ai-fx-set', { body: { rate: f.rate.trim() } });
        toast(T.saved); reload();
    }));
    cOn('[data-aiconn]', el => aiConnSheet(d.connections.find(c => c.pid === el.dataset.aiconn), 'sys-ai-', d.models, reload));
    $('#aicadd').addEventListener('click', () => aiConnAddSheet('sys-ai-', d.providers.filter(p => p.status === 'active'), reload));

    const live = d.connections.filter(c => c.status !== 'revoked');
    const targetSelect = (name, scope, cur) => `<select name="${name}">${scope === 'user' ? aiOpts(users.users, u => u.pid, u => `${u.name || ''} (${u.email || ''})`, cur) : aiOpts(offices.offices, o => o.pid, o => o.name, cur)}</select>`;
    const assignSheet = a => {
        const dlg = sheet(a ? `${AI.scopes[a.scope]}: ${a.target_name}` : AI.addAssign, `
            ${a ? '' : `<fieldset class="seg" aria-label="${AI.assignTo}"><label><input type="radio" name="scope" value="office" checked><span>${AI.scopes.office}</span></label><label><input type="radio" name="scope" value="user"><span>${AI.scopes.user}</span></label></fieldset>
              <label class="f" data-for="office"><span>${AI.scopes.office}</span>${targetSelect('office_target', 'office')}</label><label class="f" data-for="user" hidden><span>${AI.scopes.user}</span>${targetSelect('user_target', 'user')}</label>`}
            <label class="f"><span>${AI.connection}</span><select name="connection">${aiOpts(live, c => c.pid, c => c.label, a?.connection)}</select></label>
            <fieldset class="f"><legend>${AI.modelsAllowed}</legend>${d.models.map(m => `<label class="switch"><input type="checkbox" data-m="${esc(m.model_id)}"${a && a.models.includes(m.model_id) ? ' checked' : ''}><span>${esc(m.label)}${m.priced ? '' : ' — ' + AI.noPrice}</span></label>`).join('')}</fieldset>
            <label class="f"><span>${AI.defaultModel}</span><select name="default_model"><option value="">${AI.firstAllowed}</option>${aiOpts(d.models, m => m.model_id, m => m.label, a?.default_model)}</select></label>
            ${a ? `<div class="row-btns"><button type="button" class="btn ghost danger" id="aiadel">${AI.removeAssign}</button></div>` : ''}`, async f => {
            const scope = a ? a.scope : f.scope;
            await api('sys-ai-assign-save', { body: { connection: f.connection, scope, target: a ? a.target : (scope === 'user' ? f.user_target : f.office_target),
                models: $$('[data-m]', dlg).filter(b => b.checked).map(b => b.dataset.m), default_model: f.default_model || null } });
            toast(T.saved); reload();
        });
        $$('[name=scope]', dlg).forEach(r => r.addEventListener('change', () => $$('[data-for]', dlg).forEach(l => { l.hidden = l.dataset.for !== r.value; })));
        aiAct('#aiadel', dlg, async () => { await api('sys-ai-assign-delete', { body: { scope: a.scope, target: a.target } }); dlg.close(); toast(T.saved); reload(); });
    };
    cOn('[data-assign]', el => assignSheet(d.assignments[Number(el.dataset.assign)]));
    $('#aiaadd').addEventListener('click', () => assignSheet(null));

    cOn('[data-quota]', el => aiQuotaSheet(d.quotas.find(q => q.pid === el.dataset.quota), 'sys-ai-', reload));
    $('#aiqadd').addEventListener('click', () => {
        const dlg = sheet(AI.addQuota, `<label class="f"><span>${AI.track}</span><select name="track">${aiOpts(Object.keys(AI.tracks), k => k, k => AI.tracks[k])}</select></label>
            <fieldset class="seg" aria-label="${AI.scope}"><label><input type="radio" name="scope" value="office" checked><span>${AI.scopes.office}</span></label><label><input type="radio" name="scope" value="user"><span>${AI.scopes.user}</span></label></fieldset>
            <label class="f" data-for="office"><span>${AI.scopes.office}</span>${targetSelect('office_target', 'office')}</label>
            <label class="f" data-for="user" hidden><span>${AI.scopes.user}</span>${targetSelect('user_target', 'user')}</label>
            <label class="f" data-for="user" hidden><span>${AI.inOffice}</span><select name="office"><option value="">${AI.allOffices}</option>${aiOpts(offices.offices, o => o.pid, o => o.name)}</select></label>
            <label class="f"><span>${AI.period}</span><select name="period">${aiOpts(Object.keys(AI.periods), k => k, k => AI.periods[k])}</select></label>
            <label class="f"><span>${AI.limitMoney}</span><input name="limit_money" inputmode="decimal" dir="ltr"></label>${d.fx ? '' : `<p class="hint">${AI.fxNone}</p>`}
            <label class="f"><span>${AI.limitTokens}</span><input name="limit_tokens" inputmode="numeric" dir="ltr"></label>
            <label class="switch"><input type="checkbox" name="hard" checked><span>${AI.hard}</span></label><p class="hint">${AI.estNote}</p>`, async f => {
            await api('sys-ai-quota-save', { body: { track: f.track, scope: f.scope, target: f.scope === 'user' ? f.user_target : f.office_target, office: f.scope === 'user' ? f.office || null : null, period: f.period,
                limit_money: f.limit_money.trim() || null, limit_tokens: f.limit_tokens.trim() ? Number(f.limit_tokens) : null, hard: !!f.hard } });
            toast(T.saved); reload();
        });
        $$('[name=scope]', dlg).forEach(r => r.addEventListener('change', () => $$('[data-for]', dlg).forEach(l => { l.hidden = l.dataset.for !== r.value; })));
    });
}

// ── #/office/ai ──────────────────────────────────────────────

async function viewOfficeAi(tabs) {
    const d = await api('office-ai');
    const reload = () => viewOfficeAi(tabs);
    const p = d.policy, txt = d.policy_text, c = d.connection && d.connection.status !== 'revoked' ? d.connection : null;
    const provMap = Object.fromEntries(txt.providers.map(x => [x.key, x.rev]));
    App.main.innerHTML = `${tabs}
        <h2 class="sec-title">🛡️ ${AI.policyTitle}</h2>
        ${p.needs_reapproval ? `<p class="banner" role="note">⚠️ ${AI.reapprove}</p>` : ''}
        <div class="summary"><div class="line"><b class="one">${p.on && !p.needs_reapproval ? esc(AI.policyOn(p.approved_by || '', fmtDate(p.approved_at))) : AI.policyOff}</b>
            <span class="chip ${d.preview.available ? 'ok' : 'warn'}">${d.preview.available ? AI.available : esc(AI.reasons[d.preview.reason] || AI.unavailable)}</span></div>
          ${d.preview.track ? `<div class="line muted">${esc(AI.nowTrack(AI.tracks[d.preview.track]))}${d.preview.model ? ' · ' + esc(d.preview.model.label) : ''}</div>` : ''}</div>
        <section class="group" id="aipolicy"><h3>${AI.sentTo}</h3>
          ${txt.providers.map(x => `<p><b>${esc(x.name)}</b> — ${x.privacy_note ? esc(x.privacy_note) : esc(AI.noPrivacyNote)}${x.privacy_checked_at ? ` (${esc(AI.checked(fmtDate(x.privacy_checked_at)))})` : ''}
            ${x.privacy_url ? ' · ' + sLink(x.privacy_url, AI.policyLink) : ''}</p>`).join('')}
          <ul>${txt.statements.map(k => `<li>${esc(AI.statements[k] || k)}</li>`).join('')}</ul>
          <div class="row-btns wrap">${!p.on || p.needs_reapproval ? `<button type="button" class="btn primary" id="aiapprove">${p.enabled ? AI.reapproveDo : AI.approve}</button>` : ''}
            ${p.enabled ? `<button type="button" class="btn ghost danger" id="aidisable">${AI.disable}</button>` : ''}</div></section>
        <h2 class="sec-title">🔑 ${AI.personalTitle}</h2>
        ${c ? `<ul class="list">${aiConnCard(c)}</ul>` : `<p class="hint">${AI.personalNone}</p><div class="row-btns"><button type="button" class="btn ghost" id="aiconnect">${AI.connect}</button></div>`}
        <p class="hint">${AI.personalNote}</p>
        ${c && (d.managed_assigned || p.prefer_managed) ? `<label class="switch"><input type="checkbox" id="aiprefer"${p.prefer_managed ? ' checked' : ''}><span>${AI.preferManaged}</span></label>` : ''}
        <h2 class="sec-title">📏 ${AI.quotasOffice} <button class="btn ghost sm" type="button" id="aiqadd"><span aria-hidden="true">+</span> ${AI.addQuota}</button></h2><p class="hint">${AI.estNote}</p>
        ${d.quotas.length ? `<ul class="list">${d.quotas.map(q => aiQuotaCard(q, aiQuotaTitle(q), q.editable, q.editable ? '' : AI.setBySystem)).join('')}</ul>` : `<p class="empty">${AI.noQuotas}</p>`}
        <h2 class="sec-title">📊 ${AI.usageOffice}</h2>
        ${d.usage.by_user.length ? `<p class="hint">${AI.byUser}</p><ul class="list">${d.usage.by_user.map(u => `<li class="card static"><div class="body"><div class="l1"><span>${esc(u.user_name || '—')}</span></div><div class="l2 wrap">${esc(aiUsageText(u))}</div></div></li>`).join('')}</ul>` : `<p class="empty">${AI.noUsage}</p>`}
        ${d.usage.by_case.length ? `<p class="hint">${AI.byCase}</p><ul class="list">${d.usage.by_case.map(u => `<li class="card static"><div class="body"><div class="l1"><span dir="ltr">${esc(u.number)}</span></div><div class="l2 wrap">${esc(aiUsageText(u))}</div></div></li>`).join('')}</ul>` : ''}
        ${d.requests.length ? `<h2 class="sec-title">🙋 ${AI.alertRequests}</h2><ul class="list">${d.requests.map(r => `<li class="card static"><div class="body"><div class="l1"><span>${esc(r.user_name || '')}</span><span class="t">${esc(fmtDateTime(r.at))}</span></div></div></li>`).join('')}</ul>` : ''}
        <h2 class="sec-title">⛔ ${AI.blocks}</h2>${aiBlocksHtml(d.blocks)}`;
    // האישור נושא את הגרסה והספקים **שהוצגו** — אם השתנו בינתיים, השרת עונה 409 והמסך נטען מחדש
    aiAct('#aiapprove', document, async () => {
        try { await api('office-ai-policy', { body: { enabled: true, text_version: txt.version, providers: provMap } }); toast(T.saved); } finally { reload(); }
    });
    aiAct('#aidisable', document, async () => { if (!confirm(AI.disableAsk)) return; await api('office-ai-policy', { body: { enabled: false } }); toast(T.saved); reload(); });
    $('#aiprefer')?.addEventListener('change', async e => {
        try { await api('office-ai-policy', { body: { prefer_managed: e.target.checked } }); toast(T.saved); reload(); } catch (x) { e.target.checked = !e.target.checked; toastError(x); }
    });
    $('#aiconnect')?.addEventListener('click', () => aiConnAddSheet('office-ai-', txt.providers, reload));
    cOn('[data-aiconn]', () => aiConnSheet(c, 'office-ai-', d.models, reload));
    cOn('[data-quota]', el => aiQuotaSheet(d.quotas.find(q => q.pid === el.dataset.quota), 'office-ai-', reload));
    $('#aiqadd').addEventListener('click', () => {
        const dlg = sheet(AI.addQuota, `<fieldset class="seg" aria-label="${AI.scope}"><label><input type="radio" name="scope" value="office" checked><span>${AI.scopes.office}</span></label><label><input type="radio" name="scope" value="user"><span>${AI.scopes.user}</span></label></fieldset>
            <label class="f" data-for="user" hidden><span>${AI.scopes.user}</span><select name="target">${aiOpts(d.members, m => m.pid, m => m.name)}</select></label>
            <label class="f"><span>${AI.period}</span><select name="period">${aiOpts(Object.keys(AI.periods), k => k, k => AI.periods[k])}</select></label>
            <label class="f"><span>${AI.limitMoney}</span><input name="limit_money" inputmode="decimal" dir="ltr"></label>${d.has_fx ? '' : `<p class="hint">${AI.fxNone}</p>`}
            <label class="f"><span>${AI.limitTokens}</span><input name="limit_tokens" inputmode="numeric" dir="ltr"></label>
            <label class="switch"><input type="checkbox" name="hard" checked><span>${AI.hard}</span></label><p class="hint">${AI.personalNote}</p>`, async f => {
            await api('office-ai-quota-save', { body: { scope: f.scope, target: f.scope === 'user' ? f.target : null, period: f.period, limit_money: f.limit_money.trim() || null,
                limit_tokens: f.limit_tokens.trim() ? Number(f.limit_tokens) : null, hard: !!f.hard } });
            toast(T.saved); reload();
        });
        $$('[name=scope]', dlg).forEach(r => r.addEventListener('change', () => $$('[data-for]', dlg).forEach(l => { l.hidden = l.dataset.for !== r.value; })));
    });
}

// ── #/my/ai — "הבינה שלי" ────────────────────────────────────

async function viewMyAi() {
    setHead(AI.mine, null);
    const d = await api('my-ai'), a = d.ai;
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one">${a.available ? AI.available : AI.unavailable}</b><span class="chip ${a.available ? 'ok' : 'warn'}">${a.available ? '✓' : esc(AI.reasons[a.reason] || '')}</span></div>
          <div class="line muted">${AI.myTrack}: ${a.track ? esc(AI.tracks[a.track]) : '—'}</div>
          <div class="line muted">${AI.myProvider}: ${esc(a.provider || '—')} · ${AI.myModel}: ${a.model ? esc(a.model.label) : '—'}</div></div>
        <h2 class="sec-title">📏 ${AI.myQuotas}</h2><p class="hint">${AI.estNote}${a.track === 'personal' ? ' ' + AI.personalNote : ''}</p>
        ${d.quotas.length ? `<ul class="list">${d.quotas.map(q => aiQuotaCard(q, AI.quotaOf[q.scope], false) + ((q.money && q.money.warn) || (q.tokens && q.tokens.warn)
            ? `<li class="row-btns"><button type="button" class="btn ghost sm" data-ask="${esc(q.pid)}">${AI.askMore}</button></li>` : '')).join('')}</ul>` : `<p class="empty">${AI.noQuotas}</p>`}
        <h2 class="sec-title">📊 ${AI.myUsage}</h2><p class="hint">${esc(aiUsageText(d.usage))}</p>
        <h2 class="sec-title">⛔ ${AI.myBlocks}</h2>${aiBlocksHtml(d.blocks)}`;
    aiAct('[data-ask]', document, async b => { const r = await api('ai-quota-request', { body: { quota: b.dataset.ask } }); toast(AI.asked[r.to] || T.saved); });
}

// ── כרטיס התיק: "בלי בינה" ושימוש ────────────────────────────

/** ממלא את #case-ai שבסקירת התיק. כישלון בטעינה אינו מפיל את מסך התיק. */
async function aiCaseSection(pid) {
    const box = $('#case-ai');
    if (!box) return;
    let d;
    try { d = await api('case-ai', { params: { case: pid } }); } catch { return; }
    if (!$('#case-ai')) return;
    box.innerHTML = `<h2 class="sec-title">🤖 ${AI.caseTitle}</h2>
        <label class="switch"><input type="checkbox" id="ainoai"${d.no_ai ? ' checked' : ''}${d.can_set ? '' : ' disabled'}><span>${AI.noAi}</span></label><p class="hint">${AI.noAiHint}</p>
        ${d.usage ? `<p class="hint">${AI.caseUsage}: ${esc(aiUsageText(d.usage))}</p>` : ''}
        ${d.quotas.length ? `<ul class="list">${d.quotas.map(q => aiQuotaCard(q, AI.quotaOf.case, false)).join('')}</ul>` : ''}`;
    $('#ainoai').addEventListener('change', async e => {
        try { await api('case-ai-set', { body: { case: pid, no_ai: e.target.checked } }); toast(T.saved); } catch (x) { e.target.checked = !e.target.checked; toastError(x); }
    });
}
