/**
 * עמודים אמיתיים, תוך כדי הקלדה — הרחבה משלנו (ההרחבה בתשלום של TipTap אסורה).
 *
 * העורך הוא עמודה אחת. אחרי כל שינוי מודדים איפה כל בלוק — ובפסקה גם כל שורה — יושב, בקואורדינטות
 * "לוגיות" (בלי הרווחים שכבר הוכנסו), ומחשבים איפה עמוד נגמר. בכל שבירה נכנס רווח (Decoration.widget)
 * שדוחף את ההמשך לראש העמוד הבא. הדפים עצמם — רקע לבן, שוליים, כותרת עליונה/תחתונה ומספר עמוד —
 * מצוירים בשכבה נפרדת מאחורי הטקסט (onLayout), לפי מספר העמודים.
 *
 * כללים: פסקה נשברת בין שורות (לפחות 2 שורות בכל צד — כמו "בקרת שורות יתומות" ב-Word); כותרת לא
 * נשארת לבד בתחתית עמוד; טבלה ותמונה לא נשברות (גבוהה מעמוד — חורגת); מעבר עמוד ידני — תמיד.
 */
import { Extension, Plugin, PluginKey, Decoration, DecorationSet } from '../../vendor/tiptap.js';

export const pagesKey = new PluginKey('wpPages');
const sectionsKey = new PluginKey('wpSections');
let secCache = { doc: null, sig: '', set: null };
const CONTAINERS = new Set(['bulletList', 'orderedList', 'listItem']);
const TEXTBLOCKS = new Set(['paragraph', 'heading']);

export const Pages = Extension.create({
    name: 'wpPages',
    addOptions() {
        return { geometry: () => ({ pageH: 1123, contentH: 1123 - 189, gap: 24, top: 94 }), onLayout: () => {} };
    },
    addStorage() {
        return { zoom: 1, contentWidth: 600, pages: 1 };
    },
    addProseMirrorPlugins() {
        const ext = this;
        return [new Plugin({
            key: pagesKey,
            state: {
                init: () => ({ deco: DecorationSet.empty, sig: '' }),
                apply(tr, old) {
                    const next = tr.getMeta(pagesKey);
                    if (next) return next;
                    if (!tr.docChanged) return old;
                    return { deco: old.deco.map(tr.mapping, tr.doc), sig: old.sig };
                },
            },
            props: {
                decorations: state => pagesKey.getState(state).deco,
            },
            view: view => {
                let raf = 0;
                // עימודים שכבר נוסו מאז השינוי האחרון במסמך. שורה שיושבת בדיוק על קצה העמוד יכולה "להתנדנד": עימוד א'
                // משנה מעט את המדידה ומוביל לעימוד ב', שמוביל חזרה לא'. כשעימוד חוזר — בוחרים את הזהיר מבין השניים
                // (שבירה מוקדמת יותר, כך ששום שורה לא גולשת מהעמוד) ועוצרים, עד השינוי הבא.
                let tried = new Map();
                let settled = false;
                const schedule = () => {
                    cancelAnimationFrame(raf);
                    raf = requestAnimationFrame(() => {
                        raf = 0;
                        // בזמן הדפסה הדף משנה גודל — לא מודדים: מדפיסים בדיוק את העמודים שעל המסך
                        if (window.matchMedia?.('print').matches) return;
                        // מדידה מחדש מבחוץ: קודם בלי הרווחים הקיימים. רווח באמצע פסקה שנקבע במדידה ישנה (למשל לפני שהגופן
                        // נטען) מפצל את השורה שלו — ואז המדידה "מאשרת" את עצמה ונתקעת על שבירה שגויה. באותו פריים, בלי הבהוב
                        if (clean) {
                            clean = false;
                            if (pagesKey.getState(view.state).deco !== DecorationSet.empty)
                                view.dispatch(view.state.tr.setMeta(pagesKey, { deco: DecorationSet.empty, sig: '' }).setMeta('addToHistory', false));
                        }
                        const res = measure(view, ext.options.geometry());
                        const cur = pagesKey.getState(view.state);
                        if (res.sig === cur.sig) { ext.storage.pages = res.pages; ext.options.onLayout(res); return; }
                        if (settled) return;
                        let pick = res;
                        if (tried.has(res.sig)) {
                            settled = true;
                            const all = [...tried.values()];
                            pick = all.reduce((a, r) => (earlier(r, a) ? r : a));
                            if (pick.sig === cur.sig) return;
                        }
                        tried.set(res.sig, res);
                        ext.storage.pages = pick.pages;
                        ext.options.onLayout(pick);
                        const deco = DecorationSet.create(view.state.doc, pick.breaks.flatMap(b => {
                            const out = [Decoration.widget(b.pos, spacer(b.h, b.page, b.row, b.sec), {
                                side: -2, key: `pb${b.page}-${Math.round(b.h)}${b.row ? 'r' : ''}`, ignoreSelection: true,
                                marks: [],   // מחוץ לכל סימון (גודל גופן וכו'): בהדפסה Chrome לא שובר עמוד בתוך span
                            })];
                            // טבלה שנמשכת: שורת הכותרת חוזרת בראש העמוד (כמו ב-Word וב-PDF)
                            if (b.repeat != null) {
                                const hdr = view.nodeDOM(b.repeat);
                                if (hdr) out.push(Decoration.widget(b.pos, repeatRow(hdr), { side: -1, key: `rp${b.page}-${b.repeat}`, ignoreSelection: true, marks: [] }));
                            }
                            return out;
                        }).concat((pick.notesArea || []).map(a => Decoration.widget(a.pos, notesAreaEl(a), {
                            side: a.end ? 1 : -3, key: `fn${a.pos}-${Math.round(a.fill)}-${a.notes.map(n => n.n).join('.')}-${hash(a.notes.map(n => n.text + JSON.stringify(n.content || '') + (n.clip || '') + (n.skip || '')).join('|'))}`,
                            ignoreSelection: true, marks: [],
                        }))));
                        view.dispatch(view.state.tr.setMeta(pagesKey, { deco, sig: pick.sig }).setMeta('addToHistory', false));
                    });
                };
                // מדידה מחדש מבחוץ (הגדרות עמוד, גופן שנטען, שינוי רוחב) — פותחת מחדש את הבחירה
                let clean = false;
                const fresh = () => { tried = new Map(); settled = false; clean = true; schedule(); };
                ext.storage.schedule = fresh;
                // שינוי גובה — בדרך כלל הרווחים שלנו; שינוי רוחב — שבירת שורות חדשה, מתחילים מחדש
                let width = 0;
                const ro = new ResizeObserver(() => {
                    const w = view.dom.offsetWidth;
                    if (w !== width) { width = w; fresh(); } else schedule();
                });
                ro.observe(view.dom);
                document.fonts?.ready.then(fresh);
                // גופן שנטען מאוחר יותר (נטען רק כשמשתמשים בו — למשל גופן המסמך) משנה רוחב שורות — מודדים שוב
                document.fonts?.addEventListener?.('loadingdone', fresh);
                schedule();
                return {
                    update(v, prev) {
                        if (v.state.doc !== prev.doc) { tried = new Map(); settled = false; }
                        if (v.state.doc !== prev.doc || pagesKey.getState(v.state) !== pagesKey.getState(prev)) schedule();
                    },
                    destroy() { cancelAnimationFrame(raf); ro.disconnect(); document.fonts?.removeEventListener?.('loadingdone', fresh); },
                };
            },
        }), new Plugin({
            // מקטעים: כל בלוק ברוחב העמוד של המקטע שלו (גבולות שקופים — לא נוגעים ב-margin של הזחה), ושם עמוד להדפסה
            key: sectionsKey,
            props: {
                decorations: state => {
                    const g = ext.options.geometry();
                    if (!g.sections || g.sections.length < 2) return null;
                    const sig = g.sections.map(x => `${x.from}:${x.pageW}:${x.left}:${x.right}`).join('|');
                    if (secCache.doc === state.doc && secCache.sig === sig) return secCache.set;
                    const deco = [];
                    state.doc.forEach((node, pos) => {
                        let k = 0;
                        for (let i = 1; i < g.sections.length; i++) if (pos > g.sections[i].from) k = i;
                        const x = g.sections[k];
                        const side = (g.sheetW - x.pageW) / 2;
                        deco.push(Decoration.node(pos, pos + node.nodeSize, {
                            style: `border-left:${side + x.left}px solid transparent;border-right:${side + x.right}px solid transparent;`
                                + `--wps-w:${x.pageW - x.left - x.right}px;` + (k ? `page:wps${k};` : ''),
                            'data-sec': String(k),
                        }));
                    });
                    secCache = { doc: state.doc, sig, set: DecorationSet.create(state.doc, deco) };
                    return secCache.set;
                },
            },
        })];
    },
    addCommands() {
        return {
            repaginate: () => () => {
                this.storage.schedule?.();
                return true;
            },
        };
    },
});

/** האם בעימוד a הדף הראשון ששונה נשבר מוקדם יותר (בטוח יותר: שום שורה לא גולשת). */
function earlier(a, b) {
    for (let i = 0; i < Math.min(a.breaks.length, b.breaks.length); i++) {
        if (a.breaks[i].pos !== b.breaks[i].pos) return a.breaks[i].pos < b.breaks[i].pos;
    }
    return a.breaks.length > b.breaks.length;
}

const FN_SEP = 14;   // קו ההפרדה מעל ההערות + רווח
const FN_LINE = 10 * 1.25 * 4 / 3;   // שורה של הערה (px): 10pt × line-height 1.25 — כמו .wp-fn-note ב-editor.css
const fnCache = new Map();
let fnBox = null;
/** גובה הערה אחת בתחתית העמוד — מדידה בקופסה נסתרת, באותו רוחב ובאותו גופן כמו אזור ההערות. */
function noteHeight(root, note, g) {
    const w = Math.round(g.pageW - g.left - g.right);
    const font = getComputedStyle(root).fontFamily;
    const key = `${w}|${font}|${note.n}|${note.text}|${note.content ? JSON.stringify(note.content) : ''}`;
    if (fnCache.has(key)) return fnCache.get(key);
    if (!fnBox || !fnBox.isConnected) {
        fnBox = document.createElement('div');
        fnBox.className = 'wp-fn-measure';
        fnBox.setAttribute('aria-hidden', 'true');
        root.parentElement.append(fnBox);   // מחוץ למסמך העריך — ProseMirror לא רואה אותה
    }
    fnBox.style.width = w + 'px';
    fnBox.style.fontFamily = font;
    fnBox.textContent = '';
    fnBox.append(noteEl(note));
    const zoomed = root.getBoundingClientRect().height / (root.offsetHeight || 1) || 1;
    const h = fnBox.getBoundingClientRect().height / zoomed;
    if (fnCache.size > 2000) fnCache.clear();
    fnCache.set(key, h);
    return h;
}

function noteEl(note) {
    const d = document.createElement('div');
    d.className = 'wp-fn-note';
    if (note.pos != null) d.dataset.pos = note.pos;
    const sup = document.createElement('sup');
    sup.textContent = note.n;
    d.append(sup, ' ', ...noteInline(note));
    return d;
}

/** טביעה קצרה למחרוזת — מפתח לווידג'ט: תוכן אחר (גם רק עיצוב אחר) — ווידג'ט חדש */
function hash(str) {
    let x = 0;
    for (let i = 0; i < str.length; i++) x = (x * 31 + str.charCodeAt(i)) | 0;
    return (x >>> 0).toString(36);
}

const NOTE_TAGS = { bold: 'b', italic: 'i', underline: 'u', strike: 's', superscript: 'sup', subscript: 'sub' };
/** תוכן ההערה: עם עיצוב (content) — אלמנט לכל סימון; בלי — הטקסט. תמיד textContent, לא HTML. */
export function noteInline(note) {
    if (!Array.isArray(note.content)) return [note.text || ''];
    return note.content.filter(t => t?.type === 'text' && t.text).map(t => (t.marks || []).reduce((node, m) => {
        const tag = NOTE_TAGS[m?.type];
        if (!tag) return node;
        const e = document.createElement(tag);
        e.append(node);
        return e;
    }, document.createTextNode(t.text)));
}

/** אזור ההערות בתחתית עמוד: מילוי עד תחתית הטקסט, קו, וההערות. בתוך טבלה — כשורה על כל העמודות. */
function notesAreaEl(a) {
    const el = document.createElement('div');
    el.className = 'wp-fn-area';
    el.contentEditable = 'false';
    el.style.paddingTop = a.fill + 'px';
    const sep = document.createElement('div');
    sep.className = 'wp-fn-sep';
    // הערה שנחתכת בין עמודים: חלון בגובה החלק שבעמוד הזה, וההערה מוזזת למעלה בגובה מה שכבר הוצג
    el.append(sep, ...a.notes.map(n => {
        if (!n.clip && !n.skip) return noteEl(n);
        const box = document.createElement('div');
        box.className = 'wp-fn-part';
        box.style.height = n.clip + 'px';
        const inner = noteEl(n);
        if (n.skip) inner.style.marginTop = -n.skip + 'px';
        box.append(inner);
        return box;
    }));
    if (!a.row) return el;
    const tr = document.createElement('tr');
    tr.className = 'wp-fn-row';
    const td = document.createElement('td');
    td.colSpan = a.row;
    td.append(el);
    tr.append(td);
    return tr;
}

/** שורת הכותרת של טבלה — עותק לקריאה בלבד, בראש עמוד שהטבלה נמשכת אליו. */
function repeatRow(tr) {
    const el = tr.cloneNode(true);
    el.classList.add('wp-repeat');
    el.contentEditable = 'false';
    el.setAttribute('aria-hidden', 'true');
    el.querySelectorAll('.column-resize-handle, .selectedCell').forEach(x => x.classList.remove('selectedCell'));
    return el;
}

/** row — מספר העמודות כשהרווח בתוך טבלה (0 — רווח רגיל). */
function spacer(h, page, row = 0, sec = false) {
    if (row) {
        // בתוך טבלה: שורה ריקה על כל הרוחב (span בתוך tbody אינו חוקי)
        const tr = document.createElement('tr');
        tr.className = 'wp-break wp-break-row';
        tr.contentEditable = 'false';
        tr.setAttribute('aria-hidden', 'true');
        tr.dataset.page = page;
        const td = document.createElement('td');
        td.colSpan = row;   // בדיוק מספר העמודות: יותר מזה מוסיף עמודות לטבלה (table-layout: fixed) ומכווץ את כולן
        td.style.height = h + 'px';
        tr.append(td);
        return tr;
    }
    const el = document.createElement('span');
    // בגבול מקטע ההדפסה כבר עוברת לעמוד חדש בגלל שם העמוד (page: wpsN) — שבירה נוספת הייתה יוצרת עמוד ריק
    el.className = sec ? 'wp-break wp-break-sec' : 'wp-break';
    el.contentEditable = 'false';
    el.setAttribute('aria-hidden', 'true');
    el.dataset.page = page;
    el.style.height = h + 'px';
    return el;
}

/**
 * המדידה. מחזירה {breaks:[{pos,h,page}], pages, sig}.
 * g: pageH (גובה עמוד), contentH (גובה אזור הטקסט), gap (רווח בין דפים), top (שוליים עליונים) — בפיקסלים לא מוגדלים.
 */
function measure(view, g) {
    const root = view.dom;
    const rr = root.getBoundingClientRect();
    const zoom = rr.height && root.offsetHeight ? rr.height / root.offsetHeight : 1;
    // הרווחים הקיימים: [top פיזי, גובה] — כדי להמיר מיקום פיזי ללוגי
    const spacers = [...root.querySelectorAll('.wp-break, .wp-repeat, .wp-fn-area')].map(el => {
        const r = el.getBoundingClientRect();
        return [(r.top - rr.top) / zoom, r.height / zoom];
    }).sort((a, b) => a[0] - b[0]);
    const logical = physTop => {
        let y = physTop;
        for (const [t, h] of spacers) { if (t < physTop - 0.5) y -= h; else break; }
        return y;
    };
    const units = [];   // {top, bottom, pos, kind: 'block'|'line'|'force'|'heading', first?: bool}
    const box = el => {
        const r = el.getBoundingClientRect();
        return { top: logical((r.top - rr.top) / zoom), bottom: logical((r.bottom - rr.top) / zoom) };
    };

    // at — לאן מכניסים רווח לפני הבלוק הראשון בפריט רשימה: לפני הפריט, כדי שהתבליט יעבור איתו
    const visit = (node, pos, at = null) => {
        node.forEach((child, offset) => {
            const cpos = pos + offset;
            const brk = offset === 0 && at !== null ? at : cpos;
            const dom = view.nodeDOM(cpos);
            if (!(dom instanceof HTMLElement)) return;
            const name = child.type.name;
            if (name === 'pageBreak' || name === 'sectionBreak') {
                const b = box(dom);
                units.push({ top: b.top, bottom: b.bottom, pos: cpos, kind: 'force', after: cpos + child.nodeSize, section: name === 'sectionBreak' });
                return;
            }
            if (CONTAINERS.has(name)) { visit(child, cpos + 1, name === 'listItem' ? brk : (offset === 0 ? at : null)); return; }
            if (TEXTBLOCKS.has(name)) { lines(view, dom, child, cpos, brk, logical, rr, zoom, units, name === 'heading'); return; }
            if (name === 'table') { rows(view, child, cpos, brk, box, units); return; }
            const b = box(dom);
            units.push({ top: b.top, bottom: b.bottom, pos: brk, kind: 'block' });
        });
    };
    visit(view.state.doc, 0);

    // הערות שוליים: כל סימן משויך ליחידה (שורה / בלוק) שבה הוא נמצא; גובה ההערה נמדד מראש באותו רוחב ובאותו גופן
    const refs = [...root.querySelectorAll('.wp-fn-ref')].filter(el => !el.closest('.wp-repeat, .wp-fn-area'));
    refs.forEach((el, k) => {
        const b = box(el);
        const mid = (b.top + b.bottom) / 2;
        const u = units.find(x => x.kind !== 'force' && mid >= x.top - 0.5 && mid <= x.bottom + 0.5);
        if (!u) return;
        let pos = null;
        try { pos = view.posAtDOM(el, 0); } catch { /* */ }
        let content = null;
        try { content = JSON.parse(el.getAttribute('data-content') || 'null'); } catch { /* */ }
        (u.notes ||= []).push({ n: k + 1, pos, text: el.getAttribute('data-text') || '', content });
    });
    const noteH = note => noteHeight(root, note, (g.sections ? g.sections[0] : g));

    // סימולציה: עמוד אחרי עמוד. כל עמוד — בגיאומטריה של המקטע שלו (g.sections; בלי מקטעים — g עצמו)
    const secs = g.sections || [g];
    let s = 0;                    // המקטע של העמוד הנוכחי
    const pageSecs = [0];
    const breaks = [];
    let pageTop = g.top;          // הלוגי שבו מתחיל אזור הטקסט של העמוד הנוכחי
    let page = 0;
    let firstOnPage = 0;          // היחידה הראשונה בעמוד — תמיד נכנסת (גם אם גבוהה מעמוד)
    // הערות השוליים של העמוד הנוכחי: הגובה שלהן (כולל קו ההפרדה) נלקח מהמקום לטקסט
    // הערה ארוכה שלא נכנסת כולה (כמו ב-Word): השורות שנכנסות — בעמוד של הסימן (clip), והשאר — בראש אזור ההערות
    // של העמוד הבא (skip). carry — ההמשך שמחכה לעמוד הנוכחי
    let carry = null;
    const notesOf = (a, b) => [...(carry ? [carry] : []), ...units.slice(a, b).flatMap(x => x.notes || [])];
    const shownH = n => n.clip ?? (noteH(n) - (n.skip || 0));
    const areaH = list => list.length ? FN_SEP + list.reduce((t, n) => t + shownH(n), 0) : 0;
    const notesArea = [];         // {pos, fill, notes, row} — האזור בתחתית כל עמוד שיש בו הערות
    // סוגר עמוד: אם יש בו הערות — אזור ההערות לפני המעבר (מילוי עד תחתית הטקסט), והרווח מתקצר בגובה האזור
    const closePage = (brk, used, list) => {
        carry = null;
        if (!list.length) return brk;
        const fill = Math.max(0, secs[s].contentH - used - areaH(list));
        notesArea.push({ pos: brk.pos, fill, notes: list, row: brk.row || 0 });
        brk.h = Math.max(0, brk.h - fill - areaH(list));
        return brk;
    };
    let i = 0;
    while (i < units.length) {
        const u = units[i];
        if (u.kind === 'force') {
            // מעבר עמוד ידני / מעבר מקטע: העמוד הבא מתחיל אחריו (במעבר מקטע — בגיאומטריה של המקטע הבא)
            const cur = secs[s];
            const next = u.section ? secs[Math.min(s + 1, secs.length - 1)] : cur;
            breaks.push(closePage({ pos: u.after, h: Math.max(0, cur.pageH - cur.top - (u.bottom - pageTop) + g.gap + next.top), page: page + 1, sec: !!u.section },
                u.bottom - pageTop, notesOf(firstOnPage, i)));
            if (u.section) s = Math.min(s + 1, secs.length - 1);
            pageTop = u.bottom;
            page++;
            pageSecs.push(s);
            firstOnPage = ++i;
            continue;
        }
        const withNotes = areaH([...notesOf(firstOnPage, i), ...(u.notes || [])]);
        if (i === firstOnPage || u.bottom - pageTop + withNotes <= secs[s].contentH + 0.5) { i++; continue; }
        // השורה נכנסת אבל ההערה האחרונה שלה לא כולה: לפחות שורה אחת של ההערה בעמוד הזה, והשאר ממשיך בעמוד הבא
        const next = units[i + 1];
        if (u.notes?.length && next && next.kind !== 'force') {
            const before = [...notesOf(firstOnPage, i), ...u.notes.slice(0, -1)];
            const lastNote = u.notes[u.notes.length - 1];
            const room = secs[s].contentH - (u.bottom - pageTop) - FN_SEP - before.reduce((t, n) => t + shownH(n), 0);
            const k = Math.floor((room + 0.5) / FN_LINE);
            if (k >= 1 && k * FN_LINE < noteH(lastNote) - 1) {
                const used = next.top - pageTop;
                const rp = next.repeat;
                breaks.push(closePage({ pos: next.pos, h: Math.max(0, secs[s].pageH + g.gap - used), page: page + 1, row: next.row || 0, repeat: rp ? rp.pos : null },
                    used, [...before, { ...lastNote, clip: k * FN_LINE }]));
                carry = { ...lastNote, skip: k * FN_LINE, clip: noteH(lastNote) - k * FN_LINE };
                pageTop = next.top - (rp ? rp.h : 0);
                page++;
                pageSecs.push(s);
                firstOnPage = i + 1;
                i += 2;
                continue;
            }
        }
        // לא נכנס — שוברים לפני u, עם תיקונים:
        let j = i;
        // שורות יתומות: לפחות 2 שורות של הפסקה בעמוד הזה, ו-2 בבא
        if (u.kind === 'line' && !u.first) {
            const start = units.findIndex(x => x.para === u.para);
            const total = units.filter(x => x.para === u.para).length;
            const before = i - start;
            if (before < 2) j = start;
            else if (total - before < 2 && before >= 3) j = i - 1;
        }
        // כותרת לא נשארת לבד בתחתית העמוד
        while (j - 1 > firstOnPage && units[j - 1].heading) j--;
        if (j <= firstOnPage) j = i;
        const used = units[j].top - pageTop;
        const rep = units[j].repeat;   // שורה באמצע טבלה: גם השבירה בתוך הטבלה, וגם שורת הכותרת חוזרת (תופסת מקום)
        breaks.push(closePage({ pos: units[j].pos, h: Math.max(0, secs[s].pageH + g.gap - used), page: page + 1, row: units[j].row || 0, repeat: rep ? rep.pos : null },
            used, notesOf(firstOnPage, j)));
        pageTop = units[j].top - (rep ? rep.h : 0);
        page++;
        pageSecs.push(s);
        firstOnPage = j;
        i = j + 1;
    }
    // העמוד האחרון: ההערות שלו — בסוף המסמך
    const last = notesOf(firstOnPage, units.length);
    if (last.length) {
        const end = units.length ? units[units.length - 1].bottom - pageTop : 0;
        notesArea.push({ pos: view.state.doc.content.size, fill: Math.max(0, secs[s].contentH - end - areaH(last)), notes: last, row: 0, end: true });
    }
    const sig = breaks.map(b => `${b.pos}:${Math.round(b.h)}`).join('|')
        + '§' + notesArea.map(a => `${a.pos}:${Math.round(a.fill)}:${a.notes.map(n => n.n + n.text + (n.content ? JSON.stringify(n.content).length : '') + (n.clip ? '<' + Math.round(n.clip) : '') + (n.skip ? '>' + Math.round(n.skip) : '')).join(',')}`).join('|');
    return { breaks, notesArea, pages: page + 1, pageSecs, sig: sig + '#' + pageSecs.join(','), zoom };
}

/**
 * השורות של פסקה/כותרת: מלבנים של הטקסט והתמונות שבשורה (לא של הרווחים שלנו), מקובצים לפי גובה.
 * לכל שורה — המיקום במסמך שבו היא מתחילה (חיפוש בינארי ב-coordsAtPos).
 */
/**
 * טבלה: כל שורה — יחידה (שבירה בין שורות, לא בתוך שורה). שורת כותרת (כל התאים th) בראש הטבלה נשארת עם השורה
 * שאחריה, וחוזרת בראש כל עמוד שהטבלה נמשכת אליו — repeat: {pos, h}.
 */
function rows(view, table, tpos, brk, box, units) {
    let repeat = null;
    let cols = 0;
    table.firstChild?.forEach(cell => { cols += cell.attrs.colspan || 1; });
    table.forEach((row, off) => {
        const rpos = tpos + 1 + off;
        const tr = view.nodeDOM(rpos);
        if (!(tr instanceof HTMLElement)) return;
        const b = box(tr);
        const header = off === 0 && row.childCount > 0 && row.content.content.every(c => c.type.name === 'tableHeader');
        units.push({ top: b.top, bottom: b.bottom, pos: off === 0 ? brk : rpos, kind: 'block', row: off > 0 ? cols : 0, heading: header, repeat: off > 0 ? repeat : null });
        if (header) repeat = { pos: rpos, h: b.bottom - b.top };
    });
}

function lines(view, dom, node, pos, brk, logical, rr, zoom, units, heading) {
    const para = pos;
    const rects = [];
    const walker = document.createTreeWalker(dom, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
        acceptNode: n => {
            if (n.nodeType === 1) {
                // רווחים שלנו (גם אזור ההערות שלפני מעבר עמוד), ותמונות שאינן בשורה (צפות / מאחורי / לפני) — לא שורות טקסט
                if (n.classList.contains('wp-break') || n.classList.contains('wp-fn-area') || /\bwrap-(square|tight|behind|front)\b/.test(n.className)) return NodeFilter.FILTER_REJECT;
                if (n.classList.contains('wp-img')) { const r = n.getBoundingClientRect(); if (r.height) rects.push([r.top, r.bottom]); return NodeFilter.FILTER_REJECT; }
                return n.tagName === 'IMG' || n.tagName === 'BR' ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
            }
            return n.nodeValue ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
        },
    });
    const range = document.createRange();
    // לכל מלבן של טקסט — גם הגובה המלא של השורה שלו (line-height של הגופן שלו, ממורכז סביב האותיות). כך גם
    // בשורה עם כמה גדלי גופן הגבול בין השורות הוא הגבול האמיתי
    const lh = new Map();
    const leadOf = el => {
        if (!lh.has(el)) { const cs = getComputedStyle(el); lh.set(el, parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.2); }
        return lh.get(el) * zoom;
    };
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        if (n.nodeType === 3) {
            range.selectNodeContents(n);
            const L = leadOf(n.parentElement);
            for (const r of range.getClientRects()) if (r.height) { const hl = (L - r.height) / 2; rects.push([r.top, r.bottom, r.top - hl, r.bottom + hl]); }
        } else { const r = n.getBoundingClientRect(); if (r.height) rects.push([r.top, r.bottom, r.top, r.bottom]); }
    }
    const box = dom.getBoundingClientRect();
    const blockTop = logical((box.top - rr.top) / zoom);
    const blockBottom = logical((box.bottom - rr.top) / zoom);
    if (rects.length < 2) {
        units.push({ top: blockTop, bottom: blockBottom, pos: brk, kind: 'block', para, heading, first: true, last: true });
        return;
    }
    // קיבוץ לשורות: מלבן שמתחיל לפני סוף השורה הנוכחית (פחות חצי גובה) — באותה שורה
    rects.sort((a, b) => a[0] - b[0]);
    const ls = [];
    for (const [t, b, et, eb] of rects) {
        const last = ls[ls.length - 1];
        if (last && t < last[1] - (last[1] - last[0]) / 2) {
            last[1] = Math.max(last[1], b); last[0] = Math.min(last[0], t);
            last[2] = Math.min(last[2], et); last[3] = Math.max(last[3], eb);
        } else ls.push([t, b, et, eb]);
    }
    if (ls.length < 2 || heading) {
        units.push({ top: blockTop, bottom: blockBottom, pos: brk, kind: 'block', para, heading, first: true, last: true });
        return;
    }
    const start = pos + 1;
    const end = pos + node.nodeSize - 1;
    // שורה = כל גובה השורה (line-height), לא רק גובה האותיות — כך מודד גם ה-PDF מהשרת, ושורה שרק האותיות שלה
    // נכנסות בתחתית העמוד עוברת לעמוד הבא. הגבול בין שתי שורות: בין תחתית השורה המלאה של האחת לראש של הבאה
    // (כל קצה מומר ללוגי לבד: בין שתי השורות יכול לשבת רווח של מעבר עמוד)
    const lg = y => logical((y - rr.top) / zoom);
    const cut = k => (lg(ls[k][3]) + lg(ls[k + 1][2])) / 2;
    ls.forEach(([t], k) => {
        const top = k === 0 ? blockTop : cut(k - 1);
        const bottom = k === ls.length - 1 ? blockBottom : cut(k);
        // המיקום במסמך נחשב רק לשורה שבאמת נשברת (חיפוש בינארי — יקר במסמך ארוך)
        const u = { top, bottom, kind: 'line', para, heading: false, first: k === 0, last: k === ls.length - 1 };
        if (k === 0) u.pos = brk;
        else Object.defineProperty(u, 'pos', { get() { return this._p ??= lineStart(view, start, end, t); } });
        units.push(u);
    });
}

/** המיקום הראשון שהתו אחריו נמצא בשורה שמתחילה ב-lineTop (פיזי, במסך). */
function lineStart(view, lo, hi, lineTop) {
    let a = lo, b = hi;
    while (a < b) {
        const mid = (a + b) >> 1;
        let top;
        try { top = view.coordsAtPos(mid, 1).top; } catch { top = lineTop; }
        if (top >= lineTop - 2) b = mid; else a = mid + 1;
    }
    // שורה שנשברה לא מתחילה ברווח / טאב: אלה "תלויים" בסוף השורה הקודמת (גם ב-PDF). טאב תלוי מדווח לפעמים
    // במיקום של השורה הבאה — והשבירה הייתה נכנסת לפניו, והטאב היה פותח את השורה בעמוד הבא (ומזיז אותה)
    const doc = view.state.doc;
    while (a < hi && /^[ \t]$/.test(doc.textBetween(a, a + 1, '', ''))) a++;
    return a;
}

/** מספר עמוד בתבנית (זהה ל-page_label ב-lib/page.php). */
export function pageLabel(n, total, format) {
    switch (format) {
        case 'page_n': return `עמ' ${n}`;
        case 'n_of_m': return `${n} מתוך ${total}`;
        case 'dash_n': return `- ${n} -`;
        case 'hebrew': return hebrewLetters(n);
        default: return String(n);
    }
}

export function hebrewLetters(n) {
    if (n <= 0) return String(n);
    let out = '';
    for (const [v, l] of [[400, 'ת'], [300, 'ש'], [200, 'ר'], [100, 'ק']]) while (n >= v) { out += l; n -= v; }
    if (n === 15) return out + 'טו';
    if (n === 16) return out + 'טז';
    return out + ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'][Math.floor(n / 10)] + ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'][n % 10];
}
