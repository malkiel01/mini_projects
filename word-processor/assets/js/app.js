/**
 * עורך התמלילים — האפליקציה: רשימת המסמכים של המשתמש, פתיחה ועריכה, שמירה אוטומטית, נעילה, גרסאות,
 * ייצוא, ומסך המנהל (אישור לקוחות הטמעה). המשתמש — מהשער (PLATFORM_USER) או מכניסה עם הכלים הדיגיטליים.
 */
import { createWordEditor, h, toast, sheet, field, when } from './editor.js';

const tab = Math.random().toString(36).slice(2);
const main = document.getElementById('main');
const top = document.querySelector('header.top');
let me = null;
let current = null;   // {id, ed, version, dirty, timer, beat, ...}

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
    if (current && !hash.startsWith('#/d/' + current.id)) await closeDoc();
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
    const cur = current = { id, version: d.version, dirty: false, saving: null, can: d.can, title: d.title };
    header({ back: true, title: titleIn, actions: [state, h('button.icon-btn', { 'aria-label': 'עוד', title: 'עוד', onclick: e => docMenu(e.currentTarget) }, '⋯')] });
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
        onUpdate: () => {
            // טרנזקציה שלא שינתה כלום (למשל תיקון טבלה בטעינה) — לא שינוי
            if (!cur.ed || (!cur.dirty && JSON.stringify(cur.ed.getJSON()) === cur.saved)) return;
            cur.dirty = true; setState(); clearTimeout(cur.timer); cur.timer = setTimeout(() => save(), 1500);
        },
        onPage: async p => {
            try { await api('my_page', { doc: id, page: p }); } catch (e) { toast(e.message, true); }
        },
    });
    cur.ed = ed;
    cur.saved = JSON.stringify(ed.getJSON());

    function setState(text) {
        state.textContent = text || (cur.dirty ? 'לא נשמר' : 'נשמר');
    }
    setState();
    async function save() {
        if (!cur.dirty || cur.can !== 'edit') return;
        if (cur.saving) return cur.saving.then(save);
        cur.dirty = false;
        const json = ed.getJSON();
        const str = JSON.stringify(json);
        if (str === cur.saved) return setState();
        setState('שומר…');
        cur.saving = api('my_save', { doc: id, json, base_version: cur.version })
            .then(r => { cur.version = r.version; cur.saved = str; setState(); })
            .catch(e => {
                cur.dirty = true;
                setState('לא נשמר');
                if (e.code === 'conflict') banner_(`המסמך השתנה במקום אחר. `, 'טעינה מחדש', () => { current = null; openDoc(id); });
                else if (e.code === 'locked') lockedBy(e.message);
                else toast(e.message, true);
            })
            .finally(() => { cur.saving = null; });
        return cur.saving;
    }
    cur.save = save;

    function banner_(text, label, onClick) {
        banner.textContent = '';
        banner.append(h('span', text), label ? h('button.btn.sm.ghost', { onclick: onClick }, label) : null);
        banner.hidden = false;
    }
    function lockedBy(msg) {
        cur.can = 'view';
        ed.setEditable(false);
        banner_(msg + ' — פתוח לקריאה בלבד.', null);
    }
    if (d.can !== 'edit' && d.lock) lockedBy(`פתוח לעריכה אצל ${d.lock.by}`);

    // דופק: מחזיק את הנעילה; כשהיא תפוסה — בודק אם התפנתה
    cur.beat = setInterval(async () => {
        try {
            const l = await api('my_lock', { doc: id, op: 'beat' });
            if (l.ok && cur.can !== 'edit') {
                banner_('המסמך התפנה לעריכה.', 'מעבר לעריכה', async () => {
                    const fresh = await api('my_open', { doc: id });
                    cur.version = fresh.version;
                    ed.setContent(fresh.json);
                    cur.saved = JSON.stringify(ed.getJSON());
                    ed.setPage(fresh.page);
                    cur.can = 'edit';
                    ed.setEditable(true);
                    banner.hidden = true;
                });
            } else if (!l.ok && cur.can === 'edit') {
                lockedBy(`פתוח לעריכה אצל ${l.lock?.by || 'משתמש אחר'}`);
            }
        } catch { /* רשת — ננסה בדופק הבא */ }
    }, 30000);

    function docMenu(anchor) {
        const items = [
            ['⬇️', 'הורדה כ-Word', () => { location.href = `api.php?action=my_export&format=docx&doc=${id}`; }],
            ['🖨', 'הדפסה / PDF', () => ed.print()],
            ['📝', 'הורדה כטקסט', () => { location.href = `api.php?action=my_export&format=text&doc=${id}`; }],
            ['🧾', 'גיבוי (JSON)', () => { location.href = `api.php?action=my_export&format=json&doc=${id}`; }],
            ['📑', 'שכפול', async () => { await save(); const r = await api('my_copy', { doc: id }); location.hash = '#/d/' + r.doc; }],
            ['🗑', 'מחיקה', async () => {
                if (!confirm(`למחוק את "${cur.title || 'ללא שם'}"? אי אפשר לבטל.`)) return;
                await api('my_delete', { doc: id });
                cur.dirty = false;
                location.hash = '#/';
            }],
        ];
        popMenu(anchor, items);
    }
}

async function closeDoc() {
    const cur = current;
    current = null;
    if (!cur) return;
    clearTimeout(cur.timer);
    clearInterval(cur.beat);
    try {
        if (cur.dirty) { cur.dirty = true; await cur.save?.(); }
        if (cur.can === 'edit') await api('my_lock', { doc: cur.id, op: 'release' });
    } catch { /* לא מעכבים יציאה */ }
    cur.ed?.destroy();
    main.classList.remove('editor-main');
}

window.addEventListener('beforeunload', e => {
    if (current?.dirty) { current.save?.(); e.preventDefault(); }
});
document.addEventListener('visibilitychange', () => { if (document.hidden && current?.dirty) current.save?.(); });

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
    main.append(h('p.hint', 'אפליקציה נרשמת לבד (platform/docs/embed.md). מאותו דומיין ומהתיקייה שלה — פעילה מיד; מדומיין אחר, או אחרי שינוי כתובות — ממתינה לאישור כאן.'), list);
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
