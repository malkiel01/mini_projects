// אזור הפיתוח ← 📋 מטלות (DevLoop, שלב 1): קוד לכל מטלה (PM-001), סוג ועדיפות, "איך יודעים שזה עובד" וצעדי
// בדיקה, ראיות (PR, פריסה, גרסה), שיח עם צילומים, ו"ממתין לאימות שלך" — ✅ עובד אצלי / ↩️ לא עובד.
// ו-🐞 דיווח מכל מסך: מה קרה + צילום, וההקשר נאסף לבד (מסך, גרסה, בקשות ושגיאות אחרונות — PMTrace ב-core.js).
// רק למפתח, בעברית. השרת: lib/devtasks.php; README: "מטלות ושיח".
'use strict';

// i18n-none-start — אזור הפיתוח: למפתח בלבד, בעברית
const DevTasks = (() => {
    const STATUS = {
        triage: ['לבירור', 'warn', '⚠️'], verify: ['ממתין לאימות שלך', 'bad', '🧪'], question: ['שאלה אליך', 'bad', '❓'], in_progress: ['בעבודה', 'info', '🔵'],
        open: ['פתוח', 'warn', '🟡'], closed: ['סגור', 'ok', '✅'],
    };
    const KINDS = { bug: ['🐞', 'באג'], feature: ['✨', 'פיצ\'ר'], question: ['❓', 'שאלה'], research: ['🔎', 'מחקר'], task: ['🧩', 'מטלה'] };
    const PRIO = { high: ['🔺', 'דחוף'], normal: ['', 'רגיל'], low: ['▽', 'נמוך'] };
    const EV = { pr: '🔀 PR', commit: '📌 commit', deploy: '🚀 פריסה', version: '🏷️ גרסה', test: '🧪 בדיקה', note: '📝' };
    const dayTime = iso => iso ? new Date(iso).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
    const fileUrl = (id, dl) => `api.php?action=dev-file&id=${id}${dl ? '&download=1' : ''}`;
    // "לבירור" (שגיאה שנפתחה לבד) — לא נספרת כפתוחה ולא כממתינה לך עד שמחליטים: 👍 לטפל / 🙈 להתעלם
    const live = x => x.status !== 'closed' && x.status !== 'triage';
    const needsYou = x => x.status === 'verify' || x.status === 'question' || (x.waiting === 'user' && live(x));
    let box = null, list = [], meta = {}, filter = '', query = '', openCode = '';

    /** הטקסט שמדביקים לצ'אט — מטלות שממתינות ל-Claude (או כל הפתוחות). */
    function chatText() {
        const waiting = list.filter(x => x.waiting === 'claude');
        const pick = waiting.length ? waiting : list.filter(live);
        const head = waiting.length ? 'הערות שלי על מטלות במנהל הפרויקטים — ממתינות לתשובה:' : 'המטלות הפתוחות במנהל הפרויקטים:';
        return [head, ...pick.map(x => {
            const [label] = STATUS[x.status] || [x.status];
            const thread = x.comments.map(c => `  ${c.author === 'claude' ? 'Claude' : 'אני'} (${dayTime(c.at)}): ${c.body}`);
            const files = x.files.length ? [`  📎 ${x.files.length} קבצים (devapi-file)`] : [];
            return [`\n${x.code} · ${x.title} [${label}]`, ...(x.questions || []).map(q => '  ❓ ' + q), ...thread, ...files].join('\n');
        })].join('\n');
    }

    async function load() {
        try { const r = await api('dev-items'); list = r.items || []; meta = r; badge(); return true; }
        catch (e) { if (box) box.innerHTML = `<p class="error">${esc(e.message)}</p>`; return false; }
    }

    /** המונה על כפתור 🐞 בכותרת: כמה מחכות לך (אימות, שאלה, תשובה של Claude). */
    function badge() {
        const n = list.filter(needsYou).length;
        const b = $('#dev-bug-n');
        if (b) { b.textContent = n || ''; b.hidden = !n; }
    }

    async function show(el, code = '') {
        box = el;
        if (code) { openCode = code; filter = 'all'; query = code; }
        if (!box.dataset.bound) {
            box.dataset.bound = '1';
            box.addEventListener('click', onClick);
            box.addEventListener('change', onChange);
            box.addEventListener('input', e => { if (e.target.id === 'dt-q') { query = e.target.value.trim(); drawList(); } });
            box.addEventListener('toggle', e => { if (e.target.matches?.('details.dt-item') && e.target.open) openCode = e.target.dataset.code; }, true);
        }
        box.innerHTML = '<p class="hint">טוען…</p>';
        if (!await load()) return;
        if (!filter) filter = list.some(needsYou) ? 'you' : 'pending';
        draw();
    }

    const count = fn => list.filter(fn).length;
    const pass = x => ({ you: needsYou(x), pending: live(x), claude: live(x) && (x.waiting === 'claude' || x.status === 'open' || x.status === 'in_progress'),
        triage: x.status === 'triage', closed: x.status === 'closed', all: true })[filter];
    const match = x => !query || x.code.toLowerCase() === query.toLowerCase()
        || [x.code, x.title, x.area, x.detail, x.acceptance, ...(x.questions || []), ...x.comments.map(c => c.body)].join(' ').toLowerCase().includes(query.toLowerCase());
    const order = x => ({ verify: 0, question: 1, in_progress: 2, open: 3, triage: 4, closed: 5 })[x.status] ?? 6;

    function draw() {
        const nTri = count(x => x.status === 'triage');
        const filters = [['you', `ממתינות לך (${count(needsYou)})`], ['claude', `אצל Claude (${count(x => live(x) && !needsYou(x))})`],
            ['pending', `כל הפתוחות (${count(live)})`], ...(nTri ? [['triage', `⚠️ לבירור (${nTri})`]] : []), ['closed', `סגורות (${count(x => x.status === 'closed')})`], ['all', 'הכול']];
        box.innerHTML = `
            <div class="dt-stats"><b>${count(x => x.status === 'verify')}</b> לאימות · <b>${count(x => x.status === 'question')}</b> שאלות ·
                <b>${count(x => x.status === 'in_progress')}</b> בעבודה · <b>${count(x => x.status === 'open')}</b> פתוחות · <b>${count(x => x.status === 'closed')}</b> סגורות${nTri ? ` · <b>${nTri}</b> שגיאות לבירור` : ''}
                <small>· עודכן ${esc(meta.updated || '—')}</small></div>
            ${meta.note ? `<p class="hint">${esc(meta.note)}</p>` : ''}
            <div class="row-btns wrap">
                <button type="button" class="btn primary sm" data-dt="new">+ מטלה חדשה</button>
                <button type="button" class="btn ghost sm" data-dt="report">🐞 דיווח</button>
                <button type="button" class="btn ghost sm" data-dt="chat">📋 העתקה לצ'אט</button>
                <button type="button" class="btn ghost sm" data-dt="tokens">🔑 גישה ל-Claude</button>
            </div>
            <input type="search" id="dt-q" placeholder="קוד (PM-012) או מילה" aria-label="חיפוש מטלה" value="${esc(query)}" autocomplete="off">
            <div class="chip-row dt-filters" role="radiogroup" aria-label="סינון">${filters.map(([k, l]) =>
                `<button type="button" class="chip${filter === k ? ' on' : ''}" role="radio" aria-checked="${filter === k}" data-dt-f="${k}">${esc(l)}</button>`).join('')}</div>
            <div id="dt-list"></div>`;
        drawList();
    }

    function drawList() {
        const out = $('#dt-list', box);
        if (!out) return;
        const shown = list.filter(x => (query ? true : pass(x)) && match(x)).sort((a, b) => order(a) - order(b));
        out.innerHTML = shown.length ? `<ul class="dt-items">${shown.map(item).join('')}</ul>`
            : `<p class="empty">${query ? 'לא נמצאה מטלה.' : filter === 'you' ? 'שום דבר לא ממתין לך 🎉' : 'אין פריטים.'}</p>`;
        const target = openCode && out.querySelector(`details[data-code="${CSS.escape(openCode)}"]`);
        if (target) requestAnimationFrame(() => target.scrollIntoView({ block: 'nearest' }));
    }

    const evRow = e => {
        const repo = meta.repo || '';
        const url = e.url || (e.kind === 'pr' && repo && /^#?\d+$/.test(e.ref) ? `https://github.com/${repo}/pull/${e.ref.replace('#', '')}` : '');
        const label = e.ref === 'verified' ? '✅ אומת' : e.ref === 'rejected' ? '↩️ הוחזר' : `${EV[e.kind] || e.kind} ${e.ref}`;
        return `<li>${url ? `<a href="${esc(url)}" target="_blank" rel="noopener">${esc(label)}</a>` : esc(label)}${e.note && !['verified', 'rejected'].includes(e.ref) ? ` — ${esc(e.note)}` : ''}
            <small>${esc(dayTime(e.at))}</small></li>`;
    };
    const thumbs = files => files.length ? `<div class="dt-files">${files.map(f => f.mime.startsWith('image/')
        ? `<a href="${fileUrl(f.id)}" target="_blank" rel="noopener" class="dt-thumb"><img src="${fileUrl(f.id)}" alt="${esc(f.name)}" loading="lazy"></a>`
        : `<a href="${fileUrl(f.id)}" target="_blank" rel="noopener" class="chip">📎 ${esc(f.name)}</a>`).join('')}</div>` : '';

    function context(c) {
        if (!c) return '';
        const req = (c.requests || []).slice(-8).reverse();
        return `<details class="dt-ctx"><summary>🔍 הקשר טכני — נאסף לבד בדיווח</summary>
            <p><b>מסך:</b> <code dir="ltr">${esc(c.url || '')}</code> · ${esc(c.screen || '')} · גרסה ${esc(c.version || '')}</p>
            ${req.length ? `<table class="dt-req"><tr><th>פעולה</th><th>תוצאה</th><th>זמן</th><th>מזהה</th></tr>${req.map(r =>
                `<tr class="${r.status >= 400 ? 'bad' : ''}"><td dir="ltr">${esc(r.method + ' ' + r.action)}</td><td>${esc(String(r.status))}</td><td>${esc(r.ms + 'ms')}</td><td dir="ltr">${esc(r.id || '')}</td></tr>`).join('')}</table>` : ''}
            ${(c.errors || []).length ? `<ul class="dt-errs">${c.errors.map(e => `<li dir="ltr">${esc(e.message)}${e.source ? ` (${esc(String(e.source).split('/').pop())}:${esc(String(e.line || ''))})` : ''}</li>`).join('')}</ul>` : ''}
            <small dir="ltr">${esc(c.ua || '')}</small></details>`;
    }

    function item(x) {
        const [label, kind, ic] = STATUS[x.status] || [x.status, '', '•'];
        const [kic, kl] = KINDS[x.kind] || KINDS.task;
        const [pic, pl] = PRIO[x.priority] || PRIO.normal;
        const last = x.comments[x.comments.length - 1];
        const occ = x.occurrences;
        const sub = [`${kic} ${kl}`, occ ? `×${occ.count} · אחרון ${dayTime(occ.last_at)}${occ.ignored ? ' · 🙈' : ''}` : '', x.priority !== 'normal' ? `${pic} ${pl}` : '', x.area, x.closed ? 'נסגר ' + x.closed : x.opened ? 'נפתח ' + x.opened : '',
            x.source === 'user' && !occ ? 'נפתח מהמסך' : '', x.comments.length ? '💬 ' + x.comments.length : '', x.files.length ? '📎 ' + x.files.length : ''].filter(Boolean).join(' · ');
        const loose = x.files.filter(f => !f.comment_id || !x.comments.some(c => c.id === f.comment_id));
        const steps = x.steps || [];
        return `<li><details class="dt-item${needsYou(x) ? ' you' : ''}" data-code="${esc(x.code)}"${x.code === openCode ? ' open' : ''}>
            <summary class="use-row"><span class="use-ic" aria-hidden="true">${ic}</span>
                <span class="use-tx"><b><code class="dt-code" dir="ltr">${esc(x.code)}</code> ${esc(x.title)}</b><small>${esc(sub)}</small></span>
                <span class="dt-badges">${x.waiting === 'claude' && live(x) ? '<span class="chip dt-warn">ממתין ל-Claude</span>' : ''}${x.waiting === 'user' && live(x) ? '<span class="chip dt-info">Claude ענה</span>' : ''}<span class="chip dt-${kind}">${esc(label)}</span></span>
            </summary>
            <div class="dt-body">
                ${x.status === 'verify' ? `<div class="dt-verify"><b>🧪 נפרס — עובד אצלך?</b>
                    <p class="hint">עברו על "איך בודקים" למטה. לא עובד — כתבו מה ראיתם (ואפשר לצרף צילום); המטלה חוזרת ל-Claude.</p>
                    <textarea rows="2" maxlength="4000" data-verify-note placeholder="מה ראיתם (חובה כשלא עובד)"></textarea>
                    <div class="row-btns"><button type="button" class="btn primary sm" data-dt-ok="${esc(x.code)}">✅ עובד אצלי</button>
                        <button type="button" class="btn ghost sm danger" data-dt-no="${esc(x.code)}">↩️ לא עובד</button></div>
                    <p class="error" role="alert" hidden></p></div>` : ''}
                ${x.status === 'triage' ? `<div class="dt-verify"><b>⚠️ שגיאה שנפתחה לבד מהיומן${occ ? ` — קרתה ${occ.count} פעמים` : ''}</b>
                    <p class="hint">👍 לטפל — עוברת ל-Claude. 🙈 להתעלם — נסגרת, ומופעים חדשים רק נספרים.</p>
                    <div class="row-btns"><button type="button" class="btn primary sm" data-dt-tri="open" data-code="${esc(x.code)}">👍 לטפל</button>
                        <button type="button" class="btn ghost sm" data-dt-tri="ignore" data-code="${esc(x.code)}">🙈 להתעלם</button></div>
                    <p class="error" role="alert" hidden></p></div>` : ''}
                ${x.detail ? `<p dir="auto" class="dt-detail">${esc(x.detail)}</p>` : ''}
                ${x.questions?.length && x.status === 'question' ? `<div class="dt-questions"><b>שאלות אליך:</b><ul>${x.questions.map(q => `<li>${esc(q)}</li>`).join('')}</ul></div>` : ''}
                ${x.acceptance ? `<div class="dt-acc"><b>✔ איך יודעים שזה עובד</b><p dir="auto">${esc(x.acceptance)}</p></div>` : ''}
                ${steps.length ? `<div class="dt-steps"><b>🧪 איך בודקים</b><ol>${steps.map((s, i) =>
                    `<li><label><input type="checkbox" data-step="${esc(x.code)}:${i}"> <span>${esc(s)}</span></label></li>`).join('')}</ol></div>` : ''}
                ${x.evidence?.length ? `<div class="dt-ev"><b>📦 ראיות</b><ul>${x.evidence.map(evRow).join('')}</ul></div>` : ''}
                ${context(x.context)}
                ${x.source === 'user' ? `<label class="f dt-status"><span>מצב</span><select data-dt-status="${esc(x.code)}">${Object.entries(STATUS).map(([k, [l]]) =>
                    `<option value="${k}"${x.status === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></label>` : ''}
                ${thumbs(loose)}
                <div class="dt-thread"><b>💬 שיח</b>
                    ${x.comments.length ? `<ol class="dt-comments">${x.comments.map(c => `<li class="dt-c ${c.author}">
                        <div class="dt-c-head"><b>${c.author === 'claude' ? '🤖 Claude' : '👤 ' + esc(c.name)}</b><small>${esc(dayTime(c.at))}</small>
                            ${c.author === 'user' && c === last ? `<button type="button" class="link" data-dt-del="${c.id}" data-code="${esc(x.code)}">מחיקה</button>` : ''}</div>
                        <p dir="auto">${esc(c.body)}</p>${thumbs(x.files.filter(f => f.comment_id === c.id))}</li>`).join('')}</ol>` : '<p class="hint">אין עדיין הערות.</p>'}
                    ${x.waiting === 'claude' && x.status !== 'closed' ? `<p class="hint">⏳ ממתין ל-Claude — תגידו לו "תקרא את המטלות", או "📋 העתקה לצ'אט" למעלה.</p>` : ''}
                    <div class="dt-new"><textarea rows="2" maxlength="4000" placeholder="הערה, תשובה לשאלה, או בקשה — Claude יענה כאן" aria-label="הערה ל-${esc(x.code)}"></textarea>
                        <div class="dt-new-acts"><label class="icon-btn dt-attach" title="צירוף צילום / PDF" aria-label="צירוף צילום">📎<input type="file" accept="image/*,application/pdf" multiple hidden></label>
                            <button type="button" class="btn primary sm" data-dt-send="${esc(x.code)}">שליחה</button></div></div>
                    <p class="hint dt-pick"></p>
                    <p class="error" role="alert" hidden></p>
                </div>
            </div></details></li>`;
    }

    async function refresh(code) { openCode = code || openCode; if (await load()) drawList(); }
    const fail = (el, msg) => { const e = el.closest('.dt-thread, .dt-verify')?.querySelector('.error'); if (e) { e.textContent = msg; e.hidden = false; } else toast(msg, true); };

    /** תמונה גדולה — מוקטנת בדפדפן לפני השליחה; PDF — כמו שהוא. */
    async function shrink(file) {
        if (!file.type.startsWith('image/') || file.type === 'image/gif' || !('createImageBitmap' in window)) return file;
        try {
            const bmp = await createImageBitmap(file);
            const k = Math.min(1, 1800 / Math.max(bmp.width, bmp.height));
            if (k === 1 && file.size < 1.5 * 1048576) return file;
            const c = document.createElement('canvas');
            c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.85));
            return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
        } catch { return file; }
    }
    async function attach(code, files, commentId = 0) {
        for (const f0 of files) {
            const f = await shrink(f0);
            const qs = new URLSearchParams({ action: 'dev-attach', code, name: f.name, comment_id: String(commentId || '') });
            const res = await fetch('api.php?' + qs, { method: 'POST', credentials: 'same-origin', body: f,
                headers: { 'X-CSRF': state.user?.csrf || '', 'X-Lang': I18n.lang(), Accept: 'application/json', 'Content-Type': f.type || 'application/octet-stream' } });
            if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || `תקלה (${res.status})`);
        }
    }

    async function onClick(e) {
        const f = e.target.closest('[data-dt-f]');
        if (f) { filter = f.dataset.dtF; query = ''; draw(); return; }
        const send = e.target.closest('[data-dt-send]');
        if (send) {
            const wrap = send.closest('.dt-new');
            const ta = wrap.querySelector('textarea'), inp = wrap.querySelector('input[type=file]');
            const files = [...(inp.files || [])];
            if (!ta.value.trim() && !files.length) return;
            send.disabled = true;
            try {
                const r = await api('dev-comment', { body: { code: send.dataset.dtSend, body: ta.value.trim() || '📎' } });
                if (files.length) await attach(send.dataset.dtSend, files, r.id);
                await refresh(send.dataset.dtSend);
            } catch (x) { fail(send, x.message); send.disabled = false; }
            return;
        }
        const ok = e.target.closest('[data-dt-ok], [data-dt-no]');
        if (ok) {
            const good = 'dtOk' in ok.dataset, code = ok.dataset.dtOk || ok.dataset.dtNo;
            const note = ok.closest('.dt-verify').querySelector('[data-verify-note]').value.trim();
            ok.disabled = true;
            try { await api('dev-verify', { body: { code, ok: good, note } }); toast(good ? `${code} נסגרה ✅` : `${code} חזרה ל-Claude`); await refresh(code); }
            catch (x) { fail(ok, x.message); ok.disabled = false; }
            return;
        }
        const tri = e.target.closest('[data-dt-tri]');
        if (tri) {
            const code = tri.dataset.code, ign = tri.dataset.dtTri === 'ignore';
            tri.disabled = true;
            try { await api('dev-task-set', { body: ign ? { code, ignore: true } : { code, status: 'open', from: 'triage' } }); toast(ign ? `${code} — מתעלמים` : `${code} עברה ל-Claude`); await refresh(code); }
            catch (x) { fail(tri, x.message); tri.disabled = false; }
            return;
        }
        const del = e.target.closest('[data-dt-del]');
        if (del) {
            try { await api('dev-comment-delete', { body: { id: Number(del.dataset.dtDel) } }); await refresh(del.dataset.code); }
            catch (x) { fail(del, x.message); }
            return;
        }
        const b = e.target.closest('[data-dt]');
        if (!b) return;
        if (b.dataset.dt === 'new') newTask();
        else if (b.dataset.dt === 'report') report();
        else if (b.dataset.dt === 'chat') copyChat();
        else if (b.dataset.dt === 'tokens') tokens();
    }

    async function onChange(e) {
        const pick = e.target.closest('.dt-attach input');
        if (pick) { const n = pick.files.length; pick.closest('.dt-thread').querySelector('.dt-pick').textContent = n ? `📎 ${n} קבצים יצורפו להערה` : ''; return; }
        const s = e.target.closest('[data-dt-status]');
        if (!s) return;
        try { await api('dev-task-set', { body: { code: s.dataset.dtStatus, status: s.value } }); await refresh(s.dataset.dtStatus); }
        catch (x) { toast(x.message, true); }
    }

    /** חלון קטן — כותרת, תוכן, וכפתור פעולה. */
    function sheet(title, html, actionLabel, onAction) {
        const dlg = document.createElement('dialog');
        dlg.className = 'dt-dlg';
        dlg.innerHTML = `<form method="dialog" novalidate><header class="sheet-head">
                <button type="button" class="icon-btn" data-close aria-label="סגירה">✕</button><h2>${esc(title)}</h2>
                ${actionLabel ? `<button type="button" class="btn primary" data-act>${esc(actionLabel)}</button>` : '<span></span>'}</header>
            <div class="sheet-body">${html}<p class="error" role="alert" hidden></p></div></form>`;
        document.body.appendChild(dlg);
        dlg.querySelector('[data-close]').onclick = () => dlg.close();
        dlg.addEventListener('close', () => dlg.remove());
        const err = dlg.querySelector('.error');
        dlg.fail = m => { err.textContent = m; err.hidden = false; };
        if (onAction) dlg.querySelector('[data-act]').onclick = async () => {
            const a = dlg.querySelector('[data-act]');
            a.disabled = true;
            try { await onAction(dlg); } finally { a.disabled = false; }
        };
        dlg.showModal();
        return dlg;
    }

    const opts = (map, val) => Object.entries(map).map(([k, [ic, l]]) => `<option value="${k}"${k === val ? ' selected' : ''}>${esc((ic ? ic + ' ' : '') + l)}</option>`).join('');

    function newTask() {
        sheet('מטלה חדשה', `<label class="f"><span>כותרת *</span><input name="title" maxlength="200" placeholder="מה צריך לעשות"></label>
            <div class="fields"><label class="f"><span>סוג</span><select name="kind">${opts(KINDS, 'task')}</select></label>
                <label class="f"><span>עדיפות</span><select name="priority">${opts(PRIO, 'normal')}</select></label></div>
            <label class="f"><span>תחום</span><input name="area" maxlength="40" placeholder="למשל: כספים, קבצים, קישור אישי"></label>
            <label class="f"><span>פירוט</span><textarea name="detail" rows="3" maxlength="4000"></textarea></label>
            <label class="f"><span>✔ איך יודעים שזה עובד (לא חובה)</span><textarea name="acceptance" rows="2" maxlength="2000" placeholder="למשל: הצילום מופיע גם אצל קבלן א' וגם אצל קבלן ב'"></textarea></label>
            <p class="hint">המטלה מקבלת קוד מיד. Claude יוסיף "איך בודקים" כשיסיים, ותקבלו אותה לאימות.</p>`, 'פתיחה', async dlg => {
            const el = dlg.querySelector('form').elements;
            try {
                const r = await api('dev-task-create', { body: { title: el.title.value, kind: el.kind.value, priority: el.priority.value,
                    area: el.area.value, detail: el.detail.value, acceptance: el.acceptance.value } });
                dlg.close(); openCode = r.code; filter = 'all'; query = '';
                if (box?.isConnected && await load()) draw();
                toast(`נפתחה ${r.code}`);
            } catch (x) { dlg.fail(x.message); }
        }).querySelector('[name=title]').focus();
    }

    /** ההקשר של המסך הנוכחי — בלי תוכן של משתמשים: כתובת, מסך, גרסה, בקשות (פעולה, קוד, זמן, מזהה) ושגיאות. */
    function snapshot() {
        const v = (document.querySelector('script[src*="core.js"]')?.src.match(/v=(\d+)/) || [])[1] || '';
        return { url: location.hash || '#/', screen: `${innerWidth}×${innerHeight}`, ua: navigator.userAgent, version: '0.' + v, lang: I18n.lang(),
            requests: PMTrace.requests.slice(-12), errors: PMTrace.errors.slice(-8), at: new Date().toISOString() };
    }

    /** 🐞 דיווח — מכל מסך (הכפתור בכותרת) או מכאן. */
    function report() {
        const ctx = snapshot();
        const bad = ctx.requests.filter(r => r.status >= 400).length;
        let files = [];
        const dlg = sheet('🐞 דיווח', `<label class="f"><span>מה קרה *</span><input name="title" maxlength="200" placeholder="למשל: המסך לא נגלל עד הסוף"></label>
            <label class="f"><span>פירוט (לא חובה)</span><textarea name="detail" rows="3" maxlength="4000" placeholder="מה עשיתם, מה ציפיתם שיקרה, ומה קרה"></textarea></label>
            <div class="fields"><label class="f"><span>סוג</span><select name="kind">${opts(KINDS, 'bug')}</select></label>
                <label class="f"><span>עדיפות</span><select name="priority">${opts(PRIO, 'normal')}</select></label></div>
            <label class="btn ghost upload-btn">📷 צילום מסך / קובץ<input type="file" name="files" accept="image/*,application/pdf" multiple hidden></label>
            <p class="hint dt-files-n"></p>
            <p class="hint">📎 נאסף לבד: המסך (<code dir="ltr">${esc(ctx.url)}</code>), גרסה ${esc(ctx.version)}, ${ctx.requests.length} בקשות אחרונות${bad ? ` (${bad} נכשלו)` : ''}, ${ctx.errors.length} שגיאות, והמכשיר. בלי תוכן של לקוחות.</p>
            ${$('#dev-bug-n') && !$('#dev-bug-n').hidden ? `<p class="hint"><a href="#/settings/tasks" data-go-tasks>📋 ${esc($('#dev-bug-n').textContent)} מטלות ממתינות לך</a></p>` : ''}`,
            'שליחה', async d => {
                const el = d.querySelector('form').elements;
                if (!el.title.value.trim()) { d.fail('מה קרה — שדה חובה'); return; }
                try {
                    const r = await api('dev-report', { body: { title: el.title.value, detail: el.detail.value, kind: el.kind.value, priority: el.priority.value, area: 'דיווח', context: ctx } });
                    if (files.length) await attach(r.code, files);
                    d.close();
                    toast(`${r.code} נפתחה — Claude יראה אותה עם הצילום וההקשר`);
                    if (box?.isConnected) { openCode = r.code; filter = 'all'; if (await load()) draw(); } else load();
                } catch (x) { d.fail(x.message); }
            });
        dlg.querySelector('[name=files]').onchange = e => { files = [...e.target.files]; dlg.querySelector('.dt-files-n').textContent = files.length ? `📎 ${files.map(f => f.name).join(', ')}` : ''; };
        dlg.querySelector('[data-go-tasks]')?.addEventListener('click', () => dlg.close());
        dlg.querySelector('[name=title]').focus();
    }

    function copyChat() {
        const text = chatText();
        sheet("העתקה לצ'אט", `<p class="hint">בלי שום חיבור: מעתיקים, ומדביקים ל-Claude בצ'אט. את התשובות הוא יכתוב בשיח כאן (עם אסימון), או בקוד.</p>
            <textarea rows="14" readonly dir="auto" aria-label="הטקסט להדבקה">${esc(text)}</textarea>`, 'העתקה', async dlg => {
            try { await navigator.clipboard.writeText(text); dlg.querySelector('[data-act]').textContent = '✓ הועתק'; }
            catch { dlg.querySelector('textarea').select(); dlg.querySelector('[data-act]').textContent = 'סמנו והעתיקו'; }
        });
    }

    async function tokens() {
        const origin = location.href.split('#')[0].replace(/[^/]*$/, '') + 'api.php';
        const dlg = sheet('🔑 גישה ל-Claude', `<p class="hint">אסימון אישי שנותנים ל-Claude פעם אחת (בצ'אט, או כסוד בהגדרות הסביבה שלו).
            איתו הוא קורא את המטלות, ההערות והצילומים, עונה בשיח ומצרף ראיות — ולא שום דבר אחר במערכת. כשאומרים לו "תקרא את המטלות" — הוא קורא.</p>
            <div class="dt-new-token"></div><div class="dt-tokens"></div>`, '+ אסימון חדש', async d => {
            try {
                const r = await api('dev-token-create', { body: { label: 'Claude' } });
                const say = `האסימון לקריאת המטלות של מנהל הפרויקטים: ${r.token}\nהכתובת: ${origin}?action=devapi-feed (בכותרת X-Dev-Token)`;
                d.querySelector('.dt-new-token').innerHTML = `<p class="dt-ok">✓ נוצר. העתיקו עכשיו — הוא לא יוצג שוב.</p>
                    <code class="dt-secret" dir="ltr">${esc(r.token)}</code><code class="dt-secret" dir="ltr">${esc(origin)}?action=devapi-feed</code>
                    <button type="button" class="btn ghost sm" data-copy>העתקה (שניהם יחד)</button>`;
                d.querySelector('[data-copy]').onclick = async e => {
                    try { await navigator.clipboard.writeText(say); e.target.textContent = '✓ הועתק'; } catch { prompt('להעתקה:', say); }
                };
                list2();
            } catch (x) { d.fail(x.message); }
        });
        async function list2() {
            let r;
            try { r = await api('dev-tokens'); } catch (x) { dlg.fail(x.message); return; }
            const box2 = dlg.querySelector('.dt-tokens');
            box2.innerHTML = r.tokens.length ? `<ul class="use-list">${r.tokens.map(t => `<li class="dt-token"><span class="use-tx"><b>${esc(t.label)}</b>
                <small>${esc(t.by)} · נוצר ${esc(dayTime(t.created_at))} · שימוש אחרון: ${t.last_used_at ? esc(dayTime(t.last_used_at)) : 'עוד לא'}</small></span>
                ${t.revoked ? '<span class="chip">בוטל</span>' : `<button type="button" class="btn ghost sm danger" data-revoke="${t.id}">ביטול</button>`}</li>`).join('')}</ul>`
                : '<p class="hint">אין אסימונים.</p>';
            box2.querySelectorAll('[data-revoke]').forEach(b => b.onclick = async () => {
                if (!confirm('לבטל את האסימון? Claude לא יוכל לקרוא איתו יותר.')) return;
                try { await api('dev-token-revoke', { body: { id: Number(b.dataset.revoke) } }); list2(); } catch (x) { dlg.fail(x.message); }
            });
        }
        list2();
    }

    /** כפתור 🐞 בכותרת — למפתח: דיווח מכל מסך, ועליו כמה מטלות ממתינות לו. */
    function mount() {
        if (!isDev() || $('#dev-bug')) return;
        const b = document.createElement('button');
        b.type = 'button';
        b.id = 'dev-bug';
        b.className = 'icon-btn dev-bug';
        b.setAttribute('aria-label', '🐞 דיווח, ומטלות שממתינות לך');
        b.innerHTML = '🐞<span class="dev-bug-n" id="dev-bug-n" hidden></span>';
        b.onclick = report;
        $('#me-btn')?.before(b);
        load();
    }

    return { show, mount, report };
})();
// i18n-none-end
