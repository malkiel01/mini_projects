/**
 * הסרגל (כמו הסרגל האופקי של Word): מעל הדף, ברוחב העמוד שבו הסמן ובזום שלו — השוליים (אפור), שנתות בס"מ מהשוליים
 * בצד ההתחלה (מימין, כמו ב-Word בעברית), וסמנים של הפסקה שבה הסמן:
 *   ▭ הזחה (indent — רמות של 36 נק', כמו "הגדלת הזחה")  ▽ שורה ראשונה (firstLine חיובי)  △ הזחה תלויה (firstLine שלילי)
 *   עצירות טאב (attrs.tabs — נק' מהשוליים בצד ההתחלה, כמו ב-tabs.js).
 * גרירה: סמן — הפסקאות שבבחירה (צעד ביטול אחד, כשעוזבים); גבול השוליים — השוליים של המקטע; לחיצה על הסרגל —
 * עצירת טאב חדשה; גרירת עצירה אל מחוץ לסרגל — מחיקה. המיקומים נמדדים מה-DOM (גם בתא של טבלה ובפסקה משמאל לימין).
 *
 * והסרגל האנכי (vel — כמו ב-Word): בצד הדף, בגובה העמוד שבו הסמן (ואם הוא מחוץ לתצוגה — העמוד שבמרכזה) ובזום שלו,
 * צמוד אליו גם בגלילה: השוליים העליונים והתחתונים באפור, שנתות בס"מ מהשוליים העליונים; גבולות השוליים נגררים.
 *
 *   const ruler = createRuler({ editor, h, pagesLayer, zoom: () => z, sectionPage: () => page, setMargin: (side, mm) => … });
 *   stage.prepend(ruler.el); stage.before(ruler.vel); ruler.update() — אחרי עימוד / זום / תזוזת סמן; ruler.toggle(), ruler.isOn()
 */

import { tabsOf } from './tabs.js';

const PT = 96 / 72;          // px לנקודה
const MM = 96 / 25.4;        // px למ"מ
const INDENT = 36;           // נק' לרמת הזחה (כמו indent בעורך וב-PDF)
const TEXT = ['paragraph', 'heading'];
const KEY = 'wp-ruler';

export function createRuler({ editor, h, pagesLayer, sectionPage, setMargin, defaultOn = true }) {
    const track = h('div.wp-ruler-track');
    const el = h('div.wp-ruler', { role: 'group', 'aria-label': 'סרגל' }, track);
    const vtrack = h('div.wp-vruler-track');
    const vel = h('div.wp-vruler', { role: 'group', 'aria-label': 'סרגל אנכי' }, vtrack);
    let on = defaultOn;
    try { const v = localStorage.getItem(KEY); if (v !== null) on = v === '1'; } catch { /* בלי אחסון — ברירת המחדל */ }
    el.hidden = vel.hidden = !on;
    let drag = null;   // בזמן גרירה לא בונים מחדש (הסמן הנגרר זז לבד)
    let geo = null;    // הגיאומטריה האחרונה (update) — לחישוב בזמן גרירה ולחיצה
    let raf = 0;

    /** הפסקאות שבבחירה (או זו שבה הסמן). */
    function blocks() {
        const { from, to } = editor.state.selection;
        const out = [];
        editor.state.doc.nodesBetween(from, to, (n, pos) => { if (TEXT.includes(n.type.name)) { out.push([n, pos]); return false; } });
        return out;
    }

    function current() {
        const $f = editor.state.selection.$from;
        for (let d = $f.depth; d > 0; d--) if (TEXT.includes($f.node(d).type.name)) return { node: $f.node(d), pos: $f.before(d) };
        return null;
    }

    /** כל הפסקאות שבבחירה: attrs חדשים (fn מקבל את הישנים) — טרנזקציה אחת. */
    function setBlocks(fn) {
        const tr = editor.state.tr;
        for (const [n, pos] of blocks()) {
            const next = { ...n.attrs, ...fn(n.attrs) };
            if (JSON.stringify(next) !== JSON.stringify(n.attrs)) tr.setNodeMarkup(pos, undefined, next);
        }
        if (tr.docChanged) editor.view.dispatch(tr);
        editor.view.focus();
    }

    function measure() {
        const frames = [...pagesLayer.children];
        if (!frames.length) return null;
        let y = null;
        try { y = editor.view.coordsAtPos(editor.state.selection.from).top; } catch { /* לפני הציור */ }
        const frame = (y !== null && frames.find(f => { const r = f.getBoundingClientRect(); return y >= r.top - 1 && y < r.bottom; })) || frames[0];
        const fr = frame.getBoundingClientRect();
        const z = fr.width / frame.offsetWidth || 1;
        const p = sectionPage();
        const g = { fr, z, rr: track.getBoundingClientRect(), left: p.margins.left * MM * z, right: p.margins.right * MM * z, para: null };
        const cur = current();
        const dom = cur && editor.view.nodeDOM(cur.pos);
        if (dom instanceof HTMLElement) {
            const cs = getComputedStyle(dom);
            const pr = dom.getBoundingClientRect();
            const rtl = cs.direction === 'rtl';
            const s = rtl ? -1 : 1;
            // קצה הפסקה בצד ההתחלה (בלי ההזחה — margin-inline-start) ומקצה התוכן שלה (משם נמדדות העצירות)
            const base = rtl ? pr.right : pr.left;
            const start0 = base - s * (parseFloat(rtl ? cs.marginRight : cs.marginLeft) || 0) * z;
            const fl = +cur.node.attrs.firstLine || 0;
            g.para = { node: cur.node, rtl, s, base, start0, fl, indent: +cur.node.attrs.indent || 0, tabs: tabsOf(cur.node.attrs.tabs) };
        }
        return g;
    }

    /** מיקום מסך → x בתוך הסרגל. */
    const rx = X => X - geo.fr.left;
    /** נק' מנקודת מוצא, בכיוון הטקסט → מיקום מסך. */
    const at = (edge, pt) => edge + geo.para.s * pt * PT * geo.z;
    /** מיקום מסך → נק' מנקודת מוצא, בכיוון הטקסט. */
    const ptOf = (edge, X) => (X - edge) * geo.para.s / geo.z / PT;

    function marker(cls, X, title, onDrag) {
        const m = h('div.wp-ruler-mk.' + cls, { title, style: { left: rx(X) + 'px' } });
        m.addEventListener('pointerdown', e => {
            e.preventDefault();
            e.stopPropagation();
            m.setPointerCapture(e.pointerId);
            const guide = h('div.wp-ruler-guide', { style: { left: m.style.left } });
            track.append(guide);
            drag = { m, guide, out: false, x: e.clientX };
            const move = ev => {
                drag.x = ev.clientX;
                drag.out = Math.abs(ev.clientY - geo.rr.top - geo.rr.height / 2) > 30;
                const x = rx(onDrag.snap ? onDrag.snap(ev.clientX) : ev.clientX);
                m.style.left = guide.style.left = x + 'px';
                m.classList.toggle('out', drag.out && !!onDrag.remove);
            };
            const up = () => {
                m.removeEventListener('pointermove', move);
                m.removeEventListener('pointerup', up);
                m.removeEventListener('pointercancel', up);
                const d = drag;
                drag = null;
                guide.remove();
                if (d.out && onDrag.remove) onDrag.remove();
                else if (Math.abs(d.x - e.clientX) > 1) onDrag.done(d.x);
                schedule();
            };
            m.addEventListener('pointermove', move);
            m.addEventListener('pointerup', up);
            m.addEventListener('pointercancel', up);
        });
        track.append(m);
        return m;
    }

    /**
     * הסרגל האנכי: העמוד שבו הסמן — ואם הוא מחוץ לתצוגה, העמוד שבמרכז התצוגה. השוליים העליונים / התחתונים של המקטע,
     * שנתות כל רבע ס"מ מהשוליים העליונים (מספר כל ס"מ), וגבולות שנגררים (setMargin('top' | 'bottom', מ"מ)).
     */
    function renderV() {
        vtrack.textContent = '';
        const frames = [...pagesLayer.children];
        if (!frames.length || vel.hidden) return;
        const vr = vel.getBoundingClientRect();
        let y = null;
        try { y = editor.view.coordsAtPos(editor.state.selection.from).top; } catch { /* לפני הציור */ }
        const seen = f => { const r = f.getBoundingClientRect(); return r.bottom > vr.top && r.top < vr.bottom; };
        const mid = (vr.top + vr.bottom) / 2;
        const frame = (y !== null && frames.find(f => { const r = f.getBoundingClientRect(); return y >= r.top - 1 && y < r.bottom && seen(f); }))
            || frames.reduce((best, f) => { const r = f.getBoundingClientRect(); const d = Math.abs((r.top + r.bottom) / 2 - mid); return !best || d < best.d ? { f, d } : best; }, null).f;
        const fr = frame.getBoundingClientRect();
        const z = fr.width / frame.offsetWidth || 1;
        const p = sectionPage();
        const top = p.margins.top * MM * z, bottom = p.margins.bottom * MM * z, H = fr.height;
        Object.assign(vtrack.style, { top: fr.top - vr.top + 'px', height: H + 'px' });
        vtrack.append(h('div.wp-vruler-margin', { style: { top: 0, height: top + 'px' } }), h('div.wp-vruler-margin', { style: { bottom: 0, height: bottom + 'px' } }));
        const cmPx = 10 * MM * z;
        for (let q = 1; top + q * cmPx / 4 < H - bottom - 1; q++) {
            const y0 = top + q * cmPx / 4;
            vtrack.append(q % 4 ? h('div.wp-vruler-tick' + (q % 2 ? '' : '.half'), { style: { top: y0 + 'px' } })
                : h('div.wp-vruler-num', { style: { top: y0 + 'px' } }, String(q / 4)));
        }
        const mm = (side, Y) => Math.max(0, Math.min(80, Math.round((side === 'top' ? Y - fr.top : fr.bottom - Y) / z / MM)));
        for (const side of ['top', 'bottom']) {
            const m = h('div.wp-vruler-edge', { title: side === 'top' ? 'שוליים עליונים — גרירה' : 'שוליים תחתונים — גרירה',
                style: { top: (side === 'top' ? top : H - bottom) + 'px' } });
            m.dataset.side = side;
            m.addEventListener('pointerdown', e => {
                e.preventDefault();
                m.setPointerCapture(e.pointerId);
                drag = { m };
                let Y = e.clientY;
                const snap = Y0 => (side === 'top' ? fr.top + mm(side, Y0) * MM * z : fr.bottom - mm(side, Y0) * MM * z) - fr.top;
                const move = ev => { Y = ev.clientY; m.style.top = snap(Y) + 'px'; };
                const up = () => {
                    m.removeEventListener('pointermove', move);
                    m.removeEventListener('pointerup', up);
                    m.removeEventListener('pointercancel', up);
                    drag = null;
                    if (Math.abs(Y - e.clientY) > 1) setMargin(side, mm(side, Y));
                    schedule();
                };
                m.addEventListener('pointermove', move);
                m.addEventListener('pointerup', up);
                m.addEventListener('pointercancel', up);
            });
            vtrack.append(m);
        }
    }

    function render() {
        raf = 0;
        if (!on || drag || editor.isDestroyed) return;
        el.hidden = vel.hidden = false;
        renderV();
        track.textContent = '';
        geo = null;
        const g = measure();
        if (!g) return;
        geo = g;
        const rr = el.getBoundingClientRect();
        Object.assign(track.style, { left: g.fr.left - rr.left + 'px', width: g.fr.width + 'px' });
        g.rr = track.getBoundingClientRect();
        const W = g.fr.width;
        track.append(h('div.wp-ruler-margin', { style: { left: 0, width: g.left + 'px' } }), h('div.wp-ruler-margin', { style: { right: 0, width: g.right + 'px' } }));
        // שנתות: מהשוליים בצד ההתחלה (ימין) שמאלה; כל ס"מ — מספר, כל חצי — שנתה בינונית, כל רבע — קטנה
        const zero = W - g.right, cmPx = 10 * MM * g.z;
        for (let q = 1; zero - q * cmPx / 4 > g.left + 1; q++) {
            const x = zero - q * cmPx / 4;
            track.append(q % 4 ? h('div.wp-ruler-tick' + (q % 2 ? '' : '.half'), { style: { left: x + 'px' } })
                : h('div.wp-ruler-num', { style: { left: x + 'px' } }, String(q / 4)));
        }
        // גבולות השוליים — גרירה משנה את השוליים של המקטע
        const mm = (side, X) => Math.max(0, Math.min(80, Math.round((side === 'right' ? g.fr.right - X : X - g.fr.left) / g.z / MM)));
        for (const side of ['right', 'left']) {
            marker('wp-ruler-edge', side === 'right' ? g.fr.right - g.right : g.fr.left + g.left, side === 'right' ? 'שוליים ימניים — גרירה' : 'שוליים שמאליים — גרירה', {
                snap: X => (side === 'right' ? g.fr.right - mm(side, X) * MM * g.z : g.fr.left + mm(side, X) * MM * g.z),
                done: X => setMargin(side, mm(side, X)),
            }).dataset.side = side;
        }
        const P = g.para;
        if (!P) return;
        // הזחה: רמות של 36 נק' מקצה התוכן; שורה ראשונה / תלויה — עד 200 נק' מקצה הפסקה
        const level = X => Math.max(0, Math.min(8, Math.round(ptOf(P.start0, X) / INDENT)));
        const flOf = X => Math.max(0, Math.min(200, Math.round(ptOf(P.base, X))));
        marker('wp-ruler-first', at(P.base, Math.max(0, P.fl)), 'הזחת שורה ראשונה', {
            snap: X => at(P.base, flOf(X)),
            done: X => setBlocks(() => ({ firstLine: flOf(X) || null })),
        });
        marker('wp-ruler-hang', at(P.base, Math.max(0, -P.fl)), 'הזחה תלויה', {
            snap: X => at(P.base, flOf(X)),
            done: X => setBlocks(() => ({ firstLine: -flOf(X) || null })),
        });
        marker('wp-ruler-indent', P.base, 'הזחה (גרירה בצעדים של 1.27 ס"מ)', {
            snap: X => at(P.start0, level(X) * INDENT),
            done: X => setBlocks(() => ({ indent: level(X) })),
        });
        // עצירות טאב
        const posOf = X => Math.max(1, Math.min(1584, Math.round(ptOf(P.start0, X) * 10) / 10));
        P.tabs.forEach(t => {
            marker('wp-ruler-tab.al-' + t.align, at(P.start0, t.pos), 'עצירת טאב — גרירה; אל מחוץ לסרגל — מחיקה', {
                done: X => setBlocks(a => ({ tabs: tabsOf([...tabsOf(a.tabs).filter(x => x.pos !== t.pos), { ...t, pos: posOf(X) }]) })),
                remove: () => setBlocks(a => ({ tabs: tabsOf(a.tabs).filter(x => x.pos !== t.pos).length ? tabsOf(a.tabs).filter(x => x.pos !== t.pos) : null })),
            });
        });
    }

    // לחיצה על הסרגל (לא על סמן) — עצירת טאב חדשה (יישור לתחילה) במקום
    track.addEventListener('pointerdown', e => {
        if (e.target !== track && !e.target.classList.contains('wp-ruler-tick') && !e.target.classList.contains('wp-ruler-num')) return;
        if (!geo?.para) return;
        e.preventDefault();
        const pos = Math.round(ptOf(geo.para.start0, e.clientX) * 10) / 10;
        if (!(pos > 0)) return;
        setBlocks(a => ({ tabs: tabsOf([...tabsOf(a.tabs), { pos, align: 'start' }]) }));
    });

    function schedule() { if (on && !raf) raf = requestAnimationFrame(render); }
    editor.on('selectionUpdate', schedule);
    editor.on('transaction', schedule);
    // האנכי צמוד לעמוד — גם בגלילה (אזור הגלילה — ההורה של הסרגל האופקי, כשהוא כבר בדף)
    let scroller = null;
    const watch = () => { const s = el.parentElement; if (s && s !== scroller) { scroller?.removeEventListener('scroll', schedule); scroller = s; s.addEventListener('scroll', schedule, { passive: true }); } };
    editor.on('transaction', watch);
    window.addEventListener('resize', schedule);

    return {
        el,
        vel,
        update: () => { watch(); schedule(); },
        isOn: () => on,
        toggle() {
            on = !on;
            el.hidden = vel.hidden = !on;
            try { localStorage.setItem(KEY, on ? '1' : '0'); } catch { /* בלי אחסון */ }
            schedule();
        },
    };
}
