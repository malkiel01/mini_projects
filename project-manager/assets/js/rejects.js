/**
 * 🔧 ריג'קטים — לשונית בפרויקט (lib/rejects.php, README: "ריג'קטים").
 * רשימה לפי ספק: מה פתוח, מה הספק דיווח שתיקן, מה בוצע. חלון לכל ריג'קט: השרשור (הודעות עם תמונות ומסמכים),
 * ופעולות — הודעה, ✅ בוצע (רק המנהל סוגר), ↩️ לא תוקן כמו שצריך (עם הסבר וקבצים), אישור ריג'קט של הלקוח.
 * כל עוד יש ריג'קט פתוח — אין מסירה.
 */
const Rejects = (() => {
    let cur = null, data = null, bound = false, current = null, showClosed = false;
    const ICON = { pending: '🟣', open: '🔴', fixed: '🟡', returned: '↩️', done: '✅', cancelled: '⚪' };
    const ROLE = { manager: t('מנהל הפרויקט'), supplier: t('הספק'), client: t('הלקוח'), lawyer: t('עו"ד') };
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;

    async function show(p) {
        cur = p;
        bind();
        try { data = await api('rejects', { params: { project_id: p.project.id } }); } catch (e) { toast(e.message, true); return; }
        render();
        // קישור ישיר לריג'קט (מהתראה / ציר הזמן) — לא חובה
        if (pendingOpen) { const id = pendingOpen; pendingOpen = null; open(id); }
    }
    let pendingOpen = null;

    function bind() {
        if (bound) return;
        bound = true;
        $('#rejects-root').addEventListener('click', e => {
            if (e.target.closest('[data-rj-new]')) { openNew(); return; }
            if (e.target.closest('[data-rj-closed]')) { showClosed = !showClosed; render(); return; }
            const r = e.target.closest('[data-rj]');
            if (r) open(Number(r.dataset.rj));
        });
        $('#rejects-root').addEventListener('keydown', e => { const r = e.target.closest('[data-rj]'); if (r && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(Number(r.dataset.rj)); } });
        const f = $('#rj-dlg form');
        f.addEventListener('submit', e => { e.preventDefault(); if (!current) createNew(); });
        $('#rj-files').addEventListener('change', () => { const n = $('#rj-files').files.length; $('#rj-files-n').textContent = n ? t('{n} קבצים', { n }) : ''; });
        $('#rj-actions').addEventListener('click', e => { const b = e.target.closest('[data-act]'); if (b) act(b.dataset.act); });
    }

    function render() {
        const L = data.rejects, C = data.counts;
        const live = L.filter(r => !['done', 'cancelled'].includes(r.status) || showClosed);
        const pending = live.filter(r => r.status === 'pending');
        const bySup = new Map();
        for (const r of live.filter(x => x.status !== 'pending')) {
            const k = r.supplier_id || 0;
            if (!bySup.has(k)) bySup.set(k, { name: r.supplier || t('בלי ספק'), list: [] });
            bySup.get(k).list.push(r);
        }
        const closedN = L.filter(r => ['done', 'cancelled'].includes(r.status)).length;
        $('#rejects-root').innerHTML = `
            <section class="fin-card rj-sum"><header class="fin-head"><h2>🔧 ${esc(t('ריג\'קטים'))}</h2>
                <button type="button" class="btn primary sm" data-rj-new>+ ${esc(t('ריג\'קט'))}</button></header>
                <div class="chip-row">${C.open ? `<span class="chip fin-late">🔴 ${esc(t('{n} פתוחים', { n: C.open - C.fixed }))}</span>` : ''}
                    ${C.fixed ? `<span class="chip fin-warn">🟡 ${esc(t('{n} לבדיקה — הספק דיווח שתיקן', { n: C.fixed }))}</span>` : ''}
                    ${C.pending ? `<span class="chip">🟣 ${esc(t('{n} מהלקוח — לאישורך', { n: C.pending }))}</span>` : ''}
                    ${C.done ? `<span class="chip fin-ok">✅ ${esc(t('{n} בוצעו', { n: C.done }))}</span>` : ''}</div>
                <p class="hint">${esc(C.open ? t('המסירה חסומה עד שכל הריג\'קטים ייסגרו. הספק רואה את שלו בקישור ומדווח "תיקנתי" — ורק אתה מסמן בוצע.')
                    : t('מטלות שספק צריך לסיים לפני המסירה — עם תמונות, מסמכים והסברים. הספק והלקוח רואים אותן בקישור שלהם.'))}</p></section>
            ${pending.length ? `<section class="rj-group"><h3 class="sec-title">🟣 ${esc(t('פתח הלקוח — לאישורך'))}</h3><ul class="list rows-list">${pending.map(row).join('')}</ul></section>` : ''}
            ${[...bySup.values()].map(g => `<section class="rj-group"><h3 class="sec-title">👷 ${esc(g.name)} <small class="muted">${esc(t('{n} פתוחים', { n: g.list.filter(r => ['open', 'returned', 'fixed'].includes(r.status)).length }))}</small></h3>
                <ul class="list rows-list">${g.list.map(row).join('')}</ul></section>`).join('')}
            ${!L.length ? `<p class="empty">${esc(t('אין ריג\'קטים. "+ ריג\'קט" — מטלה שספק צריך לסיים לפני המסירה.'))}</p>` : ''}
            ${closedN ? `<button type="button" class="btn ghost sm" data-rj-closed>${esc(showClosed ? t('הסתרת הסגורים') : t('הצגת הסגורים ({n})', { n: closedN }))}</button>` : ''}`;
    }

    function row(r) {
        const last = r.events[r.events.length - 1];
        const files = r.events.reduce((n, e) => n + e.files.length, 0);
        const meta = [r.stage, r.unit ? '🏢 ' + r.unit : '', r.due_date ? t('עד {date}', { date: shortDate(r.due_date) }) : '', files ? `📎 ${files}` : ''].filter(Boolean);
        return `<li class="card rj-row rj-${r.status}" data-rj="${r.id}" tabindex="0" role="button">
            <span class="ficon" aria-hidden="true">${ICON[r.status]}</span>
            <div class="body"><div class="l1"><span class="one">${esc(r.title)}</span><span class="chip${r.late ? ' fin-late' : ''}">${esc(r.late ? t('באיחור') : r.status_label)}</span></div>
                <div class="l2">${meta.map(esc).join('<i class="sep" aria-hidden="true">·</i>')}</div>
                ${last && last.kind !== 'opened' ? `<div class="l2 muted one">${esc(ROLE[last.by_role] || last.by_name)}: ${esc(last.body || last.kind_label)}</div>` : ''}</div>
            <span class="go dir-flip" aria-hidden="true">‹</span></li>`;
    }

    // ================= חלון =================

    function resetCompose() {
        const f = $('#rj-dlg form');
        f.elements.body.value = '';
        $('#rj-files').value = '';
        $('#rj-files-n').textContent = '';
        formError(f, '');
    }

    function openNew() {
        current = null;
        const f = $('#rj-dlg form'), el = f.elements;
        resetCompose();
        $('#rj-dlg-title').textContent = t('ריג\'קט חדש');
        $('#rj-new').hidden = false;
        $('#rj-view').hidden = true;
        $('#rj-save').hidden = false;
        $('#rj-actions').innerHTML = '';
        $('#rj-body-label').textContent = t('הסבר והערות');
        el.title.value = '';
        el.due_date.value = '';
        fillPickers(el, null);
        $('#rj-dlg').showModal();
    }

    function fillPickers(el, r) {
        const sups = cur.team.filter(c => c.kind === 'supplier');
        el.supplier_id.innerHTML = options(sups.map(c => ({ value: c.id, label: c.name })), r?.supplier_id ?? '', t('בחירה'));
        el.stage_id.innerHTML = options(cur.stages.map(s => ({ value: s.id, label: stageName(s) })), r?.stage_id ?? '', t('ללא'));
        const units = cur.units || [];
        $('#rj-unit-f').hidden = !units.length;
        el.unit_id.innerHTML = options(units.map(u => ({ value: u.id, label: u.name })), r?.unit_id ?? '', t('משותף / כל הפרויקט'));
    }

    /** העלאת הקבצים שנבחרו — לתיקיית הריג'קטים; מחזיר מזהים. */
    async function uploadPicked() {
        const ids = [];
        for (const file of $('#rj-files').files) {
            if (file.size > state.maxUpload) throw new Error(t('{name}: גדול מ-{mb}MB', { name: file.name, mb: Math.round(state.maxUpload / 1048576) }));
            const type = file.type.startsWith('image/') ? 'photo' : file.type.startsWith('video/') ? 'video' : 'other';
            ids.push((await Files.send(file, cur.project.id, data.folder_id, undefined, { quiet: true, type })).file.id);
        }
        return ids;
    }

    async function createNew() {
        const f = $('#rj-dlg form'), el = f.elements;
        if (!el.title.value.trim()) { formError(f, t('מה צריך לתקן — שדה חובה')); return; }
        if (!el.supplier_id.value) { formError(f, t('בחר ספק')); return; }
        await busy($('#rj-save'), f, async () => {
            const file_ids = await uploadPicked();
            data = await api('reject', { body: { project_id: cur.project.id, title: el.title.value.trim(), supplier_id: el.supplier_id.value,
                stage_id: el.stage_id.value, unit_id: el.unit_id.value, due_date: el.due_date.value, body: el.body.value.trim(), file_ids } });
            $('#rj-dlg').close();
            render();
            toast(t('נשלח לספק — מייל עם הקישור שלו'));
        });
    }

    function open(id) {
        const r = data.rejects.find(x => x.id === id);
        if (!r) return;
        current = r;
        const f = $('#rj-dlg form'), el = f.elements;
        resetCompose();
        $('#rj-dlg-title').textContent = r.title;
        $('#rj-new').hidden = r.status !== 'pending';
        $('#rj-view').hidden = false;
        $('#rj-save').hidden = true;
        if (r.status === 'pending') { el.title.value = r.title; el.due_date.value = r.due_date || ''; fillPickers(el, r); }
        $('#rj-head').innerHTML = `<div class="chip-row"><span class="chip">${ICON[r.status]} ${esc(r.status_label)}</span>${r.late ? `<span class="chip fin-late">${esc(t('באיחור'))}</span>` : ''}</div>
            <p class="muted">${[r.supplier ? '👷 ' + r.supplier : '', r.stage, r.unit ? '🏢 ' + r.unit : '', r.due_date ? t('עד {date}', { date: shortDate(r.due_date) }) : ''].filter(Boolean).map(esc).join(' · ')}</p>`;
        $('#rj-thread').innerHTML = r.events.map(e => `<li class="rj-ev rj-ev-${e.kind} by-${e.by_role}">
            <div class="rj-ev-h"><b>${esc(e.by_name || ROLE[e.by_role] || '')}</b> <span class="muted">${esc(ROLE[e.by_role] || '')} · ${esc(e.kind_label)} · ${esc(new Date(e.at).toLocaleString(I18n.locale(), { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }))}</span></div>
            ${e.body ? `<p class="rj-ev-b">${esc(e.body)}</p>` : ''}
            ${e.files.length ? `<div class="rj-files">${e.files.map(x => x.thumb || /^image\//.test(x.mime) ? `<a href="${fileUrl(x.id)}" class="rj-thumb"><img src="${fileUrl(x.id, x.thumb ? '&thumb=1' : '')}" alt="${esc(x.name)}" loading="lazy"></a>`
                : `<a href="${fileUrl(x.id)}" class="chip">📎 ${esc(x.name)}</a>`).join('')}</div>` : ''}</li>`).join('');
        const A = [];
        if (r.status === 'pending') A.push(['approve', 'primary', '✅ ' + t('אישור ושליחה לספק')], ['cancel', 'ghost', t('ביטול')]);
        else if (['open', 'returned', 'fixed'].includes(r.status)) A.push(['note', 'ghost', '💬 ' + t('שליחת הודעה')], ['returned', 'ghost', '↩️ ' + t('לא תוקן כמו שצריך')], ['done', 'primary', '✅ ' + t('בוצע')]);
        else A.push(['reopen', 'ghost', t('פתיחה מחדש')]);
        $('#rj-actions').innerHTML = A.map(([k, cls, label]) => `<button type="button" class="btn ${cls} sm" data-act="${k}">${esc(label)}</button>`).join('');
        $('#rj-body-label').textContent = r.status === 'fixed' ? t('הודעה — או למה זה לא תוקן כמו שצריך') : t('הודעה');
        $('#rj-dlg').showModal();
        const th = $('#rj-thread'); th.lastElementChild?.scrollIntoView({ block: 'nearest' });
    }

    async function act(action) {
        const f = $('#rj-dlg form'), el = f.elements, body = el.body.value.trim();
        if (action === 'returned' && !body) { formError(f, t('כתבו למה זה לא תוקן כמו שצריך — אפשר גם לצרף תמונה')); el.body.focus(); return; }
        if (action === 'approve' && !el.supplier_id.value) { formError(f, t('בחר ספק')); return; }
        if (action === 'cancel' && !confirm(t('לבטל את הריג\'קט?'))) return;
        const btn = $(`#rj-actions [data-act="${action}"]`);
        await busy(btn, f, async () => {
            const file_ids = await uploadPicked();
            data = await api('reject-act', { body: { id: current.id, action, body, file_ids,
                ...(action === 'approve' ? { supplier_id: el.supplier_id.value, stage_id: el.stage_id.value } : {}) } });
            render();
            const id = current.id;
            if (['done', 'cancel'].includes(action)) { $('#rj-dlg').close(); toast(action === 'done' ? t('סומן בוצע') : t('בוטל')); }
            else { $('#rj-dlg').close(); open(id); toast(action === 'returned' ? t('הוחזר לספק — עם ההסבר') : t('נשלח')); }
        });
    }

    function openWhenReady(id) { pendingOpen = id; }

    return { show, openWhenReady };
})();
