// מצבי המיזוג — W3C Compositing and Blending Level 1: מיזוג נפרד לכל ערוץ, ואחריו source-over.
// הכול בלינארי ומוכפל באלפא (premultiplied). אותה נוסחה בשלוש שפות — JS (המרכיב במעבד), GLSL ו-WGSL — כדי
// שהמסך, הייצוא והחלופה בלי כרטיס גרפי ייראו אותו דבר.

/** הסדר קבוע: האינדקס הוא מה שהשיידר מקבל (u_mode / m.x). מצב לא מוכר → 0 (רגיל). */
export const BLEND_MODES = ['normal', 'multiply', 'screen', 'overlay'];
export const blendIndex = name => Math.max(0, BLEND_MODES.indexOf(name));

const screenB = (b, s) => b + s - b * s;
// s ≤ 0.5 → multiply(b, 2s); אחרת screen(b, 2s − 1). ב-0.5 שני הענפים שווים.
const hardLight = (b, s) => (s <= 0.5 ? b * 2 * s : screenB(b, 2 * s - 1));
const clamp01 = v => (v < 0 ? 0 : v > 1 ? 1 : v);

/** B(Cb, Cs) לערוץ אחד: Cb — הרקע, Cs — השכבה (לא מוכפלים, 0–1) */
export function blendChannel(mode, Cb, Cs) {
  if (mode === 1) return Cb * Cs;
  if (mode === 2) return screenB(Cb, Cs);
  if (mode === 3) return hardLight(Cs, Cb);   // overlay = hard-light עם הסדר ההפוך
  return Cs;
}

/**
 * פיקסל אחד: b, s — [r, g, b, a] לינאריים מוכפלים; s כבר כולל אטימות ומסכה. כותב את התוצאה ל-out (גם b עצמו).
 */
export function blendOver(out, b, s, mode) {
  const ab = b[3], as = s[3];
  for (let c = 0; c < 3; c++) {
    const Cb = ab > 0 ? clamp01(b[c] / ab) : 0, Cs = as > 0 ? clamp01(s[c] / as) : 0;
    out[c] = s[c] * (1 - ab) + b[c] * (1 - as) + as * ab * blendChannel(mode, Cb, Cs);
  }
  out[3] = as + ab * (1 - as);
  return out;
}

/** GLSL: blendOver(b, s, mode) → vec4 — b, s לינאריים מוכפלים */
export const GLSL_BLEND = `
vec3 screenB(vec3 b, vec3 s) { return b + s - b * s; }
// s ≤ 0.5 → multiply(b, 2s); אחרת screen(b, 2s − 1). ב-0.5 שני הענפים שווים.
vec3 hardLight(vec3 b, vec3 s) {
  return mix(b * 2.0 * s, screenB(b, 2.0 * s - 1.0), step(0.5, s));
}
vec4 blendOver(vec4 b, vec4 s, int mode) {
  float ab = b.a, as_ = s.a;
  vec3 Cb = ab > 0.0 ? clamp(b.rgb / ab, 0.0, 1.0) : vec3(0.0);
  vec3 Cs = as_ > 0.0 ? clamp(s.rgb / as_, 0.0, 1.0) : vec3(0.0);
  vec3 B = Cs;
  if (mode == 1) B = Cb * Cs;
  else if (mode == 2) B = screenB(Cb, Cs);
  else if (mode == 3) B = hardLight(Cs, Cb);   // overlay = hard-light עם הסדר ההפוך
  return vec4(s.rgb * (1.0 - ab) + b.rgb * (1.0 - as_) + as_ * ab * B, as_ + ab * (1.0 - as_));
}`;

/** WGSL: blendOver(b, s, mode) → vec4f — אותו דבר */
export const WGSL_BLEND = `
fn screenB(b: vec3f, s: vec3f) -> vec3f { return b + s - b * s; }
// s ≤ 0.5 → multiply(b, 2s); אחרת screen(b, 2s − 1)
fn hardLight(b: vec3f, s: vec3f) -> vec3f { return mix(b * 2.0 * s, screenB(b, 2.0 * s - 1.0), step(vec3f(0.5), s)); }
fn blendOver(b: vec4f, s: vec4f, mode: i32) -> vec4f {
  let ab = b.a;
  let sa = s.a;
  var Cb = vec3f(0.0);
  if (ab > 0.0) { Cb = clamp(b.rgb / ab, vec3f(0.0), vec3f(1.0)); }
  var Cs = vec3f(0.0);
  if (sa > 0.0) { Cs = clamp(s.rgb / sa, vec3f(0.0), vec3f(1.0)); }
  var B = Cs;
  if (mode == 1) { B = Cb * Cs; }
  else if (mode == 2) { B = screenB(Cb, Cs); }
  else if (mode == 3) { B = hardLight(Cs, Cb); }   // overlay = hard-light עם הסדר ההפוך
  return vec4f(s.rgb * (1.0 - ab) + b.rgb * (1.0 - sa) + sa * ab * B, sa + ab * (1.0 - sa));
}`;
