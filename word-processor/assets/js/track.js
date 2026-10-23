/**
 * מעקב שינויים (שלב 4) — כמו Track Changes ב-Word:
 * - כשהמעקב פועל (attrs.track של המסמך — נשמר איתו, כמו trackRevisions ב-Word), כל עריכה נרשמת במקום להתבצע:
 *   טקסט שנוסף — סימון ins; טקסט שנמחק — לא נמחק אלא מקבל סימון del (קו חוצה). לכל שינוי: מחבר ותאריך.
 * - מחיקה של טקסט שנוסף במעקב — מוחקת אותו באמת (כמו ב-Word). מחיקה שחוצה פסקאות — הטקסט מסומן, הפסקאות לא מתאחדות.
 * - קבלה / דחייה: של השינוי שבו הסמן (או של כל השינויים בבחירה), או של כולם. הבא / הקודם — מעבר בין שינויים.
 * - עיצוב תווים (מודגש, צבע, גודל…) — סימון fmt עם העיצוב הקודם (attrs.old), כמו rPrChange ב-Word: אישור — הסימון יוצא;
 *   דחייה — העיצוב הקודם חוזר. עיצוב של טקסט שנוסף במעקב — לא נרשם (הוא ממילא חדש).
 * - עיצוב פסקה (יישור, הזחה, ריווח, כיוון, עצירות, סגנון — כולל פסקה ↔ כותרת), כמו pPrChange ב-Word: attrs.pchg של
 *   הפסקה — מחבר, תאריך, והפסקה כפי שהייתה ({ type, attrs }); פס שינוי בשוליים. אישור — pchg יוצא; דחייה — הפסקה
 *   חוזרת להיות כמו שהייתה. חזרה לעיצוב המקורי — כבר לא שינוי. שינויי מבנה (פיצול, איחוד, טבלה) — לא נרשמים.
 * - תצוגה (VIEWS, כמו ב-Word): סימון מלא / סופי / מקור, והסתרת השינויים של מחברים — רק תצוגה (מחלקה ו-CSS על המסמך
 *   ב-editor.js), לא שינוי במסמך. ההדפסה וה-PDF (track_view בשרת) — באותה תצוגה.
 * הרישום: appendTransaction — העסקה של המשתמש מתבצעת, ומיד אחריה (באותו צעד ביטול) עסקה שמחזירה את מה שנמחק
 * עם del ומסמנת את מה שנוסף ב-ins.
 */
import { Extension, Mark, Plugin, PluginKey, TextSelection, Fragment, Slice } from '../../vendor/tiptap.js';

const SKIP = 'wpTrackSkip';    // עסקה שלא נרשמת (קבלה / דחייה, וטעינה)
const AUTHOR_COLORS = ['#c00000', '#2e75b6', '#538135', '#7030a0', '#c55a11', '#00808a'];
/** צבע קבוע לכל מחבר (כמו ב-Word — צבע לפי מחבר). */
export const authorColor = name => { let h = 0; for (const ch of name || '') h = (h * 31 + ch.codePointAt(0)) >>> 0; return AUTHOR_COLORS[h % AUTHOR_COLORS.length]; };

const changeMark = (name, cls) => Mark.create({
    name,
    inclusive: false,
    excludes: name === 'ins' ? 'ins del' : 'del ins',
    addAttributes() {
        return {
            author: { default: '', parseHTML: el => el.getAttribute('data-author') || '' },
            date: { default: '', parseHTML: el => el.getAttribute('data-date') || '' },
        };
    },
    parseHTML() {
        return [{ tag: `${name}[data-author]` }];
    },
    renderHTML({ HTMLAttributes: a }) {
        const t = new Date(a.date);
        return [name, { class: cls, 'data-author': a.author, 'data-date': a.date, style: `--wpc:${authorColor(a.author)}`,
            title: `${name === 'ins' ? 'נוסף' : 'נמחק'} — ${a.author}${isNaN(t) ? '' : ', ' + t.toLocaleString('he-IL')}` }, 0];
    },
});
export const InsMark = changeMark('ins', 'wp-ins');
export const DelMark = changeMark('del', 'wp-del');

/** סימוני העיצוב שנרשמים במעקב (לא קישור, הערה, תוספת / מחיקה). */
export const FORMAT_MARKS = new Set(['bold', 'italic', 'underline', 'strike', 'textStyle', 'highlight', 'superscript', 'subscript']);
const fmtOf = marks => marks.filter(m => FORMAT_MARKS.has(m.type.name)).map(m => m.toJSON());
const sameFmt = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/**
 * שינוי עיצוב במעקב: העיצוב הקודם ב-attrs.old (רשימת סימונים). בתצוגת המקור — העיצוב הקודם מוצג במקום הנוכחי:
 * ה-span עוטף את סימוני העיצוב (עדיפות גבוהה — חיצוני), מקבל את הקודם במשתנים (--o-*), וה-CSS מאפס את מה שבתוכו.
 */
export const FmtMark = Mark.create({
    name: 'fmt',
    priority: 2000,
    inclusive: false,
    addAttributes() {
        return {
            author: { default: '', parseHTML: el => el.getAttribute('data-author') || '' },
            date: { default: '', parseHTML: el => el.getAttribute('data-date') || '' },
            old: { default: [], parseHTML: el => { try { return JSON.parse(el.getAttribute('data-old') || '[]'); } catch { return []; } } },
        };
    },
    parseHTML() { return [{ tag: 'span.wp-fmt[data-author]' }]; },
    renderHTML({ HTMLAttributes: a }) {
        const old = Array.isArray(a.old) ? a.old : [];
        const has = t => old.find(m => m.type === t);
        const ts = has('textStyle')?.attrs || {};
        const v = [`--wpc:${authorColor(a.author)}`, `--o-fw:${has('bold') ? 700 : 400}`, `--o-fs:${has('italic') ? 'italic' : 'normal'}`,
            `--o-td:${[has('underline') && 'underline', has('strike') && 'line-through'].filter(Boolean).join(' ') || 'none'}`];
        if (ts.color) v.push(`--o-color:${ts.color}`);
        if (ts.fontSize) v.push(`--o-size:${ts.fontSize}`);
        if (ts.fontFamily) v.push(`--o-ff:${ts.fontFamily}`);
        if (has('highlight')) v.push(`--o-bg:${has('highlight').attrs?.color || '#ffff00'}`);
        const t = new Date(a.date);
        return ['span', { class: 'wp-fmt', 'data-author': a.author, 'data-date': a.date, 'data-old': JSON.stringify(old), style: v.join(';'),
            title: `עיצוב — ${a.author}${isNaN(t) ? '' : ', ' + t.toLocaleString('he-IL')}` }, 0];
    },
});

const now = () => new Date().toISOString().slice(0, 19) + 'Z';

const TEXTBLOCKS = ['paragraph', 'heading'];
/** הפסקה בלי סימון השינוי — מה שנשמר ב-pchg.old ומה שמשווים אליו. */
const paraOf = node => { const { pchg, ...attrs } = node.attrs; return { type: node.type.name, attrs }; };
const samePara = (a, b) => a.type === b.type && JSON.stringify(a.attrs) === JSON.stringify(b.attrs);
/** פסקה שכל התוכן שלה נוסף במעקב — חדשה; העיצוב שלה לא נרשם. */
const allIns = node => node.childCount > 0 && node.content.content.every(c => c.marks.some(m => m.type.name === 'ins'));

/** הטקסט בלי מה שנוסף במעקב (מחיקה של תוספת — מוחקת באמת), ועם del על כל השאר. */
function asDeleted(fragment, schema, attrs) {
    const out = [];
    fragment.forEach(node => {
        if (node.isText || node.isInline) {
            if (node.marks.some(m => m.type.name === 'ins')) return;
            // טקסט שכבר נמחק (Backspace שעובר עליו) — נשאר עם המחבר והתאריך שלו
            out.push(node.marks.some(m => m.type.name === 'del') ? node : node.mark(schema.marks.del.create(attrs).addToSet(node.marks)));
        } else {
            out.push(node.copy(asDeleted(node.content, schema, attrs)));
        }
    });
    return Fragment.fromArray(out);
}

export const trackKey = new PluginKey('wpTrack');

/** author — fn שמחזירה את שם מי שעובד עכשיו. */
export function trackPlugin(author) {
    return new Plugin({
        key: trackKey,
        appendTransaction(trs, oldState, state) {
            if (!state.doc.attrs.track) return null;
            const k0 = trs.findIndex(t => t.docChanged && !t.getMeta(SKIP) && !t.getMeta('history$') && !t.getMeta(trackKey));
            if (k0 < 0) return null;
            const tr0 = trs[k0];
            // עסקאות שתוספים אחרים הוסיפו אחרי העסקה של המשתמש — המיקומים עוברים גם דרכן
            const rest = trs.slice(k0 + 1);
            const after = (pos, assoc) => rest.reduce((p, t) => t.mapping.map(p, assoc), pos);
            const { schema } = state;
            const who = author() || 'אני';
            const attrs = { author: who, date: now() };
            const tr = state.tr.setMeta(trackKey, true);
            // שינוי עיצוב בלבד (כל הצעדים — הוספה / הסרה של סימון עיצוב): נרשם עם העיצוב שהיה (מהמסמך שלפני העסקה)
            const json = tr0.steps.map(s => s.toJSON());
            if (json.length && json.every(j => (j.stepType === 'addMark' || j.stepType === 'removeMark') && FORMAT_MARKS.has(j.mark?.type))) {
                const fmt = schema.marks.fmt;
                for (const j of json) {
                    oldState.doc.nodesBetween(j.from, j.to, (node, pos) => {
                        if (!node.isText) return true;
                        // טקסט שנוסף / נמחק במעקב — העיצוב שלו לא נרשם; שינוי עיצוב קודם — שומר את העיצוב המקורי
                        if (node.marks.some(m => m.type.name === 'ins' || m.type.name === 'del' || m.type.name === 'fmt')) return false;
                        const a = after(Math.max(j.from, pos), 1), b = after(Math.min(j.to, pos + node.nodeSize), -1);
                        if (a < b) tr.addMark(a, b, fmt.create({ ...attrs, old: fmtOf(node.marks) }));
                        return false;
                    });
                }
                // עיצוב שחזר להיות כמו המקורי — כבר לא שינוי
                const from = Math.min(...json.map(j => after(j.from, 1))), to = Math.max(...json.map(j => after(j.to, -1)));
                tr.doc.nodesBetween(from, to, (node, pos) => {
                    const m = node.isText && node.marks.find(x => x.type.name === 'fmt');
                    if (m && sameFmt(fmtOf(node.marks), m.attrs.old)) tr.removeMark(pos, pos + node.nodeSize, fmt);
                    return !node.isText;
                });
                return tr.docChanged ? tr : null;
            }
            // שינוי עיצוב פסקה בלבד (הטקסט והמבנה בלי שינוי — אותו גודל, צעדי מאפיינים / עטיפה): לכל פסקה שהשתנתה —
            // pchg עם הפסקה כמו שהייתה (או זו ששמורה כבר — המקור); חזרה למקור — pchg יוצא
            if (json.length && json.every(j => j.stepType === 'replaceAround' || j.stepType === 'attr') && oldState.doc.content.size === tr0.doc.content.size
                && oldState.doc.textContent === tr0.doc.textContent) {
                oldState.doc.descendants((old, pos) => {
                    if (!TEXTBLOCKS.includes(old.type.name)) return true;
                    const p = after(pos, 1);
                    const now_ = tr.doc.nodeAt(p);
                    if (!now_ || !TEXTBLOCKS.includes(now_.type.name) || allIns(now_)) return false;
                    const was = old.attrs.pchg?.old || paraOf(old);
                    const cur = paraOf(now_);
                    if (samePara(was, cur)) { if (now_.attrs.pchg) tr.setNodeMarkup(p, undefined, { ...now_.attrs, pchg: null }); }
                    else if (!samePara(paraOf(old), cur) || !now_.attrs.pchg) tr.setNodeMarkup(p, undefined, { ...now_.attrs, pchg: { ...(old.attrs.pchg || attrs), old: was } });
                    return false;
                });
                return tr.docChanged ? tr : null;
            }
            // כל צעד החלפה של המשתמש: הקטע שנמחק (ממסמך הביניים שלפניו) והקטע שנוסף — במיקומים של המסמך הסופי
            const ops = [];
            tr0.steps.forEach((step, i) => {
                const j = step.toJSON();
                if (j.stepType !== 'replace') return;
                const before = tr0.docs[i];
                const later = tr0.mapping.slice(i + 1);
                const del = step.from < step.to ? before.slice(step.from, step.to) : null;
                const insSize = step.slice.size;
                // Backspace: המחיקה נגמרת בסמן הקודם — הסמן יעמוד לפני הטקסט המחוק; Delete — אחריו (כמו ב-Word)
                const back = i === 0 && oldState.selection.empty && step.to === oldState.selection.head;
                ops.push({ at: after(later.map(step.from, -1), -1), insTo: after(later.map(step.from + insSize, 1), 1), del, insSize, back });
            });
            if (!ops.length) return null;
            let cursor = null;
            // מהסוף להתחלה — כדי שהמיקומים של הפעולות הקודמות לא יזוזו
            for (const op of ops.reverse()) {
                if (op.insSize) {
                    // מה שנוסף: ins (בטקסט ובצמתים שבשורה), עם אותם פרטים של תוספת צמודה של אותו מחבר
                    const prev = tr.doc.resolve(op.at).nodeBefore?.marks.find(m => m.type.name === 'ins' && m.attrs.author === who);
                    tr.removeMark(op.at, op.insTo, schema.marks.del);
                    tr.addMark(op.at, op.insTo, schema.marks.ins.create(prev ? prev.attrs : attrs));
                }
                if (op.del) {
                    const prevDel = tr.doc.resolve(op.at).nodeBefore?.marks.find(m => m.type.name === 'del' && m.attrs.author === who);
                    const content = asDeleted(op.del.content, schema, prevDel ? prevDel.attrs : attrs);
                    const slice = new Slice(content, op.del.openStart, op.del.openEnd);
                    if (slice.size) {
                        tr.replace(op.at, op.at, slice);
                        cursor = op.insSize ? null : (op.back ? op.at : op.at + slice.size);
                    }
                }
            }
            if (cursor !== null) tr.setSelection(TextSelection.create(tr.doc, Math.max(0, Math.min(cursor, tr.doc.content.size))));
            return tr.docChanged ? tr : null;
        },
    });
}

/**
 * Backspace / Delete במעקב (dir -1 / 1) — במקום המחיקה של הדפדפן: תו רגיל — מסומן כמחוק והסמן עובר אותו;
 * תו שנוסף במעקב — נמחק באמת; תו שכבר מחוק — הסמן רק עובר אותו. בבחירה — כל הבחירה כך.
 * בתחילת פסקה / בסופה — הסמן עובר לפסקה הסמוכה (איחוד פסקאות לא נרשם).
 */
function trackDelete(editor, dir, who) {
    const { state } = editor;
    const { schema } = state;
    let { from, to, empty } = state.selection;
    const tr = state.tr.setMeta(SKIP, true);
    if (empty) {
        const $p = state.selection.$from;
        const edge = dir < 0 ? $p.parentOffset === 0 : $p.parentOffset === $p.parent.content.size;
        if (edge) {
            const near = TextSelection.findFrom(state.doc.resolve(dir < 0 ? $p.before() : $p.after()), dir, true);
            if (near) editor.view.dispatch(tr.setSelection(near));
            return true;
        }
        [from, to] = dir < 0 ? [from - 1, from] : [from, from + 1];
    }
    const prev = state.doc.resolve(from).nodeBefore?.marks.find(m => m.type.name === 'del' && m.attrs.author === who);
    const del = schema.marks.del.create(prev ? prev.attrs : { author: who, date: now() });
    const kill = [];
    state.doc.nodesBetween(from, to, (node, pos) => {
        if (!node.isInline) return true;
        const a = Math.max(from, pos), b = Math.min(to, pos + node.nodeSize);
        if (node.marks.some(m => m.type.name === 'ins')) kill.push([a, b]);
        else if (!node.marks.some(m => m.type.name === 'del')) tr.addMark(a, b, del);
        return false;
    });
    for (const [a, b] of kill.reverse()) tr.delete(a, b);
    const m = tr.mapping;
    tr.setSelection(TextSelection.create(tr.doc, dir < 0 ? m.map(from, -1) : m.map(to, 1)));
    editor.view.dispatch(tr.scrollIntoView());
    return true;
}

/** ההרחבה בעורך: options.author — fn שמחזירה את שם המשתמש. */
export const TrackChanges = Extension.create({
    name: 'wpTrackChanges',
    priority: 1000,   // לפני Backspace / Delete של StarterKit
    addOptions() { return { author: () => '' }; },
    addProseMirrorPlugins() { return [trackPlugin(this.options.author)]; },
    addKeyboardShortcuts() {
        const on = () => !!this.editor.state.doc.attrs.track && this.editor.isEditable;
        const who = () => this.options.author() || 'אני';
        return {
            // הפעלה / כיבוי — Ctrl+Shift+E, כמו ב-Word
            'Mod-Shift-e': () => { setTracking(this.editor, !this.editor.state.doc.attrs.track); return true; },
            Backspace: () => on() && trackDelete(this.editor, -1, who()),
            Delete: () => on() && trackDelete(this.editor, 1, who()),
        };
    },
});

/** תצוגת השינויים: סימון מלא; סופי — כאילו הכול אושר; מקור — כאילו הכול נדחה. */
export const VIEWS = { all: 'סימון מלא', final: 'סופי — בלי סימון', original: 'מקור' };

/** המחברים של השינויים במסמך, עם מספר השינויים של כל אחד: [{ author, count }], לפי סדר ההופעה. */
export function authors(doc) {
    const m = new Map();
    for (const c of changes(doc)) m.set(c.author, (m.get(c.author) || 0) + 1);
    return [...m].map(([author, count]) => ({ author, count }));
}

/**
 * השינויים במסמך לפי הסדר: [{ type: 'ins'|'del'|'fmt'|'para', author, date, from, to }] — רצף צמוד של אותו סימון הוא
 * שינוי אחד; שינוי עיצוב פסקה — הפסקה כולה.
 */
export function changes(doc) {
    const out = [];
    doc.descendants((node, pos) => {
        if (node.isTextblock && node.attrs.pchg) out.push({ type: 'para', author: node.attrs.pchg.author || '', date: node.attrs.pchg.date || '', from: pos, to: pos + node.nodeSize });
        if (!node.isInline) return true;
        for (const m of node.marks) {
            if (m.type.name !== 'ins' && m.type.name !== 'del' && m.type.name !== 'fmt') continue;
            const last = out[out.length - 1];
            if (last && last.type === m.type.name && last.author === m.attrs.author && last.to === pos) last.to = pos + node.nodeSize;
            else out.push({ type: m.type.name, author: m.attrs.author, date: m.attrs.date, from: pos, to: pos + node.nodeSize });
        }
        return false;
    });
    return out;
}

/**
 * קבלה (accept=true) או דחייה של שינויים: all — כולם (חוץ ממחברים מוסתרים — hide, כמו "כל השינויים המוצגים"
 * ב-Word); אחרת — אלה שנוגעים בבחירה (או השינוי שבו הסמן). מחזיר כמה שינויים טופלו.
 */
export function resolveChanges(editor, accept, all = false, hide = null) {
    const { state } = editor;
    const { from, to } = state.selection;
    const list = changes(state.doc).filter(c => all ? !hide?.has(c.author) : (c.from <= to && c.to >= from));
    if (!list.length) return 0;
    const tr = state.tr.setMeta(SKIP, true).setMeta('closeHistory$', true);
    // מהסוף להתחלה — מחיקה לא מזיזה את מה שלפניה
    for (const c of [...list].reverse()) {
        if (c.type === 'para') {
            // דחייה: הפסקה חוזרת להיות כמו שהייתה (הסוג והמאפיינים); אישור — רק הסימון יוצא
            const node = tr.doc.nodeAt(c.from);
            const old = node?.attrs.pchg?.old;
            const type = !accept && old && state.schema.nodes[old.type];
            if (type) tr.setNodeMarkup(c.from, type, { ...old.attrs, pchg: null });
            else if (node) tr.setNodeMarkup(c.from, undefined, { ...node.attrs, pchg: null });
            continue;
        }
        if (c.type === 'fmt') {
            // דחיית שינוי עיצוב: העיצוב הקודם חוזר (לכל קטע — שלו); אישור — רק הסימון יוצא
            if (!accept) state.doc.nodesBetween(c.from, c.to, (node, pos) => {
                const m = node.isText && node.marks.find(x => x.type.name === 'fmt');
                if (!m) return !node.isText;
                const a = Math.max(c.from, pos), b = Math.min(c.to, pos + node.nodeSize);
                for (const x of node.marks) if (FORMAT_MARKS.has(x.type.name)) tr.removeMark(a, b, x.type);
                for (const o of m.attrs.old || []) { const t = state.schema.marks[o.type]; if (t) tr.addMark(a, b, t.create(o.attrs)); }
                return false;
            });
            tr.removeMark(c.from, c.to, state.schema.marks.fmt);
            continue;
        }
        const remove = (c.type === 'ins') !== accept;   // דחיית תוספת / קבלת מחיקה — הטקסט יוצא
        if (remove) tr.delete(c.from, c.to);
        else tr.removeMark(c.from, c.to, state.schema.marks[c.type]);
    }
    editor.view.dispatch(tr.scrollIntoView());
    return list.length;
}

/** מעבר לשינוי הבא / הקודם (dir 1 / -1), במעגל — רק בין השינויים של המחברים המוצגים. */
export function goToChange(editor, dir, hide = null) {
    const list = changes(editor.state.doc).filter(c => !hide?.has(c.author));
    if (!list.length) return false;
    const at = editor.state.selection.from;
    const c = dir > 0 ? (list.find(x => x.from > at) || list[0]) : ([...list].reverse().find(x => x.to < at) || list[list.length - 1]);
    // שינוי פסקה — הסמן בתחילתה (הבחירה של כל הפסקה — בתוכה)
    const [a, b] = c.type === 'para' ? [c.from + 1, c.to - 1] : [c.from, c.to];
    editor.chain().focus().setTextSelection({ from: a, to: b }).scrollIntoView().run();
    return true;
}

/** הפעלה / כיבוי של המעקב — נשמר במסמך, ולא כשינוי במעקב. */
export function setTracking(editor, on) {
    editor.view.dispatch(editor.state.tr.setDocAttribute('track', on || null).setMeta(SKIP, true).setMeta('addToHistory', false));
}
