/**
 * חלונות העמוד — הגדרות עמוד (גודל, כיוון, שוליים, כותרות, מספור, צבע וסימן מים; לפי מקטע), העורך הקטן של כותרת
 * עליונה / תחתונה, ושמירת ההגדרות במקטעים. הוצאו מ-editor.js (F20 — הפרדת מודולים) כמו שהם; הגדרות העמוד
 * מתחלפות — getPage() במקום page.
 */
import { Editor, Node as TNode, StarterKit, TextStyle, FontSize, TextAlign } from '../../vendor/tiptap.js';
import { WpTable, WpTableRow, Cell, HeaderCell } from './extensions.js';
import { h, toast, sheet, field, seg, sw, numInput, fontSelect, colorField } from './ui.js';

export function createPageDialogs({ editor, opts, getPage, sections, setPage, mergePage, MM, NUM_FORMATS }) {
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
        const nb = getPage().numbering;
        const wmInput = h('input', { name: 'watermark', value: getPage().watermark || '', maxlength: 40 });
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
                    h('div.fields.two', field('גופן', fontSelect('font', getPage().font.family)), field('גודל', numInput('fsize', getPage().font.size, { min: 6, max: 72 })))),
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
                    sw('even_odd', 'זוגי ואי-זוגי שונים (לכל המסמך)', getPage().even_odd),
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
                    sw('pc_on', 'צבע עמוד', !!getPage().page_color),
                    colorField('הצבע', 'page_color', getPage().page_color || '#fdf6e3'),
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
        const first = mergePage(getPage(), { ...(k === null || k === 0 ? sec : {}), ...whole });
        if (JSON.stringify(first) !== JSON.stringify(getPage())) tr.setDocAttribute('page', first);
        secs.forEach((x, i) => {
            if (!i) return;
            const want = k === null ? {} : pick(i === k ? mergePage(x.page, sec) : x.page);
            if (JSON.stringify(want) !== JSON.stringify(editor.state.doc.nodeAt(x.from).attrs.page || {})) tr.setNodeAttribute(x.from, 'page', want);
        });
        if (tr.docChanged || tr.steps.length) editor.view.dispatch(tr);
    }

    return { hfEditor, pageDialog, setSections };
}
