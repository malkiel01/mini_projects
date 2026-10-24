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

  // ---------- שכבות ----------

  /** משחרר את האריחים של שכבה (אחרי מחיקה מהמסמך). */
  freeLayer(layer) {
    for (const t of layer.tiles.values()) { this.gl.deleteTexture(t); this.textures--; }
    layer.tiles.clear();
  }

  /** מעתיק את כל האריחים של src ל-dst (שכפול שכבה). dst מתרוקנת קודם. */
  copyLayer(src, dst) {
    const gl = this.gl;
    this.freeLayer(dst);
    for (const [k, t] of src.tiles) {
      const c = this.#tileTexture(1);
      gl.bindFramebuffer(gl.READ_FRAMEBUFFER, this.fboRead);
      gl.framebufferTexture2D(gl.READ_FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, t, 0);
      gl.bindFramebuffer(gl.DRAW_FRAMEBUFFER, this.fbo);
      gl.framebufferTexture2D(gl.DRAW_FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, c, 0);
      gl.blitFramebuffer(0, 0, TILE, TILE, 0, 0, TILE, TILE, gl.COLOR_BUFFER_BIT, gl.NEAREST);
      dst.tiles.set(k, c);
    }
    dst.rev = (dst.rev ?? 0) + 1;
    gl.bindFramebuffer(gl.READ_FRAMEBUFFER, null);
    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
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
    for (const layer of doc.layers) {
      if (!layer.visible || layer.opacity <= 0) continue;
      const t = layer.tiles.get(key(tx, ty));
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
    const gl = this.gl;
    const out = new Uint8ClampedArray(w * h * 4);
    const buf = new Uint8Array(TILE * TILE * 4);
    const p = this.prog.read;
    for (const [tx, ty] of tilesInRect(x, y, w, h)) {
      const tex = this.#compositeTile(doc, tx, ty);
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
