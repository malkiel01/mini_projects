// מחירוני קברים — המסכים: רשימת המחירונים, מחירון (צפייה ועריכה), מחירון חדש, ומסך המפתח. ההדפסה — print.html (assets/js/print.js).
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
    if (!res.ok) throw Object.assign(new Error(data.error || 'תקלה (' + res.status + ')'), { status: res.status, data });
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
    view.innerHTML = `<div class="bar"><button class="btn primary" id="new-btn">➕ מחירון חדש</button></div>
      ${editions.length ? `<ul class="list">${editions.map(e => `
        <li class="card" data-pid="${esc(e.pid)}" role="link" tabindex="0">
          <div class="body"><div class="l1"><span>בתוקף החל מ- ${esc(dmy(e.effective_from))}</span>
            ${e.in_effect ? '<span class="chip ok">בתוקף היום</span>' : ''}${e.future ? '<span class="chip warn">עתידי</span>' : ''}</div></div>
          <span class="use-go" aria-hidden="true">‹</span>
        </li>`).join('')}</ul>` : '<p class="empty">אין עדיין מחירונים</p>'}`;
    $('#new-btn').hidden = !editions.length;
    $('#new-btn').onclick = () => { location.hash = '#/new'; };
    view.querySelectorAll('[data-pid]').forEach(li => {
        const go = () => { location.hash = '#/e/' + li.dataset.pid; };
        li.onclick = go;
        li.onkeydown = e => { if (e.key === 'Enter') go(); };
    });
}

/* ===== שאלת אישור ===== */
function ask(msg, ok = 'אישור') {
    const dlg = $('#ask-dlg');
    $('#ask-msg').textContent = msg;
    $('#ask-ok').textContent = ok;
    dlg.returnValue = '';
    dlg.showModal();
    return new Promise(res => dlg.addEventListener('close', () => res(dlg.returnValue === 'ok'), { once: true }));
}

/* ===== עריכה (02-editing.md) ===== */
let editPid = '';      // המחירון שבמצב עריכה
let confirmed = '';    // אישור "כבר בתוקף" — תקף עד סוף מצב העריכה

/** כתיבה במחירון. מחירון שכבר בתוקף — השרת מחזיר 409 need_confirm פעם אחת, שואלים, ושולחים שוב. */
async function edit(action, body) {
    try {
        return await api(action, { body: { ...body, confirm_in_effect: confirmed === body.pid } });
    } catch (e) {
        if (!e.data?.need_confirm) throw e;
        if (!await ask('המחירון הזה כבר בתוקף. לתקן אותו?', 'כן, לתקן')) throw Object.assign(new Error('בוטל'), { cancelled: true });
        confirmed = body.pid;
        return api(action, { body: { ...body, confirm_in_effect: true } });
    }
}

const labelHtml = s => esc(s).replace(/\(\((.+?)\)\)/gs, '<span class="plain">($1)</span>')
    .replace(/\*\*(.+?)\*\*/gs, '<b>$1</b>').replace(/__(.+?)__/gs, '<u>$1</u>');
const plain = s => String(s ?? '').replace(/\*\*|__/g, '').replace(/\(\(/g, '(').replace(/\)\)/g, ')').trim();
const num = n => n == null ? 'ריק' : n.toLocaleString('en-US');
const AUD = { il: 'ישראל', abroad: 'חו"ל' };

async function rerender(pid) {
    const y = scrollY;
    await showEdition(pid);
    scrollTo(0, y);
}

/* ===== מחירון ===== */
let audView = 'both';
async function showEdition(pid) {
    head('מחירון', '', true);
    const e = await api('edition', { params: { pid } });
    if (editPid !== pid) confirmed = '';
    const editing = editPid === pid;
    head('בתוקף החל מ- ' + dmy(e.effective_from), 'מחירון חברה קדישא לעדת המערבים', true);
    const names = e.audiences;
    const missing = Object.keys(names).filter(a => !e.rows.some(r => r.prices && r.prices[a] != null));
    const cell = (r, a) => {
        const c = a === 'il' ? 'il' : 'ab';
        if (editing) return `<td class="amt ${c}"><input class="price" inputmode="numeric" autocomplete="off" enterkeyhint="next"
            data-row="${esc(r.pid)}" data-aud="${a}" value="${r.prices[a] ?? ''}" aria-label="${esc(plain(r.label))} · ${esc(names[a])}"><small class="st" aria-live="polite"></small></td>`;
        return `<td class="amt ${c}${r.prices[a] == null ? ' none' : ''}">${money(r.prices[a])}</td>`;
    };
    const lb = r => editing ? ` class="lb" data-row="${esc(r.pid)}" tabindex="0" role="button" aria-label="עריכת השורה: ${esc(plain(r.label))}"` : ' class="lb"';
    view.innerHTML = `
      <div class="bar">
        <div class="seg aud-switch" role="radiogroup" aria-label="עמודות">
          ${[['il', 'ישראל'], ['abroad', 'חו"ל'], ['both', 'שתיהן']].map(([v, t]) =>
            `<label><input type="radio" name="aud" value="${v}"${v === audView ? ' checked' : ''}><span>${t}</span></label>`).join('')}
        </div>
        <button class="icon-btn ghost${editing ? ' on' : ''}" id="edit-btn" aria-pressed="${editing}" aria-label="עריכה" title="עריכה">✏️</button>
        <button class="icon-btn ghost" id="hist-btn" aria-label="שינויים" title="שינויים">📜</button>
        <button class="btn primary" id="print-btn">🖨️ הדפסה</button>
      </div>
      ${editing ? `<div class="edit-note"><span>✏️ מצב עריכה — כל שינוי נשמר לבד. לחיצה על שורה — לעריכתה.</span>
        <button class="btn sm primary" id="done-btn">סיום</button></div>` : ''}
      ${missing.map(a => `<p class="hint empty-aud">טרם הוזנו מחירים ל${esc(names[a])}</p>`).join('')}
      <table class="prices view-${audView}${editing ? ' editing' : ''}">
        <thead><tr><th>שורה</th><th class="amt il">${esc(names.il)}</th><th class="amt ab">${esc(names.abroad)}</th></tr></thead>
        <tbody>${e.rows.map(r => `<tr class="${r.kind}${r.indent ? ' indent' : ''}">
          <td${lb(r)}${r.prices ? '' : ' colspan="3"'}>${r.html}</td>${r.prices ? cell(r, 'il') + cell(r, 'abroad') : ''}</tr>`).join('')}</tbody>
      </table>
      ${editing ? editPanel(e) : ''}`;
    view.querySelectorAll('input[name=aud]').forEach(i => i.onchange = () => {
        audView = i.value;
        $('.prices', view).className = 'prices view-' + audView + (editing ? ' editing' : '');
    });
    const toggle = () => { editPid = editing ? '' : pid; confirmed = ''; rerender(pid); };
    $('#edit-btn').onclick = toggle;
    if (editing) $('#done-btn').onclick = toggle;
    $('#hist-btn').onclick = () => showHistory(pid);
    const dlg = $('#print-dlg');
    $('#print-btn').onclick = () => dlg.showModal();
    dlg.onclose = () => {
        if (dlg.returnValue) location.href = 'print.html?' + new URLSearchParams({ e: pid, mode: dlg.returnValue });
        dlg.returnValue = '';
    };
    if (editing) bindEdit(e);
}

function editPanel(e) {
    return `<h2 class="sec-title">חתימות</h2>
      <div class="sig-edit">${e.signatures.map((s, i) => `<div class="fields two">
        <label class="f"><span>שם ${i + 1}</span><input data-sig="${i}" data-k="name" value="${esc(s.name)}" maxlength="100"></label>
        <label class="f"><span>תפקיד ${i + 1}</span><input data-sig="${i}" data-k="role" value="${esc(s.role)}" maxlength="100"></label></div>`).join('')}
        <small class="st" id="sig-st" aria-live="polite"></small></div>
      <div class="row-btns wrap tail-btns"><button class="btn ghost" id="add-end">➕ שורה בסוף</button></div>
      ${e.future ? `<h2 class="sec-title">המחירון</h2>
        <div class="fields two"><label class="f"><span>בתוקף החל מ-</span><input type="date" id="date-in" value="${esc(e.effective_from)}" required></label></div>
        <div class="row-btns wrap tail-btns"><button class="btn ghost danger" id="del-btn">🗑️ מחיקת המחירון</button></div>` : ''}`;
}

function bindEdit(e) {
    const pid = e.pid;
    // מחיר: שמירה בעזיבת השדה
    view.querySelectorAll('input.price').forEach(inp => {
        inp.dataset.saved = inp.value;
        inp.onkeydown = ev => { if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); } };
        inp.onchange = async () => {
            const v = inp.value.replace(/[,\s₪]/g, '');
            const st = inp.nextElementSibling;
            if (v === inp.dataset.saved) { inp.value = v; inp.classList.remove('bad'); st.textContent = ''; return; }
            st.className = 'st'; st.textContent = '…';
            try {
                const r = await edit('price-set', { pid, row: inp.dataset.row, audience: inp.dataset.aud, amount: v === '' ? null : v });
                inp.value = inp.dataset.saved = r.amount ?? '';
                inp.classList.remove('bad');
                st.className = 'st ok'; st.textContent = 'נשמר ✓';
            } catch (err) {
                if (err.cancelled) { inp.value = inp.dataset.saved; st.textContent = ''; return; }
                inp.classList.add('bad');
                st.className = 'st err'; st.textContent = err.message;
            }
        };
    });
    // שורה: לחיצה על הטקסט
    view.querySelectorAll('td.lb[data-row]').forEach(td => {
        const open = () => openRow(e, e.rows.find(r => r.pid === td.dataset.row));
        td.onclick = open;
        td.onkeydown = ev => { if (ev.key === 'Enter') open(); };
    });
    $('#add-end').onclick = () => openRow(e, null, e.rows.at(-1)?.pid || '');
    // חתימות: כל שינוי שומר את שלושתן
    view.querySelectorAll('[data-sig]').forEach(inp => inp.onchange = async () => {
        const sigs = [0, 1, 2].map(i => ({ name: $(`[data-sig="${i}"][data-k=name]`, view).value, role: $(`[data-sig="${i}"][data-k=role]`, view).value }));
        const st = $('#sig-st');
        st.className = 'st'; st.textContent = '…';
        try {
            await edit('signatures-set', { pid, signatures: sigs });
            st.className = 'st ok'; st.textContent = 'נשמר ✓';
        } catch (err) {
            if (err.cancelled) return rerender(pid);
            st.className = 'st err'; st.textContent = err.message;
        }
    });
    if (!e.future) return;
    $('#date-in').onchange = async ev => {
        try {
            await api('edition-update', { body: { pid, effective_from: ev.target.value } });
            toast('התאריך עודכן');
            rerender(pid);
        } catch (err) {
            toast(err.message, true);
            ev.target.value = e.effective_from;
        }
    };
    $('#del-btn').onclick = async () => {
        if (!await ask(`למחוק את המחירון של ${dmy(e.effective_from)}? אי אפשר לבטל.`, '🗑️ מחיקה')) return;
        try {
            await api('edition-delete', { body: { pid } });
            editPid = '';
            toast('המחירון נמחק');
            location.hash = '#/';
        } catch (err) { toast(err.message, true); }
    };
}

/** עריכת שורה (r) או שורה חדשה מתחת ל-after. */
function openRow(e, r, after = '') {
    const dlg = $('#row-dlg'), form = $('#row-form');
    const label = $('#row-label'), kind = $('#row-kind'), indent = $('#row-indent'), err = $('#row-err');
    const base = r || e.rows.find(x => x.pid === after);
    $('#row-title').textContent = r ? 'עריכת שורה' : 'שורה חדשה';
    label.value = r ? r.label : '';
    kind.value = base ? (r ? base.kind : base.kind === 'section' || base.kind === 'subsection' ? 'item' : base.kind) : 'item';
    indent.checked = !!(base && base.indent);
    $('#row-tools').hidden = !r;
    err.hidden = true;
    const preview = () => { $('#row-preview').innerHTML = labelHtml(label.value) || '&nbsp;'; };
    label.oninput = preview;
    preview();
    const run = async (fn, close = true) => {
        err.hidden = true;
        try {
            await fn();
            if (close) dlg.close();
            await rerender(e.pid);
        } catch (x) {
            if (x.cancelled) return;
            err.textContent = x.message;
            err.hidden = false;
        }
    };
    form.onsubmit = ev => {
        ev.preventDefault();
        run(() => edit('row-save', { pid: e.pid, row: r?.pid || '', after, kind: kind.value, indent: indent.checked ? 1 : 0, label: label.value }));
    };
    dlg.querySelectorAll('[data-act]').forEach(b => b.onclick = async () => {
        const act = b.dataset.act;
        if (act === 'add') return openRow(e, null, r.pid);
        if (act === 'del') {
            if (!await ask('למחוק את השורה מהמחירון הזה?', '🗑️ מחיקה')) return;
            return run(() => edit('row-delete', { pid: e.pid, row: r.pid }));
        }
        run(() => edit('row-move', { pid: e.pid, row: r.pid, dir: act }), false);   // הזזה — החלון נשאר פתוח
    });
    $('[data-close]', dlg).onclick = () => dlg.close();
    if (!dlg.open) dlg.showModal();
    label.focus();
}

/* ===== 📜 שינויים ===== */
function histText(h) {
    const d = h.details;
    switch (h.action) {
        case 'price': return `${plain(d.label)} · ${AUD[d.audience] || d.audience} · ${num(d.old)} → ${num(d.new)}`;
        case 'row-add': return 'נוספה שורה: ' + plain(d.label);
        case 'row-edit': return 'שורה תוקנה: ' + plain(d.old.label) + (d.old.label !== d.new.label ? ' → ' + plain(d.new.label) : '');
        case 'row-delete': return 'נמחקה שורה: ' + plain(d.label) + (d.prices ? ' (' + Object.entries(d.prices).map(([a, v]) => AUD[a] + ' ' + num(v)).join(', ') + ')' : '');
        case 'row-move': return 'שורה הוזזה ' + (d.dir === 'up' ? 'למעלה' : 'למטה') + ': ' + plain(d.label);
        case 'signatures': return 'החתימות עודכנו';
        case 'edition-create': return 'המחירון נפתח על בסיס ' + dmy(d.base) + (Object.keys(d.raise || {}).length ? ', עם העלאה באחוז' : '');
        case 'edition-update': return 'התאריך שונה: ' + dmy(d.old) + ' → ' + dmy(d.new);
        default: return h.action;
    }
}

async function showHistory(pid) {
    const dlg = $('#hist-dlg'), body = $('#hist-body');
    body.innerHTML = '<p class="empty">טוען…</p>';
    dlg.showModal();
    try {
        const { history } = await api('history', { params: { pid } });
        body.innerHTML = history.length ? `<ul class="list audit">${history.map(h => `<li class="card"><div class="body">
            <div class="l1 wrap"><span>${esc(histText(h))}</span></div>
            <div class="l2">${esc(h.user)}<i class="sep" aria-hidden="true">·</i>${esc(when(h.at))}</div></div></li>`).join('')}</ul>`
            : '<p class="empty">אין עדיין שינויים במחירון הזה</p>';
    } catch (err) {
        body.innerHTML = `<p class="error">${esc(err.message)}</p>`;
    }
}

/* ===== ➕ מחירון חדש ===== */
const todayIso = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jerusalem' });

async function showNew() {
    head('מחירון חדש', '', true);
    const { editions } = await api('editions');
    if (!editions.length) { view.innerHTML = '<p class="empty">אין מחירון שאפשר לבנות עליו</p>'; return; }
    view.innerHTML = `<form id="new-form" novalidate>
      <div class="fields two">
        <label class="f"><span>בתוקף החל מ-</span><input type="date" name="date" required value="${todayIso()}"></label>
        <label class="f"><span>על בסיס</span><select name="base">${editions.map(e =>
            `<option value="${esc(e.pid)}">${esc(dmy(e.effective_from))}</option>`).join('')}</select></label>
      </div>
      <h2 class="sec-title">העלאה באחוז</h2>
      <label class="switch"><input type="checkbox" name="split"><span>אחוז שונה לתושבי ישראל ולתושבי חו"ל</span></label>
      <div id="raise"><p class="empty">טוען…</p></div>
      <p class="hint">מחיר חדש = המחיר הקודם + האחוז, מעוגל לשקל. מחיר ריק נשאר ריק. 0 — בלי שינוי.</p>
      <p class="error" id="new-err" hidden></p>
      <div class="row-btns"><button class="btn primary">➕ יצירת המחירון</button></div>
    </form>`;
    const form = $('#new-form'), box = $('#raise');
    let lines = [], vals = {};
    const read = () => box.querySelectorAll('input').forEach(i => { (vals[i.dataset.key] ??= {})[i.dataset.aud] = i.value; });
    const draw = () => {
        const split = form.split.checked;
        const field = (key, aud, lbl) => `<label class="pct"><span class="sr">${esc(lbl)}</span>${split ? `<small>${aud === 'il' ? 'ישראל' : 'חו"ל'}</small>` : ''}
            <input inputmode="decimal" autocomplete="off" data-key="${esc(key)}" data-aud="${aud}" value="${esc(vals[key]?.[aud] ?? vals[key]?.il ?? '0')}"><span>%</span></label>`;
        box.innerHTML = `<ul class="list raise">${lines.map(([key, lbl]) => `<li class="raise-row"><span class="lbl">${esc(lbl)}</span>
            ${split ? field(key, 'il', lbl) + field(key, 'abroad', lbl) : field(key, 'il', lbl)}</li>`).join('')}</ul>`;
    };
    const load = async () => {
        const b = await api('edition', { params: { pid: form.base.value } });
        lines = [...b.rows.filter(r => r.kind === 'section').map(r => [r.pid, plain(r.label).replace(/:$/, '')]), ['fee', 'תשלומים נלווים']];
        vals = {};
        draw();
    };
    form.base.onchange = () => load().catch(err => { box.innerHTML = `<p class="error">${esc(err.message)}</p>`; });
    form.split.onchange = () => { read(); draw(); };
    form.onsubmit = async ev => {
        ev.preventDefault();
        read();
        const pct = v => { const s = String(v ?? '').trim().replace(',', '.'); return s === '' ? 0 : isFinite(s) ? Number(s) : s; };
        const raise = Object.fromEntries(lines.map(([key]) => {
            const il = pct(vals[key]?.il);
            return [key, { il, abroad: form.split.checked ? pct(vals[key]?.abroad) : il }];
        }));
        const err = $('#new-err');
        err.hidden = true;
        try {
            const r = await api('edition-create', { body: { effective_from: form.date.value, base_pid: form.base.value, raise } });
            editPid = r.pid;
            toast('המחירון נפתח');
            location.hash = '#/e/' + r.pid;
        } catch (x) {
            err.textContent = x.message;
            err.hidden = false;
        }
    };
    await load();
}

/* ===== מסך המפתח ===== */
const DEV_TABS = [['tasks', '📋 מטלות'], ['log', '📜 יומן'], ['audit', '🗂️ פעולות'], ['health', '🩺 תקינות']];
const ACTIONS = { login: 'כניסה', print: 'הדפסה', price: 'שינוי מחיר', 'row-add': 'שורה נוספה', 'row-edit': 'שורה תוקנה',
    'row-delete': 'שורה נמחקה', 'row-move': 'שורה הוזזה', signatures: 'חתימות', 'edition-create': 'מחירון נפתח',
    'edition-update': 'תאריך שונה', 'edition-delete': 'מחירון נמחק' };
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
            <div class="l1"><span>${esc(ACTIONS[a.action] || a.action)}${a.edition || a.details.effective_from ? ' · ' + esc(dmy(a.edition || a.details.effective_from)) : ''}${a.details.mode ? ' · ' + esc(MODES[a.details.mode] || a.details.mode) : ''}</span>
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
    document.querySelectorAll('dialog[open]').forEach(d => d.close());
    const [, a = '', b = '', c = ''] = location.hash.split('/');
    try {
        if (a === 'e' && b) await showEdition(b);
        else if (a === 'new') await showNew();
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
