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
let current = null;   // {id, ed, saver, can, role, title, beat, ...}
/** התפקיד שלי במסמך ששותף איתי (שלב 5 — lib/share.php). */
const ROLE_TEXT = { view: 'צפייה', comment: 'הערות', edit: 'עריכה', owner: 'בעלים' };
const canSave = c => c && (c.can === 'edit' || c.can === 'comment');

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

// ---------- ניתוב: #/ רשימה · #/d/<id> מסמך · #/trash סל המחזור · #/admin ניהול (קישורים לזרים, לקוחות) ----------
window.addEventListener('hashchange', route);
async function route() {
    const hash = location.hash || '#/';
    if (current && !hash.startsWith('#/d/' + current.id)) {
        try { await closeDoc(); } catch (e) { if (e.stay) return; throw e; }
    }
    if (hash.startsWith('#/d/')) return openDoc(hash.slice(4));
    if (hash === '#/admin' && me?.admin) return adminView();
    if (hash === '#/trash') return trashView();
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
        h('button.icon-btn', { 'aria-label': 'סל המחזור', title: 'סל המחזור', onclick: () => { location.hash = '#/trash'; } }, '🗑'),
        me?.admin ? h('button.icon-btn', { 'aria-label': 'ניהול העורך', title: 'ניהול העורך — קישורים לזרים ולקוחות הטמעה', onclick: () => { location.hash = '#/admin'; } }, '🔗') : null,
    ] });
    const search = h('input', { type: 'search', placeholder: 'חיפוש', 'aria-label': 'חיפוש' });
    const list = h('ul.list', h('li.empty', 'טוען…'));
    const fileIn = h('input', { type: 'file', accept: '.docx,.odt,.html,.htm,.txt,.md,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.oasis.opendocument.text,text/html,text/plain', hidden: true, onchange: importFile });
    main.textContent = '';
    main.append(
        h('div.toolbar', search, h('button.btn.primary', { onclick: newDoc }, h('span', { 'aria-hidden': 'true' }, '+'), ' מסמך')),
        h('div.tiles', { style: { marginBottom: '12px' } },
            h('button.tile', { type: 'button', onclick: () => fileIn.click() }, h('span.tile-ic', { 'aria-hidden': 'true' }, '📥'), 'ייבוא מסמך — Word, ‏ODT, ‏HTML או טקסט')),
        fileIn, list);
    let docs = [], shared = [];
    const card = (d, l2) => h('li.card', { tabindex: 0, role: 'button', onclick: () => { location.hash = '#/d/' + d.doc; },
        onkeydown: e => { if (e.key === 'Enter') location.hash = '#/d/' + d.doc; } },
        h('span.doc-ic', { 'aria-hidden': 'true' }, d.role ? '👥' : '📄'),
        h('div.body', h('div.l1', h('span', d.title || 'ללא שם'), h('span.t', when(d.updated_at))), h('div.l2', l2)));
    const paint = () => {
        const q = search.value.trim();
        const shown = docs.filter(d => !q || (d.title || '').includes(q));
        const sh = shared.filter(d => !q || (d.title || '').includes(q));
        list.textContent = '';
        if (!shown.length) list.append(h('li.empty', docs.length ? 'אין מסמך כזה' : 'עוד אין מסמכים. "+ מסמך" כדי להתחיל.'));
        for (const d of shown) list.append(card(d, d.updated_by ? `נערך על ידי ${d.updated_by}` : ''));
        // משותף איתי (שלב 5): מסמכים של אחרים, עם התפקיד שלי והבעלים
        if (sh.length) list.append(h('li.list-head', 'משותף איתי'));
        for (const d of sh) list.append(card(d, `${ROLE_TEXT[d.role] || ''} · של ${d.owner || 'משתמש אחר'}`));
    };
    search.addEventListener('input', paint);
    try {
        const r = await api('my_docs');
        docs = r.docs;
        shared = r.shared || [];
        paint();
    } catch (e) {
        list.textContent = '';
        list.append(h('li.error', e.message));
    }
}

// ---------- סל המחזור ----------
async function trashView() {
    document.body.classList.remove('editing');
    header({ back: true, title: 'סל המחזור', sub: 'מסמך שנמחק נשמר כאן 30 יום, ואז נמחק לצמיתות' });
    const list = h('ul.list', h('li.empty', 'טוען…'));
    main.textContent = '';
    main.append(list);
    const paint = r => {
        list.textContent = '';
        if (!r.docs.length) list.append(h('li.empty', 'הסל ריק.'));
        for (const d of r.docs) {
            const left = Math.max(0, Math.ceil((d.purge_at * 1000 - Date.now()) / 86400000));
            const restore = h('button.btn.sm', { type: 'button', onclick: async () => {
                try { await api('my_restore', { doc: d.doc }); toast(`"${d.title || 'ללא שם'}" שוחזר`); load(); } catch (e) { toast(e.message, true); }
            } }, 'שחזור');
            const purge = h('button.btn.sm.danger', { type: 'button', onclick: async () => {
                if (!confirm(`למחוק את "${d.title || 'ללא שם'}" לצמיתות? אי אפשר לבטל.`)) return;
                try { await api('my_purge', { doc: d.doc }); toast('נמחק לצמיתות'); load(); } catch (e) { toast(e.message, true); }
            } }, 'מחיקה לצמיתות');
            list.append(h('li.card.static',
                h('span.doc-ic', { 'aria-hidden': 'true' }, '📄'),
                h('div.body', h('div.l1', h('span', d.title || 'ללא שם'), h('span.t', 'נמחק ' + when(d.deleted_at))),
                    h('div.l2', left > 0 ? `יימחק לצמיתות בעוד ${left} ${left === 1 ? 'יום' : 'ימים'}` : 'יימחק לצמיתות היום')),
                h('div.acts', restore, purge)));
        }
    };
    const load = async () => {
        try { paint(await api('my_trash')); } catch (e) { list.textContent = ''; list.append(h('li.error', e.message)); }
    };
    load();
}

async function newDoc() {
    try {
        const r = await api('my_new', { title: 'מסמך חדש' });
        location.hash = '#/d/' + r.doc;
    } catch (e) { toast(e.message, true); }
}

async function importFile(e) {
    const f = e.target.files[0];
    e.target.value = '';
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    toast('מייבא…');
    try {
        const r = await fetch('api.php?action=my_new&title=', { method: 'POST', body: withTitle(fd, f.name.replace(/\.(docx|odt|html?|txt|md)$/i, '')), headers: { 'X-WP': '1', 'X-Tab': tab } });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'הייבוא נכשל');
        importReport = d.report || [];
        location.hash = '#/d/' + d.doc;
    } catch (ex) { toast(ex.message, true); }
}
function withTitle(fd, t) { fd.append('title', t); return fd; }

/** דוח הייבוא — אחרי שהמסמך המיובא נפתח: מה לא עבר בדיוק. בלי הערות — רק הודעה קצרה. */
let importReport = null;
function showImportReport() {
    const rep = importReport;
    importReport = null;
    if (!rep) return;
    if (!rep.length) { toast('המסמך יובא במלואו'); return; }
    sheet('דוח ייבוא', body => {
        body.classList.add('import-report');
        body.append(h('p', 'המסמך יובא. אלה הדברים שלא עברו בדיוק כמו ב-Word:'),
            h('ul', rep.map(r => h('li', h('b', r.label), r.key === 'fonts' ? '' : ` (${r.count})`, h('span', ' — ' + r.detail)))));
    }, () => true, 'הבנתי');
}

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
    const cur = current = { id, can: d.can, title: d.title, role: d.role || 'owner' };
    // שומרים — עורך, וגם מעיר (רק הערות; השרת בודק)
    const writable = () => cur.can === 'edit' || cur.can === 'comment';
    header({ back: true, title: titleIn, actions: [pagesEl, state, h('button.icon-btn', { 'aria-label': 'עוד', title: 'עוד', onclick: e => docMenu(e.currentTarget) }, '⋯')] });
    titleIn.readOnly = !['owner', 'edit'].includes(cur.role);
    titleIn.addEventListener('change', async () => {
        try { await api('my_rename', { doc: id, title: titleIn.value }); cur.title = titleIn.value; } catch (e) { toast(e.message, true); }
    });

    main.textContent = '';
    const banner = h('div.banner', { hidden: true });
    const root = h('div');
    main.append(banner, root);
    main.classList.add('editor-main');
    const ed = createWordEditor(root, {
        json: d.json, page: d.page, editable: d.can === 'edit', user: me,
        // מעיר: המסמך לקריאה בלבד, ההערות — כן
        commentOnly: () => cur.can === 'comment',
        // מקטעים עם מספר טורים שונה — ההדפסה מה-PDF של השרת (שם הטורים נפרסים בכל מקטע לחוד)
        printPdf: () => download('pdf', 'להדפסה')(),
        upload: async file => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('doc', id);
            return api('my_upload', fd, true);
        },
        versions: {
            list: async () => (await api('my_versions', { doc: id })).versions,
            get: vid => api('my_version', { doc: id, id: vid }),
            // השוואה (כמו ב-Word): מסמך חדש — הגרסה מול המצב הנוכחי (שנשמר קודם), ההבדלים כמעקב שינויים
            compare: async vid => {
                if ((await ensureSaved(cur, 'ההשוואה')) !== 'ok') return false;
                const r = await api('my_compare', { doc: id, id: vid });
                location.hash = '#/d/' + r.doc;
                return true;
            },
            // שחזור — פעולה אחת בשרת (ביקורת 04, R07): קודם כל השינויים נשמרים, ואז השרת רושם את המצב הנוכחי ואת
            // המשוחזר כשתי גרסאות נפרדות (לא מתאחדות). מחזיר false כשלא נשמר — ואז לא משחזרים
            restore: async vid => {
                if ((await ensureSaved(cur, 'השחזור')) !== 'ok') return false;
                const r = await api('my_version_restore', { doc: id, id: vid, base_version: cur.saver.version });
                ed.setContent(r.json);
                cur.saver.reset(r.version);
                return true;
            },
        },
        // כל שינוי — כולל הגדרות עמוד וסגנונות, שנמצאים בתוך המסמך — עובר לשמירה
        onUpdate: () => { if (cur.saver && writable()) cur.saver.changed(); },
        // שמירה ידנית (💾 / Ctrl+S): שומרים עכשיו, בלי לחכות להשהיה של השמירה האוטומטית
        save: async () => {
            const r = await cur.save?.();
            if (r?.ok) toast('נשמר');
        },
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
            state.textContent = writable() || s !== 'saved' ? STATE_TEXT[s] : readOnlyText();
            state.classList.toggle('bad', ['offline', 'conflict', 'error'].includes(s));
            // "שמורים במכשיר" — רק כשהטיוטה באמת נכתבה (ביקורת 03, F01)
            if (s === 'conflict') showBanner('המסמך השתנה במקום אחר. ' + (saver.local === 'saved' ? 'השינויים שלך שמורים במכשיר.' : 'השינויים שלך לא נשמרו במכשיר — הורידו עותק.'),
                ['טעינה מחדש', reloadFromServer], ['הורדת העותק שלי', () => downloadLocalCopy(ed.getJSON(), cur.title)]);
            else if (s === 'locked') lockedBy(info.message || 'המסמך פתוח לעריכה אצל משתמש אחר');
            else if (s === 'error' && info.code === 'schema_newer') showBanner(info.message, ['רענון', () => location.reload()]);
            else if (s === 'error') toast(info.message || 'השמירה נכשלה', true);
            else if (s === 'saved' && banner.dataset.kind === 'offline') hideBanner();
            if (s === 'offline') showBanner(saver.local === 'failed' ? 'אין חיבור לשרת, ואי אפשר לשמור במכשיר (האחסון חסום או מלא). הורידו עותק כדי לא לאבד שינויים.'
                : 'אין חיבור לשרת. ממשיכים לעבוד — הכול נשמר במכשיר ויישלח כשהחיבור יחזור.', ['הורדת עותק', () => downloadLocalCopy(ed.getJSON(), cur.title)], 'offline');
            // כתיבת טיוטה במכשיר נכשלה — חיווי (לא מבטיחים "שמור במכשיר" כשלא)
            if (info.localFailed && s !== 'saved') showBanner('אי אפשר לשמור טיוטה במכשיר (האחסון חסום או מלא). השינויים נשמרים רק בשרת — כשיש חיבור.',
                ['הורדת עותק', () => downloadLocalCopy(ed.getJSON(), cur.title)], 'local');
            else if (info.localFailed === false && banner.dataset.kind === 'local') hideBanner();
        },
    });
    state.textContent = writable() ? STATE_TEXT.saved : readOnlyText();
    cur.save = () => saver.flush();
    function readOnlyText() { return cur.role === 'view' ? 'צפייה בלבד' : 'קריאה בלבד'; }
    showImportReport();

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
        state.textContent = readOnlyText();
        showBanner(msg + ' — פתוח לקריאה בלבד.' + (saver.dirty ? (saver.local === 'saved' ? ' השינויים שלך שמורים במכשיר.' : ' השינויים שלך לא נשמרו במכשיר.') : ''),
            saver.dirty ? ['הורדת השינויים', () => downloadLocalCopy(ed.getJSON(), cur.title)] : null);
    }
    async function reloadFromServer() {
        // ביקורת 03, F09: טעינה מחדש אחרי התנגשות לא מוחקת את העבודה המקומית — היא נשמרת בנפרד עד החלטה
        const kept = saver.dirty && await saver.keepConflictCopy();
        const fresh = await api('my_open', { doc: id });
        ed.setContent(fresh.json);
        cur.can = fresh.can;
        ed.setEditable(cur.can === 'edit');
        saver.reset(fresh.version);
        hideBanner();
        if (!writable() && fresh.lock) lockedBy(`פתוח לעריכה אצל ${fresh.lock.by}`);
        else if (kept) conflictCopy();
    }
    /** עותק מקומי מהתנגשות (draftKey:conflict) — נשאר עד הורדה / מחיקה מפורשת, גם אחרי סגירה ופתיחה. */
    async function conflictCopy() {
        const c = await drafts.get(draftKey + ':conflict');
        if (!c?.json || current !== cur) return;
        showBanner(`הגרסה שלך מלפני הטעינה מחדש (${when(Math.round(c.at / 1000))}) שמורה בנפרד במכשיר.`,
            ['הורדה', () => downloadLocalCopy(c.json, cur.title + ' (הגרסה שלי)')],
            ['מחיקה', () => { if (confirm('למחוק את הגרסה המקומית? אי אפשר לבטל.')) { drafts.del(draftKey + ':conflict'); hideBanner(); } }], 'conflict-copy');
    }
    if (!writable() && d.lock) lockedBy(`פתוח לעריכה אצל ${d.lock.by}`);
    conflictCopy();

    // טיוטה שנשארה במכשיר (לשונית שנסגרה, ניתוק, קריסה) — מציעים לשחזר
    findDraft(draftKey, JSON.stringify(ed.getJSON())).then(dr => {
        if (!dr || current !== cur) return;
        const changedSince = dr.base_version !== d.version;
        const at = when(Math.round(dr.at / 1000));
        if (!writable()) {
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
            if (l.ok && !writable() && cur.role !== 'view') {
                showBanner('המסמך התפנה לעריכה.', ['מעבר לעריכה', reloadFromServer]);
            } else if (!l.ok && writable()) {
                lockedBy(`פתוח לעריכה אצל ${l.lock?.by || 'משתמש אחר'}`);
            }
        } catch { /* רשת — ננסה בדופק הבא */ }
    }, 30000);

    /** לפני ייצוא / שכפול: שומרים קודם, ואם לא הצליח — לא ממשיכים בשקט עם גרסה ישנה. */
    const saved = async what => (await ensureSaved(cur, what)) === 'ok';
    // PDF — בתצוגת השינויים שבעורך (סופי / מקור / מחברים מוסתרים), כמו ההדפסה
    const download = (fmt, what) => async () => { if (await saved(what)) location.href = `api.php?action=my_export&format=${fmt}&doc=${id}` + (fmt === 'pdf' ? ed.exportQuery?.() || '' : ''); };
    function docMenu(anchor) {
        const items = [
            ['⬇️', 'הורדה כ-Word', download('docx', 'ההורדה')],
            ['📕', 'הורדה כ-PDF', download('pdf', 'ההורדה')],
            ['🖨', 'הדפסה', () => ed.print()],
            ['📝', 'הורדה כטקסט', download('text', 'ההורדה')],
            ['🧾', 'גיבוי (JSON)', download('json', 'הגיבוי')],
            // שיתוף — רק הבעלים; מי ששותף איתו רואה מה התפקיד שלו
            cur.role === 'owner' ? ['👥', 'שיתוף…', () => shareDialog(id)] : null,
            ['📑', 'שכפול', async () => { if (!(await saved('השכפול'))) return; const r = await api('my_copy', { doc: id }); location.hash = '#/d/' + r.doc; }],
            // לסל — רק הבעלים
            cur.role !== 'owner' ? null : ['🗑', 'העברה לסל המחזור', async () => {
                // קודם שומרים — כך שחזור מהסל מחזיר את המסמך כמו שהיה ברגע המחיקה
                if (!(await saved('המחיקה'))) return;
                const title = cur.title || 'ללא שם';
                const r = await api('my_delete', { doc: id });
                saver.stop();
                drafts.del(draftKey);
                cur.deleted = true;
                location.hash = '#/';
                toast(`"${title}" הועבר לסל המחזור (${r.days} יום)`, false, { label: 'ביטול', run: async () => {
                    try { await api('my_restore', { doc: id }); location.hash = '#/d/' + id; } catch (e) { toast(e.message, true); }
                } });
            }],
        ];
        popMenu(anchor, items.filter(Boolean));
    }
}

/**
 * מוודא שהכול נשמר בשרת. אם לא — שואל: לנסות שוב / להוריד עותק / (בסגירה) לצאת בכל זאת.
 * מחזיר 'ok' | 'leave' | 'stay'. השינויים שלא נשמרו נשארים בכל מקרה כטיוטה במכשיר.
 */
async function ensureSaved(cur, what, leaving = false) {
    if (!cur.saver || cur.deleted || !cur.saver.dirty) return 'ok';
    for (;;) {
        const r = await cur.saver.flushUntilClean();
        if (r.ok && !r.dirty) return 'ok';
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
    if (canSave(cur) && (await ensureSaved(cur, 'היציאה', true)) === 'stay') {
        history.replaceState(null, '', '#/d/' + cur.id);   // נשארים במסמך
        throw Object.assign(new Error('stay'), { stay: true });
    }
    current = null;
    clearInterval(cur.beat);
    cur.saver?.destroy();
    if (canSave(cur)) api('my_lock', { doc: cur.id, op: 'release' }).catch(() => {});
    cur.ed?.destroy();
    main.classList.remove('editor-main');
}

// סגירת לשונית / ניווט החוצה: הטיוטה כבר במכשיר; מנסים לשמור, משחררים את הנעילה, ומבקשים מהדפדפן לשאול
window.addEventListener('beforeunload', e => {
    if (current?.saver?.dirty) { current.saver.flush(); e.preventDefault(); }
});
window.addEventListener('pagehide', () => {
    const cur = current;
    if (!canSave(cur)) return;
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

// ---------- שיתוף (שלב 5) ----------
/**
 * חלון השיתוף (רק לבעלים): מי שהמסמך שותף איתו — מייל, שם (כשכבר נכנס לעורך), התפקיד (צפייה / הערות / עריכה) והסרה;
 * ושיתוף חדש לפי מייל — פעיל גם לפני שבעל המייל נכנס לעורך בפעם הראשונה.
 */
async function shareDialog(id) {
    let { shares, link } = await api('my_shares', { doc: id });
    const rows = h('ul.share-list');
    const roleSel = (value, onchange) => h('select', { 'aria-label': 'תפקיד', onchange },
        ['view', 'comment', 'edit'].map(r => h('option', { value: r, selected: r === value }, ROLE_TEXT[r])));
    const set = async (email, role) => {
        try { shares = (await api('my_share', { doc: id, email, role })).shares; paint(); return true; }
        catch (e) { toast(e.message, true); return false; }
    };
    const paint = () => {
        rows.textContent = '';
        if (!shares.length) rows.append(h('li.empty', 'המסמך עוד לא שותף עם אף אחד.'));
        for (const x of shares) {
            rows.append(h('li.share-row',
                h('div.who', h('strong', x.name || x.email), x.name ? h('small', x.email) : h('small', x.joined ? '' : 'עוד לא נכנס לעורך — השיתוף יחכה לו')),
                roleSel(x.role, e => set(x.email, e.target.value)),
                h('button.btn.sm.ghost', { type: 'button', 'aria-label': 'הסרת השיתוף עם ' + x.email, onclick: () => set(x.email, '') }, 'הסרה')));
        }
    };
    // קישור עריכה לזר (lib/links.php): מסמך אחד, בלי חשבון — באישור מנהל העורך
    const linkBox = h('div.link-box');
    const linkOp = async op => {
        if (op === 'revoke' && !confirm('לבטל את הקישור? מי שקיבל אותו לא יוכל להמשיך לערוך.')) return;
        try { link = (await api('my_link', { doc: id, op })).link; paintLink(); } catch (e) { toast(e.message, true); }
    };
    const paintLink = () => {
        linkBox.textContent = '';
        const btn = (label, op, cls = 'ghost') => h('button.btn.sm.' + cls, { type: 'button', onclick: () => linkOp(op) }, label);
        if (!link) {
            linkBox.append(h('p.hint', 'מי שיקבל את הקישור יוכל לערוך את המסמך הזה בלבד, בלי חשבון. הקישור פעיל אחרי אישור של מנהל העורך.'),
                btn('🔗 בקשת קישור עריכה', 'request', 'primary'));
        } else if (link.status === 'pending') {
            linkBox.append(h('p', h('span.chip.warn', 'ממתין לאישור'), ' מנהל העורך יאשר את הקישור, ואז הוא יופיע כאן.'), btn('ביטול הבקשה', 'revoke'));
        } else if (link.status === 'expired') {
            linkBox.append(h('p', h('span.chip.bad', 'פג תוקף'), ' הקישור כבר לא עובד.'), btn('בקשת קישור חדש', 'request', 'primary'), btn('הסרה', 'revoke'));
        } else {
            const url = h('input.link-url', { value: link.url, readOnly: true, dir: 'ltr', 'aria-label': 'קישור העריכה', onfocus: e => e.target.select() });
            linkBox.append(h('p', h('span.chip.ok', 'פעיל'), ` עד ${new Date(link.expires_at * 1000).toLocaleDateString('he-IL')}. מי שיש לו את הקישור — עורך.`),
                h('div.share-add', url, h('button.btn.sm', { type: 'button', onclick: async () => {
                    try { await navigator.clipboard.writeText(link.url); toast('הקישור הועתק'); } catch { url.focus(); url.select(); }
                } }, 'העתקה')),
                h('div.row', btn('קישור חדש (הישן יפסיק לעבוד)', 'request'), btn('ביטול הקישור', 'revoke')));
        }
    };
    const email = h('input', { type: 'email', name: 'email', placeholder: 'מייל', autocomplete: 'off', dir: 'ltr', 'aria-label': 'מייל לשיתוף' });
    const role = roleSel('edit');
    paint();
    paintLink();
    sheet('שיתוף המסמך', body => {
        body.append(h('section.group', h('h3', 'שיתוף עם'), h('div.share-add', email, role)),
            h('p.hint', 'מי ששיתפת איתו רואה את המסמך ב"משותף איתי". עריכה — כמוך (חוץ משיתוף ומחיקה); הערות — קריאה והוספת הערות; צפייה — קריאה והורדה.'),
            h('section.group', h('h3', 'משותף כרגע'), rows),
            h('section.group', h('h3', 'קישור עריכה לזר'), linkBox));
    }, async () => {
        const v = email.value.trim();
        if (!v) return true;   // בלי מייל — "שיתוף" רק סוגר
        if (await set(v, role.value)) { email.value = ''; toast('שותף עם ' + v); }
        return false;          // החלון נשאר פתוח — לראות את הרשימה ולשתף עוד
    }, 'שיתוף');
}

// ---------- לקוחות ההטמעה (מנהל העורך) ----------
async function adminView() {
    document.body.classList.remove('editing');
    header({ back: true, title: 'ניהול העורך', sub: 'קישורי עריכה לזרים, ואפליקציות שמטמיעות את העורך' });
    const list = h('ul.list', h('li.empty', 'טוען…'));
    const links = h('ul.list.admin-links', h('li.empty', 'טוען…'));
    main.textContent = '';
    main.append(h('h2.list-title', 'קישורי עריכה לזרים'),
        h('p.hint', 'בעל מסמך מבקש קישור — ומי שמקבל אותו עורך את המסמך הזה בלבד, בלי חשבון, עד 14 יום מהאישור.'), links,
        h('h2.list-title', 'לקוחות הטמעה'),
        h('p.hint', 'אפליקציה נרשמת לבד. מאותו דומיין ומהתיקייה שלה — פעילה מיד; מדומיין אחר, או אחרי שינוי כתובות — ממתינה לאישור כאן. ',
        h('a', { href: GUIDE, target: '_blank', rel: 'noopener' }, 'מדריך ההתממשקות')), list);
    const paintLinks = all => {
        links.textContent = '';
        if (!all.length) links.append(h('li.empty', 'אין קישורים'));
        for (const l of all) {
            const chip = { active: ['ok', 'פעיל'], pending: ['warn', 'ממתין לאישור'], expired: ['bad', 'פג תוקף'] }[l.status];
            const decide = async approve => {
                try { paintLinks((await api('admin_link_set', { doc: l.doc, approve })).links); } catch (e) { toast(e.message, true); }
            };
            links.append(h('li.card', { style: { cursor: 'default' } },
                h('span.doc-ic', { 'aria-hidden': 'true' }, '🔗'),
                h('div.body', h('div.l1', h('span', l.title || 'ללא שם'), h('span.chip.' + chip[0], chip[1])),
                    h('div.l2', `של ${l.owner || '—'} · ` + (l.status === 'active' ? `עד ${new Date(l.expires_at * 1000).toLocaleDateString('he-IL')}` : `ביקש ${when(l.created_at)}`))),
                l.status === 'pending' ? h('button.btn.sm.primary', { onclick: () => decide(true) }, 'אישור') : null,
                h('button.btn.sm.ghost', { onclick: () => (l.status !== 'active' || confirm(`לבטל את הקישור ל"${l.title || 'ללא שם'}"? מי שקיבל אותו לא יוכל להמשיך לערוך.`)) && decide(false) },
                    l.status === 'pending' ? 'דחייה' : 'ביטול')));
        }
    };
    api('admin_links').then(r => paintLinks(r.links), e => { links.textContent = ''; links.append(h('li.error', e.message)); });
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
