/**
 * עצירות טאב מותאמות ומובילים (R10 ב, כמו "טאבים" ב-Word). בפסקה עם attrs.tabs כל תו טאב עטוף ב-span.wp-tab, והרוחב
 * שלו נמדד במקום: עד העצירה הבאה אחרי תחילת הטאב (נק' מהשוליים בצד ההתחלה — לא מההזחה), ביישור לתחילה / לסוף /
 * למרכז / לנקודה העשרונית של הקטע שאחריו, עם מוביל (נקודות, מקפים, קו). אחרי העצירה האחרונה — כל 36 נק' מהשוליים.
 * פסקה בלי עצירות — כמו קודם (tab-size של הדפדפן). השרת עושה את אותו החישוב ב-PDF (lib/pdf/layout.php, tabStop).
 *
 * המדידה: אחרי כל עדכון (בפריים הבא) — לכל פסקה עם עצירות, הטאבים לפי הסדר; הרוחבות נשמרים במצב של התוסף והקישוטים
 * נבנים מהם, כך ש-ProseMirror לא מוחק אותם בציור הבא. העימוד (pagination.js) מודד את השורות אחרי זה כרגיל.
 */
import { Extension, Plugin, PluginKey, Decoration, DecorationSet } from '../../vendor/tiptap.js';

const PX = 4 / 3;   // נקודות → פיקסלים
const DEFAULT_STOP = 36 * PX;
export const ALIGNS = { start: 'לתחילת הקטע', center: 'למרכז', end: 'לסוף הקטע', decimal: 'לנקודה העשרונית' };
export const LEADERS = { none: 'ללא', dot: '.......', hyphen: '-------', underscore: '_______' };

/** העצירות של פסקה, תקינות וממוינות (כמו tabs_val בשרת). */
export function tabsOf(v) {
    if (!Array.isArray(v)) return [];
    const seen = new Map();
    for (const t of v) {
        const pos = Math.round(+t?.pos * 10) / 10;
        if (!(pos > 0 && pos <= 1584)) continue;
        seen.set(pos, { pos, align: ALIGNS[t.align] ? t.align : 'start', leader: LEADERS[t.leader] ? t.leader : 'none' });
    }
    return [...seen.values()].sort((a, b) => a.pos - b.pos).slice(0, 20);
}

export const tabsKey = new PluginKey('wpTabs');

/** הקישוטים: כל טאב בפסקה עם עצירות — span ברוחב שנמדד (או 0 עד המדידה הראשונה). */
function build(doc, widths) {
    const out = [];
    doc.descendants((n, pos) => {
        if (!n.isTextblock) return true;
        if (!tabsOf(n.attrs.tabs).length) return false;
        n.forEach((c, off) => {
            if (!c.isText) return;
            for (let i = c.text.indexOf('\t'); i >= 0; i = c.text.indexOf('\t', i + 1)) {
                const p = pos + 1 + off + i;
                const m = widths.get(p) || { w: 0, leader: 'none' };
                out.push(Decoration.inline(p, p + 1, { class: `wp-tab lead-${m.leader}`, style: `width:${m.w}px` }));
            }
        });
        return false;
    });
    return DecorationSet.create(doc, out);
}

/**
 * הקטע שאחרי הטאב (עד הטאב הבא או סוף הפסקה): הרוחב שלו, ולעשרוני — המרחק מתחילת הקטע (בצד ההתחלה של הפסקה) עד
 * הקצה השמאלי של הנקודה / הפסיק הראשונים. לפי המיקום על המסך: מספר בפסקה מימין לשמאל נכתב משמאל לימין.
 */
function segment(el, tab, next, z, rtl) {
    const r = document.createRange();
    r.setStartAfter(tab);
    if (next) r.setEndBefore(next); else r.setEnd(el, el.childNodes.length);
    const box = r.getBoundingClientRect();
    const w = box.width / z;
    let dec = null;
    const walk = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    for (let t = walk.nextNode(); t && dec === null; t = walk.nextNode()) {
        if (!r.intersectsNode(t) || tab.contains(t) || next?.contains(t)) continue;
        const i = t.data.search(/[.,]/);
        if (i < 0) continue;
        const d = document.createRange();
        d.setStart(t, i);
        d.setEnd(t, i + 1);
        const x = d.getBoundingClientRect().left;
        dec = (rtl ? box.right - x : x - box.left) / z;
    }
    return { w, dec };
}

/** מודד את הטאבים של פסקה אחת לפי הסדר; מחזיר [מיקום, {w, leader}]. */
function measurePara(view, el, stops) {
    const cs = getComputedStyle(el);
    const rtl = cs.direction === 'rtl';
    const rect = el.getBoundingClientRect();
    const z = el.offsetWidth ? rect.width / el.offsetWidth : 1;
    // השוליים: קצה הפסקה בצד ההתחלה, בלי המסגרת (רוחב המקטע) ועם ההזחה שלה (margin) — העצירות לא זזות עם ההזחה
    const origin = rtl ? rect.right - (parseFloat(cs.borderRightWidth) - parseFloat(cs.marginRight)) * z
        : rect.left + (parseFloat(cs.borderLeftWidth) - parseFloat(cs.marginLeft)) * z;
    const spans = [...el.querySelectorAll('.wp-tab')];
    for (const s of spans) s.style.width = '0px';
    const out = [];
    spans.forEach((s, k) => {
        const r = s.getBoundingClientRect();
        const x = (rtl ? origin - r.right : r.left - origin) / z;
        const t = stops.find(t => t.pos * PX > x + 0.5);
        let w, leader = 'none';
        if (t) {
            const seg = t.align === 'start' ? null : segment(el, s, spans[k + 1], z, rtl);
            w = t.pos * PX - x - (!seg ? 0 : t.align === 'end' ? seg.w : t.align === 'center' ? seg.w / 2 : seg.dec ?? seg.w);
            leader = t.leader;
        } else {
            w = DEFAULT_STOP - (x % DEFAULT_STOP + DEFAULT_STOP) % DEFAULT_STOP;
            if (w < 1) w += DEFAULT_STOP;
        }
        w = Math.max(0, Math.round(w * 10) / 10);
        s.style.width = w + 'px';
        let pos;
        try { pos = view.posAtDOM(s, 0); } catch { return; }
        out.push([pos, { w, leader }]);
    });
    return out;
}

export const TabStops = Extension.create({
    name: 'wpTabs',
    addStorage() { return { measure: null }; },
    addProseMirrorPlugins() {
        const ext = this;
        return [new Plugin({
            key: tabsKey,
            state: {
                init: (_, state) => ({ widths: new Map(), deco: build(state.doc, new Map()) }),
                apply(tr, v) {
                    const meta = tr.getMeta(tabsKey);
                    if (meta) return { widths: meta, deco: build(tr.doc, meta) };
                    if (!tr.docChanged) return v;
                    const widths = new Map();
                    for (const [p, m] of v.widths) { const q = tr.mapping.mapResult(p); if (!q.deleted) widths.set(q.pos, m); }
                    return { widths, deco: build(tr.doc, widths) };
                },
            },
            props: { decorations: state => tabsKey.getState(state).deco },
            view(view) {
                let raf = 0;
                const measure = () => {
                    raf = 0;
                    const { widths } = tabsKey.getState(view.state);
                    const next = new Map();
                    view.state.doc.descendants((n, pos) => {
                        if (!n.isTextblock) return true;
                        const stops = tabsOf(n.attrs.tabs);
                        const el = stops.length && n.textContent.includes('\t') ? view.nodeDOM(pos) : null;
                        if (el instanceof HTMLElement) for (const [p, m] of measurePara(view, el, stops)) next.set(p, m);
                        return false;
                    });
                    const same = next.size === widths.size && [...next].every(([p, m]) => {
                        const o = widths.get(p);
                        return o && Math.abs(o.w - m.w) < 0.5 && o.leader === m.leader;
                    });
                    if (!same) view.dispatch(view.state.tr.setMeta(tabsKey, next).setMeta('addToHistory', false));
                };
                const schedule = () => { if (!raf) raf = requestAnimationFrame(measure); };
                ext.storage.measure = schedule;
                document.fonts?.ready.then(schedule);
                schedule();
                return {
                    update: (v, prev) => { if (v.state.doc !== prev.doc || tabsKey.getState(v.state) !== tabsKey.getState(prev)) schedule(); },
                    destroy: () => { if (raf) cancelAnimationFrame(raf); ext.storage.measure = null; },
                };
            },
        })];
    },
});
