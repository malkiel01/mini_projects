/**
 * 📁 "המסמכים שלי" (ui-core files-window.js, integration.md שורה 20): המתאם של ספריית העורך — לשונית "באפליקציה".
 * התיקיות, המסמכים, ההעברה והסל — של העורך עצמו (my_docs / my_folder / my_move / my_delete), כמו שהם.
 *
 *   openDocuments([librarySource(api)], { onOpen: item => location.hash = '#/d/' + item.id })
 *
 * מזהה של תיקייה — עם "f:" לפניו (במסמך — המזהה עצמו), כדי שפעולה אחת תדע על מה היא חלה.
 * קריאה (read) — המסמך כ-‎.mbe (פתיחה באפליקציה אחרת); כתיבה (write) — ייבוא כמסמך חדש בתיקייה (כמו "ייבוא מסמך").
 * גרסאות — בתוך המסמך (🕘 גרסאות): שחזור דורש את נעילת העריכה, ולא נעשה מכאן.
 */
const FOLDER = 'f:';
const isFolder = id => String(id).startsWith(FOLDER);
const raw = id => String(id).slice(FOLDER.length);
const noExt = name => String(name).replace(/\.(mbe|docx?|odt|rtf|html?|txt|md)$/i, '');

/** @param {(action: string, body?: object, isForm?: boolean) => Promise<any>} api — העטיפה של העורך (עם ה-CSRF) */
export function librarySource(api, { title = 'באפליקציה', icon = '📝' } = {}) {
    let data = null;   // my_docs האחרון — לפירורי הלחם ולחיפוש
    const load = async () => (data = await api('my_docs'));
    const docItem = d => ({ id: d.doc, name: d.title || 'ללא שם', kind: 'file', updated: (d.updated_at || 0) * 1000, icon: '📝' });
    const folderItem = f => ({ id: FOLDER + f.id, name: f.name, kind: 'folder' });
    const pathTo = id => {
        const byId = new Map((data?.folders || []).map(f => [f.id, f]));
        const out = [];
        for (let f = byId.get(id); f && out.length < 12; f = byId.get(f.parent)) out.unshift({ id: FOLDER + f.id, name: f.name });
        return out;
    };
    return {
        id: 'app', title, icon,
        async list(folder) {
            const at = folder ? raw(folder) : null;
            await load();
            const items = [
                ...data.folders.filter(f => (f.parent || null) === at).map(folderItem),
                ...data.docs.filter(d => (d.folder || null) === at).map(docItem),
            ];
            return { items, path: at ? pathTo(at) : [] };
        },
        async search(q) {
            if (!data) await load();
            const n = q.trim().toLowerCase();
            return data.docs.filter(d => (d.title || '').toLowerCase().includes(n)).map(docItem);
        },
        read: async id => {
            const r = await fetch(`api.php?action=my_export&format=mbe&doc=${encodeURIComponent(id)}`, { credentials: 'same-origin' });
            if (!r.ok) throw new Error('ההורדה נכשלה');
            return r.blob();
        },
        async write(name, blob, { parent } = {}) {
            const fd = new FormData();
            fd.append('file', new File([blob], name, { type: blob.type || 'application/octet-stream' }));
            fd.append('title', noExt(name));
            if (parent) fd.append('folder', raw(parent));
            const r = await api('my_new', fd, true);
            if (!r.doc) throw new Error('הקובץ חזר כגרסה של מסמך קיים — פתחו אותו מהרשימה');
            return { id: r.doc, name: noExt(name), kind: 'file', updated: Date.now(), icon: '📝' };
        },
        async mkdir(name, parent) {
            const r = await api('my_folder', { op: 'create', name, parent: parent ? raw(parent) : null });
            return { id: FOLDER + r.id, name, kind: 'folder' };
        },
        rename: (id, name) => isFolder(id) ? api('my_folder', { op: 'rename', id: raw(id), name }) : api('my_rename', { doc: id, title: noExt(name) }),
        move: (id, parent) => isFolder(id) ? api('my_folder', { op: 'move', id: raw(id), parent: parent ? raw(parent) : null })
            : api('my_move', { doc: id, folder: parent ? raw(parent) : null }),
        async copy(id) {
            if (isFolder(id)) throw new Error('העתקת תיקייה — לא כאן');
            const r = await api('my_copy', { doc: id });
            return { id: r.doc, name: '', kind: 'file' };
        },
        remove: id => isFolder(id) ? api('my_folder', { op: 'delete', id: raw(id) }) : api('my_delete', { doc: id }),
    };
}
