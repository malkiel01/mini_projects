// הרנדרר של WebGPU — אותו ממשק בדיוק כמו WebGL2Renderer (webgl2.js), ואותן נוסחאות. נבחר כשהדפדפן תומך;
// WebGL2 נשאר הגיבוי (ו-?renderer=webgl2 מכריח אותו).
//
// אותם עקרונות: אריחים של 256×256 ב-rgba16float, לינארי מוכפל; מיזוג ב-shader לפי W3C; sRGB רק בתצוגה ובקריאה.
// מה שונה ב-WebGPU:
// - הפקודות נאספות ב-encoder אחד ונשלחות בסוף המשימה (queueMicrotask) או לפני קריאה החוצה.
// - הערכים לכל ציור (uniforms) — טבעת אחת במאגר, עם offset דינמי; נכתבת בבת אחת לפני השליחה.
// - מרקם שמשתחרר בזמן שה-encoder פתוח נהרס רק אחרי השליחה (פקודה שכבר נרשמה עוד משתמשת בו).
// - קריאה החוצה: מרקם rgba8unorm → copyTextureToBuffer → mapAsync. לכן הקריאות אסינכרוניות.
// - mipmaps — אין generateMipmap: כל רמה מצוירת מהקודמת בדגימה לינארית (ממוצע 2×2).
//
// מוסכמה: שורה 0 של כל מרקם = הקצה העליון — כמו ב-WebGL2. ב-WebGPU גם היעד וגם המסך כך, בלי היפוך.

import { TILE, BLEND_MODES, tilesInRect, newMask } from './doc.js';
import { rasterTile, selectionBox } from './selection.js';
import { matrix as xfMatrix, bounds as xfBounds } from './transform.js';
import { WebGL2Renderer, srgbToLinear } from './webgl2.js';
import { adjustOf } from './formats.js';

const WGSL = /* wgsl */`
struct U {
  rect: vec4f,   // x0 y0 x1 y1 — בפיקסלים של היעד, y למטה
  uv: vec4f,     // u0 v0 u1 v1
  size: vec4f,   // רוחב וגובה היעד
  a: vec4f,
  b: vec4f,
  c: vec4f,
  m: vec4i,
}
@group(0) @binding(0) var<uniform> u: U;
@group(0) @binding(1) var smp: sampler;
@group(0) @binding(2) var t0: texture_2d<f32>;
@group(0) @binding(3) var t1: texture_2d<f32>;
@group(0) @binding(4) var t2: texture_2d<f32>;
@group(0) @binding(5) var t3: texture_2d<f32>;

struct VO { @builtin(position) pos: vec4f, @location(0) uv: vec2f }

fn corner(vi: u32) -> vec2f { return vec2f(f32(vi & 1u), f32(vi >> 1u)); }

@vertex fn vs(@builtin(vertex_index) vi: u32) -> VO {
  let a = corner(vi);
  let p = mix(u.rect.xy, u.rect.zw, a);
  var o: VO;
  o.uv = mix(u.uv.xy, u.uv.zw, a);
  o.pos = vec4f(p.x / u.size.x * 2.0 - 1.0, 1.0 - p.y / u.size.y * 2.0, 0.0, 1.0);
  return o;
}

// תוכן צף דרך מטריצה אפינית: a = גודל המרקם הצף, b = (a b c d), c.xy = (e f), c.zw = פינת האריח במסמך
@vertex fn vsM(@builtin(vertex_index) vi: u32) -> VO {
  let a = corner(vi);
  let p = a * u.a.xy;
  let d = vec2f(u.b.x * p.x + u.b.z * p.y + u.c.x, u.b.y * p.x + u.b.w * p.y + u.c.y) - u.c.zw;
  var o: VO;
  o.uv = a;
  o.pos = vec4f(d.x / u.size.x * 2.0 - 1.0, 1.0 - d.y / u.size.y * 2.0, 0.0, 1.0);
  return o;
}

// select ולא mix: pow(0, …) יכול להחזיר NaN, ו-mix היה מעביר אותו גם כשהענף לא נבחר
fn enc(l0: vec3f) -> vec3f {
  let l = clamp(l0, vec3f(0.0), vec3f(1.0));
  return select(l * 12.92, 1.055 * pow(max(l, vec3f(1e-9)), vec3f(1.0 / 2.4)) - 0.055, l > vec3f(0.0031308));
}
fn dec(s0: vec3f) -> vec3f {
  let s = clamp(s0, vec3f(0.0), vec3f(1.0));
  return select(s / 12.92, pow((s + 0.055) / 1.055, vec3f(2.4)), s > vec3f(0.04045));
}

// sRGB ישר (המרקם rgba8unorm-srgb מפענח בחומרה) → לינארי מוכפל
@fragment fn upload(i: VO) -> @location(0) vec4f {
  let c = textureSample(t0, smp, i.uv);
  return vec4f(c.rgb * c.a, c.a);
}

@fragment fn fill(i: VO) -> @location(0) vec4f { return u.a; }

fn screenB(b: vec3f, s: vec3f) -> vec3f { return b + s - b * s; }
// s ≤ 0.5 → multiply(b, 2s); אחרת screen(b, 2s − 1)
fn hardLight(b: vec3f, s: vec3f) -> vec3f { return mix(b * 2.0 * s, screenB(b, 2.0 * s - 1.0), step(vec3f(0.5), s)); }

// W3C Compositing: t0 = הרקע, t1 = השכבה, t2 = מסכת השכבה; a.x = אטימות, m.x = מצב, m.y = יש מסכה
// (הערוץ r, בגווני אפור של sRGB — 50% אפור = חצי שקוף)
@fragment fn blend(i: VO) -> @location(0) vec4f {
  let b = textureSample(t0, smp, i.uv);
  let mk = enc(vec3f(textureSample(t2, smp, i.uv).r)).r;
  var s = textureSample(t1, smp, i.uv) * u.a.x;
  if (u.m.y == 1) { s = s * mk; }
  let ab = b.a;
  let sa = s.a;
  var Cb = vec3f(0.0);
  if (ab > 0.0) { Cb = clamp(b.rgb / ab, vec3f(0.0), vec3f(1.0)); }
  var Cs = vec3f(0.0);
  if (sa > 0.0) { Cs = clamp(s.rgb / sa, vec3f(0.0), vec3f(1.0)); }
  var B = Cs;
  if (u.m.x == 1) { B = Cb * Cs; }
  else if (u.m.x == 2) { B = screenB(Cb, Cs); }
  else if (u.m.x == 3) { B = hardLight(Cs, Cb); }   // overlay = hard-light עם הסדר ההפוך
  return vec4f(s.rgb * (1.0 - ab) + b.rgb * (1.0 - sa) + sa * ab * B, sa + ab * (1.0 - sa));
}

// נקודת מכחול: uv = המרחק מהמרכז בפיקסלים; a = (רדיוס, קשיות, עוצמה)
@fragment fn dab(i: VO) -> @location(0) vec4f {
  let d = length(i.uv);
  let r = u.a.x;
  let inner = min(u.a.y * r, r - 1.0);
  var a = clamp((r - d) / max(r - inner, 1.0), 0.0, 1.0);
  a = a * a * (3.0 - 2.0 * a);
  return vec4f(a * u.a.z);
}

// t0 שכבה, t1 מסכת המשיכה, t2 בחירה, t3 מקור השכפול; a = צבע (לינארי מוכפל), b.x = אטימות,
// m = (מחיקה, בחירה, שכפול)
@fragment fn stroke(i: VO) -> @location(0) vec4f {
  let l = textureSample(t0, smp, i.uv);
  var m = clamp(textureSample(t1, smp, i.uv).r, 0.0, 1.0) * u.b.x;
  let s = textureSample(t2, smp, i.uv).r;
  let src = textureSample(t3, smp, i.uv);
  if (u.m.y == 1) { m = m * s; }
  if (u.m.x == 1) { return l * (1.0 - m); }
  return select(u.a, src, u.m.z == 1) * m + l * (1.0 - m);
}

fn rgb2hsl(c: vec3f) -> vec3f {
  let mx = max(c.r, max(c.g, c.b));
  let mn = min(c.r, min(c.g, c.b));
  let l = (mx + mn) / 2.0;
  let d = mx - mn;
  if (d < 1e-6) { return vec3f(0.0, 0.0, l); }
  var s = d / (mx + mn);
  if (l > 0.5) { s = d / (2.0 - mx - mn); }
  var h: f32;
  if (mx == c.r) { h = (c.g - c.b) / d + select(0.0, 6.0, c.g < c.b); }
  else if (mx == c.g) { h = (c.b - c.r) / d + 2.0; }
  else { h = (c.r - c.g) / d + 4.0; }
  return vec3f(h / 6.0, s, l);
}
fn hue(p: f32, q: f32, t0_: f32) -> f32 {
  let t = fract(t0_);
  if (t < 1.0 / 6.0) { return p + (q - p) * 6.0 * t; }
  if (t < 0.5) { return q; }
  if (t < 2.0 / 3.0) { return p + (q - p) * (2.0 / 3.0 - t) * 6.0; }
  return p;
}
fn hsl2rgb(h: vec3f) -> vec3f {
  if (h.y < 1e-6) { return vec3f(h.z); }
  var q = h.z + h.y - h.z * h.y;
  if (h.z < 0.5) { q = h.z * (1.0 + h.y); }
  let p = 2.0 * h.z - q;
  return vec3f(hue(p, q, h.x + 1.0 / 3.0), hue(p, q, h.x), hue(p, q, h.x - 1.0 / 3.0));
}

// תיקוני צבע ב-sRGB: t0 אריח, t1 בחירה; m = (סוג: 1 בהירות/ניגודיות, 2 גוון/רוויה/בהירות; בחירה), a = פרמטרים
// רעש לפי מיקום הפיקסל במסמך (כמו ב-WebGL2) — בלי תפרים בין אריחים
fn noise(p: vec2f) -> f32 {
  let q = vec2u(vec2i(floor(p)) + vec2i(1 << 20));
  var h = q.x * 1973u + q.y * 9277u + 26699u;
  h = (h ^ (h >> 16u)) * 0x7feb352du; h = h ^ (h >> 15u); h = h * 0x846ca68bu; h = h ^ (h >> 16u);
  return f32(h) / 4294967295.0;
}

// m.x: 1 בהירות/ניגודיות, 2 גוון/רוויה/בהירות, 3 רעש (a = עוצמה, פינת האריח במסמך),
// 4 רמות, 5 היפוך, 6 פוסטריזציה, 7 סף — כמו ב-WebGL2
@fragment fn adjust(i: VO) -> @location(0) vec4f {
  let c = textureSample(t0, smp, i.uv);
  let sel = textureSample(t1, smp, i.uv).r;
  var r = c;
  if (c.a > 0.0) {
    var s = enc(c.rgb / c.a);
    if (u.m.x == 1) {
      s = select(pow(max(s, vec3f(1e-9)), vec3f(exp2(-u.a.x))), vec3f(0.0), s <= vec3f(0.0));
      s = (s - 0.5) * u.a.y + 0.5;
    } else if (u.m.x == 3) {
      s = clamp(s + (noise(u.a.yz + i.uv * 256.0) - 0.5) * u.a.x, vec3f(0.0), vec3f(1.0));
    } else if (u.m.x == 4) {
      s = pow(clamp((s - u.a.x) / max(u.a.y - u.a.x, 1e-4), vec3f(0.0), vec3f(1.0)), vec3f(1.0 / u.a.z));
    } else if (u.m.x == 5) {
      s = 1.0 - clamp(s, vec3f(0.0), vec3f(1.0));
    } else if (u.m.x == 6) {
      s = floor(clamp(s, vec3f(0.0), vec3f(1.0)) * (u.a.x - 1.0) + 0.5) / (u.a.x - 1.0);
    } else if (u.m.x == 7) {
      s = vec3f(step(u.a.x, dot(clamp(s, vec3f(0.0), vec3f(1.0)), vec3f(0.299, 0.587, 0.114))));
    } else {
      var h = rgb2hsl(clamp(s, vec3f(0.0), vec3f(1.0)));
      h.x = fract(h.x + u.a.x);
      h.y = clamp(h.y * u.a.y, 0.0, 1.0);
      s = hsl2rgb(h);
      if (u.a.z >= 0.0) { s = mix(s, vec3f(1.0), u.a.z); } else { s = s * (1.0 + u.a.z); }
    }
    r = vec4f(dec(s) * c.a, c.a);
  }
  var m = 1.0;
  if (u.m.y == 1) { m = sel; }
  return mix(c, r, m);
}

// שכבת התאמה: t0 מה שמתחת, t1 אחרי ההתאמה, t2 מסכה; a.x אטימות, m.y יש מסכה
@fragment fn lerp(i: VO) -> @location(0) vec4f {
  var k = u.a.x;
  if (u.m.y == 1) { k = k * enc(vec3f(textureSample(t2, smp, i.uv).r)).r; }
  return mix(textureSample(t0, smp, i.uv), textureSample(t1, smp, i.uv), k);
}

// טשטוש גאוסי בשני מעברים על אריח מרופד בשכניו; m.x = חצי רוחב הגרעין (עד 64), a.x = סיגמה
@fragment fn blurH(i: VO) -> @location(0) vec4f {
  let p = vec2i(i.pos.xy);
  let k = u.m.x;
  var acc = vec4f(0.0);
  var wsum = 0.0;
  for (var j = -k; j <= k; j++) {
    let w = exp(-f32(j * j) / (2.0 * u.a.x * u.a.x));
    acc += textureLoad(t0, vec2i(p.x + k + j, p.y), 0) * w;
    wsum += w;
  }
  return acc / wsum;
}
// t0 תוצאת המעבר האופקי, t1 האריח המרופד, t2 בחירה; m.y = בחירה
@fragment fn blurV(i: VO) -> @location(0) vec4f {
  let p = vec2i(i.pos.xy);
  let k = u.m.x;
  var acc = vec4f(0.0);
  var wsum = 0.0;
  for (var j = -k; j <= k; j++) {
    let w = exp(-f32(j * j) / (2.0 * u.a.x * u.a.x));
    acc += textureLoad(t0, vec2i(p.x, p.y + k + j), 0) * w;
    wsum += w;
  }
  let orig = textureLoad(t1, p + vec2i(k), 0);
  var r = acc / wsum;
  // a.y > 0 — חידוד (unsharp mask)
  if (u.a.y > 0.0) {
    r = orig + (orig - r) * u.a.y;
    r.a = clamp(r.a, 0.0, 1.0);
    r = vec4f(clamp(r.rgb, vec3f(0.0), vec3f(r.a)), r.a);
  }
  var m = 1.0;
  if (u.m.y == 1) { m = textureLoad(t2, p, 0).r; }
  return mix(orig, r, m);
}

// טשטוש תנועה: t0 אריח מרופד, t1 בחירה; m.x חצי האורך, a.xy כיוון
@fragment fn motion(i: VO) -> @location(0) vec4f {
  let p = vec2i(i.pos.xy);
  let k = u.m.x;
  var acc = vec4f(0.0);
  var n = 0.0;
  for (var j = -k; j <= k; j++) {
    acc += textureLoad(t0, p + vec2i(k) + vec2i(round(u.a.xy * f32(j))), 0);
    n += 1.0;
  }
  let orig = textureLoad(t0, p + vec2i(k), 0);
  var m = 1.0;
  if (u.m.y == 1) { m = textureLoad(t1, p, 0).r; }
  return mix(orig, acc / n, m);
}

@fragment fn copy(i: VO) -> @location(0) vec4f { return textureSample(t0, smp, i.uv); }

// חילוץ לתוכן צף: t0 השכבה כפול t1 מסכת הבחירה (m.x = יש בחירה)
@fragment fn extract(i: VO) -> @location(0) vec4f {
  let c = textureSample(t0, smp, i.uv);
  let s = textureSample(t1, smp, i.uv).r;
  return c * select(1.0, s, u.m.x == 1);
}

// התצוגה: מעל משבצות שקיפות, בלינארי, ואז sRGB; a.x = גודל המשבצת
@fragment fn display(i: VO) -> @location(0) vec4f {
  let c = textureSample(t0, smp, i.uv);
  let k = (floor(i.pos.x / u.a.x) + floor(i.pos.y / u.a.x)) % 2.0;
  let bg = mix(vec3f(1.0), vec3f(0.6038), k);    // #fff / #ccc בלינארי
  return vec4f(enc(c.rgb + bg * (1.0 - c.a)), 1.0);
}

// תמונה ממוזערת: 4×4 דגימות לכל פיקסל, מעל משבצות, ב-sRGB; a.xy = רבע מטביעת הרגל, ב-uv של האריח
@fragment fn thumb(i: VO) -> @location(0) vec4f {
  var c = vec4f(0.0);
  for (var j = 0; j < 4; j++) {
    for (var q = 0; q < 4; q++) {
      c += textureSampleLevel(t0, smp, i.uv + (vec2f(f32(q), f32(j)) - 1.5) * u.a.xy, 0.0);
    }
  }
  c = c / 16.0;
  let k = (floor(i.pos.x / 4.0) + floor(i.pos.y / 4.0)) % 2.0;
  let bg = mix(vec3f(1.0), vec3f(0.6038), k);
  return vec4f(enc(c.rgb + bg * (1.0 - c.a)), 1.0);
}

// קריאה החוצה: פיקסל-פיקסל → sRGB ישר של 8 ביט
@fragment fn readOut(i: VO) -> @location(0) vec4f {
  let c = textureLoad(t0, vec2i(i.pos.xy), 0);
  if (c.a > 0.0) { return vec4f(enc(c.rgb / c.a), c.a); }
  return vec4f(0.0);
}
`;

const TILE_FMT = 'rgba16float';
const MIP_LEVELS = Math.log2(TILE) + 1;
const SLOT = 256;                 // מרווח בין ערכי ציורים בטבעת (minUniformBufferOffsetAlignment)
const RING = SLOT * 8192;
const READ_BATCH = 64;            // אריחים לכל מאגר קריאה (16MB)
const key = (tx, ty) => tx + ',' + ty;
const align256 = n => Math.ceil(n / 256) * 256;

const TEX_USAGE = typeof GPUTextureUsage === 'undefined' ? {} : GPUTextureUsage;

export class WebGPURenderer {
  /** האם יש WebGPU עם מתאם. אסינכרוני — לכן יוצרים עם create ולא עם new. */
  static async supported() {
    try { return !!(navigator.gpu && await navigator.gpu.requestAdapter()); } catch { return false; }
  }

  static async create(canvas) {
    if (!navigator.gpu) throw new Error('no_webgpu');
    const adapter = await navigator.gpu.requestAdapter();
    if (!adapter) throw new Error('no_webgpu');
    const device = await adapter.requestDevice({
      requiredLimits: { maxTextureDimension2D: adapter.limits.maxTextureDimension2D },
    });
    const ctx = canvas.getContext('webgpu');
    if (!ctx) throw new Error('no_webgpu');
    const r = new WebGPURenderer(canvas, adapter, device, ctx);
    await r.#checkCompile();
    return r;
  }

  constructor(canvas, adapter, device, ctx) {
    this.name = 'WebGPU';
    this.canvas = canvas;
    this.adapter = adapter;
    this.device = device;
    this.ctx = ctx;
    this.format = navigator.gpu.getPreferredCanvasFormat();
    ctx.configure({ device, format: this.format, alphaMode: 'premultiplied' });
    this.maxTexture = device.limits.maxTextureDimension2D;
    this.lost = false;
    this.textures = 0;
    device.lost.then(info => { if (info.reason !== 'destroyed') { this.lost = true; this.onLost?.(); } });

    this.module = device.createShaderModule({ code: WGSL });
    const tex = { sampleType: 'float' };
    this.bgl = device.createBindGroupLayout({
      entries: [
        { binding: 0, visibility: GPUShaderStage.VERTEX | GPUShaderStage.FRAGMENT, buffer: { type: 'uniform', hasDynamicOffset: true, minBindingSize: 112 } },
        { binding: 1, visibility: GPUShaderStage.FRAGMENT, sampler: { type: 'filtering' } },
        { binding: 2, visibility: GPUShaderStage.FRAGMENT, texture: tex },
        { binding: 3, visibility: GPUShaderStage.FRAGMENT, texture: tex },
        { binding: 4, visibility: GPUShaderStage.FRAGMENT, texture: tex },
        { binding: 5, visibility: GPUShaderStage.FRAGMENT, texture: tex },
      ],
    });
    this.layout = device.createPipelineLayout({ bindGroupLayouts: [this.bgl] });
    this.pipes = new Map();

    const S = (o = {}) => device.createSampler({ addressModeU: 'clamp-to-edge', addressModeV: 'clamp-to-edge', ...o });
    this.smp = {
      nearest: S(),
      linear: S({ magFilter: 'linear', minFilter: 'linear' }),
      // התרחקות — mipmaps (בלי ריצוד); התקרבות — פיקסלים חדים
      display: S({ magFilter: 'nearest', minFilter: 'linear', mipmapFilter: 'linear' }),
      float: S({ magFilter: 'linear', minFilter: 'linear', mipmapFilter: 'linear' }),
    };

    this.ub = device.createBuffer({ size: RING, usage: GPUBufferUsage.UNIFORM | GPUBufferUsage.COPY_DST });
    this.stage = new ArrayBuffer(RING);
    this.stageF = new Float32Array(this.stage);
    this.stageI = new Int32Array(this.stage);
    this.used = 0;
    this.enc = null;
    this.pending = [];   // מרקמים להרוס אחרי השליחה

    this.scratch = [this.#tileTexture(1), this.#tileTexture(1)];
    this.strokeTmp = this.#tileTexture(1);
    this.floatTmp = this.#tileTexture(1);
    this.adjTmp = this.#tileTexture(1);
    this.shiftTmp = this.#tileTexture(1);
    this.maskTmp = this.#tileTexture(1);
    this.adjLayerTmp = this.#tileTexture(1);
    this.padTex = this.#tex(TILE + 128, TILE + 128, TILE_FMT);
    this.blurTmp = this.#tex(TILE, TILE + 128, TILE_FMT);
    this.emptyTex = this.#tileTexture(1);
    this.fullMask = this.#r8(new Uint8Array(TILE * TILE).fill(255));
    this.zeroMask = this.#r8(new Uint8Array(TILE * TILE));
    this.readTex = this.#tex(TILE, TILE, 'rgba8unorm');
    this.textures = 0;
  }

  /** שגיאת הידור ב-WGSL צריכה ליפול כאן (ואז — גיבוי ל-WebGL2), לא באמצע ציור */
  async #checkCompile() {
    const info = await this.module.getCompilationInfo();
    const errs = info.messages.filter(m => m.type === 'error');
    if (errs.length) throw new Error('wgsl: ' + errs.map(m => `${m.lineNum}:${m.linePos} ${m.message}`).join('; '));
    this.device.pushErrorScope('validation');
    for (const name of ['upload', 'fill', 'blend', 'dab', 'stroke', 'adjust', 'blurH', 'blurV', 'lerp', 'copy', 'extract', 'read', 'thumb', 'display', 'float']) this.#pipe(name);
    const err = await this.device.popErrorScope();
    if (err) throw new Error('pipeline: ' + err.message);
  }

  // ---------- תשתית ----------

  #tex(w, h, format, levels = 1, extra = 0) {
    const t = this.device.createTexture({
      size: [w, h], format, mipLevelCount: levels,
      usage: TEX_USAGE.TEXTURE_BINDING | TEX_USAGE.RENDER_ATTACHMENT | TEX_USAGE.COPY_SRC | TEX_USAGE.COPY_DST | extra,
    });
    return t;
  }

  /** אריח float16. ב-WebGPU מרקם חדש מאופס — אריח חדש שקוף. */
  #tileTexture(levels) {
    this.textures++;
    return this.#tex(TILE, TILE, TILE_FMT, levels);
  }

  #r8(bytes) {
    const t = this.#tex(TILE, TILE, 'r8unorm');
    this.device.queue.writeTexture({ texture: t }, bytes, { bytesPerRow: TILE }, [TILE, TILE]);
    return t;
  }

  #view(t) { return (t._v ??= t.createView()); }

  /**
   * pipeline לפי שם ה-shader. float = vsM + copy עם source-over; dab — MAX; היעד: אריח, rgba8 (קריאה,
   * ממוזערת) או המסך.
   */
  #pipe(name) {
    let p = this.pipes.get(name);
    if (p) return p;
    const format = name === 'display' ? this.format : name === 'read' || name === 'thumb' ? 'rgba8unorm' : TILE_FMT;
    const blend = name === 'dab' ? { color: { operation: 'max', srcFactor: 'one', dstFactor: 'one' }, alpha: { operation: 'max', srcFactor: 'one', dstFactor: 'one' } }
      : name === 'float' ? { color: { srcFactor: 'one', dstFactor: 'one-minus-src-alpha' }, alpha: { srcFactor: 'one', dstFactor: 'one-minus-src-alpha' } }
      : undefined;
    p = this.device.createRenderPipeline({
      layout: this.layout,
      vertex: { module: this.module, entryPoint: name === 'float' ? 'vsM' : 'vs' },
      fragment: { module: this.module, entryPoint: name === 'float' ? 'copy' : name === 'read' ? 'readOut' : name, targets: [{ format, blend }] },
      primitive: { topology: 'triangle-strip' },
    });
    this.pipes.set(name, p);
    return p;
  }

  #encoder() {
    if (!this.enc) {
      this.enc = this.device.createCommandEncoder();
      // כל מה שנאסף במשימה הזו נשלח בסופה
      queueMicrotask(() => this.#flush());
    }
    return this.enc;
  }

  /** שולח את מה שנאסף: הערכים לטבעת, ואז ה-encoder. אחריו — הורסים את מה ששוחרר בינתיים. */
  #flush() {
    if (!this.enc) return;
    if (this.used) this.device.queue.writeBuffer(this.ub, 0, this.stage, 0, this.used);
    this.device.queue.submit([this.enc.finish()]);
    this.enc = null;
    this.used = 0;
    for (const t of this.pending) t.destroy();
    this.pending = [];
  }

  #destroy(t) {
    if (this.enc) this.pending.push(t); else t.destroy();
  }

  /**
   * מעבר ציור אל view. o: { clear, viewport: [w, h] }. מחזיר { draw, end }. טבעת מלאה באמצע — המעבר נסגר,
   * נשלח, ונפתח מחדש (load) על אותו יעד.
   */
  #pass(view, o = {}) {
    let pass = null;
    const open = clear => {
      pass = this.#encoder().beginRenderPass({
        colorAttachments: [{ view, loadOp: clear ? 'clear' : 'load', storeOp: 'store', clearValue: [0, 0, 0, 0] }],
      });
      if (o.viewport) pass.setViewport(0, 0, o.viewport[0], o.viewport[1], 0, 1);
    };
    open(!!o.clear);
    return {
      /** u: { rect, uv, size, a, b, c, m }; tex: [t0, t1, t2, t3] (מרקמים או views); smp — שם הדוגם */
      draw: (name, u, tex = [], smp = 'nearest') => {
        if (this.used + SLOT > RING) { pass.end(); this.#flush(); open(false); }
        const off = this.used;
        this.used += SLOT;
        const F = this.stageF, I = this.stageI, b = off / 4;
        F.set(u.rect ?? [0, 0, TILE, TILE], b);
        F.set(u.uv ?? [0, 0, 1, 1], b + 4);
        F.set(u.size ?? [TILE, TILE, 0, 0], b + 8);
        F.set(u.a ?? [0, 0, 0, 0], b + 12);
        F.set(u.b ?? [0, 0, 0, 0], b + 16);
        F.set(u.c ?? [0, 0, 0, 0], b + 20);
        I.set(u.m ?? [0, 0, 0, 0], b + 24);
        const v = t => (t ? (t instanceof GPUTexture ? this.#view(t) : t) : this.#view(this.emptyTex));
        const bg = this.device.createBindGroup({
          layout: this.bgl,
          entries: [
            { binding: 0, resource: { buffer: this.ub, offset: 0, size: 112 } },
            { binding: 1, resource: this.smp[smp] },
            { binding: 2, resource: v(tex[0]) },
            { binding: 3, resource: v(tex[1]) },
            { binding: 4, resource: v(tex[2]) },
            { binding: 5, resource: v(tex[3]) },
          ],
        });
        pass.setPipeline(this.#pipe(name));
        pass.setBindGroup(0, bg, [off]);
        pass.draw(4);
      },
      end: () => pass.end(),
    };
  }

  /** ציור אחד לאריח שלם (או למלבן בו) */
  #drawTo(target, name, u, tex, o = {}, smp) {
    const p = this.#pass(target instanceof GPUTexture ? this.#view(target) : target, o);
    p.draw(name, u, tex, smp);
    p.end();
  }

  #copyTexture(src, dst, size = [TILE, TILE]) {
    this.#encoder().copyTextureToTexture({ texture: src }, { texture: dst }, size);
  }

  /** mipmaps: כל רמה מהקודמת, בדגימה לינארית — ממוצע 2×2 */
  #mipmaps(t, w, h, levels) {
    for (let i = 1; i < levels; i++) {
      const sw = Math.max(1, w >> i), sh = Math.max(1, h >> i);
      const src = t.createView({ baseMipLevel: i - 1, mipLevelCount: 1 });
      const dst = t.createView({ baseMipLevel: i, mipLevelCount: 1 });
      this.#drawTo(dst, 'copy', { rect: [0, 0, sw, sh], size: [sw, sh, 0, 0] }, [src], {}, 'linear');
    }
  }

  #layerTile(layer, tx, ty) {
    let t = layer.tiles.get(key(tx, ty));
    if (!t) { t = this.#tileTexture(1); layer.tiles.set(key(tx, ty), t); }
    return t;
  }

  #dirty(doc, x, y, w, h) {
    doc._dirty ??= new Set();
    for (const [tx, ty] of tilesInRect(x, y, w, h)) doc._dirty.add(key(tx, ty));
  }

  invalidate(doc) { this.#dirty(doc, 0, 0, doc.width, doc.height); }

  invalidateTiles(doc, keys) {
    doc._dirty ??= new Set();
    for (const k of keys) doc._dirty.add(k);
  }

  // ---------- שכבות ----------

  freeLayer(layer) {
    for (const t of layer.tiles.values()) { this.#destroy(t); this.textures--; }
    layer.tiles.clear();
    if (layer.mask) this.freeLayer(layer.mask);
  }

  #colorAdjust(adj, base, out) {
    this.#drawTo(out, 'adjust', { a: WebGL2Renderer.adjustParams(adj), m: [WebGL2Renderer.adjustKind(adj), 0, 0, 0] }, [base, this.fullMask]);
  }

  /** המראה של שכבת התאמה במקומה (ראו WebGL2Renderer) */
  #adjustLayerTile(doc, layer, tx, ty, out) {
    const list = doc.listOf(layer);
    const below = this.#compositeList(doc, list.slice(0, list.indexOf(layer)), tx, ty, 8);
    this.#colorAdjust(adjustOf(layer), below, this.adjLayerTmp);
    const mk = layer.mask?.enabled ? (layer.mask.tiles.get(key(tx, ty)) ?? this.emptyTex) : null;
    this.#drawTo(out, 'blend', { a: [1, 0, 0, 0], m: [0, mk ? 1 : 0, 0, 0] }, [this.emptyTex, this.adjLayerTmp, mk]);
    return out;
  }

  async readAdjustLayerPixels(doc, layer, x, y, w, h) {
    return this.#read((tx, ty) => this.#adjustLayerTile(doc, layer, tx, ty, this.maskTmp), x, y, w, h);
  }

  /** אריח השכבה כפול המסכה שלה (ראו WebGL2Renderer) */
  #maskedTile(layer, tx, ty, out) {
    const t = layer.tiles.get(key(tx, ty)) ?? this.emptyTex;
    this.#drawTo(out, 'blend', { a: [1, 0, 0, 0], m: [0, 1, 0, 0] }, [this.emptyTex, t, layer.mask.tiles.get(key(tx, ty)) ?? this.emptyTex]);
    return out;
  }

  applyMask(doc, layer) {
    const before = new Map();
    for (const k of [...layer.tiles.keys()]) {
      const [tx, ty] = k.split(',').map(Number);
      const out = this.#tileTexture(1);
      this.#maskedTile(layer, tx, ty, out);
      before.set(k, layer.tiles.get(k));
      layer.tiles.set(k, out);
    }
    layer.rev = (layer.rev ?? 0) + 1;
    this.invalidate(doc);
    return before;
  }

  #copyTile(t) {
    const c = this.#tileTexture(1);
    this.#copyTexture(t, c);
    return c;
  }

  copyLayer(src, dst) {
    this.freeLayer(dst);
    for (const [k, t] of src.tiles) dst.tiles.set(k, this.#copyTile(t));
    dst.rev = (dst.rev ?? 0) + 1;
    if (src.mask) {
      dst.mask = newMask(dst, src.mask.enabled);
      this.copyLayer(src.mask, dst.mask);
    }
  }

  snapshot(layer, keys) {
    const out = new Map();
    for (const k of keys) {
      const t = layer.tiles.get(k);
      out.set(k, t ? this.#copyTile(t) : null);
    }
    return out;
  }

  freeTextures(texs) {
    for (const t of texs) if (t) { this.#destroy(t); this.textures--; }
  }

  async thumbnail(doc, layer, max = 64) {
    const k = Math.min(1, max / Math.max(doc.width, doc.height));
    const tw = Math.max(1, Math.round(doc.width * k)), th = Math.max(1, Math.round(doc.height * k));
    if (!this.thumbTex || this.thumbSize !== max) {
      if (this.thumbTex) this.#destroy(this.thumbTex);
      this.thumbTex = this.#tex(max, max, 'rgba8unorm');
      this.thumbSize = max;
    }
    const p = this.#pass(this.#view(this.thumbTex), { clear: true });
    const size = [max, max, 0, 0];
    p.draw('thumb', { rect: [0, 0, tw, th], size, a: [0, 0, 0, 0] }, [this.emptyTex]);
    const step = 1 / k / TILE / 4;
    for (const [tx, ty] of tilesInRect(0, 0, doc.width, doc.height)) {
      const t = layer.tiles.get(key(tx, ty));
      if (!t) continue;
      const x0 = tx * TILE, y0 = ty * TILE;
      const x1 = Math.min(doc.width, x0 + TILE), y1 = Math.min(doc.height, y0 + TILE);
      p.draw('thumb', { rect: [x0 * k, y0 * k, x1 * k, y1 * k], uv: [0, 0, (x1 - x0) / TILE, (y1 - y0) / TILE], size, a: [step, step, 0, 0] }, [t], 'linear');
    }
    p.end();
    const bpr = align256(tw * 4);
    const buf = this.device.createBuffer({ size: bpr * th, usage: GPUBufferUsage.MAP_READ | GPUBufferUsage.COPY_DST });
    this.#encoder().copyTextureToBuffer({ texture: this.thumbTex }, { buffer: buf, bytesPerRow: bpr }, [tw, th]);
    this.#flush();
    await buf.mapAsync(GPUMapMode.READ);
    const src = new Uint8Array(buf.getMappedRange());
    const px = new Uint8ClampedArray(tw * th * 4);
    for (let r = 0; r < th; r++) px.set(src.subarray(r * bpr, r * bpr + tw * 4), r * tw * 4);
    buf.destroy();
    return new ImageData(px, tw, th);
  }

  // ---------- כתיבה לשכבות ----------

  fill(doc, layer, [r, g, b, a = 255]) {
    const al = a / 255;
    const color = [srgbToLinear(r) * al, srgbToLinear(g) * al, srgbToLinear(b) * al, al];
    for (const [tx, ty] of tilesInRect(0, 0, doc.width, doc.height)) {
      const w = Math.min(TILE, doc.width - tx * TILE), h = Math.min(TILE, doc.height - ty * TILE);
      this.#drawTo(this.#layerTile(layer, tx, ty), 'fill', { rect: [0, 0, w, h], a: color });
    }
    layer.rev = (layer.rev ?? 0) + 1;
    this.invalidate(doc);
  }

  async drawImage(doc, layer, source, dx = 0, dy = 0) {
    const W = source.width, H = source.height;
    const chunk = Math.min(this.maxTexture, 4096);
    for (let cy = 0; cy < H; cy += chunk) {
      for (let cx = 0; cx < W; cx += chunk) {
        const cw = Math.min(chunk, W - cx), ch = Math.min(chunk, H - cy);
        const part = cw === W && ch === H ? source
          : await createImageBitmap(source, cx, cy, cw, ch, { premultiplyAlpha: 'none', colorSpaceConversion: 'none' });
        // מרקם חדש לכל חתיכה: ההעתקה קורית מיד בתור, והציורים ממנו — רק בשליחה
        const src = this.#tex(cw, ch, 'rgba8unorm-srgb');
        this.device.queue.copyExternalImageToTexture({ source: part }, { texture: src, premultipliedAlpha: false, colorSpace: 'srgb' }, [cw, ch]);
        if (part !== source) part.close();
        const X = dx + cx, Y = dy + cy;
        for (const [tx, ty] of tilesInRect(X, Y, cw, ch)) {
          const ox = tx * TILE, oy = ty * TILE;
          const x0 = Math.max(X, ox), y0 = Math.max(Y, oy);
          const x1 = Math.min(X + cw, ox + TILE), y1 = Math.min(Y + ch, oy + TILE);
          this.#drawTo(this.#layerTile(layer, tx, ty), 'upload', {
            rect: [x0 - ox, y0 - oy, x1 - ox, y1 - oy],
            uv: [(x0 - X) / cw, (y0 - Y) / ch, (x1 - X) / cw, (y1 - Y) / ch],
          }, [src]);
        }
        this.#destroy(src);
      }
    }
    layer.rev = (layer.rev ?? 0) + 1;
    this.#dirty(doc, dx, dy, W, H);
  }

  // ---------- משיכות ----------

  beginStroke(doc, layer, { mode = 'paint', color = [0, 0, 0], opacity = 1, offset = [0, 0] } = {}) {
    if (doc._stroke) this.cancelStroke(doc);
    doc._stroke = {
      doc, layer, mode, opacity, mask: new Map(),
      color: [srgbToLinear(color[0]), srgbToLinear(color[1]), srgbToLinear(color[2]), 1],
      ...(mode === 'clone' ? { from: new Map(layer.tiles), offset: offset.map(Math.round), src: new Map() } : {}),
    };
  }

  #maskTile(st, k) {
    let m = st.mask.get(k);
    if (!m) { m = this.#tileTexture(1); st.mask.set(k, m); }
    return m;
  }

  dab(doc, x, y, r, hardness = 1, alpha = 1) {
    const st = doc._stroke;
    if (!st) return;
    r = Math.max(0.5, r);
    const R = r + 1;
    const x0 = Math.floor(x - R), y0 = Math.floor(y - R);
    const keys = [];
    for (const [tx, ty] of tilesInRect(x0, y0, Math.ceil(x + R) - x0, Math.ceil(y + R) - y0)) {
      const k = key(tx, ty);
      keys.push(k);
      const ox = tx * TILE, oy = ty * TILE;
      this.#drawTo(this.#maskTile(st, k), 'dab', {
        rect: [x - R - ox, y - R - oy, x + R - ox, y + R - oy], uv: [-R, -R, R, R], a: [r, hardness, alpha, 0],
      });
    }
    this.invalidateTiles(doc, keys);
  }

  maskFromAlpha(doc, alpha, x, y, w, h, box = [0, 0, w - 1, h - 1]) {
    const st = doc._stroke;
    if (!st) return;
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
      st.mask.set(k, this.#r8(buf));
      this.textures++;
      keys.push(k);
    }
    this.invalidateTiles(doc, keys);
  }

  #applyStroke(st, layerTex, maskTex, outTex, k = null) {
    const sel = k && st.doc.selection ? this.#selTex(st.doc, k) : null;
    const src = k && st.src ? this.#cloneSrc(st, k) : null;
    this.#drawTo(outTex, 'stroke', {
      a: st.color, b: [st.opacity, 0, 0, 0], m: [st.mode === 'erase' ? 1 : 0, sel ? 1 : 0, src ? 1 : 0, 0],
    }, [layerTex, maskTex, sel || this.fullMask, src]);
  }

  /** אריח המקור של חותמת השכפול ליעד k (ראו WebGL2Renderer.beginStroke) */
  #cloneSrc(st, k) {
    let t = st.src.get(k);
    if (t) return t;
    const [tx, ty] = k.split(',').map(Number);
    const [dx, dy] = st.offset;
    t = this.#tileTexture(1);
    const p = this.#pass(this.#view(t), { clear: true });
    for (const [sx, sy] of tilesInRect(tx * TILE + dx, ty * TILE + dy, TILE, TILE)) {
      const src = st.from.get(key(sx, sy));
      if (!src) continue;
      const ox = sx * TILE - dx - tx * TILE, oy = sy * TILE - dy - ty * TILE;
      p.draw('copy', { rect: [ox, oy, ox + TILE, oy + TILE] }, [src]);
    }
    p.end();
    st.src.set(k, t);
    return t;
  }

  endStroke(doc) {
    const st = doc._stroke;
    if (!st) return new Map();
    doc._stroke = null;
    const before = new Map();
    for (const [k, m] of st.mask) {
      const old = st.layer.tiles.get(k) ?? null;
      if (!old && st.mode === 'erase') continue;
      const out = this.#tileTexture(1);
      this.#applyStroke(st, old || this.emptyTex, m, out, k);
      st.layer.tiles.set(k, out);
      before.set(k, old);
    }
    this.#freeMasks(st);
    st.layer.rev = (st.layer.rev ?? 0) + 1;
    this.invalidateTiles(doc, st.mask.keys());
    return before;
  }

  cancelStroke(doc) {
    const st = doc._stroke;
    if (!st) return;
    doc._stroke = null;
    this.#freeMasks(st);
    this.invalidateTiles(doc, st.mask.keys());
  }

  #freeMasks(st) {
    this.freeTextures([...st.mask.values()].filter(t => t !== this.fullMask && t !== this.zeroMask));
    if (st.src) this.freeTextures([...st.src.values()]);
  }

  // ---------- בחירה ----------

  selectionChanged(doc) {
    if (doc._sel) this.freeTextures([...doc._sel.values()].filter(t => t !== this.fullMask && t !== this.zeroMask));
    doc._sel = new Map();
    if (doc._stroke) this.invalidate(doc);
  }

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

  fillSelection(doc, layer, mode, color = [0, 0, 0], opacity = 1) {
    const box = doc.selection ? selectionBox(doc.selection, doc.width, doc.height) : [0, 0, doc.width, doc.height];
    if (!box) return new Map();
    this.beginStroke(doc, layer, { mode, color, opacity });
    for (const [tx, ty] of tilesInRect(box[0], box[1], box[2] - box[0], box[3] - box[1])) {
      doc._stroke.mask.set(key(tx, ty), this.fullMask);
    }
    return this.endStroke(doc);
  }

  // ---------- תיקונים ----------

  setAdjust(doc, layer, adj) {
    doc._adjust = adj && !WebGL2Renderer.adjustNoop(adj) ? { ...adj, layer } : null;
    this.invalidate(doc);
  }

  #adjustKeys(adj, layer) {
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
    if (layer.tiles.has(key(tx, ty))) return true;
    if (!WebGL2Renderer.adjustReach(adj)) return false;
    for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) if (layer.tiles.has(key(tx + dx, ty + dy))) return true;
    return false;
  }

  static adjustParams(adj) { return WebGL2Renderer.adjustParams(adj); }

  #adjustTile(doc, adj, layer, tx, ty, base, out) {
    const k = key(tx, ty);
    const sel = doc.selection ? this.#selTex(doc, k) : null;
    base ||= this.emptyTex;
    const K = WebGL2Renderer.adjustReach(adj);
    if (K > 0) {
      const P = TILE + 2 * K;
      const pad = this.#pass(this.#view(this.padTex), { clear: true, viewport: [P, P] });
      for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
        const nt = dx || dy ? layer.tiles.get(key(tx + dx, ty + dy)) : base;
        if (!nt) continue;
        const ox = K + dx * TILE, oy = K + dy * TILE;
        pad.draw('copy', { rect: [ox, oy, ox + TILE, oy + TILE], size: [P, P, 0, 0] }, [nt]);
      }
      pad.end();
      if (adj.kind === 'motion') {
        const a = adj.angle * Math.PI / 180;
        this.#drawTo(out, 'motion', { a: [Math.cos(a), -Math.sin(a), 0, 0], m: [K, sel ? 1 : 0, 0, 0] }, [this.padTex, sel || this.fullMask]);
        return;
      }
      const sigma = Math.max(0.5, K / 3);
      this.#drawTo(this.blurTmp, 'blurH', { rect: [0, 0, TILE, P], size: [TILE, P, 0, 0], a: [sigma, 0, 0, 0], m: [K, 0, 0, 0] },
        [this.padTex], { viewport: [TILE, P] });
      this.#drawTo(out, 'blurV', { a: [sigma, adj.kind === 'sharpen' ? adj.amount / 100 : 0, 0, 0], m: [K, sel ? 1 : 0, 0, 0] },
        [this.blurTmp, this.padTex, sel || this.fullMask]);
    } else {
      const pp = WebGL2Renderer.adjustParams(adj);
      this.#drawTo(out, 'adjust', {
        a: adj.kind === 'noise' ? [pp[0], tx * TILE, ty * TILE, 0] : pp, m: [WebGL2Renderer.adjustKind(adj), sel ? 1 : 0, 0, 0],
      }, [base, sel || this.fullMask]);
    }
  }

  commitAdjust(doc) {
    const adj = doc._adjust;
    if (!adj) return new Map();
    const { layer } = adj;
    const results = new Map();
    for (const k of this.#adjustKeys(adj, layer)) {
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
    layer.rev = (layer.rev ?? 0) + 1;
    this.invalidate(doc);
    return before;
  }

  // ---------- חיתוך ----------

  shiftTiles(tiles, dx, dy) {
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
    for (const [dk, srcs] of dest) {
      const [ux, uy] = dk.split(',').map(Number);
      const t = this.#tileTexture(1);
      const p = this.#pass(this.#view(t), { clear: true });
      for (const [tx, ty, src] of srcs) {
        const ox = tx * TILE + dx - ux * TILE, oy = ty * TILE + dy - uy * TILE;
        p.draw('copy', { rect: [ox, oy, ox + TILE, oy + TILE] }, [src]);
      }
      p.end();
      out.set(dk, t);
    }
    return out;
  }

  resetComposite(doc) {
    if (doc._comp) this.freeTextures(doc._comp.values());
    doc._comp = new Map();
    doc._dirty = new Set();
    this.selectionChanged(doc);
  }

  // ---------- תוכן צף ----------

  async layerBounds(layer) {
    if (!layer.tiles.size) return null;
    let tx0 = Infinity, ty0 = Infinity, tx1 = -Infinity, ty1 = -Infinity;
    for (const k of layer.tiles.keys()) {
      const [tx, ty] = k.split(',').map(Number);
      tx0 = Math.min(tx0, tx); ty0 = Math.min(ty0, ty); tx1 = Math.max(tx1, tx); ty1 = Math.max(ty1, ty);
    }
    const X = tx0 * TILE, Y = ty0 * TILE, W = (tx1 - tx0 + 1) * TILE, H = (ty1 - ty0 + 1) * TILE;
    const px = await this.readLayerPixels(layer, X, Y, W, H);
    let x0 = W, y0 = H, x1 = -1, y1 = -1;
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      if (px[(y * W + x) * 4 + 3]) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    }
    return x1 < 0 ? null : [X + x0, Y + y0, x1 - x0 + 1, y1 - y0 + 1];
  }

  beginFloat(doc, layer, [x, y, w, h], t) {
    if (w > this.maxTexture || h > this.maxTexture) throw new Error('too_big');
    const levels = Math.floor(Math.log2(Math.max(w, h))) + 1;
    const tex = this.#tex(w, h, TILE_FMT, levels);
    this.textures++;
    const useSel = !!doc.selection;
    const sels = new Map();
    if (useSel) for (const [tx, ty] of tilesInRect(x, y, w, h)) sels.set(key(tx, ty), this.#selTex(doc, key(tx, ty)));
    const lvl0 = tex.createView({ baseMipLevel: 0, mipLevelCount: 1 });
    const p = this.#pass(lvl0, { clear: true });
    for (const [tx, ty] of tilesInRect(x, y, w, h)) {
      const lt = layer.tiles.get(key(tx, ty));
      if (!lt) continue;
      const ox = tx * TILE - x, oy = ty * TILE - y;
      p.draw('extract', { rect: [ox, oy, ox + TILE, oy + TILE], size: [w, h, 0, 0], m: [useSel ? 1 : 0, 0, 0, 0] },
        [lt, useSel ? sels.get(key(tx, ty)) : this.fullMask]);
    }
    p.end();
    this.#mipmaps(tex, w, h, levels);

    let before;
    if (useSel) before = this.fillSelection(doc, layer, 'erase');
    else { before = new Map(layer.tiles); layer.tiles.clear(); layer.rev = (layer.rev ?? 0) + 1; }
    doc._float = { layer, tex, x, y, w, h, t, before };
    this.invalidateTiles(doc, [...before.keys()]);
    this.#dirty(doc, x, y, w, h);
    return doc._float;
  }

  #floatTouches(fl, tx, ty) {
    const [x0, y0, x1, y1] = xfBounds(fl.t, fl.w, fl.h);
    return tx * TILE < x1 + 1 && (tx + 1) * TILE > x0 - 1 && ty * TILE < y1 + 1 && (ty + 1) * TILE > y0 - 1;
  }

  #floatInto(out, base, fl, tx, ty) {
    const m = xfMatrix(fl.t, fl.w, fl.h);
    const p = this.#pass(this.#view(out));
    p.draw('copy', {}, [base]);
    p.draw('float', { a: [fl.w, fl.h, 0, 0], b: [m[0], m[1], m[2], m[3]], c: [m[4], m[5], tx * TILE, ty * TILE] }, [fl.tex], 'float');
    p.end();
  }

  setFloatTransform(doc, t) {
    const fl = doc._float;
    if (!fl) return;
    const a = xfBounds(fl.t, fl.w, fl.h), b = xfBounds(t, fl.w, fl.h);
    fl.t = t;
    for (const r of [a, b]) this.#dirty(doc, r[0] - 1, r[1] - 1, r[2] - r[0] + 2, r[3] - r[1] + 2);
  }

  commitFloat(doc) {
    const fl = doc._float;
    if (!fl) return new Map();
    doc._float = null;
    const { layer, before } = fl;
    const [x0, y0, x1, y1] = xfBounds(fl.t, fl.w, fl.h);
    for (const [tx, ty] of tilesInRect(x0 - 1, y0 - 1, x1 - x0 + 2, y1 - y0 + 2)) {
      const k = key(tx, ty);
      const cur = layer.tiles.get(k) ?? null;
      const out = this.#tileTexture(1);
      this.#floatInto(out, cur || this.emptyTex, fl, tx, ty);
      layer.tiles.set(k, out);
      if (before.has(k)) { if (cur) this.freeTextures([cur]); }
      else before.set(k, cur);
    }
    this.freeTextures([fl.tex]);
    layer.rev = (layer.rev ?? 0) + 1;
    this.invalidateTiles(doc, [...before.keys()]);
    return before;
  }

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
    const [x0, y0, x1, y1] = xfBounds(fl.t, fl.w, fl.h);
    this.#dirty(doc, x0 - 1, y0 - 1, x1 - x0 + 2, y1 - y0 + 2);
  }

  // ---------- הרכבה ----------

  #composite(doc, tx, ty) {
    doc._comp ??= new Map();
    let out = doc._comp.get(key(tx, ty));
    if (!out) { out = this.#tileTexture(MIP_LEVELS); doc._comp.set(key(tx, ty), out); }
    const a = this.#compositeList(doc, doc.layers, tx, ty, 0);
    this.#copyTexture(a, out);
    this.#mipmaps(out, TILE, TILE, MIP_LEVELS);
    doc._dirty?.delete(key(tx, ty));
    return out;
  }

  #scratchAt(depth) {
    this.scratchStack ??= [this.scratch];
    return (this.scratchStack[depth] ??= [this.#tileTexture(1), this.#tileTexture(1)]);
  }

  #layerTileFor(doc, layer, tx, ty) {
    let t = layer.tiles.get(key(tx, ty));
    const sh = doc._shift;
    if (sh && sh.layers.has(layer)) t = this.#shiftedTile(layer, tx, ty, sh.dx, sh.dy, this.shiftTmp);
    const st = doc._stroke;
    const m = st && st.layer === layer ? st.mask.get(key(tx, ty)) : null;
    if (m) { this.#applyStroke(st, t || this.emptyTex, m, this.strokeTmp, key(tx, ty)); t = this.strokeTmp; }
    const fl = doc._float;
    if (fl && fl.layer === layer && this.#floatTouches(fl, tx, ty)) { this.#floatInto(this.floatTmp, t || this.emptyTex, fl, tx, ty); t = this.floatTmp; }
    const adj = doc._adjust;
    if (adj && adj.layer === layer && this.#adjustTouches(adj, layer, tx, ty)) { this.#adjustTile(doc, adj, layer, tx, ty, t, this.adjTmp); t = this.adjTmp; }
    return t;
  }

  #compositeList(doc, list, tx, ty, depth) {
    let [a, b] = this.#scratchAt(depth);
    this.#pass(this.#view(a), { clear: true }).end();
    for (const layer of list) {
      if (!layer.visible || layer.opacity <= 0) continue;
      // שכבת התאמה: משנה את מה שכבר הורכב מתחתיה (ראו WebGL2Renderer)
      if (layer.adjust) {
        const mk = layer.mask?.enabled ? (this.#layerTileFor(doc, layer.mask, tx, ty) || this.emptyTex) : null;
        this.#colorAdjust(adjustOf(layer), a, this.adjLayerTmp);
        this.#drawTo(b, 'lerp', { a: [layer.opacity, 0, 0, 0], m: [0, mk ? 1 : 0, 0, 0] }, [a, this.adjLayerTmp, mk]);
        [a, b] = [b, a];
        continue;
      }
      const t = layer.type === 'group'
        ? (layer.children.length ? this.#compositeList(doc, layer.children, tx, ty, depth + 1) : null)
        : this.#layerTileFor(doc, layer, tx, ty);
      if (!t) continue;
      // מסכה: גם היא דרך #layerTileFor (משיכה פתוחה עליה מוצגת חיה). אריח חסר = מוסתר
      const mk = layer.mask?.enabled ? (this.#layerTileFor(doc, layer.mask, tx, ty) || this.emptyTex) : null;
      this.#drawTo(b, 'blend', { a: [layer.opacity, 0, 0, 0], m: [Math.max(0, BLEND_MODES.indexOf(layer.blend)), mk ? 1 : 0, 0, 0] }, [a, t, mk]);
      [a, b] = [b, a];
    }
    return a;
  }

  #shiftedTile(layer, tx, ty, dx, dy, out) {
    const x = tx * TILE - dx, y = ty * TILE - dy;
    const srcs = tilesInRect(x, y, TILE, TILE).map(([sx, sy]) => [sx, sy, layer.tiles.get(key(sx, sy))]).filter(s => s[2]);
    if (!srcs.length) return undefined;
    const p = this.#pass(this.#view(out), { clear: true });
    for (const [sx, sy, t] of srcs) {
      const ox = sx * TILE + dx - tx * TILE, oy = sy * TILE + dy - ty * TILE;
      p.draw('copy', { rect: [ox, oy, ox + TILE, oy + TILE] }, [t]);
    }
    p.end();
    return out;
  }

  setShift(doc, layers, dx = 0, dy = 0) {
    const old = doc._shift;
    doc._shift = layers ? { layers: new Set(layers.flatMap(l => (l.mask ? [l, l.mask] : [l]))), dx, dy } : null;
    if (old || layers) this.invalidate(doc);
  }

  #compositeTile(doc, tx, ty) {
    const k = key(tx, ty);
    const t = doc._comp?.get(k);
    return t && !doc._dirty?.has(k) ? t : this.#composite(doc, tx, ty);
  }

  // ---------- תצוגה ----------

  render(doc, view, dpr = 1) {
    if (this.lost) return;
    const W = this.canvas.width, H = this.canvas.height;
    if (!W || !H) return;
    let tiles = [];
    let z = 1, ox = 0, oy = 0;
    if (doc) {
      z = view.zoom * dpr;
      ox = Math.round(view.x * dpr); oy = Math.round(view.y * dpr);
      const dx0 = Math.max(0, -ox / z), dy0 = Math.max(0, -oy / z);
      const dx1 = Math.min(doc.width, (W - ox) / z), dy1 = Math.min(doc.height, (H - oy) / z);
      if (dx1 > dx0 && dy1 > dy0) {
        tiles = tilesInRect(Math.floor(dx0), Math.floor(dy0), Math.ceil(dx1) - Math.floor(dx0), Math.ceil(dy1) - Math.floor(dy0));
      }
    }
    const comp = tiles.map(([tx, ty]) => [tx, ty, this.#compositeTile(doc, tx, ty)]);
    const p = this.#pass(this.ctx.getCurrentTexture().createView(), { clear: true });
    const check = Math.max(1, Math.round(8 * dpr));
    for (const [tx, ty, tex] of comp) {
      const x0 = tx * TILE, y0 = ty * TILE;
      const x1 = Math.min(doc.width, x0 + TILE), y1 = Math.min(doc.height, y0 + TILE);
      p.draw('display', {
        rect: [ox + x0 * z, oy + y0 * z, ox + x1 * z, oy + y1 * z], uv: [0, 0, (x1 - x0) / TILE, (y1 - y0) / TILE],
        size: [W, H, 0, 0], a: [check, 0, 0, 0],
      }, [tex], 'display');
    }
    p.end();
    this.#flush();
  }

  // ---------- קריאה החוצה ----------

  async readPixels(doc, x, y, w, h) {
    return this.#read((tx, ty) => this.#compositeTile(doc, tx, ty), x, y, w, h);
  }

  async readLayerPixels(layer, x, y, w, h, masked = false) {
    // הפקודות נרשמות לפי הסדר — אריח זמני אחד מספיק גם כשקוראים כמה אריחים במנה
    if (masked && layer.mask?.enabled) return this.#read((tx, ty) => this.#maskedTile(layer, tx, ty, this.maskTmp), x, y, w, h);
    return this.#read((tx, ty) => layer.tiles.get(key(tx, ty)) ?? this.emptyTex, x, y, w, h);
  }

  /** כל אריח: shader הקריאה → readTex → מקום משלו במאגר; עד READ_BATCH אריחים לכל מיפוי */
  async #read(source, x, y, w, h) {
    const out = new Uint8ClampedArray(w * h * 4);
    const all = tilesInRect(x, y, w, h);
    const BYTES = TILE * TILE * 4;
    for (let i = 0; i < all.length; i += READ_BATCH) {
      const batch = all.slice(i, i + READ_BATCH);
      const buf = this.device.createBuffer({ size: batch.length * BYTES, usage: GPUBufferUsage.MAP_READ | GPUBufferUsage.COPY_DST });
      batch.forEach(([tx, ty], j) => {
        const tex = source(tx, ty);
        this.#drawTo(this.readTex, 'read', {}, [tex]);
        this.#encoder().copyTextureToBuffer({ texture: this.readTex }, { buffer: buf, offset: j * BYTES, bytesPerRow: TILE * 4 }, [TILE, TILE]);
      });
      this.#flush();
      await buf.mapAsync(GPUMapMode.READ);
      const src = new Uint8Array(buf.getMappedRange());
      batch.forEach(([tx, ty], j) => {
        const ox = tx * TILE, oy = ty * TILE;
        const x0 = Math.max(x, ox), y0 = Math.max(y, oy);
        const x1 = Math.min(x + w, ox + TILE), y1 = Math.min(y + h, oy + TILE);
        const rw = x1 - x0;
        for (let r = y0; r < y1; r++) {
          const s = j * BYTES + ((r - oy) * TILE + (x0 - ox)) * 4;
          out.set(src.subarray(s, s + rw * 4), ((r - y) * w + (x0 - x)) * 4);
        }
      });
      buf.destroy();
    }
    return out;
  }

  gpuKey() {
    const i = this.adapter.info ?? {};
    return this.name + ':' + [i.vendor, i.architecture, i.device, i.description].filter(Boolean).join(' ');
  }

  /** כמו ב-WebGL2: 1024×1024 עם 4 שכבות; הזמן עד שהכרטיס סיים (onSubmittedWorkDone) */
  async benchmark(DocClass) {
    const d = new DocClass(1024, 1024);
    for (let i = 0; i < 4; i++) this.fill(d, d.addLayer({ blend: ['normal', 'multiply', 'screen', 'overlay'][i], opacity: 0.8 }), [40 * i, 90, 200 - 30 * i, 200]);
    const ops = 16 * 4;
    const run = async () => {
      this.invalidate(d);
      for (const [tx, ty] of tilesInRect(0, 0, 1024, 1024)) this.#compositeTile(d, tx, ty);
      this.#flush();
      await this.device.queue.onSubmittedWorkDone();
    };
    await run();   // חימום
    let best = Infinity;
    for (let k = 0; k < 3; k++) {
      const t0 = performance.now();
      await run();
      best = Math.min(best, performance.now() - t0);
    }
    for (const l of d.rasters()) this.freeLayer(l);
    if (d._comp) this.freeTextures(d._comp.values());
    return ops / Math.max(best, 0.01);
  }

  stats(doc) {
    const layerTiles = doc ? doc.rasters().reduce((n, l) => n + l.tiles.size, 0) : 0;
    const compTiles = doc?._comp?.size ?? 0;
    return {
      layerTiles, compTiles,
      bytes: layerTiles * TILE * TILE * 8 + Math.round(compTiles * TILE * TILE * 8 * 4 / 3),
    };
  }
}
