// בינה מלאכותית ישירה (מ4) — דרך שכבת הספקים של ענן החבילה (suite-cloud/docs/spec-3-ai.md).
// כל קוד הספקים, המפתחות והמכסה — בענן. כאן רק: מה לבקש, ואיך התוצאה נכנסת לשכבות.
// בלי חיבור לענן — capabilities הכול false, והמסלול הידני (סקיל לצ'אט) נשאר.

import { createDrives } from './drives/drives.js';
import { FONTS } from './fonts.js';
import { blobToImage, canvasToBlob } from './io.js';
import { log } from './log.js';

export const drives = createDrives({ endpoint: 'api.php' });

const NONE = { tasks: {}, quota: null, connected: false };
let capsP = null;

/** { connected, name, tasks: {…: bool}, quota } — נשמר עד force. לא זורק: תקלה = לא זמין. */
export function aiCaps(force = false) {
    if (force || !capsP) {
        capsP = (async () => {
            const st = await drives.status().catch(() => null);
            if (!st?.suite?.connected) return { ...NONE, status: st ? 'off' : 'error' };
            const c = await drives.ai.capabilities().catch(e => ({ ...NONE, error: e.message }));
            return { ...c, connected: true, name: st.suite.name, cloud: st.suite.cloud_id || '' };
        })();
        capsP.then(c => log('ai-caps', { connected: c.connected ? 1 : 0, tasks: Object.keys(c.tasks || {}).filter(k => c.tasks[k]).join(',') }));
    }
    return capsP;
}

/** הודעה בעברית לשגיאה מהענן. */
export function aiMessage(e) {
    return ({
        'not-connected': 'לא מחובר לענן החבילה',
        'no-provider': 'אין ספק בינה מלאכותית לפעולה הזו — בעל המערכת מגדיר בענן',
        'not-allowed': 'אין לך הרשאה לבינה המלאכותית בענן',
        quota: 'נגמרה המכסה — אפשר להמשיך דרך הצ\'אט (🤖 סקיל לצ\'אט)',
        'too-big': 'התמונה גדולה מדי לשליחה',
        aborted: 'בוטל',
        network: 'אין חיבור לענן',
    })[e?.code] || e?.message || 'הפעולה נכשלה';
}

async function run(task, input, opt) {
    const t0 = Date.now();
    try {
        const r = await drives.ai.run(task, input, opt);
        log('ai-run', { task, ms: Date.now() - t0, provider: r.provider || '' });
        return r;
    } catch (e) {
        log('ai-fail', { task, code: e.code || '', msg: e.message });
        throw e;
    }
}

// ─── עצב לי: מתיאור לעיצוב בשכבות ─────────────────────────────────────────

const PLAN_SCHEMA = {
    type: 'object', required: ['title', 'page', 'background', 'layers'],
    properties: {
        title: { type: 'string' },
        page: { type: 'object', properties: { background: { type: 'string' } } },
        background: { type: 'object', required: ['prompt'], properties: { prompt: { type: 'string' } } },
        elements: { type: 'array', items: { type: 'object', required: ['id', 'prompt', 'x', 'y', 'w', 'h'],
            properties: { id: { type: 'string' }, name: { type: 'string' }, prompt: { type: 'string' },
                x: { type: 'number' }, y: { type: 'number' }, w: { type: 'number' }, h: { type: 'number' }, rotation: { type: 'number' } } } },
        layers: { type: 'array', items: { type: 'object', required: ['type'] } },
    },
};

function planSystem(w, h) {
    return `You design a graphic for a Hebrew (RTL) layered image editor. The page is ${w}×${h} px.
Answer with JSON only:
- "title": a short Hebrew name for the design.
- "page": { "background": a hex colour close to the background image }.
- "background": { "prompt": an English prompt for the background image — the scene only, with calm, low-detail areas
  where the text will go. NEVER any text, letters, numbers, logos or watermarks. No real people's faces. }
- "elements": 0–3 separate objects the user may want to move (a balloon, a gift, a pomegranate…). Each: id, a Hebrew
  "name", an English "prompt" for ONE object alone on a transparent background (no text), and its place on the page
  (x, y, w, h in page pixels from the top-left).
- "layers": the text and decorations, in the editor's design.json layer format:
  · text: { "id", "type": "text", "role": "title"|"subtitle"|"body"|"date"|"cta"|"contact", "text", "x", "y", "w", "h",
    "style": { "font", "size", "weight", "color", "align": "center"|"right"|"left", "dir": "rtl", "lineHeight",
    "shadow"?: {x,y,blur,color}, "stroke"?: {color,width}, "background"?: {color,radius,padding},
    "gradient"?: {angle, stops:[{color,at}]}, "glow"?: {color,blur} } }
  · decoration: { "id", "type": "svg", "name", "x", "y", "w", "h", "svg": an inline SVG string using only path, rect,
    circle, ellipse, line, polyline, polygon and g, solid colours, no text, no images }. 1–3 decorations that fit.
All the text the user asked for must appear, in their language. Coordinates are page pixels from the top-left.
Fonts — only: ${FONTS.map(f => f.family).join(', ')}. Readable contrast (4.5:1) against the quiet areas.
Leave a margin of at least 5% from the page edges.`;
}

/**
 * מתיאור — לקבצים שהייבוא הרגיל מכיר (design.json + התמונות), כדי שהעיצוב ייכנס בדיוק כמו מהצ'אט.
 * onStep(text) — התקדמות. מחזיר File[].
 */
export async function designFromBrief({ brief, w, h }, { onStep, signal } = {}) {
    onStep?.('מתכנן את העיצוב…');
    const { json: plan } = await run('complete', { system: planSystem(w, h), prompt: brief, schema: PLAN_SCHEMA }, { signal });
    if (!plan || typeof plan !== 'object' || !plan.background?.prompt) throw new Error('הבינה המלאכותית לא החזירה תוכנית עיצוב');
    const elements = (Array.isArray(plan.elements) ? plan.elements : []).slice(0, 3).filter(e => e?.prompt);

    let done = 0;
    const total = 1 + elements.length;
    const step = () => onStep?.(`יוצר תמונות… ${done}/${total}`);
    step();
    const noText = ' No text, letters, numbers, logos or watermarks.';
    const bgP = run('generate-image', { prompt: plan.background.prompt + noText, size: `${w}x${h}` }, { signal })
        .then(r => { done++; step(); return r; });
    // אלמנט שנכשל לא מפיל את העיצוב — רק נשמט
    const elP = elements.map(e => run('generate-image', {
        prompt: e.prompt + ' Isolated object on a fully transparent background.' + noText,
        size: `${Math.round(e.w) || 512}x${Math.round(e.h) || 512}`, transparent: true }, { signal })
        .then(r => { done++; step(); return r; }, () => { done++; step(); return null; }));
    const [bg, ...els] = await Promise.all([bgP, ...elP]);

    const files = [new File([bg.png], 'background.png', { type: 'image/png' })];
    const assets = { background: { type: 'image', file: 'background.png' } };
    const layers = [{ id: 'bg', type: 'image', asset: 'background', role: 'background', x: 0, y: 0, w, h, locked: true, name: 'רקע' }];
    elements.forEach((e, i) => {
        if (!els[i]) return;
        const file = `element-${i + 1}.png`;
        files.push(new File([els[i].png], file, { type: 'image/png' }));
        assets['el' + (i + 1)] = { type: 'image', file };
        layers.push({ id: String(e.id || 'el' + (i + 1)).replace(/[^\w-]/g, '').slice(0, 40) || 'el' + (i + 1), type: 'image',
            asset: 'el' + (i + 1), role: 'decoration', name: e.name || 'אלמנט', x: e.x, y: e.y, w: e.w, h: e.h, rotation: e.rotation || 0 });
    });
    const design = {
        version: 1,
        source: { app: 'greeting-cards', ai: bg.provider || 'cloud' },
        title: plan.title || 'עיצוב',
        page: { width: w, height: h, unit: 'px', background: /^#[0-9a-f]{6}$/i.test(plan.page?.background || '') ? plan.page.background : '#ffffff' },
        assets,
        layers: [...layers, ...(Array.isArray(plan.layers) ? plan.layers : [])],
    };
    files.unshift(new File([JSON.stringify(design)], 'design.json', { type: 'application/json' }));
    return files;
}

// ─── תמונה מתיאור ─────────────────────────────────────────────────────────

export async function imageFromPrompt(prompt, { transparent = true, size = '1024x1024' } = {}, opt) {
    const r = await run('generate-image', {
        prompt: prompt + (transparent ? ' Isolated on a fully transparent background.' : '') + ' No text, letters, numbers, logos or watermarks.',
        size, transparent }, opt);
    return r.png;
}

// ─── הסרת רקע ─────────────────────────────────────────────────────────────

export async function removeBackground(blob, opt) {
    return (await run('remove-background', { image: await toPng(blob) }, opt)).png;
}

// ─── פירוק תמונה שטוחה לשכבות ─────────────────────────────────────────────

/**
 * תמונה אחת ← רקע מושלם + עצמים שקופים. segment נותן מסכה לכל עצם (לבן = העצם); כל עצם נחתך מהמקור לפי המסכה,
 * והאזורים שלהם ממולאים ברקע (inpaint) — אם אין inpaint, הרקע נשאר המקור (העצמים מעליו, כפולים).
 * מחזיר { background: Blob|null, parts: [{ png, label, box:[x,y,w,h] בפיקסלי המקור }], W, H }.
 */
export async function decompose(blob, { inpaint = true, onStep, signal } = {}) {
    onStep?.('מזהה עצמים…');
    const png = await toPng(blob);
    const img = await blobToImage(png);
    const W = img.naturalWidth, H = img.naturalHeight;
    const { masks = [] } = await run('segment', { image: png }, { signal });
    const mimgs = await Promise.all(masks.map(m => blobToImage(m.png)));

    // הניתוח (שטח, תיבה, חפיפה) — ברזולוציה מוקטנת, כדי לא להחזיק מסכה מלאה לכל עצם בזיכרון של טלפון
    const s = Math.min(1, 640 / Math.max(W, H));
    const aw = Math.max(1, Math.round(W * s)), ah = Math.max(1, Math.round(H * s));
    const read = mimgs.map((mi, k) => {
        const c = canvas(aw, ah);
        c.ctx.drawImage(mi, 0, 0, aw, ah);
        const d = c.ctx.getImageData(0, 0, aw, ah).data;
        const on = new Uint8Array(aw * ah);
        let n = 0, x0 = aw, y0 = ah, x1 = -1, y1 = -1;
        for (let p = 0; p < on.length; p++) {
            if (d[p * 4] > 127) { on[p] = 1; n++; const x = p % aw, y = (p / aw) | 0; if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
        }
        // התיבה בפיקסלי המקור, עם שוליים של פיקסל מוקטן אחד
        const bx = Math.max(0, Math.floor((x0 - 1) / s)), by = Math.max(0, Math.floor((y0 - 1) / s));
        const box = [bx, by, Math.min(W, Math.ceil((x1 + 2) / s)) - bx, Math.min(H, Math.ceil((y1 + 2) / s)) - by];
        return { k, on, n, label: masks[k].label || '', box };
    });
    // בלי פירורים (פחות מ-1%) ובלי "הרקע" עצמו (יותר מ-60%); גדול קודם; מסכה שרובה כבר נלקחה — מדלגים
    const taken = new Uint8Array(aw * ah);
    const kept = [];
    for (const m of read.filter(m => m.n > aw * ah * 0.01 && m.n < aw * ah * 0.6).sort((a, b) => b.n - a.n)) {
        let over = 0;
        for (let p = 0; p < m.on.length; p++) if (m.on[p] && taken[p]) over++;
        if (over > m.n * 0.5) continue;
        for (let p = 0; p < m.on.length; p++) if (m.on[p]) taken[p] = 1;
        kept.push(m);
        if (kept.length >= 8) break;
    }
    if (!kept.length) throw new Error('לא נמצאו עצמים להפרדה בתמונה');
    onStep?.(`נמצאו ${kept.length} עצמים — חותך…`);

    // החיתוך — בגודל מלא, רק בתוך התיבה: המסכה של הספק היא השקיפות
    const parts = [];
    for (const m of kept) {
        const [bx, by, bw, bh] = m.box;
        const c = canvas(bw, bh), mc = canvas(bw, bh);
        c.ctx.drawImage(img, bx, by, bw, bh, 0, 0, bw, bh);
        mc.ctx.drawImage(mimgs[m.k], bx * mimgs[m.k].naturalWidth / W, by * mimgs[m.k].naturalHeight / H,
            bw * mimgs[m.k].naturalWidth / W, bh * mimgs[m.k].naturalHeight / H, 0, 0, bw, bh);
        const id = c.ctx.getImageData(0, 0, bw, bh), md = mc.ctx.getImageData(0, 0, bw, bh).data;
        for (let i = 0; i < id.data.length; i += 4) id.data[i + 3] = Math.min(id.data[i + 3], md[i]);
        c.ctx.putImageData(id, 0, 0);
        parts.push({ png: await canvasToBlob(c.el), label: m.label, box: m.box });
    }
    // קטנים למעלה — עצם קטן שבתוך גדול נשאר גלוי
    parts.reverse();

    let background = null;
    if (inpaint) {
        onStep?.('משלים את הרקע מאחורי העצמים…');
        // איחוד המסכות, מורחב מעט — כדי שלא יישאר קו מתאר של העצם ברקע
        const grow = canvas(W, H);
        grow.ctx.fillStyle = '#000'; grow.ctx.fillRect(0, 0, W, H);
        grow.ctx.globalCompositeOperation = 'lighten';
        grow.ctx.filter = `blur(${Math.max(2, Math.round(Math.max(W, H) / 200))}px)`;
        for (const m of kept) grow.ctx.drawImage(mimgs[m.k], 0, 0, W, H);
        grow.ctx.filter = 'none';
        grow.ctx.globalCompositeOperation = 'source-over';
        const gd = grow.ctx.getImageData(0, 0, W, H);
        for (let i = 0; i < gd.data.length; i += 4) { const v = gd.data[i] > 8 ? 255 : 0; gd.data[i] = gd.data[i + 1] = gd.data[i + 2] = v; gd.data[i + 3] = 255; }
        grow.ctx.putImageData(gd, 0, 0);
        background = (await run('inpaint', { image: png, mask: await canvasToBlob(grow.el),
            prompt: 'Fill the masked areas with the surrounding background so no object remains. Keep everything else identical. No text.' }, { signal })).png;
    }
    return { background, parts, W, H };
}

// ─── עזר ──────────────────────────────────────────────────────────────────

function canvas(w, h) {
    const el = document.createElement('canvas');
    el.width = w; el.height = h;
    return { el, ctx: el.getContext('2d', { willReadFrequently: true }) };
}

/** הספקים מקבלים PNG; JPEG/WebP מומרים. גדול מ-2048 — מוקטן (חוסך זמן וכסף, ואין בו צורך לפירוק). */
async function toPng(blob) {
    const img = await blobToImage(blob);
    const k = Math.min(1, 2048 / Math.max(img.naturalWidth, img.naturalHeight));
    if (blob.type === 'image/png' && k === 1) return blob;
    const c = canvas(Math.round(img.naturalWidth * k), Math.round(img.naturalHeight * k));
    c.ctx.drawImage(img, 0, 0, c.el.width, c.el.height);
    return canvasToBlob(c.el);
}
