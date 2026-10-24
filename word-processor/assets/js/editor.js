/**
 * העורך — משותף לאפליקציה (app.js) ולהטמעה (embed.js).
 *
 *   const ed = createWordEditor(root, { json, page, editable, upload, versions, onUpdate, onPage, menu });
 *   ed.getJSON() · ed.setContent(json) · ed.page · ed.setPage(p) · ed.setEditable(b) · ed.print() · ed.destroy()
 *   ed.commands — כל הפקודות במקום אחד (commands.js); סרגל הכלים נבנה מהן
 *   הגדרות העמוד — בתוך ה-JSON (attrs.page של צומת השורש); opts.page / onPage — לתאימות ולמארחת.
 *
 * מה שעל המסך הוא מה שיודפס: עמודים בגודל אמיתי (מ"מ → פיקסלים ב-96 לאינץ'), שוליים, כותרת עליונה ותחתונה
 * ומספר עמוד בכל עמוד, וזום שמגדיל רק את הדפים.
 */
import { Editor, Extension, Node as TNode, StarterKit, Superscript, Subscript, TextSelection, TextStyle, FontSize, TextAlign, getHTMLFromFragment, collab } from '../../vendor/tiptap.js';
import { extensions, imageBox, FONTS, FALLBACK, cssFont, safeHref, bookmarks, BOOKMARK_RE, USER_STYLE, styleKeyOf, UNDERLINES, TABLE_BORDERS, VALIGNS,
    WpTable, WpTableRow, Cell, HeaderCell } from './extensions.js';
import { Pages, pageLabel, pageNumbers, Endnotes } from './pagination.js';
import { cleanPastedHtml } from './paste.js';
import { migrate } from './schema.js';
import { createCommands } from './commands.js';
import { FindReplace, createFindBar } from './find.js';
import { ParagraphMarks, marksKey, createNavPane, createPasteOptions, wordStats } from './view.js';
import { headings } from './toc.js';
import { captions, newSeqId, SEQ_LABELS, listTitle } from './caption.js';
import { createComments } from './comments.js';
import { TrackChanges, changes, resolveChanges, goToChange, setTracking, VIEWS as TRACK_VIEWS, authors as changeAuthors, authorColor } from './track.js';
import { TabStops, tabsOf, ALIGNS as TAB_ALIGNS, LEADERS as TAB_LEADERS } from './tabs.js';
import { createRuler } from './ruler.js';
import { Peers } from './collab.js';

const MM = 96 / 25.4;
const PT = 4 / 3;
const GAP = 24;
const SIZES = { A4: [210, 297], Letter: [215.9, 279.4] };
const SIZE_LIST = [8, 9, 10, 10.5, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32, 36, 48, 72];
/** גודל גופן שהוקלד: "13.5" / "13,5" / "13.5pt" → 13.5 (מעוגל לחצי נקודה, כמו ב-Word); מחוץ ל-1–1638 או לא מספר → null. */
export function parseSize(v) {
    const n = parseFloat(String(v).trim().replace(',', '.').replace(/\s*(pt|נק['׳]?)$/i, ''));
    if (!Number.isFinite(n) || n < 1 || n > 1638) return null;
    return Math.round(n * 2) / 2;
}
const STYLE_NAMES = { normal: 'רגיל', title: 'כותרת מסמך', caption: 'כיתוב', ...Object.fromEntries([1, 2, 3, 4, 5, 6, 7, 8, 9].map(l => ['h' + l, 'כותרת ' + l])) };
const GALLERY = ['normal', 'title', 'h1', 'h2', 'h3'];   // בגלריה בסרט; 4–9 — ברשימה (כמו ב-Word, שמראה אותן כשהן בשימוש)
const WRAP_NAMES = { inline: 'בשורה עם הטקסט', square: 'ריבוע', tight: 'צמוד', topBottom: 'מעל ומתחת', behind: 'מאחורי הטקסט', front: 'לפני הטקסט' };
const SPACING = [['-1', 'צפוף 1'], ['-0.5', 'צפוף ½'], ['', 'רגיל'], ['0.5', 'מרווח ½'], ['1', 'מרווח 1'], ['2', 'מרווח 2'], ['3', 'מרווח 3']];
const NUM_FORMATS = { n: '1', page_n: "עמ' 1", n_of_m: '1 מתוך 12', dash_n: '- 1 -', hebrew: 'א, ב, ג' };

export { FONTS, cssFont };

export function defaultPage() {
    return {
        size: 'A4', orientation: 'portrait', margins: { top: 25, bottom: 25, right: 25, left: 25 },
        font: { family: 'David', size: 12 },
        styles: {
            title: { size: 22, bold: true, color: '#1f3864', align: 'center', before: 0, after: 12 },
            caption: { size: 9, italic: true, color: '#44546a', before: 0, after: 10 },
            h1: { size: 16, bold: true, color: '#1f3864', before: 12, after: 6 },
            h2: { size: 14, bold: true, color: '#2f5496', before: 10, after: 4 },
            h3: { size: 12, bold: true, color: '#2f5496', before: 8, after: 4 },
            h4: { size: 12, bold: true, italic: true, color: '#2f5496', before: 6, after: 2 },
            h5: { size: 12, color: '#2f5496', before: 6, after: 2 },
            h6: { size: 12, color: '#1f3763', before: 4, after: 2 },
            h7: { size: 12, italic: true, color: '#1f3763', before: 4, after: 2 },
            h8: { size: 10.5, color: '#272727', before: 4, after: 2 },
            h9: { size: 10.5, italic: true, color: '#272727', before: 4, after: 2 },
            normal: { before: 0, after: 6, line: 1.15 },
        },
        numbering: { show: true, position: 'bottom', align: 'center', format: 'n', start: 1, first_page: true },
        header: '', footer: '', heading_numbers: false, page_color: '', watermark: '',
        even_odd: false, header_even: '', footer_even: '', num_restart: false, num_start: 1,
        first_diff: false, header_first: '', footer_first: '',
        columns: { n: 1, gap: 12.7, line: false },
    };
}

/** סגנונות טבלה מוכנים: גבולות (null — כולם), צבע הגבולות, מילוי השורה הראשונה, ופס לשורות הזוגיות (שנייה, רביעית…). */
export const TABLE_STYLES = {
    plain: { name: 'רגיל', borders: null, color: null, head: null, band: null },
    blueGrid: { name: 'רשת כחולה', borders: null, color: '#2f5496', head: '#d9e2f3', band: null },
    blueBands: { name: 'פסים כחולים', borders: 'outer', color: '#2f5496', head: '#b4c6e7', band: '#deeaf6' },
    greenBands: { name: 'פסים ירוקים', borders: 'outer', color: '#538135', head: '#c5e0b3', band: '#e2efd9' },
    orangeBands: { name: 'פסים כתומים', borders: 'outer', color: '#c55a11', head: '#f7caac', band: '#fbe4d5' },
    grayLight: { name: 'אפור עדין', borders: 'inner', color: '#a6a6a6', head: '#f2f2f2', band: null },
    minimal: { name: 'בלי קווים, פסים אפורים', borders: 'none', color: null, head: '#d9d9d9', band: '#f2f2f2' },
};

/** גודל סימן המים (נק') — כמו watermark_size בשרת: לפי האלכסון של העמוד ומספר התווים, 24–120. */
export function watermarkSize(text, wPt, hPt) {
    const n = Math.max(1, [...text].length);
    return Math.round(Math.max(24, Math.min(120, Math.hypot(wPt, hPt) * 0.6 / (n * 0.6))) * 10) / 10;
}

/**
 * מספור כותרות (1. / 1.1 / 1.1.1 …) במונים של CSS — אותה מחרוזת כמו heading_number() ב-PDF:
 * כותרת 1 — "N. ", עמוקה יותר — "N.M " (רמות שדולגו — 0, כמו ב-Word).
 */
function headingNumbersCss(P) {
    const L = [1, 2, 3, 4, 5, 6, 7, 8, 9];
    let css = `${P}{counter-reset:${L.map(l => 'wh' + l).join(' ')}}`;
    for (const l of L) {
        const below = L.filter(x => x > l).map(x => 'wh' + x).join(' ');
        const path = L.filter(x => x <= l).map(x => `counter(wh${x})`).join(' "." ');
        css += `${P} h${l}{counter-increment:wh${l}${below ? `;counter-reset:${below}` : ''}}`
            + `${P} h${l}::before{content:${path} "${l === 1 ? '. ' : ' '}"}`;
    }
    return css;
}

/** h('button.btn.primary', {onclick}, 'טקסט', child…) */
export function h(sel, attrs, ...kids) {
    const [tag, ...cls] = sel.split('.');
    const el = document.createElement(tag || 'div');
    if (cls.length) el.className = cls.join(' ');
    if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
        else if (k in el && !k.startsWith('aria') && !k.startsWith('data')) el[k] = v;
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const k of kids.flat()) if (k !== null && k !== undefined && k !== false) el.append(k instanceof Node ? k : String(k));
    return el;
}

/** הודעה קצרה בתחתית המסך. action: {label, run} — כפתור בהודעה (למשל "ביטול"); ההודעה נשארת יותר זמן. */
export function toast(msg, err = false, action = null) {
    const el = document.querySelector('.toast');
    if (!el) return;
    el.textContent = msg;
    if (action) el.append(h('button.toast-act', { type: 'button', onclick: () => { el.hidden = true; action.run(); } }, action.label));
    el.classList.toggle('err', err);
    el.classList.toggle('has-act', !!action);
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, action ? 8000 : 2800);
}

/** גיליון עריכה (dialog) — חלון במחשב, מסך מלא בטלפון. fields(body) ממלא; onSave(form) → true לסגירה. */
export function sheet(title, fill, onSave, saveLabel = 'אישור') {
    const body = h('div.sheet-body');
    const err = h('p.error', { role: 'alert', hidden: true });
    const dlg = h('dialog', { 'aria-label': title },
        h('form', { method: 'dialog', novalidate: true },
            h('header.sheet-head',
                h('button.icon-btn', { type: 'button', 'aria-label': 'סגירה', onclick: () => dlg.close() }, '✕'),
                h('h2', title),
                onSave ? h('button.btn.primary', { type: 'submit' }, saveLabel) : null),
            body));
    fill(body, dlg);
    body.append(err);
    dlg.querySelector('form').addEventListener('submit', async e => {
        e.preventDefault();
        if (!onSave) return dlg.close();
        err.hidden = true;
        try {
            if ((await onSave(dlg.querySelector('form'), dlg)) !== false) dlg.close();
        } catch (ex) {
            err.textContent = ex.message || String(ex);
            err.hidden = false;
        }
    });
    dlg.addEventListener('close', () => dlg.remove());
    document.body.append(dlg);
    dlg.showModal();
    return dlg;
}

export function field(label, input, cls = '') {
    return h('label.f' + (cls ? '.' + cls : ''), h('span', label), input);
}

export function seg(name, options, value, label) {
    return h('fieldset.seg', { 'aria-label': label },
        Object.entries(options).map(([v, t]) => h('label', h('input', { type: 'radio', name, value: v, checked: String(value) === v }), h('span', t))));
}

function sw(name, label, checked) {
    return h('label.switch', h('input', { type: 'checkbox', name, checked: !!checked }), h('span', label));
}

function numInput(name, value, attrs = {}) {
    return h('input', { name, value: value ?? '', inputmode: 'decimal', dir: 'ltr', type: 'number', step: 'any', ...attrs });
}

function fontSelect(name, value, allowInherit = false) {
    return h('select', { name },
        allowInherit ? h('option', { value: '' }, '— כמו בגופן המסמך —') : null,
        FONTS.map(f => h('option', { value: f, selected: f === value, style: { fontFamily: cssFont(f) } }, f)));
}

// ======================================================================

export function createWordEditor(root, opts) {
    // הגדרות העמוד יושבות בתוך המסמך (attrs.page) — נשמרות עם התוכן, ו-Ctrl+Z מבטל אותן. page כאן — העותק המוחל
    const initial = migrate(opts.json, opts.page);
    let page = mergePage(defaultPage(), initial.attrs.page || {});
    initial.attrs.page = page;
    let zoom = 1;
    let fit = true;
    let loading = false;
    let pageAttr;   // לפני שהעורך נבנה — undefined, ו-syncPage לא עושה כלום
    const emitPage = () => opts.onPage?.(structuredClone(page));

    // ---------- מבנה ----------
    const styleEl = h('style');
    const printEl = h('style', { media: 'print' });
    let printViaPdf = false;   // מקטעים עם מספר טורים שונה — ההדפסה דרך ה-PDF מהשרת (applyPage, print)
    // תצוגת השינויים (track.js, VIEWS): מחלקה על המסמך, ו-CSS למחברים המוסתרים — רק תצוגה, גם בהדפסה
    let trackView = 'all';
    const trackHide = new Set();
    const trackStyle = h('style');
    const wmPrint = h('div.wp-wm-print', { 'aria-hidden': 'true' });   // סימן המים בהדפסה (בכל עמוד)
    const pagesLayer = h('div.wp-pages', { 'aria-hidden': 'true' });
    let ruler = null;   // הסרגל (ruler.js) — נבנה אחרי העורך
    const host = h('div.wp-host');
    const sheetEl = h('div.wp-sheet', pagesLayer, host);
    const zoomEl = h('div.wp-zoom', sheetEl);
    const zoomBox = h('div.wp-zoom-box', zoomEl);
    const stage = h('div.wp-stage', zoomBox);
    const bar = h('div.wp-toolbar.wp-ribbon');           // הסרט — נבנה למטה, מרישום הפקודות
    const statusBar = h('div.wp-statusbar');             // שורת המצב: עמוד, מילים, זום
    const mobileBar = h('div.wp-mobilebar', { role: 'toolbar', 'aria-label': 'פעולות מהירות' });   // טלפון: שורה קבועה בתחתית
    const sheetShade = h('div.wp-sheet-shade', { hidden: true });
    const previewBar = h('div.wp-preview-bar', { hidden: true });   // תצוגה לפני הדפסה
    const findSlot = h('div.wp-find-slot');               // פס החיפוש וההחלפה (find.js) — נבנה אחרי העורך
    const body = h('div.wp-body', stage);                 // חלונית הניווט (view.js) נכנסת לפני הדף — בצד ההתחלה
    root.append(styleEl, trackStyle, printEl, wmPrint, bar, previewBar, findSlot, body, statusBar, mobileBar, sheetShade);
    root.classList.add('wp-editor');

    /** גיאומטריה של עמוד לפי הגדרות (בפיקסלים לא מוגדלים). */
    const geoOf = p => {
        const [wmm, hmm] = mmOf(p);
        const m = p.margins;
        const pageH = hmm * MM;
        // טורים: הטקסט ברוחב טור (textW), במקום של הטור הראשון — מימין; shift — מה שנוסף לשוליים השמאליים.
        // כל "עמוד" בעימוד הוא טור; בהדפסה — n טורים לעמוד (printEl), כמו ב-PDF מהשרת
        const cols = Math.max(1, Math.min(4, +p.columns?.n || 1));
        const colGap = cols > 1 ? (+p.columns?.gap >= 0 ? +p.columns.gap : 12.7) * MM : 0;
        const full = wmm * MM - (m.left + m.right) * MM;
        const textW = (full - colGap * (cols - 1)) / cols;
        return { pageW: wmm * MM, pageH, top: m.top * MM, bottom: m.bottom * MM, right: m.right * MM, left: m.left * MM,
            contentH: pageH - (m.top + m.bottom) * MM, cols, colGap, textW, shift: full - textW };
    };
    const mmOf = p => {
        const [w, hh] = SIZES[p.size] || SIZES.A4;
        return p.orientation === 'landscape' ? [hh, w] : [w, hh];
    };
    const pageMm = () => mmOf(page);
    /**
     * מקטעים: הראשון — הגדרות המסמך; כל מעבר מקטע (sectionBreak) — המקטע הקודם + מה שהוא משנה. from — מיקום הצומת
     * (הצומת עצמו שייך למקטע שלפניו).
     */
    let ed0 = null;
    let stylesUI = null;
    let pasteOpts = null;  // אפשרויות הדבקה (view.js) — נבנות אחרי העורך   // גלריית הסגנונות והרשימה — נבנות מחדש כשהסגנונות משתנים (נקבע אחרי בניית הסרט)
    let secMemo = { doc: null, page: null, out: null };
    const sections = () => {
        const doc = ed0?.state.doc;
        if (doc && secMemo.doc === doc && secMemo.page === page) return secMemo.out;
        const out = [{ from: 0, page }];
        let cur = page;
        doc?.forEach((n, pos) => {
            if (n.type.name !== 'sectionBreak') return;
            // התחלת מספור מחדש — רק במקטע שהמעבר שלו אומר (לא עוברת בירושה, כמו page_section בשרת)
            cur = { ...mergePage(cur, n.attrs.page || {}), num_restart: !!n.attrs.page?.num_restart };
            out.push({ from: pos, page: cur });
        });
        secMemo = { doc, page, out };
        return out;
    };
    const geometry = () => {
        const secs = sections().map(x => ({ from: x.from, page: x.page, ...geoOf(x.page) }));
        return { ...secs[0], gap: GAP, sections: secs, sheetW: Math.max(...secs.map(x => x.pageW)) };
    };

    const editor = new Editor({
        element: host,
        extensions: extensions([Pages.configure({ geometry, onLayout: drawPages }), FindReplace, ParagraphMarks, Endnotes, TabStops,
            TrackChanges.configure({ author: () => opts.user?.name || '' }),
            // עריכה משותפת (collab.js): הצעדים שעוד לא אושרו בשרת, ומספר הגרסה — מכאן נשלחים ומתקבלים
            ...(opts.collab ? [Extension.create({ name: 'wpCollab', addProseMirrorPlugins: () => [collab({ version: opts.collab.version, clientID: opts.collab.clientID })] }), Peers] : [])]),
        content: initial,
        editable: opts.editable !== false,
        editorProps: {
            attributes: { class: 'wp-doc', dir: 'rtl', lang: 'he', spellcheck: 'true', 'aria-label': 'המסמך' },
            // הסמן לא מסתתר מתחת לשורת הפעולות בתחתית (טלפון) — מרווח בגלילה אל הסמן
            scrollMargin: { top: 16, bottom: 72, left: 8, right: 8 },
            scrollThreshold: { top: 16, bottom: 72, left: 8, right: 8 },
            transformPastedHTML: cleanPastedHtml,
            // Ctrl+Alt+F — הערת שוליים (כמו ב-Word)
            handleKeyDown: (view, e) => {
                if ((e.ctrlKey || e.metaKey) && e.altKey && e.code === 'KeyF') { footnoteDialog(null); return true; }
                if ((e.ctrlKey || e.metaKey) && e.altKey && e.code === 'KeyD') { footnoteDialog(null, true); return true; }   // הערת סיום — כמו ב-Word
                return false;
            },
            handlePaste: (view, e) => {
                if (imageFiles(e.clipboardData)) { insertImages(imageFiles(e.clipboardData)); return true; }
                pasteOpts?.onPaste(e);   // אפשרויות הדבקה — אחרי שההדבקה תיכנס
                return false;
            },
            handleDrop: (view, e, slice, moved) => {
                if (moved || !imageFiles(e.dataTransfer)) return false;
                const pos = view.posAtCoords({ left: e.clientX, top: e.clientY });
                if (pos) editor.commands.setTextSelection(pos.pos);
                insertImages(imageFiles(e.dataTransfer));
                return true;
            },
        },
        onUpdate: () => opts.onUpdate?.(),
        onSelectionUpdate: () => refresh(),
        onTransaction: ({ transaction }) => { syncPage(); refresh(); if (transaction.docChanged) countWords(); },
    });
    ed0 = editor;
    pageAttr = editor.state.doc.attrs.page;
    /** המסמך השתנה (עריכה, ביטול, שחזור, טעינה) — אם הגדרות העמוד שבו אחרות, מחילים אותן. */
    let secSig = '';
    function syncPage() {
        if (pageAttr === undefined) return;
        const a = editor.state.doc.attrs.page;
        // מקטע נוסף / נמחק / השתנה — גם זה שינוי בהגדרות העמוד
        const sig = sections().slice(1).map(x => x.from + JSON.stringify(x.page)).join('|');
        if (sig !== secSig) { secSig = sig; if (a === pageAttr) { applyPage(); return; } }
        if (a === pageAttr) return;
        pageAttr = a;
        page = mergePage(defaultPage(), a || {});
        applyPage();
        if (!loading) emitPage();
    }
    editor.storage.wpPages.zoom = 1;

    // ---------- דפים ----------
    function applyPage() {
        stylesUI?.();
        const g = geometry();
        editor.storage.wpPages.contentWidth = g.textW;
        sheetEl.style.width = g.sheetW + 'px';
        // כמה מקטעים: הרוחב של כל מקטע נקבע לכל בלוק (pagination.js), לא בריפוד של המסמך
        editor.view.dom.classList.toggle('wp-multisec', g.sections.length > 1);
        const s = page.styles;
        const st = (sel, x, heading) => `${sel}{${x.font ? `font-family:${cssFont(x.font)};` : ''}${x.size ? `font-size:${x.size}pt;` : ''}`
            + `font-weight:${x.bold ? 700 : 400};font-style:${x.italic ? 'italic' : 'normal'};text-decoration:${x.underline ? 'underline' : 'none'};`
            + `${x.color ? `color:${x.color};` : (heading ? '' : '')}${x.align ? `text-align:${x.align};` : ''}`
            + `padding-top:${x.before ?? 0}pt;padding-bottom:${x.after ?? 0}pt;${x.line ? `line-height:${x.line};` : ''}}`;
        const P = '.wp-sheet .wp-doc';
        styleEl.textContent = `${P}{padding:${g.top}px ${g.right}px 0 ${g.left + g.shift}px;font-family:${cssFont(page.font.family)};font-size:${page.font.size}pt;line-height:${s.normal.line || 1.15}}`
            + st(`${P} p`, s.normal, false) + st(`${P} p.wp-title`, s.title, true) + st(`${P} p.wp-caption`, s.caption || {}, true)
            + [1, 2, 3, 4, 5, 6, 7, 8, 9].map(l => st(`${P} h${l}`, s['h' + l] || {}, true)).join('')
            + Object.keys(s).filter(k => USER_STYLE.test(k)).map(k => st(`${P} p.wp-s-${k}`, { ...s.normal, ...s[k] }, false)).join('')
            + `${P} li>p{padding-top:0;padding-bottom:${Math.min(3, s.normal.after ?? 0)}pt}`
            + (page.heading_numbers ? headingNumbersCss(P) : '')
            // בתא טבלה — בלי רווח אחרי פסקה (גם ב-PDF וב-Word); בלי זה כלל הסגנון (ספציפי יותר) גובר על editor.css
            + `${P} td>p,${P} th>p{padding-bottom:0}`;
        // הדפסה: אותם עמודים — כל רווח של עמוד הופך לשבירת עמוד, ומספר העמוד בשולי הדף
        const [wmm, hmm] = pageMm();
        const nb = page.numbering;
        const fmt = { n: 'counter(page)', page_n: `"עמ' " counter(page)`, n_of_m: 'counter(page) " מתוך " counter(pages)',
            dash_n: '"- " counter(page) " -"', hebrew: 'counter(page, hebrew)' }[nb.format] || 'counter(page)';
        const box = (pos, al) => `@${pos}-${{ right: 'right', center: 'center', left: 'left' }[al]}`;
        // כותרת עשירה — כאן רק הטקסט שלה (ממילא מודפסת דרך ה-PDF מהשרת — printViaPdf)
        const q = t => '"' + String(Array.isArray(t) ? t.map(b => (b.content || []).map(n => n.text || '').join('')).join(' ') : t).replace(/["\\]/g, '\\$&').replace(/\n/g, ' ') + '"';
        // תוכן השוליים (כותרות ומספר עמוד) — לכל מקטע לפי הכותרות שלו
        const marginOf = pp => {
            const slots = {};
            if (pp.header) slots['top-center'] = q(pp.header);
            if (pp.footer) slots['bottom-center'] = q(pp.footer);
            if (nb.show) {
                const k = box(nb.position, nb.align).slice(1);
                slots[k] = slots[k] ? `${slots[k]} "  " ${fmt}` : fmt;
            }
            return Object.entries(slots).map(([k, v]) => `@${k}{content:${v};font-family:${cssFont(page.font.family)};font-size:9pt}`).join('');
        };
        const margin = marginOf(page);
        let firstNoNum = '';
        if (nb.show && !nb.first_page) {
            const k = box(nb.position, nb.align).slice(1);
            const text = k === 'top-center' && page.header ? q(page.header) : k === 'bottom-center' && page.footer ? q(page.footer) : 'none';
            firstNoNum = `@page:first{@${k}{content:${text}}}`;
        }
        // רזרבה בשוליים התחתונים (עד 15 מ"מ, ונשארים לפחות 6 למספר העמוד): כל עמוד מסתיים ממילא במעבר עמוד מאולץ
        // (.wp-break), כך שהרזרבה לא מתמלאת בטקסט — היא רק בולעת הבדלי עיגול בין המסך להדפסה. בשורות עם כמה גדלי גופן
        // ההדפסה מעגלת את גובה השורה אחרת, ובעמוד שלם זה מצטבר לכמה מ"מ; בלי רזרבה הטקסט גולש ונוסף עמוד (tests/e2e, parity)
        const slack = Math.max(0, Math.min(15, page.margins.bottom - 6));
        // כל מקטע אחרי הראשון — עמוד בשם משלו (page: wpsN על הבלוקים שלו), בגודל ובשוליים שלו
        const named = g.sections.slice(1).map((x, k) => {
            const [w2, h2] = mmOf(x.page);
            const m2 = x.page.margins;
            const sl = Math.max(0, Math.min(15, m2.bottom - 6));
            return `@page wps${k + 1}{size:${w2}mm ${h2}mm;margin:${m2.top}mm ${m2.right}mm ${m2.bottom - sl}mm ${m2.left}mm;${marginOf(x.page)}}`;
        }).join('');
        // רקע הדף בהדפסה: צבע — ברקע של @page (כל הדף, גם השוליים); סימן מים — אלמנט fixed, שהדפדפן מדפיס בכל עמוד.
        // fixed נמדד מאזור התוכן של הדף, לכן ההזזה למרכז הדף (השוליים לא סימטריים)
        const bg = page.page_color ? `@page{background:${page.page_color}}` + g.sections.slice(1).map((x, k) => `@page wps${k + 1}{background:${page.page_color}}`).join('') : '';
        printEl.textContent = `@page{size:${wmm}mm ${hmm}mm;margin:${page.margins.top}mm ${page.margins.right}mm ${page.margins.bottom - slack}mm ${page.margins.left}mm;${margin}}`
            + firstNoNum + (nb.start !== 1 ? `@page:first{counter-reset:page ${nb.start - 1}}` : '') + named + bg
            + (page.watermark ? `.wp-wm-print{display:flex!important;transform:translate(${(page.margins.right - page.margins.left) / 2}mm,${(page.margins.bottom - slack - page.margins.top) / 2}mm) rotate(-45deg)}` : '')
            // טורים (מקטע אחד): המסמך בטורים, וכל רווח עמוד — מעבר טור (אחרי הטור האחרון — העמוד הבא, כי column-fill: auto);
            // כל טור כבר נחתך בעימוד לגובה העמוד, כך שהשבירות הן בדיוק אלה שעל המסך ושב-PDF מהשרת
            + (g.sections.length === 1 && g.cols > 1 ? `.wp-sheet .wp-doc{columns:${g.cols} !important;column-gap:${page.columns.gap}mm;column-fill:auto}`
                + '.wp-break{break-before:column !important}.wp-colskip{display:block;break-before:column}'
                // שתי שבירות צמודות מתאחדות לאחת; שורה ריקה בגובה 0 לפני כל מעבר טור נוסף מפרידה ביניהן. הגובה של הרווח —
                // auto (ממילא 0): בגובה קבוע Chrome לא מפצל את מה שבתוכו, ומעברי הטור הנוספים נבלעים
                + '.wp-doc .wp-break:has(.wp-colskip){height:auto !important;block-size:auto !important}'
                + '.wp-break:has(.wp-colskip)::before,.wp-colskip::after{content:"\\200b";display:block;height:0;overflow:hidden}' : '');
        // מקטעים עם טורים שונים — הדפדפן לא מחליף מספר טורים באמצע; ההדפסה עוברת דרך ה-PDF מהשרת (print)
        // וגם זוגי / אי-זוגי ומספור שמתחיל מחדש במקטע — @page של הדפדפן לא יודע (מונה עמודים אחד, כותרת אחת)
        // וכותרת עשירה — בשולי @page יש רק טקסט
        // ועמוד ראשון שונה — @page :first הוא רק העמוד הראשון של המסמך
        const rich = x => HF_KEYS.some(k => Array.isArray(x.page[k]));
        printViaPdf = (g.sections.length > 1 && g.sections.some(x => x.cols > 1)) || !!page.even_odd
            || g.sections.some((x, i) => (i > 0 && x.page.num_restart) || rich(x) || x.page.first_diff);
        wmPrint.textContent = page.watermark || '';
        if (page.watermark) Object.assign(wmPrint.style, { fontSize: watermarkSize(page.watermark, wmm * 72 / 25.4, hmm * 72 / 25.4) + 'pt', fontFamily: cssFont(page.font.family) });
        setZoom();
        editor.storage.wpTabs?.measure?.();   // עמוד / גופן אחרים — הטאבים נמדדים מחדש
        editor.storage.wpPages.schedule?.();
    }

    let lastLayout = null;   // העימוד האחרון — לתוכן העניינים: באיזה עמוד כל כותרת

    /** מספר העמוד (מ-0) שבו מתחיל המיקום pos — לפי המעברים של העימוד האחרון. */
    function pageOf(pos) {
        let pg = 0;
        for (const b of lastLayout?.breaks || []) if (b.pos <= pos && b.page > pg) pg = b.page;
        return lastLayout?.phys?.[pg] ?? pg;
    }

    /**
     * שורות תוכן העניינים: הכותרות עד הרמה levels, ומספר העמוד כמו שהוא מוצג (מספרים, או אותיות בתבנית עברית).
     * seq — רשימת איורים: הכיתובים עם התווית הזו (בלי רמה).
     */
    function tocEntries(levels, seq = null) {
        const nb = page.numbering;
        const fmt = nb.format === 'hebrew' ? 'hebrew' : 'n';
        const pg = pos => pageLabel(pageNums[pageOf(pos)] ?? nb.start + pageOf(pos), pageCount, fmt);
        if (seq) return captions(editor.state.doc).filter(c => c.label === seq).map((c, i) => ({ t: c.text, l: 0, i, p: pg(c.pos) }));
        return headings(editor.state.doc, levels).map(x => ({ t: x.text || '(כותרת ריקה)', l: x.level, i: x.i, p: pg(x.pos) }));
    }

    /**
     * עדכון כל תוכני העניינים. תוכן עניינים שגדל או קטן מזיז עמודים — לכן עוד סבב אחרי שהעימוד נרגע (עד 3).
     * כל העדכונים — צעד ביטול אחד.
     */
    function updateToc(round = 0) {
        const tr = editor.state.tr;
        editor.state.doc.descendants((n, pos) => {
            if (n.type.name !== 'toc') return true;
            const entries = tocEntries(n.attrs.levels, n.attrs.seq);
            if (JSON.stringify(entries) !== JSON.stringify(n.attrs.entries)) tr.setNodeMarkup(pos, undefined, { ...n.attrs, entries });
            return false;
        });
        if (!tr.docChanged) return;
        // הסבבים שאחרי הראשון — רק תיקון של מספרי העמודים, לא צעד ביטול נוסף
        if (round) tr.setMeta('addToHistory', false);
        else tr.setMeta('closeHistory$', true);
        editor.view.dispatch(tr);
        if (round < 3) setTimeout(() => updateToc(round + 1), 350);
    }

    function insertToc(seq = null) {
        editor.chain().focus().insertContent({ type: 'toc', attrs: { seq, levels: 3, entries: tocEntries(3, seq) } }).run();
        setTimeout(() => updateToc(1), 350);
    }

    /** רשימת איורים (כמו "הוספת טבלת איורים" ב-Word): לאיזו תווית — ברירת המחדל: התווית הנפוצה במסמך. */
    function tofDialog() {
        const list = captions(editor.state.doc);
        const used = SEQ_LABELS.filter(l => list.some(c => c.label === l));
        sheet('רשימת איורים', body => {
            body.append(seg('label', Object.fromEntries(SEQ_LABELS.map(l => [l, listTitle(l)])), used[0] || SEQ_LABELS[0], 'רשימה של'));
        }, form => {
            const l = new FormData(form).get('label');
            insertToc(SEQ_LABELS.includes(l) ? l : SEQ_LABELS[0]);
        }, 'הוספה');
    }

    function drawPages(res) {
        lastLayout = res;
        const G = geometry();
        const n = res.pages;
        // טורים: כל מסגרת היא טור; המספור וספירת העמודים — לפי העמוד הפיזי (res.phys)
        const physOf = i => res.phys?.[i] ?? i;
        const total = physOf(n - 1) + 1;
        // מספר כל עמוד פיזי — לפי המקטע של המסגרת הראשונה שלו (מקטע עם num_restart מתחיל מחדש)
        const secOfPhys = [];
        for (let i = 0; i < n; i++) secOfPhys[physOf(i)] ??= res.pageSecs?.[i] ?? 0;
        pageNums = pageNumbers(secOfPhys, G.sections, page.numbering.start);
        pagesLayer.textContent = '';
        let y = 0;
        for (let i = 0; i < n; i++) {
            // כל עמוד בגודל של המקטע שלו, ממורכז ברוחב הגיליון
            const sec = G.sections[res.pageSecs?.[i] ?? 0] || G.sections[0];
            const g = sec;
            const pp = sec.page;
            const nb = pp.numbering;
            const pg = h('div.wp-page', { style: { top: y + 'px', height: g.pageH + 'px', width: g.pageW + 'px', left: (G.sheetW - g.pageW) / 2 + 'px', right: 'auto' } });
            y += g.pageH + GAP;
            const pi = physOf(i);
            const label = nb.show && (pi > 0 || nb.first_page) ? pageLabel(pageNums[pi], total, nb.format) : '';
            const hdr = h('div.wp-hf.wp-hdr', { style: { height: g.top + 'px', paddingInline: `${g.right}px ${g.left}px` } });
            const ftr = h('div.wp-hf.wp-ftr', { style: { height: g.bottom + 'px', paddingInline: `${g.right}px ${g.left}px` } });
            // זוגי / אי-זוגי שונים: בעמוד שמספרו זוגי — הכותרות לעמוד זוגי של המקטע
            // עמוד ראשון שונה (העמוד הראשון של המקטע) — קודם לזוגי / אי-זוגי, כמו ב-Word ו-ב-PDF
            const kind = pp.first_diff && (pi === 0 || secOfPhys[pi] !== secOfPhys[pi - 1]) ? '_first' : page.even_odd && pageNums[pi] % 2 === 0 ? '_even' : '';
            const hText = pp['header' + kind], fText = pp['footer' + kind];
            // כותרת עשירה: הפסקאות עצמן, במרחק הכותרת מקצה הדף (כמו ב-PDF — drawRichHf), ומספר העמוד — שורה אחריהן
            const rich = (v, el, top) => {
                const box = hfNode(v, g, top);
                const num = label && nb.position === (top ? 'top' : 'bottom');
                if (num) box.append(h('p.wp-hf-num', { style: { textAlign: nb.align } }, label));
                el.append(box);
                return num;
            };
            const numDone = (Array.isArray(hText) && rich(hText, hdr, true)) | (Array.isArray(fText) && rich(fText, ftr, false));
            if (hText && !Array.isArray(hText)) hdr.append(h('span.wp-hf-text', hText));
            if (fText && !Array.isArray(fText)) ftr.append(h('span.wp-hf-text', fText));
            if (label && !numDone) (nb.position === 'top' ? hdr : ftr).append(h('span.wp-num.al-' + nb.align, { style: nb.align === 'right' ? { right: g.right + 'px' } : nb.align === 'left' ? { left: g.left + 'px' } : {} }, label));
            pg.append(hdr, ftr);
            if (g.cols > 1) {
                // תצוגת טיוטה של טורים (כמו "טיוטה" ב-Word): כל טור במסגרת משלו, ברוחב שלו; הטורים זה לצד זה — בהדפסה וב-PDF
                pg.classList.add('wp-page-col');
                hdr.append(h('span.wp-col-badge', { title: 'בהדפסה וב-PDF — הטורים זה לצד זה' }, `עמוד ${pi + 1} · טור ${(res.cols?.[i] ?? 0) + 1} מתוך ${g.cols}`));
            }
            // רקע הדף (מהגדרות המסמך): צבע, וסימן מים באלכסון מאחורי הטקסט — כמו ב-PDF
            if (page.page_color) pg.style.background = page.page_color;
            if (page.watermark) {
                const sz = watermarkSize(page.watermark, g.pageW * 0.75, g.pageH * 0.75);
                pg.append(h('div.wp-wm', { 'aria-hidden': 'true', style: { fontSize: sz + 'pt', fontFamily: cssFont(page.font.family) } }, page.watermark));
            }
            pagesLayer.append(pg);
        }
        const H = y - GAP;
        editor.view.dom.style.minHeight = H + 'px';
        sheetEl.style.height = H + 'px';
        pageCount = total;
        frameOf = physOf;
        placePageAnchored();
        ruler?.update();
        setZoom();
        status();
        statusBarPage();
    }
    /** כותרת עשירה → אלמנט (מהסכמה של העורך — אותו עיצוב כמו בגוף), במרחק הכותרת: חצי מהשוליים, עד 12.5 מ"מ. */
    const hfHtml = new Map();
    function hfNode(blocks, g, top) {
        const key = JSON.stringify(blocks);
        if (!hfHtml.has(key)) {
            let html = '';
            try { html = getHTMLFromFragment(editor.schema.nodeFromJSON({ type: 'doc', content: blocks }).content, editor.schema); } catch { /* תוכן לא תקין — ריק */ }
            hfHtml.set(key, html);
        }
        const dist = Math.min(12.5 * MM, (top ? g.top : g.bottom) / 2);
        const box = h('div.wp-hf-rich', { style: { [top ? 'top' : 'bottom']: dist + 'px', left: g.left + 'px', right: g.right + 'px',
            fontFamily: cssFont(page.font.family), fontSize: page.font.size + 'pt' } });
        box.innerHTML = hfHtml.get(key);
        return box;
    }
    /**
     * תמונות שמעוגנות לעמוד (מאחורי / לפני הטקסט, anchor: 'page'): x, y הם מהפינה של העמוד שבו הפסקה שלהן מתחילה.
     * התמונה עצמה ממוקמת יחסית לפסקה (absolute), לכן: המקום של הפסקה בעמוד → ox / oy, ו-left / top = x − ox, y − oy.
     * אחרי כל עימוד — הפסקה יכולה לזוז לעמוד אחר, והתמונה נשארת במקום שלה על הדף (כמו ב-Word).
     */
    function placePageAnchored() {
        const frames = [...pagesLayer.children];
        for (const el of editor.view.dom.querySelectorAll('.wp-img[data-anchor="page"]')) {
            const par = el.offsetParent;
            if (!par || !frames.length) continue;
            const pr = par.getBoundingClientRect();
            const frame = frames.find(f => { const r = f.getBoundingClientRect(); return pr.top >= r.top - 1 && pr.top < r.bottom; }) || frames[0];
            const fr = frame.getBoundingClientRect();
            const z = fr.width / frame.offsetWidth || 1;
            // מקום הפסקה בעמוד: מקצה הריפוד שלה (שממנו נמדד absolute)
            const ox = (pr.left - fr.left) / z + par.clientLeft, oy = (pr.top - fr.top) / z + par.clientTop;
            Object.assign(el.dataset, { ox: Math.round(ox * 10) / 10, oy: Math.round(oy * 10) / 10 });
            el.style.left = (+el.dataset.px || 0) - ox + 'px';
            el.style.top = (+el.dataset.py || 0) - oy + 'px';
        }
    }
    let pageCount = 1;
    let pageNums = [1];   // מספר כל עמוד פיזי (drawPages) — לתוכן העניינים ולכותרות
    let frameOf = i => i;   // מסגרת על המסך → העמוד הפיזי (בטורים — כמה מסגרות לעמוד)

    function setZoom() {
        const g = geometry();
        if (fit) {
            const avail = stage.clientWidth - 16;
            zoom = avail > 0 ? Math.max(0.3, Math.min(1.25, avail / g.sheetW)) : 1;
        }
        zoomEl.style.transform = `scale(${zoom})`;
        zoomBox.style.width = g.sheetW * zoom + 'px';
        zoomBox.style.height = (parseFloat(sheetEl.style.height) || g.pageH) * zoom + 'px';
        editor.storage.wpPages.zoom = zoom;
        if (zoomLabel) zoomLabel.textContent = Math.round(zoom * 100) + '%';
        if (stZoom) stZoom.textContent = Math.round(zoom * 100) + '%';
        ruler?.update();
    }
    const resizeObs = new ResizeObserver(() => { if (fit && !editor.isDestroyed) setZoom(); });
    resizeObs.observe(stage);

    // צביטה בשתי אצבעות (טלפון) — מגדילה רק את הדף, לא את הכותרת והסרגל. הדפדפן לא מגדיל (touch-action ב-CSS),
    // והנקודה שבין האצבעות נשארת במקומה.
    let pinch = null;
    const dist = t => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
    const mid = t => ({ x: (t[0].clientX + t[1].clientX) / 2, y: (t[0].clientY + t[1].clientY) / 2 });
    stage.addEventListener('touchstart', e => {
        if (e.touches.length !== 2) return;
        pinch = { d0: dist(e.touches), z0: zoom };
    }, { passive: true });
    stage.addEventListener('touchmove', e => {
        if (!pinch || e.touches.length !== 2) return;
        e.preventDefault();
        const m = mid(e.touches);
        const r = zoomBox.getBoundingClientRect();
        const px = (m.x - r.left) / zoom;
        const py = (m.y - r.top) / zoom;
        fit = false;
        zoom = Math.max(0.3, Math.min(3, pinch.z0 * dist(e.touches) / pinch.d0));
        setZoom();
        const r2 = zoomBox.getBoundingClientRect();
        stage.scrollLeft += r2.left + px * zoom - m.x;
        stage.scrollTop += r2.top + py * zoom - m.y;
    }, { passive: false });
    const endPinch = e => { if (pinch && e.touches.length < 2) pinch = null; };
    stage.addEventListener('touchend', endPinch);
    stage.addEventListener('touchcancel', endPinch);
    // ספארי (iPhone) מתעלם לפעמים מ-touch-action — חוסמים את מחוות ההגדלה שלו כל עוד העורך פתוח (גם על הכותרת)
    const noGesture = e => e.preventDefault();
    document.addEventListener('gesturestart', noGesture);
    document.addEventListener('gesturechange', noGesture);
    document.documentElement.classList.add('wp-no-page-zoom');

    // ---------- תמונות ----------
    function imageFiles(dt) {
        const files = [...(dt?.files || [])].filter(f => /^image\//.test(f.type));
        return files.length ? files : null;
    }
    async function insertImages(files) {
        if (!opts.upload) return toast('אין העלאת תמונות כאן', true);
        if (!editor.isEditable) return;
        for (const f of files) {
            try {
                toast('מעלה תמונה…');
                const m = await opts.upload(f);
                const max = editor.storage.wpPages.contentWidth;
                const w = Math.min(m.width || max, max * 0.6, max);
                const hh = m.width ? Math.round(w * m.height / m.width) : null;
                editor.chain().focus().insertContent({ type: 'image', attrs: { media: m.id, src: m.url, width: Math.round(w), height: hh, wrap: 'inline', align: 'right' } }).run();
                toast('התמונה נוספה');
            } catch (e) {
                toast(e.message || 'העלאה נכשלה', true);
            }
        }
    }

    // ---------- סרגל הכלים ----------
    const btn = (icon, label, run, extra = {}) => {
        const b = h('button.tb', { type: 'button', 'aria-label': label, title: label, onmousedown: e => e.preventDefault(), onclick: run, ...extra }, icon);
        return b;
    };
    const sep = () => h('span.tb-sep', { 'aria-hidden': 'true' });
    const chain = () => editor.chain().focus();
    const ed = editor;

    // ---------- הפקודות (commands.js) ----------
    const cmds = createCommands({
        editor, chain,
        ui: {
            paragraph: () => paragraphDialog(), table: () => tableDialog(), image: () => imgInput.click(),
            pageSetup: () => pageDialog(), styles: () => stylesDialog(), print: () => print(),
            versions: opts.versions ? () => versionsDialog() : null,
            painter: sticky => { if (painter && !sticky) stopPainter(); else { stopPainter(); startPainter(sticky); } },
            zoomOut: () => { fit = false; zoom = Math.max(0.3, Math.round((zoom - 0.1) * 10) / 10); setZoom(); },
            zoomIn: () => { fit = false; zoom = Math.min(3, Math.round((zoom + 0.1) * 10) / 10); setZoom(); },
            zoomFit: () => { fit = true; setZoom(); },
            tableStyle: k => applyTableStyle(k),
            insertToc: () => insertToc(), updateToc: () => updateToc(), hasToc: () => { let f = false; editor.state.doc.descendants(n => { if (n.type.name === 'toc') f = true; return !f; }); return f; },
            crop: pos => cropDialog(pos), imageProps: pos => imageDialog(pos), imageSize: f => imageSizeTo(f), tableProps: () => tablePropsDialog(),
            footnote: () => footnoteDialog(null), endnote: () => footnoteDialog(null, true),
            comment: () => { if (phone.matches) openSheet(false); comments.add(); refresh(); },
            commentsPane: () => { if (comments.isOpen()) comments.close(); else { if (phone.matches) openSheet(false); comments.open(); } refresh(); },
            commentsOn: () => comments.isOpen(),
            commentOnly: () => !!opts.commentOnly?.(),
            trackToggle: () => { setTracking(editor, !editor.state.doc.attrs.track); toast(editor.state.doc.attrs.track ? 'מעקב שינויים פועל — כל עריכה תסומן' : 'מעקב שינויים כבוי'); },
            trackView: v => setTrackView(v), trackViewValue: () => trackView, trackAuthors: () => authorsDialog(),
            resolve: (accept, all) => { const n = resolveChanges(editor, accept, all, trackHide); if (!n) toast('אין כאן שינויים'); else if (all) toast(`${accept ? 'אושרו' : 'נדחו'} ${n} שינויים`); },
            hasChanges: () => changes(editor.state.doc).length > 0,
            goChange: dir => goToChange(editor, dir, trackHide),
            caption: () => captionDialog(), crossRef: () => crossRefDialog(), insertTof: () => tofDialog(),
            link: () => linkDialog(), bookmark: () => bookmarkDialog(),
            newStyle: () => newStyleDialog(), updateStyle: () => updateStyleFromSelection(),
            numberingValue: () => numberingDialog(),
            wordCount: () => wordCountDialog(),
            navPane: () => { if (nav.isOpen()) nav.close(); else { if (phone.matches) openSheet(false); nav.open(); } refresh(); },
            navOn: () => nav.isOpen(),
            ruler: () => ruler.toggle(), rulerOn: () => ruler.isOn(),
            showMarks: () => editor.commands.setMarks(!marksOn()),
            marksOn: () => marksOn(),
            stepFont: dir => stepFont(dir),
            headingNumbers: () => setPage({ heading_numbers: !page.heading_numbers }),
            headingNumbersOn: () => !!page.heading_numbers,
            save: opts.save ? () => opts.save() : null,
            printPreview: () => preview(true),
            commandSearch: () => focusSearch(),
            find: mode => { if (phone.matches) openSheet(false); finder.open(mode); },
            // הגדרות העמוד של המקטע שבו הסמן
            sectionPage: () => { const at = editor.state.selection.from; return sections().filter((x, k) => k === 0 || x.from < at).pop().page; },
        },
    });
    const finder = createFindBar(h, { editor, notify: msg => toast(msg) });
    findSlot.append(finder.el);
    const nav = createNavPane({ editor, h, onClose: () => refresh() });
    // הסרגל: מעל הדף (בטלפון — כבוי כברירת מחדל); גבול שוליים שנגרר — השוליים של המקטע שבו הסמן
    ruler = createRuler({ editor, h, pagesLayer, defaultOn: !window.matchMedia('(max-width: 700px)').matches,
        sectionPage: () => { const at = editor.state.selection.from; return sections().filter((x, k) => k === 0 || x.from < at).pop().page; },
        setMargin: (side, mm) => {
            const secs = sections();
            const at = editor.state.selection.from;
            const k = secs.reduce((acc, x, i) => (i && x.from < at ? i : acc), 0);
            if (secs.length === 1) setPage({ margins: { [side]: mm } });
            else setSections(k, { margins: { ...secs[k].page.margins, [side]: mm } }, {});
        } });
    stage.prepend(ruler.el);
    stage.before(ruler.vel);   // האנכי — עמודה צרה בצד הדף, מחוץ לגלילה (צמוד לעמוד בגלילה)
    body.prepend(nav.el);
    // מעיר (שלב 5): המסמך לקריאה בלבד — העורך לא מקבל מקלדת, ולכן Ctrl+Alt+M (הערה חדשה) נתפס כאן, ברמת הדף
    const commentKey = e => {
        if ((e.ctrlKey || e.metaKey) && e.altKey && e.code === 'KeyM' && !editor.isEditable && opts.commentOnly?.() && root.isConnected) { e.preventDefault(); cmds.run('comment'); }
    };
    document.addEventListener('keydown', commentKey);
    const comments = createComments({ editor, h, user: opts.user, onChange: () => refresh(), commentOnly: () => !!opts.commentOnly?.() });
    body.append(comments.el);
    pasteOpts = createPasteOptions({ editor, h, host: root });
    const marksOn = () => !!marksKey.getState(editor.state)?.on;
    const ICONS = {
        bold: () => h('b', 'B'), italic: () => h('i', 'I'), underline: () => h('u', 'U'), strike: () => h('s', 'S'),
        superscript: () => h('span', 'x', h('sup', '2')), subscript: () => h('span', 'x', h('sub', '2')),
        alignRight: () => alignIcon('right'), alignCenter: () => alignIcon('center'), alignLeft: () => alignIcon('left'), alignJustify: () => alignIcon('justify'),
    };
    /** כפתור של פקודה: הסמל, הכותרת והפעולה — מהרישום. */
    const cmdBtn = (id, extra = {}) => btn(ICONS[id]?.() ?? cmds.get(id).icon, cmds.title(id), () => cmds.run(id), extra);
    /** תיבת בחירה של פקודה עם ערך (גופן, גודל…). */
    const cmdSel = (id, cls, options) => h('select.tb-sel.' + cls, { 'aria-label': cmds.get(id).label, title: cmds.get(id).label,
        onchange: e => cmds.run(id, e.target.value) }, options);
    const cmdColor = (id, value, cls = '') => h('label.tb.tb-color' + cls, { title: cmds.get(id).label },
        h('span', { 'aria-hidden': 'true' }, cmds.get(id).icon), h('input', { type: 'color', value, 'aria-label': cmds.get(id).label, oninput: e => cmds.run(id, e.target.value) }));

    const linkOff = cmdBtn('unlink');
    const ulSel = cmdSel('underlineStyle', 'w-ul', Object.entries(UNDERLINES).map(([v, t]) => h('option', { value: v }, t)));
    const hnumBtn = cmdBtn('headingNumbers');
    const styleSel = cmdSel('style', 'w-style', []);
    // גלריית סגנונות: כל כפתור נראה כמו הסגנון (גופן, משקל, צבע; גודל מוקטן), כמו ב-Word
    const gallery = h('div.rb-gallery', { role: 'group', 'aria-label': 'גלריית סגנונות' });
    const styleName = k => STYLE_NAMES[k] || page.styles[k]?.name || k;
    const userKeys = () => Object.keys(page.styles).filter(k => USER_STYLE.test(k)).sort((a, b) => a.slice(1) - b.slice(1));
    stylesUI = () => {
        const cur = styleSel.value;
        styleSel.textContent = '';
        for (const k of [...Object.keys(STYLE_NAMES), ...userKeys()]) styleSel.append(h('option', { value: k }, styleName(k)));
        styleSel.value = cur && styleSel.querySelector(`option[value="${cur}"]`) ? cur : 'normal';
        gallery.textContent = '';
        for (const k of [...GALLERY, ...userKeys()]) {
            const st = { ...(k === 'normal' || USER_STYLE.test(k) ? page.styles.normal : {}), ...page.styles[k] };
            gallery.append(h('button.rb-sty', { type: 'button', title: styleName(k), 'aria-pressed': 'false', 'data-style': k, onclick: () => cmds.run('style', k) },
                h('span', { style: { fontFamily: cssFont(st.font || page.font.family), fontWeight: st.bold ? '700' : '400', fontStyle: st.italic ? 'italic' : 'normal',
                    textDecoration: st.underline ? 'underline' : 'none', color: st.color || '', fontSize: Math.min(15, Math.max(10, (st.size || page.font.size) * 0.95)) + 'px' } }, styleName(k))));
        }
    };
    const fontSel = cmdSel('fontFamily', 'w-font', [h('option', { value: '' }, 'גופן המסמך'), ...FONTS.map(f => h('option', { value: f, style: { fontFamily: cssFont(f) } }, f))]);
    // גודל גופן — שדה עם רשימת הצעות (כמו ב-Word): כל גודל בין 1 ל-1638, בחצאי נקודה (13.5); ערך לא תקין — חוזר לגודל הנוכחי
    const sizeList = h('datalist', { id: 'wp-sizes-' + Math.random().toString(36).slice(2, 8) }, ...SIZE_LIST.map(s => h('option', { value: s })));
    const sizeSel = h('input.tb-sel.w-size', { type: 'text', inputmode: 'decimal', 'aria-label': cmds.get('fontSize').label, autocomplete: 'off',
        onchange: e => applySize(e.target), onkeydown: e => { if (e.key === 'Enter') { e.preventDefault(); applySize(e.target); editor.view.focus(); } else if (e.key === 'Escape') { e.target.value = cmds.state('fontSize').value; editor.view.focus(); } } });
    sizeSel.setAttribute('list', sizeList.id);   // list — מאפיין לקריאה בלבד, רק כתכונה
    function applySize(el) {
        const v = parseSize(el.value);
        if (v === null) { el.value = cmds.state('fontSize').value; el.title = 'גודל גופן: מספר בין 1 ל-1638'; return; }
        el.title = 'גודל';
        if (String(v) !== cmds.state('fontSize').value) cmds.run('fontSize', String(v));
    }
    const spacingSel = cmdSel('letterSpacing', 'w-spacing', SPACING.map(([v, t]) => h('option', { value: v }, t)));
    const listType = cmdSel('listType', 'w-list', [h('option', { value: 'decimal' }, '1.'), h('option', { value: 'hebrew' }, 'א.'), h('option', { value: 'roman' }, 'i.'), h('option', { value: 'multi' }, '1.1.1')]);
    const numBtns = ['restartNumbering', 'continueNumbering', 'numberingValue'].map(id => cmdBtn(id));
    const tocUpd = cmdBtn('updateToc');
    const trackBtn = cmdBtn('trackChanges');
    const trackViewSel = cmdSel('trackView', 'w-tview', Object.entries(TRACK_VIEWS).map(([v, t]) => h('option', { value: v }, t)));
    const changeBtns = ['acceptChange', 'rejectChange', 'prevChange', 'nextChange', 'acceptAll', 'rejectAll'].map(id => cmdBtn(id));
    sizeSel.title = 'גודל';
    let zoomLabel = h('span.tb-zoom', '100%');
    let stZoom = null;   // הזום בשורת המצב (נבנה עם שורת המצב)
    const imgInput = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', multiple: true, hidden: true,
        onchange: e => { if (e.target.files.length) insertImages([...e.target.files]); e.target.value = ''; } });

    const TOGGLES = ['bold', 'italic', 'underline', 'strike', 'superscript', 'subscript', 'alignRight', 'alignCenter', 'alignLeft', 'alignJustify', 'bulletList', 'orderedList'];
    const B = Object.fromEntries(['undo', 'redo', ...TOGGLES, 'clearFormat', 'outdent', 'indent', 'paragraphDialog', 'insertTable', 'insertImage', 'pageBreak',
        'footnote', 'sectionBreak', 'pageSetup', 'editStyles', 'print', 'zoomOut', 'zoomIn', 'zoomFit'].map(id => [id, cmdBtn(id)]));
    B.navPane = cmdBtn('navPane');
    B.showMarks = cmdBtn('showMarks');
    B.ruler = cmdBtn('ruler');
    B.painter = cmdBtn('formatPainter', { ondblclick: () => cmds.run('formatPainter', true) });
    B.color = cmdColor('color', '#c00000');
    B.mark = cmdColor('highlight', '#ffff00', '.mark');

    // ---------- הסרט (כמו ב-Word): לשוניות → קבוצות עם שם; לשונית הקשר לטבלה ולתמונה; כיווץ (Ctrl+F1); חצים ----------
    // קבוצה: שם מתחת, ושורה אחת או שתיים של פקדים
    const grp = (name, ...rows) => h('div.rb-group', { role: 'group', 'aria-label': name },
        h('div.rb-rows', rows.map(r => h('div.rb-row', (Array.isArray(r) ? r : [r]).filter(Boolean)))),
        h('div.rb-name', { 'aria-hidden': 'true' }, name));
    const zoomGroup = grp('זום', [B.zoomOut, zoomLabel, B.zoomIn, B.zoomFit]);
    zoomGroup.classList.add('tb-zoomg');
    const TABS = [
        ['home', 'בית', [
            grp('לוח', B.painter),
            grp('גופן', [fontSel, sizeSel, sizeList, cmdBtn('growFont'), cmdBtn('shrinkFont'), B.clearFormat], [B.bold, B.italic, B.underline, ulSel, B.strike, B.superscript, B.subscript, B.color, B.mark, spacingSel]),
            grp('פסקה', [B.bulletList, B.orderedList, listType, ...numBtns, B.outdent, B.indent], [B.alignRight, B.alignCenter, B.alignLeft, B.alignJustify, B.paragraphDialog, hnumBtn]),
            grp('סגנונות', [gallery], [styleSel, B.editStyles, cmdBtn('newStyle'), cmdBtn('updateStyle')]),
            grp('עריכה', [cmdBtn('find'), cmdBtn('replace')])]],
        ['insert', 'הוספה', [grp('טבלאות', B.insertTable), grp('איורים', [B.insertImage, imgInput]), grp('הערות', [B.footnote, cmdBtn('endnote')]), grp('קישורים', [cmdBtn('link'), linkOff, cmdBtn('bookmark')]), grp('כיתובים', [cmdBtn('caption'), cmdBtn('crossRef'), cmdBtn('insertTof')]), grp('תוכן עניינים', [cmdBtn('insertToc'), tocUpd]), grp('עמודים', B.pageBreak)]],
        ['layout', 'פריסה', [grp('הגדרת עמוד', B.pageSetup), grp('מעברים', [B.sectionBreak, cmdBtn('columnBreak')])]],
        ['review', 'סקירה', [grp('הערות', [cmdBtn('comment'), cmdBtn('commentsPane')]),
            grp('מעקב', [trackBtn, trackViewSel, cmdBtn('trackAuthors')]),
            grp('שינויים', changeBtns.slice(0, 4), changeBtns.slice(4))]],
        ['view', 'תצוגה', [zoomGroup, grp('הצגה', [B.navPane, B.ruler, B.showMarks, cmdBtn('wordCount')]), grp('מסמך', [opts.save ? cmdBtn('save') : null, B.print, cmdBtn('printPreview'), opts.versions ? cmdBtn('versions') : null, ...(opts.menu || [])])]],
    ];
    const uid = Math.random().toString(36).slice(2, 8);
    const tabsEl = h('div.rb-tabs', { role: 'tablist', 'aria-label': 'לשוניות הסרט' });
    const tabBtn = {}, panel = {};
    const mkTab = (key, label, items, ctx = false) => {
        const id = `rb-${uid}-${key}`;
        tabBtn[key] = h('button.rb-tab' + (ctx ? '.ctx' : ''), { type: 'button', role: 'tab', id: id + '-t', 'aria-controls': id, 'aria-selected': 'false', tabindex: '-1',
            onmousedown: e => e.preventDefault(), onclick: () => selectTab(key, true) }, label);
        panel[key] = h('div.rb-panel' + (ctx ? '.wp-ctx' : ''), { role: 'tabpanel', id, 'aria-labelledby': id + '-t', hidden: true }, items);
        tabsEl.append(tabBtn[key]);
    };
    for (const [key, label, items] of TABS) mkTab(key, label, items);
    mkTab('ctx', '', [], true);
    tabBtn.ctx.hidden = true;
    const ctxBar = panel.ctx;
    const store = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch { /* */ } return null; };
    let curTab = '';
    let userTab = TABS.some(t => t[0] === store('wp-ribbon-tab')) ? store('wp-ribbon-tab') : 'home';
    function selectTab(key, byUser = false) {
        if (byUser && collapsed) setCollapsed(false);
        curTab = key;
        for (const k in tabBtn) {
            const on = k === key;
            tabBtn[k].setAttribute('aria-selected', on ? 'true' : 'false');
            tabBtn[k].tabIndex = on ? 0 : -1;
            panel[k].hidden = !on;
        }
        if (byUser && key !== 'ctx') { userTab = key; store('wp-ribbon-tab', key); }
    }
    let collapsed = store('wp-ribbon-collapsed') === '1';
    const collapseBtn = h('button.rb-collapse', { type: 'button', onmousedown: e => e.preventDefault(), onclick: () => setCollapsed(!collapsed) });
    function setCollapsed(v) {
        collapsed = !!v;
        bar.classList.toggle('collapsed', collapsed);
        collapseBtn.textContent = collapsed ? '⌄' : '⌃';
        const t = collapsed ? 'הצגת הסרט (Ctrl+F1)' : 'כיווץ הסרט (Ctrl+F1)';
        collapseBtn.title = t;
        collapseBtn.setAttribute('aria-label', t);
        collapseBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        store('wp-ribbon-collapsed', collapsed ? '1' : '0');
    }
    // חיפוש פקודה (Alt+Q), כמו "ספר לי" ב-Word: מסנן את רישום הפקודות לפי השם; Enter מפעיל. פקודה שצריכה ערך
    // (גופן, גודל, צבע…) — פותח את הלשונית שלה ומעביר אליה את המיקוד
    const searchIn = h('input.rb-search-in', { type: 'search', placeholder: 'חיפוש פקודה', 'aria-label': cmds.title('commandSearch'), title: cmds.title('commandSearch'),
        role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': `rb-${uid}-res`, autocomplete: 'off', spellcheck: 'false' });
    const results = h('ul.rb-results', { role: 'listbox', id: `rb-${uid}-res`, 'aria-label': 'פקודות', hidden: true });
    const searchBox = h('div.rb-search', searchIn, results);
    bar.append(h('div.rb-head', h('div.rb-quick', { role: 'group', 'aria-label': 'גישה מהירה' }, opts.save ? cmdBtn('save') : null, B.undo, B.redo), tabsEl, searchBox, collapseBtn), ...Object.values(panel));
    setCollapsed(collapsed);
    selectTab(userTab);
    // חצים: בין הלשוניות, ובתוך הלשונית בין הפקדים (מימין לשמאל: שמאלה = הבא). תיבת בחירה — החצים שלה
    tabsEl.addEventListener('keydown', e => {
        const keys = Object.keys(tabBtn).filter(k => !tabBtn[k].hidden);
        const i = keys.indexOf(curTab);
        const to = { ArrowLeft: i + 1, ArrowRight: i - 1, Home: 0, End: keys.length - 1 }[e.key];
        if (to === undefined) return;
        e.preventDefault();
        const k = keys[(to + keys.length) % keys.length];
        selectTab(k, true);
        tabBtn[k].focus();
    });
    bar.addEventListener('keydown', e => {
        if ((e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') || !e.target.closest('.rb-panel') || e.target.matches('select')) return;
        const items = [...e.target.closest('.rb-panel').querySelectorAll('button, select, input[type=color]')].filter(x => !x.disabled && x.offsetParent);
        const i = items.indexOf(e.target);
        if (i < 0) return;
        e.preventDefault();
        items[(i + (e.key === 'ArrowLeft' ? 1 : -1) + items.length) % items.length].focus();
    });
    // קיצורים מכל מקום בעורך: Ctrl+F1 — כיווץ הסרט; Alt+Q — חיפוש פקודה; Ctrl+S — שמירה; Escape — יציאה מתצוגה לפני הדפסה
    root.addEventListener('keydown', e => {
        if (e.key === 'F1' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); setCollapsed(!collapsed); }
        else if (e.altKey && !e.ctrlKey && e.code === 'KeyQ') { e.preventDefault(); focusSearch(); }
        else if ((e.ctrlKey || e.metaKey) && !e.altKey && e.code === 'KeyS') { e.preventDefault(); cmds.run('save'); }
        else if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.code === 'KeyK' && !previewing) { e.preventDefault(); cmds.run('link'); }
        // Ctrl+Shift+G — ספירת מילים; Ctrl+Shift+8 (Ctrl+*) — סימני פסקה (כמו ב-Word)
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && !e.altKey && e.code === 'KeyG') { e.preventDefault(); cmds.run('wordCount'); }
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && !e.altKey && e.code === 'Digit8') { e.preventDefault(); cmds.run('showMarks'); }
        // Ctrl+Shift+> / < — הגדלה / הקטנה של הגופן (לפי המקש, גם במקלדת עברית)
        else if ((e.ctrlKey || e.metaKey) && e.shiftKey && !e.altKey && (e.code === 'Period' || e.code === 'Comma') && !previewing) {
            e.preventDefault();
            cmds.run(e.code === 'Period' ? 'growFont' : 'shrinkFont');
        }
        // Ctrl+F / Ctrl+H — החיפוש של העורך, לא של הדפדפן (שלא מוצא טקסט בעמודים שמחוץ למסך)
        else if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.code === 'KeyF' || e.code === 'KeyH') && !previewing) {
            e.preventDefault();
            cmds.run(e.code === 'KeyF' ? 'find' : 'replace');
        }
        else if (e.key === 'Escape' && previewing) { e.preventDefault(); preview(false); }
    });

    const plain = s => String(s).normalize('NFD').replace(/[\u0591-\u05C7]/g, '').toLowerCase();
    let hits = [], active = -1;
    function findCommands(q) {
        const n = plain(q.trim());
        if (!n) return [];
        const all = cmds.list().filter(c => c.id !== 'commandSearch' && (!c.when || cmds.state(c.id).enabled || c.group === 'table' || c.group === 'image'));
        // זמינות קודם, אחר כך מה שמתחיל במילה שהוקלדה, ואז הקצרות
        return all.map(c => ({ c, at: plain(c.label).indexOf(n), ok: cmds.state(c.id).enabled })).filter(x => x.at >= 0)
            .sort((a, b) => (b.ok - a.ok) || (a.at === 0 ? 0 : 1) - (b.at === 0 ? 0 : 1) || a.c.label.length - b.c.label.length).slice(0, 8).map(x => x.c);
    }
    function paintResults() {
        results.textContent = '';
        hits.forEach((c, i) => {
            const ok = cmds.state(c.id).enabled;
            results.append(h('li', { role: 'option', id: `rb-${uid}-o${i}`, 'aria-selected': i === active ? 'true' : 'false', 'aria-disabled': ok ? 'false' : 'true',
                onmousedown: e => e.preventDefault(), onclick: () => runHit(i) },
                h('span.ic', { 'aria-hidden': 'true' }, c.icon || ''), h('span.lb', c.label), c.keys ? h('span.k', c.keys) : null, ok ? null : h('span.k', 'לא זמין כאן')));
        });
        if (!hits.length && searchIn.value.trim()) results.append(h('li.none', 'אין פקודה כזו'));
        const open = !!searchIn.value.trim();
        results.hidden = !open;
        searchIn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (active >= 0) searchIn.setAttribute('aria-activedescendant', `rb-${uid}-o${active}`); else searchIn.removeAttribute('aria-activedescendant');
    }
    function closeSearch() { searchIn.value = ''; hits = []; active = -1; paintResults(); }
    function runHit(i) {
        const c = hits[i];
        if (!c || !cmds.state(c.id).enabled) return;
        closeSearch();
        // פקד בסרט שצריך ערך (בחירה / צבע / גודל) — פותחים את הלשונית שלו ומתמקדים בו; אחרת — מפעילים
        const el = [...bar.querySelectorAll('.rb-panel select, .rb-panel input[type=color], .rb-panel input.w-size')].find(x => x.getAttribute('aria-label') === c.label);
        if (el) {
            const key = Object.keys(panel).find(k => panel[k].contains(el));
            if (phone.matches) openSheet(true);
            selectTab(key, true);
            el.focus();
            // פקודה קודמת מחזירה את המיקוד לעורך באיחור של פריים (TipTap) — לא לתת לה לגנוב אותו מהפקד
            requestAnimationFrame(() => { if (document.activeElement !== el) el.focus(); });
            return;
        }
        editor.view.focus();   // מיד — לא באיחור של פריים
        cmds.run(c.id);
    }
    function focusSearch() {
        if (phone.matches) openSheet(true);
        if (collapsed) setCollapsed(false);
        searchIn.focus();
        searchIn.select();
        // פקודה שהופעלה רגע לפני מחזירה את המיקוד לעורך באיחור של פריים (TipTap, focus) — לא לתת לה לגנוב אותו
        requestAnimationFrame(() => { if (document.activeElement !== searchIn) searchIn.focus(); });
    }
    searchIn.addEventListener('input', () => { hits = findCommands(searchIn.value); active = hits.length ? 0 : -1; paintResults(); });
    searchIn.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (hits.length) { active = (active + (e.key === 'ArrowDown' ? 1 : -1) + hits.length) % hits.length; paintResults(); }
        } else if (e.key === 'Enter') { e.preventDefault(); runHit(active); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeSearch(); editor.commands.focus(); }
    });
    searchIn.addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== searchIn) { results.hidden = true; searchIn.setAttribute('aria-expanded', 'false'); } }, 150));

    // ---------- תצוגה לפני הדפסה: העמודים בלי סרט ובלי סימני עריכה (כמו בהדפסה), קריאה בלבד עד הסגירה ----------
    let canEdit = opts.editable !== false;
    // ביקורת 04, R03: לא שומרים צילום של ההרשאה — canEdit היא ההרשאה העדכנית (setEditable מבחוץ), והעריכה בפועל
    // היא canEdit && !previewing. כך הרשאה שבוטלה בזמן התצוגה המקדימה לא חוזרת כשיוצאים ממנה
    let previewing = false;
    const pvPages = h('span.pv-pages');
    previewBar.append(h('strong', 'תצוגה לפני הדפסה'), pvPages, h('span.st-fill'),
        h('button.btn.primary', { type: 'button', onclick: () => print() }, '🖨 הדפסה'),
        h('button.btn', { type: 'button', onclick: () => preview(false) }, 'חזרה לעריכה'));
    function preview(on) {
        if (on === previewing) return;
        previewing = on;
        if (on) { editor.setEditable(false, false); openSheet(false); finder.close(false); }
        else editor.setEditable(canEdit, false);
        root.classList.toggle('wp-preview', on);
        previewBar.hidden = !on;
        pvPages.textContent = `${pageCount} ${pageCount === 1 ? 'עמוד' : 'עמודים'}`;
        fit = true;
        setZoom();
        refresh();
        if (on) previewBar.querySelector('.btn.primary').focus(); else editor.commands.focus();
    }

    // ---------- שורת המצב: עמוד X מתוך Y (לפי הסמן), מילים, זום ----------
    const stPage = h('span.st-page');
    const stWords = h('button.st-words', { type: 'button', title: 'ספירת מילים (Ctrl+Shift+G)', onclick: () => cmds.run('wordCount') });
    const stFont = h('span.st-font', { hidden: true });   // "גופן מוחלף" — רק כשהסמן בגופן שאינו זמין
    stZoom = h('button.st-zoom', { type: 'button', title: 'התאמה למסך', onclick: () => cmds.run('zoomFit') });
    statusBar.append(stPage, h('span.st-sep', { 'aria-hidden': 'true' }), stWords, stFont, h('span.st-fill'),
        h('button.st-btn', { type: 'button', 'aria-label': 'הקטנת התצוגה', title: 'הקטנת התצוגה', onclick: () => cmds.run('zoomOut') }, '−'), stZoom,
        h('button.st-btn', { type: 'button', 'aria-label': 'הגדלת התצוגה', title: 'הגדלת התצוגה', onclick: () => cmds.run('zoomIn') }, '+'));
    /**
     * חיווי גופן מוחלף (ליקוי ח'): גופן שאינו ברשימה (למשל מ-Word) מוצג ב-Heebo — בתיבת הגופן "(מוחלף)", ובשורת
     * המצב מה מוצג במקומו. שם של Word (David…) — בהסבר שמעל התיבה: באיזה גופן חופשי הוא מוצג, זהה בכל מחשב.
     */
    let subOpt = null;
    function fontIndicator(font) {
        const missing = !!font && !FONTS.includes(font);
        if (missing) {
            if (!subOpt) fontSel.append(subOpt = h('option.sub'));
            subOpt.value = font;
            subOpt.textContent = `${font} (מוחלף)`;
        } else if (subOpt) { subOpt.remove(); subOpt = null; }
        fontSel.value = missing || FONTS.includes(font) ? font : '';
        fontSel.classList.toggle('sub', missing);
        const alias = FALLBACK[font]?.match(/'([^']+)'/)?.[1];
        fontSel.title = missing ? `הגופן "${font}" אינו זמין — מוצג ב-Heebo, זהה במסך, בהדפסה וב-PDF`
            : alias ? `${font} — מוצג בגופן החופשי ${alias}, זהה בכל מחשב, בהדפסה וב-PDF` : cmds.get('fontFamily').label;
        stFont.hidden = !missing;
        stFont.textContent = missing ? `⚠ גופן מוחלף: ${font} ← Heebo` : '';
        stFont.title = missing ? fontSel.title : '';
    }

    let curPage = 1;
    function caretPage() {
        const pages = pagesLayer.children;
        if (!pages.length) return 1;
        let top;
        try { top = editor.view.coordsAtPos(editor.state.selection.head).top; } catch { return curPage; }
        for (let i = 0; i < pages.length; i++) if (top < pages[i].getBoundingClientRect().bottom) return frameOf(i) + 1;
        return pageCount;
    }
    let wordsTimer = 0;
    function countWords() {
        clearTimeout(wordsTimer);
        wordsTimer = setTimeout(() => {
            const n = (editor.state.doc.textBetween(0, editor.state.doc.content.size, ' ', ' ').match(/[^\s]+/g) || []).length;
            stWords.textContent = `${n.toLocaleString('he-IL')} ${n === 1 ? 'מילה' : 'מילים'}`;
        }, 300);
    }
    function statusBarPage() {
        curPage = caretPage();
        stPage.textContent = `עמוד ${curPage} מתוך ${pageCount}`;
    }

    function refresh() {
        const on = (b, v) => { b.classList.toggle('on', !!v); b.setAttribute('aria-pressed', v ? 'true' : 'false'); };
        for (const id of TOGGLES) on(B[id], cmds.state(id).active);
        const sk = cmds.state('style').value;
        styleSel.value = sk;
        for (const b of gallery.children) { b.classList.toggle('on', b.dataset.style === sk); b.setAttribute('aria-pressed', String(b.dataset.style === sk)); }
        fontIndicator(cmds.state('fontFamily').value);
        if (document.activeElement !== sizeSel) sizeSel.value = cmds.state('fontSize').value;
        ulSel.value = cmds.state('underlineStyle').value;
        spacingSel.value = cmds.state('letterSpacing').value;
        const lt = cmds.state('listType').value;
        if (lt) listType.value = lt;
        B.undo.disabled = !cmds.state('undo').enabled;
        B.redo.disabled = !cmds.state('redo').enabled;
        linkOff.disabled = !cmds.state('unlink').enabled;
        tocUpd.disabled = !cmds.state('updateToc').enabled || !editor.isEditable;
        trackViewSel.value = trackView;
        const anyChange = cmds.state('acceptChange').enabled;
        changeBtns.forEach((b, i) => { b.disabled = !anyChange || (i !== 2 && i !== 3 && !editor.isEditable); });
        for (const b of numBtns) b.disabled = !editor.isActive('orderedList') || !editor.isEditable;
        hnumBtn.classList.toggle('on', !!page.heading_numbers);
        for (const [b, on] of [[B.navPane, nav.isOpen()], [B.ruler, !!ruler?.isOn()], [B.showMarks, marksOn()], [trackBtn, !!editor.state.doc.attrs.track]]) { b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); }
        hnumBtn.setAttribute('aria-pressed', String(!!page.heading_numbers));
        contextBar();
        statusBarPage();
        refreshMobile();
    }

    // ---------- טלפון: שורת פעולות קבועה בתחתית, ו"כלים" — כל הסרט בגיליון מלמטה ----------
    // בטלפון הסרט עצמו הוא הגיליון: אותם פקדים ואותן לשוניות, בשורות שנשברות (בלי גלילה הצידה), אזורי לחיצה 44px.
    // טבלה / תמונה — הגיליון נפתח ישר בלשונית ההקשר. כשהמקלדת פתוחה — השורה והגיליון מעליה (visualViewport).
    const phone = window.matchMedia('(max-width: 700px)');
    const ALIGNS = ['alignRight', 'alignCenter', 'alignLeft', 'alignJustify'];
    const MB = Object.fromEntries(['undo', 'bold', 'italic', 'underline', 'bulletList'].map(id => [id, cmdBtn(id)]));
    MB.align = btn(alignIcon('right'), 'יישור (הבא בתור)', () => {
        const i = ALIGNS.findIndex(id => cmds.state(id).active);
        cmds.run(ALIGNS[(i + 1) % ALIGNS.length]);
    });
    MB.tools = h('button.mb-tools', { type: 'button', 'aria-haspopup': 'dialog', 'aria-expanded': 'false', onmousedown: e => e.preventDefault(), onclick: () => openSheet(!sheetOpen) },
        h('span', { 'aria-hidden': 'true' }, '☰'), ' כלים');
    mobileBar.append(...Object.values(MB));
    const sheetClose = h('button.rb-close', { type: 'button', 'aria-label': 'סגירת הכלים', title: 'סגירה', onmousedown: e => e.preventDefault(), onclick: () => openSheet(false) }, '✕');
    bar.querySelector('.rb-head').append(sheetClose);
    let sheetOpen = false;
    function openSheet(v) {
        sheetOpen = !!v && phone.matches;
        bar.classList.toggle('sheet-open', sheetOpen);
        sheetShade.hidden = !sheetOpen;
        MB.tools.setAttribute('aria-expanded', sheetOpen ? 'true' : 'false');
        if (phone.matches) bar.inert = !sheetOpen;
        if (sheetOpen && !tabBtn.ctx.hidden) selectTab('ctx');
    }
    sheetShade.addEventListener('click', () => openSheet(false));
    bar.addEventListener('keydown', e => { if (e.key === 'Escape' && sheetOpen) { e.preventDefault(); openSheet(false); } });
    function applyPhone() {
        root.classList.toggle('wp-phone', phone.matches);
        bar.inert = phone.matches && !sheetOpen;
        if (!phone.matches) openSheet(false);
    }
    phone.addEventListener('change', applyPhone);
    // המקלדת בטלפון: כמה מתחתית המסך היא מכסה — השורה בתחתית והגיליון עולים מעליה
    const vv = window.visualViewport;
    const onViewport = () => root.style.setProperty('--kb', Math.max(0, Math.round(innerHeight - vv.height - vv.offsetTop)) + 'px');
    vv?.addEventListener('resize', onViewport);
    vv?.addEventListener('scroll', onViewport);
    function refreshMobile() {
        for (const id of ['bold', 'italic', 'underline', 'bulletList']) {
            const v = cmds.state(id).active;
            MB[id].classList.toggle('on', !!v);
            MB[id].setAttribute('aria-pressed', v ? 'true' : 'false');
        }
        MB.undo.disabled = !cmds.state('undo').enabled || !ed.isEditable;
        const al = ALIGNS.find(id => cmds.state(id).active) || 'alignRight';
        MB.align.replaceChildren(alignIcon(al.slice(5).toLowerCase()));
        MB.tools.classList.toggle('ctx', !tabBtn.ctx.hidden);
    }

    // ---------- כלים להקשר: טבלה / תמונה ----------
    let ctxKind = '';
    function contextBar() {
        const sel = ed.state.selection;
        const isImg = sel.node?.type.name === 'image';
        const kind = !ed.isEditable ? '' : isImg ? 'image' : ed.isActive('table') ? 'table' : '';
        if (kind === ctxKind && kind !== 'image') return;
        const was = ctxKind;
        ctxKind = kind;
        ctxBar.textContent = '';
        // לשונית ההקשר מופיעה רק כשיש טבלה / תמונה. תמונה שנבחרה — הלשונית נפתחת לבד; כשיוצאים — חוזרים ללשונית הקודמת
        tabBtn.ctx.hidden = !kind;
        tabBtn.ctx.textContent = kind === 'table' ? 'טבלה' : kind === 'image' ? 'תמונה' : '';
        if (!kind && curTab === 'ctx') selectTab(userTab);
        if (kind === 'image' && was !== 'image') selectTab('ctx');
        if (kind === 'table') {
            const T = id => cmdBtn('table.' + id);
            ctxBar.append(
                grp('שורות ועמודות', [T('addRowBefore'), T('addRowAfter'), T('addColumnBefore'), T('addColumnAfter')], [T('deleteRow'), T('deleteColumn')]),
                grp('תאים', [T('mergeOrSplit'), cmdColor('table.cellFill', '#ddeeff')], [T('toggleHeaderRow')]),
                grp('טבלה', [T('props'), T('deleteTable')], [cmdSel('table.style', 'w-tstyle', [h('option', { value: '' }, 'סגנון טבלה…'),
                    ...Object.entries(TABLE_STYLES).map(([k, v]) => h('option', { value: k }, v.name))])]));
        } else if (kind === 'image') {
            const a = sel.node.attrs;
            const wrapSel = cmdSel('image.wrap', 'w-wrap', Object.entries(WRAP_NAMES).map(([k, t]) => h('option', { value: k, selected: (a.wrap || 'inline') === k }, t)));
            ctxBar.append(grp('גלישת טקסט', wrapSel),
                grp('יישור', segBtns({ right: 'ימין', center: 'מרכז', left: 'שמאל' }, a.align, v => cmds.run('image.align', v), 'יישור')),
                grp('גודל', segBtns([['0.25', '¼'], ['0.5', '½'], ['1', 'מלא'], ['0', 'מקורי']], '', v => cmds.run('image.size', v), 'גודל')),
                grp('סיבוב', [cmdBtn('image.rotateLeft'), cmdBtn('image.rotateRight')]),
                ['behind', 'front'].includes(a.wrap) ? grp('שכבות', [cmdBtn('image.toFront'), cmdBtn('image.toBack')]) : null,
                grp('תמונה', [cmdBtn('image.crop'), cmdBtn('image.props'), cmdBtn('image.delete')]));
        }
    }
    /**
     * מאפייני טבלה: גבולות וצבע, רוחב ויישור (הטבלה כולה); יישור אנכי (התאים שבבחירה); גובה שורה (השורות שבבחירה).
     * שינוי רוחב מתאים גם את רוחבי העמודות (colwidth) באותו יחס — כך הטבלה ב-PDF וב-Word באותו רוחב.
     */
    /**
     * סגנון טבלה מוכן (כמו הגלריה של Word): גבולות וצבע, מילוי לשורה הראשונה, ופסים לסירוגין בשאר. נכתב כעיצוב רגיל
     * של הטבלה והתאים (borders / borderColor / background) — לכן זהה בעורך, בהדפסה, ב-PDF וב-Word. צעד ביטול אחד.
     */
    function applyTableStyle(key) {
        const st = TABLE_STYLES[key];
        const $f = editor.state.selection.$from;
        let tpos = -1, tnode = null;
        for (let d = $f.depth; d > 0; d--) if ($f.node(d).type.name === 'table') { tpos = $f.before(d); tnode = $f.node(d); break; }
        if (!st || !tnode) return false;
        const tr = editor.state.tr;
        tr.setNodeMarkup(tpos, undefined, { ...tnode.attrs, borders: st.borders, borderColor: st.color });
        let r = 0;
        tnode.forEach((row, off) => {
            const fill = r === 0 ? st.head : (st.band && r % 2 === 0 ? st.band : null);
            row.forEach((cell, coff) => {
                const pos = tpos + 1 + off + 1 + coff;
                tr.setNodeMarkup(pos, undefined, { ...cell.attrs, background: fill });
            });
            r++;
        });
        editor.view.dispatch(tr.setMeta('closeHistory$', true));
        editor.view.focus();
        const sel = ctxBar.querySelector('select.w-tstyle');
        if (sel) sel.value = '';   // אפשר לבחור שוב את אותו סגנון (למשל אחרי שנוספו שורות)
        return true;
    }

    function tablePropsDialog() {
        const st = editor.state;
        const $f = st.selection.$from;
        let tpos = -1, tnode = null;
        for (let d = $f.depth; d > 0; d--) if ($f.node(d).type.name === 'table') { tpos = $f.before(d); tnode = $f.node(d); break; }
        if (!tnode) return;
        // התאים והשורות שבבחירה (בחירת תאים — כולם; אחרת — התא שבו הסמן)
        const cells = [], rows = new Set();
        const tableEnd = tpos + tnode.nodeSize;
        if (st.selection.forEachCell) st.selection.forEachCell((n, pos) => cells.push([n, pos]));
        else for (let d = $f.depth; d > 0; d--) if (/^table(Cell|Header)$/.test($f.node(d).type.name)) { cells.push([$f.node(d), $f.before(d)]); break; }
        for (const [, pos] of cells) { const $c = st.doc.resolve(pos); rows.add($c.before($c.depth)); }
        const a = tnode.attrs;
        const c0 = cells[0]?.[0].attrs || {};
        const r0 = rows.size ? st.doc.nodeAt([...rows][0]).attrs : {};
        sheet('מאפייני טבלה', body => {
            body.append(
                h('section.group', h('h3', 'גבולות'), seg('borders', TABLE_BORDERS, a.borders || 'all', 'גבולות'),
                    field('צבע הגבולות', h('input', { type: 'color', name: 'bc', value: a.borderColor || '#888888' }))),
                h('section.group', h('h3', 'רוחב ויישור'),
                    h('div.fields.two', field('רוחב (% משורה)', numInput('width', a.width || 100, { min: 10, max: 100, step: 5 }))),
                    seg('align', { right: 'ימין', center: 'מרכז', left: 'שמאל' }, a.align || 'right', 'יישור הטבלה')),
                h('section.group', h('h3', 'תאים ושורות ', h('small', 'שבבחירה')),
                    seg('valign', VALIGNS, c0.valign || 'top', 'יישור אנכי בתא'),
                    h('div.fields.two', field('גובה שורה מינימלי (נק\', ריק — אוטומטי)', numInput('height', r0.height || '', { min: 0, max: 400 })))));
        }, form => {
            const f = new FormData(form);
            const width = Math.max(10, Math.min(100, Math.round(+f.get('width') || 100)));
            const tr = editor.state.tr;
            // עמודות: אותו יחס כמו שינוי הרוחב
            const k = width / (a.width || 100);
            if (k !== 1) tnode.descendants((n, p) => {
                if (/^table(Cell|Header)$/.test(n.type.name) && Array.isArray(n.attrs.colwidth)) {
                    tr.setNodeMarkup(tpos + 1 + p, undefined, { ...n.attrs, colwidth: n.attrs.colwidth.map(w => w ? Math.max(30, Math.round(w * k)) : w) });
                }
                return !/^table(Cell|Header)$/.test(n.type.name);
            });
            const bc = String(f.get('bc') || '').toLowerCase();
            tr.setNodeMarkup(tpos, undefined, { ...a, borders: f.get('borders') === 'all' ? null : f.get('borders'),
                borderColor: bc && bc !== '#888888' ? bc : null, width: width < 100 ? width : null, align: width < 100 && f.get('align') !== 'right' ? f.get('align') : null });
            for (const [n, pos] of cells) {
                const cur = tr.doc.nodeAt(pos);
                tr.setNodeMarkup(pos, undefined, { ...cur.attrs, valign: f.get('valign') === 'top' ? null : f.get('valign') });
            }
            const hgt = f.get('height') === '' ? null : Math.max(0, Math.min(400, +f.get('height'))) || null;
            for (const pos of rows) tr.setNodeMarkup(pos, undefined, { ...tr.doc.nodeAt(pos).attrs, height: hgt });
            editor.view.dispatch(tr.setMeta('closeHistory$', true));
            editor.view.focus();
        });
    }

    /** גודל תמונה: חלק מרוחב השורה (¼, ½, מלא), או 0 — הגודל המקורי. */
    function imageSizeTo(f) {
        const sel = ed.state.selection;
        const a = sel.node?.attrs;
        if (!a) return false;
        const max = editor.storage.wpPages.contentWidth;
        const img = editor.view.nodeDOM(sel.from)?.querySelector('img');
        const natural = img?.naturalWidth ? { w: img.naturalWidth, h: img.naturalHeight } : null;
        const g = imageBox(a, natural);
        const ratio = g.h / g.w;
        const c = a.crop || {};
        const keep = 1 - ((c.l || 0) + (c.r || 0)) / 100;   // רוחב החלק שנשאר אחרי החיתוך
        const w = f === 0 ? (natural?.w || g.w) : Math.round(max * f / keep);
        return chain().updateAttributes('image', { width: Math.round(w), height: Math.round(w * ratio) }).setNodeSelection(sel.from).run();
    }
    function segBtns(options, value, onPick, label) {
        return h('div.tb-seg', { role: 'group', 'aria-label': label },
            (Array.isArray(options) ? options : Object.entries(options)).map(([v, t]) => h('button', { type: 'button', class: String(value) === v ? 'on' : '', onmousedown: e => e.preventDefault(), onclick: () => onPick(v) }, t)));
    }

    // ---------- חלונות ----------
    /** חיתוך: ארבעה מחוונים (אחוזים מכל צד) עם תצוגה חיה. */
    function cropDialog(pos) {
        const node = ed.state.doc.nodeAt(pos);
        if (!node) return;
        const c = { t: 0, r: 0, b: 0, l: 0, ...(node.attrs.crop || {}) };
        const shades = { t: h('span.crop-shade'), r: h('span.crop-shade'), b: h('span.crop-shade'), l: h('span.crop-shade') };
        const paintShade = () => {
            Object.assign(shades.t.style, { top: 0, left: 0, right: 0, height: c.t + '%' });
            Object.assign(shades.b.style, { bottom: 0, left: 0, right: 0, height: c.b + '%' });
            Object.assign(shades.l.style, { top: c.t + '%', bottom: c.b + '%', left: 0, width: c.l + '%' });
            Object.assign(shades.r.style, { top: c.t + '%', bottom: c.b + '%', right: 0, width: c.r + '%' });
        };
        const row = (k, label) => {
            const out = h('output', c[k] + '%');
            return h('label.range-row', h('span', label), h('input', { type: 'range', min: 0, max: 45, step: 1, value: c[k], name: k,
                oninput: e => { c[k] = +e.target.value; out.textContent = c[k] + '%'; paintShade(); } }), out);
        };
        sheet('חיתוך תמונה', body => {
            body.append(h('div.crop-stage', h('div.crop-img', h('img', { src: node.attrs.src, alt: '' }), ...Object.values(shades))),
                row('t', 'למעלה'), row('b', 'למטה'), row('r', 'ימין'), row('l', 'שמאל'),
                h('p.hint', 'החלק הכהה ייחתך. אפשר לחזור ולהחזיר — התמונה המקורית נשמרת.'));
            paintShade();
        }, () => {
            const crop = c.t || c.r || c.b || c.l ? { t: c.t, r: c.r, b: c.b, l: c.l } : null;
            ed.chain().focus().setNodeSelection(pos).updateAttributes('image', { crop }).setNodeSelection(pos).run();
        });
    }

    /**
     * הערת שוליים: חדשה (pos = null — במקום הסמן) או עריכה של קיימת. טקסט ריק בעריכה — מחיקת ההערה.
     * העריכה — בעורך קטן עם עיצוב תווים (מודגש, נטוי, קו תחתון, קו חוצה, עילי, תחתי); נשמר ב-attrs.content,
     * והטקסט הפשוט ב-attrs.text. פסקה אחת: Enter לא פותח שורה (Ctrl+Enter — שמירה).
     */
    /** הערת שוליים — או הערת סיום (end): אותו חלון; הטקסט מופיע בתחתית העמוד / בסוף המסמך. */
    function footnoteDialog(pos, endNew = false) {
        const node = pos == null ? null : ed.state.doc.nodeAt(pos);
        if (pos != null && node?.type.name !== 'footnote') return;
        if (!editor.isEditable) return;
        const end = node ? !!node.attrs.end : endNew;
        const kind = end ? 'הערת סיום' : 'הערת שוליים';
        const sel = ed.state.selection;
        let mini = null;
        sheet(node ? kind : kind + ' חדשה', (body, dlg) => {
            const box = h('div.fn-edit');
            const tool = (cmd, mark, label, icon) => h('button.fn-tool', {
                type: 'button', title: label, 'aria-label': label, 'data-mark': mark,
                onmousedown: e => e.preventDefault(), onclick: () => mini.chain().focus()[cmd]().run(),
            }, icon);
            const tools = h('div.fn-tools', { role: 'toolbar', 'aria-label': 'עיצוב ההערה' },
                tool('toggleBold', 'bold', 'מודגש (Ctrl+B)', h('b', 'B')), tool('toggleItalic', 'italic', 'נטוי (Ctrl+I)', h('i', 'I')),
                tool('toggleUnderline', 'underline', 'קו תחתון (Ctrl+U)', h('u', 'U')), tool('toggleStrike', 'strike', 'קו חוצה', h('s', 'S')),
                tool('toggleSuperscript', 'superscript', 'כתב עילי', ICONS.superscript()), tool('toggleSubscript', 'subscript', 'כתב תחתי', ICONS.subscript()));
            // div ולא label: בתוך label לחיצה על הכיתוב "לוחצת" על הכפתור הראשון
            body.append(h('div.f', h('span', end ? 'הטקסט שיופיע בסוף המסמך' : 'הטקסט שיופיע בתחתית העמוד'), tools, box));
            if (node) body.append(h('p.hint', 'מחיקת כל הטקסט ושמירה — מוחקת את ההערה.'));
            const text = node?.attrs.text || '';
            const inline = Array.isArray(node?.attrs.content) ? node.attrs.content : (text ? [{ type: 'text', text }] : []);
            mini = new Editor({
                element: box,
                extensions: [
                    StarterKit.configure({ heading: false, bulletList: false, orderedList: false, listItem: false, listKeymap: false, blockquote: false,
                        code: false, codeBlock: false, horizontalRule: false, hardBreak: false, link: false, trailingNode: false, dropcursor: false, gapcursor: false }),
                    Superscript, Subscript,
                    Extension.create({ name: 'fnKeys', addKeyboardShortcuts: () => ({
                        Enter: () => true, 'Shift-Enter': () => true,
                        'Mod-Enter': () => { dlg.querySelector('form').requestSubmit(); return true; },
                        // Escape במנוע = "בחירת הבלוק" (והמקש נבלע) — כאן הוא סוגר את החלון, כמו בכל חלון אחר
                        Escape: () => { dlg.close(); return true; },
                    }) }),
                ],
                content: { type: 'doc', content: [{ type: 'paragraph', content: inline }] },
                // autofocus: הדפדפן ממקד את התיבה כחלק מפתיחת החלון (showModal) — אותיות שמוקלדות מיד לא הולכות לאיבוד
                editorProps: { attributes: { class: 'fn-edit-doc', dir: 'rtl', lang: 'he', spellcheck: 'true', role: 'textbox', 'aria-multiline': 'false', 'aria-label': end ? 'הטקסט שיופיע בסוף המסמך' : 'הטקסט שיופיע בתחתית העמוד', autofocus: 'true' } },
            });
            const sync = () => tools.querySelectorAll('[data-mark]').forEach(b => b.classList.toggle('on', mini.isActive(b.dataset.mark)));
            mini.on('transaction', sync);
            dlg.addEventListener('close', () => mini.destroy(), { once: true });
        }, () => {
            const { text, content } = noteFromMini(mini.getJSON());
            if (!node) {
                if (!text) return;
                ed.chain().focus().setTextSelection(sel.to).insertFootnote(text, content, end).run();
                // סגירת החלון מחזירה את המיקוד למה שהיה לפניו (לפעמים הסמן קופץ לפני ההערה) — מחזירים אחרי ההערה
                const after = sel.to + 1;
                setTimeout(() => ed.chain().focus().setTextSelection(Math.min(after, ed.state.doc.content.size)).run(), 0);
            } else if (!text) {
                ed.chain().focus().command(({ tr }) => { tr.delete(pos, pos + node.nodeSize); return true; }).run();
            } else {
                ed.chain().focus().command(({ tr }) => { tr.setNodeMarkup(pos, null, { ...node.attrs, text, content }); return true; }).run();
            }
        }, 'שמירה');
        // הסמן בסוף הטקסט (המיקוד עצמו — autofocus)
        mini.commands.setTextSelection(mini.state.doc.content.size);
    }
    // לחיצה על סימן הערה או על ההערה בתחתית העמוד — עריכה
    host.addEventListener('click', e => {
        const ref = e.target.closest?.('.wp-fn-ref, .wp-fn-area .wp-fn-note, .wp-en-area .wp-fn-note');
        if (!ref) return;
        let pos = ref.dataset.pos != null ? +ref.dataset.pos : null;
        // הערה בתחתית העמוד: הסימן ה-n במסמך (כמו המספור בעימוד — בלי העותקים שבשורת כותרת חוזרת / באזורים)
        if (ref.closest('.wp-fn-area')) {
            const own = [...editor.view.dom.querySelectorAll('.wp-fn-ref:not(.wp-en-ref)')].filter(el => !el.closest('.wp-repeat, .wp-fn-area, .wp-en-area'));
            const el = own[+ref.dataset.n - 1];
            pos = null;
            if (el) try { pos = editor.view.posAtDOM(el, 0); } catch { /* */ }
            if (pos == null) return;
        }
        if (pos == null) { try { pos = editor.view.posAtDOM(ref, 0); } catch { return; } }
        e.preventDefault();
        footnoteDialog(pos);
    });

    /**
     * חלון התמונה: גודל מדויק בס"מ (של מה שרואים — אחרי חיתוך וסיבוב; "נעילת יחס" כמו ב-Word), הזזה מדויקת לתמונה
     * מאחורי / לפני הטקסט, כיתוב, מרחק מהטקסט ותיאור. 1px = 2.54/96 ס"מ.
     */
    function imageDialog(pos) {
        const a = ed.state.doc.nodeAt(pos)?.attrs;
        if (!a) return;
        const img = editor.view.nodeDOM(pos)?.querySelector?.('img');
        const g = imageBox(a, img?.naturalWidth ? { w: img.naturalWidth, h: img.naturalHeight } : null);
        const CM = 2.54 / 96;
        const cm = px => Math.round(px * CM * 100) / 100;
        const free = a.wrap === 'behind' || a.wrap === 'front';
        const wIn = numInput('bw', cm(g.bw), { min: 0.5, max: 50, step: 0.01 });
        const hIn = numInput('bh', cm(g.bh), { min: 0.5, max: 50, step: 0.01 });
        const lock = h('input', { type: 'checkbox', name: 'lock', checked: true });
        // נעילת יחס: שינוי אחד מהמידות מעדכן את השנייה מיד
        wIn.addEventListener('input', () => { if (lock.checked && +wIn.value > 0) hIn.value = Math.round(+wIn.value * g.bh / g.bw * 100) / 100; });
        hIn.addEventListener('input', () => { if (lock.checked && +hIn.value > 0) wIn.value = Math.round(+hIn.value * g.bw / g.bh * 100) / 100; });
        sheet('תמונה', body => {
            body.append(
                h('section.group', h('h3', 'גודל ', h('small', 'בס"מ')),
                    h('div.fields.two', field('רוחב', wIn), field('גובה', hIn)),
                    h('label.switch', lock, h('span', 'נעילת יחס גובה-רוחב'))),
                // מיקום: מהפסקה (זזה עם הטקסט) או מהפינה השמאלית העליונה של העמוד (נשארת במקום — כמו "יחסית לעמוד" ב-Word)
                free ? h('section.group', h('h3', 'מיקום ', h('small', 'בס"מ')),
                    seg('anchor', { para: 'יחסית לפסקה (זזה עם הטקסט)', page: 'יחסית לעמוד (נשארת במקום)' }, a.anchor === 'page' ? 'page' : 'para', 'עיגון'),
                    h('div.fields.two', field('אופקי (שמאלה — שלילי)', numInput('px', cm(a.x || 0), { min: -50, max: 50, step: 0.01 })),
                        field('אנכי (למטה — חיובי)', numInput('py', cm(a.y || 0), { min: -50, max: 50, step: 0.01 })))) : null,
                field('כיתוב מתחת לתמונה', h('input', { name: 'caption', value: a.caption || '', maxlength: 300 })),
                field('מרחק מהטקסט (נק\')', numInput('dist', a.dist ?? '', { min: 0, max: 72, placeholder: '9' })),
                field('תיאור לקוראי מסך', h('input', { name: 'alt', value: a.alt || '', maxlength: 300 })));
        }, form => {
            const f = new FormData(form);
            const d = f.get('dist');
            const attrs = { caption: String(f.get('caption')).trim(), alt: String(f.get('alt')).trim(), dist: d === '' ? null : Math.max(0, Math.min(72, +d)) };
            // הגודל שרואים → רוחב וגובה של התמונה המלאה (לפני חיתוך; בסיבוב של 90° הצירים מתחלפים)
            const bw = Math.max(0.5, Math.min(50, +f.get('bw') || cm(g.bw))) / CM, bh = Math.max(0.5, Math.min(50, +f.get('bh') || cm(g.bh))) / CM;
            if (Math.abs(bw - g.bw) > 0.5 || Math.abs(bh - g.bh) > 0.5) {
                const turned = (a.rotate || 0) % 180 !== 0;
                const sx = bw / g.bw, sy = bh / g.bh;
                attrs.width = Math.round(g.w * (turned ? sy : sx));
                attrs.height = Math.round(g.h * (turned ? sx : sy));
            }
            if (free) {
                attrs.x = Math.round(+f.get('px') / CM) || 0; attrs.y = Math.round(+f.get('py') / CM) || 0;
                const anchor = f.get('anchor') === 'page' ? 'page' : null;
                // עיגון אחר והמיקום לא נגעו בו — התמונה נשארת באותו מקום על הדף (ההפרש: המקום של הפסקה בעמוד)
                if (anchor !== (a.anchor || null) && attrs.x === (a.x || 0) && attrs.y === (a.y || 0)) {
                    const el = ed.view.nodeDOM(pos);
                    const pl = el?.offsetParent, frame = pl && [...pagesLayer.children].find(fr => { const r = fr.getBoundingClientRect(); const t = pl.getBoundingClientRect().top; return t >= r.top - 1 && t < r.bottom; });
                    if (pl && frame) {
                        const fr = frame.getBoundingClientRect(), pr = pl.getBoundingClientRect(), z = fr.width / frame.offsetWidth || 1;
                        const ox = (pr.left - fr.left) / z + pl.clientLeft, oy = (pr.top - fr.top) / z + pl.clientTop;
                        const k = anchor === 'page' ? 1 : -1;
                        attrs.x = Math.round(attrs.x + k * ox); attrs.y = Math.round(attrs.y + k * oy);
                    }
                }
                attrs.anchor = anchor;
            }
            ed.chain().focus().setNodeSelection(pos).updateAttributes('image', attrs).setNodeSelection(pos).run();
        });
    }

    // ---------- העתקת עיצוב: לחיצה — פעם אחת; לחיצה כפולה — עד Esc או לחיצה נוספת ----------
    let painter = null;
    function startPainter(sticky) {
        const { $from } = ed.state.selection;
        const p = $from.parent;
        const block = {};
        for (const k of ['textAlign', 'lineHeight', 'spaceBefore', 'spaceAfter', 'indent', 'dir']) if (k in (p.attrs || {})) block[k] = p.attrs[k];
        painter = { marks: (ed.state.storedMarks || $from.marks()).map(m => ({ type: m.type.name, attrs: m.attrs })), block, sticky };
        B.painter.classList.add('on');
        ed.view.dom.classList.add('painting');
        toast(sticky ? 'העתקת עיצוב — סמנו טקסט (Esc לסיום)' : 'העתקת עיצוב — סמנו את הטקסט');
    }
    function stopPainter() {
        painter = null;
        B.painter.classList.remove('on');
        ed.view.dom.classList.remove('painting');
    }
    function applyPainter() {
        if (!painter) return;
        let { from, to, empty } = ed.state.selection;
        if (empty) {   // לחיצה בתוך מילה — המילה כולה, כמו ב-Word
            const $p = ed.state.selection.$from;
            const text = $p.parent.textContent;
            let s0 = $p.parentOffset, e0 = $p.parentOffset;
            while (s0 > 0 && /\S/.test(text[s0 - 1])) s0--;
            while (e0 < text.length && /\S/.test(text[e0])) e0++;
            if (s0 === e0) return;
            from = $p.start() + s0; to = $p.start() + e0;
        }
        let c = ed.chain().setTextSelection({ from, to }).unsetAllMarks();
        for (const m of painter.marks) c = c.setMark(m.type, m.attrs);
        c.setBlockAttrs(painter.block).run();
        if (!painter.sticky) stopPainter();
    }
    editor.view.dom.addEventListener('mouseup', () => { if (painter) setTimeout(applyPainter, 0); });
    editor.view.dom.addEventListener('touchend', () => { if (painter) setTimeout(applyPainter, 0); });
    editor.view.dom.addEventListener('keydown', e => { if (e.key === 'Escape' && painter) stopPainter(); });

    function paragraphDialog() {
        const node = ed.state.selection.$from.parent;
        const a = node.attrs || {};
        const exact = /^exact:([\d.]+)$/.exec(a.lineHeight || '');
        // עצירות טאב (כמו "טאבים" ב-Word): מיקום בס"מ מהשוליים בצד ההתחלה, יישור, מוביל — שורה לכל עצירה
        const CM = 72 / 2.54;
        const tabList = h('div.tab-list');
        const tabRow = (t = {}) => {
            const row = h('div.tab-row',
                numInput('tab_pos', t.pos ? Math.round(t.pos / CM * 100) / 100 : '', { type: 'text', pattern: '[0-9]+([.,][0-9]+)?', 'aria-label': 'מיקום (ס"מ)', placeholder: 'ס"מ' }),
                h('select', { name: 'tab_align', 'aria-label': 'יישור' }, Object.entries(TAB_ALIGNS).map(([k, v]) => h('option', { value: k, selected: k === (t.align || 'start') }, v))),
                h('select', { name: 'tab_leader', 'aria-label': 'מוביל' }, Object.entries(TAB_LEADERS).map(([k, v]) => h('option', { value: k, selected: k === (t.leader || 'none') }, v))),
                h('button.icon-btn', { type: 'button', 'aria-label': 'מחיקת העצירה', onclick: () => row.remove() }, '✕'));
            tabList.append(row);
            return row;
        };
        tabsOf(a.tabs).forEach(tabRow);
        sheet('פסקה', body => {
            body.append(
                h('section.group', h('h3', 'כיוון'), seg('dir', { rtl: 'מימין לשמאל', ltr: 'משמאל לימין' }, a.dir || 'rtl', 'כיוון')),
                h('section.group', h('h3', 'ריווח שורות'),
                    seg('line', { '': 'רגיל', 1: '1', 1.15: '1.15', 1.5: '1.5', 2: '2', exact: 'מדויק' }, exact ? 'exact' : (a.lineHeight || ''), 'ריווח שורות'),
                    field('מדויק (נקודות)', numInput('exact', exact ? exact[1] : '', { min: 4, max: 200 }))),
                h('section.group', h('h3', 'ריווח והזחה ', h('small', 'בנקודות')),
                    h('div.fields.two',
                        field('לפני הפסקה', numInput('before', a.spaceBefore, { min: 0, max: 200 })),
                        field('אחרי הפסקה', numInput('after', a.spaceAfter, { min: 0, max: 200 })),
                        field('הזחה (צעדים של 1.27 ס"מ)', numInput('indent', a.indent || 0, { min: 0, max: 8, step: 1 })),
                        field('שורה ראשונה', h('select', { name: 'flk' }, Object.entries({ '': 'ללא', first: 'הזחה', hang: 'תלויה' })
                            .map(([k, t]) => h('option', { value: k, selected: k === (a.firstLine > 0 ? 'first' : a.firstLine < 0 ? 'hang' : '') }, t)))),
                        field('בכמה (נקודות)', numInput('flv', a.firstLine ? Math.abs(a.firstLine) : 36, { min: 0, max: 200 })))),
                // כמו "שורות ומעברי עמוד" ב-Word. כותרת — ממילא נשמרת עם הבא ובשלמותה
                h('section.group', h('h3', 'שבירת עמודים'),
                    sw('keepNext', 'שמירה עם הבא — לא נשארת לבד בתחתית העמוד', a.keepNext),
                    sw('keepLines', 'שמירת שורות יחד — לא נחצית בין עמודים', a.keepLines),
                    sw('breakBefore', 'מעבר עמוד לפני — מתחילה עמוד חדש', a.breakBefore)),
                h('section.group', h('h3', 'עצירות טאב ', h('small', 'ס"מ מהשוליים')), tabList,
                    h('button.btn', { type: 'button', onclick: () => tabRow().querySelector('input').focus() }, '+ עצירה'),
                    h('p.hint', 'מוביל — נקודות / מקפים / קו עד העצירה, כמו בתוכן עניינים. אחרי העצירה האחרונה — כל 1.27 ס"מ.')));
        }, form => {
            const f = new FormData(form);
            const line = f.get('line');
            const ex = parseFloat(f.get('exact'));
            const n = k => f.get(k) === '' ? null : Math.max(0, +f.get(k));
            chain().setBlockAttrs({
                dir: f.get('dir') === 'ltr' ? 'ltr' : null,
                lineHeight: line === 'exact' ? (ex > 0 ? 'exact:' + ex : null) : (line || null),
                spaceBefore: n('before'), spaceAfter: n('after'), indent: Math.min(8, Math.round(n('indent') || 0)),
                firstLine: f.get('flk') && n('flv') ? Math.min(200, n('flv')) * (f.get('flk') === 'hang' ? -1 : 1) : null,
                keepNext: f.get('keepNext') ? true : null, keepLines: f.get('keepLines') ? true : null, breakBefore: f.get('breakBefore') ? true : null,
                tabs: (t => t.length ? t : null)(tabsOf(f.getAll('tab_pos').map((p, i) => ({ pos: parseFloat(String(p).replace(',', '.')) * CM,
                    align: f.getAll('tab_align')[i], leader: f.getAll('tab_leader')[i] })))),
            }).run();
        });
    }

    /**
     * קישור (Ctrl+K): טקסט לתצוגה + כתובת (אתר / דוא"ל) או סימניה במסמך. בתוך קישור קיים — עריכה של כולו, ו"הסרת
     * הקישור". הטקסט שהשתנה מחליף את הבחירה עם העיצוב שבתחילתה.
     */
    function linkDialog() {
        const cur = editor.getAttributes('link').href || '';
        if (cur) chain().extendMarkRange('link').run();
        const { from, to, empty } = editor.state.selection;
        const text = empty ? '' : editor.state.doc.textBetween(from, to, ' ', '\ufffc');
        const plain = !text.includes('\ufffc');   // בבחירה תמונה / הערה — בלי שדה טקסט, רק כתובת
        const bms = bookmarks(editor.state.doc).filter(b => !b.name.startsWith('_'));
        sheet(cur ? 'עריכת קישור' : 'הוספת קישור', (body, dlg) => {
            body.append(
                plain ? field('טקסט לתצוגה', h('input', { name: 'text', value: text, maxlength: 500, autocomplete: 'off' })) : null,
                field('כתובת — אתר או דוא"ל', h('input', { name: 'href', type: 'text', dir: 'ltr', value: cur.startsWith('#') ? '' : cur.replace(/^mailto:/, ''),
                    placeholder: 'https://…', inputmode: 'url', autocomplete: 'off', spellcheck: 'false' })),
                bms.length ? field('או מקום במסמך (סימניה)', h('select', { name: 'bm' }, h('option', { value: '' }, '—'),
                    ...bms.map(b => h('option', { value: b.name, selected: cur === '#' + b.name }, b.name)))) : null,
                cur ? h('button.btn.ghost', { type: 'button', onclick: () => { chain().unsetLink().run(); dlg.close(); } }, 'הסרת הקישור') : null);
            setTimeout(() => body.querySelector(plain && !text ? '[name=text]' : '[name=href]')?.focus());
        }, form => {
            const f = new FormData(form);
            const href = safeHref(f.get('bm') ? '#' + f.get('bm') : f.get('href'));
            if (!href) throw new Error('כתובת לא תקינה — אתר (https://…), דוא"ל, או סימניה מהרשימה');
            const t = plain ? String(f.get('text') || '').trim() : '';
            if (plain && (empty || t !== text)) {
                const marks = editor.state.doc.resolve(from).marks().filter(m => m.type.name !== 'link').map(m => m.toJSON());
                const shown = t || href.replace(/^mailto:/, '').replace(/^#/, '');
                chain().insertContentAt({ from, to }, { type: 'text', text: shown, marks: [...marks, { type: 'link', attrs: { href } }] })
                    .setTextSelection(from + shown.length).unsetMark('link').run();
            } else chain().setTextSelection({ from, to }).setMark('link', { href }).run();
        }, 'אישור');
    }

    /**
     * A+ / A−: כל קטע בבחירה עולה / יורד לגודל הבא ברשימה (SIZE_LIST), לפי הגודל שלו עכשיו — העיצוב הישיר, או הגודל
     * של סגנון הפסקה. בלי בחירה — הגודל להקלדה.
     */
    function stepFont(dir) {
        const { state } = editor;
        const ts = editor.schema.marks.textStyle;
        const styleSize = block => {
            const k = ['paragraph', 'heading'].includes(block?.type.name) ? styleKeyOf(block) : 'normal';
            return page.styles[k]?.size || (USER_STYLE.test(k) ? page.styles.normal.size : null) || page.font.size;
        };
        const next = sz => dir > 0 ? (SIZE_LIST.find(x => x > sz) ?? Math.min(400, sz + 10)) : ([...SIZE_LIST].reverse().find(x => x < sz) ?? Math.max(1, sz - 1));
        const { from, to, empty, $from } = state.selection;
        if (empty) return chain().setFontSize(next(parseFloat(editor.getAttributes('textStyle').fontSize) || styleSize($from.parent)) + 'pt').run();
        const tr = state.tr;
        state.doc.nodesBetween(from, to, (n, pos, parent) => {
            if (!n.isText) return;
            const old = n.marks.find(m => m.type === ts);
            const cur = parseFloat(old?.attrs.fontSize) || styleSize(parent);
            tr.addMark(Math.max(from, pos), Math.min(to, pos + n.nodeSize), ts.create({ ...(old?.attrs || {}), fontSize: next(cur) + 'pt' }));
        });
        editor.view.dispatch(tr);
        editor.view.focus();
        return true;
    }

    /** ספירת מילים: במסמך, ובבחירה (אם יש) — כמו החלון של Word. הערות השוליים לא נספרות. */
    function wordCountDialog() {
        const all = wordStats(editor.state.doc);
        const { from, to, empty } = editor.state.selection;
        const sel = empty ? null : wordStats(editor.state.doc, from, to);
        const n = v => v.toLocaleString('he-IL');
        const rows = [['עמודים', pageCount, null], ['מילים', all.words, sel?.words], ['תווים (בלי רווחים)', all.charsNoSpaces, sel?.charsNoSpaces],
            ['תווים (כולל רווחים)', all.chars, sel?.chars], ['פסקאות', all.paragraphs, sel?.paragraphs]];
        sheet('ספירת מילים', body => {
            body.append(h('table.wc-table', h('thead', h('tr', h('th', ''), h('th', 'במסמך'), sel ? h('th', 'בבחירה') : null)),
                h('tbody', rows.map(([k, a, b]) => h('tr', h('th', { scope: 'row' }, k), h('td', n(a)), sel ? h('td', b == null ? '' : n(b)) : null)))),
                h('p.hint', 'הטקסט שבהערות השוליים לא נספר.'));
        }, null);
    }

    /** קביעת ערך המספור: הרשימה שבה הסמן מתחילה במספר הזה (ולא ממשיכה את הקודמת). */
    function numberingDialog() {
        const cur = editor.getAttributes('orderedList').start ?? 1;
        sheet('קביעת ערך המספור', body => {
            body.append(field('להתחיל ב-', numInput('start', cur, { min: 0, max: 9999, step: 1 })));
        }, form => {
            const n = parseInt(new FormData(form).get('start'), 10);
            if (!(n >= 0 && n <= 9999)) throw new Error('מספר בין 0 ל-9999');
            chain().setListNumbering({ start: n, cont: false }).run();
        }, 'אישור');
    }

    /** סימניות: הוספה במקום הסמן (שם קיים — עובר לכאן), ורשימה עם מעבר ומחיקה. */
    /**
     * הוספת כיתוב (כמו ב-Word): פסקה בסגנון "כיתוב" — התווית, המספר (seq) והטקסט — מתחת לפריט שבו הסמן או מעליו
     * (בטבלה — מעליה כברירת מחדל, כמו ב-Word העברי).
     */
    function captionDialog() {
        const sel = editor.state.selection;
        const inTable = editor.isActive('table') || sel.node?.type.name === 'table';
        const onImage = sel.node?.type.name === 'image' || editor.isActive('image');
        sheet('הוספת כיתוב', body => {
            body.append(
                seg('label', Object.fromEntries(SEQ_LABELS.map(l => [l, l])), inTable ? 'טבלה' : SEQ_LABELS[0], 'תווית'),
                field('טקסט הכיתוב (אחרי המספר)', h('input', { name: 'text', maxlength: 200, autocomplete: 'off', placeholder: onImage || !inTable ? 'למשל: מבנה המערכת' : '' })),
                seg('pos', { below: 'מתחת לפריט', above: 'מעל הפריט' }, inTable ? 'above' : 'below', 'מיקום'));
        }, form => {
            const f = new FormData(form);
            const label = SEQ_LABELS.includes(f.get('label')) ? f.get('label') : SEQ_LABELS[0];
            const text = String(f.get('text') || '').trim();
            const { state } = editor;
            const $f = state.selection.$from;
            // הבלוק העליון שבו הסמן (או הבלוק העליון שנבחר כולו — טבלה, תוכן עניינים)
            const [from, to] = $f.depth ? [$f.before(1), $f.after(1)] : [state.selection.from, state.selection.to];
            const at = f.get('pos') === 'above' ? from : to;
            const content = [{ type: 'text', text: label + ' ' }, { type: 'seq', attrs: { label, id: newSeqId() } }, ...(text ? [{ type: 'text', text: ': ' + text }] : [])];
            chain().insertContentAt(at, { type: 'paragraph', attrs: { pstyle: 'caption' }, content })
                .command(({ tr }) => { tr.setSelection(TextSelection.near(tr.doc.resolve(tr.doc.resolve(at + 1).end()))); return true; }).run();
        }, 'הוספה');
    }

    /** הפניה צולבת לכיתוב: "איור 2" או "2" — מתעדכנת לבד כשהמספרים משתנים. */
    function crossRefDialog() {
        const list = captions(editor.state.doc);
        sheet('הפניה צולבת', body => {
            if (!list.length) { body.append(h('p.none', 'אין עדיין כיתובים במסמך — הוספה ← כיתובים ← הוספת כיתוב')); return; }
            body.append(
                field('הפניה אל', h('select', { name: 'target' }, ...list.map(c => h('option', { value: c.id }, (c.text || `${c.label} ${c.n}`).slice(0, 80))))),
                seg('show', { full: 'תווית ומספר', num: 'מספר בלבד' }, 'full', 'להציג'));
        }, form => {
            if (!list.length) return;
            const f = new FormData(form);
            chain().insertContent({ type: 'xref', attrs: { target: String(f.get('target')), show: f.get('show') === 'num' ? 'num' : 'full' } }).run();
        }, 'הוספה');
    }

    function bookmarkDialog() {
        sheet('סימניה', (body, dlg) => {
            const ul = h('ul.bm-list');
            const paint = () => {
                ul.textContent = '';
                for (const b of bookmarks(editor.state.doc).filter(x => !x.name.startsWith('_'))) {
                    ul.append(h('li', h('span.bm-name', b.name),
                        h('button.btn.sm', { type: 'button', onclick: () => { dlg.close(); editor.commands.goToBookmark(b.name); editor.view.focus(); } }, 'מעבר'),
                        h('button.btn.sm.ghost', { type: 'button', 'aria-label': 'מחיקת ' + b.name, onclick: () => { editor.commands.deleteBookmark(b.name); paint(); } }, 'מחיקה')));
                }
                if (!ul.children.length) ul.append(h('li.none', 'אין עדיין סימניות במסמך'));
            };
            paint();
            body.append(field('שם — מתחיל באות, בלי רווחים', h('input', { name: 'name', maxlength: 40, autocomplete: 'off', spellcheck: 'false' })),
                h('section.group', h('h3', 'הסימניות במסמך'), ul));
        }, form => {
            const n = String(new FormData(form).get('name') || '').trim();
            if (!BOOKMARK_RE.test(n)) throw new Error('שם לא תקין: מתחיל באות, אותיות / ספרות / _ בלבד, עד 40 תווים');
            chain().setBookmark(n).run();
        }, 'הוספה');
    }

    function tableDialog() {
        sheet('הוספת טבלה', body => {
            body.append(h('div.fields.two', field('שורות', numInput('rows', 3, { min: 1, max: 100, step: 1 })), field('עמודות', numInput('cols', 3, { min: 1, max: 20, step: 1 }))),
                sw('header', 'שורת כותרת', true));
        }, form => {
            const f = new FormData(form);
            chain().insertTable({ rows: Math.max(1, Math.min(100, +f.get('rows') || 3)), cols: Math.max(1, Math.min(20, +f.get('cols') || 3)), withHeaderRow: !!f.get('header') }).run();
        }, 'הוספה');
    }

    /**
     * הגדרות עמוד. במסמך עם מקטעים — לפי המקטע שבו הסמן (כמו ב-Word): גודל, כיוון, שוליים וכותרות — "המקטע
     * הזה" או "כל המסמך"; הגופן והמספור — תמיד לכל המסמך.
     */
    /**
     * עורך קטן לכותרת (עליונה / תחתונה): מודגש, נטוי, קו, גודל, יישור, ותמונה (סמליל) — כמו "עריכת כותרת" ב-Word.
     * value — טקסט פשוט או פסקאות; get() מחזיר טקסט פשוט כשאין בו עיצוב (כך הוא נשמר ונכתב ל-Word כמו קודם).
     */
    function hfEditor(label, value) {
        const box = h('div.hf-edit');
        const blocks = Array.isArray(value) ? value : String(value || '').split('\n').filter((l, i, a) => l || a.length === 1)
            .map(l => ({ type: 'paragraph', attrs: { textAlign: 'center' }, content: l ? [{ type: 'text', text: l }] : [] }));
        let mini = null;
        const run = f => () => f(mini.chain().focus()).run();
        const tool = (title, icon, f, mark) => h('button.fn-tool', { type: 'button', title, 'aria-label': title, 'data-mark': mark || '', onmousedown: e => e.preventDefault(), onclick: run(f) }, icon);
        const sizeSel = h('select.hf-size', { 'aria-label': 'גודל', onchange: e => { mini.chain().focus().setFontSize(e.target.value ? e.target.value + 'pt' : null).run(); } },
            h('option', { value: '' }, 'גודל'), [8, 9, 10, 11, 12, 14, 16, 18, 24].map(v => h('option', { value: v }, String(v))));
        const pick = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', hidden: true, onchange: async e => {
            const f = e.target.files[0];
            e.target.value = '';
            if (!f || !opts.upload) return;
            try {
                const m = await opts.upload(f);
                // סמליל: עד 2.5 ס"מ גובה (בכותרת אין מקום ליותר)
                const hh = Math.min(m.height || 60, 2.5 * 10 * MM), w = m.height ? Math.round(hh * m.width / m.height) : 120;
                mini.chain().focus().insertContent({ type: 'image', attrs: { media: m.id, src: m.url, width: w, height: Math.round(hh) } }).run();
            } catch (err) { toast(err.message || 'העלאה נכשלה', true); }
        } });
        const tools = h('div.fn-tools', { role: 'toolbar', 'aria-label': 'עיצוב ' + label },
            tool('מודגש', h('b', 'B'), c => c.toggleBold(), 'bold'), tool('נטוי', h('i', 'I'), c => c.toggleItalic(), 'italic'),
            tool('קו תחתון', h('u', 'U'), c => c.toggleUnderline(), 'underline'), sizeSel,
            tool('יישור לימין', '⇥', c => c.setTextAlign('right')), tool('מרכז', '≡', c => c.setTextAlign('center')), tool('יישור לשמאל', '⇤', c => c.setTextAlign('left')),
            opts.upload ? tool('תמונה (סמליל)', '🖼', c => { pick.click(); return c; }) : null, pick,
            // טבלה (כמו בכותרת של Word — סמליל בצד אחד, פרטים בצד השני): שני תאים בלי גבולות; עמודה נוספת; מחיקה
            tool('טבלה בכותרת (שני תאים, בלי גבולות)', '⊞', c => c.insertTable({ rows: 1, cols: 2, withHeaderRow: false }).updateAttributes('table', { borders: 'none' })),
            tool('עמודה נוספת בטבלה', '⊞+', c => c.addColumnAfter()), tool('מחיקת הטבלה', '⊟', c => c.deleteTable()));
        const el = h('div.f.hf-field', h('span', label), tools, box);
        // תמונה בשורה — בלי גלישה וידיות (כותרת קטנה)
        const HfImage = TNode.create({
            name: 'image', inline: true, group: 'inline', atom: true, draggable: false,
            addAttributes: () => ({ media: { default: null }, src: { default: null }, width: { default: null }, height: { default: null }, wrap: { default: 'inline' } }),
            parseHTML: () => [{ tag: 'img[data-media]' }],
            renderHTML: ({ HTMLAttributes: a }) => ['img', { src: a.src, width: a.width, height: a.height, 'data-media': a.media, alt: '' }],
        });
        mini = new Editor({
            element: box,
            extensions: [
                StarterKit.configure({ heading: false, bulletList: false, orderedList: false, listItem: false, listKeymap: false, blockquote: false,
                    code: false, codeBlock: false, horizontalRule: false, link: false, trailingNode: false, dropcursor: false, gapcursor: false }),
                TextStyle, FontSize, TextAlign.configure({ types: ['paragraph'], alignments: ['right', 'center', 'left'], defaultAlignment: null }), HfImage,
                WpTable.configure({ resizable: false }), WpTableRow, Cell, HeaderCell,
            ],
            content: { type: 'doc', content: blocks.length ? blocks : [{ type: 'paragraph', attrs: { textAlign: 'center' } }] },
            editorProps: { attributes: { class: 'fn-edit-doc hf-edit-doc', dir: 'rtl', lang: 'he', role: 'textbox', 'aria-multiline': 'true', 'aria-label': label } },
        });
        mini.on('transaction', () => tools.querySelectorAll('[data-mark]').forEach(b => b.dataset.mark && b.classList.toggle('on', mini.isActive(b.dataset.mark))));
        return {
            el,
            destroy: () => mini.destroy(),
            get() {
                const ps = (mini.getJSON().content || []).filter(b => b.type === 'paragraph' || b.type === 'table');
                const plain = ps.every(b => b.type === 'paragraph' && (b.attrs?.textAlign || 'center') === 'center' && (b.content || []).every(n => n.type === 'text' && !n.marks?.length));
                if (plain) return ps.map(b => (b.content || []).map(n => n.text).join('')).join('\n').trim();
                // טבלה — כמו שהיא (השרת מנקה: hf_rich_val)
                return ps.map(b => b.type === 'table' ? b : { ...b, attrs: b.attrs?.textAlign ? { textAlign: b.attrs.textAlign } : undefined });
            },
        };
    }

    function pageDialog() {
        const secs = sections();
        const at = editor.state.selection.from;
        const k = secs.reduce((acc, x, i) => (i && x.from < at ? i : acc), 0);
        const p = secs[k].page;
        const nb = page.numbering;
        const wmInput = h('input', { name: 'watermark', value: page.watermark || '', maxlength: 40 });
        const hfe = { header: hfEditor('כותרת עליונה', p.header), footer: hfEditor('כותרת תחתונה', p.footer),
            header_even: hfEditor('עליונה — עמוד זוגי', p.header_even), footer_even: hfEditor('תחתונה — עמוד זוגי', p.footer_even),
            header_first: hfEditor('עליונה — עמוד ראשון', p.header_first), footer_first: hfEditor('תחתונה — עמוד ראשון', p.footer_first) };
        sheet('הגדרות עמוד', (body, dlg) => {
            dlg?.addEventListener('close', () => Object.values(hfe).forEach(x => x.destroy()), { once: true });
            body.append(
                secs.length > 1 ? h('section.group', h('h3', 'להחיל על'),
                    seg('scope', { section: `המקטע הזה (${k + 1} מתוך ${secs.length})`, all: 'כל המסמך' }, 'section', 'להחיל על')) : null,
                h('section.group', h('h3', 'נייר'),
                    seg('size', { A4: 'A4', Letter: 'Letter' }, p.size, 'גודל'),
                    seg('orientation', { portrait: 'לאורך', landscape: 'לרוחב' }, p.orientation, 'כיוון')),
                // טורים (כמו "פריסה ← טורים" ב-Word): בהדפסה וב-PDF זה לצד זה; בעורך — תצוגת טיוטה, טור במסגרת
                h('section.group', h('h3', 'טורים'),
                    seg('cols_n', { 1: 'אחד', 2: 'שניים', 3: 'שלושה' }, String(p.columns?.n || 1), 'מספר טורים'),
                    h('div.fields.two', field('רווח בין טורים (מ"מ)', numInput('cols_gap', p.columns?.gap ?? 12.7, { min: 0, max: 50 })),
                        h('div', sw('cols_line', 'קו מפריד', !!p.columns?.line))),
                    h('p.hint', 'בהדפסה וב-PDF הטורים זה לצד זה. בעורך כל טור מוצג במסגרת משלו ("עמוד 1 · טור 2"), ברוחב הטור.')),
                h('section.group', h('h3', 'שוליים ', h('small', 'במ"מ')),
                    h('div.fields.two',
                        field('למעלה', numInput('m_top', p.margins.top, { min: 0, max: 80 })), field('למטה', numInput('m_bottom', p.margins.bottom, { min: 0, max: 80 })),
                        field('ימין', numInput('m_right', p.margins.right, { min: 0, max: 80 })), field('שמאל', numInput('m_left', p.margins.left, { min: 0, max: 80 })))),
                h('section.group', h('h3', 'גופן המסמך'),
                    h('div.fields.two', field('גופן', fontSelect('font', page.font.family)), field('גודל', numInput('fsize', page.font.size, { min: 6, max: 72 })))),
                h('section.group', h('h3', 'מספור עמודים'),
                    sw('n_show', 'מספרי עמודים', nb.show),
                    seg('n_pos', { top: 'למעלה', bottom: 'למטה' }, nb.position, 'מיקום'),
                    seg('n_align', { right: 'ימין', center: 'מרכז', left: 'שמאל' }, nb.align, 'יישור'),
                    h('div.fields.two',
                        field('תבנית', h('select', { name: 'n_format' }, Object.entries(NUM_FORMATS).map(([k, t]) => h('option', { value: k, selected: k === nb.format }, t)))),
                        field('מתחיל מ-', numInput('n_start', nb.start, { min: 0, max: 9999, step: 1 }))),
                    sw('n_first', 'מספר גם בעמוד הראשון', nb.first_page)),
                h('section.group', h('h3', 'כותרת עליונה ותחתונה'),
                    hfe.header.el, hfe.footer.el,
                    // כמו "זוגי ואי-זוגי שונים" ב-Word — לכל המסמך; הטקסט — לכל מקטע
                    sw('even_odd', 'זוגי ואי-זוגי שונים (לכל המסמך)', page.even_odd),
                    hfe.header_even.el, hfe.footer_even.el,
                    h('p.hint', 'עמוד זוגי לפי מספר העמוד. שני השדות שלמעלה — לעמודים האי-זוגיים.'),
                    // כמו "עמוד ראשון שונה" ב-Word — של המקטע: בעמוד הראשון שלו (ריק — בלי כותרת)
                    sw('first_diff', secs.length > 1 ? 'עמוד ראשון שונה (במקטע)' : 'עמוד ראשון שונה', p.first_diff),
                    hfe.header_first.el, hfe.footer_first.el),
                // מקטע (לא הראשון): התחלת מספור העמודים מחדש — כמו "התחל ב-" בתבנית מספרי העמודים של Word
                k > 0 ? h('section.group', h('h3', 'מספור במקטע הזה'),
                    sw('num_restart', 'להתחיל את מספור העמודים מחדש', p.num_restart),
                    field('מתחיל מ-', numInput('num_start', p.num_start ?? 1, { min: 0, max: 9999, step: 1 }))) : null,
                // רקע הדף — לכל המסמך (כמו "עיצוב ← רקע הדף" ב-Word)
                h('section.group', h('h3', 'רקע הדף ', h('small', 'לכל המסמך')),
                    sw('pc_on', 'צבע עמוד', !!page.page_color),
                    field('הצבע', h('input', { type: 'color', name: 'page_color', value: page.page_color || '#fdf6e3' })),
                    field('סימן מים (טקסט באלכסון; ריק — בלי)', wmInput),
                    h('div.wm-presets', ['טיוטה', 'סודי', 'דחוף', 'עותק', 'לא להפצה'].map(v => h('button.btn.sm', { type: 'button', onclick: () => { wmInput.value = v; } }, v)))));
        }, form => {
            const f = new FormData(form);
            const n = k => +f.get(k);
            const sec = {
                size: f.get('size'), orientation: f.get('orientation'),
                margins: { top: n('m_top'), bottom: n('m_bottom'), right: n('m_right'), left: n('m_left') },
                header: hfe.header.get(), footer: hfe.footer.get(), header_even: hfe.header_even.get(), footer_even: hfe.footer_even.get(),
                first_diff: !!f.get('first_diff'), header_first: hfe.header_first.get(), footer_first: hfe.footer_first.get(),
                ...(k > 0 ? { num_restart: !!f.get('num_restart'), num_start: Math.max(0, Math.round(n('num_start') || 0)) } : {}),
                columns: { n: Math.max(1, Math.min(3, n('cols_n') || 1)), gap: Math.max(0, Math.min(50, n('cols_gap'))), line: !!f.get('cols_line') },
            };
            const whole = {
                font: { family: f.get('font'), size: n('fsize') || 12 },
                numbering: { show: !!f.get('n_show'), position: f.get('n_pos'), align: f.get('n_align'), format: f.get('n_format'),
                    start: Math.max(0, Math.round(n('n_start'))), first_page: !!f.get('n_first') },
                page_color: f.get('pc_on') && f.get('page_color') !== '#ffffff' ? String(f.get('page_color')).toLowerCase() : '',
                watermark: String(f.get('watermark') || '').trim().slice(0, 40),
                even_odd: !!f.get('even_odd'),
            };
            if (secs.length === 1) setPage({ ...sec, ...whole });
            else setSections(f.get('scope') === 'all' ? null : k, sec, whole);
            // המקלדת חוזרת למסמך (Ctrl+S / Ctrl+Z) — אחרי שהחלון נסגר: סגירה מחזירה את המיקוד לכפתור שפתח אותו
            requestAnimationFrame(() => editor.view.focus());
        });
    }

    /**
     * שינוי מקטע אחד (k) או כולם (null) — טרנזקציה אחת (ביטול אחד). כל מעבר מקטע נכתב מחדש עם כל ההגדרות
     * שלו במפורש, כדי ששינוי במקטע אחד לא "יזלוג" למקטעים שאחריו.
     */
    function setSections(k, sec, whole) {
        const pick = q => ({ size: q.size, orientation: q.orientation, margins: { ...q.margins }, header: q.header, footer: q.footer,
            header_even: q.header_even || '', footer_even: q.footer_even || '', num_restart: !!q.num_restart, num_start: q.num_start ?? 1,
            first_diff: !!q.first_diff, header_first: q.header_first || '', footer_first: q.footer_first || '',
            columns: { ...(q.columns || { n: 1, gap: 12.7, line: false }) } });
        const secs = sections();
        const tr = editor.state.tr;
        const first = mergePage(page, { ...(k === null || k === 0 ? sec : {}), ...whole });
        if (JSON.stringify(first) !== JSON.stringify(page)) tr.setDocAttribute('page', first);
        secs.forEach((x, i) => {
            if (!i) return;
            const want = k === null ? {} : pick(i === k ? mergePage(x.page, sec) : x.page);
            if (JSON.stringify(want) !== JSON.stringify(editor.state.doc.nodeAt(x.from).attrs.page || {})) tr.setNodeAttribute(x.from, 'page', want);
        });
        if (tr.docChanged || tr.steps.length) editor.view.dispatch(tr);
    }

    /**
     * העיצוב של הבחירה (כמו "סגנון חדש" / "עדכון לפי הבחירה" ב-Word): מהריצה הראשונה בפסקה — גופן, גודל, צבע,
     * מודגש / נטוי / קו תחתון; מהפסקה — יישור, ריווח לפני / אחרי ושורות. מחזיר גם את הבלוק ואת מיקומו.
     */
    function selectionFormat() {
        const $f = editor.state.selection.$from;
        const block = $f.parent;
        if (!['paragraph', 'heading'].includes(block.type.name)) return null;
        const pos = $f.before($f.depth);
        let first = null;
        block.forEach(c => { if (!first && c.isText) first = c; });
        const f = {};
        for (const m of first?.marks || []) {
            if (['bold', 'italic', 'underline'].includes(m.type.name)) f[m.type.name] = true;
            if (m.type.name === 'textStyle') {
                if (m.attrs.fontFamily) f.font = m.attrs.fontFamily;
                if (parseFloat(m.attrs.fontSize)) f.size = parseFloat(m.attrs.fontSize);
                if (m.attrs.color) f.color = m.attrs.color;
            }
        }
        const a = block.attrs;
        if (a.textAlign) f.align = a.textAlign;
        if (isFinite(parseFloat(a.spaceBefore))) f.before = +a.spaceBefore;
        if (isFinite(parseFloat(a.spaceAfter))) f.after = +a.spaceAfter;
        if (a.lineHeight && !String(a.lineHeight).startsWith('exact:')) f.line = +a.lineHeight;
        return { key: styleKeyOf(block), block, pos, f };
    }

    /**
     * עסקה אחת (ביטול אחד): הסגנונות החדשים, הפסקה בסגנון toKey (אם נמסר), ובלי העיצוב הישיר שנכנס לסגנון —
     * עיצוב שחל רק על חלק מהפסקה נשאר.
     */
    function applyStyleChange(sel, patch, toKey) {
        const { block, pos, f } = sel;
        const sc = editor.schema;
        const tr = editor.state.tr.setDocAttribute('page', mergePage(page, { styles: patch }));
        const attrs = { ...block.attrs };
        if (f.align) attrs.textAlign = null;
        if ('before' in f) attrs.spaceBefore = null;
        if ('after' in f) attrs.spaceAfter = null;
        if ('line' in f) attrs.lineHeight = null;
        if (toKey) { delete attrs.level; attrs.pstyle = toKey; tr.setNodeMarkup(pos, sc.nodes.paragraph, attrs); }
        else tr.setNodeMarkup(pos, undefined, attrs);
        const texts = [];
        block.forEach((c, off) => { if (c.isText) texts.push([c, pos + 1 + off]); });
        const everywhere = test => texts.length && texts.every(([c]) => test(c));
        for (const t of ['bold', 'italic', 'underline']) {
            if (f[t] && everywhere(c => c.marks.some(m => m.type.name === t))) tr.removeMark(pos + 1, pos + 1 + block.content.size, sc.marks[t]);
        }
        const tsAll = k => everywhere(c => c.marks.some(m => m.type.name === 'textStyle' && m.attrs[k] != null && String(m.attrs[k]) === String(texts[0][0].marks.find(x => x.type.name === 'textStyle')?.attrs[k])));
        const drop = { fontFamily: 'font' in f && tsAll('fontFamily'), fontSize: 'size' in f && tsAll('fontSize'), color: 'color' in f && tsAll('color') };
        if (Object.values(drop).some(Boolean)) for (const [c, p] of texts) {
            const ts = c.marks.find(m => m.type.name === 'textStyle');
            if (!ts) continue;
            const na = { ...ts.attrs };
            for (const k of Object.keys(drop)) if (drop[k]) na[k] = null;
            tr.removeMark(p, p + c.nodeSize, sc.marks.textStyle);
            if (Object.values(na).some(v => v != null)) tr.addMark(p, p + c.nodeSize, sc.marks.textStyle.create(na));
        }
        editor.view.dispatch(tr);
        editor.view.focus();
    }

    /** סגנון חדש מהבחירה: שם, והעיצוב של הפסקה (הסגנון שלה + העיצוב הישיר). הפסקה עוברת לסגנון החדש. */
    function newStyleDialog() {
        const sel = selectionFormat();
        if (!sel) return toast('הסמן צריך להיות בפסקה', true);
        sheet('סגנון חדש מהבחירה', body => {
            body.append(field('שם הסגנון', h('input', { name: 'name', maxlength: 40, autocomplete: 'off', value: '' })),
                h('p.hint', 'הסגנון יקבל את העיצוב של הפסקה שבה הסמן, והפסקה תעבור אליו.'));
            setTimeout(() => body.querySelector('[name=name]')?.focus());
        }, form => {
            const name = String(new FormData(form).get('name') || '').trim().slice(0, 40);
            if (!name) throw new Error('צריך שם לסגנון');
            if ([...Object.keys(STYLE_NAMES), ...userKeys()].some(k => styleName(k) === name)) throw new Error('כבר יש סגנון בשם הזה');
            let n = 1;
            while (page.styles['u' + n]) n++;
            const base = { ...(sel.key === 'normal' || USER_STYLE.test(sel.key) ? {} : page.styles[sel.key]), ...(USER_STYLE.test(sel.key) ? page.styles[sel.key] : {}) };
            delete base.name;
            const key = 'u' + n;
            applyStyleChange(sel, { [key]: { name, ...base, ...sel.f } }, key);
        }, 'יצירה');
    }

    /** עדכון הסגנון של הפסקה לפי העיצוב שלה (Word: "עדכון … כך שיתאים לבחירה"). */
    function updateStyleFromSelection() {
        const sel = selectionFormat();
        if (!sel) return;
        if (!Object.keys(sel.f).length) return toast(`אין בפסקה עיצוב שונה מהסגנון "${styleName(sel.key)}"`);
        applyStyleChange(sel, { [sel.key]: sel.f }, null);
        toast(`הסגנון "${styleName(sel.key)}" עודכן — כל הפסקאות בסגנון הזה השתנו`);
    }

    function stylesDialog() {
        let cur = styleSel.value || 'h1';
        const work = structuredClone(page.styles);
        let collect = () => {};
        sheet('עריכת סגנונות', (body, dlg) => {
            const area = h('div.group');
            const keys = [...Object.keys(STYLE_NAMES), ...userKeys()];
            const pick = field('סגנון', h('select', { name: 'which' }, keys.map(k => h('option', { value: k, selected: k === cur }, styleName(k)))));
            const render = () => {
                const s = work[cur] || {};
                area.textContent = '';
                const user = USER_STYLE.test(cur);
                area.append(
                    user ? h('div.fields.two', field('שם', h('input', { name: 'sname', value: s.name || '', maxlength: 40 })),
                        h('button.btn.ghost', { type: 'button', onclick: () => {
                            const which = pick.querySelector('select');
                            work[cur] = null;
                            which.querySelector(`option[value="${cur}"]`)?.remove();
                            cur = which.value = 'normal';
                            render();
                        } }, 'מחיקת הסגנון')) : null,
                    h('div.fields.two',
                        field('גופן', fontSelect('font', s.font || '', true)),
                        field('גודל (נק\')', numInput('size', s.size ?? '', { min: 6, max: 96 })),
                        field('לפני (נק\')', numInput('before', s.before ?? '', { min: 0, max: 200 })),
                        field('אחרי (נק\')', numInput('after', s.after ?? '', { min: 0, max: 200 })),
                        field('צבע', h('input', { type: 'color', name: 'color', value: s.color || '#000000' })),
                        field('יישור', h('select', { name: 'align' }, h('option', { value: '' }, 'לפי הפסקה'),
                            Object.entries({ right: 'ימין', center: 'מרכז', left: 'שמאל', justify: 'שני הצדדים' }).map(([k, t]) => h('option', { value: k, selected: s.align === k }, t))))),
                    h('div.row-btns.wrap', sw('bold', 'מודגש', s.bold), sw('italic', 'נטוי', s.italic), sw('underline', 'קו תחתון', s.underline)),
                    cur === 'normal' ? field('ריווח שורות', numInput('line', s.line ?? 1.15, { min: 0.5, max: 5 })) : '',
                    h('p.hint', 'השינוי חל על כל הפסקאות בסגנון הזה במסמך.'));
            };
            collect = () => {
                if (work[cur] === null) return;   // נמחק
                const f = new FormData(dlg.querySelector('form'));
                const n = k => f.get(k) === '' || f.get(k) === null ? undefined : +f.get(k);
                const s = { ...work[cur] };
                s.font = f.get('font') || undefined;
                for (const k of ['size', 'before', 'after', 'line']) s[k] = n(k);
                s.bold = !!f.get('bold'); s.italic = !!f.get('italic'); s.underline = !!f.get('underline');
                s.color = f.get('color') === '#000000' && cur === 'normal' ? undefined : f.get('color');
                s.align = f.get('align') || undefined;
                if (USER_STYLE.test(cur)) s.name = String(f.get('sname') || '').trim().slice(0, 40) || s.name;
                for (const k of Object.keys(s)) if (s[k] === undefined) delete s[k];
                work[cur] = s;
            };
            pick.addEventListener('change', e => { if (e.target.name === 'which') { collect(); cur = e.target.value; render(); } });
            body.append(pick, area);
            render();
        }, () => {
            collect();
            // סגנון שנמחק: הפסקאות שבו חוזרות ל"רגיל" — באותה עסקה (ביטול אחד)
            const gone = Object.keys(work).filter(k => work[k] === null);
            const tr = editor.state.tr.setDocAttribute('page', mergePage(page, { styles: work }, true));
            if (gone.length) tr.doc.descendants((n, pos) => { if (n.type.name === 'paragraph' && gone.includes(n.attrs.pstyle)) tr.setNodeMarkup(pos, undefined, { ...n.attrs, pstyle: null }); });
            editor.view.dispatch(tr);
        }, 'שמירה');
    }

    function versionsDialog() {
        if (!opts.versions) return;
        sheet('גרסאות', async body => {
            const list = h('ul.list', h('li.empty', 'טוען…'));
            body.append(h('p.hint', 'כל שמירה נרשמת; שמירות של אותו אדם בתוך 10 דקות מאוחדות לגרסה אחת. שחזור שומר את המצב הנוכחי כגרסה ויוצר גרסה חדשה — שום דבר לא נמחק.'), list);
            try {
                const vs = await opts.versions.list();
                list.textContent = '';
                if (!vs.length) list.append(h('li.empty', 'אין עדיין גרסאות'));
                vs.forEach((v, i) => list.append(h('li.ver-row', h('button.use-row', { type: 'button', onclick: () => restore(v, i === 0) },
                    h('span.use-ic', { 'aria-hidden': 'true' }, i === 0 ? '●' : '🕘'),
                    h('span.use-tx', h('b', when(v.saved_at) + (i === 0 ? ' · הנוכחית' : '')), h('small', `${v.by || '—'} · גרסה ${v.version}`)),
                    h('span.use-go', { 'aria-hidden': 'true' }, '‹')),
                    // השוואה לנוכחית — מסמך חדש עם ההבדלים כמעקב שינויים (כמו "סקירה ← השוואה" ב-Word)
                    i > 0 && opts.versions.compare ? h('button.btn.ver-cmp', { type: 'button', title: 'מסמך חדש: מה השתנה מאז הגרסה הזו, כמעקב שינויים',
                        onclick: () => compare(v) }, '⇄ השוואה לנוכחית') : null)));
            } catch (e) {
                list.textContent = '';
                list.append(h('li.error', e.message));
            }
        });
        async function compare(v) {
            try {
                if (!(await opts.versions.compare(v.id))) return;
            } catch (e) { return toast(e.message || 'ההשוואה נכשלה', true); }
            document.querySelector('dialog[open]')?.close();
            toast('נפתח מסמך השוואה — אשרו או דחו כל שינוי');
        }
        async function restore(v, current) {
            if (current) return toast('זו הגרסה הנוכחית');
            if (!editor.isEditable) return toast('צפייה בלבד — אי אפשר לשחזר', true);
            if (!confirm(`לשחזר את הגרסה מ-${when(v.saved_at)}? המצב הנוכחי יישמר כגרסה.`)) return;
            // שחזור בשרת (ביקורת 04, R07): המצב הנוכחי והמשוחזר — שתי גרסאות נפרדות, גם בתוך חלון 10 הדקות
            if (opts.versions.restore) {
                try {
                    if (!(await opts.versions.restore(v.id))) return;
                } catch (e) { return toast(e.message || 'השחזור נכשל', true); }
                document.querySelector('dialog[open]')?.close();
                toast('הגרסה שוחזרה — המצב הקודם נשמר כגרסה');
                return;
            }
            const d = await opts.versions.get(v.id);
            // הגדרות העמוד של הגרסה — בתוך המסמך (attrs.page), כך שגם השחזור עצמו ניתן לביטול
            editor.commands.setContent(migrate(d.json, d.page || page), { emitUpdate: true });
            document.querySelector('dialog[open]')?.close();
            toast('הגרסה שוחזרה');
        }
    }

    // ---------- תצוגת השינויים ----------
    /** סימון מלא / סופי / מקור (כמו ב-Word) — מחלקה על המסמך; העימוד והטאבים נמדדים מחדש (טקסט מוסתר / מוצג). */
    function setTrackView(v) {
        trackView = TRACK_VIEWS[v] ? v : 'all';
        applyTrackView();
        if (trackView !== 'all') toast(trackView === 'final' ? 'תצוגה סופית — השינויים מוצגים כאילו אושרו (לא אושרו בפועל)' : 'תצוגת המקור — כמו לפני השינויים (הם עדיין במסמך)');
    }
    function applyTrackView() {
        const dom = editor.view.dom;
        dom.classList.toggle('wp-tv-final', trackView === 'final');
        dom.classList.toggle('wp-tv-original', trackView === 'original');
        // מחבר מוסתר: השינויים שלו כמו בתצוגה הסופית — התוספת טקסט רגיל, המחיקה לא מוצגת
        trackStyle.textContent = [...trackHide].map(a => `.wp-doc ins.wp-ins[data-author="${CSS.escape(a)}"]{text-decoration:none;color:inherit}`
            + `.wp-doc del.wp-del[data-author="${CSS.escape(a)}"]{display:none}.wp-doc .wp-fmt[data-author="${CSS.escape(a)}"]{text-decoration:none}`
            + `.wp-doc .wp-pchg[data-pchg-author="${CSS.escape(a)}"]::after{display:none}`).join('');
        editor.storage.wpTabs?.measure?.();
        editor.storage.wpPages.schedule?.();
        refresh();
    }
    /** אילו מחברים מוצגים (כמו "סקירה ← הצגת סימון ← אנשים" ב-Word). */
    function authorsDialog() {
        const list = changeAuthors(editor.state.doc);
        if (!list.length) { toast('אין שינויים במסמך'); return; }
        sheet('שינויים של מחברים', body => body.append(h('section.group',
            ...list.map(({ author, count }, i) => h('label.switch', h('input', { type: 'checkbox', name: 'a' + i, checked: !trackHide.has(author) }),
                h('span', h('span.wp-author-dot', { style: { background: authorColor(author) }, 'aria-hidden': 'true' }), ` ${author || 'ללא שם'} · ${count} שינויים`))),
            h('p.hint', 'שינויים של מחבר שאינו מסומן מוצגים כאילו אושרו. "אישור / דחיית כל השינויים" — רק של המחברים המוצגים.'))), form => {
            const f = new FormData(form);
            trackHide.clear();
            list.forEach(({ author }, i) => { if (!f.get('a' + i)) trackHide.add(author); });
            applyTrackView();
        });
    }
    /** ל-PDF מהשרת (app.js): אותה תצוגה של השינויים כמו בעורך — כמו ההדפסה. */
    const exportQuery = () => (trackView !== 'all' ? '&track=' + trackView : '') + (trackHide.size ? '&hide=' + encodeURIComponent(JSON.stringify([...trackHide])) : '');

    // ---------- הדפסה ----------
    /**
     * הדפסה = בדיוק העמודים שעל המסך. לא מודדים מחדש: ב-CSS של ההדפסה כל רווח עמוד (.wp-break) מתכווץ ל-0 והופך
     * למעבר עמוד, כך שהשבירות הן אלה שכבר חושבו. מדידה נוספת (בעבר — "מצב הדפסה") יכלה להעביר שורה גבולית לעמוד אחר.
     */
    function print() {
        if (printViaPdf && opts.printPdf) { opts.printPdf(); return; }
        if (printViaPdf) toast('ההדפסה מהדפדפן לא יודעת להחליף טורים / כותרות זוגיות / מספור באמצע — ההדפסה המדויקת: ייצוא ל-PDF', true);
        window.print();
    }

    // ---------- מצב ----------
    let statusEl = null;
    function status() {
        if (!statusEl) return;
        statusEl.textContent = `${pageCount} ${pageCount === 1 ? 'עמוד' : 'עמודים'}`;
    }

    /** שינוי הגדרות עמוד = טרנזקציה על המסמך: נכנס להיסטוריה (ביטול), ומפעיל onUpdate (שמירה). */
    function setPage(p, replaceStyles = false) {
        const next = mergePage(page, p, replaceStyles);
        if (JSON.stringify(next) === JSON.stringify(page)) return;
        editor.view.dispatch(editor.state.tr.setDocAttribute('page', next));
    }

    function setContent(json) {
        loading = true;
        try { editor.commands.setContent(migrate(json, page), { emitUpdate: false }); } finally { loading = false; }
    }

    function setEditable(v) {
        canEdit = !!v;
        editor.setEditable(canEdit && !previewing);
        root.classList.toggle('readonly', !v);
        bar.querySelectorAll('button, select, input').forEach(el => {
            if (!el.closest('.tb-zoomg, .rb-head') && el !== B.print) el.disabled = !v;
        });
        refresh();
    }

    applyPage();
    applyPhone();
    setEditable(opts.editable !== false);
    refresh();
    countWords();

    const api = {
        editor,
        get page() { return structuredClone(page); },
        get pages() { return pageCount; },
        getJSON: () => editor.getJSON(),
        setContent,
        /** רישום הפקודות (commands.js): run(id, arg), state(id), list({group, available}). */
        commands: cmds,
        setPage,
        setEditable,
        print,
        exportQuery,
        focus: () => editor.commands.focus(),
        statusTarget: el => { statusEl = el; status(); },
        destroy: () => {
            document.removeEventListener('keydown', commentKey);
            resizeObs.disconnect();
            phone.removeEventListener('change', applyPhone);
            vv?.removeEventListener('resize', onViewport);
            vv?.removeEventListener('scroll', onViewport);
            document.removeEventListener('gesturestart', noGesture);
            document.removeEventListener('gesturechange', noGesture);
            document.documentElement.classList.remove('wp-no-page-zoom');
            editor.destroy();
            root.textContent = '';
            delete root.wordEditor;
        },
    };
    root.wordEditor = api;   // כמו .editor של TipTap על המסמך — לבדיקות ולאבחון
    return api;
}

/** כל הכותרות שבהגדרות העמוד (כמו PAGE_HF_KEYS בשרת). */
const HF_KEYS = ['header', 'footer', 'header_even', 'footer_even', 'header_first', 'footer_first'];

/** מיזוג הגדרות עמוד חלקיות (כמו page_normalize בשרת, בלי הניקוי). */
export function mergePage(base, p, replaceStyles = false) {
    const out = structuredClone(base);
    if (!p || typeof p !== 'object') return out;
    for (const k of ['size', 'orientation', 'header', 'footer', 'heading_numbers', 'page_color', 'watermark', 'even_odd', 'header_even', 'footer_even', 'num_restart', 'num_start',
        'first_diff', 'header_first', 'footer_first'])
        if (p[k] !== undefined && p[k] !== null) out[k] = p[k];
    if (p.margins) Object.assign(out.margins, Object.fromEntries(Object.entries(p.margins).filter(([, v]) => isFinite(v) && v !== ''))
    );
    if (p.font) Object.assign(out.font, Object.fromEntries(Object.entries(p.font).filter(([, v]) => v !== '' && v !== null)));
    if (p.numbering) Object.assign(out.numbering, p.numbering);
    if (p.columns) out.columns = { ...(out.columns || { n: 1, gap: 12.7, line: false }), ...p.columns };
    // סגנון של משתמש עם null — נמחק
    if (p.styles) for (const [k, v] of Object.entries(p.styles)) {
        if (v === null) delete out.styles[k];
        else out.styles[k] = replaceStyles ? { ...v } : { ...(out.styles[k] || {}), ...v };
    }
    return out;
}

const FN_MARKS = ['bold', 'italic', 'underline', 'strike', 'superscript', 'subscript'];
/** מהעורך הקטן של ההערה: טקסט פשוט, ו-content (צמתי text עם עיצוב מותר) — או null כשאין עיצוב. */
export function noteFromMini(json) {
    const inline = [];
    (json.content || []).forEach((p, i) => {
        if (i) inline.push({ type: 'text', text: ' ' });
        for (const t of p.content || []) {
            if (t.type !== 'text' || !t.text) continue;
            const marks = (t.marks || []).filter(m => FN_MARKS.includes(m.type)).map(m => ({ type: m.type }));
            inline.push(marks.length ? { type: 'text', text: t.text, marks } : { type: 'text', text: t.text });
        }
    });
    if (inline.length) {
        inline[0] = { ...inline[0], text: inline[0].text.replace(/^\s+/, '') };
        const k = inline.length - 1;
        inline[k] = { ...inline[k], text: inline[k].text.replace(/\s+$/, '') };
    }
    const nodes = inline.filter(t => t.text);
    const text = nodes.map(t => t.text).join('');
    return { text, content: nodes.some(t => t.marks) ? nodes : null };
}

function alignIcon(a) {
    const widths = { right: [14, 10, 14, 8], center: [14, 10, 14, 8], left: [14, 10, 14, 8], justify: [14, 14, 14, 14] }[a];
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 16 14');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '14');
    svg.setAttribute('aria-hidden', 'true');
    widths.forEach((w, i) => {
        const r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        const x = a === 'right' ? 16 - w - 1 : a === 'center' ? (16 - w) / 2 : 1;
        r.setAttribute('x', x); r.setAttribute('y', 1 + i * 3.5); r.setAttribute('width', w); r.setAttribute('height', 1.6); r.setAttribute('rx', 0.8);
        r.setAttribute('fill', 'currentColor');
        svg.append(r);
    });
    return svg;
}

export function when(t) {
    if (!t) return '';
    const d = new Date(t * 1000);
    const today = new Date();
    const time = d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === today.toDateString()) return `היום ${time}`;
    return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : '2-digit' }) + ' ' + time;
}
