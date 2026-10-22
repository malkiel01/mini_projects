/**
 * רישום הפקודות של העורך — מקום אחד לכל פקודה: מזהה, שם, סמל, קיצור מקלדת, הפעולה, ומצב (פעיל / זמין / ערך).
 * סרגל הכלים נבנה ממנו היום; הסרט (Ribbon), חיפוש פקודות ("מה ברצונך לעשות?"), תפריט לחיצה ימנית וקיצורים
 * הניתנים להגדרה — ייבנו ממנו בהמשך, בלי לשכפל לוגיקה.
 *
 *   const cmds = createCommands(x);          // x — ההקשר מ-editor.js (ראו למטה)
 *   cmds.run('bold') · cmds.run('fontSize', '14') · cmds.state('bold') → {active, enabled, value}
 *   cmds.list({ group: 'font' }) · cmds.get('bold').label
 *
 * x: { editor, chain(), ui: { paragraph, table, image, pageSetup, styles, print, versions, painter, zoomIn, zoomOut,
 *      zoomFit, crop, imageProps, imageSize } } — הפעולות שאינן פקודת עורך (חלונות, תצוגה) מגיעות מבחוץ.
 * כל run מחזיר true כשהפקודה בוצעה.
 */

import { styleKeyOf } from './extensions.js';

/** קבוצות — לסדר בסרגל ובסרט, ולחיפוש. */
export const GROUPS = {
    history: 'ביטול',
    editing: 'עריכה',
    style: 'סגנון',
    font: 'גופן',
    paragraph: 'פסקה',
    insert: 'הוספה',
    layout: 'פריסה',
    view: 'תצוגה',
    file: 'קובץ',
    table: 'טבלה',
    image: 'תמונה',
};

const isImage = x => x.editor.state.selection.node?.type.name === 'image';
const inTable = x => x.editor.isActive('table');
const ts = x => x.editor.getAttributes('textStyle');
const imgSet = (x, attrs) => {
    const pos = x.editor.state.selection.from;
    return x.chain().updateAttributes('image', attrs).setNodeSelection(pos).run();
};
const imgAttrs = x => x.editor.state.selection.node?.attrs || {};

/** @type {Array<{id:string,label:string,icon?:string,keys?:string,group:string,when?:(x)=>boolean,
 *   run:(x,arg?)=>any, active?:(x)=>boolean, enabled?:(x)=>boolean, value?:(x)=>string, edit?:boolean}>} */
export const COMMANDS = [
    // ---------- ביטול ----------
    { id: 'undo', label: 'ביטול', icon: '↶', keys: 'Ctrl+Z', group: 'history', run: x => x.chain().undo().run(), enabled: x => x.editor.can().undo() },
    { id: 'redo', label: 'ביצוע מחדש', icon: '↷', keys: 'Ctrl+Y', group: 'history', run: x => x.chain().redo().run(), enabled: x => x.editor.can().redo() },

    // ---------- סגנון וגופן ----------
    { id: 'style', label: 'סגנון', group: 'style', run: (x, v) => x.chain().setStyle(v).run(),
        value: x => { const b = x.editor.state.selection.$from.parent; return ['paragraph', 'heading'].includes(b.type.name) ? styleKeyOf(b) : 'normal'; } },
    { id: 'newStyle', label: 'סגנון חדש מהבחירה', icon: '✚', group: 'style', run: x => x.ui.newStyle() },
    { id: 'updateStyle', label: 'עדכון הסגנון לפי הבחירה', icon: '⟲', group: 'style', run: x => x.ui.updateStyle() },
    { id: 'fontFamily', label: 'גופן', group: 'font', run: (x, v) => v ? x.chain().setFontFamily(v).run() : x.chain().unsetFontFamily().run(),
        value: x => ts(x).fontFamily || '' },
    { id: 'fontSize', label: 'גודל גופן', group: 'font', run: (x, v) => v ? x.chain().setFontSize(v + 'pt').run() : x.chain().unsetFontSize().run(),
        value: x => ts(x).fontSize ? String(parseFloat(ts(x).fontSize)) : '' },
    { id: 'bold', label: 'מודגש', icon: 'B', keys: 'Ctrl+B', group: 'font', run: x => x.chain().toggleBold().run(), active: x => x.editor.isActive('bold') },
    { id: 'italic', label: 'נטוי', icon: 'I', keys: 'Ctrl+I', group: 'font', run: x => x.chain().toggleItalic().run(), active: x => x.editor.isActive('italic') },
    { id: 'underline', label: 'קו תחתון', icon: 'U', keys: 'Ctrl+U', group: 'font', run: x => x.chain().toggleUnderline().run(), active: x => x.editor.isActive('underline') },
    { id: 'strike', label: 'קו חוצה', icon: 'S', group: 'font', run: x => x.chain().toggleStrike().run(), active: x => x.editor.isActive('strike') },
    { id: 'superscript', label: 'כתב עילי', icon: 'x²', group: 'font', run: x => x.chain().toggleSuperscript().run(), active: x => x.editor.isActive('superscript') },
    { id: 'subscript', label: 'כתב תחתי', icon: 'x₂', group: 'font', run: x => x.chain().toggleSubscript().run(), active: x => x.editor.isActive('subscript') },
    { id: 'color', label: 'צבע טקסט', icon: 'A', group: 'font', run: (x, v) => x.chain().setColor(v).run() },
    { id: 'highlight', label: 'צבע הדגשה', icon: '🖍', group: 'font', run: (x, v) => x.chain().setHighlight({ color: v }).run() },
    { id: 'letterSpacing', label: 'ריווח בין תווים', group: 'font', run: (x, v) => v ? x.chain().setLetterSpacing(v + 'pt').run() : x.chain().unsetLetterSpacing().run(),
        value: x => ts(x).letterSpacing ? String(parseFloat(ts(x).letterSpacing)) : '' },
    { id: 'formatPainter', label: 'העתקת עיצוב (לחיצה כפולה — לכמה מקומות)', icon: '🖌', group: 'font', run: (x, sticky) => x.ui.painter(!!sticky) },
    { id: 'growFont', label: 'הגדלת גופן', icon: 'A+', keys: 'Ctrl+Shift+>', group: 'font', run: x => x.ui.stepFont(1) },
    { id: 'shrinkFont', label: 'הקטנת גופן', icon: 'A−', keys: 'Ctrl+Shift+<', group: 'font', run: x => x.ui.stepFont(-1) },
    { id: 'underlineStyle', label: 'סוג קו תחתון', group: 'font', run: (x, v) => x.chain().setMark('underline', { ul: v || null }).run(),
        value: x => (x.editor.isActive('underline') ? x.editor.getAttributes('underline').ul || '' : '') },
    { id: 'clearFormat', label: 'ניקוי עיצוב', icon: '⌧', group: 'font',
        run: x => x.chain().unsetAllMarks().setBlockAttrs({ lineHeight: null, spaceBefore: null, spaceAfter: null, indent: 0 }).unsetTextAlign().run() },

    // ---------- פסקה ----------
    ...[['alignRight', 'right', 'יישור לימין'], ['alignCenter', 'center', 'מרכוז'], ['alignLeft', 'left', 'יישור לשמאל'], ['alignJustify', 'justify', 'יישור לשני הצדדים']]
        .map(([id, a, label]) => ({ id, label, group: 'paragraph', align: a, run: x => x.chain().setTextAlign(a).run(), active: x => x.editor.isActive({ textAlign: a }) })),
    { id: 'bulletList', label: 'תבליטים', icon: '•≡', group: 'paragraph', run: x => x.chain().toggleBulletList().run(), active: x => x.editor.isActive('bulletList') },
    { id: 'orderedList', label: 'רשימה ממוספרת', icon: '1≡', group: 'paragraph', run: x => x.chain().toggleOrderedList().run(), active: x => x.editor.isActive('orderedList') },
    { id: 'listType', label: 'סוג מספור', group: 'paragraph',
        run: (x, v) => { if (!x.editor.isActive('orderedList')) x.chain().toggleOrderedList().run(); return x.chain().updateAttributes('orderedList', { listType: v }).run(); },
        value: x => x.editor.isActive('orderedList') ? (x.editor.getAttributes('orderedList').listType || 'decimal') : '' },
    { id: 'restartNumbering', label: 'התחלה מחדש ב-1', icon: '↺', group: 'paragraph', enabled: x => x.editor.isActive('orderedList'),
        run: x => x.chain().setListNumbering({ start: 1, cont: false }).run() },
    { id: 'continueNumbering', label: 'המשך מספור', icon: '⤵', group: 'paragraph', enabled: x => x.editor.isActive('orderedList'),
        run: x => x.chain().setListNumbering({ cont: true }).run(), active: x => !!x.editor.getAttributes('orderedList').cont },
    { id: 'numberingValue', label: 'קביעת ערך המספור', icon: '#', group: 'paragraph', enabled: x => x.editor.isActive('orderedList'), run: x => x.ui.numberingValue() },
    { id: 'headingNumbers', label: 'מספור כותרות', icon: '§', group: 'paragraph', run: x => x.ui.headingNumbers(), active: x => x.ui.headingNumbersOn() },
    { id: 'outdent', label: 'הקטנת הזחה', icon: '⇤', group: 'paragraph',
        run: x => x.editor.isActive('listItem') ? x.chain().liftListItem('listItem').run() : x.chain().changeIndent(-1).run() },
    { id: 'indent', label: 'הגדלת הזחה', icon: '⇥', group: 'paragraph',
        run: x => x.editor.isActive('listItem') ? x.chain().sinkListItem('listItem').run() : x.chain().changeIndent(1).run() },
    { id: 'paragraphDialog', label: 'פסקה: ריווח, הזחה, כיוון', icon: '¶', group: 'paragraph', run: x => x.ui.paragraph() },

    // ---------- הוספה ----------
    { id: 'insertTable', label: 'טבלה', icon: '▦', group: 'insert', run: x => x.ui.table() },
    { id: 'insertImage', label: 'תמונה', icon: '🖼', group: 'insert', run: x => x.ui.image() },
    { id: 'footnote', label: 'הערת שוליים', icon: '¹', keys: 'Ctrl+Alt+F', group: 'insert', run: x => x.ui.footnote() },
    { id: 'link', label: 'קישור', icon: '🔗', keys: 'Ctrl+K', group: 'insert', run: x => x.ui.link(), active: x => x.editor.isActive('link') },
    { id: 'unlink', label: 'הסרת קישור', icon: '⛓', group: 'insert', enabled: x => x.editor.isActive('link'), run: x => x.chain().unsetLink().run() },
    { id: 'bookmark', label: 'סימניה', icon: '🔖', group: 'insert', run: x => x.ui.bookmark() },
    { id: 'pageBreak', label: 'מעבר עמוד', icon: '⤓', keys: 'Ctrl+Enter', group: 'insert', run: x => x.chain().insertPageBreak().run() },
    { id: 'sectionBreak', label: 'מעבר מקטע: העמוד הבא בכיוון ההפוך', icon: '⧉', group: 'layout',
        run: (x, page) => x.chain().insertSectionBreak(page || { orientation: x.ui.sectionPage().orientation === 'landscape' ? 'portrait' : 'landscape' }).run() },

    // ---------- עריכה: חיפוש והחלפה (find.js) ----------
    { id: 'find', label: 'חיפוש', icon: '🔎', keys: 'Ctrl+F', group: 'editing', edit: false, run: x => x.ui.find('find') },
    { id: 'replace', label: 'החלפה', icon: '⇄', keys: 'Ctrl+H', group: 'editing', run: x => x.ui.find('replace') },

    // ---------- פריסה וקובץ ----------
    { id: 'pageSetup', label: 'הגדרות עמוד ומספור עמודים', icon: '📄', group: 'layout', run: x => x.ui.pageSetup() },
    { id: 'editStyles', label: 'עריכת סגנונות', icon: '🅰', group: 'layout', run: x => x.ui.styles() },
    { id: 'save', label: 'שמירה', icon: '💾', keys: 'Ctrl+S', group: 'file', when: x => !!x.ui.save, run: x => x.ui.save() },
    { id: 'print', label: 'הדפסה / PDF', icon: '🖨', group: 'file', edit: false, run: x => x.ui.print() },
    { id: 'printPreview', label: 'תצוגה לפני הדפסה', icon: '🔍', group: 'file', edit: false, run: x => x.ui.printPreview() },
    { id: 'wordCount', label: 'ספירת מילים', icon: '🔢', keys: 'Ctrl+Shift+G', group: 'view', edit: false, run: x => x.ui.wordCount() },
    { id: 'navPane', label: 'חלונית ניווט', icon: '🧭', group: 'view', edit: false, run: x => x.ui.navPane(), active: x => x.ui.navOn() },
    { id: 'showMarks', label: 'סימני פסקה', icon: '¶', keys: 'Ctrl+Shift+8', group: 'view', edit: false, run: x => x.ui.showMarks(), active: x => x.ui.marksOn() },
    { id: 'commandSearch', label: 'חיפוש פקודה', icon: '⌕', keys: 'Alt+Q', group: 'view', edit: false, run: x => x.ui.commandSearch() },
    { id: 'versions', label: 'גרסאות', icon: '🕘', group: 'file', edit: false, when: x => !!x.ui.versions, run: x => x.ui.versions() },

    // ---------- תצוגה (גם בקריאה בלבד) ----------
    { id: 'zoomOut', label: 'הקטנה', icon: '−', group: 'view', edit: false, run: x => x.ui.zoomOut() },
    { id: 'zoomIn', label: 'הגדלה', icon: '+', group: 'view', edit: false, run: x => x.ui.zoomIn() },
    { id: 'zoomFit', label: 'התאמה למסך', icon: '⤢', group: 'view', edit: false, run: x => x.ui.zoomFit() },

    // ---------- טבלה (כשהסמן בטבלה) ----------
    ...[['addRowBefore', 'שורה מעל', '⬆+'], ['addRowAfter', 'שורה מתחת', '⬇+'], ['addColumnBefore', 'עמודה מימין', '➡+'], ['addColumnAfter', 'עמודה משמאל', '⬅+'],
        ['deleteRow', 'מחיקת שורה', '−⇆'], ['deleteColumn', 'מחיקת עמודה', '−⇅'], ['mergeOrSplit', 'מיזוג / פיצול תאים', '⊞'],
        ['toggleHeaderRow', 'שורת כותרת', '▤'], ['deleteTable', 'מחיקת הטבלה', '🗑']]
        .map(([id, label, icon]) => ({ id: 'table.' + id, label, icon, group: 'table', when: inTable, run: x => x.chain()[id]().run() })),
    { id: 'table.props', label: 'מאפייני טבלה: גבולות, רוחב, יישור', icon: '⚙', group: 'table', when: inTable, run: x => x.ui.tableProps() },
    { id: 'table.cellFill', label: 'מילוי תא', icon: '◩', group: 'table', when: inTable, run: (x, v) => x.chain().setCellAttribute('background', v).run() },

    // ---------- תמונה (כשתמונה בחורה) ----------
    { id: 'image.wrap', label: 'גלישת טקסט', group: 'image', when: isImage, run: (x, v) => imgSet(x, { wrap: v, x: 0, y: 0 }), value: x => imgAttrs(x).wrap || 'inline' },
    { id: 'image.align', label: 'יישור', group: 'image', when: isImage, run: (x, v) => imgSet(x, { align: v }), value: x => imgAttrs(x).align || '' },
    { id: 'image.size', label: 'גודל', group: 'image', when: isImage, run: (x, f) => x.ui.imageSize(+f) },
    { id: 'image.rotateLeft', label: 'סיבוב שמאלה', icon: '⟲', group: 'image', when: isImage, run: x => imgSet(x, { rotate: ((imgAttrs(x).rotate || 0) + 270) % 360 }) },
    { id: 'image.rotateRight', label: 'סיבוב ימינה', icon: '⟳', group: 'image', when: isImage, run: x => imgSet(x, { rotate: ((imgAttrs(x).rotate || 0) + 90) % 360 }) },
    { id: 'image.crop', label: 'חיתוך', icon: '✂', group: 'image', when: isImage, run: x => x.ui.crop(x.editor.state.selection.from) },
    { id: 'image.props', label: 'כיתוב, מרחק מהטקסט ותיאור', icon: '⚙', group: 'image', when: isImage, run: x => x.ui.imageProps(x.editor.state.selection.from) },
    { id: 'image.delete', label: 'מחיקת התמונה', icon: '🗑', group: 'image', when: isImage, run: x => x.chain().deleteSelection().run() },
];

export function createCommands(x, list = COMMANDS) {
    const byId = new Map(list.map(c => [c.id, c]));
    const can = c => (c.edit === false || x.editor.isEditable) && (!c.when || c.when(x));
    return {
        get: id => byId.get(id),
        /** הפקודות (אפשר לסנן: group, available — רק מה שאפשר להפעיל עכשיו). */
        list({ group, available } = {}) {
            return list.filter(c => (!group || c.group === group) && (!available || can(c)));
        },
        /** מפעיל פקודה. פקודה לא קיימת או לא זמינה עכשיו → false, בלי חריגה. */
        run(id, arg) {
            const c = byId.get(id);
            if (!c || !can(c) || (c.enabled && !c.enabled(x))) return false;
            const r = c.run(x, arg);
            return r !== false;
        },
        state(id) {
            const c = byId.get(id);
            if (!c) return null;
            return { active: !!c.active?.(x), enabled: can(c) && (!c.enabled || !!c.enabled(x)), value: c.value?.(x) ?? null };
        },
        /** "מודגש (Ctrl+B)" — לכותרת הכפתור. */
        title: id => { const c = byId.get(id); return c ? c.label + (c.keys ? ` (${c.keys})` : '') : id; },
    };
}
