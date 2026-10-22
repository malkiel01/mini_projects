/**
 * תוכן עניינים (שלב 4): בלוק שמחזיק עותק של הכותרות ומספרי העמודים (entries) — כמו שדה TOC ב-Word, שמתעדכן
 * ב"עדכון תוכן עניינים" ולא לבד. כל שורה: הכותרת, נקודות, ומספר העמוד; לחיצה — מעבר לכותרת.
 * המידות קבועות (גובה כותרת ושורה לפי גודל הגופן של המסמך) — אותן מידות ב-PDF (lib/pdf/layout.php, toc()).
 * entries: [{ t: טקסט, l: רמה, p: מספר העמוד כפי שמוצג, i: מספר הכותרת במסמך (לפי הסדר) }]
 */
import { Node } from '../../vendor/tiptap.js';

export const TOC_TITLE = 'תוכן עניינים';

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
        return ['div', { 'data-toc': '', 'data-levels': node.attrs.levels, 'data-entries': JSON.stringify(node.attrs.entries), class: 'wp-toc' }];
    },
    addNodeView() {
        return ({ node, editor }) => {
            const dom = document.createElement('div');
            dom.className = 'wp-toc';
            dom.contentEditable = 'false';
            dom.setAttribute('role', 'navigation');
            dom.setAttribute('aria-label', TOC_TITLE);
            const paint = n => {
                dom.textContent = '';
                const title = document.createElement('div');
                title.className = 'wp-toc-title';
                title.textContent = TOC_TITLE;
                dom.append(title);
                const list = n.attrs.entries || [];
                if (!list.length) {
                    const empty = document.createElement('div');
                    empty.className = 'wp-toc-line wp-toc-empty';
                    empty.textContent = 'אין כותרות במסמך';
                    dom.append(empty);
                }
                for (const e of list) {
                    const line = document.createElement('div');
                    line.className = 'wp-toc-line wp-toc-l' + Math.min(9, e.l || 1);
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
            };
            paint(node);
            // לחיצה על שורה — הסמן עובר לכותרת (כמו קישור בתוכן העניינים של Word)
            dom.addEventListener('mousedown', ev => {
                const line = ev.target.closest('.wp-toc-line');
                if (!line || line.dataset.i == null) return;
                ev.preventDefault();
                const h = headings(editor.state.doc, 9).find(x => x.i === +line.dataset.i);
                if (!h) return;
                editor.chain().focus().setTextSelection(h.pos + 1).run();
                requestAnimationFrame(() => editor.view.nodeDOM(h.pos)?.scrollIntoView?.({ block: 'start' }));
            });
            return {
                dom,
                update(n) { if (n.type.name !== 'toc') return false; paint(n); return true; },
                ignoreMutation: () => true,
                stopEvent: ev => ev.type === 'mousedown',
            };
        };
    },
});
