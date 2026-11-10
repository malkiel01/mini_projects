/*
 * התשתית של הדפדפן: קריאה ל-API, טוסט, גיליון עריכה, ועזרים קטנים.
 * כל הרשאה נאכפת בשרת — כאן רק מסתירים מה שלא רלוונטי.
 */
'use strict';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const App = { me: null, main: null };

/**
 * api(action, { body, params }) → ה-JSON של התשובה, או זורק Error עם ההודעה בעברית (.code, .status, .req).
 * body → POST עם X-CSRF; אחרת GET. זה גם החוזה ש-DevLoop מצפה לו.
 */
async function api(action, { body, params } = {}) {
    try {
        return await apiOnce(action, { body, params });
    } catch (x) {
        // פעולה רגישה (אפיון 02 §6): השרת דורש אישור חוזר — מבקשים אותו מהמשתמש, ושולחים שוב
        if (x.code !== 'confirm_required' || body === undefined) throw x;
        return apiOnce(action, { body: { ...body, ...await confirmSensitive(action) }, params });
    }
}

/**
 * אישור חוזר: במצב מחוברת — הקלדת מילת האישור; במנותקת — קוד בן 6 ספרות שנשלח למייל.
 * מחזיר את מה שמצטרף לגוף הבקשה. ביטול — זורק.
 */
async function confirmSensitive(action) {
    const what = T.sensitive[action] || '';
    const cancelled = () => Object.assign(new Error(T.confirmCancelled), { code: 'confirm_cancelled', status: 0, req: '' });
    if (App.me?.mode === 'detached') {
        await apiOnce('confirm-request', { body: {} });
        const code = prompt(T.confirmCodeAsk(what));
        if (!code) throw cancelled();
        await apiOnce('confirm-verify', { body: { code: code.trim() } });
        return {};
    }
    const word = App.me?.confirm_word || 'אישור';
    if ((prompt(T.confirmWordAsk(what, word)) || '').trim() !== word) throw cancelled();
    return { confirm: word };
}

async function apiOnce(action, { body, params } = {}) {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    const init = { credentials: 'same-origin' };
    if (body !== undefined) {
        init.method = 'POST';
        init.headers = { 'Content-Type': 'application/json', 'X-CSRF': App.me?.csrf || '' };
        init.body = JSON.stringify(body);
    }
    const method = init.method || 'GET';
    let res;
    try {
        res = await fetch('api.php?' + qs, init);
    } catch {
        DevLoop.noteRequest({ action, method, status: 0, id: '' });
        throw Object.assign(new Error(T.noConnection), { code: 'network', status: 0, req: '' });
    }
    const req = res.headers.get('X-Request-Id') || '';
    DevLoop.noteRequest({ action, method, status: res.status, id: req });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        // האפליקציה: {error: {code, message}}; DevLoop: {error: "…"}
        const e = data.error;
        throw Object.assign(new Error((typeof e === 'string' ? e : e?.message) || T.fault(res.status)), { code: e?.code || data.code || '', status: res.status, req });
    }
    return data;
}

function toast(msg, err = false) {
    const el = $('.toast');
    el.textContent = msg;
    el.classList.toggle('err', !!err);
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, err ? 6000 : 2600);
}

/** שגיאה בטוסט; תקלת שרת — עם מזהה הבקשה, שלפיו מוצאים אותה ביומן. */
function toastError(x) {
    toast(x.message + (x.status >= 500 && x.req ? ' · ' + T.reqId(x.req) : ''), true);
}

/** מצב שגיאה של מסך: ההודעה, "נסו שוב", ומזהה הבקשה. */
function errorHtml(x) {
    return `<div class="notice"><div class="big" aria-hidden="true">⚠️</div><h2>${esc(x.message)}</h2>
        ${x.req ? `<p class="muted" dir="auto">${esc(T.reqId(x.req))}</p>` : ''}
        <div><button class="btn ghost" data-retry>${T.retry}</button></div></div>`;
}

// ── עזרים ────────────────────────────────────────────────────
const initials = name => String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');
const hue = s => [...String(s)].reduce((h, c) => (h * 31 + c.codePointAt(0)) % 360, 7);
// זמן נשמר ב-UTC ומוצג בשעון ישראל
const TZ = 'Asia/Jerusalem';
const fmtDate = iso => iso ? new Date(iso).toLocaleDateString('he-IL', { timeZone: TZ, day: 'numeric', month: 'numeric', year: 'numeric' }) : '';
const fmtDay = iso => new Date(iso).toLocaleDateString('he-IL', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long' });
const fmtClock = iso => new Date(iso).toLocaleTimeString('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
const fmtDateTime = iso => iso ? `${fmtDate(iso)} ${fmtClock(iso)}` : '';
const seen = iso => {
    if (!iso) return T.seenNever;
    const d = Math.floor((Date.now() - new Date(iso)) / 86400000);
    return d <= 0 ? T.seenToday : d === 1 ? T.seenYesterday : T.seenDays(d);
};
const roleLabel = (role, isAdmin) => (T.roles[role] || role) + (isAdmin ? ' · ' + T.admin : '');
const opId = () => 'op-' + crypto.getRandomValues(new Uint32Array(3)).reduce((s, n) => s + n.toString(36), '');

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const ta = Object.assign(document.createElement('textarea'), { value: text });
        ($('dialog[open]') || document.body).append(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }
    toast(T.copied);
}

function setHead(title, sub = '') {
    $('#title').textContent = title;
    document.title = title === T.app ? title : `${title} · ${T.app}`;
    if (sub !== null) App.sub = sub;
}

/** לשוניות בתוך מסך: [[href, label, count?], …]. */
const tabsHtml = (tabs, current) => `<div class="seg-tabs" role="tablist">${tabs.map(([href, label, count]) =>
    `<button role="tab" aria-selected="${href === current}" data-go="${href}">${label}${count !== undefined ? ` <small>${count}</small>` : ''}</button>`).join('')}</div>`;

const roleField = (value = 'appraiser', roles = Object.keys(T.roles)) => `<fieldset class="seg" aria-label="${T.role}">${roles.map(k =>
    `<label><input type="radio" name="role" value="${k}"${k === value ? ' checked' : ''}><span>${T.roles[k]}</span></label>`).join('')}</fieldset>`;

/**
 * גיליון עריכה. onSave(ערכי הטופס, הגיליון) — הגיליון נסגר כשההבטחה מצליחה (אלא אם החזירה false);
 * שגיאה מוצגת בתוכו. onSave ריק — גיליון לצפייה בלבד.
 */
function sheet(title, html, onSave, saveLabel = T.save) {
    const dlg = $('#sheet');
    $('#sheet-title').textContent = title;
    $('#sheet-body').innerHTML = html + '<p class="error" role="alert" hidden></p>';
    const save = $('#sheet-save');
    save.hidden = !onSave;
    save.textContent = saveLabel;
    save.disabled = false;
    dlg.querySelector('form').onsubmit = async e => {
        e.preventDefault();
        if (!onSave || save.disabled) return;
        const err = $('.error', dlg);
        err.hidden = true;
        save.disabled = true;
        try {
            if (await onSave(Object.fromEntries(new FormData(e.target)), dlg) !== false) dlg.close();
        } catch (x) {
            err.textContent = x.message + (x.status >= 500 && x.req ? ' · ' + T.reqId(x.req) : '');
            err.hidden = false;
        }
        save.disabled = false;
    };
    if (!dlg.open) dlg.showModal();
    return dlg;
}

/** שורה לחיצה: גם בלחיצה וגם ב-Enter / רווח. */
function onActivate(el, fn) {
    el.addEventListener('click', fn);
    el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(e); } });
}
