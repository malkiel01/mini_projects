/**
 * כיתובים ממוספרים והפניות צולבות (שלב 4) — כמו ב-Word, ואותו חישוב כמו lib/caption.php:
 * - seq (שדה SEQ): המספר של הכיתוב. attrs.label — "איור" / "טבלה" / "משוואה" (גם שם הרצף); attrs.id — יעד להפניות.
 *   המספר = המקום בין הכיתובים עם אותה תווית, לפי הסדר במסמך. התווית עצמה — טקסט רגיל לפני המספר.
 * - xref (שדה REF): "איור 2" (show: 'full') או "2" ('num'). Ctrl+לחיצה — מעבר לכיתוב.
 * המספרים לא נשמרים בצומת: תוסף מחשב אותם אחרי כל שינוי ומציב אותם כ-data-n (התצוגה — ::after ב-editor.css),
 * כך שהוספה או מחיקה של כיתוב באמצע מעדכנת מיד את כל המספרים וההפניות.
 */
import { Node, Plugin, PluginKey, Decoration, DecorationSet } from '../../vendor/tiptap.js';

export const SEQ_LABELS = ['איור', 'טבלה', 'משוואה'];
export const XREF_MISSING = 'שגיאה! מקור ההפניה לא נמצא.';
const LABEL_RE = /^\p{L}{1,20}$/u;
const ID_RE = /^[A-Za-z0-9]{1,30}$/;
export const newSeqId = () => Math.random().toString(36).slice(2, 11).replace(/[^a-z0-9]/g, '') || String(Date.now());
const labelOf = l => (LABEL_RE.test(l || '') ? l : SEQ_LABELS[0]);

/** כל הכיתובים במסמך לפי הסדר: [{ pos, id, label, n, text }] — text: הפסקה עם המספר ("איור 2: מבנה המערכת"). */
export function captions(doc) {
    const out = [];
    const count = {};
    doc.descendants((node, pos, parent) => {
        if (node.type.name !== 'seq') return true;
        const label = labelOf(node.attrs.label);
        const n = count[label] = (count[label] || 0) + 1;
        let text = '';
        parent?.forEach(ch => { text += ch === node ? String(n) : ch.isText ? ch.text : ''; });
        out.push({ pos, id: node.attrs.id, label, n, text: text.trim() });
        return false;
    });
    return out;
}

/** רשימת איורים / טבלאות / משוואות — הכותרת של תוכן עניינים לפי כיתובים (toc עם attrs.seq). */
const PLURAL = { 'איור': 'איורים', 'טבלה': 'טבלאות', 'משוואה': 'משוואות' };
export const listTitle = label => 'רשימת ' + (PLURAL[label] || label);

export function xrefText(attrs, byId) {
    const c = byId.get(attrs.target);
    if (!c) return XREF_MISSING;
    return attrs.show === 'num' ? String(c.n) : `${c.label} ${c.n}`;
}

const key = new PluginKey('wpCaptions');

function decorate(doc) {
    const list = captions(doc);
    const byId = new Map();
    for (const c of list) if (c.id && !byId.has(c.id)) byId.set(c.id, c);
    const decos = list.map(c => Decoration.node(c.pos, c.pos + 1, { 'data-n': String(c.n) }));
    doc.descendants((node, pos) => {
        if (node.type.name !== 'xref') return true;
        const t = xrefText(node.attrs, byId);
        decos.push(Decoration.node(pos, pos + 1, { 'data-n': t, class: t === XREF_MISSING ? 'wp-xref-bad' : '' }));
        return false;
    });
    return DecorationSet.create(doc, decos);
}

export const Seq = Node.create({
    name: 'seq',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,
    addAttributes() {
        return {
            label: { default: SEQ_LABELS[0], parseHTML: el => labelOf(el.getAttribute('data-seq')) },
            id: { default: null, parseHTML: el => (ID_RE.test(el.getAttribute('data-id') || '') ? el.getAttribute('data-id') : null) },
        };
    },
    parseHTML() {
        return [{ tag: 'span[data-seq]' }];
    },
    renderHTML({ node }) {
        return ['span', { 'data-seq': node.attrs.label, 'data-id': node.attrs.id, class: 'wp-seq', contenteditable: 'false' }];
    },
    addProseMirrorPlugins() {
        return [new Plugin({
            key,
            state: {
                init: (_, state) => decorate(state.doc),
                apply: (tr, old) => (tr.docChanged ? decorate(tr.doc) : old),
            },
            props: {
                decorations: state => key.getState(state),
                // Ctrl+לחיצה על הפניה — מעבר לכיתוב (כמו בקישור, ובשדה REF \h של Word)
                handleClick: (view, pos, ev) => {
                    const el = ev.target.closest?.('.wp-xref');
                    if (!el || !(ev.ctrlKey || ev.metaKey)) return false;
                    const node = view.state.doc.nodeAt(view.posAtDOM(el, 0));
                    const c = node && captions(view.state.doc).find(x => x.id === node.attrs.target);
                    if (!c) return false;
                    view.dispatch(view.state.tr.setSelection(view.state.selection.constructor.near(view.state.doc.resolve(c.pos + 1))).scrollIntoView());
                    view.focus();
                    return true;
                },
            },
            // כיתוב בלי מזהה, או מזהה כפול (הדבקה של כיתוב שהועתק) — מזהה חדש; ההפניות נשארות על המקור
            appendTransaction: (trs, _, state) => {
                if (!trs.some(t => t.docChanged && !t.getMeta('collab$'))) return null;
                const seen = new Set();
                let tr = null;
                state.doc.descendants((node, pos) => {
                    if (node.type.name !== 'seq') return true;
                    if (!ID_RE.test(node.attrs.id || '') || seen.has(node.attrs.id)) {
                        tr ??= state.tr.setMeta('addToHistory', false);
                        let id;
                        do id = newSeqId(); while (seen.has(id));
                        tr.setNodeMarkup(pos, undefined, { ...node.attrs, id });
                        seen.add(id);
                    } else seen.add(node.attrs.id);
                    return false;
                });
                return tr;
            },
        })];
    },
});

export const Xref = Node.create({
    name: 'xref',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,
    addAttributes() {
        return {
            target: { default: '', parseHTML: el => el.getAttribute('data-target') || '' },
            show: { default: 'full', parseHTML: el => (el.getAttribute('data-show') === 'num' ? 'num' : 'full') },
        };
    },
    parseHTML() {
        return [{ tag: 'span[data-xref]' }];
    },
    renderHTML({ node }) {
        return ['span', { 'data-xref': '', 'data-target': node.attrs.target, 'data-show': node.attrs.show, class: 'wp-xref',
            contenteditable: 'false', title: 'הפניה צולבת — Ctrl+לחיצה: מעבר לכיתוב' }];
    },
});
