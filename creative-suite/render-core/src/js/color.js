// sRGB ↔ לינארי — אותה עקומה ב-JS, ב-GLSL (WebGL2) וב-WGSL (WebGPU). מיזוג השכבות נעשה בלינארי, כמו ברסטר.

/** sRGB של 8 ביט (0–255) → לינארי (0–1) */
export const srgbToLinear = v => {
  const c = v / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};

/** לינארי (0–1) → sRGB של 8 ביט (0–255, מעוגל) */
export const linearToSrgb = l => {
  const c = l <= 0 ? 0 : l >= 1 ? 1 : l;
  return Math.round(255 * (c <= 0.0031308 ? c * 12.92 : 1.055 * c ** (1 / 2.4) - 0.055));
};

// טבלה ל-8 ביט — ההמרה הנפוצה, בלי pow לכל פיקסל
export const SRGB_TO_LINEAR = Float32Array.from({ length: 256 }, (_, i) => srgbToLinear(i));

/** GLSL: dec (sRGB → לינארי) על vec3 בטווח 0–1 */
export const GLSL_DECODE = `
vec3 dec(vec3 s) {
  s = clamp(s, 0.0, 1.0);
  return mix(s / 12.92, pow((s + 0.055) / 1.055, vec3(2.4)), step(0.04045, s));
}`;

/** GLSL: enc (לינארי → sRGB) על vec3 בטווח 0–1 */
export const GLSL_ENCODE = `
vec3 enc(vec3 l) {
  l = clamp(l, 0.0, 1.0);
  return mix(l * 12.92, 1.055 * pow(l, vec3(1.0 / 2.4)) - 0.055, step(0.0031308, l));
}`;

export const GLSL_COLOR = GLSL_DECODE + GLSL_ENCODE;

/** WGSL: enc ו-dec — אותו דבר */
export const WGSL_COLOR = `
fn enc(l0: vec3f) -> vec3f {
  let l = clamp(l0, vec3f(0.0), vec3f(1.0));
  return select(l * 12.92, 1.055 * pow(max(l, vec3f(1e-9)), vec3f(1.0 / 2.4)) - 0.055, l > vec3f(0.0031308));
}
fn dec(s0: vec3f) -> vec3f {
  let s = clamp(s0, vec3f(0.0), vec3f(1.0));
  return select(s / 12.92, pow((s + 0.055) / 1.055, vec3f(2.4)), s > vec3f(0.04045));
}`;
