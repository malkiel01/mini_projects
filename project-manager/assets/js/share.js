// שיתוף לאפליקציה — שלב 4. כמו "שתף אל…" בוואטסאפ: בוחרים קבצים בגלריה או בכל
// אפליקציה, "שיתוף" ← "פרויקטים", ונפתח המסך הזה: מה התקבל, ולאן לשמור.
//
//   #/share        מה התקבל; "אחרונים" — התיקיות האחרונות ששיתפת אליהן (כמו הצ'אטים האחרונים
//                  בוואטסאפ), לחיצה אחת שומרת; ואז הפרויקטים. "⚡ למיון" שומר בלחיצה אחת
//                  ל"ממתין למיון" של הפרויקט; לחיצה על הפרויקט — בחירת תיקייה.
//   #/share/<id>   התיקיות של הפרויקט, ו"+" — תיקייה חדשה (בראש או בתוך תיקייה) שהקבצים נשמרים בה.
//   האחרונים נשמרים למשתמש בשרת (share_recent) — עוברים איתו בין מכשירים.
//
// הקבצים מחכים במכשיר (ShareStore, שנשמר ב-sw.js) עד שנשמרו בשרת — גם אם האפליקציה
// נסגרה או שצריך קודם להתחבר. טקסט או קישור ששותפו נשמרים כהערה בציר הזמן.
'use strict';

const Share = (() => {
    let items = [], projects = [], folders = [], recent = [], project = null, bound = false, saving = false, urls = [];

    const entries = () => items.flatMap(it => it.files.map((file, i) => ({ item: it, file, i })));
    const noteOf = it => [...new Set([it.title, it.text, it.url].map(s => (s || '').trim()).filter(Boolean))].join('\n');
    const notes = () => items.filter(it => noteOf(it));

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
            const el = e.target.closest('[data-inbox], [data-newfolder], [data-folder], [data-project], [data-recent]');
            if (!el) return;
            e.stopPropagation();
            if (el.dataset.inbox) saveToInbox(Number(el.dataset.inbox));
            else if (el.dataset.newfolder !== undefined) newFolder(Number(el.dataset.newfolder) || null);
            else if (el.dataset.folder) save(project.id, Number(el.dataset.folder));
            else if (el.dataset.recent) { const [p, f] = el.dataset.recent.split(':').map(Number); save(p, f); }
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
        try {
            const [P, R] = await Promise.all([api('projects', { params: { filter: 'open' } }), api('share-recent').catch(() => ({ recent: [] }))]);
            projects = P.projects;
            recent = R.recent;
        } catch (e) { toast(e.message, true); return; }
        renderProjects();
    }

    function renderProjects() {
        const q = $('#sq').value.trim().toLowerCase();
        const hasFiles = entries().length > 0;
        const list = projects.filter(p => !q || [p.name, p.address, p.client_name].some(s => (s || '').toLowerCase().includes(q)));
        // אחרונים: לחיצה אחת שומרת לשם. בחיפוש — רק אלה שמתאימים
        const rec = hasFiles ? recent.filter(r => !q || [r.project, r.path].some(s => s.toLowerCase().includes(q))) : [];
        $('#share-targets').innerHTML = (rec.length ? `<li class="share-sec" role="presentation">🕘 ${esc(t('אחרונים'))}</li>`
            + rec.map((r, i) => `<li class="card quick" data-recent="${r.project_id}:${r.folder_id}" tabindex="0" role="button"
                    aria-label="${esc(t('שמירה ל-{path} ב{project}', { path: r.path, project: r.project }))}">
                <span class="ficon" aria-hidden="true">${r.icon}</span>
                <div class="body"><div class="l1"><span class="one">${esc(r.path)}</span>${i === 0 ? `<span class="chip">${esc(t('האחרון'))}</span>` : ''}</div>
                    <div class="l2 one">${esc(r.project)}</div></div>
                <span class="go" aria-hidden="true">⤴</span></li>`).join('')
            + `<li class="share-sec" role="presentation">📁 ${esc(t('כל הפרויקטים'))}</li>` : '')
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
                    <div class="l2">${esc(f.files ? t('{n} קבצים', { n: f.files }) : t('ריקה'))}</div></div>
                <button type="button" class="icon-btn" data-newfolder="${f.id}" aria-label="${esc(t('תיקייה חדשה בתוך {name}', { name: t(f.name) }))}" title="${esc(t('תיקייה חדשה בתוך {name}', { name: t(f.name) }))}">＋</button></li>`).join('')
            + `<li class="card share-new" data-newfolder="" tabindex="0" role="button"><span class="ficon" aria-hidden="true">📁</span>
                <div class="body"><div class="l1"><span class="one">+ ${esc(t('תיקייה חדשה'))}</span></div>
                    <div class="l2">${esc(t('הקבצים יישמרו בה'))}</div></div></li>`;
    }

    /** תיקייה חדשה מתוך השיתוף — בראש הפרויקט או בתוך תיקייה — והקבצים נשמרים בה מיד. */
    async function newFolder(parent) {
        const where = parent ? t(folders.find(f => f.id === parent)?.name || '') : project.name;
        const name = (prompt(t('שם התיקייה החדשה (בתוך {where}):', { where })) || '').trim();
        if (!name) return;
        let id;
        try { ({ folders, folder_id: id } = await api('folder', { body: { project_id: project.id, parent_id: parent || '', name } })); }
        catch (e) { toast(e.message, true); return; }
        save(project.id, id);
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
                // תיקייה שנמחקה מאז (מ"אחרונים") — חוזרים לבחירה
                if (!folders.some(f => f.id === folder)) throw Object.assign(new Error(t('התיקייה כבר לא קיימת — בחר תיקייה אחרת')), { gone: pid });
                api('share-recent', { body: { folder_id: folder } }).catch(() => {}); // לראש "אחרונים"
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
            if (e.gone) { toast(e.message, true); location.hash = '#/share/' + e.gone; return; } // finally מנקה
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
