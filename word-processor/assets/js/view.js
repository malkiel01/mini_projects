/**
 * כלי תצוגה (שלב 2): ספירת מילים, סימני פסקה (¶ · →), חלונית ניווט (הכותרות), ואפשרויות הדבקה.
 * סימני הפסקה הם קישוטים (decorations) בלי רוחב — לא משנים את השבירה לשורות ולעמודים, ולא מודפסים.
 */
import { Extension, Plugin, PluginKey, Decoration, DecorationSet } from '../../vendor/tiptap.js';

// ---------- ספירת מילים ----------

/** מילים, תווים (עם רווחים ובלי), פסקאות — במסמך כולו או בטווח. כמו "ספירת מילים" ב-Word: הטקסט של הערות השוליים לא נספר. */
export function wordStats(doc, from = 0, to = doc.content.size) {
    let paragraphs = 0;
    doc.nodesBetween(from, to, n => { if (n.isTextblock) { if (n.textContent.trim()) paragraphs++; return false; } return true; });
    const text = doc.textBetween(from, to, '\n', ' ');
    return {
        words: (text.match(/[^\s]+/g) || []).length,
        chars: text.replace(/\n/g, '').length,
        charsNoSpaces: text.replace(/\s/g, '').length,
        paragraphs,
    };
}

// ---------- סימני פסקה ----------

export const marksKey = new PluginKey('wpMarks');

/** ¶ בסוף כל פסקה, · על רווח, → על טאב. setMarks(on) — הפעלה; marksKey.getState(state) — האם מוצגים. */
export const ParagraphMarks = Extension.create({
    name: 'wpParagraphMarks',
    addProseMirrorPlugins() {
        const build = doc => {
            const out = [];
            doc.descendants((n, pos) => {
                if (n.isTextblock) {
                    out.push(Decoration.widget(pos + 1 + n.content.size, () => {
                        const s = document.createElement('span');
                        s.className = 'wp-mk-p';
                        s.setAttribute('aria-hidden', 'true');
                        return s;
                    }, { side: 1, key: 'p', ignoreSelection: true }));
                    n.forEach((c, off) => {
                        if (!c.isText) return;
                        const base = pos + 1 + off;
                        for (let i = 0; i < c.text.length; i++) {
                            const ch = c.text[i];
                            if (ch === ' ' || ch === '\t' || ch === ' ') out.push(Decoration.inline(base + i, base + i + 1, { class: ch === '\t' ? 'wp-mk-tab' : 'wp-mk-sp' }));
                        }
                    });
                    return false;
                }
                return true;
            });
            return DecorationSet.create(doc, out);
        };
        return [new Plugin({
            key: marksKey,
            state: {
                init: () => ({ on: false, set: DecorationSet.empty }),
                apply(tr, st, old, state) {
                    const m = tr.getMeta(marksKey);
                    const on = m === undefined ? st.on : m;
                    if (!on) return { on, set: DecorationSet.empty };
                    return { on, set: m !== undefined || tr.docChanged ? build(state.doc) : st.set };
                },
            },
            props: { decorations: state => marksKey.getState(state).set },
        })];
    },
    addCommands() {
        return {
            setMarks: on => ({ tr, dispatch }) => { if (dispatch) tr.setMeta(marksKey, !!on).setMeta('addToHistory', false); return true; },
        };
    },
});

// ---------- חלונית ניווט ----------

/**
 * חלונית ניווט: הכותרות של המסמך (מוזחות לפי הרמה), לחיצה — מעבר; הכותרת שבה הסמן — מסומנת.
 * opts: { editor, h, onClose }. מחזיר { el, open(), close(), isOpen(), refresh() }.
 */
export function createNavPane({ editor, h, onClose }) {
    const list = h('ul.nav-list', { role: 'list' });
    const empty = h('p.nav-empty', 'אין כותרות במסמך. כותרות (כותרת 1, 2, 3…) יופיעו כאן לניווט מהיר.');
    const el = h('aside.wp-nav', { 'aria-label': 'ניווט', hidden: true },
        h('div.nav-head', h('strong', 'ניווט'), h('button.nav-close', { type: 'button', 'aria-label': 'סגירת חלונית הניווט', title: 'סגירה', onclick: () => { close(); onClose?.(); } }, '✕')),
        list, empty);
    let timer = 0;
    let items = [];
    function paint() {
        items = [];
        editor.state.doc.descendants((n, pos) => {
            if (n.type.name === 'heading') items.push({ pos, level: n.attrs.level, text: n.textContent.trim() || '(כותרת ריקה)' });
            return !n.isTextblock;
        });
        list.textContent = '';
        for (const it of items) {
            list.append(h('li', h('button.nav-item', { type: 'button', style: { paddingInlineStart: (it.level - 1) * 14 + 8 + 'px' }, 'data-pos': it.pos,
                onclick: () => {
                    editor.chain().setTextSelection(it.pos + 1).run();
                    editor.view.focus();
                    requestAnimationFrame(() => editor.view.nodeDOM(it.pos)?.scrollIntoView?.({ block: 'start' }));
                } }, it.text)));
        }
        empty.hidden = items.length > 0;
        mark();
    }
    /** הכותרת שהסמן אחריה (האחרונה לפניו) — מסומנת. */
    function mark() {
        const at = editor.state.selection.from;
        let cur = -1;
        items.forEach((it, i) => { if (it.pos < at) cur = i; });
        [...list.querySelectorAll('.nav-item')].forEach((b, i) => { b.classList.toggle('on', i === cur); b.setAttribute('aria-current', i === cur ? 'location' : 'false'); });
    }
    editor.on('update', () => { if (el.hidden) return; clearTimeout(timer); timer = setTimeout(paint, 250); });
    editor.on('selectionUpdate', () => { if (!el.hidden) mark(); });
    const open = () => { el.hidden = false; paint(); };
    const close = () => { el.hidden = true; };
    return { el, open, close, isOpen: () => !el.hidden, refresh: paint };
}

// ---------- אפשרויות הדבקה ----------

/**
 * אחרי הדבקה עם עיצוב — כפתור קטן ליד הסמן (כמו ב-Word): שמירת העיצוב המקורי / מיזוג עיצוב (בלי גופן, גודל,
 * צבע והדגשת צבע — המודגש, הנטוי, הקו והקישורים נשארים) / טקסט בלבד. Ctrl+Shift+V — הדבקה כטקסט ישר.
 * opts: { editor, h, host }. מחזיר { el, onPaste(event) } — onPaste נקרא מ-handlePaste (לפני ההדבקה).
 */
export function createPasteOptions({ editor, h, host }) {
    let last = null;   // { from, to, text } — הטווח שהודבק (ממופה הלאה) והטקסט הפשוט שהיה בלוח
    let pending = null;
    const menu = h('div.paste-menu', { role: 'menu', hidden: true },
        h('button', { type: 'button', role: 'menuitem', onclick: () => apply('keep') }, '📋 שמירת העיצוב המקורי'),
        h('button', { type: 'button', role: 'menuitem', onclick: () => apply('merge') }, '🖌 מיזוג עיצוב'),
        h('button', { type: 'button', role: 'menuitem', onclick: () => apply('text') }, 'A טקסט בלבד'));
    const chip = h('button.paste-chip', { type: 'button', 'aria-haspopup': 'menu', 'aria-label': 'אפשרויות הדבקה', title: 'אפשרויות הדבקה (Ctrl+Shift+V — כטקסט בלבד)',
        onclick: () => { menu.hidden = !menu.hidden; chip.setAttribute('aria-expanded', String(!menu.hidden)); } }, '📋▾');
    const el = h('div.wp-paste', { hidden: true }, chip, menu);
    host.append(el);

    function hide() { el.hidden = true; menu.hidden = true; last = null; }
    function place() {
        if (!last) return;
        try {
            const c = editor.view.coordsAtPos(last.to);
            const r = host.getBoundingClientRect();
            el.style.top = c.bottom - r.top + 4 + 'px';
            el.style.insetInlineStart = '';
            el.style.left = Math.max(4, Math.min(r.width - 60, c.left - r.left)) + 'px';
        } catch { hide(); }
    }
    function apply(kind) {
        menu.hidden = true;
        if (!last || kind === 'keep') { if (kind === 'keep') hide(); return; }
        const { from, to, text } = last;
        if (kind === 'text') {
            const paras = text.replace(/\r/g, '').split('\n');
            if (paras.length > 1) editor.chain().focus().insertContentAt({ from, to }, paras.map(t => ({ type: 'paragraph', content: t ? [{ type: 'text', text: t }] : [] }))).run();
            else {
                // insertText יורש את העיצוב שליד — כאן במפורש טקסט בלי שום עיצוב
                const tr = editor.state.tr;
                if (text) tr.replaceWith(from, to, editor.schema.text(text)); else tr.delete(from, to);
                editor.view.dispatch(tr.setStoredMarks([]));
                editor.view.focus();
            }
        } else {
            const sc = editor.schema.marks;
            const tr = editor.state.tr;
            for (const m of [sc.textStyle, sc.highlight].filter(Boolean)) tr.removeMark(from, to, m);
            editor.view.dispatch(tr);
            editor.view.focus();
        }
        hide();
    }
    editor.on('transaction', ({ transaction: tr }) => {
        if (pending && tr.getMeta('paste') && tr.docChanged) {
            const from = tr.mapping.map(pending.from, -1);
            const to = tr.selection.to;
            // רק הדבקה עם עיצוב (HTML) — טקסט פשוט אין מה לשנות
            last = pending.html && to > from ? { from, to, text: pending.text } : null;
            pending = null;
            el.hidden = !last;
            if (last) requestAnimationFrame(place);
            return;
        }
        if (!last) return;
        if (tr.docChanged) hide();   // כל שינוי אחר — הכפתור נעלם (כמו ב-Word, כשממשיכים להקליד)
    });
    host.addEventListener('keydown', e => { if (e.key === 'Escape' && !el.hidden) hide(); }, true);
    return {
        el,
        onPaste(e) {
            const cd = e.clipboardData;
            pending = { from: editor.state.selection.from, html: !!cd?.getData('text/html'), text: cd?.getData('text/plain') || '' };
        },
    };
}
