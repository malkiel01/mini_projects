/**
 * עורך התמלילים — האפליקציה: רשימת המסמכים של המשתמש, פתיחה ועריכה, שמירה אוטומטית, נעילה, גרסאות,
 * ייצוא, ומסך המנהל (אישור לקוחות הטמעה). המשתמש — מהשער (PLATFORM_USER) או מכניסה עם הכלים הדיגיטליים.
 */
import { createWordEditor, h, toast, sheet, when } from './editor.js';
import { createSaver, findDraft, drafts, downloadLocalCopy, STATE_TEXT } from './saver.js';

const tab = Math.random().toString(36).slice(2);
const GUIDE = 'https://github.com/malkiel01/mini_projects/blob/main/word-processor/docs/integration.md';
const main = document.getElementById('main');
const top = document.querySelector('header.top');
let me = null;
let current = null;   // {id, ed, saver, can, title, beat, ...}

async function api(action, body = {}, isForm = false) {
    let res;
    try {
        res = await fetch('api.php?action=' + action, {
            method: 'POST',
            headers: isForm ? { 'X-WP': '1', 'X-Tab': tab } : { 'Content-Type': 'application/json', 'X-WP': '1', 'X-Tab': tab },
            body: isForm ? body : JSON.stringify(body),
            credentials: 'same-origin',
        });
    } catch {
        throw Object.assign(new Error('אין חיבור לשרת'), { code: 'offline' });
    }
    const data = await res.json().catch(() => ({ error: 'תשובה לא תקינה מהשרת', code: 'server' }));
    if (!res.ok) throw Object.assign(new Error(data.error || 'שגיאה'), { code: data.code, status: res.status });
    return data;
}

// ---------- ניתוב: #/ רשימה · #/d/<id> מסמך · #/admin לקוחות ----------
window.addEventListener('hashchange', route);
async function route() {
    const hash = location.hash || '#/';
    if (current && !hash.startsWith('#/d/' + current.id)) {
        try { await closeDoc(); } catch (e) { if (e.stay) return; throw e; }
    }
    if (hash.startsWith('#/d/')) return openDoc(hash.slice(4));
    if (hash === '#/admin' && me?.admin) return adminView();
    return listView();
}

function header({ back = false, title = '', sub = '', actions = [] } = {}) {
    top.textContent = '';
    if (back) top.append(h('button.icon-btn', { 'aria-label': 'חזרה לרשימה', onclick: () => { location.hash = '#/'; } }, '→'));
    top.append(title instanceof Node ? h('div.titles', title, sub ? h('div.sub', sub) : null) : h('div.titles', h('h1', title), sub ? h('div.sub', sub) : null), ...actions);
}

// ---------- רשימת המסמכים ----------
async function listView() {
    document.body.classList.remove('editing');
    header({ title: 'עורך התמלילים', sub: me?.name || '', actions: [
        me?.admin ? h('button.icon-btn', { 'aria-label': 'לקוחות הטמעה', title: 'לקוחות הטמעה', onclick: () => { location.hash = '#/admin'; } }, '🔗') : null,
    ] });
    const search = h('input', { type: 'search', placeholder: 'חיפוש', 'aria-label': 'חיפוש' });
    const list = h('ul.list', h('li.empty', 'טוען…'));
    const fileIn = h('input', { type: 'file', accept: '.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document', hidden: true, onchange: importDocx });
    main.textContent = '';
    main.append(
        h('div.toolbar', search, h('button.btn.primary', { onclick: newDoc }, h('span', { 'aria-hidden': 'true' }, '+'), ' מסמך')),
        h('div.tiles', { style: { marginBottom: '12px' } },
            h('button.tile', { type: 'button', onclick: () => fileIn.click() }, h('span.tile-ic', { 'aria-hidden': 'true' }, '📥'), 'ייבוא מסמך Word (‎.docx)')),
        fileIn, list);
    let docs = [];
    const paint = () => {
        const q = search.value.trim();
        const shown = docs.filter(d => !q || (d.title || '').includes(q));
        list.textContent = '';
        if (!shown.length) list.append(h('li.empty', docs.length ? 'אין מסמך כזה' : 'עוד אין מסמכים. "+ מסמך" כדי להתחיל.'));
        for (const d of shown) {
            list.append(h('li.card', { tabindex: 0, role: 'button', onclick: () => { location.hash = '#/d/' + d.doc; },
                onkeydown: e => { if (e.key === 'Enter') location.hash = '#/d/' + d.doc; } },
                h('span.doc-ic', { 'aria-hidden': 'true' }, '📄'),
                h('div.body', h('div.l1', h('span', d.title || 'ללא שם'), h('span.t', when(d.updated_at))),
                    h('div.l2', d.updated_by ? `נערך על ידי ${d.updated_by}` : ''))));
        }
    };
    search.addEventListener('input', paint);
    try {
        docs = (await api('my_docs')).docs;
        paint();
    } catch (e) {
        list.textContent = '';
        list.append(h('li.error', e.message));
    }
}

async function newDoc() {
    try {
        const r = await api('my_new', { title: 'מסמך חדש' });
        location.hash = '#/d/' + r.doc;
    } catch (e) { toast(e.message, true); }
}

async function importDocx(e) {
    const f = e.target.files[0];
    e.target.value = '';
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    toast('מייבא…');
    try {
        const r = await fetch('api.php?action=my_new&title=', { method: 'POST', body: withTitle(fd, f.name.replace(/\.docx$/i, '')), headers: { 'X-WP': '1', 'X-Tab': tab } });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'הייבוא נכשל');
        location.hash = '#/d/' + d.doc;
    } catch (ex) { toast(ex.message, true); }
}
function withTitle(fd, t) { fd.append('title', t); return fd; }

// ---------- מסמך ----------
async function openDoc(id) {
    if (current?.id === id) return;
    document.body.classList.add('editing');
    main.textContent = '';
    main.append(h('p.empty', 'פותח…'));
    let d;
    try {
        d = await api('my_open', { doc: id });
    } catch (e) {
        main.textContent = '';
        main.append(h('p.error', e.message), h('p', h('a', { href: '#/' }, 'חזרה לרשימה')));
        return;
    }
    const titleIn = h('input.title-in', { value: d.title, 'aria-label': 'שם המסמך', placeholder: 'ללא שם', maxlength: 200 });
    const state = h('span.save-state', { role: 'status' }, '');
    const pagesEl = h('span.save-state.pages-count', { 'aria-live': 'off' });
    const cur = current = { id, can: d.can, title: d.title };
    header({ back: true, title: titleIn, actions: [pagesEl, state, h('button.icon-btn', { 'aria-label': 'עוד', title: 'עוד', onclick: e => docMenu(e.currentTarget) }, '⋯')] });
    titleIn.addEventListener('change', async () => {
        try { await api('my_rename', { doc: id, title: titleIn.value }); cur.title = titleIn.value; } catch (e) { toast(e.message, true); }
    });

    main.textContent = '';
    const banner = h('div.banner', { hidden: true });
    const root = h('div');
    main.append(banner, root);
    main.classList.add('editor-main');
    const ed = createWordEditor(root, {
        json: d.json, page: d.page, editable: d.can === 'edit',
        upload: async file => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('doc', id);
            return api('my_upload', fd, true);
        },
        versions: {
            list: async () => (await api('my_versions', { doc: id })).versions,
            get: vid => api('my_version', { doc: id, id: vid }),
        },
        // כל שינוי — כולל הגדרות עמוד וסגנונות, שנמצאים בתוך המסמך — עובר לשמירה
        onUpdate: () => { if (cur.saver && cur.can === 'edit') cur.saver.changed(); },
    });
    cur.ed = ed;
    ed.statusTarget(pagesEl);

    // שמירה: טיוטה במכשיר מיד, לשרת אחרי שנייה וחצי; תוכן והגדרות עמוד — בבקשה אחת ובגרסה אחת
    const draftKey = `app:${me.id}:${id}`;
    const saver = cur.saver = createSaver({
        version: d.version,
        draftKey,
        snapshot: () => ed.getJSON(),
        send: (json, base) => api('my_save', { doc: id, json, base_version: base }),
        onState: (s, info) => {
            state.textContent = cur.can === 'edit' || s !== 'saved' ? STATE_TEXT[s] : 'קריאה בלבד';
            state.classList.toggle('bad', ['offline', 'conflict', 'error'].includes(s));
            if (s === 'conflict') showBanner('המסמך השתנה במקום אחר. השינויים שלך שמורים במכשיר.',
                ['טעינה מחדש', reloadFromServer], ['הורדת העותק שלי', () => downloadLocalCopy(ed.getJSON(), cur.title)]);
            else if (s === 'locked') lockedBy(info.message || 'המסמך פתוח לעריכה אצל משתמש אחר');
            else if (s === 'error' && info.code === 'schema_newer') showBanner(info.message, ['רענון', () => location.reload()]);
            else if (s === 'error') toast(info.message || 'השמירה נכשלה', true);
            else if (s === 'saved' && banner.dataset.kind === 'offline') hideBanner();
            if (s === 'offline') showBanner('אין חיבור לשרת. ממשיכים לעבוד — הכול נשמר במכשיר ויישלח כשהחיבור יחזור.', null, 'offline');
        },
    });
    state.textContent = cur.can === 'edit' ? STATE_TEXT.saved : 'קריאה בלבד';
    cur.save = () => saver.flush();

    function showBanner(text, ...rest) {
        const kind = typeof rest[rest.length - 1] === 'string' ? rest.pop() : '';
        banner.textContent = '';
        banner.dataset.kind = kind;
        banner.append(h('span', text), ...rest.filter(Boolean).map(([label, fn]) => h('button.btn.sm.ghost', { onclick: fn }, label)));
        banner.hidden = false;
    }
    function hideBanner() { banner.hidden = true; banner.dataset.kind = ''; }
    function lockedBy(msg) {
        cur.can = 'view';
        saver.stop();
        ed.setEditable(false);
        state.textContent = 'קריאה בלבד';
        showBanner(msg + ' — פתוח לקריאה בלבד.' + (saver.dirty ? ' השינויים שלך שמורים במכשיר.' : ''));
    }
    async function reloadFromServer() {
        const fresh = await api('my_open', { doc: id });
        ed.setContent(fresh.json);
        cur.can = fresh.can;
        ed.setEditable(cur.can === 'edit');
        saver.reset(fresh.version);
        hideBanner();
        if (cur.can !== 'edit' && fresh.lock) lockedBy(`פתוח לעריכה אצל ${fresh.lock.by}`);
    }
    if (d.can !== 'edit' && d.lock) lockedBy(`פתוח לעריכה אצל ${d.lock.by}`);

    // טיוטה שנשארה במכשיר (לשונית שנסגרה, ניתוק, קריסה) — מציעים לשחזר
    findDraft(draftKey, JSON.stringify(ed.getJSON())).then(dr => {
        if (!dr || current !== cur) return;
        const changedSince = dr.base_version !== d.version;
        const at = when(Math.round(dr.at / 1000));
        if (cur.can !== 'edit') {
            showBanner(`יש במכשיר שינויים שלא נשמרו (${at}), והמסמך פתוח עכשיו אצל מישהו אחר.`, ['הורדת השינויים', () => downloadLocalCopy(dr.json, cur.title)]);
            return;
        }
        showBanner(`נמצאו במכשיר שינויים שלא נשמרו (${at}).` + (changedSince ? ' שימו לב: המסמך השתנה מאז בשרת — שחזור ידרוס את השינויים ההם.' : ''),
            ['שחזור', () => { ed.setContent(dr.json); saver.markDirty(d.version); hideBanner(); toast('השינויים שוחזרו'); }],
            ['הורדה', () => downloadLocalCopy(dr.json, cur.title)],
            ['מחיקה', () => { if (confirm('למחוק את השינויים שלא נשמרו? אי אפשר לבטל.')) { drafts.del(draftKey); hideBanner(); } }]);
    });

    // דופק: מחזיק את הנעילה; כשהיא תפוסה — בודק אם התפנתה
    cur.beat = setInterval(async () => {
        try {
            const l = await api('my_lock', { doc: id, op: 'beat' });
            if (l.ok && cur.can !== 'edit') {
                showBanner('המסמך התפנה לעריכה.', ['מעבר לעריכה', reloadFromServer]);
            } else if (!l.ok && cur.can === 'edit') {
                lockedBy(`פתוח לעריכה אצל ${l.lock?.by || 'משתמש אחר'}`);
            }
        } catch { /* רשת — ננסה בדופק הבא */ }
    }, 30000);

    /** לפני ייצוא / שכפול: שומרים קודם, ואם לא הצליח — לא ממשיכים בשקט עם גרסה ישנה. */
    const saved = async what => (await ensureSaved(cur, what)) === 'ok';
    const download = (fmt, what) => async () => { if (await saved(what)) location.href = `api.php?action=my_export&format=${fmt}&doc=${id}`; };
    function docMenu(anchor) {
        const items = [
            ['⬇️', 'הורדה כ-Word', download('docx', 'ההורדה')],
            ['📕', 'הורדה כ-PDF', download('pdf', 'ההורדה')],
            ['🖨', 'הדפסה', () => ed.print()],
            ['📝', 'הורדה כטקסט', download('text', 'ההורדה')],
            ['🧾', 'גיבוי (JSON)', download('json', 'הגיבוי')],
            ['📑', 'שכפול', async () => { if (!(await saved('השכפול'))) return; const r = await api('my_copy', { doc: id }); location.hash = '#/d/' + r.doc; }],
            ['🗑', 'מחיקה', async () => {
                if (!confirm(`למחוק את "${cur.title || 'ללא שם'}"? אי אפשר לבטל.`)) return;
                await api('my_delete', { doc: id });
                saver.stop();
                drafts.del(draftKey);
                cur.deleted = true;
                location.hash = '#/';
            }],
        ];
        popMenu(anchor, items);
    }
}

/**
 * מוודא שהכול נשמר בשרת. אם לא — שואל: לנסות שוב / להוריד עותק / (בסגירה) לצאת בכל זאת.
 * מחזיר 'ok' | 'leave' | 'stay'. השינויים שלא נשמרו נשארים בכל מקרה כטיוטה במכשיר.
 */
async function ensureSaved(cur, what, leaving = false) {
    if (!cur.saver || cur.deleted || !cur.saver.dirty) return 'ok';
    for (;;) {
        const r = await cur.saver.flush();
        if (r.ok && !cur.saver.dirty) return 'ok';
        if (r.ok) continue;   // הוקלד עוד בזמן השמירה
        const reason = { offline: 'אין חיבור לשרת', conflict: 'המסמך השתנה במקום אחר', locked: 'המסמך פתוח לעריכה אצל מישהו אחר' }[r.code] || r.message;
        const pick = await choose('השינויים לא נשמרו', `${reason}. ${what} לא תכלול את השינויים האחרונים — לכן ${leaving ? 'לא יצאנו' : 'עצרנו'}. השינויים שמורים במכשיר הזה.`, [
            ['retry', 'לנסות שוב', 'primary'],
            ['copy', 'הורדת עותק של השינויים'],
            leaving ? ['leave', 'לצאת בכל זאת (השינויים יוצעו לשחזור בפתיחה הבאה)'] : null,
            ['stay', leaving ? 'להישאר במסמך' : 'ביטול'],
        ]);
        if (pick === 'retry') continue;
        if (pick === 'copy') { downloadLocalCopy(cur.ed.getJSON(), cur.title); continue; }
        return pick === 'leave' ? 'leave' : 'stay';
    }
}

/** חלון בחירה פשוט. מחזיר את המפתח שנבחר, או null כשנסגר. */
function choose(title, text, options) {
    return new Promise(resolve => {
        let picked = null;
        const dlg = sheet(title, body => {
            body.append(h('p', text), h('div.choices', options.filter(Boolean).map(([key, label, kind]) =>
                h('button.btn' + (kind ? '.' + kind : '.ghost'), { type: 'button', onclick: () => { picked = key; dlg.close(); } }, label))));
        });
        dlg.addEventListener('close', () => resolve(picked));
    });
}

async function closeDoc() {
    const cur = current;
    if (!cur) return;
    if (cur.can === 'edit' && (await ensureSaved(cur, 'היציאה', true)) === 'stay') {
        history.replaceState(null, '', '#/d/' + cur.id);   // נשארים במסמך
        throw Object.assign(new Error('stay'), { stay: true });
    }
    current = null;
    clearInterval(cur.beat);
    cur.saver?.destroy();
    if (cur.can === 'edit') api('my_lock', { doc: cur.id, op: 'release' }).catch(() => {});
    cur.ed?.destroy();
    main.classList.remove('editor-main');
}

// סגירת לשונית / ניווט החוצה: הטיוטה כבר במכשיר; מנסים לשמור, משחררים את הנעילה, ומבקשים מהדפדפן לשאול
window.addEventListener('beforeunload', e => {
    if (current?.saver?.dirty) { current.saver.flush(); e.preventDefault(); }
});
window.addEventListener('pagehide', () => {
    const cur = current;
    if (!cur || cur.can !== 'edit') return;
    fetch('api.php?action=my_lock', { method: 'POST', keepalive: true, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP': '1', 'X-Tab': tab },
        body: JSON.stringify({ doc: cur.id, op: 'release' }) }).catch(() => {});
});
document.addEventListener('visibilitychange', () => { if (document.hidden && current?.saver?.dirty) current.saver.flush(); });

function popMenu(anchor, items) {
    document.querySelector('.menu-pop')?.remove();
    const r = anchor.getBoundingClientRect();
    const m = h('div.menu-pop', { role: 'menu', style: { top: r.bottom + 4 + 'px', left: Math.max(8, r.left - 160) + 'px' } },
        items.map(([ic, label, fn]) => h('button', { type: 'button', role: 'menuitem', onclick: async () => { m.remove(); try { await fn(); } catch (e) { toast(e.message, true); } } },
            h('span', { 'aria-hidden': 'true' }, ic), label)));
    document.body.append(m);
    m.querySelector('button')?.focus();
    setTimeout(() => document.addEventListener('click', function off(e) { if (!m.contains(e.target)) { m.remove(); document.removeEventListener('click', off); } }), 0);
}

// ---------- לקוחות ההטמעה (מנהל העורך) ----------
async function adminView() {
    document.body.classList.remove('editing');
    header({ back: true, title: 'לקוחות הטמעה', sub: 'אפליקציות שמטמיעות את העורך' });
    const list = h('ul.list', h('li.empty', 'טוען…'));
    main.textContent = '';
    main.append(h('p.hint', 'אפליקציה נרשמת לבד. מאותו דומיין ומהתיקייה שלה — פעילה מיד; מדומיין אחר, או אחרי שינוי כתובות — ממתינה לאישור כאן. ',
        h('a', { href: GUIDE, target: '_blank', rel: 'noopener' }, 'מדריך ההתממשקות')), list);
    const load = async () => {
        try {
            const { clients } = await api('admin_clients');
            list.textContent = '';
            if (!clients.length) list.append(h('li.empty', 'עוד אין לקוחות רשומים'));
            for (const c of clients) {
                const chip = { active: ['ok', 'פעיל'], pending: ['warn', 'ממתין לאישור'], disabled: ['bad', 'כבוי'] }[c.status];
                const set = async s => { try { await api('admin_client_set', { client: c.id, status: s }); load(); } catch (e) { toast(e.message, true); } };
                list.append(h('li.card', { style: { cursor: 'default' } },
                    h('span.doc-ic', { 'aria-hidden': 'true' }, '🔗'),
                    h('div.body', h('div.l1', h('span', c.name || c.id), h('span.chip.' + chip[0], chip[1])),
                        h('div.l2', `${c.id} · ${c.origins.join(', ')} · ${c.docs} מסמכים`)),
                    c.status !== 'active' ? h('button.btn.sm.primary', { onclick: () => set('active') }, 'אישור')
                        : h('button.btn.sm.ghost', { onclick: () => confirm(`לכבות את ${c.name || c.id}? ההטמעה תפסיק לעבוד אצלה.`) && set('disabled') }, 'כיבוי')));
            }
        } catch (e) {
            list.textContent = '';
            list.append(h('li.error', e.message));
        }
    };
    load();
}

// ---------- התחלה ----------
(async () => {
    (() => { const vv = window.visualViewport; if (!vv) return;
        const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
        vv.addEventListener('resize', set); set(); })();
    try {
        me = (await api('me')).user;
    } catch (e) {
        main.textContent = '';
        main.append(h('p.error', e.message));
        return;
    }
    if (!me) {
        header({ title: 'עורך התמלילים' });
        main.textContent = '';
        main.append(h('div.login-box', h('h2', 'עורך התמלילים'), h('p.muted', 'מסמכים כמו ב-Word — עמודים, כותרות, רשימות, טבלאות ותמונות.'),
            location.hash === '#login-failed' ? h('p.error', 'הכניסה לא הושלמה. נסו שוב.') : null,
            h('a.btn.primary', { href: 'api.php?action=sso_start' }, 'כניסה עם הכלים הדיגיטליים')));
        return;
    }
    route();
})();
