// דיווח מהשטח — שלב 8 (lib/reports.php). לשונית "מהשטח" בפרויקט.
//
// "📷 עדכון מהשטח": תמונות מהמצלמה או מהגלריה, סרטונים, ומה נעשה — לשלב או כללי.
// "✅ השלב בוצע": אותו דבר, וחובה לפחות תמונה אחת — וזה מה שמסמן את השלב "בוצע".
// הקבצים עולים לתיקייה "תמונות מהשטח / <שלב>". גלוי (ברירת מחדל) — הלקוח רואה בקישור.
'use strict';

const Field = (() => {
    let project = null, reports = [], bound = false, picked = [], pending = null;
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;
    const kindNow = () => $('#report-dlg [name=kind]:checked').value;

    function bind() {
        if (bound) return;
        bound = true;
        $('#add-report').addEventListener('click', () => open({ stage_id: Number($('#field-stage').value) || null }));
        $('#field-stage').addEventListener('change', render);
        $('#report-list').addEventListener('click', e => {
            const m = e.target.closest('[data-media]');
            if (m) {
                const r = reports.find(x => x.id === Number(m.dataset.report));
                Lightbox.open(r.media, Number(m.dataset.media), id => fileUrl(id));
                return;
            }
            const v = e.target.closest('[data-visible]');
            if (v) { toggleVisible(Number(v.dataset.visible)); return; }
            const d = e.target.closest('[data-delete-report]');
            if (d) remove(Number(d.dataset.deleteReport));
        });
        const form = $('#report-dlg form');
        form.addEventListener('submit', e => { e.preventDefault(); save(); });
        $$('#report-dlg [name=kind]').forEach(r => r.addEventListener('change', kindChanged));
        $('#report-dlg [name=stage_id]').addEventListener('change', suppliers);
        for (const id of ['#report-camera', '#report-pick']) {
            $(id).addEventListener('change', e => { addFiles([...e.target.files]); e.target.value = ''; });
        }
        $('#report-media').addEventListener('click', e => {
            const x = e.target.closest('[data-unpick]');
            if (x) { const [p] = picked.splice(Number(x.dataset.unpick), 1); URL.revokeObjectURL(p.url); drawPicked(); }
        });
    }

    async function show(p) {
        bind();
        project = p;
        const keep = $('#field-stage').value;
        $('#field-stage').innerHTML = options(project.stages.map(s => ({ value: s.id, label: stageName(s) })), keep, t('כל השלבים'));
        try { ({ reports } = await api('reports', { params: { project_id: project.project.id } })); }
        catch (e) { toast(e.message, true); return; }
        render();
        if (pending) { const x = pending; pending = null; open(x); }
        if (focusId) { const id = focusId; focusId = null; flash(id); }
    }

    /** מקובץ ("שימוש במערכת"): לדיווח עצמו — כל השלבים, גלילה אליו והדגשה. */
    let focusId = null;
    function focus(projectId, reportId) {
        const here = project?.project?.id === projectId && location.hash.startsWith(`#/p/${projectId}/field`);
        if (here) { $('#field-stage').value = ''; render(); flash(reportId); return; }
        $('#field-stage').value = '';
        focusId = reportId;
        location.hash = `#/p/${projectId}/field`;
    }
    function flash(id) {
        if (!reports.some(r => r.id === id)) { toast(t('הדיווח כבר לא קיים'), true); return; }
        $('#field-stage').value = '';
        render();
        const el = $(`#report-list [data-report-id="${id}"]`);
        if (!el) return;
        el.scrollIntoView({ block: 'center' });
        el.classList.add('flash');
        setTimeout(() => el.classList.remove('flash'), 2200);
    }

    /** מחלון השלב: "בוצע" נבחר — עוברים לכאן עם הדיווח מוכן. */
    function markDone(projectId, stageId) {
        pending = { stage_id: stageId, kind: 'done' };
        location.hash = `#/p/${projectId}/field`;
    }

    function render() {
        const sid = Number($('#field-stage').value) || 0;
        const list = reports.filter(r => !sid || r.stage_id === sid);
        let day = '';
        $('#report-list').innerHTML = list.map(r => {
            const d = new Date(r.at).toDateString();
            const sep = d !== day ? `<h2 class="sec-title day-sep">${esc(dateLabel(r.at))}</h2>` : '';
            day = d;
            return sep + card(r);
        }).join('') || `<p class="empty">${esc(sid ? t('אין עדכונים לשלב הזה.') : t('עוד אין עדכונים מהשטח. "📷 עדכון מהשטח" — תמונות מהמצלמה ומה נעשה.'))}</p>`;
    }

    function card(r) {
        const time = new Date(r.at).toLocaleTimeString(I18n.locale(), { hour: '2-digit', minute: '2-digit' });
        const media = r.media.map((m, i) => `<li><button type="button" class="tile" data-report="${r.id}" data-media="${i}" aria-label="${esc(m.video ? t('סרטון {n}', { n: i + 1 }) : t('תמונה {n}', { n: i + 1 }))}">
            ${m.thumb ? `<img src="${fileUrl(m.id, '&thumb=1')}" alt="" loading="lazy">` : `<span aria-hidden="true">${m.video ? '🎬' : '🖼️'}</span>`}
            ${m.video ? '<i class="play" aria-hidden="true">▶</i>' : ''}</button></li>`).join('');
        return `<article class="report${r.kind === 'done' ? ' done' : ''}${r.visible ? '' : ' hidden-r'}" data-report-id="${r.id}">
            <header><span class="one">${esc(r.kind === 'done' ? t('✅ {stage} — בוצע', { stage: r.stage_name || t('כללי') }) : (r.stage_name ? t(r.stage_name) : t('כללי')))}${r.supplier_name ? ' · ' + esc(r.supplier_name) : ''}</span>
                <time datetime="${esc(r.at)}">${time}</time></header>
            ${r.text ? `<p>${esc(r.text)}</p>` : ''}
            ${media ? `<ul class="media-grid">${media}</ul>` : ''}
            <footer><span class="one">${esc(r.author || '')}</span>
                <button type="button" class="btn ghost sm" data-visible="${r.id}" aria-pressed="${r.visible}">${esc(r.visible ? t('👁 גלוי ללקוח') : t('🙈 פנימי'))}</button>
                ${r.kind === 'done' ? '' : `<button type="button" class="icon-btn" data-delete-report="${r.id}" aria-label="${esc(t('מחיקת העדכון'))}">🗑</button>`}</footer>
        </article>`;
    }

    // ================= חלון =================

    function open(preset = {}) {
        const dlg = $('#report-dlg'), form = $('form', dlg), el = form.elements;
        formError(form, '');
        picked.forEach(p => URL.revokeObjectURL(p.url));
        picked = [];
        el.text.value = '';
        el.visible.checked = true;
        $(`#report-dlg [name=kind][value=${preset.kind || 'update'}]`).checked = true;
        el.stage_id.innerHTML = options(project.stages.map(s => ({ value: s.id, label: `${stageName(s)} · ${state.labels.stage[s.status]}` })), preset.stage_id || '', t('בלי שלב — עדכון כללי'));
        suppliers();
        drawPicked();
        kindChanged();
        $('#report-progress').innerHTML = '';
        dlg.showModal();
    }

    /**
     * "של איזה ספק" — הספקים שעובדים בשלב שנבחר (שלו, הצעות, אבני דרך) קודם, ואז השאר. ספק יחיד בשלב — נבחר לבד,
     * כך שהתיעוד מהשטח מגיע לתיק ההתקשרות (ספק × שלב).
     */
    function suppliers() {
        const el = $('#report-dlg form').elements, s = project.stages.find(x => x.id === Number(el.stage_id.value));
        const here = new Map();
        if (s?.supplier_id) here.set(s.supplier_id, s.supplier_name);
        for (const e of s?.engaged || []) here.set(e.supplier_id, e.name);
        const all = new Map(here);
        for (const x of project.stages) { if (x.supplier_id) all.set(x.supplier_id, x.supplier_name); for (const e of x.engaged || []) all.set(e.supplier_id, e.name); }
        const opt = ([id, name]) => ({ value: id, label: name });
        el.supplier_id.innerHTML = options([...here].map(opt), here.size === 1 ? [...here.keys()][0] : '', t('בלי ספק'))
            + ([...all].filter(([id]) => !here.has(id)).length ? `<optgroup label="${esc(t('ספקים אחרים בפרויקט'))}">${options([...all].filter(([id]) => !here.has(id)).map(opt))}</optgroup>` : '');
    }

    function kindChanged() {
        const done = kindNow() === 'done';
        const sel = $('#report-dlg form').elements.stage_id;
        sel.options[0].disabled = done;
        if (done && !sel.value) sel.value = project.stages.find(s => !['done', 'paid'].includes(s.status))?.id || '';
        suppliers();
        $('#report-stage-label').textContent = done ? t('איזה שלב בוצע *') : t('שלב');
        $('#report-dlg-title').textContent = done ? t('השלב בוצע') : t('עדכון מהשטח');
        $('#report-go').textContent = done ? t('סימון "בוצע"') : t('שליחה');
        hint();
    }

    function hint() {
        const done = kindNow() === 'done';
        const n = picked.length;
        const size = Files.fmtSize(picked.reduce((a, p) => a + p.file.size, 0));
        $('#report-hint').textContent = done && !n ? t('כדי לסמן "בוצע" — צריך לפחות תמונה או סרטון אחד.')
            : n ? (n > 1 ? t('{n} קבצים · {size}', { n, size }) : t('קובץ אחד · {size}', { size })) : '';
    }

    function addFiles(files) {
        for (const f of files) {
            if (!/^(image|video)\//.test(f.type)) { toast(t('{name}: רק תמונות וסרטונים', { name: f.name }), true); continue; }
            if (f.size > state.maxUpload) { toast(t('{name}: גדול מ-{mb}MB', { name: f.name, mb: Math.round(state.maxUpload / 1048576) }), true); continue; }
            picked.push({ file: f, url: URL.createObjectURL(f), video: f.type.startsWith('video/') });
        }
        drawPicked();
    }

    function drawPicked() {
        $('#report-media').innerHTML = picked.map((p, i) => `<li><span class="tile">
            ${p.video ? `<video src="${p.url}" muted playsinline preload="metadata"></video><i class="play" aria-hidden="true">▶</i>` : `<img src="${p.url}" alt="">`}
            <button type="button" class="x" data-unpick="${i}" aria-label="${esc(t('הסרה'))}">✕</button></span></li>`).join('');
        hint();
    }

    async function save() {
        const form = $('#report-dlg form'), el = form.elements;
        const kind = kindNow(), stageId = Number(el.stage_id.value) || null;
        if (kind === 'done' && !stageId) { formError(form, t('איזה שלב בוצע?')); return; }
        if (kind === 'done' && !picked.length) { formError(form, t('כדי לסמן "בוצע" — צריך לפחות תמונה או סרטון אחד')); return; }
        if (!picked.length && !el.text.value.trim()) { formError(form, t('כתבו מה נעשה, או צרפו תמונה')); return; }
        const box = $('#report-progress');
        await busy($('#report-go'), form, async () => {
            const { folder_id } = await api('report-folder', { params: { project_id: project.project.id, stage_id: stageId || '' } });
            const ids = [];
            for (const [i, p] of picked.entries()) {
                const bar = pct => { box.innerHTML = `<div class="up"><span class="one">${i + 1}/${picked.length} · ${esc(p.file.name)}</span><span>${pct}%</span><i style="width:${pct}%"></i></div>`; };
                bar(0);
                const { file } = await Files.send(p.file, project.project.id, folder_id, bar, { quiet: true, type: p.video ? 'video' : 'photo' });
                ids.push(file.id);
            }
            box.innerHTML = '';
            ({ reports } = await api('report', { body: { project_id: project.project.id, stage_id: stageId, supplier_id: Number(el.supplier_id.value) || null, kind, text: el.text.value, visible: el.visible.checked, file_ids: ids } }));
            picked.forEach(p => URL.revokeObjectURL(p.url));
            picked = [];
            $('#report-dlg').close();
            project = await Projects.reload();
            render();
            toast(kind === 'done' ? t('השלב סומן "בוצע"') : t('העדכון נשמר'));
        });
        box.innerHTML = '';
    }

    async function toggleVisible(id) {
        const r = reports.find(x => x.id === id);
        try { ({ reports } = await api('report-update', { body: { id, text: r.text, visible: !r.visible } })); render(); }
        catch (e) { toast(e.message, true); }
    }

    async function remove(id) {
        if (!confirm(t('למחוק את העדכון? התמונות יישארו בתיקייה.'))) return;
        try { ({ reports } = await api('report-delete', { body: { id } })); render(); toast(t('נמחק')); }
        catch (e) { toast(e.message, true); }
    }

    return { show, markDone, focus };
})();
