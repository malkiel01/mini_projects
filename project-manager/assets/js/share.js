// שיתוף לאפליקציה — שלב 4. כמו "שתף אל…" בוואטסאפ: בוחרים קבצים בגלריה או בכל
// אפליקציה, "שיתוף" ← "פרויקטים", ונפתח המסך הזה: מה התקבל, ולאן לשמור.
//
//   #/share        מה התקבל + הפרויקטים (האחרונים קודם). "⚡ למיון" שומר בלחיצה
//                  אחת ל"ממתין למיון" של הפרויקט; לחיצה על הפרויקט — בחירת תיקייה.
//   #/share/<id>   התיקיות של הפרויקט.
//
// הקבצים מחכים במכשיר (ShareStore, שנשמר ב-sw.js) עד שנשמרו בשרת — גם אם האפליקציה
// נסגרה או שצריך קודם להתחבר. טקסט או קישור ששותפו נשמרים כהערה בציר הזמן.
'use strict';

const Share = (() => {
    const LAST = 'pm.shareLast'; // היעד האחרון — מוצע ראשון בפעם הבאה
    let items = [], projects = [], folders = [], project = null, bound = false, saving = false, urls = [];

    const entries = () => items.flatMap(it => it.files.map((file, i) => ({ item: it, file, i })));
    const noteOf = it => [...new Set([it.title, it.text, it.url].map(s => (s || '').trim()).filter(Boolean))].join('\n');
    const notes = () => items.filter(it => noteOf(it));
    const readLast = () => { try { return JSON.parse(localStorage.getItem(LAST)) || null; } catch { return null; } };

    function bind() {
        if (bound) return;
        bound = true;
        $('#sq').addEventListener('input', renderProjects);
        $('#share-items').addEventListener('click', e => {
            const b = e.target.closest('[data-drop]');
            if (b) drop(b.dataset.drop);
        });
        $('#share-targets').addEventListener('click', e => {
            if (saving) return;
            const el = e.target.closest('[data-inbox], [data-folder], [data-project], [data-last]');
            if (!el) return;
            e.stopPropagation();
            if (el.dataset.inbox) saveToInbox(Number(el.dataset.inbox));
            else if (el.dataset.folder) save(project.id, Number(el.dataset.folder));
            else if (el.dataset.last) { const l = readLast(); save(l.project, l.folder); }
            else if (entries().length) location.hash = '#/share/' + el.dataset.project;
            else save(Number(el.dataset.project), 0); // רק טקסט — ישר לציר הזמן
        });
        $('#share-targets').addEventListener('keydown', e => {
            const row = e.target.closest('[role=button]');
            if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); row.click(); }
        });
        $('#share-cancel').addEventListener('click', async () => {
            if (!confirm(t('לבטל את השיתוף? הקבצים לא יישמרו.'))) return;
            await ShareStore.clear();
            location.hash = '#/';
        });
    }

    async function show(sub) {
        bind();
        $('#title').textContent = t('שמירה לפרויקט');
        document.title = t('שמירה לפרויקט');
        $('#share-error').hidden = sub !== 'error';
        $('#share-error').textContent = t('השיתוף לא נקלט. נסה לשתף שוב.');
        try { items = (await ShareStore.all()).sort((a, b) => a.at.localeCompare(b.at)); }
        catch { items = []; $('#share-error').hidden = false; $('#share-error').textContent = t('אין גישה לאחסון של הדפדפן במכשיר הזה.'); }
        renderItems();
        $('#share-cancel').hidden = !items.length;
        if (!items.length) {
            $('#share-where').hidden = true;
            $('#sq').closest('.toolbar').hidden = true;
            $('#share-targets').innerHTML = `<p class="empty">${esc(t('אין כרגע מה לשמור. כדי לשמור לכאן קבצים מהגלריה, מוואטסאפ או מכל אפליקציה: "שיתוף" ← "פרויקטים". (צריך שהאפליקציה תהיה מותקנת — "החשבון שלי" ← התקנה.)'))}</p>`;
            return;
        }
        const pid = Number(sub);
        if (pid > 0) await showFolders(pid);
        else await showProjects();
    }

    // ---- מה התקבל ----

    function renderItems() {
        urls.forEach(URL.revokeObjectURL);
        urls = [];
        const files = entries(), texts = notes();
        // "לשמירה: 3 קבצים ו-2 הערות" — משפט שלם לכל צירוף
        const fl = files.length > 1 ? t('{n} קבצים', { n: files.length }) : t('קובץ אחד');
        const tl = texts.length > 1 ? t('{n} הערות', { n: texts.length }) : t('הערה');
        $('#share-got').textContent = files.length && texts.length ? t('לשמירה: {files} ו{notes}', { files: fl, notes: tl })
            : files.length ? t('לשמירה: {what}', { what: fl })
            : texts.length ? t('לשמירה: {what}', { what: tl })
            : '';
        $('#share-got').hidden = !files.length && !texts.length;
        $('#share-items').innerHTML = files.map(({ item, file, i }) => {
            let pic = `<span class="ficon" aria-hidden="true">${file.type.startsWith('video/') ? '🎬' : file.type.startsWith('audio/') ? '🎵' : '📄'}</span>`;
            if (file.type.startsWith('image/')) {
                const u = URL.createObjectURL(file);
                urls.push(u);
                pic = `<img class="fthumb" src="${u}" alt="" width="40" height="40">`;
            }
            const big = file.size > state.maxUpload;
            return `<li class="card static">${pic}
                <div class="body"><div class="l1"><span class="one">${esc(file.name || t('קובץ'))}</span></div>
                    <div class="l2${big ? ' err' : ''}">${Files.fmtSize(file.size)}${big ? ' — ' + esc(t('גדול מ-{mb}MB, לא יישמר', { mb: Math.round(state.maxUpload / 1048576) })) : ''}</div></div>
                <button class="icon-btn" data-drop="${item.id}:${i}" aria-label="${esc(t('הסרת {name}', { name: file.name }))}">✕</button></li>`;
        }).join('') + texts.map(it => `<li class="card static"><span class="ficon" aria-hidden="true">💬</span>
                <div class="body"><div class="l1"><span class="one">${esc(noteOf(it))}</span></div>
                    <div class="l2">${esc(t('נשמר כהערה בציר הזמן'))}</div></div>
                <button class="icon-btn" data-drop="${it.id}:t" aria-label="${esc(t('הסרת ההערה'))}">✕</button></li>`).join('');
        $('#share-items').hidden = !files.length && !texts.length;
    }

    async function drop(key) {
        const [id, which] = key.split(':');
        const it = items.find(x => x.id === id);
        if (!it) return;
        if (which === 't') it.title = it.text = it.url = '';
        else it.files.splice(Number(which), 1);
        if (!it.files.length && !noteOf(it)) { await ShareStore.remove(id); items = items.filter(x => x !== it); }
        else await ShareStore.put(it);
        if (!items.length) { location.hash = '#/'; return; }
        renderItems();
        if (!project) renderProjects();
    }

    // ---- לאן ----

    async function showProjects() {
        project = null;
        $('#share-where').hidden = false;
        $('#share-where').textContent = t('לאן לשמור?');
        $('#sq').closest('.toolbar').hidden = false;
        try { ({ projects } = await api('projects', { params: { filter: 'open' } })); } catch (e) { toast(e.message, true); return; }
        renderProjects();
    }

    function renderProjects() {
        const q = $('#sq').value.trim().toLowerCase();
        const hasFiles = entries().length > 0;
        const list = projects.filter(p => !q || [p.name, p.address, p.client_name].some(s => (s || '').toLowerCase().includes(q)));
        const last = readLast();
        const lastP = !q && hasFiles && last && projects.find(p => p.id === last.project);
        $('#share-targets').innerHTML = (lastP ? `<li class="card quick" data-last="1" tabindex="0" role="button">
                <span class="ficon" aria-hidden="true">↩️</span>
                <div class="body"><div class="l1"><span class="one">${esc(t('כמו בפעם הקודמת'))}</span></div>
                    <div class="l2 one">${esc(lastP.name)} / ${esc(last.label || '')}</div></div></li>` : '')
            + list.map(p => `<li class="card" data-project="${p.id}" tabindex="0" role="button" aria-label="${esc(hasFiles ? t('{name} — בחירת תיקייה', { name: p.name }) : p.name)}">
                ${avatar(p.name)}
                <div class="body"><div class="l1"><span class="one">${esc(p.name)}</span></div>
                    <div class="l2 one">${esc([p.client_name, p.address].filter(Boolean).join(' · ')) || '&nbsp;'}</div></div>
                ${hasFiles ? `<button class="btn ghost sm" data-inbox="${p.id}" aria-label="${esc(t("שמירה ל'ממתין למיון' של {name}", { name: p.name }))}">⚡ ${esc(t('למיון'))}</button>` : ''}
            </li>`).join('')
            || `<p class="empty">${esc(q ? t('לא נמצא פרויקט') : t('אין פרויקטים פעילים. פותחים פרויקט במסך הפרויקטים.'))}</p>`;
    }

    async function loadFolders(pid) {
        ({ folders } = await api('folders', { params: { project_id: pid } }));
    }

    async function showFolders(pid) {
        $('#sq').closest('.toolbar').hidden = true;
        $('#share-where').hidden = false;
        try {
            if (!projects.length) ({ projects } = await api('projects', { params: { filter: 'all' } }));
            await loadFolders(pid);
        } catch (e) { toast(e.message, true); location.hash = '#/share'; return; }
        project = projects.find(p => p.id === pid) || { id: pid, name: '' };
        $('#share-where').textContent = t('{name} — לאיזו תיקייה?', { name: project.name });
        // עץ: כל תיקייה ומתחתיה הילדים שלה, בהזחה
        const rows = [];
        const walk = (parent, depth) => folders.filter(f => (f.parent_id || 0) === parent).forEach(f => { rows.push([f, depth]); walk(f.id, depth + 1); });
        walk(0, 0);
        $('#share-targets').innerHTML = rows.map(([f, d]) => `<li class="card" data-folder="${f.id}" tabindex="0" role="button" style="--d:${d}">
                <span class="ficon" aria-hidden="true">${f.icon}</span>
                <div class="body"><div class="l1"><span class="one">${esc(t(f.name))}</span></div>
                    <div class="l2">${esc(f.files ? t('{n} קבצים', { n: f.files }) : t('ריקה'))}</div></div></li>`).join('');
    }

    function folderPath(id) {
        const parts = [];
        for (let f = folders.find(x => x.id === id); f; f = folders.find(x => x.id === f.parent_id)) parts.unshift(t(f.name));
        return parts.join(' / ');
    }

    async function saveToInbox(pid) {
        try { await loadFolders(pid); } catch (e) { toast(e.message, true); return; }
        const inbox = folders.find(f => f.kind === 'inbox' && f.parent_id === null);
        if (!inbox) { toast(t('אין תיקיית "ממתין למיון" בפרויקט'), true); return; }
        save(pid, inbox.id);
    }

    // ---- שמירה ----

    async function save(pid, folder) {
        if (saving) return;
        saving = true;
        $('#view-share').classList.add('busy');
        const box = $('#share-progress');
        const failed = [];
        try {
            if (folder) {
                if (!folders.some(f => f.id === folder)) await loadFolders(pid);
                localStorage.setItem(LAST, JSON.stringify({ project: pid, folder, label: folderPath(folder) }));
            }
            for (const it of [...items]) {
                for (const file of [...it.files]) {
                    if (!folder) break;
                    if (file.size > state.maxUpload) { failed.push(file.name); continue; }
                    box.innerHTML = `<div class="up"><span class="one">${esc(file.name)}</span><span>0%</span><i style="width:0"></i></div>`;
                    try {
                        await Files.send(file, pid, folder, pct => {
                            box.innerHTML = `<div class="up"><span class="one">${esc(file.name)}</span><span>${pct}%</span><i style="width:${pct}%"></i></div>`;
                        });
                        it.files.splice(it.files.indexOf(file), 1);
                        await ShareStore.put(it); // מה שכבר עלה לא יעלה שוב אם יש ניתוק באמצע
                    } catch (e) { failed.push(`${file.name}: ${e.message}`); }
                }
                if (noteOf(it)) {
                    try {
                        await api('note', { body: { project_id: pid, text: noteOf(it) } });
                        it.title = it.text = it.url = '';
                    } catch (e) { failed.push(t('הערה: {error}', { error: e.message })); }
                }
                if (!it.files.length && !noteOf(it)) await ShareStore.remove(it.id);
                else await ShareStore.put(it);
            }
        } catch (e) {
            failed.push(e.message);
        } finally {
            saving = false;
            box.innerHTML = '';
            $('#view-share').classList.remove('busy');
        }
        items = (await ShareStore.all().catch(() => [])).filter(it => it.files.length || noteOf(it));
        if (!failed.length || !items.length) {
            if (failed.length) toast(t('לא נשמרו: {list}', { list: failed.join(', ') }), true); else toast(t('נשמר'));
            await ShareStore.clear().catch(() => {});
            location.hash = folder ? `#/p/${pid}/files/${folder}` : `#/p/${pid}/timeline`;
            return;
        }
        $('#share-error').hidden = false;
        $('#share-error').textContent = t('לא נשמרו: {list}. אפשר לנסות שוב.', { list: failed.join(' · ') });
        renderItems();
    }

    /** כמה שיתופים ממתינים — כדי לפתוח את המסך אחרי כניסה. */
    async function pending() {
        try { return 'indexedDB' in window ? await ShareStore.count() : 0; } catch { return 0; }
    }

    return { show, pending };
})();
