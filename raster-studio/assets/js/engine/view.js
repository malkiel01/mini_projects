// מצב התצוגה — זום והזזה. חשבון טהור, בלי DOM, כדי שאפשר לבדוק אותו ב-node.
// view = { zoom, x, y }: zoom = פיקסלי מסמך לפיקסל CSS; (x, y) = מיקום פינת המסמך על המסך, ב-CSS px.

export const ZOOM_MIN = 0.01;
export const ZOOM_MAX = 64;
// מדרגות הזום של הכפתורים − / + (כמו Photoshop)
export const STEPS = [0.01, 0.02, 0.03, 0.05, 0.08, 0.12, 0.17, 0.25, 0.33, 0.5, 0.67, 1, 1.5, 2, 3, 4, 6, 8, 12, 16, 24, 32, 48, 64];

export const clampZoom = z => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, z));

/**
 * המסמך כולו בתוך המסך, באמצע, לא יותר מ-100%.
 * inset — מה שמכסה את הבמה מכל צד (פס הכלים, לוח האפשרויות): המסמך נכנס לשטח הפנוי, לא מתחתם.
 */
export function fit(docW, docH, vw, vh, pad = 16, inset = {}) {
  const { l = 0, r = 0, t = 0, b = 0 } = inset;
  const fw = Math.max(1, vw - l - r), fh = Math.max(1, vh - t - b);
  const aw = Math.max(1, fw - pad * 2), ah = Math.max(1, fh - pad * 2);
  const zoom = clampZoom(Math.min(1, aw / docW, ah / docH));
  return { zoom, x: l + (fw - docW * zoom) / 2, y: t + (fh - docH * zoom) / 2 };
}

/** זום לרמה חדשה, כשהנקודה (sx, sy) על המסך נשארת מעל אותה נקודה במסמך. */
export function zoomTo(view, zoom, sx, sy) {
  const z = clampZoom(zoom);
  const k = z / view.zoom;
  return { zoom: z, x: sx - (sx - view.x) * k, y: sy - (sy - view.y) * k };
}

export const zoomBy = (view, factor, sx, sy) => zoomTo(view, view.zoom * factor, sx, sy);

/** המדרגה הבאה למעלה (dir = 1) או למטה (dir = -1) מהזום הנוכחי. */
export function nextStep(zoom, dir) {
  const eps = 1e-6;
  if (dir > 0) return STEPS.find(s => s > zoom + eps) ?? ZOOM_MAX;
  for (let i = STEPS.length - 1; i >= 0; i--) if (STEPS[i] < zoom - eps) return STEPS[i];
  return ZOOM_MIN;
}

export const screenToDoc = (view, sx, sy) => [(sx - view.x) / view.zoom, (sy - view.y) / view.zoom];
export const docToScreen = (view, dx, dy) => [view.x + dx * view.zoom, view.y + dy * view.zoom];

/** צביטה: שתי נקודות התחלה ושתי נקודות עכשיו → זום והזזה, כשהאמצע "נצמד" לאצבעות. */
export function pinch(start, a0, b0, a1, b1) {
  const d0 = Math.hypot(b0[0] - a0[0], b0[1] - a0[1]) || 1;
  const d1 = Math.hypot(b1[0] - a1[0], b1[1] - a1[1]) || 1;
  const m0 = [(a0[0] + b0[0]) / 2, (a0[1] + b0[1]) / 2];
  const m1 = [(a1[0] + b1[0]) / 2, (a1[1] + b1[1]) / 2];
  const z = zoomTo(start, start.zoom * d1 / d0, m0[0], m0[1]);
  return { zoom: z.zoom, x: z.x + m1[0] - m0[0], y: z.y + m1[1] - m0[1] };
}

/** "37%" / "1,200%" / "1.5%" — לתווית הזום. */
export function zoomLabel(zoom) {
  const p = zoom * 100;
  const r = p < 10 ? Math.round(p * 10) / 10 : Math.round(p);
  return r.toLocaleString('en-US') + '%';
}
