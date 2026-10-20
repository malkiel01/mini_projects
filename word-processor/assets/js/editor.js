/**
 * העורך — משותף לאפליקציה (app.js) ולהטמעה (embed.js).
 *
 *   const ed = createWordEditor(root, { json, page, editable, upload, versions, onUpdate, onPage, menu });
 *   ed.getJSON() · ed.setContent(json) · ed.page · ed.setPage(p) · ed.setEditable(b) · ed.print() · ed.destroy()
 *
 * מה שעל המסך הוא מה שיודפס: עמודים בגודל אמיתי (מ"מ → פיקסלים ב-96 לאינץ'), שוליים, כותרת עליונה ותחתונה
 * ומספר עמוד בכל עמוד, וזום שמגדיל רק את הדפים.
 */
import { Editor } from '../../vendor/tiptap.js';
import { extensions } from './extensions.js';
import { Pages, pageLabel } from './pagination.js';
import { cleanPastedHtml } from './paste.js';

const MM = 96 / 25.4;
const PT = 4 / 3;
const GAP = 24;
const SIZES = { A4: [210, 297], Letter: [215.9, 279.4] };
export const FONTS = ['David', 'Frank Ruehl', 'Arial', 'Narkisim', 'Miriam', 'Times New Roman', 'Calibri', 'Tahoma',
    'David Libre', 'Frank Ruhl Libre', 'Miriam Libre', 'Heebo', 'Assistant', 'Rubik', 'Alef'];
const FALLBACK = { David: "'David Libre', serif", 'Frank Ruehl': "'Frank Ruhl Libre', serif", Narkisim: "'Assistant', sans-serif",
    Miriam: "'Miriam Libre', sans-serif", Arial: "'Heebo', sans-serif", 'Times New Roman': "'Frank Ruhl Libre', serif",
    Calibri: "'Assistant', sans-serif", Tahoma: "'Heebo', sans-serif" };
const SIZE_LIST = [8, 9, 10, 10.5, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32, 36, 48, 72];
const STYLE_NAMES = { normal: 'רגיל', title: 'כותרת מסמך', h1: 'כותרת 1', h2: 'כותרת 2', h3: 'כותרת 3' };
const NUM_FORMATS = { n: '1', page_n: "עמ' 1", n_of_m: '1 מתוך 12', dash_n: '- 1 -', hebrew: 'א, ב, ג' };

export function cssFont(f) {
    return `'${String(f).replace(/'/g, '')}', ${FALLBACK[f] || 'sans-serif'}`;
}

export function defaultPage() {
    return {
        size: 'A4', orientation: 'portrait', margins: { top: 25, bottom: 25, right: 25, left: 25 },
        font: { family: 'David', size: 12 },
        styles: {
            title: { size: 22, bold: true, color: '#1f3864', align: 'center', before: 0, after: 12 },
            h1: { size: 16, bold: true, color: '#1f3864', before: 12, after: 6 },
            h2: { size: 14, bold: true, color: '#2f5496', before: 10, after: 4 },
            h3: { size: 12, bold: true, color: '#2f5496', before: 8, after: 4 },
            normal: { before: 0, after: 6, line: 1.15 },
        },
        numbering: { show: true, position: 'bottom', align: 'center', format: 'n', start: 1, first_page: true },
        header: '', footer: '',
    };
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

export function toast(msg, err = false) {
    const el = document.querySelector('.toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('err', err);
    el.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { el.hidden = true; }, 2800);
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
    let page = mergePage(defaultPage(), opts.page || {});
    let zoom = 1;
    let fit = true;
    const emitPage = () => opts.onPage?.(structuredClone(page));

    // ---------- מבנה ----------
    const styleEl = h('style');
    const printEl = h('style', { media: 'print' });
    const pagesLayer = h('div.wp-pages', { 'aria-hidden': 'true' });
    const host = h('div.wp-host');
    const sheetEl = h('div.wp-sheet', pagesLayer, host);
    const zoomEl = h('div.wp-zoom', sheetEl);
    const zoomBox = h('div.wp-zoom-box', zoomEl);
    const stage = h('div.wp-stage', zoomBox);
    const bar = h('div.wp-toolbar', { role: 'toolbar', 'aria-label': 'סרגל כלים' });
    const ctxBar = h('div.wp-toolbar.wp-ctx', { role: 'toolbar', 'aria-label': 'כלים להקשר', hidden: true });
    root.append(styleEl, printEl, bar, ctxBar, stage);
    root.classList.add('wp-editor');

    const geometry = () => {
        const [wmm, hmm] = pageMm();
        const pageH = hmm * MM;
        const m = page.margins;
        return { pageW: wmm * MM, pageH, top: m.top * MM, bottom: m.bottom * MM, right: m.right * MM, left: m.left * MM,
            contentH: pageH - (m.top + m.bottom) * MM, gap: printing ? 0 : GAP };
    };
    let printing = false;
    const pageMm = () => {
        const [w, hh] = SIZES[page.size] || SIZES.A4;
        return page.orientation === 'landscape' ? [hh, w] : [w, hh];
    };

    const editor = new Editor({
        element: host,
        extensions: extensions([Pages.configure({ geometry, onLayout: drawPages })]),
        content: opts.json || { type: 'doc', content: [{ type: 'paragraph' }] },
        editable: opts.editable !== false,
        editorProps: {
            attributes: { class: 'wp-doc', dir: 'rtl', lang: 'he', spellcheck: 'true', 'aria-label': 'המסמך' },
            transformPastedHTML: cleanPastedHtml,
            handlePaste: (view, e) => imageFiles(e.clipboardData) ? (insertImages(imageFiles(e.clipboardData)), true) : false,
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
        onTransaction: () => refresh(),
    });
    editor.storage.wpPages.zoom = 1;

    // ---------- דפים ----------
    function applyPage() {
        const g = geometry();
        editor.storage.wpPages.contentWidth = g.pageW - g.left - g.right;
        sheetEl.style.width = g.pageW + 'px';
        const s = page.styles;
        const st = (sel, x, heading) => `${sel}{${x.font ? `font-family:${cssFont(x.font)};` : ''}${x.size ? `font-size:${x.size}pt;` : ''}`
            + `font-weight:${x.bold ? 700 : 400};font-style:${x.italic ? 'italic' : 'normal'};text-decoration:${x.underline ? 'underline' : 'none'};`
            + `${x.color ? `color:${x.color};` : (heading ? '' : '')}${x.align ? `text-align:${x.align};` : ''}`
            + `padding-top:${x.before ?? 0}pt;padding-bottom:${x.after ?? 0}pt;${x.line ? `line-height:${x.line};` : ''}}`;
        const P = '.wp-sheet .wp-doc';
        styleEl.textContent = `${P}{padding:${g.top}px ${g.right}px 0 ${g.left}px;font-family:${cssFont(page.font.family)};font-size:${page.font.size}pt;line-height:${s.normal.line || 1.15}}`
            + st(`${P} p`, s.normal, false) + st(`${P} p.wp-title`, s.title, true)
            + st(`${P} h1`, s.h1, true) + st(`${P} h2`, s.h2, true) + st(`${P} h3`, s.h3, true)
            + `${P} li>p{padding-top:0;padding-bottom:${Math.min(3, s.normal.after ?? 0)}pt}`;
        // הדפסה: אותם עמודים — כל רווח של עמוד הופך לשבירת עמוד, ומספר העמוד בשולי הדף
        const [wmm, hmm] = pageMm();
        const nb = page.numbering;
        const fmt = { n: 'counter(page)', page_n: `"עמ' " counter(page)`, n_of_m: 'counter(page) " מתוך " counter(pages)',
            dash_n: '"- " counter(page) " -"', hebrew: 'counter(page, hebrew)' }[nb.format] || 'counter(page)';
        const box = (pos, al) => `@${pos}-${{ right: 'right', center: 'center', left: 'left' }[al]}`;
        const q = t => '"' + String(t).replace(/["\\]/g, '\\$&').replace(/\n/g, ' ') + '"';
        let margin = '';
        const slots = {};
        if (page.header) slots['top-center'] = q(page.header);
        if (page.footer) slots['bottom-center'] = q(page.footer);
        if (nb.show) {
            const k = box(nb.position, nb.align).slice(1);
            slots[k] = slots[k] ? `${slots[k]} "  " ${fmt}` : fmt;
        }
        for (const [k, v] of Object.entries(slots)) margin += `@${k}{content:${v};font-family:${cssFont(page.font.family)};font-size:9pt}`;
        let firstNoNum = '';
        if (nb.show && !nb.first_page) {
            const k = box(nb.position, nb.align).slice(1);
            const text = k === 'top-center' && page.header ? q(page.header) : k === 'bottom-center' && page.footer ? q(page.footer) : 'none';
            firstNoNum = `@page:first{@${k}{content:${text}}}`;
        }
        printEl.textContent = `@page{size:${wmm}mm ${hmm}mm;margin:${page.margins.top}mm ${page.margins.right}mm ${page.margins.bottom}mm ${page.margins.left}mm;${margin}}`
            + firstNoNum + (nb.start !== 1 ? `@page:first{counter-reset:page ${nb.start - 1}}` : '');
        setZoom();
        editor.storage.wpPages.schedule?.();
    }

    function drawPages(res) {
        const g = geometry();
        const n = res.pages;
        const total = n;
        const nb = page.numbering;
        pagesLayer.textContent = '';
        for (let i = 0; i < n; i++) {
            const pg = h('div.wp-page', { style: { top: i * (g.pageH + GAP) + 'px', height: g.pageH + 'px' } });
            const label = nb.show && (i > 0 || nb.first_page) ? pageLabel(nb.start + i, total, nb.format) : '';
            const hdr = h('div.wp-hf.wp-hdr', { style: { height: g.top + 'px', paddingInline: `${g.right}px ${g.left}px` } });
            const ftr = h('div.wp-hf.wp-ftr', { style: { height: g.bottom + 'px', paddingInline: `${g.right}px ${g.left}px` } });
            if (page.header) hdr.append(h('span.wp-hf-text', page.header));
            if (page.footer) ftr.append(h('span.wp-hf-text', page.footer));
            if (label) (nb.position === 'top' ? hdr : ftr).append(h('span.wp-num.al-' + nb.align, { style: nb.align === 'right' ? { right: g.right + 'px' } : nb.align === 'left' ? { left: g.left + 'px' } : {} }, label));
            pg.append(hdr, ftr);
            pagesLayer.append(pg);
        }
        const H = n * g.pageH + (n - 1) * GAP;
        editor.view.dom.style.minHeight = H + 'px';
        sheetEl.style.height = H + 'px';
        pageCount = n;
        setZoom();
        status();
    }
    let pageCount = 1;

    function setZoom() {
        const g = geometry();
        if (fit) {
            const avail = stage.clientWidth - 16;
            zoom = avail > 0 ? Math.max(0.3, Math.min(1.25, avail / g.pageW)) : 1;
        }
        zoomEl.style.transform = `scale(${zoom})`;
        zoomBox.style.width = g.pageW * zoom + 'px';
        zoomBox.style.height = (parseFloat(sheetEl.style.height) || g.pageH) * zoom + 'px';
        editor.storage.wpPages.zoom = zoom;
        if (zoomLabel) zoomLabel.textContent = Math.round(zoom * 100) + '%';
    }
    new ResizeObserver(() => { if (fit) setZoom(); }).observe(stage);

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

    const styleSel = h('select.tb-sel.w-style', { 'aria-label': 'סגנון', title: 'סגנון',
        onchange: e => { chain().setStyle(e.target.value).run(); } },
        Object.entries(STYLE_NAMES).map(([k, t]) => h('option', { value: k }, t)));
    const fontSel = h('select.tb-sel.w-font', { 'aria-label': 'גופן', title: 'גופן',
        onchange: e => { e.target.value ? chain().setFontFamily(e.target.value).run() : chain().unsetFontFamily().run(); } },
        h('option', { value: '' }, 'גופן המסמך'), FONTS.map(f => h('option', { value: f, style: { fontFamily: cssFont(f) } }, f)));
    const sizeSel = h('select.tb-sel.w-size', { 'aria-label': 'גודל גופן', title: 'גודל',
        onchange: e => { e.target.value ? chain().setFontSize(e.target.value + 'pt').run() : chain().unsetFontSize().run(); } },
        h('option', { value: '' }, '—'), SIZE_LIST.map(s => h('option', { value: s }, s)));
    const colorIn = h('input', { type: 'color', value: '#c00000', 'aria-label': 'צבע טקסט', oninput: e => chain().setColor(e.target.value).run() });
    const markIn = h('input', { type: 'color', value: '#ffff00', 'aria-label': 'צבע הדגשה', oninput: e => chain().setHighlight({ color: e.target.value }).run() });
    const listType = h('select.tb-sel.w-list', { 'aria-label': 'סוג מספור', title: 'סוג מספור',
        onchange: e => {
            if (!ed.isActive('orderedList')) chain().toggleOrderedList().run();
            chain().updateAttributes('orderedList', { listType: e.target.value }).run();
        } }, h('option', { value: 'decimal' }, '1.'), h('option', { value: 'hebrew' }, 'א.'), h('option', { value: 'roman' }, 'i.'));
    let zoomLabel = h('span.tb-zoom', '100%');
    const imgInput = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', multiple: true, hidden: true,
        onchange: e => { if (e.target.files.length) insertImages([...e.target.files]); e.target.value = ''; } });

    const B = {
        undo: btn('↶', 'ביטול (Ctrl+Z)', () => chain().undo().run()),
        redo: btn('↷', 'ביצוע מחדש (Ctrl+Y)', () => chain().redo().run()),
        bold: btn(h('b', 'B'), 'מודגש (Ctrl+B)', () => chain().toggleBold().run()),
        italic: btn(h('i', 'I'), 'נטוי (Ctrl+I)', () => chain().toggleItalic().run()),
        underline: btn(h('u', 'U'), 'קו תחתון (Ctrl+U)', () => chain().toggleUnderline().run()),
        strike: btn(h('s', 'S'), 'קו חוצה', () => chain().toggleStrike().run()),
        sup: btn(h('span', 'x', h('sup', '2')), 'כתב עילי', () => chain().toggleSuperscript().run()),
        sub: btn(h('span', 'x', h('sub', '2')), 'כתב תחתי', () => chain().toggleSubscript().run()),
        color: h('label.tb.tb-color', { title: 'צבע טקסט' }, h('span', { 'aria-hidden': 'true' }, 'A'), colorIn),
        mark: h('label.tb.tb-color.mark', { title: 'צבע הדגשה' }, h('span', { 'aria-hidden': 'true' }, '🖍'), markIn),
        clear: btn('⌧', 'ניקוי עיצוב', () => chain().unsetAllMarks().setBlockAttrs({ lineHeight: null, spaceBefore: null, spaceAfter: null, indent: 0 }).unsetTextAlign().run()),
        right: btn(alignIcon('right'), 'יישור לימין', () => chain().setTextAlign('right').run()),
        center: btn(alignIcon('center'), 'מרכוז', () => chain().setTextAlign('center').run()),
        left: btn(alignIcon('left'), 'יישור לשמאל', () => chain().setTextAlign('left').run()),
        justify: btn(alignIcon('justify'), 'יישור לשני הצדדים', () => chain().setTextAlign('justify').run()),
        bullets: btn('•≡', 'תבליטים', () => chain().toggleBulletList().run()),
        numbers: btn('1≡', 'רשימה ממוספרת', () => chain().toggleOrderedList().run()),
        outdent: btn('⇤', 'הקטנת הזחה', () => ed.isActive('listItem') ? chain().liftListItem('listItem').run() : chain().changeIndent(-1).run()),
        indent: btn('⇥', 'הגדלת הזחה', () => ed.isActive('listItem') ? chain().sinkListItem('listItem').run() : chain().changeIndent(1).run()),
        para: btn('¶', 'פסקה: ריווח, הזחה, כיוון', () => paragraphDialog()),
        table: btn('▦', 'טבלה', () => tableDialog()),
        image: btn('🖼', 'תמונה', () => imgInput.click()),
        pagebreak: btn('⤓', 'מעבר עמוד (Ctrl+Enter)', () => chain().insertPageBreak().run()),
        pagesetup: btn('📄', 'הגדרות עמוד ומספור עמודים', () => pageDialog()),
        styles: btn('🅰', 'עריכת סגנונות', () => stylesDialog()),
        print: btn('🖨', 'הדפסה / PDF', () => print()),
        zoomOut: btn('−', 'הקטנה', () => { fit = false; zoom = Math.max(0.3, Math.round((zoom - 0.1) * 10) / 10); setZoom(); }),
        zoomIn: btn('+', 'הגדלה', () => { fit = false; zoom = Math.min(3, Math.round((zoom + 0.1) * 10) / 10); setZoom(); }),
        zoomFit: btn('⤢', 'התאמה למסך', () => { fit = true; setZoom(); }),
    };
    bar.append(
        h('div.tb-group', B.undo, B.redo), sep(),
        h('div.tb-group', styleSel, fontSel, sizeSel), sep(),
        h('div.tb-group', B.bold, B.italic, B.underline, B.strike, B.color, B.mark, B.sup, B.sub, B.clear), sep(),
        h('div.tb-group', B.right, B.center, B.left, B.justify), sep(),
        h('div.tb-group', B.bullets, B.numbers, listType, B.outdent, B.indent, B.para), sep(),
        h('div.tb-group', B.table, B.image, imgInput, B.pagebreak), sep(),
        h('div.tb-group', B.pagesetup, B.styles, B.print, opts.versions ? btn('🕘', 'גרסאות', () => versionsDialog()) : null, ...(opts.menu || [])), sep(),
        h('div.tb-group.tb-zoomg', B.zoomOut, zoomLabel, B.zoomIn, B.zoomFit),
    );

    function refresh() {
        const on = (b, v) => { b.classList.toggle('on', !!v); b.setAttribute('aria-pressed', v ? 'true' : 'false'); };
        on(B.bold, ed.isActive('bold')); on(B.italic, ed.isActive('italic')); on(B.underline, ed.isActive('underline'));
        on(B.strike, ed.isActive('strike')); on(B.sup, ed.isActive('superscript')); on(B.sub, ed.isActive('subscript'));
        for (const a of ['right', 'center', 'left', 'justify']) on(B[a], ed.isActive({ textAlign: a }));
        on(B.bullets, ed.isActive('bulletList'));
        on(B.numbers, ed.isActive('orderedList'));
        styleSel.value = ed.isActive('heading', { level: 1 }) ? 'h1' : ed.isActive('heading', { level: 2 }) ? 'h2' : ed.isActive('heading', { level: 3 }) ? 'h3'
            : ed.isActive('paragraph', { pstyle: 'title' }) ? 'title' : 'normal';
        const ts = ed.getAttributes('textStyle');
        fontSel.value = FONTS.includes(ts.fontFamily) ? ts.fontFamily : '';
        sizeSel.value = ts.fontSize ? String(parseFloat(ts.fontSize)) : '';
        if (ed.isActive('orderedList')) listType.value = ed.getAttributes('orderedList').listType || 'decimal';
        B.undo.disabled = !ed.can().undo();
        B.redo.disabled = !ed.can().redo();
        contextBar();
    }

    // ---------- כלים להקשר: טבלה / תמונה ----------
    let ctxKind = '';
    function contextBar() {
        const sel = ed.state.selection;
        const isImg = sel.node?.type.name === 'image';
        const kind = !ed.isEditable ? '' : isImg ? 'image' : ed.isActive('table') ? 'table' : '';
        if (kind === ctxKind && kind !== 'image') return;
        ctxKind = kind;
        ctxBar.textContent = '';
        ctxBar.hidden = !kind;
        if (kind === 'table') {
            const fill = h('input', { type: 'color', value: '#ddeeff', 'aria-label': 'מילוי תא', oninput: e => chain().setCellAttribute('background', e.target.value).run() });
            ctxBar.append(h('span.ctx-title', 'טבלה'),
                btn('⬆+', 'שורה מעל', () => chain().addRowBefore().run()), btn('⬇+', 'שורה מתחת', () => chain().addRowAfter().run()),
                btn('➡+', 'עמודה מימין', () => chain().addColumnBefore().run()), btn('⬅+', 'עמודה משמאל', () => chain().addColumnAfter().run()),
                btn('−⇆', 'מחיקת שורה', () => chain().deleteRow().run()), btn('−⇅', 'מחיקת עמודה', () => chain().deleteColumn().run()),
                btn('⊞', 'מיזוג / פיצול תאים', () => chain().mergeOrSplit().run()), btn('▤', 'שורת כותרת', () => chain().toggleHeaderRow().run()),
                h('label.tb.tb-color', { title: 'מילוי תא' }, h('span', { 'aria-hidden': 'true' }, '◩'), fill),
                btn('🗑', 'מחיקת הטבלה', () => chain().deleteTable().run()));
        } else if (kind === 'image') {
            const a = sel.node.attrs;
            const set = attrs => chain().updateAttributes('image', attrs).setNodeSelection(sel.from).run();
            const max = editor.storage.wpPages.contentWidth;
            const sizeTo = f => {
                const img = editor.view.nodeDOM(sel.from)?.querySelector('img');
                const ratio = img && img.naturalWidth ? img.naturalHeight / img.naturalWidth : (a.height && a.width ? a.height / a.width : 1);
                const w = f === 0 ? Math.min(img?.naturalWidth || max, max) : Math.round(max * f);
                set({ width: w, height: Math.round(w * ratio) });
            };
            ctxBar.append(h('span.ctx-title', 'תמונה'),
                segBtns({ inline: 'בשורה', square: 'ריבוע' }, a.wrap, v => set({ wrap: v }), 'גלישת טקסט'),
                segBtns({ right: 'ימין', center: 'מרכז', left: 'שמאל' }, a.align, v => set({ align: v }), 'יישור'),
                segBtns([['0.25', '¼'], ['0.5', '½'], ['1', 'מלא'], ['0', 'מקורי']], '', v => sizeTo(+v), 'גודל'),
                btn('🗑', 'מחיקת התמונה', () => chain().deleteSelection().run()));
        }
    }
    function segBtns(options, value, onPick, label) {
        return h('div.tb-seg', { role: 'group', 'aria-label': label },
            (Array.isArray(options) ? options : Object.entries(options)).map(([v, t]) => h('button', { type: 'button', class: String(value) === v ? 'on' : '', onmousedown: e => e.preventDefault(), onclick: () => onPick(v) }, t)));
    }

    // ---------- חלונות ----------
    function paragraphDialog() {
        const node = ed.state.selection.$from.parent;
        const a = node.attrs || {};
        const exact = /^exact:([\d.]+)$/.exec(a.lineHeight || '');
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
                        field('הזחה (צעדים של 1.27 ס"מ)', numInput('indent', a.indent || 0, { min: 0, max: 8, step: 1 })))));
        }, form => {
            const f = new FormData(form);
            const line = f.get('line');
            const ex = parseFloat(f.get('exact'));
            const n = k => f.get(k) === '' ? null : Math.max(0, +f.get(k));
            chain().setBlockAttrs({
                dir: f.get('dir') === 'ltr' ? 'ltr' : null,
                lineHeight: line === 'exact' ? (ex > 0 ? 'exact:' + ex : null) : (line || null),
                spaceBefore: n('before'), spaceAfter: n('after'), indent: Math.min(8, Math.round(n('indent') || 0)),
            }).run();
        });
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

    function pageDialog() {
        const p = page;
        const nb = p.numbering;
        sheet('הגדרות עמוד', body => {
            body.append(
                h('section.group', h('h3', 'נייר'),
                    seg('size', { A4: 'A4', Letter: 'Letter' }, p.size, 'גודל'),
                    seg('orientation', { portrait: 'לאורך', landscape: 'לרוחב' }, p.orientation, 'כיוון')),
                h('section.group', h('h3', 'שוליים ', h('small', 'במ"מ')),
                    h('div.fields.two',
                        field('למעלה', numInput('m_top', p.margins.top, { min: 0, max: 80 })), field('למטה', numInput('m_bottom', p.margins.bottom, { min: 0, max: 80 })),
                        field('ימין', numInput('m_right', p.margins.right, { min: 0, max: 80 })), field('שמאל', numInput('m_left', p.margins.left, { min: 0, max: 80 })))),
                h('section.group', h('h3', 'גופן המסמך'),
                    h('div.fields.two', field('גופן', fontSelect('font', p.font.family)), field('גודל', numInput('fsize', p.font.size, { min: 6, max: 72 })))),
                h('section.group', h('h3', 'מספור עמודים'),
                    sw('n_show', 'מספרי עמודים', nb.show),
                    seg('n_pos', { top: 'למעלה', bottom: 'למטה' }, nb.position, 'מיקום'),
                    seg('n_align', { right: 'ימין', center: 'מרכז', left: 'שמאל' }, nb.align, 'יישור'),
                    h('div.fields.two',
                        field('תבנית', h('select', { name: 'n_format' }, Object.entries(NUM_FORMATS).map(([k, t]) => h('option', { value: k, selected: k === nb.format }, t)))),
                        field('מתחיל מ-', numInput('n_start', nb.start, { min: 0, max: 9999, step: 1 }))),
                    sw('n_first', 'מספר גם בעמוד הראשון', nb.first_page)),
                h('section.group', h('h3', 'כותרת עליונה ותחתונה'),
                    field('כותרת עליונה', h('input', { name: 'header', value: p.header, maxlength: 500 })),
                    field('כותרת תחתונה', h('input', { name: 'footer', value: p.footer, maxlength: 500 }))));
        }, form => {
            const f = new FormData(form);
            const n = k => +f.get(k);
            setPage({
                size: f.get('size'), orientation: f.get('orientation'),
                margins: { top: n('m_top'), bottom: n('m_bottom'), right: n('m_right'), left: n('m_left') },
                font: { family: f.get('font'), size: n('fsize') || 12 },
                numbering: { show: !!f.get('n_show'), position: f.get('n_pos'), align: f.get('n_align'), format: f.get('n_format'),
                    start: Math.max(0, Math.round(n('n_start'))), first_page: !!f.get('n_first') },
                header: f.get('header'), footer: f.get('footer'),
            });
            emitPage();
        });
    }

    function stylesDialog() {
        let cur = styleSel.value || 'h1';
        const work = structuredClone(page.styles);
        let collect = () => {};
        sheet('עריכת סגנונות', (body, dlg) => {
            const area = h('div.group');
            const pick = seg('which', STYLE_NAMES, cur, 'סגנון');
            const render = () => {
                const s = work[cur] || {};
                area.textContent = '';
                area.append(
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
                const f = new FormData(dlg.querySelector('form'));
                const n = k => f.get(k) === '' || f.get(k) === null ? undefined : +f.get(k);
                const s = { ...work[cur] };
                s.font = f.get('font') || undefined;
                for (const k of ['size', 'before', 'after', 'line']) s[k] = n(k);
                s.bold = !!f.get('bold'); s.italic = !!f.get('italic'); s.underline = !!f.get('underline');
                s.color = f.get('color') === '#000000' && cur === 'normal' ? undefined : f.get('color');
                s.align = f.get('align') || undefined;
                for (const k of Object.keys(s)) if (s[k] === undefined) delete s[k];
                work[cur] = s;
            };
            pick.addEventListener('change', e => { if (e.target.name === 'which') { collect(); cur = e.target.value; render(); } });
            body.append(pick, area);
            render();
        }, () => {
            collect();
            setPage({ styles: work }, true);
            emitPage();
        }, 'שמירה');
    }

    function versionsDialog() {
        if (!opts.versions) return;
        sheet('גרסאות', async body => {
            const list = h('ul.list', h('li.empty', 'טוען…'));
            body.append(h('p.hint', 'כל שמירה נרשמת; שמירות של אותו אדם בתוך 10 דקות מאוחדות לגרסה אחת. שחזור יוצר גרסה חדשה — שום דבר לא נמחק.'), list);
            try {
                const vs = await opts.versions.list();
                list.textContent = '';
                if (!vs.length) list.append(h('li.empty', 'אין עדיין גרסאות'));
                vs.forEach((v, i) => list.append(h('li', h('button.use-row', { type: 'button', onclick: () => restore(v, i === 0) },
                    h('span.use-ic', { 'aria-hidden': 'true' }, i === 0 ? '●' : '🕘'),
                    h('span.use-tx', h('b', when(v.saved_at) + (i === 0 ? ' · הנוכחית' : '')), h('small', `${v.by || '—'} · גרסה ${v.version}`)),
                    h('span.use-go', { 'aria-hidden': 'true' }, '‹')))));
            } catch (e) {
                list.textContent = '';
                list.append(h('li.error', e.message));
            }
        });
        async function restore(v, current) {
            if (current) return toast('זו הגרסה הנוכחית');
            if (!editor.isEditable) return toast('צפייה בלבד — אי אפשר לשחזר', true);
            if (!confirm(`לשחזר את הגרסה מ-${when(v.saved_at)}? המצב הנוכחי יישמר כגרסה.`)) return;
            const d = await opts.versions.get(v.id);
            editor.commands.setContent(d.json, { emitUpdate: true });
            if (d.page) { setPage(d.page); emitPage(); }
            document.querySelector('dialog[open]')?.close();
            toast('הגרסה שוחזרה');
        }
    }

    // ---------- הדפסה ----------
    function print() {
        printing = true;
        document.documentElement.classList.add('wp-printing');
        editor.storage.wpPages.schedule?.();
        // שני פריימים: המדידה עם רווח 0 בין הדפים, ואז ההדפסה
        requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(() => {
            window.print();
            printing = false;
            document.documentElement.classList.remove('wp-printing');
            editor.storage.wpPages.schedule?.();
        }, 60)));
    }

    // ---------- מצב ----------
    let statusEl = null;
    function status() {
        if (!statusEl) return;
        statusEl.textContent = `${pageCount} ${pageCount === 1 ? 'עמוד' : 'עמודים'}`;
    }

    function setPage(p, replaceStyles = false) {
        page = mergePage(page, p, replaceStyles);
        applyPage();
    }

    function setEditable(v) {
        editor.setEditable(!!v);
        root.classList.toggle('readonly', !v);
        bar.querySelectorAll('button, select, input').forEach(el => {
            if (!el.closest('.tb-zoomg') && el !== B.print) el.disabled = !v;
        });
        refresh();
    }

    applyPage();
    setEditable(opts.editable !== false);
    refresh();

    return {
        editor,
        get page() { return structuredClone(page); },
        get pages() { return pageCount; },
        getJSON: () => editor.getJSON(),
        setContent: json => editor.commands.setContent(json, { emitUpdate: false }),
        setPage,
        setEditable,
        print,
        focus: () => editor.commands.focus(),
        statusTarget: el => { statusEl = el; status(); },
        destroy: () => { editor.destroy(); root.textContent = ''; },
    };
}

/** מיזוג הגדרות עמוד חלקיות (כמו page_normalize בשרת, בלי הניקוי). */
export function mergePage(base, p, replaceStyles = false) {
    const out = structuredClone(base);
    if (!p || typeof p !== 'object') return out;
    for (const k of ['size', 'orientation', 'header', 'footer']) if (p[k] !== undefined && p[k] !== null) out[k] = p[k];
    if (p.margins) Object.assign(out.margins, Object.fromEntries(Object.entries(p.margins).filter(([, v]) => isFinite(v) && v !== ''))
    );
    if (p.font) Object.assign(out.font, Object.fromEntries(Object.entries(p.font).filter(([, v]) => v !== '' && v !== null)));
    if (p.numbering) Object.assign(out.numbering, p.numbering);
    if (p.styles) for (const [k, v] of Object.entries(p.styles)) out.styles[k] = replaceStyles ? { ...v } : { ...(out.styles[k] || {}), ...v };
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

export function when(t) {
    if (!t) return '';
    const d = new Date(t * 1000);
    const today = new Date();
    const time = d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === today.toDateString()) return `היום ${time}`;
    return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : '2-digit' }) + ' ' + time;
}
