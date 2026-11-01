// הרנדרר של WebGL2 — הגיבוי של WebGPU, והראשון שנבנה כי הוא נבדק בכל דפדפן (גם בבדיקות האוטומטיות).
//
// העקרונות (README, "מנוע"):
// - הפיקסלים באריחים של 256×256, RGBA ב-float16, צבע לינארי עם אלפא מוכפל (premultiplied).
// - קלט (PNG/JPG/WebP ב-sRGB של 8 ביט) עובר ללינארי בהעלאה — המרקם SRGB8_ALPHA8 מפענח בחומרה.
// - המיזוג בין שכבות — ב-shader, לפי נוסחאות W3C Compositing, בלינארי.
// - חזרה ל-sRGB של 8 ביט רק בתצוגה ובקריאה החוצה (readPixels → PNG בשלב השמירה).
// - העותק המורכב (composite) נבנה רק לאריחים שרואים, ורק כשהשתנו.
//
// מוסכמה: שורה 0 של כל אריח ושל כל FBO = הקצה העליון (y של המסמך גדל למטה). רק המסך עצמו הפוך.

import { TILE, BLEND_MODES, tilesInRect, newMask } from './doc.js';
import { adjustOf, fillRamp, fillGeometry, effectsOf, effectsReach } from './formats.js';
import { rasterTile, selectionBox } from './selection.js';
import { matrix as xfMatrix, bounds as xfBounds } from './transform.js';
import { meshBounds, meshCells } from './warp.js';
/** המלבן של התוכן הצף — לפי הרשת (עיוות) או לפי שינוי הצורה */
const flBounds = fl => (fl.mesh ? meshBounds(fl.mesh) : xfBounds(fl.t, fl.w, fl.h));
import { vmaskTile } from './vmask.js';

const VERT = `#version 300 es
in vec2 a;
uniform vec4 u_rect;    // x0 y0 x1 y1 — בפיקסלים של היעד, y למטה
uniform vec4 u_uv;      // u0 v0 u1 v1
uniform vec2 u_size;    // רוחב וגובה היעד
uniform float u_flip;   // 1 = מסך, -1 = FBO
out vec2 v_uv;
void main() {
  vec2 p = mix(u_rect.xy, u_rect.zw, a);
  v_uv = mix(u_uv.xy, u_uv.zw, a);
  gl_Position = vec4(p.x / u_size.x * 2.0 - 1.0, u_flip * (1.0 - p.y / u_size.y * 2.0), 0.0, 1.0);
}`;

// קודקודים דרך מטריצה אפינית — לתוכן צף (הזזה / קנה מידה / סיבוב). a ∈ [0,1]² → פיקסל במרקם הצף → מסמך → אריח
const VERT_M = `#version 300 es
in vec2 a;
uniform vec2 u_wh;      // גודל המרקם הצף
uniform vec4 u_m0;      // a b c d
uniform vec2 u_m1;      // e f
uniform vec2 u_org;     // פינת האריח במסמך
uniform vec2 u_size;    // גודל היעד
out vec2 v_uv;
void main() {
  vec2 p = a * u_wh;
  vec2 d = vec2(u_m0.x * p.x + u_m0.z * p.y + u_m1.x, u_m0.y * p.x + u_m0.w * p.y + u_m1.y) - u_org;
  v_uv = a;
  gl_Position = vec4(d.x / u_size.x * 2.0 - 1.0, d.y / u_size.y * 2.0 - 1.0, 0.0, 1.0);
}`;

// תא של רשת עיוות: הפינות במסמך (u_p01 = p00, p10; u_p23 = p01, p11), התא במרקם (u_uv), פינת האריח (u_org)
const VERT_Q = `#version 300 es
in vec2 a;
uniform vec4 u_p01, u_p23, u_uv;
uniform vec2 u_org, u_size;
out vec2 v_uv;
void main() {
  vec2 p = mix(mix(u_p01.xy, u_p01.zw, a.x), mix(u_p23.xy, u_p23.zw, a.x), a.y) - u_org;
  v_uv = mix(u_uv.xy, u_uv.zw, a);
  gl_Position = vec4(p.x / u_size.x * 2.0 - 1.0, p.y / u_size.y * 2.0 - 1.0, 0.0, 1.0);
}`;

const DECODE = `
vec3 dec(vec3 s) {
  s = clamp(s, 0.0, 1.0);
  return mix(s / 12.92, pow((s + 0.055) / 1.055, vec3(2.4)), step(0.04045, s));
}`;

const ENCODE = `
vec3 enc(vec3 l) {
  l = clamp(l, 0.0, 1.0);
  return mix(l * 12.92, 1.055 * pow(l, vec3(1.0 / 2.4)) - 0.055, step(0.0031308, l));
}`;

const FRAG = {
  // sRGB ישר (המרקם כבר מפענח ללינארי) → לינארי מוכפל
  upload: `#version 300 es
precision highp float;
uniform sampler2D u_src;
in vec2 v_uv; out vec4 o;
void main() { vec4 c = texture(u_src, v_uv); o = vec4(c.rgb * c.a, c.a); }`,

  fill: `#version 300 es
precision highp float;
uniform vec4 u_color;
out vec4 o;
void main() { o = u_color; }`,

  // W3C Compositing and Blending Level 1: מיזוג נפרד לכל ערוץ + source-over.
  // מסכת שכבה (u_useMask): הערוץ r של אריח המסכה, בגווני אפור של sRGB (50% אפור = חצי שקוף, כמו בפוטושופ)
  blend: `#version 300 es
precision highp float;
uniform sampler2D u_dst, u_src, u_msk, u_vm;
uniform float u_opacity;
uniform int u_mode, u_useMask, u_useVm;
in vec2 v_uv; out vec4 o;
${ENCODE}
vec3 screenB(vec3 b, vec3 s) { return b + s - b * s; }
// s ≤ 0.5 → multiply(b, 2s); אחרת screen(b, 2s − 1). ב-0.5 שני הענפים שווים.
vec3 hardLight(vec3 b, vec3 s) {
  return mix(b * 2.0 * s, screenB(b, 2.0 * s - 1.0), step(0.5, s));
}
void main() {
  vec4 b = texture(u_dst, v_uv);
  vec4 s = texture(u_src, v_uv) * u_opacity;
  if (u_useMask == 1) s *= enc(vec3(texture(u_msk, v_uv).r)).r;
  if (u_useVm == 1) s *= texture(u_vm, v_uv).r;   // מסכה וקטורית: כיסוי 0–1 (R8)
  float ab = b.a, as_ = s.a;
  vec3 Cb = ab > 0.0 ? clamp(b.rgb / ab, 0.0, 1.0) : vec3(0.0);
  vec3 Cs = as_ > 0.0 ? clamp(s.rgb / as_, 0.0, 1.0) : vec3(0.0);
  vec3 B = Cs;
  if (u_mode == 1) B = Cb * Cs;
  else if (u_mode == 2) B = screenB(Cb, Cs);
  else if (u_mode == 3) B = hardLight(Cs, Cb);   // overlay = hard-light עם הסדר ההפוך
  o = vec4(s.rgb * (1.0 - ab) + b.rgb * (1.0 - as_) + as_ * ab * B, as_ + ab * (1.0 - as_));
}`,

  // נקודת מכחול במסכת המשיכה: v_uv = המרחק מהמרכז בפיקסלים. רך → קשה לפי u_hard, ולפחות פיקסל של החלקה
  dab: `#version 300 es
precision highp float;
uniform float u_r, u_hard, u_alpha;
in vec2 v_uv; out vec4 o;
void main() {
  float d = length(v_uv);
  float inner = min(u_hard * u_r, u_r - 1.0);
  float a = clamp((u_r - d) / max(u_r - inner, 1.0), 0.0, 1.0);
  a = a * a * (3.0 - 2.0 * a);
  o = vec4(a * u_alpha);
}`,

  // מסכת משיכה (בערוץ r) על אריח של שכבה: צבע (מכחול, דלי), מחיקה (מחק), או המקור המוזז (חותמת השכפול)
  stroke: `#version 300 es
precision highp float;
uniform sampler2D u_layer, u_mask, u_sel, u_src;
uniform vec4 u_color;     // לינארי מוכפל, אלפא 1
uniform float u_opacity;
uniform int u_erase, u_useSel, u_clone;
in vec2 v_uv; out vec4 o;
void main() {
  vec4 l = texture(u_layer, v_uv);
  float m = clamp(texture(u_mask, v_uv).r, 0.0, 1.0) * u_opacity;
  if (u_useSel == 1) m *= texture(u_sel, v_uv).r;   // בחירה פעילה — רק בתוכה
  vec4 c = u_clone == 1 ? texture(u_src, v_uv) : u_color;
  o = u_erase == 1 ? l * (1.0 - m) : c * m + l * (1.0 - m);
}`,

  // תיקוני צבע — ב-sRGB (כמו שהעין רואה), וחזרה ללינארי. u_kind: 1 בהירות/ניגודיות, 2 גוון/רוויה/בהירות,
  // 3 רעש (u_p = עוצמה, פינת האריח במסמך) — לפי מיקום הפיקסל במסמך, כך שאין תפרים בין אריחים,
  // 4 רמות (שחור, לבן, גמא), 5 היפוך, 6 פוסטריזציה (מספר רמות), 7 סף (בהירות)
  adjust: `#version 300 es
precision highp float;
uniform sampler2D u_tex, u_sel;
uniform int u_kind, u_useSel;
uniform vec4 u_p;
in vec2 v_uv; out vec4 o;
float noise(vec2 p) {
  uvec2 q = uvec2(ivec2(floor(p)) + ivec2(1 << 20));
  uint h = q.x * 1973u + q.y * 9277u + 26699u;
  h = (h ^ (h >> 16u)) * 0x7feb352du; h ^= h >> 15u; h *= 0x846ca68bu; h ^= h >> 16u;
  return float(h) / 4294967295.0;
}
${ENCODE}
${DECODE}
vec3 rgb2hsl(vec3 c) {
  float mx = max(c.r, max(c.g, c.b)), mn = min(c.r, min(c.g, c.b)), l = (mx + mn) / 2.0, d = mx - mn;
  if (d < 1e-6) return vec3(0.0, 0.0, l);
  float s = l > 0.5 ? d / (2.0 - mx - mn) : d / (mx + mn);
  float h = mx == c.r ? (c.g - c.b) / d + (c.g < c.b ? 6.0 : 0.0) : mx == c.g ? (c.b - c.r) / d + 2.0 : (c.r - c.g) / d + 4.0;
  return vec3(h / 6.0, s, l);
}
float hue(float p, float q, float t) {
  t = fract(t);
  if (t < 1.0 / 6.0) return p + (q - p) * 6.0 * t;
  if (t < 0.5) return q;
  if (t < 2.0 / 3.0) return p + (q - p) * (2.0 / 3.0 - t) * 6.0;
  return p;
}
vec3 hsl2rgb(vec3 h) {
  if (h.y < 1e-6) return vec3(h.z);
  float q = h.z < 0.5 ? h.z * (1.0 + h.y) : h.z + h.y - h.z * h.y, p = 2.0 * h.z - q;
  return vec3(hue(p, q, h.x + 1.0 / 3.0), hue(p, q, h.x), hue(p, q, h.x - 1.0 / 3.0));
}
void main() {
  vec4 c = texture(u_tex, v_uv);
  vec4 r = c;
  if (c.a > 0.0) {
    vec3 s = enc(c.rgb / c.a);
    if (u_kind == 1) {
      // בהירות: עקומת גמא (שחור ולבן נשארים); ניגודיות: סביב האמצע
      s = pow(s, vec3(exp2(-u_p.x)));
      s = (s - 0.5) * u_p.y + 0.5;
    } else if (u_kind == 3) {
      s = clamp(s + (noise(u_p.yz + v_uv * 256.0) - 0.5) * u_p.x, 0.0, 1.0);
    } else if (u_kind == 4) {
      // רמות: שחור / לבן (0–1) וגמא
      s = pow(clamp((s - u_p.x) / max(u_p.y - u_p.x, 1e-4), 0.0, 1.0), vec3(1.0 / u_p.z));
    } else if (u_kind == 5) {
      s = 1.0 - clamp(s, 0.0, 1.0);
    } else if (u_kind == 6) {
      s = floor(clamp(s, 0.0, 1.0) * (u_p.x - 1.0) + 0.5) / (u_p.x - 1.0);
    } else if (u_kind == 7) {
      s = vec3(step(u_p.x, dot(clamp(s, 0.0, 1.0), vec3(0.299, 0.587, 0.114))));
    } else {
      vec3 h = rgb2hsl(clamp(s, 0.0, 1.0));
      h.x = fract(h.x + u_p.x);
      h.y = clamp(h.y * u_p.y, 0.0, 1.0);
      s = hsl2rgb(h);
      s = u_p.z >= 0.0 ? mix(s, vec3(1.0), u_p.z) : s * (1.0 + u_p.z);
    }
    r = vec4(dec(s) * c.a, c.a);
  }
  float m = u_useSel == 1 ? texture(u_sel, v_uv).r : 1.0;
  o = mix(c, r, m);
}`,

  // שכבת מילוי / מעבר צבעים: הצבע מ-u_ramp (256×1, sRGB ישר) לפי t — אחיד (0), לינארי (1, from→to) או רדיאלי
  // (2, מרכז ורדיוס). u_origin — פינת האריח במסמך; v_uv.y = 0 בראש האריח
  fillgen: `#version 300 es
precision highp float;
uniform sampler2D u_ramp;
uniform int u_mode;
uniform vec4 u_geo;
uniform vec2 u_origin;
in vec2 v_uv; out vec4 o;
${DECODE}
void main() {
  vec2 p = u_origin + v_uv * 256.0;
  float t = 0.0;
  if (u_mode == 1) { vec2 d = u_geo.zw - u_geo.xy; t = clamp(dot(p - u_geo.xy, d) / max(dot(d, d), 1e-6), 0.0, 1.0); }
  else if (u_mode == 2) { t = clamp(length(p - u_geo.xy) / max(u_geo.z, 1e-6), 0.0, 1.0); }
  vec4 c = texture(u_ramp, vec2(t * (255.0 / 256.0) + 0.5 / 256.0, 0.5));
  o = vec4(dec(c.rgb) * c.a, c.a);
}`,

  // מברשת ריפוי: המקור, ועוד (טשטוש היעד − טשטוש המקור) — בלינארי ומוכפל, בגבולות מוכפל-תקין
  heal: `#version 300 es
precision highp float;
uniform sampler2D u_src, u_bDst, u_bSrc;
in vec2 v_uv; out vec4 o;
void main() {
  vec4 r = texture(u_src, v_uv) + texture(u_bDst, v_uv) - texture(u_bSrc, v_uv);
  r.a = clamp(r.a, 0.0, 1.0);
  o = vec4(clamp(r.rgb, vec3(0.0), vec3(r.a)), r.a);
}`,

  // שכבת התאמה: מה שמתחת (u_a) ↔ אחרי ההתאמה (u_b), לפי האטימות כפול המסכה (אפור של sRGB)
  lerp: `#version 300 es
precision highp float;
uniform sampler2D u_a, u_b, u_msk, u_vm;
uniform float u_k;
uniform int u_useMask, u_useVm;
in vec2 v_uv; out vec4 o;
${ENCODE}
void main() {
  float k = u_k;
  if (u_useMask == 1) k *= enc(vec3(texture(u_msk, v_uv).r)).r;
  if (u_useVm == 1) k *= texture(u_vm, v_uv).r;
  o = mix(texture(u_a, v_uv), texture(u_b, v_uv), k);
}`,

  // טשטוש גאוסי בשני מעברים, על אריח מרופד בשכניו (u_k — חצי רוחב הגרעין, עד 64)
  blurH: `#version 300 es
precision highp float;
uniform sampler2D u_tex;
uniform int u_k;
uniform float u_sigma;
out vec4 o;
void main() {
  ivec2 p = ivec2(gl_FragCoord.xy);
  vec4 acc = vec4(0.0); float wsum = 0.0;
  for (int i = -64; i <= 64; i++) {
    if (i < -u_k || i > u_k) continue;
    float w = exp(-float(i * i) / (2.0 * u_sigma * u_sigma));
    acc += texelFetch(u_tex, ivec2(p.x + u_k + i, p.y), 0) * w; wsum += w;
  }
  o = acc / wsum;
}`,
  // u_amount > 0 — חידוד (unsharp mask): המקור ועוד ההפרש מהטשטוש, פי u_amount; בגבולות מוכפל-תקין
  blurV: `#version 300 es
precision highp float;
uniform sampler2D u_tex, u_pad, u_sel;
uniform int u_k, u_useSel;
uniform float u_sigma, u_amount;
out vec4 o;
void main() {
  ivec2 p = ivec2(gl_FragCoord.xy);
  vec4 acc = vec4(0.0); float wsum = 0.0;
  for (int i = -64; i <= 64; i++) {
    if (i < -u_k || i > u_k) continue;
    float w = exp(-float(i * i) / (2.0 * u_sigma * u_sigma));
    acc += texelFetch(u_tex, ivec2(p.x, p.y + u_k + i), 0) * w; wsum += w;
  }
  vec4 orig = texelFetch(u_pad, p + ivec2(u_k), 0);
  vec4 r = acc / wsum;
  if (u_amount > 0.0) {
    r = orig + (orig - r) * u_amount;
    r.a = clamp(r.a, 0.0, 1.0);
    r.rgb = clamp(r.rgb, vec3(0.0), vec3(r.a));
  }
  float m = u_useSel == 1 ? texelFetch(u_sel, p, 0).r : 1.0;
  o = mix(orig, r, m);
}`,

  // טשטוש תנועה: ממוצע לאורך קו בכיוון u_dir, על אריח מרופד (u_k — חצי האורך, עד 64)
  motion: `#version 300 es
precision highp float;
uniform sampler2D u_pad, u_sel;
uniform int u_k, u_useSel;
uniform vec2 u_dir;
out vec4 o;
void main() {
  ivec2 p = ivec2(gl_FragCoord.xy);
  vec4 acc = vec4(0.0); float n = 0.0;
  for (int i = -64; i <= 64; i++) {
    if (i < -u_k || i > u_k) continue;
    acc += texelFetch(u_pad, p + ivec2(u_k) + ivec2(round(u_dir * float(i))), 0); n += 1.0;
  }
  vec4 orig = texelFetch(u_pad, p + ivec2(u_k), 0);
  float m = u_useSel == 1 ? texelFetch(u_sel, p, 0).r : 1.0;
  o = mix(orig, acc / n, m);
}`,

  // פילטרים אמנותיים על אריח מרופד (u_k — הטווח): 1 ציור שמן (Kuwahara), 2 פסיפס, 3 תבליט, 4 קצוות (Sobel).
  // u_p: שמן — כלום; פסיפס — x גודל התא, yz פינת האריח במסמך; תבליט — x עוצמה, yz ההיסט (בפיקסלים)
  art: `#version 300 es
precision highp float;
uniform sampler2D u_pad, u_sel;
uniform int u_k, u_useSel, u_mode;
uniform vec4 u_p;
out vec4 o;
${ENCODE}
vec3 dec(vec3 s) { s = clamp(s, 0.0, 1.0); return mix(s / 12.92, pow((s + 0.055) / 1.055, vec3(2.4)), step(0.04045, s)); }
vec4 at(ivec2 p) { return texelFetch(u_pad, p + ivec2(u_k), 0); }
vec3 srgb(vec4 c) { return c.a > 0.0 ? enc(c.rgb / c.a) : vec3(0.0); }
float lum(vec4 c) { return dot(srgb(c), vec3(0.299, 0.587, 0.114)) * c.a; }
void main() {
  ivec2 p = ivec2(gl_FragCoord.xy);
  vec4 orig = at(p), r = orig;
  if (u_mode == 1) {
    float bv = 1e9;
    for (int q = 0; q < 4; q++) {
      ivec2 sg = ivec2(q == 0 || q == 2 ? -1 : 1, q < 2 ? -1 : 1);
      vec4 acc = vec4(0.0); float s1 = 0.0, s2 = 0.0, n = 0.0;
      for (int j = 0; j <= 8; j++) {
        if (j > u_k) break;
        for (int i = 0; i <= 8; i++) {
          if (i > u_k) break;
          vec4 c = at(p + sg * ivec2(i, j));
          float l = lum(c);
          acc += c; s1 += l; s2 += l * l; n += 1.0;
        }
      }
      float v = s2 / n - (s1 / n) * (s1 / n);
      if (v < bv) { bv = v; r = acc / n; }
    }
  } else if (u_mode == 2) {
    int c = int(u_p.x);
    ivec2 org = ivec2(u_p.yz);
    ivec2 c0 = ivec2(floor(vec2(p + org) / float(c))) * c;
    r = at(clamp(c0 + c / 2 - org, ivec2(-u_k), ivec2(255 + u_k)));
  } else if (u_mode == 3) {
    ivec2 d = ivec2(round(u_p.yz));
    float g = 0.5 + (lum(at(p + d)) - lum(at(p - d))) * u_p.x;
    r = vec4(dec(vec3(g)) * orig.a, orig.a);
  } else {
    vec3 gx = vec3(0.0), gy = vec3(0.0);
    for (int j = -1; j <= 1; j++) for (int i = -1; i <= 1; i++) {
      vec4 c = at(p + ivec2(i, j));
      vec3 s = srgb(c) * c.a;
      float wx = float(i) * (j == 0 ? 2.0 : 1.0), wy = float(j) * (i == 0 ? 2.0 : 1.0);
      gx += s * wx; gy += s * wy;
    }
    vec3 e = 1.0 - clamp(sqrt(gx * gx + gy * gy), 0.0, 1.0);
    r = vec4(dec(e) * orig.a, orig.a);
  }
  float m = u_useSel == 1 ? texelFetch(u_sel, p, 0).r : 1.0;
  o = mix(orig, r, m);
}`,

  // סגנונות שכבה (1): התוכן המרופד כפול המסכה המרופדת (אפור של sRGB) — הבסיס של כל האפקטים
  fxsrc: `#version 300 es
precision highp float;
uniform sampler2D u_c, u_m, u_v;
uniform int u_useMask, u_useVm;
out vec4 o;
${ENCODE}
void main() {
  ivec2 p = ivec2(gl_FragCoord.xy);
  vec4 c = texelFetch(u_c, p, 0);
  if (u_useMask == 1) c *= enc(vec3(texelFetch(u_m, p, 0).r)).r;
  if (u_useVm == 1) c *= texelFetch(u_v, p, 0).r;   // מסכה וקטורית (מרופדת)
  o = c;
}`,

  // (2) טשטוש גאוסי של האלפא בכיוון אחד (u_dir), על כל השטח המרופד (u_n × u_n); u_inv — של 1 − אלפא (אפקט פנימי)
  fxblur: `#version 300 es
precision highp float;
uniform sampler2D u_tex;
uniform int u_r, u_inv, u_n;
uniform ivec2 u_dir;
uniform float u_sigma;
out vec4 o;
void main() {
  ivec2 p = ivec2(gl_FragCoord.xy);
  float acc = 0.0, wsum = 0.0;
  for (int i = -64; i <= 64; i++) {
    if (i < -u_r || i > u_r) continue;
    float w = exp(-float(i * i) / (2.0 * u_sigma * u_sigma));
    float a = texelFetch(u_tex, clamp(p + u_dir * i, ivec2(0), ivec2(u_n - 1)), 0).a;
    acc += (u_inv == 1 ? 1.0 - a : a) * w; wsum += w;
  }
  o = vec4(acc / wsum);
}`,

  // (3) צעד אחד בבניית השכבה עם האפקטים. u_src — התוכן המרופד (fxsrc), u_blur — האלפא המטושטש, u_base — מה שנבנה
  // עד כה, u_top — לשלב "מעל". u_k — הריפוד; u_n — גודל המרקמים המרופדים. u_col — צבע לינארי ואטימות;
  // u_geo — dx, dy, spread, עובי. מצבים: 0 התוכן · 1 top מעל base · 2 צל / זוהר (מתחת) · 3 פנימי · 4 צבע · 5 קו מתאר
  fx: `#version 300 es
precision highp float;
uniform sampler2D u_src, u_blur, u_base, u_top;
uniform int u_mode, u_k, u_pos;
uniform vec4 u_col, u_geo;
uniform float u_n;
out vec4 o;
float spread(float s) { return clamp(s / (1.0 - u_geo.z), 0.0, 1.0); }
void main() {
  ivec2 p = ivec2(gl_FragCoord.xy);
  vec4 b = texelFetch(u_base, p, 0);
  vec2 q = vec2(p) + vec2(float(u_k)) + 0.5;
  if (u_mode == 0) { o = texelFetch(u_src, p + ivec2(u_k), 0); return; }
  if (u_mode == 1) { vec4 t = texelFetch(u_top, p, 0); o = t + b * (1.0 - t.a); return; }
  if (u_mode == 2) {
    float c = u_col.a * spread(texture(u_blur, (q - u_geo.xy) / u_n).r);
    o = vec4(u_col.rgb * c, c) + b * (1.0 - c);
    return;
  }
  // פנימי וצבע: רק בגבולות האלפא של התוכן (source-atop) — האלפא לא משתנה
  if (u_mode == 3 || u_mode == 4) {
    float e = u_mode == 4 ? u_col.a : u_col.a * spread(texture(u_blur, (q - u_geo.xy) / u_n).r);
    o = vec4(u_col.rgb * e * b.a + b.rgb * (1.0 - e), b.a);
    return;
  }
  // קו מתאר: התרחבות / התכווצות של האלפא בדיסק (8 טבעות × 32 כיוונים); u_pos — 0 בחוץ, 1 בפנים, 2 במרכז
  float a0 = texture(u_src, q / u_n).a, dil = a0, ero = a0;
  float R = u_pos == 2 ? u_geo.w * 0.5 : u_geo.w;
  for (int ri = 1; ri <= 8; ri++) {
    float r = R * float(ri) / 8.0;
    for (int ai = 0; ai < 32; ai++) {
      float ang = (float(ai) + 0.5 * float(ri & 1)) * 0.19634954;
      float v = texture(u_src, (q + r * vec2(cos(ang), sin(ang))) / u_n).a;
      dil = max(dil, v); ero = min(ero, v);
    }
  }
  float c = u_col.a * (u_pos == 0 ? max(dil - a0, 0.0) : u_pos == 1 ? a0 - ero : dil - ero);
  o = vec4(u_col.rgb * c, c) + b * (1.0 - c);
}`,

  copy: `#version 300 es
precision highp float;
uniform sampler2D u_tex;
in vec2 v_uv; out vec4 o;
void main() { o = texture(u_tex, v_uv); }`,

  // חילוץ לתוכן צף: השכבה כפול מסכת הבחירה
  extract: `#version 300 es
precision highp float;
uniform sampler2D u_tex, u_sel;
uniform int u_useSel;
in vec2 v_uv; out vec4 o;
void main() { o = texture(u_tex, v_uv) * (u_useSel == 1 ? texture(u_sel, v_uv).r : 1.0); }`,

  // התצוגה: מעל משבצות שקיפות, בלינארי, ואז sRGB
  display: `#version 300 es
precision highp float;
uniform sampler2D u_tex;
uniform float u_check;
in vec2 v_uv; out vec4 o;
${ENCODE}
void main() {
  vec4 c = texture(u_tex, v_uv);
  float k = mod(floor(gl_FragCoord.x / u_check) + floor(gl_FragCoord.y / u_check), 2.0);
  vec3 bg = mix(vec3(1.0), vec3(0.6038), k);    // #fff / #ccc בלינארי
  o = vec4(enc(c.rgb + bg * (1.0 - c.a)), 1.0);
}`,

  // תמונה ממוזערת של שכבה: 4×4 דגימות לכל פיקסל (לשכבה אין mipmaps), מעל משבצות, ב-sRGB
  thumb: `#version 300 es
precision highp float;
uniform sampler2D u_tex;
uniform vec2 u_step;    // רבע מטביעת הרגל של פיקסל ממוזער, ב-uv של האריח
in vec2 v_uv; out vec4 o;
${ENCODE}
void main() {
  vec4 c = vec4(0.0);
  for (int j = 0; j < 4; j++) for (int i = 0; i < 4; i++)
    c += texture(u_tex, v_uv + (vec2(i, j) - 1.5) * u_step);
  c /= 16.0;
  float k = mod(floor(gl_FragCoord.x / 4.0) + floor(gl_FragCoord.y / 4.0), 2.0);
  vec3 bg = mix(vec3(1.0), vec3(0.6038), k);
  o = vec4(enc(c.rgb + bg * (1.0 - c.a)), 1.0);
}`,

  // קריאה החוצה: פיקסל-פיקסל (texelFetch, בלי סינון) → sRGB ישר של 8 ביט
  read: `#version 300 es
precision highp float;
uniform sampler2D u_tex;
out vec4 o;
${ENCODE}
void main() {
  vec4 c = texelFetch(u_tex, ivec2(gl_FragCoord.xy), 0);
  o = c.a > 0.0 ? vec4(enc(c.rgb / c.a), c.a) : vec4(0.0);
}`,
};

const MIP_LEVELS = Math.log2(TILE) + 1;
const gl_unbind = gl => gl.bindFramebuffer(gl.FRAMEBUFFER, null);
const key = (tx, ty) => tx + ',' + ty;
// סגנונות שכבה: ריפוד מרבי (טווח האפקט, ראו effectsReach) וגודל מרקמי הריפוד
export const FX_MAX = 128;
const FX_N = TILE + 2 * FX_MAX;
/** יש במסמך שכבה עם סגנונות — שינוי באריח משנה גם את השכנים */
export const anyEffects = list => list.some(l => l.effects?.length || (l.children && anyEffects(l.children)));
const hexLinear = h => [1, 3, 5].map(i => srgbToLinear(parseInt(h.slice(i, i + 2), 16)));
const FX_POS = ['outside', 'inside', 'center'];

/** sRGB של 8 ביט → לינארי (0–1) */
export const srgbToLinear = v => {
  const c = v / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};

export class WebGL2Renderer {
  static supported() {
    try {
      const gl = document.createElement('canvas').getContext('webgl2');
      return !!(gl && (gl.getExtension('EXT_color_buffer_float') || gl.getExtension('EXT_color_buffer_half_float')));
    } catch { return false; }
  }

  constructor(canvas) {
    const gl = canvas.getContext('webgl2', { alpha: true, premultipliedAlpha: true, antialias: false, depth: false, stencil: false });
    if (!gl) throw new Error('no_webgl2');
    // בלי זה אי אפשר לרנדר לתוך מרקם float16
    if (!gl.getExtension('EXT_color_buffer_float') && !gl.getExtension('EXT_color_buffer_half_float')) {
      throw new Error('no_float_target');
    }
    this.name = 'WebGL2';
    this.gl = gl;
    this.canvas = canvas;
    this.maxTexture = gl.getParameter(gl.MAX_TEXTURE_SIZE);
    this.lost = false;
    canvas.addEventListener('webglcontextlost', e => { e.preventDefault(); this.lost = true; this.onLost?.(); });

    this.prog = {};
    for (const [name, src] of Object.entries(FRAG)) this.prog[name] = this.#program(VERT, src);
    this.prog.float = this.#program(VERT_M, FRAG.copy);
    this.prog.mesh = this.#program(VERT_Q, FRAG.copy);

    this.vao = gl.createVertexArray();
    gl.bindVertexArray(this.vao);
    const buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([0, 0, 1, 0, 0, 1, 1, 1]), gl.STATIC_DRAW);
    gl.enableVertexAttribArray(0);
    gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);

    this.fbo = gl.createFramebuffer();
    this.fboRead = gl.createFramebuffer();
    this.scratch = [this.#tileTexture(1), this.#tileTexture(1)];
    this.strokeTmp = this.#tileTexture(1);   // השכבה הפעילה + המשיכה, לתצוגה בזמן ציור
    this.floatTmp = this.#tileTexture(1);    // השכבה + התוכן הצף, לתצוגה בזמן שינוי צורה
    this.adjTmp = this.#tileTexture(1);      // השכבה אחרי תיקון, לתצוגה חיה
    this.shiftTmp = this.#tileTexture(1);    // שכבה בהזזת קבוצה, לתצוגה בזמן הגרירה
    this.maskTmp = this.#tileTexture(1);     // שכבה כפול המסכה שלה, לקריאה החוצה
    this.adjLayerTmp = this.#tileTexture(1); // מה שמתחת לשכבת התאמה, אחרי ההתאמה
    this.healTmp = [this.#tileTexture(1), this.#tileTexture(1)];   // טשטוש המקור והיעד — מברשת ריפוי
    this.fillTmp = this.#tileTexture(1);     // אריח של שכבת מילוי
    // טשטוש: אריח מרופד בשכניו (256 + 2·64), ותוצאת המעבר האופקי
    this.padTex = this.#floatTex(TILE + 128, TILE + 128);
    this.blurTmp = this.#floatTex(TILE, TILE + 128);
    this.emptyTex = this.#tileTexture(1);    // אריח שקוף — במקום אריח שעוד לא קיים
    // מסכות קבועות (R8): הכול / כלום — משותפות, לא משתחררות
    this.fullMask = this.#r8(new Uint8Array(TILE * TILE).fill(255));
    this.zeroMask = this.#r8(new Uint8Array(TILE * TILE));
    this.readTex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, this.readTex);
    gl.texStorage2D(gl.TEXTURE_2D, 1, gl.RGBA8, TILE, TILE);
    this.#nearest();
    this.textures = 0;
  }

  // ---------- תשתית ----------

  #program(vs, fs) {
    const gl = this.gl;
    const sh = (type, src) => {
      const s = gl.createShader(type);
      gl.shaderSource(s, src);
      gl.compileShader(s);
      if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error('shader: ' + gl.getShaderInfoLog(s));
      return s;
    };
    const p = gl.createProgram();
    gl.attachShader(p, sh(gl.VERTEX_SHADER, vs));
    gl.attachShader(p, sh(gl.FRAGMENT_SHADER, fs));
    gl.bindAttribLocation(p, 0, 'a');
    gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) throw new Error('link: ' + gl.getProgramInfoLog(p));
    const u = {};
    const n = gl.getProgramParameter(p, gl.ACTIVE_UNIFORMS);
    for (let i = 0; i < n; i++) {
      const name = gl.getActiveUniform(p, i).name;
      u[name] = gl.getUniformLocation(p, name);
    }
    return { p, u };
  }

  #floatTex(w, h) {
    const gl = this.gl;
    const t = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, t);
    gl.texStorage2D(gl.TEXTURE_2D, 1, gl.RGBA16F, w, h);
    this.#nearest();
    return t;
  }

  /** מרקם R8 של אריח מתוך בתים */
  #r8(bytes) {
    const gl = this.gl;
    const t = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, t);
    gl.texStorage2D(gl.TEXTURE_2D, 1, gl.R8, TILE, TILE);
    gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);
    gl.texSubImage2D(gl.TEXTURE_2D, 0, 0, 0, TILE, TILE, gl.RED, gl.UNSIGNED_BYTE, bytes);
    this.#nearest();
    return t;
  }

  #nearest() {
    const gl = this.gl;
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
  }

  /** אריח float16. WebGL מאפס מרקם חדש — אריח חדש שקוף. */
  #tileTexture(levels) {
    const gl = this.gl;
    const t = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, t);
    gl.texStorage2D(gl.TEXTURE_2D, levels, gl.RGBA16F, TILE, TILE);
    this.#nearest();
    if (levels > 1) {
      // התרחקות — mipmaps (בלי ריצוד); התקרבות — פיקסלים חדים, כמו בכל עורך תמונות
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR);
    }
    this.textures++;
    return t;
  }

  #target(tex) {
    const gl = this.gl;
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, tex, 0);
    gl.viewport(0, 0, TILE, TILE);
  }

  /** מצייר מלבן. rect ו-uv — [x0, y0, x1, y1]. */
  #quad(prog, rect, uv, size, flip) {
    const gl = this.gl;
    gl.uniform4fv(prog.u.u_rect, rect);
    gl.uniform4fv(prog.u.u_uv, uv);
    gl.uniform2f(prog.u.u_size, size[0], size[1]);
    gl.uniform1f(prog.u.u_flip, flip);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
  }

  #layerTile(layer, tx, ty) {
    let t = layer.tiles.get(key(tx, ty));
    if (!t) { t = this.#tileTexture(1); layer.tiles.set(key(tx, ty), t); }
    return t;
  }

  #dirty(doc, x, y, w, h) {
    doc._dirty ??= new Set();
    if (anyEffects(doc.layers)) { x -= FX_MAX; y -= FX_MAX; w += 2 * FX_MAX; h += 2 * FX_MAX; }
    for (const [tx, ty] of tilesInRect(x, y, w, h)) doc._dirty.add(key(tx, ty));
  }

  /** כל המסמך ישורטט מחדש (למשל אחרי שינוי שקיפות או מצב מיזוג של שכבה). */
  invalidate(doc) {
    this.#dirty(doc, 0, 0, doc.width, doc.height);
  }

  /** רק האריחים האלה ישורטטו מחדש (keys — "tx,ty"). */
  invalidateTiles(doc, keys) {
    doc._dirty ??= new Set();
    const fx = anyEffects(doc.layers);
    for (const k of keys) {
      doc._dirty.add(k);
      if (!fx) continue;
      const [tx, ty] = k.split(',').map(Number);
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) doc._dirty.add(key(tx + dx, ty + dy));
    }
  }

  // ---------- שכבות ----------

  /** משחרר את האריחים של שכבה (אחרי מחיקה מהמסמך). */
  freeLayer(layer) {
    for (const t of layer.tiles.values()) { this.gl.deleteTexture(t); this.textures--; }
    layer.tiles.clear();
    if (layer.mask) this.freeLayer(layer.mask);
    this.#freeVm(layer);
  }

  // ---------- מסכה וקטורית ----------

  /** משחרר את האריחים המרוסטרים של המסכה הווקטורית (המסכות הקבועות — משותפות, לא משתחררות) */
  #freeVm(layer) {
    const c = layer._vm;
    if (!c) return;
    for (const t of c.tiles.values()) if (t !== this.fullMask && t !== this.zeroMask) { this.gl.deleteTexture(t); this.textures--; }
    layer._vm = null;
  }

  /**
   * הכיסוי של המסכה הווקטורית באריח (R8), או null — אין מסכה / מושבתת. מרוסטר בשימוש הראשון ונשמר עד שהמסכה
   * (d / ריכוך) או הזזת הקבוצה משתנים.
   */
  #vmTex(doc, layer, tx, ty) {
    const vm = layer.vectorMask;
    if (!vm || vm.enabled === false) return null;
    const sh = doc?._shift?.layers.has(layer) ? doc._shift : null;
    const sig = `${vm.d}|${vm.feather ?? 0}|${sh ? sh.dx + ',' + sh.dy : ''}`;
    if (layer._vm?.sig !== sig) { this.#freeVm(layer); layer._vm = { sig, tiles: new Map() }; }
    const k = key(tx, ty);
    let t = layer._vm.tiles.get(k);
    if (!t) {
      const r = vmaskTile(vm, tx, ty, sh?.dx ?? 0, sh?.dy ?? 0);
      t = r === 'empty' ? this.zeroMask : r === 'full' ? this.fullMask : this.#r8(r);
      if (typeof r !== 'string') this.textures++;
      layer._vm.tiles.set(k, t);
    }
    return t;
  }

  /** עותק של אריח (מרקם חדש). */
  #copyTile(t) {
    const gl = this.gl;
    const c = this.#tileTexture(1);
    gl.bindFramebuffer(gl.READ_FRAMEBUFFER, this.fboRead);
    gl.framebufferTexture2D(gl.READ_FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, t, 0);
    gl.bindFramebuffer(gl.DRAW_FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.DRAW_FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, c, 0);
    gl.blitFramebuffer(0, 0, TILE, TILE, 0, 0, TILE, TILE, gl.COLOR_BUFFER_BIT, gl.NEAREST);
    gl.bindFramebuffer(gl.READ_FRAMEBUFFER, null);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    return c;
  }

  /** מעתיק את כל האריחים של src ל-dst (שכפול שכבה). dst מתרוקנת קודם. */
  copyLayer(src, dst) {
    this.freeLayer(dst);
    for (const [k, t] of src.tiles) dst.tiles.set(k, this.#copyTile(t));
    dst.rev = (dst.rev ?? 0) + 1;
    if (src.mask) {
      dst.mask = newMask(dst, src.mask.enabled);
      this.copyLayer(src.mask, dst.mask);
    }
  }

  /**
   * צילום "לפני" של אריחים — לביטול. מחזיר Map: מפתח → עותק, או null לאריח שעוד לא קיים.
   * keys — מפתחות "tx,ty" (tileKeys). העותקים שייכים להיסטוריה, והיא משחררת אותם (freeTextures).
   */
  snapshot(layer, keys) {
    const out = new Map();
    for (const k of keys) {
      const t = layer.tiles.get(k);
      out.set(k, t ? this.#copyTile(t) : null);
    }
    return out;
  }

  /** משחרר מרקמים שאינם בשום שכבה (מהיסטוריה). */
  freeTextures(texs) {
    for (const t of texs) if (t) { this.gl.deleteTexture(t); this.textures--; }
  }

  /**
   * תמונה ממוזערת של שכבה אחת (בלי אטימות ומיזוג — השכבה כמו שהיא), מעל משבצות שקיפות.
   * מחזיר Promise ל-ImageData; הצלע הארוכה = max פיקסלים.
   */
  async thumbnail(doc, layer, max = 64) {
    const gl = this.gl;
    const k = Math.min(1, max / Math.max(doc.width, doc.height));
    const tw = Math.max(1, Math.round(doc.width * k)), th = Math.max(1, Math.round(doc.height * k));
    if (!this.thumbTex || this.thumbSize !== max) {
      if (this.thumbTex) gl.deleteTexture(this.thumbTex);
      this.thumbTex = gl.createTexture();
      gl.bindTexture(gl.TEXTURE_2D, this.thumbTex);
      gl.texStorage2D(gl.TEXTURE_2D, 1, gl.RGBA8, max, max);
      this.#nearest();
      this.thumbSize = max;
    }
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, this.thumbTex, 0);
    gl.viewport(0, 0, max, max);
    // רקע: משבצות — מציירים "אריח ריק" על כל השטח
    const p = this.prog.thumb;
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0);
    gl.uniform1i(p.u.u_tex, 0);
    gl.bindTexture(gl.TEXTURE_2D, this.scratch[1]);
    gl.clearColor(0, 0, 0, 0);
    this.#target(this.scratch[1]);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, this.thumbTex, 0);
    gl.viewport(0, 0, max, max);
    gl.bindTexture(gl.TEXTURE_2D, this.scratch[1]);
    gl.uniform2f(p.u.u_step, 0, 0);
    this.#quad(p, [0, 0, tw, th], [0, 0, 1, 1], [max, max], -1);
    // האריחים של השכבה שבתוך הקנבס
    const step = 1 / k / TILE / 4;
    gl.uniform2f(p.u.u_step, step, step);
    for (const [tx, ty] of tilesInRect(0, 0, doc.width, doc.height)) {
      const t = layer.tiles.get(key(tx, ty));
      if (!t) continue;
      gl.bindTexture(gl.TEXTURE_2D, t);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
      const x0 = tx * TILE, y0 = ty * TILE;
      const x1 = Math.min(doc.width, x0 + TILE), y1 = Math.min(doc.height, y0 + TILE);
      this.#quad(p, [x0 * k, y0 * k, x1 * k, y1 * k], [0, 0, (x1 - x0) / TILE, (y1 - y0) / TILE], [max, max], -1);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
    }
    const px = new Uint8ClampedArray(tw * th * 4);
    gl.readPixels(0, 0, tw, th, gl.RGBA, gl.UNSIGNED_BYTE, px);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    return new ImageData(px, tw, th);
  }

  // ---------- כתיבה לשכבות ----------

  /** ממלא את כל הקנבס בשכבה בצבע אחיד. rgba — sRGB של 8 ביט, אלפא 0–255. */
  fill(doc, layer, [r, g, b, a = 255]) {
    const gl = this.gl;
    const p = this.prog.fill;
    const al = a / 255;
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.uniform4f(p.u.u_color, srgbToLinear(r) * al, srgbToLinear(g) * al, srgbToLinear(b) * al, al);
    for (const [tx, ty] of tilesInRect(0, 0, doc.width, doc.height)) {
      this.#target(this.#layerTile(layer, tx, ty));
      const w = Math.min(TILE, doc.width - tx * TILE), h = Math.min(TILE, doc.height - ty * TILE);
      this.#quad(p, [0, 0, w, h], [0, 0, 1, 1], [TILE, TILE], -1);
    }
    layer.rev = (layer.rev ?? 0) + 1;   // התוכן השתנה — התמונה הממוזערת מתעדכנת
    this.invalidate(doc);
  }

  /**
   * מעלה תמונה לשכבה, כשהפינה שלה ב-(dx, dy) במסמך. מחליפה את הפיקסלים במלבן (לא מערבבת).
   * source — ImageBitmap שנוצר עם premultiplyAlpha: 'none' (decodeImage למטה).
   * תמונה גדולה ממרקם אחד — מועלית בחתיכות.
   */
  async drawImage(doc, layer, source, dx = 0, dy = 0) {
    const gl = this.gl;
    const W = source.width, H = source.height;
    const chunk = Math.min(this.maxTexture, 4096);
    const src = gl.createTexture();
    for (let cy = 0; cy < H; cy += chunk) {
      for (let cx = 0; cx < W; cx += chunk) {
        const cw = Math.min(chunk, W - cx), ch = Math.min(chunk, H - cy);
        const part = cw === W && ch === H ? source
          : await createImageBitmap(source, cx, cy, cw, ch, { premultiplyAlpha: 'none', colorSpaceConversion: 'none' });
        gl.bindTexture(gl.TEXTURE_2D, src);
        gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
        gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, false);
        gl.pixelStorei(gl.UNPACK_COLORSPACE_CONVERSION_WEBGL, gl.NONE);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.SRGB8_ALPHA8, cw, ch, 0, gl.RGBA, gl.UNSIGNED_BYTE, part);
        this.#nearest();
        if (part !== source) part.close();

        const p = this.prog.upload;
        gl.useProgram(p.p);
        gl.bindVertexArray(this.vao);
        gl.disable(gl.BLEND);
        gl.activeTexture(gl.TEXTURE0);
        gl.uniform1i(p.u.u_src, 0);
        const X = dx + cx, Y = dy + cy;   // החתיכה, בקואורדינטות המסמך
        for (const [tx, ty] of tilesInRect(X, Y, cw, ch)) {
          const ox = tx * TILE, oy = ty * TILE;
          const x0 = Math.max(X, ox), y0 = Math.max(Y, oy);
          const x1 = Math.min(X + cw, ox + TILE), y1 = Math.min(Y + ch, oy + TILE);
          // אריח חדש נוצר עם bindTexture — לכן קושרים את המקור מחדש רק אחריו
          const tile = this.#layerTile(layer, tx, ty);
          this.#target(tile);
          gl.bindTexture(gl.TEXTURE_2D, src);
          this.#quad(p, [x0 - ox, y0 - oy, x1 - ox, y1 - oy],
            [(x0 - X) / cw, (y0 - Y) / ch, (x1 - X) / cw, (y1 - Y) / ch], [TILE, TILE], -1);
        }
      }
    }
    gl.deleteTexture(src);
    layer.rev = (layer.rev ?? 0) + 1;
    this.#dirty(doc, dx, dy, W, H);
  }

  // ---------- משיכות: מכחול, מחק, דלי ----------
  //
  // משיכה נצברת במסכה נפרדת (לכל אריח), וכל נקודה שומרת את המקסימום — כך משיכה באטימות 50% לא
  // מתעבה כשהיא חוצה את עצמה. השכבה עצמה משתנה רק ב-endStroke. עד אז ההרכבה מציגה שכבה + מסכה.

  /**
   * opts: { mode: 'paint' | 'erase' | 'clone', color: [r, g, b] (sRGB), opacity: 0–1, offset: [dx, dy] }
   * clone — חותמת שכפול: כל פיקסל מקבל את הפיקסל של השכבה ב-offset ממנו (המקור פחות היעד, בפיקסלים שלמים),
   * כפי שהשכבה הייתה בתחילת המשיכה — המשיכה לא משכפלת את עצמה.
   * heal — מברשת ריפוי: בזמן המשיכה כמו clone; ב-endStroke המקור מותאם לאור ולצבע של היעד (#healTiles,
   * טשטוש ברדיוס healRadius).
   */
  beginStroke(doc, layer, { mode = 'paint', color = [0, 0, 0], opacity = 1, offset = [0, 0], healRadius = 16 } = {}) {
    if (doc._stroke) this.cancelStroke(doc);
    doc._stroke = {
      doc, layer, mode, opacity, mask: new Map(),
      color: [srgbToLinear(color[0]), srgbToLinear(color[1]), srgbToLinear(color[2]), 1],
      ...(mode === 'clone' || mode === 'heal'
        ? { from: new Map(layer.tiles), offset: offset.map(Math.round), src: new Map(), heal: new Map(), healK: Math.max(4, Math.min(64, Math.round(healRadius))) }
        : {}),
    };
  }

  /** אריח המקור של חותמת השכפול ליעד k: עד ארבעה אריחים של השכבה (מתחילת המשיכה), מוזזים. נבנה פעם אחת. */
  #cloneSrc(st, k) {
    let t = st.src.get(k);
    if (t) return t;
    const gl = this.gl;
    const [tx, ty] = k.split(',').map(Number);
    const [dx, dy] = st.offset;
    t = this.#tileTexture(1);
    this.#target(t);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    const p = this.prog.copy;
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0);
    gl.uniform1i(p.u.u_tex, 0);
    for (const [sx, sy] of tilesInRect(tx * TILE + dx, ty * TILE + dy, TILE, TILE)) {
      const src = st.from.get(key(sx, sy));
      if (!src) continue;
      const ox = sx * TILE - dx - tx * TILE, oy = sy * TILE - dy - ty * TILE;
      gl.bindTexture(gl.TEXTURE_2D, src);
      this.#quad(p, [ox, oy, ox + TILE, oy + TILE], [0, 0, 1, 1], [TILE, TILE], -1);
    }
    st.src.set(k, t);
    return t;
  }

  #maskTile(st, k) {
    let m = st.mask.get(k);
    if (!m) { m = this.#tileTexture(1); st.mask.set(k, m); }
    return m;
  }

  /** נקודת מכחול: מרכז (x, y) במסמך, רדיוס r בפיקסלים, קשיות 0–1, עוצמה 0–1. */
  dab(doc, x, y, r, hardness = 1, alpha = 1) {
    const st = doc._stroke;
    if (!st) return;
    const gl = this.gl;
    r = Math.max(0.5, r);
    const R = r + 1;
    const p = this.prog.dab;
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.enable(gl.BLEND);
    gl.blendEquation(gl.MAX);
    gl.blendFunc(gl.ONE, gl.ONE);
    gl.uniform1f(p.u.u_r, r);
    gl.uniform1f(p.u.u_hard, hardness);
    gl.uniform1f(p.u.u_alpha, alpha);
    const x0 = Math.floor(x - R), y0 = Math.floor(y - R);
    const keys = [];
    for (const [tx, ty] of tilesInRect(x0, y0, Math.ceil(x + R) - x0, Math.ceil(y + R) - y0)) {
      const k = key(tx, ty);
      keys.push(k);
      // אריח חדש נוצר עם bindTexture — לפני שקושרים את ה-FBO
      this.#target(this.#maskTile(st, k));
      const ox = tx * TILE, oy = ty * TILE;
      this.#quad(p, [x - R - ox, y - R - oy, x + R - ox, y + R - oy], [-R, -R, R, R], [TILE, TILE], -1);
    }
    gl.disable(gl.BLEND);
    gl.blendEquation(gl.FUNC_ADD);
    this.invalidateTiles(doc, keys);
  }

  /**
   * מסכה מוכנה מבחוץ (הדלי): alpha — Uint8Array של w×h (0–255) שמתחילה ב-(x, y) במסמך.
   * box — [x0, y0, x1, y1] — רק האריחים שבתוכו.
   */
  maskFromAlpha(doc, alpha, x, y, w, h, box = [0, 0, w - 1, h - 1]) {
    const st = doc._stroke;
    if (!st) return;
    const gl = this.gl;
    const buf = new Uint8Array(TILE * TILE);
    const keys = [];
    for (const [tx, ty] of tilesInRect(x + box[0], y + box[1], box[2] - box[0] + 1, box[3] - box[1] + 1)) {
      buf.fill(0);
      const ox = tx * TILE, oy = ty * TILE;
      for (let r = 0; r < TILE; r++) {
        const sy = oy + r - y;
        if (sy < 0 || sy >= h) continue;
        const sx0 = Math.max(0, ox - x), sx1 = Math.min(w, ox + TILE - x);
        if (sx1 > sx0) buf.set(alpha.subarray(sy * w + sx0, sy * w + sx1), r * TILE + (sx0 + x - ox));
      }
      const k = key(tx, ty);
      const old = st.mask.get(k);
      if (old) this.freeTextures([old]);
      const t = gl.createTexture();
      gl.bindTexture(gl.TEXTURE_2D, t);
      gl.texStorage2D(gl.TEXTURE_2D, 1, gl.R8, TILE, TILE);
      gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);
      gl.texSubImage2D(gl.TEXTURE_2D, 0, 0, 0, TILE, TILE, gl.RED, gl.UNSIGNED_BYTE, buf);
      this.#nearest();
      this.textures++;
      st.mask.set(k, t);
      keys.push(k);
    }
    this.invalidateTiles(doc, keys);
  }

  #applyStroke(st, layerTex, maskTex, outTex, k = null) {
    const gl = this.gl;
    const p = this.prog.stroke;
    // מסכת הבחירה קודם — יצירתה קושרת מרקם, ואסור שזה יקרה אחרי שקשרנו את הקלט
    const sel = k && st.doc.selection ? this.#selTex(st.doc, k) : null;
    const src = k && st.src ? (st.heal.get(k) ?? this.#cloneSrc(st, k)) : null;
    this.#target(outTex);
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, layerTex);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, maskTex);
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, sel || this.fullMask);
    gl.activeTexture(gl.TEXTURE3); gl.bindTexture(gl.TEXTURE_2D, src || this.emptyTex);
    gl.uniform1i(p.u.u_layer, 0);
    gl.uniform1i(p.u.u_mask, 1);
    gl.uniform1i(p.u.u_sel, 2);
    gl.uniform1i(p.u.u_src, 3);
    gl.uniform1i(p.u.u_clone, src ? 1 : 0);
    gl.uniform1i(p.u.u_useSel, sel ? 1 : 0);
    gl.uniform4fv(p.u.u_color, st.color);
    gl.uniform1f(p.u.u_opacity, st.opacity);
    gl.uniform1i(p.u.u_erase, st.mode === 'erase' ? 1 : 0);
    this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
    gl.activeTexture(gl.TEXTURE3); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE0);
  }

  /**
   * מסיים את המשיכה: כל אריח שנגעו בו נכתב **למרקם חדש**, והישן עובר להיסטוריה כמו שהוא —
   * בלי העתקה. מחזיר Map: מפתח → המרקם הישן (או null) — בדיוק מה ש-history.pixels מצפה לו.
   */
  endStroke(doc) {
    const st = doc._stroke;
    if (!st) return new Map();
    doc._stroke = null;
    if (st.mode === 'heal') this.#healTiles(st);   // לפני שאריח כלשהו בשכבה מוחלף
    const before = new Map();
    for (const [k, m] of st.mask) {
      const old = st.layer.tiles.get(k) ?? null;
      if (!old && st.mode === 'erase') continue;   // אין מה למחוק
      const out = this.#tileTexture(1);
      this.#applyStroke(st, old || this.emptyTex, m, out, k);
      st.layer.tiles.set(k, out);
      before.set(k, old);
    }
    this.#freeMasks(st);
    gl_unbind(this.gl);
    st.layer.rev = (st.layer.rev ?? 0) + 1;
    this.invalidateTiles(doc, st.mask.keys());
    return before;
  }

  /** משיכה שבוטלה (למשל אצבע שנייה הפכה אותה לצביטה) — בלי שום שינוי בשכבה. */
  cancelStroke(doc) {
    const st = doc._stroke;
    if (!st) return;
    doc._stroke = null;
    this.#freeMasks(st);
    this.invalidateTiles(doc, st.mask.keys());
  }

  #freeMasks(st) {
    this.freeTextures([...st.mask.values()].filter(t => t !== this.fullMask && t !== this.zeroMask));
    if (st.src) this.freeTextures([...st.src.values(), ...st.heal.values()]);
  }

  // ---------- בחירה ----------

  /** הבחירה השתנתה (doc.selection) — המסכות שבמטמון נזרקות. */
  selectionChanged(doc) {
    if (doc._sel) this.freeTextures([...doc._sel.values()].filter(t => t !== this.fullMask && t !== this.zeroMask));
    doc._sel = new Map();
    if (doc._stroke) this.invalidate(doc);
  }

  /** מסכת הבחירה של אריח (R8) — מחושבת פעם אחת לכל בחירה. */
  #selTex(doc, k) {
    doc._sel ??= new Map();
    let t = doc._sel.get(k);
    if (t) return t;
    const [tx, ty] = k.split(',').map(Number);
    const box = selectionBox(doc.selection, doc.width, doc.height);
    const inBox = box && tx * TILE < box[2] && (tx + 1) * TILE > box[0] && ty * TILE < box[3] && (ty + 1) * TILE > box[1];
    const bytes = inBox ? rasterTile(doc.selection, tx, ty) : null;
    t = bytes ? this.#r8(bytes) : this.zeroMask;
    if (bytes) this.textures++;
    doc._sel.set(k, t);
    return t;
  }

  /**
   * ממלא (mode 'paint', בצבע) או מוחק ('erase') את כל הבחירה בשכבה. מחזיר את ה-before להיסטוריה.
   * בלי בחירה — כל הקנבס.
   */
  fillSelection(doc, layer, mode, color = [0, 0, 0], opacity = 1) {
    const box = doc.selection ? selectionBox(doc.selection, doc.width, doc.height) : [0, 0, doc.width, doc.height];
    if (!box) return new Map();
    this.beginStroke(doc, layer, { mode, color, opacity });
    for (const [tx, ty] of tilesInRect(box[0], box[1], box[2] - box[0], box[3] - box[1])) {
      doc._stroke.mask.set(key(tx, ty), this.fullMask);
    }
    return this.endStroke(doc);
  }

  // ---------- תיקונים: בהירות / ניגודיות, גוון / רוויה, טשטוש ----------
  //
  // setAdjust מציג תיקון חי על השכבה (בלי לגעת בה); commitAdjust כותב לאריחים חדשים, והישנים להיסטוריה.
  // adj = { kind: 'bc', brightness, contrast } | { kind: 'hsl', hue, saturation, lightness } | { kind: 'blur', radius }
  //     | { kind: 'sharpen', amount (%), sradius } | { kind: 'motion', angle (°), distance } | { kind: 'noise', noise (%) }

  setAdjust(doc, layer, adj) {
    doc._adjust = adj && !WebGL2Renderer.adjustNoop(adj) ? { ...adj, layer } : null;
    this.invalidate(doc);
  }

  /** תיקון שלא משנה כלום (רדיוס / כמות 0) — לא מוצג ולא נכתב */
  static adjustNoop(adj) {
    if (adj.kind === 'blur' || adj.kind === 'motion') return !WebGL2Renderer.adjustReach(adj);
    if (adj.kind === 'sharpen') return !WebGL2Renderer.adjustReach(adj) || !adj.amount;
    if (adj.kind === 'noise') return !adj.noise;
    if (adj.kind === 'mosaic') return !(adj.cell >= 2);
    return false;
  }

  /** כמה פיקסלים התיקון קורא מסביב לכל פיקסל (טשטוש, חידוד, תנועה) — 0: רק את הפיקסל עצמו. עד 64. */
  static adjustReach(adj) {
    const r = adj.kind === 'blur' ? adj.radius : adj.kind === 'sharpen' ? adj.sradius : adj.kind === 'motion' ? adj.distance / 2
      : adj.kind === 'oil' ? Math.min(8, Math.max(1, adj.oradius)) : adj.kind === 'mosaic' ? adj.cell
      : adj.kind === 'emboss' ? Math.ceil(adj.edepth) : adj.kind === 'edges' ? 1 : 0;
    return Math.max(0, Math.min(64, Math.round(r || 0)));
  }

  /** האריחים שהתיקון יכול לשנות: הקיימים, ולמה שקורא שכנים — גם השכנים (הטשטוש "נשפך") */
  #adjustKeys(adj, layer, doc) {
    // מילוי — כל הקנבס (גם אריחים שעוד אין בשכבה)
    if (adj.kind === 'fill') return new Set(tilesInRect(0, 0, doc.width, doc.height).map(([tx, ty]) => key(tx, ty)));
    const keys = new Set(layer.tiles.keys());
    if (WebGL2Renderer.adjustReach(adj) > 0) {
      for (const k of layer.tiles.keys()) {
        const [tx, ty] = k.split(',').map(Number);
        for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) keys.add(key(tx + dx, ty + dy));
      }
    }
    return keys;
  }

  #adjustTouches(adj, layer, tx, ty) {
    if (adj.kind === 'fill' || layer.tiles.has(key(tx, ty))) return true;
    if (!WebGL2Renderer.adjustReach(adj)) return false;
    for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) if (layer.tiles.has(key(tx + dx, ty + dy))) return true;
    return false;
  }

  /** פילטר אמנותי → { mode, p } ל-shader של art (tx, ty — האריח, לפסיפס שמיושר למסמך); אחר — null */
  static artParams(adj, tx, ty) {
    const mode = { oil: 1, mosaic: 2, emboss: 3, edges: 4 }[adj.kind];
    if (!mode) return null;
    if (mode === 2) return { mode, p: [Math.round(adj.cell), tx * TILE, ty * TILE, 0] };
    if (mode === 3) {
      const a = adj.eangle * Math.PI / 180, d = Math.ceil(adj.edepth);
      return { mode, p: [(adj.eamount ?? 100) / 100 * 2, Math.round(Math.cos(a) * d), Math.round(-Math.sin(a) * d), 0] };
    }
    return { mode, p: [0, 0, 0, 0] };
  }

  /** סוג התיקון → המספר שלו ב-shader של הצבע */
  static adjustKind(adj) {
    return { bc: 1, hsl: 2, noise: 3, levels: 4, invert: 5, posterize: 6, threshold: 7 }[adj.kind] ?? 2;
  }

  /** הערכים של המחוונים → פרמטרים ל-shader */
  static adjustParams(adj) {
    if (adj.kind === 'levels') {
      const b = (adj.black ?? 0) / 255, w = Math.max((adj.white ?? 255) / 255, b + 1 / 255);
      return [b, w, Math.max(0.1, Math.min(10, adj.gamma ?? 1)), 0];
    }
    if (adj.kind === 'invert') return [0, 0, 0, 0];
    if (adj.kind === 'posterize') return [Math.max(2, Math.min(255, Math.round(adj.levels ?? 4))), 0, 0, 0];
    if (adj.kind === 'threshold') return [(adj.level ?? 128) / 255, 0, 0, 0];
    if (adj.kind === 'bc') {
      const c = adj.contrast / 100;   // -1..1
      return [adj.brightness / 100, c >= 0 ? 1 / (1 - c * 0.99) : 1 + c, 0, 0];
    }
    if (adj.kind === 'noise') return [adj.noise / 100 * 0.5, 0, 0, 0];
    return [adj.hue / 360, 1 + adj.saturation / 100, adj.lightness / 100, 0];
  }

  #adjustTile(doc, adj, layer, tx, ty, base, out) {
    const gl = this.gl;
    const k = key(tx, ty);
    const sel = doc.selection ? this.#selTex(doc, k) : null;
    base ||= this.emptyTex;
    if (adj.kind === 'fill') {
      // מילוי הורס (כלי המעבר): המילוי מעל השכבה, ובבחירה — רק בתוכה
      this.#fillTile(adj.fill, tx, ty, this.fillTmp);
      this.#blendInto(out, base, this.fillTmp, 1, 0, sel);
      gl.activeTexture(gl.TEXTURE0);
      return;
    }
    if (WebGL2Renderer.adjustReach(adj) > 0) {
      const K = WebGL2Renderer.adjustReach(adj);
      this.#pad((dx, dy) => (dx || dy ? layer.tiles.get(key(tx + dx, ty + dy)) : base), K);
      const art = WebGL2Renderer.artParams(adj, tx, ty);
      if (art) {
        const m = this.prog.art;
        this.#target(out);
        gl.useProgram(m.p);
        gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, this.padTex);
        gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, sel || this.fullMask);
        gl.uniform1i(m.u.u_pad, 0); gl.uniform1i(m.u.u_sel, 1);
        gl.uniform1i(m.u.u_k, K); gl.uniform1i(m.u.u_mode, art.mode);
        gl.uniform1i(m.u.u_useSel, sel ? 1 : 0);
        gl.uniform4fv(m.u.u_p, art.p);
        this.#quad(m, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
        gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, null);
        gl.activeTexture(gl.TEXTURE0);
        return;
      }
      if (adj.kind === 'motion') {
        const m = this.prog.motion, a = adj.angle * Math.PI / 180;
        this.#target(out);
        gl.useProgram(m.p);
        gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, this.padTex);
        gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, sel || this.fullMask);
        gl.uniform1i(m.u.u_pad, 0); gl.uniform1i(m.u.u_sel, 1);
        gl.uniform1i(m.u.u_k, K);
        gl.uniform1i(m.u.u_useSel, sel ? 1 : 0);
        // זווית נגד כיוון השעון, כמו בפוטושופ: y של המרקם כלפי מטה
        gl.uniform2f(m.u.u_dir, Math.cos(a), -Math.sin(a));
        this.#quad(m, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
        gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, null);
        gl.activeTexture(gl.TEXTURE0);
        return;
      }
      this.#blurPad(K, out, sel, adj.kind === 'sharpen' ? adj.amount / 100 : 0);
    } else {
      const a = this.prog.adjust;
      this.#target(out);
      gl.useProgram(a.p);
      gl.bindVertexArray(this.vao);
      gl.disable(gl.BLEND);
      gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, base);
      gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, sel || this.fullMask);
      gl.uniform1i(a.u.u_tex, 0); gl.uniform1i(a.u.u_sel, 1);
      gl.uniform1i(a.u.u_kind, WebGL2Renderer.adjustKind(adj));
      gl.uniform1i(a.u.u_useSel, sel ? 1 : 0);
      const pp = WebGL2Renderer.adjustParams(adj);
      gl.uniform4fv(a.u.u_p, adj.kind === 'noise' ? [pp[0], tx * TILE, ty * TILE, 0] : pp);
      this.#quad(a, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
    }
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE0);
  }

  /** ריפוד: האריח ושמונת שכניו (get(dx, dy) → מרקם או כלום), כל אחד במקומו, ב-padTex בגודל TILE + 2K */
  #pad(get, K, target = this.padTex) {
    const gl = this.gl;
    const P = TILE + 2 * K;
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, target, 0);
    gl.viewport(0, 0, P, P);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    const c = this.prog.copy;
    gl.useProgram(c.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0);
    gl.uniform1i(c.u.u_tex, 0);
    for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
      const nt = get(dx, dy);
      if (!nt) continue;
      gl.bindTexture(gl.TEXTURE_2D, nt);
      const ox = K + dx * TILE, oy = K + dy * TILE;
      this.#quad(c, [ox, oy, ox + TILE, oy + TILE], [0, 0, 1, 1], [P, P], -1);
    }
  }

  /** טשטוש גאוסי של padTex (שני מעברים) ל-out; amount > 0 — חידוד; sel — רק בבחירה */
  #blurPad(K, out, sel = null, amount = 0) {
    const gl = this.gl;
    const P = TILE + 2 * K;
    const sigma = Math.max(0.5, K / 3);
    const h = this.prog.blurH;
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, this.blurTmp, 0);
    gl.viewport(0, 0, TILE, P);
    gl.useProgram(h.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, this.padTex);
    gl.uniform1i(h.u.u_tex, 0);
    gl.uniform1i(h.u.u_k, K);
    gl.uniform1f(h.u.u_sigma, sigma);
    this.#quad(h, [0, 0, TILE, P], [0, 0, 1, 1], [TILE, P], -1);
    const v = this.prog.blurV;
    this.#target(out);
    gl.useProgram(v.p);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, this.blurTmp);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, this.padTex);
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, sel || this.fullMask);
    gl.uniform1i(v.u.u_tex, 0); gl.uniform1i(v.u.u_pad, 1); gl.uniform1i(v.u.u_sel, 2);
    gl.uniform1i(v.u.u_k, K);
    gl.uniform1i(v.u.u_useSel, sel ? 1 : 0);
    gl.uniform1f(v.u.u_sigma, sigma);
    gl.uniform1f(v.u.u_amount, amount);
    this.#quad(v, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
  }

  /**
   * מברשת ריפוי (בסוף המשיכה): לכל אריח — המקור המוזז, ועוד ההפרש בין הטשטוש של היעד לטשטוש של המקור.
   * כך נשאר המרקם של המקור, והאור והצבע — של הסביבה במקום החדש. נשמר ב-st.heal, וה-shader של המשיכה משתמש בו.
   */
  #healTiles(st) {
    const gl = this.gl;
    const K = st.healK;
    for (const k of st.mask.keys()) {
      const [tx, ty] = k.split(',').map(Number);
      const near = (dx, dy) => key(tx + dx, ty + dy);
      // אריחי המקור של השכנים — קודם כולם (יצירתם מחליפה את יעד הציור, ואסור באמצע הריפוד)
      const srcs = new Map();
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) srcs.set(near(dx, dy), this.#cloneSrc(st, near(dx, dy)));
      this.#pad((dx, dy) => srcs.get(near(dx, dy)), K);
      this.#blurPad(K, this.healTmp[0]);
      this.#pad((dx, dy) => st.from.get(near(dx, dy)), K);
      this.#blurPad(K, this.healTmp[1]);
      const out = this.#tileTexture(1);
      const p = this.prog.heal;
      this.#target(out);
      gl.useProgram(p.p);
      gl.bindVertexArray(this.vao);
      gl.disable(gl.BLEND);
      gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, this.#cloneSrc(st, k));
      gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, this.healTmp[1]);
      gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, this.healTmp[0]);
      gl.uniform1i(p.u.u_src, 0); gl.uniform1i(p.u.u_bDst, 1); gl.uniform1i(p.u.u_bSrc, 2);
      this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
      st.heal.set(k, out);
    }
  }

  /** כותב את התיקון לשכבה. מחזיר before להיסטוריה. */
  commitAdjust(doc) {
    const adj = doc._adjust;
    if (!adj) return new Map();
    const { layer } = adj;
    // כל האריחים מחושבים מהמקור — לכן קודם כולם, ורק אז מחליפים (טשטוש קורא שכנים)
    const results = new Map();
    for (const k of this.#adjustKeys(adj, layer, doc)) {
      const [tx, ty] = k.split(',').map(Number);
      const out = this.#tileTexture(1);
      this.#adjustTile(doc, adj, layer, tx, ty, layer.tiles.get(k), out);
      results.set(k, out);
    }
    doc._adjust = null;
    const before = new Map();
    for (const [k, out] of results) {
      before.set(k, layer.tiles.get(k) ?? null);
      layer.tiles.set(k, out);
    }
    gl_unbind(this.gl);
    layer.rev = (layer.rev ?? 0) + 1;
    this.invalidate(doc);
    return before;
  }

  // ---------- חיתוך: הזזה של כל האריחים בפיקסלים שלמים ----------

  /**
   * עותק של מפת אריחים, מוזז ב-(dx, dy) פיקסלים שלמים — לחיתוך הקנבס. לא נוגע במקור (הוא להיסטוריה).
   * כל אריח יעד מורכב מעד ארבעה אריחי מקור, בדגימה NEAREST — מדויק פיקסל-פיקסל.
   */
  shiftTiles(tiles, dx, dy) {
    const gl = this.gl;
    const dest = new Map();
    for (const k of tiles.keys()) {
      const [tx, ty] = k.split(',').map(Number);
      for (const [ux, uy] of tilesInRect(tx * TILE + dx, ty * TILE + dy, TILE, TILE)) {
        const dk = key(ux, uy);
        if (!dest.has(dk)) dest.set(dk, []);
        dest.get(dk).push([tx, ty, tiles.get(k)]);
      }
    }
    const out = new Map();
    const p = this.prog.copy;
    for (const [dk, srcs] of dest) {
      const [ux, uy] = dk.split(',').map(Number);
      const t = this.#tileTexture(1);
      this.#target(t);
      gl.useProgram(p.p);
      gl.bindVertexArray(this.vao);
      gl.disable(gl.BLEND);
      gl.activeTexture(gl.TEXTURE0);
      gl.uniform1i(p.u.u_tex, 0);
      for (const [tx, ty, src] of srcs) {
        const ox = tx * TILE + dx - ux * TILE, oy = ty * TILE + dy - uy * TILE;
        gl.bindTexture(gl.TEXTURE_2D, src);
        this.#quad(p, [ox, oy, ox + TILE, oy + TILE], [0, 0, 1, 1], [TILE, TILE], -1);
      }
      out.set(dk, t);
    }
    gl_unbind(gl);
    return out;
  }

  /** גודל הקנבס השתנה — העותק המורכב נבנה מחדש מאפס. */
  resetComposite(doc) {
    if (doc._comp) this.freeTextures(doc._comp.values());
    doc._comp = new Map();
    doc._dirty = new Set();
    this.selectionChanged(doc);
  }

  // ---------- תוכן צף: הזזה, קנה מידה, סיבוב ----------
  //
  // beginFloat מחלץ את התוכן (הבחירה, או כל השכבה) למרקם אחד עם mipmaps ומוחק אותו מהשכבה — האריחים המקוריים
  // נשמרים ב-before. בזמן שינוי הצורה ההרכבה מציגה שכבה + תוכן צף. commitFloat כותב אותו לאריחים חדשים, ו-before
  // (המקוריים, בלי העתקה) הולך להיסטוריה. cancelFloat מחזיר את המקוריים.

  /** גבולות התוכן של שכבה (בלי בחירה): Promise ל-[x, y, w, h] מדויק לפי האלפא, או null לשכבה ריקה. */
  async layerBounds(layer) {
    if (!layer.tiles.size) return null;
    let tx0 = Infinity, ty0 = Infinity, tx1 = -Infinity, ty1 = -Infinity;
    for (const k of layer.tiles.keys()) {
      const [tx, ty] = k.split(',').map(Number);
      tx0 = Math.min(tx0, tx); ty0 = Math.min(ty0, ty); tx1 = Math.max(tx1, tx); ty1 = Math.max(ty1, ty);
    }
    const X = tx0 * TILE, Y = ty0 * TILE, W = (tx1 - tx0 + 1) * TILE, H = (ty1 - ty0 + 1) * TILE;
    const px = this.#readLayerSync(layer, X, Y, W, H);
    let x0 = W, y0 = H, x1 = -1, y1 = -1;
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      if (px[(y * W + x) * 4 + 3]) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    }
    return x1 < 0 ? null : [X + x0, Y + y0, x1 - x0 + 1, y1 - y0 + 1];
  }

  /**
   * מתחיל תוכן צף בשכבה: rect = [x, y, w, h] במסמך. עם doc.selection — רק מה שבתוכה.
   * מחזיר את התוכן הצף, או זורק 'too_big' כשהמלבן גדול ממרקם אחד.
   */
  beginFloat(doc, layer, [x, y, w, h], t) {
    const gl = this.gl;
    if (w > this.maxTexture || h > this.maxTexture) throw new Error('too_big');
    const levels = Math.floor(Math.log2(Math.max(w, h))) + 1;
    const tex = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.texStorage2D(gl.TEXTURE_2D, levels, gl.RGBA16F, w, h);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    this.textures++;

    // חילוץ: כל אריח של השכבה שבמלבן → למקומו במרקם הצף (כפול הבחירה)
    const useSel = !!doc.selection;
    const sels = new Map();
    if (useSel) for (const [tx, ty] of tilesInRect(x, y, w, h)) sels.set(key(tx, ty), this.#selTex(doc, key(tx, ty)));
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, tex, 0);
    gl.viewport(0, 0, w, h);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    const p = this.prog.extract;
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.uniform1i(p.u.u_tex, 0);
    gl.uniform1i(p.u.u_sel, 1);
    gl.uniform1i(p.u.u_useSel, useSel ? 1 : 0);
    for (const [tx, ty] of tilesInRect(x, y, w, h)) {
      const lt = layer.tiles.get(key(tx, ty));
      if (!lt) continue;
      gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, lt);
      gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, useSel ? sels.get(key(tx, ty)) : this.fullMask);
      const ox = tx * TILE - x, oy = ty * TILE - y;
      this.#quad(p, [ox, oy, ox + TILE, oy + TILE], [0, 0, 1, 1], [w, h], -1);
    }
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.generateMipmap(gl.TEXTURE_2D);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);

    // מחיקה מהשכבה: עם בחירה — רק מה שבתוכה; בלי — כל האריחים עוברים ל-before כמו שהם
    let before;
    if (useSel) before = this.fillSelection(doc, layer, 'erase');
    else { before = new Map(layer.tiles); layer.tiles.clear(); layer.rev = (layer.rev ?? 0) + 1; }
    doc._float = { layer, tex, x, y, w, h, t, before };
    this.invalidateTiles(doc, [...before.keys()]);
    this.#dirty(doc, x, y, w, h);
    return doc._float;
  }

  #floatTouches(fl, tx, ty) {
    const [x0, y0, x1, y1] = flBounds(fl);
    return tx * TILE < x1 + 1 && (tx + 1) * TILE > x0 - 1 && ty * TILE < y1 + 1 && (ty + 1) * TILE > y0 - 1;
  }

  /** בסיס (אריח) + התוכן הצף בשינוי הצורה הנוכחי → out */
  #floatInto(out, base, fl, tx, ty) {
    const gl = this.gl;
    this.#target(out);
    const c = this.prog.copy;
    gl.useProgram(c.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, base);
    gl.uniform1i(c.u.u_tex, 0);
    this.#quad(c, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
    if (fl.mesh) {
      // עיוות: תא אחר תא (רק מה שנוגע באריח), כל תא — מרובע מארבע הפינות שלו
      const q = this.prog.mesh;
      gl.useProgram(q.p);
      gl.bindTexture(gl.TEXTURE_2D, fl.tex);
      gl.uniform1i(q.u.u_tex, 0);
      gl.uniform2f(q.u.u_org, tx * TILE, ty * TILE);
      gl.uniform2f(q.u.u_size, TILE, TILE);
      gl.enable(gl.BLEND);
      gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
      const X0 = tx * TILE - 1, Y0 = ty * TILE - 1, X1 = X0 + TILE + 2, Y1 = Y0 + TILE + 2;
      for (const c of meshCells(fl.mesh)) {
        const xs = c.p.map(v => v[0]), ys = c.p.map(v => v[1]);
        if (Math.max(...xs) < X0 || Math.min(...xs) > X1 || Math.max(...ys) < Y0 || Math.min(...ys) > Y1) continue;
        gl.uniform4f(q.u.u_p01, c.p[0][0], c.p[0][1], c.p[1][0], c.p[1][1]);
        gl.uniform4f(q.u.u_p23, c.p[2][0], c.p[2][1], c.p[3][0], c.p[3][1]);
        gl.uniform4f(q.u.u_uv, ...c.uv);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
      }
      gl.disable(gl.BLEND);
      return;
    }
    const p = this.prog.float;
    gl.useProgram(p.p);
    gl.bindTexture(gl.TEXTURE_2D, fl.tex);
    gl.uniform1i(p.u.u_tex, 0);
    const m = xfMatrix(fl.t, fl.w, fl.h);
    gl.uniform2f(p.u.u_wh, fl.w, fl.h);
    gl.uniform4f(p.u.u_m0, m[0], m[1], m[2], m[3]);
    gl.uniform2f(p.u.u_m1, m[4], m[5]);
    gl.uniform2f(p.u.u_org, tx * TILE, ty * TILE);
    gl.uniform2f(p.u.u_size, TILE, TILE);
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);   // source-over, מוכפל
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
    gl.disable(gl.BLEND);
  }

  /** שינוי צורה חדש לתוכן הצף (מתעדכן בתצוגה) */
  setFloatTransform(doc, t) {
    const fl = doc._float;
    if (!fl) return;
    const a = flBounds(fl);
    fl.t = t;
    const b = flBounds(fl);
    for (const r of [a, b]) this.#dirty(doc, r[0] - 1, r[1] - 1, r[2] - r[0] + 2, r[3] - r[1] + 2);
  }

  /** עיוות של התוכן הצף: רשת (engine/warp.js) — או null (חזרה לשינוי הצורה האפיני) */
  setFloatMesh(doc, mesh) {
    const fl = doc._float;
    if (!fl) return;
    const a = flBounds(fl);
    fl.mesh = mesh;
    const b = flBounds(fl);
    for (const r of [a, b]) this.#dirty(doc, r[0] - 1, r[1] - 1, r[2] - r[0] + 2, r[3] - r[1] + 2);
  }

  /** כותב את התוכן הצף לשכבה. מחזיר before להיסטוריה (המקוריים של כל אריח שהשתנה). */
  commitFloat(doc) {
    const fl = doc._float;
    if (!fl) return new Map();
    doc._float = null;
    const { layer, before } = fl;
    const [x0, y0, x1, y1] = flBounds(fl);
    for (const [tx, ty] of tilesInRect(x0 - 1, y0 - 1, x1 - x0 + 2, y1 - y0 + 2)) {
      const k = key(tx, ty);
      const cur = layer.tiles.get(k) ?? null;
      const out = this.#tileTexture(1);
      this.#floatInto(out, cur || this.emptyTex, fl, tx, ty);
      layer.tiles.set(k, out);
      // אריח שכבר ב-before — הנוכחי הוא שלב ביניים (אחרי המחיקה), ולא שייך לאף אחד
      if (before.has(k)) { if (cur) this.freeTextures([cur]); }
      else before.set(k, cur);
    }
    this.freeTextures([fl.tex]);
    gl_unbind(this.gl);
    layer.rev = (layer.rev ?? 0) + 1;
    this.invalidateTiles(doc, [...before.keys()]);
    return before;
  }

  /** מבטל את התוכן הצף — השכבה חוזרת בדיוק למה שהייתה. */
  cancelFloat(doc) {
    const fl = doc._float;
    if (!fl) return;
    doc._float = null;
    for (const [k, orig] of fl.before) {
      const cur = fl.layer.tiles.get(k);
      if (cur) this.freeTextures([cur]);
      if (orig) fl.layer.tiles.set(k, orig); else fl.layer.tiles.delete(k);
    }
    this.freeTextures([fl.tex]);
    fl.layer.rev = (fl.layer.rev ?? 0) + 1;
    this.invalidateTiles(doc, [...fl.before.keys()]);
    const [x0, y0, x1, y1] = flBounds(fl);
    this.#dirty(doc, x0 - 1, y0 - 1, x1 - x0 + 2, y1 - y0 + 2);
  }

  // ---------- הרכבה ----------

  #composite(doc, tx, ty) {
    const gl = this.gl;
    doc._comp ??= new Map();
    let out = doc._comp.get(key(tx, ty));
    if (!out) { out = this.#tileTexture(MIP_LEVELS); doc._comp.set(key(tx, ty), out); }
    const a = this.#compositeList(doc, doc.layers, tx, ty, 0);

    // התוצאה → העותק המורכב (רמה 0), ואז mipmaps
    gl.bindFramebuffer(gl.READ_FRAMEBUFFER, this.fboRead);
    gl.framebufferTexture2D(gl.READ_FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, a, 0);
    gl.bindFramebuffer(gl.DRAW_FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.DRAW_FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, out, 0);
    gl.blitFramebuffer(0, 0, TILE, TILE, 0, 0, TILE, TILE, gl.COLOR_BUFFER_BIT, gl.NEAREST);
    gl.bindFramebuffer(gl.READ_FRAMEBUFFER, null);
    gl.bindTexture(gl.TEXTURE_2D, out);
    gl.generateMipmap(gl.TEXTURE_2D);
    doc._dirty?.delete(key(tx, ty));
    return out;
  }

  /** זוג מרקמי עבודה לכל עומק — קבוצה בתוך קבוצה לא דורסת את מה שההורה שלה צבר */
  #scratchAt(depth) {
    this.scratchStack ??= [this.scratch];
    return (this.scratchStack[depth] ??= [this.#tileTexture(1), this.#tileTexture(1)]);
  }

  /** האריח של שכבת רסטר, אחרי משיכה / תוכן צף / תיקון / הזזת קבוצה פתוחים — או undefined */
  #layerTileFor(doc, layer, tx, ty) {
    let t = layer.tiles.get(key(tx, ty));
    const sh = doc._shift;
    if (sh && sh.layers.has(layer)) t = this.#shiftedTile(layer, tx, ty, sh.dx, sh.dy, this.shiftTmp);
    // משיכה פתוחה על השכבה הזו — מציגים את התוצאה בלי לגעת בשכבה עד שהאצבע מורמת
    const st = doc._stroke;
    const m = st && st.layer === layer ? st.mask.get(key(tx, ty)) : null;
    if (m) { this.#applyStroke(st, t || this.emptyTex, m, this.strokeTmp, key(tx, ty)); t = this.strokeTmp; }
    const fl = doc._float;
    if (fl && fl.layer === layer && this.#floatTouches(fl, tx, ty)) { this.#floatInto(this.floatTmp, t || this.emptyTex, fl, tx, ty); t = this.floatTmp; }
    const adj = doc._adjust;
    if (adj && adj.layer === layer && this.#adjustTouches(adj, layer, tx, ty)) { this.#adjustTile(doc, adj, layer, tx, ty, t, this.adjTmp); t = this.adjTmp; }
    return t;
  }

  /**
   * מרכיב רשימה (המסמך או קבוצה) לאריח אחד. קבוצה מורכבת קודם לבד, ורק אז נממזגת פנימה
   * עם האטימות ומצב המיזוג שלה (קבוצה "מבודדת").
   */
  #compositeList(doc, list, tx, ty, depth) {
    const gl = this.gl;
    let [a, b] = this.#scratchAt(depth);
    this.#target(a);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    const p = this.prog.blend;
    for (const layer of list) {
      if (!layer.visible || layer.opacity <= 0) continue;
      // שכבת התאמה: משנה את מה שכבר הורכב מתחתיה (ברשימה הזו), לפי האטימות והמסכה — בלי פיקסלים משלה
      if (layer.adjust) {
        const mk = layer.mask?.enabled ? (this.#layerTileFor(doc, layer.mask, tx, ty) || this.emptyTex) : null;
        this.#colorAdjust(adjustOf(layer), a, this.adjLayerTmp);
        this.#lerpInto(b, a, this.adjLayerTmp, layer.opacity, mk, this.#vmTex(doc, layer, tx, ty));
        [a, b] = [b, a];
        continue;
      }
      // שכבת מילוי: התוכן — המילוי עצמו (אין פיקסלים שמורים)
      const t = layer.type === 'group'
        ? (layer.children.length ? this.#compositeList(doc, layer.children, tx, ty, depth + 1) : null)
        : layer.fill ? this.#fillTile(layer.fill, tx, ty, this.fillTmp) : this.#layerTileFor(doc, layer, tx, ty);
      // סגנונות שכבה: השכבה נבנית לבד עם האפקטים (והמסכה), ואז נממזגת — גם אריח ריק, אם יש תוכן בטווח
      const fxs = layer.type === 'group' ? [] : effectsOf(layer);
      if (fxs.length) {
        const l = this.#effectsTile(doc, layer, fxs, tx, ty, t);
        if (l) { this.#blendInto(b, a, l, layer.opacity, Math.max(0, BLEND_MODES.indexOf(layer.blend))); [a, b] = [b, a]; }
        continue;
      }
      if (!t) continue;
      // מסכה: גם היא עוברת דרך #layerTileFor — משיכה פתוחה על המסכה מוצגת חיה. אריח חסר = מוסתר
      const mk = layer.mask?.enabled ? (this.#layerTileFor(doc, layer.mask, tx, ty) || this.emptyTex) : null;
      this.#blendInto(b, a, t, layer.opacity, Math.max(0, BLEND_MODES.indexOf(layer.blend)), mk, this.#vmTex(doc, layer, tx, ty));
      [a, b] = [b, a];
    }
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE0);
    return a;
  }

  // ---------- סגנונות שכבה ----------

  /** מרקמי העבודה של האפקטים (בשימוש הראשון): ריפוד התוכן והמסכה, הבסיס, הטשטוש, שכנים ותוצאות ביניים */
  #fx() {
    if (this.fxTex) return this.fxTex;
    const gl = this.gl;
    const lin = t => {
      gl.bindTexture(gl.TEXTURE_2D, t);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
      return t;
    };
    const big = () => this.#floatTex(FX_N, FX_N);
    this.fxTex = {
      c: big(), m: big(), v: big(), s: lin(big()), h: big(), b: lin(big()),
      near: Array.from({ length: 9 }, () => this.#floatTex(TILE, TILE)),
      out: Array.from({ length: 4 }, () => this.#floatTex(TILE, TILE)),
    };
    return this.fxTex;
  }

  /** ציור אחד בגודל w×h אל target; tex — { שם uniform: מרקם }; set(u) — שאר ה-uniforms */
  #run(name, target, w, h, tex, set) {
    const gl = this.gl, p = this.prog[name];
    gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, target, 0);
    gl.viewport(0, 0, w, h);
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    Object.entries(tex).forEach(([u, t], i) => {
      gl.activeTexture(gl.TEXTURE0 + i);
      gl.bindTexture(gl.TEXTURE_2D, t);
      gl.uniform1i(p.u[u], i);
    });
    set(p.u);
    this.#quad(p, [0, 0, w, h], [0, 0, 1, 1], [w, h], -1);
  }

  /**
   * תשעת האריחים סביב (tx, ty), לפי (dy + 1) · 3 + dx + 1: המרכז — center (התצוגה החיה); שכן — מהשכבה, מוזז בהזזת
   * קבוצה, ובשכבת מילוי — נוצר. משיכה / תוכן צף פתוחים על אריח שכן — מגיעים לאפקט בסיומם.
   */
  #fxNear(doc, layer, tx, ty, center, fill) {
    const fx = this.#fx(), sh = doc._shift, out = [];
    for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
      const i = (dy + 1) * 3 + dx + 1;
      if (!dx && !dy) out[i] = center;
      else if (fill) out[i] = this.#fillTile(fill, tx + dx, ty + dy, fx.near[i]);
      else if (sh && sh.layers.has(layer)) out[i] = this.#shiftedTile(layer, tx + dx, ty + dy, sh.dx, sh.dy, fx.near[i]);
      else out[i] = layer.tiles.get(key(tx + dx, ty + dy));
    }
    return out;
  }

  /**
   * השכבה עם סגנונות השכבה שלה, לאריח אחד: מבודדת (בלי אטימות ומצב מיזוג), וכבר כפול המסכה. null — אין תוכן בטווח.
   * הסדר: צל וזוהר (מתחת) ← התוכן, ועליו צבע, זוהר פנימי וצל פנימי (בגבולות האלפא שלו) ← קו מתאר מעל הכול.
   */
  #effectsTile(doc, layer, fxs, tx, ty, t) {
    const gl = this.gl, fx = this.#fx();
    const K = Math.min(FX_MAX, Math.max(8, Math.ceil(effectsReach(fxs) / 8) * 8)), P = TILE + 2 * K;
    const near = this.#fxNear(doc, layer, tx, ty, t, layer.fill);
    if (!near.some(Boolean)) return null;
    this.#pad((dx, dy) => near[(dy + 1) * 3 + dx + 1], K, fx.c);
    // המסכה: גם שלה התצוגה החיה במרכז. אריח חסר במסכה = מוסתר (הריפוד מתחיל שקוף)
    const useMask = !!layer.mask?.enabled;
    if (useMask) {
      const mn = this.#fxNear(doc, layer.mask, tx, ty, this.#layerTileFor(doc, layer.mask, tx, ty), null);
      this.#pad((dx, dy) => mn[(dy + 1) * 3 + dx + 1], K, fx.m);
    }
    // המסכה הווקטורית — גם היא מרופדת (מהמטמון; אריח מחוץ לנתיב — מסכת האפס)
    const useVm = !!layer.vectorMask && layer.vectorMask.enabled !== false;
    if (useVm) {
      const vt = [];
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) vt.push(this.#vmTex(doc, layer, tx + dx, ty + dy));
      this.#pad((dx, dy) => vt[(dy + 1) * 3 + dx + 1], K, fx.v);
    }
    this.#run('fxsrc', fx.s, P, P, { u_c: fx.c, u_m: fx.m, u_v: fx.v }, u => {
      gl.uniform1i(u.u_useMask, useMask ? 1 : 0);
      gl.uniform1i(u.u_useVm, useVm ? 1 : 0);
    });

    const blurA = (e, inv) => {
      const r = Math.round(e.blur ?? 0), sigma = Math.max(0.5, r / 2);   // נראה כמעט עד הרדיוס, כמו "גודל" בפוטושופ
      const pass = (src, out, dir, flip) => this.#run('fxblur', out, P, P, { u_tex: src }, u => {
        gl.uniform1i(u.u_r, r); gl.uniform1i(u.u_inv, flip); gl.uniform1i(u.u_n, P);
        gl.uniform2i(u.u_dir, dir[0], dir[1]); gl.uniform1f(u.u_sigma, sigma);
      });
      pass(fx.s, fx.h, [1, 0], inv ? 1 : 0);   // ההיפוך (פנימי) — במעבר הראשון בלבד
      pass(fx.h, fx.b, [0, 1], 0);
    };
    const step = (mode, base, out, e = null, top = this.emptyTex) => this.#run('fx', out, TILE, TILE,
      { u_src: fx.s, u_blur: fx.b, u_base: base, u_top: top }, u => {
        gl.uniform1i(u.u_mode, mode); gl.uniform1i(u.u_k, K);
        gl.uniform1i(u.u_pos, Math.max(0, FX_POS.indexOf(e?.position)));
        gl.uniform4fv(u.u_col, e ? [...hexLinear(e.color), e.opacity] : [0, 0, 0, 0]);
        gl.uniform4fv(u.u_geo, [e?.dx ?? 0, e?.dy ?? 0, e?.spread ?? 0, e?.width ?? 0]);
        gl.uniform1f(u.u_n, FX_N);
      });
    const [o0, o1, o2, o3] = fx.out;
    const other = (pool, cur) => pool.find(x => x !== cur);
    let below = this.emptyTex;
    for (const e of fxs.filter(e => e.type === 'shadow' || e.type === 'glow')) {
      blurA(e, false);
      const o = other([o0, o1], below); step(2, below, o, e); below = o;
    }
    let c = o2;
    step(0, this.emptyTex, c);
    for (const e of fxs.filter(e => e.type === 'overlay' || e.type === 'inner-glow' || e.type === 'inner-shadow')) {
      if (e.type !== 'overlay') blurA(e, true);
      const o = other([o2, o3], c); step(e.type === 'overlay' ? 4 : 3, c, o, e); c = o;
    }
    let l = other([o0, o1], below);
    step(1, below, l, null, c);
    for (const e of fxs.filter(e => e.type === 'stroke')) {
      const o = other([o0, o1, o2, o3], l); step(5, l, o, e); l = o;
    }
    for (let i = 0; i < 4; i++) { gl.activeTexture(gl.TEXTURE0 + i); gl.bindTexture(gl.TEXTURE_2D, null); }
    gl.activeTexture(gl.TEXTURE0);
    return l;
  }

  /** השכבה עם הסגנונות שלה (כפול המסכה, בלי אטימות) — ה-src האפוי ב-‎.mbe. Promise ל-RGBA ישר של 8 ביט */
  async readEffectsLayerPixels(doc, layer, x, y, w, h) {
    const fxs = effectsOf(layer);
    return this.#read((tx, ty) => {
      const t = layer.fill ? this.#fillTile(layer.fill, tx, ty, this.fillTmp) : layer.tiles.get(key(tx, ty));
      return this.#effectsTile(doc, layer, fxs, tx, ty, t) ?? this.emptyTex;
    }, x, y, w, h);
  }

  /** תיקון צבע לאריח שלם, בלי בחירה (שכבת התאמה) */
  #colorAdjust(adj, base, out) {
    const gl = this.gl;
    const a = this.prog.adjust;
    this.#target(out);
    gl.useProgram(a.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, base);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, this.fullMask);
    gl.uniform1i(a.u.u_tex, 0); gl.uniform1i(a.u.u_sel, 1);
    gl.uniform1i(a.u.u_kind, WebGL2Renderer.adjustKind(adj));
    gl.uniform1i(a.u.u_useSel, 0);
    gl.uniform4fv(a.u.u_p, WebGL2Renderer.adjustParams(adj));
    this.#quad(a, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
  }

  /** טקסטורת ה-ramp של מילוי (במטמון לפי התוכן — מילוי שלא השתנה לא מועלה שוב) */
  #rampTex(fill) {
    const id = JSON.stringify(fill);
    this.ramps ??= new Map();
    let t = this.ramps.get(id);
    if (t) return t;
    const gl = this.gl;
    if (this.ramps.size > 32) { for (const old of this.ramps.values()) gl.deleteTexture(old); this.ramps.clear(); }
    t = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, t);
    gl.texStorage2D(gl.TEXTURE_2D, 1, gl.RGBA8, 256, 1);
    gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);
    gl.texSubImage2D(gl.TEXTURE_2D, 0, 0, 0, 256, 1, gl.RGBA, gl.UNSIGNED_BYTE, fillRamp(fill));
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    this.ramps.set(id, t);
    return t;
  }

  /** אריח של מילוי (צבע / מעבר) במקום (tx, ty) */
  #fillTile(fill, tx, ty, out) {
    const gl = this.gl;
    const ramp = this.#rampTex(fill);
    const { mode, geo } = fillGeometry(fill);
    const p = this.prog.fillgen;
    this.#target(out);
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, ramp);
    gl.uniform1i(p.u.u_ramp, 0);
    gl.uniform1i(p.u.u_mode, mode);
    gl.uniform4fv(p.u.u_geo, geo);
    gl.uniform2f(p.u.u_origin, tx * TILE, ty * TILE);
    this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
    return out;
  }

  /** out = mix(a, b, k × מסכה) */
  #lerpInto(out, a, b, k, mask = null, vm = null) {
    const gl = this.gl;
    const p = this.prog.lerp;
    this.#target(out);
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.uniform1i(p.u.u_a, 0); gl.uniform1i(p.u.u_b, 1); gl.uniform1i(p.u.u_msk, 2); gl.uniform1i(p.u.u_vm, 3);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, a);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, b);
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, mask || this.emptyTex);
    gl.activeTexture(gl.TEXTURE3); gl.bindTexture(gl.TEXTURE_2D, vm || this.fullMask);
    gl.uniform1f(p.u.u_k, k);
    gl.uniform1i(p.u.u_useMask, mask ? 1 : 0);
    gl.uniform1i(p.u.u_useVm, vm ? 1 : 0);
    this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
  }

  /**
   * המראה של שכבת התאמה במקומה (ה-src שלה ב-‎.mbe): מה שמתחתיה ברשימה שלה, אחרי ההתאמה, כפול המסכה; שקוף מחוצה לה.
   * קורא שלא מכיר את adjust מצייר את זה מעל, באטימות של השכבה — ורואה (כמעט) את אותה תמונה.
   */
  #adjustLayerTile(doc, layer, tx, ty, out) {
    const list = doc.listOf(layer);
    const below = this.#compositeList(doc, list.slice(0, list.indexOf(layer)), tx, ty, 8);
    this.#colorAdjust(adjustOf(layer), below, this.adjLayerTmp);
    const mk = layer.mask?.enabled ? (layer.mask.tiles.get(key(tx, ty)) ?? this.emptyTex) : null;
    this.#blendInto(out, this.emptyTex, this.adjLayerTmp, 1, 0, mk, this.#vmTex(doc, layer, tx, ty));
    return out;
  }

  /** ה-src של שכבת התאמה (ראו #adjustLayerTile) — Promise ל-RGBA ישר של 8 ביט */
  async readAdjustLayerPixels(doc, layer, x, y, w, h) {
    return this.#read((tx, ty) => this.#adjustLayerTile(doc, layer, tx, ty, this.maskTmp), x, y, w, h);
  }

  /** out = dst ⊕ src (אטימות, מצב מיזוג, ומסכה אם יש) */
  #blendInto(out, dst, src, opacity, mode, mask = null, vm = null) {
    const gl = this.gl;
    const p = this.prog.blend;
    this.#target(out);
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.uniform1i(p.u.u_dst, 0);
    gl.uniform1i(p.u.u_src, 1);
    gl.uniform1i(p.u.u_msk, 2);
    gl.uniform1i(p.u.u_vm, 3);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, dst);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, src);
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, mask || this.emptyTex);
    gl.activeTexture(gl.TEXTURE3); gl.bindTexture(gl.TEXTURE_2D, vm || this.fullMask);
    gl.uniform1f(p.u.u_opacity, opacity);
    gl.uniform1i(p.u.u_mode, mode);
    gl.uniform1i(p.u.u_useMask, mask ? 1 : 0);
    gl.uniform1i(p.u.u_useVm, vm ? 1 : 0);
    this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
  }

  /** אריח השכבה כפול המסכה שלה (בלי אטימות ומיזוג) — לייצוא, ולהחלת המסכה */
  /** look — המראה (מסכה רק אם פעילה, ועם המסכה הווקטורית); בלי — להחלת המסכה */
  #maskedTile(layer, tx, ty, out, look = false) {
    const t = layer.tiles.get(key(tx, ty)) ?? this.emptyTex;
    const mk = layer.mask && (!look || layer.mask.enabled) ? (layer.mask.tiles.get(key(tx, ty)) ?? this.emptyTex) : null;
    this.#blendInto(out, this.emptyTex, t, 1, 0, mk, look ? this.#vmTex(null, layer, tx, ty) : null);
    return out;
  }

  /**
   * מחיל את המסכה על הפיקסלים (השכבה כפול המסכה) — אריחים חדשים, והישנים ל-before (להיסטוריה).
   * את המסכה עצמה מסיר מי שקרא (history.mask), כך ששניהם צעד ביטול אחד.
   */
  applyMask(doc, layer) {
    const before = new Map();
    for (const k of [...layer.tiles.keys()]) {
      const [tx, ty] = k.split(',').map(Number);
      const out = this.#tileTexture(1);
      this.#maskedTile(layer, tx, ty, out);
      before.set(k, layer.tiles.get(k));
      layer.tiles.set(k, out);
    }
    gl_unbind(this.gl);
    layer.rev = (layer.rev ?? 0) + 1;
    this.invalidate(doc);
    return before;
  }

  /** אריח של שכבה כשהיא מוזזת ב-(dx, dy) שלמים — לתצוגה בזמן הזזת קבוצה (עד ארבעה אריחי מקור) */
  #shiftedTile(layer, tx, ty, dx, dy, out) {
    const gl = this.gl;
    const x = tx * TILE - dx, y = ty * TILE - dy;   // המקור שמגיע לאריח הזה
    const srcs = tilesInRect(x, y, TILE, TILE).map(([sx, sy]) => [sx, sy, layer.tiles.get(key(sx, sy))]).filter(s => s[2]);
    if (!srcs.length) return undefined;
    this.#target(out);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    const c = this.prog.copy;
    gl.useProgram(c.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0);
    gl.uniform1i(c.u.u_tex, 0);
    for (const [sx, sy, t] of srcs) {
      const ox = sx * TILE + dx - tx * TILE, oy = sy * TILE + dy - ty * TILE;
      gl.bindTexture(gl.TEXTURE_2D, t);
      this.#quad(c, [ox, oy, ox + TILE, oy + TILE], [0, 0, 1, 1], [TILE, TILE], -1);
    }
    return out;
  }

  /** הזזת קבוצה בזמן גרירה: layers — כל שכבות הרסטר שבה (או null לסיום) */
  setShift(doc, layers, dx = 0, dy = 0) {
    const old = doc._shift;
    // המסכות זזות עם השכבות שלהן
    doc._shift = layers ? { layers: new Set(layers.flatMap(l => (l.mask ? [l, l.mask] : [l]))), dx, dy } : null;
    if (old || layers) this.invalidate(doc);
  }

  #compositeTile(doc, tx, ty) {
    const k = key(tx, ty);
    const t = doc._comp?.get(k);
    return t && !doc._dirty?.has(k) ? t : this.#composite(doc, tx, ty);
  }

  // ---------- תצוגה ----------

  /**
   * מצייר את המסמך לקנבס. view = { zoom, x, y } ב-CSS px; dpr — יחס הפיקסלים של המסך.
   * רק האריחים שעל המסך מורכבים (ומעודכנים) — זה מה שמאפשר מסמך ענק.
   */
  render(doc, view, dpr = 1) {
    if (this.lost) return;
    const gl = this.gl;
    const W = this.canvas.width, H = this.canvas.height;
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    gl.viewport(0, 0, W, H);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    if (!doc) return;

    const z = view.zoom * dpr;
    const ox = Math.round(view.x * dpr), oy = Math.round(view.y * dpr);
    // האריחים שבתוך המסך
    const dx0 = Math.max(0, -ox / z), dy0 = Math.max(0, -oy / z);
    const dx1 = Math.min(doc.width, (W - ox) / z), dy1 = Math.min(doc.height, (H - oy) / z);
    if (dx1 <= dx0 || dy1 <= dy0) return;
    const tiles = tilesInRect(Math.floor(dx0), Math.floor(dy0), Math.ceil(dx1) - Math.floor(dx0), Math.ceil(dy1) - Math.floor(dy0));

    const comp = tiles.map(([tx, ty]) => [tx, ty, this.#compositeTile(doc, tx, ty)]);

    const p = this.prog.display;
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    gl.viewport(0, 0, W, H);
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0);
    gl.uniform1i(p.u.u_tex, 0);
    gl.uniform1f(p.u.u_check, Math.max(1, Math.round(8 * dpr)));
    for (const [tx, ty, tex] of comp) {
      const x0 = tx * TILE, y0 = ty * TILE;
      const x1 = Math.min(doc.width, x0 + TILE), y1 = Math.min(doc.height, y0 + TILE);
      gl.bindTexture(gl.TEXTURE_2D, tex);
      this.#quad(p, [ox + x0 * z, oy + y0 * z, ox + x1 * z, oy + y1 * z],
        [0, 0, (x1 - x0) / TILE, (y1 - y0) / TILE], [W, H], 1);
    }
  }

  // ---------- קריאה החוצה ----------
  // הקריאות החוצה אסינכרוניות בממשק (Promise): ב-WebGPU קריאה מהכרטיס אסינכרונית מעצם טבעה, ושני המנועים
  // חולקים ממשק אחד. כאן היא מסתיימת מיד.

  /** הפיקסלים המורכבים של מלבן במסמך — Promise ל-sRGB ישר, RGBA של 8 ביט, שורה 0 למעלה. */
  async readPixels(doc, x, y, w, h) {
    return this.#readCompositeSync(doc, x, y, w, h);
  }

  /**
   * הפיקסלים של שכבה אחת (בלי אטימות ומיזוג) — לדלי. אריח חסר = שקוף.
   * masked — כפול המסכה של השכבה, אם יש ופעילה (לייצוא: ORA, ה-src של ‎.mbe).
   */
  async readLayerPixels(layer, x, y, w, h, masked = false) {
    if (layer.fill) {
      // שכבת מילוי: המילוי, ועם masked — כפול המסכה
      return this.#read((tx, ty) => {
        const f = this.#fillTile(layer.fill, tx, ty, this.fillTmp);
        const vm = masked ? this.#vmTex(null, layer, tx, ty) : null;
        if (!masked || (!layer.mask?.enabled && !vm)) return f;
        const mk = layer.mask?.enabled ? (layer.mask.tiles.get(key(tx, ty)) ?? this.emptyTex) : null;
        this.#blendInto(this.maskTmp, this.emptyTex, f, 1, 0, mk, vm);
        return this.maskTmp;
      }, x, y, w, h);
    }
    // המראה: כפול המסכה וכפול המסכה הווקטורית
    if (masked && (layer.mask?.enabled || (layer.vectorMask && layer.vectorMask.enabled !== false))) {
      return this.#read((tx, ty) => this.#maskedTile(layer, tx, ty, this.maskTmp, true), x, y, w, h);
    }
    return this.#readLayerSync(layer, x, y, w, h);
  }

  #readCompositeSync(doc, x, y, w, h) {
    return this.#read((tx, ty) => this.#compositeTile(doc, tx, ty), x, y, w, h);
  }

  #readLayerSync(layer, x, y, w, h) {
    return this.#read((tx, ty) => layer.tiles.get(key(tx, ty)) ?? this.emptyTex, x, y, w, h);
  }

  #read(source, x, y, w, h) {
    const gl = this.gl;
    const out = new Uint8ClampedArray(w * h * 4);
    const buf = new Uint8Array(TILE * TILE * 4);
    const p = this.prog.read;
    for (const [tx, ty] of tilesInRect(x, y, w, h)) {
      const tex = source(tx, ty);
      gl.bindFramebuffer(gl.FRAMEBUFFER, this.fbo);
      gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, this.readTex, 0);
      gl.viewport(0, 0, TILE, TILE);
      gl.useProgram(p.p);
      gl.bindVertexArray(this.vao);
      gl.disable(gl.BLEND);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, tex);
      gl.uniform1i(p.u.u_tex, 0);
      this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
      const ox = tx * TILE, oy = ty * TILE;
      const x0 = Math.max(x, ox), y0 = Math.max(y, oy);
      const x1 = Math.min(x + w, ox + TILE), y1 = Math.min(y + h, oy + TILE);
      const rw = x1 - x0, rh = y1 - y0;
      gl.readPixels(x0 - ox, y0 - oy, rw, rh, gl.RGBA, gl.UNSIGNED_BYTE, buf);
      for (let r = 0; r < rh; r++) {
        out.set(buf.subarray(r * rw * 4, (r + 1) * rw * 4), ((y0 - y + r) * w + (x0 - x)) * 4);
      }
    }
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    return out;
  }

  /** מזהה הכרטיס (לפי מה שהדפדפן מגלה) — מפתח למטמון המדידה */
  gpuKey() {
    const gl = this.gl;
    const dbg = gl.getExtension('WEBGL_debug_renderer_info');
    return this.name + ':' + (dbg ? gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER));
  }

  /**
   * מדידה קצרה: מסמך זמני של 1024×1024 עם 4 שכבות, הרכבה מלאה כמה פעמים. מחזיר ציון — אריחי-שכבה במילישנייה.
   * readPixels מחכה לסיום העבודה בכרטיס, כך שהזמן אמיתי. כ-50–300 מ"ש, פעם אחת למכשיר. מחזיר Promise.
   */
  async benchmark(DocClass) {
    const d = new DocClass(1024, 1024);
    for (let i = 0; i < 4; i++) this.fill(d, d.addLayer({ blend: ['normal', 'multiply', 'screen', 'overlay'][i], opacity: 0.8 }), [40 * i, 90, 200 - 30 * i, 200]);
    const ops = 16 * 4;
    this.invalidate(d);
    this.#readCompositeSync(d, 0, 0, 1024, 1024);   // חימום: הידור ה-shaders והקצאות
    let best = Infinity;
    for (let k = 0; k < 3; k++) {
      this.invalidate(d);
      const t0 = performance.now();
      this.#readCompositeSync(d, 0, 0, 1024, 1024);
      best = Math.min(best, performance.now() - t0);
    }
    for (const l of d.rasters()) this.freeLayer(l);
    if (d._comp) this.freeTextures(d._comp.values());
    return ops / Math.max(best, 0.01);
  }

  /** כמה מרקמים וכמה זיכרון GPU (בערך) — למדידה ולתקציב. */
  stats(doc) {
    const layerTiles = doc ? doc.rasters().reduce((n, l) => n + l.tiles.size, 0) : 0;
    const compTiles = doc?._comp?.size ?? 0;
    return {
      layerTiles, compTiles,
      bytes: layerTiles * TILE * TILE * 8 + Math.round(compTiles * TILE * TILE * 8 * 4 / 3),
    };
  }
}

/** פענוח קובץ תמונה — sRGB ישר (בלי הכפלת אלפא), כפי שהרנדרר מצפה. resize — [w, h] להקטנה. */
export function decodeImage(blob, resize) {
  const opts = { premultiplyAlpha: 'none', colorSpaceConversion: 'default', imageOrientation: 'from-image' };
  if (resize) Object.assign(opts, { resizeWidth: resize[0], resizeHeight: resize[1], resizeQuality: 'high' });
  return createImageBitmap(blob, opts);
}
