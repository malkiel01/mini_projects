// מחירוני קברים — המסכים: רשימת המחירונים, מחירון, ומסך המפתח. ההדפסה — print.html (assets/js/print.js).
'use strict';

const $ = (s, r = document) => r.querySelector(s);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const dmy = iso => iso.split('-').reverse().join('/');
const money = n => n == null ? '—' : n.toLocaleString('en-US') + ' ₪';
const view = $('#view');
let me = null;

async function api(action, { body, params } = {}) {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    const res = await fetch('api.php?' + qs, body ? { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': me?.csrf || '' }, body: JSON.stringify(body) } : { credentials: 'same-origin' });
    DevLoop.noteRequest({ action, method: body ? 'POST' : 'GET', status: res.status, id: res.headers.get('X-Request-Id') || '' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error || 'תקלה (' + res.status + ')'), { status: res.status });
    return data;
}

function toast(msg, err) {
    const t = Object.assign(document.createElement('div'), { className: 'toast' + (err ? ' err' : ''), textContent: msg, role: 'status' });
    document.body.append(t);
    setTimeout(() => t.remove(), 3000);
}

function head(title, sub = '', back = false) {
    $('#title').textContent = title;
    $('#sub').textContent = sub;
    $('#back').hidden = !back;
    document.title = title === 'מחירוני קברים' ? title : title + ' · מחירוני קברים';
}

/* ===== רשימת המחירונים ===== */
async function showList() {
    head('מחירוני קברים');
    const { editions } = await api('editions');
    view.innerHTML = editions.length ? `<ul class="list">${editions.map(e => `
        <li class="card" data-pid="${esc(e.pid)}" role="link" tabindex="0">
          <div class="body"><div class="l1"><span>בתוקף החל מ- ${esc(dmy(e.effective_from))}</span>
            ${e.in_effect ? '<span class="chip ok">בתוקף היום</span>' : ''}</div></div>
          <span class="use-go" aria-hidden="true">‹</span>
        </li>`).join('')}</ul>` : '<p class="empty">אין עדיין מחירונים</p>';
    view.querySelectorAll('[data-pid]').forEach(li => {
        const go = () => { location.hash = '#/e/' + li.dataset.pid; };
        li.onclick = go;
        li.onkeydown = e => { if (e.key === 'Enter') go(); };
    });
}

/* ===== מחירון ===== */
let audView = 'both';
async function showEdition(pid) {
    head('מחירון', '', true);
    const e = await api('edition', { params: { pid } });
    head('בתוקף החל מ- ' + dmy(e.effective_from), 'מחירון חברה קדישא לעדת המערבים', true);
    const names = e.audiences;
    const missing = Object.keys(names).filter(a => !e.rows.some(r => r.prices && r.prices[a] != null));
    const cell = (r, a) => r.prices ? `<td class="amt ${a === 'il' ? 'il' : 'ab'}${r.prices[a] == null ? ' none' : ''}">${money(r.prices[a])}</td>`
        : `<td class="${a === 'il' ? 'il' : 'ab'}"></td>`;
    view.innerHTML = `
      <div class="bar">
        <div class="seg aud-switch" role="radiogroup" aria-label="עמודות">
          ${[['il', 'ישראל'], ['abroad', 'חו"ל'], ['both', 'שתיהן']].map(([v, t]) =>
            `<label><input type="radio" name="aud" value="${v}"${v === audView ? ' checked' : ''}><span>${t}</span></label>`).join('')}
        </div>
        <button class="btn primary" id="print-btn">🖨️ הדפסה</button>
      </div>
      ${missing.map(a => `<p class="hint empty-aud">טרם הוזנו מחירים ל${esc(names[a])}</p>`).join('')}
      <table class="prices view-${audView}">
        <thead><tr><th>שורה</th><th class="amt il">${esc(names.il)}</th><th class="amt ab">${esc(names.abroad)}</th></tr></thead>
        <tbody>${e.rows.map(r => `<tr class="${r.kind}${r.indent ? ' indent' : ''}">
          <td${r.prices ? '' : ' colspan="3"'}>${r.html}</td>${r.prices ? cell(r, 'il') + cell(r, 'abroad') : ''}</tr>`).join('')}</tbody>
      </table>`;
    view.querySelectorAll('input[name=aud]').forEach(i => i.onchange = () => {
        audView = i.value;
        $('.prices', view).className = 'prices view-' + audView;
    });
    const dlg = $('#print-dlg');
    $('#print-btn').onclick = () => dlg.showModal();
    dlg.onclose = () => {
        if (dlg.returnValue) location.href = 'print.html?' + new URLSearchParams({ e: pid, mode: dlg.returnValue });
        dlg.returnValue = '';
    };
}

/* ===== מסך המפתח ===== */
const DEV_TABS = [['tasks', '📋 מטלות'], ['log', '📜 יומן'], ['audit', '🗂️ פעולות'], ['health', '🩺 תקינות']];
const ACTIONS = { login: 'כניסה', print: 'הדפסה' };
const MODES = { il: 'תושבי ישראל', abroad: 'תושבי חו"ל', split: 'דף נפרד לכל קבוצה', both: 'שתיהן בדף אחד' };
const when = iso => new Date(iso).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', year: '2-digit', hour: '2-digit', minute: '2-digit' });

async function showDev(tab = 'tasks', arg = '') {
    if (!me.is_dev) { location.hash = '#/'; return; }
    head('מסך המפתח', 'גרסה ' + me.version, true);
    view.innerHTML = `<div class="seg-tabs" role="tablist">${DEV_TABS.map(([k, t]) =>
        `<button role="tab" aria-selected="${k === tab}" data-tab="${k}">${t}</button>`).join('')}</div><div id="dev-body"></div>`;
    view.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { location.hash = '#/dev/' + b.dataset.tab; });
    const body = $('#dev-body');
    if (tab === 'tasks') return DevLoop.show(body, arg);
    if (tab === 'log') return DevLoop.showLog(body, arg);
    if (tab === 'audit') {
        const { audit } = await api('audit');
        body.innerHTML = audit.length ? `<ul class="list audit">${audit.map(a => `<li class="card"><div class="body">
            <div class="l1"><span>${esc(ACTIONS[a.action] || a.action)}${a.edition ? ' · ' + esc(dmy(a.edition)) : ''}${a.details.mode ? ' · ' + esc(MODES[a.details.mode] || a.details.mode) : ''}</span>
            <span class="t">${esc(when(a.at))}</span></div><div class="l2">${esc(a.user)}${a.details.via ? ' · ' + esc(a.details.via) : ''}</div></div></li>`).join('')}</ul>`
            : '<p class="empty">אין עדיין פעולות</p>';
        return;
    }
    const { checks } = await api('health');
    body.innerHTML = `<ul class="list checks">${checks.map(c => `<li class="card"><span aria-hidden="true">${c.ok ? '✅' : '❌'}</span>
        <div class="body"><div class="l1"><span>${esc(c.name)}</span><span class="t">${esc(c.value)}</span></div></div></li>`).join('')}</ul>`;
}

/* ===== כניסה (אפליקציה מנותקת) ===== */
function showLogin() {
    head('מחירוני קברים');
    const err = new URLSearchParams(location.search).get('login_error');
    view.innerHTML = `<div class="empty">
        <p>${err ? 'הכניסה לא הושלמה. נסו שוב.' : 'צריך להיכנס כדי לראות את המחירונים.'}</p><br>
        <a class="btn primary" href="api.php?action=sso_start">כניסה עם הכלים הדיגיטליים</a></div>`;
}

/* ===== ניתוב ===== */
async function route() {
    if ($('#print-dlg').open) $('#print-dlg').close();
    const [, a = '', b = '', c = ''] = location.hash.split('/');
    try {
        if (a === 'e' && b) await showEdition(b);
        else if (a === 'dev') await showDev(b || 'tasks', c);
        else await showList();
    } catch (e) {
        view.innerHTML = `<p class="error">${esc(e.message)}</p>`;
    }
    view.focus({ preventScroll: true });
}

(async () => {
    try {
        me = await api('me');
    } catch (e) {
        if (e.status === 401) return showLogin();
        view.innerHTML = `<p class="error">${esc(e.message)}</p>`;
        return;
    }
    $('#dev-btn').hidden = !me.is_dev;
    DevLoop.setup({ api, toast, isDev: () => me.is_dev, appName: 'מחירוני קברים', tasksHref: '#/dev/tasks', mountBefore: '#me-btn',
        headers: () => ({ 'X-CSRF': me.csrf }), version: () => me.version, logHref: r => '#/dev/log/' + r.req, builtinLog: true });
    DevLoop.mount();
    addEventListener('hashchange', route);
    route();
})();
