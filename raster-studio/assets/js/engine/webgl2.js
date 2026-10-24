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

import { TILE, BLEND_MODES, tilesInRect } from './doc.js';
import { rasterTile, selectionBox } from './selection.js';
import { matrix as xfMatrix, bounds as xfBounds } from './transform.js';

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

  // W3C Compositing and Blending Level 1: מיזוג נפרד לכל ערוץ + source-over
  blend: `#version 300 es
precision highp float;
uniform sampler2D u_dst, u_src;
uniform float u_opacity;
uniform int u_mode;
in vec2 v_uv; out vec4 o;
vec3 screenB(vec3 b, vec3 s) { return b + s - b * s; }
// s ≤ 0.5 → multiply(b, 2s); אחרת screen(b, 2s − 1). ב-0.5 שני הענפים שווים.
vec3 hardLight(vec3 b, vec3 s) {
  return mix(b * 2.0 * s, screenB(b, 2.0 * s - 1.0), step(0.5, s));
}
void main() {
  vec4 b = texture(u_dst, v_uv);
  vec4 s = texture(u_src, v_uv) * u_opacity;
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

  // מסכת משיכה (בערוץ r) על אריח של שכבה: צבע (מכחול, דלי) או מחיקה (מחק)
  stroke: `#version 300 es
precision highp float;
uniform sampler2D u_layer, u_mask, u_sel;
uniform vec4 u_color;     // לינארי מוכפל, אלפא 1
uniform float u_opacity;
uniform int u_erase, u_useSel;
in vec2 v_uv; out vec4 o;
void main() {
  vec4 l = texture(u_layer, v_uv);
  float m = clamp(texture(u_mask, v_uv).r, 0.0, 1.0) * u_opacity;
  if (u_useSel == 1) m *= texture(u_sel, v_uv).r;   // בחירה פעילה — רק בתוכה
  o = u_erase == 1 ? l * (1.0 - m) : u_color * m + l * (1.0 - m);
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
    for (const [tx, ty] of tilesInRect(x, y, w, h)) doc._dirty.add(key(tx, ty));
  }

  /** כל המסמך ישורטט מחדש (למשל אחרי שינוי שקיפות או מצב מיזוג של שכבה). */
  invalidate(doc) {
    this.#dirty(doc, 0, 0, doc.width, doc.height);
  }

  /** רק האריחים האלה ישורטטו מחדש (keys — "tx,ty"). */
  invalidateTiles(doc, keys) {
    doc._dirty ??= new Set();
    for (const k of keys) doc._dirty.add(k);
  }

  // ---------- שכבות ----------

  /** משחרר את האריחים של שכבה (אחרי מחיקה מהמסמך). */
  freeLayer(layer) {
    for (const t of layer.tiles.values()) { this.gl.deleteTexture(t); this.textures--; }
    layer.tiles.clear();
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
   * מחזיר ImageData; הצלע הארוכה = max פיקסלים.
   */
  thumbnail(doc, layer, max = 64) {
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

  /** opts: { mode: 'paint' | 'erase', color: [r, g, b] (sRGB), opacity: 0–1 } */
  beginStroke(doc, layer, { mode = 'paint', color = [0, 0, 0], opacity = 1 } = {}) {
    if (doc._stroke) this.cancelStroke(doc);
    doc._stroke = {
      doc, layer, mode, opacity, mask: new Map(),
      color: [srgbToLinear(color[0]), srgbToLinear(color[1]), srgbToLinear(color[2]), 1],
    };
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
    this.#target(outTex);
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, layerTex);
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, maskTex);
    gl.activeTexture(gl.TEXTURE2); gl.bindTexture(gl.TEXTURE_2D, sel || this.fullMask);
    gl.uniform1i(p.u.u_layer, 0);
    gl.uniform1i(p.u.u_mask, 1);
    gl.uniform1i(p.u.u_sel, 2);
    gl.uniform1i(p.u.u_useSel, sel ? 1 : 0);
    gl.uniform4fv(p.u.u_color, st.color);
    gl.uniform1f(p.u.u_opacity, st.opacity);
    gl.uniform1i(p.u.u_erase, st.mode === 'erase' ? 1 : 0);
    this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
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

  /** גבולות התוכן של שכבה (בלי בחירה): [x, y, w, h] מדויק לפי האלפא, או null לשכבה ריקה. */
  layerBounds(layer) {
    if (!layer.tiles.size) return null;
    let tx0 = Infinity, ty0 = Infinity, tx1 = -Infinity, ty1 = -Infinity;
    for (const k of layer.tiles.keys()) {
      const [tx, ty] = k.split(',').map(Number);
      tx0 = Math.min(tx0, tx); ty0 = Math.min(ty0, ty); tx1 = Math.max(tx1, tx); ty1 = Math.max(ty1, ty);
    }
    const X = tx0 * TILE, Y = ty0 * TILE, W = (tx1 - tx0 + 1) * TILE, H = (ty1 - ty0 + 1) * TILE;
    const px = this.readLayerPixels(layer, X, Y, W, H);
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
    const [x0, y0, x1, y1] = xfBounds(fl.t, fl.w, fl.h);
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
    const a = xfBounds(fl.t, fl.w, fl.h), b = xfBounds(t, fl.w, fl.h);
    fl.t = t;
    for (const r of [a, b]) this.#dirty(doc, r[0] - 1, r[1] - 1, r[2] - r[0] + 2, r[3] - r[1] + 2);
  }

  /** כותב את התוכן הצף לשכבה. מחזיר before להיסטוריה (המקוריים של כל אריח שהשתנה). */
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
    const [x0, y0, x1, y1] = xfBounds(fl.t, fl.w, fl.h);
    this.#dirty(doc, x0 - 1, y0 - 1, x1 - x0 + 2, y1 - y0 + 2);
  }

  // ---------- הרכבה ----------

  #composite(doc, tx, ty) {
    const gl = this.gl;
    doc._comp ??= new Map();
    let out = doc._comp.get(key(tx, ty));
    if (!out) { out = this.#tileTexture(MIP_LEVELS); doc._comp.set(key(tx, ty), out); }

    let [a, b] = this.scratch;
    this.#target(a);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    const p = this.prog.blend;
    gl.useProgram(p.p);
    gl.bindVertexArray(this.vao);
    gl.disable(gl.BLEND);
    gl.uniform1i(p.u.u_dst, 0);
    gl.uniform1i(p.u.u_src, 1);
    const st = doc._stroke;
    for (const layer of doc.layers) {
      if (!layer.visible || layer.opacity <= 0) continue;
      let t = layer.tiles.get(key(tx, ty));
      // משיכה פתוחה על השכבה הזו — מציגים את התוצאה בלי לגעת בשכבה עד שהאצבע מורמת
      const m = st && st.layer === layer ? st.mask.get(key(tx, ty)) : null;
      if (m) {
        this.#applyStroke(st, t || this.emptyTex, m, this.strokeTmp, key(tx, ty));
        t = this.strokeTmp;
        gl.useProgram(p.p);
        gl.bindVertexArray(this.vao);
        gl.uniform1i(p.u.u_dst, 0);
        gl.uniform1i(p.u.u_src, 1);
      }
      const fl = doc._float;
      if (fl && fl.layer === layer && this.#floatTouches(fl, tx, ty)) {
        this.#floatInto(this.floatTmp, t || this.emptyTex, fl, tx, ty);
        t = this.floatTmp;
        gl.useProgram(p.p);
        gl.bindVertexArray(this.vao);
        gl.uniform1i(p.u.u_dst, 0);
        gl.uniform1i(p.u.u_src, 1);
      }
      if (!t) continue;
      this.#target(b);
      gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, a);
      gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, t);
      gl.uniform1f(p.u.u_opacity, layer.opacity);
      gl.uniform1i(p.u.u_mode, Math.max(0, BLEND_MODES.indexOf(layer.blend)));
      this.#quad(p, [0, 0, TILE, TILE], [0, 0, 1, 1], [TILE, TILE], -1);
      [a, b] = [b, a];
    }
    gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, null);
    gl.activeTexture(gl.TEXTURE0);

    // a → העותק המורכב (רמה 0), ואז mipmaps
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

  /** הפיקסלים המורכבים של מלבן במסמך — sRGB ישר, RGBA של 8 ביט, שורה 0 למעלה. */
  readPixels(doc, x, y, w, h) {
    return this.#read((tx, ty) => this.#compositeTile(doc, tx, ty), x, y, w, h);
  }

  /** הפיקסלים של שכבה אחת (בלי אטימות ומיזוג) — לדלי. אריח חסר = שקוף. */
  readLayerPixels(layer, x, y, w, h) {
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

  /** כמה מרקמים וכמה זיכרון GPU (בערך) — למדידה ולתקציב. */
  stats(doc) {
    const layerTiles = doc ? doc.layers.reduce((n, l) => n + l.tiles.size, 0) : 0;
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
