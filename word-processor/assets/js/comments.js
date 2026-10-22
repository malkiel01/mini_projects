/**
 * הערות על טווח טקסט (שלב 4) — כמו Comments ב-Word:
 * - הטווח: סימון comment (attrs.id) על הטקסט. כמה הערות יכולות לחפוף (excludes: '').
 * - השרשור: attrs.comments בשורש המסמך — { [id]: { author, date, text, done, replies: [{ author, date, text }] } } —
 *   נשמר עם המסמך, ונכנס לביטול / שחזור כמו כל שינוי.
 * - חלונית "הערות" בצד: כל השרשורים לפי הסדר במסמך — תשובה, "נפתר" / פתיחה מחדש, מחיקה; לחיצה — מעבר לטקסט.
 * הערה שהטקסט שלה נמחק כולו — לא מוצגת (ולא מיוצאת), כמו ב-Word.
 * בשרת: lib/export.php (comments.xml, commentsExtended.xml) ו-lib/import.php.
 */
import { Mark, Plugin, PluginKey } from '../../vendor/tiptap.js';

export const CommentMark = Mark.create({
    name: 'comment',
    inclusive: false,
    excludes: '',
    addAttributes() {
        return { id: { default: null, parseHTML: el => el.getAttribute('data-comment') } };
    },
    parseHTML() {
        return [{ tag: 'span[data-comment]' }];
    },
    renderHTML({ HTMLAttributes }) {
        return ['span', { 'data-comment': HTMLAttributes.id, class: 'wp-cmt' }, 0];
    },
    addProseMirrorPlugins() {
        // לחיצה על טקסט עם הערה — החלונית (createComments) מסמנת את השרשור
        const editor = this.editor;
        return [new Plugin({ key: new PluginKey('wpCommentClick'), props: { handleClick: (view, pos, ev) => { editor.storage.wpComments?.onClick?.(ev); return false; } } })];
    },
    addKeyboardShortcuts() {
        // הערה חדשה — Ctrl+Alt+M, כמו ב-Word
        return { 'Mod-Alt-m': () => { this.editor.storage.wpComments?.add?.(); return true; } };
    },
    addStorage() {
        return {};
    },
});

const newId = () => Math.random().toString(36).slice(2, 10).replace(/[^a-z0-9]/g, '') || String(Date.now());

/** השרשורים שהטווח שלהם עוד במסמך, לפי הסדר: [{ id, from, to, ...data }]. */
export function threads(state) {
    const data = state.doc.attrs.comments || {};
    const at = new Map();
    state.doc.descendants((node, pos) => {
        for (const m of node.marks) {
            if (m.type.name !== 'comment' || !data[m.attrs.id]) continue;
            const r = at.get(m.attrs.id);
            if (r) r.to = pos + node.nodeSize;
            else at.set(m.attrs.id, { from: pos, to: pos + node.nodeSize });
        }
    });
    return [...at].map(([id, r]) => ({ id, ...r, ...data[id] }));
}

/**
 * החלונית והפעולות. user — { name } של מי שעובד עכשיו (המחבר של הערות ותשובות).
 * h — יוצר האלמנטים של העורך.
 */
export function createComments({ editor, h, user, onChange }) {
    const author = () => (user?.name || '').trim() || 'אני';
    const list = h('div.cm-list', { role: 'list' });
    const empty = h('p.cm-empty', 'אין הערות במסמך. מסמנים טקסט ולוחצים "הערה חדשה" (Ctrl+Alt+M).');
    const doneStyle = h('style');
    const el = h('aside.wp-comments', { 'aria-label': 'הערות', hidden: true },
        h('div.nav-head', h('strong', 'הערות'), h('button.nav-close', { type: 'button', 'aria-label': 'סגירת חלונית ההערות', title: 'סגירה', onclick: () => { close(); onChange?.(); } }, '✕')),
        list, empty, doneStyle);
    let active = null;      // השרשור שהסמן בתוכו
    let draftFor = null;    // הערה חדשה שעוד לא נכתב בה טקסט — נמחקת אם יוצאים ממנה ריקה
    let timer = 0;

    const data = () => editor.state.doc.attrs.comments || {};
    /** שינוי בנתוני השרשורים — צעד ביטול אחד (ואפשר עם שינוי בסימונים באותה עסקה). */
    const commit = (fn, tr = editor.state.tr) => {
        const next = structuredClone(data());
        fn(next);
        tr.setDocAttribute('comments', Object.keys(next).length ? next : null).setMeta('closeHistory$', true);
        editor.view.dispatch(tr);
    };
    const now = () => new Date().toISOString().slice(0, 19) + 'Z';
    const fmt = iso => { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString('he-IL', { day: 'numeric', month: 'numeric', year: '2-digit', hour: '2-digit', minute: '2-digit' }); };

    /** הערה חדשה על הבחירה (בלי בחירה — על המילה שבה הסמן). */
    function add() {
        if (!editor.isEditable) return;
        let { from, to, empty: none } = editor.state.selection;
        if (none) {
            const $p = editor.state.selection.$from;
            const text = $p.parent.textContent;
            let a = $p.parentOffset, b = a;
            while (a > 0 && /\S/.test(text[a - 1])) a--;
            while (b < text.length && /\S/.test(text[b])) b++;
            if (a === b) return;
            from = $p.start() + a; to = $p.start() + b;
        }
        const id = newId();
        const tr = editor.state.tr.addMark(from, to, editor.schema.marks.comment.create({ id }));
        commit(c => { c[id] = { author: author(), date: now(), text: '', done: false, replies: [] }; }, tr);
        draftFor = id;
        open();
        paint();
        setActive(id);
        requestAnimationFrame(() => list.querySelector(`[data-id="${id}"] .cm-edit`)?.focus());
    }

    function remove(id) {
        const tr = editor.state.tr;
        editor.state.doc.descendants((node, pos) => {
            const m = node.marks.find(x => x.type.name === 'comment' && x.attrs.id === id);
            if (m) tr.removeMark(pos, pos + node.nodeSize, m);
        });
        commit(c => { delete c[id]; }, tr);
    }

    function go(t) {
        editor.chain().focus().setTextSelection({ from: t.from, to: t.to }).scrollIntoView().run();
    }

    function card(t) {
        const editing = !t.text && t.id === draftFor;
        const head = (a, d) => h('div.cm-head', h('span.cm-author', a), h('span.cm-date', fmt(d)));
        const node = h('div.cm-card', { role: 'listitem', 'data-id': t.id, tabindex: '-1' });
        node.classList.toggle('done', !!t.done);
        node.classList.toggle('on', t.id === active);
        node.append(head(t.author, t.date));
        if (editing) {
            const ta = h('textarea.cm-edit', { rows: 3, 'aria-label': 'טקסט ההערה', maxlength: 2000 });
            const save = () => {
                const v = ta.value.trim();
                draftFor = null;
                if (!v) { remove(t.id); return; }
                commit(c => { if (c[t.id]) c[t.id].text = v; });
            };
            ta.addEventListener('keydown', e => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); editor.view.focus(); }
                if (e.key === 'Escape') { e.preventDefault(); ta.value = ''; save(); editor.view.focus(); }
            });
            node.append(ta, h('div.cm-actions',
                h('button.btn.sm', { type: 'button', onclick: () => { save(); editor.view.focus(); } }, 'פרסום'),
                h('button.btn.sm.ghost', { type: 'button', onclick: () => { ta.value = ''; save(); editor.view.focus(); } }, 'ביטול')));
            return node;
        }
        node.append(h('div.cm-text', t.text));
        for (const r of t.replies || []) node.append(h('div.cm-reply', head(r.author, r.date), h('div.cm-text', r.text)));
        if (editor.isEditable) {
            const reply = h('input.cm-reply-in', { type: 'text', placeholder: 'תשובה…', 'aria-label': 'תשובה', maxlength: 2000 });
            const send = () => {
                const v = reply.value.trim();
                if (!v) return;
                commit(c => { if (c[t.id]) (c[t.id].replies ||= []).push({ author: author(), date: now(), text: v }); });
            };
            reply.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send(); } });
            node.append(t.done ? null : reply, h('div.cm-actions',
                t.done ? null : h('button.btn.sm.ghost', { type: 'button', onclick: send }, 'תשובה'),
                // אחרי הפעולה — המקלדת חוזרת למסמך (Ctrl+Z מבטל אותה)
                h('button.btn.sm.ghost', { type: 'button', onclick: () => { commit(c => { if (c[t.id]) c[t.id].done = !t.done; }); editor.view.focus(); } }, t.done ? 'פתיחה מחדש' : '✓ נפתר'),
                h('button.btn.sm.ghost', { type: 'button', 'aria-label': 'מחיקת ההערה', onclick: () => { remove(t.id); editor.view.focus(); } }, 'מחיקה')));
        }
        node.addEventListener('click', e => { if (!e.target.closest('button, input, textarea')) go(t); });
        return node;
    }

    function paint() {
        const all = threads(editor.state);
        // הערה שנפתרה — בלי צבע בטקסט (כמו ב-Word)
        doneStyle.textContent = all.filter(t => t.done).map(t => `.wp-doc .wp-cmt[data-comment="${CSS.escape(t.id)}"]`).join(',') + (all.some(t => t.done) ? '{background:none}' : '');
        if (el.hidden) return;
        const focusDraft = draftFor && list.querySelector(`[data-id="${draftFor}"] .cm-edit`);
        const keep = focusDraft ? focusDraft.value : null;
        list.textContent = '';
        for (const t of all) list.append(card(t));
        if (keep !== null) { const ta = list.querySelector(`[data-id="${draftFor}"] .cm-edit`); if (ta) { ta.value = keep; ta.focus(); } }
        empty.hidden = all.length > 0;
    }

    /** השרשור שהסמן בתוכו — מסומן בחלונית ובטקסט. */
    function setActive(id) {
        active = id;
        list.querySelectorAll('.cm-card').forEach(c => c.classList.toggle('on', c.dataset.id === id));
        editor.view.dom.querySelectorAll('.wp-cmt.on').forEach(s => s.classList.remove('on'));
        if (id) editor.view.dom.querySelectorAll(`.wp-cmt[data-comment="${CSS.escape(id)}"]`).forEach(s => s.classList.add('on'));
    }
    function track() {
        if (editor.isDestroyed) return;
        const { $from } = editor.state.selection;
        const m = [...$from.marks(), ...($from.nodeAfter?.marks || [])].find(x => x.type.name === 'comment');
        setActive(m && data()[m.attrs.id] ? m.attrs.id : null);
    }

    // העדכון מתעכב מעט — ובינתיים העורך יכול להיסגר (יציאה מהמסמך)
    editor.on('update', () => { clearTimeout(timer); timer = setTimeout(() => { if (editor.isDestroyed) return; paint(); track(); }, 120); });
    editor.on('destroy', () => clearTimeout(timer));
    editor.on('selectionUpdate', track);
    // לחיצה על טקסט עם הערה — החלונית נפתחת על השרשור
    const onClick = e => {
        const s = e.target.closest?.('.wp-cmt');
        if (!s || !data()[s.dataset.comment]) return;
        if (el.hidden) { open(); onChange?.(); }
        setActive(s.dataset.comment);
        list.querySelector(`[data-id="${CSS.escape(s.dataset.comment)}"]`)?.scrollIntoView({ block: 'nearest' });
    };
    function open() { el.hidden = false; paint(); track(); }
    function close() { el.hidden = true; }
    editor.storage.wpComments = { add, onClick };
    paint();
    return { el, add, open, close, isOpen: () => !el.hidden, count: () => threads(editor.state).length };
}
