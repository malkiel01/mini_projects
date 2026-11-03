// הרכבת עץ שכבות במעבד, בלי כרטיס גרפי. החלופה כשאין WebGL2 / WebGPU, ומה שמשמש תצוגה ממוזערת או בודק,
// בכל מקום שצריך בו "איך זה נראה". חשבון טהור על מערכים, ועובד גם ב-node.
// אותה נוסחה כמו במנועי הרסטר (blend.js): מיזוג בלינארי ומוכפל באלפא, וקבוצות מבודדות.
//
// שכבה:  { rgba, w, h, x = 0, y = 0, opacity = 1, visible = true, blend = 'normal', mask? }
//        rgba — Uint8Array / Uint8ClampedArray של w×h×4, sRGB עם אלפא ישר.
//        mask — { data, w, h, x = 0, y = 0, outside = 0 }: גווני אפור של 8 ביט (w×h) במערכת של המסמך.
//               255 = גלוי, 0 = מוסתר, 128 ≈ חצי, כמו בפוטושופ. מחוץ למסכה: outside.
// קבוצה: { type: 'group', children, opacity, visible, blend, mask? } — מורכבת לבד ואז נממזגת (מבודדת).
// העץ — מלמטה למעלה, כמו במסמך.

import { SRGB_TO_LINEAR, linearToSrgb } from './color.js';
import { blendIndex, blendChannel } from './blend.js';

const clamp01 = v => (v < 0 ? 0 : v > 1 ? 1 : v);

function maskAt(mask, X, Y) {
  const x = X - (mask.x ?? 0), y = Y - (mask.y ?? 0);
  if (x < 0 || y < 0 || x >= mask.w || y >= mask.h) return (mask.outside ?? 0) / 255;
  return mask.data[y * mask.w + x] / 255;
}

/**
 * מיזוג צומת (שכבה או קבוצה שכבר הורכבה) לתוך dst — Float32Array לינארי מוכפל בגודל המסמך, בתוך box בלבד.
 * sample(s, X, Y) — כותבת ל-s את הפיקסל הלינארי המוכפל של הצומת, או מחזירה false אם הוא שקוף.
 */
function blendInto(dst, w, h, box, node, sample) {
  const mode = blendIndex(node.blend);
  const op = node.opacity ?? 1;
  const mask = node.mask && node.mask.enabled !== false ? node.mask : null;
  const s = [0, 0, 0, 0];
  const [x0, y0, x1, y1] = box;
  for (let Y = Math.max(0, y0); Y < Math.min(h, y1); Y++) {
    for (let X = Math.max(0, x0); X < Math.min(w, x1); X++) {
      if (!sample(s, X, Y)) continue;
      const k = op * (mask ? maskAt(mask, X, Y) : 1);
      if (k <= 0) continue;
      const as = s[3] * k;
      if (as <= 0) continue;
      const i = (Y * w + X) * 4;
      const ab = dst[i + 3];
      for (let c = 0; c < 3; c++) {
        const sc = s[c] * k;
        const Cb = ab > 0 ? clamp01(dst[i + c] / ab) : 0, Cs = clamp01(sc / as);
        dst[i + c] = sc * (1 - ab) + dst[i + c] * (1 - as) + as * ab * blendChannel(mode, Cb, Cs);
      }
      dst[i + 3] = as + ab * (1 - as);
    }
  }
}

function compositeList(list, w, h) {
  const dst = new Float32Array(w * h * 4);
  for (const node of list ?? []) {
    if (!node || node.visible === false || !((node.opacity ?? 1) > 0)) continue;
    if (node.type === 'group') {
      if (!node.children?.length) continue;
      const g = compositeList(node.children, w, h);
      blendInto(dst, w, h, [0, 0, w, h], node, (s, X, Y) => {
        const i = (Y * w + X) * 4;
        if (!(g[i + 3] > 0)) return false;
        s[0] = g[i]; s[1] = g[i + 1]; s[2] = g[i + 2]; s[3] = g[i + 3];
        return true;
      });
      continue;
    }
    if (!node.rgba || !(node.w > 0) || !(node.h > 0)) continue;
    const lx = node.x ?? 0, ly = node.y ?? 0, lw = node.w, px = node.rgba;
    blendInto(dst, w, h, [lx, ly, lx + node.w, ly + node.h], node, (s, X, Y) => {
      const j = ((Y - ly) * lw + (X - lx)) * 4;
      const a = px[j + 3] / 255;
      if (!(a > 0)) return false;
      s[0] = SRGB_TO_LINEAR[px[j]] * a; s[1] = SRGB_TO_LINEAR[px[j + 1]] * a; s[2] = SRGB_TO_LINEAR[px[j + 2]] * a; s[3] = a;
      return true;
    });
  }
  return dst;
}

/**
 * מרכיב את העץ לתמונה אחת: { width, height, layers } → Uint8ClampedArray של width×height×4, sRGB עם אלפא ישר
 * (מוכן ל-ImageData, ל-encodePNG של image-io, או להשוואה).
 * linear: true — מחזיר במקום זה את Float32Array הלינארי המוכפל (להמשך חישוב).
 */
export function composite({ width, height, layers }, { linear = false } = {}) {
  const w = width | 0, h = height | 0;
  if (!(w > 0) || !(h > 0)) throw Object.assign(new Error('render-core: גודל לא תקין'), { code: 'bad-size' });
  const lin = compositeList(layers, w, h);
  if (linear) return lin;
  const out = new Uint8ClampedArray(w * h * 4);
  for (let i = 0; i < lin.length; i += 4) {
    const a = lin[i + 3];
    if (!(a > 0)) continue;
    out[i] = linearToSrgb(lin[i] / a);
    out[i + 1] = linearToSrgb(lin[i + 1] / a);
    out[i + 2] = linearToSrgb(lin[i + 2] / a);
    out[i + 3] = Math.round(clamp01(a) * 255);
  }
  return out;
}
