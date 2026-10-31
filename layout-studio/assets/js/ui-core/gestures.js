/**
 * ui-core/gestures.js — מחבר קנבס לתצוגה: גלגלת, צביטה בשתי אצבעות, הזזה (רווח / גלגלת לחוצה / כלי יד),
 * ולחיצה כפולה. החשבון — viewport.js. המקור: עורך הרסטר (editor.js), מוכלל לכל האפליקציות.
 *
 *   const g = bindViewport(stage, {
 *       get: () => view,                         // { zoom, x, y }
 *       set: v => { view = v; draw(); },
 *       pointer: {                               // רשות — הכלי של האפליקציה (ציור, בחירה, גרירה)
 *           down(e, [dx, dy]) { … return true },   // true — הכלי לוקח את המצביע; false — זו הזזה / כלום
 *           move(e, points) { … },                // points: [{ x, y, pressure }] בקואורדינטות מסמך, כולל coalesced
 *           up(e, [dx, dy]) { … },
 *           cancel() { … },                      // אצבע שנייה הגיעה — הפעולה מתבטלת בלי עקבות
 *       },
 *       hand: () => tool === 'hand',             // רשות — אצבע / עכבר אחד מזיז את התצוגה
 *       ignore: '.tools, .tool-opts',            // רשות — לחיצה שם היא לחיצה על כפתור, לא על הקנבס
 *       panOnEmpty: e => e.pointerType === 'touch',  // רשות — כש-pointer.down החזיר false: אצבע אחת מזיזה
 *                                                // (true / פונקציה). בווקטורי: מגע על רקע ריק מזיז, עכבר — מלבן בחירה
 *       onTap: (e, [dx, dy]) => …,               // רשות — הקשה בלי תזוזה (פחות מ-8px, פחות מחצי שנייה) שלא נלקחה
 *                                                // ע"י הכלי — למשל ביטול בחירה בהקשה על רקע ריק
 *       onDoubleClick: (e, [sx, sy]) => …,       // רשות
 *   });
 *   g.panning   // true בזמן הזזה / צביטה
 *   g.destroy()
 *
 * הכללים (כמו בכל עורך): אצבע / עט / עכבר אחד — של הכלי. אצבע שנייה — צביטה: הפעולה של הכלי מתבטלת. רווח לחוץ,
 * גלגלת לחוצה או hand() — הזזה. גלגלת — הזזה; Ctrl / ⌘ + גלגלת — זום סביב הסמן. מעבר מצביטה לאצבע אחת — בלי קפיצה.
 * Safari: צביטה בדף מגדילה את כל הדף — נחסם; רק הקנבס מתקרב.
 * ה-CSS של הקנבס צריך touch-action: none (אחרת הדפדפן לוקח את המגע לגלילה).
 */
import { pinch, wheel as wheelView, screenToDoc } from './viewport.js';

export function bindViewport(el, { get, set, pointer = null, hand = null, ignore = null, onDoubleClick = null, wheel = true,
                                       panOnEmpty = false, onTap = null } = {}) {
    const pointers = new Map();
    let gesture = null;     // { kind: 'pinch' | 'pan', view, a, b } / { kind: 'pan', view, p }
    let owner = null;       // pointerId שהכלי לקח
    let lastOwner = null;   // המיקום האחרון שלו — אם תגיע אצבע שנייה, הצביטה מתחילה ממנו
    let space = false;
    let tap = null;         // { id, x, y, t } — מצביע יחיד שלא נלקח ע"י הכלי: אם יורם בלי לזוז, זו הקשה

    const local = e => { const r = el.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    const toDoc = p => screenToDoc(get(), p[0], p[1]);
    const capture = id => { try { el.setPointerCapture(id); } catch { /* אירוע מדומה / מצביע שכבר עזב */ } };

    function regroup() {
        const pts = [...pointers.values()];
        gesture = pts.length >= 2 ? { kind: 'pinch', view: get(), a: pts[0], b: pts[1] }
            : pts.length === 1 ? { kind: 'pan', view: get(), p: pts[0] } : null;
        el.classList.toggle('panning', !!gesture);
    }

    const onDown = e => {
        if (ignore && e.target.closest?.(ignore)) return;
        if (e.pointerType === 'mouse' && e.button !== 0 && e.button !== 1) return;
        capture(e.pointerId);
        const p = local(e);
        // אצבע שנייה בזמן פעולה של הכלי — צביטה: הפעולה מתבטלת
        tap = null;
        if (owner !== null && e.pointerType === 'touch') {
            pointer?.cancel?.();
            pointers.set(owner, lastOwner);
            owner = null;
        }
        pointers.set(e.pointerId, p);
        const panOnly = e.button === 1 || space || pointers.size > 1 || !!hand?.();
        if (!panOnly && pointers.size === 1 && pointer?.down(e, toDoc(p))) {
            owner = e.pointerId;
            lastOwner = p;
            pointers.delete(e.pointerId);
            return;
        }
        if (pointers.size === 1 && !panOnly) tap = { id: e.pointerId, x: p[0], y: p[1], t: e.timeStamp || performance.now() };
        // הכלי לא לקח: עם panOnEmpty — אצבע אחת מזיזה; בלעדיו — האצבע נשמרת (אם תגיע שנייה — צביטה), אבל לבד לא זזה
        const empty = typeof panOnEmpty === 'function' ? panOnEmpty(e, toDoc(p)) : panOnEmpty;
        if (!panOnly && pointers.size === 1 && !empty) { gesture = null; return; }
        regroup();
    };

    const onMove = e => {
        if (e.pointerId === owner) {
            const evs = e.getCoalescedEvents?.() || [];
            const list = (evs.length ? evs : [e]).map(ev => { const [x, y] = toDoc(local(ev)); return { x, y, pressure: ev.pressure }; });
            lastOwner = local(e);
            pointer.move?.(e, list);
            return;
        }
        if (!pointers.has(e.pointerId)) return;
        const q = local(e);
        if (tap?.id === e.pointerId && Math.hypot(q[0] - tap.x, q[1] - tap.y) > 8) tap = null;
        if (!gesture) return;
        pointers.set(e.pointerId, q);
        const pts = [...pointers.values()];
        if (gesture.kind === 'pinch' && pts.length >= 2) set(pinch(gesture.view, gesture.a, gesture.b, pts[0], pts[1]));
        else if (gesture.kind === 'pan') {
            const [x, y] = pts[0];
            set({ ...gesture.view, x: gesture.view.x + x - gesture.p[0], y: gesture.view.y + y - gesture.p[1] });
        }
    };

    const onUp = e => {
        if (e.pointerId === owner) {
            owner = null;
            if (e.type === 'pointercancel') { pointer.cancel?.(); return; }
            // הקטע האחרון של קו יכול להגיע רק עם ההרמה — בלעדיו סוף הקו נחתך
            const [x, y] = toDoc(local(e));
            pointer.move?.(e, [{ x, y, pressure: e.pressure }]);
            pointer.up?.(e, [x, y]);
            return;
        }
        if (!pointers.delete(e.pointerId)) return;
        const t = tap;
        tap = null;
        if (t?.id === e.pointerId && e.type !== 'pointercancel' && (e.timeStamp || performance.now()) - t.t < 500) {
            const q = local(e);
            if (Math.hypot(q[0] - t.x, q[1] - t.y) <= 8) onTap?.(e, toDoc(q));
        }
        regroup();   // מצביטה לאצבע אחת — ממשיכים מהמקום הנוכחי
    };

    const onWheel = e => {
        e.preventDefault();
        const [sx, sy] = local(e);
        set(wheelView(get(), e, sx, sy, { pageHeight: el.clientHeight }));
    };

    const typing = t => t?.closest?.('input, textarea, select, button, [contenteditable="true"]');
    const onKeyDown = e => { if (e.code === 'Space' && !typing(e.target)) { space = true; el.classList.add('hand'); e.preventDefault(); } };
    const onKeyUp = e => { if (e.code === 'Space') { space = false; el.classList.remove('hand'); } };
    const onDbl = e => onDoubleClick?.(e, local(e));
    const noPageZoom = e => e.preventDefault();

    el.addEventListener('pointerdown', onDown);
    el.addEventListener('pointermove', onMove);
    el.addEventListener('pointerup', onUp);
    el.addEventListener('pointercancel', onUp);
    if (wheel) el.addEventListener('wheel', onWheel, { passive: false });
    if (onDoubleClick) el.addEventListener('dblclick', onDbl);
    addEventListener('keydown', onKeyDown);
    addEventListener('keyup', onKeyUp);
    document.addEventListener('gesturestart', noPageZoom);
    document.addEventListener('gesturechange', noPageZoom);

    return {
        get panning() { return !!gesture; },
        get space() { return space; },
        destroy() {
            el.removeEventListener('pointerdown', onDown);
            el.removeEventListener('pointermove', onMove);
            el.removeEventListener('pointerup', onUp);
            el.removeEventListener('pointercancel', onUp);
            el.removeEventListener('wheel', onWheel);
            el.removeEventListener('dblclick', onDbl);
            removeEventListener('keydown', onKeyDown);
            removeEventListener('keyup', onKeyUp);
            document.removeEventListener('gesturestart', noPageZoom);
            document.removeEventListener('gesturechange', noPageZoom);
        },
    };
}
