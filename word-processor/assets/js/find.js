/**
 * חיפוש והחלפה (Ctrl+F / Ctrl+H), כמו ב-Word: כל ההתאמות מסומנות במסמך, "3 מתוך 12", הבא / הקודם, החלפה
 * והחלפת הכול (צעד ביטול אחד). אפשרויות: התאמת רישיות, מילים שלמות, התעלמות מניקוד (ברירת מחדל — "שלום" מוצא
 * גם "שָׁלוֹם"), ביטוי רגולרי ($1 בהחלפה).
 *
 * החיפוש בתוך כל פסקה (גם מעבר לגבולות עיצוב — "של**ום**" נמצא); תמונה / הערת שוליים בתוך פסקה = תו אחד שלא
 * מתאים לטקסט. גם בתוך הערות השוליים והסיום (ביקורת 04, R10 ד — כמו ב-Word): התאמה בהערה במקום הסימן שלה (note:
 * המיקום בטקסט ההערה), מסומנת על הסימן; החלפה משנה את טקסט ההערה עצמו (עם העיצוב שלה).
 */
import { Extension, Plugin, PluginKey, Decoration, DecorationSet, TextSelection, NodeSelection } from '../../vendor/tiptap.js';
import { matchText, newBudget, SLOW } from './find-core.js';

export const findKey = new PluginKey('wpFind');

// ניקוד וטעמים — בלי מקף (05BE), פסק (05C0), סוף פסוק (05C3) ונון הפוכה (05C6), שהם סימני פיסוק
const NIQQUD = /[֑-ׇֽֿׁׂׅׄ]/;
const NIQQUD_G = new RegExp(NIQQUD.source, 'g');
const escapeRe = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * כל ההתאמות במסמך: [{from, to, groups}]. opts: matchCase, wholeWord, ignoreNiqqud (ברירת מחדל true), regex.
 * ביטוי רגולרי שגוי → { error }.
 */
export function findMatches(doc, query, opts = {}) {
    if (!query) return [];
    const strip = opts.ignoreNiqqud !== false;
    let re;
    try {
        const q = strip ? query.replace(NIQQUD_G, '') : query;
        if (!q) return [];
        re = new RegExp(opts.regex ? q : escapeRe(q), 'gu' + (opts.matchCase ? '' : 'i'));
    } catch (e) {
        return { error: 'ביטוי רגולרי לא תקין' };
    }
    const out = [];
    const budget = newBudget();
    doc.descendants((node, pos) => {
        if (budget.stopped) return false;
        if (!node.isTextblock) return true;
        // הטקסט של הפסקה, ולכל תו — המיקום שלו במסמך (ניקוד שהושמט — לא נכנס לטקסט, אבל נשאר בטווח ההתאמה)
        let text = '';
        const at = [];
        const notes = [];
        node.forEach((child, off) => {
            const p = pos + 1 + off;
            if (child.type.name === 'footnote') notes.push([p, child]);
            if (!child.isText) { text += '￼'; at.push(p); return; }
            for (let i = 0; i < child.text.length; i++) {
                const ch = child.text[i];
                if (strip && NIQQUD.test(ch)) continue;
                text += ch;
                at.push(p + i);
            }
        });
        const end = pos + 1 + node.content.size;
        const posAt = i => (i < at.length ? at[i] : end);
        const found = matchText(text, re, opts.wholeWord, budget).map(x => ({ from: at[x.a], to: posAt(x.b), groups: x.groups, text: x.text }));
        // ההערות שבפסקה: כל התאמה — במקום הסימן (from–to הוא הסימן), note: הטווח בטקסט ההערה (במקור — עם ניקוד)
        for (const [p, n] of notes) {
            const src = n.attrs.text || '';
            let t = '';
            const idx = [];
            for (let i = 0; i < src.length; i++) { if (strip && NIQQUD.test(src[i])) continue; t += src[i]; idx.push(i); }
            for (const x of matchText(t, re, opts.wholeWord, budget)) {
                found.push({ from: p, to: p + 1, groups: x.groups, text: x.text, note: { a: idx[x.a], b: x.b < idx.length ? idx[x.b] : src.length, end: !!n.attrs.end } });
            }
        }
        found.sort((x, y) => x.from - y.from || (x.note ? 1 : 0) - (y.note ? 1 : 0));
        out.push(...found);
        return false;
    });
    // נעצר באמצע — לא מציגים חצי תוצאות כאילו זה הכול
    if (budget.stopped) return { error: SLOW };
    return out;
}

/**
 * החלפה בתוך הערה: attrs חדשים — text, ו-content (ריצות עם עיצוב): הטקסט החדש נכנס לריצה שבה מתחילה ההתאמה,
 * ומה שההתאמה תפסה בריצות שאחריה — נמחק. ריצה שהתרוקנה — יוצאת.
 */
export function replaceInNote(attrs, a, b, str) {
    const text = (attrs.text || '').slice(0, a) + str + (attrs.text || '').slice(b);
    if (!Array.isArray(attrs.content)) return { ...attrs, text };
    let off = 0;
    const content = [];
    for (const r of attrs.content) {
        if (r?.type !== 'text' || !r.text) continue;
        const s = off, e = off + r.text.length;
        off = e;
        if (e <= a || s >= b) { content.push(r); continue; }
        const keep = r.text.slice(0, Math.max(0, a - s)) + (a >= s && a < e ? str : '') + r.text.slice(Math.max(0, Math.min(e, b) - s));
        if (keep) content.push({ ...r, text: keep });
    }
    return { ...attrs, text, content: content.length ? content : null };
}

/** טקסט ההחלפה: בביטוי רגולרי $1..$9 ו-$& מוחלפים בקבוצות; אחרת — כמו שהוא. */
export function replacementFor(match, replace, opts = {}) {
    if (!opts.regex) return replace;
    return replace.replace(/\$(\$|&|\d)/g, (s, k) => k === '$' ? '$' : k === '&' ? match.text : (match.groups[+k - 1] ?? ''));
}

const EMPTY = { query: '', opts: {}, matches: [], index: -1, error: null };

/** ההתאמה הראשונה מהמיקום והלאה (מעגלית). */
const nearest = (matches, pos) => {
    if (!matches.length) return -1;
    const i = matches.findIndex(m => m.from >= pos);
    return i < 0 ? 0 : i;
};

export const FindReplace = Extension.create({
    name: 'wpFind',
    addProseMirrorPlugins() {
        return [new Plugin({
            key: findKey,
            state: {
                init: () => EMPTY,
                apply(tr, st, old, state) {
                    const meta = tr.getMeta(findKey);
                    if (meta && 'query' in meta) {
                        if (!meta.query) return EMPTY;
                        const r = findMatches(state.doc, meta.query, meta.opts);
                        const matches = Array.isArray(r) ? r : [];
                        return { query: meta.query, opts: meta.opts || {}, matches, error: r.error || null,
                            index: nearest(matches, meta.from ?? state.selection.from) };
                    }
                    if (meta && 'index' in meta) return { ...st, index: meta.index };
                    if (!st.query || !tr.docChanged) return st;
                    // אחרי החלפה — ממשיכים מסוף הטקסט החדש (גם אם הוא עצמו מכיל את החיפוש)
                    // המסמך השתנה — מחפשים מחדש; ההתאמה הנוכחית — הקרובה למקום שבו הייתה
                    const r = findMatches(state.doc, st.query, st.opts);
                    const matches = Array.isArray(r) ? r : [];
                    const was = st.matches[st.index];
                    const from = meta?.after ?? (was ? tr.mapping.map(was.from, -1) : state.selection.from);
                    return { ...st, matches, index: nearest(matches, from) };
                },
            },
            props: {
                decorations(state) {
                    const st = findKey.getState(state);
                    if (!st.matches.length) return null;
                    // התאמה בהערה — על הסימן שלה (כמה התאמות באותה הערה: הסימן מסומן פעם אחת, כנוכחי אם אחת מהן נוכחית)
                    const cur = st.matches[st.index];
                    const seen = new Set();
                    return DecorationSet.create(state.doc, st.matches.flatMap((m, i) => {
                        if (!m.note) return [Decoration.inline(m.from, m.to, { class: i === st.index ? 'wp-find wp-find-cur' : 'wp-find' })];
                        if (seen.has(m.from)) return [];
                        seen.add(m.from);
                        return [Decoration.node(m.from, m.to, { class: cur?.note && cur.from === m.from ? 'wp-find wp-find-cur' : 'wp-find' })];
                    }));
                },
            },
        })];
    },
    addCommands() {
        // כל פקודה עובדת על tr של TipTap (ולא שולחת עסקה בעצמה) — אחרת שתי עסקאות על אותו מצב
        const select = (tr, i, m) => tr.setMeta(findKey, { index: i })
            .setSelection(m.note ? NodeSelection.create(tr.doc, m.from) : TextSelection.create(tr.doc, m.from, m.to));
        /** מחליף התאמה אחת ב-tr (בהערה — את הטקסט שלה, בלי לגעת במסמך מסביב). מחזיר את אורך הטקסט החדש במסמך. */
        const put = (tr, m, text) => {
            if (!m.note) { tr.insertText(text, m.from, m.to); return text.length; }
            const n = tr.doc.nodeAt(m.from);
            if (n?.type.name === 'footnote') tr.setNodeMarkup(m.from, undefined, replaceInNote(n.attrs, m.note.a, m.note.b, text)).setMeta('closeHistory$', true);
            return 1;
        };
        const go = dir => ({ state, tr, dispatch }) => {
            const st = findKey.getState(state);
            if (!st.matches.length) return false;
            const i = st.index < 0 ? 0 : (st.index + dir + st.matches.length) % st.matches.length;
            if (dispatch) select(tr, i, st.matches[i]);
            return true;
        };
        return {
            setFind: (query, opts = {}) => ({ tr, dispatch }) => {
                if (dispatch) tr.setMeta(findKey, { query, opts, from: tr.selection.from }).setMeta('addToHistory', false);
                return true;
            },
            findNext: () => go(1),
            findPrev: () => go(-1),
            /** בוחר את ההתאמה הנוכחית (אחרי החלפה — זו שאחריה). */
            findSelect: () => ({ state, tr, dispatch }) => {
                const st = findKey.getState(state);
                const m = st.matches[st.index];
                if (!m) return false;
                if (dispatch) select(tr, st.index, m);
                return true;
            },
            replaceCurrent: replace => ({ state, tr, dispatch }) => {
                const st = findKey.getState(state);
                const m = st.matches[st.index];
                if (!m) return false;
                const text = replacementFor(m, replace, st.opts);
                if (dispatch) tr.setMeta(findKey, { after: m.from + put(tr, m, text) });
                return true;
            },
            /** מחליף את כל ההתאמות בעסקה אחת — Ctrl+Z אחד מבטל הכול. */
            replaceAll: replace => ({ state, tr, dispatch }) => {
                const st = findKey.getState(state);
                if (!st.matches.length) return false;
                if (dispatch) for (let i = st.matches.length - 1; i >= 0; i--) {
                    const m = st.matches[i];
                    put(tr, m, replacementFor(m, replace, st.opts));
                }
                return true;
            },
        };
    },
});

/**
 * פס החיפוש וההחלפה. opts: { editor, notify(msg) }. מחזיר { el, open(mode: 'find'|'replace'), close(), isOpen }.
 */
export function createFindBar(h, { editor, notify }) {
    const st = () => findKey.getState(editor.state);
    const opt = { matchCase: false, wholeWord: false, ignoreNiqqud: true, regex: false };
    const findIn = h('input.find-in', { type: 'search', placeholder: 'חיפוש במסמך', 'aria-label': 'חיפוש במסמך', autocomplete: 'off', spellcheck: 'false' });
    const replIn = h('input.repl-in', { type: 'text', placeholder: 'החלפה ב…', 'aria-label': 'החלפה ב', autocomplete: 'off', spellcheck: 'false' });
    const count = h('span.find-count', { role: 'status', 'aria-live': 'polite' });
    const btn = (label, icon, onclick, cls = '') => h('button.find-btn' + cls, { type: 'button', 'aria-label': label, title: label, onclick }, icon);
    const prev = btn('הקודם (Shift+Enter)', '▲', () => move(-1));
    const next = btn('הבא (Enter)', '▼', () => move(1));
    const toggleRepl = btn('החלפה (Ctrl+H)', '⇄', () => setMode(replRow.hidden ? 'replace' : 'find'), '.find-toggle');
    const toggleOpts = btn('אפשרויות חיפוש', '⚙', () => { optRow.hidden = !optRow.hidden; toggleOpts.setAttribute('aria-expanded', String(!optRow.hidden)); });
    toggleOpts.setAttribute('aria-expanded', 'false');
    const closeBtn = btn('סגירת החיפוש (Escape)', '✕', () => close(true));
    const replOne = h('button.btn.sm', { type: 'button', onclick: () => replace(false) }, 'החלפה');
    const replAll = h('button.btn.sm', { type: 'button', onclick: () => replace(true) }, 'החלף הכול');
    const check = (key, label) => h('label.find-opt', h('input', { type: 'checkbox', checked: opt[key], onchange: e => { opt[key] = e.target.checked; search(); } }), label);
    const optRow = h('div.find-row.find-opts', { hidden: true },
        check('matchCase', 'התאמת רישיות'), check('wholeWord', 'מילים שלמות בלבד'), check('ignoreNiqqud', 'התעלמות מניקוד'), check('regex', 'ביטוי רגולרי'));
    const replRow = h('div.find-row.find-repl', { hidden: true }, replIn, replOne, replAll);
    const el = h('div.wp-findbar', { role: 'search', 'aria-label': 'חיפוש והחלפה', hidden: true },
        h('div.find-row', findIn, count, prev, next, toggleRepl, toggleOpts, closeBtn), replRow, optRow);

    function paint() {
        const s = st();
        const n = s.matches.length;
        const inNote = s.matches[s.index]?.note;
        count.textContent = preErr || (s.error ? s.error : !s.query ? '' : n ? `${s.index + 1} מתוך ${n}${inNote ? (inNote.end ? ' · בהערת סיום' : ' · בהערת שוליים') : ''}` : 'אין תוצאות');
        count.classList.toggle('none', !!preErr || (!!s.query && !n));
        prev.disabled = next.disabled = !n;
        const can = editor.isEditable;
        replOne.disabled = replAll.disabled = !n || !can;
        toggleRepl.hidden = !can;
        if (!can && !replRow.hidden) replRow.hidden = true;
    }
    function reveal() {
        requestAnimationFrame(() => editor.view.dom.querySelector('.wp-find-cur')?.scrollIntoView({ block: 'center', inline: 'nearest' }));
    }
    // ביטוי רגולרי — קודם בדיקה ב-Worker (find-worker.js) עם זמן קצוב: ביטוי שנתקע לא מגיע לדף ולא מקפיא את העורך
    let gen = 0;
    let preErr = null;
    const PRECHECK_MS = 1500;
    function precheck(query) {
        const strip = opt.ignoreNiqqud !== false;
        const source = strip ? query.replace(NIQQUD_G, '') : query;
        const texts = [];
        const add = t => texts.push(strip ? t.replace(NIQQUD_G, '') : t);
        editor.state.doc.descendants(n => {
            if (!n.isTextblock) return true;
            add(n.textContent);
            n.forEach(c => { if (c.type.name === 'footnote') add(c.attrs.text || ''); });   // גם ההערות — אותו תקציב
            return false;
        });
        return new Promise(resolve => {
            let w;
            try { w = new Worker(new URL('./find-worker.js', import.meta.url), { type: 'module' }); } catch { resolve({ ok: true }); return; }
            const timer = setTimeout(() => { w.terminate(); resolve({ error: 'החיפוש בוטל: הביטוי הרגולרי איטי מדי' }); }, PRECHECK_MS);
            w.onmessage = e => { clearTimeout(timer); w.terminate(); resolve(e.data.stopped ? { error: 'החיפוש בוטל: הביטוי איטי מדי או מחזיר יותר מדי התאמות' } : e.data); };
            w.onerror = () => { clearTimeout(timer); w.terminate(); resolve({ ok: true }); };
            w.postMessage({ source, flags: 'gu' + (opt.matchCase ? '' : 'i'), wholeWord: opt.wholeWord, texts });
        });
    }
    async function search() {
        const my = ++gen;
        preErr = null;
        if (opt.regex && findIn.value) {
            const r = await precheck(findIn.value);
            if (my !== gen) return;   // הוקלד משהו חדש בינתיים
            if (r.error) { preErr = r.error; editor.commands.setFind('', { ...opt }); paint(); return; }
        }
        editor.commands.setFind(findIn.value, { ...opt });
        paint();
        if (st().matches.length) reveal();
    }
    function move(dir) {
        if (dir > 0 ? editor.commands.findNext() : editor.commands.findPrev()) reveal();
        paint();
    }
    function replace(all) {
        if (!editor.isEditable) return;
        if (all) {
            const n = st().matches.length;
            if (editor.commands.replaceAll(replIn.value)) notify?.(`הוחלפו ${n} ${n === 1 ? 'התאמה' : 'התאמות'}`);
            findIn.focus();   // הכפתורים נעשים לא פעילים (אין עוד התאמות) — המיקוד לא נופל לגוף הדף
        } else if (editor.commands.replaceCurrent(replIn.value)) { editor.commands.findSelect(); reveal(); }
        paint();
    }
    function setMode(mode) {
        replRow.hidden = mode !== 'replace' || !editor.isEditable;
        toggleRepl.setAttribute('aria-expanded', String(!replRow.hidden));
        toggleRepl.classList.toggle('on', !replRow.hidden);
    }
    function open(mode = 'find') {
        // הבחירה הנוכחית (שורה אחת, קצרה) — נכנסת לחיפוש, כמו ב-Word
        // (רק בפתיחה — כשהפס כבר פתוח, Ctrl+H רק עובר להחלפה ולא דורס את החיפוש)
        const { from, to, empty } = editor.state.selection;
        const sel = empty || !el.hidden ? '' : editor.state.doc.textBetween(from, to, '\n');
        if (sel && sel.length <= 120 && !sel.includes('\n')) findIn.value = sel;
        el.hidden = false;
        setMode(mode);
        (mode === 'replace' && findIn.value ? replIn : findIn).focus();
        findIn.select();
        search();
    }
    function close(focusDoc) {
        if (el.hidden) return;
        el.hidden = true;
        const m = st().matches[st().index];
        editor.commands.setFind('');
        if (focusDoc) {
            if (m) editor.commands.setTextSelection({ from: m.from, to: m.to });
            // מיד (focus של TipTap מחכה פריים — Ctrl+Z שמגיע מהר אחרי Escape היה הולך לאיבוד)
            editor.view.focus();
        }
    }
    findIn.addEventListener('input', search);
    const keys = e => {
        if (e.key === 'Enter') { e.preventDefault(); if (e.target === replIn && !e.shiftKey) replace(e.ctrlKey || e.metaKey); else move(e.shiftKey ? -1 : 1); }
    };
    findIn.addEventListener('keydown', keys);
    replIn.addEventListener('keydown', keys);
    // Escape — מכל מקום בפס (גם אחרי לחיצה על כפתור)
    el.addEventListener('keydown', e => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); } });
    editor.on('transaction', () => { if (!el.hidden) paint(); });
    return { el, open, close, isOpen: () => !el.hidden };
}
