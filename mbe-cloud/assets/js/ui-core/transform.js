/**
 * ui-core/transform.js — חשבון טהור של הזזה, שינוי גודל וסיבוב (בלי DOM; נבדק גם ב-node).
 * המקור: raster-studio/assets/js/engine/transform.js (אותם שמות). transformShape / transformMask נשארו ברסטר.
 * ההכרעות (docs/shared-code/stage5-handles.md): פינה שומרת יחס כברירת מחדל, Shift משחרר (free); Alt — סביב המרכז;
 * סיבוב נצמד מגנטית ל-0/45/90… בטווח 6°, Shift — מדרגות של 15°; ציר באורך אפס (קו) — הפינה חופשית בציר השני.
 *
 * t = { cx, cy, sx, sy, angle } — מרכז במסמך, קנה מידה בכל ציר, זווית ברדיאנים.
 * התוכן הוא מלבן של w×h; נקודה p בו ממופה: doc = R(angle) · S(sx, sy) · (p − (w/2, h/2)) + (cx, cy)
 */

/** t ההתחלתי: התוכן במקומו — המלבן (x, y, w, h) במסמך. */
export const identity = (x, y, w, h) => ({ cx: x + w / 2, cy: y + h / 2, sx: 1, sy: 1, angle: 0 });

/** המטריצה [a, b, c, d, e, f]: x' = a·x + c·y + e, y' = b·x + d·y + f (x, y — פיקסל במרקם הצף). */
export function matrix(t, w, h) {
    const cos = Math.cos(t.angle), sin = Math.sin(t.angle);
    const a = cos * t.sx, b = sin * t.sx, c = -sin * t.sy, d = cos * t.sy;
    return [a, b, c, d, t.cx - (a * w / 2 + c * h / 2), t.cy - (b * w / 2 + d * h / 2)];
}

/** מטריצה ממסמך למסמך: התוכן יצא מהמלבן שפינתו (x0, y0) — לבחירה שזזה יחד עם התוכן. */
export function docMatrix(t, w, h, x0, y0) {
    const [a, b, c, d, e, f] = matrix(t, w, h);
    return [a, b, c, d, e - a * x0 - c * y0, f - b * x0 - d * y0];
}

export const apply = (m, x, y) => [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];

export function invert(m) {
    const [a, b, c, d, e, f] = m;
    const det = a * d - b * c;
    return [d / det, -b / det, -c / det, a / det, (c * f - d * e) / det, (b * e - a * f) / det];
}

/** ארבע הפינות במסמך: שמאל-עליון, ימין-עליון, ימין-תחתון, שמאל-תחתון (של המרקם). */
export function corners(t, w, h) {
    const m = matrix(t, w, h);
    return [[0, 0], [w, 0], [w, h], [0, h]].map(([x, y]) => apply(m, x, y));
}

/** מלבן חוסם (במספרים שלמים) של התוכן אחרי השינוי — [x0, y0, x1, y1]. */
export function bounds(t, w, h) {
    const c = corners(t, w, h);
    const xs = c.map(p => p[0]), ys = c.map(p => p[1]);
    return [Math.floor(Math.min(...xs)), Math.floor(Math.min(...ys)), Math.ceil(Math.max(...xs)), Math.ceil(Math.max(...ys))];
}

export const isIdentityAt = (t, x, y, w, h) =>
    t.sx === 1 && t.sy === 1 && t.angle === 0 && t.cx === x + w / 2 && t.cy === y + h / 2;

// ---- ידיות ----
// ידית = [hx, hy] ב-{-1, 0, 1}: פינות (±1, ±1) וצלעות (±1, 0) / (0, ±1), במערכת המקומית של התוכן.
export const HANDLES = [[-1, -1], [0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0]];

/** מיקום ידית במסמך */
export function handlePos(t, w, h, [hx, hy]) {
    return apply(matrix(t, w, h), (hx + 1) / 2 * w, (hy + 1) / 2 * h);
}

/** נקודה במסמך → מערכת מקומית סביב המרכז, בלי סיבוב (ביחידות מסמך) */
function toLocal(t, p) {
    const dx = p[0] - t.cx, dy = p[1] - t.cy;
    const cos = Math.cos(-t.angle), sin = Math.sin(-t.angle);
    return [dx * cos - dy * sin, dx * sin + dy * cos];
}
function fromLocal(t, q) {
    const cos = Math.cos(t.angle), sin = Math.sin(t.angle);
    return [t.cx + q[0] * cos - q[1] * sin, t.cy + q[0] * sin + q[1] * cos];
}

/**
  * מה נמצא מתחת לנקודה p (במסמך)? 'handle' עם הידית, 'move' בתוך התוכן, 'rotate' סמוך לפינה מבחוץ, או null.
  * tol — סבולת בפיקסלי מסמך (סבולת המסך חלקי הזום).
  */
export function hit(t, w, h, p, tol) {
    for (const hd of HANDLES) {
        const q = handlePos(t, w, h, hd);
        if (Math.hypot(q[0] - p[0], q[1] - p[1]) <= tol) return { kind: 'handle', handle: hd };
    }
    const [lx, ly] = toLocal(t, p);
    const hw = Math.abs(w * t.sx) / 2, hh = Math.abs(h * t.sy) / 2;
    if (Math.abs(lx) <= hw && Math.abs(ly) <= hh) return { kind: 'move' };
    // מבחוץ, ליד פינה — סיבוב (כמו Photoshop)
    if (Math.abs(lx) <= hw + tol * 3 && Math.abs(ly) <= hh + tol * 3 &&
            (Math.abs(lx) > hw - tol * 3 && Math.abs(ly) > hh - tol * 3)) return { kind: 'rotate' };
    return null;
}

/**
 * גרירת ידית: t0 — המצב בתחילת הגרירה, p — הנקודה עכשיו (במסמך). הצד שמול הידית נשאר במקום (או המרכז — fromCenter).
 * opts: { free, fromCenter, minSize } — free: פינה בלי יחס (Shift). true / false במקום אובייקט — כמו free (התאמה לרסטר).
 * ציר באורך אפס (קו אופקי / אנכי) — לא משנים אותו, והפינה חופשית בציר השני (אין יחס עם אפס).
 */
export function dragHandle(t0, w, h, [hx, hy], p, opts = {}) {
    const { free = false, fromCenter = false, minSize = 1 } = typeof opts === 'object' && opts ? opts : { free: !!opts };
    const flatX = !(Math.abs(w * t0.sx) > 1e-9), flatY = !(Math.abs(h * t0.sy) > 1e-9);
    if (flatX) hx = 0;
    if (flatY) hy = 0;
    if (!hx && !hy) return { ...t0 };
    const hw = w * t0.sx / 2, hh = h * t0.sy / 2;
    const anchor = fromCenter ? [0, 0] : [-hx * hw, -hy * hh];   // הנקודה שנשארת (מקומי)
    const span = fromCenter ? 2 : 1;
    const q = toLocal(t0, p);
    let sx = t0.sx, sy = t0.sy;
    if (hx && hy && !free) {
        // יחס נשמר: ההטלה על האלכסון מהעוגן לפינה
        const dx = hx * hw - anchor[0], dy = hy * hh - anchor[1];
        const k = ((q[0] - anchor[0]) * dx + (q[1] - anchor[1]) * dy) / (dx * dx + dy * dy);
        const kk = Math.max(k, minSize / Math.max(w * Math.abs(t0.sx), h * Math.abs(t0.sy)));   // לא פחות מ-minSize
        sx = t0.sx * kk; sy = t0.sy * kk;
    } else {
        if (hx) sx = Math.max(minSize / w, (q[0] - anchor[0]) * hx * span / w) * Math.sign(t0.sx || 1);
        if (hy) sy = Math.max(minSize / h, (q[1] - anchor[1]) * hy * span / h) * Math.sign(t0.sy || 1);
    }
    if (fromCenter) return { ...t0, sx, sy };
    // המרכז החדש: באמצע בין העוגן לפינה הנגררת
    const c = [anchor[0] + hx * w * sx / 2, anchor[1] + hy * h * sy / 2];
    const center = fromLocal(t0, c);
    return { ...t0, sx, sy, cx: center[0], cy: center[1] };
}

export const MAGNET = [0, 45, 90, 135, 180, 225, 270, 315];

/**
 * זווית (רדיאנים) → { angle, snapped }. step (מעלות, Shift: 15) — מדרגות; אחרת magnet — נצמד לזוויות ברשימה בטווח tol מעלות.
 */
export function snapAngle(angle, { step = 0, magnet = MAGNET, tol = 6 } = {}) {
    const deg = angle * 180 / Math.PI;
    if (step) { const s = Math.round(deg / step) * step; return { angle: s * Math.PI / 180, snapped: true }; }
    const norm = ((deg % 360) + 360) % 360;
    for (const m of magnet || []) {
        let d = norm - m;
        d = ((d + 540) % 360) - 180;
        if (Math.abs(d) <= tol) return { angle: (deg - d) * Math.PI / 180, snapped: true };
    }
    return { angle, snapped: false };
}

/** סיבוב: מהזווית של start סביב המרכז לזווית של p. opts: true (=Shift, מדרגות 15°) או { step, magnet, tol } — snapAngle. */
export function dragRotate(t0, start, p, opts = {}) {
    const a0 = Math.atan2(start[1] - t0.cy, start[0] - t0.cx);
    const a1 = Math.atan2(p[1] - t0.cy, p[0] - t0.cx);
    const o = opts === true ? { step: 15 } : opts === false ? { magnet: [] } : opts;
    return { ...t0, angle: snapAngle(t0.angle + a1 - a0, o).angle };
}
