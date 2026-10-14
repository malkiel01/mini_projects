/**
 * 🔧 ריג'קטים — לשונית בפרויקט (lib/rejects.php, README: "ריג'קטים").
 * רשימה לפי ספק: מה פתוח, מה הספק דיווח שתיקן, מה בוצע. חלון לכל ריג'קט: השרשור (הודעות עם תמונות ומסמכים),
 * ופעולות — הודעה, ✅ בוצע (רק המנהל סוגר), ↩️ לא תוקן כמו שצריך (עם הסבר וקבצים), אישור ריג'קט של הלקוח.
 * כל עוד יש ריג'קט פתוח — אין מסירה.
 */
const Rejects = (() => {
    let cur = null, data = null, bound = false, current = null, showClosed = false;
    // 🚶 סיור מסירה: הריג'קטים שהיו פתוחים כשהסיור התחיל (נשארים ברשימה גם אחרי הסימון), ומה סומן בכל אחד
    let walk = null;
    const ICON = { pending: '🟣', open: '🔴', fixed: '🟡', returned: '↩️', done: '✅', cancelled: '⚪' };
    const ROLE = { manager: t('מנהל הפרויקט'), supplier: t('הספק'), client: t('הלקוח'), lawyer: t('עו"ד') };
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;

    async function show(p) {
        if (cur?.project.id !== p.project.id) walk = null;
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
            if (e.target.closest('[data-rj-print]')) { print(); return; }
            if (e.target.closest('[data-rj-walk]')) { startWalk(); return; }
            if (e.target.closest('[data-rj-walk-end]')) { walk = null; render(); return; }
            const w = e.target.closest('[data-walk-act]');
            if (w) { walkAct(w, w.dataset.walkAct); return; }
            const r = e.target.closest('[data-rj]');
            if (r && !e.target.closest('.rj-walk-form')) open(Number(r.dataset.rj));
        });
        $('#rejects-root').addEventListener('keydown', e => { const r = e.target.closest('[data-rj]'); if (r && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(Number(r.dataset.rj)); } });
        const f = $('#rj-dlg form');
        f.addEventListener('submit', e => { e.preventDefault(); if (!current) createNew(); });
        $('#rj-files').addEventListener('change', () => { const n = $('#rj-files').files.length; $('#rj-files-n').textContent = n ? t('{n} קבצים', { n }) : ''; });
        $('#rj-actions').addEventListener('click', e => { const b = e.target.closest('[data-act]'); if (b) act(b.dataset.act); });
    }

    function render() {
        if (walk) { renderWalk(); return; }
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
        badge(C.open + C.pending);
        $('#rejects-root').innerHTML = `
            <section class="fin-card rj-sum"><header class="fin-head"><h2>🔧 ${esc(t('ריג\'קטים'))}</h2>
                <span class="row-btns">${C.open ? `<button type="button" class="btn ghost sm" data-rj-walk>🚶 ${esc(t('סיור'))}</button>` : ''}${live.length ? `<button type="button" class="btn ghost sm" data-rj-print aria-label="${esc(t('הדפסה לסיור מסירה'))}" title="${esc(t('הדפסה לסיור מסירה'))}">🖨️</button>` : ''}
                <button type="button" class="btn primary sm" data-rj-new>+ ${esc(t('ריג\'קט'))}</button></span></header>
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
    async function uploadPicked(input = $('#rj-files')) {
        const ids = [];
        for (const file of input.files) {
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

    // ================= 🚶 סיור מסירה — מהטלפון, בשטח =================

    /** מתחילים סיור: כל מה שעוד לא נסגר, לפי דירה ושלב — כרטיס לכל ריג'קט עם "✅ תקין" / "↩️ לא תוקן". */
    function startWalk() {
        const open = data.rejects.filter(r => ['open', 'fixed', 'returned'].includes(r.status));
        open.sort((a, b) => (a.unit || '').localeCompare(b.unit || '') || (a.stage || '').localeCompare(b.stage || '') || a.id - b.id);
        walk = { ids: open.map(r => r.id), marks: {}, started: Math.max(0, ...data.rejects.map(r => r.id)) };
        render();
        window.scrollTo({ top: 0 });
    }

    function renderWalk() {
        const byId = new Map(data.rejects.map(r => [r.id, r]));
        // ריג'קט חדש שנפתח בזמן הסיור — נכנס לרשימה (כבר "נבדק": הרגע רשמת אותו)
        for (const r of data.rejects) if (!walk.ids.includes(r.id) && r.id > walk.started) { walk.ids.push(r.id); walk.marks[r.id] = 'new'; }
        // סומן "בוצע" מהחלון (לחיצה על הכרטיס) — נחשב נבדק
        for (const id of walk.ids) if (!walk.marks[id] && byId.get(id)?.status === 'done') walk.marks[id] = 'done';
        const list = walk.ids.map(id => byId.get(id)).filter(Boolean);
        const done = list.filter(r => walk.marks[r.id]).length;
        const ok = list.filter(r => walk.marks[r.id] === 'done').length, back = list.filter(r => walk.marks[r.id] === 'returned').length;
        $('#rejects-root').innerHTML = `
            <section class="fin-card rj-walk-head"><header class="fin-head"><h2>🚶 ${esc(t('סיור מסירה'))}</h2>
                <button type="button" class="btn ghost sm" data-rj-walk-end>${esc(t('סיום הסיור'))}</button></header>
                <div class="rj-walk-bar" role="progressbar" aria-valuemin="0" aria-valuemax="${list.length}" aria-valuenow="${done}"><i style="inline-size:${list.length ? done / list.length * 100 : 0}%"></i></div>
                <p class="hint">${esc(t('נבדקו {done} מתוך {n}', { done, n: list.length }))}${ok ? ` · ✅ ${ok}` : ''}${back ? ` · ↩️ ${back}` : ''}</p>
                <button type="button" class="btn ghost sm" data-rj-new>+ ${esc(t('ריג\'קט חדש שמצאנו בסיור'))}</button></section>
            <ul class="list rj-walk-list">${list.map(walkCard).join('') || `<li class="empty">${esc(t('אין ריג\'קטים פתוחים — אפשר למסור'))}</li>`}</ul>
            ${done === list.length && list.length ? `<p class="hint rj-walk-end">${esc(data.counts.open ? t('הסיור הסתיים. נשארו {n} פתוחים — המסירה תיפתח כשייסגרו.', { n: data.counts.open }) : t('הסיור הסתיים — הכול סגור, אפשר למסור.'))}</p>` : ''}`;
    }

    function walkCard(r) {
        const mark = walk.marks[r.id];
        const imgs = r.events.flatMap(e => e.files).filter(x => x.thumb || /^image\//.test(x.mime)).slice(-3);
        const last = [...r.events].reverse().find(e => e.body && e.kind !== 'opened');
        const where = [r.supplier, r.stage, r.unit ? '🏢 ' + r.unit : '', r.due_date ? t('עד {date}', { date: shortDate(r.due_date) }) : ''].filter(Boolean);
        const result = mark === 'done' ? `<span class="chip fin-ok">✅ ${esc(t('תקין — נסגר'))}</span>` : mark === 'returned' ? `<span class="chip fin-late">↩️ ${esc(t('הוחזר לספק'))}</span>`
            : mark === 'new' ? `<span class="chip">🆕 ${esc(t('נפתח בסיור'))}</span>` : '';
        return `<li class="card rj-walk rj-${r.status}${mark ? ' rj-walk-done' : ''}" data-walk="${r.id}">
            <div class="rj-walk-top" data-rj="${r.id}" role="button" tabindex="0"><span class="ficon" aria-hidden="true">${ICON[r.status]}</span>
                <div class="body"><div class="l1"><b class="one">${esc(r.title)}</b>${result || `<span class="chip${r.late ? ' fin-late' : ''}">${esc(r.late ? t('באיחור') : r.status_label)}</span>`}</div>
                <div class="l2">${where.map(esc).join('<i class="sep" aria-hidden="true">·</i>')}</div>
                ${last ? `<div class="l2 muted">${esc(ROLE[last.by_role] || last.by_name)}: ${esc(last.body)}</div>` : ''}</div></div>
            ${imgs.length ? `<div class="rj-files">${imgs.map(x => `<a href="${fileUrl(x.id)}" class="rj-thumb"><img src="${fileUrl(x.id, x.thumb ? '&thumb=1' : '')}" alt="${esc(x.name)}" loading="lazy"></a>`).join('')}</div>` : ''}
            ${mark ? '' : `<div class="rj-walk-btns"><button type="button" class="btn primary" data-walk-act="done">✅ ${esc(t('תקין'))}</button>
                <button type="button" class="btn ghost" data-walk-act="form">↩️ ${esc(t('לא תוקן'))}</button></div>
            <div class="rj-walk-form" hidden><label class="f"><span>${esc(t('מה עוד לא תקין'))}</span><textarea rows="2"></textarea></label>
                <label class="btn ghost sm rj-walk-cam">📷 ${esc(t('צילום'))}<input type="file" accept="image/*" capture="environment" multiple hidden></label><span class="muted rj-walk-n"></span>
                <p class="error" role="alert" hidden></p>
                <button type="button" class="btn primary" data-walk-act="returned">${esc(t('החזרה לספק'))}</button></div>`}</li>`;
    }

    async function walkAct(btn, action) {
        const li = btn.closest('[data-walk]'), id = Number(li.dataset.walk), form = $('.rj-walk-form', li);
        if (action === 'form') {
            form.hidden = !form.hidden;
            if (!form.hidden) {
                $('textarea', form).focus();
                $('input[type=file]', form).onchange = e => { const n = e.target.files.length; $('.rj-walk-n', form).textContent = n ? t('{n} תמונות', { n }) : ''; };
            }
            return;
        }
        const body = action === 'returned' ? $('textarea', form).value.trim() : '';
        const err = $('.error', form);
        if (action === 'returned' && !body) { err.textContent = t('כתבו למה זה לא תוקן כמו שצריך — אפשר גם לצרף תמונה'); err.hidden = false; return; }
        btn.disabled = true;
        try {
            const file_ids = action === 'returned' ? await uploadPicked($('input[type=file]', form)) : [];
            data = await api('reject-act', { body: { id, action, body, file_ids } });
            walk.marks[id] = action;
            render();
            badge(data.counts.open + data.counts.pending);
            // לכרטיס הבא שעוד לא נבדק
            const next = $('#rejects-root .rj-walk:not(.rj-walk-done)');
            next?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
        } catch (e) { btn.disabled = false; toast(e.message, true); }
    }

    /** המונה בלשונית "🔧 ריג'קטים" — כמה עוד לא נסגרו (כולל ממתינים לאישור). */
    function badge(n) {
        const a = $('#project-nav [data-pane="rejects"]');
        if (!a) return;
        let b = a.querySelector('.tab-count');
        if (!b) { b = document.createElement('small'); b.className = 'tab-count'; a.append(' ', b); }
        b.textContent = n ? String(n) : '';
    }

    // ================= הדפסה לסיור מסירה =================

    /** דף לסיור עם הלקוח: לכל ספק טבלה — ☐ לסימון בשטח, מה לתקן, איפה, עד מתי, מצב, ההערה האחרונה ותמונה. מה שמוצג במסך (כולל הסגורים אם פתוחים). */
    function printHtml() {
        const L = data.rejects.filter(r => !['done', 'cancelled'].includes(r.status) || showClosed);
        const groups = new Map();
        for (const r of L) {
            const k = r.status === 'pending' ? 'p' : r.supplier_id || 0;
            if (!groups.has(k)) groups.set(k, { name: r.status === 'pending' ? t('פתח הלקוח — לאישורך') : r.supplier || t('בלי ספק'), list: [] });
            groups.get(k).list.push(r);
        }
        const p = cur.project, client = p.client_id ? cur.team.find(c => c.id === p.client_id) : null;
        const tr = r => {
            const last = [...r.events].reverse().find(e => e.body && e.kind !== 'opened');
            const img = r.events.flatMap(e => e.files).find(x => x.thumb || /^image\//.test(x.mime));
            const where = [r.stage, r.unit ? '🏢 ' + r.unit : ''].filter(Boolean).join(' · ');
            return `<tr><td class="rp-box">${['done', 'cancelled'].includes(r.status) ? '☑' : '☐'}</td>
                <td><b>${esc(r.title)}</b>${r.events[0]?.body ? `<small>${esc(r.events[0].body)}</small>` : ''}${last ? `<small>${esc(ROLE[last.by_role] || last.by_name)}: ${esc(last.body)}</small>` : ''}</td>
                <td>${esc(where)}</td><td>${esc(r.due_date ? shortDate(r.due_date) : '')}</td>
                <td>${ICON[r.status]} ${esc(r.late ? t('באיחור') : r.status_label)}</td>
                <td class="rp-img">${img ? `<img src="${fileUrl(img.id, img.thumb ? '&thumb=1' : '')}" alt="">` : ''}</td>
                <td class="rp-note"></td></tr>`;
        };
        return `<div class="lp rp" dir="${document.documentElement.dir || 'rtl'}"><header class="lp-head"><h1>🔧 ${esc(t('ריג\'קטים לסיור מסירה'))} — ${esc(p.name)}</h1>
            <p>${[client ? t('לקוח: {name}', { name: client.name }) : '', t('הודפס {date}', { date: new Date().toLocaleDateString(I18n.locale()) }), t('{n} פריטים', { n: L.length })].filter(Boolean).map(esc).join(' · ')}</p>
            <p class="lp-note">${esc(t('סמנו ☐ בשטח מה נבדק ותקין; בעמודת "הערות" — מה עוד חסר. המסירה רק כשהכול סגור.'))}</p></header>
            ${[...groups.values()].map(g => `<section><h2>👷 ${esc(g.name)} <small>(${g.list.length})</small></h2><table><thead><tr><th></th><th>${esc(t('מה לתקן'))}</th><th>${esc(t('איפה'))}</th>
                <th>${esc(t('עד'))}</th><th>${esc(t('מצב'))}</th><th>${esc(t('תמונה'))}</th><th>${esc(t('הערות'))}</th></tr></thead><tbody>${g.list.map(tr).join('')}</tbody></table></section>`).join('')}
            <footer class="rp-sign"><span>${esc(t('חתימת הלקוח'))}: ____________</span><span>${esc(t('חתימת מנהל הפרויקט'))}: ____________</span></footer></div>`;
    }

    async function print() {
        let root = document.getElementById('rj-print');
        if (!root) { root = document.createElement('div'); root.id = 'rj-print'; document.body.appendChild(root); }
        root.innerHTML = printHtml();
        // מחכים לתמונות — אחרת הן חסרות בדף המודפס (עד 4 שניות)
        await Promise.race([Promise.all([...root.querySelectorAll('img')].map(i => i.complete ? 0 : new Promise(ok => { i.onload = i.onerror = ok; }))), new Promise(ok => setTimeout(ok, 4000))]);
        const H = document.documentElement;
        H.classList.add('rj-printing');
        const done = () => { H.classList.remove('rj-printing'); root.innerHTML = ''; window.removeEventListener('afterprint', done); };
        window.addEventListener('afterprint', done);
        window.print();
        setTimeout(() => { if (!matchMedia('print').matches) done(); }, 1500);
    }

    return { show, openWhenReady, badge };
})();
