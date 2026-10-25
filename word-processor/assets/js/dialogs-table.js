/**
 * הטבלה והתמונה בלשונית ההקשר — סגנון טבלה מוכן, מאפייני טבלה, וגודל תמונה (¼, ½, מלא, מקורי). הוצאו מ-editor.js
 * (F20 — הפרדת מודולים) כמו שהם; createTableTools נקרא פעם אחת בתוך createWordEditor. הלשונית עצמה (contextBar)
 * נשארת בעורך — היא חלק מהסרט.
 */
import { imageBox, TABLE_BORDERS, VALIGNS } from './extensions.js';
import { h, sheet, field, seg, numInput } from './ui.js';

export function createTableTools({ editor, chain, TABLE_STYLES, ctxSelect }) {
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
        const sel = ctxSelect();
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
        const sel = editor.state.selection;
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

    return { applyTableStyle, tablePropsDialog, imageSizeTo };
}
