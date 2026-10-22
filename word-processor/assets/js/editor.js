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
import { Editor, Extension, StarterKit, Superscript, Subscript } from '../../vendor/tiptap.js';
import { extensions, imageBox, FONTS, FALLBACK, cssFont, safeHref, bookmarks, BOOKMARK_RE, USER_STYLE, styleKeyOf } from './extensions.js';
import { Pages, pageLabel } from './pagination.js';
import { cleanPastedHtml } from './paste.js';
import { migrate } from './schema.js';
import { createCommands } from './commands.js';
import { FindReplace, createFindBar } from './find.js';

const MM = 96 / 25.4;
const PT = 4 / 3;
const GAP = 24;
const SIZES = { A4: [210, 297], Letter: [215.9, 279.4] };
const SIZE_LIST = [8, 9, 10, 10.5, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32, 36, 48, 72];
const STYLE_NAMES = { normal: 'רגיל', title: 'כותרת מסמך', ...Object.fromEntries([1, 2, 3, 4, 5, 6, 7, 8, 9].map(l => ['h' + l, 'כותרת ' + l])) };
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
    const pagesLayer = h('div.wp-pages', { 'aria-hidden': 'true' });
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
    root.append(styleEl, printEl, bar, previewBar, findSlot, stage, statusBar, mobileBar, sheetShade);
    root.classList.add('wp-editor');

    /** גיאומטריה של עמוד לפי הגדרות (בפיקסלים לא מוגדלים). */
    const geoOf = p => {
        const [wmm, hmm] = mmOf(p);
        const m = p.margins;
        const pageH = hmm * MM;
        return { pageW: wmm * MM, pageH, top: m.top * MM, bottom: m.bottom * MM, right: m.right * MM, left: m.left * MM,
            contentH: pageH - (m.top + m.bottom) * MM };
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
    let stylesUI = null;   // גלריית הסגנונות והרשימה — נבנות מחדש כשהסגנונות משתנים (נקבע אחרי בניית הסרט)
    let secMemo = { doc: null, page: null, out: null };
    const sections = () => {
        const doc = ed0?.state.doc;
        if (doc && secMemo.doc === doc && secMemo.page === page) return secMemo.out;
        const out = [{ from: 0, page }];
        let cur = page;
        doc?.forEach((n, pos) => {
            if (n.type.name !== 'sectionBreak') return;
            cur = mergePage(cur, n.attrs.page || {});
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
        extensions: extensions([Pages.configure({ geometry, onLayout: drawPages }), FindReplace]),
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
                return false;
            },
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
        editor.storage.wpPages.contentWidth = g.pageW - g.left - g.right;
        sheetEl.style.width = g.sheetW + 'px';
        // כמה מקטעים: הרוחב של כל מקטע נקבע לכל בלוק (pagination.js), לא בריפוד של המסמך
        editor.view.dom.classList.toggle('wp-multisec', g.sections.length > 1);
        const s = page.styles;
        const st = (sel, x, heading) => `${sel}{${x.font ? `font-family:${cssFont(x.font)};` : ''}${x.size ? `font-size:${x.size}pt;` : ''}`
            + `font-weight:${x.bold ? 700 : 400};font-style:${x.italic ? 'italic' : 'normal'};text-decoration:${x.underline ? 'underline' : 'none'};`
            + `${x.color ? `color:${x.color};` : (heading ? '' : '')}${x.align ? `text-align:${x.align};` : ''}`
            + `padding-top:${x.before ?? 0}pt;padding-bottom:${x.after ?? 0}pt;${x.line ? `line-height:${x.line};` : ''}}`;
        const P = '.wp-sheet .wp-doc';
        styleEl.textContent = `${P}{padding:${g.top}px ${g.right}px 0 ${g.left}px;font-family:${cssFont(page.font.family)};font-size:${page.font.size}pt;line-height:${s.normal.line || 1.15}}`
            + st(`${P} p`, s.normal, false) + st(`${P} p.wp-title`, s.title, true)
            + [1, 2, 3, 4, 5, 6, 7, 8, 9].map(l => st(`${P} h${l}`, s['h' + l] || {}, true)).join('')
            + Object.keys(s).filter(k => USER_STYLE.test(k)).map(k => st(`${P} p.wp-s-${k}`, { ...s.normal, ...s[k] }, false)).join('')
            + `${P} li>p{padding-top:0;padding-bottom:${Math.min(3, s.normal.after ?? 0)}pt}`
            // בתא טבלה — בלי רווח אחרי פסקה (גם ב-PDF וב-Word); בלי זה כלל הסגנון (ספציפי יותר) גובר על editor.css
            + `${P} td>p,${P} th>p{padding-bottom:0}`;
        // הדפסה: אותם עמודים — כל רווח של עמוד הופך לשבירת עמוד, ומספר העמוד בשולי הדף
        const [wmm, hmm] = pageMm();
        const nb = page.numbering;
        const fmt = { n: 'counter(page)', page_n: `"עמ' " counter(page)`, n_of_m: 'counter(page) " מתוך " counter(pages)',
            dash_n: '"- " counter(page) " -"', hebrew: 'counter(page, hebrew)' }[nb.format] || 'counter(page)';
        const box = (pos, al) => `@${pos}-${{ right: 'right', center: 'center', left: 'left' }[al]}`;
        const q = t => '"' + String(t).replace(/["\\]/g, '\\$&').replace(/\n/g, ' ') + '"';
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
        printEl.textContent = `@page{size:${wmm}mm ${hmm}mm;margin:${page.margins.top}mm ${page.margins.right}mm ${page.margins.bottom - slack}mm ${page.margins.left}mm;${margin}}`
            + firstNoNum + (nb.start !== 1 ? `@page:first{counter-reset:page ${nb.start - 1}}` : '') + named;
        setZoom();
        editor.storage.wpPages.schedule?.();
    }

    function drawPages(res) {
        const G = geometry();
        const n = res.pages;
        const total = n;
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
            const label = nb.show && (i > 0 || nb.first_page) ? pageLabel(page.numbering.start + i, total, nb.format) : '';
            const hdr = h('div.wp-hf.wp-hdr', { style: { height: g.top + 'px', paddingInline: `${g.right}px ${g.left}px` } });
            const ftr = h('div.wp-hf.wp-ftr', { style: { height: g.bottom + 'px', paddingInline: `${g.right}px ${g.left}px` } });
            if (pp.header) hdr.append(h('span.wp-hf-text', pp.header));
            if (pp.footer) ftr.append(h('span.wp-hf-text', pp.footer));
            if (label) (nb.position === 'top' ? hdr : ftr).append(h('span.wp-num.al-' + nb.align, { style: nb.align === 'right' ? { right: g.right + 'px' } : nb.align === 'left' ? { left: g.left + 'px' } : {} }, label));
            pg.append(hdr, ftr);
            pagesLayer.append(pg);
        }
        const H = y - GAP;
        editor.view.dom.style.minHeight = H + 'px';
        sheetEl.style.height = H + 'px';
        pageCount = n;
        setZoom();
        status();
        statusBarPage();
    }
    let pageCount = 1;

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
            crop: pos => cropDialog(pos), imageProps: pos => imageDialog(pos), imageSize: f => imageSizeTo(f),
            footnote: () => footnoteDialog(null),
            link: () => linkDialog(), bookmark: () => bookmarkDialog(),
            newStyle: () => newStyleDialog(), updateStyle: () => updateStyleFromSelection(),
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
    const sizeSel = cmdSel('fontSize', 'w-size', [h('option', { value: '' }, '—'), ...SIZE_LIST.map(s => h('option', { value: s }, s))]);
    const spacingSel = cmdSel('letterSpacing', 'w-spacing', SPACING.map(([v, t]) => h('option', { value: v }, t)));
    const listType = cmdSel('listType', 'w-list', [h('option', { value: 'decimal' }, '1.'), h('option', { value: 'hebrew' }, 'א.'), h('option', { value: 'roman' }, 'i.')]);
    sizeSel.title = 'גודל';
    let zoomLabel = h('span.tb-zoom', '100%');
    let stZoom = null;   // הזום בשורת המצב (נבנה עם שורת המצב)
    const imgInput = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', multiple: true, hidden: true,
        onchange: e => { if (e.target.files.length) insertImages([...e.target.files]); e.target.value = ''; } });

    const TOGGLES = ['bold', 'italic', 'underline', 'strike', 'superscript', 'subscript', 'alignRight', 'alignCenter', 'alignLeft', 'alignJustify', 'bulletList', 'orderedList'];
    const B = Object.fromEntries(['undo', 'redo', ...TOGGLES, 'clearFormat', 'outdent', 'indent', 'paragraphDialog', 'insertTable', 'insertImage', 'pageBreak',
        'footnote', 'sectionBreak', 'pageSetup', 'editStyles', 'print', 'zoomOut', 'zoomIn', 'zoomFit'].map(id => [id, cmdBtn(id)]));
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
            grp('גופן', [fontSel, sizeSel, B.clearFormat], [B.bold, B.italic, B.underline, B.strike, B.superscript, B.subscript, B.color, B.mark, spacingSel]),
            grp('פסקה', [B.bulletList, B.orderedList, listType, B.outdent, B.indent], [B.alignRight, B.alignCenter, B.alignLeft, B.alignJustify, B.paragraphDialog]),
            grp('סגנונות', [gallery], [styleSel, B.editStyles, cmdBtn('newStyle'), cmdBtn('updateStyle')]),
            grp('עריכה', [cmdBtn('find'), cmdBtn('replace')])]],
        ['insert', 'הוספה', [grp('טבלאות', B.insertTable), grp('איורים', [B.insertImage, imgInput]), grp('הערות', B.footnote), grp('קישורים', [cmdBtn('link'), linkOff, cmdBtn('bookmark')]), grp('עמודים', B.pageBreak)]],
        ['layout', 'פריסה', [grp('הגדרת עמוד', B.pageSetup), grp('מעברים', B.sectionBreak)]],
        ['view', 'תצוגה', [zoomGroup, grp('מסמך', [opts.save ? cmdBtn('save') : null, B.print, cmdBtn('printPreview'), opts.versions ? cmdBtn('versions') : null, ...(opts.menu || [])])]],
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
        // פקד בסרט שצריך ערך (בחירה / צבע) — פותחים את הלשונית שלו ומתמקדים בו; אחרת — מפעילים
        const el = [...bar.querySelectorAll('.rb-panel select, .rb-panel input[type=color]')].find(x => x.getAttribute('aria-label') === c.label);
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
    let previewing = false, wasEditable = true;
    const pvPages = h('span.pv-pages');
    previewBar.append(h('strong', 'תצוגה לפני הדפסה'), pvPages, h('span.st-fill'),
        h('button.btn.primary', { type: 'button', onclick: () => print() }, '🖨 הדפסה'),
        h('button.btn', { type: 'button', onclick: () => preview(false) }, 'חזרה לעריכה'));
    function preview(on) {
        if (on === previewing) return;
        previewing = on;
        if (on) { wasEditable = editor.isEditable; editor.setEditable(false, false); openSheet(false); finder.close(false); }
        else editor.setEditable(wasEditable, false);
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
    const stWords = h('span.st-words');
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
        for (let i = 0; i < pages.length; i++) if (top < pages[i].getBoundingClientRect().bottom) return i + 1;
        return pages.length;
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
        sizeSel.value = cmds.state('fontSize').value;
        spacingSel.value = cmds.state('letterSpacing').value;
        const lt = cmds.state('listType').value;
        if (lt) listType.value = lt;
        B.undo.disabled = !cmds.state('undo').enabled;
        B.redo.disabled = !cmds.state('redo').enabled;
        linkOff.disabled = !cmds.state('unlink').enabled;
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
                grp('טבלה', T('deleteTable')));
        } else if (kind === 'image') {
            const a = sel.node.attrs;
            const wrapSel = cmdSel('image.wrap', 'w-wrap', Object.entries(WRAP_NAMES).map(([k, t]) => h('option', { value: k, selected: (a.wrap || 'inline') === k }, t)));
            ctxBar.append(grp('גלישת טקסט', wrapSel),
                grp('יישור', segBtns({ right: 'ימין', center: 'מרכז', left: 'שמאל' }, a.align, v => cmds.run('image.align', v), 'יישור')),
                grp('גודל', segBtns([['0.25', '¼'], ['0.5', '½'], ['1', 'מלא'], ['0', 'מקורי']], '', v => cmds.run('image.size', v), 'גודל')),
                grp('סיבוב', [cmdBtn('image.rotateLeft'), cmdBtn('image.rotateRight')]),
                grp('תמונה', [cmdBtn('image.crop'), cmdBtn('image.props'), cmdBtn('image.delete')]));
        }
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
    function footnoteDialog(pos) {
        const node = pos == null ? null : ed.state.doc.nodeAt(pos);
        if (pos != null && node?.type.name !== 'footnote') return;
        if (!editor.isEditable) return;
        const sel = ed.state.selection;
        let mini = null;
        sheet(node ? 'הערת שוליים' : 'הערת שוליים חדשה', (body, dlg) => {
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
            body.append(h('div.f', h('span', 'הטקסט שיופיע בתחתית העמוד'), tools, box));
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
                editorProps: { attributes: { class: 'fn-edit-doc', dir: 'rtl', lang: 'he', spellcheck: 'true', role: 'textbox', 'aria-multiline': 'false', 'aria-label': 'הטקסט שיופיע בתחתית העמוד', autofocus: 'true' } },
            });
            const sync = () => tools.querySelectorAll('[data-mark]').forEach(b => b.classList.toggle('on', mini.isActive(b.dataset.mark)));
            mini.on('transaction', sync);
            dlg.addEventListener('close', () => mini.destroy(), { once: true });
        }, () => {
            const { text, content } = noteFromMini(mini.getJSON());
            if (!node) {
                if (!text) return;
                ed.chain().focus().setTextSelection(sel.to).insertFootnote(text, content).run();
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
        const ref = e.target.closest?.('.wp-fn-ref, .wp-fn-area .wp-fn-note');
        if (!ref) return;
        let pos = ref.dataset.pos != null ? +ref.dataset.pos : null;
        if (pos == null) { try { pos = editor.view.posAtDOM(ref, 0); } catch { return; } }
        e.preventDefault();
        footnoteDialog(pos);
    });

    /** כיתוב, מרחק מהטקסט ותיאור (alt). */
    function imageDialog(pos) {
        const a = ed.state.doc.nodeAt(pos)?.attrs;
        if (!a) return;
        sheet('תמונה', body => {
            body.append(
                field('כיתוב מתחת לתמונה', h('input', { name: 'caption', value: a.caption || '', maxlength: 300 })),
                field('מרחק מהטקסט (נק\')', numInput('dist', a.dist ?? '', { min: 0, max: 72, placeholder: '9' })),
                field('תיאור לקוראי מסך', h('input', { name: 'alt', value: a.alt || '', maxlength: 300 })));
        }, form => {
            const f = new FormData(form);
            const d = f.get('dist');
            ed.chain().focus().setNodeSelection(pos).updateAttributes('image', {
                caption: String(f.get('caption')).trim(), alt: String(f.get('alt')).trim(), dist: d === '' ? null : Math.max(0, Math.min(72, +d)),
            }).setNodeSelection(pos).run();
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

    /** סימניות: הוספה במקום הסמן (שם קיים — עובר לכאן), ורשימה עם מעבר ומחיקה. */
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
    function pageDialog() {
        const secs = sections();
        const at = editor.state.selection.from;
        const k = secs.reduce((acc, x, i) => (i && x.from < at ? i : acc), 0);
        const p = secs[k].page;
        const nb = page.numbering;
        sheet('הגדרות עמוד', body => {
            body.append(
                secs.length > 1 ? h('section.group', h('h3', 'להחיל על'),
                    seg('scope', { section: `המקטע הזה (${k + 1} מתוך ${secs.length})`, all: 'כל המסמך' }, 'section', 'להחיל על')) : null,
                h('section.group', h('h3', 'נייר'),
                    seg('size', { A4: 'A4', Letter: 'Letter' }, p.size, 'גודל'),
                    seg('orientation', { portrait: 'לאורך', landscape: 'לרוחב' }, p.orientation, 'כיוון')),
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
                    field('כותרת עליונה', h('input', { name: 'header', value: p.header, maxlength: 500 })),
                    field('כותרת תחתונה', h('input', { name: 'footer', value: p.footer, maxlength: 500 }))));
        }, form => {
            const f = new FormData(form);
            const n = k => +f.get(k);
            const sec = {
                size: f.get('size'), orientation: f.get('orientation'),
                margins: { top: n('m_top'), bottom: n('m_bottom'), right: n('m_right'), left: n('m_left') },
                header: f.get('header'), footer: f.get('footer'),
            };
            const whole = {
                font: { family: f.get('font'), size: n('fsize') || 12 },
                numbering: { show: !!f.get('n_show'), position: f.get('n_pos'), align: f.get('n_align'), format: f.get('n_format'),
                    start: Math.max(0, Math.round(n('n_start'))), first_page: !!f.get('n_first') },
            };
            if (secs.length === 1) return setPage({ ...sec, ...whole });
            setSections(f.get('scope') === 'all' ? null : k, sec, whole);
        });
    }

    /**
     * שינוי מקטע אחד (k) או כולם (null) — טרנזקציה אחת (ביטול אחד). כל מעבר מקטע נכתב מחדש עם כל ההגדרות
     * שלו במפורש, כדי ששינוי במקטע אחד לא "יזלוג" למקטעים שאחריו.
     */
    function setSections(k, sec, whole) {
        const pick = q => ({ size: q.size, orientation: q.orientation, margins: { ...q.margins }, header: q.header, footer: q.footer });
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
            // הגדרות העמוד של הגרסה — בתוך המסמך (attrs.page), כך שגם השחזור עצמו ניתן לביטול
            editor.commands.setContent(migrate(d.json, d.page || page), { emitUpdate: true });
            document.querySelector('dialog[open]')?.close();
            toast('הגרסה שוחזרה');
        }
    }

    // ---------- הדפסה ----------
    /**
     * הדפסה = בדיוק העמודים שעל המסך. לא מודדים מחדש: ב-CSS של ההדפסה כל רווח עמוד (.wp-break) מתכווץ ל-0 והופך
     * למעבר עמוד, כך שהשבירות הן אלה שכבר חושבו. מדידה נוספת (בעבר — "מצב הדפסה") יכלה להעביר שורה גבולית לעמוד אחר.
     */
    function print() {
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
        editor.setEditable(!!v);
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

    return {
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
        focus: () => editor.commands.focus(),
        statusTarget: el => { statusEl = el; status(); },
        destroy: () => {
            resizeObs.disconnect();
            phone.removeEventListener('change', applyPhone);
            vv?.removeEventListener('resize', onViewport);
            vv?.removeEventListener('scroll', onViewport);
            document.removeEventListener('gesturestart', noGesture);
            document.removeEventListener('gesturechange', noGesture);
            document.documentElement.classList.remove('wp-no-page-zoom');
            editor.destroy();
            root.textContent = '';
        },
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
