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
                        }));
                        view.dispatch(view.state.tr.setMeta(pagesKey, { deco, sig: pick.sig }).setMeta('addToHistory', false));
                    });
                };
                // מדידה מחדש מבחוץ (הגדרות עמוד, גופן שנטען, שינוי רוחב) — פותחת מחדש את הבחירה
                const fresh = () => { tried = new Map(); settled = false; schedule(); };
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
    const spacers = [...root.querySelectorAll('.wp-break, .wp-repeat')].map(el => {
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

    // סימולציה: עמוד אחרי עמוד. כל עמוד — בגיאומטריה של המקטע שלו (g.sections; בלי מקטעים — g עצמו)
    const secs = g.sections || [g];
    let s = 0;                    // המקטע של העמוד הנוכחי
    const pageSecs = [0];
    const breaks = [];
    let pageTop = g.top;          // הלוגי שבו מתחיל אזור הטקסט של העמוד הנוכחי
    let page = 0;
    let firstOnPage = 0;          // היחידה הראשונה בעמוד — תמיד נכנסת (גם אם גבוהה מעמוד)
    let i = 0;
    while (i < units.length) {
        const u = units[i];
        if (u.kind === 'force') {
            // מעבר עמוד ידני / מעבר מקטע: העמוד הבא מתחיל אחריו (במעבר מקטע — בגיאומטריה של המקטע הבא)
            const cur = secs[s];
            const next = u.section ? secs[Math.min(s + 1, secs.length - 1)] : cur;
            breaks.push({ pos: u.after, h: Math.max(0, cur.pageH - cur.top - (u.bottom - pageTop) + g.gap + next.top), page: page + 1, sec: !!u.section });
            if (u.section) s = Math.min(s + 1, secs.length - 1);
            pageTop = u.bottom;
            page++;
            pageSecs.push(s);
            firstOnPage = ++i;
            continue;
        }
        if (i === firstOnPage || u.bottom - pageTop <= secs[s].contentH + 0.5) { i++; continue; }
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
        breaks.push({ pos: units[j].pos, h: Math.max(0, secs[s].pageH + g.gap - used), page: page + 1, row: units[j].row || 0, repeat: rep ? rep.pos : null });
        pageTop = units[j].top - (rep ? rep.h : 0);
        page++;
        pageSecs.push(s);
        firstOnPage = j;
        i = j + 1;
    }
    const sig = breaks.map(b => `${b.pos}:${Math.round(b.h)}`).join('|');
    return { breaks, pages: page + 1, pageSecs, sig: sig + '#' + pageSecs.join(','), zoom };
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
                // רווחים שלנו, ותמונות שאינן בשורה (צפות / מאחורי / לפני) — לא שורות טקסט
                if (n.classList.contains('wp-break') || /\bwrap-(square|tight|behind|front)\b/.test(n.className)) return NodeFilter.FILTER_REJECT;
                if (n.classList.contains('wp-img')) { const r = n.getBoundingClientRect(); if (r.height) rects.push([r.top, r.bottom]); return NodeFilter.FILTER_REJECT; }
                return n.tagName === 'IMG' || n.tagName === 'BR' ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
            }
            return n.nodeValue ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
        },
    });
    const range = document.createRange();
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        if (n.nodeType === 3) { range.selectNodeContents(n); for (const r of range.getClientRects()) if (r.height) rects.push([r.top, r.bottom]); }
        else { const r = n.getBoundingClientRect(); if (r.height) rects.push([r.top, r.bottom]); }
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
    for (const [t, b] of rects) {
        const last = ls[ls.length - 1];
        if (last && t < last[1] - (last[1] - last[0]) / 2) { last[1] = Math.max(last[1], b); last[0] = Math.min(last[0], t); }
        else ls.push([t, b]);
    }
    if (ls.length < 2 || heading) {
        units.push({ top: blockTop, bottom: blockBottom, pos: brk, kind: 'block', para, heading, first: true, last: true });
        return;
    }
    const start = pos + 1;
    const end = pos + node.nodeSize - 1;
    ls.forEach(([t, b], k) => {
        const top = k === 0 ? blockTop : logical((t - rr.top) / zoom);
        const bottom = k === ls.length - 1 ? blockBottom : logical((b - rr.top) / zoom);
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
