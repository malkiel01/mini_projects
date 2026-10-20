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
 * תמונה. גלישה כמו ב-Word: inline (בשורה) · square (ריבוע) · tight (צמוד) · topBottom (מעל ומתחת) ·
 * behind (מאחורי הטקסט) · front (לפני הטקסט). width/height — גודל התמונה המלאה (לפני חיתוך) בפיקסלים;
 * crop — אחוזים מכל צד; rotate — 0/90/180/270; dist — מרחק מהטקסט (נק'); x/y — מיקום (פיקסלים, מראש הפסקה)
 * למאחורי/לפני הטקסט; caption — כיתוב מתחת לתמונה. media — מזהה אצלנו; src — הכתובת החתומה.
 */
export const WRAPS = ['inline', 'square', 'tight', 'topBottom', 'behind', 'front'];
export const FLOATING = ['square', 'tight'];
export const ABSOLUTE = ['behind', 'front'];

/** הממדים שעל הדף: אחרי חיתוך ואחרי סיבוב. */
export function imageBox(a, natural = null) {
    const w = a.width || natural?.w || 200;
    const h = a.height || (natural?.w ? w * natural.h / natural.w : w * 0.75);
    const c = a.crop || {};
    const cw = w * (1 - ((c.l || 0) + (c.r || 0)) / 100);
    const ch = h * (1 - ((c.t || 0) + (c.b || 0)) / 100);
    const turned = (a.rotate || 0) % 180 !== 0;
    return { w, h, cw, ch, bw: turned ? ch : cw, bh: turned ? cw : ch };
}

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
            wrap: { default: 'inline', parseHTML: el => WRAPS.includes(el.getAttribute('data-wrap')) ? el.getAttribute('data-wrap') : 'inline' },
            align: { default: 'right', parseHTML: el => el.getAttribute('data-align') || 'right' },
            dist: { default: null, parseHTML: el => num(el.getAttribute('data-dist')) },
            crop: { default: null, parseHTML: el => { try { return JSON.parse(el.getAttribute('data-crop')) || null; } catch { return null; } } },
            rotate: { default: 0, parseHTML: el => num(el.getAttribute('data-rotate')) || 0 },
            x: { default: 0, parseHTML: el => num(el.getAttribute('data-x')) || 0 },
            y: { default: 0, parseHTML: el => num(el.getAttribute('data-y')) || 0 },
            caption: { default: '', parseHTML: el => el.getAttribute('data-caption') || '' },
        };
    },
    parseHTML() {
        return [{ tag: 'img[src]', getAttrs: el => (el.getAttribute('data-media') || /^(data:|blob:|https?:|api\.php)/.test(el.getAttribute('src') || '')) ? {} : false }];
    },
    renderHTML({ HTMLAttributes: a }) {
        return ['img', mergeAttributes({ src: a.src, alt: a.alt, width: a.width, height: a.height, 'data-media': a.media, 'data-wrap': a.wrap,
            'data-align': a.align, 'data-dist': a.dist, 'data-crop': a.crop ? JSON.stringify(a.crop) : null, 'data-rotate': a.rotate || null,
            'data-x': a.x || null, 'data-y': a.y || null, 'data-caption': a.caption || null })];
    },
    addNodeView() {
        return ({ node, getPos, editor }) => {
            let cur = node;
            const dom = document.createElement('span');
            const box = document.createElement('span');
            const img = document.createElement('img');
            const cap = document.createElement('span');
            const handle = document.createElement('span');
            box.className = 'wp-img-box';
            cap.className = 'wp-img-cap';
            handle.className = 'wp-img-handle';
            handle.setAttribute('aria-hidden', 'true');
            img.draggable = false;
            box.append(img);
            dom.append(box, cap, handle);
            const natural = () => img.naturalWidth ? { w: img.naturalWidth, h: img.naturalHeight } : null;
            const paint = () => {
                const a = cur.attrs;
                const wrap = WRAPS.includes(a.wrap) ? a.wrap : 'inline';
                dom.className = `wp-img wrap-${wrap} align-${a.align || 'right'}`;
                if (img.getAttribute('src') !== a.src) img.src = a.src || '';
                img.alt = a.alt || '';
                const g = imageBox(a, natural());
                const c = a.crop || {};
                // התמונה המלאה בגודלה, וחלון (box) בגודל החיתוך שמסתובב סביב המרכז
                Object.assign(img.style, { width: g.w + 'px', height: g.h + 'px', marginInlineStart: 0, marginLeft: -(g.w * (c.l || 0) / 100) + 'px', marginTop: -(g.h * (c.t || 0) / 100) + 'px' });
                Object.assign(box.style, { width: g.cw + 'px', height: g.ch + 'px',
                    transform: `translate(-50%, -50%) rotate(${a.rotate || 0}deg)` });
                dom.style.setProperty('--bw', g.bw + 'px');
                dom.style.setProperty('--bh', g.bh + 'px');
                // צמוד — כמו ריבוע סביב מלבן התמונה (כך גם ב-Word וב-PDF), במרחק קטן יותר
                dom.style.setProperty('--dist', (a.dist ?? (wrap === 'tight' ? 4 : FLOATING.includes(wrap) || wrap === 'topBottom' ? 9 : 0)) + 'pt');
                dom.style.left = ABSOLUTE.includes(wrap) ? (a.x || 0) + 'px' : '';
                dom.style.top = ABSOLUTE.includes(wrap) ? (a.y || 0) + 'px' : '';
                cap.textContent = a.caption || '';
                cap.hidden = !a.caption;
            };
            paint();
            img.addEventListener('load', () => { paint(); editor.storage.wpPages?.schedule?.(); });
            const zoom = () => editor.storage.wpPages?.zoom || 1;
            const commit = attrs => {
                const pos = getPos();
                if (typeof pos === 'number') editor.view.dispatch(editor.state.tr.setNodeMarkup(pos, undefined, { ...cur.attrs, ...attrs }));
            };
            // שינוי גודל: גרירת הפינה (שומר על היחס)
            handle.addEventListener('pointerdown', e => {
                if (!editor.isEditable) return;
                e.preventDefault();
                e.stopPropagation();
                const startX = e.clientX;
                const g = imageBox(cur.attrs, natural());
                const ratio = g.h / g.w;
                const max = (editor.storage.wpPages?.contentWidth || 2000) * 1.5;
                const rtl = getComputedStyle(dom).direction === 'rtl';
                handle.setPointerCapture(e.pointerId);
                let w = g.w;
                const move = ev => {
                    const dx = (ev.clientX - startX) / zoom();
                    w = Math.max(24, Math.min(max, g.w + (rtl ? -dx : dx)));
                    const k = w / g.w;
                    img.style.width = w + 'px'; img.style.height = w * ratio + 'px';
                    box.style.width = g.cw * k + 'px'; box.style.height = g.ch * k + 'px';
                    dom.style.setProperty('--bw', g.bw * k + 'px'); dom.style.setProperty('--bh', g.bh * k + 'px');
                };
                const up = () => {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', up);
                    handle.removeEventListener('pointercancel', up);
                    commit({ width: Math.round(w), height: Math.round(w * ratio) });
                };
                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', up);
                handle.addEventListener('pointercancel', up);
            });
            // מאחורי / לפני הטקסט: גרירה מזיזה את התמונה על הדף
            box.addEventListener('pointerdown', e => {
                if (!editor.isEditable || !ABSOLUTE.includes(cur.attrs.wrap) || !dom.classList.contains('selected')) return;
                e.preventDefault();
                e.stopPropagation();
                const sx = e.clientX, sy = e.clientY, x0 = cur.attrs.x || 0, y0 = cur.attrs.y || 0;
                box.setPointerCapture(e.pointerId);
                let x = x0, y = y0;
                const move = ev => {
                    x = Math.round(x0 + (ev.clientX - sx) / zoom());
                    y = Math.round(y0 + (ev.clientY - sy) / zoom());
                    dom.style.left = x + 'px';
                    dom.style.top = y + 'px';
                };
                const up = () => {
                    box.removeEventListener('pointermove', move);
                    box.removeEventListener('pointerup', up);
                    box.removeEventListener('pointercancel', up);
                    if (x !== x0 || y !== y0) commit({ x, y });
                };
                box.addEventListener('pointermove', move);
                box.addEventListener('pointerup', up);
                box.addEventListener('pointercancel', up);
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
                stopEvent: e => e.target === handle || (ABSOLUTE.includes(cur.attrs.wrap) && e.type.startsWith('pointer') && dom.classList.contains('selected')),
                ignoreMutation: () => true,
            };
        };
    },
});

/** ריווח בין תווים — על סימון ה-textStyle, כמו גופן וגודל ('1.5pt'). */
export const LetterSpacing = Extension.create({
    name: 'letterSpacing',
    addGlobalAttributes() {
        return [{
            types: ['textStyle'],
            attributes: {
                letterSpacing: {
                    default: null,
                    parseHTML: el => { const v = ptOf(el.style.letterSpacing); return v ? v + 'pt' : null; },
                    renderHTML: a => a.letterSpacing ? { style: `letter-spacing:${a.letterSpacing}` } : {},
                },
            },
        }];
    },
    addCommands() {
        return {
            setLetterSpacing: v => ({ chain }) => chain().setMark('textStyle', { letterSpacing: v }).run(),
            unsetLetterSpacing: () => ({ chain }) => chain().setMark('textStyle', { letterSpacing: null }).removeEmptyTextStyle().run(),
        };
    },
});

/** גרסת המבנה של ה-JSON. עולה כשהמבנה משתנה; schema.js ו-lib/docs.php יודעים להמיר גרסאות ישנות. */
export const SCHEMA_VERSION = 1;

/**
 * מאפיינים של המסמך עצמו (צומת השורש): הגדרות העמוד וגרסת המבנה.
 * הגדרות העמוד בתוך המסמך — לכן הן נשמרות יחד עם התוכן באותה גרסה, ו-Ctrl+Z מבטל גם אותן.
 */
export const DocAttrs = Extension.create({
    name: 'wpDocAttrs',
    addGlobalAttributes() {
        return [{ types: ['doc'], attributes: {
            page: { default: null, rendered: false },
            schema: { default: SCHEMA_VERSION, rendered: false },
        } }];
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
        ParagraphFormat, PageBreak, WpImage, LetterSpacing,
        Table.configure({ resizable: true, cellMinWidth: 30, allowTableNodeSelection: true }),
        TableRow, Cell, HeaderCell,
        DocAttrs,
        ...extra,
    ];
}
