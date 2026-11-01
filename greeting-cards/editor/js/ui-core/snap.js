/**
 * ui-core/snap.js — הצמדה: קצוות ומרכזים של תיבות ("קווים חכמים"), נקודות, ורשת. טהור, בלי DOM.
 * המקור: vector-studio/assets/js/snap.js — בלי התלות במודל שלו: האפליקציה מעבירה את רשימת התיבות (לוחות, עמודים,
 * אובייקטים גלויים שאינם בבחירה). ההכרעות: docs/shared-code/stage5-handles.md (תשובות הווקטורי והסטודיו).
 *
 *   const s = snapper({ boxes: [[x, y, w, h], …], points: [[x, y], …], tol: 6 / zoom, grid: 10, origin: [0, 0] });
 *   s.box([x, y, w, h])      → { dx, dy, guides }   הזזת תיבה: הקצוות והמרכז שלה מול כל היעדים
 *   s.point(x, y, 'xy')      → { x, y, guides }     נקודה (קצה בשינוי גודל) — axes: אילו צירים מותר להצמיד
 *
 * tol — ביחידות המסמך (סף בפיקסלי מסך חלקי הזום, כך שההצמדה מרגישה אותו דבר בכל הגדלה). הקרוב ביותר מנצח בכל ציר.
 * guides: [{ axis: 'x' | 'y', v }] — האפליקציה מציירת. הצמדה לרשת לא מחזירה קו. grid: מספר (0 — כבוי).
 * points — יעדים נקודתיים (עוגני נתיבים); נכללים בשני הצירים.
 */

/** קווי היעד: { xs, ys } — קצה, מרכז וקצה של כל תיבה, ו-x / y של כל נקודה. */
export function targets(boxes = [], points = []) {
    const xs = [], ys = [];
    for (const [x, y, w, h] of boxes) { xs.push(x, x + w / 2, x + w); ys.push(y, y + h / 2, y + h); }
    for (const [x, y] of points) { xs.push(x); ys.push(y); }
    return { xs, ys };
}

/** הקו הקרוב ביותר לאחד הערכים: { d, at } — d להוספה, at — הקו. null אם אין בטווח. */
export function nearest(values, lines, tol) {
    let best = null;
    for (const v of values) for (const t of lines) {
        const d = t - v;
        if (Math.abs(d) <= tol && (!best || Math.abs(d) < Math.abs(best.d))) best = { d, at: t };
    }
    return best;
}

function gridNearest(values, origin, grid, tol) {
    let best = null;
    for (const v of values) {
        const t = origin + Math.round((v - origin) / grid) * grid, d = t - v;
        if (Math.abs(d) <= tol && (!best || Math.abs(d) < Math.abs(best.d))) best = { d, at: t, grid: true };
    }
    return best;
}

export function snapper({ boxes = [], points = [], tol = 6, grid = 0, origin = [0, 0] } = {}) {
    const t = targets(boxes, points);
    const pick = (vals, lines, o) => {
        const a = nearest(vals, lines, tol), b = grid ? gridNearest(vals, o, grid, tol) : null;
        if (a && b) return Math.abs(a.d) <= Math.abs(b.d) ? a : b;
        return a || b;
    };
    return {
        box([x, y, w, h]) {
            const sx = pick([x, x + w / 2, x + w], t.xs, origin[0]);
            const sy = pick([y, y + h / 2, y + h], t.ys, origin[1]);
            return { dx: sx?.d || 0, dy: sy?.d || 0, guides: guides(sx, sy) };
        },
        point(px, py, axes = 'xy') {
            const sx = axes.includes('x') ? pick([px], t.xs, origin[0]) : null;
            const sy = axes.includes('y') ? pick([py], t.ys, origin[1]) : null;
            return { x: px + (sx?.d || 0), y: py + (sy?.d || 0), guides: guides(sx, sy) };
        },
    };
}

function guides(sx, sy) {
    const g = [];
    if (sx && !sx.grid) g.push({ axis: 'x', v: sx.at });
    if (sy && !sy.grid) g.push({ axis: 'y', v: sy.at });
    return g;
}
