/*
 * שמאות מקרקעין — הדפדפן. דף אחד, ניווט ב-#.
 * הזהות מגיעה מהשער של הפלטפורמה; כאן אין כניסה ואין יציאה. כל הרשאה נאכפת בשרת — כאן רק מסתירים מה שלא רלוונטי.
 *
 *   #/                    המסך הראשי
 *   #/team · #/team/log   הצוות של המשרד שלי · יומן הפעולות של המשרד (מנהל משרד)
 *   #/offices             משרדים (מנהל הפלטפורמה)
 *   #/dev/tasks[/קוד] · #/dev/log[/בקשה] · #/dev/audit · #/dev/health    מסך המפתח
 */
'use strict';

const $ = (sel, root = document) => root.querySelector(sel);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const main = $('#main');
let me = null;

// ── API ──────────────────────────────────────────────────────
async function api(action, { body, params } = {}) {
    const qs = new URLSearchParams({ action, ...(params || {}) });
    const init = { credentials: 'same-origin' };
    if (body !== undefined) {
        init.method = 'POST';
        init.headers = { 'Content-Type': 'application/json', 'X-CSRF': me?.csrf || '' };
        init.body = JSON.stringify(body);
    }
    let res;
    try {
        res = await fetch('api.php?' + qs, init);
    } catch {
        DevLoop.noteRequest({ action, method: init.method || 'GET', status: 0, id: '' });
        throw new Error('אין חיבור לשרת');
    }
    const id = res.headers.get('X-Request-Id') || '';
    DevLoop.noteRequest({ action, method: init.method || 'GET', status: res.status, id });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        // תקלה בשרת — עם מזהה הבקשה, כדי למצוא אותה ביומן
        const e = new Error((data.error || `תקלה (${res.status})`) + (res.status >= 500 && id ? ` · בקשה ${id}` : ''));
        e.status = res.status;
        throw e;
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

// ── עזרים ────────────────────────────────────────────────────
const initials = name => String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('');
const hue = s => [...String(s)].reduce((h, c) => (h * 31 + c.codePointAt(0)) % 360, 7);
const roleName = r => me?.roles?.[r] || r;
const dayKey = iso => new Date(iso).toLocaleDateString('he-IL', { weekday: 'long', day: 'numeric', month: 'long' });
const clock = iso => new Date(iso).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
const ago = iso => {
    if (!iso) return 'עוד לא נכנס';
    const d = Math.floor((Date.now() - new Date(iso)) / 86400000);
    return d <= 0 ? 'נכנס היום' : d === 1 ? 'נכנס אתמול' : `נכנס לפני ${d} ימים`;
};

function setHead(title, sub = '') {
    $('#title').textContent = title;
    $('#sub').textContent = sub;
    $('#sub').hidden = !sub;
    document.title = title === 'שמאות מקרקעין' ? title : `${title} · שמאות מקרקעין`;
}

/** גיליון עריכה. onSave מקבל את ערכי הטופס; מחזיר הבטחה — הגיליון נסגר כשהיא מצליחה. */
function sheet(title, html, onSave, saveLabel = 'שמירה') {
    const dlg = $('#sheet');
    $('#sheet-title').textContent = title;
    $('#sheet-body').innerHTML = html + '<p class="error" role="alert" hidden></p>';
    const save = $('#sheet-save');
    save.hidden = !onSave;
    save.textContent = saveLabel;
    save.disabled = false;
    dlg.querySelector('form').onsubmit = async e => {
        e.preventDefault();
        if (!onSave) return;
        const err = $('.error', dlg);
        err.hidden = true;
        save.disabled = true;
        try {
            await onSave(Object.fromEntries(new FormData(e.target)), dlg);
            dlg.close();
        } catch (x) {
            err.textContent = x.message;
            err.hidden = false;
            save.disabled = false;
        }
    };
    dlg.showModal();
    return dlg;
}

const roleField = (value = 'appraiser') => `<fieldset class="seg grid" style="--cols:2" aria-label="תפקיד">${Object.entries(me.roles).map(([k, v]) =>
    `<label><input type="radio" name="role" value="${esc(k)}"${k === value ? ' checked' : ''}><span>${esc(v)}</span></label>`).join('')}</fieldset>`;

// ── המסך הראשי ───────────────────────────────────────────────
function viewHome() {
    setHead('שמאות מקרקעין', me.office ? me.office.name : '');
    const tiles = [];
    if (me.office) tiles.push(['#/team', '👥', 'הצוות']);
    if (me.user.is_platform_manager) tiles.push(['#/offices', '🏢', 'משרדים']);
    if (me.user.is_developer) tiles.push(['#/dev', '🛠️', 'מסך המפתח']);
    const tilesHtml = tiles.length ? `<div class="tiles">${tiles.map(([h, i, t]) =>
        `<a class="tile" href="${h}"><span class="tile-ic" aria-hidden="true">${i}</span>${t}</a>`).join('')}</div>` : '';

    if (me.office) {
        main.innerHTML = `<div class="summary"><div class="line"><b class="one">${esc(me.office.name)}</b><span class="chip info">${esc(roleName(me.office.role))}</span></div></div>
            <p class="empty">עוד אין תיקים. פתיחת תיק וזיהוי הנכס — בשלב הבא של הפיתוח.</p>${tilesHtml}`;
    } else if (me.offices.length) {
        main.innerHTML = `<p class="hint">אתם חברים בכמה משרדים. באיזה משרד עובדים עכשיו?</p>
            <ul class="list">${me.offices.map(o => `<li><button class="use-row" type="button" data-office="${o.id}">
                <span class="use-ic" aria-hidden="true">🏛️</span><span class="use-tx"><b>${esc(o.name)}</b><small>${esc(roleName(o.role))}</small></span>
                <span class="use-go" aria-hidden="true">‹</span></button></li>`).join('')}</ul>${tilesHtml ? '<p class="sec-title">ניהול</p>' + tilesHtml : ''}`;
    } else {
        main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">🏛️</div><h2>עוד לא שויכתם למשרד</h2>
            <p class="muted">${me.user.is_platform_manager ? 'פתחו משרד במסך "משרדים", וקבעו מי מנהל אותו — אפשר גם את עצמכם.'
                : `מנהל המשרד מצרף אתכם לפי כתובת המייל${me.user.email ? ` <b dir="ltr">${esc(me.user.email)}</b>` : ''}. אחרי שצירף — רעננו את הדף.`}</p></div>
            ${tilesHtml ? '<p class="sec-title">ניהול</p>' + tilesHtml : ''}`;
    }
}

async function switchOffice(id) {
    await api('office-switch', { body: { office_id: id } });
    me = await api('me');
    drawHead();
    route();
}

// ── הצוות ────────────────────────────────────────────────────
async function viewTeam(tab) {
    setHead('הצוות', me.office?.name || '');
    const d = await api('members');
    const tabs = d.can_manage ? `<div class="seg-tabs" role="tablist">
        <button role="tab" aria-selected="${tab !== 'log'}" data-go="#/team">👥 חברים <small>${d.members.length}</small></button>
        <button role="tab" aria-selected="${tab === 'log'}" data-go="#/team/log">🧾 יומן פעולות</button></div>` : '';
    if (tab === 'log' && d.can_manage) {
        const log = await api('office-audit');
        main.innerHTML = tabs + auditHtml(log.rows, false);
        return;
    }
    main.innerHTML = `${tabs}<div class="toolbar"><p class="hint one" style="flex:1;margin:0">${d.can_manage ? 'מי עובד במשרד, ובאיזה תפקיד.' : 'מי עובד איתכם במשרד.'}</p>
        ${d.can_manage ? '<button class="btn primary" id="invite"><span aria-hidden="true">+</span> הזמנה</button>' : ''}</div>
        <ul class="list">${d.members.map(m => `<li class="card${d.can_manage ? '' : ' static'}" ${d.can_manage ? `tabindex="0" role="button" data-member="${m.id}"` : ''}>
            <span class="avatar" style="--h:${hue(m.email || m.name)}" aria-hidden="true">${esc(initials(m.name))}</span>
            <div class="body"><div class="l1"><span>${esc(m.name)}${m.me ? ' (אני)' : ''}</span></div>
            <div class="l2">${esc(roleName(m.role))}<i class="sep" aria-hidden="true">·</i>${m.pending ? esc(m.email) : esc(ago(m.last_login_at))}</div></div>
            ${m.status === 'suspended' ? '<span class="chip bad">מושעה</span>' : m.pending ? '<span class="chip warn">הוזמן</span>' : ''}</li>`).join('')}</ul>`;
    $('#invite')?.addEventListener('click', () => sheet('הזמנה למשרד', `
        <p class="hint">המוזמן נכנס דרך הכלים הדיגיטליים, עם אותה כתובת מייל — ושם הוא צריך גם הרשאה לאפליקציה הזו.</p>
        <div class="fields">
          <label class="f wide"><span>מייל *</span><input name="email" type="email" dir="ltr" inputmode="email" autocomplete="off" required maxlength="200"></label>
          <label class="f wide"><span>שם</span><input name="name" maxlength="120" autocomplete="off"></label>
        </div>
        <div class="f"><span>תפקיד</span>${roleField()}</div>`, async f => {
        await api('member-invite', { body: f });
        toast('ההזמנה נשמרה');
        viewTeam();
    }, 'הזמנה'));
    main.querySelectorAll('[data-member]').forEach(li => {
        const open = () => editMember(d.members.find(m => m.id === +li.dataset.member));
        li.addEventListener('click', open);
        li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
}

function editMember(m) {
    const dlg = sheet(m.name, `
        <p class="hint" dir="auto">${esc(m.email || '')}</p>
        <div class="f"><span>תפקיד</span>${roleField(m.role)}</div>
        <label class="switch"><input type="checkbox" name="active"${m.status !== 'suspended' ? ' checked' : ''}><span>פעיל במשרד</span></label>
        <section class="group"><button type="button" class="btn ghost danger" id="remove">🗑️ הסרה מהמשרד</button></section>`, async f => {
        await api('member-update', { body: { user_id: m.id, role: f.role, status: f.active ? 'active' : 'suspended' } });
        toast('נשמר');
        await refreshMe();
        route();
    });
    $('#remove', dlg).addEventListener('click', async () => {
        if (!confirm(`להסיר את ${m.name} מהמשרד?`)) return;
        try {
            await api('member-remove', { body: { user_id: m.id } });
            dlg.close();
            toast('הוסר מהמשרד');
            await refreshMe();
            route();
        } catch (x) { toast(x.message, true); }
    });
}

// ── יומן פעולות ──────────────────────────────────────────────
const AUDIT = {
    'user.created': ['👤', () => 'חשבון חדש נפתח'],
    'user.linked': ['🔗', () => 'החשבון קושר להזמנה, לפי המייל'],
    'office.created': ['🏛️', r => `המשרד "${r.data.name || r.office}" נפתח`],
    'office.updated': ['✏️', r => 'המשרד עודכן' + (r.data.name ? `: שם חדש "${r.data.name}"` : '') + (r.data.status ? `: ${r.data.status === 'suspended' ? 'הושעה' : 'הוחזר לפעילות'}` : '')],
    'member.added': ['➕', r => `${r.target_name || 'חבר'} צורף כ${roleName(r.data.role)}`],
    'member.updated': ['✏️', r => `${r.target_name || 'חבר'}` + (r.data.role ? ` — תפקיד: ${roleName(r.data.role)}` : '') + (r.data.status ? ` — ${r.data.status === 'suspended' ? 'הושעה' : 'הוחזר לפעילות'}` : '')],
    'member.removed': ['➖', r => `${r.target_name || 'חבר'} הוסר מהמשרד`],
    'devtasks.token_create': ['🔑', r => `נוצר אסימון ל-Claude (${r.data.label || ''})`],
    'devtasks.token_revoke': ['🔑', () => 'אסימון של Claude בוטל'],
};

/** שורות היומן לפי ימים: אייקון, מה קרה, מי ומתי. */
function auditHtml(rows, withOffice) {
    if (!rows.length) return '<p class="empty">עוד אין פעולות ביומן.</p>';
    let day = '', out = '';
    for (const r of rows) {
        const k = dayKey(r.at);
        if (k !== day) { out += `${day ? '</ul>' : ''}<p class="day">${esc(k)}</p><ul class="list">`; day = k; }
        const [icon, text] = AUDIT[r.action] || ['•', () => r.action + (r.target ? ` (${r.target})` : '')];
        out += `<li class="ev"><span class="use-ic" aria-hidden="true">${icon}</span><div class="body"><div class="what">${esc(text(r))}</div>
            <div class="who">${esc(r.actor || 'המערכת')}<i class="sep" aria-hidden="true">·</i>${esc(clock(r.at))}${
            withOffice && r.req ? `<i class="sep" aria-hidden="true">·</i><a href="#/dev/log/${esc(r.req)}" aria-label="הבקשה ביומן">#${esc(r.req)}</a>` : ''}${
            withOffice && r.office ? `<i class="sep" aria-hidden="true">·</i>${esc(r.office)}` : ''}</div></div></li>`;
    }
    return out + '</ul>';
}

// ── משרדים (מנהל הפלטפורמה) ─────────────────────────────────
async function viewOffices() {
    setHead('משרדים');
    const d = await api('offices');
    main.innerHTML = `<p class="hint">כאן פותחים משרד, קובעים מי מנהל אותו, ומשעים. התיקים של המשרד אינם נראים מכאן.</p>
        <div class="toolbar"><span style="flex:1"></span><button class="btn primary" id="add"><span aria-hidden="true">+</span> משרד</button></div>
        ${d.offices.length ? `<ul class="list">${d.offices.map(o => `<li class="card" tabindex="0" role="button" data-office-edit="${o.id}">
            <span class="use-ic" aria-hidden="true">🏛️</span>
            <div class="body"><div class="l1"><span>${esc(o.name)}</span></div>
            <div class="l2">${esc(o.admins || 'בלי מנהל')}<i class="sep" aria-hidden="true">·</i>${o.members === 1 ? 'חבר אחד' : o.members + ' חברים'}</div></div>
            ${o.status === 'suspended' ? '<span class="chip bad">מושעה</span>' : ''}</li>`).join('')}</ul>`
            : '<p class="empty">עוד אין משרדים. "+ משרד" כדי לפתוח את הראשון.</p>'}`;
    $('#add').addEventListener('click', () => sheet('משרד חדש', `
        <div class="fields">
          <label class="f wide"><span>שם המשרד *</span><input name="name" required maxlength="120" autocomplete="off"></label>
          <label class="f wide"><span>המייל של מנהל המשרד *</span><input name="admin_email" type="email" dir="ltr" inputmode="email" required maxlength="200" autocomplete="off" value="${esc(me.user.email || '')}"></label>
          <label class="f wide"><span>שם המנהל</span><input name="admin_name" maxlength="120" autocomplete="off"></label>
        </div>
        <p class="hint">המייל שלכם — אם אתם מנהלים את המשרד בעצמכם. מנהל אחר נכנס דרך הכלים הדיגיטליים, עם אותו מייל.</p>`, async f => {
        await api('office-create', { body: f });
        toast('המשרד נפתח');
        await refreshMe();
        viewOffices();
    }));
    main.querySelectorAll('[data-office-edit]').forEach(li => {
        const o = d.offices.find(x => x.id === +li.dataset.officeEdit);
        const open = () => sheet(o.name, `
            <div class="fields"><label class="f wide"><span>שם המשרד *</span><input name="name" required maxlength="120" value="${esc(o.name)}"></label></div>
            <label class="switch"><input type="checkbox" name="active"${o.status === 'active' ? ' checked' : ''}><span>פעיל</span></label>
            <p class="hint">משרד מושעה: החברים שלו לא נכנסים אליו, והנתונים נשמרים.</p>`, async f => {
            await api('office-update', { body: { id: o.id, name: f.name, status: f.active ? 'active' : 'suspended' } });
            toast('נשמר');
            await refreshMe();
            viewOffices();
        });
        li.addEventListener('click', open);
        li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
}

// ── מסך המפתח ────────────────────────────────────────────────
const DEV_TABS = [['tasks', '📋 מטלות'], ['log', '📜 יומן'], ['audit', '🧾 פעולות'], ['health', '🩺 תקינות']];
const STATE = { ok: 'תקין', warn: 'לבדיקה', bad: 'תקלה' };

async function viewDev(tab, arg) {
    if (!DEV_TABS.some(([k]) => k === tab)) tab = sessionStorage.getItem('apr-dev-tab') || 'tasks';
    sessionStorage.setItem('apr-dev-tab', tab);
    setHead('מסך המפתח', 'גרסה ' + me.version);
    main.innerHTML = `<div class="seg-tabs" role="tablist">${DEV_TABS.map(([k, t]) =>
        `<button role="tab" aria-selected="${k === tab}" data-go="#/dev/${k}">${t}</button>`).join('')}</div><div id="dev-body"></div>`;
    const body = $('#dev-body');
    if (tab === 'tasks') return DevLoop.show(body, arg || '');
    if (tab === 'log') return DevLoop.showLog(body, arg || '');
    if (tab === 'audit') {
        const d = await api('dev-audit');
        body.innerHTML = '<p class="hint">כל פעולת ניהול באפליקציה: מי, מה, מתי ובאיזה משרד.</p>' + auditHtml(d.rows, true);
        return;
    }
    const draw = async () => {
        body.innerHTML = '<p class="empty">בודק…</p>';
        const h = await api('dev-health');
        const bad = h.checks.filter(c => c.state !== 'ok').length;
        body.innerHTML = `<div class="summary"><div class="line"><span class="dot ${h.state}" aria-hidden="true"></span>
            <b>${bad ? `${bad} מתוך ${h.checks.length} דורשות בדיקה` : `כל ${h.checks.length} הבדיקות תקינות`}</b>
            <button class="btn sm ghost" id="recheck" style="margin-inline-start:auto">🔄 שוב</button></div></div>
            <ul class="list health">${h.checks.map(c => `<li class="card"><span class="dot ${c.state}" role="img" aria-label="${STATE[c.state]}"></span>
            <div class="body"><div class="l1"><span>${esc(c.label)}</span></div><div class="l2">${esc(c.detail)}</div></div>
            ${c.state !== 'ok' ? `<span class="chip ${c.state}">${STATE[c.state]}</span>` : ''}</li>`).join('')}</ul>`;
        $('#recheck').addEventListener('click', draw);
    };
    await draw();
}

// ── החשבון שלי ───────────────────────────────────────────────
function openMe() {
    const many = me.offices.length > 1;
    const dlg = sheet('החשבון שלי', `
        <div class="summary"><div class="line"><b class="one">${esc(me.user.name)}</b></div>
          <div class="line muted" dir="auto">${esc(me.user.email || '')}</div>
          <div class="chips">${me.user.is_developer ? '<span class="chip info">מפתח</span>' : ''}${me.user.is_platform_manager ? '<span class="chip info">מנהל הפלטפורמה</span>' : ''}</div></div>
        ${me.offices.length ? `<p class="sec-title">${many ? 'המשרד שעובדים בו עכשיו' : 'המשרד'}</p>
        <ul class="list">${me.offices.map(o => `<li><button class="use-row" type="button" data-office="${o.id}"${many ? '' : ' disabled'}>
            <span class="use-ic" aria-hidden="true">${me.office?.id === o.id ? '✔️' : '🏛️'}</span>
            <span class="use-tx"><b>${esc(o.name)}</b><small>${esc(roleName(o.role))}</small></span></button></li>`).join('')}</ul>` : ''}
        <p class="hint">הכניסה והיציאה — דרך הכלים הדיגיטליים. גרסה ${esc(me.version)}.</p>`, null);
    dlg.querySelectorAll('[data-office]').forEach(b => b.addEventListener('click', async () => {
        dlg.close();
        try { await switchOffice(+b.dataset.office); } catch (x) { toast(x.message, true); }
    }));
}

function drawHead() {
    const b = $('#me-btn');
    b.textContent = initials(me.user.name);
    b.hidden = false;
    $('#dev-btn').hidden = !me.user.is_developer;
}

async function refreshMe() {
    me = await api('me');
    drawHead();
}

// ── ניווט ────────────────────────────────────────────────────
async function route() {
    const [, a = '', b = '', c = ''] = location.hash.split('/').map(decodeURIComponent);
    $('#back').hidden = a === '';
    try {
        if (a === 'team' && me.office) await viewTeam(b);
        else if (a === 'offices' && me.user.is_platform_manager) await viewOffices();
        else if (a === 'dev' && me.user.is_developer) await viewDev(b, c);
        else {
            if (a !== '') { history.replaceState(null, '', '#/'); $('#back').hidden = true; }
            viewHome();
        }
    } catch (x) {
        main.innerHTML = `<p class="error" role="alert">${esc(x.message)}</p>`;
    }
    main.scrollTop = 0;
}

async function boot() {
    // --vvh: הגובה הנראה בלי המקלדת — הגיליון מתקצר והכול נגלל
    const vv = window.visualViewport;
    if (vv) {
        const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
        vv.addEventListener('resize', set);
        set();
    }
    DevLoop.setup({
        api, toast, isDev: () => !!me?.user.is_developer, appName: 'שמאות מקרקעין', tasksHref: '#/dev/tasks', mountBefore: '#me-btn',
        headers: () => ({ 'X-CSRF': me?.csrf || '' }), version: () => me?.version || '', builtinLog: true, logHref: r => '#/dev/log/' + r.req,
    });
    try {
        me = await api('me');
    } catch (x) {
        main.innerHTML = `<div class="notice"><div class="big" aria-hidden="true">🔒</div><h2>${x.status === 401 ? 'נדרשת כניסה' : 'אי אפשר להיכנס'}</h2><p class="muted">${esc(x.message)}</p></div>`;
        return;
    }
    drawHead();
    if (me.user.is_developer) DevLoop.mount();
    addEventListener('hashchange', route);
    // חזרה: למסך הקודם באפליקציה, עד הראשי — לא יוצאים מהאפליקציה
    $('#back').addEventListener('click', () => {
        const parts = location.hash.split('/').filter(Boolean);
        location.hash = parts.length > 2 ? '#/' + parts.slice(1, -1).join('/') : '#/';
    });
    $('#me-btn').addEventListener('click', openMe);
    document.addEventListener('click', e => {
        const close = e.target.closest('[data-close]');
        if (close) return close.closest('dialog').close();
        const go = e.target.closest('[data-go]');
        if (go) { location.hash = go.dataset.go; return; }
        const office = e.target.closest('main [data-office]');
        if (office) switchOffice(+office.dataset.office).catch(x => toast(x.message, true));
    });
    route();
}

boot();
