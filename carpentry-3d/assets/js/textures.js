// טקסטורות להדמיה. שני מקורות:
//  1. פרוצדורלי — סיבי עץ שמצוירים על canvas מרעש ופסים, בגוון החומר.
//     אין קובצי תמונה בריפו, והכול עובד גם בלי רשת.
//  2. תמונה אמיתית שהועלתה לחומר (data URL) — דורסת את הפרוצדורלי.
//
// הטקסטורה הבסיסית נוצרת פעם אחת לכל חומר; כל חלק מקבל עותק עם repeat
// וסיבוב משלו, כדי שהסיבים ירוצו לאורך החלק ובקנה מידה פיזי (grainMm).

const T = () => window.THREE;
const SIZE = 512;

// רעש ערכי פשוט ודטרמיניסטי — מספיק לסיבים, ולא צריך ספרייה.
function noise2(seed) {
  const perm = new Uint8Array(512);
  let s = seed >>> 0;
  const rnd = () => (s = (s * 1664525 + 1013904223) >>> 0) / 4294967296;
  const p = Array.from({ length: 256 }, (_, i) => i);
  for (let i = 255; i > 0; i--) { const j = Math.floor(rnd() * (i + 1)); [p[i], p[j]] = [p[j], p[i]]; }
  for (let i = 0; i < 512; i++) perm[i] = p[i & 255];
  const fade = (t) => t * t * (3 - 2 * t);
  return (x, y) => {
    const xi = Math.floor(x) & 255, yi = Math.floor(y) & 255;
    const xf = x - Math.floor(x), yf = y - Math.floor(y);
    const a = perm[perm[xi] + yi] / 255, b = perm[perm[xi + 1] + yi] / 255;
    const c = perm[perm[xi] + yi + 1] / 255, d = perm[perm[xi + 1] + yi + 1] / 255;
    const u = fade(xf), v = fade(yf);
    return (a + (b - a) * u) * (1 - v) + (c + (d - c) * u) * v;
  };
}

function hexToRgb(hex) { return [(hex >> 16) & 255, (hex >> 8) & 255, hex & 255]; }

/**
 * מצייר סיבים: הציר האנכי של הקנבס הוא כיוון הסיבים. `strength` — כמה
 * הסיבים בולטים (עץ 1, מלמין 0.45); `rings` — צפיפות הטבעות.
 */
function drawWood(ctx, color, { strength = 1, rings = 9, seed = 7 } = {}) {
  const [r, g, b] = hexToRgb(color);
  const n = noise2(seed);
  const img = ctx.createImageData(SIZE, SIZE);
  const d = img.data;
  for (let y = 0; y < SIZE; y++) {
    for (let x = 0; x < SIZE; x++) {
      const u = x / SIZE, v = y / SIZE;
      // עיוות הפסים לאורך הציר האנכי: הסיבים מתפתלים מעט, כמו בעץ אמיתי.
      const warp = n(u * 3 + 10, v * 1.2) * 1.6 + n(u * 12, v * 4) * 0.25;
      const ring = Math.sin((u * rings + warp) * Math.PI * 2);
      const fine = n(u * 90, v * 6) - 0.5;              // סיבים דקים
      const pores = n(u * 200 + 50, v * 40) > 0.78 ? -0.08 : 0;
      const shade = 1 + strength * (0.055 * ring + 0.07 * fine + pores);
      const i = (y * SIZE + x) * 4;
      d[i] = Math.max(0, Math.min(255, r * shade));
      d[i + 1] = Math.max(0, Math.min(255, g * shade));
      d[i + 2] = Math.max(0, Math.min(255, b * shade));
      d[i + 3] = 255;
    }
  }
  ctx.putImageData(img, 0, 0);
}

/** צבע: אחיד, עם רעש זעיר כדי שלא ייראה פלסטיק. */
function drawPaint(ctx, color) {
  const [r, g, b] = hexToRgb(color);
  const n = noise2(3);
  const img = ctx.createImageData(SIZE, SIZE);
  const d = img.data;
  for (let y = 0; y < SIZE; y++) for (let x = 0; x < SIZE; x++) {
    const shade = 1 + (n(x / 9, y / 9) - 0.5) * 0.035;
    const i = (y * SIZE + x) * 4;
    d[i] = r * shade; d[i + 1] = g * shade; d[i + 2] = b * shade; d[i + 3] = 255;
  }
  ctx.putImageData(img, 0, 0);
}

const baseCache = new Map();   // material id → { tex, mm, version }

/** הטקסטורה הבסיסית של חומר (משותפת), ומה מ"מ חזרה אחת שלה מכסה. */
function baseTexture(m) {
  const THREE = T();
  const key = m.id;
  const sig = `${m.image ? m.image.length : ''}|${m.color}|${m.finish}|${m.grainMm}|${m.imageMm}`;
  const hit = baseCache.get(key);
  if (hit && hit.sig === sig) return hit;

  let tex, mm;
  if (m.image) {
    tex = new THREE.TextureLoader().load(m.image);
    mm = m.imageMm || 600;
  } else if (m.finish === 'wood' || m.finish === 'melamine') {
    const c = document.createElement('canvas');
    c.width = c.height = SIZE;
    drawWood(c.getContext('2d'), m.color ?? 0xc9a46c, m.finish === 'wood'
      ? { strength: 1, rings: 7, seed: hash(m.id) }
      : { strength: 0.45, rings: 11, seed: hash(m.id) });
    tex = new THREE.CanvasTexture(c);
    mm = m.grainMm || 600;
  } else {
    const c = document.createElement('canvas');
    c.width = c.height = SIZE;
    drawPaint(c.getContext('2d'), m.color ?? 0xcccccc);
    tex = new THREE.CanvasTexture(c);
    mm = 600;
  }
  tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
  tex.anisotropy = 4;
  const entry = { tex, mm, sig };
  if (hit) hit.tex.dispose();
  baseCache.set(key, entry);
  return entry;
}

function hash(s) { let h = 2166136261; for (const ch of s) h = Math.imul(h ^ ch.charCodeAt(0), 16777619); return h >>> 0; }

/**
 * טקסטורה לחלק: עותק של הבסיס עם repeat לפי מידות החיתוך וסיבוב כך
 * שהסיבים (הציר האנכי של הקנבס) ירוצו לאורך ציר הסיבים של החלק על פניו
 * הראשיות. ב-BoxGeometry: פאת ±z — U לאורך x, V לאורך y; פאת ±x — U לאורך z,
 * V לאורך y; פאת ±y — U לאורך x, V לאורך z.
 */
export function textureForPart(m, part, cut) {
  const { tex, mm } = baseTexture(m);
  const t = tex.clone();
  t.needsUpdate = true;
  const face = part.axis;
  const vAxis = face === 'y' ? 'z' : 'y';           // ציר ה-V על הפאה הראשית
  const grainOnV = part.grain === vAxis;
  // הסיבים בקנבס אנכיים (V). אם ציר הסיבים של החלק הוא U — מסובבים 90°.
  t.rotation = grainOnV ? 0 : Math.PI / 2;
  t.center.set(0.5, 0.5);
  const along = cut.l / mm, across = cut.w / mm;
  if (grainOnV) t.repeat.set(across, along); else t.repeat.set(along, across);
  return t;
}

/** דוגמית לחומר למסך הספרייה: אותה טקסטורה, כ-data URL קטן. */
const swatchCache = new Map();
export function swatchDataUrl(m) {
  if (m.image) return m.image;
  const sig = `${m.color}|${m.finish}`;
  const hit = swatchCache.get(m.id);
  if (hit && hit.sig === sig) return hit.url;
  const c = document.createElement('canvas');
  c.width = c.height = SIZE;
  const ctx = c.getContext('2d');
  if (m.finish === 'wood' || m.finish === 'melamine') {
    drawWood(ctx, m.color ?? 0xc9a46c, m.finish === 'wood' ? { strength: 1, rings: 7, seed: hash(m.id) } : { strength: 0.45, rings: 11, seed: hash(m.id) });
  } else drawPaint(ctx, m.color ?? 0xcccccc);
  // דוגמית קטנה: מקטינים ל-128 כדי לא להחזיק 512² לכל כרטיס
  const small = document.createElement('canvas');
  small.width = small.height = 128;
  small.getContext('2d').drawImage(c, 0, 0, 128, 128);
  const url = small.toDataURL('image/jpeg', 0.8);
  swatchCache.set(m.id, { sig, url });
  return url;
}

/** מנקה את המטמון — אחרי שינוי בספריית החומרים. */
export function clearTextures() {
  for (const e of baseCache.values()) e.tex.dispose();
  baseCache.clear();
}

/** מקטין תמונה שהועלתה ל-1024 לכל היותר ומחזיר data URL (JPEG) — לאחסון ולטקסטורה. */
export function imageToDataUrl(file, max = 1024) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const k = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      resolve(c.toDataURL('image/jpeg', 0.85));
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('לא הצלחתי לקרוא את התמונה')); };
    img.src = url;
  });
}
