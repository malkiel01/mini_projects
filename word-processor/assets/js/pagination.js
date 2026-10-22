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
                            const out = [Decoration.widget(b.pos, spacer(b.h, b.page, b.row, b.sec, b.skip || 0), {
                                side: b.side ?? -2, key: `pb${b.page}-${Math.round(b.h)}${b.row ? 'r' : ''}${b.skip ? 's' + b.skip : ''}`, ignoreSelection: true,
                                marks: [],   // מחוץ לכל סימון (גודל גופן וכו'): בהדפסה Chrome לא שובר עמוד בתוך span
                            })];
                            // טבלה שנמשכת: שורת הכותרת חוזרת בראש העמוד (כמו ב-Word וב-PDF)
                            if (b.repeat != null) {
                                const hdr = view.nodeDOM(b.repeat);
                                if (hdr) out.push(Decoration.widget(b.pos, repeatRow(hdr), { side: -1, key: `rp${b.page}-${b.repeat}`, ignoreSelection: true, marks: [] }));
                            }
                            return out;
                        }).concat((pick.notesArea || []).map(a => Decoration.widget(a.pos, notesAreaEl(a), {
                            // אזור ההערות של העמוד האחרון — אחרי הכול (גם אחרי הערות הסיום); עמוד שנגמר בין הערות סיום — לפני המעבר שלו
                            side: a.end ? a.end : a.side != null ? a.side - 0.25 : -3, key: `fn${a.pos}-${a.end || ''}-${Math.round(a.fill)}-${a.notes.map(n => n.n).join('.')}-${a.style ? hash(a.style) : ''}-${hash(a.notes.map(n => n.text + JSON.stringify(n.content || '') + (n.clip || '') + (n.skip || '')).join('|'))}`,
                            ignoreSelection: true, marks: [],
                        }))));
                        view.dispatch(view.state.tr.setMeta(pagesKey, { deco, sig: pick.sig }).setMeta('addToHistory', false));
                    });
                };
                // מדידה מחדש מבחוץ (הגדרות עמוד, גופן שנטען, שינוי רוחב) — פותחת מחדש את הבחירה
                let clean = false;
                // גם גובה ההערות נמדד מחדש: גופן שנטען משנה אותו, והמפתח במטמון (שם הגופן) — לא (ביקורת 04, F10)
        const fresh = () => { tried = new Map(); settled = false; clean = true; fnCache.clear(); schedule(); };
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
                    const sig = g.sections.map(x => `${x.from}:${x.pageW}:${x.left}:${x.right}:${x.textW}`).join('|');
                    if (secCache.doc === state.doc && secCache.sig === sig) return secCache.set;
                    const deco = [];
                    state.doc.forEach((node, pos) => {
                        let k = 0;
                        for (let i = 1; i < g.sections.length; i++) if (pos > g.sections[i].from) k = i;
                        const x = g.sections[k];
                        const side = (g.sheetW - x.pageW) / 2;
                        deco.push(Decoration.node(pos, pos + node.nodeSize, {
                            // בטורים — ברוחב הטור, במקום של הטור הראשון (מימין)
                            style: `border-left:${side + x.left + (x.shift || 0)}px solid transparent;border-right:${side + x.right}px solid transparent;`
                                + `--wps-w:${x.textW ?? x.pageW - x.left - x.right}px;` + (k ? `page:wps${k};` : ''),
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
    const w = Math.round(g.textW ?? (g.pageW - g.left - g.right));   // בטורים — רוחב הטור
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
    el.style.cssText = a.style || '';
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
function spacer(h, page, row = 0, sec = false, skip = 0) {
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
    // טורים: מעבר עמוד ידני באמצע העמוד — בהדפסה עוד מעבר טור לכל טור שנשאר ריק (.wp-colskip ב-CSS של ההדפסה)
    for (let i = 0; i < skip; i++) el.append(Object.assign(document.createElement('span'), { className: 'wp-colskip' }));
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

    const maxH = Math.min(...(g.sections || [g]).map(x => x.contentH));   // "שמור שורות יחד" — רק לפסקה שנכנסת בעמוד
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
            if (TEXTBLOCKS.has(name)) { lines(view, dom, child, cpos, brk, logical, rr, zoom, units, name === 'heading', maxH); return; }
            if (name === 'table') { rows(view, child, cpos, brk, box, units); return; }
            const b = box(dom);
            units.push({ top: b.top, bottom: b.bottom, pos: brk, kind: 'block' });
        });
    };
    visit(view.state.doc, 0);
    // הערות הסיום (ווידג'טים בסוף המסמך — EndnotesView): כל אחת יחידה; מעבר עמוד לפני הערה — ווידג'ט באותו מקום,
    // עם side שממקם אותו בין ההערות (ראו endnoteWidgets)
    root.querySelectorAll('.wp-en-area').forEach((el, k) => {
        const b = box(el);
        units.push({ top: b.top, bottom: b.bottom, pos: view.state.doc.content.size, kind: 'block', side: 100 + k - 0.5 });
    });

    // הערות שוליים: כל סימן משויך ליחידה (שורה / בלוק) שבה הוא נמצא; גובה ההערה נמדד מראש באותו רוחב ובאותו גופן
    const refs = [...root.querySelectorAll('.wp-fn-ref:not(.wp-en-ref)')].filter(el => !el.closest('.wp-repeat, .wp-fn-area, .wp-en-area'));
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

    // סימולציה: עמוד אחרי עמוד. כל עמוד — בגיאומטריה של המקטע שלו (g.sections; בלי מקטעים — g עצמו)
    const secs = g.sections || [g];
    // המקטע של מיקום במסמך — אותו כלל כמו רוחב הבלוקים (sectionsKey): המקטע האחרון שמתחיל לפני המיקום. מקור אמת
    // אחד לרוחב הטקסט ולעמוד שמצויר מתחתיו (ביקורת 04, R01: העימוד ספר מעבר מקטע שבתוך רשימה, והרוחב — לא)
    const secOf = pos => { let k = 0; for (let x = 1; x < secs.length; x++) if (pos > secs[x].from) k = x; return k; };
    let s = 0;                    // המקטע של העמוד הנוכחי
    // גובה הערה — ברוחב העמוד שהיא בו (ביקורת 04, F10: נמדד ברוחב המקטע הראשון)
    const noteH = note => noteHeight(root, note, secs[s]);
    const pageSecs = [0];
    const breaks = [];
    let pageTop = g.top;          // הלוגי שבו מתחיל אזור הטקסט של העמוד הנוכחי
    let page = 0;
    let firstOnPage = 0;          // היחידה הראשונה בעמוד — תמיד נכנסת (גם אם גבוהה מעמוד)
    // הערות השוליים של העמוד הנוכחי: הגובה שלהן (כולל קו ההפרדה) נלקח מהמקום לטקסט
    // הערה ארוכה שלא נכנסת כולה (כמו ב-Word): השורות שנכנסות — בעמוד של הסימן (clip), והשאר — בראש אזור ההערות
    // של העמוד הבא (skip), וכך הלאה — הערה יכולה להימשך על פני כמה עמודים (ביקורת 04, F05).
    // carry — החלקים שמחכים לעמוד הנוכחי; shown — העמוד מלא: אלה ההערות שבו (חלקן חתוכות), ו-pending — ההמשך לבא
    let carry = [];
    let shown = null;
    let pending = [];
    const notesOf = (a, b) => shown ?? [...carry, ...units.slice(a, b).flatMap(x => x.notes || [])];
    const shownH = n => n.clip ?? (noteH(n) - (n.skip || 0));
    const areaH = list => list.length ? FN_SEP + list.reduce((t, n) => t + shownH(n), 0) : 0;
    // חלוקת ההערות לפי הסדר: מה שנכנס ב-room (כולל קו ההפרדה) — בעמוד הזה, בשורות שלמות; השאר — לעמוד הבא
    const fit = (list, room) => {
        const here = [], rest = [];
        let left = room - FN_SEP;
        for (const n of list) {
            if (rest.length) { rest.push(n); continue; }
            const h = shownH(n);
            if (h <= left + 0.5) { here.push(n); left -= h; continue; }
            const k = Math.floor((left + 0.5) / FN_LINE);
            if (k >= 1 && h - k * FN_LINE >= 1) {
                here.push({ ...n, clip: k * FN_LINE });
                rest.push({ ...n, skip: (n.skip || 0) + k * FN_LINE, clip: h - k * FN_LINE });
            } else rest.push(n);
        }
        return [here, rest];
    };
    const notesArea = [];         // {pos, fill, notes, row} — האזור בתחתית כל עמוד שיש בו הערות
    // ברוחב העמוד של המקטע (כמו הבלוקים — sectionsKey), ולא ברוחב הגיליון
    const secStyle = () => {
        if (!g.sections || g.sections.length < 2) return '';
        const x = secs[s], side = (g.sheetW - x.pageW) / 2;
        return `border-left:${side + x.left + (x.shift || 0)}px solid transparent;border-right:${side + x.right}px solid transparent;`;
    };
    // סוגר עמוד: אם יש בו הערות — אזור ההערות לפני המעבר (מילוי עד תחתית הטקסט), והרווח מתקצר בגובה האזור
    const closePage = (brk, used, list) => {
        carry = pending;
        pending = [];
        shown = null;
        if (!list.length) return brk;
        const fill = Math.max(0, secs[s].contentH - used - areaH(list));
        // בתוך בלוק (שבירה באמצע פסקה, ברשימה) — הבלוק העליון כבר ברוחב המקטע; רק בין בלוקים — האזור צריך אותו בעצמו
        notesArea.push({ pos: brk.pos, side: brk.side, fill, notes: list, row: brk.row || 0, style: brk.row || view.state.doc.resolve(brk.pos).depth ? '' : secStyle() });
        brk.h = Math.max(0, brk.h - fill - areaH(list));
        return brk;
    };
    let i = 0;
    while (i < units.length) {
        const u = units[i];
        if (u.kind === 'force') {
            // מעבר עמוד ידני / מעבר מקטע: העמוד הבא מתחיל אחריו (במעבר מקטע — בגיאומטריה של המקטע הבא)
            const cur = secs[s];
            const next = u.section ? secs[secOf(u.after)] : cur;
            // ההערות של העמוד (וההמשך שעוד מחכה) — מה שנכנס מתחת לטקסט; השאר ממשיך לעמוד הבא
            let [here, rest] = fit([...notesOf(firstOnPage, i), ...pending], cur.contentH - (u.bottom - pageTop));
            let top = pageTop;
            if (u.section && rest.length) {
                // הערה שלא נגמרה לפני מעבר מקטע: ממשיכה בעמודים של הערות בלבד באותו מקטע (ברוחב שלו), לפני סימון המעבר
                // (המקטע הבא ברוחב אחר — ההמשך לא נמדד בו). כמו ב-PDF: הערות שנשארו בסוף מקטע — עמודים משלהן
                const mh = u.bottom - u.top;
                [here, rest] = fit([...notesOf(firstOnPage, i), ...pending], cur.contentH - (u.top - pageTop));
                let used = u.top - pageTop;
                for (let m = 1; rest.length && m < 500; m++) {
                    shown = null;
                    pending = [];
                    breaks.push(closePage({ pos: u.pos, side: -1000 + m, h: Math.max(0, cur.pageH + g.gap - used), page: page + 1 }, used, here));
                    page++;
                    pageSecs.push(s);
                    used = 0;
                    const [h2, r2] = fit(rest, cur.contentH - mh);
                    if (!h2.length) break;   // לא אמור לקרות: עמוד שלם תמיד מכיל שורה של הערה
                    [here, rest] = [h2, r2];
                }
                top = u.top;
                rest = [];
            }
            shown = null;
            pending = rest;   // closePage מעביר אותו לעמוד הבא
            breaks.push(closePage({ pos: u.after, h: Math.max(0, cur.pageH - cur.top - (u.bottom - top) + g.gap + next.top), page: page + 1, sec: !!u.section, forced: true },
                u.bottom - top, here));
            if (u.section) s = secOf(u.after);
            pageTop = u.bottom;
            page++;
            pageSecs.push(s);
            firstOnPage = ++i;
            continue;
        }
        // "מעבר עמוד לפני" (פסקה): שוברים לפניה, בלי תיקונים — אלא אם היא כבר ראשונה בעמוד
        const pb = u.breakBefore && i > firstOnPage;
        const atTop = i === firstOnPage;
        if (!pb && !shown) {
            const list = [...notesOf(firstOnPage, i), ...(u.notes || [])];
            const room = secs[s].contentH - (u.bottom - pageTop);
            if (areaH(list) <= room + 0.5) { i++; continue; }
            // ההערות לא נכנסות כולן. ראשונה בעמוד — נשארת תמיד, וההערות (ההמשך מהעמוד הקודם ושלה) ממלאות את מה שנשאר;
            // אחרת — נשארת רק אם כל ההערות לפני האחרונה שלה נכנסות, ומהאחרונה לפחות שורה (כמו ב-Word). השאר — לעמוד הבא
            if (atTop || u.notes?.length) {
                const [here, rest] = fit(list, room);
                if (atTop || (rest.length === 1 && here.length === list.length)) {
                    if (rest.length) { shown = here; pending = rest; }
                    i++;
                    continue;
                }
            }
        } else if (!pb && atTop) { i++; continue; }
        // לא נכנס — שוברים לפני u, עם תיקונים (לא אחרי הערה שנחתכה: העמוד מלא, u פותח את הבא):
        let j = i;
        // שורות יתומות: לפחות 2 שורות של הפסקה בעמוד הזה, ו-2 בבא
        const orphans = k => {
            const x = units[k];
            if (x.kind !== 'line' || x.first) return k;
            const start = units.findIndex(y => y.para === x.para);
            const total = units.filter(y => y.para === x.para).length;
            const before = k - start;
            if (before < 2) return start;
            if (total - before < 2 && before >= 3) return k - 1;
            return k;
        };
        if (!pb && !shown) {
            j = orphans(i);
            // "שמור עם הבא" (כותרת, או פסקה שסומנה) ו"שמור שורות יחד" — עוברות יחד עם מה שאחריהן
            const k0 = j;
            while (j - 1 > firstOnPage && units[j - 1].heading) j--;
            // אם המעבר נפל באמצע פסקה — שוב לפחות 2 שורות שלה בכל צד (כמו ב-PDF)
            if (j !== k0) j = orphans(j);
        }
        if (j <= firstOnPage) j = i;
        const used = units[j].top - pageTop;
        const rep = units[j].repeat;   // שורה באמצע טבלה: גם השבירה בתוך הטבלה, וגם שורת הכותרת חוזרת (תופסת מקום)
        breaks.push(closePage({ pos: units[j].pos, side: units[j].side, h: Math.max(0, secs[s].pageH + g.gap - used), page: page + 1, row: units[j].row || 0, repeat: rep ? rep.pos : null },
            used, notesOf(firstOnPage, j)));
        pageTop = units[j].top - (rep ? rep.h : 0);
        page++;
        pageSecs.push(s);
        firstOnPage = j;
        i = j;   // הראשונה בעמוד החדש — נבדקת שוב: ההערות שלה (וההמשך מהעמוד הקודם) צריכות להיכנס איתה
    }
    // העמוד האחרון: ההערות שלו — בסוף המסמך
    const end = units.length ? units[units.length - 1].bottom - pageTop : 0;
    const [last, rest] = fit([...notesOf(firstOnPage, units.length), ...pending], secs[s].contentH - end);
    pending = rest;
    const docEnd = view.state.doc.content.size;
    if (last.length) {
        notesArea.push({ pos: docEnd, fill: Math.max(0, secs[s].contentH - end - areaH(last)), notes: last, row: 0, end: 1000, style: secStyle() });
    }
    // הערה שנמשכת אחרי סוף הטקסט: עמודים של הערות בלבד, עד סופה (בסוף המסמך — אחרי אזור ההערות של העמוד האחרון)
    for (let m = 1; pending.length && m < 500; m++) {
        breaks.push({ pos: docEnd, side: 1000 + 2 * m - 1, h: Math.max(0, secs[s].pageH - secs[s].contentH + g.gap), page: page + 1 });
        page++;
        pageSecs.push(s);
        const [here, rest] = fit(pending, secs[s].contentH);
        if (!here.length) break;   // לא אמור לקרות: עמוד שלם תמיד מכיל שורה של הערה
        pending = rest;
        notesArea.push({ pos: docEnd, fill: Math.max(0, secs[s].contentH - areaH(here)), notes: here, row: 0, end: 1000 + 2 * m, style: secStyle() });
    }
    const sig = breaks.map(b => `${b.pos}${b.side ? '~' + b.side : ''}:${Math.round(b.h)}`).join('|')
        + '§' + notesArea.map(a => `${a.pos}:${Math.round(a.fill)}:${a.notes.map(n => n.n + n.text + (n.content ? JSON.stringify(n.content).length : '') + (n.clip ? '<' + Math.round(n.clip) : '') + (n.skip ? '>' + Math.round(n.skip) : '')).join(',')}`).join('|');
    // טורים: כל "עמוד" כאן הוא טור (ברוחב הטור). העמוד הפיזי של כל אחד — n טורים לעמוד; מקטע חדש או מעבר עמוד ידני
    // פותחים עמוד חדש. מעבר ידני באמצע העמוד מדלג על הטורים שנשארו (skip — בהדפסה: עוד מעברי טור)
    const byPage = new Map(breaks.map(b => [b.page, b]));
    const phys = [0], cols = [0];
    for (let i = 1; i <= page; i++) {
        const b = byPage.get(i), prev = pageSecs[i - 1], n = secs[prev].cols || 1;
        if (pageSecs[i] !== prev || cols[i - 1] + 1 >= n || b?.forced) {
            if (b?.forced && pageSecs[i] === prev && n > 1) b.skip = n - 1 - cols[i - 1];
            phys.push(phys[i - 1] + 1); cols.push(0);
        } else { phys.push(phys[i - 1]); cols.push(cols[i - 1] + 1); }
    }
    // skip בחתימה: שינוי מספר הטורים בלבד (מסמך קצר עם מעבר ידני) לא מזיז אף מעבר, אבל משנה את מעברי הטור בהדפסה
    return { breaks, notesArea, pages: page + 1, pageSecs, phys, cols, sig: sig + '#' + pageSecs.join(',') + '#' + breaks.map(b => b.skip || 0).join(''), zoom };
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

function lines(view, dom, node, pos, brk, logical, rr, zoom, units, heading, maxH) {
    const para = pos;
    const { keepNext, keepLines, breakBefore } = node.attrs;
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
        units.push({ top: blockTop, bottom: blockBottom, pos: brk, kind: 'block', para, heading: heading || !!keepNext, breakBefore: !!breakBefore, first: true, last: true });
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
        units.push({ top: blockTop, bottom: blockBottom, pos: brk, kind: 'block', para, heading: heading || !!keepNext, breakBefore: !!breakBefore, first: true, last: true });
        return;
    }
    // "שמור שורות יחד": כל שורה (חוץ מהאחרונה) נשארת עם הבאה — רק כשכל הפסקה נכנסת בעמוד (כמו ב-Word)
    const together = keepLines && blockBottom - blockTop <= maxH + 0.5;
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
        const last = k === ls.length - 1;
        const u = { top, bottom, kind: 'line', para, heading: last ? !!keepNext : !!together, breakBefore: k === 0 && !!breakBefore, first: k === 0, last };
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

/** מספר רומי קטן (i, ii, iii…) — המספור של הערות הסיום, כמו ב-Word ו-roman_lower בשרת. */
export function romanLower(n) {
    let out = '';
    for (const [v, r] of [[1000, 'm'], [900, 'cm'], [500, 'd'], [400, 'cd'], [100, 'c'], [90, 'xc'], [50, 'l'], [40, 'xl'], [10, 'x'], [9, 'ix'], [5, 'v'], [4, 'iv'], [1, 'i']]) {
        while (n >= v) { out += r; n -= v; }
    }
    return out;
}

const endnotesKey = new PluginKey('wpEndnotes');
/**
 * הערות הסיום — בסוף המסמך: קו הפרדה, ואחריו כל הערה (אותו עיצוב כמו הערת שוליים, מספור רומי). ווידג'טים (לא חלק מהמסמך);
 * side 100 + k — כך מעבר עמוד יכול לשבת בין שתי הערות (side 100 + k − 0.5, ראו measure).
 */
export const Endnotes = Extension.create({
    name: 'wpEndnotes',
    addProseMirrorPlugins() {
        const build = doc => {
            const notes = [];
            doc.descendants((n, pos) => { if (n.type.name === 'footnote' && n.attrs.end) notes.push({ n: romanLower(notes.length + 1), text: n.attrs.text || '', content: n.attrs.content, pos }); });
            if (!notes.length) return DecorationSet.empty;
            const end = doc.content.size;
            const head = () => { const el = document.createElement('div'); el.className = 'wp-en-area wp-en-head'; el.contentEditable = 'false'; const sep = document.createElement('div'); sep.className = 'wp-fn-sep'; el.append(sep); return el; };
            return DecorationSet.create(doc, [Decoration.widget(end, head, { side: 100, key: 'en-head', ignoreSelection: true, marks: [] }),
                ...notes.map((nt, k) => Decoration.widget(end, () => { const el = document.createElement('div'); el.className = 'wp-en-area'; el.contentEditable = 'false'; el.append(noteEl(nt)); return el; },
                    { side: 101 + k, key: `en${k}-${nt.pos}-${hash(nt.text + JSON.stringify(nt.content || ''))}`, ignoreSelection: true, marks: [] }))]);
        };
        return [new Plugin({
            key: endnotesKey,
            state: { init: (_, st) => build(st.doc), apply: (tr, old) => (tr.docChanged ? build(tr.doc) : old) },
            props: { decorations: st => endnotesKey.getState(st) },
        })];
    },
});

export function hebrewLetters(n) {
    if (n <= 0) return String(n);
    let out = '';
    for (const [v, l] of [[400, 'ת'], [300, 'ש'], [200, 'ר'], [100, 'ק']]) while (n >= v) { out += l; n -= v; }
    if (n === 15) return out + 'טו';
    if (n === 16) return out + 'טז';
    return out + ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'][Math.floor(n / 10)] + ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'][n % 10];
}
