/**
 * 🛠️ דשבורד פיתוח — מחבר את DevLoop (devloop.js, מסונכרן) ל-API של הפלטפורמה (devboard.php).
 * התפקיד (מפתח / עוקב) נקבע בשרת; כאן רק מה שמוצג.
 */
(() => {
    const cfg = JSON.parse(document.getElementById('db-cfg').textContent);
    const endpoint = 'devboard.php?app=' + encodeURIComponent(cfg.app);
    const headers = () => ({ 'X-CSRF': cfg.csrf, 'X-Platform': '1' });

    async function api(action, { body, params } = {}) {
        const qs = new URLSearchParams({ action, ...(params || {}) });
        const res = await fetch(endpoint + '&' + qs, body === undefined
            ? { credentials: 'same-origin', headers: { Accept: 'application/json' } }
            : { method: 'POST', credentials: 'same-origin', headers: { ...headers(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || `תקלה (${res.status})`);
        return data;
    }

    let toastTimer;
    function toast(msg, bad) {
        let t = document.querySelector('.db-toast');
        if (!t) { t = document.createElement('div'); t.className = 'db-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
        // בתוך חלון פתוח — שם, אחרת הוא מתחת לרקע
        (document.querySelector('dialog[open]') || document.body).appendChild(t);
        t.textContent = msg; t.classList.toggle('bad', !!bad); t.hidden = false;
        clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, 3200);
    }

    DevLoop.setup({ api, toast, headers, endpoint, appName: cfg.name, role: cfg.role, isDev: () => cfg.role === 'dev',
        tasksHref: '#', builtinLog: false, version: () => '' });
    const board = document.getElementById('board');
    const open = () => DevLoop.show(board, decodeURIComponent(location.hash.slice(1)));
    addEventListener('hashchange', () => { tab('board'); open(); });
    open();

    // ── למפתח: 👥 משתמשים ו-📜 לוגים (עוקב לא רואה את הלשוניות, והשרת לא עונה לו עליהן) ──
    const $ = s => document.querySelector(s);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const when = iso => iso ? new Date(iso).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
    const legend = document.querySelector('.db-legend');
    function tab(name) {
        for (const b of document.querySelectorAll('.db-tabs [data-tab]')) b.ariaSelected = String(b.dataset.tab === name);
        for (const id of ['board', 'users', 'logs']) { const el = document.getElementById(id); if (el) el.hidden = id !== name; }
        if (legend) legend.hidden = name !== 'board';
        if (name === 'users') users();
        if (name === 'logs') logs();
    }
    document.querySelector('.db-tabs')?.addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (b) tab(b.dataset.tab); });

    async function users() {
        const box = $('#users');
        box.innerHTML = '<p class="hint">טוען…</p>';
        try {
            const [{ users: list }, { items }] = await Promise.all([api('db-users'), api('dev-items')]);
            const open = items.filter(x => x.status !== 'closed');
            box.innerHTML = `<p class="hint">העוקבים, וכל מי שנכנס לאפליקציה ב-90 הימים האחרונים. כל מטלה גלויה לכל עוקב — כאן מסתירים מטלה ממשתמש מסוים.
                עוקבים מוסיפים ומסירים בממשק המנהל → אפליקציות.</p>
                ${list.length ? `<ul class="db-users">${list.map(u => `<li><details class="db-user">
                    <summary class="use-row"><span class="use-ic">${u.follower ? '👁️' : u.role === 'admin' ? '🛠️' : '👤'}</span>
                        <span class="use-tx"><b>${esc(u.name)}</b><small>${[u.follower ? 'עוקב' : u.role === 'admin' ? 'מפתח' : 'משתמש',
                            `${u.visits} כניסות · אחרונה ${when(u.last_visit)}`, u.device, u.comments ? `💬 ${u.comments} · אחרונה ${when(u.last_comment)}` : '',
                            u.tasks ? `💡 ${u.tasks} פתח` : '', u.hidden.length ? `🙈 ${u.hidden.length} מוסתרות` : ''].filter(Boolean).map(esc).join(' · ')}</small></span></summary>
                    <div class="db-user-body">${u.role === 'admin' ? '<p class="hint">מפתח רואה הכול.</p>' : `<b>🙈 מטלות מוסתרות ממנו</b>
                        <div class="db-hide-list">${items.map(x => `<label class="small"><input type="checkbox" data-hide="${esc(x.code)}" data-user="${u.id}"${u.hidden.includes(x.code) ? ' checked' : ''}>
                            <code dir="ltr">${esc(x.code)}</code> ${esc(x.title)}${x.status === 'closed' ? ' <small>(סגורה)</small>' : ''}</label>`).join('')}</div>
                        <button type="button" class="btn ghost sm" data-logs-user="${u.id}">📜 הלוגים שלו</button>`}</div>
                </details></li>`).join('')}</ul>` : '<p class="empty">עוד אין עוקבים או כניסות לאפליקציה.</p>'}
                <p class="hint">${open.length} מטלות פתוחות · ${items.length - open.length} סגורות</p>`;
        } catch (x) { box.innerHTML = `<p class="error">${esc(x.message)}</p>`; }
    }
    $('#users')?.addEventListener('change', async e => {
        const c = e.target.closest('[data-hide]');
        if (!c) return;
        try { await api('db-hide', { body: { code: c.dataset.hide, user_id: Number(c.dataset.user), hidden: c.checked } }); toast(c.checked ? `${c.dataset.hide} הוסתרה ממנו` : `${c.dataset.hide} גלויה לו שוב`); }
        catch (x) { c.checked = !c.checked; toast(x.message, true); }
    });
    $('#users')?.addEventListener('click', e => { const b = e.target.closest('[data-logs-user]'); if (b) { logUser = b.dataset.logsUser; tab('logs'); } });

    let logUser = '', logQ = '';
    async function logs() {
        const box = $('#logs');
        if (!box.dataset.bound) {
            box.dataset.bound = '1';
            box.innerHTML = `<p class="hint">יומן האבחון של הפלטפורמה לאפליקציה הזו — כניסות, שגיאות בדפדפן והתקנות (30 יום).</p>
                <div class="row-btns"><input type="search" data-lq placeholder="חיפוש: אירוע, דף, משתמש" aria-label="חיפוש בלוגים">
                <button type="button" class="chip" data-lu hidden></button></div><ol class="dt-lrs" data-lout></ol>`;
            box.querySelector('[data-lq]').addEventListener('change', e => { logQ = e.target.value.trim(); logs(); });
            box.querySelector('[data-lu]').addEventListener('click', () => { logUser = ''; logs(); });
        }
        const lu = box.querySelector('[data-lu]');
        lu.hidden = !logUser; lu.textContent = 'משתמש אחד ✕';
        const out = box.querySelector('[data-lout]');
        out.innerHTML = '<li class="hint">טוען…</li>';
        try {
            const { rows } = await api('db-logs', { params: { q: logQ, ...(logUser ? { user: logUser } : {}) } });
            out.innerHTML = rows.map(r => {
                const bad = /error|fail|crash/i.test(r.event);
                const extra = r.info && (r.info.message || r.info.label) ? (r.info.message || r.info.label) : '';
                return `<li class="dt-lr${bad ? ' error' : ''}"><div><b dir="ltr">${esc(r.event)}</b> <small>${esc(when(r.at))}${r.user_name ? ' · ' + esc(r.user_name) : ''}</small></div>
                    ${extra ? `<p dir="auto">${esc(extra)}</p>` : ''}<small dir="ltr">${esc([r.path, r.device].filter(Boolean).join(' · '))}</small></li>`;
            }).join('') || '<li class="hint">אין שורות.</li>';
        } catch (x) { out.innerHTML = `<li class="error">${esc(x.message)}</li>`; }
    }
})();
