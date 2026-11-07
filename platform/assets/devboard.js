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
    addEventListener('hashchange', open);
    open();
})();
