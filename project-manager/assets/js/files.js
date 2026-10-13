// קבצים ותיקיות של פרויקט — שלב 3 (lib/files.php).
//
// השורש: התיקיות הראשיות ("ממתין למיון", הלקוח, עו"ד, ספק לכל ספק…) והקבצים
// האחרונים. בתוך תיקייה: תת-תיקיות וקבצים. חיפוש או סינון לפי סוג — בכל הפרויקט.
// העלאה בחלקים של 2MB, עם התקדמות, חידוש אחרי ניתוק ותמונה ממוזערת מהדפדפן.
'use strict';

const Files = (() => {
    let project = null, folders = [], files = [], folderId = 0, bound = false, current = null;
    const uploads = new Map(); // מזהה מקומי → מצב, לפס ההתקדמות

    const TYPE_ICON = { quote: '🧾', proof: '💳', contract: '📝', plan: '📐', photo: '🖼️', video: '🎬', invoice: '🧾', other: '📄' };
    const fmtSize = n => n < 1024 ? n + 'B' : n < 1048576 ? Math.round(n / 1024) + 'KB' : (n / 1048576).toFixed(n < 10485760 ? 1 : 0) + 'MB';
    const fileUrl = (id, extra = '') => `api.php?action=file&id=${id}${extra}`;
    const folderById = id => folders.find(f => f.id === id);
    const inbox = () => folders.find(f => f.kind === 'inbox' && f.parent_id === null);

    // i18n-ok-start — מתורגם בתצוגה
    const USE = { quote: ['🧾', 'הצעת מחיר'], contract: ['📝', 'חוזה'], change: ['±', 'תוספת / חריגה'], payment: ['🗓️', 'אבן דרך'],
        invoice: ['🧾', 'חשבונית'], proof: ['💳', 'אסמכתא'], expense: ['🧾', 'הוצאה ישירה'], report: ['📷', 'דיווח מהשטח'] };
    // i18n-ok-end
    /** "🧾 הצעת מחיר — אבי · חשמל": איפה הקובץ משמש. */
    function useLabel(u) {
        const [ic, l] = USE[u.kind] || ['📄', ''];
        const what = u.kind === 'quote' ? [u.supplier, t(u.text)] : u.kind === 'contract' ? [u.supplier, u.text] : [u.text, u.supplier];
        return `${ic} ${t(l)} — ${what.filter(Boolean).join(' · ')}`;
    }
    // סוגים שהם מסמך של רשומה — בלי שיוך, הקובץ "לא משויך" (למשל הצעה שהועלתה ב"קבצים" ולא נרשמה כהצעה)
    const RECORD_TYPES = ['quote', 'contract', 'invoice', 'proof'];

    /** הנתיב המלא של תיקייה, לבחירה בחלונות: "הלקוח / חוזים". */
    function path(id) {
        const parts = [];
        for (let f = folderById(id); f; f = folderById(f.parent_id)) parts.unshift(t(f.name));
        return parts.join(' / ');
    }

    function bind() {
        if (bound) return;
        bound = true;
        let timer;
        $('#fq').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadFiles, 250); });
        $('#ftype').addEventListener('change', loadFiles);
        $('#fpick').addEventListener('change', e => { upload([...e.target.files]); e.target.value = ''; });
        $('#new-folder').addEventListener('click', () => openFolder(null));
        $('#folder-list').addEventListener('click', e => {
            const edit = e.target.closest('[data-edit-folder]');
            if (edit) { e.stopPropagation(); openFolder(Number(edit.dataset.editFolder)); return; }
            const row = e.target.closest('[data-folder]');
            if (row) go(Number(row.dataset.folder));
        });
        $('#crumbs').addEventListener('click', e => {
            const a = e.target.closest('[data-go]');
            if (a) { e.preventDefault(); go(Number(a.dataset.go)); }
        });
        $('#file-list').addEventListener('click', e => {
            const row = e.target.closest('[data-file]');
            if (row) openFile(Number(row.dataset.file));
        });
        for (const id of ['#folder-list', '#file-list']) {
            $(id).addEventListener('keydown', e => {
                const row = e.target.closest('[data-file], [data-folder]');
                if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); row.click(); }
            });
        }
        // גרירת קבצים מהמחשב
        const pane = $('#pane-files');
        pane.addEventListener('dragover', e => { if (e.dataTransfer?.types?.includes('Files')) { e.preventDefault(); $('#drop-hint').hidden = false; } });
        pane.addEventListener('dragleave', e => { if (!pane.contains(e.relatedTarget)) $('#drop-hint').hidden = true; });
        pane.addEventListener('drop', e => { e.preventDefault(); $('#drop-hint').hidden = true; upload([...e.dataTransfer.files]); });

        $('#file-dlg form').addEventListener('submit', e => { e.preventDefault(); saveFile(); });
        // סגירה: עוצרים ציור של עמודים וסרטון שרץ
        $('#file-dlg').addEventListener('close', () => { stopPreview?.(); stopPreview = null; $('#file-preview').innerHTML = ''; });
        $('#file-open').addEventListener('click', () => Viewer.open(current));
        $('#add-link').addEventListener('click', addLink);
        $('#file-links').addEventListener('click', e => { const b = e.target.closest('[data-unlink]'); if (b) unlink(Number(b.dataset.unlink)); });
        $('#file-delete').addEventListener('click', deleteFile);
        $('#file-uses').addEventListener('click', e => { const b = e.target.closest('[data-use]'); if (b) goUse(current.uses[Number(b.dataset.use)]); });
        $('#file-attach-btns').addEventListener('click', e => { const b = e.target.closest('[data-attach]'); if (b) attachStart(b.dataset.attach); });
        $('#file-attach-go').addEventListener('click', attachGo);
        $('#file-dlg [name=type]').addEventListener('change', attachButtons);
        $('#file-quote').addEventListener('click', () => {
            // הספק — לפי התיקייה שהקובץ יושב בה
            const home = folderById(current.folders.find(l => l.home)?.folder_id);
            let supplier = null;
            for (let f = home; f && !supplier; f = folderById(f.parent_id)) if (f.kind === 'contact') supplier = f.contact_id;
            $('#file-dlg').close();
            Quotes.fromFile(project.id, { file_id: current.id, supplier_id: supplier });
        });
        $('#folder-dlg form').addEventListener('submit', e => { e.preventDefault(); saveFolder(); });
        $('#folder-delete').addEventListener('click', deleteFolder);
    }

    async function show(p, folder) {
        bind();
        const switched = project?.id !== p.id;
        project = p;
        folderId = folder || 0;
        $('#ftype').innerHTML = options(Object.entries(state.fileTypes).map(([value, label]) => ({ value, label: t(label) })), '', t('כל הסוגים'));
        if (switched) { $('#fq').value = ''; }
        await loadFolders();
        await loadFiles();
    }

    function go(id) {
        location.hash = `#/p/${project.id}/files` + (id ? '/' + id : '');
    }

    async function loadFolders() {
        await busy(null, null, async () => { ({ folders } = await api('folders', { params: { project_id: project.id } })); });
    }

    async function loadFiles() {
        const q = $('#fq').value.trim(), type = $('#ftype').value;
        const searching = !!(q || type);
        const params = { project_id: project.id };
        // חיפוש / סינון מתוך תיקייה — בתיקייה ובתת-התיקיות שלה; מהשורש — בכל הפרויקט
        if (searching) Object.assign(params, { q, type });
        if (folderId) params.folder_id = folderId;
        else if (!searching) params.recent = 1;
        await busy(null, null, async () => {
            ({ files } = await api('files', { params }));
            render(searching);
        });
    }

    function render(searching) {
        // פירורי לחם
        const trail = [];
        for (let f = folderById(folderId); f; f = folderById(f.parent_id)) trail.unshift(f);
        $('#crumbs').innerHTML = `<a href="#" data-go="0">${esc(t('כל הקבצים'))}</a>` + trail.map(f =>
            `<i class="dir-flip" aria-hidden="true">‹</i><a href="#" data-go="${f.id}"${f.id === folderId ? ' aria-current="page"' : ''}>${esc(t(f.name))}</a>`).join('');

        // תיקיות (לא בחיפוש)
        const subs = searching ? [] : folders.filter(f => (f.parent_id || 0) === folderId);
        const count = id => folders.filter(f => f.parent_id === id).length;
        $('#folder-list').innerHTML = subs.map(f => `<li class="card" data-folder="${f.id}" tabindex="0" role="button" aria-label="${esc(t(f.name))}">
            <span class="ficon" aria-hidden="true">${f.icon}</span>
            <div class="body"><div class="l1"><span class="one">${esc(t(f.name))}</span></div>
                <div class="l2">${[f.files ? esc(t('{n} קבצים', { n: f.files })) : esc(t('ריקה')), count(f.id) ? esc(t('{n} תיקיות', { n: count(f.id) })) : ''].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>')}</div></div>
            ${f.system ? '' : `<button class="icon-btn" data-edit-folder="${f.id}" aria-label="${esc(t('עריכת התיקייה {name}', { name: f.name }))}">✏️</button>`}
        </li>`).join('');
        $('#folder-list').hidden = !subs.length;

        $('#files-title').hidden = false;
        $('#files-title').textContent = searching ? (folderId ? t('תוצאות בתיקייה הזו ({n})', { n: files.length }) : t('תוצאות בכל הפרויקט ({n})', { n: files.length }))
            : folderId ? t('קבצים') : t('הועלו לאחרונה');
        $('#file-list').innerHTML = files.map(fileRow).join('')
            || `<p class="empty">${esc(searching ? t('לא נמצאו קבצים') : folderId ? t('התיקייה ריקה. "+ העלאה" או גרירה לכאן.') : t('עוד אין קבצים בפרויקט.'))}</p>`;
        // בשורש: העלאה נכנסת ל"ממתין למיון"
        $('.upload-btn').title = folderId ? t('העלאה לתיקייה הזו') : t('העלאה ל"ממתין למיון"');
    }

    function fileRow(f) {
        const here = folderId && f.folders.find(l => l.folder_id === folderId);
        const shortcut = here && !here.home;
        const home = f.folders.find(l => l.home);
        const thumb = f.has_thumb
            ? `<img class="fthumb" src="${fileUrl(f.id, '&thumb=1')}" alt="" loading="lazy" width="40" height="40">`
            : `<span class="ficon" aria-hidden="true">${TYPE_ICON[f.type] || '📄'}</span>`;
        const meta = [
            esc(t(state.fileTypes[f.type] || '')), fmtSize(f.size), when(f.created_at),
            home && (!folderId || home.folder_id !== folderId) ? esc(path(home.folder_id)) : '',
        ].filter(Boolean).join('<i class="sep" aria-hidden="true">·</i>');
        return `<li class="card" data-file="${f.id}" tabindex="0" role="button" aria-label="${esc(f.name)}">
            ${thumb}
            <div class="body"><div class="l1"><span class="one">${esc(f.name)}</span>
                ${f.visible ? `<span class="chip" title="${esc(t('גלוי בפורטל'))}">👁</span>` : ''}${shortcut ? `<span class="chip" title="${esc(t('קיצור דרך'))}">🔗</span>` : ''}</div>
                <div class="l2">${meta}</div>
                ${f.uses?.length ? `<div class="l2 f-uses">${f.uses.slice(0, 2).map(u => `<span class="chip">${esc(useLabel(u))}</span>`).join('')}${f.uses.length > 2 ? `<span class="chip">+${f.uses.length - 2}</span>` : ''}</div>`
                    : RECORD_TYPES.includes(f.type) ? `<div class="l2 f-uses"><span class="chip fin-warn">${esc(t('לא משויך — לחצו לשיוך'))}</span></div>` : ''}</div>
        </li>`;
    }

    // ================= העלאה =================

    async function upload(list) {
        if (!list.length) return;
        const target = folderId || inbox()?.id;
        if (!target) { toast(t('אין תיקייה להעלאה'), true); return; }
        for (const file of list) {
            if (file.size > state.maxUpload) { toast(t('{name}: גדול מ-{mb}MB', { name: file.name, mb: Math.round(state.maxUpload / 1048576) }), true); continue; }
            await uploadOne(file, target);
        }
        await loadFolders();
        await loadFiles();
    }

    async function uploadOne(file, folder) {
        const key = Math.random().toString(36).slice(2);
        uploads.set(key, { name: file.name, pct: 0 });
        renderUploads();
        try {
            await send(file, project.id, folder, (pct, info) => { Object.assign(uploads.get(key), { pct, info }); renderUploads(); });
            uploads.delete(key);
        } catch (e) {
            uploads.get(key).error = e.message;
            setTimeout(() => { uploads.delete(key); renderUploads(); }, 15000);
        }
        renderUploads();
    }

    // ---- העלאה בחלקים ----
    // כל חלק (2MB) נשלח ב-XHR, כדי לראות התקדמות בזמן אמת — גם בתוך החלק (ברשת סלולרית איטית
    // חלק אחד לוקח עשרות שניות, ובלי זה האחוז "תקוע" על 0). חיבור שלא זז 30 שניות — נקטע ומנסים
    // שוב מהמקום שהשרת קיבל; בלי רשת — מחכים שתחזור. אחרי 8 ניסיונות — שגיאה, והיא נרשמת ביומן.
    const STALL_MS = 30000, MAX_TRIES = 8;
    let active = 0; // העלאות פעילות — אזהרה לפני סגירת הדף

    function putChunk(url, blob, onBytes) {
        return new Promise((resolve, reject) => {
            const x = new XMLHttpRequest();
            let timer;
            const arm = () => { clearTimeout(timer); timer = setTimeout(() => { x.abort(); reject(new Error(t('החיבור נתקע'))); }, STALL_MS); };
            x.open('POST', url);
            x.withCredentials = true;
            x.setRequestHeader('Content-Type', 'application/octet-stream');
            x.setRequestHeader('X-CSRF', state.user.csrf);
            x.upload.onprogress = e => { arm(); onBytes(e.loaded); };
            x.onload = () => {
                clearTimeout(timer);
                let data = {};
                try { data = JSON.parse(x.responseText); } catch { /* לא JSON */ }
                if (x.status >= 200 && x.status < 300 && typeof data.received === 'number') resolve(data);
                else { const err = new Error(data.error || t('תקלה בהעלאה') + ` (${x.status})`); err.status = x.status; reject(err); }
            };
            x.onerror = () => { clearTimeout(timer); reject(new Error(t('אין חיבור לשרת'))); };
            arm();
            x.send(blob);
        });
    }

    const online = () => (navigator.onLine === false ? new Promise(r => addEventListener('online', r, { once: true })) : Promise.resolve());

    /**
     * העלאת קובץ אחד לתיקייה — בחלקים, עם חידוש. משמש גם את מסך השיתוף ואת החלונות.
     * onProgress(pct, {sent, total, speed (בתים לשנייה), left (שניות), retry}).
     */
    async function send(file, projectId, folder, onProgress = () => {}, finish = {}) {
        // התמונה הממוזערת (גם עמוד ראשון של PDF) — במקביל להעלאה, לא לפניה
        const thumbP = makeThumb(file).catch(() => '');
        const { upload_id, chunk_size } = await api('upload-start', { body: {
            project_id: projectId, folder_id: folder, name: file.name, size: file.size, mime: file.type } });
        let offset = 0, tries = 0;
        const t0 = Date.now();
        const report = (sent, retry = '') => {
            const secs = (Date.now() - t0) / 1000, speed = secs > 1 ? sent / secs : 0;
            onProgress(Math.min(100, Math.round(sent / file.size * 100)), { sent, total: file.size, speed, left: speed ? Math.round((file.size - sent) / speed) : null, retry });
        };
        active++;
        try {
            while (offset < file.size) {
                const blob = file.slice(offset, offset + chunk_size);
                try {
                    await online();
                    const data = await putChunk(`api.php?action=upload-chunk&id=${upload_id}&offset=${offset}`, blob, b => report(offset + b));
                    offset = data.received; // השרת אומר מאיפה להמשיך — גם אחרי ניתוק
                    tries = 0;
                    report(offset);
                } catch (e) {
                    // שגיאה מהשרת (4xx) — לא תעבור בניסיון חוזר
                    if (e.status >= 400 && e.status < 500 && e.status !== 408 && e.status !== 429) { logFail(file, offset, e); throw e; }
                    if (++tries > MAX_TRIES) { logFail(file, offset, e); throw new Error(t('ההעלאה נכשלה: {error}', { error: e.message })); }
                    const wait = Math.min(30, 2 ** tries);
                    report(offset, t('{error} — מנסים שוב בעוד {n} שניות', { error: e.message, n: wait }));
                    await new Promise(r => setTimeout(r, wait * 1000));
                }
            }
            return await api('upload-finish', { body: { upload_id, thumb: await thumbP, type: guessType(file), ...finish } });
        } finally { active--; }
    }

    /** העלאה שנכשלה — ליומן, כדי שיהיה אפשר לראות מה קרה (בלי זה אין שום עקבה בשרת). */
    function logFail(file, offset, e) {
        fetch('api.php?action=client-error', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-PM': '1' },
            body: JSON.stringify({ kind: 'upload', message: `upload failed: ${e.message}`, page: location.pathname + location.hash,
                stack: JSON.stringify({ name: file.name, size: file.size, offset, status: e.status || 0, online: navigator.onLine }) }),
        }).catch(() => {});
    }

    addEventListener('beforeunload', e => { if (active) { e.preventDefault(); e.returnValue = ''; } });

    const mb = n => (n / 1048576).toFixed(n < 10485760 ? 1 : 0);
    function upLine(u) {
        if (u.error) return esc(u.error);
        const i = u.info;
        if (!i) return u.pct + '%';
        const left = i.left == null ? '' : i.left >= 60 ? t('נשארו ~{n} דק\'', { n: Math.ceil(i.left / 60) }) : t('נשארו ~{n} שנ\'', { n: Math.max(1, i.left) });
        return esc(i.retry || [`${u.pct}%`, t('{a} מתוך {b}MB', { a: mb(i.sent), b: mb(i.total) }), left].filter(Boolean).join(' · '));
    }

    function renderUploads() {
        $('#uploads').innerHTML = [...uploads.values()].map(u => `<div class="up${u.error ? ' err' : ''}${u.info?.retry ? ' retry' : ''}">
            <span class="one">${esc(u.name)}</span><span class="up-st">${upLine(u)}</span>
            <i style="width:${u.pct}%"></i></div>`).join('');
    }

    /** סוג הקובץ לפי השם והתוכן — אפשר לשנות אחר כך בחלון הקובץ. */
    function guessType(file) {
        const n = file.name;
        // i18n-none-start
        if (/הצע[הת]|quote|offer/i.test(n)) return 'quote';
        if (/אסמכת|העבר|קבלה|receipt|transfer/i.test(n)) return 'proof';
        if (/חשבונית|invoice/i.test(n)) return 'invoice';
        if (/חוזה|הסכם|contract|agreement/i.test(n)) return 'contract';
        if (/תוכני|שרטוט|היתר|plan|dwg|dxf/i.test(n)) return 'plan';
        // i18n-none-end
        if (file.type.startsWith('image/')) return 'photo';
        if (file.type.startsWith('video/')) return 'video';
        return 'other';
    }

    /** תמונה ממוזערת (320px) לתמונה או לסרטון — בדפדפן, כדי שהשרת לא יצטרך. */
    async function makeThumb(file) {
        const draw = (src, w, h) => {
            const scale = Math.min(1, 320 / Math.max(w, h));
            const c = document.createElement('canvas');
            c.width = Math.round(w * scale); c.height = Math.round(h * scale);
            c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
            return c.toDataURL('image/jpeg', 0.7);
        };
        if (file.type.startsWith('image/') && 'createImageBitmap' in window) {
            const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
            return draw(bmp, bmp.width, bmp.height);
        }
        if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name)) {
            // העמוד הראשון — בדפדפן (pdf.js), כדי שברשימה יופיע המסמך עצמו ולא אייקון
            const doc = await Promise.race([Viewer.pdfDoc(await file.arrayBuffer()), new Promise((_, bad) => setTimeout(() => bad(new Error('timeout')), 10000))]);
            return Viewer.shrink(await Viewer.drawPage(await doc.getPage(1), 320));
        }
        if (file.type.startsWith('video/')) {
            const url = URL.createObjectURL(file);
            try {
                const v = document.createElement('video');
                v.muted = true; v.playsInline = true; v.preload = 'metadata'; v.src = url;
                await new Promise((ok, bad) => { v.onloadeddata = ok; v.onerror = bad; setTimeout(bad, 8000); });
                v.currentTime = Math.min(1, (v.duration || 2) / 2);
                await new Promise((ok, bad) => { v.onseeked = ok; setTimeout(bad, 8000); });
                return draw(v, v.videoWidth, v.videoHeight);
            } finally { URL.revokeObjectURL(url); }
        }
        return '';
    }

    // ================= חלון קובץ =================

    async function openFile(id) {
        let f;
        try { ({ file: f } = await api('file', { params: { id, meta: 1 } })); } catch (e) { toast(e.message, true); return; }
        current = f;
        const dlg = $('#file-dlg'), form = $('form', dlg), el = form.elements;
        formError(form, '');
        $('#file-dlg-title').textContent = f.name;
        const url = fileUrl(f.id);
        preview(f);
        $('#file-meta').textContent = [fmtSize(f.size), t('הועלה {date}', { date: new Date(f.created_at).toLocaleString(I18n.locale(), { dateStyle: 'short', timeStyle: 'short' }) }),
            f.uploader ? t('על ידי {name}', { name: f.uploader }) : ''].filter(Boolean).join(' · ');
        el.name.value = f.name;
        el.note.value = f.note;
        el.visible.checked = f.visible;
        el.type.innerHTML = options(Object.entries(state.fileTypes).map(([value, label]) => ({ value, label: t(label) })), f.type);
        const home = f.folders.find(l => l.home)?.folder_id;
        const all = folders.map(x => ({ value: x.id, label: path(x.id) })).sort((a, b) => a.label.localeCompare(b.label, I18n.locale()));
        el.home.innerHTML = options(all, home);
        renderLinks();
        $('#file-download').href = fileUrl(f.id, '&download=1');
        renderUses();
        dlg.showModal();
    }

    // ================= תצוגה: תמונה, סרטון, PDF — בתוך האפליקציה (viewer.js) =================

    let stopPreview = null;
    function preview(f) {
        const box = $('#file-preview');
        stopPreview?.(); stopPreview = null;
        box.className = 'fv';
        if (Viewer.isPdf(f) || f.mime.startsWith('image/')) {
            box.classList.add('fv-pdf');
            // PDF בלי תמונה ממוזערת (הועלה לפני שהיה ציור בדפדפן) — מהעמוד הראשון, כדי שיופיע ברשימה
            stopPreview = Viewer.mount(box, f, { onThumb: !f.has_thumb && Viewer.isPdf(f) ? c => api('file-thumb', { body: { id: f.id, thumb: Viewer.shrink(c) } }).catch(() => {}) : null });
        } else if (f.mime.startsWith('video/')) {
            box.innerHTML = `<video src="${fileUrl(f.id)}" controls preload="metadata" playsinline></video>`;
        } else {
            box.innerHTML = `<span class="ficon big" aria-hidden="true">${TYPE_ICON[f.type] || '📄'}</span>`;
        }
    }

    // ================= שימוש במערכת ושיוך =================
    // קובץ שהועלה ב"קבצים" צריך להגיע לאותו מקום כמו קובץ שהועלה מהחלון של ההצעה / החוזה / אבן הדרך:
    // כאן רואים איפה הוא משמש, ומשייכים אותו — והשרת מטפל בו כמו בכל צירוף (סוג, תיקייה — pm_file_claim).

    function renderUses() {
        const uses = current.uses || [];
        // משויך: שורה לכל רשומה (אייקון, מה, ספק · שלב) — לחיצה פותחת אותה. לא משויך: משפט אחד, והשיוך מתחת
        $('#file-uses').innerHTML = uses.length ? `<ul class="use-list">${uses.map((u, i) => {
            const [ic, l] = USE[u.kind] || ['📄', ''];
            const sub = u.kind === 'quote' ? [u.supplier, t(u.text)] : u.kind === 'contract' ? [u.supplier, u.text] : [u.text, u.supplier];
            return `<li><button type="button" class="use-row" data-use="${i}"><span class="use-ic" aria-hidden="true">${ic}</span>
                <span class="use-tx"><b>${esc(t(l))}</b><small>${esc(sub.filter(Boolean).join(' · '))}</small></span><span class="use-go" aria-hidden="true">‹</span></button></li>`;
        }).join('')}</ul>`
            : `<p class="use-empty${RECORD_TYPES.includes(current.type) ? ' warn' : ''}">${esc(RECORD_TYPES.includes(current.type) ? t('הקובץ עוד לא משויך — בחרו למטה למה הוא שייך.') : t('הקובץ לא משמש באף רשומה.'))}</p>`;
        $('#file-attach-title').textContent = uses.length ? t('שיוך נוסף') : t('לשייך את הקובץ ל…');
        $('#file-attach').hidden = true;
        attachButtons();
    }

    /** כפתורי השיוך — לפי הסוג שנבחר (גם לפני שמירה); בסוג כללי — כולם. */
    function attachButtons() {
        const type = $('#file-dlg [name=type]').value, open = ['other', 'photo'].includes(type);
        $('#file-quote').hidden = !(open || type === 'quote');
        for (const b of $$('#file-attach-btns [data-attach]')) b.hidden = !(open || type === b.dataset.attach);
        // אריח אחד — לכל הרוחב
        $('#file-attach-btns').classList.toggle('one', $$('#file-attach-btns .tile:not([hidden])').length === 1);
    }

    /** מהקובץ לרשומה: ההצעה, החוזה, אבן הדרך, התוספת, ההוצאה, הדיווח. */
    function goUse(u) {
        $('#file-dlg').close();
        const pid = project.id;
        if (u.kind === 'quote') { Quotes.openWhenReady(u.id); location.hash = `#/p/${pid}/quotes`; }
        else if (u.kind === 'contract') Finance.openWhenReady({ contract: u.id, supplier: u.supplier_id }, pid);
        else if (u.kind === 'change') Finance.openWhenReady({ change: u.id }, pid);
        else if (u.kind === 'expense') Finance.openWhenReady({ expense: u.id }, pid);
        else if (u.payment_id) { Payments.openWhenReady(u.payment_id); location.hash = `#/p/${pid}/payments`; }
        else if (u.kind === 'report') location.hash = `#/p/${pid}/field`;
    }

    let attachKind = null, attachList = [];
    async function attachStart(kind) {
        attachKind = kind;
        const form = $('#file-dlg form');
        formError(form, '');
        attachList = [];
        if (kind === 'contract') {
            // הספקים של הפרויקט — מהתיקיות שלהם
            attachList = folders.filter(f => f.kind === 'contact' && f.parent_id === null).map(f => ({ value: f.contact_id, label: f.name }));
        } else {
            let payments = [];
            try { ({ payments } = await api('payments', { params: { project_id: project.id } })); } catch (e) { formError(form, e.message); return; }
            const ok = kind === 'invoice' ? p => p.received > 0 && !p.invoice : p => ['planned', 'requested', 'partial', 'paid'].includes(p.status);
            attachList = payments.filter(p => p.status !== 'cancelled' && ok(p)).map(p => ({ value: p.id, label: `${p.title} — ${p.supplier_name}` }));
        }
        $('#file-attach-label').textContent = kind === 'contract' ? t('הספק') : t('אבן הדרך');
        $('#file-attach-to').innerHTML = options(attachList);
        const hint = $('#file-attach-hint');
        hint.textContent = !attachList.length ? (kind === 'contract' ? t('אין עדיין ספקים בפרויקט.') : kind === 'invoice' ? t('אין אבן דרך שהתקבל בה תשלום ועוד אין לה חשבונית.') : t('אין אבן דרך פתוחה לתשלום.'))
            : kind === 'contract' ? t('נפתח החוזה של הספק, עם הקובץ הזה כמסמך — שם בוחרים חוזה קיים או חדש ושומרים.')
            : kind === 'proof' ? t('נפתחת אבן הדרך, עם הקובץ כאסמכתא — שם רושמים סכום ותאריך.') : '';
        hint.hidden = !hint.textContent;
        $('#file-attach-go').disabled = !attachList.length;
        $('#file-attach').hidden = false;
    }

    async function attachGo() {
        const to = Number($('#file-attach-to').value);
        if (!to) return;
        const f = { id: current.id, name: current.name }, pid = project.id;
        if (attachKind === 'invoice') {
            await busy($('#file-attach-go'), $('#file-dlg form'), async () => {
                await api('payment-invoice', { body: { id: to, invoice_no: '', file_id: f.id } });
                toast(t('החשבונית נשמרה'));
                $('#file-dlg').close();
                await openFile(f.id);
                await loadFolders(); await loadFiles();
            });
            return;
        }
        $('#file-dlg').close();
        if (attachKind === 'contract') Finance.openWhenReady({ contract: null, supplier: to, file: f.id }, pid);
        else if (attachKind === 'proof') { Payments.openWhenReady(to, { proof: f }); location.hash = `#/p/${pid}/payments`; }
    }

    // ================= בחירת קובץ קיים (מהחלונות: תוספת, הוצאה, אסמכתא, חשבונית) =================

    let chooser = null;
    /**
     * בחירת קובץ שכבר בפרויקט — במקום להעלות שוב. prefer: הסוגים שמתאימים (ראשונים), וקבצים שעוד לא משויכים לפני
     * המשויכים. מחזיר {id, name} או null.
     */
    function choose(projectId, prefer = []) {
        return new Promise(async resolve => {
            let list = [];
            try { ({ files: list } = await api('files', { params: { project_id: projectId } })); } catch (e) { toast(e.message, true); resolve(null); return; }
            const rank = f => (prefer.includes(f.type) ? 0 : 2) + (f.uses?.length ? 1 : 0);
            list.sort((a, b) => rank(a) - rank(b) || b.created_at.localeCompare(a.created_at));
            if (!chooser) {
                chooser = document.createElement('dialog');
                chooser.id = 'fchoose-dlg';
                chooser.setAttribute('aria-labelledby', 'fchoose-title');
                chooser.innerHTML = `<form method="dialog" novalidate><header class="sheet-head">
                    <button type="button" class="icon-btn" data-close aria-label="${esc(t('סגירה'))}">✕</button>
                    <h2 id="fchoose-title">${esc(t('בחירת קובץ מהפרויקט'))}</h2><span></span></header>
                    <div class="sheet-body"><input type="search" id="fchoose-q" placeholder="${esc(t('חיפוש לפי שם'))}" aria-label="${esc(t('חיפוש לפי שם'))}">
                    <ul class="list fchoose" id="fchoose-list"></ul></div></form>`;
                document.body.appendChild(chooser);
                chooser.querySelector('[data-close]').addEventListener('click', () => chooser.close());
            }
            const q = chooser.querySelector('#fchoose-q'), ul = chooser.querySelector('#fchoose-list');
            const draw = () => {
                const s = q.value.trim().toLowerCase();
                const shown = list.filter(f => !s || f.name.toLowerCase().includes(s));
                ul.innerHTML = shown.map(f => `<li><button type="button" class="fch-row" data-id="${f.id}">
                    <span class="ficon" aria-hidden="true">${TYPE_ICON[f.type] || '📄'}</span>
                    <span class="body"><span class="one">${esc(f.name)}</span>
                    <small>${esc([t(state.fileTypes[f.type] || ''), new Date(f.created_at).toLocaleDateString(I18n.locale()), ...(f.uses || []).slice(0, 1).map(useLabel)].filter(Boolean).join(' · '))}</small></span></button></li>`).join('')
                    || `<li class="empty">${esc(t('לא נמצאו קבצים'))}</li>`;
            };
            q.value = '';
            draw();
            q.oninput = draw;
            let picked = null;
            ul.onclick = e => { const b = e.target.closest('[data-id]'); if (!b) return; const f = list.find(x => x.id === Number(b.dataset.id)); picked = { id: f.id, name: f.name }; chooser.close(); };
            chooser.onclose = () => resolve(picked);
            chooser.showModal();
        });
    }

    function renderLinks() {
        const links = current.folders.filter(l => !l.home);
        $('#file-links').innerHTML = links.map(l => `<span class="chip link">🔗 ${esc(path(l.folder_id))}
            <button type="button" class="x" data-unlink="${l.folder_id}" aria-label="${esc(t('הסרת קיצור הדרך מ{folder}', { folder: path(l.folder_id) }))}">✕</button></span>`).join('')
            || `<span class="hint">${esc(t('רק בתיקייה שלו'))}</span>`;
        const used = new Set(current.folders.map(l => l.folder_id));
        $('#file-dlg [name=link_to]').innerHTML = options(folders.filter(x => !used.has(x.id))
            .map(x => ({ value: x.id, label: path(x.id) })).sort((a, b) => a.label.localeCompare(b.label, I18n.locale())));
    }

    async function saveFile() {
        const form = $('#file-dlg form'), el = form.elements;
        await busy($('button[type=submit]', form), form, async () => {
            let { file } = await api('file-update', { body: { id: current.id, name: el.name.value, type: el.type.value, note: el.note.value, visible: el.visible.checked } });
            const home = Number(el.home.value);
            if (home && home !== file.folders.find(l => l.home)?.folder_id) ({ file } = await api('file-move', { body: { id: current.id, folder_id: home } }));
            $('#file-dlg').close();
            toast(t('נשמר'));
            await loadFolders();
            await loadFiles();
        });
    }

    async function addLink() {
        const to = Number($('#file-dlg [name=link_to]').value);
        if (!to) return;
        await busy($('#add-link'), $('#file-dlg form'), async () => {
            ({ file: current } = await api('file-link', { body: { id: current.id, folder_id: to, add: true } }));
            renderLinks();
            toast(t('נוסף קיצור דרך'));
        });
    }

    async function unlink(folder) {
        await busy(null, $('#file-dlg form'), async () => {
            ({ file: current } = await api('file-link', { body: { id: current.id, folder_id: folder, add: false } }));
            renderLinks();
        });
    }

    async function deleteFile() {
        if (!confirm(t('למחוק את "{name}"? הוא ייעלם מכל התיקיות.', { name: current.name }))) return;
        await busy(null, $('#file-dlg form'), async () => {
            await api('file-delete', { body: { id: current.id } });
            $('#file-dlg').close();
            toast(t('נמחק'));
            await loadFolders();
            await loadFiles();
        });
    }

    // ================= חלון תיקייה =================

    let editingFolder = null;

    function openFolder(id) {
        editingFolder = id;
        const dlg = $('#folder-dlg'), form = $('form', dlg);
        formError(form, '');
        const f = id ? folderById(id) : null;
        $('#folder-dlg-title').textContent = f ? t('עריכת תיקייה') : t('תיקייה חדשה');
        form.elements.name.value = f ? t(f.name) : ''; // שם שנזרע — מוצג מתורגם, ונשלח כמו שהוא אם לא שונה
        $('#folder-where').textContent = f ? t('ב: {folder}', { folder: path(f.parent_id) || t('כל הקבצים') })
            : t('תיפתח בתוך: {folder}', { folder: path(folderId) || t('כל הקבצים') });
        $('#folder-delete').hidden = !f;
        dlg.showModal();
        form.elements.name.focus();
    }

    async function saveFolder() {
        const form = $('#folder-dlg form');
        let name = form.elements.name.value.trim();
        if (!name) { formError(form, t('שם התיקייה: שדה חובה')); return; }
        const orig = editingFolder ? folderById(editingFolder)?.name : null;
        if (orig && name === t(orig)) name = orig;
        await busy($('button[type=submit]', form), form, async () => {
            const body = editingFolder ? { id: editingFolder, name } : { project_id: project.id, parent_id: folderId || null, name };
            ({ folders } = await api('folder', { body }));
            $('#folder-dlg').close();
            await loadFiles();
        });
    }

    async function deleteFolder() {
        const f = folderById(editingFolder);
        if (!f || !confirm(t('למחוק את התיקייה "{name}"?', { name: f.name }))) return;
        await busy(null, $('#folder-dlg form'), async () => {
            ({ folders } = await api('folder-delete', { body: { id: f.id } }));
            $('#folder-dlg').close();
            if (folderId === f.id) go(f.parent_id || 0); else await loadFiles();
        });
    }

    return { show, send, fmtSize, choose };
})();
