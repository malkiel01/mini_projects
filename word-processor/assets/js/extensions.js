/**
 * ההרחבות שלנו מעל TipTap (החלקים החינמיים בלבד — embed.md, פרק 0):
 * עיצוב פסקה (כיוון, הזחה, ריווח, כותרת מסמך), רשימה ממוספרת 1./א./i., תמונה עם גודל וגלישה,
 * מעבר עמוד ידני, ומילוי תא. המבנה זהה למה שהשרת מייצא (lib/export.php).
 */
import {
    Extension, Node, Mark, Plugin, PluginKey, mergeAttributes, StarterKit, TextStyle, Color, FontFamily, FontSize, TextAlign, Highlight,
    Subscript, Superscript, Table, TableRow, TableCell, TableHeader, OrderedList, TextSelection,
} from '../../vendor/tiptap.js';

const BLOCKS = ['paragraph', 'heading'];
/** מפתח של סגנון שהמשתמש יצר (u1, u2…) — כמו USER_STYLE_RE ב-lib/page.php. */
export const USER_STYLE = /^u[1-9]\d{0,2}$/;
/** מפתח הסגנון של בלוק: h1..h9, title, u<N>, או normal. */
export const styleKeyOf = node => node.type.name === 'heading' ? 'h' + node.attrs.level
    : (node.attrs.pstyle === 'title' || USER_STYLE.test(node.attrs.pstyle || '')) ? node.attrs.pstyle : 'normal';

/** הגופנים הזמינים — אותם קבצים במסך, בהדפסה וב-PDF (lib/pdf/font.php, FontSet::FAMILIES). */
export const FONTS = ['David', 'Frank Ruehl', 'Arial', 'Narkisim', 'Miriam', 'Times New Roman', 'Calibri', 'Tahoma',
    'David Libre', 'Frank Ruhl Libre', 'Miriam Libre', 'Heebo', 'Assistant', 'Rubik', 'Alef'];
export const FALLBACK = { David: "'David Libre', serif", 'Frank Ruehl': "'Frank Ruhl Libre', serif", Narkisim: "'Assistant', sans-serif",
    Miriam: "'Miriam Libre', sans-serif", Arial: "'Heebo', sans-serif", 'Times New Roman': "'Frank Ruhl Libre', serif",
    Calibri: "'Assistant', sans-serif", Tahoma: "'Heebo', sans-serif" };

/**
 * font-family ל-CSS. גופן שאינו ברשימה — Heebo בלבד, בלי השם: אחרת מחשב שהגופן מותקן אצלו מציג אותו, וה-PDF
 * (שמציג Heebo) והעימוד במחשב אחר — שונים. השם המקורי נשמר במסמך (ובייצוא ל-Word).
 */
export function cssFont(f) {
    if (!FONTS.includes(f)) return "'Heebo', sans-serif";
    return `'${String(f).replace(/'/g, '')}', ${FALLBACK[f] || 'sans-serif'}`;
}

/** גופן לטקסט: מוצג דרך cssFont; השם המקורי — ב-data-font, כדי שהעתקה והדבקה בתוך העורך ישמרו אותו. */
const WpFontFamily = FontFamily.extend({
    addGlobalAttributes() {
        return [{
            types: this.options.types,
            attributes: {
                fontFamily: {
                    default: null,
                    parseHTML: el => el.dataset.font || el.style.fontFamily?.split(',')[0].trim().replace(/^['"]|['"]$/g, '') || null,
                    renderHTML: a => a.fontFamily ? { style: `font-family: ${cssFont(a.fontFamily)}`, 'data-font': a.fontFamily } : {},
                },
            },
        }];
    },
});
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
                // שורה ראשונה (נק'): חיובי — הזחה, שלילי — תלויה (כל השאר זזות פנימה). כמו first_line_css בשרת
                firstLine: {
                    default: null,
                    parseHTML: el => { const v = ptOf(el.style.textIndent); return v ? Math.max(-200, Math.min(200, v)) : null; },
                    renderHTML: a => {
                        const v = num(a.firstLine);
                        if (!v) return {};
                        return { style: `text-indent:${v}pt` + (v < 0 ? `;padding-inline-start:${-v}pt` : '') };
                    },
                },
                letterSpacing: {
                    default: null,
                    parseHTML: el => ptOf(el.style.letterSpacing),
                    renderHTML: a => num(a.letterSpacing) ? { style: `letter-spacing:${a.letterSpacing}pt` } : {},
                },
                // שבירת עמודים (כמו ב-Word): שמירה עם הבא, שמירת שורות יחד, מעבר עמוד לפני. העימוד (pagination.js) והשרת
                // (lib/pdf/layout.php) קוראים אותן; ב-HTML — רק סימון (data-*), כי השבירה בהדפסה היא של העימוד שלנו.
                // מ-Word (הדבקה): page-break-after:avoid / page-break-inside:avoid / page-break-before:always
                keepNext: {
                    default: null,
                    parseHTML: el => el.hasAttribute('data-keep-next') || /avoid/.test(el.style.pageBreakAfter || el.style.breakAfter || '') || null,
                    renderHTML: a => a.keepNext ? { 'data-keep-next': '' } : {},
                },
                keepLines: {
                    default: null,
                    parseHTML: el => el.hasAttribute('data-keep-lines') || /avoid/.test(el.style.pageBreakInside || el.style.breakInside || '') || null,
                    renderHTML: a => a.keepLines ? { 'data-keep-lines': '' } : {},
                },
                breakBefore: {
                    default: null,
                    parseHTML: el => el.hasAttribute('data-break-before') || /always|page/.test(el.style.pageBreakBefore || el.style.breakBefore || '') || null,
                    renderHTML: a => a.breakBefore ? { 'data-break-before': '' } : {},
                },
            },
        }, {
            types: ['paragraph'],
            attributes: {
                // 'title' או סגנון של המשתמש ('u1'…) — הגדרותיו ב-page.styles
                pstyle: {
                    default: null,
                    parseHTML: el => el.classList.contains('wp-title') || el.classList.contains('MsoTitle') ? 'title'
                        : (el.getAttribute('data-style') && USER_STYLE.test(el.getAttribute('data-style')) ? el.getAttribute('data-style') : null),
                    renderHTML: a => a.pstyle === 'title' ? { class: 'wp-title' } : USER_STYLE.test(a.pstyle || '') ? { class: 'wp-s-' + a.pstyle, 'data-style': a.pstyle } : {},
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
                // צעד ביטול משלו (כמו "אישור" בחלון של Word): בלי זה שני שינויים באותה פסקה בתוך חצי שנייה מתאחדים.
                // 'closeHistory$' — המפתח של closeHistory ב-prosemirror-history (לא מיוצא מ-vendor/tiptap.js)
                tr.setMeta('closeHistory$', true);
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
            /** סגנון: 'normal' | 'title' | 'h1'..'h9' | סגנון משתמש 'u1'…. */
            setStyle: s => ({ chain }) => {
                if (s === 'normal') return chain().setParagraph().setBlockAttrs({ pstyle: null }).run();
                if (s === 'title' || USER_STYLE.test(s)) return chain().setParagraph().setBlockAttrs({ pstyle: s }).run();
                return chain().setHeading({ level: Math.max(1, Math.min(9, +s.slice(1))) }).run();
            },
        };
    },
    addKeyboardShortcuts() {
        const inList = ed => ed.isActive('listItem');
        return {
            // Tab: ברשימה — רמה פנימה; בתחילת פסקה — הזחה; באמצע הטקסט — תו טאב (כמו ב-Word)
            Tab: () => {
                const ed = this.editor;
                if (inList(ed)) return ed.commands.sinkListItem('listItem');
                const { empty, $from } = ed.state.selection;
                if (empty && $from.parentOffset > 0 && $from.parent.isTextblock) return ed.commands.insertContent('\t');
                return ed.commands.changeIndent(1);
            },
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
            // 1. / א. / i. / רב-רמתי 1.1.1 (גם הרשימות שבתוכה מוצגות כך — כמו רשימה רב-רמתית ב-Word)
            listType: {
                default: 'decimal',
                parseHTML: el => {
                    const t = el.getAttribute('data-list') || el.getAttribute('type') || el.style.listStyleType || '';
                    if (t === 'multi') return 'multi';
                    if (/hebrew/.test(t) || el.classList.contains('hebrew')) return 'hebrew';
                    if (/roman|^i$/i.test(t) || el.classList.contains('roman')) return 'roman';
                    return 'decimal';
                },
                renderHTML: a => ({ 'data-list': a.listType || 'decimal' }),
            },
            // "המשך מספור": המספר הראשון = אחרי הרשימה הממוספרת הקודמת באותה רמה (ListContinue מעדכן את start)
            cont: { default: false, parseHTML: el => el.hasAttribute('data-cont'), renderHTML: a => (a.cont ? { 'data-cont': '' } : {}) },
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        const start = node.attrs.start ?? 1;
        const { start: _s, ...rest } = HTMLAttributes;
        // מונה ל-1.1.1 (editor.css): counter-reset לפני המספר הראשון
        return ['ol', { ...rest, ...(start !== 1 ? { start } : {}), style: `counter-reset: wpl ${start - 1}` }, 0];
    },
    addCommands() {
        return {
            ...this.parent?.(),
            /** הרשימה שבה הסמן: התחלה מחדש (ב-n), או המשך מהרשימה הקודמת. */
            setListNumbering: ({ start, cont }) => ({ state, tr, dispatch }) => {
                const $f = state.selection.$from;
                for (let d = $f.depth; d > 0; d--) {
                    const n = $f.node(d);
                    if (n.type.name !== 'orderedList') continue;
                    if (dispatch) tr.setNodeMarkup($f.before(d), undefined, { ...n.attrs, cont: !!cont, ...(start != null ? { start: Math.max(0, Math.min(9999, start | 0)) } : {}) });
                    return true;
                }
                return false;
            },
        };
    },
    addProseMirrorPlugins() {
        return [...(this.parent?.() || []), listContinue()];
    },
});

/**
 * "המשך מספור": אחרי כל שינוי — רשימה ממוספרת עם cont מתחילה אחרי הקודמת שבאותו הורה (הרשימה הממוספרת
 * האחרונה לפניה, גם אם יש ביניהן פסקאות). start נשמר במסמך — לכן ה-PDF, Word וה-HTML מקבלים את אותו מספר.
 */
function listContinue() {
    return new Plugin({
        key: new PluginKey('wpListContinue'),
        appendTransaction(trs, old, state) {
            if (!trs.some(t => t.docChanged)) return null;
            const tr = state.tr;
            const walk = (parent, base) => {
                let last = null;   // {start, count} של הרשימה הממוספרת האחרונה בהורה הזה
                parent.forEach((n, off) => {
                    const pos = base + off;
                    if (n.type.name === 'orderedList') {
                        let start = n.attrs.start ?? 1;
                        if (n.attrs.cont && last) {
                            start = last.start + last.count;
                            if (start !== n.attrs.start) tr.setNodeMarkup(pos, undefined, { ...n.attrs, start });
                        }
                        last = { start, count: n.childCount };
                    }
                    if (!n.isTextblock && !n.isAtom && n.childCount) walk(n, pos + 1);
                });
            };
            walk(state.doc, 0);
            return tr.docChanged ? tr : null;   // ההיסטוריה מצרפת אותו לעסקה שגרמה לו — ביטול אחד
        },
    });
}

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

/**
 * מעבר מקטע (עמוד הבא) — כמו ב-Word: מכאן מתחיל מקטע חדש בעמוד חדש, עם הגדרות עמוד משלו (attrs.page —
 * רק מה שמשתנה לעומת המקטע הקודם: גודל, כיוון, שוליים, כותרות ומספור). המסמך נשאר שטוח: הצומת מסמן את הגבול.
 */
export const SectionBreak = Node.create({
    name: 'sectionBreak',
    group: 'block',
    atom: true,
    selectable: true,
    addAttributes() {
        return {
            page: {
                default: null,
                parseHTML: el => { try { return JSON.parse(el.getAttribute('data-page') || 'null'); } catch { return null; } },
                renderHTML: a => ({ 'data-page': JSON.stringify(a.page || {}) }),
            },
        };
    },
    parseHTML() {
        return [{ tag: 'div[data-section-break]' }];
    },
    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-section-break': '', class: 'wp-section-break', contenteditable: 'false' })];
    },
    addCommands() {
        return {
            insertSectionBreak: page => ({ chain }) => chain().insertContent({ type: 'sectionBreak', attrs: { page: page || null } }).createParagraphNear().run(),
        };
    },
});

/**
 * הערת שוליים: סימן בטקסט (מספר עילי, ממוספר לבד לפי הסדר במסמך — CSS counter), והטקסט שלה ב-attrs.text.
 * ההערה עצמה מוצגת בתחתית העמוד שבו הסימן (pagination.js) — גם בהדפסה. עריכה — בלחיצה על הסימן או על ההערה.
 */
export const Footnote = Node.create({
    name: 'footnote',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,
    addAttributes() {
        return {
            text: { default: '', parseHTML: el => el.getAttribute('data-text') || '', renderHTML: a => ({ 'data-text': a.text || '' }) },
            // אותו טקסט עם עיצוב (צמתי text עם marks: מודגש, נטוי, קו תחתון, קו חוצה, עילי, תחתי) — או null
            content: {
                default: null,
                parseHTML: el => { try { return JSON.parse(el.getAttribute('data-content') || 'null'); } catch { return null; } },
                renderHTML: a => (a.content ? { 'data-content': JSON.stringify(a.content) } : {}),
            },
        };
    },
    parseHTML() {
        return [{ tag: 'sup[data-footnote]' }];
    },
    renderHTML({ HTMLAttributes }) {
        return ['sup', mergeAttributes(HTMLAttributes, { 'data-footnote': '', class: 'wp-fn-ref', contenteditable: 'false' })];
    },
    addCommands() {
        return {
            insertFootnote: (text, content = null) => ({ chain }) => chain().insertContent({ type: 'footnote', attrs: { text: text || '', content } }).run(),
        };
    },
});

/**
 * כתובת קישור בטוחה: http(s), mailto, tel, או "#שם" (סימניה במסמך). דוא"ל בלי mailto ו-www… בלי https — משלימים.
 * כל השאר (javascript: וכו') → null — לא נכנס למסמך.
 */
export function safeHref(v) {
    const s = String(v ?? '').trim();
    if (!s) return null;
    if (s.startsWith('#')) return s.length > 1 ? s : null;
    if (/^(https?:\/\/|mailto:|tel:)/i.test(s)) return s;
    if (/^[\w.+-]+@[\w-]+(\.[\w-]+)+$/.test(s)) return 'mailto:' + s;
    if (/^(www\.)?[\w-]+(\.[\w-]+)*\.[a-z]{2,}([/?#:]\S*)?$/i.test(s)) return 'https://' + s;
    return null;
}

/** שם סימניה כמו ב-Word: מתחיל באות, אותיות / ספרות / קו תחתון, עד 40 תווים. */
export const BOOKMARK_RE = /^[\p{L}][\p{L}\p{N}_]{0,39}$/u;

/**
 * קישור (Ctrl+K). Ctrl+לחיצה פותחת (כמו ב-Word); בקריאה בלבד — לחיצה רגילה. "#שם" — קפיצה לסימניה במסמך.
 * הקלדת כתובת ואחריה רווח / Enter — הופכת לקישור לבד.
 */
export const Link = Mark.create({
    name: 'link',
    priority: 1000,
    inclusive: false,
    addAttributes() {
        return { href: { default: null, parseHTML: el => safeHref(el.getAttribute('href')) } };
    },
    parseHTML() {
        return [{ tag: 'a[href]', getAttrs: el => (safeHref(el.getAttribute('href')) ? null : false) }];
    },
    renderHTML({ HTMLAttributes }) {
        const href = safeHref(HTMLAttributes.href);
        const ext = href && !href.startsWith('#');
        return ['a', mergeAttributes(HTMLAttributes, { href, class: 'wp-link', title: `${href || ''}\nCtrl+לחיצה — מעבר לקישור`,
            ...(ext ? { target: '_blank', rel: 'noopener noreferrer nofollow' } : {}) }), 0];
    },
    addCommands() {
        return {
            setLink: href => ({ chain }) => (safeHref(href) ? chain().setMark('link', { href: safeHref(href) }).run() : false),
            unsetLink: () => ({ chain }) => chain().extendMarkRange('link').unsetMark('link').run(),
            /** טקסט חדש כקישור, במקום הבחירה. */
            insertLink: (text, href) => ({ chain }) => (safeHref(href) && text
                ? chain().insertContent({ type: 'text', text, marks: [{ type: 'link', attrs: { href: safeHref(href) } }] }).unsetMark('link').run() : false),
        };
    },
    addProseMirrorPlugins() {
        const type = this.type;
        const editor = this.editor;
        return [new Plugin({
            key: new PluginKey('wpLink'),
            props: {
                handleClick(view, pos, event) {
                    if (event.button !== 0) return false;
                    if (view.editable && !(event.ctrlKey || event.metaKey)) return false;
                    const mark = view.state.doc.resolve(pos).marks().find(m => m.type === type)
                        || (pos < view.state.doc.content.size ? view.state.doc.resolve(pos + 1).marks().find(m => m.type === type) : null);
                    const href = mark && safeHref(mark.attrs.href);
                    if (!href) return false;
                    event.preventDefault();
                    if (href.startsWith('#')) editor.commands.goToBookmark(href.slice(1));
                    else window.open(href, '_blank', 'noopener,noreferrer');
                    return true;
                },
                // כתובת שהוקלדה, ואחריה רווח — קישור
                handleTextInput(view, from, to, text) {
                    if (text !== ' ' || from !== to) return false;
                    const $f = view.state.doc.resolve(from);
                    if (!$f.parent.isTextblock) return false;
                    const before = $f.parent.textBetween(Math.max(0, $f.parentOffset - 200), $f.parentOffset, null, '\ufffc');
                    const m = before.match(/(?:https?:\/\/|www\.)[^\s\ufffc]+$/i);
                    if (!m) return false;
                    const url = m[0].replace(/[.,;:!?)\]"'»]+$/, '');
                    const start = from - m[0].length;
                    const href = safeHref(url);
                    if (!href || view.state.doc.rangeHasMark(start, start + url.length, type)) return false;
                    view.dispatch(view.state.tr.addMark(start, start + url.length, type.create({ href })).insertText(' ', from, to));
                    return true;
                },
            },
        })];
    },
});

/** סימניה: נקודה בשם במסמך (בלי טקסט) — יעד לקישור "#שם", וב-Word bookmarkStart/End. */
export const Bookmark = Node.create({
    name: 'bookmark',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,
    addAttributes() {
        return { name: { default: '', parseHTML: el => el.getAttribute('data-bookmark') || el.getAttribute('name') || '' } };
    },
    parseHTML() {
        return [{ tag: 'a[data-bookmark]' }, { tag: 'a[name]:not([href])', getAttrs: el => (BOOKMARK_RE.test(el.getAttribute('name') || '') ? null : false) }];
    },
    renderHTML({ node }) {
        return ['a', { 'data-bookmark': node.attrs.name, class: 'wp-bookmark', contenteditable: 'false', title: 'סימניה: ' + node.attrs.name }];
    },
    renderText: () => '',
    addCommands() {
        const find = (doc, name) => { let at = -1; doc.descendants((n, pos) => { if (at < 0 && n.type.name === 'bookmark' && n.attrs.name === name) at = pos; return at < 0; }); return at; };
        return {
            /** סימניה בשם הזה במקום הסמן; קיימת כבר — עוברת לכאן (כמו ב-Word). */
            setBookmark: name => ({ state, tr, dispatch }) => {
                if (!BOOKMARK_RE.test(name)) return false;
                if (dispatch) {
                    const old = find(state.doc, name);
                    const at = tr.selection.from;
                    tr.insert(at, this.type.create({ name }));
                    if (old >= 0) tr.delete(tr.mapping.map(old), tr.mapping.map(old) + 1);
                }
                return true;
            },
            deleteBookmark: name => ({ state, tr, dispatch }) => {
                const at = find(state.doc, name);
                if (at < 0) return false;
                if (dispatch) tr.delete(at, at + 1);
                return true;
            },
            goToBookmark: name => ({ state, tr, dispatch, view }) => {
                const at = find(state.doc, name);
                if (at < 0) return false;
                if (dispatch) {
                    tr.setSelection(TextSelection.near(tr.doc.resolve(at + 1))).scrollIntoView();
                    requestAnimationFrame(() => view.dom.querySelector(`[data-bookmark="${CSS.escape(name)}"]`)?.scrollIntoView({ block: 'center' }));
                }
                return true;
            },
        };
    },
});

/** סוגי קו תחתון (underline.attrs.ul) — כמו UNDERLINES ב-lib/page.php. ריק = רגיל. */
export const UNDERLINES = { '': 'קו רגיל', double: 'כפול', dotted: 'מנוקד', dashed: 'מקווקו', wavy: 'גלי', thick: 'עבה' };
const UnderlineStyle = Extension.create({
    name: 'wpUnderlineStyle',
    addGlobalAttributes() {
        return [{ types: ['underline'], attributes: { ul: {
            default: null,
            parseHTML: el => { const s = el.getAttribute('data-ul') || el.style.textDecorationStyle; return s && s !== 'solid' && UNDERLINES[s] ? s : null; },
            renderHTML: a => (UNDERLINES[a.ul] && a.ul
                ? { 'data-ul': a.ul, style: a.ul === 'thick' ? 'text-decoration-thickness:0.12em' : `text-decoration-style:${a.ul}` } : {}),
        } } }];
    },
});

/** כל הסימניות במסמך: [{name, pos}] (לפי הסדר). */
export function bookmarks(doc) {
    const out = [];
    doc.descendants((n, pos) => { if (n.type.name === 'bookmark') out.push({ name: n.attrs.name, pos }); });
    return out;
}

/** מילוי תא, ויישור אנכי בתא (למעלה — ברירת המחדל). */
export const VALIGNS = { top: 'למעלה', middle: 'באמצע', bottom: 'למטה' };
const cellAttrs = {
    background: {
        default: null,
        parseHTML: el => el.style.backgroundColor || el.getAttribute('bgcolor') || null,
        renderHTML: a => a.background ? { style: `background-color:${a.background}` } : {},
    },
    valign: {
        default: null,
        parseHTML: el => ['middle', 'bottom'].includes(el.style.verticalAlign || el.getAttribute('valign')) ? (el.style.verticalAlign || el.getAttribute('valign')) : null,
        renderHTML: a => ['middle', 'bottom'].includes(a.valign) ? { style: `vertical-align:${a.valign}` } : {},
    },
};
export const Cell = TableCell.extend({ addAttributes() { return { ...this.parent?.(), ...cellAttrs }; } });
export const HeaderCell = TableHeader.extend({ addAttributes() { return { ...this.parent?.(), ...cellAttrs }; } });

/**
 * עיצוב טבלה: גבולות (כולם / מסגרת / פנימיים / בלי), צבע הגבולות, רוחב (% מרוחב השורה) ויישור (ימין / מרכז / שמאל).
 * הגבולות תמיד ברוחב 1px — גבול "כבוי" הוא שקוף (במסך — קו עדין), כך שהגובה והרוחב לא משתנים (וזהים ל-PDF).
 */
export const TABLE_BORDERS = { all: 'כל הגבולות', outer: 'מסגרת בלבד', inner: 'פנימיים בלבד', none: 'ללא גבולות' };
const HEX = /^#[0-9a-f]{6}$/i;
const tableAttrs = {
    borders: {
        default: null,
        parseHTML: el => ['outer', 'inner', 'none'].includes(el.getAttribute('data-borders')) ? el.getAttribute('data-borders') : null,
        renderHTML: a => ['outer', 'inner', 'none'].includes(a.borders) ? { 'data-borders': a.borders } : {},
    },
    borderColor: {
        default: null,
        parseHTML: el => HEX.test(el.getAttribute('data-border-color') || '') ? el.getAttribute('data-border-color').toLowerCase() : null,
        renderHTML: a => HEX.test(a.borderColor || '') ? { 'data-border-color': a.borderColor, style: `--wpt-bc:${a.borderColor}` } : {},
    },
    width: {
        default: null,
        parseHTML: el => { const v = parseInt(el.getAttribute('data-width'), 10); return v >= 10 && v < 100 ? v : null; },
        renderHTML: a => a.width ? { 'data-width': a.width } : {},
    },
    align: {
        default: null,
        parseHTML: el => ['center', 'left', 'right'].includes(el.getAttribute('data-align')) ? el.getAttribute('data-align') : null,
        renderHTML: a => a.align ? { 'data-align': a.align } : {},
    },
};
/** התצוגה של הטבלה (של TipTap — עטיפה, colgroup): מוסיפה את העיצוב על האלמנטים שלה אחרי כל עדכון. */
const BaseTableView = Table.options.View;
class WpTableView extends BaseTableView {
    constructor(node, ...rest) { super(node, ...rest); this.style(node); }
    update(node) { const ok = super.update(node); if (ok) this.style(node); return ok; }
    style(node) {
        const a = node.attrs;
        const t = this.table;
        for (const k of ['borders', 'align']) { if (a[k]) t.setAttribute('data-' + k, a[k]); else t.removeAttribute('data-' + k); }
        t.style.setProperty('--wpt-bc', HEX.test(a.borderColor || '') ? a.borderColor : '');
        if (!HEX.test(a.borderColor || '')) t.style.removeProperty('--wpt-bc');
        // רוחב: אחוז מרוחב השורה (העמודות כבר הותאמו אליו); יישור — לפי השוליים של הטבלה
        if (a.width) { t.style.width = a.width + '%'; t.style.minWidth = ''; }
        const al = a.width ? (a.align || 'right') : null;
        t.style.marginInlineStart = t.style.marginInlineEnd = '';
        t.style.marginLeft = al === 'right' || al === 'center' ? 'auto' : '';
        t.style.marginRight = al === 'left' || al === 'center' ? 'auto' : '';
    }
}
export const WpTable = Table.extend({ addAttributes() { return { ...this.parent?.(), ...tableAttrs }; } });

/** גובה מינימלי לשורה (נק'); התוכן יכול להגביה אותה. */
export const WpTableRow = TableRow.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            height: {
                default: null,
                parseHTML: el => { const v = parseFloat(el.getAttribute('data-height')); return v > 0 ? Math.min(400, v) : null; },
                renderHTML: a => a.height > 0 ? { 'data-height': a.height, style: `height:${a.height}pt` } : {},
            },
        };
    },
});

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
            // שכבה (תמונה מאחורי / לפני הטקסט): גבוה יותר — מעל. שווה — לפי הסדר במסמך
            z: { default: 0, parseHTML: el => Math.max(-50, Math.min(50, num(el.getAttribute('data-z')) || 0)) },
        };
    },
    parseHTML() {
        return [{ tag: 'img[src]', getAttrs: el => (el.getAttribute('data-media') || /^(data:|blob:|https?:|api\.php)/.test(el.getAttribute('src') || '')) ? {} : false }];
    },
    renderHTML({ HTMLAttributes: a }) {
        return ['img', mergeAttributes({ src: a.src, alt: a.alt, width: a.width, height: a.height, 'data-media': a.media, 'data-wrap': a.wrap,
            'data-align': a.align, 'data-dist': a.dist, 'data-crop': a.crop ? JSON.stringify(a.crop) : null, 'data-rotate': a.rotate || null,
            'data-x': a.x || null, 'data-y': a.y || null, 'data-caption': a.caption || null, 'data-z': a.z || null })];
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
                // שכבות: מאחורי הטקסט — מתחת לטקסט (שלילי) ומעל הדפים (‎-200); לפני הטקסט — מעליו
                dom.style.zIndex = ABSOLUTE.includes(wrap) ? String((wrap === 'behind' ? -100 : 10) + Math.max(-50, Math.min(50, a.z || 0))) : '';
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

/**
 * גרסת המבנה של ה-JSON. עולה כשהמבנה משתנה; schema.js ו-lib/docs.php יודעים להמיר גרסאות ישנות.
 * 2: קישור (mark link) וסימניה (צומת bookmark) — לשונית עם קוד ישן לא מכירה אותם, ולכן לא תשמור מסמך כזה.
 */
export const SCHEMA_VERSION = 2;

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
            heading: { levels: [1, 2, 3, 4, 5, 6, 7, 8, 9] },   // h7–h9 אינם תגיות HTML — editor.css נותן להן display:block
            blockquote: false, code: false, codeBlock: false, link: false, orderedList: false, trailingNode: false,
            undoRedo: { depth: 200 },
        }),
        NumberedList,
        TextStyle, Color, WpFontFamily, FontSize,
        Highlight.configure({ multicolor: true }),
        Subscript, Superscript,
        TextAlign.configure({ types: BLOCKS, alignments: ['right', 'center', 'left', 'justify'], defaultAlignment: null }),
        ParagraphFormat, PageBreak, SectionBreak, Footnote, WpImage, LetterSpacing, Link, Bookmark, UnderlineStyle,
        WpTable.configure({ resizable: true, cellMinWidth: 30, allowTableNodeSelection: true, View: WpTableView }),
        WpTableRow, Cell, HeaderCell,
        DocAttrs,
        ...extra,
    ];
}
