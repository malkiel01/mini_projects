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

/**
 * טווח צבעים: כל פיקסל בתמונה (לא רק הרציפים) שצבעו קרוב לצבע ב-(sx, sy) — אותו כלל כמו floodFill.
 * מחזיר את אותו מבנה.
 */
export function colorRange(px, w, h, sx, sy, tolerance = 32) {
  const mask = new Uint8Array(w * h);
  if (sx < 0 || sy < 0 || sx >= w || sy >= h) return { mask, count: 0, box: null };
  const i0 = (sy * w + sx) * 4;
  const r0 = px[i0], g0 = px[i0 + 1], b0 = px[i0 + 2], a0 = px[i0 + 3];
  let count = 0, x0 = w, y0 = h, x1 = -1, y1 = -1;
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const i = (y * w + x) * 4, a = px[i + 3];
      const ok = (a0 === 0 && a === 0) || (Math.abs(px[i] - r0) <= tolerance && Math.abs(px[i + 1] - g0) <= tolerance &&
        Math.abs(px[i + 2] - b0) <= tolerance && Math.abs(a - a0) <= tolerance);
      if (!ok) continue;
      mask[y * w + x] = 255;
      count++;
      if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
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

/**
 * בחירה מהירה — מצב של משיכה אחת: מסכה בגודל התמונה שמצטברת מכל נקודה במשיכה (quickGrow).
 * seen — חותמת "ביקרתי" לכל קריאה (בלי להקצות מערך חדש בכל תנועה).
 */
export function quickState(px, w, h) {
  return { px, w, h, mask: new Uint8Array(w * h), seen: new Uint32Array(w * h), gen: 0, box: null };
}

/**
 * צומחת מהעיגול של המכחול (מרכז cx, cy, רדיוס r): הפיקסלים בעיגול שקרובים לצבע הממוצע שבו הם הזרעים, ומהם —
 * הפיקסלים הרציפים שצבעם קרוב לממוצע (עד tolerance בכל ערוץ, כמו במטה הקסם), עד reach מהמרכז — כך הבחירה נעצרת
 * בקצוות ולא "נשפכת" לכל התמונה. מוסיף ל-st.mask ומעדכן את st.box. מחזיר כמה פיקסלים נוספו.
 */
export function quickGrow(st, cx, cy, r, tolerance = 32, reach = Math.max(8, r * 4)) {
  const { px, w, h, mask, seen } = st;
  const gen = ++st.gen;
  const R = Math.max(0.5, r), x0 = Math.max(0, Math.floor(cx - R)), x1 = Math.min(w - 1, Math.ceil(cx + R));
  const y0 = Math.max(0, Math.floor(cy - R)), y1 = Math.min(h - 1, Math.ceil(cy + R));
  // הצבע הממוצע בעיגול
  let n = 0, sr = 0, sg = 0, sb = 0, sa = 0;
  for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
    if ((x + 0.5 - cx) ** 2 + (y + 0.5 - cy) ** 2 > R * R) continue;
    const i = (y * w + x) * 4;
    sr += px[i]; sg += px[i + 1]; sb += px[i + 2]; sa += px[i + 3]; n++;
  }
  if (!n) return 0;
  const r0 = sr / n, g0 = sg / n, b0 = sb / n, a0 = sa / n;
  const like = i => (a0 < 1 && px[i + 3] === 0) || (Math.abs(px[i] - r0) <= tolerance && Math.abs(px[i + 1] - g0) <= tolerance
    && Math.abs(px[i + 2] - b0) <= tolerance && Math.abs(px[i + 3] - a0) <= tolerance);
  const reach2 = reach * reach;
  const queue = [];
  for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
    const p = y * w + x;
    if ((x + 0.5 - cx) ** 2 + (y + 0.5 - cy) ** 2 > R * R || !like(p * 4)) continue;
    seen[p] = gen; queue.push(p);
  }
  let added = 0, bx0 = w, by0 = h, bx1 = -1, by1 = -1;
  for (let q = 0; q < queue.length; q++) {
    const p = queue[q], x = p % w, y = (p - x) / w;
    if (!mask[p]) { mask[p] = 255; added++; }
    if (x < bx0) bx0 = x; if (x > bx1) bx1 = x; if (y < by0) by0 = y; if (y > by1) by1 = y;
    const nb = [x > 0 ? p - 1 : -1, x < w - 1 ? p + 1 : -1, y > 0 ? p - w : -1, y < h - 1 ? p + w : -1];
    for (const o of nb) {
      if (o < 0 || seen[o] === gen) continue;
      seen[o] = gen;
      const ox = o % w, oy = (o - ox) / w;
      if ((ox + 0.5 - cx) ** 2 + (oy + 0.5 - cy) ** 2 > reach2 || !like(o * 4)) continue;
      queue.push(o);
    }
  }
  if (bx1 >= 0) {
    const b = st.box;
    st.box = b ? [Math.min(b[0], bx0), Math.min(b[1], by0), Math.max(b[2], bx1), Math.max(b[3], by1)] : [bx0, by0, bx1, by1];
  }
  return added;
}
