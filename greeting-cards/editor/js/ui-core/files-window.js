/**
 * ui-core/files-window.js — 📁 "המסמכים שלי": חלון קבצים אחד לניהול, לפתיחה ולשמירה. אחד לכל האפליקציות.
 * ההכרעה: creative-suite/docs/requests/account-menu.md (סעיף 3, פריט 2). לשונית לכל מקור: "באפליקציה" + כל ענן מחובר.
 *
 *   const sources = [
 *       { id: 'app', title: 'באפליקציה', icon: '📱', ...myLibrary },          // הספרייה של האפליקציה — מתאם (למטה)
 *       suiteSource(drives),                                                  // ☁️ ענן החבילה — מהמודול של הכוננים
 *   ];
 *   openDocuments(sources, { onOpen: (item, src) => openDoc(item) });       // ניהול (מתפריט המשתמש)
 *   const got = await pickFile(sources, { accept: ['.mbe', '.png'] });       // פתיחה → { source, item } | null
 *   const at  = await saveFile(sources, { name: 'כרטיס', ext: 'mbe', blob }); // שמירה → { source, item } | null
 *
 * המתאם (מקור): כל פעולה רשות, מלבד list. פעולה שאין — הכפתור שלה לא מוצג.
 *   list(folderId | null) → { items: [item], path?: [{ id, name }] }    path — מהשורש, לפירורי הלחם
 *   read(id) → Blob · write(name, blob, { parent, replace }) → item · mkdir(name, parent) → item
 *   rename(id, name) · move(id, parent) · copy(id) → item · remove(id) — לסל
 *   versions(id) → [{ id, label, date }] · restore(id, versionId)
 *   search(q) → [item]          (בלי — סינון של התיקייה הנוכחית)
 *   connected?() → bool · connect?() — לשונית של ענן לא מחובר מציגה "חיבור"
 * item: { id, name, kind: 'file' | 'folder', size?, updated? (ISO / ms), icon? }
 *
 * שגיאה עם code 'not-connected' (DriveError של הכוננים) — הלשונית מציגה "חיבור" במקום שגיאה.
 */
import { h } from './dom.js';
import { sheet, confirm, prompt, choose } from './dialog.js';
import { menu } from './menu.js';
import { toast } from './toast.js';

const ICON = { folder: '📁', mbe: '🧩', png: '🖼', jpg: '🖼', jpeg: '🖼', svg: '✒️', pdf: '📄', docx: '📝', txt: '📃' };
const ext = name => (/\.([a-z0-9]+)$/i.exec(name || '')?.[1] || '').toLowerCase();
/** שם בשורה: הבסיס מבודד (<bdi>), והסיומת הלטינית מבודדת משמאל לימין — "סידור.mbe" לא מתהפך (לקח מהעימוד). */
const nameParts = it => {
    const e = it.kind === 'folder' ? '' : ext(it.name);
    return e ? [h('bdi', it.name.slice(0, -e.length - 1)), h('bdi.fw-ext', { dir: 'ltr' }, '.' + e)] : [h('bdi', it.name)];
};
const iconOf = it => it.icon || (it.kind === 'folder' ? ICON.folder : ICON[ext(it.name)] || '📄');

export function fmtSize(n) {
    if (n == null) return '';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return `${n < 10 && i ? n.toFixed(1) : Math.round(n)} ${u[i]}`;
}

export function fmtDate(d) {
    if (!d) return '';
    const t = new Date(d);
    if (isNaN(t)) return '';
    const today = new Date().toDateString() === t.toDateString();
    return today ? t.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' })
        : t.toLocaleDateString('he-IL', { day: 'numeric', month: 'short', year: t.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
}

/** מתאם לענן החבילה מתוך המודול של הכוננים (suite-cloud/drives): createDrives(...) */
export function suiteSource(drives, { title = 'ענן החבילה', icon = '☁️' } = {}) {
    const s = drives.suite;
    const item = n => ({ id: n.id, name: n.name, kind: n.kind, size: n.size, updated: n.updated_at, mime: n.mime, rev: n.rev });
    let connected = null;
    return {
        id: 'suite', title, icon,
        async connected() { if (connected === null) connected = !!(await drives.status()).suite?.connected; return connected; },
        connect: () => drives.connect(),
        async list(folder) { const r = await s.list(folder || undefined); return { items: r.items.map(item), path: r.path?.map(p => ({ id: p.id, name: p.name })) }; },
        read: id => s.read(id),
        write: async (name, blob, { parent, replace } = {}) => item(await s.upload(new File([blob], name, { type: blob.type }), { parent, onConflict: replace ? 'replace' : 'rename' })),
        mkdir: async (name, parent) => item(await s.mkdir(name, parent)),
        rename: (id, name) => s.rename(id, name),
        move: (id, parent) => s.move(id, parent),
        remove: id => s.trash(id),
    };
}

/**
 * החלון. mode: 'manage' | 'open' | 'save'. מחזיר Promise שנפתר כשהחלון נסגר:
 * open → { source, item } | null; save → { source, item } | null; manage → null.
 */
export function filesWindow(sources, { mode = 'manage', title, accept = [], name = '', ext: saveExt = '', blob = null, getBlob = null, onOpen = null, start } = {}) {
    sources = sources.filter(Boolean);
    return new Promise(resolve => {
        let result = null;
        let src = sources.find(s => s.id === start) || sources[0];
        let folder = null, path = [], items = [], query = '';
        const okType = it => it.kind === 'folder' || !accept.length || accept.some(a => a.startsWith('.') ? it.name.toLowerCase().endsWith(a) : (it.mime || '').startsWith(a));

        const tabsEl = h('div.seg-tabs', { role: 'tablist', 'aria-label': 'מקומות' });
        const crumbs = h('nav.fw-crumbs', { 'aria-label': 'תיקייה' });
        const search = h('input', { type: 'search', placeholder: 'חיפוש', 'aria-label': 'חיפוש', oninput: () => { query = search.value.trim(); render(); } });
        const newFolder = h('button.btn.ghost.sm', { type: 'button', onclick: mkdir }, '＋ תיקייה');
        const listEl = h('ul.list.fw-list', { 'aria-live': 'polite' });
        const nameIn = h('input', { value: name, 'aria-label': 'שם הקובץ', autocomplete: 'off' });
        const saveBar = mode === 'save' ? h('div.fw-save', h('label.f', h('span', 'שם'), h('div.fw-name', nameIn, saveExt ? h('span.muted', { dir: 'ltr' }, '.' + saveExt) : null)),
            h('button.btn.primary', { type: 'button', onclick: doSave }, 'שמירה כאן')) : null;

        const s = sheet({
            title: title || { manage: 'המסמכים שלי', open: 'פתיחה', save: 'שמירה בשם' }[mode],
            full: true, wide: true,
            body: [sources.length > 1 ? tabsEl : null, h('div.toolbar', search, newFolder), crumbs, listEl, saveBar],
            onClose: () => resolve(result),
        });

        function tabs() {
            tabsEl.replaceChildren(...sources.map(x => h('button', { type: 'button', role: 'tab', 'aria-selected': String(x === src),
                onclick: () => { src = x; folder = null; query = ''; search.value = ''; load(); } }, `${x.icon || ''} ${x.title}`)));
        }

        async function load() {
            tabs();
            newFolder.hidden = !src.mkdir;
            listEl.replaceChildren(h('li.empty', 'טוען…'));
            crumbs.replaceChildren();
            try {
                if (src.connected && !(await src.connected())) return notConnected();
                const r = await src.list(folder);
                items = r.items || [];
                path = r.path || [];
                render();
            } catch (e) {
                if (e?.code === 'not-connected') return notConnected();
                listEl.replaceChildren(h('li.empty.error', e?.message || String(e)));
            }
        }

        function notConnected() {
            listEl.replaceChildren(h('li.empty', h('p', `${src.title} לא מחובר.`),
                src.connect ? h('button.btn.primary', { type: 'button', onclick: () => src.connect() }, 'חיבור') : null));
        }

        function render() {
            crumbs.replaceChildren(h('button.btn.sm', { type: 'button', onclick: () => go(null) }, src.title),
                ...path.flatMap(p => [h('span.muted', { 'aria-hidden': 'true' }, '‹'), h('button.btn.sm', { type: 'button', onclick: () => go(p.id) }, h('bdi', p.name))]));
            let shown = items.filter(okType);
            if (query) shown = shown.filter(it => it.name.toLowerCase().includes(query.toLowerCase()));
            shown.sort((a, b) => (a.kind === 'folder' ? 0 : 1) - (b.kind === 'folder' ? 0 : 1) || a.name.localeCompare(b.name, 'he'));
            if (!shown.length) { listEl.replaceChildren(h('li.empty', query ? 'לא נמצא' : 'אין כאן קבצים')); return; }
            listEl.replaceChildren(...shown.map(row));
        }

        function row(it) {
            const parts = [it.kind === 'folder' ? 'תיקייה' : fmtSize(it.size), fmtDate(it.updated)].filter(Boolean);
            const sub = parts.length ? parts.flatMap((p, i) => [i ? ' · ' : null, h('bdi', p)]) : null;
            const more = actions(it).length ? h('button.icon-btn', { type: 'button', 'aria-label': `פעולות: ${it.name}`, 'aria-haspopup': 'menu',
                onclick: e => { e.stopPropagation(); menu(e.currentTarget, actions(it)); } }, '⋯') : null;
            return h('li.card', { tabindex: '0', _item: it, onclick: () => pick(it), onkeydown: e => { if (e.key === 'Enter') pick(it); } },
                h('span.use-ic', { 'aria-hidden': 'true' }, iconOf(it)),
                h('div.body', h('div.l1', h('span', ...nameParts(it))), sub ? h('div.l2', sub) : null),
                more);
        }

        function actions(it) {
            return [
                src.rename && { icon: '✏️', label: 'שינוי שם', run: async () => { const n = await prompt('שם חדש', { value: it.name, select: 'name' }); if (n && n !== it.name) { await src.rename(it.id, n); await load(); } } },
                src.copy && it.kind === 'file' && { icon: '📄', label: 'שכפול', run: async () => { await src.copy(it.id); await load(); } },
                src.move && folders().length && { icon: '📂', label: 'העברה', run: async () => {
                    const to = await choose('העברה אל', it.name, [['__root', src.title], ...folders().filter(f => f.id !== it.id).map(f => [f.id, '📁 ' + f.name])]);
                    if (to) { await src.move(it.id, to === '__root' ? null : to); await load(); } } },
                src.versions && it.kind === 'file' && { icon: '🕘', label: 'גרסאות', run: () => versions(it) },
                src.remove && '-',
                src.remove && { icon: '🗑', label: 'מחיקה', danger: true, run: async () => {
                    if (!await confirm(`להעביר את "${it.name}" לסל?`, { ok: 'מחיקה', danger: true })) return;
                    await src.remove(it.id); toast('הועבר לסל'); await load(); } },
            ].filter(Boolean);
        }

        const folders = () => items.filter(i => i.kind === 'folder');

        async function versions(it) {
            const list = await src.versions(it.id);
            const v = await choose(`גרסאות — ${it.name}`, list.length ? 'בחירה בגרסה משחזרת אותה' : 'אין גרסאות קודמות',
                list.map(x => [x.id, `${x.label || ''} ${fmtDate(x.date)}`.trim(), 'ghost']));
            if (v && src.restore) { await src.restore(it.id, v); toast('שוחזר'); await load(); }
        }

        async function mkdir() {
            const n = await prompt('שם התיקייה', { value: 'תיקייה חדשה' });
            if (!n) return;
            try { await src.mkdir(n, folder); await load(); } catch (e) { toast(e?.message || String(e), true); }
        }

        function go(id) { folder = id; query = ''; search.value = ''; load(); }

        async function pick(it) {
            if (it.kind === 'folder') return go(it.id);
            if (mode === 'save') { nameIn.value = it.name.replace(/\.[^.]+$/, ''); nameIn.focus(); return; }
            if (mode === 'open') { result = { source: src, item: it }; s.close(); return; }
            if (onOpen) { s.close(); onOpen(it, src); return; }
            // ניהול בלי onOpen — הלחיצה פותחת את תפריט הפעולות של השורה (לקח מעורך התמלילים)
            const list = actions(it), at = [...listEl.children].find(li => li._item === it);
            if (list.length) menu(at?.querySelector('.icon-btn') || at || listEl, list);
        }

        async function doSave() {
            const base = nameIn.value.trim();
            if (!base) { nameIn.focus(); return; }
            if (!src.write) { toast(`אי אפשר לשמור ב${src.title}`, true); return; }
            const full = saveExt && !base.toLowerCase().endsWith('.' + saveExt) ? `${base}.${saveExt}` : base;
            let replace = false;
            if (items.some(i => i.kind === 'file' && i.name === full)) {
                const c = await choose('כבר יש קובץ בשם הזה', full, [['replace', 'החלפה', 'danger'], ['both', 'לשמור את שניהם', 'primary']]);
                if (!c) return;
                replace = c === 'replace';
            }
            try {
                const data = blob || await getBlob?.();
                const item = await src.write(full, data, { parent: folder, replace });
                result = { source: src, item };
                s.close();
                toast('נשמר');
            } catch (e) { toast(e?.message || String(e), true); }
        }

        load();
    });
}

export const openDocuments = (sources, opts = {}) => filesWindow(sources, { ...opts, mode: 'manage' });
export const pickFile = (sources, opts = {}) => filesWindow(sources, { ...opts, mode: 'open' });
export const saveFile = (sources, opts = {}) => filesWindow(sources, { ...opts, mode: 'save' });
