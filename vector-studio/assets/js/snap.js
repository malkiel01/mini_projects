// הצמדה: קצוות ומרכזים של הלוחות ושל אובייקטים אחרים ("קווים חכמים"), ורשת. הכול במרחב העבודה (pt);
// הסף ניתן בפיקסלים של המסך ומחולק בזום, כך שההצמדה מרגישה אותו דבר בכל הגדלה.

import { objects, layout, worldBox } from './model.js';

export const GRID = 10; // pt

/** קווי היעד: { xs: [], ys: [] } — שלושה לכל לוח ולכל אובייקט גלוי שאינו בבחירה. */
export function targets(doc, exclude) {
  const xs = [], ys = [];
  const add = ([x, y, w, h]) => { xs.push(x, x + w / 2, x + w); ys.push(y, y + h / 2, y + h); };
  const lay = layout(doc);
  for (const s of doc.surfaces) { const [ox, oy] = lay.get(s.id); add([ox, oy, s.size[0], s.size[1]]); }
  for (const { path, layer, surface } of objects(doc)) {
    if (exclude.has(path.id) || layer.visible === false || path.hidden) continue;
    const b = worldBox(path, lay.get(surface.id));
    if (b) add(b);
  }
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

/** הצמדה לרשת: הערך הקרוב לכפולה של GRID (יחסית ללוח, שמתחיל בכפולה של עצמו). */
function gridNearest(values, origin, tol) {
  let best = null;
  for (const v of values) {
    const t = origin + Math.round((v - origin) / GRID) * GRID, d = t - v;
    if (Math.abs(d) <= tol && (!best || Math.abs(d) < Math.abs(best.d))) best = { d, at: t, grid: true };
  }
  return best;
}

/**
 * יוצר פונקציית הצמדה לגרירה אחת (היעדים מחושבים פעם אחת בתחילתה).
 * opts: { doc, exclude: Set, tol (pt), grid: bool, origin: [x, y] של הלוח }
 */
export function snapper({ doc, exclude, tol, grid, origin = [0, 0] }) {
  const t = targets(doc, exclude);
  const pick = (vals, lines, o) => {
    const a = nearest(vals, lines, tol), b = grid ? gridNearest(vals, o, tol) : null;
    if (a && b) return Math.abs(a.d) <= Math.abs(b.d) ? a : b;
    return a || b;
  };
  return {
    /** הזזת תיבה: מחזיר { dx, dy, guides } — התיקון והקווים לציור. */
    box([x, y, w, h]) {
      const sx = pick([x, x + w / 2, x + w], t.xs, origin[0]);
      const sy = pick([y, y + h / 2, y + h], t.ys, origin[1]);
      return { dx: sx?.d || 0, dy: sy?.d || 0, guides: guides(sx, sy) };
    },
    /** נקודה (קצה של גרירה, קצה בשינוי גודל). axes — אילו צירים מותר להצמיד. */
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
