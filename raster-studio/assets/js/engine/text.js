// טקסט → תמונה: פריסת שורות בקנבס דו-ממדי (הדפדפן כבר יודע עברית, bidi וניקוד), בגודל מלא.
// בגרסה 1 טקסט נשמר כשכבת רסטר (README, "לא בגרסה 1").

import { ensureFont } from '../ui-core/fonts.js';

// הקטלוג — אחד לכל החבילה (ui-core/fonts.js, שלב 8): עשרה גופנים עבריים חופשיים, הקבצים ב-assets/fonts/ui-core
export { FONTS } from '../ui-core/fonts.js';

export const LINE_HEIGHT = 1.25;

/** כיוון לפי התו החזק הראשון (כמו dir="auto") */
export function textDirection(text) {
  const m = /[֐-ࣿיִ-﷿ﹰ-﻿]|[A-Za-zÀ-ɏ]/.exec(text);
  return m && /[A-Za-zÀ-ɏ]/.test(m[0]) ? 'ltr' : 'rtl';
}

export const cssFont = ({ family, size, bold }) => `${bold ? 700 : 400} ${size}px "${family}"`;

/**
 * הטקסט כתמונה. anchor — הנקודה שהמשתמש לחץ עליה (במסמך): בראש השורה הראשונה, בצד ההתחלה
 * (ימין בעברית), באמצע או בצד שמאל — לפי align ('start' | 'center' | 'end').
 * מחזיר { bitmap, x, y } — המיקום של הפינה השמאלית-עליונה של התמונה במסמך; או null לטקסט ריק.
 */
export async function renderText(text, { family, size, bold, color, align = 'start' }, [ax, ay]) {
  const lines = text.replace(/\s+$/, '').split('\n');
  if (!lines.join('').trim()) return null;
  const font = cssFont({ family, size, bold });
  await ensureFont(family, bold ? 700 : 400);   // אחרת הקנבס מצייר בגופן חלופי
  await document.fonts.load(font, text);
  const dir = textDirection(text);
  const probe = new OffscreenCanvas(1, 1).getContext('2d');
  probe.font = font;
  probe.direction = dir;
  const widths = lines.map(l => probe.measureText(l).width);
  const pad = Math.ceil(size * 0.35);   // ניקוד, זנבות ונטייה — לא נחתכים
  const lh = size * LINE_HEIGHT;
  const w = Math.ceil(Math.max(1, ...widths)) + pad * 2;
  const h = Math.ceil(lh * lines.length) + pad * 2;
  const cv = new OffscreenCanvas(w, h);
  const ctx = cv.getContext('2d');
  ctx.font = font;
  ctx.direction = dir;
  ctx.fillStyle = color;
  ctx.textBaseline = 'middle';
  // align יחסי לכיוון: start בעברית = ימין
  const side = align === 'center' ? 'center' : (align === 'start') === (dir === 'rtl') ? 'right' : 'left';
  ctx.textAlign = side;
  const x = side === 'right' ? w - pad : side === 'left' ? pad : w / 2;
  lines.forEach((l, i) => ctx.fillText(l, x, pad + lh * (i + 0.5)));
  const bitmap = await createImageBitmap(cv, { premultiplyAlpha: 'none' });
  const left = side === 'right' ? ax - (w - pad) : side === 'left' ? ax - pad : ax - w / 2;
  return { bitmap, x: Math.round(left), y: Math.round(ay - pad) };
}
