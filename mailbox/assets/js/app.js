// תיבת-מייל — ממשק הקריאה. רשימה (רענון כל 15 שניות), הודעה, צ'יפ קוד אימות, צרופות וצופה PDF,
// וניהול תיבות ושיתוף. כל טקסט שמגיע מהשרת נכנס כ-textContent; HTML של הודעה — רק בתוך
// <iframe sandbox> בלי allow-scripts, עם CSP שאוסר סקריפט (השכבה השנייה, אחרי הסינון בשרת).
'use strict';

const $ = (s, el = document) => el.querySelector(s);
const POLL_MS = 15000;
const TZ = 'Asia/Jerusalem';

const state = {
    me: null, boxes: [], box: null,
    msgs: [], known: new Set(), first: true,
    open: null, timer: 0, loading: false,
};

// ── כללי ─────────────────────────────────────────────

async function api(action, params = {}, body = null) {
    const q = new URLSearchParams({ action, ...params });
    const opt = body ? {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Mailbox': '1' },
        body: JSON.stringify({ action, ...body }),
    } : { credentials: 'same-origin' };
    let res, data;
    try {
        res = await fetch('api.php?' + q, opt);
        data = await res.json();
    } catch {
        throw new Error(res && res.status === 401 ? 'צריך להתחבר מחדש לכלים הדיגיטליים' : 'אין חיבור לשרת');
    }
    if (!data.ok) throw Object.assign(new Error(data.error || 'שגיאה'), { status: res.status });
    return data;
}

function el(tag, attrs = {}, ...kids) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null || v === false) continue;
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
        else n.setAttribute(k, v === true ? '' : v);
    }
    for (const k of kids.flat()) if (k != null) n.append(k);
    return n;
}

/** אייקון מה-sprite שב-index.html. */
function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'i'); svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + name);
    svg.append(use);
    return svg;
}

function toast(msg, err = false) {
    const t = $('.toast');
    t.textContent = msg; t.classList.toggle('err', err); t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { t.hidden = true; }, 2600);
}

const fmtTime = new Intl.DateTimeFormat('he-IL', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
const fmtDay = new Intl.DateTimeFormat('he-IL', { timeZone: TZ, day: 'numeric', month: 'short' });
const fmtDate = new Intl.DateTimeFormat('he-IL', { timeZone: TZ, day: '2-digit', month: '2-digit', year: '2-digit' });
const fmtFull = new Intl.DateTimeFormat('he-IL', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const ymd = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' });

/** היום → 14:32; השנה → 26 באוק׳; ישן → 26.10.25 — לפי שעון ישראל. */
function shortDate(ts) {
    if (!ts) return '';
    const d = new Date(ts * 1000), now = new Date();
    if (ymd.format(d) === ymd.format(now)) return fmtTime.format(d);
    if (ymd.format(d).slice(0, 4) === ymd.format(now).slice(0, 4)) return fmtDay.format(d);
    return fmtDate.format(d);
}

function hue(s) { let h = 0; for (const c of s || '') h = (h * 31 + c.charCodeAt(0)) % 360; return h; }
function initials(name, email) {
    const src = (name || email || '?').replace(/["'<>]/g, '').trim();
    // רק מילים שמתחילות באות — "שולח 48" → "ש", לא "ש4"
    const w = src.split(/[\s@._-]+/).filter(x => /^\p{L}/u.test(x));
    if (!w.length) return src.slice(0, 1) || '?';
    return (w.length > 1 ? w[0][0] + w[1][0] : w[0].slice(0, /^[a-z]/i.test(w[0]) ? 2 : 1)).toUpperCase();
}
function avatar(node, name, email) {
    node.textContent = initials(name, email);
    node.style.setProperty('--h', hue(email || name));
}
function size(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
}

// ── מסגרת: כותרת, ניווט ───────────────────────────────

function setTitle() {
    const b = state.box;
    $('#title').textContent = b ? 'דואר — ' + b.label : 'דואר';
    $('#subtitle').textContent = b ? (b.label !== b.email ? b.email : '') + (b.owner != null ? ' · שותפה איתך' : '') : '';
    document.title = b ? 'דואר — ' + b.label : 'דואר';
    $('#switch-ic').hidden = state.boxes.length < 2;
}

function route() {
    const m = location.hash.match(/^#\/m\/(\d+)$/);
    if (m && state.box) openMessage(+m[1]);
    else closeMessage();
}

function closeMessage() {
    document.body.classList.remove('reading');
    $('#back').hidden = true;
    state.open = null;
    $('#msg').hidden = true;
    $('#m-error').hidden = true;
    markCurrent();
}

$('#back').addEventListener('click', () => {
    if (history.state && history.state.fromList) history.back();
    else location.hash = '#/';
});

// ── רשימת ההודעות ─────────────────────────────────────

function skeleton() {
    const ul = $('#msgs');
    ul.replaceChildren(...Array.from({ length: 6 }, () => el('li', { class: 'card', 'aria-hidden': 'true' },
        el('span', { class: 'avatar' }), el('div', { class: 'body' },
            el('span', { class: 'skeleton', style: 'inline-size:45%' }), el('span', { class: 'skeleton', style: 'inline-size:80%' })))));
}

async function load(manual = false) {
    if (!state.box || state.loading) return;
    state.loading = true;
    $('#refresh').classList.add('spin');
    const boxId = state.box.id;
    try {
        const r = await api('list', { box: boxId });
        if (!state.box || state.box.id !== boxId) return;
        const fresh = state.first ? new Set() : new Set(r.messages.filter(m => !state.known.has(m.uid)).map(m => m.uid));
        r.messages.forEach(m => state.known.add(m.uid));
        state.msgs = r.messages;
        renderList(fresh);
        const u = $('#unseen');
        u.textContent = r.unseen; u.hidden = !r.unseen;
        $('#status').textContent = 'עודכן ' + fmtTime.format(new Date());
        if (fresh.size && !state.first) toast(fresh.size === 1 ? 'הודעה חדשה' : fresh.size + ' הודעות חדשות');
        else if (manual) toast('הדואר מעודכן');
        state.first = false;
    } catch (e) {
        $('#status').textContent = '';
        if (state.first) $('#msgs').replaceChildren(el('li', { class: 'empty' }, el('p', { class: 'error', text: e.message })));
        else toast(e.message, true);
    } finally {
        state.loading = false;
        $('#refresh').classList.remove('spin');
    }
}

function renderList(fresh = new Set()) {
    const ul = $('#msgs');
    if (!state.msgs.length) {
        ul.replaceChildren(el('li', { class: 'empty', text: 'אין עדיין הודעות בתיבה. הודעה חדשה תופיע כאן לבד, תוך 15 שניות.' }));
        return;
    }
    ul.replaceChildren(...state.msgs.map(m => {
        const who = m.from_name || m.from_email || '(בלי שולח)';
        const av = el('span', { class: 'avatar', 'aria-hidden': 'true' });
        avatar(av, m.from_name, m.from_email);
        const marks = el('div', { class: 'marks' },
            fresh.has(m.uid) ? el('span', { class: 'chip new', text: 'חדש' }) : null,
            m.code ? el('span', { class: 'chip code', dir: 'ltr' }, icon('key'), m.code) : null,
            m.attach ? el('span', { class: 'chip', 'aria-label': m.attach + ' צרופות' }, icon('clip'), String(m.attach)) : null);
        const li = el('li', {
            class: 'card' + (m.seen ? '' : ' unseen') + (fresh.has(m.uid) ? ' fresh' : ''),
            tabindex: '0', role: 'button', 'data-uid': m.uid,
            'aria-label': (m.seen ? '' : 'לא נקראה. ') + who + '. ' + (m.subject || 'בלי נושא'),
        }, av, el('div', { class: 'body' },
            el('div', { class: 'l1' }, el('span', { text: who }), el('span', { class: 't', text: shortDate(m.date) })),
            el('div', { class: 'l2', text: m.subject || '(בלי נושא)' }),
            el('div', { class: 'l3', text: m.snippet || '' }),
            marks));
        return li;
    }));
    markCurrent();
}

function markCurrent() {
    document.querySelectorAll('#msgs .card').forEach(c => c.setAttribute('aria-current', String(+c.dataset.uid === state.open)));
}

$('#msgs').addEventListener('click', e => {
    const c = e.target.closest('.card[data-uid]');
    if (!c) return;
    history.pushState({ fromList: true }, '', '#/m/' + c.dataset.uid);
    route();
});
$('#msgs').addEventListener('keydown', e => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.card[data-uid]')) { e.preventDefault(); e.target.click(); }
});
window.addEventListener('popstate', route);
window.addEventListener('hashchange', route);

$('#refresh').addEventListener('click', () => load(true));

function startPolling() {
    clearInterval(state.timer);
    state.timer = setInterval(() => { if (!document.hidden) load(); }, POLL_MS);
}
document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

// ── הודעה ─────────────────────────────────────────────

async function openMessage(uid) {
    state.open = uid;
    markCurrent();
    document.body.classList.add('reading');
    $('#back').hidden = false;
    $('#m-error').hidden = true;
    const art = $('#msg');
    art.hidden = true;
    const m0 = state.msgs.find(x => x.uid === uid);
    if (m0 && !m0.seen) { m0.seen = true; renderList(); }   // אופטימי — השרת מסמן \Seen בפתיחה
    try {
        const { message: m } = await api('message', { box: state.box.id, uid });
        if (state.open !== uid) return;
        renderMessage(m);
        art.hidden = false;
        window.scrollTo(0, 0);
        $('#read-pane').scrollTop = 0;
    } catch (e) {
        if (state.open !== uid) return;
        $('#m-error').textContent = e.message;
        $('#m-error').hidden = false;
    }
}

function renderMessage(m) {
    $('#m-subject').textContent = m.subject || '(בלי נושא)';
    avatar($('#m-avatar'), m.from_name, m.from_email);
    const from = $('#m-from');
    from.replaceChildren(m.from_name || m.from_email || '(בלי שולח)');
    if (m.from_name && m.from_email) from.append(' ', el('span', { class: 'muted', dir: 'ltr', style: 'font-weight:400', text: '<' + m.from_email + '>' }));
    $('#m-date').textContent = m.date ? fmtFull.format(new Date(m.date * 1000)) : '';
    const to = m.to.map(a => a.name || a.email).join(', ');
    $('#m-to').textContent = to ? 'אל: ' + to + (m.cc.length ? ' · עותק: ' + m.cc.map(a => a.name || a.email).join(', ') : '') : '';

    const chip = $('#m-code');
    chip.hidden = !m.code;
    chip.classList.remove('copied');
    $('#m-copy .ct').textContent = 'העתק';
    $('#m-copy .ci').replaceChildren(icon('copy'));
    if (m.code) $('#m-code-num').textContent = m.code;

    renderAttachments(m);

    const frame = $('#m-frame'), plain = $('#m-plain');
    if (m.html) {
        plain.hidden = true;
        frame.hidden = false;
        frame.style.blockSize = '120px';
        frame.srcdoc = frameDoc(m.html);
    } else {
        frame.hidden = true;
        frame.removeAttribute('srcdoc');
        plain.hidden = false;
        plain.replaceChildren(...linkify(m.text || ''));
    }
}

/** המסמך שבתוך ה-iframe: CSP שאוסר סקריפט, קישורים בלשונית חדשה, תמונות ברוחב המסך. */
function frameDoc(html) {
    const csp = "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; font-src https: data:; base-uri 'none'; form-action 'none'";
    return '<!doctype html><html><head><meta charset="utf-8">'
        + `<meta http-equiv="Content-Security-Policy" content="${csp}">`
        + '<meta name="referrer" content="no-referrer"><base target="_blank">'
        + '<style>html,body{margin:0}body{padding:4px 2px 12px;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;'
        + 'color:#16192b;background:#fff;overflow-wrap:break-word}img{max-width:100%;height:auto}a{color:#2d4a8a}'
        + 'pre{white-space:pre-wrap}</style></head><body dir="auto">' + html + '</body></html>';
}

const frame = $('#m-frame');
frame.addEventListener('load', () => {
    const doc = frame.contentDocument;
    if (!doc || !doc.body) return;
    // מייל ברוחב קבוע (600px) בטלפון — מקטינים את כולו במקום גלילה לצדדים
    const fit = () => {
        doc.body.style.zoom = '';
        const w = frame.clientWidth, sw = doc.documentElement.scrollWidth;
        if (sw > w + 4) doc.body.style.zoom = String(Math.max(0.4, w / sw));
        frame.style.blockSize = Math.ceil(doc.documentElement.getBoundingClientRect().height + 8) + 'px';
    };
    fit();
    doc.querySelectorAll('img').forEach(i => i.complete || i.addEventListener('load', fit, { once: true }));
    // קישור שנפתח — תמיד בלשונית חדשה, גם בלי target
    doc.querySelectorAll('a[href]').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
    if (window.ResizeObserver) new ResizeObserver(() => {
        frame.style.blockSize = Math.ceil(doc.documentElement.getBoundingClientRect().height + 8) + 'px';
    }).observe(doc.body);
});
window.addEventListener('resize', () => { if (!frame.hidden) frame.dispatchEvent(new Event('load')); });

/** טקסט רגיל עם קישורים לחיצים — בלי innerHTML. */
function linkify(text) {
    const out = [], re = /\bhttps?:\/\/[^\s<>"']+[^\s<>"'.,;:!?)\]]/g;
    let i = 0, m;
    while ((m = re.exec(text))) {
        if (m.index > i) out.push(text.slice(i, m.index));
        out.push(el('a', { href: m[0], target: '_blank', rel: 'noopener noreferrer', dir: 'ltr', text: m[0] }));
        i = m.index + m[0].length;
    }
    out.push(text.slice(i));
    return out;
}

// העתקת הקוד
$('#m-copy').addEventListener('click', async () => {
    const code = $('#m-code-num').textContent;
    let ok = false;
    try { await navigator.clipboard.writeText(code); ok = true; } catch {
        const t = el('textarea', { readonly: true, style: 'position:fixed;opacity:0' });
        t.value = code; document.body.append(t); t.select();
        try { ok = document.execCommand('copy'); } catch { /* */ }
        t.remove();
    }
    if (!ok) { toast('לא הצלחתי להעתיק — סמנו את הקוד והעתיקו ידנית', true); return; }
    $('#m-code').classList.add('copied');
    $('#m-copy .ct').textContent = 'הועתק';
    $('#m-copy .ci').replaceChildren(icon('check'));
    toast('הקוד ' + code + ' הועתק');
});

// ── צרופות ────────────────────────────────────────────

function attUrl(a, inline = false) {
    return 'api.php?' + new URLSearchParams({ action: 'attachment', box: state.box.id, uid: state.open, part: a.part, ...(inline ? { inline: 1 } : {}) });
}
const isPdf = a => a.type === 'application/pdf' || /\.pdf$/i.test(a.name);

function renderAttachments(m) {
    const ul = $('#m-atts');
    ul.hidden = !m.attachments.length;
    ul.replaceChildren(...m.attachments.map(a => {
        const pdf = isPdf(a);
        const ic = el('span', { class: 'ic' + (pdf ? ' pdf' : ''), 'aria-hidden': 'true', text: badge(a) });
        const tx = el('span', { class: 'tx' }, el('b', { text: a.name, dir: 'auto' }), el('small', { text: size(a.size) + (pdf ? ' · לחיצה לצפייה' : '') }));
        const open = pdf
            ? el('button', { class: 'open', type: 'button', onclick: () => Pdf.open(a) }, ic, tx)
            : el('a', { class: 'open', href: attUrl(a), download: a.name }, ic, tx);
        return el('li', { class: 'att' }, open,
            el('a', { class: 'icon-btn', href: attUrl(a), download: a.name, 'aria-label': 'הורדת ' + a.name }, icon('download')));
    }));
}
/** תווית קצרה לסוג הקובץ — PDF / DOC / XLS / IMG / ZIP, או הסיומת. */
function badge(a) {
    if (isPdf(a)) return 'PDF';
    if (a.type.startsWith('image/')) return 'IMG';
    if (/zip|compressed|rar|7z/.test(a.type)) return 'ZIP';
    if (/word|document/.test(a.type)) return 'DOC';
    if (/sheet|excel|csv/.test(a.type)) return 'XLS';
    if (a.type === 'message/rfc822') return 'EML';
    const ext = (a.name.match(/\.([a-z0-9]{1,4})$/i) || [])[1];
    return ext ? ext.toUpperCase() : 'FILE';
}

// ── צופה PDF (pdf.js מקומי, נטען רק כשצריך) — גם באנדרואיד, שלא מציג PDF ב-iframe ──

const Pdf = (() => {
    const dlg = $('#pdf-dlg'), canvas = $('#pdf-canvas'), stage = $('#pdf-stage');
    let lib = null, doc = null, page = 1, zoom = 1, task = null, seq = 0;

    function pdfjs() {
        return lib ||= new Promise((ok, bad) => {
            const s = el('script', { src: 'vendor/pdfjs/pdf.min.js?v=3.11.174' });
            s.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'vendor/pdfjs/pdf.worker.min.js?v=3.11.174'; ok(window.pdfjsLib); };
            s.onerror = () => { lib = null; bad(new Error('pdf.js')); };
            document.head.append(s);
        });
    }

    async function render() {
        if (!doc) return;
        const my = ++seq;
        const p = await doc.getPage(page);
        if (my !== seq) return;
        const base = p.getViewport({ scale: 1 });
        const fitW = Math.max(200, stage.clientWidth - 16);
        const css = (fitW / base.width) * zoom;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        // קנבס עד ~16 מיליון פיקסלים — יותר מזה קורס בטלפון
        const scale = Math.min(css * dpr, Math.sqrt(16e6 / (base.width * base.height)));
        const vp = p.getViewport({ scale });
        if (task) task.cancel();
        canvas.width = Math.round(vp.width); canvas.height = Math.round(vp.height);
        canvas.style.inlineSize = Math.round(base.width * css) + 'px';
        canvas.style.blockSize = Math.round(base.height * css) + 'px';
        task = p.render({ canvasContext: canvas.getContext('2d'), viewport: vp });
        try { await task.promise; } catch { /* בוטל */ }
        $('#pdf-page').textContent = `עמוד ${page} מתוך ${doc.numPages}`;
        $('#pdf-fit').textContent = Math.round(zoom * 100) + '%';
        $('#pdf-prev').disabled = page <= 1;
        $('#pdf-next').disabled = page >= doc.numPages;
    }

    async function open(a) {
        $('#pdf-title').textContent = a.name;
        const dl = $('#pdf-dl');
        dl.href = attUrl(a); dl.download = a.name;
        $('#pdf-page').textContent = 'טוען…';
        canvas.width = canvas.height = 0;
        page = 1; zoom = 1; doc = null;
        dlg.showModal();
        try {
            const pdf = await pdfjs();
            doc = await pdf.getDocument({ url: attUrl(a, true), withCredentials: true }).promise;
            await render();
        } catch (e) {
            $('#pdf-page').textContent = 'לא ניתן להציג — אפשר להוריד';
        }
    }

    $('#pdf-prev').addEventListener('click', () => { if (page > 1) { page--; render(); stage.scrollTop = 0; } });
    $('#pdf-next').addEventListener('click', () => { if (doc && page < doc.numPages) { page++; render(); stage.scrollTop = 0; } });
    $('#pdf-in').addEventListener('click', () => { zoom = Math.min(4, zoom * 1.25); render(); });
    $('#pdf-out').addEventListener('click', () => { zoom = Math.max(0.5, zoom / 1.25); render(); });
    $('#pdf-fit').addEventListener('click', () => { zoom = 1; render(); });
    dlg.addEventListener('close', () => { if (doc) doc.destroy(); doc = null; });
    window.addEventListener('resize', () => { if (dlg.open) render(); });

    // צביטה — מגדילה רק את המסמך, לא את הדף
    let pinch = null;
    const dist = t => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
    stage.addEventListener('touchstart', e => { if (e.touches.length === 2) pinch = { d: dist(e.touches), z: zoom }; }, { passive: true });
    stage.addEventListener('touchmove', e => {
        if (!pinch || e.touches.length !== 2) return;
        e.preventDefault();
        const z = Math.min(4, Math.max(0.5, pinch.z * dist(e.touches) / pinch.d));
        canvas.style.transform = `scale(${z / zoom})`; canvas.style.transformOrigin = 'top center';
        pinch.next = z;
    }, { passive: false });
    stage.addEventListener('touchend', () => {
        if (!pinch) return;
        canvas.style.transform = '';
        if (pinch.next) { zoom = pinch.next; render(); }
        pinch = null;
    });
    stage.addEventListener('dblclick', () => { zoom = zoom > 1 ? 1 : 2; render(); });

    return { open };
})();

// ── תיבות ─────────────────────────────────────────────

function selectBox(id) {
    const b = state.boxes.find(x => x.id === id) || state.boxes[0] || null;
    const changed = !state.box || !b || state.box.id !== b.id;
    state.box = b;
    try { if (b) localStorage.setItem('mailbox.box', String(b.id)); } catch { /* */ }
    setTitle();
    if (!changed) return;
    state.msgs = []; state.known = new Set(); state.first = true;
    if (location.hash.startsWith('#/m/')) history.replaceState(null, '', '#/');
    closeMessage();
    if (b) { skeleton(); load(); }
    else {
        $('#msgs').replaceChildren(el('li', { class: 'empty' },
            el('p', { text: 'עוד אין כאן תיבה.' }),
            el('p', {}, el('button', { class: 'btn primary', type: 'button', onclick: () => openAdd() }, '+ הוספת תיבה'))));
        $('#unseen').hidden = true;
    }
}

function renderBoxes() {
    const ul = $('#boxes-list');
    if (!state.boxes.length) {
        ul.replaceChildren(el('li', { class: 'empty', text: 'אין תיבות עדיין. "+ תיבה" כדי להוסיף.' }));
        return;
    }
    ul.replaceChildren(...state.boxes.map(b => el('li', { class: 'box-row', 'data-box': b.id },
        el('button', { class: 'use-row', type: 'button', onclick: () => { selectBox(b.id); $('#boxes-dlg').close(); } },
            el('span', { class: 'use-ic', 'aria-hidden': 'true' }, icon(state.box && state.box.id === b.id ? 'check' : 'mail')),
            el('span', { class: 'use-tx' }, el('b', { class: 'one', text: b.label }),
                el('small', { dir: 'auto', text: b.email + (b.owner != null ? ' · שותפה איתך' + (b.owner ? ' ע״י ' + b.owner : '') : '') })),
            el('span', { class: 'count', hidden: true, 'aria-label': 'לא נקראו' })),
        b.role === 'owner'
            ? el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'עריכה ושיתוף של ' + b.label, onclick: () => openBox(b) }, icon('edit'))
            : null)));
}

function openBoxes() {
    renderBoxes();
    $('#boxes-dlg').showModal();
    // כמה לא נקראו בכל תיבה — כל אחת בנפרד, כדי שתיבה איטית לא תעכב את השאר
    for (const b of state.boxes) {
        api('unseen', { box: b.id }).then(r => {
            const n = document.querySelector(`#boxes-list [data-box="${b.id}"] .count`);
            if (n) { n.textContent = r.unseen; n.hidden = !r.unseen; }
        }).catch(() => {});
    }
}
$('#boxes-btn').addEventListener('click', openBoxes);
$('#switch').addEventListener('click', openBoxes);   // לחיצה על שם התיבה — מעבר לתיבה אחרת
$('#add-box-btn').addEventListener('click', () => openAdd());

// ספקים: המדריך שמוצג בטופס, והשרת שממולא לבד. steps — הצעדים אצל הספק; [טקסט, כתובת] — קישור.
const PROVIDERS = {
    cpanel: {
        title: 'תיבה בשרת שלנו (cPanel)',
        host: 'box2054.bluehost.com', user: 'כתובת המייל המלאה', pass: 'הסיסמה של התיבה',
        steps: [
            'ב-cPanel ← Email Accounts — יוצרים תיבה חדשה, או משתמשים בקיימת.',
            'זוכרים את הסיסמה שנקבעה לתיבה (אפשר לאפס אותה שם, ב-Manage).',
            'כאן: כתובת המייל המלאה והסיסמה. השרת כבר ממולא.',
        ],
        note: 'השרת הוא box2054.bluehost.com ולא mail.mbe-plus.com — לשם השני התעודה לא מתאימה והחיבור נכשל.',
    },
    gmail: {
        title: 'Gmail',
        host: 'imap.gmail.com', user: 'כתובת ה-Gmail המלאה', pass: 'סיסמת אפליקציה — 16 אותיות',
        steps: [
            ['מפעילים אימות דו-שלבי בחשבון Google', 'https://myaccount.google.com/signinoptions/twosv'],
            ['נכנסים ל"סיסמאות אפליקציה"', 'https://myaccount.google.com/apppasswords'],
            'כותבים שם (למשל "תיבת-מייל") ← "יצירה". מעתיקים את 16 האותיות — הן מוצגות פעם אחת בלבד.',
            'כאן: כתובת ה-Gmail, ובשדה הסיסמה — סיסמת האפליקציה (רווחים לא משנים).',
        ],
        note: 'הסיסמה הרגילה של Gmail לא תעבוד. חשבון של ארגון (Google Workspace) — מנהל הארגון צריך לאפשר IMAP וסיסמאות אפליקציה. קיבלתם התראת אבטחה מגוגל? "כן, זה הייתי אני".',
    },
    outlook: {
        title: 'Outlook / Hotmail / Live',
        blocked: true,
        steps: [
            'מיקרוסופט חסמה כניסה בסיסמה ל-IMAP (רק כניסת OAuth), ולכן אי אפשר לחבר את התיבה ישירות.',
            'הפתרון: מעבירים את הדואר לתיבה בשרת שלנו. יוצרים תיבה ב-cPanel ומוסיפים אותה כאן ("השרת שלנו").',
            ['ב-Outlook: הגדרות ← דואר ← העברה ← "הפעל העברה" ← כתובת התיבה שלנו ← שמירה', 'https://outlook.live.com/mail/0/options/mail/forwarding'],
            'מעכשיו כל מייל שמגיע ל-Outlook מופיע גם כאן, תוך שניות.',
        ],
        note: 'חיבור ישיר ל-Outlook (בכניסה עם מיקרוסופט) — שלב ב׳.',
    },
    yahoo: {
        title: 'Yahoo',
        host: 'imap.mail.yahoo.com', user: 'כתובת ה-Yahoo המלאה', pass: 'סיסמת אפליקציה',
        steps: [
            ['נכנסים ל"אבטחת החשבון"', 'https://login.yahoo.com/account/security'],
            '"Generate app password" (יצירת סיסמת אפליקציה) ← שם, למשל "תיבת-מייל" ← יצירה.',
            'מעתיקים את הסיסמה, וכאן: כתובת ה-Yahoo וסיסמת האפליקציה.',
        ],
        note: 'הסיסמה הרגילה של Yahoo לא תעבוד.',
    },
    icloud: {
        title: 'iCloud (Apple)',
        host: 'imap.mail.me.com', user: 'כתובת ה-iCloud המלאה (‎@icloud.com)', pass: 'סיסמה ייעודית לאפליקציה',
        steps: [
            ['נכנסים לחשבון Apple ← "כניסה ואבטחה"', 'https://account.apple.com/account/manage'],
            '"סיסמאות ייעודיות לאפליקציות" ← + ← שם, למשל "תיבת-מייל" ← יצירה. (דורש אימות דו-שלבי.)',
            'כאן: כתובת ה-iCloud והסיסמה שנוצרה.',
        ],
        note: 'הסיסמה הרגילה של Apple לא תעבוד.',
    },
    other: {
        title: 'ספק אחר',
        host: '', user: 'בדרך כלל כתובת המייל המלאה', pass: 'סיסמת התיבה, או סיסמת אפליקציה אם יש אימות דו-שלבי',
        steps: [
            'מחפשים בעזרה של הספק "הגדרות IMAP" — צריך את שם השרת. בדרך כלל imap.<הדומיין> (למשל imap.zoho.com).',
            'פורט 993 (SSL). רק אם הספק כותב 143 — בוחרים 143 · STARTTLS.',
            'אם בחשבון יש אימות דו-שלבי — יוצרים אצל הספק "סיסמת אפליקציה" ומשתמשים בה.',
            'אם צריך להפעיל IMAP בהגדרות התיבה אצל הספק — מפעילים.',
        ],
        note: 'החיבור תמיד מוצפן, והתעודה של השרת נבדקת. שרת עם תעודה לא תקינה לא יתחבר.',
    },
};

function renderGuide(key) {
    const p = PROVIDERS[key];
    const step = s => el('li', {}, Array.isArray(s)
        ? el('a', { href: s[1], target: '_blank', rel: 'noopener noreferrer' }, s[0], ' ↗')
        : s);
    $('#guide').replaceChildren(...[
        el('h3', { text: p.blocked ? p.title + ' — לא ניתן לחבר ישירות' : 'איך מחברים ' + p.title }),
        el('ol', {}, p.steps.map(step)),
        p.blocked ? null : el('dl', { class: 'cells' },
            el('div', {}, el('dt', { text: 'שרת IMAP' }), el('dd', { dir: 'ltr', text: p.host || 'מהספק' })),
            el('div', {}, el('dt', { text: 'פורט' }), el('dd', { dir: 'ltr', text: '993 · SSL' })),
            el('div', {}, el('dt', { text: 'שם משתמש' }), el('dd', { text: p.user })),
            el('div', {}, el('dt', { text: 'סיסמה' }), el('dd', { text: p.pass }))),
        p.note ? el('p', { class: 'hint', text: p.note }) : null,
    ].filter(Boolean));   // replaceChildren(null) היה כותב "null"
    $('#guide').classList.toggle('blocked', !!p.blocked);
}

function setProvider(key) {
    const p = PROVIDERS[key] || PROVIDERS.other, f = $('#add-form');
    if (!p.blocked) f.host.value = p.host;
    f.querySelector('input[name=port][value="993"]').checked = true;
    renderGuide(key in PROVIDERS ? key : 'other');
    // Outlook — רק ההסבר: בלי שדות ובלי שמירה
    f.querySelectorAll('.fields, .group').forEach(x => { x.hidden = !!p.blocked; });
    $('#add-save').hidden = !!p.blocked;
}
$('#provider').addEventListener('change', e => setProvider(e.target.value));

function openAdd() {
    const f = $('#add-form');
    f.reset();
    setProvider('cpanel');
    $('.error', f).hidden = true;
    if ($('#boxes-dlg').open) $('#boxes-dlg').close();
    $('#add-dlg').showModal();
}

$('#add-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, err = $('.error', f), btn = $('#add-save');
    const d = Object.fromEntries(new FormData(f));
    err.hidden = true;
    if (!d.email || !d.password || !d.host) { err.textContent = 'חסרים מייל, סיסמה או שרת'; err.hidden = false; return; }
    btn.disabled = true; btn.textContent = 'בודק חיבור…';
    try {
        const r = await api('box_add', {}, { ...d, port: +d.port });
        f.reset();
        state.boxes = r.boxes;
        $('#add-dlg').close();
        toast('התיבה נוספה ✓');
        selectBox(r.id);
    } catch (x) {
        err.textContent = x.message; err.hidden = false;
    } finally {
        btn.disabled = false; btn.textContent = 'בדיקה ושמירה';
    }
});

let editing = null;
async function openBox(b) {
    editing = b;
    const f = $('#box-form');
    $('#box-title').textContent = b.email;
    f.label.value = b.label !== b.email ? b.label : '';
    $('.error', f).hidden = true;
    $('#share-email').value = '';
    $('#shares').replaceChildren();
    $('#boxes-dlg').close();
    $('#box-dlg').showModal();
    loadShares();
}

async function loadShares() {
    try {
        const r = await api('shares', { box: editing.id });
        $('#known-users').replaceChildren(...r.known.map(u => el('option', { value: u.email, label: u.name })));
        const ul = $('#shares');
        if (!r.shares.length) { ul.replaceChildren(el('li', { class: 'empty', text: 'התיבה לא משותפת עם אף אחד.' })); return; }
        ul.replaceChildren(...r.shares.map(s => el('li', { class: 'card', style: 'cursor:default' },
            el('span', { class: 'avatar', style: '--h:' + hue(s.email), 'aria-hidden': 'true', text: initials(s.name, s.email) }),
            el('div', { class: 'body' },
                el('div', { class: 'l1' }, el('span', { text: s.name || s.email })),
                el('div', { class: 'l2', dir: 'auto', text: s.email + (s.joined ? '' : ' · עוד לא נכנס/ה') })),
            el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'ביטול השיתוף עם ' + s.email,
                onclick: async () => {
                    try { await api('share_remove', {}, { box: editing.id, email: s.email }); loadShares(); }
                    catch (x) { toast(x.message, true); }
                } }, icon('close')))));
    } catch (x) { toast(x.message, true); }
}

$('#share-add').addEventListener('click', async () => {
    const email = $('#share-email').value.trim();
    if (!email) { $('#share-email').focus(); return; }
    try {
        await api('share_add', {}, { box: editing.id, email });
        $('#share-email').value = '';
        toast('שותף עם ' + email);
        loadShares();
    } catch (x) { toast(x.message, true); }
});
$('#share-email').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('#share-add').click(); } });

$('#box-form').addEventListener('submit', async e => {
    e.preventDefault();
    try {
        const r = await api('box_update', {}, { box: editing.id, label: e.target.label.value });
        state.boxes = r.boxes;
        state.box = state.boxes.find(b => b.id === state.box?.id) || state.box;
        setTitle();
        $('#box-dlg').close();
        toast('נשמר');
    } catch (x) { const er = $('.error', e.target); er.textContent = x.message; er.hidden = false; }
});

$('#box-delete').addEventListener('click', async () => {
    if (!confirm(`להסיר את ${editing.email} מהאפליקציה? ההודעות בתיבה עצמה לא נמחקות.`)) return;
    try {
        const r = await api('box_delete', {}, { box: editing.id });
        state.boxes = r.boxes;
        $('#box-dlg').close();
        if (state.box && state.box.id === editing.id) { state.box = null; selectBox(state.boxes[0]?.id); }
        toast('התיבה הוסרה');
    } catch (x) { toast(x.message, true); }
});

document.addEventListener('click', e => { const b = e.target.closest('[data-close]'); if (b) b.closest('dialog').close(); });

// --vvh — הגובה הנראה בלי המקלדת
(() => {
    const vv = window.visualViewport; if (!vv) return;
    const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
    vv.addEventListener('resize', set); set();
})();

// ── התחלה ────────────────────────────────────────────

(async function start() {
    skeleton();
    try {
        const r = await api('me');
        state.me = r.me; state.boxes = r.boxes;
        if (r.imported) toast(r.imported === 1 ? 'תיבה אחת יובאה מהשרת' : r.imported + ' תיבות יובאו מהשרת');
        let saved = 0;
        try { saved = +localStorage.getItem('mailbox.box') || 0; } catch { /* */ }
        selectBox(saved);
        startPolling();
        if (location.hash.startsWith('#/m/')) history.replaceState(null, '', '#/');
    } catch (e) {
        $('#msgs').replaceChildren(el('li', { class: 'empty' }, el('p', { class: 'error', text: e.message })));
    }
})();
