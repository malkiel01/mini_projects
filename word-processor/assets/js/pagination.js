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
                        const deco = DecorationSet.create(view.state.doc, pick.breaks.map(b => Decoration.widget(b.pos, spacer(b.h, b.page), {
                            side: -1, key: `pb${b.page}-${Math.round(b.h)}`, ignoreSelection: true,
                            marks: [],   // מחוץ לכל סימון (גודל גופן וכו'): בהדפסה Chrome לא שובר עמוד בתוך span
                        })));
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

function spacer(h, page) {
    const el = document.createElement('span');
    el.className = 'wp-break';
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
    const spacers = [...root.querySelectorAll('.wp-break')].map(el => {
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
            if (name === 'pageBreak') {
                const b = box(dom);
                units.push({ top: b.top, bottom: b.bottom, pos: cpos, kind: 'force', after: cpos + child.nodeSize });
                return;
            }
            if (CONTAINERS.has(name)) { visit(child, cpos + 1, name === 'listItem' ? brk : (offset === 0 ? at : null)); return; }
            if (TEXTBLOCKS.has(name)) { lines(view, dom, child, cpos, brk, logical, rr, zoom, units, name === 'heading'); return; }
            const b = box(dom);
            units.push({ top: b.top, bottom: b.bottom, pos: brk, kind: 'block' });
        });
    };
    visit(view.state.doc, 0);

    // סימולציה: עמוד אחרי עמוד
    const breaks = [];
    let pageTop = g.top;          // הלוגי שבו מתחיל אזור הטקסט של העמוד הנוכחי
    let page = 0;
    let firstOnPage = 0;          // היחידה הראשונה בעמוד — תמיד נכנסת (גם אם גבוהה מעמוד)
    let i = 0;
    while (i < units.length) {
        const u = units[i];
        if (u.kind === 'force') {
            // מעבר עמוד ידני: העמוד הבא מתחיל אחריו
            breaks.push({ pos: u.after, h: Math.max(0, g.pageH + g.gap - (u.bottom - pageTop)), page: page + 1 });
            pageTop = u.bottom;
            page++;
            firstOnPage = ++i;
            continue;
        }
        if (i === firstOnPage || u.bottom - pageTop <= g.contentH + 0.5) { i++; continue; }
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
        breaks.push({ pos: units[j].pos, h: Math.max(0, g.pageH + g.gap - used), page: page + 1 });
        pageTop = units[j].top;
        page++;
        firstOnPage = j;
        i = j + 1;
    }
    const sig = breaks.map(b => `${b.pos}:${Math.round(b.h)}`).join('|');
    return { breaks, pages: page + 1, sig, zoom };
}

/**
 * השורות של פסקה/כותרת: מלבנים של הטקסט והתמונות שבשורה (לא של הרווחים שלנו), מקובצים לפי גובה.
 * לכל שורה — המיקום במסמך שבו היא מתחילה (חיפוש בינארי ב-coordsAtPos).
 */
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
