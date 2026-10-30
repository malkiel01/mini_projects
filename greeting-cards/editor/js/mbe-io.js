// ‎.mbe — הקובץ של חבילת היצירה (creative-suite/docs/mbe-format.md), דרך הליבה (js/mbe — עותק מסונכרן; לא עורכים).
//
// שמירה: מסמך `kind: "design"`, משטח אחד מסוג page, יחידות pt (‏1px = 0.75pt — הכרעת המתכלל). כל שכבה שלנו — שכבה שם:
//   תמונה  → שכבת raster: PNG בגודל הדף, עם המיקום, החיתוך, המסכה, ההיפוך והפילטרים "אפויים" בה — כך הרסטר עורך פיקסלים
//   צורה / אלמנט / אייקון → שכבת vector עם נתיב (M L C Q Z בלבד — pathnorm.js)
//   טקסט   → שכבת vector עם פריט text (רקע לטקסט — קבוצה: מלבן + טקסט)
//   צבע / מדרג הדף → שכבת vector תחתונה בגודל הדף
// והמסמך המקורי שלנו כמו שהוא — ב-apps/greeting-cards/design.json, עם התמונות המקוריות ב-media/. כשהקובץ חוזר בלי
// שאפליקציה אחרת שמרה אותו — נפתח בדיוק כמו שנשמר. אחרי עריכה ברסטר / בווקטורי — נקרא מהשכבות (readForeign).

import { open, create, save, PREVIEW } from './mbe/mbe.js';
import { normalizeDoc, newDoc, uid, clone } from './doc.js';
import { assets } from './db.js';
import { ensureDocFonts, FONTS, DEFAULT_FONT } from './fonts.js';
import { Canvas, gradientAttrs } from './canvas.js';
import { ICONS } from './icons.js';
import { ELEMENTS, scalePath } from './elements.js';
import { toMbePath, absolutize, layerMatrix, mul } from './pathnorm.js';
import { blobToImage, canvasToBlob } from './io.js';

export const APP_ID = 'greeting-cards';
const APP_VERSION = '1';
export const PT = 0.75;                 // pt לכל px
const SAVED = 'design.json';            // ב-apps/greeting-cards/

const enc = s => new TextEncoder().encode(s);
const dec = b => new TextDecoder().decode(b);
const r2 = v => Math.round(v * 100) / 100;

/** #rrggbb(aa) → { color, opacity } */
function paint(hex) {
    if (typeof hex !== 'string' || !/^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(hex)) return null;
    const a = hex.length === 9 ? parseInt(hex.slice(7), 16) / 255 : 1;
    return a === 0 ? null : { color: hex.slice(0, 7).toLowerCase(), opacity: r2(a) };
}

/** המדרג שלנו (זווית CSS) → מדרג של הפורמט, בקואורדינטות הנתיב (pt). */
function gradient(g, x, y, w, h) {
    if (!g?.colors?.length) return null;
    const stops = g.colors.map((c, i) => ({ at: r2(i / (g.colors.length - 1)), color: c.slice(0, 7), opacity: paint(c)?.opacity ?? 0 }));
    const a = gradientAttrs(g, w, h);
    if (g.type === 'radial') return { type: 'radial', center: [r2(x + w / 2), r2(y + h / 2)], radius: r2(Math.hypot(w, h) / 2), stops };
    const s = a.fillLinearGradientStartPoint, e = a.fillLinearGradientEndPoint;
    return { type: 'linear', from: [r2(x + s.x), r2(y + s.y)], to: [r2(x + e.x), r2(y + e.y)], stops };
}

const rectD = (w, h, r = 0) => {
    r = Math.min(r, w / 2, h / 2);
    if (!r) return `M0 0 H${w} V${h} H0 Z`;
    return `M${r} 0 H${w - r} A${r} ${r} 0 0 1 ${w} ${r} V${h - r} A${r} ${r} 0 0 1 ${w - r} ${h} H${r} A${r} ${r} 0 0 1 0 ${h - r} V${r} A${r} ${r} 0 0 1 ${r} 0 Z`;
};
const ellipseD = (w, h) => `M0 ${h / 2} A${w / 2} ${h / 2} 0 1 0 ${w} ${h / 2} A${w / 2} ${h / 2} 0 1 0 0 ${h / 2} Z`;
function polyD(w, h, n, inner) {
    const pts = [];
    const k = inner ? n * 2 : n;
    for (let i = 0; i < k; i++) {
        const rr = inner && i % 2 ? inner : 1;
        const a = -Math.PI / 2 + (i * 2 * Math.PI) / k;
        pts.push([w / 2 + (w / 2) * rr * Math.cos(a), h / 2 + (h / 2) * rr * Math.sin(a)]);
    }
    return 'M' + pts.map(p => p.join(' ')).join(' L') + ' Z';
}

/** הנתיב של שכבת צורה / אלמנט / אייקון, בקואורדינטות השכבה (px), ואיך לצבוע אותו. */
function shapeGeometry(l) {
    const { w, h } = l;
    if (l.type === 'icon') {
        const ic = ICONS[l.icon] || ICONS.star;
        const k = Math.min(w, h) / 24;
        const filled = !!(l.filled && ic.filled);
        return { d: ic[filled ? 'filled' : 'd'], pre: [k, 0, 0, k, (w - 24 * k) / 2, (h - 24 * k) / 2], fill: filled ? l.color : null,
            stroke: filled ? null : { color: l.color, width: (l.strokeWidth || 2) * k, cap: 'round', join: 'round' } };
    }
    const fill = l.fill;
    const stroke = l.stroke?.width ? { color: l.stroke.color, width: l.stroke.width } : null;
    switch (l.shape) {
        case 'element': {
            const el = ELEMENTS[l.element] || ELEMENTS['badge-scallop'];
            return { d: scalePath(el.d, w / el.vb[0], h / el.vb[1]), fill, stroke, round: true };
        }
        case 'path': return { d: scalePath(l.path, w / l.vb[0], h / l.vb[1]), fill, stroke };
        case 'ellipse': return { d: ellipseD(w, h), fill, stroke };
        case 'star': return { d: polyD(w, h, l.points || 5, 0.4), fill, stroke };
        case 'polygon': return { d: polyD(w, h, l.points || 6), fill, stroke };
        case 'arrow': return { d: `M0 ${h * 0.33} H${w - h} V0 L${w} ${h / 2} L${w - h} ${h} V${h * 0.67} H0 Z`, fill, stroke: null };
        default: return { d: rectD(w, h, l.radius || 0), fill, stroke };
    }
}

const fx = s => (s && paint(s.color) ? [{ type: 'shadow', dx: r2(s.x * PT), dy: r2(s.y * PT), blur: r2(s.blur * PT), color: s.color.slice(0, 7), opacity: paint(s.color).opacity }] : undefined);

/** המטריצה של השכבה בעמוד (px → pt): מיקום, סיבוב והיפוך. */
function layerToPage(l) {
    let m = layerMatrix(l.x, l.y, l.rotation || 0);
    if (l.flipX || l.flipY) m = mul(m, [l.flipX ? -1 : 1, 0, 0, l.flipY ? -1 : 1, l.flipX ? l.w : 0, l.flipY ? l.h : 0]);
    return mul([PT, 0, 0, PT, 0, 0], m);
}

function vectorItem(l) {
    const g = shapeGeometry(l);
    const m = g.pre ? mul(layerToPage(l), g.pre) : layerToPage(l);
    const item = { id: l.id + '-p', d: toMbePath(g.d, m), fillRule: 'nonzero' };
    if (l.gradient && ['rect', 'ellipse', 'element', 'path'].includes(l.shape)) {
        // המדרג — בקואורדינטות הדף; לשכבה מסובבת הוא קירוב (לפי התיבה בלי הסיבוב)
        item.fill = { gradient: gradient(l.gradient, l.x * PT, l.y * PT, l.w * PT, l.h * PT), opacity: 1 };
    } else item.fill = paint(g.fill);
    if (g.stroke && paint(g.stroke.color)) {
        const lp = layerToPage(l);
        item.stroke = { ...paint(g.stroke.color), width: r2(g.stroke.width * Math.hypot(lp[0], lp[1])),
            cap: g.stroke.cap || (g.round ? 'round' : 'butt'), join: g.stroke.join || (g.round ? 'round' : 'miter') };
    } else item.stroke = null;
    const e = fx(l.shadow);
    if (e) item.effects = e;
    return item;
}

function textItems(l, measured) {
    const s = l.style;
    const box = [r2(l.x * PT), r2(l.y * PT), r2(l.w * PT), r2((measured || l.h) * PT)];
    const item = {
        id: l.id + '-t', type: 'text', text: l.text, box, auto: false,
        font: { family: s.font, size: r2(s.size * PT), weight: s.weight, ...(s.italic ? { style: 'italic' } : {}) },
        align: s.align === 'justify' ? 'justify' : s.align, lineHeight: s.lineHeight || 1.2,
        fill: s.gradient ? { gradient: gradient(s.gradient, box[0], box[1], box[2], box[3]), opacity: 1 } : paint(s.color),
        stroke: s.stroke?.width && paint(s.stroke.color) ? { ...paint(s.stroke.color), width: r2(s.stroke.width * PT) } : null,
    };
    // צל והילה — במבנה המשותף של החבילה (mbe-format.md, "effects")
    const e = [...(fx(s.shadow) || [])];
    if (s.glow && paint(s.glow.color)) e.push({ type: 'glow', blur: r2(s.glow.blur * PT), color: s.glow.color.slice(0, 7), opacity: r2(Math.min(1, paint(s.glow.color).opacity * (s.glow.strength || 1))) });
    if (e.length) item.effects = e;
    if (l.rotation) {
        // סיבוב סביב פינת התיבה (כמו בקנבס): הזזה לראשית, סיבוב, וחזרה
        const a = (l.rotation * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a), x = box[0], y = box[1];
        item.transform = [c, sn, -sn, c, x - c * x + sn * y, y - sn * x - c * y].map(v => Math.round(v * 10000) / 10000);
    }
    if (!s.background || !paint(s.background.color)) return [item];
    const p = s.background.padding || 0;
    const b = s.background, bf = paint(b.color);
    const bg = { id: l.id + '-bg', d: toMbePath(rectD(l.w + 2 * p, (measured || l.h) + 2 * p, b.radius || 0), mul(layerToPage(l), [1, 0, 0, 1, -p, -p])),
        fill: { ...bf, opacity: r2(bf.opacity * (b.opacity ?? 1)) },
        stroke: b.border && paint(b.border.color) ? { ...paint(b.border.color), width: r2(b.border.width * PT) } : null };
    return [{ id: l.id + '-g', type: 'group', name: 'טקסט עם רקע', children: [bg, item] }];
}

const shaOf = src => (/^media\/([0-9a-f]{64})\./.exec(src || '') || [])[1] || null;

/**
 * המתכון של שכבת תמונה בפורמט (mbe-format.md, "שכבת raster — עריכה לא הורסת", ר2): ‏src הוא התוצאה האפויה, ולצידו —
 * המקור, החיתוך (בפיקסלים של המקור), הטרנספורמציה אל העמוד (pt), מסכה וקטורית, פילטרים וצל. ‏baked — הגיבוב של src:
 * מי שערך את הפיקסלים בלי להבין את המתכון ישנה את src, והמתכון ייחשב לא תקף.
 */
async function recipe(f, doc, l, images, src) {
    const img = images.get(l.asset);
    const blob = await assets.get(doc.assets[l.asset]?.src);
    if (!img || !blob) return {};
    const nw = img.naturalWidth, nh = img.naturalHeight;
    const c = l.crop || { x: 0, y: 0, w: 1, h: 1 };
    const cw = c.w * nw, ch = c.h * nh;
    const out = {
        baked: shaOf(src),
        source: await f.addMedia(new Uint8Array(await blob.arrayBuffer()), blob.type || 'image/png'),
        transform: mul(layerToPage(l), [l.w / cw, 0, 0, l.h / ch, 0, 0]).map(v => Math.round(v * 10000) / 10000),
    };
    if (l.crop) out.crop = [c.x * nw, c.y * nh, cw, ch].map(v => Math.round(v * 10) / 10);
    if (l.mask === 'circle' || l.mask === 'rounded') {
        const d = l.mask === 'circle' ? ellipseD(l.w, l.h) : rectD(l.w, l.h, Math.min(l.w, l.h) * 0.12);
        out.vectorMask = { d: toMbePath(d, layerToPage(l)), feather: 0, enabled: true };
    }
    const fl = l.filters || {};
    const filters = [];
    if (fl.brightness || fl.contrast) filters.push({ type: 'brightness-contrast', brightness: r2(fl.brightness || 0), contrast: r2((fl.contrast || 0) / 100) });
    if (fl.saturation) filters.push({ type: 'hue-saturation', saturation: r2(fl.saturation) });
    if (fl.blur) filters.push({ type: 'gaussian-blur', radius: r2(fl.blur * PT) });
    if (filters.length) out.filters = filters;
    const e = fx(l.shadow);
    if (e) out.effects = e;
    return out;
}

/** ציור מסמך (או חלק ממנו) לקנבס, בלי ידיות — לייצוגים ולשכבות "אפויות". */
async function render(doc, images, scale) {
    await ensureDocFonts(doc);
    const holder = document.createElement('div');
    holder.style.cssText = 'position:fixed;left:-10000px;top:0;width:100px;height:100px';
    holder.setAttribute('aria-hidden', 'true');
    document.body.append(holder);
    try {
        const c = new Canvas(holder, {});
        for (const [k, img] of images) c.setImage(k, img);
        c.setDoc(doc);
        const out = c.pageCanvas(scale);
        c.stage.destroy();
        return out;
    } finally { holder.remove(); }
}

const pngBytes = async canvas => new Uint8Array(await (await canvasToBlob(canvas, 'image/png')).arrayBuffer());

/**
 * המסמך → בתים של ‎.mbe. images — Map של assetId → HTMLImageElement (מה שכבר טעון בקנבס).
 * baseBytes — הקובץ שנפתח (אם נפתח מ-‎.mbe): מה שלא שלנו (apps/ של אחרים, יומן) נשמר.
 * @returns {{bytes: Uint8Array, gaps: string[]}}
 */
export async function buildMbe(doc, images, { baseBytes = null } = {}) {
    const f = baseBytes ? await open(baseBytes) : create('design', { units: 'pt', color: { space: 'sRGB' }, surfaces: [] });
    if (baseBytes && !f.ok) throw new Error('הקובץ המקורי פגום — לא ניתן לשמור מעליו');
    if (!baseBytes) f.manifest.kind = 'design';
    const { width: W, height: H } = doc.page;
    const gaps = new Set();
    const layers = [];
    // צבע / מדרג הדף — שכבת וקטור תחתונה
    const pageFill = doc.page.gradient ? { gradient: gradient(doc.page.gradient, 0, 0, W * PT, H * PT), opacity: 1 } : paint(doc.page.background || '#ffffff');
    if (pageFill) layers.push({ id: 'gc-page', name: 'צבע הדף', type: 'vector', locked: true, visible: true, opacity: 1, blend: 'normal',
        paths: [{ id: 'gc-page-p', d: toMbePath(rectD(W, H), [PT, 0, 0, PT, 0, 0]), fill: pageFill, stroke: null }] });

    // תצוגה אחת של כל המסמך, כדי לקבל את הגובה המדוד של כל טקסט
    const baked = {};   // מזהה שכבה → ה-src שאפינו; אם בפתיחה הוא אחר — מישהו ערך את הפיקסלים (raster-studio/docs/layer-ai.md)
    const measure = new Map(doc.layers.filter(l => l.type === 'text').map(l => [l.id, l.h]));

    for (const l of doc.layers) {
        const common = { id: l.id, name: l.name || layerName(l), opacity: r2(l.opacity ?? 1), blend: 'normal', visible: !l.hidden, locked: !!l.locked };
        if (l.type === 'image') {
            // שכבת תמונה — "אפויה" לגודל הדף: הרסטר עורך פיקסלים, בלי לדעת על חיתוך / מסכה / סיבוב שלנו
            const solo = { ...clone(doc), page: { ...doc.page, background: '#00000000' }, layers: [{ ...clone(l), opacity: 1, hidden: false }] };
            delete solo.page.gradient;
            const c = await render(solo, images, 1);
            const src = await f.addMedia(await pngBytes(c), 'image/png');
            layers.push({ ...common, type: 'raster', src, px: [c.width, c.height], box: [0, 0, r2(W * PT), r2(H * PT)],
                ...await recipe(f, doc, l, images, src) });
            baked[l.id] = src;
            if (l.crop || l.mask || l.filters && Object.values(l.filters).some(Boolean) || l.flipX || l.flipY || l.rotation) gaps.add('image-edits → baked');
        } else if (l.type === 'text') {
            layers.push({ ...common, type: 'vector', paths: textItems(l, measure.get(l.id)) });
            if (/\{\{[^}]+\}\}/.test(l.text)) gaps.add('merge-variables → plain text');
        } else if (l.type === 'shape' || l.type === 'icon') {
            if (l.shape === 'line') { layers.push({ ...common, type: 'vector', paths: [vectorItem({ ...l, shape: 'rect' })] }); continue; }
            layers.push({ ...common, type: 'vector', paths: [vectorItem(l)] });
        }
    }
    const surfaceId = (f.document.surfaces || []).find(s => s?.type === 'page')?.id || 'gc-page-1';
    const surface = { id: surfaceId, type: 'page', size: [r2(W * PT), r2(H * PT)], layers };
    const others = (f.document.surfaces || []).filter(s => s?.id !== surfaceId);
    f.document = {
        ...f.document, units: 'pt', color: f.document.color || { space: 'sRGB' },
        fonts: [...new Set(doc.layers.filter(l => l.type === 'text').map(l => l.style.font))].map(family => ({ family })),
        surfaces: [surface, ...others],
    };

    // המסמך המקורי שלנו — לפתיחה מדויקת בסטודיו. התמונות המקוריות (לא האפויות) ב-media/.
    const saved = clone(doc);
    saved.baked = baked;
    for (const [id, a] of Object.entries(saved.assets)) {
        const blob = await assets.get(a.src);
        if (!blob) continue;
        a.media = await f.addMedia(new Uint8Array(await blob.arrayBuffer()), blob.type || 'image/png');
        delete a.src;
    }
    f.setApp(APP_ID, SAVED, enc(JSON.stringify(saved)));

    // ייצוגים: preview (עד 1024px) ו-image.png בגודל מלא
    const full = await render(doc, images, 1);
    const k = Math.min(1, 1024 / Math.max(W, H));
    const small = k < 1 ? await render(doc, images, k) : full;
    f.setRendition(PREVIEW, await pngBytes(small), { gaps: ['layers → flattened'] });
    f.setRendition('renditions/image.png', await pngBytes(full), { gaps: ['layers → flattened'] });

    const bytes = await save(f, { app: APP_ID, appVersion: APP_VERSION });
    return { bytes, gaps: [...gaps] };
}

function layerName(l) {
    if (l.type === 'text') return (l.text || '').split('\n')[0].slice(0, 40) || 'טקסט';
    if (l.type === 'image') return l.role === 'background' ? 'רקע' : 'תמונה';
    if (l.type === 'icon') return 'אייקון';
    return l.shape === 'element' ? (ELEMENTS[l.element]?.label || 'אלמנט') : 'צורה';
}

/** קובץ ‎.mbe? — לפי הבתים: ZIP שהחלק הראשון שלו mimetype עם הסוג שלנו. */
export async function isMbe(file) {
    if (/\.mbe$/i.test(file.name || '')) return true;
    const head = new Uint8Array(await file.slice(0, 80).arrayBuffer());
    return head[0] === 0x50 && head[1] === 0x4b && new TextDecoder().decode(head).includes('application/vnd.mbe+zip');
}

/**
 * ‎.mbe → מסמך שלנו. אם האחרון ששמר הוא הסטודיו — המסמך המקורי (apps/greeting-cards/design.json) בדיוק. אחרת — קריאה
 * מהשכבות (readForeign), עם התפקידים והנמענים מהמקורי אם יש.
 * @returns {{doc, loaded: Map, fixes: string[], bytes: Uint8Array}}
 */
export async function importMbe(file) {
    const bytes = new Uint8Array(await file.arrayBuffer());
    const f = await open(bytes);
    const errors = f.issues.filter(i => i.level === 'error');
    if (errors.length && !f.document) throw new Error('קובץ ‎.mbe פגום: ' + errors[0].message);
    const fixes = errors.map(e => 'בעיה בקובץ: ' + e.message);
    const savedBytes = f.apps(APP_ID).get(SAVED);
    const saved = savedBytes ? JSON.parse(dec(savedBytes)) : null;
    const ours = saved && f.manifest?.modified?.app === APP_ID;

    let doc, loaded = new Map();
    if (ours) {
        ({ doc } = normalizeDoc(saved));
        doc.merge = saved.merge || doc.merge;
        for (const [id, a] of Object.entries(saved.assets || {})) {
            const data = a.media && f.getMedia(a.media);
            if (!data) { fixes.push(`חסרה התמונה של "${id}"`); delete doc.assets[id]; continue; }
            const blob = new Blob([data], { type: a.media.endsWith('.jpg') ? 'image/jpeg' : 'image/png' });
            doc.assets[id] = { type: 'image', src: await assets.put(blob) };
            loaded.set(id, await blobToImage(blob));
        }
        doc.layers = doc.layers.filter(l => l.type !== 'image' || doc.assets[l.asset]);
    } else {
        ({ doc, loaded } = await readForeign(f, fixes));
        if (saved) {
            // נערך באפליקציה אחרת: מחזירים מהמקורי את מה שאין לו מקום בפורמט — תפקידים ונמענים
            const byId = new Map((saved.layers || []).map(l => [l.id, l]));
            for (const l of doc.layers) { const o = byId.get(l.id); if (o?.role) l.role = o.role; }
            // שכבת תמונה שהפיקסלים שלה לא השתנו מאז שאפינו — חוזרת עם המקור והפרמטרים (חיתוך, מסכה, פילטרים…)
            const srcOf = new Map();
            const walk = list => (list || []).forEach(x => { if (x?.type === 'group') walk(x.children); else if (x?.type === 'raster') srcOf.set(x.id, x); });
            for (const sf of f.document.surfaces || []) walk(sf.layers);
            let restored = 0;
            for (let i = 0; i < doc.layers.length; i++) {
                const l = doc.layers[i], o = byId.get(l.id);
                // המתכון תקף אם הפיקסלים לא השתנו: baked של הפורמט (ר2) תואם ל-src, או ה-src שרשמנו אצלנו
                const raw = srcOf.get(l.id);
                const intact = raw && (raw.baked ? raw.baked === shaOf(raw.src) : saved.baked?.[l.id] === raw.src);
                if (l.type !== 'image' || o?.type !== 'image' || !intact) continue;
                const a = saved.assets?.[o.asset];
                const data = a?.media && f.getMedia(a.media);
                if (!data) continue;
                const blob = new Blob([data], { type: a.media.endsWith('.jpg') ? 'image/jpeg' : 'image/png' });
                delete doc.assets[l.asset];
                loaded.delete(l.asset);
                const aid = uid('a');
                doc.assets[aid] = { type: 'image', src: await assets.put(blob) };
                loaded.set(aid, await blobToImage(blob));
                doc.layers[i] = { ...clone(o), asset: aid, hidden: l.hidden, locked: l.locked, opacity: l.opacity };
                if (!doc.layers[i].hidden) delete doc.layers[i].hidden;
                if (!doc.layers[i].locked) delete doc.layers[i].locked;
                restored++;
            }
            if (restored) fixes.push(`${restored} שכבות תמונה שלא נערכו — חזרו עם החיתוך, המסכה והפילטרים שלהן`);
            if (saved.merge) doc.merge = saved.merge;
            fixes.push(`נערך ב-${f.manifest?.modified?.app || 'אפליקציה אחרת'} — נפתח מהשכבות שבקובץ`);
        }
    }
    doc.title = doc.title || (file.name || 'עיצוב').replace(/\.mbe$/i, '');
    doc.source = { app: 'editor' };
    return { doc, loaded, fixes, bytes };
}

const FONT_NAMES = FONTS.map(x => x.family);

/** קריאה מהשכבות — קובץ שנוצר או נערך באפליקציה אחרת בחבילה. */
async function readForeign(f, fixes) {
    const s = (f.document.surfaces || []).find(x => Array.isArray(x?.size));
    if (!s) throw new Error('בקובץ אין עמוד או קנבס');
    const toPx = 1 / PT;
    const W = Math.round(s.size[0] * toPx), H = Math.round(s.size[1] * toPx);
    const doc = newDoc(W, H, '');
    doc.page.background = '#ffffff';
    const loaded = new Map();
    const skipped = new Set();

    const addImage = async (src, box, extra = {}) => {
        const data = f.getMedia(src);
        if (!data) { fixes.push('חסרה תמונה: ' + src); return; }
        const blob = new Blob([data], { type: /\.jpe?g$/.test(src) ? 'image/jpeg' : 'image/png' });
        const aid = uid('a');
        doc.assets[aid] = { type: 'image', src: await assets.put(blob) };
        loaded.set(aid, await blobToImage(blob));
        doc.layers.push({ id: extra.id || uid(), type: 'image', role: 'photo', asset: aid, x: box[0] * toPx, y: box[1] * toPx, w: box[2] * toPx, h: box[3] * toPx,
            rotation: 0, opacity: extra.opacity ?? 1, ...(extra.name ? { name: extra.name } : {}), ...(extra.hidden ? { hidden: true } : {}), ...(extra.locked ? { locked: true } : {}) });
    };

    const item = async (p, layer) => {
        const base = { opacity: layer.opacity ?? 1, hidden: layer.visible === false || undefined, name: p.name || layer.name };
        if (p.type === 'group') { if (p.clip) skipped.add('clip'); for (const c of p.children || []) await item(c, layer); return; }
        if (p.type === 'image') return addImage(p.src, p.box, base);
        if (p.type === 'text') {
            const fam = FONT_NAMES.includes(p.font?.family) ? p.font.family : DEFAULT_FONT;
            if (p.font?.family && fam !== p.font.family) fixes.push(`הפונט "${p.font.family}" הוחלף ב-${fam}`);
            const [x, y, w, h] = (p.box || [0, 0, 100, 20]).map(v => v * toPx);
            doc.layers.push({ id: p.id || uid(), type: 'text', role: 'body', text: String(p.text ?? ''), x, y, w: Math.max(20, w), h, rotation: 0, opacity: base.opacity,
                ...(base.name ? { name: base.name } : {}),
                style: { font: fam, size: (p.font?.size || 12) * toPx, weight: p.font?.weight || 400, color: p.fill?.color || '#000000',
                    align: ['right', 'left', 'center', 'justify'].includes(p.align) ? p.align : 'right', dir: 'rtl', lineHeight: p.lineHeight || 1.2 } });
            return;
        }
        if (p.type && p.type !== 'path') { skipped.add(p.type); return; }
        if (!p.d) return;
        // נתיב: מחשבים תיבה מכל הנקודות (גם נקודות בקרה — קירוב), ושומרים את הנתיב יחסית אליה
        let d = p.d;
        if (Array.isArray(p.transform)) d = toMbePath(d, p.transform);
        const pts = absolutize(d).flatMap(([, ...n]) => n);
        const xs = pts.filter((_, i) => i % 2 === 0), ys = pts.filter((_, i) => i % 2 === 1);
        if (!xs.length) return;
        const x0 = Math.min(...xs), y0 = Math.min(...ys), w = Math.max(0.5, Math.max(...xs) - x0), h = Math.max(0.5, Math.max(...ys) - y0);
        const local = toMbePath(d, [toPx, 0, 0, toPx, -x0 * toPx, -y0 * toPx]);
        const color = p.fill?.color || (p.fill?.gradient?.stops?.[0]?.color) || '#00000000';
        const l = { id: p.id || uid(), type: 'shape', shape: 'path', role: 'decoration', path: local, vb: [w * toPx, h * toPx],
            x: x0 * toPx, y: y0 * toPx, w: w * toPx, h: h * toPx, rotation: 0, opacity: (base.opacity) * (p.fill?.opacity ?? 1), radius: 0, points: 5,
            fill: p.fill ? color : '#00000000', ...(base.name ? { name: base.name } : {}) };
        if (p.stroke?.color) l.stroke = { color: p.stroke.color, width: (p.stroke.width || 1) * toPx };
        if (p.fill?.gradient) skipped.add('gradient');
        doc.layers.push(l);
    };

    for (const layer of flatten(s.layers || [])) {
        if (layer.type === 'raster' && layer.src) {
            const box = Array.isArray(layer.box) ? layer.box : [0, 0, s.size[0], s.size[1]];
            await addImage(layer.src, box, { id: layer.id, name: layer.name, opacity: layer.opacity, hidden: layer.visible === false, locked: layer.locked });
        } else if (layer.type === 'vector') {
            for (const p of layer.paths || []) await item(p, layer);
        } else if (layer.type === 'frame' && layer.src) {
            await addImage(layer.src, layer.box, { name: layer.name });
        } else skipped.add(layer.type);
    }
    // שכבת "צבע הדף" שלנו (או מלבן בגודל הדף בתחתית) → צבע הדף
    const first = doc.layers[0];
    if (first?.type === 'shape' && first.shape === 'path' && Math.abs(first.w - W) < 2 && Math.abs(first.h - H) < 2 && first.x < 1 && first.y < 1) {
        doc.page.background = first.fill.length === 7 ? first.fill : '#ffffff';
        doc.layers.shift();
    }
    for (const t of skipped) fixes.push(`לא נתמך בסטודיו (מוצג חלקית או מדולג): ${t}`);
    const { doc: norm, fixes: more } = normalizeDoc(doc);
    // normalizeDoc משמר את הנכסים רק עם src של idb — מחזירים אותם
    norm.assets = doc.assets;
    fixes.push(...more);
    return { doc: norm, loaded };
}

function flatten(list) {
    const out = [];
    for (const l of list) {
        if (l?.type === 'group') out.push(...flatten(l.children || []));
        else if (l) out.push(l);
    }
    return out;
}
