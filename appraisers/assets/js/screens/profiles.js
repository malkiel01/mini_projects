/*
 * פרופילים (אפיון 03 §7): הספרייה, צפייה, העורך (פרטים · מסמכים · משימות · כללים · שיתוף · היסטוריה), תצוגת ניסיון,
 * וסוגי המסמך של מנהל המערכת. כל הרשאה נאכפת בשרת; כאן רק מסתירים מה שלא רלוונטי.
 *
 * העורך מחזיק את הטיוטה בזיכרון (PE) ושומר אותה לבד, עם ה-revision שקרא: אם מישהו אחר שמר בינתיים — השרת עונה 409,
 * והמסך מציע לטעון מחדש או לשמור כעותק. אין דריסה שקטה.
 */
'use strict';

const P = T.p;
let PL = null;                 // הרשימות הסגורות (profile-lists)
const PE = { pid: null };      // מצב העורך

const pLists = async () => PL || (PL = await api('profile-lists'));
const newKey = p => p + '_' + Math.random().toString(36).slice(2, 8);
const opt = (v, label, cur) => `<option value="${esc(v)}"${v === cur ? ' selected' : ''}>${esc(label)}</option>`;
const docTypeName = k => PL.doc_types.find(d => d.key === k)?.name || k;
const dueText = d => d.mode === 'relative' ? P.dueDays(d.days) : P.dueModes[d.mode];
const scopeChip = p => `<span class="chip info">${P.scopes[p.scope]}</span>${p.is_sample ? `<span class="chip warn">${P.sample}</span>` : ''}${
    p.status === 'archived' ? `<span class="chip bad">${P.archived}</span>` : p.status === 'draft' ? `<span class="chip">${P.draftOnly}</span>` : ''}`;

// ── תנאים: טקסט לקריאה, ועורך פשוט (כל התנאים יחד — "וגם") ────────

function leafText(l, flags) {
    const [kind, name] = l.field.split('.');
    if (kind === 'flags') return (flags.find(f => f.key === name)?.title || name) + (l.value ? '' : ' — ' + P.no);
    if (kind === 'party') return P.hasParty(P.partyKinds[name]) + (l.value ? '' : ' — ' + P.no);
    const map = l.field === 'purpose' ? P.purposes : P.propertyTypes;
    const vals = [].concat(l.value).map(v => map[v] || v).join(' / ');
    return `${l.field === 'purpose' ? P.purpose : P.propertyType} ${P.ops[l.op]} ${vals}`;
}
function condText(c, flags) {
    if (!c) return '';
    if (c.field) return leafText(c, flags);
    const kids = (c.all || c.any).map(k => k.field ? leafText(k, flags) : `(${condText(k, flags)})`);
    return kids.join(c.all ? ` ${P.and} ` : ` ${P.or} `);
}
/** התנאי פשוט מספיק לעורך: null, או all של עלים עם is. */
const condSimple = c => !c || (c.all && c.all.every(k => k.field && k.op === 'is'));

function condFieldOptions(flags, cur) {
    return opt('purpose', P.purpose, cur) + opt('property_type', P.propertyType, cur)
        + flags.map(f => opt('flags.' + f.key, P.flagPrefix + (f.title || f.key), cur)).join('')
        + PL.party_kinds.map(k => opt('party.' + k, P.hasParty(P.partyKinds[k]), cur)).join('');
}
function condValueOptions(field, cur) {
    if (field === 'purpose') return PL.purposes.map(v => opt(v, P.purposes[v], cur)).join('');
    if (field === 'property_type') return PL.property_types.map(v => opt(v, P.propertyTypes[v], cur)).join('');
    return opt('true', P.yes, String(cur)) + opt('false', P.no, String(cur));
}
const condRowHtml = (l, flags) => `<div class="cond-row"><select data-cf aria-label="${P.condField}">${condFieldOptions(flags, l.field)}</select>
    <select data-cv aria-label="${P.condValue}">${condValueOptions(l.field, l.value)}</select>
    <button type="button" class="icon-btn" data-cdel aria-label="${P.remove}">✕</button></div>`;

function condHtml(when, flags) {
    if (!condSimple(when)) return `<div class="f" data-cond-keep><span>${P.when}</span><p class="hint">${P.condComplex}: ${esc(condText(when, flags))}</p></div>`;
    return `<div class="f" data-cond><span>${P.when}</span><div data-crows>${(when?.all || []).map(l => condRowHtml(l, flags)).join('')}</div>
        <button type="button" class="btn sm ghost" data-cadd>+ ${P.condAdd}</button><p class="hint">${P.condHint}</p></div>`;
}
function condBind(root, flags) {
    const box = $('[data-cond]', root);
    if (!box) return;
    box.addEventListener('click', e => {
        if (e.target.closest('[data-cadd]')) $('[data-crows]', box).insertAdjacentHTML('beforeend', condRowHtml({ field: 'purpose', value: PL.purposes[0] }, flags));
        e.target.closest('[data-cdel]')?.closest('.cond-row').remove();
    });
    box.addEventListener('change', e => {
        if (e.target.matches('[data-cf]')) $('[data-cv]', e.target.closest('.cond-row')).innerHTML = condValueOptions(e.target.value, null);
    });
}
/** קורא את התנאי מהגיליון; prev — התנאי הקודם, שנשמר כמו שהוא כשהוא מורכב מדי לעורך. */
function condRead(root, prev) {
    if (!$('[data-cond]', root)) return prev ?? null;
    const rows = $$('.cond-row', root).map(r => {
        const field = $('[data-cf]', r).value, v = $('[data-cv]', r).value;
        return { field, op: 'is', value: field === 'purpose' || field === 'property_type' ? v : v === 'true' };
    });
    return rows.length ? { all: rows } : null;
}

// ── גרירה לשינוי סדר — עובדת במגע (pointer events) ────────────────

function dragSort(root, itemSel, onDrop) {
    root.addEventListener('pointerdown', e => {
        const h = e.target.closest('[data-drag]');
        if (!h) return;
        const li = h.closest(itemSel);
        e.preventDefault();
        li.classList.add('dragging');
        const move = ev => {
            const el = document.elementFromPoint(ev.clientX, ev.clientY)?.closest(itemSel + ', [data-drop]');
            if (!el || el === li || !root.contains(el)) return;
            const r = el.getBoundingClientRect();
            const after = el.matches('[data-drop]') || ev.clientY > r.top + r.height / 2;
            el.parentNode.insertBefore(li, after ? el.nextSibling : el);
        };
        // מאזינים על המסמך: הזזת השורה ב-DOM מבטלת את לכידת המצביע, ואז pointerup לא היה מגיע לידית
        const up = () => {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            document.removeEventListener('pointercancel', up);
            li.classList.remove('dragging');
            onDrop();
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
        document.addEventListener('pointercancel', up);
    });
}

// ── הספרייה ───────────────────────────────────────────────────

function profileCard(p) {
    const tags = [...p.purposes.map(v => P.purposes[v]), ...p.property_types.map(v => P.propertyTypes[v])].join(' · ');
    return `<li class="card" tabindex="0" role="button" data-profile="${p.pid}"><span class="use-ic" aria-hidden="true">📑</span>
        <div class="body"><div class="l1"><span>${esc(p.name)}</span><span class="t">${p.version ? P.versionN(p.version.number) : ''}</span></div>
        <div class="l2 wrap">${esc(p.description || tags)}</div>
        <div class="chips">${scopeChip(p)}${p.owner && p.scope !== 'recommended' ? `<span class="chip">${esc(p.owner)}</span>` : ''}${p.has_draft && p.can.edit && p.version ? `<span class="chip">${P.hasDraft}</span>` : ''}</div></div></li>`;
}

async function viewProfiles(pid, sub, arg) {
    await pLists();
    if (pid) return sub === 'edit' ? viewProfileEdit(pid) : sub === 'try' ? viewProfileTry(pid, arg === 'draft') : viewProfile(pid, sub === 'v' ? arg : '');
    setHead(P.title, null);
    const tab = sessionStorage.getItem('apr-ptab') || 'recommended';
    const q = sessionStorage.getItem('apr-pq') || '';
    const d = await api('profiles', { params: q ? { q } : {} });
    const tabs = ['recommended', 'office', 'mine', 'shared'];
    const cur = tabs.includes(tab) ? tab : 'recommended';
    const canNew = d.can_create.personal || d.can_create.recommended;
    App.main.innerHTML = `<div class="seg-tabs" role="tablist">${tabs.map(t => `<button role="tab" aria-selected="${t === cur}" data-ptab="${t}">${P.tabs[t]} <small>${d[t].length || ''}</small></button>`).join('')}</div>
        <div class="toolbar"><input type="search" id="pq" value="${esc(q)}" placeholder="${P.search}" aria-label="${P.search}" style="flex:1">
          ${canNew ? `<button class="btn primary" id="pnew"><span aria-hidden="true">+</span> ${P.newProfile}</button>` : ''}</div>
        ${cur === 'recommended' ? `<p class="hint">${P.recommendedHint}</p>` : ''}
        ${d[cur].length ? `<ul class="list">${d[cur].map(profileCard).join('')}</ul>` : `<p class="empty">${q ? P.noResults : P.emptyTab[cur]}</p>`}`;
    $$('[data-ptab]').forEach(b => b.addEventListener('click', () => { sessionStorage.setItem('apr-ptab', b.dataset.ptab); viewProfiles(); }));
    $('#pq').addEventListener('change', e => { sessionStorage.setItem('apr-pq', e.target.value.trim()); viewProfiles(); });
    $$('[data-profile]').forEach(li => onActivate(li, () => {
        const p = d[cur].find(x => x.pid === li.dataset.profile);
        location.hash = '#/profiles/' + p.pid + (p.version ? '' : '/edit');
    }));
    $('#pnew')?.addEventListener('click', () => {
        const scopes = [d.can_create.personal && 'personal', d.can_create.office && 'office', d.can_create.recommended && 'recommended'].filter(Boolean);
        sheet(P.newProfile, `<label class="f"><span>${P.name}</span><input name="name" required maxlength="120"></label>
            <div class="f"><span>${P.scope}</span><fieldset class="seg" aria-label="${P.scope}">${scopes.map((s, i) =>
                `<label><input type="radio" name="scope" value="${s}"${i ? '' : ' checked'}><span>${P.scopes[s]}</span></label>`).join('')}</fieldset></div>
            <p class="hint">${P.newHint}</p>`, async f => {
            const r = await api('profile-create', { body: { name: f.name, scope: f.scope } });
            location.hash = '#/profiles/' + r.profile + '/edit';
        }, P.create);
    });
}

// ── תוכן לקריאה בלבד ──────────────────────────────────────────

function reqLine(r, flags) {
    return `<li class="card static"><div class="body"><div class="l1"><span>${esc(r.title || P.untitled)}</span><span class="t">${esc(dueText(r.due))}</span></div>
        <div class="l2 wrap">${esc(docTypeName(r.doc_type))}<i class="sep" aria-hidden="true">·</i>${P.partyKinds[r.party_kind]}${r.when ? `<i class="sep" aria-hidden="true">·</i>${P.when}: ${esc(condText(r.when, flags))}` : ''}${
            r.rule ? `<i class="sep" aria-hidden="true">·</i>${P.byRule(esc(r.rule.title || r.rule.key))}` : ''}</div>
        ${r.explain_recipient ? `<div class="l2 wrap muted">${esc(r.explain_recipient)}</div>` : ''}</div>
        ${r.mandatory ? `<span class="chip warn">${P.mandatory}</span>` : ''}${r.priority === 'high' ? `<span class="chip bad">${P.priorities.high}</span>` : ''}</li>`;
}
function taskLine(t, flags) {
    return `<li class="card static"><span class="use-ic" aria-hidden="true">☑️</span><div class="body"><div class="l1"><span>${esc(t.title || P.untitled)}</span><span class="t">${esc(dueText(t.due))}</span></div>
        <div class="l2 wrap">${P.assignees[t.assignee]}${t.when ? `<i class="sep" aria-hidden="true">·</i>${P.when}: ${esc(condText(t.when, flags))}` : ''}${
            t.rule ? `<i class="sep" aria-hidden="true">·</i>${P.byRule(esc(t.rule.title || t.rule.key))}` : ''}</div></div></li>`;
}
function groupedReqs(groups, items, flags) {
    const sorted = [...groups].sort((a, b) => a.sort - b.sort);
    const keys = sorted.map(g => g.key);
    const blocks = [...sorted, { key: '', title: P.noGroup }].map(g => {
        const mine = items.filter(r => g.key ? r.group === g.key : !keys.includes(r.group)).sort((a, b) => a.sort - b.sort);
        return mine.length ? `<p class="sec-title">${esc(g.title || g.key)}</p><ul class="list">${mine.map(r => reqLine(r, flags)).join('')}</ul>` : '';
    }).join('');
    return blocks || `<p class="empty">${P.noReqs}</p>`;
}
function contentHtml(c) {
    return `<h3 class="sec-title">📄 ${P.tabDocs}</h3>${groupedReqs(c.requirements.groups, c.requirements.items, c.flags)}
        <h3 class="sec-title">☑️ ${P.tabTasks}</h3>${c.tasks.items.length ? `<ul class="list">${[...c.tasks.items].sort((a, b) => a.sort - b.sort).map(t => taskLine(t, c.flags)).join('')}</ul>` : `<p class="empty">${P.noTasks}</p>`}
        <h3 class="sec-title">🔀 ${P.tabRules}</h3>${c.rules.length ? `<ul class="list">${c.rules.map(r => `<li class="card static"><div class="body"><div class="l1"><span>${esc(r.title || P.untitled)}</span></div>
            <div class="l2 wrap">${P.when}: ${esc(condText(r.when, c.flags) || P.always)}</div>
            <div class="l2 wrap">${r.then.map(a => esc(P.actions[a.do] + ': ' + (a.do === 'alert' ? a.text : a.item.title))).join('<br>')}</div></div></li>`).join('')}</ul>` : `<p class="empty">${P.noRules}</p>`}`;
}

// ── צפייה ─────────────────────────────────────────────────────

async function viewProfile(pid, versionPid) {
    const d = await api('profile', { params: { profile: pid } });
    const p = d.profile;
    let shown = d, old = false;
    if (versionPid) { shown = await api('profile-version', { params: { version: versionPid } }); old = shown.version.number !== p.version?.number; }
    setHead(p.name, null);
    const v = shown.version;
    App.main.innerHTML = `<div class="summary"><div class="line"><b class="one">${esc(p.name)}</b></div>
          ${p.description ? `<div class="line muted">${esc(p.description)}</div>` : ''}
          <div class="chips">${scopeChip(p)}${v ? `<span class="chip">${P.versionN(v.number)}</span>` : ''}</div>
          ${p.source ? `<div class="line muted">${esc(P.copiedFrom(p.source.name + (p.source.archived ? ' ' + P.inArchive : ''), p.source.number))}${p.source.newer ? ' · ' + P.sourceNewer : ''}</div>` : ''}</div>
        ${p.is_sample ? `<p class="banner" role="note">${P.sampleHint}</p>` : ''}
        ${old ? `<p class="banner" role="note">${esc(P.oldVersion(v.number, fmtDate(v.published_at)))} <a href="#/profiles/${pid}">${P.toCurrent}</a></p>` : ''}
        <div class="row-btns wrap">${p.can.edit ? `<a class="btn primary" href="#/profiles/${pid}/edit">✏️ ${P.edit}</a>` : ''}
          <a class="btn ghost" href="#/profiles/${pid}/try">🧪 ${P.tryIt}</a>
          ${p.can.duplicate ? `<button class="btn ghost" id="pdup">📄 ${P.duplicate}</button>` : ''}
          <button class="btn ghost" id="phist">🕘 ${P.tabHistory}</button></div>
        ${shown.content ? contentHtml(shown.content) : `<p class="empty">${P.notPublished}</p>`}`;
    $('#pdup')?.addEventListener('click', () => duplicateSheet(p));
    $('#phist').addEventListener('click', () => historySheet(pid));
}

function duplicateSheet(p) {
    const canOffice = !!myOffice()?.is_admin;
    sheet(P.duplicate, `<p class="hint">${P.duplicateHint}</p>
        <label class="f"><span>${P.name}</span><input name="name" required maxlength="120" value="${esc((p.name + ' — ' + P.copy).slice(0, 120))}"></label>
        ${canOffice ? `<div class="f"><span>${P.scope}</span><fieldset class="seg"><label><input type="radio" name="scope" value="personal" checked><span>${P.scopes.personal}</span></label>
            <label><input type="radio" name="scope" value="office"><span>${P.scopes.office}</span></label></fieldset></div>` : ''}`, async f => {
        const r = await api('profile-duplicate', { body: { profile: p.pid, name: f.name, scope: f.scope || 'personal' } });
        toast(P.duplicated);
        location.hash = '#/profiles/' + r.profile + '/edit';
    }, P.duplicate);
}

const diffHtml = diff => diff.total ? ['requirements', 'tasks', 'rules', 'groups', 'flags'].map(s => {
    const x = diff[s], line = (sign, cls, arr) => arr.map(i => `<li class="${cls}">${sign} ${esc(i.title || i.key)}</li>`).join('');
    return x.added.length + x.removed.length + x.changed.length ? `<p class="sec-title">${P.diffSections[s]}</p><ul class="diff">${line('＋', 'add', x.added)}${line('－', 'del', x.removed)}${line('✎', 'chg', x.changed)}</ul>` : '';
}).join('') : `<p class="empty">${P.diffNone}</p>`;

async function historySheet(pid) {
    const { versions } = await api('profile-versions', { params: { profile: pid } });
    const dlg = sheet(P.tabHistory, versions.length ? `<ul class="list">${versions.map(v => `<li class="card static"><div class="body">
            <div class="l1"><span>${P.versionN(v.number)}</span><span class="t">${esc(fmtDate(v.published_at))}</span></div>
            <div class="l2 wrap">${esc(v.published_by || T.system)}${v.change_note ? `<i class="sep" aria-hidden="true">·</i>${esc(v.change_note)}` : ''}</div></div>
            <a class="btn sm ghost" href="#/profiles/${pid}/v/${v.pid}" data-close>${P.view}</a></li>`).join('')}</ul>
        ${versions.length > 1 ? `<p class="sec-title">${P.compare}</p><div class="fields two">
            <label class="f"><span>${P.compareFrom}</span><select id="ca">${versions.map((v, i) => opt(v.pid, P.versionN(v.number), i === 1 ? v.pid : '')).join('')}</select></label>
            <label class="f"><span>${P.compareTo}</span><select id="cb">${versions.map((v, i) => opt(v.pid, P.versionN(v.number), i === 0 ? v.pid : '')).join('')}</select></label></div>
            <div id="cdiff"></div>` : ''}` : `<p class="empty">${P.notPublished}</p>`, null);
    const run = async () => {
        try {
            const r = await api('profile-compare', { params: { profile: pid, a: $('#ca', dlg).value, b: $('#cb', dlg).value } });
            $('#cdiff', dlg).innerHTML = diffHtml(r.diff);
        } catch (x) { toastError(x); }
    };
    if ($('#ca', dlg)) { $('#ca', dlg).addEventListener('change', run); $('#cb', dlg).addEventListener('change', run); run(); }
}

// ── תצוגת ניסיון ──────────────────────────────────────────────

async function viewProfileTry(pid, draft) {
    const d = draft ? await api('profile-draft-open', { body: { profile: pid } }) : await api('profile', { params: { profile: pid } });
    const flags = d.content?.flags || [];
    setHead(P.tryTitle, null);
    App.main.innerHTML = `<p class="hint">${esc(P.tryHint(d.profile.name))}${draft ? ' ' + P.tryDraft : ''}</p>
        <form class="panel" id="tryf">
          <div class="fields two"><label class="f"><span>${P.purpose}</span><select name="purpose"><option value="">—</option>${PL.purposes.map(v => opt(v, P.purposes[v])).join('')}</select></label>
            <label class="f"><span>${P.propertyType}</span><select name="property_type"><option value="">—</option>${PL.property_types.map(v => opt(v, P.propertyTypes[v])).join('')}</select></label></div>
          ${flags.length ? `<div class="f"><span>${P.flags}</span>${flags.map(f => `<label class="switch"><input type="checkbox" data-flag="${esc(f.key)}"><span>${esc(f.question || f.title || f.key)}</span></label>`).join('')}</div>` : ''}
          <div class="f"><span>${P.parties}</span><div class="chips">${PL.party_kinds.filter(k => k !== 'office').map(k => `<label class="chip pick"><input type="checkbox" data-party="${k}"> ${P.partyKinds[k]}</label>`).join('')}</div></div>
        </form><div id="tryout"></div>`;
    const form = $('#tryf');
    const run = async () => {
        const input = { purpose: form.purpose.value || null, property_type: form.property_type.value || null,
            flags: Object.fromEntries($$('[data-flag]', form).map(c => [c.dataset.flag, c.checked])), parties: Object.fromEntries($$('[data-party]', form).map(c => [c.dataset.party, c.checked])) };
        try {
            const { result: r } = await api('profile-try', { body: { profile: pid, draft: !!draft, input } });
            $('#tryout').innerHTML = `${r.alerts.map(a => `<p class="banner" role="note">🔔 ${esc(a.text)} <small>(${P.byRule(esc(a.rule.title || a.rule.key))})</small></p>`).join('')}
                <h3 class="sec-title">📄 ${P.tabDocs} <small>${r.requirements.length}</small></h3>${groupedReqs(r.groups, r.requirements, flags)}
                <h3 class="sec-title">☑️ ${P.tabTasks} <small>${r.tasks.length}</small></h3>${r.tasks.length ? `<ul class="list">${r.tasks.map(t => taskLine(t, flags)).join('')}</ul>` : `<p class="empty">${P.noTasks}</p>`}
                <p class="hint">${P.tryNothingSaved}</p>`;
        } catch (x) { $('#tryout').innerHTML = errorHtml(x); }
    };
    form.addEventListener('change', run);
    run();
}

// ── העורך ─────────────────────────────────────────────────────

function peStatus(state) {
    PE.state = state;
    const el = $('#pe-status');
    if (el) { el.textContent = P.saveState[state]; el.className = 'chip ' + ({ saved: 'ok', saving: 'info', failed: 'bad', dirty: 'info', conflict: 'bad' })[state]; }
}
/** שינוי בטיוטה: מסמנים, ושומרים לבד אחרי רגע. */
function peChanged() {
    PE.dirty = true;
    peStatus('dirty');
    clearTimeout(PE.timer);
    PE.timer = setTimeout(peSave, 900);
}
async function peSave() {
    clearTimeout(PE.timer);
    if (!PE.dirty || PE.saving || PE.state === 'conflict') return;
    PE.saving = true;
    PE.dirty = false;
    peStatus('saving');
    try {
        const r = await api('profile-draft-save', { body: { profile: PE.pid, content: PE.content, revision: PE.revision } });
        PE.revision = r.revision;
        PE.checks = r.checks;
        PE.saving = false;
        if (PE.dirty) return peSave();
        peStatus('saved');
        peChecksDraw();
    } catch (x) {
        PE.saving = false;
        PE.dirty = true;
        if (x.code === 'draft_changed') return peConflict();
        peStatus('failed');
        toastError(x);
    }
}
/** מישהו אחר שמר בינתיים: לא דורסים. טעינה מחדש (מוותרים על השינויים שלי), או שמירה כעותק. */
async function peConflict() {
    peStatus('conflict');
    let who = '';
    try { who = (await api('profile-draft-info', { params: { profile: PE.pid } })).draft.updated_by || ''; } catch { /* נשאר בלי שם */ }
    const dlg = sheet(P.conflictTitle, `<p class="banner" role="alert">${esc(P.conflictText(who))}</p>
        <div class="row-btns wrap"><button type="button" class="btn primary" id="creload">${P.reload}</button><button type="button" class="btn ghost" id="ccopy">${P.saveAsCopy}</button></div>`, null);
    $('#creload', dlg).addEventListener('click', () => { dlg.close(); PE.dirty = false; viewProfileEdit(PE.pid); });
    $('#ccopy', dlg).addEventListener('click', async () => {
        try {
            const r = await api('profile-create', { body: { name: (PE.profile.name + ' — ' + P.copy).slice(0, 120), scope: 'personal' } });
            const d = await api('profile-draft-open', { body: { profile: r.profile } });
            await api('profile-draft-save', { body: { profile: r.profile, content: PE.content, revision: d.draft.revision } });
            dlg.close();
            PE.dirty = false;
            toast(P.savedAsCopy);
            location.hash = '#/profiles/' + r.profile + '/edit';
        } catch (x) { toastError(x); }
    });
}
function peChecksDraw() {
    const el = $('#pe-checks');
    if (!el || !PE.checks) return;
    const { errors, warnings } = PE.checks;
    el.innerHTML = errors.map(e => `<p class="banner bad" role="alert">❌ ${esc(e.message)}</p>`).join('') + warnings.map(w => `<p class="banner" role="note">⚠️ ${esc(w.message)}</p>`).join('');
}
/** יציאה מהעורך (ניווט): שומרים מה שממתין. */
async function peLeave() {
    if (PE.pid && PE.dirty) await peSave();
    PE.pid = null;
}

async function viewProfileEdit(pid) {
    const d = await api('profile-draft-open', { body: { profile: pid } });
    Object.assign(PE, { pid, profile: d.profile, content: d.content, revision: d.draft.revision, checks: d.checks, dirty: false, saving: false, state: 'saved',
        tab: PE.lastPid === pid ? PE.tab || 'docs' : 'docs', lastPid: pid });
    setHead(d.profile.name, null);
    const tabs = ['details', 'docs', 'tasks', 'rules', 'contact', 'sources', ...(d.profile.can.share ? ['share'] : []), 'history'];
    App.main.innerHTML = `<div class="toolbar"><span class="chip ok" id="pe-status" role="status">${P.saveState.saved}</span><span style="flex:1"></span>
          <a class="btn ghost sm" href="#/profiles/${pid}/try/draft">🧪 ${P.tryIt}</a><button class="btn primary sm" id="pe-publish">${P.publish}</button></div>
        <div class="seg-tabs six" role="tablist">${tabs.map(t => `<button role="tab" data-pe-tab="${t}">${P.editTabs[t]}</button>`).join('')}</div>
        <div id="pe-checks"></div><div id="pe-body"></div>`;
    $$('[data-pe-tab]').forEach(b => b.addEventListener('click', () => { PE.tab = b.dataset.peTab; peDraw(); }));
    $('#pe-publish').addEventListener('click', pePublish);
    if (!tabs.includes(PE.tab)) PE.tab = 'docs';
    peChecksDraw();
    peDraw();
}

function peDraw() {
    $$('[data-pe-tab]').forEach(b => b.setAttribute('aria-selected', b.dataset.peTab === PE.tab));
    const body = $('#pe-body');
    ({ details: peDetails, docs: peDocs, tasks: peTasks, rules: peRules, contact: peContact, sources: peSources, share: peShare, history: peHistory })[PE.tab](body);
}

// פרטים
function peDetails(body) {
    const p = PE.profile, o = myOffice();
    const boxes = (name, all, cur, labels) => `<div class="chips">${all.map(v => `<label class="chip pick"><input type="checkbox" name="${name}" value="${v}"${cur.includes(v) ? ' checked' : ''}> ${labels[v]}</label>`).join('')}</div>`;
    body.innerHTML = `<form class="panel" id="pd" novalidate>
        <label class="f"><span>${P.name}</span><input name="name" required maxlength="120" value="${esc(p.name)}"></label>
        <label class="f"><span>${P.description}</span><textarea name="description" rows="2" maxlength="500">${esc(p.description)}</textarea></label>
        <div class="f"><span>${P.purposesFit}</span>${boxes('purposes', PL.purposes, p.purposes, P.purposes)}</div>
        <div class="f"><span>${P.propertyTypesFit}</span>${boxes('property_types', PL.property_types, p.property_types, P.propertyTypes)}</div>
        <p class="error" role="alert" hidden></p><button class="btn primary" type="submit">${T.save}</button></form>
        ${p.source ? `<p class="hint">${esc(P.copiedFrom(p.source.name + (p.source.archived ? ' ' + P.inArchive : ''), p.source.number))}${p.source.newer ? ' · ' + P.sourceNewer : ''}</p>` : ''}
        ${p.can.manage ? `<section class="group"><h3>${P.manage}</h3><div class="row-btns wrap">
            ${p.version ? `<button class="btn ghost" id="parch">${p.status === 'archived' ? P.unarchive : P.archive}</button>` : `<button class="btn ghost danger" id="pdel">${P.deleteDraft}</button>`}
            ${p.version && PE.profile.has_draft ? `<button class="btn ghost" id="pdiscard">${P.discardDraft}</button>` : ''}
            ${p.scope === 'office' && o?.is_admin && p.status === 'active' ? `<button class="btn ghost" id="preq">${P.requiredSet}</button>` : ''}</div>
            <p class="hint">${p.version ? P.archiveHint : P.deleteHint}</p></section>` : ''}`;
    bindForm($('#pd'), async () => {
        const f = new FormData($('#pd'));
        await api('profile-update', { body: { profile: PE.pid, name: f.get('name'), description: f.get('description'), purposes: f.getAll('purposes'), property_types: f.getAll('property_types') } });
        Object.assign(p, { name: f.get('name'), description: f.get('description'), purposes: f.getAll('purposes'), property_types: f.getAll('property_types') });
        setHead(p.name, null);
        toast(T.saved);
    });
    const act = (sel, fn) => $(sel)?.addEventListener('click', async () => { try { await fn(); } catch (x) { toastError(x); } });
    act('#parch', async () => { await api('profile-archive', { body: { profile: PE.pid, archived: p.status !== 'archived' } }); toast(T.saved); viewProfileEdit(PE.pid); });
    act('#pdel', async () => { if (!confirm(P.deleteAsk(p.name))) return; await api('profile-delete', { body: { profile: PE.pid } }); PE.dirty = false; toast(P.deleted); location.hash = '#/profiles'; });
    act('#pdiscard', async () => { if (!confirm(P.discardAsk)) return; await peSave(); await api('profile-draft-discard', { body: { profile: PE.pid, revision: PE.revision } }); PE.dirty = false; location.hash = '#/profiles/' + PE.pid; });
    act('#preq', async () => { if (!confirm(P.requiredAsk(p.name))) return; await api('office-required-profile', { body: { profile: PE.pid } }); toast(P.requiredDone); });
}

// מסמכים
const dueFields = d => `<div class="fields two"><label class="f"><span>${P.due}</span><select name="due_mode">${PL.due_modes.map(m => opt(m, P.dueModes[m], d.mode)).join('')}</select></label>
    <label class="f"><span>${P.dueDaysLabel}</span><input name="due_days" type="number" inputmode="numeric" min="0" max="365" value="${d.mode === 'relative' ? d.days : 3}"></label></div>`;
const dueRead = f => f.due_mode === 'relative' ? { mode: 'relative', days: Math.max(0, Math.min(365, parseInt(f.due_days, 10) || 0)) } : { mode: f.due_mode };

function peDocs(body) {
    const c = PE.content, R = c.requirements;
    const groups = [...R.groups].sort((a, b) => a.sort - b.sort);
    const keys = groups.map(g => g.key);
    const row = r => `<li class="card" data-req="${esc(r.key)}"><button type="button" class="icon-btn drag" data-drag aria-label="${P.dragHint}">⠿</button>
        <div class="body" tabindex="0" role="button"><div class="l1"><span>${esc(r.title || P.untitled)}</span><span class="t">${esc(dueText(r.due))}</span></div>
        <div class="l2 wrap">${esc(docTypeName(r.doc_type))}<i class="sep" aria-hidden="true">·</i>${P.partyKinds[r.party_kind]}${r.when ? `<i class="sep" aria-hidden="true">·</i>${P.conditional}` : ''}</div></div>
        ${r.mandatory ? `<span class="chip warn">${P.mandatory}</span>` : ''}</li>`;
    const block = g => `<li class="group-head" data-drop data-group="${esc(g.key)}"><b>${esc(g.title || g.key)}</b>${g.key ? `<button type="button" class="icon-btn" data-gedit="${esc(g.key)}" aria-label="${P.groupEdit}">✏️</button>` : ''}</li>`
        + R.items.filter(r => g.key ? r.group === g.key : !keys.includes(r.group)).sort((a, b) => a.sort - b.sort).map(row).join('');
    body.innerHTML = `<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${P.docsHint}</p>
          <button class="btn ghost sm" id="gadd">+ ${P.group}</button><button class="btn primary sm" id="radd">+ ${P.requirement}</button></div>
        <ul class="list" id="reqs">${groups.map(block).join('')}${block({ key: '', title: P.noGroup })}</ul>`;
    const list = $('#reqs');
    dragSort(list, '[data-req]', () => {
        let group = '', n = 0;
        [...list.children].forEach(li => {
            if (li.dataset.group !== undefined) { group = li.dataset.group; n = 0; return; }
            const r = R.items.find(x => x.key === li.dataset.req);
            r.group = group;
            r.sort = ++n;
        });
        peChanged();
    });
    $$('[data-req] .body', list).forEach(el => onActivate(el, () => reqSheet(R.items.find(x => x.key === el.parentNode.dataset.req))));
    $('#radd').addEventListener('click', () => reqSheet(null));
    const groupSheet = g => {
        const dlg = sheet(g ? P.groupEdit : P.group, `<label class="f"><span>${P.groupTitle}</span><input name="title" required maxlength="120" value="${esc(g?.title || '')}"></label>
            ${g ? `<section class="group"><button type="button" class="btn ghost danger" id="gdel">${P.groupDelete}</button><p class="hint">${P.groupDeleteHint}</p></section>` : ''}`, f => {
            if (g) g.title = f.title.trim(); else R.groups.push({ key: newKey('g'), title: f.title.trim(), sort: Math.max(0, ...R.groups.map(x => x.sort)) + 1 });
            peChanged(); peDocs(body);
        });
        $('#gdel', dlg)?.addEventListener('click', () => { R.groups = R.groups.filter(x => x !== g); R.items.forEach(r => { if (r.group === g.key) r.group = ''; }); dlg.close(); peChanged(); peDocs(body); });
    };
    $('#gadd').addEventListener('click', () => groupSheet(null));
    $$('[data-gedit]', list).forEach(b => b.addEventListener('click', () => groupSheet(R.groups.find(g => g.key === b.dataset.gedit))));

    function reqSheet(r) {
        const x = r || { key: newKey('r'), group: groups[0]?.key || '', doc_type: 'other', title: '', explain_recipient: '', explain_internal: '', priority: 'normal', mandatory: false,
            party_kind: 'client', due: { mode: 'relative', days: 3 }, needs_review: true, when: null, sort: R.items.length + 1 };
        const dlg = sheet(r ? P.requirement : P.requirementNew, `
            <label class="f"><span>${P.reqTitle}</span><input name="title" required maxlength="160" value="${esc(x.title)}"></label>
            <div class="fields two"><label class="f"><span>${P.group}</span><select name="group"><option value="">${P.noGroup}</option>${groups.map(g => opt(g.key, g.title || g.key, x.group)).join('')}</select></label>
              <label class="f"><span>${P.docType}</span><select name="doc_type">${PL.doc_types.map(t => opt(t.key, t.name, x.doc_type)).join('')}${PL.doc_types.some(t => t.key === x.doc_type) ? '' : opt(x.doc_type, x.doc_type, x.doc_type)}</select></label></div>
            <div class="fields two"><label class="f"><span>${P.party}</span><select name="party_kind">${PL.party_kinds.map(k => opt(k, P.partyKinds[k], x.party_kind)).join('')}</select></label>
              <label class="f"><span>${P.priority}</span><select name="priority">${PL.priorities.map(k => opt(k, P.priorities[k], x.priority)).join('')}</select></label></div>
            ${dueFields(x.due)}
            <label class="switch"><input type="checkbox" name="mandatory"${x.mandatory ? ' checked' : ''}><span>${P.mandatoryLong}</span></label>
            <label class="switch"><input type="checkbox" name="needs_review"${x.needs_review ? ' checked' : ''}><span>${P.needsReview}</span></label>
            <label class="f"><span>${P.explainRecipient}</span><textarea name="explain_recipient" rows="2" maxlength="600">${esc(x.explain_recipient)}</textarea></label>
            <label class="f"><span>${P.explainInternal}</span><textarea name="explain_internal" rows="2" maxlength="600">${esc(x.explain_internal)}</textarea></label>
            ${condHtml(x.when, c.flags)}
            ${r ? `<section class="group"><div class="row-btns wrap"><button type="button" class="btn ghost" id="rdup">${P.duplicateItem}</button><button type="button" class="btn ghost danger" id="rdel">${P.remove}</button></div></section>` : ''}`, f => {
            Object.assign(x, { title: f.title.trim(), group: f.group, doc_type: f.doc_type, party_kind: f.party_kind, priority: f.priority, due: dueRead(f), mandatory: !!f.mandatory,
                needs_review: !!f.needs_review, explain_recipient: f.explain_recipient.trim(), explain_internal: f.explain_internal.trim(), when: condRead(dlg, x.when) });
            if (!r) R.items.push(x);
            peChanged(); peDocs(body);
        });
        condBind(dlg, c.flags);
        $('#rdup', dlg)?.addEventListener('click', () => { R.items.push({ ...structuredClone(r), key: newKey('r'), title: (r.title + ' — ' + P.copy).slice(0, 160), sort: r.sort + 1 }); dlg.close(); peChanged(); peDocs(body); });
        $('#rdel', dlg)?.addEventListener('click', () => { R.items = R.items.filter(i => i !== r); dlg.close(); peChanged(); peDocs(body); });
    }
}

// משימות
function peTasks(body) {
    const c = PE.content, Tk = c.tasks;
    Tk.items.sort((a, b) => a.sort - b.sort);
    body.innerHTML = `<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${P.tasksHint}</p><button class="btn primary sm" id="tadd">+ ${P.task}</button></div>
        ${Tk.items.length ? `<ul class="list" id="tasks">${Tk.items.map(t => `<li class="card" data-task="${esc(t.key)}"><button type="button" class="icon-btn drag" data-drag aria-label="${P.dragHint}">⠿</button>
            <div class="body" tabindex="0" role="button"><div class="l1"><span>${esc(t.title || P.untitled)}</span><span class="t">${esc(dueText(t.due))}</span></div>
            <div class="l2 wrap">${P.assignees[t.assignee]}${t.when ? `<i class="sep" aria-hidden="true">·</i>${P.conditional}` : ''}</div></div></li>`).join('')}</ul>` : `<p class="empty">${P.noTasks}</p>`}`;
    const list = $('#tasks');
    if (list) {
        dragSort(list, '[data-task]', () => { [...list.children].forEach((li, i) => { Tk.items.find(t => t.key === li.dataset.task).sort = i + 1; }); peChanged(); });
        $$('[data-task] .body', list).forEach(el => onActivate(el, () => taskSheet(Tk.items.find(t => t.key === el.parentNode.dataset.task))));
    }
    $('#tadd').addEventListener('click', () => taskSheet(null));
    function taskSheet(t) {
        const x = t || { key: newKey('t'), title: '', assignee: 'responsible', due: { mode: 'relative', days: 5 }, when: null, sort: Tk.items.length + 1 };
        const dlg = sheet(t ? P.task : P.taskNew, `<label class="f"><span>${P.taskTitle}</span><input name="title" required maxlength="160" value="${esc(x.title)}"></label>
            <label class="f"><span>${P.assignee}</span><select name="assignee">${PL.assignees.map(k => opt(k, P.assignees[k], x.assignee)).join('')}</select></label>
            ${dueFields(x.due)}${condHtml(x.when, c.flags)}
            ${t ? `<section class="group"><button type="button" class="btn ghost danger" id="tdel">${P.remove}</button></section>` : ''}`, f => {
            Object.assign(x, { title: f.title.trim(), assignee: f.assignee, due: dueRead(f), when: condRead(dlg, x.when) });
            if (!t) Tk.items.push(x);
            peChanged(); peTasks(body);
        });
        condBind(dlg, c.flags);
        $('#tdel', dlg)?.addEventListener('click', () => { Tk.items = Tk.items.filter(i => i !== t); dlg.close(); peChanged(); peTasks(body); });
    }
}

// כללים (ודגלים — השאלות שנשאלות בפתיחת התיק)
function peRules(body) {
    const c = PE.content;
    body.innerHTML = `<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${P.flagsHint}</p><button class="btn ghost sm" id="fadd">+ ${P.flag}</button></div>
        ${c.flags.length ? `<ul class="list">${c.flags.map(f => `<li class="card" tabindex="0" role="button" data-flag-row="${esc(f.key)}"><span class="use-ic" aria-hidden="true">🏷️</span>
            <div class="body"><div class="l1"><span>${esc(f.title || f.key)}</span></div><div class="l2 wrap">${esc(f.question)}</div></div></li>`).join('')}</ul>` : `<p class="empty">${P.noFlags}</p>`}
        <div class="toolbar"><p class="hint one" style="flex:1;margin:0">${P.rulesHint}</p><button class="btn primary sm" id="ruadd">+ ${P.rule}</button></div>
        ${c.rules.length ? `<ul class="list">${c.rules.map(r => `<li class="card" tabindex="0" role="button" data-rule="${esc(r.key)}"><span class="use-ic" aria-hidden="true">🔀</span>
            <div class="body"><div class="l1"><span>${esc(r.title || P.untitled)}</span></div>
            <div class="l2 wrap">${P.when}: ${esc(condText(r.when, c.flags) || P.always)}</div>
            <div class="l2 wrap">${r.then.map(a => esc(P.actions[a.do] + ': ' + (a.do === 'alert' ? a.text : a.item.title))).join('<br>')}</div></div></li>`).join('')}</ul>` : `<p class="empty">${P.noRules}</p>`}`;
    const flagSheet = f => {
        const dlg = sheet(P.flag, `<label class="f"><span>${P.flagTitle}</span><input name="title" required maxlength="120" value="${esc(f?.title || '')}"></label>
            <label class="f"><span>${P.flagQuestion}</span><input name="question" maxlength="300" value="${esc(f?.question || '')}"></label>
            ${f ? `<section class="group"><button type="button" class="btn ghost danger" id="fdel">${P.remove}</button><p class="hint">${P.flagDeleteHint}</p></section>` : ''}`, v => {
            if (f) Object.assign(f, { title: v.title.trim(), question: v.question.trim() }); else c.flags.push({ key: newKey('f'), title: v.title.trim(), question: v.question.trim() });
            peChanged(); peRules(body);
        });
        $('#fdel', dlg)?.addEventListener('click', () => { c.flags = c.flags.filter(x => x !== f); dlg.close(); peChanged(); peRules(body); });
    };
    $('#fadd').addEventListener('click', () => flagSheet(null));
    $$('[data-flag-row]', body).forEach(li => onActivate(li, () => flagSheet(c.flags.find(f => f.key === li.dataset.flagRow))));
    $('#ruadd').addEventListener('click', () => ruleSheet(null));
    $$('[data-rule]', body).forEach(li => onActivate(li, () => ruleSheet(c.rules.find(r => r.key === li.dataset.rule))));

    function ruleSheet(r) {
        const x = r || { key: newKey('rule'), title: '', when: null, then: [] };
        const actionRow = a => `<div class="action-row panel" data-akey="${esc(a.item?.key || '')}">
            <div class="fields two"><label class="f"><span>${P.actionDo}</span><select data-ado>${PL && ['add_requirement', 'add_task', 'alert'].map(k => opt(k, P.actions[k], a.do)).join('')}</select></label>
              <label class="f"><span>${P.actionText}</span><input data-atext maxlength="160" value="${esc(a.do === 'alert' ? a.text : a.item?.title || '')}"></label></div>
            <div class="fields two" data-areq${a.do === 'add_requirement' ? '' : ' hidden'}><label class="f"><span>${P.docType}</span><select data-adoc>${PL.doc_types.map(t => opt(t.key, t.name, a.item?.doc_type || 'other')).join('')}</select></label>
              <label class="f"><span>${P.party}</span><select data-aparty>${PL.party_kinds.map(k => opt(k, P.partyKinds[k], a.item?.party_kind || 'client')).join('')}</select></label></div>
            <button type="button" class="btn sm ghost danger" data-adel>${P.remove}</button></div>`;
        const dlg = sheet(r ? P.rule : P.ruleNew, `<label class="f"><span>${P.ruleTitle}</span><input name="title" required maxlength="160" value="${esc(x.title)}"></label>
            ${condHtml(x.when, c.flags)}
            <div class="f"><span>${P.then}</span><div id="acts">${x.then.map(actionRow).join('')}</div><button type="button" class="btn sm ghost" id="aadd">+ ${P.actionAdd}</button></div>
            ${r ? `<section class="group"><button type="button" class="btn ghost danger" id="rudel">${P.remove}</button></section>` : ''}`, f => {
            const old = Object.fromEntries(x.then.filter(a => a.item).map(a => [a.item.key, a.item]));
            x.then = $$('.action-row', dlg).map(row => {
                const kind = $('[data-ado]', row).value, text = $('[data-atext]', row).value.trim(), prev = old[row.dataset.akey];
                if (kind === 'alert') return { do: 'alert', text };
                if (kind === 'add_task') return { do: 'add_task', item: { key: prev?.key || newKey('t'), assignee: 'responsible', due: { mode: 'manual' }, when: null, sort: 99, ...(prev && prev.assignee ? prev : {}), title: text } };
                return { do: 'add_requirement', item: { key: prev?.key || newKey('r'), group: '', explain_recipient: '', explain_internal: '', priority: 'normal', mandatory: false, due: { mode: 'manual' },
                    needs_review: true, when: null, sort: 99, ...(prev && prev.doc_type ? prev : {}), title: text, doc_type: $('[data-adoc]', row).value, party_kind: $('[data-aparty]', row).value } };
            });
            Object.assign(x, { title: f.title.trim(), when: condRead(dlg, x.when) });
            if (!r) c.rules.push(x);
            peChanged(); peRules(body);
        });
        condBind(dlg, c.flags);
        $('#aadd', dlg).addEventListener('click', () => $('#acts', dlg).insertAdjacentHTML('beforeend', actionRow({ do: 'add_requirement', item: { title: '' } })));
        $('#acts', dlg).addEventListener('click', e => e.target.closest('[data-adel]')?.closest('.action-row').remove());
        $('#acts', dlg).addEventListener('change', e => { if (e.target.matches('[data-ado]')) $('[data-areq]', e.target.closest('.action-row')).hidden = e.target.value !== 'add_requirement'; });
        $('#rudel', dlg)?.addEventListener('click', () => { c.rules = c.rules.filter(i => i !== r); dlg.close(); peChanged(); peRules(body); });
    }
}

// שיתוף
async function peShare(body) {
    body.innerHTML = `<p class="boot">${T.loading}</p>`;
    let d;
    try { d = await api('profile-shares', { params: { profile: PE.pid } }); } catch (x) { body.innerHTML = errorHtml(x); return; }
    body.innerHTML = `<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${P.shareHint}</p><button class="btn primary sm" id="sadd">+ ${P.share}</button></div>
        ${d.shares.length ? `<ul class="list">${d.shares.map(s => `<li class="card static"><span class="use-ic" aria-hidden="true">${s.kind === 'office' ? '🏛️' : '👤'}</span>
            <div class="body"><div class="l1"><span>${esc(s.name)}</span></div><div class="l2"><span dir="ltr">${esc(s.email || '')}</span>${s.email ? '<i class="sep" aria-hidden="true">·</i>' : ''}${P.can[s.can]}</div></div>
            <button class="icon-btn" data-unshare="${s.pid}" data-name="${esc(s.name)}" aria-label="${P.unshare}">🗑️</button></li>`).join('')}</ul>` : `<p class="empty">${P.noShares}</p>`}`;
    $$('[data-unshare]', body).forEach(b => b.addEventListener('click', async () => {
        if (!confirm(P.unshareAsk(b.dataset.name))) return;
        try { await api('profile-share-revoke', { body: { share: b.dataset.unshare } }); toast(P.unshared); peShare(body); } catch (x) { toastError(x); }
    }));
    $('#sadd').addEventListener('click', () => sheet(P.share, `
        <div class="f"><span>${P.shareWith}</span><fieldset class="seg"><label><input type="radio" name="kind" value="user" checked><span>${P.shareUser}</span></label>
            <label><input type="radio" name="kind" value="office"><span>${P.shareOffice}</span></label></fieldset></div>
        <label class="f"><span>${P.shareEmail}</span><input name="email" type="email" dir="ltr" inputmode="email" autocomplete="off" maxlength="200"></label>
        <div class="f"><span>${P.shareCan}</span><fieldset class="seg">${['view', 'duplicate', 'edit'].map((k, i) => `<label><input type="radio" name="can" value="${k}"${i ? '' : ' checked'}><span>${P.can[k]}</span></label>`).join('')}</fieldset></div>
        <p class="hint">${P.shareNot}</p>`, async f => {
        const args = { profile: PE.pid, can: f.can, ...(f.kind === 'office' ? { office: true } : { email: f.email }) };
        // תצוגה מקדימה לפני אישור: מי הנמען, מה יוכל, ומה לא עובר
        const pv = await api('profile-share-preview', { body: args });
        if (!confirm(P.sharePreview(pv.grantee.name, P.can[pv.can]))) return false;
        await api('profile-share', { body: args });
        toast(P.shared);
        peShare(body);
    }, P.shareNext));
}

// היסטוריה
function peHistory(body) {
    body.innerHTML = `<p class="hint">${P.historyHint}</p><div class="row-btns"><button class="btn ghost" id="hopen">🕘 ${P.tabHistory}</button></div>`;
    $('#hopen').addEventListener('click', () => historySheet(PE.pid));
}

// פרסום: בדיקות, סיכום שינויים מול הגרסה הקודמת, והערת שינוי
async function pePublish() {
    try {
        await peSave();
        if (PE.dirty) return;
        const d = await api('profile-check', { params: { profile: PE.pid } });
        const { errors, warnings } = d.checks;
        sheet(P.publishTitle, `${errors.map(e => `<p class="banner bad" role="alert">❌ ${esc(e.message)}</p>`).join('')}${warnings.map(w => `<p class="banner" role="note">⚠️ ${esc(w.message)}</p>`).join('')}
            ${errors.length ? `<p class="hint">${P.publishBlocked}</p>` : `<p class="sec-title">${d.previous ? P.changesSince(d.previous) : P.firstVersion}</p>${diffHtml(d.diff)}
            <label class="f"><span>${P.changeNote}</span><textarea name="note" rows="2" maxlength="500" placeholder="${P.changeNotePh}"></textarea></label>`}`,
            errors.length ? null : async f => {
                const r = await api('profile-publish', { body: { profile: PE.pid, note: f.note, revision: PE.revision } });
                toast(P.published(r.number));
                PE.dirty = false;
                location.hash = '#/profiles/' + PE.pid;
            }, P.publish);
    } catch (x) { toastError(x); }
}

// ── סוגי מסמך (מנהל המערכת) ───────────────────────────────────

async function viewDocTypes() {
    const d = await api('sys-doc-types');
    const html = `<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${P.docTypesHint}</p><button class="btn primary sm" id="dtadd">+ ${P.docType}</button></div>
        <ul class="list">${d.doc_types.map(t => `<li class="card" tabindex="0" role="button" data-dt="${esc(t.key)}"><span class="use-ic" aria-hidden="true">📄</span>
            <div class="body"><div class="l1"><span>${esc(t.name)}</span></div><div class="l2 wrap"><span dir="ltr">${esc(t.key)}</span>${t.description ? `<i class="sep" aria-hidden="true">·</i>${esc(t.description)}` : ''}</div></div>
            ${t.hidden ? `<span class="chip bad">${P.hidden}</span>` : ''}</li>`).join('')}</ul>`;
    const open = t => sheet(P.docType, `
        <label class="f"><span>${P.docTypeKey}</span><input name="key" required dir="ltr" pattern="[a-z0-9_]{1,40}" maxlength="40" value="${esc(t?.key || '')}"${t ? ' readonly' : ''}></label>
        <label class="f"><span>${P.name}</span><input name="name" required maxlength="120" value="${esc(t?.name || '')}"></label>
        <label class="f"><span>${P.description}</span><input name="description" maxlength="300" value="${esc(t?.description || '')}"></label>
        ${t ? `<label class="switch"><input type="checkbox" name="hidden"${t.hidden ? ' checked' : ''}><span>${P.hiddenLong}</span></label>` : ''}`, async f => {
        await api('sys-doc-type-save', { body: { key: f.key, name: f.name, description: f.description, hidden: !!f.hidden } });
        PL = null;
        toast(T.saved);
        viewSystem('doc-types');
    });
    return { html, bind() {
        $('#dtadd').addEventListener('click', () => open(null));
        $$('[data-dt]').forEach(li => onActivate(li, () => open(d.doc_types.find(t => t.key === li.dataset.dt))));
    } };
}
