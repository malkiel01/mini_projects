/**
 * ההרחבות שלנו מעל TipTap (החלקים החינמיים בלבד — embed.md, פרק 0):
 * עיצוב פסקה (כיוון, הזחה, ריווח, כותרת מסמך), רשימה ממוספרת 1./א./i., תמונה עם גודל וגלישה,
 * מעבר עמוד ידני, ומילוי תא. המבנה זהה למה שהשרת מייצא (lib/export.php).
 */
import {
    Extension, Node, mergeAttributes, StarterKit, TextStyle, Color, FontFamily, FontSize, TextAlign, Highlight,
    Subscript, Superscript, Table, TableRow, TableCell, TableHeader, OrderedList,
} from '../../vendor/tiptap.js';

const BLOCKS = ['paragraph', 'heading'];
const num = v => (v === null || v === undefined || v === '' || isNaN(+v)) ? null : +v;

/** עיצוב פסקה: dir, indent, lineHeight ('1.5' / 'exact:14'), spaceBefore/After (נק'), letterSpacing, pstyle. */
export const ParagraphFormat = Extension.create({
    name: 'paragraphFormat',
    addGlobalAttributes() {
        return [{
            types: BLOCKS,
            attributes: {
                dir: {
                    default: null,
                    parseHTML: el => ['rtl', 'ltr'].includes(el.getAttribute('dir')) ? el.getAttribute('dir') : null,
                    renderHTML: a => a.dir ? { dir: a.dir } : {},
                },
                indent: {
                    default: 0,
                    parseHTML: el => {
                        const m = parseFloat(el.style.marginInlineStart || el.style.marginRight || el.style.paddingInlineStart || 0);
                        return m > 0 ? Math.min(8, Math.round(m / 36)) : 0;
                    },
                    renderHTML: a => a.indent ? { style: `margin-inline-start:${a.indent * 36}pt` } : {},
                },
                lineHeight: {
                    default: null,
                    parseHTML: el => {
                        const lh = el.style.lineHeight;
                        if (!lh || lh === 'normal') return null;
                        if (/^[\d.]+$/.test(lh)) return String(Math.round(+lh * 100) / 100);
                        if (/pt$/.test(lh)) return 'exact:' + parseFloat(lh);
                        if (/%$/.test(lh)) return String(Math.round(parseFloat(lh)) / 100);
                        return null;
                    },
                    renderHTML: a => {
                        if (!a.lineHeight) return {};
                        const m = /^exact:([\d.]+)$/.exec(a.lineHeight);
                        return { style: `line-height:${m ? m[1] + 'pt' : a.lineHeight}` };
                    },
                },
                spaceBefore: {
                    default: null,
                    parseHTML: el => ptOf(el.style.marginTop || el.style.paddingTop),
                    renderHTML: a => num(a.spaceBefore) !== null ? { style: `padding-top:${a.spaceBefore}pt` } : {},
                },
                spaceAfter: {
                    default: null,
                    parseHTML: el => ptOf(el.style.marginBottom || el.style.paddingBottom),
                    renderHTML: a => num(a.spaceAfter) !== null ? { style: `padding-bottom:${a.spaceAfter}pt` } : {},
                },
                letterSpacing: {
                    default: null,
                    parseHTML: el => ptOf(el.style.letterSpacing),
                    renderHTML: a => num(a.letterSpacing) ? { style: `letter-spacing:${a.letterSpacing}pt` } : {},
                },
            },
        }, {
            types: ['paragraph'],
            attributes: {
                pstyle: {
                    default: null,
                    parseHTML: el => el.classList.contains('wp-title') || el.classList.contains('MsoTitle') ? 'title' : null,
                    renderHTML: a => a.pstyle === 'title' ? { class: 'wp-title' } : {},
                },
            },
        }];
    },
    addCommands() {
        return {
            /** מציב תכונות על כל הפסקאות והכותרות שבבחירה. */
            setBlockAttrs: attrs => ({ tr, state, dispatch }) => {
                const { from, to } = state.selection;
                state.doc.nodesBetween(from, to, (node, pos) => {
                    if (BLOCKS.includes(node.type.name)) tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...attrs });
                });
                if (dispatch) dispatch(tr);
                return true;
            },
            changeIndent: delta => ({ tr, state, dispatch }) => {
                const { from, to } = state.selection;
                state.doc.nodesBetween(from, to, (node, pos) => {
                    if (BLOCKS.includes(node.type.name)) tr.setNodeMarkup(pos, undefined, { ...node.attrs, indent: Math.max(0, Math.min(8, (node.attrs.indent || 0) + delta)) });
                });
                if (dispatch) dispatch(tr);
                return true;
            },
            /** סגנון: 'normal' | 'title' | 'h1'..'h3'. */
            setStyle: s => ({ chain }) => {
                if (s === 'normal') return chain().setParagraph().setBlockAttrs({ pstyle: null }).run();
                if (s === 'title') return chain().setParagraph().setBlockAttrs({ pstyle: 'title' }).run();
                return chain().setHeading({ level: +s.slice(1) }).run();
            },
        };
    },
    addKeyboardShortcuts() {
        const inList = ed => ed.isActive('listItem');
        return {
            Tab: () => inList(this.editor) ? this.editor.commands.sinkListItem('listItem') : this.editor.commands.changeIndent(1),
            'Shift-Tab': () => inList(this.editor) ? this.editor.commands.liftListItem('listItem') : this.editor.commands.changeIndent(-1),
            'Mod-Enter': () => this.editor.commands.insertPageBreak(),
        };
    },
});

function ptOf(v) {
    if (!v) return null;
    const n = parseFloat(v);
    if (isNaN(n)) return null;
    if (/px$/.test(v)) return Math.round(n * 0.75 * 10) / 10;
    if (/cm$/.test(v)) return Math.round(n * 28.35);
    if (/in$/.test(v)) return Math.round(n * 72);
    return Math.round(n * 10) / 10;
}

/** רשימה ממוספרת עם סוג: decimal (1.), hebrew (א.), roman (i.). */
export const NumberedList = OrderedList.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            listType: {
                default: 'decimal',
                parseHTML: el => {
                    const t = el.getAttribute('data-list') || el.getAttribute('type') || el.style.listStyleType || '';
                    if (/hebrew/.test(t) || el.classList.contains('hebrew')) return 'hebrew';
                    if (/roman|^i$/i.test(t) || el.classList.contains('roman')) return 'roman';
                    return 'decimal';
                },
                renderHTML: a => ({ 'data-list': a.listType || 'decimal' }),
            },
        };
    },
});

/** מעבר עמוד ידני (Ctrl+Enter). */
export const PageBreak = Node.create({
    name: 'pageBreak',
    group: 'block',
    atom: true,
    selectable: true,
    parseHTML() {
        return [{ tag: 'div[data-page-break]' }, { tag: 'div.page-break' },
            { tag: 'br', getAttrs: el => /page/.test(el.style.pageBreakBefore || el.style.breakBefore || '') ? null : false, priority: 60 }];
    },
    renderHTML() {
        return ['div', { 'data-page-break': '', class: 'wp-page-break', contenteditable: 'false' }];
    },
    addCommands() {
        return {
            insertPageBreak: () => ({ chain }) => chain().insertContent({ type: 'pageBreak' }).createParagraphNear().run(),
        };
    },
});

/** מילוי תא. */
const cellAttrs = {
    background: {
        default: null,
        parseHTML: el => el.style.backgroundColor || el.getAttribute('bgcolor') || null,
        renderHTML: a => a.background ? { style: `background-color:${a.background}` } : {},
    },
};
export const Cell = TableCell.extend({ addAttributes() { return { ...this.parent?.(), ...cellAttrs }; } });
export const HeaderCell = TableHeader.extend({ addAttributes() { return { ...this.parent?.(), ...cellAttrs }; } });

/**
 * תמונה: בשורה (inline) או ריבוע (צפה, הטקסט גולש). media — מזהה אצלנו; src — הכתובת החתומה.
 * שינוי גודל בגרירת הפינה; הגובה נגזר מהיחס.
 */
export const WpImage = Node.create({
    name: 'image',
    inline: true,
    group: 'inline',
    atom: true,
    draggable: true,
    selectable: true,
    addAttributes() {
        return {
            media: { default: null, parseHTML: el => el.getAttribute('data-media') },
            src: { default: null },
            alt: { default: '' },
            width: { default: null, parseHTML: el => num(el.getAttribute('width')) },
            height: { default: null, parseHTML: el => num(el.getAttribute('height')) },
            wrap: { default: 'inline', parseHTML: el => el.getAttribute('data-wrap') || 'inline' },
            align: { default: 'right', parseHTML: el => el.getAttribute('data-align') || 'right' },
        };
    },
    parseHTML() {
        return [{ tag: 'img[src]', getAttrs: el => (el.getAttribute('data-media') || /^(data:|blob:|https?:|api\.php)/.test(el.getAttribute('src') || '')) ? {} : false }];
    },
    renderHTML({ HTMLAttributes: a }) {
        return ['img', mergeAttributes({ src: a.src, alt: a.alt, width: a.width, height: a.height, 'data-media': a.media, 'data-wrap': a.wrap, 'data-align': a.align })];
    },
    addNodeView() {
        return ({ node, getPos, editor }) => {
            let cur = node;
            const dom = document.createElement('span');
            const img = document.createElement('img');
            const handle = document.createElement('span');
            handle.className = 'wp-img-handle';
            handle.setAttribute('aria-hidden', 'true');
            dom.append(img, handle);
            const paint = () => {
                const a = cur.attrs;
                dom.className = `wp-img wrap-${a.wrap === 'square' ? 'square' : 'inline'} align-${a.align || 'right'}`;
                if (img.getAttribute('src') !== a.src) img.src = a.src || '';
                img.alt = a.alt || '';
                if (a.width) { img.style.width = a.width + 'px'; img.style.height = a.height ? a.height + 'px' : 'auto'; }
                else { img.style.width = ''; img.style.height = ''; }
            };
            paint();
            img.addEventListener('load', () => editor.storage.wpPages?.schedule?.());
            handle.addEventListener('pointerdown', e => {
                if (!editor.isEditable) return;
                e.preventDefault();
                e.stopPropagation();
                const zoom = editor.storage.wpPages?.zoom || 1;
                const startX = e.clientX;
                const startW = img.getBoundingClientRect().width / zoom;
                const ratio = (img.naturalHeight || 1) / (img.naturalWidth || 1);
                const max = editor.storage.wpPages?.contentWidth || 2000;
                const rtl = getComputedStyle(dom).direction === 'rtl';
                handle.setPointerCapture(e.pointerId);
                let w = startW;
                const move = ev => {
                    const dx = (ev.clientX - startX) / zoom;
                    w = Math.max(24, Math.min(max, startW + (rtl ? -dx : dx)));
                    img.style.width = w + 'px';
                    img.style.height = (w * ratio) + 'px';
                };
                const up = () => {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', up);
                    handle.removeEventListener('pointercancel', up);
                    const pos = getPos();
                    if (typeof pos === 'number') {
                        editor.view.dispatch(editor.state.tr.setNodeMarkup(pos, undefined, { ...cur.attrs, width: Math.round(w), height: Math.round(w * ratio) }));
                    }
                };
                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', up);
                handle.addEventListener('pointercancel', up);
            });
            return {
                dom,
                update(n) {
                    if (n.type !== cur.type) return false;
                    cur = n;
                    paint();
                    return true;
                },
                selectNode() { dom.classList.add('selected'); },
                deselectNode() { dom.classList.remove('selected'); },
                stopEvent: e => e.target === handle,
                ignoreMutation: () => true,
            };
        };
    },
});

/** כל ההרחבות של העורך. */
export function extensions(extra = []) {
    return [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },
            blockquote: false, code: false, codeBlock: false, link: false, orderedList: false, trailingNode: false,
            undoRedo: { depth: 200 },
        }),
        NumberedList,
        TextStyle, Color, FontFamily, FontSize,
        Highlight.configure({ multicolor: true }),
        Subscript, Superscript,
        TextAlign.configure({ types: BLOCKS, alignments: ['right', 'center', 'left', 'justify'], defaultAlignment: null }),
        ParagraphFormat, PageBreak, WpImage,
        Table.configure({ resizable: true, cellMinWidth: 30, allowTableNodeSelection: true }),
        TableRow, Cell, HeaderCell,
        ...extra,
    ];
}
