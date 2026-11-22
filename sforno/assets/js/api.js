/*
 * ספורנו — הקריאה לשרת. כל הפעולות דרך api.php (נתיב יחסי — עובד בכל תיקייה ובכל דומיין).
 * GET כשאין גוף; POST עם JSON, X-CSRF (מגיע מ-me) ו-X-Reader-CSRF (מגיע מ-account-me). שגיאה → Error עם code, status ו-message בעברית.
 */
'use strict';

window.SF = window.SF || {};

(function (SF) {
    let csrf = '';
    let readerCsrf = '';

    SF.setCsrf = t => { csrf = t || ''; };
    /* הקורא (05) — CSRF משלו, בכותרת נפרדת: עולם החשבונות לא נשען על זה של המנהלים */
    SF.setReaderCsrf = t => { readerCsrf = t || ''; };
    SF.headers = () => (csrf ? { 'X-CSRF': csrf } : {});

    SF.api = async function api(action, opts = {}) {
        const post = opts.body !== undefined || opts.method === 'POST';
        const q = new URLSearchParams({ action, ...(opts.params || {}) });
        const t0 = performance.now();
        let res, data = null;
        try {
            res = await fetch('api.php?' + q, {
                method: post ? 'POST' : 'GET',
                credentials: 'same-origin',
                headers: post ? { 'Content-Type': 'application/json', ...SF.headers(), ...(readerCsrf ? { 'X-Reader-CSRF': readerCsrf } : {}) } : { Accept: 'application/json' },
                body: post ? JSON.stringify(opts.body || {}) : undefined,
            });
        } catch (e) {
            const err = new Error('אין חיבור לשרת. בדקו את החיבור לרשת ונסו שוב');
            err.code = 'network'; err.status = 0;
            throw err;
        }
        try { data = await res.json(); } catch (e) { data = null; }
        if (window.DevLoop) DevLoop.noteRequest({ action, method: post ? 'POST' : 'GET', status: res.status, id: res.headers.get('X-Request-Id') || '', ms: Math.round(performance.now() - t0) });
        if (!res.ok || !data || data.ok === false || data.error) {
            const e = data && data.error;
            const err = new Error((e && (e.message || (typeof e === 'string' ? e : ''))) || 'תקלה בשרת. נסו שוב');
            err.code = (e && e.code) || 'server'; err.status = res.status;
            if (e && e.details) err.details = e.details;
            throw err;
        }
        return data;
    };

    /* בניית DOM בלי innerHTML לנתונים: h('button', {class: 'btn', onclick}, 'טקסט', ילד…) */
    SF.h = function h(tag, attrs, ...kids) {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
            else if (k === 'class') el.className = v;
            else if (k === 'dataset') Object.assign(el.dataset, v);
            else el.setAttribute(k, v === true ? '' : String(v));
        }
        for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) el.append(kid instanceof Node ? kid : String(kid));
        return el;
    };

    /** replaceChildren בלי null / false (רכיב שלא מוצג). */
    SF.put = (el, ...kids) => { el.replaceChildren(...kids.flat(Infinity).filter(k => k !== null && k !== undefined && k !== false && k !== '')); return el; };

    let toastTimer = 0;
    SF.toast = function toast(msg, isErr = false) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast' + (isErr ? ' err' : '');
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { t.hidden = true; }, 3200);
    };

    /* תאריך ISO (UTC) → מקומי קצר */
    SF.when = function when(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        return isNaN(d) ? iso : d.toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' });
    };
})(window.SF);
