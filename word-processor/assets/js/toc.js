/**
 * תוכן עניינים (שלב 4): בלוק שמחזיק עותק של הכותרות ומספרי העמודים (entries) — כמו שדה TOC ב-Word, שמתעדכן
 * ב"עדכון תוכן עניינים" ולא לבד. כל שורה: הכותרת, נקודות, ומספר העמוד; לחיצה — מעבר לכותרת.
 * המידות קבועות (גובה כותרת ושורה לפי גודל הגופן של המסמך) — אותן מידות ב-PDF (lib/pdf/layout.php, toc()).
 * entries: [{ t: טקסט, l: רמה, p: מספר העמוד כפי שמוצג, i: מספר הכותרת במסמך (לפי הסדר) }]
 * רשימת איורים (attrs.seq = תווית הכיתוב, כמו TOC \c ב-Word): אותו בלוק, והשורות — הכיתובים עם התווית (l: 0, בלי הדגשה);
 * i — מספר הכיתוב בין הכיתובים עם התווית.
 * תוכן עניינים ארוך מעמוד נשבר בין רשומות: העימוד (pagination.js) מעביר את הרווח של מעבר העמוד ואת אזור ההערות של
 * העמוד ב-Decoration.node — spec.toc = {k, order, make, key}; כאן הם נכנסים לפני הרשומה k (0 — הכותרת).
 */
import { Node } from '../../vendor/tiptap.js';
import { captions, listTitle } from './caption.js';

export const TOC_TITLE = 'תוכן עניינים';
export const tocTitle = a => (a.seq ? listTitle(a.seq) : TOC_TITLE);

/** הכותרות במסמך: [{ pos, level, text, i }] — עד הרמה levels. */
export function headings(doc, levels = 3) {
    const out = [];
    let i = 0;
    doc.descendants((n, pos) => {
        if (n.type.name === 'heading') {
            if (n.attrs.level <= levels) out.push({ pos, level: n.attrs.level, text: n.textContent.trim(), i });
            i++;
        }
        return !n.isTextblock;
    });
    return out;
}

export const Toc = Node.create({
    name: 'toc',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: false,
    addAttributes() {
        return {
            seq: { default: null, parseHTML: el => el.getAttribute('data-seq') || null },
            levels: { default: 3, parseHTML: el => Math.max(1, Math.min(9, parseInt(el.getAttribute('data-levels'), 10) || 3)) },
            entries: {
                default: [],
                parseHTML: el => { try { return JSON.parse(el.getAttribute('data-entries') || '[]'); } catch { return []; } },
            },
        };
    },
    parseHTML() {
        return [{ tag: 'div[data-toc]' }];
    },
    renderHTML({ node }) {
        return ['div', { 'data-toc': '', 'data-seq': node.attrs.seq, 'data-levels': node.attrs.levels, 'data-entries': JSON.stringify(node.attrs.entries), class: 'wp-toc' }];
    },
    addNodeView() {
        return ({ node, editor, decorations }) => {
            const dom = document.createElement('div');
            dom.className = 'wp-toc';
            dom.contentEditable = 'false';
            dom.setAttribute('role', 'navigation');
            const partsOf = decos => (decos || []).map(d => d.spec?.toc).filter(Boolean).sort((a, b) => a.k - b.k || a.order - b.order);
            let parts = partsOf(decorations);
            let partsKey = parts.map(p => p.k + ':' + p.key).join('|');
            const paint = n => {
                dom.textContent = '';
                dom.setAttribute('aria-label', tocTitle(n.attrs));
                const title = document.createElement('div');
                title.className = 'wp-toc-title';
                title.textContent = tocTitle(n.attrs);
                dom.append(title);
                const list = n.attrs.entries || [];
                if (!list.length) {
                    const empty = document.createElement('div');
                    empty.className = 'wp-toc-line wp-toc-empty';
                    empty.textContent = n.attrs.seq ? `אין כיתובי "${n.attrs.seq}" במסמך` : 'אין כותרות במסמך';
                    dom.append(empty);
                }
                for (const e of list) {
                    const line = document.createElement('div');
                    line.className = 'wp-toc-line wp-toc-l' + Math.max(0, Math.min(9, e.l ?? 1));
                    line.dataset.i = e.i;
                    const t = document.createElement('span');
                    t.className = 'wp-toc-t';
                    t.textContent = e.t;
                    const dots = document.createElement('span');
                    dots.className = 'wp-toc-dots';
                    const p = document.createElement('span');
                    p.className = 'wp-toc-p';
                    p.textContent = e.p;
                    line.append(t, dots, p);
                    dom.append(line);
                }
                // מעברי העמוד (והערות העמוד) בתוך תוכן העניינים — לפני הרשומה שלהם
                const rows = [...dom.children];
                for (const part of parts) rows[part.k]?.before(part.make());
            };
            paint(node);
            // לחיצה על שורה — הסמן עובר לכותרת (כמו קישור בתוכן העניינים של Word)
            dom.addEventListener('mousedown', ev => {
                const line = ev.target.closest('.wp-toc-line');
                if (!line || line.dataset.i == null) return;
                ev.preventDefault();
                const seq = node.attrs.seq;
                const h = seq ? captions(editor.state.doc).filter(c => c.label === seq)[+line.dataset.i]
                    : headings(editor.state.doc, 9).find(x => x.i === +line.dataset.i);
                if (!h) return;
                editor.chain().focus().setTextSelection(h.pos + 1).run();
                requestAnimationFrame(() => editor.view.nodeDOM(h.pos)?.scrollIntoView?.({ block: 'start' }));
            });
            return {
                dom,
                update(n, decos) {
                    if (n.type.name !== 'toc') return false;
                    const next = partsOf(decos);
                    const key = next.map(p => p.k + ':' + p.key).join('|');
                    if (n === node && key === partsKey) return true;
                    node = n;
                    parts = next;
                    partsKey = key;
                    paint(n);
                    return true;
                },
                ignoreMutation: () => true,
                stopEvent: ev => ev.type === 'mousedown',
            };
        };
    },
});
