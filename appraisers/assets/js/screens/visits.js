/*
 * פרוסה 13 — ביקור: טופס מהפרופיל, תמונות, כמה ביקורים (docs/spec/13-visit.md §9).
 *   כרטיס תיק ← "ביקור" (visitsTab) ← #/cases/<pid>/visits/<pid> מסך הביקור (viewVisit) · עורך פרופיל ← "ביקור" (peVisit)
 *
 * מסך הביקור בנוי לתנאי שטח: שדה אחד בשורה, יעדי מגע גדולים, מקלדת מספרית למספרים, וכפתור מצלמה קבוע בתחתית.
 * **אין כפתור "שמירה"**: כל שדה נשמר כשעוזבים אותו, וליד כל שדה חיווי — נשמר ✓ · שומר… · לא נשמר. שדה שלא נשמר (אין רשת)
 * נשלח שוב לבד כשהרשת חוזרת, כל עוד הדף פתוח; סגירת הדף עם שדות שלא נשמרו מתריעה. עבודה בלי קליטה — פרוסה 14.
 * כל שמירה שולחת את ה-rev שנקרא; 409 ← חלון "הערך שלך / הערך של <שם>" — אין דריסה.
 * שדה ריק נשלח כ-null: "לא מולא" אינו 0 ואינו "לא". כל ערך של משתמש עובר דרך esc(). ההרשאות נאכפות בשרת.
 */
'use strict';

const V = T.v;
const VS = { pid: null, x: null, st: {}, pending: new Map(), timer: null, preview: null };   // המצב של מסך הביקור הפתוח
const vKey = (field, row) => field + '#' + (row || 0);
const vStatusChip = s => `<span class="chip ${V.statusCls[s] || ''}">${esc(V.statuses[s] || s)}</span>`;
const vWhen = s => s ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)} ${s.slice(11, 16)}` : V.noTime;
const vPhotoUrl = (p, thumb) => `api.php?action=visit-photo&photo=${encodeURIComponent(p.pid)}${thumb ? '&thumb=1' : ''}`;

/** אותה הערכת תנאים של השרת (apr_visit_eval): answers — ערכי התשובות; property_type, purpose — מהתיק. */
function vEval(c, inp) {
    if (!c) return true;
    if (c.all) return c.all.every(k => vEval(k, inp));
    if (c.any) return c.any.some(k => vEval(k, inp));
    const actual = c.field.startsWith('answer.') ? (inp.answers[c.field.slice(7)] ?? null) : (inp[c.field] ?? null);
    const has = v => Array.isArray(actual) ? actual.includes(v) : actual === v;
    return c.op === 'is' ? has(c.value) : c.op === 'is_not' ? !has(c.value) : c.op === 'in' ? [].concat(c.value).some(has) : ![].concat(c.value).some(has);
}

function vRowHtml(v, pid) {
    return `<li class="card" tabindex="0" role="button" data-go="#/cases/${pid}/visits/${v.pid}"><span class="use-ic" aria-hidden="true">${V.statusIcon[v.status] || '🏠'}</span><div class="body">
        <div class="l1"><span>${esc(V.visitN(v.seq))}</span><span class="t">${esc(vWhen(v.scheduled_at))}</span></div>
        <div class="l2 wrap">${esc(v.visitor.name)}${v.status_note ? ' · ' + esc(v.status_note) : ''}</div>
        <div class="chips">${vStatusChip(v.status)}</div></div></li>`;
}

// ── לשונית "ביקור" בכרטיס התיק ──
async function visitsTab(pid, reload) {
    const x = await api('case-visits', { params: { case: pid } });
    const s = x.summary;
    const html = `<h2 class="sec-title">🏠 ${V.title} <small>${x.visits.length || ''}</small></h2>
        <p class="hint">${esc(V.summary(s.completed, s.open))}${s.no_access ? ' · ' + esc(V.noAccessN(s.no_access)) : ''}</p>
        ${x.visits.length ? `<ul class="list">${x.visits.map(v => vRowHtml(v, pid)).join('')}</ul>` : `<p class="empty">${V.none}</p>`}
        ${x.perms.plan ? `<div class="row-btns"><button type="button" class="btn primary" id="v-new"><span aria-hidden="true">+</span> ${V.newVisit}</button></div>` : ''}`;
    return { html, bind: () => { cOn('[data-go]', el => { location.hash = el.dataset.go; }); $('#v-new')?.addEventListener('click', () => visitPlanSheet(pid, x, null, reload)); } };
}

function visitPlanSheet(pid, x, v, done) {
    if (!x.visitors.length) return toast(V.noVisitors, true);
    sheet(v ? V.editVisit : V.newVisit, `
        <label class="f"><span>${V.visitor} *</span><select name="visitor" required>${x.visitors.map(m => cOpt(m.pid, m.name, v?.visitor.pid)).join('')}</select></label>
        <label class="f"><span>${V.when}</span><input type="datetime-local" name="scheduled_at" value="${esc(v?.scheduled_at || '')}"></label>
        <label class="f"><span>${V.accessContact}</span><select name="access_contact"><option value="">${V.noContact}</option>${x.contacts.map(c => cOpt(c.pid, c.name + (c.phone ? ' · ' + c.phone : ''), v?.access_contact?.pid)).join('')}</select></label>
        <label class="f"><span>${V.accessNote}</span><textarea name="access_note" rows="2" maxlength="500">${esc(v?.access_note || '')}</textarea></label>
        <p class="hint">${V.planHint}</p>`, async f => {
        const body = { visitor: f.visitor, scheduled_at: (f.scheduled_at || '').slice(0, 16) || null, access_contact: f.access_contact || null, access_note: f.access_note };
        if (v) await api('visit-update', { body: { visit: v.pid, rev: v.rev, ...body } });
        else await api('visit-plan', { body: { case: pid, ...body } });
        done();
    });
}

// ── הטופס: ציור (משותף למסך הביקור ולתצוגה המקדימה בעורך הפרופיל) ──
/** מה גלוי עכשיו: לכל שדה ושורה — לפי התנאים. answers: Map(key#row → תשובה). מחזיר { groups: [{g, rows: [{row, fields}]}] }. */
function vLayout(form, answers, rows, ctx) {
    const repeat = Object.fromEntries(form.groups.map(g => [g.key, g.repeat]));
    const val = (k, row) => answers.get(vKey(k, row))?.value ?? null;
    const base = {};
    form.fields.forEach(f => { if (!repeat[f.group]) base[f.key] = val(f.key, 0); });
    return form.groups.map(g => {
        if (!vEval(g.when, { ...ctx, answers: base })) return null;
        const mine = form.fields.filter(f => f.group === g.key);
        const rowNos = g.repeat ? (rows[g.key] || []) : [0];
        return { g, rows: rowNos.map(row => {
            const a = { ...base };
            if (g.repeat) mine.forEach(f => { a[f.key] = val(f.key, row); });
            return { row, fields: mine.filter(f => vEval(f.when, { ...ctx, answers: a })) };
        }) };
    }).filter(Boolean);
}

function vInputHtml(f, a, row, x, ro) {
    const id = `vf-${f.key}-${row}`, dis = ro ? ' disabled' : '', d = `data-vf="${esc(f.key)}" data-row="${row}"`;
    const v = a?.value ?? null;
    if (f.type === 'longtext') return `<textarea id="${id}" ${d} rows="3" maxlength="4000"${dis}>${esc(v ?? '')}</textarea>`;
    if (f.type === 'text') return `<input id="${id}" ${d} maxlength="300" value="${esc(v ?? '')}"${dis}>`;
    if (f.type === 'date') return `<input id="${id}" ${d} type="date" value="${esc(v ?? '')}"${dis}>`;
    if (f.type === 'number') return `<input id="${id}" ${d} inputmode="decimal" dir="ltr" value="${esc(v ?? '')}"${dis}>`;
    if (f.type === 'area' || f.type === 'length') {
        const units = x.units[f.type];
        return `<div class="v-num"><input id="${id}" ${d} inputmode="decimal" dir="ltr" value="${esc(v ?? '')}" aria-label="${esc(f.label)}"${dis}>
            <select ${d} data-part="unit" aria-label="${V.unit}"${dis}>${units.map(u => cOpt(u, V.units[u] || u, a?.unit || units[0])).join('')}</select>
            ${f.type === 'area' ? `<select ${d} data-part="area_kind" aria-label="${V.areaKind}"${dis}><option value="">${V.areaKind}…</option>${x.area_kinds.map(k => cOpt(k, V.areaKinds[k] || k, a?.area_kind || '')).join('')}</select>` : ''}</div>`;
    }
    if (f.type === 'yesno') {
        // שלושה מצבים: כן · לא · לא מולא. לחיצה חוזרת על הבחירה מנקה אותה — "לא מולא" אינו "לא"
        return `<div class="seg v-yn" role="group" aria-label="${esc(f.label)}">${[[true, V.yes], [false, V.no]].map(([val, label]) =>
            `<button type="button" ${d} data-yn="${val}" aria-pressed="${v === val}"${dis}>${label}</button>`).join('')}</div>`;
    }
    if (f.type === 'choice') return `<select id="${id}" ${d}${dis}><option value="">${V.notFilled}</option>${(f.options || []).map(o => cOpt(o.key, o.label, v ?? '')).join('')}</select>`;
    if (f.type === 'multichoice') return `<div class="v-multi">${(f.options || []).map(o => `<label class="switch"><input type="checkbox" ${d} data-opt="${esc(o.key)}"${(v || []).includes(o.key) ? ' checked' : ''}${dis}><span>${esc(o.label)}</span></label>`).join('')}</div>`;
    return '';
}

function vPhotoStrip(f, row, x, ro) {
    const list = x.photos.filter(p => p.field === f.key && p.row === row);
    return `<div class="v-strip">${list.map(p => `<button type="button" class="v-ph" data-photo="${p.pid}" aria-label="${esc(p.caption || V.photo)}"><img src="${vPhotoUrl(p, true)}" alt="" loading="lazy"></button>`).join('')}
        ${ro ? (list.length ? '' : `<span class="muted">${V.noPhotos}</span>`) : `<button type="button" class="btn ghost v-shoot" data-shoot="${esc(f.key)}" data-row="${row}"><span aria-hidden="true">📷</span> ${V.shoot}</button>`}</div>`;
}

function vFieldHtml(f, row, a, x, ro) {
    const k = vKey(f.key, row);
    const more = f.type === 'photo' ? '' : `<details class="v-more"${a && (a.note || a.obtained_from) ? ' open' : ''}><summary>${esc(V.obtained[a?.obtained || f.obtained] || '')}${a?.note ? ' · 📝' : ''}</summary>
        <label class="f"><span>${V.obtainedHow}</span><select data-vf="${esc(f.key)}" data-row="${row}" data-part="obtained"${ro ? ' disabled' : ''}>${x.obtained.map(o => cOpt(o, V.obtained[o], a?.obtained || f.obtained)).join('')}</select></label>
        <label class="f" data-from${(a?.obtained || f.obtained) === 'reported' ? '' : ' hidden'}><span>${V.obtainedFrom}</span><input data-vf="${esc(f.key)}" data-row="${row}" data-part="obtained_from" maxlength="120" value="${esc(a?.obtained_from || '')}"${ro ? ' disabled' : ''}></label>
        <label class="f"><span>${V.note}</span><textarea data-vf="${esc(f.key)}" data-row="${row}" data-part="note" rows="2" maxlength="1000"${ro ? ' disabled' : ''}>${esc(a?.note || '')}</textarea></label></details>`;
    return `<div class="v-field${f.required ? ' req' : ''}" data-field="${esc(k)}">
        <div class="v-label"><label for="vf-${esc(f.key)}-${row}">${esc(f.label)}${f.required ? ' <span class="v-req" aria-label="' + V.required + '">*</span>' : ''}</label><span class="v-save" data-save="${esc(k)}" aria-live="polite"></span></div>
        ${f.hint ? `<p class="hint">${esc(f.hint)}</p>` : ''}
        ${a?.copied_from ? `<p class="banner" role="note">${esc(V.copied(a.copied_from))} ${ro ? '' : `<button type="button" class="btn ghost sm" data-confirm="${esc(k)}">${V.confirmCopied}</button>`}</p>` : ''}
        ${f.type === 'photo' ? vPhotoStrip(f, row, x, ro) : vInputHtml(f, a, row, x, ro)}${more}</div>`;
}

function vFormHtml(x, answers, ro) {
    const lay = vLayout(x.form, answers, x.rows, { property_type: x.case.property_type, purpose: x.case.purpose });
    return lay.map(({ g, rows }) => `<section class="panel v-group" id="vg-${esc(g.key)}"><h3 class="sec-title">${esc(g.title)}</h3>
        ${rows.map(({ row, fields }) => `${g.repeat ? `<div class="v-rowhead"><b>${esc(g.title)} ${row}</b>${ro ? '' : `<button type="button" class="btn ghost sm" data-row-del="${esc(g.key)}" data-row="${row}">${V.removeRow}</button>`}</div>` : ''}
            ${fields.map(f => vFieldHtml(f, row, answers.get(vKey(f.key, row)), x, ro)).join('')}`).join('')}
        ${g.repeat ? (ro ? (rows.length ? '' : `<p class="empty">${V.noRows}</p>`) : `<div class="row-btns"><button type="button" class="btn ghost" data-row-add="${esc(g.key)}"><span aria-hidden="true">+</span> ${esc(V.addRow(g.title))}</button></div>`) : ''}</section>`).join('');
}

function vProgressHtml(progress) {
    return `<div class="chips v-progress">${progress.filter(p => !p.hidden).map(p => `<a class="chip ${p.missing ? 'warn' : p.total && p.filled === p.total ? 'ok' : ''}" href="#vg-${esc(p.group)}" data-jump="vg-${esc(p.group)}">${esc(p.title)} ${p.filled}/${p.total}${p.missing ? ' · ' + esc(V.missingN(p.missing)) : ''}</a>`).join('')}</div>`;
}

// ── מסך הביקור ──
async function viewVisit(pid, vpid) {
    const x = await api('visit', { params: { visit: vpid } });
    const v = x.visit, fill = x.perms.fill && v.status === 'in_progress';
    VS.pid = vpid; VS.x = x; VS.pending.clear();
    const answers = VS.answers = new Map(x.answers.map(a => [vKey(a.field, a.row), a]));
    setHead(V.visitN(v.seq), x.case.number);
    App.main.classList.add('wide');
    const reload = () => viewVisit(pid, vpid);
    const btn = (id, label, cls = 'ghost') => `<button type="button" class="btn ${cls}" id="${id}">${label}</button>`;
    const actions = [];
    if (x.perms.fill && ['planned', 'postponed'].includes(v.status)) actions.push(btn('v-start', V.start, 'primary'));
    if (fill) actions.push(btn('v-finish', V.finish, 'primary'));
    if (x.perms.fill && ['completed', 'no_access'].includes(v.status)) actions.push(btn('v-reopen', V.reopen));
    if ((x.perms.fill || x.perms.plan) && ['planned', 'in_progress', 'postponed'].includes(v.status)) actions.push(btn('v-postpone', V.postpone));
    if (x.perms.plan && ['planned', 'postponed'].includes(v.status)) actions.push(btn('v-edit', V.editVisit), btn('v-cancel', V.cancel));
    if (fill && x.others.length) actions.push(btn('v-copy', V.copyFrom));
    const prepared = x.prepared;
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one">${esc(x.case.title || '')}</b>${vStatusChip(v.status)}</div>
          <div class="line muted">${esc(v.visitor.name)} · ${esc(vWhen(v.scheduled_at))}</div>
          ${v.access_contact ? `<div class="line">${V.accessContact}: ${esc(v.access_contact.name)}${v.access_contact.phone ? ` · <a href="tel:${esc(v.access_contact.phone.replace(/[^0-9+]/g, ''))}" dir="ltr">${esc(v.access_contact.phone)}</a>` : ''}</div>` : ''}
          ${v.access_note ? `<div class="line muted">${esc(v.access_note)}</div>` : ''}${v.status_note ? `<div class="line"><b>${esc(V.statusNote[v.status] || V.note)}:</b> ${esc(v.status_note)}</div>` : ''}</div>
        ${v.status === 'no_access' ? `<p class="banner" role="note">🚪 ${V.noAccessBanner}</p>` : v.status === 'postponed' ? `<p class="banner" role="note">⏳ ${V.postponedBanner}</p>` : ''}
        ${!v.snapshot ? `<p class="hint">${V.previewHint}</p>` : x.form.example ? `<p class="hint">${V.exampleForm}</p>` : ''}
        ${actions.length ? `<div class="row-btns wrap">${actions.join('')}</div>` : ''}
        ${fill ? `<label class="f"><span>${V.attendees}</span><input id="v-att" maxlength="500" value="${esc(v.attendees || '')}"></label>` : v.attendees ? `<p class="hint">${V.attendees}: ${esc(v.attendees)}</p>` : ''}
        <div id="v-prog">${vProgressHtml(x.progress)}</div>
        <details class="v-prep"><summary class="hint">📋 ${V.prepared}</summary>
          ${prepared.identifiers.length ? `<ul class="list">${prepared.identifiers.map(i => `<li class="card static"><div class="body"><div class="l1"><span>${esc(identText(i))}</span></div><div class="l2">${esc(C.identKinds[i.kind] || i.kind)}</div></div></li>`).join('')}</ul>` : ''}
          ${prepared.facts.length ? `<ul class="list">${prepared.facts.map(f => `<li class="card static"><div class="body"><div class="l1"><span>${esc(f.label)}</span><span class="t">${esc(f.num ?? f.text ?? f.date ?? (f.bool === null ? '' : f.bool ? V.yes : V.no))}${f.unit ? ' ' + esc(V.units[f.unit] || f.unit) : ''}</span></div></div></li>`).join('')}</ul>` : `<p class="empty">${V.noPrepared}</p>`}
          <p class="hint"><a href="#/cases/${pid}/reqs">${C.tabs.reqs}</a> · <a href="#/cases/${pid}/facts">${T.f.tab}</a></p></details>
        <div class="doc-grid v-grid"><div id="v-form">${vFormHtml(x, answers, !fill)}</div>
          <div id="v-gallery">${vGalleryHtml(x, fill)}</div></div>
        ${fill ? `<div class="v-bar"><button type="button" class="btn primary" id="v-cam"><span aria-hidden="true">📷</span> ${V.shoot}</button><button type="button" class="btn ghost" id="v-pick"><span aria-hidden="true">🖼️</span> ${V.pick}</button>
          <span id="v-net" class="muted" aria-live="polite"></span></div>
          <input type="file" id="v-file-cam" accept="image/*" capture="environment" hidden><input type="file" id="v-file-pick" accept="image/jpeg,image/png,image/webp" multiple hidden>` : ''}`;
    cOn('[data-jump]', (el, e) => { e.preventDefault(); document.getElementById(el.dataset.jump)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    $('#v-start')?.addEventListener('click', () => vAct('visit-start', { visit: vpid, rev: v.rev }, reload));
    $('#v-edit')?.addEventListener('click', async () => visitPlanSheet(pid, await api('case-visits', { params: { case: pid } }), v, reload));
    $('#v-cancel')?.addEventListener('click', () => reasonSheet(V.cancel, V.cancelHint, async reason => { await api('visit-cancel', { body: { visit: vpid, rev: v.rev, reason } }); reload(); }, V.cancel));
    $('#v-reopen')?.addEventListener('click', () => reasonSheet(V.reopen, V.reopenHint, async reason => { await api('visit-reopen', { body: { visit: vpid, rev: v.rev, reason } }); reload(); }, V.reopen));
    $('#v-postpone')?.addEventListener('click', () => vFinishSheet(pid, 'postponed', reload));
    $('#v-finish')?.addEventListener('click', async () => { await vFlush(); vFinishSheet(pid, 'completed', reload); });
    $('#v-copy')?.addEventListener('click', () => sheet(V.copyFrom, `<p class="hint">${V.copyHint}</p><label class="f"><span>${V.copyWhich}</span><select name="from">${x.others.map(o => cOpt(o.pid, V.visitN(o.seq) + ' · ' + V.statuses[o.status], '')).join('')}</select></label>`,
        async f => { const r = await api('visit-copy', { body: { visit: vpid, from: f.from } }); toast(V.copiedN(r.copied)); reload(); }, V.copyDo));
    $('#v-att')?.addEventListener('change', async e => {
        try { const r = await api('visit-update', { body: { visit: vpid, rev: VS.x.visit.rev, attendees: e.target.value } }); VS.x.visit = { ...VS.x.visit, ...r.visit }; } catch (err) { toastError(err); }
    });
    vBindForm(fill);
    vBindGallery(pid, fill, reload);
    if (fill) vBindCamera(reload);
}

async function vAct(action, body, done) {
    try { await api(action, { body }); done(); } catch (x) { toastError(x); }
}

// ── שמירה הדרגתית ──
function vMark(k, state, title) {
    const el = document.querySelector(`[data-save="${CSS.escape(k)}"]`);
    if (!el) return;
    el.className = 'v-save ' + state;
    el.textContent = V.save[state] || '';
    if (title) el.title = title; else el.removeAttribute('title');
    $('#v-net') && ($('#v-net').textContent = VS.pending.size ? V.unsavedN(VS.pending.size) : '');
}

/** קורא את השדה מהמסך: הערך וחלקיו (יחידה, סוג שטח, איך התקבל, ממי, הערה). ריק ← null. */
function vRead(f, row) {
    const root = document.querySelector(`[data-field="${CSS.escape(vKey(f.key, row))}"]`);
    if (!root) return null;
    const part = p => root.querySelector(`[data-part="${p}"]`)?.value ?? null;
    const main = root.querySelector(`[data-vf]:not([data-part]):not([data-yn]):not([data-opt])`);
    let value;
    if (f.type === 'yesno') { const on = root.querySelector('[data-yn][aria-pressed="true"]'); value = on ? on.dataset.yn === 'true' : null; }
    else if (f.type === 'multichoice') { value = [...root.querySelectorAll('[data-opt]:checked')].map(c => c.dataset.opt); if (!value.length) value = null; }
    else { value = (main?.value ?? '').trim(); if (value === '') value = null; else if (['number', 'area', 'length'].includes(f.type)) value = value.replace(',', '.'); }
    const out = { value, obtained: part('obtained'), obtained_from: part('obtained_from') || null, note: part('note') || null };
    if (f.type === 'area' || f.type === 'length') out.unit = part('unit');
    if (f.type === 'area') out.area_kind = part('area_kind') || null;
    return out;
}

async function vSave(f, row, forceRev) {
    const k = vKey(f.key, row), body = vRead(f, row);
    if (!body) return;
    const cur = VS.answers.get(k);
    // שטח בלי סוג שטח אינו נשלח: מבקשים מהמבקר לבחור (אפשר "לא ידוע")
    if (f.type === 'area' && body.value !== null && !body.area_kind) return vMark(k, 'need', V.needAreaKind);
    if (!cur && body.value === null && !body.note) return vMark(k, '');
    const op = VS.pending.get(k)?.op || opId();
    VS.pending.set(k, { f, row, op });
    vMark(k, 'saving');
    try {
        const r = await api('visit-answer', { body: { visit: VS.pid, field: f.key, row: row || undefined, rev: forceRev ?? cur?.rev ?? 0, op, ...body } });
        VS.pending.delete(k);
        if (r.answer) VS.answers.set(k, r.answer); else VS.answers.delete(k);
        VS.x.progress = r.progress; VS.x.missing = r.missing;
        $('#v-prog').innerHTML = vProgressHtml(r.progress);
        vMark(k, 'saved');
        vRefreshConditions();
    } catch (x) {
        if (x.code === 'visit_changed') { VS.pending.delete(k); vMark(k, 'conflict'); return vConflict(f, row, body, x.current, x.message); }
        if (x.status === 0 || x.status >= 500) { vMark(k, 'failed', x.message); return vRetrySoon(); }
        VS.pending.delete(k);
        vMark(k, 'failed', x.message);
        toastError(x);
    }
}

/** 409: הערך שלי מול הערך שבשרת — המבקר מכריע. אין דריסה שקטה. */
function vConflict(f, row, mine, theirs, message) {
    const show = a => a == null || a.value == null ? V.notFilled : esc(Array.isArray(a.value) ? a.value.join(', ') : a.value === true ? V.yes : a.value === false ? V.no : a.value) + (a.unit ? ' ' + esc(V.units[a.unit] || a.unit) : '');
    const dlg = sheet(V.conflictTitle, `<p class="banner bad" role="alert">${esc(message || V.conflictHint)}</p>
        <div class="side-by-side"><div class="panel"><p class="sec-title">${V.mine}</p><p dir="auto">${show(mine)}</p><button type="button" class="btn primary" id="vc-mine">${V.keepMine}</button></div>
        <div class="panel"><p class="sec-title">${esc(V.theirs(theirs?.updated_by || ''))}</p><p dir="auto">${show(theirs)}</p><button type="button" class="btn ghost" id="vc-theirs">${V.takeTheirs}</button></div></div>`, null);
    const k = vKey(f.key, row);
    $('#vc-mine', dlg).addEventListener('click', () => { dlg.close(); if (theirs) VS.answers.set(k, theirs); vSave(f, row, theirs ? theirs.rev : 0); });
    $('#vc-theirs', dlg).addEventListener('click', () => { dlg.close(); if (theirs) VS.answers.set(k, theirs); else VS.answers.delete(k); vRedrawForm(); });
}

function vRetrySoon() {
    clearTimeout(VS.timer);
    VS.timer = setTimeout(vFlush, 8000);
}
/** שולח שוב כל שדה שלא נשמר (מקרה קצה 5): כשהרשת חוזרת, ולפני סיום. */
async function vFlush() {
    clearTimeout(VS.timer);
    for (const [, p] of [...VS.pending]) await vSave(p.f, p.row);
}
addEventListener('online', () => { if (VS.pending.size) vFlush(); });
addEventListener('beforeunload', e => { if (VS.pending.size && location.hash.includes('/visits/')) { e.preventDefault(); e.returnValue = ''; } });

/** שדה מותנה מופיע ונעלם לפי התשובה: אם מה שגלוי השתנה — מציירים מחדש את הטופס, ושומרים על המיקוד. */
function vRefreshConditions() {
    const x = VS.x, lay = vLayout(x.form, VS.answers, x.rows, { property_type: x.case.property_type, purpose: x.case.purpose });
    const want = lay.flatMap(({ rows }) => rows.flatMap(({ row, fields }) => fields.map(f => vKey(f.key, row)))).join('|');
    const have = $$('#v-form [data-field]').map(el => el.dataset.field).join('|');
    if (want !== have) vRedrawForm();
}
function vRedrawForm() {
    const focus = document.activeElement?.id;
    $('#v-form').innerHTML = vFormHtml(VS.x, VS.answers, false);
    vBindForm(true);
    if (focus) document.getElementById(focus)?.focus();
}

function vBindForm(fill) {
    const root = $('#v-form'), x = VS.x;
    cOn('[data-photo]', el => vPhotoSheet(el.dataset.photo), root);
    if (!fill) return;
    const fieldOf = el => x.form.fields.find(f => f.key === el.dataset.vf);
    root.addEventListener('change', e => {
        const el = e.target.closest('[data-vf]');
        if (!el) return;
        if (el.dataset.part === 'obtained') el.closest('.v-more').querySelector('[data-from]').hidden = el.value !== 'reported';
        vSave(fieldOf(el), +el.dataset.row);
    });
    root.addEventListener('input', e => { const el = e.target.closest('[data-vf]'); if (el) vMark(vKey(el.dataset.vf, +el.dataset.row), 'dirty'); });
    $$('[data-yn]', root).forEach(b => b.addEventListener('click', () => {
        const on = b.getAttribute('aria-pressed') === 'true';
        $$(`[data-yn][data-vf="${CSS.escape(b.dataset.vf)}"][data-row="${b.dataset.row}"]`, root).forEach(o => o.setAttribute('aria-pressed', 'false'));
        if (!on) b.setAttribute('aria-pressed', 'true');
        vSave(fieldOf(b), +b.dataset.row);
    }));
    $$('[data-confirm]', root).forEach(b => b.addEventListener('click', () => { const [key, row] = b.dataset.confirm.split('#'); vSave(x.form.fields.find(f => f.key === key), +row); b.closest('.banner').remove(); }));
    $$('[data-row-add]', root).forEach(b => b.addEventListener('click', async () => {
        try { const r = await api('visit-row-add', { body: { visit: VS.pid, group: b.dataset.rowAdd } }); x.rows = r.rows; x.progress = r.progress; $('#v-prog').innerHTML = vProgressHtml(r.progress); vRedrawForm(); } catch (err) { toastError(err); }
    }));
    $$('[data-row-del]', root).forEach(b => b.addEventListener('click', async () => {
        if (!confirm(V.removeRowAsk)) return;
        try { const r = await api('visit-row-remove', { body: { visit: VS.pid, group: b.dataset.rowDel, row: +b.dataset.row } }); x.rows = r.rows; x.progress = r.progress; $('#v-prog').innerHTML = vProgressHtml(r.progress); vRedrawForm(); } catch (err) { toastError(err); }
    }));
    $$('[data-shoot]', root).forEach(b => b.addEventListener('click', () => { VS.target = { field: b.dataset.shoot, row: +b.dataset.row }; $('#v-file-cam').click(); }));
}

// ── תמונות ──
/** הקטנה במכשיר לפני ההעלאה: עד 2,560 פיקסלים בצלע הארוכה, JPEG. הציור על canvas מיישם את כיוון התמונה ומשמיט את נתוני ה-EXIF. */
async function vShrink(file, max) {
    try {
        const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const k = Math.min(1, max / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas');
        c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.85));
        return blob || file;
    } catch { return file; }     // דפדפן שאינו מפענח את הסוג: השרת יבדוק, ינקה או ידחה
}

/** מיקום (§6.3): רק כשהגדרת המשרד דולקת, ורק אחרי שהמבקר הסכים במכשיר — נשאל פעם אחת לכל ביקור. */
async function vGeo() {
    if (!VS.x.geo || !navigator.geolocation) return {};
    const key = 'apr-geo-' + VS.pid;
    let ok = sessionStorage.getItem(key);
    if (ok === null) { ok = confirm(V.geoAsk) ? '1' : '0'; sessionStorage.setItem(key, ok); }
    if (ok !== '1') return {};
    return new Promise(res => navigator.geolocation.getCurrentPosition(p => res({ consent: '1', lat: String(p.coords.latitude), lng: String(p.coords.longitude), acc: String(p.coords.accuracy) }), () => res({}), { timeout: 6000, maximumAge: 60000 }));
}

async function vUpload(files, target, reload) {
    const net = $('#v-net');
    let done = 0;
    for (const file of files) {
        if (net) net.textContent = V.uploading(done + 1, files.length);
        try {
            const blob = await vShrink(file, VS.x.limits.max_side);
            if (blob.size > VS.x.limits.max_size) throw new Error(V.tooBig);
            const qs = new URLSearchParams({ action: 'visit-photo-upload', visit: VS.pid, name: (file.name || 'photo').replace(/\.[^.]*$/, '') + '.jpg', key: opId(), ...(target ? { field: target.field, row: String(target.row || 0) } : {}), ...await vGeo() });
            const res = await fetch('api.php?' + qs, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': App.me?.csrf || '', 'Content-Type': 'application/octet-stream' }, body: blob });
            const data = await res.json().catch(() => ({}));
            DevLoop.noteRequest({ action: 'visit-photo-upload', method: 'POST', status: res.status, id: res.headers.get('X-Request-Id') || '' });
            if (!res.ok) throw new Error(data.error?.message || T.fault(res.status));
            if (data.geo_recorded) toast('📍 ' + V.geoRecorded);
            done++;
        } catch (x) { toast(x.message || T.noConnection, true); }
    }
    if (net) net.textContent = '';
    if (done) { await vFlush(); reload(); }
}

function vBindCamera(reload) {
    $('#v-cam').addEventListener('click', () => { VS.target = null; $('#v-file-cam').click(); });
    $('#v-pick').addEventListener('click', () => { VS.target = null; $('#v-file-pick').click(); });
    for (const id of ['#v-file-cam', '#v-file-pick']) $(id).addEventListener('change', e => { const files = [...e.target.files]; e.target.value = ''; if (files.length) vUpload(files, VS.target, reload); });
}

function vGalleryHtml(x, fill) {
    const manage = x.perms.fill && ['in_progress', 'completed'].includes(x.visit.status);
    return `<h3 class="sec-title">🖼️ ${V.gallery} <small>${x.photos.length || ''}</small></h3>
        ${x.photos.length ? `<ul class="list v-gal">${x.photos.map((p, i) => `<li class="card static" data-gal="${p.pid}"><button type="button" class="v-ph big" data-photo="${p.pid}" aria-label="${esc(p.caption || V.photo)}"><img src="${vPhotoUrl(p, true)}" alt="" loading="lazy"></button>
            <div class="body"><div class="l1"><span dir="auto">${esc(p.caption || V.noCaption)}</span></div>
            <div class="l2 wrap">${esc(p.place || '')}${p.geo ? ' · 📍' : ''}</div>
            <div class="chips"><span class="chip ${p.usage === 'private' ? '' : p.usage === 'report' ? 'ok' : 'info'}">${esc(V.usage[p.usage])}</span>${p.for_report ? `<span class="chip ok">${V.forReport}</span>` : ''}</div></div>
            ${manage ? `<span class="v-ord"><button type="button" class="icon-btn" data-up="${i}" aria-label="${V.moveUp}"${i ? '' : ' disabled'}>▲</button><button type="button" class="icon-btn" data-down="${i}" aria-label="${V.moveDown}"${i < x.photos.length - 1 ? '' : ' disabled'}>▼</button></span>` : ''}</li>`).join('')}</ul>`
            : `<p class="empty">${V.noPhotos}</p>`}<p class="hint">${V.photoHint}</p>`;
}

function vBindGallery(pid, fill, reload) {
    const root = $('#v-gallery'), x = VS.x;
    cOn('[data-photo]', el => vPhotoSheet(el.dataset.photo, reload), root);
    const move = async (i, d) => {
        const order = x.photos.map(p => p.pid);
        [order[i], order[i + d]] = [order[i + d], order[i]];
        try { await api('visit-photo-order', { body: { visit: VS.pid, order } }); reload(); } catch (err) { toastError(err); }
    };
    $$('[data-up]', root).forEach(b => b.addEventListener('click', () => move(+b.dataset.up, -1)));
    $$('[data-down]', root).forEach(b => b.addEventListener('click', () => move(+b.dataset.down, 1)));
}

/** תמונה: תצוגה, תיאור, מיקום בנכס, הרשאת שימוש, "לדוח", מחיקה. */
function vPhotoSheet(photoPid, reload) {
    const x = VS.x, p = x.photos.find(q => q.pid === photoPid);
    if (!p) return;
    const manage = x.perms.fill && ['in_progress', 'completed'].includes(x.visit.status);
    const dlg = sheet(p.caption || V.photo, `<img class="v-full" src="${vPhotoUrl(p, false)}" alt="${esc(p.caption || '')}">
        ${manage ? `<label class="f"><span>${V.caption}</span><input name="caption" maxlength="300" value="${esc(p.caption)}"></label>
        <label class="f"><span>${V.place}</span><input name="place" maxlength="80" list="v-places" value="${esc(p.place)}"><datalist id="v-places">${x.places.map(s => `<option value="${esc(s)}">`).join('')}</datalist></label>
        <label class="f"><span>${V.usageTitle}</span><select name="usage">${Object.keys(V.usage).map(u => cOpt(u, V.usage[u] + ' — ' + V.usageHint[u], p.usage)).join('')}</select></label>
        <label class="switch" data-rights hidden><input type="checkbox" name="rights"><span>${V.rights}</span></label>
        <label class="switch"><input type="checkbox" name="for_report"${p.for_report ? ' checked' : ''}><span>${V.forReportSwitch}</span></label>
        <p class="hint">${V.usageNote}</p>
        <div class="row-btns"><button type="button" class="btn ghost" id="vp-del">🗑️ ${V.deletePhoto}</button></div>`
        : `<p class="hint">${esc(p.caption || '')}${p.place ? ' · ' + esc(p.place) : ''}</p><div class="chips"><span class="chip">${esc(V.usage[p.usage])}</span></div>`}`,
        manage ? async f => {
            const usage = f.usage, body = { photo: p.pid, rev: p.rev, caption: f.caption, place: f.place, usage, for_report: f.for_report === 'on' };
            if (usage === 'report' && p.usage !== 'report') body.confirm_rights = f.rights === 'on';
            await api('visit-photo-update', { body });
            (reload || (() => viewVisit(location.hash.split('/')[2], VS.pid)))();
        } : null);
    if (!manage) return;
    const sel = $('[name="usage"]', dlg), rights = $('[data-rights]', dlg);
    const sync = () => { rights.hidden = !(sel.value === 'report' && p.usage !== 'report'); };
    sel.addEventListener('change', sync);
    sync();
    $('#vp-del', dlg).addEventListener('click', async () => {
        if (!confirm(V.deletePhotoAsk)) return;
        try { await api('visit-photo-delete', { body: { photo: p.pid, rev: p.rev } }); dlg.close(); (reload || (() => viewVisit(location.hash.split('/')[2], VS.pid)))(); } catch (err) { toastError(err); }
    });
}

// ── סיום ──
function vFinishSheet(pid, preset, reload) {
    const x = VS.x, v = x.visit, miss = x.missing || [];
    const canFill = x.perms.fill && v.status === 'in_progress';
    const results = preset === 'postponed' && !canFill ? ['postponed'] : canFill ? ['completed', 'postponed', 'no_access'] : ['postponed', 'no_access'];
    const dlg = sheet(V.finish, `
        ${miss.length ? `<p class="banner" role="note">${esc(V.missingTitle(miss.length))}</p><ul class="list">${miss.map(m => `<li class="card static"><div class="body"><div class="l1"><span>${esc(m.label)}${m.row ? ' · ' + m.row : ''}</span></div><div class="l2">${esc(V.missingWhy[m.why] || '')}</div></div></li>`).join('')}</ul>` : canFill ? `<p class="hint">✅ ${V.allFilled}</p>` : ''}
        <fieldset class="f"><legend>${V.result}</legend>${results.map(r => `<label class="switch"><input type="radio" name="result" value="${r}"${r === preset || results.length === 1 ? ' checked' : ''}><span><b>${esc(V.results[r])}</b> — ${esc(V.resultHint[r])}</span></label>`).join('')}</fieldset>
        <label class="f" data-for="other" hidden><span>${V.whatHappened} *</span><textarea name="note" rows="3" maxlength="1000"></textarea></label>
        <label class="f" data-for="postponed" hidden><span>${V.newTime}</span><input type="datetime-local" name="scheduled_at"></label>
        <label class="f" data-for="override" hidden><span>${V.overrideReason} *</span><textarea name="override_reason" rows="2" maxlength="500"></textarea></label>
        <p class="hint" data-for="completed" hidden>${V.completeHint}</p>`, async f => {
        const body = { visit: VS.pid, rev: VS.x.visit.rev, result: f.result };
        if (f.result !== 'completed') body.note = f.note;
        if (f.result === 'postponed' && f.scheduled_at) body.scheduled_at = f.scheduled_at.slice(0, 16);
        if (f.result === 'completed' && f.override_reason) body.override_reason = f.override_reason;
        try {
            const r = await api('visit-finish', { body });
            if (f.result === 'completed') toast(V.completedToast(r.facts || 0, (r.skipped || []).length, r.tasks || 0));
            reload();
        } catch (e) {
            if (e.code === 'visit_incomplete') { VS.x.missing = e.missing; VS.canOverride = e.can_override; dlg.close(); vFinishSheet(pid, 'completed', reload); return false; }
            throw e;
        }
    }, V.finishDo);
    const sync = () => {
        const r = dlg.querySelector('[name="result"]:checked')?.value;
        const show = (k, on) => { const el = dlg.querySelector(`[data-for="${k}"]`); if (el) el.hidden = !on; };
        show('other', r && r !== 'completed'); show('postponed', r === 'postponed'); show('completed', r === 'completed' && !miss.length);
        show('override', r === 'completed' && miss.length && (VS.canOverride || !x.form.completion.block_on_required));
    };
    dlg.querySelectorAll('[name="result"]').forEach(el => el.addEventListener('change', sync));
    sync();
}

function myVisitsHtml(rows) {
    if (!rows.length) return '';
    return `<h2 class="sec-title">🏠 ${V.myVisits} <small>${rows.length}</small></h2><ul class="list">${rows.slice(0, 5).map(v => `<li class="card" tabindex="0" role="button" data-go="#/cases/${v.case.pid}/visits/${v.pid}"><span class="use-ic" aria-hidden="true">${V.statusIcon[v.status] || '🏠'}</span>
        <div class="body"><div class="l1"><span>${esc(v.case.title || v.case.number)}</span><span class="t">${esc(vWhen(v.scheduled_at))}</span></div>
        <div class="l2 wrap"><span dir="ltr">${esc(v.case.number)}</span> · ${esc(V.visitN(v.seq))}</div><div class="chips">${vStatusChip(v.status)}</div></div></li>`).join('')}</ul>`;
}

// ── עורך הפרופיל: בונה הטופס ──
const vNewKey = (prefix, taken) => { let k; do { k = prefix + '_' + Math.random().toString(36).slice(2, 7); } while (taken.includes(k)); return k; };

function peVisit(body) {
    const k = PE.content.visit = PE.content.visit || { groups: [], fields: [], completion: { block_on_required: true }, example: false };
    const L = PL || {};
    const groups = () => [...k.groups].sort((a, b) => a.sort - b.sort);
    const fieldsOf = g => k.fields.filter(f => f.group === g.key).sort((a, b) => a.sort - b.sort);
    const renum = list => list.forEach((it, i) => { it.sort = i + 1; });
    const changed = () => { k.example = false; peChanged(); peVisit(body); };
    body.innerHTML = `<div class="panel"><p class="hint">${V.b.hint}</p>
        ${k.example ? `<p class="banner" role="note">${V.b.example}</p>` : ''}
        ${groups().map((g, gi, all) => `<div class="group"><div class="group-head"><b>${esc(g.title || g.key)}</b>
              <span class="chips">${g.repeat ? `<span class="chip info">${V.b.repeat}</span>` : ''}${g.when ? `<span class="chip">${V.b.conditional}</span>` : ''}</span>
              <span class="v-ord"><button type="button" class="icon-btn" data-g-up="${esc(g.key)}" aria-label="${V.moveUp}"${gi ? '' : ' disabled'}>▲</button><button type="button" class="icon-btn" data-g-down="${esc(g.key)}" aria-label="${V.moveDown}"${gi < all.length - 1 ? '' : ' disabled'}>▼</button>
              <button type="button" class="icon-btn" data-g-edit="${esc(g.key)}" aria-label="${V.b.editGroup}">✎</button></span></div>
            <ul class="list">${fieldsOf(g).map((f, fi, fs) => `<li class="card" tabindex="0" role="button" data-f-edit="${esc(f.key)}"><div class="body"><div class="l1"><span>${esc(f.label || f.key)}</span><span class="t">${esc(V.b.types[f.type] || f.type)}</span></div>
                <div class="chips">${f.required ? `<span class="chip warn">${V.required}</span>` : ''}${f.when ? `<span class="chip">${V.b.conditional}</span>` : ''}${f.maps_to ? `<span class="chip ok">→ ${esc((L.attributes || []).find(a => a.key === f.maps_to)?.label || f.maps_to)}</span>` : ''}${f.creates_task ? `<span class="chip info">${V.b.createsTask}</span>` : ''}</div></div>
                <span class="v-ord"><button type="button" class="icon-btn" data-f-up="${esc(f.key)}" aria-label="${V.moveUp}"${fi ? '' : ' disabled'}>▲</button><button type="button" class="icon-btn" data-f-down="${esc(f.key)}" aria-label="${V.moveDown}"${fi < fs.length - 1 ? '' : ' disabled'}>▼</button></span></li>`).join('')}</ul>
            <div class="row-btns"><button type="button" class="btn ghost sm" data-f-add="${esc(g.key)}"><span aria-hidden="true">+</span> ${V.b.addField}</button></div></div>`).join('')}
        <div class="row-btns wrap"><button type="button" class="btn ghost" id="pv-gadd"><span aria-hidden="true">+</span> ${V.b.addGroup}</button><button type="button" class="btn ghost" id="pv-preview">📱 ${V.b.preview}</button></div>
        <label class="switch"><input type="checkbox" id="pv-block"${k.completion.block_on_required ? ' checked' : ''}><span>${V.b.block}</span></label></div>`;
    const swap = (list, key, d) => { const i = list.findIndex(x => x.key === key); [list[i], list[i + d]] = [list[i + d], list[i]]; renum(list); changed(); };
    $$('[data-g-up]', body).forEach(b => b.addEventListener('click', () => swap(groups(), b.dataset.gUp, -1)));
    $$('[data-g-down]', body).forEach(b => b.addEventListener('click', () => swap(groups(), b.dataset.gDown, 1)));
    const fswap = (key, d) => swap(fieldsOf(k.groups.find(g => g.key === k.fields.find(f => f.key === key).group)), key, d);
    $$('[data-f-up]', body).forEach(b => b.addEventListener('click', e => { e.stopPropagation(); fswap(b.dataset.fUp, -1); }));
    $$('[data-f-down]', body).forEach(b => b.addEventListener('click', e => { e.stopPropagation(); fswap(b.dataset.fDown, 1); }));
    $$('[data-g-edit]', body).forEach(b => b.addEventListener('click', () => peVisitGroup(k, k.groups.find(g => g.key === b.dataset.gEdit), changed)));
    $('#pv-gadd').addEventListener('click', () => peVisitGroup(k, null, changed));
    cOn('[data-f-edit]', el => peVisitField(k, k.fields.find(f => f.key === el.dataset.fEdit), null, changed), body);
    $$('[data-f-add]', body).forEach(b => b.addEventListener('click', () => peVisitField(k, null, b.dataset.fAdd, changed)));
    $('#pv-block').addEventListener('change', e => { k.completion.block_on_required = e.target.checked; peChanged(); });
    $('#pv-preview').addEventListener('click', () => peVisitPreview(k));
}

function peVisitGroup(k, g, done) {
    const dlg = sheet(g ? V.b.editGroup : V.b.addGroup, `<label class="f"><span>${V.b.groupTitle} *</span><input name="title" maxlength="120" required value="${esc(g?.title || '')}"></label>
        <label class="switch"><input type="checkbox" name="repeat"${g?.repeat ? ' checked' : ''}><span>${V.b.repeatSwitch}</span></label>
        <label class="f"><span>${V.b.minRows}</span><input name="min_rows" inputmode="numeric" value="${esc(g?.min_rows || 0)}"></label>
        ${g ? `<div class="row-btns"><button type="button" class="btn ghost" id="pvg-del">🗑️ ${V.b.deleteGroup}</button></div>` : ''}`, f => {
        const rec = { title: f.title.trim(), repeat: f.repeat === 'on', min_rows: f.repeat === 'on' ? Math.max(0, Math.min(50, parseInt(f.min_rows, 10) || 0)) : 0 };
        if (g) Object.assign(g, rec); else k.groups.push({ key: vNewKey('g', k.groups.map(x => x.key)), sort: k.groups.length + 1, when: null, ...rec });
        done();
    });
    $('#pvg-del', dlg)?.addEventListener('click', () => {
        if (!confirm(V.b.deleteGroupAsk)) return;
        k.fields = k.fields.filter(f => f.group !== g.key); k.groups = k.groups.filter(x => x !== g);
        dlg.close(); done();
    });
}

function peVisitField(k, f, groupKey, done) {
    const L = PL || {}, types = L.visit_field_types || Object.keys(V.b.types);
    const ordered = [...k.groups].sort((a, b) => a.sort - b.sort).flatMap(g => k.fields.filter(x => x.group === g.key).sort((a, b) => a.sort - b.sort));
    const before = (f ? ordered.slice(0, ordered.indexOf(f)) : ordered).filter(x => x.type !== 'photo');
    const leaf = f?.when && f.when.field ? f.when : null, complex = f?.when && !leaf;
    const dlg = sheet(f ? V.b.editField : V.b.addField, `
        <label class="f"><span>${V.b.label} *</span><input name="label" maxlength="160" required value="${esc(f?.label || '')}"></label>
        <label class="f"><span>${V.b.type}</span><select name="type">${types.map(t => cOpt(t, V.b.types[t] || t, f?.type || 'text')).join('')}</select></label>
        <label class="f"><span>${V.b.group}</span><select name="group">${k.groups.map(g => cOpt(g.key, g.title || g.key, f?.group || groupKey)).join('')}</select></label>
        <label class="f"><span>${V.b.fieldHint}</span><input name="hint" maxlength="300" value="${esc(f?.hint || '')}"></label>
        <label class="switch"><input type="checkbox" name="required"${f?.required ? ' checked' : ''}><span>${V.b.requiredSwitch}</span></label>
        <label class="f" data-t="choice multichoice"><span>${V.b.options}</span><textarea name="options" rows="4">${esc((f?.options || []).map(o => o.label).join('\n'))}</textarea></label>
        <label class="f" data-t="photo"><span>${V.b.minPhotos}</span><input name="min" inputmode="numeric" value="${esc(f?.min || 1)}"></label>
        <label class="f" data-t="text longtext number area length yesno date choice"><span>${V.b.mapsTo}</span><select name="maps_to"><option value="">${V.b.noMap}</option>${(L.attributes || []).map(a => cOpt(a.key, a.label, f?.maps_to || '')).join('')}</select></label>
        <label class="switch" data-t="text longtext"><input type="checkbox" name="creates_task"${f?.creates_task ? ' checked' : ''}><span>${V.b.createsTaskSwitch}</span></label>
        <fieldset class="f"><legend>${V.b.when}</legend>${complex ? `<p class="hint">${V.b.complexWhen}</p><label class="switch"><input type="checkbox" name="when_clear"><span>${V.b.clearWhen}</span></label>` : `
          <select name="when_field" aria-label="${V.b.whenField}"><option value="">${V.b.always}</option>${before.map(x => cOpt(x.key, x.label || x.key, leaf ? leaf.field.slice(7) : '')).join('')}</select>
          <select name="when_op" aria-label="${V.b.whenOp}">${cOpt('is', V.b.ops.is, leaf?.op || 'is')}${cOpt('is_not', V.b.ops.is_not, leaf?.op)}</select>
          <input name="when_value" maxlength="80" aria-label="${V.b.whenValue}" placeholder="${V.b.whenValuePh}" value="${esc(leaf ? (leaf.value === true ? V.yes : leaf.value === false ? V.no : leaf.value) : '')}">`}</fieldset>
        <p class="hint">${V.b.whenHint}</p>
        ${f ? `<div class="row-btns"><button type="button" class="btn ghost" id="pvf-del">🗑️ ${V.b.deleteField}</button></div>` : ''}`, v => {
        const type = v.type, old = f?.options || [];
        const rec = { label: v.label.trim(), type, group: v.group, hint: (v.hint || '').trim(), required: v.required === 'on', maps_to: ['multichoice', 'photo'].includes(type) ? null : v.maps_to || null,
            creates_task: ['text', 'longtext'].includes(type) && v.creates_task === 'on' };
        if (['choice', 'multichoice'].includes(type)) {
            // אפשרות שומרת על המפתח שלה לפי המיקום — כך תשובות ותנאים קיימים אינם נשברים כשמתקנים ניסוח
            rec.options = (v.options || '').split('\n').map(s => s.trim()).filter(Boolean).slice(0, 40).map((label, i) => ({ key: old[i]?.key || vNewKey('o', old.map(o => o.key)), label }));
        }
        if (type === 'photo') rec.min = Math.max(1, Math.min(20, parseInt(v.min, 10) || 1));
        if (!complex) {
            const t = before.find(x => x.key === v.when_field);
            if (!t) rec.when = null;
            else {
                const raw = (v.when_value || '').trim();
                const value = t.type === 'yesno' ? [V.yes, 'כן', 'true', '1'].includes(raw) : t.type === 'choice' || t.type === 'multichoice' ? (t.options.find(o => o.label === raw || o.key === raw)?.key ?? raw) : raw;
                rec.when = { field: 'answer.' + t.key, op: v.when_op, value };
            }
        } else if (v.when_clear === 'on') rec.when = null;
        if (f) { const moved = f.group !== rec.group; delete f.options; delete f.min; Object.assign(f, rec); if (moved) f.sort = k.fields.filter(x => x.group === rec.group).length; }
        else k.fields.push({ key: vNewKey('f', k.fields.map(x => x.key)), sort: k.fields.filter(x => x.group === rec.group).length + 1, when: null, ...rec });
        done();
    });
    const sel = $('[name="type"]', dlg);
    const sync = () => $$('[data-t]', dlg).forEach(el => { el.hidden = !el.dataset.t.split(' ').includes(sel.value); });
    sel.addEventListener('change', sync);
    sync();
    $('#pvf-del', dlg)?.addEventListener('click', () => { if (!confirm(V.b.deleteFieldAsk)) return; k.fields = k.fields.filter(x => x !== f); dlg.close(); done(); });
}

/** "כפי שייראה בטלפון": אותו ציור של מסך הביקור, ברוחב של טלפון, עם תשובות מקומיות — כדי לראות שדה מותנה מופיע ונעלם. דבר אינו נשמר. */
function peVisitPreview(k) {
    const groups = [...k.groups].sort((a, b) => a.sort - b.sort);
    const x = { form: { groups, fields: groups.flatMap(g => k.fields.filter(f => f.group === g.key).sort((a, b) => a.sort - b.sort)), completion: k.completion },
        rows: Object.fromEntries(groups.filter(g => g.repeat).map(g => [g.key, [1]])), photos: [], case: { property_type: null, purpose: null },
        units: { area: ['sqm', 'dunam'], length: ['m', 'cm'] }, area_kinds: Object.keys(V.areaKinds), obtained: Object.keys(V.obtained) };
    const answers = new Map();
    const dlg = sheet(V.b.preview, `<p class="hint">${V.b.previewHint}</p><div class="v-phone"><div id="pv-form"></div></div>`, null);
    const draw = () => {
        const root = $('#pv-form', dlg), focus = document.activeElement?.id;
        root.innerHTML = vFormHtml(x, answers, false);
        $$('[data-shoot], [data-row-add], [data-row-del]', root).forEach(b => { b.disabled = true; });
        if (focus) document.getElementById(focus)?.focus();
    };
    const set = (f, row, value) => { if (value === null || value === '') answers.delete(vKey(f.key, row)); else answers.set(vKey(f.key, row), { value }); draw(); };
    dlg.querySelector('.v-phone').addEventListener('change', e => {
        const el = e.target.closest('[data-vf]');
        if (!el || el.dataset.part) return;
        const f = x.form.fields.find(q => q.key === el.dataset.vf), row = +el.dataset.row;
        if (f.type === 'multichoice') return set(f, row, [...dlg.querySelectorAll(`[data-opt][data-vf="${CSS.escape(f.key)}"]:checked`)].map(c => c.dataset.opt));
        set(f, row, el.value.trim());
    });
    dlg.querySelector('.v-phone').addEventListener('click', e => {
        const b = e.target.closest('[data-yn]');
        if (!b) return;
        const f = x.form.fields.find(q => q.key === b.dataset.vf);
        set(f, +b.dataset.row, b.getAttribute('aria-pressed') === 'true' ? null : b.dataset.yn === 'true');
    });
    draw();
}
