/**
 * ui-core/handles.js — ידיות על המסך: שינוי גודל מפינה ומצד, סיבוב, הצמדה, שורת מצב. אחד לכל האפליקציות.
 * החשבון — transform.js; ההצמדה — snap.js. ההכרעות והתשובות של וקטורי, רסטר והסטודיו: docs/shared-code/stage5-handles.md.
 *
 *   const hd = handles(overlay, {                 // overlay — אלמנט מעל הקנבס, באותו גודל (position: absolute; inset: 0)
 *       view: () => view,                         // { zoom, x, y } — כמו viewport.js
 *       keepRatio: 'corners',                     // 'corners' (ברירת מחדל) | 'always' | 'never' | handle => bool. Shift הופך.
 *       rotate: true,
 *       snap: () => snapper({ boxes, tol: 6 / view.zoom }),   // רשות; Ctrl / ⌘ לחוץ — בלי הצמדה
 *       dir: 'rtl',                               // כיוון החץ בשורת המצב; ברירת מחדל — direction של ה-overlay.
 *                                                 // במה ltr (קנבס) עם שורת מצב בעברית → dir: 'rtl' (לקח מהווקטורי)
 *       status: text => …,                        // רשות — שורת מצב משלך; בלי — פס קטן ב-overlay (statusAt: { top, insetInline })
 *       statusText: (t, t0, handle) => …,         // רשות — הטקסט בשינוי גודל (ברירת מחדל "100×60 ← 150×90")
 *       onGuides: guides => …,                    // רשות — האפליקציה מציירת את קווי העזר בעצמה
 *       onPreview: t => …, onCommit: (t, t0) => …, onCancel: t0 => …,
 *   });
 *   hd.show(t, w, h)   hd.update()   hd.hide()           // t כמו ב-transform.js; update — אחרי זום / הזזה / ציור
 *   hd.down(e, [sx, sy]) → true / false                 // מ-pointer.down של bindViewport: ידית נלקחה? (false — הכלי ממשיך)
 *   hd.move(e, [sx, sy])   hd.up(e)   hd.cancel()        // cancel — אצבע שנייה: חוזרים ל-t0 בלי עקבות
 *   hd.at([sx, sy], e) → 'rotate' | [hx, hy] | null      // מה מתחת לסמן (לסמן העכבר)
 *
 * מצב 'rect' (חיתוך ברסטר): mode: 'rect' — מלבן ישר בלי סיבוב, show({ x, y, w, h }); חופשי כברירת מחדל (Shift — יחס),
 * עיגול לפיקסלים שלמים (round), מותר לצאת מהקנבס, וקצה שעבר את הנגדי — מתנרמל. onPreview / onCommit מקבלים מלבן.
 *
 * המראה (סטודיו העיצוב, #931): פינה — עיגול 14px, צד — פס 9×28 שמסתובב עם הצד, ידית סיבוב 28px מעל; גודל קבוע במסך בכל
 * זום. אזור מגע: 22px באצבע, 10px בעכבר. בזמן עריכת טקסט / נקודות — hide().
 */
import { HANDLES, handlePos, dragHandle, dragRotate, snapAngle, MAGNET } from './transform.js';
import { screenToDoc, docToScreen } from './viewport.js';
import { ltr } from './dom.js';

const NS = 'http://www.w3.org/2000/svg';
const svg = (tag, attrs = {}) => { const el = document.createElementNS(NS, tag); for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v); return el; };
const deg = r => Math.round(((r * 180 / Math.PI) % 360 + 360) % 360);

export function handles(overlay, opts = {}) {
    const o = { mode: 'transform', keepRatio: opts.mode === 'rect' ? 'never' : 'corners', rotate: true, corners: true, sides: true, round: false, minSize: 1,
        magnet: MAGNET, magnetTol: 6, rotateStep: 15, rotateOffset: 28, touch: 22, mouse: 10, statusAt: { top: 8 }, ...opts };
    const rectMode = o.mode === 'rect';
    if (rectMode) o.rotate = false;

    const root = svg('svg', { class: 'uic-handles', 'aria-hidden': 'true' });
    const frame = svg('polygon', { class: 'uic-frame' });
    const stem = svg('line', { class: 'uic-stem' });
    const guideG = svg('g', { class: 'uic-guides' });
    root.append(guideG, frame, stem);
    const nodes = new Map();
    for (const hd of HANDLES) {
        const corner = hd[0] && hd[1];
        if ((corner && !o.corners) || (!corner && !o.sides)) continue;
        const n = corner ? svg('circle', { r: 7, class: 'uic-h uic-corner' }) : svg('rect', { width: 9, height: 28, rx: 4.5, class: 'uic-h uic-side' });
        nodes.set(hd.join(','), n);
        root.append(n);
    }
    const rot = svg('circle', { r: 7, class: 'uic-h uic-rotate' });
    if (o.rotate) root.append(rot);
    overlay.append(root);

    let statusEl = null;
    if (!o.status) {
        statusEl = document.createElement('div');
        statusEl.className = 'uic-status';
        statusEl.setAttribute('role', 'status');
        statusEl.hidden = true;
        Object.assign(statusEl.style, { top: (o.statusAt.top ?? 8) + 'px' });
        if (o.statusAt.insetInline != null) Object.assign(statusEl.style, { insetInlineStart: o.statusAt.insetInline + 'px', translate: 'none' });
        overlay.append(statusEl);
    }
    // callbacks נקראים רק כשמשהו משתנה — לא מהבנאי, ולא ב-hide() כשאין מה לנקות (לקח מהווקטורי: TDZ, קווי עזר נמחקו)
    let said = '', guideKey = '[]';
    const say = text => { if (text === said) return; said = text; if (o.status) o.status(text); else { statusEl.textContent = text; statusEl.hidden = !text; } };
    const rtl = (o.dir || getComputedStyle(overlay).direction || document.dir) === 'rtl';
    const arrow = rtl ? '←' : '→';

    let t = null, w = 1, h = 1, rect = null, drag = null, visible = false;

    // ---- מצב rect: המלבן כ-t על תוכן 1×1, כדי להשתמש באותו ציור ----
    const rectT = r => ({ cx: r.x + r.w / 2, cy: r.y + r.h / 2, sx: r.w, sy: r.h, angle: 0 });

    function show(a, ww = 1, hh = 1) {
        if (rectMode) { rect = { ...a }; t = rectT(rect); w = h = 1; }
        else { t = { ...a }; w = ww; h = hh; }
        visible = true;
        root.style.display = '';
        update();
    }

    function hide() { visible = false; root.style.display = 'none'; guides([]); if (!drag) say(''); }

    function screen(p) { return docToScreen(o.view(), p[0], p[1]); }

    function update() {
        if (!visible || !t) return;
        const pts = HANDLES.map(hd => screen(handlePos(t, w, h, hd)));
        const corners = [pts[0], pts[2], pts[4], pts[6]];
        frame.setAttribute('points', corners.map(p => p.join(',')).join(' '));
        const a = deg(t.angle);
        HANDLES.forEach((hd, i) => {
            const n = nodes.get(hd.join(','));
            if (!n) return;
            const [x, y] = pts[i];
            if (hd[0] && hd[1]) { n.setAttribute('cx', x); n.setAttribute('cy', y); return; }
            // פס לאורך הצד: צד שמאל/ימין — אנכי; עליון/תחתון — אופקי; ומסתובב עם התוכן
            const turn = a + (hd[1] ? 90 : 0);
            n.setAttribute('x', x - 4.5); n.setAttribute('y', y - 14);
            n.setAttribute('transform', `rotate(${turn} ${x} ${y})`);
        });
        if (o.rotate) {
            const top = pts[1], c = screen([t.cx, t.cy]);
            const len = Math.hypot(top[0] - c[0], top[1] - c[1]) || 1;
            const k = (len + o.rotateOffset) / len;
            const r = [c[0] + (top[0] - c[0]) * k, c[1] + (top[1] - c[1]) * k];
            rot.setAttribute('cx', r[0]); rot.setAttribute('cy', r[1]);
            stem.setAttribute('x1', top[0]); stem.setAttribute('y1', top[1]); stem.setAttribute('x2', r[0]); stem.setAttribute('y2', r[1]);
            rot._at = r;
        }
        root._pts = pts;
    }

    function at(s, e) {
        if (!visible || !root._pts) return null;
        const tol = e?.pointerType === 'mouse' ? o.mouse : o.touch;
        const d = p => Math.hypot(p[0] - s[0], p[1] - s[1]);
        if (o.rotate && rot._at && d(rot._at) <= tol) return 'rotate';
        let best = null, bd = Infinity;
        HANDLES.forEach((hd, i) => {
            if (!nodes.has(hd.join(','))) return;
            const dd = d(root._pts[i]);
            if (dd <= tol && dd < bd) { bd = dd; best = hd; }
        });
        return best;
    }

    function down(e, s) {
        const hit = at(s, e);
        if (!hit) return false;
        drag = { hit, t0: { ...t }, rect0: rect && { ...rect }, start: screenToDoc(o.view(), s[0], s[1]) };
        return true;
    }

    const ratioFor = hd => {
        const k = o.keepRatio;
        if (typeof k === 'function') return !!k(hd);
        if (k === 'always') return true;
        if (k === 'never') return false;
        return !!(hd[0] && hd[1]);        // 'corners'
    };

    function move(e, s) {
        if (!drag) return;
        let p = screenToDoc(o.view(), s[0], s[1]);
        if (drag.hit === 'rotate') {
            const shift = !!e.shiftKey;
            const opt = shift ? { step: o.rotateStep } : { magnet: o.magnet, tol: o.magnetTol };
            t = dragRotate(drag.t0, drag.start, p, opt);
            const locked = !shift && snapAngle(t.angle, { magnet: o.magnet, tol: 0.001 }).snapped;
            say(`סיבוב ${deg(t.angle)}°${locked ? ' ✓' : ''}`);
            update();
            o.onPreview?.({ ...t });
            return;
        }
        const hd = drag.hit;
        const keep = ratioFor(hd) !== !!e.shiftKey;            // Shift הופך
        // הצמדה: רק לקצה שזז, רק בצירים של הידית, לא כשמסובב, ולא עם Ctrl / ⌘
        const sn = !(e.ctrlKey || e.metaKey) && !drag.t0.angle && o.snap?.();
        if (sn) {
            const r = sn.point(p[0], p[1], (hd[0] ? 'x' : '') + (hd[1] ? 'y' : ''));
            p = [r.x, r.y];
            guides(r.guides);
        } else guides([]);

        if (rectMode) {
            const r0 = drag.rect0;
            let x0 = r0.x, y0 = r0.y, x1 = r0.x + r0.w, y1 = r0.y + r0.h;
            if (hd[0] < 0) x0 = p[0]; if (hd[0] > 0) x1 = p[0];
            if (hd[1] < 0) y0 = p[1]; if (hd[1] > 0) y1 = p[1];
            if (keep && hd[0] && hd[1] && r0.w && r0.h) {        // יחס: לפי הציר שזז יותר
                const ar = r0.w / r0.h, nw = Math.abs(x1 - x0), nh = Math.abs(y1 - y0);
                if (nw / ar > nh) { const H = nw / ar; if (hd[1] < 0) y0 = y1 - Math.sign(y1 - y0 || 1) * H; else y1 = y0 + Math.sign(y1 - y0 || 1) * H; }
                else { const W = nh * ar; if (hd[0] < 0) x0 = x1 - Math.sign(x1 - x0 || 1) * W; else x1 = x0 + Math.sign(x1 - x0 || 1) * W; }
            }
            let r = { x: Math.min(x0, x1), y: Math.min(y0, y1), w: Math.abs(x1 - x0), h: Math.abs(y1 - y0) };   // נרמול
            if (o.round) r = { x: Math.round(r.x), y: Math.round(r.y), w: Math.max(1, Math.round(r.x + r.w) - Math.round(r.x)), h: Math.max(1, Math.round(r.y + r.h) - Math.round(r.y)) };
            say(`${ltr(`${Math.round(r0.w)}×${Math.round(r0.h)}`)} ${arrow} ${ltr(`${Math.round(r.w)}×${Math.round(r.h)}`)}`);
            rect = r; t = rectT(r);
            update();
            o.onPreview?.({ ...r });
            return;
        }

        const corner = hd[0] && hd[1];
        let nt = dragHandle(drag.t0, w, h, hd, p, { free: corner ? !keep : true, fromCenter: !!e.altKey, minSize: o.minSize });
        if (!corner && keep) {                                  // צד עם יחס ('always'): גם הציר השני באותו יחס
            const f = hd[0] ? nt.sx / drag.t0.sx : nt.sy / drag.t0.sy;
            nt = hd[0] ? { ...nt, sy: drag.t0.sy * f } : { ...nt, sx: drag.t0.sx * f };
        }
        t = nt;
        const W0 = Math.abs(w * drag.t0.sx), H0 = Math.abs(h * drag.t0.sy), W1 = Math.abs(w * t.sx), H1 = Math.abs(h * t.sy);
        say(o.statusText ? o.statusText(t, drag.t0, hd) : `${ltr(`${Math.round(W0)}×${Math.round(H0)}`)} ${arrow} ${ltr(`${Math.round(W1)}×${Math.round(H1)}`)}`);
        update();
        o.onPreview?.({ ...t });
    }

    function up() {
        if (!drag) return;
        const d = drag;
        drag = null;
        guides([]);
        say('');
        if (rectMode) o.onCommit?.({ ...rect }, { ...d.rect0 });
        else o.onCommit?.({ ...t }, { ...d.t0 });
    }

    function cancel() {
        if (!drag) return;
        const d = drag;
        drag = null;
        guides([]);
        say('');
        if (rectMode) { rect = { ...d.rect0 }; t = rectT(rect); update(); o.onCancel?.({ ...rect }); }
        else { t = { ...d.t0 }; update(); o.onCancel?.({ ...t }); }
    }

    function guides(list) {
        const key = JSON.stringify(list);
        if (key === guideKey) return;
        guideKey = key;
        guideG.replaceChildren();
        if (o.onGuides) { o.onGuides(list); return; }
        const r = overlay.getBoundingClientRect();
        for (const g of list) {
            const [sx, sy] = screen(g.axis === 'x' ? [g.v, 0] : [0, g.v]);
            guideG.append(svg('line', g.axis === 'x' ? { x1: sx, x2: sx, y1: 0, y2: r.height } : { x1: 0, x2: r.width, y1: sy, y2: sy }));
        }
    }

    hide();
    return {
        show, hide, update, down, move, up, cancel, at,
        get dragging() { return !!drag; },
        get value() { return rectMode ? rect && { ...rect } : t && { ...t }; },
        destroy() { root.remove(); statusEl?.remove(); },
    };
}
