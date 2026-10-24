// מעבר צבע ליניארי: fill.gradient = { type: "linear", from: [x, y], to: [x, y], stops: [{ at, color, opacity? }] },
// בקואורדינטות של הנתיב (זזות איתו — model.applyMatrix). בלי DOM.

/** נקודות ההתחלה והסוף לפי זווית (מעלות, 0 = משמאל לימין, 90 = מלמעלה למטה), כך שהמעבר מכסה את כל התיבה. */
export function endpoints([x, y, w, h], angle) {
  const a = angle * Math.PI / 180, dx = Math.cos(a), dy = Math.sin(a);
  const half = (Math.abs(w * dx) + Math.abs(h * dy)) / 2;
  const cx = x + w / 2, cy = y + h / 2;
  const r = (v) => Math.round(v * 1000) / 1000 || 0; // בלי -0
  return { from: [r(cx - dx * half), r(cy - dy * half)], to: [r(cx + dx * half), r(cy + dy * half)] };
}

export function angleOf(g) {
  const a = Math.atan2(g.to[1] - g.from[1], g.to[0] - g.from[0]) * 180 / Math.PI;
  return Math.round((a + 360) % 360);
}

export function makeGradient(box, angle, c1, c2) {
  return { type: 'linear', ...endpoints(box, angle), stops: [{ at: 0, color: c1 }, { at: 1, color: c2 }] };
}

/** צבע/מעבר לקנבס — ctx בטרנספורמציה של הנתיב. */
export function canvasPaint(ctx, paint) {
  const g = paint?.gradient;
  if (!g) return paint?.color || null;
  const lg = ctx.createLinearGradient(g.from[0], g.from[1], g.to[0], g.to[1]);
  for (const s of g.stops || []) lg.addColorStop(Math.max(0, Math.min(1, s.at)), withAlpha(s.color, s.opacity ?? 1));
  return lg;
}

function withAlpha(hex, a) {
  if (a >= 1) return hex;
  const n = parseInt(String(hex).slice(1), 16) || 0;
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
}

export const hasPaint = (p) => !!(p && (p.color || p.gradient));
