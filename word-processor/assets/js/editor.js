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
import { Editor, Extension, getHTMLFromFragment, collab } from '../../vendor/tiptap.js';
import { extensions, imageBox, FONTS, FALLBACK, cssFont, USER_STYLE, styleKeyOf, UNDERLINES, TABLE_BORDERS, VALIGNS } from './extensions.js';
import { Pages, pageLabel, pageNumbers, Endnotes } from './pagination.js';
import { cleanPastedHtml } from './paste.js';
import { migrate } from './schema.js';
import { createCommands } from './commands.js';
import { FindReplace, createFindBar } from './find.js';
import { ParagraphMarks, marksKey, createNavPane, createPasteOptions } from './view.js';
import { headings } from './toc.js';
import { captions, SEQ_LABELS, listTitle } from './caption.js';
import { createComments } from './comments.js';
import { TrackChanges, changes, resolveChanges, goToChange, setTracking, VIEWS as TRACK_VIEWS, authors as changeAuthors, authorColor } from './track.js';
import { TabStops } from './tabs.js';
import { createRuler } from './ruler.js';
import { Peers } from './collab.js';
import { h, toast, sheet, field, seg, numInput, when } from './ui.js';
import { createMediaDialogs } from './dialogs-media.js';
import { createTextDialogs } from './dialogs-text.js';
import { createPageDialogs } from './dialogs-page.js';
import { createStyleDialogs } from './dialogs-styles.js';

// לתאימות: app.js / embed.js / guest.js מייבאים אותם מכאן
export { h, toast, sheet, field, seg, when };

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

    // ---------- חלונות: חיתוך, הערת שוליים / סיום, תמונה (dialogs-media.js) ----------
    const { cropDialog, footnoteDialog, imageDialog } = createMediaDialogs({ editor, host, pagesLayer, ICONS });

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

    // ---------- חלונות הטקסט: פסקה, קישור, ספירה, מספור, כיתוב, הפניה, סימניה, טבלה (dialogs-text.js) ----------
    const { paragraphDialog, linkDialog, wordCountDialog, numberingDialog, captionDialog, crossRefDialog, bookmarkDialog, tableDialog } = createTextDialogs({ editor, chain, pages: () => pageCount });

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

    // ---------- חלונות העמוד (dialogs-page.js) והסגנונות והגרסאות (dialogs-styles.js) ----------
    // page מתחלף (setPage) — עובר כפונקציה
    const getPage = () => page;
    const { pageDialog, setSections } = createPageDialogs({ editor, opts, getPage, sections, setPage, mergePage, MM, NUM_FORMATS });
    const { newStyleDialog, updateStyleFromSelection, stylesDialog, versionsDialog } = createStyleDialogs({ editor, opts, getPage, mergePage, STYLE_NAMES, styleName, userKeys, styleSel });

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
