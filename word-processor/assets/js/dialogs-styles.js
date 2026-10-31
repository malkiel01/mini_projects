/**
 * חלונות הסגנונות והגרסאות — הגלריה וחלון הסגנונות (עריכה, חדש, עדכון מהבחירה) וחלון הגרסאות (צפייה, השוואה,
 * שחזור). הוצאו מ-editor.js (F20 — הפרדת מודולים) כמו שהם; הגדרות העמוד מתחלפות — getPage() במקום page.
 */
import { USER_STYLE, styleKeyOf } from './extensions.js';
import { migrate } from './schema.js';
import { h, toast, sheet, field, sw, numInput, fontSelect, when } from './ui.js';
import { confirm } from './ui-core/dialog.js';

export function createStyleDialogs({ editor, opts, getPage, mergePage, STYLE_NAMES, styleName, userKeys, styleSel }) {
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
        const tr = editor.state.tr.setDocAttribute('page', mergePage(getPage(), { styles: patch }));
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
            while (getPage().styles['u' + n]) n++;
            const base = { ...(sel.key === 'normal' || USER_STYLE.test(sel.key) ? {} : getPage().styles[sel.key]), ...(USER_STYLE.test(sel.key) ? getPage().styles[sel.key] : {}) };
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
        const work = structuredClone(getPage().styles);
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
            const tr = editor.state.tr.setDocAttribute('page', mergePage(getPage(), { styles: work }, true));
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
            let r;
            try {
                if (!(r = await opts.versions.compare(v.id))) return;
            } catch (e) { return toast(e.message || 'ההשוואה נכשלה', true); }
            document.querySelector('dialog[open]')?.close();
            // שינויים במעקב שעוד לא אושרו — ההשוואה כאילו אושרו (כמו ב-Word); המסמך עצמו לא השתנה
            toast(r.pending ? 'נפתח מסמך השוואה — כאילו כל השינויים במעקב אושרו (כמו ב-Word). אשרו או דחו כל הבדל'
                : 'נפתח מסמך השוואה — אשרו או דחו כל שינוי');
        }
        async function restore(v, current) {
            if (current) return toast('זו הגרסה הנוכחית');
            if (!editor.isEditable) return toast('צפייה בלבד — אי אפשר לשחזר', true);
            if (!await confirm(`לשחזר את הגרסה מ-${when(v.saved_at)}? המצב הנוכחי יישמר כגרסה.`, { ok: 'שחזור' })) return;
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
            editor.commands.setContent(migrate(d.json, d.page || getPage()), { emitUpdate: true });
            document.querySelector('dialog[open]')?.close();
            toast('הגרסה שוחזרה');
        }
    }

    return { selectionFormat, applyStyleChange, newStyleDialog, updateStyleFromSelection, stylesDialog, versionsDialog };
}
