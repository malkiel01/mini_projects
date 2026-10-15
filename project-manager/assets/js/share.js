// שיתוף לאפליקציה — שלב 4. כמו "שתף אל…" בוואטסאפ: בוחרים קבצים בגלריה או בכל
// אפליקציה, "שיתוף" ← "פרויקטים", ונפתח המסך הזה: מה התקבל, ולאן לשמור.
//
//   #/share        מה התקבל; "אחרונים" — התיקיות האחרונות ששיתפת אליהן (כמו הצ'אטים האחרונים
//                  בוואטסאפ), לחיצה אחת שומרת; ואז הפרויקטים. "⚡ למיון" שומר בלחיצה אחת
//                  ל"ממתין למיון" של הפרויקט; לחיצה על הפרויקט — בחירת תיקייה.
//   #/share/<id>   התיקיות של הפרויקט, ו"+" — תיקייה חדשה (בראש או בתוך תיקייה) שהקבצים נשמרים בה.
//   האחרונים נשמרים למשתמש בשרת (share_recent) — עוברים איתו בין מכשירים.
//
// באפליקציית האנדרואיד (android/, window.PMNative): השיתוף מגיע מהאפליקציה — הקבצים נמשכים ממנה
// (importNative) לאותו ShareStore. שיתוף דרך קיצור של תיקייה (בשורה העליונה של "שיתוף" בטלפון) מגיע
// עם target — ונשמר ישר לשם. האחרונים נשלחים לאפליקציה (PMNative.setRecent) — הם הקיצורים האלה.
//
// הקבצים מחכים במכשיר (ShareStore, שנשמר ב-sw.js) עד שנשמרו בשרת — גם אם האפליקציה
// נסגרה או שצריך קודם להתחבר. טקסט או קישור ששותפו נשמרים כהערה בציר הזמן.
'use strict';

const Share = (() => {
    let items = [], projects = [], folders = [], recent = [], project = null, bound = false, saving = false, urls = [];
    let nativeTarget = null; // "פרויקט:תיקייה" — שיתוף שהגיע דרך קיצור של תיקייה באנדרואיד
    // שלב 12 — סיווג חכם: מה Claude זיהה בקובץ הראשון, ולאן הוא מציע. נשמר לשיתוף הזה (key) — לא מסווגים פעמיים
    let ai = { key: '', busy: false, res: null, error: '' };
    const AI_ICON = { invoice: '🧾', receipt: '💳', quote: '💬', contract: '📄', plan: '📐', site_photo: '📷', other: '📎' };
    const AI_LABEL = { invoice: 'חשבונית', receipt: 'קבלה / אסמכתה', quote: 'הצעת מחיר', contract: 'חוזה', plan: 'תוכנית', site_photo: 'תמונה מהשטח', other: 'מסמך' };

    /** עד 2 עמודים כ-JPEG מוקטן: תמונה — כמו שהיא; PDF — העמודים הראשונים (pdf.js). אחר — לא מסווגים. */
    async function aiPages(file) {
        const jpg = (src, w, h) => {
            const k = Math.min(1, 1500 / Math.max(w, h)), c = document.createElement('canvas');
            c.width = Math.round(w * k); c.height = Math.round(h * k);
            c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
            return c.toDataURL('image/jpeg', 0.82).split(',')[1];
        };
        if (file.type.startsWith('image/') && 'createImageBitmap' in window) {
            const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
            return [jpg(bmp, bmp.width, bmp.height)];
        }
        if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name)) {
            const doc = await Viewer.pdfDoc(await file.arrayBuffer());
            const out = [];
            for (let n = 1; n <= Math.min(2, doc.numPages); n++) {
                const c = await Viewer.drawPage(await doc.getPage(n), 1000);
                out.push(jpg(c, c.width, c.height));
            }
            return out;
        }
        return [];
    }

    /** מסווג את הקובץ הראשון בשיתוף (פעם אחת), ומצייר מחדש כשיש תשובה. */
    async function classify() {
        const first = entries()[0];
        if (!state.ai?.on || !first) return;
        const key = `${first.item.id}:${first.i}:${first.file.name}:${first.file.size}`;
        if (ai.key === key) return;
        ai = { key, busy: true, res: null, error: '' };
        renderProjects();
        try {
            const pages = await aiPages(first.file);
            if (!pages.length) { ai.busy = false; renderProjects(); return; }
            const r = await api('ai-classify', { body: { pages, name: first.file.name } });
            if (ai.key === key) ai.res = r;
        } catch (e) { if (ai.key === key) ai.error = e.message; }
        if (ai.key === key) { ai.busy = false; if (!project) renderProjects(); }
    }

    /** מה שזוהה, בשורה אחת: "חשבונית · אינסטלטור כהן · ₪4,500 · 12.10" — גם להערה על הקובץ. */
    function aiLine(a) {
        return [t(AI_LABEL[a.doc_type] || 'מסמך') + (a.doc_number ? ' ' + a.doc_number : ''), a.supplier, a.amount != null ? money(a.amount) : '',
            a.date ? shortDate(a.date) : ''].filter(Boolean).join(' · ');
    }

    function aiCard() {
        if (!state.ai?.on || !entries().length) return '';
        const head = `<li class="share-sec" role="presentation">🤖 ${esc(t('הצעה של Claude'))}</li>`;
        if (ai.busy) return head + `<li class="card static ai-wait"><span class="ficon" aria-hidden="true">⏳</span><div class="body"><div class="l1"><span class="one">${esc(t('Claude קורא את הקובץ…'))}</span></div>
            <div class="l2">${esc(t('בינתיים אפשר לבחור לבד למטה'))}</div></div></li>`;
        if (ai.error) return head + `<li class="card static"><span class="ficon" aria-hidden="true">⚠️</span><div class="body"><div class="l1"><span class="one">${esc(t('לא הצלחתי לזהות'))}</span></div>
            <div class="l2 one">${esc(ai.error)}</div></div></li>`;
        if (!ai.res) return '';
        const a = ai.res.ai, s = ai.res.suggest;
        if (!s) return head + `<li class="card static"><span class="ficon" aria-hidden="true">${AI_ICON[a.doc_type] || '📎'}</span><div class="body">
            <div class="l1"><span class="one">${esc(aiLine(a))}</span></div><div class="l2 one">${esc(t('לא ברור לאיזה פרויקט — בחר למטה'))}</div></div></li>`;
        return head + `<li class="card quick ai-suggest" data-ai="${s.project_id}:${s.folder_id}" tabindex="0" role="button" aria-label="${esc(t('שמירה ל-{path} ב{project}', { path: s.path, project: s.project }))}">
            <span class="ficon" aria-hidden="true">${AI_ICON[a.doc_type] || '📎'}</span>
            <div class="body"><div class="l1"><span class="one">${esc(s.project)} / ${esc(s.path.split(' / ').pop())}</span></div>
                <div class="l2 one">${esc(aiLine(a))}</div>
                ${s.reason ? `<div class="l2 muted one">${esc(s.reason)}</div>` : ''}</div>
            <span class="go" aria-hidden="true">⤴</span></li>`;
    }

    /** סוג והערה לקובץ שזוהה (רק לקובץ הראשון — הוא שסווג). */
    function aiFinish(it, i) {
        const a = ai.res?.ai, first = entries()[0];
        if (!a || !first || first.item !== it || first.i !== i) return {};
        return { type: a.file_type, note: aiLine(a) };
    }

    /** האחרונים — לאפליקציית האנדרואיד, לקיצורי השיתוף הישיר. */
    function pushNative(list) { try { window.PMNative?.setRecent(JSON.stringify(list || [])); } catch { /* גרסה ישנה של האפליקציה */ } }

    /** שיתוף שהגיע לאפליקציית האנדרואיד — הקבצים עוברים ל-ShareStore, כמו שיתוף לאתר המותקן. */
    async function importNative() {
        if (!window.PMNative?.sharedItems) return;
        let d;
        try { d = JSON.parse(PMNative.sharedItems()); } catch { return; }
        const files = [];
        for (const f of d.files || []) {
            const r = await fetch(d.base + f.i, { cache: 'no-store' }).catch(() => null);
            if (r?.ok) files.push(new File([await r.blob()], f.name, { type: f.type || 'application/octet-stream' }));
        }
        if (!files.length && !(d.text || '').trim()) return;
        await ShareStore.add({ id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6), at: new Date().toISOString(),
            title: d.subject || '', text: d.text || '', url: '', files });
        PMNative.clearShared();
        nativeTarget = d.target || null;
    }

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
            const el = e.target.closest('[data-inbox], [data-newfolder], [data-folder], [data-project], [data-recent], [data-ai]');
            if (!el) return;
            e.stopPropagation();
            if (el.dataset.inbox) saveToInbox(Number(el.dataset.inbox));
            else if (el.dataset.newfolder !== undefined) newFolder(Number(el.dataset.newfolder) || null);
            else if (el.dataset.folder) save(project.id, Number(el.dataset.folder));
            else if (el.dataset.recent) { const [p, f] = el.dataset.recent.split(':').map(Number); save(p, f); }
            else if (el.dataset.ai) { const [p, f] = el.dataset.ai.split(':').map(Number); save(p, f); }
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
        try { await importNative(); } catch { /* נשאר באפליקציה — ננסה שוב בכניסה הבאה למסך */ }
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
        // נבחרה תיקייה מתפריט השיתוף של הטלפון — שומרים ישר לשם
        if (nativeTarget && entries().length) {
            const [p, f] = nativeTarget.split(':').map(Number);
            nativeTarget = null;
            if (p && f) { save(p, f); return; }
        }
        nativeTarget = null;
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
        classify();
    }

    function renderProjects() {
        const q = $('#sq').value.trim().toLowerCase();
        const hasFiles = entries().length > 0;
        const list = projects.filter(p => !q || [p.name, p.address, p.client_name].some(s => (s || '').toLowerCase().includes(q)));
        // אחרונים: לחיצה אחת שומרת לשם. בחיפוש — רק אלה שמתאימים
        const rec = hasFiles ? recent.filter(r => !q || [r.project, r.path].some(s => s.toLowerCase().includes(q))) : [];
        $('#share-targets').innerHTML = (q ? '' : aiCard()) + (rec.length ? `<li class="share-sec" role="presentation">🕘 ${esc(t('אחרונים'))}</li>`
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
                api('share-recent', { body: { folder_id: folder } }).then(r => pushNative(r.recent)).catch(() => {}); // לראש "אחרונים"
            }
            for (const it of [...items]) {
                const order = [...it.files];
                for (const file of [...it.files]) {
                    const finish = aiFinish(it, order.indexOf(file)); // סוג והערה ממה שזוהה
                    if (!folder) break;
                    if (file.size > state.maxUpload) { failed.push(file.name); continue; }
                    box.innerHTML = `<div class="up"><span class="one">${esc(file.name)}</span><span>0%</span><i style="width:0"></i></div>`;
                    try {
                        await Files.send(file, pid, folder, pct => {
                            box.innerHTML = `<div class="up"><span class="one">${esc(file.name)}</span><span>${pct}%</span><i style="width:${pct}%"></i></div>`;
                        }, finish);
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
        try { if (JSON.parse(window.PMNative?.sharedItems?.() || '{}').files?.length) return 1; } catch { /* אין אפליקציה */ }
        try { return 'indexedDB' in window ? await ShareStore.count() : 0; } catch { return 0; }
    }

    /** בכניסה לאפליקציית האנדרואיד — הקיצורים מתעדכנים לאחרונים, גם בלי שיתוף חדש. */
    function syncNative() {
        if (window.PMNative?.setRecent) api('share-recent').then(r => pushNative(r.recent)).catch(() => {});
    }

    return { show, pending, syncNative };
})();
