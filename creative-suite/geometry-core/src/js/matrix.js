// מטריצות אפיניות בצורה של SVG: [a b c d e f] — x' = a·x + c·y + e,  y' = b·x + d·y + f.

export const IDENTITY = Object.freeze([1, 0, 0, 1, 0, 0]);

/** m1 אחרי m2: קודם m2, אחר כך m1. */
export function multiply(m1, m2) {
  const [a1, b1, c1, d1, e1, f1] = m1;
  const [a2, b2, c2, d2, e2, f2] = m2;
  return [
    a1 * a2 + c1 * b2, b1 * a2 + d1 * b2,
    a1 * c2 + c1 * d2, b1 * c2 + d1 * d2,
    a1 * e2 + c1 * f2 + e1, b1 * e2 + d1 * f2 + f1,
  ];
}

export function apply(m, x, y) {
  return [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];
}

export function invert(m) {
  const [a, b, c, d, e, f] = m;
  const det = a * d - b * c;
  if (!det) throw new Error('מטריצה לא הפיכה');
  return [d / det, -b / det, -c / det, a / det, (c * f - d * e) / det, (b * e - a * f) / det];
}

export const translate = (tx, ty) => [1, 0, 0, 1, tx, ty];

/** קנה מידה סביב נקודת עוגן. */
export function scaleAt(sx, sy, ox = 0, oy = 0) {
  return [sx, 0, 0, sy, ox - sx * ox, oy - sy * oy];
}

/** סיבוב ברדיאנים סביב נקודה (בכיוון השעון במסך — ציר y למטה). */
export function rotateAt(rad, cx = 0, cy = 0) {
  const c = Math.cos(rad), s = Math.sin(rad);
  return [c, s, -s, c, cx - c * cx + s * cy, cy - s * cx - c * cy];
}

export const hasRotation = (m) => Math.abs(m[1]) > 1e-9 || Math.abs(m[2]) > 1e-9;

export function isIdentity(m) {
  return !m || (m[0] === 1 && m[1] === 0 && m[2] === 0 && m[3] === 1 && m[4] === 0 && m[5] === 0);
}
