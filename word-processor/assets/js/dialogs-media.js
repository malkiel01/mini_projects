/**
 * חלונות התמונה וההערות — חיתוך, הערת שוליים / סיום (עורך קטן עם עיצוב תווים), ומאפייני תמונה. הוצאו מ-editor.js
 * (F20 — הפרדת מודולים) כמו שהם; createMediaDialogs נקרא פעם אחת בתוך createWordEditor, ורושם גם את הלחיצה על
 * סימן הערה / הערה בתחתית העמוד (עריכה).
 */
import { Editor, Extension, StarterKit, Superscript, Subscript } from '../../vendor/tiptap.js';
import { imageBox } from './extensions.js';
import { h, sheet, field, seg, numInput } from './ui.js';

const FN_MARKS = ['bold', 'italic', 'underline', 'strike', 'superscript', 'subscript'];

export function createMediaDialogs({ editor, host, pagesLayer, ICONS }) {
    const ed = editor;
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
            dlg.addEventListener('close', () => {
                mini.destroy();
                // המיקוד חוזר לעורך עם הבחירה שלו, מיד (לפני הקשה הבאה): בזמן שהחלון פתוח העימוד יכול לצייר מחדש את הטקסט
                // שבו הייתה הבחירה של הדפדפן, ומקש חץ על בחירה בצומת שכבר לא בדף — שגיאה במנוע
                if (!ed.isDestroyed) ed.view.focus();
            }, { once: true });
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

    return { cropDialog, footnoteDialog, imageDialog };
}

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
