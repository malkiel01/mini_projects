/*
 * הפורטל לנמען (אפיון 07 §5). קוד עצמאי: אינו טוען דבר מהמערכת, ואין בו קישור אליה.
 * האסימון נקרא פעם אחת מה-# של הכתובת, נשמר במשתנה בלבד (לא ב-storage), והכתובת מנוקה מיד — כדי שלא יישאר בהיסטוריה,
 * בצילום מסך או בדיווח שגיאה. הוא נשלח רק בגוף הבקשות (ובהעלאה — בכותרת), לעולם לא בכתובת.
 * כל טקסט שהגיע מהשרת — שם המשרד, נוסח הבקשה, שם קובץ, סיבת החזרה — נכנס לדף דרך textContent בלבד.
 */
'use strict';

(() => {
    const TOKEN = /^#[A-Za-z0-9_-]{43}$/.test(location.hash) ? location.hash.slice(1) : '';
    try { history.replaceState(null, '', location.pathname); } catch { /* נשאר כמו שהוא */ }
    // ה-API יושב בתיקיית האפליקציה, רמה אחת מעל הדף — הכתובת נגזרת מהנתיב של הדף עצמו
    const API = location.pathname.replace(/portal\/(index\.php)?$/, '') + 'api.php?action=';
    const main = document.getElementById('main');
    const STATES = { waiting: 'ממתין למסמך', checking: 'התקבל — בבדיקה', fix: 'נדרשת השלמה', done: 'התקבל' };
    const INVALID = 'הקישור אינו בתוקף. לקבלת קישור חדש, פנו למשרד ששלח אותו.';
    let data = null, busy = false;

    /** אלמנט: el('p', {class: 'x'}, 'טקסט' | צומת | מערך). טקסט נכנס תמיד כטקסט. */
    function el(tag, attrs, ...kids) {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v === false || v === null || v === undefined) continue;
            if (k === 'on') for (const [ev, fn] of Object.entries(v)) n.addEventListener(ev, fn);
            else if (k === 'hidden' || k === 'disabled' || k === 'multiple') n[k] = !!v;
            else n.setAttribute(k, v === true ? '' : String(v));
        }
        for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) n.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
        return n;
    }
    function toast(msg, err) {
        const t = document.querySelector('.toast');
        t.textContent = msg;
        t.classList.toggle('err', !!err);
        t.hidden = false;
        clearTimeout(toast.t);
        toast.t = setTimeout(() => { t.hidden = true; }, err ? 6000 : 3000);
    }
    const fail = (message, code, status) => Object.assign(new Error(message), { code, status });

    async function call(action, body, raw) {
        let res;
        try {
            res = await fetch(API + action, { method: 'POST', credentials: 'same-origin', referrerPolicy: 'no-referrer', cache: 'no-store',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: TOKEN, ...body }) });
        } catch { throw fail('אין חיבור לרשת. נסו שוב.', 'network', 0); }
        if (raw && res.ok) return res.blob();
        const j = await res.json().catch(() => ({}));
        if (!res.ok) throw fail(j.error?.message || 'תקלה. נסו שוב מאוחר יותר.', j.error?.code || '', res.status);
        return j;
    }
    async function upload(item, file) {
        const qs = new URLSearchParams({ requirement: item.id, name: file.name, key: 'k' + crypto.getRandomValues(new Uint32Array(3)).reduce((s, n) => s + n.toString(36), '') });
        let res;
        try {
            res = await fetch(API + 'portal-upload&' + qs, { method: 'POST', credentials: 'same-origin', referrerPolicy: 'no-referrer', cache: 'no-store',
                headers: { 'Content-Type': 'application/octet-stream', 'X-Portal-Token': TOKEN }, body: file });
        } catch { throw fail('אין חיבור לרשת. נסו שוב.', 'network', 0); }
        const j = await res.json().catch(() => ({}));
        if (!res.ok) throw fail(j.error?.message || 'ההעלאה נכשלה. נסו שוב.', j.error?.code || '', res.status);
        return j;
    }

    function notice(icon, title, text) {
        main.replaceChildren(el('div', { class: 'notice' }, el('div', { class: 'big', 'aria-hidden': 'true' }, icon), el('h1', null, title), text ? el('p', null, text) : null));
    }
    /** שגיאה של פעולה: קישור שאינו בתוקף או בקשה שנסגרה מחליפים את כל הדף; השאר — הודעה. */
    function onError(x) {
        if (x.code === 'portal_invalid') return notice('🔗', INVALID, '');
        if (x.code === 'portal_closed') return notice('✅', 'הבקשה נסגרה. תודה.', '');
        if (x.code === 'portal_otp_required') return otpScreen(true);
        toast(x.message, true);
    }
    const fmtDate = iso => { try { return new Date(iso).toLocaleDateString('he-IL', { timeZone: 'Asia/Jerusalem' }); } catch { return ''; } };
    const fmtSize = n => n < 1048576 ? Math.max(1, Math.round(n / 1024)) + ' KB' : (n / 1048576).toFixed(1) + ' MB';

    async function load() {
        if (!TOKEN) return notice('🔗', INVALID, '');
        try { data = await call('portal-open', {}); } catch (x) { return x.code === 'portal_invalid' ? notice('🔗', INVALID, '') : notice('⚠️', x.message, ''); }
        if (data.state === 'closed') return notice('✅', 'הבקשה נסגרה. תודה.', '');
        if (data.state === 'otp') return otpScreen(true);
        draw();
    }

    function draw() {
        const d = data;
        document.title = 'העלאת מסמכים — ' + d.office;
        main.replaceChildren(
            el('div', { class: 'head' }, el('h1', null, d.office), d.sender ? el('p', { class: 'muted' }, 'הבקשה נשלחה בידי ' + d.sender) : null),
            el('div', { class: 'intro', dir: 'auto' }, d.intro),
            d.items.length ? null : el('p', { class: 'muted' }, 'אין כרגע מסמכים שמחכים לכם.'),
            ...d.items.map(itemCard),
            (d.can.view || d.can.download) && d.otp.available && !d.otp.verified
                ? el('div', { class: 'foot' }, el('button', { class: 'btn', type: 'button', on: { click: () => otpScreen(false) } }, 'לצפייה בקבצים שהעליתי'))
                : null,
            el('p', { class: 'muted foot' }, 'הקישור בתוקף עד ' + fmtDate(d.expires_at) + '. הוא אישי — אין להעביר אותו לאחרים.'));
    }

    function itemCard(it) {
        const card = el('section', { class: 'item' });
        const open = it.state !== 'done';
        card.append(el('div', { class: 'top' }, el('h2', { dir: 'auto' }, it.title), el('span', { class: 'chip ' + it.state }, STATES[it.state] || '')));
        if (it.explain) card.append(el('p', { class: 'explain', dir: 'auto' }, it.explain));
        if (it.due_at) card.append(el('p', { class: 'muted' }, 'עד ' + fmtDate(it.due_at)));
        if (it.state === 'fix' && it.reason) card.append(el('p', { class: 'reason', dir: 'auto' }, 'מה חסר: ' + it.reason));
        if (it.state === 'checking') card.append(el('p', { class: 'note' }, 'הקובץ התקבל. המשרד יבדוק אותו. (זהו אישור קבלה בלבד — לא אישור של המסמך.)'));
        if (it.files) {
            card.append(el('ul', { class: 'files' }, it.files.map(f => el('li', null, el('span', { class: 'name', dir: 'auto' }, f.name + ' · ' + fmtSize(f.size)),
                el('span', null, data.can.view && f.preview ? el('button', { class: 'btn', type: 'button', on: { click: () => viewFile(f) } }, 'צפייה') : null,
                    data.can.download ? el('button', { class: 'btn', type: 'button', on: { click: () => saveFile(f) } }, 'הורדה') : null)))));
        } else if (it.files_count) card.append(el('p', { class: 'muted' }, it.files_count === 1 ? 'קובץ אחד התקבל.' : it.files_count + ' קבצים התקבלו.'));
        if (it.no_document) card.append(el('p', { class: 'muted' }, 'הודעתם שהמסמך אינו ברשותכם. המשרד יחזור אליכם.'));
        const form = el('div', { class: 'form', hidden: true });
        const btns = el('div', { class: 'btns' });
        if (open && data.can.upload) {
            const input = el('input', { type: 'file', multiple: true, hidden: true, accept: '.pdf,.jpg,.jpeg,.png,.webp,.heic,.docx,.xlsx,.zip,image/*,application/pdf',
                on: { change: () => sendFiles(it, [...input.files], input) } });
            btns.append(input, el('button', { class: 'btn primary', type: 'button', on: { click: () => input.click() } }, it.state === 'fix' ? 'העלאת קובץ מתוקן' : 'העלאת קובץ'));
        }
        if (open && data.can.reply) {
            btns.append(el('button', { class: 'btn', type: 'button', on: { click: () => replyForm(it, form, 'no_document') } }, 'אין ברשותי את המסמך'),
                el('button', { class: 'btn', type: 'button', on: { click: () => replyForm(it, form, 'comment') } }, 'הערה'));
        }
        if (btns.children.length) card.append(btns, form);
        return card;
    }

    async function sendFiles(it, files, input) {
        if (busy || !files.length) return;
        busy = true;
        let ok = 0;
        for (const f of files) {
            if (f.size > data.limits.max_size) { toast(`"${f.name}" גדול מדי — עד ${Math.floor(data.limits.max_size / 1048576)}MB`, true); continue; }
            toast(`מעלה: ${f.name}…`);
            try { await upload(it, f); ok++; } catch (x) { busy = false; if (['portal_invalid', 'portal_closed', 'portal_otp_required'].includes(x.code)) return onError(x); toast(x.message, true); busy = true; }
        }
        busy = false;
        input.value = '';
        if (ok) toast(ok === 1 ? 'הקובץ התקבל. המשרד יבדוק אותו.' : ok + ' קבצים התקבלו. המשרד יבדוק אותם.');
        await refresh();
    }

    function replyForm(it, form, kind) {
        const ta = el('textarea', { rows: 3, maxlength: 1000, id: 't-' + it.id });
        const send = el('button', { class: 'btn primary', type: 'button', on: { click: async () => {
            if (kind === 'comment' && !ta.value.trim()) { ta.focus(); return; }
            send.disabled = true;
            try {
                await call('portal-respond', { requirement: it.id, kind, text: ta.value });
                toast(kind === 'comment' ? 'ההערה נשלחה למשרד.' : 'ההודעה נשלחה למשרד.');
                await refresh();
            } catch (x) { send.disabled = false; onError(x); }
        } } }, 'שליחה');
        form.replaceChildren(el('label', { for: 't-' + it.id }, kind === 'comment' ? 'הערה למשרד' : 'אפשר להסביר (לא חובה): למה המסמך אינו ברשותכם, ומי יכול לספק אותו'), ta,
            el('div', { class: 'btns' }, send, el('button', { class: 'btn', type: 'button', on: { click: () => { form.hidden = true; } } }, 'ביטול')));
        form.hidden = false;
        ta.focus();
    }

    async function refresh() {
        try { data = await call('portal-open', {}); } catch (x) { return onError(x); }
        if (data.state === 'closed') return notice('✅', 'הבקשה נסגרה. תודה.', '');
        if (data.state === 'otp') return otpScreen(true);
        draw();
    }

    /** קוד חד-פעמי: נשלח למייל של הנמען; אחרי אימות המכשיר נזכר 30 יום. $must — בלי קוד אין פורטל ("תמיד לדרוש קוד"). */
    function otpScreen(must) {
        const input = el('input', { class: 'code', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6, id: 'code', 'aria-label': 'קוד בן 6 ספרות' });
        const sendBtn = el('button', { class: 'btn primary', type: 'button' }, 'שליחת קוד למייל שלי');
        const verify = el('button', { class: 'btn primary', type: 'button', hidden: true }, 'אימות');
        const step = el('div', { class: 'form', hidden: true }, el('label', { for: 'code' }, 'הקוד שקיבלתם במייל (תקף ל-10 דקות)'), input);
        sendBtn.addEventListener('click', async () => {
            sendBtn.disabled = true;
            try { await call('portal-otp-send', {}); step.hidden = false; verify.hidden = false; sendBtn.textContent = 'שליחת קוד חדש'; toast('הקוד נשלח למייל שלכם.'); input.focus(); } catch (x) { onError(x); }
            setTimeout(() => { sendBtn.disabled = false; }, 20000);
        });
        verify.addEventListener('click', async () => {
            if (!/^\d{6}$/.test(input.value.trim())) { input.focus(); return; }
            verify.disabled = true;
            try { await call('portal-otp-verify', { code: input.value.trim() }); toast('המכשיר אומת.'); await refresh(); } catch (x) { verify.disabled = false; input.value = ''; onError(x); }
        });
        main.replaceChildren(el('div', { class: 'item' }, el('h2', null, 'אימות בקוד חד-פעמי'),
            el('p', { class: 'explain' }, must ? 'כדי להמשיך נשלח קוד בן 6 ספרות לכתובת המייל שלכם, כפי שהיא רשומה במשרד.' : 'כדי לצפות בקבצים שהעליתם נשלח קוד בן 6 ספרות לכתובת המייל שלכם, כפי שהיא רשומה במשרד.'),
            step, el('div', { class: 'btns' }, verify, sendBtn, must ? null : el('button', { class: 'btn', type: 'button', on: { click: draw } }, 'חזרה'))));
    }

    async function viewFile(f) {
        let blob;
        try { blob = await call('portal-file', { file: f.id, inline: true }, true); } catch (x) { return onError(x); }
        const url = URL.createObjectURL(blob);
        const close = () => { box.remove(); URL.revokeObjectURL(url); };
        const box = el('div', { class: 'viewer', role: 'dialog', 'aria-label': f.name }, el('div', { class: 'bar' }, el('span', { dir: 'auto' }, f.name), el('button', { class: 'btn', type: 'button', on: { click: close } }, 'סגירה')),
            blob.type.startsWith('image/') ? el('img', { src: url, alt: f.name }) : el('iframe', { src: url, title: f.name, sandbox: '' }));
        document.body.append(box);
    }
    async function saveFile(f) {
        let blob;
        try { blob = await call('portal-file', { file: f.id, inline: false }, true); } catch (x) { return onError(x); }
        const url = URL.createObjectURL(blob), a = el('a', { href: url, download: f.name });
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 30000);
    }

    // שגיאות דפדפן — נרשמות בשרת בלי האסימון ובלי כתובת הדף
    let reported = 0;
    const report = (kind, message, source, line, col) => {
        if (reported++ >= 3) return;
        const clean = s => String(s || '').split(TOKEN || '\u0000').join('[…]').slice(0, 300);
        fetch(API + 'portal-client-error', { method: 'POST', referrerPolicy: 'no-referrer', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ kind, message: clean(message), source: clean(source).split('?')[0].split('#')[0], line: line || 0, col: col || 0 }) }).catch(() => {});
    };
    addEventListener('error', e => report('error', e.message, e.filename, e.lineno, e.colno));
    addEventListener('unhandledrejection', e => report('promise', e.reason && e.reason.message, '', 0, 0));

    load();
})();
