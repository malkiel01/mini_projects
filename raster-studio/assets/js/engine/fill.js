// מילוי (דלי) — אזור רציף שצבעו קרוב לצבע בנקודת ההתחלה. חשבון טהור על מערך פיקסלים, נבדק ב-node.

/**
 * @param {Uint8ClampedArray|Uint8Array} px  RGBA, w×h, שורה 0 למעלה
 * @param {number} sx  נקודת ההתחלה
 * @param {number} tolerance  0–255: ההפרש המרבי בכל ערוץ (כולל אלפא) מצבע ההתחלה
 * @returns {{ mask: Uint8Array, count: number, box: [number, number, number, number] | null }}
 *   mask — 255 בתוך האזור; box — x0, y0, x1, y1 (כולל) של האזור
 */
export function floodFill(px, w, h, sx, sy, tolerance = 32) {
  const mask = new Uint8Array(w * h);
  if (sx < 0 || sy < 0 || sx >= w || sy >= h) return { mask, count: 0, box: null };
  const i0 = (sy * w + sx) * 4;
  const r0 = px[i0], g0 = px[i0 + 1], b0 = px[i0 + 2], a0 = px[i0 + 3];
  // פיקסל שקוף לגמרי — הצבע שלו לא משנה (אחרת "שקוף שחור" ו"שקוף לבן" נחשבים שונים)
  const like = i => {
    const a = px[i + 3];
    if (a0 === 0 && a === 0) return true;
    return Math.abs(px[i] - r0) <= tolerance && Math.abs(px[i + 1] - g0) <= tolerance &&
      Math.abs(px[i + 2] - b0) <= tolerance && Math.abs(a - a0) <= tolerance;
  };
  let count = 0, x0 = sx, y0 = sy, x1 = sx, y1 = sy;
  // סריקת שורות: ממלאים רצף בשורה, ומוסיפים למחסנית את השורות שמעל ומתחת
  const stack = [sx, sy];
  while (stack.length) {
    const y = stack.pop(), x = stack.pop();
    if (mask[y * w + x] || !like((y * w + x) * 4)) continue;
    let l = x;
    while (l > 0 && !mask[y * w + l - 1] && like(((y * w) + l - 1) * 4)) l--;
    let r = x;
    while (r < w - 1 && !mask[y * w + r + 1] && like(((y * w) + r + 1) * 4)) r++;
    for (let i = l; i <= r; i++) mask[y * w + i] = 255;
    count += r - l + 1;
    if (l < x0) x0 = l; if (r > x1) x1 = r; if (y < y0) y0 = y; if (y > y1) y1 = y;
    for (const ny of [y - 1, y + 1]) {
      if (ny < 0 || ny >= h) continue;
      let inRun = false;
      for (let i = l; i <= r; i++) {
        const ok = !mask[ny * w + i] && like((ny * w + i) * 4);
        if (ok && !inRun) { stack.push(i, ny); inRun = true; } else if (!ok) inRun = false;
      }
    }
  }
  return { mask, count, box: count ? [x0, y0, x1, y1] : null };
}

/** "#rrggbb" ↔ [r, g, b] */
export const hexToRgb = hex => [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16));
export const rgbToHex = ([r, g, b]) => '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');

/**
 * נקודות של משיכה, במרחקים קבועים, בין שתי נקודות קלט — כדי שקו מהיר לא יהפוך לנקודות.
 * a, b — { x, y, p } (p = לחץ 0–1); spacing — מרחק בפיקסלים; carry — כמה נשאר מהקטע הקודם.
 * מחזיר { dabs: [{x, y, p}], carry }.
 */
export function dabsAlong(a, b, spacing, carry = 0) {
  const dx = b.x - a.x, dy = b.y - a.y;
  const len = Math.hypot(dx, dy);
  const dabs = [];
  let t = spacing - carry;
  if (len === 0) return { dabs, carry };
  for (; t <= len; t += spacing) {
    const k = t / len;
    dabs.push({ x: a.x + dx * k, y: a.y + dy * k, p: a.p + (b.p - a.p) * k });
  }
  return { dabs, carry: len - (t - spacing) };
}
