// ניהול פרויקטים — הליבה המשותפת: שרת, מצב, עזרים. נטען ראשון.
// הזהות מהפלטפורמה; אסימון ה-CSRF מגיע מ-api.php?action=me.
'use strict';

// ---- שגיאות בדפדפן נשלחות ליומן (הגדרות ← יומן) ----
(() => {
    let sent = 0;
    const report = (kind, message, extra = {}) => {
        if (++sent > 10) return; // לא מציפים: עד 10 לטעינת דף
        fetch('api.php?action=client-error', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-PM': '1' },
            body: JSON.stringify({ kind, message: String(message).slice(0, 1000), page: location.pathname + location.hash, ...extra }),
        }).catch(() => {});
    };
    addEventListener('error', e => report('error', e.message, { source: e.filename, line: e.lineno, col: e.colno, stack: e.error?.stack?.slice(0, 2000) }));
    addEventListener('unhandledrejection', e => report('promise', e.reason?.message || e.reason, { stack: e.reason?.stack?.slice(0, 2000) }));
})();

// ---- מקלדת בטלפון ----
// אנדרואיד: interactive-widget=resizes-content (במטא viewport) מקצר את הדף כשהמקלדת
// נפתחת. אייפון לא מכיר את זה — לכן --vvh = הגובה הנראה בפועל (VisualViewport),
// והחלונות בגובה הזה. השדה שבפוקוס נגלל למרכז, כדי שלא יישאר מתחת למקלדת.
(() => {
    const vv = window.visualViewport;
    if (vv) {
        const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
        vv.addEventListener('resize', set);
        set();
    }
    document.addEventListener('focusin', e => {
        if (!e.target.matches('input, textarea, select')) return;
        setTimeout(() => e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }), 300);
    });
})();

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const KIND_LABEL = { client: 'לקוח', supplier: 'ספק', lawyer: 'עורך דין' };

/** מצב משותף לכל המסכים. */
const state = {
    user: null, trades: [], templates: [], labels: { stage: {}, project: {}, kind: {} },
    contactsByKind: {},   // מטמון לבחירת אנשי קשר בחלונות (לקוח, עו"ד, ספק)
};

const isDev = () => state.user?.role === 'developer';

// ---- שרת ----

async function api(action, { params = {}, body = null } = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body) {
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-CSRF'] = state.user?.csrf || '';
        opts.body = JSON.stringify(body);
    }
    const res = await fetch('api.php?' + qs, opts);
    let data = {};
    try { data = await res.json(); } catch { /* תשובה שאינה JSON */ }
    if (res.status === 401) { location.href = 'login.html'; throw new Error(data.error || 'נדרשת כניסה'); }
    if (!res.ok) throw new Error(data.error || `תקלה (${res.status})`);
    // מיילים אוטומטיים שנשלחו בפעולה הזו (שלב 11)
    if (data.notified?.length) setTimeout(() => toast('✉️ נשלח מייל ל' + data.notified.map(n => n.name).join(', ')), 1800);
    return data;
}

/** אנשי קשר מסוג מסוים, לבחירה בחלונות. נטען פעם אחת; invalidate אחרי שמירה. */
async function contactsOf(kind) {
    if (!state.contactsByKind[kind]) {
        state.contactsByKind[kind] = (await api('contacts', { params: { kind } })).contacts;
    }
    return state.contactsByKind[kind];
}
function invalidateContacts(kind) { delete state.contactsByKind[kind]; }

// ---- תצוגה ----

function toast(msg, isErr = false) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('err', isErr);
    t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { t.hidden = true; }, 3000);
}

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const digits = s => String(s || '').replace(/[^\d+]/g, '');
// וואטסאפ דורש מספר בינלאומי: 050… → 97250…
const waNumber = s => { const d = digits(s).replace(/^\+/, ''); return d.startsWith('0') ? '972' + d.slice(1) : d; };
const money = n => '₪' + Number(n || 0).toLocaleString('he-IL');

/** זמן יחסי כמו בצ'אט: "עכשיו", "14:05", "אתמול", "12.10". */
function when(iso) {
    if (!iso) return '';
    const d = new Date(iso), now = new Date();
    const sameDay = (a, b) => a.toDateString() === b.toDateString();
    if (now - d < 60_000) return 'עכשיו';
    if (sameDay(d, now)) return d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (sameDay(d, y)) return 'אתמול';
    return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: d.getFullYear() === now.getFullYear() ? undefined : '2-digit' });
}
const dateLabel = iso => new Date(iso).toLocaleDateString('he-IL', { weekday: 'long', day: 'numeric', month: 'long' });
const shortDate = ymd => ymd ? ymd.split('-').reverse().slice(0, 2).join('.') : '';

/** עיגול עם ראשי תיבות וצבע קבוע לפי השם — כמו תמונת פרופיל בצ'אט. */
function avatar(name) {
    const words = String(name || '?').trim().split(/\s+/);
    const initials = (words[0]?.[0] || '?') + (words[1]?.[0] || '');
    let h = 0;
    for (const ch of String(name)) h = (h * 31 + ch.charCodeAt(0)) % 360;
    return `<span class="avatar" style="--h:${h}" aria-hidden="true">${esc(initials)}</span>`;
}

/** תג סטטוס של שלב. */
function stageChip(status) {
    return `<span class="chip st-${esc(status)}">${esc(state.labels.stage[status] || status)}</span>`;
}

function options(list, value, empty) {
    return (empty !== undefined ? `<option value="">${esc(empty)}</option>` : '')
        + list.map(o => `<option value="${esc(o.value)}"${String(o.value) === String(value ?? '') ? ' selected' : ''}>${esc(o.label)}</option>`).join('');
}

// ---- חלונות ----

function formError(form, msg) {
    const el = $('.error', form);
    el.textContent = msg;
    el.hidden = !msg;
    if (msg) el.scrollIntoView({ block: 'nearest' });
}

/** סגירה בכפתור ✕ בכל החלונות. */
document.addEventListener('click', e => {
    const b = e.target.closest('[data-close]');
    if (b) b.closest('dialog').close();
});

/** כפתור שמבצע פעולה: נעול בזמן הבקשה, ומציג שגיאה בטופס או בהודעה. */
async function busy(btn, form, fn) {
    if (btn) btn.disabled = true;
    try { await fn(); }
    catch (e) { form ? formError(form, e.message) : toast(e.message, true); }
    finally { if (btn) btn.disabled = false; }
}

/**
 * חלון שליחה — הודעה מוכנה לאיש קשר: וואטסאפ, SMS, אימייל או העתקה.
 * recipients: [{ contact, label, text }] — כשיש כמה (לקוח ועו"ד), בוחרים למעלה.
 */
const Send = (() => {
    let list = [], cur = null, subject = '', bound = false, meta = {};

    function bind() {
        if (bound) return;
        bound = true;
        $('#send-text').addEventListener('input', () => { cur.text = $('#send-text').value; links(); });
        $('#send-copy').addEventListener('click', async () => {
            try { await navigator.clipboard.writeText($('#send-text').value); toast('הועתק'); }
            catch { $('#send-text').select(); }
        });
        $('#send-who').addEventListener('change', e => { pick(Number(e.target.value)); });
        // מה נפתח — נרשם בהיסטוריית ההודעות של הפרויקט (לא ודאי שנשלח)
        for (const [id, channel] of [['#send-wa', 'whatsapp'], ['#send-sms', 'sms'], ['#send-mail', 'email'], ['#send-copy', 'copy']]) {
            $(id).addEventListener('click', () => {
                api('message-log', { body: { project_id: meta.projectId, contact_id: cur?.contact?.id || null, channel, key: meta.key, subject, text: cur?.text || '' } }).catch(() => {});
            });
        }
    }

    /** mailed — שמות שכבר קיבלו מייל אוטומטי בפעולה שפתחה את החלון. */
    function open({ title, recipients, subject: subj = '', hint = '', key = '', projectId = null, mailed = [] }) {
        bind();
        list = recipients.length ? recipients : [{ contact: null, label: '', text: '' }];
        subject = subj;
        meta = { key, projectId };
        $('#send-dlg-title').textContent = title;
        // מה שכבר יצא במייל אוטומטי בפעולה הזו
        const full = (mailed.length ? `✉️ כבר נשלח מייל ל${mailed.join(', ')}. ` : '') + hint;
        $('#send-hint').textContent = full;
        $('#send-hint').hidden = !full;
        $('#send-who').hidden = list.length < 2;
        $('#send-who').innerHTML = list.map((r, i) => `<label><input type="radio" name="send-who" value="${i}"${i ? '' : ' checked'}><span>${esc(r.label)}</span></label>`).join('');
        pick(0);
        $('#send-dlg').showModal();
    }

    function pick(i) {
        cur = list[i];
        const c = cur.contact;
        $('#send-to').textContent = c ? `אל: ${c.name}${c.phone ? ' · ' + c.phone : ''}${c.email ? ' · ' + c.email : ''}` : 'אין למי לשלוח בפרויקט — אפשר להעתיק.';
        $('#send-text').value = cur.text;
        links();
    }

    function links() {
        const c = cur.contact, text = cur.text;
        const phone = c?.whatsapp || c?.phone || '';
        $('#send-wa').href = `https://wa.me/${phone ? waNumber(phone) : ''}?text=${encodeURIComponent(text)}`;
        $('#send-sms').hidden = !c?.phone;
        $('#send-sms').href = `sms:${digits(c?.phone || '')}?&body=${encodeURIComponent(text)}`;
        $('#send-mail').hidden = !c?.email;
        $('#send-mail').href = `mailto:${encodeURIComponent(c?.email || '')}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(text)}`;
    }

    return { open };
})();

/**
 * הודעה מנוסח (הגדרות ← הודעות): renderMsg('quotes_sent', { שם, פרויקט, שלב, … }).
 * אותו נוסח שהשרת שולח במייל האוטומטי. {מנהל} — שם המשתמש.
 */
function renderMsg(key, vars) {
    const t = (state.msgTemplates || []).find(x => x.key === key);
    if (!t) return '';
    const all = { 'מנהל': state.user.display_name, 'כתובת': '', 'פרטים': '', 'סכום': '', 'שלב': '', 'קישור': '', ...vars };
    return t.body.replace(/\{([^}]+)\}/g, (m, k) => (k in all ? String(all[k]) : m)).trim();
}

/** הקישור האישי לפורטל של איש קשר בפרויקט — נוצר אם עוד אין. '' כשאי אפשר. */
async function portalUrl(projectId, contactId) {
    if (!contactId) return '';
    try { return (await api('portal-link', { body: { project_id: projectId, contact_id: contactId, do: 'get' } })).link?.url || ''; }
    catch { return ''; }
}
