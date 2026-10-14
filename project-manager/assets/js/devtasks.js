// אזור הפיתוח ← 📋 מטלות: קוד לכל מטלה (PM-001), שיח עם Claude, מטלה חדשה מהמסך, "העתקה לצ'אט",
// ואסימון ש-Claude קורא איתו. רק למפתח, בעברית. השרת: lib/devtasks.php; README: "מטלות ושיח".
// מועתק ממסמכי התבניות (document-templates/assets/js/devzone.js).
'use strict';

// i18n-none-start — אזור הפיתוח: למפתח בלבד, בעברית
const DevTasks = (() => {
    const KIND = { open: ['פתוח', 'warn', '🟡'], question: ['שאלה אליך', 'bad', '❓'], closed: ['סגור', 'ok', '✅'] };
    const dayTime = iso => iso ? new Date(iso).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
    let box = null, list = [], meta = {}, filter = 'pending', query = '', openCode = '';

    /** הטקסט שמדביקים לצ'אט — מטלות שממתינות ל-Claude (או כל הפתוחות). */
    function chatText() {
        const waiting = list.filter(x => x.waiting === 'claude');
        const pick = waiting.length ? waiting : list.filter(x => x.status !== 'closed');
        const head = waiting.length ? 'הערות שלי על מטלות במנהל הפרויקטים — ממתינות לתשובה:' : 'המטלות הפתוחות במנהל הפרויקטים:';
        return [head, ...pick.map(x => {
            const [label] = KIND[x.status] || [x.status];
            const thread = x.comments.map(c => `  ${c.author === 'claude' ? 'Claude' : 'אני'} (${dayTime(c.at)}): ${c.body}`);
            return [`\n${x.code} · ${x.title} [${label}]`, ...(x.questions || []).map(q => '  ❓ ' + q), ...thread].join('\n');
        })].join('\n');
    }

    async function load() {
        try { const r = await api('dev-items'); list = r.items || []; meta = r; return true; }
        catch (e) { box.innerHTML = `<p class="error">${esc(e.message)}</p>`; return false; }
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
        draw();
    }

    const count = fn => list.filter(fn).length;
    const pass = x => ({ pending: x.status !== 'closed', claude: x.waiting === 'claude', closed: x.status === 'closed', all: true })[filter];
    const match = x => !query || x.code.toLowerCase() === query.toLowerCase()
        || [x.code, x.title, x.area, x.detail, ...(x.questions || []), ...x.comments.map(c => c.body)].join(' ').toLowerCase().includes(query.toLowerCase());

    function draw() {
        const filters = [['pending', `פתוחים ושאלות (${count(x => x.status !== 'closed')})`], ['claude', `ממתינות ל-Claude (${count(x => x.waiting === 'claude')})`],
            ['closed', `סגורים (${count(x => x.status === 'closed')})`], ['all', 'הכול']];
        box.innerHTML = `
            <div class="dt-stats"><b>${count(x => x.status === 'question')}</b> שאלות אליך · <b>${count(x => x.status === 'open')}</b> פתוחים ·
                <b>${count(x => x.status === 'closed')}</b> סגורים · <b>${count(x => x.waiting === 'claude')}</b> ממתינות ל-Claude
                <small>· עודכן ${esc(meta.updated || '—')}</small></div>
            ${meta.note ? `<p class="hint">${esc(meta.note)}</p>` : ''}
            <div class="row-btns wrap">
                <button type="button" class="btn primary sm" data-dt="new">+ מטלה חדשה</button>
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
        const shown = list.filter(x => (query ? true : pass(x)) && match(x));
        out.innerHTML = shown.length ? `<ul class="dt-items">${shown.map(item).join('')}</ul>`
            : `<p class="empty">${query ? 'לא נמצאה מטלה.' : 'אין פריטים.'}</p>`;
        const target = openCode && out.querySelector(`details[data-code="${CSS.escape(openCode)}"]`);
        if (target) requestAnimationFrame(() => target.scrollIntoView({ block: 'nearest' }));
    }

    function item(x) {
        const [label, kind, ic] = KIND[x.status] || [x.status, '', '•'];
        const last = x.comments[x.comments.length - 1];
        const sub = [x.area, x.closed ? 'נסגר ' + x.closed : x.opened ? 'נפתח ' + x.opened : '', x.pr ? 'PR #' + x.pr : '',
            x.source === 'user' ? 'נפתח מהמסך' : '', x.comments.length ? '💬 ' + x.comments.length : ''].filter(Boolean).join(' · ');
        return `<li><details class="dt-item" data-code="${esc(x.code)}"${x.code === openCode ? ' open' : ''}>
            <summary class="use-row"><span class="use-ic" aria-hidden="true">${ic}</span>
                <span class="use-tx"><b><code class="dt-code" dir="ltr">${esc(x.code)}</code> ${esc(x.title)}</b><small>${esc(sub)}</small></span>
                <span class="dt-badges">${x.waiting === 'claude' ? '<span class="chip dt-warn">ממתין ל-Claude</span>' : ''}${x.waiting === 'user' ? '<span class="chip dt-info">Claude ענה</span>' : ''}<span class="chip dt-${kind}">${esc(label)}</span></span>
            </summary>
            <div class="dt-body">
                ${x.detail ? `<p dir="auto">${esc(x.detail)}</p>` : ''}
                ${x.questions?.length ? `<div class="dt-questions"><b>שאלות פתוחות:</b><ul>${x.questions.map(q => `<li>${esc(q)}</li>`).join('')}</ul></div>` : ''}
                ${x.source === 'user' ? `<label class="f dt-status"><span>מצב</span><select data-dt-status="${esc(x.code)}">${Object.entries(KIND).map(([k, [l]]) =>
                    `<option value="${k}"${x.status === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></label>` : ''}
                <div class="dt-thread"><b>💬 שיח</b>
                    ${x.comments.length ? `<ol class="dt-comments">${x.comments.map(c => `<li class="dt-c ${c.author}">
                        <div class="dt-c-head"><b>${c.author === 'claude' ? '🤖 Claude' : '👤 ' + esc(c.name)}</b><small>${esc(dayTime(c.at))}</small>
                            ${c.author === 'user' && c === last ? `<button type="button" class="link" data-dt-del="${c.id}" data-code="${esc(x.code)}">מחיקה</button>` : ''}</div>
                        <p dir="auto">${esc(c.body)}</p></li>`).join('')}</ol>` : '<p class="hint">אין עדיין הערות.</p>'}
                    ${x.waiting === 'claude' ? `<p class="hint">⏳ ממתין ל-Claude — תגידו לו "תקרא את המטלות", או "📋 העתקה לצ'אט" למעלה.</p>` : ''}
                    <div class="dt-new"><textarea rows="2" maxlength="4000" placeholder="הערה, תשובה לשאלה, או בקשה — Claude יענה כאן" aria-label="הערה ל-${esc(x.code)}"></textarea>
                        <button type="button" class="btn primary sm" data-dt-send="${esc(x.code)}">שליחה</button></div>
                    <p class="error" role="alert" hidden></p>
                </div>
            </div></details></li>`;
    }

    async function refresh(code) { openCode = code || openCode; if (await load()) drawList(); }
    const fail = (el, msg) => { const e = el.closest('.dt-thread')?.querySelector('.error'); if (e) { e.textContent = msg; e.hidden = false; } else toast(msg, true); };

    async function onClick(e) {
        const f = e.target.closest('[data-dt-f]');
        if (f) { filter = f.dataset.dtF; query = ''; draw(); return; }
        const send = e.target.closest('[data-dt-send]');
        if (send) {
            const ta = send.parentElement.querySelector('textarea');
            if (!ta.value.trim()) return;
            send.disabled = true;
            try { await api('dev-comment', { body: { code: send.dataset.dtSend, body: ta.value } }); await refresh(send.dataset.dtSend); }
            catch (x) { fail(send, x.message); send.disabled = false; }
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
        else if (b.dataset.dt === 'chat') copyChat();
        else if (b.dataset.dt === 'tokens') tokens();
    }

    async function onChange(e) {
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
        if (onAction) dlg.querySelector('[data-act]').onclick = () => onAction(dlg);
        dlg.showModal();
        return dlg;
    }

    function newTask() {
        sheet('מטלה חדשה', `<label class="f"><span>כותרת *</span><input name="title" maxlength="200" placeholder="מה צריך לעשות"></label>
            <label class="f"><span>תחום</span><input name="area" maxlength="40" placeholder="למשל: כספים, קבצים, קישור אישי"></label>
            <label class="f"><span>פירוט</span><textarea name="detail" rows="4" maxlength="4000" placeholder="פירוט (לא חובה)"></textarea></label>
            <p class="hint">המטלה מקבלת קוד מיד. Claude יראה אותה בפעם הבאה שיקרא את המטלות.</p>`, 'פתיחה', async dlg => {
            const el = dlg.querySelector('form').elements;
            try {
                const r = await api('dev-task-create', { body: { title: el.title.value, area: el.area.value, detail: el.detail.value } });
                dlg.close(); openCode = r.code; filter = 'pending'; query = '';
                if (await load()) draw();
                toast(`נפתחה ${r.code}`);
            } catch (x) { dlg.fail(x.message); }
        }).querySelector('[name=title]').focus();
    }

    function copyChat() {
        const text = chatText();
        sheet("העתקה לצ'אט", `<p class="hint">בלי שום חיבור: מעתיקים, ומדביקים ל-Claude בצ'אט. את התשובות הוא יכתוב בקוד, או בשיח כאן (עם אסימון).</p>
            <textarea rows="14" readonly dir="auto" aria-label="הטקסט להדבקה">${esc(text)}</textarea>`, 'העתקה', async dlg => {
            try { await navigator.clipboard.writeText(text); dlg.querySelector('[data-act]').textContent = '✓ הועתק'; }
            catch { dlg.querySelector('textarea').select(); dlg.querySelector('[data-act]').textContent = 'סמנו והעתיקו'; }
        });
    }

    async function tokens() {
        const origin = location.href.split('#')[0].replace(/[^/]*$/, '') + 'api.php';
        const dlg = sheet('🔑 גישה ל-Claude', `<p class="hint">אסימון אישי שנותנים ל-Claude פעם אחת (בצ'אט, או כסוד בהגדרות הסביבה שלו).
            איתו הוא קורא את המטלות ואת ההערות ועונה בשיח — ולא שום דבר אחר במערכת. כשאומרים לו "תקרא את המטלות" — הוא קורא.</p>
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

    return { show };
})();
// i18n-none-end
