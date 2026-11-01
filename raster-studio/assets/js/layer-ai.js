// בינה מלאכותית לשכבות (docs/layer-ai.md) — עיבוד הפיקסלים מעל שכבת הספקים של ענן החבילה (drives.ai), בלי לדעת מי הספק.
// הסרת רקע, פירוק לשכבות, יצירה (גם שקופה) ומילוי. הכול PNG ב-sRGB, אלפא ישר, בקואורדינטות של תמונת הקלט.
// המודול עושה בעצמו: הקטנה לגודל שהספק מקבל (עד 4096 בצלע), החזרה לגודל המקור, מסכה מהאלפא, הרכבת שכבות (box, label)
// והשלמת הרקע. בלי חיבור לענן / בלי ספק — capabilities() הכול false, וכל פעולה זורקת Error עם code (no-provider…).
//
//   const ai = createLayerAI({ cloud: drives });
//   await ai.capabilities();                                   // { removeBackground, decompose, generate, fill }
//   await ai.removeBackground(blob, { signal });               // → { png, mask }  (mask — גוונים, לבן = נשאר)
//   await ai.decompose(blob, { maxLayers: 6, signal });        // → { background, layers: [{ png, box: [x,y,w,h], label }] }
//   await ai.generate(prompt, { width, height, transparent, signal });   // → { png }
//   await ai.fill(blob, maskBlob, { prompt, signal });         // → { png }  (המסכה הלבנה מתמלאת)

export const AI_MAX_SIDE = 4096;

/** שגיאה עם code, כמו ב-cloud.ai */
export class LayerAIError extends Error {
  constructor(message, code) { super(message); this.code = code; }
}

const decode = blob => createImageBitmap(blob, { premultiplyAlpha: 'none', colorSpaceConversion: 'none' });

async function toPNG(source, w, h) {
  const c = new OffscreenCanvas(w, h);
  const ctx = c.getContext('2d');
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(source, 0, 0, w, h);
  return c.convertToBlob({ type: 'image/png' });
}

/** קלט לספק: עד AI_MAX_SIDE בצלע. מחזיר את הבלוב (מוקטן אם צריך) ואת גודל המקור */
async function fitInput(blob) {
  const bmp = await decode(blob);
  const { width: w, height: h } = bmp;
  const k = Math.min(1, AI_MAX_SIDE / Math.max(w, h));
  const out = k < 1 ? await toPNG(bmp, Math.round(w * k), Math.round(h * k)) : blob;
  bmp.close();
  return { blob: out, w, h };
}

/** תוצאה מהספק → בדיוק w×h (ספקים מחזירים לפעמים גודל אחר / מוקטן) */
async function toSize(png, w, h) {
  const bmp = await decode(png);
  const same = bmp.width === w && bmp.height === h;
  const out = same ? png : await toPNG(bmp, w, h);
  bmp.close();
  return out;
}

/** המסכה מהאלפא של PNG: לבן = נשאר, שחור = שקוף (PNG אטום בגוני אפור) */
async function alphaMask(png) {
  const bmp = await decode(png);
  const { width: w, height: h } = bmp;
  const c = new OffscreenCanvas(w, h);
  const ctx = c.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(bmp, 0, 0);
  bmp.close();
  const img = ctx.getImageData(0, 0, w, h);
  const d = img.data;
  for (let i = 0; i < d.length; i += 4) { const a = d[i + 3]; d[i] = d[i + 1] = d[i + 2] = a; d[i + 3] = 255; }
  ctx.putImageData(img, 0, 0);
  return c.convertToBlob({ type: 'image/png' });
}

/** התמונה כפול מסכת גוונים (לבן = נשאר) — חיתוך של עצם; מחזיר גם את המלבן החוסם (או null — ריק) */
async function cutout(image, mask, w, h) {
  const [ib, mb] = await Promise.all([decode(image), decode(mask)]);
  const c = new OffscreenCanvas(w, h);
  const ctx = c.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(mb, 0, 0, w, h);
  const m = ctx.getImageData(0, 0, w, h).data;
  ctx.clearRect(0, 0, w, h);
  ctx.drawImage(ib, 0, 0, w, h);
  ib.close(); mb.close();
  const img = ctx.getImageData(0, 0, w, h);
  const d = img.data;
  let x0 = w, y0 = h, x1 = -1, y1 = -1;
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const i = (y * w + x) * 4;
    const a = Math.round(d[i + 3] * m[i] / 255);   // הגוון (R) של המסכה
    d[i + 3] = a;
    if (a) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
  }
  if (x1 < 0) return null;
  ctx.putImageData(img, 0, 0);
  return { png: await c.convertToBlob({ type: 'image/png' }), box: [x0, y0, x1 - x0 + 1, y1 - y0 + 1] };
}

/** איחוד מסכות גוונים (המקסימום) — מה שצריך להשלים ברקע */
async function unionMask(masks, w, h) {
  const c = new OffscreenCanvas(w, h);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#000';
  ctx.fillRect(0, 0, w, h);
  ctx.globalCompositeOperation = 'lighten';
  for (const m of masks) { const b = await decode(m); ctx.drawImage(b, 0, 0, w, h); b.close(); }
  return c.convertToBlob({ type: 'image/png' });
}

export function createLayerAI({ cloud = null } = {}) {
  const none = { removeBackground: false, decompose: false, generate: false, fill: false };

  async function caps() {
    if (!cloud?.ai) return { ...none, providers: [], connected: false };
    let c;
    try { c = await cloud.ai.capabilities(); } catch { return { ...none, providers: [], connected: false }; }
    const t = c?.tasks ?? {};
    return {
      connected: !!c?.connected,
      removeBackground: !!t['remove-background'],
      decompose: !!t.segment,
      generate: !!t['generate-image'],
      fill: !!t.inpaint,
      providers: c?.providers ?? [],
    };
  }

  async function run(task, input, signal) {
    if (!cloud?.ai) throw new LayerAIError('אין חיבור לענן החבילה', 'no-provider');
    try {
      return await cloud.ai.run(task, input, { signal });
    } catch (e) {
      if (e?.name === 'AbortError' || signal?.aborted) throw new LayerAIError('בוטל', 'aborted');
      throw new LayerAIError(e?.message || 'תקלה אצל הספק', e?.code || 'provider');
    }
  }

  async function removeBackground(blob, { signal } = {}) {
    const inp = await fitInput(blob);
    const r = await run('remove-background', { image: inp.blob }, signal);
    const png = await toSize(r.png, inp.w, inp.h);
    return { png, mask: await alphaMask(png) };
  }

  async function generate(prompt, { width = 1024, height = 1024, transparent = false, style, signal } = {}) {
    const k = Math.min(1, AI_MAX_SIDE / Math.max(width, height));
    const r = await run('generate-image', { prompt, size: [Math.round(width * k), Math.round(height * k)], transparent, ...(style ? { style } : {}) }, signal);
    let png = await toSize(r.png, width, height);
    // ביקשנו שקוף והספק לא יודע — מסירים רקע בעצמנו
    if (transparent && r.transparent === false) png = (await removeBackground(png, { signal })).png;
    return { png };
  }

  async function fill(blob, maskBlob, { prompt = '', signal } = {}) {
    const inp = await fitInput(blob);
    // התמונה הוקטנה — המסכה לאותו גודל
    let mask = maskBlob;
    if (inp.blob !== blob) {
      const [ib, mb] = await Promise.all([decode(inp.blob), decode(maskBlob)]);
      mask = await toPNG(mb, ib.width, ib.height);
      ib.close(); mb.close();
    }
    const r = await run('inpaint', { image: inp.blob, mask, prompt }, signal);
    return { png: await toSize(r.png, inp.w, inp.h) };
  }

  async function decompose(blob, { maxLayers = 6, signal } = {}) {
    const inp = await fitInput(blob);
    const r = await run('segment', { image: inp.blob }, signal);
    const masks = (r.masks ?? []).slice(0, maxLayers);
    const layers = [];
    const full = [];
    for (const [i, m] of masks.entries()) {
      const mk = await toSize(m.png, inp.w, inp.h);
      const cut = await cutout(blob, mk, inp.w, inp.h);
      if (!cut) continue;
      full.push(mk);
      layers.push({ png: cut.png, box: cut.box, label: m.label || `עצם ${i + 1}` });
    }
    // הרקע: בלי העצמים, והחורים מושלמים (אם הספק יודע); אחרת — התמונה כמו שהיא
    let background = blob;
    if (full.length && (await caps()).fill) background = (await fill(blob, await unionMask(full, inp.w, inp.h), { prompt: 'background', signal })).png;
    return { background, layers };
  }

  return { capabilities: caps, removeBackground, decompose, generate, fill };
}
